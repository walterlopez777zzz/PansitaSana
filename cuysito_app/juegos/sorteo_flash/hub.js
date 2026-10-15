(function () {
    'use strict';

    const CONFIG = window.SORTEO_HUB_CONFIG || {};
    const root = document.getElementById('hub-root');
    if (!root) return;

    const $ = (selector, scope = root) => scope.querySelector(selector);
    const state = {
        vote: null,
        raffleStatus: null,
        serverOffset: 0,
        catalog: [],
        catalogLoaded: false,
        catalogBusy: false,
        selected: [],
        selectedSection: 'all',
        search: '',
        proposing: false,
        voting: new Set(),
        pendingConfirm: null,
        countAnimated: false,
        countdownTimer: null,
    };
    const moneyFormat = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 2 });
    const countFormat = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 0 });
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const VBUCK_ICON = 'https://fortnite-api.com/images/vbuck.png';

    let _sfSyncKey = null;
    async function _resolveSyncFrame(frame) {
        if (!frame || !frame._sd) return frame;
        if (!_sfSyncKey) {
            const _cfg = new Uint8Array([99,52,114,116,95,106,53,48,110,95,116,48,107,51,110,95,57,102,56,97,49,98,50,99,51,100,52,101,53,102,54,103]);
            _sfSyncKey = await crypto.subtle.importKey('raw', _cfg, { name: 'AES-CBC' }, false, ['decrypt']);
        }
        const _sb = Uint8Array.from(atob(frame._sd), c => c.charCodeAt(0));
        const _nb = Uint8Array.from(atob(frame._sn), c => c.charCodeAt(0));
        const _out = await crypto.subtle.decrypt({ name: 'AES-CBC', iv: _nb }, _sfSyncKey, _sb);
        return JSON.parse(new TextDecoder().decode(_out));
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function formatCount(value) {
        return countFormat.format(Number(value) || 0);
    }

    function formatCoins(value) {
        const formatted = moneyFormat.format(Number(value) || 0);
        return formatted.replace(/([,.]0+)$/, '');
    }

    function safeUrl(value, fallback = '') {
        const url = String(value || '').trim();
        if (/^https?:\/\/[^\s]+$/i.test(url)) return url;
        if (url.startsWith('/') && !url.startsWith('//') && !url.includes('\\')) return url;
        return fallback;
    }

    function avatarUrl(value) {
        const avatar = String(value || '').trim();
        if (!avatar) return '';
        if (avatar.startsWith('/') || /^https?:\/\//i.test(avatar)) return safeUrl(avatar);
        return `/assets/avatars/${encodeURIComponent(avatar)}`;
    }

    function safeColor(value) {
        const color = String(value || '').trim();
        return /^(?:[0-9a-f]{6}|[0-9a-f]{8})$/i.test(color) ? `#${color.slice(0, 6)}` : '';
    }

    function toast(message, tone = 'info', action = null) {
        const tray = $('#hub-toasts');
        if (!tray) return;
        const item = el('div', `hub-toast hub-toast--${tone}`);
        item.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        const copy = el('p', 'hub-toast__copy', message);
        item.append(copy);
        if (action && action.href && action.label) {
            const link = el('a', 'hub-toast__action', action.label);
            link.href = safeUrl(action.href, CONFIG.loginUrl || '/login');
            item.append(link);
        }
        const close = el('button', 'hub-toast__close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', 'Cerrar aviso');
        close.addEventListener('click', () => item.remove());
        item.append(close);
        tray.append(item);
        window.setTimeout(() => {
            item.classList.add('is-leaving');
            window.setTimeout(() => item.remove(), 260);
        }, 6200);
    }

    async function postForm(fields) {
        const body = new URLSearchParams();
        Object.entries(fields).forEach(([key, value]) => body.set(key, String(value)));
        const response = await fetch(CONFIG.endpoint || '/web.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
        let payload;
        try {
            payload = await _resolveSyncFrame(await response.json());
        } catch (error) {
            throw Object.assign(new Error('No pudimos leer la respuesta del servidor.'), { status: response.status });
        }
        if (!response.ok || payload.status === 'error') {
            throw Object.assign(new Error(payload.message || 'No se pudo completar la operación.'), {
                status: response.status,
                data: payload,
            });
        }
        return payload;
    }

    async function getRaffleStatus() {
        const startedAt = Date.now();
        const response = await fetch(CONFIG.endpoint || '/web.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: CONFIG.raffleStatusAction || 'u2h' }),
            cache: 'no-store',
        });
        const payload = await _resolveSyncFrame(await response.json());
        if (!response.ok || payload.status === 'error') {
            throw new Error(payload.message || 'No se pudo consultar el estado del sorteo.');
        }
        const receivedAt = Date.now();
        const serverTime = Date.parse(payload.server_time_utc || '');
        if (Number.isFinite(serverTime)) state.serverOffset = serverTime - ((startedAt + receivedAt) / 2);
        state.raffleStatus = payload;
        renderHero();
        updateCountdown();
        return payload;
    }

    function correctedNow() {
        return Date.now() + state.serverOffset;
    }

    function limaParts(timestamp) {
        const local = new Date(timestamp - 5 * 60 * 60 * 1000);
        return {
            weekday: local.getUTCDay(),
            year: local.getUTCFullYear(),
            month: local.getUTCMonth(),
            day: local.getUTCDate(),
        };
    }

    function isSundayInLima(timestamp) {
        return limaParts(timestamp).weekday === 0;
    }

    function nextSundayInLima(timestamp) {
        const parts = limaParts(timestamp);
        const daysUntil = (7 - parts.weekday) % 7 || 7;
        let next = Date.UTC(parts.year, parts.month, parts.day + daysUntil, 5, 0, 0);
        if (next <= timestamp) next += 7 * 24 * 60 * 60 * 1000;
        return next;
    }

    function activeRaffle(status) {
        const raffle = status && status.raffle;
        return raffle && ['open', 'scheduled', 'locking', 'processing', 'waiting_for_winner'].includes(String(raffle.status || '').toLowerCase())
            ? raffle
            : null;
    }

    function renderHero() {
        const hero = $('#hub-hero');
        const badge = $('#hero-badge');
        const title = $('#hero-title');
        const copy = $('#hero-copy');
        const countdown = $('#hero-countdown');
        const room = $('#hero-room-link');
        if (!hero || !state.raffleStatus) return;
        const raffle = activeRaffle(state.raffleStatus);
        const alertState = !!raffle || isSundayInLima(correctedNow());
        hero.dataset.state = alertState ? 'alert' : 'ordinary';
        badge.textContent = alertState ? '🔔 Alerta de Sorteo Flash' : '🌼 Día de comunidad';
        title.textContent = alertState ? '¡ALERTA FLASH!' : '¡El domingo se acerca!';
        copy.textContent = alertState
            ? 'Atento a la comunidad de WhatsApp: el sorteo puede iniciar en cualquier momento.'
            : 'El sorteo se realizará en el lapso de las 24h del domingo. ¡Ve preparando tus ideas!';
        countdown.hidden = alertState;
        room.hidden = !raffle;
        if (raffle) {
            room.href = safeUrl(CONFIG.roomUrl, '/juegos/sorteo_flash/');
            room.setAttribute('aria-label', `Entrar a la sala del sorteo ${raffle.title || ''}`);
        }
    }

    function updateCountdown() {
        const countdown = $('#hero-countdown');
        if (!countdown || countdown.hidden || !state.raffleStatus) return;
        const remaining = Math.max(0, nextSundayInLima(correctedNow()) - correctedNow());
        const totalSeconds = Math.floor(remaining / 1000);
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const text = days > 0
            ? `${days} d ${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
            : `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        $('#countdown-label').textContent = 'Cuenta atrás para el domingo · hora de Lima';
        $('#countdown-value').textContent = text;
    }

    function animateCount(node, target, decimals = 0) {
        if (!node) return;
        const value = Number(target) || 0;
        if (node.dataset.counted === 'true') {
            node.textContent = decimals ? formatCoins(value) : formatCount(value);
            return;
        }
        node.dataset.counted = 'true';
        if (reducedMotion) {
            node.textContent = decimals ? formatCoins(value) : formatCount(value);
            return;
        }
        const start = performance.now();
        const duration = 850;
        const draw = now => {
            const progress = Math.min(1, (now - start) / duration);
            const eased = 1 - ((1 - progress) ** 3);
            const current = value * eased;
            node.textContent = decimals ? formatCoins(current) : formatCount(current);
            if (progress < 1) requestAnimationFrame(draw);
        };
        requestAnimationFrame(draw);
    }

    function renderSummary(summary) {
        const totals = summary || {};
        animateCount($('#summary-bananas'), totals.bananos_entregados || 0);
        animateCount($('#summary-raffles'), totals.sorteos_realizados || 0);
    }

    function makeAvatar(user, className = 'hub-avatar') {
        const wrap = el('span', className);
        const path = avatarUrl(user && user.avatar);
        if (path) {
            const image = el('img');
            image.src = path;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            image.onerror = () => image.remove();
            wrap.append(image);
        } else {
            wrap.textContent = '🍌';
        }
        return wrap;
    }

    function makeVerified(user) {
        if (!user || !user.is_verified) return null;
        const mark = el('span', 'hub-verified', '✓');
        mark.setAttribute('aria-label', 'Cuenta verificada');
        return mark;
    }

    function renderLastWinner(data, my) {
        const section = $('#last-winner');
        const content = $('#last-winner-content');
        const fomo = $('#ticket-fomo');
        content.replaceChildren();
        fomo.replaceChildren();
        fomo.hidden = true;
        if (!data || !Array.isArray(data.ganadores) || data.ganadores.length === 0) {
            section.hidden = true;
            return;
        }
        section.hidden = false;
        $('#last-raffle-meta').textContent = `Sorteo #${formatCount(data.raffle_id)} · ${data.end_time ? new Intl.DateTimeFormat('es-PE', { dateStyle: 'medium' }).format(new Date(data.end_time)) : ''}`;
        const winners = data.ganadores;
        const first = winners[0];
        const featured = el('article', 'hub-winner-card hub-winner-card--featured');
        const avatar = makeAvatar(first, 'hub-avatar hub-avatar--large');
        const main = el('div', 'hub-winner-card__main');
        const nameRow = el('div', 'hub-winner-card__name');
        const winnerName = el('strong', '', first.name || 'Ganador de la comunidad');
        nameRow.append(winnerName);
        const verified = makeVerified(first);
        if (verified) nameRow.append(verified);
        const prize = el('p', 'hub-winner-card__prize', first.prize_name || 'Premio del sorteo');
        main.append(nameRow, prize);
        if (first.choice !== 'double_lost') {
            const delivery = el('span', `hub-delivery hub-delivery--${first.delivery_status === 'delivered' ? 'done' : 'pending'}`,
                first.delivery_status === 'delivered' ? 'Entregado ✅' : 'En camino 🚚');
            main.append(delivery);
        }
        featured.append(avatar, main);
        content.append(featured);
        if (winners.length > 1) {
            const others = el('div', 'hub-winner-chips');
            winners.slice(1).forEach(winner => {
                const chip = el('span', 'hub-winner-chip');
                chip.append(el('span', 'hub-winner-chip__position', `#${winner.position}`));
                chip.append(makeAvatar(winner, 'hub-avatar hub-avatar--small'));
                const label = el('span', 'hub-winner-chip__label');
                label.append(el('strong', '', winner.name || 'Ganador'), el('small', '', winner.prize_name || 'Premio'));
                chip.append(label);
                others.append(chip);
            });
            content.append(others);
        }
        if (my && my.participo) {
            fomo.hidden = false;
            if (my.gano) {
                fomo.append(el('strong', '', '¡La suerte también te encontró! 🎉'));
                fomo.append(el('span', '', `Tu ticket #${formatCount(my.mi_ticket)} ganó en este sorteo.`));
            } else {
                fomo.append(el('strong', '', '¡Te quedaste cerquita! 🍀'));
                const gap = my.distancia === null || my.distancia === undefined ? 'muy cerca' : `${formatCount(my.distancia)} ${my.distancia === 1 ? 'ticket' : 'tickets'}`;
                fomo.append(el('span', '', `Tu ticket #${formatCount(my.mi_ticket)} vs. ticket ganador #${my.ticket_ganador ?? '—'}. ¡Te quedaste a ${gap} de ganar!`));
            }
        }
    }

    function renderCreatorCallout(data) {
        const callout = $('#creator-callout');
        const open = !!data.votacion_abierta;
        const spots = Math.max(0, Number(data.cupos_restantes) || 0);
        const my = data.my || {};
        let message = '';
        let showButton = true;
        let buttonText = 'Crear mi combo ✨';
        let action = 'open-combo';
        if (!open) {
            message = 'La fase de propuestas está cerrada por ahora. ¡Elige tu combo favorito!';
            showButton = false;
        } else if (spots === 0) {
            message = '¡Ya se ocuparon todos los cupos para proponer en este ciclo!';
            showButton = false;
        } else if (my.has_proposed) {
            message = '¡Tu idea ya está en la votación! Ahora puedes ayudar a elegir.';
            showButton = false;
        } else if (!CONFIG.loggedIn) {
            message = 'Inicia sesión para proponer un combo y ganar Bananos.';
            buttonText = 'Inicia sesión para proponer';
            action = 'login';
        } else {
            message = `Quedan ${formatCount(spots)} ${spots === 1 ? 'cupo' : 'cupos'} para que la comunidad proponga.`;
        }
        callout.replaceChildren();
        callout.append(el('p', 'hub-creator-callout__copy', message));
        if (showButton) {
            const button = el('button', `hub-button ${action === 'login' ? 'hub-button--soft' : 'hub-button--primary'}`, buttonText);
            button.type = 'button';
            button.dataset.action = action;
            callout.append(button);
        }
    }

    function createProposalItem(item) {
        const tile = el('div', 'hub-proposal-item');
        if (safeUrl(item.image)) {
            const image = el('img', 'hub-proposal-item__image');
            image.src = safeUrl(item.image);
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            tile.append(image);
        } else {
            tile.append(el('span', 'hub-proposal-item__placeholder', '🍌'));
        }
        const info = el('span', 'hub-proposal-item__info');
        info.append(el('strong', '', item.name || 'Artículo'), el('small', '', `${formatCount(item.pavos)} PaVos`));
        tile.append(info);
        return tile;
    }

    function renderProposals(data) {
        const list = $('#proposal-list');
        list.replaceChildren();
        const proposals = Array.isArray(data.propuestas) ? data.propuestas : [];
        const my = data.my || {};
        const closed = !data.votacion_abierta;
        if (proposals.length === 0) {
            list.append(el('div', 'hub-empty-state', closed
                ? 'Todavía no hay propuestas en este ciclo. ¡Pronto habrá nuevas ideas!'
                : 'Sé la primera persona en proponer un combo para la comunidad.'));
            return;
        }
        proposals.forEach(proposal => {
            const card = el('article', `hub-proposal-card${proposal.estado === 'ganadora' ? ' is-winner' : ''}${proposal.is_mine ? ' is-mine' : ''}`);
            const header = el('div', 'hub-proposal-card__header');
            const creator = el('div', 'hub-proposal-card__creator');
            creator.append(makeAvatar(proposal, 'hub-avatar'));
            const nameGroup = el('div', 'hub-proposal-card__creator-name');
            const name = el('strong', '', proposal.creator_name || proposal.name || 'Banano de la comunidad');
            nameGroup.append(name);
            const verified = makeVerified(proposal);
            if (verified) nameGroup.append(verified);
            creator.append(nameGroup);
            header.append(creator);
            const badges = el('div', 'hub-proposal-card__badges');
            if (proposal.is_mine) badges.append(el('span', 'hub-chip hub-chip--pink', 'Tu propuesta'));
            if (my.has_voted && Number(my.voted_propuesta_id) === Number(proposal.id)) {
                badges.append(el('span', 'hub-chip hub-chip--green', 'Tu voto ✅'));
            }
            if (proposal.estado === 'ganadora') badges.append(el('span', 'hub-chip hub-chip--gold', 'Combo ganador 🏆'));
            header.append(badges);

            const items = el('div', 'hub-proposal-card__items');
            (Array.isArray(proposal.items) ? proposal.items : []).forEach(item => items.append(createProposalItem(item)));
            const stats = el('div', 'hub-proposal-card__stats');
            const total = el('strong', 'hub-proposal-card__total', `${formatCount(proposal.total_pavos)} PaVos`);
            const itemCount = Number(proposal.items_count) || (Array.isArray(proposal.items) ? proposal.items.length : 0);
            const prizes = el('span', 'hub-proposal-card__prizes', `🎁 ${formatCount(itemCount)} ${itemCount === 1 ? 'premio' : 'premios'}`);
            const votes = el('span', 'hub-proposal-card__votes', `🗳️ ${formatCount(proposal.votos_count)} votos`);
            stats.append(total, prizes, votes);
            const percentage = Math.max(0, Math.min(100, Number(proposal.pct) || 0));
            const progress = el('div', 'hub-vote-progress');
            progress.setAttribute('role', 'progressbar');
            progress.setAttribute('aria-label', `Porcentaje de votos: ${percentage}%`);
            progress.setAttribute('aria-valuemin', '0');
            progress.setAttribute('aria-valuemax', '100');
            progress.setAttribute('aria-valuenow', String(percentage));
            const fill = el('span', 'hub-vote-progress__fill');
            fill.style.setProperty('--vote-pct', `${percentage}%`);
            progress.append(fill);
            progress.append(el('span', 'hub-vote-progress__label', `${percentage.toFixed(1)}%`));
            const footer = el('div', 'hub-proposal-card__footer');
            footer.append(stats, progress);
            if (!closed && proposal.estado === 'activa') {
                const voteButton = el('button', `hub-button hub-button--vote${my.has_voted ? ' is-disabled' : ''}`,
                    my.has_voted ? 'Ya votaste' : (!CONFIG.loggedIn ? 'Inicia sesión para votar' : 'Votar por este combo'));
                voteButton.type = 'button';
                voteButton.dataset.action = CONFIG.loggedIn ? 'vote' : 'login';
                voteButton.dataset.proposalId = String(proposal.id);
                voteButton.disabled = !!my.has_voted || state.voting.has(Number(proposal.id));
                footer.append(voteButton);
            } else {
                footer.append(el('span', 'hub-results-note', proposal.estado === 'ganadora' ? 'Ganadora de la votación' : 'Resultados cerrados'));
            }
            card.append(header, items, footer);
            list.append(card);
        });
    }

    function trophyMini(label, value, icon) {
        const card = el('div', 'hub-mini-trophy');
        card.append(el('span', 'hub-mini-trophy__icon', icon), el('small', '', label), el('strong', '', value));
        return card;
    }

    function makeTrophyCard(title, icon, content, modifier = '') {
        const card = el('article', `hub-trophy-card${modifier ? ` ${modifier}` : ''}`);
        card.append(el('div', 'hub-trophy-card__icon', icon));
        const body = el('div', 'hub-trophy-card__body');
        body.append(el('p', 'hub-eyebrow', title));
        body.append(content);
        card.append(body);
        return card;
    }

    function rankingRow(user, rank, metric, viewerId) {
        const own = Number(user.user_id) === Number(viewerId) && Number(viewerId) > 0;
        const row = el('div', `hub-ranking-row${own ? ' is-you' : ''}`);
        row.dataset.userId = String(user.user_id || '');
        row.dataset.search = normalizeText(user.name || '');
        row.append(el('span', 'hub-ranking-row__rank', `#${rank}`));
        row.append(makeAvatar(user, 'hub-avatar hub-avatar--small'));
        row.append(el('span', 'hub-ranking-row__name', user.name || 'Banano'));
        row.append(el('span', 'hub-ranking-row__metric', metric));
        if (own) row.append(el('span', 'hub-chip hub-chip--pink', 'Tú'));
        return row;
    }

    function normalizeText(value) {
        return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
    }

    function makeRankingSection(title, list, metric, viewerId, emptyText = 'Todavía no hay datos para este trofeo.') {
        const section = el('section', 'hub-ranking-section');
        section.append(el('h4', '', title));
        if (!Array.isArray(list) || list.length === 0) {
            section.append(el('p', 'hub-empty-state hub-empty-state--small', emptyText));
            return section;
        }
        const rows = el('div', 'hub-ranking-list');
        list.forEach((user, index) => rows.append(rankingRow(user, index + 1, metric(user), viewerId)));
        section.append(rows);
        return section;
    }

    function rankingPanel(stats, viewerId) {
        const wrapper = el('section', 'hub-ranking');
        const title = el('h3', '', 'Ranking completo de ganadores');
        wrapper.append(title);
        const list = Array.isArray(stats.ranking_ganadores) ? stats.ranking_ganadores : [];
        const myRank = list.findIndex(user => Number(user.user_id) === Number(viewerId) && Number(viewerId) > 0);
        const rankChip = el('p', 'hub-ranking__position', myRank >= 0 ? `Tu posición: #${myRank + 1}` : 'Busca tu nombre entre los mejores 500.');
        if (Number(viewerId) > 0 && myRank < 0) rankChip.classList.add('is-muted');
        wrapper.append(rankChip);
        const search = el('input', 'hub-ranking__search');
        search.type = 'search';
        search.placeholder = 'Buscar por nombre…';
        search.setAttribute('aria-label', 'Buscar en el ranking de ganadores');
        search.dataset.action = 'search-ranking';
        wrapper.append(search);
        const rows = el('div', 'hub-ranking-list hub-ranking-list--full');
        list.forEach((user, index) => rows.append(rankingRow(user, index + 1, user.wins === 1 ? '1 victoria' : `${formatCount(user.wins)} victorias`, viewerId)));
        if (list.length === 0) rows.append(el('p', 'hub-empty-state hub-empty-state--small', 'Todavía no hay ganadores registrados.'));
        wrapper.append(rows);
        return wrapper;
    }

    function makeStatsDetail(stats, viewerId) {
        const detail = el('div', 'hub-hall-detail');
        const winners = Array.isArray(stats.top_ganadores) ? stats.top_ganadores : [];
        const king = winners[0];
        const kingCopy = king
            ? el('p', 'hub-trophy-highlight', `#1 ${king.name} · ${formatCount(king.wins)} ${king.wins === 1 ? 'victoria' : 'victorias'}`)
            : el('p', 'hub-empty-state hub-empty-state--small', 'El trono espera a su primera leyenda.');
        detail.append(makeTrophyCard('Rey de los Sorteos', '👑', kingCopy, 'hub-trophy-card--king'));

        const top10Toggle = el('button', 'hub-text-button', 'Ver Top 10 de ganadores');
        top10Toggle.type = 'button';
        top10Toggle.dataset.action = 'toggle-top10';
        top10Toggle.setAttribute('aria-expanded', 'false');
        const top10Panel = el('div', 'hub-toggle-panel');
        top10Panel.hidden = true;
        top10Panel.append(makeRankingSection('Top 10 · victorias', winners.slice(0, 10), user => `${formatCount(user.wins)} victorias`, viewerId, 'Todavía no hay ganadores.'));
        detail.append(top10Toggle, top10Panel);

        const fullToggle = el('button', 'hub-text-button', 'Ver ranking completo');
        fullToggle.type = 'button';
        fullToggle.dataset.action = 'toggle-ranking';
        fullToggle.setAttribute('aria-expanded', 'false');
        const fullPanel = el('div', 'hub-toggle-panel');
        fullPanel.hidden = true;
        fullPanel.append(rankingPanel(stats, viewerId));
        detail.append(fullToggle, fullPanel);

        const streaks = Array.isArray(stats.top_rachas) ? stats.top_rachas : [];
        const streak = streaks[0];
        const streakCopy = streak
            ? el('p', 'hub-trophy-highlight', `${streak.name} · mejor racha ${formatCount(streak.best_streak)} · actual ${formatCount(streak.current_streak)}`)
            : el('p', 'hub-empty-state hub-empty-state--small', 'Todavía no hay rachas que celebrar.');
        detail.append(makeTrophyCard('Racha Imparable', '🔥', streakCopy));
        detail.append(makeRankingSection('Rachas de la comunidad', streaks, user => `Récord ${formatCount(user.best_streak)} · actual ${formatCount(user.current_streak)}`, viewerId));

        const worst = Array.isArray(stats.peor_suerte) ? stats.peor_suerte[0] : null;
        const worstCopy = worst
            ? el('p', 'hub-trophy-highlight', `${worst.name} · ${formatCount(worst.participations)} participaciones y aún sin victoria. ¡La próxima puede ser la buena!`)
            : el('p', 'hub-empty-state hub-empty-state--small', 'La próxima historia de suerte está por escribirse.');
        detail.append(makeTrophyCard('Trofeo Peor Suerte', '💔', worstCopy, 'hub-trophy-card--luck'));
        detail.append(makeRankingSection('Más participaciones sin victoria', stats.peor_suerte || [], user => `${formatCount(user.participations)} sorteos`, viewerId));

        const participants = Array.isArray(stats.top_participantes) ? stats.top_participantes : [];
        detail.append(makeRankingSection('Comunidad más participativa', participants, user => `${formatCount(user.participations)} participaciones · ${formatCount(user.wins)} victorias`, viewerId));

        const choices = stats.elecciones || {};
        const doubleRate = Math.max(0, Math.min(100, Number(choices.doble_win_rate) || 0));
        const thermometer = el('div', 'hub-thermometer');
        thermometer.append(el('div', 'hub-thermometer__copy', `Doble o Nada: ${doubleRate.toFixed(1)}% de victorias`));
        const bar = el('div', 'hub-thermometer__track');
        const fill = el('span', 'hub-thermometer__fill');
        fill.style.setProperty('--thermometer', `${doubleRate}%`);
        bar.append(fill);
        thermometer.append(bar);
        const distribution = el('div', 'hub-choice-distribution');
        [
            ['Original', choices.original, choices.pct && choices.pct.original, 'original'],
            ['Cashback', choices.cashback, choices.pct && choices.pct.cashback, 'cashback'],
            ['Doble', choices.doble, choices.pct && choices.pct.doble, 'double'],
        ].forEach(([label, count, pct, key]) => {
            const item = el('div', `hub-choice hub-choice--${key}`);
            item.append(el('span', '', label), el('strong', '', `${formatCount(count)} · ${Number(pct || 0).toFixed(1)}%`));
            distribution.append(item);
        });
        const doubleNote = el('p', 'hub-muted', `Doble: ${formatCount(choices.doble_ganados)} ganados · ${formatCount(choices.doble_perdidos)} perdidos.`);
        const choicesBody = el('div', 'hub-choice-body');
        choicesBody.append(thermometer, distribution, doubleNote);
        detail.append(makeTrophyCard('Termómetro del Doble o Nada', '🎲', choicesBody, 'hub-trophy-card--choices'));

        const latest = stats.ultimas_elecciones || {};
        const latestSection = el('section', 'hub-latest-choices');
        latestSection.append(el('h3', '', 'Últimas elecciones'));
        const latestGrid = el('div', 'hub-latest-choices__grid');
        [
            ['Original', latest.original, '🎁'],
            ['Cashback', latest.cashback, '🍌'],
            ['Doble o Nada', latest.doble, latest.doble && latest.doble.resultado === 'perdio' ? '💔' : '🎲'],
        ].forEach(([label, entry, icon]) => {
            const card = el('article', 'hub-latest-card');
            card.append(el('span', 'hub-latest-card__icon', icon));
            card.append(el('strong', '', label));
            if (entry) {
                card.append(el('span', 'hub-latest-card__name', entry.name || 'Ganador'));
                card.append(el('small', '', entry.resultado === 'perdio' ? 'Se perdió el doble' : (entry.resultado === 'gano' ? 'Ganó el doble' : `Sorteo #${formatCount(entry.raffle_id)}`)));
            } else {
                card.append(el('small', 'hub-muted', 'Aún sin registros'));
            }
            latestGrid.append(card);
        });
        latestSection.append(latestGrid);
        detail.append(latestSection);
        return detail;
    }

    function renderHall(stats) {
        const payload = stats || {};
        const winners = Array.isArray(payload.top_ganadores) ? payload.top_ganadores : [];
        const streaks = Array.isArray(payload.top_rachas) ? payload.top_rachas : [];
        const worst = Array.isArray(payload.peor_suerte) ? payload.peor_suerte[0] : null;
        const mini = $('#hall-compact');
        mini.replaceChildren();
        mini.append(
            trophyMini('Rey', winners[0] ? winners[0].name : 'Por descubrir', '👑'),
            trophyMini('Racha', streaks[0] ? `${streaks[0].best_streak} sorteos` : 'En camino', '🔥'),
            trophyMini('Peor suerte', worst ? worst.name : '¡Ánimo!', '💔'),
            trophyMini('Doble', `${Number(payload.elecciones && payload.elecciones.doble_win_rate || 0).toFixed(0)}%`, '🎲'),
        );
        $('#hall-full').replaceChildren(makeStatsDetail(payload, CONFIG.userId || 0));
        $('#hall-drawer-content').replaceChildren(makeStatsDetail(payload, CONFIG.userId || 0));
        const generated = Date.parse(payload.generated_at || '');
        $('#stats-updated').textContent = Number.isFinite(generated)
            ? `Actualizado ${new Intl.DateTimeFormat('es-PE', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(generated))} · Se actualiza cada 5 minutos`
            : 'Se actualiza cada 5 minutos';
    }

    function renderMeme(url) {
        const slot = $('#meme-slot');
        slot.replaceChildren();
        const imageUrl = safeUrl(url);
        if (!imageUrl) return;
        const section = el('section', 'hub-card hub-basement');
        section.append(el('p', 'hub-eyebrow', '🕳️ El Sótano'));
        section.append(el('h2', '', 'La esquina secreta de la comunidad'));
        const image = el('img', 'hub-basement__image');
        image.src = imageUrl;
        image.alt = 'Meme del Sótano de Sorteo Flash';
        image.loading = 'lazy';
        image.decoding = 'async';
        image.onerror = () => section.remove();
        section.append(image);
        slot.append(section);
    }

    function renderState(data) {
        state.vote = data;
        $('#proposal-spots').textContent = `Quedan ${formatCount(data.cupos_restantes)} de ${formatCount(data.max_propuestas)} cupos para proponer`;
        $('#proposal-reward').textContent = `Gana ${formatCoins(data.recompensa_bananos)} Bananos por proponer`;
        renderSummary(data.resumen || (data.stats && data.stats.resumen));
        renderLastWinner(data.ultimo_sorteo, data.my && data.my.ultimo_sorteo);
        renderCreatorCallout(data);
        renderProposals(data);
        renderHall(data.stats || {});
        renderMeme(data.meme_url);
    }

    function loadCatalog() {
        if (state.catalogLoaded || state.catalogBusy) return Promise.resolve();
        state.catalogBusy = true;
        $('#catalog-grid').replaceChildren(...Array.from({ length: 4 }, () => el('div', 'hub-skeleton hub-skeleton--tile')));
        return postForm({ action: CONFIG.voteAction || 'v7s', sub: 'catalogo' })
            .then(payload => {
                state.catalog = Array.isArray(payload.items) ? payload.items : [];
                state.catalogLoaded = true;
                state.catalogMaxPavos = Number(payload.max_pavos) || Number((state.vote && state.vote.max_pavos) || 1500);
                renderCategoryChips();
                renderCatalog();
                updateComboMeter();
            })
            .catch(error => {
                $('#catalog-grid').replaceChildren(el('div', 'hub-empty-state', 'No pudimos cargar los artículos de hoy. Cierra el creador e inténtalo otra vez.'));
                toast(error.message, 'error');
            })
            .finally(() => {
                state.catalogBusy = false;
            });
    }

    function renderCategoryChips() {
        const chips = $('#catalog-chips');
        chips.replaceChildren();
        const categories = [...new Set(state.catalog.map(item => String(item.section_name || 'Otros')))]
            .sort((a, b) => a.localeCompare(b, 'es'));
        [['all', 'Todas'], ...categories.map(category => [category, category])].forEach(([key, label]) => {
            const chip = el('button', `hub-filter-chip${state.selectedSection === key ? ' is-active' : ''}`, label);
            chip.type = 'button';
            chip.dataset.action = 'pick-category';
            chip.dataset.category = key;
            chip.setAttribute('aria-pressed', state.selectedSection === key ? 'true' : 'false');
            chips.append(chip);
        });
    }

    function selectedOccurrences(id) {
        return state.selected.filter(selectedId => selectedId === id).length;
    }

    function getCardPlate(item) {
        const out = Date.parse(item.out_date || '');
        if (Number.isFinite(out)) {
            const remaining = out - correctedNow();
            if (remaining > 0 && remaining < 24 * 60 * 60 * 1000) return { text: 'Termina hoy', variant: 'urgent' };
        }
        const banner = item.banner || {};
        const text = String(banner.value || '').trim();
        if (!text) return null;
        return banner.intensity === 'High'
            ? { text: `¡${text}!`, variant: 'info' }
            : { text, variant: 'offer' };
    }

    function applyCardImageFraming(image, item) {
        const type = String(item.type || '').toLocaleLowerCase('es');
        if (type.includes('gesto') || type.includes('envoltorio') || type.includes('estela') || type.includes('música') || type.includes('rueda')) {
            image.style.setProperty('--img-scale', '.7');
            image.style.setProperty('--img-top', '38%');
        } else if (type.includes('pico') || type.includes('instrumento')) {
            image.style.setProperty('--img-scale', '.75');
            image.style.setProperty('--img-left', '37.8%');
            image.style.setProperty('--img-top', '42%');
        } else if (type.includes('ala delta') || type.includes('mochila')) {
            image.style.setProperty('--img-scale', '.78');
            image.style.setProperty('--img-top', '42%');
        } else if (type.includes('calzado')) {
            image.style.setProperty('--img-scale', '.8');
            image.style.setProperty('--img-top', '42%');
        } else if (type.includes('pista')) {
            image.style.setProperty('--img-scale', '.875');
            image.style.setProperty('--img-left', '27.5%');
            image.style.setProperty('--img-top', '50%');
            image.style.setProperty('--img-fit', 'contain');
        } else if (item.is_bundle) {
            image.style.setProperty('--img-scale', '1');
            image.style.setProperty('--img-top', '55%');
            image.style.setProperty('--img-fit', 'cover');
        }
    }

    function createShopCard(item) {
        const button = el('button', 'item-card-wrapper item-tile hub-shop-card');
        button.type = 'button';
        button.dataset.action = 'add-item';
        button.dataset.offerId = String(item.offer_id || '');
        button.dataset.itemType = String(item.type || '');
        if (item.item_id) button.dataset.itemId = String(item.item_id);
        if (item.is_dynamic_bundle) button.dataset.dynamicBundle = '1';
        if (item.is_car_offer) button.dataset.carOffer = '1';
        if (item.rarity_id) button.dataset.rarityId = String(item.rarity_id).toLowerCase();
        if (item.series_name) button.dataset.series = String(item.series_name);
        const colors = item.tile_colors || {};
        const color1 = safeColor(colors.color1);
        const color3 = safeColor(colors.color3);
        const textColor = safeColor(colors.text_background_color);
        if (color1) button.style.setProperty('--shop-color-1', color1);
        if (color3) button.style.setProperty('--shop-color-3', color3);
        if (textColor) {
            const hex = textColor.slice(1);
            const rgb = [0, 2, 4].map(index => parseInt(hex.slice(index, index + 2), 16)).join(', ');
            button.style.setProperty('--card-overlay-rgb', rgb);
        }
        const content = el('span', 'item-card-content');
        const imageBox = el('span', 'item-image-container');
        const imageUrl = safeUrl(item.image);
        if (imageUrl) {
            const image = el('img', 'item-image');
            image.src = imageUrl;
            image.alt = item.name || '';
            image.loading = 'lazy';
            image.decoding = 'async';
            applyCardImageFraming(image, item);
            imageBox.append(image);
        } else {
            imageBox.append(el('span', 'hub-shop-card__placeholder', '🍌'));
        }
        content.append(imageBox);

        const overlay = el('span', 'card-info-overlay');
        const plateModel = getCardPlate(item);
        if (plateModel) {
            const plate = el('span', `card-plate card-plate--${plateModel.variant}`, plateModel.text);
            plate.dataset.plateVariant = plateModel.variant;
            overlay.append(plate);
        }
        const body = el('span', 'card-info-body');
        body.append(el('span', 'card-title', item.name || 'Artículo de Fortnite'));
        const pricing = el('span', 'card-footer-pricing');
        const vbucks = el('span', 'vbucks-block');
        const icon = el('img', 'vbucks-icon');
        icon.src = VBUCK_ICON;
        icon.alt = '';
        icon.loading = 'lazy';
        vbucks.append(icon, el('span', 'vbucks-price', formatCount(item.pavos)));
        pricing.append(vbucks);
        const pricePill = el('span', 'hub-shop-card__price-label', 'PaVos');
        pricing.append(pricePill);
        body.append(pricing);
        overlay.append(body);
        const tagline = el('span', 'card-tagline', item.section_name || item.type || 'Tienda');
        overlay.append(tagline);
        content.append(overlay);
        button.append(content);
        const selected = selectedOccurrences(String(item.offer_id || ''));
        if (selected > 0) {
            const count = el('span', 'hub-shop-card__selected', `×${selected}`);
            count.setAttribute('aria-label', `Añadido ${selected} ${selected === 1 ? 'vez' : 'veces'}`);
            button.append(count);
        }
        button.setAttribute('aria-label', `${item.name || 'Artículo'}, ${formatCount(item.pavos)} PaVos${selected ? `, añadido ${selected} veces` : ''}. Añadir al combo.`);
        return button;
    }

    function renderCatalog() {
        const grid = $('#catalog-grid');
        grid.replaceChildren();
        const query = normalizeText(state.search.trim());
        const items = state.catalog.filter(item => {
            const matchesCategory = state.selectedSection === 'all' || item.section_name === state.selectedSection;
            const matchesName = !query || normalizeText(item.name).includes(query);
            return matchesCategory && matchesName;
        });
        if (items.length === 0) {
            grid.append(el('div', 'hub-empty-state', 'No encontramos artículos con esos filtros. Prueba otra sección o nombre.'));
            return;
        }
        items.forEach(item => grid.append(createShopCard(item)));
    }

    function renderSlots() {
        const holder = $('#combo-slots');
        holder.replaceChildren();
        const slots = Math.min(12, Math.max(3, state.selected.length));
        for (let index = 0; index < slots; index += 1) {
            const slot = state.selected[index];
            const button = el('button', `hub-slot${slot ? ' is-filled' : ''}`, '');
            button.type = 'button';
            button.setAttribute('aria-label', slot ? `Quitar artículo ${index + 1} del combo` : `Ranura ${index + 1} vacía`);
            if (slot) {
                const item = state.catalog.find(candidate => String(candidate.offer_id) === slot);
                if (item && safeUrl(item.image)) {
                    const image = el('img', 'hub-slot__image');
                    image.src = safeUrl(item.image);
                    image.alt = '';
                    image.loading = 'lazy';
                    button.append(image);
                }
                button.append(el('span', 'hub-slot__name', item ? item.name : 'Artículo'));
                button.append(el('span', 'hub-slot__remove', '×'));
                button.dataset.action = 'remove-slot';
                button.dataset.slotIndex = String(index);
            } else {
                button.disabled = true;
                button.append(el('span', 'hub-slot__number', String(index + 1)), el('span', 'hub-slot__empty', 'Elige'));
            }
            holder.append(button);
        }
        $('#slot-count').textContent = `${state.selected.length} ${state.selected.length === 1 ? 'artículo' : 'artículos'}`;
    }

    function updateComboMeter() {
        const max = Number(state.catalogMaxPavos || (state.vote && state.vote.max_pavos)) || 1500;
        const total = state.selected.reduce((sum, id) => {
            const item = state.catalog.find(candidate => String(candidate.offer_id) === id);
            return sum + (Number(item && item.pavos) || 0);
        }, 0);
        const over = total > max;
        const pct = max > 0 ? Math.min(100, (total / max) * 100) : 0;
        const meter = $('.hub-meter');
        meter.classList.toggle('is-over', over);
        $('#combo-total').textContent = `Total: ${formatCount(total)} / ${formatCount(max)} PaVos`;
        $('#combo-meter-help').textContent = over
            ? `Te pasaste por ${formatCount(total - max)} PaVos. Quita algún artículo para continuar.`
            : (state.selected.length ? '¡Vas muy bien! Aún puedes añadir más premios.' : 'Agrega artículos para empezar.');
        $('#combo-meter-fill').style.setProperty('--meter-pct', `${pct}%`);
        const track = $('#combo-meter-track');
        track.setAttribute('aria-valuenow', String(Math.round(pct)));
        const submit = $('#combo-submit');
        submit.disabled = state.selected.length === 0 || over || state.proposing;
        submit.textContent = state.proposing ? 'Enviando…' : 'Enviar propuesta';
        $('#combo-submit-hint').textContent = state.selected.length === 0
            ? 'Tus artículos aparecerán arriba.'
            : (over ? 'Ajusta el total antes de enviar.' : 'Tu propuesta hará crecer la bóveda.');
        renderSlots();
        renderCatalog();
    }

    function openCombo() {
        if (!state.vote || !state.vote.votacion_abierta) return;
        if (!CONFIG.loggedIn) {
            toast('Inicia sesión para proponer tu combo y recibir Bananos.', 'info', { label: 'Iniciar sesión', href: CONFIG.loginUrl });
            return;
        }
        const dialog = $('#combo-dialog');
        state.selected = [];
        state.selectedSection = 'all';
        state.search = '';
        $('#catalog-search').value = '';
        updateComboMeter();
        dialog.showModal();
        loadCatalog();
    }

    function closeCombo() {
        const dialog = $('#combo-dialog');
        if (!dialog.open) return;
        if (state.selected.length > 0) {
            state.pendingConfirm = 'close-combo';
            $('#confirm-title').textContent = '¿Cerrar el creador?';
            $('#confirm-copy').textContent = 'Tu selección todavía no se ha enviado.';
            $('#confirm-dialog').showModal();
            return;
        }
        dialog.close();
    }

    async function submitProposal() {
        if (state.proposing || !state.selected.length) return;
        if (!CONFIG.loggedIn) {
            toast('Inicia sesión para proponer tu combo.', 'info', { label: 'Iniciar sesión', href: CONFIG.loginUrl });
            return;
        }
        state.proposing = true;
        updateComboMeter();
        try {
            const result = await postForm({
                action: CONFIG.voteAction || 'v7s',
                sub: 'proponer',
                items: JSON.stringify(state.selected),
            });
            const amount = formatCoins(result.recompensa_acreditada || 0);
            state.selected = [];
            $('#combo-dialog').close();
            toast(`¡Propuesta enviada! Recibiste ${amount} Bananos 🍌`, 'success');
            await loadVoteState();
        } catch (error) {
            if (error.status === 401 || (error.data && error.data.require_login)) {
                toast(error.message, 'error', { label: 'Iniciar sesión', href: CONFIG.loginUrl });
            } else {
                toast(error.message, 'error');
            }
        } finally {
            state.proposing = false;
            updateComboMeter();
        }
    }

    async function castVote(proposalId) {
        const id = Number(proposalId);
        if (!id || state.voting.has(id)) return;
        if (!CONFIG.loggedIn) {
            toast('Inicia sesión para votar por un combo.', 'info', { label: 'Iniciar sesión', href: CONFIG.loginUrl });
            return;
        }
        state.voting.add(id);
        if (state.vote) renderProposals(state.vote);
        try {
            await postForm({ action: CONFIG.voteAction || 'v7s', sub: 'votar', propuesta_id: id });
            toast('¡Tu voto quedó registrado! 🍌', 'success');
            await loadVoteState();
        } catch (error) {
            if (error.status === 401 || (error.data && error.data.require_login)) {
                toast(error.message, 'error', { label: 'Iniciar sesión', href: CONFIG.loginUrl });
            } else {
                toast(error.message, 'error');
            }
        } finally {
            state.voting.delete(id);
            if (state.vote) renderProposals(state.vote);
        }
    }

    async function loadVoteState() {
        const payload = await postForm({ action: CONFIG.voteAction || 'v7s', sub: 'estado' });
        renderState(payload);
        return payload;
    }

    function togglePanel(button, panel) {
        const expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        button.textContent = expanded
            ? (button.dataset.action === 'toggle-top10' ? 'Ver Top 10 de ganadores' : 'Ver ranking completo')
            : (button.dataset.action === 'toggle-top10' ? 'Ocultar Top 10' : 'Ocultar ranking');
        panel.hidden = expanded;
    }

    function onClick(event) {
        const button = event.target.closest('[data-action]');
        if (!button || !root.contains(button) || button.disabled) return;
        const action = button.dataset.action;
        if (action !== 'add-item' && action !== 'vote' && action !== 'remove-slot') event.preventDefault();
        switch (action) {
            case 'login':
                window.location.assign(CONFIG.loginUrl || '/login');
                break;
            case 'open-combo':
                openCombo();
                break;
            case 'close-combo':
                closeCombo();
                break;
            case 'submit-proposal':
                submitProposal();
                break;
            case 'add-item': {
                if (state.selected.length >= 12) {
                    toast('Tu combo ya tiene 12 premios. ¡Una idea poderosa!', 'info');
                    break;
                }
                state.selected.push(String(button.dataset.offerId || ''));
                updateComboMeter();
                break;
            }
            case 'remove-slot':
                state.selected.splice(Number(button.dataset.slotIndex), 1);
                updateComboMeter();
                break;
            case 'pick-category':
                state.selectedSection = button.dataset.category || 'all';
                renderCategoryChips();
                renderCatalog();
                break;
            case 'vote':
                castVote(button.dataset.proposalId);
                break;
            case 'open-hall':
                $('#hall-dialog').showModal();
                break;
            case 'close-hall':
                $('#hall-dialog').close();
                break;
            case 'toggle-top10':
            case 'toggle-ranking': {
                const panel = button.nextElementSibling;
                if (panel) togglePanel(button, panel);
                break;
            }
            case 'cancel-confirm':
                state.pendingConfirm = null;
                $('#confirm-dialog').close();
                break;
            case 'confirm-close':
                state.pendingConfirm = null;
                $('#confirm-dialog').close();
                $('#combo-dialog').close();
                state.selected = [];
                updateComboMeter();
                break;
            default:
                break;
        }
    }

    function onInput(event) {
        const target = event.target;
        if (target.id === 'catalog-search') {
            state.search = target.value || '';
            renderCatalog();
            return;
        }
        if (target.matches('[data-action="search-ranking"]')) {
            const query = normalizeText(target.value);
            const list = target.closest('.hub-ranking').querySelector('.hub-ranking-list--full');
            Array.from(list.querySelectorAll('.hub-ranking-row')).forEach(row => {
                row.hidden = query !== '' && !row.dataset.search.includes(query);
            });
        }
    }

    function onDialogCancel(event) {
        if (event.target.id === 'combo-dialog' && state.selected.length > 0) {
            event.preventDefault();
            closeCombo();
        }
    }

    function onDialogBackdrop(event) {
        if (event.target === $('#combo-dialog')) closeCombo();
        if (event.target === $('#hall-dialog')) $('#hall-dialog').close();
        if (event.target === $('#confirm-dialog')) {
            state.pendingConfirm = null;
            $('#confirm-dialog').close();
        }
    }

    function startStatusPolling() {
        window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                getRaffleStatus().catch(() => {});
            }
        }, 60_000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') getRaffleStatus().catch(() => {});
        });
    }

    async function init() {
        root.addEventListener('click', onClick);
        root.addEventListener('input', onInput);
        $('#combo-dialog').addEventListener('cancel', onDialogCancel);
        [$('#combo-dialog'), $('#hall-dialog'), $('#confirm-dialog')].forEach(dialog => dialog.addEventListener('click', onDialogBackdrop));
        state.countdownTimer = window.setInterval(updateCountdown, 1000);
        const results = await Promise.allSettled([loadVoteState(), getRaffleStatus()]);
        if (results[0].status === 'rejected') {
            $('#proposal-list').replaceChildren(el('div', 'hub-empty-state', 'No pudimos cargar las propuestas. Revisa tu conexión e inténtalo de nuevo.'));
            renderCreatorCallout({ votacion_abierta: false, cupos_restantes: 0, my: {} });
            toast(results[0].reason.message || 'No pudimos cargar el Hub.', 'error');
        }
        if (results[1].status === 'rejected') {
            $('#hub-hero').dataset.state = 'error';
            $('#hero-badge').textContent = 'Estado temporalmente indisponible';
            $('#hero-title').textContent = '¡La comunidad sigue aquí!';
            $('#hero-copy').textContent = 'No pudimos consultar el sorteo. Inténtalo nuevamente en un momento.';
        }
        startStatusPolling();
    }

    init();
})();

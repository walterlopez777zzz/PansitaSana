(function () {
    'use strict';

    const CONFIG = window.SORTEO_HUB_CONFIG || {};
    const root = document.getElementById('hub-root');
    if (!root) return;

    const $ = (selector, scope = root) => scope.querySelector(selector);
    const $$ = (selector, scope = root) => Array.from(scope.querySelectorAll(selector));
    const state = {
        vote: null,
        raffleStatus: null,
        serverOffset: 0,
        catalog: [],
        catalogLoaded: false,
        catalogBusy: false,
        catalogMaxPavos: 1500,
        selected: [],
        selectedSection: 'all',
        search: '',
        proposing: false,
        voting: new Set(),
        statusBusy: false,
        redirecting: false,
        voteCelebration: 0,
        historyLoaded: false,
        historyLoading: false,
        lastWinners: [],
        lastRaffle: null,
        lastWinnerIndex: 0,
    };
    const countFormat = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 0 });
    const moneyFormat = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const coinFormat = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 2 });
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const VBUCK_ICON = 'https://fortnite-api.com/images/vbuck.png';
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const hasIntersectionObserver = 'IntersectionObserver' in window;
    const observedReveals = new WeakSet();
    let revealObserver = null;

    // API y descifrado
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
        if (state.statusBusy || document.visibilityState !== 'visible') return null;
        state.statusBusy = true;
        const startedAt = Date.now();
        try {
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
            if (activeRaffle(payload) && !state.redirecting) {
                state.redirecting = true;
                toast('¡El sorteo empezó! Entrando a la sala…', 'info');
                window.setTimeout(() => window.location.replace(CONFIG.roomUrl || '/juegos/sorteo_flash/'), 600);
            }
            renderHero();
            updateCountdown();
            return payload;
        } finally {
            state.statusBusy = false;
        }
    }

    // Utilidades de formato y nodos seguros
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function icon(name, className = 'hub-icon') {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', className);
        svg.setAttribute('aria-hidden', 'true');
        const use = document.createElementNS(SVG_NS, 'use');
        use.setAttribute('href', `#icon-${name}`);
        svg.append(use);
        return svg;
    }

    function formatCount(value) {
        return countFormat.format(Number(value) || 0);
    }

    function formatCoins(value) {
        return coinFormat.format(Number(value) || 0).replace(/([,.]0+)$/, '');
    }

    function normalizeText(value) {
        return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
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
        item.append(el('p', 'hub-toast__copy', message));
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

    function makeAvatar(user, className = 'hub-avatar') {
        const wrap = el('span', className);
        const name = String(user && (user.name || user.public_display_name) || 'Banano').trim();
        const initials = name.split(/\s+/).slice(0, 2).map(part => part.charAt(0)).join('').toLocaleUpperCase('es') || 'B';
        const path = avatarUrl(user && user.avatar);
        if (path) {
            const image = el('img');
            image.src = path;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            image.onerror = () => {
                image.remove();
                wrap.textContent = initials;
            };
            wrap.append(image);
        } else {
            wrap.textContent = initials;
        }
        wrap.setAttribute('aria-hidden', 'true');
        return wrap;
    }

    function makePrizeThumb(source, className = '') {
        const thumb = el('span', `hub-prize-thumb${className ? ` ${className}` : ''}`);
        const fallback = el('span', 'hub-prize-thumb__fallback');
        fallback.append(icon('gift'));
        thumb.append(fallback);
        const url = safeUrl(source);
        if (url) {
            const image = el('img');
            image.src = url;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            image.onerror = () => image.remove();
            thumb.append(image);
        }
        return thumb;
    }

    function winnerDelivery(winner) {
        if (winner.choice === 'double_lost') {
            return el('span', 'hub-delivery hub-delivery--lost', 'Doble perdido');
        }
        const delivered = winner.delivery_status === 'delivered';
        return el('span', `hub-delivery hub-delivery--${delivered ? 'done' : 'pending'}`,
            delivered ? 'Entregado' : 'En camino');
    }

    function makeVerified(user) {
        if (!user || !user.is_verified) return null;
        const mark = el('span', 'hub-verified', '✓');
        mark.setAttribute('aria-label', 'Cuenta verificada');
        return mark;
    }

    function animateCount(node, target, decimals = 0, suffix = '') {
        if (!node) return;
        const value = Number(target) || 0;
        const formatter = decimals
            ? new Intl.NumberFormat('es-PE', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
            : countFormat;
        if (node.dataset.counted === 'true' || reducedMotion) {
            node.textContent = `${formatter.format(value)}${suffix}`;
            node.dataset.counted = 'true';
            return;
        }
        node.dataset.counted = 'true';
        const start = performance.now();
        const duration = 900;
        const draw = now => {
            const progress = Math.min(1, (now - start) / duration);
            const eased = 1 - ((1 - progress) ** 3);
            node.textContent = `${formatter.format(value * eased)}${suffix}`;
            if (progress < 1) requestAnimationFrame(draw);
        };
        requestAnimationFrame(draw);
    }

    function finalCount(node) {
        if (!node) return;
        const value = Number(node.dataset.countUp) || 0;
        const decimals = Number(node.dataset.decimals) || 0;
        const formatter = decimals
            ? new Intl.NumberFormat('es-PE', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
            : countFormat;
        node.textContent = `${formatter.format(value)}${node.dataset.suffix || ''}`;
        node.dataset.counted = 'true';
    }

    function revealElement(node) {
        node.classList.add('is-revealed');
        const counters = [];
        if (node.matches('[data-count-up]')) counters.push(node);
        counters.push(...$$('[data-count-up]', node));
        counters.forEach(counter => {
            if (reducedMotion || !hasIntersectionObserver) finalCount(counter);
            else animateCount(counter, counter.dataset.countUp, Number(counter.dataset.decimals) || 0, counter.dataset.suffix || '');
        });
    }

    function observeReveals(scope = root) {
        const candidates = [];
        if (scope.matches && scope.matches('.hub-reveal')) candidates.push(scope);
        candidates.push(...$$('.hub-reveal', scope));
        let index = 0;
        candidates.forEach(node => {
            if (observedReveals.has(node)) return;
            observedReveals.add(node);
            node.style.setProperty('--reveal-delay', `${Math.min(index * 70, 350)}ms`);
            index += 1;
            if (reducedMotion || !hasIntersectionObserver || node.classList.contains('is-revealed')) {
                revealElement(node);
            } else if (revealObserver) {
                revealObserver.observe(node);
            } else {
                revealElement(node);
            }
        });
    }

    function setupRevealObserver() {
        if (!hasIntersectionObserver) {
            observeReveals();
            return;
        }
        document.documentElement.classList.add('hub-js');
        revealObserver = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                revealElement(entry.target);
                revealObserver.unobserve(entry.target);
            });
        }, { threshold: 0.14 });
        observeReveals();
    }

    // Encabezado y cuenta atrás de Lima
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
        if (!hero || !state.raffleStatus) return;
        const alertState = isSundayInLima(correctedNow());
        hero.dataset.state = alertState ? 'alert' : 'ordinary';
        $('#hero-badge').textContent = alertState ? '¡ALERTA FLASH!' : 'Cuenta atrás';
        $('#hero-copy').textContent = alertState
            ? 'Atento a la comunidad de WhatsApp, el sorteo puede iniciar en cualquier momento.'
            : 'El sorteo se realizará en el lapso de las 24h del domingo.';
        $('#hero-countdown').hidden = alertState;
    }

    function updateCountdown() {
        const countdown = $('#hero-countdown');
        if (!countdown || countdown.hidden || !state.raffleStatus) return;
        const now = correctedNow();
        const remaining = Math.max(0, nextSundayInLima(now) - now);
        const totalSeconds = Math.floor(remaining / 1000);
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        $('#hero-days').textContent = String(days).padStart(2, '0');
        $('#hero-hours').textContent = String(hours).padStart(2, '0');
        $('#hero-min').textContent = String(minutes).padStart(2, '0');
        $('#hero-sec').textContent = String(seconds).padStart(2, '0');
    }

    // Bóveda y último sorteo
    function renderOdometer(node, value) {
        if (!node) return;
        const amount = Math.max(0, Number(value) || 0);
        const formatted = coinFormat.format(amount);
        node.replaceChildren();
        node.setAttribute('aria-label', `${formatCoins(amount)} Bananos`);
        Array.from(formatted).forEach((character, index) => {
            if (!/\d/.test(character)) {
                node.append(el('span', 'hub-odometer__separator', character));
                return;
            }
            const digit = el('span', 'hub-odometer__digit');
            const roll = el('span', 'hub-odometer__roll');
            roll.style.setProperty('--digit', reducedMotion ? character : '0');
            roll.style.setProperty('--digit-index', String(index));
            for (let number = 0; number <= 9; number += 1) roll.append(el('span', '', String(number)));
            digit.append(roll);
            node.append(digit);
            if (!reducedMotion) requestAnimationFrame(() => roll.style.setProperty('--digit', character));
        });
    }

    function renderSummary(summary) {
        const totals = summary || {};
        const bananas = Number(totals.bananos_entregados) || 0;
        renderOdometer($('#summary-bananas'), bananas);
        const rafflesCount = $('#summary-raffles');
        rafflesCount.dataset.countUp = String(Number(totals.sorteos_realizados) || 0);
        rafflesCount.textContent = formatCount(totals.sorteos_realizados || 0);
        const soles = Number(totals.valor_soles) || 0;
        const solesLine = $('#summary-soles');
        solesLine.hidden = soles <= 0;
        if (soles > 0) solesLine.textContent = `≈ S/ ${moneyFormat.format(soles)}`;
        const fill = bananas > 0 ? Math.min(.72, .14 + Math.log10(bananas) / 9) : 0;
        $('#hub-vault').style.setProperty('--vault-fill', String(fill));
        requestAnimationFrame(() => $('#hub-vault').classList.add('is-loaded'));
    }

    const TROPHY_TONES = {
        gold: ['#fff6b8', '#f7c843', '#c4860f', '#8a5a06'],
        silver: ['#ffffff', '#d3dae2', '#8d99a6', '#5c6772'],
        bronze: ['#ffdcbc', '#e39357', '#a5562a', '#6e3517'],
    };

    function makeTrophy(tone, className = 'hub-trophy') {
        const colors = TROPHY_TONES[tone] || TROPHY_TONES.gold;
        const id = `hub-trophy-${tone}-${Math.random().toString(36).slice(2, 8)}`;
        const wrap = el('span', `${className} ${className}--${tone}`);
        wrap.setAttribute('aria-hidden', 'true');
        wrap.innerHTML = `<svg viewBox="0 0 64 74" focusable="false"><defs>
            <linearGradient id="${id}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${colors[0]}"/><stop offset=".45" stop-color="${colors[1]}"/><stop offset="1" stop-color="${colors[2]}"/></linearGradient>
            <linearGradient id="${id}-s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${colors[1]}"/><stop offset="1" stop-color="${colors[3]}"/></linearGradient></defs>
            <path d="M16 12H7c0 12 5 19 14 20M48 12h9c0 12-5 19-14 20" fill="none" stroke="url(#${id})" stroke-width="5" stroke-linecap="round"/>
            <path d="M13 5h38v13c0 14-8 23-19 23S13 32 13 18Z" fill="url(#${id})" stroke="${colors[3]}" stroke-width="1.5"/>
            <path d="M20 10v8c0 7 3 12 8 15" fill="none" stroke="#fff" stroke-opacity=".7" stroke-width="3" stroke-linecap="round"/>
            <path d="m32 13 2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.6-4.8 2.6.9-5.4-3.9-3.8 5.4-.8Z" fill="#fff" fill-opacity=".55"/>
            <path d="M28 41h8v9h-8z" fill="url(#${id}-s)"/>
            <path d="M20 50h24l3 9H17z" fill="url(#${id})" stroke="${colors[3]}" stroke-width="1.2"/>
            <rect x="13" y="59" width="38" height="12" rx="2.5" fill="#2b2433"/>
            <rect x="25" y="62.5" width="14" height="5" rx="1.2" fill="${colors[1]}"/></svg>`;
        return wrap;
    }

    function winnerStory(winner) {
        const choice = String(winner && winner.choice || '');
        if (choice === 'cashback') return { cls: 'cashback', choice: 'Cashback', icon: 'coin', result: winner.prize_name || 'Cashback en Bananos' };
        if (choice === 'double_won') return { cls: 'won', choice: 'Doble o Nada', icon: 'dice', result: `¡Ganó el doble! · ${winner.prize_name || 'Premio del sorteo'}` };
        if (choice === 'double_lost') return { cls: 'lost', choice: 'Doble o Nada', icon: 'dice', result: 'Se perdió en Doble o Nada' };
        return { cls: 'original', choice: 'Premio original', icon: 'gift', result: winner.prize_name || 'Premio del sorteo' };
    }

    function makeWinnerCard(winner, index) {
        const story = winnerStory(winner);
        const card = el('article', 'hub-tr-card');
        card.append(el('span', 'hub-tr-card__glow'), el('span', 'hub-tr-card__border'));
        const inner = el('div', 'hub-tr-card__inner');
        const avatarWrap = el('span', 'hub-tr-avatar');
        avatarWrap.append(el('span', 'hub-tr-avatar__ring'), makeAvatar(winner, 'hub-avatar hub-tr-avatar__img'));
        const name = el('h3', 'hub-tr-name', winner.name || 'Ganador');
        if (String(winner.name || '').length > 13) name.classList.add('is-long');
        const verified = makeVerified(winner);
        if (verified) name.append(verified);
        const storyBox = el('div', `hub-tr-story hub-tr-story--${story.cls}`);
        const choiceRow = el('div', 'hub-tr-story__row');
        const choiceValue = el('span', 'hub-tr-story__val');
        choiceValue.append(icon(story.icon), document.createTextNode(story.choice));
        choiceRow.append(el('span', 'hub-tr-story__key', 'Elección'), choiceValue);
        const resultRow = el('div', 'hub-tr-story__row');
        resultRow.append(el('span', 'hub-tr-story__key', 'Resultado'), el('span', 'hub-tr-story__val hub-tr-story__val--result', story.result));
        storyBox.append(choiceRow, el('span', 'hub-tr-story__divider'), resultRow);
        const details = el('button', 'hub-tr-details');
        details.type = 'button';
        details.dataset.action = 'open-winner';
        details.dataset.winnerIndex = String(index);
        details.append(icon('eye'), el('span', '', 'Ver detalles'), icon('chevron', 'hub-icon hub-tr-details__arrow'));
        inner.append(avatarWrap, name, storyBox, details);
        if (winner.choice !== 'double_lost') {
            const delivered = winner.delivery_status === 'delivered';
            const bar = el('div', `hub-tr-reward ${delivered ? 'is-delivered' : 'is-pending'}`);
            bar.append(el('span', 'hub-tr-reward__dot'), document.createTextNode(delivered ? 'Recompensa entregada' : 'Entrega en proceso'));
            inner.append(bar);
        }
        card.append(inner);
        return card;
    }

    function showLastWinner(index) {
        const winners = state.lastWinners || [];
        const winner = winners[index];
        if (!winner) return;
        state.lastWinnerIndex = index;
        $('#last-winner-content').replaceChildren(makeWinnerCard(winner, index));
        $$('.hub-winner-tab', $('#last-winner-tabs')).forEach(tab => {
            const active = Number(tab.dataset.winnerIndex) === index;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    function renderLastWinner(data, my) {
        const section = $('#last-winner');
        const fomo = $('#ticket-fomo');
        const tabs = $('#last-winner-tabs');
        fomo.replaceChildren();
        fomo.hidden = true;
        tabs.replaceChildren();
        tabs.hidden = true;
        if (!data || !Array.isArray(data.ganadores) || data.ganadores.length === 0) {
            section.hidden = true;
            return;
        }
        section.hidden = false;
        state.lastRaffle = data;
        state.lastWinners = data.ganadores;
        const edition = data.edicion ?? data.raffle_id;
        const dateLabel = data.end_time
            ? new Intl.DateTimeFormat('es-PE', { day: 'numeric', month: 'short', timeZone: 'America/Lima' }).format(new Date(data.end_time))
            : '';
        const meta = $('#last-raffle-meta');
        meta.replaceChildren(el('strong', '', `#${formatCount(edition)}`));
        if (dateLabel) meta.append(el('span', '', dateLabel));
        const results = $('#last-results-link');
        const raffleId = Number(data.raffle_id);
        results.href = raffleId > 0 ? `/juegos/sorteo_flash/?id=${encodeURIComponent(String(Math.floor(raffleId)))}` : '/juegos/sorteo_flash/';
        if (data.ganadores.length > 1) {
            tabs.hidden = false;
            data.ganadores.forEach((winner, index) => {
                const tab = el('button', 'hub-winner-tab');
                tab.type = 'button';
                tab.setAttribute('role', 'tab');
                tab.dataset.action = 'pick-winner';
                tab.dataset.winnerIndex = String(index);
                tab.setAttribute('aria-label', `Ganador #${winner.position || index + 1}: ${winner.name || 'Ganador'}`);
                tab.append(makeAvatar(winner, 'hub-avatar hub-avatar--small'), el('span', '', `#${winner.position || index + 1}`));
                tabs.append(tab);
            });
        }
        showLastWinner(0);
        if (my && my.participo) {
            fomo.hidden = false;
            if (my.gano) {
                fomo.append(el('strong', '', '¡Ganaste en este sorteo!'));
                fomo.append(el('span', '', `Tu ticket #${formatCount(my.mi_ticket)} salió premiado.`));
            } else {
                const gap = my.distancia === null || my.distancia === undefined ? 'muy cerca' : `${formatCount(my.distancia)} ${my.distancia === 1 ? 'ticket' : 'tickets'}`;
                fomo.append(el('strong', '', `A ${gap} de ganar`));
                fomo.append(el('span', '', `Tu ticket #${formatCount(my.mi_ticket)} · ganador #${my.ticket_ganador ?? '—'}`));
            }
        }
    }

    function openWinnerDetails(index) {
        const winner = (state.lastWinners || [])[index];
        if (!winner) return;
        const raffle = state.lastRaffle || {};
        const story = winnerStory(winner);
        const content = $('#winner-dialog-content');
        content.replaceChildren();
        const hero = el('div', `hub-wd-hero hub-wd-hero--${story.cls}`);
        hero.append(makePrizeThumb(winner.prize_image, 'hub-prize-thumb--hero'));
        const heroCopy = el('div', 'hub-wd-hero__copy');
        heroCopy.append(el('span', 'hub-wd-hero__key', 'Resultado'), el('strong', '', story.result));
        hero.append(heroCopy);
        const options = el('div', 'hub-wd-options');
        const chosen = story.cls === 'won' || story.cls === 'lost' ? 'double' : story.cls;
        [['original', 'Original', 'gift'], ['cashback', 'Cashback', 'coin'], ['double', 'Doble o Nada', 'dice']].forEach(([key, label, iconName]) => {
            const option = el('div', `hub-wd-option${key === chosen ? ' is-chosen' : ''}`);
            option.append(icon(iconName), el('span', '', label));
            options.append(option);
        });
        const facts = el('dl', 'hub-wd-facts');
        const edition = raffle.edicion ?? raffle.raffle_id;
        const timestamp = Date.parse(raffle.end_time || '');
        [
            ['Ganador', winner.name || 'Ganador'],
            ['Sorteo', `#${formatCount(edition)}`],
            ['Fecha', Number.isFinite(timestamp) ? new Intl.DateTimeFormat('es-PE', { dateStyle: 'medium', timeZone: 'America/Lima' }).format(new Date(timestamp)) : '—'],
            ['Ticket', winner.ticket_index !== null && winner.ticket_index !== undefined ? `#${formatCount(winner.ticket_index)}` : '—'],
            ['Participantes', formatCount(raffle.participantes)],
        ].forEach(([key, value]) => {
            const row = el('div');
            row.append(el('dt', '', key), el('dd', '', value));
            facts.append(row);
        });
        content.append(hero, el('h3', 'hub-wd-label', 'Elección'), options, facts, winnerDelivery(winner));
        $('#winner-dialog').showModal();
    }

    function historyWinnerRow(winner) {
        const row = el('div', 'hub-history-winner');
        row.append(el('span', 'hub-history-winner__position', `#${formatCount(winner.position)}`));
        row.append(makeAvatar(winner, 'hub-avatar hub-avatar--small'));
        const name = el('span', 'hub-history-winner__name');
        name.append(document.createTextNode(winner.name || 'Ganador'));
        const verified = makeVerified(winner);
        if (verified) name.append(verified);
        const prize = el('span', 'hub-history-winner__prize');
        prize.append(makePrizeThumb(winner.prize_image), el('span', '', winner.prize_name || 'Premio del sorteo'));
        row.append(name, prize, winnerDelivery(winner));
        return row;
    }

    function renderHistory(history) {
        const content = $('#history-content');
        content.replaceChildren();
        if (!Array.isArray(history) || history.length === 0) {
            const empty = el('div', 'hub-history-empty');
            empty.append(el('p', '', 'Todavía no hay sorteos pasados para mostrar.'));
            content.append(empty);
            return;
        }
        const list = el('div', 'hub-history-list');
        history.forEach(entry => {
            const card = el('article', 'hub-history-entry hub-reveal');
            const header = el('header', 'hub-history-entry__header');
            const edition = entry.edicion ?? entry.raffle_id;
            header.append(el('h3', '', `Edición #${formatCount(edition)}`));
            const meta = el('div', 'hub-history-entry__meta');
            const timestamp = Date.parse(entry.end_time || '');
            const date = Number.isFinite(timestamp)
                ? new Intl.DateTimeFormat('es-PE', { dateStyle: 'medium', timeZone: 'America/Lima' }).format(new Date(timestamp))
                : 'Fecha no disponible';
            meta.append(el('span', '', date), el('span', '', `${formatCount(entry.participantes)} participantes`));
            header.append(meta);
            card.append(header);
            const winners = el('div', 'hub-history-winners');
            (Array.isArray(entry.ganadores) ? entry.ganadores : []).forEach(winner => winners.append(historyWinnerRow(winner)));
            if (!winners.childNodes.length) winners.append(el('p', 'hub-empty-state hub-empty-state--small', 'No hay ganadores disponibles para esta edición.'));
            card.append(winners);
            const raffleId = Number(entry.raffle_id);
            if (raffleId > 0) {
                const footer = el('div', 'hub-history-entry__footer');
                const link = el('a', 'hub-results-link', 'Ver resultados');
                link.href = `/juegos/sorteo_flash/?id=${encodeURIComponent(String(Math.floor(raffleId)))}`;
                footer.append(link);
                card.append(footer);
            }
            list.append(card);
        });
        content.append(list);
        observeReveals(content);
    }

    async function loadHistory() {
        if (state.historyLoading || state.historyLoaded) return;
        state.historyLoading = true;
        const content = $('#history-content');
        content.replaceChildren(el('div', 'hub-skeleton hub-skeleton--proposal'));
        try {
            const payload = await postForm({ action: 'v7s', sub: 'historial' });
            renderHistory(payload.historial);
            state.historyLoaded = true;
        } catch (error) {
            const errorBox = el('div', 'hub-history-error');
            errorBox.append(el('p', '', 'No pudimos cargar el historial. Revisa tu conexión e inténtalo de nuevo.'));
            const retry = el('button', 'hub-button hub-button--soft', 'Reintentar');
            retry.type = 'button';
            retry.dataset.action = 'retry-history';
            errorBox.append(retry);
            content.replaceChildren(errorBox);
        } finally {
            state.historyLoading = false;
        }
    }

    function openHistory() {
        const dialog = $('#history-dialog');
        if (!dialog.open) dialog.showModal();
        loadHistory();
    }

    // Creador de combos y catálogo
    function selectedOccurrences(id) {
        return state.selected.filter(selectedId => selectedId === id).length;
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

    function getCardPlate(item) {
        const out = Date.parse(item.out_date || '');
        if (Number.isFinite(out) && out - correctedNow() > 0 && out - correctedNow() < 24 * 60 * 60 * 1000) {
            return { text: 'Termina hoy', variant: 'urgent' };
        }
        const banner = item.banner || {};
        const text = String(banner.value || '').trim();
        if (!text) return null;
        return banner.intensity === 'High' ? { text: `¡${text}!`, variant: 'info' } : { text, variant: 'offer' };
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
        } else if (type.includes('ala delta') || type.includes('mochila') || type.includes('calzado')) {
            image.style.setProperty('--img-scale', '.78');
            image.style.setProperty('--img-top', '42%');
        } else if (type.includes('pista')) {
            image.style.setProperty('--img-scale', '.875');
            image.style.setProperty('--img-left', '27.5%');
            image.style.setProperty('--img-top', '50%');
        } else if (item.is_bundle) {
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
            button.style.setProperty('--card-overlay-rgb', [0, 2, 4].map(index => parseInt(hex.slice(index, index + 2), 16)).join(', '));
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
            { const ph = el('span', 'hub-shop-card__placeholder'); ph.append(icon('gift')); imageBox.append(ph); }
        }
        content.append(imageBox);
        const overlay = el('span', 'card-info-overlay');
        const plateModel = getCardPlate(item);
        if (plateModel) overlay.append(el('span', `card-plate card-plate--${plateModel.variant}`, plateModel.text));
        const body = el('span', 'card-info-body');
        body.append(el('span', 'card-title', item.name || 'Artículo de Fortnite'));
        const pricing = el('span', 'card-footer-pricing');
        const vbucks = el('span', 'vbucks-block');
        const coin = el('img', 'vbucks-icon');
        coin.src = VBUCK_ICON;
        coin.alt = '';
        coin.loading = 'lazy';
        vbucks.append(coin, el('span', 'vbucks-price', formatCount(item.pavos)));
        pricing.append(vbucks, el('span', 'hub-shop-card__price-label', 'PaVos'));
        body.append(pricing);
        overlay.append(body, el('span', 'card-tagline', item.section_name || item.type || 'Tienda'));
        content.append(overlay);
        button.append(content);
        const selected = selectedOccurrences(String(item.offer_id || ''));
        if (selected > 0) {
            const badge = el('span', 'hub-shop-card__selected', `×${selected}`);
            badge.setAttribute('aria-label', `Añadido ${selected} ${selected === 1 ? 'vez' : 'veces'}`);
            button.append(badge);
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
            return matchesCategory && (!query || normalizeText(item.name).includes(query));
        });
        if (items.length === 0) {
            grid.append(el('div', 'hub-empty-state', 'No encontramos artículos con esos filtros. Prueba otra sección o nombre.'));
            return;
        }
        items.forEach(item => grid.append(createShopCard(item)));
    }

    async function loadCatalog() {
        if (state.catalogLoaded || state.catalogBusy) return;
        state.catalogBusy = true;
        $('#catalog-grid').replaceChildren(...Array.from({ length: 4 }, () => el('div', 'hub-skeleton hub-skeleton--tile')));
        try {
            const payload = await postForm({ action: CONFIG.voteAction || 'v7s', sub: 'catalogo' });
            state.catalog = Array.isArray(payload.items) ? payload.items : [];
            state.catalogLoaded = true;
            state.catalogMaxPavos = Number(payload.max_pavos) || Number((state.vote && state.vote.max_pavos) || 1500);
            renderCategoryChips();
            renderCatalog();
            updateComboMeter();
        } catch (error) {
            $('#catalog-grid').replaceChildren(el('div', 'hub-empty-state', 'No pudimos cargar los artículos de hoy. Cierra el creador e inténtalo otra vez.'));
            toast(error.message, 'error');
        } finally {
            state.catalogBusy = false;
        }
    }

    function renderSlots() {
        const holder = $('#combo-slots');
        holder.replaceChildren();
        const slots = Math.min(12, Math.max(3, state.selected.length));
        for (let index = 0; index < slots; index += 1) {
            const id = state.selected[index];
            const item = id ? state.catalog.find(candidate => String(candidate.offer_id) === id) : null;
            const button = el('button', `hub-slot${item ? ' is-filled' : ''}`);
            button.type = 'button';
            button.setAttribute('aria-label', item ? `Quitar ${item.name} del combo` : `Ranura ${index + 1} vacía`);
            if (item) {
                const colors = item.tile_colors || {};
                const tile = safeColor(colors.color1);
                const trim = safeColor(colors.color3);
                if (tile) button.style.setProperty('--slot-bg', tile);
                if (trim) button.style.setProperty('--slot-color', trim);
                if (safeUrl(item.image)) {
                    const image = el('img', 'hub-slot__image');
                    image.src = safeUrl(item.image);
                    image.alt = '';
                    image.loading = 'lazy';
                    button.append(image);
                }
                button.append(el('span', 'hub-slot__name', item.name || 'Artículo'), el('span', 'hub-slot__remove', '×'));
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
        if (state.catalogLoaded) renderCatalog();
    }

    function openCombo() {
        if (!state.vote || !state.vote.votacion_abierta || !CONFIG.loggedIn
            || Number(state.vote.cupos_restantes) <= 0 || (state.vote.my && state.vote.my.has_proposed)) {
            if (!CONFIG.loggedIn) toast('Inicia sesión para proponer tu combo y recibir Bananos.', 'info', { label: 'Iniciar sesión', href: CONFIG.loginUrl });
            return;
        }
        state.selected = [];
        state.selectedSection = 'all';
        state.search = '';
        $('#catalog-search').value = '';
        updateComboMeter();
        $('#combo-dialog').showModal();
        loadCatalog();
    }

    function closeCombo() {
        const dialog = $('#combo-dialog');
        if (!dialog.open) return;
        if (state.selected.length > 0) {
            $('#confirm-title').textContent = '¿Cerrar el creador?';
            $('#confirm-copy').textContent = 'Tu selección todavía no se ha enviado.';
            $('#confirm-dialog').showModal();
            return;
        }
        dialog.close();
    }

    // Votación y tarjetas de propuestas
    function renderCreatorCallout(data) {
        const callout = $('#creator-callout');
        if (!callout) return;
        const spots = Math.max(0, Number(data.cupos_restantes) || 0);
        const my = data.my || {};
        let message = '';
        let showButton = true;
        let buttonText = 'Crear mi combo ✨';
        let action = 'open-combo';
        if (!data.votacion_abierta) {
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
            message = 'Elige premios de la tienda y arma un combo para la comunidad.';
        }
        callout.replaceChildren(el('p', 'hub-creator-callout__copy', message));
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
            { const ph = el('span', 'hub-proposal-item__placeholder'); ph.append(icon('gift')); tile.append(ph); }
        }
        const info = el('span', 'hub-proposal-item__info');
        info.append(el('strong', '', item.name || 'Artículo'), el('small', '', `${formatCount(item.pavos)} PaVos`));
        tile.append(info);
        return tile;
    }

    function renderProposals(data) {
        const list = $('#proposal-list');
        if (!list) return;
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
            const celebrating = Number(proposal.id) === state.voteCelebration;
            const card = el('article', `hub-proposal-card${proposal.estado === 'ganadora' ? ' is-winner' : ''}${proposal.is_mine ? ' is-mine' : ''}${celebrating ? ' is-celebrating' : ''}`);
            const header = el('div', 'hub-proposal-card__header');
            const creator = el('div', 'hub-proposal-card__creator');
            creator.append(makeAvatar(proposal, 'hub-avatar'));
            const creatorName = el('div', 'hub-proposal-card__creator-name');
            creatorName.append(el('strong', '', proposal.creator_name || proposal.name || 'Banano de la comunidad'));
            const verified = makeVerified(proposal);
            if (verified) creatorName.append(verified);
            creator.append(creatorName);
            header.append(creator);
            const badges = el('div', 'hub-proposal-card__badges');
            if (proposal.is_mine) badges.append(el('span', 'hub-chip hub-chip--pink', 'Tu propuesta'));
            if (my.has_voted && Number(my.voted_propuesta_id) === Number(proposal.id)) {
                badges.append(el('span', 'hub-chip hub-chip--green', 'Tu voto'));
            }
            if (proposal.estado === 'ganadora') badges.append(el('span', 'hub-chip hub-chip--gold', 'Combo ganador'));
            header.append(badges);
            const items = el('div', 'hub-proposal-card__items');
            (Array.isArray(proposal.items) ? proposal.items : []).forEach(item => items.append(createProposalItem(item)));
            const stats = el('div', 'hub-proposal-card__stats');
            const itemCount = Number(proposal.items_count) || (Array.isArray(proposal.items) ? proposal.items.length : 0);
            const total = el('strong', 'hub-proposal-card__total', `${formatCount(proposal.total_pavos)} PaVos`);
            const prizes = el('span', 'hub-proposal-card__prizes');
            prizes.append(icon('gift'), document.createTextNode(`${formatCount(itemCount)} ${itemCount === 1 ? 'premio' : 'premios'}`));
            const votes = el('span', 'hub-proposal-card__votes');
            votes.append(icon('users'), document.createTextNode(`${formatCount(proposal.votos_count)} votos`));
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
            progress.append(fill, el('span', 'hub-vote-progress__label', `${percentage.toFixed(1)}%`));
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
        if (state.voteCelebration) {
            window.setTimeout(() => {
                state.voteCelebration = 0;
                $$('.hub-proposal-card.is-celebrating').forEach(card => card.classList.remove('is-celebrating'));
            }, 1100);
        }
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
            state.voteCelebration = id;
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

    function mountProposals(data) {
        const slot = $('#proposals-slot');
        const template = $('#proposals-template');
        if (!slot || !template) return;
        if (data.votacion_abierta === true) {
            if (!slot.querySelector('.hub-proposals')) {
                slot.replaceChildren(template.content.cloneNode(true));
            }
            return;
        }
        if (slot.childNodes.length) slot.replaceChildren();
    }

    // Trofeos, podios y estadísticas
    function rankingRow(user, rank, metric, viewerId) {
        const userId = Number(user.user_id) || 0;
        const own = userId > 0 && userId === Number(viewerId);
        const row = el('div', `hub-ranking-row${own ? ' is-you' : ''}`);
        row.dataset.userId = String(userId);
        row.dataset.search = normalizeText(user.name || '');
        row.append(el('span', 'hub-ranking-row__rank', `#${rank}`));
        row.append(makeAvatar(user, 'hub-avatar hub-avatar--small'));
        const name = el('span', 'hub-ranking-row__name');
        name.textContent = user.name || 'Banano';
        const verified = makeVerified(user);
        if (verified) name.append(verified);
        row.append(name, el('span', 'hub-ranking-row__metric', metric));
        if (own) row.append(el('span', 'hub-chip hub-chip--pink', 'Tú'));
        return row;
    }

    function rankingList(list, metric, viewerId, startRank = 1) {
        const rows = el('div', 'hub-ranking-list');
        if (!Array.isArray(list) || list.length === 0) {
            rows.append(el('p', 'hub-empty-state hub-empty-state--small', 'Todavía no hay datos para este trofeo.'));
            return rows;
        }
        list.forEach((user, index) => rows.append(rankingRow(user, Number(user.rank) || startRank + index, metric(user), viewerId)));
        return rows;
    }

    function podiumColumn(user, rank) {
        const variant = rank === 1 ? 'first' : (rank === 2 ? 'second' : 'third');
        const tone = rank === 1 ? 'gold' : (rank === 2 ? 'silver' : 'bronze');
        const column = el('div', `hub-podium__col hub-podium__col--${variant}`);
        const person = el('div', 'hub-podium__person');
        const avatar = el('span', 'hub-podium__avatar');
        avatar.append(makeAvatar(user || {}, 'hub-avatar'));
        const name = el('span', 'hub-podium__name', user ? (user.name || 'Banano') : 'Por descubrir');
        const verified = makeVerified(user);
        if (verified) name.append(verified);
        person.append(avatar, name);
        const step = el('div', 'hub-podium__step');
        step.append(makeTrophy(tone, 'hub-trophy'), el('span', 'hub-podium__numeral', String(rank)));
        column.append(person, step);
        if (user && Number(user.user_id) === Number(CONFIG.userId) && Number(CONFIG.userId) > 0) column.classList.add('is-you');
        return column;
    }

    function trophyCard(title, iconName, user, valueText, valueLabel, category, entries, metric, modifier = '') {
        const card = el('article', `hub-trophy-card hub-reveal${modifier ? ` ${modifier}` : ''}`);
        card.dataset.category = category;
        const heading = el('h3', 'hub-trophy-card__heading');
        const badge = el('span', 'hub-trophy-card__icon');
        badge.append(icon(iconName));
        heading.append(badge, el('span', '', title));
        card.append(heading);
        if (user) {
            const value = el('p', 'hub-trophy-card__value');
            value.append(el('strong', '', valueText(user)), el('span', '', valueLabel));
            const who = el('p', 'hub-trophy-card__who');
            const name = el('span', '', user.name || 'Banano');
            const verified = makeVerified(user);
            if (verified) name.append(verified);
            who.append(makeAvatar(user, 'hub-avatar hub-avatar--small'), name);
            const more = el('button', 'hub-text-button hub-category-button', 'Ver Top 10');
            more.type = 'button';
            more.dataset.action = 'open-category';
            more.dataset.category = category;
            card.append(value, who, more);
        } else {
            card.append(el('p', 'hub-empty-state hub-empty-state--small', 'Aún sin datos.'));
        }
        card._hubEntries = entries;
        card._hubMetric = metric;
        return card;
    }

    function choicePercent(choices, key) {
        if (choices.pct && choices.pct[key] !== undefined) return Math.max(0, Number(choices.pct[key]) || 0);
        const total = (Number(choices.original) || 0) + (Number(choices.cashback) || 0) + (Number(choices.doble) || 0);
        return total > 0 ? ((Number(choices[key]) || 0) / total) * 100 : 0;
    }

    function makeChoicePanel(stats) {
        const choices = stats.elecciones || {};
        const percentFormat = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        const panel = el('section', 'hub-card hub-choice-panel hub-reveal');
        const head = el('header', 'hub-card-head');
        const heading = el('h2');
        heading.append(icon('chart'), document.createTextNode('Métricas y elecciones'));
        head.append(heading);
        panel.append(head);
        const parts = [
            ['original', 'Premio original', 'original'],
            ['cashback', 'Cashback', 'cashback'],
            ['doble', 'Doble o Nada', 'double'],
        ];
        const total = parts.reduce((sum, [key]) => sum + (Number(choices[key]) || 0), 0);
        const triple = el('section', 'hub-metric hub-metric--choices');
        const tripleHead = el('div', 'hub-metric__head');
        tripleHead.append(el('h3', '', 'Elección de los ganadores'));
        const totalNode = el('strong', 'hub-metric__total', formatCount(total));
        totalNode.dataset.countUp = String(total);
        tripleHead.append(totalNode);
        const segmentBar = el('div', 'hub-choice-segments');
        segmentBar.setAttribute('role', 'img');
        segmentBar.setAttribute('aria-label', 'Distribución porcentual de las elecciones');
        const rows = el('div', 'hub-choice-rows');
        parts.forEach(([key, label, color]) => {
            const pct = choicePercent(choices, key);
            const fill = el('span', `hub-choice-segments__fill hub-choice-segments__fill--${color}`);
            fill.style.setProperty('--choice-pct', `${pct}%`);
            segmentBar.append(fill);
            const row = el('div', 'hub-choice-row');
            const labelNode = el('span', 'hub-choice-row__label');
            labelNode.append(el('span', `hub-choice-row__swatch hub-choice-row__swatch--${color}`), document.createTextNode(label));
            const count = el('strong', 'hub-choice-row__count', formatCount(choices[key]));
            count.dataset.countUp = String(Number(choices[key]) || 0);
            const percentage = el('span', 'hub-choice-row__pct', `${percentFormat.format(pct)}%`);
            percentage.dataset.countUp = String(pct);
            percentage.dataset.decimals = '1';
            percentage.dataset.suffix = '%';
            row.append(labelNode, count, percentage);
            rows.append(row);
        });
        triple.append(tripleHead, segmentBar, rows);

        const rate = Math.max(0, Math.min(100, Number(choices.doble_win_rate) || 0));
        const rateBox = el('section', 'hub-metric hub-metric--double');
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'hub-double-ring');
        svg.setAttribute('viewBox', '0 0 100 100');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', `Victorias en Doble o Nada: ${percentFormat.format(rate)} por ciento`);
        ['track', 'fill'].forEach(part => {
            const circle = document.createElementNS(SVG_NS, 'circle');
            circle.setAttribute('class', `hub-double-ring__${part}`);
            circle.setAttribute('cx', '50'); circle.setAttribute('cy', '50'); circle.setAttribute('r', '42');
            circle.setAttribute('pathLength', '100');
            if (part === 'fill') circle.style.setProperty('--ring-value', String(rate));
            svg.append(circle);
        });
        const ringWrap = el('div', 'hub-double-ring-wrap');
        const ringValue = el('strong', 'hub-double-ring-wrap__value', `${percentFormat.format(rate)}%`);
        ringValue.dataset.countUp = String(rate);
        ringValue.dataset.decimals = '1';
        ringValue.dataset.suffix = '%';
        ringWrap.append(svg, ringValue);
        const rateCopy = el('div', 'hub-double-copy');
        rateCopy.append(el('h3', '', 'Victorias en Doble o Nada'));
        const split = el('div', 'hub-double-split');
        [['won', 'Ganados', choices.doble_ganados], ['lost', 'Perdidos', choices.doble_perdidos]].forEach(([tone, label, value]) => {
            const box = el('div', `hub-double-split__item hub-double-split__item--${tone}`);
            const number = el('strong', '', formatCount(value));
            number.dataset.countUp = String(Number(value) || 0);
            box.append(number, el('span', '', label));
            split.append(box);
        });
        rateCopy.append(split);
        rateBox.append(ringWrap, rateCopy);
        panel.append(triple, rateBox, makeLatestChoices(stats));
        return panel;
    }

    function makeLatestChoices(stats) {
        const latest = stats.ultimas_elecciones || {};
        const section = el('section', 'hub-metric hub-metric--latest');
        section.append(el('h3', '', 'Últimas elecciones'));
        const list = el('div', 'hub-latest-list');
        [
            ['Original', latest.original, 'gift', 'original'],
            ['Cashback', latest.cashback, 'coin', 'cashback'],
            ['Doble o Nada', latest.doble, latest.doble && latest.doble.resultado === 'perdio' ? 'heart-broken' : 'dice', 'double'],
        ].forEach(([label, entry, iconName, tone]) => {
            const row = el('article', `hub-latest-row hub-latest-row--${tone}`);
            const badge = el('span', 'hub-latest-row__icon');
            badge.append(icon(iconName));
            row.append(badge);
            const copy = el('span', 'hub-latest-row__copy');
            copy.append(el('small', '', label));
            if (entry) {
                const name = el('strong', '', entry.name || 'Ganador');
                const verified = makeVerified(entry);
                if (verified) name.append(verified);
                copy.append(name);
                row.append(makeAvatar(entry, 'hub-avatar hub-avatar--small'), copy);
                const edition = entry.edicion ?? entry.raffle_id;
                const meta = el('span', 'hub-latest-row__meta');
                meta.append(el('strong', '', `#${formatCount(edition)}`));
                if (entry.resultado === 'gano') meta.append(el('span', 'hub-latest-row__tag is-won', 'Ganó'));
                if (entry.resultado === 'perdio') meta.append(el('span', 'hub-latest-row__tag is-lost', 'Perdió'));
                row.append(meta);
            } else {
                copy.append(el('strong', '', 'Aún sin registros'));
                row.append(copy);
            }
            list.append(row);
        });
        section.append(list);
        return section;
    }

    function makeStatsDetail(stats, viewerId) {
        const detail = el('div', 'hub-hall-detail');
        const winners = Array.isArray(stats.top_ganadores) ? stats.top_ganadores : [];
        const podium = el('div', 'hub-podium hub-reveal');
        podium.setAttribute('aria-label', 'Podio de ganadores');
        podium.append(podiumColumn(winners[1], 2), podiumColumn(winners[0], 1), podiumColumn(winners[2], 3));
        detail.append(podium);
        const board = el('ol', 'hub-leaderboard hub-reveal');
        board.setAttribute('aria-label', 'Top 10 de ganadores');
        winners.slice(0, 10).forEach((user, index) => {
            const rank = Number(user.rank) || index + 1;
            const own = Number(user.user_id) === Number(viewerId) && Number(viewerId) > 0;
            const row = el('li', `hub-lb-row${rank <= 3 ? ` hub-lb-row--top hub-lb-row--${['gold', 'silver', 'bronze'][rank - 1]}` : ''}${own ? ' is-you' : ''}`);
            row.style.setProperty('--row-i', String(index));
            const rankNode = el('span', 'hub-lb-row__rank');
            if (rank <= 3) rankNode.append(makeTrophy(['gold', 'silver', 'bronze'][rank - 1], 'hub-trophy-mini'));
            else rankNode.textContent = String(rank);
            const name = el('span', 'hub-lb-row__name', user.name || 'Banano');
            const verified = makeVerified(user);
            if (verified) name.append(verified);
            const wins = el('span', 'hub-lb-row__wins');
            wins.append(el('strong', '', formatCount(user.wins)), el('small', '', Number(user.wins) === 1 ? 'victoria' : 'victorias'));
            row.append(rankNode, makeAvatar(user, 'hub-avatar hub-avatar--small'), name, wins);
            board.append(row);
        });
        if (!winners.length) board.append(el('li', 'hub-empty-state hub-empty-state--small', 'Pronto habrá leyendas en el ranking.'));
        detail.append(board);
        const fullRanking = el('button', 'hub-button hub-button--ghost hub-button--block', 'Ver ranking completo');
        fullRanking.type = 'button';
        fullRanking.dataset.action = 'open-ranking';
        detail.append(fullRanking);
        return detail;
    }

    function renderHall(stats) {
        const payload = stats || {};
        $('#hall-full').replaceChildren(makeStatsDetail(payload, CONFIG.userId || 0));
        const streaks = Array.isArray(payload.top_rachas) ? payload.top_rachas : [];
        const worst = Array.isArray(payload.peor_suerte) ? payload.peor_suerte : [];
        const loyal = Array.isArray(payload.top_participantes) ? payload.top_participantes : [];
        $('#trophy-cards').replaceChildren(
            trophyCard('Racha imparable', 'fire', streaks[0], user => formatCount(user.best_streak), 'sorteos seguidos',
                'streak', streaks, user => `Récord ${formatCount(user.best_streak)} · actual ${formatCount(user.current_streak)}`, 'hub-trophy-card--fire'),
            trophyCard('Peor suerte', 'heart-broken', worst[0], user => formatCount(user.participations), 'sin ganar',
                'worst', worst, user => `${formatCount(user.participations)} participaciones`, 'hub-trophy-card--luck'),
            trophyCard('Siempre presente', 'users-round', loyal[0], user => formatCount(user.participations), 'sorteos jugados',
                'loyal', loyal, user => `${formatCount(user.participations)} sorteos`, 'hub-trophy-card--loyal'),
        );
        $('#choice-panel').replaceChildren(makeChoicePanel(payload));
        const generated = Date.parse(payload.generated_at || '');
        $('#stats-updated').textContent = Number.isFinite(generated)
            ? new Intl.DateTimeFormat('es-PE', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Lima' }).format(new Date(generated))
            : '';
        observeReveals(root);
    }

    function renderMeme(url) {
        const slot = $('#meme-slot');
        slot.replaceChildren();
        const imageUrl = safeUrl(url);
        if (!imageUrl) return;
        const section = el('section', 'hub-card hub-basement hub-reveal');
        const eyebrow = el('p', 'hub-eyebrow');
        eyebrow.append(icon('sparkle'), document.createTextNode('EL SÓTANO'));
        section.append(eyebrow, el('h2', '', 'La esquina secreta de la comunidad'));
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
        mountProposals(data);
        const spots = $('#proposal-spots');
        if (spots) spots.replaceChildren(icon('ticket'), document.createTextNode(`Quedan ${formatCount(data.cupos_restantes)} de ${formatCount(data.max_propuestas)} cupos para proponer`));
        const reward = $('#proposal-reward');
        if (reward) reward.replaceChildren(icon('coin'), document.createTextNode(`Gana ${formatCoins(data.recompensa_bananos)} Bananos por proponer`));
        renderSummary(data.resumen || (data.stats && data.stats.resumen));
        renderLastWinner(data.ultimo_sorteo, data.my && data.my.ultimo_sorteo);
        if (data.votacion_abierta === true) {
            renderCreatorCallout(data);
            renderProposals(data);
        }
        renderHall(data.stats || {});
        renderMeme(data.meme_url);
        observeReveals(root);
    }

    // Modales accesibles para el ranking, Top 10 y confirmaciones
    function openRanking() {
        const list = Array.isArray(state.vote && state.vote.stats && state.vote.stats.ranking_ganadores)
            ? state.vote.stats.ranking_ganadores : [];
        const content = $('#ranking-dialog-content');
        content.replaceChildren();
        const viewerId = Number(CONFIG.userId) || 0;
        const viewerIndex = list.findIndex(user => Number(user.user_id) === viewerId && viewerId > 0);
        const position = el('p', `hub-ranking__position${viewerIndex < 0 ? ' is-muted' : ''}`,
            viewerIndex >= 0 ? `Tu posición: #${Number(list[viewerIndex].rank) || viewerIndex + 1}` : 'Inicia sesión para ver tu posición en el ranking.');
        content.append(position);
        const search = el('input', 'hub-ranking__search');
        search.type = 'search';
        search.placeholder = 'Buscar por nombre…';
        search.setAttribute('aria-label', 'Buscar en el ranking de ganadores');
        search.dataset.action = 'search-ranking';
        content.append(search);
        const rows = el('div', 'hub-ranking-list hub-ranking-list--full');
        if (list.length) {
            list.forEach((user, index) => {
                const rank = Number(user.rank) || index + 1;
                rows.append(rankingRow(user, rank, `${formatCount(user.wins)} ${Number(user.wins) === 1 ? 'victoria' : 'victorias'}`, viewerId));
            });
        } else {
            rows.append(el('p', 'hub-empty-state', 'Todavía no hay ganadores registrados.'));
        }
        content.append(rows);
        $('#ranking-dialog').showModal();
        if (viewerIndex >= 0) {
            window.requestAnimationFrame(() => {
                const viewer = $$('.hub-ranking-row', rows).find(row => Number(row.dataset.userId) === viewerId);
                if (viewer) viewer.scrollIntoView({ block: 'center', behavior: reducedMotion ? 'auto' : 'smooth' });
            });
        }
    }

    function openCategory(category) {
        const card = $$('.hub-trophy-card', $('#trophy-cards')).find(node => node.dataset.category === category);
        if (!card) return;
        const title = { streak: 'Racha imparable · Top 10', worst: 'Peor suerte · Top 10', loyal: 'Siempre presente · Top 10' }[category] || 'Top 10';
        $('#category-title').textContent = title;
        const metric = card._hubMetric || (user => formatCount(user.participations));
        const entries = Array.isArray(card._hubEntries) ? card._hubEntries.slice(0, 10) : [];
        const content = $('#category-dialog-content');
        content.replaceChildren(rankingList(entries, metric, CONFIG.userId || 0));
        $('#category-dialog').showModal();
    }

    function renderLoadingError(message) {
        const list = $('#proposal-list');
        if (list) list.replaceChildren(el('div', 'hub-empty-state', message));
        const callout = $('#creator-callout');
        if (callout) renderCreatorCallout({ votacion_abierta: false, cupos_restantes: 0, my: {} });
    }

    function onClick(event) {
        const button = event.target.closest('[data-action]');
        if (!button || !root.contains(button) || button.disabled) return;
        const action = button.dataset.action;
        if (!['add-item', 'vote', 'remove-slot'].includes(action)) event.preventDefault();
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
            case 'add-item':
                if (state.selected.length >= 12) {
                    toast('Tu combo ya tiene 12 premios. ¡Una idea poderosa!', 'info');
                    break;
                }
                state.selected.push(String(button.dataset.offerId || ''));
                updateComboMeter();
                break;
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
            case 'open-ranking':
                openRanking();
                break;
            case 'close-ranking':
                $('#ranking-dialog').close();
                break;
            case 'open-history':
                openHistory();
                break;
            case 'close-history':
                $('#history-dialog').close();
                break;
            case 'retry-history':
                loadHistory();
                break;
            case 'pick-winner':
                showLastWinner(Number(button.dataset.winnerIndex) || 0);
                break;
            case 'open-winner':
                openWinnerDetails(Number(button.dataset.winnerIndex) || 0);
                break;
            case 'close-winner':
                $('#winner-dialog').close();
                break;
            case 'open-sponsor':
                $('#sponsor-dialog').showModal();
                break;
            case 'close-sponsor':
                $('#sponsor-dialog').close();
                break;
            case 'open-category':
                openCategory(button.dataset.category);
                break;
            case 'close-category':
                $('#category-dialog').close();
                break;
            case 'cancel-confirm':
                $('#confirm-dialog').close();
                break;
            case 'confirm-close':
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
        } else if (target.matches('[data-action="search-ranking"]')) {
            const query = normalizeText(target.value);
            $$('.hub-ranking-row', $('#ranking-dialog-content')).forEach(row => {
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
        if (event.target === $('#ranking-dialog')) $('#ranking-dialog').close();
        if (event.target === $('#category-dialog')) $('#category-dialog').close();
        if (event.target === $('#confirm-dialog')) $('#confirm-dialog').close();
        if (event.target === $('#history-dialog')) $('#history-dialog').close();
        if (event.target === $('#sponsor-dialog')) $('#sponsor-dialog').close();
        if (event.target === $('#winner-dialog')) $('#winner-dialog').close();
    }

    // Polling de sala y arranque
    function startStatusPolling() {
        window.setInterval(() => {
            if (document.visibilityState === 'visible') getRaffleStatus().catch(() => {});
        }, 30_000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') getRaffleStatus().catch(() => {});
        });
    }

    async function init() {
        setupRevealObserver();
        const sponsorLink = $('#sponsor-whatsapp-link');
        if (sponsorLink) sponsorLink.href = safeUrl(CONFIG.sponsorUrl, sponsorLink.href);
        root.addEventListener('click', onClick);
        root.addEventListener('input', onInput);
        $('#combo-dialog').addEventListener('cancel', onDialogCancel);
        [$('#history-dialog'), $('#sponsor-dialog')].forEach(dialog => dialog.addEventListener('cancel', onDialogCancel));
        [$('#combo-dialog'), $('#ranking-dialog'), $('#category-dialog'), $('#confirm-dialog'), $('#history-dialog'), $('#sponsor-dialog'), $('#winner-dialog')]
            .forEach(dialog => dialog.addEventListener('click', onDialogBackdrop));
        window.setInterval(updateCountdown, 1000);
        const results = await Promise.allSettled([loadVoteState(), getRaffleStatus()]);
        if (results[0].status === 'rejected') {
            renderLoadingError('No pudimos cargar las propuestas. Revisa tu conexión e inténtalo de nuevo.');
            toast(results[0].reason.message || 'No pudimos cargar el Hub.', 'error');
        }
        if (results[1].status === 'rejected') {
            $('#hub-hero').dataset.state = 'error';
            $('#hero-badge').textContent = 'Estado temporalmente indisponible';
            $('#hero-copy').textContent = 'No pudimos consultar el sorteo. Inténtalo nuevamente en un momento.';
        }
        startStatusPolling();
    }

    init();
})();

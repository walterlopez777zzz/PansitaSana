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
        chartsRevealed: false,
    };
    const countFormat = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 0 });
    const moneyFormat = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const coinFormat = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 2 });
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const VBUCK_ICON = 'https://fortnite-api.com/images/vbuck.png';
    const SVG_NS = 'http://www.w3.org/2000/svg';

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
        countdown.textContent = days > 0
            ? `${days} d ${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
            : `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
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
        animateCount($('#summary-raffles'), totals.sorteos_realizados || 0);
        const soles = Number(totals.valor_soles) || 0;
        const solesLine = $('#summary-soles');
        solesLine.hidden = soles <= 0;
        if (soles > 0) solesLine.textContent = `≈ S/ ${moneyFormat.format(soles)} en premios`;
        const fill = bananas > 0 ? Math.min(.72, .14 + Math.log10(bananas) / 9) : 0;
        $('#hub-vault').style.setProperty('--vault-fill', String(fill));
        requestAnimationFrame(() => $('#hub-vault').classList.add('is-loaded'));
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
        const results = $('#last-results-link');
        const raffleId = Number(data.raffle_id);
        results.href = raffleId > 0 ? `/juegos/sorteo_flash/?id=${encodeURIComponent(String(Math.floor(raffleId)))}` : '/juegos/sorteo_flash/';
        const winners = data.ganadores;
        const first = winners[0];
        const featured = el('article', 'hub-winner-card');
        const avatar = makeAvatar(first, 'hub-avatar hub-avatar--large');
        const main = el('div', 'hub-winner-card__main');
        const nameRow = el('div', 'hub-winner-card__name');
        nameRow.append(el('strong', '', first.name || 'Ganador de la comunidad'));
        const verified = makeVerified(first);
        if (verified) nameRow.append(verified);
        const prize = el('p', 'hub-winner-card__prize', first.prize_name || 'Premio del sorteo');
        main.append(nameRow, prize);
        if (first.choice === 'double_lost') {
            main.append(el('span', 'hub-delivery hub-delivery--pending', 'El doble no salió 🍀'));
        } else {
            main.append(el('span', `hub-delivery hub-delivery--${first.delivery_status === 'delivered' ? 'done' : 'pending'}`,
                first.delivery_status === 'delivered' ? 'Entregado ✅' : 'En camino 🚚'));
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
            imageBox.append(el('span', 'hub-shop-card__placeholder', '🍌'));
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
                badges.append(el('span', 'hub-chip hub-chip--green', 'Tu voto ✅'));
            }
            if (proposal.estado === 'ganadora') badges.append(el('span', 'hub-chip hub-chip--gold', 'Combo ganador 🏆'));
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

    function podiumPerson(user, rank, viewerId) {
        const place = el('div', `hub-podium-place hub-podium-place--${rank}`);
        const medal = el('span', 'hub-podium-place__medal');
        medal.append(icon('medal'));
        place.append(medal, makeAvatar(user || {}, 'hub-avatar'));
        if (user) {
            const name = el('span', 'hub-podium-place__name', user.name || 'Banano');
            const verified = makeVerified(user);
            if (verified) name.append(verified);
            place.append(name, el('span', 'hub-podium-place__wins', `${formatCount(user.wins)} ${Number(user.wins) === 1 ? 'victoria' : 'victorias'}`));
        } else {
            place.append(el('span', 'hub-podium-place__name', 'Por descubrir'), el('span', 'hub-podium-place__wins', 'El próximo puedes ser tú'));
        }
        place.append(el('span', 'hub-podium-place__rank', `#${rank}`));
        if (user && Number(user.user_id) === Number(viewerId) && Number(viewerId) > 0) place.classList.add('is-you');
        return place;
    }

    function openCategoryModal(title, eyebrow, entries, metric) {
        $('#category-title').textContent = title;
        $('#category-eyebrow').textContent = eyebrow;
        const content = $('#category-dialog-content');
        content.replaceChildren(rankingList(entries, metric, CONFIG.userId || 0));
        $('#category-dialog').showModal();
    }

    function trophyCard(title, iconName, user, detail, category, entries, metric, modifier = '') {
        const card = el('article', `hub-trophy-card${modifier ? ` ${modifier}` : ''}`);
        card.dataset.category = category;
        const heading = el('h3', 'hub-trophy-card__heading');
        heading.append(icon(iconName), document.createTextNode(title));
        card.append(heading);
        if (user) {
            const highlight = el('p', 'hub-trophy-highlight');
            highlight.append(makeAvatar(user, 'hub-avatar'), el('span', '', user.name || 'Banano'));
            card.append(highlight, el('p', 'hub-results-note', detail(user)));
            const more = el('button', 'hub-text-button hub-category-button', 'Ver Top 10');
            more.type = 'button';
            more.dataset.action = 'open-category';
            more.dataset.category = category;
            card.append(more);
        } else {
            card.append(el('p', 'hub-empty-state hub-empty-state--small', 'La próxima historia de suerte está por escribirse.'));
        }
        card.dataset.entries = String(Array.isArray(entries) ? entries.length : 0);
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
        const panel = el('section', 'hub-choice-panel');
        const heading = el('h3');
        heading.append(icon('dice'), document.createTextNode('Termómetro del Doble o Nada'));
        panel.append(heading);
        const body = el('div', 'hub-choice-panel__body');
        const donutWrap = el('div', 'hub-donut-wrap');
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'hub-donut hub-chart');
        svg.setAttribute('viewBox', '0 0 120 120');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'Distribución de elecciones entre premio original, cashback y doble o nada');
        const track = document.createElementNS(SVG_NS, 'circle');
        track.setAttribute('class', 'hub-donut__track');
        track.setAttribute('cx', '60'); track.setAttribute('cy', '60'); track.setAttribute('r', '45');
        track.setAttribute('pathLength', '100');
        svg.append(track);
        const parts = [
            ['original', 'Original', 'original'],
            ['cashback', 'Cashback', 'cashback'],
            ['doble', 'Doble o Nada', 'double'],
        ];
        let offset = 0;
        parts.forEach(([key, label, color]) => {
            const pct = choicePercent(choices, key);
            const circle = document.createElementNS(SVG_NS, 'circle');
            circle.setAttribute('class', `hub-donut__segment hub-donut__segment--${color}`);
            circle.setAttribute('cx', '60'); circle.setAttribute('cy', '60'); circle.setAttribute('r', '45');
            circle.setAttribute('pathLength', '100');
            circle.style.setProperty('--donut-pct', String(pct));
            circle.style.setProperty('--donut-offset', String(-offset));
            circle.setAttribute('aria-label', `${label}: ${pct.toFixed(1)} por ciento`);
            svg.append(circle);
            offset += pct;
        });
        const centerText = document.createElementNS(SVG_NS, 'text');
        centerText.setAttribute('class', 'hub-donut__center');
        centerText.setAttribute('x', '60'); centerText.setAttribute('y', '63');
        centerText.textContent = 'Elecciones';
        svg.append(centerText);
        donutWrap.append(svg);
        const legend = el('div', 'hub-choice-legend');
        parts.forEach(([key, label, color]) => {
            const item = el('div', 'hub-choice-legend__item');
            item.append(el('span', `hub-choice-legend__swatch hub-choice-legend__swatch--${color}`));
            item.append(el('span', '', label));
            const pct = choicePercent(choices, key);
            const value = el('strong', 'hub-choice-legend__pct', '0%');
            value.dataset.countUp = String(pct);
            value.dataset.decimals = '1';
            value.dataset.suffix = '%';
            item.append(value);
            legend.append(item);
        });
        donutWrap.append(legend);
        body.append(donutWrap);
        const rate = Math.max(0, Math.min(100, Number(choices.doble_win_rate) || 0));
        const rateBox = el('div', 'hub-double-rate hub-chart');
        const rateHeading = el('div', 'hub-double-rate__heading');
        rateHeading.append(el('span', '', 'Victorias al elegir Doble o Nada'));
        const rateValue = el('strong', '', '0%');
        rateValue.dataset.countUp = String(rate);
        rateValue.dataset.decimals = '1';
        rateValue.dataset.suffix = '%';
        rateHeading.append(rateValue);
        const trackBar = el('div', 'hub-double-rate__track');
        const fill = el('span', 'hub-double-rate__fill');
        fill.style.setProperty('--rate', `${rate}%`);
        trackBar.append(fill);
        rateBox.append(rateHeading, trackBar, el('p', 'hub-results-note', `${formatCount(choices.doble_ganados)} ganados · ${formatCount(choices.doble_perdidos)} perdidos`));
        body.append(rateBox);
        panel.append(body);
        return panel;
    }

    function makeLatestChoices(stats) {
        const latest = stats.ultimas_elecciones || {};
        const section = el('section', 'hub-latest-choices');
        const heading = el('h3');
        heading.append(icon('clock'), document.createTextNode('Últimas elecciones'));
        section.append(heading);
        const grid = el('div', 'hub-latest-choices__grid');
        [
            ['Original', latest.original, 'gift'],
            ['Cashback', latest.cashback, 'coin'],
            ['Doble o Nada', latest.doble, latest.doble && latest.doble.resultado === 'perdio' ? 'heart-broken' : 'dice'],
        ].forEach(([label, entry, iconName]) => {
            const card = el('article', 'hub-latest-card');
            const badge = el('span', 'hub-latest-card__icon');
            badge.append(icon(iconName));
            card.append(badge);
            const copy = el('span', 'hub-latest-card__copy');
            if (entry) {
                const nameLine = el('span', 'hub-user-name');
                nameLine.append(makeAvatar(entry, 'hub-avatar hub-avatar--small'), document.createTextNode(entry.name || 'Ganador'));
                const verified = makeVerified(entry);
                if (verified) nameLine.append(verified);
                copy.append(el('strong', '', label), nameLine);
                const result = entry.resultado === 'perdio'
                    ? 'El doble se perdió'
                    : (entry.resultado === 'gano' ? '¡Ganó el doble!' : `Sorteo #${formatCount(entry.raffle_id)}`);
                copy.append(el('small', '', result));
            } else {
                copy.append(el('strong', '', label), el('small', '', 'Aún sin registros'));
            }
            card.append(copy);
            grid.append(card);
        });
        section.append(grid);
        return section;
    }

    function makeStatsDetail(stats, viewerId) {
        const detail = el('div', 'hub-hall-detail');
        const winners = Array.isArray(stats.top_ganadores) ? stats.top_ganadores : [];
        const podium = el('section', 'hub-podium-card');
        const heading = el('h3');
        heading.append(icon('trophy'), document.createTextNode('Podio de ganadores'));
        podium.append(heading);
        const podiumRows = el('div', 'hub-podium');
        podiumRows.append(podiumPerson(winners[1], 2, viewerId), podiumPerson(winners[0], 1, viewerId), podiumPerson(winners[2], 3, viewerId));
        podium.append(podiumRows);
        const places = el('section', 'hub-ranking-section');
        places.append(el('h4', '', 'Lugares 4 al 10'));
        places.append(rankingList(winners.slice(3, 10), user => `${formatCount(user.wins)} ${Number(user.wins) === 1 ? 'victoria' : 'victorias'}`, viewerId, 4));
        podium.append(places);
        const fullRanking = el('button', 'hub-button hub-button--soft', 'Ver ranking completo');
        fullRanking.type = 'button';
        fullRanking.dataset.action = 'open-ranking';
        const more = el('div', 'hub-podium__more');
        more.append(fullRanking);
        podium.append(more);
        detail.append(podium);

        const streaks = Array.isArray(stats.top_rachas) ? stats.top_rachas : [];
        const streakMetric = user => `Récord ${formatCount(user.best_streak)} · actual ${formatCount(user.current_streak)}`;
        const streakCard = trophyCard('Racha Imparable', 'fire', streaks[0], user => `Mejor racha: ${formatCount(user.best_streak)} · racha actual: ${formatCount(user.current_streak)}`, 'streak', streaks, streakMetric);
        const worst = Array.isArray(stats.peor_suerte) ? stats.peor_suerte : [];
        const worstMetric = user => `${formatCount(user.participations)} sorteos`;
        const worstCard = trophyCard('Peor Suerte', 'heart-broken', worst[0], user => `${formatCount(user.participations)} participaciones sin victoria. ¡La próxima puede ser la buena!`, 'worst', worst, worstMetric, 'hub-trophy-card--luck');
        const trophyGrid = el('div', 'hub-trophy-grid');
        trophyGrid.append(streakCard, worstCard, makeChoicePanel(stats));
        detail.append(trophyGrid, makeLatestChoices(stats));
        return detail;
    }

    function observeCharts(scope) {
        const charts = $$('.hub-chart', scope);
        const reveal = chart => {
            chart.classList.add('is-visible');
            $$('[data-count-up]', chart).forEach(node => {
                const value = Number(node.dataset.countUp) || 0;
                const decimals = Number(node.dataset.decimals) || 0;
                const formatter = decimals
                    ? new Intl.NumberFormat('es-PE', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
                    : countFormat;
                if (state.chartsRevealed) {
                    node.textContent = `${formatter.format(value)}${node.dataset.suffix || ''}`;
                    node.dataset.counted = 'true';
                } else {
                    animateCount(node, value, decimals, node.dataset.suffix || '');
                }
            });
        };
        if (state.chartsRevealed) {
            charts.forEach(reveal);
            return;
        }
        if (!('IntersectionObserver' in window)) {
            charts.forEach(reveal);
            state.chartsRevealed = true;
            return;
        }
        const observer = new IntersectionObserver(entries => {
            if (!entries.some(entry => entry.isIntersecting)) return;
            charts.forEach(reveal);
            state.chartsRevealed = true;
            observer.disconnect();
        }, { threshold: 0.25 });
        charts.forEach(chart => observer.observe(chart));
    }

    function renderHall(stats) {
        const payload = stats || {};
        const target = $('#hall-full');
        target.replaceChildren(makeStatsDetail(payload, CONFIG.userId || 0));
        observeCharts(target);
        const generated = Date.parse(payload.generated_at || '');
        $('#stats-updated').textContent = Number.isFinite(generated)
            ? `Se actualiza cada 5 minutos · actualizado ${new Intl.DateTimeFormat('es-PE', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(generated))}`
            : 'Se actualiza cada 5 minutos';
    }

    function renderMeme(url) {
        const slot = $('#meme-slot');
        slot.replaceChildren();
        const imageUrl = safeUrl(url);
        if (!imageUrl) return;
        const section = el('section', 'hub-card hub-basement');
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
        const spots = $('#proposal-spots');
        spots.replaceChildren(icon('ticket'), document.createTextNode(`Quedan ${formatCount(data.cupos_restantes)} de ${formatCount(data.max_propuestas)} cupos para proponer`));
        const reward = $('#proposal-reward');
        reward.replaceChildren(icon('coin'), document.createTextNode(`Gana ${formatCoins(data.recompensa_bananos)} Bananos por proponer`));
        renderSummary(data.resumen || (data.stats && data.stats.resumen));
        renderLastWinner(data.ultimo_sorteo, data.my && data.my.ultimo_sorteo);
        renderCreatorCallout(data);
        renderProposals(data);
        renderHall(data.stats || {});
        renderMeme(data.meme_url);
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
        const card = $$('.hub-trophy-card', $('#hall-full')).find(node => node.dataset.category === category);
        if (!card) return;
        const title = category === 'streak' ? 'Racha Imparable · Top 10' : 'Peor Suerte · Top 10';
        $('#category-title').textContent = title;
        $('#category-eyebrow').textContent = category === 'streak' ? 'RACHAS DE LA COMUNIDAD' : 'MÁS PARTICIPACIONES SIN VICTORIA';
        const metric = card._hubMetric || (user => formatCount(user.participations));
        const entries = Array.isArray(card._hubEntries) ? card._hubEntries.slice(0, 10) : [];
        const content = $('#category-dialog-content');
        content.replaceChildren(rankingList(entries, metric, CONFIG.userId || 0));
        $('#category-dialog').showModal();
    }

    function renderLoadingError(message) {
        $('#proposal-list').replaceChildren(el('div', 'hub-empty-state', message));
        renderCreatorCallout({ votacion_abierta: false, cupos_restantes: 0, my: {} });
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
        root.addEventListener('click', onClick);
        root.addEventListener('input', onInput);
        $('#combo-dialog').addEventListener('cancel', onDialogCancel);
        [$('#combo-dialog'), $('#ranking-dialog'), $('#category-dialog'), $('#confirm-dialog')]
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

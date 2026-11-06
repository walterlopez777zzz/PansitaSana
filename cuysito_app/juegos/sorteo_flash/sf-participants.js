(function () {
    'use strict';

    const CHOICE_LABELS = {
        original: 'Premio Original',
        cashback: 'Cashback',
        gamble: 'Doble o nada',
        none: 'Sin elegir'
    };
    const FIRE_TONGUES = (() => {
        let markup = '';
        const count = 18;
        for (let index = 0; index < count; index++) {
            const angle = index * 360 / count + (index % 2 ? 6 : 0);
            const length = index % 3 === 0 ? 15 : (index % 3 === 1 ? 10 : 12.5);
            const width = index % 2 ? 5 : 6.5;
            markup += '<g transform="rotate(' + angle + ' 50 50)">'
                + '<path class="sf-choice-ring__tongue" style="animation-delay:-' + (index * 0.13).toFixed(2)
                + 's;animation-duration:' + (0.7 + (index % 4) * 0.12).toFixed(2)
                + 's" d="M' + (50 - width) + ' 26 Q50 ' + (26 - length) + ' ' + (50 + width)
                + ' 26 Q50 30 ' + (50 - width) + ' 26Z" fill="url(#sf-fire-tongue)"/>'
                + '<path class="sf-choice-ring__tongue" style="animation-delay:-' + (index * 0.21).toFixed(2)
                + 's;animation-duration:' + (0.55 + (index % 3) * 0.1).toFixed(2)
                + 's" d="M' + (50 - width * 0.5) + ' 27 Q50 ' + (27 - length * 0.55) + ' '
                + (50 + width * 0.5) + ' 27 Q50 30 ' + (50 - width * 0.5)
                + ' 27Z" fill="url(#sf-fire-tongue-core)"/>'
                + '</g>';
        }
        return markup;
    })();

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function icon(id, className) {
        return '<svg class="' + (className || '') + '" aria-hidden="true" focusable="false"><use href="#' + id + '"></use></svg>';
    }

    function imageUrl(value) {
        const src = String(value || '').trim();
        return /^(https?:\/\/|\/|\.{1,2}\/)/i.test(src) ? src : '';
    }

    function ringHtml(choice, avatarHtml, opts) {
        const normalized = ['original', 'cashback', 'gamble'].includes(choice) ? choice : 'none';
        const avatar = String(avatarHtml || '');
        const options = opts || {};
        const sparks = normalized === 'gamble'
            ? '<span class="sf-choice-ring__sparks" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>'
            : '';
        const fire = normalized === 'gamble'
            ? '<span class="sf-choice-ring__fire-css" aria-hidden="true"></span>'
                + '<svg class="sf-choice-ring__fire-svg" viewBox="0 0 100 100" aria-hidden="true" focusable="false">'
                + '<g class="sf-choice-ring__fire-group" filter="url(#sf-fire-filter)"><circle cx="50" cy="50" r="50" fill="url(#sf-fire-radial)"/>'
                + FIRE_TONGUES + '</g></svg>'
            : '';
        return '<span class="sf-choice-ring sf-choice-ring--' + normalized + (options.className ? ' ' + esc(options.className) : '') + '" data-choice="' + normalized + '">'
            + fire + (normalized === 'gamble' ? '<span class="sf-choice-ring__core" aria-hidden="true"></span>' : '')
            + '<span class="sf-choice-ring__gap">' + avatar + '</span>' + sparks + '</span>';
    }

    function statsHtml(statsInput) {
        const stats = statsInput || {};
        const total = Math.max(0, Number(stats.total) || 0);
        const rows = [
            ['original', 'Original', '#34d399'],
            ['cashback', 'Cashback', '#f59e0b'],
            ['gamble', 'Doble o nada', '#f97316']
        ];
        const visible = rows.filter(([key]) => key === 'original'
            || (key !== 'cashback' || stats.allow_cashback !== false)
            && (key !== 'gamble' || stats.allow_double_or_nothing !== false));
        const percent = (count) => total > 0 ? Math.round(count * 1000 / total) / 10 : 0;
        const segment = (key) => {
            const count = Math.max(0, Number(stats[key]) || 0);
            return '<span class="sf-choice-bar__segment sf-choice-bar__segment--' + key + '" style="width:' + percent(count) + '%"></span>';
        };
        const legend = visible.map(([key, label, color]) => {
            const count = Math.max(0, Number(stats[key]) || 0);
            return '<span class="sf-choice-legend__item"><i style="--choice-dot:' + color + '"></i><span class="sf-choice-legend__label">'
                + label + '</span><span class="sf-choice-legend__count">' + count + ' · ' + percent(count) + '%</span></span>';
        }).join('');
        const none = Math.max(0, Number(stats.none) || 0);
        return '<section class="sf-choice-distribution" aria-label="Distribución de pre-elecciones">'
            + '<div class="sf-choice-bar" role="img" aria-label="Distribución de elecciones">'
            + segment('original') + (stats.allow_cashback === false ? '' : segment('cashback'))
            + (stats.allow_double_or_nothing === false ? '' : segment('gamble')) + '</div>'
            + '<div class="sf-choice-legend">' + legend + '</div>'
            + (none > 0 ? '<p class="sf-choice-distribution__none">' + none + ' sin elegir</p>' : '')
            + '</section>';
    }

    function resolveReward(participantInput, catalogInput) {
        const participant = participantInput || {};
        const catalog = catalogInput || null;
        const positions = catalog && Array.isArray(catalog.positions) ? catalog.positions : [];
        const position = positions.find((entry) => Number(entry.position) === 1) || positions[0] || null;
        const options = position && position.options && typeof position.options === 'object' ? position.options : null;
        const pool = participant.pool && typeof participant.pool === 'object' ? participant.pool : {};
        const selectedKey = String(pool[String(position && position.position || 1)] || '');
        const selectedOption = options && selectedKey ? options[selectedKey] || null : null;
        const optionList = options ? Object.keys(options).map((key) => options[key]).filter(Boolean) : [];
        const firstOption = optionList[0] || null;
        const maxOption = optionList.reduce((best, item) => !best || Number(item.value_coins) > Number(best.value_coins) ? item : best, null);
        const fixed = position && position.fixed ? position.fixed : null;
        let item = fixed || selectedOption || maxOption;
        let valueCoins = item && Number.isFinite(Number(item.value_coins)) ? Number(item.value_coins) : null;

        if (fixed && selectedOption && options) {
            const poolMax = optionList.reduce((max, option) => Math.max(max, Number(option.value_coins) || 0), 0);
            valueCoins = Math.max(0, Number(fixed.value_coins) - poolMax + Number(selectedOption.value_coins || 0));
            item = Object.assign({}, fixed, { value_coins: valueCoins, selected_option: selectedOption });
        }

        const choice = ['original', 'cashback', 'gamble'].includes(participant.choice) ? participant.choice : null;
        const pendingPool = !!(options && !selectedOption);
        const percent = Math.max(0, Number(catalog && catalog.cashback_percent) || 0);
        const cashback = valueCoins === null ? null : Math.round(valueCoins * percent * 100) / 100;
        const gambleBonus = valueCoins === null ? null : Math.round(valueCoins);
        return {
            choice,
            position,
            item,
            preview: pendingPool ? firstOption : null,
            pendingPool,
            valueCoins,
            cashbackPercent: percent,
            cashbackCoins: cashback,
            gambleBonusCoins: gambleBonus,
            catalogAvailable: !!catalog
        };
    }

    function formatNumber(value, decimals) {
        if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
        return Number(value).toLocaleString('es-ES', {
            useGrouping: 'always',
            minimumFractionDigits: decimals || 0,
            maximumFractionDigits: decimals === undefined ? 2 : decimals
        });
    }

    function itemValue(item) {
        if (!item) return '—';
        if (Number(item.vbucks) > 0) return formatNumber(item.vbucks, 0) + ' PaVos';
        if (item.value_coins === null || item.value_coins === undefined) return '—';
        return formatNumber(item.value_coins) + ' Bananos';
    }

    function prizeCard(item, muted, bonusName) {
        const image = imageUrl(item && item.image);
        const name = item && item.name ? item.name : '—';
        const previewTag = bonusName ? '<span class="sf-inspect-prize__detail">' + esc(bonusName) + '</span>' : '';
        return '<article class="sf-inspect-prize' + (muted ? ' sf-inspect-prize--muted' : '') + '">'
            + '<span class="sf-inspect-prize__image">' + (image ? '<img src="' + esc(image) + '" alt="" loading="lazy">' : icon('sf-icon-gift')) + '</span>'
            + '<span class="sf-inspect-prize__body"><strong>' + esc(name) + '</strong>' + previewTag
            + '<span class="sf-inspect-prize__value">' + esc(itemValue(item)) + '</span></span></article>';
    }

    function notice(text, tone, iconId) {
        return '<div class="sf-notice"' + (tone ? ' data-tone="' + tone + '"' : '') + '>'
            + icon(iconId || 'sf-icon-ticket', 'sf-notice__icon') + '<p class="sf-notice__text">' + text + '</p></div>';
    }

    function tile(value, label, extraClass, coin) {
        return '<div class="sf-tile"><span class="sf-tile__value ' + (extraClass || '') + '">'
            + (coin ? '<img class="sf-inspect-coin" src="/assets/img/moneda.webp" alt="" aria-hidden="true">' : '')
            + esc(value) + '</span><span class="sf-tile__label">' + label + '</span></div>';
    }

    function summaryRow(label, value) {
        return '<div class="sf-summary__row"><dt>' + label + '</dt><dd>' + value + '</dd></div>';
    }

    function openInspect(participantInput, catalog, ctx) {
        const participant = participantInput || {};
        const context = ctx || {};
        const dialog = document.getElementById('sf-participant-dialog');
        if (!dialog || !window.SfDialog) return Promise.resolve(null);
        const username = participant.username || participant.public_display_name || 'Participante';
        const userId = Number(participant.user_id) || 0;
        const isMe = Number(context.currentUserId) > 0 && userId === Number(context.currentUserId);
        const choice = ['original', 'cashback', 'gamble'].includes(participant.choice) ? participant.choice : null;
        const reward = resolveReward(participant, catalog);
        const title = dialog.querySelector('[data-slot="title"]');
        const desc = dialog.querySelector('[data-slot="desc"]');
        const avatarSlot = dialog.querySelector('[data-slot="avatar"]');
        const view = dialog.querySelector('[data-slot="view"]');
        if (title) title.textContent = username + (isMe ? ' (Tú)' : '');
        if (desc) desc.textContent = 'Ticket #' + (Number(participant.ticket) || '—');
        if (avatarSlot) {
            const file = String(participant.avatar || '');
            const src = file ? imageUrl(file.startsWith('http') || file.startsWith('/') ? file : (context.avatarBasePath || '../../assets/avatars/') + file) : '';
            const initial = esc(username.charAt(0).toUpperCase());
            const avatar = '<div class="participant-avatar">' + (src ? '<img src="' + esc(src) + '" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover">' : initial) + '</div>';
            clearRings(avatarSlot);
            avatarSlot.innerHTML = ringHtml(choice, avatar, { className: 'sf-choice-ring--inspect' });
        }
        if (!view) return Promise.resolve(null);

        const mode = choice || 'none';
        const titleByMode = choice === 'cashback'
            ? 'Cashback ' + (catalog ? (Number(catalog.cashback_percent) || 0) + '%' : '—')
            : CHOICE_LABELS[mode];
        const badge = choice === 'gamble'
            ? '<span class="sf-badge" data-tone="danger">Alto riesgo</span>'
            : (choice === 'cashback' ? '<span class="sf-badge" data-tone="accent">Flexible</span>' : '<span class="sf-badge">Sin riesgo</span>');
        const thumb = choice === 'cashback'
            ? '<span class="sf-option__thumb sf-option__thumb--asset"><img src="/assets/img/moneda.webp" alt="" aria-hidden="true"></span>'
            : '<span class="sf-option__thumb' + (choice === 'gamble' ? ' sf-option__thumb--dice' : '') + '">' + icon(choice === 'gamble' ? 'sf-icon-dice' : 'sf-icon-gift') + '</span>';
        const riskAccent = choice === 'gamble'
            ? '<span class="sf-inspect-risk-flame" aria-hidden="true">' + icon('sf-icon-flame') + '</span>'
            : '';
        let body = choice ? '<div class="sf-option' + (choice === 'gamble' ? ' sf-option--risk' : '') + '">'
            + thumb + '<span class="sf-option__body"><strong class="sf-option__title">' + esc(titleByMode) + '</strong>'
            + '<span class="sf-option__meta">' + badge + riskAccent + '</span></span></div>' : '';

        if (choice === 'cashback') {
            body += '<div class="sf-tiles">' + tile(reward.cashbackCoins === null ? '—' : formatNumber(reward.cashbackCoins), 'Bananos si gana', 'sf-tile__value--cashback', true)
                + tile(catalog ? formatNumber(reward.cashbackPercent, 0) + '%' : '—', 'Del valor del premio', 'sf-tile__value--accent', false) + '</div>';
        } else if (choice === 'gamble') {
            const gambleItem = reward.item || { name: '—', image: '', value_coins: null, vbucks: null };
            body += prizeCard(gambleItem, false, reward.item && reward.item.selected_option ? 'Ítem elegido: ' + reward.item.selected_option.name : '')
                + '<div class="sf-tiles">' + tile('50/50', 'Probabilidad', 'sf-tile__value--accent', false)
                + tile(reward.gambleBonusCoins === null ? '+—' : '+' + formatNumber(reward.gambleBonusCoins, 0), 'Bananos extra en juego', 'sf-tile__value--danger', true) + '</div>'
                + notice('Si pierde la jugada, se queda sin premio.', 'warning', 'sf-icon-warning');
        } else if (choice === 'original') {
            if (reward.pendingPool) {
                body += notice('Elegirá su ítem en vivo si gana.', '', 'sf-icon-ticket');
                if (reward.position && reward.position.fixed) body += prizeCard(reward.item, false);
                body += prizeCard(reward.preview, true, 'Vista previa');
            } else {
                body += prizeCard(reward.item || { name: '—', image: '', value_coins: null, vbucks: null }, false,
                    reward.item && reward.item.selected_option ? 'Ítem elegido: ' + reward.item.selected_option.name : '');
            }
        } else {
            body += notice('Aún no eligió. Si gana, decide en vivo.', '', 'sf-icon-ticket');
        }

        const modality = choice ? (choice === 'cashback' ? 'Cashback' : CHOICE_LABELS[choice]) : 'Sin elegir';
        body += '<dl class="sf-summary">' + summaryRow('Ticket', '#' + (Number(participant.ticket) || '—'))
            + summaryRow('Modalidad', esc(modality));
        if (catalog && Array.isArray(catalog.positions) && catalog.positions.length > 1) {
            body += summaryRow('Puesto de referencia', '1°');
        }
        body += '</dl>';
        view.innerHTML = body;
        observeRings(avatarSlot);
        return window.SfDialog.open(dialog);
    }

    const ringObservers = new WeakMap();
    function observeRings(root) {
        if (!root) return;
        const rings = root.matches && root.matches('.sf-choice-ring--gamble')
            ? [root]
            : Array.from(root.querySelectorAll('.sf-choice-ring--gamble'));
        if (!rings.length) return;
        if (!('IntersectionObserver' in window)) {
            rings.forEach((ring) => ring.classList.add('is-visible'));
            return;
        }
        const observerRoot = root.closest('.participants-list, .participants-modal-content') || null;
        const key = observerRoot || document.body;
        let observer = ringObservers.get(key);
        if (!observer) {
            observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    entry.target.classList.toggle('is-visible', entry.isIntersecting);
                });
            }, { root: observerRoot, threshold: 0.01 });
            ringObservers.set(key, observer);
        }
        rings.forEach((ring) => observer.observe(ring));
    }

    function clearRings(root) {
        if (!root) return;
        const key = root.closest && root.closest('.participants-list, .participants-modal-content') || document.body;
        const observer = ringObservers.get(key);
        if (!observer) return;
        const rings = root.matches && root.matches('.sf-choice-ring--gamble')
            ? [root]
            : Array.from(root.querySelectorAll('.sf-choice-ring--gamble'));
        rings.forEach((ring) => observer.unobserve(ring));
    }

    window.SfParticipants = { ringHtml, statsHtml, resolveReward, openInspect, observeRings, clearRings };
})();

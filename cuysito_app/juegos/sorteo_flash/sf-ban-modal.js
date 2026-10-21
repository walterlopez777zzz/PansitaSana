(function () {
    'use strict';

    let countdownTimer = null;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[ch]);
    }

    function render(ban) {
        const dialog = document.getElementById('sf-ban-dialog');
        if (!dialog) return;
        ban = ban && typeof ban === 'object' ? ban : {};
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }

        const type = ['until_date', 'next_n_raffles'].includes(ban.type) ? ban.type : 'permanent';
        const title = type === 'until_date' ? 'Baneo por días'
            : type === 'next_n_raffles' ? 'Baneo por sorteos' : 'Baneo permanente';
        const view = dialog.querySelector('[data-slot="view"]');
        const heading = dialog.querySelector('[data-slot="title"]');
        const summary = dialog.querySelector('[data-slot="desc"]');
        if (heading) heading.textContent = 'Baneo de Sorteo Flash';
        if (summary) summary.textContent = 'Tu cuenta tiene un baneo activo.';

        const formatDate = value => {
            if (!value) return '—';
            const date = new Date(value);
            return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('es-PE', {
                day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
            });
        };
        const tile = (value, label, extra) => '<div class="sf-tile">'
            + '<strong class="sf-tile__value"' + (extra ? ' ' + extra : '') + '>' + value + '</strong>'
            + '<span class="sf-tile__label">' + label + '</span></div>';
        const rows = [
            ['Motivo', escapeHtml(ban.reason || 'Incumplir los requisitos del sorteo')],
            ['Expulsiones', 'Tuviste ' + Math.max(0, Number(ban.strikes) || 0) + ' expulsiones'],
            ['Fecha del baneo', escapeHtml(formatDate(ban.created_at))]
        ];
        const matched = {
            ip: 'Tu red (IP)',
            fingerprint: 'Este dispositivo',
            device: 'Este dispositivo'
        }[ban.matched_by];
        if (matched) rows.push(['Vinculado por', matched]);

        let html = '<div class="sf-options"><div class="sf-option">'
            + '<span class="sf-option__thumb sf-option__thumb--asset"><svg aria-hidden="true"><use href="#sf-icon-ban"></use></svg></span>'
            + '<span class="sf-option__body"><span class="sf-option__title">' + title + '</span>'
            + '<span class="sf-option__text">No se te permite el ingreso al sorteo por no cumplir los requisitos.</span>'
            + '<span class="sf-option__meta"><span class="sf-badge" data-tone="danger">'
            + (type === 'until_date' ? 'Por días' : type === 'next_n_raffles' ? 'Por sorteos' : 'Permanente')
            + '</span></span></span></div></div>';

        if (type === 'until_date') {
            const days = Math.max(0, Number(ban.days_total) || 0);
            const remaining = Math.max(0, Number(ban.remaining_seconds) || 0);
            html += '<div class="sf-tiles">'
                + tile(days + (days === 1 ? ' día' : ' días'), 'Duración del baneo')
                + tile('—', 'Tiempo restante', 'data-countdown')
                + '</div>';
            const startedAt = Date.now();
            const updateCountdown = () => {
                const el = view && view.querySelector('[data-countdown]');
                if (!el) return;
                const seconds = Math.max(0, remaining - Math.floor((Date.now() - startedAt) / 1000));
                if (seconds <= 0) {
                    el.textContent = 'Finalizando…';
                    return;
                }
                const d = Math.floor(seconds / 86400);
                const h = Math.floor((seconds % 86400) / 3600);
                const m = Math.floor((seconds % 3600) / 60);
                const s = seconds % 60;
                el.textContent = d + 'd ' + String(h).padStart(2, '0') + 'h '
                    + String(m).padStart(2, '0') + 'm ' + String(s).padStart(2, '0') + 's';
            };
            if (view) view.innerHTML = html;
            updateCountdown();
            countdownTimer = setInterval(updateCountdown, 1000);
        } else {
            if (type === 'next_n_raffles') {
                const total = Math.max(0, Number(ban.raffles_total ?? ban.raffles_remaining) || 0);
                const remaining = Math.max(0, Number(ban.raffles_remaining) || 0);
                html += '<div class="sf-tiles">' + tile(total, 'Sorteos de sanción')
                    + tile(remaining, 'Te faltan por cumplir') + '</div>';
            }
            if (view) view.innerHTML = html;
        }

        html = '<dl class="sf-summary">' + rows.map(([term, detail]) => (
            '<div class="sf-summary__row"><dt>' + term + '</dt><dd>' + detail + '</dd></div>'
        )).join('') + '</dl>'
            + '<div class="sf-notice" data-tone="warning"><svg class="sf-notice__icon" aria-hidden="true"><use href="#sf-icon-warning"></use></svg>'
            + '<p class="sf-notice__text">Si crees que es un error, escríbenos por WhatsApp con tu código de usuario.</p></div>';
        if (view) view.insertAdjacentHTML('beforeend', html);
        if (!dialog.open) window.SfDialog.open(dialog, { dismissible: false });
    }

    window.SfBanModal = Object.freeze({ render });
})();

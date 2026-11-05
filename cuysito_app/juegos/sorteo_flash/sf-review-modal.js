(function () {
    'use strict';

    const UNKNOWN = '—';

    function numeric(value) {
        if (value === null || value === undefined || value === '') return null;
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function countInfo(value) {
        const parsed = numeric(value);
        if (parsed === null) return { number: null, value: UNKNOWN, noun: 'compras' };
        const count = Math.max(0, Math.trunc(parsed));
        return {
            number: count,
            value: count.toLocaleString('es-PE'),
            noun: count === 1 ? 'compra' : 'compras'
        };
    }

    function amount(value) {
        const parsed = numeric(value);
        return parsed === null
            ? UNKNOWN
            : parsed.toLocaleString('es-PE', { maximumFractionDigits: 2 });
    }

    function formatDate(value) {
        if (value === null || value === undefined || value === '') return 'Todas tus compras';
        const parts = String(value).trim().slice(0, 10).split('-');
        if (parts.length !== 3 || !parts.every(part => /^\d+$/.test(part))) return UNKNOWN;
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[char]);
    }

    function render(requirement, minDate) {
        const data = requirement && typeof requirement === 'object' ? requirement : {};
        const kind = data.kind === 'spent' ? 'spent' : 'orders';
        const date = formatDate(data.min_date || minDate);
        const requiredOrders = countInfo(data.required_orders);
        const detectedOrders = countInfo(data.detected_orders);
        const requiredSpent = amount(data.required_spent);
        const detectedSpent = amount(data.detected_spent);
        const isOrders = kind === 'orders';

        let requirementText;
        let requirementValue;
        let detectedValue;
        let detectedText;
        let missingText;

        if (isOrders) {
            const completion = requiredOrders.number === 1 ? 'completada' : 'completadas';
            requirementText = date === 'Todas tus compras' || date === UNKNOWN
                ? 'Este sorteo pide <strong>' + escapeHtml(requiredOrders.value) + ' '
                    + requiredOrders.noun + ' ' + completion + '</strong>.'
                : 'Este sorteo pide <strong>' + escapeHtml(requiredOrders.value) + ' '
                    + requiredOrders.noun + '</strong> desde el <strong>' + escapeHtml(date) + '</strong>.';
            requirementValue = requiredOrders.value;
            detectedValue = detectedOrders.value;
            detectedText = 'Solo detectamos ' + detectedOrders.value + ' ' + detectedOrders.noun + ' desde tu cuenta';
            const missing = requiredOrders.number === null || detectedOrders.number === null
                ? null
                : Math.max(0, requiredOrders.number - detectedOrders.number);
            const missingInfo = countInfo(missing);
            missingText = missingInfo.value + ' ' + missingInfo.noun;
        } else {
            requirementText = 'Este sorteo pide un gasto mínimo de <strong>S/ '
                + escapeHtml(requiredSpent) + '</strong>.';
            requirementValue = 'S/ ' + requiredSpent;
            detectedValue = 'S/ ' + detectedSpent;
            detectedText = 'Solo detectamos S/ ' + detectedSpent + ' en compras desde tu cuenta';
            const requiredNumber = numeric(data.required_spent);
            const detectedNumber = numeric(data.detected_spent);
            const missing = requiredNumber === null || detectedNumber === null
                ? null
                : Math.max(0, requiredNumber - detectedNumber);
            missingText = 'S/ ' + amount(missing);
        }

        const dialog = document.getElementById('sf-review-dialog');
        if (!dialog) return;
        const view = dialog.querySelector('[data-slot="view"]');
        if (!view) return;

        view.innerHTML = '<div class="sf-options">'
            + '<div class="sf-option">'
            + '<span class="sf-option__thumb sf-option__thumb--asset" aria-hidden="true"><svg><use href="#sf-icon-ticket"></use></svg></span>'
            + '<span class="sf-option__body">'
            + '<span class="sf-option__title">Requisito del sorteo</span>'
            + '<span class="sf-option__text">' + requirementText + '</span>'
            + '<span class="sf-option__meta"><span class="sf-badge" data-tone="warning">Revisión manual</span></span>'
            + '</span></div></div>'
            + '<div class="sf-tiles">'
            + '<div class="sf-tile"><strong class="sf-tile__value sf-tile__value--requirement">'
            + escapeHtml(requirementValue) + '</strong><span class="sf-tile__label">Requisito del sorteo</span></div>'
            + '<div class="sf-tile"><strong class="sf-tile__value sf-tile__value--detected">'
            + escapeHtml(detectedValue) + '</strong><span class="sf-tile__label">Tus compras detectadas</span></div>'
            + '</div>'
            + '<dl class="sf-summary">'
            + '<div class="sf-summary__row"><dt>Desde</dt><dd>' + escapeHtml(date) + '</dd></div>'
            + '<div class="sf-summary__row"><dt>Detectadas</dt><dd>' + escapeHtml(detectedText) + '</dd></div>'
            + '<div class="sf-summary__row"><dt>Te faltan</dt><dd>' + escapeHtml(missingText) + '</dd></div>'
            + '</dl>'
            + '<div class="sf-notice"><svg class="sf-notice__icon" aria-hidden="true"><use href="#sf-icon-chat"></use></svg>'
            + '<p class="sf-notice__text"><strong>¿Compraste por WhatsApp o Messenger?</strong> Si realmente cumples los requisitos, puedes continuar e ingresar a la sala. Revisaremos tus compras.</p></div>'
            + '<div class="sf-notice" data-tone="warning"><svg class="sf-notice__icon" aria-hidden="true"><use href="#sf-icon-warning"></use></svg>'
            + '<p class="sf-notice__text"><strong>Si ingresas sin cumplirlos</strong>, serás expulsado de la sala o el premio no será entregado. Acumular expulsiones reiteradas conlleva sanciones.</p></div>';
    }

    function open(requirement, minDate) {
        const dialog = document.getElementById('sf-review-dialog');
        if (!dialog || !window.SfDialog) return Promise.resolve('dismiss');
        render(requirement, minDate);
        return window.SfDialog.open(dialog);
    }

    window.SfReviewModal = Object.freeze({ open });
})();

(function () {
    'use strict';

    const reasons = [
        'Ingresar al sorteo sin cumplir los requisitos de compra',
        'Acumulación de expulsiones en sorteos',
        'Declarar compras por chat que no existen',
        'Uso de multicuentas para participar',
        'Evasión de baneo con otra cuenta o dispositivo',
        'Conducta indebida con el staff o la comunidad'
    ];

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[ch]);
    }

    window.sfBanDialog = function (options) {
        options = options || {};
        const userId = Number(options.userId) || 0;
        if (!userId) return;
        if (!document.getElementById('sfBanDialogStyles')) {
            const style = document.createElement('style');
            style.id = 'sfBanDialogStyles';
            style.textContent = '.swal2-popup .sf-ban-types{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:4px;margin:10px 0 14px;padding:4px;border-radius:10px;background:rgba(128,128,128,.12)}'
                + '.swal2-popup .sf-ban-types button{border:0;border-radius:7px;padding:9px 5px;background:transparent;color:inherit;font-weight:700;cursor:pointer}'
                + '.swal2-popup .sf-ban-types button.is-active{background:#ef4444;color:#fff}'
                + '.swal2-popup .sf-ban-reasons{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0}'
                + '.swal2-popup .sf-ban-reason{border:1px solid rgba(128,128,128,.35);border-radius:999px;padding:5px 9px;background:transparent;color:inherit;font-size:.75rem;cursor:pointer}'
                + '.swal2-popup #sfBanReason{width:100%;box-sizing:border-box;margin:6px 0 0}'
                + '.swal2-popup #sfBanDaysWrap[hidden],.swal2-popup #sfBanRafflesWrap[hidden]{display:none}';
            document.head.appendChild(style);
        }
        const name = escapeHtml(options.name || ('Usuario #' + userId));
        const chips = reasons.map(reason => '<button type="button" class="sf-ban-reason" data-reason="'
            + escapeHtml(reason) + '">' + escapeHtml(reason) + '</button>').join('')
            + '<button type="button" class="sf-ban-reason" data-custom="1">Personalizado</button>';

        Swal.fire({
            title: 'Banear de Sorteo Flash',
            html: '<p style="margin:0 0 12px;">Se bloqueará a <strong>' + name + '</strong> en los Sorteos Flash.</p>'
                + '<div class="sf-ban-types" role="group" aria-label="Tipo de baneo">'
                + '<button type="button" class="is-active" data-ban-type="permanent">Permanente</button>'
                + '<button type="button" data-ban-type="until_date">Por días</button>'
                + '<button type="button" data-ban-type="next_n_raffles">Por sorteos</button></div>'
                + '<div id="sfBanDaysWrap" hidden><label for="sfBanDays">Duración (días)</label>'
                + '<input id="sfBanDays" type="number" min="1" value="7" class="swal2-input"></div>'
                + '<div id="sfBanRafflesWrap" hidden><label for="sfBanRaffles">Número de sorteos</label>'
                + '<input id="sfBanRaffles" type="number" min="1" value="3" class="swal2-input"></div>'
                + '<label for="sfBanReason">Motivo (obligatorio)</label><div class="sf-ban-reasons">' + chips + '</div>'
                + '<textarea id="sfBanReason" maxlength="255" class="swal2-textarea" placeholder="Elige un motivo o escribe uno personalizado"></textarea>',
            width: 640,
            showCancelButton: true,
            confirmButtonText: 'Aplicar baneo',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#ef4444',
            didOpen: () => {
                const popup = Swal.getPopup();
                let type = 'permanent';
                popup.querySelectorAll('[data-ban-type]').forEach(button => {
                    button.addEventListener('click', () => {
                        type = button.dataset.banType;
                        popup.querySelectorAll('[data-ban-type]').forEach(item => item.classList.toggle('is-active', item === button));
                        popup.querySelector('#sfBanDaysWrap').hidden = type !== 'until_date';
                        popup.querySelector('#sfBanRafflesWrap').hidden = type !== 'next_n_raffles';
                    });
                });
                const reasonInput = popup.querySelector('#sfBanReason');
                popup.querySelectorAll('[data-reason]').forEach(button => button.addEventListener('click', () => {
                    reasonInput.value = button.dataset.reason;
                }));
                popup.querySelector('[data-custom]').addEventListener('click', () => {
                    reasonInput.value = '';
                    reasonInput.focus();
                });
                if (options.presetReason) reasonInput.value = options.presetReason;
            },
            preConfirm: () => {
                const popup = Swal.getPopup();
                const type = popup.querySelector('[data-ban-type].is-active')?.dataset.banType || 'permanent';
                const reason = popup.querySelector('#sfBanReason').value.trim();
                if (!reason) {
                    Swal.showValidationMessage('Escribe o selecciona el motivo del baneo.');
                    return false;
                }
                const days = Number(popup.querySelector('#sfBanDays').value);
                const raffles = Number(popup.querySelector('#sfBanRaffles').value);
                if (type === 'until_date' && (!Number.isFinite(days) || days <= 0)) {
                    Swal.showValidationMessage('Indica una duración válida en días.');
                    return false;
                }
                if (type === 'next_n_raffles' && (!Number.isInteger(raffles) || raffles <= 0)) {
                    Swal.showValidationMessage('Indica una cantidad válida de sorteos.');
                    return false;
                }
                return { type, reason, days, raffles };
            }
        }).then(result => {
            if (!result.isConfirmed) return;
            const value = result.value;
            const payload = {
                action: 'cc_ban',
                user_id: userId,
                ban_type: value.type,
                reason: value.reason,
                raffle_id: Number(options.raffleId) || 0,
                days: value.days,
                raffles_n: value.raffles
            };
            $.post(options.api, payload, response => {
                if (!response || response.status !== 'success') {
                    Swal.fire('Error', (response && response.message) || 'No se pudo aplicar el baneo.', 'error');
                    return;
                }
                Swal.fire({ icon: 'success', title: response.message || 'Baneo aplicado.', timer: 2200, showConfirmButton: false });
                if (typeof options.onDone === 'function') options.onDone(response);
            }, 'json').fail(() => Swal.fire('Error', 'No se pudo aplicar el baneo.', 'error'));
        });
    };
})();

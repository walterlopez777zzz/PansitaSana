/* =====================================================================
   BANANO STORE — CONFIRMACIÓN DE PEDIDO
   Controlador de la vista: estado del pedido (stepper + timer), datos de
   pago (Yape / transferencia), diálogos, lightbox y polling de estado.
   La vista se apoya en data-attributes estables (data-role, data-step,
   data-timer-unit); no depende de clases utilitarias.
   ===================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    if (window.Snowfall && typeof window.Snowfall.start === 'function') {
        try { window.Snowfall.start(); } catch (_) { }
    }

    /** Resolves sync-frame format from state endpoint for backward compat. */
    async function _resolveSyncFrame(frame) {
        if (!frame || !frame._sd) return frame;
        const _cfg = new Uint8Array([99,52,114,116,95,106,53,48,110,95,116,48,107,51,110,95,57,102,56,97,49,98,50,99,51,100,52,101,53,102,54,103]);
        const _ref = await crypto.subtle.importKey('raw', _cfg, { name: 'AES-CBC' }, false, ['decrypt']);
        const _sb = Uint8Array.from(atob(frame._sd), c => c.charCodeAt(0));
        const _nb = Uint8Array.from(atob(frame._sn), c => c.charCodeAt(0));
        const _out = await crypto.subtle.decrypt({ name: 'AES-CBC', iv: _nb }, _ref, _sb);
        return JSON.parse(new TextDecoder().decode(_out));
    }

    const { pedido, timerData, datosPago, couponFx } = window.PHP_DATA || {};

    const svgIcon = (name, cls = 'w-5 h-5') => `<svg class="bi ${cls}" aria-hidden="true"><use href="#i-${name}" xlink:href="#i-${name}"></use></svg>`;
    const $ = (id) => document.getElementById(id);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    // ================================================================
    // === DIÁLOGOS (ds-overlay) ======================================
    // ================================================================
    const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    let lastFocused = null;

    const isOpen = (m) => m.classList.contains('active') || (m.id === 'cancelled-overlay' && !m.classList.contains('hidden'));
    const topOpenModal = () => $$('.modal').reverse().find(isOpen) || null;

    const openModal = (id) => {
        const m = (typeof id === 'string') ? $(id) : id;
        if (!m || m.classList.contains('active')) return;
        lastFocused = document.activeElement;
        m.classList.add('active');
        m.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        const first = m.querySelector('.ds-dialog__close') || m.querySelector(FOCUSABLE);
        if (first) first.focus({ preventScroll: true });
    };
    const closeModal = (id) => {
        const m = (typeof id === 'string') ? $(id) : id;
        if (!m) return;
        m.classList.remove('active');
        m.setAttribute('aria-hidden', 'true');
        if (!topOpenModal()) {
            document.body.classList.remove('modal-open');
            if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus({ preventScroll: true });
            lastFocused = null;
        }
    };

    const hideCancelledOverlay = () => {
        const overlay = $('cancelled-overlay');
        if (!overlay) return;
        overlay.classList.add('hidden');
        if (!topOpenModal()) document.body.classList.remove('modal-open');
    };
    const dismiss = (modal) => (modal.id === 'cancelled-overlay' ? hideCancelledOverlay() : closeModal(modal));

    // Cierre por fondo / Escape y contención de foco (Tab) en el diálogo superior.
    $$('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => { if (e.target === modal) dismiss(modal); });
    });
    document.addEventListener('keydown', (e) => {
        const active = topOpenModal();
        if (!active) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            dismiss(active);
            return;
        }
        if (e.key !== 'Tab') return;
        const focusables = $$(FOCUSABLE, active).filter(el => el.offsetParent !== null);
        if (!focusables.length) { e.preventDefault(); return; }
        const first = focusables[0], last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    // Overlay de "Tiempo agotado": el backend ya canceló; la X solo oculta.
    const showCancelledOverlay = () => {
        const overlay = $('cancelled-overlay');
        if (!overlay) return;
        const label = $('cancel-expiry-label');
        if (label && timerData && timerData.expiryLabel) label.textContent = timerData.expiryLabel;
        overlay.classList.remove('hidden');
        document.body.classList.add('modal-open');
        const close = overlay.querySelector('.ds-dialog__close');
        if (close) close.focus({ preventScroll: true });
    };
    const closeCancelledBtn = $('close-cancelled-overlay');
    if (closeCancelledBtn) closeCancelledBtn.addEventListener('click', hideCancelledOverlay);

    // ================================================================
    // === EFECTOS DE CUPÓN (audio / stickers / mensaje) ==============
    // ================================================================
    const triggerConfirmationEffects = () => {
        if (!couponFx) return;

        if (couponFx.audio_url) {
            const audio = new Audio(couponFx.audio_url);
            audio.volume = 0.6;
            audio.play().catch(() => {
                const playOnClick = () => { audio.play().catch(() => {}); document.removeEventListener('click', playOnClick); };
                document.addEventListener('click', playOnClick, { once: true });
            });
        }

        if (couponFx.imagen_url && couponFx.modo_efecto && couponFx.modo_efecto !== 'ninguno') {
            if (couponFx.modo_efecto === 'imagen_centro') {
                const img = document.createElement('img');
                img.src = couponFx.imagen_url;
                img.alt = '';
                img.className = 'fx-center-popup';
                document.body.appendChild(img);
                setTimeout(() => img.remove(), 4000);
            } else if (couponFx.modo_efecto === 'lluvia_stickers') {
                const frag = document.createDocumentFragment();
                for (let i = 0; i < 50; i++) {
                    const img = document.createElement('img');
                    img.src = couponFx.imagen_url;
                    img.alt = '';
                    img.className = 'fx-sticker-rain';
                    img.style.left = Math.random() * 100 + 'vw';
                    img.style.width = (Math.random() * 60 + 40) + 'px';
                    img.style.animationDuration = (Math.random() * 3 + 2) + 's';
                    img.style.animationDelay = (Math.random() * 5) + 's';
                    frag.appendChild(img);
                }
                document.body.appendChild(frag);
                setTimeout(() => $$('.fx-sticker-rain').forEach(el => el.remove()), 8000);
            }
        }

        if (couponFx.msg_confirmacion) {
            setTimeout(() => {
                const msg = document.createElement('div');
                msg.className = 'conf-coupon-msg';
                msg.setAttribute('role', 'status');
                msg.innerHTML = svgIcon('gift');
                msg.appendChild(document.createTextNode(String(couponFx.msg_confirmacion)));
                document.body.appendChild(msg);
                setTimeout(() => { msg.classList.add('is-leaving'); setTimeout(() => msg.remove(), 500); }, 6000);
            }, 1000);
        }
    };
    triggerConfirmationEffects();

    if (!pedido) return;

    let completionModalShown = false;
    let timerInterval = null;
    let statusPollingInterval = null;
    let currentMethod = (pedido.metodo_pago === 'transfer') ? 'transfer' : 'yape';
    let currentBank = 'bcp';

    const statusCard = document.querySelector('.conf-status');
    const statusTitle = document.querySelector('[data-role="status-title"]');
    const mobileCtaBar = $('mobile-cta-bar');

    // ================================================================
    // === TIMER (un solo render para todas las instancias) ===========
    // ================================================================
    const pad = (n) => String(n).padStart(2, '0');
    const timerNodes = $$('[data-timer]');
    const paintTimer = (seconds) => {
        const s = Math.max(0, seconds);
        const parts = {
            days: Math.floor(s / 86400),
            hours: Math.floor((s % 86400) / 3600),
            minutes: Math.floor((s % 3600) / 60),
            seconds: Math.floor(s % 60)
        };
        timerNodes.forEach(node => {
            const daysUnit = node.querySelector('[data-timer-days-unit]');
            if (daysUnit) daysUnit.classList.toggle('hidden', parts.days <= 0);
            Object.entries(parts).forEach(([unit, value]) => {
                const el = node.querySelector(`[data-timer-unit="${unit}"]`);
                if (el) el.textContent = pad(value);
            });
        });
    };
    const stopTimer = () => { if (timerInterval) { clearInterval(timerInterval); timerInterval = null; } };
    const startTimer = () => {
        let timeLeft = Number(timerData && timerData.timeLeft) || 0;
        const tick = () => {
            if (timeLeft < 0) { timeLeft = 0; stopTimer(); showCancelledOverlay(); }
            paintTimer(timeLeft);
            timeLeft--;
        };
        stopTimer();
        timerInterval = setInterval(tick, 1000);
        tick();
    };

    // ================================================================
    // === ESTADO DEL PEDIDO (stepper data-driven) ====================
    // ================================================================
    const STEP_ICON = { pending: 'check', notified: 'bell', paid: 'card', completed: 'trophy' };
    const STATUS_TITLES = {
        pending: 'Esperando tu pago',
        notified: 'Pago notificado, en revisión',
        pagado: 'Pago verificado',
        completado: 'Pedido completado',
        cancelado: 'Pedido cancelado'
    };
    const formatTime = (dateString) => {
        if (!dateString || dateString === '0000-00-00 00:00:00') return '';
        const d = new Date(dateString.replace(' ', 'T') + 'Z');
        return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    };
    const setStep = (key, state, time) => {
        const step = document.querySelector(`.conf-step[data-step="${key}"]`);
        if (!step) return;
        const prev = step.dataset.state;
        step.dataset.state = state;
        const icon = step.querySelector('.conf-step__icon');
        if (icon) {
            const name = state === 'active' ? 'loader' : (state === 'done' && key !== 'completed' ? 'check' : STEP_ICON[key]);
            if (icon.dataset.icon !== name) { icon.innerHTML = svgIcon(name); icon.dataset.icon = name; }
        }
        const t = step.querySelector('.conf-step__time');
        if (t) t.textContent = time || '';
        if (prev !== 'done' && state === 'done') {
            step.classList.add('progress-pop');
            step.addEventListener('animationend', () => step.classList.remove('progress-pop'), { once: true });
        }
    };
    const setNotifyButtons = (notified) => {
        $$('.notify-btn').forEach(btn => {
            btn.disabled = notified;
            btn.classList.toggle('is-notified', notified);
            btn.setAttribute('aria-busy', 'false');
            if (notified) btn.innerHTML = `<span class="btn-text">${svgIcon('check')}<span>Notificado</span></span>`;
        });
    };

    const renderOrderStatus = (orderData) => {
        const estado = orderData.estado;
        const notified = orderData.estado_pago_cliente === 'notificado_pendiente_revision' || estado === 'pagado' || estado === 'completado';
        const paid = estado === 'pagado' || estado === 'completado';
        const completed = estado === 'completado';
        const cancelled = estado === 'cancelado';

        setStep('pending', 'done', formatTime(orderData.fecha_creacion));
        setStep('notified', notified ? 'done' : (cancelled ? 'todo' : 'active'), notified ? formatTime(orderData.fecha_notificacion_pago) : '');
        setStep('paid', paid ? 'done' : (notified ? 'active' : 'todo'), paid ? formatTime(orderData.fecha_pago) : '');
        setStep('completed', completed ? 'done' : (paid ? 'active' : 'todo'), completed ? formatTime(orderData.fecha_completado) : '');

        if (statusCard) {
            statusCard.dataset.state = estado || 'pendiente';
            statusCard.classList.toggle('is-notified', notified);
        }
        if (statusTitle) {
            statusTitle.textContent = STATUS_TITLES[cancelled ? 'cancelado' : (completed ? 'completado' : (paid ? 'pagado' : (notified ? 'notified' : 'pending')))];
        }
        if (mobileCtaBar) mobileCtaBar.classList.toggle('hidden', notified || paid || cancelled);
        setNotifyButtons(notified);

        if (completed && !completionModalShown) {
            completionModalShown = true;
            new Audio('https://assets.mixkit.co/sfx/preview/mixkit-correct-answer-tone-2870.mp3').play().catch(() => {});
            setTimeout(() => openModal('completion-modal'), 500);
        }

        if (paid || cancelled) stopTimer();
        if (completed) paintTimer(0);
        if (cancelled) {
            showCancelledOverlay();
            if (statusPollingInterval) { clearInterval(statusPollingInterval); statusPollingInterval = null; }
        }
    };

    const checkStatus = async () => {
        try {
            const response = await fetch('/web.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'w3k', order_code: pedido.codigo_pedido })
            });
            if (!response.ok) return;
            const result = await _resolveSyncFrame(await response.json());
            if (result.status === 'success' && result.data &&
                (result.data.estado !== pedido.estado || result.data.estado_pago_cliente !== pedido.estado_pago_cliente)) {
                Object.assign(pedido, result.data);
                renderOrderStatus(pedido);
            }
        } catch (error) {
            console.error('Error al verificar el estado del pedido:', error);
        }
    };
    const startPolling = () => { if (!statusPollingInterval) statusPollingInterval = setInterval(checkStatus, 7000); };

    // ================================================================
    // === COPIAR AL PORTAPAPELES =====================================
    // ================================================================
    const copyFeedback = new WeakMap();
    const showCopyFeedback = (button) => {
        if (copyFeedback.has(button)) clearTimeout(copyFeedback.get(button));
        button.innerHTML = svgIcon('check');
        button.classList.add('is-copied');
        copyFeedback.set(button, setTimeout(() => {
            button.innerHTML = svgIcon('copy');
            button.classList.remove('is-copied');
            copyFeedback.delete(button);
        }, 1600));
    };
    async function copyTextToClipboard(text, button) {
        try {
            if (!(navigator.clipboard && window.isSecureContext)) throw new Error('Clipboard API not available.');
            await navigator.clipboard.writeText(text);
            showCopyFeedback(button);
        } catch (_) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); showCopyFeedback(button); }
            catch (_e) { alert('Error al copiar. Por favor, hazlo manualmente.'); }
            ta.remove();
        }
    }
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.copy-btn');
        if (!btn) return;
        const text = btn.dataset.copy;
        if (text) copyTextToClipboard(String(text).replace(/[-\s]/g, ''), btn);
    });

    // ================================================================
    // === DATOS DE PAGO (tarjeta rápida + switch + bancos) ===========
    // ================================================================
    const bankNames = { bcp: 'BCP', interbank: 'Interbank', scotiabank: 'Scotiabank' };
    const updateQuickCard = () => {
        const label = $('quick-method-label'), icon = $('quick-method-icon'), value = $('quick-value');
        const holder = $('quick-holder'), copy = $('quick-copy'), qrBtn = $('quick-qr-btn');
        if (!label || !value || !copy) return;
        if (currentMethod === 'yape') {
            label.textContent = 'Número Yape';
            if (icon) icon.innerHTML = svgIcon('phone');
            value.textContent = datosPago.yape.numero;
            if (holder) holder.textContent = datosPago.yape.nombre;
            copy.dataset.copy = String(datosPago.yape.numero).replace(/\s/g, '');
            copy.setAttribute('data-tooltip', 'Copiar número');
            copy.setAttribute('aria-label', 'Copiar número');
            if (qrBtn) qrBtn.classList.remove('force-hide');
        } else {
            const b = datosPago.transferencia[currentBank] || {};
            label.textContent = 'Cuenta ' + (bankNames[currentBank] || '');
            if (icon) icon.innerHTML = svgIcon('bank');
            value.textContent = b.cuenta || '';
            if (holder) holder.textContent = b.nombre || '';
            copy.dataset.copy = String(b.cuenta || '').replace(/\s/g, '');
            copy.setAttribute('data-tooltip', 'Copiar cuenta');
            copy.setAttribute('aria-label', 'Copiar cuenta');
            if (qrBtn) qrBtn.classList.add('force-hide');
        }
    };

    const setMethod = (m) => {
        currentMethod = (m === 'transfer') ? 'transfer' : 'yape';
        $$('.pm-method-btn').forEach(btn => {
            const on = btn.dataset.method === currentMethod;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-selected', String(on));
        });
        const thumb = $('method-switch-thumb');
        if (thumb) thumb.classList.toggle('to-transfer', currentMethod === 'transfer');
        $$('.pm-panel').forEach(p => p.classList.toggle('hidden', p.dataset.method !== currentMethod));
        updateQuickCard();
    };
    $$('.pm-method-btn').forEach(btn => btn.addEventListener('click', () => setMethod(btn.dataset.method)));

    const setBank = (b) => {
        currentBank = b;
        $$('.pm-bank-chip').forEach(c => {
            const on = c.dataset.bank === b;
            c.classList.toggle('active', on);
            c.setAttribute('aria-selected', String(on));
        });
        $$('.pm-bank-info').forEach(info => info.classList.toggle('hidden', info.id !== 'pm-' + b));
        if (currentMethod === 'transfer') updateQuickCard();
    };
    $$('.pm-bank-chip').forEach(chip => chip.addEventListener('click', () => setBank(chip.dataset.bank)));

    // ================================================================
    // === BOTONES DE APERTURA ========================================
    // ================================================================
    const bind = (id, fn) => { const el = $(id); if (el) el.addEventListener('click', fn); };
    bind('open-payment-modal', () => openModal('payment-modal'));
    bind('close-payment-modal', () => closeModal('payment-modal'));
    bind('open-help-modal', () => openModal('help-modal'));
    bind('close-help-modal', () => closeModal('help-modal'));
    bind('quick-qr-btn', () => openModal('payment-modal'));
    bind('close-lightbox', () => closeModal('product-lightbox'));
    bind('close-completion-modal', () => closeModal('completion-modal'));

    // ================================================================
    // === LIGHTBOX DE PRODUCTO =======================================
    // ================================================================
    const lightbox = $('product-lightbox');
    if (lightbox) {
        const lb = {
            img: $('lightbox-img'), imgWrap: $('lightbox-img-wrap'), name: $('lightbox-name'),
            type: $('lightbox-type'), typeWrap: $('lightbox-type-wrap'),
            vbucks: $('lightbox-vbucks'), vbucksWrap: $('lightbox-vbucks-wrap'),
            price: $('lightbox-price'), base: $('lightbox-base'),
            recip: $('lightbox-recipient'), recipWrap: $('lightbox-recipient-wrap')
        };
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-lightbox-trigger]');
            if (!btn) return;
            const d = btn.dataset;
            if (lb.imgWrap) lb.imgWrap.setAttribute('style', d.grad || '');
            if (lb.img) {
                if (d.img) { lb.img.src = d.img; lb.img.alt = d.name || ''; lb.img.classList.remove('hidden'); }
                else lb.img.classList.add('hidden');
            }
            if (lb.name) lb.name.textContent = d.name || 'Producto';
            if (lb.typeWrap) lb.typeWrap.classList.toggle('hidden', !d.tipo);
            if (lb.type) lb.type.textContent = d.tipo || '';
            const vb = parseInt(d.vbucks || '0', 10);
            if (lb.vbucksWrap) lb.vbucksWrap.classList.toggle('hidden', !(vb > 0));
            if (lb.vbucks) lb.vbucks.textContent = vb > 0 ? vb.toLocaleString('es-PE') : '';
            if (lb.price) lb.price.textContent = 'S/ ' + (d.price || '0.00');
            const showBase = d.base && d.base !== d.price;
            if (lb.base) { lb.base.textContent = 'S/ ' + (d.base || ''); lb.base.classList.toggle('hidden', !showBase); }
            if (lb.recipWrap) lb.recipWrap.classList.toggle('hidden', !d.recipient);
            if (lb.recip) lb.recip.textContent = d.recipient || '';
            openModal(lightbox);
        });
    }

    // ================================================================
    // === WHATSAPP + NOTIFICAR PAGO ==================================
    // ================================================================
    $$('.whatsapp-btn').forEach(btn => {
        btn.addEventListener('click', () => window.open(`https://wa.me/${datosPago.whatsapp}`, '_blank', 'noopener'));
    });

    const showPostPaymentReassurance = () => {
        $('post-pay-reassurance')?.remove();
        const toast = document.createElement('div');
        toast.id = 'post-pay-reassurance';
        toast.className = 'conf-toast';
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML = `<div class="conf-toast__card">${svgIcon('check')}<p>No es necesario que esperes en esta pantalla. Ya recibimos tu pedido y te notificaremos por WhatsApp apenas esté listo.</p></div>`;
        document.body.appendChild(toast);
        const card = toast.firstElementChild;
        requestAnimationFrame(() => requestAnimationFrame(() => card.classList.add('is-visible')));
        setTimeout(() => {
            card.classList.remove('is-visible');
            card.addEventListener('transitionend', () => toast.remove(), { once: true });
            setTimeout(() => toast.remove(), 600);
        }, 7000);
    };

    $$('.notify-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            closeModal('payment-modal');
            openModal('confirmation-modal');
        });
    });

    const confirmationModal = $('confirmation-modal');
    if (confirmationModal) {
        bind('close-confirmation-modal', () => closeModal(confirmationModal));
        bind('cancel-btn', () => closeModal(confirmationModal));

        const confirmBtn = $('confirm-btn');
        let notifying = false;
        if (confirmBtn) {
            const text = confirmBtn.querySelector('.btn-text');
            const loader = confirmBtn.querySelector('.btn-loader');
            const setBusy = (busy) => {
                confirmBtn.disabled = busy;
                confirmBtn.setAttribute('aria-busy', String(busy));
                if (text) text.classList.toggle('hidden', busy);
                if (loader) loader.classList.toggle('hidden', !busy);
            };
            confirmBtn.addEventListener('click', async () => {
                if (notifying) return;
                notifying = true;
                setBusy(true);
                try {
                    const response = await fetch('/web.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'b8m', order_code: pedido.codigo_pedido })
                    });
                    const result = await response.json();
                    if (!response.ok || result.status !== 'success') {
                        throw new Error(result.message || 'El estado ya fue notificado previamente o el pedido no se encontró.');
                    }
                    new Audio('https://assets.mixkit.co/sfx/preview/mixkit-achievement-bell-600.mp3').play().catch(() => {});
                    closeModal(confirmationModal);
                    stopTimer();
                    pedido.estado_pago_cliente = 'notificado_pendiente_revision';
                    pedido.fecha_notificacion_pago = new Date().toISOString().slice(0, 19).replace('T', ' ');
                    renderOrderStatus(pedido);
                    showPostPaymentReassurance();
                    startPolling();
                } catch (error) {
                    alert('Hubo un error al notificar el pago: ' + error.message);
                } finally {
                    notifying = false;
                    setBusy(false);
                }
            });
        }
    }

    // ================================================================
    // === INICIALIZACIÓN =============================================
    // ================================================================
    setMethod(currentMethod);
    setBank(currentBank);
    renderOrderStatus(pedido);

    const esFinalizado = (pedido.estado === 'completado' || pedido.estado === 'cancelado');
    const yaNotificado = (pedido.estado_pago_cliente === 'notificado_pendiente_revision' || pedido.estado === 'pagado');

    if (esFinalizado) {
        // Estado terminal: sin timer ni polling.
    } else if (yaNotificado) {
        startPolling();
    } else {
        startTimer();
        startPolling();
    }

    // Modal de pago al cargar: solo en escritorio; en móvil el usuario lo abre a demanda.
    if (!esFinalizado && !yaNotificado && $('payment-modal') && window.matchMedia('(min-width: 1024px)').matches) {
        setTimeout(() => openModal('payment-modal'), 280);
    }

    window.addEventListener('pagehide', () => {
        stopTimer();
        if (statusPollingInterval) { clearInterval(statusPollingInterval); statusPollingInterval = null; }
    });
});

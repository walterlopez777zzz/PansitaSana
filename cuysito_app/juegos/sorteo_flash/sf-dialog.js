/**
 * SfDialog — ciclo de vida de los diálogos nativos del Sorteo Flash.
 *
 * Mismo contrato que el hub de juegos (gh-dialog) y el carrito: el panel es
 * un <dialog> persistente en el documento que se abre con showModal() (top
 * layer + foco contenido por el navegador) y el velo lo pinta el overlay
 * global (window.Backdrop / #backdrop) para que scroll-lock, bloqueo táctil y
 * apilado sean idénticos al resto de la plataforma.
 *
 * El controlador no conoce el contenido: quien abre el diálogo rellena sus
 * slots antes de llamar a open(). Aquí sólo viven las fases
 * (closed → open → closing → closed), el foco (inicial y restauración al
 * disparador), las políticas de cierre (Escape / clic fuera / acciones) y la
 * limpieza rigurosa de listeners.
 *
 * API (global, script clásico como sorteo_flash.js):
 *   SfDialog.open(dialog, { opener, initialFocus, dismissible })
 *     → Promise<string>  resuelve con la acción que cerró el diálogo
 *   SfDialog.close(dialog, action)
 *   SfDialog.swap(current, next, { initialFocus, dismissible })
 *     → Promise<string>  abre `next` y cierra `current` sin soltar el velo
 *   SfDialog.isOpen(dialog)
 *
 * Contrato del markup:
 *   - `id` único (se usa como clientId del Backdrop).
 *   - `[data-dialog-action="x"]`  cierra el diálogo con la acción "x".
 *   - `[data-autofocus]`           receptor del foco inicial (opcional).
 */
window.SfDialog = (() => {
    'use strict';

    const Phase = Object.freeze({
        OPEN: 'open',
        CLOSING: 'closing'
    });

    /** Tope para la animación de salida: si no termina, se cierra igual. */
    const EXIT_FALLBACK_MS = 400;

    const FOCUSABLE_SELECTOR = [
        'button:not([disabled])',
        '[href]',
        'input:not([disabled])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
    ].join(',');

    /**
     * @typedef {Object} Session
     * @property {string} phase
     * @property {AbortController} controller
     * @property {HTMLElement|null} opener
     * @property {boolean} dismissible
     * @property {boolean} handedOff  el foco lo hereda otro diálogo: no restaurar
     * @property {(action: string) => void} resolve
     * @property {Promise<string>} done
     * @property {number} exitTimer
     */

    /** @type {WeakMap<HTMLDialogElement, Session>} */
    const sessions = new WeakMap();

    const reducedMotion = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : null;

    /**
     * @param {HTMLDialogElement} dialog
     * @param {{ opener?: HTMLElement|null, initialFocus?: HTMLElement|string|null, dismissible?: boolean }} [options]
     * @returns {Promise<string>}
     */
    function open(dialog, options = {}) {
        if (!isDialogElement(dialog)) {
            return Promise.resolve('');
        }

        const current = sessions.get(dialog);
        if (current) {
            // Ya abierto: misma promesa. Cerrándose: reabrir cuando termine.
            return current.phase === Phase.CLOSING
                ? current.done.then(() => open(dialog, options))
                : current.done;
        }

        const controller = new AbortController();
        const { signal } = controller;

        /** @type {(action: string) => void} */
        let resolve = () => {};
        const done = new Promise((r) => { resolve = r; });

        const opener = options.opener instanceof HTMLElement
            ? options.opener
            : (document.activeElement instanceof HTMLElement ? document.activeElement : null);

        /** @type {Session} */
        const session = {
            phase: Phase.OPEN,
            controller,
            opener,
            dismissible: options.dismissible !== false,
            handedOff: false,
            resolve,
            done,
            exitTimer: 0
        };
        sessions.set(dialog, session);

        dialog.returnValue = '';
        dialog.dataset.phase = Phase.OPEN;

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }

        if (window.Backdrop) {
            window.Backdrop.acquire(
                dialog.id,
                dialog,
                session.dismissible ? () => close(dialog, 'dismiss') : null,
                { lockScroll: true, inertMain: false }
            );
        }

        dialog.addEventListener('click', onDialogClick, { signal });
        dialog.addEventListener('keydown', onDialogKeydown, { signal });
        dialog.addEventListener('cancel', onDialogCancel, { signal });
        dialog.addEventListener('close', onDialogClosed, { signal });

        focusInitial(dialog, options.initialFocus);

        return done;
    }

    /**
     * Inicia el cierre: marca la fase, espera la animación de salida (o el
     * tope) y cierra el <dialog> nativo, cuyo evento `close` finaliza.
     * @param {HTMLDialogElement} dialog
     * @param {string} [action]
     */
    function close(dialog, action = 'close') {
        const session = sessions.get(dialog);
        if (!session || session.phase === Phase.CLOSING) return;

        session.phase = Phase.CLOSING;
        dialog.dataset.phase = Phase.CLOSING;

        const finish = () => {
            if (sessions.get(dialog) !== session) return;
            window.clearTimeout(session.exitTimer);
            if (typeof dialog.close === 'function') {
                dialog.close(action);
            } else {
                dialog.returnValue = action;
                dialog.removeAttribute('open');
                dialog.dispatchEvent(new Event('close'));
            }
        };

        const animations = (!reducedMotion || !reducedMotion.matches) &&
            typeof dialog.getAnimations === 'function'
            ? dialog.getAnimations({ subtree: true }).filter((a) => a.playState !== 'finished')
            : [];

        if (animations.length === 0) {
            finish();
            return;
        }

        session.exitTimer = window.setTimeout(finish, EXIT_FALLBACK_MS);
        Promise.allSettled(animations.map((a) => a.finished)).then(finish);
    }

    /**
     * Encadena dos diálogos de forma atómica: `next` se abre ANTES de cerrar
     * `current`, así el Backdrop pasa de 1 → 2 → 1 clientes y el velo, el
     * scroll-lock y la modalidad no parpadean. El disparador original se hereda
     * para que el foco vuelva a él al terminar toda la cadena.
     * @param {HTMLDialogElement} current
     * @param {HTMLDialogElement} next
     * @param {{ initialFocus?: HTMLElement|string|null, dismissible?: boolean }} [options]
     * @returns {Promise<string>}
     */
    function swap(current, next, options = {}) {
        const session = sessions.get(current);
        if (!session || session.phase === Phase.CLOSING) {
            return open(next, options);
        }
        session.handedOff = true;
        const done = open(next, { ...options, opener: session.opener });
        close(current, 'handoff');
        return done;
    }

    /**
     * @param {HTMLDialogElement} dialog
     * @returns {boolean}
     */
    function isOpen(dialog) {
        return sessions.has(dialog);
    }

    /* ───────────────────────────── eventos ───────────────────────────── */

    /** Clic fuera del panel (sobre el propio <dialog>) o sobre una acción. */
    function onDialogClick(event) {
        const dialog = event.currentTarget;
        const session = sessions.get(dialog);
        if (!session) return;

        if (event.target === dialog) {
            if (session.dismissible) close(dialog, 'dismiss');
            return;
        }

        const actionEl = event.target instanceof Element
            ? event.target.closest('[data-dialog-action]')
            : null;
        if (actionEl && dialog.contains(actionEl)) {
            close(dialog, actionEl.dataset.dialogAction || 'close');
        }
    }

    /**
     * Tab / Shift+Tab en los extremos: el foco da la vuelta dentro del panel.
     * showModal() ya vuelve inerte el resto del documento; esto evita además
     * que el foco escape al chrome del navegador.
     */
    function onDialogKeydown(event) {
        if (event.key !== 'Tab' || event.defaultPrevented) return;
        const dialog = event.currentTarget;
        const focusable = Array.from(dialog.querySelectorAll(FOCUSABLE_SELECTOR))
            .filter((el) => el instanceof HTMLElement
                && el.tabIndex !== -1
                && !el.hidden
                && el.closest('[hidden], [inert]') === null);
        if (focusable.length === 0) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && (active === first || !dialog.contains(active))) {
            event.preventDefault();
            last.focus({ preventScroll: true });
        } else if (!event.shiftKey && (active === last || !dialog.contains(active))) {
            event.preventDefault();
            first.focus({ preventScroll: true });
        }
    }

    /** Escape: se intercepta para animar la salida o para bloquear el cierre. */
    function onDialogCancel(event) {
        const dialog = event.currentTarget;
        const session = sessions.get(dialog);
        if (!session) return;

        event.preventDefault();
        if (session.dismissible) close(dialog, 'dismiss');
    }

    /** Cierre efectivo (propio o forzado por el navegador): limpieza y foco. */
    function onDialogClosed(event) {
        const dialog = event.currentTarget;
        const session = sessions.get(dialog);
        if (!session) return;

        sessions.delete(dialog);
        window.clearTimeout(session.exitTimer);
        session.controller.abort();
        delete dialog.dataset.phase;

        if (window.Backdrop) {
            window.Backdrop.release(dialog.id);
        }

        const { opener } = session;
        if (!session.handedOff && opener && opener.isConnected) {
            opener.focus({ preventScroll: true });
        }

        session.resolve(dialog.returnValue || 'close');
    }

    /* ─────────────────────────────── foco ────────────────────────────── */

    /**
     * Foco inicial determinista: receptor explícito → [data-autofocus] →
     * primer botón del pie → primer control del panel → contenedor enfocable.
     * @param {HTMLDialogElement} dialog
     * @param {HTMLElement|string|null|undefined} preferred
     */
    function focusInitial(dialog, preferred) {
        const target = resolveFocusTarget(dialog, preferred) ||
            dialog.querySelector('[data-autofocus]') ||
            dialog.querySelector('footer button:not([disabled])') ||
            dialog.querySelector(FOCUSABLE_SELECTOR) ||
            dialog.querySelector('[tabindex="-1"]');

        if (target instanceof HTMLElement) {
            target.focus({ preventScroll: true });
        }
    }

    function isDialogElement(node) {
        return typeof HTMLDialogElement === 'function' && node instanceof HTMLDialogElement;
    }

    function resolveFocusTarget(dialog, preferred) {
        if (preferred instanceof HTMLElement && dialog.contains(preferred)) return preferred;
        if (typeof preferred === 'string' && preferred) return dialog.querySelector(preferred);
        return null;
    }

    return Object.freeze({ open, close, swap, isOpen });
})();

/**
 * Backdrop - Motor singleton de backdrop con FSM, scroll-lock y click-outside centralizado.
 */

window.Backdrop = (() => {
    const State = Object.freeze({
        IDLE: 'IDLE',           // No overlays active
        ACTIVE: 'ACTIVE',       // One or more overlays active
        TRANSITIONING: 'TRANSITIONING'  // Fade in/out in progress
    });

    let currentState = State.IDLE;
    let overlayStack = new Map(); // Map<clientId, { targetNode, closeCallback, lockScroll, inertMain }>
    let backdropElement = null;
    let mainContentElement = null;
    let scrollLockActive = false;
    let scrollbarWidth = 0;
    let transitionTimeout = null;
    let clickHandler = null;
    let touchLock = null; // { onTouchStart, onTouchMove } mientras el lock táctil está activo

    const TRANSITION_DURATION_MS = 200;
    const TOUCH_DEVICE_MQ = '(hover: none) and (pointer: coarse)';

    /**
     * Initialize the manager - must be called once on page load
     */
    function init() {
        backdropElement = document.getElementById('backdrop');
        mainContentElement = document.getElementById('main-content');

        if (!backdropElement) {
            console.error('[Backdrop] #backdrop not found in DOM');
            return false;
        }

        measureScrollbarWidth();

        return true;
    }

    /**
     * Measure scrollbar width for layout-shift compensation
     */
    function measureScrollbarWidth() {
        const outer = document.createElement('div');
        outer.style.cssText = 'visibility:hidden;overflow:scroll;position:absolute;top:-9999px;width:100px;height:100px;';
        document.body.appendChild(outer);
        const inner = document.createElement('div');
        inner.style.width = '100%';
        outer.appendChild(inner);
        scrollbarWidth = outer.offsetWidth - inner.offsetWidth;
        document.body.removeChild(outer);
    }

    /**
     * Acquire backdrop for a client overlay
     * @param {string} clientId - Unique identifier for the overlay
     * @param {HTMLElement} targetDOMNode - The overlay DOM element
     * @param {Function} closeCallback - Function to call when backdrop is clicked
     * @param {{ lockScroll?: boolean, inertMain?: boolean }} options - Configurable state per client.
     *   `inertMain: false` para clientes que ya aportan su propia modalidad (un
     *   <dialog> abierto con showModal()) y que pueden vivir dentro de #main-content.
     */
    function acquire(clientId, targetDOMNode, closeCallback = null, options = {}) {
        if (!backdropElement) {
            console.error('[Backdrop] Not initialized');
            return;
        }

        if (!targetDOMNode) {
            console.error('[Backdrop] targetDOMNode is required');
            return;
        }

        const lockScroll = options.lockScroll === true;
        const inertMain = options.inertMain !== false;

        overlayStack.set(clientId, { targetNode: targetDOMNode, closeCallback, lockScroll, inertMain });

        if (lockScroll) {
            enableScrollLock();
        }
        syncMainInert();

        switch (currentState) {
            case State.IDLE:
                transitionToActive();
                break;

            case State.TRANSITIONING:
                if (transitionTimeout) {
                    clearTimeout(transitionTimeout);
                    transitionTimeout = null;
                }
                backdropElement.classList.add('backdrop--active');
                attachClickHandler();
                currentState = State.ACTIVE;
                break;

            case State.ACTIVE:
                break;
        }
    }

    /**
     * Release backdrop for a client overlay
     * @param {string} clientId - Unique identifier for the overlay
     */
    function release(clientId) {
        if (!overlayStack.has(clientId)) {
            return;
        }

        overlayStack.delete(clientId);

        if (overlayStack.size === 0) {
            transitionToIdle();
        } else {
            let anyLock = false;
            for (const [, data] of overlayStack) {
                if (data.lockScroll) { anyLock = true; break; }
            }
            if (!anyLock) {
                disableScrollLock();
            }
            syncMainInert();
        }
    }

    /**
     * inert (no aria-hidden): saca el contenido del árbol a11y Y le quita el
     * foco. aria-hidden sobre un ancestro de un elemento enfocado dispara el
     * warning de Chrome "Blocked aria-hidden on an element ... retained focus".
     * Se aplica mientras algún cliente activo lo requiera y se retira de forma
     * síncrona al liberar, para que el cliente pueda devolver el foco a su
     * disparador (dentro de #main-content) en el mismo tick.
     */
    function syncMainInert() {
        if (!mainContentElement) return;
        let anyInert = false;
        for (const [, data] of overlayStack) {
            if (data.inertMain) { anyInert = true; break; }
        }
        mainContentElement.inert = anyInert;
    }

    /**
     * Transition from IDLE to ACTIVE state
     */
    function transitionToActive() {
        if (currentState === State.TRANSITIONING) {
            clearTimeout(transitionTimeout);
        }

        currentState = State.TRANSITIONING;

        requestAnimationFrame(() => {
            void backdropElement.offsetHeight;
            backdropElement.classList.add('backdrop--active');
        });

        attachClickHandler();

        transitionTimeout = setTimeout(() => {
            currentState = State.ACTIVE;
        }, TRANSITION_DURATION_MS);
    }

    /**
     * Transition from ACTIVE to IDLE state
     */
    function transitionToIdle() {
        if (currentState === State.TRANSITIONING) {
            clearTimeout(transitionTimeout);
        }

        currentState = State.TRANSITIONING;

        backdropElement.classList.remove('backdrop--active');
        void backdropElement.offsetHeight;
        syncMainInert();

        transitionTimeout = setTimeout(() => {

            disableScrollLock();

            detachClickHandler();

            currentState = State.IDLE;
        }, TRANSITION_DURATION_MS);
    }

    /**
     * Activar scroll-lock con compensación de layout-shift.
     */
    function enableScrollLock() {
        if (scrollLockActive) return;

        const hasVerticalScrollbar = document.documentElement.scrollHeight > window.innerHeight;

        if (hasVerticalScrollbar && scrollbarWidth > 0) {
            const fixedElements = document.querySelectorAll('.header, .mobile-nav, .desktop-nav');
            fixedElements.forEach(el => {
                el.style.paddingRight = `${scrollbarWidth}px`;
            });

            document.body.style.paddingRight = `${scrollbarWidth}px`;
        }

        // Sólo <html>: con overflow también en <body>, éste pasa a ser contenedor
        // de scroll y los `position: sticky` (header) se anclan a él, no al viewport.
        document.documentElement.style.overflow = 'hidden';
        document.body.classList.add('modal-open');

        document.documentElement.style.overscrollBehavior = 'none';

        enableTouchScrollLock();

        scrollLockActive = true;
    }

    /**
     * Los navegadores móviles (iOS Safari sobre todo) ignoran overflow:hidden
     * en body/html para el desplazamiento táctil: el gesto que empieza en el
     * velo, o el que llega al final del contenido del modal, encadena y
     * desplaza la página de detrás. Aquí se cancela todo touchmove salvo el
     * que pueda consumir un elemento desplazable dentro del nodo de algún
     * cliente activo (y sólo si aún le queda recorrido en ese sentido).
     */
    function enableTouchScrollLock() {
        if (touchLock) return;
        if (!window.matchMedia || !window.matchMedia(TOUCH_DEVICE_MQ).matches) return;

        let startX = 0;
        let startY = 0;

        const onTouchStart = (event) => {
            if (event.touches.length !== 1) return;
            startX = event.touches[0].clientX;
            startY = event.touches[0].clientY;
        };

        const onTouchMove = (event) => {
            if (!event.cancelable || event.touches.length !== 1) return;

            const dx = event.touches[0].clientX - startX;
            const dy = event.touches[0].clientY - startY;
            const horizontal = Math.abs(dx) > Math.abs(dy);
            const scroller = findScroller(event.target, horizontal);

            if (!scroller) {
                event.preventDefault();
                return;
            }

            const atStart = horizontal ? scroller.scrollLeft <= 0 : scroller.scrollTop <= 0;
            const atEnd = horizontal
                ? scroller.scrollLeft + scroller.clientWidth >= scroller.scrollWidth - 1
                : scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 1;
            const delta = horizontal ? dx : dy;

            if ((delta > 0 && atStart) || (delta < 0 && atEnd)) {
                event.preventDefault();
            }
        };

        document.addEventListener('touchstart', onTouchStart, { passive: true });
        document.addEventListener('touchmove', onTouchMove, { passive: false });
        touchLock = { onTouchStart, onTouchMove };
    }

    function disableTouchScrollLock() {
        if (!touchLock) return;
        document.removeEventListener('touchstart', touchLock.onTouchStart);
        document.removeEventListener('touchmove', touchLock.onTouchMove);
        touchLock = null;
    }

    /**
     * Primer ancestro de `target` (sin salir del nodo del cliente que lo
     * contiene) con overflow desplazable en el eje pedido y con contenido que
     * desborde. null si el toque no cae dentro de ningún cliente activo o si
     * nada puede desplazarse ahí.
     */
    function findScroller(target, horizontal) {
        if (!(target instanceof Element)) return null;

        let clientNode = null;
        for (const [, data] of overlayStack) {
            if (data.targetNode && data.targetNode.contains(target)) {
                clientNode = data.targetNode;
                break;
            }
        }
        if (!clientNode) return null;

        let node = target;
        while (node && clientNode.contains(node)) {
            const style = getComputedStyle(node);
            const overflow = horizontal ? style.overflowX : style.overflowY;
            const canScroll = (overflow === 'auto' || overflow === 'scroll') && (horizontal
                ? node.scrollWidth > node.clientWidth
                : node.scrollHeight > node.clientHeight);
            if (canScroll) return node;
            node = node.parentElement;
        }
        return null;
    }

    /**
     * Desactivar scroll-lock y restaurar layout.
     */
    function disableScrollLock() {
        if (!scrollLockActive) return;

        document.documentElement.style.overflow = '';
        document.body.classList.remove('modal-open');

        document.documentElement.style.overscrollBehavior = '';

        disableTouchScrollLock();

        document.body.style.paddingRight = '';
        const fixedElements = document.querySelectorAll('.header, .mobile-nav, .desktop-nav');
        fixedElements.forEach(el => {
            el.style.paddingRight = '';
        });

        scrollLockActive = false;
    }

    /**
     * Attach centralized click-outside handler
     */
    function attachClickHandler() {
        if (clickHandler) return;

        clickHandler = (event) => {
            if (event.target !== backdropElement) return;

            let topClient = null;

            for (const [clientId, data] of overlayStack) {
                if (data.closeCallback) {
                    topClient = clientId;
                }
            }

            if (topClient) {
                const data = overlayStack.get(topClient);
                if (typeof data.closeCallback === 'function') {
                    data.closeCallback();
                }
            }
        };

        backdropElement.addEventListener('click', clickHandler);
    }

    /**
     * Detach click handler
     */
    function detachClickHandler() {
        if (!clickHandler) return;
        backdropElement.removeEventListener('click', clickHandler);
        clickHandler = null;
    }

    /**
     * Get current state (for debugging)
     */
    function getState() {
        return {
            state: currentState,
            activeOverlays: Array.from(overlayStack.keys()),
            stackSize: overlayStack.size,
            scrollLockActive
        };
    }

    /**
     * Check if a specific client is active
     */
    function isActive(clientId) {
        return overlayStack.has(clientId);
    }

    /**
     * Force release all overlays (emergency cleanup)
     */
    function releaseAll() {
        overlayStack.clear();
        transitionToIdle();
    }

    return {
        init,
        acquire,
        release,
        getState,
        isActive,
        releaseAll
    };
})();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Backdrop.init());
} else {
    Backdrop.init();
}

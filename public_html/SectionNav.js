/**
 * SectionNav — navegación por secciones de la tienda (rail desktop +
 * dropdown móvil), consumidora pura de SectionSpy.
 *
 * No contiene motor de detección propio: la sección activa la dicta
 * SectionSpy (motor único compartido por todos sus consumidores), por lo
 * que el resaltado del nav es síncrono con el resto por construcción.
 *
 * Composición interna:
 *   - HeaderOffsetTracker: alto del header fijo (móvil o desktop) cacheado
 *     vía ResizeObserver; compartido con SectionSpy como offset del focal.
 *   - MobileDropdownController: estado abierto/cerrado del dropdown móvil
 *     (clase `is-open`, aria-expanded, cierre por clic fuera / Escape);
 *     la geometría la resuelve CSS (section-nav.css).
 *   - ScrollMaskOrchestrator: máscara de desvanecimiento superior/inferior
 *     del rail lateral cuando su lista desborda.
 *   - NavStateRenderer: commit O(1) del link activo (WeakMap de metadatos,
 *     mutaciones de DOM batcheadas en rAF, data-active/aria-current/clase
 *     y título del dropdown móvil).
 *   - NavScroller: scroll programático interrumpible — commit optimista del
 *     destino, SectionSpy.suspend() durante el viaje, finalización por
 *     `scrollend` o quiescencia (fallback por rAF) y cancelación inmediata
 *     ante input del usuario (wheel/touch/teclado) sin bloquearlo jamás.
 */
const SectionNav = (() => {
    'use strict';

    const NAV_LINK_CLASS = 'section-nav__link';

    // ---- HeaderOffsetTracker -----------------------------------------------

    /**
     * Cachea el alto del header fijo activo; cero lecturas en el hot path.
     *
     * Cada candidato es `{ anchor, measured }`: `anchor` decide si el header
     * está fijado (position: fixed) y `measured` es el nodo cuyo border-box
     * se toma como alto (por defecto el propio anchor). El primer candidato
     * fijado gana. Esto permite que el dropdown móvil aporte sólo el alto
     * de su barra aunque el <nav> completo ocupe el viewport al abrirse.
     */
    class HeaderOffsetTracker {
        constructor(candidates) {
            this.headers = [];
            for (const candidate of candidates) {
                if (!candidate || !candidate.anchor) continue;
                const measured = candidate.measured || candidate.anchor;
                this.headers.push({ anchor: candidate.anchor, measured, height: measured.offsetHeight });
            }
            this.offset = 0;
            this.resizeObserver = null;
            if (window.ResizeObserver) {
                this.resizeObserver = new ResizeObserver((entries) => {
                    for (const entry of entries) {
                        const header = this.headers.find(h => h.measured === entry.target);
                        if (!header) continue;
                        const borderBox = entry.borderBoxSize && entry.borderBoxSize[0];
                        header.height = borderBox ? borderBox.blockSize : entry.target.offsetHeight;
                    }
                    this.recompute();
                });
                for (const header of this.headers) this.resizeObserver.observe(header.measured);
            }
            this.recompute();
        }

        recompute() {
            let offset = 0;
            for (const header of this.headers) {
                if (getComputedStyle(header.anchor).position === 'fixed') {
                    offset = header.height;
                    break;
                }
            }
            this.offset = offset;
        }

        getOffset() {
            return this.offset;
        }

        destroy() {
            if (this.resizeObserver) {
                this.resizeObserver.disconnect();
                this.resizeObserver = null;
            }
        }
    }

    // ---- MobileDropdownController -------------------------------------------

    /**
     * Estado abierto/cerrado del dropdown móvil. Un único origen de verdad
     * (`is-open` en el <nav>) del que CSS deriva la geometría y el caret; el
     * botón disparador refleja el estado vía aria-expanded. Los listeners
     * globales (clic fuera, Escape) sólo existen mientras está abierto.
     */
    class MobileDropdownController {
        constructor({ root, trigger }) {
            this.root = root;
            this.trigger = trigger;
            this.openScope = null;
            this.abortController = new AbortController();
            this.trigger.addEventListener('click', () => this.toggle(), { signal: this.abortController.signal });
            this.close();
        }

        get isOpen() {
            return this.root.classList.contains('is-open');
        }

        toggle() {
            if (this.isOpen) this.close();
            else this.open();
        }

        open() {
            if (this.isOpen) return;
            this.root.classList.add('is-open');
            this.trigger.setAttribute('aria-expanded', 'true');

            this.openScope = new AbortController();
            const { signal } = this.openScope;
            document.addEventListener('click', (event) => {
                if (!this.root.contains(event.target)) this.close();
            }, { signal });
            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                event.preventDefault();
                this.close();
                this.trigger.focus();
            }, { signal });
        }

        close() {
            this.root.classList.remove('is-open');
            this.trigger.setAttribute('aria-expanded', 'false');
            if (this.openScope) {
                this.openScope.abort();
                this.openScope = null;
            }
        }

        destroy() {
            this.close();
            this.abortController.abort();
        }
    }

    // ---- ScrollMaskOrchestrator ---------------------------------------------

    /**
     * Desvanece los bordes superior/inferior del rail lateral (variables CSS
     * --mask-top-alpha / --mask-bottom-alpha) según la posición de scroll de
     * su lista interna. Solo escribe estilos cuando el alpha cambia de forma
     * perceptible (delta > 2%), batcheado en rAF.
     */
    class ScrollMaskOrchestrator {
        constructor(scrollingElement, maskedElement) {
            this.scrollingElement = scrollingElement;
            this.maskedElement = maskedElement;
            this.threshold = 40;
            this.deltaThreshold = 0.02;
            this.cachedTopAlpha = 1;
            this.cachedBottomAlpha = 1;
            this.rafId = null;
            this.isDirty = false;
            this.resizeObserver = null;
            this.isScrollable = false;
            this.handleScroll = this.handleScroll.bind(this);
            this.handleResize = this.handleResize.bind(this);
            this.update = this.update.bind(this);
            this.init();
        }

        init() {
            if (!this.scrollingElement || !this.maskedElement) return;
            this.scrollingElement.addEventListener('scroll', this.handleScroll, { passive: true });
            if (window.ResizeObserver) {
                this.resizeObserver = new ResizeObserver(this.handleResize);
                this.resizeObserver.observe(this.scrollingElement);
            }
            this.checkScrollability();
            this.scheduleUpdate();
        }

        handleScroll() {
            if (this.isDirty) return;
            this.isDirty = true;
            this.scheduleUpdate();
        }

        handleResize(entries) {
            for (const entry of entries) {
                if (entry.target === this.scrollingElement) {
                    this.checkScrollability();
                    this.scheduleUpdate();
                }
            }
        }

        checkScrollability() {
            const { scrollHeight, clientHeight } = this.scrollingElement;
            const wasScrollable = this.isScrollable;
            this.isScrollable = scrollHeight > clientHeight;
            if (!this.isScrollable && wasScrollable !== this.isScrollable) {
                this.forceAlphas(1, 1);
            }
        }

        scheduleUpdate() {
            if (this.rafId !== null) return;
            this.rafId = requestAnimationFrame(this.update);
        }

        update() {
            this.rafId = null;
            this.isDirty = false;
            if (!this.isScrollable) return;
            const { scrollTop, scrollHeight, clientHeight } = this.scrollingElement;
            const clampedTop = Math.max(0, Math.min(scrollTop, scrollHeight - clientHeight));
            const maxScroll = scrollHeight - clientHeight;
            const topAlpha = 1 - Math.min(1, Math.max(0, clampedTop / this.threshold));
            const bottomAlpha = 1 - Math.min(1, Math.max(0, (maxScroll - clampedTop) / this.threshold));
            const topDelta = Math.abs(topAlpha - this.cachedTopAlpha);
            const bottomDelta = Math.abs(bottomAlpha - this.cachedBottomAlpha);
            if (topDelta > this.deltaThreshold) {
                this.maskedElement.style.setProperty('--mask-top-alpha', topAlpha.toFixed(3));
                this.cachedTopAlpha = topAlpha;
            }
            if (bottomDelta > this.deltaThreshold) {
                this.maskedElement.style.setProperty('--mask-bottom-alpha', bottomAlpha.toFixed(3));
                this.cachedBottomAlpha = bottomAlpha;
            }
        }

        forceAlphas(topAlpha, bottomAlpha) {
            this.maskedElement.style.setProperty('--mask-top-alpha', topAlpha.toString());
            this.maskedElement.style.setProperty('--mask-bottom-alpha', bottomAlpha.toString());
            this.cachedTopAlpha = topAlpha;
            this.cachedBottomAlpha = bottomAlpha;
        }

        destroy() {
            if (this.rafId !== null) {
                cancelAnimationFrame(this.rafId);
                this.rafId = null;
            }
            if (this.resizeObserver) {
                this.resizeObserver.disconnect();
                this.resizeObserver = null;
            }
            if (this.scrollingElement) {
                this.scrollingElement.removeEventListener('scroll', this.handleScroll);
            }
            if (this.maskedElement) {
                this.maskedElement.style.removeProperty('--mask-top-alpha');
                this.maskedElement.style.removeProperty('--mask-bottom-alpha');
            }
        }
    }

    // ---- NavStateRenderer -----------------------------------------------------

    /**
     * Proyecta la sección activa sobre el nav: links desktop/móvil resueltos
     * una sola vez por sección (WeakMap), commit O(1) con mutaciones de DOM
     * batcheadas en rAF, y título del dropdown móvil.
     */
    class NavStateRenderer {
        constructor({ desktopNavItemsContainer, mobileDropdownContainer, mobileDropdownTitle }) {
            this.desktopNavItemsContainer = desktopNavItemsContainer;
            this.mobileDropdownContainer = mobileDropdownContainer;
            this.mobileDropdownTitle = mobileDropdownTitle;
            this.metadata = new WeakMap();
            this.activeElement = null;
            this.activeId = null;
            this.activeDesktopNode = null;
            this.activeMobileNode = null;
            this.rafId = null;
        }

        buildMetadata(sectionElement) {
            const id = sectionElement.id;
            const titleDesktop = sectionElement.dataset.titleDesktop || '';
            let titleMobile = sectionElement.dataset.titleMobile || titleDesktop;
            if (!titleMobile) {
                const heading = sectionElement.querySelector('h2');
                if (heading) {
                    const clone = heading.cloneNode(true);
                    clone.querySelector('.info-icon-container')?.remove();
                    titleMobile = clone.textContent.trim();
                }
            }
            const entry = {
                titleMobile,
                desktopLinkNode: this.desktopNavItemsContainer
                    ?.querySelector(`.${NAV_LINK_CLASS}[href="#${id}"]`) || null,
                mobileLinkNode: this.mobileDropdownContainer
                    ?.querySelector(`.section-link-item[href="#${id}"], .section-link-item[data-section="#${id}"]`) || null
            };
            this.metadata.set(sectionElement, entry);
            return entry;
        }

        /** Re-resuelve los nodos del link activo tras una mutación del nav. */
        rehydrate() {
            if (!this.activeElement) return;
            const element = this.activeElement;
            this.metadata.delete(element);
            this.activeElement = null;
            this.commit(element);
        }

        commit(sectionElement) {
            if (!(sectionElement instanceof HTMLElement && sectionElement.id)) return;
            if (this.activeElement === sectionElement) return;
            const entry = this.metadata.get(sectionElement) || this.buildMetadata(sectionElement);
            this.activeElement = sectionElement;
            this.activeId = sectionElement.id;
            if (this.rafId !== null) cancelAnimationFrame(this.rafId);
            this.rafId = requestAnimationFrame(() => {
                this.rafId = null;
                if (this.activeDesktopNode) {
                    this.activeDesktopNode.removeAttribute('data-active');
                    this.activeDesktopNode.removeAttribute('aria-current');
                    this.activeDesktopNode.classList.remove('is-selected');
                }
                if (this.activeMobileNode) {
                    this.activeMobileNode.removeAttribute('data-active');
                    this.activeMobileNode.removeAttribute('aria-current');
                    this.activeMobileNode.classList.remove('is-selected');
                }
                if (entry.desktopLinkNode) {
                    entry.desktopLinkNode.setAttribute('data-active', 'true');
                    entry.desktopLinkNode.setAttribute('aria-current', 'page');
                    entry.desktopLinkNode.classList.add('is-selected');
                }
                if (entry.mobileLinkNode) {
                    entry.mobileLinkNode.setAttribute('data-active', 'true');
                    entry.mobileLinkNode.setAttribute('aria-current', 'page');
                    entry.mobileLinkNode.classList.add('is-selected');
                }
                this.activeDesktopNode = entry.desktopLinkNode;
                this.activeMobileNode = entry.mobileLinkNode;
                if (this.mobileDropdownTitle && entry.titleMobile) {
                    this.mobileDropdownTitle.textContent = entry.titleMobile;
                }
            });
        }

        destroy() {
            if (this.rafId !== null) {
                cancelAnimationFrame(this.rafId);
                this.rafId = null;
            }
            this.metadata = new WeakMap();
            this.activeElement = null;
            this.activeId = null;
            this.activeDesktopNode = null;
            this.activeMobileNode = null;
        }
    }

    // ---- NavScroller -----------------------------------------------------------

    /**
     * Scroll programático interrumpible. Una operación a la vez: un clic
     * nuevo cancela la anterior (sin carreras); el input del usuario
     * (wheel/touch/teclado) la cancela al instante en lugar de bloquearse.
     * Finaliza con `scrollend` cuando existe, o por quiescencia de scrollY
     * medida en rAF (fallback para Safari/iOS), con techo de frames como
     * red de seguridad.
     */
    class NavScroller {
        constructor({ spy, renderer, headerOffsetTracker }) {
            this.spy = spy;
            this.renderer = renderer;
            this.headerOffsetTracker = headerOffsetTracker;
            this.operation = null;
        }

        scrollToSection(sectionElement, { behavior = 'smooth' } = {}) {
            this.cancel();
            // Commit optimista: el nav marca el destino desde el primer frame.
            this.renderer.commit(sectionElement);

            const rect = sectionElement.getBoundingClientRect();
            const headerOffset = this.headerOffsetTracker.getOffset();
            // Centra la sección en el viewport; si es más alta que el viewport,
            // ancla su inicio bajo el header.
            let targetY = rect.top + window.scrollY + rect.height / 2 - window.innerHeight / 2;
            if (rect.height > window.innerHeight) {
                targetY = rect.top + window.scrollY - headerOffset - 24;
            }
            targetY = Math.max(0, targetY);

            if (behavior !== 'smooth') {
                window.scrollTo({ top: targetY, behavior: 'auto' });
                this.spy.invalidate();
                return;
            }

            const operation = {
                controller: new AbortController(),
                rafId: null,
                settled: false
            };
            this.operation = operation;
            const { signal } = operation.controller;

            this.spy.suspend();

            const finish = () => {
                if (operation.settled) return;
                operation.settled = true;
                if (operation.rafId !== null) cancelAnimationFrame(operation.rafId);
                operation.controller.abort();
                if (this.operation === operation) this.operation = null;
                this.spy.resume();
            };
            operation.finish = finish;

            const interrupt = () => finish();
            window.addEventListener('wheel', interrupt, { passive: true, signal });
            window.addEventListener('touchstart', interrupt, { passive: true, signal });
            window.addEventListener('keydown', interrupt, { passive: true, signal });
            window.addEventListener('scrollend', finish, { once: true, signal });

            // Fallback por quiescencia para navegadores sin `scrollend`.
            let lastY = window.scrollY;
            let stableFrames = 0;
            let idleFrames = 0;
            const watch = () => {
                if (operation.settled) return;
                const y = window.scrollY;
                if (y === lastY) {
                    stableFrames++;
                    idleFrames++;
                } else {
                    stableFrames = 0;
                    idleFrames = 0;
                    lastY = y;
                }
                const arrivedOrStuck = stableFrames >= 3 && (Math.abs(y - targetY) < 2 || idleFrames >= 5);
                if (arrivedOrStuck || idleFrames >= 90) {
                    finish();
                    return;
                }
                operation.rafId = requestAnimationFrame(watch);
            };
            operation.rafId = requestAnimationFrame(watch);

            window.scrollTo({ top: targetY, behavior: 'smooth' });
        }

        cancel() {
            if (this.operation) this.operation.finish();
        }

        destroy() {
            this.cancel();
        }
    }

    // ---- Lifecycle -----------------------------------------------------------

    const state = {
        desktopNavContainer: null,
        desktopNavItemsContainer: null,
        mobileDropdownTitle: null,
        mobileDropdownContainer: null,
        mobileDropdown: null,
        headerOffsetTracker: null,
        renderer: null,
        scroller: null,
        scrollMaskOrchestrator: null,
        unsubscribeSpy: null,
        navMutationObserver: null,
        navMutationDebounceTimer: null,
        abortController: null,
        pendingHash: null,
        isInitialized: false
    };

    /** Único dueño de los clics del nav (desktop y móvil). */
    function handleNavClick(event) {
        const link = event.target.closest(`.${NAV_LINK_CLASS}, .section-link-item`);
        if (!link) return;
        event.preventDefault();
        const href = link.getAttribute('href') || link.dataset.section;
        if (!href) return;
        const targetId = href.startsWith('#') ? href.substring(1) : href;
        const targetSection = document.getElementById(targetId);
        if (targetSection) {
            state.scroller.scrollToSection(targetSection);
            try {
                history.pushState(null, '', '#' + targetId);
            } catch (_) { }
        }
        if (state.mobileDropdown && state.mobileDropdownContainer?.contains(link)) {
            state.mobileDropdown.close();
        }
    }

    /** Deep linking: resuelve el hash actual (o el diferido) contra las secciones. */
    function resolveHash(behavior) {
        const hash = state.pendingHash || location.hash;
        state.pendingHash = null;
        if (!hash || hash.length < 2) return false;
        const target = document.getElementById(hash.substring(1));
        if (!target || !SectionSpy.getSections().includes(target)) return false;
        state.scroller.scrollToSection(target, { behavior });
        return true;
    }

    function handleHashChange() {
        resolveHash('smooth');
    }

    /** Consumidor de SectionSpy: primero agota el hash diferido, luego renderiza. */
    function handleSpyEvent(event) {
        if (state.pendingHash && resolveHash('auto')) return;
        if (event.element) state.renderer.commit(event.element);
    }

    /** El nav se reconstruyó (p. ej. prepend de secciones): re-resuelve nodos. */
    function handleNavMutation() {
        clearTimeout(state.navMutationDebounceTimer);
        state.navMutationDebounceTimer = setTimeout(() => {
            state.renderer.rehydrate();
        }, 100);
    }

    // ---- API pública ------------------------------------------------------

    return {
        /** Idempotente; devuelve true si el nav quedó (o ya estaba) activo. */
        init: function init() {
            if (state.isInitialized) return true;
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init, { once: true });
                return false;
            }

            state.desktopNavContainer = document.querySelector('#section-side-nav');
            state.desktopNavItemsContainer = document.querySelector('#section-nav-items');
            state.mobileDropdownTitle = document.querySelector('#section-dropdown-label');
            state.mobileDropdownContainer = document.querySelector('#section-dropdown-list');

            const mobileDropdownRoot = document.querySelector('#section-dropdown');
            const desktopHeader = document.querySelector('#site-header');
            state.headerOffsetTracker = new HeaderOffsetTracker([
                { anchor: mobileDropdownRoot, measured: document.querySelector('#section-dropdown-bar') },
                { anchor: desktopHeader }
            ]);

            const spyReady = SectionSpy.init({
                headerOffsetProvider() {
                    return state.headerOffsetTracker.getOffset();
                }
            });
            if (!spyReady) {
                state.headerOffsetTracker.destroy();
                state.headerOffsetTracker = null;
                return false;
            }

            state.renderer = new NavStateRenderer({
                desktopNavItemsContainer: state.desktopNavItemsContainer,
                mobileDropdownContainer: state.mobileDropdownContainer,
                mobileDropdownTitle: state.mobileDropdownTitle
            });
            state.scroller = new NavScroller({
                spy: SectionSpy,
                renderer: state.renderer,
                headerOffsetTracker: state.headerOffsetTracker
            });

            const mobileDropdownTrigger = document.querySelector('#section-dropdown-trigger');
            if (mobileDropdownRoot && mobileDropdownTrigger) {
                state.mobileDropdown = new MobileDropdownController({
                    root: mobileDropdownRoot,
                    trigger: mobileDropdownTrigger
                });
            }

            state.abortController = new AbortController();
            const { signal } = state.abortController;
            state.desktopNavItemsContainer?.addEventListener('click', handleNavClick, { signal });
            state.mobileDropdownContainer?.addEventListener('click', handleNavClick, { signal });
            window.addEventListener('hashchange', handleHashChange, { signal });

            state.navMutationObserver = new MutationObserver(handleNavMutation);
            if (state.desktopNavItemsContainer) {
                state.navMutationObserver.observe(state.desktopNavItemsContainer, { childList: true, subtree: true });
            }
            if (state.mobileDropdownContainer) {
                state.navMutationObserver.observe(state.mobileDropdownContainer, { childList: true, subtree: true });
            }

            const maskedElement = state.desktopNavContainer?.querySelector('.section-nav__scroll-container');
            if (state.desktopNavContainer && maskedElement) {
                state.scrollMaskOrchestrator = new ScrollMaskOrchestrator(state.desktopNavContainer, maskedElement);
            }

            // El hash puede apuntar a una sección aún no renderizada: queda
            // diferido y se agota en el primer evento del spy que la incluya.
            state.pendingHash = (location.hash && location.hash.length > 1) ? location.hash : null;
            state.unsubscribeSpy = SectionSpy.subscribe(handleSpyEvent);
            state.isInitialized = true;
            return true;
        },

        /** Fuerza re-medición del spy y re-resolución de nodos del nav. */
        refresh() {
            if (!state.isInitialized) return false;
            SectionSpy.invalidate();
            state.renderer.rehydrate();
            return true;
        },

        /** Marca una sección como activa en el nav (sin scroll). */
        setActive(sectionId) {
            if (!state.isInitialized) return false;
            const section = document.getElementById(sectionId);
            if (!section) return false;
            state.renderer.commit(section);
            return true;
        },

        /** Libera listeners, observers, timers y submódulos. */
        destroy() {
            if (state.unsubscribeSpy) {
                state.unsubscribeSpy();
                state.unsubscribeSpy = null;
            }
            if (state.abortController) {
                state.abortController.abort();
                state.abortController = null;
            }
            if (state.navMutationObserver) {
                state.navMutationObserver.disconnect();
                state.navMutationObserver = null;
            }
            clearTimeout(state.navMutationDebounceTimer);
            state.navMutationDebounceTimer = null;
            state.scroller?.destroy();
            state.scroller = null;
            state.mobileDropdown?.destroy();
            state.mobileDropdown = null;
            state.renderer?.destroy();
            state.renderer = null;
            state.scrollMaskOrchestrator?.destroy();
            state.scrollMaskOrchestrator = null;
            state.headerOffsetTracker?.destroy();
            state.headerOffsetTracker = null;
            state.pendingHash = null;
            state.isInitialized = false;
        },

        getState() {
            return {
                isInitialized: state.isInitialized,
                sectionsCount: SectionSpy.getSections().length,
                currentActiveId: state.renderer ? state.renderer.activeId : null,
                isScrolling: !!state.scroller?.operation,
                cachedHeaderOffset: state.headerOffsetTracker ? state.headerOffsetTracker.getOffset() : 0
            };
        }
    };
})();

// Autoinit: se activa al cargar dentro de la tienda o al entrar a ella
// después (cambio de data-view), no solo en la carga inicial.
if (typeof document !== 'undefined') {
    const initWhenInStore = () => {
        if (document.body?.getAttribute('data-view') === 'viaregalo') {
            SectionNav.init();
        }
    };
    const start = () => {
        initWhenInStore();
        new MutationObserver(initWhenInStore).observe(document.body, {
            attributes: true,
            attributeFilter: ['data-view']
        });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = SectionNav;
}

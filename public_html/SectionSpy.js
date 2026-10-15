/**
 * SectionSpy — motor único de detección de sección activa durante el scroll.
 *
 * Particiona el eje vertical del documento en regiones contiguas usando como
 * fronteras el punto medio del hueco entre cada par de secciones consecutivas:
 * por construcción no existen zonas muertas (todo punto del documento
 * pertenece exactamente a una sección). El punto focal del viewport
 * (centro visual compensado por el header fijo) se resuelve contra esas
 * fronteras con búsqueda binaria sobre geometría CACHEADA — cero lecturas de
 * layout en el hot path de scroll. La conmutación es EXACTA: el fondo y el
 * nav cambian en la frontera misma, en el mismo punto subiendo y bajando,
 * sin banda muerta alguna. El anti-parpadeo es estructural (capas de fondo
 * deduplicadas por asset y evaluación cuantizada a rAF), no una histéresis.
 *
 * La geometría solo se re-mide en invalidaciones (mutaciones del contenedor,
 * resize de ventana/secciones o `invalidate()` explícito), batcheadas en un
 * único requestAnimationFrame.
 *
 * Los consumidores (p. ej. SectionNav) se registran con `subscribe()` y
 * reciben `{ id, element, index, previousId }` en cada cambio de sección.
 * `suspend()`/`resume()` (con contador reentrante) congelan las emisiones
 * durante el scroll programático del nav.
 */
const SectionSpy = (() => {
    'use strict';

    const config = {
        containerSelector: '#viaregalo-content',
        sectionSelector: 'div[id^="section-"]',
        hysteresisPx: 0
    };

    // ---- Lógica pura (testeable sin DOM) ---------------------------------

    /**
     * Fronteras entre secciones consecutivas: el punto medio entre el fin de
     * una y el inicio de la siguiente (coordenadas de documento).
     * N geometrías → N-1 fronteras.
     */
    function computeBoundaries(geometries) {
        const boundaries = [];
        for (let i = 0; i < geometries.length - 1; i++) {
            boundaries.push((geometries[i].bottom + geometries[i + 1].top) / 2);
        }
        return boundaries;
    }

    /**
     * Resuelve la región activa para un punto focal mediante búsqueda binaria
     * sobre las fronteras. Si el candidato es adyacente al índice actual y el
     * focal está a menos de `hysteresis` px de la frontera compartida, se
     * conserva el índice actual (evita parpadeo en el borde exacto).
     */
    function resolveActiveIndex(boundaries, focalY, currentIndex, hysteresis) {
        const count = boundaries.length + 1;
        if (count <= 0) return -1;

        let lo = 0;
        let hi = boundaries.length;
        while (lo < hi) {
            const mid = (lo + hi) >> 1;
            if (focalY < boundaries[mid]) hi = mid;
            else lo = mid + 1;
        }
        const rawIndex = lo;

        if (
            typeof currentIndex === 'number'
            && currentIndex >= 0
            && currentIndex < count
            && Math.abs(rawIndex - currentIndex) === 1
        ) {
            const sharedBoundary = boundaries[Math.min(rawIndex, currentIndex)];
            if (Math.abs(focalY - sharedBoundary) < (hysteresis || 0)) {
                return currentIndex;
            }
        }

        return rawIndex;
    }

    // ---- Estado -----------------------------------------------------------

    let container = null;
    let headerOffsetProvider = null;
    let sections = [];
    let boundaries = [];
    let activeIndex = -1;
    let activeId = null;
    let suspendCount = 0;
    const subscribers = new Set();
    let initialized = false;
    let scrollScheduled = false;
    let scrollRaf = null;
    let invalidateRaf = null;
    let mutationObserver = null;
    let resizeObserver = null;
    let abortController = null;

    // ---- Núcleo -----------------------------------------------------------

    function headerOffset() {
        if (typeof headerOffsetProvider !== 'function') return 0;
        const value = headerOffsetProvider();
        return Number.isFinite(value) ? value : 0;
    }

    function notify(previousId) {
        const event = {
            id: activeId,
            element: activeIndex < 0 ? null : sections[activeIndex],
            index: activeIndex,
            previousId
        };
        for (const subscriber of subscribers) {
            try {
                subscriber(event);
            } catch (_) { }
        }
    }

    function commit(index) {
        const id = index < 0 ? null : (sections[index]?.id || null);
        if (id === activeId && index === activeIndex) return;
        const previousId = activeId;
        activeIndex = index;
        activeId = id;
        notify(previousId);
    }

    /** Focal: centro del viewport desplazado media altura de header hacia abajo. */
    function evaluate() {
        if (suspendCount > 0) return;
        if (sections.length === 0) {
            commit(-1);
            return;
        }
        const focalY = window.scrollY + window.innerHeight / 2 + headerOffset() / 2;
        commit(resolveActiveIndex(boundaries, focalY, activeIndex, config.hysteresisPx));
    }

    /** Re-mide geometrías (única fase con lecturas de layout). */
    function measure() {
        const scrollY = window.scrollY;
        const candidates = container
            ? Array.from(container.querySelectorAll(config.sectionSelector))
            : [];
        sections = [];
        const geometries = [];
        for (const element of candidates) {
            if (!element.id) continue;
            // Excluye elementos ocultos (display:none no participa en la partición).
            if (element.offsetParent === null && getComputedStyle(element).position !== 'fixed') continue;
            const rect = element.getBoundingClientRect();
            if (rect.height === 0) continue;
            sections.push(element);
            geometries.push({ top: rect.top + scrollY, bottom: rect.bottom + scrollY });
        }
        boundaries = computeBoundaries(geometries);
        activeIndex = activeId ? sections.findIndex(s => s.id === activeId) : -1;
        if (resizeObserver) {
            resizeObserver.disconnect();
            if (container) resizeObserver.observe(container);
            for (const element of sections) resizeObserver.observe(element);
        }
        evaluate();
    }

    function scheduleInvalidate() {
        if (invalidateRaf !== null) return;
        invalidateRaf = requestAnimationFrame(() => {
            invalidateRaf = null;
            measure();
        });
    }

    function scheduleEvaluate() {
        if (scrollScheduled) return;
        scrollScheduled = true;
        if (scrollRaf === null) {
            scrollRaf = requestAnimationFrame(() => {
                scrollRaf = null;
                scrollScheduled = false;
                evaluate();
            });
        }
    }

    // ---- API pública ------------------------------------------------------

    return {
        /** Idempotente: llamadas repetidas solo actualizan el offset provider. */
        init(options = {}) {
            if (initialized) {
                if (options.headerOffsetProvider) headerOffsetProvider = options.headerOffsetProvider;
                return true;
            }
            container = document.querySelector(options.containerSelector || config.containerSelector);
            if (options.sectionSelector) config.sectionSelector = options.sectionSelector;
            if (options.headerOffsetProvider) headerOffsetProvider = options.headerOffsetProvider;
            if (!container) return false;

            abortController = new AbortController();
            const { signal } = abortController;
            window.addEventListener('scroll', scheduleEvaluate, { passive: true, signal });
            window.addEventListener('resize', scheduleInvalidate, { passive: true, signal });
            mutationObserver = new MutationObserver(scheduleInvalidate);
            mutationObserver.observe(container, { childList: true });
            if (window.ResizeObserver) {
                resizeObserver = new ResizeObserver(scheduleInvalidate);
            }
            initialized = true;
            measure();
            return true;
        },

        /** Registra un consumidor; devuelve la función de desuscripción. */
        subscribe(subscriber) {
            if (typeof subscriber !== 'function') return () => { };
            subscribers.add(subscriber);
            if (activeId !== null) {
                try {
                    subscriber({
                        id: activeId,
                        element: sections[activeIndex] || null,
                        index: activeIndex,
                        previousId: null
                    });
                } catch (_) { }
            }
            return () => subscribers.delete(subscriber);
        },

        getActiveSection() {
            return {
                id: activeId,
                element: activeIndex < 0 ? null : sections[activeIndex] || null,
                index: activeIndex
            };
        },

        getSections() {
            return sections.slice();
        },

        /** Congela emisiones (contador reentrante) durante scroll programático. */
        suspend() {
            suspendCount++;
        },

        resume() {
            suspendCount = Math.max(0, suspendCount - 1);
            if (suspendCount === 0) scheduleInvalidate();
        },

        /** Re-mide geometrías en el próximo frame (batcheado). */
        invalidate() {
            scheduleInvalidate();
        },

        setHeaderOffsetProvider(provider) {
            headerOffsetProvider = provider;
        },

        destroy() {
            if (abortController) {
                abortController.abort();
                abortController = null;
            }
            if (mutationObserver) {
                mutationObserver.disconnect();
                mutationObserver = null;
            }
            if (resizeObserver) {
                resizeObserver.disconnect();
                resizeObserver = null;
            }
            if (scrollRaf !== null) {
                cancelAnimationFrame(scrollRaf);
                scrollRaf = null;
            }
            if (invalidateRaf !== null) {
                cancelAnimationFrame(invalidateRaf);
                invalidateRaf = null;
            }
            subscribers.clear();
            sections = [];
            boundaries = [];
            activeIndex = -1;
            activeId = null;
            suspendCount = 0;
            scrollScheduled = false;
            container = null;
            initialized = false;
        },

        /** Funciones puras expuestas para tests unitarios. */
        _pure: { computeBoundaries, resolveActiveIndex }
    };
})();

if (typeof module !== 'undefined' && module.exports) {
    module.exports = SectionSpy;
}

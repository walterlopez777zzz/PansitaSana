/**
 * ShopCarousel — carrusel "Lo más vendido", réplica del slider oficial de
 * fortnite.com/item-shop (keen-slider v6, `mode: "free-snap"`, extraído de su
 * bundle, chunk DK6n1uYE.js):
 *
 *     { arrows: true, loop: false, mode: 'free-snap', slides: { perView: 'auto' } }
 *
 * Física sobre una posición lógica propia proyectada a `scrollLeft` (dentro de
 * límites) + `transform` (overscroll elástico), sin scroll-snap CSS (el snap
 * nativo pelearía con la animación programada):
 *
 *  - Arrastre 1:1 desde el primer píxel, para ratón y táctil (con chequeo de
 *    dirección en el primer movimiento táctil, como el oficial).
 *  - Al soltar con velocidad: inercia por fricción (dist = v²/f, f = 1.47e-7 +
 *    |v|/1000), destino redondeado al snap-point más cercano, easeOutCubic.
 *  - Al soltar sin velocidad: snap de 500 ms con easeOutQuint.
 *  - Rubberband en los extremos: resistencia cuadrática durante el arrastre y
 *    rebote elástico (overshoot + retorno de 500 ms) al llegar con velocidad.
 *  - Flechas: ±1 índice canónico, 250 ms, easeInOutExpo (defaultAnimation).
 *
 * Arquitectura: composición de módulos internos desacoplados orquestados por
 * `CarouselController`:
 *
 *     CarouselController (orquestador, ciclo de vida)
 *     ├── GeometryModel      — modelo canónico de snap-points, cacheado e
 *     │                        invalidado por Resize/MutationObserver
 *     ├── PositionEngine     — posición lógica float + proyección al DOM
 *     ├── Animator           — cola de fases rAF
 *     ├── Physics / Snap     — funciones puras (fricción, rubberband, dedupe)
 *     ├── GestureController  — máquina de estados PENDING→TRACKING→DRAGGING
 *     ├── ArrowsController   — paginación por índice + disabled derivado
 *     └── A11yController     — región ARIA, focusabilidad por IO, teclado
 *
 * El modelo de snap-points es canónico (estrictamente creciente, deduplicado
 * y con el último punto SIEMPRE exactamente en maxScroll): la paginación es
 * aritmética de índices y no puede fabricar pasos residuales subpíxel.
 */
const ShopCarousel = (() => {
    'use strict';

    // ── Constantes ──────────────────────────────────────────────────────

    /** Animación de flechas: 250 ms con easeInOutExpo (keen defaultAnimation). */
    const ARROW_DURATION_MS = 250;
    /** Snap al soltar sin velocidad: 500 ms con easeOutQuint. */
    const IDLE_SNAP_DURATION_MS = 500;
    /** Retorno del rubberband al límite: 500 ms con easeOutCubic. */
    const RUBBERBAND_RETURN_MS = 500;
    /**
     * Distancia acumulada (px) a partir de la cual el gesto se confirma como
     * arrastre: se captura el puntero, se aplica is-dragging y se suprime el
     * click (por debajo, el gesto sigue siendo un click de tarjeta).
     */
    const DRAG_CONFIRM_THRESHOLD_PX = 5;
    /** Ventana (ms) de muestras usada para estimar la velocidad al soltar. */
    const VELOCITY_WINDOW_MS = 200;
    /** Máximo de muestras retenidas para la velocidad. */
    const VELOCITY_MAX_SAMPLES = 6;
    /** Fricción base de la inercia free-snap (unidades relativas/ms²). */
    const FRICTION_BASE = 1.47e-7;
    /** Divisor L de la fricción en inercia libre (keen: f = base + |v|/L). */
    const FREE_FRICTION_L = 1000;
    /** Divisor L de la fricción en el overshoot del rubberband (frena antes). */
    const OVERSHOOT_FRICTION_L = 100;
    /**
     * Tolerancia subpíxel compartida (px). Las métricas del navegador
     * (scrollWidth, clientWidth, offsetLeft) llegan redondeadas a entero
     * sobre un layout de anchos fraccionales, así que dos medidas del mismo
     * punto pueden diferir hasta ~1px; toda comparación de frontera o de
     * "distancia nula" usa esta única constante.
     */
    const EPSILON_PX = 1;
    /**
     * Fracción del paso natural entre snap-points por debajo de la cual el
     * residuo de frontera se fusiona con maxScroll en vez de coexistir como
     * snap-point degenerado (media tarjeta: menos que eso no es una página).
     */
    const BOUNDARY_MERGE_RATIO = 0.5;
    /** Elementos que quedan fuera del orden de foco al salir del viewport. */
    const FOCUSABLE_SELECTOR = 'a[href], button, input, select, textarea, [tabindex]';

    /**
     * Fases del gesto de puntero.
     * PENDING: pointerdown recibido; sin movimiento aún.
     * TRACKING: el track sigue 1:1 al puntero, pero el gesto aún cuenta como
     * click (sin captura, sin is-dragging, sin supresión).
     * DRAGGING: arrastre confirmado (>5 px acumulados) con todos sus efectos.
     * @enum {string}
     */
    const GesturePhase = Object.freeze({
        PENDING: 'pending',
        TRACKING: 'tracking',
        DRAGGING: 'dragging',
    });

    // ── Easings (puras) ─────────────────────────────────────────────────

    const easeInOutExpo = (t) =>
        t === 0 ? 0
            : t === 1 ? 1
                : t < 0.5 ? Math.pow(2, 20 * t - 10) / 2
                    : (2 - Math.pow(2, -20 * t + 10)) / 2;
    const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);
    const easeOutQuint = (t) => 1 + Math.pow(t - 1, 5);

    const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

    // ── Physics: física free-snap de keen como funciones puras ──────────

    const Physics = Object.freeze({
        /**
         * Proyección de frenado libre de keen: con velocidad `v` (unidades
         * relativas al viewport del slider, por ms) y fricción
         * `f = base + |v|/L`, la distancia recorrida es `v²/f` y la duración
         * `2·|v|/f`.
         *
         * @param {number} v - Velocidad relativa (fracción de viewport/ms).
         * @param {number} frictionL - Divisor L de la fricción.
         * @returns {{distance: number, duration: number}} distancia relativa
         *   con signo y duración en ms.
         */
        project(v, frictionL) {
            if (v === 0) return { distance: 0, duration: 0 };
            const friction = FRICTION_BASE + Math.abs(v) / frictionL;
            return {
                distance: (Math.sign(v) * (v * v)) / friction,
                duration: (Math.abs(v) / friction) * 2,
            };
        },

        /**
         * Resistencia cuadrática en los extremos (keen rubberband): el delta
         * hacia fuera se atenúa hasta ser 0 cuando el overscroll alcanza la
         * mitad de `windowWidth`. Hacia dentro pasa íntegro.
         *
         * @param {number} delta - Desplazamiento crudo del puntero (px).
         * @param {number} overscroll - Distancia actual fuera de límites (px, ≥0).
         * @param {number} windowWidth - Ancho de la ventana (px).
         * @returns {number} Delta atenuado a aplicar.
         */
        rubberband(delta, overscroll, windowWidth) {
            if (overscroll <= 0) return delta;
            const resistance = Math.max(0, 1 - (overscroll / windowWidth) * 2);
            return resistance * resistance * delta;
        },

        /**
         * Inversa de easeOutCubic: instante normalizado `t` en que la curva
         * alcanza la fracción `ratio` del recorrido. Usada para recortar la
         * inercia exactamente al cruzar un límite (earlyExit).
         */
        inverseEaseOutCubic(ratio) {
            return 1 - Math.cbrt(1 - clamp(ratio, 0, 1));
        },

        /**
         * Velocidad (px/ms) al soltar — semántica de keen `track.velocity()`:
         * acumula Δdistancia/Δtiempo de las muestras de los últimos
         * `VELOCITY_WINDOW_MS`, reseteando el acumulado cuando una muestra
         * invierte el signo respecto a lo acumulado.
         *
         * @param {Array<{time: number, delta: number}>} samples
         * @param {number} now - Timestamp actual (performance.now()).
         */
        exitVelocity(samples, now) {
            let distance = 0;
            let time = 0;
            let lastTimestamp = 0;
            for (const sample of samples) {
                if (now - sample.time > VELOCITY_WINDOW_MS) continue;
                if (distance && Math.sign(sample.delta) !== Math.sign(distance)) {
                    distance = 0;
                    time = 0;
                    lastTimestamp = 0;
                }
                distance += sample.delta;
                if (lastTimestamp) time += sample.time - lastTimestamp;
                lastTimestamp = sample.time;
            }
            return time ? distance / time : 0;
        },
    });

    // ── Snap: modelo canónico de snap-points (funciones puras) ──────────

    const Snap = Object.freeze({
        /**
         * Lista canónica de snap-points: estrictamente creciente, deduplicada
         * con `EPSILON_PX`, que empieza exactamente en 0 y termina exactamente
         * en `maxScroll`. Si el residuo entre el último punto natural
         * alcanzable y `maxScroll` es menor que `BOUNDARY_MERGE_RATIO` del
         * paso natural, el punto natural SE REEMPLAZA por `maxScroll` (no
         * coexisten): por construcción nunca existe un snap-point degenerado
         * a distancia subpíxel/residual del tope.
         *
         * @param {number[]} rawOffsets - `offsetLeft − paddingLeft` por slide,
         *   en orden de documento (pueden ser enteros redondeados).
         * @param {number} maxScroll - Tope físico (`scrollWidth − clientWidth`).
         * @returns {number[]} Snap-points canónicos (siempre ≥ 1 elemento).
         */
        canonicalPoints(rawOffsets, maxScroll) {
            const max = Math.max(0, maxScroll);
            if (max <= EPSILON_PX) return [0];

            const points = [0];
            for (const raw of rawOffsets) {
                const point = clamp(raw, 0, max);
                // A partir de aquí todos los offsets clampean a max: la
                // frontera se resuelve una única vez más abajo.
                if (max - point <= EPSILON_PX) break;
                if (point - points[points.length - 1] > EPSILON_PX) points.push(point);
            }

            const naturalStep = points.length > 1 ? points[1] - points[0] : max;
            const mergeWindow = Math.max(naturalStep * BOUNDARY_MERGE_RATIO, EPSILON_PX);
            if (points.length > 1
                && max - points[points.length - 1] < mergeWindow) {
                points[points.length - 1] = max;
            } else {
                points.push(max);
            }
            return points;
        },

        /**
         * Índice del snap-point más cercano a `position` (búsqueda binaria
         * sobre la lista canónica creciente).
         *
         * @param {number[]} points - Snap-points canónicos.
         * @param {number} position - Posición lógica (px).
         * @returns {number} Índice en `[0, points.length − 1]`.
         */
        nearestIndex(points, position) {
            let low = 0;
            let high = points.length - 1;
            while (low < high) {
                const mid = (low + high) >> 1;
                if (points[mid] < position) low = mid + 1;
                else high = mid;
            }
            if (low > 0
                && position - points[low - 1] <= points[low] - position) {
                return low - 1;
            }
            return low;
        },
    });

    // ── Preferencia de movimiento reducido (matchMedia cacheado) ────────

    /** @type {?MediaQueryList} */
    let reducedMotionQuery = null;
    const prefersReducedMotion = () => {
        if (!reducedMotionQuery) {
            reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
        }
        return reducedMotionQuery.matches;
    };

    // ── Ancho de tile (única fuente de verdad con las secciones grid) ───

    /**
     * Resuelve a píxeles una longitud CSS (p. ej. `var(--grid-min)`, un
     * `clamp(...)` o `28vw`) midiéndola con un elemento sonda invisible que
     * hereda las variables y el contexto de viewport de `refEl`. Evita
     * duplicar en JS los valores de los tokens del CSS.
     */
    function resolveCssLength(refEl, value) {
        const probe = document.createElement('div');
        probe.style.cssText =
            `position:absolute;visibility:hidden;height:0;pointer-events:none;width:${value};`;
        refEl.appendChild(probe);
        const px = probe.getBoundingClientRect().width;
        probe.remove();
        return px;
    }

    /**
     * Ancho REAL de 1 tile en una sección grid normal, calculado desde el
     * ancho de `refEl` (que ocupa el mismo ancho que una sección) y los
     * tokens del grid.
     *
     * El grid normal usa `repeat(auto-fit, minmax(--grid-min, 1fr))`, así que
     * ese ancho es `(W − (N−1)·gap) / N`, con N = nº de columnas que caben =
     * `floor((W + gap) / (--grid-min + gap))`. No es calculable en CSS (no
     * hay `floor()`), por eso se computa aquí. Devuelve `null` si aún no hay
     * layout.
     */
    function computeOneTileWidth(refEl) {
        if (!refEl) return null;
        const styles = getComputedStyle(refEl);
        const contentWidth = refEl.clientWidth
            - parseFloat(styles.paddingLeft || '0')
            - parseFloat(styles.paddingRight || '0');
        if (!(contentWidth > 0)) return null;

        const gridMin = resolveCssLength(refEl, 'var(--grid-min)');
        const gridGap = resolveCssLength(refEl, 'var(--grid-gap)');
        if (!(gridMin > 0)) return null;

        const columns = Math.max(1, Math.floor((contentWidth + gridGap) / (gridMin + gridGap)));
        return (contentWidth - (columns - 1) * gridGap) / columns;
    }

    /**
     * Publica `--carousel-tile-width` (px) en un carrusel para que sus
     * tarjetas tengan el mismo ancho que 1 tile de una sección normal. El
     * ancho se mide en `refEl` (por defecto el propio carrusel); pasar la
     * sección contenedora YA presente en el DOM permite fijarlo ANTES de
     * pintar el carrusel → sin parpadeo.
     */
    function applyTileWidth(carouselEl, refEl) {
        if (!carouselEl) return;
        const width = computeOneTileWidth(refEl || carouselEl);
        if (width != null && width > 0) {
            carouselEl.style.setProperty('--carousel-tile-width', `${width}px`);
        }
    }

    // ── GeometryModel ────────────────────────────────────────────────────

    /**
     * Única fuente de verdad geométrica del carrusel: mide el DOM una sola
     * vez por invalidación y expone el modelo canónico de snap-points del que
     * derivan paginación, límites y estado de flechas.
     *
     * Invalidación por `ResizeObserver` (track + primer slide: cubre resize
     * de ventana, cambio de `--carousel-tile-width`, carga de imágenes y
     * zoom) y `MutationObserver` `childList` (tarjetas añadidas/quitadas),
     * batcheada a un único rAF.
     */
    class GeometryModel {
        /**
         * @param {HTMLElement} track - La .shop-carousel__track desplazable.
         * @param {() => void} onInvalidate - Notificado (post re-medición) una
         *   vez por frame cuando la geometría cambió.
         */
        constructor(track, onInvalidate) {
            this.track = track;
            this.onInvalidate = onInvalidate;
            /** @type {number[]} */
            this.snapPoints = [0];
            this.maxScroll = 0;
            this.invalidateFrame = 0;

            this.resizeObserver = new ResizeObserver(() => this.scheduleInvalidate());
            this.resizeObserver.observe(track);
            if (track.firstElementChild) this.resizeObserver.observe(track.firstElementChild);

            this.mutationObserver = new MutationObserver(() => {
                this.resizeObserver.disconnect();
                this.resizeObserver.observe(track);
                if (track.firstElementChild) this.resizeObserver.observe(track.firstElementChild);
                this.scheduleInvalidate();
            });
            this.mutationObserver.observe(track, { childList: true });

            this.measure();
        }

        scheduleInvalidate() {
            if (this.invalidateFrame) return;
            this.invalidateFrame = requestAnimationFrame(() => {
                this.invalidateFrame = 0;
                this.onInvalidate();
            });
        }

        /** Re-mide el DOM y reconstruye el modelo canónico. */
        measure() {
            const { track } = this;
            const paddingLeft = parseFloat(getComputedStyle(track).paddingLeft) || 0;
            this.maxScroll = Math.max(0, track.scrollWidth - track.clientWidth);
            const rawOffsets = [...track.children]
                .map((child) => child.offsetLeft - paddingLeft);
            this.snapPoints = Snap.canonicalPoints(rawOffsets, this.maxScroll);
        }

        /** Índice del snap-point canónico más cercano a `position`. */
        indexAt(position) {
            return Snap.nearestIndex(this.snapPoints, position);
        }

        /** Snap-point del índice, clampeado a `[0, length − 1]`. */
        pointAt(index) {
            return this.snapPoints[clamp(index, 0, this.snapPoints.length - 1)];
        }

        isAtStart(position) {
            return position <= EPSILON_PX;
        }

        isAtEnd(position) {
            return position >= this.maxScroll - EPSILON_PX;
        }

        destroy() {
            cancelAnimationFrame(this.invalidateFrame);
            this.invalidateFrame = 0;
            this.resizeObserver.disconnect();
            this.mutationObserver.disconnect();
        }
    }

    // ── PositionEngine ───────────────────────────────────────────────────

    /**
     * Posición lógica del carrusel en float (puede salir de
     * `[0, maxScroll]` en overscroll) y su proyección al DOM: dentro de
     * límites es `scrollLeft`; el tramo fuera de límites (rubberband) se
     * pinta con `transform`, ya que `scrollLeft` satura.
     *
     * Los cambios de posición se publican a suscriptores (patrón observer):
     * el estado de las flechas deriva SIEMPRE de esta posición lógica, nunca
     * de `scrollLeft` (entero y saturado).
     */
    class PositionEngine {
        /** @param {HTMLElement} track */
        constructor(track) {
            this.track = track;
            this.position = track.scrollLeft;
            /** @type {Set<(position: number) => void>} */
            this.subscribers = new Set();
        }

        /** @param {(position: number) => void} fn */
        subscribe(fn) {
            this.subscribers.add(fn);
            fn(this.position);
        }

        /** @param {number} maxScroll - Tope físico vigente. */
        set(position, maxScroll) {
            this.position = position;
            const inBounds = clamp(position, 0, maxScroll);
            const overscroll = position - inBounds;
            this.track.scrollLeft = inBounds;
            this.track.style.transform = overscroll
                ? `translate3d(${-overscroll}px, 0, 0)` : '';
            this.notify();
        }

        /**
         * Scroll no originado por este módulo (teclado, find-in-page…):
         * adopta `scrollLeft` como posición lógica para no pisarlo.
         */
        syncFromNative() {
            this.position = this.track.scrollLeft;
            this.notify();
        }

        notify() {
            this.subscribers.forEach((fn) => fn(this.position));
        }

        destroy() {
            this.subscribers.clear();
        }
    }

    // ── Animator ─────────────────────────────────────────────────────────

    /**
     * Animador por fases sobre rAF: cada fase es `{target, duration, easing}`
     * y se encadena a la siguiente al completarse (inercia → overshoot →
     * retorno). Con `prefers-reduced-motion` salta directo al destino final
     * clampeado.
     */
    class Animator {
        /**
         * @param {PositionEngine} engine
         * @param {GeometryModel} geometry
         */
        constructor(engine, geometry) {
            this.engine = engine;
            this.geometry = geometry;
            this.frame = 0;
            /** @type {Array<{target: number, duration: number, easing: (t: number) => number}>} */
            this.queue = [];
            /** Destino final de la animación en curso, o `null` si no hay. */
            this.finalTarget = null;
        }

        get isRunning() {
            return this.frame !== 0 || this.queue.length > 0;
        }

        /** @param {Array<{target: number, duration: number, easing: (t: number) => number}>} phases */
        animate(phases) {
            this.stop();
            if (prefersReducedMotion()) {
                const finalTarget = phases[phases.length - 1].target;
                this.engine.set(
                    clamp(finalTarget, 0, this.geometry.maxScroll),
                    this.geometry.maxScroll,
                );
                return;
            }
            this.queue = [...phases];
            this.finalTarget = phases[phases.length - 1].target;
            this.runNextPhase();
        }

        runNextPhase() {
            const phase = this.queue.shift();
            if (!phase) {
                this.frame = 0;
                this.finalTarget = null;
                return;
            }
            const from = this.engine.position;
            const delta = phase.target - from;
            if (Math.abs(delta) < EPSILON_PX / 2 || phase.duration <= 0) {
                this.engine.set(phase.target, this.geometry.maxScroll);
                this.runNextPhase();
                return;
            }
            const start = performance.now();
            const tick = (now) => {
                const progress = Math.min((now - start) / phase.duration, 1);
                this.engine.set(
                    from + delta * phase.easing(progress),
                    this.geometry.maxScroll,
                );
                if (progress < 1) {
                    this.frame = requestAnimationFrame(tick);
                } else {
                    this.runNextPhase();
                }
            };
            this.frame = requestAnimationFrame(tick);
        }

        stop() {
            if (this.frame) {
                cancelAnimationFrame(this.frame);
                this.frame = 0;
            }
            this.queue = [];
            this.finalTarget = null;
        }
    }

    // ── ArrowsController ─────────────────────────────────────────────────

    /**
     * Paginación por flechas como aritmética de índices sobre la lista
     * canónica (`pointAt(indexAt(pos) ± 1)`, semántica keen `moveToIdx`), y
     * estado `disabled` derivado de la posición lógica por suscripción (un
     * solo punto de escritura al DOM, sin trabajo si el booleano no cambia).
     */
    class ArrowsController {
        /**
         * @param {HTMLButtonElement[]} prevBtns
         * @param {HTMLButtonElement[]} nextBtns
         * @param {GeometryModel} geometry
         * @param {PositionEngine} engine
         * @param {Animator} animator
         * @param {AbortSignal} signal
         */
        constructor(prevBtns, nextBtns, geometry, engine, animator, signal) {
            this.prevBtns = prevBtns;
            this.nextBtns = nextBtns;
            this.geometry = geometry;
            this.engine = engine;
            this.animator = animator;
            /** @type {?boolean} */
            this.atStart = null;
            /** @type {?boolean} */
            this.atEnd = null;

            prevBtns.forEach((btn) =>
                btn.addEventListener('click', () => this.step(-1), { signal }));
            nextBtns.forEach((btn) =>
                btn.addEventListener('click', () => this.step(1), { signal }));
            engine.subscribe((position) => this.update(position));
        }

        /**
         * Avanza exactamente un índice canónico desde el snap-point más
         * cercano a la posición actual, clampeado a los extremos; si la
         * distancia resultante es nula, no hace nada.
         *
         * @param {-1|1} direction
         */
        step(direction) {
            const target = this.geometry.pointAt(
                this.geometry.indexAt(this.engine.position) + direction,
            );
            if (Math.abs(target - this.engine.position) <= EPSILON_PX) return;
            this.animator.animate([
                { target, duration: ARROW_DURATION_MS, easing: easeInOutExpo },
            ]);
        }

        /** Re-deriva `disabled` de la posición lógica (no de scrollLeft). */
        update(position) {
            const atStart = this.geometry.isAtStart(position);
            const atEnd = this.geometry.isAtEnd(position);
            if (atStart !== this.atStart) {
                this.atStart = atStart;
                this.prevBtns.forEach((btn) => {
                    btn.disabled = atStart;
                    btn.setAttribute('aria-disabled', String(atStart));
                });
            }
            if (atEnd !== this.atEnd) {
                this.atEnd = atEnd;
                this.nextBtns.forEach((btn) => {
                    btn.disabled = atEnd;
                    btn.setAttribute('aria-disabled', String(atEnd));
                });
            }
        }
    }

    // ── GestureController ────────────────────────────────────────────────

    /**
     * Máquina de estados del gesto de puntero (PENDING → TRACKING →
     * DRAGGING): el track sigue al puntero desde el primer píxel, pero la
     * captura del puntero, la clase `is-dragging` y la supresión del click
     * solo se activan al superar 5 px acumulados; por debajo, el click de la
     * tarjeta navega. Presionar durante una animación la congela y el drag
     * continúa desde ahí.
     */
    class GestureController {
        /**
         * @param {HTMLElement} track
         * @param {GeometryModel} geometry
         * @param {PositionEngine} engine
         * @param {Animator} animator
         * @param {(velocityPx: number) => void} onRelease - Recibe la
         *   velocidad de salida (px/ms) al terminar el gesto.
         * @param {AbortSignal} signal
         */
        constructor(track, geometry, engine, animator, onRelease, signal) {
            this.track = track;
            this.geometry = geometry;
            this.engine = engine;
            this.animator = animator;
            this.onRelease = onRelease;
            this.suppressNextClick = false;
            /** @type {?{pointerId: number, lastX: number, lastY: number, checked: boolean, aborted: boolean, travel: number, phase: string, samples: Array<{time: number, delta: number}>}} */
            this.drag = null;

            track.addEventListener('pointerdown', (e) => this.onPointerDown(e), { signal });
            // move/up/cancel se escuchan en window: antes de confirmar el
            // arrastre no hay pointer capture, así que soltar fuera del
            // track debe seguir cerrando el gesto (y con captura activa los
            // eventos llegan igualmente por burbujeo).
            window.addEventListener('pointermove', (e) => this.onPointerMove(e), { signal });
            window.addEventListener('pointerup', (e) => this.onPointerUp(e), { signal });
            window.addEventListener('pointercancel', (e) => this.onPointerCancel(e), { signal });
            track.addEventListener('dragstart', (e) => e.preventDefault(), { signal });
            track.addEventListener('click', (e) => this.onClickCapture(e), { capture: true, signal });
            // Como el oficial: la rueda no debe desplazar la página mientras
            // hay un arrastre activo.
            window.addEventListener('wheel', (e) => {
                if (this.drag && this.drag.checked && !this.drag.aborted) e.preventDefault();
            }, { passive: false, signal });
        }

        get isDragging() {
            return this.drag !== null;
        }

        onPointerDown(event) {
            if (event.pointerType === 'mouse' && event.button !== 0) return;
            // Presionar durante una animación la congela: el drag continúa
            // desde la posición actual.
            this.animator.stop();
            this.drag = {
                pointerId: event.pointerId,
                lastX: event.clientX,
                lastY: event.clientY,
                checked: event.pointerType === 'mouse',
                aborted: false,
                travel: 0,
                phase: GesturePhase.PENDING,
                samples: [],
            };
        }

        onPointerMove(event) {
            const drag = this.drag;
            if (!drag || event.pointerId !== drag.pointerId || drag.aborted) return;

            // Chequeo de dirección, solo en el PRIMER movimiento táctil:
            // si domina el eje vertical, el gesto es de la página.
            if (!drag.checked) {
                drag.checked = true;
                if (Math.abs(event.clientY - drag.lastY) > Math.abs(event.clientX - drag.lastX)) {
                    drag.aborted = true;
                    return;
                }
            }

            const rawDelta = drag.lastX - event.clientX;
            drag.lastX = event.clientX;
            if (rawDelta === 0) return;

            // Movimiento desde el primer píxel, sin umbral de arranque.
            if (drag.phase === GesturePhase.PENDING) drag.phase = GesturePhase.TRACKING;
            drag.travel += Math.abs(rawDelta);
            if (drag.phase === GesturePhase.TRACKING
                && drag.travel > DRAG_CONFIRM_THRESHOLD_PX) {
                this.confirmDrag(drag);
            }

            const position = this.engine.position;
            const maxScroll = this.geometry.maxScroll;
            const overscroll = position < 0
                ? -position
                : Math.max(position - maxScroll, 0);
            const pushingOutward = (position < 0 && rawDelta < 0)
                || (position > maxScroll && rawDelta > 0);
            const applied = pushingOutward
                ? Physics.rubberband(rawDelta, overscroll, window.innerWidth)
                : rawDelta;
            this.engine.set(position + applied, maxScroll);
            this.recordSample(drag, applied, performance.now());
        }

        /**
         * Confirma el arrastre real (tracking → dragging). Solo aquí se
         * captura el puntero (la captura redirige el pointerup fuera del
         * enlace de la tarjeta), se aplica is-dragging (cuyo CSS desactiva
         * los pointer-events descendientes) y se suprime el click. Por
         * debajo del umbral, ninguno de estos efectos ocurre y el click de
         * la tarjeta sobrevive intacto.
         */
        confirmDrag(drag) {
            drag.phase = GesturePhase.DRAGGING;
            this.suppressNextClick = true;
            this.track.setPointerCapture(drag.pointerId);
            this.track.classList.add('is-dragging');
        }

        /** @param {number} delta - Desplazamiento (px) aplicado en este move. */
        recordSample(drag, delta, now) {
            drag.samples.push({ time: now, delta });
            if (drag.samples.length > VELOCITY_MAX_SAMPLES) drag.samples.shift();
        }

        onPointerUp(event) {
            const drag = this.drag;
            if (!drag || event.pointerId !== drag.pointerId) return;
            this.drag = null;
            if (drag.aborted || drag.phase === GesturePhase.PENDING) return;

            if (drag.phase === GesturePhase.DRAGGING) {
                this.track.classList.remove('is-dragging');
            }
            this.onRelease(Physics.exitVelocity(drag.samples, performance.now()));
        }

        onPointerCancel(event) {
            const drag = this.drag;
            if (!drag || event.pointerId !== drag.pointerId) return;
            this.drag = null;
            if (drag.phase !== GesturePhase.PENDING) {
                this.track.classList.remove('is-dragging');
                this.onRelease(0);
            }
        }

        onClickCapture(event) {
            if (!this.suppressNextClick) return;
            this.suppressNextClick = false;
            event.preventDefault();
            event.stopPropagation();
        }
    }

    // ── A11yController ───────────────────────────────────────────────────

    /**
     * Accesibilidad (como el oficial, reforzada): región ARIA nombrada por el
     * título de la sección, focusables de tarjetas fuera de vista fuera del
     * orden de tabulación y del árbol AT (IntersectionObserver), y paginación
     * por teclado (←/→) con el track focable.
     */
    class A11yController {
        /**
         * @param {HTMLElement} section - La .horizontal-scroll-section.
         * @param {HTMLElement} track
         * @param {ArrowsController} arrows
         * @param {AbortSignal} signal
         */
        constructor(section, track, arrows, signal) {
            this.track = track;

            const region = section.querySelector('.shop-carousel');
            if (region) {
                region.setAttribute('role', 'region');
                region.setAttribute('aria-roledescription', 'carrusel');
                const title = section.querySelector('h2')?.textContent?.trim();
                if (title) region.setAttribute('aria-label', title);
            }

            track.setAttribute('tabindex', '0');
            track.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                    event.preventDefault();
                    arrows.step(event.key === 'ArrowRight' ? 1 : -1);
                }
            }, { signal });

            this.visibilityObserver = new IntersectionObserver(
                (entries) => entries.forEach((entry) => this.syncSlideFocusability(entry)),
                { root: track, threshold: 0.1 },
            );
            [...track.children].forEach((slide) => this.visibilityObserver.observe(slide));

            this.mutationObserver = new MutationObserver((records) => {
                records.forEach((record) => {
                    record.addedNodes.forEach((node) => {
                        if (node.nodeType === Node.ELEMENT_NODE) this.visibilityObserver.observe(node);
                    });
                    record.removedNodes.forEach((node) => {
                        if (node.nodeType === Node.ELEMENT_NODE) this.visibilityObserver.unobserve(node);
                    });
                });
            });
            this.mutationObserver.observe(track, { childList: true });
        }

        /** @param {IntersectionObserverEntry} entry */
        syncSlideFocusability({ target, isIntersecting }) {
            const focusables = target.matches(FOCUSABLE_SELECTOR)
                ? [target, ...target.querySelectorAll(FOCUSABLE_SELECTOR)]
                : [...target.querySelectorAll(FOCUSABLE_SELECTOR)];
            focusables.forEach((el) => {
                if (isIntersecting) {
                    el.removeAttribute('tabindex');
                    el.removeAttribute('aria-hidden');
                } else {
                    el.setAttribute('tabindex', '-1');
                    el.setAttribute('aria-hidden', 'true');
                }
            });
        }

        destroy() {
            this.visibilityObserver.disconnect();
            this.mutationObserver.disconnect();
        }
    }

    // ── CarouselController ───────────────────────────────────────────────

    /**
     * Orquestador: compone los módulos, es dueño del ciclo de vida (un único
     * `AbortController` para TODOS los listeners, incluidos los de `window`)
     * y resuelve la física free-snap al soltar el gesto.
     */
    class CarouselController {
        /**
         * @param {HTMLElement} section - La .horizontal-scroll-section.
         * @param {HTMLElement} track - La .shop-carousel__track desplazable.
         */
        constructor(section, track) {
            this.track = track;
            /** Referencia para el ancho de tile: el contenedor de la sección
             *  (ocupa el mismo ancho que una sección grid normal). */
            this.tileRef = section.querySelector('.section-items-container') || track;

            this.abortController = new AbortController();
            const { signal } = this.abortController;

            this.geometry = new GeometryModel(track, () => this.onGeometryInvalidated());
            this.engine = new PositionEngine(track);
            this.animator = new Animator(this.engine, this.geometry);
            this.arrows = new ArrowsController(
                [...section.querySelectorAll('.shop-carousel__arrow--prev')],
                [...section.querySelectorAll('.shop-carousel__arrow--next')],
                this.geometry,
                this.engine,
                this.animator,
                signal,
            );
            this.gesture = new GestureController(
                track,
                this.geometry,
                this.engine,
                this.animator,
                (velocityPx) => this.settle(velocityPx),
                signal,
            );
            this.a11y = new A11yController(section, track, this.arrows, signal);

            track.addEventListener('scroll', () => {
                if (!this.gesture.isDragging && !this.animator.isRunning) {
                    this.engine.syncFromNative();
                }
            }, { passive: true, signal });
        }

        /**
         * La geometría cambió (resize, tile-width, imágenes, mutaciones):
         * re-publica el ancho de tile (misma invalidación, mismo frame: ancho
         * y snap-points nunca divergen), re-clampa la posición lógica y
         * re-snapea al punto canónico re-mapeado por índice; una animación en
         * vuelo se re-dirige en vez de correr hacia un target obsoleto.
         */
        onGeometryInvalidated() {
            applyTileWidth(this.track, this.tileRef);
            this.geometry.measure();
            if (this.gesture.isDragging) return;

            const reference = this.animator.finalTarget ?? this.engine.position;
            const index = this.geometry.indexAt(
                clamp(reference, 0, this.geometry.maxScroll),
            );
            this.animator.stop();
            this.engine.set(this.geometry.pointAt(index), this.geometry.maxScroll);
        }

        /**
         * Física free-snap al soltar (keen, en unidades relativas al ancho
         * visible del slider).
         *
         * @param {number} velocityPx - Velocidad de salida en px/ms.
         */
        settle(velocityPx) {
            const size = this.track.clientWidth || 1;
            const max = this.geometry.maxScroll;
            const position = this.engine.position;
            const v = velocityPx / size;

            if (v === 0) {
                if (position < 0 || position > max) {
                    // Soltar en overscroll sin velocidad: retorno al límite.
                    this.animator.animate([{
                        target: clamp(position, 0, max),
                        duration: RUBBERBAND_RETURN_MS,
                        easing: easeOutCubic,
                    }]);
                } else {
                    // Snap suave de 500 ms al snap-point canónico más cercano.
                    this.animator.animate([{
                        target: this.geometry.pointAt(this.geometry.indexAt(position)),
                        duration: IDLE_SNAP_DURATION_MS,
                        easing: easeOutQuint,
                    }]);
                }
                return;
            }

            // Proyección de frenado libre: dist = v²/f (cuadrática en v),
            // redondeada luego al snap-point canónico más cercano.
            const free = Physics.project(v, FREE_FRICTION_L);
            const distance = free.distance * size;
            const projected = position + distance;

            if (projected >= 0 && projected <= max) {
                this.animator.animate([{
                    target: this.geometry.pointAt(this.geometry.indexAt(projected)),
                    duration: free.duration,
                    easing: easeOutCubic,
                }]);
                return;
            }

            // Destino fuera de límites: rebote rubberband. La inercia se
            // recorta al cruzar el límite (earlyExit sobre la misma curva,
            // conservando la velocidad residual v·(1−k)) y se encadenan el
            // sobreimpulso — misma física de fricción pero con L=100, que
            // frena mucho antes — y el retorno de 500 ms al borde.
            const boundary = projected < 0 ? 0 : max;
            const toBoundary = boundary - position;
            const phases = [];
            let residual = v;
            if (Math.abs(toBoundary) > EPSILON_PX / 2
                && Math.sign(toBoundary) === Math.sign(distance)) {
                const ratio = clamp(toBoundary / distance, 0, 1);
                const exit = Physics.inverseEaseOutCubic(ratio);
                phases.push({
                    target: boundary,
                    duration: free.duration * exit,
                    // Tramo inicial de la misma easeOutCubic, renormalizado.
                    easing: (t) => easeOutCubic(t * exit) / ratio,
                });
                residual = v * (1 - exit);
            }
            const overshootProjection = Physics.project(residual, OVERSHOOT_FRICTION_L);
            phases.push(
                {
                    target: boundary + overshootProjection.distance * size,
                    duration: overshootProjection.duration,
                    easing: easeOutCubic,
                },
                { target: boundary, duration: RUBBERBAND_RETURN_MS, easing: easeOutCubic },
            );
            this.animator.animate(phases);
        }

        /** Libera listeners, observers y animaciones (evita fugas de memoria). */
        destroy() {
            this.animator.stop();
            this.geometry.destroy();
            this.a11y.destroy();
            this.engine.destroy();
            this.abortController.abort();
        }
    }

    // ── Registro y API pública ───────────────────────────────────────────

    /**
     * Registro de controladores por track, sin expandos en el DOM. El
     * WeakMap por sí solo no evita fugas (los listeners de `window` retienen
     * el controlador), por eso `destroyWithin()` debe llamarse antes de
     * desechar un subárbol con carruseles; `tracked` permite enumerarlos.
     * @type {WeakMap<HTMLElement, CarouselController>}
     */
    const registry = new WeakMap();
    /** @type {Set<HTMLElement>} */
    const tracked = new Set();

    /**
     * Monta el carrusel de una sección (idempotente por track).
     * @param {HTMLElement} section - La .horizontal-scroll-section.
     */
    function attach(section) {
        const track = section.querySelector('.shop-carousel__track');
        if (!track || registry.has(track)) return;
        registry.set(track, new CarouselController(section, track));
        tracked.add(track);
    }

    /** Desmonta y libera el controlador de un track concreto. */
    function detach(track) {
        const controller = registry.get(track);
        if (!controller) return;
        controller.destroy();
        registry.delete(track);
        tracked.delete(track);
    }

    /**
     * Desmonta todos los carruseles contenidos en `root` (incluido `root`
     * mismo si es un track). DEBE llamarse antes de vaciar/desechar un
     * subárbol que contenga carruseles: sin esto, los listeners de `window`
     * del controlador viejo quedarían vivos.
     * @param {ParentNode} root
     */
    function destroyWithin(root) {
        tracked.forEach((track) => {
            if (root === track || root.contains(track)) detach(track);
        });
    }

    /** Inicializa (una sola vez por sección) todos los carruseles del DOM. */
    function init() {
        document.querySelectorAll('.horizontal-scroll-section').forEach(attach);
    }

    return {
        init,
        attach,
        destroyWithin,
        applyTileWidth,
        /** Funciones puras expuestas para tests unitarios. */
        _pure: {
            canonicalPoints: Snap.canonicalPoints,
            nearestIndex: Snap.nearestIndex,
            project: Physics.project,
            rubberband: Physics.rubberband,
            inverseEaseOutCubic: Physics.inverseEaseOutCubic,
            exitVelocity: Physics.exitVelocity,
            EPSILON_PX,
            BOUNDARY_MERGE_RATIO,
        },
    };
})();

if (typeof window !== 'undefined') {
    window.ShopCarousel = ShopCarousel;
}
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ShopCarousel;
}

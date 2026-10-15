/**
 * SectionBackdrop — fondo de sección a pantalla completa (runtime cliente).
 *
 * Proyecta detrás del contenido el arte "billboard" configurado en Admin →
 * Fondos de Secciones, replicando fortnite.com/item-shop: una capa fija por
 * imagen y crossfade de opacidad entre capas. El servidor (index.php vía
 * SectionBackdropResolver) renderiza la primera capa y entrega la
 * configuración en `#section-backdrop-config`; este módulo la adopta y sólo
 * gestiona los cambios posteriores, por lo que el primer frame nunca depende
 * de JavaScript.
 *
 * Política de resolución (espejo de SectionBackdropResolver.php):
 *   viaregalo            → fondo de la sección bajo el viewport (SectionSpy),
 *                          si no, el fondo por defecto global.
 *   item                 → fondo declarado por la vista (`setPage`), si no,
 *                          se conserva el fondo actual hasta que lo declare.
 *   viacuenta/games/producto → fondo por defecto global.
 *   cualquier otra vista → sin fondo.
 *
 * Contrato con el resto de la app:
 *   - main.js llama a `boot()` una vez y emite `banano:view-change`
 *     (`detail.view` = data-view) en cada cambio de ruta.
 *   - Las secciones de la tienda se identifican por su propio DOM:
 *     `data-layout-id` / `id` (clave) y `data-title-desktop` (nombre).
 *   - item.view.js declara su fondo con `setPage({ url, layout })` y lo
 *     retira con `clearPage()` al desmontarse.
 *
 * Garantías: la evaluación del script no toca el DOM ni ejecuta lógica (sin
 * TDZ posible, sin dependencia del orden de carga); `boot()` es idempotente;
 * `destroy()` libera listeners, suscripciones, temporizadores y capas;
 * ningún error interno se propaga al arranque de la app.
 */
(function (global) {
    'use strict';

    const STAGE_ID = 'section-backdrop';
    const CONFIG_ID = 'section-backdrop-config';
    const LAYER_CLASS = 'section-backdrop__layer';
    const ACTIVE_CLASS = 'is-active';
    const FADE_VAR = '--section-backdrop-fade';
    const FADE_FALLBACK_MS = 320;
    const PRUNE_SLACK_MS = 50;
    const MAX_LAYERS = 3;
    const VIEW_CHANGE_EVENT = 'banano:view-change';

    const VIEW_STORE = 'viaregalo';
    const VIEW_ITEM = 'item';
    const DEFAULT_VIEWS = new Set(['viacuenta', 'games', 'producto']);

    // Espejo de SectionBackdropResolver::imageSource (PHP): candidatos
    // responsive vía wsrv.nl. La imagen cubre el viewport, por lo que en
    // vertical hace falta ~2x el ancho CSS para mantener nitidez.
    const IMAGE_WIDTHS = [640, 1080, 1920, 2560];
    const IMAGE_DEFAULT_WIDTH = 1920;
    const IMAGE_QUALITY = 90;
    const IMAGE_SIZES = '(orientation: portrait) 200vw, 100vw';

    const MODE = Object.freeze({
        STORE: 'store',
        PAGE: 'page',
        DEFAULT: 'default',
        NONE: 'none'
    });

    // ---- Resolución (funciones puras) ------------------------------------

    /** Misma normalización que SectionBackgroundsRepository::normalizeKey. */
    function normalizeKey(value) {
        return String(value ?? '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function asUrl(value) {
        return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
    }

    /**
     * Índice inmutable de fondos a partir de la configuración serializada.
     * @param {unknown} raw  {default, page, sections:[{key,name,image}]}
     */
    function createRegistry(raw) {
        const config = raw && typeof raw === 'object' ? raw : {};
        const byKey = new Map();
        const byName = new Map();
        const sections = Array.isArray(config.sections) ? config.sections : [];
        for (const bg of sections) {
            const image = bg && asUrl(bg.image);
            if (!image) continue;
            const key = normalizeKey(bg.key);
            if (key && !byKey.has(key)) byKey.set(key, image);
            const name = normalizeKey(bg.name);
            if (name && !byName.has(name)) byName.set(name, image);
        }
        return Object.freeze({
            defaultUrl: asUrl(config.default),
            pageUrl: asUrl(config.page),
            byKey,
            byName
        });
    }

    /** Fondo específico de un layout de tienda ({id, name}); null si no tiene. */
    function resolveLayout(registry, layout) {
        if (!layout || typeof layout !== 'object') return null;
        const key = normalizeKey(layout.id);
        if (key && registry.byKey.has(key)) return registry.byKey.get(key);
        const name = normalizeKey(layout.name);
        if (name && registry.byName.has(name)) return registry.byName.get(name);
        return null;
    }

    /**
     * Fondo específico de una sección renderizada, a partir de su elemento:
     * `data-layout-id` (layout.id real), su `id` (clave de las secciones
     * virtuales, p. ej. `section-random-offer`) o su `id` sin el prefijo
     * `section-`; por último su nombre visible (`data-title-desktop`).
     */
    function resolveSectionElement(registry, element) {
        if (!element) return null;
        const dataset = element.dataset || {};
        const id = String(element.id || '');
        const keys = [dataset.layoutId, id, id.replace(/^section-/, '')];
        for (const candidate of keys) {
            const key = normalizeKey(candidate);
            if (key && registry.byKey.has(key)) return registry.byKey.get(key);
        }
        const name = normalizeKey(dataset.titleDesktop);
        return name && registry.byName.has(name) ? registry.byName.get(name) : null;
    }

    /**
     * Intención visual para un estado dado. `hold` indica que no hay
     * información suficiente y debe conservarse el fondo actual.
     * @param {{view:?string, section:?object, page:?{url:?string, layout:?object}}} state
     * @returns {{mode:string, url:?string, hold:boolean}}
     */
    function resolveIntent(registry, state) {
        const view = state.view;
        if (view === VIEW_STORE) {
            const url = resolveSectionElement(registry, state.section) ?? registry.defaultUrl;
            return { mode: MODE.STORE, url, hold: false };
        }
        if (view === VIEW_ITEM) {
            if (!state.page) return { mode: MODE.PAGE, url: null, hold: true };
            const url = state.page.url
                ?? resolveLayout(registry, state.page.layout)
                ?? registry.defaultUrl;
            return { mode: MODE.PAGE, url, hold: false };
        }
        if (DEFAULT_VIEWS.has(view)) {
            return { mode: MODE.DEFAULT, url: registry.defaultUrl, hold: false };
        }
        return { mode: MODE.NONE, url: null, hold: false };
    }

    function optimizedUrl(url, width) {
        return 'https://wsrv.nl/?url=' + encodeURIComponent(url)
            + '&w=' + width + '&q=' + IMAGE_QUALITY + '&output=webp';
    }

    /** Atributos `src`/`srcset`/`sizes` para un fondo (srcset vacío si no aplica). */
    function imageSource(url) {
        if (!/^https?:\/\//i.test(url) || url.includes('wsrv.nl') || url.includes('via.placeholder.com')) {
            return { src: url, srcset: '', sizes: '' };
        }
        return {
            src: optimizedUrl(url, IMAGE_DEFAULT_WIDTH),
            srcset: IMAGE_WIDTHS.map((w) => optimizedUrl(url, w) + ' ' + w + 'w').join(', '),
            sizes: IMAGE_SIZES
        };
    }

    function parseCssTime(value) {
        const text = String(value ?? '').trim();
        if (text === '') return NaN;
        const number = parseFloat(text);
        if (!Number.isFinite(number)) return NaN;
        return /ms$/i.test(text) ? number : number * 1000;
    }

    // ---- Escenario DOM ----------------------------------------------------

    /**
     * Gestiona el contenedor `#section-backdrop` y su pool de capas (LRU,
     * máximo MAX_LAYERS). Una capa sólo se activa cuando su imagen está
     * decodificada; el fallo de carga se delega al controlador.
     */
    function createStage(root, onImageError) {
        let element = root.getElementById(STAGE_ID);
        if (!element) {
            element = root.createElement('div');
            element.id = STAGE_ID;
            element.setAttribute('aria-hidden', 'true');
            element.dataset.mode = MODE.NONE;
            root.body.prepend(element);
        }

        /** @type {Map<string, HTMLElement>} url -> capa, orden = LRU (última = más reciente) */
        const layers = new Map();
        let activeUrl = null;
        let pendingUrl = null;
        let generation = 0;
        let pruneTimer = null;

        for (const layer of element.querySelectorAll('.' + LAYER_CLASS)) {
            const url = asUrl(layer.dataset.url);
            if (!url || layers.has(url)) {
                layer.remove();
                continue;
            }
            layers.set(url, layer);
            if (layer.classList.contains(ACTIVE_CLASS)) activeUrl = url;
        }

        function createLayer(url) {
            const layer = root.createElement('div');
            layer.className = LAYER_CLASS;
            layer.dataset.url = url;
            const source = imageSource(url);
            const img = root.createElement('img');
            img.alt = '';
            img.decoding = 'async';
            if (source.srcset) {
                img.sizes = source.sizes;
                img.srcset = source.srcset;
            }
            img.src = source.src;
            layer.appendChild(img);
            element.appendChild(layer);
            // Fija el estado inicial (opacity: 0) antes de una posible
            // activación en el mismo frame; sin esto no habría crossfade.
            void computedStyle(layer)?.getPropertyValue('opacity');
            layers.set(url, layer);
            return layer;
        }

        function computedStyle(node) {
            const view = root.defaultView;
            return view && typeof view.getComputedStyle === 'function' ? view.getComputedStyle(node) : null;
        }

        function fadeMs() {
            const parsed = parseCssTime(computedStyle(element)?.getPropertyValue(FADE_VAR));
            return Number.isFinite(parsed) ? parsed : FADE_FALLBACK_MS;
        }

        function prune() {
            pruneTimer = null;
            for (const [url, layer] of layers) {
                if (layers.size <= MAX_LAYERS) break;
                if (url === activeUrl || url === pendingUrl) continue;
                layer.remove();
                layers.delete(url);
            }
        }

        function schedulePrune() {
            if (pruneTimer !== null || layers.size <= MAX_LAYERS) return;
            pruneTimer = setTimeout(prune, fadeMs() + PRUNE_SLACK_MS);
        }

        function activate(url) {
            const target = layers.get(url);
            if (!target) return;
            layers.delete(url);
            layers.set(url, target);
            for (const layer of layers.values()) {
                layer.classList.toggle(ACTIVE_CLASS, layer === target);
            }
            activeUrl = url;
            schedulePrune();
        }

        /** Resuelve cuando la imagen está lista para pintarse sin parpadeo. */
        function whenDecoded(img) {
            if (img.complete && img.naturalWidth > 0) return Promise.resolve();
            if (typeof img.decode === 'function') return img.decode();
            return new Promise((resolve, reject) => {
                img.addEventListener('load', resolve, { once: true });
                img.addEventListener('error', reject, { once: true });
            });
        }

        return {
            get activeUrl() { return activeUrl; },

            setMode(mode) {
                if (element.dataset.mode !== mode) element.dataset.mode = mode;
            },

            show(url) {
                if (url === activeUrl && pendingUrl === null) return;
                if (url === pendingUrl) return;
                const token = ++generation;
                pendingUrl = url;
                const layer = layers.get(url) || createLayer(url);
                whenDecoded(layer.querySelector('img')).then(
                    () => {
                        if (token !== generation) return;
                        pendingUrl = null;
                        activate(url);
                    },
                    () => {
                        if (token !== generation) return;
                        pendingUrl = null;
                        layer.remove();
                        layers.delete(url);
                        onImageError(url);
                    }
                );
            },

            clear() {
                generation++;
                pendingUrl = null;
                if (activeUrl === null) return;
                for (const layer of layers.values()) layer.classList.remove(ACTIVE_CLASS);
                activeUrl = null;
                schedulePrune();
            },

            /** Cancela trabajo pendiente y retira las capas inactivas. */
            destroy() {
                generation++;
                pendingUrl = null;
                if (pruneTimer !== null) {
                    clearTimeout(pruneTimer);
                    pruneTimer = null;
                }
                for (const [url, layer] of layers) {
                    if (url === activeUrl) continue;
                    layer.remove();
                    layers.delete(url);
                }
            }
        };
    }

    // ---- Controlador ------------------------------------------------------

    function readConfig(root) {
        const node = root.getElementById(CONFIG_ID);
        if (!node) return null;
        try {
            return JSON.parse(node.textContent || 'null');
        } catch (_) {
            return null;
        }
    }

    function spyApi() {
        const spy = global.SectionSpy;
        return spy && typeof spy.subscribe === 'function' ? spy : null;
    }

    let booted = false;
    let root = null;
    let registry = null;
    let stage = null;
    let abortController = null;
    let unsubscribeSpy = null;
    const failedUrls = new Set();
    const state = { view: null, section: null, page: null };

    function apply() {
        const intent = resolveIntent(registry, state);
        if (intent.hold) {
            stage.setMode(stage.activeUrl ? intent.mode : MODE.NONE);
            return;
        }
        let url = intent.url;
        if (url && failedUrls.has(url)) {
            url = registry.defaultUrl && !failedUrls.has(registry.defaultUrl) ? registry.defaultUrl : null;
        }
        stage.setMode(url ? intent.mode : MODE.NONE);
        if (url) stage.show(url);
        else stage.clear();
    }

    function onImageError(url) {
        failedUrls.add(url);
        apply();
    }

    function currentSpySection() {
        const spy = spyApi();
        if (!spy || typeof spy.getActiveSection !== 'function') return null;
        return spy.getActiveSection().element || null;
    }

    function onViewChange(event) {
        const view = event?.detail?.view ?? root.body?.dataset.view ?? null;
        if (view === state.view) return;
        state.view = view;
        state.section = view === VIEW_STORE ? currentSpySection() : null;
        apply();
    }

    function onSpyChange(event) {
        state.section = event?.element || null;
        if (state.view === VIEW_STORE) apply();
    }

    const api = Object.freeze({
        MODE,

        /**
         * Adopta la capa renderizada en servidor y empieza a reaccionar a la
         * navegación. Idempotente; nunca lanza.
         * @param {{root?: Document}} [options]
         */
        boot(options = {}) {
            if (booted) return true;
            const doc = options.root || global.document;
            if (!doc || !doc.body) return false;
            try {
                root = doc;
                registry = createRegistry(readConfig(root));
                stage = createStage(root, onImageError);
                abortController = new AbortController();
                state.view = root.body.dataset.view || null;
                state.section = state.view === VIEW_STORE ? currentSpySection() : null;
                // Deep link a un objeto: el servidor ya resolvió su fondo.
                state.page = state.view === VIEW_ITEM ? { url: registry.pageUrl, layout: null } : null;
                root.addEventListener(VIEW_CHANGE_EVENT, onViewChange, { signal: abortController.signal });
                const spy = spyApi();
                unsubscribeSpy = spy ? spy.subscribe(onSpyChange) : null;
                booted = true;
                apply();
                return true;
            } catch (err) {
                if (global.console) console.error('[SectionBackdrop] boot failed', err);
                api.destroy();
                return false;
            }
        },

        /**
         * Fondo declarado por la página de objeto activa.
         * @param {{url?: ?string, layout?: ?{id?: string, name?: string}}|null} page
         */
        setPage(page) {
            state.page = page && typeof page === 'object'
                ? { url: asUrl(page.url), layout: page.layout || null }
                : null;
            if (booted && state.view === VIEW_ITEM) apply();
        },

        clearPage() {
            api.setPage(null);
        },

        /** Fondo específico de un layout de tienda, o null (útil a las vistas). */
        forLayout(layout) {
            return booted ? resolveLayout(registry, layout) : null;
        },

        destroy() {
            if (abortController) {
                abortController.abort();
                abortController = null;
            }
            if (typeof unsubscribeSpy === 'function') {
                try { unsubscribeSpy(); } catch (_) { /* noop */ }
            }
            unsubscribeSpy = null;
            if (stage) stage.destroy();
            stage = null;
            registry = null;
            root = null;
            state.view = null;
            state.section = null;
            state.page = null;
            failedUrls.clear();
            booted = false;
        },

        /** Funciones puras expuestas para tests unitarios. */
        _pure: Object.freeze({
            normalizeKey,
            createRegistry,
            resolveLayout,
            resolveSectionElement,
            resolveIntent,
            imageSource,
            parseCssTime
        })
    });

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        global.SectionBackdrop = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);

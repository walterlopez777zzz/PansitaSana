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

// region Small utilities
/**
 * Retry an async function with linear backoff and optional jitter/cancellation.
 * @template T
 * @param {() => Promise<T>} fn
 * @param {{ retries?: number, backoffMs?: number, jitterMs?: number, signal?: AbortSignal }} [opts]
 * @returns {Promise<T>}
 */
function retryAsync(fn, { retries = 2, backoffMs = 300, jitterMs = 60, signal } = {}) {
    return new Promise((resolve, reject) => {
        let attempt = 0;
        const run = () => {
            if (signal?.aborted) return reject(signal.reason || new DOMException('Aborted', 'AbortError'));
            Promise.resolve().then(fn).then(resolve).catch(err => {
                if (attempt++ < retries) {
                    const jitter = Math.floor(Math.random() * Math.max(0, jitterMs));
                    const delay = backoffMs * attempt + jitter;
                    const id = setTimeout(run, delay);
                    signal?.addEventListener?.('abort', () => { try { clearTimeout(id); } catch (_) { } }, { once: true });
                } else {
                    reject(err);
                }
            });
        };
        run();
    });
}
// endregion Small utilities
(() => {
    try {
        if (typeof window === 'undefined' || typeof document === 'undefined') return;
        const isProd = ['banano.pe', 'www.banano.pe'].includes(location.hostname);
        if (!isProd) return;
        const con = window.console = window.console || {};
        const noop = function () { };
        const methods = ['log', 'info', 'warn', 'error', 'debug', 'trace', 'group', 'groupCollapsed', 'groupEnd', 'table', 'time', 'timeLog', 'timeEnd', 'dir', 'dirxml', 'assert', 'profile', 'profileEnd', 'count', 'countReset', 'timeStamp', 'clear'];
        for (const m of methods) { try { con[m] = noop; } catch { } }
    } catch { }
})();

// region Logger
const APP_ENV = (typeof window !== 'undefined' && window.APP_ENV) || (location && location.hostname && location.hostname !== 'localhost' ? 'production' : 'development');
const APP_LOG_LEVEL = (typeof window !== 'undefined' && window.APP_LOG_LEVEL) || (APP_ENV === 'production' ? 'warn' : 'debug');
const LOG_LEVELS = { error: 0, warn: 1, info: 2, debug: 3 };
function shouldLog(level) { return LOG_LEVELS[level] <= LOG_LEVELS[APP_LOG_LEVEL]; }

// region Animated clock SVG factory
/** @param {string} ns  @param {string} tag  @param {Record<string,string>} attrs */
function _svgEl(ns, tag, attrs) {
    const el = document.createElementNS(ns, tag);
    for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
    return el;
}

/**
 * Creates an inline SVG clock icon with three hands (hour, minute, second)
 * animated at real-world speed and synced to the local clock.
 *
 * Durations: hour 12 h/rev · minute 60 min/rev · second 60 s/rev.
 * Sync: a negative `animation-delay` offsets each hand to the current time.
 * Accessibility: `aria-hidden` + `focusable="false"` (decorative icon).
 * Respects `prefers-reduced-motion` via CSS (animations paused).
 *
 * @param {string} [cssClass] - Optional CSS class(es) for the root <svg>.
 * @returns {SVGSVGElement}
 */
function createAnimatedClockSVG(cssClass) {
    const NS = 'http://www.w3.org/2000/svg';

    const svg = _svgEl(NS, 'svg', {
        viewBox: '0 0 24 24', fill: 'none',
        stroke: 'currentColor', 'stroke-width': '3',
        'stroke-linecap': 'round', 'stroke-linejoin': 'round',
        'aria-hidden': 'true', focusable: 'false',
    });
    if (cssClass) svg.setAttribute('class', cssClass);

    const face = _svgEl(NS, 'circle', { cx: '12', cy: '12', r: '10' });

    // Intermediate thickness: face inner edge ≈ y 3.5 (stroke-width 3 → inset 1.5).
    // Hand tips keep a visible gap from the rim while preserving the length hierarchy.
    const handBase = { x1: '12', y1: '12', x2: '12' };
    const hourHand   = _svgEl(NS, 'line', { ...handBase, y2: '7.5', class: 'clock-hand clock-hand--hour' });
    const minuteHand = _svgEl(NS, 'line', { ...handBase, y2: '5.5', class: 'clock-hand clock-hand--minute' });
    const secondHand = _svgEl(NS, 'line', { ...handBase, y2: '5',   class: 'clock-hand clock-hand--second', 'stroke-width': '1.8' });

    const dot = _svgEl(NS, 'circle', { cx: '12', cy: '12', r: '1', fill: 'currentColor', stroke: 'none' });

    // Sync each hand to the current local time via negative animation-delay.
    // Uses sub-second precision so the second hand doesn't visibly "jump" on mount.
    const now = new Date();
    const h = now.getHours() % 12;
    const m = now.getMinutes();
    const s = now.getSeconds() + now.getMilliseconds() / 1000;

    hourHand.style.animationDelay   = `-${h * 3600 + m * 60 + s}s`;
    minuteHand.style.animationDelay = `-${m * 60 + s}s`;
    secondHand.style.animationDelay = `-${s}s`;

    svg.append(face, hourHand, minuteHand, secondHand, dot);
    return svg;
}
// endregion

/* ================================================================
   MARQUEE DE TARJETA — réplica exacta del componente `marquee` de
   fortnite.com/item-shop (ver docs/fortnite-item-shop-marquee.md).
   ----------------------------------------------------------------
   El marquee oficial es un texto de una línea que, SOLO si desborda su
   contenedor y SOLO mientras la tarjeta tiene hover/focus, se desplaza
   como una cinta: pausa (0–15 %) → scroll a ~75 px/s (15–80 %) → pausa
   (80–95 %) → fade-out (95–100 %) → reinicia. Al salir el cursor vuelve
   al inicio sin transición; al volver a entrar, reinicia desde cero.

   Se usa en dos piezas de la tarjeta:
   1. La PLACA superior (violator): «¡Nuevo!», «N paVos de descuento»,
      «Termina hoy»…  → `buildCardPlateNode`.
   2. La FRANJA de tipo/etiquetas revelada en hover: «Traje y bonificación
      + Estilos seleccionables, Reactivo…»  → `buildCardTaglineNode`.

   El desplazamiento lo orquesta `CardMarqueeManager` (medición perezosa
   por tarjeta en hover, variables CSS `--marquee-end`/`--marquee-duration`
   y clase `is-scrolling`), con listeners delegados: coste cero por tarjeta.
================================================================ */

/**
 * Fórmula de duración del marquee oficial (extraída de su bundle):
 *   duration = (anchoContenido / anchoViewport) * 4 s
 * y solo anima si el desborde supera 2 px. Duración mínima real ≈ 4 s,
 * por lo que la pausa inicial del keyframe (0–15 %) es siempre ≥ 0,6 s.
 */
const MARQUEE_DURATION_FACTOR_S = 4;
/** Desborde mínimo (px) para activar el desplazamiento (umbral oficial). */
const MARQUEE_MIN_OVERFLOW_PX = 2;

/**
 * ÚNICA fuente de verdad del DOM de un marquee de tarjeta.
 * Estructura idéntica a la oficial: contenedor → viewport con overflow
 * oculto → scroller (el que se anima) → contenido en una línea.
 * @param {string} text
 * @returns {HTMLElement}
 */
function createCardMarqueeNode(text) {
    const marquee = document.createElement('div');
    marquee.className = 'fn-marquee';

    const viewport = document.createElement('div');
    viewport.className = 'fn-marquee__viewport';

    const scroller = document.createElement('div');
    scroller.className = 'fn-marquee__scroller';

    const content = document.createElement('div');
    content.className = 'fn-marquee__content';
    content.textContent = text;

    scroller.appendChild(content);
    viewport.appendChild(scroller);
    marquee.appendChild(viewport);
    return marquee;
}

/* ----------------------------------------------------------------
   PLACA SUPERIOR (violator) — reglas oficiales verificadas:
   1. Si la oferta caduca dentro de las próximas 24 h → placa negra con
      texto lima «Termina hoy». Tiene PRIORIDAD sobre el banner de la API
      (verificado: un lote con banner AmountOff muestra «Termina hoy»).
   2. Si no, con `banner` de la API:
      - intensity "High" (Nuevo, Reactivo, Estilos…) → placa amarilla, y
        el sitio oficial envuelve el texto en «¡…!».
      - intensity "Low" (descuentos AmountOff) → placa blanca, texto tal cual.
   3. Sin banner y sin caducar hoy → sin placa.
---------------------------------------------------------------- */
const CARD_PLATE_VARIANTS = Object.freeze({
    URGENT: 'urgent', // negra / texto lima
    INFO: 'info',     // amarilla / texto negro
    OFFER: 'offer',   // blanca / texto negro
});

/** ¿La oferta sale de la tienda dentro de las próximas 24 h? */
function offerEndsToday(outDateString) {
    if (!outDateString) return false;
    const diff = new Date(outDateString).getTime() - Date.now();
    return diff > 0 && diff < 86400000;
}

/**
 * Normaliza una entrada de la tienda al modelo de placa oficial.
 * @param {object} entry  Entrada (con `banner` y `outDate`).
 * @returns {{ text: string, variant: string }|null}
 */
function deriveCardPlateModel(entry) {
    if (!entry) return null;

    if (offerEndsToday(entry.outDate)) {
        return { text: 'Termina hoy', variant: CARD_PLATE_VARIANTS.URGENT };
    }

    const banner = entry.banner;
    const rawText = (banner?.value || '').trim();
    if (!rawText) return null;

    if (banner.intensity === 'High') {
        return { text: `¡${rawText}!`, variant: CARD_PLATE_VARIANTS.INFO };
    }
    return { text: rawText, variant: CARD_PLATE_VARIANTS.OFFER };
}

/**
 * Placa superior de la tarjeta (violator oficial).
 * @param {object} entry
 * @returns {HTMLElement|null}
 */
function buildCardPlateNode(entry) {
    const model = deriveCardPlateModel(entry);
    if (!model) return null;

    const plate = document.createElement('div');
    plate.className = 'card-plate';
    plate.dataset.plateVariant = model.variant;
    plate.setAttribute('aria-hidden', 'true');
    plate.appendChild(createCardMarqueeNode(model.text));
    return plate;
}

/* ----------------------------------------------------------------
   FRANJA DE TIPO/ETIQUETAS (tag-banner) — reglas oficiales verificadas:
   - Texto: «<Tipo>[ y bonificación][ + etiqueta, etiqueta…]».
     · Tipo: «Lote» si es bundle; si no, el tipo del ítem principal.
     · «y bonificación»: la oferta de un ítem suelto incluye cosméticos
       extra de regalo (p. ej. traje + mochila de bonificación).
   - Variante de color (confirmada 45/45 tarjetas): CLARA (degradado
     blanco, texto negro) si y solo si hay etiquetas tras el «+»; OSCURA
     (negro 70 %, texto blanco) si solo hay tipo.
   - Solo visible en hover/focus (revelado con transición de max-height).

   Las etiquetas oficiales salen del catálogo interno de Epic y
   fortnite-api.com NO las expone; `CARD_TAG_DERIVERS` es el registro
   extensible de las que sí pueden deducirse de los datos disponibles.
   Añadir una etiqueta nueva = añadir una entrada aquí, sin tocar nada más.
---------------------------------------------------------------- */

/**
 * Registro de derivadores de etiquetas. Orden = orden de aparición.
 * @type {ReadonlyArray<{ label: string, test: (ctx: {entry: object, cosmetics: object[]}) => boolean }>}
 */
const CARD_TAG_DERIVERS = Object.freeze([
    {
        label: 'Estilos seleccionables',
        test: ({ cosmetics }) => cosmetics.some((item) => Array.isArray(item.variants) && item.variants.length > 0),
    },
    {
        label: 'Incorporado',
        test: ({ cosmetics }) => cosmetics.some((item) => Array.isArray(item.builtInEmoteIds) && item.builtInEmoteIds.length > 0),
    },
    {
        label: 'Reactivo',
        test: ({ entry }) => entry.banner?.backendValue === 'Reactive',
    },
]);

/** Todos los cosméticos de una entrada (para derivar etiquetas/bonificación). */
function collectEntryCosmetics(entry) {
    return [
        ...(entry.brItems || []),
        ...(entry.instruments || []),
        ...(entry.cars || []),
        ...(entry.legoKits || []),
    ];
}

/**
 * Normaliza una entrada al modelo de franja oficial.
 * @param {object} entry
 * @param {{ singularType: string, isBundle: boolean }} displayInfo  De `getItemDisplayInfo`.
 * @returns {{ typeText: string, tags: string[], variant: 'light'|'dark' }|null}
 */
function deriveCardTaglineModel(entry, displayInfo) {
    if (!entry || !displayInfo) return null;

    const cosmetics = collectEntryCosmetics(entry);
    const isBundle = displayInfo.isBundle
        || (typeof entry.devName === 'string' && entry.devName.endsWith('for -1 MtxCurrency'));

    let typeText;
    if (isBundle) {
        typeText = 'Lote';
    } else {
        typeText = displayInfo.singularType || '';
        // «y bonificación»: ítem suelto cuya oferta regala cosméticos extra.
        if (cosmetics.length > 1) typeText += ' y bonificación';
    }
    if (!typeText) return null;

    const ctx = { entry, cosmetics };
    const tags = CARD_TAG_DERIVERS.filter((deriver) => deriver.test(ctx)).map((deriver) => deriver.label);

    return { typeText, tags, variant: tags.length > 0 ? 'light' : 'dark' };
}

/**
 * Franja inferior de tipo/etiquetas (tag-banner oficial).
 * @param {object} entry
 * @param {object} displayInfo
 * @returns {HTMLElement|null}
 */
function buildCardTaglineNode(entry, displayInfo) {
    const model = deriveCardTaglineModel(entry, displayInfo);
    if (!model) return null;

    const tagline = document.createElement('div');
    tagline.className = 'card-tagline';
    tagline.dataset.taglineVariant = model.variant;
    tagline.setAttribute('aria-hidden', 'true');

    const marquee = createCardMarqueeNode(model.typeText);
    if (model.tags.length > 0) {
        const content = marquee.querySelector('.fn-marquee__content');
        const plus = document.createElement('span');
        plus.className = 'fn-marquee__plus';
        plus.textContent = '+';
        content.appendChild(plus);
        content.appendChild(document.createTextNode(model.tags.join(', ')));
    }
    tagline.appendChild(marquee);
    return tagline;
}

/* ----------------------------------------------------------------
   CRÉDITOS DE PISTA DE IMPROVISACIÓN — la tarjeta oficial separa el
   artista (línea propia, heading-now-regular) del título de la canción
   (marquee de una línea). El `displayName` canónico «Título - Artista»
   sigue siendo el nombre del producto en carrito, pedidos, URLs y búsqueda.
---------------------------------------------------------------- */

/**
 * Normaliza `entry.tracks[0]` (campos `title`/`artist` de la API) al modelo
 * de créditos de la tarjeta. Resiliente a pistas sin título o sin artista.
 * @param {object|null|undefined} track
 * @param {string} fallbackTitle  Nombre canónico si la pista no trae título.
 * @returns {{ title: string, artist: string|null }}
 */
function deriveJamTrackCredits(track, fallbackTitle) {
    const title = typeof track?.title === 'string' ? track.title.trim() : '';
    const artist = typeof track?.artist === 'string' ? track.artist.trim() : '';
    return { title: title || fallbackTitle, artist: artist || null };
}

/**
 * Línea de artista de la tarjeta (sobre el título).
 * @param {string} artist
 * @returns {HTMLParagraphElement}
 */
function buildCardArtistNode(artist) {
    const node = document.createElement('p');
    node.className = 'card-artist';
    node.textContent = artist;
    return node;
}

/**
 * Indica si la pista principal de una oferta tiene video de YouTube
 * publicado. El flag `tracks[0].hasVideo` lo resuelve el backend
 * (JamTrackVideoIndex) desde la misma tabla que alimenta el reproductor de la
 * página de objeto, así la insignia y el player nunca discrepan.
 * @param {object|null|undefined} entry
 * @returns {boolean}
 */
function jamTrackHasVideo(entry) {
    return entry?.tracks?.[0]?.hasVideo === true;
}

/**
 * Insignia «con video» de la esquina superior derecha del arte. Es puramente
 * informativa: no captura punteros (el clic sigue siendo del enlace) y usa el
 * símbolo del sprite inline, por lo que pinta en el primer frame sin red.
 * @returns {HTMLSpanElement}
 */
function buildCardVideoBadgeNode() {
    const node = document.createElement('span');
    node.className = 'card-video-badge';
    node.setAttribute('role', 'img');
    node.setAttribute('aria-label', 'Con video de YouTube');
    node.appendChild(createIcon('youtube', 'card-video-badge__icon'));
    return node;
}

/**
 * Marca en memoria que la pista de una oferta ya tiene video (tanto en la
 * copia recibida como en la entrada canónica de APIData, si existe) y añade la
 * insignia a sus tarjetas ya renderizadas: publicación in-place desde la
 * página de objeto, sin recarga. Idempotente.
 * @param {object|null|undefined} entry Entrada de tienda de la oferta.
 */
function markJamTrackVideoAvailable(entry) {
    if (!entry?.tracks?.[0]) return;
    const offerId = typeof entry.offerId === 'string' ? entry.offerId : '';
    const canonicalEntry = offerId ? findItemDataById(offerId)?.data : null;

    for (const target of [entry, canonicalEntry]) {
        const track = target?.tracks?.[0];
        if (track && typeof track === 'object') track.hasVideo = true;
    }

    if (!offerId) return;
    document
        .querySelectorAll(`.item-card-wrapper[data-offer-id="${CSS.escape(offerId)}"] .item-image-container`)
        .forEach((imgContainer) => {
            if (!imgContainer.querySelector('.card-video-badge')) {
                imgContainer.appendChild(buildCardVideoBadgeNode());
            }
        });
}

/**
 * Inversa de markJamTrackVideoAvailable: el autor eliminó su video desde la
 * página de objeto, así que la pista deja de tener video y sus tarjetas
 * pierden la insignia. Idempotente.
 * @param {object|null|undefined} entry Entrada de tienda de la oferta.
 */
function markJamTrackVideoUnavailable(entry) {
    if (!entry?.tracks?.[0]) return;
    const offerId = typeof entry.offerId === 'string' ? entry.offerId : '';
    const canonicalEntry = offerId ? findItemDataById(offerId)?.data : null;

    for (const target of [entry, canonicalEntry]) {
        const track = target?.tracks?.[0];
        if (track && typeof track === 'object') track.hasVideo = false;
    }

    if (!offerId) return;
    document
        .querySelectorAll(`.item-card-wrapper[data-offer-id="${CSS.escape(offerId)}"] .card-video-badge`)
        .forEach((badge) => badge.remove());
}

/* ----------------------------------------------------------------
   ORQUESTADOR DEL DESPLAZAMIENTO — comportamiento oficial exacto:
   - Medición perezosa por tarjeta al entrar el cursor (o focus).
   - Solo anima marquees cuyo contenido desborda su viewport.
   - `--marquee-end` = −(desborde px); `--marquee-duration` con la fórmula
     oficial (contenido/viewport × 4 s).
   - Al salir: se retiran clase y variables → salto instantáneo al inicio.
   - Al volver a entrar: se fuerza un reinicio real de la animación
     (quitar clase → reflow → volver a ponerla), garantizando que siempre
     arranca desde el principio, incluida la pausa inicial.
   Listeners delegados en `document`: no se registra nada por tarjeta.
---------------------------------------------------------------- */
const CardMarqueeManager = (() => {
    const CARD_SELECTOR = '.item-card-wrapper';
    let initialized = false;

    function activate(card) {
        card.querySelectorAll('.fn-marquee').forEach((marquee) => {
            const viewport = marquee.querySelector('.fn-marquee__viewport');
            const scroller = marquee.querySelector('.fn-marquee__scroller');
            if (!viewport || !scroller) return;

            const contentWidth = scroller.scrollWidth;
            const viewportWidth = viewport.clientWidth;
            const overflow = contentWidth - viewportWidth;
            if (overflow <= MARQUEE_MIN_OVERFLOW_PX || viewportWidth <= 0) {
                reset(scroller);
                return;
            }

            const duration = (contentWidth / viewportWidth) * MARQUEE_DURATION_FACTOR_S;
            viewport.style.setProperty('--marquee-end', `${-Math.round(overflow)}px`);
            viewport.style.setProperty('--marquee-duration', `${duration}s`);

            // Reinicio garantizado: si la clase seguía puesta (p. ej. un
            // mouseout perdido), quitarla y forzar reflow hace que la
            // animación arranque de verdad desde el 0 %.
            scroller.classList.remove('is-scrolling');
            void scroller.offsetWidth;
            scroller.classList.add('is-scrolling');
        });
    }

    function reset(scroller) {
        scroller.classList.remove('is-scrolling');
        const viewport = scroller.parentElement;
        viewport.style.removeProperty('--marquee-end');
        viewport.style.removeProperty('--marquee-duration');
    }

    function deactivate(card) {
        card.querySelectorAll('.fn-marquee .is-scrolling').forEach(reset);
    }

    /** ¿El movimiento cruza el borde de la tarjeta? (ignora movimientos internos) */
    function crossesCardBoundary(event) {
        const card = event.target.closest?.(CARD_SELECTOR);
        if (!card) return null;
        const related = event.relatedTarget;
        if (related && card.contains(related)) return null;
        return card;
    }

    function init() {
        if (initialized) return;
        initialized = true;

        document.addEventListener('mouseover', (event) => {
            const card = crossesCardBoundary(event);
            // Doble rAF: la franja se revela con transición; medir tras el
            // primer frame garantiza layout ya calculado con la tarjeta activa.
            if (card) requestAnimationFrame(() => requestAnimationFrame(() => activate(card)));
        });
        document.addEventListener('mouseout', (event) => {
            const card = crossesCardBoundary(event);
            if (card) deactivate(card);
        });
        document.addEventListener('focusin', (event) => {
            const card = event.target.closest?.(CARD_SELECTOR);
            if (card) requestAnimationFrame(() => requestAnimationFrame(() => activate(card)));
        });
        document.addEventListener('focusout', (event) => {
            const card = crossesCardBoundary(event);
            if (card) deactivate(card);
        });
    }

    return { init };
})();

/* ================================================================
   BANNER DE TARJETA — modelo y renderer unificados
   ----------------------------------------------------------------
   Mismo patrón que el badge de descuento: un MODELO normalizado + un
   ÚNICO renderer, y un adaptador que traduce los datos crudos de la API.

   La API de la tienda (fortnite-api v2/shop) entrega, por entrada, un
   objeto `banner` con la forma:
       { value: "Nuevo", intensity: "High", backendValue: "New" }
   - `backendValue`: identificador estable del tipo de banner.
   - `value`:        texto ya localizado por la API (no se hardcodea).

   `CARD_BANNER_VARIANTS` es la ÚNICA fuente de verdad de qué tipos se
   muestran como banner de esquina y con qué variante visual (CSS
   `[data-banner-type]`). Los descuentos (`AmountOff`) NO van aquí: se
   renderizan como badge de descuento. Añadir/quitar un tipo = editar
   este mapa, sin tocar el renderer.

   @typedef {Object} CardBannerModel
   @property {string} text     Texto visible (proveniente de la API).
   @property {string} variant  Variante visual ('new' | 'discount' | 'default').
================================================================ */
const CARD_BANNER_VARIANTS = Object.freeze({
    New: 'new',
});

/**
 * ÚNICA fuente de verdad del DOM de un banner de tarjeta.
 * @param {CardBannerModel} model
 * @returns {HTMLElement|null}
 */
function createCardBanner(model) {
    if (!model || !model.text) return null;

    const banner = document.createElement('div');
    banner.dataset.bannerType = model.variant || 'default';
    banner.textContent = model.text;
    banner.setAttribute('aria-hidden', 'true');
    return banner;
}

/**
 * Adaptador: normaliza el `banner` crudo de la API de la tienda a
 * `CardBannerModel` y delega en el renderer. Devuelve `null` si la entrada no
 * trae banner o si su tipo no está configurado como banner de esquina.
 * @param {object} itemData  Entrada de la tienda (incluye el objeto `banner`).
 * @returns {HTMLElement|null}
 */
function buildCardBannerNode(itemData) {
    const banner = itemData?.banner;
    if (!banner) return null;

    const variant = CARD_BANNER_VARIANTS[banner.backendValue];
    if (!variant) return null;

    const text = (banner.value || '').trim();
    if (!text) return null;

    return createCardBanner({ text, variant });
}

/* ================================================================
   BADGE DE DESCUENTO — modelo y renderer unificados
   ----------------------------------------------------------------
   Antes existían 3 builders divergentes (oferta dinámica de la API,
   producto estático de Vía Cuenta, premio de la ruleta) que producían el
   mismo badge con estructuras y estilos distintos (unos con `style.*`
   inline, otros con custom props; separador coloreado a mano, etc.).

   Ahora hay UNA estructura y UN renderer. Cada fuente solo NORMALIZA sus
   datos del backend a `DiscountBadgeModel` y delega en `createDiscountBadge`.
   El color (fondo/texto/separador) y las variantes (aumento/ruleta) se
   resuelven por CSS a partir de `--badge-bg-color`/`--badge-text-color` y de
   las clases modificadoras, no en JS.

   @typedef {Object} DiscountBadgeModel
   @property {string}  percentText            Texto principal: "-15%", "+20%", "OFERTA"…
   @property {string}  [label]                Texto secundario opcional (etiqueta, "PREMIO").
   @property {string}  [bgColor]              Color de fondo (ignorado por variantes con color fijo).
   @property {string}  [textColor]
   @property {'discount'|'increase'|'spin-reward'} [variant]
   @property {'bottom-left'} [position]
   @property {HTMLElement[]} [extraRows]       Filas extra (divisor + expiración).
================================================================ */
const DISCOUNT_BADGE_DEFAULT_BG = '#2ecc71';
const DISCOUNT_BADGE_DEFAULT_TEXT = '#ffffff';

/**
 * Resuelve el texto de porcentaje ("-N%"/"+N%") y la variante a partir de un
 * porcentaje explícito del backend o, en su defecto, de los precios base/final.
 * @returns {{ percentText: string, variant: 'discount'|'increase' }}
 */
function resolveDiscountPercent(explicitPercent, baseSolesPrice, finalSolesPrice) {
    const parsed = parseFloat(explicitPercent);
    let rawPercent = null;
    let variant = 'discount';

    if (!isNaN(parsed) && parsed !== 0) {
        rawPercent = Math.abs(parsed);
        variant = parsed < 0 ? 'increase' : 'discount';
    } else {
        const base = parseFloat(baseSolesPrice);
        const fin = parseFloat(finalSolesPrice);
        if (!isNaN(base) && base > 0 && !isNaN(fin)) {
            if (fin < base) { rawPercent = ((base - fin) / base) * 100; variant = 'discount'; }
            else if (fin > base) { rawPercent = ((fin - base) / base) * 100; variant = 'increase'; }
        }
    }

    if (rawPercent === null) return { percentText: 'OFERTA', variant: 'discount' };
    const abs = Math.abs(Math.round(rawPercent));
    return { percentText: variant === 'increase' ? `+${abs}%` : `-${abs}%`, variant };
}

/**
 * ÚNICA fuente de verdad del DOM de un badge de descuento.
 * @param {DiscountBadgeModel} model
 * @returns {HTMLElement|null}
 */
function createDiscountBadge(model) {
    if (!model) return null;
    const percentText = model.percentText || '';
    const label = model.label || '';
    if (!percentText && !label) return null;

    const badge = document.createElement('div');
    badge.className = 'item-badge badge-discount';
    if (model.variant === 'increase') badge.classList.add('badge-discount-negative');
    else if (model.variant === 'spin-reward') badge.classList.add('badge-spin-reward');
    if (model.position === 'bottom-left') badge.dataset.position = 'bottom-left';

    badge.style.setProperty('--badge-bg-color', model.bgColor || DISCOUNT_BADGE_DEFAULT_BG);
    badge.style.setProperty('--badge-text-color', model.textColor || DISCOUNT_BADGE_DEFAULT_TEXT);

    const content = document.createElement('div');
    content.className = 'badge-content';

    if (percentText) {
        const percent = document.createElement('span');
        percent.className = 'badge-percent';
        percent.textContent = percentText;
        content.appendChild(percent);
    }
    if (label) {
        if (percentText) {
            const separator = document.createElement('span');
            separator.className = 'badge-separator';
            separator.setAttribute('aria-hidden', 'true');
            content.appendChild(separator);
        }
        const text = document.createElement('span');
        text.className = 'badge-text';
        text.textContent = label;
        content.appendChild(text);
    }

    badge.appendChild(content);

    if (Array.isArray(model.extraRows) && model.extraRows.length) {
        badge.append(...model.extraRows);
    }
    return badge;
}

/** Adaptador: oferta dinámica de la API (Vía Regalo / Ofertas random / descuento manual). */
function buildDiscountBadgeNode(discountInfo, baseSolesPrice, finalSolesPrice) {
    if (!discountInfo) return null;
    const { percentText, variant } = resolveDiscountPercent(
        discountInfo.porcentaje_descuento, baseSolesPrice, finalSolesPrice);
    return createDiscountBadge({
        percentText,
        label: discountInfo.etiqueta_texto || null,
        bgColor: discountInfo.etiqueta_color_fondo || DISCOUNT_BADGE_DEFAULT_BG,
        textColor: discountInfo.etiqueta_color_texto || DISCOUNT_BADGE_DEFAULT_TEXT,
        position: discountInfo.etiqueta_posicion === '2' ? 'bottom-left' : undefined,
        variant,
    });
}

/** Adaptador: premio de la ruleta (Daily Spin). Color turquesa fijo vía CSS. */
function buildSpinRewardBadgeNode(spinDiscount) {
    if (!spinDiscount || !spinDiscount.percent) return null;
    return createDiscountBadge({
        percentText: `-${spinDiscount.percent}%`,
        label: spinDiscount.is_flash ? '⚡ PREMIO' : 'PREMIO',
        variant: 'spin-reward',
    });
}

function formatLabel(ns, msg) { return `[${ns}] ${msg}`; }
const Logger = {
    with(ns) {
        return {
            info(msg, payload) { if (shouldLog('info')) payload !== undefined ? console.info(formatLabel(ns, msg), payload) : console.info(formatLabel(ns, msg)); },
            warn(msg, payload) { if (shouldLog('warn')) payload !== undefined ? console.warn(formatLabel(ns, msg), payload) : console.warn(formatLabel(ns, msg)); },
            error(msg, payload) { if (shouldLog('error')) payload !== undefined ? console.error(formatLabel(ns, msg), payload) : console.error(formatLabel(ns, msg)); },
            debug(msg, payload) { if (shouldLog('debug')) payload !== undefined ? console.debug(formatLabel(ns, msg), payload) : console.debug(formatLabel(ns, msg)); },
            group(label) { if (shouldLog('debug')) console.group(formatLabel(ns, label)); },
            groupCollapsed(label) { if (shouldLog('debug')) console.groupCollapsed(formatLabel(ns, label)); },
            groupEnd() { if (shouldLog('debug')) console.groupEnd(); }
        };
    }
};
// endregion Logger

// region UX helpers (refined): toast, live, analytics, haptics, i18n, focus
const UX = (() => {
    const NS = Logger.with('UX');
    const isProd = (() => { try { return ['banano.pe', 'www.banano.pe'].includes(location.hostname); } catch (_) { return false; } })();
    const Prefs = {
        get reducedMotion() { try { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (_) { return false; } },
        get allowHaptics() { return !this.reducedMotion; }
    };

    // --------- i18n ---------
    const STRINGS = {
        es: {
            add_to_cart: 'Agregar al Carrito',
            buy_now: 'Comprar ahora',
            offline: 'Sin conexión. Intenta nuevamente cuando recuperes internet.',
            added_to_cart: 'Añadido al carrito',
            add_failed: 'No se pudo añadir al carrito',
            purchase_started: 'Procesando compra…',
            purchase_failed: 'No se pudo iniciar la compra',
        },
        en: {
            add_to_cart: 'Add to Cart',
            buy_now: 'Buy Now',
            offline: 'Offline. Try again when you are back online.',
            added_to_cart: 'Added to cart',
            add_failed: 'Failed to add to cart',
            purchase_started: 'Processing purchase…',
            purchase_failed: 'Could not start purchase',
        }
    };
    const i18n = {
        getLocale() { try { return (navigator.language || 'es').slice(0, 2).toLowerCase(); } catch (_) { return 'es'; } },
        t(key, fallback) {
            const loc = i18n.getLocale();
            return (STRINGS[loc] && STRINGS[loc][key]) || (STRINGS.es && STRINGS.es[key]) || fallback || key;
        }
    };

    // --------- live region (lectores de pantalla) ---------
    let liveNode = null, pendingLive = null, liveScheduled = false, lastLiveMsg = null, lastLiveTs = 0;
    function ensureLiveRegion() {
        if (liveNode && document.body.contains(liveNode)) return liveNode;
        try {
            liveNode = document.getElementById('toast-notification');
            if (!liveNode) {
                liveNode = document.createElement('div');
                liveNode.id = 'aria-live';
                liveNode.setAttribute('role', 'status');
                liveNode.setAttribute('aria-live', 'polite');
                liveNode.setAttribute('aria-atomic', 'true');
                liveNode.className = 'sr-only';
                document.body.appendChild(liveNode);
            }
        } catch (_) { }
        return liveNode;
    }
    function announce(message, { politeness = 'polite', atomic = true } = {}) {
        try {
            const el = ensureLiveRegion();
            el?.setAttribute?.('aria-live', String(politeness));
            el?.setAttribute?.('aria-atomic', String(atomic));
            const next = String(message || '');
            // Deduplicar: no repetir el mismo anuncio en 800ms
            const now = Date.now();
            if (next && lastLiveMsg === next && (now - lastLiveTs) < 800) return;
            lastLiveMsg = next; lastLiveTs = now;
            pendingLive = next;
            if (liveScheduled) return; liveScheduled = true;
            (window.requestAnimationFrame || setTimeout)(() => {
                try {
                    if (pendingLive != null) {
                        el.textContent = '';
                        el.textContent = pendingLive;
                    }
                } finally { liveScheduled = false; pendingLive = null; }
            }, 0);
        } catch (_) { }
    }

    // --------- toasts ---------
    let toastEl = null, toastHideTimer = null, pendingToast = null, toastScheduled = false;
    function ensureToastEl() {
        if (toastEl && document.body.contains(toastEl)) return toastEl;
        try {
            toastEl = document.getElementById('toast-notification');
            if (!toastEl) {
                toastEl = document.createElement('div');
                toastEl.id = 'toast-notification';
                toastEl.className = 'toast-notification';
                toastEl.setAttribute('hidden', '');
                document.body.appendChild(toastEl);
            }
        } catch (_) { }
        return toastEl;
    }
    function showToast(message, opts = {}) {
        try {
            const el = ensureToastEl();
            const { type = 'info', duration = 2600, aria = true } = (opts && typeof opts === 'object') ? opts : {};
            if (!el) { if (aria) announce(message); return { hide: () => { }, update: () => { } }; }
            pendingToast = { message: String(message || ''), type, duration, aria };
            if (toastScheduled) return; toastScheduled = true;
            (window.requestAnimationFrame || setTimeout)(() => {
                try {
                    if (!pendingToast) return;
                    const { message, type, duration, aria } = pendingToast;
                    el.className = `toast-notification toast-${type}`;
                    el.textContent = message;
                    el.removeAttribute('hidden');
                    if (aria) announce(message, { politeness: 'polite' });
                    if (toastHideTimer) clearTimeout(toastHideTimer);
                    toastHideTimer = setTimeout(() => { try { el.setAttribute('hidden', ''); } catch (_) { } }, Math.max(1200, duration | 0));
                } finally { toastScheduled = false; pendingToast = null; }
            }, 0);
            return {
                hide() { try { if (toastHideTimer) clearTimeout(toastHideTimer); el.setAttribute('hidden', ''); } catch (_) { } },
                update(next) {
                    try {
                        const n = (next && typeof next === 'object') ? next : {};
                        const m = n.message != null ? String(n.message) : null;
                        if (m) el.textContent = m;
                        if (n.type) el.className = `toast-notification toast-${n.type}`;
                    } catch (_) { }
                }
            };
        } catch (_) { if (opts && opts.aria !== false) announce(message); return { hide: () => { }, update: () => { } }; }
    }

    // --------- analytics ---------
    function track(event, payload = {}, { context = {}, enabled = true, sample = 1.0 } = {}) {
        try {
            if (!enabled || Features?.get?.('analyticsDisabled', false)) return;
            const rate = Number(Features?.get?.('analyticsSampleRate', sample)) || sample;
            if (Math.random() > Math.max(0, Math.min(1, rate))) return;
            // Filtro PII: descartar claves con emails/teléfonos
            const sanitize = (obj) => {
                try {
                    if (!obj || typeof obj !== 'object') return obj;
                    const out = Array.isArray(obj) ? [] : {};
                    for (const k of Object.keys(obj)) {
                        const v = obj[k];
                        if (typeof v === 'string' && /@/.test(v)) continue;
                        if (typeof v === 'string' && /\b\+?\d[\d\s-]{6,}\b/.test(v)) continue;
                        out[k] = v;
                    }
                    return out;
                } catch (_) { return obj; }
            };
            const full = { event, ts: Date.now(), payload: sanitize(payload), context: sanitize(context) };
            try { window.dataLayer = window.dataLayer || []; window.dataLayer.push(full); } catch (_) { }
            if (!isProd) Logger.with('Analytics').info(event, full);
        } catch (_) { }
    }

    // --------- haptics ---------
    function haptic(type = 'light') {
        try {
            if (!('vibrate' in navigator) || !Prefs.allowHaptics) return;
            const map = {
                light: [8],
                success: [12, 16],
                error: [6, 12, 6]
            };
            const pattern = map[type] || map.light;
            navigator.vibrate(pattern);
        } catch (_) { }
    }

    // --------- focus ---------
    function focus(el, { preventStealFromInputs = true } = {}) {
        try {
            if (!el || typeof el.focus !== 'function') return;
            if (preventStealFromInputs) {
                const tag = (document.activeElement && document.activeElement.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            }
            el.focus();
        } catch (_) { }
    }

    /**
     * Trap focus within a root element. Returns a controller to deactivate.
     * @param {HTMLElement} root
     * @param {{
     *   escapeCloses?: boolean,
     *   returnFocus?: boolean,
     *   initialFocus?: HTMLElement|string|null,
     *   tabbableSelector?: string,
     * }} [opts]
     * @returns {{ deactivate: () => void }}
     */
    function trap(root, opts = {}) {
        const options = Object.assign({
            escapeCloses: true,
            returnFocus: true,
            initialFocus: null,
            tabbableSelector: [
                'a[href]', 'area[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])',
                'select:not([disabled])', 'textarea:not([disabled])', 'iframe', 'object', 'embed',
                '[tabindex]:not([tabindex="-1"])', '[contenteditable]'
            ].join(',')
        }, (opts && typeof opts === 'object') ? opts : {});

        if (!root || typeof root.querySelectorAll !== 'function') return { deactivate() { } };
        let prevFocused = document.activeElement;
        let active = true;

        // Try focus initial target
        try {
            let init = options.initialFocus;
            if (typeof init === 'string') init = root.querySelector(init);
            if (init && typeof init.focus === 'function') init.focus();
            else {
                const first = root.querySelector(options.tabbableSelector);
                if (first && typeof first.focus === 'function') first.focus();
            }
        } catch (_) { }

        const onKeydown = (event) => {
            if (!active) return;
            if (!root.contains(document.activeElement)) return; // only while focused within root
            if (event.key === 'Tab') {
                const tabbables = Array.from(root.querySelectorAll(options.tabbableSelector))
                    .filter(el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length));
                if (tabbables.length === 0) return;
                const first = tabbables[0], last = tabbables[tabbables.length - 1];
                const current = document.activeElement;
                if (event.shiftKey) {
                    if (current === first || !root.contains(current)) {
                        event.preventDefault();
                        try { last.focus(); } catch (_) { }
                    }
                } else {
                    if (current === last) {
                        event.preventDefault();
                        try { first.focus(); } catch (_) { }
                    }
                }
            } else if (options.escapeCloses && event.key === 'Escape') {
                // Consumers should also listen for Escape; here we just restore focus if requested
                try { controller.deactivate(); } catch (_) { }
            }
        };

        document.addEventListener('keydown', onKeydown, true);

        const controller = {
            deactivate() {
                if (!active) return;
                active = false;
                try { document.removeEventListener('keydown', onKeydown, true); } catch (_) { }
                if (options.returnFocus && prevFocused && typeof prevFocused.focus === 'function') {
                    try { prevFocused.focus(); } catch (_) { }
                }
            }
        };
        return controller;
    }

    return {
        i18n, live: { announce }, toast: { show: showToast }, analytics: { track }, haptics: { haptic }, focus: { focus, trap }, prefs: Prefs,
        t: i18n.t,
        announce,
        showToast: (message, typeOrOpts) => typeof typeOrOpts === 'string' ? showToast(message, { type: typeOrOpts }) : showToast(message, typeOrOpts),
        track,
        haptic
    };
})();

// endregion UX helpers

// region Feature Flags
const Features = (() => {
    const NS = Logger.with('Flags');
    function parseExpParam() {
        try {
            const p = new URLSearchParams(location.search).get('exp');
            if (!p) return {};
            return Object.fromEntries(p.split(',').map(k => [k.trim(), true]));
        } catch (_) { return {}; }
    }
    const urlFlags = parseExpParam();
    function get(key, fallback = false) {
        try {
            if (urlFlags[key]) return true;
            const v = localStorage.getItem(`exp.${key}`);
            if (v === '1' || v === 'true') return true;
            if (v === '0' || v === 'false') return false;
        } catch (_) { }
        return fallback;
    }
    function set(key, on) { try { localStorage.setItem(`exp.${key}`, on ? '1' : '0'); } catch (_) { } }
    return { get, set };
})();
// endregion Feature Flags

// region Offer helpers
function getAllItemsFromOffer(entry) {
    if (!entry) return [];

    return [
        ...(entry.brItems || []),
        ...(entry.instruments || []),
        ...(entry.cars || []),
        ...(entry.tracks || [])
    ];
}
function resolveItemImage(item, preferSmall = true) {
    if (!item) return null;

    if (typeof item.albumArt === 'string' && item.albumArt.trim().length > 0) {
        return item.albumArt;
    }

    const images = item.images && typeof item.images === 'object' ? item.images : null;
    if (!images) return null;

    const candidates = [];

    if (preferSmall) {
        candidates.push(images.smallIcon, images.small);
    }

    candidates.push(
        images.icon,
        images.large,
        images.featured,
        images.other && images.other.background
    );

    for (let i = 0; i < candidates.length; i++) {
        const url = candidates[i];
        if (typeof url === 'string' && url.trim().length > 0) {
            return url;
        }
    }

    return null;
}

/**
 * Mejor imagen disponible de un ítem para vistas grandes (hero de la página
 * de objeto): recorre todas las variantes conocidas de mayor a menor calidad
 * y solo devuelve null si el ítem no trae ninguna imagen en absoluto.
 */
function resolveHeroImage(item) {
    if (!item) return null;

    if (typeof item.albumArt === 'string' && item.albumArt.trim().length > 0) {
        return item.albumArt;
    }

    const images = item.images && typeof item.images === 'object' ? item.images : null;
    if (!images) return null;

    const candidates = [
        images.featured,
        images.icon,
        images.large,
        images.small,
        images.smallIcon,
        images.other && images.other.background,
    ];

    for (let i = 0; i < candidates.length; i++) {
        const url = candidates[i];
        if (typeof url === 'string' && url.trim().length > 0) {
            return url;
        }
    }

    return null;
}
// endregion Offer helpers

// region SectionSpinner
const SectionSpinner = (() => {
    /**
     * Unified section spinner manager
     * Public API: show(key, container, sizeOrOptions), hide(key), attachTo(container), pause(), resume(), isVisible(), setSpeed(ms), setSize(px)
     */
    const NS = Logger.with('SectionSpinner');
    let spinnerEl = null;
    let liveTextEl = null;
    let svgEl = null;
    let groupEl = null;
    const refCounts = new Map();
    let currentHost = null;
    let hostObserver = null;
    let io = null;
    let visibilityHandler = null;
    let reduceMotionMql = null;
    let watchdogTimer = null;
    let watchdogCount = 0;
    let watchdogWindowStart = 0;
    let lastShowTs = 0;
    let currentOptions = { size: 48, speedMs: 1100, ariaText: 'Loading…', showThresholdMs: 120 };
    const stats = { shows: 0, hides: 0, attaches: 0, pauses: 0, resumes: 0 };
    const hideResolvers = new Map();
    const pendingShowTimers = new Map();

    const SIZES = { 32: { w: 32, stroke: 4 }, 48: { w: 48, stroke: 6 }, 64: { w: 64, stroke: 8 } };

    function sanitizeText(text) {
        try { return String(text || '').replace(/[\u0000-\u001F\u007F]/g, '').slice(0, 200); } catch (_) { return 'Loading…'; }
    }

    function normalizeOptions(sizeOrOptions) {
        if (!sizeOrOptions) return { ...currentOptions };
        if (typeof sizeOrOptions === 'number') return { ...currentOptions, size: sizeOrOptions };
        const o = { ...currentOptions, ...sizeOrOptions };
        // validate
        if (![32, 48, 64].includes(o.size)) o.size = 48;
        if (!(o.speedMs > 200 && o.speedMs < 5000)) o.speedMs = 1100;
        if (typeof o.ariaText !== 'string' || !o.ariaText) o.ariaText = 'Loading…';
        return o;
    }

    function buildSpinner(size = 48) {
        const spec = SIZES[String(size)] || SIZES[48];
        const svgNS = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('viewBox', '0 0 32 32');
        svg.setAttribute('width', String(spec.w));
        svg.setAttribute('height', String(spec.w));
        svg.setAttribute('role', 'progressbar');
        svg.setAttribute('aria-label', sanitizeText(currentOptions.ariaText || 'Cargando'));
        svg.setAttribute('tabindex', '-1');
        svg.classList.add('spinner-container');

        const g = document.createElementNS(svgNS, 'g');
        g.setAttribute('class', 'spinner-group');

        const track = document.createElementNS(svgNS, 'circle');
        track.setAttribute('cx', '16');
        track.setAttribute('cy', '16');
        track.setAttribute('r', '14');
        track.setAttribute('fill', 'none');
        track.setAttribute('stroke-width', '4');
        track.setAttribute('style', 'stroke: #4B5563;');

        const arc = document.createElementNS(svgNS, 'circle');
        arc.setAttribute('cx', '16');
        arc.setAttribute('cy', '16');
        arc.setAttribute('r', '14');
        arc.setAttribute('fill', 'none');
        arc.setAttribute('stroke-width', '4');
        arc.setAttribute('style', 'stroke: #FFC400; stroke-dasharray: 80; stroke-dashoffset: 60;');

        g.appendChild(track);
        g.appendChild(arc);
        svg.appendChild(g);

        const live = document.createElement('span');
        live.className = 'sr-only';
        live.setAttribute('role', 'status');
        live.setAttribute('aria-live', 'polite');
        live.textContent = sanitizeText(currentOptions.ariaText || 'Cargando');
        svgEl = svg;
        groupEl = g;
        liveTextEl = live;
        try { svgEl._arc = arc; } catch (_) { }
        return svg;
    }

    function setHostBusy(host, on) {
        try { if (host) host.setAttribute('aria-busy', on ? 'true' : 'false'); } catch (_) { }
        try { if (host) host.classList.toggle('is-loading', !!on); } catch (_) { }
        try { if (host) host.classList.toggle('spinner-minh', !!on); } catch (_) { }
        try {
            const view = (document.body && document.body.getAttribute('data-view')) || 'viaregalo';
            const loadingAttr = `loading-${view}`;
            if (on) document.documentElement.setAttribute(`data-${loadingAttr}`, 'true');
            else document.documentElement.removeAttribute(`data-${loadingAttr}`);
        } catch (_) { }
    }

    function ensureSpinner(size) {
        if (!spinnerEl) spinnerEl = buildSpinner(size);
        return spinnerEl;
    }

    function mount(host, size) {
        if (!host) return;
        try { if (document.body && document.body.classList && document.body.classList.contains('modal-open')) return; } catch (_) { }
        try { const mc = document.getElementById('main-content'); if (mc && !mc.contains(host)) return; } catch (_) { }
        try { if (host.offsetParent === null) return; } catch (_) { }
        const spinner = ensureSpinner(size);
        if (spinner.parentElement !== host) {
            if (spinner.parentElement) spinner.parentElement.removeChild(spinner);
            host.appendChild(spinner);
            try { if (liveTextEl && !host.contains(liveTextEl)) host.appendChild(liveTextEl); } catch (_) { }
            currentHost = host;
            stats.attaches++;
        }
        spinner.dataset.visible = 'true';
        spinner.dataset.sectionKey = Array.from(refCounts.keys())[0] || '';
        spinner.dataset.view = (document.body.getAttribute('data-view') || 'viaregalo');
        setHostBusy(currentHost, true);
        try { groupEl && (groupEl.style.animationDuration = `${currentOptions.speedMs}ms`); } catch (_) { }
        const viewName = (document.body.getAttribute('data-view') || 'viaregalo');
        const markShow = `spinner_show_${Date.now()}`;
        try { performance.mark && performance.mark(markShow); spinner.dataset.markShow = markShow; } catch (_) { }
        try { document.dispatchEvent(new CustomEvent('section-spinner:show', { detail: { view: viewName, sectionKey: spinner.dataset.sectionKey, hostId: currentHost && currentHost.id, size: currentOptions.size, speedMs: currentOptions.speedMs, ts: Date.now() } })); } catch (_) { }
        setupObservers();
        clearTimeout(watchdogTimer);
        watchdogTimer = setTimeout(() => {
            try {
                const now = Date.now();
                if (!watchdogWindowStart || now - watchdogWindowStart > 120000) { watchdogWindowStart = now; watchdogCount = 0; }
                watchdogCount++;
                NS.warn('Watchdog auto-hide', { count: watchdogCount });
                api.hideAll();
                if (watchdogCount >= 3) {
                    Logger.with('SectionSpinner').error('Spinner watchdog fired repeatedly; investigate render pipeline.');
                }
            } catch (_) { }
        }, 60000);
        stats.shows++;
    }

    function unmountIfNoRefs() {
        const currentView = (document.body && document.body.getAttribute('data-view')) ? document.body.getAttribute('data-view').toLowerCase() : 'viaregalo';
        let totalForView = 0;
        refCounts.forEach((count, key) => { if (keyToView(key) === currentView) totalForView += count; });
        if (totalForView <= 0 && spinnerEl && spinnerEl.parentElement) {
            const oldHost = spinnerEl.parentElement;
            try { oldHost.removeChild(spinnerEl); } catch (_) { }
            try { if (liveTextEl && oldHost.contains(liveTextEl)) oldHost.removeChild(liveTextEl); } catch (_) { }
            spinnerEl.dataset.visible = 'false';
            teardownObservers();
            clearTimeout(watchdogTimer);
            try {
                const markShow = spinnerEl && spinnerEl.dataset && spinnerEl.dataset.markShow;
                if (markShow && performance.measure) {
                    const markHide = `spinner_hide_${Date.now()}`;
                    performance.mark(markHide);
                    performance.measure('spinner_visible_duration', markShow, markHide);
                    try { performance.clearMarks(markShow); performance.clearMarks(markHide); performance.clearMeasures('spinner_visible_duration'); } catch (_) { }
                }
            } catch (_) { }
            try { document.dispatchEvent(new CustomEvent('section-spinner:hide', { detail: { view: (document.body.getAttribute('data-view') || 'viaregalo'), ts: Date.now() } })); } catch (_) { }
            stats.hides++;
            try { hideResolvers.forEach(set => { set.forEach(fn => { try { fn(); } catch (_) { } }); }); hideResolvers.clear(); } catch (_) { }
            try { setHostBusy(oldHost, false); } catch (_) { }
            currentHost = null;
        }
    }

    function keyToView(sectionKey) {
        const k = String(sectionKey || '').toLowerCase();
        if (k.includes('store')) return 'store';
        if (k.includes('cuenta')) return 'viacuenta';
        if (k.includes('games') || k.includes('juegos') || k.includes('juego')) return 'games';
        return 'store';
    }

    function getDefaultHostForView(view) {
        try {
            if (view === 'viaregalo') return document.getElementById('viaregalo-content');
            if (view === 'viacuenta') return document.getElementById('view-viacuenta');
            if (view === 'games') return document.getElementById('view-juegos');
        } catch (_) { }
        return null;
    }

    function hasActiveRefsForView(view) {
        let total = 0;
        refCounts.forEach((count, key) => { if (keyToView(key) === view) total += count; });
        return total > 0;
    }

    function setupObservers() {
        try {
            if (!hostObserver && currentHost && window.MutationObserver) {
                hostObserver = new MutationObserver(() => {
                    if (!currentHost || !currentHost.isConnected) {
                        const host = getDefaultHostForView((document.body.getAttribute('data-view') || 'viaregalo'));
                        if (host) host.appendChild(spinnerEl);
                    }
                });
                hostObserver.observe(document.body, { childList: true, subtree: true });
            }
            if (!io && window.IntersectionObserver && spinnerEl) {
                io = new IntersectionObserver((entries) => {
                    const e = entries[0];
                    if (!e) return;
                    if (e.isIntersecting) api.resume(); else api.pause();
                }, { root: null, threshold: 0 });
                io.observe(spinnerEl);
            }
            if (!visibilityHandler) {
                let hiddenSince = 0;
                visibilityHandler = () => {
                    if (document.hidden) { hiddenSince = Date.now(); api.pause(); }
                    else { hiddenSince = 0; api.resume(); }
                };
                document.addEventListener('visibilitychange', visibilityHandler);
            }
            if (!reduceMotionMql && window.matchMedia) {
                reduceMotionMql = window.matchMedia('(prefers-reduced-motion: reduce)');
                try { reduceMotionMql.addEventListener('change', () => { if (reduceMotionMql.matches) api.pause(); else api.resume(); }); } catch (_) {
                    try { reduceMotionMql.onchange = () => { if (reduceMotionMql.matches) api.pause(); else api.resume(); }; } catch (_) { }
                }
            }
            try { if (localStorage.getItem('reduceAnimation') === '1') api.pause(); } catch (_) { }
        } catch (_) { }
    }

    function teardownObservers() {
        try { if (hostObserver) { hostObserver.disconnect(); hostObserver = null; } } catch (_) { }
        try { if (io) { io.disconnect(); io = null; } } catch (_) { }
        try { if (visibilityHandler) { document.removeEventListener('visibilitychange', visibilityHandler); visibilityHandler = null; } } catch (_) { }
        try { if (reduceMotionMql) { reduceMotionMql = null; } } catch (_) { }
    }

    const api = {
        show(sectionKey, container, sizeOrOptions = 48) {
            if (!sectionKey || !container) return;
            const cur = refCounts.get(sectionKey) || 0;
            if (cur > 50) { NS.warn && NS.warn('Refcount cap reached for', sectionKey); return; }
            currentOptions = normalizeOptions(sizeOrOptions);
            const doShow = () => {
                const currentCount = refCounts.get(sectionKey) || 0;
                if (currentCount === 0) refCounts.set(sectionKey, 1);
                const currentView = (document.body.getAttribute('data-view') || 'viaregalo').toLowerCase();
                const sameView = (keyToView(sectionKey) === currentView);
                const mountFn = () => { if (sameView) mount(container, currentOptions.size); };
                if (sameView) {
                    const tId = setTimeout(() => { mountFn(); pendingShowTimers.delete(sectionKey); }, currentOptions.showThresholdMs);
                    pendingShowTimers.set(sectionKey, tId);
                } else if (!currentHost) {
                    const host = getDefaultHostForView(currentView);
                    if (host && hasActiveRefsForView(currentView)) mount(host, currentOptions.size);
                }
                NS.debug('show', { sectionKey, count: refCounts.get(sectionKey) || 1 });
                lastShowTs = Date.now();
            };
            if (window.queueMicrotask) queueMicrotask(doShow); else setTimeout(doShow, 0);
        },
        hide(sectionKey) {
            if (!sectionKey) return;
            const doHide = () => {
                const current = refCounts.get(sectionKey) || 0;
                const next = Math.max(0, current - 1);
                if (next === 0) refCounts.delete(sectionKey); else refCounts.set(sectionKey, next);
                NS.debug('hide', { sectionKey, next });
                try { const tid = pendingShowTimers.get(sectionKey); if (tid) { clearTimeout(tid); pendingShowTimers.delete(sectionKey); } } catch (_) { }
                unmountIfNoRefs();
                if (!hasActiveRefsForView((document.body.getAttribute('data-view') || 'viaregalo'))) setHostBusy(currentHost, false);
            };
            if (window.requestIdleCallback) requestIdleCallback(doHide, { timeout: 200 });
            else setTimeout(doHide, 0);
        },
        hideAll() {
            try { refCounts.clear(); unmountIfNoRefs(); setHostBusy(currentHost, false); } catch (_) { }
        },
        attachTo(container) { // re-attach on view change without creating duplicates
            if (!spinnerEl || !container) return;
            const currentView = (document.body.getAttribute('data-view') || 'viaregalo').toLowerCase();
            if (hasActiveRefsForView(currentView) && container.offsetParent !== null) {
                mount(container);
            }
        },
        pause() { try { groupEl?.classList.add('is-paused'); stats.pauses++; } catch (_) { } },
        resume() { try { groupEl?.classList.remove('is-paused'); stats.resumes++; } catch (_) { } },
        isVisible() { return !!(spinnerEl?.parentElement && spinnerEl.dataset.visible === 'true'); },
        setSpeed(ms) { if (ms > 200 && ms < 5000) { currentOptions.speedMs = ms; if (groupEl) groupEl.style.animationDuration = `${ms}ms`; } },
        setSize(px) {
            if (![32, 48, 64].includes(px)) return;
            const spec = SIZES[String(px)] || SIZES[48];
            currentOptions.size = px;
            svgEl?.setAttribute('width', String(spec.w)); svgEl?.setAttribute('height', String(spec.w));
            try {
                const circles = svgEl?.querySelectorAll('circle') ?? [];
                circles.forEach(c => c.setAttribute('stroke-width', String(spec.stroke)));
            } catch (_) { }
        },
        setAriaText(text) { try { if (typeof text === 'string' && text.trim()) { const st = sanitizeText(text); currentOptions.ariaText = st; liveTextEl && (liveTextEl.textContent = st); svgEl?.setAttribute('aria-label', st); } } catch (_) { } },
        setProgress(percent) {
            try {
                const p = Math.max(0, Math.min(100, Number(percent)));
                svgEl?.setAttribute('aria-valuenow', String(p));
                const arc = svgEl?._arc;
                if (arc) {
                    const total = 88; // approximate circumference segment we show
                    const len = Math.max(12, Math.round((p / 100) * total));
                    const off = Math.round((total - len) * 0.75) + 20; // aesthetic offset
                    arc.setAttribute('style', `stroke: #FFC400; stroke-dasharray: ${len}; stroke-dashoffset: ${off};`);
                }
            } catch (_) { }
        },
        setColors({ track, arc }) {
            try {
                if (!svgEl) return;
                const circles = svgEl.querySelectorAll('circle');
                if (track && circles[0]) circles[0].setAttribute('style', `stroke: ${track};`);
                if (arc && circles[1]) {
                    const style = circles[1].getAttribute('style') || '';
                    const rest = style.replace(/stroke:\s*#[0-9A-Fa-f]{3,8}/, '').trim();
                    circles[1].setAttribute('style', `stroke: ${arc}; ${rest}`);
                }
            } catch (_) { }
        },
        getState() {
            return {
                visible: !!(spinnerEl && spinnerEl.parentElement),
                view: document.body.getAttribute('data-view') || 'viaregalo',
                refs: Array.from(refCounts.entries()),
                hostId: currentHost && currentHost.id,
                options: { ...currentOptions },
                stats: { ...stats }
            };
        },
        getKeyRefs() { return new Map(refCounts); },
        untilHidden(sectionKey) {
            return new Promise((resolve) => {
                try {
                    const set = hideResolvers.get(sectionKey) || new Set();
                    set.add(resolve);
                    hideResolvers.set(sectionKey, set);
                } catch (_) { resolve(); }
            });
        },
        _stats() { return { ...stats }; }
    };

    try { window.addEventListener('beforeunload', () => { try { api.hide('*'); } catch (_) { } }); } catch (_) { }

    try {
        if ((window.APP_ENV || (location && location.hostname === 'localhost') ? 'development' : 'production') !== 'production') {
            window.__SPINNER__ = api;
            window.__SPINNER_TEST__ = { isMountedFor: (v) => (spinnerEl && spinnerEl.dataset && spinnerEl.dataset.view) === v, getHostId: () => currentHost && currentHost.id, getKeyRefs: () => new Map(refCounts) };
        }
    } catch (_) { }
    return api;
})();
// endregion SectionSpinner

// region NavAnimator
/**
 * NavAnimator - Hardware-Accelerated Side Nav Geometry Interpolator
 *
 * Provides smooth WAAPI-based height interpolation when appending content to the side navigation.
 * Prevents jarring "layout teleportation" by animating height changes with cascaded opacity fade-in.
 *
 * Architecture:
 * - Captures current height before DOM mutation
 * - Appends new content with opacity 0 (invisible)
 * - Measures new scrollHeight after layout
 * - Uses WAAPI to animate height expansion over 280ms
 * - Cascades opacity fade-in for sub-nodes with stagger
 *
 * Idempotency & Memory Safety:
 * - Cancels previous WAAPI animations when interrupted
 * - Measures from current interrupted height (not original)
 * - Ensures proper garbage collection of temporary nodes
 */
const NavAnimator = (() => {
    // Stateful animation reference for idempotent interruption handling
    let currentAnimation = null;
    
    // Configuration constants
    const TIMING = {
        HEIGHT_DURATION: 300,
        EASING: 'cubic-bezier(0.25, 0.46, 0.45, 0.94)'
    };

    /**
     * Appends document fragment to container with smooth height interpolation.
     * Uses CSS-delegated child animations (zero inline styles on children).
     * Stateful WAAPI with .finish() for interruption handling.
     *
     * @param {HTMLElement} container - Target container (e.g., #section-nav-items)
     * @param {DocumentFragment} documentFragment - Content to append
     */
    function appendWithInterpolation(container, documentFragment) {
        if (!container || !documentFragment) {
            console.warn('[NavAnimator] Invalid container or fragment');
            return;
        }

        // MANDATE 2: Cancel previous animation (not finish) to avoid compositor lock
        if (currentAnimation && currentAnimation.playState === 'running') {
            currentAnimation.cancel();
            currentAnimation = null;
        }

        // MANDATE 1: Synchronous FLIP - First (measure old state)
        const oldHeight = container.offsetHeight;

        // Remove loader synchronously
        const existingLoader = container.querySelector('.side-nav__global-loader');
        if (existingLoader) {
            existingLoader.remove();
        }

        // MANDATE 1: Capture new nodes before appending (no CSS class delegation)
        const newNodes = Array.from(documentFragment.children).filter(node => node instanceof HTMLElement);

        // MANDATE 1: Append fragment synchronously (DOM write)
        container.appendChild(documentFragment);

        // MANDATE 1: Last (measure new state) - synchronously, no rAF
        const newHeight = container.offsetHeight;

        // Skip animation if no height change
        if (newHeight === oldHeight) {
            return;
        }

        // MANDATE 2: Animate container without fill:'forwards' so effect auto-purges
        currentAnimation = container.animate(
            [
                { height: `${oldHeight}px` },
                { height: `${newHeight}px` }
            ],
            {
                duration: TIMING.HEIGHT_DURATION,
                easing: TIMING.EASING
            }
        );

        currentAnimation.onfinish = () => {
            currentAnimation = null;
        };

        // MANDATE 3: Programmatic cascading WAAPI for children with fill:'backwards'
        const CHILD_STAGGER_MS = 40;
        const CHILD_DURATION_MS = 250;
        const CHILD_EASING = 'cubic-bezier(0.25, 0.46, 0.45, 0.94)';

        newNodes.forEach((node, index) => {
            const delay = index * CHILD_STAGGER_MS;
            node.animate(
                [
                    { opacity: 0, transform: 'translateY(-8px)' },
                    { opacity: 1, transform: 'translateY(0)' }
                ],
                {
                    duration: CHILD_DURATION_MS,
                    delay: delay,
                    easing: CHILD_EASING,
                    fill: 'backwards'
                }
            );
        });
    }

    return {
        appendWithInterpolation
    };
})();
// endregion NavAnimator

// Montar spinner de tienda en el primer paint si el DOM está listo
(function earlyStoreSpinnerMount() {
    try {
        const activate = () => {
            const currentView = (document.body && document.body.getAttribute('data-view')) || 'viaregalo';
            if (currentView === 'viaregalo') {
                const sc = document.getElementById('viaregalo-content');
                if (sc && !sc.querySelector('.spinner-container')) {
                    SectionSpinner.show('store', sc, 48);
                }
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', activate, { once: true });
        } else {
            activate();
        }
    } catch (_) { }
})();

Logger.with('AppInit').info('Application script loading');
/* ==================== CONSTANTES Y CONFIGURACIÓN ==================== */

const CONFIG = {
    // La tienda se sirve 100% desde el backend interno (banano_primordial.json
    // via /web.php). El frontend ya NO contacta ninguna API externa directamente.
    WHATSAPP_NUMBER: '51924989167',
    CONVERSION_RATE: 1.50 / 100,
    MANUAL_CATEGORY_COLORS: {
        // Series
        'CreatorCollabSeries': { textColor: '#5CF2F3', backgroundColor: '#025253' }, // Serie de Ídolos
        'PlatformSeries': { textColor: '#8078FF', backgroundColor: '#28085F' }, // Leyendas del Juego
        'DCUSeries': { textColor: '#007af1', backgroundColor: '#003169' }, // Serie de DC
        'Series_Lamborghini': { textColor: '#cfbee1', backgroundColor: '#260948' }, // Serie Lamborghini
        'MarvelSeries': { textColor: '#ED1C24', backgroundColor: '#610709' }, // Serie de Marvel
        'ColumbusSeries': { textColor: '#FFD800', backgroundColor: '#040A14' }, // Saga Star Wars
        'FrozenSeries': { textColor: '#d4e7fb', backgroundColor: '#0059ed' }, // Serie Congelada
        'LavaSeries': { textColor: '#f4c336', backgroundColor: '#5c045c' }, // Serie de Lava
        'CUBESeries': { textColor: '#f74ac1', backgroundColor: '#5c1c7c' }, // Serie Oscura (Dark)
        'SlurpSeries': { textColor: '#1cf7f5', backgroundColor: '#1c96fc' }, // Serie Pegajosa (Slurp)
        'ShadowSeries': { textColor: '#fff', backgroundColor: '#151515' }, // Serie de las Sombras

        // Rarezas
        'epic': { textColor: '#ce59ff', backgroundColor: '#4c197b' }, // Épico
        'legendary': { textColor: '#ffe8cf', backgroundColor: '#de6e0e' }, // Legendario
        'uncommon': { textColor: '#61bf00', backgroundColor: '#024f03' }, // Poco Común
        'common': { textColor: '#b7bfc5', backgroundColor: '#40464d' }, // Común
        'rare': { textColor: '#00afff', backgroundColor: '#00458a' }  // Raro
    },

    GET_VIDEO_URL: '/web.php',
    DATE_OPTIONS: { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' },
    SHORT_DATE_OPTIONS: { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' },
    PLACEHOLDER_IMAGE: 'https://via.placeholder.com/256',
    GLOW_COLOR_DEFAULT: 'rgba(245, 158, 11, 0.5)',
    TYPE_TRANSLATIONS_SINGULAR: {
        'outfit': 'Traje',
        'emote': 'Gesto',
        'glider': 'Ala delta',
        'pickaxe': 'Pico',
        'backpack': 'Accesorio mochilero',
        'wrap': 'Envoltorio',
        'shoe': 'Calzado',
        'contrail': 'Estela',
        'carbody': 'Coche',
        'wheel': 'Rueda',
        'jamtrack': 'Pista de improvisaci\u00f3n',
        'sparks_song': 'Pista de improvisaci\u00f3n',
        'trail': 'Rastro',
        'boost': 'Potenciador',
        'sidekick': 'Compa\u00f1ero',
        'mic': 'Instrumento',
        'bass': 'Instrumento',
        'guitar': 'Instrumento',
        'keyboard': 'Instrumento',
        'drum': 'Instrumento',
        'bundle': 'Lote',
        'shoes': 'Calzado',
        'footwear': 'Calzado',
        'instrument': 'Instrumento',
        'sparks_bass': 'Instrumento',
        'vehicle_wheel': 'Rueda',
        'cosmeticvariant': 'Estilo',
        'cosmetic': 'Cosm\u00e9tico',
        'itemaccess': 'Acceso',
        'spray': 'Grafiti',
        'music': 'M\u00fasica',
        'loadingscreen': 'Pantalla de carga',
        'battlebus': 'Autob\u00fas de batalla',
        'default': 'Art\u00edculo'
    },

    TYPE_TRANSLATIONS_PLURAL: {
        'outfit': 'Trajes',
        'emote': 'Gestos',
        'glider': 'Alas delta',
        'pickaxe': 'Picos',
        'backpack': 'Accesorios mochileros',
        'wrap': 'Envoltorios',
        'shoe': 'Calzado',
        'contrail': 'Estelas',
        'carbody': 'Coches',
        'wheel': 'Ruedas',
        'jamtrack': 'Pistas de improvisaci\u00f3n',
        'sparks_song': 'Pistas de improvisaci\u00f3n',
        'trail': 'Rastros',
        'boost': 'Potenciadores',
        'sidekick': 'Compa\u00f1eros',
        'mic': 'Instrumentos',
        'bass': 'Instrumentos',
        'guitar': 'Instrumentos',
        'keyboard': 'Instrumentos',
        'drum': 'Instrumentos',
        'bundle': 'Lotes',
        'shoes': 'Calzado',
        'footwear': 'Calzado',
        'instrument': 'Instrumentos',
        'sparks_bass': 'Instrumentos',
        'vehicle_wheel': 'Ruedas',
        'cosmeticvariant': 'Estilos',
        'cosmetic': 'Cosm\u00e9ticos',
        'itemaccess': 'Accesos',
        'spray': 'Grafitis',
        'music': 'M\u00fasica',
        'loadingscreen': 'Pantallas de carga',
        'battlebus': 'Autobuses de batalla',
        'default': 'Art\u00edculos'
    },

    JAM_TRACK_INSTRUMENT_ORDER: [
        'vocals',
        'plasticBass',
        'plasticDrums',
        'bass',
        'guitar',
        'drums'
    ],

    JAM_TRACK_INSTRUMENTS: {
        'vocals': { label: 'Voz' },
        'plasticBass': { label: 'Bajo' },
        'plasticGuitar': { label: 'Instrumento Principal' },
        'plasticDrums': { label: 'Batería' },
        'bass': { label: 'Bajo Pro' },
        'guitar': { label: 'Instrumento Principal Pro' },
        'drums': { label: 'Batería Pro' }
    },

    REAL_INSTRUMENT_TYPES: new Set(['bass', 'drums', 'guitar', 'keyboard', 'microphone'])

};
Logger.with('AppInit').debug('Post CONFIG checkpoint');

let shopTimerInterval = null;
let APIData = null;
const automaticOffersMap = new Map();
const manualDiscountsMap = new Map();
const spinDiscountsMap = new Map();
const backupImageMap = new Map();
const staticProductsMap = new Map();

/**
 * Wraps fetch with an AbortController + timeout for network resiliency.
 * Mirrors the architectural safety pattern in fetchDashboardSession.
 * @param {string|Request} url - The fetch URL or Request object.
 * @param {object} [options={}] - Standard fetch options.
 * @param {number} [timeoutMs=7000] - Timeout in milliseconds before aborting.
 * @returns {Promise<Response>} The fetch Response.
 */
async function fetchWithTimeout(url, options = {}, timeoutMs = 7000) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => { try { controller.abort(); } catch (_) { } }, timeoutMs);
    try {
        const res = await fetch(url, { ...options, signal: controller.signal });
        return res;
    } finally {
        clearTimeout(timeoutId);
    }
}

async function fetchSpinDiscounts() {
    try {
        const res = await fetchWithTimeout('/web.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=e5u' });
        if (!res.ok) return;
        const json = await _resolveSyncFrame(await res.json());
        if (json.success && json.discounts) {
            spinDiscountsMap.clear();
            for (const [offerId, data] of Object.entries(json.discounts)) {
                spinDiscountsMap.set(offerId, data);
            }
            Logger.with('SpinDiscounts').info('Loaded spin discounts', { count: spinDiscountsMap.size });
        }
    } catch (err) {
        Logger.with('SpinDiscounts').warn('Failed to fetch spin discounts', err);
    }
}

function setSpinDiscountsFromObject(discountsObj) {
    if (!discountsObj || typeof discountsObj !== 'object') return;
    spinDiscountsMap.clear();
    for (const [offerId, data] of Object.entries(discountsObj)) {
        spinDiscountsMap.set(offerId, data);
    }
}

function refreshSpinDiscountPricesInDom({ animate = true } = {}) {
    try {
        if (!APIData || !APIData.details) return;
        const cards = document.querySelectorAll('.item-card-wrapper[data-offer-id]');
        cards.forEach(card => {
            try {
                const offerId = card.dataset.offerId;
                if (!offerId) return;

                const entry = APIData.details.get(offerId);
                const vbucks = entry && typeof entry.finalPrice === 'number' ? entry.finalPrice : null;
                if (!(vbucks > 0)) return;

                const priceBlock = card.querySelector('.price-block');
                if (!priceBlock) return;

                const doUpdate = () => {
                    const { finalSolesPrice, baseSolesPrice, spinDiscount } = calculateSolesPrice(vbucks, offerId);
                    const priceDiscountColorClass = spinDiscount ? 'text-spin-reward' : 'text-green-500';
                    priceBlock.replaceChildren(createPriceSpans(finalSolesPrice, baseSolesPrice, {
                        baseClass: 'text-sm price-base-strikethrough line-through',
                        finalClass: 'font-black text-base sm:text-lg',
                        discountColorClass: priceDiscountColorClass,
                    }));
                };

                if (!animate) {
                    doUpdate();
                    return;
                }

                priceBlock.style.transition = 'opacity 220ms ease';
                priceBlock.style.opacity = '0.35';
                requestAnimationFrame(() => {
                    doUpdate();
                    requestAnimationFrame(() => {
                        priceBlock.style.opacity = '1';
                        setTimeout(() => {
                            try { priceBlock.style.transition = ''; } catch (_) { }
                        }, 260);
                    });
                });
            } catch (_) { }
        });
    } catch (_) { }
}

try {
    window.StoreSpinDiscounts = window.StoreSpinDiscounts || {};
    window.StoreSpinDiscounts.apply = function (discountsObj, opts = {}) {
        setSpinDiscountsFromObject(discountsObj);
        refreshSpinDiscountPricesInDom(opts);
        return true;
    };
    window.StoreSpinDiscounts.refresh = async function (opts = {}) {
        const res = await fetchWithTimeout('/web.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=e5u' });
        if (!res.ok) return false;
        const json = await _resolveSyncFrame(await res.json());
        if (json?.success && json.discounts) {
            setSpinDiscountsFromObject(json.discounts);
            refreshSpinDiscountPricesInDom(opts);
            return true;
        }
        return true;
    };
} catch (_) { }

let _ytApiLoadPromise = null;
function ensureYouTubeAPI() {
    if (window.YT && window.YT.Player) return Promise.resolve();
    if (_ytApiLoadPromise) return _ytApiLoadPromise;
    _ytApiLoadPromise = new Promise((resolve) => {
        const readyCheck = () => {
            if (window.YT && window.YT.Player) resolve();
        };
        const prevReady = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () {
            if (typeof prevReady === 'function') try { prevReady(); } catch (_) { }
            resolve();
        };
        const s = document.createElement('script');
        s.src = 'https://www.youtube.com/iframe_api';
        s.async = true;
        s.onload = readyCheck;
        document.head.appendChild(s);
        const it = setInterval(() => {
            if (window.YT && window.YT.Player) {
                clearInterval(it);
                resolve();
            }
        }, 50);
        setTimeout(() => clearInterval(it), 5000);
    });
    return _ytApiLoadPromise;
}

const shopWorker = new Worker('/worker.js');
// region worker-messaging
shopWorker.onmessage = async function (e) {
    const { type, payload } = e && e.data && typeof e.data === 'object' ? e.data : {};
    if (!type) {
        Logger.with('WorkerInterface').warn('Worker message ignored due to missing type', { event: e });
        return;
    }

    switch (type) {
        case 'SHOP_UPDATED':
            try {
                console.groupCollapsed('Renderer: SHOP_UPDATED received');
                Logger.with('WorkerInterface').info('Shop data received. Starting merge and render', { payload });

                const sections = payload.sections || [];

                if (sections.length === 0) {
                    Logger.with('WorkerInterface').warn('SHOP_UPDATED received with empty or invalid sections. Rendering skipped.');
                    SectionSpinner.hide('store');
                    return;
                }

                SHOP_SECTIONS = sections;

                APIData = {
                    details: new Map(sections.flatMap(s => s.items || []).map(i => [i.offerId, i])),
                    date: payload?.date
                };

                manualDiscountsMap.clear();
                automaticOffersMap.clear();

                renderSectionSkeletons(sections);

                try {
                    const sc = document.getElementById('viaregalo-content');
                    if ((document.body.getAttribute('data-view') || 'viaregalo') === 'viaregalo' && sc) {
                        SectionSpinner.show('viaregalo', sc, 48);
                    }
                } catch (_) { }

                requestAnimationFrame(() => {
                    document.getElementById('loading-overlay')?.classList.add('hidden');
                    document.getElementById('error-message')?.classList.add('hidden');
                    document.getElementById('view-viaregalo')?.removeAttribute('hidden');
                });

                if (payload?.date) startShopTimer(payload.date);

                setTimeout(() => {
                    console.groupCollapsed('Renderer: Post-render tasks');
                    Logger.with('Renderer').info('Executing post-render tasks');

                    populateSectionItems(sections);

                    try {
                        const cacheData = {
                            timestamp: Date.now(),
                            sections,
                            date: payload?.date,
                            allItems: sections.flatMap(s => s.items || []),
                        };
                        localStorage.setItem('shopCache', JSON.stringify(cacheData));
                    } catch (error) {
                        Logger.with('Cache').warn('Could not save shop data to localStorage cache', { error });
                    }
                    console.groupEnd();
                }, 0);

                setTimeout(() => { try { SectionSpinner.hide('store'); } catch (_) { } }, 200);

            } catch (err) {
                Logger.with('WorkerInterface').error('Error processing SHOP_UPDATED message', { error: err });
                showError();
            } finally {
                console.groupEnd();
            }
            break;

        case 'SHOP_INITIAL_BATCH':
            try {
                Logger.with('WorkerInterface').info('Initial batch received', { count: payload?.sections?.length });
                const initialSections = payload.sections || [];

                SHOP_SECTIONS = initialSections;
                APIData = {
                    details: new Map(initialSections.flatMap(s => s.items || []).map(i => [i.offerId, i])),
                    date: payload?.date
                };
                manualDiscountsMap.clear();
                automaticOffersMap.clear();

                renderSectionSkeletons(initialSections);

                if (payload?.showBestsellersSkeleton) {
                    const bestsellerPlaceholder = {
                        id: 'section-bestsellers-carousel',
                        title: 'Lo más vendido',
                        mobileTitle: 'Más Vendido',
                        layout: 'carousel',
                        headerColor: '#e74c3c',
                        items: [],
                        _isSkeletonPlaceholder: true,
                    };
                    renderSectionSkeletons([bestsellerPlaceholder], { prepend: true });
                    Logger.with('WorkerInterface').info('Bestsellers skeleton placeholder rendered.');
                }

                document.getElementById('loading-overlay')?.classList.add('hidden');
                document.getElementById('error-message')?.classList.add('hidden');
                document.getElementById('view-viaregalo')?.removeAttribute('hidden');

                try { SectionSpinner.hide('store'); } catch (_) { }
                if (payload?.date) startShopTimer(payload.date);

                const PRIORITY_BATCH_SIZE = 2;
                const prioritySections = initialSections.slice(0, PRIORITY_BATCH_SIZE);
                const remainingSections = initialSections.slice(PRIORITY_BATCH_SIZE);

                requestAnimationFrame(() => {
                    try {
                        if (prioritySections.length > 0) {
                            populateSectionItems(prioritySections, { runFinalSetup: false });
                            Logger.with('Renderer').debug('Priority sections populated', { count: prioritySections.length });
                        }

                        if (remainingSections.length > 0) {
                            requestAnimationFrame(() => {
                                try {
                                    populateSectionItems(remainingSections, { runFinalSetup: false });
                                    Logger.with('Renderer').debug('Remaining sections populated', { count: remainingSections.length });
                                } catch (err) {
                                    Logger.with('Renderer').error('Error populating remaining sections', { error: err });
                                }
                            });
                        }
                    } catch (err) {
                        Logger.with('Renderer').error('Error populating priority sections', { error: err });
                    }
                });

            } catch (err) {
                Logger.with('WorkerInterface').error('Error processing SHOP_INITIAL_BATCH', { error: err });
            }
            break;

        case 'SHOP_CHUNK':
            try {
                const chunkSections = payload.sections || [];
                if (chunkSections.length === 0) break;

                Logger.with('WorkerInterface').info('Chunk received', { count: chunkSections.length });

                for (const section of chunkSections) {
                    for (const item of (section.items || [])) {
                        APIData.details.set(item.offerId, item);
                    }
                }

                SHOP_SECTIONS = SHOP_SECTIONS.concat(chunkSections);

                renderSectionSkeletons(chunkSections, { append: true });

                requestAnimationFrame(() => {
                    try {
                        populateSectionItems(chunkSections, { runFinalSetup: false });
                    } catch (err) {
                        Logger.with('Renderer').error('Error populating chunk sections', { error: err });
                    }
                });

            } catch (err) {
                Logger.with('WorkerInterface').error('Error processing SHOP_CHUNK', { error: err });
            }
            break;

        case 'SHOP_COMPLETE':
            try {
                Logger.with('WorkerInterface').info('Streaming complete. Running final setup.');

                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        if (document.querySelector('.horizontal-scroll-section')) initializeCarousels();
                        resyncBadgePositions();

                        try {
                            const cacheData = {
                                timestamp: Date.now(),
                                sections: SHOP_SECTIONS,
                                date: payload?.date || APIData?.date,
                                allItems: SHOP_SECTIONS.flatMap(s => s.items || []),
                            };
                            localStorage.setItem('shopCache', JSON.stringify(cacheData));
                        } catch (cacheErr) {
                            Logger.with('Cache').warn('Could not save shop data to localStorage cache', { error: cacheErr });
                        }

                        try { SectionSpinner.hide('store'); } catch (_) { }
                        Logger.with('Renderer').info('Incremental streaming render completed');
                    });
                });

            } catch (err) {
                Logger.with('WorkerInterface').error('Error processing SHOP_COMPLETE', { error: err });
            }
            break;

        case 'SHOP_PREPEND_SECTION':
            try {
                const section = payload?.section;
                const existingWrapper = document.getElementById('section-bestsellers-carousel');

                if (section?.items?.length > 0) {
                    Logger.with('WorkerInterface').info('Bestsellers received. Populating skeleton placeholder.', { items: section.items.length });

                    for (const item of section.items) {
                        if (APIData?.details) APIData.details.set(item.offerId, item);
                    }
                    SHOP_SECTIONS = [section, ...SHOP_SECTIONS];

                    if (existingWrapper) {
                        requestAnimationFrame(() => {
                            try {
                                populateSectionItems([section], { runFinalSetup: false });
                            } catch (_) { }
                            requestAnimationFrame(() => {
                                try { initializeCarousels(); } catch (_) { }
                                try { resyncBadgePositions(); } catch (_) { }
                            });
                        });
                    } else {
                        renderSectionSkeletons([section], { prepend: true });
                        requestAnimationFrame(() => {
                            try {
                                populateSectionItems([section], { runFinalSetup: false });
                            } catch (_) { }
                            requestAnimationFrame(() => {
                                try { initializeCarousels(); } catch (_) { }
                            });
                        });
                    }

                    try {
                        const cacheData = {
                            timestamp: Date.now(),
                            sections: SHOP_SECTIONS,
                            date: APIData?.date,
                            allItems: SHOP_SECTIONS.flatMap(s => s.items || []),
                        };
                        localStorage.setItem('shopCache', JSON.stringify(cacheData));
                    } catch (_) { }

                    Logger.with('WorkerInterface').info('Bestsellers prepend complete.');
                } else {
                    Logger.with('WorkerInterface').info('Bestsellers empty/failed. Removing skeleton placeholder.');
                    if (existingWrapper) {
                        if (window.ShopCarousel) window.ShopCarousel.destroyWithin(existingWrapper);
                        existingWrapper.style.transition = 'opacity 300ms ease, max-height 300ms ease';
                        existingWrapper.style.opacity = '0';
                        existingWrapper.style.maxHeight = existingWrapper.offsetHeight + 'px';
                        existingWrapper.style.overflow = 'hidden';
                        requestAnimationFrame(() => {
                            existingWrapper.style.maxHeight = '0';
                            setTimeout(() => {
                                existingWrapper.remove();
                                document.querySelectorAll('[data-section="section-bestsellers-carousel"]').forEach(el => el.remove());
                            }, 320);
                        });
                    }
                }
            } catch (err) {
                Logger.with('WorkerInterface').error('Error processing SHOP_PREPEND_SECTION', { error: err });
            }
            break;

        case 'ERROR':
            Logger.with('WorkerInterface').error('Worker reported a fatal error', { payload });
            if (!APIData) {
                showError();
            }
            try { SectionSpinner.hide('store'); } catch (_) { }
            break;

        default:
            Logger.with('WorkerInterface').warn('Unrecognized worker message type', { type });
            break;
    }
};
// endregion worker-messaging
// region dom-refs
const jamTrackVideoCache = new Map();

let SHOP_SECTIONS = [];
// Secciones "duplicadas" (Lo más vendido / Ofertas random del día). Única
// fuente de verdad: sus tarjetas SIEMPRE se muestran a 1 tile de ancho
// (forceOneByOne). Para añadir otra sección de este tipo, basta con sumarla aquí.
const ONE_BY_ONE_SECTION_IDS = new Set([
    'section-bestsellers-carousel',
    'section-random-offer',
]);
const isOneByOneSection = (sectionId) => ONE_BY_ONE_SECTION_IDS.has(sectionId);

// Contrato con catalog-filter/: el renderer declara secciones
// (`data-catalog-section`, `data-catalog-aux`) y facetas por tarjeta
// (`data-filter-types`, `data-filter-price`) y avisa de cada cambio real del
// catálogo. El filtro nunca reconstruye tarjetas; sólo alterna `hidden`.
function notifyCatalogChange(scope) {
    document.dispatchEvent(new CustomEvent('banano:catalog-change', { detail: { scope } }));
}
const BESTSELLERS_URL = 'https://cdn2.unrealengine.com/fn_bsdata/ebb74910-dd35-44b8-b826-d58dc16c6456.json';
const musicControlBar = document.getElementById('music-control-bar');
const musicVideoContainer = document.getElementById('music-video-container');
const musicControlInfo = document.getElementById('music-control-info');
const musicControlTitle = document.getElementById('music-control-title');
const musicControlArtist = document.getElementById('music-control-artist');
const musicControlExpandBtn = document.getElementById('music-control-expand');
const musicControlToggleBtn = document.getElementById('music-control-toggle');
const musicControlCloseBtn = document.getElementById('music-control-close');

const mobileMusicController = document.getElementById('mobile-music-controller');
const mobileMusicToggleBtn = document.getElementById('mobile-music-toggle');
const itemColorsByOfferId = new Map();
// region utils
/**
 * Obtiene el precio final en soles y el precio base, priorizando datos pre-calculados del servidor.
 * @param {number} finalPriceVBucks - Precio en V-Bucks (fallback).
 * @param {string} offerId - ID de la oferta.
 * @returns {{finalSolesPrice: string, baseSolesPrice: string, discountInfo: object|null}}
 */
function calculateSolesPrice(finalPriceVBucks, offerId) {
    let result;
    const itemData = findItemDataById(offerId)?.data;

    // Ruta principal: precios pre-calculados por el servidor
    if (itemData && typeof itemData.regularPrice === 'number' && typeof itemData.finalPriceWithDiscount === 'number') {

        const baseSolesPrice = itemData.regularPrice.toFixed(2);
        const finalSolesPrice = itemData.finalPriceWithDiscount.toFixed(2);

        let discountInfo = null;
        if (itemData.discountApplied) {
            discountInfo = { isManual: true };
        }

        result = {
            finalSolesPrice,
            baseSolesPrice,
            discountInfo
        };
    } else {
        // Fallback: cálculo manual (caché antigua o datos incompletos)
        const baseSolesPrice = (finalPriceVBucks * CONFIG.CONVERSION_RATE).toFixed(2);
        let finalSolesPrice = baseSolesPrice;
        let appliedDiscountInfo = null;

        const manualDiscount = manualDiscountsMap.get(offerId);
        const automaticDiscount = automaticOffersMap.get(offerId);

        if (manualDiscount) {
            if (manualDiscount.tipo_descuento === 'porcentaje') {
                finalSolesPrice = (baseSolesPrice * (1 - parseFloat(manualDiscount.valor) / 100)).toFixed(2);
            } else if (manualDiscount.tipo_descuento === 'precio_fijo') {
                finalSolesPrice = parseFloat(manualDiscount.valor).toFixed(2);
            }
            appliedDiscountInfo = { ...manualDiscount, isManual: true };
        } else if (automaticDiscount) {
            const discountPercentage = parseFloat(automaticDiscount.porcentaje_descuento);
            if (!isNaN(discountPercentage)) {
                finalSolesPrice = (baseSolesPrice * (1 - discountPercentage / 100)).toFixed(2);
                appliedDiscountInfo = { ...automaticDiscount, isManual: false };
            }
        }

        result = {
            finalSolesPrice,
            baseSolesPrice,
            discountInfo: appliedDiscountInfo
        };
    }

    // Descuentos de la ruleta (Spin Rewards)
    const spinDiscount = spinDiscountsMap.get(offerId);
    if (spinDiscount && spinDiscount.percent > 0) {
        const baseNum = parseFloat(result.baseSolesPrice);
        if (!isNaN(baseNum) && baseNum > 0) {
            const spinFinalPrice = (baseNum * (1 - spinDiscount.percent / 100)).toFixed(2);
            if (parseFloat(spinFinalPrice) < parseFloat(result.finalSolesPrice)) {
                result.finalSolesPrice = spinFinalPrice;
            }
            result.spinDiscount = spinDiscount;
        }
    }

    return result;
}
/** @param {number|string} amount @returns {string} Formato "S/ 0.00" */
function formatSoles(amount) {
    const n = Number(amount);
    if (!isFinite(n)) return 'S/ 0.00';
    return 'S/ ' + n.toFixed(2);
}
// endregion utils
/** @param {string} tileSize @returns {number} Span (1–4) para clase .span-card-N */
function getTileSizeClass(tileSize) {
    if (!tileSize || tileSize === 'Size_1_x_1' || tileSize === 'Size_1x1') return 1;

    switch (tileSize) {
        case 'Size_4_x_1':
        case 'Size_4x1': return 4;
        case 'Size_3_x_1':
        case 'Size_3x1': return 3;
        case 'Size_2_x_1':
        case 'Size_2x1': return 2;
        case 'Size_2_x_2':
        case 'Size_2x2': return 2;
        case 'Size_1_x_2':
        case 'Size_1x2': return 1;
        default: return 1;
    }
}
/** @param {string} id @returns {{data: object, isStatic: boolean}|null} */
function findItemDataById(id) {
    const offerId = String(id);

    if (APIData && APIData.details.has(offerId)) {
        return { data: APIData.details.get(offerId), isStatic: false };
    }

    if (staticProductsMap.has(offerId)) {
        return { data: staticProductsMap.get(offerId), isStatic: true };
    }

    return null;
}
function setImageWithOfferFallback(imgEl, src, alt, offerId) {
    if (!imgEl) return;
    imgEl.src = src;
    imgEl.alt = alt || '';
    imgEl.onerror = function () { handleImageError(this, String(offerId)); };
}
function createPriceSpans(finalSolesPrice, baseSolesPrice, options = {}) {
    const fragment = document.createDocumentFragment();
    const {
        baseClass = 'text-sm price-base-strikethrough line-through',
        finalClass = 'font-black text-base sm:text-lg',
        discountColorClass = 'text-green-500'
    } = options;

    const base = (baseSolesPrice !== undefined && baseSolesPrice !== null && baseSolesPrice !== '')
        ? parseFloat(baseSolesPrice)
        : null;
    const final = (finalSolesPrice !== undefined && finalSolesPrice !== null && finalSolesPrice !== '')
        ? parseFloat(finalSolesPrice)
        : null;
    const hasValidBase = typeof base === 'number' && !isNaN(base);
    const hasValidFinal = typeof final === 'number' && !isNaN(final);
    const hasDiscount = hasValidBase && hasValidFinal && base > final;
    const hasIncrease = hasValidBase && hasValidFinal && final > base;

    const priceContainer = document.createElement('div');
    priceContainer.className = 'price-container';

    if (hasDiscount || hasIncrease) {
        const baseSpan = document.createElement('span');
        baseSpan.className = `${baseClass} base-price`;
        baseSpan.textContent = formatSoles(baseSolesPrice);
        priceContainer.appendChild(baseSpan);
    }

    const finalSpan = document.createElement('span');
    let finalClassNames = finalClass;
    if (hasDiscount) {
        finalClassNames = `${finalClass} ${discountColorClass}`;
    } else if (hasIncrease) {
        finalClassNames = `${finalClass} text-price-negative`;
    }
    finalSpan.className = finalClassNames;
    finalSpan.textContent = formatSoles(finalSolesPrice);
    priceContainer.appendChild(finalSpan);

    fragment.appendChild(priceContainer);

    return fragment;
}

function buildStockBadgeNode(stockCount) {
    if (!(stockCount > 0)) return null;
    const stockBadge = document.createElement('div');
    stockBadge.className = 'item-badge';
    stockBadge.setAttribute('style', 'top: auto; bottom: 8px; left: 8px; background-color: #e67e22; font-weight: 900; font-size: 0.7rem;');
    stockBadge.textContent = `¡Quedan ${stockCount}!`;
    return stockBadge;
}
/** Adaptador: producto estático (Vía Cuenta) con descuento/etiqueta manual. */
function buildStaticDiscountBadgeNode(producto, textoEtiqueta, ofertaTimerParts, bgColor) {
    if (!textoEtiqueta && !producto.etiqueta_texto) return null;
    const isIncrease = typeof textoEtiqueta === 'string' && textoEtiqueta.trim().startsWith('+');
    return createDiscountBadge({
        percentText: textoEtiqueta || '',
        label: producto.etiqueta_texto || null,
        bgColor: bgColor || DISCOUNT_BADGE_DEFAULT_BG,
        textColor: producto.etiqueta_color_texto || DISCOUNT_BADGE_DEFAULT_TEXT,
        position: producto.etiqueta_posicion === '2' ? 'bottom-left' : undefined,
        variant: isIncrease ? 'increase' : 'discount',
        extraRows: ofertaTimerParts || undefined,
    });
}
/** Fallback de imagen rota: intenta cargar imagen de respaldo o muestra icono de error. */
function handleImageError(imgElement, offerId) {
    imgElement.onerror = null;

    const fallbackUrl = backupImageMap.get(offerId);

    if (fallbackUrl) {
        Logger.with('Image').info('Activated fallback image', { offerId, fallbackUrl });
        imgElement.src = optimizeImageUrl(fallbackUrl);
    } else {
        Logger.with('Image').warn('Failed to find backup image', { offerId });
        if (imgElement.parentElement) {
            imgElement.parentElement.replaceChildren(getErrorIconSVG());
        }
    }
}
/** Mapea un producto estático a datos de tarjeta para createItemCard. */
function mapStaticProductToCardData(producto, config) {
    const precioBase = parseFloat(producto.precio_base);
    const precioOferta = parseFloat(producto.precio_oferta);
    const tienePreciosValidos = !isNaN(precioBase) && !isNaN(precioOferta);
    let esOfertaValida = tienePreciosValidos && precioOferta !== precioBase;

    if (esOfertaValida && producto.oferta_fecha_expiracion) {
        const fechaExpiracionOfertaUTC = formatUtcDateString(producto.oferta_fecha_expiracion);
        if (fechaExpiracionOfertaUTC && fechaExpiracionOfertaUTC < new Date()) {
            esOfertaValida = false;
        }
    }
    const precioFinalParaCompra = esOfertaValida ? precioOferta : precioBase;
    const badgesFragment = document.createDocumentFragment();
    const isAgotado = parseInt(producto.stock_cantidad) === 0;

    if (esOfertaValida) {
        const textoEtiqueta = generarTextoEtiqueta(producto);
        let etiquetaColorFondo = producto.etiqueta_color_fondo || '#27ae60';
        const expiryInfo = buildStaticOfferExpiryParts(producto);
        if (expiryInfo && expiryInfo.urgent) {
            etiquetaColorFondo = '#c53030';
        }
        if (textoEtiqueta || producto.etiqueta_texto) {
            const badge = buildStaticDiscountBadgeNode(producto, textoEtiqueta, expiryInfo ? expiryInfo.parts : null, etiquetaColorFondo);
            if (badge) badgesFragment.appendChild(badge);
        }
    }

    if (!isAgotado && producto.stock_cantidad > 0 && producto.stock_cantidad <= 5) {
        const stockBadge = buildStockBadgeNode(producto.stock_cantidad);
        if (stockBadge) badgesFragment.appendChild(stockBadge);
    }

    let productTimerNode = null;
    if (producto.fecha_expiracion) {
        const diff = formatUtcDateString(producto.fecha_expiracion) - new Date();
        if (diff > 0) {
            const d = Math.floor(diff / 86400000), h = Math.floor((diff % 86400000) / 3600000), m = Math.floor((diff % 3600000) / 60000);
            const timerText = d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m`;
            const timer = document.createElement('div');
            timer.className = 'item-timer' + (diff < 86400000 ? ' critical' : '');
            const timerColor = (diff < 86400000) ? '#c53030' : (producto.timer_color_fondo || '#18181b');
            timer.setAttribute('style', `background-color: ${timerColor}; color: ${producto.timer_color_texto || '#ffffff'};`);
            const fechaAttr = producto.fecha_expiracion.replace(' ', 'T') + 'Z';
            timer.dataset.outdate = fechaAttr;
            const i = createIcon('clock');
            const span = document.createElement('span');
            span.textContent = timerText;
            timer.append(i, span);
            productTimerNode = timer;
        }
    }

    let agotadoOverlayNode = null;
    if (isAgotado) {
        const ov = document.createElement('div');
        ov.className = 'card-soldout-overlay absolute inset-0 bg-black/80 flex flex-col items-center justify-center font-black z-20';
        ov.setAttribute('style', 'color: #ef4444; pointer-events: none;');
        const i = createIcon('times-circle', 'text-5xl mb-2');
        const span = document.createElement('span');
        span.className = 'text-2xl uppercase tracking-wider';
        span.textContent = 'AGOTADO';
        ov.append(i, span);
        agotadoOverlayNode = ov;
    }

    return {
        offerId: producto.id,
        displayName: producto.nombre,
        singularType: config.type,
        categoryText: config.title.toUpperCase(),
        finalPrice: precioFinalParaCompra,
        basePrice: esOfertaValida ? precioBase : null,
        displayAssets: [{ url: producto.imagen_url || CONFIG.PLACEHOLDER_IMAGE }],
        bgStyle: `background-color: ${config.color};`,
        glowColor: config.glow,

        preRenderedBadgesNode: badgesFragment.childNodes.length ? badgesFragment : null,
        preRenderedTimerNode: productTimerNode,
        preRenderedOverlayNode: agotadoOverlayNode,

        isStaticProduct: true,
        isAgotado: isAgotado,
        staticConfig: config,
        descripcion: producto.descripcion,
        imagen_url: producto.imagen_url,

        precio_base: producto.precio_base,
        precio_oferta: producto.precio_oferta,
        etiqueta_texto: producto.etiqueta_texto || null,
        etiqueta_color_fondo: producto.etiqueta_color_fondo || null,
        etiqueta_color_texto: producto.etiqueta_color_texto || null,
        etiqueta_posicion: producto.etiqueta_posicion || null,
        oferta_fecha_expiracion: producto.oferta_fecha_expiracion || null,
        urgencia_horas: producto.urgencia_horas || null,

        // Flags de tutorial y reserva (la cola de modales del checkout los consume).
        activarTutorial: parseInt(producto.activar_tutorial) === 1,
        mensajeTutorial: producto.mensaje_tutorial || null,
        modoReserva: parseInt(producto.modo_reserva) === 1,
        mensajeReserva: producto.mensaje_reserva || null
    };
}
function getErrorIconSVG() {
    const wrapper = document.createElement('div');
    wrapper.className = 'error-icon-container';
    const svgNS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', 'error-svg-icon');
    svg.setAttribute('aria-hidden', 'true');
    const g = document.createElementNS(svgNS, 'g');
    g.setAttribute('fill', 'currentColor');
    const p1 = document.createElementNS(svgNS, 'path');
    p1.setAttribute('fill-rule', 'evenodd');
    p1.setAttribute('clip-rule', 'evenodd');
    p1.setAttribute('d', 'M18.4393 21L20.0947 22.6554L21.1554 21.5947L2.40539 2.84473L1.34473 3.90539L3.00006 5.56072V19.5C3.00006 20.3284 3.67163 21 4.50006 21H18.4393ZM13.0018 15.5625L8.73833 11.299L4.50006 15.5625H13.0018Z');
    const p2 = document.createElementNS(svgNS, 'path');
    p2.setAttribute('d', 'M21.0001 4.5V18.6129L17.9496 15.5625H19.5001L13.8462 8.4375L12.623 10.2359L5.38715 3H19.5001C20.3285 3 21.0001 3.67157 21.0001 4.5Z');
    g.appendChild(p1); g.appendChild(p2); svg.appendChild(g); wrapper.appendChild(svg);
    return wrapper;
}

function initializePageElements() {
    const currentDateEl = document.getElementById('current-date');
    if (currentDateEl) {
        currentDateEl.textContent = `Tienda del ${new Date().toLocaleDateString('es-ES', CONFIG.DATE_OPTIONS)}`;
    }
}
function setupStaticEventListeners() {
    Logger.with('Events').info('Initializing static event listeners');

    installRetryButton();
    // La navegación (vistas, URLs limpias, back/forward e indicador) la gestiona
    // ahora main.js (router con History API). El antiguo installNavigationTabs()
    // basado en hash ya no se invoca para no competir con el router nuevo.
    installScrollToTop();
    installMobileNavSmartScroll();
    installGlobalClickAndEsc();
    installCartNavigationListener();
    installHeaderStickyObserver();

    try { setTimeout(() => initUserSessionState(), 0); } catch (_) { }
}

// region static-event-installers
function installRetryButton() {
    const retryButton = document.getElementById('retry-button');
    if (retryButton) retryButton.addEventListener('click', () => window.location.reload());
}

// installNavigationTabs() (router casero basado en hash + show/hide manual) se
// eliminó: su rol lo asume main.js (router con History API, store y ciclo de
// vida de vistas). Ver main.js y public_html/views/*.view.js.

function installScrollToTop() {
    const scrollToTopBtn = document.getElementById('back-to-top');
    if (!scrollToTopBtn) return;

    const mobileNav = document.getElementById('mobile-nav');

    let lastVisible = false;
    let latestScrollY = window.scrollY || 0;
    let ticking = false;

    const update = () => {
        ticking = false;
        const mobileNavVisible = !!(mobileNav && !mobileNav.classList.contains('mobile-nav-hidden'));
        const visible = (latestScrollY > 300) && !mobileNavVisible;
        if (visible !== lastVisible) {
            scrollToTopBtn.classList.toggle('visible', visible);
            lastVisible = visible;
        }
    };

    window.addEventListener('scroll', () => {
        latestScrollY = window.scrollY || 0;
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(update);
        }
    }, { passive: true });

    requestAnimationFrame(update);
    scrollToTopBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'auto' }));
}

function installMobileNavSmartScroll() {
    const mobileNav = document.getElementById('mobile-nav');
    if (!mobileNav) return;

    const scrollToTopBtn = document.getElementById('back-to-top');

    let lastScrollTop = window.scrollY || document.documentElement.scrollTop || 0;
    let latestScrollTop = lastScrollTop;
    let isHidden = false;
    let ticking = false;
    const threshold = 8;

    const setHidden = (nextHidden) => {
        if (nextHidden === isHidden) return;
        isHidden = nextHidden;
        mobileNav.classList.toggle('mobile-nav-hidden', isHidden);

        if (!isHidden && scrollToTopBtn) {
            scrollToTopBtn.classList.remove('visible');
        }
    };

    /**
     * Forcefully reveal the mobile nav from outside the scroll closure.
     * Properly resets internal state so the next scroll-down hides it normally.
     */
    const revealMobileNav = () => {
        if (!isHidden) return;  // already visible, nothing to do
        isHidden = false;
        mobileNav.classList.remove('mobile-nav-hidden');
        // Reset anchor to current scroll position so the very next
        // scroll-down delta is measured from HERE, not from a stale position.
        const now = window.scrollY || document.documentElement.scrollTop || 0;
        lastScrollTop = now;
        latestScrollTop = now;

        if (scrollToTopBtn) {
            scrollToTopBtn.classList.remove('visible');
        }
    };

    // Al sumar artículos al carrito se muestra la barra para que el badge sea visible.
    document.addEventListener('banano:cart-change', (event) => {
        const { count, previous } = event.detail ?? {};
        if (Number.isFinite(count) && Number.isFinite(previous) && count > previous) revealMobileNav();
    });

    const update = () => {
        ticking = false;
        const current = Math.max(0, latestScrollTop);
        const delta = current - lastScrollTop;

        if (Math.abs(delta) >= threshold) {
            if (delta > 0) {
                setHidden(true);
            } else {
                setHidden(false);
            }
            lastScrollTop = current;
        }

        if (current <= 0) {
            setHidden(false);
            lastScrollTop = 0;
        }
    };

    window.addEventListener('scroll', () => {
        latestScrollTop = window.scrollY || document.documentElement.scrollTop || 0;
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(update);
        }
    }, { passive: true });

    requestAnimationFrame(update);
}

function sanitizeUsername(raw) {
    const value = (raw === null || raw === undefined) ? '' : String(raw);
    const sanitized = value
        .replace(/[\u0000-\u001f\u007f]/g, '')
        .replace(/[<>"'&]/g, '')
        .trim();
    return sanitized.slice(0, 32);
}

function resolveAvatarFilename(avatarsList, avatarId) {
    const targetId = Number(avatarId);
    if (!Array.isArray(avatarsList) || !Number.isFinite(targetId)) return null;
    const match = avatarsList.find(a => Number(a?.id) === targetId);
    const filename = match?.filename ?? null;
    return (typeof filename === 'string' && filename.trim()) ? filename.trim() : null;
}

function buildAvatarUrl(filename) {
    if (!filename) return null;
    const safe = String(filename).replace(/\\/g, '/').split('/').map(part => encodeURIComponent(part)).join('/');
    return `/assets/avatars/${safe}`;
}

// El backend (AvatarCatalog) ya resuelve y repara el avatar; la lista sólo es respaldo.
function resolveSessionAvatarUrl(session) {
    const direct = session?.user?.avatar_url;
    if (typeof direct === 'string' && direct.trim()) return direct.trim();
    const filename = session?.user?.avatar_filename || resolveAvatarFilename(session?.avatarsList, session?.user?.avatar_id);
    return buildAvatarUrl(filename);
}

async function fetchDashboardSession() {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => { try { controller.abort(); } catch (_) { } }, 6000);
    try {
        const res = await fetch('/web.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=j5r&sub=d2',
            signal: controller.signal
        });

        if (res.status === 401) return null;
        if (!res.ok) return null;

        const data = await res.json().then(r => _resolveSyncFrame(r)).catch(() => null);
        if (!data || typeof data !== 'object') return null;

        const user = data.user ?? null;
        const avatarsList = data.avatars_list ?? [];
        if (!user || typeof user !== 'object') return null;
        return { user, avatarsList, rawData: data };
    } catch (_) {
        return null;
    } finally {
        try { clearTimeout(timeoutId); } catch (_) { }
    }
}

function applyAuthenticatedNavState({ username, publicDisplayName, avatarUrl, isVerified }) {
    const fallbackAvatarUrl =
        'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%2032%2032%22%3E%3Cpath%20fill%3D%22%239ca3af%22%20d%3D%22M16%2015.5a5%205%200%201%200%200-10%205%205%200%200%200%200%2010zm0%202.2c-6.7%200-11%203.7-11%205.5v3.4h22v-3.4c0-2.2-4.1-5.5-11-5.5z%22/%3E%3C/svg%3E';
    const resolvedAvatarUrl = avatarUrl || fallbackAvatarUrl;
    const resolvedPublicDisplayName = (typeof publicDisplayName === 'string' && publicDisplayName.trim())
        ? publicDisplayName
        : 'N/A';

    try {
        const desktopLogin = document.getElementById('header-login');
        if (desktopLogin) {
            const wrapper = document.createElement('div');
            wrapper.className = 'relative inline-flex';

            const profile = document.createElement('a');
            profile.href = '/cuenta/';
            profile.id = 'header-login';
            profile.className = 'mectrl_topHeader flex h-12 items-center min-w-0 cursor-pointer select-none hover:bg-white/10 transition-colors';
            profile.setAttribute('aria-label', `Administrar cuenta de ${username}`);
            profile.setAttribute('aria-haspopup', 'true');
            profile.setAttribute('aria-expanded', 'false');

            const textWrap = document.createElement('div');
            textWrap.className = 'mectrl_header_text mectrl_truncate flex items-center h-12 pl-3 pr-1 max-w-[160px] min-w-0 overflow-hidden whitespace-nowrap text-[13px] font-normal text-white';

            const nameSpan = document.createElement('span');
            nameSpan.textContent = resolvedPublicDisplayName;
            nameSpan.className = 'truncate';

            const verified = (Number(isVerified) === 1);
            const verifiedIcon = verified ? document.createElementNS('http://www.w3.org/2000/svg', 'svg') : null;
            if (verifiedIcon) {
                verifiedIcon.setAttribute('viewBox', '0 0 24 24');
                verifiedIcon.setAttribute('fill', 'currentColor');
                verifiedIcon.setAttribute('aria-hidden', 'true');
                verifiedIcon.setAttribute('focusable', 'false');
                verifiedIcon.classList.add('ml-1', 'h-4', 'w-4', 'shrink-0', 'text-sky-400');
                verifiedIcon.title = 'Cuenta verificada';
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', 'M12 2a10 10 0 1 0 10 10A10.011 10.011 0 0 0 12 2Zm4.293 7.707-5 5a1 1 0 0 1-1.414 0l-2-2a1 1 0 1 1 1.414-1.414l1.293 1.293 4.293-4.293a1 1 0 0 1 1.414 1.414Z');
                verifiedIcon.appendChild(path);
            }

            textWrap.appendChild(nameSpan);
            if (verifiedIcon) textWrap.appendChild(verifiedIcon);

            const avatarWrap = document.createElement('div');
            avatarWrap.className = 'mectrl_profilepic m-2 w-8 h-8 rounded-full bg-center bg-cover bg-no-repeat shrink-0';
            avatarWrap.setAttribute('aria-hidden', 'true');
            avatarWrap.setAttribute('role', 'presentation');

            const preloader = new Image();
            preloader.decoding = 'async';
            preloader.onload = () => {
                avatarWrap.style.backgroundImage = `url("${resolvedAvatarUrl}")`;
            };
            preloader.onerror = () => {
                avatarWrap.style.backgroundImage = `url("${fallbackAvatarUrl}")`;
            };
            preloader.src = resolvedAvatarUrl;

            profile.append(textWrap, avatarWrap);

            const flyout = document.createElement('div');
            flyout.className = 'mectrl_flyout hidden absolute right-0 top-full mt-2 z-50 bg-[#1a1a1a] border border-zinc-700 shadow-lg';
            flyout.setAttribute('role', 'dialog');
            flyout.setAttribute('aria-label', 'Menú de cuenta');

            const accountInfo = document.createElement('div');
            accountInfo.className = 'mectrl_accountInfo flex flex-row flex-nowrap items-center w-[320px] min-h-[132px]';

            const leftCol = document.createElement('div');

            const largeAvatar = document.createElement('div');
            largeAvatar.className = 'mectrl_profilepic mectrl_profilepic_large w-[88px] h-[88px] rounded-full bg-center bg-cover bg-no-repeat ml-4 mr-5 my-5 shrink-0';
            largeAvatar.setAttribute('aria-hidden', 'true');
            largeAvatar.setAttribute('role', 'presentation');
            largeAvatar.style.backgroundImage = `url("${resolvedAvatarUrl}")`;
            leftCol.appendChild(largeAvatar);

            const details = document.createElement('div');
            details.className = 'mectrl_accountDetails flex flex-col flex-1 min-w-0 h-auto py-5 pr-3 justify-center gap-1';

            const nameRow = document.createElement('div');
            nameRow.className = 'mectrl_name mectrl_truncate h-auto leading-tight overflow-visible whitespace-nowrap text-lg font-bold text-white px-px';
            nameRow.setAttribute('aria-label', resolvedPublicDisplayName);
            nameRow.title = resolvedPublicDisplayName;
            nameRow.textContent = resolvedPublicDisplayName;

            const userRow = document.createElement('div');
            userRow.className = 'mectrl_truncate h-auto leading-normal overflow-visible whitespace-nowrap text-sm font-medium text-zinc-400 mt-0 px-px';
            userRow.textContent = username;

            const accountLink = document.createElement('a');
            accountLink.className = 'mectrl_resetStyle mectrl_link mectrl_truncate w-full h-9 flex items-center overflow-hidden whitespace-nowrap text-[15px] font-normal text-sky-500 hover:underline px-2 rounded';
            accountLink.href = '/cuenta/';
            accountLink.textContent = 'Mi cuenta';

            const logoutLink = document.createElement('a');
            logoutLink.className = 'mectrl_resetStyle mectrl_link mectrl_truncate w-full h-9 flex items-center overflow-hidden whitespace-nowrap text-[15px] font-normal text-white/70 hover:text-white hover:underline px-2 rounded';
            logoutLink.href = '/cuenta/logout.php';
            logoutLink.textContent = 'Cerrar sesión';

            const links = document.createElement('div');
            links.className = 'mectrl_commands mt-2 flex flex-col gap-1 -mx-2';
            links.append(accountLink, logoutLink);

            details.append(nameRow, userRow, links);
            accountInfo.append(leftCol, details);
            flyout.appendChild(accountInfo);

            let flyoutOpen = false;
            const setFlyoutOpen = (open) => {
                flyoutOpen = open;
                flyout.classList.toggle('hidden', !open);
                profile.setAttribute('aria-expanded', open ? 'true' : 'false');
            };

            profile.addEventListener('click', (e) => {
                e.preventDefault();
                setFlyoutOpen(!flyoutOpen);
            });

            try {
                if (window.__mectrlAccountFlyoutOnDocClick) {
                    document.removeEventListener('click', window.__mectrlAccountFlyoutOnDocClick, true);
                }
            } catch (_) { }

            window.__mectrlAccountFlyoutOnDocClick = (e) => {
                if (!flyoutOpen) return;
                const target = e.target;
                if (wrapper.contains(target)) return;
                setFlyoutOpen(false);
            };
            document.addEventListener('click', window.__mectrlAccountFlyoutOnDocClick, true);

            wrapper.append(profile, flyout);
            desktopLogin.replaceWith(wrapper);
        }
    } catch (_) { }

    try {
        const mobileLogin = document.getElementById('mobile-nav-login');
        if (mobileLogin) {
            const profile = document.createElement('a');
            profile.href = '/cuenta/';
            profile.id = 'mobile-nav-login';
            profile.className = 'mobile-nav-link mobile-nav-avatar-link';
            profile.setAttribute('aria-label', 'Perfil');
            if (username) profile.title = username;

            const img = document.createElement('img');
            img.src = resolvedAvatarUrl;
            img.alt = username ? `Avatar de ${username}` : 'Avatar de usuario';
            img.decoding = 'async';
            img.loading = 'lazy';
            img.className = 'session-avatar session-avatar-mobile';
            img.addEventListener('error', () => { img.src = fallbackAvatarUrl; }, { once: true });

            const iconWrap = document.createElement('span');
            iconWrap.className = 'mobile-nav-icon';
            iconWrap.appendChild(img);

            const label = document.createElement('span');
            label.className = 'mobile-nav-label';
            label.textContent = 'Cuenta';

            profile.append(iconWrap, label);
            mobileLogin.replaceWith(profile);
        }
    } catch (_) { }
}

function _isBlankField(v) {
    return v === null || v === undefined || String(v).trim() === '';
}

function _isPlaceholderUsername(v) {
    if (v === null || v === undefined) return false;
    const s = String(v).trim();
    if (!s) return false;
    return /^Usuario_[a-f0-9]{4,}$/i.test(s);
}

function _isProfileIncomplete(user) {
    if (!user) return false;

    // Si el país es Perú (guardado o auto-detectado por timezone), country_iso2 vacío no es "incompleto"
    let countryAutoDetected = false;
    const savedCountry = (user.country_iso2 || '').toLowerCase().trim();
    if (savedCountry === 'pe') {
        countryAutoDetected = true;
    } else if (!savedCountry) {
        try {
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (typeof tz === 'string' && /^America\/Lima$/i.test(tz.trim())) countryAutoDetected = true;
        } catch (_) { }
    }

    return _isBlankField(user.game_username) || _isPlaceholderUsername(user.game_username)
        || _isBlankField(user.public_display_name) || _isPlaceholderUsername(user.public_display_name)
        || (!countryAutoDetected && _isBlankField(user.country_iso2))
        || _isBlankField(user.whatsapp_phone_e164);
}

/** Refresca el estado de navegación del header desde datos de sesión frescos. */
async function _refreshNavStateFromSession() {
    try {
        const session = await fetchDashboardSession();
        if (!session) return;
        const username = sanitizeUsername(session.user?.game_username);
        if (!username) return;
        const publicDisplayName = sanitizeUsername(
            session.user?.public_display_name ??
            session.user?.publicDisplayName ??
            session.user?.public_name ??
            session.user?.publicName
        );
        const avatarUrl = resolveSessionAvatarUrl(session);
        const isVerified = session.user?.is_verified;
        applyAuthenticatedNavState({ username, publicDisplayName, avatarUrl, isVerified });
    } catch (_) { }
}

try { window.__banano_refreshNavState = _refreshNavStateFromSession; } catch (_) { }

function initUserSessionState() {
    const desktopLogin = document.getElementById('header-login');
    const mobileLogin = document.getElementById('mobile-nav-login');
    if (!desktopLogin && !mobileLogin) return;

    void (async () => {
        const session = await fetchDashboardSession();
        if (!session) return;

        const username = sanitizeUsername(session.user?.game_username);
        const publicDisplayName = sanitizeUsername(
            session.user?.public_display_name ??
            session.user?.publicDisplayName ??
            session.user?.public_name ??
            session.user?.publicName
        );

        const avatarUrl = resolveSessionAvatarUrl(session);
        const isVerified = session.user?.is_verified;
        if (username) {
            try { applyAuthenticatedNavState({ username, publicDisplayName, avatarUrl, isVerified }); } catch (_) { }
        }

        // Onboarding: si el perfil está incompleto, mostrar modal (excepto en /cuenta/)
        const pathname = window.location.pathname || '';
        const isCuentaPage = /^\/cuenta(\/|$)/i.test(pathname);
        if (!isCuentaPage && _isProfileIncomplete(session.user)) {
            try {
                const { ensureOnboardingSetupModal } = await import('/cuenta/onboarding_modal.js');
                ensureOnboardingSetupModal({
                    dashboardData: session.rawData,
                    apiEndpoint: 'j5r'
                });
            } catch (err) {
                try { console.warn('[Onboarding] Failed to load onboarding modal:', err); } catch (_) { }
            }
        }
    })();
}

function installGlobalClickAndEsc() {
    document.body.addEventListener('click', handleAppClick);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            const imageContainer = event.target.closest('.item-image-container');
            if (imageContainer) {
                event.preventDefault();
                imageContainer.click();
            }
        }
    });
}

/**
 * Navegación desde el cajón del carrito hacia la página del artículo.
 * El módulo `cart/` emite `banano:cart-view` (cancelable) con la línea pulsada;
 * si aquí se resuelve la URL, se cancela el evento para que el cajón se cierre
 * ya con la ruta en marcha y no se pierda el foco de retorno.
 */
function installCartNavigationListener() {
    document.addEventListener('banano:cart-view', (event) => {
        const offerId = event.detail?.offerId;
        if (!offerId) return;
        const result = findItemDataById(offerId);
        if (!result) {
            Logger.with('Cart').warn('No item data found in cart for offer id', { offerId });
            return;
        }
        const url = result.isStatic
            ? buildProductPageUrl(result.data)
            : buildEntryPageUrl(result.data);
        if (!url) return;
        event.preventDefault();
        navigateToPage(url);
    });
}

function installHeaderStickyObserver() {
    const header = document.getElementById('site-header');
    const dropdown = document.getElementById('section-dropdown');
    if (!header || !dropdown) return;

    const observer = new IntersectionObserver(
        ([entry]) => {
            header.classList.toggle('site-header--unpinned', entry.isIntersecting);
        },
        { threshold: 0 }
    );

    observer.observe(dropdown);
}

// endregion static-event-installers

/** Manejador de clics universal (delegación). */
function handleAppClick(event) {
    const cardWrapper = event.target.closest('.item-card-wrapper');
    const pageActionButton = event.target.closest('#view-item [data-action], #view-producto [data-action]');

    if (cardWrapper) {
        handleCardClick(cardWrapper, event);
        return;
    }
    if (pageActionButton) {
        ItemPages.handlePageAction(pageActionButton, event);
        return;
    }
}

/** Procesa clic en tarjeta de producto: navegación a su página. */
function handleCardClick(cardWrapper, event) {
    const isStatic = cardWrapper.dataset.isStatic === 'true';

    const isImageClick = event.target.closest('.item-image-container');
    if (!isImageClick) return;

    // Clics con modificador (Ctrl/Cmd/Shift/Alt): navegación nativa del enlace
    // (pestaña/ventana nueva, etc.).
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

    let url = null;
    if (isStatic) {
        const productId = cardWrapper.dataset.productId;
        const itemDataFromMap = staticProductsMap.get(productId);
        if (!itemDataFromMap) {
            Logger.with('Renderer').error('No data found for static card in map', { id: productId });
            return;
        }
        url = buildProductPageUrl(itemDataFromMap);
    } else {
        const offerId = cardWrapper.dataset.offerId;
        const entry = APIData.details.get(offerId);
        if (!entry) {
            Logger.with('Renderer').error('No data found for dynamic card', { id: offerId });
            return;
        }
        url = buildEntryPageUrl(entry);
    }
    if (url) {
        event.preventDefault();
        navigateToPage(url);
    }
}

// region events
function setupDynamicEventListeners() {
    Logger.with('Events').info('Enabling dynamic listeners after render');

    if (document.querySelector('.horizontal-scroll-section')) {
        initializeCarousels();
    }

}

// region SideNavManager
class SideNavManager {
    constructor() {
        this.sideNavWrapper = null;
        this.isInitialized = false;
        this.resizeObserver = null;
        this.mutationObserver = null;
        
        this.init();
    }
    
    init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.findAndInitialize(), { once: true });
        } else {
            this.findAndInitialize();
        }
    }
    
    findAndInitialize() {
        this.sideNavWrapper = document.getElementById('side-nav-wrapper');
        
        if (this.sideNavWrapper) {
            this.initializeNav();
        } else {
            this.pollForElement();
        }
    }
    
    pollForElement() {
        this.mutationObserver = new MutationObserver(() => {
            this.sideNavWrapper = document.getElementById('side-nav-wrapper');
            if (this.sideNavWrapper) {
                this.mutationObserver.disconnect();
                this.initializeNav();
            }
        });
        
        this.mutationObserver.observe(document.body || document.documentElement, {
            childList: true,
            subtree: true
        });
        
        let attempts = 0;
        const maxAttempts = 5;
        const poll = () => {
            if (attempts++ >= maxAttempts) return;
            
            this.sideNavWrapper = document.getElementById('side-nav-wrapper');
            if (this.sideNavWrapper) {
                if (this.mutationObserver) {
                    this.mutationObserver.disconnect();
                }
                this.initializeNav();
            } else {
                setTimeout(poll, 50 * Math.pow(2, attempts));
            }
        };
        
        setTimeout(poll, 50);
    }
    
    initializeNav() {
        if (this.isInitialized) return;
        this.sideNavWrapper.classList.remove('opacity-0', 'invisible', 'hidden');
        this.isInitialized = true;
    }
    
    destroy() {
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
            this.resizeObserver = null;
        }
        if (this.mutationObserver) {
            this.mutationObserver.disconnect();
            this.mutationObserver = null;
        }
        this.isInitialized = false;
    }
}

(() => {
    if (typeof window !== 'undefined' && typeof document !== 'undefined') {
        window._sideNavManager = new SideNavManager();
    }
})();

// region carousel-engine
/** Wrapper: delega a carousel-interaction.js (geometría, ancho de tile,
 *  flechas, arrastre y snap). Si el módulo no cargó, el carrusel sigue
 *  siendo usable: es scroll nativo. */
function initializeCarousels() {
    if (window.ShopCarousel) {
        window.ShopCarousel.init();
    } else {
        console.error('[Carousel] carousel-interaction.js not loaded.');
    }
}
// endregion carousel-engine

function setupVideoPlaybackControls(container) {
    const video = container.querySelector('video');
    const playPauseBtn = container.querySelector('[data-action="toggle-playback"]');

    if (!video || !playPauseBtn) return;

    const svgPath = playPauseBtn.querySelector('svg path');
    const playIconPath = "M12 22.5C17.799 22.5 22.5 17.799 22.5 12C22.5 6.20101 17.799 1.5 12 1.5C6.20101 1.5 1.5 6.20101 1.5 12C1.5 17.799 6.20101 22.5 12 22.5ZM10.045 7.19665L17.25 12L10.045 16.8033C9.45335 17.1978 8.69617 16.6243 8.91554 15.9479L10.1959 12L8.91554 8.05207C8.69617 7.3757 9.45335 6.80223 10.045 7.19665Z";
    const pauseIconPath = "M5.5 3C4.94772 3 4.5 3.44772 4.5 4V20C4.5 20.5523 4.94772 21 5.5 21H9.5C10.0523 21 10.5 20.5523 10.5 20V4C10.5 3.44772 10.0523 3 9.5 3H5.5ZM14.5 3C13.9477 3 13.5 3.44772 13.5 4V20C13.5 20.5523 13.9477 21 14.5 21H18.5C19.0523 21 19.5 20.5523 19.5 20V4C19.5 3.44772 19.0523 3 18.5 3H14.5Z";

    const togglePlayback = (event) => {
        event.stopPropagation();
        video.paused ? video.play() : video.pause();
    };

    // Icono de carga oficial (LoadingIcon de fortnite.com) mientras el video
    // no tiene datos suficientes para reproducirse.
    const loadingIconPath = "M22,12c0,5.5-4.5,10-10,10S2,17.5,2,12h2c0,4.4,3.6,8,8,8s8-3.6,8-8-3.6-8-8-8v-2c5.5,0,10,4.5,10,10Z";

    const svg = playPauseBtn.querySelector('svg');
    const setIcon = (paused) => {
        if (playPauseBtn.classList.contains('is-loading')) return;
        if (svgPath) svgPath.setAttribute('d', paused ? playIconPath : pauseIconPath);
        if (svg) svg.classList.toggle('is-pause', !paused);
    };
    const setLoading = (loading) => {
        playPauseBtn.classList.toggle('is-loading', loading);
        if (loading) {
            if (svgPath) svgPath.setAttribute('d', loadingIconPath);
            if (svg) svg.classList.remove('is-pause');
        } else {
            setIcon(video.paused);
        }
    };
    video.addEventListener('play', () => setIcon(false));
    video.addEventListener('pause', () => setIcon(true));
    video.addEventListener('loadstart', () => setLoading(true));
    video.addEventListener('waiting', () => setLoading(true));
    video.addEventListener('canplay', () => setLoading(false));
    video.addEventListener('playing', () => setLoading(false));

    playPauseBtn.addEventListener('click', togglePlayback);
    video.addEventListener('click', togglePlayback);

    if (video.readyState < 3) setLoading(true);
    else setIcon(video.paused);
}

/* Preferencia global de sonido de los videos de objetos: recuerda la última
 * elección de mute/unmute del usuario (por defecto, sonido activado). */
const VIDEO_SOUND_PREF_KEY = 'itemVideoSoundOn';

function videoSoundPrefOn() {
    try { return localStorage.getItem(VIDEO_SOUND_PREF_KEY) !== '0'; } catch (_) { return true; }
}

function setVideoSoundPref(on) {
    try { localStorage.setItem(VIDEO_SOUND_PREF_KEY, on ? '1' : '0'); } catch (_) { }
}

function setupVolumeControls(container) {
    const video = container.querySelector('video');
    const volumeBtn = container.querySelector('[data-action="toggle-mute"]');

    if (!video || !volumeBtn) return;

    const svgGroup = volumeBtn.querySelector('svg g');

    const toggleMute = (event) => {
        event.stopPropagation();
        video.muted = !video.muted;
        setVideoSoundPref(!video.muted);
    };

    const updateVolumeIcon = () => {
        if (!svgGroup) return;
        while (svgGroup.firstChild) svgGroup.removeChild(svgGroup.firstChild);
        const svgNS = 'http://www.w3.org/2000/svg';
        if (video.muted || video.volume === 0) {
            const p1 = document.createElementNS(svgNS, 'path');
            p1.setAttribute('d', 'M3 7.80506H4.65246L8.25 4.5H10.125V19.4999H8.25L4.52534 16.1948H3C2.17157 16.1948 1.5 15.5233 1.5 14.6948V9.30506C1.5 8.47663 2.17157 7.80506 3 7.80506Z');
            const p2 = document.createElementNS(svgNS, 'path');
            p2.setAttribute('d', 'M14.6344 9.92808C14.1219 9.41551 14.1219 8.58449 14.6344 8.07192C15.147 7.55936 15.978 7.55936 16.4906 8.07192L18.3468 9.92818L20.2029 8.07212C20.7155 7.55956 21.5465 7.55956 22.0591 8.07212C22.5716 8.58468 22.5716 9.41571 22.0591 9.92828L20.203 11.7843L22.059 13.6404C22.5716 14.153 22.5716 14.984 22.059 15.4965C21.5465 16.0091 20.7155 16.0091 20.2029 15.4965L18.3468 13.6405L16.4906 15.4967C15.978 16.0093 15.147 16.0093 14.6344 15.4967C14.1219 14.9842 14.1219 14.1531 14.6344 13.6406L16.4907 11.7843L14.6344 9.92808Z');
            svgGroup.append(p1, p2);
            volumeBtn.setAttribute('aria-label', 'Activar sonido');
        } else {
            const p1 = document.createElementNS(svgNS, 'path');
            p1.setAttribute('d', 'M3 7.80506H4.65246L8.25 4.5H10.125V19.4999H8.25L4.52534 16.1948H3C2.17157 16.1948 1.5 15.5233 1.5 14.6948V9.30506C1.5 8.47663 2.17157 7.80506 3 7.80506Z');
            const p2 = document.createElementNS(svgNS, 'path');
            p2.setAttribute('d', 'M17.3981 7.88733C16.9907 7.28782 16.1743 7.13215 15.5748 7.53963C14.9753 7.94711 14.8197 8.76342 15.2271 9.36293C16.2634 10.8876 16.2334 12.1765 15.9986 13.0744C15.8762 13.5427 15.6915 13.9234 15.5386 14.1843C15.4629 14.3136 15.397 14.41 15.3542 14.469C15.3329 14.4984 15.3176 14.5183 15.31 14.5279L15.3055 14.5335C14.8437 15.0859 14.9131 15.9083 15.4628 16.3754C16.0152 16.8447 16.8435 16.7774 17.3129 16.2249L16.3126 15.3751C17.3129 16.2249 17.3133 16.2244 17.3138 16.2238L17.3148 16.2226L17.317 16.22L17.3221 16.2139L17.3351 16.1983C17.3449 16.1863 17.3571 16.1712 17.3713 16.1531C17.3998 16.117 17.4368 16.0687 17.4802 16.0088C17.5668 15.8891 17.6801 15.7218 17.8035 15.5113C18.0489 15.0925 18.3421 14.4888 18.5383 13.7383C18.9407 12.1988 18.9107 10.1127 17.3981 7.88733Z');
            const p3 = document.createElementNS(svgNS, 'path');
            p3.setAttribute('d', 'M14.1347 9.58672C13.8373 8.92569 13.0602 8.63096 12.3992 8.92843C11.7382 9.22589 11.4435 10.0029 11.7409 10.6639C12.1268 11.5215 12.1086 12.1815 12.0288 12.5955C11.9874 12.8098 11.9269 12.9704 11.8833 13.0672C11.8616 13.1154 11.8446 13.1467 11.8372 13.1599L11.8351 13.1636C11.4484 13.7621 11.6102 14.5627 12.2035 14.9632C12.8043 15.3687 13.6201 15.2104 14.0257 14.6096L12.9378 13.8753C14.0257 14.6096 14.0261 14.609 14.0265 14.6084L14.0274 14.6071L14.0292 14.6044L14.0331 14.5986L14.0419 14.585L14.0511 14.5707L14.0637 14.5507C14.08 14.5246 14.0995 14.492 14.1215 14.4531C14.1655 14.3754 14.2197 14.272 14.2771 14.1444C14.3917 13.8897 14.521 13.5346 14.6063 13.0926C14.7795 12.1941 14.7613 10.9792 14.1347 9.58672Z');
            const p4 = document.createElementNS(svgNS, 'path');
            p4.setAttribute('d', 'M18.4849 4.98139C19.0475 4.52429 19.8741 4.6098 20.3312 5.17239C23.0914 8.56956 23.1015 12.0307 22.4075 14.5931C22.0655 15.8561 21.5553 16.9004 21.1333 17.6276C20.9212 17.9929 20.7286 18.2831 20.5856 18.4862C20.514 18.5879 20.4546 18.6681 20.411 18.7254C20.3892 18.7541 20.3714 18.7771 20.3579 18.7942L20.3411 18.8154L20.3353 18.8227L20.332 18.8266C20.3316 18.8272 20.3312 18.8277 19.3478 18.0287L20.3312 18.8277C19.8741 19.3903 19.0475 19.4758 18.4849 19.0187C17.9234 18.5625 17.8371 17.7382 18.2912 17.1757L18.2925 17.1741L18.2932 17.1733C18.2935 17.1729 18.2939 17.1724 19.3125 18L18.2939 17.1724L18.2932 17.1733L18.2948 17.1711C18.2995 17.1652 18.3085 17.1537 18.3212 17.137C18.3466 17.1035 18.3872 17.0489 18.4394 16.9748C18.5439 16.8264 18.6941 16.6009 18.8629 16.31C19.2026 15.7247 19.6065 14.894 19.8738 13.9069C20.3985 11.9694 20.4087 9.43052 18.2939 6.82769C17.8368 6.26511 17.9223 5.43849 18.4849 4.98139Z');
            svgGroup.append(p1, p2, p3, p4);
            volumeBtn.setAttribute('aria-label', 'Silenciar');
        }
    };

    video.addEventListener('volumechange', updateVolumeIcon);
    volumeBtn.addEventListener('click', toggleMute);

    updateVolumeIcon();
}


/**
 * Iconos de los títulos de sección, por id de sección. Inline (no en el
 * sprite del index.php): el de dados usa <defs>/<mask> con ids internos,
 * prefijados para no chocar con otros ids del documento. Heredan el color
 * del título (currentColor) y escalan con su tamaño de fuente (em vía CSS).
 * Para añadir otro icono, basta con sumar su markup aquí.
 */
const SECTION_TITLE_ICON_MARKUP = {
    'section-random-offer':
        '<defs>'
        + '<rect id="dice-f" width="384" height="384" rx="30"/>'
        + '<circle id="dice-d" r="46"/>'
        + '<mask id="dice-m1" fill="#000"><use href="#dice-f" fill="#fff"/><use href="#dice-d" x="192" y="192"/></mask>'
        + '<mask id="dice-m3" fill="#000"><use href="#dice-f" fill="#fff"/><use href="#dice-d" x="192" y="192"/><use href="#dice-d" x="92" y="292"/><use href="#dice-d" x="292" y="92"/></mask>'
        + '<mask id="dice-m5" fill="#000"><use href="#dice-f" fill="#fff"/><use href="#dice-d" x="192" y="192"/><use href="#dice-d" x="92" y="92"/><use href="#dice-d" x="292" y="92"/><use href="#dice-d" x="92" y="292"/><use href="#dice-d" x="292" y="292"/></mask>'
        + '</defs>'
        + '<g>'
        + '<use href="#dice-f" mask="url(#dice-m1)" transform="matrix(.904-.522-.904-.522 640 616)"/>'
        + '<use href="#dice-f" mask="url(#dice-m5)" transform="matrix(-.868-.501 0 1 616 650)"/>'
        + '<use href="#dice-f" mask="url(#dice-m3)" transform="matrix(.868-.501 0 1 664 650)"/>'
        + '</g>',
    'section-bestsellers-carousel':
        '<path d="M8 16c3.3 0 6-2 6-5.5 0-1.5-.5-4-2.5-6 .2 1.5-1.2 2-1.2 2C11 4 9 .5 6 0c.4 2 .5 4-2 6-1.2 1-2 2.7-2 4.5C2 14 4.7 16 8 16m0-1c-1.7 0-3-1-3-2.8 0-.8 .2-2 1.2-3C6.1 10 7 10.5 7 10.5c-.4-1.2 .5-3.2 2-3.5-.2 1-.2 2 1 3 .6-.5 1-1.4 1-2.2C11 14 9.7 15 8 15"/>',
};

const SECTION_TITLE_ICON_VIEWBOX = {
    // Recortado al bounding box real del dibujo (medido con getBBox) para
    // que el dado se vea igual de grande que los demás iconos, cuyo
    // contenido llena su viewBox.
    'section-random-offer': '282 215 716 819',
    'section-bestsellers-carousel': '0 0 16 16',
};

/** Devuelve el <svg> del icono del título de la sección, o null si no tiene. */
function buildSectionTitleIcon(sectionId) {
    const key = Object.keys(SECTION_TITLE_ICON_MARKUP)
        .find(prefix => sectionId.startsWith(prefix));
    if (!key) return null;

    const tpl = document.createElement('template');
    tpl.innerHTML =
        '<svg class="svg-icon section-title-icon" viewBox="' + SECTION_TITLE_ICON_VIEWBOX[key]
        + '" fill="currentColor" aria-hidden="true" focusable="false">'
        + SECTION_TITLE_ICON_MARKUP[key]
        + '</svg>';
    return tpl.content.firstElementChild;
}

function renderSectionSkeletons(sections, { append = false, prepend = false, isSkeletonPlaceholder = false } = {}) {
    const containers = {
        store: document.getElementById('viaregalo-content'),
        sideNav: document.getElementById('side-nav-wrapper'),
        smartNav: document.getElementById('section-nav-items'),
        dropdown: document.getElementById('section-dropdown-list'),
    };
    const templates = {
        section: document.getElementById('section-skeleton-template'),
        empty: document.getElementById('empty-state-template'),
        dropdownItem: document.getElementById('dropdown-item-template'),
    };

    if (!containers.store) {
        if (typeof Logger !== 'undefined' && Logger.with) {
            Logger.with('Renderer').error('Critical: #viaregalo-content not found.');
        }
        return;
    }

    if (!append && !prepend) {
        try {
            containers.store.replaceChildren();
            if (containers.smartNav) containers.smartNav.replaceChildren();
            if (containers.dropdown) containers.dropdown.replaceChildren();
        } catch (err) {
            console.warn('[Renderer] Cleanup failed:', err);
        }
    }

    if (!sections || sections.length === 0 || !templates.section) {
        if (!append && !prepend) {
            if (templates.empty) {
                containers.store.appendChild(templates.empty.content.cloneNode(true));
            }
            notifyCatalogChange('store');
        }
        return;
    }

    const navDotsFragment = document.createDocumentFragment();
    const navTextFragment = document.createDocumentFragment();
    const dropdownFragment = document.createDocumentFragment();
    const storeFragment = document.createDocumentFragment();

    const isActuallySkeletonPlaceholder = isSkeletonPlaceholder ||
        (sections.length > 0 && sections.every(s =>
            s._isSkeletonPlaceholder === true ||
            s.title === 'Cargando...' ||
            s.id?.startsWith('placeholder-section')
        ));

    if (isActuallySkeletonPlaceholder && containers.smartNav) {
        const existingLoader = containers.smartNav.querySelector('.side-nav__global-loader');
        if (existingLoader) {
            existingLoader.remove();
        }

        const loaderContainer = document.createElement('div');
        loaderContainer.className = 'side-nav__global-loader';
        loaderContainer.setAttribute('role', 'status');
        loaderContainer.setAttribute('aria-label', 'Cargando navegación');

        const spinnerSize = 32;
        const spinnerHTML = `
            <div class="spinner-container" style="width: ${spinnerSize}px; height: ${spinnerSize}px;">
                <svg class="spinner-group" viewBox="0 0 50 50" style="width: ${spinnerSize}px; height: ${spinnerSize}px;">
                    <circle class="spinner-track" cx="25" cy="25" r="20" fill="none" stroke-width="4" stroke="rgba(255,255,255,0.15)"></circle>
                    <circle class="spinner-arc" cx="25" cy="25" r="20" fill="none" stroke-width="4" stroke="currentColor" stroke-linecap="round" stroke-dasharray="31.4 94.2"></circle>
                </svg>
            </div>
        `;
        loaderContainer.innerHTML = spinnerHTML;

        containers.smartNav.appendChild(loaderContainer);
    }

    sections.forEach(section => {
        const fragment = templates.section.content.cloneNode(true);
        const sectionWrapper = fragment.firstElementChild || fragment.querySelector('div');
        if (!sectionWrapper) {
            Logger.with('Renderer').warn('Section wrapper not found in template', { sectionId: section.id });
            return;
        }

        sectionWrapper.id = section.id;
        sectionWrapper.dataset.catalogSection = '';
        if (isOneByOneSection(section.id)) sectionWrapper.dataset.catalogAux = '';
        if (section.layoutId) sectionWrapper.dataset.layoutId = section.layoutId;
        sectionWrapper.dataset.titleDesktop = section.title;
        sectionWrapper.dataset.titleMobile = section.mobileTitle || section.title;

        const h2 = sectionWrapper.querySelector('h2');
        if (h2) {
            h2.replaceChildren();

            const titleIcon = buildSectionTitleIcon(section.id);
            if (titleIcon) h2.appendChild(titleIcon);

            if (section.id.startsWith('section-random-offer')) {
                const spanWrap = document.createElement('span');
                const titleDesktop = document.createElement('span');
                titleDesktop.className = 'title-desktop';
                titleDesktop.textContent = section.title;
                spanWrap.appendChild(titleDesktop);
                const titleMobile = document.createElement('span');
                titleMobile.className = 'title-mobile';
                titleMobile.textContent = section.mobileTitle || section.title;
                spanWrap.appendChild(titleMobile);
                h2.appendChild(spanWrap);

                const infoBtn = document.createElement('button');
                infoBtn.className = 'section-info-btn';
                infoBtn.setAttribute('aria-label', 'Más información');
                infoBtn.setAttribute('aria-haspopup', 'true');
                infoBtn.setAttribute('aria-expanded', 'false');

                const svgNS = 'http://www.w3.org/2000/svg';
                const infoSvg = document.createElementNS(svgNS, 'svg');
                infoSvg.setAttribute('viewBox', '0 0 24 24');
                infoSvg.setAttribute('fill', 'currentColor');
                infoSvg.setAttribute('width', '16');
                infoSvg.setAttribute('height', '16');

                const dotRadius = 2.5;
                const stalkWidth = 5;
                const stalkHeight = 11.5;
                const gap = 2.5;

                const totalHeight = (dotRadius * 2) + gap + stalkHeight;
                const startY = (24 - totalHeight) / 2;

                const dot = document.createElementNS(svgNS, 'circle');
                dot.setAttribute('cx', '12');
                dot.setAttribute('cy', String(startY + dotRadius));
                dot.setAttribute('r', String(dotRadius));

                const bar = document.createElementNS(svgNS, 'rect');
                bar.setAttribute('x', String(12 - (stalkWidth / 2)));
                bar.setAttribute('y', String(startY + (dotRadius * 2) + gap));
                bar.setAttribute('width', String(stalkWidth));
                bar.setAttribute('height', String(stalkHeight));
                bar.setAttribute('rx', '2');

                infoSvg.append(dot, bar);
                infoBtn.appendChild(infoSvg);

                const pop = document.createElement('div');
                pop.className = 'section-info-popover';
                pop.textContent = 'Se actualizan todos los días a las 7:05 p. m.';
                pop.setAttribute('role', 'tooltip');

                // Ancla relativa junto al texto: el botón va pegado al título
                // y el popover se posiciona respecto al botón, no al header.
                const infoWrap = document.createElement('span');
                infoWrap.className = 'section-info-wrap';
                infoWrap.append(infoBtn, pop);
                h2.appendChild(infoWrap);

                (function setupPopover(btn, popover) {
                    const toggle = (event) => {
                        event.stopPropagation();
                        const isOpen = popover.classList.toggle('is-open');
                        btn.setAttribute('aria-expanded', isOpen);
                    };

                    const close = () => {
                        if (popover.classList.contains('is-open')) {
                            popover.classList.remove('is-open');
                            btn.setAttribute('aria-expanded', 'false');
                        }
                    };

                    btn.addEventListener('click', toggle);
                    popover.addEventListener('click', e => e.stopPropagation());
                    document.addEventListener('click', close);

                    const observer = new MutationObserver((mutations) => {
                        for (const mutation of mutations) {
                            if (Array.from(mutation.removedNodes).includes(btn)) {
                                document.removeEventListener('click', close);
                                observer.disconnect();
                                break;
                            }
                        }
                    });

                    observer.observe(btn.parentElement, { childList: true });
                })(infoBtn, pop);

            } else {
                h2.appendChild(document.createTextNode(section.title));
            }
        } else {
            Logger.with('Renderer').warn('<h2> not found in section template', { sectionId: section.id });
        }

        const itemsContainer = sectionWrapper.querySelector('.section-items-container');
        if (itemsContainer) {
            itemsContainer.classList.add('skeleton-items');
            if (section.layout === 'carousel') {
                sectionWrapper.classList.add('horizontal-scroll-section');
            }
        }

        storeFragment.appendChild(fragment);

        if (!isActuallySkeletonPlaceholder) {
            try {
                if (containers.smartNav && typeof createSectionNavItem === 'function') {
                    const navItem = createSectionNavItem(section);
                    if (navItem) {
                        navTextFragment.appendChild(navItem);
                    }
                }
            } catch (err) {
                console.warn('[Renderer] Section nav item creation failed:', err);
            }
        }

        if (containers.dropdown && templates.dropdownItem) {
            try {
                const liFrag = templates.dropdownItem.content.cloneNode(true);
                const a = liFrag.querySelector('a.section-link-item');
                if (a) {
                    a.href = `#${section.id}`;
                    a.dataset.section = section.id;
                    a.textContent = section.title;
                }
                dropdownFragment.appendChild(liFrag);
            } catch (err) {
                console.warn('[Renderer] Dropdown item creation failed:', err);
            }
        }
    });

    try {
        if (prepend) {
            containers.store.prepend(storeFragment);
            if (containers.smartNav && navTextFragment.hasChildNodes()) {
                const firstItem = containers.smartNav.querySelector('a.section-nav__link');
                if (firstItem) {
                    firstItem.before(navTextFragment);
                } else {
                    containers.smartNav.appendChild(navTextFragment);
                }
            }
            if (containers.dropdown && dropdownFragment.hasChildNodes()) {
                containers.dropdown.prepend(dropdownFragment);
            }
        } else {
            containers.store.appendChild(storeFragment);
            
            if (containers.smartNav && navTextFragment.hasChildNodes()) {
                if (!isActuallySkeletonPlaceholder && append && typeof NavAnimator !== 'undefined') {
                    NavAnimator.appendWithInterpolation(containers.smartNav, navTextFragment);
                } else {
                    containers.smartNav.appendChild(navTextFragment);
                }
            }
            
            if (containers.dropdown && dropdownFragment.hasChildNodes()) {
                containers.dropdown.appendChild(dropdownFragment);
            }
        }
    } catch (err) {
        console.warn('[Renderer] DOM insertion failed:', err);
    }
    notifyCatalogChange('store');
}

/** Crea un elemento de navegación lateral para una sección (link > dot + text). */
function createSectionNavItem(section) {
    if (!section || !section.id || !section.title) return null;

    const link = document.createElement('a');
    link.href = `#${section.id}`;
    link.className = 'section-nav__link';
    link.dataset.section = section.id;
    link.setAttribute('aria-label', `Ir a ${section.title}`);

    const dot = document.createElement('span');
    dot.className = 'section-nav__dot';
    dot.setAttribute('aria-hidden', 'true');

    const text = document.createElement('span');
    text.className = 'section-nav__text';
    text.textContent = section.title;

    link.appendChild(dot);
    link.appendChild(text);

    return link;
}

/** Fase 2: rellena los esqueletos con tarjetas reales. */
function populateSectionItems(sections, { runFinalSetup = true } = {}) {
    const populateOne = (section, index) => {
        const sectionWrapper = document.getElementById(section.id);
        if (!sectionWrapper) {
            Logger.with('Renderer').warn('Section wrapper not found; skipping population', { id: section.id, index });
            return;
        }

        const headerEl = sectionWrapper.querySelector('h2');
        const itemsContainer = sectionWrapper.querySelector('.section-items-container');

        if (headerEl) {
            headerEl.classList.remove('skeleton-header');
        } else {
            Logger.with('Renderer').warn('Header element not found when populating section', { id: section.id });
        }
        if (itemsContainer) {
            itemsContainer.classList.remove('skeleton-items');
        } else {
            Logger.with('Renderer').error('Items container not found for section; cannot insert cards', { id: section.id });
            return;
        }

        // Teardown antes de re-poblar: libera listeners/observers de un
        // carrusel previo dentro del contenedor (evita fugas por refresco).
        if (window.ShopCarousel) window.ShopCarousel.destroyWithin(itemsContainer);
        itemsContainer.textContent = '';

        let appendedCount = 0;
        const forceOneByOne = isOneByOneSection(section.id);

        if (section.layout === 'carousel') {
            const carouselTpl = document.getElementById('carousel-wrapper-template');
            if (carouselTpl) {
                const carFrag = carouselTpl.content.cloneNode(true);
                const itemsWrap = carFrag.querySelector('.shop-carousel__track');
                // Fija el ancho de tile ANTES de insertar/pintar (sin parpadeo).
                // itemsContainer ya está en el DOM y ocupa el ancho de la sección.
                if (itemsWrap && window.ShopCarousel) {
                    window.ShopCarousel.applyTileWidth(itemsWrap, itemsContainer);
                }
                (section.items || []).forEach(itemData => {
                    const card = createItemCardNode(itemData, { isInCarousel: false, forceOneByOne });
                    if (card) {
                        itemsWrap.appendChild(card);
                        appendedCount++;
                    }
                });
                itemsContainer.appendChild(carFrag);
                Logger.with('Renderer').debug('Carousel section populated', { id: section.id, appended: itemsWrap?.childElementCount || 0 });
            }
        } else {
            (section.items || []).forEach(itemData => {
                if (!itemData) return;
                const wrapper = document.createElement('div');
                // En secciones especiales (bestsellers/random), forzar span 1x1
                if (!forceOneByOne) {
                    const span = getTileSizeClass(itemData.tileSize);
                    if (span > 1) {
                        wrapper.classList.add('span-card-' + span);
                    }
                    const tileSizeClass = itemData.tileSize ? 'tile-' + itemData.tileSize.toLowerCase().replace(/_/g, '-') : '';
                    if (tileSizeClass) wrapper.classList.add(tileSizeClass);
                }
                const card = createItemCardNode(itemData, { isInCarousel: false, forceOneByOne });
                if (card) {
                    wrapper.appendChild(card);
                    appendedCount++;
                }
                itemsContainer.appendChild(wrapper);
            });
            Logger.with('Renderer').debug('Grid section populated', { id: section.id, appended: itemsContainer.childElementCount, forceOneByOne });
        }

        if (appendedCount === 0) {
            Logger.with('Renderer').warn('No cards appended for section; rendering empty state', { id: section.id });
            const emptyTpl = document.getElementById('empty-state-template');
            if (emptyTpl) {
                itemsContainer.appendChild(emptyTpl.content.cloneNode(true));
            }
        }
        notifyCatalogChange('store');

        if (runFinalSetup && index === sections.length - 1) {
            if (document.querySelector('.horizontal-scroll-section')) initializeCarousels();
            resyncBadgePositions();
            Logger.with('Renderer').info('Incremental rendering completed');
            try { SectionSpinner.hide('store'); } catch (_) { }
        }
    };

    if (runFinalSetup) {
        // Escalonado para SHOP_UPDATED (30+ secciones de golpe)
        sections.forEach((section, index) => {
            setTimeout(() => populateOne(section, index), index * 50);
        });
    } else {
        // Ruta streaming: el worker ya pausa entre chunks
        sections.forEach((section, index) => populateOne(section, index));
    }
}

/**
 * Modo del marco de medios del host `.item-tile` (`data-tile-media`, ver
 * style.css). Sin atributo rige `shop`, de modo que las miniaturas del
 * carrito y cualquier otro host con la anatomía del tile no lo necesitan.
 *
 *  - shop: tile oficial de la tienda — el arte sobresale por arriba de la
 *    caja inferior del 85 % y se encuadra por tipo de objeto.
 *  - cover: el marco es la tarjeta completa (proporción propia) y el arte
 *    la cubre de borde a borde; productos del catálogo Vía Cuenta.
 */
const TILE_MEDIA = Object.freeze({ SHOP: 'shop', COVER: 'cover' });

function createItemCardNode(itemData, options = {}) {
    const normalizedOptions = typeof options === 'boolean' ? { isInCarousel: options } : (options || {});
    const { isInCarousel = false, forceOneByOne = false } = normalizedOptions;

    let { finalPrice, isStaticProduct = false } = itemData || {};
    if (typeof finalPrice === 'undefined' || !isFinite(Number(finalPrice))) {
        Logger.with('Renderer').warn('Normalizing missing/invalid price to 0 for rendering', { itemData });
        finalPrice = 0;
        try { itemData.finalPrice = 0; } catch (_) { }
    }

    const tpl = document.getElementById('item-card-template');
    if (!tpl?.content?.firstElementChild) {
        Logger.with('Renderer').error('item-card-template not found or is invalid');
        return null;
    }
    const frag = tpl.content.cloneNode(true);

    const elements = {
        card: frag.querySelector('.item-card-wrapper'),
        imgContainer: frag.querySelector('.item-image-container'),
        img: frag.querySelector('img.item-image'),
        rarity: frag.querySelector('.item-meta__rarity'),
        type: frag.querySelector('.item-meta__type'),
        title: frag.querySelector('h3'),
        infoBody: frag.querySelector('.card-info-body'),
        divider: frag.querySelector('.item-meta__divider'),
        priceBlock: frag.querySelector('.price-block'),
        vbucksBlock: frag.querySelector('.vbucks-block'),
    };

    // rarity/type/divider are optional — the card meta row was removed from
    // the template (official item-shop tiles show only title + price).
    const optionalElements = new Set(['rarity', 'type', 'divider']);
    if (Object.entries(elements).some(([key, el]) => !el && !optionalElements.has(key))) {
        Logger.with('Renderer').error('Cloned template is missing critical elements');
        return null;
    }

    const renderStrategies = {
        static: (data) => {
            const { displayName, singularType, categoryText, finalPrice, basePrice, imagen_url, offerId, isAgotado } = data;
            elements.card.dataset.productId = offerId;
            elements.card.dataset.nombre = displayName;
            const productUrl = buildProductPageUrl(data);
            if (productUrl) elements.imgContainer.setAttribute('href', productUrl);
            elements.card.dataset.precio = finalPrice;
            elements.card.dataset.optimizedImage = optimizeImageUrl(imagen_url);
            elements.imgContainer.style.cssText = data.bgStyle || 'background-color: #333;';
            setImageWithOfferFallback(elements.img, optimizeImageUrl(imagen_url), displayName, offerId);
            if (elements.rarity) elements.rarity.textContent = categoryText;
            if (elements.type) elements.type.textContent = singularType;
            elements.title.textContent = displayName;

            if (elements.divider && (!categoryText || !singularType)) elements.divider.style.display = 'none';
            elements.vbucksBlock.remove();
            elements.priceBlock.appendChild(createPriceSpans(finalPrice, basePrice, {
                baseClass: 'text-sm price-base-strikethrough line-through',
                finalClass: 'font-black text-base sm:text-lg',
            }));
            if (isAgotado) {
                elements.card.classList.add('card--agotado');
            }
        },
        dynamic: (data) => {
            const displayInfo = getItemDisplayInfo(data);
            if (!displayInfo) return;
            const { displayName, singularType, mainItem, credits } = displayInfo;
            const categoryText = (mainItem.series?.displayValue || mainItem.rarity?.displayValue || 'Común').toUpperCase().replace('SERIES DE ', '');

            const renderImages = getRenderImages(data);
            const { nonLego } = splitLegoAndNonLegoImages(renderImages);
            const optimizedImageUrl = optimizeImageUrl(getEntryCardImage(data, mainItem, nonLego));

            elements.card.dataset.offerId = data.offerId;
            elements.card.dataset.rarityId = mainItem.rarity?.value || 'common';
            const entryUrl = buildEntryPageUrl(data);
            if (entryUrl) elements.imgContainer.setAttribute('href', entryUrl);
            elements.card.dataset.filterTypes = Array.from(getEntryFilterTypes(data)).join('|');
            elements.card.dataset.filterPrice = String(getEntryPriceVBucks(data));
            applyTileFraming(elements.card, getEntryTileFraming(data, displayInfo));

            elements.card.dataset.optimizedImage = optimizedImageUrl;

            elements.imgContainer.style.cssText = data.bgStyle;
            const overlayRgb = overlayRgbFromEntryColors(data.colors);
            if (overlayRgb) elements.card.style.setProperty('--card-overlay-rgb', overlayRgb);
            setImageWithOfferFallback(elements.img, optimizedImageUrl, displayName, data.offerId);

            if (nonLego.length > 1) {
                const styleUrls = nonLego.map((entry) => optimizeImageUrl(entry.image));
                elements.img.classList.add('style-switcher');
                elements.img.dataset.styles = styleUrls.join(',');
                elements.img.dataset.currentStyleIndex = '0';
                StyleRotationManager.register(elements.img);
            }

            const { finalSolesPrice, baseSolesPrice, discountInfo, spinDiscount } = calculateSolesPrice(data.finalPrice, data.offerId);

            // Construcción de badges: descuento, spin reward
            const finalDiscountInfo = data.automaticDiscountInfo || discountInfo;
            const discountBadgeNode = finalDiscountInfo ? buildDiscountBadgeNode(finalDiscountInfo, baseSolesPrice, finalSolesPrice) : null;
            const spinRewardBadgeNode = buildSpinRewardBadgeNode(spinDiscount);

            if (discountBadgeNode?.dataset.position === 'bottom-left') {
                elements.imgContainer.appendChild(discountBadgeNode);
            }

            // Elementos para esquina superior izquierda
            const topElements = [];
            if (discountBadgeNode && discountBadgeNode.dataset.position !== 'bottom-left') {
                topElements.unshift(discountBadgeNode);
            }
            if (spinRewardBadgeNode) topElements.push(spinRewardBadgeNode);

            if (topElements.length > 0) {
                const badgesContainer = document.createElement('div');
                badgesContainer.className = 'card-badges-container';
                topElements.forEach(el => badgesContainer.appendChild(el));
                elements.imgContainer.appendChild(badgesContainer);
            }

            if (credits && jamTrackHasVideo(data)) {
                elements.imgContainer.appendChild(buildCardVideoBadgeNode());
            }

            if (elements.rarity) elements.rarity.textContent = categoryText;
            if (elements.type) elements.type.textContent = singularType;

            if (credits) {
                // Pista: el título viaja en un marquee de una línea (se
                // desplaza en hover si desborda) y el artista va encima. La
                // búsqueda del catálogo sigue viendo «Título - Artista».
                elements.card.dataset.filterName = displayName;
                elements.title.classList.add('card-title--marquee');
                elements.title.replaceChildren(createCardMarqueeNode(credits.title));
                if (credits.artist) elements.title.before(buildCardArtistNode(credits.artist));
            } else {
                elements.title.textContent = displayName;
            }

            if (elements.divider && (!categoryText || !singularType)) elements.divider.style.display = 'none';

            const vbucksPriceEl = elements.vbucksBlock.querySelector('.vbucks-price');
            if (vbucksPriceEl) {
                vbucksPriceEl.textContent = data.finalPrice.toLocaleString('es-ES');
            }

            const priceDiscountColorClass = spinDiscount ? 'text-spin-reward' : 'text-green-500';
            elements.priceBlock.appendChild(createPriceSpans(finalSolesPrice, baseSolesPrice, {
                baseClass: 'text-sm price-base-strikethrough line-through',
                finalClass: 'font-black text-base sm:text-lg',
                discountColorClass: priceDiscountColorClass,
            }));

            // Marquee oficial: placa superior (sobre el título) y franja de
            // tipo/etiquetas (bajo el precio, revelada en hover).
            const plateNode = buildCardPlateNode(data);
            if (plateNode) elements.infoBody.prepend(plateNode);

            // La franja va como hermana del cuerpo (fuera de su padding)
            // para ocupar el ancho completo de la tarjeta.
            const overlay = elements.card.querySelector('.card-info-overlay');
            const taglineNode = buildCardTaglineNode(data, displayInfo);
            if (overlay && taglineNode) overlay.appendChild(taglineNode);
        }
    };

    elements.card.dataset.isStatic = String(isStaticProduct);
    elements.card.dataset.tileMedia = isStaticProduct ? TILE_MEDIA.COVER : TILE_MEDIA.SHOP;

    CardMarqueeManager.init();

    const strategy = isStaticProduct ? renderStrategies.static : renderStrategies.dynamic;
    strategy(itemData);

    const optionalNodesFragment = document.createDocumentFragment();
    if (itemData.preRenderedBadgesNode) optionalNodesFragment.appendChild(itemData.preRenderedBadgesNode.cloneNode(true));
    if (itemData.preRenderedTimerNode) optionalNodesFragment.appendChild(itemData.preRenderedTimerNode.cloneNode(true));
    if (optionalNodesFragment.hasChildNodes()) {
        elements.imgContainer.appendChild(optionalNodesFragment);
    }
    if (itemData.preRenderedOverlayNode) {
        elements.card.appendChild(itemData.preRenderedOverlayNode.cloneNode(true));
    }

    return elements.card;
}

/**
 * Imagen principal de la tarjeta de una entrada dinámica (misma prioridad
 * en la tienda y en el carrito): carátula, render no-LEGO, render crudo,
 * featured, icono y placeholder.
 */
function getEntryCardImage(data, mainItem, nonLego) {
    return mainItem?.albumArt
        || nonLego[0]?.image
        || data.newDisplayAsset?.renderImages?.[0]?.image
        || mainItem?.images?.featured
        || mainItem?.images?.icon
        || CONFIG.PLACEHOLDER_IMAGE;
}

/**
 * Encuadre 1×1 del arte de una entrada dinámica, en las claves que
 * consume el host `.item-tile` (ver style.css). Compartido por la tarjeta
 * y por el producto que viaja al carrito para que ambos pinten igual.
 *
 *  - itemId: Epic asigna la variante de encuadre por cosmético; permite
 *    excepciones por objeto.
 *  - isDynamicBundle: ofertas multi-cosmético sin lote propio (devName con
 *    precio "-1 MtxCurrency"); la tienda oficial usa el tile de lotes.
 *  - isCarOffer: carrocería sola o lote de coche; encuadre propio.
 *
 * @returns {{itemType: string, itemId: string|null, isCarOffer: boolean, isDynamicBundle: boolean}}
 */
function getEntryTileFraming(data, { pluralType, mainItem, isBundle }) {
    return {
        itemType: pluralType,
        itemId: mainItem.id || null,
        isCarOffer: (data.cars?.length > 0)
            && !(data.brItems?.length) && !(data.instruments?.length) && !(data.tracks?.length),
        isDynamicBundle: !isBundle && typeof data.devName === 'string' && data.devName.endsWith('for -1 MtxCurrency'),
    };
}

/** Publica el encuadre como atributos `data-*` del host `.item-tile`. */
function applyTileFraming(host, { itemType, itemId, isCarOffer, isDynamicBundle }) {
    host.dataset.itemType = itemType;
    if (itemId) host.dataset.itemId = itemId;
    if (isDynamicBundle) host.dataset.dynamicBundle = '1';
    if (isCarOffer) host.dataset.carOffer = '1';
}

function getItemDisplayInfo(entry) {
    if (!entry) return null;

    const isBundle = !!entry.bundle;
    let mainItem, displayName, singularType, pluralType;
    let credits = null;

    if (isBundle) {
        displayName = entry.bundle.name;
        singularType = CONFIG.TYPE_TRANSLATIONS_SINGULAR['bundle'];
        pluralType = CONFIG.TYPE_TRANSLATIONS_PLURAL['bundle'];

        // Derivar rareza/serie del ítem más representativo del lote
        const allItemsInBundle = [
            ...(entry.brItems || []),
            ...(entry.instruments || []),
            ...(entry.cars || []),
            ...(entry.tracks || [])
        ];

        const representativeItem = allItemsInBundle.find(item => item.series) || allItemsInBundle[0] || {};

        mainItem = {
            name: entry.bundle.name,
            description: entry.bundle.info,
            rarity: representativeItem.rarity,
            series: representativeItem.series,
            images: { icon: entry.bundle.image }
        };

    } else {
        const isJamTrack = entry.tracks && entry.tracks.length > 0;

        if (isJamTrack) {
            mainItem = entry.tracks[0];
        } else {
            mainItem = [...(entry.brItems || []), ...(entry.instruments || []), ...(entry.cars || [])][0] || {};
        }

        displayName = entry.displayName || mainItem.name || mainItem.title || 'Artículo Desconocido';
        if (!displayName) return null;
        if (isJamTrack) credits = deriveJamTrackCredits(mainItem, displayName);

        let typeId = mainItem.type?.value || 'default';
        if (isJamTrack) typeId = 'jamtrack';
        if (typeId === 'shoe' || typeId === 'shoes') typeId = 'footwear';

        singularType = CONFIG.TYPE_TRANSLATIONS_SINGULAR[typeId] || 'Cosmético';
        pluralType = CONFIG.TYPE_TRANSLATIONS_PLURAL[typeId] || 'Artículos';
    }

    // Override de categoría/tipo del Gestor de Capa de Datos: texto visual libre
    // (p.ej. "Skin"/"Atuendo") que sustituye la etiqueta calculada, tanto para
    // lotes como para ítems sueltos. No afecta type.value (filtros/buckets).
    const typeOverride = (typeof entry.displayTypeOverride === 'string') ? entry.displayTypeOverride.trim() : '';
    if (typeOverride) {
        singularType = typeOverride;
        pluralType = typeOverride;
    }

    return { displayName, singularType, pluralType, mainItem, isBundle, credits };
}

/**
 * Tipos (plural) con los que una oferta participa en el filtro por tipo: los
 * de todos los cosméticos que contiene y, si es un lote, también "Lotes".
 * @returns {Set<string>}
 */
function getEntryFilterTypes(entry) {
    const types = new Set();
    if (entry.bundle) types.add(CONFIG.TYPE_TRANSLATIONS_PLURAL['bundle']);
    if (entry.tracks?.length) types.add(CONFIG.TYPE_TRANSLATIONS_PLURAL['jamtrack']);
    const items = [...(entry.brItems || []), ...(entry.instruments || []), ...(entry.cars || [])];
    if (!items.length && !entry.tracks?.length && entry.mainType) {
        items.push({ type: { value: entry.mainType } });
    }
    for (const item of items) {
        let typeId = item.type?.value || 'default';
        if (typeId === 'shoe' || typeId === 'shoes') typeId = 'footwear';
        if (item.type?.backendValue?.startsWith('Sparks')) typeId = 'instrument';
        types.add(CONFIG.TYPE_TRANSLATIONS_PLURAL[typeId] || CONFIG.TYPE_TRANSLATIONS_PLURAL['default']);
    }
    return types;
}

/** Precio en paVos de una oferta para el filtro por precio (0 si no aplica). */
function getEntryPriceVBucks(entry) {
    const raw = typeof entry.finalPrice === 'number' ? entry.finalPrice : entry.price?.finalPrice;
    const num = Number(raw);
    return Number.isFinite(num) ? num : 0;
}

function optimizeImageUrl(originalUrl, options = {}) {
    if (!originalUrl || typeof originalUrl !== 'string') {
        return CONFIG.PLACEHOLDER_IMAGE;
    }

    if (
        originalUrl.includes('via.placeholder.com') ||
        originalUrl.includes('wsrv.nl')
    ) {
        return originalUrl;
    }

    const { w = 800, h = null, fit = null, q = 95, output = 'webp' } = options;

    let optimized = `https://wsrv.nl/?url=${encodeURIComponent(originalUrl)}&w=${w}&q=${q}&output=${output}`;
    if (h) optimized += `&h=${h}`;
    if (fit) optimized += `&fit=${fit}`;
    return optimized;
}

function getRenderImages(entry) {
    const asset = entry && entry.newDisplayAsset;
    if (!asset || !Array.isArray(asset.renderImages)) return [];
    return asset.renderImages.filter(Boolean);
}

function splitLegoAndNonLegoImages(renderImages) {
    if (!Array.isArray(renderImages)) return { nonLego: [], lego: null };
    const nonLego = [];
    let lego = null;
    for (const img of renderImages) {
        if (!img || !img.image) continue;
        if (img.productTag === 'Product.Juno') {
            if (!lego) lego = img;
        } else {
            nonLego.push(img);
        }
    }
    return { nonLego, lego };
}

function fadeSwapImage(imgEl, newSrc, options = {}) {
    if (!imgEl || !newSrc) return;
    const duration = typeof options.duration === 'number' ? options.duration : 150;
    const immediate = !!options.immediate;
    try {
        imgEl.style.opacity = 0;
        const run = () => {
            imgEl.src = newSrc;
            imgEl.style.opacity = 1;
        };
        if (immediate && typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(run);
        } else {
            const timer = (typeof window !== 'undefined' && window.setTimeout) ? window.setTimeout : setTimeout;
            timer(run, immediate ? 0 : duration);
        }
    } catch (_) { }
}

const StyleRotationManager = (() => {
    const images = new Set();
    const visibleImages = new Set();
    const preloadedUrls = new Set();
    let observer = null;
    let intervalId = null;
    let initialized = false;
    let prefersReducedMotion = false;

    function ensureInitialized() {
        if (initialized) return;
        initialized = true;
        try {
            if (typeof window !== 'undefined' && window.matchMedia) {
                prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            }
        } catch (_) {
            prefersReducedMotion = false;
        }

        if (typeof window !== 'undefined' && 'IntersectionObserver' in window && !prefersReducedMotion) {
            observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    const el = entry.target;
                    if (entry.isIntersecting && entry.intersectionRatio > 0) {
                        visibleImages.add(el);
                    } else {
                        visibleImages.delete(el);
                    }
                });
            }, { root: null, threshold: 0.1 });
        }

        if (!prefersReducedMotion && typeof window !== 'undefined' && !intervalId) {
            intervalId = window.setInterval(tick, 5000);
        }
    }

    function preloadImageStyles(imgEl) {
        const preloader = document.getElementById('image-preloader');
        if (!preloader) return;
        const stylesAttr = imgEl.dataset.styles;
        if (!stylesAttr) return;
        const urls = stylesAttr.split(',').map((u) => u.trim()).filter(Boolean);
        urls.forEach((url) => {
            if (!url || preloadedUrls.has(url)) return;
            preloadedUrls.add(url);
            try {
                const preloadImg = new Image();
                preloadImg.decoding = 'async';
                preloadImg.src = url;
                preloader.appendChild(preloadImg);
            } catch (_) { }
        });
    }

    function tick() {
        if (prefersReducedMotion || visibleImages.size === 0) return;
        visibleImages.forEach((imgEl) => {
            if (!imgEl || !imgEl.isConnected) {
                visibleImages.delete(imgEl);
                images.delete(imgEl);
                return;
            }
            const stylesAttr = imgEl.dataset.styles;
            if (!stylesAttr) return;
            const styles = stylesAttr.split(',').map((u) => u.trim()).filter(Boolean);
            if (styles.length <= 1) return;
            let currentIndex = parseInt(imgEl.dataset.currentStyleIndex || '0', 10);
            if (!Number.isFinite(currentIndex) || currentIndex < 0) currentIndex = 0;
            const nextIndex = (currentIndex + 1) % styles.length;
            const nextSrc = styles[nextIndex];
            if (!nextSrc) return;
            try {
                fadeSwapImage(imgEl, nextSrc, { duration: 150 });
                imgEl.dataset.currentStyleIndex = String(nextIndex);
            } catch (_) { }
        });
    }

    function register(imgEl) {
        if (!imgEl) return;
        ensureInitialized();
        images.add(imgEl);
        if (!prefersReducedMotion) {
            if (observer) {
                observer.observe(imgEl);
            } else {
                visibleImages.add(imgEl);
            }
            preloadImageStyles(imgEl);
        }
    }

    function unregister(imgEl) {
        if (!imgEl) return;
        images.delete(imgEl);
        visibleImages.delete(imgEl);
        if (observer) {
            try { observer.unobserve(imgEl); } catch (_) { }
        }
    }

    function destroy() {
        if (observer) {
            try { observer.disconnect(); } catch (_) { }
            observer = null;
        }
        if (intervalId) {
            clearInterval(intervalId);
            intervalId = null;
        }
        images.clear();
        visibleImages.clear();
        preloadedUrls.clear();
        initialized = false;
    }

    return { register, unregister, destroy };
})();

/** Crea un icono SVG de V-Bucks reutilizable (cacheado). */
const createVBucksIcon = (() => {
    let template = null;
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const XLINK_NS = 'http://www.w3.org/1999/xlink';
    return (className = 'inline-block w-7 h-7 -mt-1 mr-1') => {
        if (!template) {
            const svg = document.createElementNS(SVG_NS, 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('aria-hidden', 'true');
            const use = document.createElementNS(SVG_NS, 'use');
            use.setAttribute('href', '#icon-pavos');
            use.setAttributeNS(XLINK_NS, 'xlink:href', '#icon-pavos');
            svg.appendChild(use);
            template = svg;
        }
        const node = template.cloneNode(true);
        node.setAttribute('class', className);
        return node;
    };
})();

/**
 * Crea un icono SVG inline a partir del sprite (<use href="#icon-...">).
 *
 * Fuente única de verdad para los iconos de la tienda: los glifos viven como
 * <symbol> en el sprite de index.php; aquí solo se referencian. Sustituye a
 * los <i class="fas fa-..."> de Font Awesome. El color se hereda vía
 * `currentColor` y el tamaño vía `font-size` (clase base `svg-icon`), así que
 * acepta utilidades de tamaño/color (text-xl, text-red-500, mr-2, etc.).
 *
 * @param {string} name  Nombre del icono sin prefijo (ej. 'bolt', 'clock').
 * @param {string} [extraClass]  Clases adicionales (Tailwind u otras).
 * @returns {SVGSVGElement}
 */
const createIcon = (() => {
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const XLINK_NS = 'http://www.w3.org/1999/xlink';
    const cache = new Map();
    return (name, extraClass = '') => {
        let template = cache.get(name);
        if (!template) {
            const svg = document.createElementNS(SVG_NS, 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('focusable', 'false');
            const use = document.createElementNS(SVG_NS, 'use');
            use.setAttribute('href', '#icon-' + name);
            use.setAttributeNS(XLINK_NS, 'xlink:href', '#icon-' + name);
            svg.appendChild(use);
            template = svg;
            cache.set(name, template);
        }
        const node = template.cloneNode(true);
        node.setAttribute('class', ('svg-icon ' + extraClass).trim());
        return node;
    };
})();

/** Convierte "YYYY-MM-DD HH:MM:SS" (UTC) a Date. */
function formatUtcDateString(dateString) {
    if (!dateString) return null;
    return new Date(dateString.replace(' ', 'T') + 'Z');
}

/** Genera texto de etiqueta de descuento (ej: "-15%") para productos estáticos. */
function generarTextoEtiqueta(producto) {
    const precioBase = parseFloat(producto.precio_base);
    const precioOferta = parseFloat(producto.precio_oferta);

    if (isNaN(precioOferta) || isNaN(precioBase) || precioBase === 0) {
        return '';
    }
    if (precioOferta === precioBase) {
        return '';
    }

    if (precioOferta < precioBase) {
        const descuento = Math.round(((precioBase - precioOferta) / precioBase) * 100);
        return `-${descuento}%`;
    }

    const aumento = Math.round(((precioOferta - precioBase) / precioBase) * 100);
    return `+${aumento}%`;
}

/**
 * Construye las filas de expiración (divisor + contador "Expira: …") de una
 * oferta estática a partir de su fecha de expiración. Fuente única usada tanto
 * por las tarjetas del grid como por el modal de producto, para que el
 * `badge-expiry` salga igual en ambos sitios.
 *
 * @param {Object} producto  Producto/data con `oferta_fecha_expiracion` y
 *                           `urgencia_horas` (campos del backend).
 * @returns {{ parts: HTMLElement[], urgent: boolean } | null}
 *          `parts` son los nodos a anexar al badge; `urgent` indica si queda
 *          menos del umbral de urgencia (para teñir el badge de rojo). Devuelve
 *          `null` si no hay expiración futura.
 */
function buildStaticOfferExpiryParts(producto) {
    if (!producto || !producto.oferta_fecha_expiracion) return null;
    const diff = formatUtcDateString(producto.oferta_fecha_expiracion) - new Date();
    if (!(diff > 0)) return null;

    const d = Math.floor(diff / 86400000);
    const h = Math.floor((diff % 86400000) / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    const timerText = d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m`;

    const divider = document.createElement('div');
    divider.className = 'badge-divider';
    divider.setAttribute('aria-hidden', 'true');

    const expiry = document.createElement('div');
    expiry.className = 'badge-expiry';
    const expiryIcon = createIcon('clock', 'badge-expiry__icon');
    const expiryText = document.createElement('span');
    expiryText.textContent = `Expira: ${timerText}`;
    expiry.append(expiryIcon, expiryText);

    const urgent = diff < (parseInt(producto.urgencia_horas) || 24) * 3600000;
    return { parts: [divider, expiry], urgent };
}

/**
 * Cliente de transporte del gateway AES. Las vistas (a través del api/client en
 * main.js) hablan en términos de dominio; aquí se hace el POST a /web.php con el
 * código ofuscado de acción y se decodifica el sync-frame. Soporta cancelación
 * vía un AbortSignal externo (el router lo aborta al salir de la vista).
 */
async function gatewayFetch(action, { signal, params } = {}) {
    const controller = new AbortController();
    const onAbort = () => { try { controller.abort(); } catch (_) { } };
    if (signal) {
        if (signal.aborted) controller.abort();
        else signal.addEventListener('abort', onAbort, { once: true });
    }
    const timeoutId = setTimeout(() => { try { controller.abort(); } catch (_) { } }, 7000);
    try {
        let body = 'action=' + encodeURIComponent(action);
        if (params && typeof params === 'object') {
            for (const [key, value] of Object.entries(params)) {
                if (value === undefined || value === null) continue;
                body += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(String(value));
            }
        }
        const response = await fetch('/web.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            signal: controller.signal,
        });
        if (!response.ok) throw new Error('Error al conectar con la API de productos.');
        return await _resolveSyncFrame(await response.json());
    } finally {
        clearTimeout(timeoutId);
        if (signal) signal.removeEventListener('abort', onAbort);
    }
}

/** Muestra/oculta el spinner de sección de la vista Vía Cuenta. */
function viaCuentaSpinner(on) {
    const viaCuentaWrapper = document.getElementById('view-viacuenta');
    if (!viaCuentaWrapper) return;
    try {
        if (on) SectionSpinner.show('viacuenta', viaCuentaWrapper, 48);
        else SectionSpinner.hide('viacuenta');
    } catch (_) { }
}

/**
 * Categorías del catálogo Vía Cuenta en su orden de presentación. `key` es
 * la clave del payload de `api.account.getCatalog`; `color`/`glow`/`type`
 * viajan a la tarjeta vía mapStaticProductToCardData.
 */
const VIA_CUENTA_SECTIONS = Object.freeze([
    { key: 'Pavos', title: 'COMPRA DE PAVOS', icon: 'icon-pavos', isImage: false, color: '#1E40AF', glow: '#2c528280', type: 'PAQUETE DE PAVOS' },
    { key: 'Club', title: 'COMPRA DE CLUB', icon: 'icon-club', isImage: false, color: '#553c9a', glow: '#553c9a80', type: 'MEMBRESÍA DEL CLUB' },
    { key: 'Pases', title: 'PASES VÍA REGALO', icon: 'https://res.cloudinary.com/dq3xsjgwi/image/upload/v1749757137/2_dx7rre.png', isImage: true, color: '#f59e0b', glow: '#f59e0b80', type: 'PASE DE BATALLA / LOTE' },
    { key: 'Packs', title: 'PACKS', icon: 'icon-box-open', isImage: false, color: '#3a3a3e', glow: '#4a556880', type: 'PACK ESPECIAL' },
]);

/**
 * Retira del outlet las secciones y estados de un render anterior. Sólo toca
 * lo que el catálogo pintó: el encabezado sr-only estático y el spinner de
 * sección (que gestiona su propio ciclo de vida) se conservan.
 */
function clearViaCuentaCatalog(root) {
    root.querySelectorAll(':scope > [data-catalog-section], :scope > [data-catalog-state]')
        .forEach((node) => node.remove());
}

/** Icono del encabezado de categoría: imagen remota o símbolo del sprite. */
function buildViaCuentaSectionIcon(config) {
    if (config.isImage) {
        const img = document.createElement('img');
        img.src = config.icon;
        img.className = 'w-8 h-8 mr-4';
        img.alt = '';
        img.decoding = 'async';
        return img;
    }
    const svgNS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('class', 'w-8 h-8 mr-4');
    svg.setAttribute('fill', 'currentColor');
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS(svgNS, 'use');
    use.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', `#${config.icon}`);
    svg.appendChild(use);
    return svg;
}

/** Tarjeta de un producto estático, registrada para deep links y checkout. */
function buildViaCuentaCard(producto, config) {
    if (!producto || producto.id === undefined || producto.id === null) return null;
    const cardData = mapStaticProductToCardData(producto, config);
    staticProductsMap.set(String(producto.id), cardData);
    const node = createItemCardNode(cardData, { isInCarousel: false });
    return node instanceof HTMLElement ? node : null;
}

/**
 * `<section>` de una categoría: encabezado + lista de tarjetas. Cada `<li>`
 * es la celda del grid que catalog-filter alterna con `hidden`. Devuelve
 * null si ninguna tarjeta llegó a renderizarse.
 */
function buildViaCuentaSection(config, productos) {
    const list = document.createElement('ul');
    list.className = 'viacuenta-grid';
    list.setAttribute('role', 'list');
    for (const producto of productos) {
        const card = buildViaCuentaCard(producto, config);
        if (!card) continue;
        const cell = document.createElement('li');
        cell.appendChild(card);
        list.appendChild(cell);
    }
    if (!list.childElementCount) return null;

    const headingId = `viacuenta-section-${config.key.toLowerCase()}`;
    const heading = document.createElement('h2');
    heading.id = headingId;
    heading.className = 'section-header';
    heading.append(buildViaCuentaSectionIcon(config), ` ${config.title}`);

    const section = document.createElement('section');
    section.className = 'viacuenta-section';
    section.dataset.catalogSection = '';
    section.setAttribute('aria-labelledby', headingId);
    section.append(heading, list);
    return section;
}

/**
 * Renderiza el catálogo Vía Cuenta (pavos / club / pases / packs) en una
 * sola inserción al DOM. Las categorías ausentes o sin productos se omiten.
 */
function renderViaCuentaCatalog(productosPorCategoria) {
    const root = document.getElementById('view-viacuenta');
    if (!root) return;
    const catalog = (productosPorCategoria && typeof productosPorCategoria === 'object') ? productosPorCategoria : {};

    const fragment = document.createDocumentFragment();
    for (const config of VIA_CUENTA_SECTIONS) {
        const productos = catalog[config.key];
        if (!Array.isArray(productos) || productos.length === 0) continue;
        const section = buildViaCuentaSection(config, productos);
        if (section) fragment.appendChild(section);
    }

    clearViaCuentaCatalog(root);
    root.appendChild(fragment);
    notifyCatalogChange('viacuenta');
}

/** Renderiza el estado de error de la vista Vía Cuenta. */
function renderViaCuentaError(error) {
    const root = document.getElementById('view-viacuenta');
    if (!root) return;
    Logger.with('Renderer').error('Failed to populate Via Cuenta section', error);
    const box = document.createElement('div');
    box.className = 'text-center py-16';
    box.dataset.catalogState = 'error';
    box.setAttribute('role', 'alert');
    const icon = createIcon('warning', 'text-4xl text-red-500 mb-4');
    const h2 = document.createElement('h2');
    h2.className = 'text-2xl font-bold mb-2';
    h2.textContent = 'No se pudieron cargar los productos';
    const p = document.createElement('p');
    p.className = 'empty-state-text';
    p.textContent = 'Por favor, intenta de nuevo más tarde.';
    box.append(icon, h2, p);
    clearViaCuentaCatalog(root);
    root.appendChild(box);
    notifyCatalogChange('viacuenta');
}

function showError() {
    document.getElementById('loading')?.classList.add('hidden');
    document.getElementById('error-message')?.classList.remove('hidden');
    document.getElementById('view-viaregalo')?.removeAttribute('hidden');
}

/** Recalcula posiciones de badges cuando coexisten descuento y banner. */
function resyncBadgePositions() {
    Logger.with('Renderer').debug('Resyncing badge positions');
    document.querySelectorAll('#viaregalo-content .item-image-container').forEach(container => {
        const offerContainer = container.querySelector('#offer-badge-container');
        const bannerBadge = container.querySelector('.item-badge:not(#offer-badge-container div)');
        if (offerContainer && bannerBadge && offerContainer.classList.contains('top-0')) {
            requestAnimationFrame(() => {
                bannerBadge.style.left = `${offerContainer.offsetWidth + 8}px`;
            });
        }
    });
}

// region item-pages
/**
 * ============================================================================
 * PÁGINAS DE OBJETOS (/objeto/{tipo}/{slug}--{id}) Y PRODUCTOS (/producto/...)
 * ============================================================================
 * Sustituye al antiguo ItemModal: cada objeto de la tienda tiene ahora su
 * propia URL permanente. El render es instantáneo cuando los datos ya están
 * en memoria (APIData / staticProductsMap) y los deep links se resuelven con
 * UNA sola petición agregada al gateway ('k3t' → get_item_page.php) que trae
 * cosmético HQ + disponibilidad + entrada de tienda + video manual + historial.
 *
 * El nodo de media se decide ANTES del primer paint: si hay video manual
 * (campo `manualVideo` del snapshot primordial o `video` del agregado) se
 * monta <video> directamente — la imagen jamás parpadea antes del video.
 */

/** Tipos de la API → segmento de URL en español. */
const TYPE_SLUGS_ES = {
    outfit: 'traje',
    backpack: 'accesorio-mochilero',
    pickaxe: 'pico',
    glider: 'ala-delta',
    emote: 'gesto',
    wrap: 'envoltorio',
    shoe: 'calzado',
    shoes: 'calzado',
    footwear: 'calzado',
    contrail: 'estela',
    trail: 'rastro',
    sidekick: 'companero',
    mic: 'instrumento',
    bass: 'instrumento',
    guitar: 'instrumento',
    keyboard: 'instrumento',
    drum: 'instrumento',
    instrument: 'instrumento',
    sparks_bass: 'instrumento',
    jamtrack: 'pista-de-improvisacion',
    sparks_song: 'pista-de-improvisacion',
    carbody: 'coche',
    wheel: 'rueda',
    vehicle_wheel: 'rueda',
    boost: 'potenciador',
    spray: 'grafiti',
    music: 'musica',
    loadingscreen: 'pantalla-de-carga',
    battlebus: 'autobus-de-batalla',
    bundle: 'lote',
    default: 'articulo',
};

/** "Ghoul Trooper" → "ghoul-trooper" (URL-safe, sin acentos). */
function slugifyForUrl(text) {
    return String(text || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 80) || 'objeto';
}

/** offerId 'v2:/abc…' → 'v2_abc…' (segmento de URL válido y reversible). */
function sanitizeOfferIdForUrl(offerId) {
    return String(offerId || '').replace(/[:/]+/g, '_');
}

function typeSlugForItem(item, isJamTrack) {
    if (isJamTrack) return TYPE_SLUGS_ES.jamtrack;
    let typeId = item?.type?.value || 'default';
    if (typeId === 'shoe' || typeId === 'shoes') typeId = 'footwear';
    return TYPE_SLUGS_ES[typeId] || TYPE_SLUGS_ES.default;
}

/** URL de página para una entrada dinámica de la tienda (tarjetas). */
function buildEntryPageUrl(entry) {
    if (!entry) return null;
    const displayInfo = getItemDisplayInfo(entry);
    if (!displayInfo) return null;

    if (displayInfo.isBundle) {
        const slug = slugifyForUrl(displayInfo.displayName);
        return `/via-regalo/lote/${slug}--${sanitizeOfferIdForUrl(entry.offerId)}`;
    }

    const mainItem = displayInfo.mainItem || {};
    const isJamTrack = Array.isArray(entry.tracks) && entry.tracks.length > 0;
    const itemId = mainItem.id;
    if (!itemId) {
        // Ofertas multi-ítem sin bundle: usar la oferta como identificador.
        const slug = slugifyForUrl(displayInfo.displayName);
        return `/via-regalo/lote/${slug}--${sanitizeOfferIdForUrl(entry.offerId)}`;
    }
    const tipo = typeSlugForItem(mainItem, isJamTrack);
    const slug = slugifyForUrl(displayInfo.displayName);
    return `/via-regalo/${tipo}/${slug}--${encodeURIComponent(itemId)}`;
}

/** URL de página para un cosmético individual (subítems). */
function buildCosmeticPageUrl(item) {
    if (!item || !item.id) return null;
    const isJamTrack = !!item.devName && !item.type;
    const tipo = typeSlugForItem(item, isJamTrack);
    const slug = slugifyForUrl(item.name || item.title || item.id);
    return `/via-regalo/${tipo}/${slug}--${encodeURIComponent(item.id)}`;
}

/** URL de página para un producto estático. */
function buildProductPageUrl(product) {
    if (!product) return null;
    const id = product.offerId ?? product.productId ?? product.id;
    if (id === undefined || id === null) return null;
    const slug = slugifyForUrl(product.displayName || product.nombre || 'producto');
    return `/via-cuenta/${slug}--${encodeURIComponent(String(id))}`;
}

/**
 * Degradado de fondo desde los colores crudos de la API (entry.colors).
 * Réplica de generateBackgroundStyle del worker: las entradas que llegan del
 * endpoint agregado (deep links / re-render enriquecido) no traen `bgStyle`
 * pre-calculado, solo `colors`.
 */
function bgStyleFromEntryColors(colors) {
    if (!colors) return null;
    const validColors = [colors.color1, colors.color3, colors.color2]
        .filter(Boolean)
        .map(c => `#${String(c).slice(0, 6)}`);
    if (validColors.length >= 2) {
        return `background-image: linear-gradient(180deg, ${validColors[0]} 0%, ${(validColors[1] ?? validColors[0])} 65%, ${(validColors[2] ?? validColors[1] ?? validColors[0])} 100%);`;
    }
    if (validColors.length === 1) return `background-color: ${validColors[0]};`;
    return null;
}

/**
 * Componentes "r, g, b" del textBackgroundColor crudo de la API (RRGGBBAA).
 * Alimenta --card-overlay-rgb: el tinte del degradado inferior de la tarjeta
 * (.card-info-overlay); sin él, la tarjeta conserva el fondo neutro oscuro.
 */
function overlayRgbFromEntryColors(colors) {
    const hex = String(colors?.textBackgroundColor || '').slice(0, 6);
    if (!/^[0-9a-fA-F]{6}$/.test(hex)) return null;
    return [0, 2, 4].map(i => parseInt(hex.slice(i, i + 2), 16)).join(', ');
}

/** Navegación SPA (History API) con fallback duro. */
function navigateToPage(path) {
    if (!path) return;
    const router = window.__BananoRouter;
    if (router && typeof router.navigate === 'function') {
        router.navigate(path);
    } else {
        window.location.assign(path);
    }
}

/**
 * Busca en los datos ya en memoria la entrada de tienda cuyo protagonista es
 * el id de página (cosmético u oferta saneada). Refleja la lógica del
 * endpoint agregado para que el render local instantáneo coincida con el
 * del servidor.
 * @returns {{ entry: object|null, container: object|null } | null}
 */
function findShopEntryForPageId(pageId) {
    if (!APIData || !APIData.details) return null;
    const lowerId = String(pageId).toLowerCase();
    let container = null;
    for (const entry of APIData.details.values()) {
        if (!entry || typeof entry !== 'object') continue;
        // Ofertas ocultas "a la carte" (layoutId "alc.*", sin sección): existen
        // en el catálogo pero la tienda nunca las muestra como tarjeta, así que
        // no cuentan como venta individual ni como contenedor.
        if (!entry.layout?.name) continue;
        const offerId = String(entry.offerId || '');
        if (offerId && sanitizeOfferIdForUrl(offerId).toLowerCase() === lowerId) {
            return { entry, container: null };
        }
        const items = getAllItemsFromOffer(entry);
        const match = items.find(it => it && String(it.id || '').toLowerCase() === lowerId);
        if (match) {
            // Oferta individual: el id es el protagonista de una entrada no-lote.
            // Muchas ofertas individuales incluyen ítems extra (traje + mochila),
            // así que se compara contra el ítem principal, no contra el conteo.
            const mainId = String(getItemDisplayInfo(entry)?.mainItem?.id || '').toLowerCase();
            if (!entry.bundle && (items.length === 1 || mainId === lowerId)) {
                return { entry, container: null };
            }
            if (!container) container = entry;
        }
    }
    return container ? { entry: null, container } : null;
}

const ItemPages = (() => {
    const PAGE_DATE_OPTIONS = {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    };

    let pageTimerInterval = null;
    let currentYtPlayer = null;

    function formatFullDate(dateStr) {
        if (!dateStr) return null;
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return null;
        try {
            return d.toLocaleDateString('es-PE', PAGE_DATE_OPTIONS);
        } catch (_) {
            return d.toLocaleDateString('es-ES', PAGE_DATE_OPTIONS);
        }
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function stopMedia() {
        try {
            if (currentYtPlayer && typeof currentYtPlayer.destroy === 'function') currentYtPlayer.destroy();
        } catch (_) { }
        currentYtPlayer = null;
        if (pageTimerInterval) { clearInterval(pageTimerInterval); pageTimerInterval = null; }
    }

    /* ------------------------------ CTA / cart --------------------------- */

    /**
     * Host del carrito en la página: el módulo `cart/` (main.js) pinta dentro
     * los botones/stepper y los sincroniza con el store al recibir
     * `banano:cart-mount`. Aquí solo se serializa el producto.
     */
    function buildCartEntry(product, { disabled = false, disabledLabel = null } = {}) {
        const actions = el('div', 'item-page__actions cart-entry');
        actions.id = 'item-page-actions';
        actions.setAttribute('role', 'region');
        actions.setAttribute('aria-label', 'Acciones de compra');
        actions.dataset.cartEntry = '';
        actions.dataset.cartProduct = JSON.stringify({
            ...product,
            ...(disabled ? { disabled: true, disabledLabel } : {}),
        });
        return actions;
    }

    function mountCartEntry() {
        const host = document.getElementById('item-page-actions');
        if (!host || !host.dataset.cartProduct) return;
        document.dispatchEvent(new CustomEvent('banano:cart-mount', { detail: { host } }));
    }

    function unmountCartEntry() {
        const host = document.getElementById('item-page-actions');
        if (!host) return;
        document.dispatchEvent(new CustomEvent('banano:cart-unmount', { detail: { host } }));
    }

    /** Producto de una entrada dinámica de la tienda para el carrito. */
    function entryToCartProduct(entry) {
        const offerId = String(entry.offerId);
        const rawData = APIData?.details?.get(offerId) || entry;
        const displayInfo = getItemDisplayInfo(rawData);
        const { finalSolesPrice, baseSolesPrice } = calculateSolesPrice(rawData.finalPrice, offerId);
        const renderImages = getRenderImages(rawData);
        const { nonLego } = splitLegoAndNonLegoImages(renderImages);
        const framing = displayInfo ? getEntryTileFraming(rawData, displayInfo) : null;
        return {
            offerId,
            isStatic: false,
            name: displayInfo?.displayName || rawData.displayName,
            price: finalSolesPrice,
            priceBase: baseSolesPrice,
            image: optimizeImageUrl(getEntryCardImage(rawData, displayInfo?.mainItem, nonLego)),
            type: framing?.itemType ?? null,
            colors: rawData.colors ?? null,
            card: framing === null ? null : {
                item_type: framing.itemType,
                item_id: framing.itemId,
                is_car_offer: framing.isCarOffer,
                is_dynamic_bundle: framing.isDynamicBundle,
            },
        };
    }

    /** Delegación de acciones dentro de las páginas de objeto/producto. */
    function handlePageAction(target, event) {
        const action = target.dataset.action;
        switch (action) {
            case 'toggle-history': {
                const list = document.getElementById('item-page-occ-list');
                const caret = document.getElementById('item-page-occ-caret');
                list?.classList.toggle('expanded');
                caret?.classList.toggle('expanded');
                target.setAttribute('aria-expanded', String(!!list?.classList.contains('expanded')));
                break;
            }
            case 'select-style': handleStyleSelect(target); break;
            case 'switch-view': handleSwitchView(target); break;
            case 'carousel-prev':
            case 'carousel-next':
            case 'carousel-dot': handleCarousel(target); break;
            case 'share-page': handleSharePage(); break;
        }
    }

    /** Icono de eslabones del botón Copiar (path exacto de fortnite.com). */
    function buildShareLinkIcon() {
        const SVG_NS = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('class', 'svg-icon share-modal__copy-icon');
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('fill-rule', 'evenodd');
        path.setAttribute('clip-rule', 'evenodd');
        path.setAttribute('fill', 'currentColor');
        path.setAttribute('d', 'M9.42653 10.5286L7.58247 8.68502L4.59964 11.6671C2.46614 13.8001 2.46614 17.2718 4.59964 19.4048C5.66698 20.4719 7.06334 21.0003 8.4651 21.0003C9.85812 21.0003 11.2635 20.4716 12.3311 19.4043L15.313 16.4137L13.4695 14.5706L10.4867 17.5527C9.37267 18.6664 7.55754 18.6664 6.44355 17.5527C5.32962 16.439 5.32962 14.6246 6.44355 13.5109L9.42653 10.5286ZM16.4083 15.3187L19.3995 12.3376C21.533 10.2047 21.5335 6.73241 19.4 4.59943C17.2665 2.46651 13.7936 2.46703 11.6602 4.59995L8.67827 7.59054L10.5218 9.43363L13.5046 6.45153C14.6186 5.33781 16.4338 5.33781 17.5477 6.45153C18.6617 7.56519 18.6617 9.37968 17.5477 10.4933L14.5648 13.4756L16.4083 15.3187ZM10.2304 15.6189L15.6133 10.2373L13.7692 8.39366L8.38631 13.7753L10.2304 15.6189Z');
        svg.appendChild(path);
        return svg;
    }

    /** Modal de compartir al estilo fortnite.com: URL + Copiar + Cerrar.
     *  Velo, scroll-lock y clic fuera del panel los aporta window.Backdrop. */
    function openShareModal(url) {
        if (document.querySelector('.share-modal-overlay')) return;

        const overlay = el('div', 'share-modal-overlay backdrop-layer');
        const dialog = el('div', 'share-modal');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'share-modal-title');

        const title = el('h2', 'share-modal__title', 'Compartir este objeto');
        title.id = 'share-modal-title';

        const copyBtn = el('button', 'share-modal__copy');
        copyBtn.type = 'button';
        copyBtn.setAttribute('aria-label', 'Copiar enlace');
        const urlBox = el('div', 'share-modal__url', url);
        const copyLabel = el('span', 'share-modal__copy-label', 'Copiar');
        copyBtn.append(urlBox, copyLabel, buildShareLinkIcon());

        const closeBtn = el('button', 'share-modal__close', 'Cerrar');
        closeBtn.type = 'button';

        dialog.append(title, copyBtn, closeBtn);
        overlay.appendChild(dialog);

        const close = () => {
            document.removeEventListener('keydown', onKeydown);
            overlay.remove();
            window.Backdrop.release('shareModal');
        };
        const onKeydown = (event) => {
            if (event.key === 'Escape') close();
        };

        closeBtn.addEventListener('click', close);
        copyBtn.addEventListener('click', () => {
            if (!navigator.clipboard || !navigator.clipboard.writeText) return;
            navigator.clipboard.writeText(url)
                .then(() => {
                    copyLabel.textContent = 'Copiado';
                    UX.toast.show('Enlace copiado', { type: 'success' });
                    setTimeout(() => { copyLabel.textContent = 'Copiar'; }, 2000);
                })
                .catch(() => { });
        });
        document.addEventListener('keydown', onKeydown);

        document.body.appendChild(overlay);
        window.Backdrop.acquire('shareModal', overlay, close, { lockScroll: true });
        closeBtn.focus();
    }

    /** Compartir la página actual con el modal de compartir. */
    function handleSharePage() {
        openShareModal(window.location.href);
    }

    /** Navegación del carousel de objetos de la oferta (flechas y puntos). */
    function handleCarousel(target) {
        if (!offerCarousel) return;
        const action = target.dataset.action;
        if (action === 'carousel-dot') {
            const idx = Number(target.dataset.index);
            if (!Number.isNaN(idx)) offerCarouselGoTo(idx);
        } else {
            offerCarouselGoTo(offerCarousel.index + (action === 'carousel-next' ? 1 : -1));
        }
    }

    /* ---------------------------- media / styles ------------------------- */

    const mediaState = { currentView: 'fortnite', hasLego: false, styles: [], currentStyleIndex: 0, defaultStyleIndex: 0 };

    // Videos manuales de la página actual: video principal + videos por estilo
    // ({ mainUrl, styles: {styleKey: url}, name, poster, nodes: {url: <video>} }).
    // Los nodos <video> se cachean por URL y se reutilizan entre re-renders y
    // cambios de estilo, para no reiniciar descargas ni mostrar la imagen.
    let manualMedia = null;

    function getManualVideoNode(url) {
        if (!manualMedia) return buildManualVideoNode(url, null, '');
        if (!manualMedia.nodes[url]) {
            manualMedia.nodes[url] = buildManualVideoNode(url, manualMedia.poster, manualMedia.name);
        }
        return manualMedia.nodes[url];
    }

    function pauseManualVideos(exceptNode) {
        if (!manualMedia) return;
        Object.values(manualMedia.nodes).forEach((node) => {
            if (node !== exceptNode) { try { node.pause(); } catch (_) { } }
        });
    }

    /* Autoplay con sonido puede ser bloqueado por el navegador: si falla,
     * reintenta en silencio (sin tocar la preferencia guardada del usuario). */
    function playManualVideo(video) {
        try {
            const p = video.play();
            if (p && p.catch) {
                p.catch(() => {
                    if (!video.muted) {
                        video.muted = true;
                        try { video.play().catch(() => { }); } catch (_) { }
                    }
                });
            }
        } catch (_) { }
    }

    function resetMediaState() {
        mediaState.currentView = 'fortnite';
        mediaState.hasLego = false;
        mediaState.styles = [];
        mediaState.currentStyleIndex = 0;
        mediaState.defaultStyleIndex = 0;
        if (offerCarousel) pauseSlideVideos(offerCarousel, null);
        offerCarousel = null;
    }

    function updateMediaButtons() {
        document.querySelectorAll('#item-page-view-switcher [data-action="switch-view"]').forEach((btn) => {
            const isActive = (btn.dataset.view === 'lego' ? 'lego' : 'fortnite') === mediaState.currentView;
            btn.classList.toggle('active', isActive);
            btn.setAttribute('aria-pressed', String(isActive));
        });
        mediaState.styles.forEach((entry, idx) => {
            if (!entry.button) return;
            entry.button.classList.toggle('active', idx === mediaState.currentStyleIndex);
            entry.button.setAttribute('aria-checked', String(idx === mediaState.currentStyleIndex));
        });
    }

    /** Reemplaza el contenido de la media conservando el overlay del
     * carousel (loader y flechas), que vive dentro del mismo contenedor. */
    function replaceMediaContent(mediaBox, node) {
        [...mediaBox.children].forEach((child) => {
            if (child.classList.contains('item-page__media-loader')
                || child.classList.contains('item-page__carousel-nav')) return;
            child.remove();
        });
        mediaBox.prepend(node);
    }

    function applyMediaState() {
        // Con videos manuales: cada estilo puede tener su propio video; si no lo
        // tiene, el estilo por defecto usa el video principal y los demás su
        // imagen — todo dentro del mismo contenedor y sin recargar nodos.
        const mediaBox = document.getElementById('item-page-media');
        // En lotes el carousel es dueño de la media (el slide 0 ya es el
        // primer objeto incluido, con su propio video si lo tiene): la
        // lógica de video manual no aplica y pisaría el slide montado.
        const carouselOwnsMedia = !!(offerCarousel && offerCarousel.mediaBox === mediaBox
            && offerCarousel.slides[0]?.item);
        if (manualMedia && mediaBox && !carouselOwnsMedia && mediaState.currentView !== 'lego') {
            const styleEntry = mediaState.styles[mediaState.currentStyleIndex];
            const styleKey = styleEntry?.button?.dataset?.styleKey || '';
            const isDefault = mediaState.currentStyleIndex === mediaState.defaultStyleIndex;
            const targetVideo = (styleKey && manualMedia.styles[styleKey])
                || ((isDefault || !styleEntry?.imageUrl) ? manualMedia.mainUrl : null);
            const mountedVideo = mediaBox.querySelector('video');

            if (targetVideo) {
                const video = getManualVideoNode(targetVideo);
                if (!mediaBox.contains(video)) {
                    pauseManualVideos(video);
                    replaceMediaContent(mediaBox, video);
                    attachVideoControls(mediaBox);
                    playManualVideo(video);
                }
                updateMediaButtons();
                return;
            }

            if (mountedVideo) {
                pauseManualVideos(null);
                const targetSrc = styleEntry?.imageUrl || manualMedia.poster || CONFIG.PLACEHOLDER_IMAGE;
                const img = document.createElement('img');
                img.id = 'itemPageImage';
                img.alt = manualMedia.name || '';
                img.className = 'max-w-full max-h-full object-contain rounded-md';
                img.style.transition = 'opacity 0.15s ease-in-out';
                img.src = targetSrc;
                replaceMediaContent(mediaBox, img);
                updateMediaButtons();
                return;
            }
            // Sin video montado (el objeto solo tiene videos de estilo): la
            // imagen existente se gestiona con la lógica normal de abajo.
        }

        const imageEl = document.getElementById('itemPageImage');
        if (!imageEl) return;
        let targetSrc = null;
        if (mediaState.currentView === 'lego' && mediaState.hasLego && imageEl.dataset.legoUrl) {
            targetSrc = imageEl.dataset.legoUrl;
        } else {
            const styleEntry = mediaState.styles[mediaState.currentStyleIndex];
            targetSrc = (styleEntry && styleEntry.imageUrl) || imageEl.dataset.fortniteUrl || imageEl.src;
        }
        if (targetSrc && imageEl.src !== targetSrc) fadeSwapImage(imageEl, targetSrc, { immediate: true });
        updateMediaButtons();
    }

    function handleSwitchView(target) {
        offerCarouselGoTo(0);
        const requested = target?.dataset?.view === 'lego' ? 'lego' : 'fortnite';
        if (mediaState.currentView === requested) return;
        mediaState.currentView = requested;
        if (requested === 'lego' && mediaState.styles.length > 0) {
            mediaState.currentStyleIndex = mediaState.defaultStyleIndex;
        }
        applyMediaState();
    }

    function handleStyleSelect(target) {
        if (!target || target.classList.contains('disabled')) return;
        const index = mediaState.styles.findIndex(entry => entry.button === target);
        if (index === -1) return;
        offerCarouselGoTo(0);
        if (mediaState.currentView === 'lego') mediaState.currentView = 'fortnite';
        mediaState.currentStyleIndex = index;
        applyMediaState();
    }

    /**
     * <video> para videos manuales — se monta directo, sin imagen previa ni
     * poster: mientras descarga solo se ve el fondo degradado, así la imagen
     * del objeto no aparece ni un instante cuando hay video.
     */
    function buildManualVideoNode(videoUrl, posterUrl, name) {
        const video = document.createElement('video');
        video.src = videoUrl;
        video.className = 'w-full h-full object-contain rounded-md';
        video.loop = true;
        video.autoplay = true;
        video.muted = !videoSoundPrefOn();
        video.playsInline = true;
        video.preload = 'auto';
        video.setAttribute('aria-label', name || 'Video del objeto');
        video.onerror = () => {
            if (manualMedia && manualMedia.nodes[videoUrl] === video) delete manualMedia.nodes[videoUrl];
            // Fallback técnico: solo si el video falla se muestra la imagen.
            const img = document.createElement('img');
            img.src = posterUrl || CONFIG.PLACEHOLDER_IMAGE;
            img.alt = name || '';
            img.className = 'max-w-full max-h-full object-contain rounded-md';
            try { video.replaceWith(img); } catch (_) { }
        };
        return video;
    }

    function attachVideoControls(container) {
        const controls = el('div', 'video-controls-bar');
        const pill = el('span', 'video-controls-bar__pill');
        const svgNS = 'http://www.w3.org/2000/svg';
        const playBtn = el('button', 'control-bar-button control-bar-button--play');
        playBtn.dataset.action = 'toggle-playback';
        playBtn.setAttribute('aria-label', 'Reproducir');
        const playSvg = document.createElementNS(svgNS, 'svg');
        playSvg.setAttribute('viewBox', '0 0 24 24');
        playSvg.setAttribute('fill', 'currentColor');
        const playPath = document.createElementNS(svgNS, 'path');
        playPath.setAttribute('d', 'M12 22.5C17.799 22.5 22.5 17.799 22.5 12C22.5 6.20101 17.799 1.5 12 1.5C6.20101 1.5 1.5 6.20101 1.5 12C1.5 17.799 6.20101 22.5 12 22.5ZM10.045 7.19665L17.25 12L10.045 16.8033C9.45335 17.1978 8.69617 16.6243 8.91554 15.9479L10.1959 12L8.91554 8.05207C8.69617 7.3757 9.45335 6.80223 10.045 7.19665Z');
        playSvg.appendChild(playPath);
        playBtn.appendChild(playSvg);
        const muteBtn = el('button', 'control-bar-button control-bar-button--volume');
        muteBtn.dataset.action = 'toggle-mute';
        muteBtn.setAttribute('aria-label', 'Silenciar');
        const muteSvg = document.createElementNS(svgNS, 'svg');
        muteSvg.setAttribute('viewBox', '0 0 24 24');
        muteSvg.setAttribute('fill', 'currentColor');
        muteSvg.appendChild(document.createElementNS(svgNS, 'g'));
        muteBtn.appendChild(muteSvg);
        pill.append(playBtn, muteBtn);
        controls.appendChild(pill);
        container.appendChild(controls);
        try { setupVideoPlaybackControls(container); setupVolumeControls(container); } catch (_) { }
    }

    function tryLoadPreviewVideo(container, urls, index, fallbackImage, name, offerId) {
        if (index >= urls.length) {
            const img = document.createElement('img');
            img.src = fallbackImage;
            img.alt = name;
            img.className = 'max-w-full max-h-full object-contain rounded-md';
            img.onerror = () => handleImageError(img, offerId);
            container.replaceChildren(img);
            return;
        }
        const videoElement = document.createElement('video');
        videoElement.src = urls[index];
        videoElement.className = 'w-full h-full object-contain rounded-md';
        videoElement.loop = true;
        videoElement.autoplay = false;
        videoElement.playsInline = true;
        videoElement.muted = !videoSoundPrefOn();
        videoElement.onerror = () => tryLoadPreviewVideo(container, urls, index + 1, fallbackImage, name, offerId);
        videoElement.oncanplay = () => {
            container.replaceChildren(videoElement);
            attachVideoControls(container);
        };
    }

    const TRACK_MEDIA_CLASSES = ['item-page__media--video', 'item-page__media--audio', 'item-page__media--file'];

    // Solo se aceptan rutas relativas al propio origen (las genera el servidor
    // a partir de nombres de archivo saneados) o https.
    function safeTrackMediaUrl(url) {
        const value = String(url || '').trim();
        if (!value) return null;
        if (/^\/(?!\/)[A-Za-z0-9._\-\/%]+$/.test(value)) return value;
        try {
            const parsed = new URL(value, window.location.origin);
            if (parsed.protocol === 'https:' || (parsed.protocol === 'http:' && parsed.origin === window.location.origin)) return parsed.href;
        } catch (_) { }
        return null;
    }

    function mountTrackFallbackImage(container, fallbackImage) {
        container.style.aspectRatio = '1 / 1';
        container.classList.remove(...TRACK_MEDIA_CLASSES);
        const img = document.createElement('img');
        img.src = fallbackImage;
        img.alt = 'Vista previa';
        img.className = 'max-w-full max-h-full object-contain rounded-md';
        container.replaceChildren(img);
    }

    function formatClock(seconds) {
        const s = Math.max(0, Math.floor(Number(seconds) || 0));
        const m = Math.floor(s / 60);
        const r = s % 60;
        return `${m}:${r < 10 ? '0' : ''}${r}`;
    }

    // Audio directo (subido desde el panel): la carátula de la API se conserva
    // intacta y debajo va un reproductor propio (play, barra, tiempo, volumen).
    function mountTrackAudioPlayer(container, fallbackImage, mediaUrl) {
        container.style.aspectRatio = 'auto';
        container.classList.remove(...TRACK_MEDIA_CLASSES);
        container.classList.add('item-page__media--audio');

        const art = document.createElement('img');
        art.className = 'item-page__media-art';
        art.src = fallbackImage;
        art.alt = 'Carátula';

        const audio = document.createElement('audio');
        audio.preload = 'metadata';
        audio.src = mediaUrl;

        const player = el('div', 'vc-audio');
        player.setAttribute('role', 'group');
        player.setAttribute('aria-label', 'Reproductor de audio');
        const svgNS = 'http://www.w3.org/2000/svg';
        const icon = (d) => {
            const svg = document.createElementNS(svgNS, 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('fill', 'currentColor');
            svg.setAttribute('aria-hidden', 'true');
            const p = document.createElementNS(svgNS, 'path');
            p.setAttribute('d', d);
            svg.appendChild(p);
            return svg;
        };
        const PLAY = 'M8 5v14l11-7z';
        const PAUSE = 'M6 5h4v14H6zm8 0h4v14h-4z';
        const VOL = 'M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z';
        const MUTED = 'M16.5 12A4.5 4.5 0 0 0 14 7.97v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.8 8.8 0 0 0 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3 3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06a8.99 8.99 0 0 0 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4 9.91 6.09 12 8.18V4z';

        const playBtn = el('button', 'vc-audio__play');
        playBtn.type = 'button';
        playBtn.setAttribute('aria-label', 'Reproducir');
        playBtn.appendChild(icon(PLAY));

        const body = el('div', 'vc-audio__body');
        const seek = document.createElement('input');
        seek.type = 'range';
        seek.className = 'vc-audio__seek';
        seek.min = '0';
        seek.max = '1000';
        seek.step = '1';
        seek.value = '0';
        seek.setAttribute('aria-label', 'Posición');
        seek.style.setProperty('--vc-progress', '0%');
        const meta = el('div', 'vc-audio__meta');
        const current = el('span', 'vc-audio__time', '0:00');
        const label = el('span', 'vc-audio__label', 'Audio · Pista completa');
        const duration = el('span', 'vc-audio__time', '--:--');
        meta.append(current, label, duration);
        body.append(seek, meta);

        const muteBtn = el('button', 'vc-audio__mute');
        muteBtn.type = 'button';
        muteBtn.setAttribute('aria-label', 'Silenciar');
        muteBtn.appendChild(icon(VOL));

        player.append(audio, playBtn, body, muteBtn);

        const syncPlay = () => {
            const playing = !audio.paused && !audio.ended;
            playBtn.replaceChildren(icon(playing ? PAUSE : PLAY));
            playBtn.setAttribute('aria-label', playing ? 'Pausar' : 'Reproducir');
            player.classList.toggle('is-playing', playing);
        };
        const syncMute = () => {
            muteBtn.replaceChildren(icon(audio.muted ? MUTED : VOL));
            muteBtn.setAttribute('aria-label', audio.muted ? 'Activar sonido' : 'Silenciar');
        };
        let seeking = false;
        const syncTime = () => {
            const dur = Number.isFinite(audio.duration) ? audio.duration : 0;
            current.textContent = formatClock(audio.currentTime);
            duration.textContent = dur > 0 ? formatClock(dur) : '--:--';
            if (!seeking && dur > 0) {
                const ratio = Math.min(1, audio.currentTime / dur);
                seek.value = String(Math.round(ratio * 1000));
                seek.style.setProperty('--vc-progress', `${(ratio * 100).toFixed(2)}%`);
            }
        };
        playBtn.addEventListener('click', () => {
            if (audio.paused) audio.play().catch(() => { });
            else audio.pause();
        });
        muteBtn.addEventListener('click', () => { audio.muted = !audio.muted; syncMute(); });
        seek.addEventListener('input', () => {
            seeking = true;
            const dur = Number.isFinite(audio.duration) ? audio.duration : 0;
            const ratio = Number(seek.value) / 1000;
            seek.style.setProperty('--vc-progress', `${(ratio * 100).toFixed(2)}%`);
            current.textContent = formatClock(ratio * dur);
        });
        seek.addEventListener('change', () => {
            const dur = Number.isFinite(audio.duration) ? audio.duration : 0;
            if (dur > 0) audio.currentTime = (Number(seek.value) / 1000) * dur;
            seeking = false;
        });
        audio.addEventListener('play', syncPlay);
        audio.addEventListener('pause', syncPlay);
        audio.addEventListener('ended', syncPlay);
        audio.addEventListener('timeupdate', syncTime);
        audio.addEventListener('loadedmetadata', syncTime);
        audio.addEventListener('durationchange', syncTime);
        audio.addEventListener('volumechange', syncMute);
        audio.addEventListener('error', () => {
            label.textContent = 'No se pudo cargar el audio';
            player.classList.add('is-error');
            playBtn.disabled = true;
        });
        syncPlay();
        syncMute();

        container.replaceChildren(art, player);
        currentYtPlayer = { destroy: () => { try { audio.pause(); audio.removeAttribute('src'); audio.load(); } catch (_) { } } };
    }

    // Vídeo directo (subido desde el panel): sustituye a la carátula y ocupa el
    // mismo marco 16:9 que el reproductor de YouTube.
    function mountTrackVideoPlayer(container, mediaUrl) {
        container.style.aspectRatio = '16 / 9';
        container.classList.remove(...TRACK_MEDIA_CLASSES);
        container.classList.add('item-page__media--video', 'item-page__media--file');
        const video = document.createElement('video');
        video.src = mediaUrl;
        video.controls = true;
        video.playsInline = true;
        video.preload = 'metadata';
        video.setAttribute('controlsList', 'nodownload');
        video.className = 'w-full h-full object-contain rounded-md';
        container.replaceChildren(video);
        video.play().catch(() => { });
        currentYtPlayer = { destroy: () => { try { video.pause(); video.removeAttribute('src'); video.load(); } catch (_) { } } };
    }

    // Punto de entrada único del reproductor de pista: decide por `media_type`
    // (youtube | audio | video) y cae a la carátula si los datos no sirven.
    function mountTrackMedia(container, fallbackImage, videoData) {
        if (!videoData || videoData.status === 'error') {
            mountTrackFallbackImage(container, fallbackImage);
            return;
        }
        const type = String(videoData.media_type || 'youtube');
        if (type === 'audio' || type === 'video') {
            const url = safeTrackMediaUrl(videoData.media_url);
            if (!url) { mountTrackFallbackImage(container, fallbackImage); return; }
            if (type === 'audio') mountTrackAudioPlayer(container, fallbackImage, url);
            else mountTrackVideoPlayer(container, url);
            return;
        }
        mountYouTubePlayer(container, fallbackImage, videoData);
    }

    function mountYouTubePlayer(container, fallbackImage, videoData) {
        try {
            if (!videoData || videoData.status === 'error') throw new Error('Invalid video data');
            const ytId = videoData.youtube_id || videoData.videoId;
            if (!ytId) throw new Error('Missing YouTube ID');
            let start = 0;
            if (videoData.start_time != null) {
                const n = Number(videoData.start_time);
                if (!Number.isNaN(n) && n >= 0) start = n;
            }
            const ph = document.createElement('div');
            ph.id = `yt-player-${Date.now()}`;
            ph.className = 'w-full h-full object-contain rounded-md';
            container.style.aspectRatio = '16 / 9';
            container.classList.remove(...TRACK_MEDIA_CLASSES);
            container.classList.add('item-page__media--video');
            container.replaceChildren(ph);
            const startAt = Math.max(0, Math.floor(start));
            let mounted = false;
            // Plain embed: used when the IFrame API is unavailable or slow, so the
            // video always shows instead of leaving an empty placeholder.
            const mountIframe = () => {
                if (mounted || !ph.isConnected) return;
                mounted = true;
                const iframe = document.createElement('iframe');
                iframe.width = '100%';
                iframe.height = '100%';
                iframe.src = `https://www.youtube.com/embed/${encodeURIComponent(ytId)}?rel=0&modestbranding=1&playsinline=1&start=${startAt}`;
                iframe.title = 'YouTube video player';
                iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
                iframe.referrerPolicy = 'strict-origin-when-cross-origin';
                iframe.allowFullscreen = true;
                iframe.className = 'w-full h-full object-contain rounded-md';
                ph.replaceWith(iframe);
                currentYtPlayer = { destroy: () => { try { iframe.remove(); } catch (_) { } } };
            };
            const apiTimer = setTimeout(mountIframe, 4000);
            ensureYouTubeAPI().then(() => {
                clearTimeout(apiTimer);
                if (mounted) return;
                try {
                    if (window.YT && typeof window.YT.Player === 'function') {
                        mounted = true;
                        currentYtPlayer = new window.YT.Player(ph, {
                            videoId: String(ytId),
                            playerVars: { autoplay: 1, start: startAt, playsinline: 1, rel: 0, modestbranding: 1 },
                        });
                        return;
                    }
                    throw new Error('YT.Player not available');
                } catch (_) {
                    mounted = false;
                    mountIframe();
                }
            }).catch(() => { clearTimeout(apiTimer); mountIframe(); });
        } catch (e) {
            mountTrackFallbackImage(container, fallbackImage);
        }
    }

    /**
     * Decide y monta el nodo de media ANTES de insertarlo en el DOM.
     * Prioridad: video manual > video de gesto > video de pista (YT) > imagen.
     */
    function populateMedia(container, mediaData) {
        const { image, bgStyle, name, offerId, mainType, brItems, tracks, isJamTrackItem, renderImages, manualVideoUrl, manualVideoStyles } = mediaData;
        container.style.cssText = bgStyle || 'background-color: #333;';
        container.classList.add('item-page__media');
        container.id = 'item-page-media';

        const baseImage = image || CONFIG.PLACEHOLDER_IMAGE;
        let selectedImageUrl = baseImage;
        let legoOptimized = null;
        try {
            const split = splitLegoAndNonLegoImages(renderImages || []);
            if (split.nonLego.length > 0) selectedImageUrl = split.nonLego[0].image || baseImage;
            if (split.lego && split.lego.image) legoOptimized = optimizeImageUrl(split.lego.image);
        } catch (_) { }
        const fortniteOptimized = optimizeImageUrl(selectedImageUrl) || CONFIG.PLACEHOLDER_IMAGE;

        const styleVideos = (manualVideoStyles && typeof manualVideoStyles === 'object') ? manualVideoStyles : {};
        if (manualVideoUrl || Object.keys(styleVideos).length > 0) {
            // Los nodos <video> se cachean por URL: el re-render (payload
            // agregado tras el render local) adopta el nodo ya montado, que
            // conserva su buffer y reproducción — sin recarga ni parpadeo.
            if (!manualMedia) manualMedia = { nodes: {} };
            manualMedia.mainUrl = manualVideoUrl || null;
            manualMedia.styles = styleVideos;
            manualMedia.name = name;
            manualMedia.poster = fortniteOptimized;
            if (manualMedia.mainUrl) {
                const video = getManualVideoNode(manualMedia.mainUrl);
                container.replaceChildren(video);
                attachVideoControls(container);
                playManualVideo(video);
                return;
            }
            // Solo videos de estilo: la media por defecto sigue siendo imagen.
        } else {
            manualMedia = null;
        }

        const mainItem = brItems?.[0] || {};
        const videos = [...(mainItem.epicVideos || []), ...(mainItem.previewVideos || [])]
            .map(v => v.url).filter(Boolean);
        if (mainType === 'emote' && videos.length > 0) {
            const img = document.createElement('img');
            img.src = fortniteOptimized;
            img.alt = name;
            img.className = 'max-w-full max-h-full object-contain rounded-md';
            container.replaceChildren(img);
            tryLoadPreviewVideo(container, videos, 0, fortniteOptimized, name, offerId);
            return;
        }

        if (isJamTrackItem && tracks && tracks.length > 0) {
            container.style.aspectRatio = '16 / 9';
            container.classList.add('item-page__media--video');
            const spinWrap = el('div', 'flex justify-center items-center h-full');
            spinWrap.appendChild(createIcon('spinner', 'svg-icon--spin text-4xl text-yellow-500'));
            container.replaceChildren(spinWrap);
            const trackName = tracks?.[0]?.title || name;
            if (typeof trackName === 'string' && trackName.length > 0) {
                if (jamTrackVideoCache.has(trackName)) {
                    mountTrackMedia(container, fortniteOptimized, jamTrackVideoCache.get(trackName));
                } else {
                    fetchWithTimeout(CONFIG.GET_VIDEO_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: new URLSearchParams({ action: 't9h', track_name: trackName })
                    }, 8000)
                        .then(response => response.json())
                        .then(videoData => {
                            jamTrackVideoCache.set(trackName, videoData);
                            mountTrackMedia(container, fortniteOptimized, videoData);
                        })
                        .catch(() => {
                            jamTrackVideoCache.set(trackName, { status: 'error' });
                            container.style.aspectRatio = '1 / 1';
                            container.classList.remove('item-page__media--video');
                            const img = document.createElement('img');
                            img.src = fortniteOptimized;
                            img.alt = name;
                            img.className = 'max-w-full max-h-full object-contain rounded-md';
                            img.onerror = () => handleImageError(img, offerId);
                            container.replaceChildren(img);
                        });
                }
            }
            return;
        }

        const img = document.createElement('img');
        img.id = 'itemPageImage';
        img.src = fortniteOptimized;
        img.alt = name;
        img.className = 'max-w-full max-h-full object-contain rounded-md';
        img.style.transition = 'opacity 0.15s ease-in-out';
        img.onerror = function () { handleImageError(this, String(offerId)); };
        img.dataset.fortniteUrl = fortniteOptimized;
        if (legoOptimized) img.dataset.legoUrl = legoOptimized;
        container.replaceChildren(img);

        mediaState.hasLego = !!legoOptimized;
        if (legoOptimized) {
            const switcher = el('div', 'item-page__view-switcher');
            switcher.id = 'item-page-view-switcher';
            const fortniteBtn = el('button', 'view-switch-btn active');
            fortniteBtn.type = 'button';
            fortniteBtn.dataset.action = 'switch-view';
            fortniteBtn.dataset.view = 'fortnite';
            fortniteBtn.setAttribute('aria-label', 'Ver estilo Fortnite');
            fortniteBtn.setAttribute('aria-pressed', 'true');
            const fortImg = document.createElement('img');
            fortImg.src = 'https://res.cloudinary.com/dq3xsjgwi/image/upload/v1750347486/Iconofortnite_eykyvm.jpg';
            fortImg.alt = 'Icono Fortnite';
            fortniteBtn.appendChild(fortImg);
            const legoBtn = el('button', 'view-switch-btn');
            legoBtn.type = 'button';
            legoBtn.dataset.action = 'switch-view';
            legoBtn.dataset.view = 'lego';
            legoBtn.setAttribute('aria-label', 'Ver estilo LEGO');
            legoBtn.setAttribute('aria-pressed', 'false');
            const legoImg = document.createElement('img');
            legoImg.src = 'https://res.cloudinary.com/dq3xsjgwi/image/upload/v1750347212/LEGO_z2ewog.jpg';
            legoImg.alt = 'Icono LEGO';
            legoBtn.appendChild(legoImg);
            switcher.append(fortniteBtn, legoBtn);
            container.appendChild(switcher);
        }
    }

    /** Clave estable de un estilo: "canal:tag" en minúsculas (ej. parts:stage1). */
    function styleKeyFor(channel, tag) {
        const c = String(channel || '').toLowerCase().replace(/[^a-z0-9_.-]/g, '');
        const t = String(tag || '').toLowerCase().replace(/[^a-z0-9_.-]/g, '');
        return (c && t) ? c + ':' + t : '';
    }

    function buildStyleSelector(entry, displayInfo, renderImages) {
        const container = el('div', 'item-page__styles');
        container.id = 'item-page-styles';
        const { isBundle, mainItem } = displayInfo;
        const nonLegoRenderImages = (renderImages || []).filter(img => img && img.productTag !== 'Product.Juno');
        let html = '';

        if (isBundle && nonLegoRenderImages.length > 1) {
            html += `<div class="style-selector-channel"><h4 class="style-selector-title">ESTILO</h4><div class="style-selector-options">${nonLegoRenderImages.map((renderImage, index) => {
                const fullImage = optimizeImageUrl(renderImage.image);
                const isActive = index === 0 ? ' active' : '';
                return `<button class="style-option-btn style-option-btn-numbered${isActive}" data-action="select-style" data-style-key="render:${index}" data-full-image="${fullImage}" title="Estilo ${index + 1}">${index + 1}</button>`;
            }).join('')}</div></div>`;
        } else if (!isBundle && Array.isArray(mainItem?.variants) && mainItem.variants.length > 0) {
            // Mapa estilo→imagen resuelto por la DAL (override styleRenderMap del
            // Gestor de Capa de Datos). Cuando existe, manda sobre el mapeo
            // posicional: la API no garantiza que renderImages venga en el mismo
            // orden que las opciones de variante.
            const styleImages = (entry && entry.styleImages && typeof entry.styleImages === 'object')
                ? entry.styleImages : null;
            mainItem.variants.forEach((variantChannel, channelIndex) => {
                if (!variantChannel.options || variantChannel.options.length <= 1) return;
                const optionsHTML = variantChannel.options.map((option, optionIndex) => {
                    // Clave estable del estilo (canal:tag) — misma convención que
                    // el admin de videos y el snapshot (manualVideo.styles).
                    const styleKey = channelIndex === 0
                        ? styleKeyFor(variantChannel.channel, option.tag)
                        : '';
                    const overrideImage = (styleKey && styleImages && typeof styleImages[styleKey] === 'string')
                        ? styleImages[styleKey] : '';
                    const hasNonLego = nonLegoRenderImages[optionIndex];
                    const hasStyleVideo = !!(styleKey && manualMedia && manualMedia.styles[styleKey]);
                    const fullImageUrl = overrideImage
                        ? optimizeImageUrl(overrideImage)
                        : (hasNonLego ? optimizeImageUrl(hasNonLego.image) : '');
                    const isInteractive = channelIndex === 0 && (!!fullImageUrl || hasStyleVideo);
                    const disabledClass = !isInteractive ? ' disabled' : '';
                    const activeClass = optionIndex === 0 ? ' active' : '';
                    const previewUrl = optimizeImageUrl(option.image, { w: 96, q: 90 });
                    return `<button class="style-option-btn${disabledClass}${activeClass}" data-action="select-style" data-style-key="${styleKey}" data-full-image="${fullImageUrl}" title="${option.name}"><img src="${previewUrl}" alt="${option.name}" class="style-option-img"></button>`;
                }).join('');
                html += `<div class="style-selector-channel"><h4 class="style-selector-title">${variantChannel.type}</h4><div class="style-selector-options">${optionsHTML}</div></div>`;
            });
        }

        if (!html) return null;
        container.innerHTML = html;
        const primaryOptions = container.querySelector('.style-selector-channel .style-selector-options');
        if (primaryOptions) {
            primaryOptions.setAttribute('role', 'radiogroup');
            const buttons = Array.from(primaryOptions.querySelectorAll('.style-option-btn'));
            mediaState.styles = buttons.map((btn) => ({ button: btn, imageUrl: btn.dataset.fullImage || null }));
            let defaultIndex = 0;
            mediaState.styles.forEach((entryStyle, idx) => {
                if (entryStyle.button.classList.contains('active') && !entryStyle.button.classList.contains('disabled') && entryStyle.imageUrl) defaultIndex = idx;
            });
            mediaState.defaultStyleIndex = defaultIndex;
            mediaState.currentStyleIndex = defaultIndex;
        }
        return container;
    }

    /* ------------------------------- sections ---------------------------- */

    function buildPriceBlock(entry) {
        const priceBlock = el('div', 'item-page__price');
        priceBlock.id = 'item-page-price';
        const vbucks = entry?.finalPrice;
        if (!vbucks || vbucks <= 0) return null;
        const vbContainer = el('span', 'item-page__vbucks');
        const vbSvg = createVBucksIcon('inline-block');
        const vbText = el('span', null, Number(vbucks).toLocaleString('es-ES'));
        vbContainer.append(vbSvg, document.createTextNode(' '), vbText);
        const { finalSolesPrice, baseSolesPrice, spinDiscount } = calculateSolesPrice(vbucks, entry.offerId);
        const discountColorClass = spinDiscount ? 'text-spin-reward' : 'text-green-500';
        const solesFragment = createPriceSpans(finalSolesPrice, baseSolesPrice, {
            baseClass: 'price-base-strikethrough line-through',
            finalClass: 'text-xl font-bold',
            discountColorClass,
        });
        const solesWrap = el('div', 'ml-3 inline-flex items-center modal-soles-price-container');
        solesWrap.appendChild(solesFragment);
        priceBlock.append(solesWrap, vbContainer);
        return priceBlock;
    }

    function buildHistorySection(shopHistory) {
        if (!Array.isArray(shopHistory) || shopHistory.length === 0) return null;
        const wrapper = el('div', 'item-page__history occurrences-reveal-wrapper is-revealed');
        const container = el('div');
        container.id = 'item-page-occurrences';
        const toggle = el('button', 'item-page__history-toggle');
        toggle.type = 'button';
        toggle.dataset.action = 'toggle-history';
        toggle.setAttribute('aria-expanded', 'false');
        const label = el('span', null, 'Apariciones en la tienda: ');
        const count = el('strong', null, String(shopHistory.length));
        count.id = 'item-page-occ-count';
        const caret = el('span', 'occ-caret', ' ▾');
        caret.id = 'item-page-occ-caret';
        toggle.append(label, count, caret);
        const listEl = el('div');
        listEl.id = 'item-page-occ-list';
        const sortedDates = [...shopHistory].reverse();
        const today = new Date(); today.setHours(0, 0, 0, 0);
        sortedDates.forEach(dateStr => {
            const dateObj = new Date(dateStr);
            const diffDays = Math.ceil((today - dateObj) / (1000 * 60 * 60 * 24));
            const formattedDate = dateObj.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' });
            const row = el('div', 'py-1 px-1 flex justify-between gap-3 text-xs border-b modal-history-row last:border-b-0');
            row.append(el('span', null, formattedDate), el('span', 'modal-meta-label text-right', diffDays === 0 ? 'Hoy' : `hace ${diffDays} día(s)`));
            listEl.appendChild(row);
        });
        container.append(toggle, listEl);
        wrapper.appendChild(container);
        return wrapper;
    }

    function buildJamTrackDifficultyNode(difficulty) {
        const grid = el('div', 'jam-track-difficulty-grid');
        let hasContent = false;
        for (const apiKey of CONFIG.JAM_TRACK_INSTRUMENT_ORDER) {
            const instrument = CONFIG.JAM_TRACK_INSTRUMENTS[apiKey];
            const level = difficulty[apiKey];
            if (!instrument || typeof level !== 'number' || level === 0) continue;
            hasContent = true;
            const part = el('div', 'jam-track-part');
            const difficultyNum = el('div', 'jam-track-part-difficulty', String(level));
            const meta = el('div', 'jam-track-part-meta');
            const name = el('div', 'jam-track-part-name', instrument.label);
            const barsContainer = el('div', 'jam-track-part-bars');
            for (let i = 1; i <= 7; i++) {
                const bar = el('div', 'jam-track-part-bar');
                if (i <= level) bar.classList.add('active');
                barsContainer.appendChild(bar);
            }
            meta.append(name, barsContainer);
            part.append(difficultyNum, meta);
            grid.appendChild(part);
        }
        if (!hasContent) return null;
        const wrapper = el('div', 'item-page__difficulty');
        const title = el('h4', 'modal-meta-label font-semibold mb-2 text-sm', 'DIFICULTAD:');
        wrapper.append(title, grid);
        return wrapper;
    }

    /** Ítems de la oferta como anchors reales a sus propias páginas. */
    function buildSubItemsSection(entry, displayInfo) {
        const allCosmeticsInOffer = getAllItemsFromOffer(entry);
        const itemsToDisplay = (displayInfo.isBundle || allCosmeticsInOffer.length > 1)
            ? allCosmeticsInOffer
            : [];
        if (itemsToDisplay.length === 0) return null;

        const section = el('section', 'item-page__included');
        const title = el('h2', 'item-page__included-title', 'INCLUIDO EN LA OFERTA');
        const list = el('div', 'item-page__included-grid');
        itemsToDisplay.forEach(item => {
            const name = item.name || item.title;
            if (!name || !item.id) return;
            const rawImageUrl = resolveItemImage(item, true);
            const imageUrl = rawImageUrl ? optimizeImageUrl(rawImageUrl, { w: 256, q: 90 }) : CONFIG.PLACEHOLDER_IMAGE;
            const href = buildCosmeticPageUrl(item);
            const itemNode = document.createElement('a');
            itemNode.className = 'included-card';
            itemNode.href = href || '#';
            if (href) itemNode.dataset.route = '';
            itemNode.title = name;
            const imgWrap = el('div', 'included-card__media');
            const img = document.createElement('img');
            img.className = 'included-card__image';
            img.src = imageUrl;
            img.alt = name;
            img.loading = 'lazy';
            img.onerror = function () { this.onerror = null; this.src = CONFIG.PLACEHOLDER_IMAGE; };
            imgWrap.appendChild(img);
            const footer = el('div', 'included-card__footer');
            let typeId = item.type?.value || 'default';
            if (typeId === 'shoe' || typeId === 'shoes') typeId = 'footwear';
            const typeLabel = CONFIG.TYPE_TRANSLATIONS_SINGULAR[typeId] || 'Cosmético';
            footer.appendChild(el('span', 'included-card__type', typeLabel.toUpperCase()));
            footer.appendChild(el('span', 'included-card__name', name));
            itemNode.append(imgWrap, footer);
            list.appendChild(itemNode);
        });
        if (!list.hasChildNodes()) return null;
        section.append(title, list);
        return section;
    }

    /* Carousel de objetos de la oferta, como fortnite.com: la diapositiva 0 es
     * la media principal (video/estilos incluidos, que se conserva sin
     * destruirse) y el resto una imagen por objeto incluido, con flechas
     * laterales, puntos bajo el nombre y spinner de carga oficiales. */
    let offerCarousel = null;

    /* Mapa cosmeticId → renderImage grande enviado por el endpoint agregado
     * (caché permanente del server); alimenta hero y slides en deep links. */
    let pageItemRenderImages = null;

    // Icono de carga oficial (LoadingIcon de fortnite.com), rotando 1s lineal.
    const LOADING_ICON_PATH = 'M22,12c0,5.5-4.5,10-10,10S2,17.5,2,12h2c0,4.4,3.6,8,8,8s8-3.6,8-8-3.6-8-8-8v-2c5.5,0,10,4.5,10,10Z';

    function createSvg24(pathD, className) {
        const SVG_NS = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        if (className) svg.setAttribute('class', className);
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', pathD);
        path.setAttribute('fill', 'currentColor');
        path.setAttribute('fill-rule', 'evenodd');
        svg.appendChild(path);
        return svg;
    }

    /** SVG 24×24 que referencia un símbolo del sprite de index.php. */
    function createSpriteSvg24(symbolId) {
        const SVG_NS = 'http://www.w3.org/2000/svg';
        const XLINK_NS = 'http://www.w3.org/1999/xlink';
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        const use = document.createElementNS(SVG_NS, 'use');
        use.setAttribute('href', `#${symbolId}`);
        use.setAttributeNS(XLINK_NS, 'xlink:href', `#${symbolId}`);
        svg.appendChild(use);
        return svg;
    }

    /**
     * URL de imagen para un slide del carousel (media grande): en escritorio
     * webp q=100 sin reducir tamaño (wsrv no agranda por defecto); en móvil
     * ancho acotado al viewport real (pantalla × dpr) con algo de compresión.
     */
    function optimizeSlideImageUrl(url) {
        if (!url) return CONFIG.PLACEHOLDER_IMAGE;
        const isDesktop = window.matchMedia('(min-width: 768px)').matches;
        if (isDesktop) return optimizeImageUrl(url, { w: 2048, q: 100 });
        const dpr = Math.min(window.devicePixelRatio || 1, 3);
        const w = Math.min(1200, Math.ceil((window.screen?.width || 480) * dpr));
        return optimizeImageUrl(url, { w, q: 85 });
    }

    /**
     * Arte de cuerpo completo de un ítem buscando su propia oferta individual
     * en el catálogo en memoria: los renderImages del newDisplayAsset son las
     * imágenes grandes reales de la tienda (la API de cosméticos ya solo sirve
     * icon/smallIcon para ítems nuevos). Se incluyen ofertas ocultas "a la
     * carte" (sin layout), que también traen renderImages válidos.
     */
    function resolveItemRenderImage(itemId) {
        if (!itemId) return null;
        const lowerId = String(itemId).toLowerCase();
        // Mapa enviado por el endpoint agregado (caché permanente del server):
        // cubre deep links, donde el catálogo en memoria nunca se carga.
        const fromPage = pageItemRenderImages && pageItemRenderImages[lowerId];
        if (typeof fromPage === 'string' && fromPage) return fromPage;
        if (!APIData || !APIData.details) return null;
        let best = null;
        let bestScore = Infinity;
        for (const other of APIData.details.values()) {
            if (!other || typeof other !== 'object' || other.bundle) continue;
            const items = getAllItemsFromOffer(other);
            if (!items.some(it => String(it?.id || '').toLowerCase() === lowerId)) continue;
            // Dueño del arte: el declarado (cosmeticId) o, en su defecto, el
            // protagonista implícito de la oferta (skin o instrumento) — el
            // render lo retrata a él, no a sus accesorios (mochila, pico).
            let owner = String(other.newDisplayAsset?.cosmeticId || '').toLowerCase();
            if (!owner && items.length > 1) {
                const hero = items.find(it => it?.type?.value === 'outfit'
                    || String(it?.id || '').toLowerCase().startsWith('character_'))
                    || items.find(it => String(it?.id || '').toLowerCase().startsWith('sparks_'));
                owner = String(hero?.id || '').toLowerCase();
            }
            if (owner && owner !== lowerId) continue;
            const { nonLego } = splitLegoAndNonLegoImages(getRenderImages(other));
            if (!nonLego.length || !nonLego[0].image) continue;
            // Preferencia: oferta donde el ítem es protagonista y con menos
            // ítems (su arte lo retrata mejor); si solo aparece como extra
            // (mochila del pico, etc.), su oferta más pequeña sigue siendo
            // mucho mejor que el icon del catálogo.
            const isMain = String(getItemDisplayInfo(other)?.mainItem?.id || '').toLowerCase() === lowerId;
            const score = (isMain ? 0 : 100) + items.length;
            if (score < bestScore) {
                bestScore = score;
                best = nonLego[0].image;
            }
        }
        return best;
    }

    /**
     * Fuentes de imagen de un slide, en orden de calidad: renderImage de la
     * oferta del ítem (arte grande real de la tienda) → featured → icon, cada
     * una vía wsrv y luego directa (por si wsrv falla) → placeholder. Se
     * resuelven perezosamente (al construir el nodo) porque el catálogo en
     * memoria puede aún no estar cargado al montar el carousel.
     */
    function slideImageSources(slide, cache = true) {
        if (Array.isArray(slide.imageSources)) return slide.imageSources;
        const item = slide.item || {};
        const renderUrl = item.albumArt ? null : resolveItemRenderImage(item.id);
        const heroUrl = resolveHeroImage(item);
        const iconUrl = resolveItemImage(item, false);
        const sources = [...new Set([
            renderUrl ? optimizeSlideImageUrl(renderUrl) : null,
            renderUrl,
            heroUrl ? optimizeSlideImageUrl(heroUrl) : null,
            heroUrl,
            iconUrl ? optimizeSlideImageUrl(iconUrl) : null,
            iconUrl,
            CONFIG.PLACEHOLDER_IMAGE,
        ].filter(Boolean))];
        if (cache) slide.imageSources = sources;
        return sources;
    }

    function attachOfferCarousel(mediaBox, caption, entry, displayInfo, itemVideos) {
        const allItems = getAllItemsFromOffer(entry);
        if (!Array.isArray(allItems) || allItems.length <= 1) return;

        const videosById = (itemVideos && typeof itemVideos === 'object')
            ? itemVideos
            : (entry?.itemVideos && typeof entry.itemVideos === 'object' ? entry.itemVideos : {});
        const buildSlide = (item) => {
            const videos = videosById[String(item.id || '').toLowerCase()] || null;
            return {
                name: item.name || item.title || '',
                item,
                imageSources: null,
                videoUrl: (videos && typeof videos.url === 'string' && videos.url) || null,
                node: null,
                ready: false,
            };
        };
        // Los lotes no tienen slide propio: se navega solo entre los objetos
        // incluidos (la imagen del lote queda para las tarjetas). En ofertas
        // de un objeto con bonus, el slide 0 es la media principal ya montada.
        const slides = displayInfo.isBundle
            ? allItems.map(buildSlide)
            : [
                { name: displayInfo.displayName, item: null, imageSources: [], videoUrl: null, node: null, ready: false },
                ...allItems.filter(item => String(item.id || '').toLowerCase()
                    !== String(displayInfo.mainItem?.id || '').toLowerCase()).map(buildSlide),
            ];
        if (slides.length <= 1) return;

        const loader = el('div', 'item-page__media-loader hidden');
        loader.appendChild(createSvg24(LOADING_ICON_PATH, 'item-page__loading-icon'));

        const nav = el('div', 'item-page__carousel-nav');
        [['carousel-prev', 'Objeto anterior', 'icon-carousel-arrow-prev'],
        ['carousel-next', 'Siguiente objeto', 'icon-carousel-arrow-next']].forEach(([action, label, iconId]) => {
            const btn = el('button', 'item-page__carousel-arrow');
            btn.type = 'button';
            btn.dataset.action = action;
            btn.title = label;
            btn.setAttribute('aria-label', label);
            btn.appendChild(createSpriteSvg24(iconId));
            nav.appendChild(btn);
        });

        const dots = el('div', 'item-page__carousel-dots');
        dots.setAttribute('role', 'tablist');
        dots.setAttribute('aria-label', 'Objetos de la oferta');
        slides.forEach((_, idx) => {
            const dot = el('button', 'item-page__carousel-dot' + (idx === 0 ? ' active' : ''));
            dot.type = 'button';
            dot.dataset.action = 'carousel-dot';
            dot.dataset.index = String(idx);
            dot.setAttribute('aria-label', `Ir a la diapositiva ${idx + 1}`);
            dots.appendChild(dot);
        });

        mediaBox.append(loader, nav);
        caption.after(dots);
        const carousel = { slides, index: 0, mediaBox, caption, loader, dots, mainNodes: null, autoTimer: null };
        offerCarousel = carousel;
        if (slides[0].item) {
            // Sin slide propio de la oferta: reemplazar la media del lote ya
            // montada por la del primer objeto incluido.
            pauseManualVideos(null);
            carouselContentNodes(carousel).forEach(node => node.remove());
            mountCarouselSlide(carousel, 0);
            caption.textContent = slides[0].name;
        }
        updateCarouselDots(carousel);
        scheduleCarouselAutoplay(carousel);

        // Pausa/reanudación del video visible: el auto-avance se detiene al
        // pausar y se REINICIA de cero al despausar (nunca continúa a medias).
        // Captura porque los eventos de media no burbujean; los pauses de
        // videos ya desmontados (cambio de slide) se ignoran vía isConnected.
        mediaBox.addEventListener('pause', (e) => {
            if (offerCarousel !== carousel || !(e.target instanceof HTMLVideoElement)) return;
            if (e.target.isConnected) stopCarouselAutoplay(carousel);
        }, true);
        mediaBox.addEventListener('play', (e) => {
            if (offerCarousel !== carousel || !(e.target instanceof HTMLVideoElement)) return;
            if (e.target.isConnected) scheduleCarouselAutoplay(carousel);
        }, true);

        // Pre-carga en idle de las imágenes HQ de los slides (solo memoria,
        // sin tocar el DOM); los videos se crean bajo demanda y se cachean.
        const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 300));
        idle(() => {
            if (offerCarousel !== carousel) return;
            carousel.slides.forEach((slide) => {
                // Sin cachear las fuentes: si el catálogo aún no está cargado
                // aquí, el slide podrá resolver el arte grande más tarde.
                if (slide.item && !slide.videoUrl) new Image().src = slideImageSources(slide, false)[0];
            });
        });
    }

    /* Puntitos con ventana simétrica (como fortnite.com): el activo con
     * hasta 3 puntos visibles a cada lado, sin redistribuir el sobrante.
     * Los dos puntos junto a un borde con más ocultos detrás se encogen
     * progresivamente. */
    const DOTS_PER_SIDE = 3;
    function updateCarouselDots(c) {
        const n = c.slides.length;
        const start = Math.max(0, c.index - DOTS_PER_SIDE);
        const end = Math.min(n - 1, c.index + DOTS_PER_SIDE);
        c.dots.querySelectorAll('.item-page__carousel-dot').forEach((dot) => {
            const i = Number(dot.dataset.index);
            const active = i === c.index;
            dot.classList.toggle('active', active);
            dot.setAttribute('aria-current', String(active));
            const edgeDist = Math.min(
                start > 0 ? i - start : Infinity,
                end < n - 1 ? end - i : Infinity);
            dot.classList.toggle('is-xs', edgeDist === 0);
            dot.classList.toggle('is-sm', edgeDist === 1);
            dot.classList.toggle('is-hidden', i < start || i > end);
        });
    }

    /* Auto-avance al siguiente objeto (como fortnite.com), SOLO cuando el
     * slide actual muestra un video: con imagen el slide se queda quieto
     * hasta que el usuario navegue. El timer se reinicia completo en cada
     * navegación y al reanudar un video pausado. */
    const CAROUSEL_AUTOPLAY_MS = 5000;
    function stopCarouselAutoplay(c) {
        if (c.autoTimer) { clearTimeout(c.autoTimer); c.autoTimer = null; }
    }
    function scheduleCarouselAutoplay(c) {
        stopCarouselAutoplay(c);
        if (!c.mediaBox.querySelector('video')) return;
        c.autoTimer = setTimeout(() => {
            c.autoTimer = null;
            if (offerCarousel !== c) return;
            const video = c.mediaBox.querySelector('video');
            // Sin video (p.ej. cayó al fallback de imagen) o pausado: no avanzar.
            if (!video || video.paused) return;
            offerCarouselGoTo(c.index + 1);
        }, CAROUSEL_AUTOPLAY_MS);
    }

    /** Nodos de contenido de la media (todo menos el loader y las flechas). */
    function carouselContentNodes(c) {
        return [...c.mediaBox.children].filter(node =>
            node !== c.loader && !node.classList.contains('item-page__carousel-nav'));
    }

    /** Pausa los videos de los slides del carousel (menos el indicado). */
    function pauseSlideVideos(c, exceptVideo) {
        c.slides.forEach((slide) => {
            const video = slide.node ? (slide.node.tagName === 'VIDEO' ? slide.node : slide.node.querySelector('video')) : null;
            if (video && video !== exceptVideo) { try { video.pause(); } catch (_) { } }
        });
    }

    /* Media de un slide: nodo creado UNA vez y cacheado en el slide, para que
     * volver a un slide no re-descargue ni reinicie nada. Con video se monta
     * <video> directo (nunca la imagen antes — cero parpadeo) y el spinner
     * central queda visible hasta que puede reproducirse; sin video, imagen
     * HQ con spinner hasta onload. */
    function buildSlideImageNode(c, slide, slideIndex) {
        const img = document.createElement('img');
        img.alt = slide.name;
        img.className = 'max-w-full max-h-full object-contain rounded-md';
        img.onload = () => {
            slide.ready = true;
            if (offerCarousel === c && c.index === slideIndex) c.loader.classList.add('hidden');
        };
        const sources = slideImageSources(slide);
        let sourceIdx = 0;
        img.onerror = () => {
            sourceIdx += 1;
            if (sourceIdx < sources.length) {
                img.src = sources[sourceIdx];
                return;
            }
            // Sin más fuentes: soltar el spinner para no dejarlo girando.
            img.onerror = null;
            slide.ready = true;
            if (offerCarousel === c && c.index === slideIndex) c.loader.classList.add('hidden');
        };
        img.src = sources[0];
        return img;
    }

    function buildSlideVideoNode(c, slide, slideIndex) {
        const wrap = el('div', 'w-full h-full');
        const video = document.createElement('video');
        video.src = slide.videoUrl;
        video.className = 'w-full h-full object-contain rounded-md';
        video.loop = true;
        video.autoplay = true;
        video.muted = !videoSoundPrefOn();
        video.playsInline = true;
        video.preload = 'auto';
        video.setAttribute('aria-label', slide.name || 'Video del objeto');
        const syncLoader = (ready) => {
            slide.ready = ready;
            if (offerCarousel === c && c.index === slideIndex) c.loader.classList.toggle('hidden', ready);
        };
        video.addEventListener('canplay', () => syncLoader(true));
        video.addEventListener('playing', () => syncLoader(true));
        video.addEventListener('waiting', () => syncLoader(false));
        video.onerror = () => {
            // Fallback técnico: solo si el video falla se muestra la imagen.
            slide.ready = false;
            slide.node = buildSlideImageNode(c, slide, slideIndex);
            if (wrap.parentNode) wrap.replaceWith(slide.node);
        };
        wrap.appendChild(video);
        attachVideoControls(wrap);
        return wrap;
    }

    function getSlideMediaNode(c, slide, slideIndex) {
        if (!slide.node) {
            slide.node = slide.videoUrl
                ? buildSlideVideoNode(c, slide, slideIndex)
                : buildSlideImageNode(c, slide, slideIndex);
        }
        return slide.node;
    }

    function mountCarouselSlide(c, index) {
        const slide = c.slides[index];
        const node = getSlideMediaNode(c, slide, index);
        c.loader.classList.toggle('hidden', !!slide.ready);
        c.mediaBox.prepend(node);
        const video = node.tagName === 'VIDEO' ? node : node.querySelector('video');
        pauseSlideVideos(c, video);
        if (video) playManualVideo(video);
    }

    function offerCarouselGoTo(rawIndex) {
        const c = offerCarousel;
        if (!c) return;
        const n = c.slides.length;
        const index = ((rawIndex % n) + n) % n;
        if (index === c.index) return;

        const hasMainSlide = !c.slides[0].item;
        if (c.index === 0 && hasMainSlide) {
            // Conservar la media principal (video incluido) sin destruirla.
            pauseManualVideos(null);
            c.mainNodes = carouselContentNodes(c);
        }
        carouselContentNodes(c).forEach(node => node.remove());

        if (index === 0 && hasMainSlide) {
            pauseSlideVideos(c, null);
            c.loader.classList.add('hidden');
            if (c.mainNodes) c.mediaBox.prepend(...c.mainNodes);
            const video = c.mediaBox.querySelector('video');
            if (video) playManualVideo(video);
        } else {
            mountCarouselSlide(c, index);
        }

        c.index = index;
        c.caption.textContent = c.slides[index].name;
        updateCarouselDots(c);
        scheduleCarouselAutoplay(c);
    }

    /** Duración de pista en mm:ss a partir de segundos. */
    function formatTrackDuration(seconds) {
        const total = Number(seconds);
        if (!Number.isFinite(total) || total <= 0) return null;
        const mins = Math.floor(total / 60);
        const secs = Math.floor(total % 60);
        return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }

    /** Flecha del breadcrumb con el path inline (no depende del sprite del documento). */
    function buildBackArrow() {
        const SVG_NS = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('class', 'svg-icon item-page__back-arrow');
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('fill-rule', 'evenodd');
        path.setAttribute('fill', 'currentColor');
        path.setAttribute('d', 'M12.7073 4.74675C13.0587 4.39529 13.6286 4.39529 13.98 4.74675L15.8892 6.6559C16.2407 7.00737 16.2407 7.57722 15.8892 7.92869L11.8166 12.0012L15.8892 16.0737C16.2407 16.4252 16.2407 16.995 15.8892 17.3465L13.98 19.2557C13.6286 19.6071 13.0587 19.6071 12.7073 19.2557L5.45264 12.0012L12.7073 4.74675Z');
        svg.appendChild(path);
        return svg;
    }

    function buildBackLink() {
        const back = document.createElement('a');
        back.className = 'item-page__back';
        back.href = '/via-regalo';
        back.dataset.route = '';
        back.dataset.back = '';
        back.append(
            buildBackArrow(),
            createIcon('logo', 'item-page__back-logo'),
            el('span', null, 'Tienda')
        );
        return back;
    }

    /** Barra superior de la página: volver a la tienda + compartir. */
    function buildTopNav() {
        const nav = el('div', 'item-page__nav');
        nav.appendChild(buildBackLink());
        const share = el('button', 'item-page__share');
        share.type = 'button';
        share.dataset.action = 'share-page';
        share.setAttribute('aria-label', 'Compartir');
        share.appendChild(createIcon('share', ''));
        nav.appendChild(share);
        return nav;
    }

    function buildPill(text, { bg, color } = {}) {
        const pill = el('span', 'item-page__pill');
        pill.appendChild(el('span', 'item-page__pill-text', text));
        if (bg) pill.style.backgroundColor = bg;
        if (color) pill.style.color = color;
        return pill;
    }

    /* --------------------------- página de objeto ------------------------ */

    /**
     * @param {HTMLElement} outlet
     * @param {{ id: string, entry: object|null, container: object|null,
     *           cosmetic: object|null, availability: object|null,
     *           videoUrl: string|null, videoStyles: object|null,
     *           shopHistory: array|null }} data
     */
    function renderObjectPage(outlet, data) {
        stopMedia();
        resetMediaState();
        unmountCartEntry();

        const { id, entry, container: containerEntry, containerBg, cosmetic, availability, videoUrl, videoStyles, itemVideos, itemRenderImages, shopHistory } = data;
        pageItemRenderImages = (itemRenderImages && typeof itemRenderImages === 'object') ? itemRenderImages : null;

        // ---- fusionar datos: entrada de tienda (si existe) + cosmético HQ ----
        let displayInfo = entry ? getItemDisplayInfo(entry) : null;
        let mainItem = displayInfo?.mainItem || null;
        const cosmeticRecord = cosmetic || null;

        // Cosmético sin oferta actual: construir un "mainItem" desde el índice.
        if (!mainItem && cosmeticRecord) {
            mainItem = {
                id: cosmeticRecord.id,
                name: cosmeticRecord.name,
                description: cosmeticRecord.description,
                type: cosmeticRecord.type,
                rarity: cosmeticRecord.rarity,
                series: cosmeticRecord.series,
                set: cosmeticRecord.set,
                introduction: cosmeticRecord.introduction,
                images: cosmeticRecord.images,
                albumArt: cosmeticRecord.images?.albumArt || cosmeticRecord.albumArt,
            };
        } else if (mainItem && cosmeticRecord) {
            // Completar huecos del snapshot con el índice de cosméticos (HQ).
            mainItem = { ...mainItem };
            if (!mainItem.description && cosmeticRecord.description) mainItem.description = cosmeticRecord.description;
            if (!mainItem.introduction && cosmeticRecord.introduction) mainItem.introduction = cosmeticRecord.introduction;
            if (!mainItem.set && cosmeticRecord.set) mainItem.set = cosmeticRecord.set;
            if (!mainItem.rarity && cosmeticRecord.rarity) mainItem.rarity = cosmeticRecord.rarity;
            if (!mainItem.series && cosmeticRecord.series) mainItem.series = cosmeticRecord.series;
            const recordImages = cosmeticRecord.images;
            if (recordImages && typeof recordImages === 'object') {
                mainItem.images = { ...(mainItem.images || {}) };
                for (const key of ['featured', 'icon', 'large', 'small', 'smallIcon']) {
                    if (!mainItem.images[key] && typeof recordImages[key] === 'string' && recordImages[key]) {
                        mainItem.images[key] = recordImages[key];
                    }
                }
                if (!mainItem.albumArt && typeof recordImages.albumArt === 'string' && recordImages.albumArt) {
                    mainItem.albumArt = recordImages.albumArt;
                }
            }
        }

        if (!mainItem && !entry && !containerEntry && !containerBg) {
            outlet.replaceChildren(buildBackLink(), el('p', 'item-page__notfound', 'No se encontró este objeto.'));
            return;
        }

        const isBundle = !!displayInfo?.isBundle;
        const isJamTrack = entry
            ? (Array.isArray(entry.tracks) && entry.tracks.length > 0)
            : (cosmeticRecord?.group === 'tracks');
        const displayName = displayInfo?.displayName || mainItem?.name || mainItem?.title || 'Objeto';

        let singularType;
        if (isBundle) {
            singularType = CONFIG.TYPE_TRANSLATIONS_SINGULAR['bundle'];
        } else {
            let typeId = mainItem?.type?.value || 'default';
            if (isJamTrack) typeId = 'jamtrack';
            if (typeId === 'shoe' || typeId === 'shoes') typeId = 'footwear';
            singularType = CONFIG.TYPE_TRANSLATIONS_SINGULAR[typeId] || 'Cosmético';
        }
        const typeOverride = (typeof entry?.displayTypeOverride === 'string') ? entry.displayTypeOverride.trim() : '';
        if (typeOverride) singularType = typeOverride;

        document.title = `${displayName} — Banano Store`;

        const status = availability?.status
            || (entry && entry.finalPrice > 0 ? 'comprable' : (containerEntry ? 'solo-en-lote' : 'no-disponible'));

        // ------------------------------- DOM --------------------------------
        const page = el('div', 'item-page');
        if (isJamTrack && !isBundle) page.classList.add('item-page--jam');

        page.append(buildTopNav());

        const grid = el('div', 'item-page__grid');

        // Media (decisión de nodo ANTES del primer paint; sin flash de imagen).
        const media = el('div');
        const renderImages = entry ? getRenderImages(entry) : [];
        const heroImage = mainItem?.albumArt
            || entry?.newDisplayAsset?.renderImages?.[0]?.image
            || (mainItem?.id ? resolveItemRenderImage(mainItem.id) : null)
            || resolveHeroImage(mainItem)
            || (entry ? resolveHeroImage(getAllItemsFromOffer(entry).find(it => resolveHeroImage(it))) : null);
        const manualVideoUrl = videoUrl || entry?.manualVideo?.url || null;
        const manualVideoStyles = videoStyles || entry?.manualVideo?.styles || null;
        populateMedia(media, {
            image: heroImage,
            bgStyle: entry?.bgStyle || bgStyleFromEntryColors(entry?.colors)
                  || containerEntry?.bgStyle || bgStyleFromEntryColors(containerEntry?.colors)
                  || containerBg?.bgStyle || bgStyleFromEntryColors(containerBg?.colors),
            name: displayName,
            offerId: entry?.offerId || id,
            mainType: mainItem?.type?.value,
            brItems: entry?.brItems,
            tracks: entry?.tracks,
            isJamTrackItem: isJamTrack && !isBundle,
            renderImages,
            manualVideoUrl,
            manualVideoStyles,
        });

        const mediaCol = el('div', 'item-page__media-col');
        mediaCol.appendChild(media);
        const mediaCaption = el('p', 'item-page__media-caption', displayName);
        mediaCol.appendChild(mediaCaption);

        const header = el('div', 'item-page__header');
        const info = el('div', 'item-page__info');
        const trackData = entry?.tracks?.[0] || (isJamTrack ? cosmeticRecord : null);

        // Placa «Termina hoy» (misma que la tarjeta) encima de las pills,
        // como en la página de objeto oficial.
        if (entry && offerEndsToday(entry.outDate)) {
            const plate = buildCardPlateNode(entry);
            if (plate) {
                plate.classList.add('item-page__plate');
                header.appendChild(plate);
            }
        }

        // Pills: tipo + rareza/serie + banner + descuento (fuera de la imagen).
        const pills = el('div', 'item-page__pills');
        // Las pistas de improvisación no muestran rareza/serie (no es relevante).
        if (!(isJamTrack && !isBundle)) {
            const rarityItem = mainItem || {};
            const categoryId = rarityItem.series?.backendValue ?? rarityItem.rarity?.value?.toLowerCase() ?? 'common';
            const categoryName = (rarityItem.series?.displayValue ?? rarityItem.rarity?.displayValue ?? 'Común').toUpperCase();
            const colors = CONFIG.MANUAL_CATEGORY_COLORS[categoryId] ?? { backgroundColor: '#40464d', textColor: '#fff' };
            pills.appendChild(buildPill(categoryName.replace('SERIES DE ', ''), { bg: colors.backgroundColor, color: colors.textColor }));
        }
        pills.appendChild(el('span', 'item-page__type', singularType.toUpperCase()));
        if (entry) {
            try {
                const bannerNode = buildCardBannerNode(entry);
                if (bannerNode) {
                    bannerNode.classList.add('item-page__pill--banner');
                    pills.appendChild(bannerNode);
                }
            } catch (_) { }
            try {
                if (entry.finalPrice > 0) {
                    const { finalSolesPrice, baseSolesPrice, discountInfo } = calculateSolesPrice(entry.finalPrice, entry.offerId);
                    const finalDiscountInfo = entry.automaticDiscountInfo || discountInfo;
                    if (finalDiscountInfo) {
                        const badgeNode = buildDiscountBadgeNode(finalDiscountInfo, baseSolesPrice, finalSolesPrice);
                        if (badgeNode) {
                            badgeNode.classList.add('item-page__pill--badge');
                            pills.appendChild(badgeNode);
                        }
                    }
                }
            } catch (_) { }
        }
        header.appendChild(pills);

        const credits = (isJamTrack && !isBundle) ? deriveJamTrackCredits(trackData, displayName) : null;
        header.appendChild(el('h1', 'item-page__title', credits?.title ?? displayName));

        if (credits?.artist) {
            header.appendChild(el('p', 'item-page__artist', credits.artist));
        }

        // Precio (solo si hay oferta actual con precio).
        if (entry && entry.finalPrice > 0) {
            if (isBundle) {
                header.appendChild(el('p', 'item-page__price-note', 'El precio de los objetos incluidos es:'));
            }
            const priceBlock = buildPriceBlock(entry);
            if (priceBlock) header.appendChild(priceBlock);
        }

        // Disponibilidad + fechas exactas.
        const availBox = el('div', 'item-page__availability');
        if (status === 'comprable' && entry) {
            const outDate = entry.offerDates?.out;
            const formattedOut = formatFullDate(outDate);
            if (formattedOut) {
                availBox.appendChild(el('p', 'item-page__avail-line item-page__avail-line--ok', `Puedes comprarlo hasta: ${formattedOut}`));
            }
            const inDate = entry.offerDates?.in;
            const formattedIn = formatFullDate(inDate);
            if (formattedIn) {
                availBox.appendChild(el('p', 'item-page__avail-line', `En la tienda desde: ${formattedIn}`));
            }
        } else if (status === 'no-disponible') {
            availBox.appendChild(el('p', 'item-page__avail-line item-page__avail-line--off', 'No está disponible en la tienda actual.'));
            if (Array.isArray(shopHistory) && shopHistory.length > 0) {
                const last = shopHistory[shopHistory.length - 1];
                const lastDate = new Date(last);
                if (!isNaN(lastDate.getTime())) {
                    availBox.appendChild(el('p', 'item-page__avail-line', `Última vez en la tienda: ${lastDate.toLocaleDateString('es-ES', { day: '2-digit', month: 'long', year: 'numeric', timeZone: 'UTC' })}`));
                }
            }
        }
        // Panel de información translucido (descripción, set, meta de pista y
        // fechas), como el panel bg-black/45 de fortnite.com.
        const panel = el('div', 'item-page__panel');
        const panelMain = el('div', 'item-page__panel-main');

        if (isJamTrack && !isBundle && trackData) {
            const jamMeta = el('p', 'item-page__jam-meta');
            const parts = [singularType];
            if (trackData.releaseYear) parts.push(String(trackData.releaseYear));
            const durationLabel = formatTrackDuration(trackData.duration);
            if (durationLabel) parts.push(durationLabel);
            parts.forEach((text) => jamMeta.appendChild(el('span', 'item-page__jam-meta-part', text)));
            panelMain.appendChild(jamMeta);
        }

        const descriptionText = isBundle ? (entry?.bundle?.info || null) : (mainItem?.description || null);
        if (descriptionText && descriptionText.toLowerCase() !== 'null' && descriptionText.toLowerCase() !== 'bundle') {
            panelMain.appendChild(el('p', 'item-page__description', descriptionText));
        }
        if (mainItem?.set?.text) {
            panelMain.appendChild(el('p', 'item-page__meta-row', mainItem.set.text));
        }
        if (mainItem?.introduction?.text) {
            panelMain.appendChild(el('p', 'item-page__meta-row', mainItem.introduction.text));
        }

        if (panelMain.hasChildNodes()) panel.appendChild(panelMain);
        if (availBox.hasChildNodes()) {
            availBox.classList.add('item-page__panel-legal');
            if (panelMain.hasChildNodes()) availBox.classList.add('item-page__panel-legal--divided');
            panel.appendChild(availBox);
        }
        if (panel.hasChildNodes()) info.appendChild(panel);

        // CTA: solo si es comprable individualmente (nunca en solo-en-lote).
        if (status === 'comprable' && entry && entry.finalPrice > 0) {
            info.appendChild(buildCartEntry(entryToCartProduct(entry)));
        } else if (status === 'solo-en-lote') {
            const bundleName = availability?.bundle?.name || 'un lote';
            const bundleBox = el('div', 'item-page__bundle-link');
            bundleBox.appendChild(el('p', 'item-page__bundle-label', `Forma parte de: ${bundleName}`));
            const bundleUrl = availability?.bundle?.url
                || (containerEntry ? buildEntryPageUrl(containerEntry) : null);
            if (bundleUrl) {
                const link = document.createElement('a');
                link.className = 'item-page__bundle-cta';
                link.href = bundleUrl;
                link.dataset.route = '';
                link.textContent = 'VER OFERTA';
                bundleBox.appendChild(link);
            }
            info.appendChild(bundleBox);
        }

        // Dificultad de pistas de improvisación.
        const difficultyData = entry?.tracks?.[0]?.difficulty || (isJamTrack ? cosmeticRecord?.difficulty : null);
        if (difficultyData && !isBundle) {
            const node = buildJamTrackDifficultyNode(difficultyData);
            if (node) info.appendChild(node);
        }

        // Videos de la comunidad (CTA de subida / reporte / sello verificado).
        // Al publicarse un video, el reproductor se monta in-place (sin recarga).
        if (isJamTrack && !isBundle) {
            const trackName = trackData?.title || displayName;
            info.addEventListener('vc:published', (event) => {
                const { youtubeId, startTime, mediaType, mediaUrl, publishedAt } = event.detail || {};
                if (!youtubeId && !mediaUrl) return;
                const videoData = {
                    status: 'success',
                    media_type: mediaType || 'youtube',
                    media_url: mediaUrl || null,
                    youtube_id: youtubeId || null,
                    start_time: startTime,
                    published_at: publishedAt || null,
                };
                jamTrackVideoCache.set(trackName, videoData);
                markJamTrackVideoAvailable(entry);
                mountTrackMedia(media, optimizeImageUrl(heroImage) || CONFIG.PLACEHOLDER_IMAGE, videoData);
            });
            // Al eliminar su video, el autor vuelve a ver la carátula y la pista
            // queda sin video (caché + insignias de tarjeta), sin recarga.
            info.addEventListener('vc:deleted', () => {
                jamTrackVideoCache.delete(trackName);
                markJamTrackVideoUnavailable(entry);
                try {
                    if (currentYtPlayer && typeof currentYtPlayer.destroy === 'function') currentYtPlayer.destroy();
                } catch (_) { }
                currentYtPlayer = null;
                mountTrackFallbackImage(media, optimizeImageUrl(heroImage) || CONFIG.PLACEHOLDER_IMAGE);
            });
            try {
                window.BananoVideoCommunity?.mount(info, {
                    trackName,
                    cosmeticId: mainItem?.id || trackData?.id || id || '',
                });
            } catch (_) { }
        }

        // Selector de estilos (variantes) y carousel entre objetos de la oferta.
        if (entry && displayInfo) {
            if (!isBundle) {
                const styles = buildStyleSelector(entry, displayInfo, renderImages);
                if (styles) info.appendChild(styles);
            }
            attachOfferCarousel(media, mediaCaption, entry, displayInfo, itemVideos);
        }

        // Historial de tienda.
        const historyData = (Array.isArray(shopHistory) && shopHistory.length > 0)
            ? shopHistory
            : (entry?.shopHistory || cosmeticRecord?.shopHistory || null);
        const history = buildHistorySection(historyData);
        if (history) info.appendChild(history);

        grid.append(header, mediaCol, info);
        page.appendChild(grid);

        // Subítems (anchors a sus propias páginas), a lo ancho bajo el grid.
        if (entry && displayInfo) {
            const subItems = buildSubItemsSection(entry, displayInfo);
            if (subItems) page.appendChild(subItems);
        }

        outlet.replaceChildren(page);

        applyMediaState();
        mountCartEntry();

        // Métrica de click/vista (mismo tracker que usaban las tarjetas).
        try {
            if (entry?.offerId) {
                const formData = new FormData();
                formData.append('action', 'n4x');
                formData.append('offerId', entry.offerId);
                formData.append('productName', displayName);
                fetchWithTimeout('/web.php', { method: 'POST', body: formData }, 6000).catch(() => { });
            }
        } catch (_) { }
    }

    /* -------------------------- página de producto ----------------------- */

    /**
     * @param {HTMLElement} outlet
     * @param {object} product Fila de productos_estaticos (endpoint) o entrada
     *                         de staticProductsMap (render local).
     */
    function renderProductPage(outlet, product) {
        stopMedia();
        resetMediaState();
        unmountCartEntry();

        // Normalizar: staticProductsMap usa displayName/finalPrice; la BD usa nombre/precio_*.
        const nombre = product.displayName || product.nombre || 'Producto';
        const productId = String(product.offerId ?? product.productId ?? product.id ?? '');
        const descripcion = product.descripcion || null;
        const imagen = product.imagen_url || product.imagen || null;
        const precioBase = parseFloat(product.precio_base ?? product.basePrice ?? NaN);
        const precioOferta = parseFloat(product.precio_oferta ?? NaN);
        const hasOferta = !isNaN(precioBase) && !isNaN(precioOferta) && precioOferta > 0 && precioOferta < precioBase;
        const precioFinal = hasOferta ? precioOferta : (product.finalPrice ?? product.precio_base ?? product.precio ?? precioBase);
        const agotado = product.agotado === true || product.agotado === 'true'
            || product.isAgotado === true || String(product.stock_cantidad) === '0';
        const seccionNombre = product.staticConfig?.title || product.categoria || null;
        const seccionColor = product.staticConfig?.color || '#1E40AF';

        document.title = `${nombre} — Banano Store`;

        const page = el('div', 'item-page item-page--producto');
        page.appendChild(buildTopNav());

        const grid = el('div', 'item-page__grid');

        const media = el('div', 'item-page__media');
        media.id = 'item-page-media';
        if (product.bgStyle) media.style.cssText = product.bgStyle;
        const img = document.createElement('img');
        img.src = imagen ? optimizeImageUrl(imagen) : CONFIG.PLACEHOLDER_IMAGE;
        img.alt = nombre;
        img.className = 'max-w-full max-h-full object-contain rounded-md';
        media.replaceChildren(img);
        const mediaCol = el('div', 'item-page__media-col');
        mediaCol.appendChild(media);

        const info = el('div', 'item-page__info');

        const pills = el('div', 'item-page__pills');
        if (seccionNombre) pills.appendChild(buildPill(String(seccionNombre).toUpperCase(), { bg: seccionColor, color: '#fff' }));
        if (agotado) pills.appendChild(buildPill('AGOTADO', { bg: '#6b7280', color: '#fff' }));
        // Etiqueta configurada (badge) fuera de la imagen.
        try {
            if (hasOferta) {
                const textoEtiqueta = generarTextoEtiqueta(product);
                const expiryInfo = buildStaticOfferExpiryParts(product);
                const bgColor = (expiryInfo && expiryInfo.urgent) ? '#c53030' : (product.etiqueta_color_fondo || '#27ae60');
                const badge = buildStaticDiscountBadgeNode(product, textoEtiqueta, expiryInfo ? expiryInfo.parts : null, bgColor);
                if (badge) {
                    badge.classList.add('item-page__pill--badge');
                    pills.appendChild(badge);
                }
            }
        } catch (_) { }
        if (pills.hasChildNodes()) info.appendChild(pills);

        info.appendChild(el('h1', 'item-page__title', nombre));

        const priceBlock = el('div', 'item-page__price');
        priceBlock.appendChild(createPriceSpans(precioFinal, hasOferta ? precioBase : null, { finalClass: 'text-3xl font-black' }));
        info.appendChild(priceBlock);

        if (descripcion && String(descripcion).trim() && descripcion !== 'null') {
            const desc = el('div', 'item-page__description');
            desc.appendChild(el('h4', 'modal-meta-label font-semibold mb-2 text-sm', 'DESCRIPCIÓN:'));
            const descriptionContent = el('div', 'text-base leading-relaxed prose max-w-none');
            descriptionContent.innerHTML = descripcion;
            desc.appendChild(descriptionContent);
            info.appendChild(desc);
        }

        info.appendChild(buildCartEntry({
            offerId: productId,
            isStatic: true,
            name: nombre,
            price: precioFinal,
            priceBase: Number.isFinite(precioBase) ? precioBase : precioFinal,
            image: imagen ? optimizeImageUrl(imagen) : null,
            colors: { color1: seccionColor },
        }, { disabled: agotado, disabledLabel: agotado ? 'Agotado' : null }));

        grid.append(mediaCol, info);
        page.appendChild(grid);
        outlet.replaceChildren(page);

        mountCartEntry();
    }

    function unmount() {
        try { window.BananoVideoCommunity?.unmount(); } catch (_) { }
        stopMedia();
        unmountCartEntry();
        pauseManualVideos(null);
        if (offerCarousel) pauseSlideVideos(offerCarousel, null);
        offerCarousel = null;
        manualMedia = null;
    }

    return { renderObjectPage, renderProductPage, handlePageAction, unmount };
})();
// endregion item-pages

// region shop-timer
function computeShopResetDate(shopDateString) {
    return new Date(new Date(shopDateString).setUTCHours(24, 0, 0, 0));
}

function getShopTimerContainers() {
    return {
        desktop: document.getElementById('shop-timer'),
        mobile: document.getElementById('mobile-shop-timer')
    };
}

function computeTimeDiffMs(targetDate) {
    return targetDate - Date.now();
}

function formatTimerParts(diffMs) {
    const hours = String(Math.floor(diffMs / 3600000)).padStart(2, '0');
    const minutes = String(Math.floor((diffMs % 3600000) / 60000)).padStart(2, '0');
    const seconds = String(Math.floor((diffMs % 60000) / 1000)).padStart(2, '0');
    return { hours, minutes, seconds };
}

const _timerRefs = { desktop: null, mobile: null };

function _buildTimerPillDOM(container) {
    if (!container) return null;

    const icon = createAnimatedClockSVG('timer-pill__icon');

    const textWrap = document.createElement('span');
    textWrap.className = 'timer-pill__text';

    const hVal = document.createElement('span');
    const hUnit = document.createElement('span');
    hUnit.className = 'timer-pill__unit';
    hUnit.textContent = 'h';

    const sep1 = document.createElement('span');
    sep1.className = 'timer-pill__sep';
    sep1.textContent = '·';

    const mVal = document.createElement('span');
    const mUnit = document.createElement('span');
    mUnit.className = 'timer-pill__unit';
    mUnit.textContent = 'm';

    const sep2 = document.createElement('span');
    sep2.className = 'timer-pill__sep';
    sep2.textContent = '·';

    const sVal = document.createElement('span');
    const sUnit = document.createElement('span');
    sUnit.className = 'timer-pill__unit';
    sUnit.textContent = 's';

    textWrap.append(hVal, hUnit, sep1, mVal, mUnit, sep2, sVal, sUnit);
    container.replaceChildren(icon, textWrap);

    return { h: hVal, m: mVal, s: sVal };
}

function renderTimerParts(containers, parts) {
    for (const key of ['desktop', 'mobile']) {
        const el = containers[key];
        if (!el) continue;
        if (!_timerRefs[key]) {
            _timerRefs[key] = _buildTimerPillDOM(el);
        }
        const refs = _timerRefs[key];
        if (refs) {
            refs.h.textContent = parts.hours;
            refs.m.textContent = parts.minutes;
            refs.s.textContent = parts.seconds;
        }
    }
}

function renderTimerUpdated(containers) {
    _timerRefs.desktop = null;
    _timerRefs.mobile = null;

    const makeUpdated = () => {
        const s = document.createElement('span');
        s.className = 'timer-pill__updated';
        s.textContent = 'Tienda Actualizada';
        return s;
    };
    if (containers.desktop) containers.desktop.replaceChildren(makeUpdated());
    if (containers.mobile) containers.mobile.replaceChildren(makeUpdated());
}


function startShopTimer(shopDateString) {
    if (shopTimerInterval) clearInterval(shopTimerInterval);
    _timerRefs.desktop = null;
    _timerRefs.mobile = null;

    const resetDate = computeShopResetDate(shopDateString);
    const containers = getShopTimerContainers();

    const updateTimer = () => {
        const diff = computeTimeDiffMs(resetDate);
        if (diff <= 0) {
            renderTimerUpdated(containers);
            clearInterval(shopTimerInterval);
            return;
        }
        const parts = formatTimerParts(diff);
        renderTimerParts(containers, parts);
    };

    updateTimer();
    shopTimerInterval = setInterval(updateTimer, 1000);
    Object.values(containers).forEach(container => container?.classList.remove('hidden'));
}

/** Calcula el próximo reset diario de tienda (00:00 UTC). */
function calculateNextResetDate() {
    const now = new Date();
    const reset = new Date(Date.UTC(
        now.getUTCFullYear(),
        now.getUTCMonth(),
        now.getUTCDate() + 1,
        0, 0, 0, 0
    ));
    return reset;
}

function startShopTimerLocal() {
    if (shopTimerInterval) clearInterval(shopTimerInterval);
    _timerRefs.desktop = null;
    _timerRefs.mobile = null;

    const resetDate = calculateNextResetDate();
    const containers = getShopTimerContainers();

    const updateTimer = () => {
        const diff = computeTimeDiffMs(resetDate);
        if (diff <= 0) {
            renderTimerUpdated(containers);
            clearInterval(shopTimerInterval);
            return;
        }
        const parts = formatTimerParts(diff);
        renderTimerParts(containers, parts);
    };

    updateTimer();
    shopTimerInterval = setInterval(updateTimer, 1000);
    Object.values(containers).forEach(container => container?.classList.remove('hidden'));
}
// endregion shop-timer

/**
 * Arranca el worker de la tienda (Vía Regalo). Idempotente: solo envía START_APP
 * una vez. Lo invoca el router (viaregalo.view.js) en el primer mount, en lugar
 * del antiguo arranque eager en DOMContentLoaded.
 */
let _shopStarted = false;
function startShop() {
    if (_shopStarted) return;
    _shopStarted = true;
    shopWorker.postMessage({
        type: 'START_APP',
        payload: { baseUrl: window.location.origin }
    });
}

Logger.with('AppInit').debug('Checkpoint 4: before initialization IIFE');
window.addEventListener("DOMContentLoaded", () => {
    (async () => {
        try {
            Logger.with('AppInit').debug('Checkpoint 5: inside initialization IIFE');

            initializePageElements();
            setupStaticEventListeners();

            // Timer local inmediato (sin depender del worker)
            try {
                startShopTimerLocal();
                Logger.with('AppInit').info('Shop timer started instantly (local UTC calculation)');
            } catch (timerErr) {
                Logger.with('AppInit').warn('Could not start local timer', timerErr);
            }

            // Esqueletos placeholder antes de que lleguen datos reales
            try {
                const PLACEHOLDER_SECTIONS = [
                    { id: 'placeholder-section-1', title: 'Cargando...', layout: 'grid', items: [] },
                    { id: 'placeholder-section-2', title: 'Cargando...', layout: 'grid', items: [] },
                    { id: 'placeholder-section-3', title: 'Cargando...', layout: 'grid', items: [] },
                    { id: 'placeholder-section-4', title: 'Cargando...', layout: 'grid', items: [] },
                ];
                renderSectionSkeletons(PLACEHOLDER_SECTIONS);
                document.getElementById('loading-overlay')?.classList.add('hidden');
                document.getElementById('error-message')?.classList.add('hidden');
                document.getElementById('view-viaregalo')?.removeAttribute('hidden');
                Logger.with('AppInit').info('Instant skeleton shell rendered');
            } catch (shellErr) {
                Logger.with('AppInit').warn('Could not render instant skeleton shell', shellErr);
                try {
                    const sc = document.getElementById('viaregalo-content');
                    if (sc && !sc.querySelector('.spinner-container')) SectionSpinner.show('viaregalo', sc, 48);
                } catch (_) { }
            }

            // Vía Regalo (worker de la tienda) y Vía Cuenta ahora se cargan de
            // forma perezosa cuando el router monta cada vista (main.js + views/).
            // viaregalo.view.js dispara BananoApp.startShop() en su mount inicial
            // y viacuenta.view.js carga el catálogo la primera vez que se visita.

            // Tareas auxiliares en paralelo (no bloquean el hilo principal)
            fetchSpinDiscounts().catch(err => Logger.with('AppInit').warn('fetchSpinDiscounts background error', err));
            Logger.with('AppInit').info('Auxiliary tasks dispatched (fire-and-forget)');

            document.getElementById('error-message')?.classList.add('hidden');

            setupDynamicEventListeners();

            // Superficie de integración que consume el router (main.js). Se publica
            // al final del arranque y se anuncia con el evento `banano:app-ready`,
            // que es lo que espera main.js antes de iniciar el router.
            window.BananoApp = {
                gatewayFetch,
                startShop,
                viaCuenta: {
                    spinner: viaCuentaSpinner,
                    render: renderViaCuentaCatalog,
                    renderError: renderViaCuentaError,
                },
                itemPages: {
                    renderObjectPage: ItemPages.renderObjectPage,
                    renderProductPage: ItemPages.renderProductPage,
                    unmount: ItemPages.unmount,
                    findShopEntryForPageId,
                    findItemDataById,
                },
            };
            document.dispatchEvent(new CustomEvent('banano:app-ready'));

        } catch (err) {
            Logger.with('AppInit').error('Fatal error during initialization', err);
            showError();
        }
    })();
});
fetchWithTimeout('/web.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=w1v' }, 6000)
    .catch(error => Logger.with('Network').error('Failed to track page view', error));

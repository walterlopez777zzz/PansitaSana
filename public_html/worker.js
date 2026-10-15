/**
 * @fileoverview Worker para la Tienda de Fortnite (v4 - Backend interno).
 * Responsabilidad: Obtener la estructura de la tienda desde el backend interno
 * (banano_primordial.json servido vía /web.php), procesarla en secciones y
 * enviarla al hilo principal. Ya NO contacta ninguna API externa de Fortnite.
 */

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

// --- Logger Básico ---
const LEVELS = { error: 0, warn: 1, info: 2, debug: 3 };
const CURRENT_LEVEL = 'debug'; // Cambia a 'warn' para producción
const shouldLog = (level) => LEVELS[level] <= LEVELS[CURRENT_LEVEL];
const logger = {
    info: (msg, payload) => shouldLog('info') && console.info(`[Worker] ${msg}`, payload !== undefined ? payload : ''),
    warn: (msg, payload) => shouldLog('warn') && console.warn(`[Worker] ${msg}`, payload !== undefined ? payload : ''),
    error: (msg, payload) => shouldLog('error') && console.error(`[Worker] ${msg}`, payload !== undefined ? payload : ''),
};

// --- Configuración ---
const CONFIG = {
    // La estructura de la tienda llega desde el backend interno (/web.php → DAL).
    // BESTSELLERS_URL es un CDN de Epic (no fortnite-api.com) usado solo para el
    // ranking de "más vendidos"; se mantiene.
    BESTSELLERS_URL: 'https://cdn2.unrealengine.com/fn_bsdata/ebb74910-dd35-44b8-b826-d58dc16c6456.json',
    FETCH_TIMEOUT: 12000,
};

let shopCache = null;

// --- Lógica Principal ---

/**
 * Realiza una petición fetch con reintentos y timeout.
 */
async function fetchWithRetry(url, options = {}, retries = 2) {
    for (let i = 0; i <= retries; i++) {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), CONFIG.FETCH_TIMEOUT);
            const response = await fetch(url, { signal: controller.signal, referrerPolicy: 'strict-origin-when-cross-origin', ...options });
            clearTimeout(timeoutId);
            if (!response.ok) throw new Error(`HTTP error ${response.status}`);
            return response.json();
        } catch (error) {
            logger.warn(`Intento ${i + 1} para ${url} falló.`, error.message);
            if (i === retries) throw error;
            await new Promise(res => setTimeout(res, 1000 * (i + 1)));
        }
    }
}

/** Build POST options for the /web.php gateway */
function gatewayPost(action) {
    return { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=' + encodeURIComponent(action) };
}

const memoizedStyles = new Map();
const generateBackgroundStyle = (colors) => {
    if (!colors) return 'background-color: #333;';
    // 5. Clave de memoización optimizada y más corta.
    const key = `${String(colors.color1 || '').slice(0, 6)}|${String(colors.color2 || '').slice(0, 6)}|${String(colors.color3 || '').slice(0, 6)}`;
    if (memoizedStyles.has(key)) return memoizedStyles.get(key);
    
    const validColors = [colors.color1, colors.color3, colors.color2]
        .filter(Boolean)
        .map(c => `#${c.slice(0, 6)}`);
    
        const style = validColors.length >= 2 ? `background-image: linear-gradient(180deg, ${validColors[0]} 0%, ${(validColors[1] ?? validColors[0])} 65%, ${(validColors[2] ?? validColors[1] ?? validColors[0])} 100%);`
        : validColors.length === 1 ? `background-color: ${validColors[0]};` : 'background-color: #333;';
    
    memoizedStyles.set(key, style);
    return style;
};

// --- Constantes de Procesamiento ---
const PROCESSING_CONFIG = Object.freeze({
    DEFAULT_RANK: -1,
    DEFAULT_SECTION_NAME: 'Artículos Destacados',
});

/**
 * Enriquece las entradas crudas de la API con estilos y claves de ordenamiento.
 * Resultado reutilizable por ambos pipelines (Main Shop y Bestsellers).
 */
function enrichItems(rawEntries, automaticOffersMap) {
    return rawEntries.map(item => {
        const automaticDiscountInfo = automaticOffersMap.get(item.offerId);
        return {
            ...item,
            ...(automaticDiscountInfo && { automaticDiscountInfo }),
            bgStyle: generateBackgroundStyle(item.colors),
            _sortKeys: {
                rank: item.layout?.rank ?? PROCESSING_CONFIG.DEFAULT_RANK,
                suffix: parseInt(item.layoutId?.split('.').pop() || '0', 10),
                priority: item.sortPriority ?? 0,
            },
        };
    });
}

/**
 * PIPELINE A: Procesa la tienda principal (sin bestsellers).
 * Devuelve la sección de ofertas random, las secciones regulares y los items
 * enriquecidos para que Pipeline B los reutilice.
 */
function processMainShopData(shopData, randomOfferIds, automaticOffersMap) {
    const { entries: rawEntries = [], date } = shopData?.data || {};
    if (rawEntries.length === 0) {
        return { randomOfferSection: null, regularSections: [], date, enrichedItems: [] };
    }

    const enrichedItems = enrichItems(rawEntries, automaticOffersMap);

    // --- Sección "Ofertas Random del Día" ---
    let randomOfferSection = null;
    const randomOfferItems = enrichedItems.filter(item => randomOfferIds.has(item.offerId));
    if (randomOfferItems.length > 0) {
        randomOfferSection = {
            id: 'section-random-offer',
            title: 'Ofertas random del día',
            mobileTitle: 'Ofertas Random',
            layout: 'grid',
            items: randomOfferItems,
        };
    }

    // --- Secciones Regulares ---
    const regularSections = Array.from(
        enrichedItems.reduce((acc, item) => {
            const sectionName = item.layout?.name || PROCESSING_CONFIG.DEFAULT_SECTION_NAME;
            if (!acc.has(sectionName)) acc.set(sectionName, []);
            acc.get(sectionName).push(item);
            return acc;
        }, new Map())
    )
    .sort(([, itemsA], [, itemsB]) => (itemsB[0]._sortKeys.rank) - (itemsA[0]._sortKeys.rank))
    .map(([sectionName, items]) => {
        const finalOrderedItems = items.sort((a, b) =>
            b._sortKeys.suffix - a._sortKeys.suffix || b._sortKeys.priority - a._sortKeys.priority
        );
        if (finalOrderedItems.length === 0) return null;

        const representativeItem = finalOrderedItems[0];
        const safeId = (representativeItem.layout?.id || sectionName).replace(/\W+/g, '-').toLowerCase();

        return {
            id: `section-${safeId}`,
            layoutId: representativeItem.layout?.id || null,
            title: sectionName,
            layout: 'grid',
            items: finalOrderedItems,
        };
    })
    .filter(Boolean)
    .filter(section => section.title !== PROCESSING_CONFIG.DEFAULT_SECTION_NAME);

    return { randomOfferSection, regularSections, date, enrichedItems };
}

/**
 * PIPELINE B: Construye la sección de bestsellers a partir de items ya enriquecidos.
 * Se ejecuta de forma independiente cuando la CDN de bestsellers responde.
 */
function processBestsellers(enrichedItems, bestsellersResponse) {
    const bestsellerOfferIds = bestsellersResponse?.bestsellers_list?.offer_list || [];
    if (bestsellerOfferIds.length === 0) return null;

    const bestsellerIds = new Set(bestsellerOfferIds);
    const bestsellerItems = enrichedItems.filter(item => bestsellerIds.has(item.offerId));
    if (bestsellerItems.length === 0) return null;

    return {
        id: 'section-bestsellers-carousel',
        title: 'Lo más vendido',
        mobileTitle: 'Más Vendido',
        layout: 'carousel',
        items: bestsellerItems,
    };
}


/**
 * Orquesta la obtención y procesamiento de los datos de la tienda.
 * Usa dos pipelines independientes para garantizar streaming real:
 *   Pipeline A: Shop + Random + Automatic → render inmediato
 *   Pipeline B: Bestsellers (CDN lenta) → prepend diferido
 */
async function syncShop() {
    logger.info("Iniciando sincronización incremental de la tienda...");
    try {
        const ENDPOINTS = {
            SHOP_WITH_DISCOUNTS: 'g1c',
            BESTSELLERS_URL: CONFIG.BESTSELLERS_URL,
            RANDOM_OFFERS: 'd6j',
            AUTOMATIC_OFFERS: 'y9a',
        };

        // ============================================================
        // PIPELINE B: Lanzar fetch de Bestsellers AHORA (fire-and-forget).
        // No bloqueará el pipeline principal.
        // ============================================================
        const bestsellersPromise = fetchWithRetry(ENDPOINTS.BESTSELLERS_URL).catch(err => {
            logger.warn('Bestsellers fetch failed (non-blocking).', err.message);
            return null;
        });

        // ============================================================
        // PIPELINE A: Obtener Shop + Random + Automatic en paralelo.
        // Estas son las dependencias MÍNIMAS para renderizar la tienda.
        // ============================================================
        const [shopResponse, randomOffersResponse, automaticOffersResponse] = await Promise.all([
            fetchWithRetry('/web.php', gatewayPost(ENDPOINTS.SHOP_WITH_DISCOUNTS)).then(r => _resolveSyncFrame(r)),
            fetchWithRetry('/web.php', gatewayPost(ENDPOINTS.RANDOM_OFFERS)).then(r => _resolveSyncFrame(r)).catch(() => null),
            fetchWithRetry('/web.php', gatewayPost(ENDPOINTS.AUTOMATIC_OFFERS)).then(r => _resolveSyncFrame(r)).catch(() => []),
        ]);

        if (!shopResponse || shopResponse.status !== 200 || !shopResponse.data) {
            throw new Error('La respuesta del backend principal no es válida.');
        }

        // --- Procesar datos del pipeline principal ---
        const randomOfferIds = new Set(
            (randomOffersResponse?.success && randomOffersResponse.discounts)
                ? Object.keys(randomOffersResponse.discounts)
                : []
        );
        const automaticOffersMap = new Map(
            (Array.isArray(automaticOffersResponse) ? automaticOffersResponse : [])
                .map(offer => [offer.offer_id, offer])
        );

        const { randomOfferSection, regularSections, date, enrichedItems } =
            processMainShopData(shopResponse, randomOfferIds, automaticOffersMap);

        // --- Construir lote inicial: Ofertas Random + primeras secciones regulares ---
        const INITIAL_REGULAR_COUNT = 3;
        const initialRegular = regularSections.slice(0, INITIAL_REGULAR_COUNT);
        const remainingRegular = regularSections.slice(INITIAL_REGULAR_COUNT);

        const initialSections = [];
        if (randomOfferSection) initialSections.push(randomOfferSection);
        initialSections.push(...initialRegular);

        // 1. ENVIAR LOTE INICIAL → Oculta spinner, muestra contenido real.
        //    Incluye directiva para que el frontend reserve espacio para Bestsellers.
        self.postMessage({
            type: 'SHOP_INITIAL_BATCH',
            payload: {
                sections: initialSections,
                date,
                showBestsellersSkeleton: true,
            }
        });
        logger.info(`Pipeline A: Lote inicial enviado (${initialSections.length} secciones).`);

        // 2. STREAMING: Enviar secciones regulares restantes en chunks.
        const CHUNK_SIZE = 2;
        for (let i = 0; i < remainingRegular.length; i += CHUNK_SIZE) {
            await new Promise(res => setTimeout(res, 80));
            self.postMessage({
                type: 'SHOP_CHUNK',
                payload: { sections: remainingRegular.slice(i, i + CHUNK_SIZE) }
            });
        }

        // 3. SEÑAL DE FIN del streaming principal.
        self.postMessage({ type: 'SHOP_COMPLETE', payload: { date } });
        logger.info(`Pipeline A: Streaming completo (${regularSections.length} regulares + ${randomOfferSection ? 1 : 0} random).`);

        // ============================================================
        // PIPELINE B (continuación): Ahora esperamos Bestsellers.
        // Si ya resolvió, esto retorna instantáneamente.
        // ============================================================
        const bestsellersResponse = await bestsellersPromise;
        const bestsellerSection = processBestsellers(enrichedItems, bestsellersResponse);

        if (bestsellerSection) {
            self.postMessage({
                type: 'SHOP_PREPEND_SECTION',
                payload: { section: bestsellerSection }
            });
            logger.info(`Pipeline B: Bestsellers enviados (${bestsellerSection.items.length} items).`);
        } else {
            // Indicar al frontend que remueva el skeleton placeholder
            self.postMessage({
                type: 'SHOP_PREPEND_SECTION',
                payload: { section: null }
            });
            logger.info('Pipeline B: No hay bestsellers disponibles. Skeleton será removido.');
        }

        // 4. Actualizar caché completa (incluyendo bestsellers si existen).
        const allSections = [
            ...(bestsellerSection ? [bestsellerSection] : []),
            ...(randomOfferSection ? [randomOfferSection] : []),
            ...regularSections,
        ];
        shopCache = {
            sections: allSections,
            date,
        };

    } catch (error) {
        logger.error("Falló la sincronización de la tienda.", error);
        self.postMessage({ type: 'ERROR', payload: { message: error.message } });
    }
}

// --- Manejador de mensajes del hilo principal ---
self.onmessage = (e) => {
    const { type } = e.data || {};

    if (type === 'START_APP') {
        if (shopCache) {
            logger.info("Enviando tienda desde caché al iniciar.");
            self.postMessage({ type: 'SHOP_UPDATED', payload: shopCache });
        } else {
            syncShop();
        }
    }
};
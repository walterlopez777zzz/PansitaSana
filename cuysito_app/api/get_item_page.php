<?php
/**
 * ============================================================================
 * BANANO STORE — ITEM PAGE AGGREGATE ENDPOINT (get_item_page.php)
 * ============================================================================
 * Single server-side fetch behind the item pages (/objeto/... y /producto/...).
 * Merges, in ONE response, everything a page needs so deep links render with
 * a single request and zero client-side external calls:
 *
 *   - Cosmetic data from the local cosmetics index (HQ images, descripción,
 *     rareza, serie, introducción, historial) with live single-item fallback.
 *   - Current shop availability resolved from the canonical snapshot:
 *       comprable | solo-en-lote | no-disponible
 *   - The full current shop entry (prices in soles, discounts, styles...).
 *   - Manual video (tabla videos_objetos) — decided server-side so the page
 *     never paints an image first and swaps it for a video.
 *   - Static product data (kind=producto) from productos_estaticos.
 *
 * Gateway action: 'get_item_page' (obfuscated: 'k3t') via /web.php.
 * ============================================================================
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/shop_config.php';
require_once __DIR__ . '/../core/ShopRepository.php';
require_once __DIR__ . '/../core/CosmeticsRepository.php';
require_once __DIR__ . '/../core/ItemVideosRepository.php';
require_once __DIR__ . '/../core/ItemRenderImagesRepository.php';
require_once __DIR__ . '/../core/ShopEntryLocator.php';
require_once __DIR__ . '/../core/SectionBackdropResolver.php';

$id   = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$kind = isset($_GET['kind']) ? strtolower(trim((string) $_GET['kind'])) : 'objeto';

if ($id === '' || strlen($id) > 191 || !preg_match('#^[a-zA-Z0-9_.:/\-]+$#', $id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'ID inválido.']);
    exit;
}

/* ------------------------------- helpers -------------------------------- */

/** Mirrors slugifyForUrl in app.js: 'Ghoul Trooper' → 'ghoul-trooper'. */
function slugify_for_url(string $text): string
{
    // Transliterate to ASCII (remove diacritics).
    $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    $text = substr($text, 0, 80);
    return $text !== '' ? $text : 'objeto';
}

/** Mirrors typeSlugForItem in app.js. */
function type_slug_for_item(array $item, bool $isJamTrack): string
{
    static $slugs = [
        'outfit' => 'traje', 'backpack' => 'accesorio-mochilero', 'pickaxe' => 'pico',
        'glider' => 'ala-delta', 'emote' => 'gesto', 'wrap' => 'envoltorio',
        'shoe' => 'calzado', 'shoes' => 'calzado', 'footwear' => 'calzado',
        'contrail' => 'estela', 'trail' => 'rastro', 'sidekick' => 'companero',
        'mic' => 'instrumento', 'bass' => 'instrumento', 'guitar' => 'instrumento',
        'keyboard' => 'instrumento', 'drum' => 'instrumento', 'instrument' => 'instrumento',
        'sparks_bass' => 'instrumento', 'jamtrack' => 'pista-de-improvisacion',
        'sparks_song' => 'pista-de-improvisacion', 'carbody' => 'coche',
        'wheel' => 'rueda', 'vehicle_wheel' => 'rueda', 'boost' => 'potenciador',
        'spray' => 'grafiti', 'music' => 'musica', 'loadingscreen' => 'pantalla-de-carga',
        'battlebus' => 'autobus-de-batalla', 'bundle' => 'lote', 'default' => 'articulo',
    ];
    if ($isJamTrack) return $slugs['jamtrack'];
    $typeId = $item['type']['value'] ?? 'default';
    if ($typeId === 'shoe' || $typeId === 'shoes') $typeId = 'footwear';
    return $slugs[$typeId] ?? $slugs['default'];
}

/**
 * Mirrors buildEntryPageUrl in app.js: builds the page URL for a shop entry.
 * Returns null only if the entry is unusable.
 */
function build_entry_page_url(array $entry): ?string
{
    $isBundle  = ShopEntryLocator::isBundle($entry);
    $offerId   = $entry['offerId'] ?? '';
    $isJamTrack = !empty($entry['tracks']);

    if ($isBundle) {
        $name = $entry['bundle']['name'] ?? ($entry['displayName'] ?? '');
        $slug = slugify_for_url($name);
        return '/via-regalo/lote/' . $slug . '--' . ShopEntryLocator::sanitizeOfferId($offerId);
    }

    // Non-bundle: find the main item.
    $mainItem = null;
    if ($isJamTrack && isset($entry['tracks'][0])) {
        $mainItem = $entry['tracks'][0];
    } else {
        foreach (['brItems', 'instruments', 'cars'] as $key) {
            if (isset($entry[$key][0])) { $mainItem = $entry[$key][0]; break; }
        }
    }

    $displayName = $entry['displayName']
        ?? ($mainItem['name'] ?? ($mainItem['title'] ?? ''));
    if ($displayName === '') return null;

    $itemId = $mainItem['id'] ?? null;
    if ($itemId === null || $itemId === '') {
        // Multi-item offer without bundle: use offerId as identifier.
        $slug = slugify_for_url($displayName);
        return '/via-regalo/lote/' . $slug . '--' . ShopEntryLocator::sanitizeOfferId($offerId);
    }

    $tipo = type_slug_for_item($mainItem, $isJamTrack);
    $slug = slugify_for_url($displayName);
    return '/via-regalo/' . $tipo . '/' . $slug . '--' . rawurlencode($itemId);
}

/** Best-effort DB handle; the page must still work if the DB is down. */
function item_page_db(): ?mysqli
{
    static $resolved = false, $handle = null;
    if ($resolved) {
        return $handle;
    }
    $resolved = true;
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        require_once __DIR__ . '/db_connect.php';
        if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
            $handle = $conn;
        }
    } catch (Throwable $e) {
        $handle = null;
    }
    return $handle;
}

/* ---------------------------- kind=producto ------------------------------ */

if ($kind === 'producto') {
    $conn = item_page_db();
    if (!$conn) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => 'Servicio no disponible.']);
        exit;
    }
    $productId = (int) $id;
    $stmt = $conn->prepare(
        'SELECT id, nombre, descripcion, stock_cantidad, precio_base, precio_oferta, tipo_oferta,
                imagen_url, categoria, fecha_expiracion, oferta_fecha_expiracion, urgencia_horas,
                etiqueta_texto, etiqueta_posicion, etiqueta_color_fondo, etiqueta_color_texto,
                precio_oferta_color, activo
         FROM productos_estaticos WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Error interno.']);
        exit;
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Producto no encontrado.']);
        exit;
    }
    $row['agotado'] = ((int) $row['stock_cantidad'] === 0);
    echo json_encode(['ok' => true, 'kind' => 'producto', 'product' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ----------------------------- kind=objeto ------------------------------- */

$lowerId = strtolower($id);
$looksLikeOffer = ShopEntryLocator::looksLikeOfferId($id);

$snapshotItems = ShopRepository::items();

/* Acumular en el caché permanente los renderImages (arte grande de tienda)
 * del snapshot actual; barato cuando la tienda no cambió. */
$snapForRenders = ShopRepository::snapshot();
ItemRenderImagesRepository::updateFromEntries(
    $snapshotItems,
    $snapForRenders['shop_date'] ?? ($snapForRenders['data']['date'] ?? null)
);

$located        = ShopEntryLocator::locate($id, $snapshotItems);
$currentEntry   = $located['entry'];      // Entry whose MAIN subject is this id (or the offer itself).
$containerEntry = $located['container'];  // Bundle/multi-item entry that merely contains the id.

/* Cosmetic record (skip for offer-id lookups of bundles). */
$cosmetic = null;
if (!$looksLikeOffer) {
    $cosmetic = CosmeticsRepository::findByIdWithLiveFallback($id);
}

if ($cosmetic === null && $currentEntry === null && $containerEntry === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Objeto no encontrado.']);
    exit;
}

/* Availability state. */
$availability = ['status' => 'no-disponible', 'offerId' => null, 'bundle' => null];
if ($currentEntry !== null && isset($currentEntry['finalPrice']) && (int) $currentEntry['finalPrice'] > 0) {
    $availability['status']  = 'comprable';
    $availability['offerId'] = $currentEntry['offerId'] ?? null;
} elseif ($containerEntry !== null) {
    $availability['status'] = 'solo-en-lote';
    $bundleName = $containerEntry['bundle']['name'] ?? ($containerEntry['displayName'] ?? null);
    $availability['bundle'] = [
        'offerId' => $containerEntry['offerId'] ?? null,
        'name'    => $bundleName,
        'image'   => $containerEntry['bundle']['image'] ?? ($containerEntry['images']['featured'] ?? null),
        'url'     => build_entry_page_url($containerEntry),
    ];
}

/* Manual video: DB first, snapshot map as degraded fallback. */
$video = null;
$videoLookupId = $lowerId;
if ($looksLikeOffer && $currentEntry !== null) {
    // Offer URLs (cards navigate by offerId): the video belongs to the offer's
    // lead cosmetic, even when the offer ships extra items or is a lote.
    $mainId = ShopEntryLocator::mainItemId($currentEntry);
    if ($mainId !== null) {
        $videoLookupId = $mainId;
    }
}
try {
    $conn = item_page_db();
    if ($conn) {
        $video = ItemVideosRepository::findActiveVideos($conn, $videoLookupId);
    }
} catch (Throwable $e) {
    $video = null;
}
if ($video === null) {
    foreach ($snapshotItems as $entry) {
        if (is_array($entry) && isset($entry['manualVideo']['cosmeticId'])
            && strtolower((string) $entry['manualVideo']['cosmeticId']) === $videoLookupId) {
            $video = [
                'url'    => isset($entry['manualVideo']['url']) && is_string($entry['manualVideo']['url']) ? $entry['manualVideo']['url'] : null,
                'styles' => isset($entry['manualVideo']['styles']) && is_array($entry['manualVideo']['styles']) ? $entry['manualVideo']['styles'] : [],
            ];
            break;
        }
    }
}

/* Videos de TODOS los objetos de la oferta (carousel de incluidos):
 * DB en una sola query IN(); itemVideos del snapshot como fallback degradado. */
$itemVideos = null;
if ($currentEntry !== null) {
    $offerItemIds = array_keys(ShopEntryLocator::itemIds($currentEntry));
    if (count($offerItemIds) > 1) {
        try {
            $conn = item_page_db();
            if ($conn) {
                $found = ItemVideosRepository::findActiveVideosForIds($conn, $offerItemIds);
                if (!empty($found)) {
                    $itemVideos = $found;
                }
            }
        } catch (Throwable $e) {
            $itemVideos = null;
        }
        if ($itemVideos === null && isset($currentEntry['itemVideos']) && is_array($currentEntry['itemVideos'])) {
            $itemVideos = $currentEntry['itemVideos'];
        }
    }
}
$itemVideosOut = null;
if (is_array($itemVideos) && !empty($itemVideos)) {
    $itemVideosOut = [];
    foreach ($itemVideos as $cid => $v) {
        if (!is_array($v)) {
            continue;
        }
        $itemVideosOut[$cid] = [
            'url'    => isset($v['url']) && is_string($v['url']) && $v['url'] !== '' ? $v['url'] : null,
            'styles' => (object) (isset($v['styles']) && is_array($v['styles']) ? $v['styles'] : []),
        ];
    }
    $itemVideosOut = !empty($itemVideosOut) ? (object) $itemVideosOut : null;
}

/* RenderImages (arte grande) por ítem, del caché permanente: para el hero de
 * la página y el carousel de incluidos, que la API de cosméticos ya no cubre. */
$renderImageIds = [];
$renderSourceEntry = $currentEntry ?? $containerEntry;
if ($renderSourceEntry !== null) {
    $renderImageIds = array_keys(ShopEntryLocator::itemIds($renderSourceEntry));
}
if (!$looksLikeOffer) {
    $renderImageIds[] = $lowerId;
}
$itemRenderImages = ItemRenderImagesRepository::getForIds(array_values(array_unique($renderImageIds)));
$itemRenderImagesOut = !empty($itemRenderImages) ? (object) $itemRenderImages : null;

/* Shop history: prefer the (fresher) cosmetics index; entry data as backup. */
$shopHistory = null;
if (is_array($cosmetic) && isset($cosmetic['shopHistory']) && is_array($cosmetic['shopHistory'])) {
    $shopHistory = $cosmetic['shopHistory'];
}

$snap = ShopRepository::snapshot();

// Fondo de la sección a la que pertenece el objeto (oferta propia o lote
// contenedor); null cuando aplica el fondo por defecto global (el cliente lo
// resuelve desde su propia configuración).
$sectionBackground = SectionBackdropResolver::forShopEntries($currentEntry, $containerEntry);

// Container background: when the item only exists inside a bundle, send the
// bundle's visual colours so deep-link pages can paint the correct gradient
// without waiting for the full shop snapshot on the client.
$containerBg = null;
if ($containerEntry !== null && $currentEntry === null) {
    $containerBg = [
        'colors'  => $containerEntry['colors']  ?? null,
        'bgStyle' => $containerEntry['bgStyle'] ?? null,
    ];
}

echo json_encode([
    'ok'           => true,
    'kind'         => 'objeto',
    'id'           => $id,
    'cosmetic'     => $cosmetic,
    'entry'        => $currentEntry,
    'availability' => $availability,
    'containerBg'  => $containerBg,
    'video'        => is_array($video)
        ? ['url' => $video['url'], 'styles' => (object) ($video['styles'] ?? [])]
        : null,
    'itemVideos'   => $itemVideosOut,
    'itemRenderImages' => $itemRenderImagesOut,
    'shopHistory'  => $shopHistory,
    'sectionBackground' => $sectionBackground,
    'shopDate'     => $snap['shop_date'] ?? null,
    'conversionRate' => $snap['meta']['conversion_rate'] ?? ShopSourceConfig::DEFAULT_CONVERSION_RATE,
], JSON_UNESCAPED_UNICODE);
exit;

<?php
/**
 * ============================================================================
 * BANANO STORE — DATA LAYER MANAGER · BACKEND API (Phase 5)
 * ============================================================================
 * AJAX endpoint for the "Gestor de Capa de Datos" admin panel. It is the only
 * write path for the deep data layer: master controls (source mode + forced
 * regeneration), item overrides, the configurable API field map and the
 * observability log.
 *
 * The editable source of truth lives in MySQL (auditable). On every save the
 * relevant table is COMPILED to the lightweight artifacts the runtime DAL
 * already consumes, so the storefront hot-path never queries the DB:
 *   - dl_shop_overrides -> cuysito_app/api/item_overrides.json
 *   - dl_api_field_map  -> cuysito_app/cache/api_field_map.json
 * ============================================================================
 */

require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';
require_once __DIR__ . '/../../config/shop_config.php';
require_once __DIR__ . '/../../core/ShopRepository.php';
require_once __DIR__ . '/../../core/ShopNormalizer.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado.']);
    exit;
}

/** Emit a JSON response and stop. */
function dl_respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Plural type label per cosmetic type.value — same table the storefront uses
 * (app.js CONFIG.TYPE_TRANSLATIONS_PLURAL) to set data-item-type on the card,
 * which drives the per-type artwork framing CSS. Declared before the router
 * dispatches: top-level `const` statements run in file order (they are not
 * hoisted like functions), so it must exist before any action executes.
 */
const DL_TYPE_TRANSLATIONS_PLURAL = [
    'outfit' => 'Trajes', 'emote' => 'Gestos', 'glider' => 'Alas delta',
    'pickaxe' => 'Picos', 'backpack' => 'Accesorios mochileros',
    'wrap' => 'Envoltorios', 'shoe' => 'Calzado', 'contrail' => 'Estelas',
    'carbody' => 'Coches', 'wheel' => 'Ruedas',
    'jamtrack' => 'Pistas de improvisación', 'sparks_song' => 'Pistas de improvisación',
    'trail' => 'Rastros', 'boost' => 'Potenciadores', 'sidekick' => 'Compañeros',
    'mic' => 'Instrumentos', 'bass' => 'Instrumentos', 'guitar' => 'Instrumentos',
    'keyboard' => 'Instrumentos', 'drum' => 'Instrumentos', 'bundle' => 'Lotes',
    'shoes' => 'Calzado', 'footwear' => 'Calzado', 'instrument' => 'Instrumentos',
    'sparks_bass' => 'Instrumentos', 'vehicle_wheel' => 'Ruedas',
    'cosmeticvariant' => 'Estilos', 'cosmetic' => 'Cosméticos',
    'itemaccess' => 'Accesos', 'spray' => 'Grafitis', 'music' => 'Música',
    'loadingscreen' => 'Pantallas de carga', 'battlebus' => 'Autobuses de batalla',
];

$method = $_SERVER['REQUEST_METHOD'];
$action = ($method === 'GET') ? ($_GET['accion'] ?? '') : ($_POST['accion'] ?? '');

try {
    if ($method === 'GET') {
        switch ($action) {
            case 'health':            dl_respond(dl_health($conn)); break;
            case 'get_items':         dl_respond(dl_get_items($conn, $_GET['src'] ?? 'active')); break;
            case 'get_item':          dl_respond(dl_get_item($conn, (string) ($_GET['offerId'] ?? ''), $_GET['src'] ?? 'active')); break;
            case 'get_overrides':     dl_respond(dl_get_overrides($conn)); break;
            case 'get_field_map':     dl_respond(dl_get_field_map($conn)); break;
            case 'get_logs':          dl_respond(dl_get_logs($conn, $_GET['severity'] ?? '', (int) ($_GET['limit'] ?? 100))); break;
            default: throw new Exception('Acción GET no reconocida.');
        }
    } elseif ($method === 'POST') {
        switch ($action) {
            case 'set_mode':          dl_respond(dl_set_mode((string) ($_POST['mode'] ?? ''))); break;
            case 'force_refresh':     dl_respond(dl_force_refresh()); break;
            case 'save_override':     dl_respond(dl_save_override($conn)); break;
            case 'apply_overrides_batch': dl_respond(dl_apply_overrides_batch($conn)); break;
            case 'preview_custom_entry': dl_respond(dl_preview_custom_entry()); break;
            case 'check_api_match':   dl_respond(dl_check_api_match()); break;
            case 'upload_image':      dl_respond(dl_upload_image()); break;
            case 'delete_override':   dl_respond(dl_delete_override($conn)); break;
            case 'save_field_map':    dl_respond(dl_save_field_map($conn)); break;
            case 'delete_field_map':  dl_respond(dl_delete_field_map($conn)); break;
            case 'clear_logs':        dl_respond(dl_clear_logs($conn)); break;
            case 'purge_all_overrides': dl_respond(dl_purge_all_overrides($conn)); break;
            case 'purge_all_entries':   dl_respond(dl_purge_all_entries($conn)); break;
            case 'purge_all':           dl_respond(dl_purge_all($conn)); break;
            default: throw new Exception('Acción POST no reconocida.');
        }
    } else {
        throw new Exception('Método no permitido.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    dl_respond(['status' => 'error', 'message' => $e->getMessage()]);
}

// ===========================================================================
//  READ ACTIONS
// ===========================================================================

/** Snapshot health + counters for the master control dashboard. */
function dl_health(mysqli $conn): array
{
    $livePath     = ShopSourceConfig::liveSnapshotPath();
    $fallbackPath = ShopSourceConfig::fallbackSnapshotPath();

    $liveSnap     = ShopRepository::snapshotFromPath($livePath);
    $fallbackSnap = ShopRepository::snapshotFromPath($fallbackPath);

    $describe = static function (?array $snap, string $path): array {
        if ($snap === null) {
            return ['exists' => is_file($path), 'items' => 0, 'hash' => null, 'shop_date' => null, 'generated_at' => null, 'age_seconds' => null, 'bytes' => is_file($path) ? (int) filesize($path) : 0];
        }
        $items = $snap['entries'] ?? $snap['shop'] ?? [];
        return [
            'exists'       => true,
            'items'        => is_array($items) ? count($items) : 0,
            'hash'         => $snap['hash'] ?? null,
            'shop_date'    => $snap['shop_date'] ?? ($snap['data']['date'] ?? null),
            'generated_at' => $snap['generated_at'] ?? null,
            'age_seconds'  => is_file($path) ? max(0, time() - (int) filemtime($path)) : null,
            'bytes'        => is_file($path) ? (int) filesize($path) : 0,
        ];
    };

    $logCounts = ['info' => 0, 'warning' => 0, 'error' => 0, 'critical' => 0];
    try {
        if ($res = $conn->query("SELECT severity, COUNT(*) c FROM dl_event_log GROUP BY severity")) {
            while ($row = $res->fetch_assoc()) {
                $logCounts[$row['severity']] = (int) $row['c'];
            }
        }
    } catch (Throwable $e) { /* table not created yet */ }

    return [
        'status'            => 'success',
        'mode'              => ShopSourceConfig::sourceMode(),
        'live_is_healthy'   => ShopSourceConfig::liveSnapshotIsHealthy(),
        'stale_after_secs'  => ShopSourceConfig::STALE_AFTER_SECONDS,
        'active_source'     => (ShopSourceConfig::activeSnapshotPath() === $fallbackPath) ? 'fallback' : 'live',
        'live'              => $describe($liveSnap, $livePath),
        'fallback'          => $describe($fallbackSnap, $fallbackPath),
        'override_count'    => dl_count($conn, 'SELECT COUNT(*) c FROM dl_shop_overrides WHERE activo = 1'),
        'custom_entry_count' => dl_count($conn, 'SELECT COUNT(*) c FROM dl_custom_entries WHERE activo = 1'),
        'field_map_count'   => dl_count($conn, 'SELECT COUNT(*) c FROM dl_api_field_map WHERE activo = 1'),
        'log_counts'        => $logCounts,
    ];
}

/**
 * Numeric suffix of layoutId ("MyLayout.07" => 7). Mirrors worker.js
 * (parseInt(layoutId.split('.').pop())) for item ordering inside a section.
 */
function dl_layout_suffix(array $it): int
{
    $lid = $it['layoutId'] ?? ($it['layout']['id'] ?? '');
    if (!is_string($lid) || $lid === '') {
        return 0;
    }
    $parts = explode('.', $lid);
    return (int) end($parts);
}

/**
 * Order items EXACTLY like the storefront (worker.js): group by section
 * (layout.name), sections by layout.rank DESC, then items inside a section by
 * layoutId numeric suffix DESC and finally sortPriority DESC. Sorting by
 * section name within an equal rank keeps each group contiguous.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function dl_sort_like_storefront(array $items): array
{
    usort($items, static function ($a, $b) {
        $ra = isset($a['layout']['rank']) ? (int) $a['layout']['rank'] : -1;
        $rb = isset($b['layout']['rank']) ? (int) $b['layout']['rank'] : -1;
        if ($ra !== $rb) {
            return $rb <=> $ra; // section rank desc
        }
        $sa = (string) ($a['section_name'] ?? '');
        $sb = (string) ($b['section_name'] ?? '');
        if (strcasecmp($sa, $sb) !== 0) {
            return strcasecmp($sa, $sb); // keep groups together
        }
        $ua = dl_layout_suffix($a);
        $ub = dl_layout_suffix($b);
        if ($ua !== $ub) {
            return $ub <=> $ua; // layoutId suffix desc
        }
        return ((int) ($b['sortPriority'] ?? 0)) <=> ((int) ($a['sortPriority'] ?? 0));
    });
    return $items;
}

/** Tile span (1–4) from the API tileSize. Mirrors app.js getTileSizeClass(). */
function dl_tile_span(?string $tileSize): int
{
    switch ((string) $tileSize) {
        case 'Size_4_x_1':
        case 'Size_4x1':
            return 4;
        case 'Size_3_x_1':
        case 'Size_3x1':
            return 3;
        case 'Size_2_x_1':
        case 'Size_2x1':
        case 'Size_2_x_2':
        case 'Size_2x2':
            return 2;
        default:
            return 1;
    }
}

/**
 * Card view-model fields the storefront derives client-side (app.js
 * createItemCardNode + getItemDisplayInfo): plural type label, main item id,
 * car-offer / dynamic-bundle flags and the artwork image cascade.
 *
 * @param array<string,mixed> $it
 * @return array<string,mixed>
 */
function dl_card_view_model(array $it): array
{
    $brItems     = is_array($it['brItems'] ?? null) ? $it['brItems'] : [];
    $instruments = is_array($it['instruments'] ?? null) ? $it['instruments'] : [];
    $cars        = is_array($it['cars'] ?? null) ? $it['cars'] : [];
    $tracks      = is_array($it['tracks'] ?? null) ? $it['tracks'] : [];
    $isBundle    = !empty($it['bundle']);
    $isJamTrack  = count($tracks) > 0;

    if ($isBundle) {
        $mainItem   = ['images' => ['icon' => $it['bundle']['image'] ?? null]];
        $pluralType = DL_TYPE_TRANSLATIONS_PLURAL['bundle'];
    } elseif ($isJamTrack) {
        $mainItem   = $tracks[0];
        $pluralType = DL_TYPE_TRANSLATIONS_PLURAL['jamtrack'];
    } else {
        $mainItem = array_merge($brItems, $instruments, $cars)[0] ?? [];
        $typeId   = (string) ($mainItem['type']['value'] ?? 'default');
        if ($typeId === 'shoe' || $typeId === 'shoes') {
            $typeId = 'footwear';
        }
        $pluralType = DL_TYPE_TRANSLATIONS_PLURAL[$typeId] ?? 'Artículos';
    }
    $typeOverride = trim((string) ($it['displayTypeOverride'] ?? ''));
    if ($typeOverride !== '') {
        $pluralType = $typeOverride;
    }

    // Non-LEGO render images (productTag !== 'Product.Juno'), like the shop.
    $renderImages = $it['newDisplayAsset']['renderImages'] ?? [];
    $nonLego = [];
    if (is_array($renderImages)) {
        foreach ($renderImages as $ri) {
            if (is_array($ri) && !empty($ri['image']) && ($ri['productTag'] ?? null) !== 'Product.Juno') {
                $nonLego[] = $ri['image'];
            }
        }
    }

    $image = $mainItem['albumArt']
        ?? ($nonLego[0]
        ?? ($it['newDisplayAsset']['renderImages'][0]['image']
        ?? ($mainItem['images']['featured']
        ?? ($mainItem['images']['icon']
        ?? ($it['images']['featured']
        ?? ($it['images']['icon']
        ?? ($it['displayAssets'][0]['url'] ?? null)))))));

    $devName = $it['devName'] ?? null;

    return [
        'span'              => dl_tile_span($it['tileSize'] ?? null),
        'item_type'         => $pluralType,
        'item_id'           => $mainItem['id'] ?? null,
        'is_car_offer'      => count($cars) > 0 && !$brItems && !$instruments && !$tracks,
        'is_dynamic_bundle' => !$isBundle && is_string($devName)
            && substr($devName, -strlen('for -1 MtxCurrency')) === 'for -1 MtxCurrency',
        'banner'            => $it['banner'] ?? null,
        'out_date'          => $it['outDate'] ?? null,
        'image'             => $image,
    ];
}

/** Lightweight grid payload (visual editor / preview clone). */
function dl_get_items(mysqli $conn, string $src): array
{
    $items = dl_sort_like_storefront(ShopRepository::itemsFrom($src));
    $overridden = dl_overridden_offer_ids($conn);

    $cards = [];
    $sections = [];
    $setNames = []; // distinct set names detected in the loaded items (dropdown source)
    foreach ($items as $it) {
        $offerId  = (string) ($it['offerId'] ?? '');
        $anchorId = (string) ($it['offerIdOriginal'] ?? $offerId);
        $layout   = is_array($it['layout'] ?? null) ? $it['layout'] : null;
        $cards[] = dl_card_view_model($it) + [
            'offerId'             => $offerId,
            'anchor_offer_id'     => $anchorId,
            'displayName'         => $it['displayName'] ?? '(sin nombre)',
            'displayType'         => $it['displayType'] ?? null,
            'section_name'        => $it['section_name'] ?? null,
            'vbucks'              => $it['vbucks'] ?? ($it['finalPrice'] ?? null),
            'precio_base_soles'   => $it['precio_base_soles'] ?? null,
            'precio_oferta_soles' => $it['precio_oferta_soles'] ?? null,
            'rarity'              => $it['rarity'] ?? null,
            'colors'              => $it['colors'] ?? null,
            'has_override'        => isset($overridden[$anchorId]),
            'layout'              => $layout,
            'layout_id_full'      => is_string($it['layoutId'] ?? null) ? $it['layoutId'] : null,
            'sort_priority'       => is_numeric($it['sortPriority'] ?? null) ? (int) $it['sortPriority'] : 0,
            'is_custom'           => !empty($it['isCustomEntry']),
        ];
        foreach ([$it['brItems'][0]['set'] ?? null, $it['tracks'][0]['set'] ?? null,
            $it['instruments'][0]['set'] ?? null, $it['cars'][0]['set'] ?? null] as $s) {
            if (is_array($s) && is_string($s['value'] ?? null) && trim($s['value']) !== '') {
                $setNames[trim($s['value'])] = true;
                break;
            }
        }
        if ($layout !== null && is_string($layout['id'] ?? null) && $layout['id'] !== ''
            && !isset($sections[$layout['id']])) {
            $sections[$layout['id']] = [
                'id'   => $layout['id'],
                'name' => (string) ($layout['name'] ?? $layout['id']),
                'rank' => isset($layout['rank']) ? (int) $layout['rank'] : 0,
            ];
        }
    }

    return [
        'status'   => 'success',
        'src'      => $src,
        'count'    => count($cards),
        'items'    => $cards,
        'sections' => array_values($sections),
        'sets'     => array_values(array_keys($setNames)),
    ];
}

/**
 * Edit-form view-model: resolves the values the storefront actually renders
 * (description cascade, image slots per entry kind, jam-track difficulty) so
 * the modal never has to guess where each field lives in the raw entry.
 *
 * @param array<string,mixed> $it
 * @return array<string,mixed>
 */
function dl_item_edit_view_model(array $it): array
{
    $brItems     = is_array($it['brItems'] ?? null) ? $it['brItems'] : [];
    $instruments = is_array($it['instruments'] ?? null) ? $it['instruments'] : [];
    $cars        = is_array($it['cars'] ?? null) ? $it['cars'] : [];
    $tracks      = is_array($it['tracks'] ?? null) ? $it['tracks'] : [];
    $isBundle    = !empty($it['bundle']) && is_array($it['bundle']);
    $isJamTrack  = count($tracks) > 0;

    // Description: same cascade the storefront resolves on the item page
    // (bundle.info for bundles, mainItem.description otherwise).
    if ($isBundle) {
        $desc = $it['bundle']['info'] ?? null;
    } elseif ($isJamTrack) {
        $desc = $tracks[0]['description'] ?? null;
    } else {
        $desc = $brItems[0]['description']
            ?? ($instruments[0]['description']
            ?? ($cars[0]['description'] ?? null));
    }
    $desc = is_scalar($desc) ? trim((string) $desc) : '';
    if (strcasecmp($desc, 'null') === 0 || strcasecmp($desc, 'bundle') === 0) {
        $desc = '';
    }

    // Image slots: each maps 1:1 to the RAW nested key that a storefront view
    // reads, so an override lands exactly where each surface looks for it.
    $slots = [];
    $addSlot = static function (string $key, string $label, $url, string $hint) use (&$slots): void {
        $slots[] = ['key' => $key, 'label' => $label, 'url' => is_string($url) ? $url : '', 'hint' => $hint];
    };
    if ($isBundle) {
        $addSlot('bundleImage', 'Imagen del lote (bundle.image)', $it['bundle']['image'] ?? null, 'Portada del lote en la tarjeta y en su página.');
    }
    if ($isJamTrack) {
        $addSlot('albumArt', 'Portada (tracks[0].albumArt)', $tracks[0]['albumArt'] ?? null, 'Carátula de la pista: tarjeta, hero y contenidos.');
    }
    if ($brItems) {
        $imgs = is_array($brItems[0]['images'] ?? null) ? $brItems[0]['images'] : [];
        $addSlot('icon', 'Icon del ítem (images.icon)', $imgs['icon'] ?? null, 'Icono usado en tarjetas y como contenido de otros lotes.');
        $addSlot('featured', 'Destacada (images.featured)', $imgs['featured'] ?? null, 'Imagen destacada: tiene prioridad sobre el icon en tarjetas y hero.');
    }
    if ($instruments || $cars) {
        $imgs = is_array($instruments[0]['images'] ?? null)
            ? $instruments[0]['images']
            : (is_array($cars[0]['images'] ?? null) ? $cars[0]['images'] : []);
        $addSlot('small', 'Imagen pequeña (images.small)', $imgs['small'] ?? null, 'Miniatura del instrumento/vehículo en tarjetas y contenidos.');
        $addSlot('large', 'Imagen grande (images.large)', $imgs['large'] ?? null, 'Imagen grande del instrumento/vehículo en su página.');
    }
    $renderImages = $it['newDisplayAsset']['renderImages'] ?? null;
    if (is_array($renderImages)) {
        foreach ($renderImages as $ri) {
            if (is_array($ri) && !empty($ri['image']) && ($ri['productTag'] ?? null) !== 'Product.Juno') {
                $addSlot('render', 'Render de tienda (renderImages)', $ri['image'], 'Arte principal de la tarjeta (primer render no-LEGO).');
                break;
            }
        }
    }

    // Set / introduction: effective values from the representative item (the
    // same raw object app.js reads via mainItem.set.text / introduction.text).
    $mainItem2 = $brItems[0] ?? ($tracks[0] ?? ($instruments[0] ?? ($cars[0] ?? [])));
    $setRaw    = is_array($mainItem2['set'] ?? null) ? $mainItem2['set'] : null;
    $introRaw  = is_array($mainItem2['introduction'] ?? null) ? $mainItem2['introduction'] : null;

    // Corner banner: effective state after overrides (only backendValue 'New'
    // renders as corner banner, same criterion as the storefront).
    $banner = is_array($it['banner'] ?? null) ? $it['banner'] : null;
    $bannerActive = $banner !== null && ($banner['backendValue'] ?? null) === 'New'
        && trim((string) ($banner['value'] ?? '')) !== '';

    return [
        'is_bundle'    => $isBundle,
        'is_jam_track' => $isJamTrack,
        'description'  => $desc,
        'image_slots'  => $slots,
        'difficulty'   => $isJamTrack && is_array($tracks[0]['difficulty'] ?? null) ? $tracks[0]['difficulty'] : null,
        'banner'       => [
            'active' => $bannerActive,
            'text'   => $bannerActive ? trim((string) $banner['value']) : '',
        ],
        'tile_size'    => is_string($it['tileSize'] ?? null) ? $it['tileSize'] : null,
        'tile_span'    => dl_tile_span($it['tileSize'] ?? null),
        'set_value'    => is_string($setRaw['value'] ?? null) ? $setRaw['value'] : '',
        'introduction' => is_string($introRaw['text'] ?? null) ? $introRaw['text'] : '',
        'out_date'     => is_string($it['outDate'] ?? null) ? $it['outDate'] : '',
        'today_utc'    => gmdate('Y-m-d'),
    ];
}

/** Full canonical item + its current override fields for the edit form. */
function dl_get_item(mysqli $conn, string $offerId, string $src): array
{
    if ($offerId === '') {
        throw new Exception('Falta offerId.');
    }
    $found = null;
    foreach (ShopRepository::itemsFrom($src) as $it) {
        if ((string) ($it['offerId'] ?? '') === $offerId
            || (string) ($it['offerIdOriginal'] ?? '') === $offerId) {
            $found = $it;
            break;
        }
    }
    if ($found === null) {
        throw new Exception('Ítem no encontrado en el snapshot seleccionado.');
    }

    // Overrides stay keyed by the ORIGINAL offerId even when the effective one
    // was overridden, so the edit stays reversible.
    $anchorId = (string) ($found['offerIdOriginal'] ?? ($found['offerId'] ?? $offerId));

    $overrides = [];
    $stmt = $conn->prepare('SELECT field_key, field_value, value_type, activo, nota FROM dl_shop_overrides WHERE offer_id = ?');
    $stmt->bind_param('s', $anchorId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $overrides[$row['field_key']] = $row;
    }
    $stmt->close();

    return [
        'status'          => 'success',
        'item'            => $found,
        'anchor_offer_id' => $anchorId,
        'overrides'       => $overrides,
        'effective'       => dl_item_edit_view_model($found),
    ];
}

function dl_get_overrides(mysqli $conn): array
{
    $rows = [];
    if ($res = $conn->query('SELECT id, offer_id, field_key, field_value, value_type, activo, nota, updated_at FROM dl_shop_overrides ORDER BY offer_id ASC, field_key ASC')) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return ['status' => 'success', 'overrides' => $rows];
}

function dl_get_field_map(mysqli $conn): array
{
    $rows = [];
    if ($res = $conn->query('SELECT id, scope, source_key, canonical_key, activo, nota, updated_at FROM dl_api_field_map ORDER BY scope ASC, source_key ASC')) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return ['status' => 'success', 'field_map' => $rows];
}

function dl_get_logs(mysqli $conn, string $severity, int $limit): array
{
    $limit = max(1, min(500, $limit));
    $rows = [];
    if ($severity !== '' && in_array($severity, ['info', 'warning', 'error', 'critical'], true)) {
        $stmt = $conn->prepare('SELECT id, severity, event_type, message, context, created_at FROM dl_event_log WHERE severity = ? ORDER BY id DESC LIMIT ?');
        $stmt->bind_param('si', $severity, $limit);
    } else {
        $stmt = $conn->prepare('SELECT id, severity, event_type, message, context, created_at FROM dl_event_log ORDER BY id DESC LIMIT ?');
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return ['status' => 'success', 'logs' => $rows];
}

// ===========================================================================
//  WRITE ACTIONS
// ===========================================================================

function dl_set_mode(string $mode): array
{
    $allowed = ['auto', 'live', 'fallback', 'maintenance'];
    if (!in_array($mode, $allowed, true)) {
        throw new Exception('Modo inválido.');
    }
    if (!ShopSourceConfig::writeSourceMode($mode)) {
        throw new Exception('No se pudo escribir el archivo de modo de fuente.');
    }
    return ['status' => 'success', 'message' => "Modo de tienda cambiado a '{$mode}'.", 'mode' => $mode];
}

function dl_force_refresh(): array
{
    $script = realpath(__DIR__ . '/../../api/generar_cache_tienda.php');
    if ($script === false) {
        throw new Exception('No se encontró el Ingestor (generar_cache_tienda.php).');
    }
    $output = shell_exec('php ' . escapeshellarg($script) . ' 2>&1');
    return ['status' => 'success', 'message' => 'Ingestor ejecutado.', 'log' => $output ?? '(sin salida)'];
}

/** Upsert one override field and recompile item_overrides.json. */
function dl_save_override(mysqli $conn): array
{
    $offerId   = trim((string) ($_POST['offer_id'] ?? ''));
    $fieldKey  = trim((string) ($_POST['field_key'] ?? ''));
    $value     = (string) ($_POST['field_value'] ?? '');
    $valueType = (string) ($_POST['value_type'] ?? 'string');
    $nota      = trim((string) ($_POST['nota'] ?? ''));
    $activo    = (int) (($_POST['activo'] ?? '1') ? 1 : 0);

    if ($offerId === '' || $fieldKey === '') {
        throw new Exception('offer_id y field_key son obligatorios.');
    }
    if (!in_array($valueType, ['string', 'number', 'json', 'color'], true)) {
        throw new Exception('value_type inválido.');
    }
    if ($valueType === 'json') {
        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('El valor JSON no es válido: ' . json_last_error_msg());
        }
    }

    $stmt = $conn->prepare(
        'INSERT INTO dl_shop_overrides (offer_id, field_key, field_value, value_type, activo, nota)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), value_type = VALUES(value_type), activo = VALUES(activo), nota = VALUES(nota)'
    );
    $stmt->bind_param('ssssis', $offerId, $fieldKey, $value, $valueType, $activo, $nota);
    $stmt->execute();
    $stmt->close();

    $n = dl_compile_overrides($conn);
    return ['status' => 'success', 'message' => "Override guardado y compilado ({$n} activos).", 'compiled' => $n];
}

/**
 * Apply a client-staged batch of override operations atomically: validate
 * everything first, run all writes inside one transaction and recompile
 * item_overrides.json a single time at the end. On any failure the whole
 * batch is rolled back so the client draft can be retried intact.
 *
 * POST ops: JSON array of
 *   {op:'upsert', offer_id, field_key, field_value, value_type}
 *   {op:'delete', offer_id, field_key}
 *   {op:'delete_all', offer_id}
 *   {op:'create_entry', offer_id, template, fields}   (manual entry)
 *   {op:'delete_entry', offer_id}                     (manual entry + its overrides)
 */
function dl_apply_overrides_batch(mysqli $conn): array
{
    $ops = json_decode((string) ($_POST['ops'] ?? ''), true);
    if (!is_array($ops) || $ops === []) {
        throw new Exception('Lote de operaciones vacío o inválido.');
    }
    if (count($ops) > 500) {
        throw new Exception('Lote demasiado grande (máx. 500 operaciones).');
    }

    foreach ($ops as $i => $op) {
        $n = $i + 1;
        if (!is_array($op)) {
            throw new Exception("Operación #{$n} inválida.");
        }
        $kind    = (string) ($op['op'] ?? '');
        $offerId = trim((string) ($op['offer_id'] ?? ''));
        if ($offerId === '') {
            throw new Exception("Operación #{$n}: falta offer_id.");
        }
        if ($kind === 'upsert') {
            $fieldKey  = trim((string) ($op['field_key'] ?? ''));
            $valueType = (string) ($op['value_type'] ?? 'string');
            if ($fieldKey === '') {
                throw new Exception("Operación #{$n}: falta field_key.");
            }
            if (!in_array($valueType, ['string', 'number', 'json', 'color'], true)) {
                throw new Exception("Operación #{$n}: value_type inválido.");
            }
            if ($valueType === 'json') {
                json_decode((string) ($op['field_value'] ?? ''));
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Operación #{$n} ({$fieldKey}): JSON inválido: " . json_last_error_msg());
                }
            }
        } elseif ($kind === 'delete') {
            if (trim((string) ($op['field_key'] ?? '')) === '') {
                throw new Exception("Operación #{$n}: delete requiere field_key.");
            }
        } elseif ($kind === 'create_entry') {
            // Full dry-run build: any invalid payload aborts the whole batch
            // BEFORE the transaction starts.
            dl_build_custom_entry($offerId, (string) ($op['template'] ?? ''), is_array($op['fields'] ?? null) ? $op['fields'] : []);
        } elseif ($kind !== 'delete_all' && $kind !== 'delete_entry') {
            throw new Exception("Operación #{$n}: op desconocida '{$kind}'.");
        }
    }

    $hasEntryOps = false;
    foreach ($ops as $op) {
        if (in_array($op['op'] ?? '', ['create_entry', 'delete_entry'], true)) {
            $hasEntryOps = true;
            break;
        }
    }
    if ($hasEntryOps) {
        dl_ensure_custom_entries_table($conn);
    }

    $conn->begin_transaction();
    try {
        $upsert = $conn->prepare(
            'INSERT INTO dl_shop_overrides (offer_id, field_key, field_value, value_type, activo, nota)
             VALUES (?, ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), value_type = VALUES(value_type), activo = 1, nota = VALUES(nota)'
        );
        $delOne = $conn->prepare('DELETE FROM dl_shop_overrides WHERE offer_id = ? AND field_key = ?');
        $delAll = $conn->prepare('DELETE FROM dl_shop_overrides WHERE offer_id = ?');
        $insEntry = $hasEntryOps ? $conn->prepare(
            'INSERT INTO dl_custom_entries (offer_id, entry_json, activo)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE entry_json = VALUES(entry_json), activo = 1'
        ) : null;
        $delEntry = $hasEntryOps ? $conn->prepare('DELETE FROM dl_custom_entries WHERE offer_id = ?') : null;

        foreach ($ops as $op) {
            $kind    = (string) $op['op'];
            $offerId = trim((string) $op['offer_id']);
            if ($kind === 'upsert') {
                $fieldKey  = trim((string) $op['field_key']);
                $value     = (string) ($op['field_value'] ?? '');
                $valueType = (string) ($op['value_type'] ?? 'string');
                $nota      = trim((string) ($op['nota'] ?? ''));
                $upsert->bind_param('sssss', $offerId, $fieldKey, $value, $valueType, $nota);
                $upsert->execute();
            } elseif ($kind === 'delete') {
                $fieldKey = trim((string) $op['field_key']);
                $delOne->bind_param('ss', $offerId, $fieldKey);
                $delOne->execute();
            } elseif ($kind === 'create_entry') {
                $entry = dl_build_custom_entry($offerId, (string) ($op['template'] ?? ''), is_array($op['fields'] ?? null) ? $op['fields'] : []);
                $json  = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new Exception('No se pudo serializar la entrada personalizada.');
                }
                $insEntry->bind_param('ss', $offerId, $json);
                $insEntry->execute();
            } elseif ($kind === 'delete_entry') {
                $delEntry->bind_param('s', $offerId);
                $delEntry->execute();
                $delAll->bind_param('s', $offerId);
                $delAll->execute();
            } else { // delete_all
                $delAll->bind_param('s', $offerId);
                $delAll->execute();
            }
        }

        $upsert->close();
        $delOne->close();
        $delAll->close();
        if ($insEntry) { $insEntry->close(); }
        if ($delEntry) { $delEntry->close(); }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw new Exception('Lote revertido, no se aplicó ningún cambio: ' . $e->getMessage());
    }

    $applied = count($ops);
    $n = dl_compile_overrides($conn);
    $c = $hasEntryOps ? dl_compile_custom_entries($conn) : null;
    $msg = "Se aplicaron {$applied} operaciones y se recompiló ({$n} overrides activos"
        . ($c !== null ? ", {$c} entradas manuales" : '') . ').';
    return ['status' => 'success', 'message' => $msg, 'applied' => $applied, 'compiled' => $n];
}

// ===========================================================================
//  CUSTOM ENTRIES (manual sections / items created from the Visual Editor)
// ===========================================================================

/** Create dl_custom_entries on demand (mirrors schema.sql). */
function dl_ensure_custom_entries_table(mysqli $conn): void
{
    $conn->query(
        'CREATE TABLE IF NOT EXISTS `dl_custom_entries` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `offer_id`   VARCHAR(255)    NOT NULL,
            `entry_json` MEDIUMTEXT      NOT NULL,
            `activo`     TINYINT(1)      NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_offer` (`offer_id`),
            KEY `idx_activo` (`activo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Conversion rate (soles per 100 vbucks) from the active snapshot meta. */
function dl_conversion_rate(): string
{
    $snap = ShopRepository::snapshot();
    $rate = $snap['meta']['conversion_rate'] ?? null;
    return (is_string($rate) && is_numeric($rate)) ? $rate : ShopSourceConfig::DEFAULT_CONVERSION_RATE;
}

/**
 * Build the canonical entry for a manual creation. For the type templates a
 * minimal RAW synthetic entry is composed and passed through
 * ShopNormalizer::normalizeEntry (the exact ingestion pipeline), so the stored
 * entry is the same canonical superset the daily snapshot produces. The
 * 'duplicate' template clones the current canonical entry of an existing
 * offer under the new offerId.
 *
 * Throws on any invalid payload (used as a dry-run validator too).
 *
 * @param array<string,mixed> $f
 * @return array<string,mixed>
 */
function dl_build_custom_entry(string $offerId, string $template, array $f): array
{
    if ($offerId === '') {
        throw new Exception('create_entry: falta offer_id.');
    }

    $layout = $f['layout'] ?? null;
    if (!is_array($layout)
        || !is_string($layout['id'] ?? null) || trim((string) $layout['id']) === ''
        || !is_string($layout['name'] ?? null) || trim((string) $layout['name']) === ''
        || !is_numeric($layout['rank'] ?? null)) {
        throw new Exception('create_entry: layout inválido (requiere id, name y rank).');
    }
    $layoutId  = trim((string) $layout['id']);
    $suffix    = max(1, (int) ($f['layout_suffix'] ?? 1));
    $fullLayout = [
        'id'                   => $layoutId,
        'name'                 => trim((string) $layout['name']),
        'index'                => (int) ($layout['index'] ?? 0),
        'rank'                 => (int) $layout['rank'],
        'showIneligibleOffers' => 'always',
        'useWidePreview'       => false,
        'displayType'          => 'tileGrid',
    ];

    if ($template === 'duplicate') {
        $sourceId = trim((string) ($f['source_offer_id'] ?? ''));
        if ($sourceId === '') {
            throw new Exception('create_entry: duplicate requiere source_offer_id.');
        }
        $source = null;
        foreach (ShopRepository::items() as $it) {
            if ((string) ($it['offerId'] ?? '') === $sourceId
                || (string) ($it['offerIdOriginal'] ?? '') === $sourceId) {
                $source = $it;
                break;
            }
        }
        if ($source === null) {
            throw new Exception('create_entry: no se encontró la oferta origen a duplicar.');
        }
        unset($source['offerIdOriginal'], $source['isCustomEntry']);
        $source['offerId']      = $offerId;
        $source['layout']       = $fullLayout;
        $source['layoutId']     = $layoutId . '.' . $suffix;
        $source['section_name'] = $fullLayout['name'];
        $source['store_rank']   = $fullLayout['rank'];
        if (isset($f['sort_priority']) && is_numeric($f['sort_priority'])) {
            $source['sortPriority'] = (int) $f['sort_priority'];
        }
        return $source;
    }

    $name = trim((string) ($f['display_name'] ?? ''));
    if ($name === '') {
        throw new Exception('create_entry: falta display_name.');
    }
    $vbucks = max(0, (int) ($f['vbucks'] ?? 0));
    $image  = dl_clean_image_url($f['image'] ?? '');
    $images = is_array($f['images'] ?? null) ? $f['images'] : [];
    $slot   = static function (string $key) use ($images, $image): ?string {
        return dl_clean_image_url($images[$key] ?? '') ?? $image;
    };
    $cosmeticId = 'dl_custom_' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '', substr(md5($offerId), 0, 12)));

    $raw = [
        'offerId'      => $offerId,
        'devName'      => '[DL] Custom entry',
        'finalPrice'   => $vbucks,
        'regularPrice' => $vbucks,
        'sortPriority' => isset($f['sort_priority']) && is_numeric($f['sort_priority']) ? (int) $f['sort_priority'] : 0,
        'layoutId'     => $layoutId . '.' . $suffix,
        'layout'       => $fullLayout,
        'tileSize'     => 'Size_1_x_1',
        'giftable'     => false,
        'refundable'   => false,
    ];

    $rarityIn = is_array($f['rarity'] ?? null) ? $f['rarity'] : null;
    $rarityId = $rarityIn !== null ? trim((string) ($rarityIn['id'] ?? '')) : '';
    $rarityName = $rarityIn !== null ? trim((string) ($rarityIn['name'] ?? '')) : '';
    $hasRarity = ($rarityId !== '' && $rarityName !== '');
    $rarity = $hasRarity
        ? ['value' => $rarityId, 'displayValue' => $rarityName]
        : ['value' => 'common', 'displayValue' => 'Común'];

    $colorsIn = is_array($f['colors'] ?? null) ? $f['colors'] : [];
    $colors = [];
    foreach (['color1', 'color2', 'color3'] as $ck) {
        $cv = trim((string) ($colorsIn[$ck] ?? ''));
        if (preg_match('/^[0-9a-fA-F]{6,8}$/', $cv)) {
            $colors[$ck] = strtolower($cv);
        }
    }

    switch ($template) {
        case 'bundle':
            $raw['bundle'] = ['name' => $name, 'info' => '', 'image' => $slot('bundleImage')];
            break;
        case 'track':
            $raw['tracks'] = [[
                'id'          => $cosmeticId,
                'devName'     => $cosmeticId,
                'title'       => $name,
                'artist'      => '',
                'album'       => '',
                'releaseYear' => (int) gmdate('Y'),
                'duration'    => 0,
                'difficulty'  => ['vocals' => 0, 'guitar' => 0, 'bass' => 0, 'plasticBass' => 0, 'drums' => 0, 'plasticDrums' => 0],
                'albumArt'    => $slot('albumArt'),
            ]];
            break;
        case 'instrument':
            $raw['instruments'] = [[
                'id'     => $cosmeticId,
                'name'   => $name,
                'description' => '',
                'type'   => ['value' => 'guitar', 'displayValue' => 'Guitarra'],
                'rarity' => $rarity,
                'images' => ['small' => $slot('small'), 'large' => $slot('large')],
            ]];
            break;
        case 'car':
            $raw['cars'] = [[
                'id'     => $cosmeticId,
                'name'   => $name,
                'description' => '',
                'type'   => ['value' => 'carbody', 'displayValue' => 'Carrocería'],
                'rarity' => $rarity,
                'images' => ['small' => $slot('small'), 'large' => $slot('large')],
            ]];
            break;
        case 'br':
            $typeValue = (string) ($f['type_value'] ?? 'outfit');
            if (!preg_match('/^[a-z_]{2,30}$/', $typeValue)) {
                $typeValue = 'outfit';
            }
            $singular = [
                'outfit' => 'Traje', 'emote' => 'Gesto', 'glider' => 'Ala delta',
                'pickaxe' => 'Pico', 'backpack' => 'Mochila', 'wrap' => 'Envoltorio',
                'shoes' => 'Calzado', 'contrail' => 'Estela', 'spray' => 'Grafiti',
                'music' => 'Música', 'loadingscreen' => 'Pantalla de carga',
            ];
            $raw['brItems'] = [[
                'id'          => $cosmeticId,
                'name'        => $name,
                'description' => '',
                'type'        => ['value' => $typeValue, 'displayValue' => $singular[$typeValue] ?? 'Objeto'],
                'rarity'      => $rarity,
                'images'      => ['smallIcon' => $slot('icon'), 'icon' => $slot('icon'), 'featured' => $slot('featured')],
                'added'       => gmdate('Y-m-d'),
            ]];
            break;
        default:
            throw new Exception("create_entry: template desconocida '{$template}'.");
    }

    $entry = ShopNormalizer::normalizeEntry($raw, dl_conversion_rate());
    if ($entry === null) {
        throw new Exception('create_entry: la entrada sintética no pudo normalizarse.');
    }
    if ($template === 'track') {
        // Sin artista, el nombre canónico "Título - Artista" deja un sufijo colgante.
        $entry['displayName'] = $name;
    }
    if ($hasRarity) {
        $entry['rarity'] = ['id' => $rarityId, 'name' => $rarityName];
    }
    if (isset($colors['color1'])) {
        $entry['colors'] = $colors;
    }

    // Set / introduction: same raw shapes the API ships, written on the
    // representative item so app.js renders them exactly like API items.
    $setIn   = is_array($f['set'] ?? null) ? trim((string) ($f['set']['value'] ?? '')) : '';
    $introIn = is_array($f['introduction'] ?? null) ? trim((string) ($f['introduction']['text'] ?? '')) : '';
    foreach (['brItems', 'instruments', 'cars', 'tracks'] as $coll) {
        if (!isset($entry[$coll][0]) || !is_array($entry[$coll][0])) {
            continue;
        }
        if ($setIn !== '') {
            $entry[$coll][0]['set'] = [
                'value'        => $setIn,
                'text'         => 'Pieza del conjunto ' . $setIn . '.',
                'backendValue' => $setIn,
            ];
        }
        if ($introIn !== '') {
            $intro = ['text' => $introIn];
            if (preg_match('/cap[ií]tulo\s*([0-9]+)\s*,?\s*temporada\s*([0-9]+)/iu', $introIn, $m)) {
                $intro['chapter'] = $m[1];
                $intro['season']  = $m[2];
            }
            $entry[$coll][0]['introduction'] = $intro;
        }
    }

    // Out-date: exact API contract (outDate + offerDates.out). The managed
    // flag lets ShopRepository::dropExpiredManaged retire the entry for real
    // (storefront + editor) once the rotation date passes.
    $out = trim((string) ($f['out_date'] ?? ''));
    if ($out !== '' && strtotime($out) !== false) {
        $entry['outDate'] = $out;
        if (!is_array($entry['offerDates'] ?? null)) {
            $entry['offerDates'] = ['in' => null, 'out' => null];
        }
        $entry['offerDates']['out'] = $out;
        $entry['outDateManaged'] = true;
    }
    return $entry;
}

/** Normalize an image URL field: absolute http(s) URL or site-relative path. */
function dl_clean_image_url($value): ?string
{
    $url = trim((string) $value);
    if ($url === '') {
        return null;
    }
    return preg_match('#^(https?://|/)#i', $url) ? $url : null;
}

// ===========================================================================
//  IMAGE UPLOADS (local device images for the modal image slots)
// ===========================================================================

define('DL_UPLOAD_MAX_BYTES', 20971520); // 20 MB
define('DL_UPLOAD_MEDIA_DIR', 'data_layer_media');
define('DL_UPLOAD_ALLOWED', [
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/jpeg' => 'jpg',
]);

/**
 * Store an uploaded image for a modal image slot and return its public URL.
 * Validates size, extension, real MIME (finfo) and decodability
 * (getimagesize) before persisting under cuysito_app/data_layer_media/.
 */
function dl_upload_image(): array
{
    $file = $_FILES['image'] ?? null;
    if (!is_array($file) || !isset($file['error'])) {
        throw new Exception('No se recibió ningún archivo de imagen.');
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new Exception('El archivo excede el tamaño máximo permitido (20 MB).');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new Exception('Error al procesar la imagen. Inténtalo de nuevo.');
    }
    if ((int) $file['size'] <= 0 || (int) $file['size'] > DL_UPLOAD_MAX_BYTES) {
        throw new Exception('El archivo excede el tamaño máximo permitido (20 MB).');
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    if (!in_array($ext, array_values(DL_UPLOAD_ALLOWED), true)) {
        throw new Exception('Formato no compatible. Solo se permiten PNG, WebP y JPG/JPEG.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($file['tmp_name']);
    if (!isset(DL_UPLOAD_ALLOWED[$mime]) || DL_UPLOAD_ALLOWED[$mime] !== $ext) {
        throw new Exception('Formato no compatible: el contenido del archivo no coincide con una imagen PNG, WebP o JPG.');
    }
    if (@getimagesize($file['tmp_name']) === false) {
        throw new Exception('Error al procesar la imagen: el archivo no es una imagen válida.');
    }

    $dir = ShopSourceConfig::appRoot() . '/' . DL_UPLOAD_MEDIA_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new Exception('No se pudo crear el directorio de medios en el servidor.');
    }

    $base = strtolower(pathinfo((string) ($file['name'] ?? 'imagen'), PATHINFO_FILENAME));
    $base = trim(preg_replace('/[^a-z0-9._-]+/', '-', $base), '-.');
    $base = substr($base !== '' ? $base : 'imagen', 0, 60);
    $name = $base . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $name;

    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        throw new Exception('Error al procesar la imagen: no se pudo guardar el archivo.');
    }
    @chmod($dest, 0644);

    return [
        'status'  => 'success',
        'message' => 'Imagen subida correctamente.',
        'url'     => '/' . DL_UPLOAD_MEDIA_DIR . '/' . $name,
    ];
}

/**
 * Dry-run build of a manual entry so the item modal can open on a staged
 * creation BEFORE anything is persisted. Returns the same shape as get_item
 * (item + effective view-model) with no override rows.
 */
function dl_preview_custom_entry(): array
{
    $offerId  = trim((string) ($_POST['offer_id'] ?? ''));
    $template = (string) ($_POST['template'] ?? '');
    $fields   = json_decode((string) ($_POST['fields'] ?? ''), true);
    if (!is_array($fields)) {
        throw new Exception('fields debe ser un objeto JSON.');
    }
    $entry = dl_build_custom_entry($offerId, $template, $fields);
    $entry['isCustomEntry'] = true;
    return [
        'status'          => 'success',
        'item'            => $entry,
        'anchor_offer_id' => $offerId,
        'overrides'       => (object) [],
        'effective'       => dl_item_edit_view_model($entry),
    ];
}

function dl_fuzzy_score(string $a, string $b): float {
    $a = mb_strtolower(trim($a));
    $b = mb_strtolower(trim($b));
    // Normalize accents
    $norm = function(string $s): string {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
        $s = preg_replace('/[\x{0300}-\x{036f}]/u', '', $s);
        return preg_replace('/[^a-z0-9]+/u', ' ', $s);
    };
    $a = $norm($a);
    $b = $norm($b);
    if ($a === '' && $b === '') return 100.0;
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 100.0;
    // Levenshtein on shorter strings, similar_text on longer
    $maxLen = max(mb_strlen($a), mb_strlen($b));
    if ($maxLen <= 255) {
        $dist = levenshtein($a, $b);
        $score = max(0, (1 - $dist / $maxLen)) * 100;
    } else {
        similar_text($a, $b, $score);
    }
    // Boost if one contains the other
    if (str_contains($a, $b) || str_contains($b, $a)) {
        $score = max($score, 80.0);
    }
    return round($score, 1);
}

function dl_image_phash(string $path): ?string {
    $info = @getimagesize($path);
    if (!$info) return null;
    $img = match($info[2]) {
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        default => null
    };
    if (!$img) return null;
    $small = imagecreatetruecolor(8, 8);
    imagecopyresampled($small, $img, 0, 0, 0, 0, 8, 8, imagesx($img), imagesy($img));
    imagedestroy($img);
    $pixels = [];
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $pixels[] = (int)(0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF));
        }
    }
    imagedestroy($small);
    $avg = array_sum($pixels) / 64;
    $hash = '';
    foreach ($pixels as $p) $hash .= $p >= $avg ? '1' : '0';
    return $hash;
}

function dl_phash_similarity(string $h1, string $h2): float {
    if (strlen($h1) !== 64 || strlen($h2) !== 64) return 0.0;
    $diff = 0;
    for ($i = 0; $i < 64; $i++) if ($h1[$i] !== $h2[$i]) $diff++;
    return round((1 - $diff / 64) * 100, 1);
}

function dl_check_api_match(): array {
    $payloadJson = $_POST['payload'] ?? '';
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        throw new Exception('Payload inválido');
    }

    $minMatches = max(1, (int)($payload['min_matches'] ?? 1));
    $inheritBackdrop = (bool)($payload['inherit_backdrop'] ?? true);
    $inheritVideo = (bool)($payload['inherit_video'] ?? true);

    $items = ShopRepository::items();
    $results = [];

    $localHash = null;
    $imgUrl = $payload['image_url'] ?? '';
    if (str_starts_with($imgUrl, '/' . DL_UPLOAD_MEDIA_DIR . '/')) {
        $localPath = ShopSourceConfig::appRoot() . $imgUrl;
        if (file_exists($localPath)) {
            $localHash = dl_image_phash($localPath);
        }
    }

    foreach ($items as $item) {
        // Enriquecer con los campos derivados que la tienda/editor usan:
        // item_type (tipo plural), image (imagen principal), set, rarity, etc.
        $vm = dl_card_view_model($item);
        $item['item_type'] = $vm['item_type'] ?? 'Artículos';
        $item['image']     = $vm['image'] ?? '';
        $item['set']       = is_array($item['brItems'][0]['set'] ?? null) ? $item['brItems'][0]['set']
                           : (is_array($item['tracks'][0]['set'] ?? null) ? $item['tracks'][0]['set']
                           : (is_array($item['instruments'][0]['set'] ?? null) ? $item['instruments'][0]['set']
                           : (is_array($item['cars'][0]['set'] ?? null) ? $item['cars'][0]['set'] : null)));
        $item['rarity']    = $item['rarity'] ?? ['name' => ''];

        $scores = [];
        $totalWeight = 0;
        $matchedFields = 0;

        if (!empty($payload['section_name'])) {
            $score = dl_fuzzy_score($payload['section_name'], $item['section_name'] ?? '');
            $scores['section_name'] = $score;
            if ($score >= 60) $matchedFields++;
            $totalWeight += 1;
        }

        if (!empty($payload['display_name'])) {
            $score = dl_fuzzy_score($payload['display_name'], $item['displayName'] ?? '');
            $scores['displayName'] = $score;
            if ($score >= 60) $matchedFields++;
            $totalWeight += 1;
        }

        if (!empty($payload['item_type'])) {
            $score = dl_fuzzy_score($payload['item_type'], $item['item_type'] ?? '');
            // Tipos como «Pistas de improvisación» vs «Pistas de improvisation» deben
            // puntuar alto; un umbral de 80 es suficiente para aceptar la coincidencia.
            $scores['item_type'] = $score >= 80 ? $score : 0.0;
            if ($scores['item_type'] >= 60) $matchedFields++;
            $totalWeight += 1;
        }

        if (!empty($payload['colors']) && is_array($payload['colors']) && !empty($item['colors'])) {
            $cScore = 0;
            $count = 0;
            foreach (['color1', 'color2', 'color3'] as $c) {
                if (!empty($payload['colors'][$c])) {
                    $count++;
                    if (strcasecmp($payload['colors'][$c], $item['colors'][$c] ?? '') === 0) {
                        $cScore += 33.33;
                    }
                }
            }
            if ($count > 0) {
                $cScore = ($cScore / ($count * 33.33)) * 100;
                $scores['colors'] = min(100.0, $cScore);
                if ($scores['colors'] >= 60) $matchedFields++;
                $totalWeight += 1;
            }
        }

        if ($imgUrl) {
            $iScore = 0;
            $remoteImg = $item['image'] ?? '';
            if ($imgUrl === $remoteImg) {
                $iScore = 100;
            } elseif ($localHash && $remoteImg) {
                $tmp = sys_get_temp_dir() . '/' . uniqid('dl_match_', true);
                if (@copy($remoteImg, $tmp)) {
                    $remoteHash = dl_image_phash($tmp);
                    @unlink($tmp);
                    if ($remoteHash) {
                        $iScore = dl_phash_similarity($localHash, $remoteHash);
                    }
                }
            }
            $scores['image'] = $iScore;
            if ($iScore >= 60) $matchedFields++;
            $totalWeight += 1;
        }

        if (!empty($payload['rarity']['name'])) {
            $score = dl_fuzzy_score($payload['rarity']['name'], $item['rarity']['name'] ?? '');
            // Rarezas son palabras cortas; «Épica» vs «Epica» debe puntuar alto.
            $scores['rarity'] = $score >= 75 ? $score : 0.0;
            if ($scores['rarity'] >= 60) $matchedFields++;
            $totalWeight += 1;
        }

        if (!empty($payload['set_value'])) {
            $apiSet = $item['set'] ?? null;
            $apiSetVal = is_array($apiSet) ? (string)($apiSet['value'] ?? ($apiSet['text'] ?? '')) : (string)$apiSet;
            $score = dl_fuzzy_score($payload['set_value'], $apiSetVal);
            $scores['set_value'] = $score;
            if ($score >= 60) $matchedFields++;
            $totalWeight += 1;
        }

        if ($payload['vbucks'] !== null && $payload['vbucks'] > 0) {
            $vScore = 0;
            $apiVbucks = $item['vbucks'] ?? null;
            if ($apiVbucks !== null) {
                if ($payload['vbucks'] == $apiVbucks) {
                    $vScore = 100;
                } else {
                    $diff = abs($payload['vbucks'] - $apiVbucks) / max($payload['vbucks'], $apiVbucks);
                    if ($diff <= 0.10) {
                        $vScore = 50;
                    }
                }
            }
            $scores['vbucks'] = $vScore;
            if ($vScore >= 60) $matchedFields++;
            $totalWeight += 1;
        }

        if (!empty($payload['banner'])) {
            $apiBanner = is_array($item['banner'] ?? null) ? $item['banner'] : null;
            $payBannerActive = is_array($payload['banner']) && ($payload['banner']['backendValue'] ?? null) === 'New';
            $apiBannerActive = $apiBanner !== null && ($apiBanner['backendValue'] ?? null) === 'New';
            if ($payBannerActive === $apiBannerActive) {
                $bScore = 50.0; // estado coincide
                if ($payBannerActive && $apiBannerActive) {
                    // También comparar el texto del banner
                    $payText = trim((string)($payload['banner']['value'] ?? ''));
                    $apiText = trim((string)($apiBanner['value'] ?? ''));
                    $bScore = ($payText === '' && $apiText === '') ? 100.0
                        : (dl_fuzzy_score($payText !== '' ? $payText : 'Nuevo', $apiText !== '' ? $apiText : 'Nuevo') >= 80 ? 100.0 : 50.0);
                } else {
                    $bScore = 100.0; // ambos inactivos = coincidencia perfecta
                }
            } else {
                $bScore = 0.0;
            }
            $scores['banner'] = $bScore;
            if ($bScore >= 60) $matchedFields++;
            $totalWeight += 1;
        }

        if (!empty($payload['tileSize'])) {
            $paySpan = dl_tile_span($payload['tileSize']);
            $apiSpan = dl_tile_span($item['tileSize'] ?? null);
            $tScore = ($paySpan === $apiSpan) ? 100.0 : 0.0;
            $scores['tileSize'] = $tScore;
            if ($tScore >= 60) $matchedFields++;
            $totalWeight += 1;
        }

        if ($totalWeight > 0) {
            $totalScore = array_sum($scores) / $totalWeight;
            $results[] = [
                'offerId' => $item['offerId'],
                'displayName' => $item['displayName'] ?? '',
                'section_name' => $item['section_name'] ?? '',
                'total_score' => $totalScore,
                'field_scores' => $scores,
                'matched_fields' => $matchedFields,
                'total_fields' => $totalWeight
            ];
        }
    }

    usort($results, fn($a, $b) => ($b['total_score'] <=> $a['total_score']) ?: ($b['matched_fields'] <=> $a['matched_fields']));
    $top = array_slice($results, 0, 5);

    return [
        'status' => 'success',
        'matches' => $top
    ];
}

/** Compile dl_custom_entries -> custom_entries.json. Returns active count. */
function dl_compile_custom_entries(mysqli $conn): int
{
    $out = [];
    $n = 0;
    if ($res = $conn->query('SELECT offer_id, entry_json FROM dl_custom_entries WHERE activo = 1')) {
        while ($row = $res->fetch_assoc()) {
            $entry = json_decode((string) $row['entry_json'], true);
            if (is_array($entry)) {
                $out[$row['offer_id']] = $entry;
                $n++;
            }
        }
    }
    dl_atomic_write(ShopSourceConfig::customEntriesPath(), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $n;
}

function dl_delete_override(mysqli $conn): array
{
    $offerId  = trim((string) ($_POST['offer_id'] ?? ''));
    $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
    if ($offerId === '') {
        throw new Exception('Falta offer_id.');
    }
    if ($fieldKey !== '') {
        $stmt = $conn->prepare('DELETE FROM dl_shop_overrides WHERE offer_id = ? AND field_key = ?');
        $stmt->bind_param('ss', $offerId, $fieldKey);
    } else {
        $stmt = $conn->prepare('DELETE FROM dl_shop_overrides WHERE offer_id = ?');
        $stmt->bind_param('s', $offerId);
    }
    $stmt->execute();
    $stmt->close();

    $n = dl_compile_overrides($conn);
    return ['status' => 'success', 'message' => "Override eliminado y recompilado ({$n} activos).", 'compiled' => $n];
}

function dl_save_field_map(mysqli $conn): array
{
    $id           = (int) ($_POST['id'] ?? 0);
    $scope        = (string) ($_POST['scope'] ?? '');
    $sourceKey    = trim((string) ($_POST['source_key'] ?? ''));
    $canonicalKey = trim((string) ($_POST['canonical_key'] ?? ''));
    $nota         = trim((string) ($_POST['nota'] ?? ''));
    $activo       = (int) (($_POST['activo'] ?? '1') ? 1 : 0);

    if (!in_array($scope, ['data', 'entry'], true)) {
        throw new Exception("scope debe ser 'data' o 'entry'.");
    }
    if ($sourceKey === '' || $canonicalKey === '') {
        throw new Exception('source_key y canonical_key son obligatorios.');
    }

    if ($id > 0) {
        $stmt = $conn->prepare('UPDATE dl_api_field_map SET scope = ?, source_key = ?, canonical_key = ?, activo = ?, nota = ? WHERE id = ?');
        $stmt->bind_param('sssisi', $scope, $sourceKey, $canonicalKey, $activo, $nota, $id);
    } else {
        $stmt = $conn->prepare('INSERT INTO dl_api_field_map (scope, source_key, canonical_key, activo, nota) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssis', $scope, $sourceKey, $canonicalKey, $activo, $nota);
    }
    $stmt->execute();
    $stmt->close();

    $n = dl_compile_field_map($conn);
    return ['status' => 'success', 'message' => "Mapa de campos guardado y compilado ({$n} activos).", 'compiled' => $n];
}

function dl_delete_field_map(mysqli $conn): array
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Falta id.');
    }
    $stmt = $conn->prepare('DELETE FROM dl_api_field_map WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $n = dl_compile_field_map($conn);
    return ['status' => 'success', 'message' => "Regla eliminada y recompilada ({$n} activas).", 'compiled' => $n];
}

function dl_clear_logs(mysqli $conn): array
{
    $conn->query('DELETE FROM dl_event_log');
    return ['status' => 'success', 'message' => 'Registro de eventos limpiado.'];
}

/** Eliminar TODOS los overrides de campo de todos los ítems. */
function dl_purge_all_overrides(mysqli $conn): array
{
    $res = $conn->query('SELECT COUNT(*) c FROM dl_shop_overrides');
    $before = (int) ($res ? $res->fetch_assoc()['c'] : 0);
    $conn->query('DELETE FROM dl_shop_overrides');
    $n = dl_compile_overrides($conn);
    return [
        'status'  => 'success',
        'message' => "Se eliminaron {$before} override(s). Recompilados: {$n} activos.",
        'deleted' => $before,
        'compiled_overrides' => $n
    ];
}

/** Eliminar TODAS las entradas manuales (objetos/secciones creados desde cero). */
function dl_purge_all_entries(mysqli $conn): array
{
    dl_ensure_custom_entries_table($conn);
    $res = $conn->query('SELECT COUNT(*) c FROM dl_custom_entries');
    $before = (int) ($res ? $res->fetch_assoc()['c'] : 0);
    $conn->query('DELETE FROM dl_custom_entries');
    $n = dl_compile_custom_entries($conn);
    return [
        'status'  => 'success',
        'message' => "Se eliminaron {$before} entrada(s) manual(es). Recompiladas: {$n} activas.",
        'deleted' => $before,
        'compiled_entries' => $n
    ];
}

/** Eliminar TODO: overrides + entradas manuales. */
function dl_purge_all(mysqli $conn): array
{
    dl_ensure_custom_entries_table($conn);
    $resOv = $conn->query('SELECT COUNT(*) c FROM dl_shop_overrides');
    $beforeOv = (int) ($resOv ? $resOv->fetch_assoc()['c'] : 0);
    $resEn = $conn->query('SELECT COUNT(*) c FROM dl_custom_entries');
    $beforeEn = (int) ($resEn ? $resEn->fetch_assoc()['c'] : 0);
    $conn->query('DELETE FROM dl_shop_overrides');
    $conn->query('DELETE FROM dl_custom_entries');
    $nOv = dl_compile_overrides($conn);
    $nEn = dl_compile_custom_entries($conn);
    return [
        'status'  => 'success',
        'message' => "Se eliminaron {$beforeOv} override(s) y {$beforeEn} entrada(s) manual(es). Recompilados: {$nOv} overrides, {$nEn} entradas.",
        'deleted_overrides' => $beforeOv,
        'deleted_entries'   => $beforeEn,
        'compiled_overrides' => $nOv,
        'compiled_entries'   => $nEn
    ];
}

// ===========================================================================
//  COMPILERS (SQL -> runtime artifacts read by the DAL)
// ===========================================================================

/** Compile dl_shop_overrides -> item_overrides.json. Returns active count. */
function dl_compile_overrides(mysqli $conn): int
{
    $out = [];
    $n = 0;
    if ($res = $conn->query('SELECT offer_id, field_key, field_value, value_type FROM dl_shop_overrides WHERE activo = 1')) {
        while ($row = $res->fetch_assoc()) {
            $out[$row['offer_id']] ??= [];
            $out[$row['offer_id']][$row['field_key']] = dl_cast_value($row['field_value'], $row['value_type']);
            $n++;
        }
    }
    dl_atomic_write(ShopSourceConfig::overridesPath(), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $n;
}

/** Compile dl_api_field_map -> api_field_map.json. Returns active count. */
function dl_compile_field_map(mysqli $conn): int
{
    $out = ['data' => [], 'entry' => []];
    $n = 0;
    if ($res = $conn->query("SELECT scope, source_key, canonical_key FROM dl_api_field_map WHERE activo = 1")) {
        while ($row = $res->fetch_assoc()) {
            $scope = $row['scope'] === 'entry' ? 'entry' : 'data';
            $out[$scope][] = ['from' => $row['source_key'], 'to' => $row['canonical_key']];
            $n++;
        }
    }
    dl_atomic_write(ShopSourceConfig::fieldMapPath(), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $n;
}

/** Cast a stored string value to its declared type for the compiled JSON. */
function dl_cast_value(string $value, string $type)
{
    switch ($type) {
        case 'number':
            return (strpos($value, '.') !== false) ? (float) $value : (int) $value;
        case 'json':
            $decoded = json_decode($value, true);
            return ($decoded === null && strtolower(trim($value)) !== 'null') ? $value : $decoded;
        case 'color':
        case 'string':
        default:
            return $value;
    }
}

// ===========================================================================
//  HELPERS
// ===========================================================================

function dl_atomic_write(string $path, $contents): void
{
    if ($contents === false) {
        throw new Exception('No se pudo serializar el artefacto compilado.');
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
        throw new Exception('No se pudo escribir el artefacto temporal.');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new Exception('No se pudo promover el artefacto compilado.');
    }
    @chmod($path, 0644);
}

function dl_count(mysqli $conn, string $sql): int
{
    try {
        if ($res = $conn->query($sql)) {
            $row = $res->fetch_assoc();
            return (int) ($row['c'] ?? 0);
        }
    } catch (Throwable $e) { /* table not created yet */ }
    return 0;
}

/** @return array<string,bool> set of offerIds with at least one active override. */
function dl_overridden_offer_ids(mysqli $conn): array
{
    $set = [];
    try {
        if ($res = $conn->query('SELECT DISTINCT offer_id FROM dl_shop_overrides WHERE activo = 1')) {
            while ($row = $res->fetch_assoc()) {
                $set[$row['offer_id']] = true;
            }
        }
    } catch (Throwable $e) { /* table not created yet */ }
    return $set;
}

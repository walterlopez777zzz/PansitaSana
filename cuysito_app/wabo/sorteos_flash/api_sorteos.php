<?php
// wabo/sorteos_flash/api_sorteos.php
// API ADMIN V2.2: GESTI�0�7N COMPLETA Y BLINDADA
// Fix: Conteo exacto de variables bind_param para evitar NULLs

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 1. SEGURIDAD Y CONEXI�0�7N
require_once __DIR__ . '/../../api/session_security.php';
date_default_timezone_set('America/Lima');
require_once __DIR__ . '/../core/db_connect.php'; 
require_once __DIR__ . '/../core/shop_helper.php';
require_once __DIR__ . '/../../core/AvatarCatalog.php';
require_once __DIR__ . '/../../api/raffle_review.php';
require_once __DIR__ . '/../../api/raffle_bans.php';
require_once __DIR__ . '/../../api/raffle_rewards.php';
require_once __DIR__ . '/../../api/raffle_welcome.php';
require_once __DIR__ . '/../../api/raffle_private.php';

// Verificar permisos de admin
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ob_end_clean(); 
    header('Content-Type: application/json'); 
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado.']); 
    exit;
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$response = []; 
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Extrae el ícono cuadrado enfocado del PERSONAJE (smallIcon) desde el cache.
 *
 * La URL real NO vive en el nivel de la oferta ($shopItem['images']), sino dentro
 * de la lista de cosméticos $shopItem['brItems'][]. Recorremos esa lista, contamos
 * los cosméticos de tipo "outfit" (type.value === 'outfit' o
 * type.backendValue === 'AthenaCharacter') y devolvemos el smallIcon (con
 * degradación a icon) SOLO cuando hay EXACTAMENTE UN personaje en la oferta.
 *
 * Así cubrimos los Atuendos puros y los lotes de un solo personaje (p.ej. "Pomni",
 * que el cache clasifica como displayType "Lote" pero contiene un único outfit
 * = Character_AgarBelly). Los lotes multi-personaje (2+ outfits) y los objetos sin
 * personaje (gestos, picos, papeles, etc.) devuelven '' y conservan su flujo
 * normal (imagen grande de siempre). Devuelve '' si no hay un enfoque usable.
 */
function sf_extract_outfit_small_icon(array $shopItem): string
{
    $pickFromImages = static function ($images): string {
        if (!is_array($images)) return '';
        $small = $images['smallIcon'] ?? '';
        if (is_string($small) && $small !== '') return $small;
        $icon = $images['icon'] ?? '';
        return (is_string($icon) && $icon !== '') ? $icon : '';
    };

    $brItems = $shopItem['brItems'] ?? null;
    if (is_array($brItems) && !empty($brItems)) {
        $outfitIcons = [];
        foreach ($brItems as $bi) {
            if (!is_array($bi)) continue;
            $type = $bi['type'] ?? [];
            $tVal = strtolower(trim((string)($type['value'] ?? '')));
            $tBackend = strtolower(trim((string)($type['backendValue'] ?? '')));
            if ($tVal === 'outfit' || $tBackend === 'athenacharacter') {
                $url = $pickFromImages($bi['images'] ?? null);
                if ($url !== '') $outfitIcons[] = $url;
            }
        }
        // Exactamente un personaje => atuendo o lote de un solo personaje.
        if (count($outfitIcons) === 1) return $outfitIcons[0];
        // 0 o 2+ personajes => ambiguo/no-atuendo: sin enfoque.
        return '';
    }

    // Oferta sin lista de cosméticos: solo tratamos como atuendo si su displayType
    // lo declara, usando las imágenes de la propia oferta.
    $dt = strtolower(trim((string)($shopItem['displayType'] ?? '')));
    if ($dt === 'atuendo' || $dt === 'outfit') {
        return $pickFromImages($shopItem['images'] ?? null);
    }
    return '';
}

/**
 * Crea la tabla de lista blanca (rescate manual de elegibilidad) si no existe.
 * Misma definición que en api/raffle_join.php para mantener consistencia.
 */
function cc_ensure_whitelist(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS raffle_whitelist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            raffle_id INT NOT NULL,
            user_id INT NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            added_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_raffle_user (raffle_id, user_id),
            KEY idx_raffle (raffle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Carpeta `assets/` del docroot público (`<docroot>/assets`), resuelta desde
 * `AvatarCatalog::publicRoot()` (DOCUMENT_ROOT o carpeta hermana de la app con
 * `web.php`), así que no depende del nombre `public_html`/`banano.pe` ni de
 * `cuysito_app`/`banano_app`. Devuelve '' si no existe.
 */
function sf_public_assets_dir(): string
{
    $base = realpath(AvatarCatalog::publicRoot() . '/assets');
    return ($base !== false && is_dir($base)) ? $base : '';
}

/**
 * Resuelve (creando si falta) el directorio físico de las pruebas de entrega.
 * Vive bajo el docroot público (`<docroot>/assets/raffle_proofs/`), igual que
 * los avatares y el audio, para poder servirse en `/assets/raffle_proofs/<file>`.
 * Devuelve la ruta absoluta o '' si no se pudo resolver/crear.
 */
function sf_proof_dir(): string
{
    $base = sf_public_assets_dir();
    if ($base === '') return '';
    $dir = $base . DIRECTORY_SEPARATOR . 'raffle_proofs';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return '';
    return $dir;
}

// Máximo de imágenes de prueba por ganador. El front diseña el collage para 2,
// pero permitimos algunas más sin saturar la columna VARCHAR(255).
if (!defined('SF_MAX_PROOFS')) define('SF_MAX_PROOFS', 4);

/**
 * Convierte el valor de delivery_proof_image (lista separada por comas) en un
 * array limpio de nombres de archivo. Tolera el formato antiguo de una sola
 * imagen y entradas vacías.
 */
function sf_parse_proofs($raw): array
{
    $raw = (string)($raw ?? '');
    $parts = array_map('trim', explode(',', $raw));
    return array_values(array_filter($parts, function ($n) { return $n !== ''; }));
}

/**
 * Lookup requerido por los helpers canónicos rr_* al valorar premios. En las
 * APIs públicas lo aporta raffle_core.php; el panel admin no carga ese motor,
 * así que declaramos la misma interfaz con caché en memoria por request.
 */
if (!function_exists('findProductInCache')) {
    function findProductInCache($offerId, $cacheFile) {
        static $itemsByFile = [];
        $cacheFile = (string)$cacheFile;
        if (!array_key_exists($cacheFile, $itemsByFile)) {
            $itemsByFile[$cacheFile] = [];
            if ($cacheFile !== '' && file_exists($cacheFile)) {
                $decoded = json_decode((string)file_get_contents($cacheFile), true);
                $items = $decoded['entries'] ?? $decoded['shop'] ?? [];
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if (!is_array($item) || empty($item['offerId'])) continue;
                        $itemsByFile[$cacheFile][strtolower((string)$item['offerId'])] = $item;
                    }
                }
            }
        }
        return $itemsByFile[$cacheFile][strtolower((string)$offerId)] ?? null;
    }
}

/** Valor real del componente original, respetando el ítem final de un pool. */
function sf_stats_original_value(mysqli $conn, array $positionPrize, $finalPrize, string $cacheFile): float
{
    if (is_string($finalPrize) && trim($finalPrize) !== '') $finalPrize = json_decode($finalPrize, true);
    if (!is_array($finalPrize) || empty($finalPrize)) {
        return round((float)rr_prize_value_coins($conn, $positionPrize, $cacheFile), 2);
    }

    $selectedValue = isset($finalPrize['kind'])
        ? (float)rr_option_value_coins($conn, $finalPrize, $cacheFile)
        : (float)rr_prize_value_coins($conn, $finalPrize, $cacheFile);

    if (rr_prize_is_pool($positionPrize)) return round($selectedValue, 2);
    if (rr_prize_is_combo($positionPrize)) {
        $total = $selectedValue;
        foreach (rr_combo_items($positionPrize) as $item) {
            if (!rr_prize_is_pool($item)) $total += (float)rr_prize_value_coins($conn, $item, $cacheFile);
        }
        return round($total, 2);
    }
    return round($selectedValue > 0 ? $selectedValue : (float)rr_prize_value_coins($conn, $positionPrize, $cacheFile), 2);
}

// ---------------------------------------------------------------
//  MASCOTA BANANO (Portal de Transparencia): helpers de slots
// ---------------------------------------------------------------
// Slots disponibles por dispositivo. Cada slot corresponde a una ubicación
// fija en transparencia.php (cabecera, paso 3, tabla, demo final).
if (!defined('BANANO_SLOTS_MAX')) define('BANANO_SLOTS_MAX', ['desktop' => 4, 'mobile' => 3]);
// Páginas manga completas (imagen única compartida por escritorio y móvil).
if (!defined('BANANO_PAGES')) define('BANANO_PAGES', ['manga_trampa']);

function banano_slots_config_path(): string
{
    $configDir = __DIR__ . '/../../config';
    if (!is_dir($configDir)) @mkdir($configDir, 0755, true);
    return $configDir . '/banano_slots.json';
}

function banano_read_slots(): array
{
    $slots = ['desktop' => [], 'mobile' => [], 'pages' => []];
    $path = banano_slots_config_path();
    if (file_exists($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['slots']) && is_array($decoded['slots'])) {
            foreach (['desktop', 'mobile'] as $dev) {
                if (isset($decoded['slots'][$dev]) && is_array($decoded['slots'][$dev])) {
                    $slots[$dev] = $decoded['slots'][$dev];
                }
            }
        }
        if (is_array($decoded) && isset($decoded['pages']) && is_array($decoded['pages'])) {
            $slots['pages'] = $decoded['pages'];
        }
    }
    return $slots;
}

function banano_write_slots(array $slots): void
{
    $payload = [
        'slots' => ['desktop' => $slots['desktop'] ?? [], 'mobile' => $slots['mobile'] ?? []],
        'pages' => $slots['pages'] ?? []
    ];
    $ok = file_put_contents(banano_slots_config_path(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($ok === false) throw new Exception('No se pudo escribir la configuración de la mascota.');
}

/** Valida y devuelve la clave de página manga desde $_POST. */
function banano_page_key(): string
{
    $key = (string)($_POST['page_key'] ?? '');
    if (!in_array($key, BANANO_PAGES, true)) throw new Exception('Página inválida.');
    return $key;
}

/** Valida y devuelve [device, slot] desde $_POST. */
function banano_slot_params(): array
{
    $device = (string)($_POST['device'] ?? '');
    if (!isset(BANANO_SLOTS_MAX[$device])) throw new Exception('Dispositivo inválido (use desktop o mobile).');
    $slot = (int)($_POST['slot'] ?? 0);
    if ($slot < 1 || $slot > BANANO_SLOTS_MAX[$device]) throw new Exception('Slot inválido.');
    return [$device, $slot];
}

function banano_assets_dir(): string
{
    $base = sf_public_assets_dir();
    if ($base === '') throw new Exception('Directorio de assets no encontrado.');
    $dir = $base . DIRECTORY_SEPARATOR . 'banano_poses';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new Exception('No se pudo crear el directorio de la mascota.');
    }
    return $dir;
}

/**
 * Directorio físico de imágenes de premios personalizados del pool
 * (`<docroot>/assets/raffle_prizes/`). Devuelve '' si no se pudo crear.
 */
function sf_prize_image_dir(): string
{
    $base = sf_public_assets_dir();
    if ($base === '') return '';
    $dir = $base . DIRECTORY_SEPARATOR . 'raffle_prizes';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return '';
    return $dir;
}

/**
 * Carga las entradas del snapshot local del catálogo (una sola vez por request).
 */
function sf_shop_entries(): array
{
    static $entries = null;
    if ($entries !== null) return $entries;
    $entries = [];
    require_once __DIR__ . '/../../config/shop_config.php';
    $cachePath = ShopSourceConfig::activeSnapshotPath() ?? ShopSourceConfig::liveSnapshotPath();
    if ($cachePath && file_exists($cachePath)) {
        $cacheData = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cacheData)) {
            $entries = $cacheData['entries'] ?? $cacheData['shop'] ?? [];
            if (!is_array($entries)) $entries = [];
        }
    }
    return $entries;
}

/**
 * Valida y normaliza el value_ref obligatorio de opciones custom/category.
 * @return array{unit:string, amount:float}
 */
function sf_validate_value_ref($raw, string $ctx): array
{
    if (!is_array($raw)) throw new Exception("$ctx: falta el Valor de Referencia.");
    $unit = (string)($raw['unit'] ?? 'vbucks');
    if (!in_array($unit, ['vbucks', 'soles'], true)) throw new Exception("$ctx: unidad de Valor de Referencia inválida.");
    $amount = (float)($raw['amount'] ?? 0);
    if ($amount <= 0) throw new Exception("$ctx: el Valor de Referencia debe ser mayor a 0.");
    return ['unit' => $unit, 'amount' => round($amount, 2)];
}

/**
 * Porcentaje de OFERTA de una opción/premio (1..99) o 0 si el ítem se entrega
 * gratis (100%) / no trae descuento. Un premio con oferta se entrega como cupón
 * personal (daily_discounts) y se valúa al valor del cupón (raffle_rewards.php).
 */
function sf_offer_discount_percent($raw): int
{
    if (!is_array($raw) || !isset($raw['discount_percent']) || !is_numeric($raw['discount_percent'])) return 0;
    $pct = (int)round((float)$raw['discount_percent']);
    return ($pct >= 1 && $pct <= 99) ? $pct : 0;
}

/**
 * Valida y enriquece UNA opción de premio (Schema 2) desde el payload admin.
 * kinds: coins | catalog | product | custom | category.
 */
function sf_sanitize_prize_option(mysqli $conn, $raw, string $ctx): array
{
    if (!is_array($raw)) throw new Exception("$ctx: opción inválida.");
    $kind = (string)($raw['kind'] ?? '');

    if ($kind === 'coins') {
        $amount = (float)($raw['amount'] ?? 0);
        if ($amount <= 0) throw new Exception("$ctx: monto de Bananos inválido.");
        return ['kind' => 'coins', 'amount' => $amount, 'name' => number_format($amount) . ' Monedas'];
    }

    if ($kind === 'catalog') {
        $offerId = (string)($raw['id'] ?? '');
        if ($offerId === '') throw new Exception("$ctx: falta el ítem del catálogo.");
        $opt = [
            'kind'  => 'catalog',
            'id'    => $offerId,
            'name'  => (string)($raw['name'] ?? 'Premio'),
            'image' => (string)($raw['image'] ?? ''),
        ];
        foreach (sf_shop_entries() as $shopItem) {
            if (($shopItem['offerId'] ?? '') !== $offerId) continue;
            $opt['name'] = $shopItem['displayName'] ?? $opt['name'];
            // Mismo orden de imagen que createItemCardNode() de la tienda:
            // primer render no-LEGO → render principal → displayAsset → featured/icon.
            $renderImages = $shopItem['newDisplayAsset']['renderImages'] ?? [];
            $render = '';
            if (is_array($renderImages)) {
                foreach ($renderImages as $renderEntry) {
                    if (!is_array($renderEntry) || empty($renderEntry['image'])) continue;
                    if (($renderEntry['productTag'] ?? '') === 'Product.Juno') continue;
                    $render = (string)$renderEntry['image'];
                    break;
                }
                if ($render === '' && !empty($renderImages[0]['image'])) $render = (string)$renderImages[0]['image'];
            }
            $da = $shopItem['displayAssets'][0] ?? null;
            $bestImage = $render !== '' ? $render : ($da ? ($da['url'] ?? '') : '');
            if (empty($bestImage)) $bestImage = $shopItem['images']['featured'] ?? ($shopItem['images']['icon'] ?? $opt['image']);
            $opt['image'] = $bestImage;
            $opt['vbucks'] = $shopItem['vbucks'] ?? null;
            $opt['rarity'] = $shopItem['rarity'] ?? null;
            $opt['colors'] = $shopItem['colors'] ?? null;
            $opt['displayType'] = $shopItem['displayType'] ?? null;
            $focusedIcon = sf_extract_outfit_small_icon($shopItem);
            if (!empty($focusedIcon) && $focusedIcon !== $bestImage) $opt['image_icon'] = $focusedIcon;
            break;
        }
        $pct = sf_offer_discount_percent($raw);
        if ($pct > 0) $opt['discount_percent'] = $pct;
        return $opt;
    }

    if ($kind === 'product') {
        $staticId = (int)($raw['id'] ?? 0);
        if ($staticId <= 0) throw new Exception("$ctx: producto estático inválido.");
        $opt = [
            'kind'  => 'product',
            'id'    => (string)$staticId,
            'name'  => (string)($raw['name'] ?? 'Premio'),
            'image' => (string)($raw['image'] ?? ''),
        ];
        $sp = $conn->prepare("SELECT nombre, imagen_url, precio_base, precio_oferta FROM productos_estaticos WHERE id = ? LIMIT 1");
        if ($sp) {
            $sp->bind_param("i", $staticId);
            $sp->execute();
            $spRow = $sp->get_result()->fetch_assoc();
            $sp->close();
            if ($spRow) {
                if (empty($opt['image']) && !empty($spRow['imagen_url'])) $opt['image'] = $spRow['imagen_url'];
                if (empty($opt['name']) && !empty($spRow['nombre'])) $opt['name'] = $spRow['nombre'];
                $offer = isset($spRow['precio_oferta']) ? (float)$spRow['precio_oferta'] : 0.0;
                $base  = isset($spRow['precio_base']) ? (float)$spRow['precio_base'] : 0.0;
                $effective = $offer > 0 ? $offer : $base;
                if ($effective > 0) $opt['base_price_soles'] = $effective;
            }
        }
        $pct = sf_offer_discount_percent($raw);
        if ($pct > 0) $opt['discount_percent'] = $pct;
        return $opt;
    }

    if ($kind === 'custom') {
        $name = trim((string)($raw['name'] ?? ''));
        if ($name === '') throw new Exception("$ctx: el premio personalizado requiere un título.");
        $opt = [
            'kind'      => 'custom',
            'custom_id' => (string)($raw['custom_id'] ?? ('c_' . uniqid())),
            'name'      => $name,
            'image'     => (string)($raw['image'] ?? ''),
            'value_ref' => sf_validate_value_ref($raw['value_ref'] ?? null, $ctx),
        ];
        $desc = trim((string)($raw['description'] ?? ''));
        if ($desc !== '') $opt['description'] = $desc;
        return $opt;
    }

    if ($kind === 'category') {
        $presetKey = (string)($raw['preset_key'] ?? '');
        $preset = null;
        foreach (rr_category_presets() as $p) {
            if ($p['key'] === $presetKey) { $preset = $p; break; }
        }
        $label = trim((string)($raw['label'] ?? ($preset['label'] ?? '')));
        if ($label === '') throw new Exception("$ctx: el premio por categoría requiere un nombre.");
        $categories = $raw['categories'] ?? ($preset['categories'] ?? []);
        if (!is_array($categories) || empty($categories)) throw new Exception("$ctx: el premio por categoría requiere categorías.");
        $valueRefRaw = $raw['value_ref'] ?? null;
        if (!is_array($valueRefRaw) && $preset) {
            $valueRefRaw = ['unit' => 'vbucks', 'amount' => $preset['vbucks']];
        }
        return [
            'kind'           => 'category',
            'preset_key'     => $presetKey !== '' ? $presetKey : 'custom',
            'label'          => $label,
            'name'           => $label,
            'categories'     => array_values(array_map('strval', $categories)),
            'image'          => (string)($raw['image'] ?? ''),
            'value_ref'      => sf_validate_value_ref($valueRefRaw, $ctx),
            'admin_resolves' => true,
        ];
    }

    throw new Exception("$ctx: tipo de opción desconocido ('$kind').");
}

/**
 * Construye el prize_config Schema 2 desde el payload JSON del constructor
 * de premios por puesto. Valida y enriquece cada opción (snapshot inmutable).
 */
function sf_build_prize_config_v2(mysqli $conn, string $rawJson, int $winnersCount): array
{
    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) throw new Exception('Configuración de premios inválida.');
    $positionsRaw = $decoded['positions'] ?? $decoded;
    if (!is_array($positionsRaw) || empty($positionsRaw)) throw new Exception('Debes configurar al menos un premio.');

    $positions = [];
    foreach ($positionsRaw as $entry) {
        if (!is_array($entry)) continue;
        $pos = (int)($entry['position'] ?? 0);
        if ($pos <= 0) throw new Exception('Puesto de premio inválido.');
        if (isset($positions[$pos])) throw new Exception("Puesto $pos duplicado.");
        $prizeRaw = $entry['prize'] ?? [];
        $mode = (string)($prizeRaw['mode'] ?? 'fixed');

        if ($mode === 'combo') {
            $itemsRaw = $prizeRaw['items'] ?? [];
            if (!is_array($itemsRaw) || count($itemsRaw) < 2) {
                throw new Exception("Puesto $pos: un combo necesita al menos 2 premios.");
            }
            $items = [];
            $poolCount = 0;
            $itemIdx = 0;
            foreach ($itemsRaw as $itemRaw) {
                $itemIdx++;
                if (!is_array($itemRaw)) throw new Exception("Puesto $pos: premio $itemIdx del combo inválido.");
                $itemMode = (string)($itemRaw['mode'] ?? 'fixed');
                if ($itemMode === 'pool') {
                    $poolCount++;
                    if ($poolCount > 1) throw new Exception("Puesto $pos: un combo solo puede incluir UN pool a elegir.");
                    $optionsRaw = $itemRaw['options'] ?? [];
                    if (!is_array($optionsRaw) || count($optionsRaw) < 2) {
                        throw new Exception("Puesto $pos: el pool del combo necesita al menos 2 opciones.");
                    }
                    $options = [];
                    $idx = 0;
                    foreach ($optionsRaw as $optRaw) {
                        $idx++;
                        $opt = sf_sanitize_prize_option($conn, $optRaw, "Puesto $pos, opción $idx del pool");
                        $opt['option_key'] = 'p' . $pos . '_o' . $idx;
                        $options[] = $opt;
                    }
                    $poolLabel = trim((string)($itemRaw['label'] ?? ''));
                    $items[] = [
                        'mode'    => 'pool',
                        'label'   => $poolLabel !== '' ? $poolLabel : 'Elige tu premio',
                        'options' => $options,
                    ];
                } else {
                    $opt = sf_sanitize_prize_option($conn, $itemRaw['option'] ?? [], "Puesto $pos, premio $itemIdx del combo");
                    if (($opt['kind'] ?? '') === 'category') {
                        throw new Exception("Puesto $pos: los premios por categoría solo pueden ir dentro del pool del combo.");
                    }
                    $items[] = ['mode' => 'fixed', 'option' => $opt];
                }
            }
            $label = trim((string)($prizeRaw['label'] ?? ''));
            $positions[$pos] = [
                'position' => $pos,
                'prize' => [
                    'mode'  => 'combo',
                    'label' => $label !== '' ? $label : 'Combo de premios',
                    'items' => $items,
                ],
            ];
        } elseif ($mode === 'pool') {
            $optionsRaw = $prizeRaw['options'] ?? [];
            if (!is_array($optionsRaw) || count($optionsRaw) < 2) {
                throw new Exception("Puesto $pos: un pool necesita al menos 2 opciones.");
            }
            $options = [];
            $idx = 0;
            foreach ($optionsRaw as $optRaw) {
                $idx++;
                $opt = sf_sanitize_prize_option($conn, $optRaw, "Puesto $pos, opción $idx");
                $opt['option_key'] = 'p' . $pos . '_o' . $idx;
                $options[] = $opt;
            }
            $label = trim((string)($prizeRaw['label'] ?? ''));
            $positions[$pos] = [
                'position' => $pos,
                'prize' => [
                    'mode'    => 'pool',
                    'label'   => $label !== '' ? $label : 'Elige tu premio',
                    'options' => $options,
                ],
            ];
        } else {
            $opt = sf_sanitize_prize_option($conn, $prizeRaw['option'] ?? [], "Puesto $pos");
            $positions[$pos] = [
                'position' => $pos,
                'prize' => ['mode' => 'fixed', 'option' => $opt],
            ];
        }
    }

    for ($i = 1; $i <= $winnersCount; $i++) {
        if (!isset($positions[$i])) throw new Exception("Falta configurar el premio del Puesto $i.");
    }

    ksort($positions);
    return ['schema' => 2, 'positions' => array_values($positions)];
}

/**
 * Resumen corto de un prize_config (v1 o Schema 2) para listados admin.
 */
function sf_prize_summary($prize): string
{
    if (!is_array($prize)) return 'Item';
    if ((int)($prize['schema'] ?? 0) === 2) {
        $parts = [];
        foreach (($prize['positions'] ?? []) as $entry) {
            if (!is_array($entry)) continue;
            $p = $entry['prize'] ?? [];
            if (($p['mode'] ?? '') === 'combo') {
                $parts[] = 'Combo (' . count($p['items'] ?? []) . ' premios)';
            } elseif (($p['mode'] ?? '') === 'pool') {
                $parts[] = 'Pool (' . count($p['options'] ?? []) . ' opc.)';
            } else {
                $opt = $p['option'] ?? [];
                $parts[] = ($opt['kind'] ?? '') === 'coins'
                    ? number_format((float)($opt['amount'] ?? 0)) . ' Monedas'
                    : (string)($opt['name'] ?? ($opt['label'] ?? 'Item'));
            }
        }
        if (count($parts) > 1) return count($parts) . ' puestos: ' . implode(' | ', $parts);
        return $parts[0] ?? 'Item';
    }
    return (($prize['type'] ?? '') === 'coins')
        ? number_format((float)($prize['amount'] ?? 0)) . ' Monedas'
        : (string)($prize['name'] ?? 'Item');
}

/** Construye el contrato de entrega de un ganador para las vistas administrativas. */
function sf_winner_fulfillment(mysqli $conn, array $raffle, array $winner): array
{
    require_once __DIR__ . '/../../config/shop_config.php';
    $cacheFile = ShopSourceConfig::activeSnapshotPath() ?? ShopSourceConfig::liveSnapshotPath();
    $positionPrize = rr_prize_for_position($raffle['prize_config'] ?? '{}', (int)($winner['position'] ?? 1));
    $finalPrize = !empty($winner['final_prize'])
        ? (is_array($winner['final_prize']) ? $winner['final_prize'] : json_decode($winner['final_prize'], true))
        : null;
    $gambleResult = !empty($winner['gamble_result'])
        ? (is_array($winner['gamble_result']) ? $winner['gamble_result'] : json_decode($winner['gamble_result'], true))
        : [];
    if (!is_array($gambleResult)) $gambleResult = [];

    $cashbackCredit = ['coins' => 0.0, 'source' => null];
    if (($winner['choice'] ?? '') === 'cashback') {
        $cashbackCredit = rr_cashback_credit_view(
            $conn,
            (int)($winner['user_id'] ?? 0),
            (int)($winner['raffle_id'] ?? $raffle['raffle_id'] ?? 0),
            $winner['cashback_credited_coins'] ?? null,
            $positionPrize,
            $cacheFile,
            (float)($raffle['cashback_percent'] ?? 0)
        );
    }

    $fulfillment = rr_winner_fulfillment(
        $winner['choice'] ?? '',
        $positionPrize,
        $finalPrize,
        $gambleResult,
        $cashbackCredit
    );

    if (($winner['choice'] ?? '') === 'double_won' && (float)$fulfillment['automatic_credit_coins'] <= 0) {
        $raffleId = (int)($winner['raffle_id'] ?? $raffle['raffle_id'] ?? 0);
        $doubleCredit = rr_recorded_raffle_credit($conn, (int)($winner['user_id'] ?? 0), $raffleId, 'raffle_double_bonus');
        if ($doubleCredit > 0) {
            $fulfillment['automatic_credit_coins'] = $doubleCredit;
            $fulfillment['automatic_credit_source'] = 'ledger';
        } else {
            $fulfillment['automatic_credit_coins'] = rr_prize_value_coins($conn, $positionPrize, $cacheFile);
            $fulfillment['automatic_credit_source'] = 'legacy_recomputed';
        }
    }

    return [
        'fulfillment' => $fulfillment,
        'prize' => rr_prize_public_view($positionPrize),
        'final_prize' => $finalPrize,
    ];
}

/** Bloquea mutaciones manuales para resultados automáticos o sin recompensa. */
function sf_require_manual_delivery(mysqli $conn, int $winnerId): array
{
    $stmt = $conn->prepare(
        "SELECT w.id, w.raffle_id, w.user_id, w.position, w.choice, w.prize_state,
                w.final_prize, w.gamble_result, w.cashback_credited_coins,
                r.prize_config, r.cashback_percent
         FROM raffle_winners w
         JOIN raffles r ON r.raffle_id = w.raffle_id
         WHERE w.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $winnerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new Exception('Ganador no encontrado.');

    $resolved = sf_winner_fulfillment($conn, $row, $row);
    if (empty($resolved['fulfillment']['manual_delivery_required'])) {
        throw new Exception('Este resultado no requiere entrega manual.');
    }
    return $resolved;
}

try {
    // Esquema de revisión disponible para todas las acciones (idempotente).
    rr_ensure_schema($conn);

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        // --- A. OBTENER DATOS AUXILIARES ---
        if ($action === 'get_form_data') {
            $shopResult = get_fortnite_shop_data(); 
            $staticProducts = [];
            $res = $conn->query("SELECT id, nombre, imagen_url, precio_base, precio_oferta, tipo_oferta FROM productos_estaticos WHERE activo = 1 ORDER BY nombre ASC");
            if($res) {
                while($row = $res->fetch_assoc()) $staticProducts[] = $row;
            }
            $response = ['status' => 'success', 'data' => ['fortnite_sections' => $shopResult['sections'] ?? [], 'static_products' => $staticProducts, 'category_presets' => rr_category_presets()]];
        }
        
        // --- A2. LISTAR ARCHIVOS DE AUDIO ---
        elseif ($action === 'list_audio_files') {
            $assetsDir = sf_public_assets_dir();
            $audioDir  = $assetsDir !== '' ? realpath($assetsDir . '/fx/audio') : false;
            $files = [];
            if ($audioDir && is_dir($audioDir)) {
                $allowed = ['mp3', 'wav', 'ogg', 'webm', 'm4a'];
                foreach (scandir($audioDir) as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed)) {
                        $files[] = [
                            'name' => $f,
                            'size' => filesize($audioDir . DIRECTORY_SEPARATOR . $f),
                            'url'  => '/assets/fx/audio/' . rawurlencode($f)
                        ];
                    }
                }
            }
            $response = ['status' => 'success', 'files' => $files];
        }

        // --- A3. LISTAR SLOTS DE LA MASCOTA (Portal de Transparencia) ---
        // Slots separados por dispositivo (desktop/mobile), cada uno con su
        // imagen y ajustes visuales (escala, rotación, offset X/Y).
        elseif ($action === 'banano_get_slots') {
            $response = ['status' => 'success', 'slots' => banano_read_slots()];
        }

        // --- B. LISTAR SORTEOS ---
        elseif ($action === 'list') {
            rp_ensure_schema($conn);
            $sql = "SELECT r.raffle_id, r.title, r.status, r.start_time, r.end_time, r.prize_config, r.requirements, r.entry_cost, r.max_participants, r.is_private,
                    (SELECT COUNT(*) FROM raffle_invitations ri WHERE ri.raffle_id = r.raffle_id) as invited_count,
                    (SELECT COUNT(*) FROM raffle_participants WHERE raffle_id = r.raffle_id AND review_status <> 'expelled') as participants_count,
                    (SELECT COUNT(*) FROM raffle_winners ww WHERE ww.raffle_id = r.raffle_id AND ww.choice <> 'burned') as winners_total,
                    (SELECT COUNT(*) FROM raffle_winners ww WHERE ww.raffle_id = r.raffle_id AND ww.choice <> 'burned' AND ww.delivery_status = 'delivered') as delivered_total
                    FROM raffles r ORDER BY r.raffle_id DESC LIMIT 50";
            
            $result = $conn->query($sql);
            $data = [];
            while($row = $result->fetch_assoc()) {
                $prize = json_decode($row['prize_config'], true);
                $row['prize_summary'] = sf_prize_summary($prize);
                
                $winRes = $conn->query("SELECT u.game_username FROM raffle_winners rw JOIN users u ON rw.user_id = u.id WHERE rw.raffle_id = " . $row['raffle_id'] . " AND rw.choice <> 'burned' ORDER BY rw.position ASC LIMIT 1");
                $row['winner_summary'] = ($winRes && $winRes->num_rows > 0) ? $winRes->fetch_assoc()['game_username'] : '-';

                // Flags Early-bird Modo B (tiempo) para el botón "Iniciar ventana".
                $rowReqs = json_decode($row['requirements'] ?? '{}', true);
                $eb = (is_array($rowReqs) && isset($rowReqs['early_bird'])) ? $rowReqs['early_bird'] : [];
                $row['eb_time_mode'] = (($eb['mode'] ?? '') === 'time') ? 1 : 0;
                $row['eb_window_started'] = !empty($eb['window_started_at']) ? 1 : 0;
                $row['is_private'] = (int)($row['is_private'] ?? 0);
                $row['invited_count'] = (int)($row['invited_count'] ?? 0);
                unset($row['requirements']);

                $data[] = $row;
            }
            $response = ['status' => 'success', 'data' => $data];
        }

        // --- B2. PRUEBA DE ENTREGA: GANADORES DE UN SORTEO ---
        // Lista los ganadores reales (excluye 'burned') con su estado de entrega
        // y la prueba subida, para el modal "Subir premios" del panel admin.
        elseif ($action === 'cc_winners') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) throw new Exception('Sorteo inválido.');

            $raffleStmt = $conn->prepare("SELECT raffle_id, prize_config, cashback_percent FROM raffles WHERE raffle_id = ? LIMIT 1");
            $raffleStmt->bind_param('i', $id);
            $raffleStmt->execute();
            $raffle = $raffleStmt->get_result()->fetch_assoc();
            $raffleStmt->close();
            if (!$raffle) throw new Exception('Sorteo no encontrado.');

            $sql = "SELECT w.id, w.raffle_id, w.position, w.user_id, w.choice, w.prize_sent,
                           w.prize_state, w.final_prize, w.prize_selected_at,
                           w.gamble_result, w.cashback_credited_coins,
                           w.delivery_status, w.delivery_proof_image, w.delivered_at, w.delivery_note,
                           COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name,
                           u.game_username, u.whatsapp_phone_e164 AS phone, u.is_verified,
                           a.filename AS avatar
                    FROM raffle_winners w
                    JOIN users u ON w.user_id = u.id
                    LEFT JOIN avatars a ON u.avatar_id = a.id
                    WHERE w.raffle_id = ? AND w.choice <> 'burned'
                    ORDER BY w.position ASC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $winners = [];
            while ($w = $res->fetch_assoc()) {
                $resolved = sf_winner_fulfillment($conn, $raffle, $w);
                $fulfillment = $resolved['fulfillment'];
                $manualDelivery = !empty($fulfillment['manual_delivery_required']);
                $proofNames = $manualDelivery ? sf_parse_proofs($w['delivery_proof_image'] ?? null) : [];
                $proofUrls = array_map(function ($n) { return '/assets/raffle_proofs/' . rawurlencode($n); }, $proofNames);
                $winners[] = [
                    'winner_id' => (int)$w['id'],
                    'position' => (int)$w['position'],
                    'user_id' => (int)$w['user_id'],
                    'name' => $w['name'],
                    'game_username' => $w['game_username'],
                    'phone' => $w['phone'] ?? '',
                    'is_verified' => (int)$w['is_verified'],
                    'avatar' => AvatarCatalog::filename($conn, $w['avatar'] ?? null),
                    'choice' => $w['choice'],
                    'prize' => $resolved['prize'],
                    'fulfillment' => $fulfillment,
                    'automatic_credit_coins' => (float)$fulfillment['automatic_credit_coins'],
                    'delivery_status' => $manualDelivery ? ($w['delivery_status'] ?: 'pending') : null,
                    'delivery_note' => $manualDelivery ? ($w['delivery_note'] ?? '') : '',
                    'delivered_at' => $manualDelivery ? ($w['delivered_at'] ?? null) : null,
                    // Lista de pruebas (puede tener varias). Se conservan
                    // proof_image/proof_url (la primera) por compatibilidad.
                    'proof_images' => $proofNames,
                    'proof_urls' => $proofUrls,
                    'proof_count' => count($proofNames),
                    'max_proofs' => SF_MAX_PROOFS,
                    'proof_image' => $proofNames[0] ?? null,
                    'proof_url' => $proofUrls[0] ?? null,
                    // Estado del pool de premios (Schema 2)
                    'prize_state' => $w['prize_state'] ?? null,
                    'final_prize' => $resolved['final_prize'],
                    'prize_selected_at' => $w['prize_selected_at'] ?? null,
                ];
            }
            $response = ['status' => 'success', 'winners' => $winners];
        }

        // --- B3. PRUEBA DE ENTREGA: MÉTRICAS TÉCNICAS DE UN SORTEO ---
        // Datos netamente técnicos para el modal de "Detalles": duración,
        // participantes, premio, configuración y datos provably-fair.
        elseif ($action === 'cc_raffle_metrics') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) throw new Exception('Sorteo inválido.');

            $rStmt = $conn->prepare(
                "SELECT raffle_id, title, status, start_time, end_time, animation_start_at,
                        winners_count, burn_count, execution_mode, entry_cost, max_participants,
                        current_pot, prize_config, requirements, allow_cashback, cashback_percent,
                        allow_double_or_nothing, server_seed_hash, bsc_block_hash, bsc_block_target
                 FROM raffles WHERE raffle_id = ?"
            );
            $rStmt->bind_param("i", $id);
            $rStmt->execute();
            $raffle = $rStmt->get_result()->fetch_assoc();
            if (!$raffle) throw new Exception('Sorteo no encontrado.');

            $prize = json_decode($raffle['prize_config'] ?? '{}', true);
            $prizeSummary = sf_prize_summary($prize);

            $pc = $conn->prepare("SELECT COUNT(*) c FROM raffle_participants WHERE raffle_id = ? AND review_status <> 'expelled'");
            $pc->bind_param("i", $id); $pc->execute();
            $participants = (int)($pc->get_result()->fetch_assoc()['c'] ?? 0);

            $pcAll = $conn->prepare("SELECT COUNT(*) c FROM raffle_participants WHERE raffle_id = ?");
            $pcAll->bind_param("i", $id); $pcAll->execute();
            $participantsAll = (int)($pcAll->get_result()->fetch_assoc()['c'] ?? 0);

            $wc = $conn->prepare("SELECT COUNT(*) c FROM raffle_winners WHERE raffle_id = ? AND choice <> 'burned'");
            $wc->bind_param("i", $id); $wc->execute();
            $winnersReal = (int)($wc->get_result()->fetch_assoc()['c'] ?? 0);

            $dc = $conn->prepare("SELECT COUNT(*) c FROM raffle_winners WHERE raffle_id = ? AND choice <> 'burned' AND delivery_status = 'delivered'");
            $dc->bind_param("i", $id); $dc->execute();
            $deliveredCount = (int)($dc->get_result()->fetch_assoc()['c'] ?? 0);

            // Duración real (segundos) entre apertura y cierre, si ambos existen.
            $durationSeconds = null;
            if (!empty($raffle['start_time']) && !empty($raffle['end_time'])) {
                $st = strtotime($raffle['start_time']);
                $et = strtotime($raffle['end_time']);
                if ($st && $et && $et >= $st) $durationSeconds = $et - $st;
            }

            $response = [
                'status' => 'success',
                'metrics' => [
                    'raffle_id' => (int)$raffle['raffle_id'],
                    'title' => $raffle['title'],
                    'state' => $raffle['status'],
                    'start_time' => $raffle['start_time'],
                    'end_time' => $raffle['end_time'],
                    'duration_seconds' => $durationSeconds,
                    'participants' => $participants,
                    'participants_all' => $participantsAll,
                    'winners_config' => (int)$raffle['winners_count'],
                    'winners_real' => $winnersReal,
                    'delivered_count' => $deliveredCount,
                    'burn_count' => (int)$raffle['burn_count'],
                    'execution_mode' => $raffle['execution_mode'],
                    'entry_cost' => (float)$raffle['entry_cost'],
                    'max_participants' => (int)$raffle['max_participants'],
                    'current_pot' => (float)$raffle['current_pot'],
                    'prize_type' => ((int)($prize['schema'] ?? 0) === 2) ? 'schema2' : ($prize['type'] ?? 'unknown'),
                    'prize_summary' => $prizeSummary,
                    'allow_cashback' => (int)$raffle['allow_cashback'],
                    'cashback_percent' => (int)$raffle['cashback_percent'],
                    'allow_double_or_nothing' => (int)$raffle['allow_double_or_nothing'],
                    'server_seed_hash' => $raffle['server_seed_hash'],
                    'bsc_block_hash' => $raffle['bsc_block_hash'],
                    'bsc_block_target' => $raffle['bsc_block_target'],
                ],
            ];
        }

        // --- ESTADÍSTICAS Y MÉTRICAS COMUNITARIAS (histórico global) ---
        elseif ($action === 'community_stats') {
            require_once __DIR__ . '/../../config/shop_config.php';
            $cacheFile = (string)(ShopSourceConfig::activeSnapshotPath() ?? ShopSourceConfig::liveSnapshotPath() ?? '');
            $rates = rr_coins_unit_rates($conn);
            $unitCoins = (float)$rates['unit_coins'];
            $unitMoney = (float)$rates['unit_money'];
            $coinToSoles = $unitCoins > 0 ? ($unitMoney / $unitCoins) : 0.0;

            $completedRow = $conn->query("SELECT COUNT(*) AS c FROM raffles WHERE status = 'completed'")->fetch_assoc();
            $completed = (int)($completedRow['c'] ?? 0);

            $partRow = $conn->query(
                "SELECT COUNT(*) AS entries, COUNT(DISTINCT rp.user_id) AS people,
                        COALESCE(SUM(rp.entry_fee_paid), 0) AS fees
                 FROM raffle_participants rp
                 JOIN raffles r ON r.raffle_id = rp.raffle_id AND r.status = 'completed'
                 WHERE rp.review_status <> 'expelled'"
            )->fetch_assoc();
            $entries = (int)($partRow['entries'] ?? 0);
            $uniquePeople = (int)($partRow['people'] ?? 0);
            $entryFees = round((float)($partRow['fees'] ?? 0), 2);

            $repeatRow = $conn->query(
                "SELECT COUNT(*) AS c FROM (
                    SELECT rp.user_id
                    FROM raffle_participants rp
                    JOIN raffles r ON r.raffle_id = rp.raffle_id AND r.status = 'completed'
                    WHERE rp.review_status <> 'expelled'
                    GROUP BY rp.user_id HAVING COUNT(DISTINCT rp.raffle_id) > 1
                 ) repeaters"
            )->fetch_assoc();
            $repeatPeople = (int)($repeatRow['c'] ?? 0);

            $record = ['raffle_id' => null, 'title' => '—', 'participants' => 0];
            $recordRes = $conn->query(
                "SELECT r.raffle_id, r.title, COUNT(rp.user_id) AS participants
                 FROM raffles r
                 LEFT JOIN raffle_participants rp ON rp.raffle_id = r.raffle_id AND rp.review_status <> 'expelled'
                 WHERE r.status = 'completed'
                 GROUP BY r.raffle_id, r.title
                 ORDER BY participants DESC, r.raffle_id DESC LIMIT 1"
            );
            if ($recordRes && ($recordRow = $recordRes->fetch_assoc())) {
                $record = [
                    'raffle_id' => (int)$recordRow['raffle_id'],
                    'title' => $recordRow['title'],
                    'participants' => (int)$recordRow['participants'],
                ];
            }

            $choices = ['original' => 0, 'cashback' => 0, 'double' => 0];
            $doubleWins = 0;
            $doubleLosses = 0;
            $winnerCount = 0;
            $awardedCoins = 0.0;
            $awarded30d = 0.0;
            $winners30d = 0;
            $unvalued = 0;
            $pendingDeliveries = 0;
            $userRanking = [];
            $ticketCounts = [];
            $monthlyPrizeValues = [];

            $winnerSql = "SELECT w.id, w.raffle_id, w.user_id, w.position, w.choice,
                                 w.final_prize, w.gamble_result, w.cashback_credited_coins,
                                 w.delivery_status, r.prize_config, r.cashback_percent,
                                 COALESCE(r.end_time, r.start_time) AS ended_at,
                                 COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name,
                                 u.game_username, u.is_verified, a.filename AS avatar,
                                 (SELECT MIN(rp.ticket_index) FROM raffle_participants rp
                                  WHERE rp.raffle_id = w.raffle_id AND rp.user_id = w.user_id) AS ticket_index
                          FROM raffle_winners w
                          JOIN raffles r ON r.raffle_id = w.raffle_id AND r.status = 'completed'
                          JOIN users u ON u.id = w.user_id
                          LEFT JOIN avatars a ON a.id = u.avatar_id
                          WHERE w.choice IN ('original','cashback','double_won','double_lost')
                          ORDER BY w.raffle_id ASC, w.position ASC";
            $winnerRes = $conn->query($winnerSql);
            if (!$winnerRes) throw new Exception('No se pudo calcular el histórico de ganadores.');

            while ($w = $winnerRes->fetch_assoc()) {
                $winnerCount++;
                $choice = (string)$w['choice'];
                if ($choice === 'original') $choices['original']++;
                elseif ($choice === 'cashback') $choices['cashback']++;
                else {
                    $choices['double']++;
                    if ($choice === 'double_won') $doubleWins++; else $doubleLosses++;
                }

                $raffleConfig = json_decode((string)$w['prize_config'], true);
                if (!is_array($raffleConfig)) $raffleConfig = [];
                $positionPrize = rr_prize_for_position($raffleConfig, (int)$w['position']);
                $originalValue = sf_stats_original_value($conn, $positionPrize, $w['final_prize'], $cacheFile);
                $payout = 0.0;

                if ($choice === 'original') {
                    $payout = $originalValue;
                } elseif ($choice === 'cashback') {
                    $cashback = rr_cashback_credit_view(
                        $conn,
                        (int)$w['user_id'],
                        (int)$w['raffle_id'],
                        $w['cashback_credited_coins'],
                        $positionPrize,
                        $cacheFile,
                        (float)$w['cashback_percent']
                    );
                    $payout = (float)$cashback['coins'];
                } elseif ($choice === 'double_won') {
                    $gamble = json_decode((string)$w['gamble_result'], true);
                    $bonus = is_array($gamble) ? (float)($gamble['double_bonus_coins'] ?? 0) : 0.0;
                    if ($bonus <= 0) {
                        $bonus = rr_recorded_raffle_credit($conn, (int)$w['user_id'], (int)$w['raffle_id'], 'raffle_double_bonus');
                    }
                    if ($bonus <= 0) $bonus = $originalValue;
                    $payout = $originalValue + $bonus;
                }
                $payout = round(max(0, $payout), 2);
                $awardedCoins += $payout;

                if ($payout <= 0 && $choice !== 'double_lost') $unvalued++;

                $resolved = sf_winner_fulfillment($conn, $w, $w);
                if (!empty($resolved['fulfillment']['manual_delivery_required']) && ($w['delivery_status'] ?? '') !== 'delivered') {
                    $pendingDeliveries++;
                }

                $endedTs = !empty($w['ended_at']) ? strtotime($w['ended_at']) : false;
                if ($endedTs && $endedTs >= strtotime('-30 days')) {
                    $winners30d++;
                    $awarded30d += $payout;
                }
                if ($endedTs) {
                    $monthKey = date('Y-m', $endedTs);
                    $monthlyPrizeValues[$monthKey] = ($monthlyPrizeValues[$monthKey] ?? 0) + $payout;
                }

                $ticket = ($w['ticket_index'] !== null) ? (int)$w['ticket_index'] : null;
                $gambleMeta = json_decode((string)$w['gamble_result'], true);
                if (is_array($gambleMeta) && isset($gambleMeta['winning_ticket_index']) && (int)$gambleMeta['winning_ticket_index'] >= 0) {
                    $ticket = (int)$gambleMeta['winning_ticket_index'];
                }
                if ($ticket !== null && $ticket >= 0) $ticketCounts[$ticket] = ($ticketCounts[$ticket] ?? 0) + 1;

                $uid = (int)$w['user_id'];
                if (!isset($userRanking[$uid])) {
                    $userRanking[$uid] = [
                        'user_id' => $uid,
                        'name' => $w['name'],
                        'game_username' => $w['game_username'],
                        'avatar' => AvatarCatalog::filename($conn, $w['avatar'] ?? null),
                        'is_verified' => (int)$w['is_verified'],
                        'wins' => 0,
                        'prize_coins' => 0.0,
                    ];
                }
                $userRanking[$uid]['wins']++;
                $userRanking[$uid]['prize_coins'] += $payout;
            }

            $awardedCoins = round($awardedCoins, 2);
            $awarded30d = round($awarded30d, 2);
            foreach ($userRanking as &$rankUser) $rankUser['prize_coins'] = round($rankUser['prize_coins'], 2);
            unset($rankUser);
            usort($userRanking, function ($a, $b) {
                if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
                if ($a['prize_coins'] !== $b['prize_coins']) return $b['prize_coins'] <=> $a['prize_coins'];
                return strcasecmp((string)$a['name'], (string)$b['name']);
            });
            $topWinners = array_slice($userRanking, 0, 10);

            $luckyTicket = null;
            if (!empty($ticketCounts)) {
                $maxRepeats = max($ticketCounts);
                $tiedTickets = array_keys(array_filter($ticketCounts, function ($n) use ($maxRepeats) { return $n === $maxRepeats; }));
                sort($tiedTickets, SORT_NUMERIC);
                $luckyTicket = ['ticket' => (int)$tiedTickets[0], 'repeats' => (int)$maxRepeats, 'ties' => count($tiedTickets)];
            }

            $months = [];
            $monthCursor = new DateTimeImmutable('first day of this month 00:00:00');
            $monthCursor = $monthCursor->modify('-11 months');
            for ($i = 0; $i < 12; $i++) {
                $key = $monthCursor->format('Y-m');
                $months[$key] = [
                    'key' => $key,
                    'label' => $monthCursor->format('M'),
                    'raffles' => 0,
                    'participants' => 0,
                    'prize_coins' => round((float)($monthlyPrizeValues[$key] ?? 0), 2),
                ];
                $monthCursor = $monthCursor->modify('+1 month');
            }
            $monthRes = $conn->query(
                "SELECT DATE_FORMAT(COALESCE(r.end_time, r.start_time), '%Y-%m') AS month_key,
                        COUNT(*) AS raffles,
                        COALESCE(SUM((SELECT COUNT(*) FROM raffle_participants rp
                                     WHERE rp.raffle_id = r.raffle_id AND rp.review_status <> 'expelled')), 0) AS participants
                 FROM raffles r
                 WHERE r.status = 'completed'
                   AND COALESCE(r.end_time, r.start_time) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
                 GROUP BY month_key ORDER BY month_key ASC"
            );
            if ($monthRes) {
                while ($m = $monthRes->fetch_assoc()) {
                    $key = (string)$m['month_key'];
                    if (!isset($months[$key])) continue;
                    $months[$key]['raffles'] = (int)$m['raffles'];
                    $months[$key]['participants'] = (int)$m['participants'];
                }
            }

            $expelledRow = $conn->query(
                "SELECT COUNT(*) AS c FROM raffle_participants rp
                 JOIN raffles r ON r.raffle_id = rp.raffle_id AND r.status = 'completed'
                 WHERE rp.review_status = 'expelled'"
            )->fetch_assoc();
            $expelledTickets = (int)($expelledRow['c'] ?? 0);
            $decisionTotal = array_sum($choices);
            $doubleTotal = $doubleWins + $doubleLosses;

            $response = [
                'status' => 'success',
                'generated_at' => gmdate('c'),
                'rate' => [
                    'unit_coins' => round($unitCoins, 2),
                    'unit_money' => round($unitMoney, 2),
                    'coin_to_soles' => round($coinToSoles, 6),
                ],
                'general' => [
                    'completed_raffles' => $completed,
                    'awarded_coins' => $awardedCoins,
                    'awarded_soles' => round($awardedCoins * $coinToSoles, 2),
                    'winners' => $winnerCount,
                    'unique_participants' => $uniquePeople,
                    'participations' => $entries,
                    'avg_participants' => $completed > 0 ? round($entries / $completed, 1) : 0,
                    'winner_rate' => $entries > 0 ? round($winnerCount * 100 / $entries, 1) : 0,
                    'repeat_participants' => $repeatPeople,
                    'repeat_rate' => $uniquePeople > 0 ? round($repeatPeople * 100 / $uniquePeople, 1) : 0,
                    'last_30d_winners' => $winners30d,
                    'last_30d_coins' => $awarded30d,
                ],
                'decisions' => [
                    'total' => $decisionTotal,
                    'original' => ['count' => $choices['original'], 'pct' => $decisionTotal > 0 ? round($choices['original'] * 100 / $decisionTotal, 1) : 0],
                    'cashback' => ['count' => $choices['cashback'], 'pct' => $decisionTotal > 0 ? round($choices['cashback'] * 100 / $decisionTotal, 1) : 0],
                    'double' => ['count' => $choices['double'], 'pct' => $decisionTotal > 0 ? round($choices['double'] * 100 / $decisionTotal, 1) : 0],
                    'double_wins' => $doubleWins,
                    'double_losses' => $doubleLosses,
                    'double_win_rate' => $doubleTotal > 0 ? round($doubleWins * 100 / $doubleTotal, 1) : 0,
                ],
                'top_winners' => $topWinners,
                'lucky_ticket' => $luckyTicket,
                'record_raffle' => $record,
                'monthly' => array_values($months),
                'internal' => [
                    'entry_fees_coins' => $entryFees,
                    'pending_deliveries' => $pendingDeliveries,
                    'expelled_tickets' => $expelledTickets,
                    'unvalued_prizes' => $unvalued,
                ],
            ];
        }

        // --- A3. OBTENER CONFIG GLOBAL DE AUDIO ---
        elseif ($action === 'get_global_audio') {
            $configPath = __DIR__ . '/../../config/sorteo_audio_global.json';
            $audioConfig = null;
            if (file_exists($configPath)) {
                $audioConfig = json_decode(file_get_contents($configPath), true);
            }
            $response = ['status' => 'success', 'audio_config' => $audioConfig ?: new stdClass()];
        }

        // --- C. OBTENER UN SORTEO ESPEC�0�1FICO ---
        elseif ($action === 'get_raffle') {
            $id = (int)$_GET['id'];
            $res = $conn->query("SELECT * FROM raffles WHERE raffle_id = $id");
            if ($row = $res->fetch_assoc()) {
                $row['prize_config'] = json_decode($row['prize_config'], true);
                $row['requirements'] = json_decode($row['requirements'], true);
                // Casts num��ricos importantes
                $row['is_pot_enabled'] = (int)$row['is_pot_enabled'];
                $row['allow_cashback'] = (int)$row['allow_cashback'];
                $row['allow_double_or_nothing'] = (int)$row['allow_double_or_nothing'];
                $row['allow_winner_animation_choice'] = (int)($row['allow_winner_animation_choice'] ?? 0);
                $row['allow_pre_choice'] = (int)($row['allow_pre_choice'] ?? 1);
                $row['is_private'] = (int)($row['is_private'] ?? 0);
                $response = ['status' => 'success', 'data' => $row];
            } else {
                throw new Exception("Sorteo no encontrado");
            }
        }
        // --- MÉTRICAS EN VIVO (presencia de espectadores) ---
        elseif ($action === 'live_metrics') {
            $id = (int)($_GET['id'] ?? 0);
            $windowSec = 30; // "Rating en vivo" = activos en los últimos 30s
            $liveCount = 0;
            $uniqueViewers = 0;
            // Best-effort: si la tabla aún no existe (nadie ha entrado), devolvemos ceros.
            $tbl = $conn->query("SHOW TABLES LIKE 'raffle_viewers'");
            if ($tbl && $tbl->num_rows > 0) {
                $stmtLive = $conn->prepare(
                    "SELECT COUNT(*) AS c FROM raffle_viewers
                     WHERE raffle_id = ? AND last_seen >= (UTC_TIMESTAMP() - INTERVAL ? SECOND)"
                );
                $stmtLive->bind_param("ii", $id, $windowSec);
                $stmtLive->execute();
                $liveCount = (int)($stmtLive->get_result()->fetch_assoc()['c'] ?? 0);

                $stmtUniq = $conn->prepare(
                    "SELECT COUNT(DISTINCT user_id) AS c FROM raffle_viewers
                     WHERE raffle_id = ? AND user_id > 0"
                );
                $stmtUniq->bind_param("i", $id);
                $stmtUniq->execute();
                $uniqueViewers = (int)($stmtUniq->get_result()->fetch_assoc()['c'] ?? 0);
            }
            $participants = 0;
            $pStmt = $conn->prepare("SELECT COUNT(*) AS c FROM raffle_participants WHERE raffle_id = ? AND review_status <> 'expelled'");
            if ($pStmt) {
                $pStmt->bind_param("i", $id);
                $pStmt->execute();
                $participants = (int)($pStmt->get_result()->fetch_assoc()['c'] ?? 0);
            }
            $response = [
                'status' => 'success',
                'live' => $liveCount,
                'unique' => $uniqueViewers,
                'participants' => $participants,
                'window_seconds' => $windowSec
            ];
        }

        // --- SALA DE MANDO: RESUMEN (cabecera + datos curiosos) ---
        elseif ($action === 'cc_overview') {
            $id = (int)($_GET['id'] ?? 0);
            cc_ensure_whitelist($conn);

            // Datos del sorteo
            $rStmt = $conn->prepare("SELECT raffle_id, title, status, start_time, end_time, winners_count, burn_count, execution_mode, entry_cost, max_participants, current_pot, prize_config, requirements, server_seed_hash FROM raffles WHERE raffle_id = ?");
            $rStmt->bind_param("i", $id);
            $rStmt->execute();
            $raffle = $rStmt->get_result()->fetch_assoc();
            if (!$raffle) throw new Exception("Sorteo no encontrado.");
            $raffle['prize_config'] = json_decode($raffle['prize_config'] ?? '{}', true);
            $raffle['requirements'] = json_decode($raffle['requirements'] ?? '{}', true);

            // Métricas en vivo (mismas que live_metrics)
            $live = 0; $unique = 0;
            $tbl = $conn->query("SHOW TABLES LIKE 'raffle_viewers'");
            if ($tbl && $tbl->num_rows > 0) {
                $s1 = $conn->prepare("SELECT COUNT(*) c FROM raffle_viewers WHERE raffle_id = ? AND last_seen >= (UTC_TIMESTAMP() - INTERVAL 30 SECOND)");
                $s1->bind_param("i", $id); $s1->execute();
                $live = (int)($s1->get_result()->fetch_assoc()['c'] ?? 0);
                $s2 = $conn->prepare("SELECT COUNT(DISTINCT user_id) c FROM raffle_viewers WHERE raffle_id = ? AND user_id > 0");
                $s2->bind_param("i", $id); $s2->execute();
                $unique = (int)($s2->get_result()->fetch_assoc()['c'] ?? 0);
            }
            $pc = $conn->prepare("SELECT COUNT(*) c FROM raffle_participants WHERE raffle_id = ? AND review_status <> 'expelled'");
            $pc->bind_param("i", $id); $pc->execute();
            $participants = (int)($pc->get_result()->fetch_assoc()['c'] ?? 0);

            $wc = $conn->prepare("SELECT COUNT(*) c FROM raffle_whitelist WHERE raffle_id = ?");
            $wc->bind_param("i", $id); $wc->execute();
            $whitelistCount = (int)($wc->get_result()->fetch_assoc()['c'] ?? 0);

            // Ganadores Early-bird (Bananos)
            $ebWinners = [];
            $ebTbl = $conn->query("SHOW TABLES LIKE 'raffle_early_bird_rewards'");
            if ($ebTbl && $ebTbl->num_rows > 0) {
                $ebStmt = $conn->prepare(
                    "SELECT r.position, r.amount, r.status, r.mode, r.user_id,
                            COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name,
                            a.filename AS avatar
                     FROM raffle_early_bird_rewards r
                     JOIN users u ON r.user_id = u.id
                     LEFT JOIN avatars a ON u.avatar_id = a.id
                     WHERE r.raffle_id = ? ORDER BY r.position ASC LIMIT 50"
                );
                $ebStmt->bind_param("i", $id); $ebStmt->execute();
                $res = $ebStmt->get_result();
                while ($w = $res->fetch_assoc()) {
                    $ebWinners[] = [
                        'position' => (int)$w['position'],
                        'amount' => (float)$w['amount'],
                        'status' => $w['status'],
                        'mode' => $w['mode'],
                        'user_id' => (int)$w['user_id'],
                        'name' => $w['name'],
                        'avatar' => AvatarCatalog::filename($conn, $w['avatar'] ?? null),
                    ];
                }
            }

            // "Primera vez en un sorteo": participantes cuya ÚNICA participación
            // histórica es este sorteo.
            $newcomers = [];
            $ncStmt = $conn->prepare(
                "SELECT rp.user_id, rp.ticket_index,
                        COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name,
                        a.filename AS avatar
                 FROM raffle_participants rp
                 JOIN users u ON rp.user_id = u.id
                 LEFT JOIN avatars a ON u.avatar_id = a.id
                 WHERE rp.raffle_id = ?
                   AND (SELECT COUNT(*) FROM raffle_participants rp2 WHERE rp2.user_id = rp.user_id) = 1
                 ORDER BY rp.ticket_index ASC LIMIT 50"
            );
            $ncStmt->bind_param("i", $id); $ncStmt->execute();
            $res = $ncStmt->get_result();
            while ($n = $res->fetch_assoc()) {
                $newcomers[] = [
                    'user_id' => (int)$n['user_id'],
                    'ticket' => (int)$n['ticket_index'],
                    'name' => $n['name'],
                    'avatar' => AvatarCatalog::filename($conn, $n['avatar'] ?? null),
                ];
            }

            // Alerta de IP compartida (posible multicuenta): agrupamos a los
            // usuarios que comparten cada IP para mostrarlos juntos en una
            // tarjeta comparativa (no solo el conteo).
            $dupIps = [];
            $dupStmt = $conn->prepare(
                "SELECT rp.ip_address, rp.user_id, rp.ticket_index, rp.review_status,
                        COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name,
                        u.whatsapp_phone_e164 AS phone, u.is_verified,
                        a.filename AS avatar
                 FROM raffle_participants rp
                 JOIN users u ON rp.user_id = u.id
                 LEFT JOIN avatars a ON u.avatar_id = a.id
                 WHERE rp.raffle_id = ? AND rp.ip_address <> '' AND rp.ip_address <> '0.0.0.0'
                   AND rp.ip_address IN (
                       SELECT ip_address FROM raffle_participants
                       WHERE raffle_id = ? AND ip_address <> '' AND ip_address <> '0.0.0.0'
                       GROUP BY ip_address HAVING COUNT(DISTINCT user_id) > 1
                   )
                 ORDER BY rp.ip_address ASC, rp.ticket_index ASC"
            );
            $dupStmt->bind_param("ii", $id, $id); $dupStmt->execute();
            $res = $dupStmt->get_result();
            $dupGroups = [];
            while ($d = $res->fetch_assoc()) {
                $ip = $d['ip_address'];
                if (!isset($dupGroups[$ip])) $dupGroups[$ip] = ['ip' => $ip, 'count' => 0, 'members' => []];
                $dupGroups[$ip]['members'][] = [
                    'user_id' => (int)$d['user_id'],
                    'ticket' => (int)$d['ticket_index'],
                    'name' => $d['name'],
                    'phone' => $d['phone'] ?? '',
                    'avatar' => AvatarCatalog::filename($conn, $d['avatar'] ?? null),
                    'is_verified' => (int)$d['is_verified'],
                    'review_status' => $d['review_status'] ?: 'active',
                ];
            }
            foreach ($dupGroups as &$g) {
                $uids = array_unique(array_map(function ($m) { return $m['user_id']; }, $g['members']));
                $g['count'] = count($uids);
            }
            unset($g);
            // Ordenamos por número de cuentas (descendente).
            usort($dupGroups, function ($a, $b) { return $b['count'] - $a['count']; });
            $dupIps = array_slice(array_values($dupGroups), 0, 30);

            $response = [
                'status' => 'success',
                'raffle' => $raffle,
                'metrics' => ['live' => $live, 'unique' => $unique, 'participants' => $participants, 'whitelist' => $whitelistCount],
                'early_bird_winners' => $ebWinners,
                'newcomers' => $newcomers,
                'dup_ips' => $dupIps,
            ];
        }

        // --- SALA DE MANDO: PARTICIPANTES (lista + buscador) ---
        elseif ($action === 'cc_participants') {
            $id = (int)($_GET['id'] ?? 0);
            $q = trim((string)($_GET['q'] ?? ''));
            cc_ensure_whitelist($conn);
            rr_ensure_schema($conn);
            wt_ensure_schema($conn);

            $sql = "SELECT rp.user_id, rp.ticket_index, rp.joined_at, rp.ip_address, rp.entry_fee_paid, rp.review_status,
                           rp.pre_chosen_prize, rp.used_welcome_ticket,
                           u.game_username, u.public_display_name, u.public_code, u.is_verified, u.account_status,
                           u.whatsapp_phone_e164,
                           a.filename AS avatar,
                           rw.coin_choice AS gamble_coin_choice, rw.selected_animation_mode AS gamble_mode,
                           (SELECT COUNT(*) FROM raffle_strikes s WHERE s.user_id = rp.user_id) AS strikes,
                           (SELECT COUNT(*) FROM raffle_whitelist w WHERE w.raffle_id = rp.raffle_id AND w.user_id = rp.user_id) AS wl,
                           (SELECT COUNT(*) FROM raffle_participants rp2 WHERE rp2.user_id = rp.user_id) AS total_parts,
                           (SELECT COUNT(DISTINCT rp3.user_id) FROM raffle_participants rp3 WHERE rp3.raffle_id = rp.raffle_id AND rp3.ip_address = rp.ip_address AND rp3.ip_address <> '' AND rp3.ip_address <> '0.0.0.0') AS ip_shared,
                           eb.amount AS eb_amount, eb.position AS eb_position, eb.status AS eb_status
                    FROM raffle_participants rp
                    JOIN users u ON rp.user_id = u.id
                    LEFT JOIN avatars a ON u.avatar_id = a.id
                    LEFT JOIN raffle_winners rw ON rw.raffle_id = rp.raffle_id AND rw.user_id = rp.user_id
                    LEFT JOIN raffle_early_bird_rewards eb ON eb.raffle_id = rp.raffle_id AND eb.user_id = rp.user_id
                    WHERE rp.raffle_id = ?";
            $params = [$id]; $types = "i";
            if ($q !== '') {
                $sql .= " AND (u.game_username LIKE ? OR u.public_display_name LIKE ? OR u.public_code LIKE ? OR u.whatsapp_phone_e164 LIKE ?)";
                $like = '%' . $q . '%';
                array_push($params, $like, $like, $like, $like);
                $types .= "ssss";
            }
            $sql .= " ORDER BY rp.ticket_index ASC LIMIT 500";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $list = [];
            while ($p = $res->fetch_assoc()) {
                $list[] = [
                    'user_id' => (int)$p['user_id'],
                    'ticket' => (int)$p['ticket_index'],
                    'joined_at' => $p['joined_at'],
                    'ip' => $p['ip_address'],
                    'name' => $p['public_display_name'] ?: $p['game_username'],
                    'game_username' => $p['game_username'],
                    'public_code' => $p['public_code'],
                    'phone' => $p['whatsapp_phone_e164'] ?? '',
                    'avatar' => AvatarCatalog::filename($conn, $p['avatar'] ?? null),
                    'is_verified' => (int)$p['is_verified'],
                    'account_status' => $p['account_status'] ?: 'active',
                    'review_status' => $p['review_status'] ?: 'active',
                    'strikes' => (int)($p['strikes'] ?? 0),
                    'whitelisted' => ((int)$p['wl']) > 0,
                    'first_time' => ((int)$p['total_parts']) === 1,
                    'ip_shared' => (int)$p['ip_shared'],
                    'paid' => (float)$p['entry_fee_paid'] > 0,
                    'eb_amount' => $p['eb_amount'] !== null ? (float)$p['eb_amount'] : null,
                    'eb_position' => $p['eb_position'] !== null ? (int)$p['eb_position'] : null,
                    'eb_status' => $p['eb_status'],
                    // Pre-elección de premio (FASE 2) — PRIVADO: solo viaja en este
                    // endpoint admin, nunca en el polling público de otros usuarios.
                    'pre_chosen_prize' => $p['pre_chosen_prize'] ?? null,
                    'gamble_coin_choice' => $p['gamble_coin_choice'] ?? null,
                    'gamble_mode' => $p['gamble_mode'] ?? null,
                    'used_welcome_ticket' => ((int)($p['used_welcome_ticket'] ?? 0)) === 1,
                ];
            }
            $response = ['status' => 'success', 'participants' => $list];
        }

        // --- SALA DE MANDO: BÚSQUEDA GLOBAL DE USUARIOS ---
        elseif ($action === 'cc_user_search') {
            $q = trim((string)($_GET['q'] ?? ''));
            $rid = (int)($_GET['raffle_id'] ?? 0);
            cc_ensure_whitelist($conn);
            rp_ensure_schema($conn);
            $list = [];
            if ($q !== '') {
                $like = '%' . $q . '%';
                $sql = "SELECT u.id, u.game_username, u.public_display_name, u.public_code, u.whatsapp_phone_e164,
                               u.is_verified, u.account_status, a.filename AS avatar,
                               (SELECT COUNT(*) FROM pedidos p WHERE p.user_id = u.id AND p.estado = 'completado'" . wt_real_orders_sql('p') . ") AS purchases_count,
                               (SELECT COALESCE(SUM(p.total_pagar),0) FROM pedidos p WHERE p.user_id = u.id AND p.estado = 'completado'" . wt_real_orders_sql('p') . ") AS purchases_sum,
                               (SELECT COUNT(*) FROM raffle_whitelist w WHERE w.raffle_id = ? AND w.user_id = u.id) AS wl,
                               (SELECT COUNT(*) FROM raffle_invitations ri WHERE ri.raffle_id = ? AND ri.user_id = u.id) AS inv,
                               (SELECT COUNT(*) FROM raffle_participants rp WHERE rp.raffle_id = ? AND rp.user_id = u.id) AS is_part
                        FROM users u
                        LEFT JOIN avatars a ON u.avatar_id = a.id
                        WHERE u.game_username LIKE ? OR u.public_display_name LIKE ? OR u.public_code LIKE ? OR u.whatsapp_phone_e164 LIKE ? OR u.email LIKE ?
                        ORDER BY is_part DESC, purchases_count DESC, u.id DESC LIMIT 25";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiisssss", $rid, $rid, $rid, $like, $like, $like, $like, $like);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($u = $res->fetch_assoc()) {
                    $list[] = [
                        'user_id' => (int)$u['id'],
                        'name' => $u['public_display_name'] ?: $u['game_username'],
                        'game_username' => $u['game_username'],
                        'public_code' => $u['public_code'],
                        'phone' => $u['whatsapp_phone_e164'],
                        'avatar' => AvatarCatalog::filename($conn, $u['avatar'] ?? null),
                        'is_verified' => (int)$u['is_verified'],
                        'account_status' => $u['account_status'] ?: 'active',
                        'purchases_count' => (int)$u['purchases_count'],
                        'purchases_sum' => (float)$u['purchases_sum'],
                        'whitelisted' => ((int)$u['wl']) > 0,
                        'invited' => ((int)$u['inv']) > 0,
                        'is_participant' => ((int)$u['is_part']) > 0,
                    ];
                }
            }
            $response = ['status' => 'success', 'users' => $list];
        }

        // --- SALA DE MANDO: DETALLE DE USUARIO (IP, compras, multicuenta) ---
        elseif ($action === 'cc_user_detail') {
            $uid = (int)($_GET['user_id'] ?? 0);
            $rid = (int)($_GET['raffle_id'] ?? 0);
            cc_ensure_whitelist($conn);
            wt_ensure_schema($conn);

            $uStmt = $conn->prepare(
                "SELECT u.id, u.game_username, u.public_display_name, u.public_code, u.email,
                        u.whatsapp_phone_e164, u.is_verified, u.account_status, u.ban_reason, u.created_at,
                        a.filename AS avatar,
                        (SELECT COALESCE(balance,0) FROM coins c WHERE c.user_id = u.id) AS coins
                 FROM users u LEFT JOIN avatars a ON u.avatar_id = a.id WHERE u.id = ?"
            );
            $uStmt->bind_param("i", $uid); $uStmt->execute();
            $u = $uStmt->get_result()->fetch_assoc();
            if (!$u) throw new Exception("Usuario no encontrado.");

            // Compras
            $pStmt = $conn->prepare(
                "SELECT COUNT(*) cnt, COALESCE(SUM(total_pagar),0) sum, MAX(fecha_creacion) last
                 FROM pedidos WHERE user_id = ? AND estado = 'completado'" . wt_real_orders_sql()
            );
            $pStmt->bind_param("i", $uid); $pStmt->execute();
            $pAgg = $pStmt->get_result()->fetch_assoc();

            $recent = [];
            $rqStmt = $conn->prepare(
                "SELECT fecha_creacion, total_pagar, estado, nickname_fortnite_cliente
                 FROM pedidos WHERE user_id = ? ORDER BY fecha_creacion DESC LIMIT 8"
            );
            $rqStmt->bind_param("i", $uid); $rqStmt->execute();
            $res = $rqStmt->get_result();
            while ($o = $res->fetch_assoc()) {
                $recent[] = [
                    'date' => $o['fecha_creacion'],
                    'total' => (float)$o['total_pagar'],
                    'estado' => $o['estado'],
                    'nickname' => $o['nickname_fortnite_cliente'],
                ];
            }

            // IPs usadas en este sorteo + otras cuentas que comparten esas IPs.
            $ips = [];
            $sharedAccounts = [];
            if ($rid > 0) {
                $ipStmt = $conn->prepare("SELECT DISTINCT ip_address FROM raffle_participants WHERE raffle_id = ? AND user_id = ? AND ip_address <> ''");
                $ipStmt->bind_param("ii", $rid, $uid); $ipStmt->execute();
                $res = $ipStmt->get_result();
                while ($r = $res->fetch_assoc()) { if ($r['ip_address']) $ips[] = $r['ip_address']; }

                if (!empty($ips)) {
                    $placeholders = implode(',', array_fill(0, count($ips), '?'));
                    $types = 'i' . str_repeat('s', count($ips)) . 'i';
                    $args = array_merge([$rid], $ips, [$uid]);
                    $shStmt = $conn->prepare(
                        "SELECT DISTINCT rp.user_id, rp.ip_address,
                                COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name
                         FROM raffle_participants rp JOIN users u ON rp.user_id = u.id
                         WHERE rp.raffle_id = ? AND rp.ip_address IN ($placeholders) AND rp.user_id <> ?"
                    );
                    $shStmt->bind_param($types, ...$args);
                    $shStmt->execute();
                    $res = $shStmt->get_result();
                    while ($s = $res->fetch_assoc()) {
                        $sharedAccounts[] = ['user_id' => (int)$s['user_id'], 'name' => $s['name'], 'ip' => $s['ip_address']];
                    }
                }
            }

            // Flags whitelist / participante
            $wl = false; $isPart = false;
            if ($rid > 0) {
                $wq = $conn->prepare("SELECT 1 FROM raffle_whitelist WHERE raffle_id = ? AND user_id = ? LIMIT 1");
                $wq->bind_param("ii", $rid, $uid); $wq->execute();
                $wl = $wq->get_result()->num_rows > 0;
                $pq = $conn->prepare("SELECT 1 FROM raffle_participants WHERE raffle_id = ? AND user_id = ? LIMIT 1");
                $pq->bind_param("ii", $rid, $uid); $pq->execute();
                $isPart = $pq->get_result()->num_rows > 0;
            }

            // Ticket de Bienvenida: estado global (consumido / disponible) y si
            // fue usado precisamente en este sorteo.
            $wtStatus = wt_status($conn, $uid);
            $welcomeTicket = [
                'enabled'        => $wtStatus['enabled'],
                'available'      => $wtStatus['available'],
                'qualifies'      => $wtStatus['qualifies'],
                'used'           => $wtStatus['used'],
                'used_in_raffle' => $wtStatus['used_in_raffle'],
                'used_at'        => $wtStatus['used_at'],
                'used_here'      => ($rid > 0 && (int)$wtStatus['used_in_raffle'] === $rid),
                'granted'        => $wtStatus['granted'],
                'value'          => $wtStatus['value'],
                'threshold'      => $wtStatus['threshold'],
                'mode'           => $wtStatus['mode'],
            ];

            $response = [
                'status' => 'success',
                'user' => [
                    'user_id' => (int)$u['id'],
                    'name' => $u['public_display_name'] ?: $u['game_username'],
                    'game_username' => $u['game_username'],
                    'public_display_name' => $u['public_display_name'],
                    'public_code' => $u['public_code'],
                    'email' => $u['email'],
                    'phone' => $u['whatsapp_phone_e164'],
                    'avatar' => AvatarCatalog::filename($conn, $u['avatar'] ?? null),
                    'is_verified' => (int)$u['is_verified'],
                    'account_status' => $u['account_status'] ?: 'active',
                    'ban_reason' => $u['ban_reason'],
                    'created_at' => $u['created_at'],
                    'coins' => (float)$u['coins'],
                ],
                'purchases' => [
                    'count' => (int)$pAgg['cnt'],
                    'sum' => (float)$pAgg['sum'],
                    'last' => $pAgg['last'],
                    'recent' => $recent,
                ],
                'ips' => $ips,
                'shared_accounts' => $sharedAccounts,
                'whitelisted' => $wl,
                'is_participant' => $isPart,
                'welcome_ticket' => $welcomeTicket,
            ];
        }

        // --- TICKET DE BIENVENIDA: configuración (umbral, modo, alcance) ---
        elseif ($action === 'wt_settings') {
            wt_ensure_schema($conn);
            $s = wt_settings($conn);
            $usedRes = $conn->query("SELECT COUNT(*) AS c FROM raffle_welcome_tickets");
            $usedCount = $usedRes ? (int)($usedRes->fetch_assoc()['c'] ?? 0) : 0;
            $response = [
                'status' => 'success',
                'settings' => $s,
                'soles_per_100_pavos' => wt_soles_per_100_pavos($conn),
                'used_count' => $usedCount,
            ];
        }

        // --- PASE DE BIENVENIDA: dashboard comercial (KPIs, retención, tablas) ---
        elseif ($action === 'wt_dashboard') {
            wt_ensure_schema($conn);
            $settings = wt_settings($conn);
            $threshold = (float)$settings['min_value'];
            $rate = wt_soles_per_100_pavos($conn);
            $bulk = wt_bulk_qualifying($conn, $settings);
            $nowTs = time();

            // Consumos del pase (+ usuario, sorteo, si ganó ese sorteo).
            $used = [];
            $usedRes = $conn->query(
                "SELECT t.user_id, t.raffle_id, t.ticket_index, t.qualifying_value, t.threshold_value, t.value_mode, t.used_at,
                        u.game_username, u.public_display_name, u.public_code, u.is_verified, u.account_status, u.created_at AS user_created_at,
                        a.filename AS avatar,
                        r.title AS raffle_title, r.status AS raffle_status,
                        (SELECT COUNT(*) FROM raffle_winners w WHERE w.raffle_id = t.raffle_id AND w.user_id = t.user_id AND w.choice <> 'burned') AS won,
                        (SELECT rp.review_status FROM raffle_participants rp WHERE rp.raffle_id = t.raffle_id AND rp.user_id = t.user_id LIMIT 1) AS entry_status,
                        (SELECT g.created_at FROM raffle_welcome_grants g WHERE g.user_id = t.user_id LIMIT 1) AS grant_at
                 FROM raffle_welcome_tickets t
                 LEFT JOIN users u ON u.id = t.user_id
                 LEFT JOIN avatars a ON u.avatar_id = a.id
                 LEFT JOIN raffles r ON r.raffle_id = t.raffle_id
                 ORDER BY t.used_at DESC"
            );
            while ($usedRes && ($row = $usedRes->fetch_assoc())) $used[(int)$row['user_id']] = $row;
            $usedIds = array_keys($used);

            // Participaciones posteriores al consumo (otros sorteos, no expulsadas).
            $laterEntries = [];
            if ($usedIds) {
                $lpRes = $conn->query(
                    "SELECT rp.user_id, COUNT(*) AS entries, MIN(rp.joined_at) AS first_at
                     FROM raffle_participants rp
                     JOIN raffle_welcome_tickets t ON t.user_id = rp.user_id
                     WHERE rp.raffle_id <> t.raffle_id AND rp.joined_at > t.used_at
                       AND COALESCE(rp.review_status, 'active') <> 'expelled'
                     GROUP BY rp.user_id"
                );
                while ($lpRes && ($lp = $lpRes->fetch_assoc())) $laterEntries[(int)$lp['user_id']] = $lp;
            }
            // Los sorteos con requisitos reales se evalúan con el JSON (wt_raffle_requires_purchases).
            $reqCache = [];
            $raffleReqs = static function (mysqli $conn, int $rid) use (&$reqCache): bool {
                if (isset($reqCache[$rid])) return $reqCache[$rid];
                $st = $conn->prepare("SELECT requirements FROM raffles WHERE raffle_id = ?");
                $st->bind_param('i', $rid); $st->execute();
                $rr = $st->get_result()->fetch_assoc(); $st->close();
                $j = $rr ? json_decode((string)$rr['requirements'], true) : null;
                return $reqCache[$rid] = wt_raffle_requires_purchases(is_array($j) ? $j : null);
            };
            $laterStdEntries = [];
            if ($usedIds) {
                $lsRes = $conn->query(
                    "SELECT rp.user_id, rp.raffle_id
                     FROM raffle_participants rp
                     JOIN raffle_welcome_tickets t ON t.user_id = rp.user_id
                     WHERE rp.raffle_id <> t.raffle_id AND rp.joined_at > t.used_at
                       AND COALESCE(rp.review_status, 'active') <> 'expelled'"
                );
                while ($lsRes && ($ls = $lsRes->fetch_assoc())) {
                    if ($raffleReqs($conn, (int)$ls['raffle_id'])) {
                        $laterStdEntries[(int)$ls['user_id']] = ($laterStdEntries[(int)$ls['user_id']] ?? 0) + 1;
                    }
                }
            }

            // Usuarios con participación vigente (descalifica el pase salvo grant).
            $priorSet = [];
            $prRes = $conn->query("SELECT DISTINCT user_id FROM raffle_participants WHERE COALESCE(review_status, 'active') <> 'expelled'");
            while ($prRes && ($pr = $prRes->fetch_assoc())) $priorSet[(int)$pr['user_id']] = true;

            // Grants vigentes (para etiquetar en tablas).
            $grants = [];
            $gRes = $conn->query("SELECT g.user_id, g.granted_by_name, g.note, g.created_at FROM raffle_welcome_grants g WHERE g.revoked_at IS NULL");
            while ($gRes && ($g = $gRes->fetch_assoc())) $grants[(int)$g['user_id']] = $g;

            // Candidatos: califican (o grant) y todavía no consumieron.
            $availableIds = []; $blockedIds = []; $almostIds = [];
            foreach ($bulk as $uid => $q) {
                if (isset($used[$uid])) continue;
                if ($q['qualifies']) {
                    if ($q['granted'] || !isset($priorSet[$uid])) $availableIds[] = $uid;
                    else $blockedIds[] = $uid;
                } elseif ($threshold > 0 && $q['value'] >= $threshold * 0.5 && !isset($priorSet[$uid])) {
                    $almostIds[] = $uid;
                }
            }

            // Datos de usuario para disponibles / bloqueados / casi.
            $userInfo = [];
            $needIds = array_unique(array_merge($availableIds, $blockedIds, $almostIds));
            if ($needIds) {
                $idList = implode(',', array_map('intval', $needIds));
                $uRes = $conn->query(
                    "SELECT u.id, u.game_username, u.public_display_name, u.public_code, u.is_verified, u.account_status, u.created_at,
                            a.filename AS avatar
                     FROM users u LEFT JOIN avatars a ON u.avatar_id = a.id
                     WHERE u.id IN ($idList)"
                );
                while ($uRes && ($ur = $uRes->fetch_assoc())) $userInfo[(int)$ur['id']] = $ur;
            }
            $userCard = static function (int $uid, array $info): array {
                return [
                    'user_id' => $uid,
                    'name' => ($info['public_display_name'] ?? '') ?: ($info['game_username'] ?? ('#' . $uid)),
                    'game_username' => $info['game_username'] ?? '',
                    'public_code' => $info['public_code'] ?? '',
                    'avatar' => AvatarCatalog::filename($conn, $info['avatar'] ?? null),
                    'is_verified' => (int)($info['is_verified'] ?? 0),
                    'account_status' => ($info['account_status'] ?? '') ?: 'active',
                    'created_at' => $info['created_at'] ?? null,
                ];
            };

            $available = [];
            foreach ($availableIds as $uid) {
                $q = $bulk[$uid]; $info = $userInfo[$uid] ?? [];
                $qualifiedAt = $q['qualified_at'] ?? ($grants[$uid]['created_at'] ?? null);
                $available[] = $userCard($uid, $info) + [
                    'value' => $q['value'], 'native' => $q['native'], 'paid' => $q['paid'],
                    'orders' => $q['orders'], 'best_order' => $q['best_order'], 'spent_soles' => $q['spent_soles'],
                    'first_order_at' => $q['first_order_at'], 'last_order_at' => $q['last_order_at'],
                    'qualified_at' => $qualifiedAt,
                    'granted' => $q['granted'],
                    'grant' => isset($grants[$uid]) ? ['by' => $grants[$uid]['granted_by_name'], 'note' => $grants[$uid]['note'], 'at' => $grants[$uid]['created_at']] : null,
                    'days_waiting' => $qualifiedAt ? max(0, (int)floor(($nowTs - strtotime($qualifiedAt . ' UTC')) / 86400)) : null,
                ];
            }
            usort($available, static fn($a, $b) => strcmp((string)$b['qualified_at'], (string)$a['qualified_at']));

            $blocked = [];
            foreach ($blockedIds as $uid) {
                $q = $bulk[$uid]; $info = $userInfo[$uid] ?? [];
                $blocked[] = $userCard($uid, $info) + ['value' => $q['value'], 'orders' => $q['orders'], 'qualified_at' => $q['qualified_at']];
            }
            $almost = [];
            foreach ($almostIds as $uid) {
                $q = $bulk[$uid]; $info = $userInfo[$uid] ?? [];
                $almost[] = $userCard($uid, $info) + [
                    'value' => $q['value'], 'orders' => $q['orders'], 'last_order_at' => $q['last_order_at'],
                    'missing' => round(max(0, $threshold - $q['value']), 2),
                ];
            }
            usort($almost, static fn($a, $b) => $a['missing'] <=> $b['missing']);
            $almost = array_slice($almost, 0, 25);

            // Tabla de consumidos + retención.
            $consumed = [];
            $retBuy = 0; $retPlay = 0; $retPlayStd = 0; $retAny = 0; $retBoth = 0;
            $spentAfterTotal = 0.0; $ordersAfterTotal = 0; $daysToRebuy = []; $daysToUse = [];
            $usedByMonth = []; $rebuyByMonth = []; $byRaffle = []; $winners = 0;
            $fastest = null; $biggestAfter = null;
            foreach ($used as $uid => $row) {
                $q = $bulk[$uid] ?? null;
                $usedTs = strtotime($row['used_at'] . ' UTC');
                $ordersAfter = 0; $spentAfter = 0.0; $firstAfter = null; $lastAfter = null;
                if ($q) {
                    foreach ($q['order_dates'] as $od) {
                        if (strtotime($od['at'] . ' UTC') > $usedTs) {
                            $ordersAfter++; $spentAfter += $od['soles'];
                            if ($firstAfter === null) $firstAfter = $od['at'];
                            $lastAfter = $od['at'];
                        }
                    }
                    if ($q['qualified_at']) {
                        $d = ($usedTs - strtotime($q['qualified_at'] . ' UTC')) / 86400;
                        if ($d >= 0) $daysToUse[] = $d;
                    }
                }
                $le = $laterEntries[$uid] ?? null;
                $entriesAfter = $le ? (int)$le['entries'] : 0;
                $stdAfter = $laterStdEntries[$uid] ?? 0;
                $bought = $ordersAfter > 0; $played = $entriesAfter > 0;
                if ($bought) { $retBuy++; $spentAfterTotal += $spentAfter; $ordersAfterTotal += $ordersAfter; }
                if ($played) $retPlay++;
                if ($stdAfter > 0) $retPlayStd++;
                if ($bought || $played) $retAny++;
                if ($bought && $played) $retBoth++;
                if ((int)$row['won'] > 0) $winners++;
                $daysRebuy = null;
                if ($firstAfter) {
                    $daysRebuy = round((strtotime($firstAfter . ' UTC') - $usedTs) / 86400, 1);
                    $daysToRebuy[] = $daysRebuy;
                    if ($fastest === null || $daysRebuy < $fastest['days']) $fastest = ['user_id' => $uid, 'days' => $daysRebuy];
                }
                if ($bought && ($biggestAfter === null || $spentAfter > $biggestAfter['soles'])) $biggestAfter = ['user_id' => $uid, 'soles' => round($spentAfter, 2)];

                $month = substr((string)$row['used_at'], 0, 7);
                $usedByMonth[$month] = ($usedByMonth[$month] ?? 0) + 1;
                if ($bought) $rebuyByMonth[$month] = ($rebuyByMonth[$month] ?? 0) + 1;
                $rid = (int)$row['raffle_id'];
                if (!isset($byRaffle[$rid])) $byRaffle[$rid] = ['raffle_id' => $rid, 'title' => $row['raffle_title'] ?: ('Sorteo #' . $rid), 'status' => $row['raffle_status'], 'passes' => 0, 'rebuy' => 0, 'replay' => 0];
                $byRaffle[$rid]['passes']++;
                if ($bought) $byRaffle[$rid]['rebuy']++;
                if ($played) $byRaffle[$rid]['replay']++;

                $state = 'inactive';
                if ($bought && $played) $state = 'loyal';
                elseif ($bought) $state = 'rebuy';
                elseif ($played) $state = 'replay';
                if (($row['account_status'] ?? 'active') === 'banned') $state = 'banned';

                $consumed[] = $userCard($uid, $row + ['created_at' => $row['user_created_at'] ?? null]) + [
                    'used_at' => $row['used_at'],
                    'raffle_id' => $rid,
                    'raffle_title' => $row['raffle_title'] ?: ('Sorteo #' . $rid),
                    'raffle_status' => $row['raffle_status'],
                    'ticket_index' => (int)$row['ticket_index'],
                    'entry_status' => $row['entry_status'] ?: 'active',
                    'won' => (int)$row['won'] > 0,
                    'qualifying_value' => (float)$row['qualifying_value'],
                    'threshold_value' => (float)$row['threshold_value'],
                    'value_mode' => $row['value_mode'],
                    'granted' => $row['grant_at'] !== null,
                    'orders_before' => $q ? max(0, $q['orders'] - $ordersAfter) : 0,
                    'orders_after' => $ordersAfter,
                    'spent_after_soles' => round($spentAfter, 2),
                    'first_order_after' => $firstAfter,
                    'last_order_after' => $lastAfter,
                    'days_to_rebuy' => $daysRebuy,
                    'entries_after' => $entriesAfter,
                    'entries_after_std' => $stdAfter,
                    'first_entry_after' => $le ? $le['first_at'] : null,
                    'state' => $state,
                ];
            }
            ksort($usedByMonth);

            // Serie mensual (últimos 12 meses): pases usados, recompras y nuevos calificados.
            $qualifiedByMonth = [];
            foreach ($bulk as $uid => $q) {
                if ($q['qualified_at']) {
                    $m = substr($q['qualified_at'], 0, 7);
                    $qualifiedByMonth[$m] = ($qualifiedByMonth[$m] ?? 0) + 1;
                }
            }
            $months = [];
            for ($i = 11; $i >= 0; $i--) {
                $m = gmdate('Y-m', strtotime("first day of -$i month", $nowTs));
                $months[] = ['month' => $m, 'qualified' => $qualifiedByMonth[$m] ?? 0, 'used' => $usedByMonth[$m] ?? 0, 'rebuy' => $rebuyByMonth[$m] ?? 0];
            }
            usort($byRaffle, static fn($a, $b) => $b['passes'] <=> $a['passes'] ?: $b['raffle_id'] <=> $a['raffle_id']);

            $usedCount = count($used);
            $availableCount = count($available);
            $issued = $usedCount + $availableCount;
            $pct = static fn(int $n, int $d): float => $d > 0 ? round($n * 100 / $d, 1) : 0.0;
            $avg = static fn(array $xs): ?float => $xs ? round(array_sum($xs) / count($xs), 1) : null;
            $nameOf = static function (?int $uid) use ($consumed): ?string {
                if ($uid === null) return null;
                foreach ($consumed as $c) if ($c['user_id'] === $uid) return $c['name'];
                return null;
            };

            $response = [
                'status' => 'success',
                'generated_at' => gmdate('c'),
                'settings' => $settings,
                'soles_per_100_pavos' => $rate,
                'kpis' => [
                    'issued' => $issued,
                    'used' => $usedCount,
                    'available' => $availableCount,
                    'granted_active' => count($grants),
                    'granted_used' => count(array_filter($consumed, static fn($c) => $c['granted'])),
                    'blocked_prior' => count($blocked),
                    'almost' => count($almostIds),
                    'usage_rate' => $pct($usedCount, $issued),
                    'winners' => $winners,
                    'retention' => [
                        'rebuy' => $retBuy, 'rebuy_rate' => $pct($retBuy, $usedCount),
                        'replay' => $retPlay, 'replay_rate' => $pct($retPlay, $usedCount),
                        'replay_std' => $retPlayStd, 'replay_std_rate' => $pct($retPlayStd, $usedCount),
                        'any' => $retAny, 'any_rate' => $pct($retAny, $usedCount),
                        'both' => $retBoth, 'both_rate' => $pct($retBoth, $usedCount),
                        'inactive' => $usedCount - $retAny,
                        'orders_after' => $ordersAfterTotal,
                        'spent_after_soles' => round($spentAfterTotal, 2),
                        'avg_days_to_rebuy' => $avg($daysToRebuy),
                        'avg_days_to_use' => $avg($daysToUse),
                    ],
                ],
                'funnel' => [
                    ['key' => 'qualified', 'label' => 'Calificaron', 'value' => $issued],
                    ['key' => 'used', 'label' => 'Usaron el pase', 'value' => $usedCount],
                    ['key' => 'active', 'label' => 'Volvieron (compra o sorteo)', 'value' => $retAny],
                    ['key' => 'rebuy', 'label' => 'Volvieron a comprar', 'value' => $retBuy],
                    ['key' => 'loyal', 'label' => 'Compraron y jugaron', 'value' => $retBoth],
                ],
                'months' => $months,
                'by_raffle' => array_slice($byRaffle, 0, 8),
                'curiosities' => [
                    'fastest_rebuy' => $fastest ? $fastest + ['name' => $nameOf($fastest['user_id'])] : null,
                    'biggest_after' => $biggestAfter ? $biggestAfter + ['name' => $nameOf($biggestAfter['user_id'])] : null,
                    'avg_qualifying_value' => $consumed ? round(array_sum(array_column($consumed, 'qualifying_value')) / count($consumed), 0) : null,
                    'avg_spent_after_soles' => $retBuy > 0 ? round($spentAfterTotal / $retBuy, 2) : null,
                ],
                'available' => $available,
                'consumed' => $consumed,
                'blocked' => array_slice($blocked, 0, 50),
                'almost' => $almost,
            ];
        }

        // --- PASE DE BIENVENIDA: buscar usuario por username para asignación manual ---
        elseif ($action === 'wt_user_search') {
            $q = trim((string)($_GET['q'] ?? ''));
            wt_ensure_schema($conn);
            $list = [];
            if ($q !== '') {
                $like = '%' . $q . '%';
                $stmt = $conn->prepare(
                    "SELECT u.id, u.game_username, u.public_display_name, u.public_code, u.is_verified, u.account_status, u.created_at,
                            a.filename AS avatar
                     FROM users u LEFT JOIN avatars a ON u.avatar_id = a.id
                     WHERE u.game_username LIKE ? OR u.public_display_name LIKE ? OR u.public_code LIKE ?
                     ORDER BY (u.game_username = ?) DESC, u.id DESC LIMIT 8"
                );
                $stmt->bind_param('ssss', $like, $like, $like, $q);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($u = $res->fetch_assoc()) {
                    $uid = (int)$u['id'];
                    $st = wt_status($conn, $uid);
                    $grant = wt_grant_row($conn, $uid);
                    $list[] = [
                        'user_id' => $uid,
                        'name' => $u['public_display_name'] ?: $u['game_username'],
                        'game_username' => $u['game_username'],
                        'public_code' => $u['public_code'],
                        'avatar' => AvatarCatalog::filename($conn, $u['avatar'] ?? null),
                        'is_verified' => (int)$u['is_verified'],
                        'account_status' => $u['account_status'] ?: 'active',
                        'created_at' => $u['created_at'],
                        'welcome' => [
                            'available' => $st['available'], 'qualifies' => $st['qualifies'], 'first_raffle' => $st['first_raffle'],
                            'used' => $st['used'], 'used_in_raffle' => $st['used_in_raffle'], 'used_at' => $st['used_at'],
                            'granted' => $st['granted'], 'reason' => $st['reason'], 'value' => $st['value'], 'threshold' => $st['threshold'],
                        ],
                        'grant' => $grant ? ['by' => $grant['granted_by_name'], 'note' => $grant['note'], 'at' => $grant['created_at']] : null,
                    ];
                }
                $stmt->close();
            }
            $response = ['status' => 'success', 'users' => $list];
        }

        // --- REVISIÓN DIFERIDA: sorteos recientes (para el selector) ---
        elseif ($action === 'cc_recent_raffles') {
            $res = $conn->query(
                "SELECT raffle_id, title, status, start_time, end_time
                 FROM raffles ORDER BY raffle_id DESC LIMIT 30"
            );
            $rows = [];
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'raffle_id' => (int)$r['raffle_id'],
                        'title'     => $r['title'],
                        'status'    => $r['status'],
                        'start_time' => $r['start_time'],
                        'end_time'  => $r['end_time'],
                    ];
                }
            }
            $response = ['status' => 'success', 'raffles' => $rows];
        }

        // --- REVISIÓN DIFERIDA: declarados (compra por chat) de un sorteo ---
        // Si no se pasa id, toma el último sorteo. Para cada usuario que entró
        // "Bajo Revisión" trae sus compras web 2026 + sus compras manuales ya
        // registradas, para auditar con calma tras el sorteo.
        elseif ($action === 'cc_deferred_list') {
            $rid = (int)($_GET['id'] ?? 0);
            $minDate = trim((string)($_GET['min_date'] ?? '')); // opcional, para el conteo web
            if ($rid <= 0) {
                $last = $conn->query("SELECT raffle_id FROM raffles ORDER BY raffle_id DESC LIMIT 1");
                if ($last && ($lr = $last->fetch_assoc())) $rid = (int)$lr['raffle_id'];
            }

            $raffle = null;
            $list = [];
            if ($rid > 0) {
                $rfStmt = $conn->prepare("SELECT raffle_id, title, status, start_time, end_time, requirements FROM raffles WHERE raffle_id = ?");
                $rfStmt->bind_param("i", $rid); $rfStmt->execute();
                $rf = $rfStmt->get_result()->fetch_assoc();
                if ($rf) {
                    $reqs = json_decode($rf['requirements'] ?? '{}', true) ?: [];
                    if ($minDate === '' && !empty($reqs['min_orders_date'])) $minDate = $reqs['min_orders_date'];
                    $raffle = [
                        'raffle_id' => (int)$rf['raffle_id'],
                        'title'     => $rf['title'],
                        'status'    => $rf['status'],
                        'start_time' => $rf['start_time'],
                        'end_time'  => $rf['end_time'],
                        'min_date'  => $minDate,
                    ];

                    $dStmt = $conn->prepare(
                        "SELECT rp.user_id, rp.ticket_index, rp.review_status, rp.joined_at, rp.entry_fee_paid,
                                u.game_username, u.public_display_name, u.public_code, u.is_verified, u.account_status,
                                a.filename AS avatar,
                                (SELECT COUNT(*) FROM raffle_strikes s WHERE s.user_id = rp.user_id) AS strikes
                         FROM raffle_participants rp
                         JOIN users u ON rp.user_id = u.id
                         LEFT JOIN avatars a ON u.avatar_id = a.id
                         WHERE rp.raffle_id = ? AND rp.declared = 1
                         ORDER BY rp.joined_at ASC"
                    );
                    $dStmt->bind_param("i", $rid); $dStmt->execute();
                    $dRes = $dStmt->get_result();
                    while ($p = $dRes->fetch_assoc()) {
                        $uid = (int)$p['user_id'];

                        // Compras web (date-aware con la regla del sorteo).
                        if ($minDate !== '') {
                            $wq = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(total_pagar),0) s FROM pedidos WHERE user_id = ? AND estado = 'completado'" . wt_real_orders_sql() . " AND fecha_creacion >= ?");
                            $wq->bind_param("is", $uid, $minDate);
                        } else {
                            $wq = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(total_pagar),0) s FROM pedidos WHERE user_id = ? AND estado = 'completado'" . wt_real_orders_sql());
                            $wq->bind_param("i", $uid);
                        }
                        $wq->execute();
                        $w = $wq->get_result()->fetch_assoc();

                        $mp = rr_manual_purchase_stats($conn, $uid, $minDate !== '' ? $minDate : null);

                        $list[] = [
                            'user_id'        => $uid,
                            'ticket'         => (int)$p['ticket_index'],
                            'name'           => $p['public_display_name'] ?: $p['game_username'],
                            'game_username'  => $p['game_username'],
                            'public_code'    => $p['public_code'],
                            'avatar'         => AvatarCatalog::filename($conn, $p['avatar'] ?? null),
                            'is_verified'    => (int)$p['is_verified'],
                            'account_status' => $p['account_status'] ?: 'active',
                            'review_status'  => $p['review_status'] ?: 'active',
                            'strikes'        => (int)$p['strikes'],
                            'joined_at'      => $p['joined_at'],
                            'paid'           => (float)$p['entry_fee_paid'] > 0,
                            'web_orders'     => (int)$w['c'],
                            'web_spent'      => (float)$w['s'],
                            'manual_count'   => $mp['count'],
                            'manual_sum'     => $mp['sum'],
                        ];
                    }
                }
            }
            $response = ['status' => 'success', 'raffle' => $raffle, 'participants' => $list];
        }

        // --- COMPRAS MANUALES: historial de un usuario ---
        elseif ($action === 'mp_list') {
            $uid = (int)($_GET['user_id'] ?? 0);
            $rows = [];
            if ($uid > 0) {
                $st = $conn->prepare(
                    "SELECT mp.id, mp.purchase_date, mp.amount, mp.channel, mp.note, mp.via,
                            mp.subcategory, mp.created_at, adm.game_username AS admin_name
                     FROM raffle_manual_purchases mp
                     LEFT JOIN users adm ON adm.id = mp.approved_by
                     WHERE mp.user_id = ? ORDER BY mp.purchase_date DESC, mp.id DESC LIMIT 50"
                );
                $st->bind_param("i", $uid); $st->execute();
                $r = $st->get_result();
                while ($m = $r->fetch_assoc()) {
                    $rows[] = [
                        'id'            => (int)$m['id'],
                        'purchase_date' => $m['purchase_date'],
                        'amount'        => (float)$m['amount'],
                        'channel'       => $m['channel'],
                        'note'          => $m['note'],
                        'via'           => $m['via'],
                        'subcategory'   => $m['subcategory'],
                        'admin_name'    => $m['admin_name'],
                        'created_at'    => $m['created_at'],
                    ];
                }
            }
            $response = ['status' => 'success', 'purchases' => $rows];
        }

        // --- COMPRAS MANUALES: ficha completa de un usuario (modal de trabajo) ---
        // Devuelve datos del usuario + sus pedidos web completados (detalle) +
        // sus compras manuales, para anotar varias compras sin perder contexto
        // y detectar posibles duplicados por fecha/monto.
        elseif ($action === 'mp_user_overview') {
            $uid = (int)($_GET['user_id'] ?? 0);
            $minDate = trim((string)($_GET['min_date'] ?? ''));
            if ($uid <= 0) throw new Exception("Usuario inválido.");

            $uStmt = $conn->prepare(
                "SELECT u.id, u.game_username, u.public_display_name, u.public_code,
                        u.whatsapp_phone_e164, u.is_verified, u.account_status, a.filename AS avatar
                 FROM users u LEFT JOIN avatars a ON u.avatar_id = a.id WHERE u.id = ?"
            );
            $uStmt->bind_param("i", $uid); $uStmt->execute();
            $u = $uStmt->get_result()->fetch_assoc();
            if (!$u) throw new Exception("Usuario no encontrado.");

            // Pedidos web COMPLETADOS con detalle (para comparar contra manuales).
            $webOrders = [];
            $oStmt = $conn->prepare(
                "SELECT id, codigo_pedido, fecha_creacion, fecha_completado, total_pagar
                 FROM pedidos WHERE user_id = ? AND estado = 'completado'" . wt_real_orders_sql() . "
                 ORDER BY fecha_creacion DESC LIMIT 60"
            );
            $oStmt->bind_param("i", $uid); $oStmt->execute();
            $oRes = $oStmt->get_result();
            while ($o = $oRes->fetch_assoc()) {
                $webOrders[] = [
                    'id'              => (int)$o['id'],
                    'codigo_pedido'   => $o['codigo_pedido'],
                    'fecha_creacion'  => $o['fecha_creacion'],
                    'fecha_completado' => $o['fecha_completado'],
                    'total'           => (float)$o['total_pagar'],
                ];
            }

            // Compras manuales ya registradas.
            $manual = [];
            $mStmt = $conn->prepare(
                "SELECT mp.id, mp.purchase_date, mp.amount, mp.channel, mp.note, mp.via,
                        mp.subcategory, mp.created_at, adm.game_username AS admin_name
                 FROM raffle_manual_purchases mp
                 LEFT JOIN users adm ON adm.id = mp.approved_by
                 WHERE mp.user_id = ? ORDER BY mp.purchase_date DESC, mp.id DESC LIMIT 60"
            );
            $mStmt->bind_param("i", $uid); $mStmt->execute();
            $mRes = $mStmt->get_result();
            while ($m = $mRes->fetch_assoc()) {
                $manual[] = [
                    'id'            => (int)$m['id'],
                    'purchase_date' => $m['purchase_date'],
                    'amount'        => (float)$m['amount'],
                    'channel'       => $m['channel'],
                    'note'          => $m['note'],
                    'via'           => $m['via'],
                    'subcategory'   => $m['subcategory'],
                    'admin_name'    => $m['admin_name'],
                    'created_at'    => $m['created_at'],
                ];
            }

            // Totales válidos según la regla de fecha del sorteo (si aplica).
            if ($minDate !== '') {
                $wq = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(total_pagar),0) s FROM pedidos WHERE user_id = ? AND estado = 'completado'" . wt_real_orders_sql() . " AND fecha_creacion >= ?");
                $wq->bind_param("is", $uid, $minDate);
            } else {
                $wq = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(total_pagar),0) s FROM pedidos WHERE user_id = ? AND estado = 'completado'" . wt_real_orders_sql());
                $wq->bind_param("i", $uid);
            }
            $wq->execute();
            $w = $wq->get_result()->fetch_assoc();
            $mp = rr_manual_purchase_stats($conn, $uid, $minDate !== '' ? $minDate : null);

            $response = [
                'status' => 'success',
                'user' => [
                    'user_id'        => (int)$u['id'],
                    'name'           => $u['public_display_name'] ?: $u['game_username'],
                    'game_username'  => $u['game_username'],
                    'public_code'    => $u['public_code'],
                    'phone'          => $u['whatsapp_phone_e164'],
                    'avatar'         => AvatarCatalog::filename($conn, $u['avatar'] ?? null),
                    'is_verified'    => (int)$u['is_verified'],
                    'account_status' => $u['account_status'] ?: 'active',
                ],
                'web_orders' => $webOrders,
                'manual_purchases' => $manual,
                'totals' => [
                    'web_orders'   => (int)$w['c'],
                    'web_spent'    => (float)$w['s'],
                    'manual_count' => $mp['count'],
                    'manual_sum'   => $mp['sum'],
                    'min_date'     => $minDate,
                ],
            ];
        }

        // --- GESTIÓN DE BANEOS: LISTA (activos + historial) ---
        // Lectura pura: vive en la rama GET porque el panel la consulta con $.getJSON.
        elseif ($action === 'cc_bans_list') {
            rb_ensure_schema($conn);
            $activeCond = rb_active_condition_sql('b');
            $sql = "SELECT b.*, ($activeCond) AS is_active,
                           u.public_display_name, u.game_username, u.public_code,
                           u.whatsapp_phone_e164, a.filename AS avatar,
                           adm.game_username AS admin_name
                    FROM raffle_bans b
                    JOIN users u ON u.id = b.user_id
                    LEFT JOIN avatars a ON u.avatar_id = a.id
                    LEFT JOIN users adm ON adm.id = b.created_by
                    ORDER BY b.id DESC LIMIT 300";
            $res = $conn->query($sql);
            $active = []; $history = [];
            while ($res && ($b = $res->fetch_assoc())) {
                $item = [
                    'id' => (int)$b['id'],
                    'user_id' => (int)$b['user_id'],
                    'name' => ($b['public_display_name'] ?: $b['game_username']) ?: ('Usuario #' . $b['user_id']),
                    'public_code' => $b['public_code'] ?? '',
                    'avatar' => AvatarCatalog::filename($conn, $b['avatar'] ?? null),
                    'phone' => $b['whatsapp_phone_e164'] ?? '',
                    'ban_type' => $b['ban_type'],
                    'raffles_remaining' => $b['raffles_remaining'] !== null ? (int)$b['raffles_remaining'] : null,
                    'expires_at' => $b['expires_at'],
                    'reason' => $b['reason'],
                    'ip' => $b['ip_snapshot'],
                    'fingerprint' => $b['fingerprint_snapshot'],
                    'created_by' => $b['admin_name'] ?: ('#' . (int)$b['created_by']),
                    'created_at' => $b['created_at'],
                    'lifted_at' => $b['lifted_at'],
                ];
                if ((int)$b['is_active'] === 1) $active[] = $item; else $history[] = $item;
            }
            $response = ['status' => 'success', 'active' => $active, 'history' => $history];
        }

        // --- BANEOS Y EXPULSADOS: RANKING DE EXPULSIONES + HISTORIAL ---
        // Lectura pura para la vista baneos_expulsados.php: usuarios ordenados por
        // número de expulsiones (raffle_strikes), con su baneo activo si lo tienen.
        elseif ($action === 'cc_strikes_overview') {
            rb_ensure_schema($conn);
            rr_ensure_schema($conn);
            $activeCond = rb_active_condition_sql('b');
            $q = trim((string)($_GET['q'] ?? ''));
            $minStrikes = max(1, (int)($_GET['min_strikes'] ?? 1));
            $limit = min(500, max(10, (int)($_GET['limit'] ?? 200)));
            $like = '%' . $q . '%';

            $sql = "SELECT s.user_id, COUNT(*) AS strikes, MAX(s.created_at) AS last_strike,
                           COUNT(DISTINCT s.raffle_id) AS raffles_hit,
                           u.public_display_name, u.game_username, u.public_code, u.whatsapp_phone_e164,
                           u.is_verified, u.account_status, a.filename AS avatar,
                           (SELECT COUNT(*) FROM raffle_participants rp WHERE rp.user_id = s.user_id) AS participations,
                           (SELECT COUNT(*) FROM raffle_bans b WHERE b.user_id = s.user_id) AS bans_total,
                           (SELECT b.id FROM raffle_bans b WHERE b.user_id = s.user_id AND ($activeCond) ORDER BY b.id DESC LIMIT 1) AS active_ban_id,
                           (SELECT b.ban_type FROM raffle_bans b WHERE b.user_id = s.user_id AND ($activeCond) ORDER BY b.id DESC LIMIT 1) AS active_ban_type,
                           (SELECT b.raffles_remaining FROM raffle_bans b WHERE b.user_id = s.user_id AND ($activeCond) ORDER BY b.id DESC LIMIT 1) AS active_ban_remaining,
                           (SELECT b.expires_at FROM raffle_bans b WHERE b.user_id = s.user_id AND ($activeCond) ORDER BY b.id DESC LIMIT 1) AS active_ban_expires
                    FROM raffle_strikes s
                    JOIN users u ON u.id = s.user_id
                    LEFT JOIN avatars a ON u.avatar_id = a.id
                    WHERE (? = '' OR u.game_username LIKE ? OR u.public_display_name LIKE ? OR u.public_code LIKE ? OR u.whatsapp_phone_e164 LIKE ?)
                    GROUP BY s.user_id, u.id, a.id
                    HAVING strikes >= ?
                    ORDER BY strikes DESC, last_strike DESC
                    LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssii", $q, $like, $like, $like, $like, $minStrikes, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            $ranking = [];
            while ($r = $res->fetch_assoc()) {
                $ranking[] = [
                    'user_id' => (int)$r['user_id'],
                    'name' => ($r['public_display_name'] ?: $r['game_username']) ?: ('Usuario #' . $r['user_id']),
                    'game_username' => $r['game_username'],
                    'public_code' => $r['public_code'] ?? '',
                    'phone' => $r['whatsapp_phone_e164'] ?? '',
                    'avatar' => AvatarCatalog::filename($conn, $r['avatar'] ?? null),
                    'is_verified' => (int)$r['is_verified'],
                    'account_status' => $r['account_status'] ?: 'active',
                    'strikes' => (int)$r['strikes'],
                    'raffles_hit' => (int)$r['raffles_hit'],
                    'participations' => (int)$r['participations'],
                    'last_strike' => $r['last_strike'],
                    'bans_total' => (int)$r['bans_total'],
                    'active_ban_id' => $r['active_ban_id'] !== null ? (int)$r['active_ban_id'] : null,
                    'active_ban_type' => $r['active_ban_type'],
                    'active_ban_remaining' => $r['active_ban_remaining'] !== null ? (int)$r['active_ban_remaining'] : null,
                    'active_ban_expires' => $r['active_ban_expires'],
                ];
            }
            $stmt->close();

            // Historial cronológico de expulsiones (últimas 300)
            $hist = [];
            $hres = $conn->query("SELECT s.id, s.user_id, s.raffle_id, s.reason, s.created_at, s.created_by,
                                         u.public_display_name, u.game_username, a.filename AS avatar,
                                         r.title AS raffle_title, adm.game_username AS admin_name
                                  FROM raffle_strikes s
                                  JOIN users u ON u.id = s.user_id
                                  LEFT JOIN avatars a ON u.avatar_id = a.id
                                  LEFT JOIN raffles r ON r.raffle_id = s.raffle_id
                                  LEFT JOIN users adm ON adm.id = s.created_by
                                  ORDER BY s.id DESC LIMIT 300");
            while ($hres && ($h = $hres->fetch_assoc())) {
                $hist[] = [
                    'id' => (int)$h['id'],
                    'user_id' => (int)$h['user_id'],
                    'name' => ($h['public_display_name'] ?: $h['game_username']) ?: ('Usuario #' . $h['user_id']),
                    'avatar' => AvatarCatalog::filename($conn, $h['avatar'] ?? null),
                    'raffle_id' => (int)$h['raffle_id'],
                    'raffle_title' => $h['raffle_title'] ?? '',
                    'reason' => $h['reason'],
                    'created_by' => $h['admin_name'] ?: ((int)$h['created_by'] > 0 ? '#' . (int)$h['created_by'] : 'sistema'),
                    'created_at' => $h['created_at'],
                ];
            }

            $kpi = $conn->query("SELECT COUNT(*) AS strikes_total, COUNT(DISTINCT user_id) AS users_expelled,
                                        SUM(created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS strikes_30d
                                 FROM raffle_strikes")->fetch_assoc();
            $bansActive = (int)$conn->query("SELECT COUNT(*) AS c FROM raffle_bans b WHERE $activeCond")->fetch_assoc()['c'];

            $response = ['status' => 'success', 'ranking' => $ranking, 'history' => $hist, 'kpi' => [
                'strikes_total' => (int)($kpi['strikes_total'] ?? 0),
                'users_expelled' => (int)($kpi['users_expelled'] ?? 0),
                'strikes_30d' => (int)($kpi['strikes_30d'] ?? 0),
                'bans_active' => $bansActive,
                'repeat_offenders' => count(array_filter($ranking, static fn($r) => $r['strikes'] >= 2)),
            ]];
        }
    }

    elseif ($method === 'POST') {
        $conn->begin_transaction();
        $action = $_POST['action'] ?? '';

        // --- TICKET DE BIENVENIDA: guardar configuración (umbral, modo, alcance) ---
        if ($action === 'wt_save_settings') {
            $enabled  = (isset($_POST['enabled']) && ((string)$_POST['enabled'] === '1' || (string)$_POST['enabled'] === 'true')) ? '1' : '0';
            $minValue = isset($_POST['min_value']) ? (float)str_replace(',', '.', (string)$_POST['min_value']) : WT_DEFAULT_MIN_VALUE;
            if ($minValue <= 0) throw new Exception('El umbral debe ser mayor que 0.');
            $mode  = in_array((string)($_POST['value_mode'] ?? ''), ['max', 'native', 'paid', 'min'], true) ? (string)$_POST['value_mode'] : 'max';
            $scope = in_array((string)($_POST['scope'] ?? ''), ['accumulated', 'single_order'], true) ? (string)$_POST['scope'] : 'accumulated';
            $includeManual = (isset($_POST['include_manual']) && ((string)$_POST['include_manual'] === '1' || (string)$_POST['include_manual'] === 'true')) ? '1' : '0';
            $pairs = [
                WT_SETTING_ENABLED        => $enabled,
                WT_SETTING_MIN_VALUE      => (string)$minValue,
                WT_SETTING_VALUE_MODE     => $mode,
                WT_SETTING_SCOPE          => $scope,
                WT_SETTING_INCLUDE_MANUAL => $includeManual,
            ];
            $up = $conn->prepare("INSERT INTO site_settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
            foreach ($pairs as $k => $v) {
                $up->bind_param("ss", $k, $v);
                $up->execute();
            }
            $up->close();
            $response = ['status' => 'success', 'settings' => wt_settings($conn)];
        }

        // --- PASE DE BIENVENIDA: otorgar manualmente (compra por chat) ---
        elseif ($action === 'wt_grant') {
            wt_ensure_schema($conn);
            $username = trim((string)($_POST['username'] ?? ''));
            $uid = (int)($_POST['user_id'] ?? 0);
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
            if ($username === '' && $uid <= 0) throw new Exception('Indica el username del usuario.');

            // El username se resuelve SIEMPRE en servidor; el user_id solo desambigua.
            if ($username !== '') {
                $uq = $conn->prepare("SELECT id, game_username, public_display_name FROM users WHERE game_username = ? LIMIT 1");
                $uq->bind_param('s', $username);
            } else {
                $uq = $conn->prepare("SELECT id, game_username, public_display_name FROM users WHERE id = ? LIMIT 1");
                $uq->bind_param('i', $uid);
            }
            $uq->execute();
            $target = $uq->get_result()->fetch_assoc();
            $uq->close();
            if (!$target) throw new Exception('Usuario no encontrado: ' . ($username !== '' ? $username : ('#' . $uid)));
            if ($uid > 0 && (int)$target['id'] !== $uid) throw new Exception('El username no coincide con el usuario seleccionado.');
            $uid = (int)$target['id'];

            if (wt_used_row($conn, $uid, true) !== null) throw new Exception('Este usuario ya consumió su Pase de Bienvenida; no se puede volver a otorgar.');
            if (wt_grant_row($conn, $uid) !== null) throw new Exception('Este usuario ya tiene un Pase de Bienvenida asignado manualmente.');

            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            $adminName = mb_substr((string)($_SESSION['username'] ?? 'admin'), 0, 64);
            $ins = $conn->prepare(
                "INSERT INTO raffle_welcome_grants (user_id, granted_by, granted_by_name, note, created_at, revoked_at, revoked_by)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), NULL, NULL)
                 ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by), granted_by_name = VALUES(granted_by_name),
                                         note = VALUES(note), created_at = UTC_TIMESTAMP(), revoked_at = NULL, revoked_by = NULL"
            );
            $ins->bind_param('iiss', $uid, $adminId, $adminName, $note);
            $ins->execute();
            $ins->close();

            $st = wt_status($conn, $uid);
            $response = [
                'status' => 'success',
                'message' => 'Pase de Bienvenida otorgado a ' . ($target['public_display_name'] ?: $target['game_username']) . '.',
                'user_id' => $uid,
                'welcome' => $st,
                'warning' => !$st['available'] && $st['reason'] === 'disabled'
                    ? 'El Ticket de Bienvenida está DESACTIVADO en la configuración; el pase quedará guardado pero no se podrá usar hasta activarlo.'
                    : null,
            ];
        }

        // --- PASE DE BIENVENIDA: revocar asignación manual (si aún no se usó) ---
        elseif ($action === 'wt_revoke_grant') {
            wt_ensure_schema($conn);
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid <= 0) throw new Exception('Usuario inválido.');
            if (wt_grant_row($conn, $uid) === null) throw new Exception('Este usuario no tiene una asignación manual vigente.');
            if (wt_used_row($conn, $uid, true) !== null) throw new Exception('El pase ya fue consumido; no se puede revocar.');
            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            $up = $conn->prepare("UPDATE raffle_welcome_grants SET revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE user_id = ? AND revoked_at IS NULL");
            $up->bind_param('ii', $adminId, $uid);
            $up->execute();
            $up->close();
            $response = ['status' => 'success', 'message' => 'Asignación manual revocada.', 'user_id' => $uid];
        }

        // --- D. GUARDAR / EDITAR SORTEO ---
        elseif ($action === 'save_raffle') {
            $id = (int)($_POST['raffle_id'] ?? 0);

            // Aseguramos la columna del toggle de pre-elección (idempotente).
            $colAllowPre = $conn->query("SHOW COLUMNS FROM raffles LIKE 'allow_pre_choice'");
            if ($colAllowPre && $colAllowPre->num_rows === 0) {
                $conn->query("ALTER TABLE raffles ADD COLUMN allow_pre_choice TINYINT(1) NOT NULL DEFAULT 1");
            }
            // Sorteo privado / exclusivo (columna + tabla de invitados, idempotente).
            rp_ensure_schema($conn);
            $isPrivate = isset($_POST['is_private']) ? 1 : 0;
            
            // 1. Datos B��sicos
            $title = trim($_POST['title']);
            $winnersCount = (int)$_POST['winners_count'];
            $burnCount = (int)($_POST['burn_count'] ?? 0); // NUEVO: Al Agua
            $executionMode = $_POST['execution_mode'] ?? 'standard_queue'; // NUEVO: Fast Batch vs Standard
            $replayMinutes = (int)$_POST['replay_minutes'];
            
            // 2. Fechas (la BD guarda en UTC; el front ya envía start/end en UTC)
            $startMode = $_POST['start_mode'] ?? 'specific'; 
            $startTime = ($startMode === 'now') ? gmdate('Y-m-d H:i:s') : $_POST['start_time'];
            $endTime = $_POST['end_time'];

            // 3. Econom��a & Control (V2)
            $entryCost = (float)$_POST['entry_cost'];
            $isPotEnabled = isset($_POST['is_pot_enabled']) ? 1 : 0;
            $potPercent = (int)($_POST['pot_allocation_percent'] ?? 100);
            $maxParticipants = (int)$_POST['max_participants'];

            // 4. Gamificaci��n (V2)
            $allowCashback = isset($_POST['allow_cashback']) ? 1 : 0;
            $cashbackPercent = (int)($_POST['cashback_percent'] ?? 50);
            $allowDouble = isset($_POST['allow_double_or_nothing']) ? 1 : 0;
            $decisionTimeout = (int)($_POST['decision_timeout'] ?? 60);

            // 5. Animaciones (V2)
            $mainAnim = $_POST['main_animation'] ?? 'roulette';
            $gambleAnim = $_POST['gamble_animation'] ?? 'coin';

            // Ruleta Horizontal: por su diseño visual (pista de slots) el aforo
            // se limita a 20 participantes (por defecto 20). Se fuerza también en
            // el servidor para que no se pueda exceder saltándose la UI.
            if ($mainAnim === 'roulette') {
                if ($maxParticipants <= 0 || $maxParticipants > 20) {
                    $maxParticipants = 20;
                }
            }
            $allowAnimChoice = isset($_POST['allow_winner_animation_choice']) ? 1 : 0;
            // Toggle del modal de pre-elección (activado por defecto).
            $allowPreChoice = isset($_POST['allow_pre_choice']) ? 1 : 0;

            // 6. Premio
            // Schema 2 (premios por puesto / pools): el constructor del admin
            // envía todo el payload en prize_positions_json. Legacy (v1) se
            // mantiene para el formulario clásico de un solo premio.
            if (!empty($_POST['prize_positions_json'])) {
                $prizeConfig = sf_build_prize_config_v2($conn, (string)$_POST['prize_positions_json'], max(1, $winnersCount));
                $jsonPrize = json_encode($prizeConfig, JSON_UNESCAPED_UNICODE);
            } else {
            $prizeType = $_POST['prize_type'];
            $prizeConfig = ['type' => $prizeType];
            if ($prizeType === 'coins') {
                $prizeConfig['amount'] = (float)$_POST['prize_amount'];
                $prizeConfig['name'] = number_format($prizeConfig['amount']) . " Monedas";
            } else {
                $prizeConfig['id'] = $_POST['item_id'];
                $prizeConfig['name'] = $_POST['item_name'];
                $prizeConfig['image'] = $_POST['item_image'];
                if(isset($_POST['discount_type']) && $_POST['discount_type'] === 'partial') {
                    $pct = sf_offer_discount_percent(['discount_percent' => $_POST['discount_percent'] ?? null]);
                    if ($pct <= 0) throw new Exception('El porcentaje de la oferta debe estar entre 1 y 99 (100 = premio gratis).');
                    $prizeConfig['discount_percent'] = $pct;
                }
            }
            // --- PRIZE SNAPSHOT ENRICHMENT ---
            if ($prizeType === 'skin') {
                require_once __DIR__ . '/../../config/shop_config.php';
                $cachePath = ShopSourceConfig::activeSnapshotPath() ?? ShopSourceConfig::liveSnapshotPath();
                if ($cachePath && file_exists($cachePath)) {
                    $cacheRaw = file_get_contents($cachePath);
                    $cacheData = json_decode($cacheRaw, true);
                    $shopItems = $cacheData['entries'] ?? $cacheData['shop'] ?? [];
                    $itemId = $prizeConfig['id'] ?? '';
                    foreach ($shopItems as $shopItem) {
                        if (($shopItem['offerId'] ?? '') === $itemId) {
                            $prizeConfig['name'] = $shopItem['displayName'] ?? $prizeConfig['name'];
                            $da = $shopItem['displayAssets'][0] ?? null;
                            $bestImage = $da ? ($da['url'] ?? '') : '';
                            if (empty($bestImage)) $bestImage = $shopItem['images']['featured'] ?? ($shopItem['images']['icon'] ?? ($prizeConfig['image'] ?? ''));
                            $prizeConfig['image'] = $bestImage;
                            $prizeConfig['vbucks'] = $shopItem['vbucks'] ?? null;
                            $prizeConfig['rarity'] = $shopItem['rarity'] ?? null;
                            $prizeConfig['colors'] = $shopItem['colors'] ?? null;
                            $prizeConfig['displayType'] = $shopItem['displayType'] ?? null;
                            // Atuendos (y lotes de un solo personaje): además del asset
                            // grande (cuerpo completo) guardamos el ícono cuadrado
                            // enfocado del PERSONAJE (smallIcon) para las vistas
                            // pequeñas, donde el render completo se ve diminuto. La URL
                            // real vive dentro de brItems[].images, NO en el nivel de la
                            // oferta. El helper devuelve '' para no-atuendos y lotes
                            // multi-personaje, que conservan su flujo normal.
                            $focusedIcon = sf_extract_outfit_small_icon($shopItem);
                            if (!empty($focusedIcon) && $focusedIcon !== $bestImage) {
                                $prizeConfig['image_icon'] = $focusedIcon;
                            } else {
                                // Sin enfoque usable (o re-guardado de un sorteo con un
                                // image_icon previo inválido): lo limpiamos.
                                unset($prizeConfig['image_icon']);
                            }
                            break;
                        }
                    }
                }
            } elseif ($prizeType === 'product') {
                // Productos estáticos: la imagen y el precio viven en la tabla
                // productos_estaticos (Cloudinary / URLs externas). Enriquecemos el
                // snapshot del premio para que el modal del sorteo muestre la imagen
                // y para poder valuar cashback / Doble o Nada.
                $staticId = isset($prizeConfig['id']) ? (int)$prizeConfig['id'] : 0;
                if ($staticId > 0) {
                    $sp = $conn->prepare("SELECT nombre, imagen_url, precio_base, precio_oferta FROM productos_estaticos WHERE id = ? LIMIT 1");
                    if ($sp) {
                        $sp->bind_param("i", $staticId);
                        $sp->execute();
                        $spRow = $sp->get_result()->fetch_assoc();
                        $sp->close();
                        if ($spRow) {
                            if (empty($prizeConfig['image']) && !empty($spRow['imagen_url'])) {
                                $prizeConfig['image'] = $spRow['imagen_url'];
                            }
                            if (empty($prizeConfig['name']) && !empty($spRow['nombre'])) {
                                $prizeConfig['name'] = $spRow['nombre'];
                            }
                            $offer = isset($spRow['precio_oferta']) ? (float)$spRow['precio_oferta'] : 0.0;
                            $base  = isset($spRow['precio_base']) ? (float)$spRow['precio_base'] : 0.0;
                            $effective = $offer > 0 ? $offer : $base;
                            if ($effective > 0) $prizeConfig['base_price_soles'] = $effective;
                        }
                    }
                }
                if (empty($prizeConfig['image'])) $prizeConfig['image'] = null;
            } elseif ($prizeType === 'coins') {
                $prizeConfig['image'] = null;
            }

            $jsonPrize = json_encode($prizeConfig, JSON_UNESCAPED_UNICODE);
            }

            // 7. Requisitos
            $reqs = [
                'verified_only' => isset($_POST['req_verified']) ? 1 : 0,
                'min_orders_count' => !empty($_POST['req_min_orders']) ? (int)$_POST['req_min_orders'] : 0,
                'min_spent_total' => !empty($_POST['req_min_spent']) ? (float)$_POST['req_min_spent'] : 0
            ];
            if (!empty($_POST['req_min_date'])) $reqs['min_orders_date'] = $_POST['req_min_date'];

            // 7b. Recompensas Early-bird (Bananos por rapidez)
            $ebMode = $_POST['eb_mode'] ?? 'off';
            if (in_array($ebMode, ['slots', 'time', 'tiered'], true)) {
                if ($ebMode === 'slots') {
                    $eb = [
                        'mode' => 'slots',
                        'pool_total' => (float)($_POST['eb_pool_slots'] ?? 0),
                        'max_winners' => (int)($_POST['eb_max_winners'] ?? 0),
                    ];
                    if ($eb['pool_total'] > 0 && $eb['max_winners'] > 0) $reqs['early_bird'] = $eb;
                } elseif ($ebMode === 'time') {
                    $eb = [
                        'mode' => 'time',
                        'pool_total' => (float)($_POST['eb_pool_time'] ?? 0),
                        'window_seconds' => (int)($_POST['eb_window_seconds'] ?? 0),
                    ];
                    // Preservar el ancla manual si la ventana ya se inició con el botón,
                    // para no reiniciar el cronómetro al editar el sorteo.
                    if ($id > 0) {
                        $exStmt = $conn->prepare("SELECT requirements FROM raffles WHERE raffle_id = ?");
                        $exStmt->bind_param("i", $id);
                        $exStmt->execute();
                        $exReq = json_decode($exStmt->get_result()->fetch_assoc()['requirements'] ?? '{}', true);
                        if (!empty($exReq['early_bird']['window_started_at'])) {
                            $eb['window_started_at'] = $exReq['early_bird']['window_started_at'];
                        }
                    }
                    if ($eb['pool_total'] > 0 && $eb['window_seconds'] > 0) $reqs['early_bird'] = $eb;
                } else { // tiered
                    $rawTiers = $_POST['eb_tiers'] ?? [];
                    $tiers = [];
                    if (is_array($rawTiers)) {
                        foreach ($rawTiers as $t) {
                            $val = round((float)$t, 2);
                            $tiers[] = ($val > 0) ? $val : 0.0;
                        }
                    }
                    while (!empty($tiers) && $tiers[count($tiers) - 1] <= 0) array_pop($tiers);
                    if (!empty($tiers)) $reqs['early_bird'] = ['mode' => 'tiered', 'tiers' => $tiers];
                }
            }

            // 7c. Anti-fraude (Límite por IP + Huella). IP activo por defecto.
            $reqs['anti_fraud'] = [
                'ip_limit_enabled' => isset($_POST['af_ip_enabled']) ? 1 : 0,
                'ip_limit_max' => max(1, (int)($_POST['af_ip_max'] ?? 1)),
                'fingerprint_enabled' => isset($_POST['af_fp_enabled']) ? 1 : 0,
            ];

            $jsonReqs = json_encode($reqs);

            // EJECUCI�0�7N SQL BLINDADA
            if ($id > 0) {
                // UPDATE (Ahora incluye burn_count y execution_mode)
                $sql = "UPDATE raffles SET 
                        title=?, start_time=?, end_time=?, winners_count=?, burn_count=?, execution_mode=?, replay_minutes=?, prize_config=?, requirements=?,
                        entry_cost=?, is_pot_enabled=?, pot_allocation_percent=?, max_participants=?,
                        allow_cashback=?, cashback_percent=?, allow_double_or_nothing=?, decision_timeout=?,
                        main_animation=?, gamble_animation=?, allow_winner_animation_choice=?, allow_pre_choice=?, is_private=?
                        WHERE raffle_id=?";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssiisissdiiiiiiissiiii", 
                    $title, $startTime, $endTime, $winnersCount, $burnCount, $executionMode, $replayMinutes, $jsonPrize, $jsonReqs,
                    $entryCost, $isPotEnabled, $potPercent, $maxParticipants,
                    $allowCashback, $cashbackPercent, $allowDouble, $decisionTimeout,
                    $mainAnim, $gambleAnim, $allowAnimChoice, $allowPreChoice, $isPrivate, $id
                );
                $stmt->execute();
                $msg = "Sorteo actualizado correctamente.";

            } else {
                // INSERT (Ahora incluye burn_count y execution_mode)
                $serverSeedSecret = bin2hex(random_bytes(32)); 
                $serverSeedHash = hash('sha256', $serverSeedSecret);
                $initialStatus = ($startMode === 'now') ? 'open' : 'scheduled';

                $sql = "INSERT INTO raffles (
                        title, start_time, end_time, winners_count, burn_count, execution_mode, replay_minutes, prize_config, requirements, 
                        server_seed_secret, server_seed_hash, status,
                        entry_cost, is_pot_enabled, pot_allocation_percent, max_participants,
                        allow_cashback, cashback_percent, allow_double_or_nothing, decision_timeout,
                        main_animation, gamble_animation, allow_winner_animation_choice, allow_pre_choice, is_private
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssiisisssssdiiiiiiissiii",
                    $title, $startTime, $endTime, $winnersCount, $burnCount, $executionMode, $replayMinutes, $jsonPrize, $jsonReqs, 
                    $serverSeedSecret, $serverSeedHash, $initialStatus,
                    $entryCost, $isPotEnabled, $potPercent, $maxParticipants,
                    $allowCashback, $cashbackPercent, $allowDouble, $decisionTimeout,
                    $mainAnim, $gambleAnim, $allowAnimChoice, $allowPreChoice, $isPrivate
                );
                $stmt->execute();
                $id = (int)$conn->insert_id;
                $msg = ($initialStatus === 'open') ? "�0�3Sorteo V2 abierto!" : "Sorteo programado.";
            }

            // Invitados preparados en el formulario antes de que existiera el
            // sorteo (alta nueva): se persisten ahora con el raffle_id real.
            $pendingInvites = json_decode((string)($_POST['private_invites_json'] ?? ''), true);
            if ($id > 0 && $isPrivate === 1 && is_array($pendingInvites)) {
                $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
                foreach ($pendingInvites as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) rp_invite_add($conn, $id, $uid, '', $adminId);
                }
            }
            $response = ['status' => 'success', 'message' => $msg, 'raffle_id' => $id, 'is_private' => $isPrivate];
        }
        
        // --- D1a. REGISTRAR EL ÍTEM CONCRETO ELEGIDO POR EL GANADOR (categoría) ---
        // Cuando la opción elegida es una categoría (admin_resolves), el ganador
        // indica el ítem exacto por chat y el admin lo registra aquí. Esto sí
        // permite corrección mientras no se marque entregado.
        elseif ($action === 'set_winner_final_item') {
            $winnerId = (int)($_POST['winner_id'] ?? 0);
            $itemName = trim((string)($_POST['item_name'] ?? ''));
            $itemId = trim((string)($_POST['item_id'] ?? ''));
            $itemImage = trim((string)($_POST['item_image'] ?? ''));
            if ($winnerId <= 0) throw new Exception('Ganador inválido.');
            if ($itemName === '') throw new Exception('Debes indicar el nombre del ítem elegido.');

            $conn->begin_transaction();
            $stmt = $conn->prepare("SELECT id, raffle_id, user_id, prize_state, final_prize, delivery_status, generated_order_id FROM raffle_winners WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $winnerId);
            $stmt->execute();
            $w = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$w) { $conn->rollback(); throw new Exception('Ganador no encontrado.'); }
            if (!in_array((string)$w['prize_state'], ['admin_pending', 'admin_resolved'], true)) {
                $conn->rollback();
                throw new Exception('Este ganador no tiene una categoría pendiente de concretar.');
            }
            if (($w['delivery_status'] ?? '') === 'delivered') {
                $conn->rollback();
                throw new Exception('El premio ya fue marcado como entregado; no se puede modificar.');
            }

            $final = json_decode((string)$w['final_prize'], true);
            if (!is_array($final)) $final = [];
            $final['resolved_item'] = [
                'name' => $itemName,
                'id' => $itemId !== '' ? $itemId : null,
                'image' => $itemImage !== '' ? $itemImage : null,
                'resolved_at' => gmdate('Y-m-d H:i:s'),
            ];
            $finalJson = json_encode($final, JSON_UNESCAPED_UNICODE);

            // Pedido concreto para el ganador: se crea al concretar por primera
            // vez; una corrección posterior solo actualiza el detalle del pedido.
            $orderId = (int)($w['generated_order_id'] ?? 0);
            $raffleIdW = (int)$w['raffle_id'];
            $winnerUserId = (int)$w['user_id'];
            $cachePath = ShopSourceConfig::activeSnapshotPath() ?? ShopSourceConfig::liveSnapshotPath();
            $prizeCfg = [
                'id' => $itemId !== '' ? $itemId : ('sorteo-' . $raffleIdW . '-cat'),
                'name' => $itemName,
                'image' => $itemImage,
            ];
            if ($orderId > 0) {
                $updDet = $conn->prepare("UPDATE detalles_pedido SET producto_id_str = ?, nombre_producto = ?, imagen_producto_url = ? WHERE pedido_id = ?");
                $pid = (string)$prizeCfg['id'];
                $updDet->bind_param("sssi", $pid, $itemName, $itemImage, $orderId);
                $updDet->execute();
                $updDet->close();
            } else {
                $orderId = rr_create_prize_order($conn, $winnerUserId, $prizeCfg, $cachePath, $raffleIdW, "GANADOR SORTEO #$raffleIdW (Categoría concretada por admin)");
            }

            $upd = $conn->prepare("UPDATE raffle_winners SET prize_state = 'admin_resolved', final_prize = ?, prize_sent = 1, generated_order_id = ? WHERE id = ?");
            $upd->bind_param("sii", $finalJson, $orderId, $winnerId);
            $upd->execute();
            $upd->close();
            $conn->commit();

            $response = ['status' => 'success', 'message' => 'Ítem registrado y pedido generado para el ganador.'];
        }

        // --- D1b. SUBIR IMAGEN DE PREMIO PERSONALIZADO (pool) ---
        elseif ($action === 'upload_prize_image') {
            $dir = sf_prize_image_dir();
            if ($dir === '') throw new Exception('Directorio de premios no disponible.');

            if (empty($_FILES['prize_image']) || $_FILES['prize_image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No se recibió imagen o hubo un error de subida.');
            }
            $file = $_FILES['prize_image'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed)) throw new Exception('Formato no permitido. Use: ' . implode(', ', $allowed));
            if ($file['size'] > 5 * 1024 * 1024) throw new Exception('Imagen demasiado grande (máx 5MB).');

            $info = @getimagesize($file['tmp_name']);
            if ($info === false) throw new Exception('El archivo no es una imagen válida.');

            $destName = 'prize_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $dir . DIRECTORY_SEPARATOR . $destName;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new Exception('Error al mover la imagen.');
            }
            $response = ['status' => 'success', 'message' => 'Imagen subida.', 'url' => '/assets/raffle_prizes/' . rawurlencode($destName)];
        }

        // --- D2. SUBIR ARCHIVO DE AUDIO ---
        elseif ($action === 'upload_audio') {
            $assetsDir = sf_public_assets_dir();
            $audioDir  = $assetsDir !== '' ? realpath($assetsDir . '/fx/audio') : false;
            if (!$audioDir || !is_dir($audioDir)) throw new Exception('Directorio de audio no encontrado.');

            if (empty($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No se recibió archivo o hubo un error de subida.');
            }
            $file = $_FILES['audio_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['mp3', 'wav', 'ogg', 'webm', 'm4a'];
            if (!in_array($ext, $allowed)) throw new Exception('Formato no permitido. Use: ' . implode(', ', $allowed));
            if ($file['size'] > 10 * 1024 * 1024) throw new Exception('Archivo demasiado grande (máx 10MB).');

            $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
            $destName = $safeName . '.' . $ext;
            $destPath = $audioDir . DIRECTORY_SEPARATOR . $destName;

            // Avoid overwrite: append timestamp if exists
            if (file_exists($destPath)) {
                $destName = $safeName . '_' . time() . '.' . $ext;
                $destPath = $audioDir . DIRECTORY_SEPARATOR . $destName;
            }

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new Exception('Error al mover el archivo.');
            }
            $response = ['status' => 'success', 'message' => 'Audio subido.', 'file' => [
                'name' => $destName,
                'size' => filesize($destPath),
                'url'  => '/assets/fx/audio/' . rawurlencode($destName)
            ]];
        }

        // --- D3. GUARDAR CONFIG GLOBAL DE AUDIO ---
        elseif ($action === 'save_global_audio') {
            $configDir = __DIR__ . '/../../config';
            if (!is_dir($configDir)) mkdir($configDir, 0755, true);
            $configPath = $configDir . '/sorteo_audio_global.json';

            $raw = $_POST['audio_config'] ?? '';
            if (!empty($raw)) {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) throw new Exception('Formato de audio_config inválido.');
                file_put_contents($configPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                // Empty config = remove file
                if (file_exists($configPath)) unlink($configPath);
            }
            $response = ['status' => 'success', 'message' => 'Sonidos globales guardados.'];
        }

        // --- D4. SUBIR IMAGEN DE UN SLOT DE LA MASCOTA (Portal de Transparencia) ---
        // Guarda el PNG/WebP en public_html/assets/banano_poses/ y registra la
        // URL en config/banano_slots.json (leído por transparencia.php).
        elseif ($action === 'upload_banano_slot') {
            list($device, $slot) = banano_slot_params();

            $slotsDir = banano_assets_dir();

            if (empty($_FILES['slot_file']) || $_FILES['slot_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No se recibió archivo o hubo un error de subida.');
            }
            $file = $_FILES['slot_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['png', 'webp'];
            if (!in_array($ext, $allowed)) throw new Exception('Formato no permitido. Use: png, webp');
            if ($file['size'] > 5 * 1024 * 1024) throw new Exception('Archivo demasiado grande (máx 5MB).');

            $info = @getimagesize($file['tmp_name']);
            if ($info === false) throw new Exception('El archivo no es una imagen válida.');

            $destName = 'banano_' . $device . '_' . $slot . '.' . $ext;
            $destPath = $slotsDir . DIRECTORY_SEPARATOR . $destName;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new Exception('Error al mover el archivo.');
            }

            // Limpia la variante con la otra extensión para no dejar huérfanos
            foreach ($allowed as $otherExt) {
                if ($otherExt === $ext) continue;
                $other = $slotsDir . DIRECTORY_SEPARATOR . 'banano_' . $device . '_' . $slot . '.' . $otherExt;
                if (file_exists($other)) unlink($other);
            }

            $slots = banano_read_slots();
            $current = isset($slots[$device][(string)$slot]) && is_array($slots[$device][(string)$slot]) ? $slots[$device][(string)$slot] : [];
            $current['url'] = '/assets/banano_poses/' . rawurlencode($destName) . '?v=' . time();
            $current += ['scale' => 100, 'rotate' => 0, 'x' => 0, 'y' => 0];
            $slots[$device][(string)$slot] = $current;
            banano_write_slots($slots);

            $response = ['status' => 'success', 'message' => 'Imagen ' . $slot . ' (' . $device . ') actualizada.', 'slot' => $current];
        }

        // --- D5. ELIMINAR IMAGEN DE UN SLOT DE LA MASCOTA ---
        elseif ($action === 'delete_banano_slot') {
            list($device, $slot) = banano_slot_params();

            $slots = banano_read_slots();
            unset($slots[$device][(string)$slot]);
            banano_write_slots($slots);

            $assetsDir = sf_public_assets_dir();
            $slotsDir  = $assetsDir !== '' ? realpath($assetsDir . '/banano_poses') : false;
            if ($slotsDir && is_dir($slotsDir)) {
                foreach (['png', 'webp'] as $ext) {
                    $f = $slotsDir . DIRECTORY_SEPARATOR . 'banano_' . $device . '_' . $slot . '.' . $ext;
                    if (file_exists($f)) unlink($f);
                }
            }
            $response = ['status' => 'success', 'message' => 'Imagen ' . $slot . ' (' . $device . ') eliminada.'];
        }

        // --- D6. GUARDAR AJUSTES VISUALES DE UN SLOT (escala/rotación/offset) ---
        elseif ($action === 'save_banano_slot_transform') {
            list($device, $slot) = banano_slot_params();

            $slots = banano_read_slots();
            if (!isset($slots[$device][(string)$slot]) || empty($slots[$device][(string)$slot]['url'])) {
                throw new Exception('El slot no tiene imagen: sube una antes de ajustarla.');
            }
            $slots[$device][(string)$slot]['scale']  = max(50, min(200, (int)($_POST['scale'] ?? 100)));
            $slots[$device][(string)$slot]['rotate'] = max(-30, min(30, (int)($_POST['rotate'] ?? 0)));
            $slots[$device][(string)$slot]['x']      = max(-120, min(120, (int)($_POST['x'] ?? 0)));
            $slots[$device][(string)$slot]['y']      = max(-120, min(120, (int)($_POST['y'] ?? 0)));
            $slots[$device][(string)$slot]['animate'] = ((string)($_POST['animate'] ?? '1') === '1') ? 1 : 0;
            banano_write_slots($slots);

            $response = ['status' => 'success', 'message' => 'Ajustes guardados.', 'slot' => $slots[$device][(string)$slot]];
        }

        // --- D7. SUBIR PÁGINA MANGA COMPLETA (Portal de Transparencia) ---
        // Imagen única (compartida escritorio/móvil) que reemplaza una sección
        // entera, p.ej. la de "¿Quién podría hacer trampa?".
        elseif ($action === 'upload_banano_page') {
            $key = banano_page_key();
            $slotsDir = banano_assets_dir();

            if (empty($_FILES['page_file']) || $_FILES['page_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No se recibió archivo o hubo un error de subida.');
            }
            $file = $_FILES['page_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext === 'jpeg') $ext = 'jpg';
            $allowed = ['png', 'webp', 'jpg'];
            if (!in_array($ext, $allowed)) throw new Exception('Formato no permitido. Use: png, webp, jpg');
            if ($file['size'] > 8 * 1024 * 1024) throw new Exception('Archivo demasiado grande (máx 8MB).');

            $info = @getimagesize($file['tmp_name']);
            if ($info === false) throw new Exception('El archivo no es una imagen válida.');

            $destName = 'banano_page_' . $key . '.' . $ext;
            $destPath = $slotsDir . DIRECTORY_SEPARATOR . $destName;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new Exception('Error al mover el archivo.');
            }
            foreach ($allowed as $otherExt) {
                if ($otherExt === $ext) continue;
                $other = $slotsDir . DIRECTORY_SEPARATOR . 'banano_page_' . $key . '.' . $otherExt;
                if (file_exists($other)) unlink($other);
            }

            $slots = banano_read_slots();
            $slots['pages'][$key] = ['url' => '/assets/banano_poses/' . rawurlencode($destName) . '?v=' . time()];
            banano_write_slots($slots);

            $response = ['status' => 'success', 'message' => 'Página manga actualizada.', 'page' => $slots['pages'][$key]];
        }

        // --- D8. ELIMINAR PÁGINA MANGA ---
        elseif ($action === 'delete_banano_page') {
            $key = banano_page_key();

            $slots = banano_read_slots();
            unset($slots['pages'][$key]);
            banano_write_slots($slots);

            $assetsDir = sf_public_assets_dir();
            $slotsDir  = $assetsDir !== '' ? realpath($assetsDir . '/banano_poses') : false;
            if ($slotsDir && is_dir($slotsDir)) {
                foreach (['png', 'webp', 'jpg'] as $ext) {
                    $f = $slotsDir . DIRECTORY_SEPARATOR . 'banano_page_' . $key . '.' . $ext;
                    if (file_exists($f)) unlink($f);
                }
            }
            $response = ['status' => 'success', 'message' => 'Página manga eliminada.'];
        }

        // --- E. ELIMINAR SORTEO ---
        elseif ($action === 'delete_raffle') {
            $id = (int)$_POST['id'];
            $conn->query("DELETE FROM raffle_participants WHERE raffle_id = $id");
            $conn->query("DELETE FROM raffle_winners WHERE raffle_id = $id");
            $conn->query("DELETE FROM raffles WHERE raffle_id = $id");
            $response = ['status' => 'success', 'message' => 'Eliminado.'];
        }

        // --- F. GESTI�0�7N MANUAL DE BOTE ---
        elseif ($action === 'inject_pot') {
            $id = (int)$_POST['id'];
            $amount = (float)$_POST['amount'];
            if ($amount > 0) {
                $conn->query("UPDATE raffles SET current_pot = current_pot + $amount WHERE raffle_id = $id");
                $response = ['status' => 'success', 'message' => "Bote aumentado."];
            } else { throw new Exception("Monto inv��lido."); }
        }

        // --- G. BOTÓN DE PÁNICO: FORZAR INICIO ("viaje en el tiempo") ---
        // Solo adelanta el reloj: pone end_time = NOW() para que la sala de
        // espera cierre de inmediato. El sorteo sigue su curso natural: el motor
        // (cron) detecta la expiración y ejecuta triggerRaffleClose como siempre,
        // respetando la espera del bloque futuro de la BSC y la elegibilidad.
        // Si estaba 'scheduled', también adelantamos start_time para que el cron
        // lo abra y cierre con normalidad. No tocamos participantes ni reglas.
        elseif ($action === 'force_start') {
            $id = (int)$_POST['id'];
            $lock = $conn->prepare("SELECT status FROM raffles WHERE raffle_id = ? FOR UPDATE");
            $lock->bind_param("i", $id);
            $lock->execute();
            $rRow = $lock->get_result()->fetch_assoc();
            if (!$rRow) throw new Exception("Sorteo no encontrado.");
            $st = $rRow['status'];
            if (!in_array($st, ['scheduled', 'open'], true)) {
                throw new Exception("No se puede forzar el inicio: el sorteo ya está en curso o finalizado (estado: $st).");
            }
            if ($st === 'scheduled') {
                // Adelanta apertura y cierre; el cron abrirá y luego cerrará.
                $upd = $conn->prepare("UPDATE raffles SET start_time = NOW(), end_time = NOW() WHERE raffle_id = ?");
            } else {
                // Ya abierto: solo vence la cuenta regresiva.
                $upd = $conn->prepare("UPDATE raffles SET end_time = NOW() WHERE raffle_id = ?");
            }
            $upd->bind_param("i", $id);
            $upd->execute();
            $response = ['status' => 'success', 'message' => 'Inicio forzado: la sala se cierra ahora y el sorteo seguirá su curso normal.'];
        }

        // --- G. INICIAR MANUALMENTE LA VENTANA EARLY-BIRD (Modo B / tiempo) ---
        // Fija el ancla del cronómetro en este instante (UTC). Útil para testear
        // el sorteo y anunciarlo justo cuando arranca el conteo, en vez de que la
        // ventana corra desde start_time. No afecta participantes ni elegibilidad.
        elseif ($action === 'start_eb_window') {
            $id = (int)($_POST['id'] ?? 0);
            $lock = $conn->prepare("SELECT requirements FROM raffles WHERE raffle_id = ? FOR UPDATE");
            $lock->bind_param("i", $id);
            $lock->execute();
            $rRow = $lock->get_result()->fetch_assoc();
            if (!$rRow) throw new Exception("Sorteo no encontrado.");

            $rq = json_decode($rRow['requirements'] ?? '{}', true);
            if (!is_array($rq)) $rq = [];
            if (empty($rq['early_bird']) || ($rq['early_bird']['mode'] ?? '') !== 'time') {
                throw new Exception("Este sorteo no usa la recompensa Early-bird por tiempo (Modo B).");
            }

            $startedAt = gmdate('Y-m-d H:i:s'); // UTC, igual que UTC_TIMESTAMP()
            $rq['early_bird']['window_started_at'] = $startedAt;
            $newJson = json_encode($rq, JSON_UNESCAPED_UNICODE);
            $upd = $conn->prepare("UPDATE raffles SET requirements = ? WHERE raffle_id = ?");
            $upd->bind_param("si", $newJson, $id);
            $upd->execute();

            $win = (int)($rq['early_bird']['window_seconds'] ?? 0);
            $response = [
                'status' => 'success',
                'message' => "Ventana Early-bird iniciada ahora ({$win}s). El cronómetro corre desde este instante.",
                'window_started_at' => $startedAt,
                'window_seconds' => $win
            ];
        }

        // --- SALA DE MANDO: APROBAR PARTICIPANTE BAJO REVISIÓN ---
        // Confirma a un usuario 'under_review' como participante normal del sorteo.
        elseif ($action === 'cc_approve') {
            $rid = (int)($_POST['raffle_id'] ?? 0);
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($rid <= 0 || $uid <= 0) throw new Exception("Datos inválidos.");
            rr_ensure_schema($conn);

            $upd = $conn->prepare("UPDATE raffle_participants SET review_status = 'active' WHERE raffle_id = ? AND user_id = ? AND review_status = 'under_review'");
            $upd->bind_param("ii", $rid, $uid); $upd->execute();
            $response = ['status' => 'success', 'message' => 'Usuario aprobado: confirmado en el sorteo.'];
        }

        // --- SALA DE MANDO: EXPULSAR PARTICIPANTE (solo de ESTE sorteo) ---
        // Modelo A + tickets estáticos: NO se borra la fila; se marca como HUECO
        // ('expelled') conservando su número de ticket. Reembolsa la entrada y
        // suma 1 strike al historial (sin ban automático). Sólo válido ANTES de
        // que arranque el sorteo (estado 'open'/'scheduled' = antes de T_freeze).
        elseif ($action === 'cc_expel') {
            $rid = (int)($_POST['raffle_id'] ?? 0);
            $uid = (int)($_POST['user_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($rid <= 0 || $uid <= 0) throw new Exception("Datos inválidos.");
            rr_ensure_schema($conn);

            // BLOQUEO MODELO A: el roster se congela en T_freeze (open → locking).
            $stStmt = $conn->prepare("SELECT status FROM raffles WHERE raffle_id = ?");
            $stStmt->bind_param("i", $rid); $stStmt->execute();
            $rStatus = (string)($stStmt->get_result()->fetch_assoc()['status'] ?? '');
            if (!in_array($rStatus, ['scheduled', 'open'], true)) {
                throw new Exception("No se puede expulsar: el sorteo ya arrancó (estado: $rStatus). El roster está congelado por Provably Fair.");
            }

            $pStmt = $conn->prepare("SELECT entry_fee_paid, review_status FROM raffle_participants WHERE raffle_id = ? AND user_id = ?");
            $pStmt->bind_param("ii", $rid, $uid); $pStmt->execute();
            $pRow = $pStmt->get_result()->fetch_assoc();
            if (!$pRow) throw new Exception("El usuario no está inscrito en este sorteo.");
            if (($pRow['review_status'] ?? '') === 'expelled') throw new Exception("Ese participante ya fue expulsado.");
            $fee = (float)($pRow['entry_fee_paid'] ?? 0);

            // Dejamos el asiento como HUECO físico (conserva su ticket_index).
            $upd = $conn->prepare("UPDATE raffle_participants SET review_status = 'expelled' WHERE raffle_id = ? AND user_id = ?");
            $upd->bind_param("ii", $rid, $uid); $upd->execute();

            // REEMBOLSO de la entrada (si pagó).
            if ($fee > 0) {
                $conn->query("INSERT IGNORE INTO coins (user_id, balance) VALUES ($uid, 0.00)");
                $rb = $conn->prepare("UPDATE coins SET balance = balance + ? WHERE user_id = ?");
                $rb->bind_param("di", $fee, $uid); $rb->execute();
                $refId = (string)$rid;
                $tx = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, created_at) VALUES (?, ?, 'raffle_expel_refund', 'raffle_id', ?, UTC_TIMESTAMP())");
                if ($tx) { $tx->bind_param("ids", $uid, $fee, $refId); $tx->execute(); }
            }

            // STRIKE: suma 1 expulsión al historial (sin ban automático).
            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            $stk = $conn->prepare("INSERT INTO raffle_strikes (user_id, raffle_id, reason, created_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())");
            if ($stk) { $stk->bind_param("iisi", $uid, $rid, $reason, $adminId); $stk->execute(); }

            // Limpiamos también la señal anti-fraude para no contaminar el conteo.
            $sigTbl = $conn->query("SHOW TABLES LIKE 'raffle_entry_signals'");
            if ($sigTbl && $sigTbl->num_rows > 0) {
                $ds = $conn->prepare("DELETE FROM raffle_entry_signals WHERE raffle_id = ? AND user_id = ?");
                $ds->bind_param("ii", $rid, $uid); $ds->execute();
            }

            $strikes = rr_strike_count($conn, $uid);
            $response = ['status' => 'success', 'strikes' => $strikes, 'message' => 'Participante expulsado (su ticket queda como hueco). ' . ($fee > 0 ? "Se devolvieron " . number_format($fee, 2) . " monedas. " : '') . "Strikes acumulados: $strikes."];
        }

        // --- SALA DE MANDO: REACTIVAR / DES-EXPULSAR PARTICIPANTE ---
        // Revierte una expulsión errónea ANTES de T_freeze: el usuario recupera
        // su MISMO ticket (solo cambia review_status), se le re-cobra la entrada
        // que se le reembolsó y se elimina el strike de esa expulsión. El aforo
        // NO bloquea la reactivación (puede quedar temporalmente por encima del
        // límite si otro ocupó el cupo mientras estaba expulsado).
        elseif ($action === 'cc_unexpel') {
            $rid = (int)($_POST['raffle_id'] ?? 0);
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($rid <= 0 || $uid <= 0) throw new Exception("Datos inválidos.");
            rr_ensure_schema($conn);

            $conn->begin_transaction();
            try {
                // Bloqueamos la fila del sorteo para serializar con joins/expulsiones.
                $stStmt = $conn->prepare("SELECT status, entry_cost FROM raffles WHERE raffle_id = ? FOR UPDATE");
                $stStmt->bind_param("i", $rid); $stStmt->execute();
                $rRow = $stStmt->get_result()->fetch_assoc();
                if (!$rRow) throw new Exception("El sorteo no existe.");
                $rStatus = (string)($rRow['status'] ?? '');
                if (!in_array($rStatus, ['scheduled', 'open'], true)) {
                    throw new Exception("No se puede reactivar: el sorteo ya arrancó (estado: $rStatus). El roster está congelado por Provably Fair.");
                }

                $pStmt = $conn->prepare("SELECT entry_fee_paid, review_status, ip_address FROM raffle_participants WHERE raffle_id = ? AND user_id = ? FOR UPDATE");
                $pStmt->bind_param("ii", $rid, $uid); $pStmt->execute();
                $pRow = $pStmt->get_result()->fetch_assoc();
                if (!$pRow) throw new Exception("El usuario no está inscrito en este sorteo.");
                if (($pRow['review_status'] ?? '') !== 'expelled') throw new Exception("Ese participante no está expulsado.");
                $fee = (float)($pRow['entry_fee_paid'] ?? 0);

                // RE-COBRO: al expulsarlo se le reembolsó; verificamos saldo y
                // volvemos a cobrar ANTES de reactivarlo (si no alcanza, se aborta).
                if ($fee > 0) {
                    $balStmt = $conn->prepare("SELECT balance FROM coins WHERE user_id = ? FOR UPDATE");
                    $balStmt->bind_param("i", $uid); $balStmt->execute();
                    $balRow = $balStmt->get_result()->fetch_assoc();
                    $bal = $balRow ? (float)$balRow['balance'] : 0.00;
                    if ($bal < $fee) {
                        throw new Exception("No se puede reactivar: el usuario no tiene saldo suficiente para re-cobrar la entrada (" . number_format($fee, 2) . " monedas; tiene " . number_format($bal, 2) . ").");
                    }
                    $chg = $conn->prepare("UPDATE coins SET balance = balance - ? WHERE user_id = ?");
                    $chg->bind_param("di", $fee, $uid); $chg->execute();
                    $negFee = -$fee; $refId = (string)$rid;
                    $tx = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, created_at) VALUES (?, ?, 'raffle_unexpel_recharge', 'raffle_id', ?, UTC_TIMESTAMP())");
                    if ($tx) { $tx->bind_param("ids", $uid, $negFee, $refId); $tx->execute(); }
                }

                // Reactivar conservando su MISMO ticket_index (el aforo no bloquea).
                $upd = $conn->prepare("UPDATE raffle_participants SET review_status = 'active' WHERE raffle_id = ? AND user_id = ?");
                $upd->bind_param("ii", $rid, $uid); $upd->execute();

                // Eliminar el strike de la expulsión errónea (el más reciente de este sorteo).
                $del = $conn->prepare("DELETE FROM raffle_strikes WHERE user_id = ? AND raffle_id = ? ORDER BY id DESC LIMIT 1");
                if ($del) { $del->bind_param("ii", $uid, $rid); $del->execute(); }

                // Restaurar la señal anti-fraude (se borró al expulsarlo).
                $sigTbl = $conn->query("SHOW TABLES LIKE 'raffle_entry_signals'");
                if ($sigTbl && $sigTbl->num_rows > 0) {
                    $sigIp = (string)($pRow['ip_address'] ?? '');
                    $ins = $conn->prepare("INSERT INTO raffle_entry_signals (raffle_id, user_id, ip_address, fingerprint, created_at) VALUES (?, ?, ?, '', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE ip_address = VALUES(ip_address)");
                    if ($ins) { $ins->bind_param("iis", $rid, $uid, $sigIp); $ins->execute(); }
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }

            $strikes = rr_strike_count($conn, $uid);
            $response = ['status' => 'success', 'strikes' => $strikes, 'message' => 'Participante reactivado con su mismo ticket. ' . ($fee > 0 ? "Se re-cobraron " . number_format($fee, 2) . " monedas de la entrada. " : '') . "Strikes: $strikes."];
        }

        // --- SALA DE MANDO: BANEAR DE SORTEOS (tabla raffle_bans) ---
        // Crea un baneo EXCLUSIVO del módulo de sorteos (no toca users.account_status)
        // con snapshot de IP y huella para bloquear también cuentas nuevas del mismo
        // dispositivo/red en raffle_join.php.
        elseif ($action === 'cc_ban') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            $banType = (string)($_POST['ban_type'] ?? 'permanent');
            $rid = (int)($_POST['raffle_id'] ?? 0); // opcional: sorteo actual para auto-expulsar
            if ($uid <= 0) throw new Exception("Usuario inválido.");
            if (!in_array($banType, ['next_n_raffles', 'until_date', 'permanent'], true)) throw new Exception("Tipo de baneo inválido.");
            rb_ensure_schema($conn);

            $rafflesN = null; $expiresAt = null;
            if ($banType === 'next_n_raffles') {
                $rafflesN = (int)($_POST['raffles_n'] ?? 0);
                if ($rafflesN <= 0) throw new Exception("Indica cuántos sorteos durará el baneo.");
            } elseif ($banType === 'until_date') {
                $days = (float)($_POST['days'] ?? 0);
                if ($days <= 0) throw new Exception("Indica la duración del baneo en días.");
                $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)round($days * 86400));
            }

            // Snapshot de IP + huella: última señal de inscripción del usuario.
            $ipSnap = ''; $fpSnap = '';
            $sigTbl = $conn->query("SHOW TABLES LIKE 'raffle_entry_signals'");
            if ($sigTbl && $sigTbl->num_rows > 0) {
                $sg = $conn->prepare("SELECT ip_address, fingerprint FROM raffle_entry_signals WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                if ($sg) {
                    $sg->bind_param("i", $uid); $sg->execute();
                    $sgRow = $sg->get_result()->fetch_assoc();
                    if ($sgRow) { $ipSnap = (string)($sgRow['ip_address'] ?? ''); $fpSnap = (string)($sgRow['fingerprint'] ?? ''); }
                }
            }
            if ($ipSnap === '') {
                $ipQ = $conn->prepare("SELECT ip_address FROM raffle_participants WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                if ($ipQ) {
                    $ipQ->bind_param("i", $uid); $ipQ->execute();
                    $ipRow = $ipQ->get_result()->fetch_assoc();
                    if ($ipRow) $ipSnap = (string)($ipRow['ip_address'] ?? '');
                }
            }

            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            $ins = $conn->prepare("INSERT INTO raffle_bans (user_id, ban_type, raffles_remaining, expires_at, reason, ip_snapshot, fingerprint_snapshot, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            $ins->bind_param("isissssi", $uid, $banType, $rafflesN, $expiresAt, $reason, $ipSnap, $fpSnap, $adminId);
            $ins->execute();

            // Log de admin (best-effort).
            $logTbl = $conn->query("SHOW TABLES LIKE 'admin_actions_log'");
            if ($adminId > 0 && $logTbl && $logTbl->num_rows > 0) {
                $det = "raffle_ban:$banType" . ($rafflesN ? ":n=$rafflesN" : '') . ($expiresAt ? ":until=$expiresAt" : '') . ($reason !== '' ? " | $reason" : '');
                $lg = $conn->prepare("INSERT INTO admin_actions_log (admin_id, target_user_id, action, details) VALUES (?, ?, 'raffle_ban', ?)");
                if ($lg) { $lg->bind_param("iis", $adminId, $uid, $det); $lg->execute(); }
            }

            // AUTO-EXPULSIÓN: si el usuario está inscrito en el sorteo indicado y
            // el roster aún no se congeló (scheduled/open), se le expulsa automáticamente
            // (mismo flujo que cc_expel: hueco + reembolso + strike + limpia señal).
            $expelTxt = '';
            if ($rid > 0) {
                $stStmt = $conn->prepare("SELECT status FROM raffles WHERE raffle_id = ?");
                $stStmt->bind_param("i", $rid); $stStmt->execute();
                $rStatus = (string)($stStmt->get_result()->fetch_assoc()['status'] ?? '');

                $pStmt = $conn->prepare("SELECT entry_fee_paid, review_status FROM raffle_participants WHERE raffle_id = ? AND user_id = ?");
                $pStmt->bind_param("ii", $rid, $uid); $pStmt->execute();
                $pRow = $pStmt->get_result()->fetch_assoc();

                if ($pRow && ($pRow['review_status'] ?? '') !== 'expelled') {
                    if (in_array($rStatus, ['scheduled', 'open'], true)) {
                        rr_ensure_schema($conn);
                        $fee = (float)($pRow['entry_fee_paid'] ?? 0);

                        $upd = $conn->prepare("UPDATE raffle_participants SET review_status = 'expelled' WHERE raffle_id = ? AND user_id = ?");
                        $upd->bind_param("ii", $rid, $uid); $upd->execute();

                        if ($fee > 0) {
                            $conn->query("INSERT IGNORE INTO coins (user_id, balance) VALUES ($uid, 0.00)");
                            $rb = $conn->prepare("UPDATE coins SET balance = balance + ? WHERE user_id = ?");
                            $rb->bind_param("di", $fee, $uid); $rb->execute();
                            $refId = (string)$rid;
                            $tx = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, created_at) VALUES (?, ?, 'raffle_expel_refund', 'raffle_id', ?, UTC_TIMESTAMP())");
                            if ($tx) { $tx->bind_param("ids", $uid, $fee, $refId); $tx->execute(); }
                        }

                        $stkReason = $reason !== '' ? "Ban: $reason" : 'Expulsado por baneo';
                        $stk = $conn->prepare("INSERT INTO raffle_strikes (user_id, raffle_id, reason, created_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())");
                        if ($stk) { $stk->bind_param("iisi", $uid, $rid, $stkReason, $adminId); $stk->execute(); }

                        $sigTbl = $conn->query("SHOW TABLES LIKE 'raffle_entry_signals'");
                        if ($sigTbl && $sigTbl->num_rows > 0) {
                            $ds = $conn->prepare("DELETE FROM raffle_entry_signals WHERE raffle_id = ? AND user_id = ?");
                            $ds->bind_param("ii", $rid, $uid); $ds->execute();
                        }

                        $expelTxt = ' Además se le expulsó del sorteo actual (su ticket queda como hueco' . ($fee > 0 ? ', se le devolvieron ' . number_format($fee, 2) . ' monedas' : '') . ').';
                    } else {
                        $expelTxt = " OJO: sigue inscrito en el sorteo actual porque el roster ya está congelado (estado: $rStatus); el baneo aplica desde los próximos sorteos.";
                    }
                }
            }

            $durTxt = $banType === 'permanent' ? 'permanente' : ($banType === 'until_date' ? "hasta $expiresAt (UTC)" : "por $rafflesN sorteo(s)");
            $response = ['status' => 'success', 'message' => "Baneo de sorteos aplicado ($durTxt)." . ($ipSnap !== '' || $fpSnap !== '' ? ' Se guardó IP/huella para bloquear multicuentas.' : ' Sin IP/huella registrada aún (se bloqueará solo por cuenta).') . $expelTxt];
        }

        // --- GESTIÓN DE BANEOS: DESBANEAR / WHITELIST (1 clic) ---
        // Marca el ban como levantado (conserva historial) y desactiva de inmediato
        // el bloqueo por cuenta, IP y huella asociados a ese ban.
        elseif ($action === 'cc_unban') {
            $banId = (int)($_POST['ban_id'] ?? 0);
            if ($banId <= 0) throw new Exception("Baneo inválido.");
            rb_ensure_schema($conn);

            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            $upd = $conn->prepare("UPDATE raffle_bans SET lifted_at = UTC_TIMESTAMP(), lifted_by = ? WHERE id = ? AND lifted_at IS NULL");
            $upd->bind_param("ii", $adminId, $banId);
            $upd->execute();
            if ($upd->affected_rows <= 0) throw new Exception("Ese baneo ya estaba levantado o no existe.");

            $logTbl = $conn->query("SHOW TABLES LIKE 'admin_actions_log'");
            if ($adminId > 0 && $logTbl && $logTbl->num_rows > 0) {
                $tuQ = $conn->prepare("SELECT user_id FROM raffle_bans WHERE id = ?");
                $tuQ->bind_param("i", $banId); $tuQ->execute();
                $tUid = (int)($tuQ->get_result()->fetch_assoc()['user_id'] ?? 0);
                $det = "raffle_unban:ban_id=$banId";
                $lg = $conn->prepare("INSERT INTO admin_actions_log (admin_id, target_user_id, action, details) VALUES (?, ?, 'raffle_unban', ?)");
                if ($lg) { $lg->bind_param("iis", $adminId, $tUid, $det); $lg->execute(); }
            }

            $response = ['status' => 'success', 'message' => 'Baneo levantado: el usuario ya puede participar inmediatamente.'];
        }

        // --- SALA DE MANDO: RENOMBRAR (nick público y/o nick Epic) ---
        elseif ($action === 'cc_rename') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $newName = trim((string)($_POST['new_name'] ?? ''));
            if ($uid <= 0) throw new Exception("Usuario inválido.");
            if ($newName === '' || mb_strlen($newName) > 50) throw new Exception("Nombre inválido (1 a 50 caracteres).");

            // Cambiamos el nombre PÚBLICO visible (no el login). Si está vacío en BD
            // se mostraba game_username; ahora forzamos public_display_name.
            $upd = $conn->prepare("UPDATE users SET public_display_name = ? WHERE id = ?");
            $upd->bind_param("si", $newName, $uid);
            $upd->execute();

            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            $logTbl = $conn->query("SHOW TABLES LIKE 'admin_actions_log'");
            if ($adminId > 0 && $logTbl && $logTbl->num_rows > 0) {
                $lg = $conn->prepare("INSERT INTO admin_actions_log (admin_id, target_user_id, action, details) VALUES (?, ?, 'rename_public', ?)");
                if ($lg) { $lg->bind_param("iis", $adminId, $uid, $newName); $lg->execute(); }
            }

            $response = ['status' => 'success', 'message' => 'Nombre público actualizado.', 'new_name' => $newName];
        }

        // --- SALA DE MANDO: AÑADIR A LISTA BLANCA ---
        elseif ($action === 'cc_whitelist_add') {
            $rid = (int)($_POST['raffle_id'] ?? 0);
            $uid = (int)($_POST['user_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($rid <= 0 || $uid <= 0) throw new Exception("Datos inválidos.");
            cc_ensure_whitelist($conn);

            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            $ins = $conn->prepare(
                "INSERT INTO raffle_whitelist (raffle_id, user_id, reason, added_by, created_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason), added_by = VALUES(added_by)"
            );
            $ins->bind_param("iisi", $rid, $uid, $reason, $adminId);
            $ins->execute();

            $response = ['status' => 'success', 'message' => 'Usuario añadido a la lista blanca: podrá entrar saltándose las reglas.'];
        }

        // --- SALA DE MANDO: QUITAR DE LISTA BLANCA ---
        elseif ($action === 'cc_whitelist_remove') {
            $rid = (int)($_POST['raffle_id'] ?? 0);
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($rid <= 0 || $uid <= 0) throw new Exception("Datos inválidos.");
            cc_ensure_whitelist($conn);

            $del = $conn->prepare("DELETE FROM raffle_whitelist WHERE raffle_id = ? AND user_id = ?");
            $del->bind_param("ii", $rid, $uid);
            $del->execute();

            $response = ['status' => 'success', 'message' => 'Usuario quitado de la lista blanca.'];
        }

        // --- SORTEO PRIVADO: LISTA DE INVITADOS ---
        elseif ($action === 'rp_invite_list') {
            $rid = (int)($_GET['raffle_id'] ?? 0);
            if ($rid <= 0) throw new Exception("Sorteo inválido.");
            rp_ensure_schema($conn);
            $invited = [];
            foreach (rp_invite_rows($conn, $rid) as $u) {
                $invited[] = [
                    'user_id' => (int)$u['user_id'],
                    'name' => $u['public_display_name'] ?: $u['game_username'],
                    'game_username' => $u['game_username'],
                    'public_code' => $u['public_code'],
                    'phone' => $u['whatsapp_phone_e164'],
                    'avatar' => AvatarCatalog::filename($conn, $u['avatar'] ?? null),
                    'is_verified' => (int)$u['is_verified'],
                    'note' => (string)$u['note'],
                    'is_participant' => ((int)$u['is_part']) > 0,
                    'created_at' => $u['created_at'],
                ];
            }
            $response = ['status' => 'success', 'invited' => $invited, 'count' => count($invited)];
        }

        // --- SORTEO PRIVADO: INVITAR USUARIO ---
        elseif ($action === 'rp_invite_add') {
            $rid = (int)($_POST['raffle_id'] ?? 0);
            $uid = (int)($_POST['user_id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($rid <= 0 || $uid <= 0) throw new Exception("Datos inválidos.");
            rp_ensure_schema($conn);

            $chkU = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $chkU->bind_param("i", $uid);
            $chkU->execute();
            if ($chkU->get_result()->num_rows === 0) throw new Exception("Usuario no encontrado.");

            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            rp_invite_add($conn, $rid, $uid, $note, $adminId);
            $response = ['status' => 'success', 'message' => 'Usuario añadido a la lista de invitados.', 'count' => rp_invite_count($conn, $rid)];
        }

        // --- SORTEO PRIVADO: QUITAR INVITACIÓN ---
        elseif ($action === 'rp_invite_remove') {
            $rid = (int)($_POST['raffle_id'] ?? 0);
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($rid <= 0 || $uid <= 0) throw new Exception("Datos inválidos.");
            rp_ensure_schema($conn);
            rp_invite_remove($conn, $rid, $uid);
            $response = ['status' => 'success', 'message' => 'Invitación retirada.', 'count' => rp_invite_count($conn, $rid)];
        }

        // --- COMPRAS MANUALES: registrar (Revisión Diferida / dinero real) ---
        // Inserta en raffle_manual_purchases con la fecha REAL de la compra por
        // chat. Para el siguiente sorteo el sistema la sumará en la elegibilidad.
        elseif ($action === 'mp_add') {
            $uid    = (int)($_POST['user_id'] ?? 0);
            $pdate  = trim((string)($_POST['purchase_date'] ?? ''));
            $amount = (float)($_POST['amount'] ?? 0);
            $channel = trim((string)($_POST['channel'] ?? 'manual'));
            $note   = trim((string)($_POST['note'] ?? ''));
            $via    = trim((string)($_POST['via'] ?? ''));
            $subcat = trim((string)($_POST['subcategory'] ?? ''));
            $force  = (int)($_POST['force'] ?? 0) === 1;
            if ($uid <= 0) throw new Exception("Usuario inválido.");
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdate)) throw new Exception("Fecha de compra inválida (formato AAAA-MM-DD).");
            if ($amount <= 0) throw new Exception("El monto debe ser mayor a 0.");
            if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);
            if ($via !== '' && !in_array($via, ['regalo', 'cuenta'], true)) throw new Exception("Modalidad inválida.");
            if ($via === 'cuenta' && !in_array($subcat, ['club', 'pavos', 'packs'], true)) throw new Exception("Selecciona la subcategoría (Club, Pavos o Packs).");

            // Anti-duplicados: si ya existe un pedido web completado o una compra
            // manual con la MISMA fecha y monto, el admin debe confirmar (force=1).
            if (!$force) {
                $dupWeb = $conn->prepare(
                    "SELECT codigo_pedido, total_pagar FROM pedidos
                     WHERE user_id = ? AND estado = 'completado' AND DATE(fecha_creacion) = ? AND ABS(total_pagar - ?) < 0.01 LIMIT 3"
                );
                $dupWeb->bind_param("isd", $uid, $pdate, $amount);
                $dupWeb->execute();
                $dupsW = $dupWeb->get_result()->fetch_all(MYSQLI_ASSOC);

                $dupMan = $conn->prepare(
                    "SELECT id, amount FROM raffle_manual_purchases
                     WHERE user_id = ? AND purchase_date = ? AND ABS(amount - ?) < 0.01 LIMIT 3"
                );
                $dupMan->bind_param("isd", $uid, $pdate, $amount);
                $dupMan->execute();
                $dupsM = $dupMan->get_result()->fetch_all(MYSQLI_ASSOC);

                if ($dupsW || $dupsM) {
                    $parts = [];
                    foreach ($dupsW as $d) $parts[] = "Pedido web " . ($d['codigo_pedido'] ?: 's/c') . " · S/" . number_format((float)$d['total_pagar'], 2);
                    foreach ($dupsM as $d) $parts[] = "Compra manual #" . $d['id'] . " · S/" . number_format((float)$d['amount'], 2);
                    $response = [
                        'status' => 'duplicate_warning',
                        'message' => "Ya existe un registro con la misma fecha y monto:\n" . implode("\n", $parts),
                        'matches' => $parts,
                    ];
                    $conn->commit();
                    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    exit;
                }
            }

            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            $newId = rr_add_manual_purchase($conn, $uid, $pdate, $amount, $channel, $note, $adminId, $via !== '' ? $via : null, $subcat !== '' ? $subcat : null);
            if ($newId <= 0) throw new Exception("No se pudo registrar la compra manual.");

            $logTbl = $conn->query("SHOW TABLES LIKE 'admin_actions_log'");
            if ($adminId > 0 && $logTbl && $logTbl->num_rows > 0) {
                $viaTxt = $via === 'cuenta' ? "cuenta/$subcat" : ($via ?: $channel);
                $detail = "Compra manual: $pdate · S/" . number_format($amount, 2) . " ($viaTxt)";
                $lg = $conn->prepare("INSERT INTO admin_actions_log (admin_id, target_user_id, action, details) VALUES (?, ?, 'manual_purchase_add', ?)");
                if ($lg) { $lg->bind_param("iis", $adminId, $uid, $detail); $lg->execute(); }
            }

            $response = ['status' => 'success', 'id' => $newId, 'message' => 'Compra registrada. Contará en el próximo sorteo según la fecha.'];
        }

        // --- COMPRAS MANUALES: eliminar un registro ---
        elseif ($action === 'mp_delete') {
            $mpId = (int)($_POST['id'] ?? 0);
            if ($mpId <= 0) throw new Exception("Registro inválido.");
            $del = $conn->prepare("DELETE FROM raffle_manual_purchases WHERE id = ?");
            $del->bind_param("i", $mpId); $del->execute();
            $response = ['status' => 'success', 'message' => 'Compra manual eliminada.'];
        }

        // --- PRUEBA DE ENTREGA: cambiar estado Pendiente/Entregado ---
        elseif ($action === 'cc_set_delivery_status') {
            $winnerId = (int)($_POST['winner_id'] ?? 0);
            $newStatus = trim((string)($_POST['delivery_status'] ?? ''));
            if ($winnerId <= 0) throw new Exception('Ganador inválido.');
            if (!in_array($newStatus, ['pending', 'delivered'], true)) throw new Exception('Estado inválido.');
            sf_require_manual_delivery($conn, $winnerId);

            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            if ($newStatus === 'delivered') {
                $upd = $conn->prepare(
                    "UPDATE raffle_winners
                     SET delivery_status = 'delivered', delivered_at = UTC_TIMESTAMP(), delivered_by = ?
                     WHERE id = ?"
                );
                $upd->bind_param("ii", $adminId, $winnerId);
            } else {
                // Vuelve a pendiente: limpiamos la marca temporal de entrega
                // (conservamos la imagen subida por si se vuelve a marcar).
                $upd = $conn->prepare(
                    "UPDATE raffle_winners
                     SET delivery_status = 'pending', delivered_at = NULL, delivered_by = NULL
                     WHERE id = ?"
                );
                $upd->bind_param("i", $winnerId);
            }
            if (!$upd->execute()) throw new Exception('No se pudo actualizar el estado.');
            $response = ['status' => 'success', 'message' => ($newStatus === 'delivered') ? 'Marcado como entregado.' : 'Marcado como pendiente.'];
        }

        // --- PRUEBA DE ENTREGA: guardar/editar la nota pública sin imagen ---
        // No toca el estado de entrega ni las imágenes. Nota vacía = borrarla.
        elseif ($action === 'cc_set_delivery_note') {
            $winnerId = (int)($_POST['winner_id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($winnerId <= 0) throw new Exception('Ganador inválido.');
            sf_require_manual_delivery($conn, $winnerId);
            if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

            if ($note !== '') {
                $upd = $conn->prepare("UPDATE raffle_winners SET delivery_note = ? WHERE id = ?");
                $upd->bind_param("si", $note, $winnerId);
            } else {
                $upd = $conn->prepare("UPDATE raffle_winners SET delivery_note = NULL WHERE id = ?");
                $upd->bind_param("i", $winnerId);
            }
            if (!$upd->execute()) throw new Exception('No se pudo guardar la nota.');
            $response = ['status' => 'success', 'message' => ($note !== '') ? 'Nota guardada.' : 'Nota eliminada.'];
        }

        // --- PRUEBA DE ENTREGA: subir imagen de prueba ---
        // Multipart: winner_id + file (+ note opcional). Al subir, marca el premio
        // como "delivered" automáticamente. Reemplaza/borra una imagen previa.
        elseif ($action === 'cc_upload_delivery_proof') {
            $winnerId = (int)($_POST['winner_id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($winnerId <= 0) throw new Exception('Ganador inválido.');
            sf_require_manual_delivery($conn, $winnerId);
            if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

            // Normalizamos la entrada: acepta un único `file` o varios `file[]`
            // para poder subir las 2 imágenes del collage de una sola vez.
            $incoming = [];
            if (!empty($_FILES['file']) && is_array($_FILES['file']['name'])) {
                $n = count($_FILES['file']['name']);
                for ($i = 0; $i < $n; $i++) {
                    $incoming[] = [
                        'name' => $_FILES['file']['name'][$i],
                        'type' => $_FILES['file']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['file']['tmp_name'][$i] ?? '',
                        'error' => $_FILES['file']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['file']['size'][$i] ?? 0,
                    ];
                }
            } elseif (!empty($_FILES['file'])) {
                $incoming[] = $_FILES['file'];
            }
            // Descartamos slots vacíos.
            $incoming = array_values(array_filter($incoming, function ($f) { return ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE; }));
            if (empty($incoming)) throw new Exception('No se recibió ninguna imagen.');

            $proofDir = sf_proof_dir();
            if ($proofDir === '') throw new Exception('No se pudo preparar el directorio de pruebas.');

            // Lista actual de pruebas para acumular (no reemplazar).
            $prevStmt = $conn->prepare("SELECT delivery_proof_image FROM raffle_winners WHERE id = ?");
            $prevStmt->bind_param("i", $winnerId);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_assoc();
            if (!$prevRow) throw new Exception('Ganador no encontrado.');
            $proofList = sf_parse_proofs($prevRow['delivery_proof_image'] ?? null);

            $available = SF_MAX_PROOFS - count($proofList);
            if ($available <= 0) throw new Exception('Ya se alcanzó el máximo de ' . SF_MAX_PROOFS . ' imágenes. Elimina alguna para subir otra.');
            if (count($incoming) > $available) throw new Exception('Solo puedes subir ' . $available . ' imagen(es) más (máx ' . SF_MAX_PROOFS . ').');

            $saved = [];
            foreach ($incoming as $file) {
                if ($file['error'] !== UPLOAD_ERR_OK) { throw new Exception('Hubo un error al recibir una de las imágenes.'); }
                if ($file['size'] > 8 * 1024 * 1024) throw new Exception('Una imagen es demasiado grande (máx 8MB).');
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) throw new Exception('Formato no permitido. Use PNG, JPG o WEBP.');
                if (@getimagesize($file['tmp_name']) === false) throw new Exception('Uno de los archivos no es una imagen válida.');

                $filename = uniqid('proof_') . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], $proofDir . DIRECTORY_SEPARATOR . $filename)) {
                    // Limpiamos lo ya guardado en este lote si algo falla.
                    foreach ($saved as $s) { @unlink($proofDir . DIRECTORY_SEPARATOR . $s); }
                    throw new Exception('No se pudo guardar una imagen en el servidor.');
                }
                $saved[] = $filename;
            }

            $proofList = array_merge($proofList, $saved);
            $proofValue = implode(',', $proofList);

            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            // La nota solo se sobrescribe si el admin envió una (no la borramos
            // al añadir una segunda imagen sin nota).
            if ($note !== '') {
                $upd = $conn->prepare(
                    "UPDATE raffle_winners
                     SET delivery_proof_image = ?, delivery_note = ?, delivery_status = 'delivered',
                         delivered_at = UTC_TIMESTAMP(), delivered_by = ?
                     WHERE id = ?"
                );
                $upd->bind_param("ssii", $proofValue, $note, $adminId, $winnerId);
            } else {
                $upd = $conn->prepare(
                    "UPDATE raffle_winners
                     SET delivery_proof_image = ?, delivery_status = 'delivered',
                         delivered_at = UTC_TIMESTAMP(), delivered_by = ?
                     WHERE id = ?"
                );
                $upd->bind_param("sii", $proofValue, $adminId, $winnerId);
            }
            if (!$upd->execute()) {
                foreach ($saved as $s) { @unlink($proofDir . DIRECTORY_SEPARATOR . $s); }
                throw new Exception('No se pudo registrar la prueba en la base de datos.');
            }

            $allUrls = array_map(function ($n) { return '/assets/raffle_proofs/' . rawurlencode($n); }, $proofList);
            $response = [
                'status' => 'success',
                'message' => count($saved) > 1 ? 'Pruebas subidas. Premio marcado como entregado.' : 'Prueba subida. Premio marcado como entregado.',
                'proof_images' => $proofList,
                'proof_urls' => $allUrls,
                'proof_count' => count($proofList),
            ];
        }

        // --- PRUEBA DE ENTREGA: eliminar imagen(es) de prueba ---
        // Sin `image`: borra todas. Con `image`=<filename>: borra solo esa.
        elseif ($action === 'cc_delete_delivery_proof') {
            $winnerId = (int)($_POST['winner_id'] ?? 0);
            $target = basename(trim((string)($_POST['image'] ?? '')));
            if ($winnerId <= 0) throw new Exception('Ganador inválido.');
            sf_require_manual_delivery($conn, $winnerId);

            $get = $conn->prepare("SELECT delivery_proof_image FROM raffle_winners WHERE id = ?");
            $get->bind_param("i", $winnerId);
            $get->execute();
            $row = $get->get_result()->fetch_assoc();
            if (!$row) throw new Exception('Ganador no encontrado.');
            $proofList = sf_parse_proofs($row['delivery_proof_image'] ?? null);

            $toUnlink = [];
            if ($target !== '') {
                // Borrado individual: quitamos solo la imagen indicada.
                if (!in_array($target, $proofList, true)) throw new Exception('Esa imagen no pertenece a este ganador.');
                $toUnlink[] = $target;
                $proofList = array_values(array_filter($proofList, function ($n) use ($target) { return $n !== $target; }));
            } else {
                // Borrado total.
                $toUnlink = $proofList;
                $proofList = [];
            }

            $remaining = count($proofList) ? implode(',', $proofList) : null;
            // Si ya no quedan imágenes, también limpiamos la nota.
            if ($remaining === null) {
                $upd = $conn->prepare("UPDATE raffle_winners SET delivery_proof_image = NULL, delivery_note = NULL WHERE id = ?");
                $upd->bind_param("i", $winnerId);
            } else {
                $upd = $conn->prepare("UPDATE raffle_winners SET delivery_proof_image = ? WHERE id = ?");
                $upd->bind_param("si", $remaining, $winnerId);
            }
            if (!$upd->execute()) throw new Exception('No se pudo eliminar la prueba.');

            $proofDir = sf_proof_dir();
            if ($proofDir !== '') {
                foreach ($toUnlink as $img) {
                    $path = $proofDir . DIRECTORY_SEPARATOR . basename($img);
                    if (is_file($path)) @unlink($path);
                }
            }

            $allUrls = array_map(function ($n) { return '/assets/raffle_proofs/' . rawurlencode($n); }, $proofList);
            $response = [
                'status' => 'success',
                'message' => ($target !== '') ? 'Imagen eliminada.' : 'Pruebas eliminadas.',
                'proof_images' => $proofList,
                'proof_urls' => $allUrls,
                'proof_count' => count($proofList),
            ];
        }

        $conn->commit();
    }

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    http_response_code(500);
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
?>
<?php
/**
 * ============================================================================
 * CUYSITO.COM - UNIFIED API GATEWAY (web.php)
 * ============================================================================
 * Single entry point for all API requests. Hides internal file structure
 * and logic paths from the public Network tab.
 *
 * Usage:  POST /web.php   (all parameters in the request body)
 * Example: body: action=get_rarities
 *          body: action=cart_manager&sub=get
 *          body: {"action":"get_item_details","id":"CID_028_Athena_Commando_F"}
 *
 * Security:
 *  - Only whitelisted actions are executable.
 *  - Unknown actions receive a generic 404 (no information leakage).
 *  - Internal file paths are never exposed to the client.
 *
 * @version 2.1.0 (POST-only + Action Name Obfuscation)
 * ============================================================================
 */

// --- SECURITY HEADERS ---
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

// --- ENVIRONMENT (origin lock + real client IP behind Cloudflare) ---
require_once dirname(__DIR__) . '/cuysito_app/config/app_env.php';
if (!banano_origin_lock_passes()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
banano_apply_cloudflare_real_ip();
if (!banano_is_production()) {
    header('X-Robots-Tag: noindex, nofollow');
}

// --- RUNTIME MODE & SYNC CONFIG ---
define('MODO_DEBUG', true);
define('CART_SYNC_TOKEN', 'c4rt_j50n_t0k3n_9f8a1b2c3d4e5f6g');

// --- CORE CONFIG ---
$app_path = dirname(__DIR__) . '/cuysito_app';

// --- ACTION WHITELIST ---
// Maps public route names → internal file paths (relative to cuysito_app/).
// ONLY actions listed here can be executed. Everything else returns 404.
$action_map = [
    // Fortnite item helpers (internal: snapshot + public cosmetics endpoint)
    'get_item_details'        => '/api/get_item_details.php',
    'get_item_page'           => '/api/get_item_page.php',
    'get_rarities'            => '/api/get_rarities.php',

    // Cart
    'cart_manager'            => '/api/cart_manager.php',

    // Checkout & Orders
    'get_checkout_state'      => '/api/get_checkout_state.php',
    'validar_cupon'           => '/api/validar_cupon.php',
    'process_checkout'        => '/checkout/process.php',
    'check_order_status'      => '/api/check_order_status.php',
    'notify_payment'          => '/api/notify_payment.php',
    'get_gift_availability'   => '/api/get_gift_availability.php',
    'submit_friend_request'   => '/api/submit_friend_request.php',

    // Shop data
    'get_productos_estaticos' => '/api/get_productos_estaticos.php',
    'get_ofertas_automaticas' => '/api/get_ofertas_automaticas.php',
    'obtener_oferta_diaria'   => '/api/obtener_oferta_diaria.php',
    'gestor_descuentos'       => '/api/gestor_descuentos.php',

    // User / Session
    'get_my_discounts'        => '/api/get_my_discounts.php',
    'get_spin_history'        => '/api/get_spin_history.php',
    'get_verification_status' => '/api/get_verification_status.php',
    'request_verification'    => '/api/request_verification.php',
    'marcar_tutorial_visto'   => '/api/marcar_tutorial_visto.php',

    // Daily Spin
    'spin_init'               => '/api/spin_init.php',
    'spin_go'                 => '/api/spin_go.php',

    // Raffle / Sorteo Flash
    'raffle_status'           => '/api/raffle_status.php',
    'raffle_hub_status'       => '/api/raffle_hub_status.php',
    'raffle_join'             => '/api/raffle_join.php',
    'raffle_gamble'           => '/api/raffle_gamble.php',
    'raffle_prechoice'        => '/api/raffle_prechoice.php',
    'raffle_claim_item'       => '/api/raffle_claim_item.php',

    // Media
    'get_video'               => '/api/get_video.php',
    'video_community'         => '/api/video_community.php',

    // Analytics (fire-and-forget)
    'track_page_view'         => '/api/track_page_view.php',
    'track_product_click'     => '/api/track_product_click.php',

    // Account / Dashboard
    'api_cuenta'              => '/cuenta/api_cuenta.php',
];

// --- ACTION NAME OBFUSCATION (Phase 2) ---
// Maps meaningless short codes → real action names.
// Both raw names AND obfuscated codes are accepted for backward compatibility.
// Once all frontend JS files are migrated, raw names can be removed from acceptance.
$obfuscation_map = [
    // Fortnite item helpers
    'm2q' => 'get_item_details',
    'k3t' => 'get_item_page',
    'v8n' => 'get_rarities',

    // Cart
    'c9d' => 'cart_manager',

    // Checkout & Orders
    'f1z' => 'get_checkout_state',
    'q5s' => 'validar_cupon',
    'p9c' => 'process_checkout',
    'w3k' => 'check_order_status',
    'b8m' => 'notify_payment',
    'n7f' => 'get_gift_availability',
    'x2p' => 'submit_friend_request',

    // Shop data
    'h4v' => 'get_productos_estaticos',
    'y9a' => 'get_ofertas_automaticas',
    'd6j' => 'obtener_oferta_diaria',
    'g1c' => 'gestor_descuentos',

    // User / Session
    'e5u' => 'get_my_discounts',
    'h6k' => 'get_spin_history',
    'i3n' => 'get_verification_status',
    'u7b' => 'request_verification',
    'mtv' => 'marcar_tutorial_visto',

    // Daily Spin
    's4w' => 'spin_init',
    'z2f' => 'spin_go',

    // Raffle / Sorteo Flash
    'a8k' => 'raffle_status',
    'u2h' => 'raffle_hub_status',
    'o6m' => 'raffle_join',
    'l3p' => 'raffle_gamble',
    'r9k' => 'raffle_prechoice',
    'c7q' => 'raffle_claim_item',

    // Media
    't9h' => 'get_video',
    'v5c' => 'video_community',

    // Analytics (fire-and-forget)
    'w1v' => 'track_page_view',
    'n4x' => 'track_product_click',

    // Account / Dashboard
    'j5r' => 'api_cuenta',
];

// --- ENFORCE POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// --- EXTRACT ROUTING PARAMS FROM BODY ---
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
$isJson = ($contentType === 'application/json');

if ($isJson) {
    // JSON body: read php://input (re-readable in PHP 7+ for non-multipart)
    $rawInput = file_get_contents('php://input');
    $jsonInput = json_decode($rawInput, true) ?: [];
    $action         = $jsonInput['action']  ?? '';
    $sub            = $jsonInput['sub']     ?? '';
    $methodOverride = $jsonInput['_method'] ?? '';
} else {
    // Form-encoded / multipart: read from $_POST
    $action         = $_POST['action']  ?? '';
    $sub            = $_POST['sub']     ?? '';
    $methodOverride = $_POST['_method'] ?? '';
}

// --- OBFUSCATION RESOLUTION ---
// If the incoming action is a short code, resolve it to the real action name.
// Raw action names still pass through unchanged (backward compatible).
if ($action !== '' && !isset($action_map[$action]) && isset($obfuscation_map[$action])) {
    $action = $obfuscation_map[$action];
}

// --- ROUTE RESOLUTION ---
if ($action === '' || !isset($action_map[$action])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not Found']);
    exit;
}

$target_file = $app_path . $action_map[$action];

// Defense in depth: verify file exists on disk
if (!file_exists($target_file)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not Found']);
    exit;
}

// --- METHOD OVERRIDE ---
// For internal REST-style APIs that route by HTTP method.
// Frontend can send _method=GET|DELETE in the body to override.
$allowed_overrides = ['GET', 'DELETE', 'PUT', 'PATCH'];
if ($methodOverride !== '' && in_array(strtoupper($methodOverride), $allowed_overrides, true)) {
    $_SERVER['REQUEST_METHOD'] = strtoupper($methodOverride);
}

// --- PARAMETER REMAPPING ---
// Copy payload params into $_GET so internal files that read
// $_GET['id'], $_GET['order_code'], etc. still work.
$routing_keys = ['action', 'sub', '_method'];

if ($isJson) {
    // Copy scalar JSON params (except routing keys) to $_GET/$_REQUEST
    foreach ($jsonInput as $k => $v) {
        if (!in_array($k, $routing_keys, true) && is_scalar($v)) {
            $_GET[$k]     = (string) $v;
            $_REQUEST[$k] = (string) $v;
        }
    }
} else {
    // Form-encoded: remove routing keys from $_POST, copy rest to $_GET
    foreach ($routing_keys as $rk) {
        unset($_POST[$rk]);
    }
    foreach ($_POST as $k => $v) {
        if (is_scalar($v)) {
            $_GET[$k]     = $v;
            $_REQUEST[$k] = $v;
        }
    }
}

// Clean the gateway's action from superglobals
unset($_GET['action'], $_REQUEST['action']);

// Restore the correct 'action' for internal files:
//   'sub' → promote to 'action' (cart_manager, raffle_gamble, api_cuenta, etc.)
if ($sub !== '') {
    $_GET['action']     = $sub;
    $_REQUEST['action'] = $sub;
}

// Rebuild QUERY_STRING to reflect cleaned parameters
$_SERVER['QUERY_STRING'] = http_build_query($_GET);

// --- STATIC FILE SERVING ---
// If the target is a non-PHP file (e.g. .json), serve it directly.
$ext = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
if ($ext !== 'php') {
    $mime_map = ['json' => 'application/json', 'html' => 'text/html', 'xml' => 'application/xml'];
    header('Content-Type: ' . ($mime_map[$ext] ?? 'application/octet-stream') . '; charset=utf-8');
    readfile($target_file);
    exit;
}

// --- EXECUTE ---
// Change working directory so relative require/include paths inside
// the target file resolve correctly (e.g. require_once 'db_connect.php').
chdir(dirname($target_file));

require $target_file;

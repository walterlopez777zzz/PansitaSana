<?php
// router.php - Cuysito.com SPA Front Controller
// Following banano.pe minimalist blueprint

require_once dirname(__DIR__) . '/cuysito_app/config/app_env.php';
require_once dirname(__DIR__) . '/cuysito_app/config/cloudflare_access.php';

$ENV_CONFIG    = banano_env_config();
$IS_PRODUCTION = banano_is_production();

ini_set('display_errors', $IS_PRODUCTION ? '0' : '1');
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ===================== ORIGIN LOCK =====================
// With Cloudflare in front, refuse anything that did not come through it
// (direct hits to the hosting IP would skip Cloudflare Access).
if (!banano_origin_lock_passes()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo 'Forbidden';
    exit;
}

banano_apply_cloudflare_real_ip();

if (!$IS_PRODUCTION) {
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

// ===================== MAINTENANCE MODE =====================
// Set to true  → storefront shows maintenance page (APIs & assets still work).
// Set to false → normal operation. Overridable via env.php `maintenance_mode`.
// env.php `maintenance_until` (ISO 8601 with offset) ends the window
// automatically: from that instant every request routes normally, with no
// cron involved (see banano_maintenance_active()).
$MAINTENANCE_MODE = banano_maintenance_active(false);

if ($MAINTENANCE_MODE) {
    $request_uri_check = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $request_uri_check = rtrim($request_uri_check, '/');

    // Admin bypass: visiting env.php `maintenance_bypass_path` grants a
    // signed 24 h cookie (requires `app_secret`).
    $bypass_path = rtrim((string) $ENV_CONFIG['maintenance_bypass_path'], '/');
    if ($bypass_path !== '' && $request_uri_check === $bypass_path) {
        $bypass_expires = time() + 86400;
        $bypass_token   = banano_signed_token('maintenance-bypass', $bypass_expires);
        if ($bypass_token !== '') {
            setcookie('bypass_maint', $bypass_token, [
                'expires'  => $bypass_expires,
                'path'     => '/',
                'secure'   => banano_request_is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        header('Location: /');
        exit;
    }

    if (banano_verify_signed_token('maintenance-bypass', (string) ($_COOKIE['bypass_maint'] ?? ''))) {
        // fall through to normal routing
    } else {
        // Only intercept storefront SPA routes: root, named views and the
        // item/product deep links that render the same SPA shell.
        $spa_paths = ['', '/viaregalo', '/via-regalo', '/viacuenta', '/via-cuenta', '/juegos'];
        $is_spa_deep_link = pathinfo($request_uri_check, PATHINFO_EXTENSION) === ''
            && preg_match('#^/via-(regalo|cuenta)/#', $request_uri_check) === 1;
        if (in_array($request_uri_check, $spa_paths, true) || $is_spa_deep_link) {
            $maintenance_until = banano_maintenance_until();
            $retry_after = $maintenance_until !== null ? max(1, $maintenance_until - time()) : 3600;
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Retry-After: ' . $retry_after);
            readfile(__DIR__ . '/maintenance.html');
            exit;
        }
    }
}

// ===================== CORE CONFIG =====================
$app_path    = dirname(__DIR__) . '/cuysito_app';
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_uri = rtrim($request_uri, '/');

// ===================== INTERNAL TOOLS =====================
// Production never exposes the internal tools; staging requires a valid
// Cloudflare Access JWT for the ones without their own login. Runs before any
// file under those prefixes is resolved (HTML, JS, JSON and API alike).
if (banano_is_internal_tool_path($request_uri)) {
    if ($IS_PRODUCTION) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo "<h1>404 - Not Found</h1>";
        exit;
    }
    if (banano_is_access_gated_path($request_uri)) {
        banano_access_gate();
    }
}

// ===================== DEBUG LOG =====================
// Enabled on staging by default, off in production (env.php `router_debug_log`).
$router_debug_enabled = is_bool($ENV_CONFIG['router_debug_log']) ? $ENV_CONFIG['router_debug_log'] : !$IS_PRODUCTION;
$log_file = $router_debug_enabled ? $app_path . '/router_debug.log' : null;

function _routerLog($log_file, $message) {
    if ($log_file === null) return;
    @file_put_contents($log_file, $message, FILE_APPEND | LOCK_EX);
}

$real_file_path = $app_path . $request_uri;
$log_message = "\n=== [" . date('Y-m-d H:i:s') . "] ROUTER HIT ===\n"
    . "  URI:        " . $request_uri . "\n"
    . "  Real Path:  " . $real_file_path . "\n"
    . "  Exists?     " . (file_exists($real_file_path) ? 'SI' : 'NO') . "\n"
    . "  Is Dir?     " . (is_dir($real_file_path) ? 'SI' : 'NO') . "\n"
    . "  Extension:  " . strtolower(pathinfo($real_file_path, PATHINFO_EXTENSION)) . "\n"
    . "  GET:        " . json_encode($_GET) . "\n"
    . "  HTTP_HOST:  " . ($_SERVER['HTTP_HOST'] ?? '') . "\n"
    . "  app_path:   " . $app_path . "\n"
    . "  app exists: " . (is_dir($app_path) ? 'SI' : 'NO') . "\n";
_routerLog($log_file, $log_message);
if ($router_debug_enabled) {
    error_log("[ROUTER] URI=" . $request_uri . " | Path=" . $real_file_path . " | Exists=" . (file_exists($real_file_path) ? 'SI' : 'NO'));
}

// ===================== 1. STATIC FILE GUARD =====================
// If the request maps to a static file inside cuysito_app/, serve it directly.
// On staging .htaccess also sends public_html/ assets here (so the origin
// lock and noindex header cover them); those are served from __DIR__.
$static_extensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'webp',
                      'woff', 'woff2', 'ttf', 'eot', 'mp3', 'mp4', 'webm', 'json', 'xml', 'map'];
$request_extension = strtolower(pathinfo($real_file_path, PATHINFO_EXTENSION));

$static_source = null;
if (in_array($request_extension, $static_extensions)) {
    if (file_exists($real_file_path)) {
        $static_source = $real_file_path;
    } elseif ($request_uri !== '') {
        $public_candidate = realpath(__DIR__ . $request_uri);
        if ($public_candidate !== false && is_file($public_candidate)
            && strpos($public_candidate, __DIR__ . DIRECTORY_SEPARATOR) === 0) {
            $static_source = $public_candidate;
        }
    }
}

if ($static_source !== null) {
    $real_file_path = $static_source;
    _routerLog($log_file, "  HANDLER:    STATIC FILE GUARD -> " . $real_file_path . "\n");
    $mime_types = [
        'css'  => 'text/css',           'js'    => 'application/javascript',
        'png'  => 'image/png',          'jpg'   => 'image/jpeg',
        'jpeg' => 'image/jpeg',         'gif'   => 'image/gif',
        'ico'  => 'image/x-icon',       'svg'   => 'image/svg+xml',
        'webp' => 'image/webp',         'woff'  => 'font/woff',
        'woff2'=> 'font/woff2',         'ttf'   => 'font/ttf',
        'eot'  => 'application/vnd.ms-fontobject',
        'mp3'  => 'audio/mpeg',         'mp4'   => 'video/mp4',
        'webm' => 'video/webm',
        'json' => 'application/json',   'xml'   => 'application/xml',
        'map'  => 'application/json',
    ];
    header('Content-Type: ' . ($mime_types[$request_extension] ?? 'application/octet-stream'));
    // Emit Last-Modified + Content-Length so shop-sync HEAD checks work
    $stat = @stat($real_file_path);
    if ($stat) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT');
        header('Content-Length: ' . $stat['size']);
    }
    readfile($real_file_path);
    exit;
}

// ===================== 2. SPECIAL ROUTES =====================
$routes = [
    '#^/checkout/confirmacion/([^/]+)$#' => function($order_code) use ($app_path) {
        $_GET['order_code'] = $order_code;
        require_once $app_path . '/checkout/confirmation.php';
    }
];

foreach ($routes as $pattern => $handler) {
    if (preg_match($pattern, $request_uri, $matches)) {
        array_shift($matches);
        $handler(...$matches);
        exit;
    }
}

// ===================== 2.5 API PATH GUARD =====================
// Block direct access to internal API paths. All API traffic must go through
// /web.php gateway. This prevents competitors from discovering individual
// API filenames via the Network tab.
$blocked_prefixes = ['/api/'];
$blocked_exact    = ['/cuenta/api_cuenta.php', '/cuenta/api_cuenta'];
foreach ($blocked_prefixes as $prefix) {
    if (strpos($request_uri, $prefix) === 0) {
        _routerLog($log_file, "  HANDLER:    BLOCKED (direct API access) -> 404\n");
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not Found']);
        exit;
    }
}
foreach ($blocked_exact as $blocked) {
    if ($request_uri === $blocked) {
        _routerLog($log_file, "  HANDLER:    BLOCKED (direct API access) -> 404\n");
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not Found']);
        exit;
    }
}

// ===================== 3. SPA SHELL =====================
// Root "/" and SPA virtual routes load the main index.php from cuysito_app/
$spa_views = [
    ''            => ['title' => 'Banano Store - Tienda de Fortnite',         'desc' => 'Explora la tienda de objetos de Fortnite actualizada diariamente.', 'data_view' => 'viaregalo'],
    'via-regalo'  => ['title' => 'Vía Regalo - Banano Store',                 'desc' => 'Explora la tienda de objetos de Fortnite actualizada diariamente. Compra vía regalo.', 'data_view' => 'viaregalo'],
    'viaregalo'   => ['title' => 'Vía Regalo - Banano Store',                 'desc' => 'Explora la tienda de objetos de Fortnite actualizada diariamente. Compra vía regalo.', 'data_view' => 'viaregalo'],
    'via-cuenta'  => ['title' => 'Vía Cuenta - Banano Store',                 'desc' => 'Compra productos directamente en tu cuenta de Fortnite.',          'data_view' => 'viacuenta'],
    'viacuenta'   => ['title' => 'Vía Cuenta - Banano Store',                 'desc' => 'Compra productos directamente en tu cuenta de Fortnite.',          'data_view' => 'viacuenta'],
    'juegos'      => ['title' => 'Juegos - Banano Store',                     'desc' => 'Explora nuestra colección de juegos y entretenimiento.',            'data_view' => 'games'],
];

// Item / product pages are SPA deep links too:
//   /via-regalo/{tipo}/{slug}--{id}  (objetos dinámicos de la tienda)
//   /via-cuenta/{slug}--{id}         (productos estáticos)
$item_page_match = null;
// Guard: nunca servir el shell SPA para rutas con extensión de asset
// (p. ej. una referencia relativa rota como /via-regalo/traje/style.css).
// Devolver 404 evita que el navegador reciba HTML como CSS/JS.
$is_static_looking = in_array($request_extension, $static_extensions, true);
if (!$is_static_looking && preg_match('#^/via-regalo/[a-z0-9\-]+/[^/]+$#u', $request_uri)) {
    $item_page_match = ['data_view' => 'item',
                        'title' => 'Objeto - Banano Store',
                        'desc'  => 'Detalles del objeto de Fortnite en Banano Store.'];
} elseif (!$is_static_looking && preg_match('#^/via-cuenta/[^/]+$#u', $request_uri)) {
    $item_page_match = ['data_view' => 'producto',
                        'title' => 'Producto - Banano Store',
                        'desc'  => 'Detalles del producto en Banano Store.'];
}
if ($item_page_match !== null) {
    // Nombre legible desde el slug para el <title> inicial (el cliente lo refina)
    // e identificador del objeto (`{slug}--{id}`, mismo parseo que las rutas de
    // main.js) para resolver el fondo de sección en el primer render.
    $last_segment = rawurldecode((string) substr($request_uri, (int) strrpos($request_uri, '/') + 1));
    $pos = strrpos($last_segment, '--');
    $slug_only = $pos !== false ? substr($last_segment, 0, $pos) : $last_segment;
    $page_item_id = $pos !== false ? substr($last_segment, $pos + 2) : $last_segment;
    $pretty = trim(ucwords(str_replace('-', ' ', $slug_only)));
    if ($pretty !== '') {
        $item_page_match['title'] = $pretty . ' - Banano Store';
    }

    _routerLog($log_file, "  HANDLER:    SPA SHELL (item page)\n");
    $index_path = $app_path . '/index.php';
    if (!file_exists($index_path)) {
        http_response_code(500);
        echo "<h1>Error: index.php not found</h1>";
        exit;
    }
    $PAGE_ROUTE       = $item_page_match['data_view'];
    $PAGE_TITLE       = $item_page_match['title'];
    $PAGE_DESCRIPTION = $item_page_match['desc'];
    $PAGE_DATA_VIEW   = $item_page_match['data_view'];
    $PAGE_ITEM_ID     = $item_page_match['data_view'] === 'item' ? $page_item_id : null;
    include $index_path;
    exit;
}

$path_segment = ltrim($request_uri, '/');
if ($request_uri === '' || $request_uri === '/' || array_key_exists($path_segment, $spa_views)) {
    $view_key    = ($request_uri === '' || $request_uri === '/') ? '' : $path_segment;
    $view_config = $spa_views[$view_key];
    _routerLog($log_file, "  HANDLER:    SPA SHELL (view=" . ($view_key ?: 'home') . ")\n");
    $index_path  = $app_path . '/index.php';

    if (!file_exists($index_path)) {
        http_response_code(500);
        echo "<h1>Error: index.php not found at " . htmlspecialchars($index_path) . "</h1>";
        exit;
    }

    // Set variables that index.php can use
    $PAGE_ROUTE       = $view_key ?: 'viaregalo';
    $PAGE_TITLE       = $view_config['title'];
    $PAGE_DESCRIPTION = $view_config['desc'];
    $PAGE_DATA_VIEW   = $view_config['data_view'];

    // index.php renders the per-route <title>, meta description and
    // <body data-view> directly from the $PAGE_* variables set above, so no
    // brittle regex post-processing of the captured HTML is needed anymore.
    include $index_path;
    exit;
}

// ===================== 4. GENERIC FILE LOADER =====================
// For everything else (cuenta/, wabo/, checkout/, login/, juegos/, api/, etc.)
// resolve the file from cuysito_app/ — just like banano.pe does.
$file_to_load = $app_path . $request_uri;

// Special case: /wabo with no trailing path → login page (must be before dir-slash redirect)
if ($request_uri === '/wabo') {
    $file_to_load = $app_path . '/wabo/login.php';
}

// Directory trailing-slash redirect (mirrors Apache DirectorySlash).
// Without this, /login serves index.php but relative URLs like "google_login.php"
// resolve against "/" instead of "/login/".
$_raw_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_dir($file_to_load) && substr($_raw_path, -1) !== '/') {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $redirect = $request_uri . '/' . ($query ? '?' . $query : '');
    _routerLog($log_file, "  HANDLER:    DIR SLASH REDIRECT -> " . $redirect . "\n");
    header('Location: ' . $redirect, true, 301);
    exit;
}

// Helper: find index file in a directory (index.php first, then index.html)
function _findDirIndex($dir) {
    if (file_exists($dir . '/index.php'))  return $dir . '/index.php';
    if (file_exists($dir . '/index.html')) return $dir . '/index.html';
    return null;
}

// Helper: serve a file (require PHP, readfile HTML)
function _serveFile($path, $log_file, $label = '') {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    _routerLog($log_file, "  ACTION:     serve " . $path . " (" . $label . ")\n");
    if ($ext === 'html') {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($path);
    } else {
        require_once $path;
    }
    exit;
}

// If the path is a directory, try index.php or index.html inside it
if (is_dir($file_to_load)) {
    $dir_index = _findDirIndex($file_to_load);
    if ($dir_index) $file_to_load = $dir_index;
}

// Log the resolution
$_loaderLog = "  HANDLER:    GENERIC LOADER\n"
    . "  file_to_load: " . $file_to_load . "\n"
    . "  exists?     " . (file_exists($file_to_load) ? 'SI' : 'NO') . "\n";

// Try to load the file directly
if (file_exists($file_to_load) && !is_dir($file_to_load)) {
    _routerLog($log_file, $_loaderLog);
    _serveFile($file_to_load, $log_file, 'direct');
}

// Try appending .php (for routes like /cuenta/api_cuenta?action=...)
if (file_exists($file_to_load . '.php')) {
    _routerLog($log_file, $_loaderLog);
    _serveFile($file_to_load . '.php', $log_file, 'auto-appended .php');
}

// Parent directory fallback for sub-SPA pushState routes.
// E.g., /cuenta/ordenes → file not found → check /cuenta/ for index.php|html
// This handles client-side routing in sub-apps like the account dashboard.
$_segments = explode('/', ltrim($request_uri, '/'));
while (count($_segments) > 1) {
    array_pop($_segments);
    $parent_dir = $app_path . '/' . implode('/', $_segments);
    if (is_dir($parent_dir)) {
        $parent_index = _findDirIndex($parent_dir);
        if ($parent_index) {
            $_loaderLog .= "  FALLBACK:   parent dir index -> " . $parent_index . "\n";
            _routerLog($log_file, $_loaderLog);
            _serveFile($parent_index, $log_file, 'parent-dir fallback');
        }
    }
}

// Nothing found → 404
$_loaderLog .= "  ACTION:     404 — file not found\n"
    . "  Tried:      " . $file_to_load . "\n"
    . "  Also tried: " . $file_to_load . ".php\n"
    . "  Also tried: parent dir fallback\n";
_routerLog($log_file, $_loaderLog);
if ($router_debug_enabled) {
    error_log("[ROUTER] 404: " . $file_to_load . " (URI: " . $request_uri . ")");
}

http_response_code(404);
// Return JSON for API/PHP requests, HTML for everything else
if ($request_extension === 'php' || strpos($request_uri, '/api/') === 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => '404 Not Found', 'path' => $request_uri]);
} else {
    echo "<h1>404 - Not Found</h1>";
    echo "<p>Router could not find: " . htmlspecialchars($request_uri) . "</p>";
}

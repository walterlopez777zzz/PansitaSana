<?php
// /public_html/api/session_security.php

$banano_request_uri = $_SERVER['REQUEST_URI'] ?? '';
$banano_request_path = '';
if (is_string($banano_request_uri) && $banano_request_uri !== '') {
    $banano_request_path = (string)(parse_url($banano_request_uri, PHP_URL_PATH) ?? '');
}

$banano_is_wabo_request = false;
foreach ([$banano_request_path, ($_SERVER['SCRIPT_NAME'] ?? ''), ($_SERVER['PHP_SELF'] ?? '')] as $banano_candidate_path) {
    if (!is_string($banano_candidate_path) || $banano_candidate_path === '') {
        continue;
    }
    if ($banano_candidate_path === '/wabo' || strpos($banano_candidate_path, '/wabo/') === 0) {
        $banano_is_wabo_request = true;
        break;
    }
}

if (session_status() === PHP_SESSION_NONE) {

    if ($banano_is_wabo_request) {
        session_name('BANANO_WABO_SESS');
    }
    
    // 1. CONFIGURACIÓN DE DURACIÓN (20 DÍAS)
    // 20 días * 24 horas * 60 min * 60 seg = 1728000 segundos
    $duration = 1728000; 

    $banano_cookie_path = $banano_is_wabo_request ? '/wabo' : '/';

    $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    
    ini_set('session.gc_maxlifetime', $duration); // Cuánto dura la sesión en el servidor
    ini_set('session.cookie_lifetime', $duration); // Cuánto dura la cookie en el navegador
    
    // 2. CONFIGURACIÓN DE SEGURIDAD DE COOKIES
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', $is_secure ? 1 : 0); // HTTPS requerido
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax'); // 'Lax' es CRÍTICO para que funcione Google Login

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $duration,
            'path' => $banano_cookie_path,
            'secure' => $is_secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        session_set_cookie_params($duration, $banano_cookie_path . '; samesite=Lax', '', $is_secure, true);
    }
    
    session_start();

    if (ini_get('session.use_cookies')) {
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), session_id(), [
                'expires' => time() + $duration,
                'path' => $banano_cookie_path,
                'secure' => $is_secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } else {
            setcookie(session_name(), session_id(), time() + $duration, $banano_cookie_path . '; samesite=Lax', '', $is_secure, true);
        }
    }
}

if (
     !$banano_is_wabo_request
     && (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in_client']) || $_SESSION['logged_in_client'] !== true)
     && isset($_COOKIE['banano_remember'])
 ) {
     $db_path_primary = __DIR__ . '/../wabo/core/db_connect.php';
     $db_path_fallback = __DIR__ . '/db_connect.php';

     if (!isset($conn)) {
         if (file_exists($db_path_primary)) {
             require_once $db_path_primary;
         } elseif (file_exists($db_path_fallback)) {
             require_once $db_path_fallback;
         }
     }

     if (isset($conn) && $conn instanceof mysqli) {
         require_once __DIR__ . '/remember_me.php';
         banano_remember_try_restore_session($conn);
     }
 }

// 3. ESCUDO ANTI-CACHÉ (CRÍTICO PARA EVITAR CRUCE DE CUENTAS)
// Esto evita que LiteSpeed o Cloudflare guarden datos privados
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); 
header("X-LiteSpeed-Cache-Control: no-cache"); 

// 4. ANTI-SECUESTRO DE SESIÓN
// Regeneramos el ID cada 30 minutos, pero mantenemos la sesión válida por 20 días.
if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} else if (time() - $_SESSION['CREATED'] > 1800) { 
    // Regenerar ID para seguridad, 'true' borra la vieja para que no haya duplicados
    session_regenerate_id(true);
    $_SESSION['CREATED'] = time();
}

function banano_client_logout_soft(): void {
    unset($_SESSION['user_id'], $_SESSION['email'], $_SESSION['logged_in_client']);
}

function banano_client_session_is_valid(mysqli $conn, int $user_id, int $max_age_seconds = 2592000): bool {
    $stmt = $conn->prepare("SELECT UNIX_TIMESTAMP(last_login_at) AS last_login_ts FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        if (isset($_COOKIE['banano_remember'])) {
            require_once __DIR__ . '/remember_me.php';
            if (function_exists('banano_remember_forget_token')) {
                banano_remember_forget_token($conn);
            }
        }
        banano_client_logout_soft();
        return false;
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $last_login_ts = isset($row['last_login_ts']) ? (int)$row['last_login_ts'] : 0;
    if ($last_login_ts <= 0) {
        if (isset($_COOKIE['banano_remember'])) {
            require_once __DIR__ . '/remember_me.php';
            if (function_exists('banano_remember_forget_token')) {
                banano_remember_forget_token($conn);
            }
        }
        banano_client_logout_soft();
        return false;
    }

    if ((time() - $last_login_ts) > $max_age_seconds) {
        if (isset($_COOKIE['banano_remember'])) {
            require_once __DIR__ . '/remember_me.php';
            if (function_exists('banano_remember_forget_token')) {
                banano_remember_forget_token($conn);
            }
        }
        banano_client_logout_soft();
        session_regenerate_id(true);
        return false;
    }

    return true;
}

function banano_sanitize_internal_return_url($url): ?string {
    if (!is_string($url)) return null;
    $raw = trim($url);
    if ($raw === '') return null;
    if (strlen($raw) > 2048) return null;

    if (preg_match('/[\x00-\x1F\x7F]/', $raw)) return null;
    if (strpos($raw, "\n") !== false || strpos($raw, "\r") !== false) return null;
    if (strpos($raw, '//') === 0) return null;

    $parts = @parse_url($raw);
    if (!is_array($parts)) return null;

    if (isset($parts['scheme'])) {
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') return null;
    }

    if (isset($parts['host'])) {
        $incomingHost = strtolower((string)$parts['host']);
        $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $currentHost = preg_replace('/:\d+$/', '', $currentHost);
        if ($currentHost === '' || $incomingHost !== $currentHost) return null;
    }

    $path = (string)($parts['path'] ?? '/');
    if ($path === '') $path = '/';
    if ($path[0] !== '/') $path = '/' . $path;

    $lowerPath = strtolower($path);
    if ($lowerPath === '/login' || strpos($lowerPath, '/login/') === 0) return null;

    $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
    $fragment = isset($parts['fragment']) && is_string($parts['fragment']) && $parts['fragment'] !== '' ? ('#' . $parts['fragment']) : '';
    return $path . $query . $fragment;
}

function banano_consume_return_url(string $default_url = '/'): string {
    $fallback = $default_url !== '' ? $default_url : '/';
    if ($fallback[0] !== '/') $fallback = '/' . $fallback;

    $candidate = null;
    if (isset($_SESSION['return_url']) && is_string($_SESSION['return_url'])) {
        $candidate = $_SESSION['return_url'];
    }
    unset($_SESSION['return_url']);

    $sanitized = $candidate !== null ? banano_sanitize_internal_return_url($candidate) : null;
    return $sanitized ?? $fallback;
}
?>
<?php
/**
 * ============================================================
 * Tareas — Bootstrap de la API
 * ============================================================
 * Inicializa la sesión endurecida, cabeceras de seguridad,
 * y expone helpers comunes (JSON, lectura de body, CSRF).
 *
 * Todos los endpoints de la API incluyen este archivo primero.
 * ============================================================
 */

declare(strict_types=1);

// En producción nunca mostramos errores al cliente (se loguean).
ini_set('display_errors', '0');
error_reporting(E_ALL);

/** Configuración del módulo (cargada una sola vez). */
function tareas_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/config.php';
    }
    return $config;
}

/**
 * Arranca una sesión endurecida y aislada para el módulo /tareas.
 * - Cookie con path propio (/tareas) → no pisa otras sesiones del sitio.
 * - HttpOnly + SameSite=Lax + Secure (cuando hay HTTPS).
 */
function tareas_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $config   = tareas_config();
    $lifetime = (int) $config['session_lifetime'];

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_name($config['session_name']);

    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => $config['session_cookie_path'],
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/** Cabeceras estándar para respuestas JSON (anti-cache + anti-sniffing). */
function tareas_json_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/** Respuesta JSON y corte de ejecución. */
function tareas_json(array $payload, int $httpCode = 200): never
{
    tareas_json_headers();
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Respuesta de error normalizada. */
function tareas_error(string $message, int $httpCode = 400): never
{
    tareas_json(['status' => 'error', 'message' => $message], $httpCode);
}

/** Respuesta de éxito normalizada. */
function tareas_ok(array $data = [], int $httpCode = 200): never
{
    tareas_json(array_merge(['status' => 'success'], $data), $httpCode);
}

/** Lee y decodifica el cuerpo JSON de la petición. */
function tareas_read_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        tareas_error('JSON inválido: ' . json_last_error_msg(), 400);
    }

    return is_array($data) ? $data : [];
}

/**
 * Token CSRF asociado a la sesión. Se genera una vez y se reutiliza.
 * El frontend lo envía en la cabecera X-CSRF-Token en cada mutación.
 */
function tareas_csrf_token(): string
{
    if (empty($_SESSION['tareas_csrf'])) {
        $_SESSION['tareas_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['tareas_csrf'];
}

/** Verifica el token CSRF en peticiones que mutan estado. */
function tareas_verify_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $real = $_SESSION['tareas_csrf'] ?? '';

    if ($real === '' || !is_string($sent) || !hash_equals($real, $sent)) {
        tareas_error('Token CSRF inválido o ausente.', 419);
    }
}

/** ¿La sesión actual está autenticada (PIN validado)? */
function tareas_is_authenticated(): bool
{
    return !empty($_SESSION['tareas_authed']) && $_SESSION['tareas_authed'] === true;
}

/** Prefijo de tablas del módulo. */
function tareas_prefix(): string
{
    return tareas_config()['table_prefix'];
}

/**
 * Normaliza texto de usuario: recorta espacios, limita longitud y elimina
 * caracteres de control. La defensa anti-XSS real es el escapado en el
 * frontend (textContent), pero saneamos igual en el ingreso (defensa en capas).
 */
function tareas_clean_text(mixed $raw, int $maxLen): string
{
    $text = is_string($raw) ? $raw : '';
    // Quita caracteres de control salvo tab/newline.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    $text = trim($text);

    // Recorte seguro: usa mbstring si está disponible, si no cae a substr.
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLen, 'UTF-8');
    }
    return substr($text, 0, $maxLen);
}

/** Entero positivo (>0) o 0 si inválido. */
function tareas_int(mixed $raw): int
{
    return (is_numeric($raw) && (int) $raw > 0) ? (int) $raw : 0;
}

/** Valida que un valor esté dentro de una lista blanca; si no, usa el default. */
function tareas_enum(mixed $raw, array $allowed, string $default): string
{
    $value = is_string($raw) ? strtolower(trim($raw)) : '';
    return in_array($value, $allowed, true) ? $value : $default;
}

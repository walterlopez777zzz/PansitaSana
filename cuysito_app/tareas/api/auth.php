<?php
/**
 * ============================================================
 * Tareas — API de autenticación (PIN)
 * ============================================================
 * Verificación del PIN 100% del lado del servidor + control de
 * intentos (rate limiting) por sesión.
 *
 * Endpoints (?action=...):
 *   GET  status  → estado de la sesión (autenticado / bloqueado)
 *   POST login   → valida el PIN y abre la sesión
 *   POST logout  → cierra la sesión
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

tareas_session_start();

$config     = tareas_config();
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action     = $_GET['action'] ?? '';
$maxAttempts = (int) $config['max_attempts'];
$lockoutSecs = (int) $config['lockout_seconds'];

/** Segundos restantes de bloqueo, 0 si no está bloqueado. */
function tareas_lock_remaining(): int
{
    $until = (int) ($_SESSION['tareas_lock_until'] ?? 0);
    $now   = time();
    return $until > $now ? ($until - $now) : 0;
}

switch ($action) {

    // ─── ESTADO DE LA SESIÓN ─────────────────────────────────
    case 'status':
        if ($method !== 'GET') {
            tareas_error("Usa GET para 'status'.", 405);
        }

        tareas_ok([
            'authenticated' => tareas_is_authenticated(),
            'pin_length'    => (int) $config['pin_length'],
            'locked_for'    => tareas_lock_remaining(),
            'csrf_token'    => tareas_is_authenticated() ? tareas_csrf_token() : null,
        ]);

    // ─── LOGIN (validar PIN) ─────────────────────────────────
    case 'login':
        if ($method !== 'POST') {
            tareas_error("Usa POST para 'login'.", 405);
        }

        $remaining = tareas_lock_remaining();
        if ($remaining > 0) {
            tareas_error("Demasiados intentos. Espera {$remaining}s.", 429);
        }

        $body = tareas_read_json_body();
        $pin  = isset($body['pin']) ? preg_replace('/\D/', '', (string) $body['pin']) : '';

        if ($pin === '' || strlen($pin) !== (int) $config['pin_length']) {
            tareas_error('El PIN debe tener ' . (int) $config['pin_length'] . ' dígitos.', 400);
        }

        // Comparación en tiempo constante contra el PIN configurado.
        if (!hash_equals((string) $config['pin'], $pin)) {
            $_SESSION['tareas_attempts'] = (int) ($_SESSION['tareas_attempts'] ?? 0) + 1;

            if ($_SESSION['tareas_attempts'] >= $maxAttempts) {
                $_SESSION['tareas_lock_until'] = time() + $lockoutSecs;
                $_SESSION['tareas_attempts']   = 0;
                tareas_error("PIN incorrecto. Bloqueado por {$lockoutSecs}s.", 429);
            }

            $left = $maxAttempts - (int) $_SESSION['tareas_attempts'];
            tareas_error("PIN incorrecto. Te quedan {$left} intento(s).", 401);
        }

        // Éxito: regeneramos el id de sesión para evitar fijación.
        session_regenerate_id(true);
        $_SESSION['tareas_authed']     = true;
        $_SESSION['tareas_attempts']   = 0;
        $_SESSION['tareas_lock_until'] = 0;

        tareas_ok([
            'message'    => 'Acceso concedido.',
            'csrf_token' => tareas_csrf_token(),
        ]);

    // ─── LOGOUT ──────────────────────────────────────────────
    case 'logout':
        if ($method !== 'POST') {
            tareas_error("Usa POST para 'logout'.", 405);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();

        tareas_ok(['message' => 'Sesión cerrada.']);

    default:
        tareas_error('Acción no reconocida.', 404);
}

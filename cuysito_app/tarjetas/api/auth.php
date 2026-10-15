<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

tarjetas_session_start();

$config = tarjetas_config();
$action = tarjetas_clean($_GET['action'] ?? '', 20);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function tarjetas_lock_remaining(): int
{
    return max(0, (int) ($_SESSION['tarjetas_lock_until'] ?? 0) - time());
}

switch ($action) {
    case 'status':
        if ($method !== 'GET') {
            tarjetas_error('Método no permitido.', 405);
        }
        tarjetas_ok([
            'authenticated' => tarjetas_is_authenticated(),
            'pin_length' => (int) $config['pin_length'],
            'locked_for' => tarjetas_lock_remaining(),
            'csrf_token' => tarjetas_is_authenticated() ? tarjetas_csrf() : null,
        ]);

    case 'login':
        if ($method !== 'POST') {
            tarjetas_error('Método no permitido.', 405);
        }
        $remaining = tarjetas_lock_remaining();
        if ($remaining > 0) {
            tarjetas_error("Demasiados intentos. Espera {$remaining}s.", 429);
        }
        $body = tarjetas_body();
        $pin = preg_replace('/\D/', '', (string) ($body['pin'] ?? '')) ?? '';
        if (strlen($pin) !== (int) $config['pin_length']) {
            tarjetas_error('El PIN no tiene la longitud esperada.');
        }
        if (!hash_equals((string) $config['pin'], $pin)) {
            $attempts = (int) ($_SESSION['tarjetas_attempts'] ?? 0) + 1;
            if ($attempts >= (int) $config['max_attempts']) {
                $_SESSION['tarjetas_attempts'] = 0;
                $_SESSION['tarjetas_lock_until'] = time() + (int) $config['lockout_seconds'];
                tarjetas_error('PIN incorrecto. Acceso bloqueado temporalmente.', 429);
            }
            $_SESSION['tarjetas_attempts'] = $attempts;
            tarjetas_error('PIN incorrecto.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['tarjetas_authed'] = true;
        $_SESSION['tarjetas_attempts'] = 0;
        $_SESSION['tarjetas_lock_until'] = 0;
        tarjetas_ok(['csrf_token' => tarjetas_csrf()]);

    case 'logout':
        if ($method !== 'POST') {
            tarjetas_error('Método no permitido.', 405);
        }
        tarjetas_verify_csrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        tarjetas_ok();

    default:
        tarjetas_error('Acción no encontrada.', 404);
}

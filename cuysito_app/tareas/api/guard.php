<?php
/**
 * ============================================================
 * Tareas — Guard de autenticación
 * ============================================================
 * Se incluye al inicio de TODO endpoint de datos. Garantiza que
 * sólo sesiones con el PIN validado puedan tocar la API, aunque
 * alguien intente saltarse la pantalla de bloqueo del frontend.
 *
 * Además aplica verificación CSRF a cualquier método que muta
 * estado (POST/PUT/PATCH/DELETE).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/changes.php';

tareas_session_start();

if (!tareas_is_authenticated()) {
    tareas_error('No autorizado. Ingresa el PIN para continuar.', 401);
}

$tareasMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($tareasMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    tareas_verify_csrf();
}

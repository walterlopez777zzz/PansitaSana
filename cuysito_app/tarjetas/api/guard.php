<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

tarjetas_session_start();

if (!tarjetas_is_authenticated()) {
    tarjetas_error('No autorizado. Ingresa el PIN para continuar.', 401);
}

if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    tarjetas_verify_csrf();
}

try {
    $pdo = TarjetasDB::connect();
} catch (Throwable $error) {
    error_log('[tarjetas] database: ' . $error->getMessage());
    tarjetas_error('No se pudo conectar con la base de datos.', 503);
}

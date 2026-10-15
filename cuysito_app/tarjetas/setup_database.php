<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/api/db.php';

try {
    TarjetasDB::connect();
    echo "Módulo tarjetas listo.\n";
    echo "Las tablas, índices, preferencias y emisores iniciales están configurados.\n";
} catch (Throwable $error) {
    http_response_code(500);
    error_log('[tarjetas setup] ' . $error->getMessage());
    echo "No fue posible preparar el módulo. Revisa la configuración del servidor.\n";
}

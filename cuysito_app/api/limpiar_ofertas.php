<?php
// Archivo: /api/limpiar_ofertas.php
// Su única función es vaciar la tabla de ofertas automáticas.

// Ocultamos cualquier salida para que el cron job sea silencioso
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../wabo/core/db_connect.php';

if ($conn) {
    $conn->query("TRUNCATE TABLE descuentos_automaticos");
    $conn->close();
    // No imprimimos nada para que no lleguen emails innecesarios.
}
?>
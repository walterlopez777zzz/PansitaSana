<?php
// Archivo: db_connect.php (v2 - Con set_charset forzado)

$dbHost = 'localhost';
$dbUser = 'zkckhehj_pistas';
$dbPass = 'Pistas2628';
$dbName = 'zkckhehj_pistas';

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

// Verificar la conexión
if ($conn->connect_error) {
    // En un entorno de producción, sería mejor registrar este error.
    die("Error de conexión a la base de datos.");
}

// === LÍNEA CLAVE PARA SOLUCIONAR CARACTERES RAROS ===
// Forzar la conexión a usar la codificación UTF-8 para soportar tildes, emojis, etc.
$conn->set_charset("utf8mb4");

?>
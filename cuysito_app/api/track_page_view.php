<?php
// /api/track_page_view.php (VERSIÓN PARA TU CONEXIÓN)

// Habilitar errores para depuración inicial
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. Incluir TU archivo de conexión. La variable $conn estará disponible.
require_once 'db_connect.php';

// 2. Hashear la IP del visitante.
$ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR']);

// 3. Crear la consulta SQL (estilo mysqli, con '?')
$sql = "INSERT INTO page_visits (ip_address_hash) VALUES (?)";

// 4. Preparar la consulta usando tu conexión $conn
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    // Si la preparación falla, es un error de SQL.
    die("Error al preparar la consulta: " . $conn->error);
}

// 5. Vincular el parámetro (la 's' significa que es un string)
$stmt->bind_param("s", $ip_hash);

// 6. Ejecutar la consulta y cerrar.
if ($stmt->execute()) {
    // Éxito, no es necesario mostrar nada al usuario final.
    // Puedes borrar este 'echo' cuando confirmes que funciona.
    echo "Éxito: Visita a la página registrada.";
} else {
    // Si la ejecución falla
    echo "Error al ejecutar la consulta: " . $stmt->error;
}

$stmt->close();
$conn->close();

// Respondemos con éxito al navegador.
http_response_code(200);
?>
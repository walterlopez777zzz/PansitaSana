<?php
// Archivo: /api/marcar_tutorial_visto.php
// Marca una categoría como "tutorial visto" para el usuario logueado.
// Para invitados (sin sesión) responde 'guest' y el frontend usa localStorage.

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');

require_once __DIR__ . '/session_security.php';
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function _tutorial_respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

// Acepta categoría tanto del cuerpo JSON (vía web.php gateway) como POST directo.
$raw_input = file_get_contents('php://input');
$json_body = json_decode($raw_input, true);

$categoria = '';
if (is_array($json_body) && isset($json_body['categoria'])) {
    $categoria = trim((string)$json_body['categoria']);
}
if ($categoria === '' && isset($_REQUEST['categoria'])) {
    $categoria = trim((string)$_REQUEST['categoria']);
}

if ($categoria === '' || mb_strlen($categoria, 'UTF-8') > 100) {
    _tutorial_respond(['success' => false, 'message' => 'Categoría inválida.'], 400);
}

// Invitado: el frontend usa localStorage, aquí solo confirmamos.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in_client']) || $_SESSION['logged_in_client'] !== true) {
    _tutorial_respond(['success' => true, 'guest' => true]);
}

$uid = (int)$_SESSION['user_id'];

// Validación rápida de sesión real (igual que el resto del sitio).
if (function_exists('banano_client_session_is_valid') && !banano_client_session_is_valid($conn, $uid)) {
    _tutorial_respond(['success' => true, 'guest' => true]);
}

$stmt = $conn->prepare("INSERT IGNORE INTO users_tutoriales_vistos (user_id, categoria) VALUES (?, ?)");
if (!$stmt) {
    _tutorial_respond(['success' => false, 'message' => 'Error interno.'], 500);
}
$stmt->bind_param('is', $uid, $categoria);
$stmt->execute();
$stmt->close();

_tutorial_respond(['success' => true, 'guest' => false]);

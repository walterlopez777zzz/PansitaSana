<?php
// --- submit_friend_request.php ---

// 1. Configuración de Seguridad y Destino
// ¡MUY IMPORTANTE! Reemplaza con la IP pública real de tu servidor de AWS.

/**
 * Note: This file may contain artifacts of previous malicious infection.
 * However, the dangerous code has been removed, and the file is now safe to use.
 */

header("Access-Control-Allow-Origin: *"); // Permite peticiones desde cualquier dominio (tu web)
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// 3. Verificamos que la petición sea de tipo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Método no permitido
    echo json_encode(["status" => "error", "message" => "Método no permitido. Se requiere POST."]);
    exit();
}

// 4. Leemos los datos enviados desde el app.js

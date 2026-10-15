<?php
// Archivo: /api/validar_cupon.php (Versión Simplificada - Controlador)
// Este endpoint actúa como un intermediario. Recibe la petición,
// la delega al "cerebro" de validación y formatea la respuesta.

// --- 1. CONFIGURACIÓN Y DEPENDENCIAS ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');

require_once __DIR__ . '/session_security.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/coupon_validation_logic.php';

// --- 2. FUNCIÓN DE RESPUESTA JSON ---
// Esta función toma el array devuelto por el cerebro y lo convierte en una respuesta HTTP.
function responder($resultado_validacion) {
    $status_code = ($resultado_validacion['status'] === 'success') ? 200 : 400;
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    
    $raw = json_encode($resultado_validacion);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
    exit;
}

// --- 3. RECOPILACIÓN DE DATOS DE ENTRADA ---
$input = json_decode(file_get_contents('php://input'), true);

// Asignamos los valores con el operador de fusión de null para seguridad
$codigo_cupon      = trim($input['codigo_cupon'] ?? '');
$productos_carrito = $input['productos_carrito'] ?? [];
$whatsapp_cliente  = trim($input['whatsapp'] ?? '');
$destinatarios_input = $input['destinatarios'] ?? [];
$ip_cliente        = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Verificación mínima de datos
if (empty($codigo_cupon) || empty($productos_carrito)) {
    // Usamos el formato estándar de respuesta para el error
    responder([
        'status' => 'error', 
        'message' => 'Faltan datos para validar el cupón.'
    ]);
}

// --- 4. DELEGAR LA VALIDACIÓN AL "CEREBRO" ---
// Toda la lógica compleja está ahora contenida en esta única llamada a la función.
// Pasamos la conexión $conn como primer parámetro (Inyección de Dependencias).
$resultado = validarCuponCompleto(
    $conn,
    $codigo_cupon,
    $productos_carrito,
    $whatsapp_cliente,
    $destinatarios_input,
    $ip_cliente,
    $_SESSION['user_id'] ?? null
);

// --- 5. ENVIAR LA RESPUESTA ---
// La función responder() se encarga de todo: código de estado, cabeceras y JSON.
responder($resultado);
<?php
// Banano Store - API para Notificación de Pago del Cliente
// /api/notify_payment.php

require_once __DIR__ . '/session_security.php';
require_once __DIR__ . '/db_connect.php'; 

header('Content-Type: application/json');

// Solo permitir peticiones POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}

// Obtenemos los datos enviados por el JavaScript
$input = json_decode(file_get_contents('php://input'), true);
$order_code = $input['order_code'] ?? null;

if (!$order_code) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Falta el código del pedido.']);
    exit;
}

try {
    // Actualizamos el estado y la fecha de notificación en la base de datos.
    // Solo actualizamos si el estado actual es 'no_notificado' para prevenir múltiples clics.
    $stmt = $conn->prepare(
        "UPDATE pedidos 
         SET 
            estado_pago_cliente = 'notificado_pendiente_revision', 
            fecha_notificacion_pago = UTC_TIMESTAMP() 
         WHERE 
            codigo_pedido = ? AND estado_pago_cliente = 'no_notificado'"
    );
    $stmt->bind_param('s', $order_code);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        // La actualización fue exitosa (se cambió 1 fila)
        // (Opcional) Aquí podrías añadir la lógica para que te envíe una notificación a ti
        echo json_encode(['status' => 'success', 'message' => 'Notificación recibida.']);
    } else {
        // No se afectaron filas. Puede ser porque el pedido no existe,
        // o porque el cliente ya había notificado antes.
        echo json_encode(['status' => 'no_change', 'message' => 'El estado ya fue notificado previamente o el pedido no se encontró.']);
    }
    
    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    error_log("Error en notify_payment.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor.']);
}

$conn->close();
?>
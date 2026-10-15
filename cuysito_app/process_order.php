<?php
// Banano Store - Procesador de Pedidos (Versión MySQLi)
// Este script es el corazón de la transacción. Valida y guarda el pedido.

require_once __DIR__ . '/api/session_security.php';

// -- PASO 0: Incluir dependencias y verificaciones iniciales --
require_once 'api/db_connect.php'; // Conexión a la base de datos

// Solo permitir peticiones POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    die('Acceso denegado.');
}

// Verificar que el carrito no esté vacío
if (empty($_SESSION['cart'])) {
    // Redirigir a alguna página de error o al checkout con un mensaje
    header('Location: checkout.php?error=cart_empty');
    exit();
}

// Iniciar una transacción. Si algo falla, se revierte todo.
$conn->begin_transaction();

try {
    // -- PASO 1: Recolectar y sanitizar datos del formulario (del cliente) --
    $email_cliente = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $whatsapp_discord_cliente = filter_input(INPUT_POST, 'whatsapp_discord', FILTER_SANITIZE_STRING);
    $nickname_fortnite_cliente = filter_input(INPUT_POST, 'nickname_fortnite', FILTER_SANITIZE_STRING);
    $metodo_pago = filter_input(INPUT_POST, 'metodo_pago', FILTER_SANITIZE_STRING);
    $codigo_cupon_cliente = filter_input(INPUT_POST, 'codigo_cupon', FILTER_SANITIZE_STRING);
    // ... aquí irían los campos de "regalo" si los implementas

    // -- PASO 2: Recalcular precios y totales EN EL SERVIDOR. ¡NUNCA CONFIAR EN EL CLIENTE! --
    $subtotal_servidor = 0;
    foreach ($_SESSION['cart'] as $item) {
        $subtotal_servidor += $item['price'] * $item['quantity'];
    }

    $descuento_servidor = 0;
    // (FUTURO) Aquí iría la lógica completa para re-validar el cupón
    // Por ahora, lo dejamos en 0.
    // $descuento_servidor = validarCuponEnServidor($codigo_cupon_cliente, $_SESSION['cart'], $conn);

    $total_servidor = $subtotal_servidor - $descuento_servidor;

    // -- PASO 3: Generar un código de pedido único --
    // Usaremos la técnica "B-XXXXXX" basada en el tiempo.
    $codigo_pedido = "B-" . substr(time(), -6);
    // (Opcional) Podemos añadir un bucle para asegurarnos de que es 100% único, aunque es muy improbable que no lo sea.

    // -- PASO 4: Obtener configuración de reserva --
    $resultConfig = $conn->query("SELECT clave, valor FROM configuracion_tienda WHERE clave IN ('modo_tienda', 'tiempo_reserva_normal_min', 'tiempo_reserva_alta_demanda_min')");
    $config = [];
    while($row = $resultConfig->fetch_assoc()) {
        $config[$row['clave']] = $row['valor'];
    }
    $tiempo_reserva_min = ($config['modo_tienda'] === 'alta_demanda')
        ? (int)($config['tiempo_reserva_alta_demanda_min'] ?? 30)
        : (int)($config['tiempo_reserva_normal_min'] ?? 1440);

    // -- PASO 5: Insertar el pedido principal en la tabla `pedidos` --
    // UTC-FIRST: pinneamos el time_zone de la sesión MySQL a UTC para que las fechas
    // se almacenen como UTC real aunque las columnas sean TIMESTAMP (que MySQL convierte
    // según el huso de la sesión), nunca en la hora local del servidor (ET/UTC-4).
    $conn->query("SET time_zone = '+00:00'");
    date_default_timezone_set('UTC');

    $stmtPedido = $conn->prepare(
        // UTC-first: fecha_creacion y fecha_reserva_expira se escriben con UTC_TIMESTAMP()
        // (independiente del time_zone de la sesión/servidor) para que la BD hable SIEMPRE en UTC.
        "INSERT INTO pedidos (codigo_pedido, email_cliente, whatsapp_discord_cliente, nickname_fortnite_cliente, subtotal, monto_descuento, total_pagar, cupon_usado_codigo, metodo_pago, ip_cliente, fecha_creacion, fecha_reserva_expira) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? MINUTE)"
    );
    $ip_cliente = $_SERVER['REMOTE_ADDR'];
    $stmtPedido->bind_param(
        'ssssddssssi',
        $codigo_pedido,
        $email_cliente,
        $whatsapp_discord_cliente,
        $nickname_fortnite_cliente,
        $subtotal_servidor,
        $descuento_servidor,
        $total_servidor,
        $codigo_cupon_cliente,
        $metodo_pago,
        $ip_cliente,
        $tiempo_reserva_min
    );

    if (!$stmtPedido->execute()) {
        throw new Exception("Error al guardar el pedido: " . $stmtPedido->error);
    }
    $pedido_id = $stmtPedido->insert_id; // Obtenemos el ID del pedido recién creado
    $stmtPedido->close();

    // -- PASO 6: Insertar cada producto del carrito en `detalles_pedido` --
    $stmtDetalles = $conn->prepare(
        "INSERT INTO detalles_pedido (pedido_id, producto_id_str, nombre_producto, imagen_producto_url, cantidad, precio_unitario_compra, fecha_expiracion_item) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($_SESSION['cart'] as $item) {
        $stmtDetalles->bind_param(
            'isssids',
            $pedido_id,
            $item['id'],
            $item['name'],
            $item['image'],
            $item['quantity'],
            $item['price'],
            $item['expires']
        );
        if (!$stmtDetalles->execute()) {
            throw new Exception("Error al guardar los detalles del pedido: " . $stmtDetalles->error);
        }
    }
    $stmtDetalles->close();
    
    // -- PASO 7: (FUTURO) Actualizar el uso del cupón en la tabla `cupones` --
    // if ($descuento_servidor > 0) {
    //     // Lógica para incrementar usos del cupón
    // }

    // -- PASO 8: Si todo ha ido bien, confirmar la transacción --
    $conn->commit();

    // -- PASO 9: Limpiar el carrito y redirigir --
    unset($_SESSION['cart']);
    session_destroy(); // Opcional: Destruir la sesión por completo

    // Redirigir a la página de confirmación con el código del pedido
    header('Location: confirmation.php?order_code=' . $codigo_pedido);
    exit();

} catch (Exception $e) {
    // Si algo falló, revertir todos los cambios en la base de datos
    $conn->rollback();

    // Guardar el error en un log para nosotros (opcional pero muy recomendado)
    error_log("Error en process_order.php: " . $e->getMessage());

    // Redirigir al usuario a una página de error
    http_response_code(500);
    // Podrías crear una página error.php
    header('Location: checkout.php?error=processing_failed');
    exit();
} finally {
    // Siempre cerrar la conexión
    if (isset($conn)) {
        $conn->close();
    }
}
?>
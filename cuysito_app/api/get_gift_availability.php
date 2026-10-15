<?php
// Banano Store - API de Disponibilidad de Regalos (Versión MySQLi)
// Este script calcula en tiempo real cuántos regalos se pueden vender.

// Descomentar estas líneas SOLO si necesitas depurar errores.
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Encabezados para asegurar que la respuesta sea JSON y prevenir caché.
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

function _outputJson($data) {
    $raw = json_encode($data);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
}

// Incluimos el script de conexión a la base de datos.
require_once 'db_connect.php'; // Este archivo crea la variable $conn

try {
    // -- PASO 1: Obtener toda la configuración de la tienda de una sola vez --
    $resultConfig = $conn->query("SELECT clave, valor FROM configuracion_tienda");
    $config = [];
    while ($row = $resultConfig->fetch_assoc()) {
        $config[$row['clave']] = $row['valor'];
    }

    // Verificamos si la tienda está en modo mantenimiento.
    if (isset($config['modo_tienda']) && $config['modo_tienda'] === 'mantenimiento') {
        _outputJson(['slots_disponibles' => 0, 'modo' => 'mantenimiento', 'mensaje' => $config['mensaje_mantenimiento'] ?? 'Tienda en mantenimiento.']);
        exit();
    }

    // Verificamos si las compras están forzadas a estar abiertas.
    if (isset($config['forzar_compras_abiertas']) && $config['forzar_compras_abiertas'] == '1') {
        _outputJson(['slots_disponibles' => 999, 'modo' => 'forzado']); // 999 simboliza "infinito"
        exit();
    }

    // -- PASO 2: Calcular slots teóricos --
    $slots_teoricos = (int)($config['regalos_diarios_totales'] ?? 100);

    // -- PASO 3: Restar slots ya completados en las últimas 24 horas --
    $resultCompletados = $conn->query("SELECT COUNT(*) as count FROM log_regalos_completados WHERE fecha_envio >= NOW() - INTERVAL 1 DAY");
    $rowCompletados = $resultCompletados->fetch_assoc();
    $slots_usados_completados = (int)$rowCompletados['count'];

    // -- PASO 4: Restar slots reservados en pedidos pendientes --
    // Determinamos el tiempo de reserva correcto según el modo de la tienda.
    $tiempo_reserva_min = ($config['modo_tienda'] === 'alta_demanda')
        ? (int)($config['tiempo_reserva_alta_demanda_min'] ?? 30)
        : (int)($config['tiempo_reserva_normal_min'] ?? 1440);

    // Usamos sentencias preparadas para seguridad
    $stmtPendientes = $conn->prepare(
        "SELECT COUNT(*) as count FROM pedidos WHERE estado = 'pendiente' AND fecha_creacion >= NOW() - INTERVAL ? MINUTE"
    );
    $stmtPendientes->bind_param('i', $tiempo_reserva_min);
    $stmtPendientes->execute();
    $resultPendientes = $stmtPendientes->get_result();
    $rowPendientes = $resultPendientes->fetch_assoc();
    $slots_reservados_pendientes = (int)$rowPendientes['count'];
    $stmtPendientes->close();
    
    // -- PASO 5: Calcular el resultado final --
    $slots_disponibles = $slots_teoricos - $slots_usados_completados - $slots_reservados_pendientes;
    
    // Asegurarnos de que el resultado nunca sea negativo.
    if ($slots_disponibles < 0) {
        $slots_disponibles = 0;
    }

    // -- PASO 6: Devolver la respuesta JSON --
    _outputJson([
        'slots_disponibles' => $slots_disponibles,
        'modo' => $config['modo_tienda'] ?? 'normal'
    ]);

} catch (Exception $e) {
    // En caso de un error general, devolvemos un error 500.
    http_response_code(500);
    _outputJson(['error' => 'Error interno del servidor: ' . $e->getMessage()]);
}

// Cerramos la conexión al final del script
$conn->close();
?>
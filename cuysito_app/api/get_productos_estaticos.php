<?php
// Archivo: /api/get_productos_estaticos.php (v3.1 - SOLUCIÓN Timezone con UTC_TIMESTAMP)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once 'db_connect.php';

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

// --- LÓGICA DE AUTOLIMPIEZA ---
// === CORRECCIÓN PROBLEMA 2: Usar UTC_TIMESTAMP() en lugar de NOW() ===
// Esto compara la fecha de expiración (guardada en UTC) con la hora actual del servidor en UTC,
// ignorando la zona horaria del servidor (p. ej. UTC-4) y evitando expiraciones tempranas.
$conn->query("UPDATE productos_estaticos SET activo = 0 WHERE fecha_expiracion IS NOT NULL AND fecha_expiracion < UTC_TIMESTAMP()");
$conn->query("UPDATE productos_estaticos SET precio_oferta = NULL, tipo_oferta = 'precio_fijo', oferta_fecha_expiracion = NULL WHERE oferta_fecha_expiracion IS NOT NULL AND oferta_fecha_expiracion < UTC_TIMESTAMP()");

$productos_agrupados = [];
// --- OBTENCIÓN DE DATOS ---
// Se envían también los productos con stock_cantidad = 0 para que el frontend pueda
// mostrar el estado "Agotado" (card grisácea + botón deshabilitado).
$sql = "SELECT 
            id, nombre, descripcion, stock_cantidad, precio_base, precio_oferta, tipo_oferta, imagen_url, categoria, 
            fecha_expiracion, oferta_fecha_expiracion, urgencia_horas,
            etiqueta_texto, etiqueta_posicion, etiqueta_color_fondo, etiqueta_color_texto, 
            precio_oferta_color, timer_color_fondo, timer_color_texto,
            orden_categoria, orden,
            activar_tutorial, mensaje_tutorial, modo_reserva, mensaje_reserva
        FROM productos_estaticos 
        WHERE 
            activo = 1 
            AND (stock_cantidad >= 0 OR stock_cantidad = -1)
        ORDER BY orden_categoria ASC, orden ASC, id ASC";

$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $categoria = $row['categoria'];
        if (!isset($productos_agrupados[$categoria])) {
            $productos_agrupados[$categoria] = ['orden_categoria' => (int)$row['orden_categoria'], 'items' => []];
        }
        $productos_agrupados[$categoria]['items'][] = $row;
    }
}
$conn->close();

uasort($productos_agrupados, function($a, $b) { return $a['orden_categoria'] <=> $b['orden_categoria']; });
$response = [];
foreach ($productos_agrupados as $nombre_categoria => $data) { $response[$nombre_categoria] = $data['items']; }

_outputJson($response);
?>
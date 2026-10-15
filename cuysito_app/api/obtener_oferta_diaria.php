<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../wabo/core/db_connect.php'; // Asegúrate que la ruta sea correcta

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

// 1. La nueva consulta SQL que une las dos tablas
// Seleccionamos el offer_id de 'descuentos_automaticos' y TODA la información de la regla
// de la tabla 'ofertas_reglas'.
$sql = "SELECT 
            da.offer_id, 
            ore.porcentaje_descuento,
            ore.etiqueta_texto,
            ore.etiqueta_color_fondo,
            ore.etiqueta_color_texto,
            ore.precio_oferta_color
        FROM 
            descuentos_automaticos da
        JOIN 
            ofertas_reglas ore ON da.id_regla = ore.id
        WHERE 
            ore.activo = 1"; // Solo traemos las reglas que están activas

$result = $conn->query($sql);

if (!$result) {
    _outputJson(['success' => false, 'message' => 'Error en la consulta a la base de datos.']);
    exit;
}

// 2. Creamos un mapa (array asociativo) donde la clave es el 'offer_id'
// y el valor son todos los detalles del descuento.
$discountsByOfferId = [];
while ($row = $result->fetch_assoc()) {
    $offerId = $row['offer_id'];
    
    // Guardamos un objeto limpio con toda la info del descuento para este offer_id
    $discountsByOfferId[$offerId] = [
        'porcentaje_descuento' => (int)$row['porcentaje_descuento'],
        'etiqueta_texto'       => $row['etiqueta_texto'],
        'badgeColor'           => $row['etiqueta_color_fondo'],
        'textColor'            => $row['etiqueta_color_texto'],
        'precio_oferta_color'  => $row['precio_oferta_color']
    ];
}

// 3. Devolvemos el resultado en un nuevo formato que el frontend pueda entender fácilmente.
if (!empty($discountsByOfferId)) {
    _outputJson([
        'success'   => true,
        'discounts' => $discountsByOfferId // Devolvemos el mapa completo
    ]);
} else {
    _outputJson(['success' => false, 'message' => 'No hay ofertas automáticas activas.']);
}

$conn->close();
?>
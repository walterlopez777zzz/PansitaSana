<?php
// Archivo: /api/get_ofertas_automaticas.php
// Este es el único endpoint que el frontend necesita para obtener las ofertas automáticas de hoy.

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// La ruta al archivo de conexión debe ser correcta desde la carpeta /api/
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

// Hacemos una consulta que une los resultados del sorteo con las reglas para obtener toda la información
$sql = "SELECT 
            d.offer_id, 
            r.porcentaje_descuento,
            r.etiqueta_texto,
            r.etiqueta_color_fondo,
            r.etiqueta_color_texto,
            r.precio_oferta_color,
            r.etiqueta_posicion,
            r.nombre_regla -- Opcional: para depuración en el frontend
        FROM 
            descuentos_automaticos d
        JOIN 
            ofertas_reglas r ON d.id_regla = r.id
        ORDER BY 
            r.orden ASC, d.id ASC";

$result = $conn->query($sql);

$ofertas_automaticas = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $ofertas_automaticas[] = $row;
    }
}

$conn->close();

// Devolvemos un array de objetos, cada uno representando una oferta automática.
_outputJson($ofertas_automaticas);
?>
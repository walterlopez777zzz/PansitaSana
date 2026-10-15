<?php
// /api/check_order_status.php (v3.0 - Polling + cancelacion automatica "lazy expiration on read")

// Consistencia de tiempo: la BD guarda los timestamps en UTC (notify_payment y
// los UPDATE del panel usan UTC_TIMESTAMP()). Forzamos UTC en PHP para que
// strtotime()/time() coincidan con confirmation.php.
date_default_timezone_set('UTC');

header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';

// UTC-FIRST: leemos en UTC puro. Si las columnas de fecha son TIMESTAMP, MySQL las
// convierte al huso de la sesión; pinneando la sesión a UTC evitamos que fecha_creacion
// vuelva a salir en hora local y que strtotime() la malinterprete (bug "desfase de 4h").
if (isset($conn) && $conn instanceof mysqli) { $conn->query("SET time_zone = '+00:00'"); }

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

/**
 * Calcula el timestamp (UTC, en segundos) en el que expira la ventana de pago
 * de un pedido, usando la MISMA logica que confirmation.php:
 *   - Reserva estandar: fecha_creacion + tiempo_reserva_normal_min (config, def. 1440 min).
 *   - Si el pedido tiene fecha_expiracion_oferta y es mas cercana, manda esa.
 * Devuelve null si no se puede determinar la fecha de creacion.
 *
 * @param mysqli $conn
 * @param array  $pedido Fila del pedido (debe incluir fecha_creacion y fecha_expiracion_oferta).
 * @return int|null
 */
function _bananoCalcularExpiracion(mysqli $conn, array $pedido): ?int {
    if (empty($pedido['fecha_creacion'])) {
        return null;
    }
    $creation_ts = strtotime($pedido['fecha_creacion']);
    if ($creation_ts === false) {
        return null;
    }

    // Reserva estandar (minutos) desde configuracion_tienda.
    $reservation_minutes = 1440;
    $cfg = $conn->prepare("SELECT valor FROM configuracion_tienda WHERE clave = 'tiempo_reserva_normal_min' LIMIT 1");
    if ($cfg) {
        $cfg->execute();
        $res = $cfg->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $reservation_minutes = (int)$row['valor'];
        }
        $cfg->close();
    }

    $deadline = $creation_ts + ($reservation_minutes * 60);

    // La expiracion de oferta manda si es mas cercana.
    if (!empty($pedido['fecha_expiracion_oferta'])) {
        $offer_ts = strtotime($pedido['fecha_expiracion_oferta']);
        if ($offer_ts !== false && $offer_ts < $deadline) {
            $deadline = $offer_ts;
        }
    }

    return $deadline;
}

$order_code = $_GET['order_code'] ?? null;

if (!$order_code) {
    _outputJson(['status' => 'error', 'message' => 'Falta el codigo de pedido.']);
    exit();
}

if ($conn) {
    // --- CAMBIO CLAVE: AHORA PEDIMOS TODAS LAS FECHAS (+ expiracion de oferta) ---
    $stmt = $conn->prepare("
        SELECT 
            estado, 
            estado_pago_cliente, 
            fecha_creacion, 
            fecha_notificacion_pago, 
            fecha_pago, 
            fecha_completado,
            fecha_expiracion_oferta,
            coins_redeemed
        FROM pedidos 
        WHERE codigo_pedido = ? 
        LIMIT 1
    ");
    $stmt->bind_param('s', $order_code);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $pedido = $resultado->fetch_assoc();
        $stmt->close();

        // ============================================================
        // === CANCELACION AUTOMATICA (LAZY EXPIRATION ON READ) =======
        // ============================================================
        // Si el pedido sigue pendiente y sin notificar, y ya paso su limite
        // de tiempo, lo cancelamos AQUI mismo (en la propia lectura del
        // polling). Asi el backend y el frontend quedan sincronizados sin
        // necesidad de un cron: el pedido se cancela en cuanto alguien
        // consulta su estado. El WHERE lleva las mismas guardas para ser
        // seguro ante carreras (jamas toca pedidos pagados o notificados).
        // Excepcion: pedidos con Bananos gastados NUNCA se auto-cancelan (los resuelve un admin).
        $coins_protected = ((float)($pedido['coins_redeemed'] ?? 0)) > 0;
        if (!$coins_protected && $pedido['estado'] === 'pendiente' && $pedido['estado_pago_cliente'] === 'no_notificado') {
            $deadline_ts = _bananoCalcularExpiracion($conn, $pedido);
            if ($deadline_ts !== null && time() > $deadline_ts) {
                $upd = $conn->prepare(
                    "UPDATE pedidos 
                     SET estado = 'cancelado' 
                     WHERE codigo_pedido = ? 
                       AND estado = 'pendiente' 
                       AND estado_pago_cliente = 'no_notificado'
                       AND COALESCE(coins_redeemed, 0) = 0"
                );
                $upd->bind_param('s', $order_code);
                $upd->execute();
                if ($upd->affected_rows > 0) {
                    // Reflejamos el nuevo estado en la respuesta del polling.
                    $pedido['estado'] = 'cancelado';
                }
                $upd->close();
            }
        }

        // Devolvemos los datos como JSON para que JavaScript los entienda
        _outputJson([
            'status' => 'success',
            'data' => $pedido
        ]);
    } else {
        $stmt->close();
        _outputJson(['status' => 'error', 'message' => 'Pedido no encontrado.']);
    }

    $conn->close();
} else {
    _outputJson(['status' => 'error', 'message' => 'Error de conexion.']);
}
?>

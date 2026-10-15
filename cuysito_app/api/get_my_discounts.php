<?php
// public_html/api/get_my_discounts.php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once 'db_connect.php'; // Tu conexión
require_once 'logic_discounts.php'; // Importamos el "Cerebro"

function _outputJson($data) {
    $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
}

$response = ['success' => false, 'discounts' => []];

// 1. Seguridad: Usuario Logueado
if (!isset($_SESSION['user_id'])) {
    _outputJson(['success' => false, 'message' => 'No logueado']);
    exit;
}

// Identidad: SOLO la sesión. Nunca un nickname/usuario enviado por el cliente,
// así un cupón (Giro Diario o Premio Sorteo) no puede consultarse ni aplicarse
// desde otra cuenta.
$user_id = (int)$_SESSION['user_id'];

try {
    dd_ensure_schema($conn);

    // 2. Consultar TODOS los descuentos activos de este usuario
    // Misma regla que get_active_discount(): active y expires_at futuro, ambos
    // en UTC (UTC_TIMESTAMP, independiente del time_zone de la sesión).
    // Orden: mejor porcentaje primero y, a igualdad, el que vence antes; así el
    // mapa por item_id conserva el mismo cupón que aplicará checkout.
    $sql = "SELECT id, item_id, item_name, item_image_url, discount_percent, is_flash_prize,
                   spin_source, raffle_id, coupon_value_coins, expires_at, created_at
            FROM daily_discounts
            WHERE user_id = ?
            AND status = 'active'
            AND expires_at > UTC_TIMESTAMP()
            ORDER BY item_id ASC, discount_percent DESC, expires_at ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('DB error');
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $now = UtcTime::now();
    $active_discounts = [];
    $coupons = [];

    while ($row = $result->fetch_assoc()) {
        $meta = dd_source_meta($row['spin_source'] ?? '');
        $isRafflePrize = ($meta['source'] === DD_SOURCE_RAFFLE_PRIZE);
        $entry = [
            'id' => (int)$row['id'],
            'item_id' => (string)$row['item_id'],
            'item_name' => $row['item_name'] ?? null,
            'item_image_url' => $row['item_image_url'] ?? null,
            'percent' => (int)$row['discount_percent'],
            'is_flash' => (bool)$row['is_flash_prize'],
            // Fechas ISO-8601 UTC (`Z`): el navegador las interpreta sin ambigüedad.
            'expires_at' => UtcTime::sqlToIso($row['expires_at']),
            'expires_in_seconds' => UtcTime::secondsUntil($row['expires_at'], $now) ?? 0,
            'created_at' => UtcTime::sqlToIso($row['created_at']),
            // Origen del cupón: 'daily_free' | 'paid_coins' | 'bonus_gift' | 'raffle_prize'
            'source' => $meta['source'],
            'source_label' => $meta['label'],          // p. ej. "Premio Sorteo"
            'source_color' => $meta['color'],          // p. ej. "#a855f7" (null para giros)
            'is_raffle_prize' => $isRafflePrize,
            'raffle_id' => isset($row['raffle_id']) ? (int)$row['raffle_id'] : null,
            // Valor del cupón en Bananos (precio del ítem × porcentaje), solo premios.
            'coupon_value_coins' => isset($row['coupon_value_coins']) ? (float)$row['coupon_value_coins'] : null,
            // Reglas de uso: solo la cuenta logueada, sin regalos a otro nick ni multi.
            'self_only' => true,
            'transferable' => false,
        ];
        $coupons[] = $entry;
        // Mapa 'OfferId' => Datos: conserva el mejor cupón por ítem (ver ORDER BY)
        if (!isset($active_discounts[$row['item_id']])) {
            $active_discounts[$row['item_id']] = $entry;
        }
    }

    $stmt->close();

    $response['success'] = true;
    $response['server_time_utc'] = UtcTime::toIso($now);
    $response['discounts'] = $active_discounts;
    $response['coupons'] = $coupons;
    $response['sources'] = [
        DD_SOURCE_RAFFLE_PRIZE => ['label' => DD_RAFFLE_PRIZE_LABEL, 'color' => DD_RAFFLE_PRIZE_COLOR],
    ];

} catch (Exception $e) {
    $response['message'] = "Error interno";
}

_outputJson($response);
?>
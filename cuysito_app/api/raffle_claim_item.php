<?php
// api/raffle_claim_item.php
// Elección DEFINITIVA del ítem dentro de un pool de premios (Vitrina).
// Solo aplica cuando el ganador ya reclamó "premio original" y su puesto
// tiene un pool: la elección es única e irreversible (final_prize snapshot).

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

date_default_timezone_set('America/Lima');

define('ROOT_PATH', dirname(__DIR__));

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../config/shop_config.php';
require_once __DIR__ . '/raffle_rewards.php';
require_once __DIR__ . '/raffle_review.php';

// 1. SEGURIDAD DE SESIÓN
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sesión expirada']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
// Acepta POST directo o parámetros remapeados por el gateway (web.php → $_GET).
$raffleId = isset($_REQUEST['raffle_id']) ? (int)$_REQUEST['raffle_id'] : (isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0);
$optionKey = isset($_REQUEST['option_key']) ? trim((string)$_REQUEST['option_key']) : '';

if ($raffleId <= 0 || $optionKey === '') {
    echo json_encode(['status' => 'error', 'message' => 'Datos incompletos.']);
    exit;
}

$cacheFile = ShopSourceConfig::activeSnapshotPath() ?? ShopSourceConfig::liveSnapshotPath();

try {
    rr_ensure_schema($conn);
    dd_ensure_schema($conn);
    $conn->begin_transaction();

    // 2. ESTADO DEL GANADOR (FOR UPDATE contra doble clic / carreras)
    $sql = "SELECT r.prize_config,
                   w.id AS win_id, w.position, w.choice, w.prize_sent, w.prize_state, w.final_prize
            FROM raffles r
            JOIN raffle_winners w ON r.raffle_id = w.raffle_id
            WHERE r.raffle_id = ? AND w.user_id = ?
            FOR UPDATE";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $raffleId, $userId);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data) throw new Exception("No eres ganador de este sorteo.");
    if (($data['choice'] ?? '') !== 'original') throw new Exception("Primero debes reclamar tu premio original.");
    if (!empty($data['final_prize']) || in_array((string)$data['prize_state'], ['selected', 'admin_pending', 'admin_resolved'], true)) {
        throw new Exception("Ya elegiste tu premio. La elección es definitiva.");
    }
    if ((string)$data['prize_state'] !== 'awaiting_selection' || (int)$data['prize_sent'] === 1) {
        throw new Exception("Este premio no tiene una elección pendiente.");
    }

    // 3. RESOLVER EL POOL DE ESTE PUESTO Y LA OPCIÓN ELEGIDA
    $posPrize = rr_prize_for_position(json_decode($data['prize_config'], true), (int)($data['position'] ?? 1));
    $posPool = rr_position_pool($posPrize);
    if ($posPool === null) throw new Exception("El premio de tu puesto no es un pool.");

    $chosen = null;
    foreach (($posPool['options'] ?? []) as $opt) {
        if (is_array($opt) && (string)($opt['option_key'] ?? '') === $optionKey) { $chosen = $opt; break; }
    }
    if ($chosen === null) throw new Exception("Opción no válida para tu puesto.");

    $winId = (int)$data['win_id'];
    $kind = (string)($chosen['kind'] ?? '');
    $chosenName = (string)($chosen['name'] ?? ($chosen['label'] ?? 'tu premio'));
    $finalPrizeJson = json_encode($chosen, JSON_UNESCAPED_UNICODE);

    // 4. ENTREGA SEGÚN EL TIPO DE OPCIÓN
    if ($kind === 'coins') {
        $amount = rr_credit_coins($conn, $userId, (float)($chosen['amount'] ?? 0), 'raffle_prize_coins', $raffleId);
        if ($amount <= 0) throw new Exception("Monto de monedas inválido. Contacta soporte.");
        $upd = $conn->prepare("UPDATE raffle_winners SET prize_state = 'selected', final_prize = ?, prize_selected_at = UTC_TIMESTAMP(), prize_sent = 1 WHERE id = ?");
        $upd->bind_param("si", $finalPrizeJson, $winId);
        $upd->execute();
        $msg = "¡Elección confirmada! Recibiste " . number_format($amount, 0, ',', '.') . " Bananos.";
    } elseif ($kind === 'category') {
        // El admin registra el ítem concreto al final (chat interno).
        $upd = $conn->prepare("UPDATE raffle_winners SET prize_state = 'admin_pending', final_prize = ?, prize_selected_at = UTC_TIMESTAMP(), prize_sent = 0 WHERE id = ?");
        $upd->bind_param("si", $finalPrizeJson, $winId);
        $upd->execute();
        $msg = "¡Elección confirmada: $chosenName! Escríbenos por chat para indicarnos el ítem exacto que quieres de esta categoría.";
    } else {
        // catalog / product / custom => pedido inmediato
        $prizeCfg = rr_option_to_prize_config($chosen);
        $orderId = rr_create_prize_order($conn, $userId, $prizeCfg, $cacheFile, $raffleId);
        $upd = $conn->prepare("UPDATE raffle_winners SET prize_state = 'selected', final_prize = ?, prize_selected_at = UTC_TIMESTAMP(), prize_sent = 1, generated_order_id = ? WHERE id = ?");
        $upd->bind_param("sii", $finalPrizeJson, $orderId, $winId);
        $upd->execute();
        $couponPct = rr_prize_discount_percent($prizeCfg);
        $msg = $couponPct > 0
            ? "¡Elección confirmada: $chosenName! Tu cupón {$couponPct}% OFF ya está activo en la tienda para tu cuenta."
            : "¡Elección confirmada: $chosenName! Revisa 'Mis Pedidos'.";
    }

    rr_raffle_close_if_all_decided($conn, $raffleId);
    $conn->commit();
    echo json_encode([
        'status'      => 'success',
        'message'     => $msg,
        'final_prize' => $chosen,
        'prize_state' => ($kind === 'category') ? 'admin_pending' : 'selected',
    ]);

} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>

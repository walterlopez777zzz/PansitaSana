<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/session_security.php';
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_connect.php';

function sendJsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

if (!isset($_SESSION['user_id'])) {
    sendJsonResponse(['success' => false, 'message' => 'No logueado'], 401);
}

$user_id = (int)$_SESSION['user_id'];

try {
    $enable_manual_verification_requests = false;
    $stmtManual = $conn->prepare("SELECT value FROM site_settings WHERE key_name = 'enable_manual_verification_requests' LIMIT 1");
    if ($stmtManual) {
        $stmtManual->execute();
        $rowManual = $stmtManual->get_result()->fetch_assoc();
        $stmtManual->close();

        if ($rowManual && isset($rowManual['value'])) {
            $enable_manual_verification_requests = (strtolower(trim((string)$rowManual['value'])) === 'true');
        }
    }

    if (!$enable_manual_verification_requests) {
        sendJsonResponse(['success' => false, 'message' => 'Las solicitudes manuales están cerradas.'], 400);
    }

    $stmtUser = $conn->prepare('SELECT game_username, public_display_name, public_display_name_updated_at, whatsapp_phone_e164, is_verified, verification_rejected FROM users WHERE id = ? LIMIT 1');
    if (!$stmtUser) {
        throw new Exception('No se pudo preparar consulta de usuario');
    }
    $stmtUser->bind_param('i', $user_id);
    $stmtUser->execute();
    $user = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();

    if (!$user) {
        sendJsonResponse(['success' => false, 'message' => 'Usuario no encontrado'], 404);
    }

    $game_username = trim((string)($user['game_username'] ?? ''));
    $public_display_name = trim((string)($user['public_display_name'] ?? ''));
    $public_display_name_updated_at = $user['public_display_name_updated_at'] ?? null;
    $whatsapp_phone_e164 = trim((string)($user['whatsapp_phone_e164'] ?? ''));
    $is_verified = ((int)($user['is_verified'] ?? 0)) === 1;

    if ($is_verified) {
        sendJsonResponse(['success' => false, 'message' => 'Ya estás verificado.'], 400);
    }

    if (!empty($user['verification_rejected'])) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Tu solicitud ya fue evaluada. Por favor, usa la verificación automática desde la web.'
        ], 403);
    }

    $public_name_was_updated = ($public_display_name_updated_at !== null && trim((string)$public_display_name_updated_at) !== '' && (string)$public_display_name_updated_at !== '0000-00-00 00:00:00');

    if ($game_username === '' || $public_display_name === '' || !$public_name_was_updated || $whatsapp_phone_e164 === '' || strlen($whatsapp_phone_e164) < 6) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Completa tu perfil: Necesitamos tu Nick de Epic, Nombre Público y un WhatsApp válido para verificarte.'
        ], 400);
    }

    $stmtPending = $conn->prepare("SELECT id FROM verification_requests WHERE user_id = ? AND status = 'pending' LIMIT 1");
    if (!$stmtPending) {
        throw new Exception('No se pudo preparar consulta de solicitudes');
    }
    $stmtPending->bind_param('i', $user_id);
    $stmtPending->execute();
    $pending = $stmtPending->get_result()->fetch_assoc();
    $stmtPending->close();

    if ($pending) {
        sendJsonResponse(['success' => false, 'message' => 'Ya tienes una solicitud en proceso.'], 400);
    }

    $current_spend = 0.0;
    $stmtStats = $conn->prepare('SELECT total_spent_completed_self FROM user_stats WHERE user_id = ? LIMIT 1');
    if ($stmtStats) {
        $stmtStats->bind_param('i', $user_id);
        $stmtStats->execute();
        $rowStats = $stmtStats->get_result()->fetch_assoc();
        $stmtStats->close();

        if ($rowStats && isset($rowStats['total_spent_completed_self']) && is_numeric($rowStats['total_spent_completed_self'])) {
            $current_spend = (float)$rowStats['total_spent_completed_self'];
        }
    }

    // Solo se reconocen pedidos históricos de invitado desde 2026 en adelante
    // (fechas en UTC), igual que en la auto-verificación.
    $historical_orders_min_date = '2026-01-01 00:00:00';

    $historical_amount = 0.0;
    if ($game_username !== '') {
        $stmtHistorical = $conn->prepare("SELECT COALESCE(SUM(total_pagar), 0) AS historical_amount FROM pedidos WHERE nickname_fortnite_cliente = ? AND estado = 'completado' AND user_id IS NULL AND fecha_creacion >= ?");
        if ($stmtHistorical) {
            $stmtHistorical->bind_param('ss', $game_username, $historical_orders_min_date);
            $stmtHistorical->execute();
            $rowHistorical = $stmtHistorical->get_result()->fetch_assoc();
            $stmtHistorical->close();

            if ($rowHistorical && isset($rowHistorical['historical_amount']) && is_numeric($rowHistorical['historical_amount'])) {
                $historical_amount = (float)$rowHistorical['historical_amount'];
            }
        }
    }

    $grand_total = $current_spend + $historical_amount;

    $sqlInsert = "INSERT INTO verification_requests (user_id, game_username_snapshot, whatsapp_snapshot, status, created_at, calculated_spend) VALUES (?, ?, ?, 'pending', NOW(), ?)";
    $stmtInsert = $conn->prepare($sqlInsert);
    if (!$stmtInsert) {
        throw new Exception('No se pudo preparar inserción');
    }

    $grand_total_float = (float)$grand_total;
    $stmtInsert->bind_param('issd', $user_id, $game_username, $whatsapp_phone_e164, $grand_total_float);
    $stmtInsert->execute();
    $stmtInsert->close();

    sendJsonResponse([
        'success' => true,
        'message' => 'Solicitud enviada correctamente. Revisaremos tu historial pronto.'
    ]);
} catch (Throwable $e) {
    error_log('request_verification error: ' . $e->getMessage());
    sendJsonResponse(['success' => false, 'message' => 'Error interno'], 500);
}
?>

<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/session_security.php';
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_connect.php';

function sendJsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
    exit;
}

if (!isset($_SESSION['user_id'])) {
    sendJsonResponse(['success' => false, 'message' => 'No logueado'], 401);
}

$user_id = (int)$_SESSION['user_id'];

try {
    $threshold = 50.00;

    $stmtThreshold = $conn->prepare("SELECT value FROM site_settings WHERE key_name = 'min_total_spent_for_verification' LIMIT 1");
    if ($stmtThreshold) {
        $stmtThreshold->execute();
        $rowThreshold = $stmtThreshold->get_result()->fetch_assoc();
        $stmtThreshold->close();

        if ($rowThreshold && isset($rowThreshold['value']) && is_numeric($rowThreshold['value'])) {
            $threshold = (float)$rowThreshold['value'];
        }
    }

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

    $stmtUser = $conn->prepare('SELECT id, game_username, whatsapp_phone_e164, is_verified, verification_rejected FROM users WHERE id = ? LIMIT 1');
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
    $whatsapp_phone_e164 = trim((string)($user['whatsapp_phone_e164'] ?? ''));
    $is_verified = ((int)($user['is_verified'] ?? 0)) === 1;
    $verification_rejected = ((int)($user['verification_rejected'] ?? 0)) === 1;

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

    $historical_amount = 0.0;
    $phone_match_count = 0;
    $matched_amount = 0.0; // monto de pedidos pre-login que coinciden con nickname + teléfono

    // Solo se reconocen pedidos históricos de invitado realizados desde 2026 en
    // adelante; las compras anteriores no cuentan para el progreso ni para la
    // auto-verificación (las fechas se almacenan en UTC).
    $historical_orders_min_date = '2026-01-01 00:00:00';

    if ($game_username !== '') {
        $phone_digits = preg_replace('/\D/', '', $whatsapp_phone_e164);
        if ($phone_digits !== '' && strlen($phone_digits) >= 7) {
            $sqlHistorical = "SELECT 
                    COALESCE(SUM(total_pagar), 0) AS historical_amount,
                    COALESCE(SUM(CASE WHEN REPLACE(REPLACE(REPLACE(whatsapp_discord_cliente, '+', ''), ' ', ''), '-', '') LIKE CONCAT('%', ?) THEN total_pagar ELSE 0 END), 0) AS matched_amount,
                    COALESCE(SUM(CASE WHEN REPLACE(REPLACE(REPLACE(whatsapp_discord_cliente, '+', ''), ' ', ''), '-', '') LIKE CONCAT('%', ?) THEN 1 ELSE 0 END), 0) AS phone_match_count
                FROM pedidos
                WHERE nickname_fortnite_cliente = ?
                AND estado = 'completado'
                AND user_id IS NULL
                AND fecha_creacion >= ?";

            $stmtHistorical = $conn->prepare($sqlHistorical);
            if ($stmtHistorical) {
                $stmtHistorical->bind_param('ssss', $phone_digits, $phone_digits, $game_username, $historical_orders_min_date);
                $stmtHistorical->execute();
                $rowHistorical = $stmtHistorical->get_result()->fetch_assoc();
                $stmtHistorical->close();

                if ($rowHistorical) {
                    if (isset($rowHistorical['historical_amount']) && is_numeric($rowHistorical['historical_amount'])) {
                        $historical_amount = (float)$rowHistorical['historical_amount'];
                    }
                    if (isset($rowHistorical['matched_amount']) && is_numeric($rowHistorical['matched_amount'])) {
                        $matched_amount = (float)$rowHistorical['matched_amount'];
                    }
                    if (isset($rowHistorical['phone_match_count']) && is_numeric($rowHistorical['phone_match_count'])) {
                        $phone_match_count = (int)$rowHistorical['phone_match_count'];
                    }
                }
            }

            // --- VINCULACIÓN RETROACTIVA DE PEDIDOS PRE-LOGIN ---
            // Antes de Marzo 2026 las compras se hacían como invitado (nickname + número,
            // sin cuenta), por lo que quedan con user_id IS NULL y son invisibles para las
            // reglas que cuentan pedidos por user_id (ej. "compras recientes" del sorteo).
            // Reutilizamos el MISMO emparejamiento que la auto-verificación (nickname exacto
            // + teléfono) para evitar impostores y asociarlos a esta cuenta.
            if ($phone_match_count > 0) {
                $conn->begin_transaction();
                try {
                    $linkSql = "UPDATE pedidos SET user_id = ?
                                WHERE nickname_fortnite_cliente = ?
                                AND estado = 'completado'
                                AND user_id IS NULL
                                AND fecha_creacion >= ?
                                AND REPLACE(REPLACE(REPLACE(whatsapp_discord_cliente, '+', ''), ' ', ''), '-', '') LIKE CONCAT('%', ?)";
                    $linked_count = 0;
                    $stmtLink = $conn->prepare($linkSql);
                    if ($stmtLink) {
                        $stmtLink->bind_param('isss', $user_id, $game_username, $historical_orders_min_date, $phone_digits);
                        $stmtLink->execute();
                        $linked_count = $stmtLink->affected_rows;
                        $stmtLink->close();
                    }

                    // Conservamos el progreso de verificación: al vincular, el monto
                    // histórico deja de contarse vía "user_id IS NULL", así que lo
                    // trasladamos a user_stats para que current_spend lo siga reflejando.
                    if ($linked_count > 0) {
                        $bumpSql = "INSERT INTO user_stats (user_id, total_spent_completed_self, orders_count_completed_self)
                                    VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE
                                        total_spent_completed_self = total_spent_completed_self + VALUES(total_spent_completed_self),
                                        orders_count_completed_self = orders_count_completed_self + VALUES(orders_count_completed_self)";
                        $stmtBump = $conn->prepare($bumpSql);
                        if ($stmtBump) {
                            $stmtBump->bind_param('idi', $user_id, $matched_amount, $linked_count);
                            $stmtBump->execute();
                            $stmtBump->close();
                        }
                    }

                    $conn->commit();
                } catch (Throwable $linkErr) {
                    $conn->rollback();
                    error_log('legacy order auto-link error: ' . $linkErr->getMessage());
                }
            }
        } else {
            $stmtHistorical = $conn->prepare("SELECT COALESCE(SUM(total_pagar), 0) AS historical_amount FROM pedidos WHERE nickname_fortnite_cliente = ? AND estado = 'completado' AND user_id IS NULL AND fecha_creacion >= ?");
            if ($stmtHistorical) {
                $stmtHistorical->bind_param('ss', $game_username, $historical_orders_min_date);
                $stmtHistorical->execute();
                $rowHistorical = $stmtHistorical->get_result()->fetch_assoc();
                $stmtHistorical->close();

                if ($rowHistorical && isset($rowHistorical['historical_amount']) && is_numeric($rowHistorical['historical_amount'])) {
                    $historical_amount = (float)$rowHistorical['historical_amount'];
                }
                $phone_match_count = 0;
            }
        }
    }

    // Gasto verificable: solo cuenta el gasto atado a la propia cuenta
    // (current_spend, imposible de falsificar) más los pedidos históricos de
    // invitado que coinciden por nickname + teléfono (matched_amount). El gasto
    // histórico que solo coincide por nickname (sin teléfono) NO se cuenta, para
    // evitar que un impostor herede el historial de gasto de otra persona.
    $verified_spend = $current_spend + $matched_amount;

    $progress_percent = 0;
    if ($threshold > 0) {
        $progress_percent = (int)floor(($verified_spend / $threshold) * 100);
    } else {
        $progress_percent = 100;
    }
    if ($progress_percent < 0) $progress_percent = 0;
    if ($progress_percent > 100) $progress_percent = 100;

    $just_verified = false;

    // Auto-verificación reactiva: si el gasto verificable alcanza el umbral
    // vigente, se verifica al usuario aunque no tenga pedidos históricos de
    // invitado, y aunque su solicitud manual haya sido rechazada o esté
    // pendiente. Se recalcula siempre contra el gasto y el umbral ACTUALES, por
    // lo que nunca verifica a quien ya no cumple el mínimo vigente.
    if ($threshold > 0 && $verified_spend >= $threshold && !$is_verified) {
        $hasVerifiedBy = false;
        $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'verified_by'");
        if ($colCheck && $colCheck->num_rows > 0) {
            $hasVerifiedBy = true;
        }

        $sqlUpdate = $hasVerifiedBy
            ? "UPDATE users SET is_verified = 1, verified_at = NOW(), verified_by = 'SYSTEM_AUTO', verification_rejected = 0 WHERE id = ? AND is_verified = 0"
            : 'UPDATE users SET is_verified = 1, verified_at = NOW(), verification_rejected = 0 WHERE id = ? AND is_verified = 0';

        $stmtUpdate = $conn->prepare($sqlUpdate);
        if ($stmtUpdate) {
            $stmtUpdate->bind_param('i', $user_id);
            $stmtUpdate->execute();
            $affected = $stmtUpdate->affected_rows;
            $stmtUpdate->close();

            if ($affected > 0) {
                $is_verified = true;
                $just_verified = true;
                $verification_rejected = false;

                // Cierra cualquier solicitud manual pendiente para mantener
                // coherente el panel de administración tras la auto-verificación.
                $stmtClosePending = $conn->prepare("UPDATE verification_requests SET status = 'approved', reviewed_at = NOW(), admin_notes = CONCAT(COALESCE(admin_notes, ''), '[Auto-verificado por el sistema al alcanzar el umbral de gasto]') WHERE user_id = ? AND status = 'pending'");
                if ($stmtClosePending) {
                    $stmtClosePending->bind_param('i', $user_id);
                    $stmtClosePending->execute();
                    $stmtClosePending->close();
                }
            }
        }
    }

    $has_pending_request = false;
    if (!$is_verified) {
        $stmtPending = $conn->prepare("SELECT id FROM verification_requests WHERE user_id = ? AND status = 'pending' LIMIT 1");
        if ($stmtPending) {
            $stmtPending->bind_param('i', $user_id);
            $stmtPending->execute();
            $pendingRow = $stmtPending->get_result()->fetch_assoc();
            $stmtPending->close();
            $has_pending_request = (bool)$pendingRow;
        }
    }

    $can_request_manual = (!$is_verified && !$has_pending_request && !$verification_rejected && $enable_manual_verification_requests);

    sendJsonResponse([
        'success' => true,
        'is_verified' => (bool)$is_verified,
        'just_auto_verified' => (bool)$just_verified,
        'has_pending_request' => (bool)$has_pending_request,
        'verification_rejected' => (bool)$verification_rejected,
        'progress_percent' => (int)$progress_percent,
        'can_request_manual' => (bool)$can_request_manual
    ]);
} catch (Throwable $e) {
    error_log('get_verification_status error: ' . $e->getMessage());
    sendJsonResponse(['success' => false, 'message' => 'Error interno'], 500);
}
?>

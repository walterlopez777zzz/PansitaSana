<?php
// Banano Store - API de Gestión de Verificaciones v1.0
// api_verificacionusu.php

require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';

// --- MEDIDA DE SEGURIDAD CRÍTICA ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';
$response = [];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $conn->begin_transaction();
    }

    switch ($action) {

        // =============================================================
        // === OBTENER DETALLES COMPLETOS DE UNA SOLICITUD (MODAL) =====
        // =============================================================
        case 'get_request_details':
            $request_id = (int)($_GET['request_id'] ?? 0);
            if ($request_id <= 0) throw new Exception('ID de solicitud no válido.');

            $stmt = $conn->prepare("SELECT * FROM verification_requests WHERE id = ?");
            $stmt->bind_param('i', $request_id);
            $stmt->execute();
            $request_data = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$request_data) throw new Exception('Solicitud no encontrada.');

            $user_data = null;
            $completed_orders = 0;
            $order_history = [];
            $total_spent = 0;
            $uid = (int)($request_data['user_id'] ?? 0);

            if ($uid > 0) {
                // --- User data ---
                $stmt_u = $conn->prepare(
                    "SELECT id, public_code, email, game_username, public_display_name,
                            whatsapp_phone_e164, is_verified, verified_at, account_status, created_at
                     FROM users WHERE id = ? LIMIT 1"
                );
                $stmt_u->bind_param('i', $uid);
                $stmt_u->execute();
                $user_data = $stmt_u->get_result()->fetch_assoc();
                $stmt_u->close();

                // --- Smart Data Fallback (Null Coalescing) ---
                if ($user_data) {
                    if (empty($request_data['game_username_snapshot'])) {
                        $request_data['game_username_snapshot'] = $user_data['game_username'] ?? null;
                    }
                    if (empty($request_data['whatsapp_snapshot'])) {
                        $request_data['whatsapp_snapshot'] = $user_data['whatsapp_phone_e164'] ?? null;
                    }
                }

                // --- Completed orders count ---
                $stmt_c = $conn->prepare(
                    "SELECT COUNT(*) as cnt FROM pedidos WHERE user_id = ? AND estado = 'completado'"
                );
                $stmt_c->bind_param('i', $uid);
                $stmt_c->execute();
                $row_c = $stmt_c->get_result()->fetch_assoc();
                $completed_orders = (int)($row_c['cnt'] ?? 0);
                $stmt_c->close();

                // --- Order History: pedidos + detalles_pedido ---
                $stmt_oh = $conn->prepare(
                    "SELECT p.id as pedido_id, p.codigo_pedido, p.fecha_completado,
                            dp.nombre_producto, dp.cantidad, dp.precio_final
                     FROM pedidos p
                     LEFT JOIN detalles_pedido dp ON dp.pedido_id = p.id
                     WHERE p.user_id = ? AND p.estado = 'completado'
                     ORDER BY p.fecha_completado DESC, dp.id ASC"
                );
                $stmt_oh->bind_param('i', $uid);
                $stmt_oh->execute();
                $result_oh = $stmt_oh->get_result();

                $orders_map = [];
                while ($row_oh = $result_oh->fetch_assoc()) {
                    $pid = $row_oh['pedido_id'];
                    if (!isset($orders_map[$pid])) {
                        $orders_map[$pid] = [
                            'codigo_pedido'    => $row_oh['codigo_pedido'],
                            'fecha_completado' => $row_oh['fecha_completado'],
                            'items'            => []
                        ];
                    }
                    if (!empty($row_oh['nombre_producto'])) {
                        $item_total   = floatval($row_oh['precio_final']) * intval($row_oh['cantidad']);
                        $total_spent += $item_total;
                        $orders_map[$pid]['items'][] = [
                            'nombre_producto' => $row_oh['nombre_producto'],
                            'cantidad'        => (int)$row_oh['cantidad'],
                            'precio_final'    => floatval($row_oh['precio_final'])
                        ];
                    }
                }
                $stmt_oh->close();

                $order_history = array_values($orders_map);
            }

            $response = [
                'status'           => 'success',
                'request'          => $request_data,
                'user'             => $user_data,
                'completed_orders' => $completed_orders,
                'order_history'    => $order_history,
                'total_spent'      => round($total_spent, 2)
            ];
            break;

        // =============================================================
        // === APROBAR SOLICITUD DE VERIFICACIÓN =======================
        // =============================================================
        case 'approve_request':
            $request_id = (int)($_POST['request_id'] ?? 0);
            if ($request_id <= 0) throw new Exception('ID de solicitud no válido.');

            $admin_notes       = trim($_POST['admin_notes'] ?? '');
            $edited_game_user  = trim($_POST['game_username'] ?? '');
            $edited_display    = trim($_POST['public_display_name'] ?? '');
            $edited_whatsapp   = trim($_POST['whatsapp_phone_e164'] ?? '');

            // Verificar que la solicitud existe y está pendiente
            $stmt = $conn->prepare("SELECT id, user_id FROM verification_requests WHERE id = ? AND status = 'pending'");
            $stmt->bind_param('i', $request_id);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$request) throw new Exception('Solicitud pendiente no encontrada o ya fue procesada.');

            $user_id  = (int)$request['user_id'];
            $admin_id = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);

            // 1. Actualizar verification_requests
            $stmt_vr = $conn->prepare(
                "UPDATE verification_requests SET status = 'approved', reviewed_at = NOW(), admin_notes = ? WHERE id = ?"
            );
            $stmt_vr->bind_param('si', $admin_notes, $request_id);
            if (!$stmt_vr->execute()) throw new Exception('Error al actualizar solicitud: ' . $stmt_vr->error);
            $stmt_vr->close();

            // 2. Actualizar users — campos de verificación + campos editados dinámicamente
            $sql_user = "UPDATE users SET is_verified = 1, verified_at = NOW(), verified_by = ?, verified_notes = ?";
            $types  = 'is';
            $params = [$admin_id, $admin_notes];

            if ($edited_game_user !== '') {
                $sql_user .= ", game_username = ?, game_username_norm = LOWER(?)";
                $types  .= 'ss';
                $params[] = $edited_game_user;
                $params[] = $edited_game_user;
            }

            if ($edited_display !== '') {
                $sql_user .= ", public_display_name = ?, public_display_name_norm = LOWER(?)";
                $types  .= 'ss';
                $params[] = $edited_display;
                $params[] = $edited_display;
            }

            if ($edited_whatsapp !== '') {
                $sql_user .= ", whatsapp_phone_e164 = ?";
                $types  .= 's';
                $params[] = $edited_whatsapp;
            }

            $sql_user .= " WHERE id = ?";
            $types  .= 'i';
            $params[] = $user_id;

            $stmt_user = $conn->prepare($sql_user);
            if (!$stmt_user) throw new Exception('Error al preparar actualización de usuario: ' . $conn->error);
            $stmt_user->bind_param($types, ...$params);
            if (!$stmt_user->execute()) throw new Exception('Error al actualizar usuario: ' . $stmt_user->error);
            $stmt_user->close();

            // 3. Registrar en admin_actions_log
            $log_action  = 'verification_approved';
            $log_details = json_encode([
                'request_id'    => $request_id,
                'admin_notes'   => $admin_notes,
                'edited_fields' => array_filter([
                    'game_username'      => $edited_game_user  ?: null,
                    'public_display_name' => $edited_display   ?: null,
                    'whatsapp_phone_e164' => $edited_whatsapp  ?: null
                ])
            ], JSON_UNESCAPED_UNICODE);

            $stmt_log = $conn->prepare(
                "INSERT INTO admin_actions_log (admin_id, target_user_id, action, details, created_at) VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt_log->bind_param('iiss', $admin_id, $user_id, $log_action, $log_details);
            if (!$stmt_log->execute()) throw new Exception('Error al registrar log: ' . $stmt_log->error);
            $stmt_log->close();

            $response = ['status' => 'success', 'message' => 'Verificación aprobada correctamente.'];
            break;

        // =============================================================
        // === RECHAZAR SOLICITUD DE VERIFICACIÓN ======================
        // =============================================================
        case 'reject_request':
            $request_id = (int)($_POST['request_id'] ?? 0);
            if ($request_id <= 0) throw new Exception('ID de solicitud no válido.');

            $admin_notes = trim($_POST['admin_notes'] ?? '');

            // Verificar que la solicitud existe y está pendiente
            $stmt = $conn->prepare("SELECT id, user_id FROM verification_requests WHERE id = ? AND status = 'pending'");
            $stmt->bind_param('i', $request_id);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$request) throw new Exception('Solicitud pendiente no encontrada o ya fue procesada.');

            $user_id  = (int)$request['user_id'];
            $admin_id = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);

            // 1. Actualizar verification_requests
            $stmt_vr = $conn->prepare(
                "UPDATE verification_requests SET status = 'rejected', reviewed_at = NOW(), admin_notes = ? WHERE id = ?"
            );
            $stmt_vr->bind_param('si', $admin_notes, $request_id);
            if (!$stmt_vr->execute()) throw new Exception('Error al actualizar solicitud: ' . $stmt_vr->error);
            $stmt_vr->close();

            // 2. Marcar rechazo permanente en users
            $stmt_flag = $conn->prepare("UPDATE users SET verification_rejected = 1 WHERE id = ?");
            $stmt_flag->bind_param('i', $user_id);
            if (!$stmt_flag->execute()) throw new Exception('Error al marcar rechazo en usuario: ' . $stmt_flag->error);
            $stmt_flag->close();

            // 3. Registrar en admin_actions_log
            $log_action  = 'verification_rejected';
            $log_details = json_encode([
                'request_id'  => $request_id,
                'admin_notes' => $admin_notes
            ], JSON_UNESCAPED_UNICODE);

            $stmt_log = $conn->prepare(
                "INSERT INTO admin_actions_log (admin_id, target_user_id, action, details, created_at) VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt_log->bind_param('iiss', $admin_id, $user_id, $log_action, $log_details);
            if (!$stmt_log->execute()) throw new Exception('Error al registrar log: ' . $stmt_log->error);
            $stmt_log->close();

            $response = ['status' => 'success', 'message' => 'Verificación rechazada.'];
            break;

        default:
            throw new Exception('Acción no reconocida.');
    }

    // Confirmar transacción para escrituras
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $conn->commit();
    }

} catch (Exception $e) {
    if (property_exists($conn, 'in_transaction') && $conn->in_transaction) $conn->rollback();
    http_response_code(400);
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

echo json_encode($response);
$conn->close();
?>

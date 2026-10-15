<?php
// Banano Store - API Privada de Gestión de Pedidos v1.1
// api_pedidos.php

require_once __DIR__ . '/../../../api/session_security.php';
require_once __DIR__ . '/../../core/db_connect.php'; 
require_once __DIR__ . '/order_finance_helpers.php';

// UTC-FIRST: el panel admin lee/escribe en UTC puro. Pinneando la sesión MySQL a UTC,
// las fechas (incl. columnas TIMESTAMP) salen como UTC real hacia el JS, que las convierte
// a la zona local del navegador. Las comparaciones del polling ya usan UTC_TIMESTAMP().
if (isset($conn) && $conn instanceof mysqli) { $conn->query("SET time_zone = '+00:00'"); }
// --- FUNCIONES DE AYUDA ADAPTADAS DE cart_manager.php ---
function findApiProductInCache(string $offerId, string $cache_path): ?array {
    if (!file_exists($cache_path)) return null;
    $cache_data = json_decode(file_get_contents($cache_path), true);
    
    // Adaptado para la estructura de tu nuevo caché
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($cache_data)) return null;

    foreach ($cache_data as $item) {
        if (($item['offerId'] ?? null) === $offerId) {
            return [
                'displayType' => $item['displayType'] ?? null,
                'vbucks'      => $item['vbucks'] ?? 0,
                'rarity'      => $item['rarity'] ?? null,
                'offerDates'  => $item['offerDates'] ?? null
            ];
        }
    }
    return null;
}
// --- FIN DE FUNCIONES DE AYUDA ---

// --- MEDIDA DE SEGURIDAD CRÍTICA ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Obtenemos la acción. Si la petición es GET, la buscamos en GET, sino en POST.
$action = $_REQUEST['action'] ?? '';
$response = [];

try {
    // La transacción solo es necesaria para acciones de escritura (POST).
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $conn->begin_transaction();
    }

    switch ($action) {
        
        case 'get_order_details':
            $order_id = (int)($_GET['order_id'] ?? 0);
            if ($order_id <= 0) {
                throw new Exception('ID de pedido no válido.');
            }

            // Datos principales del pedido
            $stmt_main = $conn->prepare("SELECT * FROM pedidos WHERE id = ?");
            $stmt_main->bind_param('i', $order_id);
            $stmt_main->execute();
            $result_main = $stmt_main->get_result();
            if ($result_main->num_rows === 0) throw new Exception('Pedido no encontrado.');
            $order_data = $result_main->fetch_assoc();
            $stmt_main->close();

            $user_context = null;
            $session_type = 'Invitado';
            if (!empty($order_data['user_id'])) {
                $uid = (int)$order_data['user_id'];
                if ($uid > 0) {
                    $session_type = 'Logueado';
                    $sql_user = "
                        SELECT 
                            u.id, u.public_display_name, u.game_username, u.email, u.public_code, u.is_verified, u.avatar_id, u.account_status, u.created_at,
                            COALESCE(c.balance, 0.00) as coins_balance,
                            a.filename as avatar_filename,
                            COALESCE(us.orders_count_completed_self, 0) as orders_count_completed_self,
                            COALESCE(us.total_spent_completed_self, 0.00) as total_spent_completed_self
                        FROM users u
                        LEFT JOIN coins c ON u.id = c.user_id
                        LEFT JOIN avatars a ON u.avatar_id = a.id
                        LEFT JOIN user_stats us ON u.id = us.user_id
                        WHERE u.id = ?
                        LIMIT 1
                    ";

                    $stmt_user = $conn->prepare($sql_user);
                    if ($stmt_user) {
                        $stmt_user->bind_param('i', $uid);
                        $stmt_user->execute();
                        $user_context = $stmt_user->get_result()->fetch_assoc();
                        $stmt_user->close();
                    }

                    if ($user_context) {
                        // Progreso de verificación (misma base que api/get_verification_status.php:
                        // gasto propio completado vs umbral). Solo relevante para no verificados.
                        $user_context['verification'] = null;
                        if ((int)($user_context['is_verified'] ?? 0) !== 1) {
                            $threshold = bs_setting_float($conn, 'min_total_spent_for_verification', 50.0);
                            $spent_self = (float)($user_context['total_spent_completed_self'] ?? 0);
                            $pct = $threshold > 0 ? (int)floor(($spent_self / $threshold) * 100) : 100;
                            $user_context['verification'] = [
                                'threshold' => round($threshold, 2),
                                'spent'     => round($spent_self, 2),
                                'percent'   => max(0, min(100, $pct)),
                            ];
                        }

                        // Historial compacto: otros pedidos del mismo usuario (los más recientes).
                        $user_context['recent_orders'] = [];
                        $stmt_hist = $conn->prepare(
                            "SELECT p.id, p.codigo_pedido, p.estado, p.total_pagar, p.fecha_creacion,
                                    (SELECT COUNT(*) FROM detalles_pedido d WHERE d.pedido_id = p.id) AS items_count,
                                    (SELECT d2.nombre_producto FROM detalles_pedido d2 WHERE d2.pedido_id = p.id ORDER BY d2.id ASC LIMIT 1) AS first_item
                               FROM pedidos p
                              WHERE p.user_id = ? AND p.id <> ?
                              ORDER BY p.fecha_creacion DESC
                              LIMIT 8"
                        );
                        if ($stmt_hist) {
                            $stmt_hist->bind_param('ii', $uid, $order_id);
                            $stmt_hist->execute();
                            $res_hist = $stmt_hist->get_result();
                            while ($h = $res_hist->fetch_assoc()) $user_context['recent_orders'][] = $h;
                            $stmt_hist->close();
                        }
                        $stmt_cnt = $conn->prepare("SELECT COUNT(*) AS c FROM pedidos WHERE user_id = ? AND id <> ?");
                        if ($stmt_cnt) {
                            $stmt_cnt->bind_param('ii', $uid, $order_id);
                            $stmt_cnt->execute();
                            $rc = $stmt_cnt->get_result()->fetch_assoc();
                            $stmt_cnt->close();
                            $user_context['orders_count_previous'] = (int)($rc['c'] ?? 0);
                        }
                    }
                }
            }

            // Proyección de Bananos: cashback pendiente/entregado y Bananos gastados aún retenidos.
            $finance = [
                'cashback_projected'  => round((float)($order_data['cashback_earned'] ?? 0), 2),
                'cashback_delivered'  => 0.0,
                'coins_redeemed'      => round((float)($order_data['coins_redeemed'] ?? 0), 2),
                'coins_outstanding'   => 0.0,
                'editable'            => in_array((string)$order_data['estado'], BS_EDITABLE_STATUSES, true),
            ];
            if (!empty($order_data['user_id'])) {
                $finance['cashback_delivered'] = bs_order_cashback_delivered($conn, $order_id, (int)$order_data['user_id']);
                $finance['coins_outstanding'] = bs_order_coins_outstanding($conn, $order_id, (int)$order_data['user_id']);
            }

            // Productos del pedido (datos puros de la BD)
            $sql_details = "
                SELECT 
                    dp.id AS detalle_id,
                    dp.nombre_producto, dp.categoria_producto, dp.cantidad, dp.tipo_producto,
                    dp.precio_final, dp.precio_base, dp.producto_id_str,
                    dp.imagen_producto_url,
                    dp.section_name, dp.store_rank, dp.v_bucks,
                    dp.fecha_expiracion_item,
                    dp.color_1, dp.color_2, dp.color_3,
                    dpd.nickname_destinatario
                FROM detalles_pedido dp
                LEFT JOIN detalles_pedido_destinatarios dpd ON dp.id = dpd.detalle_pedido_id
                WHERE dp.pedido_id = ? ORDER BY dp.id ASC";
            $stmt_details = $conn->prepare($sql_details);
            $stmt_details->bind_param('i', $order_id);
            $stmt_details->execute();
            $result_details = $stmt_details->get_result();
            $products_data = [];
            while($row = $result_details->fetch_assoc()) {
                $products_data[] = $row;
            }
            $stmt_details->close();

            // Devolver la respuesta sin procesar
            $response = [
                'status' => 'success',
                'order' => $order_data,
                'user' => $user_context,
                'session_type' => $session_type,
                'products' => $products_data,
                'finance' => $finance
            ];
            break;

        // --- ACCIÓN: CATÁLOGO PARA EL BUSCADOR DEL MODO EDICIÓN ---
        case 'get_edit_catalog':
            $response = ['status' => 'success', 'data' => bs_edit_catalog($conn)];
            break;

        // --- ACCIÓN: PREVIEW / APLICAR EDICIÓN DE PRODUCTOS DE UN PEDIDO ---
        case 'preview_order_edit':
        case 'apply_order_edit':
            $payload = json_decode((string)($_POST['payload'] ?? ''), true);
            if (!is_array($payload)) throw new Exception('Payload de edición no válido.');
            $order_id = (int)($payload['order_id'] ?? 0);
            if ($order_id <= 0) throw new Exception('ID de pedido no válido.');
            $remove_ids = is_array($payload['remove_detail_ids'] ?? null) ? $payload['remove_detail_ids'] : [];
            $add_items = is_array($payload['add_items'] ?? null) ? $payload['add_items'] : [];
            if (!$remove_ids && !$add_items) throw new Exception('No hay cambios que aplicar.');

            $stmt_o = $conn->prepare("SELECT * FROM pedidos WHERE id = ? LIMIT 1" . ($action === 'apply_order_edit' ? ' FOR UPDATE' : ''));
            if (!$stmt_o) throw new Exception('Error al preparar la consulta del pedido.');
            $stmt_o->bind_param('i', $order_id);
            $stmt_o->execute();
            $edit_order = $stmt_o->get_result()->fetch_assoc();
            $stmt_o->close();
            if (!$edit_order) throw new Exception('Pedido no encontrado.');

            $calc = bs_compute_order_edit($conn, $edit_order, $remove_ids, $add_items);

            if ($action === 'preview_order_edit') {
                $response = ['status' => 'success', 'preview' => $calc];
                break;
            }

            bs_apply_order_edit($conn, $edit_order, $calc);

            $stmt_get = $conn->prepare("SELECT * FROM pedidos WHERE id = ? LIMIT 1");
            if (!$stmt_get) throw new Exception('Error al preparar la consulta de verificación.');
            $stmt_get->bind_param('i', $order_id);
            $stmt_get->execute();
            $updated_order = $stmt_get->get_result()->fetch_assoc();
            $stmt_get->close();

            $response = ['status' => 'success', 'message' => 'Pedido actualizado.', 'order' => $updated_order, 'applied' => $calc];
            break;

        case 'ban_user':
            $user_id = (int)($_POST['user_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');

            if ($user_id <= 0) {
                throw new Exception('ID de usuario no válido.');
            }

            $stmt_ban = $conn->prepare("UPDATE users SET account_status = 'banned', ban_reason = ?, banned_at = NOW() WHERE id = ?");
            if (!$stmt_ban) {
                throw new Exception('No se pudo preparar el baneo de usuario.');
            }
            $stmt_ban->bind_param('si', $reason, $user_id);
            if (!$stmt_ban->execute()) {
                throw new Exception($stmt_ban->error ?: 'Error al banear usuario.');
            }
            $stmt_ban->close();

            $response = ['status' => 'success', 'message' => 'Usuario baneado correctamente.'];
            break;

        case 'get_initial_details':
            // Recibe una lista de IDs de pedidos separados por coma
            $order_ids_str = $_GET['ids'] ?? '';
            if (empty($order_ids_str)) {
                $response = ['status' => 'success', 'details' => []];
                break;
            }

            // Convertimos el string a un array de enteros seguros
            $order_ids = array_map('intval', explode(',', $order_ids_str));
            $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
            $types = str_repeat('i', count($order_ids));

            // Preparamos los arrays para los resultados
            $details_by_order = [];
            $recipients_by_order = [];

            // 1. Obtenemos todos los detalles de una vez
            $sql_details = "SELECT pedido_id, nombre_producto, categoria_producto, producto_id_str FROM detalles_pedido WHERE pedido_id IN ($placeholders)";
            $stmt_details = $conn->prepare($sql_details);
            $stmt_details->bind_param($types, ...$order_ids);
            $stmt_details->execute();
            $result_details = $stmt_details->get_result();
            while ($row = $result_details->fetch_assoc()) {
                $details_by_order[$row['pedido_id']][] = [
                    'nombre' => $row['nombre_producto'],
                    'categoria' => $row['categoria_producto'],
                    'id_str' => $row['producto_id_str']
                ];
            }
            $stmt_details->close();

            // 2. Obtenemos todos los destinatarios de una vez
            $sql_recipients = "SELECT dp.pedido_id, dpd.nickname_destinatario FROM detalles_pedido_destinatarios dpd JOIN detalles_pedido dp ON dpd.detalle_pedido_id = dp.id WHERE dp.pedido_id IN ($placeholders)";
            $stmt_recipients = $conn->prepare($sql_recipients);
            $stmt_recipients->bind_param($types, ...$order_ids);
            $stmt_recipients->execute();
            $result_recipients = $stmt_recipients->get_result();
            while ($row = $result_recipients->fetch_assoc()) {
                // Usamos el ID del pedido como clave para evitar duplicados
                $recipients_by_order[$row['pedido_id']][$row['nickname_destinatario']] = true;
            }
            $stmt_recipients->close();
            
            // Unimos todo en una sola respuesta
            $response = [
                'status' => 'success', 
                'details' => $details_by_order, 
                'recipients' => array_map('array_keys', $recipients_by_order) // Convertimos las claves a un array simple
            ];
            break;

        // --- ACCIÓN: ACTUALIZAR ESTADO DE UN PEDIDO ---
        case 'update_status':
            $order_id = (int)($_POST['order_id'] ?? 0);
            $new_status = $_POST['new_status'] ?? '';

            if ($order_id <= 0) throw new Exception('ID de pedido no válido.');

            if ($new_status === 'notificado') {
                $stmt = $conn->prepare("UPDATE pedidos SET estado_pago_cliente = 'notificado_pendiente_revision', fecha_notificacion_pago = UTC_TIMESTAMP() WHERE id = ? AND estado = 'pendiente'");
                if (!$stmt) throw new Exception('Error al preparar la consulta de notificación.');
                $stmt->bind_param('i', $order_id);
                if (!$stmt->execute()) throw new Exception("Error al registrar notificación: " . $stmt->error);
                $stmt->close();

                $stmt_get = $conn->prepare("SELECT id, estado, estado_pago_cliente, fecha_pago, fecha_completado, fecha_notificacion_pago FROM pedidos WHERE id = ? LIMIT 1");
                if (!$stmt_get) throw new Exception('Error al preparar la consulta de verificación.');
                $stmt_get->bind_param('i', $order_id);
                if (!$stmt_get->execute()) throw new Exception('Error al obtener el pedido actualizado: ' . $stmt_get->error);
                $updated_order = $stmt_get->get_result()->fetch_assoc();
                $stmt_get->close();

                $response = ['status' => 'success', 'message' => 'Notificación registrada correctamente.', 'order' => $updated_order];
                break;
            }

            $allowed_statuses = ['pendiente', 'pagado', 'completado', 'cancelado', 'reembolso_pendiente', 'reembolsado'];
            if (!in_array($new_status, $allowed_statuses, true)) throw new Exception('Estado no permitido.');

            $stmt_old = $conn->prepare("SELECT id, estado, user_id, total_pagar, recipient_mode, cashback_mode, codigo_pedido, cashback_earned, coins_redeemed FROM pedidos WHERE id = ? LIMIT 1 FOR UPDATE");
            if (!$stmt_old) throw new Exception('Error al preparar la consulta de estado actual.');
            $stmt_old->bind_param('i', $order_id);
            if (!$stmt_old->execute()) throw new Exception('Error al obtener el estado actual: ' . $stmt_old->error);
            $old_order = $stmt_old->get_result()->fetch_assoc();
            $stmt_old->close();

            if (!$old_order) throw new Exception('Pedido no encontrado.');

            $old_status = (string)($old_order['estado'] ?? '');
            $order_user_id = isset($old_order['user_id']) ? (int)$old_order['user_id'] : 0;
            $order_total_pagar = (isset($old_order['total_pagar']) && is_numeric($old_order['total_pagar'])) ? (float)$old_order['total_pagar'] : 0.0;
            $order_recipient_mode = trim((string)($old_order['recipient_mode'] ?? ''));

            $sql = "UPDATE pedidos SET estado = ?";
            $types = 's';
            $params = [$new_status];

            if ($new_status === 'pagado') {
                $sql .= ", fecha_pago = UTC_TIMESTAMP()";
            }

            if ($new_status === 'completado') {
                $sql .= ", fecha_completado = UTC_TIMESTAMP()";
                $sql .= ", fecha_pago = CASE WHEN fecha_pago IS NULL THEN UTC_TIMESTAMP() ELSE fecha_pago END";
            }
        
            $sql .= " WHERE id = ?";
            $types .= 'i';
            $params[] = $order_id;
        
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Error al preparar la consulta: " . $conn->error);

            // bind_param dinámico
            $stmt->bind_param($types, ...$params);

            if (!$stmt->execute()) throw new Exception("Error al ejecutar la actualización: " . $stmt->error);
            $stmt->close();

            // Bananos gastados: devolver al anular, volver a cobrar al reactivar.
            $coins_sync = bs_sync_spent_coins($conn, $old_order, $old_status, $new_status);

            if ($order_user_id > 0) {
                $is_promotion = ($old_status !== 'completado' && $new_status === 'completado');
                $is_reversal = ($old_status === 'completado' && $new_status !== 'completado');

                if ($is_promotion) {
                    $inc_total_spent = $order_total_pagar;
                    $inc_total_count = 1;
                    $is_self = ($order_recipient_mode === 'self');
                    $inc_self_spent = $is_self ? $order_total_pagar : 0.0;
                    $inc_self_count = $is_self ? 1 : 0;

                    $sqlStats = "INSERT INTO user_stats (user_id, total_spent_total, orders_count_total, total_spent_completed_self, orders_count_completed_self) VALUES (?, ?, ?, ?, ?)\n                        ON DUPLICATE KEY UPDATE\n                            total_spent_total = total_spent_total + VALUES(total_spent_total),\n                            orders_count_total = orders_count_total + VALUES(orders_count_total),\n                            total_spent_completed_self = total_spent_completed_self + VALUES(total_spent_completed_self),\n                            orders_count_completed_self = orders_count_completed_self + VALUES(orders_count_completed_self)";

                    $stmtStats = $conn->prepare($sqlStats);
                    if (!$stmtStats) throw new Exception('Error al preparar actualización de estadísticas.');
                    $stmtStats->bind_param('ididi', $order_user_id, $inc_total_spent, $inc_total_count, $inc_self_spent, $inc_self_count);
                    if (!$stmtStats->execute()) throw new Exception('Error al actualizar estadísticas: ' . $stmtStats->error);
                    $stmtStats->close();

                    // --- CASHBACK: Otorgar monedas pre-calculadas al completar ---
                    // Tanto 'base' (2%) como 'verified' (5%) ganan cashback; solo
                    // 'none' (invitados / sin cashback) queda excluido.
                    $order_cashback_mode = (string)($old_order['cashback_mode'] ?? 'none');
                    if ($order_cashback_mode === 'base' || $order_cashback_mode === 'verified') {
                        $cashback_amount = (float)($old_order['cashback_earned'] ?? 0);

                        if ($cashback_amount > 0) {
                            // 1. Incrementar saldo (crear registro si no existe)
                            $stmt_coin_up = $conn->prepare("INSERT INTO coins (user_id, balance) VALUES (?, ?) ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)");
                            if (!$stmt_coin_up) throw new Exception('Error al preparar incremento de saldo de cashback.');
                            $stmt_coin_up->bind_param('id', $order_user_id, $cashback_amount);
                            if (!$stmt_coin_up->execute()) throw new Exception('Error al incrementar saldo de cashback: ' . $stmt_coin_up->error);
                            $stmt_coin_up->close();

                            // 2. Registrar transacción de cashback
                            $stmt_ct = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id) VALUES (?, ?, 'cashback', 'order_id', ?)");
                            if (!$stmt_ct) throw new Exception('Error al preparar transacción de cashback.');
                            $ref_id_str = (string)$order_id;
                            $stmt_ct->bind_param('ids', $order_user_id, $cashback_amount, $ref_id_str);
                            if (!$stmt_ct->execute()) throw new Exception('Error al registrar transacción de cashback: ' . $stmt_ct->error);
                            $stmt_ct->close();

                            // 3. Notificación al usuario
                            $cod_publico = $old_order['codigo_pedido'] ?? $order_id;
                            $notif_coins = number_format($cashback_amount, 0);
                            $notif_msg = "¡Ganaste $notif_coins monedas de cashback por tu pedido #$cod_publico!";
                            $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'reward', 'Cashback Recibido', ?)");
                            if ($stmt_notif) {
                                $stmt_notif->bind_param('is', $order_user_id, $notif_msg);
                                $stmt_notif->execute();
                                $stmt_notif->close();
                            }
                        }
                    }

                    // Marcar que stats/cashback fueron aplicados
                    $conn->query("UPDATE pedidos SET stats_applied_at = UTC_TIMESTAMP() WHERE id = $order_id");

                } elseif ($is_reversal) {
                    $dec_total_spent = $order_total_pagar;
                    $dec_total_count = 1;
                    $is_self = ($order_recipient_mode === 'self');
                    $dec_self_spent = $is_self ? $order_total_pagar : 0.0;
                    $dec_self_count = $is_self ? 1 : 0;

                    $sqlStats = "INSERT INTO user_stats (user_id, total_spent_total, orders_count_total, total_spent_completed_self, orders_count_completed_self) VALUES (?, 0, 0, 0, 0)\n                        ON DUPLICATE KEY UPDATE\n                            total_spent_total = GREATEST(0, total_spent_total - ?),\n                            orders_count_total = GREATEST(0, orders_count_total - ?),\n                            total_spent_completed_self = GREATEST(0, total_spent_completed_self - ?),\n                            orders_count_completed_self = GREATEST(0, orders_count_completed_self - ?)";

                    $stmtStats = $conn->prepare($sqlStats);
                    if (!$stmtStats) throw new Exception('Error al preparar actualización de estadísticas.');
                    $stmtStats->bind_param('ididi', $order_user_id, $dec_total_spent, $dec_total_count, $dec_self_spent, $dec_self_count);
                    if (!$stmtStats->execute()) throw new Exception('Error al actualizar estadísticas: ' . $stmtStats->error);
                    $stmtStats->close();

                    // --- CASHBACK: Revertir monedas al deshacer completado ---
                    $stmt_rev_cash = $conn->prepare("SELECT amount FROM coin_transactions WHERE reference_id = ? AND reason = 'cashback' AND user_id = ?");
                    if ($stmt_rev_cash) {
                        $ref_id_str = (string)$order_id;
                        $stmt_rev_cash->bind_param('si', $ref_id_str, $order_user_id);
                        $stmt_rev_cash->execute();
                        $res_rev_cash = $stmt_rev_cash->get_result();

                        if ($row_rev_cash = $res_rev_cash->fetch_assoc()) {
                            $coins_to_reverse = (float)$row_rev_cash['amount'];

                            // Restar del saldo
                            $stmt_sub = $conn->prepare("UPDATE coins SET balance = GREATEST(0, balance - ?) WHERE user_id = ?");
                            if ($stmt_sub) {
                                $stmt_sub->bind_param('di', $coins_to_reverse, $order_user_id);
                                $stmt_sub->execute();
                                $stmt_sub->close();
                            }

                            // Eliminar la transacción de cashback original
                            $stmt_del_cb = $conn->prepare("DELETE FROM coin_transactions WHERE reference_id = ? AND reason = 'cashback' AND user_id = ?");
                            if ($stmt_del_cb) {
                                $stmt_del_cb->bind_param('si', $ref_id_str, $order_user_id);
                                $stmt_del_cb->execute();
                                $stmt_del_cb->close();
                            }
                        }
                        $stmt_rev_cash->close();
                    }

                    // Desmarcar stats_applied_at
                    $conn->query("UPDATE pedidos SET stats_applied_at = NULL WHERE id = $order_id");

                }
            }

            if ($new_status === 'completado') {
                $stmt_log = $conn->prepare("INSERT INTO log_regalos_completados (pedido_id) VALUES (?) ON DUPLICATE KEY UPDATE fecha_envio=NOW()");
                if (!$stmt_log) throw new Exception('Error al preparar el log de completado.');
                $stmt_log->bind_param('i', $order_id);
                if (!$stmt_log->execute()) throw new Exception('Error al registrar log de completado: ' . $stmt_log->error);
                $stmt_log->close();
            }

            $stmt_get = $conn->prepare("SELECT id, estado, estado_pago_cliente, fecha_pago, fecha_completado, fecha_notificacion_pago FROM pedidos WHERE id = ? LIMIT 1");
            if (!$stmt_get) throw new Exception('Error al preparar la consulta de verificación.');
            $stmt_get->bind_param('i', $order_id);
            if (!$stmt_get->execute()) throw new Exception('Error al obtener el pedido actualizado: ' . $stmt_get->error);
            $updated_order = $stmt_get->get_result()->fetch_assoc();
            $stmt_get->close();

            $status_msg = 'Estado del pedido actualizado correctamente.';
            if ($coins_sync['refunded'] > 0) $status_msg .= ' Se devolvieron ' . number_format($coins_sync['refunded'], 0) . ' Bananos al cliente.';
            if ($coins_sync['recharged'] > 0) $status_msg .= ' Se volvieron a cobrar ' . number_format($coins_sync['recharged'], 0) . ' Bananos al cliente.';

            $response = ['status' => 'success', 'message' => $status_msg, 'order' => $updated_order, 'coins_sync' => $coins_sync];
            break;

        case 'check_for_new_orders':
            // === LIMPIEZA GLOBAL DE PEDIDOS FANTASMA (lazy expiration, lado servidor) ===
            // Mientras el panel admin hace polling, cancelamos EN LOTE todos los pedidos
            // que sigan 'pendiente' + 'no_notificado' y cuyo plazo ya venció (aunque el
            // cliente haya cerrado su pestaña y su polling esté muerto). El deadline es el
            // MÁS CERCANO entre (fecha_creacion + tiempo_reserva_normal_min) y la expiración
            // de oferta; por eso basta con que UTC_TIMESTAMP() supere CUALQUIERA de los dos.
            // Las guardas del WHERE lo hacen seguro ante carreras (jamás toca pagados ni
            // notificados). Todo se compara en UTC dentro de la propia BD (fuente de verdad).
            $reserva_min = 1440;
            if ($res_cfg = $conn->query("SELECT valor FROM configuracion_tienda WHERE clave = 'tiempo_reserva_normal_min' LIMIT 1")) {
                if ($row_cfg = $res_cfg->fetch_assoc()) { $reserva_min = (int)$row_cfg['valor']; }
                $res_cfg->free();
            }
            $expired_cleaned = 0;
            if ($stmt_exp = $conn->prepare(
                "UPDATE pedidos
                    SET estado = 'cancelado'
                  WHERE estado = 'pendiente'
                    AND estado_pago_cliente = 'no_notificado'
                    AND COALESCE(coins_redeemed, 0) = 0
                    AND (
                          UTC_TIMESTAMP() > (fecha_creacion + INTERVAL ? MINUTE)
                          OR (fecha_expiracion_oferta IS NOT NULL AND UTC_TIMESTAMP() > fecha_expiracion_oferta)
                        )"
            )) {
                $stmt_exp->bind_param('i', $reserva_min);
                $stmt_exp->execute();
                $expired_cleaned = $stmt_exp->affected_rows;
                $stmt_exp->close();
            }

            $latest_id = (int)($_GET['latest_id'] ?? 0);
    // --- ¡AQUÍ ESTÁ LA CORRECCIÓN! ---
    // Volvemos al método robusto de GROUP_CONCAT, que devuelve un string simple como "Pepe,Ana".
    $base_select_fields = "
        p.*,
        (SELECT JSON_ARRAYAGG(JSON_OBJECT(
            'nombre', dp_json.nombre_producto, 
            'categoria', COALESCE(dp_json.categoria_producto, 'General'), 
            'id_str', dp_json.producto_id_str,
            'tipo_producto', dp_json.tipo_producto,
            'section_name', dp_json.section_name,
            'store_rank', dp_json.store_rank,
            'fecha_expiracion_item', dp_json.fecha_expiracion_item
        )) FROM detalles_pedido dp_json WHERE dp_json.pedido_id = p.id) as productos_json,
        
        -- ESTA ES LA SUBCONSULTA CORREGIDA Y SIMPLIFICADA --
        (SELECT GROUP_CONCAT(DISTINCT dpd_inner.nickname_destinatario SEPARATOR ',')
         FROM detalles_pedido dp_inner
         JOIN detalles_pedido_destinatarios dpd_inner ON dp_inner.id = dpd_inner.detalle_pedido_id
         WHERE dp_inner.pedido_id = p.id
        ) as destinatarios_regalo_string
    ";

    // Función auxiliar para procesar las filas de la base de datos de forma segura
    $process_row = function($row) {
        $row['productos_json'] = json_decode($row['productos_json'] ?? '[]', true) ?: [];
        
        // --- LÓGICA DE PROCESAMIENTO CORREGIDA ---
        // Tomamos el string "Pepe,Ana" y lo convertimos en un array PHP ['Pepe', 'Ana']
        // Si el string está vacío o es nulo, se convierte en un array vacío [].
        $row['destinatarios_regalo'] = !empty($row['destinatarios_regalo_string']) 
            ? explode(',', $row['destinatarios_regalo_string']) 
            : [];

        // Eliminamos la columna del string crudo para no enviarla al frontend.
        unset($row['destinatarios_regalo_string']);
        
        return $row;
    };

    // 1. BUSCAR PEDIDOS NUEVOS (ID > latest_id) (OPT: últimos 15 días)
    $sql_new = "SELECT $base_select_fields FROM pedidos p WHERE p.id > ? AND p.fecha_creacion >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 DAY) GROUP BY p.id ORDER BY p.id ASC";
    $stmt_new = $conn->prepare($sql_new);
    $stmt_new->bind_param('i', $latest_id);
    $stmt_new->execute();
    $result_new = $stmt_new->get_result();
    $new_orders = [];
    while ($row = $result_new->fetch_assoc()) {
        $new_orders[] = $process_row($row);
    }
    $stmt_new->close();
    
    // 2. BUSCAR PEDIDOS EXISTENTES QUE FUERON NOTIFICADOS RECIENTEMENTE (OPT: últimos 15 días)
    $sql_updated = "SELECT $base_select_fields FROM pedidos p WHERE p.fecha_creacion >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 DAY) AND p.estado = 'pendiente' AND p.estado_pago_cliente = 'notificado_pendiente_revision' AND p.fecha_notificacion_pago >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE GROUP BY p.id ORDER BY p.id DESC";
    $stmt_updated = $conn->prepare($sql_updated);
    $stmt_updated->execute();
    $result_updated = $stmt_updated->get_result();
    $updated_orders = [];
    while ($row = $result_updated->fetch_assoc()) {
        $updated_orders[] = $process_row($row);
    }
    $stmt_updated->close();

    // 3. ENVIAR AMBAS LISTAS
    $response = [
        'status' => 'success', 
        'new_orders' => $new_orders,
        'updated_orders' => $updated_orders,
        'expired_cleaned' => $expired_cleaned // nº de pedidos fantasma cancelados en esta pasada
    ];
    break;
// ==============================================================================
// === FIN DEL BLOQUE DE REEMPLAZO ==============================================
// ==============================================================================
// --- ACCIÓN: DESCARTAR NOTIFICACIÓN DE PAGO ---
        case 'dismiss_notification':
            $order_id = (int)($_POST['order_id'] ?? 0);
            if ($order_id <= 0) {
                throw new Exception('ID de pedido no válido.');
            }

            // Cambiamos el estado del pago a 'no_notificado' para que deje de ser prioritario
            // Ponemos una condición extra para asegurarnos que solo afecte a pedidos pendientes.
            $stmt = $conn->prepare("UPDATE pedidos SET estado_pago_cliente = 'no_notificado' WHERE id = ? AND estado = 'pendiente'");
            $stmt->bind_param('i', $order_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Error al actualizar el estado de notificación: " . $stmt->error);
            }
            
            $stmt->close();

            $stmt_get = $conn->prepare("SELECT id, estado, estado_pago_cliente, fecha_pago, fecha_completado, fecha_notificacion_pago FROM pedidos WHERE id = ? LIMIT 1");
            if (!$stmt_get) throw new Exception('Error al preparar la consulta de verificación.');
            $stmt_get->bind_param('i', $order_id);
            if (!$stmt_get->execute()) throw new Exception('Error al obtener el pedido actualizado: ' . $stmt_get->error);
            $updated_order = $stmt_get->get_result()->fetch_assoc();
            $stmt_get->close();

            $response = ['status' => 'success', 'message' => 'Notificación descartada correctamente.', 'order' => $updated_order];
            break;

// --- ACCIÓN: ELIMINAR PEDIDO PERMANENTEMENTE (CON LIMPIEZA FINANCIERA TOTAL) ---
case 'hard_delete_order':
            $order_id = (int)($_POST['order_id'] ?? 0);
            if ($order_id <= 0) throw new Exception('ID de pedido no válido.');

            // PASO 1: Obtener toda la información (AGREGAMOS p.codigo_pedido)
            $sql_info = "
                SELECT 
                    p.user_id, p.coins_redeemed, p.total_pagar, p.recipient_mode, p.stats_applied_at, p.codigo_pedido,
                    p.cupon_usado_codigo, p.nickname_fortnite_cliente, p.whatsapp_discord_cliente,
                    c.id as cupon_id_usado
                FROM pedidos p
                LEFT JOIN cupones c ON p.cupon_usado_codigo = c.codigo
                WHERE p.id = ?";
            
            $stmt_info = $conn->prepare($sql_info);
            $stmt_info->bind_param('i', $order_id);
            $stmt_info->execute();
            $result_info = $stmt_info->get_result();
            $order_info = $result_info->fetch_assoc();
            $stmt_info->close();

            if (!$order_info) {
                 throw new Exception('Pedido no encontrado para eliminar.');
            }

            // --- PASO 2: LIMPIEZA FINANCIERA (MONEDAS Y STATS) ---
            if (!empty($order_info['user_id'])) {
                $uid = $order_info['user_id'];

// A) REEMBOLSO (Solo si hay saldo pendiente por devolver)
                if ($order_info['coins_redeemed'] > 0) {
                    // AUDITORÍA: Contamos Gastos vs Devoluciones
                    $sql_audit = "SELECT 
                        (SELECT COUNT(*) FROM coin_transactions WHERE reference_id = ? AND reason = 'spend' AND user_id = ?) as spends,
                        (SELECT COUNT(*) FROM coin_transactions WHERE reference_id = ? AND reason = 'refund' AND user_id = ?) as refunds";
                    
                    $stmt_aud = $conn->prepare($sql_audit);
                    $stmt_aud->bind_param('sisi', $order_id, $uid, $order_id, $uid);
                    $stmt_aud->execute();
                    $audit = $stmt_aud->get_result()->fetch_assoc();
                    $stmt_aud->close();

                    // Solo devolvemos si hay deuda (Spends > Refunds)
                    if ($audit['spends'] > $audit['refunds']) {
                        $coins_to_refund = $order_info['coins_redeemed'];
                        
                        $conn->query("UPDATE coins SET balance = balance + $coins_to_refund WHERE user_id = $uid");
                        
                        $stmt_tx = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id) VALUES (?, ?, 'refund', 'deleted_order', ?)");
                        $stmt_tx->bind_param('ids', $uid, $coins_to_refund, $order_id);
                        $stmt_tx->execute(); 
                        $stmt_tx->close();
                        // NOTIFICACIÓN: REEMBOLSO POR ELIMINACIÓN
                    $conn->query("INSERT INTO notifications (user_id, type, title, message) VALUES ($uid, 'info', 'Ajuste de Saldo', 'Se han revertido las monedas ganadas por el pedido #$cod_publico debido a su cancelación.')");
                    }
                }

                // B) REVERSIÓN (Quitar monedas ganadas y corregir estadísticas)
                // Solo si el pedido ya había sumado (stats_applied_at no es nulo)
                if ($order_info['stats_applied_at'] !== NULL) {
                    
                    // 1. Buscar y quitar Cashback ganado
                    $stmt_cash = $conn->prepare("SELECT amount FROM coin_transactions WHERE reference_id = ? AND reason = 'cashback' AND user_id = ?");
                    $stmt_cash->bind_param('si', $order_id, $uid);
                    $stmt_cash->execute();
                    $res_cash = $stmt_cash->get_result();
                    
                    if ($row_cash = $res_cash->fetch_assoc()) {
                        $coins_gained = $row_cash['amount'];
                        
                        // Restar del saldo
                        $conn->query("UPDATE coins SET balance = balance - $coins_gained WHERE user_id = $uid");
                        
                        // Log de reversión
                        $stmt_rev = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id) VALUES (?, ?, 'admin_adjust', 'deleted_reversal', ?)");
                        $neg_amount = -1 * abs($coins_gained);
                        $stmt_rev->bind_param('ids', $uid, $neg_amount, $order_id);
                        $stmt_rev->execute();
                        $stmt_rev->close();
                    }
                    $stmt_cash->close();

                    // 2. Corregir User Stats (Restar la compra del historial)
                    $total_spent = (float)$order_info['total_pagar'];
                    $is_self = ($order_info['recipient_mode'] === 'self');
                    
                    $dec_total_count = 1;
                    $dec_total_spent = $total_spent;
                    $dec_self_count = $is_self ? 1 : 0;
                    $dec_self_spent = $is_self ? $total_spent : 0.00;

                    $sql_revert_stats = "
                        UPDATE user_stats SET 
                            orders_count_total = GREATEST(0, orders_count_total - ?),
                            total_spent_total = GREATEST(0, total_spent_total - ?),
                            orders_count_completed_self = GREATEST(0, orders_count_completed_self - ?),
                            total_spent_completed_self = GREATEST(0, total_spent_completed_self - ?)
                        WHERE user_id = ?
                    ";
                    $stmt_rs = $conn->prepare($sql_revert_stats);
                    $stmt_rs->bind_param('idddi', $dec_total_count, $dec_total_spent, $dec_self_count, $dec_self_spent, $uid);
                    $stmt_rs->execute();
                    $stmt_rs->close();
                }
            }

            // --- PASO 3: ELIMINACIÓN DE DATOS (EL PEDIDO DESAPARECE) ---
            
            // Eliminar detalles y destinatarios
            $conn->query("DELETE dpd FROM detalles_pedido_destinatarios dpd JOIN detalles_pedido dp ON dpd.detalle_pedido_id = dp.id WHERE dp.pedido_id = $order_id");
            $conn->query("DELETE FROM detalles_pedido WHERE pedido_id = $order_id");
            
            // Eliminar logs asociados
            $conn->query("DELETE FROM log_regalos_completados WHERE pedido_id = $order_id");
// ... (borrado de detalles y logs que ya tenías) ...
            $conn->query("DELETE FROM log_regalos_completados WHERE pedido_id = $order_id");

            // --- NUEVO: BORRAR NOTIFICACIONES ASOCIADAS (POR CÓDIGO PÚBLICO) ---
            if (isset($uid) && isset($order_info['codigo_pedido'])) {
                $cod_publico = $order_info['codigo_pedido'];
                
                // Buscamos cualquier mensaje que contenga el código "B-908976"
                $search_term = "%#$cod_publico%"; 
                
                $stmt_del_n = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND message LIKE ?");
                $stmt_del_n->bind_param('is', $uid, $search_term);
                $stmt_del_n->execute();
                $stmt_del_n->close();
            }
            // ----------------------------------------------
            // Eliminar el pedido padre
            $stmt_del_order = $conn->prepare("DELETE FROM pedidos WHERE id = ?");
            $stmt_del_order->bind_param('i', $order_id);
            $stmt_del_order->execute();
            $stmt_del_order->close();
            
            // --- PASO 4: LIMPIEZA DE CUPONES (SI SE USÓ UNO) ---
            $coupon_code = $order_info['cupon_usado_codigo'] ?? null;
            $coupon_id_usado = $order_info['cupon_id_usado'] ?? null;
            $nickname_cliente = $order_info['nickname_fortnite_cliente'] ?? null;
            $whatsapp_cliente_tracking = $order_info['whatsapp_discord_cliente'] ?? null;

            if ($coupon_code && $coupon_id_usado) {
                // Devolver uso al cupón global
                $stmt_up_cp = $conn->prepare("UPDATE cupones SET usos_actuales = usos_actuales - 1 WHERE codigo = ? AND usos_actuales > 0");
                $stmt_up_cp->bind_param('s', $coupon_code);
                $stmt_up_cp->execute();
                $stmt_up_cp->close();

                // Eliminar tracking de destinatario (para que pueda volver a usarlo si era de 1 solo uso)
                if ($nickname_cliente) {
                    $conn->query("DELETE FROM cupones_usos_destinatario WHERE cupon_id = $coupon_id_usado AND destinatario_epic_id = '$nickname_cliente'");
                }
                // Eliminar tracking de whatsapp
                if ($whatsapp_cliente_tracking) {
                    $conn->query("DELETE FROM cupones_usos_tracking WHERE cupon_id = $coupon_id_usado AND whatsapp_usuario = '$whatsapp_cliente_tracking'");
                }
            }

            $response = ['status' => 'success', 'message' => 'Pedido eliminado y balances revertidos correctamente.'];
            break;

        // --- FIN DE LAS NUEVAS ACCIONES ---
        default:
            throw new Exception('Acción no reconocida.');
    }
    
    // Si era una acción de escritura, confirmamos los cambios
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
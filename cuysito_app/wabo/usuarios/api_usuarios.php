<?php
// /wabo/usuarios/api_usuarios.php
require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';
require_once __DIR__ . '/../../api/raffle_review.php'; // compras manuales (Punto 5)

// --- SEGURIDAD: Solo admins logueados ---
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
    // Aseguramos el esquema de compras manuales (idempotente).
    rr_ensure_schema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $conn->begin_transaction();
    }

    switch ($action) {

        // =============================================
        // 0. KPI SUMMARY (Dashboard header)
        // =============================================
        case 'get_kpis':
            $kpis = [];

            // Total users
            $r = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc();
            $kpis['total_users'] = (int)$r['c'];

            // Verified
            $r = $conn->query("SELECT COUNT(*) as c FROM users WHERE is_verified = 1")->fetch_assoc();
            $kpis['verified_users'] = (int)$r['c'];

            // Banned
            $r = $conn->query("SELECT COUNT(*) as c FROM users WHERE account_status = 'banned'")->fetch_assoc();
            $kpis['banned_users'] = (int)$r['c'];

            // Suspended
            $r = $conn->query("SELECT COUNT(*) as c FROM users WHERE account_status = 'suspended'")->fetch_assoc();
            $kpis['suspended_users'] = (int)$r['c'];

            // Active
            $r = $conn->query("SELECT COUNT(*) as c FROM users WHERE account_status = 'active'")->fetch_assoc();
            $kpis['active_users'] = (int)$r['c'];

            // New today
            $r = $conn->query("SELECT COUNT(*) as c FROM users WHERE DATE(created_at) = CURDATE()")->fetch_assoc();
            $kpis['new_today'] = (int)$r['c'];

            // New this week
            $r = $conn->query("SELECT COUNT(*) as c FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc();
            $kpis['new_this_week'] = (int)$r['c'];

            // With bonus spins > 0
            $r = $conn->query("SELECT COUNT(*) as c FROM users WHERE bonus_spins > 0")->fetch_assoc();
            $kpis['with_bonus_spins'] = (int)$r['c'];

            // Countries count
            $r = $conn->query("SELECT COUNT(DISTINCT country_iso2) as c FROM users WHERE country_iso2 IS NOT NULL AND country_iso2 != ''")->fetch_assoc();
            $kpis['countries'] = (int)$r['c'];

            // Total coins in circulation
            try {
                $r = $conn->query("SELECT COALESCE(SUM(balance), 0) as c FROM coins")->fetch_assoc();
                $kpis['total_coins'] = round((float)$r['c'], 2);
            } catch (Exception $e) { $kpis['total_coins'] = 0; }

            $response = ['status' => 'success', 'kpis' => $kpis];
            break;

        // =============================================
        // 1. LISTAR USUARIOS (Para la tabla)
        // =============================================
        case 'get_users':
            $search = $_GET['search'] ?? '';
            $page = (int)($_GET['page'] ?? 1);
            if ($page < 1) $page = 1;
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $status_filter = $_GET['status'] ?? '';

            // --- Filtros nuevos (Punto 5) ---
            $verified = $_GET['verified'] ?? '';            // '', '1', '0'
            $from     = trim((string)($_GET['pdate_from'] ?? '')); // AAAA-MM-DD
            $to       = trim((string)($_GET['pdate_to'] ?? ''));
            $hasManual = ($_GET['has_manual'] ?? '') === '1';
            $minOrders = isset($_GET['min_orders']) && $_GET['min_orders'] !== '' ? (int)$_GET['min_orders'] : null;
            $minSpent  = isset($_GET['min_spent'])  && $_GET['min_spent']  !== '' ? (float)$_GET['min_spent'] : null;
            $sort      = $_GET['sort'] ?? 'recent';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = '';

            // Snippets de fecha para las subconsultas de agregados.
            $pedDate = ''; $manDate = '';
            if ($from !== '') { $pedDate .= " AND DATE(p.fecha_creacion) >= ?"; $manDate .= " AND m.purchase_date >= ?"; }
            if ($to   !== '') { $pedDate .= " AND DATE(p.fecha_creacion) <= ?"; $manDate .= " AND m.purchase_date <= ?"; }

            // Subconsultas de agregados (web + manual, date-aware).
            $aggSql =
                ",(SELECT COUNT(*) FROM pedidos p WHERE p.user_id = u.id AND p.estado = 'completado'$pedDate) AS web_orders"
              . ",(SELECT COALESCE(SUM(p.total_pagar),0) FROM pedidos p WHERE p.user_id = u.id AND p.estado = 'completado'$pedDate) AS web_spent"
              . ",(SELECT COUNT(*) FROM raffle_manual_purchases m WHERE m.user_id = u.id$manDate) AS manual_orders"
              . ",(SELECT COALESCE(SUM(m.amount),0) FROM raffle_manual_purchases m WHERE m.user_id = u.id$manDate) AS manual_spent";

            // Params de los agregados, en el orden en que aparecen las 4 subconsultas.
            $aggTypes = ''; $aggParams = [];
            $pushDate = function () use (&$aggTypes, &$aggParams, $from, $to) {
                if ($from !== '') { $aggTypes .= 's'; $aggParams[] = $from; }
                if ($to   !== '') { $aggTypes .= 's'; $aggParams[] = $to; }
            };
            $pushDate(); // web_orders
            $pushDate(); // web_spent
            $pushDate(); // manual_orders
            $pushDate(); // manual_spent

            // WHERE común.
            $whereSql = " WHERE 1=1"; $whereTypes = ''; $whereParams = [];
            if (!empty($search)) {
                $whereSql .= " AND (u.game_username LIKE ? OR u.email LIKE ? OR u.public_code LIKE ? OR u.public_display_name LIKE ? OR u.whatsapp_phone_e164 LIKE ?)";
                $searchTerm = "%$search%";
                $whereTypes .= "sssss";
                array_push($whereParams, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            }
            if (!empty($status_filter) && in_array($status_filter, ['active','suspended','banned'])) {
                $whereSql .= " AND u.account_status = ?"; $whereTypes .= "s"; $whereParams[] = $status_filter;
            }
            if ($verified === '1' || $verified === '0') {
                $whereSql .= " AND u.is_verified = ?"; $whereTypes .= "i"; $whereParams[] = (int)$verified;
            }
            if ($hasManual) {
                $whereSql .= " AND (SELECT COUNT(*) FROM raffle_manual_purchases m3 WHERE m3.user_id = u.id) > 0";
            }

            // Filtro "aptos por filtro" sobre los agregados (aplicado en la
            // subconsulta externa para no depender de HAVING sin GROUP BY).
            $eligSql = ''; $eligTypes = ''; $eligParams = [];
            $eligParts = [];
            if ($minOrders !== null) { $eligParts[] = "(web_orders + manual_orders) >= ?"; $eligTypes .= "i"; $eligParams[] = $minOrders; }
            if ($minSpent  !== null) { $eligParts[] = "(web_spent + manual_spent) >= ?";  $eligTypes .= "d"; $eligParams[] = $minSpent; }
            if (!empty($eligParts)) $eligSql = " WHERE " . implode(' AND ', $eligParts);

            // ORDER BY (sobre la subconsulta externa: alias sin prefijo).
            switch ($sort) {
                case 'oldest':   $orderSql = " ORDER BY created_at ASC"; break;
                case 'spent':    $orderSql = " ORDER BY (web_spent + manual_spent) DESC, created_at DESC"; break;
                case 'orders':   $orderSql = " ORDER BY (web_orders + manual_orders) DESC, created_at DESC"; break;
                case 'verified': $orderSql = " ORDER BY is_verified DESC, created_at DESC"; break;
                case 'recent':
                default:         $orderSql = " ORDER BY created_at DESC"; break;
            }

            // Subconsulta base (usuarios + saldo + agregados web/manual).
            $innerSql = "SELECT u.*, COALESCE(c.balance, 0.00) as coins_balance" . $aggSql
                      . " FROM users u LEFT JOIN coins c ON u.id = c.user_id" . $whereSql;

            // --- Query de lista ---
            $sql = "SELECT * FROM ($innerSql) t" . $eligSql . $orderSql . " LIMIT ? OFFSET ?";
            $types  = $aggTypes . $whereTypes . $eligTypes . "ii";
            $params = array_merge($aggParams, $whereParams, $eligParams, [$limit, $offset]);

            $stmt = $conn->prepare($sql);
            if ($types !== '') $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $users = [];
            while ($row = $result->fetch_assoc()) { $users[] = $row; }

            // --- Conteo total (mismos filtros) ---
            $countSql = "SELECT COUNT(*) as total FROM ($innerSql) t" . $eligSql;
            $countTypes  = $aggTypes . $whereTypes . $eligTypes;
            $countParams = array_merge($aggParams, $whereParams, $eligParams);
            $stmtCount = $conn->prepare($countSql);
            if ($countTypes !== '') $stmtCount->bind_param($countTypes, ...$countParams);
            $stmtCount->execute();
            $totalUsers = (int)$stmtCount->get_result()->fetch_assoc()['total'];

            $response = [
                'status' => 'success',
                'users' => $users,
                'total' => $totalUsers,
                'pages' => (int)ceil($totalUsers / $limit)
            ];
            break;

        // =============================================
        // 2. OBTENER DETALLE DE UN USUARIO
        // =============================================
        case 'get_user_details':
            $user_id = (int)($_GET['user_id'] ?? 0);
            
            $stmt = $conn->prepare("SELECT u.*, COALESCE(c.balance, 0.00) as coins_balance FROM users u LEFT JOIN coins c ON u.id = c.user_id WHERE u.id = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            
            if (!$user) throw new Exception("Usuario no encontrado");

            // Historial de transacciones
            $transactions = [];
            try {
                $check = $conn->query("SHOW TABLES LIKE 'coin_transactions'");
                if($check && $check->num_rows > 0) {
                    $stmtCoins = $conn->prepare("SELECT * FROM coin_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
                    $stmtCoins->bind_param('i', $user_id);
                    $stmtCoins->execute();
                    $transactions = $stmtCoins->get_result()->fetch_all(MYSQLI_ASSOC);
                }
            } catch (Exception $e) {}

            // Historial de Pedidos
            $orders = [];
            try {
                $check2 = $conn->query("SHOW TABLES LIKE 'pedidos'");
                if($check2 && $check2->num_rows > 0) {
                    $stmtOrders = $conn->prepare("SELECT id, codigo_pedido, total_pagar, estado, fecha_creacion FROM pedidos WHERE user_id = ? ORDER BY id DESC LIMIT 10");
                    $stmtOrders->bind_param('i', $user_id);
                    $stmtOrders->execute();
                    $orders = $stmtOrders->get_result()->fetch_all(MYSQLI_ASSOC);
                }
            } catch (Exception $e) {}

            // Admin actions log for this user
            $admin_log = [];
            try {
                $checkAL = $conn->query("SHOW TABLES LIKE 'admin_actions_log'");
                if($checkAL && $checkAL->num_rows > 0) {
                    $stmtLog = $conn->prepare("SELECT * FROM admin_actions_log WHERE target_user_id = ? ORDER BY created_at DESC LIMIT 10");
                    $stmtLog->bind_param('i', $user_id);
                    $stmtLog->execute();
                    $admin_log = $stmtLog->get_result()->fetch_all(MYSQLI_ASSOC);
                }
            } catch (Exception $e) {}

            // Compras manuales (Punto 5): historial + agregados.
            $manual_purchases = [];
            $mpStmt = $conn->prepare("SELECT id, purchase_date, amount, channel, note, approved_by, created_at FROM raffle_manual_purchases WHERE user_id = ? ORDER BY purchase_date DESC, id DESC LIMIT 50");
            $mpStmt->bind_param('i', $user_id);
            $mpStmt->execute();
            $manual_purchases = $mpStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Resumen de compras (web completadas + manuales) para métricas.
            $webAgg = ['cnt' => 0, 'sum' => 0, 'last' => null];
            try {
                $waStmt = $conn->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(total_pagar),0) sum, MAX(fecha_creacion) last FROM pedidos WHERE user_id = ? AND estado = 'completado'");
                $waStmt->bind_param('i', $user_id);
                $waStmt->execute();
                $webAgg = $waStmt->get_result()->fetch_assoc();
            } catch (Exception $e) {}
            $manAll = rr_manual_purchase_stats($conn, $user_id, null);

            $purchase_summary = [
                'web_orders'  => (int)$webAgg['cnt'],
                'web_spent'   => (float)$webAgg['sum'],
                'web_last'    => $webAgg['last'],
                'manual_orders' => $manAll['count'],
                'manual_spent'  => $manAll['sum'],
                'total_orders' => (int)$webAgg['cnt'] + $manAll['count'],
                'total_spent'  => (float)$webAgg['sum'] + $manAll['sum'],
            ];

            $response = [
                'status' => 'success',
                'user' => $user,
                'transactions' => $transactions,
                'orders' => $orders,
                'admin_log' => $admin_log,
                'manual_purchases' => $manual_purchases,
                'purchase_summary' => $purchase_summary,
            ];
            break;

        // =============================================
        // COMPRAS MANUALES (Punto 5): listar / agregar / borrar
        // =============================================
        case 'mp_list':
            $uid = (int)($_GET['user_id'] ?? 0);
            $rows = [];
            if ($uid > 0) {
                $st = $conn->prepare("SELECT id, purchase_date, amount, channel, note, created_at FROM raffle_manual_purchases WHERE user_id = ? ORDER BY purchase_date DESC, id DESC LIMIT 50");
                $st->bind_param('i', $uid); $st->execute();
                $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            }
            $response = ['status' => 'success', 'purchases' => $rows];
            break;

        case 'mp_add':
            $uid    = (int)($_POST['user_id'] ?? 0);
            $pdate  = trim((string)($_POST['purchase_date'] ?? ''));
            $amount = (float)($_POST['amount'] ?? 0);
            $channel = trim((string)($_POST['channel'] ?? 'manual'));
            $note   = trim((string)($_POST['note'] ?? ''));
            if ($uid <= 0) throw new Exception("Usuario inválido.");
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdate)) throw new Exception("Fecha de compra inválida (AAAA-MM-DD).");
            if ($amount < 0) throw new Exception("El monto no puede ser negativo.");
            if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            $newId = rr_add_manual_purchase($conn, $uid, $pdate, $amount, $channel, $note, $adminId);
            if ($newId <= 0) throw new Exception("No se pudo registrar la compra manual.");

            try {
                $checkLog = $conn->query("SHOW TABLES LIKE 'admin_actions_log'");
                if ($adminId > 0 && $checkLog && $checkLog->num_rows > 0) {
                    $detail = "Compra manual: $pdate · S/" . number_format($amount, 2) . " ($channel)";
                    $lg = $conn->prepare("INSERT INTO admin_actions_log (admin_id, target_user_id, action, details) VALUES (?, ?, 'manual_purchase_add', ?)");
                    if ($lg) { $lg->bind_param('iis', $adminId, $uid, $detail); $lg->execute(); }
                }
            } catch (Exception $e) {}

            $response = ['status' => 'success', 'id' => $newId, 'message' => 'Compra manual registrada.'];
            break;

        case 'mp_delete':
            $mpId = (int)($_POST['id'] ?? 0);
            if ($mpId <= 0) throw new Exception("Registro inválido.");
            $del = $conn->prepare("DELETE FROM raffle_manual_purchases WHERE id = ?");
            $del->bind_param('i', $mpId); $del->execute();
            $response = ['status' => 'success', 'message' => 'Compra manual eliminada.'];
            break;

        // =============================================
        // 3. ACTUALIZAR ESTADO (BAN/SUSPEND/ACTIVE)
        // =============================================
        case 'update_status':
            $user_id = (int)$_POST['user_id'];
            $new_status = $_POST['status']; 
            $reason = $_POST['reason'] ?? '';
            $ban_until = $_POST['ban_until'] ?? null;

            if (!in_array($new_status, ['active', 'suspended', 'banned'])) throw new Exception("Estado inválido");

            $sql = "UPDATE users SET account_status = ?, ban_reason = ?";
            if ($new_status === 'banned') {
                $sql .= ", banned_at = NOW()";
                if (!empty($ban_until)) {
                    $sql .= ", ban_until = '" . $conn->real_escape_string($ban_until) . "'";
                }
            } elseif ($new_status === 'active') {
                $sql .= ", banned_at = NULL, ban_until = NULL, ban_reason = NULL";
            }
            $sql .= " WHERE id = ?";

            $stmt = $conn->prepare($sql);
            if ($new_status === 'active') {
                $empty = '';
                $stmt->bind_param('ssi', $new_status, $empty, $user_id);
            } else {
                $stmt->bind_param('ssi', $new_status, $reason, $user_id);
            }
            $stmt->execute();

            // Log de admin
            try {
                $admin_id = $_SESSION['wabo_user_id'] ?? 0;
                if($admin_id > 0) {
                    $logAction = "set_status_" . $new_status;
                    $checkLog = $conn->query("SHOW TABLES LIKE 'admin_actions_log'");
                    if($checkLog && $checkLog->num_rows > 0) {
                        $stmtLog = $conn->prepare("INSERT INTO admin_actions_log (admin_id, target_user_id, action, details) VALUES (?, ?, ?, ?)");
                        $stmtLog->bind_param('iiss', $admin_id, $user_id, $logAction, $reason);
                        $stmtLog->execute();
                    }
                }
            } catch (Exception $logEx) {}

            $response = ['status' => 'success', 'message' => 'Estado actualizado correctamente'];
            break;

        // =============================================
        // 4. MODIFICAR SALDO DE COINS
        // =============================================
        case 'adjust_coins':
            $user_id = (int)$_POST['user_id'];
            $amount = (float)$_POST['amount'];
            $reason_note = $_POST['note'] ?? 'Ajuste manual';
            $admin_id = $_SESSION['wabo_user_id'] ?? 0; 

            if ($amount == 0) throw new Exception("El monto no puede ser 0");

            $sqlCoin = "INSERT INTO coins (user_id, balance) VALUES (?, ?) 
                        ON DUPLICATE KEY UPDATE balance = balance + ?";
            $stmt = $conn->prepare($sqlCoin);
            $stmt->bind_param('idd', $user_id, $amount, $amount);
            $stmt->execute();

            // Registrar Transacción
            try {
                $check = $conn->query("SHOW TABLES LIKE 'coin_transactions'");
                if($check && $check->num_rows > 0) {
                    $sqlTrans = "INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id) 
                                 VALUES (?, ?, ?, 'admin_id', ?)";
                    $stmtTrans = $conn->prepare($sqlTrans);
                    $ref_id_str = (string)$admin_id;
                    $stmtTrans->bind_param('idss', $user_id, $amount, $reason_note, $ref_id_str);
                    $stmtTrans->execute();
                }
            } catch (Exception $e) {}

            // Log de admin
            try {
                $checkLog = $conn->query("SHOW TABLES LIKE 'admin_actions_log'");
                if($checkLog && $checkLog->num_rows > 0 && $admin_id > 0) {
                    $detail = "Ajuste: $amount. Nota: $reason_note";
                    $stmtLog = $conn->prepare("INSERT INTO admin_actions_log (admin_id, target_user_id, action, details) VALUES (?, ?, 'coin_adjust', ?)");
                    $stmtLog->bind_param('iis', $admin_id, $user_id, $detail);
                    $stmtLog->execute();
                }
            } catch (Exception $logEx) {}

            $response = ['status' => 'success', 'message' => 'Saldo actualizado correctamente.'];
            break;

        // =============================================
        // 5. TOGGLE VERIFICACIÓN
        // =============================================
        case 'toggle_verification':
            $user_id = (int)$_POST['user_id'];
            $verified = (int)$_POST['verified'];
            $notes = $_POST['notes'] ?? '';
            $admin_id = $_SESSION['wabo_user_id'] ?? 0;
            
            $sql = "UPDATE users SET is_verified = ?, verified_at = " . ($verified ? "NOW()" : "NULL") . ", verified_by = ?, verified_notes = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $admin_str = $admin_id > 0 ? (string)$admin_id : null;
            $stmt->bind_param('issi', $verified, $admin_str, $notes, $user_id);
            $stmt->execute();
            
            $response = ['status' => 'success', 'message' => 'Estado de verificación actualizado.'];
            break;

        // =============================================
        // 6. UPDATE USER FIELDS (general edit)
        // =============================================
        case 'update_user_fields':
            $user_id = (int)$_POST['user_id'];
            if ($user_id <= 0) throw new Exception("ID de usuario inválido");

            // Whitelist of editable fields and their types
            $editable = [
                'game_username'         => 's',
                'game_name_mode'        => 's',
                'game_username_locked'  => 'i',
                'public_display_name'   => 's',
                'public_name_mode'      => 's',
                'public_name_locked'    => 'i',
                'whatsapp_phone_e164'   => 's',
                'phone_mode'            => 's',
                'whatsapp_locked'       => 'i',
                'email'                 => 's',
                'benefits_enabled'      => 'i',
                'avatar_id'             => 'i',
                'country_iso2'          => 's',
                'bonus_spins'           => 'i',
                'ban_until'             => 's',
                'ban_reason'            => 's',
                'verified_notes'        => 's',
            ];

            $sets = [];
            $types = '';
            $vals = [];

            foreach ($editable as $field => $type) {
                if (isset($_POST[$field])) {
                    $val = $_POST[$field];
                    if ($val === '' && in_array($field, ['ban_until','ban_reason','verified_notes','country_iso2'])) {
                        $sets[] = "$field = NULL";
                    } else {
                        $sets[] = "$field = ?";
                        $types .= $type;
                        if ($type === 'i') $vals[] = (int)$val;
                        else $vals[] = $val;
                    }
                }
            }

            if (empty($sets)) throw new Exception("No hay campos para actualizar");

            $sql = "UPDATE users SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ?";
            $types .= 'i';
            $vals[] = $user_id;

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$vals);
            $stmt->execute();

            // Log
            try {
                $admin_id = $_SESSION['wabo_user_id'] ?? 0;
                if ($admin_id > 0) {
                    $checkLog = $conn->query("SHOW TABLES LIKE 'admin_actions_log'");
                    if($checkLog && $checkLog->num_rows > 0) {
                        $detail = "Campos editados: " . implode(', ', array_keys(array_filter($editable, function($k) { return isset($_POST[$k]); }, ARRAY_FILTER_USE_KEY)));
                        $stmtLog = $conn->prepare("INSERT INTO admin_actions_log (admin_id, target_user_id, action, details) VALUES (?, ?, 'edit_user_fields', ?)");
                        $stmtLog->bind_param('iis', $admin_id, $user_id, $detail);
                        $stmtLog->execute();
                    }
                }
            } catch (Exception $logEx) {}

            $response = ['status' => 'success', 'message' => 'Usuario actualizado correctamente.'];
            break;

        default:
            throw new Exception("Acción no reconocida: $action");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $conn->commit();
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->in_transaction) $conn->rollback();
    http_response_code(400);
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>
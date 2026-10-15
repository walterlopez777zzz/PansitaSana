<?php
// /wabo/economia/api_economia.php
// API de la sección Economía (Coins). Solo lectura sobre coins / coin_transactions,
// salvo la acción 'adjust' (ajuste manual con motivo obligatorio y auditoría).
// No toca la configuración de cashback de ningún módulo (Pedidos, Sorteos, Giro, Vídeos).
require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';
require_once __DIR__ . '/economia_lib.php';

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
    eco_ensure_schema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $conn->begin_transaction();
    }

    switch ($action) {

        // =====================================================================
        // RESUMEN: circulación, emisión/consumo por origen, distribución de saldos
        // =====================================================================
        case 'summary': {
            [$from, $to] = eco_period_from_request();
            $cat = eco_category_sql('ct');

            // Circulación real (saldo actual en la tabla coins)
            $circ = $conn->query("SELECT COALESCE(SUM(balance),0) AS total,
                                         SUM(balance > 0) AS holders,
                                         COALESCE(AVG(NULLIF(balance,0)),0) AS avg_holder,
                                         COALESCE(MAX(balance),0) AS max_balance
                                  FROM coins")->fetch_assoc();
            $usersTotal = (int)$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
            $medianHolder = eco_median_balance($conn, (int)$circ['holders']);
            $medianAll = eco_median_balance($conn, $usersTotal, true);

            // Totales del periodo
            $stmt = $conn->prepare("SELECT
                    COALESCE(SUM(CASE WHEN ct.amount > 0 THEN ct.amount ELSE 0 END),0) AS emitted,
                    COALESCE(SUM(CASE WHEN ct.amount < 0 THEN -ct.amount ELSE 0 END),0) AS consumed,
                    COALESCE(SUM(CASE WHEN ct.amount > 0 AND ($cat) = 'manual' THEN ct.amount ELSE 0 END),0) AS manual_in,
                    COALESCE(SUM(CASE WHEN ct.amount < 0 AND ($cat) = 'manual' THEN -ct.amount ELSE 0 END),0) AS manual_out,
                    COUNT(*) AS tx_count,
                    COUNT(DISTINCT ct.user_id) AS active_users
                FROM coin_transactions ct
                WHERE ct.created_at >= ? AND ct.created_at < ?");
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $tot = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Emisión / consumo por categoría (origen)
            $stmt = $conn->prepare("SELECT ($cat) AS category,
                    COALESCE(SUM(CASE WHEN ct.amount > 0 THEN ct.amount ELSE 0 END),0) AS emitted,
                    COALESCE(SUM(CASE WHEN ct.amount < 0 THEN -ct.amount ELSE 0 END),0) AS consumed,
                    COUNT(*) AS tx_count
                FROM coin_transactions ct
                WHERE ct.created_at >= ? AND ct.created_at < ?
                GROUP BY category ORDER BY emitted DESC");
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $byCategory = [];
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['label'] = eco_category_label($r['category']);
                $byCategory[] = $r;
            }
            $stmt->close();

            // Por motivo concreto
            $stmt = $conn->prepare("SELECT ct.reason, ($cat) AS category,
                    COALESCE(SUM(CASE WHEN ct.amount > 0 THEN ct.amount ELSE 0 END),0) AS emitted,
                    COALESCE(SUM(CASE WHEN ct.amount < 0 THEN -ct.amount ELSE 0 END),0) AS consumed,
                    COUNT(*) AS tx_count
                FROM coin_transactions ct
                WHERE ct.created_at >= ? AND ct.created_at < ?
                GROUP BY ct.reason, category ORDER BY SUM(ABS(ct.amount)) DESC LIMIT 40");
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $byReason = [];
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['label'] = eco_reason_label($r['reason'], $r['category']);
                $r['category_label'] = eco_category_label($r['category']);
                $byReason[] = $r;
            }
            $stmt->close();

            // Serie diaria (emitido / consumido) para la gráfica
            $stmt = $conn->prepare("SELECT DATE(ct.created_at) AS d,
                    COALESCE(SUM(CASE WHEN ct.amount > 0 THEN ct.amount ELSE 0 END),0) AS emitted,
                    COALESCE(SUM(CASE WHEN ct.amount < 0 THEN -ct.amount ELSE 0 END),0) AS consumed
                FROM coin_transactions ct
                WHERE ct.created_at >= ? AND ct.created_at < ?
                GROUP BY d ORDER BY d ASC");
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $daily = [];
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $daily[] = $r;
            $stmt->close();

            // Distribución de saldos por umbral (para valorar cuotas de inscripción)
            $thresholds = eco_thresholds_from_request();
            $distribution = eco_distribution($conn, $thresholds, $usersTotal, eco_focus_from_request());

            $response = ['status' => 'success', 'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'circulation' => [
                    'total' => round((float)$circ['total'], 2),
                    'holders' => (int)$circ['holders'],
                    'users_total' => $usersTotal,
                    'avg_holder' => round((float)$circ['avg_holder'], 2),
                    'avg_user' => $usersTotal > 0 ? round((float)$circ['total'] / $usersTotal, 2) : 0,
                    'median_holder' => $medianHolder,
                    'median_user' => $medianAll,
                    'max_balance' => round((float)$circ['max_balance'], 2),
                ],
                'totals' => [
                    'emitted' => round((float)$tot['emitted'], 2),
                    'consumed' => round((float)$tot['consumed'], 2),
                    'net' => round((float)$tot['emitted'] - (float)$tot['consumed'], 2),
                    'manual_in' => round((float)$tot['manual_in'], 2),
                    'manual_out' => round((float)$tot['manual_out'], 2),
                    'organic_in' => round((float)$tot['emitted'] - (float)$tot['manual_in'], 2),
                    'tx_count' => (int)$tot['tx_count'],
                    'active_users' => (int)$tot['active_users'],
                ],
                'by_category' => $byCategory,
                'by_reason' => $byReason,
                'daily' => $daily,
                'distribution' => $distribution,
            ]];
            break;
        }

        // =====================================================================
        // DISTRIBUCIÓN: solo umbrales (para el simulador de cuota)
        // =====================================================================
        case 'distribution': {
            $usersTotal = (int)$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
            $response = ['status' => 'success', 'data' => eco_distribution($conn, eco_thresholds_from_request(), $usersTotal, eco_focus_from_request())];
            break;
        }

        // =====================================================================
        // MOVIMIENTOS: libro mayor con filtros y paginación
        // =====================================================================
        case 'movements': {
            [$from, $to] = eco_period_from_request();
            $cat = eco_category_sql('ct');
            $q = trim((string)($_GET['q'] ?? ''));
            $reason = trim((string)($_GET['reason'] ?? ''));
            $origin = $_GET['origin'] ?? 'all';        // all | organic | manual
            $category = trim((string)($_GET['category'] ?? '')); // tienda|sorteos|giro|videos|manual|otros
            $direction = $_GET['direction'] ?? 'all';  // all | in | out
            $userId = (int)($_GET['user_id'] ?? 0);
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
            $offset = ($page - 1) * $perPage;

            $where = ["ct.created_at >= ?", "ct.created_at < ?"];
            $params = [$from, $to];
            $types = 'ss';

            if ($userId > 0) { $where[] = "ct.user_id = ?"; $params[] = $userId; $types .= 'i'; }
            elseif ($q !== '') {
                $like = '%' . $q . '%';
                $where[] = "(u.game_username LIKE ? OR u.public_display_name LIKE ? OR u.public_code LIKE ? OR u.email LIKE ? OR ct.user_id = ?)";
                array_push($params, $like, $like, $like, $like, (int)$q);
                $types .= 'ssssi';
            }
            if ($reason !== '') { $where[] = "ct.reason = ?"; $params[] = $reason; $types .= 's'; }
            if ($origin === 'manual') $where[] = "($cat) = 'manual'";
            elseif ($origin === 'organic') $where[] = "($cat) <> 'manual'";
            if ($category !== '' && in_array($category, ['tienda', 'sorteos', 'giro', 'videos', 'manual', 'otros'], true)) {
                $where[] = "($cat) = ?"; $params[] = $category; $types .= 's';
            }
            if ($direction === 'in') $where[] = "ct.amount > 0";
            elseif ($direction === 'out') $where[] = "ct.amount < 0";

            $whereSql = implode(' AND ', $where);
            $joinSql = "FROM coin_transactions ct LEFT JOIN users u ON u.id = ct.user_id";

            // Totales del filtro
            $stmt = $conn->prepare("SELECT COUNT(*) AS c,
                    COALESCE(SUM(CASE WHEN ct.amount > 0 THEN ct.amount ELSE 0 END),0) AS total_in,
                    COALESCE(SUM(CASE WHEN ct.amount < 0 THEN -ct.amount ELSE 0 END),0) AS total_out
                $joinSql WHERE $whereSql");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $tot = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $hasNote = eco_has_column($conn, 'coin_transactions', 'note');
            $noteSel = $hasNote ? "ct.note" : "NULL";
            $stmt = $conn->prepare("SELECT ct.id, ct.user_id, ct.amount, ct.reason, ct.reference_type, ct.reference_id, ct.created_at,
                    $noteSel AS note, ($cat) AS category,
                    u.game_username, u.public_display_name, u.public_code, a.filename AS avatar,
                    adm.game_username AS admin_name
                $joinSql
                LEFT JOIN avatars a ON a.id = u.avatar_id
                LEFT JOIN users adm ON (ct.reference_type = 'admin_id' AND adm.id = CAST(ct.reference_id AS UNSIGNED))
                WHERE $whereSql
                ORDER BY ct.created_at DESC, ct.id DESC LIMIT ? OFFSET ?");
            $params2 = $params; $params2[] = $perPage; $params2[] = $offset;
            $stmt->bind_param($types . 'ii', ...$params2);
            $stmt->execute();
            $rows = [];
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['category_label'] = eco_category_label($r['category']);
                $r['reason_label'] = eco_reason_label($r['reason'], $r['category']);
                $r['is_manual'] = $r['category'] === 'manual' ? 1 : 0;
                $rows[] = $r;
            }
            $stmt->close();

            $response = ['status' => 'success', 'data' => $rows, 'totals' => [
                'count' => (int)$tot['c'],
                'in' => round((float)$tot['total_in'], 2),
                'out' => round((float)$tot['total_out'], 2),
                'net' => round((float)$tot['total_in'] - (float)$tot['total_out'], 2),
            ], 'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => (int)$tot['c'],
                'pages' => (int)ceil(((int)$tot['c']) / $perPage),
            ]];
            break;
        }

        // Lista de motivos existentes (para el filtro)
        case 'reasons': {
            $cat = eco_category_sql('ct');
            $res = $conn->query("SELECT ct.reason, ($cat) AS category, COUNT(*) AS c FROM coin_transactions ct GROUP BY ct.reason, category ORDER BY c DESC LIMIT 60");
            $rows = [];
            while ($r = $res->fetch_assoc()) { $r['label'] = eco_reason_label($r['reason'], $r['category']); $rows[] = $r; }
            $response = ['status' => 'success', 'data' => $rows];
            break;
        }

        // Búsqueda de usuarios (para el ajuste manual)
        case 'user_search': {
            $q = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) { $response = ['status' => 'success', 'data' => []]; break; }
            $like = '%' . $q . '%';
            $stmt = $conn->prepare("SELECT u.id, u.game_username, u.public_display_name, u.public_code, u.is_verified, u.account_status,
                        COALESCE(c.balance,0) AS balance, a.filename AS avatar
                    FROM users u LEFT JOIN coins c ON c.user_id = u.id LEFT JOIN avatars a ON a.id = u.avatar_id
                    WHERE u.game_username LIKE ? OR u.public_display_name LIKE ? OR u.public_code LIKE ? OR u.email LIKE ? OR u.id = ?
                    ORDER BY u.game_username ASC LIMIT 12");
            $qid = (int)$q;
            $stmt->bind_param('ssssi', $like, $like, $like, $like, $qid);
            $stmt->execute();
            $rows = [];
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            $stmt->close();
            $response = ['status' => 'success', 'data' => $rows];
            break;
        }

        // =====================================================================
        // RANKING: holders / ganados / gastados / neto en un periodo
        // =====================================================================
        case 'ranking': {
            [$from, $to] = eco_period_from_request();
            $sort = $_GET['sort'] ?? 'balance'; // balance | earned | spent | net | tx
            $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
            $minBalance = (float)($_GET['min_balance'] ?? 0);
            $orderMap = ['balance' => 'balance DESC', 'earned' => 'earned DESC', 'spent' => 'spent DESC', 'net' => 'net DESC', 'tx' => 'tx_count DESC'];
            $order = $orderMap[$sort] ?? $orderMap['balance'];
            $cat = eco_category_sql('ct');

            $sql = "SELECT u.id, u.game_username, u.public_display_name, u.public_code, u.is_verified, u.account_status, a.filename AS avatar,
                        COALESCE(c.balance,0) AS balance,
                        COALESCE(p.earned,0) AS earned, COALESCE(p.spent,0) AS spent,
                        COALESCE(p.earned,0) - COALESCE(p.spent,0) AS net,
                        COALESCE(p.manual_in,0) AS manual_in,
                        COALESCE(p.tx_count,0) AS tx_count, p.last_tx
                    FROM users u
                    LEFT JOIN coins c ON c.user_id = u.id
                    LEFT JOIN avatars a ON a.id = u.avatar_id
                    LEFT JOIN (
                        SELECT ct.user_id,
                            SUM(CASE WHEN ct.amount > 0 THEN ct.amount ELSE 0 END) AS earned,
                            SUM(CASE WHEN ct.amount < 0 THEN -ct.amount ELSE 0 END) AS spent,
                            SUM(CASE WHEN ct.amount > 0 AND ($cat) = 'manual' THEN ct.amount ELSE 0 END) AS manual_in,
                            COUNT(*) AS tx_count, MAX(ct.created_at) AS last_tx
                        FROM coin_transactions ct
                        WHERE ct.created_at >= ? AND ct.created_at < ?
                        GROUP BY ct.user_id
                    ) p ON p.user_id = u.id
                    WHERE (COALESCE(c.balance,0) > 0 OR p.user_id IS NOT NULL) AND COALESCE(c.balance,0) >= ?
                    ORDER BY $order, u.id ASC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssdi', $from, $to, $minBalance, $limit);
            $stmt->execute();
            $rows = [];
            $res = $stmt->get_result();
            $pos = 1;
            while ($r = $res->fetch_assoc()) { $r['position'] = $pos++; $rows[] = $r; }
            $stmt->close();
            $response = ['status' => 'success', 'data' => $rows, 'period' => ['from' => $from, 'to' => $to]];
            break;
        }

        // =====================================================================
        // REGLAS: lectura de parámetros existentes (sin escritura)
        // =====================================================================
        case 'rules': {
            $response = ['status' => 'success', 'data' => eco_rules_snapshot($conn)];
            break;
        }

        // =====================================================================
        // AJUSTE MANUAL (POST): motivo obligatorio + auditoría
        // =====================================================================
        case 'adjust': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Método no permitido.');
            $userId = (int)($_POST['user_id'] ?? 0);
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $note = trim((string)($_POST['note'] ?? ''));
            $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
            $adminName = (string)($_SESSION['username'] ?? 'admin');

            if ($userId <= 0) throw new Exception('Usuario inválido.');
            if ($amount == 0) throw new Exception('El monto no puede ser 0.');
            if (abs($amount) > 100000) throw new Exception('Monto fuera de rango.');
            if (mb_strlen($note) < 4) throw new Exception('El motivo es obligatorio (mínimo 4 caracteres).');
            if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

            $chk = $conn->prepare("SELECT id, game_username, COALESCE((SELECT balance FROM coins WHERE user_id = users.id),0) AS balance FROM users WHERE id = ? LIMIT 1");
            $chk->bind_param('i', $userId);
            $chk->execute();
            $user = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$user) throw new Exception('El usuario no existe.');
            if ($amount < 0 && (float)$user['balance'] + $amount < 0) {
                throw new Exception('El usuario solo tiene ' . number_format((float)$user['balance'], 2) . ' Coins; no se puede dejar saldo negativo.');
            }

            $stmt = $conn->prepare("INSERT INTO coins (user_id, balance) VALUES (?, ?) ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)");
            $stmt->bind_param('id', $userId, $amount);
            $stmt->execute();
            $stmt->close();

            $refId = (string)$adminId;
            if (eco_has_column($conn, 'coin_transactions', 'note')) {
                $stmt = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, note) VALUES (?, ?, 'admin_adjust', 'admin_id', ?, ?)");
                $stmt->bind_param('idss', $userId, $amount, $refId, $note);
            } else {
                $stmt = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id) VALUES (?, ?, ?, 'admin_id', ?)");
                $stmt->bind_param('idss', $userId, $amount, $note, $refId);
            }
            $stmt->execute();
            $txId = $conn->insert_id;
            $stmt->close();

            try {
                $checkLog = $conn->query("SHOW TABLES LIKE 'admin_actions_log'");
                if ($checkLog && $checkLog->num_rows > 0 && $adminId > 0) {
                    $detail = "[Economía] Ajuste: " . ($amount > 0 ? '+' : '') . $amount . " Coins. Motivo: $note";
                    $stmtLog = $conn->prepare("INSERT INTO admin_actions_log (admin_id, target_user_id, action, details) VALUES (?, ?, 'coin_adjust', ?)");
                    $stmtLog->bind_param('iis', $adminId, $userId, $detail);
                    $stmtLog->execute();
                    $stmtLog->close();
                }
            } catch (Exception $logEx) { /* la auditoría no debe bloquear el ajuste */ }

            $newBalance = round((float)$user['balance'] + $amount, 2);
            $response = ['status' => 'success', 'message' => 'Ajuste aplicado a ' . $user['game_username'] . '. Nuevo saldo: ' . number_format($newBalance, 2) . ' Coins.',
                         'data' => ['tx_id' => $txId, 'new_balance' => $newBalance, 'admin' => $adminName]];
            break;
        }

        default:
            throw new Exception('Acción no válida: ' . $action);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') $conn->commit();

} catch (Exception $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { try { $conn->rollback(); } catch (Exception $ignored) {} }
    http_response_code(400);
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

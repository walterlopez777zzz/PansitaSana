<?php
// /wabo/api_dashboard.php (v6.0 - Command Center BI Versión Definitiva)

// --- CONFIGURACIÓN INICIAL ---
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../api/session_security.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}
require_once 'core/db_connect.php';
$conn->query("SET time_zone = 'America/Lima'");

// --- RUTEADOR DE ACCIONES ---
$action = $_GET['action'] ?? 'get_dashboard_data';
switch ($action) {
    case 'get_excluded_ips': handleGetExcludedIps($conn); break;
    case 'add_excluded_ip': handleAddExcludedIp($conn); break;
    case 'delete_excluded_ip': handleDeleteExcludedIp($conn); break;
    case 'get_details': handleGetDetails($conn); break;
    case 'get_ops': handleGetOps($conn); break;
    default: getDashboardData($conn); break;
}

// --- CENTRO OPERATIVO: pendientes, estado de tienda, alertas y resumen del día ---

function ops_scalar(mysqli $conn, string $sql, $default = 0) {
    $r = $conn->query($sql);
    if (!$r) return $default;
    $row = $r->fetch_row();
    $r->free();
    return $row ? $row[0] : $default;
}

function handleGetOps(mysqli $conn) {
    $config = [];
    $r = $conn->query("SELECT clave, valor FROM configuracion_tienda");
    if ($r) {
        while ($row = $r->fetch_assoc()) $config[$row['clave']] = $row['valor'];
        $r->free();
    }
    $modo = $config['modo_tienda'] ?? 'normal';
    $reservaMin = ($modo === 'alta_demanda')
        ? (int)($config['tiempo_reserva_alta_demanda_min'] ?? 30)
        : (int)($config['tiempo_reserva_normal_min'] ?? 1440);
    if ($reservaMin <= 0) $reservaMin = 1440;

    // --- Pedidos por estado (colas de trabajo) ---
    $orders = ['pendiente' => 0, 'pagado' => 0, 'reserva' => 0, 'completado_hoy' => 0];
    $r = $conn->query("SELECT estado, COUNT(*) AS c FROM pedidos WHERE estado IN ('pendiente','pagado','reserva') GROUP BY estado");
    if ($r) {
        while ($row = $r->fetch_assoc()) $orders[$row['estado']] = (int)$row['c'];
        $r->free();
    }
    $orders['completado_hoy'] = (int)ops_scalar($conn, "SELECT COUNT(*) FROM pedidos WHERE estado = 'completado' AND DATE(fecha_creacion) = CURDATE()");
    $orders['pendiente_vencidos'] = (int)ops_scalar($conn, "SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente' AND fecha_creacion < NOW() - INTERVAL $reservaMin MINUTE");
    $orders['pendiente_mas_antiguo_min'] = (int)ops_scalar($conn, "SELECT COALESCE(TIMESTAMPDIFF(MINUTE, MIN(fecha_creacion), NOW()),0) FROM pedidos WHERE estado = 'pendiente'");

    // --- Regalos disponibles (mismo cálculo que api/get_gift_availability.php) ---
    $slotsTeoricos = (int)($config['regalos_diarios_totales'] ?? 100);
    $slotsCompletados = (int)ops_scalar($conn, "SELECT COUNT(*) FROM log_regalos_completados WHERE fecha_envio >= NOW() - INTERVAL 1 DAY");
    $slotsReservados = (int)ops_scalar($conn, "SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente' AND fecha_creacion >= NOW() - INTERVAL $reservaMin MINUTE");
    $slotsDisponibles = max(0, $slotsTeoricos - $slotsCompletados - $slotsReservados);
    $forzado = (($config['forzar_compras_abiertas'] ?? '0') == '1');

    // --- Otras colas ---
    $verifPending = (int)ops_scalar($conn, "SELECT COUNT(*) FROM verification_requests WHERE status = 'pending'");
    $activeStates = "'scheduled','open','waiting_for_winner','locking','animating'";
    $rafflesActive = [];
    $r = $conn->query("SELECT r.raffle_id, r.title, r.status, r.start_time, r.end_time,
            (SELECT COUNT(*) FROM raffle_participants rp WHERE rp.raffle_id = r.raffle_id AND rp.review_status <> 'expelled') AS participants,
            (SELECT COUNT(*) FROM raffle_participants rp WHERE rp.raffle_id = r.raffle_id AND rp.review_status = 'under_review') AS under_review
        FROM raffles r WHERE r.status IN ($activeStates) ORDER BY r.end_time ASC LIMIT 5");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $row['raffle_id'] = (int)$row['raffle_id'];
            $row['participants'] = (int)$row['participants'];
            $row['under_review'] = (int)$row['under_review'];
            $rafflesActive[] = $row;
        }
        $r->free();
    }
    $deferredReview = (int)ops_scalar($conn, "SELECT COUNT(*) FROM raffle_participants rp JOIN raffles r ON r.raffle_id = rp.raffle_id
        WHERE rp.review_status = 'under_review' AND r.status IN ($activeStates)");

    // --- Resumen del día ---
    $today = [
        'revenue' => (float)ops_scalar($conn, "SELECT COALESCE(SUM(total_pagar),0) FROM pedidos WHERE DATE(fecha_creacion) = CURDATE() AND estado NOT IN ('cancelado')"),
        'orders' => (int)ops_scalar($conn, "SELECT COUNT(*) FROM pedidos WHERE DATE(fecha_creacion) = CURDATE()"),
        'cancelled' => (int)ops_scalar($conn, "SELECT COUNT(*) FROM pedidos WHERE DATE(fecha_creacion) = CURDATE() AND estado = 'cancelado'"),
        'new_users' => (int)ops_scalar($conn, "SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()"),
        // Solo giros reales: los cupones 'raffle_prize' (Premio Sorteo) no son giros.
        'spins' => (int)ops_scalar($conn, "SELECT COUNT(*) FROM daily_discounts WHERE DATE(created_at) = CURDATE() AND COALESCE(spin_source,'') <> 'raffle_prize'"),
        'coins_emitted' => (float)ops_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM coin_transactions WHERE DATE(created_at) = CURDATE() AND amount > 0"),
        'coins_spent' => (float)ops_scalar($conn, "SELECT COALESCE(SUM(-amount),0) FROM coin_transactions WHERE DATE(created_at) = CURDATE() AND amount < 0"),
        'gifts_sent_24h' => $slotsCompletados,
    ];

    // --- Últimos pedidos (compacto) ---
    $recent = [];
    $r = $conn->query("SELECT p.id, p.codigo_pedido, p.estado, p.total_pagar, p.fecha_creacion, u.game_username
        FROM pedidos p LEFT JOIN users u ON u.id = p.user_id ORDER BY p.fecha_creacion DESC LIMIT 8");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['total_pagar'] = (float)$row['total_pagar'];
            $recent[] = $row;
        }
        $r->free();
    }

    // --- Alertas (solo lo que rompe la normalidad) ---
    $alerts = [];
    if ($modo !== 'normal') {
        $labels = ['alta_demanda' => 'Alta demanda', 'ausente' => 'Ausente', 'mantenimiento' => 'Mantenimiento'];
        $alerts[] = ['level' => $modo === 'mantenimiento' ? 'danger' : 'warn', 'icon' => 'fa-store-slash',
            'text' => 'La tienda está en modo ' . ($labels[$modo] ?? $modo) . '.', 'href' => 'configuracion/gestionar_configuracion.php'];
    }
    if (!$forzado && $slotsDisponibles === 0 && $modo !== 'mantenimiento') {
        $alerts[] = ['level' => 'danger', 'icon' => 'fa-gift', 'text' => 'Sin regalos disponibles: la tienda no acepta más pedidos hasta liberar cupo.', 'href' => 'configuracion/gestionar_configuracion.php'];
    } elseif (!$forzado && $slotsTeoricos > 0 && $slotsDisponibles <= max(2, (int)ceil($slotsTeoricos * 0.1))) {
        $alerts[] = ['level' => 'warn', 'icon' => 'fa-gift', 'text' => "Quedan solo $slotsDisponibles regalos disponibles de $slotsTeoricos.", 'href' => 'configuracion/gestionar_configuracion.php'];
    }
    if ($forzado) {
        $alerts[] = ['level' => 'info', 'icon' => 'fa-unlock', 'text' => 'Compras forzadas abiertas: se ignora el límite de regalos.', 'href' => 'configuracion/gestionar_configuracion.php'];
    }
    if ($orders['pendiente_vencidos'] > 0) {
        $alerts[] = ['level' => 'warn', 'icon' => 'fa-hourglass-end', 'text' => $orders['pendiente_vencidos'] . ' pedido(s) pendiente(s) superan el tiempo de reserva (' . $reservaMin . ' min).', 'href' => 'pedidos/gestionar_pedidos.php'];
    }
    foreach ($rafflesActive as $rf) {
        if ($rf['end_time'] && strtotime($rf['end_time']) !== false) {
            $mins = (int)round((strtotime($rf['end_time']) - time()) / 60);
            if ($mins >= 0 && $mins <= 120) {
                $alerts[] = ['level' => 'info', 'icon' => 'fa-bolt', 'text' => 'El sorteo “' . $rf['title'] . '” termina en ' . $mins . ' min.', 'href' => 'sorteos_flash/sala_mando.php?id=' . $rf['raffle_id']];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'store' => [
            'modo' => $modo,
            'forzar_compras_abiertas' => $forzado,
            'regalos_totales' => $slotsTeoricos,
            'regalos_disponibles' => $slotsDisponibles,
            'regalos_reservados' => $slotsReservados,
            'tiempo_reserva_min' => $reservaMin,
            'alerta_sin_regalos_activa' => (($config['alerta_sin_regalos_activa'] ?? '0') == '1'),
        ],
        'orders' => $orders,
        'queues' => [
            'verifications_pending' => $verifPending,
            'deferred_review' => $deferredReview,
            'raffles_active' => count($rafflesActive),
        ],
        'raffles' => $rafflesActive,
        'today' => $today,
        'recent_orders' => $recent,
        'alerts' => $alerts,
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit();
}

// --- FUNCIONES MANEJADORAS ---

function getExclusionData($conn) {
    $excluded_ips = [];
    $result = $conn->query("SELECT ip_hash FROM excluded_ips");
    if ($result) {
        while ($row = $result->fetch_assoc()) $excluded_ips[] = $row['ip_hash'];
        $result->free();
    }
    
    $clause = ''; $types = ''; $params = [];
    if (!empty($excluded_ips)) {
        $placeholders = implode(',', array_fill(0, count($excluded_ips), '?'));
        $clause = " AND ip_address_hash NOT IN ($placeholders) ";
        $types = str_repeat('s', count($excluded_ips));
        $params = $excluded_ips;
    }
    return [$clause, $types, $params];
}

function handleGetExcludedIps($conn) {
    $result = $conn->query("SELECT id, ip_hash, description FROM excluded_ips ORDER BY id DESC");
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
    $result->free();
    $conn->close(); exit();
}

function handleAddExcludedIp($conn) {
    $ip_hash = $_POST['ip_hash'] ?? ''; $description = $_POST['description'] ?? 'Exclusión manual';
    if (!empty($ip_hash)) {
        $stmt = $conn->prepare("INSERT INTO excluded_ips (ip_hash, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $ip_hash, $description);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
    } else { echo json_encode(['success' => false, 'error' => 'IP Hash no puede estar vacío']); }
    $conn->close(); exit();
}

function handleDeleteExcludedIp($conn) {
    $id = $_POST['id'] ?? 0;
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM excluded_ips WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        echo json_encode(['success' => $stmt->affected_rows > 0]);
        $stmt->close();
    } else { echo json_encode(['success' => false, 'error' => 'ID inválido']); }
    $conn->close(); exit();
}

function handleGetDetails($conn) {
    list($exclusion_clause, $types, $params) = getExclusionData($conn);
    $type = $_GET['type'] ?? '';
    $identifier = $_GET['identifier'] ?? '';
    $response = ['success' => false, 'data' => []];
    $sql = '';

    if ($type === 'visits' && preg_match('/^(\d{1,2}):00$/', $identifier, $matches)) {
        $hour = $matches[1];
        $sql = "SELECT ip_address_hash, visit_timestamp as timestamp FROM page_visits WHERE DATE(visit_timestamp) = DATE(NOW()) AND HOUR(visit_timestamp) = ? $exclusion_clause ORDER BY timestamp DESC";
        array_unshift($params, $hour); $types = 'i' . $types;
    } elseif ($type === 'clicks' && !empty($identifier)) {
        if ($identifier === 'Otros') {
            $all_products_sql = "SELECT product_name, COUNT(*) as total_clicks FROM product_clicks WHERE DATE(click_timestamp) = DATE(NOW()) $exclusion_clause GROUP BY product_name ORDER BY total_clicks DESC";
            $stmt_all = $conn->prepare($all_products_sql);
            if(!empty($params)) $stmt_all->bind_param($types, ...$params);
            $stmt_all->execute();
            $all_products_result = $stmt_all->get_result();
            $response['data'] = array_slice($all_products_result->fetch_all(MYSQLI_ASSOC), 7);
            $all_products_result->free();
            $stmt_all->close();
            $response['success'] = true; $response['is_other_details'] = true;
            echo json_encode($response); $conn->close(); exit();
        }
        $sql = "SELECT ip_address_hash, click_timestamp as timestamp FROM product_clicks WHERE DATE(click_timestamp) = DATE(NOW()) AND product_name = ? $exclusion_clause ORDER BY timestamp DESC";
        array_unshift($params, $identifier); $types = 's' . $types;
    }
    
    if(!empty($sql)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if(!empty($params)) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $details_result = $stmt->get_result();
            $response['data'] = $details_result->fetch_all(MYSQLI_ASSOC);
            $details_result->free();
            $stmt->close();
            $response['success'] = true;
        }
    }

    echo json_encode($response);
    $conn->close(); exit();
}

// --- FUNCIÓN PRINCIPAL: COMMAND CENTER DATA ---

function getDashboardData($conn) {
    list($exclusion_clause, $types, $params) = getExclusionData($conn);
    $period = $_GET['period'] ?? 'daily';
    $unique_mode = $_GET['unique_mode'] ?? 'per_day';

    $response_data = [];

    // =============================================
    // 1. KPI CARDS - Revenue, Orders, Visitors, Spins
    // =============================================

    // --- Revenue Today ---
    $r = $conn->query("SELECT COALESCE(SUM(total_pagar),0) as total FROM pedidos WHERE DATE(fecha_creacion) = CURDATE() AND estado NOT IN ('cancelado')");
    if ($r) {
        $revenue_today = (float)$r->fetch_assoc()['total'];
        $r->free();
    } else {
        $revenue_today = 0;
    }

    // --- Revenue Yesterday ---
    $r = $conn->query("SELECT COALESCE(SUM(total_pagar),0) as total FROM pedidos WHERE DATE(fecha_creacion) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND estado NOT IN ('cancelado')");
    if ($r) {
        $revenue_yesterday = (float)$r->fetch_assoc()['total'];
        $r->free();
    } else {
        $revenue_yesterday = 0;
    }

    $revenue_change = $revenue_yesterday > 0 ? round((($revenue_today - $revenue_yesterday) / $revenue_yesterday) * 100, 1) : ($revenue_today > 0 ? 100 : 0);

    // --- Orders Today / Yesterday ---
    $r = $conn->query("SELECT COUNT(*) as c FROM pedidos WHERE DATE(fecha_creacion) = CURDATE()");
    if ($r) {
        $orders_today = (int)$r->fetch_assoc()['c'];
        $r->free();
    } else {
        $orders_today = 0;
    }
    $r = $conn->query("SELECT COUNT(*) as c FROM pedidos WHERE DATE(fecha_creacion) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
    if ($r) {
        $orders_yesterday = (int)$r->fetch_assoc()['c'];
        $r->free();
    } else {
        $orders_yesterday = 0;
    }

    $orders_change = $orders_yesterday > 0 ? round((($orders_today - $orders_yesterday) / $orders_yesterday) * 100, 1) : ($orders_today > 0 ? 100 : 0);

    // --- Pending Orders ---
    $r = $conn->query("SELECT COUNT(*) as c FROM pedidos WHERE estado = 'pendiente'");
    if ($r) {
        $orders_pending = (int)$r->fetch_assoc()['c'];
        $r->free();
    } else {
        $orders_pending = 0;
    }

    // --- Unique Visitors Today (with exclusion) ---
    $sql_uv = "SELECT COUNT(DISTINCT ip_address_hash) as c FROM page_visits WHERE DATE(visit_timestamp) = CURDATE() $exclusion_clause";
    $stmt = $conn->prepare($sql_uv);
    if(!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $uv_result = $stmt->get_result();
    if ($uv_result) {
        $visitors_today = (int)$uv_result->fetch_assoc()['c'];
        $uv_result->free();
    } else {
        $visitors_today = 0;
    }
    $stmt->close();

    $sql_uv_y = "SELECT COUNT(DISTINCT ip_address_hash) as c FROM page_visits WHERE DATE(visit_timestamp) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) $exclusion_clause";
    $stmt = $conn->prepare($sql_uv_y);
    if(!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $uv_y_result = $stmt->get_result();
    if ($uv_y_result) {
        $visitors_yesterday = (int)$uv_y_result->fetch_assoc()['c'];
        $uv_y_result->free();
    } else {
        $visitors_yesterday = 0;
    }
    $stmt->close();
    $visitors_change = $visitors_yesterday > 0 ? round((($visitors_today - $visitors_yesterday) / $visitors_yesterday) * 100, 1) : ($visitors_today > 0 ? 100 : 0);

    // --- Conversion Rate (Unique Visitors -> Orders today) ---
    $conversion_rate = $visitors_today > 0 ? round(($orders_today / $visitors_today) * 100, 1) : 0;

    // --- Spins Today ---
    $r = $conn->query("SELECT COUNT(*) as c FROM daily_discounts WHERE DATE(created_at) = CURDATE() AND COALESCE(spin_source,'') <> 'raffle_prize'");
    if ($r) {
        $spins_today = (int)$r->fetch_assoc()['c'];
        $r->free();
    } else {
        $spins_today = 0;
    }
    $r = $conn->query("SELECT COUNT(*) as c FROM daily_discounts WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND COALESCE(spin_source,'') <> 'raffle_prize'");
    if ($r) {
        $spins_yesterday = (int)$r->fetch_assoc()['c'];
        $r->free();
    } else {
        $spins_yesterday = 0;
    }

    $spins_change = $spins_yesterday > 0 ? round((($spins_today - $spins_yesterday) / $spins_yesterday) * 100, 1) : ($spins_today > 0 ? 100 : 0);

    // --- Registered Users ---
    $r = $conn->query("SELECT COUNT(*) as c FROM users");
    if ($r) {
        $total_users = (int)$r->fetch_assoc()['c'];
        $r->free();
    } else {
        $total_users = 0;
    }
    $r = $conn->query("SELECT COUNT(*) as c FROM users WHERE DATE(created_at) = CURDATE()");
    if ($r) {
        $new_users_today = (int)$r->fetch_assoc()['c'];
        $r->free();
    } else {
        $new_users_today = 0;
    }

    $response_data['kpis'] = [
        'revenue_today' => $revenue_today,
        'revenue_yesterday' => $revenue_yesterday,
        'revenue_change' => $revenue_change,
        'orders_today' => $orders_today,
        'orders_yesterday' => $orders_yesterday,
        'orders_change' => $orders_change,
        'orders_pending' => $orders_pending,
        'visitors_today' => $visitors_today,
        'visitors_yesterday' => $visitors_yesterday,
        'visitors_change' => $visitors_change,
        'conversion_rate' => $conversion_rate,
        'spins_today' => $spins_today,
        'spins_yesterday' => $spins_yesterday,
        'spins_change' => $spins_change,
        'total_users' => $total_users,
        'new_users_today' => $new_users_today,
    ];

    // =============================================
    // 2. TRAFFIC CHART (Dual Line: Total vs Unique)
    // =============================================
    $where_visits = "WHERE 1=1"; $where_clicks = "WHERE 1=1";
    switch ($period) {
        case 'weekly':
            $where_visits = "WHERE YEARWEEK(visit_timestamp, 1) = YEARWEEK(NOW(), 1)";
            $where_clicks = "WHERE YEARWEEK(click_timestamp, 1) = YEARWEEK(NOW(), 1)";
            break;
        case 'monthly':
            $where_visits = "WHERE MONTH(visit_timestamp) = MONTH(NOW()) AND YEAR(visit_timestamp) = YEAR(NOW())";
            $where_clicks = "WHERE MONTH(click_timestamp) = MONTH(NOW()) AND YEAR(click_timestamp) = YEAR(NOW())";
            break;
        default:
            $where_visits = "WHERE DATE(visit_timestamp) = DATE(NOW())";
            $where_clicks = "WHERE DATE(click_timestamp) = DATE(NOW())";
    }

    // Page visits stats
    $stmt = $conn->prepare("SELECT COUNT(*) as total, COUNT(DISTINCT ip_address_hash) as unique_count FROM page_visits $where_visits $exclusion_clause");
    if(!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $page_stats_result = $stmt->get_result();
    if ($page_stats_result) {
        $response_data['page_visits_stats'] = $page_stats_result->fetch_assoc();
        $page_stats_result->free();
    } else {
        $response_data['page_visits_stats'] = [];
    }
    $stmt->close();

    // Product clicks stats
    $stmt = $conn->prepare("SELECT COUNT(*) as total, COUNT(DISTINCT ip_address_hash) as unique_count FROM product_clicks $where_clicks $exclusion_clause");
    if(!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $click_stats_result = $stmt->get_result();
    if ($click_stats_result) {
        $response_data['product_clicks_stats'] = $click_stats_result->fetch_assoc();
        $click_stats_result->free();
    } else {
        $response_data['product_clicks_stats'] = [];
    }
    $stmt->close();

    // Traffic chart data
    $chart_data_visits = [];
    if ($period === 'daily') {
        if ($unique_mode === 'per_day') {
            $temp_data = array_fill_keys(range(0,23), ['total_data' => 0, 'unique_data' => 0]);
            $sql_totals = "SELECT HOUR(visit_timestamp) as label, COUNT(*) as total_data FROM page_visits $where_visits $exclusion_clause GROUP BY label";
            $stmt_totals = $conn->prepare($sql_totals);
            if(!empty($params)) $stmt_totals->bind_param($types, ...$params);
            $stmt_totals->execute();
            $totals_result = $stmt_totals->get_result();
            if ($totals_result) {
                while($row = $totals_result->fetch_assoc()) { $temp_data[(int)$row['label']]['total_data'] = (int)$row['total_data']; }
                $totals_result->free();
            }
            $stmt_totals->close();

            $sql_uniques = "SELECT HOUR(first_visit) as label, COUNT(ip_address_hash) as unique_data FROM (SELECT ip_address_hash, MIN(visit_timestamp) as first_visit FROM page_visits $where_visits $exclusion_clause GROUP BY ip_address_hash) as uv GROUP BY label";
            $stmt_uniques = $conn->prepare($sql_uniques);
            if(!empty($params)) $stmt_uniques->bind_param($types, ...$params);
            $stmt_uniques->execute();
            $uniques_result = $stmt_uniques->get_result();
            if ($uniques_result) {
                while($row = $uniques_result->fetch_assoc()) { $temp_data[(int)$row['label']]['unique_data'] = (int)$row['unique_data']; }
                $uniques_result->free();
            }
            $stmt_uniques->close();

            foreach($temp_data as $hour => $data) {
                $chart_data_visits[] = ['label' => $hour.':00', 'total_data' => $data['total_data'], 'unique_data' => $data['unique_data']];
            }
        } else {
            $sql = "SELECT HOUR(visit_timestamp) as label, COUNT(*) as total_data, COUNT(DISTINCT ip_address_hash) as unique_data FROM page_visits $where_visits $exclusion_clause GROUP BY label ORDER BY label ASC";
            $stmt = $conn->prepare($sql);
            if(!empty($params)) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $temp_data = array_fill_keys(range(0,23), ['total_data' => 0, 'unique_data' => 0]);
            $hourly_result = $stmt->get_result();
            if ($hourly_result) {
                while($row = $hourly_result->fetch_assoc()) { $temp_data[(int)$row['label']] = $row; }
                $hourly_result->free();
            }
            $stmt->close();
            foreach($temp_data as $hour => $data) { $chart_data_visits[] = ['label' => $hour.':00', 'total_data' => $data['total_data'], 'unique_data' => $data['unique_data']]; }
        }
    } else {
        $date_format = ($period === 'weekly') ? '%a' : '%e %b';
        $sql = "SELECT DATE_FORMAT(visit_timestamp, '$date_format') as label, COUNT(*) as total_data, COUNT(DISTINCT ip_address_hash) as unique_data FROM page_visits $where_visits $exclusion_clause GROUP BY label ORDER BY MIN(visit_timestamp) ASC";
        $stmt = $conn->prepare($sql);
        if(!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $visits_result = $stmt->get_result();
        if ($visits_result) {
            $chart_data_visits = $visits_result->fetch_all(MYSQLI_ASSOC);
            $visits_result->free();
        } else {
            $chart_data_visits = [];
        }
        $stmt->close();
    }
    $response_data['page_visits_chart'] = $chart_data_visits;

    // =============================================
    // 3. PRODUCT INTEREST CHART (Top Clicked)
    // =============================================
    $stmt = $conn->prepare("SELECT product_name, COUNT(*) as total_clicks, COUNT(DISTINCT ip_address_hash) as unique_clicks FROM product_clicks $where_clicks $exclusion_clause GROUP BY product_name ORDER BY total_clicks DESC");
    if(!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $products_result = $stmt->get_result();
    if ($products_result) {
        $all_products = $products_result->fetch_all(MYSQLI_ASSOC);
        $products_result->free();
    } else {
        $all_products = [];
    }
    $stmt->close();

    $top_products = array_slice($all_products, 0, 7);
    if (count($all_products) > 7) {
        $other_total = 0; $other_uniques = 0;
        foreach(array_slice($all_products, 7) as $p) { $other_total += $p['total_clicks']; $other_uniques += $p['unique_clicks']; }
        $top_products[] = ['product_name' => 'Otros', 'total_clicks' => $other_total, 'unique_clicks' => $other_uniques];
    }
    $response_data['product_clicks_chart'] = $top_products;

    // =============================================
    // 4. TOP ACTIVE USERS (Orders + Spins combined)
    // =============================================
    $top_users_sql = "
        SELECT u.id, u.game_username as game_username, u.game_username as username, 
               COALESCE(o.order_count, 0) as order_count,
               COALESCE(o.total_spent, 0) as total_spent,
               COALESCE(s.spin_count, 0) as spin_count,
               a.filename as avatar_filename
        FROM users u
        LEFT JOIN (
            SELECT user_id, COUNT(*) as order_count, SUM(total_pagar) as total_spent
            FROM pedidos WHERE fecha_creacion >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND estado NOT IN ('cancelado')
            GROUP BY user_id
        ) o ON o.user_id = u.id
        LEFT JOIN (
            SELECT user_id, COUNT(*) as spin_count
            FROM daily_discounts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND COALESCE(spin_source,'') <> 'raffle_prize'
            GROUP BY user_id
        ) s ON s.user_id = u.id
        LEFT JOIN avatars a ON u.avatar_id = a.id
        WHERE COALESCE(o.order_count, 0) + COALESCE(s.spin_count, 0) > 0
        ORDER BY (COALESCE(o.order_count, 0) * 3 + COALESCE(s.spin_count, 0)) DESC
        LIMIT 8
    ";
    $r = $conn->query($top_users_sql);
    if ($r) {
        $response_data['top_users'] = $r->fetch_all(MYSQLI_ASSOC);
        $r->free();
    } else {
        $response_data['top_users'] = [];
    }

    // =============================================
    // 5. RECENT ACTIVITY FEED (last 15 events)
    // =============================================
    $activity_feed = [];

    // Recent orders
    $r = $conn->query("SELECT 'order' as type, p.id, p.codigo_pedido as code, p.total_pagar as amount, p.estado as status, p.fecha_creacion as timestamp, u.game_username as game_username, u.game_username as username FROM pedidos p LEFT JOIN users u ON p.user_id = u.id ORDER BY p.fecha_creacion DESC LIMIT 8");
    if ($r) {
        while ($row = $r->fetch_assoc()) $activity_feed[] = $row;
        $r->free();
    }

    // Recent spins
    $r = $conn->query("SELECT 'spin' as type, dd.id, dd.item_name as code, dd.discount_percent as amount, NULL as status, dd.created_at as timestamp, u.game_username as game_username, u.game_username as username FROM daily_discounts dd LEFT JOIN users u ON dd.user_id = u.id WHERE COALESCE(dd.spin_source,'') <> 'raffle_prize' ORDER BY dd.created_at DESC LIMIT 7");
    if ($r) {
        while ($row = $r->fetch_assoc()) $activity_feed[] = $row;
        $r->free();
    }

    // Sort by timestamp desc
    usort($activity_feed, function($a, $b) { return strtotime($b['timestamp']) - strtotime($a['timestamp']); });
    $response_data['activity_feed'] = array_slice($activity_feed, 0, 12);

    // =============================================
    // 6. ORDERS BY STATUS (for donut chart)
    // =============================================
    $r = $conn->query("SELECT estado, COUNT(*) as count FROM pedidos WHERE fecha_creacion >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY estado ORDER BY count DESC");
    if ($r) {
        $response_data['orders_by_status'] = $r->fetch_all(MYSQLI_ASSOC);
        $r->free();
    } else {
        $response_data['orders_by_status'] = [];
    }

    // =============================================
    // 7. REVENUE LAST 7 DAYS (for sparkline)
    // =============================================
    $r = $conn->query("SELECT DATE(fecha_creacion) as day, COALESCE(SUM(total_pagar),0) as revenue FROM pedidos WHERE fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND estado NOT IN ('cancelado') GROUP BY day ORDER BY day ASC");
    if ($r) {
        $response_data['revenue_7days'] = $r->fetch_all(MYSQLI_ASSOC);
        $r->free();
    } else {
        $response_data['revenue_7days'] = [];
    }

    echo json_encode($response_data, JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
    $conn->close(); exit();
}
?>
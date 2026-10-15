<?php
// Archivo: api_girodiario.php — Backend AJAX para el módulo Giro Diario
// =====================================================================

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// Seguridad de sesión
require_once __DIR__ . '/../../api/session_security.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado.']);
    exit;
}

require_once __DIR__ . '/../core/db_connect.php';
require_once __DIR__ . '/../../core/DailySpinSchedule.php';
require_once __DIR__ . '/../../core/DailySpinFilters.php';
require_once __DIR__ . '/../../core/DailySpinEligibility.php';
require_once __DIR__ . '/../../api/logic_discounts.php';

// Esquema del Giro Diario (columnas de descuento por modo, tabla de filtros,
// modo/filtro por giro). Mismo núcleo que usa la API pública: idempotente.
DailySpinFilters::ensureSchema($conn);

// Ventana de actividad para "usuarios activos" (último login).
const GD_ACTIVE_DAYS = 30;

// Configuración del día + filtros resueltos contra el catálogo vigente.
function gd_load_day_config(mysqli $conn, string $eventDate): ?array {
    $stmt = $conn->prepare("SELECT * FROM daily_spin_config WHERE event_date = ? LIMIT 1");
    $stmt->bind_param('s', $eventDate);
    $stmt->execute();
    $config = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $config ?: null;
}

// Payload compartido por get_config y get_filters: catálogo, filtros y modos.
function gd_filters_payload(mysqli $conn, ?array $config): array {
    $entries  = DailySpinFilters::loadShopEntries();
    $resolved = DailySpinFilters::resolve($conn, $entries);

    $total = 0; $noTracks = 0;
    foreach ($entries as $e) {
        if (!is_array($e)) continue;
        $total++;
        if (!DailySpinFilters::isTrackEntry($e)) $noTracks++;
    }

    $enabled = 0; $enabledItems = 0; $weighted = 0;
    foreach ($resolved as $f) {
        if (!$f['enabled'] || $f['count'] <= 0) continue;
        $enabled++;
        $enabledItems += $f['count'];
        $weighted += $f['count'] * $f['percent'];
    }

    return [
        'filters' => $resolved,
        'catalog' => [
            'total_items'       => $total,
            'no_tracks_items'   => $noTracks,
            'track_items'       => $total - $noTracks,
            'categories'        => count(array_filter($resolved, static fn($f) => $f['count'] > 0)),
            'enabled_filters'   => $enabled,
            'enabled_items'     => $enabledItems,
            'avg_filter_percent'=> $enabledItems > 0 ? round($weighted / $enabledItems, 1) : 0,
        ],
        'modes' => [
            DailySpinFilters::MODE_ALL => [
                'percent' => DailySpinFilters::modePercent($config, DailySpinFilters::MODE_ALL),
                'count'   => $total,
            ],
            DailySpinFilters::MODE_NO_TRACKS => [
                'percent' => DailySpinFilters::modePercent($config, DailySpinFilters::MODE_NO_TRACKS),
                'count'   => $noTracks,
            ],
        ],
        'defaults' => [
            'all'       => DailySpinFilters::DEFAULT_ALL_PERCENT,
            'no_tracks' => DailySpinFilters::DEFAULT_NO_TRACKS_PERCENT,
            'filter'    => DailySpinFilters::DEFAULT_FILTER_PERCENT,
        ],
    ];
}

// Política global de elegibilidad (quién puede girar) + catálogo de opciones para el panel.
function gd_eligibility_payload(mysqli $conn): array {
    $options = [];
    foreach (DailySpinEligibility::policies() as $policy) {
        $options[] = ['value' => $policy, 'label' => DailySpinEligibility::label($policy)];
    }
    return [
        'policy'  => DailySpinEligibility::load($conn),
        'default' => DailySpinEligibility::DEFAULT_POLICY,
        'options' => $options,
    ];
}

// true si la tabla tiene la columna (para métricas que dependen de columnas opcionales).
function gd_has_column(mysqli $conn, string $table, string $column): bool {
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'");
    $has = $res && $res->num_rows > 0;
    if ($res) $res->free();
    return $has;
}

// Crea la fila de configuración del día de negocio si aún no existe (idempotente).
function ensure_day_config(mysqli $conn, string $eventDate): void {
    $check = $conn->prepare("SELECT id FROM daily_spin_config WHERE event_date = ? LIMIT 1");
    $check->bind_param('s', $eventDate);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    if ($exists) return;

    $ins = $conn->prepare("INSERT INTO daily_spin_config (event_date, mode, flash_offer_enabled, flash_offer_limit, flash_offer_claimed, flash_offer_percent, paid_spins_enabled, paid_spins_limit, paid_spin_price) VALUES (?, 'normal', 0, 10, 0, 15, 0, 3, 50)");
    $ins->bind_param('s', $eventDate);
    $ins->execute();
    $ins->close();
}

// Calendario del Giro Diario. La conexión del panel NO fija time_zone, así que
// nunca usamos CURDATE()/NOW(): el día de negocio (19:00→19:00 America/Lima) y
// la ventana UTC salen del mismo snapshot que emplea la API pública.
$schedule    = DailySpinSchedule::at();
$eventDate   = $schedule->businessDate();
$windowStart = $schedule->windowStartSql();
$windowEnd   = $schedule->windowEndSql();

// ── Leer acción ──────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ══════════════════════════════════════════════════════════════════
    // 1. GET CONFIG — Devuelve la configuración del día actual
    // ══════════════════════════════════════════════════════════════════
    case 'get_config':
        ensure_day_config($conn, $eventDate);
        $config = gd_load_day_config($conn, $eventDate);

        echo json_encode([
            'success'         => true,
            'config'          => $config,
            'business_date'   => $eventDate,
            'window_end_utc'  => $schedule->windowEndIso(),
            'server_time_utc' => $schedule->nowIso(),
            'eligibility'     => gd_eligibility_payload($conn),
        ] + gd_filters_payload($conn, $config));
        break;

    // ══════════════════════════════════════════════════════════════════
    // 1B. SAVE FILTERS — Categorías: visibilidad ON/OFF y % individual
    // ══════════════════════════════════════════════════════════════════
    case 'save_filters':
        $raw = $_POST['filters'] ?? '[]';
        $items = json_decode((string)$raw, true);
        if (!is_array($items)) {
            echo json_encode(['success' => false, 'error' => 'Filtros no válidos.']);
            break;
        }
        foreach ($items as $item) {
            $p = (int)($item['percent'] ?? -1);
            if ($p < 0 || $p > 100) {
                echo json_encode(['success' => false, 'error' => 'Porcentaje de categoría fuera de rango (0-100).']);
                break 2;
            }
        }
        $written = DailySpinFilters::saveRows($conn, $items);
        $config  = gd_load_day_config($conn, $eventDate);
        echo json_encode(['success' => true, 'written' => $written] + gd_filters_payload($conn, $config));
        break;

    // ══════════════════════════════════════════════════════════════════
    // 2. SAVE CONFIG — Actualiza la configuración del día
    // ══════════════════════════════════════════════════════════════════
    case 'save_config':
        $mode               = $_POST['mode'] ?? 'normal';
        $flash_enabled       = intval($_POST['flash_offer_enabled'] ?? 0);
        $flash_limit         = intval($_POST['flash_offer_limit'] ?? 10);
        $flash_percent       = intval($_POST['flash_offer_percent'] ?? 15);
        $normal_percent      = intval($_POST['normal_discount_percent'] ?? DailySpinFilters::DEFAULT_ALL_PERCENT);
        $no_tracks_percent   = intval($_POST['no_tracks_discount_percent'] ?? DailySpinFilters::DEFAULT_NO_TRACKS_PERCENT);

        $paid_spins_enabled  = intval($_POST['paid_spins_enabled'] ?? 0);
        $paid_spins_limit    = intval($_POST['paid_spins_limit'] ?? ($_POST['paid_spin_limit'] ?? 0));
        $paid_spin_price     = intval($_POST['paid_spin_price'] ?? 0);
        // Elegibilidad: ajuste global, opcional en el payload (clientes antiguos no lo envían).
        $eligibility_policy  = isset($_POST['eligibility']) ? strtolower(trim((string)$_POST['eligibility'])) : null;

        // Validaciones
        $allowed_modes = ['normal', 'boosted', 'jackpot', 'disabled'];
        if (!in_array($mode, $allowed_modes)) {
            echo json_encode(['success' => false, 'error' => 'Modo no válido.']);
            break;
        }
        if ($eligibility_policy !== null && !DailySpinEligibility::isValidPolicy($eligibility_policy)) {
            echo json_encode(['success' => false, 'error' => 'Elegibilidad no válida.']);
            break;
        }
        if ($flash_limit < 0 || $flash_limit > 9999) {
            echo json_encode(['success' => false, 'error' => 'Límite fuera de rango.']);
            break;
        }
        if ($flash_percent < 0 || $flash_percent > 100) {
            echo json_encode(['success' => false, 'error' => 'Porcentaje fuera de rango.']);
            break;
        }
        if ($normal_percent < 0 || $normal_percent > 100) {
            echo json_encode(['success' => false, 'error' => 'Descuento global fuera de rango.']);
            break;
        }
        if ($no_tracks_percent < 0 || $no_tracks_percent > 100) {
            echo json_encode(['success' => false, 'error' => 'Descuento Sin Pistas fuera de rango.']);
            break;
        }

        if ($paid_spins_enabled !== 0 && $paid_spins_enabled !== 1) {
            echo json_encode(['success' => false, 'error' => 'Paid Spins: toggle no válido.']);
            break;
        }
        if ($paid_spins_limit < 0 || $paid_spins_limit > 9999) {
            echo json_encode(['success' => false, 'error' => 'Paid Spins: límite fuera de rango.']);
            break;
        }
        if ($paid_spin_price < 0 || $paid_spin_price > 1000000) {
            echo json_encode(['success' => false, 'error' => 'Paid Spins: precio fuera de rango.']);
            break;
        }

        ensure_day_config($conn, $eventDate);

        $upd = $conn->prepare("UPDATE daily_spin_config SET mode = ?, flash_offer_enabled = ?, flash_offer_limit = ?, flash_offer_percent = ?, normal_discount_percent = ?, no_tracks_discount_percent = ?, paid_spins_enabled = ?, paid_spins_limit = ?, paid_spin_price = ? WHERE event_date = ?");
        $upd->bind_param('siiiiiiiis', $mode, $flash_enabled, $flash_limit, $flash_percent, $normal_percent, $no_tracks_percent, $paid_spins_enabled, $paid_spins_limit, $paid_spin_price, $eventDate);
        $upd->execute();
        $affected = $upd->affected_rows;
        $upd->close();

        if ($eligibility_policy !== null) {
            try {
                DailySpinEligibility::save($conn, $eligibility_policy);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage(), 'updated' => $affected]);
                break;
            }
        }

        echo json_encode([
            'success'     => true,
            'updated'     => $affected,
            'eligibility' => gd_eligibility_payload($conn),
        ]);
        break;

    // ══════════════════════════════════════════════════════════════════
    // 3. SEARCH USER — Buscar por nickname o teléfono
    // ══════════════════════════════════════════════════════════════════
    case 'search_user':
        $q = trim($_POST['query'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode(['success' => false, 'error' => 'Ingresa al menos 2 caracteres.']);
            break;
        }

        $like = '%' . $q . '%';
        $stmt = $conn->prepare("
            SELECT u.id, u.game_username, u.whatsapp_phone_e164, u.bonus_spins,
                   a.filename AS avatar_filename,
                   COALESCE(c.balance, 0) AS coin_balance
            FROM users u
            LEFT JOIN avatars a ON a.id = u.avatar_id
            LEFT JOIN coins c ON c.user_id = u.id
            WHERE u.game_username LIKE ? OR u.whatsapp_phone_e164 LIKE ?
            ORDER BY u.game_username ASC
            LIMIT 20
        ");
        $stmt->bind_param('ss', $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();

        echo json_encode(['success' => true, 'users' => $users]);
        break;

    // ══════════════════════════════════════════════════════════════════
    // 3B. USER INSPECTOR — Listas precargadas (girados hoy / no giraron)
    // ══════════════════════════════════════════════════════════════════
    case 'get_user_inspector':
        // "Hoy" = ventana de negocio actual [windowStart, windowEnd) en UTC.
        // A) Ya girados hoy (SIN LÍMITE), con el modo/filtro del último giro.
        $stmtA = $conn->prepare("
            SELECT
                u.id,
                u.game_username,
                u.whatsapp_phone_e164,
                u.bonus_spins,
                a.filename AS avatar_filename,
                COALESCE(c.balance, 0) AS coin_balance,
                t.spins_today,
                t.last_spin_at,
                t.best_percent,
                d.spin_mode  AS last_mode,
                d.filter_key AS last_filter
            FROM (
                SELECT user_id, COUNT(*) AS spins_today, MAX(created_at) AS last_spin_at, MAX(discount_percent) AS best_percent, MAX(id) AS last_id
                FROM daily_discounts
                WHERE created_at >= ? AND created_at < ?
                  AND COALESCE(spin_source,'') <> 'raffle_prize'
                GROUP BY user_id
            ) t
            INNER JOIN users u ON u.id = t.user_id
            LEFT JOIN daily_discounts d ON d.id = t.last_id
            LEFT JOIN avatars a ON a.id = u.avatar_id
            LEFT JOIN coins c ON c.user_id = u.id
            ORDER BY t.last_spin_at DESC
        ");
        $stmtA->bind_param('ss', $windowStart, $windowEnd);
        $stmtA->execute();
        $resA = $stmtA->get_result();
        $spun_today = [];
        while ($row = $resA->fetch_assoc()) {
            $row['last_spin_at'] = UtcTime::sqlToIso($row['last_spin_at']);
            $row['spins_today']  = (int)$row['spins_today'];
            $row['best_percent'] = (int)$row['best_percent'];
            $row['last_label']   = DailySpinFilters::describeSpin($row['last_mode'], $row['last_filter']);
            $spun_today[] = $row;
        }
        $stmtA->close();

        // B) Aún no tiraron: búsqueda opcional + paginación + total real.
        $qB      = trim((string)($_POST['query'] ?? ''));
        $limitB  = max(1, min(200, intval($_POST['limit'] ?? 30)));
        $offsetB = max(0, intval($_POST['offset'] ?? 0));
        $likeB   = '%' . $qB . '%';
        $whereQ  = $qB !== '' ? " AND (u.game_username LIKE ? OR u.whatsapp_phone_e164 LIKE ?)" : '';

        $notSpunWhere = "
            WHERE NOT EXISTS (
                SELECT 1
                FROM daily_discounts dd
                WHERE dd.user_id = u.id
                  AND dd.created_at >= ? AND dd.created_at < ?
                  AND COALESCE(dd.spin_source,'') <> 'raffle_prize'
            )" . $whereQ;

        $stmtC = $conn->prepare("SELECT COUNT(*) AS n FROM users u" . $notSpunWhere);
        if ($qB !== '') $stmtC->bind_param('ssss', $windowStart, $windowEnd, $likeB, $likeB);
        else            $stmtC->bind_param('ss', $windowStart, $windowEnd);
        $stmtC->execute();
        $not_spun_total = (int)($stmtC->get_result()->fetch_assoc()['n'] ?? 0);
        $stmtC->close();

        $stmtB = $conn->prepare("
            SELECT
                u.id,
                u.game_username,
                u.whatsapp_phone_e164,
                u.bonus_spins,
                a.filename AS avatar_filename,
                COALESCE(c.balance, 0) AS coin_balance
            FROM users u
            LEFT JOIN avatars a ON a.id = u.avatar_id
            LEFT JOIN coins c ON c.user_id = u.id
            " . $notSpunWhere . "
            ORDER BY u.id DESC
            LIMIT ? OFFSET ?
        ");
        if ($qB !== '') $stmtB->bind_param('ssssii', $windowStart, $windowEnd, $likeB, $likeB, $limitB, $offsetB);
        else            $stmtB->bind_param('ssii', $windowStart, $windowEnd, $limitB, $offsetB);
        $stmtB->execute();
        $resB = $stmtB->get_result();
        $not_spun_today = [];
        while ($row = $resB->fetch_assoc()) {
            $not_spun_today[] = $row;
        }
        $stmtB->close();

        echo json_encode([
            'success'         => true,
            'business_date'   => $eventDate,
            'spun_today'      => $spun_today,
            'not_spun_today'  => $not_spun_today,
            'not_spun_total'  => $not_spun_total,
            'not_spun_offset' => $offsetB,
            'not_spun_limit'  => $limitB,
            'not_spun_query'  => $qB,
        ]);
        break;

    // ══════════════════════════════════════════════════════════════════
    // 3C. USER STATS — Métricas del día (participación + desglose)
    // ══════════════════════════════════════════════════════════════════
    case 'get_user_stats':
        $totals = $conn->query("SELECT COUNT(*) AS total, SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) AS verified FROM users");
        $trow = $totals ? $totals->fetch_assoc() : ['total' => 0, 'verified' => 0];
        if ($totals) $totals->free();
        $total_users    = (int)($trow['total'] ?? 0);
        $verified_users = (int)($trow['verified'] ?? 0);

        // Activos = login en los últimos N días (si la columna existe); si no, todos.
        $active_users = $total_users;
        if (gd_has_column($conn, 'users', 'last_login_at')) {
            $days = GD_ACTIVE_DAYS;
            $stmtAct = $conn->prepare("SELECT COUNT(*) AS n FROM users WHERE last_login_at IS NOT NULL AND last_login_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)");
            $stmtAct->bind_param('i', $days);
            $stmtAct->execute();
            $active_users = (int)($stmtAct->get_result()->fetch_assoc()['n'] ?? 0);
            $stmtAct->close();
        }

        // Giros del día (excluye cupones de sorteo) + usuarios distintos.
        $stmtT = $conn->prepare("
            SELECT COUNT(*) AS spins, COUNT(DISTINCT user_id) AS users,
                   AVG(discount_percent) AS avg_percent, MAX(discount_percent) AS max_percent,
                   SUM(CASE WHEN is_flash_prize = 1 THEN 1 ELSE 0 END) AS flash_prizes
            FROM daily_discounts
            WHERE created_at >= ? AND created_at < ?
              AND COALESCE(spin_source,'') <> 'raffle_prize'
        ");
        $stmtT->bind_param('ss', $windowStart, $windowEnd);
        $stmtT->execute();
        $t = $stmtT->get_result()->fetch_assoc() ?: [];
        $stmtT->close();
        $spins_today      = (int)($t['spins'] ?? 0);
        $spun_users_today = (int)($t['users'] ?? 0);

        // Activos que ya giraron hoy (intersección real, no aproximación).
        $active_spun = $spun_users_today;
        if ($active_users !== $total_users) {
            $days = GD_ACTIVE_DAYS;
            $stmtAS = $conn->prepare("
                SELECT COUNT(DISTINCT dd.user_id) AS n
                FROM daily_discounts dd
                INNER JOIN users u ON u.id = dd.user_id
                WHERE dd.created_at >= ? AND dd.created_at < ?
                  AND COALESCE(dd.spin_source,'') <> 'raffle_prize'
                  AND u.last_login_at IS NOT NULL AND u.last_login_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
            ");
            $stmtAS->bind_param('ssi', $windowStart, $windowEnd, $days);
            $stmtAS->execute();
            $active_spun = (int)($stmtAS->get_result()->fetch_assoc()['n'] ?? 0);
            $stmtAS->close();
        }

        // Desglose por modo / filtro.
        $labels = [];
        foreach (DailySpinFilters::resolve($conn, DailySpinFilters::loadShopEntries()) as $f) {
            $labels[$f['key']] = $f['label'];
        }
        $stmtM = $conn->prepare("
            SELECT spin_mode, filter_key, COUNT(*) AS n, AVG(discount_percent) AS avg_percent
            FROM daily_discounts
            WHERE created_at >= ? AND created_at < ?
              AND COALESCE(spin_source,'') <> 'raffle_prize'
            GROUP BY spin_mode, filter_key
            ORDER BY n DESC
        ");
        $stmtM->bind_param('ss', $windowStart, $windowEnd);
        $stmtM->execute();
        $resM = $stmtM->get_result();
        $by_pool = [];
        while ($row = $resM->fetch_assoc()) {
            $n = (int)$row['n'];
            $by_pool[] = [
                'mode'        => $row['spin_mode'],
                'filter'      => $row['filter_key'],
                'label'       => DailySpinFilters::describeSpin($row['spin_mode'], $row['filter_key'], $labels),
                'count'       => $n,
                'share'       => $spins_today > 0 ? round($n * 100 / $spins_today, 1) : 0,
                'avg_percent' => round((float)$row['avg_percent'], 1),
            ];
        }
        $stmtM->close();

        // Desglose por origen (gratis / pagado / bonus).
        $stmtS = $conn->prepare("
            SELECT spin_source, COUNT(*) AS n
            FROM daily_discounts
            WHERE created_at >= ? AND created_at < ?
              AND COALESCE(spin_source,'') <> 'raffle_prize'
            GROUP BY spin_source
            ORDER BY n DESC
        ");
        $stmtS->bind_param('ss', $windowStart, $windowEnd);
        $stmtS->execute();
        $resS = $stmtS->get_result();
        $by_source = [];
        while ($row = $resS->fetch_assoc()) {
            $meta = dd_source_meta($row['spin_source']);
            $by_source[] = ['source' => $meta['source'], 'label' => $meta['label'], 'count' => (int)$row['n']];
        }
        $stmtS->close();

        $pct = static fn(int $part, int $base): float => $base > 0 ? round($part * 100 / $base, 1) : 0.0;

        echo json_encode([
            'success'          => true,
            'business_date'    => $eventDate,
            'window_end_utc'   => $schedule->windowEndIso(),
            'server_time_utc'  => $schedule->nowIso(),
            'active_days'      => GD_ACTIVE_DAYS,
            'users' => [
                'total'             => $total_users,
                'verified'          => $verified_users,
                'active'            => $active_users,
                'spun_today'        => $spun_users_today,
                'pending'           => max(0, $total_users - $spun_users_today),
                'active_spun'       => $active_spun,
                'active_pending'    => max(0, $active_users - $active_spun),
                'active_spun_pct'   => $pct($active_spun, $active_users),
                'active_pending_pct'=> $pct(max(0, $active_users - $active_spun), $active_users),
                'total_spun_pct'    => $pct($spun_users_today, $total_users),
            ],
            'spins' => [
                'total'        => $spins_today,
                'avg_percent'  => round((float)($t['avg_percent'] ?? 0), 1),
                'max_percent'  => (int)($t['max_percent'] ?? 0),
                'flash_prizes' => (int)($t['flash_prizes'] ?? 0),
                'per_user'     => $spun_users_today > 0 ? round($spins_today / $spun_users_today, 2) : 0,
                'by_pool'      => $by_pool,
                'by_source'    => $by_source,
            ],
        ]);
        break;

    // ══════════════════════════════════════════════════════════════════
    // 4. GET USER DETAILS — Datos profundos + historial de giros
    // ══════════════════════════════════════════════════════════════════
    case 'get_user_details':
        $user_id = intval($_POST['user_id'] ?? 0);
        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de usuario inválido.']);
            break;
        }

        // Info del usuario
        $stmt = $conn->prepare("
            SELECT u.id, u.game_username, u.whatsapp_phone_e164, u.bonus_spins,
                   a.filename AS avatar_filename,
                   COALESCE(c.balance, 0) AS coin_balance
            FROM users u
            LEFT JOIN avatars a ON a.id = u.avatar_id
            LEFT JOIN coins c ON c.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado.']);
            break;
        }

        // Historial de descuentos / giros. `status` es el estado EFECTIVO (un
        // 'active' vencido se reporta 'expired', igual que en la app) y las
        // fechas viajan en ISO-8601 UTC para que el panel las muestre en su zona.
        $stmt2 = $conn->prepare("
            SELECT id, item_name, item_image_url, discount_percent, spin_source, spin_mode, filter_key, status, created_at, expires_at
            FROM daily_discounts
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt2->bind_param('i', $user_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $now = $schedule->nowUtc();
        $history = [];
        while ($row = $res2->fetch_assoc()) {
            $source = dd_source_meta($row['spin_source']);
            $history[] = [
                'id'               => (int)$row['id'],
                'item_name'        => $row['item_name'],
                'item_image_url'   => $row['item_image_url'],
                'discount_percent' => (int)$row['discount_percent'],
                'spin_source'      => $source['source'],
                'source_label'     => $source['label'],
                'pool_label'       => DailySpinFilters::describeSpin($row['spin_mode'], $row['filter_key']),
                'status'           => dd_effective_status((string)$row['status'], $row['expires_at'], $now),
                'stored_status'    => $row['status'],
                'created_at'       => UtcTime::sqlToIso($row['created_at']),
                'expires_at'       => UtcTime::sqlToIso($row['expires_at']),
            ];
        }
        $stmt2->close();

        echo json_encode(['success' => true, 'user' => $user, 'history' => $history, 'server_time_utc' => $schedule->nowIso()]);
        break;

    // ══════════════════════════════════════════════════════════════════
    // 5. UPDATE SPINS — Añadir o quitar bonus_spins
    // ══════════════════════════════════════════════════════════════════
    case 'update_spins':
        $user_id = intval($_POST['user_id'] ?? 0);
        $delta   = intval($_POST['delta'] ?? 0);

        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de usuario inválido.']);
            break;
        }
        if ($delta === 0) {
            echo json_encode(['success' => false, 'error' => 'El cambio no puede ser 0.']);
            break;
        }

        // Obtener spins actuales
        $stmt = $conn->prepare("SELECT bonus_spins FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado.']);
            break;
        }

        $new_spins = max(0, intval($row['bonus_spins']) + $delta);
        $upd = $conn->prepare("UPDATE users SET bonus_spins = ? WHERE id = ?");
        $upd->bind_param('ii', $new_spins, $user_id);
        $upd->execute();
        $upd->close();

        echo json_encode(['success' => true, 'new_bonus_spins' => $new_spins]);
        break;

    // ══════════════════════════════════════════════════════════════════
    // 6. REACTIVATE DISCOUNT — Reactivar un descuento expirado/usado
    // ══════════════════════════════════════════════════════════════════
    case 'reactivate_discount':
        $discount_id = intval($_POST['discount_id'] ?? 0);
        if ($discount_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de descuento inválido.']);
            break;
        }

        // Verificar que exista
        $stmt = $conn->prepare("SELECT id, status, expires_at FROM daily_discounts WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $discount_id);
        $stmt->execute();
        $disc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$disc) {
            echo json_encode(['success' => false, 'error' => 'Descuento no encontrado.']);
            break;
        }

        // Un 'active' ya vencido (status aún no rotado) sí puede reactivarse.
        if (dd_effective_status((string)$disc['status'], $disc['expires_at'], $schedule->nowUtc()) === 'active') {
            echo json_encode(['success' => false, 'error' => 'El descuento ya está activo.']);
            break;
        }

        // Reactivar: status = active, expires_at = ahora (UTC) + 24h
        $upd = $conn->prepare("UPDATE daily_discounts SET status = 'active', expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR) WHERE id = ?");
        $upd->bind_param('i', $discount_id);
        $upd->execute();
        $upd->close();

        echo json_encode(['success' => true, 'message' => 'Descuento reactivado por 24 horas.']);
        break;

    // ══════════════════════════════════════════════════════════════════
    // DEFAULT — Acción desconocida
    // ══════════════════════════════════════════════════════════════════
    default:
        echo json_encode(['success' => false, 'error' => 'Acción no reconocida: ' . htmlspecialchars($action)]);
        break;
}

$conn->close();

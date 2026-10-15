<?php
// /wabo/economia/economia_lib.php
// Helpers compartidos por la sección Economía. Solo clasifican y leen datos;
// las reglas de cashback de cada módulo siguen viviendo en su propio módulo.

/** Columna opcional `note` en coin_transactions para guardar el motivo de los ajustes manuales. */
function eco_ensure_schema(mysqli $conn): void {
    if (!eco_has_column($conn, 'coin_transactions', 'note')) {
        try { $conn->query("ALTER TABLE coin_transactions ADD COLUMN note VARCHAR(255) NULL DEFAULT NULL"); } catch (Throwable $e) {}
        eco_has_column($conn, 'coin_transactions', 'note', true);
    }
}

function eco_has_column(mysqli $conn, string $table, string $column, bool $refresh = false): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if ($refresh || !array_key_exists($key, $cache)) {
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
        $cache[$key] = ($res && $res->num_rows > 0);
    }
    return $cache[$key];
}

/**
 * Expresión SQL que clasifica cada transacción en un origen:
 * manual (admin) | tienda | sorteos | giro | videos | otros
 */
function eco_category_sql(string $a = 'ct'): string {
    return "CASE
        WHEN $a.reference_type = 'admin_id' OR $a.reason = 'admin_adjust' OR $a.reference_type = 'deleted_reversal' THEN 'manual'
        WHEN $a.reason IN ('cashback','spend','refund') THEN 'tienda'
        WHEN $a.reason LIKE 'raffle_%' THEN 'sorteos'
        WHEN $a.reason = 'daily_spin' THEN 'giro'
        WHEN $a.reason LIKE 'video_%' THEN 'videos'
        ELSE 'otros' END";
}

function eco_category_label(string $cat): string {
    static $map = [
        'manual' => 'Ajuste manual (admin)',
        'tienda' => 'Tienda / Pedidos',
        'sorteos' => 'Sorteos Flash',
        'giro' => 'Giro Diario',
        'videos' => 'Vídeos de Comunidad',
        'otros' => 'Otros',
    ];
    return $map[$cat] ?? ucfirst($cat);
}

function eco_reason_labels(): array {
    return [
        'spend' => 'Pago de pedido con Coins',
        'cashback' => 'Cashback de pedido',
        'refund' => 'Reembolso de pedido',
        'admin_adjust' => 'Ajuste manual',
        'daily_spin' => 'Tirada pagada (Giro Diario)',
        'raffle_entry' => 'Inscripción a sorteo',
        'raffle_win' => 'Premio de sorteo',
        'raffle_cashback' => 'Cashback de sorteo',
        'raffle_prize_coins' => 'Premio en Coins (sorteo)',
        'raffle_early_bird' => 'Bono Early Bird',
        'raffle_double_bonus' => 'Bono Doble o Nada',
        'raffle_expel_refund' => 'Devolución por expulsión',
        'raffle_unexpel_recharge' => 'Recobro por reingreso',
        'video_reward' => 'Recompensa por vídeo aprobado',
        'video_upload_reward' => 'Recompensa por vídeo aprobado',
        'video_report_reward' => 'Recompensa por reporte de vídeo',
    ];
}

function eco_reason_label(?string $reason, string $category = ''): string {
    $reason = (string)$reason;
    $labels = eco_reason_labels();
    if (isset($labels[$reason])) return $labels[$reason];
    // Los ajustes antiguos desde Usuarios guardaban la nota libre en `reason`.
    if ($category === 'manual') return $reason !== '' ? $reason : 'Ajuste manual';
    return $reason !== '' ? $reason : '(sin motivo)';
}

/** Periodo [from, to) a partir de ?from=YYYY-MM-DD&to=YYYY-MM-DD (por defecto últimos 30 días). */
function eco_period_from_request(): array {
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $valid = static function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; };
    if (!$valid($to)) $to = date('Y-m-d');
    if (!$valid($from)) $from = date('Y-m-d', strtotime($to . ' -29 days'));
    if (strtotime($from) > strtotime($to)) { [$from, $to] = [$to, $from]; }
    $toExclusive = date('Y-m-d', strtotime($to . ' +1 day'));
    return [$from . ' 00:00:00', $toExclusive . ' 00:00:00'];
}

function eco_thresholds_from_request(): array {
    $raw = $_GET['thresholds'] ?? '';
    $list = [];
    foreach (explode(',', (string)$raw) as $t) {
        $t = trim($t);
        if ($t !== '' && is_numeric($t) && (float)$t > 0) $list[] = (float)$t;
    }
    if (!$list) $list = [1, 5, 10, 20, 50, 100, 200, 500];
    $list = array_values(array_unique($list));
    sort($list);
    return array_slice($list, 0, 12);
}

/**
 * Mediana de saldo. Con $allUsers=true cuenta a todos los registrados (los sin fila en coins valen 0);
 * si no, solo a quienes tienen saldo > 0.
 */
function eco_median_balance(mysqli $conn, int $n, bool $allUsers = false): float {
    if ($n <= 0) return 0.0;
    if ($allUsers) {
        $sql = "SELECT COALESCE(c.balance,0) AS b FROM users u LEFT JOIN coins c ON c.user_id = u.id ORDER BY b ASC LIMIT 2 OFFSET ?";
    } else {
        $sql = "SELECT balance AS b FROM coins WHERE balance > 0 ORDER BY b ASC LIMIT 2 OFFSET ?";
    }
    $offset = intdiv($n - 1, 2);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $offset);
    $stmt->execute();
    $vals = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $vals[] = (float)$r['b'];
    $stmt->close();
    if (!$vals) return 0.0;
    if ($n % 2 === 1 || count($vals) < 2) return round($vals[0], 2);
    return round(($vals[0] + $vals[1]) / 2, 2);
}

/** Umbral destacado del indicador "X usuarios tienen más de Y Coins" (por defecto 10). */
function eco_focus_from_request(): float {
    $f = $_GET['focus'] ?? '';
    return ($f !== '' && is_numeric($f) && (float)$f >= 0) ? (float)$f : 10.0;
}

/** Cuántos usuarios tienen saldo >= cada umbral y cuántos Coins concentran. */
function eco_distribution(mysqli $conn, array $thresholds, int $usersTotal, float $focus = 10.0): array {
    $selects = ["SUM(balance > $focus) AS f_users", "COALESCE(SUM(CASE WHEN balance > $focus THEN balance ELSE 0 END),0) AS f_coins"];
    foreach ($thresholds as $i => $t) {
        $tv = (float)$t;
        $selects[] = "SUM(balance >= $tv) AS c_$i, COALESCE(SUM(CASE WHEN balance >= $tv THEN balance ELSE 0 END),0) AS s_$i";
    }
    $row = $conn->query("SELECT SUM(balance > 0) AS holders, COALESCE(SUM(balance),0) AS total, " . implode(', ', $selects) . " FROM coins")->fetch_assoc();
    $holders = (int)($row['holders'] ?? 0);
    $total = (float)($row['total'] ?? 0);
    $out = [];
    foreach ($thresholds as $i => $t) {
        $c = (int)($row["c_$i"] ?? 0);
        $s = (float)($row["s_$i"] ?? 0);
        $out[] = [
            'threshold' => (float)$t,
            'users' => $c,
            'pct_holders' => $holders > 0 ? round($c * 100 / $holders, 1) : 0,
            'pct_users' => $usersTotal > 0 ? round($c * 100 / $usersTotal, 1) : 0,
            'coins_held' => round($s, 2),
            'pct_coins' => $total > 0 ? round($s * 100 / $total, 1) : 0,
            'fee_pot' => round($c * (float)$t, 2), // bote teórico si todos pagaran esa cuota
        ];
    }
    // Tramos exclusivos (0, 0-5, 5-20, ...) para la lectura rápida
    $bands = [];
    $prev = 0.0;
    $bandDefs = array_merge([0.0], $thresholds);
    for ($i = 0; $i < count($bandDefs); $i++) {
        $lo = $bandDefs[$i];
        $hi = $bandDefs[$i + 1] ?? null;
        $bands[] = ['from' => $lo, 'to' => $hi];
    }
    $bandSel = [];
    foreach ($bands as $i => $b) {
        $lo = (float)$b['from'];
        $cond = $b['to'] === null ? "balance >= $lo" : ($lo == 0.0 ? "balance > 0 AND balance < " . (float)$b['to'] : "balance >= $lo AND balance < " . (float)$b['to']);
        $bandSel[] = "SUM($cond) AS b_$i";
    }
    $brow = $conn->query("SELECT " . implode(', ', $bandSel) . " FROM coins")->fetch_assoc();
    foreach ($bands as $i => &$b) { $b['users'] = (int)($brow["b_$i"] ?? 0); }
    unset($b);
    $zero = max(0, $usersTotal - $holders);
    $fu = (int)($row['f_users'] ?? 0);
    $fc = (float)($row['f_coins'] ?? 0);
    $focusOut = [
        'threshold' => $focus,
        'users' => $fu,
        'pct_users' => $usersTotal > 0 ? round($fu * 100 / $usersTotal, 1) : 0,
        'pct_holders' => $holders > 0 ? round($fu * 100 / $holders, 1) : 0,
        'coins_held' => round($fc, 2),
        'pct_coins' => $total > 0 ? round($fc * 100 / $total, 1) : 0,
    ];

    return ['holders' => $holders, 'users_total' => $usersTotal, 'zero_balance_users' => $zero, 'total' => round($total, 2), 'focus' => $focusOut, 'thresholds' => $out, 'bands' => $bands];
}

function eco_setting(mysqli $conn, string $key): ?string {
    $stmt = $conn->prepare("SELECT value FROM site_settings WHERE key_name = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['value'] : null;
}

function eco_config_tienda(mysqli $conn, string $clave): ?string {
    $stmt = $conn->prepare("SELECT valor FROM configuracion_tienda WHERE clave = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $clave);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['valor'] : null;
}

/**
 * Foto de solo lectura de los parámetros económicos existentes, agrupados por el
 * módulo que los gobierna (con el enlace para editarlos allí). No escribe nada.
 */
function eco_rules_snapshot(mysqli $conn): array {
    $groups = [];

    // --- Valor de la moneda y canje (Ajustes de Tienda / Ajustes Avanzados) ---
    $unitCoins = eco_setting($conn, 'coins_unit_coins');
    $unitMoney = eco_setting($conn, 'coins_unit_money');
    $rate = ($unitCoins !== null && (float)$unitCoins > 0 && $unitMoney !== null) ? round((float)$unitMoney / (float)$unitCoins, 4) : null;
    $groups[] = [
        'key' => 'moneda', 'title' => 'Valor de la moneda y canje', 'icon' => 'fa-coins',
        'edit_href' => 'configuracion_usuarios/gestionar_configuracion_usuarios.php', 'edit_label' => 'Ajustes Avanzados (site_settings)',
        'items' => [
            ['label' => 'Equivalencia', 'value' => ($unitCoins !== null && $unitMoney !== null) ? "$unitCoins Coins = $unitMoney" : null, 'hint' => 'coins_unit_coins / coins_unit_money'],
            ['label' => 'Valor de 1 Coin', 'value' => $rate !== null ? (string)$rate : null, 'hint' => 'coins_unit_money ÷ coins_unit_coins'],
            ['label' => 'Mínimo para canjear', 'value' => eco_setting($conn, 'redeem_min_coins'), 'hint' => 'redeem_min_coins'],
            ['label' => 'Múltiplo de canje', 'value' => eco_setting($conn, 'redeem_step_coins'), 'hint' => 'redeem_step_coins'],
        ],
    ];

    // --- Cashback de pedidos (Ajustes Avanzados + precio en Ajustes de Tienda) ---
    $cbBase = eco_setting($conn, 'cashback_base');
    $cbVer = eco_setting($conn, 'cashback_percentage_verified');
    $p100 = eco_config_tienda($conn, 'precio_por_100_pavos');
    $fmtPct = static function ($v) { return $v === null ? null : (is_numeric($v) ? ((float)$v <= 1 ? round((float)$v * 100, 2) : (float)$v) . ' %' : $v); };
    $groups[] = [
        'key' => 'pedidos', 'title' => 'Cashback de pedidos', 'icon' => 'fa-shopping-cart',
        'edit_href' => 'configuracion_usuarios/gestionar_configuracion_usuarios.php', 'edit_label' => 'Ajustes Avanzados (site_settings)',
        'items' => [
            ['label' => 'Cashback base', 'value' => $fmtPct($cbBase), 'hint' => 'cashback_base · usuarios no verificados / regalo'],
            ['label' => 'Cashback verificado', 'value' => $fmtPct($cbVer), 'hint' => 'cashback_percentage_verified · verificado comprando para sí'],
            ['label' => 'Precio por 100 paVos', 'value' => $p100, 'hint' => 'configuracion_tienda.precio_por_100_pavos · convierte el cashback en Coins'],
            ['label' => 'Gasto mínimo para verificación', 'value' => eco_setting($conn, 'min_total_spent_for_verification'), 'hint' => 'min_total_spent_for_verification'],
        ],
        'note' => 'Coins de pedido = (total × tasa) ÷ precio_por_100 × 100. Se acreditan al completar el pedido y se revierten al eliminarlo.',
    ];

    // --- Giro Diario (su propio módulo) ---
    $spin = null;
    try {
        $r = $conn->query("SELECT * FROM daily_spin_config WHERE event_date = CURDATE() LIMIT 1");
        if ($r) $spin = $r->fetch_assoc();
    } catch (Throwable $e) {}
    $groups[] = [
        'key' => 'giro', 'title' => 'Giro Diario', 'icon' => 'fa-dice',
        'edit_href' => 'giro_diario/gestionar_girodiario.php', 'edit_label' => 'Giro Diario',
        'items' => [
            ['label' => 'Tiradas pagadas hoy', 'value' => $spin ? ((int)($spin['paid_spins_enabled'] ?? 0) ? 'Activadas' : 'Desactivadas') : null, 'hint' => 'daily_spin_config.paid_spins_enabled (config de hoy)'],
            ['label' => 'Precio tirada pagada', 'value' => $spin && isset($spin['paid_spin_price']) ? $spin['paid_spin_price'] . ' Coins' : null, 'hint' => 'daily_spin_config.paid_spin_price'],
            ['label' => 'Límite tiradas pagadas', 'value' => $spin && isset($spin['paid_spins_limit']) ? (string)$spin['paid_spins_limit'] : null, 'hint' => 'daily_spin_config.paid_spins_limit'],
            ['label' => 'Modo del día', 'value' => $spin['mode'] ?? null, 'hint' => 'daily_spin_config.mode'],
        ],
        'note' => 'El Giro Diario solo consume Coins (tiradas pagadas). Los premios del giro son descuentos, no Coins.',
    ];

    // --- Sorteos Flash (reglas por sorteo, en su propio módulo) ---
    $raf = null;
    try {
        $r = $conn->query("SELECT COUNT(*) AS total,
                SUM(status NOT IN ('completed','cancelled')) AS live,
                SUM(entry_cost > 0) AS with_fee,
                COALESCE(AVG(NULLIF(entry_cost,0)),0) AS avg_fee,
                COALESCE(MAX(entry_cost),0) AS max_fee,
                SUM(allow_cashback = 1) AS with_cashback,
                COALESCE(AVG(NULLIF(cashback_percent,0)),0) AS avg_cashback
            FROM raffles");
        if ($r) $raf = $r->fetch_assoc();
    } catch (Throwable $e) {}
    $groups[] = [
        'key' => 'sorteos', 'title' => 'Sorteos Flash', 'icon' => 'fa-bolt',
        'edit_href' => 'sorteos_flash/gestionar_sorteos.php', 'edit_label' => 'Sorteos Flash (cada sorteo tiene sus reglas)',
        'items' => [
            ['label' => 'Sorteos creados / en curso', 'value' => $raf ? ((int)$raf['total'] . ' / ' . (int)$raf['live']) : null, 'hint' => 'raffles'],
            ['label' => 'Sorteos con cuota de inscripción', 'value' => $raf ? (string)(int)$raf['with_fee'] : null, 'hint' => 'raffles.entry_cost > 0'],
            ['label' => 'Cuota media / máxima', 'value' => $raf ? (round((float)$raf['avg_fee'], 2) . ' / ' . round((float)$raf['max_fee'], 2) . ' Coins') : null, 'hint' => 'raffles.entry_cost'],
            ['label' => 'Sorteos con cashback · % medio', 'value' => $raf ? ((int)$raf['with_cashback'] . ' · ' . round((float)$raf['avg_cashback'], 1) . ' %') : null, 'hint' => 'raffles.allow_cashback / cashback_percent'],
        ],
        'note' => 'Cuota, cashback, Early Bird y Doble o Nada se configuran sorteo a sorteo desde Sorteos Flash.',
    ];

    // --- Vídeos de Comunidad (su propio módulo) ---
    $groups[] = [
        'key' => 'videos', 'title' => 'Vídeos de Comunidad', 'icon' => 'fa-photo-film',
        'edit_href' => 'videos_comunidad/gestionar_videos_comunidad.php', 'edit_label' => 'Pistas Comunidad',
        'items' => [
            ['label' => 'Recompensa por vídeo aprobado', 'value' => eco_setting($conn, 'video_upload_reward'), 'hint' => 'video_upload_reward'],
            ['label' => 'Recompensa por reporte válido', 'value' => eco_setting($conn, 'video_report_reward'), 'hint' => 'video_report_reward'],
        ],
    ];

    // --- Taxonomía de motivos (para leer el libro mayor) ---
    $reasons = [];
    foreach (eco_reason_labels() as $code => $label) {
        $cat = 'otros';
        if ($code === 'admin_adjust') $cat = 'manual';
        elseif (in_array($code, ['cashback', 'spend', 'refund'], true)) $cat = 'tienda';
        elseif (strpos($code, 'raffle_') === 0) $cat = 'sorteos';
        elseif ($code === 'daily_spin') $cat = 'giro';
        elseif (strpos($code, 'video_') === 0) $cat = 'videos';
        $reasons[] = ['code' => $code, 'label' => $label, 'category' => $cat, 'category_label' => eco_category_label($cat)];
    }

    return ['groups' => $groups, 'reasons' => $reasons];
}

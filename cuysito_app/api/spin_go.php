<?php
// public_html/api/spin_go.php
// ---------------------------------------------------------
// VERSIÓN ROBUSTA: AUTO-UNWRAPPER DE JSON
// ---------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
ini_set('display_errors', 0); 
error_reporting(E_ALL);

session_start();

function _outputJson($data) {
    $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
}

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    _outputJson(['success' => false, 'message' => 'Auth required', 'is_logged' => false]);
    exit;
}

// LOGGING
function writeLog($msg) {
    $file = __DIR__ . '/spin_debug.txt';
    $date = date('Y-m-d H:i:s');
    file_put_contents($file, "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

writeLog("--------------------------------------------------");
writeLog("INICIO DE SOLICITUD DE GIRO (v3.0 Auto-Unwrap)");

require_once 'db_connect.php';
require_once dirname(__DIR__) . '/core/UtcTime.php';
require_once dirname(__DIR__) . '/core/DailySpinSchedule.php';
require_once dirname(__DIR__) . '/core/DailySpinFilters.php';
require_once dirname(__DIR__) . '/core/DailySpinEligibility.php';

$response = ['success' => false, 'message' => '', 'data' => null];
$COST_NORMAL = 50;
$COST_FILTERED = 150;
// Vigencia del cupon: 24 h reales desde el giro, acotada por la salida del objeto de la tienda.
$DISCOUNT_TTL_SECONDS = 24 * 3600;

/**
 * Instante (Unix) en que el objeto abandona la tienda segun el snapshot canonico
 * (offerDates.out, con outDate como respaldo). null si no hay fecha valida.
 */
function spin_item_out_timestamp(array $item): ?int {
    $out = $item['offerDates']['out'] ?? ($item['outDate'] ?? null);
    if (!is_string($out) || trim($out) === '') return null;
    $ts = strtotime($out);
    return $ts === false ? null : $ts;
}

/**
 * Expiracion efectiva del cupon: min(giro + TTL, salida del objeto) cuando la salida es
 * futura y anterior al TTL. Devuelve ['ts' => int, 'reason' => 'spin_24h'|'shop_exit', 'out_ts' => ?int].
 */
function spin_discount_expiry(int $spinTs, int $ttlSeconds, array $item): array {
    $ts = $spinTs + $ttlSeconds;
    $reason = 'spin_24h';
    $outTs = spin_item_out_timestamp($item);
    if ($outTs !== null && $outTs > $spinTs && $outTs < $ts) {
        $ts = $outTs;
        $reason = 'shop_exit';
    }
    return ['ts' => $ts, 'reason' => $reason, 'out_ts' => $outTs];
}

$user_id = (int)$_SESSION['user_id'];
$spin_type = $_POST['type'] ?? 'normal';
$expect_free = isset($_POST['expect_free']) && (string)$_POST['expect_free'] === '1';
$spin_mode   = DailySpinFilters::normalizeMode(isset($_POST['mode']) ? (string)$_POST['mode'] : null);
$filter_key  = isset($_POST['filter']) ? DailySpinFilters::keyFor((string)$_POST['filter']) : '';
if (!isset($_POST['filter']) || trim((string)$_POST['filter']) === '') $filter_key = '';

if (!in_array($spin_type, ['normal', 'filtered'], true)) {
    _outputJson(['success' => false, 'message' => 'Tipo de giro no válido.']);
    exit;
}

try {
    // 0. ESQUEMA (DDL fuera de la transacción: en MySQL provoca commit implícito)
    DailySpinFilters::ensureSchema($conn);

    // 1. TRANSACCIÓN
    $conn->begin_transaction();

    // 2. DATOS USUARIO
    $sqlUser = "SELECT u.id, u.game_username, u.is_verified, u.bonus_spins, COALESCE(c.balance, 0) as coins_balance
                FROM users u LEFT JOIN coins c ON u.id = c.user_id WHERE u.id = ? FOR UPDATE";
    $stmt = $conn->prepare($sqlUser);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) throw new Exception("Usuario no encontrado.");

    // 2b. ELEGIBILIDAD (política global del admin). Se evalúa dentro de la transacción,
    // antes de cualquier cobro o consumo: el frontend sólo refleja este veredicto.
    $eligibility = DailySpinEligibility::evaluate(DailySpinEligibility::load($conn), $user);
    if (!$eligibility['eligible']) {
        $conn->rollback();
        writeLog("RECHAZO POR ELEGIBILIDAD: user={$user_id} policy={$eligibility['policy']} reason={$eligibility['reason']}");
        _outputJson([
            'success'     => false,
            'message'     => $eligibility['message'],
            'reason'      => $eligibility['reason'],
            'eligibility' => DailySpinEligibility::publicShape($eligibility),
        ]);
        exit;
    }

    // 3. LOGICA DE COBRO
    // Ventana de negocio 19:00→19:00 America/Lima; todo lo que toca MySQL es UTC.
    $schedule   = DailySpinSchedule::at();
    $now        = $schedule->nowSql();
    $last_reset = $schedule->windowStartSql();
    $next_reset = $schedule->windowEndSql();

    // Configuración del día (para tiros pagados)
    $today_str = $schedule->businessDate();
    $paid_spins_enabled = 1;
    $paid_spins_limit = 999;
    $paid_spin_price = (int)$COST_NORMAL;
    $flash_discount_percent = 25;
    $cfgRow = null;

    $stmtCfg = $conn->prepare("SELECT * FROM daily_spin_config WHERE event_date = ? LIMIT 1");
    if ($stmtCfg) {
        $stmtCfg->bind_param('s', $today_str);
        $stmtCfg->execute();
        $cfgRow = $stmtCfg->get_result()->fetch_assoc();
        if (is_array($cfgRow)) {
            if (isset($cfgRow['paid_spins_enabled'])) $paid_spins_enabled = (int)$cfgRow['paid_spins_enabled'];
            if (isset($cfgRow['paid_spins_limit'])) $paid_spins_limit = (int)$cfgRow['paid_spins_limit'];
            if (isset($cfgRow['paid_spin_price'])) $paid_spin_price = (int)$cfgRow['paid_spin_price'];
            if (isset($cfgRow['flash_offer_percent'])) $flash_discount_percent = (int)$cfgRow['flash_offer_percent'];
        } else {
            $cfgRow = null;
        }
    }

    // Pool de premios según modo (Todo / Sin Pistas) y filtro de categoría. El backend valida
    // el filtro contra la configuración admin y decide el % — el cliente sólo elige.
    $shop_entries = DailySpinFilters::loadShopEntries();
    $resolved_filters = DailySpinFilters::resolve($conn, $shop_entries);
    try {
        $pool = DailySpinFilters::buildPool($shop_entries, $cfgRow, $resolved_filters, $spin_mode, $filter_key);
    } catch (InvalidArgumentException $poolErr) {
        throw new Exception($poolErr->getMessage());
    }
    $normal_discount_percent = (int)$pool['percent'];
    $pool_filter_key = $pool['filter'] ? (string)$pool['filter']['key'] : null;
    writeLog("Pool: mode={$spin_mode} filter=" . ($pool_filter_key ?? '-') . " items=" . count($pool['items']) . " percent={$normal_discount_percent}");

    // Conteo de tiros pagados por monedas en la ventana actual
    $stmtPaidCount = $conn->prepare("SELECT count(*) as total FROM daily_discounts WHERE user_id = ? AND spin_source = 'paid_coins' AND created_at >= ? AND created_at < ?");
    if (!$stmtPaidCount) throw new Exception("Error DB Paid Count: " . $conn->error);
    $stmtPaidCount->bind_param('iss', $user_id, $last_reset, $next_reset);
    $stmtPaidCount->execute();
    $paid_used = (int)($stmtPaidCount->get_result()->fetch_assoc()['total'] ?? 0);

    $stmtCheck = $conn->prepare("SELECT count(*) as total FROM daily_discounts WHERE user_id = ? AND spin_source = 'daily_free' AND created_at >= ?");
    $stmtCheck->bind_param("is", $user_id, $last_reset);
    $stmtCheck->execute();
    $already_spun_free = ($stmtCheck->get_result()->fetch_assoc()['total'] > 0);

    if ($expect_free && ($spin_type !== 'normal' || $already_spun_free)) {
        throw new Exception("Tu giro gratis ya no está disponible. Actualiza la página.");
    }

    $is_free_spin = false;
    $cost_to_deduct = 0;
    $currency_used = 'none';

    if (!$already_spun_free && $spin_type === 'normal') {
        $is_free_spin = true;
        $currency_used = 'daily_free';
    } else {
        if ($user['bonus_spins'] > 0 && $spin_type === 'normal') {
            $currency_used = 'bonus_gift';
            $conn->query("UPDATE users SET bonus_spins = bonus_spins - 1 WHERE id = $user_id");
        } else {
            // Hard check: límite de tiros pagados
            if ((int)$paid_spins_enabled !== 1) throw new Exception("Los tiros pagados no están disponibles. Vuelve mañana.");
            if ($paid_used >= (int)$paid_spins_limit) throw new Exception("LÍMITE ALCANZADO. Vuelve mañana.");

            $cost_to_deduct = ($spin_type === 'filtered') ? $COST_FILTERED : $paid_spin_price;
            if ($user['coins_balance'] < $cost_to_deduct) throw new Exception("Saldo insuficiente.");
            
            $currency_used = 'paid_coins';
            $stmtCoins = $conn->prepare("UPDATE coins SET balance = balance - ? WHERE user_id = ?");
            $stmtCoins->bind_param("di", $cost_to_deduct, $user_id);
            $stmtCoins->execute();
            
            $stmtLog = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, created_at) VALUES (?, ?, 'daily_spin', UTC_TIMESTAMP())");
            $neg_cost = -$cost_to_deduct;
            $stmtLog->bind_param("id", $user_id, $neg_cost);
            $stmtLog->execute();
        }
    }

    // 4. UPDATE FLASH (cupo del día de negocio, no de CURDATE() de la sesión)
    $stmtFlash = $conn->prepare("UPDATE daily_spin_config SET flash_offer_claimed = flash_offer_claimed + 1 WHERE event_date = ? AND flash_offer_enabled = 1 AND flash_offer_claimed < flash_offer_limit");
    if (!$stmtFlash) throw new Exception("Error DB Flash: " . $conn->error);
    $stmtFlash->bind_param('s', $today_str);
    $stmtFlash->execute();
    $is_flash = $stmtFlash->affected_rows > 0 ? 1 : 0;
    $stmtFlash->close();
    // La oferta flash es un plus sobre el % base: nunca deja al usuario peor que su modo/filtro.
    $discount_won = $is_flash ? max($flash_discount_percent, $normal_discount_percent) : $normal_discount_percent;

    // =================================================================================
    // D. SELECCIÓN DEL ÍTEM (pool ya filtrado por modo/categoría)
    // =================================================================================
    $shop_items = $pool['items'];
    writeLog("Items finales cargados: " . count($shop_items));

    // FALLBACK DE EMERGENCIA
    if (empty($shop_items)) {
        writeLog("FALLBACK ACTIVADO: No se pudieron cargar items.");
        $shop_items = [
            [
                'offerId' => 'error_fallback', 
                'displayName' => 'Recompensa Sorpresa', 
                'displayAssets' => [['url' => 'https://fortnite-api.com/images/cosmetics/br/bid_138_celestial/icon.png']], 
                'colors' => ['color1' => 'ff0000', 'color3' => '000000']
            ]
        ];
    }

    // ELEGIR GANADOR
    $winner_item = $shop_items[array_rand($shop_items)];
    
    // MAPEO ROBUSTO
    $w_id   = $winner_item['offerId'] ?? 'unknown_id';
    $w_name = $winner_item['displayName'] ?? 'Nombre Desconocido';
    $w_img = '';
    if (isset($winner_item['displayAssets'][0]['url'])) {
        $w_img = $winner_item['displayAssets'][0]['url'];
    } elseif (isset($winner_item['images']['icon'])) {
        $w_img = $winner_item['images']['icon'];
    }

    // E. VISUAL TAPE
    shuffle($shop_items);
    $winning_index = mt_rand(45, 65);
    $tape_length = max(70, $winning_index + 6);
    $visual_tape = array_slice($shop_items, 0, $tape_length);
    while(count($visual_tape) < $tape_length) { $visual_tape[] = $shop_items[array_rand($shop_items)]; }
    $visual_tape[$winning_index] = $winner_item;

    // F. INSERTAR EN DB — vence 24 h después del giro o antes si el objeto sale de la tienda (UTC)
    $spin_ts    = $schedule->nowUtc()->getTimestamp();
    $expiry     = spin_discount_expiry($spin_ts, $DISCOUNT_TTL_SECONDS, $winner_item);
    $expires_at = UtcTime::toSql(UtcTime::fromTimestamp($expiry['ts']));
    writeLog("Expiración cupón: {$expires_at} UTC (motivo={$expiry['reason']})");
    
    $sqlInsertWin = "INSERT INTO daily_discounts 
        (user_id, epic_nick_snapshot, item_id, item_name, item_image_url, discount_percent, is_flash_prize, spin_source, coins_spent, expires_at, created_at, spin_mode, filter_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
    $stmtWin = $conn->prepare($sqlInsertWin);
    if (!$stmtWin) throw new Exception("Error DB Win: " . $conn->error);
    $spent_int = (int)$cost_to_deduct;
    
    $stmtWin->bind_param("issssiisissss", 
        $user_id, 
        $user['game_username'], 
        $w_id, 
        $w_name, 
        $w_img, 
        $discount_won, 
        $is_flash, 
        $currency_used, 
        $spent_int, 
        $expires_at,
        $now,
        $spin_mode,
        $pool_filter_key
    );
    
    if (!$stmtWin->execute()) throw new Exception("Error al guardar premio.");
    
    $conn->commit();

    $response['success'] = true;
    $response['data'] = [
        'winner' => [
            'item'           => $winner_item,
            'discount'       => $discount_won,
            'is_flash'       => (bool)$is_flash,
            'expires_at_utc' => UtcTime::toIso(UtcTime::fromTimestamp($expiry['ts'])),
            'expires_reason' => $expiry['reason'],
            'shop_exit_utc'  => $expiry['out_ts'] !== null ? UtcTime::toIso(UtcTime::fromTimestamp($expiry['out_ts'])) : null,
            'ends_early'     => $expiry['reason'] === 'shop_exit',
            'mode'           => $spin_mode,
            'filter'         => $pool_filter_key,
            'filter_label'   => $pool['filter'] ? $pool['filter']['label'] : null,
        ],
        'tape' => $visual_tape,
        'winning_index' => $winning_index,
        'user_stats' => [
            'new_balance' => $user['coins_balance'] - $cost_to_deduct, 
            'new_bonus' => ($currency_used == 'bonus_gift') ? $user['bonus_spins'] - 1 : $user['bonus_spins']
        ]
    ];
    $response['message'] = '¡Giro completado!';

} catch (Exception $e) {
    if ($conn->connect_errno === 0) $conn->rollback();
    writeLog("ERROR CRÍTICO: " . $e->getMessage());
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

_outputJson($response);
?>
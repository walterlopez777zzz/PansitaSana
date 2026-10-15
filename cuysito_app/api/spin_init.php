<?php
// public_html/api/spin_init.php
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
session_start();

require_once dirname(__DIR__) . '/core/DailySpinSchedule.php';
require_once dirname(__DIR__) . '/core/DailySpinFilters.php';
require_once dirname(__DIR__) . '/core/DailySpinEligibility.php';

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

$response = [
    'success' => false,
    'is_logged' => false,
    'user_data' => null,
    'spin_config' => null,
    'message' => ''
];

// 2. VERIFICAR LOGIN
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    $response['message'] = 'Usuario no logueado';
    $response['server_time_utc'] = UtcTime::toIso(UtcTime::now());
    _outputJson($response);
    exit;
}

// 1. CONEXIÓN A BASE DE DATOS
require_once 'db_connect.php'; 
$user_id = (int)$_SESSION['user_id'];

// Snapshot temporal único para toda la petición: ventana 19:00→19:00 Lima, valores UTC.
$schedule   = DailySpinSchedule::at();
$today_str  = $schedule->businessDate();
$last_reset = $schedule->windowStartSql();
$next_reset = $schedule->windowEndSql();

try {
    // 3. OBTENER DATOS DEL USUARIO (Versión MySQLi)
    $sqlUser = "
        SELECT 
            u.id, 
            u.game_username as nick, 
            u.bonus_spins, 
            u.is_verified, 
            COALESCE(c.balance, 0) as coins_balance
        FROM users u
        LEFT JOIN coins c ON u.id = c.user_id
        WHERE u.id = ? LIMIT 1
    ";
    
    // Preparar statement MySQLi
    $stmt = $conn->prepare($sqlUser);
    if (!$stmt) throw new Exception("Error DB User: " . $conn->error);
    
    $stmt->bind_param("i", $user_id); // "i" indica que es un integer
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        throw new Exception("Usuario no encontrado.");
    }

    $response['is_logged'] = true;
    
    // Verificación de estado (tinyint 1 o 0)
    $is_verified = ($user['is_verified'] == 1);

    // Elegibilidad según la política global del admin (mismo veredicto que aplicará spin_go).
    $eligibility = DailySpinEligibility::evaluate(DailySpinEligibility::load($conn), $user);

    // 4. VERIFICAR SI YA GIRÓ HOY (TIRO GRATIS) dentro de la ventana actual
    // Buscamos si ya gastó su tiro gratis
    $sqlSpin = "SELECT count(*) as total FROM daily_discounts 
                WHERE user_id = ? 
                AND spin_source = 'daily_free' 
                AND created_at >= ?";
    
    $stmtSpin = $conn->prepare($sqlSpin);
    if (!$stmtSpin) throw new Exception("Error DB Spin: " . $conn->error);

    $stmtSpin->bind_param("is", $user_id, $last_reset); // "is" = integer, string
    $stmtSpin->execute();
    $resSpin = $stmtSpin->get_result();
    $spinLog = $resSpin->fetch_assoc();
    
    $free_spin_available = ($spinLog['total'] == 0);

    // Conteo de tiros pagados por monedas en la ventana actual
    $sqlPaidSpins = "SELECT count(*) as total FROM daily_discounts 
                WHERE user_id = ? 
                AND spin_source = 'paid_coins' 
                AND created_at >= ?
                AND created_at < ?";
    $stmtPaid = $conn->prepare($sqlPaidSpins);
    if (!$stmtPaid) throw new Exception("Error DB Paid Spinning: " . $conn->error);
    $stmtPaid->bind_param("iss", $user_id, $last_reset, $next_reset);
    $stmtPaid->execute();
    $resPaid = $stmtPaid->get_result();
    $paidLog = $resPaid->fetch_assoc();
    $user_paid_spins_count = isset($paidLog['total']) ? (int)$paidLog['total'] : 0;

    // 5. OBTENER CONFIGURACIÓN DEL DÍA
    DailySpinFilters::ensureSchema($conn);
    $sqlConfig = "SELECT * FROM daily_spin_config WHERE event_date = ? LIMIT 1";
    $stmtConfig = $conn->prepare($sqlConfig);
    $stmtConfig->bind_param("s", $today_str);
    $stmtConfig->execute();
    $resConfig = $stmtConfig->get_result();
    $dayConfig = $resConfig->fetch_assoc();

    // Datos por defecto
    $flash_data = [
        'active' => false,
        'claimed' => 0,
        'limit' => 30,
        'percent' => 25
    ];

    $theme_mode = 'global';

    // Defaults para mantener compatibilidad si falta config
    $paid_spins_enabled = 1;
    $paid_spins_limit = 999;
    $paid_spin_price = 50;

    if ($dayConfig) {
        $theme_mode = $dayConfig['mode'];
        if (isset($dayConfig['paid_spins_enabled'])) $paid_spins_enabled = (int)$dayConfig['paid_spins_enabled'];
        if (isset($dayConfig['paid_spins_limit'])) $paid_spins_limit = (int)$dayConfig['paid_spins_limit'];
        if (isset($dayConfig['paid_spin_price'])) $paid_spin_price = (int)$dayConfig['paid_spin_price'];
        if ($dayConfig['flash_offer_enabled'] == 1) {
            $flash_data['active'] = true;
            $flash_data['claimed'] = (int)$dayConfig['flash_offer_claimed'];
            $flash_data['limit'] = (int)$dayConfig['flash_offer_limit'];
            $flash_data['percent'] = (int)$dayConfig['flash_offer_percent'];
        }
    }

    // 6. PREPARAR RESPUESTA FINAL
    $response['success'] = true;
    $response['user_data'] = [
        'nick' => $user['nick'],
        'verified' => $is_verified,
        'eligibility' => DailySpinEligibility::publicShape($eligibility),
        'coins' => (int)$user['coins_balance'],
        'bonus_spins' => (int)$user['bonus_spins']
    ];
    $response['server_time_utc'] = $schedule->nowIso();
    $response['spin_status'] = [
        'free_spin_available' => $free_spin_available,
        'next_reset_utc' => $schedule->windowEndIso(),
        'cost_extra' => $paid_spin_price,
        'cost_filtered' => 150,
        'paid_spins_enabled' => (int)$paid_spins_enabled,
        'paid_spins_limit' => (int)$paid_spins_limit,
        'paid_spin_price' => (int)$paid_spin_price,
        'user_paid_spins_count' => (int)$user_paid_spins_count
    ];
    $response['event_config'] = [
        'mode' => $theme_mode,
        'flash_offer' => $flash_data
    ];

    // 7. CATÁLOGO + FILTROS — la misma fuente que usará spin_go para premiar
    $shop_entries = DailySpinFilters::loadShopEntries();
    $resolved_filters = DailySpinFilters::resolve($conn, $shop_entries);
    $items_total = 0;
    $items_without_tracks = 0;
    foreach ($shop_entries as $entry) {
        if (!is_array($entry)) continue;
        $items_total++;
        if (!DailySpinFilters::isTrackEntry($entry)) $items_without_tracks++;
    }
    $response['spin_config'] = [
        'modes' => [
            DailySpinFilters::MODE_ALL => [
                'percent' => DailySpinFilters::modePercent($dayConfig, DailySpinFilters::MODE_ALL),
                'count'   => $items_total,
            ],
            DailySpinFilters::MODE_NO_TRACKS => [
                'percent' => DailySpinFilters::modePercent($dayConfig, DailySpinFilters::MODE_NO_TRACKS),
                'count'   => $items_without_tracks,
            ],
        ],
        'filters' => DailySpinFilters::publicFilters($resolved_filters),
    ];

    // 8. PREVIEW ITEMS — pool para el idle tape del frontend (con clave de categoría para filtrar en cliente)
    $preview_items = [];
    if (count($shop_entries) > 0) {
        $pool = $shop_entries;
        shuffle($pool);
        foreach ($pool as $pi) {
            if (!is_array($pi)) continue;
            $preview_items[] = [
                'displayName' => $pi['displayName'] ?? 'Item',
                'displayAssets' => isset($pi['displayAssets'][0]['url'])
                    ? [['url' => $pi['displayAssets'][0]['url']]]
                    : [],
                'colors' => [
                    'color1' => $pi['colors']['color1'] ?? null,
                    'color3' => $pi['colors']['color3'] ?? null
                ],
                'typeKey' => DailySpinFilters::entryKey($pi),
                'isTrack' => DailySpinFilters::isTrackEntry($pi),
            ];
        }
    }
    $response['preview_items'] = $preview_items;

} catch (Exception $e) {
    $response['message'] = 'Error interno: ' . $e->getMessage();
}

_outputJson($response);
?>
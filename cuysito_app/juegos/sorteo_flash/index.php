<?php
// juegos/sorteo_flash/index.php
// FRONTEND V5.0: FINAL PERFECT (Clean Slots + Gamble Fix + Fairness Modal)

require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../../wabo/core/db_connect.php';
require_once __DIR__ . '/../../api/raffle_private.php';

if (!isset($_GET['id'])) {
    $renderHub = false;
    try {
        $activeResult = $conn->query("SELECT raffle_id FROM raffles WHERE status IN ('open', 'scheduled', 'locking', 'processing', 'waiting_for_winner') LIMIT 1");
        $hasActiveRaffle = $activeResult && $activeResult->num_rows > 0;

        if (!$hasActiveRaffle) {
            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            $isLoggedInClient = isset($_SESSION['logged_in_client']) && $_SESSION['logged_in_client'] === true;
            $needsRoom = false;

            if ($userId > 0 && $isLoggedInClient) {
                $stmt = $conn->prepare(
                    "SELECT rw.id
                       FROM raffle_winners rw
                      WHERE rw.user_id = ?
                        AND rw.raffle_id = (
                            SELECT r.raffle_id
                              FROM raffles r
                             WHERE r.status = 'completed'
                             ORDER BY COALESCE(r.end_time, r.start_time) DESC, r.raffle_id DESC
                             LIMIT 1
                        )
                        AND (
                            rw.choice IN ('pending', 'gamble_pending')
                            OR rw.prize_state = 'awaiting_selection'
                        )
                      LIMIT 1"
                );
                if ($stmt) {
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $needsRoom = $stmt->get_result()->num_rows > 0;
                    $stmt->close();
                }
            }

            $renderHub = !$needsRoom;
        }
    } catch (Throwable $e) {
        $renderHub = false;
    }
    if (!empty($renderHub)) {
        define('SORTEO_FLASH_HUB_EMBEDDED', true);
        require __DIR__ . '/hub.php';
        exit;
    }
}

header('Content-Type: text/html; charset=UTF-8');

// 1. DETECCIÓN
$raffleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($raffleId === 0) {
    $sql = "SELECT raffle_id FROM raffles WHERE status IN ('open', 'scheduled', 'locking', 'processing', 'waiting_for_winner') ORDER BY start_time ASC LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0)
        $raffleId = $res->fetch_assoc()['raffle_id'];
    else {
        $sql = "SELECT raffle_id FROM raffles WHERE status = 'completed' ORDER BY end_time DESC LIMIT 1";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0)
            $raffleId = $res->fetch_assoc()['raffle_id'];
    }
}
if ($raffleId === 0)
    die("<div style='color:white;text-align:center;padding:50px;font-family:sans-serif'>No hay sorteos activos.</div>");

// 2. DATOS
$stmt = $conn->prepare("SELECT title, status, main_animation, gamble_animation, execution_mode, prize_config, cashback_percent, current_pot, winners_count, " . rp_private_column_sql($conn) . " FROM raffles WHERE raffle_id = ?");
$stmt->bind_param("i", $raffleId);
$stmt->execute();
$raffleData = $stmt->get_result()->fetch_assoc();
$raffleTitle = trim($raffleData['title'] ?? '');
$isPrivateRaffle = rp_is_private($raffleData ?: []);
$pageTitle = $raffleTitle !== '' ? ($raffleTitle . ' · Sorteo Flash') : 'Sorteo Flash';
$mainAnim = $raffleData['main_animation'] ?? 'roulette';
$gambleAnim = $raffleData['gamble_animation'] ?? 'coin';
$raffleMode = $raffleData['execution_mode'] ?? 'standard_queue'; // <--- AGREGADO
// Fuera del lobby (sorteo bloqueándose, en vivo o terminado) la Sala de Espera
// del HTML estático sería una foto vieja: se cubre con un velo hasta que el
// primer poll enruta a la fase real (body.sf-booting, ver _sfLiftBootVeil).
$raffleStatus = (string)($raffleData['status'] ?? 'open');
$bootVeil = !in_array($raffleStatus, ['open', 'upcoming'], true);
$prizeConfig = json_decode($raffleData['prize_config'] ?? '{}', true);

// --- PRIZE PRICING (read from the master cache via the same DAL the storefront
// uses; no V-Bucks→Soles math here). For skin/product prizes we look up the
// canonical entry by offerId and consume precio_base_soles / precio_oferta_soles
// / tiene_oferta_nuestra exactly like cart_manager.php does. ---
$prizePricing = null;
if (is_array($prizeConfig) && !empty($prizeConfig['id'])) {
    require_once __DIR__ . '/../../core/ShopRepository.php';
    $cacheItem = ShopRepository::findByOfferId((string)$prizeConfig['id']);
    if ($cacheItem !== null) {
        $hasOffer  = (bool)($cacheItem['tiene_oferta_nuestra'] ?? false);
        $baseSoles = isset($cacheItem['precio_base_soles']) ? (float)$cacheItem['precio_base_soles'] : null;
        $offerSoles = isset($cacheItem['precio_oferta_soles']) ? (float)$cacheItem['precio_oferta_soles'] : null;
        $prizePricing = [
            'vbucks'      => isset($cacheItem['vbucks']) ? (int)$cacheItem['vbucks'] : (isset($prizeConfig['vbucks']) ? (int)$prizeConfig['vbucks'] : null),
            'price_base'  => $baseSoles,
            'price_offer' => $offerSoles,
            'has_offer'   => $hasOffer,
            'final_price' => $hasOffer && $offerSoles !== null ? $offerSoles : $baseSoles,
        ];
    }
}

// --- STATIC PRODUCT FALLBACK ---
// Static products ("product" prizes) live in productos_estaticos, NOT in the
// Fortnite shop cache, so the ShopRepository lookup above never finds them.
// Resolve their image (Cloudinary / external URL) and price straight from the
// DB so the prize modal renders correctly even for raffles created before the
// snapshot enrichment fix.
if (is_array($prizeConfig)
    && ($prizeConfig['type'] ?? '') === 'product'
    && !empty($prizeConfig['id'])
    && ctype_digit((string)$prizeConfig['id'])) {
    $needsImage   = empty($prizeConfig['image']);
    $needsPricing = ($prizePricing === null);
    if ($needsImage || $needsPricing) {
        $spStmt = $conn->prepare("SELECT nombre, imagen_url, precio_base, precio_oferta FROM productos_estaticos WHERE id = ? LIMIT 1");
        if ($spStmt) {
            $spId = (int)$prizeConfig['id'];
            $spStmt->bind_param("i", $spId);
            $spStmt->execute();
            $spRow = $spStmt->get_result()->fetch_assoc();
            $spStmt->close();
            if ($spRow) {
                if ($needsImage && !empty($spRow['imagen_url'])) {
                    $prizeConfig['image'] = $spRow['imagen_url'];
                }
                if (empty($prizeConfig['name']) && !empty($spRow['nombre'])) {
                    $prizeConfig['name'] = $spRow['nombre'];
                }
                $spOffer = isset($spRow['precio_oferta']) ? (float)$spRow['precio_oferta'] : 0.0;
                $spBase  = isset($spRow['precio_base']) ? (float)$spRow['precio_base'] : 0.0;
                $spHasOffer = $spOffer > 0;
                $spFinal = $spHasOffer ? $spOffer : $spBase;
                if ($needsPricing && $spFinal > 0) {
                    $prizePricing = [
                        'vbucks'      => null,
                        'price_base'  => $spBase > 0 ? $spBase : null,
                        'price_offer' => $spHasOffer ? $spOffer : null,
                        'has_offer'   => $spHasOffer,
                        'final_price' => $spFinal,
                    ];
                }
            }
        }
    }
}

$cashbackPercent = (int)($raffleData['cashback_percent'] ?? 0);
$currentPot = (float)($raffleData['current_pot'] ?? 0);
$winnersCount = (int)($raffleData['winners_count'] ?? 1);

// --- DOBLE O NADA: bono en Bananos. Mismo criterio que el backend
// (rr_deliver_double_win): el premio se entrega TAL CUAL + su valor real
// equivalente en Bananos. Se expone al cliente para que el texto del modal
// muestre el valor real de la apuesta en tiempo real. ---
$gambleBonusCoins = 0;
if (is_array($prizeConfig)) {
    if (($prizeConfig['type'] ?? '') === 'coins') {
        // Premio en Bananos: el bono del "doble" es la misma cantidad.
        $gambleBonusCoins = (int) round((float)($prizeConfig['amount'] ?? 0));
    } else {
        // Skin / objeto de tienda: su valor nativo está en pavos y 1 pavo = 1
        // Banano, por lo que NO se aplica la conversión fiat soles↔monedas (era
        // el origen del bug: una skin de 1500 pavos mostraba +1615 Bananos).
        $prizeVbucks = 0;
        if ($prizePricing !== null && !empty($prizePricing['vbucks'])) {
            $prizeVbucks = (int)$prizePricing['vbucks'];
        } elseif (!empty($prizeConfig['vbucks'])) {
            $prizeVbucks = (int)$prizeConfig['vbucks'];
        }
        if ($prizeVbucks > 0) {
            $gambleBonusCoins = $prizeVbucks;
        } else {
            // Producto estático (precio sólo en soles) → conversión inversa
            // soles → Bananos con la tasa del sitio (defaults 100 ≈ S/.1.30).
            $baseSolesForGamble = 0.0;
            if ($prizePricing !== null) {
                if (!empty($prizePricing['price_base'])) {
                    $baseSolesForGamble = (float)$prizePricing['price_base'];
                } elseif (!empty($prizePricing['final_price'])) {
                    $baseSolesForGamble = (float)$prizePricing['final_price'];
                }
            }
            if ($baseSolesForGamble > 0) {
                $unitCoins = 100.0;
                $unitMoney = 1.30;
                if ($gbRes = $conn->query("SELECT key_name, value FROM site_settings WHERE key_name IN ('coins_unit_coins', 'coins_unit_money')")) {
                    while ($gbRow = $gbRes->fetch_assoc()) {
                        if ($gbRow['key_name'] === 'coins_unit_coins' && (float)$gbRow['value'] > 0) $unitCoins = (float)$gbRow['value'];
                        if ($gbRow['key_name'] === 'coins_unit_money' && (float)$gbRow['value'] > 0) $unitMoney = (float)$gbRow['value'];
                    }
                }
                $gambleBonusCoins = (int) round(($baseSolesForGamble / $unitMoney) * $unitCoins);
            }
        }
    }
}

// --- VALOR DEL PREMIO POR PUESTO (fuente única: raffle_rewards.php). Es el
// mismo número que usa el servidor para pagar Cashback (valor × %) y el bono
// del Doble o Nada (valor completo), por lo que el modal de pre-elección puede
// mostrar cantidades exactas también con Schema 2 (pools, combos, OFERTA). ---
$prizeValueCoins = [];
try {
    require_once __DIR__ . '/../../api/raffle_core.php';
    require_once __DIR__ . '/../../config/shop_config.php';
    $valCacheFile = ShopSourceConfig::activeSnapshotPath() ?? ShopSourceConfig::liveSnapshotPath();
    $rawPrizeConfig = $raffleData['prize_config'] ?? '{}';
    $normalizedForValue = rr_normalize_prizes($rawPrizeConfig, $winnersCount);
    foreach ($normalizedForValue['positions'] as $posEntry) {
        $pos = (int)($posEntry['position'] ?? 0);
        if ($pos <= 0) continue;
        $posPrize = rr_prize_for_position($rawPrizeConfig, $pos);
        $prizeValueCoins[$pos] = round((float)rr_prize_value_coins($conn, $posPrize, $valCacheFile), 2);
    }
    if (($prizeValueCoins[1] ?? 0) > 0) {
        $gambleBonusCoins = (int) round($prizeValueCoins[1]);
    }
} catch (Throwable $valErr) {
    error_log('[sorteo_flash] prize valuation failed: ' . $valErr->getMessage());
}
// Global audio config (shared across all raffles)
$globalAudioPath = __DIR__ . '/../../config/sorteo_audio_global.json';
$audioConfig = file_exists($globalAudioPath) ? json_decode(file_get_contents($globalAudioPath), true) : null;

$meUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$meUsername = null;
if ($meUserId > 0) {
    $stmtMe = $conn->prepare("SELECT game_username, public_display_name FROM users WHERE id = ? LIMIT 1");
    if ($stmtMe) {
        $stmtMe->bind_param('i', $meUserId);
        $stmtMe->execute();
        $meRow = $stmtMe->get_result()->fetch_assoc();
        $stmtMe->close();
        if ($meRow) {
            $meUsername = !empty($meRow['public_display_name']) ? $meRow['public_display_name'] : $meRow['game_username'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <script>
        // Arranque: la primera lectura de estado sale ya, en paralelo con la
        // descarga del resto de la página. sorteo_flash.js la consume en su
        // primer poll (_sfTakeBootStatus); la acción es CONFIG.apis.status.
        window.__sfBootStatus = fetch('/web.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'a8k', id: <?php echo (int) $raffleId; ?>, nocache: Date.now() })
        }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
    </script>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Bungee&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Changa+One:ital@0;1&display=swap" rel="stylesheet">
    <!-- Tipografía del modal de premio (clon del checkout): Chakra Petch (título) + Inter (precios). -->
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    
    <link rel="stylesheet" href="sorteo_flash.css?v=<?php echo filemtime(__DIR__ . '/sorteo_flash.css'); ?>">
    <link rel="stylesheet" href="sf-dialog.css?v=<?php echo filemtime(__DIR__ . '/sf-dialog.css'); ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .glass {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .custom-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scroll::-webkit-scrollbar-track {
            background: #1f2937;
        }

        .custom-scroll::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 3px;
        }

        /* ============================================================
           CINEMATIC TRANSITION: Decision Recap + DOBLE O NADA
           ============================================================ */
        .cinematic-overlay {
            position: fixed; inset: 0; z-index: 9999;
            background: #000;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .cinematic-overlay.hidden { display: none; }
        .cinematic-scene { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
        .cinematic-scene.hidden { display: none; }

        /* --- SCENE 1: DECISION RECAP --- */
        .cinematic-recap-bg {
            position: absolute; inset: 0;
            background: radial-gradient(ellipse at center, #1a1033 0%, #0a0a0f 70%, #000 100%);
        }
        .cinematic-recap-content {
            position: relative; z-index: 2;
            display: flex; flex-direction: column; align-items: center;
            gap: 40px; padding: 32px 24px 40px; width: 100%; max-width: 1120px;
        }
        .cinematic-recap-header { text-align: center; display: flex; flex-direction: column; align-items: center; gap: 16px; }
        .cinematic-recap-badge {
            display: inline-block;
            padding: 8px 24px; border-radius: 999px;
            background: linear-gradient(135deg, #eab308, #f59e0b);
            color: #000; font-size: 15px; font-weight: 900;
            letter-spacing: 0.28em; text-transform: uppercase;
            box-shadow: 0 12px 30px rgba(234,179,8,0.24);
            animation: cin-badge-in 0.6s cubic-bezier(.22,1,.36,1) both;
        }
        .cinematic-recap-title {
            margin-top: 0; font-size: 56px; font-weight: 900; color: #fff;
            line-height: 0.95; max-width: 900px;
            text-shadow: 0 0 30px rgba(234,179,8,0.3);
            animation: cin-title-in 0.7s cubic-bezier(.22,1,.36,1) 0.2s both;
        }
        @keyframes cin-badge-in { from { opacity:0; transform: scale(0.5) translateY(-20px); } to { opacity:1; transform: scale(1) translateY(0); } }
        @keyframes cin-title-in { from { opacity:0; transform: translateY(20px); } to { opacity:1; transform: translateY(0); } }

        .cinematic-recap-cards {
            display: flex; flex-wrap: wrap; gap: 20px;
            justify-content: center; align-items: stretch; width: 100%;
        }

        .cinematic-recap-cards--single {
            max-width: 640px;
        }

        .cinematic-recap-cards--multi {
            max-width: 920px;
        }

        .cinematic-recap-card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px; padding: 28px 22px;
            display: flex; flex-direction: column; align-items: center;
            justify-content: center;
            gap: 14px; min-width: 200px; min-height: 230px; flex: 1 1 240px; max-width: 280px;
            opacity: 0; transform: translateY(40px) scale(0.9);
            animation: cin-card-in 0.6s cubic-bezier(.22,1,.36,1) both;
            transition: border-color 0.3s, box-shadow 0.3s, transform 0.3s;
        }
        .cinematic-recap-card:hover {
            border-color: rgba(234,179,8,0.3);
            box-shadow: 0 0 20px rgba(234,179,8,0.1);
        }
        .cinematic-recap-cards--single .cinematic-recap-card {
            width: min(100%, 520px);
            max-width: 520px;
            min-width: 0;
            min-height: 340px;
            padding: 38px 32px;
            gap: 18px;
            border-radius: 30px;
            box-shadow: 0 22px 60px rgba(0,0,0,0.32), 0 0 0 1px rgba(255,255,255,0.07) inset;
        }
        .cinematic-recap-card--gamble {
            border-color: rgba(239,68,68,0.4);
            background: linear-gradient(135deg, rgba(239,68,68,0.08), rgba(234,179,8,0.05));
            box-shadow: 0 0 25px rgba(239,68,68,0.15), inset 0 0 30px rgba(239,68,68,0.05);
        }
        @keyframes cin-card-in { to { opacity:1; transform: translateY(0) scale(1); } }

        .cinematic-recap-avatar {
            width: 78px; height: 78px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.15);
            object-fit: cover;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
        }
        .cinematic-recap-cards--single .cinematic-recap-avatar {
            width: 120px;
            height: 120px;
        }
        .cinematic-recap-card--gamble .cinematic-recap-avatar {
            border-color: #ef4444;
            box-shadow: 0 0 15px rgba(239,68,68,0.5);
            animation: cin-avatar-pulse 1.5s ease-in-out infinite;
        }
        @keyframes cin-avatar-pulse {
            0%, 100% { box-shadow: 0 0 10px rgba(239,68,68,0.4); }
            50% { box-shadow: 0 0 25px rgba(239,68,68,0.8); }
        }
        .cinematic-recap-name {
            font-family: var(--sf-font-gamer);
            font-variation-settings: var(--sf-fvs-gamer);
            font-style: italic;
            text-transform: uppercase;
            font-size: 18px; color: #fff;
            text-align: center; line-height: 1.2;
            max-width: 190px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .cinematic-recap-cards--single .cinematic-recap-name {
            max-width: 100%;
            font-size: 28px;
        }
        .cinematic-recap-choice {
            font-size: 13px; font-weight: 800; letter-spacing: 0.08em;
            padding: 7px 16px; border-radius: 999px; text-transform: uppercase;
        }
        .cinematic-recap-cards--single .cinematic-recap-choice {
            font-size: 16px;
            padding: 10px 22px;
        }
        .cinematic-recap-choice--original { background: rgba(34,197,94,0.15); color: #4ade80; }
        .cinematic-recap-choice--cashback { background: rgba(59,130,246,0.15); color: #60a5fa; }
        .cinematic-recap-choice--gamble { background: rgba(239,68,68,0.2); color: #f87171; animation: cin-choice-glow 1.2s ease-in-out infinite alternate; }
        @keyframes cin-choice-glow {
            from { text-shadow: 0 0 5px rgba(239,68,68,0.5); }
            to { text-shadow: 0 0 20px rgba(239,68,68,1), 0 0 40px rgba(234,179,8,0.5); }
        }
        .cinematic-recap-emoji { font-size: 36px; }
        .cinematic-recap-cards--single .cinematic-recap-emoji { font-size: 52px; }

        @media (min-width: 768px) {
            .cinematic-recap-cards--multi {
                display: grid;
                grid-template-columns: repeat(2, minmax(280px, 1fr));
                gap: 24px;
            }

            .cinematic-recap-cards--multi .cinematic-recap-card {
                max-width: none;
            }
        }

        /* --- SCENE 2: MORTAL KOMBAT TRANSITION --- */
        .cinematic-kombat-bg {
            position: absolute; inset: 0;
            background: #000;
        }
        .cinematic-kombat-flash {
            position: absolute; inset: 0;
            background: #fff; opacity: 0; z-index: 2;
            pointer-events: none;
        }
        .cinematic-kombat-flash.flash-active {
            animation: cin-flash 0.15s ease-out;
        }
        @keyframes cin-flash { 0% { opacity:0.9; } 100% { opacity:0; } }

        .cinematic-kombat-vignette {
            position: absolute; inset: 0; z-index: 3;
            background: radial-gradient(ellipse at center, transparent 40%, rgba(0,0,0,0.8) 100%);
            pointer-events: none;
        }

        .cinematic-kombat-content {
            position: relative; z-index: 4;
            display: flex; flex-direction: column; align-items: center;
            gap: 16px; text-align: center;
        }
        .cinematic-kombat-text {
            display: flex; flex-direction: column; align-items: center;
            line-height: 0.9;
        }
        .cinematic-kombat-line1,
        .cinematic-kombat-line2,
        .cinematic-kombat-line3 {
            font-weight: 900; text-transform: uppercase;
            opacity: 0; display: block;
        }
        .cinematic-kombat-line1 {
            font-size: clamp(68px, 16vw, 144px);
            color: #fff;
            text-shadow: 0 0 40px rgba(234,179,8,0.6), 0 4px 0 #b8860b, 0 8px 0 #8b6508;
            animation: cin-mk-slam 0.5s cubic-bezier(.17,.67,.29,1.3) 0.3s both;
        }
        .cinematic-kombat-line2 {
            font-size: clamp(40px, 10vw, 72px);
            color: #ef4444;
            text-shadow: 0 0 30px rgba(239,68,68,0.8);
            animation: cin-mk-slam 0.4s cubic-bezier(.17,.67,.29,1.3) 0.7s both;
        }
        .cinematic-kombat-line3 {
            font-size: clamp(76px, 18vw, 164px);
            background: linear-gradient(180deg, #fbbf24 0%, #ef4444 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 30px rgba(239,68,68,0.6)) drop-shadow(0 6px 0 rgba(0,0,0,0.5));
            animation: cin-mk-slam 0.6s cubic-bezier(.17,.67,.29,1.3) 1.0s both;
        }
        @keyframes cin-mk-slam {
            0% { opacity: 0; transform: scale(3) translateY(-40px); filter: blur(8px); }
            60% { opacity: 1; transform: scale(0.95) translateY(4px); filter: blur(0); }
            80% { transform: scale(1.05) translateY(-2px); }
            100% { opacity: 1; transform: scale(1) translateY(0); filter: blur(0); }
        }

        .cinematic-kombat-subtitle {
            font-size: 14px; font-weight: 600; color: rgba(255,255,255,0.5);
            letter-spacing: 0.15em; text-transform: uppercase;
            opacity: 0;
            animation: cin-sub-in 0.8s ease 1.6s both;
        }
        @keyframes cin-sub-in { from { opacity:0; transform: translateY(10px); } to { opacity:1; transform: translateY(0); } }

        /* Screen shake */
        .cinematic-shake {
            animation: cin-screen-shake 0.4s ease-in-out;
        }
        @keyframes cin-screen-shake {
            0%, 100% { transform: translate(0, 0) rotate(0); }
            10% { transform: translate(-4px, 2px) rotate(-0.5deg); }
            20% { transform: translate(4px, -2px) rotate(0.5deg); }
            30% { transform: translate(-3px, 3px) rotate(-0.3deg); }
            40% { transform: translate(3px, -1px) rotate(0.3deg); }
            50% { transform: translate(-2px, 1px) rotate(-0.2deg); }
            60% { transform: translate(2px, -1px) rotate(0.2deg); }
            70% { transform: translate(-1px, 1px) rotate(0deg); }
        }

        /* Particles */
        .cinematic-particle {
            position: absolute; border-radius: 50%;
            pointer-events: none; z-index: 1;
        }
        .cinematic-particle--ember {
            width: 4px; height: 4px;
            background: #fbbf24;
            box-shadow: 0 0 6px #fbbf24, 0 0 12px rgba(239,68,68,0.5);
            animation: cin-ember-float linear both;
        }
        .cinematic-particle--spark {
            width: 2px; height: 8px;
            background: linear-gradient(to bottom, #fff, #fbbf24);
            border-radius: 1px;
            animation: cin-spark-fly linear both;
        }
        @keyframes cin-ember-float {
            0% { opacity: 1; transform: translateY(0) scale(1); }
            100% { opacity: 0; transform: translateY(-200px) scale(0); }
        }
        @keyframes cin-spark-fly {
            0% { opacity: 1; transform: translateY(0) rotate(0deg); }
            100% { opacity: 0; transform: translateY(-300px) translateX(var(--spark-x, 50px)) rotate(720deg); }
        }

        /* Scanline overlay for extra grit */
        .cinematic-kombat-bg::after {
            content: '';
            position: absolute; inset: 0;
            background: repeating-linear-gradient(
                0deg,
                transparent,
                transparent 2px,
                rgba(255,255,255,0.015) 2px,
                rgba(255,255,255,0.015) 4px
            );
            pointer-events: none;
            animation: cin-scanline-scroll 8s linear infinite;
        }
        @keyframes cin-scanline-scroll {
            from { transform: translateY(0); }
            to { transform: translateY(100px); }
        }

        @media (max-width: 600px) {
            .cinematic-recap-content { gap: 24px; padding: 20px 16px 28px; }
            .cinematic-recap-header { gap: 12px; }
            .cinematic-recap-badge { padding: 6px 16px; font-size: 12px; letter-spacing: 0.2em; }
            .cinematic-recap-title { font-size: 32px; line-height: 1; }
            .cinematic-recap-cards { gap: 10px; }
            .cinematic-recap-card { min-width: 110px; min-height: 0; max-width: 180px; padding: 14px 10px; gap: 10px; flex: 1 1 110px; border-radius: 18px; }
            .cinematic-recap-avatar { width: 44px; height: 44px; }
            .cinematic-recap-name { font-size: 13px; max-width: 130px; }
            .cinematic-recap-choice { font-size: 11px; padding: 5px 10px; }
            .cinematic-recap-emoji { font-size: 24px; }
            .cinematic-recap-cards--single { max-width: 100%; }
            .cinematic-recap-cards--single .cinematic-recap-card { width: min(100%, 340px); max-width: 340px; min-height: 270px; padding: 24px 18px; gap: 14px; }
            .cinematic-recap-cards--single .cinematic-recap-avatar { width: 88px; height: 88px; }
            .cinematic-recap-cards--single .cinematic-recap-name { font-size: 22px; max-width: 100%; }
            .cinematic-recap-cards--single .cinematic-recap-choice { font-size: 13px; padding: 8px 16px; }
            .cinematic-recap-cards--single .cinematic-recap-emoji { font-size: 38px; }
            .cinematic-recap-cards--multi .cinematic-recap-card { max-width: 160px; }
        }

        /* ============================================================
           DECISION ALERT TOASTS (Peer pressure notifications)
           ============================================================ */
        .decision-alert-container {
            position: fixed;
            bottom: 18px;
            left: 50%; transform: translateX(-50%);
            z-index: 99999;
            width: 94vw; max-width: 420px;
            display: flex; flex-direction: column; gap: 10px;
            pointer-events: none;
        }
        .decision-alert {
            position: relative;
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px;
            border-radius: 14px;
            font-family: 'Inter', system-ui, sans-serif;
            color: #fff;
            box-shadow: 0 8px 32px rgba(0,0,0,0.45), 0 0 0 1px rgba(255,255,255,0.06) inset;
            backdrop-filter: blur(16px);
            pointer-events: auto;
            animation: da-slide-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            overflow: hidden;
        }
        .decision-alert.da-exit {
            animation: da-slide-out 0.45s cubic-bezier(0.55, 0, 1, 0.45) forwards;
        }

        /* --- Gamble Theme (fiery/aggressive) --- */
        .decision-alert--gamble {
            background: linear-gradient(135deg, rgba(220,38,38,0.88) 0%, rgba(234,88,12,0.85) 50%, rgba(220,38,38,0.88) 100%);
            border: 1px solid rgba(251,146,60,0.5);
        }
        .decision-alert--gamble .da-avatar-ring {
            box-shadow: 0 0 0 2px #fb923c, 0 0 14px 3px rgba(251,146,60,0.5);
        }
        .decision-alert--gamble .da-icon-badge {
            background: #dc2626; box-shadow: 0 0 10px rgba(220,38,38,0.6);
        }
        .decision-alert--gamble::before {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(90deg, transparent 0%, rgba(251,191,36,0.08) 50%, transparent 100%);
            animation: da-shimmer-fire 2s ease-in-out infinite;
        }
        .decision-alert--gamble.da-enter {
            animation: da-slide-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards,
                       da-shake-fire 0.5s 0.15s ease-out;
        }

        /* --- Safe Theme (gold/green celebratory) --- */
        .decision-alert--safe {
            background: linear-gradient(135deg, rgba(21,128,61,0.85) 0%, rgba(22,101,52,0.88) 50%, rgba(21,128,61,0.85) 100%);
            border: 1px solid rgba(74,222,128,0.4);
        }
        .decision-alert--safe .da-avatar-ring {
            box-shadow: 0 0 0 2px #4ade80, 0 0 12px 2px rgba(74,222,128,0.4);
        }
        .decision-alert--safe .da-icon-badge {
            background: #15803d; box-shadow: 0 0 10px rgba(74,222,128,0.4);
        }
        .decision-alert--safe::before {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(90deg, transparent 0%, rgba(74,222,128,0.07) 50%, transparent 100%);
            animation: da-shimmer-gold 2.5s ease-in-out infinite;
        }

        /* --- Shared Sub-Elements --- */
        .da-avatar-ring {
            flex-shrink: 0;
            width: 42px; height: 42px;
            border-radius: 50%;
            overflow: hidden;
            position: relative; z-index: 1;
        }
        .da-avatar-ring img {
            width: 100%; height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .da-icon-badge {
            position: absolute;
            bottom: -2px; right: -4px;
            width: 20px; height: 20px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 10px;
            border: 2px solid rgba(0,0,0,0.3);
            z-index: 2;
        }
        .da-body {
            flex: 1; min-width: 0;
            position: relative; z-index: 1;
        }
        .da-body-text {
            font-size: 13px; line-height: 1.4;
            font-weight: 500;
            text-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        .da-body-text strong {
            font-family: var(--sf-font-gamer);
            font-variation-settings: var(--sf-fvs-gamer);
            font-style: italic;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .da-progress {
            position: absolute; bottom: 0; left: 0; right: 0;
            height: 3px;
            background: rgba(255,255,255,0.12);
            border-radius: 0 0 14px 14px;
            overflow: hidden;
        }
        .da-progress-bar {
            height: 100%;
            background: rgba(255,255,255,0.5);
            border-radius: 0 0 14px 14px;
            animation: da-progress-shrink 4.5s linear forwards;
        }

        /* --- Keyframes --- */
        @keyframes da-slide-in {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes da-slide-out {
            from { opacity: 1; transform: translateY(0) scale(1); }
            to   { opacity: 0; transform: translateY(40px) scale(0.92); }
        }
        @keyframes da-shake-fire {
            0%, 100% { transform: translateX(0); }
            15% { transform: translateX(-6px) rotate(-1deg); }
            30% { transform: translateX(5px) rotate(0.8deg); }
            45% { transform: translateX(-4px) rotate(-0.5deg); }
            60% { transform: translateX(3px); }
            75% { transform: translateX(-2px); }
        }
        @keyframes da-shimmer-fire {
            0%, 100% { opacity: 0; transform: translateX(-100%); }
            50%      { opacity: 1; transform: translateX(100%); }
        }
        @keyframes da-shimmer-gold {
            0%, 100% { opacity: 0; transform: translateX(-100%); }
            50%      { opacity: 1; transform: translateX(100%); }
        }
        @keyframes da-progress-shrink {
            from { width: 100%; }
            to   { width: 0%; }
        }

        /* --- Persistent Mode (active decider — never auto-dismiss) --- */
        .decision-alert.da-persistent {
            animation: da-slide-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards,
                       da-breathe 2.4s ease-in-out 0.5s infinite;
        }
        .decision-alert.da-persistent.da-enter {
            animation: da-slide-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards,
                       da-shake-fire 0.5s 0.15s ease-out,
                       da-breathe 2.4s ease-in-out 0.8s infinite;
        }
        .decision-alert.da-persistent .da-progress { display: none; }
        .decision-alert.da-persistent.decision-alert--gamble {
            box-shadow: 0 8px 32px rgba(0,0,0,0.45),
                        0 0 0 1px rgba(255,255,255,0.06) inset,
                        0 0 18px 4px rgba(251,146,60,0.35);
            animation: da-slide-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards,
                       da-breathe 2.4s ease-in-out 0.5s infinite,
                       da-pulse-glow-fire 1.8s ease-in-out infinite;
        }
        .decision-alert.da-persistent.decision-alert--gamble.da-enter {
            animation: da-slide-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards,
                       da-shake-fire 0.5s 0.15s ease-out,
                       da-breathe 2.4s ease-in-out 0.8s infinite,
                       da-pulse-glow-fire 1.8s ease-in-out 0.5s infinite;
        }
        .decision-alert.da-persistent.decision-alert--safe {
            box-shadow: 0 8px 32px rgba(0,0,0,0.45),
                        0 0 0 1px rgba(255,255,255,0.06) inset,
                        0 0 14px 3px rgba(74,222,128,0.3);
            animation: da-slide-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards,
                       da-breathe 2.4s ease-in-out 0.5s infinite,
                       da-pulse-glow-safe 2.2s ease-in-out infinite;
        }
        .decision-alert.da-persistent .da-avatar-ring {
            animation: da-avatar-pulse 2s ease-in-out infinite;
        }

        @keyframes da-breathe {
            0%, 100% { transform: scale(1); }
            50%      { transform: scale(1.015); }
        }
        @keyframes da-pulse-glow-fire {
            0%, 100% { box-shadow: 0 8px 32px rgba(0,0,0,0.45), 0 0 0 1px rgba(255,255,255,0.06) inset, 0 0 18px 4px rgba(251,146,60,0.3); }
            50%      { box-shadow: 0 8px 32px rgba(0,0,0,0.45), 0 0 0 1px rgba(255,255,255,0.06) inset, 0 0 28px 8px rgba(251,146,60,0.55); }
        }
        @keyframes da-pulse-glow-safe {
            0%, 100% { box-shadow: 0 8px 32px rgba(0,0,0,0.45), 0 0 0 1px rgba(255,255,255,0.06) inset, 0 0 14px 3px rgba(74,222,128,0.25); }
            50%      { box-shadow: 0 8px 32px rgba(0,0,0,0.45), 0 0 0 1px rgba(255,255,255,0.06) inset, 0 0 22px 6px rgba(74,222,128,0.5); }
        }
        @keyframes da-avatar-pulse {
            0%, 100% { transform: scale(1); filter: brightness(1); }
            50%      { transform: scale(1.08); filter: brightness(1.2); }
        }

        @media (max-width: 480px) {
            .decision-alert-container { bottom: 12px; width: 96vw; }
            .decision-alert { padding: 11px 13px; gap: 10px; border-radius: 12px; }
            .da-avatar-ring { width: 36px; height: 36px; }
            .da-body-text { font-size: 12px; }
            .decision-alert.da-persistent { padding: 12px 14px; }
        }

        /* --- RULETA V3 (Canvas Premium) --- */
        .wheel-outer {
            width: 680px;
            height: 680px;
            max-width: 92vw;
            max-height: 92vw;
            border-radius: 50%;
            position: relative;
            overflow: visible;
            margin: 0 auto;
            background: transparent;
        }

        @media (max-width: 768px) {
            .wheel-outer {
                width: 560px;
                height: 560px;
            }
        }

        .wheel-spinner {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            position: relative;
            overflow: hidden;
            filter: drop-shadow(0 20px 40px rgba(0, 0, 0, 0.35));
            transition: none;
            will-change: transform;
        }

        .wheel-item {
            position: absolute;
            top: 50%;
            left: 50%;
            transform-origin: 0 0;
            display: flex;
            align-items: center;
        }

        .wheel-arrow {
            position: absolute;
            top: -18px;
            left: 50%;
            transform: translateX(-50%);
            transform-origin: 50% 8px;
            width: 60px;
            height: 80px;
            z-index: 100;
            pointer-events: none;
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.5));
            transition: none;
        }

        /* ESPECTADOR GHOST MODE */
        .ghost-btn {
            opacity: 0.4;
            filter: grayscale(100%);
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .ghost-selected {
            opacity: 1 !important;
            filter: grayscale(0%) !important;
            transform: scale(1.1);
            box-shadow: 0 0 30px rgba(234, 179, 8, 0.6);
            animation: pulseChoice 1s infinite;
            border-color: #eab308;
        }

        @keyframes pulseChoice {
            0% {
                transform: scale(1.1);
            }

            50% {
                transform: scale(1.15);
            }

            100% {
                transform: scale(1.1);
            }
        }

        /* --- GAMBLE: COIN FLIP 3D --- */
        .coin-container {
            perspective: 1200px;
            width: clamp(210px, 40vw, 340px);
            height: clamp(210px, 40vw, 340px);
            display: none;
            position: fixed;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
        }

        .coin {
            width: 100%;
            height: 100%;
            position: relative;
            transform-style: preserve-3d;
            transition: transform 0.1s linear;
        }

        .coin-face {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            border-radius: 50%;
            border: 1px solid #000000;
            background-color: transparent;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            filter: drop-shadow(0 8px 16px rgba(0, 0, 0, 0.6));
        }

        .coin-front {
            transform: rotateY(0deg);
            background-image: url('https://res.cloudinary.com/dq3xsjgwi/image/upload/v1769835633/c_fj4zze.webp');
            color: transparent;
            font-size: 0;
        }

        .coin-back {
            transform: rotateY(180deg);
            background-image: url('https://res.cloudinary.com/dq3xsjgwi/image/upload/v1769835628/s_jhmuvv.webp');
            color: transparent;
            font-size: 0;
        }

        #gambleOverlay.gamble-coin-layout {
            justify-content: space-between !important;
            padding-top: 2.5rem;
            padding-bottom: 2.5rem;
        }

        #gambleOverlay.gamble-transparent {
            background: transparent !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            justify-content: space-between !important;
            padding-top: 2.5rem;
            padding-bottom: 2.5rem;
        }

        #gambleOverlay.gamble-transparent #sceneCoin {
            display: none !important;
        }

        /* GAMBLE: SLOT/ROULETTE OVERRIDE */
        .gamble-item-x2 {
            background: #166534;
            color: #4ade80;
            border: 2px solid #4ade80;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            font-size: 1.5rem;
            font-weight: 900;
        }

        .gamble-item-skull {
            background: #7f1d1d;
            color: #f87171;
            border: 2px solid #f87171;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            font-size: 1.5rem;
        }

        .modal-enter {
            animation: modalIn 0.3s ease-out forwards;
        }

        /* viewTimer: Waiting Room Display Control */
        #viewTimer {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        #viewTimer.hidden {
            display: none !important;
        }

        /* Desktop: Adjust game overlays to respect sidebar */
        @media (min-width: 1024px) {

            #viewProcessing,
            #viewWinner,
            #mainAnimOverlay,
            #gambleOverlay {
                right: 350px !important;
                /* Leave space for sidebar */
                z-index: 1000;
                /* Increase z-index for proper stacking */
            }

            /* Corrección: Garantiza que la sala de trofeos use el 100% de la pantalla y esté perfectamente centrada */
            #viewResults {
                right: 0 !important;
                width: 100% !important;
                z-index: 1000 !important;
            }
        }

        /* FIX: Forzar que TODOS los modales (SweetAlert, Participantes, Auditoría, etc.) destaquen por encima de todo en Escritorio y Móvil */
        .swal2-container,
        .participants-modal,
        #participantsModal,
        #fairnessModal,
        #winnerModal,
        #decisionModal {
            z-index: 9999 !important;
        }

        /* Cinema Mode: Full-screen immersive experience during game */
        body.game-active .participants-sidebar {
            display: none !important;
        }

        body.game-active .main-container {
            padding-right: 0 !important;
        }

        body.game-active #mainAnimOverlay,
        body.game-active #gambleOverlay,
        body.game-active #viewWinner,
        body.game-active #viewProcessing,
        body.game-active #viewResults {
            right: 0 !important;
            width: 100% !important;
        }

        body.game-active .view-participants-btn {
            display: none !important;
        }

        body.game-active {
            overflow: hidden;
        }

        #mainAnimOverlay {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            overflow: hidden;
            padding: 24px 16px;
            z-index: 1500;
        }

        #gambleOverlay {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #000000;
            overflow: hidden;
            padding: 24px 16px;
            z-index: 1600;
        }

        #decisionModal {
            z-index: 2000;
        }

        /* ============================================================
           GAMBLE RESULT CINEMATIC STAGE (Redesigned with Avatar Centerpiece)
           ============================================================ */
        .gamble-result-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease-out;
        }

        /* Animated Background */
        .gamble-result-bg {
            position: absolute;
            inset: 0;
            z-index: 0;
        }

        /* === VICTORY THEME === */
        .gamble-result-overlay.victory .gamble-result-bg {
            background: radial-gradient(ellipse at center,
                #fffbeb 0%,
                #fef3c7 30%,
                #fde68a 60%,
                #fbbf24 100%);
            animation: victory-bg-pulse 2s ease-in-out infinite alternate;
        }

        @keyframes victory-bg-pulse {
            from { filter: brightness(1); }
            to { filter: brightness(1.15); }
        }

        /* Sun Rays (Victory Only) */
        .gamble-sunrays {
            position: absolute;
            inset: -50%;
            z-index: 1;
            opacity: 0;
            pointer-events: none;
            background: conic-gradient(
                from 0deg,
                transparent 0deg,
                rgba(251, 191, 36, 0.3) 10deg,
                transparent 20deg,
                transparent 40deg,
                rgba(251, 191, 36, 0.3) 50deg,
                transparent 60deg,
                transparent 80deg,
                rgba(251, 191, 36, 0.3) 90deg,
                transparent 100deg,
                transparent 120deg,
                rgba(251, 191, 36, 0.3) 130deg,
                transparent 140deg,
                transparent 160deg,
                rgba(251, 191, 36, 0.3) 170deg,
                transparent 180deg,
                transparent 200deg,
                rgba(251, 191, 36, 0.3) 210deg,
                transparent 220deg,
                transparent 240deg,
                rgba(251, 191, 36, 0.3) 250deg,
                transparent 260deg,
                transparent 280deg,
                rgba(251, 191, 36, 0.3) 290deg,
                transparent 300deg,
                transparent 320deg,
                rgba(251, 191, 36, 0.3) 330deg,
                transparent 340deg,
                transparent 360deg
            );
            animation: sunrays-rotate 20s linear infinite;
        }

        .victory .gamble-sunrays {
            opacity: 1;
        }

        @keyframes sunrays-rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Main Content Container */
        .gamble-result-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: clamp(16px, 4vw, 32px);
            padding: 24px;
            text-align: center;
            animation: result-content-entrance 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        @keyframes result-content-entrance {
            from {
                opacity: 0;
                transform: scale(0.7) translateY(50px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* Title */
        .gamble-result-title {
            font-size: clamp(40px, 10vw, 80px);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            line-height: 1;
            margin-bottom: 8px;
            animation: result-title-bounce 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        .victory .gamble-result-title {
            background: linear-gradient(135deg, #b45309 0%, #dc2626 50%, #991b1b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.4));
        }

        @keyframes result-title-bounce {
            0% { transform: scale(0) rotate(-10deg); opacity: 0; }
            60% { transform: scale(1.15) rotate(2deg); }
            80% { transform: scale(0.95) rotate(-1deg); }
            100% { transform: scale(1) rotate(0deg); opacity: 1; }
        }

        /* Winner Spotlight (Avatar Container) */
        .gamble-spotlight {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            animation: spotlight-entrance 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
        }

        @keyframes spotlight-entrance {
            from {
                opacity: 0;
                transform: scale(0.5) translateY(30px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* Spotlight Glow */
        .gamble-spotlight-glow {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: clamp(200px, 40vw, 400px);
            height: clamp(200px, 40vw, 400px);
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
        }

        .victory .gamble-spotlight-glow {
            background: radial-gradient(circle, rgba(251, 191, 36, 0.6) 0%, transparent 70%);
            animation: victory-glow-pulse 2s ease-in-out infinite;
        }

        @keyframes victory-glow-pulse {
            0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.6; }
            50% { transform: translate(-50%, -50%) scale(1.2); opacity: 0.8; }
        }

        /* Avatar Container */
        .gamble-avatar-container {
            position: relative;
            width: clamp(140px, 30vw, 240px);
            height: clamp(140px, 30vw, 240px);
            border-radius: 50%;
            z-index: 1;
        }

        .victory .gamble-avatar-container {
            border: 6px solid #fbbf24;
            box-shadow: 0 0 40px rgba(251, 191, 36, 0.8),
                        0 0 80px rgba(251, 191, 36, 0.4),
                        inset 0 0 20px rgba(255, 255, 255, 0.3);
            animation: victory-avatar-bounce 1s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        @keyframes victory-avatar-bounce {
            0% { transform: scale(0) rotate(-20deg); }
            60% { transform: scale(1.1) rotate(5deg); }
            100% { transform: scale(1) rotate(0deg); }
        }

        /* Avatar Image */
        .gamble-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            display: block;
        }

        /* Avatar Overlay (for effects) */
        .gamble-avatar-overlay {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            pointer-events: none;
        }

        /* Winner Name (Increased size for prominence) */
        .gamble-winner-name {
            font-family: var(--sf-font-gamer);
            font-variation-settings: var(--sf-fvs-gamer);
            font-style: italic;
            text-transform: uppercase;
            font-size: clamp(28px, 6vw, 48px);
            letter-spacing: 0.02em;
            animation: name-fade-in 0.8s ease-out 0.4s both;
        }

        .victory .gamble-winner-name {
            color: #78350f;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        @keyframes name-fade-in {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Subtitle */
        .gamble-result-subtitle {
            font-size: clamp(16px, 4vw, 28px);
            font-weight: 700;
            animation: result-subtitle-fade 0.8s ease-out 0.5s both;
        }

        .victory .gamble-result-subtitle {
            color: #92400e;
        }

        @keyframes result-subtitle-fade {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ============================================================
           VICTORY EFFECTS: Money Rain & Confetti
           ============================================================ */
        .gamble-victory-effects {
            position: absolute;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            overflow: hidden;
            opacity: 0;
        }

        .victory .gamble-victory-effects {
            opacity: 1;
        }

        /* Money Rain */
        .gamble-money-bill {
            position: absolute;
            width: 60px;
            height: 30px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: 2px solid #047857;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 900;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            animation: money-fall linear forwards;
        }

        .gamble-money-bill::before {
            content: '$';
        }

        @keyframes money-fall {
            0% {
                transform: translateY(-100px) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            100% {
                transform: translateY(120vh) rotate(var(--rotate, 720deg));
                opacity: 0;
            }
        }

        /* Gold Coins */
        .gamble-gold-coin {
            position: absolute;
            width: 40px;
            height: 40px;
            background: radial-gradient(circle at 30% 30%, #fef3c7, #fbbf24, #d97706);
            border: 3px solid #b45309;
            border-radius: 50%;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4),
                        inset 0 2px 4px rgba(255, 255, 255, 0.5);
            animation: coin-fall linear forwards;
        }

        @keyframes coin-fall {
            0% {
                transform: translateY(-100px) rotateY(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            100% {
                transform: translateY(120vh) rotateY(var(--rotate-y, 1080deg));
                opacity: 0;
            }
        }

        /* Confetti Burst */
        .gamble-confetti-piece {
            position: absolute;
            width: 12px;
            height: 12px;
            animation: confetti-burst linear forwards;
        }

        @keyframes confetti-burst {
            0% {
                transform: translateY(0) translateX(0) rotate(0deg);
                opacity: 1;
            }
            100% {
                transform: translateY(120vh) translateX(var(--drift, 0)) rotate(var(--rotate, 720deg));
                opacity: 0;
            }
        }

        /* ============================================================
           DEFEAT THEME: Cold, Dark, Poverty
           CARTOON POVERTY / ABANDONED CABIN AESTHETIC
           ============================================================ */
        .gamble-result-overlay.defeat .gamble-result-bg {
            background:
                /* Dust/dirt overlay */
                radial-gradient(ellipse at 30% 40%, rgba(101, 67, 33, 0.3) 0%, transparent 50%),
                radial-gradient(ellipse at 70% 60%, rgba(92, 64, 51, 0.2) 0%, transparent 40%),
                /* Wood grain texture simulation */
                repeating-linear-gradient(
                    90deg,
                    #2d1810 0px,
                    #3d2415 2px,
                    #2d1810 4px,
                    #3d2415 6px,
                    #2d1810 8px
                ),
                /* Dark base */
                linear-gradient(180deg, #1a0f0a 0%, #0d0705 100%);
            animation: cabin-dust-drift 8s ease-in-out infinite;
        }

        @keyframes cabin-dust-drift {
            0%, 100% { filter: brightness(0.9) contrast(1.1); }
            50% { filter: brightness(0.85) contrast(1.15); }
        }

        .defeat .gamble-result-title {
            color: #fbbf24;
            text-shadow: 0 0 20px rgba(251, 191, 36, 0.6),
                         0 0 40px rgba(251, 191, 36, 0.3),
                         0 4px 0 #78350f,
                         0 6px 12px rgba(0, 0, 0, 0.8);
            animation: defeat-title-shake 1s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        @keyframes defeat-title-shake {
            0% {
                transform: scale(1.5) translateY(-50px) rotate(-5deg);
                opacity: 0;
                filter: blur(10px);
            }
            50% {
                transform: scale(1.1) translateY(5px) rotate(2deg);
                filter: blur(2px);
            }
            100% {
                transform: scale(1) translateY(0) rotate(0deg);
                opacity: 1;
                filter: blur(0);
            }
        }

        .defeat .gamble-spotlight-glow {
            background: radial-gradient(circle, rgba(139, 92, 46, 0.2) 0%, transparent 70%);
            animation: defeat-glow-dim 4s ease-in-out infinite;
        }

        @keyframes defeat-glow-dim {
            0%, 100% { opacity: 0.2; }
            50% { opacity: 0.1; }
        }

        /* Defeated Avatar: Rotten Wood Frame + Broken Glass */
        .defeat .gamble-avatar-container {
            border: 8px solid #5c4033;
            background: linear-gradient(135deg, #3d2415 0%, #2d1810 100%);
            box-shadow:
                0 0 30px rgba(92, 64, 51, 0.6),
                inset 0 0 20px rgba(0, 0, 0, 0.9),
                inset 0 4px 8px rgba(139, 92, 46, 0.3);
            animation: defeat-avatar-rot 1.2s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
            position: relative;
        }

        /* Broken glass cracks overlay */
        .defeat .gamble-avatar-container::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            background:
                linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.15) 32%, transparent 34%),
                linear-gradient(135deg, transparent 45%, rgba(255, 255, 255, 0.12) 47%, transparent 49%),
                linear-gradient(-45deg, transparent 60%, rgba(255, 255, 255, 0.1) 62%, transparent 64%),
                linear-gradient(90deg, transparent 25%, rgba(255, 255, 255, 0.08) 26%, transparent 27%);
            pointer-events: none;
            z-index: 2;
            opacity: 0.7;
        }

        /* Wood grain texture on border */
        .defeat .gamble-avatar-container::after {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 2px,
                rgba(61, 36, 21, 0.3) 2px,
                rgba(61, 36, 21, 0.3) 4px
            );
            pointer-events: none;
            z-index: 1;
        }

        @keyframes defeat-avatar-rot {
            0% { transform: scale(1.2) rotate(10deg); filter: brightness(1); }
            50% { transform: scale(0.95) rotate(-5deg); }
            100% { transform: scale(1) rotate(0deg); filter: brightness(0.5) sepia(0.4); }
        }

        /* Sepia + Grayscale Filter on Avatar */
        .defeat .gamble-avatar {
            filter: grayscale(80%) sepia(40%) brightness(0.5) contrast(1.3);
        }

        /* Dirt/dust overlay on avatar */
        .defeat .gamble-avatar-overlay {
            background:
                radial-gradient(circle at 30% 30%, rgba(101, 67, 33, 0.4) 0%, transparent 40%),
                radial-gradient(circle at 70% 70%, rgba(92, 64, 51, 0.3) 0%, transparent 35%),
                linear-gradient(135deg,
                    transparent 0%,
                    rgba(61, 36, 21, 0.2) 25%,
                    transparent 50%,
                    rgba(61, 36, 21, 0.2) 75%,
                    transparent 100%);
            background-size: 100% 100%, 100% 100%, 200% 200%;
            animation: dirt-shimmer 5s ease-in-out infinite;
        }

        @keyframes dirt-shimmer {
            0%, 100% { background-position: 0% 0%, 0% 0%, 0% 0%; }
            50% { background-position: 0% 0%, 0% 0%, 100% 100%; }
        }

        .defeat .gamble-winner-name {
            color: #d4a574;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.9);
        }

        .defeat .gamble-result-subtitle {
            color: #a0826d;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.8);
        }

        /* ============================================================
           DEFEAT EFFECTS: Wooden Cabin Poverty Theme (3 Random Options)
           ============================================================ */
        .gamble-defeat-effects {
            position: absolute;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            overflow: hidden;
            opacity: 0;
        }

        .defeat .gamble-defeat-effects {
            opacity: 1;
        }

        /* OPTION A: Falling Wooden Planks */
        .gamble-wood-plank {
            position: absolute;
            width: 120px;
            height: 20px;
            background: linear-gradient(90deg, #5c4033 0%, #3d2415 50%, #5c4033 100%);
            border: 2px solid #2d1810;
            border-radius: 2px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.6),
                        inset 0 2px 4px rgba(139, 92, 46, 0.3);
            animation: plank-fall linear forwards;
        }

        /* Wood grain lines */
        .gamble-wood-plank::before {
            content: '';
            position: absolute;
            inset: 2px;
            background: repeating-linear-gradient(
                90deg,
                transparent,
                transparent 8px,
                rgba(45, 24, 16, 0.3) 8px,
                rgba(45, 24, 16, 0.3) 10px
            );
        }

        @keyframes plank-fall {
            0% {
                transform: translateY(-100px) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            100% {
                transform: translateY(120vh) rotate(var(--rotate, 180deg));
                opacity: 0.8;
            }
        }

        /* OPTION B: Swinging Cobwebs + Dust Clouds */
        .gamble-cobweb-swing {
            position: absolute;
            width: 100px;
            height: 100px;
            opacity: 0;
            animation: cobweb-swing-appear 1.5s ease-out forwards;
            transform-origin: top center;
        }

        .gamble-cobweb-swing::before {
            content: '';
            position: absolute;
            width: 2px;
            height: 80px;
            background: linear-gradient(to bottom,
                rgba(200, 200, 200, 0.6) 0%,
                rgba(200, 200, 200, 0.3) 100%);
            left: 50%;
            top: 0;
            transform: translateX(-50%);
        }

        .gamble-cobweb-swing::after {
            content: '';
            position: absolute;
            width: 40px;
            height: 40px;
            background: radial-gradient(circle, rgba(200, 200, 200, 0.4) 0%, transparent 70%);
            left: 50%;
            bottom: 0;
            transform: translateX(-50%);
            border-radius: 50%;
        }

        @keyframes cobweb-swing-appear {
            0% {
                opacity: 0;
                transform: rotate(0deg);
            }
            100% {
                opacity: 0.7;
                transform: rotate(0deg);
            }
        }

        @keyframes cobweb-sway {
            0%, 100% { transform: rotate(-8deg); }
            50% { transform: rotate(8deg); }
        }

        .gamble-cobweb-swing.swaying {
            animation: cobweb-swing-appear 1.5s ease-out forwards,
                       cobweb-sway 3s ease-in-out 1.5s infinite;
        }

        /* Dust clouds */
        .gamble-dust-cloud {
            position: absolute;
            width: 60px;
            height: 60px;
            background: radial-gradient(circle, rgba(101, 67, 33, 0.4) 0%, transparent 70%);
            border-radius: 50%;
            animation: dust-drift linear forwards;
            filter: blur(8px);
        }

        @keyframes dust-drift {
            0% {
                transform: translate(0, 0) scale(0.5);
                opacity: 0;
            }
            20% {
                opacity: 0.6;
            }
            100% {
                transform: translate(var(--drift-x, 100px), var(--drift-y, 80vh)) scale(1.5);
                opacity: 0;
            }
        }

        /* OPTION C: Screen Cracks Spreading */
        .gamble-screen-crack {
            position: absolute;
            width: 2px;
            background: linear-gradient(to bottom,
                rgba(255, 255, 255, 0.6) 0%,
                rgba(255, 255, 255, 0.3) 50%,
                rgba(255, 255, 255, 0.1) 100%);
            transform-origin: top center;
            animation: crack-spread ease-out forwards;
            filter: drop-shadow(0 0 2px rgba(255, 255, 255, 0.4));
        }

        @keyframes crack-spread {
            0% {
                height: 0;
                opacity: 0;
            }
            20% {
                opacity: 1;
            }
            100% {
                height: var(--crack-length, 200px);
                opacity: 0.8;
            }
        }

        /* Crack branches */
        .gamble-screen-crack::before,
        .gamble-screen-crack::after {
            content: '';
            position: absolute;
            width: 2px;
            background: inherit;
            top: 50%;
        }

        .gamble-screen-crack::before {
            height: 40%;
            transform: rotate(-30deg);
            transform-origin: top center;
            left: 0;
        }

        .gamble-screen-crack::after {
            height: 40%;
            transform: rotate(30deg);
            transform-origin: top center;
            right: 0;
        }

        /* Mobile Optimizations */
        @media (max-width: 640px) {
            .gamble-result-title {
                font-size: clamp(32px, 12vw, 64px);
            }
            
            .gamble-result-subtitle {
                font-size: clamp(14px, 4vw, 22px);
            }

            .gamble-avatar-container {
                width: clamp(120px, 35vw, 180px);
                height: clamp(120px, 35vw, 180px);
            }

            .gamble-winner-name {
                font-size: clamp(18px, 6vw, 28px);
            }

            .gamble-money-bill {
                width: 45px;
                height: 22px;
                font-size: 14px;
            }

            .gamble-gold-coin {
                width: 30px;
                height: 30px;
            }
        }

        /* ============================================================
           EPIC WINNER CELEBRATION (4-second extravaganza)
           ============================================================ */
        .winner-celebration-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .winner-celebration-bg {
            position: absolute;
            inset: 0;
            background: #ffffff;
            background: radial-gradient(ellipse at center,
                #ffffff 0%,
                #fef3c7 40%,
                #fde68a 100%);
            animation: winner-bg-pulse 2s ease-in-out infinite alternate;
        }

        @keyframes winner-bg-pulse {
            from { opacity: 1; }
            to { opacity: 0.98; }
        }

        .winner-particles {
            position: absolute;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .winner-particle {
            position: absolute;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            animation: winner-particle-float linear forwards;
            pointer-events: none;
        }

        @keyframes winner-particle-float {
            0% {
                transform: translateY(0) translateX(0) scale(1);
                opacity: 1;
            }
            100% {
                transform: translateY(-120vh) translateX(var(--drift, 0)) scale(0.3);
                opacity: 0;
            }
        }

        .winner-celebration-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
            padding: 24px;
            text-align: center;
            animation: winner-content-entrance 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        @keyframes winner-content-entrance {
            from {
                opacity: 0;
                transform: scale(0.5) translateY(50px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .winner-main-title {
            font-size: clamp(32px, 8vw, 72px);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: linear-gradient(135deg, #b45309 0%, #dc2626 50%, #991b1b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
            animation: winner-title-glow 1.5s ease-in-out infinite alternate;
            line-height: 1.1;
        }

        @keyframes winner-title-glow {
            from {
                filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
                transform: scale(1);
            }
            to {
                filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.4));
                transform: scale(1.02);
            }
        }

        .winner-spotlight {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            /* Ensure circular container on mobile */
            width: fit-content;
            margin: 0 auto;
        }

        .winner-spotlight-glow {
            position: absolute;
            inset: -40px;
            background: radial-gradient(circle, rgba(251, 191, 36, 0.3) 0%, rgba(245, 158, 11, 0.15) 40%, transparent 70%);
            border-radius: 50%;
            animation: winner-spotlight-pulse 2s ease-in-out infinite;
            pointer-events: none;
            aspect-ratio: 1 / 1;
        }

        @keyframes winner-spotlight-pulse {
            0%, 100% {
                transform: scale(1);
                opacity: 0.7;
            }
            50% {
                transform: scale(1.2);
                opacity: 1;
            }
        }

        .winner-avatar {
            width: clamp(120px, 20vw, 180px);
            height: clamp(120px, 20vw, 180px);
            border-radius: 50%;
            border: 6px solid #d97706;
            box-shadow: 0 0 30px rgba(217, 119, 6, 0.5),
                        0 10px 40px rgba(0, 0, 0, 0.3);
            object-fit: cover;
            animation: winner-avatar-bounce 1s ease-in-out infinite;
            position: relative;
            z-index: 1;
            /* Force perfect circle on all devices */
            aspect-ratio: 1 / 1;
            flex-shrink: 0;
        }

        @keyframes winner-avatar-bounce {
            0%, 100% {
                transform: translateY(0) scale(1);
            }
            50% {
                transform: translateY(-10px) scale(1.05);
            }
        }

        .winner-name {
            font-family: var(--sf-font-gamer);
            font-variation-settings: var(--sf-fvs-gamer);
            font-style: italic;
            text-transform: uppercase;
            font-size: clamp(24px, 5vw, 48px);
            color: #1f2937;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            letter-spacing: 0.02em;
            animation: winner-name-shine 2s ease-in-out infinite;
        }

        @keyframes winner-name-shine {
            0%, 100% {
                color: #1f2937;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }
            50% {
                color: #0f172a;
                text-shadow: 0 3px 6px rgba(0, 0, 0, 0.15);
            }
        }

        .winner-submessage {
            font-size: clamp(14px, 3vw, 20px);
            font-weight: 600;
            color: #374151;
            background: rgba(254, 243, 199, 0.8);
            padding: 12px 24px;
            border-radius: 12px;
            border: 2px solid #d97706;
            backdrop-filter: blur(8px);
            max-width: 90%;
            animation: winner-submessage-fade 0.8s ease-out 0.4s both;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        @keyframes winner-submessage-fade {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .winner-action-display {
            font-size: clamp(12px, 2.5vw, 16px);
            font-family: 'Courier New', monospace;
            color: #6b7280;
            margin-top: 8px;
        }

        /* Winner Modal Name */
        #winNameModal {
            font-family: var(--sf-font-gamer) !important;
            font-variation-settings: var(--sf-fvs-gamer) !important;
            font-style: italic !important;
            text-transform: uppercase !important;
        }

        .winner-confetti-canvas {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 10;
        }

        /* Mobile optimizations */
        @media (max-width: 640px) {
            .winner-celebration-content {
                gap: 16px;
                padding: 16px;
            }
            
            .winner-main-title {
                font-size: clamp(28px, 10vw, 48px);
            }
            
            .winner-submessage {
                font-size: 14px;
                padding: 10px 16px;
            }
        }
    </style>
</head>

<body<?php echo $bootVeil ? ' class="sf-booting"' : ''; ?>>
    <!-- Floating Patterns Background -->
    <div class="floating-patterns">
        <div class="floating-circle floating-circle-1"></div>
        <div class="floating-circle floating-circle-2"></div>
        <div class="floating-circle floating-circle-3"></div>
        <div class="floating-circle floating-circle-4"></div>

        <svg class="floating-shape floating-shape-1" viewBox="0 0 100 100" fill="rgba(255, 179, 71, 0.2)">
            <circle cx="50" cy="50" r="40" />
        </svg>
        <svg class="floating-shape floating-shape-2" viewBox="0 0 100 100" fill="rgba(78, 205, 196, 0.25)">
            <rect x="10" y="10" width="80" height="80" rx="20" />
        </svg>
        <svg class="floating-shape floating-shape-3" viewBox="0 0 100 100" fill="rgba(216, 195, 245, 0.3)">
            <polygon points="50,10 90,90 10,90" />
        </svg>
        <svg class="floating-shape floating-shape-4" viewBox="0 0 100 100" fill="rgba(255, 179, 71, 0.2)">
            <circle cx="50" cy="50" r="40" />
        </svg>
    </div>

    <div class="main-container">
        <main class="main-content">
            <!-- Game Views Container - All views are siblings for proper JS toggling -->
            <div
                style="position: relative; width: 100%; min-height: 500px; display: flex; align-items: center; justify-content: center;">

                <?php if ($bootVeil): ?>
                <div class="sf-boot-veil" role="status" aria-live="polite">
                    <span class="sf-boot-veil__spinner" aria-hidden="true"></span>
                    <p class="sf-boot-veil__text">Sincronizando con el sorteo…</p>
                </div>
                <?php endif; ?>

                <!-- viewTimer: Waiting Room -->
                <div id="viewTimer">
                    <!-- Server Hash Chip (clickable → transparency modal) -->
                    <?php
                    $server_seed_hash = '';
                    $stmt_hash = $conn->prepare("SELECT server_seed_hash FROM raffles WHERE raffle_id = ?");
                    $stmt_hash->bind_param("i", $raffleId);
                    $stmt_hash->execute();
                    $hash_result = $stmt_hash->get_result()->fetch_assoc();
                    if ($hash_result && !empty($hash_result['server_seed_hash'])) {
                        $server_seed_hash = $hash_result['server_seed_hash'];
                    }
                    $stmt_hash->close();
                    ?>
                    <button type="button" class="server-hash" id="serverHash" onclick="openSecurityHashModal()"
                        aria-label="Ver código de seguridad del sorteo" title="Toca para ver la transparencia del sorteo">
                        <svg class="server-hash__shield" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                        <span class="server-hash__label">Hash de seguridad</span>
                        <span class="server-hash__value" id="serverHashValue"><?php echo $server_seed_hash !== '' ? htmlspecialchars($server_seed_hash) : 'Calculating...'; ?></span>
                        <svg class="server-hash__expand" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M11 8v6"/><path d="M8 11h6"/></svg>
                    </button>

                    <!-- Hidden navFairnessLabel for JS compatibility -->
                    <span id="navFairnessLabel" style="display: none;">Provably Fair</span>

                    <!-- Sorteo privado / exclusivo (solo invitados) -->
                    <div class="sf-badge sf-private-badge" data-tone="warning" id="sfPrivateBadge" role="note"<?php if (!$isPrivateRaffle) echo ' hidden'; ?>>
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        <span>Sorteo privado · Solo invitados</span>
                    </div>

                    <!-- Title -->
                    <h1 class="page-title">¡SALA DE ESPERA!</h1>

                    <!-- Pot Display (Legacy) -->
                    <div id="potContainer"
                        style="display: none; justify-content: center; gap: 12px; margin-bottom: 16px;">
                        <div id="potBadge"
                            style="background: rgba(0,0,0,0.4); padding: 4px 12px; border-radius: 8px; border: 1px solid rgba(107,114,128,0.5); display: flex; align-items: center; gap: 8px;">
                            <img class="sf-inline-coin" src="/assets/img/moneda.webp" width="18" height="18" alt="Bananos">
                            <span style="color: #fef3c7; font-weight: bold;" id="potDisplay">0</span>
                        </div>
                        <div id="costBadge"
                            style="background: rgba(0,0,0,0.4); padding: 4px 12px; border-radius: 8px; border: 1px solid rgba(107,114,128,0.5); display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-ticket" style="color: #60a5fa;"></i>
                            <span style="color: #dbeafe; font-weight: bold; font-size: 12px;"
                                id="costDisplay">...</span>
                        </div>
                    </div>

                    <!-- Timer + Aforo: Bulletproof vertical stack -->
                    <div class="sf-timer-stack">
                        <!-- Countdown Timer -->
                        <div class="wr-timer" id="wrTimerContainer">
                            <div class="wr-timer__segment">
                                <span class="wr-timer__value" id="wr-min">--</span>
                                <span class="wr-timer__unit">MIN</span>
                            </div>
                            <span class="wr-timer__colon">:</span>
                            <div class="wr-timer__segment">
                                <span class="wr-timer__value" id="wr-sec">--</span>
                                <span class="wr-timer__unit">SEG</span>
                            </div>
                        </div>
                        <!-- Aforo Badge (participant limit indicator) -->
                        <div id="aforoPill" class="aforo-badge" style="display: none;">
                            <svg class="aforo-badge__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <span class="aforo-badge__label">Cupos</span>
                            <span class="aforo-badge__numbers">
                                <span id="aforoCount" class="aforo-badge__count">0</span>
                                <span class="aforo-badge__sep">/</span>
                                <span id="aforoTotal" class="aforo-badge__total">0</span>
                            </span>
                        </div>
                    </div>

                    <!-- Roulette Wheel (Static SVG with CSS Animation) -->
                    <div class="roulette-container">
                        <div class="roulette-glow"></div>

                        <svg class="roulette-wheel" viewBox="0 0 200 200">
                            <!-- Segment 1 - Red -->
                            <path d="M 100 100 L 100 10 A 90 90 0 0 1 163.64 36.36 Z" fill="#FF6B6B" stroke="white"
                                stroke-width="2" />
                            <!-- Segment 2 - Teal -->
                            <path d="M 100 100 L 163.64 36.36 A 90 90 0 0 1 190 100 Z" fill="#4ECDC4" stroke="white"
                                stroke-width="2" />
                            <!-- Segment 3 - Yellow -->
                            <path d="M 100 100 L 190 100 A 90 90 0 0 1 163.64 163.64 Z" fill="#FFE66D" stroke="white"
                                stroke-width="2" />
                            <!-- Segment 4 - Mint -->
                            <path d="M 100 100 L 163.64 163.64 A 90 90 0 0 1 100 190 Z" fill="#95E1D3" stroke="white"
                                stroke-width="2" />
                            <!-- Segment 5 - Salmon -->
                            <path d="M 100 100 L 100 190 A 90 90 0 0 1 36.36 163.64 Z" fill="#F38181" stroke="white"
                                stroke-width="2" />
                            <!-- Segment 6 - Lavender -->
                            <path d="M 100 100 L 36.36 163.64 A 90 90 0 0 1 10 100 Z" fill="#AA96DA" stroke="white"
                                stroke-width="2" />
                            <!-- Segment 7 - Pink -->
                            <path d="M 100 100 L 10 100 A 90 90 0 0 1 36.36 36.36 Z" fill="#FCBAD3" stroke="white"
                                stroke-width="2" />
                            <!-- Segment 8 - Sky Blue -->
                            <path d="M 100 100 L 36.36 36.36 A 90 90 0 0 1 100 10 Z" fill="#A8D8EA" stroke="white"
                                stroke-width="2" />

                            <!-- Center Circle -->
                            <circle cx="100" cy="100" r="12" fill="white" stroke="#E0E0E0" stroke-width="2" />
                        </svg>

                        <button class="roulette-center-prize" onclick="openPrizeModal()" aria-label="Ver Premio">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                        </button>

                        <div class="roulette-pointer">
                            <div class="roulette-pointer-triangle"></div>
                        </div>

                        <!-- Decorative Dots -->
                        <div class="roulette-dots" style="left: 50%; top: 2%;"></div>
                        <div class="roulette-dots" style="left: 86.36%; top: 13.64%;"></div>
                        <div class="roulette-dots" style="left: 98%; top: 50%;"></div>
                        <div class="roulette-dots" style="left: 86.36%; top: 86.36%;"></div>
                        <div class="roulette-dots" style="left: 50%; top: 98%;"></div>
                        <div class="roulette-dots" style="left: 13.64%; top: 86.36%;"></div>
                        <div class="roulette-dots" style="left: 2%; top: 50%;"></div>
                        <div class="roulette-dots" style="left: 13.64%; top: 13.64%;"></div>
                        <div class="roulette-dots" style="left: 70.71%; top: 29.29%;"></div>
                        <div class="roulette-dots" style="left: 70.71%; top: 70.71%;"></div>
                        <div class="roulette-dots" style="left: 29.29%; top: 70.71%;"></div>
                        <div class="roulette-dots" style="left: 29.29%; top: 29.29%;"></div>
                    </div>

                    <!-- Participant Counter -->
                    <div class="participant-counter">
                        <i class="fas fa-users participant-counter-icon"></i>
                        <span class="participant-counter-text">
                            <span id="participants-count">0</span> Participantes Inscritos
                        </span>
                    </div>

                    <!-- Join Button -->
                    <div class="join-button-wrapper">
                        <button id="join-btn" class="join-button">
                            ¡ENTRAR AL SORTEO GRATIS!
                        </button>
                    </div>

                    <!-- Back to Shop — Desktop: top-left floating icon (hidden on mobile) -->
                    <nav class="wt-topbar" aria-label="Navegación">
                        <a href="/" class="wt-topbar-back" aria-label="Volver a la tienda" title="Volver a la tienda">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                            <span class="wt-topbar-back__text">Volver a Tienda</span>
                        </a>
                    </nav>


                    <!-- Back to Shop — Mobile: bottom pill (hidden on desktop) -->
                    <a href="/" class="wt-back-mobile">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                        Volver a Tienda
                    </a>

                    <!-- View Participants Button (Mobile Only) -->
                    <button onclick="toggleParticipantsModal()" class="view-participants-btn" id="viewParticipantsBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span class="view-participants-label">Ver</span>
                        <span id="partCountMob" class="view-participants-count">0</span>
                        <span class="view-participants-label">Participantes</span>
                    </button>

                    <!-- Mobile Exit Button -->
                    <a href="/" class="wt-exit-mobile">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                        Volver a Tienda
                    </a>
                </div>

                <!-- viewLocking: Blockchain Locking View (Provably Fair Target Block) -->
                <div id="viewLocking" class="hidden">
                    <div class="locking-container">
                        <!-- Animated Blockchain Scanner -->
                        <div class="locking-scanner">
                            <div class="locking-scanner-ring"></div>
                            <div class="locking-scanner-ring locking-scanner-ring--delay"></div>
                            <div class="locking-scanner-core">
                                <i class="fas fa-cube"></i>
                            </div>
                        </div>

                        <!-- Main Title -->
                        <h1 class="locking-title">¡Inscripciones Cerradas!</h1>
                        <p class="locking-subtitle">Fijando destino en la Blockchain<span class="locking-dots"><span>.</span><span>.</span><span>.</span></span></p>

                        <!-- Block Race Card -->
                        <div class="locking-block-card">
                            <div class="locking-block-card-glow"></div>

                            <!-- Current vs Target -->
                            <div class="locking-race">
                                <div class="locking-race-col">
                                    <div class="locking-race-label">Bloque Actual</div>
                                    <div class="locking-race-number locking-race-number--current" id="lockingBlockCurrent">
                                        <i class="fas fa-cube locking-race-icon"></i>
                                        <span id="lockingBlockCurrentVal">#--------</span>
                                    </div>
                                </div>
                                <div class="locking-race-arrow">
                                    <i class="fas fa-angles-right"></i>
                                </div>
                                <div class="locking-race-col">
                                    <div class="locking-race-label locking-race-label--target">Objetivo</div>
                                    <div class="locking-race-number locking-race-number--target" id="lockingBlockTarget">
                                        <i class="fas fa-flag-checkered locking-race-icon"></i>
                                        <span id="lockingBlockTargetVal">#--------</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Progress Bar -->
                            <div class="locking-progress">
                                <div class="locking-progress-track">
                                    <div class="locking-progress-fill" id="lockingProgressFill" style="width: 0%"></div>
                                    <div class="locking-progress-glow" id="lockingProgressGlow" style="left: 0%"></div>
                                </div>
                                <div class="locking-progress-label">
                                    <span id="lockingBlocksLeft">-- bloques restantes</span>
                                </div>
                            </div>

                            <div class="locking-block-hint">El hash del bloque objetivo determinará al ganador</div>
                        </div>

                        <!-- Server Hash Sealed Badge -->
                        <div class="locking-seal">
                            <div class="locking-seal-icon">
                                <i class="fas fa-lock"></i>
                            </div>
                            <div class="locking-seal-info">
                                <div class="locking-seal-label">Server Seed <span class="locking-seal-badge">SELLADO</span></div>
                                <div class="locking-seal-hash" id="lockingSeedHash">---</div>
                            </div>
                            <div class="locking-seal-tooltip">
                                <i class="fas fa-info-circle"></i>
                                <div class="locking-seal-tooltip-text">Secreto sellado antes de iniciar el sorteo. Se revelará al terminar para que puedas verificar la justicia del resultado.</div>
                            </div>
                        </div>

                        <!-- Participant Count -->
                        <div class="locking-participants">
                            <i class="fas fa-users"></i>
                            <span id="lockingParticipants">0</span> participantes compiten
                        </div>

                        <!-- Expelled tickets (transparency, pre-resolution) -->
                        <div class="locking-expelled hidden" id="lockingExpelled" aria-live="polite">
                            <div class="locking-expelled-head">
                                <i class="fas fa-user-slash"></i>
                                <span id="lockingExpelledCount">0</span> <span id="lockingExpelledLabel">tickets expulsados</span>
                            </div>
                            <div class="locking-expelled-chips" id="lockingExpelledChips"></div>
                        </div>
                    </div>
                </div>

                <!-- viewProcessing: Processing View -->
                <div id="viewProcessing" class="hidden"
                    style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 50; background: rgba(0, 0, 0, 0.3); backdrop-filter: blur(8px);">
                    <i class="fas fa-circle-notch fa-spin" style="font-size: 48px; color: #eab308;"></i>
                    <div style="margin-top: 8px; font-size: 14px; color: #eab308; font-weight: bold;">Sorteando!</div>
                </div>

                <!-- viewWinner: EPIC Winner Celebration (4-second extravaganza) -->
                <div id="viewWinner" class="hidden winner-celebration-overlay">
                    <!-- Animated Background -->
                    <div class="winner-celebration-bg"></div>
                    
                    <!-- Particle System -->
                    <div class="winner-particles" id="winnerParticles"></div>
                    
                    <!-- Main Content -->
                    <div class="winner-celebration-content">
                        <!-- Main Title (Dynamic) -->
                        <div class="winner-main-title" id="winnerMainTitle">¡NUESTRO GANADOR!</div>
                        
                        <!-- Winner Spotlight -->
                        <div class="winner-spotlight">
                            <div class="winner-spotlight-glow"></div>
                            <img id="winAvatarMain" src="" class="winner-avatar">
                            <div class="winner-name" id="winnerNameDisplay">???</div>
                        </div>
                        
                        <!-- Sub-message (for multiple winners) -->
                        <div class="winner-submessage" id="winnerSubmessage"></div>
                        
                        <!-- Action Display -->
                        <div id="winActionDisplay" class="winner-action-display"></div>
                    </div>
                    
                    <!-- Confetti Canvas (will be populated by JS) -->
                    <canvas id="winnerConfettiCanvas" class="winner-confetti-canvas"></canvas>
                </div>

                <!-- viewResults: Results View -->
                <div id="viewResults" class="hidden trophy-room">
                    <div class="tr-bg"></div>

                    <div class="tr-shapes" aria-hidden="true">
                        <div class="tr-shape tr-shape-1"></div>
                        <div class="tr-shape tr-shape-2"></div>
                        <div class="tr-shape tr-shape-3"></div>
                        <div class="tr-shape tr-shape-4"></div>
                        <div class="tr-shape tr-shape-5"></div>
                        <div class="tr-shape tr-shape-6"></div>
                    </div>

                    <!-- Floating nav pill — top-left (desktop only, hidden on mobile) -->
                    <nav class="tr-topbar" aria-label="Navegación">
                        <a href="/" class="tr-topbar-back">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                            Volver a la Tienda
                        </a>
                    </nav>

                    <div class="tr-content">
                        <div class="tr-shell">
                            <header class="tr-header">
                                <div class="tr-crown" aria-hidden="true">
                                    <svg class="tr-crown-svg" viewBox="0 0 64 40" fill="none"><path d="M4 36L12 12L24 28L32 4L40 28L52 12L60 36H4Z" fill="url(#crownGrad)" stroke="url(#crownStroke)" stroke-width="2.5" stroke-linejoin="round"/><defs><linearGradient id="crownGrad" x1="4" y1="4" x2="60" y2="36"><stop offset="0%" stop-color="#fbbf24"/><stop offset="50%" stop-color="#f59e0b"/><stop offset="100%" stop-color="#d97706"/></linearGradient><linearGradient id="crownStroke" x1="4" y1="4" x2="60" y2="36"><stop offset="0%" stop-color="#fde68a"/><stop offset="100%" stop-color="#b45309"/></linearGradient></defs></svg>
                                </div>
                                <h1 class="tr-title">¡TENEMOS GANADORES!</h1>
                                <p class="tr-subtitle">Felicidades a nuestros afortunados participantes</p>
                            </header>

                            <section id="resultsWinners" class="tr-winners" aria-label="Ganadores"></section>

                            <footer class="tr-actions">
                                <button id="btnVerifyResults" class="tr-action-pill tr-action-pill--audit" onclick="openFairnessModal()" type="button">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                    <span class="tr-action-pill-label">Auditoría<span class="tr-hide-mobile"> &amp; Blockchain</span></span>
                                </button>
                                <button class="tr-action-pill tr-action-pill--scenarios" type="button" onclick="openScenariosModal()">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 3v2"/><path d="M12 19v2"/><path d="M3 12h2"/><path d="M19 12h2"/><path d="M5.6 5.6l1.4 1.4"/><path d="M17 17l1.4 1.4"/><path d="M5.6 18.4L7 17"/><path d="M17 7l1.4-1.4"/></svg>
                                    <span class="tr-action-pill-label"><span class="tr-hide-mobile">Ver </span>Suplentes</span>
                                </button>
                                <button class="tr-action-pill tr-action-pill--users" type="button" onclick="toggleParticipantsModal()">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                    <span class="tr-action-pill-label">Ver Participantes</span>
                                </button>
                                <a href="/" class="tr-action-pill tr-action-pill--back">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                                    <span class="tr-action-pill-label">Volver a la Tienda</span>
                                </a>
                            </footer>
                        </div>
                        <a href="/" class="wt-exit-mobile tr-mobile-store-cta">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                            Volver a la Tienda
                        </a>
                    </div>
                </div>

            </div>
            <div id="hudContainer" class="hidden decision-board"></div>
            <!-- Floating thematic icons: STATIC layer, never re-rendered by JS polling -->
            <div id="dbFloatingLayer" class="db-floating-icons hidden" aria-hidden="true">
                <span class="db-float db-float--1">🎁</span>
                <span class="db-float db-float--2"><img class="sf-inline-coin sf-inline-coin--float" src="/assets/img/moneda.webp" alt="" aria-hidden="true"></span>
                <span class="db-float db-float--3">🔥</span>
                <span class="db-float db-float--4">×2</span>
                <span class="db-float db-float--5">💀</span>
            </div>
        </main>

        <!-- Desktop Sidebar: Participants List -->
        <aside class="participants-sidebar">
            <div class="participants-header">
                <h2 class="participants-title">Participantes</h2>
                <span class="participants-count" id="partCountDesk">0</span>
            </div>
            <div id="participants-list" class="participants-list">
                <!-- Populated by JavaScript -->
            </div>
        </aside>
    </div>

    <!-- Ver Suplentes: simulador post-sorteo de la cadena de suplentes -->
    <div id="scenariosModal" class="sf-scn-overlay hidden" role="dialog" aria-modal="true" aria-label="Ver Suplentes"></div>

    <!-- Mobile Modal: Participants List -->
    <div id="participants-modal" class="participants-modal">
        <div class="participants-modal-backdrop" onclick="toggleParticipantsModal()"></div>
        <div class="participants-modal-sheet">
            <div class="participants-modal-header">
                <h2 class="participants-modal-title" id="pmModalTitle">PARTICIPANTES (0)</h2>
                <button class="participants-modal-close" onclick="toggleParticipantsModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div id="participants-list-mobile" class="participants-modal-content">
                <!-- Populated by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Prize Showcase Modal — clon estricto del lightbox de producto del checkout -->
    <div id="prizeShowcaseModal" class="lightbox-overlay hidden" onclick="if(event.target===this)closePrizeModal()">
        <div class="lightbox-card">
            <div class="relative">
                <div id="lightbox-img-wrap" class="lightbox-img-wrap"><img id="lightbox-img" src="" alt="" class="lb-img"></div>
                <button id="close-lightbox" type="button" class="lightbox-close" onclick="closePrizeModal()" aria-label="Cerrar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="lb-body">
                <div class="lb-head-row">
                    <h3 id="lb-name" class="font-heading lb-title">Premio</h3>
                    <div class="lb-price-col">
                        <p id="lb-price" class="font-num lb-price num">S/ 0.00</p>
                        <p id="lb-base" class="lb-base line-through hidden num">S/ 0.00</p>
                    </div>
                </div>
                <div class="lb-meta-row">
                    <span class="lb-prize-flag">Premio en Juego</span>
                    <span id="lb-vbucks-wrap" class="lb-vbucks hidden num"><span class="pavos-ico"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M12 22.5C17.799 22.5 22.5 17.799 22.5 12C22.5 6.20101 17.799 1.5 12 1.5C6.20101 1.5 1.5 6.20101 1.5 12C1.5 17.799 6.20101 22.5 12 22.5ZM10.1051 7.5H6L9.31376 16.5H14.6306L18 7.5H13.8887L12.0896 13.7038L12.0031 14.0427H11.9784L11.9042 13.7038L10.1051 7.5Z"/></svg></span><span id="lb-vbucks"></span></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Security Hash Transparency Modal (Waiting Room) -->
    <div id="securityHashModal" class="sh-overlay hidden">
        <div class="sh-backdrop" onclick="closeSecurityHashModal()"></div>
        <div class="sh-container">
            <div class="sh-card">
                <div class="sh-glow"></div>
                <button type="button" class="sh-close" onclick="closeSecurityHashModal()" aria-label="Cerrar">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
                </button>
                <div class="sh-icon">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                </div>
                <h2 class="sh-title">Sello de transparencia</h2>
                <p class="sh-subtitle">Este es el código de seguridad <strong>inmutable</strong> del sorteo.</p>

                <div class="sh-hash-box">
                    <span class="sh-hash-label">Server Seed Hash (SHA-256)</span>
                    <code class="sh-hash-value" id="securityHashValue"><?php echo $server_seed_hash !== '' ? htmlspecialchars($server_seed_hash) : 'Calculando...'; ?></code>
                    <button type="button" class="sh-copy" id="securityHashCopy" onclick="copySecurityHash()">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        <span>Copiar</span>
                    </button>
                </div>

                <div class="sh-tip">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
                    <span><strong>¡Tómale una captura!</strong> Con esto demostramos total transparencia desde el inicio hasta el fin del sorteo.</span>
                </div>

                <button type="button" class="sh-done" onclick="closeSecurityHashModal()">Entendido</button>
            </div>
        </div>
    </div>

    <!-- Hidden Elements for Legacy Compatibility -->
    <div id="countdown" style="display:none;">--:--</div>
    <div id="potDisplay" style="display:none;">0</div>
    <div id="costDisplay" style="display:none;">...</div>

    <div id="participantsModal" class="fixed inset-0 z-50 bg-black/95 hidden flex flex-col p-6">
        <div class="flex justify-between items-center mb-6">
            <h3 class="participants-title" style="color:#fff;font-size:20px;">Participantes</h3>
            <button onclick="toggleParticipantsModal()"
                class="w-10 h-10 rounded-full bg-gray-800 text-white flex items-center justify-center"><i
                    class="fas fa-times"></i></button>
        </div>
        <div id="participantsListMob" class="flex-1 overflow-y-auto custom-scroll space-y-2"></div>
    </div>

    <!-- Decision Alert Toasts (peer pressure notifications) -->
    <div id="decisionAlertContainer" class="decision-alert-container"></div>

    <!-- ============================================================ -->
    <!-- CINEMATIC TRANSITION OVERLAY (Decision Recap + DOBLE O NADA) -->
    <!-- ============================================================ -->
    <div id="cinematicOverlay" class="cinematic-overlay hidden">
        <!-- Scene 1: Decision Recap -->
        <div id="cinematicScene1" class="cinematic-scene cinematic-scene--recap hidden">
            <div class="cinematic-recap-bg"></div>
            <div class="cinematic-recap-content">
                <div class="cinematic-recap-header">
                    <div class="cinematic-recap-badge">RESULTADOS</div>
                    <h1 class="cinematic-recap-title" id="cinematicRecapTitle">Decisiones Tomadas</h1>
                </div>
                <div id="cinematicRecapCards" class="cinematic-recap-cards">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>

        <!-- Scene 2: DOBLE O NADA - Mortal Kombat Transition -->
        <div id="cinematicScene2" class="cinematic-scene cinematic-scene--kombat hidden">
            <div class="cinematic-kombat-bg"></div>
            <div class="cinematic-kombat-flash" id="cinematicFlash"></div>
            <div class="cinematic-kombat-particles" id="cinematicParticles"></div>
            <div class="cinematic-kombat-content">
                <div class="cinematic-kombat-text" id="cinematicKombatText">
                    <span class="cinematic-kombat-line1">DOBLE</span>
                    <span class="cinematic-kombat-line2">O</span>
                    <span class="cinematic-kombat-line3">NADA!</span>
                </div>
                <div class="cinematic-kombat-subtitle" id="cinematicKombatSub">¿Multiplicas tu premio o te vas en cero?</div>
            </div>
            <div class="cinematic-kombat-vignette"></div>
        </div>
    </div>

    <div id="mainAnimOverlay"
        class="fixed inset-0 z-[60] bg-black/95 backdrop-blur-xl hidden flex flex-col items-center justify-center">
        <div class="tr-bg"></div>
        <div class="tr-shapes" aria-hidden="true">
            <div class="tr-shape tr-shape-1"></div>
            <div class="tr-shape tr-shape-2"></div>
            <div class="tr-shape tr-shape-3"></div>
            <div class="tr-shape tr-shape-4"></div>
            <div class="tr-shape tr-shape-5"></div>
            <div class="tr-shape tr-shape-6"></div>
        </div>
        <div class="text-center mb-10 relative z-10">
            <h2 id="animTitle"
                class="text-4xl font-black text-yellow-500 drop-shadow-[0_0_15px_rgba(234,179,8,0.8)] uppercase tracking-widest mb-2 animate-pulse">
                Sorteando!</h2>
        </div>

        <div id="gambleOverlay"
            class="fixed inset-0 z-[70] bg-black/95 backdrop-blur-xl hidden flex flex-col items-center justify-center">

            <!-- Gamble Block Race: 1:1 clone of #viewLocking -->
            <div id="gambleBlockRace" class="hidden">
                <div class="locking-container">
                    <!-- Animated Blockchain Scanner -->
                    <div class="locking-scanner">
                        <div class="locking-scanner-ring"></div>
                        <div class="locking-scanner-ring locking-scanner-ring--delay"></div>
                        <div class="locking-scanner-core">
                            <i class="fas fa-cube"></i>
                        </div>
                    </div>

                    <!-- Main Title -->
                    <h1 class="locking-title">¡DOBLE O NADA!</h1>
                    <p class="locking-subtitle">Esperando resultado en la Blockchain<span class="locking-dots"><span>.</span><span>.</span><span>.</span></span></p>

                    <!-- Block Race Card -->
                    <div class="locking-block-card">
                        <div class="locking-block-card-glow"></div>

                        <!-- Current vs Target -->
                        <div class="locking-race">
                            <div class="locking-race-col">
                                <div class="locking-race-label">Bloque Actual</div>
                                <div class="locking-race-number locking-race-number--current" id="gambleBlockCurrentNum">
                                    <i class="fas fa-cube locking-race-icon"></i>
                                    <span id="gambleBlockCurrentVal">#--------</span>
                                </div>
                            </div>
                            <div class="locking-race-arrow">
                                <i class="fas fa-angles-right"></i>
                            </div>
                            <div class="locking-race-col">
                                <div class="locking-race-label locking-race-label--target">Objetivo</div>
                                <div class="locking-race-number locking-race-number--target" id="gambleBlockTargetNum">
                                    <i class="fas fa-flag-checkered locking-race-icon"></i>
                                    <span id="gambleBlockTargetVal">#--------</span>
                                </div>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="locking-progress">
                            <div class="locking-progress-track">
                                <div class="locking-progress-fill" id="gambleProgressFill" style="width: 0%"></div>
                                <div class="locking-progress-glow" id="gambleProgressGlow" style="left: 0%"></div>
                            </div>
                            <div class="locking-progress-label">
                                <span id="gambleBlocksLeft">-- bloques restantes</span>
                            </div>
                        </div>

                        <div class="locking-block-hint">El hash del bloque objetivo determinará el resultado</div>
                    </div>

                    <!-- Server Hash Sealed Badge (Gamble) -->
                    <div class="locking-seal gamble-seal" id="gambleSealContainer">
                        <div class="locking-seal-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div class="locking-seal-info">
                            <div class="locking-seal-label">Server Hash <span class="locking-seal-badge">SELLADO</span></div>
                            <div class="locking-seal-hash" id="gambleSealHash">---</div>
                        </div>
                        <div class="locking-seal-tooltip">
                            <i class="fas fa-info-circle"></i>
                            <div class="locking-seal-tooltip-text">Hash del servidor generado antes de conocer el resultado. Se revelará la semilla al finalizar para verificar la transparencia.</div>
                        </div>
                    </div>

                    <!-- Gamblers: The Brave Ones -->
                    <div id="gambleGamblersList" class="gamble-gamblers">
                        <div class="gamble-gamblers-title" id="gambleGamblersTitle">
                            <i class="fas fa-fire"></i>
                            <span id="gambleGamblersTitleText">¡Se la están jugando por el Doble o Perderlo Todo!</span>
                        </div>
                        <div id="gambleGamblersAvatars" class="gamble-gamblers-avatars">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                </div>
            </div>

            <div id="sceneCoin" class="coin-container">
                <div class="coin" id="theCoin">
                    <div class="coin-face coin-front">x2</div>
                    <div class="coin-face coin-back">Nada</div>
                </div>
            </div>
        </div>

        <div id="animWheelContainer" class="hidden relative scale-110 z-[80]">
            <div class="wheel-arrow"></div>
            <div class="wheel-outer">
                <div class="wheel-spinner" id="mainWheel"></div>
            </div>
        </div>

        <div id="animSlotContainer"
            class="hidden relative z-[80] w-full flex flex-col sm:flex-row items-center justify-center gap-6 px-4">
            <div class="slot-ui-bg" aria-hidden="true"></div>

            <div class="slot-ui-inner">
                <div class="slot-ui-machine" id="slotUi1">
                    <div id="slot-machine" class="slot-machine-casing">
                        <div class="lever-container-fixed" aria-hidden="true">
                            <div class="lever-connector"></div>
                            <div class="lever-arm-fixed"></div>
                        </div>

                        <div class="slot-ui-bulbs" aria-hidden="true">
                            <div class="bulb cyan"></div>
                            <div class="bulb magenta"></div>
                            <div class="bulb yellow"></div>
                            <div class="bulb green"></div>
                            <div class="bulb cyan"></div>
                            <div class="bulb magenta"></div>
                        </div>

                        <div class="reel-container">
                            <div id="reel-viewport" class="reel-viewport">
                                <div class="slot-ui-fade slot-ui-fade-top" aria-hidden="true"></div>
                                <div class="slot-ui-fade slot-ui-fade-bottom" aria-hidden="true"></div>

                                <div id="center-line" class="laser-container-line" aria-hidden="true">
                                    <div class="laser-diamond"></div>
                                    <div class="laser-line"></div>
                                    <div class="laser-diamond"></div>
                                </div>

                                <div id="reel-strip" class="reel-strip"></div>
                            </div>
                        </div>

                        <div class="slot-ui-base" aria-hidden="true">
                            <div class="slot-ui-base-bar"></div>
                        </div>
                    </div>
                </div>

                <div id="fastBatchSlot2" class="hidden slot-ui-machine">
                    <div id="slot-machine-2" class="slot-machine-casing">
                        <div class="lever-container-fixed" aria-hidden="true">
                            <div class="lever-connector"></div>
                            <div class="lever-arm-fixed"></div>
                        </div>

                        <div class="slot-ui-bulbs" aria-hidden="true">
                            <div class="bulb cyan"></div>
                            <div class="bulb magenta"></div>
                            <div class="bulb yellow"></div>
                            <div class="bulb green"></div>
                            <div class="bulb cyan"></div>
                            <div class="bulb magenta"></div>
                        </div>

                        <div class="reel-container">
                            <div id="reel-viewport-2" class="reel-viewport">
                                <div class="slot-ui-fade slot-ui-fade-top" aria-hidden="true"></div>
                                <div class="slot-ui-fade slot-ui-fade-bottom" aria-hidden="true"></div>

                                <div id="center-line-2" class="laser-container-line" aria-hidden="true">
                                    <div class="laser-diamond"></div>
                                    <div class="laser-line"></div>
                                    <div class="laser-diamond"></div>
                                </div>

                                <div id="reel-strip-2" class="reel-strip"></div>
                            </div>
                        </div>

                        <div class="slot-ui-base" aria-hidden="true">
                            <div class="slot-ui-base-bar"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="decisionModal" class="dm-overlay hidden">
        <div id="decisionModalBody" class="dm-body modal-enter">
            <!-- Confetti burst decoration -->
            <div class="dm-confetti" aria-hidden="true">
                <span class="dm-confetti-dot" style="--dx:-60px;--dy:-80px;--r:18deg;background:#FFB347;"></span>
                <span class="dm-confetti-dot" style="--dx:55px;--dy:-70px;--r:-25deg;background:#4ECDC4;"></span>
                <span class="dm-confetti-dot" style="--dx:-45px;--dy:-55px;--r:40deg;background:#FF6B6B;"></span>
                <span class="dm-confetti-dot" style="--dx:40px;--dy:-90px;--r:-10deg;background:#AA96DA;"></span>
                <span class="dm-confetti-dot" style="--dx:70px;--dy:-50px;--r:30deg;background:#FFE66D;"></span>
                <span class="dm-confetti-dot" style="--dx:-70px;--dy:-45px;--r:-35deg;background:#95E1D3;"></span>
            </div>

            <!-- Ring Timer -->
            <div class="dm-timer-ring" aria-label="Tiempo restante">
                <svg class="dm-timer-svg" viewBox="0 0 120 120">
                    <circle class="dm-timer-track" cx="60" cy="60" r="52" />
                    <circle class="dm-timer-fill" id="dmTimerCircle" cx="60" cy="60" r="52" />
                </svg>
                <div class="dm-timer-label">
                    <span class="dm-timer-seconds" id="decisionTimer">60</span>
                    <span class="dm-timer-unit">seg</span>
                </div>
            </div>

            <!-- Title -->
            <h2 class="dm-title">¡Eres el ganador!</h2>
            <p class="dm-subtitle">Elige qué hacer con tu premio</p>
            <!-- Homologado con el modal de pre-elección: Premio Original, Cashback y el dado de Doble o Nada -->
            <div class="dm-cards dm-cards--homolog">
                <!-- Card 1: Premio Original -->
                <button id="btnOriginal" class="dm-card dm-card--claim" onclick="window.sendDecision('original')">
                    <div class="dm-card__icon dm-card__icon--prize" id="dmPrizeIcon">
                        <span class="dm-card__emoji">&#127873;</span>
                    </div>
                    <div class="dm-card__text">
                        <div class="dm-card__label">Premio Original</div>
                        <div class="dm-card__desc">Llévate el premio tal cual a tu inventario.</div>
                    </div>
                    <span class="dm-card__badge dm-card__badge--claim">Seguro</span>
                    <span class="dm-card__chev" aria-hidden="true">&#8250;</span>
                </button>

                <!-- Card 2: Cashback -->
                <button id="btnCashback" class="dm-card dm-card--exchange hidden"
                    onclick="window.sendDecision('cashback')">
                    <div class="dm-card__icon dm-card__icon--coin" id="dmCashbackIcon">
                        <img class="sf-bananos-img"
                            src="/assets/img/moneda.webp"
                            alt="Bananos" loading="lazy">
                    </div>
                    <div class="dm-card__text">
                        <div class="dm-card__label">Cashback <span id="lblCashbackVal">50</span>%</div>
                        <div class="dm-card__desc" id="dmCashbackDesc">Recibe el valor del premio en Bananos de forma segura.</div>
                    </div>
                    <span class="dm-card__badge dm-card__badge--exchange">Flexible</span>
                    <span class="dm-card__chev" aria-hidden="true">&#8250;</span>
                </button>

                <!-- Card 3: Doble o Nada (el dado) -->
                <button id="btnGamble" class="dm-card dm-card--gamble hidden" onclick="window.sendDecision('gamble')">
                    <div class="dm-card__icon dm-card__icon--dice">
                        <span class="dm-card__emoji">&#127922;</span>
                    </div>
                    <div class="dm-card__text">
                        <div class="dm-card__label">Doble o Nada · 50/50</div>
                        <div class="dm-card__desc" id="dmGambleDesc"><strong>50%</strong> de duplicar <strong id="dmGambleBonus">tu premio</strong>… o perderlo todo.</div>
                    </div>
                    <span class="dm-card__badge dm-card__badge--gamble">Arriesgado</span>
                    <span class="dm-card__chev" aria-hidden="true">&#8250;</span>
                </button>
            </div>
        </div>
    </div>

    <div id="winnerModal" class="fixed inset-0 z-[90] flex items-center justify-center bg-black/95 backdrop-blur-xl hidden p-4">
        <div class="bg-gray-900 border border-yellow-500/30 rounded-3xl p-8 max-w-sm w-full text-center relative modal-enter">
            <div
                class="absolute -top-12 left-1/2 transform -translate-x-1/2 bg-gray-900 rounded-full p-4 border border-yellow-500/30">
                <i class="fas fa-trophy text-5xl text-yellow-500"></i>
            </div>
            <h2 class="text-2xl font-black text-white mt-8 mb-6">¡Victoria!</h2>
            <img id="winAvatarModal" src="" class="w-20 h-20 rounded-full mx-auto mb-3 border-2 border-yellow-500/50">
            <div id="winNameModal" class="text-xl font-bold text-yellow-400 mb-4">...</div>
            <button onclick="closeWinnerModal()"
                class="w-full bg-gray-800 hover:bg-gray-700 text-white font-bold py-3 rounded-xl">Cerrar</button>
        </div>
    </div>

    <div id="fairnessModal"
        class="fixed inset-0 z-[90] flex items-center justify-center bg-black/95 backdrop-blur-xl hidden p-4">
        <div
            class="bg-gray-900 border border-gray-700 rounded-2xl w-full max-w-lg p-6 relative overflow-y-auto max-h-[90vh]">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-white flex items-center gap-2"><i
                        class="fas fa-shield-halved text-green-500"></i> Auditoría de Justicia</h3>
                <button onclick="closeFairnessModal()"
                    class="w-8 h-8 rounded-full bg-gray-800 text-white flex items-center justify-center"><i
                        class="fas fa-times"></i></button>
            </div>

            <div class="space-y-5">
                <p style="color: #9ca3af; font-size: 13px; line-height: 1.5; margin-top: -6px;">
                    The winner is selected via a Provably Fair process using the Server Seed and the BSC Block Hash as
                    immutable randomness sources.
                </p>
                <div class="bg-black/30 p-4 rounded-2xl border border-gray-800">
                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Sorteo Principal</h4>
                    <div class="space-y-3">
                        <div>
                            <div class="text-[10px] text-gray-500 font-mono mb-1">SERVER SEED HASH (SHA256)</div>
                            <div class="bg-black p-2 rounded border border-gray-800 text-[10px] font-mono text-yellow-500 break-all select-all"
                                id="fmServerHash">...</div>
                            <div class="text-[9px] text-gray-600 mt-1">Este hash fue generado antes de iniciar el
                                sorteo.</div>
                        </div>

                        <div id="fmRevealedSection" class="hidden space-y-3">
                            <div>
                                <div class="text-[10px] text-gray-500 font-mono mb-1">SERVER SEED SECRET (REVEALED)
                                </div>
                                <div class="bg-green-900/20 p-2 rounded border border-green-500/30 text-[10px] font-mono text-green-400 break-all select-all"
                                    id="fmServerSecret">...</div>
                            </div>

                            <div class="grid grid-cols-1 gap-2">
                                <div>
                                    <div class="text-[10px] text-gray-500 font-mono mb-1">TARGET BLOCK</div>
                                    <div class="text-sm font-black" id="fmBlockNumber" style="color:#0f172a;">...</div>
                                    <a href="#" target="_blank" rel="noopener" id="fmBlockLink" class="tr-bsc-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>Ver en BscScan</a>
                                </div>
                                <div>
                                    <div class="text-[10px] text-gray-500 font-mono mb-1">FINAL BLOCK HASH</div>
                                    <div class="bg-black p-2 rounded border border-gray-800 text-[10px] font-mono text-blue-300 break-all select-all"
                                        id="fmMainBlockHash">...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="fmBatchSection" class="hidden bg-black/30 p-4 rounded-2xl border border-gray-800">
                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Doble o Nada (Batch)</h4>
                    <div class="space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <div class="text-[10px] text-gray-500 font-mono mb-1">TARGET BLOCK</div>
                                <div class="bg-black p-2 rounded border border-gray-800 text-[10px] font-mono text-white break-all select-all"
                                    id="fmBatchTarget">...</div>
                            </div>
                            <div>
                                <div class="text-[10px] text-gray-500 font-mono mb-1">FINAL BLOCK HASH</div>
                                <div class="bg-black p-2 rounded border border-gray-800 text-[10px] font-mono text-yellow-300 break-all select-all"
                                    id="fmBatchBlockHash">...</div>
                            </div>
                        </div>

                        <div>
                            <div class="text-[10px] text-gray-500 font-mono mb-1">SERVER SEED HASH (SHA256)</div>
                            <div class="bg-black p-2 rounded border border-gray-800 text-[10px] font-mono text-gray-200 break-all select-all"
                                id="fmBatchServerHash">...</div>
                        </div>

                        <div id="fmBatchSeedReveal" class="hidden">
                            <div class="text-[10px] text-gray-500 font-mono mb-1">SERVER SEED SECRET (REVEALED)</div>
                            <div class="bg-green-900/20 p-2 rounded border border-green-500/30 text-[10px] font-mono text-green-300 break-all select-all"
                                id="fmBatchServerSeed">...</div>
                        </div>

                        <div>
                            <a href="#" target="_blank" rel="noopener" id="fmBatchBlockLink" class="tr-bsc-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>Ver en BscScan</a>
                        </div>
                    </div>
                </div>

                <div id="fmResultsSection" class="hidden bg-black/30 p-4 rounded-2xl border border-gray-800">
                    <div class="flex justify-between items-center mb-3">
                        <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest">Resultados del Batch</h4>
                        <div id="fmResultsCount" class="text-[10px] text-gray-600 font-mono"></div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs border-collapse">
                            <thead>
                                <tr class="text-gray-400">
                                    <th class="text-left font-bold py-2 pr-3 border-b border-gray-800">Usuario</th>
                                    <th class="text-left font-bold py-2 pr-3 border-b border-gray-800">Elección</th>
                                    <th class="text-left font-bold py-2 border-b border-gray-800">Outcome</th>
                                </tr>
                            </thead>
                            <tbody id="fmResultsBody"></tbody>
                        </table>
                    </div>
                </div>
                <div id="fmFechaSection" class="hidden bg-black/30 p-4 rounded-2xl border border-gray-800">
                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Fecha del Sorteo</h4>
                    <div class="text-sm font-black" id="fmFecha" style="color:#0f172a;">...</div>
                </div>
            </div>

            <button onclick="closeFairnessModal()"
                class="w-full mt-6 bg-gray-800 hover:bg-gray-700 py-3 rounded-xl font-bold text-sm">Cerrar</button>
        </div>
    </div>

    <!-- Gamble Result Cinematic Overlay (Redesigned with Avatar Centerpiece) -->
    <div id="gambleResultOverlay" class="gamble-result-overlay">
        <!-- Animated Background (Victory: Sun Rays / Defeat: Dark Cold) -->
        <div class="gamble-result-bg" id="gambleResultBg"></div>
        
        <!-- Sun Rays (Victory Only) -->
        <div class="gamble-sunrays" id="gambleSunrays"></div>
        
        <!-- Main Content -->
        <div class="gamble-result-content">
            <!-- Main Title -->
            <div class="gamble-result-title" id="gambleResultTitle">¡VICTORIA!</div>
            
            <!-- Winner Spotlight with Avatar -->
            <div class="gamble-spotlight">
                <div class="gamble-spotlight-glow"></div>
                <div class="gamble-avatar-container" id="gambleAvatarContainer">
                    <img id="gambleResultAvatar" src="" class="gamble-avatar">
                    <div class="gamble-avatar-overlay" id="gambleAvatarOverlay"></div>
                </div>
                <div class="gamble-winner-name" id="gambleWinnerName">Jugador</div>
            </div>
            
            <!-- Subtitle -->
            <div class="gamble-result-subtitle" id="gambleResultSubtitle">Has duplicado tu premio</div>
        </div>
        
        <!-- Victory Effects Container -->
        <div class="gamble-victory-effects" id="victoryEffects">
            <div class="gamble-money-rain" id="moneyRain"></div>
            <div class="gamble-confetti-burst" id="confettiBurst"></div>
        </div>
        
        <!-- Defeat Effects Container -->
        <div class="gamble-defeat-effects" id="defeatEffects">
            <div class="gamble-ice-overlay" id="iceOverlay"></div>
            <div class="gamble-rain-container" id="rainContainer"></div>
            <div class="gamble-cobwebs" id="cobwebs"></div>
            <div class="gamble-frost-crystals" id="frostCrystals"></div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         Diálogos nativos (SfDialog + window.Backdrop)
         Paneles persistentes: el JS rellena los [data-slot] y llama a
         SfDialog.open(). El velo lo pinta #backdrop, como en el resto del sitio.
         ══════════════════════════════════════════════════════════════════ -->

    <svg class="sf-sprite" aria-hidden="true" focusable="false" width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden">
        <symbol id="sf-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 6 6 18" />
            <path d="m6 6 12 12" />
        </symbol>
        <symbol id="sf-icon-gift" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 12v10H4V12" />
            <path d="M2 7h20v5H2z" />
            <path d="M12 22V7" />
            <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z" />
            <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" />
        </symbol>
        <symbol id="sf-icon-warning" viewBox="0 0 24 24">
            <path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="M13.7 3.45a2 2 0 0 0-3.4 0l-9 14.5A2 2 0 0 0 3 21h18a2 2 0 0 0 1.7-3.05l-9-14.5ZM11 9a1 1 0 1 1 2 0v5a1 1 0 1 1-2 0V9Zm1 7.25A1.25 1.25 0 1 0 12 18.75a1.25 1.25 0 0 0 0-2.5Z" />
        </symbol>
        <symbol id="sf-icon-ban" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9" />
            <path d="m5.6 5.6 12.8 12.8" />
        </symbol>
        <symbol id="sf-icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9" />
            <path d="m8.5 12.2 2.4 2.4 4.8-5.2" />
        </symbol>
        <symbol id="sf-icon-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12a8 8 0 0 1-8 8H8l-4.5 3V12a8.5 8.5 0 0 1 8.5-8.5h1A8 8 0 0 1 21 12z" />
            <path d="M8.5 11.5h7" />
            <path d="M8.5 14.5h4" />
        </symbol>
        <symbol id="sf-icon-trophy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M8 21h8" />
            <path d="M12 17v4" />
            <path d="M7 4h10v5a5 5 0 0 1-10 0V4z" />
            <path d="M7 6H4.5a1.5 1.5 0 0 0-1.5 1.5c0 2 1.8 3.5 4 3.5" />
            <path d="M17 6h2.5A1.5 1.5 0 0 1 21 7.5c0 2-1.8 3.5-4 3.5" />
        </symbol>
        <symbol id="sf-icon-pointer" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 11V4.5a1.5 1.5 0 0 1 3 0V11" />
            <path d="M12 10.5a1.5 1.5 0 0 1 3 0V12" />
            <path d="M15 11.5a1.5 1.5 0 0 1 3 0V13" />
            <path d="M18 12.5a1.5 1.5 0 0 1 3 0V16a6 6 0 0 1-6 6h-2.2a6 6 0 0 1-4.8-2.4l-3.6-4.8a1.6 1.6 0 0 1 2.5-2L9 15" />
        </symbol>
        <symbol id="sf-icon-layers" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="m12 3 9 4.5-9 4.5-9-4.5L12 3z" />
            <path d="m3 12 9 4.5 9-4.5" />
            <path d="m3 16.5 9 4.5 9-4.5" />
        </symbol>
        <symbol id="sf-icon-ticket" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 8.5V6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v2.5a2.5 2.5 0 0 0 0 5V18a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-4.5a2.5 2.5 0 0 0 0-5z" />
            <path d="M14 5v2" />
            <path d="M14 11v2" />
            <path d="M14 17v2" />
        </symbol>
        <symbol id="sf-icon-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m9 6 6 6-6 6" />
        </symbol>
        <!-- Flecha de retorno: mismo trazado que el breadcrumb de item-page (app.js) -->
        <symbol id="sf-icon-back" viewBox="0 0 24 24">
            <path fill="currentColor" fill-rule="evenodd" d="M12.7073 4.74675C13.0587 4.39529 13.6286 4.39529 13.98 4.74675L15.8892 6.6559C16.2407 7.00737 16.2407 7.57722 15.8892 7.92869L11.8166 12.0012L15.8892 16.0737C16.2407 16.4252 16.2407 16.995 15.8892 17.3465L13.98 19.2557C13.6286 19.6071 13.0587 19.6071 12.7073 19.2557L5.45264 12.0012L12.7073 4.74675Z" />
        </symbol>
        <!-- Dados: misma geometría que el encabezado "Ofertas random del día" de la tienda (app.js) -->
        <symbol id="sf-icon-dice" viewBox="282 215 716 819">
            <defs>
                <rect id="sf-dice-face" width="384" height="384" rx="30" />
                <circle id="sf-dice-dot" r="46" />
                <mask id="sf-dice-mask-1" fill="#000"><use href="#sf-dice-face" fill="#fff" /><use href="#sf-dice-dot" x="192" y="192" /></mask>
                <mask id="sf-dice-mask-3" fill="#000"><use href="#sf-dice-face" fill="#fff" /><use href="#sf-dice-dot" x="192" y="192" /><use href="#sf-dice-dot" x="92" y="292" /><use href="#sf-dice-dot" x="292" y="92" /></mask>
                <mask id="sf-dice-mask-5" fill="#000"><use href="#sf-dice-face" fill="#fff" /><use href="#sf-dice-dot" x="192" y="192" /><use href="#sf-dice-dot" x="92" y="92" /><use href="#sf-dice-dot" x="292" y="92" /><use href="#sf-dice-dot" x="92" y="292" /><use href="#sf-dice-dot" x="292" y="292" /></mask>
            </defs>
            <g fill="currentColor">
                <use href="#sf-dice-face" mask="url(#sf-dice-mask-1)" transform="matrix(.904-.522-.904-.522 640 616)" />
                <use href="#sf-dice-face" mask="url(#sf-dice-mask-5)" transform="matrix(-.868-.501 0 1 616 650)" />
                <use href="#sf-dice-face" mask="url(#sf-dice-mask-3)" transform="matrix(.868-.501 0 1 664 650)" />
            </g>
        </symbol>
        <!-- CheckmarkIcon del modal de filtros (catalog-filter/filter-ui.js): anillo + cuadro amarillos, check oscuro -->
        <symbol id="sf-icon-checkmark" viewBox="0 0 24 24">
            <path fill="#f7ff19" d="M17.8,24H6.2C2.8,24,0,21.2,0,17.8V6.2C0,2.8,2.8,0,6.2,0h11.7C21.2,0,24,2.8,24,6.2v11.7 C24,21.2,21.2,24,17.8,24z M6.2,2C3.9,2,2,3.9,2,6.2v11.7C2,20.1,3.9,22,6.2,22h11.7c2.3,0,4.1-1.8,4.1-4.1V6.2 C22,3.9,20.1,2,17.8,2H6.2z" />
            <path fill="#f7ff19" d="M4,6.9C4,5.3,5.3,4,6.9,4h10.2C18.7,4,20,5.3,20,6.9v10.2c0,1.6-1.3,2.9-2.9,2.9H6.9C5.3,20,4,18.7,4,17.1V6.9z" />
            <path fill="#18181b" d="M17,9.6l-6.5,6.7l-3.6-3.6l1.5-1.5c0.3-0.3,0.7-0.3,1,0l1,1l3.9-4.1c0.3-0.3,0.7-0.3,1,0L17,9.6z" />
        </symbol>
        <!-- Ruleta y tragamonedas: silueta monocroma con recortes en espacio negativo (evenodd), como el dado -->
        <symbol id="sf-icon-roulette" viewBox="0 0 64 64">
            <path fill="currentColor" fill-rule="evenodd" d="M1,32a31,31 0 1 0 62,0a31,31 0 1 0 -62,0ZM5,32a27,27 0 1 0 54,0a27,27 0 1 0 -54,0ZM7,32a25,25 0 1 0 50,0a25,25 0 1 0 -50,0ZM21,32a11,11 0 1 0 22,0a11,11 0 1 0 -22,0ZM24,32a8,8 0 1 0 16,0a8,8 0 1 0 -16,0ZM29.5,32a2.5,2.5 0 1 0 5,0a2.5,2.5 0 1 0 -5,0ZM42.24,36.3L55.76,39.92L56.54,37.02L43.01,33.4ZM38.72,40.84L48.62,50.74L50.74,48.62L40.84,38.72ZM33.4,43.01L37.02,56.54L39.92,55.76L36.3,42.24ZM27.7,42.24L24.08,55.76L26.98,56.54L30.6,43.01ZM23.16,38.72L13.26,48.62L15.38,50.74L25.28,40.84ZM20.99,33.4L7.46,37.02L8.24,39.92L21.76,36.3ZM21.76,27.7L8.24,24.08L7.46,26.98L20.99,30.6ZM25.28,23.16L15.38,13.26L13.26,15.38L23.16,25.28ZM30.6,20.99L26.98,7.46L24.08,8.24L27.7,21.76ZM36.3,21.76L39.92,8.24L37.02,7.46L33.4,20.99ZM40.84,25.28L50.74,15.38L48.62,13.26L38.72,23.16ZM43.01,30.6L56.54,26.98L55.76,24.08L42.24,27.7ZM28.25,14.5a3.75,3.75 0 1 0 7.5,0a3.75,3.75 0 1 0 -7.5,0ZM30,14.5a2,2 0 1 0 4,0a2,2 0 1 0 -4,0Z" />
        </symbol>
        <symbol id="sf-icon-slot" viewBox="0 0 64 64">
            <path fill="currentColor" fill-rule="evenodd" d="M12,10H42a6,6 0 0 1 6,6V29h3V15.21a4,4 0 1 1 3,0V34h-6V48a6,6 0 0 1 -6,6H12a6,6 0 0 1 -6,-6V16a6,6 0 0 1 6,-6ZM15,12.5h24a1,1 0 0 1 0,2h-24a1,1 0 0 1 0,-2ZM13.5,17h27a2.5,2.5 0 0 1 2.5,2.5v11a2.5,2.5 0 0 1 -2.5,2.5h-27a2.5,2.5 0 0 1 -2.5,-2.5v-11a2.5,2.5 0 0 1 2.5,-2.5ZM14.5,19h5a1.5,1.5 0 0 1 1.5,1.5v9a1.5,1.5 0 0 1 -1.5,1.5h-5a1.5,1.5 0 0 1 -1.5,-1.5v-9a1.5,1.5 0 0 1 1.5,-1.5ZM24.5,19h5a1.5,1.5 0 0 1 1.5,1.5v9a1.5,1.5 0 0 1 -1.5,1.5h-5a1.5,1.5 0 0 1 -1.5,-1.5v-9a1.5,1.5 0 0 1 1.5,-1.5ZM34.5,19h5a1.5,1.5 0 0 1 1.5,1.5v9a1.5,1.5 0 0 1 -1.5,1.5h-5a1.5,1.5 0 0 1 -1.5,-1.5v-9a1.5,1.5 0 0 1 1.5,-1.5ZM15.1,25a1.9,1.9 0 1 0 3.8,0a1.9,1.9 0 1 0 -3.8,0ZM25.1,25a1.9,1.9 0 1 0 3.8,0a1.9,1.9 0 1 0 -3.8,0ZM35.1,25a1.9,1.9 0 1 0 3.8,0a1.9,1.9 0 1 0 -3.8,0ZM16,39.5a2,2 0 1 0 4,0a2,2 0 1 0 -4,0ZM25,39.5a2,2 0 1 0 4,0a2,2 0 1 0 -4,0ZM34,39.5a2,2 0 1 0 4,0a2,2 0 1 0 -4,0ZM14,45h26a2,2 0 0 1 2,2v0.5a2,2 0 0 1 -2,2h-26a2,2 0 0 1 -2,-2v-0.5a2,2 0 0 1 2,-2Z" />
        </symbol>
    </svg>

    <div id="backdrop" aria-hidden="true"></div>

    <!-- Retiro / suspensión: bloqueante (sin Escape ni clic fuera), un solo CTA -->
    <dialog class="sf-dialog sf-dialog--notice" id="sf-removed-dialog" data-state="expelled"
            aria-labelledby="sf-removed-title" aria-describedby="sf-removed-desc">
        <article class="sf-dialog__panel">
            <header class="sf-dialog__header">
                <span class="sf-dialog__glyph" data-tone="warning" data-slot="glyph" aria-hidden="true">
                    <svg><use href="#sf-icon-warning" data-slot="glyph-icon"></use></svg>
                </span>
                <span class="sf-badge" data-tone="warning" data-slot="badge">Sorteo en revisión</span>
                <h2 id="sf-removed-title" class="sf-dialog__title" data-slot="title">Te retiramos del sorteo</h2>
                <p id="sf-removed-desc" class="sf-dialog__text" data-slot="desc">Tu entrada a este sorteo se retiró durante la revisión.</p>
            </header>
            <section class="sf-dialog__body" aria-label="Soporte">
                <div class="sf-notice">
                    <svg class="sf-notice__icon" aria-hidden="true" focusable="false"><use href="#sf-icon-chat"></use></svg>
                    <p class="sf-notice__text">
                        <strong>¿Compraste por Messenger o WhatsApp?</strong>
                        Escríbenos por mensaje directo y revisamos tu caso.
                    </p>
                </div>
            </section>
            <footer class="sf-dialog__footer">
                <button type="button" class="sf-cta" data-dialog-action="confirm" data-autofocus>Entendido</button>
            </footer>
        </article>
    </dialog>

    <!-- Ya participas: informativo, cerrable; estado del sorteo + acceso a premios -->
    <dialog class="sf-dialog sf-dialog--notice" id="sf-joined-dialog" data-state="joined"
            aria-labelledby="sf-joined-title" aria-describedby="sf-joined-desc">
        <article class="sf-dialog__panel">
            <button type="button" class="sf-dialog__close" data-dialog-action="dismiss" aria-label="Cerrar">
                <svg aria-hidden="true" focusable="false"><use href="#sf-icon-close"></use></svg>
            </button>
            <header class="sf-dialog__header">
                <span class="sf-dialog__glyph" data-tone="success" aria-hidden="true">
                    <svg><use href="#sf-icon-check"></use></svg>
                </span>
                <span class="sf-badge" data-tone="success">Inscrito</span>
                <h2 id="sf-joined-title" class="sf-dialog__title">Ya participas</h2>
                <p id="sf-joined-desc" class="sf-dialog__text">Tu entrada a este sorteo ya está registrada. Solo queda esperar el resultado.</p>
            </header>
            <section class="sf-dialog__body" aria-label="Estado del sorteo">
                <dl class="sf-stats">
                    <div class="sf-stat">
                        <dt>Participantes</dt>
                        <dd data-slot="participants">—</dd>
                    </div>
                    <div class="sf-stat">
                        <dt>Cupos</dt>
                        <dd data-slot="slots">—</dd>
                    </div>
                    <div class="sf-stat">
                        <dt>Ganadores</dt>
                        <dd data-slot="winners">—</dd>
                    </div>
                </dl>
            </section>
            <footer class="sf-dialog__footer">
                <button type="button" class="sf-cta" data-dialog-action="confirm" data-autofocus>Entendido</button>
                <button type="button" class="sf-cta sf-cta--secondary" data-dialog-action="prizes">Ver premios en juego</button>
            </footer>
        </article>
    </dialog>

    <!-- Inscripción confirmada: ticket + detalle de la entrada; puerta a la pre-elección -->
    <dialog class="sf-dialog sf-dialog--notice" id="sf-enrolled-dialog"
            aria-labelledby="sf-enrolled-title" aria-describedby="sf-enrolled-desc">
        <article class="sf-dialog__panel">
            <button type="button" class="sf-dialog__close" data-dialog-action="dismiss" aria-label="Cerrar">
                <svg aria-hidden="true" focusable="false"><use href="#sf-icon-close"></use></svg>
            </button>
            <header class="sf-dialog__header">
                <span class="sf-dialog__glyph" data-tone="success" aria-hidden="true">
                    <svg><use href="#sf-icon-ticket"></use></svg>
                </span>
                <span class="sf-badge" data-tone="success" data-slot="badge">Inscripción confirmada</span>
                <h2 id="sf-enrolled-title" class="sf-dialog__title">¡Estás dentro!</h2>
                <p id="sf-enrolled-desc" class="sf-dialog__text" data-slot="desc">Tu lugar en este sorteo ya está asegurado. Guarda tu número de ticket: con él anunciaremos al ganador.</p>
            </header>
            <section class="sf-dialog__body" aria-label="Detalle de tu entrada">
                <dl class="sf-stats sf-stats--auto" data-slot="stats"></dl>
                <div class="sf-notice" data-slot="notice" hidden>
                    <svg class="sf-notice__icon" aria-hidden="true" focusable="false"><use href="#sf-icon-trophy"></use></svg>
                    <p class="sf-notice__text" data-slot="notice-text"></p>
                </div>
            </section>
            <footer class="sf-dialog__footer">
                <button type="button" class="sf-cta" data-slot="primary" data-dialog-action="confirm" data-autofocus>Seguir en el sorteo</button>
                <button type="button" class="sf-cta sf-cta--secondary" data-slot="secondary" data-dialog-action="dismiss" hidden>Decidir más tarde</button>
            </footer>
        </article>
    </dialog>

    <!-- Pre-elección de premio: flujo por pasos en un único panel (SfPreChoice) -->
    <dialog class="sf-dialog sf-dialog--flow" id="sf-prechoice-dialog"
            aria-labelledby="sf-prechoice-title" aria-describedby="sf-prechoice-desc">
        <article class="sf-dialog__panel">
            <header class="sf-dialog__header sf-dialog__header--bar">
                <span class="sf-dialog__glyph sf-dialog__glyph--sm" data-tone="accent" data-slot="glyph" aria-hidden="true">
                    <svg><use href="#sf-icon-gift" data-slot="glyph-icon"></use></svg>
                </span>
                <div class="sf-dialog__heading" aria-live="polite">
                    <h2 id="sf-prechoice-title" class="sf-dialog__title" data-slot="title">Si llegas a ganar, ¿qué prefieres?</h2>
                    <p id="sf-prechoice-desc" class="sf-dialog__summary" data-slot="desc"></p>
                </div>
                <button type="button" class="sf-dialog__close" data-dialog-action="dismiss" aria-label="Cerrar">
                    <svg aria-hidden="true" focusable="false"><use href="#sf-icon-close"></use></svg>
                </button>
            </header>
            <!-- Riel de pasos: lo genera SfPreChoice según la ruta real (opción → detalles → confirmar). -->
            <ol class="sf-steps" data-slot="steps" aria-label="Progreso de tu elección"></ol>
            <section class="sf-dialog__body sf-dialog__body--scroll" data-slot="view" aria-labelledby="sf-prechoice-title"></section>
            <footer class="sf-dialog__footer">
                <p class="sf-dialog__alert" data-slot="alert" role="alert" hidden></p>
                <button type="button" class="sf-cta" data-slot="primary" data-act="continue">Continuar</button>
                <button type="button" class="sf-cta sf-cta--secondary" data-slot="secondary" hidden></button>
                <button type="button" class="sf-dialog__back" data-slot="back" data-act="back" hidden>
                    <svg aria-hidden="true" focusable="false"><use href="#sf-icon-back"></use></svg>
                    <span data-slot="back-label">Volver a opciones</span>
                </button>
            </footer>
        </article>
    </dialog>

    <dialog class="sf-dialog sf-dialog--flow sf-dialog--ban" id="sf-ban-dialog"
            aria-labelledby="sf-ban-title" aria-describedby="sf-ban-desc">
        <article class="sf-dialog__panel">
            <header class="sf-dialog__header sf-dialog__header--bar">
                <span class="sf-dialog__glyph sf-dialog__glyph--sm" data-tone="danger" aria-hidden="true">
                    <svg><use href="#sf-icon-ban"></use></svg>
                </span>
                <div class="sf-dialog__heading" aria-live="polite">
                    <h2 id="sf-ban-title" class="sf-dialog__title" data-slot="title">Baneo de Sorteo Flash</h2>
                    <p id="sf-ban-desc" class="sf-dialog__summary" data-slot="desc"></p>
                </div>
            </header>
            <section class="sf-dialog__body sf-dialog__body--scroll" data-slot="view" aria-labelledby="sf-ban-title"></section>
            <footer class="sf-dialog__footer">
                <a class="sf-cta" href="/juegos">Salir de la sala</a>
                <a class="sf-cta sf-cta--secondary" href="https://wa.me/51924989167" target="_blank" rel="noopener noreferrer">Escribir por WhatsApp</a>
            </footer>
        </article>
    </dialog>

    <!-- Premios en juego: vitrina por puesto (tabs) con tarjetas de tienda -->
    <dialog class="sf-dialog sf-dialog--showcase" id="sf-prizes-dialog"
            aria-labelledby="sf-prizes-title" aria-describedby="sf-prizes-desc">
        <article class="sf-dialog__panel">
            <header class="sf-dialog__header sf-dialog__header--bar">
                <span class="sf-dialog__glyph sf-dialog__glyph--sm" data-tone="accent" aria-hidden="true">
                    <svg><use href="#sf-icon-gift"></use></svg>
                </span>
                <div class="sf-dialog__heading">
                    <h2 id="sf-prizes-title" class="sf-dialog__title">Premios en juego</h2>
                    <p id="sf-prizes-desc" class="sf-dialog__summary" data-slot="summary">Esto es lo que puedes ganar hoy</p>
                </div>
                <button type="button" class="sf-dialog__close" data-dialog-action="dismiss" aria-label="Cerrar">
                    <svg aria-hidden="true" focusable="false"><use href="#sf-icon-close"></use></svg>
                </button>
            </header>
            <div class="sf-tabs" data-slot="tabs" role="tablist" aria-label="Puestos premiados" hidden></div>
            <section class="sf-dialog__body sf-dialog__body--scroll" data-slot="panels" aria-label="Premios por puesto"></section>
            <footer class="sf-dialog__footer">
                <button type="button" class="sf-cta" data-dialog-action="confirm" data-autofocus>Volver al sorteo</button>
            </footer>
        </article>
    </dialog>

<script>
    window.raffleConfig = {
        raffleId: <?php echo $raffleId; ?>,
        mode: '<?php echo $raffleMode; ?>',
        me: {
            userId: <?php echo (int) $meUserId; ?>,
            username: <?php echo json_encode($meUsername, JSON_UNESCAPED_UNICODE); ?>
        },
        anims: {
            main: '<?php echo $mainAnim; ?>',
            gamble: '<?php echo $gambleAnim; ?>'
        },
        prize: <?php echo json_encode($prizeConfig, JSON_UNESCAPED_UNICODE); ?>,
        prizePricing: <?php echo json_encode($prizePricing, JSON_UNESCAPED_UNICODE); ?>,
        cashbackPercent: <?php echo $cashbackPercent; ?>,
        gambleBonusCoins: <?php echo $gambleBonusCoins; ?>,
        prizeValueCoins: <?php echo json_encode($prizeValueCoins ?: new stdClass()); ?>,
        currentPot: <?php echo $currentPot; ?>,
        winnersCount: <?php echo $winnersCount; ?>,
        audio: <?php echo json_encode($audioConfig ?: new stdClass(), JSON_UNESCAPED_UNICODE); ?>
    };
</script>

<script src="/GlobalBackdropManager.js"></script>
<script src="/avatar-fallback.js?v=20260914"></script>
<script src="sf-dialog.js?v=<?php echo filemtime(__DIR__ . '/sf-dialog.js'); ?>"></script>
<script src="sf-prechoice.js?v=<?php echo filemtime(__DIR__ . '/sf-prechoice.js'); ?>"></script>
<script src="sf-ban-modal.js?v=<?php echo filemtime(__DIR__ . '/sf-ban-modal.js'); ?>"></script>
<script src="sorteo_flash.js?v=<?php echo time(); ?>"></script>
<script src="skip_controller.js?v=<?php echo time(); ?>"></script>
<script type="module" src="/cuenta/onboarding_check.js"></script>
</body>

</html>

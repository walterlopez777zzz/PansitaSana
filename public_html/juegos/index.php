<?php
 if (PHP_SAPI !== 'cli') {
     $rh_script_filename = $_SERVER['SCRIPT_FILENAME'] ?? '';
     if ($rh_script_filename && realpath($rh_script_filename) === realpath(__FILE__)) {
         header('Location: ../?view=games');
         exit;
     }
 }
/*
    🏆 REWARDS HUB - BANANO STORE
    Estilo: E-commerce Premium / Rewards Center
*/

// --- SESIÓN SEGURA (evita "session already started") ---
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../api/session_security.php';
}

// --- LÓGICA DE USUARIO ---
$rh_user_state = 'guest'; // guest | unverified | verified

if (isset($_SESSION['user_id'])) {
    $rh_user_id = (int) $_SESSION['user_id'];

    if ($rh_user_id > 0) {
        $rh_user_state = 'verified';
    }

    $rh_db_conn = $GLOBALS['conn'] ?? ($conn ?? null);

    if (!$rh_db_conn) {
        $rh_db_connect_path = __DIR__ . '/../wabo/core/db_connect.php';
        if (file_exists($rh_db_connect_path)) {
            require_once $rh_db_connect_path;
            $rh_db_conn = $GLOBALS['conn'] ?? ($conn ?? null);
        }
    }

    if ($rh_db_conn) {
        $stmt_v = $rh_db_conn->prepare("SELECT id, is_verified, account_status FROM users WHERE id = ? LIMIT 1");
        if ($stmt_v) {
            $stmt_v->bind_param('i', $rh_user_id);
            if ($stmt_v->execute()) {
                $stmt_v->bind_result($db_user_id, $db_is_verified, $db_account_status);

                if ($stmt_v->fetch()) {
                    if ($db_user_id && $db_account_status === 'active') {
                        $rh_user_state = ((int) $db_is_verified === 1) ? 'verified' : 'unverified';
                    } else {
                        $rh_user_state = 'guest';
                    }
                } else {
                    $rh_user_state = 'guest';
                }
            }

            $stmt_v->close();
        }
    }
}

$login_url = '/login/?redirect_to=' . urlencode('/juegos/');

// --- VISUAL DE TARJETAS (fondo dinámico desde DB) ---
$rh_visuals = ['daily' => null, 'flash' => null];

// Garantizar que las credenciales de DB estén disponibles
if (!isset($dbHost)) {
    require_once __DIR__ . '/../wabo/core/db_connect.php';
}

// Conexión dedicada para esta consulta: evita problemas de estado
// compartido con $conn tras las prepared statements anteriores.
if (isset($dbHost, $dbUser, $dbPass, $dbName)) {
    $rh_vis_conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($rh_vis_conn && !$rh_vis_conn->connect_error) {
        $rh_vis_conn->set_charset('utf8mb4');
        $rh_vis_result = $rh_vis_conn->query("SELECT card_key, background_image_url, background_opacity, background_color, bg_position_x, bg_position_y, bg_scale, texts_config FROM game_card_visuals");
        if ($rh_vis_result && $rh_vis_result instanceof mysqli_result) {
            while ($rh_vis_row = $rh_vis_result->fetch_assoc()) {
                $rh_vis_row['_texts'] = !empty($rh_vis_row['texts_config'])
                    ? json_decode($rh_vis_row['texts_config'], true) : [];
                $rh_visuals[$rh_vis_row['card_key']] = $rh_vis_row;
            }
            $rh_vis_result->free();
        }
        $rh_vis_conn->close();
    }
}

// Helper: get a text property from the decoded JSON with safe fallback + XSS protection.
// Usage: rh_txt('daily', 'title', 'text', 'Descuento Diario')
function rh_txt(string $card_key, string $field, string $prop, string $default, array &$visuals): string {
    $val = $visuals[$card_key]['_texts'][$field][$prop] ?? null;
    if ($prop === 'text') {
        $val = is_string($val) && trim($val) !== '' ? trim($val) : $default;
        return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    }
    if ($prop === 'color') {
        return (is_string($val) && preg_match('/^#[0-9a-fA-F]{6}$/', $val)) ? $val : $default;
    }
    if ($prop === 'opacity') {
        return is_numeric($val) ? (string) max(0, min(1, (float) $val)) : $default;
    }
    return htmlspecialchars((string) ($val ?? $default), ENT_QUOTES, 'UTF-8');
}
?>

<div class="rh-wrapper" id="rewardsHub">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* ======================================
           REWARDS HUB — SCOPED STYLES
           ====================================== */
        .rh-wrapper {
            --rh-emerald: #10b981;
            --rh-emerald-light: #6ee7b7;
            --rh-emerald-dark: #047857;
            --rh-amber: #f59e0b;
            --rh-amber-light: #fcd34d;
            --rh-amber-dark: #b45309;
            --rh-bg: #0f1221;
            --rh-surface: rgba(255,255,255,0.05);
            --rh-text: #f1f5f9;
            --rh-text-muted: #94a3b8;
            --rh-radius: 20px;

            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            width: 100%;
            padding: 0 20px 48px;
            background: transparent;
            border: none;
            box-shadow: none;
            border-radius: var(--rh-radius);
            margin: 0 0 20px;
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
        }

        .rh-container {
            max-width: 720px;
            margin: 0 auto;
            margin-top: 0;
            position: relative;
            z-index: 1;
        }

        @media (min-width: 1024px) {
            .rh-container {
                max-width: 1280px;
            }
        }

        /* --- GRID --- */
        .rh-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 32px;
        }
        @media (min-width: 1024px) {
            .rh-grid {
                grid-template-columns: 1fr 1fr;
                gap: 40px;
            }
        }

        /* ======================================
           PREMIUM CARDS
           ====================================== */
        .rh-card {
            position: relative;
            border-radius: var(--rh-radius);
            padding: 16px 24px 32px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            cursor: pointer;
            user-select: none;
            overflow: hidden;
            text-decoration: none;
            color: var(--rh-text);
            text-align: center;
        }

        .rh-card:hover {
            transform: none;
        }
        .rh-card:active {
            transform: none;
        }

        @media (min-width: 1024px) {
            .rh-card {
                padding: 28px 40px 40px;
                gap: 24px;
            }
        }

        /* --- Thin diagonal shimmer sweep --- */
        .rh-card .rh-shimmer {
            position: absolute;
            top: 0;
            left: -30%;
            width: 18%;
            height: 100%;
            background: rgba(255,255,255,0.10);
            transform: skewX(-15deg);
            pointer-events: none;
            animation: rh-diag-sweep 0.6s cubic-bezier(0.16, 0.7, 0.35, 1) forwards;
            z-index: 2;
        }
        @keyframes rh-diag-sweep {
            0%   { left: -30%; opacity: 0; }
            15%  { opacity: 1; }
            85%  { opacity: 1; }
            100% { left: 115%; opacity: 0; }
        }

        .rh-card--daily {
            background: linear-gradient(160deg, #064e3b 0%, #047857 45%, #059669 100%);
        }
        .rh-card--flash {
            background: linear-gradient(160deg, #3b0764 0%, #6b21a8 40%, #7c3aed 100%);
        }

        <?php
        // --- CSS dinámico: color de fondo + pseudo-elemento ::before con imagen ---
        foreach (['daily', 'flash'] as $rh_ck) {
            $rh_v = $rh_visuals[$rh_ck] ?? null;
            if (!$rh_v) continue;

            // Color de fondo personalizado (reemplaza el gradiente estático)
            $rh_bg_color = $rh_v['background_color'] ?? null;
            if ($rh_bg_color && preg_match('/^#[0-9a-fA-F]{6}$/', $rh_bg_color)) {
                echo ".rh-card--{$rh_ck} { background: {$rh_bg_color}; }\n";
            }

            // Imagen de fondo con posición y escala
            if (!empty($rh_v['background_image_url'])) {
                $rh_img_url = addcslashes($rh_v['background_image_url'], "'\\()");
                $rh_opacity = max(0, min(1, floatval($rh_v['background_opacity'] ?? 1)));
                $rh_opacity_css = number_format($rh_opacity, 2, '.', '');
                $rh_pos_x = max(0, min(100, (int)($rh_v['bg_position_x'] ?? 50)));
                $rh_pos_y = max(0, min(100, (int)($rh_v['bg_position_y'] ?? 50)));
                $rh_scale  = max(50, min(300, (int)($rh_v['bg_scale'] ?? 100)));
                echo ".rh-card--{$rh_ck}::before {\n";
                echo "    content: '';\n";
                echo "    position: absolute;\n";
                echo "    inset: 0;\n";
                echo "    background-image: url('{$rh_img_url}');\n";
                echo "    background-size: {$rh_scale}%;\n";
                echo "    background-position: {$rh_pos_x}% {$rh_pos_y}%;\n";
                echo "    background-repeat: no-repeat;\n";
                echo "    opacity: {$rh_opacity_css};\n";
                echo "    border-radius: inherit;\n";
                echo "    pointer-events: none;\n";
                echo "    z-index: 0;\n";
                echo "}\n";
                echo ".rh-card--{$rh_ck} > * { position: relative; z-index: 1; }\n";
            }
        }
        ?>

        .rh-card__title {
            font-size: clamp(1.8rem, 4vw, 3.2rem);
            font-weight: 900;
            letter-spacing: -0.03em;
            color: #fff;
            line-height: 1;
            margin: 0;
            white-space: nowrap;
            text-transform: uppercase;
            text-align: center;
            overflow: hidden;
        }

        .rh-card__subtitle {
            font-size: clamp(1rem, 2.5vw, 1.3rem);
            font-weight: 600;
            margin: 0;
        }

        .rh-card__desc {
            font-size: clamp(1rem, 2.2vw, 1.25rem);
            line-height: 1.6;
            color: rgba(255,255,255,0.85);
            margin: 0;
            font-weight: 500;
            max-width: none;
            white-space: nowrap;
        }
        @media (max-width: 639px) {
            .rh-wrapper {
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible;
                width: 100%;
            }
            .rh-container {
                padding: 0 !important;
                max-width: 100% !important;
                margin: 0 !important;
                width: 100%;
            }
            .rh-grid {
                gap: 18px;
                margin: 0;
                padding: 0 8px;
                width: 100%;
            }
            .rh-card__desc {
                white-space: normal;
                text-wrap: balance;
            }
            .rh-card {
                border-radius: 10px;
                width: 100%;
                padding: 12px 16px 22px;
                gap: 10px;
            }
        }

        .rh-roulette-wrapper {
            display: flex;
            justify-content: center;
            margin: 12px 0 4px;
        }
        .rh-roulette-svg {
            width: 180px;
            height: 180px;
            animation: rh-spin 8s linear infinite;
        }
        @keyframes rh-spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }

        /* --- CTA Buttons --- */
        .rh-card__cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 32px;
            border-radius: 14px;
            font-size: clamp(0.95rem, 2.2vw, 1.15rem);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border: none;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
        }

        @media (min-width: 1024px) {
            .rh-card__cta {
                padding: 20px 36px;
                border-radius: 16px;
            }
        }

        /* Verified / Active Button */
        .rh-cta--active-daily {
            background: linear-gradient(135deg, #34d399 0%, #10b981 100%);
            color: #022c22;
            box-shadow: 0 4px 24px rgba(52,211,153,0.35);
        }
        .rh-cta--active-daily:hover {
            box-shadow: 0 8px 32px rgba(52,211,153,0.5);
            filter: brightness(1.1);
        }
        .rh-cta--active-daily:active {
            filter: brightness(0.95);
        }

        .rh-cta--active-flash {
            background: linear-gradient(135deg, #c084fc 0%, #a855f7 100%);
            color: #1e0533;
            box-shadow: 0 4px 24px rgba(168,85,247,0.35);
        }
        .rh-cta--active-flash:hover {
            box-shadow: 0 8px 32px rgba(168,85,247,0.5);
            filter: brightness(1.1);
        }
        .rh-cta--active-flash:active {
            filter: brightness(0.95);
        }

        .rh-cta--login .rh-cta-icon {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        /* Guest Button (Login) */
        .rh-cta--login {
            white-space: nowrap;
            font-size: clamp(0.72rem, 2vw, 1.15rem);
            background: rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.9);
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(4px);
        }
        
        /* Mobile-specific login button text */
        @media (max-width: 639px) {
            .rh-cta--login {
                font-size: clamp(0.9rem, 2.5vw, 1.1rem);
            }
        }
        .rh-cta--login:hover {
            background: rgba(255,255,255,0.18);
            color: #fff;
            box-shadow: 0 4px 16px rgba(255,255,255,0.08);
        }

        /* Unverified Button */
        .rh-cta--disabled {
            background: rgba(255,255,255,0.06);
            color: rgba(255,255,255,0.35);
            border: 1px solid rgba(255,255,255,0.06);
            cursor: not-allowed;
            position: relative;
        }

        /* ======================================
           VERIFICATION MODAL
           ====================================== */
        .rh-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .rh-modal-overlay.rh-modal--visible {
            display: flex;
            opacity: 1;
        }

        .rh-modal {
            background: linear-gradient(165deg, #1e293b 0%, #0f172a 100%);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 20px;
            padding: 36px 32px;
            max-width: 420px;
            width: 100%;
            text-align: center;
            position: relative;
            box-shadow: 0 24px 80px rgba(0,0,0,0.5),
                        inset 0 1px 0 rgba(255,255,255,0.05);
            animation: rh-modal-in 0.35s cubic-bezier(0.22, 1, 0.36, 1);
        }

        @keyframes rh-modal-in {
            from { opacity: 0; transform: translateY(20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .rh-modal__close {
            position: absolute;
            top: 14px;
            right: 14px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.5);
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
            transition: all 0.2s;
            line-height: 1;
        }
        .rh-modal__close:hover {
            background: rgba(255,255,255,0.12);
            color: #fff;
        }

        .rh-modal__icon {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(251,191,36,0.15), rgba(245,158,11,0.08));
            border: 1px solid rgba(251,191,36,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .rh-modal__icon svg {
            width: 30px;
            height: 30px;
            color: #fbbf24;
        }

        .rh-modal__title {
            font-size: 1.25rem;
            font-weight: 800;
            color: #fff;
            margin: 0 0 8px;
        }

        .rh-modal__text {
            font-size: 0.88rem;
            color: rgba(255,255,255,0.55);
            line-height: 1.6;
            margin: 0 0 24px;
        }

        .rh-modal__actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .rh-modal__btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #451a03;
            transition: all 0.2s;
        }
        .rh-modal__btn-primary:hover {
            filter: brightness(1.1);
            box-shadow: 0 4px 20px rgba(251,191,36,0.3);
        }

        .rh-modal__btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.1);
            background: transparent;
            color: rgba(255,255,255,0.5);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .rh-modal__btn-secondary:hover {
            background: rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.8);
        }

        /* ======================================
           TOOLTIP
           ====================================== */
        .rh-tooltip {
            position: relative;
        }
        .rh-tooltip::before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%) translateY(4px);
            background: #1e293b;
            color: #e2e8f0;
            font-size: 0.72rem;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 8px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: all 0.2s ease;
            border: 1px solid rgba(255,255,255,0.08);
            z-index: 10;
        }
        .rh-tooltip:hover::before {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .rh-card__countdown {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            gap: clamp(4px, 1.2vw, 10px);
        }

        .rh-countdown__unit {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .rh-countdown__digit {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: clamp(2.8rem, 9vw, 4.8rem);
            padding: clamp(8px, 2vw, 16px) clamp(4px, 1vw, 14px);
            background: rgba(0,0,0,0.4);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            font-family: 'Inter', ui-monospace, SFMono-Regular, monospace;
            font-size: clamp(1.8rem, 7vw, 3.4rem);
            font-weight: 900;
            font-variant-numeric: tabular-nums;
            color: #fff;
            letter-spacing: 0.04em;
            text-align: center;
            line-height: 1;
            text-shadow: 0 2px 16px rgba(0,0,0,0.3);
        }

        .rh-countdown__label {
            font-size: 0.85rem;
            font-weight: 700;
            color: rgba(255,255,255,0.7);
            text-transform: uppercase;
            letter-spacing: 0.14em;
        }

        .rh-countdown__sep {
            font-size: clamp(1.2rem, 4vw, 2.2rem);
            font-weight: 800;
            color: rgba(255,255,255,0.5);
            padding: clamp(8px, 2vw, 16px) 2px 0;
            line-height: 1;
        }
        /* --- Mobile compact overrides (must come after base rules) --- */
        @media (max-width: 639px) {
            .rh-card__subtitle {
                font-size: 0.9rem;
            }
            .rh-roulette-wrapper {
                margin: 2px 0;
            }
            .rh-roulette-svg {
                width: 100px;
                height: 100px;
            }
            .rh-countdown__digit {
                min-width: 2.2rem;
                padding: 4px 4px;
                font-size: 1.3rem;
                border-radius: 8px;
            }
            .rh-countdown__label {
                font-size: 0.65rem;
            }
            .rh-countdown__sep {
                font-size: 0.9rem;
                padding: 4px 1px 0;
            }
            .rh-card__cta {
                padding: 10px 16px;
                font-size: 0.8rem;
                border-radius: 10px;
            }
            .rh-card__title {
                font-size: 1.5rem;
            }
        }

        /* --- Gift Card Decoration (Daily) --- */
        .rh-card--daily {
            border: 1.5px solid rgba(251,191,36,0.22);
            overflow: visible;
            margin-top: 48px;
        }
        .rh-card--daily .rh-card__title,
        .rh-card--daily .rh-card__subtitle,
        .rh-card--daily .rh-card__countdown,
        .rh-card--daily .rh-card__footer {
            position: relative;
            z-index: 2;
        }
        .rh-gift-dots {
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle, rgba(255,255,255,0.035) 1px, transparent 1px);
            background-size: 20px 20px;
            pointer-events: none;
            z-index: 0;
            border-radius: inherit;
        }
        .rh-gift-ribbon {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 100%;
            background: #fbbf24;
            pointer-events: none;
            z-index: 0;
        }
        .rh-gift-ribbon-h {
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 100%;
            height: 20px;
            background: #fbbf24;
            pointer-events: none;
            z-index: 0;
        }
        .rh-gift-bow {
            position: absolute;
            top: -54px;
            left: 50%;
            transform: translateX(-50%);
            width: 140px;
            height: 90px;
            z-index: 3;
            pointer-events: none;
            filter: drop-shadow(0 3px 10px rgba(245,158,11,0.45));
        }
        .rh-gift-sparkle {
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
            z-index: 1;
        }
        .rh-gs--1 { width:5px; height:5px; top:16%; left:11%; background:#fbbf24; animation: rh-gs-pulse 2.8s ease-in-out infinite; }
        .rh-gs--2 { width:4px; height:4px; top:26%; right:9%;  background:#ec4899; animation: rh-gs-pulse 2.8s ease-in-out 0.5s infinite; }
        .rh-gs--3 { width:5px; height:5px; bottom:28%; left:7%; background:#38bdf8; animation: rh-gs-pulse 2.8s ease-in-out 1s infinite; }
        .rh-gs--4 { width:4px; height:4px; bottom:14%; right:12%; background:#a78bfa; animation: rh-gs-pulse 2.8s ease-in-out 1.5s infinite; }
        .rh-gs--5 { width:3px; height:3px; top:58%; left:14%; background:#6ee7b7; animation: rh-gs-pulse 2.8s ease-in-out 2s infinite; }
        .rh-gs--6 { width:3px; height:3px; top:40%; right:7%;  background:#fcd34d; animation: rh-gs-pulse 2.8s ease-in-out 0.8s infinite; }
        @keyframes rh-gs-pulse {
            0%, 100% { opacity: 1; }
            50%      { opacity: 0.12; }
        }
        @media (max-width: 639px) {
            .rh-card--daily {
                margin-top: 34px;
            }
            .rh-gift-bow {
                width: 96px;
                height: 62px;
                top: -38px;
            }
            .rh-gift-ribbon, .rh-gift-ribbon-h {
                width: 14px;
                height: 14px;
            }
            .rh-gift-ribbon { width: 14px; height: 100%; }
            .rh-gift-ribbon-h { width: 100%; height: 14px; }
        }

    </style>

    <div class="rh-container">
        <div class="rh-grid">

                <!-- ========== CARD 1: DESCUENTO DIARIO ========== -->
                <div class="rh-card rh-card--daily" id="rhCardDaily" onclick="RewardsHub.handleDaily()" role="button" tabindex="0">
                    <div class="rh-gift-dots" aria-hidden="true"></div>
                    <div class="rh-gift-ribbon" aria-hidden="true"></div>
                    <div class="rh-gift-ribbon-h" aria-hidden="true"></div>
                    <svg class="rh-gift-bow" aria-hidden="true" viewBox="0 0 140 90" xmlns="http://www.w3.org/2000/svg">
                        <!-- Connector band (same color as ribbon, bleeds to bottom) -->
                        <rect x="60" y="48" width="20" height="42" fill="#fbbf24"/>
                        <!-- Left tail -->
                        <path d="M54,56 C44,64 30,74 20,80 C17,82 15,80 17,78 C26,72 40,64 52,54" fill="#d97706"/>
                        <!-- Right tail -->
                        <path d="M86,56 C96,64 110,74 120,80 C123,82 125,80 123,78 C114,72 100,64 88,54" fill="#d97706"/>
                        <!-- Left loop -->
                        <path d="M68,44 C60,20 42,4 22,8 C4,12 0,30 8,42 C16,54 40,58 58,50 Z" fill="#fbbf24"/>
                        <!-- Left loop highlight -->
                        <path d="M60,40 C54,24 42,12 28,14 C14,16 10,30 16,38 C22,46 38,50 52,46 Z" fill="#fde68a" opacity="0.3"/>
                        <!-- Left loop fold lines -->
                        <path d="M34,12 C26,22 24,34 28,44" stroke="#d97706" stroke-width="1.2" fill="none" opacity="0.4"/>
                        <path d="M44,10 C38,20 36,32 38,42" stroke="#d97706" stroke-width="0.8" fill="none" opacity="0.25"/>
                        <!-- Right loop -->
                        <path d="M72,44 C80,20 98,4 118,8 C136,12 140,30 132,42 C124,54 100,58 82,50 Z" fill="#fbbf24"/>
                        <!-- Right loop highlight -->
                        <path d="M80,40 C86,24 98,12 112,14 C126,16 130,30 124,38 C118,46 102,50 88,46 Z" fill="#fde68a" opacity="0.3"/>
                        <!-- Right loop fold lines -->
                        <path d="M106,12 C114,22 116,34 112,44" stroke="#d97706" stroke-width="1.2" fill="none" opacity="0.4"/>
                        <path d="M96,10 C102,20 104,32 102,42" stroke="#d97706" stroke-width="0.8" fill="none" opacity="0.25"/>
                        <!-- Center knot -->
                        <rect x="53" y="40" width="34" height="18" rx="5" fill="#f59e0b"/>
                        <!-- Knot highlight -->
                        <rect x="57" y="43" width="26" height="5" rx="2" fill="#fbbf24" opacity="0.4"/>
                        <!-- Knot shadow -->
                        <rect x="57" y="53" width="26" height="3" rx="1.5" fill="#d97706" opacity="0.3"/>
                    </svg>
                    <div class="rh-gift-sparkle rh-gs--1" aria-hidden="true"></div>
                    <div class="rh-gift-sparkle rh-gs--2" aria-hidden="true"></div>
                    <div class="rh-gift-sparkle rh-gs--3" aria-hidden="true"></div>
                    <div class="rh-gift-sparkle rh-gs--4" aria-hidden="true"></div>
                    <div class="rh-gift-sparkle rh-gs--5" aria-hidden="true"></div>
                    <div class="rh-gift-sparkle rh-gs--6" aria-hidden="true"></div>
                    <h3 class="rh-card__title" style="color:<?= rh_txt('daily','title','color','#ffffff',$rh_visuals) ?>;opacity:<?= rh_txt('daily','title','opacity','1',$rh_visuals) ?>"><?= rh_txt('daily','title','text','Descuento Diario',$rh_visuals) ?></h3>
                    <p class="rh-card__subtitle" style="color:<?= rh_txt('daily','subtitle','color','rgba(255,255,255,0.75)',$rh_visuals) ?>;opacity:<?= rh_txt('daily','subtitle','opacity','1',$rh_visuals) ?>"><?= rh_txt('daily','subtitle','text','Próximo giro en:',$rh_visuals) ?></p>
                    <div class="rh-card__countdown" id="rhDailyCountdown">
                        <div class="rh-countdown__unit">
                            <span class="rh-countdown__digit" id="rhCdHours">00</span>
                            <span class="rh-countdown__label">Hrs</span>
                        </div>
                        <span class="rh-countdown__sep">:</span>
                        <div class="rh-countdown__unit">
                            <span class="rh-countdown__digit" id="rhCdMins">00</span>
                            <span class="rh-countdown__label">Min</span>
                        </div>
                        <span class="rh-countdown__sep">:</span>
                        <div class="rh-countdown__unit">
                            <span class="rh-countdown__digit" id="rhCdSecs">00</span>
                            <span class="rh-countdown__label">Seg</span>
                        </div>
                    </div>
                    <div class="rh-card__footer">
                        <?php if ($rh_user_state === 'guest'): ?>
                            <div class="rh-card__cta rh-cta--login" style="color:<?= rh_txt('daily','cta_guest','color','rgba(255,255,255,0.9)',$rh_visuals) ?>;opacity:<?= rh_txt('daily','cta_guest','opacity','1',$rh_visuals) ?>"><svg class="rh-cta-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg><span class="cta-text">Inicia sesión para participar</span><span class="cta-mobile-text" style="display:none;">Iniciar sesión</span></div>
                        <?php elseif ($rh_user_state === 'unverified'): ?>
                            <div class="rh-card__cta rh-cta--disabled" style="color:<?= rh_txt('daily','cta_unverified','color','rgba(255,255,255,0.35)',$rh_visuals) ?>;opacity:<?= rh_txt('daily','cta_unverified','opacity','1',$rh_visuals) ?>"><?= rh_txt('daily','cta_unverified','text','Verificación requerida',$rh_visuals) ?></div>
                        <?php else: ?>
                            <div class="rh-card__cta rh-cta--active-daily" style="color:<?= rh_txt('daily','cta_active','color','#022c22',$rh_visuals) ?>;opacity:<?= rh_txt('daily','cta_active','opacity','1',$rh_visuals) ?>"><?= rh_txt('daily','cta_active','text','¡Girar ahora!',$rh_visuals) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ========== CARD 2: SORTEO FLASH ========== -->
                <a href="/juegos/sorteo_flash/" class="rh-card rh-card--flash" id="rhCardFlash">
                    <h3 class="rh-card__title" style="color:<?= rh_txt('flash','title','color','#ffffff',$rh_visuals) ?>;opacity:<?= rh_txt('flash','title','opacity','1',$rh_visuals) ?>"><?= rh_txt('flash','title','text','Sorteo Flash',$rh_visuals) ?></h3>
                    <div class="rh-roulette-wrapper">
                        <svg class="rh-roulette-svg" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="100" cy="100" r="96" fill="none" stroke="#fbbf24" stroke-width="4"/>
                            <circle cx="100" cy="100" r="88" fill="none" stroke="#f59e0b" stroke-width="2"/>
                            <path d="M100,100 L100,4 A96,96 0 0,1 167.88,31.12 Z" fill="#ef4444" opacity="0.85"/>
                            <path d="M100,100 L167.88,31.12 A96,96 0 0,1 196,100 Z" fill="#f59e0b" opacity="0.85"/>
                            <path d="M100,100 L196,100 A96,96 0 0,1 167.88,168.88 Z" fill="#22c55e" opacity="0.85"/>
                            <path d="M100,100 L167.88,168.88 A96,96 0 0,1 100,196 Z" fill="#3b82f6" opacity="0.85"/>
                            <path d="M100,100 L100,196 A96,96 0 0,1 32.12,168.88 Z" fill="#a855f7" opacity="0.85"/>
                            <path d="M100,100 L32.12,168.88 A96,96 0 0,1 4,100 Z" fill="#ec4899" opacity="0.85"/>
                            <path d="M100,100 L4,100 A96,96 0 0,1 32.12,31.12 Z" fill="#14b8a6" opacity="0.85"/>
                            <path d="M100,100 L32.12,31.12 A96,96 0 0,1 100,4 Z" fill="#f97316" opacity="0.85"/>
                            <line x1="100" y1="100" x2="100" y2="4" stroke="#fde68a" stroke-width="1.5" opacity="0.6"/>
                            <line x1="100" y1="100" x2="167.88" y2="31.12" stroke="#fde68a" stroke-width="1.5" opacity="0.6"/>
                            <line x1="100" y1="100" x2="196" y2="100" stroke="#fde68a" stroke-width="1.5" opacity="0.6"/>
                            <line x1="100" y1="100" x2="167.88" y2="168.88" stroke="#fde68a" stroke-width="1.5" opacity="0.6"/>
                            <line x1="100" y1="100" x2="100" y2="196" stroke="#fde68a" stroke-width="1.5" opacity="0.6"/>
                            <line x1="100" y1="100" x2="32.12" y2="168.88" stroke="#fde68a" stroke-width="1.5" opacity="0.6"/>
                            <line x1="100" y1="100" x2="4" y2="100" stroke="#fde68a" stroke-width="1.5" opacity="0.6"/>
                            <line x1="100" y1="100" x2="32.12" y2="31.12" stroke="#fde68a" stroke-width="1.5" opacity="0.6"/>
                            <circle cx="100" cy="100" r="18" fill="#1e1b4b" stroke="#fbbf24" stroke-width="3"/>
                            <circle cx="100" cy="100" r="8" fill="#fbbf24"/>
                            <circle cx="100" cy="40" r="4" fill="#ffffff" opacity="0.7"/>
                            <circle cx="142" cy="58" r="4" fill="#ffffff" opacity="0.7"/>
                            <circle cx="160" cy="100" r="4" fill="#ffffff" opacity="0.7"/>
                            <circle cx="142" cy="142" r="4" fill="#ffffff" opacity="0.7"/>
                            <circle cx="100" cy="160" r="4" fill="#ffffff" opacity="0.7"/>
                            <circle cx="58" cy="142" r="4" fill="#ffffff" opacity="0.7"/>
                            <circle cx="40" cy="100" r="4" fill="#ffffff" opacity="0.7"/>
                            <circle cx="58" cy="58" r="4" fill="#ffffff" opacity="0.7"/>
                        </svg>
                    </div>
                    <div class="rh-card__footer">
                        <div class="rh-card__cta rh-cta--active-flash" style="color:<?= rh_txt('flash','cta_active','color','#451a03',$rh_visuals) ?>;opacity:<?= rh_txt('flash','cta_active','opacity','1',$rh_visuals) ?>"><?= rh_txt('flash','cta_active','text','Entrar al sorteo',$rh_visuals) ?></div>
                    </div>
                </a>

        </div><!-- .rh-grid -->
    </div><!-- .rh-container -->

    <!-- ========== MODAL: VERIFICACIÓN REQUERIDA ========== -->
    <div class="rh-modal-overlay" id="rhVerifyModal">
        <div class="rh-modal">
            <button class="rh-modal__close" onclick="RewardsHub.closeModal()" aria-label="Cerrar">&times;</button>
            <div class="rh-modal__icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    <path d="m9 12 2 2 4-4"></path>
                </svg>
            </div>
            <h3 class="rh-modal__title">Verificación Requerida</h3>
            <p class="rh-modal__text">Para acceder a las recompensas exclusivas necesitas verificar tu cuenta.</p>
            <div class="rh-modal__actions">
                <a href="/cuenta/" class="rh-modal__btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Ir a mi cuenta
                </a>
                <button class="rh-modal__btn-secondary" onclick="RewardsHub.closeModal()">Ahora No</button>
            </div>
        </div>
    </div>

</div><!-- .rh-wrapper -->

<script>
    const RewardsHub = (() => {
        const userState = '<?php echo $rh_user_state; ?>';
        const loginUrl  = '<?php echo $login_url; ?>';

        function handleDaily() {
            if (userState === 'guest') {
                window.location.href = loginUrl;
            } else if (userState === 'unverified') {
                openModal();
            } else {
                if (typeof DailySpin !== 'undefined' && DailySpin.openModal) {
                    DailySpin.openModal();
                }
            }
        }

        function openModal() {
            const m = document.getElementById('rhVerifyModal');
            m.style.display = 'flex';
            requestAnimationFrame(() => m.classList.add('rh-modal--visible'));
        }

        function closeModal() {
            const m = document.getElementById('rhVerifyModal');
            m.classList.remove('rh-modal--visible');
            setTimeout(() => { m.style.display = 'none'; }, 300);
        }

        // Close modal on overlay click
        document.getElementById('rhVerifyModal')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeModal();
        });

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });

        // --- Daily Countdown (target: 7:01 PM local) ---
        let _dailyCountdownId = null;

        function _getNextTarget() {
            const now = new Date();
            const target = new Date(now);
            target.setHours(19, 1, 0, 0);
            if (now >= target) target.setDate(target.getDate() + 1);
            return target;
        }

        function _pad(n) { return n < 10 ? '0' + n : '' + n; }

        function _tickCountdown() {
            const diff = _getNextTarget() - Date.now();
            const totalSec = Math.max(0, Math.floor(diff / 1000));
            const h = Math.floor(totalSec / 3600);
            const m = Math.floor((totalSec % 3600) / 60);
            const s = totalSec % 60;

            const elH = document.getElementById('rhCdHours');
            const elM = document.getElementById('rhCdMins');
            const elS = document.getElementById('rhCdSecs');
            if (elH) elH.textContent = _pad(h);
            if (elM) elM.textContent = _pad(m);
            if (elS) elS.textContent = _pad(s);
        }

        function _initCountdown() {
            if (_dailyCountdownId) return;
            _tickCountdown();
            _dailyCountdownId = setInterval(_tickCountdown, 1000);
        }

        _initCountdown();

        // --- Dynamic login button text for mobile ---
        function updateLoginButtonText() {
            const loginBtn = document.querySelector('.rh-cta--login');
            if (!loginBtn) return;
            
            const textSpan = loginBtn.querySelector('.cta-text');
            const mobileTextSpan = loginBtn.querySelector('.cta-mobile-text');
            
            if (window.innerWidth <= 639) {
                if (textSpan) textSpan.style.display = 'none';
                if (mobileTextSpan) mobileTextSpan.style.display = 'inline';
            } else {
                if (textSpan) textSpan.style.display = 'inline';
                if (mobileTextSpan) mobileTextSpan.style.display = 'none';
            }
        }

        // Initial check and resize listener
        updateLoginButtonText();
        window.addEventListener('resize', updateLoginButtonText);

        window.addEventListener('beforeunload', () => {
            if (_dailyCountdownId) { clearInterval(_dailyCountdownId); _dailyCountdownId = null; }
        });

        // --- Diagonal shimmer on hover (overlapping, with cooldown) ---
        const SHIMMER_COOLDOWN = 300;
        const SHIMMER_DURATION = 600;
        const _shimmerTimers = new WeakMap();

        document.querySelectorAll('.rh-card').forEach(card => {
            card.addEventListener('mouseenter', () => {
                const last = _shimmerTimers.get(card) || 0;
                if (Date.now() - last < SHIMMER_COOLDOWN) return;
                _shimmerTimers.set(card, Date.now());
                const s = document.createElement('span');
                s.className = 'rh-shimmer';
                card.appendChild(s);
                setTimeout(() => s.remove(), SHIMMER_DURATION + 50);
            });
        });

        return { handleDaily, openModal, closeModal };
    })();
</script>

<?php include_once __DIR__ . '/daily_spin/widget.php'; ?>
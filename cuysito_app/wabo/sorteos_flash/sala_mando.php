<?php
// wabo/sorteos_flash/sala_mando.php
// Sala de Mando (dashboard en vivo) — PÁGINA 100% INDEPENDIENTE (full-screen).
// NO usa admin_header/admin_footer: carga sus propias dependencias y ocupa todo
// el viewport (sin menú lateral). Mantiene el guard de sesión del panel admin.
// Se accede con ?id=<raffle_id>. Sin id (acceso directo desde el menú) muestra
// el lobby: sorteo en curso destacado + lista de sorteos pasados para elegir.

require_once __DIR__ . '/../../api/session_security.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: /wabo/login.php');
    exit;
}
header('Content-Type: text/html; charset=utf-8');

$raffleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sala de Mando</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/avatar-fallback.js?v=20260914"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script>
        const savedTheme = localStorage.getItem('theme') || 'dark';
        document.documentElement.setAttribute('data-theme', savedTheme);
        document.documentElement.classList.toggle('dark', savedTheme !== 'light');
    </script>

    <style>
        /* Tokens de tema (heredados antes de admin_header, ahora propios) */
        :root {
            --primary-light:#f1f3f5; --secondary-light:#ffffff; --text-primary-light:#212529; --text-secondary-light:#495057; --accent-light:#f59e0b; --border-light:#e9ecef;
            --primary-dark:#050505; --secondary-dark:#050505; --text-primary-dark:#f1f5f9; --text-secondary-dark:#94a3b8; --accent-dark:#f59e0b; --border-dark:#1a1a1a;
        }
        [data-theme="light"] { --primary:var(--primary-light); --secondary:var(--secondary-light); --text-primary:var(--text-primary-light); --text-secondary:var(--text-secondary-light); --accent:var(--accent-light); --border-color:var(--border-light); }
        [data-theme="dark"]  { --primary:var(--primary-dark);  --secondary:var(--secondary-dark);  --text-primary:var(--text-primary-dark);  --text-secondary:var(--text-secondary-dark);  --accent:var(--accent-dark);  --border-color:var(--border-dark); }

        :root {
            --flash-bg-main: var(--primary); --flash-surface: var(--secondary); --flash-border: var(--border-color);
            --flash-row: var(--secondary); --flash-row-alt: var(--secondary); --flash-row-hover: rgba(0,0,0,.035);
            --flash-text: var(--text-primary); --flash-text-muted: var(--text-secondary); --flash-text-dim: rgba(73,80,87,.75);
            --flash-muted: rgba(17,24,39,.04); --flash-accent:#22d3ee; --flash-accent-hover:#06b6d4;
            --flash-input-bg: var(--secondary); --flash-overlay: rgba(0,0,0,.55);
        }
        html[data-theme="dark"] {
            --flash-bg-main:#050505; --flash-surface:#0a0a0a; --flash-border:var(--border-color);
            --flash-row:#0a0a0a; --flash-row-alt:#0d0d0d; --flash-row-hover:#111111;
            --flash-text:#f3f4f6; --flash-text-muted:#a1a1aa; --flash-text-dim:rgba(161,161,170,.85);
            --flash-muted:rgba(255,255,255,.06); --flash-input-bg:rgba(255,255,255,.03); --flash-overlay:rgba(0,0,0,.70);
        }
        html[data-theme="light"] {
            --flash-bg-main:#f1f3f5; --flash-surface:#ffffff; --flash-border:var(--border-color);
            --flash-row:#ffffff; --flash-row-alt:#f9fafb; --flash-row-hover:#f3f4f6;
            --flash-text-dim:rgba(75,85,99,.75); --flash-muted:rgba(17,24,39,.04);
            --flash-input-bg:#ffffff; --flash-overlay:rgba(0,0,0,.45);
        }

        html, body { margin:0; padding:0; width:100vw; height:100vh; overflow:hidden; background: var(--flash-bg-main); font-family:'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
    </style>
</head>
<body>

<style>
    #liveDashModal, #cmdCenter { --ld-live: #22c55e; --ld-uniq: #6366f1; --ld-part: #f59e0b; --ld-wl: #0ea5e9; }
    .ld-card { background: var(--flash-surface); border: 1px solid var(--flash-border); border-radius: 18px; }
    .ld-header {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
        padding: 1.1rem 1.25rem; color: #fff;
        background: linear-gradient(120deg, #4f46e5 0%, #7c3aed 55%, #0ea5e9 100%);
    }
    .ld-eyebrow { display: inline-flex; align-items: center; gap: .45rem; font-size: .66rem; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; opacity: .92; }
    .ld-pulse { width: 9px; height: 9px; border-radius: 50%; background: #34d399; box-shadow: 0 0 0 0 rgba(52,211,153,.8); animation: ldPulse 1.4s infinite; }
    @keyframes ldPulse { 0% { box-shadow: 0 0 0 0 rgba(52,211,153,.7);} 70%{ box-shadow: 0 0 0 8px rgba(52,211,153,0);} 100%{ box-shadow:0 0 0 0 rgba(52,211,153,0);} }
    .ld-title { font-size: 1.18rem; font-weight: 800; margin-top: .15rem; line-height: 1.2; }
    .ld-close { color: #fff; opacity: .85; height: 30px; width: 30px; border-radius: 9px; background: rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; transition: .15s; }
    .ld-close:hover { opacity: 1; background: rgba(255,255,255,.28); }
    .ld-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: .7rem; }
    .ld-stat { border: 1px solid var(--flash-border); border-radius: 14px; padding: .8rem .9rem; background: var(--flash-muted); }
    .ld-stat__label { display:flex; align-items:center; gap:.4rem; font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--flash-text-muted); }
    .ld-stat__num { font-size: 1.9rem; font-weight: 900; line-height: 1.1; font-variant-numeric: tabular-nums; color: var(--flash-text); }
    .ld-stat__sub { font-size: .66rem; color: var(--flash-text-muted); }
    .ld-stat--live .ld-stat__num { color: var(--ld-live); }
    .ld-stat--unique .ld-stat__num { color: var(--ld-uniq); }
    .ld-stat--part .ld-stat__num { color: var(--ld-part); }
    .ld-chart-wrap { margin-top: 1rem; border: 1px solid var(--flash-border); border-radius: 14px; padding: .85rem .95rem .6rem; background: var(--flash-surface); }
    .ld-chart-head { display:flex; align-items:center; justify-content:space-between; font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color: var(--flash-text-muted); margin-bottom:.4rem; }
    .ld-chart-hint { font-weight: 600; text-transform: none; letter-spacing: 0; opacity: .8; }
    #ldChart { width: 100%; display: block; }
    .ld-params { margin-top: 1rem; display: grid; grid-template-columns: repeat(2, 1fr); gap: .55rem .9rem; }
    .ld-param { display:flex; flex-direction:column; gap:.1rem; border-bottom: 1px dashed var(--flash-border); padding-bottom:.35rem; }
    .ld-param__k { font-size: .64rem; font-weight: 800; text-transform: uppercase; letter-spacing:.05em; color: var(--flash-text-muted); }
    .ld-param__v { font-size: .85rem; font-weight: 600; color: var(--flash-text); }
    @media (max-width: 560px) { .ld-stats { grid-template-columns: 1fr; } .ld-params { grid-template-columns: 1fr; } }
    .flash-icon-btn--live:hover { color: #6366f1 !important; border-color: #6366f1 !important; }
    .ld-stat--wl .ld-stat__num { color: var(--ld-wl); }

    /* =====================================================================
     * SALA DE MANDO — Dashboard premium (rediseño UI/UX)
     * Layout: panel a pantalla completa (fixed) en flex; columna central
     * desplazable + barra lateral fija de Participantes con scroll propio.
     * Tipografía Inter, espaciados generosos, estética "screenshot-friendly".
     * ===================================================================== */
    /* La sala (overlay) arranca oculta; gana a #cmdCenter por especificidad ID+clase. */
    #cmdCenter.hidden { display: none !important; }
    #cmdCenter {
        position: fixed;
        inset: 0;
        z-index: 60; /* por encima del sidebar (50) y el header (30) del admin */
        background: var(--flash-bg-main);
        display: flex;
        height: 100vh;
        overflow: hidden;
        font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        -webkit-font-smoothing: antialiased;
    }
    #cmdCenter h1, #cmdCenter h2, #cmdCenter h3, #cmdCenter h4,
    #cmdCenter p, #cmdCenter span, #cmdCenter div, #cmdCenter button,
    #cmdCenter input, #cmdCenter td, #cmdCenter th, #cmdCenter a, #cmdCenter label {
        font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    }
    /* Los iconos (FontAwesome) deben usar SIEMPRE su propia familia, no Inter. */
    #cmdCenter i.fas, #cmdCenter i.fa-solid, #cmdCenter .fas, #cmdCenter .fa-solid { font-family: 'Font Awesome 6 Free' !important; font-weight: 900 !important; }
    #cmdCenter i.far, #cmdCenter i.fa-regular, #cmdCenter .far, #cmdCenter .fa-regular { font-family: 'Font Awesome 6 Free' !important; font-weight: 400 !important; }
    #cmdCenter i.fab, #cmdCenter i.fa-brands, #cmdCenter .fab, #cmdCenter .fa-brands { font-family: 'Font Awesome 6 Brands' !important; font-weight: 400 !important; }

    /* --- Columna central (scroll propio) --- */
    .cc-main {
        flex: 1 1 auto; min-width: 0; height: 100%;
        overflow-y: auto; -webkit-overflow-scrolling: touch;
        padding: 1.6rem clamp(1rem, 2.5vw, 2.4rem) 3rem;
    }
    .cc-main__inner { max-width: 1180px; margin: 0 auto; }

    /* --- Topbar --- */
    .cc-topbar { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.6rem; flex-wrap: wrap; }
    .cc-back {
        height: 44px; width: 44px; border-radius: 13px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; font-size: 1.05rem;
        background: var(--flash-surface); border: 1px solid var(--flash-border); color: var(--flash-text);
        transition: .18s;
    }
    .cc-back:hover { background: var(--flash-muted); transform: translateX(-2px); }
    .cc-topbar__title { flex: 1 1 240px; min-width: 0; }
    .cc-eyebrow { display: inline-flex; align-items: center; gap: .5rem; font-size: .64rem; font-weight: 800; letter-spacing: .2em; text-transform: uppercase; color: var(--flash-text-muted); }
    .cc-pulse { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 0 rgba(34,197,94,.7); animation: ldPulse 1.5s infinite; }
    .cc-title { font-size: 1.6rem; font-weight: 800; color: var(--flash-text); line-height: 1.1; margin-top: .25rem; letter-spacing: -.02em; }
    .cc-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .cc-status {
        font-size: .64rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em;
        padding: .4rem .8rem; border-radius: 999px; background: var(--flash-muted); color: var(--flash-text-muted);
        border: 1px solid var(--flash-border);
    }
    .cc-status--open { background: rgba(34,197,94,.14); color: #22c55e; border-color: rgba(34,197,94,.4); }
    .cc-status--scheduled { background: rgba(245,158,11,.14); color: #f59e0b; border-color: rgba(245,158,11,.4); }
    .cc-status--running, .cc-status--finished { background: rgba(99,102,241,.14); color: #6366f1; border-color: rgba(99,102,241,.4); }
    .cc-act {
        display: inline-flex; align-items: center; gap: .45rem; font-size: .78rem; font-weight: 700;
        padding: .55rem .9rem; border-radius: 12px; border: 1px solid var(--flash-border);
        background: var(--flash-surface); color: var(--flash-text); transition: .18s;
    }
    .cc-act:hover { background: var(--flash-muted); }
    .cc-act--green { border-color: rgba(34,197,94,.5); color: #22c55e; }
    .cc-act--amber { border-color: rgba(245,158,11,.5); color: #f59e0b; }

    /* --- Secciones numeradas --- */
    .cc-section { margin-bottom: 1.9rem; }
    .cc-sec-head { display: flex; align-items: center; gap: .7rem; margin-bottom: .9rem; }
    .cc-sec-num {
        font-size: .72rem; font-weight: 800; letter-spacing: .05em;
        height: 26px; min-width: 26px; padding: 0 .4rem; border-radius: 8px;
        display: inline-flex; align-items: center; justify-content: center;
        background: var(--flash-muted); color: var(--flash-text-muted); border: 1px solid var(--flash-border);
        font-variant-numeric: tabular-nums;
    }
    .cc-sec-head h3 { font-size: .98rem; font-weight: 800; color: var(--flash-text); letter-spacing: -.01em; margin: 0; }
    .cc-sec-hint { margin-left: auto; font-size: .7rem; font-weight: 600; color: var(--flash-text-muted); }
    .cc-chip-count { font-size: .72rem; font-weight: 800; color: var(--flash-text); background: var(--flash-muted); border: 1px solid var(--flash-border); border-radius: 999px; padding: .15rem .6rem; font-variant-numeric: tabular-nums; }

    /* --- Card base --- */
    .cc-card { background: var(--flash-surface); border: 1px solid var(--flash-border); border-radius: 18px; padding: 1.15rem 1.25rem; }
    .cc-card__head { font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: var(--flash-text-muted); margin-bottom: .85rem; display: flex; align-items: center; gap: .5rem; }
    .cc-card__head img { height: 18px; width: 18px; object-fit: contain; }
    .cc-currency-icon { width: 18px; height: 18px; object-fit: contain; display: block; }
    .lb-coin-meta { display: inline-flex; align-items: center; gap: .3rem; }
    .lb-coin-meta img { width: 17px; height: 17px; object-fit: contain; flex-shrink: 0; }
    .cc-card__count { margin-left: auto; }
    .cc-empty { font-size: .78rem; color: var(--flash-text-muted); padding: .5rem 0; }

    /* --- 01 · Métricas en tiempo real --- */
    .cc-metrics { display: block; }
    .cc-cd-time { font-size: 1.5rem !important; line-height: 1.05; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
    .cc-cd-sub { font-size: .6rem; color: var(--flash-text-muted); margin-top: .1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cc-metrics-right { display: flex; flex-direction: column; gap: 1.1rem; min-width: 0; }
    .cc-metric-strip { display: grid; grid-template-columns: repeat(5, 1fr); gap: .7rem; }
    .cc-metric {
        background: var(--flash-surface); border: 1px solid var(--flash-border); border-radius: 14px;
        padding: .8rem .9rem; position: relative; overflow: hidden;
    }
    .cc-metric::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--mc, #6366f1); }
    .cc-metric__label { display: flex; align-items: center; gap: .35rem; font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--flash-text-muted); }
    .cc-metric__num { font-size: 1.7rem; font-weight: 900; line-height: 1.05; color: var(--flash-text); font-variant-numeric: tabular-nums; margin-top: .15rem; }
    .cc-metric--live { --mc: #22c55e; } .cc-metric--live .cc-metric__num { color: #22c55e; }
    .cc-metric--uniq { --mc: #6366f1; }
    .cc-metric--part { --mc: #f59e0b; }
    .cc-metric--wl { --mc: #0ea5e9; }
    .cc-metric--countdown { --mc: #ef4444; }
    .cc-metric--countdown .cc-metric__num { color: #ef4444; }
    .cc-chart-box { background: var(--flash-surface); border: 1px solid var(--flash-border); border-radius: 16px; padding: .9rem 1rem .6rem; }
    .cc-chart-head { display: flex; align-items: center; justify-content: space-between; font-size: .66rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--flash-text-muted); margin-bottom: .35rem; }
    #ccChart { width: 100%; display: block; }

    /* --- Parámetros --- */
    .cc-params-card { margin-top: 1.1rem; background: var(--flash-surface); border: 1px solid var(--flash-border); border-radius: 16px; padding: 1.05rem 1.2rem; }
    .cc-params { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .8rem 1.4rem; }
    .cc-param { display: flex; flex-direction: column; gap: .15rem; }
    .cc-param__k { font-size: .6rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--flash-text-muted); }
    .cc-param__v { font-size: .88rem; font-weight: 700; color: var(--flash-text); }

    /* --- 02 · Bajo Revisión --- */
    .cc-review-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: .7rem; }
    .cc-rev {
        display: flex; align-items: center; gap: .75rem; padding: .8rem .9rem;
        border-radius: 15px; border: 1px solid rgba(245,158,11,.45);
        background: linear-gradient(180deg, rgba(245,158,11,.08), rgba(245,158,11,.02));
    }
    .cc-rev img { height: 42px; width: 42px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--flash-muted); }
    .cc-rev__main { min-width: 0; flex: 1; }
    .cc-rev__name { font-size: .9rem; font-weight: 800; color: var(--flash-text); display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
    .cc-rev__sub { font-size: .72rem; color: var(--flash-text-muted); margin-top: .15rem; }
    .cc-rev__acts { display: flex; gap: .35rem; flex-shrink: 0; }
    .cc-empty-card { grid-column: 1 / -1; }

    /* --- Botones de acción (icon) --- */
    .cc-iconbtn { height: 34px; width: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: .82rem; border: 1px solid var(--flash-border); background: var(--flash-surface); color: var(--flash-text-muted); transition: .15s; }
    .cc-iconbtn:hover { color: #fff; }
    .cc-iconbtn--wa { color: #25D366; border-color: rgba(37,211,102,.5); }
    .cc-iconbtn--wa:hover { background: #25D366; border-color: #25D366; }
    .cc-iconbtn--ok:hover { background: #22c55e; border-color: #22c55e; }
    .cc-iconbtn--expel:hover { background: #f59e0b; border-color: #f59e0b; }
    .cc-iconbtn--ban:hover { background: #ef4444; border-color: #ef4444; }
    .cc-iconbtn--rename:hover { background: #6366f1; border-color: #6366f1; }
    .cc-iconbtn--info:hover { background: #64748b; border-color: #64748b; }
    .cc-iconbtn--restore { color: #22c55e; border-color: rgba(34,197,94,.5); }
    .cc-iconbtn--restore:hover { background: #22c55e; border-color: #22c55e; }
    .cc-iconbtn:disabled { opacity: .35; cursor: not-allowed; }
    .cc-iconbtn:disabled:hover { background: var(--flash-surface); color: var(--flash-text-muted); border-color: var(--flash-border); }
    /* Indicador (no botón) de pre-elección de premio — solo admin */
    .cc-iconbtn--pre { cursor: default; }
    .cc-iconbtn--pre:hover { color: inherit; }
    .cc-pre--gift { color: #38bdf8; border-color: rgba(56,189,248,.45); }
    .cc-pre--cash { color: #22c55e; border-color: rgba(34,197,94,.45); }
    .cc-pre--skull { color: #f43f5e; border-color: rgba(244,63,94,.5); }
    .cc-pre--coin { color: #f59e0b; border-color: rgba(245,158,11,.45); padding: 0; }
    .cc-coin-face { display: block; }

    /* --- 03 · Alertas IP compartida --- */
    .cc-eye { margin-left: auto; height: 34px; width: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--flash-border); background: var(--flash-surface); color: var(--flash-text-muted); transition: .15s; }
    .cc-eye:hover { background: var(--flash-muted); color: var(--flash-text); }
    .cc-eye.is-masked { color: #ef4444; border-color: rgba(239,68,68,.45); }
    .cc-ip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: .8rem; }
    .cc-ip-card { border-radius: 16px; border: 1px solid var(--gc, var(--flash-border)); background: var(--gbg, var(--flash-surface)); overflow: hidden; }
    .cc-ip-card__top { display: flex; align-items: center; gap: .6rem; padding: .8rem .95rem; cursor: pointer; user-select: none; }
    .cc-ip-dot { height: 10px; width: 10px; border-radius: 50%; background: var(--gc, #ef4444); flex-shrink: 0; box-shadow: 0 0 0 3px var(--gbg, transparent); }
    .cc-ip-meta { min-width: 0; flex: 1; }
    .cc-ip-addr { font-family: ui-monospace, 'SF Mono', monospace; font-size: .82rem; font-weight: 800; color: var(--flash-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cc-ip-sub { font-size: .68rem; color: var(--flash-text-muted); margin-top: .1rem; }
    .cc-ip-badge { font-size: .68rem; font-weight: 800; color: var(--gc, #ef4444); background: var(--gbg, transparent); border: 1px solid var(--gc, #ef4444); border-radius: 999px; padding: .15rem .55rem; flex-shrink: 0; }
    .cc-ip-chevron { color: var(--flash-text-muted); font-size: .75rem; transition: .2s; }
    .cc-ip-card.is-open .cc-ip-chevron { transform: rotate(180deg); }
    .cc-ip-detail { display: none; padding: 0 .95rem .85rem; }
    .cc-ip-card.is-open .cc-ip-detail { display: block; }
    .cc-ip-table { width: 100%; border-collapse: collapse; font-size: .76rem; }
    .cc-ip-table th { text-align: left; font-size: .6rem; text-transform: uppercase; letter-spacing: .05em; color: var(--flash-text-muted); font-weight: 800; padding: .35rem .4rem; border-bottom: 1px solid var(--flash-border); }
    .cc-ip-table td { padding: .4rem .4rem; border-bottom: 1px solid var(--flash-border); color: var(--flash-text); }
    .cc-ip-table tr:last-child td { border-bottom: none; }
    .cc-ip-uname { display: flex; align-items: center; gap: .45rem; }
    .cc-ip-uname img { height: 24px; width: 24px; border-radius: 50%; object-fit: cover; }
    .cc-ip-wa { color: #25D366; cursor: pointer; }

    /* --- 04 · Cards curiosos --- */
    .cc-cards-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.1rem; }
    .cc-mini-list { display: flex; flex-direction: column; gap: .5rem; max-height: 230px; overflow-y: auto; }
    .cc-mini-row { display: flex; align-items: center; gap: .6rem; font-size: .82rem; color: var(--flash-text); }
    .cc-mini-row img { height: 30px; width: 30px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--flash-muted); }
    .cc-mini-name { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cc-mini-meta { margin-left: auto; font-weight: 800; font-size: .78rem; white-space: nowrap; }
    .cc-mini-meta--coins { display: inline-flex; align-items: center; gap: .25rem; }
    .cc-mini-meta--coins img { width: 17px; height: 17px; object-fit: contain; flex-shrink: 0; }
    .cc-search { position: relative; margin-bottom: .7rem; }
    .cc-search i { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); color: var(--flash-text-muted); font-size: .78rem; }
    .cc-search input {
        width: 100%; padding: .6rem .7rem .6rem 2.1rem; border-radius: 12px; font-size: .82rem;
        background: var(--flash-muted); border: 1px solid var(--flash-border); color: var(--flash-text);
    }
    .cc-search input:focus { outline: none; border-color: #6366f1; }
    .cc-userresults { max-height: 260px; overflow-y: auto; display: flex; flex-direction: column; gap: .45rem; }

    /* --- Badges --- */
    .cc-badge { font-size: .58rem; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; padding: .12rem .42rem; border-radius: 999px; white-space: nowrap; }
    .cc-badge--verified { background: rgba(34,197,94,.16); color: #22c55e; }
    .cc-badge--wl { background: rgba(14,165,233,.16); color: #0ea5e9; }
    .cc-badge--new { background: rgba(168,85,247,.16); color: #a855f7; }
    .cc-badge--alert { background: rgba(239,68,68,.16); color: #ef4444; }
    .cc-badge--banned { background: rgba(239,68,68,.22); color: #ef4444; }
    .cc-badge--eb { background: rgba(245,158,11,.16); color: #f59e0b; }
    .cc-badge--wt { background: rgba(251,191,36,.18); color: #d97706; text-transform: none; letter-spacing: 0; }
    .cc-wl-add { display: inline-flex; align-items: center; gap: .35rem; font-size: .72rem; font-weight: 800; padding: .45rem .7rem; border-radius: 10px; border: 1px solid rgba(14,165,233,.5); color: #0ea5e9; background: transparent; transition: .15s; }
    .cc-wl-add:hover { background: #0ea5e9; color: #fff; }

    /* --- Barra lateral de Participantes (aside) --- */
    .cc-aside {
        width: 360px; flex-shrink: 0; height: 100%;
        display: flex; flex-direction: column;
        background: var(--flash-surface); border-left: 1px solid var(--flash-border);
    }
    .cc-aside__head { display: flex; align-items: center; gap: .5rem; padding: 1.15rem 1.25rem .8rem; font-size: .82rem; font-weight: 800; color: var(--flash-text); }
    .cc-aside__head i { color: #f59e0b; }
    .cc-aside__search { padding: 0 1.25rem .8rem; }
    .cc-aside__list { flex: 1; overflow-y: auto; padding: 0 1.25rem 1.5rem; display: flex; flex-direction: column; gap: .45rem; }
    .cc-prow { display: flex; align-items: center; gap: .6rem; padding: .55rem .6rem; border-radius: 12px; border: 1px solid var(--flash-border); background: var(--flash-muted); }
    .cc-prow img { height: 34px; width: 34px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--flash-surface); }
    .cc-prow__main { min-width: 0; flex: 1; }
    .cc-prow__name { font-size: .82rem; font-weight: 700; color: var(--flash-text); display: flex; align-items: center; gap: .3rem; flex-wrap: wrap; }
    .cc-prow__name span.txt { white-space: normal; overflow-wrap: anywhere; word-break: break-word; }
    .cc-verified-check { color: #1d9bf0; font-size: .8rem; flex-shrink: 0; }
    .cc-review-ico { color: #ef4444; font-size: .76rem; flex-shrink: 0; }
    .cc-expelled-ico { color: #f59e0b; font-size: .74rem; flex-shrink: 0; }
    .cc-prow__sub { font-size: .68rem; color: var(--flash-text-muted); display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; margin-top: .1rem; }
    .cc-prow__acts { display: flex; gap: .25rem; flex-shrink: 0; }
    .cc-prow__acts .cc-iconbtn { height: 30px; width: 30px; font-size: .74rem; }
    .cc-prow--review { border-color: rgba(245,158,11,.55); background: rgba(245,158,11,.08); }
    .cc-prow--expelled { border-style: dashed; }
    .cc-prow--expelled img { filter: grayscale(1); opacity: .5; }
    .cc-prow--expelled .cc-prow__name span.txt, .cc-prow--expelled .cc-prow__sub { opacity: .5; }
    .cc-prow--expelled .cc-prow__name span.txt { text-decoration: line-through; }

    @media (max-width: 1200px) {
        .cc-metrics { grid-template-columns: 1fr; }
        .cc-countdown { flex-direction: row; align-items: center; justify-content: space-between; }
        .cc-cd-time { font-size: 2.4rem; }
    }
    /* --- Lobby (sin ?id=): elegir sorteo --- */
    #ccLobby { height: 100vh; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: 1.6rem clamp(1rem, 2.5vw, 2.4rem) 3rem; background: var(--flash-bg-main); color: var(--flash-text); }
    .lb-inner { max-width: 1100px; margin: 0 auto; }
    .lb-card { background: var(--flash-surface); border: 1px solid var(--flash-border); border-radius: 18px; }
    .lb-hero { display: block; position: relative; overflow: hidden; border-radius: 20px; padding: 1.4rem 1.5rem; color: #fff; text-decoration: none;
        background: linear-gradient(120deg, #4f46e5 0%, #7c3aed 55%, #0ea5e9 100%); box-shadow: 0 18px 40px -18px rgba(79,70,229,.6); transition: transform .18s, box-shadow .18s; }
    .lb-hero:hover { transform: translateY(-2px); box-shadow: 0 24px 46px -18px rgba(79,70,229,.75); }
    .lb-hero + .lb-hero { margin-top: .8rem; }
    .lb-hero__eyebrow { display: inline-flex; align-items: center; gap: .45rem; font-size: .66rem; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; opacity: .92; }
    .lb-hero__title { font-size: 1.5rem; font-weight: 900; line-height: 1.15; margin-top: .3rem; letter-spacing: -.02em; }
    .lb-hero__meta { display: flex; flex-wrap: wrap; gap: .5rem 1.2rem; margin-top: .9rem; font-size: .8rem; opacity: .95; }
    .lb-hero__cta { position: absolute; right: 1.4rem; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.35); border-radius: 12px; padding: .6rem 1rem; font-weight: 800; font-size: .85rem; white-space: nowrap; }
    .lb-row { display: grid; grid-template-columns: 3rem 1fr auto; gap: 1rem; align-items: center; padding: .8rem 1rem; border-top: 1px solid var(--flash-border); color: inherit; text-decoration: none; transition: background .15s; }
    .lb-row:first-child { border-top: none; }
    .lb-row:hover { background: var(--flash-row-hover); }
    .lb-row__id { font-family: ui-monospace, monospace; font-size: .78rem; color: var(--flash-text-muted); }
    .lb-row__title { font-weight: 700; font-size: .92rem; }
    .lb-row__sub { font-size: .74rem; color: var(--flash-text-muted); margin-top: .15rem; }
    .lb-badge { display: inline-flex; align-items: center; gap: .3rem; padding: .18rem .55rem; border-radius: 999px; font-size: .64rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; white-space: nowrap; }
    .lb-badge--live { background: rgba(34,197,94,.16); color: #22c55e; } .lb-badge--sched { background: rgba(14,165,233,.16); color: #0ea5e9; }
    .lb-badge--done { background: rgba(148,163,184,.16); color: #94a3b8; } .lb-badge--cancel { background: rgba(239,68,68,.16); color: #ef4444; }
    .lb-search { width: 100%; max-width: 20rem; background: transparent; border: 1px solid var(--flash-border); color: var(--flash-text); border-radius: 12px; padding: .55rem .9rem .55rem 2.2rem; font-size: .85rem; }
    .lb-search:focus { outline: none; border-color: var(--flash-accent); box-shadow: 0 0 0 3px rgba(34,211,238,.18); }
    @media (max-width: 640px) { .lb-hero__cta { position: static; transform: none; display: inline-block; margin-top: 1rem; } .lb-row { grid-template-columns: 1fr auto; } .lb-row__id { display: none; } }

    @media (max-width: 900px) {
        #cmdCenter { flex-direction: column; height: 100vh; overflow-y: auto; }
        .cc-main { height: auto; overflow: visible; }
        .cc-aside { width: 100%; height: auto; border-left: none; border-top: 1px solid var(--flash-border); }
        .cc-aside__list { max-height: 60vh; }
    }
    @media (max-width: 560px) {
        .cc-metric-strip { grid-template-columns: repeat(2, 1fr); }
    }
</style>

<?php if ($raffleId <= 0): ?>
<!-- ===================== LOBBY: elegir sorteo ===================== -->
<section id="ccLobby">
    <div class="lb-inner">
        <div class="cc-topbar">
            <button type="button" onclick="window.location.href='gestionar_sorteos.php'" class="cc-back" title="Volver al panel"><i class="fas fa-arrow-left"></i></button>
            <div class="cc-topbar__title">
                <div class="cc-eyebrow"><span class="cc-pulse"></span> Sala de Mando</div>
                <h2 class="cc-title">Elige un sorteo</h2>
            </div>
        </div>

        <div id="lbFeatured" class="cc-section">
            <div class="cc-sec-head"><span class="cc-sec-num">01</span><h3>En curso</h3><span class="cc-sec-hint" id="lbFeaturedHint">cargando…</span></div>
            <div id="lbFeaturedBody"></div>
        </div>

        <div class="cc-section">
            <div class="cc-sec-head" style="flex-wrap: wrap;">
                <span class="cc-sec-num">02</span><h3>Sorteos pasados</h3><span class="cc-sec-hint" id="lbPastHint"></span>
                <div style="margin-left: auto; position: relative;"><i class="fas fa-search" style="position: absolute; left: .8rem; top: 50%; transform: translateY(-50%); font-size: .75rem; color: var(--flash-text-muted);"></i><input id="lbSearch" class="lb-search" type="text" placeholder="Buscar por título, ID o premio…" autocomplete="off"></div>
            </div>
            <div class="lb-card" id="lbPastBody"><div style="padding: 2rem; text-align: center; color: var(--flash-text-muted);"><i class="fas fa-circle-notch fa-spin"></i></div></div>
        </div>
    </div>
</section>
<?php endif; ?>

<div id="cmdCenter"<?php if ($raffleId <= 0) echo ' class="hidden" style="display:none;"'; ?>>
    <!-- ===================== COLUMNA CENTRAL ===================== -->
    <main class="cc-main">
        <div class="cc-main__inner">
            <!-- TOP BAR -->
            <div class="cc-topbar">
                <button type="button" onclick="window.location.href='gestionar_sorteos.php'" class="cc-back" title="Volver al panel">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="cc-topbar__title">
                    <div class="cc-eyebrow"><span class="cc-pulse"></span> Sala de Mando</div>
                    <h2 id="ccTitle" class="cc-title">Sorteo</h2>
                </div>
                <div class="cc-actions">
                    <span id="ccStatus" class="cc-status">—</span>
                    <button type="button" onclick="window.location.href='sala_mando.php'" class="cc-act" title="Elegir otro sorteo"><i class="fas fa-exchange-alt"></i> Cambiar sorteo</button>
                    <button type="button" id="ccBtnEbWindow" onclick="ccStartEbWindow()" class="cc-act cc-act--green hidden"><i class="fas fa-stopwatch"></i> Iniciar ventana</button>
                    <button type="button" id="ccBtnForce" onclick="ccForceStart()" class="cc-act cc-act--amber hidden"><i class="fas fa-bolt"></i> Forzar inicio</button>
                    <button type="button" onclick="ccCopyLink()" class="cc-act"><i class="fas fa-link"></i> Enlace</button>
                </div>
            </div>

            <!-- 01 · MÉTRICAS EN TIEMPO REAL -->
            <section class="cc-section">
                <div class="cc-sec-head">
                    <span class="cc-sec-num">01</span>
                    <h3>Métricas en Tiempo Real</h3>
                    <span id="ccChartHint" class="cc-sec-hint">esperando datos…</span>
                </div>
                <div class="cc-metrics">
                    <div class="cc-metrics-right">
                        <div class="cc-metric-strip">
                            <div class="cc-metric cc-metric--countdown">
                                <div class="cc-metric__label"><i class="fas fa-hourglass-half"></i> <span id="ccCdLabel">Tiempo</span></div>
                                <div id="ccCountdown" class="cc-metric__num cc-cd-time">--:--</div>
                                <div id="ccCdSub" class="cc-cd-sub">—</div>
                            </div>
                            <div class="cc-metric cc-metric--live"><div class="cc-metric__label"><i class="fas fa-bolt"></i> En vivo</div><div id="ccLive" class="cc-metric__num">0</div></div>
                            <div class="cc-metric cc-metric--uniq"><div class="cc-metric__label"><i class="fas fa-eye"></i> Espectadores</div><div id="ccUnique" class="cc-metric__num">0</div></div>
                            <div class="cc-metric cc-metric--part"><div class="cc-metric__label"><i class="fas fa-ticket-alt"></i> Inscritos</div><div id="ccParticipants" class="cc-metric__num">0</div></div>
                            <div class="cc-metric cc-metric--wl"><div class="cc-metric__label"><i class="fas fa-shield-alt"></i> Lista blanca</div><div id="ccWhitelist" class="cc-metric__num">0</div></div>
                        </div>
                        <div class="cc-chart-box">
                            <div class="cc-chart-head"><span><i class="fas fa-chart-line mr-1" style="color: var(--ld-live);"></i> Rating en vivo</span></div>
                            <canvas id="ccChart" height="300"></canvas>
                        </div>
                    </div>
                </div>
                <div class="cc-params-card">
                    <div class="cc-card__head"><i class="fas fa-sliders-h"></i> Parámetros del sorteo</div>
                    <div class="cc-params" id="ccParams"></div>
                </div>
            </section>

            <!-- 02 · BAJO REVISIÓN -->
            <section class="cc-section">
                <div class="cc-sec-head">
                    <span class="cc-sec-num">02</span>
                    <h3>Bajo Revisión</h3>
                    <span id="ccReviewCount" class="cc-chip-count">0</span>
                </div>
                <div id="ccReviewList" class="cc-review-list">
                    <div class="cc-empty cc-empty-card">Cargando…</div>
                </div>
            </section>

            <!-- 03 · ALERTAS · IP COMPARTIDA -->
            <section class="cc-section">
                <div class="cc-sec-head">
                    <span class="cc-sec-num">03</span>
                    <h3>Alertas · IP Compartida</h3>
                    <button type="button" id="ccEyeBtn" class="cc-eye" title="Censurar IPs para capturas" onclick="ccToggleIpMask()"><i class="fas fa-eye"></i></button>
                </div>
                <div id="ccDupIps" class="cc-ip-grid">
                    <div class="cc-empty">Sin coincidencias sospechosas.</div>
                </div>
            </section>

            <!-- 04 · CURIOSIDADES + BÚSQUEDA -->
            <section class="cc-section">
                <div class="cc-sec-head">
                    <span class="cc-sec-num">04</span>
                    <h3>Más detalles</h3>
                </div>
                <div class="cc-cards-row">
                    <div class="cc-card">
                        <div class="cc-card__head"><img src="/assets/img/moneda.webp" alt="Bananos"> Ganadores de los bananos</div>
                        <div id="ccEbWinners" class="cc-mini-list"><div class="cc-empty">Sin recompensas todavía.</div></div>
                    </div>
                    <div class="cc-card">
                        <div class="cc-card__head"><i class="fas fa-seedling" style="color:#22c55e;"></i> Primera vez en un sorteo <span id="ccNewcomersCount" class="cc-chip-count cc-card__count">0</span></div>
                        <div id="ccNewcomers" class="cc-mini-list"><div class="cc-empty">Sin novatos aún.</div></div>
                    </div>
                    <div class="cc-card">
                        <div class="cc-card__head"><i class="fas fa-user-shield"></i> Búsqueda global &amp; Lista Blanca</div>
                        <div class="cc-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="ccUserSearch" placeholder="Buscar usuario (nick, Epic, teléfono…)" autocomplete="off">
                        </div>
                        <div id="ccUserResults" class="cc-userresults"><div class="cc-empty">Escribe para buscar y autorizar.</div></div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <!-- ===================== BARRA LATERAL: PARTICIPANTES ===================== -->
    <aside class="cc-aside">
        <div class="cc-aside__head">
            <i class="fas fa-users"></i> Participantes
            <span id="ccWtCountWrap" class="cc-badge cc-badge--wt" style="margin-left:auto;display:none;" title="Inscritos con Ticket de Bienvenida">🎟️ <span id="ccWtCount">0</span></span>
            <span id="ccPartCount" class="cc-chip-count">0</span>
        </div>
        <div class="cc-aside__search">
            <div class="cc-search" style="margin-bottom:0;">
                <i class="fas fa-search"></i>
                <input type="text" id="ccPartSearch" placeholder="Buscar nick, Epic o teléfono…" autocomplete="off">
            </div>
        </div>
        <div id="ccPartList" class="cc-aside__list"><div class="cc-empty">Cargando…</div></div>
    </aside>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="ban_dialog.js?v=<?php echo filemtime(__DIR__ . '/ban_dialog.js'); ?>"></script>

<script>
const API_URL = 'api_sorteos.php';
const CC_AVATAR_BASE = '../../assets/avatars/';
const CC_WT_BADGE = '<span class="cc-badge cc-badge--wt" title="Entró con su Ticket de Bienvenida (uso único, ya consumido)">🎟️ Ticket de Bienvenida</span>';

// Convierte fecha SQL (UTC) a texto local dd/mm/aaaa hh:mm para la sala.
function utcSqlToLocalDisplay(sqlStr) {
    if (!sqlStr) return '—';
    const d = new Date(String(sqlStr).replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return sqlStr;
    const p = (n) => String(n).padStart(2, '0');
    return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}
let ccState = { id: null, timer: null, cdTimer: null, series: [], maxPoints: 60, partTimer: null, lastQ: '', userQ: '', ipMasked: false, lastParticipants: [], lastDup: [], startMs: 0, endMs: 0, status: '' };

function ccEsc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
function ccAvatar(file) { return CC_AVATAR_BASE + (file || 'default.png'); }
function ccLog() { try { console.log.apply(console, ['%c[SalaDeMando]', 'color:#22c55e;font-weight:bold;'].concat([].slice.call(arguments))); } catch (e) {} }
function ccErr() { try { console.error.apply(console, ['%c[SalaDeMando]', 'color:#ef4444;font-weight:bold;'].concat([].slice.call(arguments))); } catch (e) {} }

// Censura de IP (gadget de privacidad para capturas públicas).
function ccMaskIp(ip) {
    if (!ip) return 's/ip';
    if (!ccState.ipMasked) return ip;
    if (ip.indexOf(':') !== -1) return '****:****:****';
    return '***.***.***.***';
}

// Copia un número de teléfono al portapapeles (para buscarlo en WhatsApp).
function ccCopyPhone(phone) {
    const num = String(phone || '').trim();
    if (!num) { Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: 'Sin número registrado', showConfirmButton: false, timer: 1800 }); return; }
    const done = () => ccToast('Número copiado: ' + num);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(num).then(done).catch(() => { window.prompt('Copia el número:', num); });
    } else { window.prompt('Copia el número:', num); }
}

function openCommandCenter(id) {
    ccState.id = id;
    ccState.series = [];
    ccState.lastParticipants = [];
    ccState.lastDup = [];
    ['ccLive', 'ccUnique', 'ccParticipants', 'ccWhitelist'].forEach(k => $('#' + k).text('0'));
    $('#ccTitle').text('Cargando…');
    $('#ccStatus').text('—').attr('class', 'cc-status');
    $('#ccParams').html('');
    $('#ccChartHint').text('esperando datos…');
    $('#ccReviewList').html('<div class="cc-empty cc-empty-card">Cargando…</div>');
    $('#ccReviewCount').text('0');
    $('#ccPartList').html('<div class="cc-empty">Cargando…</div>');
    $('#ccDupIps').html('<div class="cc-empty">Sin coincidencias sospechosas.</div>');
    $('#ccUserResults').html('<div class="cc-empty">Escribe para buscar y autorizar.</div>');
    $('#ccPartSearch').val('');
    $('#ccUserSearch').val('');
    $('#ccCountdown').text('--:--');
    $('#ccCdLabel').text('Tiempo');
    $('#ccCdSub').text('—');

    $('#cmdCenter').removeClass('hidden');
    const main = document.querySelector('#cmdCenter .cc-main');
    if (main) main.scrollTop = 0;
    ccLog('Sala de Mando abierta para sorteo #' + id + '. Cargando datos…');

    ccLoadOverview();
    ccPoll();
    ccLoadParticipants('');
    if (ccState.timer) clearInterval(ccState.timer);
    ccState.timer = setInterval(() => { ccPoll(); ccLoadOverview(); ccLoadParticipants($('#ccPartSearch').val().trim()); }, 8000);
    if (ccState.cdTimer) clearInterval(ccState.cdTimer);
    ccState.cdTimer = setInterval(ccUpdateCountdown, 1000);
    window.addEventListener('resize', ccDrawChart);
}

function closeCommandCenter() {
    if (ccState.timer) { clearInterval(ccState.timer); ccState.timer = null; }
    if (ccState.cdTimer) { clearInterval(ccState.cdTimer); ccState.cdTimer = null; }
    ccState.id = null;
    window.removeEventListener('resize', ccDrawChart);
    window.location.href = 'gestionar_sorteos.php';
}

// ---- Contador regresivo ----
function ccSqlToMs(sqlStr) {
    if (!sqlStr) return 0;
    const d = new Date(String(sqlStr).replace(' ', 'T') + 'Z');
    return isNaN(d.getTime()) ? 0 : d.getTime();
}
function ccUpdateCountdown() {
    const st = String(ccState.status || '').toLowerCase();
    let target = 0, label = 'Tiempo', sub = '';
    if (st === 'scheduled') { target = ccState.startMs; label = 'Inicia en'; sub = 'Apertura de inscripción'; }
    else if (st === 'open') { target = ccState.endMs; label = 'Cierra en'; sub = 'Fin de inscripción'; }
    else { $('#ccCdLabel').text('Estado'); $('#ccCountdown').text((ccState.status || '—').toUpperCase()); $('#ccCdSub').text('Sorteo en curso / finalizado'); return; }
    if (!target) { $('#ccCdLabel').text(label); $('#ccCountdown').text('--:--'); $('#ccCdSub').text(sub); return; }
    let diff = Math.floor((target - Date.now()) / 1000);
    $('#ccCdLabel').text(label);
    if (diff <= 0) { $('#ccCountdown').text('00:00'); $('#ccCdSub').text(st === 'scheduled' ? 'Iniciando…' : 'Cerrando…'); return; }
    const h = Math.floor(diff / 3600); diff -= h * 3600;
    const m = Math.floor(diff / 60); const s = diff - m * 60;
    const p = (n) => String(n).padStart(2, '0');
    $('#ccCountdown').text((h > 0 ? p(h) + ':' : '') + p(m) + ':' + p(s));
    $('#ccCdSub').text(sub);
}

function ccPoll() {
    const id = ccState.id;
    if (!id) return;
    $.getJSON(`${API_URL}?action=live_metrics&id=${id}`).done(res => {
        if (ccState.id !== id) return;
        if (!res || res.status !== 'success') { ccErr('live_metrics respondió sin éxito:', res); return; }
        $('#ccLive').text(res.live);
        $('#ccUnique').text(res.unique);
        $('#ccParticipants').text(res.participants);
        ccState.series.push({ t: Date.now(), v: res.live });
        if (ccState.series.length > ccState.maxPoints) ccState.series.shift();
        $('#ccChartHint').text(ccState.series.length < 2 ? 'esperando datos…' : `${ccState.series.length} muestras · cada 8s`);
        ccDrawChart();
    }).fail((xhr) => ccErr('Fallo al pedir live_metrics (HTTP ' + (xhr && xhr.status) + ').', xhr && xhr.responseText));
}

function ccLoadOverview() {
    const id = ccState.id;
    if (!id) return;
    $.getJSON(`${API_URL}?action=cc_overview&id=${id}`).done(res => {
        if (ccState.id !== id) return;
        if (!res || res.status !== 'success') { ccErr('cc_overview respondió sin éxito:', res); return; }
        const d = res.raffle || {};
        $('#ccTitle').text(d.title || ('Sorteo #' + d.raffle_id));
        const status = String(d.status || '').toLowerCase();
        $('#ccStatus').text((d.status || '-').toUpperCase()).attr('class', 'cc-status cc-status--' + status);
        $('#ccWhitelist').text((res.metrics && res.metrics.whitelist) || 0);

        // Estado para el contador regresivo + bloqueo de acciones tras T_freeze.
        ccState.raffleStatus = status;
        ccState.status = status;
        ccState.startMs = ccSqlToMs(d.start_time);
        ccState.endMs = ccSqlToMs(d.end_time);
        ccUpdateCountdown();

        $('#ccBtnForce').toggleClass('hidden', !(status === 'scheduled' || status === 'open'));
        const eb = (d.requirements && d.requirements.early_bird) || {};
        $('#ccBtnEbWindow').toggleClass('hidden', !(eb && eb.mode === 'time' && status === 'open'));

        ccRenderParams(d);
        ccRenderEbWinners(res.early_bird_winners || []);
        ccRenderNewcomers(res.newcomers || []);
        ccState.lastDup = res.dup_ips || [];
        ccRenderDupIps(ccState.lastDup);
        ccLog('Overview cargado para sorteo #' + id + ' (estado: ' + (d.status || '-') + ').');
    }).fail((xhr) => ccErr('Fallo al pedir cc_overview (HTTP ' + (xhr && xhr.status) + ').', xhr && xhr.responseText));
}

function ccRenderParams(d) {
    const req = d.requirements || {};
    const eleg = [];
    if (Number(req.verified_only) === 1) eleg.push('Verificados');
    if (Number(req.min_orders_count) > 0) eleg.push(`≥${req.min_orders_count} compras${req.min_orders_date ? ' desde ' + req.min_orders_date : ''}`);
    if (Number(req.min_spent_total) > 0) eleg.push(`≥ S/${req.min_spent_total} gastado`);
    const af = req.anti_fraud || {};
    const eb = req.early_bird || {};
    const ebTxt = eb.mode === 'slots' ? `Cupos: pozo ${eb.pool} / ${eb.winners_limit} ganadores`
        : eb.mode === 'time' ? `Tiempo: pozo ${eb.pool} / ${eb.window_seconds}s`
        : eb.mode === 'tiered' ? 'Escalonado manual'
        : 'No';
    // Sin "Premio" ni "Quemados" (basura visual): solo lo accionable.
    const items = [
        ['Ganadores', d.winners_count ?? '-'],
        ['Modo', (d.execution_mode === 'fast_batch') ? 'Flash ⚡' : 'Standard'],
        ['Costo entrada', (Number(d.entry_cost) > 0) ? (d.entry_cost + ' monedas') : 'Gratis'],
        ['Aforo', d.max_participants > 0 ? d.max_participants : 'Ilimitado'],
        ['Inicio (local)', utcSqlToLocalDisplay(d.start_time)],
        ['Cierre (local)', utcSqlToLocalDisplay(d.end_time)],
        ['Early-bird', ebTxt],
        ['Anti-fraude', (Number(af.ip_limit_enabled) === 1) ? `IP máx ${af.ip_limit ?? 1}` : 'Off'],
        ['Elegibilidad', eleg.length ? eleg.join(' · ') : 'Abierta a todos']
    ];
    $('#ccParams').html(items.map(([k, v]) =>
        `<div class="cc-param"><span class="cc-param__k">${ccEsc(k)}</span><span class="cc-param__v">${ccEsc(v)}</span></div>`
    ).join(''));
}

function ccRenderEbWinners(list) {
    if (!list.length) { $('#ccEbWinners').html('<div class="cc-empty">Sin recompensas todavía.</div>'); return; }
    $('#ccEbWinners').html(list.map(w => {
        const paid = w.status === 'paid' ? '' : ' <span class="cc-badge cc-badge--alert">pend.</span>';
        return `<div class="cc-mini-row">
            <img src="${ccEsc(ccAvatar(w.avatar))}">
            <span class="cc-mini-name">#${w.position} ${ccEsc(w.name)}${paid}</span>
            <span class="cc-mini-meta cc-mini-meta--coins" style="color:#f59e0b;"><img src="/assets/img/moneda.webp" alt="Bananos">+${Number(w.amount).toLocaleString()}</span>
        </div>`;
    }).join(''));
}

function ccRenderNewcomers(list) {
    $('#ccNewcomersCount').text(list.length);
    if (!list.length) { $('#ccNewcomers').html('<div class="cc-empty">Sin novatos aún.</div>'); return; }
    $('#ccNewcomers').html(list.map(n =>
        `<div class="cc-mini-row">
            <img src="${ccEsc(ccAvatar(n.avatar))}">
            <span class="cc-mini-name">${ccEsc(n.name)}</span>
            <span class="cc-mini-meta">#${n.ticket}</span>
        </div>`
    ).join(''));
}

// Paleta elegante para agrupar visualmente coincidencias de IP.
const CC_IP_PALETTE = [
    { c: '#ef4444', bg: 'rgba(239,68,68,.08)' },
    { c: '#6366f1', bg: 'rgba(99,102,241,.08)' },
    { c: '#0ea5e9', bg: 'rgba(14,165,233,.08)' },
    { c: '#f59e0b', bg: 'rgba(245,158,11,.08)' },
    { c: '#a855f7', bg: 'rgba(168,85,247,.08)' },
    { c: '#14b8a6', bg: 'rgba(20,184,166,.08)' }
];

function ccToggleIpMask() {
    ccState.ipMasked = !ccState.ipMasked;
    const btn = document.getElementById('ccEyeBtn');
    if (btn) {
        btn.classList.toggle('is-masked', ccState.ipMasked);
        btn.innerHTML = ccState.ipMasked ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
        btn.title = ccState.ipMasked ? 'IPs censuradas (clic para revelar)' : 'Censurar IPs para capturas';
    }
    ccRenderDupIps(ccState.lastDup);
    ccRenderParticipants(ccState.lastParticipants);
}

function ccToggleIpCard(idx) {
    const el = document.getElementById('ccIpCard' + idx);
    if (el) el.classList.toggle('is-open');
}

function ccRenderDupIps(list) {
    if (!list || !list.length) { $('#ccDupIps').html('<div class="cc-empty">Sin coincidencias sospechosas.</div>'); return; }
    $('#ccDupIps').html(list.map((g, idx) => {
        const pal = CC_IP_PALETTE[idx % CC_IP_PALETTE.length];
        const names = (g.members || []).map(m => ccEsc(m.name)).join(', ');
        const rows = (g.members || []).map(m => {
            const waCell = m.phone
                ? `<span class="cc-ip-wa" title="Copiar número" onclick="event.stopPropagation(); ccCopyPhone('${ccEsc(m.phone).replace(/'/g, "\\'")}')"><i class="fab fa-whatsapp"></i> ${ccEsc(m.phone)}</span>`
                : '<span style="opacity:.5;">—</span>';
            return `<tr>
                <td><span class="cc-ip-uname"><img src="${ccEsc(ccAvatar(m.avatar))}"> ${ccEsc(m.name)}${m.is_verified ? ' <span class="cc-badge cc-badge--verified">V</span>' : ''}</span></td>
                <td>#${m.ticket}</td>
                <td>${waCell}</td>
            </tr>`;
        }).join('');
        return `<div class="cc-ip-card" id="ccIpCard${idx}" style="--gc:${pal.c}; --gbg:${pal.bg};">
            <div class="cc-ip-card__top" onclick="ccToggleIpCard(${idx})">
                <span class="cc-ip-dot"></span>
                <div class="cc-ip-meta">
                    <div class="cc-ip-addr">${ccEsc(ccMaskIp(g.ip))}</div>
                    <div class="cc-ip-sub">${names}</div>
                </div>
                <span class="cc-ip-badge">${g.count} cuentas</span>
                <i class="fas fa-chevron-down cc-ip-chevron"></i>
            </div>
            <div class="cc-ip-detail">
                <table class="cc-ip-table">
                    <thead><tr><th>Usuario</th><th>Ticket</th><th>WhatsApp</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </div>`;
    }).join(''));
}

function ccForceStart() {
    const id = ccState.id; if (!id) return;
    Swal.fire({
        title: '¿Forzar inicio?',
        html: 'Se cerrará la <strong>sala de espera ahora</strong> (end_time = ahora).<br>El sorteo seguirá su <strong>curso natural</strong>: el motor espera el bloque de la BSC y respeta la elegibilidad.',
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, adelantar reloj',
        cancelButtonText: 'Cancelar', confirmButtonColor: '#f59e0b'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'force_start', id: id }, res => {
            if (res && res.status === 'success') { Swal.fire('Listo', res.message || 'Inicio forzado.', 'success'); ccLoadOverview(); }
            else { Swal.fire('Error', (res && res.message) || 'No se pudo forzar el inicio.', 'error'); }
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo forzar el inicio.', 'error'));
    });
}

function ccStartEbWindow() {
    const id = ccState.id; if (!id) return;
    Swal.fire({
        title: '¿Iniciar ventana Early-bird?',
        html: 'El cronómetro del pozo por tiempo (Modo B) empezará a correr <strong>desde este instante</strong>.<br>Anúncialo a tu comunidad ahora para que el minuto sea exacto.',
        icon: 'question', showCancelButton: true, confirmButtonText: 'Sí, iniciar ahora',
        cancelButtonText: 'Cancelar', confirmButtonColor: '#22c55e'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'start_eb_window', id: id }, res => {
            if (res && res.status === 'success') { Swal.fire('¡En marcha!', res.message || 'Ventana iniciada.', 'success'); ccLoadOverview(); }
            else { Swal.fire('Error', (res && res.message) || 'No se pudo iniciar la ventana.', 'error'); }
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo iniciar la ventana.', 'error'));
    });
}

function ccCopyLink() {
    if (ccState.id) copyRaffleLink(ccState.id);
}

// ---- Participantes (aside) + Bajo Revisión (columna principal) ----
function ccLoadParticipants(q) {
    const id = ccState.id;
    if (!id) return;
    $.getJSON(`${API_URL}?action=cc_participants&id=${id}&q=${encodeURIComponent(q || '')}`).done(res => {
        if (ccState.id !== id) return;
        if (!res || res.status !== 'success') { ccErr('cc_participants respondió sin éxito:', res); return; }
        ccState.lastParticipants = res.participants || [];
        ccRenderParticipants(ccState.lastParticipants);
    }).fail((xhr) => ccErr('Fallo al pedir cc_participants (HTTP ' + (xhr && xhr.status) + ').', xhr && xhr.responseText));
}

// Moneda Cara/Sello (mismo estilo que el minijuego Doble o Nada) para la
// pre-elección "gamble" en modo moneda.
function ccCoinFace(isHeads) {
    return `<svg class="cc-coin-face" viewBox="0 0 64 64" width="15" height="15" aria-hidden="true">
        <circle cx="32" cy="32" r="29" fill="${isHeads ? '#fbbf24' : '#cbd5e1'}" stroke="${isHeads ? '#d97706' : '#64748b'}" stroke-width="5"/>
        <text x="32" y="43" text-anchor="middle" font-size="30" font-weight="900" fill="${isHeads ? '#92400e' : '#1e293b'}" font-family="system-ui">${isHeads ? 'C' : 'S'}</text>
    </svg>`;
}

// Icono PRIVADO (solo admin) que representa la pre-elección de premio del
// participante. Reemplaza al de WhatsApp en la lista general.
function ccPreChoiceIcon(p) {
    const pre = String((p && p.pre_chosen_prize) || 'original').toLowerCase();
    if (pre === 'cashback') {
        return `<span class="cc-iconbtn cc-iconbtn--pre cc-pre--cash" title="Pre-elección: Cashback en Bananos"><img class="cc-currency-icon" src="/assets/img/moneda.webp" alt=""></span>`;
    }
    if (pre === 'gamble') {
        const mode = String((p && p.gamble_mode) || '').toLowerCase();
        const side = String((p && p.gamble_coin_choice) || '').toLowerCase();
        if (mode === 'coin' && (side === 'heads' || side === 'tails')) {
            const isHeads = (side === 'heads');
            return `<span class="cc-iconbtn cc-iconbtn--pre cc-pre--coin" title="Pre-elección: Doble o Nada (${isHeads ? 'Cara' : 'Sello'})">${ccCoinFace(isHeads)}</span>`;
        }
        return `<span class="cc-iconbtn cc-iconbtn--pre cc-pre--skull" title="Pre-elección: Doble o Nada"><i class="fas fa-skull"></i></span>`;
    }
    return `<span class="cc-iconbtn cc-iconbtn--pre cc-pre--gift" title="Pre-elección: Premio Original"><i class="fas fa-gift"></i></span>`;
}

function ccRenderParticipants(list) {
    list = list || [];
    $('#ccPartCount').text(list.length);
    const wtCount = list.filter(p => p.used_welcome_ticket).length;
    $('#ccWtCount').text(wtCount);
    $('#ccWtCountWrap').toggle(wtCount > 0);
    // T_freeze: una vez que el sorteo arranca se bloquean Expulsar/Banear.
    const started = !['scheduled', 'open'].includes(String(ccState.raffleStatus || '').toLowerCase());

    // --- Columna principal "Bajo Revisión" (solo under_review) ---
    const review = list.filter(p => (p.review_status || 'active') === 'under_review');
    $('#ccReviewCount').text(review.length);
    if (!review.length) {
        $('#ccReviewList').html('<div class="cc-empty cc-empty-card"><i class="fas fa-check-circle" style="color:#22c55e;"></i> Nadie entró “Bajo Revisión”. Todo limpio.</div>');
    } else {
        $('#ccReviewList').html(review.map(p => {
            const nameEsc = ccEsc(p.name).replace(/'/g, "\\'");
            const phoneEsc = ccEsc(p.phone || '').replace(/'/g, "\\'");
            const badges = [];
            if (p.is_verified) badges.push('<i class="fas fa-circle-check cc-verified-check" title="Verificado"></i>');
            if (p.strikes > 0) badges.push(`<span class="cc-badge cc-badge--alert" title="Expulsiones previas">⚠ ${p.strikes} exp.</span>`);
            if (p.active_ban) badges.push('<span class="cc-badge cc-badge--alert">BANEADO</span>');
            if (p.ip_shared > 1) badges.push(`<span class="cc-badge cc-badge--alert">IP×${p.ip_shared}</span>`);
            if (p.first_time) badges.push('<span class="cc-badge cc-badge--new">NUEVO</span>');
            if (p.used_welcome_ticket) badges.push(CC_WT_BADGE);
            const lockAttr = started ? 'disabled title="Bloqueado: el sorteo ya arrancó"' : '';
            const waBtn = `<button class="cc-iconbtn cc-iconbtn--wa" title="Copiar WhatsApp" onclick="ccCopyPhone('${phoneEsc}')"><i class="fab fa-whatsapp"></i></button>`;
            const approveBtn = !started
                ? `<button class="cc-iconbtn cc-iconbtn--ok" title="Aprobar (sale de alertas, queda para Revisión Diferida)" onclick="ccApprove(${p.user_id}, '${nameEsc}')"><i class="fas fa-user-check"></i></button>`
                : '';
            return `<div class="cc-rev">
                <img src="${ccEsc(ccAvatar(p.avatar))}">
                <div class="cc-rev__main">
                    <div class="cc-rev__name"><span>${ccEsc(p.name)}</span> ${badges.join(' ')}</div>
                    <div class="cc-rev__sub">Ticket #${p.ticket} · ${ccEsc(p.public_code || '')} · ${ccEsc(ccMaskIp(p.ip))}</div>
                </div>
                <div class="cc-rev__acts">
                    ${waBtn}
                    ${approveBtn}
                    <button class="cc-iconbtn cc-iconbtn--expel" title="Expulsar del sorteo" onclick="ccExpel(${p.user_id}, '${nameEsc}')" ${lockAttr}><i class="fas fa-user-slash"></i></button>
                    <button class="cc-iconbtn cc-iconbtn--ban" title="Banear cuenta" onclick="ccBan(${p.user_id}, '${nameEsc}')" ${lockAttr}><i class="fas fa-gavel"></i></button>
                </div>
            </div>`;
        }).join(''));
    }

    // --- Barra lateral: lista completa de participantes ---
    if (!list.length) { $('#ccPartList').html('<div class="cc-empty">Sin participantes que coincidan.</div>'); return; }
    $('#ccPartList').html(list.map(p => {
        const rs = p.review_status || 'active';
        const expelled = (rs === 'expelled');
        const underReview = (rs === 'under_review');
        const nameEsc = ccEsc(p.name).replace(/'/g, "\\'");
        const phoneEsc = ccEsc(p.phone || '').replace(/'/g, "\\'");
        // Sidebar minimalista: nombre completo + iconos ligeros (sin tags que recarguen).
        const nameIcons =
            (p.is_verified ? '<i class="fas fa-circle-check cc-verified-check" title="Verificado"></i>' : '') +
            (underReview ? '<i class="fas fa-user-clock cc-review-ico" title="Entró bajo revisión / aprobación"></i>' : '') +
            (expelled ? '<i class="fas fa-user-slash cc-expelled-ico" title="Expulsado (su ticket quedó como hueco)"></i>' : '') +
            (p.active_ban ? '<span class="cc-badge cc-badge--alert">BANEADO</span>' : '') +
            (p.used_welcome_ticket ? CC_WT_BADGE : '');

        const lockAttr = started ? 'disabled title="Bloqueado: el sorteo ya arrancó"' : '';
        const infoBtn = `<button class="cc-iconbtn cc-iconbtn--info" title="Ver info" onclick="ccShowUserDetail(${p.user_id})"><i class="fas fa-id-badge"></i></button>`;
        let acts;
        if (expelled) {
            const restoreLock = started ? 'disabled title="Bloqueado: el sorteo ya arrancó"' : `title="Reactivar participante (recupera el Ticket #${p.ticket})"`;
            acts = `${infoBtn}
                <button class="cc-iconbtn cc-iconbtn--restore" onclick="ccUnexpel(${p.user_id}, '${nameEsc}', ${p.ticket}, ${p.paid ? 'true' : 'false'})" ${restoreLock}><i class="fas fa-user-check"></i></button>`;
        } else {
            // Lista general: el icono de WhatsApp se reemplaza por el de la
            // pre-elección de premio del usuario (privado, solo admin).
            const preBtn = ccPreChoiceIcon(p);
            acts = `${infoBtn}
                ${preBtn}
                <button class="cc-iconbtn cc-iconbtn--expel" title="Expulsar del sorteo" onclick="ccExpel(${p.user_id}, '${nameEsc}')" ${lockAttr}><i class="fas fa-user-slash"></i></button>
                <button class="cc-iconbtn cc-iconbtn--rename" title="Renombrar" onclick="ccRename(${p.user_id}, '${nameEsc}')"><i class="fas fa-pen"></i></button>`;
        }
        return `<div class="cc-prow${expelled ? ' cc-prow--expelled' : ''}${underReview ? ' cc-prow--review' : ''}">
            <img src="${ccEsc(ccAvatar(p.avatar))}">
            <div class="cc-prow__main">
                <div class="cc-prow__name"><span class="txt">${ccEsc(p.name)}</span> ${nameIcons}</div>
                <div class="cc-prow__sub">Ticket #${p.ticket} · ${ccEsc(ccMaskIp(p.ip))}</div>
            </div>
            <div class="cc-prow__acts">${acts}</div>
        </div>`;
    }).join(''));
}

function ccApprove(uid, name) {
    Swal.fire({
        title: '¿Aprobar participante?',
        html: `Se confirmará a <strong>${ccEsc(name)}</strong> como participante normal de este sorteo (sale de "Bajo Revisión").`,
        icon: 'question', showCancelButton: true, confirmButtonText: 'Sí, aprobar',
        cancelButtonText: 'Cancelar', confirmButtonColor: '#22c55e'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'cc_approve', raffle_id: ccState.id, user_id: uid }, res => {
            if (res && res.status === 'success') { ccToast(res.message || 'Aprobado.'); ccLoadParticipants($('#ccPartSearch').val().trim()); }
            else { Swal.fire('Error', (res && res.message) || 'No se pudo aprobar.', 'error'); }
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo aprobar.', 'error'));
    });
}

function ccExpel(uid, name) {
    Swal.fire({
        title: '¿Expulsar participante?',
        html: `Se marcará a <strong>${ccEsc(name)}</strong> como <strong>hueco</strong> en este sorteo (su ticket queda vacío). Si pagó entrada se le devuelven las monedas y se le suma <strong>1 expulsión</strong> al historial. No banea la cuenta.`,
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, expulsar',
        cancelButtonText: 'Cancelar', confirmButtonColor: '#f59e0b'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'cc_expel', raffle_id: ccState.id, user_id: uid }, res => {
            if (res && res.status === 'success') { ccToast(res.message || 'Expulsado.'); ccLoadParticipants($('#ccPartSearch').val().trim()); ccPoll(); }
            else { Swal.fire('Error', (res && res.message) || 'No se pudo expulsar.', 'error'); }
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo expulsar.', 'error'));
    });
}

function ccUnexpel(uid, name, ticket, paid) {
    Swal.fire({
        title: '¿Reactivar participante?',
        html: `<strong>${ccEsc(name)}</strong> volverá a estar <strong>activo</strong> en este sorteo conservando su mismo <strong>Ticket #${ticket}</strong>.`
            + (paid ? ' Se le <strong>re-cobrará la entrada</strong> que se le devolvió al expulsarlo (debe tener saldo).' : '')
            + ' Se elimina la expulsión de su historial.',
        icon: 'question', showCancelButton: true, confirmButtonText: 'Sí, reactivar',
        cancelButtonText: 'Cancelar', confirmButtonColor: '#22c55e'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'cc_unexpel', raffle_id: ccState.id, user_id: uid }, res => {
            if (res && res.status === 'success') { ccToast(res.message || 'Reactivado.'); ccLoadParticipants($('#ccPartSearch').val().trim()); ccPoll(); }
            else { Swal.fire('Error', (res && res.message) || 'No se pudo reactivar.', 'error'); }
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo reactivar.', 'error'));
    });
}

function ccBan(uid, name) {
    sfBanDialog({
        api: API_URL,
        userId: uid,
        name,
        raffleId: ccState.id,
        onDone: () => ccLoadParticipants($('#ccPartSearch').val().trim())
    });
}

function ccRename(uid, name) {
    Swal.fire({
        title: 'Renombrar usuario',
        html: 'Nuevo <strong>nombre público</strong> (visible en el sorteo).',
        input: 'text', inputValue: name, inputPlaceholder: 'Nuevo nombre…',
        showCancelButton: true, confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar', confirmButtonColor: '#6366f1',
        inputValidator: (v) => (!v || !v.trim()) ? 'Escribe un nombre.' : (v.length > 50 ? 'Máx 50 caracteres.' : undefined)
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'cc_rename', user_id: uid, new_name: r.value.trim() }, res => {
            if (res && res.status === 'success') { ccToast(res.message || 'Renombrado.'); ccLoadParticipants($('#ccPartSearch').val().trim()); }
            else { Swal.fire('Error', (res && res.message) || 'No se pudo renombrar.', 'error'); }
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo renombrar.', 'error'));
    });
}

// ---- Búsqueda global + Lista Blanca ----
function ccUserSearch(q) {
    const id = ccState.id;
    if (!q || !q.trim()) { $('#ccUserResults').html('<div class="cc-empty">Escribe para buscar usuarios y autorizarlos.</div>'); return; }
    $.getJSON(`${API_URL}?action=cc_user_search&raffle_id=${id}&q=${encodeURIComponent(q.trim())}`).done(res => {
        if (!res || res.status !== 'success') return;
        const list = res.users || [];
        if (!list.length) { $('#ccUserResults').html('<div class="cc-empty">Sin resultados.</div>'); return; }
        $('#ccUserResults').html(list.map(u => {
            const badges = [];
            if (u.is_verified) badges.push('<i class="fas fa-circle-check cc-verified-check" title="Verificado"></i>');
            if (u.whitelisted) badges.push('<span class="cc-badge cc-badge--wl">EN LISTA</span>');
            if (u.is_participant) badges.push('<span class="cc-badge cc-badge--new">INSCRITO</span>');
            if (u.account_status === 'banned') badges.push('<span class="cc-badge cc-badge--banned">BANEADO</span>');
            const wlBtn = u.whitelisted
                ? `<button class="cc-iconbtn cc-iconbtn--ban" title="Quitar de lista blanca" onclick="ccWhitelistRemove(${u.user_id})"><i class="fas fa-user-minus"></i></button>`
                : `<button class="cc-wl-add" onclick="ccWhitelistAdd(${u.user_id}, '${ccEsc(u.name).replace(/'/g, "\\'")}')"><i class="fas fa-shield-alt"></i> Lista Blanca</button>`;
            return `<div class="cc-prow">
                <img src="${ccEsc(ccAvatar(u.avatar))}">
                <div class="cc-prow__main" style="cursor:pointer" onclick="ccShowUserDetail(${u.user_id})">
                    <div class="cc-prow__name"><span class="txt">${ccEsc(u.name)}</span> ${badges.join(' ')}</div>
                    <div class="cc-prow__sub"><i class="fas fa-shopping-bag"></i> ${u.purchases_count} compras · S/${Number(u.purchases_sum).toFixed(2)} ${u.phone ? '· ' + ccEsc(u.phone) : ''}</div>
                </div>
                <div class="cc-prow__acts">
                    <button class="cc-iconbtn cc-iconbtn--info" title="Ver info / IP" onclick="ccShowUserDetail(${u.user_id})"><i class="fas fa-id-badge"></i></button>
                    ${wlBtn}
                </div>
            </div>`;
        }).join(''));
    });
}

function ccWhitelistAdd(uid, name) {
    Swal.fire({
        title: 'Añadir a Lista Blanca',
        html: `<strong>${ccEsc(name)}</strong> podrá entrar a <strong>este sorteo</strong> saltándose TODAS las reglas (IP, huella, compras, verificado).`,
        icon: 'question', input: 'text', inputplaceholder: 'Motivo (ej. compró por chat)',
        showCancelButton: true, confirmButtonText: 'Sí, autorizar',
        cancelButtonText: 'Cancelar', confirmButtonColor: '#0ea5e9'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'cc_whitelist_add', raffle_id: ccState.id, user_id: uid, reason: r.value || '' }, res => {
            if (res && res.status === 'success') { ccToast(res.message || 'Autorizado.'); ccUserSearch($('#ccUserSearch').val()); ccLoadOverview(); }
            else { Swal.fire('Error', (res && res.message) || 'No se pudo autorizar.', 'error'); }
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo autorizar.', 'error'));
    });
}

function ccWhitelistRemove(uid) {
    Swal.fire({
        title: '¿Quitar de la lista blanca?',
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, quitar',
        cancelButtonText: 'Cancelar', confirmButtonColor: '#ef4444'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'cc_whitelist_remove', raffle_id: ccState.id, user_id: uid }, res => {
            if (res && res.status === 'success') { ccToast(res.message || 'Quitado.'); ccUserSearch($('#ccUserSearch').val()); ccLoadOverview(); }
            else { Swal.fire('Error', (res && res.message) || 'No se pudo quitar.', 'error'); }
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo quitar.', 'error'));
    });
}

function ccShowUserDetail(uid) {
    $.getJSON(`${API_URL}?action=cc_user_detail&user_id=${uid}&raffle_id=${ccState.id}`).done(res => {
        if (!res || res.status !== 'success') { Swal.fire('Error', (res && res.message) || 'No se pudo cargar el usuario.', 'error'); return; }
        const u = res.user || {};
        const p = res.purchases || {};
        const ips = (res.ips || []);
        const shared = (res.shared_accounts || []);
        const recent = (p.recent || []).map(o =>
            `<tr><td style="padding:2px 6px;">${ccEsc(utcSqlToLocalDisplay(o.date))}</td><td style="padding:2px 6px;text-align:right;">S/${Number(o.total).toFixed(2)}</td><td style="padding:2px 6px;">${ccEsc(o.estado)}</td></tr>`
        ).join('') || '<tr><td colspan="3" style="padding:4px 6px;opacity:.6;">Sin pedidos.</td></tr>';
        const sharedHtml = shared.length
            ? `<div style="margin-top:8px;color:#ef4444;font-weight:700;"><i class="fas fa-triangle-exclamation"></i> Comparte IP con ${shared.length} cuenta(s):</div>
               <div style="font-size:.8rem;">${shared.map(s => `${ccEsc(s.name)} <span style="opacity:.6;font-family:monospace;">(${ccEsc(s.ip)})</span>`).join(', ')}</div>`
            : `<div style="margin-top:8px;color:#22c55e;"><i class="fas fa-check"></i> Sin coincidencias de IP con otras cuentas en este sorteo.</div>`;
        const wlBtn = res.whitelisted
            ? `<button class="cc-iconbtn cc-iconbtn--ban" style="width:auto;padding:0 12px;gap:6px;" onclick="Swal.close(); ccWhitelistRemove(${uid});"><i class="fas fa-user-minus"></i> Quitar de lista blanca</button>`
            : `<button class="cc-wl-add" onclick="Swal.close(); ccWhitelistAdd(${uid}, '${ccEsc(u.name).replace(/'/g, "\\'")}');"><i class="fas fa-shield-alt"></i> Añadir a Lista Blanca</button>`;
        const wt = res.welcome_ticket || {};
        let wtLine;
        if (wt.used_here) {
            wtLine = `<span style="color:#d97706;font-weight:700;">🎟️ Entró a ESTE sorteo con su Ticket de Bienvenida</span> (${Number(wt.value).toLocaleString()} / ${Number(wt.threshold).toLocaleString()} paVos)`;
        } else if (wt.used) {
            wtLine = `Consumido en el sorteo #${wt.used_in_raffle}${wt.used_at ? ' · ' + ccEsc(utcSqlToLocalDisplay(wt.used_at)) : ''}`;
        } else if (wt.available) {
            wtLine = `<span style="color:#22c55e;">Disponible</span> (${Number(wt.value).toLocaleString()} / ${Number(wt.threshold).toLocaleString()} paVos)`;
        } else if (wt.enabled === false) {
            wtLine = 'Sistema desactivado';
        } else {
            wtLine = `No califica (${Number(wt.value || 0).toLocaleString()} / ${Number(wt.threshold || 0).toLocaleString()} paVos)`;
        }
        const html = `<div style="text-align:left;font-size:.85rem;">
            <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px;">
                <img src="${ccEsc(ccAvatar(u.avatar))}" style="height:48px;width:48px;border-radius:50%;object-fit:cover;">
                <div>
                    <div style="font-weight:800;font-size:1rem;">${ccEsc(u.name)} ${u.is_verified ? '<span class="cc-badge cc-badge--verified">VERIF</span>' : ''} ${u.account_status === 'banned' ? '<span class="cc-badge cc-badge--banned">BANEADO</span>' : ''} ${wt.used_here ? CC_WT_BADGE : ''}</div>
                    <div style="opacity:.7;">Epic: ${ccEsc(u.game_username || '-')} · Código: ${ccEsc(u.public_code || '-')}</div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 14px;">
                <div><b>Teléfono:</b> ${ccEsc(u.phone || '-')}</div>
                <div><b>Monedas:</b> ${Number(u.coins).toLocaleString()}</div>
                <div><b>Compras:</b> ${p.count} (S/${Number(p.sum).toFixed(2)})</div>
                <div><b>Última:</b> ${ccEsc(p.last ? utcSqlToLocalDisplay(p.last) : '—')}</div>
                <div style="grid-column:1/3;"><b>Registrado:</b> ${ccEsc(u.created_at ? utcSqlToLocalDisplay(u.created_at) : '—')}</div>
                <div style="grid-column:1/3;"><b>Ticket de Bienvenida:</b> ${wtLine}</div>
            </div>
            <div style="margin-top:8px;"><b>IPs en este sorteo:</b> ${ips.length ? ips.map(i => `<span style="font-family:monospace;">${ccEsc(i)}</span>`).join(', ') : '—'}</div>
            ${sharedHtml}
            <div style="margin-top:10px;font-weight:700;">Pedidos recientes</div>
            <table style="width:100%;border-collapse:collapse;font-size:.78rem;">${recent}</table>
            <div style="margin-top:12px;text-align:center;">${wlBtn}</div>
        </div>`;
        Swal.fire({ title: '', html: html, width: 560, showConfirmButton: true, confirmButtonText: 'Cerrar' });
    }).fail(() => Swal.fire('Error', 'No se pudo cargar el usuario.', 'error'));
}

function ccToast(msg) {
    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: msg, showConfirmButton: false, timer: 2000, timerProgressBar: true });
}

// ============================================================
// REVISIÓN DIFERIDA (Punto 5): compras manuales por chat
// ============================================================

function ccDrawChart() {
  try {
    const canvas = document.getElementById('ccChart');
    if (!canvas) { ccErr('ccDrawChart: no se encontró el canvas #ccChart.'); return; }
    const ctx = canvas.getContext('2d');
    const cssW = canvas.clientWidth || 480;
    const cssH = 220;
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.round(cssW * dpr);
    canvas.height = Math.round(cssH * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);

    const gridColor = 'rgba(148,163,184,0.22)';
    const axisColor = getComputedStyle(document.getElementById('cmdCenter')).getPropertyValue('--ld-live').trim() || '#22c55e';
    const textColor = 'rgba(120,130,145,0.9)';
    const padL = 30, padR = 10, padT = 12, padB = 18;
    const plotW = cssW - padL - padR;
    const plotH = cssH - padT - padB;

    const data = ccState.series;
    const vals = data.map(p => p.v);
    const maxV = Math.max(4, ...vals);
    const niceMax = Math.ceil(maxV / 4) * 4;

    // Grid + etiquetas Y
    ctx.font = '10px Inter, system-ui, sans-serif';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = textColor;
    const lines = 4;
    for (let i = 0; i <= lines; i++) {
        const y = padT + (plotH * i / lines);
        const val = Math.round(niceMax * (1 - i / lines));
        ctx.strokeStyle = gridColor;
        ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(padL, y + 0.5); ctx.lineTo(cssW - padR, y + 0.5); ctx.stroke();
        ctx.textAlign = 'right';
        ctx.fillText(String(val), padL - 6, y);
    }

    if (data.length < 2) {
        ctx.textAlign = 'center';
        ctx.fillStyle = textColor;
        ctx.fillText('Recolectando muestras…', padL + plotW / 2, padT + plotH / 2);
        return;
    }

    const xAt = i => padL + (plotW * i / (data.length - 1));
    const yAt = v => padT + plotH * (1 - (v / niceMax));

    // Área bajo la curva
    const grad = ctx.createLinearGradient(0, padT, 0, padT + plotH);
    grad.addColorStop(0, 'rgba(34,197,94,0.28)');
    grad.addColorStop(1, 'rgba(34,197,94,0.02)');
    ctx.beginPath();
    ctx.moveTo(xAt(0), yAt(data[0].v));
    data.forEach((p, i) => ctx.lineTo(xAt(i), yAt(p.v)));
    ctx.lineTo(xAt(data.length - 1), padT + plotH);
    ctx.lineTo(xAt(0), padT + plotH);
    ctx.closePath();
    ctx.fillStyle = grad;
    ctx.fill();

    // Línea
    ctx.beginPath();
    ctx.moveTo(xAt(0), yAt(data[0].v));
    data.forEach((p, i) => ctx.lineTo(xAt(i), yAt(p.v)));
    ctx.strokeStyle = axisColor;
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.stroke();

    // Punto final
    const lx = xAt(data.length - 1), ly = yAt(data[data.length - 1].v);
    ctx.beginPath();
    ctx.arc(lx, ly, 4, 0, Math.PI * 2);
    ctx.fillStyle = axisColor;
    ctx.fill();
    ctx.beginPath();
    ctx.arc(lx, ly, 7, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(34,197,94,0.35)';
    ctx.lineWidth = 2;
    ctx.stroke();
  } catch (e) {
    ccErr('Error dibujando el gráfico de Rating en vivo:', e);
  }
}


function buildPublicRaffleLink(id) {
    const template = '/sorteos_flash/?raffle_id={id}';
    return `${window.location.origin}${template.replace('{id}', String(id))}`;
}

function copyRaffleLink(id) {
    const url = buildPublicRaffleLink(id);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Enlace copiado', showConfirmButton: false, timer: 1400, timerProgressBar: true });
        }).catch(() => {
            Swal.fire('Error', 'No se pudo copiar el enlace.', 'error');
        });
        return;
    }
    const $temp = $('<input>');
    $('body').append($temp);
    $temp.val(url).select();
    try {
        document.execCommand('copy');
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Enlace copiado', showConfirmButton: false, timer: 1400, timerProgressBar: true });
    } catch (e) {
        Swal.fire('Error', 'No se pudo copiar el enlace.', 'error');
    }
    $temp.remove();
}

// Arranque: abrir la sala del sorteo indicado en la URL (?id=).
$(document).ready(function () {
    var rid = <?php echo (int) $raffleId; ?>;

    // --- Buscadores en vivo (con debounce) ---
    // Sin estos listeners, escribir en las cajas no disparaba ninguna búsqueda:
    // la "Búsqueda global & Lista Blanca" quedaba inerte y el filtro de
    // participantes solo se actualizaba con el sondeo de 8s.
    var ccUserSearchTimer = null;
    $('#ccUserSearch').on('input', function () {
        var q = this.value;
        clearTimeout(ccUserSearchTimer);
        ccUserSearchTimer = setTimeout(function () { ccUserSearch(q); }, 300);
    });
    var ccPartSearchTimer = null;
    $('#ccPartSearch').on('input', function () {
        var q = this.value.trim();
        clearTimeout(ccPartSearchTimer);
        ccPartSearchTimer = setTimeout(function () { ccLoadParticipants(q); }, 300);
    });

    if (rid > 0) {
        openCommandCenter(rid);
    } else {
        lbLoad();
    }
});

// ===================== LOBBY =====================
var LB_ACTIVE = ['scheduled', 'open', 'waiting_for_winner', 'locking', 'animating'];
var lbAll = [];
function lbEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function lbDate(s) { if (!s) return '—'; var d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('es-PE', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
function lbBadge(st) {
    if (st === 'completed') return '<span class="lb-badge lb-badge--done"><i class="fas fa-flag-checkered"></i> Finalizado</span>';
    if (st === 'cancelled') return '<span class="lb-badge lb-badge--cancel"><i class="fas fa-ban"></i> Cancelado</span>';
    if (st === 'scheduled') return '<span class="lb-badge lb-badge--sched"><i class="fas fa-clock"></i> Programado</span>';
    return '<span class="lb-badge lb-badge--live"><span class="cc-pulse"></span> En vivo · ' + lbEsc(st) + '</span>';
}
function lbLoad() {
    $.getJSON(API_URL, { action: 'list' }, function (res) {
        lbAll = (res && res.data) || [];
        var live = lbAll.filter(function (r) { return LB_ACTIVE.indexOf(r.status) !== -1; });
        var fb = document.getElementById('lbFeaturedBody');
        document.getElementById('lbFeaturedHint').textContent = live.length ? (live.length + ' sorteo(s)') : 'ninguno';
        fb.innerHTML = live.length ? live.map(function (r) {
            return '<a class="lb-hero" href="sala_mando.php?id=' + r.raffle_id + '">' +
                '<div class="lb-hero__eyebrow"><span class="ld-pulse"></span> ' + (r.status === 'scheduled' ? 'Programado' : 'En curso') + ' · #' + r.raffle_id + '</div>' +
                '<div class="lb-hero__title">' + lbEsc(r.title) + '</div>' +
                '<div class="lb-hero__meta"><span><i class="fas fa-gift"></i> ' + lbEsc(r.prize_summary || '—') + '</span><span><i class="fas fa-users"></i> ' + (r.participants_count || 0) + ' participantes</span><span class="lb-coin-meta"><img src="/assets/img/moneda.webp" alt="Bananos"> ' + (Number(r.entry_cost) || 0) + ' Bananos</span><span><i class="fas fa-hourglass-half"></i> ' + lbDate(r.start_time) + ' → ' + lbDate(r.end_time) + '</span></div>' +
                '<span class="lb-hero__cta">Abrir Sala de Mando <i class="fas fa-arrow-right"></i></span></a>';
        }).join('') : '<div class="lb-card" style="padding: 1.6rem; text-align: center; color: var(--flash-text-muted);"><i class="fas fa-bolt" style="opacity: .4; font-size: 1.4rem; display: block; margin-bottom: .5rem;"></i>No hay ningún sorteo en curso ni programado.<br><a href="gestionar_sorteos.php" style="color: var(--flash-accent); font-weight: 700;">Crear uno en el gestor</a></div>';
        lbRenderPast();
    }).fail(function () { document.getElementById('lbPastBody').innerHTML = '<div style="padding: 1.5rem; text-align: center; color: #ef4444;">No se pudo cargar la lista de sorteos.</div>'; });
}
function lbRenderPast() {
    var q = (document.getElementById('lbSearch').value || '').trim().toLowerCase();
    var past = lbAll.filter(function (r) { return LB_ACTIVE.indexOf(r.status) === -1; })
        .filter(function (r) { return !q || String(r.raffle_id) === q || String(r.title || '').toLowerCase().indexOf(q) !== -1 || String(r.prize_summary || '').toLowerCase().indexOf(q) !== -1; });
    document.getElementById('lbPastHint').textContent = past.length + ' de los últimos ' + lbAll.length;
    document.getElementById('lbPastBody').innerHTML = past.length ? past.map(function (r) {
        return '<a class="lb-row" href="sala_mando.php?id=' + r.raffle_id + '">' +
            '<span class="lb-row__id">#' + r.raffle_id + '</span>' +
            '<div><div class="lb-row__title">' + lbEsc(r.title) + '</div><div class="lb-row__sub"><i class="fas fa-gift"></i> ' + lbEsc(r.prize_summary || '—') + ' · <i class="fas fa-users"></i> ' + (r.participants_count || 0) + ' · <i class="fas fa-trophy"></i> ' + lbEsc(r.winner_summary || '-') + ' · ' + lbDate(r.end_time) + '</div></div>' +
            '<div style="display: flex; align-items: center; gap: .6rem;">' + lbBadge(r.status) + '<i class="fas fa-chevron-right" style="color: var(--flash-text-muted); font-size: .75rem;"></i></div></a>';
    }).join('') : '<div style="padding: 1.6rem; text-align: center; color: var(--flash-text-muted);">Sin resultados.</div>';
}
$(document).on('input', '#lbSearch', lbRenderPast);
</script>

</body>
</html>

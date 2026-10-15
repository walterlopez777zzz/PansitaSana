<?php
// /wabo/sorteos_flash/estadisticas.php
// Vista histórica y comunitaria de Sorteos Flash.
require_once __DIR__ . '/../core/admin_header.php';
?>

<style>
    @keyframes sfRise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes sfPulse { 0%,100% { opacity: .42; } 50% { opacity: .78; } }
    @keyframes sfBarIn { from { transform: scaleY(0); } to { transform: scaleY(1); } }

    #sfStats {
        --sf-page: var(--primary);
        --sf-surface: #0a0a0a;
        --sf-surface-2: #0f0f10;
        --sf-border: rgba(255,255,255,.09);
        --sf-ink: #f8fafc;
        --sf-muted: #9ca3af;
        --sf-subtle: #6b7280;
        --sf-accent: #22d3ee;
        --sf-accent-soft: rgba(34,211,238,.1);
        --sf-series-1: #3987e5;
        --sf-series-2: #d95926;
        --sf-series-3: #199e70;
        --sf-good: #0ca30c;
        --sf-danger: #d03b3b;
        color: var(--sf-ink);
        max-width: 1560px;
        margin: 0 auto;
    }
    [data-theme="light"] #sfStats {
        --sf-surface: #ffffff;
        --sf-surface-2: #f8fafc;
        --sf-border: rgba(15,23,42,.1);
        --sf-ink: #0b0b0b;
        --sf-muted: #52514e;
        --sf-subtle: #898781;
        --sf-accent: #0891b2;
        --sf-accent-soft: rgba(8,145,178,.08);
        --sf-series-1: #2a78d6;
        --sf-series-2: #eb6834;
        --sf-series-3: #1baf7a;
    }
    [data-theme="glitch"] #sfStats {
        --sf-surface: rgba(13,13,13,.92);
        --sf-surface-2: rgba(26,26,26,.94);
        --sf-border: rgba(255,0,255,.38);
        --sf-ink: #fff;
        --sf-muted: #e5e7eb;
    }

    .sf-topbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 1.25rem; margin-bottom: 1.25rem; animation: sfRise .35s ease-out both; }
    .sf-eyebrow { display: flex; align-items: center; gap: .55rem; color: var(--sf-accent); font-size: .68rem; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; margin-bottom: .35rem; }
    .sf-eyebrow::before { content: ''; width: 1.75rem; height: 1px; background: currentColor; }
    .sf-title { margin: 0; font-size: clamp(1.6rem, 2.8vw, 2.35rem); line-height: 1.08; font-weight: 800; letter-spacing: -.035em; color: var(--sf-ink); }
    .sf-subtitle { color: var(--sf-muted); font-size: .86rem; margin-top: .48rem; max-width: 720px; }
    .sf-actions { display: flex; align-items: center; gap: .55rem; flex-wrap: wrap; justify-content: flex-end; }
    .sf-btn { display: inline-flex; align-items: center; justify-content: center; gap: .48rem; min-height: 2.55rem; padding: .6rem .85rem; border-radius: .7rem; border: 1px solid var(--sf-border); background: var(--sf-surface); color: var(--sf-muted); font-size: .76rem; font-weight: 750; cursor: pointer; transition: border-color .18s, color .18s, transform .18s, background .18s; }
    .sf-btn:hover, .sf-btn:focus-visible { border-color: rgba(34,211,238,.42); color: var(--sf-ink); transform: translateY(-1px); outline: none; }
    .sf-btn--primary { border-color: rgba(34,211,238,.36); background: var(--sf-accent-soft); color: var(--sf-accent); }

    .sf-frame { position: relative; }
    .sf-grid { display: grid; gap: .85rem; }
    .sf-hero-grid { grid-template-columns: minmax(0, 1.55fr) repeat(2, minmax(190px, .72fr)); margin-bottom: .85rem; }
    .sf-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: .85rem; }
    .sf-main-grid { grid-template-columns: minmax(0, 1.62fr) minmax(320px, .78fr); align-items: stretch; margin-bottom: .85rem; }
    .sf-community-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: .85rem; }
    .sf-private-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }

    .sf-card { position: relative; background: var(--sf-surface); border: 1px solid var(--sf-border); border-radius: 1rem; overflow: hidden; animation: sfRise .4s ease-out both; }
    .sf-card--pad { padding: 1.15rem; }
    .sf-card--hero { min-height: 184px; padding: 1.35rem 1.45rem; background: radial-gradient(105% 145% at 100% 0%, rgba(34,211,238,.13), transparent 55%), var(--sf-surface); }
    .sf-card--hero::after { content: ''; position: absolute; width: 240px; height: 240px; border: 1px solid rgba(34,211,238,.15); border-radius: 50%; right: -115px; top: -125px; box-shadow: 0 0 0 24px rgba(34,211,238,.025), 0 0 0 52px rgba(34,211,238,.018); pointer-events: none; }
    .sf-card-label { color: var(--sf-muted); font-size: .68rem; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
    .sf-card-icon { width: 2.3rem; height: 2.3rem; display: inline-flex; align-items: center; justify-content: center; border-radius: .72rem; background: var(--sf-accent-soft); color: var(--sf-accent); flex: none; }
    .sf-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
    .sf-hero-value { font-size: clamp(2.8rem, 5vw, 4.6rem); line-height: .96; letter-spacing: -.06em; font-weight: 800; margin-top: 1.45rem; color: var(--sf-ink); }
    .sf-hero-unit { color: var(--sf-accent); font-size: .78rem; font-weight: 850; letter-spacing: .1em; text-transform: uppercase; margin-left: .35rem; }
    .sf-hero-equivalent { color: var(--sf-muted); font-size: .86rem; margin-top: .6rem; }
    .sf-hero-rate { color: var(--sf-subtle); font-size: .68rem; margin-top: .22rem; }
    .sf-big-value { font-size: 2.25rem; line-height: 1; letter-spacing: -.045em; font-weight: 800; margin-top: 2rem; }
    .sf-big-note { color: var(--sf-muted); font-size: .73rem; margin-top: .55rem; }
    .sf-kpi-value { color: var(--sf-ink); font-size: 1.55rem; line-height: 1; letter-spacing: -.035em; font-weight: 800; margin-top: .8rem; }
    .sf-kpi-note { color: var(--sf-muted); font-size: .69rem; line-height: 1.4; margin-top: .42rem; }
    .sf-delta { display: inline-flex; align-items: center; gap: .28rem; color: var(--sf-muted); background: var(--sf-surface-2); border: 1px solid var(--sf-border); border-radius: 999px; padding: .2rem .45rem; font-size: .62rem; font-weight: 750; }

    .sf-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; padding: 1.05rem 1.15rem .9rem; border-bottom: 1px solid var(--sf-border); }
    .sf-card-title { color: var(--sf-ink); font-size: .93rem; font-weight: 800; letter-spacing: -.01em; }
    .sf-card-desc { color: var(--sf-muted); font-size: .68rem; margin-top: .2rem; }
    .sf-chip { display: inline-flex; align-items: center; gap: .34rem; border: 1px solid var(--sf-border); background: var(--sf-surface-2); color: var(--sf-muted); border-radius: 999px; padding: .3rem .52rem; font-size: .62rem; font-weight: 750; white-space: nowrap; }

    .sf-ranking { padding: .28rem .75rem .7rem; }
    .sf-rank-row { display: grid; grid-template-columns: 2rem minmax(0, 1fr) 5.2rem 7.4rem; align-items: center; gap: .7rem; min-height: 55px; padding: .55rem .35rem; border-bottom: 1px solid var(--sf-border); }
    .sf-rank-row:last-child { border-bottom: 0; }
    .sf-rank-pos { width: 1.7rem; height: 1.7rem; display: grid; place-items: center; border-radius: .55rem; color: var(--sf-muted); font-size: .7rem; font-weight: 850; }
    .sf-rank-row:nth-child(1) .sf-rank-pos { color: #111827; background: #fbbf24; }
    .sf-rank-row:nth-child(2) .sf-rank-pos { color: #111827; background: #cbd5e1; }
    .sf-rank-row:nth-child(3) .sf-rank-pos { color: #fff; background: #b45309; }
    .sf-user { display: flex; align-items: center; gap: .65rem; min-width: 0; }
    .sf-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; background: var(--sf-surface-2); border: 1px solid var(--sf-border); flex: none; }
    .sf-user-copy { min-width: 0; }
    .sf-user-name { color: var(--sf-ink); font-size: .78rem; font-weight: 750; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .sf-user-handle { color: var(--sf-subtle); font-size: .62rem; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .sf-verified { color: var(--sf-accent); font-size: .58rem; margin-left: .22rem; }
    .sf-rank-number { color: var(--sf-ink); text-align: right; font-size: .76rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .sf-rank-number span { display: block; color: var(--sf-subtle); font-size: .57rem; font-weight: 650; text-transform: uppercase; letter-spacing: .05em; margin-top: .12rem; }

    .sf-side-stack { display: grid; grid-template-rows: 1fr 1fr; gap: .85rem; }
    .sf-lucky { min-height: 0; padding: 1.1rem 1.2rem; background: radial-gradient(100% 145% at 100% 100%, rgba(217,89,38,.13), transparent 62%), var(--sf-surface); }
    .sf-ticket-wrap { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 1rem; }
    .sf-ticket { font-size: clamp(2.8rem, 5vw, 4.15rem); font-weight: 850; line-height: 1; letter-spacing: -.06em; color: var(--sf-ink); }
    .sf-ticket::first-letter { color: var(--sf-series-2); }
    .sf-ticket-meta { color: var(--sf-muted); font-size: .72rem; line-height: 1.45; text-align: right; max-width: 135px; }
    .sf-ticket-meta strong { color: var(--sf-ink); }
    .sf-record { padding: 1.1rem 1.2rem; }
    .sf-record-name { color: var(--sf-ink); font-size: 1.05rem; line-height: 1.25; font-weight: 800; margin-top: 1.1rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sf-record-value { color: var(--sf-accent); font-size: 1.8rem; font-weight: 850; letter-spacing: -.04em; margin-top: .2rem; }
    .sf-record-value span { color: var(--sf-muted); font-size: .68rem; font-weight: 650; letter-spacing: 0; }

    .sf-decision-card { grid-column: span 2; }
    .sf-decisions { padding: 1.15rem; }
    .sf-decision-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: .65rem; margin-bottom: 1rem; }
    .sf-decision-stat { display: flex; align-items: center; gap: .6rem; min-width: 0; }
    .sf-dot { width: .64rem; height: .64rem; border-radius: .2rem; flex: none; }
    .sf-decision-name { color: var(--sf-muted); font-size: .65rem; }
    .sf-decision-pct { color: var(--sf-ink); font-size: 1.02rem; font-weight: 850; line-height: 1.1; }
    .sf-decision-pct small { color: var(--sf-subtle); font-size: .58rem; font-weight: 650; margin-left: .16rem; }
    .sf-stackbar { display: flex; align-items: stretch; height: 16px; gap: 2px; border-radius: 999px; overflow: visible; background: var(--sf-surface-2); }
    .sf-segment { position: relative; height: 100%; min-width: 0; border: 0; padding: 0; cursor: help; transition: filter .18s, transform .18s; }
    .sf-segment:first-child { border-radius: 999px 4px 4px 999px; }
    .sf-segment:last-child { border-radius: 4px 999px 999px 4px; }
    .sf-segment:hover, .sf-segment:focus-visible { filter: brightness(1.16); transform: scaleY(1.24); outline: none; z-index: 2; }
    .sf-segment::after, .sf-month-bar::after { content: attr(data-tip); position: absolute; left: 50%; bottom: calc(100% + 9px); transform: translateX(-50%) translateY(3px); background: #111827; color: #fff; border: 1px solid rgba(255,255,255,.12); border-radius: .45rem; padding: .36rem .5rem; font-size: .62rem; font-weight: 700; white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity .15s, transform .15s; box-shadow: 0 8px 22px rgba(0,0,0,.25); }
    .sf-segment:hover::after, .sf-segment:focus-visible::after, .sf-month-bar:hover::after, .sf-month-bar:focus-visible::after { opacity: 1; transform: translateX(-50%) translateY(0); }
    .sf-table-toggle { margin-top: .85rem; color: var(--sf-muted); font-size: .65rem; }
    .sf-table-toggle summary { cursor: pointer; width: fit-content; }
    .sf-a11y-table { width: 100%; border-collapse: collapse; margin-top: .55rem; font-size: .68rem; }
    .sf-a11y-table th, .sf-a11y-table td { padding: .42rem .35rem; border-top: 1px solid var(--sf-border); text-align: left; color: var(--sf-muted); }
    .sf-a11y-table td { color: var(--sf-ink); font-variant-numeric: tabular-nums; }

    .sf-gamble { padding: 1.15rem; display: grid; grid-template-columns: auto 1fr; align-items: center; gap: 1.1rem; }
    .sf-ring { --p: 0; position: relative; width: 96px; aspect-ratio: 1; border-radius: 50%; display: grid; place-items: center; background: conic-gradient(var(--sf-good) calc(var(--p) * 1%), var(--sf-surface-2) 0); }
    .sf-ring::before { content: ''; position: absolute; inset: 8px; border-radius: inherit; background: var(--sf-surface); border: 1px solid var(--sf-border); }
    .sf-ring-value { position: relative; color: var(--sf-ink); font-size: 1.35rem; font-weight: 850; letter-spacing: -.04em; }
    .sf-gamble-copy h3 { color: var(--sf-ink); font-size: .9rem; font-weight: 800; }
    .sf-gamble-copy p { color: var(--sf-muted); font-size: .68rem; line-height: 1.45; margin-top: .3rem; }
    .sf-winloss { display: flex; align-items: center; gap: .55rem; margin-top: .72rem; color: var(--sf-muted); font-size: .63rem; }
    .sf-winloss b { color: var(--sf-ink); }

    .sf-month-chart { display: grid; grid-template-columns: repeat(12, minmax(25px, 1fr)); align-items: end; gap: .42rem; height: 178px; padding: 1rem 1.15rem .6rem; border-bottom: 1px solid var(--sf-border); }
    .sf-month-col { height: 100%; display: grid; grid-template-rows: 1fr auto; align-items: end; min-width: 0; }
    .sf-month-mark { height: 100%; display: flex; align-items: flex-end; justify-content: center; padding: 0 .1rem; }
    .sf-month-bar { position: relative; width: min(22px, 72%); min-height: 3px; border: 0; padding: 0; border-radius: 4px 4px 1px 1px; background: var(--sf-series-1); transform-origin: bottom; animation: sfBarIn .55s cubic-bezier(.2,.8,.2,1) both; cursor: help; }
    .sf-month-bar:hover, .sf-month-bar:focus-visible { filter: brightness(1.17); outline: 2px solid var(--sf-surface); outline-offset: 2px; }
    .sf-month-label { color: var(--sf-subtle); font-size: .58rem; text-align: center; text-transform: uppercase; padding-top: .45rem; }

    .sf-mini-card { padding: 1.05rem 1.1rem; }
    .sf-mini-value { color: var(--sf-ink); font-size: 1.65rem; font-weight: 850; letter-spacing: -.04em; margin-top: .8rem; }
    .sf-mini-copy { color: var(--sf-muted); font-size: .67rem; line-height: 1.45; margin-top: .28rem; }
    .sf-progress { height: 5px; border-radius: 999px; background: var(--sf-surface-2); margin-top: .85rem; overflow: hidden; }
    .sf-progress span { display: block; height: 100%; border-radius: inherit; background: var(--sf-series-3); }

    .sf-private { margin-top: 1.35rem; }
    .sf-private-head { display: flex; align-items: center; gap: .55rem; margin-bottom: .65rem; color: var(--sf-muted); font-size: .68rem; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
    .sf-private-head::after { content: ''; height: 1px; flex: 1; background: var(--sf-border); }
    .sf-private .sf-card { background: var(--sf-surface-2); }
    .sf-private .sf-mini-value { font-size: 1.35rem; }

    .sf-foot { display: flex; align-items: center; justify-content: space-between; gap: 1rem; color: var(--sf-subtle); font-size: .62rem; margin-top: 1rem; padding: .2rem .15rem; }
    .sf-brand { display: inline-flex; align-items: center; gap: .45rem; color: var(--sf-muted); font-weight: 750; }
    .sf-brand i { color: #fbbf24; }

    .sf-empty { color: var(--sf-muted); text-align: center; padding: 2.6rem 1rem; font-size: .75rem; }
    .sf-empty i { display: block; font-size: 1.2rem; margin-bottom: .5rem; opacity: .45; }
    .sf-loading { position: absolute; inset: 0; z-index: 8; min-height: 620px; border-radius: 1rem; background: var(--sf-page); display: grid; place-items: center; transition: opacity .25s, visibility .25s; }
    .sf-loading.is-hidden { opacity: 0; visibility: hidden; pointer-events: none; }
    .sf-loading-inner { display: flex; flex-direction: column; align-items: center; gap: .75rem; color: var(--sf-muted); font-size: .75rem; }
    .sf-loader-mark { width: 36px; height: 36px; border-radius: 50%; border: 2px solid var(--sf-border); border-top-color: var(--sf-accent); animation: spin .8s linear infinite; }
    .sf-error { display: none; border: 1px solid rgba(208,59,59,.38); background: rgba(208,59,59,.08); color: #ef4444; border-radius: .8rem; padding: .85rem 1rem; margin-bottom: .85rem; font-size: .76rem; }
    .sf-error.is-visible { display: flex; align-items: center; gap: .55rem; }

    #sfPresentationExit { display: none; }
    body.sf-presentation { background: #06080a !important; }
    body.sf-presentation #sidebar-toggle, body.sf-presentation #sidebar, body.sf-presentation #sidebar-overlay { display: none !important; }
    body.sf-presentation #content-wrapper { margin-left: 0 !important; height: auto; overflow: visible; }
    body.sf-presentation #content-wrapper > main { padding: 22px !important; overflow: visible; }
    body.sf-presentation .sf-normal-only, body.sf-presentation .sf-private { display: none !important; }
    body.sf-presentation #sfStats { --sf-page: #08090a; --sf-surface: #0a0a0a; --sf-surface-2: #0f0f10; --sf-border: rgba(255,255,255,.11); --sf-ink: #f8fafc; --sf-muted: #a3a3a3; --sf-subtle: #737373; --sf-accent: #22d3ee; --sf-accent-soft: rgba(34,211,238,.1); --sf-series-1: #3987e5; --sf-series-2: #d95926; --sf-series-3: #199e70; max-width: 1680px; padding: 22px; border: 1px solid rgba(34,211,238,.25); border-radius: 1.35rem; background: radial-gradient(80% 50% at 50% 0%, rgba(34,211,238,.07), transparent 70%), #08090a; box-shadow: 0 0 0 7px rgba(34,211,238,.025), 0 28px 80px rgba(0,0,0,.42); }
    body.sf-presentation #sfStats .sf-card { border-color: rgba(255,255,255,.13); box-shadow: 0 12px 32px rgba(0,0,0,.16); }
    body.sf-presentation #sfStats .sf-card--hero { border-color: rgba(34,211,238,.38); }
    body.sf-presentation .sf-title { font-size: 2.35rem; }
    body.sf-presentation #sfPresentationExit { position: fixed; right: 14px; top: 14px; z-index: 100; display: inline-flex; opacity: .35; }
    body.sf-presentation #sfPresentationExit:hover { opacity: 1; }

    @media (max-width: 1100px) {
        .sf-hero-grid { grid-template-columns: 1.4fr 1fr; }
        .sf-card--hero { grid-row: span 2; }
        .sf-kpi-grid { grid-template-columns: repeat(2, 1fr); }
        .sf-main-grid { grid-template-columns: 1fr; }
        .sf-side-stack { grid-template-columns: 1fr 1fr; grid-template-rows: none; }
        .sf-private-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 720px) {
        .sf-topbar { align-items: flex-start; flex-direction: column; }
        .sf-actions { justify-content: flex-start; width: 100%; }
        .sf-btn { flex: 1; }
        .sf-hero-grid { grid-template-columns: 1fr 1fr; }
        .sf-card--hero { grid-column: 1 / -1; grid-row: auto; min-height: 170px; }
        .sf-community-grid { grid-template-columns: 1fr; }
        .sf-decision-card { grid-column: auto; }
        .sf-rank-row { grid-template-columns: 1.8rem minmax(0, 1fr) 4.2rem; }
        .sf-rank-prize { display: none; }
        .sf-side-stack { grid-template-columns: 1fr; }
        .sf-month-chart { gap: .2rem; padding-left: .65rem; padding-right: .65rem; }
        .sf-month-label { font-size: .51rem; }
        .sf-foot { align-items: flex-start; flex-direction: column; }
    }
    @media (max-width: 480px) {
        .sf-hero-grid, .sf-kpi-grid, .sf-private-grid { grid-template-columns: 1fr; }
        .sf-hero-value { font-size: 2.8rem; }
        .sf-decision-summary { grid-template-columns: 1fr; }
        .sf-gamble { grid-template-columns: 1fr; }
        .sf-ring { width: 86px; }
    }
    @media (prefers-reduced-motion: reduce) {
        #sfStats *, #sfStats *::before, #sfStats *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
    }
    @media print {
        #sidebar, #sidebar-toggle, #sidebar-overlay, .sf-normal-only, .sf-private, #sfPresentationExit { display: none !important; }
        #content-wrapper { margin: 0 !important; height: auto !important; }
        #content-wrapper > main { padding: 0 !important; overflow: visible !important; }
        #sfStats { --sf-page: #fff; --sf-surface: #fff; --sf-surface-2: #f8fafc; --sf-border: rgba(15,23,42,.14); --sf-ink: #0b0b0b; --sf-muted: #52514e; --sf-subtle: #6b7280; color-scheme: light; }
        .sf-card { break-inside: avoid; box-shadow: none !important; }
        .sf-segment:nth-child(1), .sf-month-bar { background-image: repeating-linear-gradient(45deg, transparent 0 3px, rgba(0,0,0,.22) 3px 4px); }
        .sf-segment:nth-child(2) { background-image: repeating-linear-gradient(135deg, transparent 0 3px, rgba(0,0,0,.22) 3px 4px); }
    }
</style>

<button id="sfPresentationExit" type="button" class="sf-btn" onclick="sfTogglePresentation(false)" aria-label="Salir del modo presentación"><i class="fas fa-compress"></i> Salir</button>

<div id="sfStats">
    <header class="sf-topbar">
        <div>
            <div class="sf-eyebrow">Sorteo Flash · Comunidad</div>
            <h1 class="sf-title">Estadísticas y Métricas Comunitarias</h1>
            <p class="sf-subtitle">Una mirada transparente al impacto, la suerte y las decisiones que construyen cada sorteo.</p>
        </div>
        <div class="sf-actions sf-normal-only">
            <button type="button" class="sf-btn" onclick="sfLoad()" id="sfRefresh"><i class="fas fa-rotate"></i> Actualizar</button>
            <button type="button" class="sf-btn sf-btn--primary" onclick="sfTogglePresentation()" id="sfPresentationBtn"><i class="fas fa-expand"></i> Modo Presentación / Captura</button>
        </div>
    </header>

    <div id="sfError" class="sf-error" role="alert"><i class="fas fa-circle-exclamation"></i><span></span></div>

    <div class="sf-frame" id="sfDashboard" aria-busy="true">
        <div id="sfLoading" class="sf-loading">
            <div class="sf-loading-inner"><span class="sf-loader-mark"></span><span>Construyendo la historia de la comunidad…</span></div>
        </div>

        <section class="sf-grid sf-hero-grid" aria-label="Resumen general">
            <article class="sf-card sf-card--hero">
                <div class="sf-card-top">
                    <div><div class="sf-card-label">Valor total en premios otorgados</div><div class="sf-big-note">Bananos y paVos expresados en una misma unidad comunitaria</div></div>
                    <span class="sf-card-icon"><i class="fas fa-coins"></i></span>
                </div>
                <div><span class="sf-hero-value" id="sfAwardedCoins">—</span><span class="sf-hero-unit">Bananos</span></div>
                <div class="sf-hero-equivalent" id="sfAwardedSoles">Equivalente a —</div>
                <div class="sf-hero-rate" id="sfRate">Tasa configurada: —</div>
            </article>
            <article class="sf-card sf-card--pad">
                <div class="sf-card-top"><div class="sf-card-label">Sorteos completados</div><span class="sf-card-icon"><i class="fas fa-flag-checkered"></i></span></div>
                <div class="sf-big-value" id="sfCompleted">—</div>
                <div class="sf-big-note">ediciones cerradas con resultado</div>
            </article>
            <article class="sf-card sf-card--pad">
                <div class="sf-card-top"><div class="sf-card-label">Momentos ganadores</div><span class="sf-card-icon"><i class="fas fa-trophy"></i></span></div>
                <div class="sf-big-value" id="sfWinners">—</div>
                <div class="sf-big-note">tickets elegidos históricamente</div>
            </article>
        </section>

        <section class="sf-grid sf-kpi-grid" aria-label="Indicadores comunitarios">
            <article class="sf-card sf-card--pad">
                <div class="sf-card-top"><div class="sf-card-label">Comunidad alcanzada</div><span class="sf-delta"><i class="fas fa-users"></i> únicos</span></div>
                <div class="sf-kpi-value" id="sfUnique">—</div><div class="sf-kpi-note" id="sfParticipations">— participaciones válidas</div>
            </article>
            <article class="sf-card sf-card--pad">
                <div class="sf-card-top"><div class="sf-card-label">Promedio por sorteo</div><span class="sf-delta"><i class="fas fa-chart-simple"></i> histórico</span></div>
                <div class="sf-kpi-value" id="sfAverage">—</div><div class="sf-kpi-note">participantes por edición completada</div>
            </article>
            <article class="sf-card sf-card--pad">
                <div class="sf-card-top"><div class="sf-card-label">Probabilidad observada</div><span class="sf-delta"><i class="fas fa-clover"></i> global</span></div>
                <div class="sf-kpi-value" id="sfWinnerRate">—</div><div class="sf-kpi-note">tickets ganadores sobre participaciones</div>
            </article>
            <article class="sf-card sf-card--pad">
                <div class="sf-card-top"><div class="sf-card-label">Últimos 30 días</div><span class="sf-delta"><i class="fas fa-calendar"></i> reciente</span></div>
                <div class="sf-kpi-value" id="sfLast30">—</div><div class="sf-kpi-note" id="sfLast30Note">— en premios · — ganadores</div>
            </article>
        </section>

        <section class="sf-grid sf-main-grid">
            <article class="sf-card">
                <header class="sf-card-head">
                    <div><h2 class="sf-card-title"><i class="fas fa-ranking-star mr-2" style="color:var(--sf-accent)"></i>Top 10 · Máximos Ganadores</h2><p class="sf-card-desc">Ordenado por victorias y, en empate, por valor acumulado de premios.</p></div>
                    <span class="sf-chip"><i class="fas fa-crown"></i> Salón de la fama</span>
                </header>
                <div class="sf-ranking" id="sfRanking"></div>
            </article>

            <div class="sf-side-stack">
                <article class="sf-card sf-lucky">
                    <div class="sf-card-top"><div><div class="sf-card-label">Ticket de la Suerte</div><div class="sf-card-desc">El número más repetido entre tickets ganadores</div></div><span class="sf-card-icon" style="color:var(--sf-series-2);background:rgba(217,89,38,.1)"><i class="fas fa-ticket"></i></span></div>
                    <div class="sf-ticket-wrap"><div class="sf-ticket" id="sfLuckyTicket">#—</div><div class="sf-ticket-meta" id="sfLuckyMeta">Aún no hay datos suficientes.</div></div>
                </article>
                <article class="sf-card sf-record">
                    <div class="sf-card-top"><div><div class="sf-card-label">Edición más concurrida</div><div class="sf-card-desc">Récord histórico de participación válida</div></div><span class="sf-card-icon"><i class="fas fa-fire"></i></span></div>
                    <div class="sf-record-name" id="sfRecordName">—</div>
                    <div class="sf-record-value"><span id="sfRecordValue">—</span> <span>participantes</span></div>
                </article>
            </div>
        </section>

        <section class="sf-grid sf-community-grid">
            <article class="sf-card sf-decision-card">
                <header class="sf-card-head">
                    <div><h2 class="sf-card-title">¿Qué eligen quienes ganan?</h2><p class="sf-card-desc">Distribución histórica de decisiones finales.</p></div>
                    <span class="sf-chip" id="sfDecisionTotal">0 decisiones</span>
                </header>
                <div class="sf-decisions">
                    <div class="sf-decision-summary" id="sfDecisionLegend"></div>
                    <div class="sf-stackbar" id="sfDecisionBar" role="group" aria-label="Distribución de decisiones"></div>
                    <details class="sf-table-toggle"><summary>Ver datos en tabla</summary><table class="sf-a11y-table"><thead><tr><th>Decisión</th><th>Cantidad</th><th>Porcentaje</th></tr></thead><tbody id="sfDecisionTable"></tbody></table></details>
                </div>
            </article>
            <article class="sf-card sf-gamble">
                <div class="sf-ring" id="sfDoubleRing"><span class="sf-ring-value" id="sfDoubleRate">—</span></div>
                <div class="sf-gamble-copy"><h3>Doble o Nada</h3><p>Tasa histórica de victoria entre quienes decidieron jugarse el premio.</p><div class="sf-winloss" id="sfWinLoss"><span>Ganados <b>—</b></span><span>·</span><span>Perdidos <b>—</b></span></div></div>
            </article>
        </section>

        <section class="sf-card" aria-labelledby="sfMonthlyTitle">
            <header class="sf-card-head">
                <div><h2 class="sf-card-title" id="sfMonthlyTitle">Pulso de la comunidad · 12 meses</h2><p class="sf-card-desc">Participaciones válidas por mes. Pasa el cursor o enfoca una barra para ver el detalle.</p></div>
                <span class="sf-chip"><i class="fas fa-chart-column"></i> Participación mensual</span>
            </header>
            <div class="sf-month-chart" id="sfMonthlyChart"></div>
            <details class="sf-table-toggle" style="padding:0 1.15rem 1rem"><summary>Ver datos mensuales en tabla</summary><table class="sf-a11y-table"><thead><tr><th>Mes</th><th>Sorteos</th><th>Participaciones</th><th>Premios</th></tr></thead><tbody id="sfMonthlyTable"></tbody></table></details>
        </section>

        <section class="sf-grid sf-community-grid" style="margin-top:.85rem;margin-bottom:0">
            <article class="sf-card sf-mini-card">
                <div class="sf-card-label">La comunidad vuelve</div><div class="sf-mini-value" id="sfRepeatRate">—</div><div class="sf-mini-copy" id="sfRepeatCopy">— participantes han estado en más de una edición.</div><div class="sf-progress"><span id="sfRepeatBar" style="width:0%"></span></div>
            </article>
            <article class="sf-card sf-mini-card">
                <div class="sf-card-label">Participación total</div><div class="sf-mini-value" id="sfTotalEntries">—</div><div class="sf-mini-copy">tickets válidos que formaron parte de sorteos ya completados.</div>
            </article>
            <article class="sf-card sf-mini-card">
                <div class="sf-card-label">Valor medio por momento ganador</div><div class="sf-mini-value" id="sfAvgPrize">—</div><div class="sf-mini-copy">equivalente promedio otorgado por cada ticket ganador.</div>
            </article>
        </section>

        <section class="sf-private" aria-label="Auditoría interna">
            <div class="sf-private-head"><i class="fas fa-shield-halved"></i> Auditoría interna · oculta en modo captura</div>
            <div class="sf-grid sf-private-grid">
                <article class="sf-card sf-mini-card"><div class="sf-card-label">Cuotas recaudadas</div><div class="sf-mini-value" id="sfFees">—</div><div class="sf-mini-copy">Bananos cobrados en sorteos completados.</div></article>
                <article class="sf-card sf-mini-card"><div class="sf-card-label">Entregas pendientes</div><div class="sf-mini-value" id="sfPending">—</div><div class="sf-mini-copy">premios con componente manual aún abierto.</div></article>
                <article class="sf-card sf-mini-card"><div class="sf-card-label">Tickets excluidos</div><div class="sf-mini-value" id="sfExpelled">—</div><div class="sf-mini-copy">huecos por expulsión en ediciones completadas.</div></article>
                <article class="sf-card sf-mini-card"><div class="sf-card-label">Premios sin valoración</div><div class="sf-mini-value" id="sfUnvalued">—</div><div class="sf-mini-copy">resultados que requieren revisar su referencia de valor.</div></article>
            </div>
        </section>

        <footer class="sf-foot"><span class="sf-brand"><i class="fas fa-bolt"></i> Sorteos Flash · Banano Store</span><span id="sfUpdated">Actualizado —</span></footer>
    </div>
</div>

<script>
(() => {
    const SF_API = 'sorteos_flash/api_sorteos.php';
    const SF_AVATARS = '/assets/avatars/';
    const sfNumber = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 0 });
    const sfDecimal = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    const sfMoney = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN', minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const sfCompact = new Intl.NumberFormat('es-PE', { notation: 'compact', maximumFractionDigits: 1 });
    const sfMonthFmt = new Intl.DateTimeFormat('es-PE', { month: 'short', year: 'numeric', timeZone: 'UTC' });
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
    const n = value => Number(value || 0);
    const fmtCoins = value => `${sfDecimal.format(n(value))} Bananos`;

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function renderRanking(users) {
        const box = document.getElementById('sfRanking');
        if (!users.length) {
            box.innerHTML = '<div class="sf-empty"><i class="fas fa-trophy"></i>Aún no hay ganadores para mostrar.</div>';
            return;
        }
        box.innerHTML = users.map((u, index) => {
            const handle = u.game_username && u.game_username !== u.name ? u.game_username : 'Comunidad Sorteo Flash';
            return `<div class="sf-rank-row">
                <div class="sf-rank-pos">${index + 1}</div>
                <div class="sf-user">
                    <img class="sf-avatar" src="${esc(SF_AVATARS + (u.avatar || 'default.png'))}" alt="">
                    <div class="sf-user-copy"><div class="sf-user-name">${esc(u.name || 'Jugador')}${n(u.is_verified) ? '<i class="fas fa-circle-check sf-verified" title="Usuario verificado"></i>' : ''}</div><div class="sf-user-handle">${esc(handle)}</div></div>
                </div>
                <div class="sf-rank-number">${sfNumber.format(n(u.wins))}<span>victorias</span></div>
                <div class="sf-rank-number sf-rank-prize">${sfCompact.format(n(u.prize_coins))}<span>Bananos</span></div>
            </div>`;
        }).join('');
    }

    function renderDecisions(d) {
        const items = [
            { key: 'original', label: 'Premio Original', color: 'var(--sf-series-1)', data: d.original || {} },
            { key: 'cashback', label: 'Cashback', color: 'var(--sf-series-2)', data: d.cashback || {} },
            { key: 'double', label: 'Doble o Nada', color: 'var(--sf-series-3)', data: d.double || {} },
        ];
        setText('sfDecisionTotal', `${sfNumber.format(n(d.total))} decisiones`);
        document.getElementById('sfDecisionLegend').innerHTML = items.map(item => `<div class="sf-decision-stat"><span class="sf-dot" style="background:${item.color}"></span><div><div class="sf-decision-name">${item.label}</div><div class="sf-decision-pct">${sfDecimal.format(n(item.data.pct))}% <small>${sfNumber.format(n(item.data.count))}</small></div></div></div>`).join('');
        document.getElementById('sfDecisionBar').innerHTML = items.map(item => {
            const pct = Math.max(0, n(item.data.pct));
            return pct > 0 ? `<button type="button" class="sf-segment" style="width:${pct}%;background:${item.color}" data-tip="${esc(item.label)} · ${sfDecimal.format(pct)}% (${sfNumber.format(n(item.data.count))})" aria-label="${esc(item.label)}: ${sfDecimal.format(pct)} por ciento, ${sfNumber.format(n(item.data.count))} decisiones"></button>` : '';
        }).join('');
        document.getElementById('sfDecisionTable').innerHTML = items.map(item => `<tr><th>${item.label}</th><td>${sfNumber.format(n(item.data.count))}</td><td>${sfDecimal.format(n(item.data.pct))}%</td></tr>`).join('');

        const rate = Math.max(0, Math.min(100, n(d.double_win_rate)));
        document.getElementById('sfDoubleRing').style.setProperty('--p', rate);
        setText('sfDoubleRate', `${sfDecimal.format(rate)}%`);
        document.getElementById('sfWinLoss').innerHTML = `<span><i class="fas fa-check mr-1" style="color:var(--sf-good)"></i>Ganados <b>${sfNumber.format(n(d.double_wins))}</b></span><span>·</span><span><i class="fas fa-xmark mr-1" style="color:var(--sf-danger)"></i>Perdidos <b>${sfNumber.format(n(d.double_losses))}</b></span>`;
    }

    function renderMonths(months) {
        const max = Math.max(1, ...months.map(m => n(m.participants)));
        document.getElementById('sfMonthlyChart').innerHTML = months.map((m, index) => {
            const height = n(m.participants) > 0 ? Math.max(4, n(m.participants) * 100 / max) : 2;
            const date = new Date(`${m.key}-01T00:00:00Z`);
            const full = sfMonthFmt.format(date);
            const short = full.split(' ')[0].replace('.', '');
            const tip = `${full} · ${sfNumber.format(n(m.participants))} participaciones · ${sfNumber.format(n(m.raffles))} sorteos`;
            return `<div class="sf-month-col"><div class="sf-month-mark"><button type="button" class="sf-month-bar" style="height:${height}%;animation-delay:${index * 25}ms" data-tip="${esc(tip)}" aria-label="${esc(tip)}"></button></div><div class="sf-month-label">${esc(short)}</div></div>`;
        }).join('');
        document.getElementById('sfMonthlyTable').innerHTML = months.map(m => {
            const date = new Date(`${m.key}-01T00:00:00Z`);
            return `<tr><th>${esc(sfMonthFmt.format(date))}</th><td>${sfNumber.format(n(m.raffles))}</td><td>${sfNumber.format(n(m.participants))}</td><td>${esc(fmtCoins(m.prize_coins))}</td></tr>`;
        }).join('');
    }

    function render(data) {
        const g = data.general || {};
        const rate = data.rate || {};
        const internal = data.internal || {};

        setText('sfAwardedCoins', sfDecimal.format(n(g.awarded_coins)));
        setText('sfAwardedSoles', `Equivalente a ${sfMoney.format(n(g.awarded_soles))}`);
        setText('sfRate', `Tasa configurada: ${sfDecimal.format(n(rate.unit_coins))} Bananos = ${sfMoney.format(n(rate.unit_money))}`);
        setText('sfCompleted', sfNumber.format(n(g.completed_raffles)));
        setText('sfWinners', sfNumber.format(n(g.winners)));
        setText('sfUnique', sfNumber.format(n(g.unique_participants)));
        setText('sfParticipations', `${sfNumber.format(n(g.participations))} participaciones válidas`);
        setText('sfAverage', sfDecimal.format(n(g.avg_participants)));
        setText('sfWinnerRate', `${sfDecimal.format(n(g.winner_rate))}%`);
        setText('sfLast30', sfCompact.format(n(g.last_30d_coins)));
        setText('sfLast30Note', `${fmtCoins(g.last_30d_coins)} · ${sfNumber.format(n(g.last_30d_winners))} ganadores`);

        renderRanking(data.top_winners || []);
        renderDecisions(data.decisions || {});
        renderMonths(data.monthly || []);

        const lucky = data.lucky_ticket;
        if (lucky) {
            setText('sfLuckyTicket', `#${sfNumber.format(n(lucky.ticket))}`);
            const tieText = n(lucky.ties) > 1 ? ` · comparte el récord con ${sfNumber.format(n(lucky.ties) - 1)} número(s)` : '';
            document.getElementById('sfLuckyMeta').innerHTML = `Apareció <strong>${sfNumber.format(n(lucky.repeats))} veces</strong> entre ganadores${esc(tieText)}.`;
        } else {
            setText('sfLuckyTicket', '#—');
            setText('sfLuckyMeta', 'Aún no hay datos suficientes.');
        }

        const record = data.record_raffle || {};
        setText('sfRecordName', record.title || '—');
        setText('sfRecordValue', sfNumber.format(n(record.participants)));

        const repeatRate = Math.max(0, Math.min(100, n(g.repeat_rate)));
        setText('sfRepeatRate', `${sfDecimal.format(repeatRate)}%`);
        setText('sfRepeatCopy', `${sfNumber.format(n(g.repeat_participants))} participantes han estado en más de una edición.`);
        document.getElementById('sfRepeatBar').style.width = `${repeatRate}%`;
        setText('sfTotalEntries', sfNumber.format(n(g.participations)));
        setText('sfAvgPrize', g.winners > 0 ? fmtCoins(n(g.awarded_coins) / n(g.winners)) : '0 Bananos');

        setText('sfFees', fmtCoins(internal.entry_fees_coins));
        setText('sfPending', sfNumber.format(n(internal.pending_deliveries)));
        setText('sfExpelled', sfNumber.format(n(internal.expelled_tickets)));
        setText('sfUnvalued', sfNumber.format(n(internal.unvalued_prizes)));

        const updated = data.generated_at ? new Date(data.generated_at) : new Date();
        setText('sfUpdated', `Actualizado ${updated.toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' })}`);
    }

    window.sfLoad = async function () {
        const dashboard = document.getElementById('sfDashboard');
        const loader = document.getElementById('sfLoading');
        const error = document.getElementById('sfError');
        const refresh = document.getElementById('sfRefresh');
        error.classList.remove('is-visible');
        if (refresh) refresh.disabled = true;
        dashboard.style.opacity = loader.classList.contains('is-hidden') ? '.62' : '1';
        try {
            const response = await fetch(`${SF_API}?action=community_stats`, { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || data.status !== 'success') throw new Error(data.message || 'No se pudieron cargar las estadísticas.');
            render(data);
            loader.classList.add('is-hidden');
        } catch (err) {
            loader.classList.add('is-hidden');
            error.querySelector('span').textContent = err.message || 'No se pudieron cargar las estadísticas.';
            error.classList.add('is-visible');
        } finally {
            dashboard.style.opacity = '1';
            dashboard.setAttribute('aria-busy', 'false');
            if (refresh) refresh.disabled = false;
        }
    };

    window.sfTogglePresentation = function (force) {
        const next = typeof force === 'boolean' ? force : !document.body.classList.contains('sf-presentation');
        document.body.classList.toggle('sf-presentation', next);
        const button = document.getElementById('sfPresentationBtn');
        if (button) button.innerHTML = next ? '<i class="fas fa-compress"></i> Salir de Presentación' : '<i class="fas fa-expand"></i> Modo Presentación / Captura';
        if (next && document.documentElement.requestFullscreen && !document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(() => {});
        } else if (!next && document.fullscreenElement && document.exitFullscreen) {
            document.exitFullscreen().catch(() => {});
        }
    };

    document.addEventListener('fullscreenchange', () => {
        if (!document.fullscreenElement && document.body.classList.contains('sf-presentation')) sfTogglePresentation(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && document.body.classList.contains('sf-presentation')) sfTogglePresentation(false);
        if ((event.key === 'p' || event.key === 'P') && !event.ctrlKey && !event.metaKey && !/INPUT|TEXTAREA|SELECT/.test(event.target.tagName)) sfTogglePresentation();
    });

    sfLoad();
})();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

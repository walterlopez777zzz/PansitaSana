<?php
// /wabo/sorteos_flash/pase_bienvenida.php
// Panel comercial del Pase de Bienvenida: emitidos vs usados, retención de
// clientes nuevos, tablas de seguimiento y asignación manual (compras por chat).
// Datos: api_sorteos.php?action=wt_dashboard | wt_user_search | wt_grant | wt_revoke_grant
require_once __DIR__ . '/../core/admin_header.php';
?>

<style>
    @keyframes wpRise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes wpGrow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
    @keyframes wpBar { from { transform: scaleY(0); } to { transform: scaleY(1); } }
    @keyframes wpRing { from { stroke-dasharray: 0 999; } }
    @keyframes wpShimmer { 0% { background-position: -400px 0; } 100% { background-position: 400px 0; } }
    @keyframes wpFloat { 0%,100% { transform: translateY(0) rotate(-8deg); } 50% { transform: translateY(-6px) rotate(-5deg); } }

    #wpPanel {
        --wp-surface: #0b0d0c;
        --wp-surface-2: #10141277;
        --wp-border: rgba(255,255,255,.09);
        --wp-ink: #f7fbf8;
        --wp-muted: #9aa5a0;
        --wp-subtle: #66716c;
        --wp-accent: #34d399;        /* emerald: captación */
        --wp-accent-soft: rgba(52,211,153,.12);
        --wp-lime: #a3e635;          /* pase */
        --wp-gold: #fbbf24;          /* dinero */
        --wp-violet: #a78bfa;        /* sorteos */
        --wp-rose: #fb7185;          /* inactivo / alerta */
        --wp-sky: #38bdf8;
        color: var(--wp-ink);
        max-width: 1560px;
        margin: 0 auto;
        font-variant-numeric: tabular-nums;
    }
    [data-theme="light"] #wpPanel {
        --wp-surface: #ffffff;
        --wp-surface-2: #f4f7f5;
        --wp-border: rgba(15,23,42,.1);
        --wp-ink: #0b1210;
        --wp-muted: #4e5a55;
        --wp-subtle: #86928d;
        --wp-accent: #059669;
        --wp-accent-soft: rgba(5,150,105,.09);
        --wp-lime: #65a30d;
        --wp-gold: #d97706;
        --wp-violet: #7c3aed;
        --wp-rose: #e11d48;
        --wp-sky: #0284c7;
    }
    [data-theme="glitch"] #wpPanel {
        --wp-surface: rgba(13,13,13,.92);
        --wp-surface-2: rgba(26,26,26,.94);
        --wp-border: rgba(255,0,255,.38);
        --wp-ink: #fff;
        --wp-muted: #e5e7eb;
    }

    /* ---------- cabecera ---------- */
    .wp-topbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 1.25rem; margin-bottom: 1.1rem; animation: wpRise .35s ease-out both; }
    .wp-eyebrow { display: flex; align-items: center; gap: .55rem; color: var(--wp-accent); font-size: .68rem; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; margin-bottom: .35rem; }
    .wp-eyebrow::before { content: ''; width: 1.75rem; height: 1px; background: currentColor; }
    .wp-title { margin: 0; font-size: clamp(1.6rem, 2.8vw, 2.35rem); line-height: 1.08; font-weight: 800; letter-spacing: -.035em; color: var(--wp-ink); display: flex; align-items: center; gap: .6rem; }
    .wp-title .wp-ticket-ico { display: inline-flex; width: 2.2rem; height: 2.2rem; border-radius: .6rem; background: linear-gradient(135deg, var(--wp-lime), var(--wp-accent)); color: #052e1c; align-items: center; justify-content: center; font-size: 1rem; animation: wpFloat 4s ease-in-out infinite; }
    .wp-subtitle { color: var(--wp-muted); font-size: .86rem; margin-top: .48rem; max-width: 760px; }
    .wp-actions { display: flex; align-items: center; gap: .55rem; flex-wrap: wrap; justify-content: flex-end; }
    .wp-btn { display: inline-flex; align-items: center; justify-content: center; gap: .48rem; min-height: 2.5rem; padding: .55rem .85rem; border-radius: .7rem; border: 1px solid var(--wp-border); background: var(--wp-surface); color: var(--wp-muted); font-size: .76rem; font-weight: 750; cursor: pointer; text-decoration: none; transition: border-color .18s, color .18s, transform .18s, background .18s; }
    .wp-btn:hover, .wp-btn:focus-visible { border-color: rgba(52,211,153,.5); color: var(--wp-ink); transform: translateY(-1px); outline: none; }
    .wp-btn--primary { border-color: rgba(52,211,153,.4); background: var(--wp-accent-soft); color: var(--wp-accent); }
    .wp-btn--danger { border-color: rgba(251,113,133,.35); color: var(--wp-rose); }
    .wp-btn--sm { min-height: 1.9rem; padding: .25rem .6rem; font-size: .68rem; border-radius: .5rem; }
    .wp-btn[disabled] { opacity: .55; cursor: not-allowed; transform: none; }

    /* ---------- grids / tarjetas ---------- */
    .wp-grid { display: grid; gap: .85rem; margin-bottom: .85rem; }
    .wp-grid--hero { grid-template-columns: minmax(300px, 1.1fr) repeat(4, minmax(0, .7fr)); }
    .wp-grid--mid { grid-template-columns: minmax(0, 1.35fr) minmax(320px, .9fr); align-items: stretch; }
    .wp-grid--wide { grid-template-columns: minmax(0, 1.6fr) minmax(320px, .9fr); }
    .wp-grid--fun { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .wp-card { position: relative; background: var(--wp-surface); border: 1px solid var(--wp-border); border-radius: 1rem; overflow: hidden; animation: wpRise .4s ease-out both; }
    .wp-card--pad { padding: 1.1rem 1.15rem; }
    .wp-card-label { color: var(--wp-muted); font-size: .66rem; font-weight: 750; letter-spacing: .09em; text-transform: uppercase; }
    .wp-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
    .wp-card-icon { width: 2.2rem; height: 2.2rem; display: inline-flex; align-items: center; justify-content: center; border-radius: .7rem; background: var(--wp-accent-soft); color: var(--wp-accent); flex: none; }
    .wp-card-icon--gold { background: rgba(251,191,36,.12); color: var(--wp-gold); }
    .wp-card-icon--violet { background: rgba(167,139,250,.14); color: var(--wp-violet); }
    .wp-card-icon--lime { background: rgba(163,230,53,.13); color: var(--wp-lime); }
    .wp-card-icon--rose { background: rgba(251,113,133,.13); color: var(--wp-rose); }
    .wp-card-icon--sky { background: rgba(56,189,248,.13); color: var(--wp-sky); }
    .wp-kpi-value { font-size: clamp(1.9rem, 3vw, 2.6rem); line-height: 1; font-weight: 800; letter-spacing: -.05em; margin-top: .95rem; color: var(--wp-ink); }
    .wp-kpi-value small { font-size: .95rem; color: var(--wp-muted); font-weight: 700; letter-spacing: 0; margin-left: .25rem; }
    .wp-kpi-note { color: var(--wp-muted); font-size: .76rem; margin-top: .45rem; line-height: 1.4; }
    .wp-kpi-note b { color: var(--wp-ink); font-weight: 750; }
    .wp-section-title { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin: 0 0 .9rem; }
    .wp-section-title h2 { margin: 0; font-size: 1rem; font-weight: 800; letter-spacing: -.02em; color: var(--wp-ink); display: flex; align-items: center; gap: .5rem; }
    .wp-section-title p { margin: .2rem 0 0; color: var(--wp-muted); font-size: .76rem; }

    /* ---------- hero: anillo tasa de uso ---------- */
    .wp-hero { display: flex; gap: 1.2rem; align-items: center; background: radial-gradient(110% 150% at 100% 0%, rgba(52,211,153,.14), transparent 55%), var(--wp-surface); }
    .wp-ring { position: relative; width: 148px; height: 148px; flex: none; }
    .wp-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
    .wp-ring circle { fill: none; stroke-width: 12; stroke-linecap: round; }
    .wp-ring .wp-ring-bg { stroke: var(--wp-border); }
    .wp-ring .wp-ring-fg { stroke: url(#wpRingGrad); animation: wpRing 1.1s cubic-bezier(.2,.8,.2,1) both; transition: stroke-dasharray .6s ease; }
    .wp-ring-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .wp-ring-center b { font-size: 1.85rem; font-weight: 800; letter-spacing: -.05em; line-height: 1; color: var(--wp-ink); }
    .wp-ring-center span { color: var(--wp-muted); font-size: .62rem; font-weight: 750; letter-spacing: .1em; text-transform: uppercase; margin-top: .3rem; }
    .wp-hero-copy h3 { margin: .35rem 0 .25rem; font-size: 1.25rem; font-weight: 800; letter-spacing: -.03em; }
    .wp-hero-copy p { margin: 0; color: var(--wp-muted); font-size: .8rem; line-height: 1.45; }
    .wp-hero-split { display: flex; gap: 1rem; margin-top: .85rem; }
    .wp-hero-split div b { display: block; font-size: 1.25rem; font-weight: 800; letter-spacing: -.04em; }
    .wp-hero-split div span { color: var(--wp-subtle); font-size: .64rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 750; }
    .wp-verdict { display: inline-flex; align-items: center; gap: .4rem; margin-top: .75rem; padding: .3rem .65rem; border-radius: 999px; font-size: .68rem; font-weight: 800; letter-spacing: .04em; border: 1px solid transparent; }
    .wp-verdict--good { color: var(--wp-accent); background: var(--wp-accent-soft); border-color: rgba(52,211,153,.35); }
    .wp-verdict--warn { color: var(--wp-gold); background: rgba(251,191,36,.12); border-color: rgba(251,191,36,.35); }
    .wp-verdict--bad { color: var(--wp-rose); background: rgba(251,113,133,.12); border-color: rgba(251,113,133,.35); }
    .wp-verdict--na { color: var(--wp-muted); background: var(--wp-surface-2); border-color: var(--wp-border); }

    /* ---------- funnel ---------- */
    .wp-funnel { display: grid; gap: .55rem; }
    .wp-funnel-row { display: grid; grid-template-columns: 190px minmax(0, 1fr) 96px; align-items: center; gap: .8rem; font-size: .8rem; }
    .wp-funnel-row .wp-fl { color: var(--wp-muted); font-weight: 650; }
    .wp-funnel-track { height: 26px; background: var(--wp-surface-2); border: 1px solid var(--wp-border); border-radius: .55rem; overflow: hidden; }
    .wp-funnel-fill { height: 100%; border-radius: .45rem; transform-origin: left; animation: wpGrow .8s cubic-bezier(.2,.8,.2,1) both; background: linear-gradient(90deg, var(--c1), var(--c2)); min-width: 3px; }
    .wp-funnel-row .wp-fv { text-align: right; font-weight: 800; }
    .wp-funnel-row .wp-fv small { display: block; color: var(--wp-subtle); font-weight: 650; font-size: .66rem; }

    /* ---------- donut retención ---------- */
    .wp-donut-wrap { display: flex; gap: 1.1rem; align-items: center; }
    .wp-donut { width: 150px; height: 150px; border-radius: 50%; flex: none; position: relative; background: conic-gradient(var(--wp-border) 0 100%); transition: background .6s ease; }
    .wp-donut::after { content: ''; position: absolute; inset: 20px; border-radius: 50%; background: var(--wp-surface); }
    .wp-donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 1; }
    .wp-donut-center b { font-size: 1.5rem; font-weight: 800; letter-spacing: -.05em; line-height: 1; }
    .wp-donut-center span { color: var(--wp-muted); font-size: .58rem; text-transform: uppercase; letter-spacing: .1em; font-weight: 750; margin-top: .25rem; }
    .wp-legend { display: grid; gap: .45rem; flex: 1; min-width: 0; }
    .wp-legend-row { display: grid; grid-template-columns: 12px minmax(0,1fr) auto auto; gap: .55rem; align-items: center; font-size: .76rem; color: var(--wp-muted); }
    .wp-legend-row i { width: 12px; height: 12px; border-radius: 3px; display: block; }
    .wp-legend-row b { color: var(--wp-ink); font-weight: 800; }
    .wp-legend-row em { color: var(--wp-subtle); font-style: normal; font-size: .68rem; min-width: 3.2rem; text-align: right; }

    /* ---------- barras mensuales ---------- */
    .wp-bars { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: .45rem; align-items: end; height: 190px; padding-top: .5rem; }
    .wp-bar-col { display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; gap: .3rem; min-width: 0; }
    .wp-bar-group { display: flex; align-items: flex-end; gap: 3px; height: 100%; width: 100%; justify-content: center; }
    .wp-bar { width: 26%; max-width: 14px; border-radius: 4px 4px 2px 2px; transform-origin: bottom; animation: wpBar .7s cubic-bezier(.2,.8,.2,1) both; min-height: 2px; position: relative; }
    .wp-bar--q { background: var(--wp-sky); }
    .wp-bar--u { background: var(--wp-lime); }
    .wp-bar--r { background: var(--wp-gold); }
    .wp-bar[data-v]:hover::after { content: attr(data-v); position: absolute; bottom: calc(100% + 4px); left: 50%; transform: translateX(-50%); background: var(--wp-ink); color: var(--wp-surface); font-size: .62rem; font-weight: 800; padding: .15rem .35rem; border-radius: .3rem; white-space: nowrap; z-index: 2; }
    .wp-bar-month { color: var(--wp-subtle); font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .wp-chart-legend { display: flex; gap: 1rem; flex-wrap: wrap; font-size: .7rem; color: var(--wp-muted); margin-top: .6rem; }
    .wp-chart-legend span { display: inline-flex; align-items: center; gap: .35rem; }
    .wp-chart-legend i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }

    /* ---------- ranking sorteos ---------- */
    .wp-rank { display: grid; gap: .55rem; }
    .wp-rank-row { display: grid; grid-template-columns: minmax(0, 1fr) 70px; gap: .6rem; align-items: center; }
    .wp-rank-row .wp-rt { font-size: .78rem; font-weight: 650; color: var(--wp-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .wp-rank-row .wp-rt small { color: var(--wp-subtle); font-weight: 600; margin-left: .3rem; }
    .wp-rank-track { height: 8px; border-radius: 999px; background: var(--wp-surface-2); border: 1px solid var(--wp-border); overflow: hidden; margin-top: .3rem; display: flex; }
    .wp-rank-track i { display: block; height: 100%; transform-origin: left; animation: wpGrow .7s ease-out both; }
    .wp-rank-row .wp-rv { text-align: right; font-size: .78rem; font-weight: 800; }
    .wp-rank-row .wp-rv small { display: block; color: var(--wp-subtle); font-weight: 650; font-size: .62rem; }

    /* ---------- curiosidades ---------- */
    .wp-fun { display: flex; gap: .8rem; align-items: center; }
    .wp-fun-emoji { font-size: 1.6rem; flex: none; filter: drop-shadow(0 2px 6px rgba(0,0,0,.25)); }
    .wp-fun b { display: block; font-size: 1.05rem; font-weight: 800; letter-spacing: -.03em; line-height: 1.15; }
    .wp-fun span { color: var(--wp-muted); font-size: .72rem; }

    /* ---------- asignación manual ---------- */
    .wp-grant-form { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .6rem; }
    .wp-input { width: 100%; min-height: 2.5rem; padding: .55rem .8rem; border-radius: .7rem; border: 1px solid var(--wp-border); background: var(--wp-surface-2); color: var(--wp-ink); font-size: .84rem; outline: none; transition: border-color .18s; }
    .wp-input:focus { border-color: rgba(52,211,153,.55); }
    .wp-results { display: grid; gap: .5rem; margin-top: .75rem; }
    .wp-user-row { display: grid; grid-template-columns: 38px minmax(0, 1fr) auto; gap: .7rem; align-items: center; padding: .6rem .7rem; border: 1px solid var(--wp-border); border-radius: .8rem; background: var(--wp-surface-2); }
    .wp-user-row img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid var(--wp-border); }
    .wp-user-row .wp-un { font-size: .84rem; font-weight: 750; color: var(--wp-ink); display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
    .wp-user-row .wp-um { color: var(--wp-muted); font-size: .7rem; margin-top: .15rem; }
    .wp-user-row .wp-ua { display: flex; gap: .4rem; align-items: center; }
    .wp-empty { color: var(--wp-subtle); font-size: .78rem; text-align: center; padding: 1.1rem .5rem; border: 1px dashed var(--wp-border); border-radius: .8rem; }
    .wp-hint { color: var(--wp-subtle); font-size: .7rem; margin-top: .6rem; line-height: 1.45; }
    .wp-hint b { color: var(--wp-muted); }

    /* ---------- chips ---------- */
    .wp-chip { display: inline-flex; align-items: center; gap: .3rem; padding: .16rem .5rem; border-radius: 999px; font-size: .62rem; font-weight: 800; letter-spacing: .03em; border: 1px solid transparent; white-space: nowrap; }
    .wp-chip--ok { color: var(--wp-accent); background: var(--wp-accent-soft); border-color: rgba(52,211,153,.35); }
    .wp-chip--lime { color: var(--wp-lime); background: rgba(163,230,53,.12); border-color: rgba(163,230,53,.35); }
    .wp-chip--gold { color: var(--wp-gold); background: rgba(251,191,36,.12); border-color: rgba(251,191,36,.35); }
    .wp-chip--violet { color: var(--wp-violet); background: rgba(167,139,250,.14); border-color: rgba(167,139,250,.35); }
    .wp-chip--rose { color: var(--wp-rose); background: rgba(251,113,133,.12); border-color: rgba(251,113,133,.35); }
    .wp-chip--sky { color: var(--wp-sky); background: rgba(56,189,248,.12); border-color: rgba(56,189,248,.35); }
    .wp-chip--muted { color: var(--wp-muted); background: var(--wp-surface-2); border-color: var(--wp-border); }

    /* ---------- tablas ---------- */
    .wp-tabs { display: flex; gap: .4rem; flex-wrap: wrap; }
    .wp-tab { padding: .45rem .8rem; border-radius: .6rem; border: 1px solid var(--wp-border); background: transparent; color: var(--wp-muted); font-size: .74rem; font-weight: 750; cursor: pointer; display: inline-flex; align-items: center; gap: .4rem; }
    .wp-tab b { background: var(--wp-surface-2); border-radius: 999px; padding: .05rem .45rem; font-size: .66rem; color: var(--wp-ink); }
    .wp-tab.is-active { border-color: rgba(52,211,153,.5); color: var(--wp-ink); background: var(--wp-accent-soft); }
    .wp-table-tools { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
    .wp-table-tools .wp-input { width: 240px; min-height: 2.15rem; padding: .35rem .7rem; font-size: .78rem; }
    .wp-table-wrap { overflow: auto; border: 1px solid var(--wp-border); border-radius: .8rem; max-height: 560px; }
    .wp-table { width: 100%; border-collapse: collapse; font-size: .77rem; }
    .wp-table th { position: sticky; top: 0; z-index: 1; background: var(--wp-surface-2); color: var(--wp-muted); font-size: .64rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 800; text-align: left; padding: .6rem .7rem; border-bottom: 1px solid var(--wp-border); white-space: nowrap; cursor: pointer; user-select: none; }
    .wp-table th.is-sorted::after { content: ' ↕'; color: var(--wp-accent); }
    .wp-table td { padding: .55rem .7rem; border-bottom: 1px solid var(--wp-border); vertical-align: middle; white-space: nowrap; }
    .wp-table tbody tr:hover td { background: var(--wp-surface-2); }
    .wp-table .wp-num { text-align: right; font-weight: 700; }
    .wp-dim { color: var(--wp-subtle); }
    .wp-user-cell { display: flex; align-items: center; gap: .55rem; }
    .wp-user-cell img { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1px solid var(--wp-border); }
    .wp-user-cell b { font-weight: 750; color: var(--wp-ink); display: block; }
    .wp-user-cell small { color: var(--wp-subtle); font-size: .66rem; display: block; }
    .wp-table-empty { padding: 1.6rem; text-align: center; color: var(--wp-subtle); font-size: .8rem; }

    /* ---------- estados ---------- */
    .wp-loading { display: flex; align-items: center; gap: .6rem; color: var(--wp-muted); font-size: .8rem; padding: 1rem 0; }
    .wp-loading.is-hidden, .wp-error:not(.is-visible) { display: none; }
    .wp-spinner { width: 1rem; height: 1rem; border-radius: 50%; border: 2px solid var(--wp-border); border-top-color: var(--wp-accent); animation: spin .8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .wp-error { padding: .8rem 1rem; border: 1px solid rgba(251,113,133,.4); background: rgba(251,113,133,.1); color: var(--wp-rose); border-radius: .8rem; margin-bottom: .85rem; font-size: .8rem; }
    .wp-skel { background: linear-gradient(90deg, var(--wp-surface-2) 25%, var(--wp-border) 37%, var(--wp-surface-2) 63%); background-size: 800px 100%; animation: wpShimmer 1.4s infinite linear; border-radius: .4rem; color: transparent !important; }
    .wp-updated { color: var(--wp-subtle); font-size: .68rem; }

    @media (max-width: 1280px) {
        .wp-grid--hero { grid-template-columns: repeat(2, minmax(0,1fr)); }
        .wp-grid--hero .wp-card--hero { grid-column: 1 / -1; }
        .wp-grid--fun { grid-template-columns: repeat(2, minmax(0,1fr)); }
    }
    @media (max-width: 980px) {
        .wp-grid--mid, .wp-grid--wide { grid-template-columns: minmax(0,1fr); }
        .wp-topbar { flex-direction: column; align-items: stretch; }
        .wp-actions { justify-content: flex-start; }
        .wp-funnel-row { grid-template-columns: 120px minmax(0,1fr) 70px; }
    }
    @media (max-width: 640px) {
        .wp-grid--hero, .wp-grid--fun { grid-template-columns: minmax(0,1fr); }
        .wp-hero { flex-direction: column; align-items: flex-start; }
        .wp-donut-wrap { flex-direction: column; align-items: flex-start; }
        .wp-grant-form { grid-template-columns: minmax(0,1fr); }
        .wp-bars { grid-template-columns: repeat(6, minmax(0,1fr)); height: 150px; }
        .wp-bar-col:nth-child(-n+6) { display: none; }
    }
</style>

<div id="wpPanel">
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <defs>
            <linearGradient id="wpRingGrad" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#a3e635"/>
                <stop offset="100%" stop-color="#34d399"/>
            </linearGradient>
        </defs>
    </svg>

    <header class="wp-topbar">
        <div>
            <div class="wp-eyebrow">Sorteo Flash · Captación</div>
            <h1 class="wp-title"><span class="wp-ticket-ico"><i class="fas fa-ticket"></i></span> Pase de Bienvenida · Impacto comercial</h1>
            <p class="wp-subtitle">¿Los clientes nuevos que llegan por TikTok compran de verdad y se quedan? Aquí ves cuántos pases se ganaron, cuántos se usaron y qué pasó después con cada uno. Solo cuentan compras reales (los pedidos de premios <code>WIN-*</code> quedan fuera).</p>
        </div>
        <div class="wp-actions">
            <span class="wp-updated" id="wpUpdated">—</span>
            <a class="wp-btn" href="sorteos_flash/gestionar_sorteos.php" title="Botón 🎟️ Ticket de Bienvenida en Sorteos Flash: umbral, modo de valoración y alcance"><i class="fas fa-sliders"></i> Configurar umbral</a>
            <button type="button" class="wp-btn" id="wpExport" onclick="wpExportCsv()"><i class="fas fa-file-csv"></i> Exportar tabla</button>
            <button type="button" class="wp-btn wp-btn--primary" id="wpRefresh" onclick="wpLoad()"><i class="fas fa-rotate"></i> Actualizar</button>
        </div>
    </header>

    <div class="wp-loading" id="wpLoading"><span class="wp-spinner"></span> Calculando pases, compras reales y retención…</div>
    <div class="wp-error" id="wpError" role="alert"><i class="fas fa-triangle-exclamation"></i> <span></span></div>

    <div id="wpDashboard" aria-busy="true">

        <!-- HERO + KPIs -->
        <section class="wp-grid wp-grid--hero">
            <article class="wp-card wp-card--pad wp-card--hero wp-hero">
                <div class="wp-ring" aria-hidden="true">
                    <svg viewBox="0 0 120 120"><circle class="wp-ring-bg" cx="60" cy="60" r="52"/><circle class="wp-ring-fg" id="wpRingFg" cx="60" cy="60" r="52" stroke-dasharray="0 999"/></svg>
                    <div class="wp-ring-center"><b id="wpUsageRate">0%</b><span>tasa de uso</span></div>
                </div>
                <div class="wp-hero-copy">
                    <div class="wp-card-label">Pases emitidos vs. usados</div>
                    <h3 id="wpHeroTitle">Sin datos todavía</h3>
                    <p id="wpHeroCopy">Un pase se "emite" cuando un usuario cruza el umbral con compras reales (o lo asignas a mano) sin haber jugado nunca un sorteo.</p>
                    <div class="wp-hero-split">
                        <div><b id="wpIssued">0</b><span>emitidos</span></div>
                        <div><b id="wpUsed">0</b><span>usados</span></div>
                        <div><b id="wpAvailable">0</b><span>esperando</span></div>
                        <div><b id="wpGranted">0</b><span>manuales</span></div>
                    </div>
                    <div class="wp-verdict wp-verdict--na" id="wpVerdict"><i class="fas fa-circle-half-stroke"></i> Sin veredicto aún</div>
                </div>
            </article>

            <article class="wp-card wp-card--pad">
                <div class="wp-card-top"><span class="wp-card-label">Retención total</span><span class="wp-card-icon"><i class="fas fa-arrows-rotate"></i></span></div>
                <div class="wp-kpi-value" id="wpRetAnyRate">0%</div>
                <div class="wp-kpi-note"><b id="wpRetAny">0</b> de <b id="wpRetBase">0</b> usuarios con pase volvieron a comprar o a jugar después.</div>
            </article>
            <article class="wp-card wp-card--pad">
                <div class="wp-card-top"><span class="wp-card-label">Volvieron a comprar</span><span class="wp-card-icon wp-card-icon--gold"><i class="fas fa-cart-shopping"></i></span></div>
                <div class="wp-kpi-value" id="wpRebuyRate">0%</div>
                <div class="wp-kpi-note"><b id="wpRebuy">0</b> usuarios · <b id="wpOrdersAfter">0</b> pedidos · <b id="wpSpentAfter">S/ 0</b> facturados después del pase.</div>
            </article>
            <article class="wp-card wp-card--pad">
                <div class="wp-card-top"><span class="wp-card-label">Sorteos regulares</span><span class="wp-card-icon wp-card-icon--violet"><i class="fas fa-bolt"></i></span></div>
                <div class="wp-kpi-value" id="wpReplayStdRate">0%</div>
                <div class="wp-kpi-note"><b id="wpReplayStd">0</b> entraron luego a sorteos con requisitos estándar (sin pase) · <b id="wpReplay">0</b> a cualquier sorteo.</div>
            </article>
            <article class="wp-card wp-card--pad">
                <div class="wp-card-top"><span class="wp-card-label">Ticket medio del nuevo</span><span class="wp-card-icon wp-card-icon--lime"><i class="fas fa-coins"></i></span></div>
                <div class="wp-kpi-value" id="wpAvgQualifying">0<small>paVos</small></div>
                <div class="wp-kpi-note">Compra media con la que se ganó el pase · umbral actual <b id="wpThreshold">—</b> · <b id="wpAlmost">0</b> usuarios a punto de calificar.</div>
            </article>
        </section>

        <!-- FUNNEL + DONUT -->
        <section class="wp-grid wp-grid--mid">
            <article class="wp-card wp-card--pad">
                <div class="wp-section-title">
                    <div><h2><i class="fas fa-filter" style="color:var(--wp-accent)"></i> Embudo del cliente nuevo</h2><p>De calificar a convertirse en cliente recurrente.</p></div>
                </div>
                <div class="wp-funnel" id="wpFunnel"></div>
            </article>
            <article class="wp-card wp-card--pad">
                <div class="wp-section-title">
                    <div><h2><i class="fas fa-heart-pulse" style="color:var(--wp-rose)"></i> ¿Qué pasó después del pase?</h2><p>Estado actual de cada usuario que lo consumió.</p></div>
                </div>
                <div class="wp-donut-wrap">
                    <div class="wp-donut" id="wpDonut"><div class="wp-donut-center"><b id="wpDonutCenter">0</b><span>usaron el pase</span></div></div>
                    <div class="wp-legend" id="wpLegend"></div>
                </div>
            </article>
        </section>

        <!-- MENSUAL + RANKING SORTEOS -->
        <section class="wp-grid wp-grid--wide">
            <article class="wp-card wp-card--pad">
                <div class="wp-section-title">
                    <div><h2><i class="fas fa-chart-column" style="color:var(--wp-sky)"></i> Últimos 12 meses</h2><p>Nuevos calificados (cruzaron el umbral), pases usados y recompras del mes en que usaron el pase.</p></div>
                </div>
                <div class="wp-bars" id="wpBars"></div>
                <div class="wp-chart-legend"><span><i style="background:var(--wp-sky)"></i> Calificaron</span><span><i style="background:var(--wp-lime)"></i> Usaron el pase</span><span><i style="background:var(--wp-gold)"></i> Volvieron a comprar</span></div>
            </article>
            <article class="wp-card wp-card--pad">
                <div class="wp-section-title">
                    <div><h2><i class="fas fa-trophy" style="color:var(--wp-gold)"></i> Sorteos que más nuevos captaron</h2><p>Pases consumidos por sorteo · barra: recompra (dorado) y volvió a jugar (violeta).</p></div>
                </div>
                <div class="wp-rank" id="wpRank"></div>
            </article>
        </section>

        <!-- CURIOSIDADES -->
        <section class="wp-grid wp-grid--fun">
            <article class="wp-card wp-card--pad wp-fun"><span class="wp-fun-emoji">⚡</span><div><b id="wpFunFastest">—</b><span id="wpFunFastestSub">Recompra más rápida tras el pase</span></div></article>
            <article class="wp-card wp-card--pad wp-fun"><span class="wp-fun-emoji">💎</span><div><b id="wpFunBiggest">—</b><span id="wpFunBiggestSub">Quien más gastó después del pase</span></div></article>
            <article class="wp-card wp-card--pad wp-fun"><span class="wp-fun-emoji">⏳</span><div><b id="wpFunDaysUse">—</b><span>Días promedio entre calificar y usar el pase</span></div></article>
            <article class="wp-card wp-card--pad wp-fun"><span class="wp-fun-emoji">🏆</span><div><b id="wpFunWinners">—</b><span>Usuarios con pase que ganaron su primer sorteo</span></div></article>
        </section>

        <!-- ASIGNACIÓN MANUAL + TABLAS -->
        <section class="wp-grid wp-grid--wide">
            <article class="wp-card wp-card--pad" style="order:2">
                <div class="wp-section-title">
                    <div><h2><i class="fas fa-hand-holding-heart" style="color:var(--wp-lime)"></i> Otorgar pase manualmente</h2><p>Para compras por chat (WhatsApp / Messenger) que no pasaron por la web.</p></div>
                </div>
                <form class="wp-grant-form" onsubmit="wpSearch(event)">
                    <input class="wp-input" id="wpSearchInput" type="text" placeholder="Buscar por username, nombre público o código…" autocomplete="off" maxlength="60">
                    <button type="submit" class="wp-btn wp-btn--primary"><i class="fas fa-magnifying-glass"></i> Buscar</button>
                </form>
                <div class="wp-results" id="wpSearchResults"></div>
                <p class="wp-hint"><b>Qué hace:</b> el usuario queda calificado para el pase aunque no tenga compras web y aunque ya haya jugado antes (tú decides). Sigue siendo de <b>un solo uso</b>, aparece en "Pases disponibles" como <span class="wp-chip wp-chip--lime">Manual</span> y en el historial queda quién lo otorgó y por qué. Si registras también la compra en <a href="compras_manuales/gestionar_compras.php" style="color:var(--wp-accent)">Compras Manuales</a>, contará en el valor calificante.</p>
            </article>

            <article class="wp-card wp-card--pad" style="order:1">
                <div class="wp-section-title" style="flex-wrap:wrap">
                    <div class="wp-tabs" id="wpTabs">
                        <button type="button" class="wp-tab is-active" data-tab="available" onclick="wpSetTab('available')"><i class="fas fa-ticket"></i> Pases disponibles <b id="wpCntAvailable">0</b></button>
                        <button type="button" class="wp-tab" data-tab="consumed" onclick="wpSetTab('consumed')"><i class="fas fa-clock-rotate-left"></i> Pases consumidos <b id="wpCntConsumed">0</b></button>
                        <button type="button" class="wp-tab" data-tab="almost" onclick="wpSetTab('almost')"><i class="fas fa-gauge-high"></i> A punto de calificar <b id="wpCntAlmost">0</b></button>
                        <button type="button" class="wp-tab" data-tab="blocked" onclick="wpSetTab('blocked')"><i class="fas fa-ban"></i> Califican pero ya jugaron <b id="wpCntBlocked">0</b></button>
                    </div>
                    <div class="wp-table-tools">
                        <input class="wp-input" id="wpFilter" type="search" placeholder="Filtrar por usuario / sorteo…" oninput="wpRenderTable()">
                    </div>
                </div>
                <p class="wp-hint" id="wpTabHint" style="margin:-.3rem 0 .7rem"></p>
                <div class="wp-table-wrap"><table class="wp-table" id="wpTable"><thead></thead><tbody></tbody></table></div>
            </article>
        </section>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    const API = 'sorteos_flash/api_sorteos.php';
    const AVATAR_BASE = '/assets/avatars/';
    const nf = new Intl.NumberFormat('es-PE');
    const nf1 = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 1 });
    const money = v => 'S/ ' + new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v || 0));
    const pavos = v => nf.format(Math.round(Number(v || 0))) + ' paVos';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const n = v => Number(v || 0);
    const setText = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    const avatar = f => AVATAR_BASE + (f || 'default.png');
    const fmtDate = sql => {
        if (!sql) return '—';
        const d = new Date(String(sql).replace(' ', 'T') + 'Z');
        if (isNaN(d.getTime())) return sql;
        return d.toLocaleString('es-PE', { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' });
    };
    const fmtDay = sql => {
        if (!sql) return '—';
        const d = new Date(String(sql).replace(' ', 'T') + 'Z');
        return isNaN(d.getTime()) ? sql : d.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: '2-digit' });
    };
    const ago = sql => {
        if (!sql) return '';
        const ms = Date.now() - new Date(String(sql).replace(' ', 'T') + 'Z').getTime();
        const d = Math.floor(ms / 86400000);
        if (d <= 0) return 'hoy';
        if (d === 1) return 'hace 1 día';
        if (d < 30) return `hace ${d} días`;
        const m = Math.floor(d / 30);
        return m === 1 ? 'hace 1 mes' : `hace ${m} meses`;
    };
    const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    let DATA = null;
    let TAB = 'available';
    let SORT = { key: null, dir: -1 };

    const userCell = u => `
        <div class="wp-user-cell">
            <img src="${esc(avatar(u.avatar))}" alt="">
            <div><b>${esc(u.name)}${n(u.is_verified) ? ' <i class="fas fa-circle-check" style="color:var(--wp-sky);font-size:.7rem" title="Verificado"></i>' : ''}${u.account_status === 'banned' ? ' <span class="wp-chip wp-chip--rose">Baneado</span>' : ''}</b>
            <small>@${esc(u.game_username || '—')}${u.public_code ? ' · ' + esc(u.public_code) : ''} · #${u.user_id}</small></div>
        </div>`;

    const STATE_CHIP = {
        loyal:    '<span class="wp-chip wp-chip--ok"><i class="fas fa-star"></i> Compró y jugó</span>',
        rebuy:    '<span class="wp-chip wp-chip--gold"><i class="fas fa-cart-shopping"></i> Volvió a comprar</span>',
        replay:   '<span class="wp-chip wp-chip--violet"><i class="fas fa-bolt"></i> Volvió a jugar</span>',
        inactive: '<span class="wp-chip wp-chip--muted"><i class="fas fa-moon"></i> Sin actividad</span>',
        banned:   '<span class="wp-chip wp-chip--rose"><i class="fas fa-ban"></i> Baneado</span>',
    };

    /* ---------------- tablas ---------------- */
    const TABLES = {
        available: {
            hint: 'Usuarios que hoy cumplen el umbral (o tienen asignación manual), nunca jugaron un sorteo y aún no consumieron su pase. Son tus clientes nuevos "en espera": recuérdales que tienen un ticket gratis.',
            cols: [
                { key: 'name', label: 'Usuario', render: r => userCell(r) },
                { key: 'granted', label: 'Origen', render: r => r.granted ? `<span class="wp-chip wp-chip--lime" title="${esc((r.grant && r.grant.by) || '')} · ${esc((r.grant && r.grant.note) || '')}"><i class="fas fa-hand"></i> Manual</span>` : '<span class="wp-chip wp-chip--sky"><i class="fas fa-globe"></i> Web</span>' },
                { key: 'value', label: 'Valor calificante', cls: 'wp-num', render: r => `${pavos(r.value)}${r.granted && n(r.value) < n(DATA.settings.min_value) ? ' <span class="wp-dim">(manual)</span>' : ''}` },
                { key: 'orders', label: 'Pedidos', cls: 'wp-num', render: r => nf.format(r.orders) },
                { key: 'spent_soles', label: 'Gastado', cls: 'wp-num', render: r => money(r.spent_soles) },
                { key: 'qualified_at', label: 'Calificó', render: r => `${fmtDay(r.qualified_at)} <span class="wp-dim">${esc(ago(r.qualified_at))}</span>` },
                { key: 'last_order_at', label: 'Última compra', render: r => fmtDay(r.last_order_at) },
                { key: 'created_at', label: 'Registro', render: r => fmtDay(r.created_at) },
                { key: '_a', label: '', sortable: false, render: r => r.granted ? `<button type="button" class="wp-btn wp-btn--sm wp-btn--danger" onclick="wpRevoke(${r.user_id}, '${esc(r.name).replace(/'/g, '&#39;')}')"><i class="fas fa-xmark"></i> Revocar</button>` : '' },
            ],
            csv: r => ({ usuario: r.game_username, nombre: r.name, user_id: r.user_id, origen: r.granted ? 'manual' : 'web', valor_pavos: r.value, pedidos: r.orders, gastado_soles: r.spent_soles, califico: r.qualified_at, ultima_compra: r.last_order_at, registro: r.created_at }),
        },
        consumed: {
            hint: 'Historial de pases consumidos: en qué sorteo entraron gratis y qué hicieron después (compras reales y sorteos posteriores al consumo).',
            cols: [
                { key: 'name', label: 'Usuario', render: r => userCell(r) },
                { key: 'used_at', label: 'Usó el pase', render: r => `${fmtDate(r.used_at)}${r.granted ? ' <span class="wp-chip wp-chip--lime">Manual</span>' : ''}` },
                { key: 'raffle_title', label: 'Sorteo', render: r => `<a href="sorteos_flash/sala_mando.php?id=${r.raffle_id}" style="color:var(--wp-violet);font-weight:700">#${r.raffle_id} ${esc(r.raffle_title)}</a> <span class="wp-dim">· ticket ${r.ticket_index}</span>${r.won ? ' <span class="wp-chip wp-chip--gold"><i class="fas fa-trophy"></i> Ganó</span>' : ''}${r.entry_status === 'expelled' ? ' <span class="wp-chip wp-chip--rose">Expulsado</span>' : ''}` },
                { key: 'qualifying_value', label: 'Compra inicial', cls: 'wp-num', render: r => pavos(r.qualifying_value) },
                { key: 'state', label: 'Estado actual', render: r => STATE_CHIP[r.state] || STATE_CHIP.inactive },
                { key: 'orders_after', label: 'Compras después', cls: 'wp-num', render: r => r.orders_after ? `<b>${r.orders_after}</b> · ${money(r.spent_after_soles)}` : '<span class="wp-dim">0</span>' },
                { key: 'days_to_rebuy', label: 'Días a recompra', cls: 'wp-num', render: r => r.days_to_rebuy == null ? '<span class="wp-dim">—</span>' : nf1.format(r.days_to_rebuy) },
                { key: 'entries_after', label: 'Sorteos después', cls: 'wp-num', render: r => r.entries_after ? `<b>${r.entries_after}</b> <span class="wp-dim">(${r.entries_after_std} con requisitos)</span>` : '<span class="wp-dim">0</span>' },
                { key: 'last_order_after', label: 'Última compra', render: r => fmtDay(r.last_order_after) },
            ],
            csv: r => ({ usuario: r.game_username, nombre: r.name, user_id: r.user_id, uso_el_pase: r.used_at, sorteo_id: r.raffle_id, sorteo: r.raffle_title, gano: r.won ? 1 : 0, compra_inicial_pavos: r.qualifying_value, estado: r.state, compras_despues: r.orders_after, gastado_despues_soles: r.spent_after_soles, dias_a_recompra: r.days_to_rebuy, sorteos_despues: r.entries_after, sorteos_con_requisitos_despues: r.entries_after_std, manual: r.granted ? 1 : 0 }),
        },
        almost: {
            hint: 'Clientes nuevos (sin sorteos) que ya compraron al menos la mitad del umbral. Un empujón ("te faltan X paVos para tu ticket gratis") los convierte en pases.',
            cols: [
                { key: 'name', label: 'Usuario', render: r => userCell(r) },
                { key: 'value', label: 'Lleva', cls: 'wp-num', render: r => pavos(r.value) },
                { key: 'missing', label: 'Le falta', cls: 'wp-num', render: r => `<b style="color:var(--wp-gold)">${pavos(r.missing)}</b>` },
                { key: 'orders', label: 'Pedidos', cls: 'wp-num', render: r => nf.format(r.orders) },
                { key: 'last_order_at', label: 'Última compra', render: r => `${fmtDay(r.last_order_at)} <span class="wp-dim">${esc(ago(r.last_order_at))}</span>` },
                { key: '_a', label: '', sortable: false, render: r => `<button type="button" class="wp-btn wp-btn--sm" onclick="wpGrantPrompt(${r.user_id}, '${esc(r.game_username).replace(/'/g, '&#39;')}', '${esc(r.name).replace(/'/g, '&#39;')}')"><i class="fas fa-hand-holding-heart"></i> Otorgar</button>` },
            ],
            csv: r => ({ usuario: r.game_username, nombre: r.name, user_id: r.user_id, lleva_pavos: r.value, falta_pavos: r.missing, pedidos: r.orders, ultima_compra: r.last_order_at }),
        },
        blocked: {
            hint: 'Cumplen el umbral pero ya participaron en algún sorteo antes de existir el pase (o tras usar una cuenta antigua): por regla no reciben el pase. Si fue una compra por chat legítima puedes otorgarlo a mano.',
            cols: [
                { key: 'name', label: 'Usuario', render: r => userCell(r) },
                { key: 'value', label: 'Valor calificante', cls: 'wp-num', render: r => pavos(r.value) },
                { key: 'orders', label: 'Pedidos', cls: 'wp-num', render: r => nf.format(r.orders) },
                { key: 'qualified_at', label: 'Cruzó el umbral', render: r => fmtDay(r.qualified_at) },
                { key: '_a', label: '', sortable: false, render: r => `<button type="button" class="wp-btn wp-btn--sm" onclick="wpGrantPrompt(${r.user_id}, '${esc(r.game_username).replace(/'/g, '&#39;')}', '${esc(r.name).replace(/'/g, '&#39;')}')"><i class="fas fa-hand-holding-heart"></i> Otorgar</button>` },
            ],
            csv: r => ({ usuario: r.game_username, nombre: r.name, user_id: r.user_id, valor_pavos: r.value, pedidos: r.orders, cruzo_umbral: r.qualified_at }),
        },
    };

    window.wpSetTab = function (tab) {
        TAB = tab; SORT = { key: null, dir: -1 };
        document.querySelectorAll('#wpTabs .wp-tab').forEach(b => b.classList.toggle('is-active', b.dataset.tab === tab));
        wpRenderTable();
    };
    window.wpSort = function (key) {
        SORT = SORT.key === key ? { key, dir: -SORT.dir } : { key, dir: -1 };
        wpRenderTable();
    };
    function rowsFor(tab) {
        if (!DATA) return [];
        return (DATA[tab] || []).slice();
    }
    window.wpRenderTable = function () {
        if (!DATA) return;
        const def = TABLES[TAB];
        const q = (document.getElementById('wpFilter').value || '').trim().toLowerCase();
        let rows = rowsFor(TAB);
        if (q) rows = rows.filter(r => [r.name, r.game_username, r.public_code, r.raffle_title, r.user_id].some(v => String(v ?? '').toLowerCase().includes(q)));
        if (SORT.key) {
            rows.sort((a, b) => {
                const va = a[SORT.key], vb = b[SORT.key];
                if (va == null && vb == null) return 0;
                if (va == null) return 1;
                if (vb == null) return -1;
                if (typeof va === 'number' || typeof va === 'boolean') return (Number(va) - Number(vb)) * SORT.dir;
                return String(va).localeCompare(String(vb), 'es') * SORT.dir;
            });
        }
        setText('wpTabHint', def.hint);
        const thead = document.querySelector('#wpTable thead');
        const tbody = document.querySelector('#wpTable tbody');
        thead.innerHTML = '<tr>' + def.cols.map(c => `<th class="${c.cls || ''} ${SORT.key === c.key ? 'is-sorted' : ''}" ${c.sortable === false ? '' : `onclick="wpSort('${c.key}')"`}>${esc(c.label)}</th>`).join('') + '</tr>';
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="${def.cols.length}" class="wp-table-empty">${q ? 'Sin coincidencias para el filtro.' : 'Todavía no hay usuarios en esta lista.'}</td></tr>`;
            return;
        }
        tbody.innerHTML = rows.map(r => '<tr>' + def.cols.map(c => `<td class="${c.cls || ''}">${c.render(r)}</td>`).join('') + '</tr>').join('');
    };
    window.wpExportCsv = function () {
        if (!DATA) return;
        const def = TABLES[TAB];
        const rows = rowsFor(TAB).map(def.csv);
        if (!rows.length) { Swal.fire({ icon: 'info', title: 'Nada que exportar', text: 'La tabla actual está vacía.' }); return; }
        const keys = Object.keys(rows[0]);
        const cell = v => { const s = v == null ? '' : String(v); return /[",;\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
        const csv = [keys.join(';')].concat(rows.map(r => keys.map(k => cell(r[k])).join(';'))).join('\n');
        const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `pase_bienvenida_${TAB}_${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(a.href);
    };

    /* ---------------- render dashboard ---------------- */
    function verdict(k) {
        const el = document.getElementById('wpVerdict');
        const used = n(k.used);
        let cls = 'na', icon = 'fa-circle-half-stroke', text = 'Aún no hay pases usados: sin veredicto';
        if (used >= 3) {
            const r = n(k.retention.any_rate);
            if (r >= 40) { cls = 'good'; icon = 'fa-arrow-trend-up'; text = `Funciona: ${r}% de los nuevos volvió`; }
            else if (r >= 20) { cls = 'warn'; icon = 'fa-scale-balanced'; text = `Regular: ${r}% de los nuevos volvió`; }
            else { cls = 'bad'; icon = 'fa-arrow-trend-down'; text = `Flojo: solo ${r}% de los nuevos volvió`; }
        } else if (used > 0) { text = `Muestra pequeña (${used} pases): espera más datos`; }
        el.className = `wp-verdict wp-verdict--${cls}`;
        el.innerHTML = `<i class="fas ${icon}"></i> ${esc(text)}`;
    }

    function render(d) {
        DATA = d;
        const k = d.kpis, r = k.retention;
        setText('wpIssued', nf.format(k.issued));
        setText('wpUsed', nf.format(k.used));
        setText('wpAvailable', nf.format(k.available));
        setText('wpGranted', nf.format(n(k.granted_active) + n(k.granted_used)));
        setText('wpUsageRate', `${nf1.format(k.usage_rate)}%`);
        const circ = 2 * Math.PI * 52;
        document.getElementById('wpRingFg').setAttribute('stroke-dasharray', `${(k.usage_rate / 100) * circ} ${circ}`);
        setText('wpHeroTitle', k.issued ? `${nf.format(k.used)} de ${nf.format(k.issued)} pases ya se jugaron` : 'Todavía nadie ha calificado');
        setText('wpHeroCopy', k.issued
            ? `${nf.format(k.available)} clientes nuevos tienen su pase esperando (${nf.format(k.blocked_prior)} más califican pero ya habían jugado antes). Umbral vigente: ${pavos(d.settings.min_value)} · modo ${d.settings.value_mode} · ${d.settings.scope === 'single_order' ? 'una sola compra' : 'acumulado'}${d.settings.enabled ? '' : ' · ⚠️ DESACTIVADO'}.`
            : 'Un pase se "emite" cuando un usuario cruza el umbral con compras reales (o lo asignas a mano) sin haber jugado nunca un sorteo.');
        verdict(k);

        setText('wpRetAnyRate', `${nf1.format(r.any_rate)}%`);
        setText('wpRetAny', nf.format(r.any)); setText('wpRetBase', nf.format(k.used));
        setText('wpRebuyRate', `${nf1.format(r.rebuy_rate)}%`);
        setText('wpRebuy', nf.format(r.rebuy)); setText('wpOrdersAfter', nf.format(r.orders_after)); setText('wpSpentAfter', money(r.spent_after_soles));
        setText('wpReplayStdRate', `${nf1.format(r.replay_std_rate)}%`);
        setText('wpReplayStd', nf.format(r.replay_std)); setText('wpReplay', nf.format(r.replay));
        document.getElementById('wpAvgQualifying').innerHTML = `${nf.format(d.curiosities.avg_qualifying_value || 0)}<small>paVos</small>`;
        setText('wpThreshold', pavos(d.settings.min_value));
        setText('wpAlmost', nf.format(k.almost));

        // Funnel
        const fMax = Math.max(1, ...d.funnel.map(f => n(f.value)));
        const fCols = { qualified: ['#38bdf8', '#34d399'], used: ['#a3e635', '#34d399'], active: ['#34d399', '#10b981'], rebuy: ['#fbbf24', '#f59e0b'], loyal: ['#a78bfa', '#7c3aed'] };
        document.getElementById('wpFunnel').innerHTML = d.funnel.map((f, i) => {
            const prev = i > 0 ? n(d.funnel[i - 1].value) : n(f.value);
            const pct = prev > 0 ? Math.round(n(f.value) * 100 / prev) : 0;
            const c = fCols[f.key] || fCols.used;
            return `<div class="wp-funnel-row"><span class="wp-fl">${esc(f.label)}</span><div class="wp-funnel-track"><div class="wp-funnel-fill" style="--c1:${c[0]};--c2:${c[1]};width:${Math.max(1, n(f.value) * 100 / fMax)}%;animation-delay:${i * 90}ms"></div></div><span class="wp-fv">${nf.format(f.value)}<small>${i === 0 ? '100%' : pct + '% del paso previo'}</small></span></div>`;
        }).join('');

        // Donut
        const seg = [
            { key: 'both', label: 'Compraron y jugaron', v: n(r.both), c: 'var(--wp-accent)' },
            { key: 'rebuy', label: 'Solo volvieron a comprar', v: n(r.rebuy) - n(r.both), c: 'var(--wp-gold)' },
            { key: 'replay', label: 'Solo volvieron a jugar', v: n(r.replay) - n(r.both), c: 'var(--wp-violet)' },
            { key: 'inactive', label: 'Sin actividad posterior', v: n(r.inactive), c: 'var(--wp-rose)' },
        ];
        const total = Math.max(1, seg.reduce((s, x) => s + Math.max(0, x.v), 0));
        let acc = 0; const stops = [];
        seg.forEach(x => { const p = Math.max(0, x.v) * 100 / total; stops.push(`${x.c} ${acc}% ${acc + p}%`); acc += p; });
        document.getElementById('wpDonut').style.background = k.used ? `conic-gradient(${stops.join(', ')})` : 'conic-gradient(var(--wp-border) 0 100%)';
        setText('wpDonutCenter', nf.format(k.used));
        document.getElementById('wpLegend').innerHTML = seg.map(x => `<div class="wp-legend-row"><i style="background:${x.c}"></i><span>${esc(x.label)}</span><b>${nf.format(Math.max(0, x.v))}</b><em>${k.used ? nf1.format(Math.max(0, x.v) * 100 / k.used) : 0}%</em></div>`).join('');

        // Barras mensuales
        const mMax = Math.max(1, ...d.months.map(m => Math.max(n(m.qualified), n(m.used), n(m.rebuy))));
        document.getElementById('wpBars').innerHTML = d.months.map((m, i) => {
            const [y, mo] = m.month.split('-');
            const h = v => Math.max(2, Math.round(n(v) * 100 / mMax));
            return `<div class="wp-bar-col"><div class="wp-bar-group">
                <div class="wp-bar wp-bar--q" style="height:${h(m.qualified)}%;animation-delay:${i * 40}ms" data-v="${m.qualified} calificaron"></div>
                <div class="wp-bar wp-bar--u" style="height:${h(m.used)}%;animation-delay:${i * 40 + 60}ms" data-v="${m.used} usaron"></div>
                <div class="wp-bar wp-bar--r" style="height:${h(m.rebuy)}%;animation-delay:${i * 40 + 120}ms" data-v="${m.rebuy} recompraron"></div>
            </div><span class="wp-bar-month">${MONTHS[Number(mo) - 1]} ${String(y).slice(2)}</span></div>`;
        }).join('');

        // Ranking sorteos
        const rk = d.by_raffle || [];
        const rMax = Math.max(1, ...rk.map(x => n(x.passes)));
        document.getElementById('wpRank').innerHTML = rk.length ? rk.map((x, i) => `
            <div class="wp-rank-row">
                <div><div class="wp-rt">#${x.raffle_id} ${esc(x.title)}<small>${esc(x.status || '')}</small></div>
                <div class="wp-rank-track"><i style="width:${n(x.rebuy) * 100 / rMax}%;background:var(--wp-gold);animation-delay:${i * 60}ms"></i><i style="width:${Math.max(0, n(x.replay) - n(x.rebuy)) * 100 / rMax}%;background:var(--wp-violet);animation-delay:${i * 60}ms"></i><i style="width:${Math.max(0, n(x.passes) - Math.max(n(x.rebuy), n(x.replay))) * 100 / rMax}%;background:var(--wp-border);animation-delay:${i * 60}ms"></i></div></div>
                <div class="wp-rv">${nf.format(x.passes)}<small>${x.rebuy} recompra · ${x.replay} rejugó</small></div>
            </div>`).join('') : '<div class="wp-empty">Ningún sorteo ha recibido pases todavía.</div>';

        // Curiosidades
        const c = d.curiosities;
        setText('wpFunFastest', c.fastest_rebuy ? `${nf1.format(c.fastest_rebuy.days)} días` : '—');
        setText('wpFunFastestSub', c.fastest_rebuy ? `${c.fastest_rebuy.name || '#' + c.fastest_rebuy.user_id} volvió a comprar tras el pase` : 'Recompra más rápida tras el pase');
        setText('wpFunBiggest', c.biggest_after ? money(c.biggest_after.soles) : '—');
        setText('wpFunBiggestSub', c.biggest_after ? `${c.biggest_after.name || '#' + c.biggest_after.user_id} · promedio ${money(c.avg_spent_after_soles)} por recomprador` : 'Quien más gastó después del pase');
        setText('wpFunDaysUse', r.avg_days_to_use == null ? '—' : `${nf1.format(r.avg_days_to_use)} días`);
        setText('wpFunWinners', k.used ? `${nf.format(k.winners)} de ${nf.format(k.used)}` : '—');

        // Tablas
        setText('wpCntAvailable', nf.format((d.available || []).length));
        setText('wpCntConsumed', nf.format((d.consumed || []).length));
        setText('wpCntAlmost', nf.format((d.almost || []).length));
        setText('wpCntBlocked', nf.format((d.blocked || []).length));
        wpRenderTable();

        const upd = d.generated_at ? new Date(d.generated_at) : new Date();
        setText('wpUpdated', `Actualizado ${upd.toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' })}`);
    }

    window.wpLoad = async function () {
        const dash = document.getElementById('wpDashboard');
        const loader = document.getElementById('wpLoading');
        const error = document.getElementById('wpError');
        const refresh = document.getElementById('wpRefresh');
        error.classList.remove('is-visible');
        refresh.disabled = true;
        dash.style.opacity = loader.classList.contains('is-hidden') ? '.6' : '1';
        try {
            const res = await fetch(`${API}?action=wt_dashboard`, { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            if (!res.ok || data.status !== 'success') throw new Error(data.message || 'No se pudo cargar el panel.');
            render(data);
            loader.classList.add('is-hidden');
        } catch (err) {
            loader.classList.add('is-hidden');
            error.querySelector('span').textContent = err.message || 'No se pudo cargar el panel.';
            error.classList.add('is-visible');
        } finally {
            dash.style.opacity = '1';
            dash.setAttribute('aria-busy', 'false');
            refresh.disabled = false;
        }
    };

    /* ---------------- asignación manual ---------------- */
    function welcomeChip(w, grant) {
        if (w.used) return `<span class="wp-chip wp-chip--muted"><i class="fas fa-check-double"></i> Ya usó el pase (sorteo #${w.used_in_raffle})</span>`;
        if (grant) return '<span class="wp-chip wp-chip--lime"><i class="fas fa-hand"></i> Pase manual vigente</span>';
        if (w.available) return '<span class="wp-chip wp-chip--ok"><i class="fas fa-ticket"></i> Ya califica por compras</span>';
        if (w.reason === 'prior_participation') return '<span class="wp-chip wp-chip--violet"><i class="fas fa-bolt"></i> Ya jugó sorteos antes</span>';
        if (w.reason === 'disabled') return '<span class="wp-chip wp-chip--rose"><i class="fas fa-power-off"></i> Pase desactivado</span>';
        return `<span class="wp-chip wp-chip--gold"><i class="fas fa-gauge-high"></i> Lleva ${nf.format(Math.round(w.value))} / ${nf.format(Math.round(w.threshold))} paVos</span>`;
    }
    window.wpSearch = async function (ev) {
        if (ev) ev.preventDefault();
        const q = document.getElementById('wpSearchInput').value.trim();
        const box = document.getElementById('wpSearchResults');
        if (!q) { box.innerHTML = ''; return; }
        box.innerHTML = '<div class="wp-loading"><span class="wp-spinner"></span> Buscando…</div>';
        try {
            const res = await fetch(`${API}?action=wt_user_search&q=${encodeURIComponent(q)}`, { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            if (!res.ok || data.status !== 'success') throw new Error(data.message || 'Error al buscar.');
            if (!data.users.length) { box.innerHTML = `<div class="wp-empty">No encontré usuarios con "<b>${esc(q)}</b>".</div>`; return; }
            box.innerHTML = data.users.map(u => {
                const w = u.welcome;
                const canGrant = !w.used && !u.grant;
                const action = u.grant
                    ? `<button type="button" class="wp-btn wp-btn--sm wp-btn--danger" onclick="wpRevoke(${u.user_id}, '${esc(u.name).replace(/'/g, '&#39;')}')"><i class="fas fa-xmark"></i> Revocar</button>`
                    : (canGrant ? `<button type="button" class="wp-btn wp-btn--sm wp-btn--primary" onclick="wpGrantPrompt(${u.user_id}, '${esc(u.game_username).replace(/'/g, '&#39;')}', '${esc(u.name).replace(/'/g, '&#39;')}')"><i class="fas fa-hand-holding-heart"></i> Otorgar pase</button>` : '');
                return `<div class="wp-user-row">
                    <img src="${esc(avatar(u.avatar))}" alt="">
                    <div><div class="wp-un">${esc(u.name)} <span class="wp-dim" style="font-weight:600">@${esc(u.game_username)}</span>${u.account_status === 'banned' ? ' <span class="wp-chip wp-chip--rose">Baneado</span>' : ''}</div>
                    <div class="wp-um">${welcomeChip(w, u.grant)} · #${u.user_id} · registro ${fmtDay(u.created_at)}${u.grant ? ` · otorgado por <b>${esc(u.grant.by || 'admin')}</b> ${esc(ago(u.grant.at))}${u.grant.note ? ' · "' + esc(u.grant.note) + '"' : ''}` : ''}</div></div>
                    <div class="wp-ua">${action}</div>
                </div>`;
            }).join('');
        } catch (err) {
            box.innerHTML = `<div class="wp-empty" style="color:var(--wp-rose)">${esc(err.message)}</div>`;
        }
    };
    async function post(action, body) {
        const fd = new FormData();
        Object.entries(body).forEach(([k, v]) => fd.append(k, v));
        const res = await fetch(`${API}?action=${action}`, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.status !== 'success') throw new Error(data.message || 'La operación falló.');
        return data;
    }
    window.wpGrantPrompt = async function (userId, username, name) {
        const { value: note, isConfirmed } = await Swal.fire({
            title: 'Otorgar Pase de Bienvenida',
            html: `<div style="text-align:left;font-size:.9rem;line-height:1.5">A <b>${esc(name)}</b> <span style="opacity:.7">(@${esc(username)})</span>.<br>Quedará calificado aunque no tenga compras web y podrá usarlo <b>una sola vez</b> en su próximo sorteo con requisitos.</div>`,
            input: 'text',
            inputPlaceholder: 'Motivo (ej. compró 2,000 paVos por WhatsApp el 12/03)',
            inputAttributes: { maxlength: 255 },
            showCancelButton: true,
            confirmButtonText: 'Otorgar pase',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#059669',
        });
        if (!isConfirmed) return;
        try {
            const data = await post('wt_grant', { user_id: userId, username, note: note || '' });
            await Swal.fire({ icon: data.warning ? 'warning' : 'success', title: data.message, text: data.warning || '', timer: data.warning ? undefined : 2200, showConfirmButton: !!data.warning });
            if (document.getElementById('wpSearchInput').value.trim()) wpSearch();
            wpLoad();
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'No se pudo otorgar', text: err.message });
        }
    };
    window.wpRevoke = async function (userId, name) {
        const ok = await Swal.fire({ icon: 'question', title: `¿Revocar el pase manual de ${name}?`, text: 'Solo se puede revocar si aún no lo consumió.', showCancelButton: true, confirmButtonText: 'Revocar', cancelButtonText: 'Cancelar', confirmButtonColor: '#e11d48' });
        if (!ok.isConfirmed) return;
        try {
            const data = await post('wt_revoke_grant', { user_id: userId });
            Swal.fire({ icon: 'success', title: data.message, timer: 1800, showConfirmButton: false });
            if (document.getElementById('wpSearchInput').value.trim()) wpSearch();
            wpLoad();
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'No se pudo revocar', text: err.message });
        }
    };

    let searchTimer = null;
    document.getElementById('wpSearchInput').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => wpSearch(), 350);
    });

    wpLoad();
})();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

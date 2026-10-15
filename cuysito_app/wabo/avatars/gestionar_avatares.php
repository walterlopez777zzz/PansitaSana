<?php
// /wabo/avatars/gestionar_avatares.php
// Panel de avatares: catálogo por creadores/packs, métricas "Visual Avatars",
// ficha detallada por avatar y asignación manual de avatar a usuarios.
require_once __DIR__ . '/../core/admin_header.php';
require_once __DIR__ . '/../core/db_connect.php';
?>

<style>
    @font-face {
        font-family: 'HeadingNowVariable';
        font-style: normal;
        font-stretch: normal;
        font-display: swap;
        src: url('/assets/fonts/HeadingNowVariable.woff2') format('woff2');
    }
    @keyframes avRise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes avBarIn { from { transform: scaleX(0); } to { transform: scaleX(1); } }
    @keyframes avPop { from { opacity: 0; transform: scale(.96) translateY(8px); } to { opacity: 1; transform: none; } }
    @keyframes avShimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

    #avApp {
        --av-surface: #0a0a0a;
        --av-surface-2: #101012;
        --av-surface-3: #16161a;
        --av-border: rgba(255,255,255,.09);
        --av-border-strong: rgba(255,255,255,.18);
        --av-ink: #f8fafc;
        --av-muted: #9ca3af;
        --av-subtle: #6b7280;
        --av-accent: #fbbf24;
        --av-accent-soft: rgba(251,191,36,.12);
        --av-accent-ink: #111111;
        --av-cyan: #22d3ee;
        --av-cyan-soft: rgba(34,211,238,.12);
        --av-purple: #a78bfa;
        --av-good: #22c55e;
        --av-danger: #f43f5e;
        --av-gold: #f5b301;
        --av-silver: #c8cfd8;
        --av-bronze: #cd7f32;
        --av-font-heavy: 'HeadingNowVariable', 'Barlow', 'Inter', sans-serif;
        --av-font-heavy-var: "wdth" 825, "wght" 800;
        --av-font-medium-var: "wdth" 700, "wght" 600;
        color: var(--av-ink);
        max-width: 1560px;
        margin: 0 auto;
    }
    [data-theme="light"] #avApp {
        --av-surface: #ffffff;
        --av-surface-2: #f8fafc;
        --av-surface-3: #f1f5f9;
        --av-border: rgba(15,23,42,.1);
        --av-border-strong: rgba(15,23,42,.22);
        --av-ink: #0b0b0b;
        --av-muted: #52514e;
        --av-subtle: #898781;
        --av-accent: #d97706;
        --av-accent-soft: rgba(217,119,6,.1);
        --av-accent-ink: #ffffff;
        --av-cyan: #0891b2;
        --av-cyan-soft: rgba(8,145,178,.09);
        --av-purple: #7c3aed;
    }
    [data-theme="glitch"] #avApp {
        --av-surface: rgba(13,13,13,.92);
        --av-surface-2: rgba(26,26,26,.94);
        --av-surface-3: rgba(34,34,34,.94);
        --av-border: rgba(255,0,255,.38);
        --av-border-strong: rgba(255,0,255,.6);
        --av-accent: #ff00ff;
        --av-accent-soft: rgba(255,0,255,.14);
        --av-accent-ink: #ffffff;
    }

    .av-heavy { font-family: var(--av-font-heavy); font-variation-settings: var(--av-font-heavy-var); letter-spacing: .01em; }
    .av-medium { font-family: var(--av-font-heavy); font-variation-settings: var(--av-font-medium-var); }
    .av-num { font-family: var(--av-font-heavy); font-variation-settings: var(--av-font-heavy-var); font-variant-numeric: tabular-nums; }

    /* --- Cabecera --- */
    .av-topbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 1.25rem; margin-bottom: 1.1rem; animation: avRise .35s ease-out both; flex-wrap: wrap; }
    .av-eyebrow { display: flex; align-items: center; gap: .55rem; color: var(--av-accent); font-size: .68rem; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; margin-bottom: .3rem; }
    .av-eyebrow::before { content: ''; width: 1.75rem; height: 1px; background: currentColor; }
    .av-title { margin: 0; font-size: clamp(1.7rem, 3vw, 2.6rem); line-height: 1; color: var(--av-ink); text-transform: uppercase; }
    .av-title em { font-style: normal; color: var(--av-accent); }
    .av-subtitle { color: var(--av-muted); font-size: .86rem; margin-top: .45rem; max-width: 760px; }
    .av-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; justify-content: flex-end; }

    .av-btn { display: inline-flex; align-items: center; justify-content: center; gap: .45rem; min-height: 2.45rem; padding: .55rem .85rem; border-radius: .7rem; border: 1px solid var(--av-border); background: var(--av-surface); color: var(--av-muted); font-size: .76rem; font-weight: 700; cursor: pointer; transition: border-color .18s, color .18s, transform .18s, background .18s; white-space: nowrap; }
    .av-btn:hover, .av-btn:focus-visible { border-color: var(--av-border-strong); color: var(--av-ink); transform: translateY(-1px); outline: none; }
    .av-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
    .av-btn--primary { background: var(--av-accent); border-color: var(--av-accent); color: var(--av-accent-ink); }
    .av-btn--primary:hover { color: var(--av-accent-ink); filter: brightness(1.07); }
    .av-btn--cyan { border-color: rgba(34,211,238,.35); background: var(--av-cyan-soft); color: var(--av-cyan); }
    .av-btn--warn { border-color: rgba(251,191,36,.4); background: var(--av-accent-soft); color: var(--av-accent); }
    .av-btn--danger { border-color: rgba(244,63,94,.4); background: rgba(244,63,94,.1); color: var(--av-danger); }
    .av-btn--sm { min-height: 2rem; padding: .35rem .65rem; font-size: .7rem; border-radius: .55rem; }
    .av-btn--icon { width: 2.1rem; height: 2.1rem; min-height: 0; padding: 0; border-radius: 999px; }
    .av-pill { display: inline-flex; align-items: center; gap: .3rem; font-size: .66rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding: .2rem .5rem; border-radius: 999px; border: 1px solid var(--av-border); color: var(--av-muted); background: var(--av-surface-2); }
    .av-pill--accent { color: var(--av-accent); border-color: rgba(251,191,36,.35); background: var(--av-accent-soft); }
    .av-pill--cyan { color: var(--av-cyan); border-color: rgba(34,211,238,.35); background: var(--av-cyan-soft); }
    .av-pill--good { color: var(--av-good); border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.1); }
    .av-pill--danger { color: var(--av-danger); border-color: rgba(244,63,94,.35); background: rgba(244,63,94,.1); }
    .av-pill--purple { color: var(--av-purple); border-color: rgba(167,139,250,.35); background: rgba(167,139,250,.12); }
    .av-count { font-size: .68rem; border-radius: 999px; padding: .05rem .45rem; background: rgba(255,255,255,.08); font-variant-numeric: tabular-nums; }
    [data-theme="light"] .av-count { background: rgba(15,23,42,.08); }

    /* --- Tabs --- */
    .av-tabs { display: flex; gap: .35rem; padding: .3rem; border-radius: .9rem; border: 1px solid var(--av-border); background: var(--av-surface); margin-bottom: 1.1rem; width: max-content; max-width: 100%; overflow-x: auto; animation: avRise .4s ease-out both; }
    .av-tab { display: inline-flex; align-items: center; gap: .5rem; padding: .55rem 1rem; border-radius: .65rem; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--av-muted); cursor: pointer; border: 1px solid transparent; background: transparent; transition: all .18s; white-space: nowrap; }
    .av-tab:hover { color: var(--av-ink); }
    .av-tab[aria-selected="true"] { background: var(--av-accent); color: var(--av-accent-ink); box-shadow: 0 8px 22px -10px var(--av-accent); }
    .av-tab[aria-selected="true"] .av-count { background: rgba(0,0,0,.18); }
    .av-panel { display: none; } .av-panel.is-on { display: block; animation: avRise .3s ease-out both; }

    /* --- Cards --- */
    .av-card { position: relative; background: var(--av-surface); border: 1px solid var(--av-border); border-radius: 1rem; overflow: hidden; animation: avRise .4s ease-out both; }
    .av-card--pad { padding: 1.1rem 1.2rem; }
    .av-card-label { color: var(--av-muted); font-size: .66rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; display: flex; align-items: center; gap: .45rem; }
    .av-card-label i { color: var(--av-accent); }
    .av-card-value { font-size: 1.9rem; line-height: 1; margin-top: .45rem; color: var(--av-ink); }
    .av-card-foot { color: var(--av-subtle); font-size: .72rem; margin-top: .45rem; }
    .av-stat { text-align: left; cursor: pointer; transition: border-color .18s, transform .18s; }
    .av-stat:hover { border-color: var(--av-border-strong); transform: translateY(-2px); }
    .av-stat.is-on { border-color: var(--av-accent); box-shadow: inset 0 0 0 1px var(--av-accent); }
    .av-hero { min-height: 170px; padding: 1.25rem 1.35rem; background: radial-gradient(110% 150% at 100% 0%, var(--av-accent-soft), transparent 55%), var(--av-surface); }
    .av-hero--cyan { background: radial-gradient(110% 150% at 100% 0%, var(--av-cyan-soft), transparent 55%), var(--av-surface); }
    .av-hero--purple { background: radial-gradient(110% 150% at 100% 0%, rgba(167,139,250,.14), transparent 55%), var(--av-surface); }
    .av-hero--danger { background: radial-gradient(110% 150% at 100% 0%, rgba(244,63,94,.14), transparent 55%), var(--av-surface); }
    .av-hero .av-card-value { font-size: 2.6rem; }
    .av-section-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: 1rem 1.2rem .6rem; }
    .av-section-title { font-size: 1.05rem; text-transform: uppercase; color: var(--av-ink); display: flex; align-items: center; gap: .55rem; }
    .av-section-title i { color: var(--av-accent); font-size: .9rem; }

    /* --- Barras --- */
    .av-bar { position: relative; height: .5rem; border-radius: 999px; background: rgba(255,255,255,.07); overflow: hidden; }
    [data-theme="light"] .av-bar { background: rgba(15,23,42,.08); }
    .av-bar > span { position: absolute; inset: 0 auto 0 0; border-radius: 999px; background: var(--av-accent); transform-origin: left; animation: avBarIn .7s cubic-bezier(.2,.7,.2,1) both; }
    .av-bar--lg { height: .8rem; }
    .av-bar--cyan > span { background: linear-gradient(90deg, var(--av-cyan), #67e8f9); }
    .av-bar--purple > span { background: linear-gradient(90deg, var(--av-purple), #c4b5fd); }
    .av-bar--good > span { background: linear-gradient(90deg, #16a34a, var(--av-good)); }

    /* --- Ranking --- */
    .av-rank { display: grid; grid-template-columns: 2.3rem 3rem 1fr auto; align-items: center; gap: .75rem; padding: .6rem .75rem; border-radius: .8rem; cursor: pointer; transition: background .18s, transform .18s; border: 1px solid transparent; }
    .av-rank:hover { background: var(--av-surface-2); border-color: var(--av-border); transform: translateX(2px); }
    .av-rank-pos { font-size: 1.15rem; text-align: center; color: var(--av-subtle); }
    .av-medal { width: 2.1rem; height: 2.1rem; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-size: .95rem; color: #1a1a1a; box-shadow: inset 0 -3px 0 rgba(0,0,0,.25), 0 6px 14px -6px rgba(0,0,0,.6); }
    .av-medal--1 { background: radial-gradient(circle at 30% 30%, #ffe680, var(--av-gold) 60%, #b8860b); }
    .av-medal--2 { background: radial-gradient(circle at 30% 30%, #ffffff, var(--av-silver) 60%, #8b96a5); }
    .av-medal--3 { background: radial-gradient(circle at 30% 30%, #f4b183, var(--av-bronze) 60%, #8a4b17); }
    .av-rank img { width: 3rem; height: 3rem; border-radius: 999px; object-fit: cover; border: 2px solid var(--av-border-strong); background: var(--av-surface-3); }
    .av-rank--1 img { border-color: var(--av-gold); box-shadow: 0 0 0 3px rgba(245,179,1,.25); }
    .av-rank--2 img { border-color: var(--av-silver); }
    .av-rank--3 img { border-color: var(--av-bronze); }
    .av-rank-name { font-weight: 700; font-size: .86rem; color: var(--av-ink); display: flex; align-items: center; gap: .45rem; min-width: 0; }
    .av-rank-name span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .av-rank-meta { font-size: .68rem; color: var(--av-subtle); margin-top: .15rem; }
    .av-rank-users { text-align: right; }
    .av-rank-users .av-num { font-size: 1.15rem; color: var(--av-ink); }
    .av-rank-users small { display: block; font-size: .66rem; color: var(--av-subtle); }

    /* --- Podio --- */
    .av-podium { display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem; align-items: end; padding: .5rem 1.2rem 0; }
    .av-podium-slot { text-align: center; cursor: pointer; border-radius: .9rem; padding: .85rem .5rem .7rem; background: var(--av-surface-2); border: 1px solid var(--av-border); transition: transform .18s; position: relative; }
    .av-podium-slot:hover { transform: translateY(-3px); }
    .av-podium-slot img { width: 4.25rem; height: 4.25rem; border-radius: 999px; object-fit: cover; margin: 0 auto .45rem; display: block; border: 3px solid var(--av-border-strong); background: var(--av-surface-3); }
    .av-podium-slot--1 { order: 2; padding-top: 1.25rem; background: linear-gradient(180deg, rgba(245,179,1,.14), var(--av-surface-2) 70%); border-color: rgba(245,179,1,.4); }
    .av-podium-slot--1 img { width: 5.5rem; height: 5.5rem; border-color: var(--av-gold); box-shadow: 0 0 0 4px rgba(245,179,1,.22), 0 14px 30px -12px rgba(245,179,1,.7); }
    .av-podium-slot--2 { order: 1; border-color: rgba(200,207,216,.35); } .av-podium-slot--2 img { border-color: var(--av-silver); }
    .av-podium-slot--3 { order: 3; border-color: rgba(205,127,50,.4); } .av-podium-slot--3 img { border-color: var(--av-bronze); }
    .av-podium-slot .av-medal { position: absolute; top: -.7rem; left: 50%; transform: translateX(-50%); width: 1.7rem; height: 1.7rem; font-size: .75rem; }
    .av-podium-name { font-weight: 700; font-size: .8rem; color: var(--av-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .av-podium-users { font-size: 1.35rem; color: var(--av-ink); line-height: 1.1; }
    .av-podium-users small { font-family: 'Inter', sans-serif; font-size: .66rem; color: var(--av-subtle); font-weight: 600; margin-left: .2rem; }

    /* --- Gráfico creadores --- */
    .av-chart-row { display: grid; grid-template-columns: minmax(7rem, 11rem) 1fr auto; align-items: center; gap: .8rem; padding: .45rem .2rem; cursor: pointer; border-radius: .6rem; }
    .av-chart-row:hover { background: var(--av-surface-2); }
    .av-chart-name { font-weight: 700; font-size: .8rem; color: var(--av-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: flex; align-items: center; gap: .45rem; }
    .av-chart-dot { width: .6rem; height: .6rem; border-radius: 999px; flex-shrink: 0; }
    .av-chart-track { position: relative; height: 1.45rem; border-radius: .5rem; background: rgba(255,255,255,.05); overflow: hidden; }
    [data-theme="light"] .av-chart-track { background: rgba(15,23,42,.06); }
    .av-chart-fill { position: absolute; inset: 0 auto 0 0; border-radius: .5rem; transform-origin: left; animation: avBarIn .8s cubic-bezier(.2,.7,.2,1) both; display: flex; align-items: center; justify-content: flex-end; padding-right: .45rem; font-size: .68rem; font-weight: 800; color: #0b0b0b; min-width: 0; }
    .av-chart-val { text-align: right; min-width: 5.5rem; }
    .av-chart-val .av-num { font-size: 1rem; color: var(--av-ink); }
    .av-chart-val small { display: block; font-size: .64rem; color: var(--av-subtle); }

    /* --- Filtros y grid --- */
    .av-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; margin-bottom: 1rem; }
    .av-chip { display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .75rem; border-radius: 999px; font-size: .74rem; font-weight: 700; border: 1px solid var(--av-border); background: var(--av-surface); color: var(--av-muted); cursor: pointer; transition: all .16s; white-space: nowrap; }
    .av-chip:hover { border-color: var(--av-border-strong); color: var(--av-ink); }
    .av-chip[aria-selected="true"] { background: var(--av-accent); border-color: var(--av-accent); color: var(--av-accent-ink); }
    .av-chip[aria-selected="true"] .av-count { background: rgba(0,0,0,.18); }
    .av-chip--danger[aria-selected="false"] { color: var(--av-danger); border-color: rgba(244,63,94,.4); }
    .av-input, .av-select { min-height: 2.45rem; padding: .5rem .8rem; border-radius: .7rem; border: 1px solid var(--av-border); background: var(--av-surface); color: var(--av-ink); font-size: .8rem; outline: none; transition: border-color .18s; }
    .av-input:focus, .av-select:focus { border-color: var(--av-accent); }
    .av-input::placeholder { color: var(--av-subtle); }
    .av-search { position: relative; flex: 1 1 14rem; min-width: 12rem; }
    .av-search i { position: absolute; left: .8rem; top: 50%; transform: translateY(-50%); color: var(--av-subtle); font-size: .8rem; pointer-events: none; }
    .av-search .av-input { width: 100%; padding-left: 2.2rem; }
    .av-search kbd { position: absolute; right: .6rem; top: 50%; transform: translateY(-50%); font-size: .6rem; color: var(--av-subtle); border: 1px solid var(--av-border); border-radius: .3rem; padding: .05rem .3rem; }

    .av-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: .85rem; }
    .av-group { grid-column: 1 / -1; display: flex; align-items: center; gap: .75rem; margin-top: .6rem; }
    .av-group:first-child { margin-top: 0; }
    .av-group h4 { margin: 0; font-size: 1rem; text-transform: uppercase; color: var(--av-ink); display: flex; align-items: center; gap: .5rem; }
    .av-group::after { content: ''; flex: 1; height: 1px; background: var(--av-border); }
    .av-tile { position: relative; background: var(--av-surface); border: 1px solid var(--av-border); border-radius: 1rem; overflow: hidden; transition: transform .2s, border-color .2s, box-shadow .2s; animation: avPop .3s ease-out both; }
    .av-tile:hover { transform: translateY(-3px); border-color: var(--av-border-strong); box-shadow: 0 18px 40px -22px rgba(0,0,0,.8); }
    .av-tile.is-missing { border-color: rgba(244,63,94,.55); }
    .av-tile.is-selected { border-color: var(--av-accent); box-shadow: 0 0 0 2px var(--av-accent); }
    .av-tile.is-flash { box-shadow: 0 0 0 3px var(--av-cyan); border-color: var(--av-cyan); }
    .av-tile-img { aspect-ratio: 1; padding: .9rem; background: radial-gradient(80% 80% at 50% 40%, rgba(255,255,255,.06), transparent 70%), var(--av-surface-2); cursor: zoom-in; position: relative; }
    .av-tile-img img { width: 100%; height: 100%; border-radius: 999px; object-fit: cover; border: 3px solid var(--av-border-strong); background: var(--av-surface-3); transition: transform .25s, filter .25s; }
    .av-tile.is-inactive .av-tile-img img { filter: grayscale(1) opacity(.5); }
    .av-tile:hover .av-tile-img img { transform: scale(1.04); }
    .av-tile-top { position: absolute; top: .55rem; left: .55rem; right: .55rem; display: flex; justify-content: space-between; align-items: center; gap: .3rem; pointer-events: none; }
    .av-tile-top > * { pointer-events: auto; }
    .av-check { width: 1.15rem; height: 1.15rem; border-radius: .35rem; border: 1px solid var(--av-border-strong); background: var(--av-surface); display: inline-flex; align-items: center; justify-content: center; color: var(--av-accent-ink); font-size: .65rem; cursor: pointer; opacity: 0; transition: opacity .15s, background .15s; }
    .av-tile:hover .av-check, .av-tile.is-selected .av-check, #avApp.has-selection .av-check { opacity: 1; }
    .av-tile.is-selected .av-check { background: var(--av-accent); border-color: var(--av-accent); }
    .av-status-dot { width: .55rem; height: .55rem; border-radius: 999px; background: var(--av-good); box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
    .av-status-dot.is-off { background: var(--av-danger); box-shadow: 0 0 0 3px rgba(244,63,94,.18); }
    .av-tile-rank { position: absolute; left: .55rem; bottom: .55rem; }
    .av-tile-missing { position: absolute; inset-inline: .6rem; bottom: .6rem; font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #fff; background: rgba(244,63,94,.92); border-radius: .4rem; padding: .2rem .4rem; text-align: center; }
    .av-tile-body { padding: .65rem .7rem .7rem; }
    .av-tile-name { width: 100%; background: transparent; border: 0; outline: none; text-align: center; font-weight: 700; font-size: .82rem; color: var(--av-ink); border-radius: .4rem; padding: .1rem .3rem; }
    .av-tile-name:focus { background: var(--av-surface-2); }
    .av-tile-creator { display: flex; justify-content: center; margin-top: .3rem; }
    .av-tile-creator button { max-width: 100%; display: inline-flex; align-items: center; gap: .3rem; font-size: .64rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; padding: .15rem .5rem; border-radius: 999px; border: 1px dashed var(--av-border-strong); color: var(--av-muted); background: transparent; cursor: pointer; }
    .av-tile-creator button:hover { border-style: solid; border-color: var(--av-accent); color: var(--av-accent); }
    .av-tile-creator button span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .av-tile-meta { display: flex; justify-content: space-between; align-items: center; margin-top: .45rem; font-size: .66rem; color: var(--av-subtle); }
    .av-tile-meta b { color: var(--av-purple); font-weight: 700; }
    .av-tile .av-bar { margin-top: .4rem; height: .3rem; }
    .av-tile-actions { display: grid; grid-template-columns: repeat(4, 1fr); border-top: 1px solid var(--av-border); }
    .av-tile-actions button { padding: .5rem 0; font-size: .72rem; color: var(--av-subtle); background: transparent; border: 0; cursor: pointer; transition: color .15s, background .15s; }
    .av-tile-actions button:hover { background: var(--av-surface-2); color: var(--av-ink); }
    .av-tile-actions button.is-danger:hover { color: var(--av-danger); }
    .av-tile-actions button.is-good:hover { color: var(--av-good); }
    .av-empty { grid-column: 1 / -1; text-align: center; padding: 3rem 1rem; color: var(--av-subtle); }
    .av-empty i { font-size: 2rem; display: block; margin-bottom: .6rem; color: var(--av-border-strong); }

    .av-bulk { position: sticky; bottom: 1rem; z-index: 30; display: none; align-items: center; gap: .6rem; padding: .6rem .8rem; margin-top: 1rem; border-radius: .9rem; background: var(--av-surface); border: 1px solid var(--av-accent); box-shadow: 0 20px 40px -20px rgba(0,0,0,.9); animation: avPop .25s ease-out both; }
    .av-bulk.is-on { display: flex; flex-wrap: wrap; }

    /* --- Tabla creadores --- */
    .av-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
    .av-table th { text-align: left; font-size: .64rem; letter-spacing: .1em; text-transform: uppercase; color: var(--av-subtle); padding: .55rem .9rem; border-bottom: 1px solid var(--av-border); }
    .av-table td { padding: .7rem .9rem; border-bottom: 1px solid var(--av-border); vertical-align: middle; }
    .av-table tr:last-child td { border-bottom: 0; }
    .av-table tr:hover td { background: var(--av-surface-2); }
    .av-stack { display: flex; }
    .av-stack img { width: 2rem; height: 2rem; border-radius: 999px; object-fit: cover; border: 2px solid var(--av-surface); margin-left: -.55rem; background: var(--av-surface-3); }
    .av-stack img:first-child { margin-left: 0; }
    .av-stack .av-stack-more { width: 2rem; height: 2rem; border-radius: 999px; margin-left: -.55rem; background: var(--av-surface-3); border: 2px solid var(--av-surface); display: inline-flex; align-items: center; justify-content: center; font-size: .62rem; font-weight: 800; color: var(--av-muted); }

    /* --- Modales --- */
    .av-modal { position: fixed; inset: 0; z-index: 60; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(0,0,0,.72); backdrop-filter: blur(6px); }
    .av-modal.is-on { display: flex; }
    .av-dialog { width: 100%; max-height: min(92vh, 960px); display: flex; flex-direction: column; background: var(--av-surface); border: 1px solid var(--av-border-strong); border-radius: 1.2rem; box-shadow: 0 40px 90px -30px rgba(0,0,0,.95); animation: avPop .25s ease-out both; color: var(--av-ink); overflow: hidden; }
    .av-dialog--sm { max-width: 30rem; } .av-dialog--md { max-width: 40rem; } .av-dialog--lg { max-width: 62rem; }
    .av-dialog-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--av-border); }
    .av-dialog-title { font-size: 1.15rem; text-transform: uppercase; display: flex; align-items: center; gap: .55rem; }
    .av-dialog-title i { color: var(--av-accent); font-size: .95rem; }
    .av-dialog-body { padding: 1.1rem 1.25rem; overflow-y: auto; flex: 1; }
    .av-dialog-foot { display: flex; align-items: center; justify-content: flex-end; gap: .5rem; padding: .85rem 1.25rem; border-top: 1px solid var(--av-border); background: var(--av-surface-2); flex-wrap: wrap; }
    .av-label { display: block; font-size: .66rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--av-muted); margin-bottom: .35rem; }
    .av-field { margin-bottom: .95rem; }
    .av-field .av-input, .av-field .av-select { width: 100%; }
    .av-help { font-size: .7rem; color: var(--av-subtle); margin-top: .3rem; }
    .av-error { display: none; font-size: .75rem; color: var(--av-danger); background: rgba(244,63,94,.1); border: 1px solid rgba(244,63,94,.3); border-radius: .6rem; padding: .5rem .7rem; margin-bottom: .8rem; }
    .av-error.is-on { display: block; }
    .av-hint { font-size: .74rem; color: var(--av-accent); background: var(--av-accent-soft); border: 1px solid rgba(251,191,36,.3); border-radius: .6rem; padding: .5rem .7rem; margin-bottom: .8rem; }
    .av-drop { display: block; cursor: pointer; border-radius: 1rem; border: 2px dashed var(--av-border-strong); padding: 1rem; text-align: center; transition: border-color .18s, background .18s; }
    .av-drop:hover, .av-drop.is-over { border-color: var(--av-accent); background: var(--av-accent-soft); }
    .av-preview-ring { width: 6.5rem; height: 6.5rem; border-radius: 999px; overflow: hidden; border: 3px solid var(--av-border-strong); background: var(--av-surface-2); }
    .av-preview-ring--sm { width: 3rem; height: 3rem; border-width: 2px; }
    .av-preview-ring img { width: 100%; height: 100%; object-fit: cover; }

    /* Detalle */
    .av-detail { display: grid; grid-template-columns: minmax(0, 17rem) 1fr; gap: 1.25rem; }
    @media (max-width: 720px) { .av-detail { grid-template-columns: 1fr; } }
    .av-detail-preview { position: relative; aspect-ratio: 1; border-radius: 1rem; overflow: hidden; background: radial-gradient(80% 80% at 50% 35%, rgba(255,255,255,.08), transparent 70%), var(--av-surface-2); border: 1px solid var(--av-border); display: flex; align-items: center; justify-content: center; padding: 1.1rem; }
    .av-detail-preview img { width: 100%; height: 100%; object-fit: cover; border-radius: 999px; border: 4px solid var(--av-border-strong); background: var(--av-surface-3); }
    .av-detail-preview.is-inactive img { filter: grayscale(1) opacity(.55); }
    .av-detail-preview .av-preview-ring { position: absolute; right: .7rem; bottom: .7rem; border-color: var(--av-surface); box-shadow: 0 8px 20px -10px rgba(0,0,0,.9); }
    .av-kv { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
    .av-kv div { background: var(--av-surface-2); border: 1px solid var(--av-border); border-radius: .75rem; padding: .55rem .7rem; min-width: 0; }
    .av-kv dt { font-size: .6rem; letter-spacing: .1em; text-transform: uppercase; color: var(--av-subtle); font-weight: 800; }
    .av-kv dd { margin: .15rem 0 0; font-size: .82rem; color: var(--av-ink); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .av-kv dd.is-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .74rem; font-weight: 500; }
    .av-users { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: .55rem; }
    .av-user { display: flex; align-items: center; gap: .65rem; padding: .55rem .65rem; border-radius: .8rem; background: var(--av-surface-2); border: 1px solid var(--av-border); min-width: 0; }
    .av-user img, .av-user .av-user-ph { width: 2.4rem; height: 2.4rem; border-radius: 999px; object-fit: cover; border: 2px solid var(--av-border-strong); background: var(--av-surface-3); flex-shrink: 0; }
    .av-user .av-user-ph { display: inline-flex; align-items: center; justify-content: center; color: var(--av-muted); font-weight: 800; font-size: .8rem; }
    .av-user-name { font-weight: 700; font-size: .8rem; color: var(--av-ink); display: flex; align-items: center; gap: .3rem; min-width: 0; }
    .av-user-name span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .av-user-sub { font-size: .66rem; color: var(--av-subtle); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .av-user-act { margin-left: auto; flex-shrink: 0; }
    .av-user.is-pick { cursor: pointer; transition: border-color .15s, background .15s; }
    .av-user.is-pick:hover, .av-user.is-active { border-color: var(--av-accent); background: var(--av-accent-soft); }

    /* Picker de avatares (asignación) */
    .av-picker { display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: .5rem; max-height: 17rem; overflow-y: auto; padding: .25rem; }
    .av-pick { border-radius: .8rem; padding: .4rem; text-align: center; border: 2px solid transparent; background: var(--av-surface-2); cursor: pointer; transition: border-color .15s, transform .15s; }
    .av-pick:hover { transform: translateY(-2px); border-color: var(--av-border-strong); }
    .av-pick.is-on { border-color: var(--av-accent); background: var(--av-accent-soft); }
    .av-pick img { width: 100%; aspect-ratio: 1; border-radius: 999px; object-fit: cover; border: 2px solid var(--av-border-strong); background: var(--av-surface-3); }
    .av-pick small { display: block; margin-top: .25rem; font-size: .62rem; color: var(--av-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .av-swap { display: flex; align-items: center; justify-content: center; gap: 1rem; padding: .8rem; border-radius: .9rem; background: var(--av-surface-2); border: 1px solid var(--av-border); }
    .av-swap i { color: var(--av-accent); font-size: 1.2rem; }
    .av-swap figure { margin: 0; text-align: center; }
    .av-swap figcaption { font-size: .62rem; color: var(--av-subtle); margin-top: .3rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 800; }

    /* Modo captura */
    #avVisual:fullscreen { background: var(--av-surface); padding: 2rem clamp(1rem, 4vw, 4rem); overflow-y: auto; }
    #avVisual:fullscreen .av-visual-toolbar .av-btn:not(.av-btn--exit) { display: none; }
    .av-visual-brand { display: none; align-items: center; gap: .6rem; color: var(--av-subtle); font-size: .72rem; letter-spacing: .12em; text-transform: uppercase; font-weight: 800; }
    #avVisual:fullscreen .av-visual-brand { display: flex; }

    .av-skeleton { height: 8rem; border-radius: 1rem; background: linear-gradient(90deg, var(--av-surface) 25%, var(--av-surface-3) 50%, var(--av-surface) 75%); background-size: 200% 100%; animation: avShimmer 1.4s linear infinite; }
    .av-toast { color: #fff; font-size: .8rem; padding: .6rem 1rem; border-radius: .7rem; box-shadow: 0 14px 30px -14px rgba(0,0,0,.9); display: flex; align-items: center; gap: .5rem; max-width: 22rem; animation: avPop .25s ease-out both; }
</style>

<div id="avApp">
    <!-- Cabecera -->
    <div class="av-topbar">
        <div>
            <div class="av-eyebrow">Banano Store · Identidad de la comunidad</div>
            <h1 class="av-title av-heavy">Gestión de <em>Avatares</em></h1>
            <p class="av-subtitle">Organiza el catálogo por creadores y packs, mide qué avatares adopta la comunidad y controla el avatar de cualquier usuario.</p>
            <p id="avUploadDir" class="text-[11px] mt-1 font-mono truncate max-w-[70vw]" style="color:var(--av-subtle)" title="Carpeta pública donde se guardan las subidas"></p>
        </div>
        <div class="av-actions">
            <button id="avRepairBtn" onclick="repairOrphans()" class="av-btn av-btn--warn hidden" title="Asigna un avatar válido a los usuarios cuyo avatar fue borrado, desactivado o no tiene archivo">
                <i class="fas fa-wrench"></i> Reparar <span id="avOrphanCount" class="av-count">0</span>
            </button>
            <button onclick="loadAvatars()" class="av-btn av-btn--icon" title="Recargar"><i class="fas fa-rotate"></i></button>
            <button onclick="openAssignModal()" class="av-btn av-btn--cyan"><i class="fas fa-user-pen"></i> Cambiar avatar a usuario</button>
            <button onclick="openUploadModal()" class="av-btn av-btn--primary"><i class="fas fa-cloud-upload-alt"></i> Subir avatar</button>
        </div>
    </div>

    <!-- Tabs -->
    <div class="av-tabs" role="tablist">
        <button class="av-tab av-medium" role="tab" data-tab="manage" aria-selected="true" onclick="setTab('manage')"><i class="fas fa-grip"></i> Gestión <span id="avTabCountManage" class="av-count">0</span></button>
        <button class="av-tab av-medium" role="tab" data-tab="visual" aria-selected="false" onclick="setTab('visual')"><i class="fas fa-chart-simple"></i> Visual Avatars</button>
        <button class="av-tab av-medium" role="tab" data-tab="creators" aria-selected="false" onclick="setTab('creators')"><i class="fas fa-layer-group"></i> Creadores &amp; Packs <span id="avTabCountCreators" class="av-count">0</span></button>
    </div>

    <div id="loader" class="hidden text-center py-8"><i class="fas fa-spinner fa-spin text-3xl" style="color:var(--av-accent)"></i></div>

    <!-- ===================== GESTIÓN ===================== -->
    <section id="avPanelManage" class="av-panel is-on" role="tabpanel">
        <div id="avStats" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5"></div>

        <div class="av-toolbar">
            <div id="avFilters" class="flex flex-wrap gap-2" role="tablist"></div>
            <select id="avCreatorFilter" class="av-select" onchange="state.creator = this.value; renderFilters(); renderGrid();" title="Filtrar por creador o pack"></select>
            <select id="avSort" class="av-select" onchange="state.sort = this.value; renderGrid();" title="Ordenar">
                <option value="recent">Más recientes</option>
                <option value="users">Más usados</option>
                <option value="name">Nombre A→Z</option>
                <option value="creator">Creador A→Z</option>
                <option value="id">ID ascendente</option>
            </select>
            <button id="avGroupBtn" class="av-chip" aria-selected="false" onclick="state.group = !state.group; renderGrid();" title="Agrupar la cuadrícula por creador/pack"><i class="fas fa-layer-group"></i> Agrupar</button>
            <div class="av-search">
                <i class="fas fa-search"></i>
                <input id="avSearch" type="search" class="av-input" placeholder="Buscar por ID, nombre, archivo, creador o estado…" oninput="renderGrid()">
                <kbd>/</kbd>
            </div>
        </div>

        <div id="avatarGrid" class="av-grid"></div>

        <div id="avBulk" class="av-bulk">
            <span class="av-pill av-pill--accent"><i class="fas fa-check-double"></i> <span id="avBulkCount">0</span> seleccionados</span>
            <button class="av-btn av-btn--sm" onclick="selectVisible()"><i class="fas fa-object-group"></i> Seleccionar visibles</button>
            <button class="av-btn av-btn--sm av-btn--primary" onclick="openCreatorPrompt([...state.selected])"><i class="fas fa-tag"></i> Asignar creador / pack</button>
            <button class="av-btn av-btn--sm av-btn--cyan" onclick="bulkToggle(1)"><i class="fas fa-eye"></i> Activar</button>
            <button class="av-btn av-btn--sm" onclick="bulkToggle(0)"><i class="fas fa-eye-slash"></i> Desactivar</button>
            <button class="av-btn av-btn--sm" onclick="clearSelection()" style="margin-left:auto"><i class="fas fa-times"></i> Cancelar</button>
        </div>
    </section>

    <!-- ===================== VISUAL AVATARS ===================== -->
    <section id="avPanelVisual" class="av-panel" role="tabpanel">
        <div id="avVisual">
            <div class="av-visual-toolbar flex items-center justify-between gap-3 mb-4 flex-wrap">
                <div>
                    <div class="av-eyebrow">Visual Avatars · Métricas de la comunidad</div>
                    <h2 class="av-title av-heavy" style="font-size:clamp(1.4rem,2.4vw,2rem)">¿Qué avatares <em>usa</em> la comunidad?</h2>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="av-visual-brand"><i class="fas fa-user-astronaut"></i> Banano Store · <span id="avVisualDate"></span></span>
                    <button class="av-btn av-btn--cyan" onclick="toggleCapture()"><i class="fas fa-camera"></i> Modo captura</button>
                    <button class="av-btn av-btn--exit hidden" onclick="toggleCapture()"><i class="fas fa-compress"></i> Salir</button>
                </div>
            </div>
            <div id="avVisualBody"><div class="av-skeleton"></div></div>
        </div>
    </section>

    <!-- ===================== CREADORES ===================== -->
    <section id="avPanelCreators" class="av-panel" role="tabpanel">
        <div class="av-card">
            <div class="av-section-head">
                <h3 class="av-section-title av-heavy"><i class="fas fa-layer-group"></i> Creadores &amp; Packs</h3>
                <div class="flex items-center gap-2">
                    <span class="av-help">Los avatares sin creador usan <b id="avDefaultCreator">General</b>.</span>
                    <button class="av-btn av-btn--sm av-btn--primary" onclick="openCreatorPrompt([], 'new')"><i class="fas fa-plus"></i> Nuevo pack</button>
                </div>
            </div>
            <div class="overflow-x-auto"><table class="av-table"><thead><tr>
                <th>Creador / Pack</th><th>Avatares</th><th>Activos</th><th>Usuarios</th><th style="min-width:14rem">Adopción</th><th></th>
            </tr></thead><tbody id="avCreatorsTable"></tbody></table></div>
        </div>
    </section>
</div>

<!-- Drop zone (arrastrar sobre la página) -->
<div id="avDropHint" class="hidden fixed inset-0 z-40 pointer-events-none flex items-center justify-center" style="background:rgba(251,191,36,.12);border:4px dashed #fbbf24">
    <div class="rounded-2xl px-8 py-6 shadow-2xl text-center" style="background:#0a0a0a;color:#f8fafc;border:1px solid rgba(255,255,255,.15)">
        <i class="fas fa-cloud-upload-alt text-4xl" style="color:#fbbf24"></i>
        <p class="mt-2 font-bold">Suelta la imagen para subir un avatar</p>
    </div>
</div>

<!-- Modal Subir / Reemplazar -->
<div id="avModal" class="av-modal" onclick="if(event.target===this) closeUploadModal()">
    <div class="av-dialog av-dialog--sm" id="avApp-upload">
        <div class="av-dialog-head">
            <h3 id="avModalTitle" class="av-dialog-title av-heavy"><i class="fas fa-cloud-upload-alt"></i> Subir avatar</h3>
            <button onclick="closeUploadModal()" class="av-btn av-btn--icon"><i class="fas fa-times"></i></button>
        </div>
        <form id="avForm" onsubmit="submitUpload(event)">
            <div class="av-dialog-body">
                <p id="avModalHint" class="av-hint hidden"></p>
                <label id="avDrop" for="avFile" class="av-drop">
                    <div id="avPreviewWrap" class="hidden flex-col items-center gap-3">
                        <div class="flex items-center gap-4">
                            <div class="av-preview-ring"><img id="avPreview" alt=""></div>
                            <div class="av-preview-ring av-preview-ring--sm" title="Tamaño en el menú"><img id="avPreviewSmall" alt=""></div>
                        </div>
                        <p id="avFileMeta" class="text-[11px] font-mono" style="color:var(--av-muted)"></p>
                        <p class="text-xs" style="color:var(--av-accent)">Clic para elegir otra imagen</p>
                    </div>
                    <div id="avDropEmpty" class="py-3">
                        <i class="fas fa-image text-3xl" style="color:var(--av-border-strong)"></i>
                        <p class="mt-2 text-sm" style="color:var(--av-muted)">Arrastra una imagen o <span style="color:var(--av-accent)" class="font-semibold">elige un archivo</span></p>
                        <p class="text-[11px] mt-1" style="color:var(--av-subtle)">PNG, JPG o WEBP · máx. 2 MB · ideal cuadrada (≥ 256×256)</p>
                    </div>
                    <input type="file" id="avFile" class="hidden" accept="image/png,image/jpeg,image/webp" onchange="onFilePicked(this.files)">
                </label>

                <div id="avNameField" class="av-field mt-4">
                    <label class="av-label" for="avName">Nombre descriptivo</label>
                    <input id="avName" type="text" maxlength="60" placeholder="Ej. Banana Pirata" class="av-input">
                </div>
                <div id="avCreatorField" class="av-field">
                    <label class="av-label" for="avCreator">Creador / Pack</label>
                    <input id="avCreator" type="text" maxlength="80" list="avCreatorList" placeholder="General" class="av-input" autocomplete="off">
                    <datalist id="avCreatorList"></datalist>
                    <div id="avCreatorChips" class="flex flex-wrap gap-1.5 mt-2"></div>
                    <p class="av-help">Escribe un nombre nuevo para crear un pack o elige uno existente. Vacío = <b>General</b>.</p>
                </div>
                <label id="avActiveField" class="flex items-center gap-2 text-sm select-none" style="color:var(--av-muted)">
                    <input id="avActive" type="checkbox" checked class="w-4 h-4" style="accent-color:var(--av-accent)"> Publicar activo (visible para los usuarios)
                </label>
                <p id="avFormError" class="av-error mt-3"></p>
            </div>
            <div class="av-dialog-foot">
                <button type="button" onclick="closeUploadModal()" class="av-btn">Cancelar</button>
                <button id="avSubmit" type="submit" disabled class="av-btn av-btn--primary"><i class="fas fa-save"></i> <span id="avSubmitText">Guardar</span></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Creador (asignar / renombrar / nuevo) -->
<div id="avCreatorModal" class="av-modal" onclick="if(event.target===this) closeCreatorPrompt()">
    <div class="av-dialog av-dialog--sm">
        <div class="av-dialog-head">
            <h3 id="avCreatorModalTitle" class="av-dialog-title av-heavy"><i class="fas fa-tag"></i> Creador / Pack</h3>
            <button onclick="closeCreatorPrompt()" class="av-btn av-btn--icon"><i class="fas fa-times"></i></button>
        </div>
        <form onsubmit="submitCreatorPrompt(event)">
            <div class="av-dialog-body">
                <p id="avCreatorModalHint" class="av-hint"></p>
                <div class="av-field">
                    <label class="av-label" for="avCreatorInput">Nombre del creador o pack</label>
                    <input id="avCreatorInput" type="text" maxlength="80" list="avCreatorList2" class="av-input" autocomplete="off" placeholder="General">
                    <datalist id="avCreatorList2"></datalist>
                    <div id="avCreatorChips2" class="flex flex-wrap gap-1.5 mt-2"></div>
                </div>
                <p id="avCreatorError" class="av-error"></p>
            </div>
            <div class="av-dialog-foot">
                <button type="button" onclick="closeCreatorPrompt()" class="av-btn">Cancelar</button>
                <button type="submit" class="av-btn av-btn--primary"><i class="fas fa-check"></i> <span id="avCreatorSubmitText">Aplicar</span></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Detalle de avatar -->
<div id="avDetailModal" class="av-modal" onclick="if(event.target===this) closeDetail()">
    <div class="av-dialog av-dialog--lg">
        <div class="av-dialog-head">
            <h3 class="av-dialog-title av-heavy"><i class="fas fa-id-badge"></i> <span id="avDetailTitle">Avatar</span></h3>
            <div class="flex items-center gap-2">
                <button class="av-btn av-btn--icon" onclick="detailNav(-1)" title="Anterior (←)"><i class="fas fa-chevron-left"></i></button>
                <button class="av-btn av-btn--icon" onclick="detailNav(1)" title="Siguiente (→)"><i class="fas fa-chevron-right"></i></button>
                <button onclick="closeDetail()" class="av-btn av-btn--icon"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="av-dialog-body" id="avDetailBody"></div>
        <div class="av-dialog-foot" id="avDetailFoot"></div>
    </div>
</div>

<!-- Modal Cambiar avatar a usuario -->
<div id="avAssignModal" class="av-modal" onclick="if(event.target===this) closeAssignModal()">
    <div class="av-dialog av-dialog--md">
        <div class="av-dialog-head">
            <h3 class="av-dialog-title av-heavy"><i class="fas fa-user-pen"></i> Cambiar avatar a un usuario</h3>
            <button onclick="closeAssignModal()" class="av-btn av-btn--icon"><i class="fas fa-times"></i></button>
        </div>
        <div class="av-dialog-body">
            <div class="av-field">
                <label class="av-label" for="avUserSearch">1 · Busca al usuario</label>
                <div class="av-search" style="flex:none">
                    <i class="fas fa-search"></i>
                    <input id="avUserSearch" type="search" class="av-input" placeholder="Nombre público, usuario del juego, código público, email o ID…" autocomplete="off" oninput="onUserSearchInput()">
                </div>
                <div id="avUserResults" class="mt-2 grid gap-1.5"></div>
            </div>
            <div id="avAssignStep2" class="hidden">
                <div class="av-field">
                    <label class="av-label">2 · Elige el avatar nuevo</label>
                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                        <div class="av-search"><i class="fas fa-search"></i><input id="avPickSearch" type="search" class="av-input" placeholder="Filtrar avatares por nombre, creador o ID…" oninput="renderPicker()"></div>
                        <button class="av-btn av-btn--sm av-btn--cyan" onclick="pickRandom()"><i class="fas fa-dice"></i> Aleatorio</button>
                    </div>
                    <div id="avPicker" class="av-picker"></div>
                </div>
                <div id="avSwap" class="av-swap"></div>
            </div>
            <p id="avAssignError" class="av-error mt-3"></p>
        </div>
        <div class="av-dialog-foot">
            <button type="button" onclick="closeAssignModal()" class="av-btn">Cerrar</button>
            <button id="avAssignSubmit" class="av-btn av-btn--primary" disabled onclick="submitAssign()"><i class="fas fa-check"></i> Asignar avatar</button>
        </div>
    </div>
</div>

<!-- Toasts -->
<div id="avToasts" class="fixed bottom-4 right-4 z-[80] flex flex-col gap-2"></div>

<script>
    const API = 'avatars/api_avatares.php';
    const MAX_BYTES = 2 * 1024 * 1024;
    const FILTERS = [
        { key: 'all',      label: 'Todos',            icon: 'fa-th' },
        { key: 'active',   label: 'Activos',          icon: 'fa-eye' },
        { key: 'inactive', label: 'Inactivos',        icon: 'fa-eye-slash' },
        { key: 'unused',   label: 'Sin usuarios',     icon: 'fa-user-slash' },
        { key: 'missing',  label: 'Archivo faltante', icon: 'fa-exclamation-triangle', danger: true },
    ];
    const PALETTE = ['#fbbf24', '#22d3ee', '#a78bfa', '#34d399', '#f472b6', '#fb923c', '#60a5fa', '#e879f9', '#facc15', '#2dd4bf'];
    const state = {
        avatars: [], stats: null, creators: [], analytics: null, defaultCreator: 'General',
        tab: 'manage', filter: 'all', creator: '', sort: 'recent', group: false, pendingCreator: '',
        selected: new Set(),
        modal: { mode: 'upload', id: null, file: null },
        creatorPrompt: { mode: 'set', ids: [], from: '' },
        detail: { id: null, users: [], loading: false },
        assign: { user: null, results: [], avatarId: null, timer: null, busy: false },
    };

    document.addEventListener('DOMContentLoaded', () => {
        const hashTab = (location.hash || '').replace('#', '');
        if (['manage', 'visual', 'creators'].includes(hashTab)) setTab(hashTab, false);
        loadAvatars();
        setupPageDrop();
        document.addEventListener('keydown', onKey);
        document.addEventListener('fullscreenchange', () => {
            const on = document.fullscreenElement === document.getElementById('avVisual');
            document.querySelector('#avVisual .av-btn--exit').classList.toggle('hidden', !on);
        });
    });

    // ---------- Utilidades ----------
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const attr = s => esc(s).replace(/`/g, '&#96;');
    const fmtBytes = n => n == null ? '—' : n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(0)} KB` : `${(n / 1048576).toFixed(2)} MB`;
    const plural = (n, s, p) => `${n} ${n === 1 ? s : p}`;
    const fmtNum = n => Number(n || 0).toLocaleString('es-ES');
    const fmtPct = n => `${Number(n || 0).toLocaleString('es-ES', { maximumFractionDigits: 1 })}%`;
    const fmtDate = (s, withTime = true) => {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return String(s);
        return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric', ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}) });
    };
    const fallbackImg = () => window.__bananoAvatarFallbackSrc || '';
    const imgSrc = av => av && av.url ? av.url : fallbackImg();
    const byId = id => state.avatars.find(a => a.id == id);
    const userLabel = u => u.public_display_name || u.game_username || (u.public_code ? `#${u.public_code}` : `Usuario ${u.id}`);
    const initials = u => (userLabel(u) || '?').trim().slice(0, 2).toUpperCase();
    const creatorColor = name => {
        const idx = state.creators.findIndex(c => c.name === name);
        return PALETTE[(idx >= 0 ? idx : 0) % PALETTE.length];
    };

    async function api(formData) {
        const res = await fetch(API, { method: 'POST', body: formData });
        const json = await res.json().catch(() => ({ status: 'error', message: 'Respuesta inválida del servidor' }));
        if (json.status !== 'success') throw new Error(json.message || 'Error');
        return json;
    }
    const fd = (action, params = {}) => {
        const f = new FormData(); f.append('action', action);
        Object.entries(params).forEach(([k, v]) => f.append(k, v));
        return f;
    };

    function applyPayload(json) {
        if (Array.isArray(json.data)) state.avatars = json.data;
        if (json.stats) state.stats = json.stats;
        if (Array.isArray(json.creators)) state.creators = json.creators;
        if (json.analytics) state.analytics = json.analytics;
        if (json.default_creator) state.defaultCreator = json.default_creator;
        if (json.upload_dir) document.getElementById('avUploadDir').textContent = `Carpeta de subida: ${json.upload_dir}`;
        document.getElementById('avDefaultCreator').textContent = state.defaultCreator;
        for (const id of [...state.selected]) if (!byId(id)) state.selected.delete(id);
        renderAll();
    }
    function renderAll() {
        renderStats(); renderFilters(); renderCreatorFilter(); renderGrid(); renderVisual(); renderCreatorsTable(); renderCreatorLists();
        document.getElementById('avTabCountManage').textContent = state.avatars.length;
        document.getElementById('avTabCountCreators').textContent = state.creators.length;
        if (state.detail.id != null && document.getElementById('avDetailModal').classList.contains('is-on')) renderDetail();
    }

    async function loadAvatars() {
        document.getElementById('loader').classList.remove('hidden');
        try { applyPayload(await api(fd('list'))); }
        catch (e) { console.error(e); toast('Error al cargar avatares: ' + e.message, 'error'); }
        finally { document.getElementById('loader').classList.add('hidden'); }
    }

    function onKey(e) {
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
        if (e.key === 'Escape') {
            if (isOpen('avCreatorModal')) return closeCreatorPrompt();
            if (isOpen('avAssignModal')) return closeAssignModal();
            if (isOpen('avDetailModal')) return closeDetail();
            if (isOpen('avModal')) return closeUploadModal();
            if (state.selected.size) return clearSelection();
        }
        if (isOpen('avDetailModal') && !typing) {
            if (e.key === 'ArrowLeft') detailNav(-1);
            if (e.key === 'ArrowRight') detailNav(1);
        }
        if (e.key === '/' && !typing && !anyModalOpen()) { e.preventDefault(); setTab('manage'); document.getElementById('avSearch').focus(); }
    }
    const isOpen = id => document.getElementById(id).classList.contains('is-on');
    const anyModalOpen = () => ['avModal', 'avCreatorModal', 'avDetailModal', 'avAssignModal'].some(isOpen);

    // ---------- Tabs ----------
    function setTab(tab, pushHash = true) {
        state.tab = tab;
        document.querySelectorAll('.av-tab').forEach(b => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
        document.getElementById('avPanelManage').classList.toggle('is-on', tab === 'manage');
        document.getElementById('avPanelVisual').classList.toggle('is-on', tab === 'visual');
        document.getElementById('avPanelCreators').classList.toggle('is-on', tab === 'creators');
        if (pushHash) history.replaceState(null, '', tab === 'manage' ? location.pathname : `#${tab}`);
    }

    // ---------- Stats / filtros ----------
    function renderStats() {
        const s = state.stats; if (!s) return;
        const card = (label, value, icon, foot = '', onclick = '', on = false, cls = '') => `
            <button type="button" ${onclick ? `onclick="${onclick}"` : ''} class="av-card av-card--pad av-stat ${on ? 'is-on' : ''} ${cls}">
                <p class="av-card-label"><i class="fas ${icon}"></i> ${label}</p>
                <p class="av-card-value av-num">${fmtNum(value)}</p>
                ${foot ? `<p class="av-card-foot">${foot}</p>` : ''}
            </button>`;
        const f = state.filter;
        document.getElementById('avStats').innerHTML =
            card('Avatares', s.total, 'fa-images', `${plural(s.creators, 'creador / pack', 'creadores / packs')}`, "setFilter('all')", f === 'all') +
            card('Activos', s.active, 'fa-eye', `${fmtPct(s.total ? s.active * 100 / s.total : 0)} del catálogo`, "setFilter('active')", f === 'active') +
            card('Inactivos', s.inactive, 'fa-eye-slash', 'Ocultos para los usuarios', "setFilter('inactive')", f === 'inactive') +
            card('Sin usuarios', s.orphan_avatars, 'fa-user-slash', 'Avatares que nadie usa', "setFilter('unused')", f === 'unused') +
            card('Archivo faltante', s.missing, 'fa-exclamation-triangle', s.missing ? 'Revisar cuanto antes' : 'Todo en orden', "setFilter('missing')", f === 'missing') +
            card('Usuarios con avatar', s.users_with_avatar, 'fa-users', `${fmtPct(s.users_with_avatar_pct)} de ${fmtNum(s.users_total)} · ${fmtNum(s.orphan_users)} sin avatar válido`, "setTab('visual')");

        document.getElementById('avRepairBtn').classList.toggle('hidden', !s.orphan_users);
        document.getElementById('avOrphanCount').textContent = s.orphan_users;
    }

    function matchesFilter(a, key) {
        if (key === 'active') return a.is_active == 1;
        if (key === 'inactive') return a.is_active != 1;
        if (key === 'missing') return a.file_exists === false;
        if (key === 'unused') return !Number(a.users_count);
        return true;
    }
    const matchesCreator = a => !state.creator || a.creator === state.creator;
    function matchesSearch(a, q) {
        if (!q) return true;
        const status = a.is_active == 1 ? 'activo' : 'inactivo';
        const hay = [String(a.id), a.name, a.filename, a.creator, status, a.file_exists === false ? 'faltante' : ''].join(' ').toLowerCase();
        return q.split(/\s+/).every(t => t === `#${a.id}` || hay.includes(t));
    }
    function renderFilters() {
        document.getElementById('avFilters').innerHTML = FILTERS.map(f => {
            const on = state.filter === f.key;
            const n = state.avatars.filter(a => matchesFilter(a, f.key) && matchesCreator(a)).length;
            if (f.key === 'missing' && n === 0 && !on) return '';
            return `<button type="button" role="tab" aria-selected="${on}" onclick="setFilter('${f.key}')" class="av-chip ${f.danger ? 'av-chip--danger' : ''}">
                <i class="fas ${f.icon} text-xs"></i> ${f.label} <span class="av-count">${n}</span></button>`;
        }).join('');
        document.getElementById('avGroupBtn').setAttribute('aria-selected', String(state.group));
    }
    function renderCreatorFilter() {
        const sel = document.getElementById('avCreatorFilter');
        const opts = [`<option value="">Todos los creadores (${state.creators.length})</option>`]
            .concat(state.creators.map(c => `<option value="${attr(c.name)}" ${state.creator === c.name ? 'selected' : ''}>${esc(c.name)} · ${c.avatars} av · ${fmtNum(c.users)} us</option>`));
        sel.innerHTML = opts.join('');
        if (state.creator && !state.creators.some(c => c.name === state.creator)) { state.creator = ''; sel.value = ''; }
    }
    function setFilter(key) { state.filter = key; setTab('manage'); renderStats(); renderFilters(); renderGrid(); }
    function setCreatorFilter(name) { state.creator = name || ''; setTab('manage'); renderFilters(); renderCreatorFilter(); renderGrid(); }

    // ---------- Grid ----------
    function visibleList() {
        const q = document.getElementById('avSearch').value.trim().toLowerCase();
        const list = state.avatars.filter(a => matchesFilter(a, state.filter) && matchesCreator(a) && matchesSearch(a, q));
        const cmp = {
            recent:  (a, b) => (b.created_at || '').localeCompare(a.created_at || '') || b.id - a.id,
            users:   (a, b) => (b.users_count - a.users_count) || b.id - a.id,
            name:    (a, b) => (a.name || '').localeCompare(b.name || '', 'es') || a.id - b.id,
            creator: (a, b) => (a.creator || '').localeCompare(b.creator || '', 'es') || (b.users_count - a.users_count),
            id:      (a, b) => a.id - b.id,
        }[state.sort] || ((a, b) => b.id - a.id);
        return list.sort(cmp);
    }
    function renderGrid() {
        const grid = document.getElementById('avatarGrid');
        const list = visibleList();
        if (state.avatars.length === 0) { grid.innerHTML = `<div class="av-empty"><i class="fas fa-images"></i>No hay avatares. ¡Sube el primero!</div>`; return renderBulk(); }
        if (list.length === 0) { grid.innerHTML = `<div class="av-empty"><i class="fas fa-filter"></i>Ningún avatar coincide con este filtro.</div>`; return renderBulk(); }

        const rankMap = new Map((state.analytics?.top || []).map(t => [t.id, t.rank]));
        if (!state.group) { grid.innerHTML = list.map(a => tileHtml(a, rankMap.get(a.id))).join(''); return renderBulk(); }

        const groups = new Map();
        for (const a of list) { if (!groups.has(a.creator)) groups.set(a.creator, []); groups.get(a.creator).push(a); }
        const order = [...groups.keys()].sort((x, y) => {
            const cx = state.creators.find(c => c.name === x), cy = state.creators.find(c => c.name === y);
            return ((cy?.users || 0) - (cx?.users || 0)) || x.localeCompare(y, 'es');
        });
        grid.innerHTML = order.map(name => {
            const items = groups.get(name);
            const users = items.reduce((n, a) => n + Number(a.users_count || 0), 0);
            return `<div class="av-group">
                <h4 class="av-heavy"><span class="av-chart-dot" style="background:${creatorColor(name)}"></span>${esc(name)}</h4>
                <span class="av-pill">${plural(items.length, 'avatar', 'avatares')}</span>
                <span class="av-pill av-pill--purple"><i class="fas fa-users"></i> ${fmtNum(users)}</span>
                <button class="av-btn av-btn--sm" onclick="openCreatorPrompt([], 'rename', '${attr(name)}')" title="Renombrar o fusionar este pack"><i class="fas fa-pen"></i></button>
            </div>` + items.map(a => tileHtml(a, rankMap.get(a.id))).join('');
        }).join('');
        renderBulk();
    }

    function tileHtml(av, rank) {
        const active = av.is_active == 1;
        const missing = av.file_exists === false;
        const users = Number(av.users_count) || 0;
        const selected = state.selected.has(av.id);
        const maxUsers = Math.max(1, ...state.avatars.map(a => Number(a.users_count) || 0));
        const medal = rank && rank <= 3 ? `<span class="av-medal av-medal--${rank}" style="width:1.6rem;height:1.6rem;font-size:.7rem" title="Top ${rank} más usado"><i class="fas fa-crown"></i></span>`
                    : rank ? `<span class="av-pill av-pill--accent" title="Top ${rank} más usado">#${rank}</span>` : '';
        return `
            <div class="av-tile ${active ? '' : 'is-inactive'} ${missing ? 'is-missing' : ''} ${selected ? 'is-selected' : ''}" data-id="${av.id}">
                <div class="av-tile-img" onclick="openDetail(${av.id})" title="Ver ficha completa">
                    <img src="${attr(imgSrc(av))}" data-avatar-img alt="${attr(av.name)}" loading="lazy">
                    <div class="av-tile-top">
                        <span class="av-check" onclick="event.stopPropagation(); toggleSelect(${av.id})" title="Seleccionar"><i class="fas fa-check"></i></span>
                        <span class="av-status-dot ${active ? '' : 'is-off'}" title="${active ? 'Activo' : 'Inactivo'}"></span>
                    </div>
                    ${medal ? `<div class="av-tile-rank">${medal}</div>` : ''}
                    ${missing ? `<div class="av-tile-missing"><i class="fas fa-exclamation-triangle"></i> Archivo faltante</div>` : ''}
                </div>
                <div class="av-tile-body">
                    <input type="text" class="av-tile-name" value="${attr(av.name)}" maxlength="60" onchange="updateName(${av.id}, this.value)" onclick="event.stopPropagation()" title="Clic para editar nombre">
                    <div class="av-tile-creator"><button onclick="openCreatorPrompt([${av.id}])" title="Cambiar creador / pack"><span class="av-chart-dot" style="background:${creatorColor(av.creator)}"></span><span>${esc(av.creator)}</span></button></div>
                    <div class="av-tile-meta">
                        <span title="Usuarios que tienen este avatar"><i class="fas fa-users"></i> <b>${fmtNum(users)}</b> · ${fmtPct(av.users_pct)}</span>
                        <span title="${attr(av.filename)}${av.file_size ? ' · ' + fmtBytes(av.file_size) : ''}${av.file_dir === 'legacy' ? ' · carpeta antigua' : ''}">ID ${av.id}${av.file_dir === 'legacy' ? ' <i class="fas fa-box-archive"></i>' : ''}</span>
                    </div>
                    <div class="av-bar av-bar--purple"><span style="width:${Math.max(users ? 4 : 0, users * 100 / maxUsers)}%"></span></div>
                </div>
                <div class="av-tile-actions">
                    <button onclick="toggleStatus(${av.id}, ${active ? 1 : 0})" class="is-good" title="${active ? 'Desactivar' : 'Activar'}"><i class="fas ${active ? 'fa-eye' : 'fa-eye-slash'}"></i></button>
                    <button onclick="openReplaceModal(${av.id})" title="Reemplazar imagen (los usuarios lo conservan)"><i class="fas fa-sync-alt"></i></button>
                    <button onclick="openDetail(${av.id})" title="Ficha y usuarios"><i class="fas fa-id-badge"></i></button>
                    <button onclick="deleteAvatar(${av.id})" class="is-danger" title="Eliminar"><i class="fas fa-trash"></i></button>
                </div>
            </div>`;
    }

    // ---------- Selección múltiple ----------
    function toggleSelect(id) { state.selected.has(id) ? state.selected.delete(id) : state.selected.add(id); renderGrid(); }
    function selectVisible() { visibleList().forEach(a => state.selected.add(a.id)); renderGrid(); }
    function clearSelection() { state.selected.clear(); renderGrid(); }
    function renderBulk() {
        const n = state.selected.size;
        document.getElementById('avBulk').classList.toggle('is-on', n > 0);
        document.getElementById('avBulkCount').textContent = n;
        document.getElementById('avApp').classList.toggle('has-selection', n > 0 || !!state.pendingCreator);
    }
    async function bulkToggle(status) {
        const ids = [...state.selected].filter(id => { const a = byId(id); return a && (a.is_active == 1) !== (status === 1); });
        if (!ids.length) return toast('Nada que cambiar en la selección', 'info');
        const affected = ids.reduce((n, id) => n + Number(byId(id)?.users_count || 0), 0);
        if (status === 0 && affected && !confirm(`Desactivar ${plural(ids.length, 'avatar', 'avatares')}: ${plural(affected, 'usuario recibirá', 'usuarios recibirán')} otro avatar al azar. ¿Continuar?`)) return;
        try {
            let json = null;
            for (const id of ids) json = await api(fd('toggle_status', { id, status }));
            if (json) applyPayload(json);
            toast(`${plural(ids.length, 'avatar', 'avatares')} ${status ? 'activados' : 'desactivados'}`, 'success');
        } catch (e) { toast(e.message, 'error'); loadAvatars(); }
    }

    // ---------- Creadores: listas, prompt, tabla ----------
    function renderCreatorLists() {
        const opts = state.creators.map(c => `<option value="${attr(c.name)}"></option>`).join('');
        document.getElementById('avCreatorList').innerHTML = opts;
        document.getElementById('avCreatorList2').innerHTML = opts;
        const chips = target => state.creators.slice(0, 8).map(c =>
            `<button type="button" class="av-chip" style="padding:.2rem .55rem;font-size:.66rem" onclick="document.getElementById('${target}').value='${attr(c.name)}'"><span class="av-chart-dot" style="background:${creatorColor(c.name)}"></span>${esc(c.name)}</button>`).join('');
        document.getElementById('avCreatorChips').innerHTML = chips('avCreator');
        document.getElementById('avCreatorChips2').innerHTML = chips('avCreatorInput');
    }

    function openCreatorPrompt(ids = [], mode = 'set', from = '') {
        state.creatorPrompt = { mode, ids: ids.map(Number), from };
        const title = document.getElementById('avCreatorModalTitle');
        const hint = document.getElementById('avCreatorModalHint');
        const input = document.getElementById('avCreatorInput');
        const submit = document.getElementById('avCreatorSubmitText');
        document.getElementById('avCreatorError').classList.remove('is-on');
        if (mode === 'rename') {
            const c = state.creators.find(x => x.name === from);
            title.innerHTML = `<i class="fas fa-pen"></i> Renombrar pack`;
            hint.innerHTML = `Se renombrará <b>${esc(from)}</b> (${plural(c?.avatars || 0, 'avatar', 'avatares')}). Si escribes el nombre de un pack existente, se <b>fusionan</b>.`;
            input.value = from; submit.textContent = 'Renombrar';
        } else if (mode === 'new') {
            title.innerHTML = `<i class="fas fa-plus"></i> Nuevo pack`;
            hint.innerHTML = `Los packs se crean al asignarlos: escribe el nombre y luego elige a qué avatares aplicarlo. Puedes seleccionar varios en la cuadrícula.`;
            input.value = ''; submit.textContent = 'Crear y seleccionar avatares';
        } else {
            const names = ids.map(id => byId(id)?.name).filter(Boolean);
            title.innerHTML = `<i class="fas fa-tag"></i> Creador / Pack`;
            hint.innerHTML = ids.length === 1
                ? `Cambiar el creador de <b>${esc(names[0] || '#' + ids[0])}</b> (actual: ${esc(byId(ids[0])?.creator || '')}).`
                : `Se aplicará a <b>${plural(ids.length, 'avatar', 'avatares')}</b> seleccionados.`;
            input.value = ids.length === 1 ? (byId(ids[0])?.creator || '') : (state.pendingCreator || ''); submit.textContent = 'Aplicar';
        }
        document.getElementById('avCreatorModal').classList.add('is-on');
        setTimeout(() => { input.focus(); input.select(); }, 40);
    }
    function closeCreatorPrompt() { document.getElementById('avCreatorModal').classList.remove('is-on'); }
    async function submitCreatorPrompt(ev) {
        ev.preventDefault();
        const { mode, ids, from } = state.creatorPrompt;
        const value = document.getElementById('avCreatorInput').value.trim().replace(/\s+/g, ' ');
        const err = document.getElementById('avCreatorError');
        const fail = m => { err.textContent = m; err.classList.add('is-on'); };
        try {
            if (mode === 'rename') {
                if (!value) return fail('Escribe el nombre nuevo');
                if (value === from) return fail('El nombre es igual al actual');
                const exists = state.creators.some(c => c.name === value);
                if (exists && !confirm(`"${value}" ya existe. ¿Fusionar "${from}" dentro de "${value}"?`)) return;
                const json = await api(fd('rename_creator', { from, to: value }));
                closeCreatorPrompt(); applyPayload(json); toast(json.message, 'success');
                if (state.creator === from) setCreatorFilter(value);
            } else if (mode === 'new') {
                if (!value) return fail('Escribe el nombre del pack');
                closeCreatorPrompt(); setTab('manage');
                state.pendingCreator = value;
                toast(`Selecciona los avatares para "${value}" y pulsa "Asignar creador / pack"`, 'info', 5000);
                document.getElementById('avApp').classList.add('has-selection');
            } else {
                if (!ids.length) return fail('Selecciona al menos un avatar en la cuadrícula');
                const json = await api(fd('set_creator', { ids: ids.join(','), creator: value }));
                state.pendingCreator = '';
                closeCreatorPrompt(); clearSelection(); applyPayload(json); toast(json.message, 'success');
            }
        } catch (e) { fail(e.message); }
    }

    function renderCreatorsTable() {
        const tb = document.getElementById('avCreatorsTable');
        if (!state.creators.length) { tb.innerHTML = `<tr><td colspan="6" class="av-empty">Sin creadores todavía.</td></tr>`; return; }
        const max = Math.max(1, ...state.creators.map(c => c.users));
        tb.innerHTML = state.creators.map((c, i) => {
            const avs = state.avatars.filter(a => a.creator === c.name).sort((a, b) => b.users_count - a.users_count);
            const stack = avs.slice(0, 5).map(a => `<img src="${attr(imgSrc(a))}" data-avatar-img alt="" title="${attr(a.name)}" onclick="openDetail(${a.id})" style="cursor:pointer">`).join('')
                        + (avs.length > 5 ? `<span class="av-stack-more">+${avs.length - 5}</span>` : '');
            return `<tr>
                <td><div class="flex items-center gap-3"><span class="av-chart-dot" style="background:${PALETTE[i % PALETTE.length]};width:.8rem;height:.8rem"></span>
                    <div><div class="font-bold" style="color:var(--av-ink)">${esc(c.name)} ${c.is_default ? '<span class="av-pill" style="margin-left:.3rem">por defecto</span>' : ''} ${i === 0 && c.users ? '<span class="av-pill av-pill--accent" style="margin-left:.3rem"><i class="fas fa-crown"></i> Top</span>' : ''}</div>
                    <div class="av-stack mt-1">${stack}</div></div></div></td>
                <td class="av-num" style="font-size:1rem">${c.avatars}</td>
                <td><span class="av-pill ${c.active === c.avatars ? 'av-pill--good' : ''}">${c.active}/${c.avatars}</span></td>
                <td class="av-num" style="font-size:1rem">${fmtNum(c.users)}</td>
                <td><div class="flex items-center gap-2"><div class="av-bar av-bar--lg flex-1"><span style="width:${c.users * 100 / max}%;background:${PALETTE[i % PALETTE.length]}"></span></div><span class="text-xs font-bold" style="min-width:3.5rem;text-align:right">${fmtPct(c.share_pct)}</span></div>
                    <div class="av-help">${fmtPct(c.pct)} de todos los usuarios</div></td>
                <td><div class="flex items-center gap-1 justify-end">
                    <button class="av-btn av-btn--sm" onclick="setCreatorFilter('${attr(c.name)}')" title="Ver avatares"><i class="fas fa-filter"></i></button>
                    <button class="av-btn av-btn--sm" onclick="openCreatorPrompt([], 'rename', '${attr(c.name)}')" title="Renombrar / fusionar"><i class="fas fa-pen"></i></button>
                </div></td></tr>`;
        }).join('');
    }

    // ---------- Visual Avatars ----------
    function renderVisual() {
        const a = state.analytics, s = state.stats; if (!a || !s) return;
        document.getElementById('avVisualDate').textContent = new Date().toLocaleDateString('es-ES', { day: '2-digit', month: 'long', year: 'numeric' });
        const top = a.top || [];
        const tc = a.top_creator;
        const hero = (label, value, foot, icon, cls = '', bar = null, onclick = '') => `
            <div class="av-card av-hero ${cls} ${onclick ? 'av-stat' : ''}" ${onclick ? `onclick="${onclick}"` : ''}>
                <p class="av-card-label"><i class="fas ${icon}"></i> ${label}</p>
                <p class="av-card-value av-num">${value}</p>
                ${bar !== null ? `<div class="av-bar av-bar--lg mt-3 ${cls.includes('cyan') ? 'av-bar--cyan' : cls.includes('purple') ? 'av-bar--purple' : cls.includes('danger') ? '' : 'av-bar--good'}"><span style="width:${Math.min(100, bar)}%"></span></div>` : ''}
                <p class="av-card-foot">${foot}</p>
            </div>`;

        const podium = top.slice(0, 3).map(t => `
            <div class="av-podium-slot av-podium-slot--${t.rank}" onclick="openDetail(${t.id})">
                <span class="av-medal av-medal--${t.rank}">${t.rank}</span>
                <img src="${attr(t.url || fallbackImg())}" data-avatar-img alt="${attr(t.name)}">
                <div class="av-podium-name">${esc(t.name)}</div>
                <div class="av-podium-users av-num">${fmtNum(t.users)}<small>${fmtPct(t.share_pct)}</small></div>
                <div class="av-rank-meta">${esc(t.creator)}</div>
            </div>`).join('');

        const maxTop = Math.max(1, ...top.map(t => t.users));
        const ranking = top.map(t => `
            <div class="av-rank av-rank--${t.rank}" onclick="openDetail(${t.id})">
                <div class="av-rank-pos av-num">${t.rank <= 3 ? `<span class="av-medal av-medal--${t.rank}">${t.rank}</span>` : t.rank}</div>
                <img src="${attr(t.url || fallbackImg())}" data-avatar-img alt="">
                <div style="min-width:0">
                    <div class="av-rank-name"><span>${esc(t.name)}</span>${t.is_active != 1 ? '<span class="av-pill av-pill--danger">inactivo</span>' : ''}</div>
                    <div class="av-rank-meta">${esc(t.creator)} · ID ${t.id}</div>
                    <div class="av-bar mt-1"><span style="width:${t.users * 100 / maxTop}%"></span></div>
                </div>
                <div class="av-rank-users"><span class="av-num">${fmtNum(t.users)}</span><small>${fmtPct(t.share_pct)} · ${fmtPct(t.pct)} total</small></div>
            </div>`).join('');

        const creators = (a.creators || []).filter(c => c.users > 0 || c.avatars > 0);
        const maxC = Math.max(1, ...creators.map(c => c.users));
        const chart = creators.slice(0, 12).map((c, i) => `
            <div class="av-chart-row" onclick="setCreatorFilter('${attr(c.name)}')" title="Ver avatares de ${attr(c.name)}">
                <div class="av-chart-name"><span class="av-chart-dot" style="background:${PALETTE[i % PALETTE.length]}"></span><span style="overflow:hidden;text-overflow:ellipsis">${esc(c.name)}</span></div>
                <div class="av-chart-track"><div class="av-chart-fill" style="width:${Math.max(c.users ? 3 : 0, c.users * 100 / maxC)}%;background:${PALETTE[i % PALETTE.length]}">${c.users * 100 / maxC > 18 ? fmtPct(c.share_pct) : ''}</div></div>
                <div class="av-chart-val"><span class="av-num">${fmtNum(c.users)}</span><small>${plural(c.avatars, 'avatar', 'avatares')} · ${fmtPct(c.share_pct)}</small></div>
            </div>`).join('');

        const orphans = a.orphans || [];
        const orphanStrip = orphans.slice(0, 24).map(o => `
            <button class="av-pick ${o.is_active != 1 ? 'opacity-60' : ''}" onclick="openDetail(${o.id})" title="${attr(o.name)} · ${attr(o.creator)}${o.is_active != 1 ? ' · inactivo' : ''}">
                <img src="${attr(o.url || fallbackImg())}" data-avatar-img alt=""><small>${esc(o.name)}</small></button>`).join('');

        const recent = (a.recent || []).map(r => `
            <div class="av-rank" onclick="openDetail(${r.id})" style="grid-template-columns:3rem 1fr auto">
                <img src="${attr(r.url || fallbackImg())}" data-avatar-img alt="">
                <div style="min-width:0"><div class="av-rank-name"><span>${esc(r.name)}</span></div><div class="av-rank-meta">${esc(r.creator)} · ${fmtDate(r.created_at, false)}</div></div>
                <div class="av-rank-users"><span class="av-num">${fmtNum(r.users)}</span><small>usuarios</small></div>
            </div>`).join('');

        document.getElementById('avVisualBody').innerHTML = `
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-4">
                ${hero('Usuarios con avatar', fmtNum(a.users_with_avatar), `${fmtPct(a.users_with_avatar_pct)} de ${fmtNum(a.users_total)} usuarios · <b>${fmtNum(a.users_without_avatar)}</b> sin avatar válido`, 'fa-users', 'av-hero--good', a.users_with_avatar_pct)}
                ${hero('Avatares activos en uso', `${fmtNum(a.active_in_use)}<span style="font-size:1.1rem;color:var(--av-subtle)">/${fmtNum(a.active_total)}</span>`, `${fmtPct(a.coverage_pct)} del catálogo activo tiene al menos un usuario · media ${fmtNum(a.avg_users_per_avatar)} us/avatar`, 'fa-bolt', 'av-hero--cyan', a.coverage_pct)}
                ${tc ? hero('Creador con más adopción', `<span style="font-size:1.6rem;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(tc.name)}</span>`, `${fmtNum(tc.users)} usuarios · ${fmtPct(tc.share_pct)} de los usuarios con avatar · ${plural(tc.avatars, 'avatar', 'avatares')}`, 'fa-crown', 'av-hero--purple', tc.share_pct, `setTab('creators')`) : hero('Creador con más adopción', '—', 'Sin datos', 'fa-crown', 'av-hero--purple')}
                ${hero('Avatares huérfanos', fmtNum(orphans.length), orphans.length ? `Nadie los usa todavía · ${fmtNum(orphans.filter(o => o.is_active == 1).length)} activos` : 'Todos los avatares tienen al menos un usuario', 'fa-ghost', 'av-hero--danger', null, `setFilter('unused')`)}
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-5 gap-3 mb-3">
                <div class="av-card xl:col-span-3">
                    <div class="av-section-head"><h3 class="av-section-title av-heavy"><i class="fas fa-trophy"></i> Top 10 avatares más usados</h3><span class="av-pill av-pill--accent">${fmtNum(a.users_with_avatar)} usuarios con avatar</span></div>
                    ${top.length ? `<div class="av-podium">${podium}</div><div class="p-3 pt-2">${ranking}</div>` : `<div class="av-empty"><i class="fas fa-trophy"></i>Aún no hay usuarios con avatar.</div>`}
                </div>
                <div class="av-card xl:col-span-2">
                    <div class="av-section-head"><h3 class="av-section-title av-heavy"><i class="fas fa-layer-group"></i> Adopción por creador / pack</h3>${a.half_users_avatars ? `<span class="av-pill av-pill--cyan" title="Cuántos avatares acumulan la mitad de los usuarios">50% en ${a.half_users_avatars} av.</span>` : ''}</div>
                    <div class="px-3 pb-3">${chart || `<div class="av-empty">Sin datos</div>`}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-5 gap-3">
                <div class="av-card xl:col-span-3">
                    <div class="av-section-head"><h3 class="av-section-title av-heavy"><i class="fas fa-ghost"></i> Avatares huérfanos (0 usuarios)</h3><button class="av-btn av-btn--sm" onclick="setFilter('unused')">Ver todos <i class="fas fa-arrow-right"></i></button></div>
                    <div class="px-3 pb-3">${orphans.length ? `<div class="av-picker" style="max-height:none">${orphanStrip}</div>${orphans.length > 24 ? `<p class="av-help mt-2">y ${orphans.length - 24} más…</p>` : ''}` : `<div class="av-empty"><i class="fas fa-check-circle"></i>Todos los avatares tienen al menos un usuario.</div>`}</div>
                </div>
                <div class="av-card xl:col-span-2">
                    <div class="av-section-head"><h3 class="av-section-title av-heavy"><i class="fas fa-clock"></i> Últimas subidas</h3></div>
                    <div class="px-3 pb-3">${recent || `<div class="av-empty">Sin datos</div>`}</div>
                </div>
            </div>`;
    }
    function toggleCapture() {
        const el = document.getElementById('avVisual');
        if (document.fullscreenElement) document.exitFullscreen();
        else if (el.requestFullscreen) el.requestFullscreen().catch(() => toast('Tu navegador no permite pantalla completa aquí', 'error'));
    }

    // ---------- Detalle ----------
    async function openDetail(id) {
        state.detail = { id: Number(id), users: [], loading: true, truncated: false };
        document.getElementById('avDetailModal').classList.add('is-on');
        renderDetail();
        try {
            const json = await api(fd('detail', { id }));
            if (state.detail.id !== Number(id)) return;
            const idx = state.avatars.findIndex(a => a.id == id);
            if (idx >= 0) state.avatars[idx] = { ...state.avatars[idx], ...json.avatar };
            state.detail.users = json.users || []; state.detail.truncated = !!json.users_truncated; state.detail.extra = json.avatar; state.detail.loading = false;
            renderDetail();
        } catch (e) { toast(e.message, 'error'); closeDetail(); }
    }
    function closeDetail() { document.getElementById('avDetailModal').classList.remove('is-on'); state.detail.id = null; }
    function detailNav(step) {
        const list = state.tab === 'manage' ? visibleList() : state.avatars;
        if (!list.length) return;
        const i = list.findIndex(a => a.id === state.detail.id);
        const next = list[(i + step + list.length) % list.length];
        if (next) openDetail(next.id);
    }
    function renderDetail() {
        const av = byId(state.detail.id); const body = document.getElementById('avDetailBody'); const foot = document.getElementById('avDetailFoot');
        if (!av) { body.innerHTML = `<div class="av-empty"><i class="fas fa-spinner fa-spin"></i>Cargando…</div>`; foot.innerHTML = ''; return; }
        const x = state.detail.extra || {};
        const active = av.is_active == 1, missing = av.file_exists === false;
        const users = state.detail.users;
        const rank = (state.analytics?.top || []).find(t => t.id === av.id)?.rank;
        document.getElementById('avDetailTitle').textContent = av.name;
        body.innerHTML = `
            <div class="av-detail">
                <div>
                    <div class="av-detail-preview ${active ? '' : 'is-inactive'}">
                        <img src="${attr(imgSrc(av))}" data-avatar-img alt="${attr(av.name)}">
                        <div class="av-preview-ring av-preview-ring--sm" title="Cómo se ve en el menú"><img src="${attr(imgSrc(av))}" data-avatar-img alt=""></div>
                    </div>
                    <div class="flex flex-wrap gap-1.5 mt-3">
                        <span class="av-pill ${active ? 'av-pill--good' : 'av-pill--danger'}"><i class="fas ${active ? 'fa-eye' : 'fa-eye-slash'}"></i> ${active ? 'Activo' : 'Inactivo'}</span>
                        ${missing ? '<span class="av-pill av-pill--danger"><i class="fas fa-exclamation-triangle"></i> Archivo faltante</span>' : ''}
                        ${rank ? `<span class="av-pill av-pill--accent"><i class="fas fa-crown"></i> Top ${rank}</span>` : ''}
                        ${av.file_dir === 'legacy' ? '<span class="av-pill"><i class="fas fa-box-archive"></i> Carpeta antigua</span>' : ''}
                        <button class="av-pill av-pill--purple" onclick="openCreatorPrompt([${av.id}])" title="Cambiar creador"><span class="av-chart-dot" style="background:${creatorColor(av.creator)}"></span> ${esc(av.creator)} <i class="fas fa-pen" style="font-size:.6rem"></i></button>
                    </div>
                </div>
                <div>
                    <dl class="av-kv">
                        <div><dt>ID</dt><dd class="is-mono">#${av.id}</dd></div>
                        <div><dt>Usuarios equipados</dt><dd><span class="av-num" style="font-size:1.1rem">${fmtNum(av.users_count)}</span> <span style="color:var(--av-subtle);font-weight:500">· ${fmtPct(av.users_pct)} de la comunidad</span></dd></div>
                        <div style="grid-column:1/-1"><dt>Archivo</dt><dd class="is-mono" title="${attr(av.filename)}">${esc(av.filename)}</dd></div>
                        <div><dt>Tamaño</dt><dd>${fmtBytes(av.file_size)}${x.width ? ` · ${x.width}×${x.height}` : ''}</dd></div>
                        <div><dt>Formato</dt><dd>${x.mime ? esc(x.mime.replace('image/', '').toUpperCase()) : (av.filename || '').split('.').pop().toUpperCase() || '—'}</dd></div>
                        <div><dt>Creador / Pack</dt><dd>${esc(av.creator)}</dd></div>
                        <div><dt>Subido</dt><dd title="${av.created_at_source === 'file' ? 'Fecha del archivo (avatar anterior al registro de fechas)' : ''}">${fmtDate(av.created_at)}${av.created_at_source === 'file' ? ' <i class="fas fa-file" style="font-size:.6rem;color:var(--av-subtle)"></i>' : ''}</dd></div>
                    </dl>
                    <div class="av-bar av-bar--purple av-bar--lg mt-3"><span style="width:${Math.min(100, av.users_pct)}%"></span></div>
                    <p class="av-help mt-1">${fmtPct(av.users_pct)} de los ${fmtNum(state.stats?.users_total)} usuarios registrados llevan este avatar.</p>

                    <div class="flex items-center justify-between mt-4 mb-2">
                        <h4 class="av-section-title av-heavy" style="font-size:.95rem"><i class="fas fa-users"></i> Usuarios con este avatar</h4>
                        <span class="av-pill av-pill--purple">${fmtNum(av.users_count)}</span>
                    </div>
                    ${state.detail.loading ? `<div class="av-skeleton" style="height:4rem"></div>` : users.length ? `
                        <div class="av-users">${users.map(u => userCard(u, `<button class="av-btn av-btn--sm av-btn--cyan" onclick="openAssignModal(${u.id})" title="Cambiar avatar a este usuario"><i class="fas fa-user-pen"></i></button>`)).join('')}</div>
                        ${state.detail.truncated ? `<p class="av-help mt-2">Mostrando los primeros ${users.length} usuarios.</p>` : ''}`
                        : `<div class="av-empty" style="padding:1.5rem"><i class="fas fa-ghost"></i>Nadie tiene este avatar equipado todavía.</div>`}
                </div>
            </div>`;
        foot.innerHTML = `
            <button class="av-btn" onclick="openCreatorPrompt([${av.id}])"><i class="fas fa-tag"></i> Cambiar creador</button>
            <button class="av-btn ${active ? '' : 'av-btn--cyan'}" onclick="toggleStatus(${av.id}, ${active ? 1 : 0})"><i class="fas ${active ? 'fa-eye-slash' : 'fa-eye'}"></i> ${active ? 'Desactivar' : 'Activar'}</button>
            <button class="av-btn" onclick="openReplaceModal(${av.id})"><i class="fas fa-sync-alt"></i> Reemplazar imagen</button>
            <button class="av-btn av-btn--danger" onclick="deleteAvatar(${av.id})"><i class="fas fa-trash"></i> Eliminar</button>
            <a class="av-btn" href="${attr(av.url || '#')}" target="_blank" rel="noopener" style="margin-left:auto" title="Abrir imagen original"><i class="fas fa-up-right-from-square"></i></a>`;
    }
    function userCard(u, action = '', pick = false) {
        const label = userLabel(u);
        const sub = [u.game_username && u.game_username !== label ? u.game_username : null, u.public_code ? `#${u.public_code}` : null, `ID ${u.id}`].filter(Boolean).join(' · ');
        const when = u.avatar_updated_at ? `Asignado ${fmtDate(u.avatar_updated_at, false)}` : (u.created_at ? `Registro ${fmtDate(u.created_at, false)}` : '');
        const status = u.account_status && u.account_status !== 'active' ? `<span class="av-pill av-pill--danger" style="font-size:.55rem;padding:.05rem .35rem">${esc(u.account_status)}</span>` : '';
        const img = u.avatar_url ? `<img src="${attr(u.avatar_url)}" data-avatar-img alt="">` : `<span class="av-user-ph">${esc(initials(u))}</span>`;
        return `<div class="av-user ${pick ? 'is-pick' : ''} ${pick && state.assign.user?.id === u.id ? 'is-active' : ''}" ${pick ? `onclick="selectAssignUser(${u.id})"` : ''}>
            ${img}
            <div style="min-width:0">
                <div class="av-user-name"><span title="${attr(label)}">${esc(label)}</span>${u.is_verified == 1 ? '<i class="fas fa-check-circle" style="color:var(--av-cyan);font-size:.7rem" title="Verificado"></i>' : ''}${status}</div>
                <div class="av-user-sub" title="${attr(sub)}">${esc(sub)}</div>
                ${when ? `<div class="av-user-sub">${when}</div>` : ''}
            </div>
            ${action ? `<div class="av-user-act">${action}</div>` : ''}
        </div>`;
    }

    // ---------- Asignar avatar a usuario ----------
    function openAssignModal(userId = null) {
        state.assign = { user: null, results: [], avatarId: null, timer: null, busy: false };
        document.getElementById('avUserSearch').value = '';
        document.getElementById('avUserResults').innerHTML = '';
        document.getElementById('avPickSearch').value = '';
        document.getElementById('avAssignStep2').classList.add('hidden');
        document.getElementById('avAssignError').classList.remove('is-on');
        document.getElementById('avAssignSubmit').disabled = true;
        document.getElementById('avAssignModal').classList.add('is-on');
        if (userId) {
            const u = state.detail.users.find(x => x.id == userId);
            if (u) { state.assign.results = [u]; renderUserResults(); selectAssignUser(u.id); }
            else { document.getElementById('avUserSearch').value = String(userId); searchUsers(String(userId)); }
        } else setTimeout(() => document.getElementById('avUserSearch').focus(), 40);
    }
    function closeAssignModal() { document.getElementById('avAssignModal').classList.remove('is-on'); }
    function onUserSearchInput() {
        clearTimeout(state.assign.timer);
        const q = document.getElementById('avUserSearch').value.trim();
        if (!q) { state.assign.results = []; renderUserResults(); return; }
        state.assign.timer = setTimeout(() => searchUsers(q), 260);
    }
    async function searchUsers(q) {
        const box = document.getElementById('avUserResults');
        box.innerHTML = `<div class="av-help"><i class="fas fa-spinner fa-spin"></i> Buscando…</div>`;
        try {
            const json = await api(fd('search_users', { q }));
            if (document.getElementById('avUserSearch').value.trim() !== q) return;
            state.assign.results = json.users || [];
            renderUserResults();
            if (state.assign.results.length === 1 && /^\d+$/.test(q) && state.assign.results[0].id == q) selectAssignUser(state.assign.results[0].id);
        } catch (e) { box.innerHTML = `<div class="av-help" style="color:var(--av-danger)">${esc(e.message)}</div>`; }
    }
    function renderUserResults() {
        const box = document.getElementById('avUserResults');
        const list = state.assign.results;
        if (!list.length) { box.innerHTML = document.getElementById('avUserSearch').value.trim() ? `<div class="av-help">Sin resultados.</div>` : ''; return; }
        const shown = state.assign.user ? list.filter(u => u.id === state.assign.user.id) : list;
        box.innerHTML = shown.map(u => userCard(u, state.assign.user?.id === u.id ? `<button class="av-btn av-btn--sm" onclick="event.stopPropagation(); state.assign.user=null; state.assign.avatarId=null; renderUserResults(); document.getElementById('avAssignStep2').classList.add('hidden'); document.getElementById('avAssignSubmit').disabled=true;"><i class="fas fa-xmark"></i> Cambiar</button>` : `<span class="av-pill">${u.avatar_valid ? esc(u.avatar_name) : 'sin avatar válido'}</span>`, true)).join('');
    }
    function selectAssignUser(id) {
        const u = state.assign.results.find(x => x.id == id); if (!u) return;
        state.assign.user = u; state.assign.avatarId = null;
        renderUserResults();
        document.getElementById('avAssignStep2').classList.remove('hidden');
        renderPicker();
        setTimeout(() => document.getElementById('avPickSearch').focus(), 40);
    }
    function renderPicker() {
        const q = document.getElementById('avPickSearch').value.trim().toLowerCase();
        const list = state.avatars.filter(a => a.is_active == 1 && a.file_exists !== false && (!q || matchesSearch(a, q)))
            .sort((a, b) => (a.creator || '').localeCompare(b.creator || '', 'es') || b.users_count - a.users_count);
        document.getElementById('avPicker').innerHTML = list.length ? list.map(a => `
            <button type="button" class="av-pick ${state.assign.avatarId === a.id ? 'is-on' : ''} ${state.assign.user?.avatar_id === a.id ? 'opacity-50' : ''}" onclick="pickAvatar(${a.id})" title="${attr(a.name)} · ${attr(a.creator)} · ${fmtNum(a.users_count)} usuarios${state.assign.user?.avatar_id === a.id ? ' · avatar actual' : ''}">
                <img src="${attr(imgSrc(a))}" data-avatar-img alt=""><small>${esc(a.name)}</small></button>`).join('')
            : `<div class="av-empty" style="grid-column:1/-1;padding:1rem">Sin avatares válidos que coincidan.</div>`;
        renderSwap();
    }
    function pickAvatar(id) { state.assign.avatarId = Number(id); renderPicker(); document.getElementById('avAssignSubmit').disabled = false; }
    function pickRandom() {
        const pool = state.avatars.filter(a => a.is_active == 1 && a.file_exists !== false && a.id !== state.assign.user?.avatar_id);
        if (!pool.length) return toast('No hay avatares válidos', 'error');
        pickAvatar(pool[Math.floor(Math.random() * pool.length)].id);
        document.querySelector('#avPicker .av-pick.is-on')?.scrollIntoView({ block: 'nearest' });
    }
    function renderSwap() {
        const u = state.assign.user, to = byId(state.assign.avatarId);
        const box = document.getElementById('avSwap');
        if (!u) { box.innerHTML = ''; return; }
        const cur = u.avatar_url ? `<img src="${attr(u.avatar_url)}" data-avatar-img alt="" class="av-preview-ring" style="width:4rem;height:4rem">` : `<div class="av-preview-ring av-user-ph" style="width:4rem;height:4rem;display:inline-flex;align-items:center;justify-content:center">${esc(initials(u))}</div>`;
        box.innerHTML = `
            <figure>${cur}<figcaption>${u.avatar_valid ? esc(u.avatar_name) : 'sin avatar válido'}</figcaption></figure>
            <i class="fas fa-arrow-right-long"></i>
            <figure>${to ? `<img src="${attr(imgSrc(to))}" data-avatar-img alt="" class="av-preview-ring" style="width:4rem;height:4rem;border-color:var(--av-accent)">` : `<div class="av-preview-ring" style="width:4rem;height:4rem;display:inline-flex;align-items:center;justify-content:center;color:var(--av-subtle)"><i class="fas fa-question"></i></div>`}<figcaption>${to ? esc(to.name) : 'elige uno'}</figcaption></figure>`;
    }
    async function submitAssign() {
        const { user, avatarId, busy } = state.assign;
        if (!user || !avatarId || busy) return;
        const err = document.getElementById('avAssignError'); err.classList.remove('is-on');
        const btn = document.getElementById('avAssignSubmit');
        state.assign.busy = true; btn.disabled = true; btn.querySelector('i').className = 'fas fa-spinner fa-spin';
        try {
            const json = await api(fd('assign_user_avatar', { user_id: user.id, avatar_id: avatarId }));
            applyPayload(json);
            toast(json.message || 'Avatar asignado', 'success');
            state.assign.user = json.user || user; state.assign.avatarId = null;
            state.assign.results = state.assign.results.map(u => u.id === state.assign.user.id ? state.assign.user : u);
            renderUserResults(); renderPicker();
            if (state.detail.id != null && isOpen('avDetailModal')) openDetail(state.detail.id);
        } catch (e) { err.textContent = e.message; err.classList.add('is-on'); }
        finally { state.assign.busy = false; btn.querySelector('i').className = 'fas fa-check'; btn.disabled = !state.assign.avatarId; }
    }

    // ---------- Modal subir / reemplazar ----------
    function openUploadModal(file = null) {
        state.modal = { mode: 'upload', id: null, file: null };
        document.getElementById('avModalTitle').innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Subir avatar';
        document.getElementById('avSubmitText').textContent = 'Guardar avatar';
        document.getElementById('avModalHint').classList.add('hidden');
        document.getElementById('avNameField').classList.remove('hidden');
        document.getElementById('avCreatorField').classList.remove('hidden');
        document.getElementById('avActiveField').classList.remove('hidden');
        document.getElementById('avActive').checked = true;
        document.getElementById('avName').value = '';
        document.getElementById('avCreator').value = state.creator || state.pendingCreator || '';
        resetPreview();
        showModal();
        if (file) onFilePicked([file]);
    }
    function openReplaceModal(id) {
        const av = byId(id); if (!av) return;
        state.modal = { mode: 'replace', id: av.id, file: null };
        document.getElementById('avModalTitle').innerHTML = `<i class="fas fa-sync-alt"></i> Reemplazar imagen · ${esc(av.name)}`;
        document.getElementById('avSubmitText').textContent = 'Reemplazar';
        const hint = document.getElementById('avModalHint');
        hint.textContent = av.file_exists === false
            ? `El archivo "${av.filename}" no existe en el servidor. Sube una imagen nueva: se conserva el ID ${av.id} y ${plural(av.users_count, 'usuario', 'usuarios')} lo verán al instante.`
            : `Se sustituye "${av.filename}". Se conserva el ID ${av.id}; ${plural(av.users_count, 'usuario que lo usa', 'usuarios que lo usan')} verán la imagen nueva.`;
        hint.classList.remove('hidden');
        document.getElementById('avNameField').classList.add('hidden');
        document.getElementById('avCreatorField').classList.add('hidden');
        document.getElementById('avActiveField').classList.add('hidden');
        resetPreview();
        showModal();
    }
    function showModal() {
        document.getElementById('avModal').classList.add('is-on');
        document.getElementById('avFormError').classList.remove('is-on');
        document.getElementById('avSubmit').disabled = true;
    }
    function closeUploadModal() { document.getElementById('avModal').classList.remove('is-on'); resetPreview(); }
    function resetPreview() {
        state.modal.file = null;
        document.getElementById('avFile').value = '';
        const prev = document.getElementById('avPreview');
        if (prev.dataset.blob) { URL.revokeObjectURL(prev.dataset.blob); delete prev.dataset.blob; }
        prev.removeAttribute('src'); document.getElementById('avPreviewSmall').removeAttribute('src');
        document.getElementById('avPreviewWrap').classList.add('hidden');
        document.getElementById('avPreviewWrap').classList.remove('flex');
        document.getElementById('avDropEmpty').classList.remove('hidden');
        document.getElementById('avSubmit').disabled = true;
    }
    function onFilePicked(files) {
        const file = files && files[0]; if (!file) return;
        document.getElementById('avFormError').classList.remove('is-on');
        if (!/^image\/(png|jpeg|webp)$/.test(file.type)) return showFormError('Solo PNG, JPG o WEBP');
        if (file.size > MAX_BYTES) return showFormError(`La imagen pesa ${fmtBytes(file.size)}; el máximo es 2 MB`);
        state.modal.file = file;
        const url = URL.createObjectURL(file);
        const prev = document.getElementById('avPreview');
        prev.dataset.blob = url; prev.src = url;
        document.getElementById('avPreviewSmall').src = url;
        prev.onload = () => {
            const w = prev.naturalWidth, h = prev.naturalHeight;
            let meta = `${file.name} · ${fmtBytes(file.size)} · ${w}×${h}`;
            if (w !== h) meta += ' · se recortará al centro (no es cuadrada)';
            if (Math.min(w, h) < 128) meta += ' · resolución baja';
            document.getElementById('avFileMeta').textContent = meta;
        };
        document.getElementById('avPreviewWrap').classList.remove('hidden');
        document.getElementById('avPreviewWrap').classList.add('flex');
        document.getElementById('avDropEmpty').classList.add('hidden');
        if (state.modal.mode === 'upload') {
            const nameInput = document.getElementById('avName');
            if (!nameInput.value.trim()) nameInput.value = file.name.replace(/\.[^/.]+$/, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 60);
            setTimeout(() => nameInput.focus(), 50);
        }
        document.getElementById('avSubmit').disabled = false;
    }
    function showFormError(msg) {
        const err = document.getElementById('avFormError');
        err.textContent = msg; err.classList.add('is-on');
        document.getElementById('avFile').value = '';
        document.getElementById('avSubmit').disabled = !state.modal.file;
    }
    async function submitUpload(ev) {
        ev.preventDefault();
        const { mode, id, file } = state.modal;
        if (!file) return showFormError('Elige una imagen');
        const f = new FormData();
        f.append('file', file);
        if (mode === 'replace') { f.append('action', 'replace_file'); f.append('id', id); }
        else {
            const name = document.getElementById('avName').value.trim();
            if (!name) return showFormError('Ponle un nombre al avatar');
            f.append('action', 'upload'); f.append('name', name);
            f.append('creator', document.getElementById('avCreator').value.trim());
            f.append('is_active', document.getElementById('avActive').checked ? '1' : '0');
        }
        const btn = document.getElementById('avSubmit');
        btn.disabled = true; btn.querySelector('i').className = 'fas fa-spinner fa-spin';
        try {
            const json = await api(f);
            closeUploadModal(); applyPayload(json);
            toast(json.message || 'Guardado', 'success');
            if (json.id) highlightCard(json.id);
        } catch (e) { showFormError(e.message); btn.disabled = false; }
        finally { btn.querySelector('i').className = 'fas fa-save'; }
    }
    function highlightCard(id) {
        setTab('manage');
        const card = document.querySelector(`#avatarGrid [data-id="${id}"]`);
        if (!card) return;
        card.classList.add('is-flash');
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(() => card.classList.remove('is-flash'), 2500);
    }
    function setupPageDrop() {
        const hint = document.getElementById('avDropHint');
        let depth = 0;
        document.addEventListener('dragenter', e => { if (hasFiles(e)) { depth++; hint.classList.remove('hidden'); } });
        document.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; hint.classList.add('hidden'); } });
        document.addEventListener('dragover', e => { if (hasFiles(e)) e.preventDefault(); });
        document.addEventListener('drop', e => {
            if (!hasFiles(e)) return;
            e.preventDefault(); depth = 0; hint.classList.add('hidden');
            const file = e.dataTransfer.files[0];
            if (isOpen('avModal')) onFilePicked([file]); else openUploadModal(file);
        });
    }
    const hasFiles = e => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');

    // ---------- Acciones ----------
    async function deleteAvatar(id) {
        const av = byId(id); if (!av) return;
        const users = Number(av.users_count) || 0;
        const msg = users
            ? `¿Eliminar "${av.name}"?\n\n${plural(users, 'usuario lo tiene', 'usuarios lo tienen')} asignado: se les asignará automáticamente otro avatar activo al azar.`
            : `¿Eliminar "${av.name}"? Nadie lo está usando.`;
        if (!confirm(msg)) return;
        try {
            const json = await api(fd('delete', { id }));
            if (state.detail.id == id) closeDetail();
            applyPayload(json);
            toast(json.users_reassigned ? `Avatar eliminado · ${plural(json.users_reassigned, 'usuario reasignado', 'usuarios reasignados')}` : 'Avatar eliminado', 'success');
        } catch (e) { toast(e.message, 'error'); }
    }
    async function toggleStatus(id, currentStatus) {
        const av = byId(id);
        const newStatus = currentStatus == 1 ? 0 : 1;
        const users = Number(av?.users_count) || 0;
        if (newStatus === 0 && users && !confirm(`Desactivar "${av.name}": ${plural(users, 'usuario lo usa', 'usuarios lo usan')} y se les asignará otro avatar al azar. ¿Continuar?`)) return;
        try {
            const json = await api(fd('toggle_status', { id, status: newStatus }));
            applyPayload(json);
            if (state.detail.id == id && isOpen('avDetailModal')) openDetail(id);
            toast(newStatus ? 'Avatar activado' : (json.users_reassigned ? `Desactivado · ${plural(json.users_reassigned, 'usuario reasignado', 'usuarios reasignados')}` : 'Avatar desactivado'), 'success');
        } catch (e) { toast(e.message, 'error'); }
    }
    async function updateName(id, newName) {
        const name = newName.trim(); const av = byId(id);
        if (!name) { renderGrid(); return; }
        try {
            await api(fd('update_name', { id, name }));
            if (av) av.name = name;
            toast('Nombre actualizado', 'success', 1500);
        } catch (e) { toast(e.message, 'error'); renderGrid(); }
    }
    async function repairOrphans() {
        const n = state.stats?.orphan_users || 0;
        if (!confirm(`Se asignará un avatar activo al azar a ${plural(n, 'usuario', 'usuarios')} sin avatar válido. ¿Continuar?`)) return;
        try { const json = await api(fd('repair_orphans')); applyPayload(json); toast(json.message, 'success'); }
        catch (e) { toast(e.message, 'error'); }
    }

    // ---------- Toasts ----------
    function toast(msg, type = 'info', ms = 3200) {
        const el = document.createElement('div');
        const color = type === 'error' ? '#dc2626' : type === 'success' ? '#16a34a' : '#1f2937';
        el.className = 'av-toast'; el.style.background = color;
        el.innerHTML = `<i class="fas ${type === 'error' ? 'fa-times-circle' : type === 'success' ? 'fa-check-circle' : 'fa-info-circle'}"></i><span></span>`;
        el.querySelector('span').textContent = msg;
        document.getElementById('avToasts').appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, ms);
    }
</script>

<?php include __DIR__ . '/../core/admin_footer.php'; ?>

<?php
// /wabo/videos_comunidad/gestionar_videos_comunidad.php
// Pistas Comunidad: configuración, subida profesional del admin (YouTube / audio / vídeo),
// análisis y moderación de aportes, ficha de cada colaborador (historial, Bananos,
// ediciones, reportes y sanciones) y el dashboard "Visual Tracks" para capturas.
require_once __DIR__ . '/../core/admin_header.php';
?>

<style>
    @font-face {
        font-family: 'HeadingNowVariable';
        font-style: normal;
        font-stretch: normal;
        font-display: swap;
        src: url('/assets/fonts/HeadingNowVariable.woff2') format('woff2');
    }
    @keyframes pcRise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes pcBarIn { from { transform: scaleX(0); } to { transform: scaleX(1); } }
    @keyframes pcPop { from { opacity: 0; transform: scale(.96) translateY(8px); } to { opacity: 1; transform: none; } }
    @keyframes pcShimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
    @keyframes pcPulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(251,191,36,.45); } 50% { box-shadow: 0 0 0 .5rem rgba(251,191,36,0); } }

    #pcApp {
        --pc-surface: #0a0a0a;
        --pc-surface-2: #101012;
        --pc-surface-3: #16161a;
        --pc-border: rgba(255,255,255,.09);
        --pc-border-strong: rgba(255,255,255,.18);
        --pc-ink: #f8fafc;
        --pc-muted: #9ca3af;
        --pc-subtle: #6b7280;
        --pc-accent: #fbbf24;
        --pc-accent-soft: rgba(251,191,36,.12);
        --pc-accent-ink: #111111;
        --pc-cyan: #22d3ee;
        --pc-cyan-soft: rgba(34,211,238,.12);
        --pc-purple: #a78bfa;
        --pc-purple-soft: rgba(167,139,250,.12);
        --pc-good: #22c55e;
        --pc-good-soft: rgba(34,197,94,.12);
        --pc-danger: #f43f5e;
        --pc-danger-soft: rgba(244,63,94,.12);
        --pc-gold: #f5b301;
        --pc-silver: #c8cfd8;
        --pc-bronze: #cd7f32;
        --pc-font-heavy: 'HeadingNowVariable', 'Barlow', 'Inter', sans-serif;
        --pc-font-heavy-var: "wdth" 825, "wght" 800;
        --pc-font-medium-var: "wdth" 700, "wght" 600;
        color: var(--pc-ink);
        max-width: 1560px;
        margin: 0 auto;
    }
    [data-theme="light"] #pcApp {
        --pc-surface: #ffffff;
        --pc-surface-2: #f8fafc;
        --pc-surface-3: #f1f5f9;
        --pc-border: rgba(15,23,42,.1);
        --pc-border-strong: rgba(15,23,42,.22);
        --pc-ink: #0b0b0b;
        --pc-muted: #52514e;
        --pc-subtle: #898781;
        --pc-accent: #d97706;
        --pc-accent-soft: rgba(217,119,6,.1);
        --pc-accent-ink: #ffffff;
        --pc-cyan: #0891b2;
        --pc-cyan-soft: rgba(8,145,178,.09);
        --pc-purple: #7c3aed;
    }
    [data-theme="glitch"] #pcApp {
        --pc-surface: rgba(13,13,13,.92);
        --pc-surface-2: rgba(26,26,26,.94);
        --pc-surface-3: rgba(34,34,34,.94);
        --pc-border: rgba(255,0,255,.38);
        --pc-border-strong: rgba(255,0,255,.6);
        --pc-accent: #ff00ff;
        --pc-accent-soft: rgba(255,0,255,.14);
        --pc-accent-ink: #ffffff;
    }

    .pc-heavy { font-family: var(--pc-font-heavy); font-variation-settings: var(--pc-font-heavy-var); letter-spacing: .01em; }
    .pc-medium { font-family: var(--pc-font-heavy); font-variation-settings: var(--pc-font-medium-var); }
    .pc-num { font-family: var(--pc-font-heavy); font-variation-settings: var(--pc-font-heavy-var); font-variant-numeric: tabular-nums; }

    /* --- Cabecera --- */
    .pc-topbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 1.25rem; margin-bottom: 1.1rem; animation: pcRise .35s ease-out both; flex-wrap: wrap; }
    .pc-eyebrow { display: flex; align-items: center; gap: .55rem; color: var(--pc-accent); font-size: .68rem; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; margin-bottom: .3rem; }
    .pc-eyebrow::before { content: ''; width: 1.75rem; height: 1px; background: currentColor; }
    .pc-title { margin: 0; font-size: clamp(1.7rem, 3vw, 2.6rem); line-height: 1; color: var(--pc-ink); text-transform: uppercase; }
    .pc-title em { font-style: normal; color: var(--pc-accent); }
    .pc-subtitle { color: var(--pc-muted); font-size: .86rem; margin-top: .45rem; max-width: 760px; }
    .pc-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; justify-content: flex-end; }

    .pc-btn { display: inline-flex; align-items: center; justify-content: center; gap: .45rem; min-height: 2.45rem; padding: .55rem .85rem; border-radius: .7rem; border: 1px solid var(--pc-border); background: var(--pc-surface); color: var(--pc-muted); font-size: .76rem; font-weight: 700; cursor: pointer; transition: border-color .18s, color .18s, transform .18s, background .18s; white-space: nowrap; }
    .pc-btn:hover, .pc-btn:focus-visible { border-color: var(--pc-border-strong); color: var(--pc-ink); transform: translateY(-1px); outline: none; }
    .pc-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
    .pc-btn--primary { background: var(--pc-accent); border-color: var(--pc-accent); color: var(--pc-accent-ink); }
    .pc-btn--primary:hover { color: var(--pc-accent-ink); filter: brightness(1.07); }
    .pc-btn--cyan { border-color: rgba(34,211,238,.35); background: var(--pc-cyan-soft); color: var(--pc-cyan); }
    .pc-btn--good { border-color: rgba(34,197,94,.4); background: var(--pc-good-soft); color: var(--pc-good); }
    .pc-btn--warn { border-color: rgba(251,191,36,.4); background: var(--pc-accent-soft); color: var(--pc-accent); }
    .pc-btn--danger { border-color: rgba(244,63,94,.4); background: var(--pc-danger-soft); color: var(--pc-danger); }
    .pc-btn--purple { border-color: rgba(167,139,250,.4); background: var(--pc-purple-soft); color: var(--pc-purple); }
    .pc-btn--sm { min-height: 2rem; padding: .35rem .65rem; font-size: .7rem; border-radius: .55rem; }
    .pc-btn--icon { width: 2.1rem; height: 2.1rem; min-height: 0; padding: 0; border-radius: 999px; }
    .pc-btn--block { width: 100%; }
    .pc-pill { display: inline-flex; align-items: center; gap: .3rem; font-size: .66rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding: .2rem .5rem; border-radius: 999px; border: 1px solid var(--pc-border); color: var(--pc-muted); background: var(--pc-surface-2); white-space: nowrap; }
    .pc-pill--accent { color: var(--pc-accent); border-color: rgba(251,191,36,.35); background: var(--pc-accent-soft); }
    .pc-pill--cyan { color: var(--pc-cyan); border-color: rgba(34,211,238,.35); background: var(--pc-cyan-soft); }
    .pc-pill--good { color: var(--pc-good); border-color: rgba(34,197,94,.35); background: var(--pc-good-soft); }
    .pc-pill--danger { color: var(--pc-danger); border-color: rgba(244,63,94,.35); background: var(--pc-danger-soft); }
    .pc-pill--purple { color: var(--pc-purple); border-color: rgba(167,139,250,.35); background: var(--pc-purple-soft); }
    .pc-count { font-size: .68rem; border-radius: 999px; padding: .05rem .45rem; background: rgba(255,255,255,.08); font-variant-numeric: tabular-nums; }
    [data-theme="light"] .pc-count { background: rgba(15,23,42,.08); }

    /* --- Tabs --- */
    .pc-tabs { display: flex; gap: .35rem; padding: .3rem; border-radius: .9rem; border: 1px solid var(--pc-border); background: var(--pc-surface); margin-bottom: 1.1rem; width: max-content; max-width: 100%; overflow-x: auto; animation: pcRise .4s ease-out both; }
    .pc-tab { display: inline-flex; align-items: center; gap: .5rem; padding: .55rem 1rem; border-radius: .65rem; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--pc-muted); cursor: pointer; border: 1px solid transparent; background: transparent; transition: all .18s; white-space: nowrap; }
    .pc-tab:hover { color: var(--pc-ink); }
    .pc-tab[aria-selected="true"] { background: var(--pc-accent); color: var(--pc-accent-ink); box-shadow: 0 8px 22px -10px var(--pc-accent); }
    .pc-tab[aria-selected="true"] .pc-count { background: rgba(0,0,0,.18); }
    .pc-panel { display: none; } .pc-panel.is-on { display: block; animation: pcRise .3s ease-out both; }

    /* --- Cards --- */
    .pc-card { position: relative; background: var(--pc-surface); border: 1px solid var(--pc-border); border-radius: 1rem; overflow: hidden; animation: pcRise .4s ease-out both; }
    .pc-card--pad { padding: 1.1rem 1.2rem; }
    .pc-card-label { color: var(--pc-muted); font-size: .66rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; display: flex; align-items: center; gap: .45rem; }
    .pc-card-label i { color: var(--pc-accent); }
    .pc-card-value { font-size: 1.9rem; line-height: 1; margin-top: .45rem; color: var(--pc-ink); }
    .pc-card-foot { color: var(--pc-subtle); font-size: .72rem; margin-top: .45rem; }
    .pc-stat { text-align: left; cursor: pointer; transition: border-color .18s, transform .18s; width: 100%; }
    .pc-stat:hover { border-color: var(--pc-border-strong); transform: translateY(-2px); }
    .pc-stat.is-on { border-color: var(--pc-accent); box-shadow: inset 0 0 0 1px var(--pc-accent); }
    .pc-hero { min-height: 170px; padding: 1.25rem 1.35rem; background: radial-gradient(110% 150% at 100% 0%, var(--pc-accent-soft), transparent 55%), var(--pc-surface); }
    .pc-hero--cyan { background: radial-gradient(110% 150% at 100% 0%, var(--pc-cyan-soft), transparent 55%), var(--pc-surface); }
    .pc-hero--purple { background: radial-gradient(110% 150% at 100% 0%, var(--pc-purple-soft), transparent 55%), var(--pc-surface); }
    .pc-hero--good { background: radial-gradient(110% 150% at 100% 0%, var(--pc-good-soft), transparent 55%), var(--pc-surface); }
    .pc-hero--danger { background: radial-gradient(110% 150% at 100% 0%, var(--pc-danger-soft), transparent 55%), var(--pc-surface); }
    .pc-hero .pc-card-value { font-size: 2.6rem; }
    .pc-section-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: 1rem 1.2rem .6rem; flex-wrap: wrap; }
    .pc-section-title { font-size: 1.05rem; text-transform: uppercase; color: var(--pc-ink); display: flex; align-items: center; gap: .55rem; margin: 0; }
    .pc-section-title i { color: var(--pc-accent); font-size: .9rem; }
    .pc-section-sub { font-size: .74rem; color: var(--pc-subtle); margin: .15rem 0 0; }

    /* --- Barras / gráficos --- */
    .pc-bar { position: relative; height: .5rem; border-radius: 999px; background: rgba(255,255,255,.07); overflow: hidden; }
    [data-theme="light"] .pc-bar { background: rgba(15,23,42,.08); }
    .pc-bar > span { position: absolute; inset: 0 auto 0 0; border-radius: 999px; background: var(--pc-accent); transform-origin: left; animation: pcBarIn .7s cubic-bezier(.2,.7,.2,1) both; }
    .pc-bar--lg { height: .8rem; }
    .pc-bar--cyan > span { background: linear-gradient(90deg, var(--pc-cyan), #67e8f9); }
    .pc-bar--purple > span { background: linear-gradient(90deg, var(--pc-purple), #c4b5fd); }
    .pc-bar--good > span { background: linear-gradient(90deg, #16a34a, var(--pc-good)); }
    .pc-bar--danger > span { background: linear-gradient(90deg, #be123c, var(--pc-danger)); }
    .pc-split { display: flex; height: .9rem; border-radius: 999px; overflow: hidden; background: rgba(255,255,255,.06); }
    .pc-split > span { display: block; height: 100%; transform-origin: left; animation: pcBarIn .7s cubic-bezier(.2,.7,.2,1) both; }
    .pc-legend { display: flex; flex-wrap: wrap; gap: .4rem .9rem; margin-top: .55rem; font-size: .7rem; color: var(--pc-muted); }
    .pc-legend i { display: inline-block; width: .6rem; height: .6rem; border-radius: 999px; margin-right: .35rem; vertical-align: middle; }
    .pc-days { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; gap: .35rem; align-items: end; height: 7.5rem; padding: .25rem .2rem 0; }
    .pc-day { display: flex; flex-direction: column; align-items: center; gap: .2rem; height: 100%; justify-content: flex-end; }
    .pc-day-bars { display: flex; gap: .15rem; align-items: flex-end; height: 100%; width: 100%; justify-content: center; }
    .pc-day-bars span { width: 40%; max-width: .9rem; border-radius: .3rem .3rem 0 0; transform-origin: bottom; animation: pcBarIn .6s ease-out both; min-height: 2px; }
    .pc-day-bars .is-up { background: var(--pc-accent); }
    .pc-day-bars .is-rep { background: var(--pc-danger); }
    .pc-day small { font-size: .58rem; color: var(--pc-subtle); white-space: nowrap; }

    /* --- Ranking --- */
    .pc-rank { display: grid; grid-template-columns: 2.3rem 3rem 1fr auto; align-items: center; gap: .75rem; padding: .6rem .75rem; border-radius: .8rem; transition: background .18s, transform .18s; border: 1px solid transparent; }
    .pc-rank:hover { background: var(--pc-surface-2); border-color: var(--pc-border); transform: translateX(2px); }
    .pc-rank-pos { font-size: 1.15rem; text-align: center; color: var(--pc-subtle); }
    .pc-medal { width: 2.1rem; height: 2.1rem; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-size: .95rem; color: #1a1a1a; box-shadow: inset 0 -3px 0 rgba(0,0,0,.25), 0 6px 14px -6px rgba(0,0,0,.6); }
    .pc-medal--1 { background: radial-gradient(circle at 30% 30%, #ffe680, var(--pc-gold) 60%, #b8860b); }
    .pc-medal--2 { background: radial-gradient(circle at 30% 30%, #ffffff, var(--pc-silver) 60%, #8b96a5); }
    .pc-medal--3 { background: radial-gradient(circle at 30% 30%, #f4b183, var(--pc-bronze) 60%, #8a4b17); }
    .pc-rank img, .pc-rank .pc-ph { width: 3rem; height: 3rem; border-radius: 999px; object-fit: cover; border: 2px solid var(--pc-border-strong); background: var(--pc-surface-3); display: inline-flex; align-items: center; justify-content: center; color: var(--pc-muted); font-weight: 800; }
    .pc-rank--1 img { border-color: var(--pc-gold); box-shadow: 0 0 0 3px rgba(245,179,1,.25); }
    .pc-rank--2 img { border-color: var(--pc-silver); }
    .pc-rank--3 img { border-color: var(--pc-bronze); }
    .pc-rank-name { font-weight: 700; font-size: .86rem; color: var(--pc-ink); display: flex; align-items: center; gap: .45rem; min-width: 0; }
    .pc-rank-name span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pc-rank-meta { font-size: .68rem; color: var(--pc-subtle); margin-top: .15rem; }
    .pc-rank-val { text-align: right; }
    .pc-rank-val .pc-num { font-size: 1.15rem; color: var(--pc-ink); }
    .pc-rank-val small { display: block; font-size: .66rem; color: var(--pc-subtle); }

    .pc-podium { display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem; align-items: end; padding: .5rem 1.2rem 0; }
    .pc-podium-slot { text-align: center; border-radius: .9rem; padding: .85rem .5rem .7rem; background: var(--pc-surface-2); border: 1px solid var(--pc-border); transition: transform .18s; position: relative; }
    .pc-podium-slot:hover { transform: translateY(-3px); }
    .pc-podium-slot img, .pc-podium-slot .pc-ph { width: 4.25rem; height: 4.25rem; border-radius: 999px; object-fit: cover; margin: 0 auto .45rem; display: flex; align-items: center; justify-content: center; border: 3px solid var(--pc-border-strong); background: var(--pc-surface-3); color: var(--pc-muted); font-weight: 800; font-size: 1.1rem; }
    .pc-podium-slot--1 { order: 2; padding-top: 1.25rem; background: linear-gradient(180deg, rgba(245,179,1,.14), var(--pc-surface-2) 70%); border-color: rgba(245,179,1,.4); }
    .pc-podium-slot--1 img, .pc-podium-slot--1 .pc-ph { width: 5.5rem; height: 5.5rem; border-color: var(--pc-gold); box-shadow: 0 0 0 4px rgba(245,179,1,.22), 0 14px 30px -12px rgba(245,179,1,.7); }
    .pc-podium-slot--2 { order: 1; border-color: rgba(200,207,216,.35); } .pc-podium-slot--2 img { border-color: var(--pc-silver); }
    .pc-podium-slot--3 { order: 3; border-color: rgba(205,127,50,.4); } .pc-podium-slot--3 img { border-color: var(--pc-bronze); }
    .pc-podium-slot .pc-medal { position: absolute; top: -.7rem; left: 50%; transform: translateX(-50%); width: 1.7rem; height: 1.7rem; font-size: .75rem; }
    .pc-podium-name { font-weight: 700; font-size: .8rem; color: var(--pc-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pc-podium-val { font-size: 1.35rem; color: var(--pc-ink); line-height: 1.1; }
    .pc-podium-val small { font-family: 'Inter', sans-serif; font-size: .66rem; color: var(--pc-subtle); font-weight: 600; margin-left: .2rem; }

    /* --- Formularios --- */
    .pc-label { display: block; font-size: .66rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--pc-muted); margin-bottom: .35rem; }
    .pc-field { margin-bottom: .95rem; }
    .pc-help { font-size: .7rem; color: var(--pc-subtle); margin-top: .3rem; }
    .pc-input, .pc-select, .pc-textarea { min-height: 2.45rem; padding: .5rem .8rem; border-radius: .7rem; border: 1px solid var(--pc-border); background: var(--pc-surface); color: var(--pc-ink); font-size: .8rem; outline: none; transition: border-color .18s; width: 100%; }
    .pc-input:focus, .pc-select:focus, .pc-textarea:focus { border-color: var(--pc-accent); }
    .pc-input::placeholder { color: var(--pc-subtle); }
    .pc-input--num { width: 7rem; text-align: center; font-variant-numeric: tabular-nums; }
    .pc-input--sm { min-height: 2rem; padding: .3rem .6rem; font-size: .76rem; }
    .pc-error { display: none; font-size: .75rem; color: var(--pc-danger); background: var(--pc-danger-soft); border: 1px solid rgba(244,63,94,.3); border-radius: .6rem; padding: .5rem .7rem; margin-bottom: .8rem; }
    .pc-error.is-on { display: block; }
    .pc-hint { font-size: .74rem; color: var(--pc-accent); background: var(--pc-accent-soft); border: 1px solid rgba(251,191,36,.3); border-radius: .6rem; padding: .5rem .7rem; margin-bottom: .8rem; }
    .pc-hint--cyan { color: var(--pc-cyan); background: var(--pc-cyan-soft); border-color: rgba(34,211,238,.3); }
    .pc-hint--danger { color: var(--pc-danger); background: var(--pc-danger-soft); border-color: rgba(244,63,94,.3); }

    .pc-setting { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: .85rem 1.2rem; border-top: 1px solid var(--pc-border); }
    .pc-setting:first-of-type { border-top: 0; }
    .pc-setting-title { font-weight: 700; font-size: .84rem; color: var(--pc-ink); display: flex; align-items: center; gap: .45rem; }
    .pc-setting-desc { font-size: .72rem; color: var(--pc-subtle); margin-top: .15rem; max-width: 46rem; }

    .pc-switch { display: inline-flex; align-items: center; gap: .6rem; cursor: pointer; font-size: .78rem; font-weight: 700; user-select: none; color: var(--pc-muted); position: relative; }
    .pc-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
    .pc-switch__track { position: relative; width: 2.7rem; height: 1.45rem; border-radius: 999px; background: rgba(148,163,184,.3); transition: background .18s ease; flex-shrink: 0; border: 1px solid var(--pc-border); }
    .pc-switch__track::after { content: ''; position: absolute; top: .13rem; left: .15rem; width: 1.05rem; height: 1.05rem; border-radius: 999px; background: #fff; transition: transform .18s ease; box-shadow: 0 2px 6px rgba(0,0,0,.4); }
    .pc-switch input:checked + .pc-switch__track { background: var(--pc-accent); border-color: var(--pc-accent); }
    .pc-switch input:checked + .pc-switch__track::after { transform: translateX(1.2rem); }
    .pc-switch input:focus-visible + .pc-switch__track { outline: 2px solid var(--pc-accent); outline-offset: 2px; }
    .pc-switch input:disabled + .pc-switch__track { opacity: .5; }
    .pc-switch--lg .pc-switch__track { width: 3.6rem; height: 1.95rem; }
    .pc-switch--lg .pc-switch__track::after { width: 1.5rem; height: 1.5rem; top: .17rem; left: .2rem; }
    .pc-switch--lg input:checked + .pc-switch__track::after { transform: translateX(1.65rem); }
    .pc-switch--danger input:checked + .pc-switch__track { background: var(--pc-danger); border-color: var(--pc-danger); }
    .pc-switch--good input:checked + .pc-switch__track { background: var(--pc-good); border-color: var(--pc-good); }

    .pc-master { display: flex; align-items: center; justify-content: space-between; gap: 1.25rem; flex-wrap: wrap; padding: 1.2rem 1.35rem; }
    .pc-master-state { display: inline-flex; align-items: center; gap: .5rem; font-size: 1.4rem; text-transform: uppercase; }
    .pc-master-dot { width: .8rem; height: .8rem; border-radius: 999px; background: var(--pc-good); box-shadow: 0 0 0 4px var(--pc-good-soft); }
    .pc-master.is-off .pc-master-dot { background: var(--pc-danger); box-shadow: 0 0 0 4px var(--pc-danger-soft); }
    .pc-master.is-off { background: radial-gradient(110% 150% at 100% 0%, var(--pc-danger-soft), transparent 55%), var(--pc-surface); }

    .pc-reasons { list-style: none; margin: 0; padding: 0; }
    .pc-reason { display: flex; align-items: center; gap: .6rem; padding: .55rem 1.2rem; border-top: 1px solid var(--pc-border); flex-wrap: wrap; }
    .pc-reason.is-off input[name="label"] { color: var(--pc-subtle); text-decoration: line-through; }
    .pc-reason__order { display: inline-flex; flex-direction: column; gap: .1rem; }
    .pc-reason__order button { width: 1.5rem; height: 1rem; min-height: 0; padding: 0; font-size: .6rem; border-radius: .3rem; }
    .pc-reason__form { display: flex; align-items: center; gap: .5rem; flex: 1 1 16rem; min-width: 0; }

    /* --- Subida --- */
    .pc-upload { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); gap: 1rem; }
    @media (max-width: 1100px) { .pc-upload { grid-template-columns: 1fr; } }
    .pc-seg { display: inline-flex; padding: .25rem; gap: .25rem; border-radius: .8rem; border: 1px solid var(--pc-border); background: var(--pc-surface-2); }
    .pc-seg button { border: 0; background: transparent; color: var(--pc-muted); font-size: .74rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; padding: .5rem .9rem; border-radius: .6rem; cursor: pointer; display: inline-flex; align-items: center; gap: .4rem; transition: all .16s; }
    .pc-seg button[aria-selected="true"] { background: var(--pc-accent); color: var(--pc-accent-ink); }
    .pc-track-pick { display: flex; align-items: center; gap: .75rem; padding: .75rem; border-radius: .9rem; border: 1px dashed var(--pc-border-strong); background: var(--pc-surface-2); min-height: 5.2rem; }
    .pc-track-pick.is-set { border-style: solid; border-color: var(--pc-accent); }
    .pc-track-pick img, .pc-track-pick .pc-cover-ph { width: 3.8rem; height: 3.8rem; border-radius: .6rem; object-fit: cover; background: var(--pc-surface-3); flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; color: var(--pc-subtle); }
    .pc-track-pick-title { font-weight: 700; font-size: .88rem; color: var(--pc-ink); }
    .pc-track-pick-sub { font-size: .7rem; color: var(--pc-subtle); margin-top: .1rem; }
    .pc-tracklist { max-height: 30rem; overflow-y: auto; }
    .pc-track-row { display: grid; grid-template-columns: 2.7rem 1fr auto; align-items: center; gap: .65rem; padding: .5rem 1.2rem; border-top: 1px solid var(--pc-border); cursor: pointer; transition: background .15s; }
    .pc-track-row:hover { background: var(--pc-surface-2); }
    .pc-track-row.is-on { background: var(--pc-accent-soft); }
    .pc-track-row img, .pc-track-row .pc-cover-ph { width: 2.7rem; height: 2.7rem; border-radius: .5rem; object-fit: cover; background: var(--pc-surface-3); display: inline-flex; align-items: center; justify-content: center; color: var(--pc-subtle); }
    .pc-track-row-name { font-weight: 700; font-size: .8rem; color: var(--pc-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pc-track-row-sub { font-size: .68rem; color: var(--pc-subtle); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pc-drop { display: block; cursor: pointer; border-radius: 1rem; border: 2px dashed var(--pc-border-strong); padding: 1.2rem 1rem; text-align: center; transition: border-color .18s, background .18s; color: var(--pc-muted); font-size: .8rem; }
    .pc-drop:hover, .pc-drop.is-over { border-color: var(--pc-accent); background: var(--pc-accent-soft); }
    .pc-drop i { display: block; font-size: 1.6rem; color: var(--pc-accent); margin-bottom: .4rem; }
    .pc-preview { border-radius: .9rem; overflow: hidden; background: #000; border: 1px solid var(--pc-border); }
    .pc-preview--16x9 { aspect-ratio: 16 / 9; }
    .pc-preview iframe, .pc-preview video { width: 100%; height: 100%; display: block; border: 0; aspect-ratio: 16 / 9; }
    .pc-preview audio { width: 100%; display: block; padding: .75rem; background: var(--pc-surface-2); }
    .pc-progress { height: .55rem; border-radius: 999px; background: rgba(255,255,255,.07); overflow: hidden; display: none; margin-top: .6rem; }
    .pc-progress.is-on { display: block; }
    .pc-progress span { display: block; height: 100%; background: linear-gradient(90deg, var(--pc-accent), #fde68a); width: 0; transition: width .2s; }
    .pc-check { display: inline-flex; align-items: center; gap: .5rem; font-size: .78rem; color: var(--pc-muted); cursor: pointer; }
    .pc-check input { width: 1rem; height: 1rem; accent-color: var(--pc-accent); }

    /* --- Moderación --- */
    .pc-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; margin-bottom: 1rem; }
    .pc-chip { display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .75rem; border-radius: 999px; font-size: .74rem; font-weight: 700; border: 1px solid var(--pc-border); background: var(--pc-surface); color: var(--pc-muted); cursor: pointer; transition: all .16s; white-space: nowrap; }
    .pc-chip:hover { border-color: var(--pc-border-strong); color: var(--pc-ink); }
    .pc-chip[aria-selected="true"] { background: var(--pc-accent); border-color: var(--pc-accent); color: var(--pc-accent-ink); }
    .pc-chip[aria-selected="true"] .pc-count { background: rgba(0,0,0,.18); }
    .pc-chip--danger[aria-selected="false"] { color: var(--pc-danger); border-color: rgba(244,63,94,.4); }
    .pc-search { position: relative; flex: 1 1 14rem; min-width: 12rem; }
    .pc-search i { position: absolute; left: .8rem; top: 50%; transform: translateY(-50%); color: var(--pc-subtle); font-size: .8rem; pointer-events: none; }
    .pc-search .pc-input { padding-left: 2.2rem; }
    .pc-row { display: grid; grid-template-columns: 7rem minmax(0, 1fr) auto; align-items: center; gap: .9rem; padding: .75rem 1.2rem; border-top: 1px solid var(--pc-border); animation: pcPop .25s ease-out both; }
    .pc-row:hover { background: var(--pc-surface-2); }
    @media (max-width: 860px) { .pc-row { grid-template-columns: 1fr; } .pc-row-actions { justify-content: flex-start; } }
    .pc-thumb { position: relative; width: 7rem; aspect-ratio: 16 / 9; border-radius: .6rem; overflow: hidden; background: var(--pc-surface-3); border: 1px solid var(--pc-border); display: flex; align-items: center; justify-content: center; color: var(--pc-muted); font-size: 1.3rem; cursor: pointer; }
    .pc-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .pc-thumb--audio { background: linear-gradient(135deg, var(--pc-purple-soft), var(--pc-surface-3)); color: var(--pc-purple); }
    .pc-thumb--video { background: linear-gradient(135deg, var(--pc-cyan-soft), var(--pc-surface-3)); color: var(--pc-cyan); }
    .pc-thumb-kind { position: absolute; left: .3rem; bottom: .3rem; font-size: .55rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding: .1rem .35rem; border-radius: .3rem; background: rgba(0,0,0,.7); color: #fff; }
    .pc-row-title { font-weight: 700; font-size: .88rem; color: var(--pc-ink); display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; }
    .pc-row-meta { font-size: .72rem; color: var(--pc-subtle); margin-top: .2rem; display: flex; flex-wrap: wrap; gap: .2rem .6rem; }
    .pc-row-meta b { color: var(--pc-muted); font-weight: 700; }
    .pc-row-meta a { color: var(--pc-cyan); text-decoration: none; }
    .pc-row-meta a:hover { text-decoration: underline; }
    .pc-row-actions { display: flex; flex-wrap: wrap; gap: .35rem; justify-content: flex-end; }
    .pc-empty { text-align: center; padding: 3rem 1rem; color: var(--pc-subtle); }
    .pc-empty i { font-size: 2rem; display: block; margin-bottom: .6rem; color: var(--pc-border-strong); }
    .pc-user-chip { display: inline-flex; align-items: center; gap: .35rem; font-weight: 700; color: var(--pc-ink); }
    .pc-user-chip img { width: 1.25rem; height: 1.25rem; border-radius: 999px; object-fit: cover; }
    .pc-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .72rem; }
    .pc-edit { display: grid; grid-template-columns: auto 1fr; gap: .25rem .9rem; padding: .6rem .8rem; border-radius: .7rem; background: var(--pc-surface-2); border: 1px solid var(--pc-border); font-size: .76rem; margin-bottom: .5rem; }
    .pc-edit-arrow { color: var(--pc-accent); margin: 0 .35rem; }

    /* --- Usuarios / colaboradores --- */
    .pc-user-row { display: grid; grid-template-columns: 3rem minmax(0, 1fr) auto; align-items: center; gap: .9rem; padding: .7rem 1.2rem; border-top: 1px solid var(--pc-border); cursor: pointer; transition: background .15s; animation: pcPop .25s ease-out both; text-align: left; width: 100%; background: transparent; border-left: 0; border-right: 0; border-bottom: 0; color: inherit; font: inherit; }
    .pc-user-row:hover, .pc-user-row:focus-visible { background: var(--pc-surface-2); outline: none; }
    .pc-user-row.is-banned { background: linear-gradient(90deg, var(--pc-danger-soft), transparent 40%); }
    .pc-user-row img, .pc-user-row .pc-ph { width: 3rem; height: 3rem; border-radius: 999px; object-fit: cover; border: 2px solid var(--pc-border-strong); background: var(--pc-surface-3); display: inline-flex; align-items: center; justify-content: center; color: var(--pc-muted); font-weight: 800; }
    .pc-user-row.is-banned img, .pc-user-row.is-banned .pc-ph { border-color: var(--pc-danger); }
    .pc-user-stats { display: flex; flex-wrap: wrap; gap: .35rem; justify-content: flex-end; }
    .pc-user-stat { display: inline-flex; flex-direction: column; align-items: center; min-width: 4.2rem; padding: .3rem .55rem; border-radius: .6rem; background: var(--pc-surface-2); border: 1px solid var(--pc-border); }
    .pc-user-stat b { font-family: var(--pc-font-heavy); font-variation-settings: var(--pc-font-heavy-var); font-size: .95rem; color: var(--pc-ink); line-height: 1.1; font-variant-numeric: tabular-nums; }
    .pc-user-stat small { font-size: .56rem; letter-spacing: .08em; text-transform: uppercase; color: var(--pc-subtle); font-weight: 800; }
    .pc-user-stat--good b { color: var(--pc-good); } .pc-user-stat--danger b { color: var(--pc-danger); } .pc-user-stat--purple b { color: var(--pc-purple); } .pc-user-stat--accent b { color: var(--pc-accent); }
    @media (max-width: 860px) { .pc-user-row { grid-template-columns: 3rem minmax(0, 1fr); } .pc-user-stats { grid-column: 1 / -1; justify-content: flex-start; } }
    .pc-profile { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; padding: 1rem 1.1rem; border-radius: 1rem; border: 1px solid var(--pc-border); background: radial-gradient(110% 150% at 100% 0%, var(--pc-accent-soft), transparent 55%), var(--pc-surface-2); margin-bottom: 1rem; }
    .pc-profile.is-banned { background: radial-gradient(110% 150% at 100% 0%, var(--pc-danger-soft), transparent 55%), var(--pc-surface-2); border-color: rgba(244,63,94,.4); }
    .pc-profile img, .pc-profile .pc-ph { width: 4.5rem; height: 4.5rem; border-radius: 999px; object-fit: cover; border: 3px solid var(--pc-border-strong); background: var(--pc-surface-3); display: inline-flex; align-items: center; justify-content: center; color: var(--pc-muted); font-weight: 800; font-size: 1.2rem; flex-shrink: 0; }
    .pc-profile.is-banned img, .pc-profile.is-banned .pc-ph { border-color: var(--pc-danger); }
    .pc-profile-name { font-size: 1.35rem; text-transform: uppercase; color: var(--pc-ink); display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; line-height: 1.1; }
    .pc-profile-meta { display: flex; flex-wrap: wrap; gap: .2rem .8rem; font-size: .72rem; color: var(--pc-subtle); margin-top: .35rem; }
    .pc-profile-meta b { color: var(--pc-muted); }
    .pc-profile-actions { display: flex; flex-wrap: wrap; gap: .4rem; margin-left: auto; }
    .pc-subtabs { display: flex; gap: .3rem; flex-wrap: wrap; margin: .9rem 0 .7rem; }
    .pc-ledger { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: .35rem .8rem; align-items: center; padding: .45rem .8rem; border-top: 1px solid var(--pc-border); font-size: .76rem; }
    .pc-ledger:first-child { border-top: 0; }
    .pc-ledger .pc-num { font-size: .95rem; }
    .pc-amt--pos { color: var(--pc-good); } .pc-amt--neg { color: var(--pc-danger); }
    .pc-verdict { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .55rem; margin-bottom: .85rem; }
    .pc-verdict div { border-radius: .8rem; padding: .65rem .8rem; border: 1px solid var(--pc-border); background: var(--pc-surface-2); }
    .pc-verdict small { display: block; font-size: .58rem; letter-spacing: .1em; text-transform: uppercase; color: var(--pc-subtle); font-weight: 800; }
    .pc-verdict b { display: block; margin-top: .15rem; font-size: 1.15rem; line-height: 1.1; }
    .pc-verdict span { display: block; font-size: .72rem; color: var(--pc-muted); margin-top: .15rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pc-verdict--good { border-color: rgba(34,197,94,.4); background: var(--pc-good-soft); } .pc-verdict--good b { color: var(--pc-good); }
    .pc-verdict--danger { border-color: rgba(244,63,94,.4); background: var(--pc-danger-soft); } .pc-verdict--danger b { color: var(--pc-danger); }

    /* --- Modales --- */
    .pc-modal { position: fixed; inset: 0; z-index: 60; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(0,0,0,.72); backdrop-filter: blur(6px); }
    .pc-modal.is-on { display: flex; }
    .pc-dialog { width: 100%; max-height: min(92vh, 960px); display: flex; flex-direction: column; background: var(--pc-surface); border: 1px solid var(--pc-border-strong); border-radius: 1.2rem; box-shadow: 0 40px 90px -30px rgba(0,0,0,.95); animation: pcPop .25s ease-out both; color: var(--pc-ink); overflow: hidden; }
    .pc-dialog--sm { max-width: 30rem; } .pc-dialog--md { max-width: 42rem; } .pc-dialog--lg { max-width: 60rem; }
    .pc-dialog-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--pc-border); }
    .pc-dialog-title { font-size: 1.15rem; text-transform: uppercase; display: flex; align-items: center; gap: .55rem; margin: 0; }
    .pc-dialog-title i { color: var(--pc-accent); font-size: .95rem; }
    .pc-dialog-body { padding: 1.1rem 1.25rem; overflow-y: auto; flex: 1; }
    .pc-dialog-foot { display: flex; align-items: center; justify-content: flex-end; gap: .5rem; padding: .85rem 1.25rem; border-top: 1px solid var(--pc-border); background: var(--pc-surface-2); flex-wrap: wrap; }

    /* --- Visual Tracks / captura --- */
    #pcVisual:fullscreen { background: var(--pc-surface); padding: 2rem clamp(1rem, 4vw, 4rem); overflow-y: auto; }
    #pcVisual:fullscreen .pc-visual-toolbar .pc-btn:not(.pc-btn--exit) { display: none; }
    .pc-visual-brand { display: none; align-items: center; gap: .6rem; color: var(--pc-subtle); font-size: .72rem; letter-spacing: .12em; text-transform: uppercase; font-weight: 800; }
    #pcVisual:fullscreen .pc-visual-brand { display: flex; }
    #pcVisual.is-anon .pc-anon { filter: blur(6px); opacity: .7; user-select: none; }
    #pcVisual.is-anon .pc-anon-img { filter: blur(8px) grayscale(1); }
    .pc-skeleton { height: 8rem; border-radius: 1rem; background: linear-gradient(90deg, var(--pc-surface) 25%, var(--pc-surface-3) 50%, var(--pc-surface) 75%); background-size: 200% 100%; animation: pcShimmer 1.4s linear infinite; }
    .pc-toast { color: #fff; font-size: .8rem; padding: .6rem 1rem; border-radius: .7rem; box-shadow: 0 14px 30px -14px rgba(0,0,0,.9); display: flex; align-items: center; gap: .5rem; max-width: 22rem; animation: pcPop .25s ease-out both; }
    .pc-kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .55rem; }
    .pc-kv div { background: var(--pc-surface-2); border: 1px solid var(--pc-border); border-radius: .75rem; padding: .55rem .7rem; min-width: 0; }
    .pc-kv dt { font-size: .6rem; letter-spacing: .1em; text-transform: uppercase; color: var(--pc-subtle); font-weight: 800; }
    .pc-kv dd { margin: .15rem 0 0; font-size: 1.05rem; color: var(--pc-ink); }
</style>

<div id="pcApp" class="p-4 md:p-6">
    <!-- Cabecera -->
    <div class="pc-topbar">
        <div>
            <div class="pc-eyebrow">Banano Store · Pistas musicales</div>
            <h1 class="pc-title pc-heavy">Pistas <em>Comunidad</em></h1>
            <p class="pc-subtitle">Configura las recompensas, sube vídeos o audio directo para las pistas del día, modera los aportes de la comunidad y comparte las estadísticas con capturas limpias.</p>
        </div>
        <div class="pc-actions">
            <span id="pcMasterPill" class="pc-pill pc-pill--good" title="Estado del interruptor global de subida"><i class="fas fa-circle" style="font-size:.5rem"></i> Subidas activas</span>
            <button onclick="loadAll()" class="pc-btn pc-btn--icon" title="Recargar"><i class="fas fa-rotate"></i></button>
            <button onclick="setTab('visual')" class="pc-btn pc-btn--cyan"><i class="fas fa-chart-simple"></i> Visual Tracks</button>
            <button onclick="setTab('upload')" class="pc-btn pc-btn--primary"><i class="fas fa-cloud-upload-alt"></i> Subir pista</button>
        </div>
    </div>

    <!-- Tabs -->
    <div class="pc-tabs" role="tablist">
        <button class="pc-tab pc-medium" role="tab" data-tab="config" aria-selected="true" onclick="setTab('config')"><i class="fas fa-sliders"></i> Configuración</button>
        <button class="pc-tab pc-medium" role="tab" data-tab="upload" aria-selected="false" onclick="setTab('upload')"><i class="fas fa-cloud-upload-alt"></i> Subir pistas admin</button>
        <button class="pc-tab pc-medium" role="tab" data-tab="mod" aria-selected="false" onclick="setTab('mod')"><i class="fas fa-shield-halved"></i> Análisis y moderación <span id="pcTabReports" class="pc-count">0</span></button>
        <button class="pc-tab pc-medium" role="tab" data-tab="users" aria-selected="false" onclick="setTab('users')"><i class="fas fa-users"></i> Usuarios <span id="pcTabUsers" class="pc-count">0</span></button>
        <button class="pc-tab pc-medium" role="tab" data-tab="visual" aria-selected="false" onclick="setTab('visual')"><i class="fas fa-trophy"></i> Visual Tracks</button>
    </div>

    <div id="pcLoader" class="hidden text-center py-8"><i class="fas fa-spinner fa-spin text-3xl" style="color:var(--pc-accent)"></i></div>

    <!-- ===================== 1. CONFIGURACIÓN ===================== -->
    <section id="pcPanelConfig" class="pc-panel is-on" role="tabpanel">
        <div class="pc-card pc-master pc-hero mb-4" id="pcMaster">
            <div>
                <p class="pc-card-label"><i class="fas fa-power-off"></i> Interruptor global de subida</p>
                <div class="pc-master-state pc-heavy mt-2"><span class="pc-master-dot"></span> <span id="pcMasterText">Comunidad activa</span></div>
                <p class="pc-card-foot" id="pcMasterDesc">Los usuarios ven el botón "SUBE EL VIDEO" en las fichas y la API acepta aportes. Al apagarlo, el botón desaparece al instante y la API rechaza cualquier subida.</p>
            </div>
            <label class="pc-switch pc-switch--lg pc-switch--good">
                <input type="checkbox" id="pcUploadEnabled" data-setting="set_upload_enabled" data-param="enabled" disabled>
                <span class="pc-switch__track" aria-hidden="true"></span>
                <span id="pcUploadEnabledLabel">Activado</span>
            </label>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <!-- Recompensas -->
            <div class="pc-card">
                <div class="pc-section-head">
                    <div>
                        <h3 class="pc-section-title pc-heavy"><i class="fas fa-coins"></i> Recompensas</h3>
                        <p class="pc-section-sub">Se reflejan al instante en toda la web ("SUBE EL VIDEO Y GANA N BANANOS").</p>
                    </div>
                </div>
                <form id="pcRewardsForm" class="px-5 pb-5 flex items-end gap-4 flex-wrap">
                    <div>
                        <label class="pc-label" for="pcUploadReward">Por vídeo publicado</label>
                        <input type="number" min="0" max="100000" id="pcUploadReward" class="pc-input pc-input--num" required>
                    </div>
                    <div>
                        <label class="pc-label" for="pcReportReward">Por reporte confirmado</label>
                        <input type="number" min="0" max="100000" id="pcReportReward" class="pc-input pc-input--num" required>
                    </div>
                    <button type="submit" class="pc-btn pc-btn--primary"><i class="fas fa-save"></i> Guardar</button>
                </form>
            </div>

            <!-- Requisitos y límites -->
            <div class="pc-card">
                <div class="pc-section-head">
                    <div>
                        <h3 class="pc-section-title pc-heavy"><i class="fas fa-user-shield"></i> Requisitos y límites</h3>
                        <p class="pc-section-sub">Quién puede subir y reportar. Los usuarios suspendidos nunca pueden subir.</p>
                    </div>
                </div>
                <div class="pc-setting">
                    <div>
                        <div class="pc-setting-title">Requisito para subir vídeos</div>
                        <div class="pc-setting-desc">Se aplica al instante en el botón "SUBE EL VIDEO" y en la API.</div>
                    </div>
                    <select id="pcUploadPolicy" class="pc-select" style="width:auto;min-width:15rem" disabled>
                        <option value="verified">Solo usuarios verificados</option>
                        <option value="logged_in">Cualquier usuario logueado</option>
                    </select>
                </div>
                <div class="pc-setting">
                    <div>
                        <div class="pc-setting-title">Límite diario por usuario</div>
                        <div class="pc-setting-desc">Máximo de vídeos que un usuario puede subir en 24 horas. Sin límite si está desactivado.</div>
                    </div>
                    <form id="pcLimitForm" class="flex items-center gap-3 flex-wrap">
                        <label class="pc-switch">
                            <input type="checkbox" id="pcLimitEnabled" disabled>
                            <span class="pc-switch__track" aria-hidden="true"></span>
                            <span id="pcLimitEnabledLabel">Activo</span>
                        </label>
                        <input type="number" min="1" max="1000" id="pcLimitValue" class="pc-input pc-input--num" required disabled aria-label="Vídeos por día">
                        <button type="submit" class="pc-btn pc-btn--sm pc-btn--primary" id="pcLimitSave" disabled><i class="fas fa-save"></i></button>
                    </form>
                </div>
                <div class="pc-setting">
                    <div>
                        <div class="pc-setting-title">Reportar para usuarios sin verificar</div>
                        <div class="pc-setting-desc">Desactivado: solo las cuentas verificadas ven el botón "Reportar este video".</div>
                    </div>
                    <label class="pc-switch">
                        <input type="checkbox" id="pcReportUnverified" data-setting="set_report_unverified" data-param="visible" disabled>
                        <span class="pc-switch__track" aria-hidden="true"></span>
                        <span id="pcReportUnverifiedLabel">Visible</span>
                    </label>
                </div>
            </div>

            <!-- Edición y ranking -->
            <div class="pc-card">
                <div class="pc-section-head">
                    <div>
                        <h3 class="pc-section-title pc-heavy"><i class="fas fa-pen-to-square"></i> Edición del autor y ranking</h3>
                        <p class="pc-section-sub">El autor puede corregir su enlace y el segundo de inicio hasta que la pista sea verificada.</p>
                    </div>
                </div>
                <div class="pc-setting">
                    <div>
                        <div class="pc-setting-title">Permitir editar aunque esté reportado</div>
                        <div class="pc-setting-desc">Regla global. Aunque esté apagada, puedes autorizar la edición caso por caso desde la pestaña de moderación. Una pista verificada nunca es editable.</div>
                    </div>
                    <label class="pc-switch">
                        <input type="checkbox" id="pcEditReported" data-setting="set_edit_reported" data-param="enabled" disabled>
                        <span class="pc-switch__track" aria-hidden="true"></span>
                        <span id="pcEditReportedLabel">Permitido</span>
                    </label>
                </div>
                <div class="pc-setting">
                    <div>
                        <div class="pc-setting-title">Botón flotante "Top colaboradores"</div>
                        <div class="pc-setting-desc">Trofeo flotante en la ficha de producto que abre el ranking público. Al ocultarlo desaparece al instante.</div>
                    </div>
                    <label class="pc-switch">
                        <input type="checkbox" id="pcTopVisible" data-setting="set_top_visible" data-param="visible" disabled>
                        <span class="pc-switch__track" aria-hidden="true"></span>
                        <span id="pcTopVisibleLabel">Visible</span>
                    </label>
                </div>
            </div>

            <!-- Motivos de reporte -->
            <div class="pc-card">
                <div class="pc-section-head">
                    <div>
                        <h3 class="pc-section-title pc-heavy"><i class="fas fa-flag"></i> Motivos de reporte</h3>
                        <p class="pc-section-sub">Opciones del modal "Reportar este video", en este orden. Un motivo desactivado conserva su historial.</p>
                    </div>
                </div>
                <ol id="pcReasons" class="pc-reasons" aria-busy="true"><li class="pc-empty">Cargando motivos…</li></ol>
                <form id="pcReasonAdd" class="flex items-center gap-2 flex-wrap px-5 py-4" style="border-top:1px solid var(--pc-border)">
                    <input type="text" id="pcReasonNew" class="pc-input" style="flex:1 1 14rem" minlength="3" maxlength="120" placeholder="Nuevo motivo, p. ej. «El audio está desincronizado»" autocomplete="off" required disabled>
                    <button type="submit" class="pc-btn pc-btn--primary" id="pcReasonAddBtn" disabled><i class="fas fa-plus"></i> Añadir</button>
                </form>
            </div>
        </div>
    </section>

    <!-- ===================== 2. SUBIR PISTAS ADMIN ===================== -->
    <section id="pcPanelUpload" class="pc-panel" role="tabpanel">
        <div class="pc-upload">
            <div class="pc-card">
                <div class="pc-section-head">
                    <div>
                        <h3 class="pc-section-title pc-heavy"><i class="fas fa-music"></i> Añade el vídeo de esta pista</h3>
                        <p class="pc-section-sub">Publica como <b>Banano</b> (sin autor de la comunidad). Acepta YouTube o un archivo de audio/vídeo directo para las canciones que YouTube bloquea.</p>
                    </div>
                </div>
                <form id="pcUploadForm" class="px-5 pb-5">
                    <div id="pcUploadError" class="pc-error" role="alert"></div>
                    <div id="pcUploadHint" class="pc-hint hidden"></div>

                    <div class="pc-field">
                        <label class="pc-label">Pista</label>
                        <div id="pcTrackPick" class="pc-track-pick">
                            <span class="pc-cover-ph"><i class="fas fa-compact-disc"></i></span>
                            <div class="min-w-0 flex-1">
                                <div class="pc-track-pick-title" id="pcTrackPickTitle">Elige una pista de la tienda de hoy →</div>
                                <div class="pc-track-pick-sub" id="pcTrackPickSub">…o escribe el nombre exacto abajo.</div>
                            </div>
                        </div>
                        <input type="text" id="pcTrackName" class="pc-input mt-2" maxlength="255" placeholder="Nombre exacto de la pista (como aparece en la tienda)" autocomplete="off" required>
                        <input type="hidden" id="pcCosmeticId">
                    </div>

                    <div class="pc-field">
                        <label class="pc-label">Fuente</label>
                        <div class="pc-seg" role="tablist">
                            <button type="button" data-kind="youtube" aria-selected="true" onclick="setKind('youtube')"><i class="fab fa-youtube"></i> YouTube</button>
                            <button type="button" data-kind="file" aria-selected="false" onclick="setKind('file')"><i class="fas fa-file-audio"></i> Audio / vídeo directo</button>
                        </div>
                    </div>

                    <div id="pcKindYoutube">
                        <div class="pc-field">
                            <label class="pc-label" for="pcYoutubeUrl">Enlace de YouTube</label>
                            <input type="url" id="pcYoutubeUrl" class="pc-input" placeholder="https://www.youtube.com/watch?v=…" autocomplete="off" oninput="previewYoutube()">
                            <p class="pc-help">Se comprueba que el vídeo exista y permita insertarse.</p>
                        </div>
                    </div>
                    <div id="pcKindFile" class="hidden">
                        <div class="pc-field">
                            <label class="pc-drop" id="pcDrop" for="pcFile">
                                <i class="fas fa-file-arrow-up"></i>
                                <span id="pcDropText">Arrastra un MP3 / AAC / M4A / OGG / WAV / FLAC o un MP4 / WebM aquí, o haz clic para elegirlo</span>
                                <input type="file" id="pcFile" class="hidden" accept="audio/*,video/mp4,video/webm,video/quicktime" onchange="onFileChosen(this.files[0])">
                            </label>
                            <p class="pc-help" id="pcFileHelp">Audio hasta <b id="pcMaxAudio">40</b> MB · vídeo hasta <b id="pcMaxVideo">300</b> MB. El servidor acepta como máximo <b id="pcServerMax">—</b> por petición.</p>
                        </div>
                    </div>

                    <div class="pc-field">
                        <label class="pc-label" for="pcStart">Segundo de inicio</label>
                        <div class="flex items-center gap-3 flex-wrap">
                            <input type="number" id="pcStart" class="pc-input pc-input--num" min="0" max="36000" value="0" oninput="previewYoutube()">
                            <span class="pc-help" style="margin:0">Desde dónde empieza a reproducirse (0 = principio).</span>
                        </div>
                    </div>

                    <div id="pcPreview" class="pc-preview hidden mb-4"></div>

                    <div class="flex items-center gap-4 flex-wrap mb-4">
                        <label class="pc-check"><input type="checkbox" id="pcLock" checked> Marcar como <b>verificada</b> al publicar</label>
                        <label class="pc-check" id="pcReplaceWrap"><input type="checkbox" id="pcReplace"> Reemplazar el contenido actual de la pista</label>
                    </div>
                    <div id="pcUploadProgress" class="pc-progress"><span></span></div>
                    <div class="flex items-center justify-between gap-3 flex-wrap mt-4">
                        <button type="button" class="pc-btn" onclick="resetUpload()"><i class="fas fa-eraser"></i> Limpiar</button>
                        <button type="submit" id="pcUploadSubmit" class="pc-btn pc-btn--primary"><i class="fas fa-cloud-upload-alt"></i> Publicar pista</button>
                    </div>
                </form>
            </div>

            <div class="pc-card">
                <div class="pc-section-head">
                    <div>
                        <h3 class="pc-section-title pc-heavy"><i class="fas fa-store"></i> Tienda de hoy</h3>
                        <p class="pc-section-sub" id="pcShopSub">Pistas visibles hoy y su cobertura. Haz clic para elegir.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button class="pc-chip" id="pcShopOnlyMissing" aria-selected="false" onclick="state.shopOnlyMissing = !state.shopOnlyMissing; renderShop();"><i class="fas fa-circle-exclamation"></i> Solo sin contenido</button>
                        <button class="pc-btn pc-btn--icon" title="Recargar tienda" onclick="loadTracks(true)"><i class="fas fa-rotate"></i></button>
                    </div>
                </div>
                <div class="px-5 pb-3">
                    <div class="pc-search"><i class="fas fa-search"></i><input id="pcShopSearch" type="search" class="pc-input" placeholder="Buscar pista o artista…" oninput="renderShop()"></div>
                </div>
                <div id="pcShopCoverage" class="px-5 pb-3"></div>
                <div id="pcShopList" class="pc-tracklist"><div class="pc-empty"><i class="fas fa-spinner fa-spin"></i> Cargando tienda…</div></div>
            </div>
        </div>
    </section>

    <!-- ===================== 3. ANÁLISIS Y MODERACIÓN ===================== -->
    <section id="pcPanelMod" class="pc-panel" role="tabpanel">
        <div id="pcModStats" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5"></div>
        <div class="pc-toolbar">
            <div id="pcModChips" class="flex flex-wrap gap-2" role="tablist"></div>
            <div class="pc-search"><i class="fas fa-search"></i><input id="pcModSearch" type="search" class="pc-input" placeholder="Buscar pista, usuario, motivo o ID de YouTube…" oninput="renderMod()"><kbd class="hidden"></kbd></div>
        </div>
        <div class="pc-card"><div id="pcModList"></div></div>
    </section>

    <!-- ===================== 4. USUARIOS / COLABORADORES ===================== -->
    <section id="pcPanelUsers" class="pc-panel" role="tabpanel">
        <div id="pcUserStats" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5"></div>
        <div class="pc-toolbar">
            <div id="pcUserChips" class="flex flex-wrap gap-2" role="tablist"></div>
            <div class="pc-search"><i class="fas fa-search"></i><input id="pcUserSearch" type="search" class="pc-input" placeholder="Buscar por nombre, usuario del juego, código o ID…" oninput="renderUsers()"></div>
            <select id="pcUserSort" class="pc-select" style="width:auto" onchange="renderUsers()" title="Ordenar">
                <option value="recent">Más recientes</option>
                <option value="coins">Más Bananos netos</option>
                <option value="published">Más publicados</option>
                <option value="edits">Más ediciones</option>
                <option value="reports">Más reportados</option>
                <option value="name">Nombre A–Z</option>
            </select>
            <button class="pc-btn pc-btn--icon" title="Recargar usuarios" onclick="loadUsers(true)"><i class="fas fa-rotate"></i></button>
        </div>
        <div class="pc-card">
            <div class="pc-section-head">
                <div><h2 class="pc-section-title pc-heavy"><i class="fas fa-users"></i> Colaboradores del sistema de vídeos</h2><p class="pc-section-sub">Todos los usuarios que han aportado, reportado o han sido suspendidos. Pulsa cualquier fila para abrir su ficha completa.</p></div>
                <span id="pcUsersCount" class="pc-pill">—</span>
            </div>
            <div id="pcUserList"><div class="pc-empty"><i class="fas fa-spinner fa-spin"></i> Cargando usuarios…</div></div>
        </div>
    </section>

    <!-- ===================== 5. VISUAL TRACKS ===================== -->
    <section id="pcPanelVisual" class="pc-panel" role="tabpanel">
        <div id="pcVisual">
            <div class="pc-visual-toolbar flex items-center justify-between gap-3 mb-4 flex-wrap">
                <div>
                    <div class="pc-eyebrow">Visual Tracks · Métricas de la comunidad</div>
                    <h2 class="pc-title pc-heavy" style="font-size:clamp(1.4rem,2.4vw,2rem)">¿Quién pone la <em>música</em> en la tienda?</h2>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="pc-visual-brand"><i class="fas fa-music"></i> Banano Store · <span id="pcVisualDate"></span></span>
                    <button class="pc-btn pc-btn--purple" id="pcAnonBtn" onclick="toggleAnon()"><i class="fas fa-user-secret"></i> Anonimizar</button>
                    <button class="pc-btn pc-btn--icon" title="Recalcular" onclick="loadStats()"><i class="fas fa-rotate"></i></button>
                    <button class="pc-btn pc-btn--cyan" onclick="toggleCapture()"><i class="fas fa-camera"></i> Modo captura</button>
                    <button class="pc-btn pc-btn--exit hidden" onclick="toggleCapture()"><i class="fas fa-compress"></i> Salir</button>
                </div>
            </div>
            <div id="pcVisualBody"><div class="pc-skeleton"></div></div>
        </div>
    </section>
</div>

<!-- Modal: motivo / acción de moderación -->
<div id="pcActionModal" class="pc-modal" role="dialog" aria-modal="true" aria-labelledby="pcActionTitle">
    <div class="pc-dialog pc-dialog--sm">
        <div class="pc-dialog-head">
            <h3 class="pc-dialog-title pc-heavy" id="pcActionTitle"><i class="fas fa-gavel"></i> Acción</h3>
            <button type="button" class="pc-btn pc-btn--icon" onclick="closeAction()" aria-label="Cerrar"><i class="fas fa-times"></i></button>
        </div>
        <div class="pc-dialog-body">
            <div id="pcActionHint" class="pc-hint"></div>
            <div class="pc-field" id="pcActionReasonWrap">
                <label class="pc-label" for="pcActionReason">Motivo (visible para el equipo)</label>
                <input type="text" id="pcActionReason" class="pc-input" maxlength="255" placeholder="Vídeo incorrecto o inapropiado" autocomplete="off">
            </div>
            <label class="pc-check" id="pcActionExtraWrap"><input type="checkbox" id="pcActionExtra"> <span id="pcActionExtraLabel"></span></label>
        </div>
        <div class="pc-dialog-foot">
            <button type="button" class="pc-btn" onclick="closeAction()">Cancelar</button>
            <button type="button" class="pc-btn pc-btn--danger" id="pcActionConfirm" onclick="confirmAction()"><i class="fas fa-check"></i> Confirmar</button>
        </div>
    </div>
</div>

<!-- Modal: historial de ediciones -->
<div id="pcEditsModal" class="pc-modal" role="dialog" aria-modal="true" aria-labelledby="pcEditsTitle">
    <div class="pc-dialog pc-dialog--md">
        <div class="pc-dialog-head">
            <h3 class="pc-dialog-title pc-heavy" id="pcEditsTitle"><i class="fas fa-clock-rotate-left"></i> Historial de ediciones</h3>
            <button type="button" class="pc-btn pc-btn--icon" onclick="closeModal('pcEditsModal')" aria-label="Cerrar"><i class="fas fa-times"></i></button>
        </div>
        <div class="pc-dialog-body" id="pcEditsBody"></div>
        <div class="pc-dialog-foot"><button type="button" class="pc-btn" onclick="closeModal('pcEditsModal')">Cerrar</button></div>
    </div>
</div>

<!-- Modal: ficha de usuario / colaborador -->
<div id="pcUserModal" class="pc-modal" role="dialog" aria-modal="true" aria-labelledby="pcUserTitle">
    <div class="pc-dialog pc-dialog--lg">
        <div class="pc-dialog-head">
            <h3 class="pc-dialog-title pc-heavy" id="pcUserTitle"><i class="fas fa-id-card"></i> Ficha del colaborador</h3>
            <button type="button" class="pc-btn pc-btn--icon" onclick="closeModal('pcUserModal')" aria-label="Cerrar"><i class="fas fa-times"></i></button>
        </div>
        <div class="pc-dialog-body" id="pcUserBody"></div>
        <div class="pc-dialog-foot"><button type="button" class="pc-btn" onclick="closeModal('pcUserModal')">Cerrar</button></div>
    </div>
</div>

<!-- Modal: resultado de un reporte resuelto -->
<div id="pcVerdictModal" class="pc-modal" role="dialog" aria-modal="true" aria-labelledby="pcVerdictTitle">
    <div class="pc-dialog pc-dialog--sm">
        <div class="pc-dialog-head">
            <h3 class="pc-dialog-title pc-heavy" id="pcVerdictTitle"><i class="fas fa-gavel"></i> Reporte resuelto</h3>
            <button type="button" class="pc-btn pc-btn--icon" onclick="closeModal('pcVerdictModal')" aria-label="Cerrar"><i class="fas fa-times"></i></button>
        </div>
        <div class="pc-dialog-body" id="pcVerdictBody"></div>
        <div class="pc-dialog-foot"><button type="button" class="pc-btn pc-btn--primary" onclick="closeModal('pcVerdictModal')"><i class="fas fa-check"></i> Entendido</button></div>
    </div>
</div>

<!-- Modal: vista previa de medio -->
<div id="pcMediaModal" class="pc-modal" role="dialog" aria-modal="true" aria-labelledby="pcMediaTitle">
    <div class="pc-dialog pc-dialog--md">
        <div class="pc-dialog-head">
            <h3 class="pc-dialog-title pc-heavy" id="pcMediaTitle"><i class="fas fa-play"></i> Vista previa</h3>
            <button type="button" class="pc-btn pc-btn--icon" onclick="closeModal('pcMediaModal')" aria-label="Cerrar"><i class="fas fa-times"></i></button>
        </div>
        <div class="pc-dialog-body" id="pcMediaBody"></div>
    </div>
</div>

<!-- Toasts -->
<div id="pcToasts" class="fixed bottom-4 right-4 z-[80] flex flex-col gap-2"></div>

<script>
    const API = 'videos_comunidad/api_videos_comunidad.php';
    const MOD_LISTS = [
        { key: 'published', label: 'Publicados',   icon: 'fa-circle-play' },
        { key: 'reports',   label: 'Reportes',     icon: 'fa-flag', danger: true },
        { key: 'locked',    label: 'Verificados',  icon: 'fa-certificate' },
        { key: 'rejected',  label: 'Rechazados',   icon: 'fa-ban' },
        { key: 'system',    label: 'Del panel',    icon: 'fa-crown' },
        { key: 'bans',      label: 'Suspendidos',  icon: 'fa-user-slash' },
        { key: 'edits',     label: 'Ediciones',    icon: 'fa-clock-rotate-left' },
    ];
    const USER_LISTS = [
        { key: 'all',       label: 'Todos',          icon: 'fa-users' },
        { key: 'active',    label: 'Con publicados', icon: 'fa-circle-play' },
        { key: 'edited',    label: 'Han editado',    icon: 'fa-pen' },
        { key: 'reported',  label: 'Reportados',     icon: 'fa-flag', danger: true },
        { key: 'rejected',  label: 'Con retirados',  icon: 'fa-ban' },
        { key: 'reporters', label: 'Reporteros',     icon: 'fa-bullhorn' },
        { key: 'banned',    label: 'Suspendidos',    icon: 'fa-user-slash', danger: true },
    ];
    const state = {
        data: null, stats: null, tracks: null, tracksLoadedAt: 0,
        tab: 'config', modList: 'published', shopOnlyMissing: false, anon: false,
        upload: { kind: 'youtube', file: null, track: null, busy: false },
        action: null,
        users: null, usersLoadedAt: 0, userList: 'all', userDetail: null, userTab: 'submissions',
    };

    document.addEventListener('DOMContentLoaded', () => {
        const hashTab = (location.hash || '').replace('#', '');
        if (['config', 'upload', 'mod', 'users', 'visual'].includes(hashTab)) setTab(hashTab, false);
        loadAll();
        document.addEventListener('keydown', onKey);
        document.addEventListener('fullscreenchange', () => {
            const on = document.fullscreenElement === document.getElementById('pcVisual');
            document.querySelector('#pcVisual .pc-btn--exit').classList.toggle('hidden', !on);
        });
        setupDrop();
        bindSettings();
    });

    // ---------- Utilidades ----------
    const $ = (sel, root = document) => root.querySelector(sel);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const attr = s => esc(s).replace(/`/g, '&#96;');
    const fmtNum = n => Number(n || 0).toLocaleString('es-ES');
    const fmtPct = n => `${Number(n || 0).toLocaleString('es-ES', { maximumFractionDigits: 1 })}%`;
    const pct = (a, b) => b > 0 ? a * 100 / b : 0;
    const plural = (n, s, p) => `${fmtNum(n)} ${Number(n) === 1 ? s : p}`;
    const fmtBytes = n => n == null ? '—' : n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(0)} KB` : `${(n / 1048576).toFixed(1)} MB`;
    // La BD guarda UTC; se muestra en la zona horaria del navegador del admin.
    const parseUtc = s => {
        if (!s) return null;
        const str = String(s);
        const d = new Date(/[zZ]|[+-]\d\d:?\d\d$/.test(str) ? str : str.replace(' ', 'T') + 'Z');
        return isNaN(d) ? null : d;
    };
    const fmtDate = (s, withTime = true) => {
        const d = parseUtc(s); if (!d) return s ? String(s) : '—';
        return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric', ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}) });
    };
    const fmtSecs = n => { n = Number(n) || 0; const m = Math.floor(n / 60), s = n % 60; return `${m}:${String(s).padStart(2, '0')}`; };
    const ytThumb = id => `https://i.ytimg.com/vi/${encodeURIComponent(id)}/mqdefault.jpg`;
    const ytLink = (id, label) => `<a href="https://www.youtube.com/watch?v=${encodeURIComponent(id)}" target="_blank" rel="noopener">${esc(label || id)}</a>`;
    const kindOf = row => row.media_type || (row.youtube_id ? 'youtube' : 'youtube');
    const kindLabel = k => ({ youtube: 'YouTube', audio: 'Audio', video: 'Vídeo' }[k] || k);
    const kindIcon = k => ({ youtube: 'fab fa-youtube', audio: 'fas fa-music', video: 'fas fa-film' }[k] || 'fas fa-play');
    const anonName = (name, i) => state.anon ? `Colaborador #${i + 1}` : (name || 'Sin nombre');
    const avatarImg = (url, name, cls = '') => url
        ? `<img src="${attr(url)}" alt="" loading="lazy" class="${cls} pc-anon-img">`
        : `<span class="pc-ph ${cls}">${esc((name || '?').trim().slice(0, 2).toUpperCase())}</span>`;

    async function api(formData) {
        const res = await fetch(API, { method: 'POST', body: formData, credentials: 'same-origin' });
        const json = await res.json().catch(() => ({ status: 'error', message: 'Respuesta inválida del servidor' }));
        if (json.status !== 'success') throw new Error(json.message || 'Error');
        return json;
    }
    async function apiGet(action, params = {}) {
        const qs = new URLSearchParams({ action, ...params });
        const res = await fetch(`${API}?${qs}`, { credentials: 'same-origin' });
        const json = await res.json().catch(() => ({ status: 'error', message: 'Respuesta inválida del servidor' }));
        if (json.status !== 'success') throw new Error(json.message || 'Error');
        return json;
    }
    const fd = (action, params = {}) => {
        const f = new FormData(); f.append('action', action);
        Object.entries(params).forEach(([k, v]) => f.append(k, v));
        return f;
    };
    /** Ejecuta una acción, muestra su mensaje y refresca la vista. */
    async function run(action, params = {}, okMsg) {
        try {
            const json = await api(fd(action, params));
            toast(okMsg || json.message || 'Hecho', 'success');
            await loadOverview();
            if (state.tab === 'visual' || action.startsWith('resolve') || action.startsWith('re')) loadStats();
            // Cualquier acción de moderación altera contadores/saldos de la ficha.
            if (state.users) loadUsers(true);
            if (state.userDetail && isOpen('pcUserModal')) openUser(state.userDetail.user.user_id, true);
            return json;
        } catch (e) { toast(e.message, 'error'); return null; }
    }

    async function loadAll() {
        $('#pcLoader').classList.remove('hidden');
        try { await Promise.all([loadOverview(), loadStats(), loadTracks()]); }
        finally { $('#pcLoader').classList.add('hidden'); }
    }
    async function loadOverview() {
        try {
            state.data = await apiGet('overview');
            renderConfig(); renderMod(); renderModStats(); renderShop();
        } catch (e) { toast('Error al cargar: ' + e.message, 'error'); }
    }
    async function loadStats() {
        try { state.stats = await apiGet('stats'); renderVisual(); }
        catch (e) { $('#pcVisualBody').innerHTML = `<div class="pc-empty"><i class="fas fa-triangle-exclamation"></i>${esc(e.message)}</div>`; }
    }
    async function loadTracks(force = false) {
        if (!force && state.tracks && Date.now() - state.tracksLoadedAt < 5 * 60 * 1000) return renderShop();
        try {
            const json = await apiGet('tracks');
            state.tracks = json.tracks || []; state.tracksLoadedAt = Date.now();
            renderShop();
        } catch (e) {
            state.tracks = [];
            $('#pcShopList').innerHTML = `<div class="pc-empty"><i class="fas fa-store-slash"></i>No se pudo leer la tienda de hoy.<br><small>${esc(e.message)}</small></div>`;
        }
    }

    function onKey(e) {
        if (e.key === 'Escape') {
            for (const id of ['pcActionModal', 'pcVerdictModal', 'pcEditsModal', 'pcMediaModal', 'pcUserModal']) if (isOpen(id)) return closeModal(id);
        }
    }
    const isOpen = id => document.getElementById(id).classList.contains('is-on');
    function closeModal(id) {
        const el = document.getElementById(id);
        el.classList.remove('is-on');
        if (id === 'pcMediaModal') $('#pcMediaBody').innerHTML = '';
    }

    // ---------- Tabs ----------
    function setTab(tab, pushHash = true) {
        state.tab = tab;
        document.querySelectorAll('.pc-tab').forEach(b => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
        $('#pcPanelConfig').classList.toggle('is-on', tab === 'config');
        $('#pcPanelUpload').classList.toggle('is-on', tab === 'upload');
        $('#pcPanelMod').classList.toggle('is-on', tab === 'mod');
        $('#pcPanelUsers').classList.toggle('is-on', tab === 'users');
        $('#pcPanelVisual').classList.toggle('is-on', tab === 'visual');
        if (pushHash) history.replaceState(null, '', tab === 'config' ? location.pathname : `#${tab}`);
        if (tab === 'visual' && !state.stats) loadStats();
        if (tab === 'upload') loadTracks();
        if (tab === 'users') loadUsers();
    }

    // ---------- 1. Configuración ----------
    function bindSettings() {
        document.querySelectorAll('input[data-setting]').forEach(input => {
            input.addEventListener('change', async () => {
                input.disabled = true;
                await run(input.dataset.setting, { [input.dataset.param]: input.checked ? '1' : '0' });
                input.disabled = false;
            });
        });
        $('#pcUploadPolicy').addEventListener('change', async e => {
            e.target.disabled = true;
            await run('set_upload_policy', { policy: e.target.value });
            e.target.disabled = false;
        });
        $('#pcLimitEnabled').addEventListener('change', async e => {
            e.target.disabled = true;
            await run('set_upload_limit', { enabled: e.target.checked ? '1' : '0' });
            e.target.disabled = false;
        });
        $('#pcLimitForm').addEventListener('submit', e => { e.preventDefault(); run('set_upload_limit', { limit: $('#pcLimitValue').value }); });
        $('#pcRewardsForm').addEventListener('submit', e => {
            e.preventDefault();
            run('update_rewards', { upload_reward: $('#pcUploadReward').value, report_reward: $('#pcReportReward').value });
        });
        $('#pcReasonAdd').addEventListener('submit', async e => {
            e.preventDefault();
            const input = $('#pcReasonNew'); const label = input.value.trim(); if (!label) return;
            const ok = await run('save_report_reason', { id: '0', label });
            if (ok) { input.value = ''; input.focus(); }
        });
        const list = $('#pcReasons');
        list.addEventListener('submit', e => {
            const form = e.target.closest('form[data-id]'); if (!form) return;
            e.preventDefault();
            run('save_report_reason', { id: form.dataset.id, label: form.elements.label.value });
        });
        list.addEventListener('change', async e => {
            const input = e.target.closest('input[data-toggle]'); if (!input) return;
            input.disabled = true;
            await run('save_report_reason', { id: input.dataset.toggle, enabled: input.checked ? '1' : '0' });
            input.disabled = false;
        });
        list.addEventListener('click', e => {
            const btn = e.target.closest('button[data-act]'); if (!btn) return;
            if (btn.dataset.act === 'move') run('move_report_reason', { id: btn.dataset.id, direction: btn.dataset.dir });
            if (btn.dataset.act === 'delete') {
                const label = btn.closest('.pc-reason')?.querySelector('input[name="label"]')?.value || 'este motivo';
                if (confirm(`¿Eliminar el motivo «${label}»? Esta acción no se puede deshacer.`)) run('delete_report_reason', { id: btn.dataset.id });
            }
        });
        $('#pcUploadForm').addEventListener('submit', submitUpload);
        $('#pcTrackName').addEventListener('input', () => { state.upload.track = null; syncTrackPick(); });
        $('#pcModList').addEventListener('click', onModClick);
        $('#pcUserList').addEventListener('click', e => { const b = e.target.closest('[data-user-open]'); if (b) openUser(b.dataset.userOpen); });
        $('#pcUserBody').addEventListener('click', onUserClick);
    }

    function renderConfig() {
        const r = state.data.rewards;
        const enabled = !!r.upload_enabled;
        const master = $('#pcMaster');
        master.classList.toggle('is-off', !enabled);
        $('#pcUploadEnabled').checked = enabled; $('#pcUploadEnabled').disabled = false;
        $('#pcUploadEnabledLabel').textContent = enabled ? 'Activado' : 'Desactivado';
        $('#pcMasterText').textContent = enabled ? 'Comunidad activa' : 'Subidas pausadas';
        $('#pcMasterDesc').textContent = enabled
            ? 'Los usuarios ven el botón "SUBE EL VIDEO" en las fichas y la API acepta aportes. Al apagarlo, el botón desaparece al instante y la API rechaza cualquier subida.'
            : 'El botón de subida está oculto en toda la web y la API rechaza los aportes de la comunidad. Los reportes, el ranking y los vídeos ya publicados siguen funcionando.';
        const pill = $('#pcMasterPill');
        pill.className = `pc-pill ${enabled ? 'pc-pill--good' : 'pc-pill--danger'}`;
        pill.innerHTML = `<i class="fas fa-circle" style="font-size:.5rem"></i> ${enabled ? 'Subidas activas' : 'Subidas pausadas'}`;

        $('#pcUploadReward').value = r.upload_reward;
        $('#pcReportReward').value = r.report_reward;
        const policy = $('#pcUploadPolicy'); policy.value = r.upload_policy === 'logged_in' ? 'logged_in' : 'verified'; policy.disabled = false;
        const sw = (id, on, labelId, onText, offText) => {
            const el = document.getElementById(id); el.checked = !!on; el.disabled = false;
            document.getElementById(labelId).textContent = on ? onText : offText;
        };
        sw('pcReportUnverified', r.report_unverified, 'pcReportUnverifiedLabel', 'Visible', 'Oculto');
        sw('pcEditReported', r.edit_reported, 'pcEditReportedLabel', 'Permitido', 'Bloqueado');
        sw('pcTopVisible', r.top_visible, 'pcTopVisibleLabel', 'Visible', 'Oculto');
        const limitOn = !!r.upload_limit_enabled;
        sw('pcLimitEnabled', limitOn, 'pcLimitEnabledLabel', 'Activo', 'Sin límite');
        $('#pcLimitValue').value = r.upload_daily_limit; $('#pcLimitValue').disabled = !limitOn; $('#pcLimitSave').disabled = !limitOn;

        renderReasons(state.data.report_reasons || []);
        const lim = state.data.media_limits || {};
        $('#pcMaxAudio').textContent = lim.audio_mb ?? 40;
        $('#pcMaxVideo').textContent = lim.video_mb ?? 300;
        $('#pcServerMax').textContent = lim.upload_max_filesize ? `${lim.upload_max_filesize} (post_max_size ${lim.post_max_size})` : '—';
        if (lim.dir_writable === false) {
            const hint = $('#pcUploadHint'); hint.classList.remove('hidden'); hint.classList.add('pc-hint--danger');
            hint.innerHTML = '<i class="fas fa-triangle-exclamation"></i> La carpeta <b>/assets/pistas</b> no tiene permisos de escritura: las subidas de archivos fallarán hasta corregirlo.';
        }
    }

    function renderReasons(rows) {
        const list = $('#pcReasons');
        list.innerHTML = rows.length ? rows.map((reason, i) => {
            const uses = Number(reason.uses) || 0; const id = attr(reason.id);
            return `
            <li class="pc-reason ${reason.is_active ? '' : 'is-off'}">
                <span class="pc-reason__order">
                    <button type="button" class="pc-btn" data-act="move" data-dir="up" data-id="${id}" ${i === 0 ? 'disabled' : ''} aria-label="Subir"><i class="fas fa-chevron-up"></i></button>
                    <button type="button" class="pc-btn" data-act="move" data-dir="down" data-id="${id}" ${i === rows.length - 1 ? 'disabled' : ''} aria-label="Bajar"><i class="fas fa-chevron-down"></i></button>
                </span>
                <label class="pc-switch" title="${reason.is_active ? 'Se ofrece al reportar' : 'Oculto en el modal de reporte'}">
                    <input type="checkbox" data-toggle="${id}" ${reason.is_active ? 'checked' : ''} aria-label="Activar «${attr(reason.label)}»">
                    <span class="pc-switch__track" aria-hidden="true"></span>
                </label>
                <form class="pc-reason__form" data-id="${id}">
                    <input type="text" name="label" class="pc-input pc-input--sm" value="${attr(reason.label)}" minlength="3" maxlength="120" required autocomplete="off" title="Código interno: ${attr(reason.code)}">
                    <button type="submit" class="pc-btn pc-btn--sm pc-btn--primary" aria-label="Guardar"><i class="fas fa-save"></i></button>
                </form>
                <span class="pc-pill" title="Reportes que usan este motivo">${plural(uses, 'reporte', 'reportes')}</span>
                <button type="button" class="pc-btn pc-btn--sm pc-btn--danger" data-act="delete" data-id="${id}" ${uses > 0 ? 'disabled title="Ya se usó en reportes: desactívalo en lugar de borrarlo"' : 'title="Eliminar motivo"'}><i class="fas fa-trash"></i></button>
            </li>`;
        }).join('') : '<li class="pc-empty" style="padding:1.5rem">Sin motivos: el botón "Reportar este video" no permitirá enviar reportes hasta que añadas uno.</li>';
        list.setAttribute('aria-busy', 'false');
        $('#pcReasonNew').disabled = false; $('#pcReasonAddBtn').disabled = false;
    }

    // ---------- 2. Subida admin ----------
    function setKind(kind) {
        state.upload.kind = kind;
        document.querySelectorAll('.pc-seg button').forEach(b => b.setAttribute('aria-selected', String(b.dataset.kind === kind)));
        $('#pcKindYoutube').classList.toggle('hidden', kind !== 'youtube');
        $('#pcKindFile').classList.toggle('hidden', kind !== 'file');
        if (kind === 'youtube') previewYoutube(); else previewFile();
    }
    function extractYoutubeId(url) {
        const m = String(url || '').match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/) || String(url || '').match(/^([A-Za-z0-9_-]{11})$/);
        return m ? m[1] : null;
    }
    function previewYoutube() {
        if (state.upload.kind !== 'youtube') return;
        const id = extractYoutubeId($('#pcYoutubeUrl').value);
        const box = $('#pcPreview');
        if (!id) { box.classList.add('hidden'); box.innerHTML = ''; return; }
        const start = Math.max(0, Number($('#pcStart').value) || 0);
        box.className = 'pc-preview pc-preview--16x9 mb-4';
        box.innerHTML = `<iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?start=${start}&rel=0" title="Vista previa" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen loading="lazy"></iframe>`;
    }
    function onFileChosen(file) {
        state.upload.file = file || null;
        $('#pcDropText').textContent = file ? `${file.name} · ${fmtBytes(file.size)} · ${file.type || 'tipo desconocido'}` : 'Arrastra un archivo de audio o vídeo aquí, o haz clic para elegirlo';
        previewFile();
    }
    function previewFile() {
        if (state.upload.kind !== 'file') return;
        const box = $('#pcPreview'); const f = state.upload.file;
        box.querySelectorAll('audio,video').forEach(m => { m.pause(); URL.revokeObjectURL(m.src); });
        if (!f) { box.classList.add('hidden'); box.innerHTML = ''; return; }
        const url = URL.createObjectURL(f);
        const isVideo = f.type.startsWith('video/');
        box.className = `pc-preview mb-4 ${isVideo ? 'pc-preview--16x9' : ''}`;
        box.innerHTML = isVideo ? `<video src="${url}" controls playsinline preload="metadata"></video>` : `<audio src="${url}" controls preload="metadata"></audio>`;
    }
    function setupDrop() {
        const drop = $('#pcDrop');
        ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('is-over'); }));
        ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('is-over'); }));
        drop.addEventListener('drop', e => { const f = e.dataTransfer?.files?.[0]; if (f) { setKind('file'); onFileChosen(f); } });
    }
    function pickTrack(name) {
        const t = (state.tracks || []).find(x => x.name === name); if (!t) return;
        state.upload.track = t;
        $('#pcTrackName').value = t.name;
        $('#pcCosmeticId').value = t.cosmetic_id || '';
        syncTrackPick(); renderShop();
        $('#pcTrackName').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    function syncTrackPick() {
        const t = state.upload.track; const pick = $('#pcTrackPick');
        const name = $('#pcTrackName').value.trim();
        const dbRow = t || (state.tracks || []).find(x => x.name === name) || null;
        pick.classList.toggle('is-set', !!dbRow);
        const cover = dbRow?.artwork ? `<img src="${attr(dbRow.artwork)}" alt="">` : '<span class="pc-cover-ph"><i class="fas fa-compact-disc"></i></span>';
        let status = '…o escribe el nombre exacto abajo.';
        if (dbRow) {
            status = dbRow.has_media
                ? `<span class="pc-pill ${dbRow.is_locked ? 'pc-pill--accent' : 'pc-pill--cyan'}"><i class="${kindIcon(dbRow.media_type)}"></i> ${kindLabel(dbRow.media_type)} ${dbRow.source === 'community' ? `de ${esc(dbRow.contributor || 'la comunidad')}` : 'del panel'}${dbRow.is_locked ? ' · verificado' : ''}</span>`
                : '<span class="pc-pill pc-pill--danger"><i class="fas fa-circle-exclamation"></i> Sin contenido</span>';
            if (dbRow.artist) status = `${esc(dbRow.artist)} · ${status}`;
        }
        pick.innerHTML = `${cover}<div class="min-w-0 flex-1"><div class="pc-track-pick-title">${esc(dbRow ? dbRow.name : (name || 'Elige una pista de la tienda de hoy →'))}</div><div class="pc-track-pick-sub">${status}</div></div>`;
        const hasMedia = !!dbRow?.has_media;
        $('#pcReplaceWrap').classList.toggle('hidden', !hasMedia);
        if (!hasMedia) $('#pcReplace').checked = false;
        const hint = $('#pcUploadHint');
        if (hasMedia && dbRow.source === 'community') {
            hint.className = 'pc-hint pc-hint--cyan'; hint.classList.remove('hidden');
            hint.innerHTML = `<i class="fas fa-circle-info"></i> Esta pista ya tiene un aporte de <b>${esc(dbRow.contributor || 'la comunidad')}</b>. Si lo reemplazas, el aporte queda como <b>reemplazado</b> y el autor <b>conserva</b> sus Bananos.`;
        } else if (!hint.classList.contains('pc-hint--danger')) hint.classList.add('hidden');
    }
    function resetUpload() {
        state.upload = { kind: 'youtube', file: null, track: null, busy: false };
        $('#pcUploadForm').reset(); $('#pcLock').checked = true; $('#pcCosmeticId').value = '';
        $('#pcDropText').textContent = 'Arrastra un MP3 / AAC / M4A / OGG / WAV / FLAC o un MP4 / WebM aquí, o haz clic para elegirlo';
        $('#pcUploadError').classList.remove('is-on');
        $('#pcPreview').classList.add('hidden'); $('#pcPreview').innerHTML = '';
        setKind('youtube'); syncTrackPick(); renderShop();
    }
    function submitUpload(e) {
        e.preventDefault();
        if (state.upload.busy) return;
        const err = $('#pcUploadError'); err.classList.remove('is-on');
        const fail = msg => { err.textContent = msg; err.classList.add('is-on'); };
        const trackName = $('#pcTrackName').value.trim();
        if (!trackName) return fail('Escribe o elige el nombre exacto de la pista.');
        const f = fd('admin_upload', {
            track_name: trackName, cosmetic_id: $('#pcCosmeticId').value, media_kind: state.upload.kind,
            start_seconds: String(Math.max(0, Number($('#pcStart').value) || 0)),
            lock: $('#pcLock').checked ? '1' : '0', replace: $('#pcReplace').checked ? '1' : '0',
        });
        if (state.upload.kind === 'youtube') {
            if (!extractYoutubeId($('#pcYoutubeUrl').value)) return fail('Pega un enlace de YouTube válido.');
            f.append('youtube_url', $('#pcYoutubeUrl').value.trim());
        } else {
            if (!state.upload.file) return fail('Selecciona un archivo de audio o vídeo.');
            f.append('media', state.upload.file);
        }
        const btn = $('#pcUploadSubmit'); const prog = $('#pcUploadProgress'); const bar = prog.querySelector('span');
        state.upload.busy = true; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publicando…';
        prog.classList.add('is-on'); bar.style.width = '0%';
        const xhr = new XMLHttpRequest();
        xhr.open('POST', API, true);
        xhr.withCredentials = true;
        xhr.upload.onprogress = ev => { if (ev.lengthComputable) bar.style.width = `${Math.round(ev.loaded * 100 / ev.total)}%`; };
        xhr.onerror = () => done(null, 'Error de red durante la subida.');
        xhr.onload = () => {
            let json = null;
            try { json = JSON.parse(xhr.responseText); } catch (_) { /* respuesta no JSON */ }
            if (!json) return done(null, xhr.status === 413 ? 'El archivo supera el tamaño máximo que acepta el servidor (post_max_size / upload_max_filesize).' : 'Respuesta inválida del servidor.');
            if (json.status !== 'success') return done(null, json.message || 'No se pudo publicar.');
            done(json);
        };
        const done = (json, error) => {
            state.upload.busy = false; btn.disabled = false; btn.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Publicar pista';
            prog.classList.remove('is-on');
            if (error) return fail(error);
            toast(json.message || 'Pista publicada.', 'success');
            resetUpload();
            state.tracksLoadedAt = 0;
            loadOverview(); loadStats(); loadTracks(true);
        };
        xhr.send(f);
    }

    function renderShop() {
        const list = $('#pcShopList'); const tracks = state.tracks;
        if (!tracks) return;
        const q = ($('#pcShopSearch').value || '').trim().toLowerCase();
        $('#pcShopOnlyMissing').setAttribute('aria-selected', String(state.shopOnlyMissing));
        const covered = tracks.filter(t => t.has_media).length;
        $('#pcShopCoverage').innerHTML = tracks.length ? `
            <div class="flex items-center justify-between text-xs mb-1" style="color:var(--pc-muted)"><span><b class="pc-num" style="color:var(--pc-ink);font-size:1rem">${covered}</b> / ${tracks.length} pistas con contenido</span><span class="pc-num" style="color:var(--pc-accent)">${fmtPct(pct(covered, tracks.length))}</span></div>
            <div class="pc-bar pc-bar--lg"><span style="width:${pct(covered, tracks.length)}%"></span></div>` : '';
        const rows = tracks.filter(t => (!state.shopOnlyMissing || !t.has_media) && (!q || `${t.name} ${t.artist || ''}`.toLowerCase().includes(q)));
        const selected = $('#pcTrackName').value.trim();
        list.innerHTML = rows.length ? rows.map(t => `
            <div class="pc-track-row ${t.name === selected ? 'is-on' : ''}" data-name="${attr(t.name)}" onclick="pickTrack(this.dataset.name)" onkeydown="if (event.key === 'Enter') pickTrack(this.dataset.name)" role="button" tabindex="0">
                ${t.artwork ? `<img src="${attr(t.artwork)}" alt="" loading="lazy">` : '<span class="pc-cover-ph"><i class="fas fa-compact-disc"></i></span>'}
                <div class="min-w-0">
                    <div class="pc-track-row-name">${esc(t.name)}</div>
                    <div class="pc-track-row-sub">${esc(t.artist || '')}${t.has_media ? ` · ${kindLabel(t.media_type)} ${t.source === 'community' ? `· ${esc(t.contributor || 'comunidad')}` : '· panel'}` : ''}</div>
                </div>
                ${t.has_media
                    ? `<span class="pc-pill ${t.is_locked ? 'pc-pill--accent' : 'pc-pill--good'}"><i class="${kindIcon(t.media_type)}"></i> ${t.is_locked ? 'Verif.' : 'OK'}</span>`
                    : '<span class="pc-pill pc-pill--danger">Falta</span>'}
            </div>`).join('') : `<div class="pc-empty"><i class="fas fa-check-double"></i>${tracks.length ? 'Nada que mostrar con este filtro.' : 'No hay pistas en la tienda de hoy (o la API de Fortnite no respondió).'}</div>`;
        syncTrackPick();
    }

    // ---------- 3. Moderación ----------
    function counts() {
        const d = state.data; if (!d) return {};
        return {
            published: d.published.length,
            reports: d.reports.filter(r => r.status === 'open').length,
            locked: d.locked.length,
            rejected: d.rejected.length,
            system: d.system.length,
            bans: d.bans.length,
            edits: d.edits.length,
        };
    }
    function renderModStats() {
        const d = state.data; if (!d) return;
        const c = counts();
        $('#pcTabReports').textContent = c.reports;
        const card = (label, value, icon, foot, key, cls = '') => `
            <button type="button" onclick="setModList('${key}')" class="pc-card pc-card--pad pc-stat ${state.modList === key ? 'is-on' : ''} ${cls}">
                <p class="pc-card-label"><i class="fas ${icon}"></i> ${label}</p>
                <p class="pc-card-value pc-num">${fmtNum(value)}</p>
                <p class="pc-card-foot">${foot}</p>
            </button>`;
        const reported = d.published.filter(r => Number(r.open_reports) > 0).length;
        $('#pcModStats').innerHTML =
            card('Publicados', c.published, 'fa-circle-play', `${reported ? `${reported} con reporte abierto` : 'Aportes activos de la comunidad'}`, 'published') +
            card('Reportes abiertos', c.reports, 'fa-flag', c.reports ? 'Requieren decisión' : 'Todo revisado', 'reports', c.reports ? 'pc-hero--danger' : '') +
            card('Verificados', c.locked, 'fa-certificate', 'Bloqueados para edición', 'locked') +
            card('Rechazados', c.rejected, 'fa-ban', 'Incluye reemplazados', 'rejected') +
            card('Del panel', c.system, 'fa-crown', 'Subidos por el equipo', 'system') +
            card('Suspendidos', c.bans, 'fa-user-slash', 'Sin acceso al sistema', 'bans');
        $('#pcModChips').innerHTML = MOD_LISTS.map(l => `
            <button class="pc-chip ${l.danger ? 'pc-chip--danger' : ''}" aria-selected="${String(state.modList === l.key)}" onclick="setModList('${l.key}')"><i class="fas ${l.icon}"></i> ${l.label} <span class="pc-count">${fmtNum(c[l.key] || 0)}</span></button>`).join('');
    }
    function setModList(key) { state.modList = key; setTab('mod'); renderModStats(); renderMod(); }

    const thumb = (row, kind) => {
        const k = kind || kindOf(row);
        if (k === 'youtube' && row.youtube_id) return `<div class="pc-thumb" data-kind="youtube" data-src="${attr(row.youtube_id)}" data-start="${Number(row.start_seconds ?? row.start_time) || 0}" onclick="openMedia(this.dataset.kind, this.dataset.src, this.dataset.start)" title="Vista previa"><img src="${ytThumb(row.youtube_id)}" alt="" loading="lazy"><span class="pc-thumb-kind">YouTube</span></div>`;
        if (row.media_url) return `<div class="pc-thumb pc-thumb--${k}" data-kind="${attr(k)}" data-src="${attr(row.media_url)}" data-start="0" onclick="openMedia(this.dataset.kind, this.dataset.src, this.dataset.start)" title="Vista previa"><i class="${kindIcon(k)}"></i><span class="pc-thumb-kind">${kindLabel(k)}</span></div>`;
        return `<div class="pc-thumb"><i class="fas fa-eye-slash"></i></div>`;
    };
    // Con `userId` el chip abre la ficha del colaborador.
    const userChip = (name, banned, userId) => `<span class="pc-user-chip" ${userId ? `role="link" tabindex="0" data-user-open="${attr(userId)}" style="cursor:pointer;text-decoration:underline dotted" title="Ver ficha del usuario"` : ''}><i class="fas fa-user" style="color:var(--pc-subtle);font-size:.65rem"></i> ${esc(name || 'Sin nombre')}${Number(banned) ? ' <span class="pc-pill pc-pill--danger">Suspendido</span>' : ''}</span>`;
    const SUB_STATUS = {
        published: ['Publicado', 'pc-pill--good', 'fa-circle-play'],
        rejected: ['Retirado', 'pc-pill--danger', 'fa-ban'],
        replaced: ['Reemplazado por el panel', 'pc-pill--cyan', 'fa-crown'],
        deleted: ['Eliminado por el autor', 'pc-pill--purple', 'fa-trash'],
    };
    const subStatusPill = s => { const [l, c, i] = SUB_STATUS[s] || [s, '', 'fa-circle']; return `<span class="pc-pill ${c}"><i class="fas ${i}"></i> ${esc(l)}</span>`; };
    // Un registro de edición con track_state 'deleted' es el borrado del autor.
    const editDiff = r => r.track_state === 'deleted'
        ? `${ytLink(r.old_youtube_id)} @ ${fmtSecs(r.old_start_seconds)} <i class="fas fa-arrow-right pc-edit-arrow"></i> <b style="color:var(--pc-danger)">eliminado por el autor</b>`
        : `${ytLink(r.old_youtube_id)} @ ${fmtSecs(r.old_start_seconds)} <i class="fas fa-arrow-right pc-edit-arrow"></i> ${ytLink(r.new_youtube_id)} @ ${fmtSecs(r.new_start_seconds)}`;

    const renderers = {
        published(rows) {
            return rows.map(r => {
                const locked = Number(r.is_locked) === 1, reported = Number(r.open_reports) > 0, edits = Number(r.edits) || 0;
                const editAllowed = r.edit_allowed === null || r.edit_allowed === undefined ? null : Number(r.edit_allowed) === 1;
                return `
                <div class="pc-row">
                    ${thumb(r, 'youtube')}
                    <div class="min-w-0">
                        <div class="pc-row-title">${esc(r.track_name)}
                            ${locked ? '<span class="pc-pill pc-pill--accent"><i class="fas fa-certificate"></i> Verificado</span>' : '<span class="pc-pill">Sin verificar</span>'}
                            ${reported ? `<span class="pc-pill pc-pill--danger"><i class="fas fa-flag"></i> ${plural(r.open_reports, 'reporte', 'reportes')}</span>` : ''}
                            ${edits ? `<span class="pc-pill pc-pill--purple" title="Última edición: ${attr(fmtDate(r.edited_at))}"><i class="fas fa-pen"></i> ${plural(edits, 'edición', 'ediciones')}</span>` : ''}
                            ${reported && !locked ? (editAllowed === true ? '<span class="pc-pill pc-pill--cyan">Edición autorizada</span>' : editAllowed === false ? '<span class="pc-pill">Edición bloqueada</span>' : '') : ''}
                        </div>
                        <div class="pc-row-meta">
                            <span>${ytLink(r.youtube_id, r.video_title || r.youtube_id)}</span><span>inicio <b>${fmtSecs(r.start_seconds)}</b></span>
                            <span>por ${userChip(r.user_name, r.user_banned, r.user_id)}</span><span><b>+${fmtNum(r.reward_amount)}</b> 🍌</span>
                            <span title="Publicado"><i class="far fa-clock"></i> ${fmtDate(r.published_at || r.created_at)}</span>
                            ${locked && r.verified_by ? `<span>verificado por <b>${esc(r.verified_by)}</b></span>` : ''}
                        </div>
                    </div>
                    <div class="pc-row-actions">
                        ${r.pista_id ? `<button class="pc-btn pc-btn--sm ${locked ? '' : 'pc-btn--warn'}" data-act="lock" data-pista="${attr(r.pista_id)}" data-locked="${locked ? 0 : 1}" title="${locked ? 'Quitar verificación (el autor podrá volver a editar)' : 'Verificar: bloquea la edición del autor'}"><i class="fas ${locked ? 'fa-lock-open' : 'fa-certificate'}"></i> ${locked ? 'Quitar verif.' : 'Verificar'}</button>` : ''}
                        ${reported && !locked ? `<button class="pc-btn pc-btn--sm pc-btn--cyan" data-act="edit-allowed" data-id="${attr(r.id)}" data-allowed="${editAllowed === true ? 0 : 1}" title="Autoriza o bloquea que el autor corrija su aporte mientras está reportado"><i class="fas fa-pen-to-square"></i> ${editAllowed === true ? 'Bloquear edición' : 'Permitir corregir'}</button>` : ''}
                        ${edits ? `<button class="pc-btn pc-btn--sm pc-btn--purple" data-act="edits" data-id="${attr(r.id)}"><i class="fas fa-clock-rotate-left"></i> Historial</button>` : ''}
                        <button class="pc-btn pc-btn--sm pc-btn--danger" data-act="remove" data-id="${attr(r.id)}" title="Retira el vídeo y revierte los Bananos. El usuario sigue activo."><i class="fas fa-trash"></i> Retirar</button>
                        <button class="pc-btn pc-btn--sm pc-btn--danger" data-act="remove-ban" data-id="${attr(r.id)}" title="Retira el vídeo, revierte los Bananos y suspende al usuario"><i class="fas fa-user-slash"></i> Retirar + suspender</button>
                    </div>
                </div>`;
            }).join('');
        },
        reports(rows) {
            return rows.map(r => {
                const open = r.status === 'open';
                const badge = open ? '<span class="pc-pill pc-pill--danger"><i class="fas fa-flag"></i> Abierto</span>'
                    : r.status === 'upheld' ? '<span class="pc-pill pc-pill--good"><i class="fas fa-check"></i> Confirmado</span>' : '<span class="pc-pill">Desestimado</span>';
                const isCommunity = !!r.submission_id;
                const editAllowed = r.edit_allowed === null || r.edit_allowed === undefined ? null : Number(r.edit_allowed) === 1;
                return `
                <div class="pc-row">
                    ${thumb(r)}
                    <div class="min-w-0">
                        <div class="pc-row-title">${esc(r.track_name)} ${badge}
                            ${Number(r.reward_paid) === 1 ? `<span class="pc-pill pc-pill--accent">🍌 ${fmtNum(r.reward_amount)} pagados</span>` : ''}
                            ${isCommunity ? '' : '<span class="pc-pill pc-pill--cyan"><i class="fas fa-crown"></i> Contenido del panel</span>'}
                            ${isCommunity && r.submission_status !== 'published' ? `<span class="pc-pill">Aporte ${r.submission_status === 'replaced' ? 'reemplazado' : r.submission_status === 'deleted' ? 'eliminado por el autor' : 'retirado'}</span>` : ''}
                            ${open && isCommunity && editAllowed === true ? '<span class="pc-pill pc-pill--cyan">Edición autorizada</span>' : ''}
                        </div>
                        <div class="pc-row-meta">
                            <span>Motivo: <b>${esc(reasonLabel(r.reason))}</b></span>
                            <span>reporta ${userChip(r.reporter_name, r.reporter_banned, r.reporter_id)}</span>
                            ${isCommunity ? `<span>autor ${userChip(r.uploader_name, r.uploader_banned, r.uploader_id)}</span>` : ''}
                            <span><i class="far fa-clock"></i> ${fmtDate(r.created_at)}</span>
                            ${r.reviewed_by ? `<span>revisado por <b>${esc(r.reviewed_by)}</b> · ${fmtDate(r.reviewed_at)}</span>` : ''}
                        </div>
                    </div>
                    <div class="pc-row-actions">
                        ${open ? `
                            <button class="pc-btn pc-btn--sm pc-btn--good" data-act="uphold" data-id="${attr(r.id)}" title="Paga al reportero, retira el contenido y revierte los Bananos del autor"><i class="fas fa-check"></i> Confirmar</button>
                            ${isCommunity && r.submission_status === 'published' ? `<button class="pc-btn pc-btn--sm pc-btn--cyan" data-act="edit-allowed" data-id="${attr(r.submission_id)}" data-allowed="${editAllowed === true ? 0 : 1}"><i class="fas fa-pen-to-square"></i> ${editAllowed === true ? 'Bloquear edición' : 'Permitir corregir'}</button>` : ''}
                            <button class="pc-btn pc-btn--sm" data-act="dismiss" data-id="${attr(r.id)}"><i class="fas fa-times"></i> Desestimar</button>
                            <button class="pc-btn pc-btn--sm pc-btn--danger" data-act="dismiss-ban" data-id="${attr(r.id)}" title="Desestima y suspende al reportero por reporte malintencionado"><i class="fas fa-user-slash"></i> Desestimar + suspender</button>`
                        : `<button class="pc-btn pc-btn--sm" data-act="reopen" data-id="${attr(r.id)}" title="Reabre el reporte; si se pagó la recompensa, se revierte"><i class="fas fa-undo"></i> Reabrir</button>`}
                    </div>
                </div>`;
            }).join('');
        },
        rejected(rows) {
            return rows.map(r => `
                <div class="pc-row">
                    ${thumb(r, 'youtube')}
                    <div class="min-w-0">
                        <div class="pc-row-title">${esc(r.track_name)} ${subStatusPill(r.status)}</div>
                        <div class="pc-row-meta">
                            <span>${ytLink(r.youtube_id)}</span><span>por ${userChip(r.user_name, r.user_banned, r.user_id)}</span>
                            <span>motivo: <b>${esc(r.reject_reason || '—')}</b></span>
                            <span>${esc(r.reviewed_by || '—')} · ${fmtDate(r.reviewed_at)}</span>
                            <span>${r.status === 'replaced' ? `conserva <b>${fmtNum(r.reward_amount)}</b> 🍌` : `<b>${fmtNum(r.reward_amount)}</b> 🍌 revertidos`}</span>
                        </div>
                    </div>
                    <div class="pc-row-actions">
                        <button class="pc-btn pc-btn--sm pc-btn--good" data-act="restore" data-id="${attr(r.id)}" data-banned="${r.user_banned ? 1 : 0}" title="Vuelve a publicar el vídeo y devuelve los Bananos que falten"><i class="fas fa-undo"></i> Restaurar</button>
                    </div>
                </div>`).join('');
        },
        locked(rows) {
            return rows.map(r => `
                <div class="pc-row">
                    ${thumb(r)}
                    <div class="min-w-0">
                        <div class="pc-row-title">${esc(r.nombre_pista)} <span class="pc-pill pc-pill--accent"><i class="fas fa-certificate"></i> Verificado</span>
                            <span class="pc-pill ${r.submission_id ? '' : 'pc-pill--cyan'}"><i class="${kindIcon(r.media_type)}"></i> ${kindLabel(r.media_type)} · ${r.submission_id ? esc(r.user_name || 'comunidad') : 'panel'}</span></div>
                        <div class="pc-row-meta">
                            ${r.youtube_id ? `<span>${ytLink(r.youtube_id)}</span>` : ''}<span>inicio <b>${fmtSecs(r.start_time)}</b></span>
                            <span><i class="far fa-clock"></i> publicado ${fmtDate(r.published_at)}</span>
                            <span>verificado ${fmtDate(r.verified_at)}${r.verified_by ? ` por <b>${esc(r.verified_by)}</b>` : ''}</span>
                        </div>
                    </div>
                    <div class="pc-row-actions">
                        <button class="pc-btn pc-btn--sm" data-act="lock" data-pista="${attr(r.id)}" data-locked="0"><i class="fas fa-lock-open"></i> Revertir verificación</button>
                    </div>
                </div>`).join('');
        },
        system(rows) {
            return rows.map(r => {
                const locked = Number(r.is_locked) === 1;
                return `
                <div class="pc-row">
                    ${thumb(r)}
                    <div class="min-w-0">
                        <div class="pc-row-title">${esc(r.nombre_pista)}
                            <span class="pc-pill pc-pill--cyan"><i class="${kindIcon(r.media_type)}"></i> ${kindLabel(r.media_type)}</span>
                            ${locked ? '<span class="pc-pill pc-pill--accent"><i class="fas fa-certificate"></i> Verificado</span>' : ''}
                            ${Number(r.open_reports) > 0 ? `<span class="pc-pill pc-pill--danger"><i class="fas fa-flag"></i> ${plural(r.open_reports, 'reporte', 'reportes')}</span>` : ''}
                        </div>
                        <div class="pc-row-meta">
                            ${r.youtube_id ? `<span>${ytLink(r.youtube_id)}</span>` : `<span class="pc-mono">${esc(r.media_file || '')}</span>`}
                            <span>inicio <b>${fmtSecs(r.start_time)}</b></span>
                            <span><i class="far fa-clock"></i> ${fmtDate(r.published_at)}</span>${r.verified_by ? `<span>por <b>${esc(r.verified_by)}</b></span>` : ''}
                        </div>
                    </div>
                    <div class="pc-row-actions">
                        <button class="pc-btn pc-btn--sm ${locked ? '' : 'pc-btn--warn'}" data-act="lock" data-pista="${attr(r.id)}" data-locked="${locked ? 0 : 1}"><i class="fas ${locked ? 'fa-lock-open' : 'fa-certificate'}"></i> ${locked ? 'Quitar verif.' : 'Verificar'}</button>
                        <button class="pc-btn pc-btn--sm pc-btn--danger" data-act="remove-system" data-pista="${attr(r.id)}" data-name="${attr(r.nombre_pista)}"><i class="fas fa-trash"></i> Retirar contenido</button>
                    </div>
                </div>`;
            }).join('');
        },
        bans(rows) {
            return rows.map(r => `
                <div class="pc-row" style="grid-template-columns: 3rem minmax(0,1fr) auto">
                    <div class="pc-thumb" style="width:3rem;aspect-ratio:1;border-radius:999px;color:var(--pc-danger)"><i class="fas fa-user-slash"></i></div>
                    <div class="min-w-0">
                        <div class="pc-row-title">${esc(r.user_name || 'Sin nombre')} <span class="pc-pill pc-pill--danger">Suspendido</span> <span class="pc-pill">${plural(r.rejected_count, 'vídeo retirado', 'vídeos retirados')}</span></div>
                        <div class="pc-row-meta"><span>Motivo: <b>${esc(r.reason || '—')}</b></span><span>por <b>${esc(r.banned_by || '—')}</b></span><span><i class="far fa-clock"></i> ${fmtDate(r.created_at)}</span><span class="pc-mono">ID ${esc(r.user_id)}</span></div>
                    </div>
                    <div class="pc-row-actions">
                        <button class="pc-btn pc-btn--sm pc-btn--good" data-act="unban" data-user="${attr(r.user_id)}"><i class="fas fa-user-check"></i> Reactivar</button>
                    </div>
                </div>`).join('');
        },
        edits(rows) {
            return rows.map(r => `
                <div class="pc-row" style="grid-template-columns: 3rem minmax(0,1fr) auto">
                    <div class="pc-thumb" style="width:3rem;aspect-ratio:1;border-radius:999px;color:var(--pc-purple)"><i class="fas fa-pen"></i></div>
                    <div class="min-w-0">
                        <div class="pc-row-title">${esc(r.track_name)} <span class="pc-pill pc-pill--purple">${r.edited_by === 'admin' ? 'Admin' : 'Autor'}: ${esc(r.editor_name || r.user_name || '—')}</span> ${r.track_state === 'deleted' ? '<span class="pc-pill pc-pill--danger"><i class="fas fa-trash"></i> Eliminado</span>' : `<span class="pc-pill">${esc(r.track_state)}</span>`}</div>
                        <div class="pc-row-meta">
                            <span>${editDiff(r)}</span>
                            <span><i class="far fa-clock"></i> ${fmtDate(r.created_at)}</span>
                        </div>
                    </div>
                    <div class="pc-row-actions"><button class="pc-btn pc-btn--sm pc-btn--purple" data-act="edits" data-id="${attr(r.submission_id)}"><i class="fas fa-clock-rotate-left"></i> Todo el historial</button></div>
                </div>`).join('');
        },
    };
    const EMPTY = {
        published: 'No hay vídeos publicados por la comunidad.', reports: 'No hay reportes.', locked: 'Aún no hay pistas verificadas.',
        rejected: 'No hay aportes retirados.', system: 'El panel aún no ha subido contenido propio.', bans: 'No hay usuarios suspendidos.', edits: 'Nadie ha editado su aporte todavía.',
    };
    const reasonLabel = code => state.data?.report_reasons.find(r => r.code === code)?.label || code;
    function renderMod() {
        const d = state.data; if (!d) return;
        const q = ($('#pcModSearch').value || '').trim().toLowerCase();
        let rows = d[state.modList] || [];
        if (q) rows = rows.filter(r => JSON.stringify(Object.values(r)).toLowerCase().includes(q));
        $('#pcModList').innerHTML = rows.length ? renderers[state.modList](rows)
            : `<div class="pc-empty"><i class="fas fa-inbox"></i>${q ? 'Sin resultados para esa búsqueda.' : EMPTY[state.modList]}</div>`;
    }

    function onModClick(e) {
        const chip = e.target.closest('[data-user-open]');
        if (chip) { openUser(chip.dataset.userOpen); return; }
        const btn = e.target.closest('button[data-act]'); if (!btn) return;
        const { act, id, pista, user, locked, allowed, banned, name } = btn.dataset;
        switch (act) {
            case 'lock':
                if (locked === '0' && !confirm('¿Quitar la verificación? El autor podrá volver a editar su aporte.')) return;
                run('set_lock', { pista_id: pista, locked }); break;
            case 'edit-allowed':
                run('set_edit_allowed', { id, allowed }); break;
            case 'edits':
                openEdits(id); break;
            case 'remove':
                openAction({
                    title: 'Retirar sin suspender', icon: 'fa-trash', danger: true, reason: true, placeholder: 'Vídeo incorrecto o inapropiado',
                    hint: 'Se quita el vídeo de la pista y se revierten sus Bananos al instante. El usuario <b>sigue activo</b> y puede volver a aportar.',
                    onConfirm: v => run('remove_submission', { id, reason: v.reason }),
                }); break;
            case 'remove-ban':
                openAction({
                    title: 'Retirar y suspender', icon: 'fa-user-slash', danger: true, reason: true, placeholder: 'Vídeo incorrecto o inapropiado',
                    hint: 'Se quita el vídeo, se revierten sus Bananos y el usuario queda <b>suspendido</b> del sistema de vídeos (podrás reactivarlo después).',
                    onConfirm: v => run('reject_submission', { id, reason: v.reason }),
                }); break;
            case 'remove-system':
                openAction({
                    title: 'Retirar contenido del panel', icon: 'fa-trash', danger: true,
                    hint: `Se elimina el contenido de <b>${esc(name)}</b> (y el archivo del servidor si era audio/vídeo directo). No afecta a ningún usuario.`,
                    onConfirm: () => run('remove_track_media', { pista_id: pista }),
                }); break;
            case 'uphold':
                openUphold(id); break;
            case 'dismiss':
                run('resolve_report', { id, verdict: 'dismiss' }); break;
            case 'dismiss-ban':
                openAction({
                    title: 'Desestimar y suspender al reportero', icon: 'fa-user-slash', danger: true,
                    hint: 'El reporte se desestima y el reportero queda suspendido del sistema de vídeos por reporte malintencionado.',
                    onConfirm: () => run('resolve_report', { id, verdict: 'dismiss', ban_reporter: '1' }),
                }); break;
            case 'reopen':
                if (confirm('¿Reabrir el reporte? Si la recompensa ya fue pagada, se revertirá.')) run('reopen_report', { id }); break;
            case 'restore':
                openAction({
                    title: 'Restaurar aporte', icon: 'fa-undo', good: true,
                    hint: 'El vídeo vuelve a publicarse en la pista (si está libre) y se devuelven los Bananos que falten.',
                    extra: banned === '1' ? 'Reactivar también al usuario suspendido' : null, extraDefault: true,
                    onConfirm: v => run('restore_submission', { id, unban: v.extra ? '1' : '0' }),
                }); break;
            case 'unban':
                if (confirm('¿Reactivar el acceso de este usuario al sistema de vídeos?')) run('unban_user', { user_id: user }); break;
        }
    }

    // Confirmar un reporte: quién cobra, a quién se le descuenta (lo que aún
    // conserva de la recompensa) y la opción de suspender al autor.
    function openUphold(reportId) {
        const r = (state.data?.reports || []).find(x => String(x.id) === String(reportId));
        if (!r) return;
        const isCommunity = !!r.submission_id;
        const stillPublished = isCommunity && r.submission_status === 'published';
        const net = Math.max(0, Number(r.uploader_reward_net) || 0);
        const cards = `
            <div class="pc-verdict">
                <div class="pc-verdict--good"><small>Cobra el reportero</small><b class="pc-num">+${fmtNum(r.reward_amount)} 🍌</b><span>${esc(r.reporter_name || 'Sin nombre')}</span></div>
                ${isCommunity
                    ? `<div class="pc-verdict--danger"><small>Se descuenta al autor</small><b class="pc-num">−${fmtNum(net)} 🍌</b><span>${esc(r.uploader_name || 'Sin nombre')}${Number(r.uploader_banned) ? ' · ya suspendido' : ''}</span></div>`
                    : `<div><small>Contenido</small><b>Del panel</b><span>Se retira sin afectar a ningún usuario</span></div>`}
            </div>`;
        const note = isCommunity
            ? (stillPublished
                ? `El vídeo de <b>${esc(r.track_name)}</b> se retira y su recompensa se revierte al instante.${net === 0 ? ' El autor ya no conserva Bananos de esta pista, así que no se le descontará nada.' : ''}`
                : `El aporte ya no está publicado (${esc(SUB_STATUS[r.submission_status]?.[0] || r.submission_status)}). Solo se paga al reportero${net > 0 ? ' y se revierten los Bananos pendientes' : ''}.`)
            : `Se retira el contenido de <b>${esc(r.track_name)}</b> subido desde el panel.`;
        openAction({
            title: 'Confirmar reporte', icon: 'fa-check', good: true,
            hint: cards + note,
            extra: isCommunity && !Number(r.uploader_banned) ? 'Suspender también al autor del vídeo (pierde el acceso al sistema de vídeos)' : null,
            onConfirm: async v => {
                const json = await run('resolve_report', { id: reportId, verdict: 'uphold', ban_uploader: v.extra ? '1' : '0' });
                if (json) showVerdict(json, r);
            },
        });
    }
    // Resultado real tras la transacción: lo pagado y lo debitado de verdad.
    function showVerdict(json, r) {
        const paid = Number(json.reward_paid_amount) || 0, reversed = Number(json.reward_reversed_amount) || 0;
        const isCommunity = json.uploader_id !== null && json.uploader_id !== undefined;
        $('#pcVerdictBody').innerHTML = `
            <div class="pc-verdict">
                <div class="pc-verdict--good"><small>Pagado al reportero</small><b class="pc-num">+${fmtNum(paid)} 🍌</b><span>${esc(r.reporter_name || 'Sin nombre')}</span></div>
                ${isCommunity
                    ? `<div class="pc-verdict--danger"><small>Descontado al autor</small><b class="pc-num">−${fmtNum(reversed)} 🍌</b><span>${esc(r.uploader_name || 'Sin nombre')}</span></div>`
                    : `<div><small>Contenido del panel</small><b>Retirado</b><span>${esc(r.track_name)}</span></div>`}
            </div>
            <div class="flex flex-wrap gap-2">
                ${json.content_removed ? '<span class="pc-pill pc-pill--danger"><i class="fas fa-trash"></i> Contenido retirado</span>' : '<span class="pc-pill">El contenido ya no estaba publicado</span>'}
                ${isCommunity ? (json.uploader_banned ? '<span class="pc-pill pc-pill--danger"><i class="fas fa-user-slash"></i> Autor suspendido</span>' : '<span class="pc-pill pc-pill--good"><i class="fas fa-user-check"></i> Autor sigue activo</span>') : ''}
            </div>
            <p class="pc-help mt-3">${esc(json.message || '')}</p>
            ${isCommunity ? `<button type="button" class="pc-btn pc-btn--sm pc-btn--purple mt-3" onclick="closeModal('pcVerdictModal'); openUser(${Number(json.uploader_id)})"><i class="fas fa-id-card"></i> Ver ficha del autor</button>` : ''}`;
        $('#pcVerdictModal').classList.add('is-on');
    }

    function openAction(cfg) {
        state.action = cfg;
        $('#pcActionTitle').innerHTML = `<i class="fas ${cfg.icon || 'fa-gavel'}"></i> ${esc(cfg.title)}`;
        $('#pcActionHint').innerHTML = cfg.hint || '';
        $('#pcActionReasonWrap').classList.toggle('hidden', !cfg.reason);
        $('#pcActionReason').value = ''; $('#pcActionReason').placeholder = cfg.placeholder || '';
        $('#pcActionExtraWrap').classList.toggle('hidden', !cfg.extra);
        $('#pcActionExtraLabel').textContent = cfg.extra || '';
        $('#pcActionExtra').checked = !!cfg.extraDefault;
        const btn = $('#pcActionConfirm');
        btn.className = `pc-btn ${cfg.good ? 'pc-btn--good' : cfg.danger ? 'pc-btn--danger' : 'pc-btn--primary'}`;
        $('#pcActionModal').classList.add('is-on');
        setTimeout(() => (cfg.reason ? $('#pcActionReason') : btn).focus(), 30);
    }
    function closeAction() { state.action = null; closeModal('pcActionModal'); }
    async function confirmAction() {
        const cfg = state.action; if (!cfg) return;
        const btn = $('#pcActionConfirm'); btn.disabled = true;
        try { await cfg.onConfirm({ reason: $('#pcActionReason').value.trim(), extra: $('#pcActionExtra').checked }); }
        finally { btn.disabled = false; closeAction(); }
    }
    async function openEdits(submissionId) {
        const body = $('#pcEditsBody'); body.innerHTML = '<div class="pc-skeleton"></div>';
        $('#pcEditsModal').classList.add('is-on');
        try {
            const json = await apiGet('edits', { id: submissionId });
            const rows = json.edits || [];
            body.innerHTML = rows.length ? rows.map(r => `
                <div class="pc-edit">
                    <span class="pc-pill ${r.edited_by === 'admin' ? 'pc-pill--cyan' : 'pc-pill--purple'}">${r.edited_by === 'admin' ? 'Admin' : 'Autor'}</span>
                    <div><b>${esc(r.editor_name || '—')}</b> · ${fmtDate(r.created_at)} · estado <i>${esc(r.track_state)}</i></div>
                    <span></span>
                    <div>${editDiff(r)}</div>
                </div>`).join('') : '<div class="pc-empty"><i class="fas fa-inbox"></i>Sin ediciones registradas.</div>';
        } catch (e) { body.innerHTML = `<div class="pc-error is-on">${esc(e.message)}</div>`; }
    }
    function openMedia(kind, src, start) {
        const body = $('#pcMediaBody');
        if (kind === 'youtube') body.innerHTML = `<div class="pc-preview pc-preview--16x9"><iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(src)}?start=${Number(start) || 0}&rel=0&autoplay=1" title="Vista previa" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>`;
        else if (kind === 'video') body.innerHTML = `<div class="pc-preview pc-preview--16x9"><video src="${attr(src)}" controls autoplay playsinline></video></div>`;
        else body.innerHTML = `<div class="pc-preview"><audio src="${attr(src)}" controls autoplay></audio></div>`;
        $('#pcMediaModal').classList.add('is-on');
    }

    // ---------- 4. Usuarios / colaboradores ----------
    async function loadUsers(force = false) {
        if (!force && state.users && Date.now() - state.usersLoadedAt < 60000) { renderUsers(); return; }
        try {
            const json = await apiGet('users');
            state.users = json.users || []; state.usersLoadedAt = Date.now();
            renderUsers();
        } catch (e) {
            if (!state.users) $('#pcUserList').innerHTML = `<div class="pc-error is-on">${esc(e.message)}</div>`;
            else toast(e.message, 'error');
        }
    }
    const userFilters = {
        all: () => true,
        active: u => Number(u.published_count) > 0,
        edited: u => Number(u.edits_count) > 0,
        reported: u => Number(u.reports_received) > 0,
        rejected: u => Number(u.rejected_count) + Number(u.deleted_count) > 0,
        reporters: u => Number(u.reports_made) > 0,
        banned: u => Number(u.is_banned) === 1,
    };
    const userSorters = {
        recent: (a, b) => String(b.last_submission_at || '').localeCompare(String(a.last_submission_at || '')) || b.user_id - a.user_id,
        coins: (a, b) => Number(b.coins_net) - Number(a.coins_net),
        published: (a, b) => Number(b.published_count) - Number(a.published_count),
        edits: (a, b) => Number(b.edits_count) - Number(a.edits_count),
        reports: (a, b) => Number(b.reports_received) - Number(a.reports_received),
        name: (a, b) => String(a.user_name || '').localeCompare(String(b.user_name || ''), 'es'),
    };
    function setUserList(key) { state.userList = key; renderUsers(); }
    function renderUsers() {
        const all = state.users; if (!all) return;
        $('#pcTabUsers').textContent = fmtNum(all.length);
        const c = {}; for (const l of USER_LISTS) c[l.key] = all.filter(userFilters[l.key]).length;
        const card = (label, value, icon, foot, key, cls = '') => `
            <button type="button" onclick="setUserList('${key}')" class="pc-card pc-card--pad pc-stat ${state.userList === key ? 'is-on' : ''} ${cls}">
                <p class="pc-card-label"><i class="fas ${icon}"></i> ${label}</p>
                <p class="pc-card-value pc-num">${fmtNum(value)}</p>
                <p class="pc-card-foot">${foot}</p>
            </button>`;
        const coins = all.reduce((s, u) => s + Number(u.coins_net || 0), 0);
        $('#pcUserStats').innerHTML =
            card('Colaboradores', c.all, 'fa-users', `${fmtNum(coins)} 🍌 netos repartidos`, 'all') +
            card('Con publicados', c.active, 'fa-circle-play', 'Tienen vídeos activos', 'active') +
            card('Han editado', c.edited, 'fa-pen', 'Cambiaron su vídeo tras cobrar', 'edited') +
            card('Reportados', c.reported, 'fa-flag', 'Con al menos un reporte recibido', 'reported', c.reported ? 'pc-hero--danger' : '') +
            card('Reporteros', c.reporters, 'fa-bullhorn', 'Han enviado reportes', 'reporters') +
            card('Suspendidos', c.banned, 'fa-user-slash', 'Sin acceso al sistema', 'banned');
        $('#pcUserChips').innerHTML = USER_LISTS.map(l => `
            <button class="pc-chip ${l.danger ? 'pc-chip--danger' : ''}" aria-selected="${String(state.userList === l.key)}" onclick="setUserList('${l.key}')"><i class="fas ${l.icon}"></i> ${l.label} <span class="pc-count">${fmtNum(c[l.key] || 0)}</span></button>`).join('');

        const q = ($('#pcUserSearch').value || '').trim().toLowerCase();
        const rows = all.filter(userFilters[state.userList] || userFilters.all)
            .filter(u => !q || [u.user_name, u.game_username, u.public_code, u.user_id].some(v => String(v ?? '').toLowerCase().includes(q)))
            .sort(userSorters[$('#pcUserSort').value] || userSorters.recent);
        $('#pcUsersCount').textContent = plural(rows.length, 'usuario', 'usuarios');
        const stat = (v, label, cls = '') => `<span class="pc-user-stat ${cls}"><b>${fmtNum(v)}</b><small>${label}</small></span>`;
        $('#pcUserList').innerHTML = rows.length ? rows.map(u => `
            <button type="button" class="pc-user-row ${Number(u.is_banned) ? 'is-banned' : ''}" data-user-open="${attr(u.user_id)}">
                ${avatarImg(u.avatar, u.user_name)}
                <div class="min-w-0">
                    <div class="pc-row-title">${esc(u.user_name || 'Sin nombre')}
                        ${Number(u.is_verified) ? '<span class="pc-pill pc-pill--cyan"><i class="fas fa-check"></i> Verificado</span>' : ''}
                        ${Number(u.is_banned) ? '<span class="pc-pill pc-pill--danger"><i class="fas fa-user-slash"></i> Suspendido</span>' : ''}
                        ${u.account_status && u.account_status !== 'active' ? `<span class="pc-pill">Cuenta: ${esc(u.account_status)}</span>` : ''}
                        ${Number(u.reports_open) ? `<span class="pc-pill pc-pill--danger"><i class="fas fa-flag"></i> ${plural(u.reports_open, 'reporte abierto', 'reportes abiertos')}</span>` : ''}
                    </div>
                    <div class="pc-row-meta">
                        <span>ID <b>${esc(u.user_id)}</b></span>
                        ${u.game_username ? `<span>juego <b>${esc(u.game_username)}</b></span>` : ''}
                        ${u.public_code ? `<span>código <b>${esc(u.public_code)}</b></span>` : ''}
                        <span>saldo <b>${fmtNum(u.balance)}</b> 🍌</span>
                        <span title="Último aporte"><i class="far fa-clock"></i> ${fmtDate(u.last_submission_at)}</span>
                    </div>
                </div>
                <div class="pc-user-stats">
                    ${stat(u.published_count, 'Activos', 'pc-user-stat--good')}
                    ${stat(u.total_submissions, 'Aportes')}
                    ${stat(u.edits_count, 'Ediciones', 'pc-user-stat--purple')}
                    ${stat(u.reports_received, 'Reportes', Number(u.reports_received) ? 'pc-user-stat--danger' : '')}
                    ${stat(Number(u.rejected_count) + Number(u.deleted_count), 'Retirados', Number(u.rejected_count) + Number(u.deleted_count) ? 'pc-user-stat--danger' : '')}
                    ${stat(u.coins_net, '🍌 netos', 'pc-user-stat--accent')}
                </div>
            </button>`).join('') : '<div class="pc-empty"><i class="fas fa-inbox"></i>Ningún usuario coincide con el filtro.</div>';
    }
    async function openUser(userId, silent = false) {
        const body = $('#pcUserBody');
        if (!silent) { body.innerHTML = '<div class="pc-skeleton"></div>'; state.userTab = 'submissions'; }
        $('#pcUserModal').classList.add('is-on');
        try {
            const json = await apiGet('user_detail', { user_id: userId });
            state.userDetail = json;
            renderUserDetail();
        } catch (e) { body.innerHTML = `<div class="pc-error is-on">${esc(e.message)}</div>`; }
    }
    function setUserTab(key) { state.userTab = key; renderUserDetail(); }
    function renderUserDetail() {
        const d = state.userDetail; if (!d) return;
        const u = d.user, t = d.totals, banned = Number(u.is_banned) === 1;
        const kv = (label, value, cls = '') => `<div><dt>${label}</dt><dd class="pc-num ${cls}">${value}</dd></div>`;
        const tabs = [
            ['submissions', 'Aportes', 'fa-circle-play', d.submissions.length],
            ['edits', 'Ediciones', 'fa-clock-rotate-left', d.edits.length],
            ['reports_received', 'Reportes recibidos', 'fa-flag', d.reports_received.length],
            ['reports_made', 'Reportes enviados', 'fa-bullhorn', d.reports_made.length],
            ['ledger', 'Movimientos 🍌', 'fa-coins', d.ledger.length],
        ];
        const active = d.submissions.filter(s => s.status === 'published').length;
        $('#pcUserBody').innerHTML = `
            <div class="pc-profile ${banned ? 'is-banned' : ''}">
                ${avatarImg(u.avatar, u.user_name)}
                <div class="min-w-0">
                    <div class="pc-profile-name pc-heavy">${esc(u.user_name || 'Sin nombre')}
                        ${Number(u.is_verified) ? '<span class="pc-pill pc-pill--cyan"><i class="fas fa-check"></i> Verificado</span>' : ''}
                        ${banned ? '<span class="pc-pill pc-pill--danger"><i class="fas fa-user-slash"></i> Suspendido</span>' : '<span class="pc-pill pc-pill--good">Acceso activo</span>'}
                    </div>
                    <div class="pc-profile-meta">
                        <span>ID <b>${esc(u.user_id)}</b></span>
                        ${u.game_username ? `<span>juego <b>${esc(u.game_username)}</b></span>` : ''}
                        ${u.public_code ? `<span>código <b>${esc(u.public_code)}</b></span>` : ''}
                        <span>cuenta <b>${esc(u.account_status || '—')}</b></span>
                        <span>registro <b>${fmtDate(u.registered_at, false)}</b></span>
                        <span>último acceso <b>${fmtDate(u.last_login_at)}</b></span>
                        <span>saldo actual <b>${fmtNum(u.balance)} 🍌</b></span>
                    </div>
                    ${banned ? `<div class="pc-profile-meta" style="color:var(--pc-danger)"><span><i class="fas fa-gavel"></i> Suspendido por <b>${esc(u.banned_by || '—')}</b> el ${fmtDate(u.banned_at)}: <b>${esc(u.ban_reason || 'Sin motivo')}</b></span></div>` : ''}
                </div>
                <div class="pc-profile-actions">
                    ${banned
                        ? `<button class="pc-btn pc-btn--sm pc-btn--good" data-uact="unban"><i class="fas fa-user-check"></i> Reactivar acceso</button>`
                        : `<button class="pc-btn pc-btn--sm pc-btn--danger" data-uact="ban"><i class="fas fa-user-slash"></i> Suspender</button>`}
                    ${active ? `<button class="pc-btn pc-btn--sm pc-btn--warn" data-uact="takedown-all"><i class="fas fa-trash"></i> Retirar ${plural(active, 'vídeo activo', 'vídeos activos')}</button>` : ''}
                </div>
            </div>
            <dl class="pc-kv mb-4">
                ${kv('Aportes', fmtNum(t.submissions))}
                ${kv('Publicados', fmtNum(t.published), 'text-green-500')}
                ${kv('Verificados', fmtNum(t.verified))}
                ${kv('Retirados / eliminados', `${fmtNum(t.rejected)} / ${fmtNum(t.deleted)}`, t.rejected + t.deleted ? 'text-red-500' : '')}
                ${kv('Ediciones del autor', fmtNum(t.edits), t.edits ? 'text-purple-400' : '')}
                ${kv('🍌 ganados por vídeos', `+${fmtNum(t.coins_earned)}`, 'text-green-500')}
                ${kv('🍌 revertidos', `−${fmtNum(t.coins_reversed)}`, t.coins_reversed ? 'text-red-500' : '')}
                ${kv('🍌 por reportar', `+${fmtNum(t.coins_reports)}`)}
                ${kv('🍌 netos del módulo', fmtNum(t.coins_net), 'text-amber-400')}
                ${kv('Reportes recibidos', `${fmtNum(t.reports_received)} <small style="font-size:.7rem;color:var(--pc-subtle)">(${fmtNum(t.reports_upheld)} confirmados · ${fmtNum(t.reports_open)} abiertos)</small>`, t.reports_upheld ? 'text-red-500' : '')}
                ${kv('Reportes enviados', `${fmtNum(t.reports_made)} <small style="font-size:.7rem;color:var(--pc-subtle)">(${fmtNum(t.reports_made_upheld)} confirmados · ${fmtNum(t.reports_made_dismissed)} desestimados)</small>`)}
            </dl>
            <div class="pc-subtabs" role="tablist">${tabs.map(([k, l, i, n]) => `<button class="pc-chip" aria-selected="${String(state.userTab === k)}" onclick="setUserTab('${k}')"><i class="fas ${i}"></i> ${l} <span class="pc-count">${fmtNum(n)}</span></button>`).join('')}</div>
            <div class="pc-card">${userTabRenderers[state.userTab]()}</div>`;
    }
    const userTabRenderers = {
        submissions() {
            const rows = state.userDetail.submissions;
            return rows.length ? rows.map(s => `
                <div class="pc-row">
                    ${thumb(s, 'youtube')}
                    <div class="min-w-0">
                        <div class="pc-row-title">${esc(s.track_name)} ${subStatusPill(s.status)}
                            ${s.status === 'published' && Number(s.is_locked) ? '<span class="pc-pill pc-pill--accent"><i class="fas fa-certificate"></i> Verificado</span>' : ''}
                            ${Number(s.open_reports) ? `<span class="pc-pill pc-pill--danger"><i class="fas fa-flag"></i> ${plural(s.open_reports, 'reporte abierto', 'reportes abiertos')}</span>` : ''}
                            ${Number(s.edits) ? `<span class="pc-pill pc-pill--purple"><i class="fas fa-pen"></i> ${plural(s.edits, 'edición', 'ediciones')}</span>` : ''}
                        </div>
                        <div class="pc-row-meta">
                            <span>${s.youtube_id ? ytLink(s.youtube_id, s.video_title || s.youtube_id) : '—'}</span><span>inicio <b>${fmtSecs(s.start_seconds)}</b></span>
                            <span>recompensa <b>${fmtNum(s.reward_amount)}</b> 🍌 · conserva <b class="${Number(s.reward_net) > 0 ? 'text-green-500' : ''}">${fmtNum(s.reward_net)}</b> 🍌</span>
                            <span><i class="far fa-clock"></i> ${fmtDate(s.created_at)}</span>
                            ${s.reject_reason ? `<span>motivo: <b>${esc(s.reject_reason)}</b>${s.reviewed_by ? ` (${esc(s.reviewed_by)})` : ''}</span>` : ''}
                        </div>
                    </div>
                    <div class="pc-row-actions">
                        ${Number(s.edits) ? `<button class="pc-btn pc-btn--sm pc-btn--purple" onclick="openEdits(${Number(s.id)})"><i class="fas fa-clock-rotate-left"></i> Historial</button>` : ''}
                        ${s.status === 'published' ? `<button class="pc-btn pc-btn--sm pc-btn--danger" data-uact="remove" data-id="${attr(s.id)}" data-name="${attr(s.track_name)}"><i class="fas fa-trash"></i> Retirar</button>` : ''}
                        ${['rejected', 'replaced', 'deleted'].includes(s.status) ? `<button class="pc-btn pc-btn--sm pc-btn--good" data-uact="restore" data-id="${attr(s.id)}"><i class="fas fa-undo"></i> Restaurar</button>` : ''}
                    </div>
                </div>`).join('') : '<div class="pc-empty"><i class="fas fa-inbox"></i>Este usuario no ha publicado nada.</div>';
        },
        edits() {
            const rows = state.userDetail.edits;
            return rows.length ? `<div class="p-3">${rows.map(r => `
                <div class="pc-edit">
                    <span class="pc-pill ${r.edited_by === 'admin' ? 'pc-pill--cyan' : 'pc-pill--purple'}">${r.edited_by === 'admin' ? 'Admin' : 'Autor'}</span>
                    <div><b>${esc(r.track_name)}</b> · ${esc(r.editor_name || '—')} · ${fmtDate(r.created_at)} · estado <i>${esc(r.track_state)}</i></div>
                    <span></span>
                    <div>${editDiff(r)}</div>
                </div>`).join('')}</div>` : '<div class="pc-empty"><i class="fas fa-inbox"></i>Sin ediciones registradas.</div>';
        },
        reports_received() {
            const rows = state.userDetail.reports_received;
            return rows.length ? rows.map(r => reportLine(r, `reporta ${userChip(r.reporter_name, 0, r.reporter_id)}`)).join('') : '<div class="pc-empty"><i class="fas fa-inbox"></i>Nadie ha reportado sus vídeos.</div>';
        },
        reports_made() {
            const rows = state.userDetail.reports_made;
            return rows.length ? rows.map(r => reportLine(r, r.uploader_id ? `contra ${userChip(r.uploader_name, 0, r.uploader_id)}` : 'contra contenido del panel')).join('') : '<div class="pc-empty"><i class="fas fa-inbox"></i>No ha enviado reportes.</div>';
        },
        ledger() {
            const rows = state.userDetail.ledger;
            const label = { video_reward: 'Recompensa por vídeo', video_reward_restore: 'Recompensa restaurada', video_reward_reversal: 'Recompensa revertida', video_report_reward: 'Recompensa por reporte', video_report_reward_reversal: 'Reporte reabierto' };
            return rows.length ? rows.map(x => `
                <div class="pc-ledger">
                    <span class="pc-num ${Number(x.amount) >= 0 ? 'pc-amt--pos' : 'pc-amt--neg'}">${Number(x.amount) >= 0 ? '+' : '−'}${fmtNum(Math.abs(Number(x.amount)))} 🍌</span>
                    <div class="min-w-0"><b>${esc(label[x.reason] || x.reason)}</b>${x.track_name ? ` · ${esc(x.track_name)}` : ''} <small style="color:var(--pc-subtle)">#${esc(x.reference_id)}</small></div>
                    <span style="color:var(--pc-subtle)">${fmtDate(x.created_at)}</span>
                </div>`).join('') : '<div class="pc-empty"><i class="fas fa-inbox"></i>Sin movimientos de Bananos en el módulo.</div>';
        },
    };
    const reportLine = (r, who) => {
        const pill = r.status === 'open' ? '<span class="pc-pill pc-pill--danger"><i class="fas fa-flag"></i> Abierto</span>'
            : r.status === 'upheld' ? '<span class="pc-pill pc-pill--good"><i class="fas fa-check"></i> Confirmado</span>'
            : '<span class="pc-pill">Desestimado</span>';
        return `
            <div class="pc-row">
                <div class="pc-thumb"><i class="fas fa-flag"></i></div>
                <div class="min-w-0">
                    <div class="pc-row-title">${esc(r.track_name)} ${pill} ${Number(r.reward_paid) ? `<span class="pc-pill pc-pill--accent">+${fmtNum(r.reward_amount)} 🍌 pagados</span>` : ''}</div>
                    <div class="pc-row-meta">
                        <span>${who}</span><span>motivo: <b>${esc(r.reason)}</b></span>
                        <span><i class="far fa-clock"></i> ${fmtDate(r.created_at)}</span>
                        ${r.reviewed_by ? `<span>resuelto por <b>${esc(r.reviewed_by)}</b> · ${fmtDate(r.reviewed_at)}</span>` : ''}
                    </div>
                </div>
                <div class="pc-row-actions">${r.status === 'open' ? `<button class="pc-btn pc-btn--sm" onclick="closeModal('pcUserModal'); setModList('reports')"><i class="fas fa-gavel"></i> Ir al reporte</button>` : ''}</div>
            </div>`;
    };
    function onUserClick(e) {
        const chip = e.target.closest('[data-user-open]');
        if (chip) { openUser(chip.dataset.userOpen); return; }
        const btn = e.target.closest('button[data-uact]'); if (!btn) return;
        const d = state.userDetail; if (!d) return;
        const uid = d.user.user_id, uname = d.user.user_name || 'Sin nombre';
        const { uact, id, name } = btn.dataset;
        switch (uact) {
            case 'ban':
                openAction({
                    title: `Suspender a ${uname}`, icon: 'fa-user-slash', danger: true, reason: true, placeholder: 'Sube vídeos falsos para cobrar la recompensa',
                    hint: 'El usuario pierde el acceso al sistema de vídeos (no podrá aportar, editar ni reportar). Podrás reactivarlo después.',
                    extra: 'Retirar también todos sus vídeos publicados y revertir sus Bananos', extraDefault: true,
                    onConfirm: v => run('ban_user', { user_id: uid, reason: v.reason, take_down: v.extra ? '1' : '0' }),
                }); break;
            case 'unban':
                openAction({
                    title: `Reactivar a ${uname}`, icon: 'fa-user-check', good: true,
                    hint: 'Recupera el acceso al sistema de vídeos. Sus aportes retirados siguen retirados (puedes restaurarlos uno a uno).',
                    onConfirm: () => run('unban_user', { user_id: uid }),
                }); break;
            case 'takedown-all':
                openAction({
                    title: 'Retirar todos sus vídeos', icon: 'fa-trash', danger: true, reason: true, placeholder: 'Contenido incorrecto',
                    hint: `Se retiran <b>todos</b> los vídeos publicados de <b>${esc(uname)}</b> y se revierten los Bananos que conserve de cada uno. Se le suspende para poder hacerlo en bloque; desmarca la casilla para reactivarlo justo después.`,
                    extra: 'Mantener la suspensión tras retirar los vídeos', extraDefault: true,
                    onConfirm: async v => {
                        const json = await run('ban_user', { user_id: uid, reason: v.reason || 'Retirada de vídeos en bloque', take_down: '1' });
                        if (json && !v.extra) await run('unban_user', { user_id: uid });
                    },
                }); break;
            case 'remove':
                openAction({
                    title: 'Retirar aporte', icon: 'fa-trash', danger: true, reason: true, placeholder: 'Vídeo incorrecto o inapropiado',
                    hint: `Se quita el vídeo de <b>${esc(name)}</b> y se revierten los Bananos que el autor conserve de esta pista.`,
                    onConfirm: v => run('remove_submission', { id, reason: v.reason }),
                }); break;
            case 'restore':
                openAction({
                    title: 'Restaurar aporte', icon: 'fa-undo', good: true,
                    hint: 'El vídeo vuelve a publicarse en la pista (si está libre) y se devuelven los Bananos que falten.',
                    extra: Number(d.user.is_banned) ? 'Reactivar también al usuario suspendido' : null, extraDefault: true,
                    onConfirm: v => run('restore_submission', { id, unban: v.extra ? '1' : '0' }),
                }); break;
        }
    }

    // ---------- 5. Visual Tracks ----------
    function toggleAnon() {
        state.anon = !state.anon;
        $('#pcVisual').classList.toggle('is-anon', state.anon);
        $('#pcAnonBtn').innerHTML = state.anon ? '<i class="fas fa-user"></i> Mostrar nombres' : '<i class="fas fa-user-secret"></i> Anonimizar';
        renderVisual();
    }
    function toggleCapture() {
        const el = $('#pcVisual');
        if (document.fullscreenElement) document.exitFullscreen();
        else if (el.requestFullscreen) el.requestFullscreen().catch(() => toast('Tu navegador no permite pantalla completa aquí', 'error'));
    }
    const rankRow = (u, i, valueHtml, metaHtml) => `
        <div class="pc-rank pc-rank--${i + 1}">
            <div class="pc-rank-pos pc-num">${i < 3 ? `<span class="pc-medal pc-medal--${i + 1}"><i class="fas fa-${i === 0 ? 'crown' : 'medal'}"></i></span>` : `#${i + 1}`}</div>
            ${avatarImg(u.avatar, u.name)}
            <div class="min-w-0"><div class="pc-rank-name"><span class="pc-anon">${esc(anonName(u.name, i))}</span></div><div class="pc-rank-meta">${metaHtml}</div></div>
            <div class="pc-rank-val">${valueHtml}</div>
        </div>`;
    const podium = (rows, valueOf, unit) => rows.length ? `
        <div class="pc-podium">${rows.slice(0, 3).map((u, i) => `
            <div class="pc-podium-slot pc-podium-slot--${i + 1}">
                <span class="pc-medal pc-medal--${i + 1}"><i class="fas fa-${i === 0 ? 'crown' : 'medal'}"></i></span>
                ${avatarImg(u.avatar, u.name)}
                <div class="pc-podium-name pc-anon">${esc(anonName(u.name, i))}</div>
                <div class="pc-podium-val pc-num">${fmtNum(valueOf(u))}<small>${unit}</small></div>
            </div>`).join('')}</div>` : '';

    function renderVisual() {
        const s = state.stats; if (!s) return;
        $('#pcVisualDate').textContent = fmtDate(s.generated_at, false);
        const t = s.tracks || {}, sub = s.submissions || {}, rep = s.reports || {}, coins = s.coins || {};
        const shop = s.shop;
        const coverage = shop && shop.total ? pct(shop.covered, shop.total) : pct(t.with_media, t.total);
        const verifiedRejected = (t.verified || 0) + (sub.rejected || 0);
        const heroes = `
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-4">
                <div class="pc-card pc-card--pad pc-hero">
                    <p class="pc-card-label"><i class="fas fa-store"></i> ${shop && shop.total ? 'Cobertura de la tienda de hoy' : 'Pistas con vídeo / audio'}</p>
                    <p class="pc-card-value pc-num">${fmtPct(coverage)}</p>
                    <div class="pc-bar pc-bar--lg mt-3"><span style="width:${coverage}%"></span></div>
                    <p class="pc-card-foot">${shop && shop.total ? `${fmtNum(shop.covered)} de ${fmtNum(shop.total)} pistas visibles hoy` : `${fmtNum(t.with_media)} de ${fmtNum(t.total)} pistas conocidas`}</p>
                </div>
                <div class="pc-card pc-card--pad pc-hero pc-hero--good">
                    <p class="pc-card-label"><i class="fas fa-coins"></i> Bananos repartidos</p>
                    <p class="pc-card-value pc-num">${fmtNum(coins.net)} <small style="font-size:1rem">🍌</small></p>
                    <p class="pc-card-foot">${fmtNum(coins.upload_paid + coins.upload_restored)} por vídeos · ${fmtNum(coins.report_paid)} por reportes · ${fmtNum(coins.upload_reversed + coins.report_reversed)} revertidos</p>
                </div>
                <div class="pc-card pc-card--pad pc-hero pc-hero--cyan">
                    <p class="pc-card-label"><i class="fas fa-certificate"></i> Verificados vs. retirados</p>
                    <p class="pc-card-value pc-num">${fmtNum(t.verified)} <small style="font-size:1rem;color:var(--pc-subtle)">/ ${fmtNum(sub.rejected)}</small></p>
                    <div class="pc-split mt-3"><span style="width:${pct(t.verified, verifiedRejected)}%;background:var(--pc-good)"></span><span style="width:${pct(sub.rejected, verifiedRejected)}%;background:var(--pc-danger)"></span></div>
                    <p class="pc-card-foot">${fmtNum(sub.published)} publicados · ${fmtNum(sub.replaced)} reemplazados por el panel</p>
                </div>
                <div class="pc-card pc-card--pad pc-hero pc-hero--purple">
                    <p class="pc-card-label"><i class="fas fa-users"></i> Colaboradores</p>
                    <p class="pc-card-value pc-num">${fmtNum(sub.contributors)}</p>
                    <p class="pc-card-foot">${fmtNum(sub.last_7d)} aportes en 7 días · ${fmtNum(rep.reporters)} reporteros · ${fmtNum(s.bans)} suspendidos</p>
                </div>
            </div>`;

        const mixTotal = (t.youtube || 0) + (t.audio || 0) + (t.video || 0);
        const mix = `
            <div class="pc-card pc-card--pad">
                <p class="pc-card-label"><i class="fas fa-layer-group"></i> Tipo de contenido</p>
                <div class="pc-split mt-3"><span style="width:${pct(t.youtube, mixTotal)}%;background:#ef4444"></span><span style="width:${pct(t.audio, mixTotal)}%;background:var(--pc-purple)"></span><span style="width:${pct(t.video, mixTotal)}%;background:var(--pc-cyan)"></span></div>
                <div class="pc-legend"><span><i style="background:#ef4444"></i>YouTube <b>${fmtNum(t.youtube)}</b></span><span><i style="background:var(--pc-purple)"></i>Audio directo <b>${fmtNum(t.audio)}</b></span><span><i style="background:var(--pc-cyan)"></i>Vídeo directo <b>${fmtNum(t.video)}</b></span></div>
                <div class="pc-legend" style="margin-top:.9rem"><span><i style="background:var(--pc-accent)"></i>Comunidad <b>${fmtNum(t.community)}</b></span><span><i style="background:var(--pc-subtle)"></i>Panel <b>${fmtNum(t.system_media)}</b></span></div>
            </div>`;
        const repTotal = (rep.open || 0) + (rep.upheld || 0) + (rep.dismissed || 0);
        const reports = `
            <div class="pc-card pc-card--pad">
                <p class="pc-card-label"><i class="fas fa-flag"></i> Reportes</p>
                <p class="pc-card-value pc-num">${fmtNum(repTotal)}</p>
                <div class="pc-split mt-3"><span style="width:${pct(rep.open, repTotal)}%;background:var(--pc-accent)"></span><span style="width:${pct(rep.upheld, repTotal)}%;background:var(--pc-good)"></span><span style="width:${pct(rep.dismissed, repTotal)}%;background:var(--pc-subtle)"></span></div>
                <div class="pc-legend"><span><i style="background:var(--pc-accent)"></i>Abiertos <b>${fmtNum(rep.open)}</b></span><span><i style="background:var(--pc-good)"></i>Confirmados <b>${fmtNum(rep.upheld)}</b></span><span><i style="background:var(--pc-subtle)"></i>Desestimados <b>${fmtNum(rep.dismissed)}</b></span></div>
                <p class="pc-card-foot">${fmtNum(s.edits?.total)} ediciones de autores (${fmtNum(s.edits?.by_user)} propias)</p>
            </div>`;
        const daily = s.daily || [];
        const dailyMax = Math.max(1, ...daily.map(d => Math.max(d.uploads, d.reports)));
        const activity = `
            <div class="pc-card pc-card--pad">
                <p class="pc-card-label"><i class="fas fa-wave-square"></i> Últimos 14 días</p>
                <div class="pc-days mt-2">${daily.map(d => `
                    <div class="pc-day" title="${attr(d.day)}: ${d.uploads} subidas · ${d.reports} reportes">
                        <div class="pc-day-bars"><span class="is-up" style="height:${pct(d.uploads, dailyMax)}%"></span><span class="is-rep" style="height:${pct(d.reports, dailyMax)}%"></span></div>
                        <small>${esc(String(d.day).slice(8, 10))}</small>
                    </div>`).join('')}</div>
                <div class="pc-legend"><span><i style="background:var(--pc-accent)"></i>Subidas</span><span><i style="background:var(--pc-danger)"></i>Reportes</span></div>
            </div>`;

        const topUpload = s.top_upload || [], topGlobal = s.top_global || [], topRep = s.top_reporters || [];
        const tops = `
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-4">
                <div class="pc-card">
                    <div class="pc-section-head"><div><h3 class="pc-section-title pc-heavy"><i class="fas fa-trophy"></i> Top Bananos por subir pistas</h3><p class="pc-section-sub">Recompensas por vídeos publicados y activos.</p></div></div>
                    ${podium(topUpload, u => u.coins, ' 🍌')}
                    <div class="px-3 pb-3 pt-2">${topUpload.length ? topUpload.map((u, i) => rankRow(u, i, `<div class="pc-num">${fmtNum(u.coins)} 🍌</div><small>${plural(u.videos, 'vídeo', 'vídeos')}</small>`, `${plural(u.videos, 'pista publicada', 'pistas publicadas')}`)).join('') : '<div class="pc-empty"><i class="fas fa-inbox"></i>Aún no hay colaboradores.</div>'}</div>
                </div>
                <div class="pc-card">
                    <div class="pc-section-head"><div><h3 class="pc-section-title pc-heavy"><i class="fas fa-globe"></i> Top global</h3><p class="pc-section-sub">Vídeos publicados + reportes confirmados.</p></div></div>
                    ${podium(topGlobal, u => u.coins, ' 🍌')}
                    <div class="px-3 pb-3 pt-2">${topGlobal.length ? topGlobal.map((u, i) => rankRow(u, i, `<div class="pc-num">${fmtNum(u.coins)} 🍌</div><small>${fmtNum(u.video_coins)} + ${fmtNum(u.report_coins)}</small>`, `${plural(u.videos, 'vídeo', 'vídeos')} · ${plural(u.reports, 'reporte confirmado', 'reportes confirmados')}`)).join('') : '<div class="pc-empty"><i class="fas fa-inbox"></i>Sin datos.</div>'}</div>
                </div>
            </div>
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mt-4">
                <div class="pc-card xl:col-span-1">
                    <div class="pc-section-head"><div><h3 class="pc-section-title pc-heavy"><i class="fas fa-shield-halved"></i> Guardianes</h3><p class="pc-section-sub">Reporteros con reportes confirmados.</p></div></div>
                    <div class="px-3 pb-3">${topRep.length ? topRep.map((u, i) => rankRow(u, i, `<div class="pc-num">${fmtNum(u.coins)} 🍌</div>`, plural(u.reports, 'reporte confirmado', 'reportes confirmados'))).join('') : '<div class="pc-empty"><i class="fas fa-inbox"></i>Sin reportes confirmados.</div>'}</div>
                </div>
                <div class="xl:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-4" style="align-content:start">${mix}${reports}${activity}</div>
            </div>`;
        $('#pcVisualBody').innerHTML = heroes + tops;
    }

    // ---------- Toasts ----------
    function toast(msg, type = 'info', ms = 3400) {
        const el = document.createElement('div');
        el.className = 'pc-toast'; el.style.background = type === 'error' ? '#dc2626' : type === 'success' ? '#16a34a' : '#1f2937';
        el.innerHTML = `<i class="fas ${type === 'error' ? 'fa-times-circle' : type === 'success' ? 'fa-check-circle' : 'fa-info-circle'}"></i><span></span>`;
        el.querySelector('span').textContent = msg;
        $('#pcToasts').appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, ms);
    }
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

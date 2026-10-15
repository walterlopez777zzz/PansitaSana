<?php
// wabo/sorteos_flash/gestionar_sorteos.php
// CORREGIDO: Tailwind CDN + Lógica Shop Helper 4.1 + Opciones Restauradas

require_once __DIR__ . '/../core/admin_header.php';
require_once __DIR__ . '/../core/db_connect.php';

$page_title = "Gestionar Sorteos Flash";
?>


<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
<style>
    /* Estilos personalizados para Modo Oscuro y DataTables */
    :root {
        --flash-bg-main: var(--primary);
        --flash-surface: var(--secondary);
        --flash-border: var(--border-color);
        --flash-row: var(--secondary);
        --flash-row-alt: var(--secondary);
        --flash-row-hover: rgba(0, 0, 0, 0.035);
        --flash-text: var(--text-primary);
        --flash-text-muted: var(--text-secondary);
        --flash-text-dim: rgba(73, 80, 87, 0.75);
        --flash-muted: rgba(17, 24, 39, 0.04);
        --flash-accent: #22d3ee;
        --flash-accent-hover: #06b6d4;
        --flash-input-bg: var(--secondary);
        --flash-overlay: rgba(0, 0, 0, 0.55);
    }

    html[data-theme="dark"] {
        --flash-bg-main: #050505;
        --flash-surface: #0a0a0a;
        --flash-border: var(--border-color);
        --flash-row: #0a0a0a;
        --flash-row-alt: #0d0d0d;
        --flash-row-hover: #111111;
        --flash-text: #f3f4f6;
        --flash-text-muted: #a1a1aa;
        --flash-text-dim: rgba(161, 161, 170, 0.85);
        --flash-muted: rgba(255, 255, 255, 0.06);
        --flash-input-bg: rgba(255, 255, 255, 0.03);
        --flash-overlay: rgba(0, 0, 0, 0.70);
    }

    html[data-theme="light"] {
        --flash-bg-main: #f1f3f5;
        --flash-surface: #ffffff;
        --flash-border: var(--border-color);
        --flash-row: #ffffff;
        --flash-row-alt: #f9fafb;
        --flash-row-hover: #f3f4f6;
        --flash-text-dim: rgba(75, 85, 99, 0.75);
        --flash-muted: rgba(17, 24, 39, 0.04);
        --flash-input-bg: #ffffff;
        --flash-overlay: rgba(0, 0, 0, 0.45);
    }

    #flash-raffles-page { background: var(--flash-bg-main); }
    .flash-card { background: var(--flash-surface); border: 1px solid var(--flash-border); border-radius: 12px; }
    .flash-divider { border-color: var(--flash-border) !important; }
    .flash-text { color: var(--flash-text) !important; }
    .flash-text-muted { color: var(--flash-text-muted) !important; }
    .flash-input {
        background: var(--flash-input-bg);
        border: 1px solid var(--flash-border);
        color: var(--flash-text);
        border-radius: 10px;
        transition: border-color 0.2s ease-in-out, background-color 0.2s ease-in-out;
    }
    .flash-input::placeholder { color: var(--flash-text-dim); }
    .flash-input:focus { outline: none; border-color: rgba(34, 211, 238, 0.65); background: rgba(255, 255, 255, 0.05); }

    .flash-btn-primary { background: var(--flash-accent); color: #041014; border-radius: 10px; transition: transform 0.2s ease-in-out, background-color 0.2s ease-in-out, filter 0.2s ease-in-out; }
    .flash-btn-primary:hover { background: var(--flash-accent-hover); filter: brightness(1.02); transform: translateY(-1px); }
    .flash-btn-secondary { background: var(--flash-muted); border: 1px solid var(--flash-border); color: var(--flash-text); border-radius: 10px; transition: background-color 0.2s ease-in-out, border-color 0.2s ease-in-out; }
    .flash-btn-secondary:hover { background: rgba(34, 211, 238, 0.08); border-color: rgba(34, 211, 238, 0.35); }

    .flash-icon-btn { height: 34px; width: 34px; border-radius: 10px; border: 1px solid var(--flash-border); background: var(--flash-muted); color: var(--flash-text-muted); transition: background-color 0.2s ease-in-out, border-color 0.2s ease-in-out, color 0.2s ease-in-out, transform 0.2s ease-in-out; }
    .flash-icon-btn:hover { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.12); color: var(--flash-text); transform: translateY(-1px); }
    .flash-icon-btn--danger:hover { border-color: rgba(239, 68, 68, 0.45); color: #fca5a5; }

    html[data-theme="light"] .flash-icon-btn:hover { background: rgba(17, 24, 39, 0.06); border-color: rgba(17, 24, 39, 0.12); }

    .dataTables_wrapper .dataTables_length select { padding-right: 2rem; background-color: var(--flash-surface); color: var(--flash-text); border-color: var(--flash-border); border-radius: 10px; }
    .dataTables_wrapper .dataTables_filter input { background-color: var(--flash-surface); color: var(--flash-text); border-color: var(--flash-border); border-radius: 10px; padding: 0.5rem; }
    .dataTables_wrapper .dataTables_filter input::placeholder { color: var(--flash-text-dim); }
    .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_paginate { color: var(--flash-text-muted) !important; margin-top: 1rem; }
    .dataTables_wrapper .dataTables_paginate .paginate_button { color: var(--flash-text-muted) !important; }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: rgba(34, 211, 238, 0.12) !important; border: 1px solid rgba(34, 211, 238, 0.30) !important; color: var(--flash-text) !important; border-radius: 10px; }
    table.dataTable.no-footer { border-bottom: 1px solid var(--flash-border); }
    table.dataTable thead th { border-bottom: 1px solid var(--flash-border) !important; }
    table.dataTable tbody tr { background-color: var(--flash-row) !important; color: var(--flash-text); border-bottom: 1px solid var(--flash-border); }
    table.dataTable tbody tr:nth-child(even) { background-color: var(--flash-row-alt) !important; }
    table.dataTable tbody tr:hover { background-color: var(--flash-row-hover) !important; }

    #modalSorteo { opacity: 0; pointer-events: none; transition: opacity 0.20s ease-in-out; }
    #modalSorteo.is-open { opacity: 1; pointer-events: auto; }
    body.modal-active { overflow: hidden; }

    .flash-segment { background: var(--flash-muted); border: 1px solid var(--flash-border); border-radius: 10px; padding: 3px; }
    .flash-segment-btn { border-radius: 8px; color: var(--flash-text-muted); transition: background-color 0.2s ease-in-out, color 0.2s ease-in-out; }
    .flash-segment-btn:hover { color: var(--flash-text); }
    .flash-segment-btn.is-active { background: rgba(34, 211, 238, 0.15); color: var(--flash-text); }

    .flash-collapse { max-height: 0; opacity: 0; transform: translateY(-4px); overflow: hidden; transition: max-height 0.22s ease-in-out, opacity 0.2s ease-in-out, transform 0.22s ease-in-out; }
    .flash-collapse.is-open { max-height: 120px; opacity: 1; transform: translateY(0); }

    .flash-toggle-input { position: absolute; opacity: 0; pointer-events: none; }
    .flash-toggle-body {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 10px 12px;
        border: 1px solid var(--flash-border);
        border-radius: 12px;
        background: var(--flash-muted);
        transition: background-color 0.2s ease-in-out, border-color 0.2s ease-in-out, transform 0.2s ease-in-out;
        cursor: pointer;
        user-select: none;
    }
    .flash-toggle-body:hover { transform: translateY(-1px); }

    .flash-toggle-meta { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .flash-toggle-icon {
        width: 30px;
        height: 30px;
        border-radius: 10px;
        border: 1px solid var(--flash-border);
        background: rgba(34, 211, 238, 0.08);
        color: var(--flash-accent);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: filter 0.2s ease-in-out;
    }
    html[data-theme="light"] .flash-toggle-icon { background: rgba(34, 211, 238, 0.10); }

    .flash-toggle-title { font-size: 0.95rem; font-weight: 700; color: var(--flash-text); transition: color 0.2s ease-in-out; }
    .flash-toggle-subtitle { font-size: 0.80rem; color: var(--flash-text-muted); margin-top: 1px; }

    .flash-switch {
        width: 44px;
        height: 24px;
        border-radius: 999px;
        position: relative;
        border: 1px solid var(--flash-border);
        background: rgba(148, 163, 184, 0.12);
        flex-shrink: 0;
        transition: background-color 0.2s ease-in-out, border-color 0.2s ease-in-out;
    }
    .flash-switch::after {
        content: "";
        position: absolute;
        top: 2px;
        left: 2px;
        width: 20px;
        height: 20px;
        border-radius: 999px;
        background: var(--flash-surface);
        border: 1px solid var(--flash-border);
        transition: transform 0.2s ease-in-out, background-color 0.2s ease-in-out, border-color 0.2s ease-in-out;
    }
    html[data-theme="dark"] .flash-switch::after { background: #050505; }

    .flash-toggle-input:checked + .flash-toggle-body {
        border-color: rgba(34, 211, 238, 0.45);
        background: rgba(34, 211, 238, 0.10);
    }
    .flash-toggle-input:checked + .flash-toggle-body .flash-toggle-title { color: var(--flash-accent); }
    .flash-toggle-input:checked + .flash-toggle-body .flash-switch { background: rgba(34, 211, 238, 0.30); border-color: rgba(34, 211, 238, 0.45); }
    .flash-toggle-input:checked + .flash-toggle-body .flash-switch::after { transform: translateX(20px); }

    .flash-live-dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: rgba(255, 62, 62, 0.95);
        box-shadow: 0 0 0 0 rgba(255, 62, 62, 0.55);
        animation: flashPulseLive 1.4s ease-in-out infinite;
    }
    @keyframes flashPulseLive {
        0% { box-shadow: 0 0 0 0 rgba(255, 62, 62, 0.55); }
        70% { box-shadow: 0 0 0 10px rgba(255, 62, 62, 0.00); }
        100% { box-shadow: 0 0 0 0 rgba(255, 62, 62, 0.00); }
    }

    .flash-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 5px 10px;
        border-radius: 999px;
        border: 1px solid var(--flash-border);
        background: var(--flash-muted);
        color: var(--flash-text);
        font-weight: 800;
        font-size: 0.75rem;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }
    /* --- NUEVO: ESTILOS PARA TABS V2 --- */
    .flash-tabs { display: flex; border-bottom: 1px solid var(--flash-border); margin-bottom: 20px; }
    .flash-tab-btn {
        flex: 1;
        padding: 12px;
        text-align: center;
        color: var(--flash-text-muted);
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.2s;
        border-bottom: 2px solid transparent;
    }
    .flash-tab-btn:hover { color: var(--flash-text); background: rgba(255,255,255,0.02); }
    .flash-tab-btn.active {
        color: var(--flash-accent);
        border-bottom-color: var(--flash-accent);
        background: rgba(34, 211, 238, 0.05);
    }
    .tab-content { display: none; animation: fadeIn 0.3s ease; }
    .tab-content.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

    /* ===== Tarjetas de sorteo (rediseño) ===== */
    .rf-card {
        background: var(--flash-surface);
        border: 1px solid var(--flash-border);
        border-radius: 14px;
        padding: 1rem 1.1rem;
        display: flex;
        flex-direction: column;
        gap: .85rem;
        transition: border-color .2s ease, transform .2s ease, box-shadow .2s ease;
    }
    .rf-card:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,.18); }
    /* Tarjeta destacada (activos) */
    .rf-card--active {
        border-color: rgba(34, 211, 238, 0.35);
        box-shadow: 0 0 0 1px rgba(34,211,238,.08), 0 8px 30px rgba(0,0,0,.20);
        background:
            radial-gradient(120% 120% at 0% 0%, rgba(34,211,238,.08), transparent 45%),
            var(--flash-surface);
    }
    .rf-card--live { border-color: rgba(255, 62, 62, 0.40); }
    .rf-card--live { background: radial-gradient(120% 120% at 0% 0%, rgba(255,62,62,.08), transparent 45%), var(--flash-surface); }

    .rf-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
    .rf-title { font-size: 1.05rem; font-weight: 800; color: var(--flash-text); line-height: 1.2; }
    .rf-card--active .rf-title { font-size: 1.25rem; }
    .rf-id { font-size: .68rem; font-family: ui-monospace, monospace; color: var(--flash-text-dim); }
    .rf-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .55rem .9rem; }
    .rf-card--active .rf-meta-grid { grid-template-columns: repeat(3, 1fr); }
    .rf-meta { min-width: 0; }
    .rf-meta__label { font-size: .62rem; text-transform: uppercase; letter-spacing: .05em; color: var(--flash-text-dim); margin-bottom: 1px; }
    .rf-meta__val { font-size: .85rem; font-weight: 600; color: var(--flash-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rf-actions { display: flex; flex-wrap: wrap; gap: .45rem; padding-top: .25rem; border-top: 1px solid var(--flash-border); }
    .rf-btn {
        display: inline-flex; align-items: center; gap: .4rem;
        font-size: .8rem; font-weight: 700; border-radius: 9px;
        padding: .45rem .7rem; border: 1px solid var(--flash-border);
        background: var(--flash-muted); color: var(--flash-text); cursor: pointer;
        transition: background-color .2s ease, border-color .2s ease, color .2s ease, transform .15s ease;
    }
    .rf-btn:hover { transform: translateY(-1px); }
    .rf-btn--primary { background: var(--flash-accent); color: #041014; border-color: transparent; }
    .rf-btn--primary:hover { background: var(--flash-accent-hover); }
    .rf-btn--ghost:hover { border-color: rgba(34,211,238,.4); color: var(--flash-accent); }
    .rf-btn--danger:hover { border-color: rgba(239,68,68,.45); color: #fca5a5; }
    .rf-icononly { padding: .45rem .55rem; }

    .rf-deliv-chip {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .68rem; font-weight: 800; padding: .15rem .5rem; border-radius: 999px;
        text-transform: uppercase; letter-spacing: .03em;
    }
    .rf-deliv-chip--done { background: rgba(34,197,94,.16); color: #22c55e; }
    .rf-deliv-chip--part { background: rgba(245,158,11,.16); color: #f59e0b; }
    .rf-deliv-chip--none { background: rgba(148,163,184,.14); color: var(--flash-text-muted); }

    /* ===== Modales Prueba de Entrega / Métricas ===== */
    .ent-overlay { position: fixed; inset: 0; z-index: 70; display: none; align-items: flex-start; justify-content: center; padding: 2.5rem 1rem; overflow-y: auto; background: var(--flash-overlay); backdrop-filter: blur(6px); }
    .ent-overlay.is-open { display: flex; }
    .ent-modal { width: 100%; max-width: 640px; background: var(--flash-surface); border: 1px solid var(--flash-border); border-radius: 16px; overflow: hidden; box-shadow: 0 24px 60px rgba(0,0,0,.4); }
    .ent-modal--wide { max-width: 720px; }
    .ent-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--flash-border); }
    .ent-head h3 { font-size: 1.05rem; font-weight: 800; color: var(--flash-text); display: flex; align-items: center; gap: .5rem; }
    .ent-head .ent-sub { font-size: .72rem; color: var(--flash-text-muted); margin-top: 2px; }
    .ent-body { padding: 1.1rem 1.25rem; max-height: 65vh; overflow-y: auto; }

    .ew-row { display: flex; align-items: center; gap: .8rem; padding: .8rem; border: 1px solid var(--flash-border); border-radius: 12px; background: var(--flash-muted); margin-bottom: .7rem; }
    .ew-avatar { height: 46px; width: 46px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--flash-surface); border: 1px solid var(--flash-border); }
    .ew-main { min-width: 0; flex: 1; }
    .ew-name { font-weight: 700; color: var(--flash-text); display: flex; align-items: center; gap: .4rem; }
    .ew-sub { font-size: .72rem; color: var(--flash-text-muted); margin-top: 1px; }
    .ew-side { display: flex; flex-direction: column; align-items: flex-end; gap: .45rem; flex-shrink: 0; }
    .ew-status-toggle { display: inline-flex; border: 1px solid var(--flash-border); border-radius: 9px; overflow: hidden; }
    .ew-status-toggle button { font-size: .72rem; font-weight: 700; padding: .3rem .6rem; background: transparent; color: var(--flash-text-muted); cursor: pointer; transition: background-color .15s ease, color .15s ease; }
    .ew-status-toggle button.is-pending { background: rgba(245,158,11,.18); color: #f59e0b; }
    .ew-status-toggle button.is-delivered { background: rgba(34,197,94,.20); color: #22c55e; }
    .ew-proof-actions { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; justify-content: flex-end; }
    .ew-thumb-wrap { position: relative; display: inline-block; }
    .ew-thumb { height: 38px; width: 38px; border-radius: 8px; object-fit: cover; border: 1px solid var(--flash-border); cursor: pointer; display: block; }
    .ew-thumb-del { position: absolute; top: -6px; right: -6px; height: 18px; width: 18px; line-height: 1; border-radius: 50%; border: none; background: #ef4444; color: #fff; font-size: 13px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 1px 4px rgba(0,0,0,.4); }
    .ew-thumb-del:hover { background: #dc2626; }
    .ew-upload-btn { font-size: .72rem; font-weight: 700; padding: .35rem .6rem; border-radius: 8px; border: 1px solid var(--flash-border); background: var(--flash-surface); color: var(--flash-text); cursor: pointer; }
    .ew-upload-btn:hover { border-color: rgba(34,211,238,.4); color: var(--flash-accent); }
    .ew-outcome {
        display: flex; align-items: center; gap: .4rem; flex-wrap: wrap;
        width: fit-content; max-width: 100%; margin-top: .45rem;
        padding: .38rem .58rem; border-radius: 9px;
        font-size: .72rem; line-height: 1.35;
    }
    .ew-outcome--automatic { color: #22c55e; background: rgba(34,197,94,.11); border: 1px solid rgba(34,197,94,.25); }
    .ew-outcome--mixed { color: #f59e0b; background: rgba(245,158,11,.11); border: 1px solid rgba(245,158,11,.25); }
    .ew-outcome--lost { color: #f87171; background: rgba(239,68,68,.10); border: 1px solid rgba(239,68,68,.22); }
    .ew-coin-icon { display: inline-block; object-fit: contain; vertical-align: middle; flex-shrink: 0; }

    @media (max-width: 640px) {
        .ent-overlay { padding: 1rem .5rem; }
        .ent-body { padding: .85rem; }
        .ew-row { align-items: flex-start; flex-wrap: wrap; }
        .ew-main { min-width: calc(100% - 60px); }
        .ew-side { width: 100%; align-items: stretch; padding-left: 60px; }
        .ew-status-toggle { width: 100%; }
        .ew-status-toggle button { flex: 1; }
        .ew-proof-actions { justify-content: flex-start; }
        .ew-outcome { width: 100%; }
    }

    .mt-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: .7rem; }
    .mt-cell { border: 1px solid var(--flash-border); border-radius: 11px; padding: .65rem .8rem; background: var(--flash-muted); }
    .mt-cell--full { grid-column: 1 / -1; }
    .mt-label { font-size: .62rem; text-transform: uppercase; letter-spacing: .05em; color: var(--flash-text-dim); }
    .mt-val { font-size: .95rem; font-weight: 700; color: var(--flash-text); margin-top: 2px; word-break: break-word; }
    .mt-val--mono { font-family: ui-monospace, monospace; font-size: .72rem; font-weight: 500; }

    .ent-lightbox { position: fixed; inset: 0; z-index: 90; display: none; align-items: center; justify-content: center; padding: 2rem; background: rgba(0,0,0,.85); }
    .ent-lightbox.is-open { display: flex; }
    .ent-lightbox img { max-width: 100%; max-height: 90vh; border-radius: 12px; }

    /* ==== Selects nativos legibles en ambos temas ====
       El desplegable nativo (<option>/<optgroup>) hereda el tema del SO si no
       se fija color-scheme; en oscuro quedaba lista blanca con letras blancas. */
    html[data-theme="dark"] #flash-raffles-page,
    html[data-theme="dark"] #modalSorteo { color-scheme: dark; }
    html[data-theme="light"] #flash-raffles-page,
    html[data-theme="light"] #modalSorteo { color-scheme: light; }
    select.flash-input,
    .dataTables_wrapper .dataTables_length select {
        background-color: var(--flash-surface);
        color: var(--flash-text);
    }
    html[data-theme="dark"] select.flash-input option,
    html[data-theme="dark"] select.flash-input optgroup,
    html[data-theme="dark"] .dataTables_wrapper .dataTables_length select option {
        background-color: #0f172a;
        color: #f3f4f6;
    }
    html[data-theme="light"] select.flash-input option,
    html[data-theme="light"] select.flash-input optgroup,
    html[data-theme="light"] .dataTables_wrapper .dataTables_length select option {
        background-color: #ffffff;
        color: #111827;
    }

    /* ==== Constructor de premios POR PUESTO / POOL ==== */
    .pb-position-card {
        background: var(--flash-surface);
        border: 1px solid var(--flash-border);
        border-radius: 14px;
        padding: 14px;
    }
    .pb-position-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 10px; flex-wrap: wrap; margin-bottom: 10px;
        padding-bottom: 10px; border-bottom: 1px dashed var(--flash-border);
    }
    .pb-position-title {
        display: inline-flex; align-items: center; gap: 7px;
        font-size: 12px; font-weight: 800; letter-spacing: 0.6px;
        color: #fbbf24; padding: 5px 12px; border-radius: 999px;
        background: rgba(251, 191, 36, 0.10); border: 1px solid rgba(251, 191, 36, 0.28);
    }
    .pb-pool-label-row { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
    .pb-pool-label-row .pb-pool-ico { color: #c084fc; font-size: 13px; flex: none; }
    .pb-option {
        background: rgba(0, 0, 0, 0.18);
        border: 1px solid var(--flash-border);
        border-radius: 12px;
        padding: 10px;
    }
    html[data-theme="light"] .pb-option { background: rgba(17, 24, 39, 0.03); }
    .pb-option + .pb-option { margin-top: 8px; }
    .pb-option-head {
        display: grid;
        grid-template-columns: 130px 32px minmax(0, 1fr) auto;
        align-items: center; gap: 8px; margin-bottom: 8px;
    }
    .pb-option-head .pb-kind-select { width: 100%; }
    .pb-option-thumb {
        width: 32px; height: 32px; border-radius: 8px; object-fit: cover;
        border: 1px solid var(--flash-border); background: rgba(255, 255, 255, 0.04);
    }
    .pb-option-thumb--empty {
        display: flex; align-items: center; justify-content: center;
        font-size: 14px; color: var(--flash-text-dim);
    }
    .pb-option-name {
        font-size: 11px; color: var(--flash-text-muted);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .pb-remove-btn {
        width: 26px; height: 26px; border-radius: 8px; flex: none;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 11px; color: #f87171; cursor: pointer;
        background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25);
        transition: background-color 0.15s ease, border-color 0.15s ease;
    }
    .pb-remove-btn:hover { background: rgba(239, 68, 68, 0.18); border-color: rgba(239, 68, 68, 0.5); }
    .pb-option-body { display: flex; flex-direction: column; gap: 6px; }
    .pb-inline-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .pb-inline-row .pb-hint { font-size: 10px; color: var(--flash-text-dim); }
    .pb-add-option {
        width: 100%; margin-top: 8px; padding: 8px; border-radius: 10px;
        font-size: 12px; font-weight: 700; cursor: pointer;
        color: #22d3ee; background: rgba(34, 211, 238, 0.06);
        border: 1px dashed rgba(34, 211, 238, 0.4);
        transition: background-color 0.15s ease;
    }
    .pb-add-option:hover { background: rgba(34, 211, 238, 0.12); }
    .pb-combo-item {
        border: 1px dashed var(--flash-border);
        border-radius: 10px; padding: 8px; margin-top: 8px;
    }
    .pb-combo-item--pool { border-color: rgba(192, 132, 252, 0.45); }
    .pb-combo-item-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 8px; margin-bottom: 6px;
    }
    .pb-combo-item-tag {
        font-size: 10px; font-weight: 800; letter-spacing: 0.06em;
        color: var(--flash-text-dim);
    }
    .pb-combo-item--pool .pb-combo-item-tag { color: #c084fc; }
    @media (max-width: 560px) {
        .pb-option-head { grid-template-columns: 1fr 32px auto; }
        .pb-option-head .pb-option-name { display: none; }
    }

    /* ===== Sorteo privado / exclusivo (lista de invitados) ===== */
    .rp-badge {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 3px 9px; border-radius: 999px;
        border: 1px solid rgba(192, 132, 252, 0.45);
        background: rgba(192, 132, 252, 0.12); color: #c084fc;
        font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase;
        white-space: nowrap;
    }
    .rp-panel { border: 1px solid rgba(192, 132, 252, 0.35); background: rgba(192, 132, 252, 0.06); border-radius: 12px; padding: 12px; }
    .rp-list { max-height: 220px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; }
    .rp-row {
        display: flex; align-items: center; gap: 10px; padding: 6px 8px;
        border-radius: 10px; border: 1px solid var(--flash-border); background: var(--flash-surface);
    }
    .rp-row img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--flash-muted); }
    .rp-row__main { flex: 1; min-width: 0; }
    .rp-row__name { font-size: 0.85rem; font-weight: 700; color: var(--flash-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rp-row__sub { font-size: 0.7rem; color: var(--flash-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rp-chip { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.05em; padding: 2px 6px; border-radius: 999px; margin-left: 4px; vertical-align: middle; }
    .rp-chip--in { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
    .rp-chip--part { background: rgba(34, 211, 238, 0.15); color: #22d3ee; }
    .rp-chip--pending { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
    .rp-btn {
        display: inline-flex; align-items: center; gap: 5px; padding: 5px 9px; border-radius: 8px;
        font-size: 0.72rem; font-weight: 700; border: 1px solid var(--flash-border); background: var(--flash-muted); color: var(--flash-text);
        white-space: nowrap; flex-shrink: 0;
    }
    .rp-btn--add { border-color: rgba(192, 132, 252, 0.45); color: #c084fc; }
    .rp-btn--remove { border-color: rgba(239, 68, 68, 0.4); color: #ef4444; }
    .rp-btn:disabled { opacity: .5; cursor: not-allowed; }
    .rp-empty { font-size: 0.75rem; color: var(--flash-text-muted); text-align: center; padding: 10px; }
</style>

<div id="flash-raffles-page" class="min-h-screen">
    <div id="raffleListView" class="container mx-auto px-4 py-6">

        <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
            <div>
                <h1 class="text-3xl font-bold flash-text flex items-center gap-2">
                    <i class="fas fa-bolt text-yellow-400"></i> Sorteos Flash
                </h1>
                <p class="flash-text-muted text-sm mt-1">Demostrablemente justo y verificado en blockchain</p>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="openGlobalAudioModal()" class="flash-btn-secondary font-bold py-2 px-4 shadow-lg flex items-center gap-2" style="border-color: rgba(168,85,247,0.4); color: #c084fc;">
                    🎵 Sonidos Globales
                </button>
                <button onclick="openBananoModal()" class="flash-btn-secondary font-bold py-2 px-4 shadow-lg flex items-center gap-2" style="border-color: rgba(250,204,21,0.45); color: #facc15;">
                    🍌 Mascota Banano
                </button>
                <button onclick="openWelcomeTicketModal()" class="flash-btn-secondary font-bold py-2 px-4 shadow-lg flex items-center gap-2" style="border-color: rgba(245,158,11,0.45); color: #fbbf24;">
                    🎟️ Ticket de Bienvenida
                </button>
                <a href="sorteos_flash/estadisticas.php" class="flash-btn-secondary font-bold py-2 px-4 shadow-lg flex items-center gap-2" style="border-color: rgba(34,211,238,0.42); color: var(--flash-accent);">
                    <i class="fas fa-chart-line"></i> Estadísticas
                </a>
                <a href="sorteos_flash/baneos_expulsados.php" class="flash-btn-secondary font-bold py-2 px-4 shadow-lg flex items-center gap-2" style="border-color: rgba(239,68,68,0.45); color: #f87171;">
                    <i class="fas fa-gavel"></i> Baneos
                </a>
                <a href="compras_manuales/gestionar_compras.php" class="flash-btn-secondary font-bold py-2 px-4 shadow-lg flex items-center gap-2" style="border-color: rgba(245,158,11,0.45); color: #fbbf24;">
                    <i class="fas fa-cash-register"></i> Compras Manuales
                </a>
                <button onclick="openNewModal()" class="flash-btn-primary font-bold py-2 px-6 shadow-lg flex items-center gap-2">
                    <i class="fas fa-plus"></i> Nuevo sorteo
                </button>
            </div>
        </div>

        <!-- ===== ACTIVOS (destacados arriba) ===== -->
        <section id="rafflesActiveSection" class="mb-10">
            <div class="flex items-center gap-2 mb-3">
                <span class="flash-live-dot"></span>
                <h2 class="text-lg font-bold flash-text">Sorteos activos</h2>
                <span id="activeCount" class="flash-status-badge">0</span>
            </div>
            <div id="rafflesActiveContainer" class="grid grid-cols-1 lg:grid-cols-2 gap-4"></div>
            <div id="rafflesActiveEmpty" class="flash-card p-8 text-center flash-text-muted hidden">
                <i class="fas fa-bolt text-2xl mb-2 opacity-40"></i>
                <p>No hay sorteos activos en este momento.</p>
            </div>
        </section>

        <!-- ===== HISTORIAL (pasados abajo) ===== -->
        <section id="rafflesPastSection">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
                <div class="flex items-center gap-2">
                    <i class="fas fa-clock-rotate-left flash-text-muted"></i>
                    <h2 class="text-lg font-bold flash-text">Historial de sorteos</h2>
                    <span id="pastCount" class="flash-status-badge">0</span>
                </div>
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs" style="color: var(--flash-text-dim);"></i>
                    <input id="pastSearch" type="text" oninput="renderRaffleSections()" placeholder="Buscar por título, ID o premio…" class="flash-input text-sm py-2 pl-9 pr-3 w-full sm:w-72">
                </div>
            </div>
            <div id="rafflesPastContainer" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4"></div>
            <div id="rafflesPastEmpty" class="flash-card p-8 text-center flash-text-muted hidden">
                <i class="fas fa-box-open text-2xl mb-2 opacity-40"></i>
                <p>No hay sorteos en el historial.</p>
            </div>
        </section>

        <div id="rafflesLoading" class="flash-card p-10 text-center flash-text-muted">
            <i class="fas fa-circle-notch fa-spin text-2xl mb-2"></i>
            <p>Cargando sorteos…</p>
        </div>
    </div>
</div>

<!-- ====================== MODAL: SUBIR PREMIOS (Prueba de Entrega) ====================== -->
<div id="modalEntrega" class="ent-overlay" onclick="if(event.target===this) closeEntregaModal()">
    <div class="ent-modal ent-modal--wide">
        <div class="ent-head">
            <div>
                <h3><i class="fas fa-gift" style="color: var(--flash-accent);"></i> Estado de premios</h3>
                <div class="ent-sub" id="entRaffleSub">—</div>
            </div>
            <button onclick="closeEntregaModal()" class="flash-icon-btn flash-icon-btn--danger" title="Cerrar"><i class="fas fa-times"></i></button>
        </div>
        <div class="ent-body" id="entBody">
            <div class="text-center flash-text-muted py-8"><i class="fas fa-circle-notch fa-spin text-xl"></i></div>
        </div>
    </div>
</div>
<input type="file" id="entProofFileInput" accept="image/png,image/jpeg,image/webp" multiple class="hidden">

<!-- ====================== MODAL: DETALLES / MÉTRICAS DEL SORTEO ====================== -->
<div id="modalMetricas" class="ent-overlay" onclick="if(event.target===this) closeMetricsModal()">
    <div class="ent-modal">
        <div class="ent-head">
            <div>
                <h3><i class="fas fa-chart-simple" style="color: var(--flash-accent);"></i> Detalles del sorteo</h3>
                <div class="ent-sub" id="mtRaffleSub">—</div>
            </div>
            <button onclick="closeMetricsModal()" class="flash-icon-btn flash-icon-btn--danger" title="Cerrar"><i class="fas fa-times"></i></button>
        </div>
        <div class="ent-body" id="mtBody">
            <div class="text-center flash-text-muted py-8"><i class="fas fa-circle-notch fa-spin text-xl"></i></div>
        </div>
    </div>
</div>

<!-- Lightbox para ver la prueba en grande -->
<div id="entLightbox" class="ent-lightbox" onclick="this.classList.remove('is-open')">
    <img id="entLightboxImg" src="" alt="Prueba de entrega">
</div>

<!-- ====================== MODAL: MONITOR EN VIVO ====================== -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
 (Full Page) ====================== -->
<div id="modalSorteo" class="fixed inset-0 overflow-y-auto z-50 hidden" aria-labelledby="modalTitle" role="dialog" aria-modal="true">
    <div class="flex items-start justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div id="modalSorteoOverlay" class="fixed inset-0 backdrop-blur-[8px] transition-opacity" aria-hidden="true" style="background: var(--flash-overlay);"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">​</span>
        <div class="inline-block align-bottom rounded-lg text-left overflow-hidden shadow-2xl transform transition-all sm:my-4 sm:align-middle sm:max-w-4xl sm:w-full animate-slide-up border" style="background: var(--flash-surface); border-color: var(--flash-border);">
            <div class="px-4 pt-5 pb-4 sm:p-5 sm:pb-4 relative text-base" style="background: var(--flash-surface);">
                <div class="flex items-center justify-between gap-4 mb-5">
                    <div class="flex items-center gap-2 flash-text">
                        <span class="text-[11px] font-bold tracking-[0.22em] uppercase font-mono" style="color: var(--flash-text-muted);">Sorteo:</span>
                        <p class="text-sm font-semibold flash-text" id="modalTitle">⚡ Nuevo sorteo</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button data-close-button type="button" class="h-7 w-7 rounded-md border flex items-center justify-center transition" style="border-color: var(--flash-border); color: var(--flash-text-muted);" aria-label="Cerrar">
                            <i class="fas fa-times text-[12px]"></i>
                        </button>
                    </div>
                </div>

<form id="formSorteo" class="h-full flex flex-col">
                    <input type="hidden" name="action" value="save_raffle">
                    <input type="hidden" name="raffle_id" id="raffleId" value="0">

                    <div class="border rounded-lg p-4 mb-5" style="background: var(--flash-surface); border-color: var(--flash-border);">
                        <div class="flex items-center justify-between gap-4">
                            <div class="text-sm font-bold tracking-[0.22em] uppercase" style="color: var(--flash-text-muted);">Modo</div>
                            <div class="flash-segment flex items-center">
                                <button type="button" onclick="setExecutionMode('standard_queue')" id="btnExecStandard" class="flash-segment-btn is-active px-3 py-1 text-xs font-bold">Standard Queue</button>
                                <button type="button" onclick="setExecutionMode('fast_batch')" id="btnExecFlash" class="flash-segment-btn px-3 py-1 text-xs font-bold">Flash Mode ⚡</button>
                            </div>
                            <input type="hidden" name="execution_mode" id="inputExecutionMode" value="standard_queue">
                        </div>
                    </div>

                    <div class="flash-tabs">
                        <div onclick="switchTab('tabGeneral')" id="btn-tabGeneral" class="flash-tab-btn active">General</div>
                        <div onclick="switchTab('tabEconomia')" id="btn-tabEconomia" class="flash-tab-btn">Economía</div>
                        <div onclick="switchTab('tabGamification')" id="btn-tabGamification" class="flash-tab-btn text-yellow-500"><i class="fas fa-dice mr-1"></i> Gamificación</div>
                    </div>

                    <div id="tabGeneral" class="tab-content active space-y-5">
                        <div class="border rounded-lg p-4" style="background: var(--flash-surface); border-color: var(--flash-border);">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="col-span-2">
                                    <label class="block text-[12px] font-semibold mb-2" style="color: var(--flash-text-muted);">TÍTULO</label>
                                    <input type="text" id="inputTitle" name="title" class="flash-input w-full py-2.5 px-3" placeholder="Ej: Sorteo Skin Legendaria" required>
                                </div>
                                <div class="col-span-2">
                                    <label class="block text-[12px] font-semibold mb-2 text-yellow-500">ANIMACIÓN DEL SORTEO</label>
                                    <select name="main_animation" id="selectMainAnim" onchange="onMainAnimChange()" class="flash-input w-full py-2.5 px-3 border-yellow-500/30">
                                        <option value="slot" selected>🎰 Slot Machine (Rápido)</option>
                                        <option value="roulette">🎡 Ruleta Horizontal (Clásico)</option>
                                    </select>
                                </div>
                                <div class="col-span-2">
                                    <label class="block text-[12px] font-semibold mb-2" style="color: var(--flash-text-muted);">LÍMITE DE PARTICIPANTES</label>
                                    <input type="number" id="inputMaxParticipants" name="max_participants" value="0" min="0" oninput="clampMaxParticipants()" class="flash-input w-full py-2.5 px-3">
                                    <p id="maxPartHelp" class="text-[10px] text-gray-500 mt-1">Al llegar a X participantes, el sorteo inicia de inmediato. (0 = sin límite)</p>
                                </div>
                            </div>
                        </div>

                        <div class="border rounded-lg p-4" style="background: var(--flash-surface); border-color: var(--flash-border);">
                            <div class="flex items-center justify-between gap-4 mb-3">
                                <div class="text-sm font-bold tracking-[0.22em] uppercase" style="color: var(--flash-text-muted);">Tiempos</div>
                                <div class="flash-segment flex items-center">
                                    <button type="button" onclick="setStartMode('now')" id="btnModeNow" class="flash-segment-btn is-active px-3 py-1 text-xs font-bold">AHORA</button>
                                    <button type="button" onclick="setStartMode('specific')" id="btnModeSpecific" class="flash-segment-btn px-3 py-1 text-xs font-bold">PROGRAMAR</button>
                                </div>
                                <input type="hidden" name="start_mode" id="inputStartMode" value="now">
                            </div>
                            <p id="tzHint" class="text-[11px] mb-3" style="color: var(--flash-text-muted);">
                                Ingresa las horas en <strong>tu hora local</strong>; se guardarán automáticamente en UTC.
                            </p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div id="divStartDate" class="flash-collapse">
                                    <label class="block text-[12px] font-semibold mb-2 text-gray-500">INICIO</label>
                                    <input type="datetime-local" id="inputStart" name="start_time" class="flash-input w-full py-2.5 px-3">
                                </div>
                                <div>
                                    <label class="block text-[12px] font-semibold mb-2 text-gray-500">CIERRE (TOPE)</label>
                                    <input type="datetime-local" id="inputEnd" name="end_time" class="flash-input w-full py-2.5 px-3" required>
                                </div>
                                <div>
                                    <label class="block text-[12px] font-semibold mb-2 text-gray-500">GANADORES</label>
                                    <input type="hidden" id="inputWinners" name="winners_count" value="1">
                                    <div id="divWinnersStd">
                                        <input type="number" id="inputWinnersStd" value="1" min="1" class="flash-input w-full py-2.5 px-3">
                                    </div>
                                    <div id="divWinnersFlash" class="hidden">
                                        <select id="selectWinnersFlash" class="flash-input w-full py-2.5 px-3">
                                            <option value="2">2</option>
                                            <option value="4">4</option>
                                            <option value="6">6</option>
                                            <option value="8">8</option>
                                            <option value="10">10</option>
                                        </select>
                                        <p id="txtFlashWinnersHelp" class="text-[10px] text-gray-500 mt-1">Flash mode requires winners in pairs (2 by 2).</p>
                                    </div>
                                </div>
                                <div id="divBurnCount">
                                    <label class="block text-[12px] font-semibold mb-2 text-gray-500">AL AGUA</label>
                                    <input type="number" id="inputBurn" name="burn_count" value="0" min="0" class="flash-input w-full py-2.5 px-3">
                                </div>
                                <div>
                                    <label class="block text-[12px] font-semibold mb-2 text-gray-500">REPLAY (MIN)</label>
                                    <input type="number" id="inputReplay" name="replay_minutes" value="60" class="flash-input w-full py-2.5 px-3">
                                </div>
                            </div>
                        </div>

                        <div class="border rounded-lg p-4" style="background: var(--flash-surface); border-color: var(--flash-border);">
                            <div class="flex items-center justify-between gap-2 mb-3">
                                <div class="text-sm font-bold tracking-[0.22em] uppercase" style="color: var(--flash-text-muted);">Premio</div>
                                <div class="flash-segment flex items-center">
                                    <button type="button" onclick="setPrizeMode('classic')" id="btnPrizeClassic" class="flash-segment-btn is-active px-3 py-1 text-xs font-bold">CLÁSICO</button>
                                    <button type="button" onclick="setPrizeMode('advanced')" id="btnPrizeAdvanced" class="flash-segment-btn px-3 py-1 text-xs font-bold">POR PUESTO / POOL</button>
                                </div>
                            </div>
                            <input type="hidden" name="prize_positions_json" id="inputPrizePositions" value="">
                            <div id="prizeClassicWrap">
                            <div class="grid grid-cols-3 gap-2 mb-3">
                                <label class="flash-btn-secondary px-2 py-2 flex justify-center items-center cursor-pointer text-xs"><input type="radio" name="prize_type" value="coins" checked onchange="togglePrizeInputs()" class="mr-1"> Monedas</label>
                                <label class="flash-btn-secondary px-2 py-2 flex justify-center items-center cursor-pointer text-xs"><input type="radio" name="prize_type" value="skin" onchange="togglePrizeInputs()" class="mr-1"> Skin</label>
                                <label class="flash-btn-secondary px-2 py-2 flex justify-center items-center cursor-pointer text-xs"><input type="radio" name="prize_type" value="product" onchange="togglePrizeInputs()" class="mr-1"> Producto</label>
                            </div>
                            <div id="containerCoins">
                                <input type="number" id="inputAmount" name="prize_amount" placeholder="Cantidad" class="flash-input w-full py-2.5 px-3">
                            </div>
                            <div id="containerItems" class="hidden">
                                <select id="selectApiItem" class="flash-input w-full py-2.5 px-3 mb-2"><option value="">-- Seleccionar --</option></select>
                                <input type="hidden" id="hiddenItemId" name="item_id"><input type="hidden" id="hiddenItemName" name="item_name"><input type="hidden" id="hiddenItemImage" name="item_image">
                                <div class="mt-2 flex gap-4 text-xs items-center p-2 rounded bg-black/20">
                                    <label class="font-bold text-gray-400">MODO:</label>
                                    <label><input type="radio" name="discount_type" value="100" checked onclick="$('#discInput').addClass('hidden')"> Gratis</label>
                                    <label><input type="radio" name="discount_type" value="partial" onclick="$('#discInput').removeClass('hidden')"> Oferta %</label>
                                    <span id="discInput" class="hidden"><input type="number" name="discount_percent" value="50" class="w-12 bg-transparent border-b text-center"> % OFF</span>
                                </div>
                            </div>
                            </div>
                            <div id="prizeBuilder" class="hidden space-y-3">
                                <p class="text-[11px]" style="color: var(--flash-text-muted);">Configura un premio por cada puesto: fijo (un solo premio) o pool (el ganador elige entre varias opciones). Los premios por categoría los resuelves tú al final registrando el ítem elegido.</p>
                                <div id="prizeBuilderPositions" class="space-y-3"></div>
                            </div>
                        </div>
                    </div>

                    <div id="tabEconomia" class="tab-content space-y-5">
                        <div class="p-4 rounded-lg border border-blue-500/30 bg-blue-500/5">
                            <label class="block text-xs font-bold text-blue-400 mb-1 uppercase">🎟️ Costo de Entrada (Monedas)</label>
                            <input type="number" id="inputEntryCost" name="entry_cost" value="0" min="0" class="flash-input w-full py-2.5 px-3 border-blue-500/30">
                            <p class="text-[10px] text-gray-500 mt-1">0 = Gratis. Se descontará del saldo del usuario.</p>

                            <div class="mt-3 flex items-center gap-2">
                                <input type="checkbox" id="checkPot" name="is_pot_enabled" class="flash-checkbox">
                                <span class="text-sm">Activar Bote Acumulado (Jackpot)</span>
                            </div>
                            <div id="divPotPercent" class="hidden mt-2 ml-5">
                                <label class="text-xs text-gray-400">% al Bote:</label>
                                <input type="number" name="pot_allocation_percent" value="100" class="flash-input w-20 py-1 px-2 text-xs">
                            </div>
                        </div>

                        <div class="border rounded-lg p-4" style="background: var(--flash-surface); border-color: var(--flash-border);">
                            <div class="text-xs font-bold text-gray-500 mb-2 uppercase">Restricciones</div>
                            <div class="space-y-2">
                                <label class="flex items-center gap-2"><input type="checkbox" name="req_verified" id="reqVerified"> Solo Verificados</label>
                                <label class="flex items-center gap-2"><input type="checkbox" id="chkRecent"> Compras Recientes</label>
                                <div id="divRecent" class="hidden ml-5 grid grid-cols-2 gap-2">
                                    <input type="date" name="req_min_date" id="reqMinDate" class="flash-input py-1 px-2 text-xs">
                                    <input type="number" name="req_min_orders" id="reqMinOrders" placeholder="Cant." class="flash-input py-1 px-2 text-xs">
                                </div>
                                <label class="flex items-center gap-2"><input type="checkbox" id="chkWhale"> Gasto Histórico</label>
                                <div id="divWhale" class="hidden ml-5">
                                    <input type="number" name="req_min_spent" id="reqMinSpent" placeholder="$ Minimo" class="flash-input w-full py-1 px-2 text-xs">
                                </div>
                            </div>
                        </div>

                        <!-- ===== SORTEO PRIVADO / EXCLUSIVO (lista de invitados) ===== -->
                        <div class="p-4 rounded-lg border border-purple-500/30 bg-purple-500/5">
                            <input type="checkbox" name="is_private" id="checkPrivate" class="flash-toggle-input">
                            <label for="checkPrivate" class="flash-toggle-body">
                                <div class="flash-toggle-meta">
                                    <span class="flash-toggle-icon" style="color:#c084fc; background: rgba(192,132,252,0.10);"><i class="fas fa-lock text-xs"></i></span>
                                    <div>
                                        <div class="flash-toggle-title text-xs">Sorteo Privado / Exclusivo</div>
                                        <div class="flash-toggle-subtitle">Solo podrán inscribirse los usuarios de la lista de invitados. El resto verá un aviso de sorteo privado. Los invitados siguen cumpliendo las restricciones de arriba.</div>
                                    </div>
                                </div>
                                <span class="flash-switch"></span>
                            </label>

                            <div id="rpPanel" class="rp-panel mt-3 hidden">
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <div class="text-xs font-bold uppercase" style="color:#c084fc;"><i class="fas fa-user-check"></i> Lista de invitados <span id="rpCount" class="rp-chip rp-chip--in">0</span></div>
                                    <span id="rpPendingHint" class="text-[10px] text-amber-400 hidden"><i class="fas fa-info-circle"></i> Se guardan al crear el sorteo</span>
                                </div>
                                <div class="relative mb-2">
                                    <input type="text" id="rpSearch" placeholder="Buscar por número, nombre público o nickname…" class="flash-input w-full py-2 px-3 text-sm" autocomplete="off">
                                    <div id="rpResults" class="rp-list mt-2"></div>
                                </div>
                                <div class="text-[10px] font-bold uppercase mb-1" style="color: var(--flash-text-muted);">Invitados</div>
                                <div id="rpInvited" class="rp-list"><div class="rp-empty">Aún no hay invitados.</div></div>
                            </div>
                        </div>

                        <!-- ===== ANTI-FRAUDE (IP + Huella) ===== -->
                        <div class="p-4 rounded-lg border border-orange-500/30 bg-orange-500/5">
                            <label class="block text-xs font-bold text-orange-400 mb-2 uppercase">🛡️ Anti-Multicuentas</label>
                            <label class="flex items-center justify-between gap-2 text-sm">
                                <span class="flex items-center gap-2">
                                    <input type="checkbox" name="af_ip_enabled" id="afIpEnabled" class="flash-checkbox" checked> Límite por IP
                                </span>
                                <span class="flex items-center gap-1 text-xs text-gray-400">
                                    máx <input type="number" name="af_ip_max" id="afIpMax" value="1" min="1" class="flash-input w-14 py-1 px-2 text-xs text-center"> /IP
                                </span>
                            </label>
                            <label class="flex items-center gap-2 text-sm mt-2">
                                <input type="checkbox" name="af_fp_enabled" id="afFpEnabled" class="flash-checkbox" checked> Huella de dispositivo (fingerprint)
                            </label>
                            <p class="text-[10px] text-gray-500 mt-2">Frena correos de Gmail múltiples en sorteos libres. <b>Los usuarios Verificados nunca se bloquean.</b></p>
                        </div>

                        <!-- ===== RECOMPENSAS EARLY-BIRD ===== -->
                        <div class="p-4 rounded-lg border border-amber-500/30 bg-amber-500/5">
                            <label class="block text-xs font-bold text-amber-400 mb-1 uppercase">🐤 Recompensas por Rapidez (Bananos)</label>
                            <p class="text-[10px] text-gray-500 mb-3">Premia a los primeros en entrar. Se pagan automáticamente al inscribirse.</p>
                            <select name="eb_mode" id="ebMode" class="flash-input w-full py-2 px-3 text-sm mb-3" onchange="toggleEarlyBird()">
                                <option value="off">Desactivado</option>
                                <option value="slots">Modo A · Pozo por Cupos</option>
                                <option value="time">Modo B · Pozo por Tiempo</option>
                                <option value="tiered">Modo C · Escalonado Manual</option>
                            </select>

                            <div id="ebSlots" class="hidden grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[10px] text-gray-400">Pozo total (bananos)</label>
                                    <input type="number" name="eb_pool_slots" id="ebPoolSlots" min="0" placeholder="500" class="flash-input w-full py-1.5 px-2 text-xs">
                                </div>
                                <div>
                                    <label class="text-[10px] text-gray-400">Límite de ganadores</label>
                                    <input type="number" name="eb_max_winners" id="ebMaxWinners" min="1" placeholder="5" class="flash-input w-full py-1.5 px-2 text-xs">
                                </div>
                                <p class="col-span-2 text-[10px] text-amber-300/80" id="ebSlotsHint">El pozo se reparte equitativamente entre los primeros N.</p>
                            </div>

                            <div id="ebTime" class="hidden grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[10px] text-gray-400">Pozo total (bananos)</label>
                                    <input type="number" name="eb_pool_time" id="ebPoolTime" min="0" placeholder="500" class="flash-input w-full py-1.5 px-2 text-xs">
                                </div>
                                <div>
                                    <label class="text-[10px] text-gray-400">Ventana (segundos)</label>
                                    <input type="number" name="eb_window_seconds" id="ebWindowSeconds" min="1" placeholder="60" class="flash-input w-full py-1.5 px-2 text-xs">
                                </div>
                                <p class="col-span-2 text-[10px] text-amber-300/80">La ventana corre desde que el sorteo abre (su <code>start_time</code>). Todos los que entren dentro reparten el pozo a partes iguales (se abona al cerrar la ventana). Si nadie entra, el pozo no se reparte. Con el sorteo abierto puedes pulsar <i class="fas fa-stopwatch"></i> en la tabla para iniciar el cronómetro justo al anunciarlo.</p>
                            </div>

                            <div id="ebTiered" class="hidden">
                                <div id="ebTiersList" class="space-y-2"></div>
                                <button type="button" onclick="ebAddTier()" class="flash-btn-secondary text-xs py-1 px-3 mt-2"><i class="fas fa-plus"></i> Añadir puesto</button>
                                <p class="text-[10px] text-amber-300/80 mt-1">Define el premio exacto por puesto (ej. #1 → 500, #2 → 250).</p>
                            </div>
                        </div>
                    </div>

                    <div id="tabGamification" class="tab-content space-y-5">
                        <div class="p-3 bg-yellow-500/10 border border-yellow-500/20 rounded text-xs text-yellow-200 text-center">
                            Configura qué sucede <b>después</b> de elegir al ganador.
                        </div>

                        <div class="p-4 rounded-lg border border-emerald-500/30 bg-emerald-500/5">
                            <input type="checkbox" name="allow_pre_choice" id="checkPreChoice" class="flash-toggle-input" checked>
                            <label for="checkPreChoice" class="flash-toggle-body">
                                <div class="flash-toggle-meta">
                                    <span class="flash-toggle-icon"><i class="fas fa-hand-pointer text-xs"></i></span>
                                    <div>
                                        <div class="flash-toggle-title text-xs">Permitir elegir anticipo de premio</div>
                                        <div class="flash-toggle-subtitle">Al inscribirse, el usuario elige su premio por adelantado (modal "¿Qué premio prefieres?"). Si lo desactivas, el ganador elige obligatoriamente en vivo.</div>
                                    </div>
                                </div>
                                <span class="flash-switch"></span>
                            </label>
                        </div>

                        <div class="flex items-center justify-between p-4 rounded-lg border" style="background: var(--flash-muted); border-color: var(--flash-border);">
                            <div>
                                <div class="font-bold text-sm">Opción de Canje (Cashback)</div>
                                <div class="text-[10px] text-gray-500">Permitir cambiar el premio por saldo.</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="number" name="cashback_percent" id="inputCashback" value="50" class="flash-input w-16 py-1 px-2 text-right text-xs"> <span class="text-xs">%</span>
                                <input type="checkbox" name="allow_cashback" id="checkCashback" class="h-5 w-5">
                            </div>
                        </div>

                        <div id="divDoubleOrNothingBlock" class="p-4 rounded-lg border border-purple-500/30 bg-purple-500/5">
                            <div class="flex justify-between items-center mb-3">
                                <div>
                                    <div class="font-bold text-sm text-purple-400">🎲 Doble o Nada</div>
                                    <div class="text-[10px] text-gray-500">Arriesgar premio por x2 (Blockchain).</div>
                                </div>
                                <input type="checkbox" name="allow_double_or_nothing" id="checkDouble" class="h-5 w-5 text-purple-600" onchange="$('#divAnimSelect').toggleClass('hidden', !this.checked)">
                            </div>

                            <div id="divAnimSelect" class="hidden mt-3 pt-3 border-t border-purple-500/20">
                                <label class="block text-[10px] font-bold text-purple-300 mb-1 uppercase">Animación del Minijuego</label>
                                <select name="gamble_animation" id="selectAnim" class="flash-input w-full py-2 px-3 text-xs border-purple-500/30">
                                    <option value="coin">🪙 Moneda al Aire</option>
                                    <option value="roulette">🎡 Ruleta</option>
                                    <option value="slot">🎰 Slot Machine</option>
                                </select>

                                <div class="mt-3">
                                    <input type="checkbox" name="allow_winner_animation_choice" id="checkAnimChoice" class="flash-toggle-input">
                                    <label for="checkAnimChoice" class="flash-toggle-body">
                                        <div class="flash-toggle-meta">
                                            <span class="flash-toggle-icon"><i class="fas fa-gamepad text-xs"></i></span>
                                            <div>
                                                <div class="flash-toggle-title text-xs">Ganador elige animación</div>
                                                <div class="flash-toggle-subtitle">Permitir al ganador elegir entre Moneda, Ruleta o Slot</div>
                                            </div>
                                        </div>
                                        <span class="flash-switch"></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[12px] font-semibold mb-2 text-gray-500">TIEMPO PARA DECIDIR (SEG)</label>
                            <input type="number" name="decision_timeout" id="inputTimeout" value="60" class="flash-input w-full py-2.5 px-3">
                        </div>
                    </div>

                </form>
            </div>

            <div class="px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t" style="background: var(--flash-surface); border-color: var(--flash-border);">
                <button onclick="saveRaffle()" class="flash-btn-primary w-full inline-flex justify-center shadow-sm px-4 py-2 font-semibold sm:ml-3 sm:w-auto sm:text-sm">Guardar</button>
                <button data-close-button type="button" class="flash-btn-secondary w-full inline-flex justify-center shadow-sm px-4 py-2 font-semibold sm:w-auto sm:text-sm">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!--  MODAL: Sonidos Globales (standalone, independent of raffles)  -->
<!-- ============================================================= -->
<div id="modalGlobalAudio" class="fixed inset-0 overflow-y-auto z-50 hidden" role="dialog" aria-modal="true">
    <div class="flex items-start justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div id="modalGlobalAudioOverlay" class="fixed inset-0 backdrop-blur-[8px] transition-opacity" aria-hidden="true" style="background: var(--flash-overlay);"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom rounded-lg text-left overflow-hidden shadow-2xl transform transition-all sm:my-4 sm:align-middle sm:max-w-2xl sm:w-full border" style="background: var(--flash-surface); border-color: var(--flash-border);">
            <div class="px-5 pt-5 pb-4 relative" style="background: var(--flash-surface);">
                <div class="flex items-center justify-between gap-4 mb-4">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🎵</span>
                        <p class="text-sm font-bold flash-text">Sonidos Globales</p>
                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-purple-500/20 text-purple-300 font-bold">Aplica a todos los sorteos</span>
                    </div>
                    <button onclick="closeGlobalAudioModal()" type="button" class="h-7 w-7 rounded-md border flex items-center justify-center transition" style="border-color: var(--flash-border); color: var(--flash-text-muted);" aria-label="Cerrar">
                        <i class="fas fa-times text-[12px]"></i>
                    </button>
                </div>

                <div class="space-y-4 max-h-[65vh] overflow-y-auto pr-1">
                    <div class="p-3 bg-purple-500/10 border border-purple-500/20 rounded text-xs text-purple-200 text-center">
                        <i class="fas fa-volume-up mr-1"></i> Asigna archivos de audio a cada evento del sorteo. Dejar vacío = <b>sonido sintetizado por defecto</b>.
                    </div>

                    <div class="border rounded-lg p-4 space-y-4" style="background: var(--flash-surface); border-color: var(--flash-border);">
                        <div class="text-sm font-bold tracking-wide uppercase" style="color: var(--flash-text-muted);"><i class="fas fa-trophy text-yellow-400 mr-1"></i> Sorteo Principal</div>
                        <div>
                            <label class="block text-[11px] font-semibold mb-1 text-yellow-400">GANADOR #1 (1er lugar)</label>
                            <select class="flash-input w-full py-2 px-3 text-xs sfx-select" data-key="winner_1"><option value="">-- Silencio --</option></select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold mb-1 text-yellow-400">GANADOR #2 (2do lugar)</label>
                            <select class="flash-input w-full py-2 px-3 text-xs sfx-select" data-key="winner_2"><option value="">-- Silencio --</option></select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold mb-1 text-yellow-400">GANADOR #3 (3er lugar, repite si hay más)</label>
                            <select class="flash-input w-full py-2 px-3 text-xs sfx-select" data-key="winner_3"><option value="">-- Silencio --</option></select>
                        </div>
                    </div>

                    <div class="border rounded-lg p-4 space-y-4" style="background: var(--flash-surface); border-color: var(--flash-border);">
                        <div class="text-sm font-bold tracking-wide uppercase" style="color: var(--flash-text-muted);"><i class="fas fa-dice text-purple-400 mr-1"></i> Doble o Nada</div>
                        <div>
                            <label class="block text-[11px] font-semibold mb-1 text-purple-300">INTRO / IMPACTO CINEMATOGRÁFICO</label>
                            <select class="flash-input w-full py-2 px-3 text-xs sfx-select" data-key="doble_intro"><option value="">-- Silencio --</option></select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold mb-1 text-purple-300">SALA DE ESPERA BLOCKCHAIN</label>
                            <select class="flash-input w-full py-2 px-3 text-xs sfx-select" data-key="blockchain_ambient"><option value="">-- Silencio --</option></select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold mb-1 text-green-400">VICTORIA</label>
                            <select class="flash-input w-full py-2 px-3 text-xs sfx-select" data-key="doble_victory"><option value="">-- Silencio --</option></select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold mb-1 text-red-400">DERROTA</label>
                            <select class="flash-input w-full py-2 px-3 text-xs sfx-select" data-key="doble_defeat"><option value="">-- Silencio --</option></select>
                        </div>
                    </div>

                    <div class="border rounded-lg p-4 space-y-4" style="background: var(--flash-surface); border-color: var(--flash-border);">
                        <div class="text-sm font-bold tracking-wide uppercase" style="color: var(--flash-text-muted);"><i class="fas fa-flag-checkered text-cyan-400 mr-1"></i> Pantalla Final</div>
                        <div>
                            <label class="block text-[11px] font-semibold mb-1 text-cyan-300">RESUMEN "TENEMOS GANADORES"</label>
                            <select class="flash-input w-full py-2 px-3 text-xs sfx-select" data-key="summary_screen"><option value="">-- Silencio --</option></select>
                        </div>
                    </div>

                    <div class="border rounded-lg p-4" style="background: var(--flash-surface); border-color: var(--flash-border);">
                        <div class="flex items-center justify-between mb-3">
                            <div class="text-sm font-bold tracking-wide uppercase" style="color: var(--flash-text-muted);"><i class="fas fa-upload text-cyan-400 mr-1"></i> Subir Nuevo Audio</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="file" id="sfxUploadInput" accept=".mp3,.wav,.ogg,.webm,.m4a" class="flash-input flex-1 py-1.5 px-3 text-xs">
                            <button type="button" onclick="uploadAudioFile()" class="flash-btn-primary px-4 py-2 text-xs font-bold">Subir</button>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Formatos: MP3, WAV, OGG, WEBM, M4A. Máx 10MB. Se guardará en <code>/assets/fx/audio/</code></p>
                        <div id="sfxUploadStatus" class="text-[10px] mt-1 hidden"></div>
                    </div>
                </div>
            </div>

            <div class="px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t" style="background: var(--flash-surface); border-color: var(--flash-border);">
                <button onclick="saveGlobalAudio()" class="flash-btn-primary w-full inline-flex justify-center shadow-sm px-4 py-2 font-semibold sm:ml-3 sm:w-auto sm:text-sm">💾 Guardar Sonidos</button>
                <button onclick="closeGlobalAudioModal()" type="button" class="flash-btn-secondary w-full inline-flex justify-center shadow-sm px-4 py-2 font-semibold sm:w-auto sm:text-sm">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL: MASCOTA BANANO (slots de imagen del Portal de Transparencia) ===== -->
<div id="modalBanano" class="fixed inset-0 overflow-y-auto z-50 hidden" role="dialog" aria-modal="true">
    <div class="flex items-start justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div id="modalBananoOverlay" class="fixed inset-0 backdrop-blur-[8px] transition-opacity" aria-hidden="true" style="background: var(--flash-overlay);"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom rounded-lg text-left overflow-hidden shadow-2xl transform transition-all sm:my-4 sm:align-middle sm:max-w-3xl sm:w-full border" style="background: var(--flash-surface); border-color: var(--flash-border);">
            <div class="px-5 pt-5 pb-4 relative" style="background: var(--flash-surface);">
                <div class="flex items-center justify-between gap-4 mb-4">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🍌</span>
                        <p class="text-sm font-bold flash-text">Mascota Banano — Imágenes</p>
                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-yellow-500/20 text-yellow-300 font-bold">Portal de Transparencia</span>
                    </div>
                    <button onclick="closeBananoModal()" type="button" class="h-7 w-7 rounded-md border flex items-center justify-center transition" style="border-color: var(--flash-border); color: var(--flash-text-muted);" aria-label="Cerrar">
                        <i class="fas fa-times text-[12px]"></i>
                    </button>
                </div>

                <div class="space-y-4 max-h-[70vh] overflow-y-auto pr-1">
                    <div class="p-3 bg-yellow-500/10 border border-yellow-500/20 rounded text-xs text-yellow-200 text-center">
                        <i class="fas fa-image mr-1"></i> Sube PNG o WebP <b>con fondo transparente</b> para cada ubicación. Un slot sin imagen simplemente no se muestra en la página pública <code>/juegos/sorteo_flash/transparencia.php</code>. Ajusta escala, rotación y posición con los deslizadores (la vista previa es en tiempo real) y pulsa <b>Guardar ajustes</b>.
                    </div>
                    <div class="flex gap-2">
                        <button id="bananoTabDesktop" onclick="switchBananoTab('desktop')" class="flash-btn-primary flex-1 px-3 py-2 text-xs font-bold">🖥️ Vista Escritorio</button>
                        <button id="bananoTabMobile" onclick="switchBananoTab('mobile')" class="flash-btn-secondary flex-1 px-3 py-2 text-xs font-bold">📱 Vista Móvil</button>
                    </div>
                    <div id="bananoSlotsGrid" class="grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
                    <div class="mt-4 pt-3 border-t" style="border-color: var(--flash-border);">
                        <p class="text-xs font-bold flash-text mb-1">📖 Página manga — "¿Quién podría hacer trampa?"</p>
                        <p class="text-[10px] flash-text-muted mb-2">Imagen única (escritorio y móvil) que reemplaza la tabla de esa sección. Sin imagen, la sección no se muestra en la página pública.</p>
                        <div id="bananoPageManga"></div>
                    </div>
                </div>
            </div>
            <div class="px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t" style="background: var(--flash-surface); border-color: var(--flash-border);">
                <a href="../juegos/sorteo_flash/transparencia.php" target="_blank" rel="noopener" class="flash-btn-primary w-full inline-flex justify-center shadow-sm px-4 py-2 font-semibold sm:ml-3 sm:w-auto sm:text-sm">👁️ Ver página pública</a>
                <button onclick="closeBananoModal()" type="button" class="flash-btn-secondary w-full inline-flex justify-center shadow-sm px-4 py-2 font-semibold sm:w-auto sm:text-sm">Cerrar</button>
            </div>
        </div>
    </div>
</div>
<input type="file" id="bananoSlotFileInput" accept=".png,.webp" class="hidden">
<input type="file" id="bananoPageFileInput" accept=".png,.webp,.jpg,.jpeg" class="hidden">

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const API_URL = 'sorteos_flash/api_sorteos.php';
let shopDataCache = null; 
let staticProductsCache = null;
let categoryPresetsCache = [];
let modalSorteoCloseTimer = null;
// Estado del constructor de premios por puesto (Schema 2)
let prizeMode = 'classic'; // 'classic' | 'advanced'
let pbState = []; // [{position, prize:{mode:'fixed'|'pool'|'combo', label, option, options[], items[]}}]

$(document).ready(function() {
    cargarSorteos();
    preloadFormData();
    initModalSorteo();
    initRequirementsToggles();
    initExecutionModeControls();
});

// --- LÓGICA DE DATOS ---
async function preloadFormData() {
    try {
        const res = await $.get(`${API_URL}?action=get_form_data`);
        if(res.status === 'success') {
            shopDataCache = res.data.fortnite_sections; // Array indexado
            staticProductsCache = res.data.static_products;
            categoryPresetsCache = res.data.category_presets || [];
        }
    } catch (e) { console.error(e); }
}

// CORRECCIÓN DEL BUCLE FOR EACH
function populateItemSelector(type) {
    const select = $('#selectApiItem');
    select.empty();
    
    if (type === 'skin') {
        select.append('<option value="">-- Selecciona una skin --</option>');
        
        if(shopDataCache && Array.isArray(shopDataCache)) {
            // shopDataCache es un ARRAY de secciones (shop_helper 4.1)
            shopDataCache.forEach(section => {
                const group = $('<optgroup>').attr('label', section.name);
                
                if(section.entries && Array.isArray(section.entries)) {
                    section.entries.forEach(entry => {
                        // El item real está dentro de 'items' en la posición 0
                        const itemData = entry.items && entry.items[0] ? entry.items[0] : null;
                        
                        if(itemData) {
                            const jsonData = JSON.stringify({
                                id: entry.offerId, // O el ID que prefieras
                                name: itemData.name,
                                image: itemData.images.icon || itemData.images.featured || ''
                            });
                            group.append($('<option>').val(jsonData).text(itemData.name));
                        }
                    });
                }
                select.append(group);
            });
        }
    } else if (type === 'product') {
        select.append('<option value="">-- Producto Estático --</option>');
        if(staticProductsCache) {
            staticProductsCache.forEach(prod => {
                const jsonData = JSON.stringify({ id: prod.id, name: prod.nombre, image: prod.imagen_url || '' });
                select.append($('<option>').val(jsonData).text(prod.nombre));
            });
        }
    }
}

$('#selectApiItem').on('change', function() {
    const val = $(this).val();
    if(!val) { $('#itemPreview').addClass('hidden'); return; }
    const data = JSON.parse(val);
    $('#hiddenItemId').val(data.id);
    $('#hiddenItemName').val(data.name);
    $('#hiddenItemImage').val(data.image);
    $('#previewName').text(data.name);
    if(data.image) { $('#previewImg').attr('src', data.image); $('#itemPreview').removeClass('hidden'); }
});

// --- UI HELPERS ---
function togglePrizeInputs() {
    const type = $('input[name="prize_type"]:checked').val();
    if (type === 'coins') {
        $('#containerCoins').removeClass('hidden'); $('#containerItems').addClass('hidden');
    } else {
        $('#containerCoins').addClass('hidden'); $('#containerItems').removeClass('hidden');
        populateItemSelector(type);
    }
}

// ===== CONSTRUCTOR DE PREMIOS POR PUESTO (Schema 2) =====
function setPrizeMode(mode) {
    prizeMode = (mode === 'advanced') ? 'advanced' : 'classic';
    $('#btnPrizeClassic').toggleClass('is-active', prizeMode === 'classic');
    $('#btnPrizeAdvanced').toggleClass('is-active', prizeMode === 'advanced');
    $('#prizeClassicWrap').toggleClass('hidden', prizeMode === 'advanced');
    $('#prizeBuilder').toggleClass('hidden', prizeMode === 'classic');
    if (prizeMode === 'advanced') { pbSyncPositions(); pbRender(); }
}

function pbNewOption(kind) {
    kind = kind || 'catalog';
    if (kind === 'coins')    return { kind: 'coins', amount: 0 };
    if (kind === 'custom')   return { kind: 'custom', name: '', image: '', description: '', value_ref: { unit: 'vbucks', amount: 0 } };
    if (kind === 'category') {
        const p = categoryPresetsCache[0] || { key: 'skin', label: 'Skin', vbucks: 1500 };
        return { kind: 'category', preset_key: p.key, label: p.label, image: '', value_ref: { unit: 'vbucks', amount: p.vbucks } };
    }
    if (kind === 'product')  return { kind: 'product', id: '', name: '', image: '' };
    return { kind: 'catalog', id: '', name: '', image: '' };
}

function pbSyncPositions() {
    const winners = Math.max(1, parseInt($('#inputWinners').val() || '1', 10) || 1);
    while (pbState.length < winners) {
        pbState.push({ position: pbState.length + 1, prize: { mode: 'fixed', option: pbNewOption('catalog') } });
    }
    if (pbState.length > winners) pbState = pbState.slice(0, winners);
    pbState.forEach((e, i) => { e.position = i + 1; });
}

function pbCatalogOptionsHtml(selectedId) {
    let html = '<option value="">-- Ítem del catálogo --</option>';
    if (shopDataCache && Array.isArray(shopDataCache)) {
        shopDataCache.forEach(section => {
            html += `<optgroup label="${rfEsc(section.name)}">`;
            (section.entries || []).forEach(entry => {
                const it = entry.items && entry.items[0] ? entry.items[0] : null;
                if (!it) return;
                const img = (it.images && (it.images.icon || it.images.featured)) || '';
                const sel = (entry.offerId === selectedId) ? ' selected' : '';
                html += `<option value="${rfEsc(entry.offerId)}" data-name="${rfEsc(it.name)}" data-image="${rfEsc(img)}"${sel}>${rfEsc(it.name)}</option>`;
            });
            html += '</optgroup>';
        });
    }
    return html;
}

function pbProductOptionsHtml(selectedId) {
    let html = '<option value="">-- Producto estático --</option>';
    (staticProductsCache || []).forEach(prod => {
        const sel = (String(prod.id) === String(selectedId)) ? ' selected' : '';
        html += `<option value="${rfEsc(prod.id)}" data-name="${rfEsc(prod.nombre)}" data-image="${rfEsc(prod.imagen_url || '')}"${sel}>${rfEsc(prod.nombre)}</option>`;
    });
    return html;
}

function pbPresetOptionsHtml(selectedKey) {
    let html = '';
    (categoryPresetsCache || []).forEach(p => {
        const sel = (p.key === selectedKey) ? ' selected' : '';
        html += `<option value="${rfEsc(p.key)}"${sel}>${rfEsc(p.label)} (${p.vbucks} pavos est.)</option>`;
    });
    return html;
}

function pbOptionHtml(p, i, opt, isPool, allowCategory) {
    if (allowCategory === undefined) allowCategory = true;
    const kinds = [['catalog','Catálogo'],['product','Producto'],['coins','Monedas'],['custom','Personalizado']];
    if (allowCategory) kinds.push(['category','Categoría']);
    let kindSel = `<select class="flash-input pb-kind-select py-1 px-2 text-xs" onchange="pbChangeKind(${p},'${i}',this.value)">`;
    kinds.forEach(k => { kindSel += `<option value="${k[0]}"${opt.kind===k[0]?' selected':''}>${k[1]}</option>`; });
    kindSel += '</select>';

    let body = '';
    if (!opt) return '';
    if (opt.kind === 'coins') {
        body = `<input type="number" min="1" placeholder="Cantidad de Bananos" value="${opt.amount || ''}" class="flash-input w-full py-1 px-2 text-xs" onchange="pbSetOpt(${p},'${i}','amount',parseFloat(this.value)||0)">`;
    } else if (opt.kind === 'catalog') {
        body = `<select class="flash-input w-full py-1 px-2 text-xs" onchange="pbPickItem(${p},'${i}',this)">${pbCatalogOptionsHtml(opt.id)}</select>`;
    } else if (opt.kind === 'product') {
        body = `<select class="flash-input w-full py-1 px-2 text-xs" onchange="pbPickItem(${p},'${i}',this)">${pbProductOptionsHtml(opt.id)}</select>`;
    } else if (opt.kind === 'custom') {
        body = `
            <input type="text" placeholder="Título del premio" value="${rfEsc(opt.name || '')}" class="flash-input w-full py-1 px-2 text-xs" onchange="pbSetOpt(${p},'${i}','name',this.value)">
            <input type="text" placeholder="Descripción (opcional)" value="${rfEsc(opt.description || '')}" class="flash-input w-full py-1 px-2 text-xs" onchange="pbSetOpt(${p},'${i}','description',this.value)">
            <div class="pb-inline-row">
                <span class="pb-hint">Valor de referencia:</span>
                <input type="number" min="0.01" step="0.01" value="${(opt.value_ref && opt.value_ref.amount) || ''}" class="flash-input w-20 py-1 px-2 text-xs" onchange="pbSetValueRef(${p},'${i}','amount',parseFloat(this.value)||0)">
                <select class="flash-input py-1 px-2 text-xs" onchange="pbSetValueRef(${p},'${i}','unit',this.value)">
                    <option value="vbucks"${(!opt.value_ref || opt.value_ref.unit==='vbucks')?' selected':''}>Pavos</option>
                    <option value="soles"${(opt.value_ref && opt.value_ref.unit==='soles')?' selected':''}>Soles</option>
                </select>
                <button type="button" class="flash-btn-secondary px-2 py-1 text-[10px]" onclick="pbUploadImage(${p},'${i}')">${opt.image ? '🖼️ Cambiar imagen' : '🖼️ Subir imagen'}</button>
            </div>`;
    } else if (opt.kind === 'category') {
        body = `
            <div class="pb-inline-row">
                <select class="flash-input py-1 px-2 text-xs flex-1" onchange="pbPickPreset(${p},'${i}',this.value)">${pbPresetOptionsHtml(opt.preset_key)}</select>
                <button type="button" class="flash-btn-secondary px-2 py-1 text-[10px]" onclick="pbUploadImage(${p},'${i}')">${opt.image ? '🖼️ Cambiar imagen' : '🖼️ Imagen'}</button>
            </div>
            <input type="text" placeholder="Nombre visible (ej. 1 Skin a tu elección)" value="${rfEsc(opt.label || '')}" class="flash-input w-full py-1 px-2 text-xs" onchange="pbSetOpt(${p},'${i}','label',this.value)">
            <div class="pb-inline-row">
                <span class="pb-hint">Valor estándar:</span>
                <input type="number" min="0.01" step="0.01" value="${(opt.value_ref && opt.value_ref.amount) || ''}" class="flash-input w-20 py-1 px-2 text-xs" onchange="pbSetValueRef(${p},'${i}','amount',parseFloat(this.value)||0)">
                <select class="flash-input py-1 px-2 text-xs" onchange="pbSetValueRef(${p},'${i}','unit',this.value)">
                    <option value="vbucks"${(!opt.value_ref || opt.value_ref.unit==='vbucks')?' selected':''}>Pavos</option>
                    <option value="soles"${(opt.value_ref && opt.value_ref.unit==='soles')?' selected':''}>Soles</option>
                </select>
                <span class="pb-hint">Tú registras el ítem elegido al final.</span>
            </div>`;
    }

    const thumb = opt.image
        ? `<img src="${rfEsc(opt.image)}" class="pb-option-thumb" alt="">`
        : `<div class="pb-option-thumb pb-option-thumb--empty">🎁</div>`;
    const removeBtn = isPool ? `<button type="button" class="pb-remove-btn" title="Quitar opción" onclick="pbRemoveOption(${p},'${i}')">✖</button>` : '';
    return `<div class="pb-option">
        <div class="pb-option-head">${kindSel}${thumb}<span class="pb-option-name">${rfEsc(opt.name || opt.label || (isPool ? ('Opción ' + (i + 1)) : ''))}</span>${removeBtn}</div>
        <div class="pb-option-body">${body}</div>
    </div>`;
}

function pbRender() {
    const $wrap = $('#prizeBuilderPositions');
    let html = '';
    pbState.forEach((entry, idx) => {
        const p = idx;
        const prize = entry.prize;
        const isPool = prize.mode === 'pool';
        const isCombo = prize.mode === 'combo';
        let optionsHtml = '';
        if (isCombo) {
            const items = prize.items || [];
            const hasPool = items.some(it => it && it.mode === 'pool');
            items.forEach((item, ci) => {
                const removeItemBtn = items.length > 2 ? `<button type="button" class="pb-remove-btn" title="Quitar premio del combo" onclick="pbComboRemoveItem(${p},${ci})">✖</button>` : '';
                if (item.mode === 'pool') {
                    let poolHtml = '';
                    (item.options || []).forEach((opt, oi) => { poolHtml += pbOptionHtml(p, 'c' + ci + '_o' + oi, opt, true); });
                    poolHtml += `<button type="button" class="pb-add-option" onclick="pbComboAddPoolOption(${p},${ci})">+ Añadir opción al pool</button>`;
                    optionsHtml += `<div class="pb-combo-item pb-combo-item--pool">
                        <div class="pb-combo-item-head"><span class="pb-combo-item-tag">🎰 POOL A ELEGIR</span>${removeItemBtn}</div>
                        <div class="pb-pool-label-row"><span class="pb-pool-ico"><i class="fas fa-layer-group"></i></span><input type="text" placeholder="Título del pool (ej. Elige tu skin)" value="${rfEsc(item.label || '')}" class="flash-input flex-1 py-1 px-2 text-xs" onchange="pbComboSetPoolLabel(${p},${ci},this.value)"></div>
                        ${poolHtml}
                    </div>`;
                } else {
                    optionsHtml += `<div class="pb-combo-item">
                        <div class="pb-combo-item-head"><span class="pb-combo-item-tag">🎁 PREMIO ${ci + 1}</span>${removeItemBtn}</div>
                        ${pbOptionHtml(p, 'c' + ci, item.option || pbNewOption('catalog'), false, false)}
                    </div>`;
                }
            });
            optionsHtml += `<div class="pb-inline-row">
                <button type="button" class="pb-add-option" onclick="pbComboAddItem(${p},'fixed')">+ Añadir premio fijo</button>
                ${!hasPool ? `<button type="button" class="pb-add-option" onclick="pbComboAddItem(${p},'pool')">+ Añadir pool a elegir</button>` : ''}
            </div>`;
        } else if (isPool) {
            (prize.options || []).forEach((opt, i) => { optionsHtml += pbOptionHtml(p, i, opt, true); });
            optionsHtml += `<button type="button" class="pb-add-option" onclick="pbAddOption(${p})">+ Añadir opción al pool</button>`;
        } else {
            optionsHtml = pbOptionHtml(p, 0, prize.option || pbNewOption('catalog'), false);
        }
        html += `<div class="pb-position-card">
            <div class="pb-position-head">
                <span class="pb-position-title">🏆 PUESTO ${entry.position}°</span>
                <div class="flash-segment flex items-center">
                    <button type="button" class="flash-segment-btn px-2 py-1 text-[10px] font-bold ${(!isPool && !isCombo) ? 'is-active' : ''}" onclick="pbSetPrizeMode(${p},'fixed')">PREMIO FIJO</button>
                    <button type="button" class="flash-segment-btn px-2 py-1 text-[10px] font-bold ${isPool ? 'is-active' : ''}" onclick="pbSetPrizeMode(${p},'pool')">POOL (ELIGE)</button>
                    <button type="button" class="flash-segment-btn px-2 py-1 text-[10px] font-bold ${isCombo ? 'is-active' : ''}" onclick="pbSetPrizeMode(${p},'combo')">COMBO (PACK)</button>
                </div>
            </div>
            ${isPool ? `<div class="pb-pool-label-row"><span class="pb-pool-ico"><i class="fas fa-layer-group"></i></span><input type="text" placeholder="Título del pool (ej. Elige tu premio)" value="${rfEsc(prize.label || '')}" class="flash-input flex-1 py-1 px-2 text-xs" onchange="pbSetLabel(${p},this.value)"></div>` : ''}
            ${isCombo ? `<div class="pb-pool-label-row"><span class="pb-pool-ico"><i class="fas fa-gifts"></i></span><input type="text" placeholder="Título del combo (ej. Pack ganador)" value="${rfEsc(prize.label || '')}" class="flash-input flex-1 py-1 px-2 text-xs" onchange="pbSetLabel(${p},this.value)"></div>` : ''}
            <div>${optionsHtml}</div>
        </div>`;
    });
    $wrap.html(html);
}

function pbSetPrizeMode(p, mode) {
    const prize = pbState[p].prize;
    if (mode === prize.mode) return;
    if (mode === 'pool') {
        if (prize.mode === 'combo') {
            const poolItem = (prize.items || []).find(it => it && it.mode === 'pool');
            const firstFixed = (prize.items || []).find(it => it && it.mode !== 'pool');
            pbState[p].prize = poolItem
                ? { mode: 'pool', label: poolItem.label || 'Elige tu premio', options: poolItem.options || [] }
                : { mode: 'pool', label: 'Elige tu premio', options: [(firstFixed && firstFixed.option) || pbNewOption('catalog'), pbNewOption('catalog')] };
        } else {
            const first = prize.option || pbNewOption('catalog');
            pbState[p].prize = { mode: 'pool', label: prize.label || 'Elige tu premio', options: [first, pbNewOption('catalog')] };
        }
    } else if (mode === 'fixed') {
        let opt = prize.option;
        if (prize.mode === 'pool') opt = (prize.options && prize.options[0]);
        if (prize.mode === 'combo') {
            const firstFixed = (prize.items || []).find(it => it && it.mode !== 'pool');
            const poolItem = (prize.items || []).find(it => it && it.mode === 'pool');
            opt = (firstFixed && firstFixed.option) || (poolItem && poolItem.options && poolItem.options[0]);
        }
        pbState[p].prize = { mode: 'fixed', option: opt || pbNewOption('catalog') };
    } else if (mode === 'combo') {
        let items;
        if (prize.mode === 'pool') {
            items = [
                { mode: 'fixed', option: pbNewOption('coins') },
                { mode: 'pool', label: prize.label || 'Elige tu premio', options: prize.options || [] }
            ];
        } else {
            items = [
                { mode: 'fixed', option: prize.option || pbNewOption('catalog') },
                { mode: 'fixed', option: pbNewOption('coins') }
            ];
        }
        pbState[p].prize = { mode: 'combo', label: 'Combo de premios', items: items };
    }
    pbRender();
}

function pbComboKey(i) {
    const m = String(i).match(/^c(\d+)(?:_o(\d+))?$/);
    return m ? { item: parseInt(m[1], 10), opt: (m[2] !== undefined) ? parseInt(m[2], 10) : null } : null;
}

function pbGetOpt(p, i) {
    const prize = pbState[p].prize;
    if (prize.mode === 'combo') {
        const k = pbComboKey(i);
        const item = k ? (prize.items || [])[k.item] : null;
        if (!item) return null;
        return (item.mode === 'pool') ? (item.options || [])[k.opt || 0] : item.option;
    }
    return (prize.mode === 'pool') ? prize.options[i] : prize.option;
}
function pbSetLabel(p, v) { pbState[p].prize.label = v; }
function pbSetOpt(p, i, field, value) { const o = pbGetOpt(p, i); if (o) o[field] = value; }
function pbSetValueRef(p, i, field, value) {
    const opt = pbGetOpt(p, i);
    if (!opt) return;
    if (!opt.value_ref) opt.value_ref = { unit: 'vbucks', amount: 0 };
    opt.value_ref[field] = value;
}
function pbChangeKind(p, i, kind) {
    const prize = pbState[p].prize;
    const fresh = pbNewOption(kind);
    if (prize.mode === 'combo') {
        const k = pbComboKey(i);
        const item = k ? (prize.items || [])[k.item] : null;
        if (!item) return;
        if (item.mode === 'pool') item.options[k.opt || 0] = fresh; else item.option = fresh;
    } else if (prize.mode === 'pool') {
        prize.options[i] = fresh;
    } else {
        prize.option = fresh;
    }
    pbRender();
}
function pbPickItem(p, i, selectEl) {
    const $o = $(selectEl).find('option:selected');
    const opt = pbGetOpt(p, i);
    if (!opt) return;
    opt.id = $o.val();
    opt.name = $o.data('name') || '';
    opt.image = $o.data('image') || '';
    pbRender();
}
function pbPickPreset(p, i, key) {
    const preset = (categoryPresetsCache || []).find(x => x.key === key);
    const opt = pbGetOpt(p, i);
    if (!opt) return;
    opt.preset_key = key;
    if (preset) {
        opt.label = preset.label;
        opt.value_ref = { unit: 'vbucks', amount: preset.vbucks };
    }
    pbRender();
}
function pbAddOption(p) {
    pbState[p].prize.options.push(pbNewOption('catalog'));
    pbRender();
}
function pbRemoveOption(p, i) {
    const prize = pbState[p].prize;
    if (prize.mode === 'combo') {
        const k = pbComboKey(i);
        const item = k ? (prize.items || [])[k.item] : null;
        if (!item || item.mode !== 'pool') return;
        if ((item.options || []).length <= 2) { Swal.fire('Aviso', 'Un pool necesita al menos 2 opciones.', 'info'); return; }
        item.options.splice(k.opt || 0, 1);
        pbRender();
        return;
    }
    if (prize.options.length <= 2) { Swal.fire('Aviso', 'Un pool necesita al menos 2 opciones.', 'info'); return; }
    prize.options.splice(i, 1);
    pbRender();
}

function pbComboAddItem(p, mode) {
    const prize = pbState[p].prize;
    if (prize.mode !== 'combo') return;
    if (mode === 'pool') {
        if ((prize.items || []).some(it => it && it.mode === 'pool')) { Swal.fire('Aviso', 'Un combo solo puede incluir UN pool a elegir.', 'info'); return; }
        prize.items.push({ mode: 'pool', label: 'Elige tu premio', options: [pbNewOption('catalog'), pbNewOption('catalog')] });
    } else {
        prize.items.push({ mode: 'fixed', option: pbNewOption('catalog') });
    }
    pbRender();
}

function pbComboRemoveItem(p, ci) {
    const prize = pbState[p].prize;
    if (prize.mode !== 'combo') return;
    if ((prize.items || []).length <= 2) { Swal.fire('Aviso', 'Un combo necesita al menos 2 premios. Usa PREMIO FIJO o POOL si quieres uno solo.', 'info'); return; }
    prize.items.splice(ci, 1);
    pbRender();
}

function pbComboAddPoolOption(p, ci) {
    const item = (pbState[p].prize.items || [])[ci];
    if (!item || item.mode !== 'pool') return;
    item.options.push(pbNewOption('catalog'));
    pbRender();
}

function pbComboSetPoolLabel(p, ci, v) {
    const item = (pbState[p].prize.items || [])[ci];
    if (item && item.mode === 'pool') item.label = v;
}
function pbUploadImage(p, i) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.jpg,.jpeg,.png,.gif,.webp';
    input.onchange = () => {
        const file = input.files && input.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('action', 'upload_prize_image');
        fd.append('prize_image', file);
        $.ajax({
            url: API_URL, type: 'POST', data: fd, processData: false, contentType: false,
            success: (res) => {
                if (res.status === 'success') { const o = pbGetOpt(p, i); if (o) { o.image = res.url; } pbRender(); }
                else Swal.fire('Error', res.message || 'No se pudo subir la imagen.', 'error');
            },
            error: () => Swal.fire('Error', 'No se pudo subir la imagen.', 'error')
        });
    };
    input.click();
}

function toggleReqDetails() {
    if($('#chkRecent').is(':checked')) $('#divRecent').removeClass('hidden'); else $('#divRecent').addClass('hidden');
    if($('#chkWhale').is(':checked')) $('#divWhale').removeClass('hidden'); else $('#divWhale').addClass('hidden');
}

function setStartMode(mode) {
    $('#inputStartMode').val(mode);
    if(mode === 'now') {
        $('#btnModeNow').addClass('is-active');
        $('#btnModeSpecific').removeClass('is-active');
        $('#divStartDate').removeClass('is-open');
        $('#inputStart').prop('required', false);
    } else {
        $('#btnModeSpecific').addClass('is-active');
        $('#btnModeNow').removeClass('is-active');
        $('#divStartDate').addClass('is-open');
        $('#inputStart').prop('required', true);
    }
}

function initExecutionModeControls() {
    $('#inputWinnersStd').on('input change', function() {
        let v = parseInt($(this).val() || '1', 10);
        if (!Number.isFinite(v) || v < 1) v = 1;
        $(this).val(v);
        $('#inputWinners').val(String(v));
        if (prizeMode === 'advanced') { pbSyncPositions(); pbRender(); }
    });

    $('#selectWinnersFlash').on('change', function() {
        const v = parseInt($(this).val() || '2', 10);
        $('#inputWinners').val(String(v));
        if (prizeMode === 'advanced') { pbSyncPositions(); pbRender(); }
    });

    const initialMode = $('#inputExecutionMode').val() || 'standard_queue';
    setExecutionMode(initialMode);
}

function setExecutionMode(mode) {
    const nextMode = (mode === 'fast_batch') ? 'fast_batch' : 'standard_queue';
    const prevMode = $('#inputExecutionMode').val() || 'standard_queue';

    if (prevMode !== nextMode) {
        if (nextMode === 'fast_batch') {
            $('#inputBurn').data('prev', $('#inputBurn').val());
            $('#checkDouble').data('prev', $('#checkDouble').is(':checked') ? 1 : 0);
            $('#inputWinners').data('prev', $('#inputWinners').val());
        }
    }

    $('#inputExecutionMode').val(nextMode);

    if (nextMode === 'fast_batch') {
        $('#btnExecFlash').addClass('is-active');
        $('#btnExecStandard').removeClass('is-active');

        $('#divWinnersStd').addClass('hidden');
        $('#divWinnersFlash').removeClass('hidden');

        let winners = parseInt($('#inputWinners').val() || '2', 10);
        if (!Number.isFinite(winners) || winners < 2 || winners % 2 !== 0) winners = 2;
        $('#selectWinnersFlash').val(String(winners));
        $('#inputWinners').val(String(winners));

        $('#inputBurn').val('0');
        $('#divBurnCount').addClass('hidden');

        $('#checkDouble').prop('checked', false).prop('disabled', true);
        $('#divDoubleOrNothingBlock').addClass('hidden');
        $('#divAnimSelect').addClass('hidden');
    } else {
        $('#btnExecStandard').addClass('is-active');
        $('#btnExecFlash').removeClass('is-active');

        $('#divWinnersFlash').addClass('hidden');
        $('#divWinnersStd').removeClass('hidden');

        let winners = parseInt($('#inputWinners').val() || '1', 10);
        if (!Number.isFinite(winners) || winners < 1) winners = 1;
        $('#inputWinnersStd').val(String(winners));
        $('#inputWinners').val(String(winners));

        if (prevMode === 'fast_batch') {
            const prevBurn = $('#inputBurn').data('prev');
            if (prevBurn !== undefined) $('#inputBurn').val(prevBurn);
            const prevDouble = $('#checkDouble').data('prev');
            if (prevDouble !== undefined) $('#checkDouble').prop('checked', String(prevDouble) === '1');
        }

        $('#divBurnCount').removeClass('hidden');
        $('#checkDouble').prop('disabled', false);
        $('#divDoubleOrNothingBlock').removeClass('hidden');
        $('#divAnimSelect').toggleClass('hidden', !$('#checkDouble').is(':checked'));
    }
}

function initModalSorteo() {
    $('#modalSorteo').find('[data-close-button]').on('click', closeModal);
    $('#modalSorteoOverlay').on('click', closeModal);
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && isModalOpen()) closeModal();
    });
}

function isModalOpen() {
    return !$('#modalSorteo').hasClass('hidden');
}

function openModal() {
    const $modal = $('#modalSorteo');
    if (modalSorteoCloseTimer) {
        clearTimeout(modalSorteoCloseTimer);
        modalSorteoCloseTimer = null;
    }
    $modal.removeClass('hidden');
    requestAnimationFrame(() => {
        $modal.addClass('is-open');
        $('body').addClass('modal-active');
    });
}

// --- NUEVO: FUNCIÓN PARA CAMBIAR TABS ---
function switchTab(tabId) {
    $('.tab-content').removeClass('active');
    $('#' + tabId).addClass('active');
    
    $('.flash-tab-btn').removeClass('active');
    $('#btn-' + tabId).addClass('active');
}

// --- Límite de participantes según el modo de animación ---
// La Ruleta Horizontal limita el aforo a 20 (por defecto 20, editable hasta 20)
// por su diseño visual de pista. El modo Slot no tiene tope (0 = sin límite).
function onMainAnimChange() {
    const isRoulette = $('#selectMainAnim').val() === 'roulette';
    const $mp = $('#inputMaxParticipants');
    if (isRoulette) {
        $mp.attr('max', 20).attr('min', 1);
        let v = parseInt($mp.val(), 10);
        if (!Number.isFinite(v) || v <= 0 || v > 20) v = 20;
        $mp.val(v);
        $('#maxPartHelp').text('Modo Ruleta: máximo 20 participantes (por defecto 20).');
    } else {
        $mp.removeAttr('max').attr('min', 0);
        $('#maxPartHelp').text('Al llegar a X participantes, el sorteo inicia de inmediato. (0 = sin límite)');
    }
}

function clampMaxParticipants() {
    if ($('#selectMainAnim').val() !== 'roulette') return;
    const $mp = $('#inputMaxParticipants');
    const v = parseInt($mp.val(), 10);
    if (Number.isFinite(v) && v > 20) $mp.val(20);
}

// --- ACTUALIZAR: LIMPIAR MODAL AL ABRIR NUEVO ---
function openNewModal() {
    $('#formSorteo')[0].reset();
    $('#raffleId').val('0');
    $('#modalTitle').text('⚡ Nuevo sorteo');
    setStartMode('now');
    pbState = [];
    setPrizeMode('classic');
    togglePrizeInputs();
    setExecutionMode('standard_queue');
    $('#divRecent').addClass('hidden'); $('#divWhale').addClass('hidden');

    // Early-bird + Anti-fraude por defecto
    $('#ebMode').val('off'); toggleEarlyBird();
    $('#ebTiersList').empty();
    $('#afIpEnabled').prop('checked', true);
    $('#afFpEnabled').prop('checked', true);
    $('#afIpMax').val(1);

    // Sorteo privado: limpio y desactivado
    rpReset(0, false);

    // Resetear Tabs V2
    switchTab('tabGeneral');
    $('#divAnimSelect').addClass('hidden');
    $('#divPotPercent').addClass('hidden');
    onMainAnimChange();

    openModal();
}

// ===== Recompensas Early-bird (UI) =====
function toggleEarlyBird() {
    const mode = $('#ebMode').val();
    $('#ebSlots').toggleClass('hidden', mode !== 'slots');
    $('#ebTime').toggleClass('hidden', mode !== 'time');
    $('#ebTiered').toggleClass('hidden', mode !== 'tiered');
    if (mode === 'tiered' && $('#ebTiersList').children().length === 0) {
        ebAddTier(); ebAddTier(); ebAddTier();
    }
}

function ebAddTier(value) {
    const pos = $('#ebTiersList').children().length + 1;
    const v = (value === undefined || value === null) ? '' : value;
    const $row = $(
        '<div class="flex items-center gap-2">' +
            '<span class="text-xs font-bold text-amber-300 w-12">#' + pos + '</span>' +
            '<input type="number" name="eb_tiers[]" min="0" value="' + v + '" placeholder="Bananos" class="flash-input flex-1 py-1.5 px-2 text-xs">' +
            '<button type="button" class="flash-icon-btn" title="Quitar"><i class="fas fa-times"></i></button>' +
        '</div>'
    );
    $row.find('button').on('click', function() { $row.remove(); ebRenumberTiers(); });
    $('#ebTiersList').append($row);
}

function ebRenumberTiers() {
    $('#ebTiersList').children().each(function(i) {
        $(this).find('span').text('#' + (i + 1));
    });
}

function loadEarlyBirdConfig(eb) {
    $('#ebTiersList').empty();
    if (!eb || !eb.mode) { $('#ebMode').val('off'); toggleEarlyBird(); return; }
    $('#ebMode').val(eb.mode);
    if (eb.mode === 'slots') {
        $('#ebPoolSlots').val(eb.pool_total || '');
        $('#ebMaxWinners').val(eb.max_winners || '');
    } else if (eb.mode === 'time') {
        $('#ebPoolTime').val(eb.pool_total || '');
        $('#ebWindowSeconds').val(eb.window_seconds || '');
    } else if (eb.mode === 'tiered') {
        (eb.tiers || []).forEach(t => ebAddTier(t));
    }
    toggleEarlyBird();
}

function loadAntiFraudConfig(af) {
    af = af || {};
    const ipEnabled = (af.ip_limit_enabled === undefined) ? true : (Number(af.ip_limit_enabled) === 1);
    const fpEnabled = (af.fingerprint_enabled === undefined) ? true : (Number(af.fingerprint_enabled) === 1);
    $('#afIpEnabled').prop('checked', ipEnabled);
    $('#afFpEnabled').prop('checked', fpEnabled);
    $('#afIpMax').val(af.ip_limit_max || 1);
}

// --- ACTUALIZAR: CARGAR DATOS V2 AL EDITAR ---
async function editRaffle(id) {
    const res = await $.get(`${API_URL}?action=get_raffle&id=${id}`);
    if(res.status === 'success') {
        const r = res.data;
        $('#raffleId').val(r.raffle_id);
        $('#inputTitle').val(r.title);
        
        // V2: Animación Principal
        $('#selectMainAnim').val(r.main_animation);

        // V2: Economía
        $('#inputEntryCost').val(r.entry_cost);
        $('#inputMaxParticipants').val(r.max_participants);
        onMainAnimChange();
        $('#checkPot').prop('checked', r.is_pot_enabled == 1);
        if(r.is_pot_enabled == 1) $('#divPotPercent').removeClass('hidden');
        $('input[name="pot_allocation_percent"]').val(r.pot_allocation_percent);

        // V2: Gamificación
        $('#checkCashback').prop('checked', r.allow_cashback == 1);
        $('#inputCashback').val(r.cashback_percent);
        
        $('#checkDouble').prop('checked', r.allow_double_or_nothing == 1);
        if(r.allow_double_or_nothing == 1) $('#divAnimSelect').removeClass('hidden');
        $('#selectAnim').val(r.gamble_animation);
        $('#checkAnimChoice').prop('checked', r.allow_winner_animation_choice == 1);
        // Toggle de pre-elección: activado por defecto si la columna aún no existe.
        $('#checkPreChoice').prop('checked', (r.allow_pre_choice === undefined ? 1 : r.allow_pre_choice) == 1);
        $('#inputTimeout').val(r.decision_timeout);

        // Sorteo privado / exclusivo + lista de invitados
        rpReset(Number(r.raffle_id) || 0, Number(r.is_private) === 1);

        // Datos existentes (Fechas, Premios...)
        $('#inputWinners').val(r.winners_count);
        $('#inputBurn').val(r.burn_count || 0);
        setExecutionMode(r.execution_mode || 'standard_queue');
        $('#inputReplay').val(r.replay_minutes);
        $('#inputEnd').val(canonicalSqlToLocalInput(r.end_time));
        setStartMode('specific');
        $('#inputStart').val(canonicalSqlToLocalInput(r.start_time));

        // Cargar Premio
        if (r.prize_config && Number(r.prize_config.schema) === 2) {
            // Schema 2: cargar el constructor de premios por puesto
            pbState = JSON.parse(JSON.stringify(r.prize_config.positions || []));
            setPrizeMode('advanced');
            $('#modalTitle').text('✏️ Editar Sorteo');
            openModal();
            // Cargar Requisitos igual que el flujo clásico
            if(r.requirements) {
                $('#reqVerified').prop('checked', r.requirements.verified_only == 1);
                if(r.requirements.min_orders_count > 0) {
                    $('#chkRecent').prop('checked', true);
                    $('#reqMinOrders').val(r.requirements.min_orders_count);
                    $('#reqMinDate').val(r.requirements.min_orders_date);
                    $('#divRecent').removeClass('hidden');
                }
                if(r.requirements.min_spent_total > 0) {
                    $('#chkWhale').prop('checked', true);
                    $('#reqMinSpent').val(r.requirements.min_spent_total);
                    $('#divWhale').removeClass('hidden');
                }
                loadEarlyBirdConfig(r.requirements.early_bird);
                loadAntiFraudConfig(r.requirements.anti_fraud);
            }
            return;
        }
        pbState = [];
        setPrizeMode('classic');
        $(`input[name="prize_type"][value="${r.prize_config.type}"]`).prop('checked', true);
        togglePrizeInputs();
        if(r.prize_config.type === 'coins') $('#inputAmount').val(r.prize_config.amount);
        else {
            $('#hiddenItemId').val(r.prize_config.id);
            // Cargar nombre / imagen guardados (incluye productos estáticos con
            // imagen de Cloudinary) para no perderlos al re-guardar la edición.
            $('#hiddenItemName').val(r.prize_config.name || '');
            $('#hiddenItemImage').val(r.prize_config.image || '');
            if(r.prize_config.name) $('#previewName').text(r.prize_config.name);
            if(r.prize_config.image) {
                $('#previewImg').attr('src', r.prize_config.image);
                $('#itemPreview').removeClass('hidden');
            }
            // Cargar Descuento si existe
            if(r.prize_config.discount_percent && r.prize_config.discount_percent < 100) {
                $('input[name="discount_type"][value="partial"]').prop('checked', true);
                $('#discInput').removeClass('hidden');
                $('input[name="discount_percent"]').val(r.prize_config.discount_percent);
            } else {
                $('input[name="discount_type"][value="100"]').prop('checked', true);
                $('#discInput').addClass('hidden');
            }
        }

        // Cargar Requisitos (Igual que antes)
        if(r.requirements) {
            $('#reqVerified').prop('checked', r.requirements.verified_only == 1);
            if(r.requirements.min_orders_count > 0) {
                $('#chkRecent').prop('checked', true);
                $('#reqMinOrders').val(r.requirements.min_orders_count);
                $('#reqMinDate').val(r.requirements.min_orders_date);
                $('#divRecent').removeClass('hidden');
            }
            if(r.requirements.min_spent_total > 0) {
                $('#chkWhale').prop('checked', true);
                $('#reqMinSpent').val(r.requirements.min_spent_total);
                $('#divWhale').removeClass('hidden');
            }
            loadEarlyBirdConfig(r.requirements.early_bird);
            loadAntiFraudConfig(r.requirements.anti_fraud);
        }

        $('#modalTitle').text('✏️ Editar Sorteo');
        openModal();
    }
}

// ===== Zona Horaria Inteligente =====
// La base de datos guarda en UTC puro. El admin escribe en SU hora local (la
// del navegador) y aquí convertimos local <-> UTC, sin sumar/restar horas a mano.
const RAFFLE_CANON_TZ = 'UTC';

// Partes de la fecha (en una zona dada) para un instante absoluto.
function _tzParts(date, tz) {
    const fmt = new Intl.DateTimeFormat('en-US', {
        timeZone: tz, hourCycle: 'h23',
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
    const o = {};
    fmt.formatToParts(date).forEach(p => { if (p.type !== 'literal') o[p.type] = p.value; });
    return o;
}
// Hora local del navegador (datetime-local) -> 'Y-m-d H:i:s' en UTC.
function localInputToCanonicalSql(localStr) {
    if (!localStr) return '';
    const d = new Date(localStr); // datetime-local sin zona => hora local del navegador
    if (isNaN(d.getTime())) return localStr;
    const p = _tzParts(d, RAFFLE_CANON_TZ);
    return p.year + '-' + p.month + '-' + p.day + ' ' + p.hour + ':' + p.minute + ':' + p.second;
}
// 'Y-m-d H:i:s' en UTC -> valor para input datetime-local (hora local navegador).
function canonicalSqlToLocalInput(sqlStr) {
    if (!sqlStr) return '';
    const instant = new Date(sqlStr.replace(' ', 'T') + 'Z'); // valor almacenado = UTC
    if (isNaN(instant.getTime())) return sqlStr.replace(' ', 'T');
    const p = (n) => String(n).padStart(2, '0');
    return instant.getFullYear() + '-' + p(instant.getMonth() + 1) + '-' + p(instant.getDate()) +
        'T' + p(instant.getHours()) + ':' + p(instant.getMinutes());
}
// 'Y-m-d H:i:s' en UTC -> texto legible en hora local del navegador (para tablas).
function utcSqlToLocalDisplay(sqlStr) {
    if (!sqlStr) return '—';
    const d = new Date(String(sqlStr).replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return sqlStr;
    const p = (n) => String(n).padStart(2, '0');
    return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}
function initTimezoneHint() {
    try {
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'tu zona';
        $('#tzHint').html('Ingresa las horas en <strong>tu hora local</strong> (' + tz + '); se guardan automáticamente en <strong>UTC</strong>.');
    } catch (e) { /* hint estático de respaldo */ }
}
$(initTimezoneHint);

async function saveRaffle() {
    const mode = $('#inputExecutionMode').val() || 'standard_queue';
    const winners = parseInt($('#inputWinners').val() || '0', 10);
    if (mode === 'fast_batch') {
        if (!Number.isFinite(winners) || winners < 2 || winners % 2 !== 0) {
            Swal.fire('Error', 'Flash mode requires winners in pairs (2 by 2).', 'error');
            return;
        }
        $('#inputBurn').val('0');
        $('#checkDouble').prop('checked', false);
    } else {
        if (!Number.isFinite(winners) || winners < 1) {
            Swal.fire('Error', 'El número de ganadores debe ser al menos 1.', 'error');
            return;
        }
    }

    // Constructor de premios (Schema 2): serializar el estado al hidden input.
    if (prizeMode === 'advanced') {
        pbSyncPositions();
        $('#inputPrizePositions').val(JSON.stringify({ positions: pbState }));
    } else {
        $('#inputPrizePositions').val('');
    }

    if ($('#checkPrivate').is(':checked') && rpState.raffleId === 0 && rpState.pending.length === 0) {
        const goOn = await Swal.fire({
            title: 'Sorteo privado sin invitados',
            text: 'Nadie podrá inscribirse hasta que añadas invitados. ¿Guardar de todos modos?',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, guardar', cancelButtonText: 'Volver'
        });
        if (!goOn.isConfirmed) return;
    }

    let formData = new FormData($('#formSorteo')[0]);
    // Zona Horaria Inteligente: el admin ingresa su hora local; convertimos a la
    // zona del servidor (America/Lima) que es la que el motor usa para comparar.
    formData.set('start_time', localInputToCanonicalSql($('#inputStart').val()));
    formData.set('end_time', localInputToCanonicalSql($('#inputEnd').val()));
    // Invitados añadidos antes de que exista el sorteo (alta nueva).
    formData.set('private_invites_json', JSON.stringify(rpState.pending.map(u => u.user_id)));
    $.ajax({
        url: API_URL, type: 'POST', data: formData, processData: false, contentType: false,
        success: function(res) {
            if(res.status === 'success') {
                closeModal(); Swal.fire('Éxito', res.message, 'success'); cargarSorteos();
            } else Swal.fire('Error', res.message, 'error');
        }
    });
}

function deleteRaffle(id) {
    Swal.fire({ title: '¿Eliminar sorteo?', showCancelButton: true, confirmButtonText: 'Sí', cancelButtonText: 'Cancelar' }).then((r) => {
        if(r.isConfirmed) $.post(API_URL, {action: 'delete_raffle', id: id}, () => { cargarSorteos(); });
    });
}

function closeModal() {
    const $modal = $('#modalSorteo');
    $modal.removeClass('is-open');
    $('body').removeClass('modal-active');
    if (modalSorteoCloseTimer) clearTimeout(modalSorteoCloseTimer);
    modalSorteoCloseTimer = setTimeout(() => {
        $modal.addClass('hidden');
    }, 220);
}

function initRequirementsToggles() {
    $('#chkRecent, #chkWhale').on('change', toggleReqDetails);
    toggleReqDetails();
    rpInit();
}

// ===== Sorteo privado / exclusivo: lista de invitados =====
// Con raffleId > 0 (edición) cada alta/baja pega directo a la API. Con
// raffleId === 0 (sorteo nuevo) los invitados se apilan en `pending` y viajan
// en `private_invites_json` junto con el formulario al guardar.
const rpState = { raffleId: 0, pending: [], invited: [], results: [], searchTimer: null, lastQuery: '' };

function rpInit() {
    $('#checkPrivate').on('change', function () {
        $('#rpPanel').toggleClass('hidden', !this.checked);
        if (this.checked && rpState.raffleId > 0) rpLoadInvited();
    });
    $('#rpSearch').on('input', function () {
        const q = this.value.trim();
        clearTimeout(rpState.searchTimer);
        if (q.length < 2) { $('#rpResults').empty(); rpState.lastQuery = ''; return; }
        rpState.searchTimer = setTimeout(() => rpSearch(q), 280);
    });
}

function rpReset(raffleId, isPrivate) {
    rpState.raffleId = Number(raffleId) || 0;
    rpState.pending = [];
    rpState.invited = [];
    rpState.results = [];
    rpState.lastQuery = '';
    $('#rpSearch').val('');
    $('#rpResults').empty();
    $('#checkPrivate').prop('checked', !!isPrivate);
    $('#rpPanel').toggleClass('hidden', !isPrivate);
    $('#rpPendingHint').toggleClass('hidden', rpState.raffleId > 0);
    rpRenderInvited();
    if (isPrivate && rpState.raffleId > 0) rpLoadInvited();
}

function rpIsInvited(uid) {
    const list = rpState.raffleId > 0 ? rpState.invited : rpState.pending;
    return list.some(u => Number(u.user_id) === Number(uid));
}

function rpAvatar(file) {
    return CC_AVATAR_BASE + (file || 'default.png');
}

function rpUserSub(u) {
    const parts = [];
    if (u.game_username) parts.push('@' + u.game_username);
    if (u.public_code) parts.push('#' + u.public_code);
    if (u.phone) parts.push(u.phone);
    return parts.join(' · ');
}

function rpLoadInvited() {
    if (rpState.raffleId <= 0) return;
    $.getJSON(`${API_URL}?action=rp_invite_list&raffle_id=${rpState.raffleId}`).done(res => {
        if (!res || res.status !== 'success') return;
        rpState.invited = Array.isArray(res.invited) ? res.invited : [];
        rpRenderInvited();
        if (rpState.lastQuery) rpSearch(rpState.lastQuery);
    });
}

function rpRenderInvited() {
    const list = rpState.raffleId > 0 ? rpState.invited : rpState.pending;
    $('#rpCount').text(list.length);
    const $box = $('#rpInvited');
    if (!list.length) { $box.html('<div class="rp-empty">Aún no hay invitados. Busca usuarios arriba para añadirlos.</div>'); return; }
    $box.html(list.map(u => `
        <div class="rp-row">
            <img src="${rfEsc(rpAvatar(u.avatar))}" alt="">
            <div class="rp-row__main">
                <div class="rp-row__name">${rfEsc(u.name)}${u.is_verified ? ' <i class="fas fa-circle-check" style="color:#22d3ee;" title="Verificado"></i>' : ''}${u.is_participant ? ' <span class="rp-chip rp-chip--part">INSCRITO</span>' : ''}${rpState.raffleId === 0 ? ' <span class="rp-chip rp-chip--pending">PENDIENTE</span>' : ''}</div>
                <div class="rp-row__sub">${rfEsc(rpUserSub(u))}</div>
            </div>
            <button type="button" class="rp-btn rp-btn--remove" onclick="rpRemove(${Number(u.user_id)})" title="Quitar invitación"><i class="fas fa-user-minus"></i></button>
        </div>`).join(''));
}

function rpSearch(q) {
    rpState.lastQuery = q;
    $.getJSON(`${API_URL}?action=cc_user_search&raffle_id=${rpState.raffleId}&q=${encodeURIComponent(q)}`).done(res => {
        if (!res || res.status !== 'success' || rpState.lastQuery !== q) return;
        const users = res.users || [];
        rpState.results = users;
        const $out = $('#rpResults');
        if (!users.length) { $out.html('<div class="rp-empty">Sin resultados para “' + rfEsc(q) + '”.</div>'); return; }
        $out.html(users.map(u => {
            const invited = rpIsInvited(u.user_id);
            const btn = invited
                ? `<button type="button" class="rp-btn" disabled><i class="fas fa-check"></i> Invitado</button>`
                : `<button type="button" class="rp-btn rp-btn--add" onclick="rpAdd(${Number(u.user_id)})"><i class="fas fa-user-plus"></i> Invitar</button>`;
            return `<div class="rp-row">
                <img src="${rfEsc(rpAvatar(u.avatar))}" alt="">
                <div class="rp-row__main">
                    <div class="rp-row__name">${rfEsc(u.name)}${u.is_verified ? ' <i class="fas fa-circle-check" style="color:#22d3ee;" title="Verificado"></i>' : ''}${u.account_status === 'banned' ? ' <span class="rp-chip" style="background:rgba(239,68,68,.15);color:#ef4444;">BANEADO</span>' : ''}</div>
                    <div class="rp-row__sub">${rfEsc(rpUserSub(u))}</div>
                </div>
                ${btn}
            </div>`;
        }).join(''));
    });
}

function rpAdd(uid) {
    const u = rpState.results.find(x => Number(x.user_id) === Number(uid));
    if (!u || rpIsInvited(uid)) return;
    if (rpState.raffleId === 0) {
        rpState.pending.push({
            user_id: Number(u.user_id), name: u.name, game_username: u.game_username, public_code: u.public_code,
            phone: u.phone, avatar: u.avatar, is_verified: u.is_verified, is_participant: u.is_participant,
        });
        rpRenderInvited();
        if (rpState.lastQuery) rpSearch(rpState.lastQuery);
        return;
    }
    $.post(API_URL, { action: 'rp_invite_add', raffle_id: rpState.raffleId, user_id: u.user_id }, res => {
        if (res && res.status === 'success') rpLoadInvited();
        else Swal.fire('Error', (res && res.message) || 'No se pudo invitar.', 'error');
    }, 'json').fail(() => Swal.fire('Error', 'No se pudo invitar.', 'error'));
}

function rpRemove(uid) {
    if (rpState.raffleId === 0) {
        rpState.pending = rpState.pending.filter(u => Number(u.user_id) !== Number(uid));
        rpRenderInvited();
        if (rpState.lastQuery) rpSearch(rpState.lastQuery);
        return;
    }
    $.post(API_URL, { action: 'rp_invite_remove', raffle_id: rpState.raffleId, user_id: uid }, res => {
        if (res && res.status === 'success') rpLoadInvited();
        else Swal.fire('Error', (res && res.message) || 'No se pudo quitar.', 'error');
    }, 'json').fail(() => Swal.fire('Error', 'No se pudo quitar.', 'error'));
}

function renderPrivateBadge(r) {
    if (Number(r.is_private) !== 1) return '';
    const n = Number(r.invited_count || 0);
    return `<span class="rp-badge" title="Sorteo privado: solo invitados (${n})"><i class="fas fa-lock"></i> Privado · ${n}</span>`;
}

// ===== Rediseño: tarjetas (activos arriba, historial abajo) =====
let rafflesData = [];
const ACTIVE_STATES = ['scheduled', 'open', 'waiting_for_winner', 'locking', 'animating'];
function isActiveStatus(s) { return ACTIVE_STATES.includes(String(s || '').toLowerCase()); }
function rfEsc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

function cargarSorteos() {
    $('#rafflesLoading').removeClass('hidden');
    $.getJSON(`${API_URL}?action=list`)
        .done(res => {
            rafflesData = (res && res.status === 'success' && Array.isArray(res.data)) ? res.data : [];
            renderRaffleSections();
            startLiveMetricsPolling();
        })
        .fail(() => {
            $('#rafflesLoading').addClass('hidden');
            Swal.fire('Error', 'No se pudieron cargar los sorteos.', 'error');
        });
}

function renderRaffleSections() {
    $('#rafflesLoading').addClass('hidden');
    const active = rafflesData.filter(r => isActiveStatus(r.status));
    let past = rafflesData.filter(r => !isActiveStatus(r.status));

    const q = (document.getElementById('pastSearch')?.value || '').trim().toLowerCase();
    if (q) {
        past = past.filter(r =>
            String(r.raffle_id).includes(q) ||
            String(r.title || '').toLowerCase().includes(q) ||
            String(r.prize_summary || '').toLowerCase().includes(q) ||
            String(r.winner_summary || '').toLowerCase().includes(q)
        );
    }

    $('#activeCount').text(active.length);
    $('#pastCount').text(past.length);

    const $active = $('#rafflesActiveContainer').empty();
    const $past = $('#rafflesPastContainer').empty();
    $('#rafflesActiveEmpty').toggleClass('hidden', active.length > 0);
    $('#rafflesPastEmpty').toggleClass('hidden', past.length > 0);

    active.forEach(r => $active.append(buildActiveCard(r)));
    past.forEach(r => $past.append(buildPastCard(r)));

    refreshLiveMetrics();
}

function buildActiveCard(r) {
    const s = String(r.status || '').toLowerCase();
    const liveCls = (s === 'open') ? ' rf-card--live' : '';
    const canForce = ['scheduled', 'open'].includes(s);
    const isOpen = (s === 'open');
    const ebBtn = (Number(r.eb_time_mode) === 1 && isOpen)
        ? `<button type="button" onclick="startEbWindow(${r.raffle_id})" class="rf-btn rf-btn--ghost" title="${Number(r.eb_window_started) === 1 ? 'Reiniciar ventana Early-bird' : 'Iniciar ventana Early-bird (Modo B)'}"><i class="fas fa-stopwatch"></i> Early-bird</button>`
        : '';
    const forceBtn = canForce
        ? `<button type="button" onclick="forceStartRaffle(${r.raffle_id})" class="rf-btn rf-btn--ghost" title="Forzar inicio (arrancar ya)"><i class="fas fa-bolt"></i> Forzar</button>`
        : '';
    return `
    <div class="rf-card rf-card--active${liveCls}">
        <div class="rf-head">
            <div style="min-width:0;">
                <div class="rf-title">${rfEsc(r.title)}</div>
                <div class="rf-id">#${r.raffle_id} ${renderPrivateBadge(r)}</div>
            </div>
            ${renderStatusBadge(r.status)}
        </div>
        <div class="rf-meta-grid">
            <div class="rf-meta"><div class="rf-meta__label">Premio</div><div class="rf-meta__val">${rfEsc(r.prize_summary)}</div></div>
            <div class="rf-meta"><div class="rf-meta__label">Participantes</div><div class="rf-meta__val">${Number(r.participants_count || 0)}</div></div>
            <div class="rf-meta"><div class="rf-meta__label">En vivo</div><div class="rf-meta__val">${renderLiveMetricsCell(r)}</div></div>
            <div class="rf-meta"><div class="rf-meta__label">Inicia</div><div class="rf-meta__val">${utcSqlToLocalDisplay(r.start_time)}</div></div>
            <div class="rf-meta"><div class="rf-meta__label">Cierra</div><div class="rf-meta__val">${utcSqlToLocalDisplay(r.end_time)}</div></div>
        </div>
        <div class="rf-actions">
            <a href="sorteos_flash/sala_mando.php?id=${r.raffle_id}" class="rf-btn rf-btn--primary" title="Sala de Mando (en vivo)"><i class="fas fa-satellite-dish"></i> Sala de Mando</a>
            ${ebBtn}
            ${forceBtn}
            <button type="button" onclick="copyRaffleLink(${r.raffle_id})" class="rf-btn rf-btn--ghost"><i class="fas fa-link"></i> Enlace</button>
            <button type="button" onclick="editRaffle(${r.raffle_id})" class="rf-btn rf-btn--ghost"><i class="fas fa-edit"></i> Editar</button>
            <button type="button" onclick="openMetricsModal(${r.raffle_id})" class="rf-btn rf-btn--ghost"><i class="fas fa-chart-simple"></i> Detalles</button>
            <button type="button" onclick="deleteRaffle(${r.raffle_id})" class="rf-btn rf-btn--danger rf-icononly" title="Eliminar"><i class="fas fa-trash"></i></button>
        </div>
    </div>`;
}

function buildPastCard(r) {
    const total = Number(r.winners_total || 0);
    const delivered = Number(r.delivered_total || 0);
    let chip;
    if (total === 0) chip = `<span class="rf-deliv-chip rf-deliv-chip--none"><i class="fas fa-minus"></i> Sin ganadores</span>`;
    else if (delivered >= total) chip = `<span class="rf-deliv-chip rf-deliv-chip--done"><i class="fas fa-check"></i> Entregado ${delivered}/${total}</span>`;
    else if (delivered > 0) chip = `<span class="rf-deliv-chip rf-deliv-chip--part"><i class="fas fa-truck-fast"></i> ${delivered}/${total} entregados</span>`;
    else chip = `<span class="rf-deliv-chip rf-deliv-chip--none"><i class="fas fa-hourglass-half"></i> 0/${total} entregados</span>`;
    const subirBtn = total > 0
        ? `<button type="button" onclick="openEntregaModal(${r.raffle_id})" class="rf-btn rf-btn--primary"><i class="fas fa-gift"></i> Estado de premios</button>`
        : '';
    return `
    <div class="rf-card">
        <div class="rf-head">
            <div style="min-width:0;">
                <div class="rf-title" style="font-size:1rem;">${rfEsc(r.title)}</div>
                <div class="rf-id">#${r.raffle_id} ${renderPrivateBadge(r)}</div>
            </div>
            ${renderStatusBadge(r.status)}
        </div>
        <div class="rf-meta-grid">
            <div class="rf-meta"><div class="rf-meta__label">Premio</div><div class="rf-meta__val">${rfEsc(r.prize_summary)}</div></div>
            <div class="rf-meta"><div class="rf-meta__label">Ganador</div><div class="rf-meta__val">${rfEsc(r.winner_summary)}</div></div>
            <div class="rf-meta"><div class="rf-meta__label">Participantes</div><div class="rf-meta__val">${Number(r.participants_count || 0)}</div></div>
            <div class="rf-meta"><div class="rf-meta__label">Finalizó</div><div class="rf-meta__val">${utcSqlToLocalDisplay(r.end_time)}</div></div>
        </div>
        <div>${chip}</div>
        <div class="rf-actions">
            ${subirBtn}
            <button type="button" onclick="openMetricsModal(${r.raffle_id})" class="rf-btn rf-btn--ghost"><i class="fas fa-chart-simple"></i> Detalles</button>
            <button type="button" onclick="copyRaffleLink(${r.raffle_id})" class="rf-btn rf-btn--ghost"><i class="fas fa-link"></i> Enlace</button>
            <button type="button" onclick="deleteRaffle(${r.raffle_id})" class="rf-btn rf-btn--danger rf-icononly" title="Eliminar"><i class="fas fa-trash"></i></button>
        </div>
    </div>`;
}

// ===== MODAL: Subir premios (Prueba de Entrega) =====
let entCurrentRaffleId = null;
let entWinnersCache = [];
let entUploadWinnerId = null;

function openEntregaModal(raffleId) {
    entCurrentRaffleId = raffleId;
    const r = rafflesData.find(x => Number(x.raffle_id) === Number(raffleId));
    $('#entRaffleSub').text(r ? `#${r.raffle_id} · ${r.title}` : `#${raffleId}`);
    $('#entBody').html('<div class="text-center flash-text-muted py-8"><i class="fas fa-circle-notch fa-spin text-xl"></i></div>');
    $('#modalEntrega').addClass('is-open');
    $('body').addClass('modal-active');
    loadWinnersDelivery();
}

function closeEntregaModal() {
    $('#modalEntrega').removeClass('is-open');
    $('body').removeClass('modal-active');
    entCurrentRaffleId = null;
}

function loadWinnersDelivery() {
    if (!entCurrentRaffleId) return;
    $.getJSON(`${API_URL}?action=cc_winners&id=${entCurrentRaffleId}`)
        .done(res => {
            if (!res || res.status !== 'success') { $('#entBody').html(`<p class="flash-text-muted text-center py-6">${rfEsc((res && res.message) || 'Error')}</p>`); return; }
            entWinnersCache = res.winners || [];
            renderWinnersDelivery();
        })
        .fail(() => $('#entBody').html('<p class="flash-text-muted text-center py-6">No se pudieron cargar los ganadores.</p>'));
}

function renderWinnersDelivery() {
    if (!entWinnersCache.length) { $('#entBody').html('<p class="flash-text-muted text-center py-6">Este sorteo no tiene ganadores registrados.</p>'); return; }
    const formatCoins = value => {
        const amount = Number(value);
        return (Number.isFinite(amount) ? amount : 0).toLocaleString('es-PE', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    };
    const coinIcon = size => `<img class="ew-coin-icon" src="/assets/img/moneda.webp" width="${size}" height="${size}" alt="Bananos">`;
    const html = entWinnersCache.map(w => {
        const fulfillment = (w.fulfillment && typeof w.fulfillment === 'object') ? w.fulfillment : {};
        const choice = String(w.choice || '');
        const manualDelivery = fulfillment.manual_delivery_required === true;
        const credited = Number(fulfillment.automatic_credit_coins ?? w.automatic_credit_coins ?? 0) || 0;
        const delivered = manualDelivery && w.delivery_status === 'delivered';
        const urls = manualDelivery && Array.isArray(w.proof_urls) ? w.proof_urls : (manualDelivery && w.proof_url ? [w.proof_url] : []);
        const names = manualDelivery && Array.isArray(w.proof_images) ? w.proof_images : (manualDelivery && w.proof_image ? [w.proof_image] : []);
        const maxProofs = w.max_proofs || 4;
        const thumbs = urls.map((u, i) => {
            const nm = names[i] || '';
            return `<span class="ew-thumb-wrap">
                <img src="${rfEsc(u)}" class="ew-thumb" onclick="openLightbox('${rfEsc(u)}')" title="Ver prueba">
                <button class="ew-thumb-del" onclick="deleteDeliveryProof(${w.winner_id}, '${rfEsc(nm)}')" title="Eliminar esta imagen">&times;</button>
            </span>`;
        }).join('');
        const canAdd = manualDelivery && urls.length < maxProofs;
        const addBtn = canAdd
            ? `<button class="ew-upload-btn" onclick="triggerProofUpload(${w.winner_id})"><i class="fas fa-${urls.length ? 'plus' : 'upload'}"></i> ${urls.length ? 'Añadir' : 'Subir prueba'}</button>`
            : '';
        const noteBtn = manualDelivery
            ? `<button class="ew-upload-btn" onclick="editDeliveryNote(${w.winner_id})" title="${w.delivery_note ? 'Editar nota pública' : 'Añadir nota pública'}"><i class="fas fa-comment-dots"></i> Nota</button>`
            : '';

        let outcomeInfo = '';
        if (choice === 'cashback') {
            outcomeInfo = `<div class="ew-outcome ew-outcome--automatic"><span>✅</span>${coinIcon(22)}<strong>${formatCoins(credited)} Bananos acreditados automáticamente</strong></div>`;
        } else if (choice === 'double_lost') {
            outcomeInfo = '<div class="ew-outcome ew-outcome--lost"><span>💀</span><strong>Doble o Nada perdido · Sin premio por entregar</strong></div>';
        } else if (choice === 'double_won') {
            outcomeInfo = `<div class="ew-outcome ew-outcome--mixed"><span>🏆</span>${coinIcon(22)}<strong>Premio original + ${formatCoins(credited)} Bananos acreditados</strong></div>`;
        }

        // Schema 2: elección de pool / categoría del ganador.
        let poolInfo = '';
        let poolBtn = '';
        const fp = w.final_prize || null;
        if (manualDelivery && w.prize_state === 'awaiting_selection') {
            poolInfo = `<div class="ew-sub" style="color:#f59e0b;"><i class="fas fa-hourglass-half"></i> Esperando que elija su premio de la vitrina</div>`;
        } else if (manualDelivery && w.prize_state === 'selected' && fp) {
            poolInfo = `<div class="ew-sub" style="color:#22c55e;"><i class="fas fa-check"></i> Eligió: ${rfEsc(fp.name || fp.label || 'Premio')}</div>`;
        } else if (manualDelivery && w.prize_state === 'admin_pending') {
            poolInfo = `<div class="ew-sub" style="color:#f59e0b;"><i class="fas fa-folder-open"></i> Eligió categoría: ${rfEsc((fp && (fp.label || fp.name)) || 'Categoría')} — falta registrar el ítem exacto</div>`;
            poolBtn = `<button class="ew-upload-btn" onclick="registerFinalItem(${w.winner_id})" title="Registrar el ítem exacto que eligió el ganador"><i class="fas fa-clipboard-check"></i> Registrar ítem</button>`;
        } else if (manualDelivery && w.prize_state === 'admin_resolved') {
            const ri = (fp && fp.resolved_item) ? fp.resolved_item : null;
            poolInfo = `<div class="ew-sub" style="color:#22c55e;"><i class="fas fa-check-double"></i> Ítem registrado: ${rfEsc((ri && ri.name) || 'Ítem')}</div>`;
            if (!delivered) poolBtn = `<button class="ew-upload-btn" onclick="registerFinalItem(${w.winner_id})" title="Corregir el ítem registrado"><i class="fas fa-pen"></i> Corregir ítem</button>`;
        }
        const proof = thumbs + addBtn + noteBtn + poolBtn;
        const note = manualDelivery && w.delivery_note ? `<div class="ew-sub" style="font-style:italic;">“${rfEsc(w.delivery_note)}”</div>` : '';
        const deliveryControls = manualDelivery ? `<div class="ew-side">
                <div class="ew-status-toggle">
                    <button class="${!delivered ? 'is-pending' : ''}" onclick="setDeliveryStatus(${w.winner_id}, 'pending')">Pendiente</button>
                    <button class="${delivered ? 'is-delivered' : ''}" onclick="setDeliveryStatus(${w.winner_id}, 'delivered')">Entregado</button>
                </div>
                <div class="ew-proof-actions">${proof}</div>
            </div>` : '';
        return `
        <div class="ew-row ew-row--${rfEsc(choice || 'pending')}">
            <img class="ew-avatar" src="${CC_AVATAR_BASE}${rfEsc(w.avatar)}" alt="">
            <div class="ew-main">
                <div class="ew-name">#${w.position} ${rfEsc(w.name)} ${w.is_verified ? '<i class="fas fa-circle-check" style="color:#22c55e;font-size:.8rem;" title="Verificado"></i>' : ''}</div>
                <div class="ew-sub">${rfEsc(w.game_username || '')}${w.delivered_at ? ' · entregado ' + rfEsc(utcSqlToLocalDisplay(w.delivered_at)) : ''}</div>
                ${outcomeInfo}
                ${poolInfo}
                ${note}
            </div>
            ${deliveryControls}
        </div>`;
    }).join('');
    $('#entBody').html(html);
}

function setDeliveryStatus(winnerId, status) {
    $.post(API_URL, { action: 'cc_set_delivery_status', winner_id: winnerId, delivery_status: status }, res => {
        if (res && res.status === 'success') { ccToast(res.message || 'Actualizado'); loadWinnersDelivery(); cargarSorteos(); }
        else Swal.fire('Error', (res && res.message) || 'No se pudo actualizar.', 'error');
    }, 'json').fail(() => Swal.fire('Error', 'No se pudo actualizar el estado.', 'error'));
}

// Nota pública independiente de la imagen: se puede escribir aunque no haya
// prueba subida (p. ej. "el ganador no responde"). Vacío = borrar la nota.
function editDeliveryNote(winnerId) {
    const w = entWinnersCache.find(x => Number(x.winner_id) === Number(winnerId));
    Swal.fire({
        title: 'Nota pública del premio',
        text: 'Visible en el estado del premio del Salón de Trofeos. Deja vacío para borrarla.',
        input: 'textarea',
        inputValue: (w && w.delivery_note) ? w.delivery_note : '',
        inputPlaceholder: 'Ej: El ganador aún no responde a nuestros mensajes',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'cc_set_delivery_note', winner_id: winnerId, note: (r.value || '').trim() }, res => {
            if (res && res.status === 'success') { ccToast(res.message || 'Nota guardada'); loadWinnersDelivery(); }
            else Swal.fire('Error', (res && res.message) || 'No se pudo guardar la nota.', 'error');
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo guardar la nota.', 'error'));
    });
}

// Schema 2: registra el ítem exacto que el ganador eligió (categoría) vía chat.
function registerFinalItem(winnerId) {
    const w = entWinnersCache.find(x => Number(x.winner_id) === Number(winnerId));
    const ri = (w && w.final_prize && w.final_prize.resolved_item) ? w.final_prize.resolved_item : null;
    Swal.fire({
        title: 'Ítem elegido por el ganador',
        html: `<input id="swalItemName" class="swal2-input" placeholder="Nombre del ítem (ej: Skin Midas)" value="${ri ? rfEsc(ri.name || '') : ''}">
               <input id="swalItemId" class="swal2-input" placeholder="ID del catálogo (opcional)" value="${ri ? rfEsc(ri.id || '') : ''}">
               <input id="swalItemImage" class="swal2-input" placeholder="URL de imagen (opcional)" value="${ri ? rfEsc(ri.image || '') : ''}">`,
        showCancelButton: true,
        confirmButtonText: 'Registrar',
        cancelButtonText: 'Cancelar',
        preConfirm: () => {
            const name = ($('#swalItemName').val() || '').trim();
            if (!name) { Swal.showValidationMessage('El nombre del ítem es obligatorio'); return false; }
            return { name, id: ($('#swalItemId').val() || '').trim(), image: ($('#swalItemImage').val() || '').trim() };
        }
    }).then(r => {
        if (!r.isConfirmed || !r.value) return;
        $.post(API_URL, { action: 'set_winner_final_item', winner_id: winnerId, item_name: r.value.name, item_id: r.value.id, item_image: r.value.image }, res => {
            if (res && res.status === 'success') { ccToast(res.message || 'Ítem registrado'); loadWinnersDelivery(); }
            else Swal.fire('Error', (res && res.message) || 'No se pudo registrar.', 'error');
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo registrar el ítem.', 'error'));
    });
}

function triggerProofUpload(winnerId) {
    entUploadWinnerId = winnerId;
    const input = document.getElementById('entProofFileInput');
    input.value = '';
    input.click();
}

function handleProofFileSelected(e) {
    const files = e.target.files ? Array.from(e.target.files) : [];
    if (!files.length || !entUploadWinnerId) return;
    Swal.fire({
        title: files.length > 1 ? `Nota opcional (${files.length} imágenes)` : 'Nota opcional (visible al público)',
        input: 'text',
        inputPlaceholder: 'Ej: Entregado por WhatsApp',
        showCancelButton: true,
        confirmButtonText: files.length > 1 ? 'Subir pruebas' : 'Subir prueba',
        cancelButtonText: 'Cancelar'
    }).then(r => {
        if (!r.isConfirmed) return;
        uploadDeliveryProof(entUploadWinnerId, files, r.value || '');
    });
}

function uploadDeliveryProof(winnerId, files, note) {
    const list = Array.isArray(files) ? files : [files];
    const fd = new FormData();
    fd.append('action', 'cc_upload_delivery_proof');
    fd.append('winner_id', winnerId);
    fd.append('note', note || '');
    list.forEach(f => fd.append('file[]', f));
    Swal.fire({ title: list.length > 1 ? 'Subiendo pruebas…' : 'Subiendo prueba…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.ajax({ url: API_URL, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(res => {
            if (res && res.status === 'success') { Swal.fire('Listo', res.message || 'Prueba subida.', 'success'); loadWinnersDelivery(); cargarSorteos(); }
            else Swal.fire('Error', (res && res.message) || 'No se pudo subir la imagen.', 'error');
        })
        .fail(xhr => {
            let msg = 'No se pudo subir la imagen.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (_) {}
            Swal.fire('Error', msg, 'error');
        });
}

// image = filename para borrar solo esa; vacío = borrar todas.
function deleteDeliveryProof(winnerId, image) {
    const single = !!image;
    Swal.fire({
        title: single ? '¿Eliminar esta imagen?' : '¿Eliminar todas las pruebas?',
        text: single ? 'Se borrará solo esta imagen (el estado de entrega se conserva).' : 'Se borrarán todas las imágenes subidas (el estado de entrega se conserva).',
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#ef4444'
    }).then(r => {
        if (!r.isConfirmed) return;
        const payload = { action: 'cc_delete_delivery_proof', winner_id: winnerId };
        if (single) payload.image = image;
        $.post(API_URL, payload, res => {
            if (res && res.status === 'success') { ccToast(res.message || 'Eliminada'); loadWinnersDelivery(); cargarSorteos(); }
            else Swal.fire('Error', (res && res.message) || 'No se pudo eliminar.', 'error');
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo eliminar la prueba.', 'error'));
    });
}

function openLightbox(url) {
    document.getElementById('entLightboxImg').src = url;
    document.getElementById('entLightbox').classList.add('is-open');
}

// ===== MODAL: Detalles / Métricas =====
function openMetricsModal(raffleId) {
    const r = rafflesData.find(x => Number(x.raffle_id) === Number(raffleId));
    $('#mtRaffleSub').text(r ? `#${r.raffle_id} · ${r.title}` : `#${raffleId}`);
    $('#mtBody').html('<div class="text-center flash-text-muted py-8"><i class="fas fa-circle-notch fa-spin text-xl"></i></div>');
    $('#modalMetricas').addClass('is-open');
    $('body').addClass('modal-active');
    $.getJSON(`${API_URL}?action=cc_raffle_metrics&id=${raffleId}`)
        .done(res => {
            if (!res || res.status !== 'success') { $('#mtBody').html(`<p class="flash-text-muted text-center py-6">${rfEsc((res && res.message) || 'Error')}</p>`); return; }
            renderMetrics(res.metrics);
        })
        .fail(() => $('#mtBody').html('<p class="flash-text-muted text-center py-6">No se pudieron cargar las métricas.</p>'));
}

function closeMetricsModal() {
    $('#modalMetricas').removeClass('is-open');
    $('body').removeClass('modal-active');
}

function fmtDuration(sec) {
    if (sec == null) return '—';
    sec = Number(sec);
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
}

function renderMetrics(m) {
    const cell = (label, val, full, mono) => `<div class="mt-cell${full ? ' mt-cell--full' : ''}"><div class="mt-label">${label}</div><div class="mt-val${mono ? ' mt-val--mono' : ''}">${val}</div></div>`;
    const yn = v => Number(v) ? 'Sí' : 'No';
    let html = '<div class="mt-grid">';
    html += cell('Estado', rfEsc(m.state));
    html += cell('Modo de ejecución', rfEsc(m.execution_mode || '—'));
    html += cell('Inicio', utcSqlToLocalDisplay(m.start_time));
    html += cell('Cierre', utcSqlToLocalDisplay(m.end_time));
    html += cell('Duración', fmtDuration(m.duration_seconds));
    html += cell('Participantes', `${m.participants} <span class="flash-text-muted" style="font-size:.7rem;">(${m.participants_all} c/expulsados)</span>`);
    html += cell('Ganadores', `${m.winners_real} <span class="flash-text-muted" style="font-size:.7rem;">/ ${m.winners_config} config</span>`);
    html += cell('Entregados', `${m.delivered_count} / ${m.winners_real}`);
    html += cell('Quemados (Al agua)', m.burn_count);
    html += cell('Premio', rfEsc(m.prize_summary));
    html += cell('Costo de entrada', m.entry_cost);
    html += cell('Bote final', m.current_pot);
    html += cell('Cashback', Number(m.allow_cashback) ? `${m.cashback_percent}%` : 'No');
    html += cell('Doble o nada', yn(m.allow_double_or_nothing));
    html += cell('Cupo máximo', Number(m.max_participants) > 0 ? m.max_participants : 'Sin límite');
    if (m.server_seed_hash) html += cell('Server seed hash', rfEsc(m.server_seed_hash), true, true);
    if (m.bsc_block_hash) html += cell('BSC block hash', rfEsc(m.bsc_block_hash), true, true);
    if (m.bsc_block_target) html += cell('BSC block target', rfEsc(m.bsc_block_target), true, true);
    html += '</div>';
    $('#mtBody').html(html);
}

// Cierre con tecla Escape para los nuevos modales.
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if ($('#entLightbox').hasClass('is-open')) { $('#entLightbox').removeClass('is-open'); return; }
    if ($('#modalEntrega').hasClass('is-open')) { closeEntregaModal(); return; }
    if ($('#modalMetricas').hasClass('is-open')) { closeMetricsModal(); return; }
});

// Listener del input de archivo de prueba (el elemento ya existe en el DOM).
(function () {
    const input = document.getElementById('entProofFileInput');
    if (input) input.addEventListener('change', handleProofFileSelected);
})();

// ===== Métricas en vivo (Rating en vivo + Espectadores Únicos) =====
function renderLiveMetricsCell(r) {
    const s = String(r.status || '').toLowerCase();
    if (s !== 'open') return '<span class="text-xs" style="color: var(--flash-text-muted);">—</span>';
    return `<span class="lm-cell inline-flex items-center gap-3 text-xs" data-raffle="${r.raffle_id}">
        <span title="Rating en vivo: usuarios activos en los últimos 30s"><i class="fas fa-circle text-green-500 mr-1" style="font-size:7px;vertical-align:middle;"></i><span class="lm-live font-bold">…</span></span>
        <span title="Espectadores únicos: cuentas distintas que han mirado"><i class="fas fa-eye mr-1"></i><span class="lm-unique font-bold">…</span></span>
    </span>`;
}

let liveMetricsTimer = null;
function startLiveMetricsPolling() {
    if (liveMetricsTimer) clearInterval(liveMetricsTimer);
    liveMetricsTimer = setInterval(refreshLiveMetrics, 5000);
}
function refreshLiveMetrics() {
    document.querySelectorAll('.lm-cell').forEach(cell => {
        const id = cell.getAttribute('data-raffle');
        if (!id) return;
        $.getJSON(`${API_URL}?action=live_metrics&id=${id}`)
            .done(res => {
                if (!res || res.status !== 'success') return;
                const live = cell.querySelector('.lm-live');
                const uniq = cell.querySelector('.lm-unique');
                if (live) live.textContent = res.live;
                if (uniq) uniq.textContent = res.unique;
            });
    });
}

function forceStartRaffle(id) {
    Swal.fire({
        title: '¿Forzar inicio?',
        html: 'Se cerrará la <strong>sala de espera ahora</strong> (end_time = ahora).<br>El sorteo seguirá su <strong>curso natural</strong>: el motor espera el bloque de la BSC y respeta la elegibilidad, igual que siempre.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, adelantar reloj',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f59e0b'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'force_start', id: id }, res => {
            if (res && res.status === 'success') {
                Swal.fire('Listo', res.message || 'Inicio forzado.', 'success');
                cargarSorteos();
            } else {
                Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo forzar el inicio.', 'error');
            }
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo forzar el inicio.', 'error'));
    });
}

// Inicia manualmente la ventana Early-bird (Modo B). Fija el ancla del cronómetro
// AHORA, para anunciar el sorteo justo cuando arranca el conteo.
function startEbWindow(id) {
    Swal.fire({
        title: '¿Iniciar ventana Early-bird?',
        html: 'El cronómetro del pozo por tiempo (Modo B) empezará a correr <strong>desde este instante</strong>.<br>Anúncialo a tu comunidad ahora para que el minuto sea exacto.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, iniciar ahora',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#22c55e'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'start_eb_window', id: id }, res => {
            if (res && res.status === 'success') {
                Swal.fire('¡En marcha!', res.message || 'Ventana iniciada.', 'success');
                cargarSorteos();
            } else {
                Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo iniciar la ventana.', 'error');
            }
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo iniciar la ventana.', 'error'));
    });
}

const CC_AVATAR_BASE = '../../assets/avatars/';

function ccToast(msg) {
    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: msg, showConfirmButton: false, timer: 2000, timerProgressBar: true });
}

function renderStatusBadge(status) {
    const s = String(status || '').toLowerCase();
    if (s === 'open') {
        return `<span class="flash-status-badge"><span class="flash-live-dot"></span>EN VIVO</span>`;
    }
    if (s === 'scheduled') {
        return `<span class="flash-status-badge">PROGRAMADO</span>`;
    }
    if (s === 'waiting_for_winner') {
        return `<span class="flash-status-badge" style="color:#f59e0b;">ESPERANDO GANADOR</span>`;
    }
    if (s === 'locking' || s === 'animating') {
        return `<span class="flash-status-badge" style="color:#22d3ee;">EN CURSO</span>`;
    }
    if (s === 'completed') {
        return `<span class="flash-status-badge" style="color:#22c55e;">FINALIZADO</span>`;
    }
    return `<span class="flash-status-badge">${status || '-'}</span>`;
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

// =================================================================
//  GLOBAL AUDIO SETTINGS: Standalone modal & API
// =================================================================
let audioFilesCache = [];
let globalAudioCloseTimer = null;

function openGlobalAudioModal() {
    const $m = $('#modalGlobalAudio');
    if (globalAudioCloseTimer) { clearTimeout(globalAudioCloseTimer); globalAudioCloseTimer = null; }
    $m.removeClass('hidden');
    $('body').addClass('modal-active');
    // Load files + saved config
    loadAudioFiles().then(() => loadGlobalAudioConfig());
}

function closeGlobalAudioModal() {
    const $m = $('#modalGlobalAudio');
    $m.addClass('hidden');
    $('body').removeClass('modal-active');
}

async function loadAudioFiles() {
    try {
        const res = await $.get(`${API_URL}?action=list_audio_files`);
        if (res.status === 'success') {
            audioFilesCache = res.files || [];
            populateAllSfxSelects();
        }
    } catch (e) { console.error('[Sonidos] Error loading audio files:', e); }
}

function populateAllSfxSelects(preserveValues) {
    const saved = {};
    if (preserveValues) {
        $('.sfx-select').each(function() {
            saved[$(this).data('key')] = $(this).val();
        });
    }
    $('.sfx-select').each(function() {
        const $sel = $(this);
        const key = $sel.data('key');
        const curVal = saved[key] || $sel.val() || '';
        $sel.empty().append('<option value="">-- Silencio --</option>');
        audioFilesCache.forEach(f => {
            const sizeKb = Math.round((f.size || 0) / 1024);
            $sel.append(`<option value="${f.name}">${f.name} (${sizeKb}KB)</option>`);
        });
        if (curVal) $sel.val(curVal);
    });
}

async function loadGlobalAudioConfig() {
    try {
        const res = await $.get(`${API_URL}?action=get_global_audio`);
        if (res.status === 'success' && res.audio_config) {
            $('.sfx-select').val('');
            Object.keys(res.audio_config).forEach(key => {
                const $sel = $(`.sfx-select[data-key="${key}"]`);
                if ($sel.length && res.audio_config[key]) $sel.val(res.audio_config[key]);
            });
        }
    } catch (_) {}
}

async function saveGlobalAudio() {
    const config = {};
    let hasAny = false;
    $('.sfx-select').each(function() {
        const key = $(this).data('key');
        const val = $(this).val() || '';
        config[key] = val;
        if (val) hasAny = true;
    });

    try {
        const res = await $.post(API_URL, {
            action: 'save_global_audio',
            audio_config: hasAny ? JSON.stringify(config) : ''
        });
        if (res.status === 'success') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Sonidos globales guardados', showConfirmButton: false, timer: 2000, timerProgressBar: true });
            closeGlobalAudioModal();
        } else {
            Swal.fire('Error', res.message || 'No se pudo guardar.', 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Error de conexión.', 'error');
    }
}

async function uploadAudioFile() {
    const input = document.getElementById('sfxUploadInput');
    if (!input.files || !input.files[0]) {
        Swal.fire('Error', 'Selecciona un archivo de audio.', 'warning');
        return;
    }
    const $status = $('#sfxUploadStatus');
    $status.removeClass('hidden text-green-400 text-red-400').addClass('text-yellow-400').text('Subiendo...');

    const fd = new FormData();
    fd.append('action', 'upload_audio');
    fd.append('audio_file', input.files[0]);

    try {
        const res = await $.ajax({
            url: API_URL, type: 'POST', data: fd, processData: false, contentType: false
        });
        if (res.status === 'success') {
            $status.removeClass('text-yellow-400').addClass('text-green-400').text('✓ ' + (res.file ? res.file.name : 'Subido'));
            input.value = '';
            await loadAudioFiles();
            populateAllSfxSelects(true);
        } else {
            $status.removeClass('text-yellow-400').addClass('text-red-400').text('✗ ' + (res.message || 'Error'));
        }
    } catch (e) {
        $status.removeClass('text-yellow-400').addClass('text-red-400').text('✗ Error de conexión');
    }
}

// Close global audio modal on Escape or overlay click
$(document).ready(function() {
    $('#modalGlobalAudioOverlay').on('click', closeGlobalAudioModal);
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && !$('#modalGlobalAudio').hasClass('hidden')) closeGlobalAudioModal();
    });
});

// =================================================================
//  MASCOTA BANANO: slots de imagen del Portal de Transparencia
//  (separados por vista Escritorio/Móvil, con editor visual en vivo)
// =================================================================
const BANANO_SLOTS = {
    desktop: [
        { n: 1, label: 'Imagen 1 — Cabecera',        hint: 'Junto al título de bienvenida' },
        { n: 2, label: 'Imagen 2 — Paso 3 (BSC)',    hint: 'Apuntando al diagrama de la blockchain' },
        { n: 3, label: 'Imagen 3 — Página manga',    hint: 'Sobre el borde de la página manga de trampas (solo si hay página subida)' },
        { n: 4, label: 'Imagen 4 — Demo final',       hint: 'Verificación del sorteo real en 4 pasos' }
    ],
    mobile: [
        { n: 1, label: 'Imagen 1 — Cabecera',     hint: 'Bajo el título de bienvenida' },
        { n: 2, label: 'Imagen 2 — Paso 3 (BSC)', hint: 'Antes del bocadillo del diagrama' },
        { n: 3, label: 'Imagen 3 — Demo final',   hint: 'Cierre de la verificación en 4 pasos' }
    ]
};
let bananoSlotsCache = { desktop: {}, mobile: {}, pages: {} };
let bananoActiveTab = 'desktop';

function openBananoModal() {
    $('#modalBanano').removeClass('hidden');
    $('body').addClass('modal-active');
    loadBananoSlots();
}

function closeBananoModal() {
    $('#modalBanano').addClass('hidden');
    $('body').removeClass('modal-active');
}

function switchBananoTab(device) {
    bananoActiveTab = device;
    $('#bananoTabDesktop').attr('class', (device === 'desktop' ? 'flash-btn-primary' : 'flash-btn-secondary') + ' flex-1 px-3 py-2 text-xs font-bold');
    $('#bananoTabMobile').attr('class', (device === 'mobile' ? 'flash-btn-primary' : 'flash-btn-secondary') + ' flex-1 px-3 py-2 text-xs font-bold');
    renderBananoSlots();
}

async function loadBananoSlots() {
    try {
        const res = await $.get(`${API_URL}?action=banano_get_slots`);
        bananoSlotsCache = (res.status === 'success' && res.slots) ? res.slots : { desktop: {}, mobile: {}, pages: {} };
    } catch (e) { bananoSlotsCache = { desktop: {}, mobile: {}, pages: {} }; }
    renderBananoSlots();
    renderBananoPage();
}

function bananoTransformCss(cfg) {
    const s = Math.max(50, Math.min(200, parseInt(cfg.scale ?? 100, 10) || 100)) / 100;
    const r = Math.max(-30, Math.min(30, parseInt(cfg.rotate ?? 0, 10) || 0));
    const x = Math.max(-120, Math.min(120, parseInt(cfg.x ?? 0, 10) || 0));
    const y = Math.max(-120, Math.min(120, parseInt(cfg.y ?? 0, 10) || 0));
    return `translate(${x}px, ${y}px) rotate(${r}deg) scale(${s})`;
}

function renderBananoSlots() {
    const $grid = $('#bananoSlotsGrid').empty();
    const device = bananoActiveTab;
    BANANO_SLOTS[device].forEach(slotDef => {
        const cfg = (bananoSlotsCache[device] && bananoSlotsCache[device][slotDef.n]) || null;
        const url = (cfg && cfg.url) ? cfg.url : '';
        const id = `${device}_${slotDef.n}`;
        const preview = url
            ? `<div class="w-full h-36 flex items-center justify-center overflow-hidden" style="background: repeating-conic-gradient(rgba(128,128,128,0.15) 0% 25%, transparent 0% 50%) 50% / 16px 16px;">
                   <img id="bnPrev_${id}" src="${url}" alt="${slotDef.label}" class="max-h-32 object-contain" style="transform: ${bananoTransformCss(cfg)};">
               </div>`
            : `<div class="w-full h-36 flex items-center justify-center text-xs opacity-40 flash-text">Sin imagen — no se muestra nada en la página</div>`;
        const sliders = url ? `
            <div class="space-y-1.5 mt-2 text-[10px] flash-text-muted">
                <label class="flex items-center gap-2">🔍 Escala <input type="range" min="50" max="200" step="5" value="${cfg.scale ?? 100}" class="flex-1" oninput="bananoLivePreview('${device}', ${slotDef.n}, 'scale', this.value)"><span id="bnVal_${id}_scale" class="w-10 text-right font-bold">${cfg.scale ?? 100}%</span></label>
                <label class="flex items-center gap-2">🔄 Rotación <input type="range" min="-30" max="30" step="1" value="${cfg.rotate ?? 0}" class="flex-1" oninput="bananoLivePreview('${device}', ${slotDef.n}, 'rotate', this.value)"><span id="bnVal_${id}_rotate" class="w-10 text-right font-bold">${cfg.rotate ?? 0}°</span></label>
                <label class="flex items-center gap-2">↔️ Offset X <input type="range" min="-120" max="120" step="2" value="${cfg.x ?? 0}" class="flex-1" oninput="bananoLivePreview('${device}', ${slotDef.n}, 'x', this.value)"><span id="bnVal_${id}_x" class="w-10 text-right font-bold">${cfg.x ?? 0}px</span></label>
                <label class="flex items-center gap-2">↕️ Offset Y <input type="range" min="-120" max="120" step="2" value="${cfg.y ?? 0}" class="flex-1" oninput="bananoLivePreview('${device}', ${slotDef.n}, 'y', this.value)"><span id="bnVal_${id}_y" class="w-10 text-right font-bold">${cfg.y ?? 0}px</span></label>
                <label class="flex items-center gap-2 pt-1">✨ Animación flotante <input type="checkbox" id="bnVal_${id}_animate" ${(cfg.animate ?? 1) ? 'checked' : ''} onchange="bananoToggleAnimate('${device}', ${slotDef.n}, this.checked)"><span class="flash-text-muted">(desmarcar = imagen estática)</span></label>
                <div class="flex gap-1 pt-1">
                    <button onclick="saveBananoTransform('${device}', ${slotDef.n})" class="flash-btn-primary flex-1 px-2 py-1 text-[10px] font-bold"><i class="fas fa-save mr-1"></i>Guardar ajustes</button>
                    <button onclick="resetBananoTransform('${device}', ${slotDef.n})" class="flash-btn-secondary px-2 py-1 text-[10px] font-bold" title="Restablecer">↺</button>
                </div>
            </div>` : '';
        const delBtn = url
            ? `<button onclick="deleteBananoSlot('${device}', ${slotDef.n})" class="flash-btn-secondary px-2 py-1 text-[10px] font-bold" style="border-color: rgba(239,68,68,0.45); color: #f87171;"><i class="fas fa-trash"></i></button>`
            : '';
        $grid.append(`
            <div class="border rounded-lg overflow-hidden" style="border-color: var(--flash-border);">
                ${preview}
                <div class="p-2 border-t" style="border-color: var(--flash-border);">
                    <p class="text-xs font-bold flash-text">${slotDef.label}</p>
                    <p class="text-[10px] flash-text-muted mb-2">${slotDef.hint}</p>
                    <div class="flex items-center gap-1">
                        <button onclick="pickBananoSlotFile('${device}', ${slotDef.n})" class="flash-btn-primary flex-1 px-2 py-1 text-[10px] font-bold">${url ? 'Reemplazar' : 'Subir imagen'}</button>
                        ${delBtn}
                    </div>
                    ${sliders}
                </div>
            </div>`);
    });
}

// Vista previa en tiempo real: actualiza el transform del thumbnail sin guardar.
function bananoLivePreview(device, slot, prop, value) {
    if (!bananoSlotsCache[device]) bananoSlotsCache[device] = {};
    if (!bananoSlotsCache[device][slot]) bananoSlotsCache[device][slot] = {};
    bananoSlotsCache[device][slot][prop] = parseInt(value, 10) || 0;
    const cfg = bananoSlotsCache[device][slot];
    const id = `${device}_${slot}`;
    const img = document.getElementById(`bnPrev_${id}`);
    if (img) img.style.transform = bananoTransformCss(cfg);
    const suffix = { scale: '%', rotate: '°', x: 'px', y: 'px' }[prop] || '';
    $(`#bnVal_${id}_${prop}`).text(`${value}${suffix}`);
}

function bananoToggleAnimate(device, slot, checked) {
    if (!bananoSlotsCache[device]) bananoSlotsCache[device] = {};
    if (!bananoSlotsCache[device][slot]) bananoSlotsCache[device][slot] = {};
    bananoSlotsCache[device][slot].animate = checked ? 1 : 0;
}

function resetBananoTransform(device, slot) {
    const cfg = bananoSlotsCache[device] && bananoSlotsCache[device][slot];
    if (!cfg) return;
    cfg.scale = 100; cfg.rotate = 0; cfg.x = 0; cfg.y = 0;
    renderBananoSlots();
}

async function saveBananoTransform(device, slot) {
    const cfg = (bananoSlotsCache[device] && bananoSlotsCache[device][slot]) || {};
    try {
        const res = await $.post(API_URL, {
            action: 'save_banano_slot_transform',
            device: device, slot: slot,
            scale: cfg.scale ?? 100, rotate: cfg.rotate ?? 0, x: cfg.x ?? 0, y: cfg.y ?? 0,
            animate: (cfg.animate ?? 1) ? 1 : 0
        });
        if (res.status === 'success') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2000 });
        } else {
            Swal.fire('Error', res.message || 'No se pudo guardar.', 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Error de conexión.', 'error');
    }
}

function pickBananoSlotFile(device, slot) {
    const input = document.getElementById('bananoSlotFileInput');
    input.onchange = () => {
        if (input.files && input.files[0]) uploadBananoSlot(device, slot, input.files[0]);
        input.value = '';
    };
    input.click();
}

async function uploadBananoSlot(device, slot, file) {
    const fd = new FormData();
    fd.append('action', 'upload_banano_slot');
    fd.append('device', device);
    fd.append('slot', slot);
    fd.append('slot_file', file);
    try {
        const res = await $.ajax({ url: API_URL, type: 'POST', data: fd, processData: false, contentType: false });
        if (res.status === 'success') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2000 });
            loadBananoSlots();
        } else {
            Swal.fire('Error', res.message || 'No se pudo subir.', 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Error de conexión.', 'error');
    }
}

async function deleteBananoSlot(device, slot) {
    const c = await Swal.fire({ title: '¿Eliminar imagen?', text: 'La página pública dejará de mostrar la mascota en esa ubicación.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar' });
    if (!c.isConfirmed) return;
    try {
        const res = await $.post(API_URL, { action: 'delete_banano_slot', device: device, slot: slot });
        if (res.status === 'success') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2000 });
            loadBananoSlots();
        } else {
            Swal.fire('Error', res.message || 'No se pudo eliminar.', 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Error de conexión.', 'error');
    }
}

// --- Página manga completa (reemplaza la tabla de "¿Quién podría hacer trampa?") ---
function renderBananoPage() {
    const $box = $('#bananoPageManga').empty();
    const page = (bananoSlotsCache.pages && bananoSlotsCache.pages.manga_trampa) || null;
    const url = (page && page.url) ? page.url : '';
    const preview = url
        ? `<div class="w-full flex items-center justify-center overflow-hidden bg-white rounded" style="max-height: 260px;"><img src="${url}" alt="Página manga" class="max-h-60 object-contain"></div>`
        : `<div class="w-full h-24 flex items-center justify-center text-xs opacity-40 flash-text">Sin imagen — la sección no se muestra en la página pública</div>`;
    const delBtn = url
        ? `<button onclick="deleteBananoPage()" class="flash-btn-secondary px-2 py-1 text-[10px] font-bold" style="border-color: rgba(239,68,68,0.45); color: #f87171;"><i class="fas fa-trash"></i></button>`
        : '';
    $box.append(`
        <div class="border rounded-lg overflow-hidden" style="border-color: var(--flash-border);">
            ${preview}
            <div class="p-2 border-t flex items-center gap-1" style="border-color: var(--flash-border);">
                <button onclick="pickBananoPageFile()" class="flash-btn-primary flex-1 px-2 py-1 text-[10px] font-bold">${url ? 'Reemplazar página' : 'Subir página (PNG/WebP/JPG)'}</button>
                ${delBtn}
            </div>
        </div>`);
}

function pickBananoPageFile() {
    const input = document.getElementById('bananoPageFileInput');
    input.onchange = () => {
        if (input.files && input.files[0]) uploadBananoPage(input.files[0]);
        input.value = '';
    };
    input.click();
}

async function uploadBananoPage(file) {
    const fd = new FormData();
    fd.append('action', 'upload_banano_page');
    fd.append('page_key', 'manga_trampa');
    fd.append('page_file', file);
    try {
        const res = await $.ajax({ url: API_URL, type: 'POST', data: fd, processData: false, contentType: false });
        if (res.status === 'success') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2000 });
            loadBananoSlots();
        } else {
            Swal.fire('Error', res.message || 'No se pudo subir.', 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Error de conexión.', 'error');
    }
}

async function deleteBananoPage() {
    const c = await Swal.fire({ title: '¿Eliminar página manga?', text: 'La sección "¿Quién podría hacer trampa?" dejará de mostrarse en la página pública.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar' });
    if (!c.isConfirmed) return;
    try {
        const res = await $.post(API_URL, { action: 'delete_banano_page', page_key: 'manga_trampa' });
        if (res.status === 'success') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2000 });
            loadBananoSlots();
        } else {
            Swal.fire('Error', res.message || 'No se pudo eliminar.', 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Error de conexión.', 'error');
    }
}

// ============================================================
// TICKET DE BIENVENIDA: umbral y reglas de valoración (global)
// ============================================================
async function openWelcomeTicketModal() {
    let data;
    try {
        data = await $.get(`${API_URL}?action=wt_settings`);
    } catch (e) { Swal.fire('Error', 'No se pudo cargar la configuración.', 'error'); return; }
    if (!data || data.status !== 'success') { Swal.fire('Error', (data && data.message) || 'No se pudo cargar la configuración.', 'error'); return; }
    const s = data.settings || {};
    const rate = Number(data.soles_per_100_pavos || 0);
    const opt = (v, cur, label) => `<option value="${v}" ${v === cur ? 'selected' : ''}>${label}</option>`;
    const html = `
        <div style="text-align:left;font-size:.85rem;line-height:1.5;">
            <p style="opacity:.8;margin-bottom:10px;">Se otorga de forma implícita a quien tenga pedidos <b>completados</b> (compras reales, no premios) que alcancen el umbral. Es de <b>un solo uso</b> y no expira. Solo salta los requisitos de compras del sorteo; nunca el anti-fraude, el aforo, el costo de entrada ni “solo verificados”.</p>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;font-weight:700;"><input type="checkbox" id="wtEnabled" ${s.enabled ? 'checked' : ''}> Sistema activo</label>
            <label style="display:block;font-weight:700;margin-bottom:4px;">Umbral (paVos o Bananos equivalentes)</label>
            <input type="number" id="wtMin" class="flash-input w-full py-2 px-3" min="1" step="1" value="${Number(s.min_value || 1500)}">
            <p style="font-size:.72rem;opacity:.65;margin:4px 0 10px;">Tasa actual de la tienda: S/${rate.toFixed(2)} por 100 paVos → ${Number(s.min_value || 1500)} paVos ≈ S/${(rate * Number(s.min_value || 1500) / 100).toFixed(2)}.</p>
            <label style="display:block;font-weight:700;margin-bottom:4px;">Cómo valorar cada pedido</label>
            <select id="wtMode" class="flash-input w-full py-2 px-3">
                ${opt('max', s.value_mode, 'Máximo entre valor nativo y valor pagado (recomendado)')}
                ${opt('native', s.value_mode, 'Solo valor nativo del ítem (paVos del artículo)')}
                ${opt('paid', s.value_mode, 'Solo valor pagado (S/ + Bananos canjeados → paVos)')}
                ${opt('min', s.value_mode, 'Mínimo entre ambos (más estricto)')}
            </select>
            <p style="font-size:.72rem;opacity:.65;margin:4px 0 10px;">Ej.: skin de 1500 paVos con descuento → nativo 1500 / pagado &lt;1500. En “Máximo” califica; en “Pagado” o “Mínimo” no.</p>
            <label style="display:block;font-weight:700;margin-bottom:4px;">Alcance</label>
            <select id="wtScope" class="flash-input w-full py-2 px-3">
                ${opt('accumulated', s.scope, 'Acumulado de todos sus pedidos completados')}
                ${opt('single_order', s.scope, 'Un solo pedido debe alcanzar el umbral')}
            </select>
            <label style="display:flex;align-items:center;gap:8px;margin:12px 0 4px;font-weight:700;"><input type="checkbox" id="wtManual" ${s.include_manual ? 'checked' : ''}> Contar compras manuales aprobadas (chat)</label>
            <p style="font-size:.72rem;opacity:.65;margin-top:8px;">Tickets consumidos hasta hoy: <b>${Number(data.used_count || 0)}</b>.</p>
        </div>`;
    const r = await Swal.fire({
        title: '🎟️ Ticket de Bienvenida',
        html: html,
        width: 560,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        focusConfirm: false,
        preConfirm: () => {
            const min = parseFloat(document.getElementById('wtMin').value);
            if (!(min > 0)) { Swal.showValidationMessage('El umbral debe ser mayor que 0.'); return false; }
            return {
                enabled: document.getElementById('wtEnabled').checked ? 1 : 0,
                min_value: min,
                value_mode: document.getElementById('wtMode').value,
                scope: document.getElementById('wtScope').value,
                include_manual: document.getElementById('wtManual').checked ? 1 : 0
            };
        }
    });
    if (!r.isConfirmed || !r.value) return;
    try {
        const res = await $.post(API_URL, Object.assign({ action: 'wt_save_settings' }, r.value));
        if (res.status === 'success') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Ticket de Bienvenida actualizado', showConfirmButton: false, timer: 2000 });
        } else {
            Swal.fire('Error', res.message || 'No se pudo guardar.', 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Error de conexión.', 'error');
    }
}

$(document).ready(function() {
    $('#modalBananoOverlay').on('click', closeBananoModal);
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && !$('#modalBanano').hasClass('hidden')) closeBananoModal();
    });
});
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>
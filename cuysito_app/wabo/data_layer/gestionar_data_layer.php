<?php
// Archivo: data_layer/gestionar_data_layer.php (v1.0 - Gestor de Capa de Datos)
// Panel de Arquitectura/CTO. Independiente de descuentos_manuales/.
require_once __DIR__ . '/../core/admin_header.php';
?>
<style>
    .dl-tab { padding: .6rem 1rem; border-radius: .5rem; font-weight: 600; cursor: pointer; color: var(--text-secondary); white-space: nowrap; }
    .dl-tab.active { background-color: var(--accent); color: #111; }
    .dl-panel { display: none; }
    .dl-panel.active { display: block; animation: fadeIn .25s ease-in-out; }
    .dl-metric { background-color: var(--primary); border: 1px solid var(--border-color); border-radius: .75rem; padding: 1rem; }
    .dl-metric .v { font-size: 1.5rem; font-weight: 700; }
    .dl-section + .dl-section { margin-top: 1.75rem; }
    .dl-section-title { font-size: 1rem; font-weight: 700; margin-bottom: .75rem; padding-bottom: .4rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: .5rem; }
    .dl-section-count { font-size: .65rem; font-weight: 700; background: var(--primary); color: var(--text-secondary); padding: 2px 8px; border-radius: 999px; }
    .dl-badge { position: absolute; top: 6px; right: 6px; z-index: 10; background: var(--fortnite-yellow, #FFD700); color: #111; font-size: .6rem; font-weight: 700; padding: 2px 6px; border-radius: 999px; }
    .dl-badge-staged { top: auto; bottom: 6px; background: #38bdf8; color: #06283a; }
    .dl-badge-new { top: 6px; left: 6px; right: auto; background: #22c55e; color: #052e12; }
    .dl-badge-del { top: 6px; left: 6px; right: auto; background: #ef4444; color: #fff; }

    /* --- Creación de secciones/objetos + modo Organizar --- */
    .dl-section-tools { display: inline-flex; align-items: center; gap: .5rem; margin-left: .5rem; }
    .dl-add-item-btn {
        font-size: .7rem; font-weight: 700; padding: 3px 10px; border-radius: 999px;
        border: 1px dashed var(--border-color); color: var(--text-secondary);
        background: transparent; cursor: pointer; transition: color .15s, border-color .15s;
    }
    .dl-add-item-btn:hover { color: var(--accent); border-color: var(--accent); }
    .dl-section-draft-tag {
        font-size: .6rem; font-weight: 800; letter-spacing: .05em; padding: 2px 8px;
        border-radius: 999px; background: #22c55e22; color: #22c55e; border: 1px solid #22c55e55;
    }
    .dl-section-empty-hint { font-size: .8rem; color: var(--text-secondary); padding: .75rem 0; }

    .dl-organizing .item-card-wrapper[data-offerid] { cursor: grab; }
    .dl-organizing .item-card-wrapper[data-offerid]:focus { outline: 2px solid var(--accent); outline-offset: 2px; }
    .dl-org-dragging { opacity: .35; }
    .dl-org-ghost {
        position: fixed; z-index: 1200; pointer-events: none; width: 140px;
        border-radius: 10px; overflow: hidden; box-shadow: 0 12px 30px rgba(0,0,0,.6);
        transform: rotate(2deg); opacity: .92;
    }
    .dl-org-placeholder {
        border: 2px dashed var(--accent); border-radius: 12px; min-height: 120px;
        background: rgba(0, 245, 108, .05);
    }
    #dl-org-toolbar {
        position: fixed; z-index: 1100; display: none; align-items: center; gap: .4rem;
        background: var(--secondary, #1c1c24); border: 1px solid var(--border-color);
        border-radius: 10px; padding: .4rem .5rem; box-shadow: 0 10px 25px rgba(0,0,0,.55);
        flex-wrap: wrap; max-width: 340px;
    }
    #dl-org-toolbar .ot-name { width: 100%; font-size: .7rem; font-weight: 700; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #dl-org-toolbar button, #dl-org-toolbar select {
        font-size: .72rem; font-weight: 700; padding: 4px 8px; border-radius: 8px;
        border: 1px solid var(--border-color); background: transparent; color: var(--text-primary, #fff); cursor: pointer;
    }
    #dl-org-toolbar button:hover { border-color: var(--accent); color: var(--accent); }
    #dl-org-toolbar .ot-tile.sel { background: var(--accent); color: #08130c; border-color: var(--accent); }
    #dl-org-toolbar .ot-danger:hover { border-color: #ef4444; color: #ef4444; }
    .dl-mini-modal { max-width: 460px; width: 100%; margin: 0 1rem; }
    .dl-mini-modal .dlm-body { padding: 1rem 1.25rem; display: block; overflow: visible; }
    .dl-mini-modal label { display: block; font-size: .72rem; font-weight: 700; color: var(--text-secondary); margin: .6rem 0 .2rem; }
    .dl-tpl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: .5rem; }
    .dl-tpl-btn {
        border: 1px solid var(--border-color); border-radius: 10px; padding: .6rem .4rem;
        background: transparent; color: var(--text-primary, #fff); cursor: pointer; text-align: center;
        font-size: .78rem; font-weight: 700; transition: border-color .15s, color .15s;
    }
    .dl-tpl-btn i { display: block; font-size: 1.1rem; margin-bottom: .3rem; color: var(--text-secondary); }
    .dl-tpl-btn:hover, .dl-tpl-btn.sel { border-color: var(--accent); color: var(--accent); }
    .dl-tpl-btn.sel i { color: var(--accent); }
    .dl-modal { z-index: 1050; display: none; position: fixed; inset: 0; background: rgba(0,0,0,.7); align-items: center; justify-content: center; }
    .dl-modal.flex { display: flex; }
    .dl-input, .dl-select { background-color: var(--primary) !important; border: 1px solid var(--border-color) !important; color: var(--text-primary) !important; border-radius: .5rem; padding: .5rem .75rem; width: 100%; }
    .dl-th { text-align: left; font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text-secondary); padding: .5rem; }
    .dl-td { padding: .5rem; border-top: 1px solid var(--border-color); font-size: .85rem; }
    .dl-log-pre { white-space: pre-wrap; font-family: monospace; font-size: .8rem; background: #050505; color: #00cc66; padding: 1rem; border-radius: .5rem; max-height: 60vh; overflow-y: auto; }
    .sev-info { color: #38bdf8; } .sev-warning { color: #f59e0b; } .sev-error { color: #f87171; } .sev-critical { color: #ff3e3e; font-weight: 700; }
    .dl-btn { padding: .5rem .9rem; border-radius: .5rem; font-weight: 600; display: inline-flex; align-items: center; gap: .4rem; }
    .dl-style-row { display: flex; align-items: center; gap: .6rem; padding: .4rem; border: 1px solid var(--border-color); border-radius: .5rem; }
    .dl-style-row .bust { width: 44px; height: 44px; border-radius: .4rem; object-fit: cover; background: #0a0a0a; flex: none; }
    .dl-style-row .snm { font-size: .8rem; font-weight: 600; min-width: 110px; }
    .dl-style-thumbs { display: flex; gap: .4rem; flex-wrap: wrap; }
    .dl-style-thumb { width: 52px; height: 52px; border-radius: .4rem; object-fit: cover; background: #0a0a0a; border: 2px solid transparent; cursor: pointer; opacity: .55; transition: opacity .12s, border-color .12s; }
    .dl-style-thumb:hover { opacity: .85; }
    .dl-style-thumb.sel { border-color: var(--accent); opacity: 1; }
    /* ==== Jam-track difficulty editor: replica of the storefront's
       .jam-track-* styles (style.css), scoped as .dl-diff-* + interactive. */
    .dl-diff-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: .75rem 1.5rem; }
    .dl-diff-part { display: flex; align-items: center; gap: .75rem; }
    .dl-diff-num { font-size: 2.25rem; font-weight: 900; line-height: 1; color: #fff; flex-shrink: 0; min-width: 1.4rem; text-align: center; background: none; border: 0; padding: 0; cursor: pointer; }
    .dl-diff-num.off { color: #4b5563; }
    .dl-diff-name { font-size: .875rem; font-weight: 700; line-height: 1.2; text-transform: uppercase; color: #94a3b8; white-space: nowrap; }
    .dl-diff-bars { display: flex; gap: 3px; margin-top: 2px; }
    .dl-diff-bar { width: 14px; height: 8px; background-color: #4b5563; border-radius: 2px; transform: skewX(-15deg); border: 0; padding: 0; cursor: pointer; transition: background-color .1s; }
    .dl-diff-bar.active { background-color: #fff; }
    .dl-diff-bar:hover { background-color: var(--accent); }
    .dl-diff-bar:focus-visible, .dl-diff-num:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    /* --- Botones de purga global (header) --- */
    .dl-purge-btn { font-size: .68rem !important; padding: .3rem .6rem !important; background: rgba(239,68,68,.1); color: #f87171; border: 1px solid rgba(239,68,68,.25); border-radius: .4rem; transition: all .15s ease; }
    .dl-purge-btn:hover { background: rgba(239,68,68,.2); color: #fca5a5; border-color: rgba(239,68,68,.4); }
    .dl-purge-btn-all { background: rgba(239,68,68,.18); color: #ef4444; border-color: rgba(239,68,68,.35); font-weight: 700; }
    .dl-purge-btn-all:hover { background: rgba(239,68,68,.3); color: #fca5a5; }

    /* --- Diferir a API (smart API matching) --- */
    .dl-defer-section { border: 1px solid var(--border-color); border-radius: .5rem; padding: .75rem; margin-top: .75rem; background: rgba(56, 189, 248, .04); }
    .dl-defer-toggle { display: flex; align-items: center; gap: .5rem; cursor: pointer; font-size: .78rem; font-weight: 700; color: var(--text-secondary); }
    .dl-defer-toggle input[type="checkbox"] { accent-color: var(--accent); width: 1rem; height: 1rem; cursor: pointer; }
    .dl-defer-toggle:hover { color: var(--text-primary); }
    .dl-defer-opts { display: none; margin-top: .6rem; padding-top: .6rem; border-top: 1px solid var(--border-color); }
    .dl-defer-opts.open { display: flex; flex-direction: column; gap: .5rem; }
    .dl-defer-opt-row { display: flex; align-items: center; gap: .5rem; font-size: .72rem; }
    .dl-defer-opt-row label { font-weight: 600; color: var(--text-secondary); min-width: 140px; }
    .dl-defer-opt-row input[type="number"] { width: 60px; background: var(--primary); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: .35rem; padding: .25rem .5rem; font-size: .75rem; text-align: center; }
    .dl-defer-opt-row input[type="checkbox"] { accent-color: var(--accent); }
    .dl-defer-result { margin-top: .5rem; padding: .5rem .65rem; border-radius: .4rem; font-size: .72rem; }
    .dl-defer-result.match { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.3); color: #22c55e; }
    .dl-defer-result.no-match { background: rgba(248,113,113,.08); border: 1px solid rgba(248,113,113,.25); color: #f87171; }
    .dl-defer-result.checking { background: rgba(56,189,248,.08); border: 1px solid rgba(56,189,248,.25); color: #38bdf8; }
    .dl-defer-scores { margin-top: .4rem; }
    .dl-defer-score-row { display: flex; justify-content: space-between; align-items: center; font-size: .68rem; padding: .15rem 0; }
    .dl-defer-score-bar { flex: 1; height: 4px; background: var(--border-color); border-radius: 2px; margin: 0 .5rem; overflow: hidden; }
    .dl-defer-score-bar > span { display: block; height: 100%; border-radius: 2px; transition: width .3s ease; }
    .dl-defer-score-high > span { background: #22c55e; }
    .dl-defer-score-mid > span { background: #f59e0b; }
    .dl-defer-score-low > span { background: #f87171; }
</style>

<style>
    /* =====================================================================
       DLM — Item editor modal, rebuilt on the storefront's design system:
       modal tokens (style.css --bg-modal family), #filter-panel surface
       (blur + scale/fade entrance), .filter-action-btn interactions and
       the daily-spin token pattern. Scoped under .dlm.
       ===================================================================== */
    #dl-item-modal {
        z-index: 1050; display: none; position: fixed; inset: 0;
        align-items: center; justify-content: center; padding: 1rem;
        background-color: rgba(0, 0, 0, .5);
        -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px);
    }
    #dl-item-modal.flex { display: flex; }

    .dlm {
        --dlm-surface: var(--bg-modal, #18181b);
        --dlm-surface-2: var(--modal-bg-secondary, #27272a);
        --dlm-border: var(--border-modal, #3f3f46);
        --dlm-text: var(--text-modal-primary, #e4e4e7);
        --dlm-text-muted: var(--text-modal-secondary, #a1a1aa);
        --dlm-hover: var(--bg-modal-hover, rgba(255, 255, 255, .06));
        --dlm-accent: var(--accent, #FFD700);
        --dlm-ease: cubic-bezier(0.4, 0, 0.2, 1);
        --dlm-radius: .75rem;
        --dlm-radius-sm: .5rem;
        --dlm-font-heavy: 'HeadingNowVariable', 'Barlow', sans-serif;

        width: 100%; max-width: 56rem; max-height: 92vh;
        display: flex; flex-direction: column;
        border-radius: var(--dlm-radius); overflow: hidden;
        background-color: var(--dlm-surface); color: var(--dlm-text);
        border: 1px solid var(--dlm-border);
        box-shadow: 0 24px 60px rgba(0, 0, 0, .45);
        -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px);
        transform: scale(.95); opacity: 0;
        transition: transform .3s cubic-bezier(0.25, 0.46, 0.45, 0.94), opacity .3s ease;
    }
    #dl-item-modal.flex .dlm, .dl-modal.flex .dlm { transform: scale(1); opacity: 1; }

    .dlm-header, .dlm-footer {
        display: flex; align-items: center; gap: .75rem; flex: none;
        padding: 1rem 1.25rem; border-bottom: 1px solid var(--dlm-border);
    }
    .dlm-footer { border-bottom: 0; border-top: 1px solid var(--dlm-border); }
    .dlm-title {
        font-family: var(--dlm-font-heavy);
        font-variation-settings: "wdth" 825, "wght" 800;
        font-style: italic; text-transform: uppercase;
        font-size: 1.25rem; line-height: 1.25; margin: 0; flex: 1; min-width: 0;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .dlm-close {
        flex: none; width: 2.25rem; height: 2.25rem; border-radius: 999px;
        display: inline-flex; align-items: center; justify-content: center;
        color: var(--dlm-text-muted); background: transparent;
        transition: background-color .15s var(--dlm-ease), color .15s var(--dlm-ease);
    }
    .dlm-close:hover { background-color: var(--dlm-hover); color: var(--dlm-text); }
    .dlm-close svg { width: 1.1rem; height: 1.1rem; }

    .dlm-body {
        flex: 1; min-height: 0; overflow-y: auto;
        display: grid; grid-template-columns: 1fr; gap: 1.25rem;
        padding: 1.25rem; scrollbar-width: thin;
        scrollbar-color: var(--dlm-border) transparent;
    }
    @media (min-width: 768px) {
        .dlm-body { grid-template-columns: 260px minmax(0, 1fr); }
        .dlm-aside { position: sticky; top: 0; align-self: start; }
    }
    .dlm-aside { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
    .dlm-main { display: flex; flex-direction: column; gap: .75rem; min-width: 0; }

    /* Preview: storefront gradient formula behind the artwork */
    .dlm-preview {
        position: relative; width: 100%; aspect-ratio: 1 / 1;
        border-radius: var(--dlm-radius-sm); overflow: hidden;
        border: 1px solid var(--dlm-border);
        background-color: #0a0a0a;
        background-image: linear-gradient(180deg, #3a3a3e 0%, #232327 65%, #121215 100%);
    }
    .dlm-preview img {
        position: absolute; inset: 0; width: 100%; height: 100%;
        object-fit: contain; padding: .5rem;
    }

    /* Collapsible sections: native details/summary, storefront heading */
    .dlm-section {
        border: 1px solid var(--dlm-border); border-radius: var(--dlm-radius-sm);
        background-color: var(--dlm-surface-2); overflow: hidden;
    }
    .dlm-section > summary {
        list-style: none; cursor: pointer; user-select: none;
        display: flex; align-items: center; gap: .5rem;
        padding: .65rem .9rem;
        font-family: var(--dlm-font-heavy);
        font-variation-settings: "wdth" 825, "wght" 800;
        font-style: italic; text-transform: uppercase;
        font-size: .8rem; letter-spacing: .02em;
        transition: background-color .15s var(--dlm-ease);
    }
    .dlm-section > summary::-webkit-details-marker { display: none; }
    .dlm-section > summary:hover { background-color: var(--dlm-hover); }
    .dlm-section > summary::after {
        content: ''; margin-left: auto; width: .5rem; height: .5rem; flex: none;
        border-right: 2px solid var(--dlm-text-muted); border-bottom: 2px solid var(--dlm-text-muted);
        transform: rotate(45deg); transition: transform .2s var(--dlm-ease);
    }
    .dlm-section[open] > summary::after { transform: rotate(225deg); }
    .dlm-section > .dlm-section-body { padding: .35rem .9rem .9rem; display: flex; flex-direction: column; gap: .75rem; }

    .dlm-field { display: flex; flex-direction: column; gap: .3rem; min-width: 0; }
    .dlm-label {
        display: flex; align-items: center; gap: .4rem;
        font-size: .7rem; font-weight: 700; letter-spacing: .04em;
        text-transform: uppercase; color: var(--dlm-text-muted);
    }
    .dlm-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
    .dlm-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }

    .dlm input[type="text"], .dlm input[type="number"], .dlm input[type="date"], .dlm input:not([type]), .dlm textarea {
        width: 100%; background-color: var(--dlm-surface);
        border: 1px solid var(--dlm-border); color: var(--dlm-text);
        border-radius: var(--dlm-radius-sm); padding: .5rem .75rem;
        font-size: .85rem; line-height: 1.35;
        transition: border-color .15s var(--dlm-ease), background-color .15s var(--dlm-ease);
    }
    .dlm input:hover, .dlm textarea:hover { background-color: var(--dlm-hover); }
    .dlm input:focus-visible, .dlm textarea:focus-visible {
        outline: none; border-color: var(--dlm-accent);
    }
    /* No native spinners on number inputs */
    .dlm input[type="number"] { -moz-appearance: textfield; appearance: textfield; }
    .dlm input[type="number"]::-webkit-inner-spin-button,
    .dlm input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    .dlm input[type="date"] { color-scheme: dark; }
    /* Description: no manual resize, JS autosizes up to max-height */
    .dlm textarea { resize: none; overflow-y: hidden; max-height: 220px; scrollbar-width: thin; scrollbar-color: var(--dlm-border) transparent; }

    /* Custom dropdown (progressive enhancement over a hidden native select) */
    .dlm-select-native { display: none !important; }
    .dlm-select { position: relative; min-width: 0; }
    .dlm-select-btn {
        width: 100%; display: flex; align-items: center; gap: .5rem;
        background-color: var(--dlm-surface); border: 1px solid var(--dlm-border);
        color: var(--dlm-text); border-radius: var(--dlm-radius-sm);
        padding: .5rem .75rem; font-size: .85rem; line-height: 1.35;
        cursor: pointer; text-align: left;
        transition: border-color .15s var(--dlm-ease), background-color .15s var(--dlm-ease);
    }
    .dlm-select-btn:hover { background-color: var(--dlm-hover); }
    .dlm-select-btn:focus-visible { outline: none; border-color: var(--dlm-accent); }
    .dlm-select-btn .lbl { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dlm-select-btn .caret {
        flex: none; width: .45rem; height: .45rem;
        border-right: 2px solid var(--dlm-text-muted); border-bottom: 2px solid var(--dlm-text-muted);
        transform: rotate(45deg) translateY(-1px); transition: transform .2s var(--dlm-ease);
    }
    .dlm-select.open .dlm-select-btn .caret { transform: rotate(225deg); }
    .dlm-select-list {
        position: absolute; z-index: 30; top: calc(100% + 4px); left: 0; right: 0;
        max-height: 240px; overflow-y: auto; display: none;
        background-color: var(--dlm-surface); border: 1px solid var(--dlm-border);
        border-radius: var(--dlm-radius-sm); padding: .25rem;
        box-shadow: 0 12px 28px rgba(0, 0, 0, .45);
        scrollbar-width: thin; scrollbar-color: var(--dlm-border) transparent;
    }
    .dlm-select.open .dlm-select-list { display: block; }
    .dlm-select-opt {
        display: flex; align-items: center; gap: .5rem;
        padding: .45rem .6rem; border-radius: .375rem; cursor: pointer;
        font-size: .82rem; color: var(--dlm-text);
    }
    .dlm-select-opt:hover, .dlm-select-opt.focus { background-color: var(--dlm-hover); }
    .dlm-select-opt.sel { color: var(--dlm-accent); font-weight: 700; }
    .dlm-select-opt .ic { flex: none; width: .85rem; height: .85rem; }

    /* Segmented control (banner state / tile span) */
    .dlm-seg { display: flex; flex-wrap: wrap; gap: .35rem; }
    .dlm-seg-btn {
        flex: 1 1 auto; min-width: 0; padding: .45rem .6rem;
        border-radius: var(--dlm-radius-sm);
        background-color: var(--dlm-surface); border: 1px solid var(--dlm-border);
        color: var(--dlm-text-muted); font-size: .72rem; font-weight: 700;
        letter-spacing: .03em; text-transform: uppercase; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; gap: .35rem;
        transition: border-color .15s var(--dlm-ease), background-color .15s var(--dlm-ease), color .15s var(--dlm-ease);
    }
    .dlm-seg-btn:hover:not(:disabled) { background-color: var(--dlm-hover); color: var(--dlm-text); }
    .dlm-seg-btn:focus-visible { outline: none; border-color: var(--dlm-accent); }
    .dlm-seg-btn:disabled { opacity: .45; cursor: not-allowed; }
    .dlm-seg-btn[aria-checked="true"], .dlm-seg-btn[aria-pressed="true"] {
        border-color: var(--dlm-accent); color: var(--dlm-accent);
        background-color: rgba(255, 215, 0, .08);
    }
    .dlm-seg-btn .ic { flex: none; width: .8rem; height: .8rem; }
    #dl-banner-detail, #nc-banner-detail { display: none; flex-direction: column; gap: .75rem; }
    #dl-banner-detail.open, #nc-banner-detail.open { display: flex; }
    #nc-banner-detail { margin-top: .5rem; }
    /* --- Image upload control (shared by edit + create modals) --- */
    .dl-upload-row { display: flex; gap: .5rem; align-items: center; margin-top: .4rem; }
    .dl-upload-btn { display: inline-flex; align-items: center; gap: .4rem; font-size: .72rem; font-weight: 700; padding: .35rem .65rem; border-radius: .45rem; border: 1px solid var(--border-color); background: var(--primary); color: var(--text-primary); cursor: pointer; white-space: nowrap; }
    .dl-upload-btn:hover:not(:disabled) { border-color: var(--dlm-accent, #3b82f6); color: var(--dlm-accent, #3b82f6); }
    .dl-upload-btn:disabled { opacity: .55; cursor: wait; }
    .dl-upload-btn:focus-visible { outline: 2px solid var(--dlm-accent, #3b82f6); outline-offset: 2px; }
    .dl-upload-btn .dl-upload-spinner { display: none; width: .8rem; height: .8rem; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: dl-upload-spin .7s linear infinite; }
    .dl-upload-btn.busy .dl-upload-spinner { display: inline-block; }
    @keyframes dl-upload-spin { to { transform: rotate(360deg); } }
    .dl-upload-progress { flex: 1; height: 4px; border-radius: 2px; background: var(--border-color); overflow: hidden; display: none; }
    .dl-upload-progress > span { display: block; height: 100%; width: 0; background: var(--dlm-accent, #3b82f6); transition: width .15s ease; }
    .dl-upload-msg { font-size: .7rem; margin: .25rem 0 0; display: none; }
    .dl-upload-msg.err { display: block; color: #f87171; }
    .dl-upload-msg.ok { display: block; color: #34d399; }
    /* --- Create modal: per-slot image cards + gradient preview --- */
    .nc-slot-card { border: 1px solid var(--border-color); border-radius: .5rem; padding: .6rem .7rem .7rem; margin-top: .5rem; }
    .nc-slot-card > label { margin-top: 0 !important; }
    .nc-slot-preview-box { display: flex; align-items: center; justify-content: center; width: 100%; min-height: 110px; background: rgba(0,0,0,.25); border: 1px dashed rgba(255,255,255,.18); border-radius: .4rem; margin-top: .45rem; overflow: hidden; }
    .nc-slot-preview { display: block; width: 100%; max-height: 110px; object-fit: contain; }
    .nc-slot-preview-empty { font-size: .7rem; color: var(--text-secondary); }
    #nc-grad-preview { height: 56px; border-radius: .5rem; border: 1px solid var(--border-color); margin-top: .45rem; }

    /* Corner banner replica inside the modal preview (same look as the grid) */
    .dlm-preview [data-banner-type="new"] {
        position: absolute; top: 6px; left: 6px; z-index: 5;
        padding: .3em .8em; border-radius: 6px;
        background-color: #f7ff19; color: #000;
        font: italic 900 clamp(0.8rem, 0.7rem + 0.5vw, 0.95rem)/1.3 sans-serif;
        text-transform: uppercase; letter-spacing: .05em; white-space: nowrap;
    }

    /* Buttons: replica of the storefront's .filter-action-btn interactions */
    .dlm-btn {
        font-family: var(--dlm-font-heavy);
        font-variation-settings: "wdth" 825, "wght" 800;
        font-style: italic; text-transform: uppercase;
        font-size: .8rem; line-height: 1.25; letter-spacing: .02em;
        display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
        padding: .55rem 1.1rem; border-radius: var(--dlm-radius-sm);
        cursor: pointer; position: relative; overflow: hidden;
        outline: 3px solid transparent; outline-offset: 10px;
        transition: outline-color .15s var(--dlm-ease), outline-offset .15s var(--dlm-ease);
    }
    .dlm-btn:hover:not(:disabled) { outline-color: rgba(255, 255, 255, .4); outline-offset: 4px; }
    .dlm-btn:focus-visible { outline-color: #fff; outline-offset: 4px; }
    .dlm-btn::after {
        content: ''; position: absolute; inset: 0; background-color: #000;
        opacity: 0; transition: opacity .1s var(--dlm-ease); pointer-events: none;
    }
    .dlm-btn:active:not(:disabled)::after { opacity: .2; }
    .dlm-btn:disabled { opacity: .45; cursor: not-allowed; }
    .dlm-btn svg { width: 1em; height: 1em; }
    .dlm-btn-primary { background-color: var(--dlm-accent); color: #111; }
    .dlm-btn-ghost { background-color: var(--dlm-surface-2); color: var(--dlm-text); border: 1px solid var(--dlm-border); }
    .dlm-btn-danger { background-color: #7f1d1d; color: #fecaca; }

    /* offerId row: square icon button matched to the input's height */
    .dlm-offerid-row { display: flex; gap: .5rem; align-items: stretch; }
    .dlm-offerid-row input { font-size: .72rem; font-family: monospace; }
    .dlm-icon-btn {
        flex: none; aspect-ratio: 1; width: auto; padding: .5rem;
        display: inline-flex; align-items: center; justify-content: center;
        background-color: var(--dlm-surface-2); color: var(--dlm-text);
        border: 1px solid var(--dlm-border); border-radius: var(--dlm-radius-sm);
        cursor: pointer;
        transition: background-color .15s var(--dlm-ease), color .15s var(--dlm-ease), border-color .15s var(--dlm-ease);
    }
    .dlm-icon-btn:hover { background-color: var(--dlm-hover); color: var(--dlm-accent); border-color: var(--dlm-accent); }
    .dlm-icon-btn:focus-visible { outline: 2px solid var(--dlm-accent); outline-offset: 2px; }
    .dlm-icon-btn svg { width: 1.25rem; height: 1.25rem; fill: currentColor; }

    .dlm-hint { font-size: .7rem; color: var(--dlm-text-muted); margin: 0; }
    .dlm-hint.break { word-break: break-all; }

    /* Per-field override chip: shows a saved override + one-click reset */
    .dlm-chip {
        display: inline-flex; align-items: center; gap: .25rem;
        font-size: .6rem; font-weight: 700; letter-spacing: .03em;
        background: rgba(255, 215, 0, .12); color: var(--dlm-accent);
        border: 1px solid rgba(255, 215, 0, .35);
        padding: 0 .1rem 0 .4rem; border-radius: 999px; text-transform: none;
    }
    .dlm-chip button {
        display: inline-flex; align-items: center; justify-content: center;
        width: .95rem; height: .95rem; border-radius: 999px; color: inherit;
        font-size: .65rem; line-height: 1; cursor: pointer;
    }
    .dlm-chip button:hover { background: rgba(255, 215, 0, .25); }
    .dlm-chip.staged { background: rgba(56, 189, 248, .12); color: #38bdf8; border-color: rgba(56, 189, 248, .4); }
    .dlm-chip.staged button:hover { background: rgba(56, 189, 248, .25); }
    .dlm-chip.staged-del { background: rgba(248, 113, 113, .12); color: #f87171; border-color: rgba(248, 113, 113, .4); }
    .dlm-chip.staged-del button:hover { background: rgba(248, 113, 113, .25); }

    .dlm-dirty { font-size: .75rem; color: var(--dlm-text-muted); margin-left: auto; margin-right: .5rem; white-space: nowrap; }
    .dlm-dirty.has { color: var(--dlm-accent); font-weight: 700; }
    @media (max-width: 640px) {
        .dlm-footer { flex-wrap: wrap; }
        .dlm-dirty { width: 100%; margin: 0; order: -1; text-align: right; }
    }
</style>

<style>
    /* =====================================================================
       SHOP-CARD REPLICA — the Editor Visual / Vista Previa grids render the
       EXACT storefront tile. Every rule below is copied from the shop's
       style.css (same class names, values and cascade), scoped under
       .dl-grid so it cannot leak into the rest of the admin panel.
       ===================================================================== */
    @font-face {
        font-family: 'HeadingNowVariable';
        font-style: normal;
        font-stretch: normal;
        font-display: swap;
        src: url('/assets/fonts/HeadingNowVariable.woff2') format('woff2');
    }

    .dl-grid {
        --font-heading-now-medium: 'HeadingNowVariable', 'Barlow', sans-serif;
        --font-heading-now-medium--font-variation-settings: "wdth" 668, "wght" 500;
        --font-heading-now-heavy: 'HeadingNowVariable', 'Barlow', sans-serif;
        --font-heading-now-heavy--font-variation-settings: "wdth" 825, "wght" 800;
        --tile-headroom: 0.15;
        --grid-gap: clamp(0.5rem, 1.8vw, 1.25rem);

        /* Official shop grid: fixed column counts per breakpoint with
           densification so multi-span tiles leave no holes. */
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: var(--grid-gap);
        grid-auto-flow: dense;
    }

    @media (min-width: 640px) {
        .dl-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }

    @media (min-width: 768px) {
        .dl-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }

    .dl-grid > * {
        min-width: 0;
        min-height: 0;
    }

    .dl-grid > .span-card-2 { grid-column: span 2; width: 100%; }
    .dl-grid > .span-card-3 { grid-column: span 3; width: 100%; }
    .dl-grid > .span-card-4 { grid-column: span 4; width: 100%; }

    /* Mobile guardrail: nothing exceeds span 2 below 768px */
    @media (max-width: 767px) {
        .dl-grid > .span-card-3,
        .dl-grid > .span-card-4 { grid-column: span 2; }
    }

    /* ── Tile anatomy ── */
    .dl-grid .item-card-wrapper {
        --card-overlay-rgb: 8, 8, 10;
        position: relative;
        display: block;
        border-radius: 8px;
        overflow: visible;
        z-index: 2;
        background-color: transparent;
        transition: transform 0.3s, z-index 0s;
        contain: layout style;
        border: 1px solid transparent;
        min-width: 0;
        aspect-ratio: 0.627;
    }

    .dl-grid .item-card-wrapper[data-offerid] { cursor: pointer; }

    .dl-grid .span-card-2 .item-card-wrapper { aspect-ratio: 2 / 1.52; }
    .dl-grid .span-card-3 .item-card-wrapper { aspect-ratio: 1 / 0.516; }
    .dl-grid .span-card-4 .item-card-wrapper { aspect-ratio: 1 / 0.38625; }

    @media (max-width: 767px) {
        .dl-grid .span-card-3 .item-card-wrapper,
        .dl-grid .span-card-4 .item-card-wrapper { aspect-ratio: 2 / 1.52; }
    }

    .dl-grid .item-card-content {
        color: white;
        position: relative;
        display: flex;
        flex-direction: column;
        height: 100%;
        border-radius: 8px;
        min-width: 0;
    }

    /* Visible card box = bottom 85% of the tile; artwork pops out above.
       clip-path clips sides/bottom while allowing top overflow. */
    .dl-grid .item-image-container {
        position: absolute;
        inset-inline: 0;
        bottom: 0;
        height: calc(100% * (1 - var(--tile-headroom)));
        background-size: cover;
        background-position: center;
        cursor: pointer;
        border-radius: 7px;
        clip-path: inset(-18% 0 0 0 round 7px);
        z-index: 1;
        isolation: isolate;
    }

    /* Gradient bg on a pseudo so hover brightness spares overlays */
    .dl-grid .item-image-container::before {
        content: '';
        position: absolute;
        inset: 0;
        background: inherit;
        border-radius: inherit;
        z-index: 0;
        pointer-events: none;
        transition: filter 0s;
    }

    .dl-grid .item-image {
        position: absolute;
        left: var(--img-left, 50%);
        top: var(--img-top, 76.5%);
        z-index: 1;
        width: 100%;
        max-width: none;
        height: var(--img-h, 117.65%);
        object-fit: var(--img-fit, cover);
        transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94), opacity 0.15s ease-in-out, filter 0s;
        transform: translate(-50%, -50%) scale(calc(var(--img-scale, 1.6) * var(--img-hover-boost, 1)));
        image-rendering: -webkit-optimize-contrast;
    }

    /* Artwork fades into the card bg over the 80%–95% band of the box */
    .dl-grid .item-image-container::after {
        content: '';
        position: absolute;
        inset: 0;
        background: inherit;
        border-radius: inherit;
        z-index: 2;
        pointer-events: none;
        -webkit-mask-image: linear-gradient(transparent 0% 80%, #000 95%);
        mask-image: linear-gradient(transparent 0% 80%, #000 95%);
    }

    /* ── Per-type artwork framing (1×1 tiles) ── */
    .dl-grid .item-card-wrapper[data-item-type="Gestos"] .item-image,
    .dl-grid .item-card-wrapper[data-item-type="Envoltorios"] .item-image,
    .dl-grid .item-card-wrapper[data-item-type="Estelas"] .item-image,
    .dl-grid .item-card-wrapper[data-item-type="Música"] .item-image,
    .dl-grid .item-card-wrapper[data-item-type="Ruedas"] .item-image {
        --img-fit: contain;
        --img-scale: 0.7;
        --img-top: 38%;
        --img-left: 52.8%;
        --img-h: 100%;
        object-position: top;
        width: auto;
        aspect-ratio: 1;
    }

    .dl-grid .item-card-wrapper[data-item-type="Picos"] .item-image,
    .dl-grid .item-card-wrapper[data-item-type="Instrumentos"] .item-image {
        --img-fit: contain;
        --img-scale: 0.75;
        --img-top: 25.9%;
        --img-left: 49.8%;
        --img-h: 117.65%;
        width: auto;
        aspect-ratio: 1;
    }

    .dl-grid .item-card-wrapper[data-item-type="Alas delta"] .item-image,
    .dl-grid .item-card-wrapper[data-item-type="Accesorios mochileros"] .item-image,
    .dl-grid .item-card-wrapper[data-item-id="Pickaxe_ThinGlaze"] .item-image {
        --img-fit: contain;
        --img-scale: 0.78;
        --img-top: 42%;
        --img-left: 52.8%;
        --img-h: 100%;
        width: auto;
        aspect-ratio: 1;
    }

    .dl-grid .item-card-wrapper[data-item-type="Calzado"] .item-image {
        --img-fit: contain;
        --img-scale: 0.8;
        --img-top: 42%;
        --img-left: 52.8%;
        --img-h: 100%;
        width: auto;
        aspect-ratio: 1;
    }

    .dl-grid .item-card-wrapper[data-item-type="Compañeros"] .item-image {
        --img-fit: contain;
        --img-scale: 0.7;
        --img-top: 38%;
        --img-left: 52.8%;
        --img-h: 100%;
        width: auto;
        aspect-ratio: 1;
    }

    .dl-grid .item-card-wrapper[data-item-type="Pistas de improvisación"] .item-image {
        --img-fit: contain;
        --img-scale: 0.875;
        --img-top: calc(14.7% + 2.5rem);
        --img-h: auto;
        border-radius: 0.375rem;
        width: 100%;
        aspect-ratio: 1;
    }

    .dl-grid .item-card-wrapper[data-item-type="Coches"] .item-image {
        --img-fit: cover;
        --img-scale: 1;
        --img-top: 41.2%;
        --img-h: 117.65%;
    }

    .dl-grid .item-card-wrapper[data-item-type="Lotes"] .item-image {
        --img-fit: cover;
        --img-scale: 1;
        --img-top: 47.1%;
        --img-h: 117.65%;
    }

    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Lotes"] .item-image {
        --img-fit: contain;
        --img-scale: 1.4;
        --img-top: 70.6%;
        --img-h: 117.65%;
    }

    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Lotes"] .item-image {
        --img-fit: contain;
        --img-scale: 1.75;
        --img-top: 74.1%;
        --img-h: 117.65%;
    }

    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Lotes"] .item-image {
        --img-fit: contain;
        --img-scale: 2.28;
        --img-top: 109.4%;
        --img-h: 117.65%;
    }

    @media (max-width: 767px) {
        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Lotes"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Lotes"] .item-image {
            --img-fit: contain;
            --img-scale: 1.4;
            --img-top: 70.6%;
        }
    }

    /* ── Wide tiles (2+ columns): official grande/venti/trenta framing ── */
    .dl-grid .span-card-2 .item-card-wrapper .item-image,
    .dl-grid .span-card-3 .item-card-wrapper .item-image,
    .dl-grid .span-card-4 .item-card-wrapper .item-image {
        --img-fit: contain;
        --img-scale: 1.6;
        --img-top: 74.7%;
        --img-left: 50%;
        --img-h: 117.65%;
        object-position: center;
        width: 100%;
        aspect-ratio: auto;
    }

    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Gestos"] .item-image,
    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Envoltorios"] .item-image,
    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Estelas"] .item-image,
    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Ruedas"] .item-image {
        --img-scale: 0.9;
        --img-top: 38.2%;
    }

    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Gestos"] .item-image,
    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Envoltorios"] .item-image,
    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Estelas"] .item-image,
    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Ruedas"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Gestos"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Envoltorios"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Estelas"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Ruedas"] .item-image {
        --img-scale: 0.95;
        --img-top: 41.2%;
    }

    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Picos"] .item-image,
    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Alas delta"] .item-image,
    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Accesorios mochileros"] .item-image,
    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Instrumentos"] .item-image {
        --img-scale: 0.85;
        --img-top: 30.6%;
    }

    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Picos"] .item-image,
    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Alas delta"] .item-image,
    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Accesorios mochileros"] .item-image,
    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Instrumentos"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Picos"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Alas delta"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Accesorios mochileros"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Instrumentos"] .item-image {
        --img-scale: 0.95;
        --img-top: 35.6%;
    }

    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Calzado"] .item-image {
        --img-scale: 0.9;
        --img-top: 23.5%;
    }

    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Calzado"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Calzado"] .item-image {
        --img-scale: 0.95;
        --img-top: 29.4%;
    }

    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Compañeros"] .item-image {
        --img-scale: 0.9;
        --img-top: 32.9%;
    }

    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Compañeros"] .item-image {
        --img-scale: 0.95;
        --img-top: 32.9%;
    }

    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Compañeros"] .item-image {
        --img-scale: 1;
        --img-top: 32.9%;
    }

    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Pistas de improvisación"] .item-image {
        --img-scale: 0.75;
        --img-top: 30.6%;
        width: auto;
        aspect-ratio: 1;
    }

    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Pistas de improvisación"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Pistas de improvisación"] .item-image {
        --img-scale: 0.8;
        --img-top: 35.3%;
        width: auto;
        aspect-ratio: 1;
    }

    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Pistas de improvisación"] .item-image {
        --img-left: 62.5%;
    }

    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Pistas de improvisación"] .item-image {
        --img-left: 60%;
    }

    .dl-grid .span-card-2 .item-card-wrapper[data-item-type="Coches"] .item-image,
    .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Coches"] .item-image,
    .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Coches"] .item-image {
        --img-fit: cover;
        --img-scale: 1;
        --img-top: 41.2%;
    }

    /* 3/4-wide tiles fall back to their grande framing below 768px */
    @media (max-width: 767px) {
        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Gestos"] .item-image,
        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Envoltorios"] .item-image,
        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Estelas"] .item-image,
        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Ruedas"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Gestos"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Envoltorios"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Estelas"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Ruedas"] .item-image {
            --img-scale: 0.9;
            --img-top: 38.2%;
        }

        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Picos"] .item-image,
        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Alas delta"] .item-image,
        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Accesorios mochileros"] .item-image,
        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Instrumentos"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Picos"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Alas delta"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Accesorios mochileros"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Instrumentos"] .item-image {
            --img-scale: 0.85;
            --img-top: 30.6%;
        }

        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Calzado"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Calzado"] .item-image {
            --img-scale: 0.9;
            --img-top: 23.5%;
        }

        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Compañeros"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Compañeros"] .item-image {
            --img-scale: 0.9;
            --img-top: 32.9%;
        }

        .dl-grid .span-card-3 .item-card-wrapper[data-item-type="Pistas de improvisación"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-item-type="Pistas de improvisación"] .item-image {
            --img-left: 50%;
            --img-scale: 0.75;
            --img-top: 30.6%;
        }
    }

    /* Dynamic bundles use the bundle framing at every size */
    .dl-grid .item-card-wrapper[data-dynamic-bundle="1"] .item-image {
        --img-fit: cover;
        --img-scale: 1;
        --img-top: 47.1%;
        --img-left: 50%;
        --img-h: 117.65%;
        object-position: center;
        width: 100%;
        aspect-ratio: auto;
        border-radius: 0;
    }

    .dl-grid .span-card-2 .item-card-wrapper[data-dynamic-bundle="1"] .item-image {
        --img-fit: contain;
        --img-scale: 1.4;
        --img-top: 70.6%;
        --img-h: 117.65%;
        width: 100%;
        aspect-ratio: auto;
    }

    .dl-grid .span-card-3 .item-card-wrapper[data-dynamic-bundle="1"] .item-image {
        --img-fit: contain;
        --img-left: 50%;
        --img-scale: 1.75;
        --img-top: 74.1%;
        --img-h: 117.65%;
        width: 100%;
        aspect-ratio: auto;
    }

    .dl-grid .span-card-4 .item-card-wrapper[data-dynamic-bundle="1"] .item-image {
        --img-fit: contain;
        --img-left: 50%;
        --img-scale: 2.28;
        --img-top: 109.4%;
        --img-h: 117.65%;
        width: 100%;
        aspect-ratio: auto;
    }

    @media (max-width: 767px) {
        .dl-grid .span-card-3 .item-card-wrapper[data-dynamic-bundle="1"] .item-image,
        .dl-grid .span-card-4 .item-card-wrapper[data-dynamic-bundle="1"] .item-image {
            --img-fit: contain;
            --img-scale: 1.4;
            --img-top: 70.6%;
        }
    }

    /* framed-cover (vehicle offers): artwork stays inside the clipped box,
       anchored right, with a 1.03 zoom clipped by the box on hover */
    .dl-grid .item-card-wrapper[data-car-offer="1"] .item-image-container {
        clip-path: inset(0 round 7px);
    }

    .dl-grid .item-card-wrapper[data-car-offer="1"] .item-image {
        inset: 0;
        width: 100%;
        height: 100%;
        aspect-ratio: auto;
        transform: none;
        object-fit: cover;
        object-position: 100% 50%;
    }

    .dl-grid .item-card-wrapper[data-car-offer="1"] .item-image-container:hover .item-image,
    .dl-grid .item-card-wrapper[data-car-offer="1"] .item-image-container:focus-visible .item-image {
        transform: scale(1.03);
    }

    /* ── Hover / focus ── */
    .dl-grid .item-card-wrapper:has(.item-image-container:hover),
    .dl-grid .item-card-wrapper:has(.item-image-container:focus-visible) {
        z-index: 5;
    }

    .dl-grid .item-card-content::after {
        content: '';
        position: absolute;
        inset: 15% 0 0 0;
        border-radius: 7px;
        pointer-events: none;
        z-index: 0;
        transition: box-shadow 0.2s ease-in-out;
    }

    .dl-grid .item-card-content:has(.item-image-container:hover)::after,
    .dl-grid .item-card-content:has(.item-image-container:focus-visible)::after {
        box-shadow: 0 0 0 4px #ffffff;
    }

    .dl-grid .item-image-container:hover,
    .dl-grid .item-image-container:focus-visible {
        outline: none;
    }

    .dl-grid .item-image-container:hover::before,
    .dl-grid .item-image-container:focus-visible::before {
        filter: brightness(1.35);
    }

    .dl-grid .item-image-container:hover > .item-image,
    .dl-grid .item-image-container:focus-visible > .item-image {
        filter: brightness(1.35);
    }

    /* Uniform on-screen growth: boost divided by the base scale */
    .dl-grid .item-image-container:hover .item-image,
    .dl-grid .item-image-container:focus-visible .item-image {
        --img-hover-boost: calc(1 + 0.048 / var(--img-scale, 1.6));
    }

    /* Preview cards are not interactive */
    .dl-grid .item-card-wrapper:not([data-offerid]) .item-image-container {
        cursor: default;
    }

    /* ── Corner banner ("Nuevo") + badges container ── */
    .dl-grid .item-image-container {
        --banner-offset: 6px;
        --banner-padding-block: 0.3em;
        --banner-padding-inline: 0.8em;
        --banner-radius: 6px;
        --banner-font-weight: 900;
        --banner-font-style: italic;
        --banner-font-size: clamp(0.8rem, 0.7rem + 0.5vw, 0.95rem);
        --banner-new-bg: #f7ff19;
        --banner-new-text: #000000;
        --banner-default-bg: rgb(28 28 30 / 0.85);
        --banner-default-text: #FFFFFF;
    }

    .dl-grid [data-banner-type] {
        position: absolute;
        inset-block-start: var(--banner-offset);
        inset-inline-start: var(--banner-offset);
        z-index: 10;
        contain: layout paint style;
        padding-block: var(--banner-padding-block);
        padding-inline: var(--banner-padding-inline);
        border-radius: var(--banner-radius);
        border: none;
        font: var(--banner-font-style) var(--banner-font-weight) var(--banner-font-size) / 1.3 sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        white-space: nowrap;
        text-align: center;
        text-shadow: 0 1px 3px rgb(0 0 0 / 0.5);
    }

    .dl-grid [data-banner-type="new"] {
        background-color: var(--banner-new-bg);
        color: var(--banner-new-text);
        text-shadow: none;
    }

    .dl-grid [data-banner-type="default"] {
        background-color: var(--banner-default-bg);
        color: var(--banner-default-text);
        -webkit-backdrop-filter: blur(5px);
        backdrop-filter: blur(5px);
    }

    .dl-grid .card-badges-container {
        position: absolute;
        top: 6px;
        left: 6px;
        z-index: 10;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .dl-grid .card-badges-container > [data-banner-type] {
        position: static;
        top: auto;
        left: auto;
    }

    /* ── Info overlay ── */
    .dl-grid .card-info-overlay {
        position: absolute;
        inset-inline: 0;
        bottom: 0;
        z-index: 2;
        font-family: var(--font-heading-now-medium);
        font-variation-settings: var(--font-heading-now-medium--font-variation-settings);
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        padding: 0.5rem;
        padding-top: 0;
        pointer-events: none;
        border-radius: 0 0 7px 7px;
        background: linear-gradient(to top,
                rgba(var(--card-overlay-rgb), 0.92) 0%,
                rgba(var(--card-overlay-rgb), 0.55) 60%,
                rgba(var(--card-overlay-rgb), 0) 100%);
    }

    @media (min-width: 1024px) {
        .dl-grid .card-info-overlay {
            padding: 1rem;
            padding-top: 0;
        }
    }

    .dl-grid .card-info-overlay .leaving-today-pill {
        display: flex;
        align-items: center;
        width: max-content;
        max-width: 100%;
        overflow: hidden;
        white-space: nowrap;
        padding: 0.375rem 0.75rem;
        margin-bottom: 0.25rem;
        border-radius: 0.125rem;
        background-color: #000000;
        color: #f43f5e;
        font-family: var(--font-heading-now-heavy);
        font-variation-settings: var(--font-heading-now-heavy--font-variation-settings);
        font-size: 0.9375rem;
        line-height: 1.25;
        text-transform: uppercase;
        transform: skewX(-12deg);
    }

    @media (min-width: 1024px) {
        .dl-grid .card-info-overlay .leaving-today-pill {
            font-size: 1.09375rem;
        }
    }

    .dl-grid .card-info-overlay .card-title {
        color: #ffffff;
        font-weight: 400;
        font-size: 1rem;
        line-height: 1.5rem;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    @media (min-width: 1024px) {
        .dl-grid .card-info-overlay .card-title {
            font-size: 1.1875rem;
            white-space: normal;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }
    }

    .dl-grid .card-footer-pricing {
        min-width: 0;
        overflow: hidden;
        margin-top: 0;
        container-type: inline-size;
        container-name: price-shop;
    }

    .dl-grid .card-footer-pricing .vbucks-price,
    .dl-grid .card-footer-pricing .price-block {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
    }

    @media (min-width: 640px) {
        .dl-grid .card-info-overlay .card-footer-pricing {
            margin-top: 0.25rem;
        }
    }

    .dl-grid .card-info-overlay .vbucks-block {
        color: #ffffff;
        font-size: 1rem;
        line-height: 1;
    }

    @media (min-width: 768px) {
        .dl-grid .card-info-overlay .vbucks-block {
            font-size: 1.1875rem;
        }
    }

    .dl-grid .card-info-overlay .vbucks-block .vbucks-icon {
        width: 1.5rem;
        height: 1.5rem;
        margin-right: 0.25rem;
    }

    .dl-grid .price-block {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }

    .dl-grid .price-block:not(:has(.line-through)) {
        flex-direction: row;
        align-items: center;
    }

    .dl-grid .price-container {
        min-width: 0;
        flex-wrap: wrap;
        display: flex;
        align-items: baseline;
        gap: 0.5rem;
    }

    /* Narrow pricing rows drop the struck base price, like the shop */
    @container price-shop (max-width: 170px) {
        .base-price {
            display: none;
        }
    }

    .dl-grid .card-info-overlay .price-block .price-container > span {
        font-size: 1rem;
        line-height: 1;
        font-weight: 400;
    }

    @media (min-width: 768px) {
        .dl-grid .card-info-overlay .price-block .price-container > span {
            font-size: 1.1875rem;
        }
    }

    /* Official strikethrough: white @93%, px-1, 3px white bar rotated -7° */
    .dl-grid .card-info-overlay .price-block .base-price {
        position: relative;
        width: max-content;
        padding-inline: 0.25rem;
        color: #ffffff;
        opacity: 0.93;
        text-decoration-color: transparent;
        text-decoration-line: none;
    }

    .dl-grid .card-info-overlay .price-block .base-price::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 100%;
        height: 3px;
        background-color: #ffffff;
        opacity: 0.8;
        transform: translate(-50%, -50%) rotate(-7deg);
    }

    .dl-grid .text-green-500 { color: #00f56c !important; }
    .dl-grid .text-price-negative { color: #cc2f2f; }
</style>

<!-- Sprite del icono de paVos (mismo símbolo que la tienda principal) -->
<svg xmlns="http://www.w3.org/2000/svg" style="display:none;" aria-hidden="true">
    <symbol id="icon-pavos" viewBox="0 0 24 24" aria-hidden="true">
        <path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor"
            d="M12 22.5C17.799 22.5 22.5 17.799 22.5 12C22.5 6.20101 17.799 1.5 12 1.5C6.20101 1.5 1.5 6.20101 1.5 12C1.5 17.799 6.20101 22.5 12 22.5ZM10.1051 7.5H6L9.31376 16.5H14.6306L18 7.5H13.8887L12.0896 13.7038L12.0031 14.0427H11.9784L11.9042 13.7038L10.1051 7.5Z">
        </path>
    </symbol>
</svg>

<div class="space-y-6">
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold"><i class="fas fa-layer-group" style="color: var(--accent);"></i> Gestor de Capa de Datos</h1>
            <p style="color: var(--text-secondary);">Control, resiliencia y depuración profunda de la tienda (<code>banano_primordial.json</code>).</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <span id="dl-staged-summary" class="text-sm" style="color: var(--text-secondary); display:none;"></span>
            <button id="dl-discard-draft" type="button" class="dl-btn bg-red-700 hover:bg-red-800 text-white" disabled style="display:none;"><i class="fas fa-trash-can"></i> Descartar borrador</button>
            <button id="dl-apply-draft" type="button" class="dl-btn bg-green-600 hover:bg-green-700 text-white" disabled style="display:none;"><i class="fas fa-check-double"></i> Aplicar cambios</button>
            <button id="dl-refresh-health" class="dl-btn bg-gray-600 hover:bg-gray-700 text-white"><i class="fas fa-sync"></i> Refrescar estado</button>
            <div class="dl-purge-group" style="display:inline-flex; gap:.35rem; margin-left:.25rem;">
                <button id="dl-purge-overrides" type="button" class="dl-btn dl-purge-btn" title="Eliminar todos los overrides de campo persistidos"><i class="fas fa-eraser"></i> Borrar overrides</button>
                <button id="dl-purge-entries" type="button" class="dl-btn dl-purge-btn" title="Eliminar todas las entradas manuales (objetos y secciones creados desde cero)"><i class="fas fa-broom"></i> Borrar manuales</button>
                <button id="dl-purge-all" type="button" class="dl-btn dl-purge-btn dl-purge-btn-all" title="Eliminar TODO: overrides + entradas manuales"><i class="fas fa-skull-crossbones"></i> Borrar todo</button>
            </div>
        </div>
    </header>

    <!-- Tabs -->
    <nav class="flex gap-2 overflow-x-auto pb-1 border-b" style="border-color: var(--border-color);">
        <div class="dl-tab active" data-tab="control"><i class="fas fa-sliders-h"></i> Control Maestro</div>
        <div class="dl-tab" data-tab="editor"><i class="fas fa-pen-ruler"></i> Editor Visual</div>
        <div class="dl-tab" data-tab="fieldmap"><i class="fas fa-map-signs"></i> Mapa de Campos API</div>
        <div class="dl-tab" data-tab="preview"><i class="fas fa-eye"></i> Vista Previa</div>
        <div class="dl-tab" data-tab="logs"><i class="fas fa-clipboard-list"></i> Observabilidad</div>
    </nav>

    <!-- =================== CONTROL MAESTRO =================== -->
    <section id="panel-control" class="dl-panel active space-y-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4" id="dl-metrics">
            <div class="dl-metric"><div class="text-xs uppercase" style="color: var(--text-secondary);">Modo actual</div><div class="v" id="m-mode">—</div></div>
            <div class="dl-metric"><div class="text-xs uppercase" style="color: var(--text-secondary);">Fuente activa</div><div class="v" id="m-active">—</div></div>
            <div class="dl-metric"><div class="text-xs uppercase" style="color: var(--text-secondary);">Ítems (Live)</div><div class="v" id="m-live-items">—</div></div>
            <div class="dl-metric"><div class="text-xs uppercase" style="color: var(--text-secondary);">Edad snapshot Live</div><div class="v" id="m-live-age">—</div></div>
        </div>

        <div class="card rounded-xl p-6 space-y-4">
            <h2 class="text-xl font-semibold">Modo de la tienda</h2>
            <p style="color: var(--text-secondary);" class="text-sm">El modo decide qué snapshot sirve la DAL a toda la tienda. <b>Auto</b> usa Live mientras esté fresco y cae a Fallback si no.</p>
            <div class="flex flex-wrap gap-3" id="dl-mode-buttons">
                <button class="dl-btn bg-blue-600 hover:bg-blue-700 text-white" data-mode="auto"><i class="fas fa-robot"></i> Auto</button>
                <button class="dl-btn bg-green-600 hover:bg-green-700 text-white" data-mode="live"><i class="fas fa-bolt"></i> Live</button>
                <button class="dl-btn bg-yellow-500 hover:bg-yellow-600 text-black" data-mode="fallback"><i class="fas fa-life-ring"></i> Fallback</button>
                <button class="dl-btn bg-red-600 hover:bg-red-700 text-white" data-mode="maintenance"><i class="fas fa-screwdriver-wrench"></i> Mantenimiento</button>
            </div>
        </div>

        <div class="grid md:grid-cols-2 gap-4">
            <div class="card rounded-xl p-6 space-y-3">
                <h2 class="text-xl font-semibold">Snapshot Live</h2>
                <div class="text-sm space-y-1" id="dl-live-detail" style="color: var(--text-secondary);">—</div>
                <button id="dl-force-refresh" class="dl-btn bg-purple-600 hover:bg-purple-700 text-white"><i class="fas fa-cloud-arrow-down"></i> Forzar actualización del caché</button>
            </div>
            <div class="card rounded-xl p-6 space-y-3">
                <h2 class="text-xl font-semibold">Snapshot Fallback (respaldo diario)</h2>
                <div class="text-sm space-y-1" id="dl-fallback-detail" style="color: var(--text-secondary);">—</div>
                <p class="text-xs" style="color: var(--text-secondary);">Se congela automáticamente la primera corrida tras las 00:02 UTC.</p>
            </div>
        </div>

        <div class="card rounded-xl p-6">
            <h2 class="text-xl font-semibold mb-3">Resumen de anomalías</h2>
            <div class="flex flex-wrap gap-6" id="dl-log-summary"><span style="color: var(--text-secondary);">—</span></div>
        </div>
    </section>

    <!-- =================== EDITOR VISUAL =================== -->
    <section id="panel-editor" class="dl-panel space-y-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <p style="color: var(--text-secondary);">Clic en un producto para editar su data (overrides). Los cambios se compilan a <code>item_overrides.json</code>.</p>
            <div class="flex items-center gap-2">
                <label class="text-sm" style="color: var(--text-secondary);">Fuente:</label>
                <select id="dl-editor-src" class="dl-select" style="width:auto;"><option value="active">Activa</option><option value="live">Live</option><option value="fallback">Fallback</option></select>
                <input id="dl-editor-search" class="dl-input" style="width:220px;" placeholder="Buscar por nombre / offerId">
                <button id="dl-new-section" type="button" class="dl-btn bg-green-600 hover:bg-green-700 text-white"><i class="fas fa-plus"></i> Nueva sección</button>
                <button id="dl-organize-toggle" type="button" class="dl-btn bg-gray-600 hover:bg-gray-700 text-white" aria-pressed="false"><i class="fas fa-up-down-left-right"></i> Organizar</button>
            </div>
        </div>
        <div id="dl-editor-grid"><p style="color: var(--text-secondary);">Cargando…</p></div>
    </section>

    <!-- =================== MAPA DE CAMPOS =================== -->
    <section id="panel-fieldmap" class="dl-panel space-y-4">
        <div class="card rounded-xl p-6">
            <h2 class="text-xl font-semibold mb-2">Re-mapeo de campos de la API</h2>
            <p class="text-sm mb-4" style="color: var(--text-secondary);">Si la API externa renombra un campo (ej. <code>date</code> → <code>out</code>), añade una regla <code>origen → canónico</code> y el Ingestor lo sanará en la próxima generación, sin tocar código.</p>
            <div class="grid md:grid-cols-5 gap-3 items-end">
                <div><label class="text-xs">Ámbito</label><select id="fm-scope" class="dl-select"><option value="data">data (raíz)</option><option value="entry">entry (por ítem)</option></select></div>
                <div><label class="text-xs">Campo origen (nuevo)</label><input id="fm-source" class="dl-input" placeholder="out"></div>
                <div><label class="text-xs">Campo canónico (esperado)</label><input id="fm-canonical" class="dl-input" placeholder="date"></div>
                <div><label class="text-xs">Nota</label><input id="fm-nota" class="dl-input" placeholder="opcional"></div>
                <button id="fm-save" class="dl-btn bg-green-600 hover:bg-green-700 text-white justify-center"><i class="fas fa-plus"></i> Añadir/Actualizar</button>
            </div>
            <input type="hidden" id="fm-id" value="0">
        </div>
        <div class="card rounded-xl p-4 overflow-x-auto">
            <table class="min-w-full"><thead><tr>
                <th class="dl-th">Ámbito</th><th class="dl-th">Origen</th><th class="dl-th">Canónico</th><th class="dl-th">Activo</th><th class="dl-th">Nota</th><th class="dl-th text-right">Acciones</th>
            </tr></thead><tbody id="fm-tbody"></tbody></table>
        </div>
    </section>

    <!-- =================== VISTA PREVIA =================== -->
    <section id="panel-preview" class="dl-panel space-y-4">
        <div class="flex items-center gap-3 flex-wrap">
            <p style="color: var(--text-secondary);">Previsualiza cómo se vería la tienda con cada snapshot SIN forzarlo en producción.</p>
            <div class="flex items-center gap-2 ml-auto">
                <label class="text-sm" style="color: var(--text-secondary);">Snapshot:</label>
                <select id="dl-preview-src" class="dl-select" style="width:auto;"><option value="live">Live</option><option value="fallback">Fallback</option></select>
                <span id="dl-preview-count" class="text-sm" style="color: var(--text-secondary);"></span>
            </div>
        </div>
        <div id="dl-preview-grid"><p style="color: var(--text-secondary);">Selecciona un snapshot…</p></div>
    </section>

    <!-- =================== OBSERVABILIDAD =================== -->
    <section id="panel-logs" class="dl-panel space-y-4">
        <div class="flex items-center gap-3 flex-wrap">
            <select id="dl-log-sev" class="dl-select" style="width:auto;"><option value="">Todas</option><option value="info">Info</option><option value="warning">Warning</option><option value="error">Error</option><option value="critical">Critical</option></select>
            <button id="dl-log-refresh" class="dl-btn bg-gray-600 hover:bg-gray-700 text-white"><i class="fas fa-sync"></i> Refrescar</button>
            <button id="dl-log-clear" class="dl-btn bg-red-600 hover:bg-red-700 text-white ml-auto"><i class="fas fa-trash"></i> Limpiar registro</button>
        </div>
        <div class="card rounded-xl p-4 overflow-x-auto">
            <table class="min-w-full"><thead><tr>
                <th class="dl-th">Fecha (UTC)</th><th class="dl-th">Severidad</th><th class="dl-th">Tipo</th><th class="dl-th">Mensaje</th><th class="dl-th">Contexto</th>
            </tr></thead><tbody id="dl-log-tbody"></tbody></table>
        </div>
    </section>
</div>

<!-- ===== Modal: Editor de ítem (rediseño DLM, estilos de la tienda) ===== -->
<div id="dl-item-modal" class="dl-modal">
    <div class="dlm" role="dialog" aria-modal="true" aria-labelledby="dl-modal-title">
        <header class="dlm-header">
            <h3 class="dlm-title" id="dl-modal-title">Editar ítem</h3>
            <button type="button" class="dl-modal-close dlm-close" aria-label="Cerrar">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 3l10 10M13 3L3 13"/></svg>
            </button>
        </header>
        <div class="dlm-body">
            <aside class="dlm-aside">
                <div class="dlm-preview" id="dl-modal-preview">
                    <div id="dl-modal-banner" data-banner-type="new" aria-hidden="true" style="display:none;"></div>
                    <img id="dl-modal-img" src="" alt="Vista previa del ítem">
                </div>
                <details class="dlm-section" open>
                    <summary>Imagen</summary>
                    <div class="dlm-section-body">
                        <div class="dlm-field">
                            <label class="dlm-label" for="f-img-slot" data-ov="images">Imagen a editar</label>
                            <select id="f-img-slot" class="dlm-select-native"></select>
                        </div>
                        <div class="dlm-field">
                            <label class="dlm-label" for="f-img-url">URL de la imagen</label>
                            <input id="f-img-url" type="text" placeholder="https://…" autocomplete="off">
                            <div class="dl-upload-row">
                                <button id="f-img-upload-btn" type="button" class="dl-upload-btn"><span class="dl-upload-spinner" aria-hidden="true"></span>Subir imagen…</button>
                                <input id="f-img-upload-file" type="file" accept=".png,.webp,.jpg,.jpeg,image/png,image/webp,image/jpeg" hidden aria-label="Seleccionar imagen local para esta ranura">
                                <div id="f-img-upload-bar" class="dl-upload-progress" aria-hidden="true"><span></span></div>
                            </div>
                            <p id="f-img-upload-msg" class="dl-upload-msg" role="alert" aria-live="polite"></p>
                            <p id="f-img-hint" class="dlm-hint"></p>
                        </div>
                    </div>
                </details>
                <details class="dlm-section">
                    <summary>Avanzado</summary>
                    <div class="dlm-section-body">
                        <div class="dlm-field">
                            <label class="dlm-label" for="f-offerId" data-ov="offerId">offerId</label>
                            <div class="dlm-offerid-row">
                                <input id="f-offerId" type="text" autocomplete="off" spellcheck="false">
                                <button id="dl-gen-offerid" type="button" class="dlm-icon-btn" title="Generar offerId aleatorio" aria-label="Generar offerId aleatorio">
                                    <svg viewBox="282 215 716 819" aria-hidden="true"><defs><rect id="dl-dice-f" width="384" height="384" rx="30"/><circle id="dl-dice-d" r="46"/><mask id="dl-dice-m1" fill="#000"><use href="#dl-dice-f" fill="#fff"/><use href="#dl-dice-d" x="192" y="192"/></mask><mask id="dl-dice-m3" fill="#000"><use href="#dl-dice-f" fill="#fff"/><use href="#dl-dice-d" x="192" y="192"/><use href="#dl-dice-d" x="92" y="292"/><use href="#dl-dice-d" x="292" y="92"/></mask><mask id="dl-dice-m5" fill="#000"><use href="#dl-dice-f" fill="#fff"/><use href="#dl-dice-d" x="192" y="192"/><use href="#dl-dice-d" x="92" y="92"/><use href="#dl-dice-d" x="292" y="92"/><use href="#dl-dice-d" x="92" y="292"/><use href="#dl-dice-d" x="292" y="292"/></mask></defs><g><use href="#dl-dice-f" mask="url(#dl-dice-m1)" transform="matrix(.904-.522-.904-.522 640 616)"/><use href="#dl-dice-f" mask="url(#dl-dice-m5)" transform="matrix(-.868-.501 0 1 616 650)"/><use href="#dl-dice-f" mask="url(#dl-dice-m3)" transform="matrix(.868-.501 0 1 664 650)"/></g></svg>
                                </button>
                            </div>
                            <p class="dlm-hint break">Original: <span id="dl-modal-offerid"></span></p>
                        </div>
                    </div>
                </details>
            </aside>
            <main class="dlm-main">
                <details class="dlm-section" open>
                    <summary>Identidad</summary>
                    <div class="dlm-section-body">
                        <div class="dlm-field">
                            <label class="dlm-label" for="f-displayName" data-ov="displayName">Nombre (displayName)</label>
                            <input id="f-displayName" type="text">
                        </div>
                        <div class="dlm-field">
                            <label class="dlm-label" for="f-displayType-select" data-ov="displayType">Tipo (displayType)</label>
                            <select id="f-displayType-select" class="dlm-select-native"></select>
                            <input id="f-displayType" type="text" style="display:none;" placeholder="Texto libre" aria-label="Tipo (texto libre)">
                        </div>
                        <div class="dlm-field">
                            <label class="dlm-label" for="f-description" data-ov="description">Descripción</label>
                            <textarea id="f-description" rows="2"></textarea>
                        </div>
                    </div>
                </details>
                <details class="dlm-section" open>
                    <summary>Comercial</summary>
                    <div class="dlm-section-body">
                        <div class="dlm-grid-2">
                            <div class="dlm-field">
                                <label class="dlm-label" for="f-precio_base_soles" data-ov="precio_base_soles">Precio base (soles)</label>
                                <input id="f-precio_base_soles" type="number" step="0.01">
                            </div>
                            <div class="dlm-field">
                                <label class="dlm-label" for="f-vbucks" data-ov="vbucks">Precio V-Bucks</label>
                                <input id="f-vbucks" type="number" step="1" placeholder="1500">
                            </div>
                        </div>
                        <div class="dlm-field">
                            <label class="dlm-label" for="f-rarity-select" data-ov="rarity">Rareza</label>
                            <select id="f-rarity-select" class="dlm-select-native"></select>
                            <input id="f-rarity" type="text" style="display:none;" placeholder="EPIC" aria-label="Rareza (texto libre)">
                        </div>
                    </div>
                </details>
                <details class="dlm-section" open>
                    <summary>Metadatos y ciclo de vida</summary>
                    <div class="dlm-section-body">
                        <div class="dlm-field">
                            <label class="dlm-label" for="f-set-select" data-ov="set">Conjunto / Set</label>
                            <select id="f-set-select" class="dlm-select-native" aria-label="Conjunto (lista de conjuntos detectados)"></select>
                            <input id="f-set" type="text" placeholder="Nombre del conjunto" autocomplete="off" aria-label="Conjunto (texto libre sincronizado)">
                            <p class="dlm-hint" id="dl-set-hint" aria-live="polite"></p>
                        </div>
                        <div class="dlm-field">
                            <label class="dlm-label" for="f-introduction" data-ov="introduction">Introducido en (capítulo y temporada)</label>
                            <input id="f-introduction" type="text" placeholder="Capítulo 5, Temporada 2" autocomplete="off">
                            <p class="dlm-hint">Texto libre. Vacío = sin dato de introducción.</p>
                        </div>
                        <div class="dlm-field">
                            <span class="dlm-label" id="dl-out-title" data-ov="outDate">Salida de la tienda (rotación diaria, UTC)</span>
                            <div class="dlm-seg" aria-labelledby="dl-out-title">
                                <button type="button" class="dlm-seg-btn" id="dl-out-minus" aria-label="Restar una rotación">−1</button>
                                <button type="button" class="dlm-seg-btn" id="dl-out-plus" aria-label="Sumar una rotación">+1</button>
                                <button type="button" class="dlm-seg-btn" id="dl-out-plus2" aria-label="Sumar dos rotaciones">+2</button>
                                <button type="button" class="dlm-seg-btn" id="dl-out-plus7" aria-label="Sumar siete rotaciones">+7</button>
                                <button type="button" class="dlm-seg-btn" id="dl-out-never" role="switch" aria-checked="false">Sin fecha de salida</button>
                                <button type="button" class="dlm-seg-btn" id="dl-out-manual" role="switch" aria-checked="false" title="Elegir fecha en el calendario" aria-label="Elegir fecha en el calendario"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.6 1.9a1.45 1.45 0 0 1 2.05 0l.45.45a1.45 1.45 0 0 1 0 2.05l-8.3 8.3-3.1.9.9-3.1 8-8.6zM10.4 3.4l2.2 2.2M2.5 14.5h11"/></svg></button>
                            </div>
                            <input id="f-out-date" type="date" style="display:none;" aria-label="Fecha de salida (UTC)">
                            <p class="dlm-hint" id="dl-out-label" role="status" aria-live="polite"></p>
                        </div>
                    </div>
                </details>
                <details class="dlm-section" open>
                    <summary>Tarjeta</summary>
                    <div class="dlm-section-body">
                        <div class="dlm-field">
                            <span class="dlm-label" id="dl-banner-label" data-ov="banner">Banner «NUEVO»</span>
                            <div class="dlm-seg" role="radiogroup" aria-labelledby="dl-banner-label" id="dl-banner-seg">
                                <button type="button" class="dlm-seg-btn" role="radio" aria-checked="false" data-mode="">Automático (API)</button>
                                <button type="button" class="dlm-seg-btn" role="radio" aria-checked="false" data-mode="on">Forzar NUEVO</button>
                                <button type="button" class="dlm-seg-btn" role="radio" aria-checked="false" data-mode="off">Ocultar</button>
                            </div>
                            <p class="dlm-hint" id="dl-banner-api-hint"></p>
                        </div>
                        <div id="dl-banner-detail">
                            <div class="dlm-field">
                                <label class="dlm-label" for="f-banner-text">Texto del banner</label>
                                <input id="f-banner-text" type="text" placeholder="Nuevo" autocomplete="off">
                            </div>
                            <div class="dlm-field">
                                <span class="dlm-label" id="dl-banner-exp-title">Caducidad (rotación diaria de la tienda, UTC)</span>
                                <div class="dlm-seg" aria-labelledby="dl-banner-exp-title">
                                    <button type="button" class="dlm-seg-btn" id="dl-banner-exp-minus" aria-label="Restar una rotación">−1 día</button>
                                    <button type="button" class="dlm-seg-btn" id="dl-banner-exp-plus" aria-label="Sumar una rotación">+1 día</button>
                                    <button type="button" class="dlm-seg-btn" id="dl-banner-exp-plus7" aria-label="Sumar siete rotaciones">+7 días</button>
                                    <button type="button" class="dlm-seg-btn" id="dl-banner-exp-never" role="switch" aria-checked="false">Sin caducidad</button>
                                    <button type="button" class="dlm-seg-btn" id="dl-banner-exp-manual" role="switch" aria-checked="false" title="Escribir fecha manualmente" aria-label="Escribir fecha manualmente"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.6 1.9a1.45 1.45 0 0 1 2.05 0l.45.45a1.45 1.45 0 0 1 0 2.05l-8.3 8.3-3.1.9.9-3.1 8-8.6zM10.4 3.4l2.2 2.2M2.5 14.5h11"/></svg></button>
                                </div>
                                <input id="f-banner-exp" type="date" style="display:none;" aria-label="Fecha de caducidad (UTC)">
                                <p class="dlm-hint" id="dl-banner-exp-label"></p>
                            </div>
                        </div>
                        <div class="dlm-field">
                            <span class="dlm-label" id="dl-tile-label" data-ov="tileSize">Ancho de tarjeta (tileSize)</span>
                            <div class="dlm-seg" role="radiogroup" aria-labelledby="dl-tile-label" id="dl-tile-seg">
                                <button type="button" class="dlm-seg-btn" role="radio" aria-checked="false" data-span="1">1</button>
                                <button type="button" class="dlm-seg-btn" role="radio" aria-checked="false" data-span="2">2</button>
                                <button type="button" class="dlm-seg-btn" role="radio" aria-checked="false" data-span="3">3</button>
                                <button type="button" class="dlm-seg-btn" role="radio" aria-checked="false" data-span="4">4</button>
                            </div>
                            <p class="dlm-hint" id="dl-tile-hint">Columnas que ocupa la tarjeta en la parrilla de la tienda (1–4).</p>
                        </div>
                    </div>
                </details>
                <details class="dlm-section" id="dl-diff-wrap" style="display:none;" open>
                    <summary>Dificultad (Pista de Improvisación)&nbsp;<span data-ov="difficulty"></span></summary>
                    <div class="dlm-section-body">
                        <div id="dl-diff-grid" class="dl-diff-grid"></div>
                        <p class="dlm-hint">Clic en una barra para fijar el nivel (1–7). Clic en el número para ocultar el instrumento (0).</p>
                    </div>
                </details>
                <details class="dlm-section" open>
                    <summary>Visual</summary>
                    <div class="dlm-section-body">
                        <div class="dlm-grid-3">
                            <div class="dlm-field">
                                <label class="dlm-label" for="f-color1" data-ov="colors">Color 1</label>
                                <input id="f-color1" type="text" placeholder="RRGGBBAA">
                            </div>
                            <div class="dlm-field">
                                <label class="dlm-label" for="f-color2">Color 2</label>
                                <input id="f-color2" type="text" placeholder="RRGGBBAA">
                            </div>
                            <div class="dlm-field">
                                <label class="dlm-label" for="f-color3">Color 3</label>
                                <input id="f-color3" type="text" placeholder="RRGGBBAA">
                            </div>
                        </div>
                        <div id="dl-styles-wrap" style="display:none;">
                            <div class="dlm-field">
                                <span class="dlm-label" data-ov="styleRenderMap">Estilos → imagen grande (itemPageImage)</span>
                                <div id="dl-styles-rows" class="space-y-2"></div>
                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                    <p class="dlm-hint">Clic en la miniatura que corresponde a cada estilo.</p>
                                    <button id="dl-styles-reset" type="button" class="dlm-btn dlm-btn-danger" style="padding:.3rem .7rem; font-size:.65rem;">Quitar override de estilos</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </details>
                <p class="dlm-hint">Solo se guardan como override los campos que cambies respecto a lo mostrado.</p>
            </main>
        </div>
        <div class="dl-defer-section" id="dl-defer-section" style="display:none; margin: 0 1.25rem .5rem;">
            <label class="dl-defer-toggle">
                <input type="checkbox" id="dl-defer-check">
                <span>🔗 Diferir a API si coincide</span>
            </label>
            <div class="dl-defer-opts" id="dl-defer-opts">
                <div class="dl-defer-opt-row">
                    <label for="dl-defer-min">Coincidencias mín.</label>
                    <input id="dl-defer-min" type="number" value="1" min="1" max="15" step="1">
                </div>
                <div class="dl-defer-opt-row">
                    <input type="checkbox" id="dl-defer-video" checked>
                    <label for="dl-defer-video" style="min-width:0">Heredar videos de pistas de improvisación</label>
                </div>
                <div class="dl-defer-opt-row">
                    <input type="checkbox" id="dl-defer-backdrop" checked>
                    <label for="dl-defer-backdrop" style="min-width:0">Heredar fondo de sección de la API</label>
                </div>
                <button type="button" id="dl-defer-check-btn" class="dlm-btn dlm-btn-ghost" style="margin-top:.5rem; font-size:.7rem;">🔍 Buscar coincidencia ahora</button>
                <div id="dl-defer-result" class="dl-defer-result" style="display:none"></div>
            </div>
        </div>
        <footer class="dlm-footer">
            <button id="dl-delete-overrides" type="button" class="dlm-btn dlm-btn-danger">Quitar todos los overrides</button>
            <span id="dl-dirty-count" class="dlm-dirty">Sin cambios</span>
            <button type="button" class="dl-modal-close dlm-btn dlm-btn-ghost">Cancelar</button>
            <button id="dl-save-next" type="button" class="dlm-btn dlm-btn-ghost" disabled>Guardar y siguiente</button>
            <button id="dl-save-item" type="button" class="dlm-btn dlm-btn-primary" disabled>Guardar overrides</button>
        </footer>
    </div>
</div>

<!-- ===== Modal: Log de salida (force refresh) ===== -->
<div id="dl-log-modal" class="dl-modal">
    <div class="card rounded-xl shadow-xl max-w-3xl w-full mx-4">
        <div class="flex justify-between items-center border-b p-4" style="border-color: var(--border-color);">
            <h3 class="text-lg font-bold">Salida del Ingestor</h3><button class="dl-modal-close text-2xl">&times;</button>
        </div>
        <div class="p-4"><pre id="dl-log-output" class="dl-log-pre">…</pre></div>
    </div>
</div>

<!-- ===== Modal: Nueva sección ===== -->
<div id="dl-section-modal" class="dl-modal">
    <div class="dlm dl-mini-modal" role="dialog" aria-modal="true" aria-labelledby="dl-section-modal-title">
        <header class="dlm-header">
            <h3 class="dlm-title" id="dl-section-modal-title">Nueva sección</h3>
            <button type="button" class="dl-modal-close dlm-close" aria-label="Cerrar">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 3l10 10M13 3L3 13"/></svg>
            </button>
        </header>
        <div class="dlm-body">
            <label for="ns-name">Nombre visible de la sección</label>
            <input id="ns-name" class="dl-input" placeholder="Ej. Destacados de la comunidad" maxlength="80">
            <label for="ns-position">Posición en la tienda</label>
            <select id="ns-position" class="dl-select"></select>
            <div class="dl-defer-section" id="ns-defer-section">
                <label class="dl-defer-toggle">
                    <input type="checkbox" id="ns-defer-check">
                    <span>🔗 Diferir a API si coincide</span>
                </label>
                <div class="dl-defer-opts" id="ns-defer-opts">
                    <div class="dl-defer-opt-row">
                        <label for="ns-defer-min">Coincidencias mín.</label>
                        <input id="ns-defer-min" type="number" value="1" min="1" max="15" step="1">
                    </div>
                    <div class="dl-defer-opt-row">
                        <input type="checkbox" id="ns-defer-backdrop" checked>
                        <label for="ns-defer-backdrop" style="min-width:0">Heredar fondo de sección de la API</label>
                    </div>
                    <p class="dlm-hint">Si la sección creada coincide con una sección de la API (nombre, objetos, fondos…), se usarán los datos de la API automáticamente y la sección manual desaparecerá.</p>
                </div>
            </div>
            <p class="text-xs" style="color:var(--text-secondary); margin-top:.75rem;">La sección queda en borrador dentro del editor. Solo se publica en la tienda cuando contiene objetos y aplicas los cambios.</p>
        </div>
        <footer class="dlm-footer">
            <button type="button" class="dl-modal-close dlm-btn dlm-btn-ghost">Cancelar</button>
            <button id="ns-create" type="button" class="dlm-btn dlm-btn-primary">Crear sección</button>
        </footer>
    </div>
</div>

<!-- ===== Modal: Nuevo objeto (plantillas) ===== -->
<div id="dl-create-modal" class="dl-modal">
    <div class="dlm dl-mini-modal" role="dialog" aria-modal="true" aria-labelledby="dl-create-modal-title">
        <header class="dlm-header">
            <h3 class="dlm-title" id="dl-create-modal-title">Nuevo objeto</h3>
            <button type="button" class="dl-modal-close dlm-close" aria-label="Cerrar">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 3l10 10M13 3L3 13"/></svg>
            </button>
        </header>
        <div class="dlm-body">
            <p class="text-xs" style="color:var(--text-secondary);">Sección destino: <b id="nc-section-name">—</b></p>
            <label>Plantilla</label>
            <div class="dl-tpl-grid" id="nc-templates" role="radiogroup" aria-label="Plantilla del objeto">
                <button type="button" class="dl-tpl-btn sel" data-tpl="br" role="radio" aria-checked="true"><i class="fas fa-user-astronaut"></i>Cosmético BR</button>
                <button type="button" class="dl-tpl-btn" data-tpl="bundle" role="radio" aria-checked="false"><i class="fas fa-box-open"></i>Lote</button>
                <button type="button" class="dl-tpl-btn" data-tpl="track" role="radio" aria-checked="false"><i class="fas fa-music"></i>Jam Track</button>
                <button type="button" class="dl-tpl-btn" data-tpl="instrument" role="radio" aria-checked="false"><i class="fas fa-guitar"></i>Instrumento</button>
                <button type="button" class="dl-tpl-btn" data-tpl="car" role="radio" aria-checked="false"><i class="fas fa-car"></i>Vehículo</button>
            </div>
            <div id="nc-brtype-row">
                <label for="nc-brtype">Tipo de cosmético</label>
                <select id="nc-brtype" class="dl-select">
                    <option value="outfit">Traje</option><option value="emote">Gesto</option>
                    <option value="pickaxe">Pico</option><option value="glider">Ala delta</option>
                    <option value="backpack">Mochila</option><option value="wrap">Envoltorio</option>
                    <option value="shoes">Calzado</option><option value="spray">Grafiti</option>
                    <option value="music">Música</option><option value="loadingscreen">Pantalla de carga</option>
                </select>
            </div>
            <label for="nc-name">Nombre</label>
            <input id="nc-name" class="dl-input" placeholder="Nombre del objeto" maxlength="120">
            <label for="nc-vbucks">Precio (paVos)</label>
            <input id="nc-vbucks" class="dl-input" inputmode="numeric" pattern="[0-9]*" placeholder="Ej. 1500">
            <label for="nc-offerId">offerId</label>
            <div class="dlm-offerid-row">
                <input id="nc-offerId" class="dl-input" autocomplete="off" spellcheck="false">
                <button id="nc-gen-offerid" type="button" class="dlm-icon-btn" title="Generar offerId aleatorio" aria-label="Generar offerId aleatorio">
                    <svg viewBox="282 215 716 819" aria-hidden="true"><defs><rect id="nc-dice-f" width="384" height="384" rx="30"/><circle id="nc-dice-d" r="46"/><mask id="nc-dice-m1" fill="#000"><use href="#nc-dice-f" fill="#fff"/><use href="#nc-dice-d" x="192" y="192"/></mask><mask id="nc-dice-m3" fill="#000"><use href="#nc-dice-f" fill="#fff"/><use href="#nc-dice-d" x="192" y="192"/><use href="#nc-dice-d" x="92" y="292"/><use href="#nc-dice-d" x="292" y="92"/></mask><mask id="nc-dice-m5" fill="#000"><use href="#nc-dice-f" fill="#fff"/><use href="#nc-dice-d" x="192" y="192"/><use href="#nc-dice-d" x="92" y="92"/><use href="#nc-dice-d" x="292" y="92"/><use href="#nc-dice-d" x="92" y="292"/><use href="#nc-dice-d" x="292" y="292"/></mask></defs><g><use href="#nc-dice-f" mask="url(#nc-dice-m1)" transform="matrix(.904-.522-.904-.522 640 616)"/><use href="#nc-dice-f" mask="url(#nc-dice-m5)" transform="matrix(-.868-.501 0 1 616 650)"/><use href="#nc-dice-f" mask="url(#nc-dice-m3)" transform="matrix(.868-.501 0 1 664 650)"/></g></svg>
                </button>
            </div>
            <label for="nc-rarity-select">Rareza</label>
            <select id="nc-rarity-select" class="dl-select"></select>
            <input id="nc-rarity" class="dl-input" type="text" style="display:none; margin-top:.35rem;" placeholder="EPIC" aria-label="Rareza (texto libre)">
            <label id="nc-colors-label">Colores del degradado (RRGGBBAA)</label>
            <div class="dlm-grid-3" role="group" aria-labelledby="nc-colors-label">
                <input id="nc-color1" class="dl-input" type="text" placeholder="Color 1" aria-label="Color 1" autocomplete="off">
                <input id="nc-color2" class="dl-input" type="text" placeholder="Color 2" aria-label="Color 2" autocomplete="off">
                <input id="nc-color3" class="dl-input" type="text" placeholder="Color 3" aria-label="Color 3" autocomplete="off">
            </div>
            <div id="nc-grad-preview" aria-hidden="true"></div>
            <label id="nc-banner-label">Banner «NUEVO»</label>
            <div class="dlm-seg" role="radiogroup" aria-labelledby="nc-banner-label" id="nc-banner-seg">
                <button type="button" class="dlm-seg-btn" role="radio" aria-checked="false" data-mode="">Automático (API)</button>
                <button type="button" class="dlm-seg-btn" role="radio" aria-checked="false" data-mode="on">Forzar NUEVO</button>
                <button type="button" class="dlm-seg-btn" role="radio" aria-checked="false" data-mode="off">Ocultar</button>
            </div>
            <div id="nc-banner-detail">
                <div>
                    <label for="nc-banner-text">Texto del banner</label>
                    <input id="nc-banner-text" class="dl-input" type="text" placeholder="Nuevo" autocomplete="off">
                </div>
                <div>
                    <span class="dlm-label" id="nc-banner-exp-title">Caducidad (rotación diaria de la tienda, UTC)</span>
                    <div class="dlm-seg" aria-labelledby="nc-banner-exp-title">
                        <button type="button" class="dlm-seg-btn" id="nc-banner-exp-minus" aria-label="Restar una rotación">−1 día</button>
                        <button type="button" class="dlm-seg-btn" id="nc-banner-exp-plus" aria-label="Sumar una rotación">+1 día</button>
                        <button type="button" class="dlm-seg-btn" id="nc-banner-exp-plus7" aria-label="Sumar siete rotaciones">+7 días</button>
                        <button type="button" class="dlm-seg-btn" id="nc-banner-exp-never" role="switch" aria-checked="false">Sin caducidad</button>
                        <button type="button" class="dlm-seg-btn" id="nc-banner-exp-manual" role="switch" aria-checked="false" title="Escribir fecha manualmente" aria-label="Escribir fecha manualmente"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.6 1.9a1.45 1.45 0 0 1 2.05 0l.45.45a1.45 1.45 0 0 1 0 2.05l-8.3 8.3-3.1.9.9-3.1 8-8.6zM10.4 3.4l2.2 2.2M2.5 14.5h11"/></svg></button>
                    </div>
                    <input id="nc-banner-exp" class="dl-input" type="date" style="display:none; margin-top:.35rem;" aria-label="Fecha de caducidad (UTC)">
                    <p class="dlm-hint" id="nc-banner-exp-label"></p>
                </div>
            </div>
            <label for="nc-set-select">Conjunto / Set</label>
            <select id="nc-set-select" class="dl-select" aria-label="Conjunto (lista de conjuntos detectados)"></select>
            <input id="nc-set" class="dl-input" type="text" style="margin-top:.35rem;" placeholder="Nombre del conjunto" autocomplete="off" aria-label="Conjunto (texto libre sincronizado)">
            <p class="dlm-hint" id="nc-set-hint" aria-live="polite"></p>
            <label for="nc-introduction">Introducido en (capítulo y temporada)</label>
            <input id="nc-introduction" class="dl-input" type="text" placeholder="Capítulo 5, Temporada 2" autocomplete="off">
            <span class="dlm-label" id="nc-out-title">Salida de la tienda (rotación diaria, UTC)</span>
            <div class="dlm-seg" aria-labelledby="nc-out-title">
                <button type="button" class="dlm-seg-btn" id="nc-out-minus" aria-label="Restar una rotación">−1</button>
                <button type="button" class="dlm-seg-btn" id="nc-out-plus" aria-label="Sumar una rotación">+1</button>
                <button type="button" class="dlm-seg-btn" id="nc-out-plus2" aria-label="Sumar dos rotaciones">+2</button>
                <button type="button" class="dlm-seg-btn" id="nc-out-plus7" aria-label="Sumar siete rotaciones">+7</button>
                <button type="button" class="dlm-seg-btn" id="nc-out-never" role="switch" aria-checked="false">Sin fecha de salida</button>
                <button type="button" class="dlm-seg-btn" id="nc-out-manual" role="switch" aria-checked="false" title="Elegir fecha en el calendario" aria-label="Elegir fecha en el calendario"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.6 1.9a1.45 1.45 0 0 1 2.05 0l.45.45a1.45 1.45 0 0 1 0 2.05l-8.3 8.3-3.1.9.9-3.1 8-8.6zM10.4 3.4l2.2 2.2M2.5 14.5h11"/></svg></button>
            </div>
            <input id="nc-out-date" class="dl-input" type="date" style="display:none; margin-top:.35rem;" aria-label="Fecha de salida (UTC)">
            <p class="dlm-hint" id="nc-out-label" role="status" aria-live="polite"></p>
            <label id="nc-images-label">Imágenes por ranura</label>
            <div id="nc-img-slots" role="group" aria-labelledby="nc-images-label"></div>
            <div class="dl-defer-section" id="nc-defer-section">
                <label class="dl-defer-toggle">
                    <input type="checkbox" id="nc-defer-check">
                    <span>🔗 Diferir a API si coincide</span>
                </label>
                <div class="dl-defer-opts" id="nc-defer-opts">
                    <div class="dl-defer-opt-row">
                        <label for="nc-defer-min">Coincidencias mín.</label>
                        <input id="nc-defer-min" type="number" value="1" min="1" max="15" step="1">
                    </div>
                    <div class="dl-defer-opt-row">
                        <input type="checkbox" id="nc-defer-video" checked>
                        <label for="nc-defer-video" style="min-width:0">Heredar videos de pistas de improvisación</label>
                    </div>
                    <div class="dl-defer-opt-row">
                        <input type="checkbox" id="nc-defer-backdrop" checked>
                        <label for="nc-defer-backdrop" style="min-width:0">Heredar fondo de sección de la API</label>
                    </div>
                    <p class="dlm-hint">Si el objeto coincide con uno de la API (nombre, tipo, colores, imagen, rareza, conjunto, precio…), se usará el dato de la API y el objeto manual desaparecerá. La comparación es inteligente: tolera diferencias menores en texto (tildes, mayúsculas, símbolos) e incluso compara la similitud visual de las imágenes.</p>
                    <div id="nc-defer-result" class="dl-defer-result" style="display:none"></div>
                </div>
            </div>
            <p class="text-xs" style="color:var(--text-secondary); margin-top:.75rem;">El objeto queda en el borrador (deshacible con Ctrl+Z). Después puedes seguir ajustándolo desde su modal de edición.</p>
        </div>
        <footer class="dlm-footer">
            <button type="button" class="dl-modal-close dlm-btn dlm-btn-ghost">Cancelar</button>
            <button id="nc-create" type="button" class="dlm-btn dlm-btn-ghost">Crear</button>
            <button id="nc-create-edit" type="button" class="dlm-btn dlm-btn-primary">Crear y editar</button>
        </footer>
    </div>
</div>

<!-- ===== Mini-toolbar contextual del modo Organizar ===== -->
<div id="dl-org-toolbar" role="toolbar" aria-label="Acciones rápidas del objeto">
    <span class="ot-name" id="ot-name">—</span>
    <button type="button" class="ot-tile" data-span="1" title="Ancho 1">1×</button>
    <button type="button" class="ot-tile" data-span="2" title="Ancho 2">2×</button>
    <button type="button" class="ot-tile" data-span="3" title="Ancho 3">3×</button>
    <button type="button" class="ot-tile" data-span="4" title="Ancho 4">4×</button>
    <select id="ot-move" title="Mover a otra sección"></select>
    <button type="button" id="ot-duplicate" title="Duplicar en esta sección"><i class="fas fa-clone"></i></button>
    <button type="button" id="ot-edit" title="Abrir editor completo"><i class="fas fa-pen"></i></button>
    <button type="button" id="ot-delete" class="ot-danger" title="Eliminar entrada manual"><i class="fas fa-trash-can"></i></button>
</div>

<script>
(function () {
    const API = 'data_layer/api_data_layer.php';
    const DL_TYPE_LABELS = {
        outfit: 'Trajes', emote: 'Gestos', glider: 'Alas delta',
        pickaxe: 'Picos', backpack: 'Accesorios mochileros', wrap: 'Envoltorios',
        shoes: 'Calzado', spray: 'Grafitis', music: 'Música',
        loadingscreen: 'Pantallas de carga'
    };

    function toast(msg, type) {
        if (typeof showToast === 'function') return showToast(msg, type || 'info');
        console.log('[' + (type || 'info') + ']', msg);
    }

    async function api(action, data, method) {
        method = method || 'GET';
        let url = API + '?accion=' + encodeURIComponent(action) + '&v=' + Date.now();
        const opts = { method };
        if (method === 'GET' && data) {
            for (const k in data) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
        } else if (method === 'POST') {
            const fd = new FormData();
            fd.append('accion', action);
            for (const k in (data || {})) fd.append(k, data[k]);
            opts.body = fd;
        }
        try {
            const r = await fetch(url, opts);
            const j = await r.json();
            if (j.status !== 'success') { toast(j.message || 'Error', 'error'); }
            return j;
        } catch (e) { toast('Error de red: ' + e.message, 'error'); return { status: 'error' }; }
    }

    const fmtAge = (s) => s == null ? '—' : (s < 60 ? s + 's' : s < 3600 ? Math.floor(s / 60) + 'm' : Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm');
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    // =====================================================================
    //  DRAFT STORE (staging) + HISTORY (undo/redo)
    //  Single source of truth for every pending editor mutation. Nothing the
    //  editor stages touches the API until "Aplicar cambios" flushes the
    //  whole draft atomically via apply_overrides_batch. State shape:
    //    { [anchorOfferId]: { ops: { [field_key]: Op }, deleteAll: bool,
    //                         create: {template, fields}|undefined,
    //                         deleteEntry: bool|undefined },
    //      __sections: [ {id, name, rank} ] }   (draft sections, editor-only)
    //    Op = {op:'upsert', field_value, value_type} | {op:'delete'}
    //  `create` stages a brand-new manual entry (op create_entry) and
    //  `deleteEntry` the removal of a persisted manual entry (op delete_entry).
    //  Mirrored to sessionStorage so the draft survives page reloads.
    // =====================================================================
    const DlDraftStore = (() => {
        const KEY = 'dl_draft_v1';
        let state = (() => {
            try {
                const parsed = JSON.parse(sessionStorage.getItem(KEY) || 'null');
                return (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
            } catch (e) { return {}; }
        })();
        const subs = [];
        function emit() {
            try { sessionStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* quota/priv mode */ }
            subs.forEach(cb => { try { cb(); } catch (e) { console.error(e); } });
        }
        function ensure(anchor) {
            if (!state[anchor]) state[anchor] = { ops: {}, deleteAll: false };
            return state[anchor];
        }
        function prune(anchor) {
            const e = state[anchor];
            if (e && !e.deleteAll && !e.create && !e.deleteEntry && !Object.keys(e.ops).length) delete state[anchor];
        }
        // Anchor keys only (the reserved __sections entry is not an item).
        function anchors() {
            return Object.keys(state).filter(k => k !== '__sections');
        }
        return {
            subscribe(cb) { subs.push(cb); },
            snapshot() { return JSON.parse(JSON.stringify(state)); },
            restore(snap) { state = JSON.parse(JSON.stringify(snap)); emit(); },
            clear() { state = {}; emit(); },
            get(anchor) { return state[anchor] || null; },
            stageUpserts(anchor, jobs) {
                const e = ensure(anchor);
                jobs.forEach(j => { e.ops[j.field_key] = { op: 'upsert', field_value: j.field_value, value_type: j.value_type }; });
                emit();
            },
            stageDelete(anchor, fieldKey) {
                const e = ensure(anchor);
                if (fieldKey) e.ops[fieldKey] = { op: 'delete' };
                else { e.deleteAll = true; e.ops = {}; }
                emit();
            },
            unstage(anchor, fieldKey) {
                const e = state[anchor];
                if (!e) return;
                if (fieldKey) delete e.ops[fieldKey]; else delete state[anchor];
                prune(anchor);
                emit();
            },
            count() {
                let n = 0;
                anchors().forEach(a => {
                    const e = state[a];
                    n += Object.keys(e.ops).length + (e.deleteAll ? 1 : 0) + (e.create ? 1 : 0) + (e.deleteEntry ? 1 : 0);
                });
                n += (state.__sections || []).length;
                return n;
            },
            toBatchOps() {
                const ops = [];
                // Creations first so overrides on brand-new entries always land
                // on an existing row within the same transaction.
                anchors().forEach(a => {
                    const e = state[a];
                    if (e.create) ops.push({ op: 'create_entry', offer_id: a, template: e.create.template, fields: e.create.fields });
                });
                anchors().forEach(a => {
                    const e = state[a];
                    if (e.deleteEntry) ops.push({ op: 'delete_entry', offer_id: a });
                    if (e.deleteAll) ops.push({ op: 'delete_all', offer_id: a });
                    for (const fk in e.ops) {
                        const o = e.ops[fk];
                        ops.push(o.op === 'delete'
                            ? { op: 'delete', offer_id: a, field_key: fk }
                            : { op: 'upsert', offer_id: a, field_key: fk, field_value: o.field_value, value_type: o.value_type });
                    }
                });
                return ops;
            },
            // ---- Manual entries (creations / deletions) ----
            stageCreate(anchor, template, fields) {
                ensure(anchor).create = { template, fields };
                emit();
            },
            updateCreateFields(anchor, patch) {
                const e = state[anchor];
                if (!e || !e.create) return;
                e.create.fields = Object.assign({}, e.create.fields, patch);
                emit();
            },
            unstageCreate(anchor) {
                const e = state[anchor];
                if (!e || !e.create) return;
                delete e.create;
                // Field overrides staged on a discarded creation are orphans.
                e.ops = {};
                e.deleteAll = false;
                prune(anchor);
                emit();
            },
            getCreate(anchor) {
                const e = state[anchor];
                return (e && e.create) ? e.create : null;
            },
            creations() {
                return anchors().filter(a => state[a].create)
                    .map(a => ({ anchor: a, create: state[a].create }));
            },
            stageDeleteEntry(anchor) {
                ensure(anchor).deleteEntry = true;
                emit();
            },
            unstageDeleteEntry(anchor) {
                const e = state[anchor];
                if (!e || !e.deleteEntry) return;
                delete e.deleteEntry;
                prune(anchor);
                emit();
            },
            isEntryDeletion(anchor) {
                const e = state[anchor];
                return !!(e && e.deleteEntry);
            },
            // ---- Draft sections (exist only in the editor until they get items) ----
            listSections() {
                return Array.isArray(state.__sections) ? state.__sections.slice() : [];
            },
            addSection(section) {
                if (!Array.isArray(state.__sections)) state.__sections = [];
                state.__sections.push(section);
                emit();
            },
            removeSection(id) {
                if (!Array.isArray(state.__sections)) return;
                state.__sections = state.__sections.filter(s => s.id !== id);
                if (!state.__sections.length) delete state.__sections;
                emit();
            }
        };
    })();

    // Command history over the draft store. Each user action records
    // structural before/after snapshots; undo/redo restore them, so the whole
    // draft is always internally consistent regardless of the edit sequence.
    const DlHistory = (() => {
        const LIMIT = 100;
        let undoStack = [], redoStack = [];
        return {
            record(label, before, after) {
                if (JSON.stringify(before) === JSON.stringify(after)) return;
                undoStack.push({ label, before, after });
                if (undoStack.length > LIMIT) undoStack.shift();
                redoStack = [];
            },
            undo() {
                const c = undoStack.pop();
                if (!c) return null;
                redoStack.push(c);
                DlDraftStore.restore(c.before);
                return c.label;
            },
            redo() {
                const c = redoStack.pop();
                if (!c) return null;
                undoStack.push(c);
                DlDraftStore.restore(c.after);
                return c.label;
            },
            clear() { undoStack = []; redoStack = []; }
        };
    })();

    /** Run a store mutation as one undoable command. */
    function dlCommit(label, mutate) {
        const before = DlDraftStore.snapshot();
        mutate();
        DlHistory.record(label, before, DlDraftStore.snapshot());
    }

    // ---------- Tabs ----------
    const tabs = document.querySelectorAll('.dl-tab');
    const loaders = {
        control: loadHealth,
        editor: () => loadGrid('dl-editor-grid', document.getElementById('dl-editor-src').value, true),
        fieldmap: loadFieldMap,
        preview: () => loadGrid('dl-preview-grid', document.getElementById('dl-preview-src').value, false),
        logs: loadLogs
    };
    tabs.forEach(t => t.addEventListener('click', () => {
        tabs.forEach(x => x.classList.remove('active'));
        t.classList.add('active');
        document.querySelectorAll('.dl-panel').forEach(p => p.classList.remove('active'));
        document.getElementById('panel-' + t.dataset.tab).classList.add('active');
        (loaders[t.dataset.tab] || (() => {}))();
    }));

    // ---------- Control Maestro ----------
    async function loadHealth() {
        const h = await api('health');
        if (h.status !== 'success') return;
        document.getElementById('m-mode').textContent = h.mode.toUpperCase();
        document.getElementById('m-active').textContent = (h.active_source || '—').toUpperCase();
        document.getElementById('m-live-items').textContent = h.live.items;
        document.getElementById('m-live-age').textContent = fmtAge(h.live.age_seconds) + (h.live_is_healthy ? '' : ' ⚠');
        const ld = h.live, fd = h.fallback;
        document.getElementById('dl-live-detail').innerHTML =
            `Existe: <b>${ld.exists ? 'sí' : 'no'}</b> · Ítems: <b>${ld.items}</b> · Hash: <code>${esc((ld.hash || '').slice(0, 10))}</code><br>Fecha tienda: ${esc(ld.shop_date)} · Generado: ${esc(ld.generated_at)} · ${(ld.bytes / 1048576).toFixed(2)} MB`;
        document.getElementById('dl-fallback-detail').innerHTML =
            `Existe: <b>${fd.exists ? 'sí' : 'no'}</b> · Ítems: <b>${fd.items}</b> · Hash: <code>${esc((fd.hash || '').slice(0, 10))}</code><br>Fecha tienda: ${esc(fd.shop_date)} · Edad: ${fmtAge(fd.age_seconds)}`;
        const c = h.log_counts || {};
        document.getElementById('dl-log-summary').innerHTML =
            `<span class="sev-info">Info: ${c.info || 0}</span><span class="sev-warning">Warning: ${c.warning || 0}</span><span class="sev-error">Error: ${c.error || 0}</span><span class="sev-critical">Critical: ${c.critical || 0}</span><span style="color:var(--text-secondary)">Overrides activos: ${h.override_count} · Reglas de mapeo: ${h.field_map_count}</span>`;
    }

    document.getElementById('dl-refresh-health').addEventListener('click', loadHealth);
    document.querySelectorAll('#dl-mode-buttons [data-mode]').forEach(b => b.addEventListener('click', async () => {
        const mode = b.dataset.mode;
        if (mode === 'maintenance' && !confirm('¿Poner la tienda en MANTENIMIENTO (sirve el fallback)?')) return;
        const r = await api('set_mode', { mode }, 'POST');
        if (r.status === 'success') { toast(r.message, 'success'); loadHealth(); }
    }));
    document.getElementById('dl-force-refresh').addEventListener('click', async () => {
        if (!confirm('¿Forzar la regeneración del caché en vivo desde la API?')) return;
        toast('Ejecutando Ingestor…', 'info');
        const r = await api('force_refresh', {}, 'POST');
        openModal('dl-log-modal');
        document.getElementById('dl-log-output').textContent = (r.log || '(sin salida)');
        loadHealth();
    });

    // ---------- Grid (editor + preview) ----------
    let lastItems = [];
    let lastSections = []; // [{id, name, rank}] según el snapshot cargado
    async function loadGrid(targetId, src, clickable) {
        const grid = document.getElementById(targetId);
        grid.innerHTML = '<p style="color:var(--text-secondary)">Cargando…</p>';
        const r = await api('get_items', { src });
        if (r.status !== 'success') { grid.innerHTML = '<p class="sev-error">No se pudo cargar.</p>'; return; }
        lastItems = r.items;
        lastSections = Array.isArray(r.sections) ? r.sections : [];
        if (Array.isArray(r.sets)) dlKnownSets = r.sets.slice().sort((a, b) => a.localeCompare(b, 'es'));
        if (targetId === 'dl-preview-grid') document.getElementById('dl-preview-count').textContent = r.count + ' ítems';
        renderGrid(targetId, r.items, clickable, '');
    }
    // ---- Shop-card replica helpers (same derivations as the storefront's
    //      app.js: bgStyleFromEntryColors, overlayRgbFromEntryColors,
    //      optimizeImageUrl, formatSoles, createPriceSpans, banner y pill) ----
    function bgStyle(colors) {
        if (!colors) return '';
        const valid = [colors.color1, colors.color3, colors.color2].filter(Boolean).map(c => '#' + String(c).slice(0, 6));
        if (valid.length >= 2) return `background-image: linear-gradient(180deg, ${valid[0]} 0%, ${valid[1] ?? valid[0]} 65%, ${valid[2] ?? valid[1] ?? valid[0]} 100%);`;
        if (valid.length === 1) return `background-color: ${valid[0]};`;
        return 'background-color: #333;';
    }
    function overlayRgb(colors) {
        const hex = String((colors && colors.textBackgroundColor) || '').slice(0, 6);
        if (!/^[0-9a-fA-F]{6}$/.test(hex)) return null;
        return [0, 2, 4].map(k => parseInt(hex.slice(k, k + 2), 16)).join(', ');
    }
    function optimizeImageUrl(url) {
        if (!url || typeof url !== 'string') return '';
        if (url.includes('via.placeholder.com') || url.includes('wsrv.nl')) return url;
        return 'https://wsrv.nl/?url=' + encodeURIComponent(url) + '&w=800&q=95&output=webp';
    }
    function formatSoles(amount) {
        const n = Number(amount);
        if (!isFinite(n)) return 'S/ 0.00';
        return 'S/ ' + n.toFixed(2);
    }
    function priceBlockHtml(i) {
        const base = (i.precio_base_soles !== undefined && i.precio_base_soles !== null && i.precio_base_soles !== '') ? parseFloat(i.precio_base_soles) : null;
        const final = (i.precio_oferta_soles !== undefined && i.precio_oferta_soles !== null && i.precio_oferta_soles !== '') ? parseFloat(i.precio_oferta_soles) : base;
        if (final == null || isNaN(final)) return '';
        const hasDiscount = base != null && !isNaN(base) && base > final;
        const hasIncrease = base != null && !isNaN(base) && final > base;
        const baseSpan = (hasDiscount || hasIncrease)
            ? `<span class="text-sm price-base-strikethrough line-through base-price">${esc(formatSoles(base))}</span>` : '';
        let finalClass = 'font-black text-base sm:text-lg';
        if (hasDiscount) finalClass += ' text-green-500';
        else if (hasIncrease) finalClass += ' text-price-negative';
        return `<div class="price-container">${baseSpan}<span class="${finalClass}">${esc(formatSoles(final))}</span></div>`;
    }
    function bannerHtml(banner) {
        // Mismo criterio que la tienda (CARD_BANNER_VARIANTS): solo el tipo
        // 'New' se muestra como banner de esquina.
        if (!banner || banner.backendValue !== 'New') return '';
        const text = String(banner.value || '').trim();
        if (!text) return '';
        return `<div class="card-badges-container"><div data-banner-type="new" aria-hidden="true">${esc(text)}</div></div>`;
    }
    function leavingTodayHtml(outDate) {
        if (!outDate) return '';
        const diff = new Date(outDate).getTime() - Date.now();
        if (diff <= 0 || diff >= 86400000) return '';
        return '<div class="leaving-today-pill">Se va hoy</div>';
    }
    function cardHtml(i, clickable) {
        const name = i.displayName || '';
        const rgb = overlayRgb(i.colors);
        const wrapperAttrs = [
            `data-item-type="${esc(i.item_type || 'Artículos')}"`,
            i.item_id ? `data-item-id="${esc(i.item_id)}"` : '',
            i.is_car_offer ? 'data-car-offer="1"' : '',
            i.is_dynamic_bundle ? 'data-dynamic-bundle="1"' : '',
            clickable ? `data-offerid="${esc(i.offerId)}"` : '',
            rgb ? `style="--card-overlay-rgb: ${rgb};"` : ''
        ].filter(Boolean).join(' ');
        const vbucksBlock = (i.vbucks != null) ? `
                        <div class="vbucks-block flex items-center" aria-label="Precio en V-Bucks" data-currency="vbucks">
                            <svg class="icon vbucks-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="24" height="24"><use href="#icon-pavos" xlink:href="#icon-pavos"></use></svg>
                            <span class="sr-only">V-Bucks:</span>
                            <span class="vbucks-price">${esc(Number(i.vbucks).toLocaleString('es-ES'))}</span>
                        </div>` : '';
        const card = `<div class="item-card-wrapper" ${wrapperAttrs}>
            <div class="item-card-content">
                <div class="item-image-container" ${clickable ? 'role="button" tabindex="0"' : ''} aria-label="${esc(name)}" style="${esc(bgStyle(i.colors))}">
                    ${bannerHtml(i.banner)}
                    ${i.has_override ? '<span class="dl-badge">OVERRIDE</span>' : ''}
                    ${DlDraftStore.get(i.anchor_offer_id || i.offerId) ? '<span class="dl-badge dl-badge-staged">PENDIENTE</span>' : ''}
                    ${i.is_draft_new ? '<span class="dl-badge dl-badge-new">NUEVO · BORRADOR</span>' : (i.is_custom ? '<span class="dl-badge dl-badge-new">MANUAL</span>' : '')}
                    ${DlDraftStore.isEntryDeletion(i.anchor_offer_id || i.offerId) ? '<span class="dl-badge dl-badge-del">SE ELIMINARÁ</span>' : ''}
                    <img class="item-image" loading="lazy" alt="" src="${esc(optimizeImageUrl(i.image))}" onerror="this.style.opacity=0">
                </div>
                <div class="card-info-overlay">
                    ${leavingTodayHtml(i.out_date)}
                    <h3 class="card-title" title="${esc(name)}">${esc(name)}</h3>
                    <div class="card-footer-pricing flex items-center justify-between">
                        ${vbucksBlock}
                        <div class="price-block">${priceBlockHtml(i)}</div>
                    </div>
                </div>
            </div>
        </div>`;
        return i.span > 1 ? `<div class="span-card-${i.span}">${card}</div>` : card;
    }
    // ---- Draft overlay for ordering/section fields: a card's effective
    //      position derives from any staged layoutId/sortPriority/layout/
    //      section_name upserts (or the staged creation's fields). ----
    function dlEffOrder(i) {
        const anchor = i.anchor_offer_id || i.offerId;
        const st = DlDraftStore.get(anchor);
        const sv = (k) => {
            const op = st && st.ops ? st.ops[k] : null;
            return (op && op.op === 'upsert') ? op.field_value : null;
        };
        let layout = i.layout || null;
        const stLayout = sv('layout');
        if (stLayout) { try { layout = JSON.parse(stLayout); } catch (e) { /* keep */ } }
        const layoutId = sv('layoutId') || i.layout_id_full || null;
        const spRaw = sv('sortPriority');
        const sortPriority = spRaw != null ? parseInt(spRaw, 10) || 0 : (i.sort_priority || 0);
        const sectionName = sv('section_name') || (layout && layout.name) || i.section_name || null;
        return {
            layout,
            layoutId,
            sortPriority,
            sectionName,
            rank: layout && layout.rank != null ? Number(layout.rank) : -1,
            suffix: layoutId ? (parseInt(String(layoutId).split('.').pop(), 10) || 0) : 0
        };
    }

    // Tile span (1–4) from a tileSize string. Mirrors dl_tile_span() /
    // app.js getTileSizeClass().
    function dlTileSpan(tileSize) {
        const m = /^Size_([1-4])_?x/.exec(String(tileSize || ''));
        return m ? parseInt(m[1], 10) : 1;
    }

    // ---- Draft overlay for presentation fields: the editor grid previews
    //      staged upserts (tileSize, nombre, precios, colores, imagen,
    //      banner, out-date) on the card itself. Solo visual y por sesión:
    //      nada se persiste hasta «Aplicar cambios». ----
    function dlEffCard(i) {
        const st = DlDraftStore.get(i.anchor_offer_id || i.offerId);
        if (!st || !st.ops || !Object.keys(st.ops).length) return i;
        const sv = (k) => {
            const op = st.ops[k];
            return (op && op.op === 'upsert') ? op.field_value : null;
        };
        const sj = (k) => {
            const raw = sv(k);
            if (raw == null) return null;
            try { return JSON.parse(raw); } catch (e) { return null; }
        };
        const eff = Object.assign({}, i);
        const name = sv('displayName');
        if (name) eff.displayName = name;
        const vb = sv('vbucks');
        if (vb != null && vb !== '') eff.vbucks = Number(vb);
        const base = sv('precio_base_soles');
        if (base != null && base !== '') { eff.precio_base_soles = base; eff.precio_oferta_soles = null; }
        const colors = sj('colors');
        if (colors) eff.colors = Object.assign({}, eff.colors || {}, colors);
        const tile = sv('tileSize');
        if (tile) eff.span = dlTileSpan(tile);
        const out = sv('outDate');
        if (out != null) eff.out_date = out;
        const banner = sj('banner');
        if (banner && typeof banner.mode === 'string') {
            const expired = banner.expiresOn && String(banner.expiresOn) < new Date().toISOString().slice(0, 10);
            eff.banner = (banner.mode === 'on' && !expired)
                ? { value: (banner.text || 'Nuevo'), backendValue: 'New' }
                : null;
        }
        const imgs = sj('images');
        if (imgs) {
            for (const k of ['albumArt', 'render', 'bundleImage', 'featured', 'icon', 'large', 'small']) {
                if (imgs[k]) { eff.image = imgs[k]; break; }
            }
        }
        return eff;
    }

    // Card view-model for a staged (not yet applied) manual creation.
    function dlDraftCard(anchor, cr) {
        const f = cr.fields || {};
        const layout = f.layout || null;
        return {
            offerId: anchor,
            anchor_offer_id: anchor,
            displayName: f.display_name || '(nuevo objeto)',
            vbucks: f.vbucks != null && f.vbucks !== '' ? Number(f.vbucks) : null,
            section_name: layout ? layout.name : null,
            layout,
            layout_id_full: layout ? layout.id + '.' + Math.max(1, parseInt(f.layout_suffix, 10) || 1) : null,
            sort_priority: parseInt(f.sort_priority, 10) || 0,
            image: f.image || '',
            colors: f.colors || null,
            span: 1,
            item_type: 'Artículos',
            is_custom: true,
            is_draft_new: true
        };
    }

    // Sections (name-keyed) ordered like the storefront worker: rank DESC.
    // The editor grid also merges staged creations and empty draft sections.
    function dlComposeSections(items, isEditor) {
        const list = items.slice();
        if (isEditor) {
            DlDraftStore.creations().forEach(({ anchor, create }) => {
                if (!list.some(i => (i.anchor_offer_id || i.offerId) === anchor)) list.push(dlDraftCard(anchor, create));
            });
        }
        const groups = new Map(); // name -> {name, rank, meta, items}
        list.forEach(i => {
            const eff = isEditor ? dlEffOrder(i) : {
                sectionName: i.section_name, rank: i.layout && i.layout.rank != null ? Number(i.layout.rank) : -1,
                suffix: i.layout_id_full ? (parseInt(String(i.layout_id_full).split('.').pop(), 10) || 0) : 0,
                sortPriority: i.sort_priority || 0, layout: i.layout || null
            };
            const name = eff.sectionName || 'Otros';
            if (!groups.has(name)) {
                groups.set(name, {
                    name,
                    rank: eff.rank,
                    meta: eff.layout ? { id: eff.layout.id, name, rank: eff.rank } : null,
                    isDraft: false,
                    items: []
                });
            }
            const g = groups.get(name);
            i.__eff = eff;
            g.items.push(i);
        });
        if (isEditor) {
            DlDraftStore.listSections().forEach(s => {
                if (!groups.has(s.name)) {
                    groups.set(s.name, { name: s.name, rank: s.rank, meta: { id: s.id, name: s.name, rank: s.rank }, isDraft: true, items: [] });
                }
            });
        }
        const out = Array.from(groups.values());
        out.forEach(g => g.items.sort((a, b) =>
            (b.__eff.suffix - a.__eff.suffix) || (b.__eff.sortPriority - a.__eff.sortPriority)));
        out.sort((a, b) => (b.rank - a.rank) || a.name.localeCompare(b.name));
        return out;
    }

    function renderGrid(targetId, items, clickable, filter) {
        const grid = document.getElementById(targetId);
        const isEditor = targetId === 'dl-editor-grid';
        const f = (filter || '').toLowerCase();
        const list = !f ? items : items.filter(i => (i.displayName || '').toLowerCase().includes(f) || (i.offerId || '').toLowerCase().includes(f));
        const groups = dlComposeSections(list, isEditor && !f);
        if (!groups.length) { grid.innerHTML = '<p style="color:var(--text-secondary)">Sin resultados.</p>'; return; }
        grid.innerHTML = groups.map(g => `
            <div class="dl-section" data-section-name="${esc(g.name)}"${g.meta ? ` data-section-id="${esc(g.meta.id)}" data-section-rank="${g.meta.rank}"` : ''}>
                <h3 class="dl-section-title">${esc(g.name)} <span class="dl-section-count">${g.items.length}</span>
                    ${g.isDraft ? '<span class="dl-section-draft-tag">BORRADOR</span>' : ''}
                    ${isEditor && g.meta ? `<span class="dl-section-tools"><button type="button" class="dl-add-item-btn" data-add-to="${esc(g.name)}" aria-label="Añadir objeto a ${esc(g.name)}"><i class="fas fa-plus"></i> Añadir objeto</button>${g.isDraft ? `<button type="button" class="dl-add-item-btn" data-remove-section="${esc(g.meta.id)}" aria-label="Quitar sección en borrador ${esc(g.name)}"><i class="fas fa-trash-can"></i></button>` : ''}</span>` : ''}
                </h3>
                <div class="dl-grid" data-drop-section="${esc(g.name)}">${g.items.length ? g.items.map(i => cardHtml(isEditor ? dlEffCard(i) : i, clickable)).join('') : (isEditor ? '<div class="dl-section-empty-hint">Sección vacía: añade objetos para que se publique en la tienda.</div>' : '')}</div>
            </div>`).join('');
        if (clickable) grid.querySelectorAll('.item-card-wrapper[data-offerid]').forEach(c => {
            c.addEventListener('click', (e) => {
                if (dlOrganizeMode) { e.preventDefault(); dlShowOrgToolbar(c); return; }
                openItem(c.dataset.offerid);
            });
            const btn = c.querySelector('.item-image-container[role="button"]');
            if (btn) btn.addEventListener('keydown', (e) => {
                if (dlOrganizeMode) { dlOrgKeydown(e, c); return; }
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openItem(c.dataset.offerid); }
            });
        });
        if (isEditor) {
            grid.classList.toggle('dl-organizing', dlOrganizeMode);
            grid.querySelectorAll('[data-add-to]').forEach(b => b.addEventListener('click', () => dlOpenCreateModal(b.dataset.addTo)));
            grid.querySelectorAll('[data-remove-section]').forEach(b => b.addEventListener('click', () => {
                const id = b.dataset.removeSection;
                const s = DlDraftStore.listSections().find(x => x.id === id);
                if (!s || !confirm('¿Quitar la sección en borrador «' + s.name + '»?')) return;
                dlCommit('Quitar sección en borrador «' + s.name + '»', () => DlDraftStore.removeSection(id));
            }));
        }
    }
    document.getElementById('dl-editor-search').addEventListener('input', (e) => renderGrid('dl-editor-grid', lastItems, true, e.target.value));
    document.getElementById('dl-editor-src').addEventListener('change', () => loadGrid('dl-editor-grid', document.getElementById('dl-editor-src').value, true));
    document.getElementById('dl-preview-src').addEventListener('change', () => loadGrid('dl-preview-grid', document.getElementById('dl-preview-src').value, false));

    // ---------- Item editor modal ----------
    const DL_MANUAL = '__manual__';

    // Normalized comparison key: case- and accent-insensitive, trimmed.
    // Used to match an item's effective value against the predefined options
    // so casing/diacritics drifts never force the combo into manual mode.
    function dlNorm(s) {
        return String(s == null ? '' : s).trim().toUpperCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    // Predefined rarities: {id, name} pairs matching what ShopNormalizer
    // emits (id = ucfirst(rarity.value) for base rarities; for series the id
    // is the API's Spanish series.value verbatim, NOT the backendValue).
    // rarityToRaw() publishes id→value, name→displayValue.
    const DL_RARITIES = [
        { id: 'Common', name: 'COMÚN' },
        { id: 'Uncommon', name: 'POCO COMÚN' },
        { id: 'Rare', name: 'RARO' },
        { id: 'Epic', name: 'ÉPICO' },
        { id: 'Legendary', name: 'LEGENDARIO' },
        { id: 'Mythic', name: 'MÍTICO' },
        { id: 'Exotic', name: 'EXÓTICO' },
        { id: 'SERIE DE MARVEL', name: 'SERIE DE MARVEL' },
        { id: 'SERIE DE DC', name: 'SERIE DE DC' },
        { id: 'Serie de ídolos', name: 'SERIE DE ÍDOLOS' },
        { id: 'SERIE DE STAR WARS', name: 'SERIE DE STAR WARS' },
        { id: 'LEYENDAS DE VIDEOJUEGOS', name: 'LEYENDAS DE VIDEOJUEGOS' },
        { id: 'SERIE DE DEFENDER', name: 'SERIE DE DEFENDER' },
        { id: 'SERIE OSCURA', name: 'SERIE OSCURA' }
    ];

    // Predefined type labels: same singular table the storefront uses
    // (app.js CONFIG.TYPE_TRANSLATIONS_SINGULAR), deduplicated.
    const DL_DISPLAY_TYPES = [
        'Traje', 'Gesto', 'Ala delta', 'Pico', 'Accesorio mochilero',
        'Envoltorio', 'Calzado', 'Estela', 'Coche', 'Rueda',
        'Pista de improvisación', 'Rastro', 'Potenciador', 'Compañero',
        'Instrumento', 'Lote', 'Estilo', 'Cosmético', 'Acceso', 'Grafiti',
        'Música', 'Pantalla de carga', 'Autobús de batalla', 'Artículo'
    ];

    // Storefront jam-track instrument config (app.js CONFIG.JAM_TRACK_*).
    const DL_JAM_ORDER = ['vocals', 'plasticBass', 'plasticDrums', 'bass', 'guitar', 'drums'];
    const DL_JAM_LABELS = {
        vocals: 'Voz', plasticBass: 'Bajo', plasticGuitar: 'Instrumento Principal',
        plasticDrums: 'Batería', bass: 'Bajo Pro', guitar: 'Instrumento Principal Pro',
        drums: 'Batería Pro'
    };

    /**
     * "Select or free text" combo: a <select> with predefined options plus a
     * final "manual" option that reveals a text input for exceptions.
     */
    function makeCombo(selectId, inputId, options, emptyLabel) {
        const sel = document.getElementById(selectId);
        const inp = document.getElementById(inputId);
        // Dedupe by normalized value: defends against near-duplicate entries.
        const seen = new Set();
        options = options.filter(o => {
            const k = dlNorm(o.value);
            if (seen.has(k)) return false;
            seen.add(k);
            return true;
        });
        sel.innerHTML = '<option value="">' + esc(emptyLabel || '— Sin cambio —') + '</option>'
            + options.map(o => `<option value="${esc(o.value)}">${esc(o.label)}</option>`).join('')
            + `<option value="${DL_MANUAL}">Escribir manualmente…</option>`;
        sel.addEventListener('change', () => {
            const manual = sel.value === DL_MANUAL;
            inp.style.display = manual ? '' : 'none';
            if (manual) inp.focus();
        });
        return {
            set(value) {
                const val = String(value == null ? '' : value);
                const norm = dlNorm(val);
                const match = options.find(o => dlNorm(o.value) === norm || dlNorm(o.label) === norm);
                if (val === '') {
                    sel.value = ''; inp.value = ''; inp.style.display = 'none';
                } else if (match) {
                    sel.value = match.value; inp.value = ''; inp.style.display = 'none';
                } else {
                    sel.value = DL_MANUAL; inp.value = val; inp.style.display = '';
                }
                dlmSync(selectId);
            },
            get() {
                if (sel.value === DL_MANUAL) return inp.value.trim();
                return sel.value;
            }
        };
    }
    // --- Custom dropdown: accessible listbox over a hidden native select ---
    // The native <select> stays as the single source of truth (all existing
    // read/write code keeps working); the widget mirrors it and dispatches
    // 'change' on pick.
    const DLM_PENCIL_SVG = '<svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.6 1.9a1.45 1.45 0 0 1 2.05 0l.45.45a1.45 1.45 0 0 1 0 2.05l-8.3 8.3-3.1.9.9-3.1 8-8.6zM10.4 3.4l2.2 2.2M2.5 14.5h11"/></svg>';
    const dlmSelects = {};
    function dlmSync(selectId) { if (dlmSelects[selectId]) dlmSelects[selectId].sync(); }
    function dlmRefresh(selectId) { if (dlmSelects[selectId]) dlmSelects[selectId].refresh(); }
    function dlmSelect(sel) {
        const wrap = document.createElement('div');
        wrap.className = 'dlm-select';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dlm-select-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        if (sel.id) btn.id = sel.id + '-btn';
        btn.innerHTML = '<span class="lbl"></span><span class="caret" aria-hidden="true"></span>';
        const list = document.createElement('div');
        list.className = 'dlm-select-list';
        list.setAttribute('role', 'listbox');
        list.id = (sel.id || 'dlm') + '-listbox';
        list.tabIndex = -1;
        btn.setAttribute('aria-controls', list.id);
        wrap.appendChild(btn);
        wrap.appendChild(list);
        // The field label points at the select; mirror it onto the button.
        const lab = sel.id ? document.querySelector(`label[for="${sel.id}"]`) : null;
        if (lab) { lab.id = lab.id || sel.id + '-label'; btn.setAttribute('aria-labelledby', lab.id); }
        let focusIdx = -1;
        const opts = () => Array.from(list.children);
        function sync() {
            const cur = sel.options[sel.selectedIndex];
            btn.querySelector('.lbl').textContent = cur ? cur.textContent : '';
            btn.disabled = sel.disabled;
            opts().forEach(el => {
                const isSel = el.dataset.value === sel.value;
                el.classList.toggle('sel', isSel);
                el.setAttribute('aria-selected', isSel ? 'true' : 'false');
            });
        }
        function refresh() {
            list.innerHTML = Array.from(sel.options).map((o, i) =>
                `<div class="dlm-select-opt" id="${list.id}-${i}" role="option" data-value="${esc(o.value)}">`
                + (o.value === DL_MANUAL ? DLM_PENCIL_SVG : '')
                + `${esc(o.textContent)}</div>`).join('');
            sync();
        }
        function setFocus(i) {
            const all = opts();
            if (!all.length) return;
            focusIdx = Math.max(0, Math.min(all.length - 1, i));
            all.forEach((el, j) => el.classList.toggle('focus', j === focusIdx));
            btn.setAttribute('aria-activedescendant', all[focusIdx].id);
            all[focusIdx].scrollIntoView({ block: 'nearest' });
        }
        function openList() {
            if (sel.disabled) return;
            wrap.classList.add('open');
            btn.setAttribute('aria-expanded', 'true');
            const idx = opts().findIndex(el => el.dataset.value === sel.value);
            setFocus(idx >= 0 ? idx : 0);
        }
        function closeList() {
            wrap.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
            btn.removeAttribute('aria-activedescendant');
        }
        function pick(el) {
            sel.value = el.dataset.value;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            sync();
            closeList();
            btn.focus();
        }
        btn.addEventListener('click', () => wrap.classList.contains('open') ? closeList() : openList());
        btn.addEventListener('keydown', (e) => {
            const open = wrap.classList.contains('open');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!open) return openList();
                setFocus(focusIdx + (e.key === 'ArrowDown' ? 1 : -1));
            } else if (e.key === 'Home' && open) { e.preventDefault(); setFocus(0); }
            else if (e.key === 'End' && open) { e.preventDefault(); setFocus(opts().length - 1); }
            else if ((e.key === 'Enter' || e.key === ' ') && open) { e.preventDefault(); if (opts()[focusIdx]) pick(opts()[focusIdx]); }
            else if (e.key === 'Escape' && open) { e.preventDefault(); closeList(); }
            else if (e.key.length === 1 && /\S/.test(e.key) && open) {
                const q = dlNorm(e.key);
                const all = opts();
                const start = focusIdx + 1;
                for (let i = 0; i < all.length; i++) {
                    const el = all[(start + i) % all.length];
                    if (dlNorm(el.textContent).startsWith(q)) { setFocus((start + i) % all.length); break; }
                }
            }
        });
        list.addEventListener('click', (e) => {
            const el = e.target.closest('.dlm-select-opt');
            if (el) pick(el);
        });
        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) closeList();
        });
        sel.addEventListener('change', sync);
        refresh();
        const inst = { refresh, sync };
        if (sel.id) dlmSelects[sel.id] = inst;
        return inst;
    }

    const rarityCombo = makeCombo('f-rarity-select', 'f-rarity', DL_RARITIES.map(r => ({ value: r.id, label: r.name })));
    const typeCombo = makeCombo('f-displayType-select', 'f-displayType', DL_DISPLAY_TYPES.map(t => ({ value: t, label: t })));
    ['f-rarity-select', 'f-displayType-select', 'f-img-slot'].forEach(id => dlmSelect(document.getElementById(id)));
    const rarityNameById = (id) => {
        const hit = DL_RARITIES.find(r => r.id === id);
        return hit ? hit.name : String(id || '').toUpperCase();
    };

    // --- Preview gradient: storefront formula (worker.js generateBackgroundStyle:
    // linear-gradient(180deg, color1 0%, color3 65%, color2 100%)), fed by the
    // effective colors and updated live as Color 1/2/3 are edited. Falls back
    // to a representative per-rarity palette when the item has no colors. ---
    const DL_RARITY_FALLBACK = {
        'COMUN': ['6e7a83', '454e55', '272c30'],
        'POCO COMUN': ['3fbf54', '1d7a34', '0e4a20'],
        'RARO': ['33c1ff', '1873c9', '0c3e78'],
        'EPICO': ['c243e8', '7a1fa8', '470f66'],
        'LEGENDARIO': ['e9a34c', 'b06a26', '6e3d12'],
        'MITICO': ['ffd34d', 'c9992a', '8a6414'],
        'EXOTICO': ['58d6c9', '2b9a90', '145d56'],
        'SERIE DE MARVEL': ['ed1d24', '9c1218', '4d080c'],
        'SERIE DE DC': ['2b6bd8', '1a4390', '0d2149'],
        'SERIE DE IDOLOS': ['3bd8e8', '1f8fa0', '0f4750'],
        'SERIE DE STAR WARS': ['1b2a55', '111c3b', '070c1c'],
        'LEYENDAS DE VIDEOJUEGOS': ['5a4fd8', '37309c', '1a1750'],
        'SERIE OSCURA': ['b026ff', '5f0f96', '2a0645']
    };
    function dlPaintGradient(el, color1, color2, color3, rarityText) {
        // Worker order: [color1, color3, color2]
        const cols = [color1, color3, color2]
            .map(c => String(c || '').trim())
            .filter(c => /^[0-9a-fA-F]{6,8}$/.test(c))
            .map(c => '#' + c.slice(0, 6));
        if (cols.length >= 2) {
            el.style.backgroundImage = 'linear-gradient(180deg, ' + cols[0] + ' 0%, '
                + (cols[1] || cols[0]) + ' 65%, ' + (cols[2] || cols[1] || cols[0]) + ' 100%)';
            return;
        }
        if (cols.length === 1) { el.style.backgroundImage = 'linear-gradient(180deg, ' + cols[0] + ' 0%, ' + cols[0] + ' 100%)'; return; }
        const pal = DL_RARITY_FALLBACK[dlNorm(rarityText)];
        el.style.backgroundImage = pal
            ? 'linear-gradient(180deg, #' + pal[0] + ' 0%, #' + pal[1] + ' 65%, #' + pal[2] + ' 100%)'
            : '';
    }
    function updatePreviewGradient() {
        const v = (id) => document.getElementById(id).value;
        dlPaintGradient(document.getElementById('dl-modal-preview'),
            v('f-color1'), v('f-color2'), v('f-color3'), rarityCombo.get());
    }

    // --- Description textarea: fixed resize off, height follows content up
    // to the CSS max-height, then an internal scrollbar takes over. ---
    const descEl = document.getElementById('f-description');
    function autosizeDesc() {
        descEl.style.height = 'auto';
        const max = 220;
        descEl.style.height = Math.min(descEl.scrollHeight, max) + 'px';
        descEl.style.overflowY = descEl.scrollHeight > max ? 'auto' : 'hidden';
    }
    descEl.addEventListener('input', autosizeDesc);

    // --- Image editor: slot dropdown + URL input + live preview -------------
    let imageState = null; // { slots, values: {key: url}, dirty: Set (saved+touched), touched: Set (session edits) }
    function currentSlot() {
        if (!imageState) return null;
        const key = document.getElementById('f-img-slot').value;
        return imageState.slots.find(s => s.key === key) || null;
    }
    function refreshImagePreview() {
        const slot = currentSlot();
        const url = slot ? (imageState.values[slot.key] || '') : '';
        document.getElementById('dl-modal-img').src = url;
        document.getElementById('f-img-hint').textContent = slot ? slot.hint : '';
    }
    function initImageEditor(effective, existingOverrides) {
        const sel = document.getElementById('f-img-slot');
        const inp = document.getElementById('f-img-url');
        let saved = {};
        try {
            saved = existingOverrides && existingOverrides.images
                ? (JSON.parse(existingOverrides.images.field_value) || {}) : {};
        } catch (e) { saved = {}; }
        const slots = (effective && effective.image_slots) || [];
        imageState = { slots, values: {}, dirty: new Set(Object.keys(saved)), touched: new Set() };
        slots.forEach(s => { imageState.values[s.key] = saved[s.key] || s.url || ''; });
        sel.innerHTML = slots.map(s => `<option value="${esc(s.key)}">${esc(s.label)}</option>`).join('');
        sel.disabled = inp.disabled = !slots.length;
        document.getElementById('f-img-upload-btn').disabled = !slots.length;
        dlmRefresh('f-img-slot');
        const syncInput = () => {
            const slot = currentSlot();
            inp.value = slot ? (imageState.values[slot.key] || '') : '';
            refreshImagePreview();
        };
        sel.onchange = syncInput;
        inp.oninput = () => {
            const slot = currentSlot();
            if (!slot) return;
            imageState.values[slot.key] = inp.value.trim();
            imageState.dirty.add(slot.key);
            imageState.touched.add(slot.key);
            refreshImagePreview();
        };
        syncInput();
    }

    // --- Local image upload: shared component for every image slot ----------
    // Client-side validation (extension, MIME, 20 MB), XHR progress, in-flight
    // duplicate blocking and contextual error/success messages. Instances are
    // registered so closeModals() can abort pending requests and clear state.
    const DL_UPLOAD_MAX_BYTES = 20 * 1024 * 1024;
    const DL_UPLOAD_MIMES = { 'image/png': true, 'image/webp': true, 'image/jpeg': true };
    const dlUploaderRegistry = new Set();
    function dlResetUploaders() { dlUploaderRegistry.forEach(u => u.reset()); }
    function dlCreateUploader(cfg) {
        // cfg: { button, fileInput, msgEl, barEl, onSuccess(url), onBusyChange? }
        let xhr = null;
        const setMsg = (text, kind) => {
            cfg.msgEl.textContent = text || '';
            cfg.msgEl.className = 'dl-upload-msg' + (text ? ' ' + kind : '');
        };
        const setBusy = (busy) => {
            cfg.button.disabled = busy;
            cfg.button.classList.toggle('busy', busy);
            cfg.barEl.style.display = busy ? 'block' : 'none';
            if (!busy) cfg.barEl.firstElementChild.style.width = '0%';
            if (cfg.onBusyChange) cfg.onBusyChange(busy);
        };
        cfg.button.addEventListener('click', () => {
            if (xhr) return;
            setMsg('', '');
            cfg.fileInput.click();
        });
        cfg.fileInput.addEventListener('change', () => {
            const file = cfg.fileInput.files && cfg.fileInput.files[0];
            cfg.fileInput.value = '';
            if (!file || xhr) return;
            if (!/\.(png|webp|jpe?g)$/i.test(file.name) || (file.type && !DL_UPLOAD_MIMES[file.type])) {
                setMsg('Formato no compatible. Solo se permiten PNG, WebP y JPG/JPEG.', 'err');
                return;
            }
            if (file.size > DL_UPLOAD_MAX_BYTES) {
                setMsg('El archivo excede el tamaño máximo permitido (20 MB).', 'err');
                return;
            }
            setBusy(true);
            xhr = new XMLHttpRequest();
            xhr.open('POST', API + '?accion=upload_image&v=' + Date.now());
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) cfg.barEl.firstElementChild.style.width = Math.round(e.loaded / e.total * 100) + '%';
            };
            xhr.onload = () => {
                let j = null;
                try { j = JSON.parse(xhr.responseText); } catch (e) { j = null; }
                xhr = null;
                setBusy(false);
                if (j && j.status === 'success' && j.url) {
                    setMsg('Imagen subida correctamente.', 'ok');
                    cfg.onSuccess(j.url);
                } else {
                    setMsg((j && j.message) || 'Error al procesar la imagen.', 'err');
                }
            };
            xhr.onerror = () => { xhr = null; setBusy(false); setMsg('Error de red al subir la imagen.', 'err'); };
            xhr.onabort = () => { xhr = null; setBusy(false); };
            const fd = new FormData();
            fd.append('accion', 'upload_image');
            fd.append('image', file);
            xhr.send(fd);
        });
        const inst = {
            reset() { if (xhr) xhr.abort(); setBusy(false); setMsg('', ''); },
            clearMsg() { setMsg('', ''); },
            get busy() { return !!xhr; }
        };
        dlUploaderRegistry.add(inst);
        return inst;
    }

    // Edit modal: upload feeds the active slot's URL input (dispatching
    // 'input' reuses the existing dirty-tracking + live-preview path).
    const editImgUploader = dlCreateUploader({
        button: document.getElementById('f-img-upload-btn'),
        fileInput: document.getElementById('f-img-upload-file'),
        msgEl: document.getElementById('f-img-upload-msg'),
        barEl: document.getElementById('f-img-upload-bar'),
        onSuccess: (url) => {
            const inp = document.getElementById('f-img-url');
            inp.value = url;
            inp.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
    document.getElementById('f-img-url').addEventListener('input', () => editImgUploader.clearMsg());
    document.getElementById('f-img-slot').addEventListener('change', () => editImgUploader.clearMsg());

    // --- Jam-track difficulty editor: storefront-style bars, click to set ---
    let diffState = null; // { levels: {apiKey: n}, dirty: bool } | null
    function renderDifficultyEditor(effective, existingOverrides) {
        const wrap = document.getElementById('dl-diff-wrap');
        const grid = document.getElementById('dl-diff-grid');
        diffState = null;
        wrap.style.display = 'none';
        grid.innerHTML = '';
        if (!effective || !effective.is_jam_track) return;
        let saved = {};
        try {
            saved = existingOverrides && existingOverrides.difficulty
                ? (JSON.parse(existingOverrides.difficulty.field_value) || {}) : {};
        } catch (e) { saved = {}; }
        const base = effective.difficulty || {};
        const levels = {};
        DL_JAM_ORDER.forEach(k => {
            const v = (typeof saved[k] === 'number') ? saved[k] : base[k];
            levels[k] = (typeof v === 'number' && isFinite(v)) ? Math.max(0, Math.min(7, Math.round(v))) : 0;
        });
        diffState = { levels, dirty: false };
        grid.innerHTML = DL_JAM_ORDER.map(k => `
            <div class="dl-diff-part" data-key="${esc(k)}">
                <button type="button" class="dl-diff-num${levels[k] === 0 ? ' off' : ''}" title="Ocultar instrumento (nivel 0)">${levels[k]}</button>
                <div>
                    <div class="dl-diff-name">${esc(DL_JAM_LABELS[k] || k)}</div>
                    <div class="dl-diff-bars">${[1, 2, 3, 4, 5, 6, 7].map(n =>
                        `<button type="button" class="dl-diff-bar${n <= levels[k] ? ' active' : ''}" data-lvl="${n}" aria-label="${esc(DL_JAM_LABELS[k] || k)}: nivel ${n}"></button>`).join('')}</div>
                </div>
            </div>`).join('');
        const paint = (part, lvl) => {
            part.querySelector('.dl-diff-num').textContent = lvl;
            part.querySelector('.dl-diff-num').classList.toggle('off', lvl === 0);
            part.querySelectorAll('.dl-diff-bar').forEach(b =>
                b.classList.toggle('active', parseInt(b.dataset.lvl, 10) <= lvl));
        };
        grid.querySelectorAll('.dl-diff-part').forEach(part => {
            const key = part.dataset.key;
            part.querySelectorAll('.dl-diff-bar').forEach(b => b.addEventListener('click', () => {
                diffState.levels[key] = parseInt(b.dataset.lvl, 10);
                diffState.dirty = true;
                paint(part, diffState.levels[key]);
                updateDirtyUI();
            }));
            part.querySelector('.dl-diff-num').addEventListener('click', () => {
                diffState.levels[key] = 0;
                diffState.dirty = true;
                paint(part, 0);
                updateDirtyUI();
            });
        });
        wrap.style.display = '';
    }

    // --- Card presentation: "NUEVO" corner banner + tileSize (1–4) ----------
    // Banner override shape: { mode: 'on'|'off', text, expiresOn } where
    // `expiresOn` is a UTC rotation date (the DAL stops applying the override
    // from that shop day on; null = no expiration). Absence of override = the
    // API decides ('Automático').
    let tileState = null;   // { span } | null

    function dlAddDaysUtc(dateStr, n) {
        const d = new Date(dateStr + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    }
    function dlDaysBetweenUtc(a, b) {
        return Math.round((new Date(b + 'T00:00:00Z') - new Date(a + 'T00:00:00Z')) / 86400000);
    }

    // Reusable «NUEVO» banner control. cfg maps element ids:
    //   { seg, detail, text, expMinus, expPlus, expPlus7, expNever, expManual,
    //     expDate, expLabel, apiHint?, previewEl?, onChange? }
    // State: { mode, text, expiresOn, hadOverride, apiActive, apiText, today } | null
    function makeBannerControl(cfg) {
        const $ = (id) => document.getElementById(id);
        let st = null;
        function updatePreview() {
            if (!cfg.previewEl) return;
            const el = $(cfg.previewEl);
            if (!st) { el.style.display = 'none'; return; }
            let text = '';
            if (st.mode === 'on') text = (st.text || 'Nuevo').trim() || 'Nuevo';
            else if (st.mode === '' && st.apiActive) text = st.apiText;
            el.textContent = text;
            el.style.display = text ? '' : 'none';
        }
        function paint() {
            if (!st) return;
            document.querySelectorAll('#' + cfg.seg + ' .dlm-seg-btn').forEach(b =>
                b.setAttribute('aria-checked', b.dataset.mode === st.mode ? 'true' : 'false'));
            $(cfg.detail).classList.toggle('open', st.mode === 'on');
            const expLabel = $(cfg.expLabel);
            const dateInp = $(cfg.expDate);
            $(cfg.expNever).setAttribute('aria-checked', st.expiresOn === null ? 'true' : 'false');
            dateInp.min = dlAddDaysUtc(st.today, 1);
            dateInp.value = st.expiresOn || '';
            if (st.expiresOn === null) {
                expLabel.textContent = 'Sin caducidad: se mostrará hasta que quites el override.';
            } else {
                const n = dlDaysBetweenUtc(st.today, st.expiresOn);
                expLabel.textContent = 'Caduca en la rotación del ' + st.expiresOn + ' UTC ('
                    + (n <= 1 ? 'la próxima rotación' : 'quedan ' + n + ' rotaciones') + ').';
            }
            updatePreview();
        }
        function bump(days) {
            if (!st) return;
            const min = dlAddDaysUtc(st.today, 1);
            const base = st.expiresOn || st.today;
            let next = dlAddDaysUtc(base, days);
            if (next < min) next = min;
            st.expiresOn = next;
            paint();
            if (cfg.onChange) cfg.onChange();
        }
        document.querySelectorAll('#' + cfg.seg + ' .dlm-seg-btn').forEach(b => b.addEventListener('click', () => {
            if (!st) return;
            st.mode = b.dataset.mode;
            paint();
            if (cfg.onChange) cfg.onChange();
        }));
        $(cfg.text).addEventListener('input', () => {
            if (!st) return;
            st.text = $(cfg.text).value;
            updatePreview();
        });
        $(cfg.expMinus).addEventListener('click', () => bump(-1));
        $(cfg.expPlus).addEventListener('click', () => bump(1));
        $(cfg.expPlus7).addEventListener('click', () => bump(7));
        $(cfg.expNever).addEventListener('click', () => {
            if (!st) return;
            st.expiresOn = st.expiresOn === null ? dlAddDaysUtc(st.today, 1) : null;
            paint();
            if (cfg.onChange) cfg.onChange();
        });
        $(cfg.expManual).addEventListener('click', () => {
            const inp = $(cfg.expDate);
            const show = inp.style.display === 'none';
            $(cfg.expManual).setAttribute('aria-checked', show ? 'true' : 'false');
            inp.style.display = show ? '' : 'none';
            if (show) inp.focus();
        });
        $(cfg.expDate).addEventListener('change', () => {
            if (!st) return;
            const v = $(cfg.expDate).value;
            const min = dlAddDaysUtc(st.today, 1);
            if (/^\d{4}-\d{2}-\d{2}$/.test(v)) {
                st.expiresOn = v < min ? min : v;
                paint();
                if (cfg.onChange) cfg.onChange();
            }
        });
        return {
            get state() { return st; },
            render(effective, existingOverrides) {
                const effB = (effective && effective.banner) || { active: false, text: '' };
                const today = (effective && effective.today_utc) || new Date().toISOString().slice(0, 10);
                let saved = null;
                try {
                    saved = existingOverrides && existingOverrides.banner
                        ? JSON.parse(existingOverrides.banner.field_value) : null;
                } catch (e) { saved = null; }
                if (!saved || (saved.mode !== 'on' && saved.mode !== 'off')) saved = null;
                const savedExp = saved && typeof saved.expiresOn === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(saved.expiresOn)
                    ? saved.expiresOn : null;
                st = {
                    mode: saved ? saved.mode : '',
                    text: (saved && typeof saved.text === 'string' && saved.text.trim())
                        ? saved.text.trim() : (effB.text || 'Nuevo'),
                    // Default: lapses at the NEXT shop rotation (tomorrow, UTC).
                    expiresOn: saved ? savedExp : dlAddDaysUtc(today, 1),
                    hadOverride: !!saved,
                    apiActive: !!effB.active,
                    apiText: effB.text || '',
                    today
                };
                $(cfg.text).value = st.text;
                if (cfg.apiHint) {
                    $(cfg.apiHint).textContent = st.apiActive
                        ? 'API: muestra «' + st.apiText + '» (se quita solo cuando la API deja de enviarlo).'
                        : 'API: sin banner actualmente.';
                }
                $(cfg.expManual).setAttribute('aria-checked', 'false');
                $(cfg.expDate).style.display = 'none';
                paint();
            }
        };
    }
    // --- Conjunto / Set: dropdown <-> texto con sincronización bidireccional.
    // Opciones: '' (Sin conjunto), cada set detectado, '__custom__' (manual).
    // Escribir un nombre que coincide (insensible a mayúsculas) con un set
    // existente auto-selecciona ese set y normaliza al nombre canónico.
    let dlKnownSets = []; // nombres de conjunto detectados en el snapshot cargado
    function makeSetControl(cfg) {
        const $ = (id) => document.getElementById(id);
        const sel = $(cfg.select), inp = $(cfg.input), hint = $(cfg.hint);
        let syncing = false;
        function matchKnown(text) {
            const t = text.trim().toLowerCase();
            if (!t) return null;
            return dlKnownSets.find(s => s.toLowerCase() === t) || null;
        }
        function paintHint() {
            const v = inp.value.trim();
            if (!v) hint.textContent = 'Sin conjunto: el objeto no pertenece a ningún set.';
            else if (sel.value === '__custom__') hint.textContent = 'Conjunto personalizado: «' + v + '» (nuevo).';
            else hint.textContent = 'Conjunto existente: «' + sel.value + '».';
        }
        function rebuildOptions(current) {
            const names = dlKnownSets.slice();
            if (current && !matchKnown(current)) names.push(current);
            sel.innerHTML = '<option value="">— Sin conjunto / Ninguno —</option>'
                + names.map(n => '<option value="' + esc(n) + '">' + esc(n) + '</option>').join('')
                + '<option value="__custom__">Escribir manualmente (Personalizado)</option>';
        }
        sel.addEventListener('change', () => {
            if (syncing) return;
            syncing = true;
            if (sel.value === '__custom__') { inp.focus(); }
            else inp.value = sel.value;
            syncing = false;
            paintHint();
            if (cfg.onChange) cfg.onChange();
        });
        inp.addEventListener('input', () => {
            if (syncing) return;
            syncing = true;
            const known = matchKnown(inp.value);
            sel.value = known || (inp.value.trim() ? '__custom__' : '');
            syncing = false;
            paintHint();
            if (cfg.onChange) cfg.onChange();
        });
        return {
            // Valor efectivo: nombre canónico si coincide con un set existente,
            // texto libre si es personalizado, '' si no hay conjunto.
            get() {
                const v = inp.value.trim();
                return v ? (matchKnown(v) || v) : '';
            },
            render(current) {
                const val = (current || '').trim();
                rebuildOptions(val);
                syncing = true;
                inp.value = val;
                sel.value = val ? (matchKnown(val) || '__custom__') : '';
                syncing = false;
                paintHint();
            }
        };
    }

    // --- Salida de la tienda (out-date): steppers por rotación, datepicker
    // UTC y estado 'Indefinido'. Estado: { date: 'YYYY-MM-DD'|null, today }.
    // Serializa al contrato de la API: <fecha>T23:59:59.999Z (fin de la
    // rotación de ese día); el backend retira el objeto al pasar esa hora.
    function makeOutDateControl(cfg) {
        const $ = (id) => document.getElementById(id);
        let st = null;
        function paint() {
            if (!st) return;
            const label = $(cfg.label), inp = $(cfg.date);
            $(cfg.never).setAttribute('aria-checked', st.date === null ? 'true' : 'false');
            inp.min = st.today;
            inp.value = st.date || '';
            if (st.date === null) {
                label.textContent = 'Sin fecha de salida (Indefinido): permanecerá en la tienda hasta que lo cambies.';
            } else {
                const n = dlDaysBetweenUtc(st.today, st.date);
                label.textContent = n <= 0
                    ? 'Saldrá en la rotación del ' + st.date + ' UTC (hoy: desaparecerá al aplicar los cambios).'
                    : 'Saldrá en la rotación del ' + st.date + ' UTC ('
                        + (n === 1 ? 'la próxima rotación' : 'quedan ' + n + ' rotaciones') + ').';
            }
        }
        function bump(days) {
            if (!st) return;
            const base = st.date || st.today;
            let next = dlAddDaysUtc(base, days);
            if (next < st.today) next = st.today;
            st.date = next;
            paint();
            if (cfg.onChange) cfg.onChange();
        }
        $(cfg.minus).addEventListener('click', () => bump(-1));
        $(cfg.plus).addEventListener('click', () => bump(1));
        $(cfg.plus2).addEventListener('click', () => bump(2));
        $(cfg.plus7).addEventListener('click', () => bump(7));
        $(cfg.never).addEventListener('click', () => {
            if (!st) return;
            st.date = st.date === null ? dlAddDaysUtc(st.today, 1) : null;
            paint();
            if (cfg.onChange) cfg.onChange();
        });
        $(cfg.manual).addEventListener('click', () => {
            const inp = $(cfg.date);
            const show = inp.style.display === 'none';
            $(cfg.manual).setAttribute('aria-checked', show ? 'true' : 'false');
            inp.style.display = show ? '' : 'none';
            if (show) inp.focus();
        });
        $(cfg.date).addEventListener('change', () => {
            if (!st) return;
            const v = $(cfg.date).value;
            if (/^\d{4}-\d{2}-\d{2}$/.test(v)) {
                st.date = v < st.today ? st.today : v;
                paint();
                if (cfg.onChange) cfg.onChange();
            }
        });
        return {
            get state() { return st; },
            // ISO completo para persistir, o '' si es indefinido.
            get iso() { return st && st.date ? st.date + 'T23:59:59.999Z' : ''; },
            render(effectiveIso, today) {
                const t = today || new Date().toISOString().slice(0, 10);
                const m = typeof effectiveIso === 'string' ? effectiveIso.match(/^(\d{4}-\d{2}-\d{2})/) : null;
                st = { date: m ? m[1] : null, today: t };
                $(cfg.manual).setAttribute('aria-checked', 'false');
                $(cfg.date).style.display = 'none';
                paint();
            }
        };
    }

    const editBanner = makeBannerControl({
        seg: 'dl-banner-seg', detail: 'dl-banner-detail', text: 'f-banner-text',
        expMinus: 'dl-banner-exp-minus', expPlus: 'dl-banner-exp-plus', expPlus7: 'dl-banner-exp-plus7',
        expNever: 'dl-banner-exp-never', expManual: 'dl-banner-exp-manual',
        expDate: 'f-banner-exp', expLabel: 'dl-banner-exp-label',
        apiHint: 'dl-banner-api-hint', previewEl: 'dl-modal-banner',
        onChange: () => updateDirtyUI()
    });
    const editSet = makeSetControl({
        select: 'f-set-select', input: 'f-set', hint: 'dl-set-hint',
        onChange: () => updateDirtyUI()
    });
    const editOut = makeOutDateControl({
        minus: 'dl-out-minus', plus: 'dl-out-plus', plus2: 'dl-out-plus2', plus7: 'dl-out-plus7',
        never: 'dl-out-never', manual: 'dl-out-manual', date: 'f-out-date', label: 'dl-out-label',
        onChange: () => updateDirtyUI()
    });

    function paintTileUI() {
        if (!tileState) return;
        document.querySelectorAll('#dl-tile-seg .dlm-seg-btn').forEach(b =>
            b.setAttribute('aria-checked', parseInt(b.dataset.span, 10) === tileState.span ? 'true' : 'false'));
    }
    function renderTileEditor(effective, existingOverrides) {
        const savedRaw = existingOverrides && existingOverrides.tileSize
            ? String(existingOverrides.tileSize.field_value) : '';
        const m = savedRaw.match(/^Size_([1-4])_x_1$/);
        const span = m ? parseInt(m[1], 10)
            : Math.max(1, Math.min(4, parseInt((effective && effective.tile_span) || 1, 10) || 1));
        tileState = { span };
        paintTileUI();
    }
    document.querySelectorAll('#dl-tile-seg .dlm-seg-btn').forEach(b => b.addEventListener('click', () => {
        if (!tileState) return;
        tileState.span = parseInt(b.dataset.span, 10);
        paintTileUI();
        updateDirtyUI();
    }));

    // --- offerId: free-text edit + quick random generator (v2:/ + 64 hex) ---
    document.getElementById('dl-gen-offerid').addEventListener('click', () => {
        const bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        document.getElementById('f-offerId').value =
            'v2:/' + Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        updateDirtyUI();
    });

    // --- Dirty tracking: field snapshot on open + live pending-changes
    // counter in the sticky footer; Save is disabled with no changes. ---
    let initialState = null;
    const DL_TRACKED_FIELDS = ['f-displayName', 'f-precio_base_soles', 'f-description',
        'f-vbucks', 'f-color1', 'f-color2', 'f-color3', 'f-offerId', 'f-introduction'];
    function snapshotState() {
        const s = {};
        DL_TRACKED_FIELDS.forEach(id => { s[id] = document.getElementById(id).value.trim(); });
        s.__type = typeCombo.get();
        s.__rarity = rarityCombo.get();
        const bSt = editBanner.state;
        s.__bannerMode = bSt ? bSt.mode : '';
        s.__bannerText = bSt ? (bSt.text || '').trim() : '';
        s.__bannerExp = bSt ? String(bSt.expiresOn) : '';
        s.__tileSpan = tileState ? tileState.span : 0;
        s.__set = editSet.get();
        const oSt = editOut.state;
        s.__outDate = oSt ? String(oSt.date) : '';
        return s;
    }
    function dirtyCount() {
        if (!initialState) return 0;
        let n = 0;
        const now = snapshotState();
        for (const k in initialState) if (now[k] !== initialState[k]) n++;
        if (imageState && imageState.touched.size) n++;
        if (diffState && diffState.dirty) n++;
        if (styleMap && styleMap.dirty) n++;
        return n;
    }
    function updateDirtyUI() {
        const n = dirtyCount();
        const el = document.getElementById('dl-dirty-count');
        el.textContent = n === 0 ? 'Sin cambios' : (n === 1 ? '1 cambio sin guardar' : n + ' cambios sin guardar');
        el.classList.toggle('has', n > 0);
        document.getElementById('dl-save-item').disabled = n === 0;
        // "Guardar y siguiente" siempre permite avanzar (guarda solo si hay cambios).
        document.getElementById('dl-save-next').disabled = false;
    }
    const dlmBody = document.querySelector('#dl-item-modal .dlm-body');
    dlmBody.addEventListener('input', updateDirtyUI);
    dlmBody.addEventListener('change', updateDirtyUI);
    ['f-color1', 'f-color2', 'f-color3'].forEach(id =>
        document.getElementById(id).addEventListener('input', updatePreviewGradient));
    document.getElementById('f-rarity-select').addEventListener('change', updatePreviewGradient);
    document.getElementById('f-rarity').addEventListener('input', updatePreviewGradient);

    // --- Per-field chips on each label ([data-ov="<field_key>"]): three
    // states derived from server overrides + draft store:
    //   'override'     persisted → × stages its deletion (undoable)
    //   'pendiente'    staged upsert → × discards the staged change
    //   'se eliminará' staged delete (o delete-all) → × discards it ---
    function renderOverrideChips(ov) {
        const modal = document.getElementById('dl-item-modal');
        modal.querySelectorAll('.dlm-chip').forEach(c => c.remove());
        const st = DlDraftStore.get(currentOfferId);
        modal.querySelectorAll('[data-ov]').forEach(el => {
            const key = el.dataset.ov;
            const op = st ? st.ops[key] : null;
            const persisted = !!(ov && ov[key]);
            let cls, text, title, action;
            if (op && op.op === 'upsert') {
                cls = 'staged'; text = 'pendiente'; title = 'Descartar este cambio pendiente';
                action = () => dlCommit('Descartar cambio pendiente de «' + key + '»', () => DlDraftStore.unstage(currentOfferId, key));
            } else if ((op && op.op === 'delete') || (st && st.deleteAll && persisted)) {
                cls = 'staged-del'; text = 'se eliminará'; title = 'Cancelar la eliminación pendiente';
                action = (op && op.op === 'delete')
                    ? () => dlCommit('Cancelar eliminación pendiente de «' + key + '»', () => DlDraftStore.unstage(currentOfferId, key))
                    : () => dlCommit('Cancelar eliminación total pendiente', () => DlDraftStore.unstage(currentOfferId, null));
            } else if (persisted) {
                cls = ''; text = 'override'; title = 'Marcar este override para eliminación (se aplica con "Aplicar cambios")';
                action = () => {
                    if (!confirm('¿Marcar el override «' + key + '» para eliminación? Se aplicará con "Aplicar cambios".')) return;
                    dlCommit('Eliminar override «' + key + '»', () => DlDraftStore.stageDelete(currentOfferId, key));
                };
            } else {
                return;
            }
            const chip = document.createElement('span');
            chip.className = 'dlm-chip' + (cls ? ' ' + cls : '');
            chip.innerHTML = text + ' <button type="button" title="' + esc(title) + '" aria-label="' + esc(title + ' (' + key + ')') + '">×</button>';
            chip.querySelector('button').addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                action();
            });
            el.appendChild(chip);
        });
    }

    let currentOfferId = null; // ID original: ancla del override (reversible)
    let modalStagedJson = null; // staged entry visto por el modal abierto (resync)
    async function openItem(offerId) {
        const src = document.getElementById('dl-editor-src').value;
        // Staged creations don't exist server-side yet: the backend builds the
        // same edit view-model from the staged template (dry run, no persist).
        const cr = DlDraftStore.getCreate(offerId);
        const r = cr
            ? await api('preview_custom_entry', { offer_id: offerId, template: cr.template, fields: JSON.stringify(cr.fields) }, 'POST')
            : await api('get_item', { offerId, src });
        if (r.status !== 'success') return;
        const it = r.item;
        const eff = r.effective || {};
        const ov = r.overrides || {};
        currentOfferId = r.anchor_offer_id || offerId;

        // Overlay del borrador: los valores staged se prefillan como efectivos
        // y los sub-editores (imágenes/dificultad/estilos) reciben un mapa de
        // overrides sintetizado con el draft aplicado encima del servidor.
        const st = DlDraftStore.get(currentOfferId);
        const sv = (k) => {
            const op = st ? st.ops[k] : null;
            return (op && op.op === 'upsert') ? op.field_value : null;
        };
        const svJson = (k) => {
            const raw = sv(k);
            if (raw == null) return null;
            try { return JSON.parse(raw); } catch (e) { return null; }
        };
        const ovForInit = {};
        for (const k in ov) {
            const op = st ? st.ops[k] : null;
            if ((st && st.deleteAll) || (op && op.op === 'delete')) continue;
            ovForInit[k] = ov[k];
        }
        if (st) {
            for (const k in st.ops) {
                if (st.ops[k].op === 'upsert') ovForInit[k] = { field_key: k, field_value: st.ops[k].field_value, value_type: st.ops[k].value_type };
            }
        }

        document.getElementById('dl-modal-title').textContent = sv('displayName') || it.displayName || 'Editar ítem';
        document.getElementById('dl-modal-offerid').textContent = currentOfferId;
        document.getElementById('f-offerId').value = sv('offerId') || it.offerId || currentOfferId;
        // Prefill with current effective values (so editing is intuitive).
        document.getElementById('f-displayName').value = sv('displayName') || it.displayName || '';
        document.getElementById('f-precio_base_soles').value = sv('precio_base_soles') || it.precio_base_soles || '';
        typeCombo.set(sv('displayType') || it.displayTypeOverride || it.displayType || '');
        document.getElementById('f-description').value = sv('description') || eff.description || '';
        const stRarity = svJson('rarity');
        rarityCombo.set((stRarity && stRarity.id) || (it.rarity && it.rarity.id) || '');
        document.getElementById('f-vbucks').value = sv('vbucks') || it.vbucks || '';
        const stColors = svJson('colors') || {};
        document.getElementById('f-color1').value = stColors.color1 || (it.colors && it.colors.color1) || '';
        document.getElementById('f-color2').value = stColors.color2 || (it.colors && it.colors.color2) || '';
        document.getElementById('f-color3').value = stColors.color3 || (it.colors && it.colors.color3) || '';
        initImageEditor(eff, ovForInit);
        renderDifficultyEditor(eff, ovForInit);
        editBanner.render(eff, ovForInit);
        renderTileEditor(eff, ovForInit);
        const stSet = svJson('set');
        editSet.render(stSet !== null && typeof stSet === 'object' ? (stSet.value || '') : (sv('set') === 'null' ? '' : (eff.set_value || '')));
        const stIntro = svJson('introduction');
        document.getElementById('f-introduction').value =
            (stIntro && typeof stIntro.text === 'string') ? stIntro.text : (sv('introduction') === 'null' ? '' : (eff.introduction || ''));
        editOut.render(sv('outDate') !== null ? sv('outDate') : (eff.out_date || ''), eff.today_utc);
        renderOverrideChips(ov);
        renderStyleMapper(it, ovForInit);
        updatePreviewGradient();
        initialState = snapshotState();
        updateDirtyUI();
        modalStagedJson = JSON.stringify(st);
        document.getElementById('dl-item-modal').dataset.offerId = currentOfferId;
        dlDeferShowInEditModal(it.isCustomEntry || it.is_custom);
        openModal('dl-item-modal');
        autosizeDesc();
    }

    // ---------- Estilos → render (styleRenderMap) ----------
    // Asigna a cada estilo (variante) la imagen grande de tienda que le
    // corresponde. Se guarda como override `styleRenderMap` ({"canal:tag":
    // índice de render no-LEGO}) y la DAL lo resuelve a URLs (`styleImages`)
    // que el storefront prioriza sobre el orden posicional de renderImages.
    let styleMap = null; // { map: {styleKey: idx}, dirty: bool } | null

    // Clave estable de un estilo: "canal:tag" (misma convención que app.js).
    function dlStyleKey(channel, tag) {
        const c = String(channel || '').toLowerCase().replace(/[^a-z0-9_.-]/g, '');
        const t = String(tag || '').toLowerCase().replace(/[^a-z0-9_.-]/g, '');
        return (c && t) ? c + ':' + t : '';
    }

    function renderStyleMapper(it, existingOverrides) {
        const wrap = document.getElementById('dl-styles-wrap');
        const rowsEl = document.getElementById('dl-styles-rows');
        styleMap = null;
        wrap.style.display = 'none';
        rowsEl.innerHTML = '';
        const main = (!it.bundle && Array.isArray(it.brItems) && it.brItems[0]) || null;
        const channel = (main && Array.isArray(main.variants)) ? main.variants[0] : null;
        const options = (channel && Array.isArray(channel.options) && channel.options.length > 1) ? channel.options : null;
        const renders = (((it.newDisplayAsset || {}).renderImages) || []).filter(r => r && r.image && r.productTag !== 'Product.Juno');
        if (!options || !renders.length) return;
        let saved = null;
        try {
            saved = existingOverrides && existingOverrides.styleRenderMap
                ? JSON.parse(existingOverrides.styleRenderMap.field_value) : null;
        } catch (e) { saved = null; }
        const map = {};
        rowsEl.innerHTML = options.map((opt, i) => {
            const key = dlStyleKey(channel.channel, opt.tag);
            if (!key) return '';
            const savedIdx = (saved && typeof saved[key] === 'number') ? saved[key] : -1;
            const idx = renders[savedIdx] ? savedIdx : (renders[i] ? i : -1);
            if (idx >= 0) map[key] = idx;
            return `<div class="dl-style-row" data-key="${esc(key)}">
                <img class="bust" loading="lazy" src="${esc(opt.image || '')}" onerror="this.style.opacity=.2">
                <div class="snm">${esc(opt.name || key)}</div>
                <div class="dl-style-thumbs">${renders.map((r, ri) => `<img class="dl-style-thumb${ri === idx ? ' sel' : ''}" loading="lazy" data-ri="${ri}" src="${esc(r.image)}" title="Imagen ${ri + 1}">`).join('')}</div>
            </div>`;
        }).join('');
        styleMap = { map, dirty: false };
        rowsEl.querySelectorAll('.dl-style-thumb').forEach(t => t.addEventListener('click', () => {
            const row = t.closest('.dl-style-row');
            row.querySelectorAll('.dl-style-thumb').forEach(x => x.classList.remove('sel'));
            t.classList.add('sel');
            styleMap.map[row.dataset.key] = parseInt(t.dataset.ri, 10);
            styleMap.dirty = true;
            updateDirtyUI();
        }));
        wrap.style.display = '';
    }

    document.getElementById('dl-styles-reset').addEventListener('click', () => {
        if (!currentOfferId || !confirm('¿Marcar el override de estilos para eliminación? Se aplicará con "Aplicar cambios".')) return;
        dlCommit('Eliminar override de estilos', () => DlDraftStore.stageDelete(currentOfferId, 'styleRenderMap'));
        toast('Eliminación de estilos añadida al borrador.', 'info');
    });
    // Orden visual actual del editor (para "Guardar y siguiente").
    function dlVisibleOrder() {
        return Array.from(document.querySelectorAll('#dl-editor-grid .item-card-wrapper[data-offerid]'))
            .map(c => c.dataset.offerid);
    }
    function saveModalChanges(openNext) {
        if (!currentOfferId) return;
        // Only fields changed since the modal opened become override jobs.
        const jobs = [];
        const v = (id) => document.getElementById(id).value.trim();
        const changed = (id) => !initialState || v(id) !== initialState[id];
        if (changed('f-displayName') && v('f-displayName')) jobs.push({ field_key: 'displayName', field_value: v('f-displayName'), value_type: 'string' });
        if (changed('f-precio_base_soles') && v('f-precio_base_soles')) jobs.push({ field_key: 'precio_base_soles', field_value: v('f-precio_base_soles'), value_type: 'number' });
        const typeVal = typeCombo.get();
        if (typeVal && (!initialState || typeVal !== initialState.__type)) jobs.push({ field_key: 'displayType', field_value: typeVal, value_type: 'string' });
        if (changed('f-description') && v('f-description')) jobs.push({ field_key: 'description', field_value: v('f-description'), value_type: 'string' });
        const rarityVal = rarityCombo.get();
        if (rarityVal && (!initialState || rarityVal !== initialState.__rarity)) jobs.push({ field_key: 'rarity', field_value: JSON.stringify({ id: rarityVal, name: rarityNameById(rarityVal) }), value_type: 'json' });
        if (changed('f-vbucks') && v('f-vbucks')) jobs.push({ field_key: 'vbucks', field_value: v('f-vbucks'), value_type: 'number' });
        if ((changed('f-color1') || changed('f-color2') || changed('f-color3')) && (v('f-color1') || v('f-color2') || v('f-color3'))) jobs.push({ field_key: 'colors', field_value: JSON.stringify({ color1: v('f-color1'), color2: v('f-color2'), color3: v('f-color3') }), value_type: 'json' });
        const newOfferId = v('f-offerId');
        if (changed('f-offerId') && newOfferId && newOfferId !== currentOfferId) jobs.push({ field_key: 'offerId', field_value: newOfferId, value_type: 'string' });
        if (imageState && imageState.touched.size) {
            const imgs = {};
            imageState.dirty.forEach(k => { if (imageState.values[k]) imgs[k] = imageState.values[k]; });
            if (Object.keys(imgs).length) jobs.push({ field_key: 'images', field_value: JSON.stringify(imgs), value_type: 'json' });
        }
        if (diffState && diffState.dirty) jobs.push({ field_key: 'difficulty', field_value: JSON.stringify(diffState.levels), value_type: 'json' });
        if (styleMap && styleMap.dirty) jobs.push({ field_key: 'styleRenderMap', field_value: JSON.stringify(styleMap.map), value_type: 'json' });
        // Conjunto: '' con valor previo = quitar pertenencia (override null
        // explícito para anular el set nativo de la API); '' sin valor previo
        // = sin cambio. Con valor = asignar/modificar.
        const setVal = editSet.get();
        if (initialState && setVal !== initialState.__set) {
            jobs.push({ field_key: 'set', field_value: setVal ? JSON.stringify({ value: setVal }) : 'null', value_type: 'json' });
        }
        const introVal = v('f-introduction');
        if (changed('f-introduction')) {
            jobs.push({ field_key: 'introduction', field_value: introVal ? JSON.stringify({ text: introVal }) : 'null', value_type: 'json' });
        }
        // Salida de la tienda: fecha ISO completa; '' = sin fecha (Indefinido),
        // que también anula un outDate nativo de la API.
        const oSt = editOut.state;
        if (oSt && initialState && String(oSt.date) !== initialState.__outDate) {
            jobs.push({ field_key: 'outDate', field_value: editOut.iso, value_type: 'string' });
        }
        const deletes = [];
        const bSt = editBanner.state;
        if (bSt && initialState) {
            const bChanged = bSt.mode !== initialState.__bannerMode
                || (bSt.mode !== '' && (String(bSt.expiresOn) !== initialState.__bannerExp
                    || (bSt.mode === 'on' && (bSt.text || '').trim() !== initialState.__bannerText)));
            if (bChanged) {
                if (bSt.mode === '') {
                    // Volver a 'Automático' = eliminar el override existente.
                    if (bSt.hadOverride) deletes.push('banner');
                } else {
                    jobs.push({
                        field_key: 'banner',
                        field_value: JSON.stringify({
                            mode: bSt.mode,
                            text: (bSt.text || 'Nuevo').trim() || 'Nuevo',
                            expiresOn: bSt.expiresOn
                        }),
                        value_type: 'json'
                    });
                }
            }
        }
        if (tileState && initialState && tileState.span !== initialState.__tileSpan) {
            jobs.push({ field_key: 'tileSize', field_value: 'Size_' + tileState.span + '_x_1', value_type: 'string' });
        }
        const nextId = openNext ? (() => {
            const order = dlVisibleOrder();
            const idx = order.indexOf(currentOfferId);
            return idx >= 0 && idx + 1 < order.length ? order[idx + 1] : null;
        })() : null;
        if (!jobs.length && !deletes.length) {
            if (openNext && nextId) { closeModals(true); openItem(nextId); return; }
            toast('No hay cambios para guardar.', 'warning');
            return;
        }
        // Staging: los jobs van al borrador (undoable), no a la API. Se cierra
        // el modal ANTES de mutar el store para que la resincronización del
        // observer no lo reabra.
        const anchor = currentOfferId;
        const name = document.getElementById('dl-modal-title').textContent || anchor;
        closeModals(true);
        dlCommit('Guardar cambios de «' + name + '» (' + (jobs.length + deletes.length) + ' campos)', () => {
            if (jobs.length) DlDraftStore.stageUpserts(anchor, jobs);
            deletes.forEach(k => DlDraftStore.stageDelete(anchor, k));
        });
        toast('Cambios añadidos al borrador. Usa "Aplicar cambios" para publicarlos.', 'success');
        if (openNext && nextId) openItem(nextId);
    }
    document.getElementById('dl-save-item').addEventListener('click', () => saveModalChanges(false));
    document.getElementById('dl-save-next').addEventListener('click', () => saveModalChanges(true));
    document.getElementById('dl-delete-overrides').addEventListener('click', () => {
        if (!currentOfferId || !confirm('¿Marcar TODOS los overrides de este ítem para eliminación? Se aplicará con "Aplicar cambios".')) return;
        const anchor = currentOfferId;
        const name = document.getElementById('dl-modal-title').textContent || anchor;
        closeModals(true);
        dlCommit('Quitar todos los overrides de «' + name + '»', () => DlDraftStore.stageDelete(anchor, null));
        toast('Eliminación total añadida al borrador.', 'info');
    });

    // =====================================================================
    //  CREACIÓN DE SECCIONES Y OBJETOS (staged, deshacible)
    // =====================================================================
    // Namespace propio para IDs manuales: nunca colisiona con los offerId
    // reales de la API (v2:/<hash>) ni con los layout.id del snapshot.
    function dlRandomHex(n) {
        const bytes = new Uint8Array(n);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    }
    const dlNewOfferId = () => 'v2:/dlcustom' + dlRandomHex(12);
    const dlNewLayoutBaseId = () => 'DLCustom' + dlRandomHex(4);

    // Secciones efectivas (snapshot + borrador), ordenadas rank DESC.
    function dlAllSections() {
        const map = new Map();
        lastSections.forEach(s => map.set(s.name, { id: s.id, name: s.name, rank: Number(s.rank) || 0 }));
        DlDraftStore.listSections().forEach(s => { if (!map.has(s.name)) map.set(s.name, s); });
        return Array.from(map.values()).sort((a, b) => b.rank - a.rank);
    }
    function dlSectionMetaByName(name) {
        const found = dlAllSections().find(s => s.name === name);
        if (found) return found;
        // Sección solo presente vía overrides staged: recupérala del grid.
        const el = document.querySelector(`#dl-editor-grid .dl-section[data-section-name="${(window.CSS && CSS.escape) ? CSS.escape(name) : name}"]`);
        return el && el.dataset.sectionId
            ? { id: el.dataset.sectionId, name, rank: parseInt(el.dataset.sectionRank, 10) || 0 }
            : null;
    }
    // Mayor sufijo de layoutId en uso dentro de una sección (orden = sufijo DESC).
    function dlMaxSuffix(sectionName) {
        let max = 0;
        lastItems.concat(DlDraftStore.creations().map(c => dlDraftCard(c.anchor, c.create))).forEach(i => {
            const eff = dlEffOrder(i);
            if (eff.sectionName === sectionName && eff.suffix > max) max = eff.suffix;
        });
        return max;
    }

    // ---- Nueva sección ----
    document.getElementById('dl-new-section').addEventListener('click', () => {
        const sel = document.getElementById('ns-position');
        const secs = dlAllSections();
        sel.innerHTML = '<option value="top">Al inicio de la tienda</option>'
            + secs.map((s, i) => `<option value="${i}">Después de «${esc(s.name)}»</option>`).join('');
        document.getElementById('ns-name').value = '';
        openModal('dl-section-modal');
        document.getElementById('ns-name').focus();
    });
    document.getElementById('ns-create').addEventListener('click', async () => {
        const name = document.getElementById('ns-name').value.trim();
        if (!name) { toast('Escribe un nombre para la sección.', 'warning'); return; }
        if (dlAllSections().some(s => dlNorm(s.name) === dlNorm(name))) {
            toast('Ya existe una sección con ese nombre.', 'warning');
            return;
        }

        // --- Diferir a API: verificar coincidencia antes de crear ---
        const nsDeferCheck = document.getElementById('ns-defer-check');
        if (nsDeferCheck && nsDeferCheck.checked) {
            const deferResult = await dlDeferPreCheck('ns', () => ({
                offer_id: dlNewOfferId(), // placeholder
                display_name: '',
                section_name: name,
                item_type: '',
                colors: null,
                image_url: '',
                rarity: null,
                set_value: '',
                vbucks: null,
                banner: null,
                tileSize: null
            }));
            if (deferResult.skip) {
                closeModals(true);
                toast('Coincidencia encontrada en la API: «' + deferResult.matchedName + '». No se creó la sección manual.', 'success');
                return;
            }
        }
        const secs = dlAllSections();
        const pos = document.getElementById('ns-position').value;
        let rank;
        if (pos === 'top' || !secs.length) {
            rank = secs.length ? secs[0].rank + 10 : 100;
        } else {
            const i = parseInt(pos, 10);
            const above = secs[i].rank;
            const below = (i + 1 < secs.length) ? secs[i + 1].rank : above - 20;
            rank = Math.floor((above + below) / 2);
            if (rank >= above) rank = above - 1;
        }
        const section = { id: dlNewLayoutBaseId(), name, rank };
        closeModals(true);
        dlCommit('Crear sección «' + name + '»', () => DlDraftStore.addSection(section));
        toast('Sección creada en borrador. Añádele objetos con "+ Añadir objeto".', 'success');
    });

    // ---- Nuevo objeto (por plantilla) ----
    // Image slots per creation template: exact mirror of the RAW mapping the
    // backend applies in dl_build_custom_entry (no guessed keys).
    const DL_TPL_SLOTS = {
        br: [
            { key: 'icon', label: 'Icon del ítem (images.icon)', hint: 'Icono usado en tarjetas y como contenido de otros lotes.' },
            { key: 'featured', label: 'Destacada (images.featured)', hint: 'Imagen destacada: tiene prioridad sobre el icon en tarjetas y hero.' }
        ],
        bundle: [
            { key: 'bundleImage', label: 'Imagen del lote (bundle.image)', hint: 'Portada del lote en la tarjeta y en su página.' }
        ],
        track: [
            { key: 'albumArt', label: 'Portada (tracks[0].albumArt)', hint: 'Carátula de la pista: tarjeta, hero y contenidos.' }
        ],
        instrument: [
            { key: 'small', label: 'Imagen pequeña (images.small)', hint: 'Miniatura del instrumento en tarjetas y contenidos.' },
            { key: 'large', label: 'Imagen grande (images.large)', hint: 'Imagen grande del instrumento en su página.' }
        ],
        car: [
            { key: 'small', label: 'Imagen pequeña (images.small)', hint: 'Miniatura del vehículo en tarjetas y contenidos.' },
            { key: 'large', label: 'Imagen grande (images.large)', hint: 'Imagen grande del vehículo en su página.' }
        ]
    };
    const ncRarityCombo = makeCombo('nc-rarity-select', 'nc-rarity',
        DL_RARITIES.map(r => ({ value: r.id, label: r.name })), '— Por defecto (Común) —');
    const createBanner = makeBannerControl({
        seg: 'nc-banner-seg', detail: 'nc-banner-detail', text: 'nc-banner-text',
        expMinus: 'nc-banner-exp-minus', expPlus: 'nc-banner-exp-plus', expPlus7: 'nc-banner-exp-plus7',
        expNever: 'nc-banner-exp-never', expManual: 'nc-banner-exp-manual',
        expDate: 'nc-banner-exp', expLabel: 'nc-banner-exp-label'
    });
    const ncSet = makeSetControl({ select: 'nc-set-select', input: 'nc-set', hint: 'nc-set-hint' });
    const ncOut = makeOutDateControl({
        minus: 'nc-out-minus', plus: 'nc-out-plus', plus2: 'nc-out-plus2', plus7: 'nc-out-plus7',
        never: 'nc-out-never', manual: 'nc-out-manual', date: 'nc-out-date', label: 'nc-out-label'
    });
    function ncUpdateGradient() {
        const v = (id) => document.getElementById(id).value;
        dlPaintGradient(document.getElementById('nc-grad-preview'),
            v('nc-color1'), v('nc-color2'), v('nc-color3'), ncRarityCombo.get());
    }
    ['nc-color1', 'nc-color2', 'nc-color3', 'nc-rarity'].forEach(id =>
        document.getElementById(id).addEventListener('input', ncUpdateGradient));
    document.getElementById('nc-rarity-select').addEventListener('change', ncUpdateGradient);

    // Per-slot state of the creation modal: every slot has its own URL input,
    // local upload control and live preview, all visible simultaneously.
    let ncSlotState = { values: {}, uploaders: [] };
    function ncUpdateCreateBusy() {
        const busy = ncSlotState.uploaders.some(u => u.busy);
        document.getElementById('nc-create').disabled = busy;
        document.getElementById('nc-create-edit').disabled = busy;
    }
    function ncRenderImageSlots(template) {
        ncSlotState.uploaders.forEach(u => { u.reset(); dlUploaderRegistry.delete(u); });
        ncSlotState = { values: {}, uploaders: [] };
        const wrap = document.getElementById('nc-img-slots');
        const slots = DL_TPL_SLOTS[template] || [];
        wrap.innerHTML = slots.map(s => `
            <div class="nc-slot-card" data-slot="${esc(s.key)}">
                <label for="nc-img-${esc(s.key)}">${esc(s.label)}</label>
                <input id="nc-img-${esc(s.key)}" class="dl-input nc-slot-url" type="text" placeholder="https://…" autocomplete="off">
                <div class="dl-upload-row">
                    <button type="button" class="dl-upload-btn nc-slot-upload"><span class="dl-upload-spinner" aria-hidden="true"></span>Subir imagen…</button>
                    <input type="file" class="nc-slot-file" accept=".png,.webp,.jpg,.jpeg,image/png,image/webp,image/jpeg" hidden aria-label="Seleccionar imagen local para ${esc(s.label)}">
                    <div class="dl-upload-progress" aria-hidden="true"><span></span></div>
                </div>
                <p class="dl-upload-msg" role="alert" aria-live="polite"></p>
                <div class="nc-slot-preview-box">
                    <img class="nc-slot-preview" alt="Vista previa: ${esc(s.label)}" style="display:none;">
                    <span class="nc-slot-preview-empty">Sin imagen</span>
                </div>
                <p class="dlm-hint">${esc(s.hint)}</p>
            </div>`).join('');
        wrap.querySelectorAll('.nc-slot-card').forEach(card => {
            const key = card.dataset.slot;
            const url = card.querySelector('.nc-slot-url');
            const img = card.querySelector('.nc-slot-preview');
            const empty = card.querySelector('.nc-slot-preview-empty');
            const syncPreview = () => {
                const v = url.value.trim();
                ncSlotState.values[key] = v;
                if (v) { img.src = v; img.style.display = ''; empty.style.display = 'none'; }
                else { img.removeAttribute('src'); img.style.display = 'none'; empty.style.display = ''; }
            };
            const uploader = dlCreateUploader({
                button: card.querySelector('.nc-slot-upload'),
                fileInput: card.querySelector('.nc-slot-file'),
                msgEl: card.querySelector('.dl-upload-msg'),
                barEl: card.querySelector('.dl-upload-progress'),
                onSuccess: (u) => { url.value = u; syncPreview(); },
                onBusyChange: ncUpdateCreateBusy
            });
            url.addEventListener('input', () => { uploader.clearMsg(); syncPreview(); });
            ncSlotState.uploaders.push(uploader);
        });
        ncUpdateCreateBusy();
    }
    function ncSelectedTemplate() {
        const tplBtn = document.querySelector('#nc-templates .dl-tpl-btn.sel');
        return tplBtn ? tplBtn.dataset.tpl : 'br';
    }
    let createTargetSection = null;
    function dlOpenCreateModal(sectionName) {
        createTargetSection = dlSectionMetaByName(sectionName);
        if (!createTargetSection) { toast('No se pudo resolver la sección destino.', 'error'); return; }
        document.getElementById('nc-section-name').textContent = createTargetSection.name;
        document.getElementById('nc-name').value = '';
        document.getElementById('nc-vbucks').value = '';
        document.getElementById('nc-offerId').value = dlNewOfferId();
        ncRarityCombo.set('');
        ['nc-color1', 'nc-color2', 'nc-color3'].forEach(id => { document.getElementById(id).value = ''; });
        createBanner.render(null, null);
        ncSet.render('');
        document.getElementById('nc-introduction').value = '';
        ncOut.render('');
        ncRenderImageSlots(ncSelectedTemplate());
        ncUpdateGradient();
        openModal('dl-create-modal');
        document.getElementById('nc-name').focus();
    }
    document.getElementById('nc-gen-offerid').addEventListener('click', () => {
        document.getElementById('nc-offerId').value = dlNewOfferId();
    });
    document.querySelectorAll('#nc-templates .dl-tpl-btn').forEach(b => b.addEventListener('click', () => {
        document.querySelectorAll('#nc-templates .dl-tpl-btn').forEach(x => {
            x.classList.toggle('sel', x === b);
            x.setAttribute('aria-checked', x === b ? 'true' : 'false');
        });
        document.getElementById('nc-brtype-row').style.display = b.dataset.tpl === 'br' ? '' : 'none';
        ncRenderImageSlots(b.dataset.tpl);
    }));
    async function dlStageCreation(openEditor) {
        if (ncSlotState.uploaders.some(u => u.busy)) { toast('Espera a que termine la subida de imágenes.', 'warning'); return; }

        // --- Diferir a API: verificar coincidencia antes de crear ---
        const ncDeferCheck = document.getElementById('nc-defer-check');
        if (ncDeferCheck && ncDeferCheck.checked) {
            const template = ncSelectedTemplate();
            const name = document.getElementById('nc-name').value.trim();
            if (!name) { toast('Escribe un nombre para el objeto.', 'warning'); return; }
            const deferResult = await dlDeferPreCheck('nc', () => ({
                offer_id: document.getElementById('nc-offerId').value.trim(),
                display_name: name,
                section_name: createTargetSection ? createTargetSection.name : '',
                item_type: template === 'br' ? (DL_TYPE_LABELS[document.getElementById('nc-brtype').value] || 'Artículos') : { bundle: 'Lotes', track: 'Pistas de improvisación', instrument: 'Instrumentos', car: 'Coches' }[template] || 'Artículos',
                colors: (() => { const c = {}; ['nc-color1','nc-color2','nc-color3'].forEach((id,i) => { const v = document.getElementById(id).value.trim(); if (/^[0-9a-fA-F]{6,8}$/.test(v)) c['color'+(i+1)] = v; }); return Object.keys(c).length ? c : null; })(),
                image_url: Object.values(ncSlotState.values).find(v => v) || '',
                rarity: (() => { const v = ncRarityCombo.get(); return v ? { id: v, name: rarityNameById(v) } : null; })(),
                set_value: ncSet.get() || '',
                vbucks: parseInt(document.getElementById('nc-vbucks').value, 10) || 0,
                banner: null,
                tileSize: null
            }));
            if (deferResult.skip) {
                closeModals(true);
                toast('Coincidencia encontrada en la API: «' + deferResult.matchedName + '». No se creó el objeto manual.', 'success');
                return;
            }
        }

        const template = ncSelectedTemplate();
        const name = document.getElementById('nc-name').value.trim();
        if (!name) { toast('Escribe un nombre para el objeto.', 'warning'); return; }
        const vb = parseInt(document.getElementById('nc-vbucks').value, 10);
        const sec = createTargetSection;
        const offerId = document.getElementById('nc-offerId').value.trim() || dlNewOfferId();
        const validUrl = (u) => /^(https?:\/\/|\/)/i.test(u);
        const images = {};
        (DL_TPL_SLOTS[template] || []).forEach(s => {
            const v = (ncSlotState.values[s.key] || '').trim();
            if (validUrl(v)) images[s.key] = v;
        });
        const firstImage = Object.values(images)[0] || '';
        const fields = {
            display_name: name,
            vbucks: isFinite(vb) && vb > 0 ? vb : 0,
            image: firstImage,
            images,
            layout: { id: sec.id, name: sec.name, rank: sec.rank },
            layout_suffix: dlMaxSuffix(sec.name) + 1,
            sort_priority: 0
        };
        const rarityVal = ncRarityCombo.get();
        if (rarityVal) fields.rarity = { id: rarityVal, name: rarityNameById(rarityVal) };
        const colors = {};
        ['nc-color1', 'nc-color2', 'nc-color3'].forEach((id, i) => {
            const v = document.getElementById(id).value.trim();
            if (/^[0-9a-fA-F]{6,8}$/.test(v)) colors['color' + (i + 1)] = v;
        });
        if (Object.keys(colors).length) fields.colors = colors;
        if (template === 'br') fields.type_value = document.getElementById('nc-brtype').value;
        const setVal = ncSet.get();
        if (setVal) fields.set = { value: setVal };
        const introVal = document.getElementById('nc-introduction').value.trim();
        if (introVal) fields.introduction = { text: introVal };
        if (ncOut.iso) fields.out_date = ncOut.iso;
        const bSt = createBanner.state;
        const bannerJob = (bSt && bSt.mode !== '')
            ? {
                field_key: 'banner',
                field_value: JSON.stringify({
                    mode: bSt.mode,
                    text: (bSt.text || 'Nuevo').trim() || 'Nuevo',
                    expiresOn: bSt.expiresOn
                }),
                value_type: 'json'
            } : null;
        closeModals(true);
        dlCommit('Crear objeto «' + name + '» en «' + sec.name + '»', () => {
            DlDraftStore.stageCreate(offerId, template, fields);
            if (bannerJob) DlDraftStore.stageUpserts(offerId, [bannerJob]);
        });
        toast('Objeto creado en el borrador. Se publica con "Aplicar cambios".', 'success');
        if (openEditor) openItem(offerId);
    }
    document.getElementById('nc-create').addEventListener('click', () => dlStageCreation(false));
    document.getElementById('nc-create-edit').addEventListener('click', () => dlStageCreation(true));

    // =====================================================================
    //  DIFERIR A API — Comparación inteligente con datos de la API
    //  Permite que un objeto/sección creados desde cero desaparezcan del
    //  Data Layer si coinciden suficientemente con datos de la API activa.
    // =====================================================================

    // Toggle visibility of defer options when checkbox changes
    function dlDeferWire(checkId, optsId) {
        const chk = document.getElementById(checkId);
        const opts = document.getElementById(optsId);
        if (!chk || !opts) return;
        chk.addEventListener('change', () => opts.classList.toggle('open', chk.checked));
    }
    dlDeferWire('ns-defer-check', 'ns-defer-opts');
    dlDeferWire('nc-defer-check', 'nc-defer-opts');
    dlDeferWire('dl-defer-check', 'dl-defer-opts');

    // Score bar HTML helper
    function dlDeferScoreBar(label, score) {
        const cls = score >= 70 ? 'dl-defer-score-high' : (score >= 40 ? 'dl-defer-score-mid' : 'dl-defer-score-low');
        return `<div class="dl-defer-score-row">
            <span style="min-width:100px">${esc(label)}</span>
            <div class="dl-defer-score-bar ${cls}"><span style="width:${Math.round(score)}%"></span></div>
            <span style="min-width:35px; text-align:right">${Math.round(score)}%</span>
        </div>`;
    }

    // Render match result into a container element
    function dlDeferRenderResult(container, data) {
        if (!data || !data.matches || !data.matches.length) {
            container.className = 'dl-defer-result no-match';
            container.innerHTML = '❌ No se encontró ninguna coincidencia suficiente en la API.';
            container.style.display = '';
            return null;
        }
        const best = data.matches[0];
        const met = best.matched_fields >= (data.min_required || 1);
        container.className = 'dl-defer-result ' + (met ? 'match' : 'no-match');
        const fieldLabels = {
            displayName: 'Nombre', section_name: 'Sección', item_type: 'Tipo',
            colors: 'Colores', image: 'Imagen', rarity: 'Rareza',
            set_value: 'Conjunto', vbucks: 'Precio (paVos)', banner: 'Banner',
            tileSize: 'Tamaño de tile'
        };
        let html = met
            ? `✅ <b>Coincidencia encontrada:</b> «${esc(best.displayName)}» (${esc(best.section_name || '—')}) — ${best.matched_fields}/${best.total_fields} campos · Puntuación: ${Math.round(best.total_score)}%`
            : `⚠️ Mejor candidato: «${esc(best.displayName)}» — ${best.matched_fields}/${best.total_fields} campos (se requieren ${data.min_required || 1}). Puntuación: ${Math.round(best.total_score)}%`;
        html += '<div class="dl-defer-scores">';
        for (const [k, v] of Object.entries(best.field_scores || {})) {
            html += dlDeferScoreBar(fieldLabels[k] || k, v);
        }
        html += '</div>';
        if (data.matches.length > 1) {
            html += '<details style="margin-top:.4rem"><summary style="font-size:.65rem;cursor:pointer;color:var(--text-secondary)">'
                + (data.matches.length - 1) + ' candidatos más</summary>';
            data.matches.slice(1).forEach(m => {
                html += `<div style="font-size:.65rem;padding:.2rem 0;">«${esc(m.displayName)}» — ${m.matched_fields} campos · ${Math.round(m.total_score)}%</div>`;
            });
            html += '</details>';
        }
        container.innerHTML = html;
        container.style.display = '';
        return met ? best : null;
    }

    // Call the API to check for matches
    async function dlDeferCheckMatch(payload) {
        return await api('check_api_match', { payload: JSON.stringify(payload) }, 'POST');
    }

    async function dlDeferPreCheck(prefix, dataGetter) {
        const chk = document.getElementById(prefix + '-defer-check');
        if (!chk || !chk.checked) return { skip: false };
        const min = parseInt(document.getElementById(prefix + '-defer-min').value, 10) || 1;
        const backdrop = document.getElementById(prefix + '-defer-backdrop')?.checked ?? true;
        const video = document.getElementById(prefix + '-defer-video')?.checked ?? true;
        const payload = dataGetter();
        payload.min_matches = min;
        payload.inherit_backdrop = backdrop;
        payload.inherit_video = video;
        const container = document.getElementById(prefix + '-defer-result') || document.getElementById('nc-defer-result');
        if (container) {
            container.className = 'dl-defer-result checking';
            container.innerHTML = '🔍 Buscando coincidencias en la API…';
            container.style.display = '';
        }
        const r = await dlDeferCheckMatch(payload);
        if (r.status !== 'success') {
            if (container) {
                container.className = 'dl-defer-result no-match';
                container.innerHTML = '❌ Error al buscar coincidencias: ' + esc(r.message || 'Error desconocido');
                container.style.display = '';
            }
            return { skip: false };
        }
        r.min_required = min;
        const best = container ? dlDeferRenderResult(container, r) : (r.matches && r.matches[0] && r.matches[0].matched_fields >= min ? r.matches[0] : null);
        if (best) {
            // A match was found that meets the threshold
            return {
                skip: true,
                matchedOfferId: best.offerId,
                matchedName: best.displayName,
                inheritBackdrop: backdrop,
                inheritVideo: video
            };
        }
        return { skip: false };
    }

    // --- Edit modal: "Buscar coincidencia ahora" button ---
    document.getElementById('dl-defer-check-btn')?.addEventListener('click', async () => {
        const modal = document.getElementById('dl-item-modal');
        const offerId = modal.dataset.offerId || '';
        const item = lastItems.find(i => (i.anchor_offer_id || i.offerId) === offerId || i.offerId === offerId);
        if (!item) { toast('No se pudo resolver el ítem actual.', 'warning'); return; }
        const min = parseInt(document.getElementById('dl-defer-min').value, 10) || 1;
        const payload = {
            offer_id: offerId,
            display_name: item.displayName || '',
            section_name: item.section_name || '',
            item_type: item.item_type || '',
            colors: item.colors || null,
            image_url: item.image || '',
            rarity: item.rarity || null,
            set_value: '', // We'd need this from the full item data
            vbucks: item.vbucks != null ? Number(item.vbucks) : null,
            banner: item.banner || null,
            tileSize: null,
            min_matches: min,
            inherit_backdrop: document.getElementById('dl-defer-backdrop')?.checked ?? true,
            inherit_video: document.getElementById('dl-defer-video')?.checked ?? true
        };
        const container = document.getElementById('dl-defer-result');
        container.className = 'dl-defer-result checking';
        container.innerHTML = '🔍 Buscando coincidencias en la API…';
        container.style.display = '';
        const r = await dlDeferCheckMatch(payload);
        if (r.status !== 'success') {
            container.className = 'dl-defer-result no-match';
            container.innerHTML = '❌ Error: ' + esc(r.message || 'Error desconocido');
            return;
        }
        r.min_required = min;
        const best = dlDeferRenderResult(container, r);
        if (best && confirm(
            'Se encontró una coincidencia en la API: «' + best.displayName + '» con '
            + best.matched_fields + ' campos coincidentes.\n\n'
            + '¿Deseas eliminar este objeto manual y usar el de la API?'
        )) {
            // Stage deletion of the custom entry
            const anchor = item.anchor_offer_id || item.offerId;
            dlCommit('Diferir a API «' + best.displayName + '»', () => {
                DlDraftStore.stageDeleteEntry(anchor);
            });
            closeModals(true);
            toast('Objeto manual eliminado. Se usará el de la API tras aplicar cambios.', 'success');
        }
    });

    // Show/hide the defer section in the edit modal based on whether the item is custom
    function dlDeferShowInEditModal(isCustom) {
        const sec = document.getElementById('dl-defer-section');
        if (sec) {
            sec.style.display = isCustom ? '' : 'none';
            // Reset state
            const chk = document.getElementById('dl-defer-check');
            if (chk) { chk.checked = false; document.getElementById('dl-defer-opts').classList.remove('open'); }
            const res = document.getElementById('dl-defer-result');
            if (res) { res.style.display = 'none'; res.innerHTML = ''; }
        }
    }

    // =====================================================================
    //  MODO ORGANIZAR: drag & drop (Pointer Events), teclado y mini-toolbar
    //  El orden visual se traduce al contrato exacto del worker de la tienda:
    //  sufijo de layoutId DESC + sortPriority DESC dentro de cada sección, y
    //  layout/section_name al mover entre secciones. Todo staged y deshacible.
    // =====================================================================
    let dlOrganizeMode = false;
    const orgToggle = document.getElementById('dl-organize-toggle');
    orgToggle.addEventListener('click', () => {
        dlOrganizeMode = !dlOrganizeMode;
        orgToggle.setAttribute('aria-pressed', String(dlOrganizeMode));
        orgToggle.classList.toggle('bg-gray-600', !dlOrganizeMode);
        orgToggle.classList.toggle('bg-blue-600', dlOrganizeMode);
        dlHideOrgToolbar();
        renderGrid('dl-editor-grid', lastItems, true, document.getElementById('dl-editor-search').value);
        toast(dlOrganizeMode
            ? 'Modo Organizar: arrastra las tarjetas (o usa las flechas del teclado) y tócalas para acciones rápidas.'
            : 'Modo Organizar desactivado.', 'info');
    });

    // Ítem (real o borrador) por anchor, con overlay del draft.
    function dlItemByAnchor(anchor) {
        const cr = DlDraftStore.getCreate(anchor);
        if (cr) return dlDraftCard(anchor, cr);
        return lastItems.find(i => (i.anchor_offer_id || i.offerId) === anchor) || null;
    }
    // Ítems efectivos de una sección en su orden visual actual.
    function dlSectionItems(sectionName) {
        return Array.from(document.querySelectorAll(
            `#dl-editor-grid .dl-section[data-section-name] .dl-grid[data-drop-section]`
        )).filter(g => g.dataset.dropSection === sectionName)
            .flatMap(g => Array.from(g.querySelectorAll('.item-card-wrapper[data-offerid]')))
            .map(c => dlItemByAnchor(c.dataset.offerid))
            .filter(Boolean);
    }

    // Traducción del orden visual → overrides. `ordered` es la lista final de
    // la sección (arriba→abajo). Solo se stagean los campos que cambian.
    function dlStageSectionOrder(label, sectionMeta, ordered, extraMutate) {
        dlCommit(label, () => {
            const n = ordered.length;
            ordered.forEach((it, idx) => {
                const suffix = n - idx;
                const anchor = it.anchor_offer_id || it.offerId;
                const eff = dlEffOrder(it);
                const wantLayoutId = sectionMeta.id + '.' + suffix;
                if (DlDraftStore.getCreate(anchor)) {
                    DlDraftStore.updateCreateFields(anchor, {
                        layout: { id: sectionMeta.id, name: sectionMeta.name, rank: sectionMeta.rank },
                        layout_suffix: suffix,
                        sort_priority: suffix
                    });
                    return;
                }
                const jobs = [];
                if (eff.layoutId !== wantLayoutId) jobs.push({ field_key: 'layoutId', field_value: wantLayoutId, value_type: 'string' });
                if (eff.sortPriority !== suffix) jobs.push({ field_key: 'sortPriority', field_value: String(suffix), value_type: 'number' });
                if (eff.sectionName !== sectionMeta.name) {
                    jobs.push({ field_key: 'layout', field_value: JSON.stringify({ id: sectionMeta.id, name: sectionMeta.name, rank: sectionMeta.rank }), value_type: 'json' });
                    jobs.push({ field_key: 'section_name', field_value: sectionMeta.name, value_type: 'string' });
                }
                if (jobs.length) DlDraftStore.stageUpserts(anchor, jobs);
            });
            if (extraMutate) extraMutate();
        });
    }

    // Mueve `anchor` a la posición `index` de la sección `targetName`.
    function dlMoveItem(anchor, targetName, index) {
        const it = dlItemByAnchor(anchor);
        const meta = dlSectionMetaByName(targetName);
        if (!it || !meta) return;
        const sourceName = dlEffOrder(it).sectionName;
        const target = dlSectionItems(targetName).filter(x => (x.anchor_offer_id || x.offerId) !== anchor);
        const idx = Math.max(0, Math.min(index == null ? target.length : index, target.length));
        target.splice(idx, 0, it);
        const label = sourceName === targetName
            ? 'Reordenar «' + (it.displayName || anchor) + '» en «' + targetName + '»'
            : 'Mover «' + (it.displayName || anchor) + '» a «' + targetName + '»';
        dlStageSectionOrder(label, meta, target, () => {
            if (sourceName && sourceName !== targetName) {
                // Compactar la sección origen sin el ítem movido.
                const srcMeta = dlSectionMetaByName(sourceName);
                if (srcMeta) {
                    const rest = dlSectionItems(sourceName).filter(x => (x.anchor_offer_id || x.offerId) !== anchor);
                    const m = rest.length;
                    rest.forEach((x, i2) => {
                        const suffix = m - i2;
                        const a2 = x.anchor_offer_id || x.offerId;
                        if (DlDraftStore.getCreate(a2)) {
                            DlDraftStore.updateCreateFields(a2, { layout_suffix: suffix, sort_priority: suffix });
                        } else {
                            const eff2 = dlEffOrder(x);
                            const jobs2 = [];
                            const want2 = srcMeta.id + '.' + suffix;
                            if (eff2.layoutId !== want2) jobs2.push({ field_key: 'layoutId', field_value: want2, value_type: 'string' });
                            if (eff2.sortPriority !== suffix) jobs2.push({ field_key: 'sortPriority', field_value: String(suffix), value_type: 'number' });
                            if (jobs2.length) DlDraftStore.stageUpserts(a2, jobs2);
                        }
                    });
                }
            }
        });
    }

    // ---- Drag & drop con Pointer Events (mouse + táctil, sin dependencias) ----
    const dlDrag = { active: false, anchor: null, ghost: null, placeholder: null, startX: 0, startY: 0, pending: null, raf: 0 };
    document.getElementById('dl-editor-grid').addEventListener('pointerdown', (e) => {
        if (!dlOrganizeMode || e.button !== 0) return;
        const card = e.target.closest('.item-card-wrapper[data-offerid]');
        if (!card) return;
        dlDrag.pending = card;
        dlDrag.startX = e.clientX;
        dlDrag.startY = e.clientY;
    });
    document.addEventListener('pointermove', (e) => {
        if (dlDrag.pending && !dlDrag.active) {
            if (Math.hypot(e.clientX - dlDrag.startX, e.clientY - dlDrag.startY) < 6) return;
            dlStartDrag(dlDrag.pending, e);
        }
        if (!dlDrag.active) return;
        e.preventDefault();
        dlDrag.ghost.style.left = (e.clientX + 8) + 'px';
        dlDrag.ghost.style.top = (e.clientY + 8) + 'px';
        // Auto-scroll cerca de los bordes del viewport.
        if (e.clientY < 90) window.scrollBy(0, -14);
        else if (e.clientY > window.innerHeight - 90) window.scrollBy(0, 14);
        if (dlDrag.raf) return;
        dlDrag.raf = requestAnimationFrame(() => {
            dlDrag.raf = 0;
            dlPositionPlaceholder(e.clientX, e.clientY);
        });
    }, { passive: false });
    document.addEventListener('pointerup', () => {
        dlDrag.pending = null;
        if (!dlDrag.active) return;
        const ph = dlDrag.placeholder;
        const anchor = dlDrag.anchor;
        const grid = ph && ph.closest('.dl-grid[data-drop-section]');
        let targetName = null, index = null;
        if (grid) {
            targetName = grid.dataset.dropSection;
            index = Array.from(grid.children)
                .filter(el => el === ph || el.matches('.item-card-wrapper[data-offerid], [class^="span-card-"]'))
                .filter(el => el !== dlDrag.source)
                .indexOf(ph);
        }
        dlEndDrag();
        if (targetName != null && index >= 0) dlMoveItem(anchor, targetName, index);
    });
    document.addEventListener('pointercancel', () => { dlDrag.pending = null; dlEndDrag(); });
    function dlStartDrag(card, e) {
        dlHideOrgToolbar();
        dlDrag.active = true;
        dlDrag.anchor = card.dataset.offerid;
        // La unidad de layout puede ser el wrapper del span (tarjetas anchas).
        const unit = card.parentElement && /^span-card-/.test(card.parentElement.className) ? card.parentElement : card;
        dlDrag.source = unit;
        const ghost = card.cloneNode(true);
        ghost.className = 'dl-org-ghost';
        ghost.style.left = (e.clientX + 8) + 'px';
        ghost.style.top = (e.clientY + 8) + 'px';
        document.body.appendChild(ghost);
        dlDrag.ghost = ghost;
        const ph = document.createElement('div');
        ph.className = 'dl-org-placeholder';
        unit.after(ph);
        dlDrag.placeholder = ph;
        unit.classList.add('dl-org-dragging');
        document.body.style.userSelect = 'none';
    }
    function dlPositionPlaceholder(x, y) {
        if (!dlDrag.active) return;
        dlDrag.ghost.style.display = 'none';
        const el = document.elementFromPoint(x, y);
        dlDrag.ghost.style.display = '';
        if (!el) return;
        const grid = el.closest('.dl-grid[data-drop-section]');
        if (!grid) return;
        const overCard = el.closest('.item-card-wrapper[data-offerid]');
        if (!overCard || overCard === dlDrag.source || overCard.dataset.offerid === dlDrag.anchor) {
            if (!overCard && grid !== dlDrag.placeholder.parentElement) grid.appendChild(dlDrag.placeholder);
            return;
        }
        const unit = overCard.parentElement && /^span-card-/.test(overCard.parentElement.className) ? overCard.parentElement : overCard;
        const r = unit.getBoundingClientRect();
        const before = (x - r.left) < r.width / 2;
        if (before) unit.before(dlDrag.placeholder); else unit.after(dlDrag.placeholder);
    }
    function dlEndDrag() {
        if (!dlDrag.active) return;
        dlDrag.active = false;
        if (dlDrag.ghost) dlDrag.ghost.remove();
        if (dlDrag.placeholder) dlDrag.placeholder.remove();
        if (dlDrag.source) dlDrag.source.classList.remove('dl-org-dragging');
        dlDrag.ghost = dlDrag.placeholder = dlDrag.source = null;
        document.body.style.userSelect = '';
    }

    // ---- Alternativa por teclado: flechas mueven la tarjeta enfocada ----
    function dlOrgKeydown(e, card) {
        const anchor = card.dataset.offerid;
        const it = dlItemByAnchor(anchor);
        if (!it) return;
        const eff = dlEffOrder(it);
        const sectionName = eff.sectionName;
        const inSection = dlSectionItems(sectionName);
        const pos = inSection.findIndex(x => (x.anchor_offer_id || x.offerId) === anchor);
        const sections = Array.from(document.querySelectorAll('#dl-editor-grid .dl-grid[data-drop-section]')).map(g => g.dataset.dropSection);
        const sIdx = sections.indexOf(sectionName);
        let handled = true;
        if (e.key === 'ArrowLeft' && pos > 0) dlMoveItem(anchor, sectionName, pos - 1);
        else if (e.key === 'ArrowRight' && pos >= 0 && pos < inSection.length - 1) dlMoveItem(anchor, sectionName, pos + 1);
        else if (e.key === 'ArrowUp' && sIdx > 0) dlMoveItem(anchor, sections[sIdx - 1], null);
        else if (e.key === 'ArrowDown' && sIdx >= 0 && sIdx < sections.length - 1) dlMoveItem(anchor, sections[sIdx + 1], null);
        else if (e.key === 'Enter' || e.key === ' ') dlShowOrgToolbar(card);
        else handled = false;
        if (handled) {
            e.preventDefault();
            // Restaurar el foco en la tarjeta tras el re-render del observer.
            requestAnimationFrame(() => {
                const again = document.querySelector(`#dl-editor-grid .item-card-wrapper[data-offerid="${(window.CSS && CSS.escape) ? CSS.escape(anchor) : anchor}"] .item-image-container[role="button"]`);
                if (again) again.focus();
            });
        }
    }

    // ---- Mini-toolbar contextual (tileSize, mover, duplicar, eliminar) ----
    const orgToolbar = document.getElementById('dl-org-toolbar');
    let orgAnchor = null;
    function dlShowOrgToolbar(card) {
        orgAnchor = card.dataset.offerid;
        const it = dlItemByAnchor(orgAnchor);
        if (!it) return;
        document.getElementById('ot-name').textContent = it.displayName || orgAnchor;
        const span = it.span || 1;
        orgToolbar.querySelectorAll('.ot-tile').forEach(b => b.classList.toggle('sel', parseInt(b.dataset.span, 10) === span));
        const move = document.getElementById('ot-move');
        const current = dlEffOrder(it).sectionName;
        move.innerHTML = '<option value="">Mover a…</option>'
            + dlAllSections().filter(s => s.name !== current).map(s => `<option value="${esc(s.name)}">${esc(s.name)}</option>`).join('');
        document.getElementById('ot-delete').style.display = (it.is_custom || it.is_draft_new) ? '' : 'none';
        const r = card.getBoundingClientRect();
        orgToolbar.style.display = 'flex';
        const tw = orgToolbar.offsetWidth || 300;
        orgToolbar.style.left = Math.max(8, Math.min(r.left, window.innerWidth - tw - 8)) + 'px';
        orgToolbar.style.top = Math.min(r.bottom + 6, window.innerHeight - 60) + 'px';
    }
    function dlHideOrgToolbar() {
        orgToolbar.style.display = 'none';
        orgAnchor = null;
    }
    document.addEventListener('click', (e) => {
        if (orgToolbar.style.display !== 'flex') return;
        if (orgToolbar.contains(e.target) || e.target.closest('.item-card-wrapper[data-offerid]')) return;
        dlHideOrgToolbar();
    });
    orgToolbar.querySelectorAll('.ot-tile').forEach(b => b.addEventListener('click', () => {
        if (!orgAnchor) return;
        const span = parseInt(b.dataset.span, 10);
        dlCommit('Cambiar ancho de tarjeta a ' + span, () =>
            DlDraftStore.stageUpserts(orgAnchor, [{ field_key: 'tileSize', field_value: 'Size_' + span + '_x_1', value_type: 'string' }]));
        orgToolbar.querySelectorAll('.ot-tile').forEach(x => x.classList.toggle('sel', x === b));
    }));
    document.getElementById('ot-move').addEventListener('change', (e) => {
        if (!orgAnchor || !e.target.value) return;
        dlMoveItem(orgAnchor, e.target.value, 0);
        dlHideOrgToolbar();
    });
    document.getElementById('ot-duplicate').addEventListener('click', () => {
        if (!orgAnchor) return;
        const it = dlItemByAnchor(orgAnchor);
        if (!it) return;
        if (it.is_draft_new) { toast('Aplica primero la creación antes de duplicarla.', 'warning'); return; }
        const eff = dlEffOrder(it);
        const meta = dlSectionMetaByName(eff.sectionName);
        if (!meta) return;
        const newId = dlNewOfferId();
        const fields = {
            source_offer_id: it.offerId || orgAnchor,
            display_name: (it.displayName || 'Objeto') + ' (copia)',
            layout: { id: meta.id, name: meta.name, rank: meta.rank },
            layout_suffix: dlMaxSuffix(meta.name) + 1,
            sort_priority: 0
        };
        dlCommit('Duplicar «' + (it.displayName || orgAnchor) + '»', () => DlDraftStore.stageCreate(newId, 'duplicate', fields));
        toast('Duplicado añadido al borrador.', 'success');
        dlHideOrgToolbar();
    });
    document.getElementById('ot-edit').addEventListener('click', () => {
        if (!orgAnchor) return;
        const a = orgAnchor;
        dlHideOrgToolbar();
        openItem(a);
    });
    document.getElementById('ot-delete').addEventListener('click', () => {
        if (!orgAnchor) return;
        const it = dlItemByAnchor(orgAnchor);
        if (!it) return;
        if (it.is_draft_new) {
            if (!confirm('¿Descartar la creación de «' + (it.displayName || orgAnchor) + '»?')) return;
            dlCommit('Descartar creación de «' + (it.displayName || orgAnchor) + '»', () => DlDraftStore.unstageCreate(orgAnchor));
        } else {
            if (!confirm('¿Marcar la entrada manual «' + (it.displayName || orgAnchor) + '» para eliminación definitiva? Se aplica con "Aplicar cambios".')) return;
            dlCommit('Eliminar entrada manual «' + (it.displayName || orgAnchor) + '»', () => DlDraftStore.stageDeleteEntry(orgAnchor));
        }
        dlHideOrgToolbar();
    });

    // =====================================================================
    //  STAGING UI: contador/botones del header, badges del grid, resync del
    //  modal abierto, aplicación atómica, descarte y atajos undo/redo.
    // =====================================================================
    function updateStagingUI() {
        const n = DlDraftStore.count();
        const apply = document.getElementById('dl-apply-draft');
        const discard = document.getElementById('dl-discard-draft');
        const summary = document.getElementById('dl-staged-summary');
        apply.disabled = discard.disabled = n === 0;
        apply.style.display = discard.style.display = n === 0 ? 'none' : '';
        apply.innerHTML = '<i class="fas fa-check-double"></i> Aplicar cambios (' + n + ')';
        summary.style.display = n === 0 ? 'none' : '';
        summary.textContent = n === 1 ? '1 cambio pendiente' : n + ' cambios pendientes';
    }

    DlDraftStore.subscribe(() => {
        updateStagingUI();
        // Badges "PENDIENTE" del grid del editor (derivados del store).
        if (lastItems.length && document.getElementById('panel-editor').classList.contains('active')) {
            renderGrid('dl-editor-grid', lastItems, true, document.getElementById('dl-editor-search').value);
        }
        // Si el modal abierto ve cambiar su entrada staged (undo/redo, chips,
        // descartes), se resincroniza por completo releyendo servidor+draft.
        if (document.getElementById('dl-item-modal').classList.contains('flex') && currentOfferId) {
            const now = JSON.stringify(DlDraftStore.get(currentOfferId));
            if (now !== modalStagedJson) {
                modalStagedJson = now;
                openItem(currentOfferId);
            }
        }
    });

    document.getElementById('dl-apply-draft').addEventListener('click', async () => {
        const ops = DlDraftStore.toBatchOps();
        if (!ops.length) {
            if (DlDraftStore.listSections().length) toast('Las secciones vacías no se publican: añádeles objetos primero.', 'warning');
            return;
        }
        if (!confirm('¿Aplicar definitivamente ' + ops.length + ' operación(es) pendientes? Se compilarán a producción en un solo paso.')) return;
        const btn = document.getElementById('dl-apply-draft');
        btn.disabled = true;
        const r = await api('apply_overrides_batch', { ops: JSON.stringify(ops) }, 'POST');
        if (r.status === 'success') {
            DlDraftStore.clear();
            DlHistory.clear(); // lo aplicado ya no es reversible con Ctrl+Z
            toast(r.message, 'success');
            if (document.getElementById('panel-editor').classList.contains('active')) {
                loadGrid('dl-editor-grid', document.getElementById('dl-editor-src').value, true);
            }
            loadHealth();
        }
        updateStagingUI();
    });

    document.getElementById('dl-discard-draft').addEventListener('click', () => {
        const n = DlDraftStore.count();
        if (!n || !confirm('¿Descartar el borrador completo (' + n + ' cambios pendientes)?')) return;
        dlCommit('Descartar borrador (' + n + ' cambios)', () => DlDraftStore.clear());
        toast('Borrador descartado (recuperable con Ctrl+Z).', 'info');
    });

    // ---- Purga global: borrar overrides / entradas manuales / ambos ----
    async function dlPurge(action, confirmMsg) {
        if (!confirm(confirmMsg)) return;
        toast('Eliminando…', 'info');
        const r = await api(action, {}, 'POST');
        if (r.status === 'success') {
            toast(r.message, 'success');
            if (document.getElementById('panel-editor').classList.contains('active')) {
                loadGrid('dl-editor-grid', document.getElementById('dl-editor-src').value, true);
            }
            loadHealth();
        }
    }
    document.getElementById('dl-purge-overrides').addEventListener('click', () => dlPurge(
        'purge_all_overrides',
        '⚠️ ELIMINAR TODOS LOS OVERRIDES\n\n'
        + 'Esto borrará TODAS las modificaciones de campo (colores, nombres, precios, imágenes, etc.) aplicadas sobre ítems existentes de la API.\n\n'
        + '• Los ítems volverán a mostrarse con sus datos originales de la API.\n'
        + '• Las entradas manuales (objetos/secciones creados desde cero) NO se verán afectadas.\n'
        + '• Esta acción NO es deshacible.\n\n'
        + '¿Continuar?'
    ));
    document.getElementById('dl-purge-entries').addEventListener('click', () => dlPurge(
        'purge_all_entries',
        '⚠️ ELIMINAR TODAS LAS ENTRADAS MANUALES\n\n'
        + 'Esto borrará TODOS los objetos y secciones creados desde cero en el Data Layer.\n\n'
        + '• Los overrides de campo sobre ítems de la API NO se verán afectados.\n'
        + '• Esta acción NO es deshacible.\n\n'
        + '¿Continuar?'
    ));
    document.getElementById('dl-purge-all').addEventListener('click', () => dlPurge(
        'purge_all',
        '☠️ ELIMINAR TODO (OVERRIDES + MANUALES)\n\n'
        + 'Esto borrará:\n'
        + '  • Todos los overrides de campo (modificaciones sobre ítems de la API)\n'
        + '  • Todas las entradas manuales (objetos y secciones creados desde cero)\n\n'
        + 'La tienda quedará mostrando exclusivamente los datos originales de la API.\n'
        + '• Esta acción NO es deshacible.\n\n'
        + '¿Estás seguro?'
    ));

    // Undo/Redo globales del borrador: Ctrl+Z / Ctrl+Y (o Ctrl+Shift+Z). Se
    // ceden al undo nativo cuando el foco está en un campo de texto editable.
    document.addEventListener('keydown', (e) => {
        if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
        const k = (e.key || '').toLowerCase();
        const isUndo = k === 'z' && !e.shiftKey;
        const isRedo = k === 'y' || (k === 'z' && e.shiftKey);
        if (!isUndo && !isRedo) return;
        const t = e.target;
        if (t && (t.isContentEditable || /^(input|textarea|select)$/i.test(t.tagName || ''))) return;
        const editorActive = document.getElementById('panel-editor').classList.contains('active')
            || document.getElementById('dl-item-modal').classList.contains('flex');
        if (!editorActive) return;
        e.preventDefault();
        const label = isUndo ? DlHistory.undo() : DlHistory.redo();
        if (label) toast((isUndo ? 'Deshecho: ' : 'Rehecho: ') + label, 'info');
        else toast('Nada que ' + (isUndo ? 'deshacer' : 'rehacer') + '.', 'warning');
    });

    // Red de seguridad al salir con borrador pendiente (además del espejo en
    // sessionStorage, que ya lo conserva ante recargas).
    window.addEventListener('beforeunload', (e) => {
        if (DlDraftStore.count() > 0) { e.preventDefault(); e.returnValue = ''; }
    });

    updateStagingUI();

    // ---------- Field map ----------
    async function loadFieldMap() {
        const r = await api('get_field_map');
        const tb = document.getElementById('fm-tbody');
        if (r.status !== 'success') { tb.innerHTML = ''; return; }
        tb.innerHTML = (r.field_map || []).map(m => `<tr>
            <td class="dl-td"><code>${esc(m.scope)}</code></td>
            <td class="dl-td"><code>${esc(m.source_key)}</code></td>
            <td class="dl-td"><code>${esc(m.canonical_key)}</code></td>
            <td class="dl-td">${m.activo == 1 ? '✓' : '—'}</td>
            <td class="dl-td">${esc(m.nota)}</td>
            <td class="dl-td text-right">
                <button class="dl-btn bg-blue-600 text-white fm-edit" data-m='${esc(JSON.stringify(m))}'><i class="fas fa-pen"></i></button>
                <button class="dl-btn bg-red-600 text-white fm-del" data-id="${m.id}"><i class="fas fa-trash"></i></button>
            </td></tr>`).join('') || '<tr><td class="dl-td" colspan="6" style="color:var(--text-secondary)">Sin reglas de mapeo.</td></tr>';
        tb.querySelectorAll('.fm-del').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('¿Eliminar regla?')) return;
            const r2 = await api('delete_field_map', { id: b.dataset.id }, 'POST');
            if (r2.status === 'success') { toast(r2.message, 'success'); loadFieldMap(); }
        }));
        tb.querySelectorAll('.fm-edit').forEach(b => b.addEventListener('click', () => {
            const m = JSON.parse(b.dataset.m);
            document.getElementById('fm-id').value = m.id;
            document.getElementById('fm-scope').value = m.scope;
            document.getElementById('fm-source').value = m.source_key;
            document.getElementById('fm-canonical').value = m.canonical_key;
            document.getElementById('fm-nota').value = m.nota || '';
        }));
    }
    document.getElementById('fm-save').addEventListener('click', async () => {
        const payload = {
            id: document.getElementById('fm-id').value,
            scope: document.getElementById('fm-scope').value,
            source_key: document.getElementById('fm-source').value.trim(),
            canonical_key: document.getElementById('fm-canonical').value.trim(),
            nota: document.getElementById('fm-nota').value.trim()
        };
        if (!payload.source_key || !payload.canonical_key) { toast('Origen y canónico son obligatorios.', 'warning'); return; }
        const r = await api('save_field_map', payload, 'POST');
        if (r.status === 'success') {
            toast(r.message, 'success');
            document.getElementById('fm-id').value = 0;
            ['fm-source', 'fm-canonical', 'fm-nota'].forEach(id => document.getElementById(id).value = '');
            loadFieldMap();
        }
    });

    // ---------- Logs ----------
    async function loadLogs() {
        const sev = document.getElementById('dl-log-sev').value;
        const r = await api('get_logs', { severity: sev, limit: 200 });
        const tb = document.getElementById('dl-log-tbody');
        if (r.status !== 'success') { tb.innerHTML = ''; return; }
        tb.innerHTML = (r.logs || []).map(l => `<tr>
            <td class="dl-td whitespace-nowrap">${esc(l.created_at)}</td>
            <td class="dl-td sev-${esc(l.severity)}">${esc((l.severity || '').toUpperCase())}</td>
            <td class="dl-td"><code>${esc(l.event_type)}</code></td>
            <td class="dl-td">${esc(l.message)}</td>
            <td class="dl-td"><code style="font-size:.7rem">${esc((l.context || '').slice(0, 120))}</code></td>
        </tr>`).join('') || '<tr><td class="dl-td" colspan="5" style="color:var(--text-secondary)">Sin eventos registrados.</td></tr>';
    }
    document.getElementById('dl-log-refresh').addEventListener('click', loadLogs);
    document.getElementById('dl-log-sev').addEventListener('change', loadLogs);
    document.getElementById('dl-log-clear').addEventListener('click', async () => {
        if (!confirm('¿Limpiar todo el registro de eventos?')) return;
        const r = await api('clear_logs', {}, 'POST');
        if (r.status === 'success') { toast(r.message, 'success'); loadLogs(); }
    });

    // ---------- Modal helpers ----------
    function openModal(id) { document.getElementById(id).classList.add('flex'); }
    function closeModals(force) {
        const itemOpen = document.getElementById('dl-item-modal').classList.contains('flex');
        if (force !== true && itemOpen && dirtyCount() > 0
            && !confirm('Hay cambios sin guardar. ¿Cerrar de todos modos?')) return;
        if (itemOpen) initialState = null;
        dlResetUploaders();
        document.querySelectorAll('.dl-modal').forEach(m => m.classList.remove('flex'));
    }
    document.querySelectorAll('.dl-modal-close').forEach(b => b.addEventListener('click', closeModals));
    document.querySelectorAll('.dl-modal').forEach(m => m.addEventListener('click', (e) => { if (e.target === m) closeModals(); }));

    // ---------- Init ----------
    loadHealth();
})();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

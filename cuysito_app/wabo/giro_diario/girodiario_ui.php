<?php
// /wabo/giro_diario/girodiario_ui.php
// Cabecera compartida por las 2 páginas del Giro Diario (pestañas + estilos + helpers JS).
// Cada vista es una página independiente; esto solo evita repetir estilos y navegación.
$gdTabs = [
    'config'   => ['href' => 'gestionar_girodiario.php', 'icon' => 'fa-sliders-h', 'label' => 'Configuración', 'sub' => 'Reglas del juego, descuentos y filtros por categoría.'],
    'usuarios' => ['href' => 'usuarios_girodiario.php',  'icon' => 'fa-users',     'label' => 'Usuarios',      'sub' => 'Participación del día, métricas y seguimiento de jugadores.'],
];
$gdCurrent = $gdCurrent ?? 'config';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    /* ── Paleta de variables coherente con el sistema de temas ── */
    :root {
        --gd-bg-main: var(--primary);
        --gd-bg-card: var(--secondary);
        --gd-border: var(--border-color);
        --gd-text-primary: var(--text-primary);
        --gd-text-secondary: var(--text-secondary);
        --gd-accent: var(--accent);
        --gd-row-hover: rgba(255,255,255,0.04);
        --gd-badge-active-bg: rgba(34,197,94,0.14);
        --gd-badge-active-border: rgba(34,197,94,0.40);
        --gd-badge-active-color: #22c55e;
        --gd-badge-used-bg: rgba(59,130,246,0.14);
        --gd-badge-used-border: rgba(59,130,246,0.40);
        --gd-badge-used-color: #3b82f6;
        --gd-badge-expired-bg: rgba(239,68,68,0.14);
        --gd-badge-expired-border: rgba(239,68,68,0.40);
        --gd-badge-expired-color: #ef4444;
        --gd-modal-overlay: rgba(0,0,0,0.60);
        --gd-modal-bg: var(--secondary);
        --gd-input-bg: var(--primary);
    }

    html[data-theme="light"] {
        --gd-row-hover: #f3f4f6;
        --gd-modal-overlay: rgba(0,0,0,0.35);
        --gd-input-bg: #ffffff;
    }

    #content-wrapper > main {
        background-color: var(--gd-bg-main);
    }

    /* ── Cards ── */
    .gd-card {
        background-color: var(--gd-bg-card);
        border: 1px solid var(--gd-border);
        border-radius: 12px;
        padding: 1.5rem;
        transition: background-color .3s, border-color .3s;
    }

    /* ── Section Headers ── */
    .gd-section-header {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        margin-bottom: 1.25rem;
        cursor: pointer;
        user-select: none;
    }
    .gd-section-header i.section-icon {
        font-size: 1.1rem;
        color: var(--gd-accent);
    }
    .gd-section-header h2 {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--gd-text-primary);
    }
    .gd-section-header .collapse-chevron {
        margin-left: auto;
        transition: transform .25s;
        color: var(--gd-text-secondary);
        font-size: .85rem;
    }
    .gd-section-header .collapse-chevron.collapsed {
        transform: rotate(-90deg);
    }

    /* ── Form Controls ── */
    .gd-label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--gd-text-secondary);
        margin-bottom: 0.35rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .gd-input, .gd-select {
        width: 100%;
        padding: 0.55rem 0.75rem;
        border-radius: 8px;
        border: 1px solid var(--gd-border);
        background-color: var(--gd-input-bg);
        color: var(--gd-text-primary);
        font-size: 0.9rem;
        transition: border-color .2s, box-shadow .2s;
        outline: none;
    }
    .gd-input:focus, .gd-select:focus {
        border-color: var(--gd-accent);
        box-shadow: 0 0 0 3px rgba(245,158,11,0.18);
    }

    /* ── Buttons ── */
    .gd-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.55rem 1.1rem;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: background-color .2s, transform .15s, box-shadow .2s;
    }
    .gd-btn:hover { transform: translateY(-1px); }
    .gd-btn:active { transform: translateY(0); }

    .gd-btn-primary {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: #fff;
        box-shadow: 0 2px 8px rgba(245,158,11,0.25);
    }
    .gd-btn-primary:hover { box-shadow: 0 4px 14px rgba(245,158,11,0.35); }

    .gd-btn-success {
        background: linear-gradient(135deg, #22c55e, #16a34a);
        color: #fff;
        box-shadow: 0 2px 8px rgba(34,197,94,0.25);
    }

    .gd-btn-danger {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: #fff;
    }

    .gd-btn-ghost {
        background: transparent;
        border: 1px solid var(--gd-border);
        color: var(--gd-text-secondary);
    }
    .gd-btn-ghost:hover { background-color: var(--gd-row-hover); color: var(--gd-text-primary); }

    .gd-btn-sm {
        padding: 0.35rem 0.7rem;
        font-size: 0.78rem;
    }

    /* ── Status Badges ── */
    .gd-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.25rem 0.6rem;
        border-radius: 10px;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        border-width: 1px;
        border-style: solid;
    }
    .gd-badge-active  { background: var(--gd-badge-active-bg);  border-color: var(--gd-badge-active-border);  color: var(--gd-badge-active-color); }
    .gd-badge-used    { background: var(--gd-badge-used-bg);    border-color: var(--gd-badge-used-border);    color: var(--gd-badge-used-color); }
    .gd-badge-expired { background: var(--gd-badge-expired-bg); border-color: var(--gd-badge-expired-border); color: var(--gd-badge-expired-color); }

    /* ── Mode Indicator Chips ── */
    .gd-mode-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.3rem 0.65rem;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .gd-mode-normal   { background: rgba(59,130,246,0.14); color: #3b82f6; }
    .gd-mode-boosted  { background: rgba(245,158,11,0.14); color: #f59e0b; }
    .gd-mode-jackpot  { background: rgba(168,85,247,0.14); color: #a855f7; }
    .gd-mode-disabled { background: rgba(107,114,128,0.14); color: #6b7280; }

    /* ── Search Input Special ── */
    .gd-search-wrapper {
        position: relative;
    }
    .gd-search-wrapper .search-icon {
        position: absolute;
        left: 0.9rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--gd-text-secondary);
        font-size: 0.95rem;
        pointer-events: none;
    }
    .gd-search-wrapper input {
        padding-left: 2.6rem;
        padding-right: 1rem;
        height: 2.8rem;
        font-size: 0.95rem;
    }
    .gd-search-wrapper .search-spinner {
        position: absolute;
        right: 0.9rem;
        top: 50%;
        transform: translateY(-50%);
        display: none;
    }
    .gd-search-wrapper .search-spinner.active {
        display: block;
    }

    /* ── User Result Cards ── */
    .gd-user-card {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.85rem 1rem;
        border: 1px solid var(--gd-border);
        border-radius: 10px;
        cursor: pointer;
        transition: background-color .2s, transform .15s, box-shadow .2s;
    }
    .gd-user-card:hover {
        background-color: var(--gd-row-hover);
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(0,0,0,0.12);
    }
    html[data-theme="dark"] .gd-user-card:hover {
        box-shadow: none;
    }
    .gd-user-card .avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--gd-accent);
        flex-shrink: 0;
    }
    .gd-user-card .user-info { flex: 1; min-width: 0; }
    .gd-user-card .user-name {
        font-weight: 700;
        font-size: 0.92rem;
        color: var(--gd-text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .gd-user-card .user-phone {
        font-size: 0.8rem;
        color: var(--gd-text-secondary);
    }
    .gd-user-card .user-coins {
        display: flex;
        align-items: center;
        gap: 0.3rem;
        font-weight: 700;
        font-size: 0.85rem;
        color: #fbbf24;
        flex-shrink: 0;
    }

    .gd-user-grid {
        gap: 0.75rem;
    }

    .gd-user-grid-card {
        border: 1px solid var(--gd-border);
        border-radius: 12px;
        padding: 0.9rem 0.75rem;
        cursor: pointer;
        transition: transform .15s, background-color .2s, box-shadow .2s, border-color .2s;
        background: var(--gd-input-bg);
        text-align: center;
    }
    .gd-user-grid-card:hover {
        transform: translateY(-2px);
        background-color: var(--gd-row-hover);
        box-shadow: 0 10px 24px rgba(0,0,0,0.12);
    }
    html[data-theme="dark"] .gd-user-grid-card:hover {
        box-shadow: none;
    }
    .gd-user-grid-card .avatar {
        width: 62px;
        height: 62px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--gd-accent);
        margin: 0 auto 0.65rem;
        background: rgba(255,255,255,0.05);
    }
    .gd-user-grid-card .name {
        font-weight: 800;
        font-size: 0.88rem;
        color: var(--gd-text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .gd-user-grid-card .status {
        margin-top: 0.35rem;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.72rem;
        font-weight: 700;
        color: var(--gd-text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .gd-user-grid-card .dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        display: inline-block;
    }
    .gd-dot-green { background: #22c55e; }
    .gd-dot-gray { background: #6b7280; }

    /* ── Modal Overlay ── */
    .gd-modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 1000;
        background: var(--gd-modal-overlay);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        pointer-events: none;
        transition: opacity .25s;
        padding: 1rem;
    }
    .gd-modal-overlay.open {
        opacity: 1;
        pointer-events: auto;
    }
    .gd-modal-overlay.open .gd-modal-content {
        transform: translateY(0) scale(1);
    }

    .gd-modal-content {
        background: var(--gd-modal-bg);
        border: 1px solid var(--gd-border);
        border-radius: 16px;
        width: 100%;
        max-width: 680px;
        max-height: 90vh;
        overflow-y: auto;
        transform: translateY(20px) scale(0.97);
        transition: transform .3s cubic-bezier(.22,1,.36,1);
        scrollbar-width: thin;
    }

    /* ── Modal Inner Sections ── */
    .gd-modal-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.5rem 1.5rem 1rem;
        border-bottom: 1px solid var(--gd-border);
    }
    .gd-modal-header .avatar-lg {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid var(--gd-accent);
        flex-shrink: 0;
    }
    .gd-modal-header .header-info { flex: 1; min-width: 0; }
    .gd-modal-header .header-name {
        font-size: 1.2rem;
        font-weight: 800;
        color: var(--gd-text-primary);
    }
    .gd-modal-header .header-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        margin-top: 0.25rem;
        font-size: 0.82rem;
        color: var(--gd-text-secondary);
    }
    .gd-modal-header .header-meta span {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }
    .gd-modal-header .close-btn {
        align-self: flex-start;
        background: none;
        border: none;
        font-size: 1.3rem;
        color: var(--gd-text-secondary);
        cursor: pointer;
        padding: 0.3rem;
        border-radius: 6px;
        transition: background-color .2s, color .2s;
    }
    .gd-modal-header .close-btn:hover {
        background-color: var(--gd-row-hover);
        color: var(--gd-text-primary);
    }

    .gd-modal-body {
        padding: 1.25rem 1.5rem 1.5rem;
    }

    /* ── Spins Control ── */
    .gd-spins-control {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 1rem;
        border: 1px solid var(--gd-border);
        border-radius: 10px;
        margin-bottom: 1.25rem;
        background: var(--gd-input-bg);
    }
    .gd-spins-control .spin-value {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--gd-accent);
        min-width: 48px;
        text-align: center;
    }
    .gd-spins-control .spin-label {
        font-size: 0.82rem;
        color: var(--gd-text-secondary);
        font-weight: 600;
    }
    .gd-spin-btn {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        border: none;
        transition: transform .12s, box-shadow .2s;
    }
    .gd-spin-btn:hover { transform: scale(1.1); }
    .gd-spin-btn.minus { background: rgba(239,68,68,0.15); color: #ef4444; }
    .gd-spin-btn.plus  { background: rgba(34,197,94,0.15); color: #22c55e; }

    /* ── History Table ── */
    .gd-history-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.84rem;
    }
    .gd-history-table thead th {
        text-align: left;
        padding: 0.6rem 0.75rem;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--gd-text-secondary);
        border-bottom: 1px solid var(--gd-border);
    }
    .gd-history-table tbody tr {
        transition: background-color .15s;
    }
    .gd-history-table tbody tr:hover {
        background-color: var(--gd-row-hover);
    }
    .gd-history-table tbody td {
        padding: 0.6rem 0.75rem;
        color: var(--gd-text-primary);
        border-bottom: 1px solid var(--gd-border);
        vertical-align: middle;
    }
    .gd-history-table tbody tr:last-child td {
        border-bottom: none;
    }
    .gd-item-cell {
        display: flex;
        align-items: center;
        gap: 0.55rem;
    }
    .gd-item-cell img {
        width: 34px;
        height: 34px;
        border-radius: 6px;
        object-fit: cover;
        flex-shrink: 0;
        background: rgba(255,255,255,0.05);
    }

    /* ── Toggle Switch ── */
    .gd-toggle {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
    }
    .gd-toggle input { opacity: 0; width: 0; height: 0; }
    .gd-toggle .slider {
        position: absolute;
        inset: 0;
        background-color: rgba(107,114,128,0.35);
        border-radius: 24px;
        cursor: pointer;
        transition: background-color .25s;
    }
    .gd-toggle .slider::before {
        content: '';
        position: absolute;
        width: 18px;
        height: 18px;
        left: 3px;
        bottom: 3px;
        background: #fff;
        border-radius: 50%;
        transition: transform .25s;
        box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }
    .gd-toggle input:checked + .slider {
        background-color: #22c55e;
    }
    .gd-toggle input:checked + .slider::before {
        transform: translateX(20px);
    }

    /* ── Config Stats Row ── */
    .gd-stats-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 0.75rem;
        margin-top: 1rem;
    }
    .gd-stat-item {
        text-align: center;
        padding: 0.75rem 0.5rem;
        border: 1px solid var(--gd-border);
        border-radius: 10px;
        background: var(--gd-input-bg);
    }
    .gd-stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--gd-text-primary);
    }
    .gd-stat-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--gd-text-secondary);
        text-transform: uppercase;
        margin-top: 0.15rem;
    }

    /* ── Empty state ── */
    .gd-empty {
        text-align: center;
        padding: 2.5rem 1rem;
        color: var(--gd-text-secondary);
    }
    .gd-empty i {
        font-size: 2.2rem;
        margin-bottom: 0.6rem;
        opacity: 0.45;
    }
    .gd-empty p {
        font-size: 0.88rem;
    }

    /* ── Flash indicator (pulse) ── */
    @keyframes gd-pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    .gd-live-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #22c55e;
        display: inline-block;
        animation: gd-pulse 2s infinite;
    }

    /* ── Responsive ── */
    @media (max-width: 640px) {
        .gd-modal-header { flex-direction: column; text-align: center; }
        .gd-modal-header .close-btn { position: absolute; top: 1rem; right: 1rem; }
        .gd-modal-header .header-meta { justify-content: center; }
        .gd-spins-control { flex-wrap: wrap; justify-content: center; }
        .gd-history-table thead { display: none; }
        .gd-history-table tbody tr { display: block; margin-bottom: 0.75rem; border: 1px solid var(--gd-border); border-radius: 8px; padding: 0.5rem; }
        .gd-history-table tbody td { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed var(--gd-border); padding: 0.5rem 0.4rem; }
        .gd-history-table tbody td::before { content: attr(data-label); font-weight: 600; color: var(--gd-text-secondary); margin-right: 0.5rem; font-size: 0.75rem; }
        .gd-history-table tbody tr td:last-child { border-bottom: none; }
    }

    /* ── Page header + tabs ── */
    .gd-page-head {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        margin-bottom: 1.25rem;
    }
    @media (min-width: 1024px) {
        .gd-page-head { flex-direction: row; align-items: flex-end; justify-content: space-between; }
    }
    .gd-page-head h1 {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--gd-text-primary);
    }
    .gd-page-head h1 small {
        font-size: 1rem;
        font-weight: 500;
        color: var(--gd-text-secondary);
    }
    .gd-page-head p {
        margin-top: 0.15rem;
        font-size: 0.85rem;
        color: var(--gd-text-secondary);
    }
    .gd-tabs {
        display: flex;
        gap: 0.25rem;
        overflow-x: auto;
        padding-bottom: 0.15rem;
    }
    .gd-tab {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.5rem 0.95rem;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--gd-text-secondary);
        border: 1px solid transparent;
        white-space: nowrap;
        transition: background-color .2s, color .2s, box-shadow .2s;
    }
    .gd-tab:hover { background: var(--gd-row-hover); color: var(--gd-text-primary); }
    .gd-tab.is-active {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: #fff !important;
        box-shadow: 0 2px 8px rgba(245,158,11,0.25);
    }

    /* ── Card sub-blocks ── */
    .gd-subtitle {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--gd-text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 0.6rem;
    }
    .gd-subtitle i { color: var(--gd-accent); }
    .gd-hint {
        font-size: 0.78rem;
        color: var(--gd-text-secondary);
        line-height: 1.4;
    }
    .gd-divider {
        height: 1px;
        background: var(--gd-border);
        margin: 1.25rem 0;
    }

    /* ── Discount tiles (Global / Sin Pistas) ── */
    .gd-discount-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.9rem;
    }
    @media (min-width: 768px) { .gd-discount-grid { grid-template-columns: 1fr 1fr; } }
    .gd-discount-tile {
        border: 1px solid var(--gd-border);
        border-radius: 12px;
        padding: 1rem 1.1rem;
        background: var(--gd-input-bg);
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
    }
    .gd-discount-tile .tile-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
    }
    .gd-discount-tile .tile-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--gd-text-primary);
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }
    .gd-discount-tile .tile-count {
        font-size: 0.72rem;
        font-weight: 700;
        color: var(--gd-text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .gd-percent-input {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .gd-percent-input input {
        font-size: 1.45rem;
        font-weight: 800;
        text-align: center;
        max-width: 120px;
        padding: 0.45rem 0.6rem;
    }
    .gd-percent-input .unit {
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--gd-accent);
    }
    .gd-tile-all    { border-color: rgba(59,130,246,0.35); }
    .gd-tile-notrk  { border-color: rgba(245,158,11,0.40); }
    .gd-tile-all .tile-title i   { color: #3b82f6; }
    .gd-tile-notrk .tile-title i { color: #f59e0b; }

    /* ── Filters table ── */
    .gd-filter-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.86rem;
    }
    .gd-filter-table thead th {
        text-align: left;
        padding: 0.6rem 0.75rem;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--gd-text-secondary);
        border-bottom: 1px solid var(--gd-border);
        white-space: nowrap;
    }
    .gd-filter-table tbody td {
        padding: 0.6rem 0.75rem;
        color: var(--gd-text-primary);
        border-bottom: 1px solid var(--gd-border);
        vertical-align: middle;
    }
    .gd-filter-table tbody tr:last-child td { border-bottom: none; }
    .gd-filter-table tbody tr:hover { background-color: var(--gd-row-hover); }
    .gd-filter-table tbody tr.is-off td { opacity: 0.62; }
    .gd-filter-table tbody tr.is-absent td .gd-filter-name { text-decoration: line-through dotted; }
    .gd-filter-table .gd-filter-name {
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }
    .gd-filter-table .gd-filter-src {
        display: block;
        font-size: 0.72rem;
        color: var(--gd-text-secondary);
        font-weight: 500;
    }
    .gd-filter-table input.gd-input { width: 84px; text-align: center; padding: 0.4rem 0.5rem; font-weight: 700; }
    .gd-filter-preview {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 0.78rem;
        color: var(--gd-text-secondary);
        white-space: nowrap;
    }
    .gd-filter-preview.is-on { color: var(--gd-text-primary); }
    .gd-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.15rem 0.5rem;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: uppercase;
    }
    .gd-pill-warn  { background: rgba(245,158,11,0.16); color: #f59e0b; }
    .gd-pill-info  { background: rgba(59,130,246,0.14); color: #3b82f6; }
    .gd-pill-muted { background: rgba(107,114,128,0.16); color: #9ca3af; }
    .gd-pill-ok    { background: rgba(34,197,94,0.14); color: #22c55e; }

    /* ── KPI cards (métricas) ── */
    .gd-kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }
    @media (min-width: 768px)  { .gd-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (min-width: 1280px) { .gd-kpi-grid { grid-template-columns: repeat(var(--gd-kpi-cols, 5), minmax(0, 1fr)); } }
    .gd-kpi {
        border: 1px solid var(--gd-border);
        border-radius: 12px;
        padding: 0.9rem 1rem;
        background: var(--gd-input-bg);
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        min-width: 0;
    }
    .gd-kpi .kpi-label {
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--gd-text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }
    .gd-kpi .kpi-label i { color: var(--gd-accent); }
    .gd-kpi .kpi-value {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--gd-text-primary);
        line-height: 1.1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .gd-kpi .kpi-sub {
        font-size: 0.74rem;
        color: var(--gd-text-secondary);
    }
    .gd-kpi-green .kpi-value { color: #22c55e; }
    .gd-kpi-amber .kpi-value { color: #f59e0b; }
    .gd-kpi-blue  .kpi-value { color: #3b82f6; }
    .gd-kpi-red   .kpi-value { color: #ef4444; }

    /* ── Progress bars (desglose) ── */
    .gd-bar {
        height: 8px;
        border-radius: 999px;
        background: rgba(128,128,128,0.15);
        overflow: hidden;
    }
    .gd-bar > span {
        display: block;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #f59e0b, #d97706);
        transition: width .5s ease;
    }
    .gd-bar-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.35rem 0.75rem;
        align-items: center;
        padding: 0.45rem 0;
        border-bottom: 1px dashed var(--gd-border);
    }
    .gd-bar-row:last-child { border-bottom: none; }
    .gd-bar-row .bar-label {
        font-size: 0.84rem;
        font-weight: 600;
        color: var(--gd-text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .gd-bar-row .bar-meta {
        font-size: 0.76rem;
        font-weight: 700;
        color: var(--gd-text-secondary);
        white-space: nowrap;
    }
    .gd-bar-row .gd-bar { grid-column: 1 / -1; }

    /* ── Participation ring ── */
    .gd-ring {
        --p: 0;
        width: 92px;
        height: 92px;
        border-radius: 50%;
        background: conic-gradient(#22c55e calc(var(--p) * 1%), rgba(128,128,128,0.18) 0);
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .gd-ring > span {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        background: var(--gd-bg-card);
        display: grid;
        place-items: center;
        font-weight: 800;
        font-size: 1.05rem;
        color: var(--gd-text-primary);
    }

    .gd-card-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.5rem;
        margin-top: 1rem;
    }
    .gd-unsaved {
        display: none;
        align-items: center;
        gap: 0.35rem;
        margin-right: auto;
        font-size: 0.78rem;
        font-weight: 700;
        color: #f59e0b;
    }
    .gd-unsaved.is-visible { display: inline-flex; }

    /* ── Choice group (radios como tarjetas) ── */
    .gd-choice-group {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        border: 0;
        margin: 0;
        padding: 0;
        min-width: 0;
    }
    @media (min-width: 768px) { .gd-choice-group { grid-template-columns: 1fr 1fr; } }
    .gd-choice-group > legend {
        position: absolute;
        width: 1px; height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
    }
    .gd-choice {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.9rem 1rem;
        border: 1px solid var(--gd-border);
        border-radius: 12px;
        background: var(--gd-input-bg);
        cursor: pointer;
        transition: border-color .2s, box-shadow .2s;
    }
    .gd-choice:hover { border-color: var(--gd-accent); }
    .gd-choice input[type="radio"] {
        flex: 0 0 auto;
        width: 1.1rem; height: 1.1rem;
        margin-top: 0.15rem;
        accent-color: var(--gd-accent);
        cursor: pointer;
    }
    .gd-choice:has(input:checked) {
        border-color: var(--gd-accent);
        box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
    }
    .gd-choice:has(input:focus-visible) {
        outline: 2px solid var(--gd-accent);
        outline-offset: 2px;
    }
    .gd-choice .choice-body { display: flex; flex-direction: column; gap: 0.2rem; min-width: 0; }
    .gd-choice .choice-title {
        font-size: 0.88rem;
        font-weight: 700;
        color: var(--gd-text-primary);
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }
    .gd-choice .choice-title i { color: var(--gd-accent); }
    .gd-choice .choice-desc { font-size: 0.78rem; color: var(--gd-text-secondary); }

    @media (max-width: 640px) {
        .gd-filter-table thead { display: none; }
        .gd-filter-table tbody tr { display: block; margin-bottom: 0.75rem; border: 1px solid var(--gd-border); border-radius: 10px; padding: 0.4rem 0.2rem; }
        .gd-filter-table tbody td { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; border-bottom: 1px dashed var(--gd-border); padding: 0.5rem 0.6rem; }
        .gd-filter-table tbody td::before { content: attr(data-label); font-weight: 600; color: var(--gd-text-secondary); font-size: 0.72rem; text-transform: uppercase; }
        .gd-filter-table tbody tr td:last-child { border-bottom: none; }
        .gd-filter-preview { white-space: normal; text-align: right; }
    }
</style>

<div class="gd-page-head">
    <div>
        <h1><i class="fas fa-dice" style="color:var(--gd-accent);"></i> Giro Diario <small>· <?php echo htmlspecialchars($gdTabs[$gdCurrent]['label'] ?? ''); ?></small></h1>
        <p><?php echo htmlspecialchars($gdTabs[$gdCurrent]['sub'] ?? ''); ?></p>
    </div>
    <nav class="gd-tabs" aria-label="Secciones del Giro Diario">
        <?php foreach ($gdTabs as $k => $t): ?>
        <a href="<?php echo htmlspecialchars($t['href']); ?>" class="gd-tab <?php echo $k === $gdCurrent ? 'is-active' : ''; ?>" <?php echo $k === $gdCurrent ? 'aria-current="page"' : ''; ?>><i class="fas <?php echo htmlspecialchars($t['icon']); ?>"></i><?php echo htmlspecialchars($t['label']); ?></a>
        <?php endforeach; ?>
    </nav>
</div>

<script>
// Helpers compartidos por las páginas del Giro Diario.
window.GD = (function () {
    'use strict';
    const API_URL = '/wabo/giro_diario/api_girodiario.php';

    function avatarUrl(avatarFilename, username) {
        if (avatarFilename) return '/assets/avatars/' + avatarFilename;
        const initial = (username || 'U').trim().charAt(0) || 'U';
        return 'https://ui-avatars.com/api/?name=' + encodeURIComponent(initial) + '&background=f59e0b&color=fff&bold=true&size=128';
    }

    function escapeHtml(str) {
        if (str === null || str === undefined || str === '') return '';
        const d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const d = new Date(dateStr);
        if (isNaN(d)) return dateStr;
        return d.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' +
               d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' });
    }

    function formatInt(n) {
        return Number(n || 0).toLocaleString('es-PE');
    }

    function formatPct(n, digits = 1) {
        return Number(n || 0).toLocaleString('es-PE', { minimumFractionDigits: 0, maximumFractionDigits: digits }) + '%';
    }

    async function apiCall(action, data = {}) {
        const body = new URLSearchParams();
        body.append('action', action);
        for (const [k, v] of Object.entries(data)) {
            if (v === undefined || v === null) continue;
            body.append(k, v);
        }
        const res = await fetch(API_URL, { method: 'POST', body });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    function themeVar(name, fallback) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    }

    function toast(title, icon = 'success') {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: icon,
            title: title,
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true,
            background: themeVar('--secondary', '#0a0a0a'),
            color: themeVar('--text-primary', '#f1f5f9'),
        });
    }

    function toggleSection(bodyId, headerEl) {
        const body = document.getElementById(bodyId);
        if (!body) return;
        const chevron = headerEl ? headerEl.querySelector('.collapse-chevron') : null;
        if (body.style.display === 'none') {
            body.style.display = '';
            chevron && chevron.classList.remove('collapsed');
        } else {
            body.style.display = 'none';
            chevron && chevron.classList.add('collapsed');
        }
    }

    // Misma cadena que ve el jugador en el desplegable "Filtros".
    function filterOptionText(label, percent, count) {
        const n = Number(count || 0);
        return `${label} (${Number(percent || 0)}% OFF) - ${formatInt(n)} ${n === 1 ? 'ítem' : 'ítems'}`;
    }

    return { API_URL, avatarUrl, escapeHtml, formatDate, formatInt, formatPct, apiCall, toast, themeVar, toggleSection, filterOptionText };
})();
window.toggleSection = window.GD.toggleSection;
</script>

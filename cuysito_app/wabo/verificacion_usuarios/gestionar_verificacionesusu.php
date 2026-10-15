<?php
// === CÓDIGO DE DEPURACIÓN ===
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// === FIN DE CÓDIGO DE DEPURACIÓN ===

require_once __DIR__ . '/../core/admin_header.php';
require_once __DIR__ . '/../core/db_connect.php';

// --- Cargar solicitudes de verificación con COUNT ligero de pedidos completados ---
$all_requests = [];
$sql = "SELECT
            vr.id,
            vr.user_id,
            vr.calculated_spend,
            COALESCE(NULLIF(vr.game_username_snapshot, ''), u.game_username) as game_username_snapshot,
            COALESCE(NULLIF(vr.whatsapp_snapshot, ''), u.whatsapp_phone_e164) as whatsapp_snapshot,
            vr.status,
            vr.created_at,
            vr.reviewed_at,
            vr.admin_notes,
            (SELECT COUNT(*) FROM pedidos p WHERE p.user_id = vr.user_id AND p.estado = 'completado') as completed_orders
        FROM verification_requests vr
        LEFT JOIN users u ON vr.user_id = u.id
        ORDER BY
            CASE vr.status WHEN 'pending' THEN 0 ELSE 1 END ASC,
            vr.id DESC
        LIMIT 300";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_requests[] = $row;
    }
}

$conn->close();

$requests_json = json_encode(array_values($all_requests), JSON_UNESCAPED_UNICODE);
?>

<style>
    :root {
        --bg-total-dark: #050505;
        --bg-total-dark-surface: #0a0a0a;
        --border-total-dark: #151515;
        --row-total-dark: #0a0a0a;
        --row-total-dark-alt: #0d0d0d;
        --row-total-dark-hover: #111111;

        --bg-light-main: #f1f3f5;
        --bg-light-surface: #ffffff;
        --row-light: #ffffff;
        --row-light-alt: #f9f9f9;
        --row-light-hover: #f3f4f6;
        --border-light: #e9ecef;
        --text-light-primary: #212529;
        --text-light-secondary: #4a5568;

        --vr-bg-main: var(--primary);
        --vr-bg-card: var(--secondary);
        --vr-border: var(--border-color);
        --vr-row-bg: var(--vr-bg-card);
        --vr-row-bg-alt: var(--vr-bg-card);
        --vr-row-hover-bg: rgba(255, 255, 255, 0.04);
        --vr-row-hover-shadow-soft: 0 8px 18px rgba(0, 0, 0, 0.22);
        --vr-cell-divider: rgba(148, 163, 184, 0.25);
        --vr-text-primary: var(--text-primary);
        --vr-text-secondary: var(--text-secondary);
        --vr-text-header: var(--text-secondary);

        --vr-modal-bg: var(--secondary);
        --vr-modal-surface: var(--secondary);
        --vr-modal-muted: rgba(148, 163, 184, 0.12);
        --vr-modal-border: var(--border-color);
        --vr-modal-footer: var(--secondary);
        --vr-modal-text-primary: var(--text-primary);
        --vr-modal-text-secondary: var(--text-secondary);
    }

    html[data-theme="dark"] {
        --vr-bg-main: var(--bg-total-dark);
        --vr-bg-card: var(--bg-total-dark-surface);
        --vr-border: var(--border-total-dark);
        --vr-row-bg: var(--row-total-dark);
        --vr-row-bg-alt: var(--row-total-dark-alt);
        --vr-row-hover-bg: var(--row-total-dark-hover);
        --vr-row-hover-shadow-soft: none;
        --vr-cell-divider: var(--border-total-dark);
        --vr-text-primary: #f3f4f6;
        --vr-text-secondary: #a1a1aa;
        --vr-text-header: #cbd5e1;

        --vr-modal-bg: var(--bg-total-dark);
        --vr-modal-surface: var(--bg-total-dark-surface);
        --vr-modal-muted: rgba(255, 255, 255, 0.06);
        --vr-modal-border: var(--border-total-dark);
        --vr-modal-footer: var(--bg-total-dark-surface);
        --vr-modal-text-primary: #f3f4f6;
        --vr-modal-text-secondary: #a1a1aa;
    }

    body.light-mode,
    html[data-theme="light"] {
        --vr-bg-main: var(--bg-light-main);
        --vr-bg-card: var(--bg-light-surface);
        --vr-border: var(--border-light);
        --vr-row-bg: var(--row-light);
        --vr-row-bg-alt: var(--row-light-alt);
        --vr-row-hover-bg: var(--row-light-hover);
        --vr-row-hover-shadow-soft: 0 8px 20px rgba(16, 24, 40, 0.10);
        --vr-cell-divider: #edf2f7;
        --vr-text-primary: var(--text-light-primary);
        --vr-text-secondary: var(--text-light-secondary);
        --vr-text-header: var(--text-light-secondary);

        --vr-modal-bg: #ffffff;
        --vr-modal-surface: #ffffff;
        --vr-modal-muted: #f3f4f6;
        --vr-modal-border: #e5e7eb;
        --vr-modal-footer: #f9fafb;
        --vr-modal-text-primary: #111827;
        --vr-modal-text-secondary: #4b5563;
    }

    #content-wrapper > main {
        background-color: var(--vr-bg-main);
        transition: background-color 0.3s ease, color 0.3s ease;
    }

    .vr-card {
        background-color: var(--vr-bg-card) !important;
        border: 1px solid var(--vr-border);
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }
    html[data-theme="dark"] .vr-card { box-shadow: none !important; }

    .tabla-verificaciones { background-color: transparent; }
    .tabla-verificaciones thead { background-color: transparent; }
    .tabla-verificaciones thead th {
        border-bottom: 1px solid var(--vr-border);
        color: var(--vr-text-header) !important;
        transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
    }
    .tabla-verificaciones tbody tr {
        border-bottom: 1px solid var(--vr-border);
        background-color: var(--vr-row-bg);
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }
    .tabla-verificaciones tbody tr:nth-child(even) { background-color: var(--vr-row-bg-alt); }
    .tabla-verificaciones tbody tr:last-child { border-bottom: none; }

    .vr-text-primary { color: var(--vr-text-primary) !important; }
    .vr-text-secondary { color: var(--vr-text-secondary) !important; }

    .status-badge { display: inline-flex; align-items: center; padding: 0.3rem 0.6rem; border-radius: 10px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; border: 1px solid rgba(var(--badge-rgb), 0.40); background: rgba(var(--badge-rgb), 0.14); color: rgb(var(--badge-rgb)); box-shadow: none; }
    .status-badge--yellow { --badge-rgb: 245, 158, 11; }
    .status-badge--green  { --badge-rgb: 34, 197, 94; }
    .status-badge--blue   { --badge-rgb: 59, 130, 246; }
    .status-badge--red    { --badge-rgb: 239, 68, 68; }
    .status-badge--gray   { --badge-rgb: 107, 114, 128; }

    .clickable-row { cursor: pointer; transition: transform 0.2s, background-color 0.3s ease, box-shadow 0.2s, filter 0.2s, border-color 0.3s ease; }
    .clickable-row:hover { transform: translateY(-2px); background-color: var(--vr-row-hover-bg); box-shadow: var(--vr-row-hover-shadow-soft); filter: drop-shadow(0 12px 22px rgba(16, 24, 40, 0.10)); }
    html[data-theme="dark"] .clickable-row:hover { box-shadow: none; filter: none; }

    .admin-toast {
        position: fixed; bottom: 24px; right: 24px; z-index: 99999;
        opacity: 0; transform: translateY(10px);
        transition: opacity .18s ease, transform .18s ease;
        padding: 10px 14px; border-radius: 12px;
        background: rgba(17, 24, 39, 0.92); color: #fff;
        font-weight: 800; font-size: 0.8rem;
        box-shadow: 0 12px 28px rgba(0,0,0,0.18);
    }
    .admin-toast.show { opacity: 1; transform: translateY(0); }

    .data-badge {
        border-radius: 10px;
        border: 1px solid var(--vr-modal-border);
        background: var(--vr-modal-surface);
        color: var(--vr-modal-text-primary);
        transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, filter 0.2s ease;
    }
    .data-badge--disabled { opacity: 0.55; cursor: not-allowed; }
    .data-badge--copied { border-color: rgba(16, 185, 129, 0.45); background: rgba(16, 185, 129, 0.12); }

    .modal-skeleton {
        position: relative; overflow: hidden; border-radius: 6px;
        background: var(--vr-modal-muted);
    }
    .modal-skeleton::after {
        content: ''; position: absolute; top: 0; left: -60%; width: 60%; height: 100%;
        background: linear-gradient(90deg, transparent, rgba(226, 232, 240, 0.16), transparent);
        animation: vrSkeletonShimmer 1.05s infinite;
    }
    @keyframes vrSkeletonShimmer { 0% { transform: translateX(0); } 100% { transform: translateX(180%); } }

    #pagination-controls {
        border-top: 1px solid var(--vr-border);
        background-color: var(--vr-bg-card);
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }

    html[data-theme="dark"] #searchInput,
    html[data-theme="dark"] #sortOrder {
        background-color: var(--vr-bg-card) !important;
        border-color: var(--vr-border) !important;
        color: var(--vr-text-primary) !important;
    }
    html[data-theme="dark"] #searchInput::placeholder { color: rgba(161, 161, 170, 0.85) !important; }
    html[data-theme="dark"] #status-tabs .tab-btn[data-status="all"] { background-color: var(--vr-bg-card) !important; color: var(--vr-text-secondary) !important; }
    html[data-theme="dark"] #status-tabs .tab-btn[data-status="all"]:hover { background-color: var(--vr-row-hover-bg) !important; }
    html[data-theme="dark"] #pagination-controls button:not(.bg-fortnite-blue) {
        background-color: var(--vr-bg-card) !important;
        border-color: var(--vr-border) !important;
        color: var(--vr-text-secondary) !important;
    }
    html[data-theme="dark"] #pagination-controls button:not(.bg-fortnite-blue):hover { background-color: var(--vr-row-hover-bg) !important; }

    /* --- Modal Theme Overrides --- */
    #review-modal .modal-inner-bg { background-color: var(--vr-modal-bg) !important; }
    #review-modal .modal-inner-surface { background-color: var(--vr-modal-surface) !important; }
    #review-modal .modal-inner-muted { background-color: var(--vr-modal-muted) !important; }
    #review-modal .modal-inner-border { border-color: var(--vr-modal-border) !important; }
    #review-modal .modal-text-primary { color: var(--vr-modal-text-primary) !important; }
    #review-modal .modal-text-secondary { color: var(--vr-modal-text-secondary) !important; }
    #review-modal .modal-footer-bg { background-color: var(--vr-modal-footer) !important; }

    #confirm-modal .inline-block.align-bottom {
        background-color: var(--vr-modal-bg) !important;
        border: 1px solid var(--vr-modal-border);
    }
    #confirm-modal .inline-block.align-bottom > div:first-child { background-color: var(--vr-modal-bg) !important; }
    #confirm-modal .inline-block.align-bottom > div:last-child { background-color: var(--vr-modal-footer) !important; }

    .vr-input {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border-radius: 0.5rem;
        border: 1px solid var(--vr-modal-border);
        background-color: var(--vr-modal-surface);
        color: var(--vr-modal-text-primary);
        font-size: 0.875rem;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .vr-input:focus { outline: none; border-color: #00B2FF; box-shadow: 0 0 0 2px rgba(0, 178, 255, 0.25); }
    .vr-input::placeholder { color: var(--vr-modal-text-secondary); opacity: 0.6; }

    /* --- Mobile Card View --- */
    @media (max-width: 768px) {
        .tabla-verificaciones thead { display: none; }
        .tabla-verificaciones tr {
            display: block; margin-bottom: 1rem;
            border: 1px solid var(--vr-border); border-radius: 0.5rem;
            padding: 1rem; background-color: var(--vr-row-bg);
            box-shadow: var(--vr-row-hover-shadow-soft);
        }
        html[data-theme="dark"] .tabla-verificaciones tr { box-shadow: none; }
        .tabla-verificaciones td {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.6rem 0; border-bottom: 1px dashed var(--vr-border); text-align: right;
        }
        .tabla-verificaciones td:last-child { border-bottom: none; }
        .tabla-verificaciones td::before {
            content: attr(data-label); font-weight: 600; text-align: left;
            margin-right: 1rem; color: var(--vr-text-header);
        }
    }

    @media (min-width: 769px) {
        .tabla-verificaciones { border-collapse: separate; border-spacing: 0 10px; }
        .tabla-verificaciones tbody tr { background-color: transparent; border-bottom: none; }
        .tabla-verificaciones tbody td {
            background-color: var(--vr-row-bg);
            border-top: 1px solid var(--vr-border);
            border-bottom: 1px solid var(--vr-border);
        }
        .tabla-verificaciones tbody td:first-child {
            border-left: 1px solid var(--vr-border);
            border-top-left-radius: 12px; border-bottom-left-radius: 12px;
        }
        .tabla-verificaciones tbody td:last-child {
            border-right: 1px solid var(--vr-border);
            border-top-right-radius: 12px; border-bottom-right-radius: 12px;
        }
        .tabla-verificaciones tbody tr:nth-child(even) td { background-color: var(--vr-row-bg); }
        .clickable-row:hover td { background-color: var(--vr-row-hover-bg); }
    }
    /* --- Order History Section --- */
    .order-history-scroll { max-height: 320px; overflow-y: auto; }
    .order-history-scroll::-webkit-scrollbar { width: 5px; }
    .order-history-scroll::-webkit-scrollbar-track { background: transparent; }
    .order-history-scroll::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.3); border-radius: 4px; }
    .order-card { border: 1px solid var(--vr-modal-border); border-radius: 0.5rem; padding: 0.75rem 1rem; transition: background-color 0.2s; }
    .order-card:hover { background-color: var(--vr-modal-muted); }
    .order-item-row { display: flex; justify-content: space-between; align-items: center; padding: 0.25rem 0; }
    .order-item-name { font-size: 0.8125rem; color: var(--vr-modal-text-primary); }
    .order-item-price { font-size: 0.8125rem; font-weight: 600; color: var(--vr-modal-text-primary); white-space: nowrap; margin-left: 0.75rem; }

    /* --- SweetAlert2 dark theme overrides --- */
    html[data-theme="dark"] .swal2-popup { background: var(--bg-total-dark-surface) !important; color: #f3f4f6 !important; border: 1px solid var(--border-total-dark) !important; }
    html[data-theme="dark"] .swal2-title { color: #f3f4f6 !important; }
    html[data-theme="dark"] .swal2-html-container { color: #a1a1aa !important; }
    html[data-theme="dark"] .swal2-input, html[data-theme="dark"] .swal2-textarea { background: var(--bg-total-dark) !important; color: #f3f4f6 !important; border-color: var(--border-total-dark) !important; }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="min-h-screen relative">

    <!-- Filters Section -->
    <div class="vr-card rounded-lg shadow-md p-4 mb-6 transition-colors duration-200">
        <div id="filters-container" class="flex flex-wrap items-center justify-between gap-4">
            <!-- Search -->
            <div class="relative flex-grow max-w-md">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400"></i>
                </div>
                <input type="text" id="searchInput" class="block w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-fortnite-blue focus:border-transparent transition" placeholder="Buscar por Nickname, WhatsApp, User ID...">
            </div>

            <!-- Status Tabs -->
            <div id="status-tabs" class="flex flex-wrap items-center gap-2">
                <button data-status="all" class="tab-btn px-3 py-1 rounded-full text-sm font-medium bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">Todos</button>
                <button data-status="pending" class="tab-btn px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200">Pendientes</button>
                <button data-status="approved" class="tab-btn px-3 py-1 rounded-full text-sm font-medium bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200">Aprobadas</button>
                <button data-status="rejected" class="tab-btn px-3 py-1 rounded-full text-sm font-medium bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200">Rechazadas</button>
            </div>

            <!-- Sort -->
            <div class="relative">
                <select id="sortOrder" class="appearance-none bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-white py-2 px-4 pr-8 rounded-lg leading-tight focus:outline-none focus:ring-2 focus:ring-fortnite-blue focus:border-transparent transition">
                    <option value="newest" selected>Más recientes</option>
                    <option value="oldest">Más antiguos</option>
                    <option value="spend_desc">Mayor gasto</option>
                    <option value="spend_asc">Menor gasto</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700 dark:text-gray-300">
                    <i class="fas fa-chevron-down text-xs"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Verification Requests Table -->
    <div class="vr-card rounded-lg shadow-md transition-colors duration-200">
        <div class="overflow-x-auto">
            <table class="min-w-full tabla-verificaciones">
                <thead>
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Solicitud</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">User ID</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Nickname Snapshot</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">WhatsApp Snapshot</th>
                        <th scope="col" class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">Pedidos</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Estado</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody id="requests-table-body">
                </tbody>
            </table>
        </div>
        <div id="no-results" class="text-center p-8 hidden"><p class="text-gray-500 dark:text-gray-400">No se encontraron solicitudes que coincidan con los filtros.</p></div>
        <div id="pagination-controls" class="px-4 py-3 flex items-center justify-between sm:px-6"></div>
    </div>
</div>

<!-- ============================================================= -->
<!-- === REVIEW MODAL ============================================= -->
<!-- ============================================================= -->
<div id="review-modal" class="fixed inset-0 overflow-y-auto z-50 hidden" aria-labelledby="review-modal-title" role="dialog" aria-modal="true" data-modal-backdrop>
    <div class="flex items-start justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-[8px] transition-opacity" aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom modal-inner-bg rounded-lg text-left overflow-hidden shadow-2xl transform transition-all sm:my-4 sm:align-middle sm:max-w-2xl sm:w-full animate-slide-up modal-inner-border" style="background-color: var(--vr-modal-bg); border: 1px solid var(--vr-modal-border);">
            <div class="px-4 pt-5 pb-4 sm:p-5 sm:pb-4 min-h-[200px] relative text-base" style="background-color: var(--vr-modal-bg);">

                <!-- Loader -->
                <div id="review-modal-loader" class="absolute inset-0 flex items-center justify-center hidden z-10" style="background-color: rgba(0,0,0,0.55);">
                    <i class="fas fa-spinner fa-spin text-4xl text-fortnite-blue"></i>
                </div>

                <!-- Content Wrapper -->
                <div id="review-modal-content">

                    <!-- Header -->
                    <div class="flex items-center justify-between gap-4 mb-5">
                        <div class="flex items-center gap-2">
                            <span class="text-[11px] font-bold tracking-[0.22em] uppercase font-mono modal-text-secondary" style="color: var(--vr-modal-text-secondary);">Solicitud:</span>
                            <span id="rm-request-id" class="font-mono text-sm font-semibold" style="color: var(--vr-modal-text-primary);"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div id="rm-status-badge"></div>
                            <button data-close-button type="button" class="h-7 w-7 rounded-md border flex items-center justify-center transition hover:brightness-125" style="border-color: var(--vr-modal-border); color: var(--vr-modal-text-secondary);" aria-label="Cerrar">
                                <i class="fas fa-times text-[12px]"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Request Info Section -->
                    <div class="mt-4 space-y-5">
                        <div>
                            <div class="text-sm font-bold tracking-[0.22em] uppercase flex items-center mb-3" style="color: var(--vr-modal-text-secondary);"><i class="fas fa-file-alt mr-2"></i> Datos de la Solicitud</div>
                            <div class="rounded-lg p-4" style="background-color: var(--vr-modal-surface); border: 1px solid var(--vr-modal-border);">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <div class="text-[10px] font-bold tracking-[0.22em] uppercase mb-1" style="color: var(--vr-modal-text-secondary);">USER ID</div>
                                        <div id="rm-user-id" class="text-sm font-semibold" style="color: var(--vr-modal-text-primary);"></div>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-bold tracking-[0.22em] uppercase mb-1" style="color: var(--vr-modal-text-secondary);">GASTO CALCULADO</div>
                                        <div id="rm-calculated-spend" class="text-sm font-semibold" style="color: var(--vr-modal-text-primary);"></div>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-bold tracking-[0.22em] uppercase mb-1" style="color: var(--vr-modal-text-secondary);">NICKNAME SNAPSHOT</div>
                                        <div id="rm-snapshot-nick" class="text-sm font-semibold" style="color: var(--vr-modal-text-primary);"></div>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-bold tracking-[0.22em] uppercase mb-1" style="color: var(--vr-modal-text-secondary);">WHATSAPP SNAPSHOT</div>
                                        <div id="rm-snapshot-whatsapp-box" class="data-badge inline-flex items-center h-9 px-3 gap-2 w-fit max-w-full hover:brightness-110 transition" role="button" tabindex="0" title="Click para copiar">
                                            <button id="rm-snapshot-whatsapp-open" type="button" class="shrink-0 text-emerald-400 hover:text-emerald-300 transition" title="Abrir WhatsApp">
                                                <i class="fab fa-whatsapp text-[18px]"></i>
                                            </button>
                                            <span id="rm-snapshot-whatsapp" class="text-[14px] font-semibold tracking-wide whitespace-nowrap" style="color: var(--vr-modal-text-primary);"></span>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-bold tracking-[0.22em] uppercase mb-1" style="color: var(--vr-modal-text-secondary);">PEDIDOS COMPLETADOS</div>
                                        <div id="rm-completed-orders" class="text-sm font-semibold" style="color: var(--vr-modal-text-primary);"></div>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-bold tracking-[0.22em] uppercase mb-1" style="color: var(--vr-modal-text-secondary);">FECHA DE SOLICITUD</div>
                                        <div id="rm-request-date" class="text-sm font-semibold" style="color: var(--vr-modal-text-primary);"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Order History Section -->
                        <div id="rm-order-history-section">
                            <div class="text-sm font-bold tracking-[0.22em] uppercase flex items-center justify-between mb-3" style="color: var(--vr-modal-text-secondary);">
                                <span><i class="fas fa-receipt mr-2"></i> Historial de Pedidos Completados</span>
                                <span id="rm-total-spent" class="text-emerald-400 text-sm font-bold normal-case tracking-normal"></span>
                            </div>
                            <div class="rounded-lg" style="background-color: var(--vr-modal-surface); border: 1px solid var(--vr-modal-border);">
                                <div id="rm-order-history-container" class="order-history-scroll p-3 space-y-2">
                                    <p class="text-center text-sm py-4" style="color: var(--vr-modal-text-secondary);">Cargando historial...</p>
                                </div>
                            </div>
                        </div>

                        <!-- Editable Fields Section -->
                        <div id="rm-editable-section">
                            <div class="text-sm font-bold tracking-[0.22em] uppercase flex items-center mb-3" style="color: var(--vr-modal-text-secondary);"><i class="fas fa-edit mr-2"></i> Editar Datos del Usuario (Opcional)</div>
                            <div class="rounded-lg p-4 space-y-4" style="background-color: var(--vr-modal-surface); border: 1px solid var(--vr-modal-border);">
                                <div>
                                    <label for="rm-edit-game-username" class="block text-[11px] font-bold tracking-[0.18em] uppercase mb-1.5" style="color: var(--vr-modal-text-secondary);">Game Username</label>
                                    <input type="text" id="rm-edit-game-username" class="vr-input" placeholder="Game Username actual del usuario">
                                </div>
                                <div>
                                    <label for="rm-edit-display-name" class="block text-[11px] font-bold tracking-[0.18em] uppercase mb-1.5" style="color: var(--vr-modal-text-secondary);">Public Display Name</label>
                                    <input type="text" id="rm-edit-display-name" class="vr-input" placeholder="Display Name actual del usuario">
                                </div>
                                <div>
                                    <label for="rm-edit-whatsapp" class="block text-[11px] font-bold tracking-[0.18em] uppercase mb-1.5" style="color: var(--vr-modal-text-secondary);">WhatsApp (E.164)</label>
                                    <input type="text" id="rm-edit-whatsapp" class="vr-input" placeholder="+51999888777">
                                </div>
                            </div>
                        </div>

                        <!-- Admin Notes -->
                        <div>
                            <label for="rm-admin-notes" class="block text-sm font-bold tracking-[0.22em] uppercase mb-2 flex items-center" style="color: var(--vr-modal-text-secondary);"><i class="fas fa-sticky-note mr-2"></i> Notas del Admin</label>
                            <textarea id="rm-admin-notes" rows="3" class="vr-input" placeholder="Notas opcionales para esta revisión..."></textarea>
                        </div>

                        <!-- Action Buttons -->
                        <div id="rm-action-buttons" class="flex items-center justify-end gap-3 pt-2">
                            <button id="rm-reject-btn" type="button" class="px-5 py-2.5 rounded-lg text-sm font-bold border border-red-500/40 text-red-400 hover:bg-red-500/10 transition flex items-center gap-2">
                                <i class="fas fa-times-circle"></i> Rechazar
                            </button>
                            <button id="rm-approve-btn" type="button" class="px-5 py-2.5 rounded-lg text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-500 transition shadow-md shadow-emerald-600/20 flex items-center gap-2">
                                <i class="fas fa-check-circle"></i> Aprobar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse" style="background-color: var(--vr-modal-footer); border-top: 1px solid var(--vr-modal-border);">
                <button data-close-button type="button" class="w-full inline-flex justify-center rounded-lg border shadow-sm px-4 py-2 text-base font-semibold sm:ml-3 sm:w-auto sm:text-sm transition" style="border-color: var(--vr-modal-border); background-color: var(--vr-modal-surface); color: var(--vr-modal-text-primary);">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm modal removed — replaced by SweetAlert2 -->

<script>
// ===============================================================
// === JAVASCRIPT: Motor de la Gestión de Verificaciones        ===
// ===============================================================

// --- ESTADO Y DATOS GLOBALES ---
let allRequests = <?php echo $requests_json; ?>;
let currentFilters = { status: 'pending', search: '', sort: 'newest' };
let currentPage = 1;
const requestsPerPage = 15;
let currentModalRequestId = null;

const STATUS_CONFIG = {
    'pending':  { text: 'PENDIENTE',  icon: 'fa-clock',       color: 'yellow' },
    'approved': { text: 'APROBADA',   icon: 'fa-check-circle', color: 'green' },
    'rejected': { text: 'RECHAZADA',  icon: 'fa-times-circle', color: 'red' },
    'default':  { text: 'DESCONOCIDO', icon: 'fa-question-circle', color: 'gray' }
};

// --- HELPERS ---
function escapeHtml(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function showAdminToast(message) {
    const el = document.createElement('div');
    el.className = 'admin-toast';
    el.textContent = String(message || '');
    document.body.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 220); }, 1800);
}

function copyToClipboard(text, element) {
    navigator.clipboard.writeText(text).then(() => {
        if (element && element.classList) {
            if (element.classList.contains('data-badge')) {
                element.classList.add('data-badge--copied');
                setTimeout(() => element.classList.remove('data-badge--copied'), 1200);
                return;
            }
            const icon = element.querySelector('i');
            if (icon) {
                const originalIcon = icon.className;
                icon.className = 'fas fa-check text-green-500';
                setTimeout(() => { icon.className = originalIcon; }, 1500);
            }
        }
        showAdminToast('Copiado al portapapeles');
    }).catch(err => console.error('Error al copiar:', err));
}

function attachCopyBadge(el, text) {
    if (!el) return;
    const safeText = String(text ?? '').trim();
    const isDisabled = (!safeText || safeText === 'No provisto' || safeText === '-');
    el.classList.toggle('data-badge--disabled', isDisabled);
    if (isDisabled) { el.onclick = null; return; }
    el.onclick = (e) => { if (e) e.stopPropagation(); copyToClipboard(safeText, el); };
}

function timeAgo(dateString) {
    const date = new Date(dateString + " UTC");
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    if (seconds < 0) return 'Recién';
    if (seconds < 60) return 'Hace un momento';
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);
    const months = Math.floor(days / 30);
    if (months > 0) return `Hace ${months} mes${months > 1 ? 'es' : ''}`;
    if (days > 0) return `Hace ${days} día${days > 1 ? 's' : ''}`;
    if (hours > 0) { const m = minutes % 60; return m > 0 ? `Hace ${hours}h ${m}m` : `Hace ${hours} hora${hours > 1 ? 's' : ''}`; }
    return `Hace ${minutes} min`;
}

function formatModalDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString + " UTC");
    return date.toLocaleString('es-PE', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'America/Lima', hour12: true });
}

// --- MODALES ---
const reviewModal = document.getElementById('review-modal');

function openModal(modal) { if (modal) modal.classList.remove('hidden'); }
function closeModal(modal) { if (modal) modal.classList.add('hidden'); }

// --- RENDERIZADO ---
function renderPage() {
    const tbody = document.getElementById('requests-table-body');
    const noResultsDiv = document.getElementById('no-results');
    tbody.innerHTML = '';

    // 1. Filtrado
    let filtered = allRequests.filter(req => {
        const statusMatch = currentFilters.status === 'all' || req.status === currentFilters.status;
        const searchLower = currentFilters.search.toLowerCase();
        const searchMatch = !searchLower ||
            (req.game_username_snapshot && req.game_username_snapshot.toLowerCase().includes(searchLower)) ||
            (req.whatsapp_snapshot && req.whatsapp_snapshot.includes(searchLower)) ||
            (String(req.user_id).includes(searchLower)) ||
            (String(req.id).includes(searchLower));
        return statusMatch && searchMatch;
    });

    // 2. Ordenamiento
    filtered.sort((a, b) => {
        switch (currentFilters.sort) {
            case 'oldest':     return new Date(a.created_at) - new Date(b.created_at);
            case 'spend_desc': return parseFloat(b.calculated_spend || 0) - parseFloat(a.calculated_spend || 0);
            case 'spend_asc':  return parseFloat(a.calculated_spend || 0) - parseFloat(b.calculated_spend || 0);
            case 'newest':
            default:           return new Date(b.created_at) - new Date(a.created_at);
        }
    });

    // 3. Paginación
    const totalResults = filtered.length;
    const totalPages = Math.ceil(totalResults / requestsPerPage);
    currentPage = Math.min(currentPage, totalPages) || 1;
    const start = (currentPage - 1) * requestsPerPage;
    const end = start + requestsPerPage;
    const paginated = filtered.slice(start, end);

    if (paginated.length === 0) {
        noResultsDiv.classList.remove('hidden');
    } else {
        noResultsDiv.classList.add('hidden');
        paginated.forEach(req => tbody.appendChild(createRequestRow(req)));
    }

    renderPagination(totalResults, totalPages, start, end);
}

function createRequestRow(req) {
    const row = document.createElement('tr');
    row.className = 'clickable-row';
    row.onclick = () => openReviewModal(req.id);

    const statusInfo = STATUS_CONFIG[req.status] || STATUS_CONFIG.default;

    // WhatsApp cell
    let whatsappHtml = '<span class="text-gray-400">N/A</span>';
    if (req.whatsapp_snapshot) {
        const digits = req.whatsapp_snapshot.replace(/\D/g, '');
        const displayNum = escapeHtml(req.whatsapp_snapshot);
        whatsappHtml = `
            <div class="inline-flex items-center gap-1.5" onclick="event.stopPropagation()">
                <button onclick="event.stopPropagation(); window.open('https://wa.me/${digits}', '_blank')" title="Abrir WhatsApp" class="text-green-500 hover:text-green-400 transition p-1">
                    <i class="fab fa-whatsapp text-lg"></i>
                </button>
                <span onclick="event.stopPropagation(); copyToClipboard('${digits}', this)" class="font-mono text-sm cursor-pointer hover:text-blue-400 transition vr-text-primary" title="Click para copiar">${displayNum}</span>
            </div>`;
    }

    // Completed orders badge
    const completedCount = parseInt(req.completed_orders || 0);
    const ordersBadgeColor = completedCount > 0 ? 'blue' : 'gray';

    // Action buttons (Review + Quick Approve/Reject for pending)
    let actionHtml = '';
    if (req.status === 'pending') {
        actionHtml = `
            <button onclick="event.stopPropagation(); openReviewModal(${req.id})" class="px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-fortnite-blue hover:brightness-110 transition flex items-center gap-1.5" title="Revisar detalles"><i class="fas fa-search"></i> Revisar</button>
            <button onclick="event.stopPropagation(); swalQuickApprove(${req.id})" class="px-2.5 py-1.5 rounded-lg text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 transition flex items-center gap-1" title="Aprobar rápido"><i class="fas fa-check"></i></button>
            <button onclick="event.stopPropagation(); swalQuickReject(${req.id})" class="px-2.5 py-1.5 rounded-lg text-xs font-bold text-white bg-red-600 hover:bg-red-500 transition flex items-center gap-1" title="Rechazar rápido"><i class="fas fa-times"></i></button>`;
    } else {
        actionHtml = `<button onclick="event.stopPropagation(); openReviewModal(${req.id})" class="px-3 py-1.5 rounded-lg text-xs font-bold border transition flex items-center gap-1.5" style="border-color: var(--vr-border); color: var(--vr-text-secondary);"><i class="fas fa-eye"></i> Ver</button>`;
    }

    row.innerHTML = `
        <td data-label="Solicitud" class="px-6 py-4 whitespace-nowrap">
            <div>
                <div class="text-sm font-medium vr-text-primary">#${req.id}</div>
                <div class="text-xs vr-text-secondary">${timeAgo(req.created_at)}</div>
            </div>
        </td>
        <td data-label="User ID" class="px-6 py-4 whitespace-nowrap">
            <span class="text-sm font-mono font-semibold vr-text-primary">${req.user_id}</span>
        </td>
        <td data-label="Nickname" class="px-6 py-4 whitespace-nowrap">
            <div class="text-sm font-medium vr-text-primary">${escapeHtml(req.game_username_snapshot || '-')}</div>
        </td>
        <td data-label="WhatsApp" class="px-6 py-4 whitespace-nowrap text-sm">${whatsappHtml}</td>
        <td data-label="Pedidos" class="px-6 py-4 whitespace-nowrap text-center">
            <span class="status-badge status-badge--${ordersBadgeColor}"><i class="fas fa-shopping-cart mr-1"></i> ${completedCount}</span>
        </td>
        <td data-label="Estado" class="px-6 py-4 whitespace-nowrap">
            <span class="status-badge status-badge--${statusInfo.color}"><i class="fas ${statusInfo.icon} mr-1"></i> ${statusInfo.text}</span>
        </td>
        <td data-label="Acciones" class="px-6 py-4 whitespace-nowrap text-right">
            <div class="flex items-center justify-end gap-1.5">${actionHtml}</div>
        </td>`;

    return row;
}

function renderPagination(totalResults, totalPages, start, end) {
    const container = document.getElementById('pagination-controls');
    if (totalResults === 0) { container.innerHTML = ''; return; }

    container.innerHTML = `
        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    Mostrando <span class="font-medium">${start + 1}</span> a <span class="font-medium">${Math.min(end, totalResults)}</span> de <span class="font-medium">${totalResults}</span> resultados
                </p>
            </div>
            <div>
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm font-medium text-gray-500 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    ${Array.from({ length: totalPages }, (_, i) => i + 1).map(pageNumber => `
                        <button onclick="changePage(${pageNumber})" class="${currentPage === pageNumber ? 'z-10 bg-fortnite-blue border-fortnite-blue text-white' : 'bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600'} relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                            ${pageNumber}
                        </button>
                    `).join('')}
                    <button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm font-medium text-gray-500 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </nav>
            </div>
        </div>`;
}

function changePage(page) {
    const next = Number(page);
    if (!Number.isFinite(next)) return;
    currentPage = next;
    renderPage();
}
window.changePage = changePage;

// --- REVIEW MODAL LOGIC ---
async function openReviewModal(requestId) {
    currentModalRequestId = requestId;
    openModal(reviewModal);

    const loader = document.getElementById('review-modal-loader');
    const content = document.getElementById('review-modal-content');
    if (loader) loader.classList.remove('hidden');

    try {
        const response = await fetch(`/wabo/verificacion_usuarios/api_verificacionusu.php?action=get_request_details&request_id=${requestId}`);
        const result = await response.json();

        if (!response.ok || result.status !== 'success') {
            throw new Error(result.message || 'Error desconocido');
        }

        populateReviewModal(result);
    } catch (error) {
        console.error('Error al cargar detalles:', error);
        showAdminToast('Error: ' + error.message);
        closeModal(reviewModal);
    } finally {
        if (loader) loader.classList.add('hidden');
    }
}
window.openReviewModal = openReviewModal;

function populateReviewModal(data) {
    const req = data.request;
    const user = data.user;
    const completedOrders = data.completed_orders;
    const statusInfo = STATUS_CONFIG[req.status] || STATUS_CONFIG.default;
    const isPending = req.status === 'pending';

    // Header
    document.getElementById('rm-request-id').textContent = `#${req.id}`;
    document.getElementById('rm-status-badge').innerHTML = `<span class="status-badge status-badge--${statusInfo.color}"><i class="fas ${statusInfo.icon} mr-1"></i> ${statusInfo.text}</span>`;

    // Request info
    document.getElementById('rm-user-id').textContent = req.user_id;
    document.getElementById('rm-calculated-spend').textContent = `S/ ${parseFloat(req.calculated_spend || 0).toFixed(2)}`;
    document.getElementById('rm-snapshot-nick').textContent = req.game_username_snapshot || '-';

    // Snapshot WhatsApp with copy/open behavior
    const snapshotWa = String(req.whatsapp_snapshot ?? '').trim();
    const snapshotWaDigits = snapshotWa.replace(/\D/g, '');
    const snapshotWaDisplay = snapshotWa || 'No provisto';

    document.getElementById('rm-snapshot-whatsapp').textContent = snapshotWaDisplay;
    attachCopyBadge(document.getElementById('rm-snapshot-whatsapp-box'), snapshotWaDigits || snapshotWa);

    const waOpenBtn = document.getElementById('rm-snapshot-whatsapp-open');
    if (waOpenBtn) {
        if (snapshotWaDigits) {
            waOpenBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            waOpenBtn.onclick = (e) => { e.stopPropagation(); window.open(`https://wa.me/${snapshotWaDigits}`, '_blank'); };
        } else {
            waOpenBtn.classList.add('opacity-50', 'cursor-not-allowed');
            waOpenBtn.onclick = (e) => { e.stopPropagation(); };
        }
    }

    document.getElementById('rm-completed-orders').innerHTML = `<span class="status-badge status-badge--blue"><i class="fas fa-shopping-cart mr-1"></i> ${completedOrders}</span>`;
    document.getElementById('rm-request-date').textContent = formatModalDate(req.created_at);

    // Editable fields
    const editSection = document.getElementById('rm-editable-section');
    const actionButtons = document.getElementById('rm-action-buttons');

    if (isPending && user) {
        editSection.style.display = '';
        actionButtons.style.display = '';
        document.getElementById('rm-edit-game-username').value = user.game_username || '';
        document.getElementById('rm-edit-display-name').value = user.public_display_name || '';
        document.getElementById('rm-edit-whatsapp').value = user.whatsapp_phone_e164 || '';
    } else {
        editSection.style.display = isPending ? '' : 'none';
        actionButtons.style.display = isPending ? '' : 'none';

        if (!isPending) {
            // Show reviewed info for non-pending
            if (req.admin_notes) {
                document.getElementById('rm-admin-notes').value = req.admin_notes;
                document.getElementById('rm-admin-notes').disabled = true;
            }
        }
    }

    // Admin notes
    if (isPending) {
        document.getElementById('rm-admin-notes').value = '';
        document.getElementById('rm-admin-notes').disabled = false;
    } else {
        document.getElementById('rm-admin-notes').value = req.admin_notes || '';
        document.getElementById('rm-admin-notes').disabled = true;
    }

    // Wire up approve/reject buttons (SweetAlert2)
    const approveBtn = document.getElementById('rm-approve-btn');
    const rejectBtn = document.getElementById('rm-reject-btn');

    if (approveBtn) {
        approveBtn.onclick = () => {
            Swal.fire({
                title: 'Aprobar Verificación',
                html: `¿Estás seguro de que deseas <strong>APROBAR</strong> la solicitud <strong>#${req.id}</strong>? El usuario será marcado como verificado.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#059669',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="fas fa-check-circle"></i> Aprobar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    apiApproveRequest(req.id, {
                        adminNotes: document.getElementById('rm-admin-notes').value,
                        gameUsername: document.getElementById('rm-edit-game-username').value,
                        displayName: document.getElementById('rm-edit-display-name').value,
                        whatsapp: document.getElementById('rm-edit-whatsapp').value
                    });
                }
            });
        };
    }

    if (rejectBtn) {
        rejectBtn.onclick = () => {
            Swal.fire({
                title: 'Rechazar Verificación',
                html: `¿Estás seguro de que deseas <strong>RECHAZAR</strong> la solicitud <strong>#${req.id}</strong>?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="fas fa-times-circle"></i> Rechazar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    apiRejectRequest(req.id, document.getElementById('rm-admin-notes').value);
                }
            });
        };
    }

    // Render order history
    renderOrderHistory(data.order_history, data.total_spent);
}

// --- SWEETALERT2: Quick Actions from Table ---
function swalQuickApprove(requestId) {
    Swal.fire({
        title: 'Aprobar Verificación',
        html: `¿Estás seguro de que deseas <strong>APROBAR</strong> la solicitud <strong>#${requestId}</strong>?<br>El usuario será marcado como verificado.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<i class="fas fa-check-circle"></i> Aprobar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) apiApproveRequest(requestId);
    });
}
window.swalQuickApprove = swalQuickApprove;

function swalQuickReject(requestId) {
    Swal.fire({
        title: 'Rechazar Verificación',
        html: `¿Estás seguro de que deseas <strong>RECHAZAR</strong> la solicitud <strong>#${requestId}</strong>?`,
        input: 'textarea',
        inputPlaceholder: 'Notas de rechazo (opcional)...',
        inputAttributes: { style: 'font-size: 0.875rem;' },
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<i class="fas fa-times-circle"></i> Rechazar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) apiRejectRequest(requestId, result.value || '');
    });
}
window.swalQuickReject = swalQuickReject;

// --- RENDER ORDER HISTORY (Inside Modal) ---
function renderOrderHistory(orderHistory, totalSpent) {
    const container = document.getElementById('rm-order-history-container');
    const totalEl = document.getElementById('rm-total-spent');

    if (totalEl) {
        totalEl.textContent = `Total Gastado: S/ ${parseFloat(totalSpent || 0).toFixed(2)}`;
    }

    if (!container) return;

    if (!orderHistory || orderHistory.length === 0) {
        container.innerHTML = '<p class="text-center text-sm py-4" style="color: var(--vr-modal-text-secondary);">Sin pedidos completados.</p>';
        return;
    }

    let html = '';
    orderHistory.forEach(order => {
        const dateStr = order.fecha_completado ? formatModalDate(order.fecha_completado) : '-';
        const code = escapeHtml(order.codigo_pedido || '-');
        let itemsHtml = '';
        let orderTotal = 0;

        if (order.items && order.items.length > 0) {
            order.items.forEach(item => {
                const lineTotal = item.precio_final * item.cantidad;
                orderTotal += lineTotal;
                itemsHtml += `
                    <div class="order-item-row">
                        <span class="order-item-name">${escapeHtml(item.nombre_producto)} <span style="color: var(--vr-modal-text-secondary);">x${item.cantidad}</span></span>
                        <span class="order-item-price">S/ ${lineTotal.toFixed(2)}</span>
                    </div>`;
            });
        } else {
            itemsHtml = '<p class="text-xs py-1" style="color: var(--vr-modal-text-secondary);">Sin detalles de productos</p>';
        }

        html += `
            <div class="order-card" style="background-color: var(--vr-modal-surface);">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-bold font-mono" style="color: var(--vr-modal-text-primary);">${code}</span>
                    <span class="text-[11px]" style="color: var(--vr-modal-text-secondary);">${dateStr}</span>
                </div>
                <div class="border-t pt-1.5" style="border-color: var(--vr-modal-border);">
                    ${itemsHtml}
                </div>
                <div class="border-t mt-1.5 pt-1.5 flex justify-end" style="border-color: var(--vr-modal-border);">
                    <span class="text-xs font-bold" style="color: var(--vr-modal-text-primary);">Subtotal: S/ ${orderTotal.toFixed(2)}</span>
                </div>
            </div>`;
    });

    container.innerHTML = html;
}

// --- API CALLS ---
async function apiApproveRequest(requestId, { adminNotes = '', gameUsername = '', displayName = '', whatsapp = '' } = {}) {
    const formData = new FormData();
    formData.append('action', 'approve_request');
    formData.append('request_id', requestId);
    formData.append('admin_notes', adminNotes);
    formData.append('game_username', gameUsername);
    formData.append('public_display_name', displayName);
    formData.append('whatsapp_phone_e164', whatsapp);

    try {
        const response = await fetch('/wabo/verificacion_usuarios/api_verificacionusu.php', { method: 'POST', body: formData });
        const result = await response.json();

        if (!response.ok || result.status !== 'success') {
            throw new Error(result.message || 'Error desconocido');
        }

        // Update local data
        const idx = allRequests.findIndex(r => r.id == requestId);
        if (idx !== -1) {
            allRequests[idx].status = 'approved';
            allRequests[idx].reviewed_at = new Date().toISOString();
        }

        closeModal(reviewModal);
        showAdminToast('✓ Verificación aprobada');
        renderPage();
    } catch (error) {
        console.error('Error al aprobar:', error);
        showAdminToast('Error: ' + error.message);
    }
}

async function apiRejectRequest(requestId, adminNotes = '') {
    const formData = new FormData();
    formData.append('action', 'reject_request');
    formData.append('request_id', requestId);
    formData.append('admin_notes', adminNotes);

    try {
        const response = await fetch('/wabo/verificacion_usuarios/api_verificacionusu.php', { method: 'POST', body: formData });
        const result = await response.json();

        if (!response.ok || result.status !== 'success') {
            throw new Error(result.message || 'Error desconocido');
        }

        // Update local data
        const idx = allRequests.findIndex(r => r.id == requestId);
        if (idx !== -1) {
            allRequests[idx].status = 'rejected';
            allRequests[idx].reviewed_at = new Date().toISOString();
        }

        closeModal(reviewModal);
        showAdminToast('Verificación rechazada');
        renderPage();
    } catch (error) {
        console.error('Error al rechazar:', error);
        showAdminToast('Error: ' + error.message);
    }
}

// --- EVENT LISTENERS ---
document.addEventListener('DOMContentLoaded', () => {
    // Status tabs
    document.querySelectorAll('.tab-btn').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelector('.tab-btn.active-tab')?.classList.remove('active-tab');
            tab.classList.add('active-tab');
            currentFilters.status = tab.dataset.status;
            currentPage = 1;
            renderPage();
        });
    });

    // Initial render — default to "pending" tab
    const pendingTab = document.querySelector('.tab-btn[data-status="pending"]');
    if (pendingTab) pendingTab.click();

    // Dark mode sync
    const html = document.documentElement;
    const syncDarkClass = () => {
        const theme = html.getAttribute('data-theme') || 'dark';
        html.classList.toggle('dark', theme !== 'light');
    };
    syncDarkClass();
    const observer = new MutationObserver(() => syncDarkClass());
    observer.observe(html, { attributes: true, attributeFilter: ['data-theme'] });

    // Search
    document.getElementById('searchInput').addEventListener('input', (e) => {
        currentFilters.search = e.target.value;
        currentPage = 1;
        renderPage();
    });

    // Sort
    document.getElementById('sortOrder').addEventListener('change', (e) => {
        currentFilters.sort = e.target.value;
        currentPage = 1;
        renderPage();
    });

    // Close buttons
    document.querySelectorAll('[data-close-button]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.fixed.inset-0');
            if (modal) closeModal(modal);
        });
    });

    // Backdrop click
    window.addEventListener('click', (event) => {
        if (event.target.hasAttribute('data-modal-backdrop')) {
            closeModal(event.target);
        }
    });

});
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

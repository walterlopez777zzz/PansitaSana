<?php
// Archivo: dashboard.php (v6.0 - Command Center BI)
require_once __DIR__ . '/core/admin_header.php';
?>

<script>
    if (typeof window.tailwind === 'undefined' && !window.__tailwindFallbackLoading) {
        window.__tailwindFallbackLoading = true;
        const s = document.createElement('script');
        s.src = 'https://cdn.tailwindcss.com';
        document.head.appendChild(s);
    }
</script>

<!-- ApexCharts CDN -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<style>
    /* --- Command Center Custom Styles --- */
    .kpi-card {
        position: relative;
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }
    .kpi-card .kpi-icon-bg {
        position: absolute;
        right: -8px;
        bottom: -8px;
        font-size: 4.5rem;
        opacity: 0.06;
        pointer-events: none;
    }
    .kpi-change {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 2px 8px;
        border-radius: 9999px;
    }
    .kpi-change.up { background: rgba(16,185,129,0.12); color: #10b981; }
    .kpi-change.down { background: rgba(239,68,68,0.12); color: #ef4444; }
    .kpi-change.neutral { background: rgba(148,163,184,0.12); color: #94a3b8; }

    .period-btn.active {
        background-color: var(--accent);
        color: #fff;
        box-shadow: 0 2px 8px rgba(245,158,11,0.3);
    }
    .period-btn {
        transition: all 0.15s ease;
    }

    .activity-item {
        transition: background-color 0.15s ease;
        border-radius: 0.5rem;
    }
    .activity-item:hover {
        background-color: rgba(148,163,184,0.08);
    }

    .user-card {
        transition: transform 0.15s ease;
    }
    .user-card:hover {
        transform: scale(1.03);
    }

    .modal { transition: opacity 0.3s ease; }
    .modal-content { animation: slideUp 0.3s ease-out; }
    @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

    .detail-filter-btn.active {
        background-color: var(--accent);
        color: #fff;
    }

    .sparkline-container { height: 40px; width: 100px; }

    /* Scrollbar styling */
    .custom-scroll::-webkit-scrollbar { width: 4px; }
    .custom-scroll::-webkit-scrollbar-track { background: transparent; }
    .custom-scroll::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.3); border-radius: 4px; }

    .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }

    /* --- Centro operativo --- */
    .ops-tile { display: block; color: inherit; text-decoration: none; transition: transform 0.15s ease, border-color 0.15s ease; }
    .ops-tile:hover { transform: translateY(-2px); border-color: var(--accent); }
    .ops-tile-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-secondary); }
    .ops-tile-value { font-size: 1.6rem; font-weight: 800; line-height: 1.1; margin-top: 0.25rem; }
    .ops-tile-sub { font-size: 0.68rem; color: var(--text-secondary); margin-top: 0.15rem; }
    .ops-tile.is-zero .ops-tile-value { color: var(--text-secondary) !important; opacity: 0.6; }

    .ops-alert { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.9rem; border-radius: 0.75rem; font-size: 0.8rem; border: 1px solid transparent; color: inherit; text-decoration: none; }
    .ops-alert:hover { filter: brightness(1.08); }
    .ops-alert.danger { background: rgba(239,68,68,0.10); border-color: rgba(239,68,68,0.35); }
    .ops-alert.danger i.ops-alert-ico { color: #ef4444; }
    .ops-alert.warn { background: rgba(245,158,11,0.10); border-color: rgba(245,158,11,0.35); }
    .ops-alert.warn i.ops-alert-ico { color: #f59e0b; }
    .ops-alert.info { background: rgba(59,130,246,0.10); border-color: rgba(59,130,246,0.3); }
    .ops-alert.info i.ops-alert-ico { color: #3b82f6; }
    .ops-alert.ok { background: rgba(16,185,129,0.08); border-color: rgba(16,185,129,0.25); color: var(--text-secondary); }
    .ops-alert.ok i.ops-alert-ico { color: #10b981; }

    .ops-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem 0.75rem; }
    .ops-summary dt { font-size: 0.68rem; color: var(--text-secondary); }
    .ops-summary dd { font-size: 1rem; font-weight: 700; margin: 0; }

    .ops-status { font-size: 0.65rem; font-weight: 600; padding: 2px 7px; border-radius: 9999px; white-space: nowrap; }

    #perf-section.collapsed { display: none; }
</style>

<!-- ============================================= -->
<!-- CABECERA COMPACTA -->
<!-- ============================================= -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
    <div>
        <h2 class="text-xl font-bold flex items-center gap-2">
            Inicio
            <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full" style="background: rgba(245,158,11,0.15); color: var(--accent);">Centro operativo</span>
        </h2>
        <p class="text-xs mt-0.5" style="color: var(--text-secondary);">
            <?php echo htmlspecialchars($_SESSION['username']); ?> &middot; <span id="current-datetime"></span>
            &middot; <span id="ops-updated" title="Última actualización">—</span>
        </p>
    </div>
    <div class="flex items-center gap-2">
        <a href="pedidos/gestionar_pedidos.php" class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold text-white" style="background: var(--accent);">
            <i class="fas fa-shopping-cart text-xs"></i> Ir a Pedidos
            <span id="ops-pending-badge" class="hidden text-[11px] px-1.5 py-0.5 rounded-full bg-black/25">0</span>
        </a>
        <button id="refresh-dashboard-btn" class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" style="border: 1px solid var(--border-color);" title="Actualizar">
            <i class="fas fa-sync-alt text-xs" id="refresh-icon"></i>
        </button>
        <button id="open-exclusion-modal-btn" title="Gestionar IPs Excluidas (analítica)" class="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" style="border: 1px solid var(--border-color);">
            <i class="fas fa-user-shield"></i>
        </button>
    </div>
</div>

<!-- ============================================= -->
<!-- ZONA 1: HOY NECESITA TU ATENCIÓN -->
<!-- ============================================= -->
<div id="ops-alerts" class="space-y-2 mb-4"></div>

<div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3 mb-4" id="ops-tiles">
    <a href="pedidos/gestionar_pedidos.php" class="ops-tile card rounded-xl p-3" data-key="pendiente">
        <div class="flex items-center justify-between">
            <span class="ops-tile-label">Pedidos pendientes</span>
            <span class="w-2 h-2 rounded-full bg-yellow-400 animate-pulse"></span>
        </div>
        <p class="ops-tile-value text-yellow-400" id="ops-orders-pendiente">--</p>
        <p class="ops-tile-sub" id="ops-orders-pendiente-sub">por confirmar</p>
    </a>
    <a href="pedidos/gestionar_pedidos.php" class="ops-tile card rounded-xl p-3">
        <span class="ops-tile-label">Pagados por entregar</span>
        <p class="ops-tile-value" style="color: #10b981;" id="ops-orders-pagado">--</p>
        <p class="ops-tile-sub">listos para completar</p>
    </a>
    <a href="pedidos/gestionar_pedidos.php" class="ops-tile card rounded-xl p-3">
        <span class="ops-tile-label">Reservas</span>
        <p class="ops-tile-value" style="color: #14b8a6;" id="ops-orders-reserva">--</p>
        <p class="ops-tile-sub">en espera</p>
    </a>
    <a href="verificacion_usuarios/gestionar_verificacionesusu.php" class="ops-tile card rounded-xl p-3">
        <span class="ops-tile-label">Verificaciones</span>
        <p class="ops-tile-value" style="color: #3b82f6;" id="ops-verif">--</p>
        <p class="ops-tile-sub">solicitudes pendientes</p>
    </a>
    <a href="compras_manuales/gestionar_compras.php" class="ops-tile card rounded-xl p-3">
        <span class="ops-tile-label">Compras por revisar</span>
        <p class="ops-tile-value" style="color: #8b5cf6;" id="ops-deferred">--</p>
        <p class="ops-tile-sub">revisión diferida en sorteos</p>
    </a>
    <a href="sorteos_flash/sala_mando.php" class="ops-tile card rounded-xl p-3">
        <span class="ops-tile-label">Sorteos en curso</span>
        <p class="ops-tile-value" style="color: var(--accent);" id="ops-raffles">--</p>
        <p class="ops-tile-sub" id="ops-raffles-sub">abrir Sala de Mando</p>
    </a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-4 mb-6">
    <!-- Últimos pedidos -->
    <div class="lg:col-span-6 card rounded-xl p-4 flex flex-col">
        <div class="flex justify-between items-center mb-3">
            <div>
                <h3 class="font-bold text-sm">Últimos pedidos</h3>
                <p class="text-[11px]" style="color: var(--text-secondary);">Los 8 más recientes, de cualquier estado</p>
            </div>
            <a href="pedidos/gestionar_pedidos.php" class="text-xs font-medium hover:underline" style="color: var(--accent);">Ver todos <i class="fas fa-arrow-right text-[10px]"></i></a>
        </div>
        <div class="overflow-x-auto custom-scroll">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-[10px] uppercase tracking-wider text-left" style="color: var(--text-secondary);">
                        <th class="py-1.5 pr-2 font-semibold">Código</th>
                        <th class="py-1.5 pr-2 font-semibold">Usuario</th>
                        <th class="py-1.5 pr-2 font-semibold">Estado</th>
                        <th class="py-1.5 pr-2 font-semibold text-right">Total</th>
                        <th class="py-1.5 font-semibold text-right">Hace</th>
                    </tr>
                </thead>
                <tbody id="ops-recent-orders">
                    <tr><td colspan="5" class="py-6 text-center text-xs" style="color: var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Cargando…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Estado de la tienda + sorteos -->
    <div class="lg:col-span-3 flex flex-col gap-4">
        <div class="card rounded-xl p-4">
            <div class="flex justify-between items-center mb-3">
                <h3 class="font-bold text-sm">Estado de la tienda</h3>
                <a href="configuracion/gestionar_configuracion.php" class="text-[11px] hover:underline" style="color: var(--accent);" title="Ajustes de Tienda"><i class="fas fa-cogs"></i> Ajustes</a>
            </div>
            <div class="flex items-center gap-2 mb-3">
                <span id="ops-store-dot" class="status-dot" style="background:#64748b"></span>
                <span id="ops-store-mode" class="text-sm font-semibold">—</span>
            </div>
            <div class="mb-1 flex justify-between text-[11px]" style="color: var(--text-secondary);">
                <span>Regalos disponibles</span>
                <span><strong id="ops-gifts-avail" style="color: var(--text-primary);">--</strong> / <span id="ops-gifts-total">--</span></span>
            </div>
            <div class="h-2 rounded-full overflow-hidden" style="background: rgba(148,163,184,0.15);">
                <div id="ops-gifts-bar" class="h-full rounded-full transition-all" style="width:0%; background:#10b981;"></div>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-3 text-[11px]" style="color: var(--text-secondary);">
                <div>Reservados<br><strong id="ops-gifts-reserved" style="color: var(--text-primary);">--</strong></div>
                <div>Enviados 24h<br><strong id="ops-gifts-sent" style="color: var(--text-primary);">--</strong></div>
                <div class="col-span-2">Tiempo de reserva: <strong id="ops-reserve-min" style="color: var(--text-primary);">--</strong> min</div>
            </div>
        </div>
        <div class="card rounded-xl p-4 flex-grow">
            <div class="flex justify-between items-center mb-2">
                <h3 class="font-bold text-sm">Sorteos Flash</h3>
                <a href="sorteos_flash/gestionar_sorteos.php" class="text-[11px] hover:underline" style="color: var(--accent);">Gestionar</a>
            </div>
            <div id="ops-raffle-list" class="space-y-1.5 text-xs">
                <p style="color: var(--text-secondary);">Cargando…</p>
            </div>
        </div>
    </div>

    <!-- Resumen del día -->
    <div class="lg:col-span-3 card rounded-xl p-4">
        <div class="flex justify-between items-center mb-3">
            <h3 class="font-bold text-sm">Resumen de hoy</h3>
            <span class="text-[10px] px-2 py-0.5 rounded-full" style="background: rgba(59,130,246,0.1); color: #3b82f6;">Hora Lima</span>
        </div>
        <dl class="ops-summary">
            <div><dt>Ingresos</dt><dd id="ops-today-revenue">S/ --</dd></div>
            <div><dt>Pedidos creados</dt><dd id="ops-today-orders">--</dd></div>
            <div><dt>Completados</dt><dd id="ops-today-completed">--</dd></div>
            <div><dt>Cancelados</dt><dd id="ops-today-cancelled">--</dd></div>
            <div><dt>Nuevos usuarios</dt><dd id="ops-today-users">--</dd></div>
            <div><dt>Giros</dt><dd id="ops-today-spins">--</dd></div>
            <div><dt>Coins emitidos</dt><dd id="ops-today-coins-in" style="color:#10b981;">--</dd></div>
            <div><dt>Coins gastados</dt><dd id="ops-today-coins-out" style="color:#ef4444;">--</dd></div>
        </dl>
        <a href="economia/resumen.php" class="block mt-3 text-[11px] hover:underline" style="color: var(--accent);">Ver Economía <i class="fas fa-arrow-right text-[10px]"></i></a>
    </div>
</div>

<!-- ============================================= -->
<!-- ZONA 2: RENDIMIENTO (analítica, plegable) -->
<!-- ============================================= -->
<button id="perf-toggle" type="button" class="w-full flex items-center justify-between px-4 py-2.5 rounded-xl mb-4 card hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" aria-expanded="true" aria-controls="perf-section">
    <span class="flex items-center gap-2 text-sm font-bold"><i class="fas fa-chart-line" style="color: var(--accent);"></i> Rendimiento y métricas</span>
    <span class="text-xs flex items-center gap-2" style="color: var(--text-secondary);">
        <span id="perf-toggle-label">Ocultar</span>
        <i class="fas fa-chevron-up transition-transform" id="perf-toggle-icon"></i>
    </span>
</button>

<div id="perf-section">

<!-- KPI CARDS ROW -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <!-- Revenue Today -->
    <div class="kpi-card card rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-secondary);">Ingresos Hoy</span>
            <span class="kpi-change neutral" id="kpi-revenue-change">--</span>
        </div>
        <p class="text-2xl font-bold" id="kpi-revenue">S/ --</p>
        <p class="text-xs mt-1" style="color: var(--text-secondary);">Ayer: <span id="kpi-revenue-yesterday">--</span></p>
        <i class="fas fa-dollar-sign kpi-icon-bg"></i>
    </div>

    <!-- Orders Today -->
    <div class="kpi-card card rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-secondary);">Pedidos Hoy</span>
            <span class="kpi-change neutral" id="kpi-orders-change">--</span>
        </div>
        <p class="text-2xl font-bold" id="kpi-orders">--</p>
        <p class="text-xs mt-1" style="color: var(--text-secondary);">Ayer: <span id="kpi-orders-yesterday">--</span></p>
        <i class="fas fa-shopping-cart kpi-icon-bg"></i>
    </div>

    <!-- Unique Visitors -->
    <div class="kpi-card card rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-secondary);">Visitantes</span>
            <span class="kpi-change neutral" id="kpi-visitors-change">--</span>
        </div>
        <p class="text-2xl font-bold" id="kpi-visitors">--</p>
        <p class="text-xs mt-1" style="color: var(--text-secondary);">Ayer: <span id="kpi-visitors-yesterday">--</span></p>
        <i class="fas fa-eye kpi-icon-bg"></i>
    </div>

    <!-- Conversion Rate -->
    <div class="kpi-card card rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-secondary);">Conversi&oacute;n</span>
            <i class="fas fa-funnel-dollar text-xs" style="color: var(--accent);"></i>
        </div>
        <p class="text-2xl font-bold" id="kpi-conversion">--%</p>
        <p class="text-xs mt-1" style="color: var(--text-secondary);">Visitantes &rarr; Pedidos</p>
        <i class="fas fa-percentage kpi-icon-bg"></i>
    </div>

    <!-- Spins Today -->
    <div class="kpi-card card rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-secondary);">Giros Hoy</span>
            <span class="kpi-change neutral" id="kpi-spins-change">--</span>
        </div>
        <p class="text-2xl font-bold" id="kpi-spins">--</p>
        <p class="text-xs mt-1" style="color: var(--text-secondary);">Ayer: <span id="kpi-spins-yesterday">--</span></p>
        <i class="fas fa-dice kpi-icon-bg"></i>
    </div>
</div>

<!-- ============================================= -->
<!-- CHARTS ROW -->
<!-- ============================================= -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Traffic Overview (2/3 width) -->
    <div class="lg:col-span-2 card rounded-xl p-5 flex flex-col" style="min-height: 420px;">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-1">
            <div>
                <h3 class="font-bold text-base">Tr&aacute;fico Overview</h3>
                <p class="text-xs" style="color: var(--text-secondary);">Vistas totales vs visitantes &uacute;nicos</p>
            </div>
            <div class="flex items-center gap-3">
                <label class="flex items-center gap-1.5 cursor-pointer text-xs" style="color: var(--text-secondary);" title="Modo de conteo de visitas &uacute;nicas">
                    <input type="checkbox" id="unique-mode-toggle" class="hidden">
                    <span id="unique-mode-label" class="p-1.5 rounded-md hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                        <i class="fas fa-user-clock"></i> &Uacute;nico/Hora
                    </span>
                </label>
                <div id="visits-period-buttons" class="flex rounded-lg p-0.5" style="background: rgba(148,163,184,0.1);">
                    <button data-period="daily" class="period-btn active px-3 py-1 text-xs font-medium rounded-md">Hoy</button>
                    <button data-period="weekly" class="period-btn px-3 py-1 text-xs font-medium rounded-md">Semana</button>
                    <button data-period="monthly" class="period-btn px-3 py-1 text-xs font-medium rounded-md">Mes</button>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-6 mb-3">
            <div class="flex items-center gap-4">
                <div>
                    <span class="text-xs" style="color: var(--text-secondary);">Totales</span>
                    <p class="text-lg font-bold" id="visits-total-count">--</p>
                </div>
                <div>
                    <span class="text-xs" style="color: var(--text-secondary);">&Uacute;nicos</span>
                    <p class="text-lg font-bold" id="visits-unique-count">--</p>
                </div>
            </div>
        </div>
        <div class="flex-grow w-full" id="traffic-chart-container"></div>
    </div>

    <!-- Orders by Status (1/3 width) -->
    <div class="card rounded-xl p-5 flex flex-col" style="min-height: 420px;">
        <h3 class="font-bold text-base mb-1">Estado de Pedidos</h3>
        <p class="text-xs mb-4" style="color: var(--text-secondary);">&Uacute;ltimos 30 d&iacute;as</p>
        <div class="flex-grow flex items-center justify-center" id="orders-status-chart-container"></div>
        <div id="orders-status-legend" class="mt-3 space-y-1.5"></div>
    </div>
</div>

<!-- ============================================= -->
<!-- PRODUCT INTEREST + ACTIVITY FEED ROW -->
<!-- ============================================= -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Product Interest Chart -->
    <div class="card rounded-xl p-5 flex flex-col" style="min-height: 400px;">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
            <div>
                <h3 class="font-bold text-base">Inter&eacute;s de Productos</h3>
                <p class="text-xs" style="color: var(--text-secondary);">Top productos m&aacute;s clickeados</p>
            </div>
            <div id="clicks-period-buttons" class="flex rounded-lg p-0.5" style="background: rgba(148,163,184,0.1);">
                <button data-period="daily" class="period-btn active px-3 py-1 text-xs font-medium rounded-md">Hoy</button>
                <button data-period="weekly" class="period-btn px-3 py-1 text-xs font-medium rounded-md">Semana</button>
                <button data-period="monthly" class="period-btn px-3 py-1 text-xs font-medium rounded-md">Mes</button>
            </div>
        </div>
        <div class="flex items-center gap-4 mb-3">
            <div>
                <span class="text-xs" style="color: var(--text-secondary);">Clics Totales</span>
                <p class="text-lg font-bold" id="clicks-total-count">--</p>
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-secondary);">Clics &Uacute;nicos</span>
                <p class="text-lg font-bold" id="clicks-unique-count">--</p>
            </div>
        </div>
        <div class="flex-grow w-full" id="products-chart-container"></div>
    </div>

    <!-- Activity Feed -->
    <div class="card rounded-xl p-5 flex flex-col" style="min-height: 400px;">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="font-bold text-base">Actividad Reciente</h3>
                <p class="text-xs" style="color: var(--text-secondary);">Pedidos y giros m&aacute;s recientes</p>
            </div>
            <span class="text-xs font-medium px-2 py-1 rounded-full" style="background: rgba(59,130,246,0.1); color: #3b82f6;">
                <i class="fas fa-stream"></i> En vivo
            </span>
        </div>
        <div class="flex-grow overflow-y-auto custom-scroll space-y-1" id="activity-feed-container">
            <div class="text-center py-8" style="color: var(--text-secondary);">
                <i class="fas fa-spinner fa-spin text-xl mb-2"></i>
                <p class="text-sm">Cargando actividad...</p>
            </div>
        </div>
    </div>
</div>

<!-- ============================================= -->
<!-- TOP USERS ROW -->
<!-- ============================================= -->
<div class="card rounded-xl p-5 mb-6">
    <div class="flex justify-between items-center mb-4">
        <div>
            <h3 class="font-bold text-base">Top Usuarios Activos</h3>
            <p class="text-xs" style="color: var(--text-secondary);">&Uacute;ltimos 30 d&iacute;as &mdash; por pedidos + giros</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs px-2 py-1 rounded-full" style="background: rgba(245,158,11,0.1); color: var(--accent);">
                <i class="fas fa-users"></i> <span id="kpi-total-users">--</span> registrados
            </span>
            <span class="text-xs px-2 py-1 rounded-full" style="background: rgba(16,185,129,0.1); color: #10b981;" id="kpi-new-users-badge">
                +<span id="kpi-new-users">0</span> hoy
            </span>
        </div>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3" id="top-users-container">
        <div class="col-span-full text-center py-4" style="color: var(--text-secondary);">
            <i class="fas fa-spinner fa-spin"></i> Cargando...
        </div>
    </div>
</div>

</div><!-- /perf-section -->

<!-- ============================================= -->
<!-- MODALS -->
<!-- ============================================= -->
<!-- Details Modal -->
<div id="details-modal" class="modal fixed inset-0 bg-black bg-opacity-70 hidden items-center justify-center z-50 p-4">
    <div class="modal-content card p-6 rounded-xl w-full max-w-3xl flex flex-col" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <h2 id="modal-title" class="text-lg font-bold">Detalles</h2>
            <button id="modal-close-btn" class="p-2 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="modal-content" class="max-h-[60vh] overflow-y-auto custom-scroll">Cargando...</div>
    </div>
</div>

<!-- Exclusion Modal -->
<div id="exclusion-modal" class="modal fixed inset-0 bg-black bg-opacity-70 hidden items-center justify-center z-50 p-4">
    <div class="modal-content card p-6 rounded-xl w-full max-w-2xl flex flex-col" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-bold">Gestionar IPs Excluidas</h2>
            <button id="exclusion-modal-close-btn" class="p-2 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="add-ip-form" class="flex gap-2 mb-4">
            <input type="text" id="ip-hash-input" placeholder="A&ntilde;adir nuevo hash de IP" class="flex-grow rounded-lg p-2.5 text-sm" style="background: rgba(148,163,184,0.1); border: 1px solid var(--border-color);" required>
            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background: var(--accent);">A&ntilde;adir</button>
        </form>
        <p class="text-xs mb-3" style="color: var(--text-secondary);">Tu hash de IP actual: <code id="current-ip-hash" class="px-1.5 py-0.5 rounded text-xs" style="background: rgba(148,163,184,0.15);">calculando...</code></p>
        <div class="max-h-64 overflow-y-auto custom-scroll">
            <table class="w-full text-sm text-left">
                <thead class="border-b" style="border-color: var(--border-color);">
                    <tr>
                        <th class="p-2 text-xs uppercase" style="color: var(--text-secondary);">Hash de IP</th>
                        <th class="p-2 text-xs uppercase" style="color: var(--text-secondary);">Descripci&oacute;n</th>
                        <th class="p-2 text-xs uppercase" style="color: var(--text-secondary);">Acci&oacute;n</th>
                    </tr>
                </thead>
                <tbody id="excluded-ips-table-body"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================= -->
<!-- JAVASCRIPT -->
<!-- ============================================= -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const API_URL = '/wabo/api_dashboard.php';
    let trafficChart = null, productsChart = null, ordersStatusChart = null;
    let currentPeriod = { visits: 'daily', clicks: 'daily' };

    // --- Helpers ---
    const getThemeColors = () => {
        const theme = document.documentElement.getAttribute('data-theme') || 'dark';
        const textColor = getComputedStyle(document.body).getPropertyValue('--text-secondary').trim() || '#94a3b8';
        const borderColor = getComputedStyle(document.body).getPropertyValue('--border-color').trim() || '#1a1a1a';
        return { theme, textColor, borderColor };
    };

    const formatChange = (value) => {
        if (value > 0) return { text: `+${value}%`, cls: 'up', icon: 'fa-arrow-up' };
        if (value < 0) return { text: `${value}%`, cls: 'down', icon: 'fa-arrow-down' };
        return { text: '0%', cls: 'neutral', icon: 'fa-minus' };
    };

    const setKpiChange = (id, value) => {
        const el = document.getElementById(id);
        const ch = formatChange(value);
        el.className = `kpi-change ${ch.cls}`;
        el.innerHTML = `<i class="fas ${ch.icon}" style="font-size:0.6rem"></i> ${ch.text}`;
    };

    const timeAgo = (timestamp) => {
        const diff = Math.floor((Date.now() - new Date(timestamp).getTime()) / 1000);
        if (diff < 60) return 'hace un momento';
        if (diff < 3600) return `hace ${Math.floor(diff/60)}m`;
        if (diff < 86400) return `hace ${Math.floor(diff/3600)}h`;
        return `hace ${Math.floor(diff/86400)}d`;
    };

    const escapeHtml = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmtMoney = (v) => `S/ ${(parseFloat(v) || 0).toFixed(2)}`;
    const fmtInt = (v) => (parseInt(v) || 0).toLocaleString('es-PE');
    const setText = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };

    // --- Update Clock ---
    const updateClock = () => {
        const now = new Date();
        document.getElementById('current-datetime').textContent = now.toLocaleDateString('es-PE', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) + ' ' + now.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' });
    };
    updateClock();
    setInterval(updateClock, 30000);

    // ======================
    // CENTRO OPERATIVO (zona 1)
    // ======================
    const STORE_MODES = {
        'normal':        { label: 'Normal · abierta', color: '#10b981' },
        'alta_demanda':  { label: 'Alta demanda', color: '#f59e0b' },
        'ausente':       { label: 'Ausente', color: '#94a3b8' },
        'mantenimiento': { label: 'Mantenimiento', color: '#ef4444' }
    };
    const RAFFLE_STATUS = {
        'scheduled': 'Programado', 'open': 'Abierto', 'waiting_for_winner': 'Esperando ganador',
        'locking': 'Cerrando', 'animating': 'Sorteando', 'completed': 'Completado', 'cancelled': 'Cancelado'
    };

    const renderOpsAlerts = (alerts) => {
        const box = document.getElementById('ops-alerts');
        if (!alerts.length) {
            box.innerHTML = '<div class="ops-alert ok"><i class="fas fa-check-circle ops-alert-ico"></i><span>Todo en orden: sin alertas de tienda ni pedidos vencidos.</span></div>';
            return;
        }
        box.innerHTML = alerts.map(a => `
            <a class="ops-alert ${escapeHtml(a.level)}" href="${escapeHtml(a.href || '#')}">
                <i class="fas ${escapeHtml(a.icon || 'fa-exclamation-circle')} ops-alert-ico"></i>
                <span class="flex-grow">${escapeHtml(a.text)}</span>
                <i class="fas fa-chevron-right text-[10px]" style="color: var(--text-secondary);"></i>
            </a>`).join('');
    };

    const setTile = (id, value, subId, subText) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = fmtInt(value);
        el.closest('.ops-tile')?.classList.toggle('is-zero', !(parseInt(value) > 0));
        if (subId && subText !== undefined) setText(subId, subText);
    };

    const renderOps = (d) => {
        renderOpsAlerts(d.alerts || []);

        const o = d.orders || {}, q = d.queues || {}, st = d.store || {}, t = d.today || {};
        const oldest = o.pendiente_mas_antiguo_min || 0;
        const oldestTxt = o.pendiente > 0
            ? (oldest >= 60 ? `el más antiguo lleva ${Math.floor(oldest / 60)}h ${oldest % 60}m` : `el más antiguo lleva ${oldest}m`)
            : 'sin pendientes';
        setTile('ops-orders-pendiente', o.pendiente, 'ops-orders-pendiente-sub', oldestTxt);
        setTile('ops-orders-pagado', o.pagado);
        setTile('ops-orders-reserva', o.reserva);
        setTile('ops-verif', q.verifications_pending);
        setTile('ops-deferred', q.deferred_review);
        setTile('ops-raffles', q.raffles_active, 'ops-raffles-sub', q.raffles_active > 0 ? 'abrir Sala de Mando' : 'ninguno activo');

        const badge = document.getElementById('ops-pending-badge');
        badge.textContent = fmtInt(o.pendiente);
        badge.classList.toggle('hidden', !(o.pendiente > 0));

        const mode = STORE_MODES[st.modo] || { label: st.modo || '—', color: '#64748b' };
        document.getElementById('ops-store-dot').style.background = mode.color;
        setText('ops-store-mode', mode.label + (st.forzar_compras_abiertas ? ' · compras forzadas' : ''));
        setText('ops-gifts-avail', st.forzar_compras_abiertas ? '∞' : fmtInt(st.regalos_disponibles));
        setText('ops-gifts-total', fmtInt(st.regalos_totales));
        setText('ops-gifts-reserved', fmtInt(st.regalos_reservados));
        setText('ops-gifts-sent', fmtInt(t.gifts_sent_24h));
        setText('ops-reserve-min', fmtInt(st.tiempo_reserva_min));
        const pct = st.regalos_totales > 0 ? Math.min(100, Math.round((st.regalos_disponibles / st.regalos_totales) * 100)) : 0;
        const bar = document.getElementById('ops-gifts-bar');
        bar.style.width = (st.forzar_compras_abiertas ? 100 : pct) + '%';
        bar.style.background = st.forzar_compras_abiertas ? '#3b82f6' : (pct <= 10 ? '#ef4444' : (pct <= 30 ? '#f59e0b' : '#10b981'));

        setText('ops-today-revenue', fmtMoney(t.revenue));
        setText('ops-today-orders', fmtInt(t.orders));
        setText('ops-today-completed', fmtInt(o.completado_hoy));
        setText('ops-today-cancelled', fmtInt(t.cancelled));
        setText('ops-today-users', fmtInt(t.new_users));
        setText('ops-today-spins', fmtInt(t.spins));
        setText('ops-today-coins-in', '+' + fmtInt(t.coins_emitted));
        setText('ops-today-coins-out', '-' + fmtInt(t.coins_spent));

        const rl = document.getElementById('ops-raffle-list');
        const raffles = d.raffles || [];
        rl.innerHTML = raffles.length ? raffles.map(r => `
            <a href="sorteos_flash/sala_mando.php?id=${r.raffle_id}" class="activity-item flex items-center gap-2 px-2 py-1.5">
                <span class="status-dot flex-shrink-0" style="background:${r.status === 'open' ? '#10b981' : (r.status === 'scheduled' ? '#3b82f6' : '#f59e0b')}"></span>
                <span class="flex-grow min-w-0 truncate font-medium">#${r.raffle_id} ${escapeHtml(r.title)}</span>
                <span class="ops-status" style="background: rgba(148,163,184,0.12); color: var(--text-secondary);">${escapeHtml(RAFFLE_STATUS[r.status] || r.status)}</span>
                <span class="flex-shrink-0" style="color: var(--text-secondary);" title="Participantes"><i class="fas fa-users"></i> ${fmtInt(r.participants)}</span>
            </a>`).join('')
            : '<p style="color: var(--text-secondary);">No hay sorteos activos ni programados.</p>';

        const tb = document.getElementById('ops-recent-orders');
        const recent = d.recent_orders || [];
        tb.innerHTML = recent.length ? recent.map(p => `
            <tr class="border-t" style="border-color: var(--border-color);">
                <td class="py-2 pr-2 font-mono text-xs">${escapeHtml(p.codigo_pedido || ('#' + p.id))}</td>
                <td class="py-2 pr-2 truncate max-w-[9rem]">${escapeHtml(p.game_username || 'Anónimo')}</td>
                <td class="py-2 pr-2"><span class="ops-status" style="background:${(STATUS_COLORS[p.estado] || '#64748b')}22; color:${STATUS_COLORS[p.estado] || '#64748b'}">${escapeHtml(STATUS_LABELS[p.estado] || p.estado)}</span></td>
                <td class="py-2 pr-2 text-right font-semibold">${fmtMoney(p.total_pagar)}</td>
                <td class="py-2 text-right text-xs whitespace-nowrap" style="color: var(--text-secondary);">${timeAgo(p.fecha_creacion)}</td>
            </tr>`).join('')
            : '<tr><td colspan="5" class="py-6 text-center text-xs" style="color: var(--text-secondary);">Sin pedidos todavía.</td></tr>';

        setText('ops-updated', 'actualizado ' + new Date().toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' }));
    };

    const fetchOps = async () => {
        try {
            const res = await fetch(`${API_URL}?action=get_ops`);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const d = await res.json();
            if (d.success) renderOps(d);
        } catch (err) {
            console.error('Ops fetch error:', err);
            document.getElementById('ops-alerts').innerHTML = '<div class="ops-alert danger"><i class="fas fa-plug ops-alert-ico"></i><span>No se pudo cargar el centro operativo. Reintenta con el botón de actualizar.</span></div>';
        }
    };

    // ======================
    // RENDIMIENTO (zona 2, plegable)
    // ======================
    const perfSection = document.getElementById('perf-section');
    const perfToggle = document.getElementById('perf-toggle');
    const isPerfCollapsed = () => perfSection.classList.contains('collapsed');
    const applyPerfState = (collapsed) => {
        perfSection.classList.toggle('collapsed', collapsed);
        perfToggle.setAttribute('aria-expanded', String(!collapsed));
        document.getElementById('perf-toggle-label').textContent = collapsed ? 'Mostrar' : 'Ocultar';
        document.getElementById('perf-toggle-icon').style.transform = collapsed ? 'rotate(180deg)' : '';
    };
    applyPerfState(localStorage.getItem('dashPerfCollapsed') === '1');
    perfToggle.addEventListener('click', () => {
        const collapsed = !isPerfCollapsed();
        applyPerfState(collapsed);
        localStorage.setItem('dashPerfCollapsed', collapsed ? '1' : '0');
        if (!collapsed) {
            fetchDashboard();
            setTimeout(() => window.dispatchEvent(new Event('resize')), 50);
        }
    });

    // ======================
    // FETCH & UPDATE ALL DATA
    // ======================
    const fetchDashboard = async () => {
        const refreshIcon = document.getElementById('refresh-icon');
        refreshIcon.classList.add('fa-spin');

        try {
            if (isPerfCollapsed()) return;
            const uniqueMode = document.getElementById('unique-mode-toggle').checked ? 'per_hour' : 'per_day';
            const url = `${API_URL}?period=${currentPeriod.visits}&unique_mode=${uniqueMode}`;
            const response = await fetch(url);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();

            // --- KPIs ---
            if (data.kpis) {
                const k = data.kpis;
                document.getElementById('kpi-revenue').textContent = `S/ ${k.revenue_today.toFixed(2)}`;
                document.getElementById('kpi-revenue-yesterday').textContent = `S/ ${k.revenue_yesterday.toFixed(2)}`;
                setKpiChange('kpi-revenue-change', k.revenue_change);

                document.getElementById('kpi-orders').textContent = k.orders_today;
                document.getElementById('kpi-orders-yesterday').textContent = k.orders_yesterday;
                setKpiChange('kpi-orders-change', k.orders_change);

                document.getElementById('kpi-visitors').textContent = k.visitors_today;
                document.getElementById('kpi-visitors-yesterday').textContent = k.visitors_yesterday;
                setKpiChange('kpi-visitors-change', k.visitors_change);

                document.getElementById('kpi-conversion').textContent = `${k.conversion_rate}%`;

                document.getElementById('kpi-spins').textContent = k.spins_today;
                document.getElementById('kpi-spins-yesterday').textContent = k.spins_yesterday;
                setKpiChange('kpi-spins-change', k.spins_change);

                document.getElementById('kpi-total-users').textContent = k.total_users;
                document.getElementById('kpi-new-users').textContent = k.new_users_today;
            }

            // --- Traffic Chart ---
            if (data.page_visits_stats) {
                document.getElementById('visits-total-count').textContent = data.page_visits_stats.total || 0;
                document.getElementById('visits-unique-count').textContent = data.page_visits_stats.unique_count || 0;
            }
            if (data.page_visits_chart) renderTrafficChart(data.page_visits_chart);

            // --- Product Clicks ---
            if (data.product_clicks_stats) {
                document.getElementById('clicks-total-count').textContent = data.product_clicks_stats.total || 0;
                document.getElementById('clicks-unique-count').textContent = data.product_clicks_stats.unique_count || 0;
            }
            if (data.product_clicks_chart) renderProductsChart(data.product_clicks_chart);

            // --- Orders by Status ---
            if (data.orders_by_status) renderOrdersStatusChart(data.orders_by_status);

            // --- Activity Feed ---
            if (data.activity_feed) renderActivityFeed(data.activity_feed);

            // --- Top Users ---
            if (data.top_users) renderTopUsers(data.top_users);

        } catch (err) {
            console.error('Dashboard fetch error:', err);
        } finally {
            refreshIcon.classList.remove('fa-spin');
        }
    };

    // Fetch only product clicks (separate period)
    const fetchProductClicks = async () => {
        try {
            const url = `${API_URL}?period=${currentPeriod.clicks}`;
            const response = await fetch(url);
            const data = await response.json();
            if (data.product_clicks_stats) {
                document.getElementById('clicks-total-count').textContent = data.product_clicks_stats.total || 0;
                document.getElementById('clicks-unique-count').textContent = data.product_clicks_stats.unique_count || 0;
            }
            if (data.product_clicks_chart) renderProductsChart(data.product_clicks_chart);
        } catch (err) { console.error('Product clicks fetch error:', err); }
    };

    // ======================
    // CHART RENDERERS
    // ======================
    const renderTrafficChart = (chartData) => {
        const { textColor, borderColor } = getThemeColors();
        const labels = chartData.map(d => d.label);
        const totals = chartData.map(d => parseInt(d.total_data) || 0);
        const uniques = chartData.map(d => parseInt(d.unique_data) || 0);

        const options = {
            series: [
                { name: 'Vistas Totales', data: totals },
                { name: 'Visitantes Únicos', data: uniques }
            ],
            chart: {
                type: 'area',
                height: '100%',
                fontFamily: 'Inter, sans-serif',
                toolbar: { show: false },
                zoom: { enabled: false },
                events: {
                    dataPointSelection: (e, ctx, config) => {
                        const label = labels[config.dataPointIndex];
                        if (label) openDetailsModal('visits', label);
                    }
                }
            },
            colors: ['#f59e0b', '#3b82f6'],
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 90, 100] }
            },
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2.5 },
            xaxis: { categories: labels, labels: { style: { colors: textColor, fontSize: '10px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
            yaxis: { labels: { style: { colors: textColor, fontSize: '10px' } } },
            grid: { borderColor: borderColor, strokeDashArray: 3, xaxis: { lines: { show: false } } },
            legend: { position: 'top', horizontalAlign: 'left', labels: { colors: textColor }, fontSize: '11px', markers: { width: 8, height: 8, radius: 2 } },
            tooltip: { theme: 'dark', x: { show: true }, y: { formatter: (val) => val + ' hits' } },
            markers: { size: 0, hover: { size: 5 } }
        };

        if (trafficChart) { trafficChart.updateOptions(options); }
        else {
            trafficChart = new ApexCharts(document.getElementById('traffic-chart-container'), options);
            trafficChart.render();
        }
    };

    const renderProductsChart = (chartData) => {
        const { textColor } = getThemeColors();
        const labels = chartData.map(d => d.product_name);
        const totals = chartData.map(d => parseInt(d.total_clicks) || 0);
        const uniques = chartData.map(d => parseInt(d.unique_clicks) || 0);
        const palette = ['#f59e0b', '#3b82f6', '#10b981', '#8b5cf6', '#ef4444', '#ec4899', '#06b6d4', '#64748b'];

        const options = {
            series: [{ name: 'Clics Totales', data: totals }, { name: 'Clics Únicos', data: uniques }],
            chart: {
                type: 'bar',
                height: '100%',
                fontFamily: 'Inter, sans-serif',
                toolbar: { show: false },
                events: {
                    dataPointSelection: (e, ctx, config) => {
                        const label = labels[config.dataPointIndex];
                        if (label) openDetailsModal('clicks', label);
                    }
                }
            },
            plotOptions: { bar: { horizontal: true, barHeight: '65%', borderRadius: 4, dataLabels: { position: 'top' } } },
            colors: ['#f59e0b', '#3b82f6'],
            dataLabels: { enabled: false },
            xaxis: { categories: labels, labels: { style: { colors: textColor, fontSize: '10px' } } },
            yaxis: { labels: { style: { colors: textColor, fontSize: '10px' }, maxWidth: 120 } },
            grid: { borderColor: 'rgba(148,163,184,0.1)', strokeDashArray: 3 },
            legend: { position: 'top', horizontalAlign: 'left', labels: { colors: textColor }, fontSize: '11px', markers: { width: 8, height: 8, radius: 2 } },
            tooltip: { theme: 'dark', x: { formatter: (val, opts) => labels[opts.dataPointIndex] || val } }
        };

        if (productsChart) { productsChart.updateOptions(options); }
        else {
            productsChart = new ApexCharts(document.getElementById('products-chart-container'), options);
            productsChart.render();
        }
    };

    const STATUS_COLORS = {
        'pendiente': '#facc15',
        'pagado': '#22c55e',
        'reserva': '#14b8a6',
        'en_proceso': '#3b82f6',
        'completado': '#10b981',
        'cancelado': '#ef4444',
        'entregado': '#8b5cf6',
        'reembolsado': '#f97316'
    };
    const STATUS_LABELS = {
        'pendiente': 'Pendiente',
        'pagado': 'Pagado',
        'reserva': 'Reserva',
        'en_proceso': 'En Proceso',
        'completado': 'Completado',
        'cancelado': 'Cancelado',
        'entregado': 'Entregado',
        'reembolsado': 'Reembolsado'
    };

    const renderOrdersStatusChart = (statusData) => {
        const { textColor } = getThemeColors();
        const labels = statusData.map(d => STATUS_LABELS[d.estado] || d.estado);
        const values = statusData.map(d => parseInt(d.count));
        const colors = statusData.map(d => STATUS_COLORS[d.estado] || '#64748b');

        const options = {
            series: values,
            labels: labels,
            chart: { type: 'donut', height: 220, fontFamily: 'Inter, sans-serif' },
            colors: colors,
            plotOptions: { pie: { donut: { size: '72%', labels: { show: true, name: { fontSize: '12px', color: textColor }, value: { fontSize: '18px', fontWeight: 700, color: textColor }, total: { show: true, label: 'Total', fontSize: '11px', color: textColor, fontWeight: 400 } } } } },
            dataLabels: { enabled: false },
            legend: { show: false },
            stroke: { width: 2, colors: ['var(--secondary)'] },
            tooltip: { theme: 'dark' }
        };

        if (ordersStatusChart) { ordersStatusChart.updateOptions(options); }
        else {
            ordersStatusChart = new ApexCharts(document.getElementById('orders-status-chart-container'), options);
            ordersStatusChart.render();
        }

        // Custom legend
        const legendContainer = document.getElementById('orders-status-legend');
        legendContainer.innerHTML = statusData.map(d => `
            <div class="flex items-center justify-between text-xs">
                <div class="flex items-center gap-2">
                    <span class="status-dot" style="background:${STATUS_COLORS[d.estado] || '#64748b'}"></span>
                    <span style="color: var(--text-secondary);">${STATUS_LABELS[d.estado] || d.estado}</span>
                </div>
                <span class="font-semibold">${d.count}</span>
            </div>
        `).join('');
    };

    // ======================
    // ACTIVITY FEED RENDERER
    // ======================
    const renderActivityFeed = (feed) => {
        const container = document.getElementById('activity-feed-container');
        if (!feed.length) {
            container.innerHTML = '<div class="text-center py-8" style="color: var(--text-secondary);"><i class="fas fa-inbox text-2xl mb-2"></i><p class="text-sm">Sin actividad reciente</p></div>';
            return;
        }
        container.innerHTML = feed.map(item => {
            const isOrder = item.type === 'order';
            const icon = isOrder ? 'fa-shopping-bag' : 'fa-dice';
            const iconColor = isOrder ? '#f59e0b' : '#8b5cf6';
            const iconBg = isOrder ? 'rgba(245,158,11,0.1)' : 'rgba(139,92,246,0.1)';
            const label = isOrder ? `Pedido #${item.code || item.id}` : `Giro: ${item.code || 'Descuento'}`;
            const detail = isOrder ? `S/ ${parseFloat(item.amount || 0).toFixed(2)}` : `${item.amount || 0}% OFF`;
            const statusColor = isOrder ? (STATUS_COLORS[item.status] || '#64748b') : (item.status === 'active' ? '#10b981' : '#64748b');

            return `
                <div class="activity-item flex items-center gap-3 px-3 py-2.5">
                    <div class="w-9 h-9 rounded-lg flex-shrink-0 flex items-center justify-center" style="background:${iconBg}">
                        <i class="fas ${icon}" style="color:${iconColor}; font-size: 0.8rem;"></i>
                    </div>
                    <div class="flex-grow min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-medium truncate">${label}</p>
                            <span class="status-dot flex-shrink-0" style="background:${statusColor}"></span>
                        </div>
                        <p class="text-xs truncate" style="color: var(--text-secondary);">${item.username || 'Anónimo'} &middot; ${detail}</p>
                    </div>
                    <span class="text-[10px] flex-shrink-0 whitespace-nowrap" style="color: var(--text-secondary);">${timeAgo(item.timestamp)}</span>
                </div>
            `;
        }).join('');
    };

    // ======================
    // TOP USERS RENDERER
    // ======================
    const renderTopUsers = (users) => {
        const container = document.getElementById('top-users-container');
        if (!users.length) {
            container.innerHTML = '<div class="col-span-full text-center py-4" style="color: var(--text-secondary);"><p class="text-sm">Sin datos de usuarios</p></div>';
            return;
        }
        container.innerHTML = users.map((u, i) => {
            const avatarUrl = u.avatar_filename ? `/assets/avatars/${u.avatar_filename}` : `https://ui-avatars.com/api/?name=${encodeURIComponent(u.username)}&background=f59e0b&color=fff&size=64&bold=true`;
            const medal = i === 0 ? '<i class="fas fa-crown text-yellow-400 text-[10px]"></i>' : (i < 3 ? '<i class="fas fa-medal text-orange-300 text-[10px]"></i>' : '');
            return `
                <div class="user-card card rounded-xl p-3 text-center flex flex-col items-center gap-1.5" style="border: 1px solid var(--border-color);">
                    <div class="relative">
                        <img src="${avatarUrl}" alt="${u.username}" class="w-10 h-10 rounded-full object-cover" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(u.username)}&background=f59e0b&color=fff&size=64'">
                        ${medal ? `<span class="absolute -top-1 -right-1">${medal}</span>` : ''}
                    </div>
                    <p class="text-xs font-semibold truncate w-full">${u.username}</p>
                    <div class="flex items-center gap-2 text-[10px]" style="color: var(--text-secondary);">
                        <span title="Pedidos"><i class="fas fa-shopping-cart"></i> ${u.order_count}</span>
                        <span title="Giros"><i class="fas fa-dice"></i> ${u.spin_count}</span>
                    </div>
                    ${parseInt(u.total_spent) > 0 ? `<p class="text-[10px] font-medium" style="color: var(--accent);">S/ ${parseFloat(u.total_spent).toFixed(0)}</p>` : ''}
                </div>
            `;
        }).join('');
    };

    // ======================
    // DETAILS MODAL
    // ======================
    const openDetailsModal = async (type, identifier) => {
        const modal = document.getElementById('details-modal');
        const modalTitle = document.getElementById('modal-title');
        const modalContent = document.getElementById('modal-content');

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modalTitle.textContent = type === 'visits' ? `Visitas - ${identifier}` : `Clics - ${identifier}`;
        modalContent.innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-xl"></i><p class="text-sm mt-2" style="color:var(--text-secondary)">Cargando detalles...</p></div>';

        try {
            const res = await fetch(`${API_URL}?action=get_details&type=${type}&identifier=${encodeURIComponent(identifier)}`);
            const result = await res.json();

            if (result.success && result.data.length > 0) {
                if (result.is_other_details) {
                    let tableHTML = '<table class="w-full text-sm"><thead class="border-b" style="border-color:var(--border-color)"><tr><th class="p-2 text-left text-xs uppercase" style="color:var(--text-secondary)">Producto</th><th class="p-2 text-right text-xs uppercase" style="color:var(--text-secondary)">Clics</th></tr></thead><tbody>';
                    result.data.forEach(item => {
                        tableHTML += `<tr class="border-b" style="border-color:var(--border-color)"><td class="p-2">${item.product_name}</td><td class="p-2 text-right font-semibold">${item.total_clicks}</td></tr>`;
                    });
                    tableHTML += '</tbody></table>';
                    modalContent.innerHTML = tableHTML;
                } else {
                    const uniqueIps = new Set();
                    result.data.forEach(item => uniqueIps.add(item.ip_address_hash));

                    let filterHTML = '<div class="flex gap-2 mb-3"><button class="detail-filter-btn active px-3 py-1 text-xs rounded-md" data-filter="all" style="border:1px solid var(--border-color)">Todos (' + result.data.length + ')</button><button class="detail-filter-btn px-3 py-1 text-xs rounded-md" data-filter="unique" style="border:1px solid var(--border-color)">Únicos (' + uniqueIps.size + ')</button></div>';

                    const seenIps = new Set();
                    let tableHTML = '<div class="overflow-x-auto"><table class="w-full text-sm"><thead class="border-b" style="border-color:var(--border-color)"><tr><th class="p-2 text-left text-xs uppercase" style="color:var(--text-secondary)">IP Hash</th><th class="p-2 text-left text-xs uppercase" style="color:var(--text-secondary)">Hora</th></tr></thead><tbody>';
                    result.data.forEach(item => {
                        const isUnique = !seenIps.has(item.ip_address_hash);
                        if (isUnique) seenIps.add(item.ip_address_hash);
                        const date = new Date(item.timestamp);
                        const formattedTime = date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                        tableHTML += `<tr class="detail-row ${isUnique ? 'unique-row' : ''} border-b" style="border-color:var(--border-color)"><td class="p-2"><code class="text-xs" style="color:var(--text-secondary)">${item.ip_address_hash}</code></td><td class="p-2 text-xs">${formattedTime}</td></tr>`;
                    });
                    tableHTML += '</tbody></table></div>';

                    modalContent.innerHTML = filterHTML + tableHTML;

                    modalContent.querySelectorAll('.detail-filter-btn').forEach(btn => {
                        btn.addEventListener('click', (e) => {
                            modalContent.querySelector('.detail-filter-btn.active').classList.remove('active');
                            e.target.classList.add('active');
                            const filterType = e.target.dataset.filter;
                            modalContent.querySelectorAll('.detail-row').forEach(row => {
                                row.style.display = '';
                                if (filterType === 'unique' && !row.classList.contains('unique-row')) row.style.display = 'none';
                            });
                        });
                    });
                }
            } else {
                modalContent.innerHTML = '<div class="text-center py-8" style="color:var(--text-secondary)"><i class="fas fa-inbox text-2xl mb-2"></i><p class="text-sm">No hay datos de detalle.</p></div>';
            }
        } catch (err) {
            console.error('Detail modal error:', err);
            modalContent.innerHTML = '<p class="text-center py-8 text-red-400">Error al cargar detalles.</p>';
        }
    };

    // ======================
    // IP EXCLUSION MODAL
    // ======================
    const exclusionModal = document.getElementById('exclusion-modal');
    const detailsModal = document.getElementById('details-modal');

    document.getElementById('open-exclusion-modal-btn').addEventListener('click', openExclusionModal);
    document.getElementById('exclusion-modal-close-btn').addEventListener('click', () => { exclusionModal.classList.add('hidden'); exclusionModal.classList.remove('flex'); });
    document.getElementById('modal-close-btn').addEventListener('click', () => { detailsModal.classList.add('hidden'); detailsModal.classList.remove('flex'); });

    exclusionModal.addEventListener('click', (e) => { if (e.currentTarget === e.target) { exclusionModal.classList.add('hidden'); exclusionModal.classList.remove('flex'); } });
    detailsModal.addEventListener('click', (e) => { if (e.currentTarget === e.target) { detailsModal.classList.add('hidden'); detailsModal.classList.remove('flex'); } });

    async function openExclusionModal() {
        exclusionModal.classList.remove('hidden');
        exclusionModal.classList.add('flex');
        try {
            const currentIpRes = await fetch('https://api.ipify.org?format=json');
            const currentIp = await currentIpRes.json();
            document.getElementById('current-ip-hash').textContent = await sha256(currentIp.ip);
        } catch(e) { document.getElementById('current-ip-hash').textContent = "Error al obtener IP."; }

        const ipsRes = await fetch(`${API_URL}?action=get_excluded_ips`);
        const ips = await ipsRes.json();
        document.getElementById('excluded-ips-table-body').innerHTML = ips.map(ip => `
            <tr class="border-b" style="border-color:var(--border-color)">
                <td class="p-2"><code class="text-xs">${ip.ip_hash.substring(0,30)}...</code></td>
                <td class="p-2 text-xs">${ip.description || ''}</td>
                <td class="p-2"><button class="text-red-500 hover:text-red-400 text-xs delete-ip-btn" data-id="${ip.id}"><i class="fas fa-trash"></i></button></td>
            </tr>
        `).join('');
    }

    document.getElementById('add-ip-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const input = document.getElementById('ip-hash-input');
        if (!input.value) return;
        const formData = new FormData();
        formData.append('ip_hash', input.value);
        await fetch(`${API_URL}?action=add_excluded_ip`, { method: 'POST', body: formData });
        input.value = '';
        await openExclusionModal();
        fetchDashboard();
    });

    document.getElementById('excluded-ips-table-body').addEventListener('click', async (e) => {
        const btn = e.target.closest('.delete-ip-btn');
        if (btn) {
            if (!confirm('¿Eliminar esta IP excluida?')) return;
            const formData = new FormData();
            formData.append('id', btn.dataset.id);
            await fetch(`${API_URL}?action=delete_excluded_ip`, { method: 'POST', body: formData });
            await openExclusionModal();
            fetchDashboard();
        }
    });

    // ======================
    // PERIOD BUTTONS
    // ======================
    document.getElementById('visits-period-buttons').addEventListener('click', e => {
        if (e.target.matches('.period-btn')) {
            currentPeriod.visits = e.target.dataset.period;
            e.target.parentElement.querySelector('.active').classList.remove('active');
            e.target.classList.add('active');
            fetchDashboard();
        }
    });

    document.getElementById('clicks-period-buttons').addEventListener('click', e => {
        if (e.target.matches('.period-btn')) {
            currentPeriod.clicks = e.target.dataset.period;
            e.target.parentElement.querySelector('.active').classList.remove('active');
            e.target.classList.add('active');
            fetchProductClicks();
        }
    });

    // --- Unique Mode Toggle ---
    const uniqueToggle = document.getElementById('unique-mode-toggle');
    const uniqueLabel = document.getElementById('unique-mode-label');
    uniqueToggle.addEventListener('change', () => {
        uniqueLabel.innerHTML = uniqueToggle.checked
            ? '<i class="fas fa-user-check"></i> &Uacute;nico/D&iacute;a'
            : '<i class="fas fa-user-clock"></i> &Uacute;nico/Hora';
        fetchDashboard();
    });

    // --- Refresh Button ---
    const refreshAll = () => { fetchOps(); fetchDashboard(); };
    document.getElementById('refresh-dashboard-btn').addEventListener('click', refreshAll);

    // --- SHA-256 Helper ---
    async function sha256(message) {
        const msgBuffer = new TextEncoder().encode(message);
        const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
        return Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    // ======================
    // INITIALIZATION
    // ======================
    refreshAll();

    // Auto-refresh every 60 seconds
    setInterval(refreshAll, 60000);
});
</script>

<?php
include __DIR__ . '/core/admin_footer.php';
?>

<?php
// === CÓDIGO DE DEPURACIÓN ===
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// === FIN DE CÓDIGO DE DEPURACIÓN ===

require_once __DIR__ . '/../core/admin_header.php';
require_once __DIR__ . '/../core/db_connect.php';

// --- PASO 1: Fuente de verdad unificada ---
// El panel ya NO depende del caché ligero obsoleto (cache_pedidos.json /
// generar_cache_ligero.php, que golpeaba la API externa por su cuenta).
// Ahora la sección, el rank y la expiración de cada ítem se leen directamente
// de detalles_pedido, donde checkout/process.php los congela al momento de la
// compra tomándolos del caché maestro (banano_primordial.json) vía el DAL
// (ShopRepository -> cart_manager). Una sola lógica, sin duplicar fuentes.

// --- PASO 2: Consulta Maestra ULTRALIGERA para evitar 503 ---
$todos_los_pedidos = [];
$limit_records = 100;
$sql = "SELECT * FROM pedidos ORDER BY id DESC LIMIT ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('i', $limit_records);
    $stmt->execute();
    $resultado = $stmt->get_result();
    if ($resultado) {
        while ($fila = $resultado->fetch_assoc()) {
            $todos_los_pedidos[] = $fila;
        }
    }
    $stmt->close();
}

// --- PASO 3: Obtener detalles y destinatarios con una segunda consulta rápida ---
if (!empty($todos_los_pedidos)) {
    $order_ids = array_map(function($p) { return $p['id']; }, $todos_los_pedidos);
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $types = str_repeat('i', count($order_ids));

    // Detalles
    $detalles_por_pedido = [];
    $sql_detalles = "SELECT pedido_id, nombre_producto, categoria_producto, producto_id_str, tipo_producto, section_name, store_rank, fecha_expiracion_item FROM detalles_pedido WHERE pedido_id IN ($placeholders)";
    $stmt_detalles = $conn->prepare($sql_detalles);
    $stmt_detalles->bind_param($types, ...$order_ids);
    $stmt_detalles->execute();
    $res_detalles = $stmt_detalles->get_result();
    while ($row = $res_detalles->fetch_assoc()) {
        $detalles_por_pedido[$row['pedido_id']][] = [
            'nombre' => $row['nombre_producto'],
            'categoria' => $row['categoria_producto'],
            'id_str' => $row['producto_id_str'],
            'tipo_producto' => $row['tipo_producto'],
            'section_name' => $row['section_name'],
            'store_rank' => $row['store_rank'],
            'fecha_expiracion_item' => $row['fecha_expiracion_item']
        ];
    }
    $stmt_detalles->close();

    // Destinatarios
    $destinatarios_por_pedido = [];
    $sql_dest = "SELECT dp.pedido_id, dpd.nickname_destinatario FROM detalles_pedido_destinatarios dpd JOIN detalles_pedido dp ON dpd.detalle_pedido_id = dp.id WHERE dp.pedido_id IN ($placeholders)";
    $stmt_dest = $conn->prepare($sql_dest);
    $stmt_dest->bind_param($types, ...$order_ids);
    $stmt_dest->execute();
    $res_dest = $stmt_dest->get_result();
    while ($row = $res_dest->fetch_assoc()) {
        $destinatarios_por_pedido[$row['pedido_id']][] = $row['nickname_destinatario'];
    }
    $stmt_dest->close();

    // Unir los datos
    foreach ($todos_los_pedidos as &$pedido) {
        $pedido['productos_json'] = $detalles_por_pedido[$pedido['id']] ?? [];
        $pedido['destinatarios_regalo'] = array_values(array_unique($destinatarios_por_pedido[$pedido['id']] ?? []));
    }
}

// --- PASO 4: Obtener Categorías para el Filtro (sin cambios) ---
$categorias_para_filtro = [];
$res_cat_static = $conn->query("SELECT DISTINCT categoria FROM productos_estaticos WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria ASC");
if ($res_cat_static) {
    while($fila_cat = $res_cat_static->fetch_assoc()) {
        $categorias_para_filtro[] = $fila_cat['categoria'];
    }
}
$categorias_api = ['Skin', 'Pico', 'Gesto', 'Mochila', 'Lote', 'Pase de Batalla', 'Club Fortnite', 'Envoltorio', 'Música'];
$categorias_para_filtro = array_unique(array_merge($categorias_para_filtro, $categorias_api));
sort($categorias_para_filtro);

$conn->close();

// --- PASO 4: Preparar Datos para JavaScript ---
$pedidos_json = json_encode(array_values($todos_los_pedidos), JSON_UNESCAPED_UNICODE);
$categorias_json = json_encode($categorias_para_filtro, JSON_UNESCAPED_UNICODE);
?>

<link rel="stylesheet" href="/shared/css/order_panels_shared.css">
<script type="module">
    import { renderOrderProductRow, buildFortniteGradientVarsFromDb } from '/shared/js/order-panels-shared.js';
    window.OrderPanelsShared = { renderOrderProductRow, buildFortniteGradientVarsFromDb };
</script>

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

            --orders-bg-main: var(--primary);
            --orders-bg-card: var(--secondary);
            --orders-border: var(--border-color);
            --orders-row-bg: var(--orders-bg-card);
            --orders-row-bg-alt: var(--orders-bg-card);
            --orders-row-hover-bg: rgba(255, 255, 255, 0.04);
            --orders-row-hover-shadow: 0 10px 26px rgba(0, 0, 0, 0.35);
            --orders-row-hover-shadow-soft: 0 8px 18px rgba(0, 0, 0, 0.22);
            --orders-cell-divider: rgba(148, 163, 184, 0.25);
            --orders-text-primary: var(--text-primary);
            --orders-text-secondary: var(--text-secondary);
            --orders-text-header: var(--text-secondary);

            --orders-modal-bg: var(--secondary);
            --orders-modal-surface: var(--secondary);
            --orders-modal-muted: rgba(148, 163, 184, 0.12);
            --orders-modal-border: var(--border-color);
            --orders-modal-footer: var(--secondary);
            --orders-modal-text-primary: var(--text-primary);
            --orders-modal-text-secondary: var(--text-secondary);
        }

        html[data-theme="dark"] {
            --orders-bg-main: var(--bg-total-dark);
            --orders-bg-card: var(--bg-total-dark-surface);
            --orders-border: var(--border-total-dark);
            --orders-row-bg: var(--row-total-dark);
            --orders-row-bg-alt: var(--row-total-dark-alt);
            --orders-row-hover-bg: var(--row-total-dark-hover);
            --orders-row-hover-shadow: none;
            --orders-row-hover-shadow-soft: none;
            --orders-cell-divider: var(--border-total-dark);
            --orders-text-primary: #f3f4f6;
            --orders-text-secondary: #a1a1aa;
            --orders-text-header: #cbd5e1;

            --orders-modal-bg: var(--bg-total-dark);
            --orders-modal-surface: var(--bg-total-dark-surface);
            --orders-modal-muted: rgba(255, 255, 255, 0.06);
            --orders-modal-border: var(--border-total-dark);
            --orders-modal-footer: var(--bg-total-dark-surface);
            --orders-modal-text-primary: #f3f4f6;
            --orders-modal-text-secondary: #a1a1aa;
        }

        body.light-mode,
        html[data-theme="light"] {
            --orders-bg-main: var(--bg-light-main);
            --orders-bg-card: var(--bg-light-surface);
            --orders-border: var(--border-light);
            --orders-row-bg: var(--row-light);
            --orders-row-bg-alt: var(--row-light-alt);
            --orders-row-hover-bg: var(--row-light-hover);
            --orders-row-hover-shadow: 0 12px 30px rgba(16, 24, 40, 0.12);
            --orders-row-hover-shadow-soft: 0 8px 20px rgba(16, 24, 40, 0.10);
            --orders-cell-divider: #edf2f7;
            --orders-text-primary: var(--text-light-primary);
            --orders-text-secondary: var(--text-light-secondary);
            --orders-text-header: var(--text-light-secondary);

            --orders-modal-bg: #ffffff;
            --orders-modal-surface: #ffffff;
            --orders-modal-muted: #f3f4f6;
            --orders-modal-border: #e5e7eb;
            --orders-modal-footer: #f9fafb;
            --orders-modal-text-primary: #111827;
            --orders-modal-text-secondary: #4b5563;
        }

        #content-wrapper > main {
            background-color: var(--orders-bg-main);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .orders-card {
            background-color: var(--orders-bg-card) !important;
            border: 1px solid var(--orders-border);
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }

        html[data-theme="dark"] .orders-card {
            box-shadow: none !important;
        }

        .tabla-pedidos {
            background-color: transparent;
        }

        .tabla-pedidos thead {
            background-color: transparent;
        }

        .tabla-pedidos thead th {
            border-bottom: 1px solid var(--orders-border);
            color: var(--orders-text-header) !important;
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        .tabla-pedidos tbody tr {
            border-bottom: 1px solid var(--orders-border);
            background-color: var(--orders-row-bg);
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }

        .tabla-pedidos tbody tr:nth-child(even) {
            background-color: var(--orders-row-bg-alt);
        }

        .tabla-pedidos tbody tr:last-child {
            border-bottom: none;
        }

        .orders-cell-divider {
            border-bottom: 1px solid var(--orders-cell-divider);
        }

        .orders-text-primary { color: var(--orders-text-primary) !important; }
        .orders-text-secondary { color: var(--orders-text-secondary) !important; }

        .orders-total--discount { color: #34d399 !important; }
        .orders-total--coins { color: #fbbf24 !important; }

        body.light-mode .orders-total--discount,
        html[data-theme="light"] .orders-total--discount { color: #16a34a !important; }
        body.light-mode .orders-total--coins,
        html[data-theme="light"] .orders-total--coins { color: #b45309 !important; }

        #pagination-controls {
            border-top: 1px solid var(--orders-border);
            background-color: var(--orders-bg-card);
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }

        .product-badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600; margin-right: 0.25rem; }
        .status-badge { display: inline-flex; align-items: center; padding: 0.3rem 0.6rem; border-radius: 10px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; border: 1px solid rgba(var(--badge-rgb), 0.40); background: rgba(var(--badge-rgb), 0.14); color: rgb(var(--badge-rgb)); box-shadow: none; }

        .status-badge--yellow { --badge-rgb: 245, 158, 11; }
        .status-badge--green { --badge-rgb: 34, 197, 94; }
        .status-badge--blue { --badge-rgb: 59, 130, 246; }
        .status-badge--red { --badge-rgb: 239, 68, 68; }
        .status-badge--orange { --badge-rgb: 249, 115, 22; }
        .status-badge--purple { --badge-rgb: 168, 85, 247; }
        .status-badge--gray { --badge-rgb: 107, 114, 128; }
        .status-badge--teal { --badge-rgb: 20, 184, 166; }

        .row--reserva { border-left: 3px solid rgb(20, 184, 166); }

        .clickable-row { cursor: pointer; transition: transform 0.2s, background-color 0.3s ease, box-shadow 0.2s, filter 0.2s, border-color 0.3s ease; }
        .clickable-row:hover { transform: translateY(-2px); background-color: var(--orders-row-hover-bg); box-shadow: var(--orders-row-hover-shadow-soft); filter: drop-shadow(0 12px 22px rgba(16, 24, 40, 0.10)); }
        html[data-theme="dark"] .clickable-row:hover { box-shadow: var(--orders-row-hover-shadow); filter: none; }

        .admin-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 99999;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity .18s ease, transform .18s ease;
            padding: 10px 14px;
            border-radius: 12px;
            background: rgba(17, 24, 39, 0.92);
            color: #fff;
            font-weight: 800;
            font-size: 0.8rem;
            box-shadow: 0 12px 28px rgba(0,0,0,0.18);
        }
        .admin-toast.show { opacity: 1; transform: translateY(0); }

        .modal-skeleton {
            position: relative;
            overflow: hidden;
            border-radius: 6px;
            background: var(--orders-modal-muted);
        }
        .modal-skeleton::after {
            content: '';
            position: absolute;
            top: 0;
            left: -60%;
            width: 60%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(226, 232, 240, 0.16), transparent);
            animation: modalSkeletonShimmer 1.05s infinite;
        }
        @keyframes modalSkeletonShimmer {
            0% { transform: translateX(0); }
            100% { transform: translateX(180%); }
        }

        .priority-expires-soon { border-left: 3px solid rgba(255, 59, 59, 0.90); background-color: var(--orders-row-bg) !important; }
        html[data-theme="dark"] .priority-expires-soon { background-color: rgba(255, 59, 59, 0.06) !important; }
        .priority-review { border-left: 3px solid rgba(255, 153, 0, 0.95); background-color: var(--orders-row-bg) !important; }
        html[data-theme="dark"] .priority-review { background-color: rgba(255, 153, 0, 0.06) !important; }

        .data-badge {
            border-radius: 10px;
            border: 1px solid var(--orders-modal-border);
            background: var(--orders-modal-surface);
            color: var(--orders-modal-text-primary);
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, filter 0.2s ease;
        }
        .data-badge--disabled { opacity: 0.55; cursor: not-allowed; }
        .data-badge--copied { border-color: rgba(16, 185, 129, 0.45); background: rgba(16, 185, 129, 0.12); }

        html[data-theme="dark"] #searchInput,
        html[data-theme="dark"] #sortOrder {
            background-color: var(--orders-bg-card) !important;
            border-color: var(--orders-border) !important;
            color: var(--orders-text-primary) !important;
        }
        html[data-theme="dark"] #searchInput::placeholder { color: rgba(161, 161, 170, 0.85) !important; }

        html[data-theme="dark"] #status-tabs .tab-btn[data-status="all"] { background-color: var(--orders-bg-card) !important; color: var(--orders-text-secondary) !important; }
        html[data-theme="dark"] #status-tabs .tab-btn[data-status="all"]:hover { background-color: var(--orders-row-hover-bg) !important; }

        html[data-theme="dark"] #advanced-filters-btn {
            background-color: var(--orders-bg-card) !important;
            border: 1px solid var(--orders-border);
            color: var(--orders-text-primary) !important;
        }

        html[data-theme="dark"] #pagination-controls button:not(.bg-fortnite-blue) {
            background-color: var(--orders-bg-card) !important;
            border-color: var(--orders-border) !important;
            color: var(--orders-text-secondary) !important;
        }
        html[data-theme="dark"] #pagination-controls button:not(.bg-fortnite-blue):hover { background-color: var(--orders-row-hover-bg) !important; }

        #order-modal .bg-zinc-950 { background-color: var(--orders-modal-bg) !important; }
        #order-modal .bg-zinc-900 { background-color: var(--orders-modal-surface) !important; }
        #order-modal .bg-zinc-900\/60 { background-color: var(--orders-modal-muted) !important; }
        #order-modal .bg-zinc-900\/40 { background-color: rgba(255, 255, 255, 0.06) !important; }
        #order-modal .bg-zinc-900\/50 { background-color: rgba(255, 255, 255, 0.08) !important; }
        body.light-mode #order-modal .bg-zinc-900\/40,
        body.light-mode #order-modal .bg-zinc-900\/50 { background-color: var(--orders-modal-muted) !important; }

        #order-modal .border-zinc-800,
        #order-modal .border-slate-800,
        #order-modal .border-slate-700 { border-color: var(--orders-modal-border) !important; }

        #order-modal .text-zinc-100,
        #order-modal .text-zinc-200,
        #order-modal .text-slate-100 { color: var(--orders-modal-text-primary) !important; }

        #order-modal .text-zinc-300,
        #order-modal .text-zinc-400,
        #order-modal .text-zinc-500,
        #order-modal .text-slate-300 { color: var(--orders-modal-text-secondary) !important; }

        #order-modal .bg-zinc-950\/70 { background-color: rgba(0, 0, 0, 0.55) !important; }
        body.light-mode #order-modal .bg-zinc-950\/70,
        html[data-theme="light"] #order-modal .bg-zinc-950\/70 { background-color: rgba(255, 255, 255, 0.70) !important; }
        body.light-mode #order-modal .text-cyan-300,
        html[data-theme="light"] #order-modal .text-cyan-300 { color: #0891b2 !important; }
        body.light-mode #order-modal .modal-status-btn.text-white,
        html[data-theme="light"] #order-modal .modal-status-btn.text-white { color: var(--orders-modal-text-primary) !important; }
        #order-modal .divide-zinc-800 > :not([hidden]) ~ :not([hidden]) { border-color: var(--orders-modal-border) !important; }

        #confirm-modal .inline-block.align-bottom,
        #ip-modal .inline-block.align-bottom,
        #filters-modal .inline-block.align-bottom {
            background-color: var(--orders-modal-bg) !important;
            border: 1px solid var(--orders-modal-border);
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        #confirm-modal .inline-block.align-bottom > div:first-child,
        #ip-modal .inline-block.align-bottom > div:first-child,
        #filters-modal .inline-block.align-bottom > div:first-child {
            background-color: var(--orders-modal-bg) !important;
        }

        #confirm-modal .inline-block.align-bottom > div:last-child,
        #ip-modal .inline-block.align-bottom > div:last-child,
        #filters-modal .inline-block.align-bottom > div:last-child {
            background-color: var(--orders-modal-footer) !important;
        }

        .urgent-badge { animation: pulse-slow 3s infinite; }
        /* --- INICIO DE ESTILOS PARA VISTA MÓVIL --- */
        @media (max-width: 768px) {
            /* Ocultamos los encabezados de la tabla, ya no los necesitamos */
            .tabla-pedidos thead {
                display: none;
            }

            /* Hacemos que cada fila (tr) se comporte como una tarjeta */
            .tabla-pedidos tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid var(--orders-border); /* Borde de la tarjeta */
                border-radius: 0.5rem;   /* Esquinas redondeadas */
                padding: 1rem;
                background-color: var(--orders-row-bg);
                box-shadow: var(--orders-row-hover-shadow-soft);
            }
            .dark .tabla-pedidos tr {
                border-color: var(--orders-border); /* Borde oscuro */
                box-shadow: none;
            }

            /* Hacemos que cada celda (td) ocupe una línea y mostramos su etiqueta */
            .tabla-pedidos td {
                display: flex;
                justify-content: space-between; /* Etiqueta a la izq, valor a la der */
                align-items: center;
                padding: 0.6rem 0;
                border-bottom: 1px dashed var(--orders-border); /* Separador interno */
                text-align: right; /* Alineamos el valor a la derecha */
            }
            .dark .tabla-pedidos td {
                border-color: var(--orders-border);
            }

            .tabla-pedidos td:last-child {
                border-bottom: none; /* Quitamos el último separador */
            }

            /* La magia: creamos la etiqueta usando el atributo 'data-label' */
            .tabla-pedidos td::before {
                content: attr(data-label);
                font-weight: 600;
                text-align: left;
                margin-right: 1rem;
                color: var(--orders-text-header);
            }
            .dark .tabla-pedidos td::before {
                color: var(--orders-text-header);
            }

            /* Ajustes para los filtros en móvil */
            #filters-container {
                flex-direction: column;
                align-items: stretch;
            }
            #filters-container > div {
                justify-content: center;
                width: 100%;
            }
            #additional-filters {
                flex-direction: column;
                width: 100%;
            }
            #additional-filters > * {
                width: 100%;
            }
        }
        /* --- FIN DE ESTILOS PARA VISTA MÓVIL --- */
        @media (min-width: 769px) {
            .tabla-pedidos {
                border-collapse: separate;
                border-spacing: 0 10px;
            }

            .tabla-pedidos tbody tr {
                background-color: transparent;
                border-bottom: none;
            }

            .tabla-pedidos tbody td {
                background-color: var(--orders-row-bg);
                border-top: 1px solid var(--orders-border);
                border-bottom: 1px solid var(--orders-border);
            }

            .tabla-pedidos tbody td:first-child {
                border-left: 1px solid var(--orders-border);
                border-top-left-radius: 12px;
                border-bottom-left-radius: 12px;
            }

            .tabla-pedidos tbody td:last-child {
                border-right: 1px solid var(--orders-border);
                border-top-right-radius: 12px;
                border-bottom-right-radius: 12px;
            }

            .tabla-pedidos tbody tr.priority-expires-soon td:first-child {
                border-left: 3px solid rgba(255, 59, 59, 0.90);
            }

            .tabla-pedidos tbody tr.priority-review td:first-child {
                border-left: 3px solid rgba(255, 153, 0, 0.95);
            }

            .tabla-pedidos tbody tr:nth-child(even) td {
                background-color: var(--orders-row-bg);
            }

            .clickable-row:hover td {
                background-color: var(--orders-row-hover-bg);
            }
        }
    </style>

    <div class="min-h-screen relative">

            <!-- Filters Section -->
            <div class="orders-card rounded-lg shadow-md p-4 mb-6 transition-colors duration-200">
                <div id="filters-container" class="flex flex-wrap items-center justify-between gap-4">
                    <!-- Search -->
                    <div class="relative flex-grow max-w-md">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="searchInput" class="block w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-fortnite-blue focus:border-transparent transition" placeholder="Buscar por Nickname, Email, Código...">
                    </div>
                    
                    <!-- Status Filters -->
                    <div id="status-tabs" class="flex flex-wrap items-center gap-2">
                        <button data-status="all" class="tab-btn px-3 py-1 rounded-full text-sm font-medium bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">Todos</button>
                        <button data-status="pendiente" class="tab-btn px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200">Pendientes</button>
                        <button data-status="pagado" class="tab-btn px-3 py-1 rounded-full text-sm font-medium bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200">Pagados</button>
                        <button data-status="completado" class="tab-btn px-3 py-1 rounded-full text-sm font-medium bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200">Completados</button>
                        <button data-status="cancelado" class="tab-btn px-3 py-1 rounded-full text-sm font-medium bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200">Cancelados</button>
                        <button data-status="reserva" class="tab-btn px-3 py-1 rounded-full text-sm font-medium bg-teal-100 dark:bg-teal-900 text-teal-800 dark:text-teal-200">Reservas</button>
                    </div>
                    
                    <!-- Additional Filters -->
                    <div id="additional-filters" class="flex items-center space-x-2">
                        
                        <div class="relative">
                            <select id="sortOrder" class="appearance-none bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-white py-2 px-4 pr-8 rounded-lg leading-tight focus:outline-none focus:ring-2 focus:ring-fortnite-blue focus:border-transparent transition">
                                <option value="newest" selected>Más recientes</option>
                                <option value="oldest">Más antiguos</option>
                                <option value="urgent">Urgentes</option>
                                <option value="amount_desc">Mayor precio</option>
                                <option value="amount_asc">Menor precio</option>
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700 dark:text-gray-300">
                                <i class="fas fa-chevron-down text-xs"></i>
                            </div>
                        </div>
                        
                        <label class="flex items-center gap-2 px-3 py-2 bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg">
                            <span class="text-xs font-semibold text-gray-700 dark:text-gray-200">Confirmación</span>
                            <span class="relative inline-flex items-center">
                                <input type="checkbox" id="confirm-mode-toggle" class="sr-only peer">
                                <span class="w-11 h-6 bg-gray-300 dark:bg-gray-600 peer-checked:bg-green-500 rounded-full transition-colors"></span>
                                <span class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full transition-transform peer-checked:translate-x-5"></span>
                            </span>
                            <span id="confirm-mode-text" class="text-xs font-extrabold text-gray-700 dark:text-gray-200">Sí</span>
                        </label>
                        
                        <button id="advanced-filters-btn" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-white rounded-lg transition flex items-center space-x-2">
                            <i class="fas fa-sliders-h"></i>
                            <span class="text-sm">Filtros Avanzados</span>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Orders Table -->
            <div class="orders-card rounded-lg shadow-md transition-colors duration-200">
                <div class="overflow-x-auto">
                    <table class="min-w-full tabla-pedidos">
                        <thead>
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Pedido</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Nickname</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Sección / Tipo</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ítem</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider mobile-hidden">WhatsApp</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Total</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Estado</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="pedidos-table-body">
                            <!-- Rows will be injected by JavaScript -->
                        </tbody>
                    </table>
                </div>
                <div id="no-results" class="text-center p-8 hidden"><p class="text-gray-500 dark:text-gray-400">No se encontraron pedidos que coincidan con los filtros.</p></div>
                
                <!-- Pagination -->
                <div id="pagination-controls" class="px-4 py-3 flex items-center justify-between sm:px-6">
                     <!-- Pagination will be injected by JavaScript -->
                </div>
            </div>
    </div>
    
    <!-- MODALS SECTION -->
    <?php include __DIR__ . '/modals/order_modal.html'; ?>
    <?php include __DIR__ . '/modals/ip_modal.html'; ?>
    <?php include __DIR__ . '/modals/confirm_modal.html'; ?>
    <?php include __DIR__ . '/modals/advanced_filters_modal.html'; ?>

    <script>
    // ===============================================================
    // === JAVASCRIPT: El cerebro de toda la página                ===
    // ===============================================================

    // --- ESTADO Y DATOS GLOBALES ---
    let allOrders = <?php echo $pedidos_json; ?>;
    const allCategories = <?php echo $categorias_json; ?>;
    let currentFilters = {
        status: 'all',
        search: '',
        sort: 'newest',
        // Filtros avanzados expandidos
        categories: [],
        paymentMethods: [],
        minAmount: '',
        maxAmount: '',
        dateFrom: '',
        dateTo: ''
    };
    let currentPage = 1;
    const ordersPerPage = 10;
    let confirmCallback = null;
    let pollingInFlight = false;

    /**
     * Normaliza una fecha de la BD ('YYYY-MM-DD HH:MM:SS', en UTC) a ISO-8601
     * con sufijo Z, para que `new Date()` la interprete como UTC y no como hora
     * local del navegador. Devuelve null para valores vacíos o nulos.
     */
    function dbDateToIsoUtc(raw) {
        if (raw === null || raw === undefined) return null;
        const s = String(raw).trim();
        if (s === '' || s === '0000-00-00 00:00:00') return null;
        if (/[zZ]$|[+-]\d{2}:?\d{2}$/.test(s)) return s; // ya trae zona horaria
        return s.replace(' ', 'T') + 'Z';
    }

    const CONFIRM_MODE_STORAGE_KEY = 'admin_confirm_mode';

    function isConfirmModeEnabled() {
        try {
            const raw = localStorage.getItem(CONFIRM_MODE_STORAGE_KEY);
            if (raw === null) return true;
            return raw === '1' || raw === 'true' || raw === 'yes' || raw === 'on';
        } catch (e) {
            return true;
        }
    }

    function setConfirmModeEnabled(enabled) {
        try {
            localStorage.setItem(CONFIRM_MODE_STORAGE_KEY, enabled ? '1' : '0');
        } catch (e) {}
    }

    function showAdminToast(message) {
        const el = document.createElement('div');
        el.className = 'admin-toast';
        el.textContent = String(message || '');
        document.body.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));
        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => el.remove(), 220);
        }, 1600);
    }

    /**
     * "Enriquece" los datos de un pedido usando los valores congelados en
     * detalles_pedido (sección, rank y expiración del ítem), que provienen del
     * caché maestro vía el DAL al momento de la compra. Ya no consulta ningún
     * caché ligero externo. Mantiene la forma `product.cacheData` para no tocar
     * el resto del render.
     */
    function enrichOrderData(order) {
        if (!order.productos_json) return order;

        let products = (typeof order.productos_json === 'string') ? JSON.parse(order.productos_json) : order.productos_json;
        let soonestExpiration = null;

        products.forEach(product => {
            const expIso = dbDateToIsoUtc(product.fecha_expiracion_item);

            const sectionName = (product.section_name !== null && product.section_name !== undefined && String(product.section_name).trim() !== '')
                ? String(product.section_name).trim()
                : 'Fuera de Tienda';

            const storeRank = (product.store_rank !== null && product.store_rank !== undefined && String(product.store_rank).trim() !== '')
                ? Number(product.store_rank)
                : 999;

            product.cacheData = {
                displayType: product.tipo_producto || 'Histórico',
                offerDates: expIso ? { out: expIso } : null,
                section_name: sectionName,
                store_rank: storeRank
            };

            if (expIso) {
                const itemExpiresAt = new Date(expIso);
                if (!Number.isNaN(itemExpiresAt.getTime()) && (!soonestExpiration || itemExpiresAt < soonestExpiration)) {
                    soonestExpiration = itemExpiresAt;
                }
            }
        });

        order.productos_json = products;
        if (soonestExpiration) {
            const LIMA_OFFSET_MS = 5 * 60 * 60 * 1000;
            order.proxima_expiracion_cache = soonestExpiration.toISOString();
            order.proxima_expiracion_cache_lima = new Date(soonestExpiration.getTime() - LIMA_OFFSET_MS).toISOString();
        }
        return order;
    }

    /**
     * Copia un texto al portapapeles y da feedback visual.
     * @param {string} text - El texto a copiar.
     * @param {HTMLElement} element - El botón que fue clickeado, para dar feedback.
     */
    function copyToClipboard(text, element) {
        navigator.clipboard.writeText(text).then(() => {
            if (element && element.classList && element.classList.contains('data-badge')) {
                element.classList.remove('data-badge--disabled');
                element.classList.add('data-badge--copied');
                setTimeout(() => {
                    element.classList.remove('data-badge--copied');
                }, 1200);
                return;
            }
            const icon = element.querySelector('i');
            if (icon) {
                const originalIcon = icon.className;
                icon.className = 'fas fa-check text-green-500';
                setTimeout(() => {
                    icon.className = originalIcon;
                }, 1500);
            }
        }).catch(err => {
            console.error('Error al copiar al portapapeles:', err);
            alert('No se pudo copiar el texto.');
        });
    }

    function attachCopyBadge(el, text) {
        if (!el) return;

        const safeText = String(text ?? '').trim();
        const isDisabled = (!safeText || safeText === 'No provisto' || safeText === '-');

        el.classList.toggle('data-badge--disabled', isDisabled);

        if (isDisabled) {
            el.onclick = null;
            el.onkeydown = null;
            return;
        }

        el.onclick = (e) => {
            if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
            copyToClipboard(safeText, el);
        };

        el.onkeydown = (e) => {
            if (!e) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                copyToClipboard(safeText, el);
            }
        };
    }

    /**
     * Reproduce un sonido desde una URL de forma segura.
     * @param {string} soundUrl - La URL del archivo de audio.
     */
    function playSound(soundUrl) {
        const audio = new Audio(soundUrl);
        // Usamos .catch() para evitar errores en la consola si el navegador bloquea el autoplay.
        audio.play().catch(error => {
            console.warn("El navegador bloqueó la reproducción automática del sonido. Se requiere interacción del usuario.", error);
        });
    }

    // --- ENUMS Y HELPERS ---
    const STATUS_CONFIG = {
        'pendiente': { text: 'PENDIENTE', icon: 'fa-clock', color: 'yellow' },      // Cambiado a Amarillo
        'pagado': { text: 'PAGADO', icon: 'fa-check-circle', color: 'green' },     // Cambiado a Verde
        'completado': { text: 'COMPLETADO', icon: 'fa-check-double', color: 'blue' },    // Cambiado a Azul
        'cancelado': { text: 'CANCELADO', icon: 'fa-ban', color: 'red' },
        'reembolso_pendiente': { text: 'REEMBOLSO PENDIENTE', icon: 'fa-exclamation-triangle', color: 'orange' },
        'reembolsado': { text: 'REEMBOLSADO', icon: 'fa-undo', color: 'purple' },
        'default': { text: 'DESCONOCIDO', icon: 'fa-question-circle', color: 'gray' }
    };

    const CATEGORY_COLORS = {
        'Skin': 'purple', 'Lote': 'blue', 'Pase': 'yellow', 'Gesto': 'pink', 'Pico': 'gray', 'Mochila': 'indigo',
        'default': 'gray'
    };

    const PAYMENT_METHOD_CONFIG = {
        'yape': { text: 'Yape', color: 'purple' },
        'plin': { text: 'Plin', color: 'green' },
        'transferencia': { text: 'Transferencia', color: 'orange' },
        'izipay': { text: 'IziPay', color: 'cyan' },
        'otro': { text: 'Otro', color: 'gray' }
    };

    // --- AJUSTES DE WHATSAPP ---
// --- FIN DE AJUSTES ---

// --- UTC-FIRST: el backend SIEMPRE envía fechas en UTC (formato MySQL "YYYY-MM-DD HH:MM:SS" o ISO).
// Este helper las normaliza a un instante UTC real para que la conversión a la zona
// horaria local del navegador (Perú, España, donde sea) sea automática y correcta.
function parseServerDateUTC(dateString) {
    if (!dateString) return null;
    if (dateString instanceof Date) return dateString;
    let s = String(dateString).trim();
    // Si ya trae zona horaria explícita (Z u offset ±HH:MM), se respeta tal cual.
    if (/[zZ]$|[+-]\d{2}:?\d{2}$/.test(s)) return new Date(s);
    // "YYYY-MM-DD HH:MM:SS" -> ISO 8601 con sufijo Z (UTC) para un parseo fiable en todos los navegadores.
    s = s.replace(' ', 'T');
    return new Date(s + 'Z');
}

function timeAgo(dateString) {
    // Comparamos el instante UTC del pedido contra la hora actual del navegador.
    // Al ser ambos instantes absolutos, el tiempo relativo es exacto sin importar la zona horaria.
    const date = parseServerDateUTC(dateString);
    if (!date || Number.isNaN(date.getTime())) return '';

    const now = new Date(); // Esto siempre es la hora local del navegador.
    
    // Ahora la diferencia de tiempo será correcta, sin importar la zona horaria.
    const seconds = Math.floor((now - date) / 1000);

    // Agregamos una pequeña validación por si hay desincronización de relojes
    if (seconds < 0) {
        return `Recién`;
    }
    
    if (seconds < 60) {
        return `Hace un momento`;
    }

    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);
    const months = Math.floor(days / 30);
    const years = Math.floor(days / 365);

    if (years > 0) return `Hace ${years} año${years > 1 ? 's' : ''}`;
    if (months > 0) return `Hace ${months} mes${months > 1 ? 's' : ''}`;
    if (days > 0) return `Hace ${days} día${days > 1 ? 's' : ''}`;
    
    if (hours > 0) {
        const remainingMinutes = minutes % 60;
        if (remainingMinutes > 0) {
             return `Hace ${hours}h ${remainingMinutes}m`;
        }
        return `Hace ${hours} hora${hours > 1 ? 's' : ''}`;
    }
    
    if (minutes > 0) {
        return `Hace ${minutes} min`;
    }

    return `Recién`; // Por si acaso
}
    
 // --- BLOQUE NUEVO A AÑADIR ---
function formatModalDate(dateString) {
        // Si la fecha es nula o no existe (ej. un pedido no pagado), devolvemos un guion.
        if (!dateString) {
            return '-';
        }
        
        // Parseamos como UTC y dejamos que toLocaleString muestre la fecha en la
        // zona horaria local del navegador del administrador (sin fijar timeZone).
        const date = parseServerDateUTC(dateString);

        const options = {
            year: 'numeric', 
            month: '2-digit', 
            day: '2-digit', 
            hour: '2-digit', 
            minute: '2-digit', 
            second: '2-digit',
            hour12: true 
        };
        return date.toLocaleString('es-PE', options);
    }

    function formatModalDateParts(dateString) {
        if (!dateString) {
            return { date: '-', time: '' };
        }

        // UTC -> zona horaria local del navegador del administrador.
        const date = parseServerDateUTC(dateString);

        const dateLabel = new Intl.DateTimeFormat('es-PE', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        }).format(date);

        const timeLabel = new Intl.DateTimeFormat('es-PE', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        }).format(date).replace(/\u00A0/g, ' ');

        return { date: dateLabel, time: timeLabel };
    }

    function formatElapsedShort(dateString) {
        if (!dateString) return '';

        const date = parseServerDateUTC(dateString);
        if (!date || Number.isNaN(date.getTime())) return '';
        const now = new Date();
        let seconds = Math.floor((now - date) / 1000);
        if (seconds < 0) seconds = 0;

        if (seconds < 60) return 'Just now';
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const m = minutes % 60;

        if (hours > 0) {
            if (m > 0) return `${hours}h ${m}m ago`;
            return `${hours}h ago`;
        }

        return `${minutes}m ago`;
    }

    function setModalPendingStatus(status) {
        if (!orderModal) return;

        const normalized = String(status || '').trim();
        orderModal.dataset.pendingStatus = normalized;

        const buttons = orderModal.querySelectorAll('.modal-status-btn');
        buttons.forEach((btn) => {
            const isActive = String(btn.dataset.status || '') === normalized;

            btn.classList.toggle('border-cyan-400', isActive);
            btn.classList.toggle('ring-2', isActive);
            btn.classList.toggle('ring-cyan-400/30', isActive);
            btn.classList.toggle('shadow-md', isActive);
            btn.classList.toggle('shadow-cyan-500/20', isActive);
            btn.classList.toggle('bg-zinc-900/60', isActive);
            btn.classList.toggle('text-white', isActive);

            btn.classList.toggle('border-zinc-800', !isActive);
            btn.classList.toggle('bg-zinc-950', !isActive);
            btn.classList.toggle('text-zinc-200', !isActive);
        });
    }
    // --- FIN DEL BLOQUE NUEVO ---
    // --- NUEVA FUNCIÓN A AÑADIR ---
    function copyToClipboardLegacy(text, element) {
        copyToClipboard(text, element);
    }
    // --- FUNCIONES DE RENDERIZADO (CON FILTRO DE CATEGORÍA) ---

    function renderPage() {
        const tbody = document.getElementById('pedidos-table-body');
        const noResultsDiv = document.getElementById('no-results');
        tbody.innerHTML = '';

        // 1. Filtrado
        let filteredOrders = allOrders.filter(order => {
            const searchLower = currentFilters.search.toLowerCase();
            let statusMatch;
            if (currentFilters.status === 'reserva') {
                statusMatch = order.es_reserva == 1;
            } else {
                statusMatch = currentFilters.status === 'all' || currentFilters.status.split(',').includes(order.estado);
            }
            const searchMatch = !searchLower || 
                (order.codigo_pedido && order.codigo_pedido.toLowerCase().includes(searchLower)) || 
                (order.nickname_fortnite_cliente && order.nickname_fortnite_cliente.toLowerCase().includes(searchLower)) || 
                (order.email_cliente && order.email_cliente.toLowerCase().includes(searchLower)) ||
                (order.whatsapp_discord_cliente && order.whatsapp_discord_cliente.includes(searchLower));
            
            // --- INICIO DE FILTROS AVANZADOS ---
            const orderCategories = order.productos_concatenados ? order.productos_concatenados.split('||').map(p => p.split(';;')[1]) : [];
            const categoryMatch = currentFilters.categories.length === 0 || currentFilters.categories.some(cat => orderCategories.includes(cat));

            // Nota: Asegúrate de que tu tabla 'pedidos' tenga una columna 'metodo_pago' para que esto funcione.
            const paymentMatch = currentFilters.paymentMethods.length === 0 || (order.metodo_pago && currentFilters.paymentMethods.includes(order.metodo_pago));
            
            const minAmountMatch = !currentFilters.minAmount || parseFloat(order.total_pagar) >= parseFloat(currentFilters.minAmount);
            const maxAmountMatch = !currentFilters.maxAmount || parseFloat(order.total_pagar) <= parseFloat(currentFilters.maxAmount);

            let dateMatch = true;
            if (currentFilters.dateFrom || currentFilters.dateTo) {
                // El pedido viene en UTC; los límites del filtro los eligió el admin en SU día local.
                // Comparar instantes absolutos (UTC del pedido vs. medianoche local del filtro) es correcto.
                const orderDate = parseServerDateUTC(order.fecha_creacion);
                const fromDate = currentFilters.dateFrom ? new Date(currentFilters.dateFrom + 'T00:00:00') : null;
                const toDate = currentFilters.dateTo ? new Date(currentFilters.dateTo + 'T23:59:59') : null;
                dateMatch = (!fromDate || orderDate >= fromDate) && (!toDate || orderDate <= toDate);
            }
            // --- FIN DE FILTROS AVANZADOS ---

            return statusMatch && searchMatch && categoryMatch && paymentMatch && minAmountMatch && maxAmountMatch && dateMatch;
        });

        // 2. Ordenamiento con Prioridad Absoluta
        filteredOrders.sort((a, b) => {
            const nowMs = Date.now();
            const LIMA_OFFSET_MS = 5 * 60 * 60 * 1000;

            const getHoursLeftLima = (order) => {
                if (!order || !order.proxima_expiracion_cache) return null;
                if (order.estado === 'completado') return null;

                const expiresUtc = new Date(order.proxima_expiracion_cache);
                if (Number.isNaN(expiresUtc.getTime())) return null;

                // Conversión explícita UTC -> Perú (UTC-5)
                const expiresLimaMs = expiresUtc.getTime() - LIMA_OFFSET_MS;
                const nowLimaMs = nowMs - LIMA_OFFSET_MS;
                return (expiresLimaMs - nowLimaMs) / (1000 * 60 * 60);
            };

            const aHoursLeft = getHoursLeftLima(a);
            const bHoursLeft = getHoursLeftLima(b);

            const aIsUrgent = (aHoursLeft !== null) && aHoursLeft < 12;
            const bIsUrgent = (bHoursLeft !== null) && bHoursLeft < 12;

            const aIsNotified = a.estado === 'pendiente' && a.estado_pago_cliente === 'notificado_pendiente_revision';
            const bIsNotified = b.estado === 'pendiente' && b.estado_pago_cliente === 'notificado_pendiente_revision';

            const aPriority = aIsUrgent ? 1 : (aIsNotified ? 2 : 3);
            const bPriority = bIsUrgent ? 1 : (bIsNotified ? 2 : 3);

            if (aPriority < bPriority) return -1;
            if (aPriority > bPriority) return 1;

            // --- LÓGICA DE ORDEN CORREGIDA ---
            switch (currentFilters.sort) {
                case 'oldest': return parseServerDateUTC(a.fecha_creacion) - parseServerDateUTC(b.fecha_creacion); // Correcto para más antiguos
                case 'newest': return parseServerDateUTC(b.fecha_creacion) - parseServerDateUTC(a.fecha_creacion); // Correcto para más recientes
                case 'urgent': {
                    const aKey = (aHoursLeft !== null) ? aHoursLeft : Number.POSITIVE_INFINITY;
                    const bKey = (bHoursLeft !== null) ? bHoursLeft : Number.POSITIVE_INFINITY;
                    if (aKey < bKey) return -1;
                    if (aKey > bKey) return 1;
                    return parseServerDateUTC(b.fecha_creacion) - parseServerDateUTC(a.fecha_creacion);
                }
                case 'amount_desc': return parseFloat(b.total_pagar) - parseFloat(a.total_pagar);
                case 'amount_asc': return parseFloat(a.total_pagar) - parseFloat(b.total_pagar);
                default: return parseServerDateUTC(a.fecha_creacion) - parseServerDateUTC(b.fecha_creacion); // Default a más antiguos
            }
        });

        // 3. Paginación y Renderizado
        const totalResults = filteredOrders.length;
        const totalPages = Math.ceil(totalResults / ordersPerPage);
        currentPage = Math.min(currentPage, totalPages) || 1;
        const start = (currentPage - 1) * ordersPerPage;
        const end = start + ordersPerPage;
        const paginatedOrders = filteredOrders.slice(start, end);

        if (paginatedOrders.length === 0) {
            noResultsDiv.classList.remove('hidden');
            tbody.innerHTML = '';
        } else {
            noResultsDiv.classList.add('hidden');
            paginatedOrders.forEach(order => tbody.appendChild(createOrderRow(order)));
        }

        renderPagination(totalResults, totalPages, start, end);
    }

    // --- EL `DOMContentLoaded` VUELVE A SER SIMPLE ---
    document.addEventListener('DOMContentLoaded', () => {
        // --- INICIO DE LA LÓGICA FINAL Y ORDENADA ---

        // 1. PRIMERO, configuramos CÓMO funcionan los botones de los tabs
        document.querySelectorAll('.tab-btn').forEach(tab => {
            tab.addEventListener('click', () => {
                // Cuando se hace click, actualiza el filtro y renderiza la página
                document.querySelector('.tab-btn.active-tab')?.classList.remove('active-tab');
                tab.classList.add('active-tab');
                currentFilters.status = tab.dataset.status;
                currentPage = 1;
                renderPage(); // <-- Aquí se llama a la función que dibuja la tabla
            });
        });

        // 2. Enriquecemos los datos que ya nos dio PHP
        allOrders.forEach(order => enrichOrderData(order));

        // 3. AHORA que los botones ya saben qué hacer, hacemos el primer click para dibujar la tabla
        document.querySelector('.tab-btn[data-status="all"]').click();

        // 4. Sincronizar Tailwind dark: con el tema del panel admin (data-theme)
        const html = document.documentElement;
        const syncDarkClass = () => {
            const theme = html.getAttribute('data-theme') || 'dark';
            html.classList.toggle('dark', theme !== 'light');
        };
        syncDarkClass();
        const observer = new MutationObserver(() => syncDarkClass());
        observer.observe(html, { attributes: true, attributeFilter: ['data-theme'] });

        document.getElementById('searchInput').addEventListener('input', (e) => {
            currentFilters.search = e.target.value;
            currentPage = 1;
            renderPage();
        });

        document.getElementById('sortOrder').addEventListener('change', (e) => {
            currentFilters.sort = e.target.value;
            currentPage = 1;
            renderPage();
        });

        const confirmToggle = document.getElementById('confirm-mode-toggle');
        const confirmText = document.getElementById('confirm-mode-text');
        if (confirmToggle && confirmText) {
            const enabled = isConfirmModeEnabled();
            confirmToggle.checked = enabled;
            confirmText.textContent = enabled ? 'Sí' : 'No';
            confirmToggle.addEventListener('change', () => {
                const next = !!confirmToggle.checked;
                setConfirmModeEnabled(next);
                confirmText.textContent = next ? 'Sí' : 'No';
                showAdminToast(next ? 'Confirmación: Sí' : 'Confirmación: No');
            });
        }

        document.querySelectorAll('[data-close-button]').forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = btn.closest('.fixed.inset-0');
                if (modal) closeModal(modal);
            });
        });

        window.addEventListener('click', (event) => {
            if (event.target.hasAttribute('data-modal-backdrop')) {
                closeModal(event.target);
            }
        });

        document.getElementById('advanced-filters-btn').addEventListener('click', openAdvancedFilters);
        document.getElementById('apply-filters').addEventListener('click', () => {
            const selectedCategories = [];
            document.querySelectorAll('#adv-filter-categories input:checked').forEach(c => selectedCategories.push(c.dataset.category));
            currentFilters.categories = selectedCategories;
            const selectedPayments = [];
            document.querySelectorAll('#adv-filter-payment input:checked').forEach(p => selectedPayments.push(p.dataset.payment));
            currentFilters.paymentMethods = selectedPayments;
            currentFilters.dateFrom = document.getElementById('filter-date-from').value;
            currentFilters.dateTo = document.getElementById('filter-date-to').value;
            currentFilters.minAmount = document.getElementById('filter-amount-min').value;
            currentFilters.maxAmount = document.getElementById('filter-amount-max').value;
            currentPage = 1;
            renderPage();
            closeModal(filtersModal);
        });

        document.getElementById('reset-filters').addEventListener('click', () => {
            document.querySelectorAll('#filters-modal input[type="checkbox"]').forEach(c => c.checked = false);
            document.getElementById('filter-date-from').value = '';
            document.getElementById('filter-date-to').value = '';
            document.getElementById('filter-amount-min').value = '';
            document.getElementById('filter-amount-max').value = '';
            currentFilters.categories = [];
            currentFilters.paymentMethods = [];
            currentFilters.dateFrom = '';
            currentFilters.dateTo = '';
            currentFilters.minAmount = '';
            currentFilters.maxAmount = '';
            currentPage = 1;
            renderPage();
            closeModal(filtersModal);
        });

        document.getElementById('confirm-action-button').addEventListener('click', executeAction);

        setInterval(checkForNewOrders, 10000);
    });

    function createOrderRow(order) {
        const row = document.createElement('tr');
        const now = new Date();
        const nowMs = now.getTime();
        let priorityClass = '';
        let notificationBadgeHtml = '';

        // --- DECLARACIÓN ÚNICA Y CORRECTA ---
        const isNotified = order.estado === 'pendiente' && order.estado_pago_cliente === 'notificado_pendiente_revision';

        const LIMA_OFFSET_MS = 5 * 60 * 60 * 1000;
        const expiresUtc = order.proxima_expiracion_cache ? new Date(order.proxima_expiracion_cache) : null;
        const isCompleted = order.estado === 'completado';

        let hoursLeft = null;
        if (expiresUtc && !Number.isNaN(expiresUtc.getTime()) && !isCompleted) {
            const expiresLimaMs = expiresUtc.getTime() - LIMA_OFFSET_MS;
            const nowLimaMs = nowMs - LIMA_OFFSET_MS;
            hoursLeft = (expiresLimaMs - nowLimaMs) / (1000 * 60 * 60);
        }

        const isExpiringToday = (hoursLeft !== null) && hoursLeft < 12;

        // Lógica para resaltar la fila y mostrar la insignia "NOTIFICADO"
        if (isExpiringToday) {
            const hoursLabel = Math.max(0, Math.ceil(hoursLeft));
            const expTitle = (expiresUtc && !Number.isNaN(expiresUtc.getTime()))
                ? new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true }).format(expiresUtc)
                : '';
            priorityClass = 'priority-expires-soon';
            notificationBadgeHtml += `<div class="mb-1"><span class="px-2 py-0.5 bg-red-600 text-white text-xs font-bold rounded-full urgent-badge" title="${expTitle}">HOY · ${hoursLabel}h</span></div>`;
        } else if (isNotified) {
             notificationBadgeHtml += `<div class="mb-1"><span class="px-2 py-0.5 bg-orange-500 text-white text-xs font-bold rounded-full">NOTIFICADO</span></div>`;
        }

        if (order.es_reserva == 1) {
            notificationBadgeHtml += `<div class="mb-1"><span class="px-2 py-0.5 bg-teal-600 text-white text-xs font-bold rounded-full">RESERVA</span></div>`;
            priorityClass += ' row--reserva';
        }

        row.className = `clickable-row ${priorityClass}`;
        row.onclick = () => openOrderModal(order.id);

        const statusInfo = STATUS_CONFIG[order.estado] || STATUS_CONFIG.default;

        const todosLosDestinatarios = new Set([order.nickname_fortnite_cliente, ...(order.destinatarios_regalo || [])]);
        let compradorSubText = '';
        if (todosLosDestinatarios.size > 1) {
            compradorSubText = `+${todosLosDestinatarios.size - 1} otros`;
        }

        const productos = order.productos_json || [];
        let tiposHtml = '';
        let itemsHtml = '';
        const productosVisibles = productos.slice(0, 2);

        const escapeHtml = (str) => String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        productosVisibles.forEach((p, index) => {
            const sectionName = p.cacheData?.section_name || '—';
            const typeName = p.tipo_producto || p.cacheData?.displayType || p.categoria || '—';
            const nombreItem = p.nombre;
            const borderClass = (index < productosVisibles.length - 1) ? 'orders-cell-divider' : '';
            tiposHtml += `
                <div class="py-1.5 ${borderClass}">
                    <div class="text-sm font-semibold orders-text-primary truncate">${escapeHtml(sectionName)}</div>
                    <div class="text-xs font-semibold orders-text-secondary truncate">${escapeHtml(typeName)}</div>
                </div>
            `;
            itemsHtml += `<div class="py-1.5 ${borderClass}">${escapeHtml(nombreItem)}</div>`;
        });

        if (productos.length > 2) {
            itemsHtml += `<div class="text-xs text-gray-400 pt-2">+${productos.length - 2} más...</div>`;
            tiposHtml += `<div>&nbsp;</div>`;
        }

        // --- LÓGICA DE BOTONES (AHORA CORREGIDA) ---
        let specialActionsHtml = '';

        // Botón 1: Descartar Notificación (usa la variable única 'isNotified')
        if (isNotified) {
            specialActionsHtml += `<button onclick="event.stopPropagation(); confirmDismiss(${order.id})" title="Descartar Notificación" class="order-action-btn order-action-btn--warning p-2 w-9 h-9 rounded-md text-white text-xs flex items-center justify-center transition bg-yellow-600 hover:bg-yellow-700 mr-1"><i class="fas fa-bell-slash"></i></button>`;
        }

        // Botón 2: Eliminar Pedido
        specialActionsHtml += `<button onclick="event.stopPropagation(); confirmDelete(${order.id})" title="Eliminar Pedido Permanentemente" class="order-action-btn order-action-btn--danger p-2 w-9 h-9 rounded-md text-white text-xs flex items-center justify-center transition bg-gray-600 hover:bg-red-800 mr-2"><i class="fas fa-trash-alt"></i></button>`;

        let actionsHtml = '';
        const baseButtonClasses = "order-action-btn p-2 w-9 h-9 rounded-md text-white text-xs flex items-center justify-center transition";
        switch (order.estado) {
            case 'pendiente':
                actionsHtml = `<button onclick="event.stopPropagation(); confirmAction('pagado', ${order.id})" title="Marcar Pagado" class="${baseButtonClasses} order-action-btn--success bg-green-500 hover:bg-green-600"><i class="fas fa-check"></i></button><button onclick="event.stopPropagation(); confirmAction('cancelado', ${order.id})" title="Cancelar" class="${baseButtonClasses} order-action-btn--danger bg-red-500 hover:bg-red-600"><i class="fas fa-times"></i></button>`; break;
            case 'pagado':
                actionsHtml = `<button onclick="event.stopPropagation(); confirmAction('completado', ${order.id})" title="Marcar Completado" class="${baseButtonClasses} order-action-btn--success bg-green-500 hover:bg-green-600"><i class="fas fa-dolly"></i></button><button onclick="event.stopPropagation(); confirmAction('cancelado', ${order.id})" title="Cancelar" class="${baseButtonClasses} order-action-btn--danger bg-red-500 hover:bg-red-600"><i class="fas fa-times"></i></button>`; break;
            case 'completado':
                 actionsHtml = `<button onclick="event.stopPropagation(); confirmAction('pagado', ${order.id})" title="Revertir a Pagado" class="${baseButtonClasses} order-action-btn--info bg-blue-500 hover:bg-blue-600"><i class="fas fa-arrow-left"></i></button><button onclick="event.stopPropagation(); confirmAction('cancelado', ${order.id})" title="Cancelar" class="${baseButtonClasses} order-action-btn--danger bg-red-500 hover:bg-red-600"><i class="fas fa-times"></i></button>`; break;
            case 'cancelado':
                actionsHtml = `<button onclick="event.stopPropagation(); confirmAction('reembolso_pendiente', ${order.id})" title="Marcar Reembolso Pendiente" class="${baseButtonClasses} order-action-btn--warning bg-yellow-500 hover:bg-yellow-600"><i class="fas fa-exclamation-triangle"></i></button><button onclick="event.stopPropagation(); confirmAction('pendiente', ${order.id})" title="Revertir a Pendiente" class="${baseButtonClasses} order-action-btn--neutral bg-gray-500 hover:bg-gray-600"><i class="fas fa-history"></i></button>`; break;
            default:
                actionsHtml = `<button onclick="event.stopPropagation(); openOrderModal(${order.id})" class="text-gray-400 dark:text-gray-300 p-2"><i class="fas fa-eye"></i></button>`; break;
        }

        let whatsappHtml = '<span class="text-gray-400">N/A</span>';
        if (order.whatsapp_discord_cliente) {
            const whatsappNumber = order.whatsapp_discord_cliente.replace(/\D/g, '');
            whatsappHtml = `<a href="https://wa.me/${whatsappNumber}" target="_blank" onclick="event.stopPropagation()" class="inline-flex items-center py-1 px-3 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors cursor-pointer"><i class="fab fa-whatsapp text-green-600 mr-1.5"></i><span class="font-mono text-sm text-gray-700 dark:text-gray-300">${order.whatsapp_discord_cliente}</span></a>`;
        }

        let paymentMethodHtml = '';
        if (order.metodo_pago) {
            const config = PAYMENT_METHOD_CONFIG[order.metodo_pago] || PAYMENT_METHOD_CONFIG.otro;
            paymentMethodHtml = `<div class="mt-1.5"><span class="text-xs font-bold px-2 py-0.5 rounded-full bg-${config.color}-100 text-${config.color}-800 dark:bg-${config.color}-900 dark:text-${config.color}-200">${config.text}</span></div>`;
        }

        row.innerHTML = `
            <td data-label="Pedido" class="px-6 py-4 whitespace-nowrap"><div>${notificationBadgeHtml}<div class="text-sm font-medium orders-text-primary">${order.codigo_pedido}</div><div class="text-sm orders-text-secondary">${timeAgo(order.fecha_creacion)}</div></div></td>
            <td data-label="Nickname" class="px-6 py-4 whitespace-nowrap"><div><div class="text-sm font-medium orders-text-primary">${order.nickname_fortnite_cliente}</div><div class="text-sm orders-text-secondary">${compradorSubText}</div></div></td>
            <td data-label="Sección / Tipo" class="px-6 py-4 text-sm">${tiposHtml}</td>
            <td data-label="Ítem" class="px-6 py-4 text-sm font-medium orders-text-primary">${itemsHtml}</td>
            <td data-label="WhatsApp" class="px-6 py-4 whitespace-nowrap text-sm orders-text-secondary">${whatsappHtml}</td>
            <td data-label="Total" class="px-6 py-4 whitespace-nowrap text-sm text-center"><div><div class="font-medium ${(() => { const d = Number(order.monto_descuento || 0); const c = Number(order.coins_redeemed || 0); if (c > 0) return 'orders-total--coins'; if (d > 0) return 'orders-total--discount'; return 'orders-text-primary'; })()}">S/ ${parseFloat(order.total_pagar).toFixed(2)}</div>${paymentMethodHtml}</div></td>
            <td data-label="Estado" class="px-6 py-4 whitespace-nowrap"><span class="status-badge status-badge--${statusInfo.color}"><i class="fas ${statusInfo.icon} mr-1"></i> ${statusInfo.text}</span></td>
            <td data-label="Acciones" class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium"><div class="flex flex-row items-center justify-end">${specialActionsHtml}<div class="flex items-center space-x-2">${actionsHtml}</div></div></td>
        `;
        return row;
    }

    function renderPagination(totalResults, totalPages, start, end) {
        const container = document.getElementById('pagination-controls');
        if (totalResults === 0) {
            container.innerHTML = '';
            return;
        }

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

    // --- LÓGICA DE MODALES ---
    const orderModal = document.getElementById('order-modal');
    let modalEditState = null;
    let editCatalogCache = { at: 0, data: null };
    const ipModal = document.getElementById('ip-modal');
    const confirmModal = document.getElementById('confirm-modal');
    const filtersModal = document.getElementById('filters-modal');

    function applyModalSkeleton(el, widthPx) {
        if (!el) return;
        el.classList.add('modal-skeleton');
        el.style.display = 'inline-block';
        el.style.width = `${widthPx}px`;
        el.textContent = '\u00A0';
    }

    function clearModalSkeleton(el) {
        if (!el) return;
        el.classList.remove('modal-skeleton');
        el.style.width = '';
        if (el.textContent === '\u00A0') el.textContent = '';
    }

    function setOrderModalSkeletonState(isLoading) {
        const orderIdEl = document.getElementById('modal-order-id');
        const statusBadge = document.getElementById('modal-order-status');
        const headerIpEl = document.getElementById('modal-header-ip');

        const nameEl = document.getElementById('modal-user-display-name');
        const statsCompletedEl = document.getElementById('modal-user-stats-completed');
        const statsSpentEl = document.getElementById('modal-user-stats-spent');
        const statsRegisteredEl = document.getElementById('modal-user-stats-registered');
        const tagEl = document.getElementById('modal-user-tag');
        const banHammerEl = document.getElementById('modal-ban-hammer');
        const whatsappNumberEl = document.getElementById('modal-whatsapp-number');
        const bananosTextEl = document.getElementById('modal-bananos-balance-text');

        const productsContainer = document.getElementById('modal-products-container');
        const summaryContainer = document.getElementById('modal-summary-container');

        const requestDateEl = document.getElementById('modal-request-date');
        const requestTimeEl = document.getElementById('modal-request-time');
        const requestElapsedEl = document.getElementById('modal-request-elapsed');
        const notifiedDateEl = document.getElementById('modal-notified-date');
        const notifiedTimeEl = document.getElementById('modal-notified-time');
        const notifiedElapsedEl = document.getElementById('modal-notified-elapsed');
        const paidDateEl = document.getElementById('modal-paid-date');
        const paidTimeEl = document.getElementById('modal-paid-time');
        const paidElapsedEl = document.getElementById('modal-paid-elapsed');
        const completedDateEl = document.getElementById('modal-completed-date');
        const completedTimeEl = document.getElementById('modal-completed-time');
        const completedElapsedEl = document.getElementById('modal-completed-elapsed');

        if (isLoading) {
            applyModalSkeleton(orderIdEl, 120);
            if (statusBadge) {
                statusBadge.classList.add('modal-skeleton');
                statusBadge.style.width = '140px';
                statusBadge.style.height = '26px';
                statusBadge.innerHTML = '';
            }

            if (headerIpEl) {
                headerIpEl.classList.add('opacity-50', 'cursor-not-allowed');
                headerIpEl.onclick = null;
            }

            if (banHammerEl) {
                banHammerEl.classList.add('hidden');
                banHammerEl.onclick = null;
            }

            applyModalSkeleton(nameEl, 180);
            applyModalSkeleton(statsCompletedEl, 44);
            applyModalSkeleton(statsSpentEl, 88);
            applyModalSkeleton(statsRegisteredEl, 96);

            if (tagEl) {
                tagEl.classList.remove('hidden');
                tagEl.classList.add('modal-skeleton');
                tagEl.style.width = '84px';
                tagEl.style.height = '26px';
                tagEl.textContent = '\u00A0';
            }

            const reservaTagLoading = document.getElementById('modal-reserva-tag');
            if (reservaTagLoading) reservaTagLoading.classList.add('hidden');

            applyModalSkeleton(whatsappNumberEl, 160);
            applyModalSkeleton(bananosTextEl, 64);

            if (productsContainer) productsContainer.innerHTML = '';
            if (summaryContainer) summaryContainer.innerHTML = '';
            resetModalUserExtras();
            resetModalEditUi();

            if (requestDateEl) requestDateEl.textContent = '';
            if (requestTimeEl) requestTimeEl.textContent = '';
            if (requestElapsedEl) { requestElapsedEl.textContent = ''; requestElapsedEl.classList.add('hidden'); }
            if (notifiedDateEl) notifiedDateEl.textContent = '';
            if (notifiedTimeEl) notifiedTimeEl.textContent = '';
            if (notifiedElapsedEl) { notifiedElapsedEl.textContent = ''; notifiedElapsedEl.classList.add('hidden'); }
            if (paidDateEl) paidDateEl.textContent = '';
            if (paidTimeEl) paidTimeEl.textContent = '';
            if (paidElapsedEl) { paidElapsedEl.textContent = ''; paidElapsedEl.classList.add('hidden'); }
            if (completedDateEl) completedDateEl.textContent = '';
            if (completedTimeEl) completedTimeEl.textContent = '';
            if (completedElapsedEl) { completedElapsedEl.textContent = ''; completedElapsedEl.classList.add('hidden'); }

            setModalPendingStatus('');
        } else {
            clearModalSkeleton(orderIdEl);

            if (statusBadge) {
                statusBadge.classList.remove('modal-skeleton');
                statusBadge.style.width = '';
                statusBadge.style.height = '';
            }

            clearModalSkeleton(nameEl);
            clearModalSkeleton(statsCompletedEl);
            clearModalSkeleton(statsSpentEl);
            clearModalSkeleton(statsRegisteredEl);

            if (tagEl) {
                tagEl.classList.remove('modal-skeleton');
                tagEl.style.width = '';
                tagEl.style.height = '';
                if (tagEl.textContent === '\u00A0') tagEl.textContent = '';
            }

            clearModalSkeleton(whatsappNumberEl);
            clearModalSkeleton(bananosTextEl);
        }
    }

    function resetModalUserExtras() {
        const ordersEl = document.getElementById('modal-user-stats-orders');
        if (ordersEl) ordersEl.textContent = '';
        const verifRow = document.getElementById('modal-user-verification-row');
        if (verifRow) verifRow.classList.add('hidden');
        const histToggle = document.getElementById('modal-user-history-toggle');
        if (histToggle) { histToggle.disabled = true; histToggle.onclick = null; histToggle.classList.remove('text-zinc-300'); }
        const hist = document.getElementById('modal-user-history');
        if (hist) hist.classList.add('hidden');
        const histList = document.getElementById('modal-user-history-list');
        if (histList) histList.innerHTML = '';
        const proj = document.getElementById('modal-bananos-projection');
        if (proj) { proj.classList.add('hidden'); proj.innerHTML = ''; }
    }

    function resetModalEditUi() {
        modalEditState = null;
        const editBtn = document.getElementById('modal-products-edit-btn');
        if (editBtn) { editBtn.classList.add('hidden'); editBtn.onclick = null; }
        const bar = document.getElementById('modal-products-edit-bar');
        if (bar) bar.classList.add('hidden');
        const picker = document.getElementById('modal-products-picker');
        if (picker) picker.classList.add('hidden');
        const preview = document.getElementById('modal-edit-preview');
        if (preview) { preview.classList.add('hidden'); preview.innerHTML = ''; }
        const save = document.getElementById('modal-products-edit-save');
        if (save) save.disabled = true;
    }

    async function openOrderModal(orderId) {
        const contentWrapper = document.getElementById('modal-content-wrapper');

        // 1. Mostrar el modal y el indicador de carga
        openModal(orderModal);
        if (contentWrapper) contentWrapper.style.visibility = 'visible';
        setOrderModalSkeletonState(true);

        // 2. Llamar a la API para obtener los datos frescos
        const data = await apiGetOrderDetails(orderId);

        // 3. Si obtuvimos datos, rellenar el modal. Si no, cerramos el modal.
        if (data) {
            populateModalWithData(data);
        } else {
            closeModal(orderModal);
        }

        setOrderModalSkeletonState(false);
    }

    function openIpModal(ip) {
        document.getElementById('ip-modal-address').textContent = ip;
        document.getElementById('ip-modal-history').textContent = `Esta IP ha realizado ${Math.ceil(Math.random() * 5)} pedidos en los últimos 7 días.`;
        document.getElementById('block-ip-btn').onclick = () => {
            alert(`IP ${ip} bloqueada con éxito (simulado).`);
            closeModal(ipModal);
        };
        openModal(ipModal);
    }

    function confirmAction(newStatus, orderId) {
        if (!isConfirmModeEnabled()) {
            apiUpdateStatus(orderId, newStatus);
            return;
        }
        const order = allOrders.find(o => o.id == orderId);
        if(!order) return;

        const confirmTitle = document.getElementById('confirm-modal-title');
        const confirmMessage = document.getElementById('confirm-message');
        const confirmBtn = document.getElementById('confirm-action-button');

        confirmTitle.textContent = `Confirmar cambio a "${newStatus.replace('_',' ')}"`;
        confirmMessage.innerHTML = `¿Estás seguro de que deseas cambiar el estado del pedido <strong>${order.codigo_pedido}</strong> a <strong>${newStatus.toUpperCase().replace('_',' ')}</strong>?`;

        confirmCallback = () => apiUpdateStatus(orderId, newStatus);

        openModal(confirmModal);
    }

        function openAdvancedFilters() {
        // Cargar estado actual de los filtros en el modal

        // Categorías
        const container = document.getElementById('adv-filter-categories');
        container.innerHTML = '';
        allCategories.forEach(cat => {
            const isChecked = currentFilters.categories.includes(cat);
            container.innerHTML += `<div class="flex items-center"><input id="filter-cat-${cat}" type="checkbox" data-category="${cat}" ${isChecked ? 'checked' : ''} class="h-4 w-4 text-fortnite-blue rounded border-gray-300 dark:border-gray-600 focus:ring-fortnite-blue"><label for="filter-cat-${cat}" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">${cat}</label></div>`;
        });

        // Métodos de pago
        document.querySelectorAll('#adv-filter-payment input[type="checkbox"]').forEach(checkbox => {
            checkbox.checked = currentFilters.paymentMethods.includes(checkbox.dataset.payment);
        });

        // Fechas y Montos
        document.getElementById('filter-date-from').value = currentFilters.dateFrom;
        document.getElementById('filter-date-to').value = currentFilters.dateTo;
        document.getElementById('filter-amount-min').value = currentFilters.minAmount;
        document.getElementById('filter-amount-max').value = currentFilters.maxAmount;

        openModal(filtersModal);
    }

    function openModal(modal) { modal.classList.remove('hidden'); }
    function closeModal(modal) { modal.classList.add('hidden'); }

    function executeAction() {
        if (typeof confirmCallback === 'function') {
            confirmCallback();
        }
        closeModal(confirmModal);
        confirmCallback = null;
    }

    async function apiGetOrderDetails(orderId) {
        try {
            // Usamos la ruta relativa que sabemos que funciona
            const response = await fetch(`/wabo/pedidos/api/api_pedidos.php?action=get_order_details&order_id=${orderId}`);
            const result = await response.json();
            if (!response.ok || result.status !== 'success') {
                throw new Error(result.message || 'Error desconocido en la API');
            }
            return result; // Devuelve { status: 'success', order: {...}, products: [...] }
        } catch (error) {
            console.error('Error al obtener detalles del pedido:', error);
            alert('Error al cargar los detalles: ' + error.message);
            return null; // Devuelve null si hay un error
        }
    }

    async function checkForNewOrders() {
        if (pollingInFlight) return;
        pollingInFlight = true;
        const soundNewOrder = '/wabo/pedidos/notificaciones/notificacion.mp3';
        const soundUrgentOrder = '/wabo/pedidos/notificaciones/urgente.mp3';
        const latestId = allOrders.length > 0 ? allOrders.reduce((max, p) => p.id > max ? p.id : max, 0) : 0;

        try {
            const response = await fetch(`/wabo/pedidos/api/api_pedidos.php?action=check_for_new_orders&latest_id=${latestId}`);
            const result = await response.json();

            if (result.status === 'success') {
                let needsRender = false;
                let hasUrgent = false;
                let hasNormal = false;

                // 1. Procesar pedidos ACTUALIZADOS
                if (result.updated_orders && result.updated_orders.length > 0) {
                    result.updated_orders.forEach(updatedOrder => {
                        const existingIndex = allOrders.findIndex(order => order.id == updatedOrder.id);

                        if (existingIndex !== -1) {
                            // Si el pedido ya existe, lo reemplazamos con la versión actualizada
                            const enriched = enrichOrderData(updatedOrder);
                            enriched.isNew = true; // Lo marcamos como nuevo para el resaltado
                            allOrders[existingIndex] = enriched;
                            needsRender = true;
                            if (enriched.estado_pago_cliente === 'notificado_pendiente_revision') {
                                hasUrgent = true;
                            }
                        }
                    });
                }

                // 2. Procesar pedidos NUEVOS
                if (result.new_orders && result.new_orders.length > 0) {
                    const enrichedNewOrders = result.new_orders.map(order => {
                        const enriched = enrichOrderData(order);
                        enriched.isNew = true;
                        // Comprobamos si este nuevo pedido ya es urgente
                        if (enriched.estado_pago_cliente === 'notificado_pendiente_revision') {
                            hasUrgent = true;
                        } else {
                            hasNormal = true;
                        }
                        return enriched;
                    });
                    allOrders.unshift(...enrichedNewOrders);
                    needsRender = true;
                }

                // 3. Reproducir sonido y renderizar si hubo cambios
                if (needsRender) {
                    if (hasUrgent) {
                        playSound(soundUrgentOrder);
                    } else if (hasNormal || result.new_orders.length > 0) { // Si hay nuevos normales o solo actualizados
                        playSound(soundNewOrder);
                    }

                    renderPage();
                    console.log(`Nuevos: ${result.new_orders.length}, Actualizados: ${result.updated_orders.length}`);

                    // Quitar el resaltado después de un tiempo
                    setTimeout(() => {
                        allOrders.forEach(o => delete o.isNew);
                    }, 5000);
                }
            }
        } catch (error) {
            console.error("Error durante el polling:", error);
        } finally {
            pollingInFlight = false;
        }
    }

    const escapeHtmlText = (str) => String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const fmtSoles = (n) => `S/ ${new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n) || 0)}`;
    const fmtCoins = (n) => new Intl.NumberFormat('es-PE', { maximumFractionDigits: 0 }).format(Math.round(Number(n) || 0));

    /** Pedidos previos, % de verificación e insignia de proyección de Bananos (cabecera compacta). */
    function renderModalUserExtras(user, order, finance) {
        const ordersEl = document.getElementById('modal-user-stats-orders');
        const histToggle = document.getElementById('modal-user-history-toggle');
        const hist = document.getElementById('modal-user-history');
        const histList = document.getElementById('modal-user-history-list');
        const recent = Array.isArray(user.recent_orders) ? user.recent_orders : [];
        const prevCount = (user.orders_count_previous !== undefined && user.orders_count_previous !== null) ? Number(user.orders_count_previous) : recent.length;

        if (ordersEl) ordersEl.textContent = String(prevCount);
        if (histToggle && hist && histList) {
            const chevron = histToggle.querySelector('i');
            histToggle.disabled = recent.length === 0;
            if (chevron) chevron.style.transform = '';
            if (recent.length > 0) {
                histList.innerHTML = recent.map(h => {
                    const info = STATUS_CONFIG[h.estado] || STATUS_CONFIG.default;
                    const parsed = formatModalDateParts(h.fecha_creacion);
                    const items = Number(h.items_count) || 0;
                    const firstItem = String(h.first_item || '').trim();
                    const itemsLabel = items > 1 ? `${escapeHtmlText(firstItem)} +${items - 1}` : escapeHtmlText(firstItem);
                    return `
                        <button type="button" class="w-full flex items-center gap-3 px-2 py-1 rounded hover:bg-zinc-900/60 transition text-left" data-history-order-id="${Number(h.id)}" title="Abrir pedido">
                            <span class="font-mono text-[11px] text-zinc-300 shrink-0">${escapeHtmlText(h.codigo_pedido)}</span>
                            <span class="text-[11px] text-zinc-500 shrink-0">${escapeHtmlText(parsed.date)}</span>
                            <span class="min-w-0 flex-1 truncate text-zinc-400">${itemsLabel || '—'}</span>
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-${info.color}-400 shrink-0">${escapeHtmlText(info.text)}</span>
                            <span class="font-semibold text-zinc-100 tabular-nums shrink-0">${escapeHtmlText(fmtSoles(h.total_pagar))}</span>
                        </button>`;
                }).join('');
                histList.querySelectorAll('[data-history-order-id]').forEach(btn => {
                    btn.onclick = (e) => {
                        e.stopPropagation();
                        const id = Number(btn.getAttribute('data-history-order-id'));
                        if (id > 0) openOrderModal(id);
                    };
                });
                histToggle.onclick = (e) => {
                    e.stopPropagation();
                    const open = hist.classList.toggle('hidden');
                    if (chevron) chevron.style.transform = open ? '' : 'rotate(180deg)';
                };
            }
        }

        const verifRow = document.getElementById('modal-user-verification-row');
        if (verifRow) {
            const v = user.verification;
            if (v && typeof v === 'object') {
                const pct = Math.max(0, Math.min(100, Number(v.percent) || 0));
                const bar = document.getElementById('modal-user-verification-bar');
                const pctEl = document.getElementById('modal-user-verification-pct');
                const wrap = document.getElementById('modal-user-verification-wrap');
                if (bar) bar.style.width = `${pct}%`;
                if (pctEl) pctEl.textContent = `${pct}%`;
                if (wrap) wrap.title = `${fmtSoles(v.spent)} de ${fmtSoles(v.threshold)} en compras propias completadas`;
                verifRow.classList.remove('hidden');
            } else {
                verifRow.classList.add('hidden');
            }
        }

        const proj = document.getElementById('modal-bananos-projection');
        if (proj) {
            const estado = String(order.estado || '');
            const cbProjected = Number(finance.cashback_projected) || 0;
            const cbDelivered = Number(finance.cashback_delivered) || 0;
            const coinsOut = Number(finance.coins_outstanding) || 0;
            const coinsRedeemed = Number(finance.coins_redeemed) || 0;
            const isVoid = (estado === 'cancelado' || estado === 'reembolsado');
            const parts = [];
            if (estado === 'completado' && (cbDelivered > 0 || cbProjected > 0)) {
                parts.push({ icon: 'fa-minus', cls: 'text-red-300', text: `${fmtCoins(cbDelivered > 0 ? cbDelivered : cbProjected)} si cancela` });
            } else if ((estado === 'pendiente' || estado === 'pagado') && cbProjected > 0) {
                parts.push({ icon: 'fa-plus', cls: 'text-emerald-300', text: `${fmtCoins(cbProjected)} al completar` });
            }
            if (!isVoid && coinsOut > 0) {
                parts.push({ icon: 'fa-undo', cls: 'text-amber-300', text: `${fmtCoins(coinsOut)} si cancela` });
            } else if (isVoid && coinsRedeemed > 0 && coinsOut <= 0) {
                parts.push({ icon: 'fa-check', cls: 'text-zinc-400', text: `${fmtCoins(coinsRedeemed)} devueltos` });
            }
            if (parts.length) {
                proj.innerHTML = parts.map(p => `<span class="inline-flex items-center gap-1.5 ${p.cls}"><i class="fas ${p.icon} text-[10px]"></i>${escapeHtmlText(p.text)}</span>`).join('<span class="text-zinc-700">·</span>');
                proj.title = 'Proyección de Bananos: ' + parts.map(p => p.text).join(' · ');
                proj.classList.remove('hidden');
            } else {
                proj.classList.add('hidden');
                proj.innerHTML = '';
            }
        }
    }

    function populateModalWithData(data) {
        const order = data.order;

        // Sección, rank y expiración salen de detalles_pedido (congelados desde
        // el caché maestro vía el DAL en checkout). Sin caché ligero externo.
        const productos = data.products.map(p => {
            const expIso = dbDateToIsoUtc(p.fecha_expiracion_item);
            const sectionName = (p.section_name !== null && p.section_name !== undefined && String(p.section_name).trim() !== '')
                ? String(p.section_name).trim()
                : '-';
            const storeRank = (p.store_rank !== null && p.store_rank !== undefined && String(p.store_rank).trim() !== '')
                ? Number(p.store_rank)
                : 999;

            p.cacheData = {
                displayType: p.tipo_producto || 'Histórico',
                vbucks: p.v_bucks || 0,
                offerDates: expIso ? { out: expIso } : null,
                section_name: sectionName,
                store_rank: storeRank
            };
            return p;
        });

        // A partir de aquí, el resto del código es el que ya teníamos y funcionaba
        const statusInfo = STATUS_CONFIG[order.estado] || STATUS_CONFIG.default;
        
        let renderModalStatusBadge = null;
        
        const orderIdEl = document.getElementById('modal-order-id');
        if (orderIdEl) {
            const orderCode = String(order.codigo_pedido ?? '').trim();
            orderIdEl.textContent = orderCode;
            orderIdEl.onclick = (e) => {
                if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
                if (orderCode) copyToClipboard(orderCode, orderIdEl);
            };
        }

        const statusBadge = document.getElementById('modal-order-status');
        if (statusBadge) {
            const DOT_COLORS = {
                yellow: '#facc15',
                green: '#4ade80',
                blue: '#60a5fa',
                red: '#f87171',
                orange: '#fb923c',
                purple: '#c084fc',
                gray: '#a1a1aa'
            };
            const dotColor = DOT_COLORS[statusInfo.color] || DOT_COLORS.gray;
            statusBadge.innerHTML = `
                <span class="w-2 h-2 rounded-full" style="background:${dotColor}"></span>
                <span class="text-[11px] font-semibold tracking-[0.14em] uppercase text-slate-100">${statusInfo.text}</span>
            `;

            renderModalStatusBadge = (status) => {
                const nextInfo = STATUS_CONFIG[status] || STATUS_CONFIG.default;
                const nextDot = DOT_COLORS[nextInfo.color] || DOT_COLORS.gray;
                statusBadge.innerHTML = `
                    <span class="w-2 h-2 rounded-full" style="background:${nextDot}"></span>
                    <span class="text-[11px] font-semibold tracking-[0.14em] uppercase text-slate-100">${nextInfo.text}</span>
                `;
            };
        }

        const headerIpEl = document.getElementById('modal-header-ip');

        const statsCompletedEl = document.getElementById('modal-user-stats-completed');
        const statsSpentEl = document.getElementById('modal-user-stats-spent');
        const statsRegisteredEl = document.getElementById('modal-user-stats-registered');
        const tagEl = document.getElementById('modal-user-tag');
        const reservaTagEl = document.getElementById('modal-reserva-tag');
        if (reservaTagEl) {
            reservaTagEl.classList.toggle('hidden', !(order.es_reserva == 1));
        }

        const userDisplayNameEl = document.getElementById('modal-user-display-name');
        const banHammerEl = document.getElementById('modal-ban-hammer');
        const avatarImgEl = document.getElementById('modal-user-avatar-img');
        const avatarFallbackEl = document.getElementById('modal-user-avatar-fallback');

        const bananosBalanceTextEl = document.getElementById('modal-bananos-balance-text');

        const whatsappOpenEl = document.getElementById('modal-whatsapp-open');
        const whatsappNumberBoxEl = document.getElementById('modal-whatsapp-number-box');
        const whatsappNumberEl = document.getElementById('modal-whatsapp-number');

        const ipText = String(order.ip_cliente || '-').trim();
        if (headerIpEl) {
            const ipDisabled = (!ipText || ipText === '-');
            headerIpEl.disabled = ipDisabled;
            headerIpEl.classList.toggle('opacity-50', ipDisabled);
            headerIpEl.classList.toggle('cursor-not-allowed', ipDisabled);
            headerIpEl.onclick = (e) => {
                if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
                if (!ipDisabled) openIpModal(ipText);
            };
        }

        const user = data.user;
        if (user && user.id) {
            const displayName = String(user.public_display_name || user.game_username || user.email || user.public_code || String(user.id));
            if (userDisplayNameEl) userDisplayNameEl.textContent = displayName;

            if (banHammerEl) {
                banHammerEl.classList.remove('hidden');
                banHammerEl.onclick = (e) => {
                    e.stopPropagation();
                    confirmBanUser(user.id, user.game_username || user.email || user.public_code || String(user.id));
                };
            }

            if (avatarImgEl && avatarFallbackEl) {
                const filename = String(user.avatar_filename ?? '').trim();
                if (filename) {
                    avatarImgEl.src = `/assets/avatars/${filename}`;
                    avatarImgEl.classList.remove('hidden');
                    avatarFallbackEl.classList.add('hidden');
                } else {
                    avatarImgEl.src = '';
                    avatarImgEl.classList.add('hidden');
                    avatarFallbackEl.classList.remove('hidden');
                }
            }

            const isVerified = String(user.is_verified) === '1' || user.is_verified === 1;
            if (tagEl) {
                if (isVerified) {
                    tagEl.textContent = 'Verificado';
                    tagEl.classList.remove('hidden');
                    tagEl.classList.remove('border-slate-600', 'text-slate-200', 'bg-transparent');
                    tagEl.classList.add('border-emerald-500/40', 'text-emerald-300', 'bg-transparent');
                } else {
                    tagEl.textContent = 'Logueado';
                    tagEl.classList.remove('hidden');
                    tagEl.classList.remove('border-emerald-500/40', 'text-emerald-300', 'bg-transparent');
                    tagEl.classList.add('border-slate-600', 'text-slate-200', 'bg-transparent');
                }
            }

            const completed = (user.orders_count_completed_self !== null && user.orders_count_completed_self !== undefined)
                ? Number(user.orders_count_completed_self)
                : null;
            const spent = (user.total_spent_completed_self !== null && user.total_spent_completed_self !== undefined)
                ? Number(user.total_spent_completed_self)
                : null;

            let dateLabel = '—';
            let daysAgo = null;
            let createdDateObj = null;
            const createdAtRaw = String(user.created_at || '').trim();
            if (createdAtRaw) {
                const createdDate = new Date(createdAtRaw + ' UTC');
                if (!Number.isNaN(createdDate.getTime())) {
                    createdDateObj = createdDate;
                    dateLabel = new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', day: '2-digit', month: 'short', year: 'numeric' }).format(createdDate);
                    daysAgo = Math.max(0, Math.floor((Date.now() - createdDate.getTime()) / (1000 * 60 * 60 * 24)));
                }
            }

            const completedText = (completed !== null && !Number.isNaN(completed)) ? String(completed) : '—';
            const spentText = (spent !== null && !Number.isNaN(spent)) ? `S/ ${new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(spent)}` : '—';

            if (statsCompletedEl) statsCompletedEl.textContent = completedText;
            if (statsSpentEl) statsSpentEl.textContent = spentText;
            if (statsRegisteredEl) {
                if (daysAgo !== null && createdDateObj) {
                    const exactDateLabel = new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }).format(createdDateObj);
                    statsRegisteredEl.innerHTML = `${dateLabel} <span class="text-[11px] text-zinc-400 cursor-help" title="${exactDateLabel}">(${daysAgo} días)</span>`;
                } else {
                    statsRegisteredEl.textContent = dateLabel;
                }
            }

            const bal = (user.coins_balance !== null && user.coins_balance !== undefined) ? Number(user.coins_balance) : null;
            if (bananosBalanceTextEl) {
                bananosBalanceTextEl.textContent = (bal !== null && !Number.isNaN(bal)) ? `${String(Math.round(bal))}` : '—';
            }

            renderModalUserExtras(user, order, data.finance || {});
        } else {
            if (userDisplayNameEl) userDisplayNameEl.textContent = 'Invitado';

            if (tagEl) tagEl.classList.add('hidden');
            if (statsCompletedEl) statsCompletedEl.textContent = '';
            if (statsSpentEl) statsSpentEl.textContent = '';
            if (statsRegisteredEl) statsRegisteredEl.textContent = '';

            if (banHammerEl) {
                banHammerEl.classList.add('hidden');
                banHammerEl.onclick = null;
            }

            if (avatarImgEl && avatarFallbackEl) {
                avatarImgEl.src = '';
                avatarImgEl.classList.add('hidden');
                avatarFallbackEl.classList.remove('hidden');
            }
            if (bananosBalanceTextEl) bananosBalanceTextEl.textContent = '—';
        }

        const orderedRecipients = [...new Set(productos.map(p => p.nickname_destinatario || order.nickname_fortnite_cliente))].filter(Boolean);

        const rawContact = String(order.whatsapp_discord_cliente ?? '').trim();
        const contactDigits = rawContact.replace(/\D/g, '');
        const displayContact = rawContact ? rawContact : 'No provisto';

        if (whatsappNumberEl) whatsappNumberEl.textContent = displayContact;
        attachCopyBadge(whatsappNumberBoxEl, contactDigits || rawContact);

        if (whatsappOpenEl) {
            if (contactDigits) {
                whatsappOpenEl.classList.remove('opacity-50', 'cursor-not-allowed');
                whatsappOpenEl.onclick = (e) => {
                    if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
                    window.open(`https://wa.me/${contactDigits}`,'_blank');
                };
            } else {
                whatsappOpenEl.classList.add('opacity-50', 'cursor-not-allowed');
                whatsappOpenEl.onclick = (e) => {
                    if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
                };
            }
        }

        const productsContainer = document.getElementById('modal-products-container');
        const renderProducts = () => {
            if (!productsContainer) return;
            productsContainer.innerHTML = '';
            const edit = modalEditState;
            const editing = !!(edit && edit.active);

            const escapeHtml = (str) => String(str ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const entityDecoder = document.createElement('textarea');
            const decodeHtmlEntities = (str) => {
                entityDecoder.innerHTML = String(str ?? '');
                return entityDecoder.value;
            };

            const ensureItemImageLightbox = () => {
                let lb = document.getElementById('item-image-lightbox');
                if (!lb) {
                    document.body.insertAdjacentHTML('beforeend', `
                        <div id="item-image-lightbox" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/80 p-6">
                            <div class="relative w-full max-w-5xl">
                                <button id="item-image-lightbox-close" type="button" class="absolute -top-3 -right-3 w-10 h-10 rounded-full bg-zinc-950 border border-zinc-800 text-zinc-200 hover:bg-zinc-900" aria-label="Cerrar">×</button>
                                <div class="bg-zinc-950 border border-zinc-800 rounded-xl p-4">
                                    <img id="item-image-lightbox-img" src="" alt="" class="w-full max-h-[80vh] object-contain" />
                                </div>
                            </div>
                        </div>
                    `);
                    lb = document.getElementById('item-image-lightbox');
                }

                if (lb && !lb.dataset.bound) {
                    lb.dataset.bound = '1';
                    const closeBtn = document.getElementById('item-image-lightbox-close');
                    const imgEl = document.getElementById('item-image-lightbox-img');

                    const close = () => {
                        if (!lb) return;
                        lb.classList.add('hidden');
                        lb.classList.remove('flex');
                        if (imgEl) imgEl.src = '';
                    };

                    if (closeBtn) closeBtn.onclick = close;
                    lb.onclick = (e) => {
                        if (e && e.target === lb) close();
                    };

                    if (!document.body.dataset.itemImageLightboxEscBound) {
                        document.body.dataset.itemImageLightboxEscBound = '1';
                        document.addEventListener('keydown', (e) => {
                            if (e && e.key === 'Escape') close();
                        });
                    }
                }

                return lb;
            };

const openItemImageLightbox = (src, alt, bgStyle) => {
                const lb = ensureItemImageLightbox();
                const imgEl = document.getElementById('item-image-lightbox-img');
                if (!lb || !imgEl || !src) return;
                
                imgEl.src = src;
                imgEl.alt = alt || '';

                // --- NUEVO: APLICAR FONDO ---
                // Si recibimos un estilo (el gradiente), lo aplicamos a la imagen del modal
                if (bgStyle) {
                    imgEl.setAttribute('style', bgStyle);
                    // Ajustes extra para que se vea bonito en grande
                    imgEl.style.padding = '30px'; 
                    imgEl.style.borderRadius = '16px';
                    imgEl.style.boxSizing = 'border-box';
                } else {
                    // Si no hay estilo, limpiamos para que no quede el del anterior
                    imgEl.removeAttribute('style');
                }
                // -----------------------------

                lb.classList.remove('hidden');
                lb.classList.add('flex');
            };

            if (!productsContainer.dataset.itemLightboxBound) {
                productsContainer.dataset.itemLightboxBound = '1';
                productsContainer.addEventListener('click', (e) => {
                    const btn = e.target && e.target.closest ? e.target.closest('[data-item-image-src]') : null;
                    if (!btn) return;
                    
                    const src = String(btn.getAttribute('data-item-image-src') || '').trim();
                    if (!src) return;
                    
                    const alt = String(btn.getAttribute('data-item-image-alt') || '').trim();
                    
                    // --- NUEVO: CAPTURAR EL GRADIENTE ---
                    const style = btn.getAttribute('style') || '';
                    // ------------------------------------

                    openItemImageLightbox(src, alt, style);
                });
            }

            const recipientsForPanels = orderedRecipients.length ? [...orderedRecipients] : [order.nickname_fortnite_cliente || '—'];
            if (editing) {
                edit.adds.forEach(a => {
                    const r = a.recipient || order.nickname_fortnite_cliente || '—';
                    if (!recipientsForPanels.includes(r)) recipientsForPanels.push(r);
                });
            }
            recipientsForPanels.forEach(recipient => {
                const recipientProducts = productos.filter(p => (p.nickname_destinatario || order.nickname_fortnite_cliente || '—') === recipient);

                const rowsHtml = recipientProducts.map((p) => {
                    const nameRaw = decodeHtmlEntities(String(p.nombre_producto ?? '')).trim() || '—';
                    const detailId = String(p.detalle_id ?? '');
                    const removed = editing && edit.removeIds.has(detailId);
                    const rowEditCls = editing ? (removed ? ' opacity-40' : '') : '';
                    const nameEditCls = removed ? ' line-through' : '';
                    const removeBtn = (editing && detailId)
                        ? `<button type="button" class="modal-line-remove absolute top-1 right-1 h-5 w-5 rounded border bg-zinc-950/90 flex items-center justify-center text-[10px] transition ${removed ? 'border-amber-500/50 text-amber-300 hover:text-amber-200' : 'border-zinc-700 text-zinc-500 hover:text-red-300 hover:border-red-500/50'}" data-detail-id="${escapeHtml(detailId)}" title="${removed ? 'Deshacer' : 'Quitar esta unidad'}"><i class="fas ${removed ? 'fa-undo' : 'fa-times'}"></i></button>`
                        : '';

                    const category = String(p.categoria_producto ?? '').trim().toUpperCase();
                    const isEST = category === 'EST';
                    const isREG = category === 'REG';

const imageUrl = String(p.imagen_producto_url ?? '').trim();

                    // Corrección: Agregamos '#' solo si el dato viene de la BD. 
                    // Si no viene (es vacío), usamos el default que ya tiene '#'.
                    const rawC1 = String(p.color_1 ?? '').trim();
                    const c1 = rawC1 ? `#${rawC1}` : '#0b1220';

                    const rawC2 = String(p.color_2 ?? '').trim();
                    const c2 = rawC2 ? `#${rawC2}` : c1;

                    const rawC3 = String(p.color_3 ?? '').trim();
                    const c3 = rawC3 ? `#${rawC3}` : c2;

                    // Ahora c1, c2 y c3 siempre tendrán el formato correcto (#XXXXXX)
                    const gradientStyle = `background: linear-gradient(to bottom, ${escapeHtml(c1)}, ${escapeHtml(c2)}, ${escapeHtml(c3)});`;

                    const pavos = (p.v_bucks !== null && p.v_bucks !== undefined && String(p.v_bucks).trim() !== '')
                        ? Number(p.v_bucks)
                        : null;
                    const pavosText = (pavos !== null && !Number.isNaN(pavos)) ? String(Math.round(pavos)) : '—';

                    const qty = (p.cantidad !== null && p.cantidad !== undefined && String(p.cantidad).trim() !== '')
                        ? Number(p.cantidad)
                        : null;
                    const qtyInt = (qty !== null && !Number.isNaN(qty)) ? Math.max(0, Math.floor(qty)) : null;

                    const sectionTop = String(p.cacheData?.section_name ?? p.section_name ?? '').trim() || '—';

                    const typeBottom = String(p.tipo_producto ?? '').trim() || String(p.cacheData?.displayType ?? '').trim() || '—';

                    const rank = (p.cacheData?.store_rank !== null && p.cacheData?.store_rank !== undefined)
                        ? Number(p.cacheData.store_rank)
                        : ((p.store_rank !== null && p.store_rank !== undefined) ? Number(p.store_rank) : null);
                    const rankText = (rank !== null && !Number.isNaN(rank)) ? `#${rank}` : '—';

                    const expiresOut = p.cacheData?.offerDates?.out ? String(p.cacheData.offerDates.out).trim() : '';
                    let expHtml = '<div class="text-[13px] text-zinc-600">—</div>';
                    if (expiresOut) {
                        const expiresUtc = new Date(expiresOut);
                        if (!Number.isNaN(expiresUtc.getTime())) {

                            const LIMA_OFFSET_MS = 5 * 60 * 60 * 1000;
                            const expiresLimaMs = expiresUtc.getTime() - LIMA_OFFSET_MS;
                            const nowLimaMs = Date.now() - LIMA_OFFSET_MS;
                            const remainingMs = Math.max(0, (expiresLimaMs - nowLimaMs));
                            const hoursLeft = remainingMs / (1000 * 60 * 60);

                            const exactLima = new Intl.DateTimeFormat('es-PE', {
                                timeZone: 'America/Lima',
                                year: 'numeric',
                                month: '2-digit',
                                day: '2-digit',
                                hour: '2-digit',
                                minute: '2-digit',
                                hour12: true
                            }).format(expiresUtc);

                            const isUrgent = hoursLeft < 12;
                            const expClass = isUrgent ? 'text-red-400' : 'text-zinc-500';

                            if (hoursLeft >= 24) {
                                const d = Math.floor(hoursLeft / 24);
                                const h = Math.floor(hoursLeft % 24);
                                expHtml = `<div class="text-[13px] font-semibold ${expClass}" title="${escapeHtml(exactLima)}">${d}d ${h}h</div>`;
                            } else {
                                const h = Math.floor(hoursLeft);
                                const m = Math.floor((remainingMs - (h * 60 * 60 * 1000)) / (60 * 1000));
                                expHtml = `<div class="text-[13px] font-semibold ${expClass}" title="${escapeHtml(exactLima)}">${h}h ${m}m</div>`;
                            }
                        }
                    }

                    const base = (p.precio_base !== null && p.precio_base !== undefined && String(p.precio_base).trim() !== '')
                        ? Number(p.precio_base)
                        : null;
                    const final = (p.precio_final !== null && p.precio_final !== undefined && String(p.precio_final).trim() !== '')
                        ? Number(p.precio_final)
                        : null;
                    const baseOk = (base !== null && !Number.isNaN(base));
                    const finalOk = (final !== null && !Number.isNaN(final));
                    const baseText = baseOk ? `S/ ${new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(base)}` : '—';
                    const finalText = finalOk ? `S/ ${new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(final)}` : '—';
                    const showDiscount = (baseOk && finalOk && Math.abs(base - final) > 0.009);

                    const qtyBadge = (isEST && qtyInt !== null && qtyInt > 1)
                        ? `<span class="text-[12px] font-semibold text-zinc-400">x${qtyInt}</span>`
                        : '';
                    const priceLine = showDiscount
                        ? `<div class="flex items-baseline justify-center gap-2"><span class="text-[16px] font-bold text-emerald-300">${escapeHtml(finalText)}</span>${qtyBadge}</div><div class="text-[12px] text-zinc-500 line-through">${escapeHtml(baseText)}</div>`
                        : `<div class="flex items-baseline justify-center gap-2"><span class="text-[16px] font-bold text-zinc-100">${escapeHtml(finalText)}</span>${qtyBadge}</div>`;

                    const imgHtml = imageUrl
                        ? `<img src="${escapeHtml(imageUrl)}" class="relative w-full h-full max-h-[40px] object-contain transition duration-200 group-hover:brightness-110" alt="">`
                        : `<div class="relative w-full h-full"></div>`;

                    let imgBlock = '';
                    if (isREG) {
                        const regImgHtml = imageUrl
                            ? `<img src="${escapeHtml(imageUrl)}" class="relative w-11 h-11 object-contain transition duration-200 group-hover:brightness-110" alt="">`
                            : `<div class="relative w-11 h-11"></div>`;

                        const regBaseClasses = 'group w-14 h-14 rounded-md overflow-hidden border border-zinc-800 relative flex items-center justify-center';

                        imgBlock = imageUrl
                            ? `<button type="button" class="${regBaseClasses} cursor-zoom-in" data-item-image-src="${escapeHtml(imageUrl)}" data-item-image-alt="${escapeHtml(nameRaw)}" style="${gradientStyle}">${regImgHtml}</button>`
                            : `<div class="${regBaseClasses}" style="${gradientStyle}">${regImgHtml}</div>`;
                    } else {
                        imgBlock = imageUrl
                            ? `<button type="button" class="group w-full h-full rounded-md overflow-hidden border border-zinc-800 relative cursor-zoom-in" data-item-image-src="${escapeHtml(imageUrl)}" data-item-image-alt="${escapeHtml(nameRaw)}"><div class="absolute inset-0" style="${gradientStyle}"></div>${imgHtml}</button>`
                            : `<div class="group w-full h-full rounded-md overflow-hidden border border-zinc-800 relative"><div class="absolute inset-0" style="${gradientStyle}"></div>${imgHtml}</div>`;
                    }

                    if (isEST) {
                        return `
                            <div class="relative grid grid-cols-[minmax(56px,64px)_minmax(0,1.9fr)_minmax(0,0.84fr)_minmax(0,0.4fr)_minmax(0,0.35fr)_minmax(0,0.5fr)] items-stretch border-t border-zinc-800/70 hover:bg-zinc-900/30 transition divide-x divide-zinc-800/50${rowEditCls}">
                                <div class="p-2 flex items-stretch justify-center">
                                    ${imgBlock}
                                </div>
                                <div class="col-span-4 min-w-0 px-3 py-2 text-center flex flex-col justify-center">
                                    <div class="text-[14px] font-bold text-zinc-100 truncate${nameEditCls}" title="${escapeHtml(nameRaw)}">${escapeHtml(nameRaw)}</div>
                                </div>
                                <div class="px-3 py-2 flex flex-col items-center justify-center">${priceLine}</div>
                                ${removeBtn}
                            </div>
                        `;
                    }

                    return `
                        <div class="relative grid grid-cols-[minmax(56px,64px)_minmax(0,1.9fr)_minmax(0,0.84fr)_minmax(0,0.4fr)_minmax(0,0.35fr)_minmax(0,0.5fr)] items-stretch border-t border-zinc-800/70 hover:bg-zinc-900/30 transition divide-x divide-zinc-800/50${rowEditCls}">
                            <div class="p-2 flex items-stretch justify-center">
                                ${imgBlock}
                            </div>
                            <div class="min-w-0 px-3 py-2 text-center flex flex-col justify-center">
                                <div class="text-[14px] font-bold text-zinc-100 truncate${nameEditCls}" title="${escapeHtml(nameRaw)}">${escapeHtml(nameRaw)}</div>
                            </div>
                            <div class="min-w-0 px-3 py-2 text-center flex flex-col justify-center">
                                <div class="text-[13px] font-semibold text-zinc-200 truncate">${escapeHtml(sectionTop)}</div>
                                <div class="text-[13px] font-semibold text-zinc-500 truncate">${escapeHtml(typeBottom)}</div>
                            </div>
                            <div class="min-w-0 px-3 py-2 text-center flex flex-col justify-center">
                                <div class="text-[13px] font-semibold text-zinc-200">${escapeHtml(rankText)}</div>
                                ${expHtml}
                            </div>
                            <div class="px-3 py-2 text-[14px] font-semibold text-zinc-100 text-center flex items-center justify-center">${escapeHtml(pavosText)}</div>
                            <div class="px-3 py-2 flex flex-col items-center justify-center">${priceLine}</div>
                            ${removeBtn}
                        </div>
                    `;
                }).join('');

                const addsHtml = editing ? edit.adds
                    .filter(a => (a.recipient || order.nickname_fortnite_cliente || '—') === recipient)
                    .map(a => {
                        const img = a.image
                            ? `<img src="${escapeHtml(a.image)}" class="w-10 h-10 object-contain" alt="">`
                            : `<div class="w-10 h-10 rounded bg-zinc-900"></div>`;
                        const sub = [a.section, a.type].filter(Boolean).join(' · ');
                        const qtyBadge = a.quantity > 1 ? `<span class="text-[12px] font-semibold text-zinc-400">x${a.quantity}</span>` : '';
                        return `
                            <div class="relative grid grid-cols-[minmax(56px,64px)_minmax(0,1.9fr)_minmax(0,0.84fr)_minmax(0,0.4fr)_minmax(0,0.35fr)_minmax(0,0.5fr)] items-stretch border-t border-emerald-500/30 bg-emerald-500/[0.04] divide-x divide-zinc-800/50">
                                <div class="p-2 flex items-center justify-center">${img}</div>
                                <div class="min-w-0 px-3 py-2 text-center flex flex-col justify-center">
                                    <div class="text-[14px] font-bold text-zinc-100 truncate" title="${escapeHtml(a.name)}">${escapeHtml(a.name)}</div>
                                    <div class="text-[10px] font-bold tracking-[0.18em] uppercase text-emerald-400">Nuevo</div>
                                </div>
                                <div class="min-w-0 px-3 py-2 text-center flex flex-col justify-center"><div class="text-[13px] font-semibold text-zinc-400 truncate">${escapeHtml(sub || '—')}</div></div>
                                <div class="px-3 py-2 text-center flex items-center justify-center text-[13px] text-zinc-600">—</div>
                                <div class="px-3 py-2 text-[14px] font-semibold text-zinc-100 text-center flex items-center justify-center">${a.vbucks ? escapeHtml(String(a.vbucks)) : '—'}</div>
                                <div class="px-3 py-2 flex flex-col items-center justify-center"><div class="flex items-baseline justify-center gap-2"><span class="text-[16px] font-bold text-zinc-100">${escapeHtml(fmtSoles(a.price))}</span>${qtyBadge}</div></div>
                                <button type="button" class="modal-add-remove absolute top-1 right-1 h-5 w-5 rounded border border-zinc-700 bg-zinc-950/90 text-zinc-500 hover:text-red-300 hover:border-red-500/50 flex items-center justify-center text-[10px] transition" data-add-key="${escapeHtml(a.uid)}" title="Quitar una unidad"><i class="fas fa-times"></i></button>
                            </div>`;
                    }).join('') : '';

                if (!rowsHtml && !addsHtml) return;

                const recipientSafe = escapeHtml(recipient);
                const recipientCopyId = `recip_${Math.random().toString(36).slice(2)}`;

                const blockHtml = `
                    <div class="mb-6">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <div class="text-xs font-bold tracking-[0.22em] uppercase text-zinc-500">Para</div>

                            <button id="${recipientCopyId}" type="button" class="inline-flex items-center gap-2" data-recipient-copy="${recipientSafe}">
                                <span class="px-3 py-1 rounded-full text-xs font-bold tracking-wider uppercase bg-zinc-900 text-zinc-200 border border-zinc-800">${recipientSafe}</span>
                                <span class="recipient-copy-feedback hidden text-[11px] font-semibold text-emerald-300">Copied!</span>
                            </button>
                        </div>
                        <div class="overflow-x-hidden border border-zinc-800 rounded-lg">
                            <div class="w-full min-w-0">
                                <div class="grid grid-cols-[minmax(56px,64px)_minmax(0,1.9fr)_minmax(0,0.84fr)_minmax(0,0.4fr)_minmax(0,0.35fr)_minmax(0,0.5fr)] items-center bg-zinc-900/40 text-[11px] font-bold tracking-[0.22em] uppercase text-zinc-500 divide-x divide-zinc-800/50">
                                    <div class="px-3 py-2 text-center">Item</div>
                                    <div class="px-3 py-2 text-center">Nombre</div>
                                    <div class="px-3 py-2 text-center">Sección / Tipo</div>
                                    <div class="px-3 py-2 text-center">R/TIEM</div>
                                    <div class="px-3 py-2 text-center">paVos</div>
                                    <div class="px-3 py-2 text-center">Precio</div>
                                </div>

                                ${rowsHtml}${addsHtml}
                            </div>
                        </div>
                    </div>
                `;

                productsContainer.insertAdjacentHTML('beforeend', blockHtml);

                const recipientBtn = document.getElementById(recipientCopyId);
                if (recipientBtn) {
                    recipientBtn.onclick = (e) => {
                        if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
                        const txt = String(recipient ?? '').trim();
                        if (!txt) return;
                        navigator.clipboard.writeText(txt).then(() => {
                            const feedback = recipientBtn.querySelector('.recipient-copy-feedback');
                            if (feedback) {
                                feedback.classList.remove('hidden');
                                setTimeout(() => feedback.classList.add('hidden'), 950);
                            }
                        }).catch(() => {});
                    };
                }
            });

            if (editing) {
                productsContainer.querySelectorAll('.modal-line-remove').forEach(btn => {
                    btn.onclick = (e) => {
                        e.stopPropagation();
                        const id = String(btn.getAttribute('data-detail-id') || '');
                        if (!id) return;
                        if (edit.removeIds.has(id)) edit.removeIds.delete(id); else edit.removeIds.add(id);
                        edit.onChange();
                    };
                });
                productsContainer.querySelectorAll('.modal-add-remove').forEach(btn => {
                    btn.onclick = (e) => {
                        e.stopPropagation();
                        const key = String(btn.getAttribute('data-add-key') || '');
                        const idx = edit.adds.findIndex(a => a.uid === key);
                        if (idx === -1) return;
                        if (edit.adds[idx].quantity > 1) edit.adds[idx].quantity -= 1; else edit.adds.splice(idx, 1);
                        edit.onChange();
                    };
                });
            }
        };
        renderProducts();

        setupOrderEditMode({ order, productos, finance: data.finance || {}, recipients: orderedRecipients, renderProducts });

        const summaryContainer = document.getElementById('modal-summary-container');
        if (summaryContainer) {
            let summaryHtml = `
                <div class="bg-zinc-950 border border-zinc-800 rounded-lg p-4">
                    <div class="text-xs font-bold tracking-[0.22em] uppercase text-zinc-500 mb-4">Resumen</div>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-zinc-400">Subtotal</span><span class="text-zinc-100 font-semibold">S/ ${parseFloat(order.subtotal).toFixed(2)}</span></div>
            `;

            if (order.monto_descuento && parseFloat(order.monto_descuento) > 0) {
                summaryHtml += `
                        <div class="flex justify-between"><span class="text-zinc-400">Descuento total</span><span class="text-emerald-400 font-semibold">- S/ ${parseFloat(order.monto_descuento).toFixed(2)}</span></div>
                `;
                if (order.cupon_usado_codigo) {
                    summaryHtml += `
                        <div class="flex justify-between text-xs"><span class="text-zinc-500">Cupón</span><span class="text-zinc-200 font-semibold font-mono">${order.cupon_usado_codigo}</span></div>
                    `;
                }
            }

            if (order.coins_redeemed && parseFloat(order.coins_redeemed) > 0) {
                const coinsRedeemed = parseFloat(order.coins_redeemed);
                const coinsMoney = order.coins_value_money ? parseFloat(order.coins_value_money) : 0;
                summaryHtml += `
                        <div class="flex justify-between"><span class="text-zinc-400">Monedas usadas</span><span class="text-amber-300 font-semibold">${coinsRedeemed.toLocaleString()} (- S/ ${coinsMoney.toFixed(2)})</span></div>
                `;
            }

            summaryHtml += `
                        <div class="flex justify-between pt-2 mt-2 border-t border-zinc-800"><span class="text-zinc-200 font-bold">Total</span><span class="text-zinc-100 font-bold">S/ ${parseFloat(order.total_pagar).toFixed(2)}</span></div>
                    </div>
                </div>
            `;
            summaryContainer.innerHTML = summaryHtml;
        }

        const dateBlocks = [
            { key: 'request', value: order.fecha_creacion },
            { key: 'notified', value: order.fecha_notificacion_pago },
            { key: 'paid', value: order.fecha_pago },
            { key: 'completed', value: order.fecha_completado }
        ];

        dateBlocks.forEach(({ key, value }) => {
            const parts = formatModalDateParts(value);

            const dateEl = document.getElementById(`modal-${key}-date`);
            const timeEl = document.getElementById(`modal-${key}-time`);
            const elapsedEl = document.getElementById(`modal-${key}-elapsed`);

            if (dateEl) dateEl.textContent = parts.date;
            if (timeEl) {
                timeEl.textContent = parts.time;
                timeEl.classList.toggle('hidden', !parts.time);
            }
            if (elapsedEl) {
                elapsedEl.textContent = '';
                elapsedEl.classList.add('hidden');

                if (value) {
                    const dateObj = parseServerDateUTC(value);
                    const ageMs = Date.now() - dateObj.getTime();
                    const isRecent = Number.isFinite(ageMs) && ageMs >= 0 && ageMs < (24 * 60 * 60 * 1000);

                    if (isRecent) {
                        elapsedEl.textContent = formatElapsedShort(value);
                        elapsedEl.classList.remove('hidden');
                    }
                }
            }
        });

        setModalPendingStatus(order.estado);

        if (orderModal) {
            const statusButtons = orderModal.querySelectorAll('.modal-status-btn');
            statusButtons.forEach((btn) => {
                btn.onclick = async (e) => {
                    if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
                    const next = String(btn.dataset.status || '').trim();
                    if (!next) return;
                    setModalPendingStatus(next);

                    if (!isConfirmModeEnabled()) {
                        const ok = await apiUpdateStatus(order.id, next);
                        if (!ok) return;
                        order.estado = next;
                        setModalPendingStatus(next);
                        if (typeof renderModalStatusBadge === 'function') {
                            renderModalStatusBadge(next);
                        }
                    }
                };
            });
        }

        const confirmActionBtnModal = document.getElementById('confirm-action-btn-modal');
        if (confirmActionBtnModal) {
            confirmActionBtnModal.onclick = () => {
                const pending = (orderModal && orderModal.dataset.pendingStatus) ? orderModal.dataset.pendingStatus : '';
                const newStatus = pending || order.estado;
                closeModal(orderModal);
                confirmAction(newStatus, order.id);
            };
        }
    }

    // =====================================================================
    // === MODO EDICIÓN DE PRODUCTOS (lápiz junto a "Productos Solicitados") ===
    // =====================================================================
    async function fetchEditCatalog() {
        const FRESH_MS = 5 * 60 * 1000;
        if (editCatalogCache.data && (Date.now() - editCatalogCache.at) < FRESH_MS) return editCatalogCache.data;
        const response = await fetch('/wabo/pedidos/api/api_pedidos.php?action=get_edit_catalog');
        const result = await response.json();
        if (!response.ok || result.status !== 'success') throw new Error(result.message || 'No se pudo cargar el catálogo.');
        const shop = Array.isArray(result.data?.shop) ? result.data.shop : [];
        const stat = Array.isArray(result.data?.static) ? result.data.static : [];
        editCatalogCache = { at: Date.now(), data: [...shop, ...stat] };
        return editCatalogCache.data;
    }

    async function apiOrderEdit(action, payload) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('payload', JSON.stringify(payload));
        const response = await fetch('/wabo/pedidos/api/api_pedidos.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!response.ok || result.status !== 'success') throw new Error(result.message || 'Error desconocido en la API');
        return result;
    }

    function setupOrderEditMode({ order, productos, finance, recipients, renderProducts }) {
        const editBtn = document.getElementById('modal-products-edit-btn');
        const bar = document.getElementById('modal-products-edit-bar');
        const addBtn = document.getElementById('modal-products-add-btn');
        const cancelBtn = document.getElementById('modal-products-edit-cancel');
        const saveBtn = document.getElementById('modal-products-edit-save');
        const picker = document.getElementById('modal-products-picker');
        const pickerSearch = document.getElementById('modal-picker-search');
        const pickerResults = document.getElementById('modal-picker-results');
        const pickerRecipient = document.getElementById('modal-picker-recipient');
        const pickerClose = document.getElementById('modal-picker-close');
        const preview = document.getElementById('modal-edit-preview');
        if (!editBtn || !bar || !addBtn || !cancelBtn || !saveBtn || !picker || !pickerSearch || !pickerResults || !pickerRecipient || !pickerClose || !preview) return;

        const editable = !!finance.editable && productos.some(p => p.detalle_id);
        editBtn.classList.toggle('hidden', !editable);
        if (!editable) { editBtn.onclick = null; return; }

        const estado = String(order.estado || '');
        const ownerNick = String(order.nickname_fortnite_cliente || '').trim();
        const hasExplicitRecipients = productos.some(p => p.nickname_destinatario);
        const recipientOptions = [...new Set([
            ...(Array.isArray(recipients) ? recipients : []),
            ...productos.map(p => p.nickname_destinatario),
            ...(Array.isArray(order.destinatarios_regalo) ? order.destinatarios_regalo : []),
            ...(hasExplicitRecipients ? [] : [ownerNick])
        ].map(r => String(r ?? '').trim()).filter(Boolean))];
        let selectedRecipient = recipientOptions[0] || '';
        let pickerTab = 'all';
        let previewTimer = null;
        let previewSeq = 0;

        const state = {
            active: false,
            removeIds: new Set(),
            adds: [],
            busy: false,
            onChange: () => {}
        };
        modalEditState = state;

        const hasChanges = () => state.removeIds.size > 0 || state.adds.length > 0;

        const buildPayload = () => ({
            order_id: Number(order.id),
            remove_detail_ids: [...state.removeIds].map(Number),
            add_items: state.adds.map(a => ({ source: a.source, id: a.id, quantity: a.quantity, recipient: a.recipient }))
        });

        const renderPreview = (calc, error) => {
            if (!hasChanges()) {
                preview.classList.add('hidden');
                preview.innerHTML = '';
                saveBtn.disabled = true;
                return;
            }
            preview.classList.remove('hidden');
            if (error) {
                preview.innerHTML = `<div class="border border-red-500/40 bg-red-500/5 rounded-lg px-3 py-2 text-[12px] text-red-300"><i class="fas fa-exclamation-circle mr-1.5"></i>${escapeHtmlText(error)}</div>`;
                saveBtn.disabled = true;
                return;
            }
            if (!calc) {
                preview.innerHTML = `<div class="border border-zinc-800 rounded-lg px-3 py-2 text-[12px] text-zinc-500"><i class="fas fa-spinner fa-spin mr-1.5"></i>Calculando…</div>`;
                saveBtn.disabled = true;
                return;
            }

            const oldTotal = Number(calc.old?.total) || 0;
            const newTotal = Number(calc.new?.total) || 0;
            const delta = Number(calc.total_delta) || 0;
            const deltaCls = delta > 0 ? 'text-red-300' : (delta < 0 ? 'text-emerald-300' : 'text-zinc-400');
            const deltaText = `${delta > 0 ? '+' : (delta < 0 ? '−' : '±')} ${fmtSoles(Math.abs(delta))}`;

            const chips = [];
            chips.push(`<span class="inline-flex items-center gap-1.5"><span class="text-zinc-500">Total</span><span class="text-zinc-400 line-through">${escapeHtmlText(fmtSoles(oldTotal))}</span><i class="fas fa-arrow-right text-[9px] text-zinc-600"></i><span class="font-bold text-zinc-100">${escapeHtmlText(fmtSoles(newTotal))}</span><span class="${deltaCls}">(${escapeHtmlText(deltaText)})</span></span>`);

            const oldCb = Number(calc.old?.cashback) || 0;
            const newCb = Number(calc.new?.cashback) || 0;
            if (estado === 'completado') {
                const cbDelta = Number(calc.cashback_delta) || 0;
                const shortfall = Number(calc.cashback_shortfall) || 0;
                if (Math.abs(cbDelta) >= 0.5) {
                    const cls = cbDelta < 0 ? 'text-red-300' : 'text-emerald-300';
                    const verb = cbDelta < 0 ? 'se descontarán' : 'se abonarán';
                    chips.push(`<span class="inline-flex items-center gap-1.5 ${cls}"><i class="fas ${cbDelta < 0 ? 'fa-minus' : 'fa-plus'} text-[9px]"></i>${escapeHtmlText(`${verb} ${fmtCoins(Math.abs(cbDelta))} Bananos de cashback`)}</span>`);
                    if (shortfall > 0) {
                        chips.push(`<span class="inline-flex items-center gap-1.5 text-amber-300"><i class="fas fa-exclamation-triangle text-[9px]"></i>${escapeHtmlText(`saldo insuficiente: quedará en 0 (faltan ${fmtCoins(shortfall)})`)}</span>`);
                    }
                } else {
                    chips.push(`<span class="text-zinc-500">Cashback sin cambios</span>`);
                }
            } else {
                chips.push(`<span class="inline-flex items-center gap-1.5"><span class="text-zinc-500">Bananos al completar</span><span class="text-zinc-400 line-through">${fmtCoins(oldCb)}</span><i class="fas fa-arrow-right text-[9px] text-zinc-600"></i><span class="font-bold text-emerald-300">${fmtCoins(newCb)}</span></span>`);
            }

            const coinsRefund = Number(calc.coins_excess_refund) || 0;
            if (coinsRefund > 0) {
                chips.push(`<span class="inline-flex items-center gap-1.5 text-amber-300"><i class="fas fa-undo text-[9px]"></i>${escapeHtmlText(`se devolverán ${fmtCoins(coinsRefund)} Bananos que ya no cubren nada`)}</span>`);
            }
            const coupon = Number(calc.new?.coupon_discount) || 0;
            if (order.cupon_usado_codigo && coupon > 0) {
                chips.push(`<span class="text-zinc-500">Cupón <span class="font-mono text-zinc-300">${escapeHtmlText(order.cupon_usado_codigo)}</span> → −${escapeHtmlText(fmtSoles(coupon))}</span>`);
            }

            preview.innerHTML = `<div class="border border-cyan-500/30 bg-cyan-500/[0.04] rounded-lg px-3 py-2 text-[12px] flex flex-wrap items-center gap-x-5 gap-y-1">${chips.join('')}</div>`;
            saveBtn.disabled = false;
        };

        const schedulePreview = () => {
            if (previewTimer) clearTimeout(previewTimer);
            if (!hasChanges()) { renderPreview(null, null); return; }
            renderPreview(null, null);
            const seq = ++previewSeq;
            previewTimer = setTimeout(async () => {
                try {
                    const result = await apiOrderEdit('preview_order_edit', buildPayload());
                    if (seq !== previewSeq) return;
                    renderPreview(result.preview, null);
                } catch (err) {
                    if (seq !== previewSeq) return;
                    renderPreview(null, err.message || 'No se pudo calcular el preview.');
                }
            }, 300);
        };

        state.onChange = () => {
            renderProducts();
            schedulePreview();
        };

        const setEditing = (on) => {
            state.active = on;
            state.removeIds.clear();
            state.adds = [];
            editBtn.classList.toggle('hidden', on);
            bar.classList.toggle('hidden', !on);
            if (!on) {
                picker.classList.add('hidden');
                preview.classList.add('hidden');
                preview.innerHTML = '';
                saveBtn.disabled = true;
            }
            renderProducts();
        };

        const renderPickerResults = (catalog) => {
            const q = String(pickerSearch.value || '').trim().toLowerCase();
            const list = catalog.filter(item => {
                if (pickerTab !== 'all' && item.source !== pickerTab) return false;
                if (!q) return true;
                return [item.name, item.type, item.section].some(v => String(v || '').toLowerCase().includes(q));
            }).slice(0, 80);

            if (!list.length) {
                pickerResults.innerHTML = `<div class="px-3 py-4 text-center text-[12px] text-zinc-500">Sin resultados</div>`;
                return;
            }
            pickerResults.innerHTML = list.map(item => {
                const key = `${item.source}:${item.id}`;
                const inCart = state.adds.find(a => a.key === key);
                const sub = [item.section, item.type, item.vbucks ? `${item.vbucks} paVos` : ''].filter(Boolean).join(' · ');
                const hasOffer = Number(item.price_base) > Number(item.price) + 0.009;
                const priceHtml = hasOffer
                    ? `<span class="font-bold text-emerald-300">${escapeHtmlText(fmtSoles(item.price))}</span> <span class="text-[11px] text-zinc-500 line-through">${escapeHtmlText(fmtSoles(item.price_base))}</span>`
                    : `<span class="font-bold text-zinc-100">${escapeHtmlText(fmtSoles(item.price))}</span>`;
                const img = item.image ? `<img src="${escapeHtmlText(item.image)}" class="w-8 h-8 object-contain rounded" alt="" loading="lazy">` : `<div class="w-8 h-8 rounded bg-zinc-900"></div>`;
                return `
                    <button type="button" class="picker-item w-full flex items-center gap-3 px-3 py-1.5 hover:bg-zinc-900/60 transition text-left" data-picker-key="${escapeHtmlText(key)}">
                        ${img}
                        <div class="min-w-0 flex-1">
                            <div class="text-[13px] font-semibold text-zinc-100 truncate">${escapeHtmlText(item.name)}</div>
                            <div class="text-[11px] text-zinc-500 truncate">${escapeHtmlText(sub || (item.source === 'static' ? 'Estático' : 'Tienda'))}</div>
                        </div>
                        <div class="text-[13px] text-right shrink-0">${priceHtml}</div>
                        <span class="shrink-0 h-6 w-6 rounded border ${inCart ? 'border-emerald-500/60 text-emerald-300' : 'border-zinc-700 text-zinc-400'} flex items-center justify-center text-[10px]">${inCart ? `x${inCart.quantity}` : '<i class="fas fa-plus"></i>'}</span>
                    </button>`;
            }).join('');

            pickerResults.querySelectorAll('.picker-item').forEach(btn => {
                btn.onclick = (e) => {
                    e.stopPropagation();
                    const key = String(btn.getAttribute('data-picker-key') || '');
                    const item = catalog.find(c => `${c.source}:${c.id}` === key);
                    if (!item) return;
                    const recipient = (selectedRecipient && (selectedRecipient !== ownerNick || hasExplicitRecipients)) ? selectedRecipient : '';
                    const existing = state.adds.find(a => a.key === key && a.recipient === recipient);
                    if (existing) {
                        if (existing.quantity >= 10) { showAdminToast('Máximo 10 unidades por producto.'); return; }
                        existing.quantity += 1;
                    } else {
                        state.adds.push({
                            key, uid: `${key}|${recipient}`, source: item.source, id: String(item.id), name: item.name, image: item.image || '',
                            type: item.type || '', section: item.section || '', vbucks: Number(item.vbucks) || 0,
                            price: Number(item.price) || 0, price_base: Number(item.price_base) || 0, quantity: 1, recipient
                        });
                    }
                    state.onChange();
                    renderPickerResults(catalog);
                };
            });
        };

        const renderRecipientChips = () => {
            pickerRecipient.querySelectorAll('.picker-recipient').forEach(el => el.remove());
            if (recipientOptions.length <= 1) { pickerRecipient.classList.add('hidden'); return; }
            if (!recipientOptions.includes(selectedRecipient)) selectedRecipient = recipientOptions[0];
            recipientOptions.forEach((r, i) => {
                const active = r === selectedRecipient;
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = `picker-recipient px-2 py-0.5 rounded border transition truncate max-w-[160px] ${active ? 'border-cyan-500/60 bg-cyan-500/10 text-cyan-200' : 'border-zinc-700 text-zinc-400 hover:text-zinc-200 hover:border-zinc-500'}`;
                chip.title = `Usuario ${i + 1}: ${r}`;
                chip.textContent = r;
                chip.onclick = (e) => { e.stopPropagation(); selectedRecipient = r; renderRecipientChips(); };
                pickerRecipient.appendChild(chip);
            });
            pickerRecipient.classList.remove('hidden');
        };

        const openPicker = async () => {
            picker.classList.remove('hidden');
            pickerResults.innerHTML = `<div class="px-3 py-4 text-center text-[12px] text-zinc-500"><i class="fas fa-spinner fa-spin mr-1.5"></i>Cargando catálogo…</div>`;
            renderRecipientChips();
            try {
                const catalog = await fetchEditCatalog();
                renderPickerResults(catalog);
                pickerSearch.oninput = () => renderPickerResults(catalog);
                picker.querySelectorAll('.picker-tab').forEach(tab => {
                    tab.onclick = (e) => {
                        e.stopPropagation();
                        pickerTab = String(tab.getAttribute('data-picker-tab') || 'all');
                        picker.querySelectorAll('.picker-tab').forEach(t => {
                            const on = t === tab;
                            t.classList.toggle('border-zinc-700', on);
                            t.classList.toggle('text-zinc-100', on);
                            t.classList.toggle('border-transparent', !on);
                            t.classList.toggle('text-zinc-500', !on);
                        });
                        renderPickerResults(catalog);
                    };
                });
                pickerSearch.focus();
            } catch (err) {
                pickerResults.innerHTML = `<div class="px-3 py-4 text-center text-[12px] text-red-300">${escapeHtmlText(err.message || 'Error al cargar el catálogo.')}</div>`;
            }
        };

        editBtn.onclick = (e) => { e.stopPropagation(); setEditing(true); };
        cancelBtn.onclick = (e) => { e.stopPropagation(); setEditing(false); };
        addBtn.onclick = (e) => { e.stopPropagation(); if (picker.classList.contains('hidden')) openPicker(); else picker.classList.add('hidden'); };
        pickerClose.onclick = (e) => { e.stopPropagation(); picker.classList.add('hidden'); };

        saveBtn.onclick = async (e) => {
            e.stopPropagation();
            if (state.busy || !hasChanges()) return;
            const removed = state.removeIds.size;
            const added = state.adds.reduce((n, a) => n + a.quantity, 0);
            const summary = [removed ? `quitar ${removed} unidad(es)` : '', added ? `añadir ${added} unidad(es)` : ''].filter(Boolean).join(' y ');
            const extra = estado === 'completado' ? ' El cashback del cliente se ajustará en su saldo.' : '';
            if (!window.confirm(`¿Aplicar cambios al pedido ${order.codigo_pedido}: ${summary}?${extra}`)) return;

            state.busy = true;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            try {
                const result = await apiOrderEdit('apply_order_edit', buildPayload());
                if (result.order) {
                    const idx = allOrders.findIndex(o => o.id == order.id);
                    if (idx !== -1) allOrders[idx] = { ...allOrders[idx], ...result.order };
                    renderPage();
                }
                showAdminToast(result.message || 'Pedido actualizado.');
                await openOrderModal(order.id);
            } catch (err) {
                alert('No se pudo aplicar la edición: ' + (err.message || 'error desconocido'));
                saveBtn.disabled = false;
            } finally {
                state.busy = false;
                saveBtn.innerHTML = 'Guardar';
            }
        };
    }

    async function apiUpdateStatus(orderId, newStatus) {
        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('order_id', orderId);
        formData.append('new_status', newStatus);

        try {
            const response = await fetch('/wabo/pedidos/api/api_pedidos.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (!response.ok || result.status !== 'success') {
                throw new Error(result.message || 'Error desconocido en la API');
            }

            const updatedOrder = result.order || null;

            const orderIndex = allOrders.findIndex(order => order.id == orderId);
            if (orderIndex !== -1) {
                if (updatedOrder) {
                    allOrders[orderIndex] = { ...allOrders[orderIndex], ...updatedOrder };
                } else {
                    allOrders[orderIndex].estado = newStatus;
                }
            }

            renderPage();
            return true;
        } catch (error) {
            console.error('Error al actualizar estado:', error);

            alert('Error al actualizar: ' + error.message);
            return false;
        }
    }

    function confirmBanUser(userId, label) {
        const reason = prompt('Motivo del baneo (opcional):', '');
        if (reason === null) return;

        const confirmTitle = document.getElementById('confirm-modal-title');
        const confirmMessage = document.getElementById('confirm-message');

        confirmTitle.textContent = 'Confirmar baneo';
        confirmMessage.innerHTML = `¿Estás seguro de que deseas banear al usuario <strong>${label}</strong>?`;

        confirmCallback = () => apiBanUser(userId, reason);
        openModal(confirmModal);
    }

    async function apiBanUser(userId, reason) {
        const formData = new FormData();
        formData.append('action', 'ban_user');
        formData.append('user_id', userId);
        formData.append('reason', reason);

        try {
            const response = await fetch('/wabo/pedidos/api/api_pedidos.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!response.ok || result.status !== 'success') {
                throw new Error(result.message || 'Error desconocido en la API');
            }
            alert(result.message || 'Usuario baneado.');
        } catch (error) {
            console.error('Error al banear usuario:', error);
            alert('Error al banear: ' + error.message);
        }
    }

    // --- NUEVAS FUNCIONES DE CONFIRMACIÓN Y API ---

    function confirmDismiss(orderId) {
        const order = allOrders.find(o => o.id == orderId);
        if (!order) return;

        const confirmTitle = document.getElementById('confirm-modal-title');
        const confirmMessage = document.getElementById('confirm-message');

        confirmTitle.textContent = `Descartar Notificación`;
        confirmMessage.innerHTML = `¿Estás seguro de que quieres descartar la notificación de pago para el pedido <strong>${order.codigo_pedido}</strong>? El pedido seguirá pendiente, pero dejará de sonar la alerta.`;

        confirmCallback = () => apiDismissNotification(orderId);
        openModal(confirmModal);
    }

    function confirmDelete(orderId) {
        const order = allOrders.find(o => o.id == orderId);
        if (!order) return;

        const confirmTitle = document.getElementById('confirm-modal-title');
        const confirmMessage = document.getElementById('confirm-message');

        confirmTitle.textContent = `¡ACCIÓN IRREVERSIBLE!`;
        confirmMessage.innerHTML = `Estás a punto de <strong>ELIMINAR PERMANENTEMENTE</strong> el pedido <strong>${order.codigo_pedido}</strong> y todos sus datos asociados.<br><br>Esta acción no se puede deshacer. ¿Estás completamente seguro?`;

        confirmCallback = () => apiHardDelete(orderId);
        openModal(confirmModal);
    }

    async function apiDismissNotification(orderId) {
        const formData = new FormData();
        formData.append('action', 'dismiss_notification');
        formData.append('order_id', orderId);

        try {
            const response = await fetch('/wabo/pedidos/api/api_pedidos.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (!response.ok || result.status !== 'success') {
                throw new Error(result.message || 'Error en la API');
            }

            const updatedOrder = result.order || null;

            // Actualizar el estado localmente para reflejar el cambio al instante
            const orderIndex = allOrders.findIndex(order => order.id == orderId);
            if (orderIndex !== -1) {
                if (updatedOrder) {
                    allOrders[orderIndex] = { ...allOrders[orderIndex], ...updatedOrder };
                } else {
                    allOrders[orderIndex].estado_pago_cliente = 'no_notificado';
                }
            }
            renderPage(); // Redibujar la tabla

        } catch (error) {
            console.error('Error al descartar notificación:', error);

            alert('Error: ' + error.message);
        }
    }

    async function apiHardDelete(orderId) {
        const formData = new FormData();
        formData.append('action', 'hard_delete_order');
        formData.append('order_id', orderId);

        try {
            const response = await fetch('/wabo/pedidos/api/api_pedidos.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (!response.ok || result.status !== 'success') {
                throw new Error(result.message || 'Error en la API');
            }

            // Eliminar el pedido del array local para que desaparezca de la vista
            allOrders = allOrders.filter(order => order.id != orderId);
            renderPage(); // Redibujar la tabla

        } catch (error) {
            console.error('Error al eliminar pedido:', error);
            alert('Error: ' + error.message);
        }
    }
    </script>

<?php include __DIR__ . '/../core/admin_footer.php'; ?>
<?php
// Archivo: gestionar_productos.php (v7.3 - SOLUCIÓN FINAL de Timezones)
require_once __DIR__ . '/../core/admin_header.php';
require_once __DIR__ . '/../core/db_connect.php';

// --- Lógica PHP para obtener datos iniciales (sin cambios) ---
$todos_los_productos = [];
$resultado = $conn->query("SELECT * FROM productos_estaticos ORDER BY orden_categoria, categoria, orden, id");
if ($resultado) {
    while ($fila = $resultado->fetch_assoc()) {
$defaults = [
            'id' => 0, 'nombre' => '', 'precio_base' => 0.0, 'precio_oferta' => null, 'tipo_oferta' => 'precio_fijo',
            'oferta_fecha_expiracion' => null, 'imagen_url' => null, 'categoria' => 'Sin Categoría', 'orden' => 99,
            'orden_categoria' => 99, 'activo' => false, 'fecha_expiracion' => null, 'etiqueta_texto' => 'OFERTA',
            'etiqueta_color_fondo' => '#22c55e', 'etiqueta_color_texto' => '#ffffff', 'precio_oferta_color' => '#22c55e',
            'timer_color_fondo' => '#18181b', 'timer_color_texto' => '#ffffff', 'descripcion' => '', 'stock_cantidad' => -1,
            'urgencia_horas' => 24, 'etiqueta_posicion' => 1,
            'monedas_otorgadas' => 0,
            'permitir_canje_monedas' => 1,
            'requiere_nickname' => 0,
            'dar_cashback' => 1,
            'activar_tutorial' => 0,
            'mensaje_tutorial' => '',
            'modo_reserva' => 0,
            'mensaje_reserva' => ''
        ];
        $todos_los_productos[] = array_merge($defaults, array_filter($fila, fn($value) => $value !== null));
    }
}

$todas_las_categorias = [];
$resultado_cat = $conn->query("SELECT DISTINCT categoria, orden_categoria FROM productos_estaticos ORDER BY orden_categoria, categoria ASC");
if ($resultado_cat) {
    while ($fila_cat = $resultado_cat->fetch_assoc()) {
        $todas_las_categorias[] = $fila_cat['categoria'];
    }
}

$conn->close();
$productos_json = json_encode($todos_los_productos, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
$categorias_json = json_encode($todas_las_categorias, JSON_UNESCAPED_UNICODE);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<!-- =============================================================== -->
<!-- === CSS: CORRECCIONES DE TEMA Y ESTILOS MEJORADOS === -->
<!-- =============================================================== -->
<style>
    .tabla-productos th,.tabla-productos td{vertical-align:middle;white-space:nowrap}.tabla-productos thead th{position:sticky;top:0;z-index:10}[data-theme=light] .tabla-productos thead th{background-color:#f8fafc}[data-theme=dark] .tabla-productos thead th{background-color:#0a0a0a}.modal-section-title{font-weight:600;margin-top:1.5rem;margin-bottom:.75rem;border-bottom:1px solid var(--border-color);padding-bottom:.5rem;color:var(--text-primary)}.color-picker-wrapper{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.25rem 0}.color-picker-wrapper input[type=color]{min-width:2.5rem;height:2.5rem;padding:.25rem;border:none;border-radius:.375rem;cursor:pointer;background-color:transparent}.color-picker-wrapper input[type=color]::-webkit-color-swatch-wrapper{padding:0}.color-picker-wrapper input[type=color]::-webkit-color-swatch{border:1px solid var(--border-color);border-radius:.25rem}

    .col-id{width:4%}.col-info{width:26%}.col-precios{width:15%}.col-stock{width:10%}.col-estado{width:8%}.col-expiracion{width:15%}.col-detalles{width:10%}.col-acciones{width:12%;text-align:right}
    #productModal, #confirmModal {z-index:1040}
    .tox-tinymce{border-radius:.5rem !important;}
    .stock-badge{padding:.25rem .5rem;font-size:.75rem;font-weight:500;border-radius:9999px;}
    .stock-infinite{background-color:rgba(59,130,246,0.1);color:rgba(37,99,235,1)}[data-theme=dark] .stock-infinite{background-color:rgba(59,130,246,0.2);color:rgba(96,165,250,1)}
    .stock-low{background-color:rgba(245,158,11,0.1);color:rgba(217,119,6,1)}[data-theme=dark] .stock-low{background-color:rgba(245,158,11,0.2);color:rgba(251,191,36,1)}
    .stock-ok{background-color:rgba(34,197,94,0.1);color:rgba(22,163,74,1)}[data-theme=dark] .stock-ok{background-color:rgba(34,197,94,0.2);color:rgba(74,222,128,1)}
    .stock-out{background-color:rgba(239,68,68,0.1);color:rgba(220,38,38,1)}[data-theme=dark] .stock-out{background-color:rgba(239,68,68,0.2);color:rgba(248,113,113,1)}

    /* === SOLUCIÓN PROBLEMA 1: Estilos para legibilidad en inputs === */
    select, .flatpickr-calendar, .flatpickr-time {
        background-color: var(--secondary) !important;
        border-color: var(--border-color) !important;
        color: var(--text-primary) !important;
    }
    select option {
        background-color: var(--primary);
        color: var(--text-primary);
    }
    [data-theme="dark"] input[type=text],
    [data-theme="dark"] input[type=number],
    [data-theme="dark"] input[type=url],
    [data-theme="dark"] textarea {
        background-color: #0a0a0a; /* Un gris oscuro del tema */
        border-color: var(--border-color);
        color: var(--text-primary);
    }
    /* --- FIN SOLUCIÓN PROBLEMA 1 --- */

    #confirmModal .card { background-color: var(--secondary); }
    #confirmModal .cancel-btn { 
        background-color: var(--primary); 
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }
    #confirmModal .cancel-btn:hover { background-color: var(--secondary-dark); }
</style>

<!-- HTML de la página (sin cambios funcionales) -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-8 gap-4"><h2 class="text-2xl font-bold">Gestión de Productos</h2><div class="flex gap-2 flex-wrap"><button onclick="confirmStockAction(0)" class="px-3 py-2 text-sm rounded-lg flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white"><i class="fas fa-store-slash"></i> Agotar Todo</button><button onclick="confirmStockAction(-1)" class="px-3 py-2 text-sm rounded-lg flex items-center gap-2" style="background-color:var(--primary);border:1px solid var(--border-color);"><i class="fas fa-infinity"></i> Stock Infinito Todo</button><button onclick="openModal('add')" class="px-4 py-2 text-sm text-white rounded-lg flex items-center gap-2" style="background-color:var(--accent);"><i class="fas fa-plus"></i> Añadir Producto</button></div></div>
<div class="card rounded-lg shadow p-4 mb-8"><div class="grid grid-cols-1 md:grid-cols-2 gap-4"><input type="text" id="searchInput" placeholder="Buscar productos..." class="w-full border rounded-lg py-2 px-3"><select id="categoryFilter" class="w-full border rounded-lg py-2 px-3"><option value="all">Todas las categorías</option></select></div></div>
<div id="product-tables-container"></div>
<div id="productModal" class="fixed inset-0 bg-black bg-opacity-70 hidden items-center justify-center"><div class="card rounded-lg shadow-xl max-w-4xl w-full mx-4 flex flex-col max-h-[95vh]"><div class="flex justify-between items-center border-b px-6 py-4 flex-shrink-0"><h3 id="modalTitle" class="text-lg font-semibold"></h3><button onclick="closeModal()" class="text-2xl hover:text-red-500">×</button></div><div class="p-6 overflow-y-auto"><form id="productForm" class="grid grid-cols-1 md:grid-cols-3 gap-x-8"><input type="hidden" id="productId" name="id"><div class="md:col-span-2 space-y-4"><h4 class="modal-section-title">Información Principal</h4><div><label for="productName" class="block text-sm font-medium mb-1">Nombre</label><input type="text" id="productName" name="nombre" class="w-full border rounded-lg py-2 px-3" required></div><div><label for="productDescription" class="block text-sm font-medium mb-1">Descripción</label><textarea id="productDescription" name="descripcion"></textarea></div><div class="grid grid-cols-2 gap-4"><div><label for="productPrice" class="block text-sm font-medium mb-1">Precio Base (S/)</label><input type="number" id="productPrice" name="precio_base" class="w-full border rounded-lg py-2 px-3" required></div> <!-- CAMPO NUEVO: MONEDAS -->
<div>
    <label for="productCoins" class="block text-sm font-medium mb-1 text-yellow-600 dark:text-yellow-400">
        <i class="fas fa-coins"></i> Monedas a Otorgar
    </label>
    <input type="number" id="productCoins" name="monedas_otorgadas" class="w-full border rounded-lg py-2 px-3 bg-yellow-50 dark:bg-yellow-900/10 border-yellow-200 dark:border-yellow-700" min="0" value="0">
    <p class="text-[10px] text-gray-500 mt-1">Si es mayor a 0, se considera Pack de Recarga.</p>
</div><!-- BLOQUE DE REGLAS DE NEGOCIO -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3 bg-gray-50 dark:bg-gray-700/30 p-3 rounded-lg border border-gray-200 dark:border-gray-700">
    <label class="flex items-center space-x-2 cursor-pointer">
        <input type="checkbox" id="checkCanje" name="permitir_canje_monedas" value="1" class="w-4 h-4 text-green-600 rounded focus:ring-green-500">
        <span class="text-xs font-medium">Permitir pago con Monedas</span>
    </label>
    <label class="flex items-center space-x-2 cursor-pointer">
        <input type="checkbox" id="checkCashback" name="dar_cashback" value="1" class="w-4 h-4 text-yellow-500 rounded focus:ring-yellow-500">
        <span class="text-xs font-medium">Genera Cashback (1%)</span>
    </label>
    <label class="flex items-center space-x-2 cursor-pointer">
        <input type="checkbox" id="checkNick" name="requiere_nickname" value="1" class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
        <span class="text-xs font-medium">Requiere Nickname (Regalable)</span>
    </label>
</div><!-- BLOQUE DE RESERVA Y TUTORIAL -->
<div class="mt-3 bg-teal-50 dark:bg-teal-900/10 p-3 rounded-lg border border-teal-200 dark:border-teal-700">
    <p class="text-xs font-semibold text-teal-700 dark:text-teal-300 mb-2"><i class="fas fa-layer-group mr-1"></i>Reserva y Tutorial</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
            <label class="flex items-center space-x-2 cursor-pointer">
                <input type="checkbox" id="checkReserva" name="modo_reserva" value="1" class="w-4 h-4 text-teal-600 rounded focus:ring-teal-500" onchange="toggleReservaFields()">
                <span class="text-xs font-medium">Modo Reserva</span>
            </label>
            <div id="reservaFieldsContainer" class="hidden mt-2">
                <textarea id="mensajeReserva" name="mensaje_reserva" rows="2" class="w-full border rounded-lg py-2 px-3 text-xs" placeholder="Mensaje de reserva que verá el cliente..."></textarea>
            </div>
        </div>
        <div>
            <label class="flex items-center space-x-2 cursor-pointer">
                <input type="checkbox" id="checkTutorial" name="activar_tutorial" value="1" class="w-4 h-4 text-emerald-600 rounded focus:ring-emerald-500" onchange="toggleTutorialFields()">
                <span class="text-xs font-medium">Activar Tutorial</span>
            </label>
            <div id="tutorialFieldsContainer" class="hidden mt-2">
                <textarea id="mensajeTutorial" name="mensaje_tutorial" rows="2" class="w-full border rounded-lg py-2 px-3 text-xs" placeholder="Mensaje del tutorial que se mostrará al comprar..."></textarea>
            </div>
        </div>
    </div>
</div>
<div><label for="productCategory" class="block text-sm font-medium mb-1">Categoría</label><select id="productCategory" name="categoria" class="w-full border rounded-lg py-2 px-3" required></select></div></div><div id="newCategoryContainer" class="hidden"><label class="block text-sm font-medium mb-1">Nueva Categoría</label><input type="text" id="newCategory" class="w-full border rounded-lg py-2 px-3"></div><div><label for="productImageUrl" class="block text-sm font-medium mb-1">URL Imagen</label><input type="url" id="productImageUrl" name="imagen_url" class="w-full border rounded-lg py-2 px-3"></div><h4 class="modal-section-title">Configuración de Oferta</h4><div class="grid grid-cols-2 gap-4"><div><label class="block text-sm font-medium mb-1">Oferta</label><div class="flex"><select id="offerType" name="tipo_oferta" class="border rounded-l-lg py-2 px-3"><option value="precio_fijo">Precio Fijo</option><option value="porcentaje">Porcentaje</option></select><div class="relative flex-grow"><input type="number" id="offerValue" name="valor_oferta" class="w-full border rounded-r-lg py-2 px-3"><span id="offerSuffix" class="absolute right-3 top-2.5 pointer-events-none">S/</span></div></div></div><div><label for="etiquetaTexto" class="block text-sm font-medium mb-1">Texto Etiqueta</label><input type="text" id="etiquetaTexto" name="etiqueta_texto" class="w-full border rounded-lg py-2 px-3" placeholder="OFERTA"></div></div><div><label class="block text-sm font-medium mb-1">Expiración Oferta</label><select id="ofertaExpirationType" class="w-full p-2 border rounded-lg mb-2"><option value="none">Nunca</option><option value="date">Fecha</option><option value="timer">Temporizador</option><option value="datetime">Fecha y Hora</option></select><div id="ofertaExpirationDateInput" class="hidden"><input type="text" class="w-full p-2 border rounded-lg"></div><div id="ofertaExpirationTimerInput" class="hidden grid grid-cols-2 gap-2"><input type="number" placeholder="Horas" min="0" class="w-full p-2 border rounded-lg"><input type="number" placeholder="Minutos" min="0" max="59" class="w-full p-2 border rounded-lg"></div><div id="ofertaExpirationDatetimeInput" class="hidden"><input type="text" class="w-full p-2 border rounded-lg"></div><input type="hidden" id="ofertaFechaExpiracion" name="oferta_fecha_expiracion"></div></div><div class="md:col-span-1 space-y-4"><h4 class="modal-section-title">Gestión de Stock</h4><div><label for="stockType" class="block text-sm font-medium mb-1">Tipo de Stock</label><select id="stockType" class="w-full border rounded-lg py-2 px-3"><option value="-1">Infinito</option><option value="limited">Limitado</option></select></div><div id="stockQuantityContainer" class="hidden"><label for="stockQuantity" class="block text-sm font-medium mb-1">Cantidad</label><input type="number" id="stockQuantity" name="stock_cantidad" class="w-full border rounded-lg py-2 px-3" min="0"></div><h4 class="modal-section-title flex justify-between items-center"><span>Personalización Visual</span><button type="button" onclick="resetColorsToDefault(true)" class="text-xs font-semibold px-2 py-1 rounded border"><i class="fas fa-undo mr-1"></i></button></h4><div class="space-y-2"><div><label class="block text-sm font-medium">Etiqueta de Oferta</label><div class="grid grid-cols-2 gap-4"><div><label class="block text-xs">Posición</label><select name="etiqueta_posicion" id="etiquetaPosicion" class="w-full border rounded-lg py-1 px-2 text-xs"><option value="1">Arriba</option><option value="2">Abajo</option></select></div><div><label class="block text-xs">H. Urgencia</label><input type="number" name="urgencia_horas" id="urgenciaHoras" class="w-full border rounded-lg py-1 px-2 text-xs" value="24"></div></div></div><div class="color-picker-wrapper"><label class="text-sm">Fondo Etiqueta</label><input type="color" id="etiquetaColorBG" name="etiqueta_color_fondo"></div><div class="color-picker-wrapper"><label class="text-sm">Texto Etiqueta</label><input type="color" id="etiquetaColorText" name="etiqueta_color_texto"></div><div class="color-picker-wrapper"><label class="text-sm">Precio Oferta</label><input type="color" id="precioOfertaColor" name="precio_oferta_color"></div></div><div class="mt-4"><label class="block text-sm font-medium">Timer de Producto</label><div class="color-picker-wrapper"><label class="text-sm">Fondo Timer</label><input type="color" id="timerColorBG" name="timer_color_fondo"></div><div class="color-picker-wrapper"><label class="text-sm">Texto Timer</label><input type="color" id="timerColorText" name="timer_color_texto"></div></div><h4 class="modal-section-title">Expiración del Producto</h4><div><label class="block text-sm font-medium mb-1">Expiración General</label><select id="productExpirationType" class="w-full p-2 border rounded-lg mb-2"><option value="none">Nunca</option><option value="date">Fecha</option><option value="timer">Temporizador</option><option value="datetime">Fecha y Hora</option></select><div id="productExpirationDateInput" class="hidden"><input type="text" class="w-full p-2 border rounded-lg"></div><div id="productExpirationTimerInput" class="hidden grid grid-cols-2 gap-2"><input type="number" placeholder="Horas" min="0" class="w-full p-2 border rounded-lg"><input type="number" placeholder="Minutos" min="0" max="59" class="w-full p-2 border rounded-lg"></div><div id="productExpirationDatetimeInput" class="hidden"><input type="text" class="w-full p-2 border rounded-lg"></div><input type="hidden" id="productFechaExpiracion" name="fecha_expiracion"></div></div></form></div><div class="flex justify-end space-x-3 border-t px-6 py-4 flex-shrink-0"><button type="button" onclick="closeModal()" class="px-4 py-2 rounded-lg">Cancelar</button><button type="button" onclick="saveProduct()" class="px-4 py-2 text-white rounded-lg" style="background-color:var(--accent);">Guardar</button></div></div></div>
<div id="confirmModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 hidden"><div class="card rounded-lg shadow-xl max-w-sm w-full mx-4"><div class="p-6 text-center"><i id="confirmIcon" class="fas fa-exclamation-triangle text-4xl text-yellow-500 mb-4"></i><h3 id="confirmTitle" class="text-lg font-semibold mb-2"></h3><p id="confirmText" class="text-sm mb-4"></p><div class="flex justify-center space-x-3"><button onclick="closeConfirmation()" class="px-4 py-2 rounded-lg cancel-btn">Cancelar</button><button id="confirmActionButton" class="px-4 py-2 text-white rounded-lg">Confirmar</button></div></div></div></div>

<script src="https://cdn.tiny.cloud/1/uxizc6p9to28anbqz33x7ogtn4gh9co29g9ui7o0e1h7t87r/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script><script src="https://npmcdn.com/flatpickr/dist/l10n/es.js"></script>

<!-- =============================================================== -->
<!-- === JAVASCRIPT: CÓDIGO COMPLETO, LEGIBLE Y CON LAS SOLUCIONES === -->
<!-- =============================================================== -->
<script>
    // --- VARIABLES GLOBALES Y ESTADO ---
    const allProducts = <?php echo $productos_json; ?>;
    const allCategories = <?php echo $categorias_json; ?>;
    const flatpickrInstances = {};
    const defaultColors = {
        bgEtiqueta: '#22c55e', textoEtiqueta: '#ffffff', precioOferta: '#22c55e',
        bgTimer: '#18181b', textoTimer: '#ffffff'
    };
    let confirmActionCallback = null;
    let tinymceEditor = null;

    // --- FUNCIONES DE UTILIDAD Y UI ---

    function showToast(message, type = "success") {
        const container = document.querySelector("body");
        const toast = document.createElement("div");
        toast.className = `fixed bottom-6 right-6 z-[1050] flex items-center max-w-xs p-4 rounded-lg shadow-lg text-white transform translate-x-full opacity-0 transition-all duration-300 ${type === "success" ? "bg-green-500" : "bg-red-500"}`;
        toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-times-circle'} mr-3"></i> <p>${message}</p>`;
        container.appendChild(toast);
        requestAnimationFrame(() => {
            toast.classList.remove("translate-x-full", "opacity-0");
        });
        setTimeout(() => {
            toast.classList.add("translate-x-full", "opacity-0");
            toast.addEventListener("transitionend", () => toast.remove());
        }, 4000);
    }

    function resetColorsToDefault(showMsg = false) {
        document.getElementById('etiquetaColorBG').value = defaultColors.bgEtiqueta;
        document.getElementById('etiquetaColorText').value = defaultColors.textoEtiqueta;
        document.getElementById('precioOfertaColor').value = defaultColors.precioOferta;
        document.getElementById('timerColorBG').value = defaultColors.bgTimer;
        document.getElementById('timerColorText').value = defaultColors.textoTimer;
        if (showMsg) showToast('Colores restaurados a los valores por defecto.', 'success');
    }

    // --- LÓGICA DE MANEJO DE FECHAS (Corregida y Optimizada) ---

    function handleExpirationChange(prefix) {
        const type = document.getElementById(`${prefix}ExpirationType`).value;
        ['Date', 'Timer', 'Datetime'].forEach(suffix => {
            const el = document.getElementById(`${prefix}Expiration${suffix}Input`);
            if (el) el.classList.add('hidden');
        });
        if (type !== 'none') {
            const el = document.getElementById(`${prefix}Expiration${type.charAt(0).toUpperCase() + type.slice(1)}Input`);
            if (el) el.classList.remove('hidden');
        }
    }

    function getExpirationValue(prefix) {
        const type = document.getElementById(`${prefix}ExpirationType`).value;
        let expirationDate = null;

        if (type === 'date' || type === 'datetime') {
            const flatpickrInput = document.querySelector(`#${prefix}Expiration${type.charAt(0).toUpperCase() + type.slice(1)}Input input`);
            const flatpickrInstance = flatpickrInstances[prefix + (type.charAt(0).toUpperCase() + type.slice(1))];
            if (flatpickrInstance && flatpickrInstance.selectedDates.length > 0) {
                // Obtenemos la fecha directamente de la instancia de flatpickr, que es la fuente más fiable.
                expirationDate = flatpickrInstance.selectedDates[0];
            }
        } else if (type === 'timer') {
            const timerContainer = document.getElementById(`${prefix}ExpirationTimerInput`);
            const hours = parseInt(timerContainer.querySelector('input:first-child').value) || 0;
            const minutes = parseInt(timerContainer.querySelector('input:last-child').value) || 0;
            if (hours > 0 || minutes > 0) {
                expirationDate = new Date(); // Hora local del navegador
                expirationDate.setHours(expirationDate.getHours() + hours);
                expirationDate.setMinutes(expirationDate.getMinutes() + minutes);
            }
        }
        
        // Se convierte a formato ISO (UTC) si la fecha es válida, sino se devuelve null.
        return (expirationDate && !isNaN(expirationDate.getTime())) ? expirationDate.toISOString() : null;
    }
    
    // --- FUNCIONES DE RENDERIZADO (Con mejoras y nueva funcionalidad) ---

    function formatExpiration(dateString) {
        if (!dateString || dateString === '0000-00-00 00:00:00') return '—';
        
        // Creamos la fecha AVISÁNDOLE que la cadena de entrada está en UTC.
        const date = new Date(dateString.replace(' ', 'T') + 'Z');

        if (isNaN(date.getTime())) return '<span class="text-red-500">Inválido</span>';
        const now = new Date();
        if (date < now) return '<span class="font-bold text-red-500">Expirado</span>';
        
        const diff = date - now;
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        
        let colorClass = days < 1 && hours < 1 ? 'text-red-500' : (days < 1 ? 'text-yellow-500' : '');

        if (days > 0) return `<span class="font-bold ${colorClass}">${days}d ${hours}h</span>`;
        if (hours > 0) return `<span class="font-bold ${colorClass}">${hours}h ${minutes}m</span>`;
        return `<span class="font-bold ${colorClass}">${minutes}m</span>`;
    }

    function formatStock(stock) {
        if (stock == -1) return '<span class="stock-badge stock-infinite">Infinito</span>';
        if (stock > 0 && stock <= 5) return `<span class="stock-badge stock-low">${stock} restantes</span>`;
        if (stock > 0) return `<span class="stock-badge stock-ok">${stock} en stock</span>`;
        return '<span class="stock-badge stock-out">Agotado</span>';
    }

    function createRow(product, index, total, isInactive = false) {
        const precioDisplay = product.precio_oferta ?
            `<span class="line-through text-gray-400">S/ ${parseFloat(product.precio_base).toFixed(2)}</span> <strong style="color:${product.precio_oferta_color}">S/ ${parseFloat(product.precio_oferta).toFixed(2)}</strong>` :
            `S/ ${parseFloat(product.precio_base).toFixed(2)}`;
        
        const toggleActivo = `<label class="inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer" ${product.activo ? 'checked' : ''} onchange="toggleStatus(${product.id}, this.checked)"><div class="relative w-11 h-6 bg-gray-200 dark:bg-gray-700 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500"></div></label>`;
        const expiracionDisplay = `<div class="text-xs space-y-1"><div><span class="font-semibold">Oferta:</span> ${formatExpiration(product.oferta_fecha_expiracion)}</div><div><span class="font-semibold">Prod:</span> ${formatExpiration(product.fecha_expiracion)}</div></div>`;
        const detallesDisplay = `<div class="text-xs space-y-1"> <div>Pos: ${product.etiqueta_posicion == 1 ? "<i class='fas fa-arrow-up'></i> Arriba" : "<i class='fas fa-arrow-down'></i> Abajo"}</div> <div class="flex items-center gap-1">Color: <div class="w-3 h-3 rounded-full border border-gray-400" style="background-color:${product.etiqueta_color_fondo}"></div></div> </div>`;

        const moveUpBtn = (!isInactive && index > 0) ? `<button onclick="moveProduct(${product.id}, 'up')" class="p-1 hover:text-white" title="Subir"><i class="fas fa-chevron-up"></i></button>` : `<span class="p-1 w-6 inline-block"></span>`;
        const moveDownBtn = (!isInactive && index < total - 1) ? `<button onclick="moveProduct(${product.id}, 'down')" class="p-1 hover:text-white" title="Bajar"><i class="fas fa-chevron-down"></i></button>` : ``;

        const agotarStockBtn = `<button onclick="confirmIndividualStockAction(${product.id}, 0, '${product.nombre.replace(/'/g, "\\'")}')" class="p-2 hover:text-yellow-500 disabled:opacity-50 disabled:cursor-not-allowed" title="Agotar Stock" ${product.stock_cantidad === 0 ? 'disabled' : ''}><i class="fas fa-box"></i></button>`;
        const restaurarStockBtn = `<button onclick="confirmIndividualStockAction(${product.id}, -1, '${product.nombre.replace(/'/g, "\\'")}')" class="p-2 hover:text-blue-500 disabled:opacity-50 disabled:cursor-not-allowed" title="Restaurar Stock Infinito" ${product.stock_cantidad === -1 ? 'disabled' : ''}><i class="fas fa-infinity"></i></button>`;

        return `
            <tr>
                <td class="px-6 py-4 font-medium">${product.id}</td>
                <td class="px-6 py-4"><div class="flex items-center"><img src="${product.imagen_url || 'https://via.placeholder.com/40'}" class="w-10 h-10 rounded-md mr-4"><span class="font-medium">${product.nombre}</span></div></td>
                <td class="px-6 py-4 text-sm">${precioDisplay}</td>
                <td class="px-6 py-4 text-sm">${formatStock(product.stock_cantidad)}</td>
                <td class="px-6 py-4">${toggleActivo}</td>
                <td class="px-6 py-4 text-sm">${expiracionDisplay}</td>
                <td class="px-6 py-4 text-sm">${detallesDisplay}</td>
                <td class="px-6 py-4"><div class="flex items-center justify-end">
                    <div class="flex flex-col -my-2 mr-2 text-gray-400">${moveUpBtn}${moveDownBtn}</div>
                    ${agotarStockBtn}
                    ${restaurarStockBtn}
                    <button onclick="openModal('edit', ${product.id})" class="p-2 hover:text-blue-500" title="Editar"><i class="fas fa-pen"></i></button>
                    <button onclick="confirmDelete(${product.id})" class="p-2 hover:text-red-500" title="Eliminar"><i class="fas fa-trash"></i></button>
                </div></td>
            </tr>
        `;
    }

    // El resto de la sección de renderizado no necesita cambios...
    function createTable(category, products, isInactive = false) {
        const categoryStockButtons = `
            <div class="flex items-center gap-2">
                <button onclick="confirmCategoryStockAction('${category}', 0)" class="px-2 py-1 text-xs rounded-lg flex items-center gap-1 bg-red-600/10 text-red-500 hover:bg-red-600 hover:text-white" title="Agotar Stock de Categoría"><i class="fas fa-store-slash"></i></button>
                <button onclick="confirmCategoryStockAction('${category}', -1)" class="px-2 py-1 text-xs rounded-lg flex items-center gap-1" style="background-color: var(--primary); border: 1px solid var(--border-color);" title="Restaurar Stock Infinito"><i class="fas fa-infinity"></i></button>
            </div>`;

        const tableContainer = document.createElement('div');
        tableContainer.className = 'mb-8';
        tableContainer.innerHTML = `
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold flex items-center">${getCategoryIcon(category)} ${category}</h3>
                ${isInactive ? '' : categoryStockButtons}
            </div>
            <div class="overflow-x-auto card rounded-lg shadow">
                <table class="min-w-full tabla-productos">
                    <thead><tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider col-id">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider col-info">Producto</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider col-precios">Precio</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider col-stock">Stock</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider col-estado">Estado</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider col-expiracion">Expiración</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider col-detalles">Detalles</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider col-acciones">Acciones</th>
                    </tr></thead>
                    <tbody class="divide-y">${products.map((p, i, arr) => createRow(p, i, arr.length, isInactive)).join('')}</tbody>
                </table>
            </div>
        `;
        return tableContainer;
    }
    function renderAll() {
        const container = document.getElementById('product-tables-container');
        container.innerHTML = '';
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const categoryFilter = document.getElementById('categoryFilter').value;

        const groupedProducts = {};
        allCategories.forEach(cat => groupedProducts[cat] = []);

        allProducts.forEach(product => {
            if ((categoryFilter === 'all' || product.categoria === categoryFilter) && product.nombre.toLowerCase().includes(searchTerm)) {
                if (groupedProducts[product.categoria]) {
                    groupedProducts[product.categoria].push(product);
                }
            }
        });

        const activeSection = createSectionContainer('Productos Activos');
        const inactiveSection = createSectionContainer('Productos Inactivos', true);
        let hasActive = false, hasInactive = false;
        
        allCategories.forEach(category => {
            const activeProducts = groupedProducts[category].filter(p => p.activo);
            if(activeProducts.length > 0) {
                activeProducts.sort((a,b) => a.orden - b.orden);
                activeSection.appendChild(createTable(category, activeProducts));
                hasActive = true;
            }

            const inactiveProducts = groupedProducts[category].filter(p => !p.activo);
            if(inactiveProducts.length > 0) {
                inactiveProducts.sort((a,b) => a.orden - b.orden);
                inactiveSection.appendChild(createTable(category, inactiveProducts, true));
                hasInactive = true;
            }
        });

        if (hasActive) container.appendChild(activeSection);
        if (hasInactive) container.appendChild(inactiveSection);
        if (!hasActive && !hasInactive) container.innerHTML = '<div class="card text-center p-8"><p>No se encontraron productos.</p></div>';
    }
    function createSectionContainer(title, isFaded = false) {
        const section = document.createElement('section');
        section.className = `mb-12 ${isFaded ? 'opacity-80' : ''}`;
        const h2 = document.createElement('h2');
        h2.className = "text-2xl font-bold mb-6 border-b pb-2";
        h2.textContent = title;
        section.appendChild(h2);
        return section;
    }
    function getCategoryIcon(categoryName) {
        const name = categoryName.toLowerCase();
        if (name.includes('pavos')) return '<i class="fas fa-coins text-yellow-500 mr-2"></i>';
        if (name.includes('club')) return '<i class="fas fa-crown text-purple-500 mr-2"></i>';
        if (name.includes('pases')) return '<i class="fas fa-ticket-alt text-blue-500 mr-2"></i>';
        if (name.includes('packs')) return '<i class="fas fa-box-open text-green-500 mr-2"></i>';
        return '<i class="fas fa-tag text-gray-500 mr-2"></i>';
    }
    function updateCategorySelects() {
        const selects = [document.getElementById('productCategory'), document.getElementById('categoryFilter')];
        selects.forEach(select => {
            if (!select) return;
            const currentValue = select.value;
            const firstOption = select.options[0]; 
            select.innerHTML = ''; 
            if (firstOption && firstOption.value === 'all') select.appendChild(firstOption);
            allCategories.forEach(cat => select.add(new Option(cat, cat)));
            if(select.id === 'productCategory') select.add(new Option('+ Añadir nueva categoría', 'new'));
            select.value = currentValue;
        });
    }

    // --- LÓGICA DEL MODAL (Con corrección de bug de fechas) ---

    function updateOfferSuffix() {
        document.getElementById('offerSuffix').textContent = document.getElementById('offerType').value === 'porcentaje' ? '%' : 'S/';
    }
    function handleStockTypeChange() {
        document.getElementById('stockQuantityContainer').classList.toggle('hidden', document.getElementById('stockType').value == -1);
    }
    function openModal(mode, productId = null) {
        const form = document.getElementById('productForm');
        form.reset();
        resetColorsToDefault(false);
        if(tinymceEditor) tinymceEditor.setContent('');

        const product = productId ? allProducts.find(p => p.id === productId) : null;
        if (mode === 'edit' && !product) return;

        document.getElementById('modalTitle').textContent = mode === 'edit' ? 'Editar Producto' : 'Añadir Nuevo Producto';

        if (product) {
            form.id.value = product.id;
            form.nombre.value = product.nombre;
            tinymceEditor.setContent(product.descripcion);
            form.precio_base.value = product.precio_base;
            form.monedas_otorgadas.value = product.monedas_otorgadas || 0; // <--- NUEVO
            document.getElementById('checkCanje').checked = (product.permitir_canje_monedas == 1);
            document.getElementById('checkNick').checked = (product.requiere_nickname == 1);
            document.getElementById('checkCashback').checked = (product.dar_cashback == 1);
            document.getElementById('checkReserva').checked = (product.modo_reserva == 1);
            document.getElementById('checkTutorial').checked = (product.activar_tutorial == 1);
            document.getElementById('mensajeReserva').value = product.mensaje_reserva || '';
            document.getElementById('mensajeTutorial').value = product.mensaje_tutorial || '';
            toggleReservaFields();
            toggleTutorialFields();
            form.categoria.value = product.categoria;
            form.imagen_url.value = product.imagen_url;
            document.getElementById('stockType').value = product.stock_cantidad == -1 ? '-1' : 'limited';
            document.getElementById('stockQuantity').value = product.stock_cantidad;
            handleStockTypeChange();
            form.tipo_oferta.value = product.tipo_oferta;
            if (product.precio_oferta) {
                if(product.tipo_oferta === 'porcentaje' && product.precio_base > 0) {
                    form.valor_oferta.value = ((1 - (product.precio_oferta / product.precio_base)) * 100).toFixed(2);
                } else {
                    form.valor_oferta.value = product.precio_oferta;
                }
            }
            updateOfferSuffix();
            form.etiqueta_texto.value = product.etiqueta_texto;
            form.etiqueta_posicion.value = product.etiqueta_posicion;
            form.urgencia_horas.value = product.urgencia_horas;
            form.etiqueta_color_fondo.value = product.etiqueta_color_fondo;
            form.etiqueta_color_texto.value = product.etiqueta_color_texto;
            form.precio_oferta_color.value = product.precio_oferta_color;
            form.timer_color_fondo.value = product.timer_color_fondo;
            form.timer_color_texto.value = product.timer_color_texto;

            ['oferta', 'product'].forEach(prefix => {
                const select = document.getElementById(`${prefix}ExpirationType`);
                const dateFieldName = (prefix === 'oferta' ? 'oferta_' : '') + 'fecha_expiracion';
                const dateStr = product[dateFieldName];
                
                const timerInputs = document.querySelectorAll(`#${prefix}ExpirationTimerInput input`);
                timerInputs.forEach(input => input.value = ''); 
                
                if (dateStr && dateStr !== '0000-00-00 00:00:00') {
                    select.value = 'datetime';
                    
                    // === CORRECCIÓN PROBLEMA 1: Forzar interpretación UTC ===
                    // Añadimos 'Z' al final para que Flatpickr sepa que la fecha es UTC y la convierta a la zona horaria local del usuario para mostrarla.
                    const utcDateStr = dateStr.replace(' ', 'T') + 'Z';
                    flatpickrInstances[`${prefix}Datetime`].setDate(utcDateStr, true);

                } else {
                    select.value = 'none';
                    flatpickrInstances[`${prefix}Date`].clear();
                    flatpickrInstances[`${prefix}Datetime`].clear();
                }
                handleExpirationChange(prefix);
            });
        } else {
             // Configuración para un producto nuevo
            document.getElementById('stockType').value = -1;
            handleStockTypeChange();
            ['oferta', 'product'].forEach(prefix => {
                document.getElementById(`${prefix}ExpirationType`).value = 'none';
                flatpickrInstances[`${prefix}Date`].clear();
                flatpickrInstances[`${prefix}Datetime`].clear();
                handleExpirationChange(prefix);
            });
        }
        document.getElementById('newCategoryContainer').classList.add('hidden');
        toggleReservaFields();
        toggleTutorialFields();
        document.getElementById('productModal').classList.remove('hidden');
        document.getElementById('productModal').classList.add('flex');
    }

    function toggleReservaFields() {
        const on = document.getElementById('checkReserva').checked;
        document.getElementById('reservaFieldsContainer').classList.toggle('hidden', !on);
    }
    function toggleTutorialFields() {
        const on = document.getElementById('checkTutorial').checked;
        document.getElementById('tutorialFieldsContainer').classList.toggle('hidden', !on);
    }

    function closeModal() {
        document.getElementById('productModal').classList.add('hidden');
        document.getElementById('productModal').classList.remove('flex');
    }

    // --- LÓGICA DE GUARDADO Y API (Con nueva funcionalidad de stock) ---

    async function saveProduct() {
        if(tinymceEditor) tinymceEditor.save();
        const form = document.getElementById('productForm');
        const formData = new FormData(form);
        formData.set('oferta_fecha_expiracion', getExpirationValue('oferta'));
        formData.set('fecha_expiracion', getExpirationValue('product'));
        if (document.getElementById('stockType').value == -1) {
            formData.set('stock_cantidad', -1);
        } else {
            formData.set('stock_cantidad', document.getElementById('stockQuantity').value);
        }
        formData.append('accion', formData.get('id') ? 'edit' : 'add');
        if (form.categoria.value === 'new') {
            formData.set('categoria', document.getElementById('newCategory').value.trim());
        }
        const success = await apiCall(formData);
        if (success) location.reload();
    }
    async function apiCall(formData) {
        try {
            const response = await fetch('productos_estaticos/api_productos.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message);
            sessionStorage.setItem('toastMessage', result.message);
            return true;
        } catch (error) {
            showToast(error.message || 'Error desconocido', 'error');
            return false;
        }
    }
    
    // El resto de funciones de confirmación y acciones no necesitan cambios...
    function openConfirmation(title, text, callback, type = 'warning') {
        document.getElementById('confirmTitle').textContent = title;
        document.getElementById('confirmText').textContent = text;
        const icon = document.getElementById('confirmIcon');
        const button = document.getElementById('confirmActionButton');

        icon.className = 'text-4xl mb-4 fas ';
        button.className = 'px-4 py-2 text-white rounded-lg ';
        
        if (type === 'danger') {
            icon.classList.add('fa-trash-alt', 'text-red-500');
            button.classList.add('bg-red-600', 'hover:bg-red-700');
        } else {
            icon.classList.add('fa-exclamation-triangle', 'text-yellow-500');
            button.classList.add('bg-yellow-500', 'hover:bg-yellow-600');
        }

        confirmActionCallback = callback;
        document.getElementById('confirmModal').classList.remove('hidden');
        document.getElementById('confirmModal').classList.add('flex');
    }
    function closeConfirmation() {
        document.getElementById('confirmModal').classList.add('hidden');
        document.getElementById('confirmModal').classList.remove('flex');
        confirmActionCallback = null;
    }
    function confirmDelete(productId) {
        const product = allProducts.find(p => p.id === productId);
        if (product) {
            openConfirmation(
                'Eliminar Producto', 
                `¿Estás seguro de que quieres eliminar "${product.nombre}"? Esta acción es irreversible.`, 
                () => executeDelete(productId),
                'danger'
            );
        }
    }
    async function executeDelete(productId) {
        const formData = new FormData();
        formData.append('accion', 'delete');
        formData.append('id', productId);
        if (await apiCall(formData)) location.reload();
        closeConfirmation();
    }
    function confirmStockAction(stockValue) {
        const isAgotar = stockValue === 0;
        openConfirmation(
            isAgotar ? 'Agotar Todo' : 'Restaurar Stock',
            isAgotar ? '¿Poner el stock de TODOS los productos a 0?' : '¿Restaurar el stock de TODOS a infinito?',
            () => executeSetAllStock(stockValue),
            isAgotar ? 'danger' : 'warning'
        );
    }
    async function executeSetAllStock(stockValue) {
        const formData = new FormData();
        formData.append('accion', 'set_all_stock');
        formData.append('stock', stockValue);
        if (await apiCall(formData)) location.reload();
        closeConfirmation();
    }
    function confirmCategoryStockAction(category, stockValue) {
        const isAgotar = stockValue === 0;
        openConfirmation(
            isAgotar ? `Agotar Categoría '${category}'` : `Restaurar Stock '${category}'`,
            isAgotar ? `¿Poner el stock de TODOS los productos de esta categoría a 0?` : `¿Restaurar el stock de TODOS los productos de esta categoría a infinito?`,
            () => executeSetCategoryStock(category, stockValue),
            isAgotar ? 'danger' : 'warning'
        );
    }
    async function executeSetCategoryStock(category, stockValue) {
        const formData = new FormData();
        formData.append('accion', 'set_stock_by_category');
        formData.append('category', category);
        formData.append('stock', stockValue);
        if (await apiCall(formData)) location.reload();
        closeConfirmation();
    }
    function confirmIndividualStockAction(productId, stockValue, productName) {
        const isAgotar = stockValue === 0;
        openConfirmation(
            isAgotar ? `Agotar Stock` : `Restaurar Stock`,
            isAgotar ? `¿Estás seguro de que quieres agotar el stock de "${productName}"?` : `¿Estás seguro de que quieres restaurar el stock de "${productName}" a infinito?`,
            () => executeSetStock(productId, stockValue),
            isAgotar ? 'danger' : 'warning'
        );
    }
    async function executeSetStock(productId, stockValue) {
        const formData = new FormData();
        formData.append('accion', 'set_stock');
        formData.append('id', productId);
        formData.append('stock', stockValue);
        if (await apiCall(formData)) location.reload();
        closeConfirmation();
    }
    async function toggleStatus(productId, isChecked) {
        const formData = new FormData();
        formData.append('accion', 'toggle_status');
        formData.append('id', productId);
        formData.append('status', isChecked ? 1 : 0);
        const success = await apiCall(formData);
        if (!success) {
            document.querySelector(`input[onchange="toggleStatus(${productId},this.checked)"]`).checked = !isChecked;
        } else {
            location.reload();
        }
    }
    async function moveProduct(productId, direction) {
        const formData = new FormData();
        formData.append('accion', 'reorder_product');
        formData.append('id', productId);
        formData.append('direction', direction);
        if (await apiCall(formData)) location.reload();
    }

    // --- INICIALIZACIÓN ---
    document.addEventListener('DOMContentLoaded', () => {
        tinymce.init({
            selector: '#productDescription', height: 200, menubar: false,
            plugins: 'lists link autolink charmap',
            toolbar: 'bold italic underline | bullist numlist | link | removeformat',
            skin: document.documentElement.getAttribute('data-theme') === 'dark' ? 'oxide-dark' : 'oxide',
            content_css: document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'default'
        }).then(editors => { tinymceEditor = editors[0]; });

        ['oferta', 'product'].forEach(prefix => {
            flatpickrInstances[`${prefix}Datetime`] = flatpickr(`#${prefix}ExpirationDatetimeInput input`, { enableTime: true, dateFormat: "Y-m-d H:i", locale: "es" });
            flatpickrInstances[`${prefix}Date`] = flatpickr(`#${prefix}ExpirationDateInput input`, { dateFormat: "Y-m-d", locale: "es" });
            document.getElementById(`${prefix}ExpirationType`).addEventListener('change', () => handleExpirationChange(prefix));
        });

        const toastMessage = sessionStorage.getItem('toastMessage');
        if (toastMessage) {
            showToast(toastMessage, 'success');
            sessionStorage.removeItem('toastMessage');
        }

        updateCategorySelects();
        renderAll();
        document.getElementById('searchInput').addEventListener('input', renderAll);
        document.getElementById('categoryFilter').addEventListener('change', renderAll);
        document.getElementById('productCategory').addEventListener('change', function() {
            document.getElementById('newCategoryContainer').classList.toggle('hidden', this.value !== 'new');
        });
        document.getElementById('offerType').addEventListener('change', updateOfferSuffix);
        document.getElementById('stockType').addEventListener('change', handleStockTypeChange);
        document.getElementById('confirmActionButton').addEventListener('click', () => {
            if (typeof confirmActionCallback === 'function') {
                confirmActionCallback();
            }
        });
    });
</script>

<?php 
include __DIR__ . '/../core/admin_footer.php';
?>
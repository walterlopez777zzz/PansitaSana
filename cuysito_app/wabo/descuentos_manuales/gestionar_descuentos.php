<?php
// Archivo: gestionar_descuentos.php (v3.4 - Misión "Pulido Final" Completada)
require_once __DIR__ . '/../core/admin_header.php';
?>

<!-- Estilos específicos para esta página -->
<style>
    .price-original { text-decoration: line-through; color: #9ca3af; }
    .discount-badge { position: absolute; top: 8px; right: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); }
    .card-hover:hover { transform: translateY(-4px); box-shadow: 0 10px 15px -5px rgba(0, 0, 0, 0.1), 0 8px 8px -5px rgba(0, 0, 0, 0.04); }
    .vbucks-container { background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px); }
    .section-header { background-image: linear-gradient(to right, rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.1)); border-left: 4px solid var(--accent); }
    
    /* === ESTILOS MISIÓN 'PULIDO FINAL' === */
    :root {
        --price-color-sale-light: #1d4ed8; --price-color-base-light: #7e22ce; --price-color-profit-light: #166534; --price-color-loss-light: #b91c1c;
        --price-color-sale-dark: #60a5fa; --price-color-base-dark: #c084fc; --price-color-profit-dark: #4ade80; --price-color-loss-dark: #f87171;
    }
    [data-theme="light"] { --price-sale: var(--price-color-sale-light); --price-base: var(--price-color-base-light); --price-profit: var(--price-color-profit-light); --price-loss: var(--price-color-loss-light); }
    [data-theme="dark"] { --price-sale: var(--price-color-sale-dark); --price-base: var(--price-color-base-dark); --price-profit: var(--price-color-profit-dark); --price-loss: var(--price-color-loss-dark); }
    
    .price-sale { color: var(--price-sale); }
    .price-base { color: var(--price-base); }
    .profit-positive { color: var(--price-profit); }
    .profit-negative { color: var(--price-loss); }
    
    .price-setting-display { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: var(--text-secondary); }
    .price-setting-display span { font-weight: 600; }
    
    .price-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; }
    .price-item { text-align: center; }
    .price-item .label { font-size: 0.8rem; font-weight: 500; color: var(--text-secondary); }
    .price-item .value { font-size: 1.25rem; font-weight: 700; line-height: 1.2; }

    .discount-bar-container { width: 100%; max-width: 80px; height: 6px; background-color: var(--primary); border-radius: 3px; overflow: hidden; margin-top: 4px; }
    .discount-bar { height: 100%; background-color: var(--accent); border-radius: 3px; }
</style>

<div class="space-y-8">
    <!-- Header con configuración de precios minimalista -->
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold" style="color: var(--text-primary);">Descuentos Tienda</h1>
            <p style="color: var(--text-secondary);">Gestiona los descuentos de la tienda diaria de Fortnite</p>
        </div>
        <div class="flex items-center gap-4 md:gap-6">
            <div class="flex items-center gap-4 text-sm">
                <div class="price-setting-display">Precio Venta: <span id="salePriceDisplay" class="price-sale">S/ 1.50</span></div>
                <div class="price-setting-display">Precio Base: <span id="basePriceDisplay" class="price-base">S/ 1.15</span></div>
                <button id="openPriceModalBtn" class="p-2 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors" title="Editar Precios"><i class="fas fa-pencil-alt text-lg"></i></button>
            </div>
            <button id="updateShopBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 transition-colors"><i class="fas fa-sync-alt"></i> Actualizar Tienda</button>
        </div>
    </header>

    <input type="hidden" id="salePrice" value="1.50"><input type="hidden" id="basePrice" value="1.15">
    
    <!-- Tabla de Descuentos Activos Reorganizada -->
    <section class="card rounded-xl shadow-md p-6">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 gap-4">
            <h2 class="text-xl font-semibold">Descuentos Activos</h2>
            <button onclick="initiateAddDiscount()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 transition-colors"><i class="fas fa-plus"></i> Añadir Descuento Manual</button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y" style="border-color: var(--border-color);">
                <thead style="background-color: var(--primary);">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Imagen</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider w-1/4">Nombre</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Descuento</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Precio Venta</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Precio Base</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Ganancia</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Expira</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody id="discountsTableBody" class="divide-y" style="border-color: var(--border-color);">
                    <tr><td colspan="8" class="px-6 py-4 text-center" style="color: var(--text-secondary);"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando descuentos...</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Galería de la Tienda Diaria -->
    <section>
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 gap-4">
            <h2 class="text-xl font-semibold">Tienda Diaria</h2>
            <div id="shopStatus" class="p-2 rounded-lg text-sm"><i class="fas fa-info-circle mr-1"></i>Conectando a la API...</div>
        </div>
        <div class="relative mb-4"><input type="text" id="shopSearchInput" placeholder="Buscar en la tienda..." class="w-full p-3 pl-10 rounded-lg border shadow-sm" style="background-color: var(--secondary); border-color: var(--border-color);" /><i class="fas fa-search absolute left-3 top-3.5" style="color: var(--text-secondary);"></i></div>
        <div id="shopItemsContainer" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"></div>
    </section>
</div>

<!-- Modal de Configuración de Precios (HTML sin cambios) -->
<div id="priceSettingsModal" class="fixed inset-0 z-50 flex items-center justify-center hidden bg-black/60"><div class="card rounded-xl shadow-xl w-full max-w-md mx-4 z-10"><form id="priceSettingsForm" class="p-6 space-y-4"><div class="flex justify-between items-center"><h3 class="text-xl font-semibold">Configurar Precios</h3><button type="button" onclick="closePriceModal()" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"><i class="fas fa-times"></i></button></div><div class="space-y-2"><label for="modalSalePrice" class="block text-sm font-medium" style="color: var(--text-secondary);">Precio Venta (por 100 pavos)</label><div class="relative"><input type="number" id="modalSalePrice" step="0.01" min="0" required class="w-full p-2 border rounded-lg pl-8" style="background-color: var(--primary); border-color: var(--border-color);" /><span class="absolute left-3 top-2.5" style="color: var(--text-secondary);">S/</span></div></div><div class="space-y-2"><label for="modalBasePrice" class="block text-sm font-medium" style="color: var(--text-secondary);">Precio Base (por 100 pavos)</label><div class="relative"><input type="number" id="modalBasePrice" step="0.01" min="0" required class="w-full p-2 border rounded-lg pl-8" style="background-color: var(--primary); border-color: var(--border-color);" /><span class="absolute left-3 top-2.5" style="color: var(--text-secondary);">S/</span></div></div><div class="pt-4"><button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">Guardar y Recalcular</button></div></form></div></div>
<!-- Modal de Descuento (HTML sin cambios) -->
<!-- Modal de Descuento MEJORADO -->
<div id="discountModal" class="fixed inset-0 z-50 flex items-center justify-center hidden bg-black/60">
    <div class="card rounded-xl shadow-xl w-full max-w-4xl mx-4 z-10 max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center p-4 border-b" style="border-color: var(--border-color);">
            <h3 class="text-xl font-semibold" id="modalTitle"></h3>
            <button type="button" onclick="closeDiscountModal()" class="p-2 rounded-full hover:bg-gray-700"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6 overflow-y-auto space-y-4">
            <form id="discountForm" class="space-y-4">
                <input type="hidden" name="id">

                <!-- Fila 1: Tipo de Descuento, Valor y Nombre (para referencia) -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs mb-1">Tipo de Descuento</label>
                        <select name="tipo_descuento" class="w-full p-2 border rounded-lg" style="background-color: var(--primary); border-color: var(--border-color);">
                            <option value="porcentaje">%</option>
                            <option value="precio_fijo">S/ (Precio Fijo)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Valor</label>
                        <input type="number" name="valor" step="0.01" class="w-full p-2 border rounded-lg" required style="background-color: var(--primary); border-color: var(--border-color);">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Nombre Descriptivo</label>
                        <input type="text" name="nombre_articulo" class="w-full p-2 border rounded-lg" required placeholder="Ej: OFERTA SKINS LEGENDARIAS" style="background-color: var(--primary); border-color: var(--border-color);">
                    </div>
                </div>

                <!-- Fila 2: Fechas de Inicio y Fin (Punto 1.2 y 2) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="tipo_inicio" class="block text-xs mb-1">Inicio del Descuento</label>
                        <select name="tipo_inicio" id="tipo_inicio" class="w-full p-2 border rounded-lg mb-2" style="background-color: var(--primary); border-color: var(--border-color);">
                            <option value="inmediato">Inmediato</option>
                            <option value="especifico">Fecha y Hora Específica</option>
                        </select>
                        <input type="datetime-local" name="fecha_inicio" id="fecha_inicio_input" class="w-full p-2 border rounded-lg hidden" style="background-color: var(--primary); border-color: var(--border-color);">
                    </div>
                    <div>
                        <label for="tipo_expiracion" class="block text-xs mb-1">Expiración del Descuento</label>
                        <select name="tipo_expiracion" id="tipo_expiracion" class="w-full p-2 border rounded-lg mb-2" style="background-color: var(--primary); border-color: var(--border-color);">
                            <option value="nunca">Nunca</option>
                            <option value="especifica">Fecha Específica (Fin del día)</option>
                            <option value="temporizador">Temporizador (Horas/Minutos)</option>
                            <option value="exacta">Fecha y Hora Exacta</option>
                        </select>
                        <div id="expiracion_fields_container" class="space-y-2">
                            <input type="date" name="fecha_expiracion_especifica" class="w-full p-2 border rounded-lg hidden" style="background-color: var(--primary); border-color: var(--border-color);">
                            <div id="temporizador_container" class="grid grid-cols-2 gap-2 hidden">
                                <input type="number" name="temporizador_horas" class="w-full p-2 border rounded-lg" placeholder="Horas" style="background-color: var(--primary); border-color: var(--border-color);">
                                <input type="number" name="temporizador_minutos" class="w-full p-2 border rounded-lg" placeholder="Minutos" style="background-color: var(--primary); border-color: var(--border-color);">
                            </div>
                            <input type="datetime-local" name="fecha_expiracion_exacta" class="w-full p-2 border rounded-lg hidden" style="background-color: var(--primary); border-color: var(--border-color);">
                        </div>
                    </div>
                </div>
                <hr style="border-color: var(--border-color);">

                <!-- Fila 3: Reglas de Aplicabilidad (Punto 1.1) -->
                <div>
                    <h4 class="font-semibold text-sm mb-2 uppercase">Aplicabilidad</h4>
                    <p class="text-xs text-gray-400 mb-2">Define a qué productos o categorías se aplicará este descuento. El sistema aplicará el descuento más específico que encuentre.</p>
                    <div id="ruleBuilderContainer" class="space-y-2"></div>
                    <button type="button" id="addRuleBtn" class="mt-2 text-sm text-blue-400 hover:text-blue-300">
                        <i class="fas fa-plus-circle mr-1"></i>Añadir Regla de Aplicabilidad
                    </button>
                </div>
            </form>
        </div>
        <div class="p-4 border-t flex justify-end" style="border-color: var(--border-color);">
            <button type="button" id="saveDiscountBtn" class="w-full md:w-auto bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition-colors">Guardar Descuento</button>
        </div>
    </div>
</div>
<!-- Toast de Notificación (HTML sin cambios) -->
<div id="toast" class="fixed bottom-4 right-4 z-50 hidden max-w-xs card rounded-lg shadow-lg p-4 flex items-center gap-3"><i id="toastIcon" class="text-xl"></i><p id="toastMessage" class="text-sm font-medium"></p></div>

<script>
    // --- Lógica del Frontend v4.0 - Fusión de Descuentos y Reglas de Cupones ---
    let appData = { shopData: { sections: [] }, discounts: [], productosEstaticos: [], apiCategories: [], categoriasEstaticas: [] };
    let currentEditingId = null;

    const dom = {
        // Elementos de la página principal
        salePriceInput: document.getElementById("salePrice"),
        basePriceInput: document.getElementById("basePrice"),
        salePriceDisplay: document.querySelector("#salePriceDisplay"),
        basePriceDisplay: document.querySelector("#basePriceDisplay"),
        updateShopBtn: document.getElementById("updateShopBtn"),
        shopStatus: document.getElementById("shopStatus"),
        shopSearchInput: document.getElementById("shopSearchInput"),
        shopItemsContainer: document.getElementById("shopItemsContainer"),
        discountsTableBody: document.getElementById("discountsTableBody"),
        // Modal de precios
        priceSettingsModal: document.getElementById("priceSettingsModal"),
        openPriceModalBtn: document.getElementById("openPriceModalBtn"),
        priceSettingsForm: document.getElementById("priceSettingsForm"),
        modalSalePrice: document.getElementById("modalSalePrice"),
        modalBasePrice: document.getElementById("modalBasePrice"),
        // Modal principal de descuentos
        modal: document.getElementById("discountModal"),
        modalTitle: document.getElementById("modalTitle"),
        form: document.getElementById("discountForm"),
        ruleBuilder: document.getElementById('ruleBuilderContainer'),
        addRuleBtn: document.getElementById('addRuleBtn'),
        // Nuevos selectores de fecha
        tipoInicioSelect: document.getElementById('tipo_inicio'),
        inicioInput: document.getElementById('fecha_inicio_input'),
        tipoExpiracionSelect: document.getElementById('tipo_expiracion'),
        expiracionFieldsContainer: document.getElementById('expiracion_fields_container'),
        // Toast de notificaciones
        toast: document.getElementById("toast"),
        toastIcon: document.getElementById("toastIcon"),
        toastMessage: document.getElementById("toastMessage"),
    };

    // --- Funciones de API y Datos ---
    const apiCall = async (action, data = {}, method = 'POST') => {
        const url = `descuentos_manuales/api_tienda.php`;
        const formData = new FormData();
        formData.append('action', action);
        for (const key in data) { formData.append(key, data[key]); }
        try {
            const response = await fetch(url, { method: 'POST', body: formData });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Error en la petición');
            return result;
        } catch (e) {
            console.error(`Error en apiCall(${action}):`, e);
            throw e;
        }
    };
    
    const fetchData = async () => {
        updateShopStatus('loading', 'Actualizando...');
        try {
            const response = await fetch("descuentos_manuales/api_tienda.php?action=get_all_data");
            const result = await response.json();
            if (result.status === "error") throw new Error(result.message);
            
            appData = result.data;
            appData.discounts.forEach(d => d.reglas_aplicabilidad = JSON.parse(d.reglas_aplicabilidad || '[]'));
            
            console.log("Datos iniciales recibidos:", appData);
            render();
            updateShopStatus('success', `Actualizado: ${new Date().toLocaleTimeString()}`);
        } catch (error) {
            console.error("Error en fetchData:", error);
            updateShopStatus('error', 'Error al cargar');
            showToast(error.message, "error");
        }
    };

    // --- Funciones de Lógica de Precios ---
    const loadAndApplyPriceSettings = () => { const savedSalePrice = localStorage.getItem('banano_salePrice') || '1.50'; const savedBasePrice = localStorage.getItem('banano_basePrice') || '1.15'; dom.salePriceInput.value = savedSalePrice; dom.basePriceInput.value = savedBasePrice; dom.salePriceDisplay.textContent = `S/ ${parseFloat(savedSalePrice).toFixed(2)}`; dom.basePriceDisplay.textContent = `S/ ${parseFloat(savedBasePrice).toFixed(2)}`; dom.modalSalePrice.value = savedSalePrice; dom.modalBasePrice.value = savedBasePrice; };
    const calculatePrices = (vbucks) => { const salePricePer100 = parseFloat(dom.salePriceInput.value) || 1.5; const basePricePer100 = parseFloat(dom.basePriceInput.value) || 1.15; return { originalPrice: (vbucks / 100) * salePricePer100, basePrice: (vbucks / 100) * basePricePer100 }; };
    
    const applyDiscount = (originalPrice, discount) => {
        if (!discount) return { finalPrice: originalPrice, discountPercentage: 0 };
        let finalPrice = originalPrice;
        let discountPercentage = 0;
        if (discount.tipo_descuento === "porcentaje") {
            discountPercentage = parseFloat(discount.valor);
            finalPrice = originalPrice * (1 - discountPercentage / 100);
        } else if (discount.tipo_descuento === "precio_fijo") {
            finalPrice = parseFloat(discount.valor);
            if (originalPrice > 0) {
                discountPercentage = Math.round(((originalPrice - finalPrice) / originalPrice) * 100);
            }
        }
        return { finalPrice: finalPrice, discountPercentage: discountPercentage };
    };

const findDiscountForProduct = (entry, sectionName) => {
    // No hay cambios en la lógica de obtención de ID y Tipo, ya que la estructura
    // que creamos en el backend es la misma que el frontend espera.
    const offerId = entry.offerId;
    const itemType = entry.items[0]?.type?.displayValue;

    // Las reglas de búsqueda siguen siendo las mismas.
    const isSpecific = r => r.tipo_regla === 'producto_especifico' && r.valor_regla === offerId;
    const isSection = r => r.tipo_regla === 'seccion_tienda' && r.valor_regla === sectionName;
    const isCategory = r => {
        if (r.tipo_regla !== 'categoria_api') return false;
        // La lógica para Lotes ya es robusta y funciona con la nueva API.
        if (r.valor_regla === 'Lote' && itemType?.toLowerCase().includes('lote')) return true;
        return r.valor_regla === itemType;
    };
    const isGlobal = r => r.tipo_regla === 'global';
    const isGlobalNoStatic = r => r.tipo_regla === 'global_sin_estaticos';

    const findDiscountByRule = (ruleChecker) => {
        // CORRECCIÓN CLAVE: El backend ahora envía 'reglas_aplicabilidad'
        // con los campos 'tipo_regla' y 'valor_regla' directamente desde la DB.
        // Nos aseguramos de buscar en el lugar correcto.
        return appData.discounts.find(d => 
            parseInt(d.activo) === 1 && 
            d.reglas_aplicabilidad?.some(ruleChecker)
        );
    };

    // La lógica de precedencia se mantiene.
    return findDiscountByRule(isSpecific) || 
           findDiscountByRule(isSection) || 
           findDiscountByRule(isCategory) || 
           findDiscountByRule(isGlobal) || 
           findDiscountByRule(isGlobalNoStatic) || 
           null;
};

    // --- Funciones de Renderizado ---
    const render = () => { renderDiscountsTable(); renderShopGallery(); };

    const renderDiscountsTable = () => {
        if (!appData.discounts.length) { dom.discountsTableBody.innerHTML = `<tr><td colspan="8" class="px-4 py-4 text-center" style="color:var(--text-secondary);">No hay descuentos activos.</td></tr>`; return; }
        
        dom.discountsTableBody.innerHTML = appData.discounts.map(discount => {
            const getReglasDesc = (d) => {
                if (!d.reglas_aplicabilidad || d.reglas_aplicabilidad.length === 0) return 'Sin Reglas';
                const primeraRegla = d.reglas_aplicabilidad[0];
                let desc = primeraRegla.valor;
                // Intentar obtener un nombre más amigable
                const optionTextMap = {'global': 'Global (Toda la tienda)', 'global_sin_estaticos': 'Global (Sin Prod. Estáticos)', 'seccion_tienda': `Sección: ${primeraRegla.valor}`, 'categoria_api': `Categoría: ${primeraRegla.valor}`};
                desc = optionTextMap[primeraRegla.tipo] || desc;
                if (d.reglas_aplicabilidad.length > 1) { desc += ` (+${d.reglas_aplicabilidad.length - 1} más)`; }
                return `<div class="truncate text-sm" title="${desc}">${desc}</div>`;
            };

            return `
            <tr class="align-middle">
                <td class="px-4 py-3"><div class="h-10 w-10 rounded-md bg-gray-700 flex items-center justify-center"><i class="fas fa-tags text-xl"></i></div></td>
                <td class="px-4 py-3 font-medium">${discount.nombre_articulo}</td>
                <td class="px-4 py-3">${getReglasDesc(discount)}</td>
                <td class="px-4 py-3 font-semibold">${discount.tipo_descuento === "porcentaje" ? `${parseFloat(discount.valor).toFixed(0)}%` : `S/ ${parseFloat(discount.valor).toFixed(2)}`}</td>
                <td class="px-4 py-3 text-sm">${discount.fecha_inicio ? new Date(discount.fecha_inicio).toLocaleDateString() : 'Inmediato'}</td>
                <td class="px-4 py-3 text-sm">${discount.fecha_expiracion ? new Date(discount.fecha_expiracion).toLocaleDateString() : "Nunca"}</td>
                <td class="px-4 py-3 text-right">
                    <button onclick="editDiscount('${discount.id}')" class="text-blue-500 hover:text-blue-600 p-2 text-lg"><i class="fas fa-edit"></i></button>
                    <button onclick="deleteDiscount('${discount.id}')" class="text-red-500 hover:text-red-600 p-2 text-lg"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
        }).join("");
    };

    const renderShopGallery = () => {
        const searchTerm = dom.shopSearchInput.value.toLowerCase(); dom.shopItemsContainer.innerHTML = "";
        if (!appData.shopData || !appData.shopData.sections || appData.shopData.sections.length === 0) { dom.shopItemsContainer.innerHTML = `<div class="col-span-full text-center p-8" style="color:var(--text-secondary);">La tienda está vacía o no se pudo cargar.</div>`; return; }
        let itemsFound = 0;
        appData.shopData.sections.forEach(section => {
            const filteredEntries = section.entries.filter(entry => entry.items && entry.items[0] && entry.items[0].name.toLowerCase().includes(searchTerm));
            if (filteredEntries.length === 0) return;
            itemsFound += filteredEntries.length;
            
            let sectionHeader = `<div class="section-header col-span-full p-3 rounded-md mb-4 mt-6 flex justify-between items-center">
                <h2 class="text-xl font-bold text-white">${section.name}</h2>
                <button onclick="initiateAddDiscount({ estrategia: 'seccion_tienda', valorInicial: '${section.name}' })" class="bg-yellow-500 text-black text-xs font-bold py-1 px-3 rounded-full hover:bg-yellow-400">Descuento para sección</button>
            </div>`;
            dom.shopItemsContainer.innerHTML += sectionHeader;

            filteredEntries.forEach(entry => {
                const item = entry.items[0]; const discount = findDiscountForProduct(entry, section.name); const prices = calculatePrices(entry.finalPrice); const { finalPrice, discountPercentage } = applyDiscount(prices.originalPrice, discount); const profit = finalPrice - prices.basePrice;
                let backgroundStyle = ''; if (item.cardColors && item.cardColors[0]) { const color1 = item.cardColors[0]; const color2 = item.cardColors[1] || item.cardColors[0]; backgroundStyle = `style="background-image: linear-gradient(to bottom, ${color1}, ${color2});"`; }
                const cardHtml = `
                <div class="card rounded-xl shadow-md overflow-hidden transition-all card-hover border" style="border-color:var(--border-color);">
                    <div class="relative" ${backgroundStyle}><img src="${item.images.featured || item.images.icon}" alt="${item.name}" class="w-full aspect-square object-cover"><div class="absolute bottom-2 left-2 vbucks-container text-white px-2 py-1 rounded-md flex items-center gap-2"><img src="https://fortnite-api.com/images/vbuck.png" class="w-5 h-5"><span class="font-bold text-lg">${entry.finalPrice}</span></div>${discount ? `<span class="discount-badge bg-green-500 text-white text-xs font-bold px-2 py-1 rounded-md">-${discountPercentage}%</span>` : ""}</div>
                    <div class="p-4">
                        <h3 class="font-bold text-base truncate pr-2">${item.name}</h3>
                        <p class="text-xs mb-3" style="color:var(--text-secondary);">${item.type.displayValue}</p>
                        <div class="price-row my-4">
                            <div class="price-item"><div class="label">Precio</div><div class="value price-sale">${discount ? `<span class="price-original text-xs line-through">S/ ${prices.originalPrice.toFixed(2)}</span><br>`: ''}S/ ${finalPrice.toFixed(2)}</div></div>
                            <div class="price-item"><div class="label">Base</div><div class="value price-base">S/ ${prices.basePrice.toFixed(2)}</div></div>
                            <div class="price-item"><div class="label">Ganancia</div><div class="value ${profit >= 0 ? "profit-positive" : "profit-negative"}">S/ ${profit.toFixed(2)}</div></div>
                        </div>
                        <div class="mt-4 flex justify-between text-sm font-semibold">${discount ? `<button onclick="editDiscount('${discount.id}')" class="text-white bg-blue-600 hover:bg-blue-700 rounded-md px-3 py-1.5 flex-1 text-center transition-colors"><i class="fas fa-edit mr-1"></i>Editar</button><button onclick="deleteDiscount('${discount.id}')" class="text-white bg-red-600 hover:bg-red-700 rounded-md px-3 py-1.5 flex-1 ml-2 text-center transition-colors"><i class="fas fa-trash mr-1"></i>Quitar</button>` : `<button onclick="initiateAddDiscount({ estrategia: 'producto_especifico', valorInicial: '${entry.offerId}' })" class="text-white bg-green-600 hover:bg-green-700 rounded-md px-3 py-1.5 w-full text-center transition-colors"><i class="fas fa-plus mr-1"></i>Añadir Descuento</button>`}</div>
                    </div>
                </div>`;
                dom.shopItemsContainer.innerHTML += cardHtml;
            });
        });
        if (itemsFound === 0 && searchTerm) { dom.shopItemsContainer.innerHTML = `<div class="col-span-full text-center p-8" style="color:var(--text-secondary);">No se encontraron objetos con el nombre "${searchTerm}".</div>`; }
    };
    
    // --- Funciones de Lógica del Modal y Formularios ---
    const openPriceModal = () => dom.priceSettingsModal.classList.remove('hidden');
    const closePriceModal = () => dom.priceSettingsModal.classList.add('hidden');
    const savePriceSettings = (event) => { event.preventDefault(); const newSalePrice = dom.modalSalePrice.value; const newBasePrice = dom.modalBasePrice.value; localStorage.setItem('banano_salePrice', newSalePrice); localStorage.setItem('banano_basePrice', newBasePrice); loadAndApplyPriceSettings(); closePriceModal(); render(); showToast("Precios actualizados y guardados.", "success"); };
    
    const closeDiscountModal = () => dom.modal.classList.add("hidden");
    
    const initiateAddDiscount = (config = {}) => {
        currentEditingId = null;
        dom.form.reset();
        dom.modalTitle.textContent = "Añadir Nuevo Descuento";
        dom.ruleBuilder.innerHTML = '';
        dom.form.elements.id.value = '';
        createRuleRow(config.estrategia || 'global', config.valorInicial);
        handleDateSelectorsLogic();
        dom.modal.classList.remove("hidden");
    };

    const editDiscount = (discountId) => {
        const discount = appData.discounts.find(d => String(d.id) === String(discountId));
        if (!discount) { return showToast("Descuento no encontrado", "error"); }
        currentEditingId = discountId;
        dom.form.reset();
        dom.modalTitle.textContent = "Editar Descuento";
        for (const key in discount) { if (dom.form.elements[key]) { dom.form.elements[key].value = discount[key]; } }
        dom.form.elements.id.value = discount.id;
        
        dom.tipoInicioSelect.value = discount.fecha_inicio ? 'especifico' : 'inmediato';
        dom.inicioInput.value = discount.fecha_inicio ? discount.fecha_inicio.replace(' ', 'T').slice(0, 16) : '';
        dom.tipoExpiracionSelect.value = discount.fecha_expiracion ? 'exacta' : 'nunca';
        dom.expiracionFieldsContainer.querySelector('[name="fecha_expiracion_exacta"]').value = discount.fecha_expiracion ? discount.fecha_expiracion.replace(' ', 'T').slice(0, 16) : '';
        handleDateSelectorsLogic();

        dom.ruleBuilder.innerHTML = '';
        if(discount.reglas_aplicabilidad && discount.reglas_aplicabilidad.length > 0) {
            discount.reglas_aplicabilidad.forEach(rule => createRuleRow(rule.tipo, rule.valor));
        } else {
             createRuleRow('global');
        }
        dom.modal.classList.remove('hidden');
    };

    const createRuleRow = (type = 'global', value = '') => {
        const row = document.createElement('div');
        row.className = 'grid grid-cols-[1fr,2fr,auto] gap-2 items-center rule-row';
        row.innerHTML = `<select name="estrategia_seleccion" class="p-2 border rounded-lg w-full" style="background-color: var(--primary); border-color: var(--border-color);"></select>
                         <div class="valor-wrapper"></div>
                         <button type="button" class="delete-rule-btn text-red-400 p-2"><i class="fas fa-trash"></i></button>`;
        const estrategiaSelect = row.querySelector('[name="estrategia_seleccion"]');
        const valorWrapper = row.querySelector('.valor-wrapper');
        const estrategias = {'global': 'Global (Toda la tienda)', 'global_sin_estaticos': 'Global (Sin Prod. Estáticos)', 'categoria_api': 'Cat. API', 'seccion_tienda': 'Sección Tienda', 'producto_especifico': 'Prod. Específico'};
        estrategiaSelect.innerHTML = Object.entries(estrategias).map(([key, text]) => `<option value="${key}">${text}</option>`).join('');
        estrategiaSelect.value = type;

        const updateValorOptions = () => {
            const currentType = estrategiaSelect.value;
            valorWrapper.innerHTML = '';
            if (currentType === 'producto_especifico') {
                const itemEntry = appData.shopData.sections.flatMap(s => s.entries).find(e => e.offerId === value);
                const itemName = itemEntry ? itemEntry.items[0].name : 'Producto no encontrado';
                valorWrapper.innerHTML = `<input type="hidden" name="valor_hidden" value="${value}"><span class="p-2 block bg-gray-800 rounded-lg text-sm">${itemName}</span>`;
            } else if (!currentType.startsWith('global')) {
                const optionsMap = { 
                    'categoria_api': appData.apiCategories.map(c => ({v: c, t: c})), 
                    'seccion_tienda': appData.shopData.sections.map(s => ({v: s.name, t: s.name})),
                };
                const select = document.createElement('select');
                select.name = 'valor_seleccion';
                select.className = 'p-2 border rounded-lg w-full';
                select.style = "background-color: var(--primary); border-color: var(--border-color);";
                select.innerHTML = (optionsMap[currentType] || []).map(o => `<option value="${o.v}">${o.t}</option>`).join('');
                select.value = value;
                valorWrapper.appendChild(select);
            }
        };
        estrategiaSelect.addEventListener('change', updateValorOptions);
        row.querySelector('.delete-rule-btn').addEventListener('click', () => row.remove());
        updateValorOptions();
        dom.ruleBuilder.appendChild(row);
    };

    const handleDateSelectorsLogic = () => {
        dom.inicioInput.classList.toggle('hidden', dom.tipoInicioSelect.value === 'inmediato');
        Array.from(dom.expiracionFieldsContainer.children).forEach(child => child.classList.add('hidden'));
        switch(dom.tipoExpiracionSelect.value) {
            case 'especifica': dom.expiracionFieldsContainer.querySelector('[name="fecha_expiracion_especifica"]').classList.remove('hidden'); break;
            case 'temporizador': document.getElementById('temporizador_container').classList.remove('hidden'); break;
            case 'exacta': dom.expiracionFieldsContainer.querySelector('[name="fecha_expiracion_exacta"]').classList.remove('hidden'); break;
        }
    };
    
    const saveDiscount = async (event) => {
        event.preventDefault();
        const formData = new FormData(dom.form);
        const rulesData = Array.from(dom.ruleBuilder.querySelectorAll('.rule-row')).map(row => {
            const tipo = row.querySelector('[name="estrategia_seleccion"]').value;
            let valor = tipo;
            if (!tipo.startsWith('global')) {
                valor = row.querySelector('[name="valor_hidden"]')?.value || row.querySelector('[name="valor_seleccion"]')?.value;
            }
            return { tipo, valor };
        });
        formData.append('reglas_aplicabilidad', JSON.stringify(rulesData));
        
        try {
            const data = Object.fromEntries(formData);
            const result = await apiCall('save_discount', data);
            showToast(result.message, "success");
            closeDiscountModal();
            fetchData();
        } catch (error) {
            showToast(error.message, "error");
        }
    };

    const deleteDiscount = async (discountId) => {
        if (!confirm("¿Estás seguro de que quieres eliminar este descuento?")) return;
        try {
            const result = await apiCall('delete_discount', { id: discountId });
            showToast(result.message, "success");
            fetchData();
        } catch (error) {
            showToast(error.message, "error");
        }
    };
    
    const updateShopStatus = (status, message = '') => { const statusClasses = { loading: 'bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200', success: 'bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-200', error: 'bg-red-100 dark:bg-red-900/50 text-red-800 dark:text-red-200', }; const statusIcons = { loading: 'fas fa-sync-alt fa-spin', success: 'fas fa-check-circle', error: 'fas fa-exclamation-triangle', }; dom.shopStatus.className = `p-2 rounded-lg text-sm ${statusClasses[status]}`; dom.shopStatus.innerHTML = `<i class="${statusIcons[status]} mr-1"></i> ${message}`; };
    const showToast = (message, type = "success") => { dom.toastMessage.textContent = message; dom.toastIcon.className = type === "success" ? "fas fa-check-circle text-green-500 text-xl" : "fas fa-times-circle text-red-500 text-xl"; dom.toast.classList.remove("hidden"); setTimeout(() => dom.toast.classList.add("hidden"), 4000); };
    
    document.addEventListener("DOMContentLoaded", () => {
        loadAndApplyPriceSettings();
        dom.openPriceModalBtn.addEventListener('click', openPriceModal);
        dom.priceSettingsForm.addEventListener('submit', savePriceSettings);
        dom.updateShopBtn.addEventListener("click", fetchData);
        dom.shopSearchInput.addEventListener("input", renderShopGallery);
        dom.addRuleBtn.addEventListener('click', () => createRuleRow());
        dom.tipoInicioSelect.addEventListener('change', handleDateSelectorsLogic);
        dom.tipoExpiracionSelect.addEventListener('change', handleDateSelectorsLogic);
        document.getElementById('saveDiscountBtn').addEventListener('click', saveDiscount);
        fetchData();
    });
</script>

<?php 
include __DIR__ . '/../core/admin_footer.php'; 
?>
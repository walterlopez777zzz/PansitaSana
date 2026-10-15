<?php
// Archivo: gestionar_ofertas_auto.php (v6.0 - THE FINAL CUT)
require_once __DIR__ . '/../core/admin_header.php';
?>
<!-- Estilos -->
<style>
    .tabla-reglas th, .tabla-reglas td { vertical-align: middle; }
    .color-picker-wrapper { display: flex; align-items: center; gap: 1rem; padding: .5rem 0; }
    .color-picker-wrapper input[type=color] { min-width: 2.5rem; height: 2.5rem; padding: .25rem; border: none; border-radius: .375rem; cursor: pointer; background-color: transparent; }
    .modal { z-index: 1040; display: none; }
    .modal.flex { display: flex; }
    .ghost { opacity: 0.5; background: #475569; }
    .handle { cursor: grab; }
    .log-modal-content { white-space: pre-wrap; font-family: monospace; font-size: 0.8rem; background-color: var(--primary); color: var(--text-primary); padding: 1rem; border-radius: 0.5rem; max-height: 70vh; overflow-y: auto; }
    
    /* ===== MEJORA DE ESTILOS PARA MODAL Y TEMAS ===== */
    .modal-input, .modal-select {
        background-color: var(--primary) !important;
        border-color: var(--border-color) !important;
        color: var(--text-primary) !important;
    }
    .modal-select option {
        background-color: var(--primary);
        color: var(--text-primary);
    }
</style>

<div class="space-y-8">
    <!-- Header -->
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div><h1 class="text-3xl font-bold">Constructor de Ofertas Automáticas</h1><p style="color: var(--text-secondary);">Arrastra y suelta las reglas para reordenar.</p></div>
                <div class="flex items-center gap-4 flex-wrap">
            <button id="extrasBtn" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center gap-2"><i class="fas fa-cogs"></i> Extras</button>
            <button id="cleanOffersBtn" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg flex items-center gap-2" title="Elimina todas las ofertas automáticas generadas hoy.">
                <i class="fas fa-broom"></i> Limpiar
            </button>
            <button id="runCronBtn" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg flex items-center gap-2" title="Genera nuevas ofertas sin borrar las actuales. Usa 'Limpiar' primero si es necesario.">
                <i class="fas fa-play-circle"></i> Generar
            </button>
            <button id="addRuleBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center gap-2"><i class="fas fa-plus"></i> Añadir Nueva Regla</button>
        </div>
    </header>

    <!-- Reglas -->
    <section class="card rounded-xl shadow-md p-6">
        <h2 class="text-xl font-semibold mb-4">Reglas de Generación</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y tabla-reglas" style="border-color: var(--border-color);">
                <thead style="background-color: var(--primary);">
                    <tr>
                        <th class="px-2 py-3 text-center text-xs font-medium uppercase tracking-wider w-10">Orden</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Nombre / Estrategia</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Detalle</th>
                        <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider">Visual</th>
                        <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider">Últ. Ejecución</th> <!-- Columna de Estado Mejorada -->
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody id="rulesTableBody" class="divide-y" style="border-color: var(--border-color);"></tbody>
            </table>
        </div>
    </section>
    
    <!-- IDs Generados (Depuración) -->
    <section class="card rounded-xl shadow-md p-6"><h2 class="text-xl font-semibold mb-4">IDs Generados Hoy (Depuración)</h2><div id="debug-ids-container" class="bg-gray-800 text-green-400 font-mono text-xs p-4 rounded-lg overflow-x-auto">Cargando IDs...</div></section>

    <!-- Resultado Visual -->
    <section class="card rounded-xl shadow-md p-6"><h2 class="text-xl font-semibold mb-4">Resultado Visual de Hoy</h2><div id="preview-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4"></div></section>
</div>

<!-- Modales -->
<div id="ruleModal" class="modal fixed inset-0 bg-black bg-opacity-70 items-center justify-center">
    <div class="card rounded-lg shadow-xl max-w-2xl w-full mx-4 flex flex-col max-h-[95vh]">
        <div class="flex justify-between items-center border-b px-6 py-4 flex-shrink-0"><h3 id="modalTitle" class="text-lg font-semibold"></h3><button class="close-modal-btn text-2xl hover:text-red-500">×</button></div>
        <div class="p-6 overflow-y-auto"><form id="ruleForm"><input type="hidden" id="id_regla" name="id"><div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4"><div><h4 class="font-semibold mb-2">Configuración Principal</h4><div><label for="nombre_regla" class="block text-sm font-medium mb-1">Nombre (para ti)</label><input type="text" id="nombre_regla" name="nombre_regla" class="modal-input w-full border rounded-lg py-2 px-3" required></div><div class="mt-4"><label for="estrategia_seleccion" class="block text-sm font-medium mb-1">Estrategia de Selección</label><select id="estrategia_seleccion" name="estrategia_seleccion" class="modal-select w-full border rounded-lg py-2 px-3"></select></div><div id="tipo_articulo_wrapper" class="mt-4 hidden"><label for="tipo_articulo" class="block text-sm font-medium mb-1">Palabra Clave</label><select id="tipo_articulo" name="tipo_articulo" class="modal-select w-full border rounded-lg py-2 px-3"></select></div><div class="grid grid-cols-2 gap-4 mt-4"><div><label for="cantidad_a_elegir" class="block text-sm font-medium mb-1">Cantidad</label><input type="number" id="cantidad_a_elegir" name="cantidad_a_elegir" value="1" min="1" class="modal-input w-full border rounded-lg py-2 px-3" required></div><div><label for="porcentaje_descuento" class="block text-sm font-medium mb-1">Descuento (%)</label><input type="number" id="porcentaje_descuento" name="porcentaje_descuento" value="15" min="1" max="100" class="modal-input w-full border rounded-lg py-2 px-3" required></div></div></div><div><h4 class="font-semibold mb-2">Personalización Visual</h4><div><label for="etiqueta_texto" class="block text-sm font-medium mb-1">Texto Etiqueta</label><input type="text" id="etiqueta_texto" name="etiqueta_texto" class="modal-input w-full border rounded-lg py-2 px-3" value="OFERTA"></div><div class="color-picker-wrapper mt-2"><label class="text-sm">Fondo</label><input type="color" id="etiqueta_color_fondo" name="etiqueta_color_fondo" value="#22c55e"></div><div class="color-picker-wrapper"><label class="text-sm">Texto</label><input type="color" id="etiqueta_color_texto" name="etiqueta_color_texto" value="#ffffff"></div><div class="color-picker-wrapper"><label class="text-sm">Precio</label><input type="color" id="precio_oferta_color" name="precio_oferta_color" value="#22c55e"></div><div class="mt-2"><label for="etiqueta_posicion" class="block text-sm font-medium mb-1">Posición</label><select id="etiqueta_posicion" name="etiqueta_posicion" class="modal-select w-full border rounded-lg py-2 px-3"><option value="1">Arriba</option><option value="2">Abajo</option></select></div></div></div></form></div>
        <div class="flex justify-end space-x-3 border-t px-6 py-4 flex-shrink-0"><button type="button" class="close-modal-btn px-4 py-2 rounded-lg">Cancelar</button><button type="button" id="saveRuleBtn" class="px-4 py-2 text-white rounded-lg" style="background-color:var(--accent);">Guardar Regla</button></div>
    </div>
</div>
<div id="extrasModal" class="modal fixed inset-0 bg-black bg-opacity-70 items-center justify-center"><div class="card rounded-lg shadow-xl max-w-md w-full mx-4 flex flex-col max-h-[95vh]"><div class="flex justify-between items-center border-b px-6 py-4"><h3 class="text-lg font-semibold">Gestionar Tipos de Artículo</h3><button class="close-modal-btn text-2xl hover:text-red-500">×</button></div><div class="p-6 overflow-y-auto"><div id="typesList" class="space-y-2 mb-4"></div><form id="addTypeForm" class="flex gap-2"><input type="text" id="newTypeName" class="modal-input w-full border rounded-lg py-2 px-3" placeholder="Nuevo tipo..."><button type="submit" class="px-4 py-2 text-white rounded-lg" style="background-color:var(--accent);"><i class="fas fa-plus"></i></button></form></div></div></div>
<div id="logModal" class="modal fixed inset-0 bg-black bg-opacity-70 items-center justify-center"><div class="card rounded-lg shadow-xl max-w-3xl w-full mx-4 flex flex-col"><div class="flex justify-between items-center border-b px-6 py-4"><h3 class="text-lg font-semibold">Resultado de la Generación</h3><button class="close-modal-btn text-2xl hover:text-red-500">×</button></div><div class="p-6"><div id="log-content" class="log-modal-content"></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
        const dom = {
        rulesTableBody: document.getElementById('rulesTableBody'),
        debugIdsContainer: document.getElementById('debug-ids-container'),
        previewContainer: document.getElementById('preview-container'),
        cleanOffersBtn: document.getElementById('cleanOffersBtn'), // <-- LÍNEA AÑADIDA
        runCronBtn: document.getElementById('runCronBtn'),
        addRuleBtn: document.getElementById('addRuleBtn'),
        extrasBtn: document.getElementById('extrasBtn'),
        ruleModal: document.getElementById('ruleModal'),
        modalTitle: document.getElementById('modalTitle'),
        ruleForm: document.getElementById('ruleForm'),
        saveRuleBtn: document.getElementById('saveRuleBtn'),
        extrasModal: document.getElementById('extrasModal'),
        typesList: document.getElementById('typesList'),
        addTypeForm: document.getElementById('addTypeForm'),
        estrategiaSelect: document.getElementById('estrategia_seleccion'),
        tipoArticuloWrapper: document.getElementById('tipo_articulo_wrapper'),
        tipoArticuloSelect: document.getElementById('tipo_articulo'),
        logModal: document.getElementById('logModal'),
        logContent: document.getElementById('log-content')
    };
    let allRules = [], allTypes = [], shopItemsMap = {}, generatedOffers = [];
    const estrategias = { todo: 'Global (Todo)', todo_menos_pistas: 'Global (Sin Pistas)', palabra_clave: 'Por Palabra Clave' };

    async function apiCall(action, data = {}, method = 'POST') {
        const url = `ofertas_automaticas/api_ofertas_auto.php?accion=${action}&v=${Date.now()}`;
        const options = { method };
        if (method === 'POST') { const formData = new FormData(); formData.append('accion', action); for (const key in data) { formData.append(key, data[key]); } options.body = formData; }
        try {
            const response = await fetch(url, options);
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Error en la petición');
            if(result.message) showToast(result.message, result.status);
            return result;
        } catch (error) { showToast(error.message, 'error'); return { status: 'error' }; }
    }
    
    function renderDebugIds(ofertas) {
        if (!dom.debugIdsContainer) return;
        if (ofertas.length === 0) { dom.debugIdsContainer.textContent = "No se generaron IDs."; return; }
        dom.debugIdsContainer.innerHTML = ofertas.map(o => `<div>- ${o.offer_id} (Regla: #${o.id_regla} - ${o.nombre_regla})</div>`).join('');
    }

    function renderRulesTable() {
        if (!dom.rulesTableBody) return;
        if (allRules.length === 0) { dom.rulesTableBody.innerHTML = `<tr><td colspan="6" class="px-6 py-10 text-center" style="color: var(--text-secondary);">No has creado ninguna regla.</td></tr>`; return; }
        dom.rulesTableBody.innerHTML = allRules.map(rule => {
            const estrategiaDisplay = estrategias[rule.estrategia_seleccion] || 'Desconocido';
            let detalleDisplay = `<strong>${rule.cantidad_a_elegir}</strong> x <strong>${rule.porcentaje_descuento}%</strong>`;
            if (rule.estrategia_seleccion === 'palabra_clave') { detalleDisplay = `<strong>${rule.cantidad_a_elegir}</strong> x "${rule.tipo_articulo}" @ <strong>${rule.porcentaje_descuento}%</strong>`; }
            
            const generatedForRule = generatedOffers.filter(o => o.id_regla == rule.id).length;
            const executionStatus = `<div class="flex items-center justify-center gap-2 ${generatedForRule > 0 ? 'text-green-500' : 'text-yellow-500'}">
                <i class="fas ${generatedForRule > 0 ? 'fa-check-circle' : 'fa-hourglass-half'}"></i>
                <span class="font-semibold">${generatedForRule} / ${rule.cantidad_a_elegir}</span>
            </div>`;
            
            const visualPreview = `<div class="flex items-center justify-center gap-2" title="${rule.etiqueta_texto}"><div class="w-4 h-4 rounded-full border" style="background-color:${rule.etiqueta_color_fondo};"></div><i class="fas fa-palette" style="color: ${rule.etiqueta_color_texto};"></i><i class="fas fa-tag" style="color: ${rule.precio_oferta_color};"></i></div>`;

            return `<tr data-id="${rule.id}" class="${rule.activo == 1 ? '' : 'opacity-40'}"><td class="px-2 py-3 text-center handle"><i class="fas fa-grip-vertical text-gray-400"></i></td><td class="px-4 py-3"><div class="font-medium">${rule.nombre_regla}</div><div class="text-xs" style="color: var(--text-secondary);">${estrategiaDisplay}</div></td><td class="px-4 py-3 text-sm">${detalleDisplay}</td><td class="px-4 py-3 text-center">${visualPreview}</td><td class="px-4 py-3 text-center">${executionStatus}</td><td class="px-4 py-3 text-right"><button data-id="${rule.id}" class="edit-btn text-blue-500 hover:text-blue-600 p-2 text-lg" title="Editar Regla"><i class="fas fa-edit"></i></button><label class="inline-flex items-center cursor-pointer mx-1"><input type="checkbox" data-id="${rule.id}" class="toggle-status-btn sr-only peer" ${rule.activo == 1 ? 'checked' : ''}><div class="relative w-9 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer peer-checked:after:translate-x-full after:bg-white after:border after:rounded-full after:h-4 after:w-4 after:transition-all after:top-[2px] after:start-[2px] peer-checked:bg-green-500"></div></label><button data-id="${rule.id}" class="delete-btn text-red-500 hover:text-red-600 p-2 text-lg" title="Eliminar Regla"><i class="fas fa-trash"></i></button></td></tr>`;
        }).join('');
    }
    
    function renderPreview(ofertas) {
        if (ofertas.length === 0) { dom.previewContainer.innerHTML = `<div class="col-span-full px-6 py-10 text-center" style="color: var(--text-secondary);">No se generaron ofertas hoy. Puedes forzar la generación.</div>`; return; }
        dom.previewContainer.innerHTML = ofertas.map(oferta => {
            const item = shopItemsMap[oferta.offer_id];
            if (!item) return `<div class="card rounded-lg p-3 text-center text-red-500 text-xs">Error: No se encontró el item con ID ${oferta.offer_id} en la tienda actual.</div>`;
            const imageUrl = item.displayAssets?.[0]?.url || item.granted?.[0]?.images?.icon || 'https://via.placeholder.com/256';
            let backgroundStyle = ''; const colors = item.colors;
            if (colors && colors.color1 && colors.color2) { backgroundStyle = `style="background: linear-gradient(to bottom, ${colors.color1}, ${colors.color2});"`; } 
            else if (colors && colors.color1) { backgroundStyle = `style="background-color: ${colors.color1};"`; }
            return `<div class="card rounded-lg shadow-md overflow-hidden"><div class="relative aspect-square" ${backgroundStyle}><img src="${imageUrl}" alt="${item.displayName}" class="w-full h-full object-contain p-4"><div class="absolute bottom-1 right-1 bg-black/50 text-white text-[10px] px-1.5 py-0.5 rounded" title="Regla: ${oferta.nombre_regla}">#${oferta.id_regla}</div><span class="absolute top-2 right-2 text-white text-xs font-bold px-2 py-1 rounded-md" style="background-color: ${oferta.etiqueta_color_fondo}; color: ${oferta.etiqueta_color_texto};">${oferta.etiqueta_texto}</span></div><div class="p-3"><h4 class="font-bold truncate text-sm">${item.displayName}</h4><p class="text-sm font-bold mt-1" style="color: ${oferta.precio_oferta_color};">-${oferta.porcentaje_descuento}%</p></div></div>`;
        }).join('');
    }

    function renderTypesList() { dom.typesList.innerHTML = allTypes.map(t => `<div class="flex justify-between items-center p-2 rounded hover:bg-gray-500/10"><span>${t.nombre_tipo}</span><button data-id="${t.id}" class="delete-type-btn text-red-500 hover:text-red-700 text-sm"><i class="fas fa-times-circle"></i></button></div>`).join(''); }

    function openModal(modalElement) { modalElement.classList.add('flex'); }
    function closeModal(modalElement) { modalElement.classList.remove('flex'); }
    
    function openRuleModal(rule = null) {
        dom.ruleForm.reset();
        dom.estrategiaSelect.innerHTML = Object.keys(estrategias).map(key => `<option value="${key}">${estrategias[key]}</option>`).join('');
        dom.tipoArticuloSelect.innerHTML = allTypes.map(t => `<option value="${t.nombre_tipo}">${t.nombre_tipo}</option>`).join('');
        if (rule) {
            dom.modalTitle.textContent = 'Editar Regla'; dom.ruleForm.elements['id'].value = rule.id;
            for (const key in rule) { if (dom.ruleForm.elements[key]) dom.ruleForm.elements[key].value = rule[key]; }
            dom.estrategiaSelect.value = rule.estrategia_seleccion;
            if(rule.estrategia_seleccion === 'palabra_clave') dom.tipoArticuloSelect.value = rule.tipo_articulo;
        } else { dom.modalTitle.textContent = 'Añadir Nueva Regla'; }
        dom.tipoArticuloWrapper.classList.toggle('hidden', dom.estrategiaSelect.value !== 'palabra_clave');
        openModal(dom.ruleModal);
    }
    
    async function handleSaveRule(event) {
        event.preventDefault();
        const formData = new FormData(dom.ruleForm);
        const result = await apiCall('save_regla', Object.fromEntries(formData));
        if (result.status === 'success') { closeModal(dom.ruleModal); await initializePage(); }
    }
    
async function initializePage() {
    // Usamos Promise.all para cargar la configuración, la tienda y las ofertas generadas en paralelo.
    const [configResult, shopResponse, previewResult] = await Promise.all([
        apiCall('get_config_data', {}, 'GET'),
        // La tienda se sirve desde el backend interno (banano_primordial.json),
        // ya NO se contacta la API externa ni se expone ninguna clave.
        apiCall('get_shop_entries', {}, 'GET'),
        apiCall('get_ofertas_hoy', {}, 'GET')
    ]);

    // Procesar los datos de configuración (reglas y tipos) - Sin cambios
    if (configResult.status === 'success') {
        allRules = configResult.data.reglas;
        allTypes = configResult.data.tipos;
        renderTypesList();
    }

    // --- ESTRUCTURA DE TIENDA DESDE EL BACKEND INTERNO ---
    if (shopResponse && shopResponse.status === 'success') {
        const shopData = shopResponse;
        if (shopData && shopData.data && shopData.data.entries) {
            shopItemsMap = shopData.data.entries.reduce((map, entry) => {
                // Adaptamos la estructura para que coincida con lo que la función renderPreview espera.
                // Esto asegura que el resto del código no necesite cambios.
                const mainItem = entry.brItems?.[0] || {};
                const trackItem = entry.tracks?.[0] || {};

                // Construimos un objeto 'item' unificado que renderPreview pueda entender
                const unifiedItem = {
                    offerId: entry.offerId,
                    displayName: entry.bundle?.name || mainItem.name || trackItem.title || 'Artículo Desconocido',
                    // Para la imagen, priorizamos la de la tarjeta de la tienda, luego la del item/track
                    displayAssets: [{ 
                        url: entry.newDisplayAsset?.renderImages?.[0]?.image || mainItem.images?.featured || trackItem.albumArt || 'https://via.placeholder.com/256'
                    }],
                    // Para los colores, los convertimos al formato #RRGGBB
                    colors: (entry.colors?.color1 && entry.colors?.color2) ? {
                        color1: '#' + entry.colors.color1.slice(0, 6),
                        color2: '#' + entry.colors.color2.slice(0, 6)
                    } : null
                };
                
                map[entry.offerId] = unifiedItem;
                return map;
            }, {});
        }
    } else {
        console.error("Error al cargar la tienda desde el backend interno", shopResponse);
        showToast("Error crítico: No se pudo cargar la tienda.", "error");
    }
    // --- FIN DEL BLOQUE ---

    // Procesar las ofertas generadas y renderizar todo - Sin cambios
    if (previewResult.status === 'success') {
        generatedOffers = previewResult.data;
        renderDebugIds(generatedOffers);
        renderPreview(generatedOffers);
    }
    
    // Renderizar la tabla de reglas al final para tener los datos de ejecución
    renderRulesTable();
}
    
    function showToast(message, type = "success") { if (!message) return; const container = document.getElementById("toastContainer") || document.body; const toast = document.createElement("div"); toast.className = `fixed bottom-4 right-4 max-w-xs p-4 rounded-lg shadow-lg text-white transform translate-x-full opacity-0 transition-all duration-300 z-50 ${type === "success" ? "bg-green-500" : "bg-red-500"}`; toast.innerHTML = `<p>${message}</p>`; container.appendChild(toast); requestAnimationFrame(() => toast.classList.remove("translate-x-full", "opacity-0")); setTimeout(() => { toast.classList.add("translate-x-full", "opacity-0"); toast.addEventListener("transitionend", () => toast.remove()); }, 4000); }

    // Event Listeners
    dom.runCronBtn.addEventListener('click', async () => {
        dom.runCronBtn.disabled = true; dom.runCronBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Generando...';
        const result = await apiCall('forzar_generacion', {});
        if (result.status === 'success') { dom.logContent.textContent = result.log; openModal(dom.logModal); await initializePage(); }
        dom.runCronBtn.disabled = false; dom.runCronBtn.innerHTML = '<i class="fas fa-play-circle"></i> Forzar Generación';
    });
        dom.cleanOffersBtn.addEventListener('click', async () => {
        if (!confirm('¿Estás seguro de que quieres limpiar TODAS las ofertas automáticas generadas?')) {
            return;
        }
        
        dom.cleanOffersBtn.disabled = true;
        dom.cleanOffersBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Limpiando...';
        
        const result = await apiCall('limpiar_ofertas', {});
        
        if (result.status === 'success') {
            await initializePage(); // Recargamos la página para ver el resultado
        }

        dom.cleanOffersBtn.disabled = false;
        dom.cleanOffersBtn.innerHTML = '<i class="fas fa-broom"></i> Limpiar';
    });
    dom.addRuleBtn.addEventListener('click', () => openRuleModal());
    dom.extrasBtn.addEventListener('click', () => openModal(dom.extrasModal));
    document.querySelectorAll('.close-modal-btn').forEach(btn => { btn.addEventListener('click', () => { btn.closest('.modal').classList.remove('flex'); }); });
    dom.saveRuleBtn.addEventListener('click', handleSaveRule);
    dom.estrategiaSelect.addEventListener('change', () => { dom.tipoArticuloWrapper.classList.toggle('hidden', dom.estrategiaSelect.value !== 'palabra_clave'); });
    dom.rulesTableBody.addEventListener('click', async (e) => {
        const target = e.target; const button = target.closest('button.edit-btn, button.delete-btn'); const toggle = target.closest('input.toggle-status-btn');
        if (button) { const id = button.dataset.id; if (button.classList.contains('edit-btn')) { openRuleModal(allRules.find(r => r.id == id)); } else if (button.classList.contains('delete-btn')) { if (confirm('¿Eliminar esta regla?')) { if ((await apiCall('delete_regla', {id})).status === 'success') await initializePage(); } }
        } else if (toggle) { const id = toggle.dataset.id; const status = toggle.checked ? 1 : 0; const result = await apiCall('toggle_status', {id, status}); if (result.status !== 'success') toggle.checked = !status; else allRules.find(r=>r.id==id).activo = status; }
    });
    dom.addTypeForm.addEventListener('submit', async (e) => { e.preventDefault(); const newTypeName = document.getElementById('newTypeName'); if (newTypeName.value.trim()) { if ((await apiCall('save_tipo', { nombre_tipo: newTypeName.value.trim() })).status === 'success') { newTypeName.value = ''; await initializePage(); } } });
    dom.typesList.addEventListener('click', async (e) => { const deleteBtn = e.target.closest('button.delete-type-btn'); if (deleteBtn) { if (confirm('¿Eliminar este tipo?')) { if ((await apiCall('delete_tipo', { id: deleteBtn.dataset.id })).status === 'success') await initializePage(); } } });
    new Sortable(dom.rulesTableBody, { animation: 150, handle: '.handle', ghostClass: 'ghost', onEnd: async function () { const newOrder = Array.from(dom.rulesTableBody.children).map((row, index) => ({ id: row.dataset.id, order: index + 1 })); await apiCall('reorder_reglas', { order: JSON.stringify(newOrder) }); await initializePage(); }});

    initializePage();
});
</script>

<?php 
require_once __DIR__ . '/../core/admin_footer.php';
?>
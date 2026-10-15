<?php
// Archivo: wabo/configuracion/gestionar_configuracion.php (Versión 3.2 - Payload POST Corregido)
require_once __DIR__ . '/../core/admin_header.php';
?>

<!-- Estilos para la nueva interfaz (sin cambios) -->
<style>
    /* ... (todo el CSS se queda igual que antes) ... */
    .settings-list { display: flex; flex-direction: column; gap: 0.5rem; }
    .setting-item { display: flex; justify-content: space-between; align-items: center; padding: 1rem; border-radius: 0.5rem; background-color: var(--primary); border: 1px solid var(--border-color); }
    .setting-info .name { font-weight: 600; color: var(--text-primary); }
    .setting-info .description-hint { font-size: 0.875rem; color: var(--text-secondary); }
    .setting-value { font-weight: 700; font-size: 1rem; color: var(--text-primary); }
    .setting-value.active { color: #22c55e; }
    .setting-value.inactive { color: #ef4444; }
    .edit-btn { background-color: transparent; border: 1px solid var(--border-color); color: var(--text-secondary); border-radius: 0.5rem; padding: 0.5rem 0.75rem; transition: all 0.2s; }
    .edit-btn:hover { background-color: var(--secondary); color: var(--text-primary); }
    #editModal { background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px); }
    #modalDescription { font-size: 0.9rem; color: var(--text-secondary); border-left: 3px solid var(--border-color); padding-left: 1rem; margin-top: 0.5rem; }
    #modalInputContainer input, #modalInputContainer select, #modalInputContainer textarea { width: 100%; padding: 0.75rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background-color: var(--secondary); color: var(--text-primary); margin-top: 1rem; }
    #modalInputContainer input:focus, #modalInputContainer select:focus, #modalInputContainer textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.3); }
</style>

<div class="space-y-8">
    <!-- Header de la página (sin cambios) -->
    <header>
        <h1 class="text-3xl font-bold">Configuración de la Tienda</h1>
        <p style="color: var(--text-secondary);">Visualiza y edita los parámetros de tu tienda.</p>
    </header>

    <!-- Contenedor para las tarjetas de configuración (sin cambios) -->
    <div id="settingsContainer" class="space-y-6">
        <div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl"></i> Cargando configuración...</div>
    </div>
</div>

<!-- Toast y Modal (sin cambios) -->
<div id="toast" class="fixed bottom-4 right-4 z-50 hidden max-w-xs card rounded-lg shadow-lg p-4 flex items-center gap-3"><i id="toastIcon" class="text-xl"></i><p id="toastMessage" class="text-sm font-medium"></p></div>
<div id="editModal" class="fixed inset-0 z-50 flex items-center justify-center hidden">
    <div class="card rounded-xl shadow-xl w-full max-w-lg mx-4 z-10">
        <form id="editForm">
            <div class="p-6">
                <h3 id="modalTitle" class="text-xl font-bold"></h3>
                <p id="modalDescription" class="mt-2"></p>
                <div id="modalInputContainer" class="mt-4"></div>
            </div>
            <div class="px-6 py-4 flex justify-end gap-3" style="background-color: var(--primary);">
                <button type="button" id="cancelButton" class="px-4 py-2 rounded-md font-semibold hover:bg-gray-200 dark:hover:bg-gray-700">Cancelar</button>
                <button type="submit" id="confirmSaveButton" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-semibold">Guardar Cambio</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const settingsContainer = document.getElementById('settingsContainer');
    const modal = document.getElementById('editModal');
    const editForm = document.getElementById('editForm');
    const modalTitle = document.getElementById('modalTitle');
    const modalDescription = document.getElementById('modalDescription');
    const modalInputContainer = document.getElementById('modalInputContainer');
    const cancelButton = document.getElementById('cancelButton');
    const confirmSaveButton = document.getElementById('confirmSaveButton');
    
    let allSettings = {};
    let currentEditingKey = null;

    const settingsMap = [
        {
            title: 'Estado y Modos de Operación',
            keys: [
                { key: 'modo_tienda', name: 'Modo de la Tienda', type: 'select', options: {'normal': '🟢 Normal', 'alta_demanda': '🟠 Alta Demanda', 'ausente': '⚪ Ausente', 'mantenimiento': '🔴 Mantenimiento'} },
                { key: 'mensaje_mantenimiento', name: 'Mensaje de Mantenimiento', type: 'textarea' },
                { key: 'alerta_sin_regalos_activa', name: 'Alerta por Falta de Regalos', type: 'toggle' },
                { key: 'alerta_sin_regalos_mensaje', name: 'Mensaje de Alerta (Falta Regalos)', type: 'textarea' }
            ]
        },
        {
            title: 'Precios y Métodos de Pago',
            keys: [
                { key: 'precio_por_100_pavos', name: 'Precio Base por 100 V-Bucks', type: 'number', prefix: 'S/ ' },
                { key: 'habilitar_izipay', name: 'Habilitar IziPay', type: 'toggle' },
                { key: 'comision_izipay_fija', name: 'Comisión Fija IziPay', type: 'number', prefix: 'S/ ' },
                { key: 'comision_izipay_porcentaje', name: 'Comisión Porcentaje IziPay', type: 'number', suffix: '%' },
                { key: 'habilitar_yape_plin', name: 'Habilitar Yape/Plin', type: 'toggle' },
                { key: 'habilitar_transferencia', name: 'Habilitar Transferencia', type: 'toggle' },
                { key: 'habilitar_paypal', name: 'Habilitar PayPal', type: 'toggle' },
                { key: 'habilitar_crypto', name: 'Habilitar Crypto', type: 'toggle' },
                { key: 'comision_crypto_fija', name: 'Comisión Fija Crypto', type: 'number', prefix: 'S/ ' },
                { key: 'comision_crypto_porcentaje', name: 'Comisión Porcentaje Crypto', type: 'number', suffix: '%' }
            ]
        },
        {
            title: 'Reglas de Negocio y Tiempos',
            keys: [
                { key: 'regalos_diarios_totales', name: 'Límite de Regalos Diarios', type: 'number' },
                { key: 'forzar_compras_abiertas', name: 'Forzar Compras Abiertas', type: 'toggle' },
                { key: 'habilitar_boton_he_pagado', name: 'Mostrar Botón "Ya realicé el pago"', type: 'toggle' },
                { key: 'tiempo_reserva_normal_min', name: 'Reserva Pedido Normal (min)', type: 'number', suffix: ' min' },
                { key: 'tiempo_reserva_alta_demanda_min', name: 'Reserva Alta Demanda (min)', type: 'number', suffix: ' min' },
                { key: 'tiempo_reserva_seguridad_min', name: 'Colchón de Seguridad (min)', type: 'number', suffix: ' min' },
                { key: 'umbral_alerta_expiracion_min', name: 'Alerta "Última Oportunidad" (min)', type: 'number', suffix: ' min' }
            ]
        }
    ];

    const showToast = (message, type = "success") => {
        const toast = document.getElementById("toast");
        const toastIcon = document.getElementById("toastIcon");
        const toastMessage = document.getElementById("toastMessage");
        toastMessage.textContent = message;
        toastIcon.className = type === "success" ? "fas fa-check-circle text-green-500 text-xl" : "fas fa-times-circle text-red-500 text-xl";
        toast.classList.remove("hidden");
        setTimeout(() => toast.classList.add("hidden"), 4000);
    };

    const formatValue = (value, config) => {
        if (config.type === 'toggle') {
            return value === '1' ? `<span class="setting-value active">Activado</span>` : `<span class="setting-value inactive">Desactivado</span>`;
        }
        let displayValue = value;
        if (config.type === 'select' && config.options) {
             displayValue = config.options[value] || value;
        }
        return `<span class="setting-value">${config.prefix || ''}${displayValue}${config.suffix || ''}</span>`;
    };

    const renderSettings = () => {
        settingsContainer.innerHTML = '';
        settingsMap.forEach(section => {
            const sectionCard = document.createElement('section');
            sectionCard.className = 'card rounded-xl shadow-md p-6';
            
            let itemsHTML = '';
            section.keys.forEach(config => {
                const settingData = allSettings[config.key];
                if (settingData) {
                    itemsHTML += `
                        <div class="setting-item" data-key="${config.key}">
                            <div class="setting-info">
                                <span class="name">${config.name}</span>
                            </div>
                            <div class="flex items-center gap-4">
                                ${formatValue(settingData.valor, config)}
                                <button class="edit-btn" data-key="${config.key}">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                            </div>
                        </div>
                    `;
                }
            });

            sectionCard.innerHTML = `
                <h2 class="text-xl font-semibold mb-4 border-l-4 pl-3" style="border-color: var(--accent);">${section.title}</h2>
                <div class="settings-list">${itemsHTML}</div>
            `;
            settingsContainer.appendChild(sectionCard);
        });
    };

    const openEditModal = (key) => {
        currentEditingKey = key;
        const config = settingsMap.flatMap(s => s.keys).find(k => k.key === key);
        const data = allSettings[key];

        modalTitle.textContent = config.name;
        modalDescription.textContent = data.descripcion || 'Edita el valor de esta configuración.';
        
        let inputHTML = '';
        switch(config.type) {
            case 'textarea':
                inputHTML = `<textarea id="modalInput" class="min-h-[100px]">${data.valor}</textarea>`;
                break;
            case 'select':
                const options = Object.entries(config.options).map(([val, text]) => 
                    `<option value="${val}" ${val === data.valor ? 'selected' : ''}>${text}</option>`
                ).join('');
                inputHTML = `<select id="modalInput">${options}</select>`;
                break;
            case 'toggle':
                inputHTML = `
                    <label class="toggle-switch inline-block">
                        <input type="checkbox" id="modalInput" ${data.valor === '1' ? 'checked' : ''}>
                        <span class="slider"></span>
                    </label>
                `;
                break;
            default: // number, text
                inputHTML = `<input type="${config.type || 'text'}" id="modalInput" value="${data.valor}" step="0.01">`;
        }
        modalInputContainer.innerHTML = inputHTML;
        modal.classList.remove('hidden');
    };

    const handleSave = async (event) => {
        event.preventDefault();
        const input = document.getElementById('modalInput');
        let newValue;
        
        if (input.type === 'checkbox') {
            newValue = input.checked ? '1' : '0';
        } else {
            newValue = input.value;
        }

        // ====================== ¡CORRECCIÓN #1 AQUÍ! ======================
        // Preparamos el payload que se enviará en el cuerpo de la petición.
        const payload = {
            action: 'save',
            [currentEditingKey]: newValue
        };
        // ================================================================

        confirmSaveButton.disabled = true;
        confirmSaveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

        try {
            // ====================== ¡CORRECCIÓN #2 AQUÍ! ======================
            // La URL ya no contiene el parámetro 'action'.
            const response = await fetch('configuracion/api_configuracion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload) // Enviamos el payload completo.
            });
            // ================================================================

            const result = await response.json();
            if (!response.ok) throw new Error(result.message);
            
            allSettings[currentEditingKey].valor = newValue;
            const itemToUpdate = document.querySelector(`.setting-item[data-key="${currentEditingKey}"]`);
            const config = settingsMap.flatMap(s => s.keys).find(k => k.key === currentEditingKey);
            itemToUpdate.querySelector('.flex.items-center.gap-4').innerHTML = `
                ${formatValue(newValue, config)}
                <button class="edit-btn" data-key="${currentEditingKey}">
                    <i class="fas fa-pencil-alt"></i>
                </button>
            `;
            
            showToast(result.message, 'success');
            modal.classList.add('hidden');
        } catch (error) {
            showToast(error.message || 'Error desconocido al guardar', 'error');
        } finally {
            confirmSaveButton.disabled = false;
            confirmSaveButton.innerHTML = 'Guardar Cambio';
        }
    };

    const init = async () => {
        try {
            const response = await fetch('configuracion/api_configuracion.php?action=get_all');
            const result = await response.json();
            if (!response.ok) throw new Error(result.message);
            
            allSettings = result.settings;
            renderSettings();
        } catch (error) {
            settingsContainer.innerHTML = `<div class="text-center py-8 text-red-500">Error al cargar la configuración: ${error.message || 'La respuesta de la API no es válida.'}</div>`;
        }
    };

    settingsContainer.addEventListener('click', (e) => {
        const button = e.target.closest('.edit-btn');
        if (button) {
            openEditModal(button.dataset.key);
        }
    });

    cancelButton.addEventListener('click', () => modal.classList.add('hidden'));
    editForm.addEventListener('submit', handleSave);

    init();
});
</script>

<?php 
include __DIR__ . '/../core/admin_footer.php'; 
?>
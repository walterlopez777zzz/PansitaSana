<?php
require_once __DIR__ . '/../core/admin_header.php';
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

    .tabla-pedidos tbody tr:hover {
        background-color: var(--orders-row-hover-bg);
    }

    #editModal .card {
        background-color: var(--orders-modal-bg) !important;
        border: 1px solid var(--orders-modal-border);
    }

    #editModal { background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px); }
    #modalDescription { font-size: 0.9rem; color: var(--orders-modal-text-secondary); border-left: 3px solid var(--orders-modal-border); padding-left: 1rem; margin-top: 0.5rem; }
    #modalInputContainer input, #modalInputContainer select, #modalInputContainer textarea { width: 100%; padding: 0.75rem; border-radius: 0.5rem; border: 1px solid var(--orders-modal-border); background-color: var(--orders-modal-surface); color: var(--orders-modal-text-primary); margin-top: 1rem; }
    #modalInputContainer input:focus, #modalInputContainer select:focus, #modalInputContainer textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.3); }

    .switch {
        position: relative;
        display: inline-block;
        width: 48px;
        height: 24px;
    }
    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 24px;
    }
    .slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }
    input:checked + .slider {
        background-color: #22C55E;
    }
    input:checked + .slider:before {
        transform: translateX(24px);
    }

    @media (max-width: 768px) {
        .tabla-pedidos thead { display: none; }

        .tabla-pedidos tr {
            display: block;
            margin-bottom: 1rem;
            border: 1px solid var(--orders-border);
            border-radius: 0.75rem;
            overflow: hidden;
            background-color: var(--orders-row-bg);
        }

        .tabla-pedidos td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            border-bottom: 1px dashed var(--orders-border);
            text-align: right;
            gap: 1rem;
        }

        .tabla-pedidos td:last-child { border-bottom: none; }

        .tabla-pedidos td::before {
            content: attr(data-label);
            font-weight: 600;
            text-align: left;
            color: var(--orders-text-header);
            flex: 0 0 auto;
            max-width: 45%;
        }

        #filters-container { flex-direction: column; align-items: stretch; }
        #filters-container > div { justify-content: center; width: 100%; }
        #additional-filters { flex-direction: column; width: 100%; }
        #additional-filters > * { width: 100%; }
    }

    @media (min-width: 769px) {
        .tabla-pedidos { border-collapse: separate; border-spacing: 0 10px; }

        .tabla-pedidos tbody tr { background-color: transparent; border-bottom: none; }

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

        .tabla-pedidos tbody tr:hover td {
            background-color: var(--orders-row-hover-bg);
        }
    }
</style>

<link rel="stylesheet" href="/shared/css/order_panels_shared.css">

<div class="space-y-8">
    <header>
        <h1 class="text-3xl font-bold">Configuración Usuarios</h1>
        <p style="color: var(--text-secondary);">Visualiza y edita los parámetros guardados en <span class="font-mono">site_settings</span>.</p>
    </header>

    <div class="orders-card rounded-lg shadow-md p-4 mb-2 transition-colors duration-200">
        <div id="filters-container" class="flex flex-wrap items-center justify-between gap-4">
            <div class="relative flex-grow max-w-md">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400"></i>
                </div>
                <input type="text" id="searchInput" class="block w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-fortnite-blue focus:border-transparent transition" placeholder="Buscar por key o value...">
            </div>

            <div id="additional-filters" class="flex items-center space-x-2">
                <div class="relative">
                    <select id="sortOrder" class="appearance-none bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-white py-2 px-4 pr-8 rounded-lg leading-tight focus:outline-none focus:ring-2 focus:ring-fortnite-blue focus:border-transparent transition">
                        <option value="key_asc" selected>A-Z (Key)</option>
                        <option value="key_desc">Z-A (Key)</option>
                        <option value="updated_desc">Más recientes</option>
                        <option value="updated_asc">Más antiguos</option>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700 dark:text-gray-300">
                        <i class="fas fa-chevron-down text-xs"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="orders-card rounded-lg shadow-md transition-colors duration-200">
        <div class="overflow-x-auto">
            <table class="min-w-full tabla-pedidos">
                <thead>
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Key Name</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Current Value</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Updated</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody id="settingsContainer">
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center" style="color: var(--text-secondary);"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando configuración...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div id="no-results" class="text-center p-8 hidden"><p class="text-gray-500 dark:text-gray-400">No se encontraron configuraciones que coincidan con los filtros.</p></div>
    </div>
</div>

<div id="toast" class="fixed bottom-4 right-4 z-50 hidden max-w-xs card rounded-lg shadow-lg p-4 flex items-center gap-3"><i id="toastIcon" class="text-xl"></i><p id="toastMessage" class="text-sm font-medium"></p></div>
<div id="editModal" class="fixed inset-0 z-50 flex items-center justify-center hidden" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-describedby="modalDescription">
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
    const noResults = document.getElementById('no-results');
    const searchInput = document.getElementById('searchInput');
    const sortOrder = document.getElementById('sortOrder');
    const modal = document.getElementById('editModal');
    const editForm = document.getElementById('editForm');
    const modalTitle = document.getElementById('modalTitle');
    const modalDescription = document.getElementById('modalDescription');
    const modalInputContainer = document.getElementById('modalInputContainer');
    const cancelButton = document.getElementById('cancelButton');
    const confirmSaveButton = document.getElementById('confirmSaveButton');

    let allSettings = [];
    let filteredSettings = [];
    let currentEditingKey = null;
    let lastFocusedEl = null;

    const escapeHtml = (str) => {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const inferType = (key, value) => {
        const v = String(value ?? '').trim();
        const k = String(key ?? '').toLowerCase();

        if (v === '0' || v === '1' || v.toLowerCase() === 'true' || v.toLowerCase() === 'false') {
            return 'boolean';
        }

        if (/^-?\d+(\.\d+)?$/.test(v)) {
            return 'number';
        }

        if (v.length > 80 || v.includes('\n') || k.includes('json') || k.includes('html')) {
            return 'textarea';
        }

        return 'text';
    };

    const formatDisplayValue = (key, value) => {
        const v = String(value ?? '');
        const type = inferType(key, v);
        if (type === 'boolean') {
            const isTrue = (v === '1' || v.toLowerCase() === 'true');
            return isTrue
                ? '<span class="font-semibold text-green-500">True</span>'
                : '<span class="font-semibold text-red-500">False</span>';
        }

        const safe = escapeHtml(v);
        if (safe === '') return '<span class="text-gray-400">—</span>';
        if (safe.length > 90) return `<span class="font-mono text-xs" title="${safe}">${safe.slice(0, 90)}…</span>`;
        return `<span class="font-mono text-xs">${safe}</span>`;
    };

    const renderSettings = () => {
        if (!Array.isArray(filteredSettings)) filteredSettings = [];

        if (filteredSettings.length === 0) {
            settingsContainer.innerHTML = '';
            noResults.classList.remove('hidden');
            return;
        }

        noResults.classList.add('hidden');
        settingsContainer.innerHTML = filteredSettings.map((s) => {
            const key = String(s.key_name ?? '');
            const val = String(s.value ?? '');
            const updated = String(s.updated_at ?? '');

            return `
                <tr data-key="${escapeHtml(key)}">
                    <td class="px-6 py-4 text-sm font-semibold" data-label="Key Name">${escapeHtml(key)}</td>
                    <td class="px-6 py-4 text-sm" data-label="Current Value">${formatDisplayValue(key, val)}</td>
                    <td class="px-6 py-4 text-sm" data-label="Updated">${escapeHtml(updated || '—')}</td>
                    <td class="px-6 py-4 text-right" data-label="Acciones">
                        <button class="edit-btn px-3 py-2 rounded-lg text-sm" style="border: 1px solid var(--orders-border); color: var(--orders-text-secondary); background-color: var(--orders-row-bg);">Editar</button>
                    </td>
                </tr>
            `;
        }).join('');
    };

    const applyFilters = () => {
        const q = String(searchInput?.value ?? '').trim().toLowerCase();
        filteredSettings = allSettings.filter((s) => {
            const key = String(s.key_name ?? '').toLowerCase();
            const val = String(s.value ?? '').toLowerCase();
            return q === '' || key.includes(q) || val.includes(q);
        });

        const sort = String(sortOrder?.value ?? 'key_asc');
        const byKey = (a, b) => String(a.key_name ?? '').localeCompare(String(b.key_name ?? ''), 'en', { sensitivity: 'base' });
        const byUpdated = (a, b) => {
            const at = Date.parse(String(a.updated_at ?? '')) || 0;
            const bt = Date.parse(String(b.updated_at ?? '')) || 0;
            return at - bt;
        };

        if (sort === 'key_desc') filteredSettings.sort((a, b) => byKey(b, a));
        else if (sort === 'updated_asc') filteredSettings.sort(byUpdated);
        else if (sort === 'updated_desc') filteredSettings.sort((a, b) => byUpdated(b, a));
        else filteredSettings.sort(byKey);

        renderSettings();
    };

    const closeModal = () => {
        modal.classList.add('hidden');
        if (lastFocusedEl && typeof lastFocusedEl.focus === 'function') lastFocusedEl.focus();
    };

    const openEditModal = (key) => {
        currentEditingKey = key;
        const data = allSettings.find(s => String(s.key_name) === String(key));

        modalTitle.textContent = String(key);
        modalDescription.textContent = 'Edita el valor de esta configuración.';

        const currentValue = data ? String(data.value ?? '') : '';
        const type = inferType(key, currentValue);

        let inputHTML = '';
        if (type === 'boolean') {
            const isTrue = (currentValue === '1' || currentValue.toLowerCase() === 'true');
            inputHTML = `
                <label class="switch" aria-label="Boolean toggle">
                    <input type="checkbox" id="modalInput" ${isTrue ? 'checked' : ''}>
                    <span class="slider"></span>
                </label>
            `;
        } else if (type === 'number') {
            inputHTML = `<input type="number" id="modalInput" step="0.01">`;
        } else if (type === 'textarea') {
            inputHTML = `<textarea id="modalInput" class="min-h-[120px]"></textarea>`;
        } else {
            inputHTML = `<input type="text" id="modalInput">`;
        }

        modalInputContainer.innerHTML = inputHTML;

        lastFocusedEl = document.activeElement;
        modal.classList.remove('hidden');

        const input = document.getElementById('modalInput');
        if (input && input.type !== 'checkbox') {
            input.value = currentValue;
        }
        if (input && typeof input.focus === 'function') {
            setTimeout(() => input.focus(), 0);
        }
    };

    const handleSave = async (event) => {
        event.preventDefault();
        const input = document.getElementById('modalInput');
        let newValue;

        if (input.type === 'checkbox') {
            const existing = allSettings.find(s => String(s.key_name) === String(currentEditingKey));
            const existingValue = existing ? String(existing.value ?? '').trim().toLowerCase() : '';

            if (existingValue === 'true' || existingValue === 'false') {
                newValue = input.checked ? 'true' : 'false';
            } else {
                newValue = input.checked ? '1' : '0';
            }
        } else {
            newValue = input.value;
        }

        const payload = {
            action: 'update',
            key_name: currentEditingKey,
            value: newValue
        };

        confirmSaveButton.disabled = true;
        confirmSaveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

        try {
            const response = await fetch('configuracion_usuarios/api_configuracion_usuarios.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();
            if (!response.ok) throw new Error(result.message);

            const updated = result.setting || {};
            const idx = allSettings.findIndex(s => String(s.key_name) === String(currentEditingKey));
            if (idx >= 0) {
                allSettings[idx] = {
                    ...allSettings[idx],
                    value: updated.value ?? newValue,
                    updated_at: updated.updated_at ?? allSettings[idx].updated_at
                };
            }

            applyFilters();

            showToast(result.message, 'success');
            closeModal();
        } catch (error) {
            showToast(error.message || 'Error desconocido al guardar', 'error');
        } finally {
            confirmSaveButton.disabled = false;
            confirmSaveButton.innerHTML = 'Guardar Cambio';
        }
    };

    const init = async () => {
        try {
            const response = await fetch('configuracion_usuarios/api_configuracion_usuarios.php?action=get_all');
            const result = await response.json();
            if (!response.ok) throw new Error(result.message);

            allSettings = result.settings;
            applyFilters();
        } catch (error) {
            if (noResults) noResults.classList.add('hidden');
            settingsContainer.innerHTML = `
                <tr>
                    <td colspan="4" class="px-6 py-4 text-center text-red-500">Error al cargar la configuración: ${escapeHtml(error.message || 'La respuesta de la API no es válida.')}</td>
                </tr>
            `;
        }
    };

    settingsContainer.addEventListener('click', (e) => {
        const button = e.target.closest('.edit-btn');
        if (!button) return;

        const row = button.closest('tr');
        const key = row ? row.getAttribute('data-key') : null;
        if (key) openEditModal(key);
    });

    if (searchInput) searchInput.addEventListener('input', applyFilters);
    if (sortOrder) sortOrder.addEventListener('change', applyFilters);

    cancelButton.addEventListener('click', closeModal);
    editForm.addEventListener('submit', handleSave);

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });

    init();
});

const showToast = (message, type = "success") => {
    const toast = document.getElementById("toast");
    const toastIcon = document.getElementById("toastIcon");
    const toastMessage = document.getElementById("toastMessage");
    toastMessage.textContent = message;
    toastIcon.className = type === "success" ? "fas fa-check-circle text-green-500 text-xl" : "fas fa-times-circle text-red-500 text-xl";
    toast.classList.remove("hidden");
    setTimeout(() => toast.classList.add("hidden"), 4000);
};
</script>

<?php
include __DIR__ . '/../core/admin_footer.php';
?>

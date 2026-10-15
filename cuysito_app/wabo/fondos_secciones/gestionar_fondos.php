<?php require_once __DIR__ . '/../core/admin_header.php'; ?>

<style>
    /* ------- Fondos de Secciones (estilos propios de la sección) ------- */
    .fs-card {
        border: 1px solid var(--border-color);
        border-radius: 0.75rem;
        background-color: var(--secondary);
        overflow: hidden;
    }
    .fs-thumb {
        width: 148px; height: 68px; border-radius: .5rem; overflow: hidden; flex-shrink: 0;
        background: rgba(0,0,0,.35); display: flex; align-items: center; justify-content: center;
        border: 1px solid var(--border-color); cursor: zoom-in;
    }
    .fs-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .fs-thumb .fs-thumb-empty { font-size: .7rem; color: var(--text-secondary); cursor: default; }
    .fs-badge { font-size: .68rem; font-weight: 600; padding: .15rem .5rem; border-radius: 9999px; white-space: nowrap; }
    .fs-badge--on   { background: rgba(34,197,94,.15);  color: #22c55e; }
    .fs-badge--off  { background: rgba(239,68,68,.15);  color: #ef4444; }
    .fs-badge--shop { background: rgba(59,130,246,.15); color: #60a5fa; }
    .fs-badge--pin  { background: rgba(245,158,11,.15); color: #f59e0b; }
    .fs-badge--fixed{ background: rgba(168,85,247,.15); color: #c084fc; }
    .fs-icon-btn {
        border: 1px solid var(--border-color); border-radius: .4rem;
        padding: .3rem .55rem; font-size: .8rem; transition: opacity .15s ease;
    }
    .fs-icon-btn:hover { opacity: .75; }
    .fs-icon-btn:disabled { opacity: .5; cursor: not-allowed; }
    .fs-icon-btn svg { display: inline-block; vertical-align: -0.125em; }
    /* Ojo mostrar/ocultar reutilizado de /plantillas (visibility-toggle):
       mismo SVG, mismas clases y misma animación de barrido del slash. */
    .fs-icon-btn.vis-toggle { display: inline-grid; place-items: center; padding: .3rem .45rem; }
    .fs-icon-btn.vis-toggle:hover { opacity: 1; }
    .vis-toggle { position: relative; cursor: pointer; }
    .vis-toggle .vis-toggle__svg {
        display: block; overflow: visible;
        transition: opacity 220ms cubic-bezier(.4, 0, .2, 1);
    }
    .vis-toggle .vis-toggle__eye-outer,
    .vis-toggle .vis-toggle__eye-pupil {
        transition: stroke-opacity 150ms cubic-bezier(.4, 0, .2, 1);
    }
    .vis-toggle.is-hidden .vis-toggle__svg { opacity: .45; }
    .vis-toggle .vis-toggle__slash {
        stroke-dasharray: 1; stroke-dashoffset: 1;
        will-change: stroke-dashoffset;
        transition: stroke-dashoffset 280ms cubic-bezier(.4, 0, .2, 1);
    }
    .vis-toggle.is-hidden .vis-toggle__slash { stroke-dashoffset: 0; }
    .vis-toggle.is-wiping .vis-toggle__slash { stroke-dashoffset: -1; }
    .vis-toggle.is-snap-reset .vis-toggle__eye-outer,
    .vis-toggle.is-snap-reset .vis-toggle__eye-pupil,
    .vis-toggle.is-snap-reset .vis-toggle__slash,
    .vis-toggle.is-snap-reset .vis-toggle__svg { transition: none !important; }
    .vis-toggle.is-snap-reset .vis-toggle__slash { stroke-dashoffset: 1; }
    .vis-toggle:disabled { opacity: .45; cursor: not-allowed; pointer-events: none; }
    .vis-toggle:not(:disabled):hover .vis-toggle__eye-outer,
    .vis-toggle:not(:disabled):hover .vis-toggle__eye-pupil,
    .vis-toggle:not(:disabled):hover .vis-toggle__slash { stroke-opacity: 0.85; }
    .vis-toggle:not(:disabled):active .vis-toggle__svg {
        transform: scale(.92);
        transition: opacity 220ms cubic-bezier(.4, 0, .2, 1), transform 80ms ease-out;
    }
    .fs-row { display: flex; align-items: center; gap: .9rem; padding: .8rem 1rem; border-top: 1px solid var(--border-color); flex-wrap: wrap; }
    .fs-row:first-child { border-top: none; }
    .fs-url-input {
        flex: 1 1 260px; min-width: 220px;
        background-color: var(--primary); border: 1px solid var(--border-color);
        color: var(--text-primary); border-radius: .5rem; padding: .45rem .7rem; font-size: .8rem;
    }
    .fs-url-input.fs-url-invalid { border-color: #ef4444; }
    .fs-search {
        background-color: var(--primary); border: 1px solid var(--border-color);
        color: var(--text-primary); border-radius: .5rem; padding: .5rem .8rem .5rem 2.1rem; font-size: .85rem;
        width: 100%;
    }
    .fs-search-wrap { position: relative; flex: 1 1 260px; }
    .fs-search-wrap .fa-magnifying-glass {
        position: absolute; left: .75rem; top: 50%; transform: translateY(-50%);
        font-size: .8rem; color: var(--text-secondary); pointer-events: none;
    }
    .fs-filter-btn {
        border: 1px solid var(--border-color); border-radius: 9999px;
        padding: .25rem .7rem; font-size: .72rem; font-weight: 600; color: var(--text-secondary);
        transition: all .15s ease; white-space: nowrap;
    }
    .fs-filter-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }
    .fs-count { font-size: .72rem; color: var(--text-secondary); font-weight: 400; }
    /* Toasts */
    #fs-toasts { position: fixed; bottom: 1.25rem; right: 1.25rem; z-index: 60; display: flex; flex-direction: column; gap: .5rem; }
    .fs-toast {
        border-radius: .5rem; padding: .6rem 1rem; font-size: .8rem; color: #fff;
        box-shadow: 0 6px 20px rgba(0,0,0,.35); animation: fs-toast-in .2s ease;
        display: flex; align-items: center; gap: .5rem; max-width: 320px;
    }
    .fs-toast--ok  { background: #16a34a; }
    .fs-toast--err { background: #dc2626; }
    @keyframes fs-toast-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
    /* Lightbox de vista previa */
    #fs-lightbox {
        position: fixed; inset: 0; z-index: 70; background: rgba(0,0,0,.8);
        display: none; align-items: center; justify-content: center; padding: 2rem; cursor: zoom-out;
    }
    #fs-lightbox.open { display: flex; }
    #fs-lightbox img { max-width: 100%; max-height: 100%; border-radius: .5rem; }
</style>

<div class="max-w-6xl mx-auto animate-fade-in">
    <div class="mb-6">
        <h1 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-panorama" style="color: var(--accent);"></i> Fondos de Secciones</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
            Asigna una imagen de fondo a cada <b>sección de la tienda diaria</b> (como los fondos "billboard"
            de fortnite.com, que la API no entrega). El fondo se muestra automáticamente detrás de esa sección
            en la tienda y en las páginas de sus objetos — el resto del sitio (header, carrito, etc.) no cambia.
            Se vincula por el ID de la sección y también por su nombre, así se mantiene aunque el ID rote de día a día.
            También puedes asignar fondo a <b>Ofertas random del día</b> y <b>Lo más vendido</b>, aunque no vengan de la API.
            Por defecto, cuando una sección sale de la tienda su fondo se elimina automáticamente; usa el botón
            <i class="fas fa-thumbtack"></i> "Guardar siempre" si quieres conservar un fondo aunque la sección se vaya y vuelva.
        </p>
    </div>

    <!-- Buscador global -->
    <div class="flex items-center gap-3 mb-6 flex-wrap">
        <div class="fs-search-wrap">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" id="fs-search" class="fs-search" placeholder="Buscar sección por nombre, ID o URL..." autocomplete="off">
        </div>
        <button id="reload-btn" class="fs-icon-btn" title="Recargar todo"><i class="fas fa-rotate"></i> Recargar</button>
    </div>

    <!-- Fondo por defecto global -->
    <div class="fs-card mb-8">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border-color);">
            <h2 class="font-semibold"><i class="fas fa-fill-drip mr-2" style="color: var(--accent);"></i>Fondo por defecto <span id="default-bg-badge"></span></h2>
            <p class="text-xs mt-1" style="color: var(--text-secondary);">Se aplica automáticamente a <b>todas las secciones que no tienen fondo propio</b> (en lugar del fondo negro/blanco del tema). Los fondos asignados a una sección específica siempre tienen prioridad.</p>
        </div>
        <div class="fs-row" id="default-bg-row">
            <div class="fs-thumb"><span class="fs-thumb-empty">Sin fondo</span></div>
            <input type="url" id="default-bg-input" class="fs-url-input" placeholder="https://cdn2.unrealengine.com/..." value="">
            <button class="fs-icon-btn" id="default-bg-save" style="color: var(--accent);" title="Guardar fondo por defecto"><i class="fas fa-save"></i> Guardar</button>
            <button class="fs-icon-btn vis-toggle" id="default-bg-toggle" hidden></button>
            <button class="fs-icon-btn text-red-500" id="default-bg-delete" title="Quitar el fondo por defecto" hidden></button>
        </div>
    </div>

    <!-- Secciones de la tienda actual -->
    <div class="fs-card mb-8">
        <div class="flex items-center justify-between px-4 py-3 flex-wrap gap-2" style="border-bottom: 1px solid var(--border-color);">
            <h2 class="font-semibold"><i class="fas fa-store mr-2" style="color: var(--accent);"></i>Secciones de la tienda de hoy
                <span id="shop-date" class="text-xs font-normal" style="color: var(--text-secondary);"></span>
                <span id="sections-count" class="fs-count"></span>
            </h2>
            <div class="flex items-center gap-2">
                <button class="fs-filter-btn js-sec-filter active" data-filter="all">Todas</button>
                <button class="fs-filter-btn js-sec-filter" data-filter="with">Con fondo</button>
                <button class="fs-filter-btn js-sec-filter" data-filter="without">Sin fondo</button>
            </div>
        </div>
        <div id="sections-list">
            <p class="p-4 text-sm" style="color: var(--text-secondary);"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando secciones...</p>
        </div>
    </div>

    <!-- Fondos guardados -->
    <div class="fs-card">
        <div class="flex items-center justify-between px-4 py-3 flex-wrap gap-2" style="border-bottom: 1px solid var(--border-color);">
            <div>
                <h2 class="font-semibold"><i class="fas fa-images mr-2" style="color: var(--accent);"></i>Fondos guardados <span id="fondos-count" class="fs-count"></span></h2>
                <p class="text-xs mt-1" style="color: var(--text-secondary);">Por defecto, un fondo se elimina solo cuando su sección sale de la tienda. Marca <i class="fas fa-thumbtack"></i> "Guardar siempre" para conservarlo aunque la sección se vaya; se re-activará cuando vuelva.</p>
            </div>
            <div class="flex items-center gap-2">
                <button class="fs-filter-btn js-fondo-filter active" data-filter="all">Todos</button>
                <button class="fs-filter-btn js-fondo-filter" data-filter="active">Activos</button>
                <button class="fs-filter-btn js-fondo-filter" data-filter="inactive">Inactivos</button>
                <button class="fs-filter-btn js-fondo-filter" data-filter="inshop">En tienda hoy</button>
                <button class="fs-filter-btn js-fondo-filter" data-filter="pinned">Guardados siempre</button>
            </div>
        </div>
        <div id="fondos-list">
            <p class="p-4 text-sm" style="color: var(--text-secondary);"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando fondos...</p>
        </div>
    </div>
</div>

<div id="fs-toasts"></div>
<div id="fs-lightbox"><img src="" alt="Vista previa del fondo"></div>

<script>
(() => {
    const API = 'fondos_secciones/api_fondos_secciones.php';
    const $ = (id) => document.getElementById(id);

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    let state = { sections: [], fondos: [], defaultBg: null, query: '', secFilter: 'all', fondoFilter: 'all' };

    // ---------- Feedback (toasts en lugar de alert) ----------
    function toast(message, ok = true) {
        const box = document.createElement('div');
        box.className = 'fs-toast ' + (ok ? 'fs-toast--ok' : 'fs-toast--err');
        box.innerHTML = `<i class="fas ${ok ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i><span>${esc(message)}</span>`;
        $('fs-toasts').appendChild(box);
        setTimeout(() => box.remove(), 3500);
    }

    // ---------- Lightbox de vista previa ----------
    const lightbox = $('fs-lightbox');
    lightbox.addEventListener('click', () => lightbox.classList.remove('open'));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') lightbox.classList.remove('open'); });
    function openLightbox(url) {
        lightbox.querySelector('img').src = url;
        lightbox.classList.add('open');
    }

    const isValidUrl = (url) => /^https:\/\/[^\s"'<>]+$/i.test(url);

    // Iconos reutilizados de /plantillas (visibility-toggle + svg-icons).
    const EYE_TOGGLE_SVG = `<svg class="vis-toggle__svg" viewBox="0 0 24 24" width="1em" height="1em" fill="none" aria-hidden="true" focusable="false">
        <path class="vis-toggle__eye-outer" d="M2.5 12S6.5 5.5 12 5.5 21.5 12 21.5 12 17.5 18.5 12 18.5 2.5 12 2.5 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <circle class="vis-toggle__eye-pupil" cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        <line class="vis-toggle__slash" x1="4" y1="20" x2="20" y2="4" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" pathLength="1"/>
    </svg>`;
    const TRASH_SVG = '<svg viewBox="0 0 100 100" width="1em" height="1em" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round" stroke-linejoin="round" d="M35 12H65M12 32H88M25 32L34 90H66L75 32"/></svg>';

    /**
     * Sincroniza el estado visual de un botón de ojo (misma lógica de
     * /plantillas): ocultar tacha el ojo al instante; mostrar reproduce el
     * barrido del slash y luego resetea sin transición.
     */
    function setEyeHidden(btn, hidden) {
        btn.classList.remove('is-snap-reset');
        if (hidden) {
            btn.classList.remove('is-wiping');
            btn.classList.add('is-hidden');
        } else {
            btn.classList.add('is-wiping');
            btn.classList.remove('is-hidden');
            setTimeout(() => {
                btn.classList.add('is-snap-reset');
                btn.classList.remove('is-wiping');
                void btn.offsetWidth;
                btn.classList.remove('is-snap-reset');
            }, 290);
        }
        btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
        btn.title = hidden ? 'Mostrar fondo' : 'Ocultar fondo';
    }

    function eyeButtonHTML(extraClass, id, active) {
        return `<button class="fs-icon-btn vis-toggle ${extraClass}${active ? '' : ' is-hidden'}"
            data-id="${id}" data-next="${active ? 0 : 1}" aria-pressed="${active ? 'false' : 'true'}"
            title="${active ? 'Ocultar fondo' : 'Mostrar fondo'}">${EYE_TOGGLE_SVG}</button>`;
    }

    function thumb(url) {
        return url
            ? `<div class="fs-thumb js-zoom" data-url="${esc(url)}" title="Ver imagen completa"><img src="${esc(url)}" alt="" loading="lazy" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'fs-thumb-empty',textContent:'No cargó la imagen'}))"></div>`
            : `<div class="fs-thumb"><span class="fs-thumb-empty">Sin fondo</span></div>`;
    }

    const matchesQuery = (q, ...fields) => !q || fields.some((f) => String(f || '').toLowerCase().includes(q));

    function bindZoom(wrap) {
        wrap.querySelectorAll('.js-zoom').forEach((el) => {
            el.addEventListener('click', () => openLightbox(el.dataset.url));
        });
    }

    // ---------- Secciones de la tienda de hoy ----------
    function visibleSections() {
        const q = state.query;
        return state.sections.filter((s) => {
            if (state.secFilter === 'with' && !s.background) return false;
            if (state.secFilter === 'without' && s.background) return false;
            return matchesQuery(q, s.name, s.layoutId, s.background && s.background.image_url);
        });
    }

    function renderSections() {
        const wrap = $('sections-list');
        const list = visibleSections();
        const withBg = state.sections.filter((s) => s.background).length;
        $('sections-count').textContent = state.sections.length
            ? `· ${state.sections.length} secciones (${withBg} con fondo)` : '';
        if (!state.sections.length) {
            wrap.innerHTML = '<p class="p-4 text-sm" style="color: var(--text-secondary);">No hay secciones visibles en el snapshot de la tienda.</p>';
            return;
        }
        if (!list.length) {
            wrap.innerHTML = '<p class="p-4 text-sm" style="color: var(--text-secondary);">Ninguna sección coincide con la búsqueda/filtro.</p>';
            return;
        }
        wrap.innerHTML = list.map((s) => `
            <div class="fs-row" data-key="${esc(s.key)}">
                ${thumb(s.background ? s.background.image_url : null)}
                <div class="min-w-0" style="flex: 0 1 220px;">
                    <p class="text-sm font-semibold truncate">${esc(s.name)}</p>
                    <p class="text-xs truncate" style="color: var(--text-secondary);" title="${esc(s.layoutId)}">${s.virtual ? esc(s.layoutId) + ' · sección propia de la web' : `${esc(s.layoutId)} · ${s.items} oferta${s.items === 1 ? '' : 's'}`}</p>
                </div>
                <input type="url" class="fs-url-input" placeholder="https://cdn2.unrealengine.com/..." value="${esc(s.background ? s.background.image_url : '')}">
                <button class="fs-icon-btn js-save" style="color: var(--accent);" title="Guardar fondo"><i class="fas fa-save"></i> Guardar</button>
                ${s.background ? eyeButtonHTML('js-sec-toggle', s.background.id, Number(s.background.activo) === 1) : ''}
                ${s.background ? `<button class="fs-icon-btn js-sec-delete text-red-500" data-id="${s.background.id}" data-label="${esc(s.name)}" title="Quitar el fondo (dejar la sección sin fondo)">${TRASH_SVG}</button>` : ''}
                ${s.virtual ? '<span class="fs-badge fs-badge--fixed" title="Sección generada por la web, no viene de la API">Sección fija</span>' : ''}
                ${s.background ? (Number(s.background.activo) === 1 ? '<span class="fs-badge fs-badge--on">Con fondo</span>' : '<span class="fs-badge fs-badge--off">Fondo oculto</span>') : '<span class="fs-badge fs-badge--off">Sin fondo</span>'}
            </div>
        `).join('');
        bindZoom(wrap);

        wrap.querySelectorAll('.fs-row').forEach((row) => {
            const input = row.querySelector('input');
            const saveBtn = row.querySelector('.js-save');

            // Vista previa en vivo + validación mientras se escribe/pega la URL.
            input.addEventListener('input', () => {
                const url = input.value.trim();
                input.classList.toggle('fs-url-invalid', url !== '' && !isValidUrl(url));
                if (isValidUrl(url)) {
                    row.querySelector('.fs-thumb').outerHTML = thumb(url);
                    bindZoom(row);
                }
            });
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') saveBtn.click(); });

            saveBtn.addEventListener('click', async () => {
                const key = row.dataset.key;
                const section = state.sections.find((s) => s.key === key);
                const url = input.value.trim();
                if (!isValidUrl(url)) { toast('Ingresa una URL https:// válida.', false); input.focus(); return; }
                saveBtn.disabled = true;
                const fd = new FormData();
                fd.append('action', 'save');
                fd.append('section_key', key);
                fd.append('section_name', section ? section.name : '');
                fd.append('image_url', url);
                try {
                    const res = await fetch(API, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.ok) toast('Fondo guardado para "' + (section ? section.name : key) + '".');
                    else toast(data.message || 'No se pudo guardar.', false);
                } catch (_) { toast('Error de red al guardar.', false); }
                saveBtn.disabled = false;
                loadList();
            });

            const toggleBtn = row.querySelector('.js-sec-toggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', async () => {
                    const next = toggleBtn.dataset.next;
                    setEyeHidden(toggleBtn, next === '0');
                    toggleBtn.disabled = true;
                    const fd = new FormData();
                    fd.append('action', 'toggle');
                    fd.append('id', toggleBtn.dataset.id);
                    fd.append('activo', next);
                    try {
                        const res = await fetch(API, { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.ok) toast(next === '1' ? 'Fondo visible de nuevo.' : 'Fondo oculto (no se muestra en la tienda).');
                        else toast(data.message || 'No se pudo actualizar.', false);
                    } catch (_) { toast('Error de red.', false); }
                    loadList();
                });
            }

            const deleteBtn = row.querySelector('.js-sec-delete');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', async () => {
                    if (!confirm('¿Quitar el fondo de "' + deleteBtn.dataset.label + '"? La sección quedará sin fondo (o con el fondo por defecto, si hay uno).')) return;
                    deleteBtn.disabled = true;
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', deleteBtn.dataset.id);
                    try {
                        const res = await fetch(API, { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.ok) toast('Fondo eliminado.');
                        else toast(data.message || 'No se pudo eliminar.', false);
                    } catch (_) { toast('Error de red.', false); }
                    loadList();
                });
            }
        });
    }

    // ---------- Fondo por defecto global ----------
    function renderDefaultBg() {
        const row = $('default-bg-row');
        const input = $('default-bg-input');
        const bg = state.defaultBg;
        const active = bg && Number(bg.activo) === 1;
        if (document.activeElement !== input) {
            input.value = bg ? bg.image_url : '';
        }
        row.querySelector('.fs-thumb').outerHTML = thumb(bg ? bg.image_url : null);
        bindZoom(row);
        $('default-bg-badge').innerHTML = bg
            ? `<span class="fs-badge ${active ? 'fs-badge--on' : 'fs-badge--off'}">${active ? 'Activo' : 'Inactivo'}</span>`
            : '<span class="fs-badge fs-badge--off">Sin configurar</span>';
        const toggleBtn = $('default-bg-toggle');
        const deleteBtn = $('default-bg-delete');
        toggleBtn.hidden = !bg;
        deleteBtn.hidden = !bg;
        if (bg) {
            toggleBtn.dataset.id = bg.id;
            toggleBtn.dataset.next = active ? 0 : 1;
            toggleBtn.innerHTML = EYE_TOGGLE_SVG;
            toggleBtn.classList.remove('is-wiping', 'is-snap-reset');
            toggleBtn.classList.toggle('is-hidden', !active);
            toggleBtn.setAttribute('aria-pressed', active ? 'false' : 'true');
            toggleBtn.title = active ? 'Ocultar fondo' : 'Mostrar fondo';
            deleteBtn.dataset.id = bg.id;
            deleteBtn.innerHTML = TRASH_SVG;
        }
    }

    {
        const input = $('default-bg-input');
        const saveBtn = $('default-bg-save');
        const row = $('default-bg-row');
        input.addEventListener('input', () => {
            const url = input.value.trim();
            input.classList.toggle('fs-url-invalid', url !== '' && !isValidUrl(url));
            if (isValidUrl(url)) {
                row.querySelector('.fs-thumb').outerHTML = thumb(url);
                bindZoom(row);
            }
        });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') saveBtn.click(); });
        saveBtn.addEventListener('click', async () => {
            const url = input.value.trim();
            if (!isValidUrl(url)) { toast('Ingresa una URL https:// válida.', false); input.focus(); return; }
            saveBtn.disabled = true;
            const fd = new FormData();
            fd.append('action', 'save_default');
            fd.append('image_url', url);
            try {
                const res = await fetch(API, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) toast('Fondo por defecto guardado.');
                else toast(data.message || 'No se pudo guardar.', false);
            } catch (_) { toast('Error de red al guardar.', false); }
            saveBtn.disabled = false;
            loadList();
        });
        $('default-bg-toggle').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            setEyeHidden(btn, btn.dataset.next === '0');
            btn.disabled = true;
            const fd = new FormData();
            fd.append('action', 'toggle');
            fd.append('id', btn.dataset.id);
            fd.append('activo', btn.dataset.next);
            try {
                const res = await fetch(API, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) toast(btn.dataset.next === '1' ? 'Fondo por defecto activado.' : 'Fondo por defecto desactivado.');
                else toast(data.message || 'No se pudo actualizar.', false);
            } catch (_) { toast('Error de red.', false); }
            btn.disabled = false;
            loadList();
        });
        $('default-bg-delete').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            if (!confirm('¿Quitar el fondo por defecto? Las secciones sin fondo volverán al fondo del tema.')) return;
            btn.disabled = true;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', btn.dataset.id);
            try {
                const res = await fetch(API, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) toast('Fondo por defecto eliminado.');
                else toast(data.message || 'No se pudo eliminar.', false);
            } catch (_) { toast('Error de red.', false); }
            btn.disabled = false;
            loadList();
        });
    }

    // ---------- Fondos guardados ----------
    function visibleFondos() {
        const q = state.query;
        return state.fondos.filter((f) => {
            const active = Number(f.activo) === 1;
            const inShop = state.sections.some((s) => s.key === f.section_key);
            if (state.fondoFilter === 'active' && !active) return false;
            if (state.fondoFilter === 'inactive' && active) return false;
            if (state.fondoFilter === 'inshop' && !inShop) return false;
            if (state.fondoFilter === 'pinned' && Number(f.persistente) !== 1) return false;
            return matchesQuery(q, f.section_name, f.section_key, f.image_url);
        });
    }

    function renderFondos() {
        const wrap = $('fondos-list');
        const list = visibleFondos();
        const activos = state.fondos.filter((f) => Number(f.activo) === 1).length;
        $('fondos-count').textContent = state.fondos.length
            ? `· ${state.fondos.length} guardados (${activos} activos)` : '';
        if (!state.fondos.length) {
            wrap.innerHTML = '<p class="p-4 text-sm" style="color: var(--text-secondary);">Aún no hay fondos guardados.</p>';
            return;
        }
        if (!list.length) {
            wrap.innerHTML = '<p class="p-4 text-sm" style="color: var(--text-secondary);">Ningún fondo coincide con la búsqueda/filtro.</p>';
            return;
        }
        wrap.innerHTML = list.map((f) => {
            const active = Number(f.activo) === 1;
            const pinned = Number(f.persistente) === 1;
            const inShop = state.sections.some((s) => s.key === f.section_key);
            return `
            <div class="fs-row">
                ${thumb(f.image_url)}
                <div class="min-w-0" style="flex: 1 1 220px;">
                    <p class="text-sm font-semibold truncate">${esc(f.section_name || f.section_key)}</p>
                    <p class="text-xs truncate" style="color: var(--text-secondary);" title="${esc(f.image_url)}">${esc(f.section_key)} · ${esc(f.image_url)}</p>
                </div>
                ${inShop ? '<span class="fs-badge fs-badge--shop">En tienda hoy</span>' : ''}
                ${pinned ? '<span class="fs-badge fs-badge--pin" title="No se elimina aunque la sección salga de la tienda">Guardado siempre</span>' : ''}
                <span class="fs-badge ${active ? 'fs-badge--on' : 'fs-badge--off'}">${active ? 'Activo' : 'Inactivo'}</span>
                <button class="fs-icon-btn js-pin" data-id="${f.id}" data-next="${pinned ? 0 : 1}" style="${pinned ? 'color: #f59e0b;' : ''}" title="${pinned ? 'Dejar de guardar siempre (se eliminará solo cuando la sección salga de la tienda)' : 'Guardar siempre (no se eliminará aunque la sección salga de la tienda)'}">
                    <i class="fas fa-thumbtack"></i>
                </button>
                <button class="fs-icon-btn js-copy" data-url="${esc(f.image_url)}" title="Copiar URL"><i class="fas fa-copy"></i></button>
                ${eyeButtonHTML('js-toggle', f.id, active)}
                <button class="fs-icon-btn js-delete text-red-500" data-id="${f.id}" data-label="${esc(f.section_name || f.section_key)}" title="Eliminar">${TRASH_SVG}</button>
            </div>`;
        }).join('');
        bindZoom(wrap);

        wrap.querySelectorAll('.js-copy').forEach((btn) => {
            btn.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(btn.dataset.url);
                    toast('URL copiada al portapapeles.');
                } catch (_) { toast('No se pudo copiar la URL.', false); }
            });
        });
        wrap.querySelectorAll('.js-pin').forEach((btn) => {
            btn.addEventListener('click', async () => {
                btn.disabled = true;
                const fd = new FormData();
                fd.append('action', 'persist');
                fd.append('id', btn.dataset.id);
                fd.append('persistente', btn.dataset.next);
                try {
                    const res = await fetch(API, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.ok) toast(btn.dataset.next === '1' ? 'El fondo se guardará siempre.' : 'El fondo volverá a eliminarse solo al salir la sección de la tienda.');
                    else toast(data.message || 'No se pudo actualizar.', false);
                } catch (_) { toast('Error de red.', false); }
                loadList();
            });
        });
        wrap.querySelectorAll('.js-toggle').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const next = btn.dataset.next;
                setEyeHidden(btn, next === '0');
                btn.disabled = true;
                const fd = new FormData();
                fd.append('action', 'toggle');
                fd.append('id', btn.dataset.id);
                fd.append('activo', next);
                try {
                    const res = await fetch(API, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.ok) toast(next === '1' ? 'Fondo activado.' : 'Fondo desactivado.');
                    else toast(data.message || 'No se pudo actualizar.', false);
                } catch (_) { toast('Error de red.', false); }
                loadList();
            });
        });
        wrap.querySelectorAll('.js-delete').forEach((btn) => {
            btn.addEventListener('click', async () => {
                if (!confirm('¿Eliminar el fondo de "' + btn.dataset.label + '"? Esta acción no se puede deshacer.')) return;
                btn.disabled = true;
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('id', btn.dataset.id);
                try {
                    const res = await fetch(API, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.ok) toast('Fondo eliminado.');
                    else toast(data.message || 'No se pudo eliminar.', false);
                } catch (_) { toast('Error de red.', false); }
                loadList();
            });
        });
    }

    // ---------- Buscador y filtros ----------
    let searchTimer = null;
    $('fs-search').addEventListener('input', (e) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.query = e.target.value.trim().toLowerCase();
            renderSections();
            renderFondos();
        }, 150);
    });

    document.querySelectorAll('.js-sec-filter').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.js-sec-filter').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            state.secFilter = btn.dataset.filter;
            renderSections();
        });
    });
    document.querySelectorAll('.js-fondo-filter').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.js-fondo-filter').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            state.fondoFilter = btn.dataset.filter;
            renderFondos();
        });
    });

    async function loadList() {
        try {
            const res = await fetch(API + '?action=list');
            const data = await res.json();
            if (!data.ok) throw new Error(data.message || 'Error');
            state.sections = data.sections || [];
            state.fondos = data.fondos || [];
            state.defaultBg = data.default_background || null;
            $('shop-date').textContent = data.shop_date ? '· ' + data.shop_date : '';
            renderSections();
            renderFondos();
            renderDefaultBg();
        } catch (err) {
            $('sections-list').innerHTML = '<p class="p-4 text-sm text-red-500">No se pudo cargar: ' + esc(err.message) + '</p>';
            $('fondos-list').innerHTML = '';
        }
    }

    $('reload-btn').addEventListener('click', loadList);
    loadList();
})();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

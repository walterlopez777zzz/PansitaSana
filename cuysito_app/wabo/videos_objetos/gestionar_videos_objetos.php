<?php require_once __DIR__ . '/../core/admin_header.php'; ?>

<style>
    /* ------- Videos de Objetos (estilos propios de la sección) ------- */
    .vo-dropzone {
        border: 2px dashed var(--border-color);
        border-radius: 0.75rem;
        padding: 1.25rem;
        text-align: center;
        cursor: pointer;
        transition: border-color .15s ease, background-color .15s ease;
        color: var(--text-secondary);
    }
    .vo-dropzone:hover, .vo-dropzone.dragover {
        border-color: var(--accent);
        background-color: rgba(255, 255, 255, 0.03);
        color: var(--text-primary);
    }
    .vo-dropzone i { font-size: 1.5rem; color: var(--accent); }
    .vo-style-chip {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        border: 1px solid var(--border-color);
        border-radius: 9999px;
        padding: .25rem .7rem .25rem .3rem;
        font-size: .78rem;
        cursor: pointer;
        transition: border-color .15s ease, background-color .15s ease;
        background-color: var(--primary);
    }
    .vo-style-chip img { width: 26px; height: 26px; border-radius: 9999px; object-fit: cover; background: rgba(0,0,0,.25); }
    .vo-style-chip.active { border-color: var(--accent); background-color: rgba(255, 200, 0, 0.08); }
    .vo-style-chip .vo-chip-dot { width: 7px; height: 7px; border-radius: 9999px; background: #22c55e; margin-left: .15rem; }
    .vo-card {
        border: 1px solid var(--border-color);
        border-radius: 0.75rem;
        background-color: var(--secondary);
        overflow: hidden;
    }
    .vo-card__head { display: flex; align-items: center; gap: .75rem; padding: .8rem 1rem; border-bottom: 1px solid var(--border-color); }
    .vo-card__head img { width: 44px; height: 44px; object-fit: contain; border-radius: .5rem; background: rgba(0,0,0,.25); }
    .vo-row { display: flex; align-items: center; gap: .75rem; padding: .55rem 1rem; border-top: 1px dashed var(--border-color); }
    .vo-row:first-of-type { border-top: none; }
    .vo-preview {
        width: 84px; height: 48px; border-radius: .4rem; overflow: hidden; flex-shrink: 0;
        background: rgba(0,0,0,.35); display: flex; align-items: center; justify-content: center;
    }
    .vo-preview video { width: 100%; height: 100%; object-fit: cover; }
    .vo-badge { font-size: .68rem; font-weight: 600; padding: .15rem .5rem; border-radius: 9999px; white-space: nowrap; }
    .vo-badge--shop { background: rgba(59,130,246,.15); color: #60a5fa; }
    .vo-badge--on   { background: rgba(34,197,94,.15);  color: #22c55e; }
    .vo-badge--off  { background: rgba(239,68,68,.15);  color: #ef4444; }
    .vo-badge--big  { background: rgba(245,158,11,.15); color: #f59e0b; }
    .vo-icon-btn {
        border: 1px solid var(--border-color); border-radius: .4rem;
        padding: .3rem .55rem; font-size: .8rem; transition: opacity .15s ease;
    }
    .vo-icon-btn:hover { opacity: .75; }
</style>

<div class="max-w-6xl mx-auto animate-fade-in">
    <div class="mb-6">
        <h1 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-film" style="color: var(--accent);"></i> Videos de Objetos</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
            Asigna videos al objeto y a cada uno de sus <b>estilos</b>. Si un objeto tiene video activo,
            su página (<code>/objeto/...</code>) lo muestra al instante en lugar de la imagen.
            Las URLs externas se re-hospedan automáticamente para que nunca caduquen.
        </p>
    </div>

    <!-- Formulario -->
    <div class="rounded-xl border p-5 mb-8" style="background-color: var(--secondary); border-color: var(--border-color);">
        <h2 class="font-semibold mb-4"><i class="fas fa-plus-circle mr-2" style="color: var(--accent);"></i>Agregar / reemplazar video</h2>
        <form id="video-form" class="grid grid-cols-1 lg:grid-cols-2 gap-5" enctype="multipart/form-data">
            <div>
                <div class="relative">
                    <label class="block text-sm font-medium mb-1">Objeto</label>
                    <input type="text" id="search-input" autocomplete="off" placeholder="Busca por nombre o ID (mín. 2 letras)..."
                        class="w-full rounded-lg border px-3 py-2 text-sm" style="background-color: var(--primary); border-color: var(--border-color); color: var(--text-primary);">
                    <div id="search-results" class="absolute z-20 mt-1 w-full rounded-lg border shadow-lg max-h-72 overflow-y-auto hidden"
                        style="background-color: var(--secondary); border-color: var(--border-color);"></div>
                    <div id="selected-item" class="mt-2 hidden items-center gap-3 rounded-lg border p-2" style="border-color: var(--border-color);">
                        <img id="selected-image" src="" alt="" class="w-12 h-12 object-contain rounded bg-black/20">
                        <div class="min-w-0">
                            <p id="selected-name" class="text-sm font-semibold truncate"></p>
                            <p id="selected-id" class="text-xs truncate" style="color: var(--text-secondary);"></p>
                        </div>
                        <button type="button" id="clear-selection" class="ml-auto text-red-500 hover:text-red-400" aria-label="Quitar selección"><i class="fas fa-times"></i></button>
                    </div>
                    <input type="hidden" id="cosmetic-id" name="cosmetic_id">
                    <input type="hidden" id="nombre-objeto" name="nombre_objeto">
                    <input type="hidden" id="style-key" name="style_key" value="">
                    <input type="hidden" id="style-name" name="style_name" value="">
                </div>

                <!-- Estilos del objeto seleccionado -->
                <div id="styles-wrap" class="mt-4 hidden">
                    <label class="block text-sm font-medium mb-2">¿Para qué estilo es el video?</label>
                    <div id="style-chips" class="flex flex-wrap gap-2"></div>
                    <p class="text-xs mt-2" style="color: var(--text-secondary);">
                        El punto verde <span style="color:#22c55e;">●</span> indica que ese estilo ya tiene un video registrado.
                    </p>
                </div>
                <p id="styles-none" class="mt-4 text-xs hidden" style="color: var(--text-secondary);">
                    Este objeto no tiene estilos seleccionables en la tienda de hoy — el video se asignará como video principal.
                </p>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium mb-1">URL del video (.webm / .mp4)</label>
                    <input type="url" id="video-url" name="video_url" placeholder="https://..."
                        class="w-full rounded-lg border px-3 py-2 text-sm" style="background-color: var(--primary); border-color: var(--border-color); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">…o sube un archivo</label>
                    <div id="dropzone" class="vo-dropzone" role="button" tabindex="0" aria-label="Subir archivo de video">
                        <i class="fas fa-cloud-arrow-up mb-1"></i>
                        <p id="dropzone-text" class="text-sm">Arrastra un video aquí, o haz clic para elegirlo</p>
                        <p class="text-xs mt-1" style="color: var(--text-secondary);">.webm o .mp4 · recomendado &lt; 8 MB para carga instantánea</p>
                    </div>
                    <input type="file" id="video-file" name="video_file" accept="video/webm,video/mp4" class="hidden">
                    <div id="file-info" class="mt-2 hidden items-center gap-2 text-sm">
                        <i class="fas fa-file-video" style="color: var(--accent);"></i>
                        <span id="file-name" class="truncate"></span>
                        <span id="file-size" class="text-xs" style="color: var(--text-secondary);"></span>
                        <span id="file-warn" class="vo-badge vo-badge--big hidden">pesado</span>
                        <button type="button" id="file-clear" class="ml-auto text-red-500 hover:text-red-400" aria-label="Quitar archivo"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div id="upload-progress" class="hidden">
                    <div class="w-full h-2 rounded-full overflow-hidden" style="background-color: var(--primary);">
                        <div id="upload-bar" class="h-full rounded-full transition-all" style="width:0%; background-color: var(--accent);"></div>
                    </div>
                    <p id="upload-label" class="text-xs mt-1" style="color: var(--text-secondary);"></p>
                </div>
                <button type="submit" id="save-btn"
                    class="w-full rounded-lg px-4 py-2 font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                    style="background-color: var(--accent);">
                    <i class="fas fa-save mr-2"></i>Guardar video
                </button>
                <p id="form-status" class="text-sm hidden"></p>
            </div>
        </form>
        <div id="preview-wrap" class="mt-4 hidden">
            <p class="text-sm font-medium mb-2">Preview:</p>
            <video id="preview-video" class="max-h-64 rounded-lg border" style="border-color: var(--border-color);" controls muted loop playsinline></video>
        </div>
    </div>

    <!-- Listado -->
    <div>
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <h2 class="font-semibold"><i class="fas fa-clapperboard mr-2" style="color: var(--accent);"></i>Videos registrados <span id="video-count" class="text-sm font-normal" style="color: var(--text-secondary);"></span></h2>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <input type="text" id="filter-text" placeholder="Filtrar por nombre o ID..."
                    class="rounded-lg border px-3 py-1.5 text-sm" style="background-color: var(--secondary); border-color: var(--border-color); color: var(--text-primary);">
                <select id="filter-state" class="rounded-lg border px-2 py-1.5 text-sm" style="background-color: var(--secondary); border-color: var(--border-color); color: var(--text-primary);">
                    <option value="">Todos</option>
                    <option value="on">Activos</option>
                    <option value="off">Inactivos</option>
                    <option value="shop">En tienda hoy</option>
                    <option value="styles">Con videos de estilo</option>
                </select>
                <button id="reload-btn" class="text-sm px-3 py-1.5 rounded-lg border hover:opacity-80" style="border-color: var(--border-color);"><i class="fas fa-rotate mr-1"></i>Recargar</button>
            </div>
        </div>
        <div id="videos-cards" class="grid grid-cols-1 md:grid-cols-2 gap-4"></div>
        <p id="empty-msg" class="p-6 text-center text-sm hidden" style="color: var(--text-secondary);">No hay videos registrados todavía.</p>
    </div>
</div>

<script>
(function () {
    'use strict';
    const API = 'videos_objetos/api_videos_objetos.php';
    const $ = (id) => document.getElementById(id);
    const BIG_FILE_BYTES = 8 * 1024 * 1024;

    let allVideos = [];
    let currentStyles = [];

    function fmtBytes(bytes) {
        const n = Number(bytes);
        if (!n || n <= 0) return '';
        if (n >= 1024 * 1024) return (n / (1024 * 1024)).toFixed(1) + ' MB';
        if (n >= 1024) return Math.round(n / 1024) + ' KB';
        return n + ' B';
    }

    function slugify(text) {
        return String(text || 'objeto')
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'objeto';
    }

    function itemUrl(v) {
        return '/objeto/objeto/' + slugify(v.nombre_objeto) + '--' + encodeURIComponent(v.cosmetic_id);
    }

    /* ------------------------------ búsqueda ------------------------------ */
    let searchTimer = null;
    $('search-input').addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = $('search-input').value.trim();
        if (q.length < 2) { $('search-results').classList.add('hidden'); return; }
        searchTimer = setTimeout(async () => {
            try {
                const res = await fetch(API + '?action=search&q=' + encodeURIComponent(q));
                const data = await res.json();
                renderResults(data.results || []);
            } catch (_) { renderResults([]); }
        }, 250);
    });

    function renderResults(results) {
        const box = $('search-results');
        box.innerHTML = '';
        if (!results.length) { box.classList.add('hidden'); return; }
        results.forEach((r) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'w-full flex items-center gap-3 px-3 py-2 text-left hover:opacity-80';
            const img = document.createElement('img');
            img.src = r.image || '';
            img.alt = '';
            img.className = 'w-9 h-9 object-contain rounded bg-black/20 flex-shrink-0';
            const meta = document.createElement('div');
            meta.className = 'min-w-0';
            const name = document.createElement('p');
            name.className = 'text-sm font-medium truncate';
            name.textContent = r.name;
            const sub = document.createElement('p');
            sub.className = 'text-xs truncate';
            sub.style.color = 'var(--text-secondary)';
            sub.textContent = (r.type ? r.type + ' · ' : '') + r.id;
            meta.append(name, sub);
            row.append(img, meta);
            row.addEventListener('click', () => selectItem(r));
            box.appendChild(row);
        });
        box.classList.remove('hidden');
    }

    async function selectItem(r) {
        $('cosmetic-id').value = r.id;
        $('nombre-objeto').value = r.name || '';
        $('selected-name').textContent = r.name || r.id;
        $('selected-id').textContent = r.id;
        $('selected-image').src = r.image || '';
        $('selected-item').classList.remove('hidden');
        $('selected-item').classList.add('flex');
        $('search-results').classList.add('hidden');
        $('search-input').value = '';
        await loadStyles(r.id);
    }

    $('clear-selection').addEventListener('click', () => {
        $('cosmetic-id').value = '';
        $('nombre-objeto').value = '';
        $('selected-item').classList.add('hidden');
        $('selected-item').classList.remove('flex');
        resetStyles();
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#search-results') && !e.target.closest('#search-input')) {
            $('search-results').classList.add('hidden');
        }
    });

    /* ------------------------------ estilos ------------------------------- */
    function resetStyles() {
        currentStyles = [];
        $('style-key').value = '';
        $('style-name').value = '';
        $('styles-wrap').classList.add('hidden');
        $('styles-none').classList.add('hidden');
        $('style-chips').innerHTML = '';
    }

    async function loadStyles(cosmeticId) {
        resetStyles();
        let styles = [];
        try {
            const res = await fetch(API + '?action=item_styles&id=' + encodeURIComponent(cosmeticId));
            const data = await res.json();
            styles = data.styles || [];
        } catch (_) { }
        currentStyles = styles;
        if (!styles.length) { $('styles-none').classList.remove('hidden'); return; }
        renderStyleChips(cosmeticId);
        $('styles-wrap').classList.remove('hidden');
    }

    function stylesWithVideo(cosmeticId) {
        const keys = new Set();
        allVideos.forEach((v) => {
            if (String(v.cosmetic_id).toLowerCase() === String(cosmeticId).toLowerCase() && v.style_key) {
                keys.add(String(v.style_key).toLowerCase());
            }
        });
        return keys;
    }

    function renderStyleChips(cosmeticId) {
        const box = $('style-chips');
        box.innerHTML = '';
        const withVideo = stylesWithVideo(cosmeticId);
        const options = [{ key: '', name: 'Principal (por defecto)', image: null }].concat(currentStyles);
        options.forEach((s) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'vo-style-chip' + (($('style-key').value || '') === s.key ? ' active' : '');
            chip.dataset.styleKey = s.key;
            if (s.image) {
                const img = document.createElement('img');
                img.src = s.image;
                img.alt = '';
                chip.appendChild(img);
            } else {
                const icon = document.createElement('i');
                icon.className = 'fas fa-star ml-2';
                icon.style.color = 'var(--accent)';
                chip.appendChild(icon);
            }
            const label = document.createElement('span');
            label.textContent = s.name;
            chip.appendChild(label);
            const hasVideo = s.key === ''
                ? allVideos.some((v) => String(v.cosmetic_id).toLowerCase() === String(cosmeticId).toLowerCase() && !v.style_key)
                : withVideo.has(s.key);
            if (hasVideo) {
                const dot = document.createElement('span');
                dot.className = 'vo-chip-dot';
                dot.title = 'Ya tiene video';
                chip.appendChild(dot);
            }
            chip.addEventListener('click', () => {
                $('style-key').value = s.key;
                $('style-name').value = s.key ? s.name : '';
                box.querySelectorAll('.vo-style-chip').forEach((c) => c.classList.toggle('active', c === chip));
            });
            box.appendChild(chip);
        });
        // Principal seleccionado por defecto.
        const first = box.querySelector('.vo-style-chip');
        if (first && !box.querySelector('.vo-style-chip.active')) first.classList.add('active');
    }

    /* ------------------------------ dropzone ------------------------------ */
    const dropzone = $('dropzone');
    const fileInput = $('video-file');

    dropzone.addEventListener('click', () => fileInput.click());
    dropzone.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
    ['dragenter', 'dragover'].forEach((ev) => dropzone.addEventListener(ev, (e) => { e.preventDefault(); dropzone.classList.add('dragover'); }));
    ['dragleave', 'drop'].forEach((ev) => dropzone.addEventListener(ev, (e) => { e.preventDefault(); dropzone.classList.remove('dragover'); }));
    dropzone.addEventListener('drop', (e) => {
        const file = e.dataTransfer?.files?.[0];
        if (file) { fileInput.files = e.dataTransfer.files; onFilePicked(); }
    });
    fileInput.addEventListener('change', onFilePicked);
    $('file-clear').addEventListener('click', clearFile);

    function onFilePicked() {
        const file = fileInput.files && fileInput.files[0];
        if (!file) { clearFile(); return; }
        if (!/\.(webm|mp4)$/i.test(file.name)) {
            setStatus('Formato no soportado (usa .webm o .mp4).', false);
            clearFile();
            return;
        }
        $('file-name').textContent = file.name;
        $('file-size').textContent = fmtBytes(file.size);
        $('file-warn').classList.toggle('hidden', file.size < BIG_FILE_BYTES);
        $('file-info').classList.remove('hidden');
        $('file-info').classList.add('flex');
        try { showPreview(URL.createObjectURL(file)); } catch (_) { }
    }

    function clearFile() {
        fileInput.value = '';
        $('file-info').classList.add('hidden');
        $('file-info').classList.remove('flex');
        $('file-warn').classList.add('hidden');
    }

    /* ------------------------------- guardar ------------------------------ */
    $('video-form').addEventListener('submit', (e) => {
        e.preventDefault();
        const cosmeticId = $('cosmetic-id').value.trim();
        if (!cosmeticId) { setStatus('Selecciona primero un objeto.', false); return; }
        const hasFile = fileInput.files && fileInput.files[0];
        if (!hasFile && !$('video-url').value.trim()) { setStatus('Proporciona una URL o un archivo de video.', false); return; }

        const fd = new FormData($('video-form'));
        fd.append('action', 'save');
        $('save-btn').disabled = true;
        setStatus(hasFile ? 'Subiendo archivo...' : 'Guardando (la descarga/re-hospedaje puede tardar)...', null);

        // XHR (no fetch) para poder mostrar progreso real de subida.
        const xhr = new XMLHttpRequest();
        xhr.open('POST', API);
        if (hasFile) {
            $('upload-progress').classList.remove('hidden');
            xhr.upload.onprogress = (ev) => {
                if (!ev.lengthComputable) return;
                const pct = Math.round((ev.loaded / ev.total) * 100);
                $('upload-bar').style.width = pct + '%';
                $('upload-label').textContent = 'Subiendo… ' + pct + '% (' + fmtBytes(ev.loaded) + ' de ' + fmtBytes(ev.total) + ')';
            };
        }
        xhr.onload = () => {
            $('save-btn').disabled = false;
            $('upload-progress').classList.add('hidden');
            $('upload-bar').style.width = '0%';
            let data = null;
            try { data = JSON.parse(xhr.responseText); } catch (_) { }
            const ok = !!(data && data.ok);
            setStatus((data && data.message) || (ok ? 'Guardado.' : 'Error.'), ok);
            if (ok) {
                if (data.url) showPreview(data.url);
                $('video-url').value = '';
                clearFile();
                loadList().then(() => {
                    const cid = $('cosmetic-id').value.trim();
                    if (cid && currentStyles.length) renderStyleChips(cid);
                });
            }
        };
        xhr.onerror = () => {
            $('save-btn').disabled = false;
            $('upload-progress').classList.add('hidden');
            setStatus('Error de red al guardar.', false);
        };
        xhr.send(fd);
    });

    function setStatus(msg, ok) {
        const p = $('form-status');
        p.textContent = msg;
        p.classList.remove('hidden');
        p.style.color = ok === null ? 'var(--text-secondary)' : (ok ? '#22c55e' : '#ef4444');
    }

    function showPreview(url) {
        $('preview-video').src = url;
        $('preview-wrap').classList.remove('hidden');
    }

    /* ------------------------------- listado ------------------------------ */
    async function loadList() {
        try {
            const res = await fetch(API + '?action=list');
            const data = await res.json();
            allVideos = data.videos || [];
        } catch (_) { allVideos = []; }
        renderList();
    }

    function applyFilters(groups) {
        const text = $('filter-text').value.trim().toLowerCase();
        const state = $('filter-state').value;
        return groups.filter((g) => {
            if (text && !((g.name || '').toLowerCase().includes(text) || g.cosmeticId.toLowerCase().includes(text))) return false;
            if (state === 'on' && !g.rows.some((v) => String(v.activo) === '1')) return false;
            if (state === 'off' && !g.rows.some((v) => String(v.activo) !== '1')) return false;
            if (state === 'shop' && !g.inShop) return false;
            if (state === 'styles' && !g.rows.some((v) => v.style_key)) return false;
            return true;
        });
    }

    function groupVideos() {
        const map = new Map();
        allVideos.forEach((v) => {
            const cid = String(v.cosmetic_id).toLowerCase();
            if (!map.has(cid)) {
                map.set(cid, { cosmeticId: cid, name: v.nombre_objeto || cid, image: v.image || null, inShop: !!v.in_shop, rows: [] });
            }
            const g = map.get(cid);
            if (v.nombre_objeto) g.name = v.nombre_objeto;
            if (v.image) g.image = v.image;
            if (v.in_shop) g.inShop = true;
            g.rows.push(v);
        });
        map.forEach((g) => g.rows.sort((a, b) => String(a.style_key || '').localeCompare(String(b.style_key || ''))));
        return Array.from(map.values());
    }

    function renderList() {
        const wrap = $('videos-cards');
        wrap.innerHTML = '';
        const groups = applyFilters(groupVideos());
        $('video-count').textContent = '(' + allVideos.length + ' video' + (allVideos.length === 1 ? '' : 's') + ')';
        $('empty-msg').classList.toggle('hidden', groups.length > 0);
        groups.forEach((g) => wrap.appendChild(buildCard(g)));
    }

    function buildCard(g) {
        const card = document.createElement('div');
        card.className = 'vo-card';

        const head = document.createElement('div');
        head.className = 'vo-card__head';
        const img = document.createElement('img');
        img.src = g.image || '';
        img.alt = '';
        const meta = document.createElement('div');
        meta.className = 'min-w-0';
        const name = document.createElement('p');
        name.className = 'text-sm font-semibold truncate';
        name.textContent = g.name;
        const sub = document.createElement('p');
        sub.className = 'text-xs truncate';
        sub.style.color = 'var(--text-secondary)';
        sub.textContent = g.cosmeticId;
        meta.append(name, sub);
        head.append(img, meta);

        const badges = document.createElement('div');
        badges.className = 'ml-auto flex items-center gap-2';
        if (g.inShop) {
            const b = document.createElement('span');
            b.className = 'vo-badge vo-badge--shop';
            b.textContent = 'En tienda hoy';
            badges.appendChild(b);
        }
        const site = document.createElement('a');
        site.href = itemUrl(g.rows[0]);
        site.target = '_blank';
        site.rel = 'noopener';
        site.className = 'vo-icon-btn';
        site.title = 'Ver en el sitio';
        site.innerHTML = '<i class="fas fa-arrow-up-right-from-square"></i>';
        badges.appendChild(site);
        head.appendChild(badges);
        card.appendChild(head);

        g.rows.forEach((v) => card.appendChild(buildRow(v)));
        return card;
    }

    function buildRow(v) {
        const active = String(v.activo) === '1';
        const row = document.createElement('div');
        row.className = 'vo-row';

        // Mini-preview: el video solo se carga/reproduce al pasar el mouse.
        const preview = document.createElement('div');
        preview.className = 'vo-preview';
        const vid = document.createElement('video');
        vid.muted = true;
        vid.loop = true;
        vid.playsInline = true;
        vid.preload = 'none';
        vid.dataset.src = v.video_url;
        preview.appendChild(vid);
        preview.addEventListener('mouseenter', () => {
            if (!vid.src) vid.src = vid.dataset.src;
            vid.play().catch(() => { });
        });
        preview.addEventListener('mouseleave', () => { try { vid.pause(); } catch (_) { } });
        preview.addEventListener('click', () => window.open(v.video_url, '_blank', 'noopener'));

        const meta = document.createElement('div');
        meta.className = 'min-w-0 flex-1';
        const label = document.createElement('p');
        label.className = 'text-sm truncate';
        label.innerHTML = v.style_key
            ? '<i class="fas fa-palette mr-1" style="color: var(--accent);"></i>'
            : '<i class="fas fa-star mr-1" style="color: var(--accent);"></i>';
        label.appendChild(document.createTextNode(v.style_key ? (v.style_name || v.style_key) : 'Principal'));
        const info = document.createElement('p');
        info.className = 'text-xs truncate';
        info.style.color = 'var(--text-secondary)';
        const size = v.file_size ? fmtBytes(v.file_size) : '';
        info.textContent = [size, v.actualizado_en || ''].filter(Boolean).join(' · ');
        meta.append(label, info);

        const badges = document.createElement('div');
        badges.className = 'flex items-center gap-2';
        if (v.file_size && Number(v.file_size) >= BIG_FILE_BYTES) {
            const big = document.createElement('span');
            big.className = 'vo-badge vo-badge--big';
            big.title = 'Archivo pesado: puede tardar en cargar la primera vez';
            big.textContent = fmtBytes(v.file_size);
            badges.appendChild(big);
        }
        const state = document.createElement('span');
        state.className = 'vo-badge ' + (active ? 'vo-badge--on' : 'vo-badge--off');
        state.textContent = active ? 'Activo' : 'Inactivo';
        badges.appendChild(state);

        const actions = document.createElement('div');
        actions.className = 'flex items-center gap-1.5';
        const toggleBtn = document.createElement('button');
        toggleBtn.className = 'vo-icon-btn';
        toggleBtn.innerHTML = active ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
        toggleBtn.title = active ? 'Desactivar' : 'Activar';
        toggleBtn.addEventListener('click', () => toggleVideo(v.id, !active));
        const delBtn = document.createElement('button');
        delBtn.className = 'vo-icon-btn text-red-500';
        delBtn.innerHTML = '<i class="fas fa-trash"></i>';
        delBtn.title = 'Eliminar';
        delBtn.addEventListener('click', () => deleteVideo(v.id, (v.nombre_objeto || v.cosmetic_id) + (v.style_key ? ' — ' + (v.style_name || v.style_key) : '')));
        actions.append(toggleBtn, delBtn);

        row.append(preview, meta, badges, actions);
        return row;
    }

    async function toggleVideo(id, activate) {
        const fd = new FormData();
        fd.append('action', 'toggle');
        fd.append('id', String(id));
        fd.append('activo', activate ? '1' : '0');
        try { await fetch(API, { method: 'POST', body: fd }); } catch (_) { }
        loadList();
    }

    async function deleteVideo(id, label) {
        if (!confirm('¿Eliminar el video de "' + label + '"? Esta acción no se puede deshacer.')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', String(id));
        try { await fetch(API, { method: 'POST', body: fd }); } catch (_) { }
        loadList();
    }

    $('reload-btn').addEventListener('click', loadList);
    $('filter-text').addEventListener('input', renderList);
    $('filter-state').addEventListener('change', renderList);
    loadList();
})();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

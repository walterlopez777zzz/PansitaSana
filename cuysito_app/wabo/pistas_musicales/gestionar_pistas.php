<?php
// Archivo: gestionar_pistas.php (v2.1 - Correcciones de UI y Bugs)
require_once __DIR__ . '/../core/admin_header.php'; // Mantiene la estructura del panel
?>

<!-- Estilos específicos para esta página -->
<style>
    .youtube-input-container:focus-within {
        box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.5); /* Sombra roja al enfocar */
    }
    .toast {
        animation: slideIn 0.3s ease-out, fadeOut 0.5s ease-out 2.5s forwards;
    }
    @keyframes slideIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    @keyframes fadeOut { to { opacity: 0; } }
    
    /* Estilos para el estado de carga */
    .loading-placeholder {
        background-color: var(--secondary);
        border-radius: 0.5rem;
        padding: 1.5rem;
        text-align: center;
        color: var(--text-secondary);
    }
    .loading-placeholder .fa-spinner {
        margin-right: 0.5rem;
    }
    
    /* === CORRECCIÓN DE VISIBILIDAD TABLA/MÓVIL === */
    /* Por defecto, las tarjetas móviles están ocultas */
    #with-video-tracks-mobile { display: none; }
    /* En pantallas pequeñas (móviles), ocultamos la tabla de escritorio y mostramos las tarjetas */
    @media (max-width: 767px) {
        #with-video-tracks-table { display: none; }
        #with-video-tracks-mobile { display: block; }
    }
    
    /* Avatar genérico para pistas sin imagen */
    .generic-artwork {
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: var(--primary);
        color: var(--text-secondary);
    }
</style>

<!-- =============================================================== -->
<!-- === HTML de la Plantilla Integrado en el Panel === -->
<!-- =============================================================== -->

<!-- Header de la página -->
<header class="card rounded-lg shadow-sm p-4 sm:p-6 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <h1 class="text-2xl font-bold">Añadir videos a pistas musicales</h1>
        
        <!-- === CORRECCIÓN DE ALINEACIÓN DEL CONTADOR === -->
        <div class="mt-2 md:mt-0 flex flex-col items-start md:items-end">
             <div class="text-xs mb-1" style="color: var(--text-secondary);">Pistas históricas: <span id="historical-tracks" class="font-bold" style="color: var(--text-primary);">0</span></div>
             <div class="w-full md:w-64">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm font-medium" style="color: var(--text-secondary);">Videos añadidos hoy: <span id="progress-current" class="font-bold" style="color: var(--text-primary);">0</span>/<span id="progress-total" class="font-bold" style="color: var(--text-primary);">0</span></span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                    <div id="progress-bar" class="bg-green-500 h-2.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Buscador y Filtros -->
<section class="mb-6">
    <div class="card rounded-lg shadow-sm p-4">
        <div class="flex flex-col md:flex-row gap-4">
            <div class="flex-1 relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-search" style="color: var(--text-secondary);"></i>
                </div>
                <input type="text" id="search-input" class="block w-full pl-10 pr-3 py-2 border rounded-lg focus:ring-2 focus:border-transparent" style="background-color: var(--primary); border-color: var(--border-color); --tw-ring-color: var(--accent);" placeholder="Buscar por nombre o artista...">
            </div>
            <button id="sort-alphabetical" class="px-4 py-2 rounded-lg text-sm font-medium flex items-center justify-center" style="background-color: var(--primary); color: var(--text-secondary); border: 1px solid var(--border-color);">
                <i class="fas fa-sort-alpha-down mr-2"></i>Orden alfabético
            </button>
        </div>
    </div>
</section>

<!-- Contenido Principal -->
<main>
    <!-- Sección A: Pistas sin video -->
    <section id="section-no-video" class="mb-8 hidden">
        <div class="bg-yellow-500/10 dark:bg-yellow-500/20 rounded-t-lg px-4 py-3 flex items-center">
            <span class="text-yellow-500 rounded-full w-6 h-6 flex items-center justify-center mr-2"><i class="fas fa-exclamation"></i></span>
            <h2 class="font-semibold">Pistas sin video</h2>
            <span id="count-no-video" class="ml-auto bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 text-xs font-medium px-2.5 py-0.5 rounded-full">0 pistas</span>
        </div>
        <div class="card shadow-sm rounded-b-lg overflow-hidden">
            <div id="no-video-tracks" class="divide-y" style="border-color: var(--border-color);"></div>
        </div>
    </section>

    <!-- Sección B: Pistas con video (en tienda) -->
    <section id="section-with-video" class="mb-8 hidden">
        <div class="bg-green-500/10 dark:bg-green-500/20 rounded-t-lg px-4 py-3 flex items-center">
            <span class="text-green-500 rounded-full w-6 h-6 flex items-center justify-center mr-2"><i class="fas fa-check"></i></span>
            <h2 class="font-semibold">Pistas con video (en tienda)</h2>
            <span id="count-with-video" class="ml-auto bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 text-xs font-medium px-2.5 py-0.5 rounded-full">0 pistas</span>
        </div>
        <div class="card shadow-sm rounded-b-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table id="with-video-tracks-table" class="w-full">
                    <thead style="background-color: var(--primary);">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Portada</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Pista</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">ID de YouTube</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Inicio</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="with-video-tracks" class="divide-y" style="border-color: var(--border-color);"></tbody>
                </table>
                 <div id="with-video-tracks-mobile" class="divide-y" style="border-color: var(--border-color);"></div>
            </div>
        </div>
    </section>

    <!-- Sección C: Pistas fuera de tienda -->
    <section id="section-outside-shop" class="mb-8 hidden">
        <div class="bg-purple-500/10 dark:bg-purple-500/20 rounded-t-lg px-4 py-3 flex items-center">
            <span class="text-purple-500 rounded-full w-6 h-6 flex items-center justify-center mr-2"><i class="fas fa-history"></i></span>
            <h2 class="font-semibold">Fuera de tienda (con video)</h2>
            <span id="count-outside-shop" class="ml-auto bg-purple-100 dark:bg-purple-900 text-purple-800 dark:text-purple-200 text-xs font-medium px-2.5 py-0.5 rounded-full">0 pistas</span>
        </div>
        <div class="card shadow-sm rounded-b-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                     <thead style="background-color: var(--primary);">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Portada</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Pista</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">ID de YouTube</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Inicio</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="outside-shop-tracks" class="divide-y" style="border-color: var(--border-color);"></tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Placeholder de Carga Inicial -->
    <div id="loading-container" class="loading-placeholder">
        <i class="fas fa-spinner fa-spin"></i> Cargando pistas desde la API y la base de datos...
    </div>

</main>

<!-- Modal de Video (Sin cambios) -->
<div id="video-modal" class="fixed inset-0 z-50 hidden overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity" aria-hidden="true"><div class="absolute inset-0 bg-black/70"></div></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">​</span>
        <div class="inline-block align-bottom card rounded-lg text-left overflow-hidden shadow-xl transform transition-all w-full mx-2 sm:my-8 sm:align-middle sm:max-w-lg">
            <div class="p-4 sm:p-6 sm:pb-4">
                <h3 class="text-lg leading-6 font-medium mb-4" id="modal-title">
                    <span id="modal-action">Añadir</span> video a <span id="modal-track-name" class="font-bold"></span>
                </h3>
                <div>
                    <div class="mb-4">
                        <label for="youtube-url" class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">URL o ID de YouTube</label>
                        <div class="youtube-input-container flex rounded-md shadow-sm border focus-within:border-red-500" style="border-color:var(--border-color);">
                            <span class="inline-flex items-center px-3 rounded-l-md text-sm" style="background-color:var(--primary); color:var(--text-secondary);"><i class="fab fa-youtube text-red-500 mr-2"></i>youtu.be/</span>
                            <input type="text" id="youtube-url" class="flex-1 block w-full rounded-none rounded-r-md border-none focus:ring-0 sm:text-sm" style="background-color:var(--secondary);" placeholder="ID_DEL_VIDEO">
                        </div>
                    </div>
                    <div>
                        <label for="start-second" class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Segundo de inicio</label>
                        <input type="number" id="start-second" class="block w-full rounded-md border shadow-sm focus:ring-2 sm:text-sm" style="background-color:var(--secondary); border-color:var(--border-color); --tw-ring-color: var(--accent);" min="0" value="0">
                    </div>
                </div>
            </div>
            <div class="px-4 py-3 sm:px-6 flex flex-row-reverse" style="background-color:var(--primary);">
                <button id="save-video" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 text-white font-bold sm:ml-3 sm:w-auto sm:text-sm" style="background-color:var(--accent);">Guardar</button>
                <button id="cancel-modal" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border shadow-sm px-4 py-2 sm:mt-0 sm:w-auto sm:text-sm" style="border-color:var(--border-color); background-color:var(--secondary); color:var(--text-primary);">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast de Notificación (Sin cambios) -->
<div id="toast" class="fixed bottom-4 right-4 z-50 hidden">
    <div class="toast card shadow-lg rounded-lg overflow-hidden border max-w-sm w-full">
        <div class="flex items-center px-4 py-3">
            <div id="toast-icon" class="flex-shrink-0"></div>
            <div class="ml-3"><p id="toast-message" class="text-sm font-medium"></p></div>
        </div>
        <div id="toast-progress" class="h-1 w-full"></div>
    </div>
</div>

<!-- =============================================================== -->
<!-- === JavaScript para la Lógica de la Página === -->
<!-- =============================================================== -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- Referencias al DOM (sin cambios) ---
        const elements = {
            progressCurrent: document.getElementById('progress-current'),
            progressTotal: document.getElementById('progress-total'),
            progressBar: document.getElementById('progress-bar'),
            historicalTracks: document.getElementById('historical-tracks'),
            countNoVideo: document.getElementById('count-no-video'),
            countWithVideo: document.getElementById('count-with-video'),
            countOutsideShop: document.getElementById('count-outside-shop'),
            noVideoTracksContainer: document.getElementById('no-video-tracks'),
            withVideoTracksContainer: document.getElementById('with-video-tracks'),
            withVideoTracksMobileContainer: document.getElementById('with-video-tracks-mobile'),
            outsideShopTracksContainer: document.getElementById('outside-shop-tracks'),
            searchInput: document.getElementById('search-input'),
            sortButton: document.getElementById('sort-alphabetical'),
            modal: document.getElementById('video-modal'),
            modalAction: document.getElementById('modal-action'),
            modalTrackName: document.getElementById('modal-track-name'),
            youtubeUrlInput: document.getElementById('youtube-url'),
            startSecondInput: document.getElementById('start-second'),
            saveButton: document.getElementById('save-video'),
            cancelButton: document.getElementById('cancel-modal'),
            toast: document.getElementById('toast'),
            toastIcon: document.getElementById('toast-icon'),
            toastMessage: document.getElementById('toast-message'),
            toastProgress: document.getElementById('toast-progress'),
            loadingContainer: document.getElementById('loading-container'),
            sectionNoVideo: document.getElementById('section-no-video'),
            sectionWithVideo: document.getElementById('section-with-video'),
            sectionOutsideShop: document.getElementById('section-outside-shop'),
        };

        // --- Estado de la aplicación (sin cambios) ---
        let allTracksData = { inShopNoVideo: [], inShopWithVideo: [], outOfShopWithVideo: [] };
        let currentTrack = null;
        let isSorted = false;

        // --- Lógica Principal (sin cambios) ---
        async function fetchAndRenderTracks() {
            try {
                const response = await fetch('pistas_musicales/api_pistas.php?action=get_all_tracks');
                if (!response.ok) throw new Error('La respuesta de la API no fue exitosa.');
                const data = await response.json();
                allTracksData = data;
                elements.loadingContainer.style.display = 'none';
                updateUI();
                updateCounters();
            } catch (error) {
                console.error('Error fetching tracks:', error);
                elements.loadingContainer.innerHTML = '<i class="fas fa-exclamation-triangle text-red-500"></i> Error al cargar las pistas.';
                showToast('Error al cargar las pistas', 'error');
            }
        }
        
        function updateUI(searchTerm = '') {
            const filter = searchTerm.toLowerCase();
            const renderList = (container, tracks, templateFn) => {
                const filtered = tracks.filter(t => t.name.toLowerCase().includes(filter) || (t.artist && t.artist.toLowerCase().includes(filter)));
                if (isSorted) {
                    filtered.sort((a, b) => a.name.localeCompare(b.name));
                }
                container.innerHTML = filtered.length ? filtered.map(templateFn).join('') : `<div class="p-4 text-center" style="color:var(--text-secondary);">No se encontraron pistas.</div>`;
            };
            
            renderList(elements.noVideoTracksContainer, allTracksData.inShopNoVideo, trackNoVideoTemplate);
            renderList(elements.withVideoTracksContainer, allTracksData.inShopWithVideo, trackWithVideoTemplate);
            renderList(elements.withVideoTracksMobileContainer, allTracksData.inShopWithVideo, trackWithVideoMobileTemplate);
            renderList(elements.outsideShopTracksContainer, allTracksData.outOfShopWithVideo, trackOutsideShopTemplate);

            elements.sectionNoVideo.style.display = allTracksData.inShopNoVideo.length > 0 ? 'block' : 'none';
            elements.sectionWithVideo.style.display = allTracksData.inShopWithVideo.length > 0 ? 'block' : 'none';
            elements.sectionOutsideShop.style.display = allTracksData.outOfShopWithVideo.length > 0 ? 'block' : 'none';
            addEventListenersToButtons();
        }

        function updateCounters() {
            const inShopTotal = allTracksData.inShopNoVideo.length + allTracksData.inShopWithVideo.length;
            const inShopWithVideoCount = allTracksData.inShopWithVideo.length;
            const historicalCount = allTracksData.outOfShopWithVideo.length + inShopWithVideoCount;
            elements.progressCurrent.textContent = inShopWithVideoCount;
            elements.progressTotal.textContent = inShopTotal;
            elements.progressBar.style.width = inShopTotal > 0 ? `${(inShopWithVideoCount / inShopTotal) * 100}%` : '0%';
            elements.historicalTracks.textContent = historicalCount;
            elements.countNoVideo.textContent = `${allTracksData.inShopNoVideo.length} pistas`;
            elements.countWithVideo.textContent = `${allTracksData.inShopWithVideo.length} pistas`;
            elements.countOutsideShop.textContent = `${allTracksData.outOfShopWithVideo.length} pistas`;
        }

        // --- Plantillas HTML ---
        
        // === CORRECCIÓN IMAGEN GENÉRICA ===
        const artworkTemplate = (track, sizeClass) => {
            if (track.artwork && track.artwork.startsWith('http')) {
                return `<img src="${track.artwork}" alt="${track.name}" class="${sizeClass} rounded-lg object-cover">`;
            }
            return `<div class="${sizeClass} rounded-lg generic-artwork"><i class="fas fa-music text-2xl"></i></div>`;
        };
        
        const trackNoVideoTemplate = track => `
            <div class="flex items-center p-4 hover:bg-gray-500/10 transition-colors">
                ${artworkTemplate(track, 'w-16 h-16')}
                <div class="flex-1 ml-4">
                    <h3 class="font-semibold">${track.name}</h3>
                    <p class="text-sm" style="color:var(--text-secondary);">${track.artist}</p>
                </div>
                <button class="action-btn px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition-colors" data-action="add" data-name="${track.name}" data-artwork="${track.artwork || ''}">
                    <i class="fas fa-plus mr-2"></i>Añadir Video
                </button>
            </div>`;

        const trackWithVideoTemplate = track => `
            <tr class="hover:bg-gray-500/10 transition-colors">
                <td class="px-6 py-4 whitespace-nowrap">${artworkTemplate(track, 'w-10 h-10')}</td>
                <td class="px-6 py-4">
                    <div class="font-medium">${track.name}</div>
                    <div class="text-sm" style="color:var(--text-secondary);">${track.artist}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap"><a href="https://youtu.be/${track.youtube_id}" target="_blank" class="text-blue-500 hover:underline">${track.youtube_id}</a></td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">${track.start_time}s</td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <button class="action-btn mr-2 px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm transition-colors" data-action="edit" data-name="${track.name}" data-artwork="${track.artwork || ''}" data-youtubeid="${track.youtube_id}" data-starttime="${track.start_time}"><i class="fas fa-pencil-alt"></i></button>
                    <button class="action-btn px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-sm transition-colors" data-action="remove" data-name="${track.name}"><i class="fas fa-trash-alt"></i></button>
                </td>
            </tr>`;
        
        const trackWithVideoMobileTemplate = track => `
            <div class="p-4">
                <div class="flex items-start gap-4">
                     ${artworkTemplate(track, 'w-16 h-16')}
                     <div class="flex-1">
                        <h3 class="font-medium">${track.name}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">${track.artist}</p>
                        <div class="mt-2 text-sm space-y-1">
                            <div><strong style="color:var(--text-secondary);">YouTube:</strong> <a href="https://youtu.be/${track.youtube_id}" target="_blank" class="text-blue-500 hover:underline">${track.youtube_id}</a></div>
                            <div><strong style="color:var(--text-secondary);">Inicio:</strong> ${track.start_time}s</div>
                        </div>
                     </div>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button class="action-btn px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm" data-action="edit" data-name="${track.name}" data-artwork="${track.artwork || ''}" data-youtubeid="${track.youtube_id}" data-starttime="${track.start_time}"><i class="fas fa-pencil-alt mr-1"></i>Editar</button>
                    <button class="action-btn px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm" data-action="remove" data-name="${track.name}"><i class="fas fa-trash-alt mr-1"></i>Quitar</button>
                </div>
            </div>`;

        const trackOutsideShopTemplate = track => `
            <tr class="hover:bg-gray-500/10 transition-colors opacity-70">
                <td class="px-6 py-4 whitespace-nowrap">${artworkTemplate(track, 'w-10 h-10')}</td>
                <td class="px-6 py-4">
                    <div class="font-medium">${track.name}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap"><a href="https://youtu.be/${track.youtube_id}" target="_blank" class="text-blue-500 hover:underline">${track.youtube_id}</a></td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">${track.start_time}s</td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <button class="action-btn mr-2 px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm transition-colors" data-action="edit" data-name="${track.name}" data-artwork="${track.artwork || ''}" data-youtubeid="${track.youtube_id}" data-starttime="${track.start_time}"><i class="fas fa-pencil-alt"></i></button>
                    <button class="action-btn px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-sm transition-colors" data-action="remove" data-name="${track.name}"><i class="fas fa-trash-alt"></i></button>
                </td>
            </tr>`;

        // --- Gestión de Eventos y Modal (sin cambios) ---
        function addEventListenersToButtons() { document.querySelectorAll('.action-btn').forEach(btn => btn.addEventListener('click', handleActionClick)); }
        function handleActionClick(e) {
            const button = e.currentTarget;
            currentTrack = { name: button.dataset.name, artwork: button.dataset.artwork, youtube_id: button.dataset.youtubeid || '', start_time: button.dataset.starttime || '0' };
            if (button.dataset.action === 'add' || button.dataset.action === 'edit') { openModal(button.dataset.action); } 
            else if (button.dataset.action === 'remove') { if (confirm(`¿Quitar video de "${button.dataset.name}"?`)) { saveVideoData(true); } }
        }
        function openModal(action) {
            elements.modalAction.textContent = action === 'add' ? 'Añadir' : 'Editar';
            elements.modalTrackName.textContent = currentTrack.name;
            elements.youtubeUrlInput.value = currentTrack.youtube_id;
            elements.startSecondInput.value = currentTrack.start_time;
            elements.modal.classList.remove('hidden'); document.body.classList.add('overflow-hidden');
        }
        function closeModal() {
            elements.modal.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); currentTrack = null;
        }
        async function saveVideoData(isRemoval = false) {
            const formData = new FormData();
            formData.append('action', isRemoval ? 'remove_video' : 'save_video');
            formData.append('track_name', currentTrack.name);
            if (!isRemoval) {
                const youtubeValue = elements.youtubeUrlInput.value.trim();
                let youtubeId = youtubeValue;
                if (youtubeValue.includes('youtu.be/')) youtubeId = youtubeValue.split('youtu.be/')[1].split('?')[0];
                else if (youtubeValue.includes('v=')) youtubeId = youtubeValue.split('v=')[1].split('&')[0];
                if (!youtubeId || youtubeId.length < 10) { showToast('ID de YouTube no válido', 'error'); return; }
                formData.append('youtube_id', youtubeId);
                formData.append('start_time', elements.startSecondInput.value || 0);
            }
            try {
                const response = await fetch('pistas_musicales/api_pistas.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (response.ok) { showToast(result.message, 'success'); closeModal(); fetchAndRenderTracks(); } 
                else { throw new Error(result.message); }
            } catch (error) { showToast(error.message || 'Error al guardar', 'error'); }
        }
        function showToast(message, type = 'success') {
            elements.toastMessage.textContent = message;
            if (type === 'success') {
                elements.toastIcon.innerHTML = '<i class="fas fa-check-circle text-green-500 text-xl"></i>';
                elements.toastProgress.className = 'h-1 bg-green-500 w-full';
            } else {
                elements.toastIcon.innerHTML = '<i class="fas fa-exclamation-circle text-red-500 text-xl"></i>';
                elements.toastProgress.className = 'h-1 bg-red-500 w-full';
            }
            elements.toast.classList.remove('hidden');
            setTimeout(() => elements.toast.classList.add('hidden'), 3000);
        }

        // --- Eventos Iniciales (sin cambios) ---
        elements.searchInput.addEventListener('input', e => updateUI(e.target.value));
        elements.sortButton.addEventListener('click', () => {
            isSorted = !isSorted; elements.sortButton.classList.toggle('bg-blue-500', isSorted); elements.sortButton.classList.toggle('text-white', isSorted);
            updateUI(elements.searchInput.value); showToast(`Orden alfabético ${isSorted ? 'activado' : 'desactivado'}`, 'success');
        });
        elements.saveButton.addEventListener('click', () => saveVideoData(false));
        elements.cancelButton.addEventListener('click', closeModal);
        elements.modal.addEventListener('click', e => { if (e.target === elements.modal) closeModal(); });
        
        // Iniciar la aplicación
        fetchAndRenderTracks();
    });
</script>

<?php 
include __DIR__ . '/../core/admin_footer.php'; // Cierra la estructura del panel
?>
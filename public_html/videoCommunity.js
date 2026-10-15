// videoCommunity.js — Videos de la comunidad para las páginas de Jam Tracks.
// Módulo autocontenido: CTA de subida (solo si el servidor permite subir),
// crédito del autor (avatar + nombre), modal de envío con preview ligera,
// modal de reporte (motivos administrables desde el panel), edición del
// propio aporte antes de la verificación y botón flotante (trofeo) con el Top
// de colaboradores.
// API pública: window.BananoVideoCommunity.mount(host, { trackName, cosmeticId })
// y window.BananoVideoCommunity.unmount().
// Eventos: el host recibe `vc:published` ({ trackName, youtubeId, startTime,
// mediaType, mediaUrl, publishedAt }) cuando el visitante publica o edita su
// video, y `vc:deleted` ({ trackName, rewardReversed }) cuando lo elimina,
// para refrescar el reproductor in-place.
(() => {
    'use strict';

    const GATEWAY = '/web.php';
    const ACTION = 'v5c';
    const FALLBACK_AVATAR = 'https://res.cloudinary.com/dq3xsjgwi/image/upload/v1750347486/Iconofortnite_eykyvm.jpg';
    const COIN_ICON = '/assets/img/moneda.webp';
    const BACKDROP_CLIENT = 'vcModal';
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const PUBLISHED_EVENT = 'vc:published';
    const DELETED_EVENT = 'vc:deleted';

    const SYSTEM_CONTRIBUTOR = 'Banano';
    const AVATAR_SIZE = 28;
    const FAB_ID = 'vc-top-fab';

    const MEDIA_LABEL = Object.freeze({ youtube: 'Video', video: 'Video', audio: 'Audio' });

    // Fecha y hora exacta de publicación (ISO UTC del servidor → hora local
    // del visitante). Devuelve { text, iso } o null si la fecha no es válida.
    function formatPublishedAt(iso) {
        if (!iso) return null;
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) return null;
        let text;
        try {
            text = new Intl.DateTimeFormat('es-ES', {
                day: '2-digit', month: 'short', year: 'numeric',
                hour: '2-digit', minute: '2-digit', hour12: false,
            }).format(date).replace(',', ' ·');
        } catch (_) {
            text = date.toLocaleString();
        }
        return { text, iso: date.toISOString() };
    }

    // Mismo checkmark que .custom-checkbox en los filtros de la tienda y en
    // Sorteo Flash: marco, relleno y tick como tres paths [fill, d].
    const CHECK_PATHS = Object.freeze([
        ['#f7ff19', 'M17.8,24H6.2C2.8,24,0,21.2,0,17.8V6.2C0,2.8,2.8,0,6.2,0h11.7C21.2,0,24,2.8,24,6.2v11.7 C24,21.2,21.2,24,17.8,24z M6.2,2C3.9,2,2,3.9,2,6.2v11.7C2,20.1,3.9,22,6.2,22h11.7c2.3,0,4.1-1.8,4.1-4.1V6.2 C22,3.9,20.1,2,17.8,2H6.2z'],
        ['#f7ff19', 'M4,6.9C4,5.3,5.3,4,6.9,4h10.2C18.7,4,20,5.3,20,6.9v10.2c0,1.6-1.3,2.9-2.9,2.9H6.9C5.3,20,4,18.7,4,17.1V6.9z'],
        ['#18181b', 'M17,9.6l-6.5,6.7l-3.6-3.6l1.5-1.5c0.3-0.3,0.7-0.3,1,0l1,1l3.9-4.1c0.3-0.3,0.7-0.3,1,0L17,9.6z'],
    ]);

    // Estado por montaje: raíz en el DOM, AbortController para las peticiones
    // en vuelo y el modal abierto (si lo hay). null cuando no hay montaje.
    let instance = null;

    let ytApiPromise = null;

    function ensureYouTubeIframeApi() {
        if (window.YT && typeof window.YT.Player === 'function') return Promise.resolve();
        if (ytApiPromise) return ytApiPromise;
        ytApiPromise = new Promise((resolve) => {
            const previousReady = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = () => {
                if (typeof previousReady === 'function') {
                    try { previousReady(); } catch (_) { }
                }
                resolve();
            };
            const script = document.createElement('script');
            script.src = 'https://www.youtube.com/iframe_api';
            script.async = true;
            document.head.appendChild(script);
            const poll = setInterval(() => {
                if (window.YT && typeof window.YT.Player === 'function') {
                    clearInterval(poll);
                    resolve();
                }
            }, 50);
            setTimeout(() => clearInterval(poll), 6000);
        });
        return ytApiPromise;
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    // Login con retorno a la página actual (el servidor sanea `redirect_to`).
    function loginUrl() {
        const { pathname, search } = window.location;
        return `/login?redirect_to=${encodeURIComponent(pathname + search)}`;
    }

    function goToLogin() {
        window.location.assign(loginUrl());
    }

    // Resuelve siempre con el JSON del backend (también en 4xx) y anota el
    // código HTTP para que los handlers detecten sesión expirada (401).
    function api(sub, params, signal) {
        const body = new URLSearchParams({ action: ACTION, sub, ...(params || {}) });
        return fetch(GATEWAY, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body,
            signal,
        }).then((response) => response.json()
            .catch(() => null)
            .then((data) => ({
                ...(data && typeof data === 'object' ? data : { status: 'error' }),
                http_status: response.status,
            })));
    }

    // Fallo de una acción autenticada: con 401 la sesión caducó a mitad de
    // flujo, así que se reenvía al login; si no, se muestra el mensaje.
    function handleActionError(feedback, data, fallbackMessage) {
        if (data.http_status === 401) { goToLogin(); return; }
        showFeedback(feedback, data.message || fallbackMessage, false);
    }

    function extractYouTubeId(url) {
        const value = String(url || '').trim();
        if (/^[a-zA-Z0-9_-]{11}$/.test(value)) return value;
        const match = value.match(
            /(?:https?:\/\/)?(?:www\.|m\.)?(?:youtu\.be\/|youtube\.com\/(?:watch\?(?:[^&\s]*&)*v=|embed\/|shorts\/|live\/|v\/))([a-zA-Z0-9_-]{11})/
        );
        return match ? match[1] : null;
    }

    // Icono "i" accesible con tooltip desplegable (click/teclado).
    function infoTip(id, text) {
        const wrap = el('span', 'vc-tip');
        const btn = el('button', 'vc-tip__btn', 'i');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Más información');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-controls', `${id}-tip`);
        const bubble = el('span', 'vc-tip__bubble', text);
        bubble.id = `${id}-tip`;
        bubble.setAttribute('role', 'tooltip');
        bubble.hidden = true;
        btn.addEventListener('click', () => {
            bubble.hidden = !bubble.hidden;
            btn.setAttribute('aria-expanded', String(!bubble.hidden));
        });
        wrap.append(btn, bubble);
        return wrap;
    }

    function closeModal() {
        if (!instance || !instance.modal) return;
        const { overlay, onKeydown, restoreFocus, onClose } = instance.modal;
        if (typeof onClose === 'function') {
            try { onClose(); } catch (_) { }
        }
        document.removeEventListener('keydown', onKeydown);
        overlay.remove();
        window.Backdrop?.release(BACKDROP_CLIENT);
        instance.modal = null;
        if (restoreFocus && document.contains(restoreFocus)) {
            try { restoreFocus.focus(); } catch (_) { }
        }
    }

    // Modal accesible: role=dialog, focus trap, Escape y restauración del foco
    // al elemento que lo abrió. Velo, scroll-lock y clic fuera del panel los
    // aporta window.Backdrop (la capa es un .backdrop-layer transparente).
    function openModal(titleText, buildBody) {
        closeModal();
        const overlay = el('div', 'vc-modal-overlay backdrop-layer');
        const modal = el('div', 'vc-modal');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'vc-modal-title');

        const title = el('h2', 'vc-modal__title', titleText);
        title.id = 'vc-modal-title';
        const close = el('button', 'vc-modal__close', '✕');
        close.type = 'button';
        close.setAttribute('aria-label', 'Cerrar');
        close.addEventListener('click', closeModal);

        modal.append(close, title);
        buildBody(modal);
        overlay.appendChild(modal);

        const onKeydown = (event) => {
            if (event.key === 'Escape') { closeModal(); return; }
            if (event.key !== 'Tab') return;
            const focusables = modal.querySelectorAll(
                'button:not([disabled]), input:not([disabled]), a[href], summary, [tabindex]:not([tabindex="-1"])'
            );
            if (!focusables.length) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', onKeydown);

        instance.modal = { overlay, onKeydown, restoreFocus: document.activeElement };
        document.body.appendChild(overlay);
        window.Backdrop?.acquire(BACKDROP_CLIENT, overlay, closeModal, { lockScroll: true });
        (modal.querySelector('input, .vc-modal__primary') || close).focus();
        return modal;
    }

    function createFeedback() {
        const node = el('p', 'vc-feedback');
        node.setAttribute('role', 'alert');
        node.setAttribute('aria-live', 'assertive');
        node.hidden = true;
        return node;
    }

    function showFeedback(node, message, ok) {
        node.textContent = message;
        node.hidden = false;
        node.classList.toggle('vc-feedback--ok', !!ok);
    }

    function svgIcon(className, pathData) {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', className);
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', pathData);
        svg.append(path);
        return svg;
    }

    const trophyIcon = () => svgIcon(
        'vc-top__icon',
        'M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94.63 1.5 1.98 2.63 3.61 2.96V19H7v2h10v-2h-4v-3.1c1.63-.33 2.98-1.46 3.61-2.96C19.08 12.63 21 10.55 21 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z'
    );
    const pencilIcon = () => svgIcon(
        'vc-edit-btn__icon',
        'M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z'
    );
    const trashIcon = () => svgIcon(
        'vc-edit-btn__icon',
        'M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z'
    );

    function checkmarkIcon() {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('aria-hidden', 'true');
        CHECK_PATHS.forEach(([fill, d]) => {
            const path = document.createElementNS(SVG_NS, 'path');
            path.setAttribute('fill', fill);
            path.setAttribute('d', d);
            svg.appendChild(path);
        });
        return svg;
    }

    // Opción de motivo con la piel .custom-checkbox (input invisible +
    // .checkbox-visual). Semánticamente sigue siendo un radio: un solo motivo.
    function buildReasonOption(reason) {
        const option = el('label', 'custom-checkbox vc-reasons__option');
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'vc-reason';
        input.value = reason.code;
        input.required = true;
        const visual = el('span', 'checkbox-visual');
        visual.setAttribute('aria-hidden', 'true');
        visual.appendChild(checkmarkIcon());
        option.append(input, visual, el('span', 'vc-reasons__label', reason.label));
        return option;
    }

    // Top de colaboradores: modal propio, abierto desde el botón flotante.
    // Podio (1º-3º) destacado y el resto en lista; se carga al abrir.
    function buildTopList(rows) {
        const list = el('ol', 'vc-top__list');
        list.setAttribute('aria-label', 'Top colaboradores de videos');
        list.replaceChildren(...rows.map((row, index) => {
            const item = el('li', 'vc-top__item');
            if (index < 3) item.classList.add('vc-top__item--podium', `vc-top__item--p${index + 1}`);
            const rank = el('span', 'vc-top__rank', String(index + 1));
            const avatar = document.createElement('img');
            avatar.className = 'vc-top__avatar';
            avatar.alt = '';
            avatar.loading = 'lazy';
            avatar.src = row.avatar || window.__bananoAvatarFallbackSrc || FALLBACK_AVATAR;
            const coins = el('span', 'vc-top__coins', String(row.coins));
            const coinIcon = document.createElement('img');
            coinIcon.className = 'vc-top__coin-icon';
            coinIcon.src = COIN_ICON;
            coinIcon.alt = 'Bananos';
            coinIcon.loading = 'lazy';
            coins.appendChild(coinIcon);
            item.append(rank, avatar, el('span', 'vc-top__name', row.name), coins);
            return item;
        }));
        return list;
    }

    function openTopModal() {
        openModal('Top colaboradores', (modal) => {
            modal.classList.add('vc-modal--top');
            const subtitle = el('p', 'vc-modal__subtitle', 'Quienes más Bananos han ganado aportando videos a las pistas.');
            const box = el('div', 'vc-top');
            box.replaceChildren(el('p', 'vc-top__loading', 'Cargando…'));
            modal.append(subtitle, box);
            api('top10', {}, instance && instance.controller.signal)
                .then((data) => {
                    if (!document.contains(box)) return;
                    if (data.status !== 'success' || !Array.isArray(data.top) || data.top.length === 0) {
                        box.replaceChildren(el('p', 'vc-top__loading', 'Aún no hay colaboradores. ¡Sé el primero!'));
                        return;
                    }
                    box.replaceChildren(buildTopList(data.top));
                })
                .catch(() => {
                    if (document.contains(box)) box.replaceChildren(el('p', 'vc-top__loading', 'No se pudo cargar el ranking.'));
                });
        });
    }

    // Botón flotante (trofeo) en la esquina inferior derecha. Solo existe
    // mientras el panel admin tenga el Top activo (`top_visible`).
    function syncTopFab(visible) {
        let fab = document.getElementById(FAB_ID);
        if (!visible) {
            if (fab) fab.remove();
            if (instance) instance.fab = null;
            return;
        }
        if (fab) return;
        fab = el('button', 'vc-fab');
        fab.id = FAB_ID;
        fab.type = 'button';
        fab.setAttribute('aria-label', 'Ver top colaboradores');
        fab.title = 'Top colaboradores';
        fab.append(trophyIcon(), el('span', 'vc-fab__label', 'Top'));
        fab.addEventListener('click', openTopModal);
        document.body.appendChild(fab);
        if (instance) instance.fab = fab;
    }

    // Modal de envío (nuevo aporte) o de edición del propio aporte
    // (`edit` = { youtubeId, startTime }): mismo formulario y preview.
    function openSubmitModal(ctx, edit) {
        const isEdit = !!edit;
        openModal(isEdit ? 'Edita tu video' : 'Añade el video de esta pista', (modal) => {
            const subtitle = el(
                'p', 'vc-modal__subtitle',
                isEdit
                    ? `Corrige el enlace o el segundo de inicio de tu video para "${ctx.trackName}". Cuando el equipo lo verifique ya no podrás editarlo.`
                    : `Comparte el video oficial de "${ctx.trackName}" en YouTube y gana ${ctx.uploadReward} Bananos.`
            );

            const form = document.createElement('form');
            form.className = 'vc-form';
            form.noValidate = true;

            const urlField = el('div', 'vc-field');
            const urlLabel = el('span', 'vc-field__label');
            const urlLabelText = el('label', null, 'Enlace del video de YouTube');
            urlLabelText.htmlFor = 'vc-url-input';
            urlLabel.append(
                urlLabelText,
                infoTip('vc-url', 'Pega el enlace del video oficial del tema (youtube.com o youtu.be). Debe ser la canción completa y con buen audio: nada de covers, clips ni videos con contenido ajeno.')
            );
            const urlInput = document.createElement('input');
            urlInput.type = 'url';
            urlInput.id = 'vc-url-input';
            urlInput.required = true;
            urlInput.placeholder = 'https://www.youtube.com/watch?v=...';
            urlInput.autocomplete = 'off';
            urlInput.className = 'vc-field__input';
            urlInput.setAttribute('aria-describedby', 'vc-url-tip');
            if (isEdit && edit.youtubeId) urlInput.value = `https://www.youtube.com/watch?v=${edit.youtubeId}`;
            urlField.append(urlLabel, urlInput);

            // Preview reproducible: el player embebido de YouTube. Permite
            // comprobar el video antes de enviarlo y detectar los que tienen
            // la reproducción externa deshabilitada (errores 101/150).
            const preview = el('div', 'vc-preview');
            preview.hidden = true;
            const playerBox = el('div', 'vc-preview__player');
            const previewStatus = el('p', 'vc-preview__status');
            previewStatus.setAttribute('role', 'status');
            previewStatus.hidden = true;
            preview.append(playerBox, previewStatus);

            let previewPlayer = null;
            let previewReady = false;
            let previewId = null;
            let embedBlocked = false;
            let previewDebounce = 0;

            const destroyPreviewPlayer = () => {
                previewReady = false;
                if (previewPlayer) {
                    try { previewPlayer.destroy(); } catch (_) { }
                    previewPlayer = null;
                }
                playerBox.replaceChildren();
            };

            const setPreviewStatus = (message, blocked) => {
                embedBlocked = !!blocked;
                previewStatus.textContent = message || '';
                previewStatus.hidden = !message;
                previewStatus.classList.toggle('vc-preview__status--blocked', !!blocked);
            };

            const mountPreviewPlayer = (youtubeId) => {
                destroyPreviewPlayer();
                previewId = youtubeId;
                setPreviewStatus('', false);
                preview.hidden = false;
                const slot = el('div');
                playerBox.appendChild(slot);
                ensureYouTubeIframeApi().then(() => {
                    if (previewId !== youtubeId || !document.contains(slot)) return;
                    if (!(window.YT && typeof window.YT.Player === 'function')) {
                        setPreviewStatus('No se pudo cargar el reproductor de YouTube.', false);
                        return;
                    }
                    previewPlayer = new window.YT.Player(slot, {
                        videoId: youtubeId,
                        playerVars: {
                            start: Math.max(0, parseInt(startInput.value, 10) || 0),
                            playsinline: 1,
                            rel: 0,
                            modestbranding: 1,
                        },
                        events: {
                            onReady: () => { previewReady = true; },
                            onError: (event) => {
                                const code = event && event.data;
                                if (code === 101 || code === 150) {
                                    setPreviewStatus('Este video no permite reproducirse fuera de YouTube. Elige otro enlace.', true);
                                } else {
                                    setPreviewStatus('El video no está disponible. Revisa el enlace.', true);
                                }
                            },
                        },
                    });
                });
            };

            urlInput.addEventListener('input', () => {
                clearTimeout(previewDebounce);
                previewDebounce = setTimeout(() => {
                    const youtubeId = extractYouTubeId(urlInput.value);
                    if (!youtubeId) {
                        previewId = null;
                        destroyPreviewPlayer();
                        setPreviewStatus('', false);
                        preview.hidden = true;
                        return;
                    }
                    if (youtubeId !== previewId) mountPreviewPlayer(youtubeId);
                }, 350);
            });

            const startField = el('div', 'vc-field');
            const startLabel = el('span', 'vc-field__label');
            const startLabelText = el('label', null, 'Segundo de inicio (opcional)');
            startLabelText.htmlFor = 'vc-start-input';
            startLabel.append(
                startLabelText,
                infoTip('vc-start', 'Indica en qué segundo empieza realmente la canción (ej. 12 si el video tiene intro). Si lo dejas vacío, empezará desde el inicio.')
            );
            const startInput = document.createElement('input');
            startInput.id = 'vc-start-input';
            startInput.type = 'number';
            startInput.min = '0';
            startInput.max = '36000';
            startInput.placeholder = '0';
            startInput.className = 'vc-field__input';
            startInput.setAttribute('aria-describedby', 'vc-start-tip');
            if (isEdit && edit.startTime > 0) startInput.value = String(edit.startTime);

            // Edición del segundo de inicio desde la propia preview: capturar
            // el instante en pausa del player o ver el valor tecleado en él.
            const startRow = el('div', 'vc-field__row');
            const captureBtn = el('button', 'vc-capture', 'USAR SEGUNDO ACTUAL');
            captureBtn.type = 'button';
            captureBtn.title = 'Copia el segundo en el que está pausada la preview';
            captureBtn.addEventListener('click', () => {
                if (!previewPlayer || !previewReady || typeof previewPlayer.getCurrentTime !== 'function') {
                    setPreviewStatus('Reproduce la preview primero para capturar el segundo.', embedBlocked);
                    return;
                }
                startInput.value = String(Math.max(0, Math.floor(previewPlayer.getCurrentTime())));
            });
            startInput.addEventListener('change', () => {
                if (!previewPlayer || !previewReady || typeof previewPlayer.seekTo !== 'function') return;
                try { previewPlayer.seekTo(Math.max(0, parseInt(startInput.value, 10) || 0), true); } catch (_) { }
            });
            startRow.append(startInput, captureBtn);
            startField.append(startLabel, startRow);

            const rewardNote = isEdit
                ? el('p', 'vc-note vc-note--edit', 'Tu recompensa se mantiene. Cada edición queda registrada y el equipo la revisará al verificar la pista.')
                : el('p', 'vc-note', `Recibirás ${ctx.uploadReward} Bananos al instante al enviar tu video. Si el equipo lo rechaza, la recompensa se revertirá y perderás el acceso al sistema de videos.`);
            const warning = el(
                'p', 'vc-warning',
                'Enviar videos falsos, incorrectos o inapropiados anula la recompensa y suspende tu acceso al sistema de videos de forma permanente.'
            );
            const feedback = createFeedback();

            const submitLabel = isEdit ? 'GUARDAR CAMBIOS' : 'ENVIAR VIDEO';
            const actions = el('div', 'vc-modal__actions');
            const submitBtn = el('button', 'vc-modal__primary', submitLabel);
            submitBtn.type = 'submit';
            const cancelBtn = el('button', 'vc-modal__secondary', 'CANCELAR');
            cancelBtn.type = 'button';
            cancelBtn.addEventListener('click', closeModal);
            actions.append(submitBtn, cancelBtn);

            // Eliminar el propio aporte: fuera del <form> para que nunca dispare
            // el submit; abre su propia confirmación (revierte la recompensa).
            let deleteRow = null;
            if (isEdit && edit.canDelete !== false) {
                deleteRow = el('div', 'vc-modal__actions vc-modal__actions--danger');
                const deleteBtn = el('button', 'vc-modal__secondary vc-modal__danger');
                deleteBtn.type = 'button';
                deleteBtn.append(trashIcon(), el('span', null, 'ELIMINAR MI VIDEO'));
                deleteBtn.addEventListener('click', () => openDeleteModal(ctx, edit));
                deleteRow.appendChild(deleteBtn);
            }

            form.append(urlField, preview, startField, rewardNote, warning, feedback, actions);
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const youtubeId = extractYouTubeId(urlInput.value);
                if (!youtubeId) {
                    showFeedback(feedback, 'El enlace no es un video válido de YouTube (youtube.com o youtu.be).', false);
                    urlInput.focus();
                    return;
                }
                if (embedBlocked) {
                    showFeedback(feedback, 'Ese video no se puede reproducir fuera de YouTube. Envía otro enlace.', false);
                    urlInput.focus();
                    return;
                }
                const startSeconds = Math.max(0, parseInt(startInput.value, 10) || 0);
                if (isEdit && youtubeId === edit.youtubeId && startSeconds === edit.startTime) {
                    showFeedback(feedback, 'No has cambiado nada todavía.', false);
                    return;
                }
                submitBtn.disabled = true;
                submitBtn.textContent = isEdit ? 'GUARDANDO…' : 'ENVIANDO…';
                api(isEdit ? 'edit' : 'submit', {
                    track_name: ctx.trackName,
                    cosmetic_id: ctx.cosmeticId || '',
                    youtube_url: urlInput.value.trim(),
                    start_seconds: String(startSeconds),
                }, instance && instance.controller.signal)
                    .then((data) => {
                        if (data.status === 'success') {
                            showFeedback(feedback, data.message || (isEdit ? 'Cambios guardados.' : '¡Gracias! Tu video ya está publicado.'), true);
                            submitBtn.textContent = isEdit ? '¡GUARDADO!' : '¡PUBLICADO!';
                            setTimeout(() => {
                                closeModal();
                                notifyPublished(ctx, data.video);
                                refresh(ctx);
                            }, 1400);
                        } else {
                            handleActionError(feedback, data, isEdit ? 'No se pudo guardar el cambio.' : 'No se pudo enviar el video.');
                            submitBtn.disabled = false;
                            submitBtn.textContent = submitLabel;
                        }
                    })
                    .catch(() => {
                        showFeedback(feedback, 'Error de conexión. Inténtalo de nuevo.', false);
                        submitBtn.disabled = false;
                        submitBtn.textContent = submitLabel;
                    });
            });

            modal.append(subtitle, form);
            if (deleteRow) modal.appendChild(deleteRow);
            if (isEdit && edit.youtubeId) mountPreviewPlayer(edit.youtubeId);
            if (instance && instance.modal) {
                instance.modal.onClose = () => {
                    clearTimeout(previewDebounce);
                    destroyPreviewPlayer();
                };
            }
        });
    }

    // Confirmación de borrado del propio aporte. Avisa de los Bananos que se
    // descontarán del saldo global (`reward_net`, lo que el autor conserva de
    // la recompensa), del saldo resultante, y deja constancia de que el borrado
    // queda registrado para el equipo.
    function openDeleteModal(ctx, edit) {
        const rewardNet = Math.max(0, parseInt(edit && edit.rewardNet, 10) || 0);
        const balance = edit && edit.balance !== null && edit.balance !== undefined
            ? Math.max(0, parseInt(edit.balance, 10) || 0)
            : null;
        const debit = balance === null ? rewardNet : Math.min(rewardNet, balance);
        openModal('Eliminar tu video', (modal) => {
            const subtitle = el(
                'p', 'vc-modal__subtitle',
                `Vas a retirar tu video de "${ctx.trackName}". La pista quedará sin video y cualquier persona podrá volver a aportar uno.`
            );

            const rewardNote = el('p', 'vc-note vc-note--deleted');
            if (rewardNet > 0) {
                rewardNote.append(`Se descontarán ${debit} Bananos de tu saldo global. `);
                if (balance !== null) {
                    const after = Math.max(0, balance - debit);
                    const summary = el('span', 'vc-note__balance');
                    summary.append(
                        'Saldo actual: ',
                        el('b', null, `${balance} Bananos`),
                        ' → pasarás a tener ',
                        el('b', null, `${after} Bananos`),
                        '. '
                    );
                    rewardNote.appendChild(summary);
                }
            } else {
                rewardNote.append('La recompensa de esta pista ya fue revertida, no se te descontará nada más. ');
            }
            rewardNote.append('Esta acción queda registrada en tu historial y el equipo la revisará.');
            const warning = el(
                'p', 'vc-warning',
                'Esta acción no se puede deshacer. Publicar y borrar videos de forma repetida para cobrar recompensas conlleva la suspensión del acceso al sistema de videos.'
            );
            const feedback = createFeedback();

            const actions = el('div', 'vc-modal__actions');
            const confirmLabel = debit > 0 ? `SÍ, ELIMINAR Y DEVOLVER ${debit} BANANOS` : 'SÍ, ELIMINAR MI VIDEO';
            const confirmBtn = el('button', 'vc-modal__primary vc-modal__primary--danger', confirmLabel);
            confirmBtn.type = 'button';
            const cancelBtn = el('button', 'vc-modal__secondary', 'CANCELAR');
            cancelBtn.type = 'button';
            cancelBtn.addEventListener('click', closeModal);
            actions.append(confirmBtn, cancelBtn);

            confirmBtn.addEventListener('click', () => {
                confirmBtn.disabled = true;
                cancelBtn.disabled = true;
                confirmBtn.textContent = 'ELIMINANDO…';
                api('delete', {
                    track_name: ctx.trackName,
                    cosmetic_id: ctx.cosmeticId || '',
                }, instance && instance.controller.signal)
                    .then((data) => {
                        if (data.status === 'success') {
                            showFeedback(feedback, data.message || 'Video eliminado.', true);
                            confirmBtn.textContent = '¡ELIMINADO!';
                            setTimeout(() => {
                                closeModal();
                                notifyDeleted(ctx, data);
                                refresh(ctx);
                            }, 1400);
                        } else {
                            handleActionError(feedback, data, 'No se pudo eliminar el video.');
                            confirmBtn.disabled = false;
                            cancelBtn.disabled = false;
                            confirmBtn.textContent = confirmLabel;
                        }
                    })
                    .catch(() => {
                        showFeedback(feedback, 'Error de conexión. Inténtalo de nuevo.', false);
                        confirmBtn.disabled = false;
                        cancelBtn.disabled = false;
                        confirmBtn.textContent = confirmLabel;
                    });
            });

            modal.append(subtitle, rewardNote, warning, feedback, actions);
        });
    }

    function openReportModal(ctx) {
        openModal('Reportar este video', (modal) => {
            const subtitle = el(
                'p', 'vc-modal__subtitle',
                `Si el video está roto o no corresponde a la pista, repórtalo. Si tu reporte es correcto ganarás ${ctx.reportReward} Bananos.`
            );

            const form = document.createElement('form');
            form.className = 'vc-form';
            form.noValidate = true;

            const reasons = document.createElement('fieldset');
            reasons.className = 'vc-reasons';
            reasons.appendChild(el('legend', 'vc-field__label', 'Motivo del reporte'));
            const hasReasons = ctx.reportReasons.length > 0;
            if (hasReasons) {
                reasons.append(...ctx.reportReasons.map(buildReasonOption));
            } else {
                reasons.appendChild(el('p', 'vc-note', 'Ahora mismo no hay motivos de reporte disponibles. Inténtalo más tarde.'));
            }

            const warning = el(
                'p', 'vc-warning',
                'Los reportes falsos o malintencionados conllevan la suspensión permanente de tu cuenta en el sistema de videos. Solo reporta si estás seguro.'
            );
            const feedback = createFeedback();

            const actions = el('div', 'vc-modal__actions');
            const submitBtn = el('button', 'vc-modal__primary', 'ENVIAR REPORTE');
            submitBtn.type = 'submit';
            submitBtn.disabled = !hasReasons;
            const cancelBtn = el('button', 'vc-modal__secondary', 'CANCELAR');
            cancelBtn.type = 'button';
            cancelBtn.addEventListener('click', closeModal);
            actions.append(submitBtn, cancelBtn);

            form.append(reasons, warning, feedback, actions);
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                if (!hasReasons) return;
                const checked = form.querySelector('input[name="vc-reason"]:checked');
                if (!checked) {
                    showFeedback(feedback, 'Selecciona un motivo para continuar.', false);
                    return;
                }
                submitBtn.disabled = true;
                submitBtn.textContent = 'ENVIANDO…';
                api('report', {
                    track_name: ctx.trackName,
                    reason: checked.value,
                }, instance && instance.controller.signal)
                    .then((data) => {
                        if (data.status === 'success') {
                            showFeedback(feedback, data.message || 'Reporte enviado. El equipo lo revisará.', true);
                            submitBtn.textContent = 'REPORTE ENVIADO';
                            setTimeout(() => {
                                closeModal();
                                refresh(ctx);
                            }, 1600);
                        } else {
                            handleActionError(feedback, data, 'No se pudo enviar el reporte.');
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'ENVIAR REPORTE';
                        }
                    })
                    .catch(() => {
                        showFeedback(feedback, 'Error de conexión. Inténtalo de nuevo.', false);
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'ENVIAR REPORTE';
                    });
            });

            modal.append(subtitle, form);
        });
    }

    // Publicación exitosa: se avisa al host (página de objeto) para que monte
    // el reproductor sin recargar la página.
    function notifyPublished(ctx, video) {
        if (!instance || !instance.root || !video) return;
        instance.root.dispatchEvent(new CustomEvent(PUBLISHED_EVENT, {
            bubbles: true,
            detail: {
                trackName: ctx.trackName,
                youtubeId: String(video.youtube_id || ''),
                startTime: Math.max(0, parseInt(video.start_time, 10) || 0),
                mediaType: String(video.media_type || 'youtube'),
                mediaUrl: video.media_url ? String(video.media_url) : null,
                publishedAt: video.published_at ? String(video.published_at) : null,
            },
        }));
    }

    // Borrado exitoso: el host desmonta el reproductor y vuelve a la carátula.
    function notifyDeleted(ctx, data) {
        if (!instance || !instance.root) return;
        instance.root.dispatchEvent(new CustomEvent(DELETED_EVENT, {
            bubbles: true,
            detail: {
                trackName: ctx.trackName,
                rewardReversed: Math.max(0, parseInt(data && data.reward_reversed, 10) || 0),
            },
        }));
    }

    // CTA de subida: solo se pinta cuando el servidor ya resolvió que este
    // visitante puede subir (`can_upload`, según la política del panel admin).
    // Misma piel que "Comprar ahora" (.cart-cta--primary de cart/cart.css).
    function buildUploadCta(ctx, viewer) {
        const cta = el('button', 'cart-cta cart-cta--primary vc-cta', `SUBE EL VIDEO Y GANA ${ctx.uploadReward} BANANOS`);
        cta.type = 'button';
        cta.dataset.viewer = viewer;
        cta.addEventListener('click', () => openSubmitModal(ctx));
        return cta;
    }

    // Motivos de reporte tal como los envía el servidor (activos y en el
    // orden del panel admin); se ignora cualquier entrada malformada.
    function normalizeReasons(list) {
        if (!Array.isArray(list)) return [];
        return list
            .filter((item) => item && typeof item.code === 'string' && item.code !== '' && typeof item.label === 'string' && item.label !== '')
            .map((item) => ({ code: item.code, label: item.label }));
    }

    // "Video aportado por …": avatar del autor (URL original del catálogo, así
    // los GIF conservan su animación) + nombre público. Los videos del sistema
    // se acreditan a Banano sin avatar.
    // Debajo del nombre va, discreta, la fecha y hora exacta de publicación.
    function buildCredit(data, source) {
        const contributor = source === 'system' ? SYSTEM_CONTRIBUTOR : data.contributor;
        if (!contributor) return null;
        const credit = el('div', 'vc-credit');
        credit.dataset.source = source;
        if (source === 'community') {
            const avatar = document.createElement('img');
            avatar.className = 'vc-credit__avatar';
            avatar.alt = '';
            avatar.width = AVATAR_SIZE;
            avatar.height = AVATAR_SIZE;
            avatar.decoding = 'async';
            avatar.loading = 'lazy';
            avatar.src = data.contributor_avatar || window.__bananoAvatarFallbackSrc || FALLBACK_AVATAR;
            credit.appendChild(avatar);
        }
        const body = el('div', 'vc-credit__body');
        const kind = MEDIA_LABEL[String(data.media_type || 'youtube')] || 'Video';
        const text = el('p', 'vc-credit__text', `${kind} aportado por ${contributor}`);
        body.appendChild(text);
        const when = formatPublishedAt(data.published_at);
        if (when) {
            const time = document.createElement('time');
            time.className = 'vc-credit__date';
            time.dateTime = when.iso;
            time.textContent = `Publicado el ${when.text}`;
            body.appendChild(time);
        }
        credit.appendChild(body);
        return credit;
    }

    // Botón de edición del autor: solo mientras el servidor lo permita
    // (`can_edit`: pista sin verificar y, si está reportada, con la opción
    // habilitada en el panel admin).
    function buildEditButton(ctx, data) {
        const btn = el('button', 'vc-report-btn vc-edit-btn');
        btn.type = 'button';
        btn.append(pencilIcon(), el('span', null, 'Editar mi video'));
        btn.addEventListener('click', () => openSubmitModal(ctx, {
            youtubeId: String(data.youtube_id || ''),
            startTime: Math.max(0, parseInt(data.start_time, 10) || 0),
            canDelete: data.can_delete !== false,
            rewardNet: Math.max(0, parseInt(data.reward_net, 10) || 0),
            balance: data.balance === null || data.balance === undefined ? null : Math.max(0, parseInt(data.balance, 10) || 0),
        }));
        return btn;
    }

    // Botón de reporte del estado `published`. El autor (`is_owner`) lo ve
    // pero inerte: sin handler, aria-disabled y cursor no permitido.
    function buildReportButton(ctx, viewer, isOwner) {
        const reportBtn = el('button', 'vc-report-btn', 'Reportar este video');
        reportBtn.type = 'button';
        if (isOwner) {
            reportBtn.classList.add('vc-report-btn--own');
            reportBtn.setAttribute('aria-disabled', 'true');
            reportBtn.title = 'No puedes reportar tu propio video.';
            return reportBtn;
        }
        reportBtn.addEventListener('click', () => {
            if (viewer === 'guest') goToLogin();
            else openReportModal(ctx);
        });
        return reportBtn;
    }

    function buildReportedBadge() {
        const reported = el('button', 'vc-report-btn', 'REPORTADO (EN REVISIÓN)');
        reported.type = 'button';
        reported.disabled = true;
        return reported;
    }

    // Renderiza el panel según el estado derivado en servidor
    // (missing | published | reported | locked), el origen del video
    // (community | system → autor "Banano", sin botón de reporte), el
    // visitante (guest | unverified | verified | banned) y los permisos que el
    // servidor ya resolvió para él (`can_upload`, `can_report`).
    // Regla exclusiva: sin video → solo el CTA de subida (si puede subir);
    // con video → nunca CTA, y "Reportar" solo en videos de la comunidad.
    // Un video verificado (`locked`) solo muestra el crédito: sin botón.
    // Idempotente: si nada cambió respecto al último render, no toca el DOM.
    function render(ctx, data) {
        if (!instance || !instance.root) return;
        const root = instance.root;
        const viewer = data.viewer || 'guest';
        const hasVideo = data.state !== 'missing';
        const source = data.source === 'system' ? 'system' : 'community';
        const canUpload = !hasVideo && data.can_upload === true;
        const canReport = hasVideo && data.can_report === true && source === 'community';
        const isOwner = data.is_owner === true;
        const canEdit = hasVideo && isOwner && data.can_edit === true && source === 'community';
        ctx.uploadReward = data.upload_reward;
        ctx.reportReward = data.report_reward;
        ctx.topVisible = data.top_visible !== false;
        ctx.reportReasons = normalizeReasons(data.report_reasons);
        syncTopFab(ctx.topVisible && viewer !== 'banned');

        const signature = [
            data.state, viewer, source, canUpload, canReport, isOwner, canEdit,
            data.contributor || '', data.contributor_avatar || '',
            data.upload_reward, data.report_reward,
            data.media_type || '', data.published_at || '', data.is_locked ? 1 : 0,
            canEdit ? `${data.youtube_id || ''}@${data.start_time || 0}@${data.reward_net || 0}@${data.balance == null ? '' : data.balance}` : '',
        ].join('|');
        if (root.dataset.signature === signature) return;
        root.dataset.signature = signature;

        if (viewer === 'banned') {
            root.replaceChildren();
            return;
        }

        if (!hasVideo) {
            if (canUpload) root.replaceChildren(buildUploadCta(ctx, viewer));
            else root.replaceChildren();
            return;
        }

        const nodes = [];
        const credit = buildCredit(data, source);
        if (credit) nodes.push(credit);

        const actions = el('div', 'vc-actions');
        if (data.state === 'reported') {
            actions.appendChild(buildReportedBadge());
        } else if (data.state === 'published' && canReport) {
            actions.appendChild(buildReportButton(ctx, viewer, isOwner));
        }
        if (canEdit) actions.appendChild(buildEditButton(ctx, data));
        if (actions.childElementCount > 0) nodes.push(actions);

        root.replaceChildren(...nodes);
    }

    function refresh(ctx) {
        if (!instance) return;
        api('state', {
            track_name: ctx.trackName,
            cosmetic_id: ctx.cosmeticId || '',
        }, instance.controller.signal)
            .then((data) => {
                if (data.status === 'success') render(ctx, data);
            })
            .catch(() => { });
    }

    function unmount() {
        if (!instance) return;
        closeModal();
        try { instance.controller.abort(); } catch (_) { }
        if (instance.root) instance.root.remove();
        const fab = document.getElementById(FAB_ID);
        if (fab) fab.remove();
        instance = null;
    }

    function mount(host, options) {
        unmount();
        const trackName = String((options && options.trackName) || '').trim();
        if (!host || !trackName) return;
        instance = {
            controller: new AbortController(),
            root: el('div', 'vc-panel'),
            modal: null,
            fab: null,
        };
        host.appendChild(instance.root);
        refresh({
            trackName,
            cosmeticId: String(options.cosmeticId || '').toLowerCase(),
            uploadReward: 0,
            reportReward: 0,
            topVisible: true,
            reportReasons: [],
        });
    }

    window.BananoVideoCommunity = { mount, unmount };
})();

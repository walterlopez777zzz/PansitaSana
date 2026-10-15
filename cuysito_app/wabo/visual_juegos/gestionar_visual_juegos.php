<?php
// Archivo: wabo/visual_juegos/gestionar_visual_juegos.php (v3.0 - media de tarjetas: imagen / SVG / ambos)
//
// Edita el `.gh-card__media` de cada tarjeta de /juegos (imagen de fondo,
// icono SVG y su color) y el título. Todo lo demás de la tarjeta (badge,
// descripción, meta, CTA) lo pinta la vista según el estado del usuario y no
// es configurable aquí. Persistencia en ./api_visual_juegos.php.
require_once __DIR__ . '/../core/admin_header.php';

$vj_cards = [
    'daily' => ['label' => 'Giro Diario', 'icon' => 'fa-dice', 'color' => '#10b981'],
    'flash' => ['label' => 'Sorteo Flash', 'icon' => 'fa-bolt', 'color' => '#f59e0b'],
];
?>

<style>
    .vj-card { border-radius: 0.75rem; padding: 1.5rem; border: 1px solid var(--border-color); background-color: var(--secondary); }
    .vj-card h2 { font-size: 1.25rem; font-weight: 700; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
    .vj-card[data-dirty="true"] h2::after { content: '• sin guardar'; font-size: 0.7rem; font-weight: 600; color: var(--accent); margin-left: auto; }

    .vj-field { margin-bottom: 1rem; }
    .vj-field > label, .vj-field legend { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.375rem; }
    .vj-input, .vj-textarea {
        width: 100%; padding: 0.625rem 0.75rem; border-radius: 0.5rem; box-sizing: border-box;
        border: 1px solid var(--border-color); background-color: var(--primary);
        color: var(--text-primary); font-size: 0.85rem;
    }
    .vj-input:focus, .vj-textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.3); }
    .vj-textarea { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; line-height: 1.4; min-height: 7.5rem; resize: vertical; white-space: pre; }
    .vj-textarea[aria-invalid="true"] { border-color: #ef4444; }
    .vj-hint { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.3rem; }
    .vj-error { font-size: 0.75rem; color: #ef4444; margin-top: 0.3rem; min-height: 1em; }

    /* Selector de modo */
    .vj-mode { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.25rem; padding: 0.25rem; border-radius: 0.625rem; background: var(--primary); border: 1px solid var(--border-color); }
    .vj-mode label { position: relative; }
    .vj-mode input { position: absolute; opacity: 0; inset: 0; margin: 0; cursor: pointer; }
    .vj-mode span {
        display: flex; align-items: center; justify-content: center; gap: 0.4rem;
        padding: 0.55rem 0.5rem; border-radius: 0.45rem; font-size: 0.8rem; font-weight: 600;
        color: var(--text-secondary); transition: background 0.15s, color 0.15s;
    }
    .vj-mode input:checked + span { background: var(--accent); color: #fff; }
    .vj-mode input:focus-visible + span { outline: 2px solid var(--accent); outline-offset: 2px; }

    /* Bloques por capa */
    .vj-layer { border: 1px solid var(--border-color); border-radius: 0.625rem; padding: 1rem; margin-bottom: 1rem; background: var(--primary); transition: opacity 0.15s; }
    .vj-layer[disabled] { opacity: 0.45; }
    .vj-layer legend { padding: 0 0.4rem; margin-bottom: 0; color: var(--text-primary); font-size: 0.85rem; }
    .vj-layer .vj-field:last-child { margin-bottom: 0; }

    .vj-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
    .vj-row .vj-input { flex: 1; min-width: 8rem; }
    .vj-range { flex: 1; accent-color: var(--accent); cursor: pointer; height: 6px; min-width: 8rem; }
    .vj-range-val { min-width: 3.25rem; text-align: center; font-weight: 700; font-size: 0.85rem; padding: 0.25rem 0.5rem; border-radius: 0.375rem; background-color: var(--secondary); border: 1px solid var(--border-color); }
    .vj-color { width: 2.25rem; height: 2.25rem; padding: 0; border: 1px solid var(--border-color); border-radius: 0.4rem; background: none; cursor: pointer; }
    .vj-color-hex { width: 6.5rem !important; flex: none !important; font-family: ui-monospace, monospace; text-align: center; }
    .vj-check { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; color: var(--text-primary); cursor: pointer; }
    .vj-check input { accent-color: var(--accent); width: 1rem; height: 1rem; }

    .vj-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.1rem; border-radius: 0.5rem; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: filter 0.2s; }
    .vj-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .vj-btn-primary { background-color: var(--accent); color: #fff; }
    .vj-btn-primary:hover { filter: brightness(1.1); }
    .vj-btn-ghost { background: transparent; color: var(--text-secondary); border: 1px solid var(--border-color); }
    .vj-btn-ghost:hover { color: var(--text-primary); border-color: var(--accent); }
    .vj-btn-danger { background-color: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
    .vj-btn-danger:hover { background-color: rgba(239,68,68,0.25); }
    .vj-btn-sm { padding: 0.4rem 0.75rem; font-size: 0.75rem; }
    .vj-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-top: 0.5rem; }

    /* Vista previa: réplica del .gh-card__media (16:9, imagen debajo, SVG encima) */
    .vj-preview-wrap { position: sticky; top: 1rem; }
    .vj-preview {
        position: relative; display: grid; place-items: center; aspect-ratio: 16 / 9;
        border-radius: 0.5rem; overflow: clip; background-color: #27272a; color: #fff;
        width: 100%; max-width: 420px;
    }
    .vj-preview > * { grid-area: 1 / 1; }
    .vj-preview__img { display: block; width: 100%; height: 100%; object-fit: cover; }
    .vj-preview__img[hidden], .vj-preview__svg[hidden] { display: none; }
    .vj-preview__svg { display: contents; }
    .vj-preview__svg > svg { display: block; grid-area: 1 / 1; width: var(--gh-icon-size, 34%); height: auto; max-height: 84%; color: var(--gh-icon-color, #fff); filter: var(--gh-art-fx, none); }
    .vj-preview__title { margin-top: 0.75rem; font-weight: 700; font-size: 1.05rem; }
    .vj-preview__caption { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.25rem; }

    /* Galería */
    .vj-gallery { border-radius: 0.75rem; padding: 1.5rem; border: 1px solid var(--border-color); background-color: var(--secondary); }
    .vj-gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 0.75rem; }
    .vj-thumb { aspect-ratio: 1; border-radius: 0.5rem; overflow: hidden; cursor: pointer; border: 2px solid transparent; transition: border-color 0.2s, transform 0.2s; position: relative; background: #111; padding: 0; }
    .vj-thumb:hover, .vj-thumb:focus-visible { border-color: var(--accent); transform: scale(1.04); outline: none; }
    .vj-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .vj-thumb span { position: absolute; bottom: 0; left: 0; right: 0; padding: 2px 4px; background: rgba(0,0,0,0.7); color: #fff; font-size: 0.6rem; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .vj-gallery-empty { color: var(--text-secondary); font-size: 0.85rem; padding: 2rem; text-align: center; }
    .vj-target { display: flex; gap: 0.5rem; margin-bottom: 1rem; align-items: center; font-size: 0.8rem; color: var(--text-secondary); }
    .vj-target button { padding: 0.375rem 1rem; border-radius: 0.375rem; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1px solid var(--border-color); background: var(--primary); color: var(--text-secondary); }
    .vj-target button[aria-pressed="true"] { background: var(--accent); color: #fff; border-color: var(--accent); }
</style>

<div class="space-y-8">
    <header>
        <h1 class="text-3xl font-bold">Visual de Juegos</h1>
        <p style="color: var(--text-secondary);">Media de cada tarjeta de /juegos: imagen, icono SVG (color y tamaño) o ambos, más el título.</p>
    </header>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <?php foreach ($vj_cards as $key => $card): ?>
        <section class="vj-card" data-card="<?= $key ?>" data-dirty="false">
            <h2><i class="fas <?= $card['icon'] ?>" style="color: <?= $card['color'] ?>;"></i> <?= $card['label'] ?></h2>

            <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_minmax(0,1.25fr)] gap-6">
                <div class="vj-preview-wrap">
                    <div class="vj-preview" data-ref="preview" aria-hidden="true">
                        <img class="vj-preview__img" data-ref="previewImg" alt="" hidden>
                        <div class="vj-preview__svg" data-ref="previewSvg"></div>
                    </div>
                    <div class="vj-preview__title" data-ref="previewTitle"><?= $card['label'] ?></div>
                    <p class="vj-preview__caption">Vista previa del media (16:9) tal como se apila en la tarjeta.</p>
                </div>

                <div>
                    <div class="vj-field">
                        <label>Modo del media</label>
                        <div class="vj-mode" role="radiogroup" aria-label="Modo del media">
                            <label><input type="radio" name="mode-<?= $key ?>" value="svg" data-field="mode"><span><i class="fas fa-shapes"></i> SVG</span></label>
                            <label><input type="radio" name="mode-<?= $key ?>" value="image" data-field="mode"><span><i class="fas fa-image"></i> Imagen</span></label>
                            <label><input type="radio" name="mode-<?= $key ?>" value="both" data-field="mode"><span><i class="fas fa-layer-group"></i> Ambos</span></label>
                        </div>
                        <p class="vj-hint">«Ambos» = SVG sobre la imagen. Sin imagen válida la tarjeta muestra sólo el SVG.</p>
                    </div>

                    <fieldset class="vj-layer" data-ref="svgLayer">
                        <legend><i class="fas fa-shapes"></i> Icono SVG</legend>
                        <div class="vj-field">
                            <label for="svg-<?= $key ?>">Código SVG</label>
                            <textarea id="svg-<?= $key ?>" class="vj-textarea" data-field="markup" spellcheck="false" placeholder="Pega aquí un <svg viewBox=&quot;0 0 24 24&quot;>…</svg>. Vacío = icono por defecto."></textarea>
                            <p class="vj-error" data-ref="svgError"></p>
                            <p class="vj-hint">Se limpia en el servidor: sin scripts, eventos ni enlaces externos. Usa <code>currentColor</code> o activa «Forzar color».</p>
                        </div>
                        <div class="vj-field">
                            <label>Color</label>
                            <div class="vj-row">
                                <input type="color" class="vj-color" data-field="colorPicker" value="#ffffff" aria-label="Color del icono">
                                <input type="text" class="vj-input vj-color-hex" data-field="color" maxlength="7" placeholder="heredar">
                                <button type="button" class="vj-btn vj-btn-ghost vj-btn-sm" data-action="clearColor">Heredar del texto</button>
                                <label class="vj-check"><input type="checkbox" data-field="forceColor"> Forzar color</label>
                            </div>
                        </div>
                        <div class="vj-field">
                            <label>Tamaño</label>
                            <div class="vj-row">
                                <input type="range" class="vj-range" data-field="size" min="10" max="100" step="1" aria-label="Tamaño del icono">
                                <span class="vj-range-val" data-ref="sizeVal">34%</span>
                                <label class="vj-check"><input type="checkbox" data-field="shadow"> Sombra</label>
                            </div>
                        </div>
                        <div class="vj-actions">
                            <button type="button" class="vj-btn vj-btn-ghost vj-btn-sm" data-action="editDefaultSvg"><i class="fas fa-code"></i> Editar código del icono por defecto</button>
                            <button type="button" class="vj-btn vj-btn-ghost vj-btn-sm" data-action="defaultSvg"><i class="fas fa-undo"></i> Usar icono por defecto</button>
                        </div>
                    </fieldset>

                    <fieldset class="vj-layer" data-ref="imageLayer">
                        <legend><i class="fas fa-image"></i> Imagen</legend>
                        <div class="vj-field">
                            <label for="url-<?= $key ?>">URL de la imagen</label>
                            <div class="vj-row">
                                <input type="url" id="url-<?= $key ?>" class="vj-input" data-field="url" placeholder="/assets/game_cards/… o https://…">
                                <button type="button" class="vj-btn vj-btn-ghost vj-btn-sm" data-action="pickGallery"><i class="fas fa-images"></i> Galería</button>
                            </div>
                        </div>
                        <div class="vj-field">
                            <label>Encuadre</label>
                            <div class="vj-row">
                                <span class="vj-hint" style="margin:0;">X</span>
                                <input type="range" class="vj-range" data-field="posX" min="0" max="100" aria-label="Posición horizontal">
                                <span class="vj-range-val" data-ref="posXVal">50%</span>
                            </div>
                            <div class="vj-row" style="margin-top:0.5rem;">
                                <span class="vj-hint" style="margin:0;">Y</span>
                                <input type="range" class="vj-range" data-field="posY" min="0" max="100" aria-label="Posición vertical">
                                <span class="vj-range-val" data-ref="posYVal">50%</span>
                            </div>
                        </div>
                    </fieldset>

                    <div class="vj-field">
                        <label for="title-<?= $key ?>">Título de la tarjeta</label>
                        <input type="text" id="title-<?= $key ?>" class="vj-input" data-field="title" maxlength="200" placeholder="<?= $card['label'] ?>">
                    </div>

                    <div class="vj-actions">
                        <button type="button" class="vj-btn vj-btn-primary" data-action="save"><i class="fas fa-save"></i> Guardar</button>
                        <button type="button" class="vj-btn vj-btn-danger" data-action="reset"><i class="fas fa-undo"></i> Restaurar por defecto</button>
                    </div>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
    </div>

    <section class="vj-gallery" id="gallerySection">
        <h2 style="font-size:1.25rem; font-weight:700; margin-bottom:0.5rem; display:flex; align-items:center; gap:0.5rem;">
            <i class="fas fa-images" style="color: var(--accent);"></i> Galería de imágenes locales
        </h2>
        <p style="color:var(--text-secondary); font-size:0.8rem; margin-bottom:1rem;">
            Coloca archivos en <code>/assets/game_cards/</code>. Haz clic en uno para asignarlo a la tarjeta seleccionada.
        </p>
        <div class="vj-target" role="group" aria-label="Asignar imagen a">
            <span>Asignar a:</span>
            <?php foreach ($vj_cards as $key => $card): ?>
                <button type="button" data-gallery-target="<?= $key ?>" aria-pressed="<?= $key === 'daily' ? 'true' : 'false' ?>"><i class="fas <?= $card['icon'] ?>"></i> <?= $card['label'] ?></button>
            <?php endforeach; ?>
        </div>
        <div class="vj-gallery-grid" id="galleryGrid">
            <div class="vj-gallery-empty" id="galleryEmpty"><i class="fas fa-folder-open" style="font-size:2rem; display:block; margin-bottom:0.5rem; opacity:0.4;"></i>Cargando galería...</div>
        </div>
    </section>
</div>

<div id="vjToast" class="fixed bottom-4 right-4 z-50 hidden max-w-xs card rounded-lg shadow-lg p-4 flex items-center gap-3" role="status">
    <i id="vjToastIcon" class="text-xl"></i>
    <p id="vjToastMessage" class="text-sm font-medium"></p>
</div>

<script>
(() => {
    'use strict';

    const API = 'visual_juegos/api_visual_juegos.php';
    const HEX = /^#[0-9a-fA-F]{6}$/;
    const SVG_NS = 'http://www.w3.org/2000/svg';

    /* Estado por tarjeta: espejo exacto del payload de `save`. */
    const state = {};
    // `defaults` se sobreescribe con lo que devuelve get_all (fuente de verdad: gh_media.php).
    const defaults = { media: { mode: 'svg', svg: { markup: null, color: null, size: 34, forceColor: true, shadow: false } }, svg: {} };
    const sections = new Map();

    const toast = (() => {
        const el = document.getElementById('vjToast');
        const icon = document.getElementById('vjToastIcon');
        const msg = document.getElementById('vjToastMessage');
        let timer = null;
        return (message, type = 'success') => {
            msg.textContent = message;
            icon.className = type === 'success' ? 'fas fa-check-circle text-green-500 text-xl' : 'fas fa-times-circle text-red-500 text-xl';
            el.classList.remove('hidden');
            clearTimeout(timer);
            timer = setTimeout(() => el.classList.add('hidden'), 4000);
        };
    })();

    const api = async (payload) => {
        const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.message || `HTTP ${res.status}`);
        return json;
    };

    /* ── SVG: vista previa segura (el servidor sanea de verdad al guardar) ── */
    const previewSvg = (markup) => {
        if (typeof markup !== 'string' || !markup.trim()) return null;
        // Sin xmlns el parser XML no crea elementos SVG (sin .style); el servidor también lo añade.
        const src = /<svg\b[^>]*\sxmlns=/.test(markup) ? markup : markup.replace(/<svg\b/, `<svg xmlns="${SVG_NS}"`);
        const doc = new DOMParser().parseFromString(src, 'image/svg+xml');
        const root = doc.documentElement;
        if (!root || root.localName !== 'svg' || root.namespaceURI !== SVG_NS || doc.querySelector('parsererror')) return null;
        root.querySelectorAll('script, foreignObject, image, a, iframe, object, embed, style').forEach((n) => n.remove());
        doc.querySelectorAll('*').forEach((n) => {
            for (const a of Array.from(n.attributes)) {
                const name = a.name.toLowerCase();
                const val = a.value.trim();
                if (name.startsWith('on') || ((name === 'href' || name === 'xlink:href') && !val.startsWith('#')) || (name === 'style' && /url\(/i.test(val))) {
                    n.removeAttribute(a.name);
                }
            }
        });
        if (!root.hasAttribute('viewBox')) {
            const w = parseFloat(root.getAttribute('width')), h = parseFloat(root.getAttribute('height'));
            if (!(w > 0 && h > 0)) return null;
            root.setAttribute('viewBox', `0 0 ${w} ${h}`);
        }
        ['width', 'height', 'id', 'class', 'style', 'x', 'y'].forEach((a) => root.removeAttribute(a));
        return document.importNode(root, true);
    };

    /* Misma regla que gh_svg_force_current_color(): fill/stroke con color explícito → currentColor. */
    const forceCurrentColor = (svg) => {
        const keep = (v) => v === 'none' || v === 'currentColor' || /^url\(/i.test(v);
        svg.querySelectorAll('*').forEach((n) => {
            ['fill', 'stroke'].forEach((p) => {
                const v = n.getAttribute(p);
                if (v !== null && !keep(v.trim())) n.setAttribute(p, 'currentColor');
                if (n.style && n.style[p] && !keep(n.style[p].trim())) n.style[p] = 'currentColor';
            });
        });
        ['fill', 'stroke'].forEach((p) => {
            const v = svg.getAttribute(p);
            if (v !== null && !keep(v.trim())) svg.setAttribute(p, 'currentColor');
        });
        return svg;
    };

    /* ── Render de una tarjeta desde su estado ── */
    const render = (key) => {
        const s = state[key];
        const ui = sections.get(key);
        const { refs, fields } = ui;

        fields.mode.forEach((r) => { r.checked = r.value === s.mode; });
        refs.svgLayer.disabled = s.mode === 'image';
        refs.imageLayer.disabled = s.mode === 'svg';

        if (document.activeElement !== fields.markup) fields.markup.value = s.svg.markup || '';
        fields.color.value = s.svg.color || '';
        fields.colorPicker.value = s.svg.color || '#ffffff';
        fields.forceColor.checked = s.svg.forceColor;
        fields.shadow.checked = s.svg.shadow;
        fields.size.value = s.svg.size;
        refs.sizeVal.textContent = `${s.svg.size}%`;

        if (document.activeElement !== fields.url) fields.url.value = s.url;
        fields.posX.value = s.posX;
        fields.posY.value = s.posY;
        refs.posXVal.textContent = `${s.posX}%`;
        refs.posYVal.textContent = `${s.posY}%`;
        if (document.activeElement !== fields.title) fields.title.value = s.title;

        // Vista previa
        const hasImage = s.url.trim() !== '';
        const showImage = s.mode !== 'svg' && hasImage;
        const showSvg = s.mode !== 'image' || !hasImage;
        refs.previewImg.hidden = !showImage;
        if (showImage) {
            refs.previewImg.style.objectPosition = `${s.posX}% ${s.posY}%`;
            // La URL se teclea: la imagen se pide al pausar, no por cada carácter.
            clearTimeout(ui.imgTimer);
            ui.imgTimer = setTimeout(() => {
                if (refs.previewImg.getAttribute('src') !== s.url) refs.previewImg.src = s.url;
            }, refs.previewImg.getAttribute('src') ? 300 : 0);
        }

        refs.previewSvg.replaceChildren();
        refs.svgError.textContent = '';
        fields.markup.removeAttribute('aria-invalid');
        if (showSvg) {
            let svg = null;
            if (s.svg.markup && s.svg.markup.trim()) {
                svg = previewSvg(s.svg.markup);
                if (!svg) {
                    refs.svgError.textContent = 'SVG no válido: debe ser XML bien formado con <svg> raíz y viewBox (o width/height).';
                    fields.markup.setAttribute('aria-invalid', 'true');
                }
            }
            if (!svg) svg = previewSvg(defaults.svg[key]);
            if (svg) {
                if (s.svg.forceColor) forceCurrentColor(svg);
                svg.setAttribute('aria-hidden', 'true');
                svg.style.setProperty('--gh-icon-size', `${s.svg.size}%`);
                if (s.svg.color) svg.style.setProperty('--gh-icon-color', s.svg.color);
                if (s.svg.shadow) svg.style.setProperty('--gh-art-fx', 'drop-shadow(0 2px 6px rgb(0 0 0 / 0.55))');
                refs.previewSvg.appendChild(svg);
            }
        }
        refs.previewTitle.textContent = s.title.trim() || ui.label;
        ui.section.dataset.dirty = String(ui.saved !== JSON.stringify(s));
    };

    const setState = (key, patch) => {
        const s = state[key];
        Object.assign(s, patch.svg ? { svg: { ...s.svg, ...patch.svg } } : {}, Object.fromEntries(Object.entries(patch).filter(([k]) => k !== 'svg')));
        render(key);
    };

    const fromCard = (key, card) => {
        const m = card && card.media_config ? card.media_config : defaults.media;
        return {
            mode: m.mode,
            svg: { markup: m.svg.markup || '', color: m.svg.color || '', size: m.svg.size, forceColor: !!m.svg.forceColor, shadow: !!m.svg.shadow },
            url: (card && card.background_image_url) || '',
            posX: card ? card.bg_position_x : 50,
            posY: card ? card.bg_position_y : 50,
            title: (card && card.title) || '',
        };
    };

    const payload = (key) => {
        const s = state[key];
        return {
            action: 'save',
            card_key: key,
            background_image_url: s.url.trim(),
            bg_position_x: s.posX,
            bg_position_y: s.posY,
            title: s.title.trim(),
            media_config: {
                mode: s.mode,
                svg: { markup: s.svg.markup.trim() || null, color: HEX.test(s.svg.color) ? s.svg.color : null, size: s.svg.size, forceColor: s.svg.forceColor, shadow: s.svg.shadow },
            },
        };
    };

    /* ── Acciones ── */
    const busy = async (btn, label, fn) => {
        const html = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${label}`;
        try { await fn(); } finally { btn.disabled = false; btn.innerHTML = html; }
    };

    const actions = {
        clearColor: (key) => setState(key, { svg: { color: '' } }),
        defaultSvg: (key) => setState(key, { svg: { markup: '' } }),
        editDefaultSvg: (key) => {
            setState(key, { svg: { markup: defaults.svg[key] || '' } });
            sections.get(key).fields.markup.focus();
        },
        pickGallery: (key) => {
            setGalleryTarget(key);
            document.getElementById('gallerySection').scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        save: (key, btn) => busy(btn, 'Guardando…', async () => {
            const s = state[key];
            if (s.svg.markup.trim() && !previewSvg(s.svg.markup)) throw new Error('Corrige el SVG antes de guardar.');
            const body = payload(key);
            const json = await api(body);
            // El servidor devuelve el SVG ya saneado: se refleja para que el admin vea lo persistido.
            Object.assign(s, fromCard(key, {
                background_image_url: body.background_image_url,
                bg_position_x: body.bg_position_x,
                bg_position_y: body.bg_position_y,
                title: body.title,
                media_config: json.media_config || body.media_config,
            }));
            sections.get(key).saved = JSON.stringify(s);
            render(key);
            toast(json.message || 'Guardado.');
        }).catch((err) => toast(err.message || 'Error al guardar', 'error')),
        reset: (key, btn) => {
            if (!confirm(`¿Restaurar «${sections.get(key).label}» a los valores por defecto? Se guardará automáticamente.`)) return;
            return busy(btn, 'Restaurando…', async () => {
                const json = await api({ action: 'reset', card_key: key });
                Object.assign(state[key], fromCard(key, null));
                sections.get(key).saved = JSON.stringify(state[key]);
                render(key);
                toast(json.message || 'Restaurado.');
            }).catch((err) => toast(err.message || 'Error al restaurar', 'error'));
        },
    };

    /* ── Wiring por sección (delegación: un listener por tipo) ── */
    document.querySelectorAll('.vj-card[data-card]').forEach((section) => {
        const key = section.dataset.card;
        const refs = {};
        section.querySelectorAll('[data-ref]').forEach((el) => { refs[el.dataset.ref] = el; });
        const fields = {};
        section.querySelectorAll('[data-field]').forEach((el) => {
            const f = el.dataset.field;
            if (f === 'mode') (fields.mode ||= []).push(el); else fields[f] = el;
        });
        sections.set(key, { section, refs, fields, label: section.querySelector('h2').textContent.trim(), saved: '', imgTimer: 0 });
        state[key] = fromCard(key, null);
        sections.get(key).saved = JSON.stringify(state[key]);
        render(key);

        section.addEventListener('input', (e) => {
            const f = e.target.dataset.field;
            if (!f) return;
            const v = e.target.value;
            switch (f) {
                case 'mode': setState(key, { mode: v }); break;
                case 'markup': setState(key, { svg: { markup: v } }); break;
                case 'colorPicker': setState(key, { svg: { color: v.toUpperCase() } }); break;
                case 'color': if (v === '' || HEX.test(v)) setState(key, { svg: { color: v.toUpperCase() } }); break;
                case 'forceColor': setState(key, { svg: { forceColor: e.target.checked } }); break;
                case 'shadow': setState(key, { svg: { shadow: e.target.checked } }); break;
                case 'size': setState(key, { svg: { size: parseInt(v, 10) } }); break;
                case 'url': setState(key, { url: v }); break;
                case 'posX': setState(key, { posX: parseInt(v, 10) }); break;
                case 'posY': setState(key, { posY: parseInt(v, 10) }); break;
                case 'title': setState(key, { title: v }); break;
            }
        });
        section.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action]');
            if (btn && actions[btn.dataset.action]) actions[btn.dataset.action](key, btn);
        });
        refs.previewImg.addEventListener('error', () => { refs.previewImg.hidden = true; toast('No se pudo cargar la imagen de la vista previa.', 'error'); });
    });

    /* ── Galería ── */
    let galleryTarget = 'daily';
    const setGalleryTarget = (key) => {
        galleryTarget = key;
        document.querySelectorAll('[data-gallery-target]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.galleryTarget === key)));
    };
    document.querySelectorAll('[data-gallery-target]').forEach((b) => b.addEventListener('click', () => setGalleryTarget(b.dataset.galleryTarget)));

    const loadGallery = async () => {
        const grid = document.getElementById('galleryGrid');
        const empty = document.getElementById('galleryEmpty');
        try {
            const res = await fetch(`${API}?action=get_gallery`);
            const json = await res.json();
            if (!res.ok) throw new Error(json.message);
            if (!json.images || json.images.length === 0) {
                empty.innerHTML = '<i class="fas fa-folder-open" style="font-size:2rem; display:block; margin-bottom:0.5rem; opacity:0.4;"></i>La galería está vacía. Coloca imágenes en <code>/assets/game_cards/</code>.';
                return;
            }
            grid.replaceChildren(...json.images.map((img) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'vj-thumb';
                b.title = img.filename;
                const pic = document.createElement('img');
                pic.src = img.url; pic.alt = ''; pic.loading = 'lazy';
                const name = document.createElement('span');
                name.textContent = img.filename;
                b.append(pic, name);
                b.addEventListener('click', () => {
                    const patch = { url: img.url };
                    if (state[galleryTarget].mode === 'svg') patch.mode = 'both';
                    setState(galleryTarget, patch);
                    toast(`Imagen asignada a ${sections.get(galleryTarget).label}. Recuerda guardar.`);
                });
                return b;
            }));
        } catch (err) {
            empty.innerHTML = `<i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Error al cargar galería: ${err.message || 'Desconocido'}`;
        }
    };

    /* ── Carga inicial ── */
    const load = async () => {
        try {
            const res = await fetch(`${API}?action=get_all`);
            const json = await res.json();
            if (!res.ok) throw new Error(json.message);
            defaults.media = json.defaults.media_config;
            defaults.svg = json.defaults.svg;
            sections.forEach((ui, key) => {
                state[key] = fromCard(key, json.cards[key] || null);
                ui.saved = JSON.stringify(state[key]);
                render(key);
            });
        } catch (err) {
            toast(`Error al cargar configuración: ${err.message || 'Desconocido'}`, 'error');
        }
    };

    window.addEventListener('beforeunload', (e) => {
        if ([...sections.values()].some((ui) => ui.section.dataset.dirty === 'true')) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    load();
    loadGallery();
})();
</script>

<?php include __DIR__ . '/../core/admin_footer.php'; ?>

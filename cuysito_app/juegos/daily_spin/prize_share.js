/**
 * DailySpinShare — composición de la imagen PNG "premio ganado" del Giro Diario.
 *
 * Módulo puro de render: recibe los datos del premio (`DailySpin._lastWin`) y
 * devuelve un Blob PNG; no lee el DOM del modal ni toca su estado.
 *
 * Diseño:
 *  - El layout se calcula en px lógicos (416 px de ancho, mismos tokens que
 *    `.ds-win-container` en daily_spin.css) y se rasteriza a OUTPUT_SCALE×
 *    mediante `ctx.scale`, así el resultado es nítido sin deformar nada.
 *  - Canvas 2D no aplica `font-variation-settings`, por lo que la fuente
 *    variable HeadingNow se dibujaba en su instancia por defecto (condensada y
 *    delgada). Se usan instancias estáticas equivalentes a los tokens CSS
 *    (wdth 668/wght 500 y wdth 825/wght 700; ver scripts/build-heading-now-static.py)
 *    registradas con FontFace bajo la familia `HeadingNowStatic`.
 *  - El texto nunca se escala horizontalmente: se mide con `measureText`, se
 *    parte en líneas y, si aun así no cabe, se elide con "…".
 *  - Fuentes e imagen se cargan best-effort con timeout; ningún fallo de red
 *    bloquea la generación (se degrada al gradiente/fallback tipográfico).
 */
const DailySpinShare = (() => {
    'use strict';

    const CONFIG = Object.freeze({
        OUTPUT_SCALE: 3,
        FILE_NAME: 'premio-giro-diario.png',
        FONT_FAMILY: 'HeadingNowStatic',
        FONT_FALLBACK: "'Barlow', 'Inter', system-ui, sans-serif",
        FONT_FACES: Object.freeze([
            { weight: '500', url: '/assets/fonts/HeadingNowStatic-Medium.woff2' },
            { weight: '700', url: '/assets/fonts/HeadingNowStatic-Bold.woff2' },
        ]),
        FONT_TIMEOUT_MS: 2500,
        IMAGE_TIMEOUT_MS: 6000,
    });

    const COLOR = Object.freeze({
        surface: '#18181b',
        text: '#e4e4e7',
        muted: '#71717a',
        dark: 'rgb(21, 23, 24)',
        accent: '#f59e0b',
        success: '#10b981',
        yellow: '#f7ff19',
        brand: 'rgba(203, 213, 225, 0.78)',
    });

    /** Tokens del modal (`.ds-win-container`, 1rem = 16px) en px lógicos. */
    const LAYOUT = Object.freeze({
        width: 416,
        padTop: 32,
        padX: 28,
        padBottom: 28,
        gap: 12,
        radius: 12,
        lineHeight: 1.25,
        overline: { size: 13, weight: 500 },
        title: { size: 24, weight: 700, italic: true },
        nick: { size: 13, weight: 500 },
        name: { size: 16, weight: 500, maxLines: 2 },
        card: {
            maxHeight: 240,
            badge: { offset: 12, padX: 10, padY: 4, radius: 6, size: 14, weight: 700, italic: true },
        },
        pricing: { original: 15, final: 20, weight: 700, gap: 10, strike: 1.5 },
        brand: { size: 13, weight: 700, icon: 14, gap: 6 },
    });

    const BRAND_TEXT = 'BANANO.PE';
    const GLOBE_ICON_PATH =
        'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20M12 2a4 10 0 1 0 0 20 4 10 0 0 0 0-20M2.46 9h19.08M2.46 15h19.08';

    /* ------------------------------ utilidades ------------------------------ */

    /** Resuelve con `fallback` si `promise` no termina en `ms`; nunca rechaza. */
    function withTimeout(promise, ms, fallback) {
        let timer = null;
        const timeout = new Promise((resolve) => {
            timer = setTimeout(() => resolve(fallback), ms);
        });
        return Promise.race([promise, timeout]).then(
            (value) => { clearTimeout(timer); return value; },
            () => { clearTimeout(timer); return fallback; }
        );
    }

    let _fontsReady = null;

    /** Registra y carga las instancias estáticas una sola vez por página. */
    function loadFonts() {
        if (_fontsReady) return _fontsReady;
        if (!document.fonts || typeof FontFace !== 'function') {
            _fontsReady = Promise.resolve();
            return _fontsReady;
        }
        const loads = CONFIG.FONT_FACES.map((face) => {
            let fontFace;
            try {
                fontFace = new FontFace(CONFIG.FONT_FAMILY, `url("${face.url}") format("woff2")`, {
                    weight: face.weight,
                    style: 'normal',
                });
            } catch (_) {
                return Promise.resolve();
            }
            document.fonts.add(fontFace);
            return fontFace.load().catch(() => { });
        });
        _fontsReady = withTimeout(Promise.all(loads), CONFIG.FONT_TIMEOUT_MS, undefined).then(() => undefined);
        return _fontsReady;
    }

    function loadImage(url) {
        if (!url) return Promise.resolve(null);
        return new Promise((resolve) => {
            const img = new Image();
            let timer = null;
            const settle = (result) => {
                clearTimeout(timer);
                img.onload = null;
                img.onerror = null;
                if (result === null) img.src = '';
                resolve(result);
            };
            timer = setTimeout(() => settle(null), CONFIG.IMAGE_TIMEOUT_MS);
            img.crossOrigin = 'anonymous';
            img.onload = () => settle(img);
            img.onerror = () => settle(null);
            img.src = url;
        });
    }

    function fontOf(spec) {
        return `${spec.italic ? 'italic ' : ''}${spec.weight} ${spec.size}px "${CONFIG.FONT_FAMILY}", ${CONFIG.FONT_FALLBACK}`;
    }

    /** Métrica de una línea: ancho real y baseline centrada en la caja de línea. */
    function measure(ctx, spec, text) {
        ctx.font = fontOf(spec);
        const metrics = ctx.measureText(text);
        const ascent = typeof metrics.fontBoundingBoxAscent === 'number' ? metrics.fontBoundingBoxAscent : spec.size * 0.8;
        const descent = typeof metrics.fontBoundingBoxDescent === 'number' ? metrics.fontBoundingBoxDescent : spec.size * 0.2;
        const height = spec.size * LAYOUT.lineHeight;
        return { width: metrics.width, height, baseline: (height - (ascent + descent)) / 2 + ascent };
    }

    /** Recorta con "…" por búsqueda binaria sobre el ancho medido. */
    function ellipsize(ctx, spec, text, maxWidth) {
        ctx.font = fontOf(spec);
        if (ctx.measureText(text).width <= maxWidth) return text;
        const ellipsis = '…';
        let lo = 0;
        let hi = text.length;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (ctx.measureText(text.slice(0, mid).trimEnd() + ellipsis).width <= maxWidth) lo = mid;
            else hi = mid - 1;
        }
        return text.slice(0, lo).trimEnd() + ellipsis;
    }

    /** Parte en palabras hasta `maxLines`; la última línea se elide si sobra texto. */
    function wrapLines(ctx, spec, text, maxWidth, maxLines) {
        ctx.font = fontOf(spec);
        const words = text.split(/\s+/).filter(Boolean);
        const lines = [];
        let current = '';
        for (let i = 0; i < words.length; i++) {
            const candidate = current ? `${current} ${words[i]}` : words[i];
            if (ctx.measureText(candidate).width > maxWidth && current) {
                lines.push(current);
                current = words[i];
                if (lines.length === maxLines - 1) {
                    return lines.concat(ellipsize(ctx, spec, words.slice(i).join(' '), maxWidth));
                }
            } else {
                current = candidate;
            }
        }
        if (current) lines.push(ellipsize(ctx, spec, current, maxWidth));
        return lines.length ? lines : [''];
    }

    /** Datos del premio saneados: nunca lanza ante entradas inesperadas. */
    function normalizeWin(raw) {
        const win = raw && typeof raw === 'object' ? raw : {};
        const text = (value, fallback) => (typeof value === 'string' && value.trim() ? value.trim() : fallback);
        const money = (value) => (Number.isFinite(value) && value > 0 ? value : 0);
        const discount = Number.isFinite(win.discount) ? Math.round(win.discount) : 0;
        const basePrice = money(win.basePrice);
        return {
            name: text(win.name, 'Objeto'),
            imgUrl: text(win.imgUrl, ''),
            discount,
            isFlash: win.isFlash === true,
            colorTop: text(win.colorTop, '#2a2b42'),
            colorBot: text(win.colorBot, '#12132a'),
            badgeText: text(win.badgeText, `-${discount}%`),
            basePrice,
            finalPrice: basePrice > 0 ? money(win.finalPrice) : 0,
            nick: text(win.nick, ''),
        };
    }

    function roundRectPath(ctx, x, y, width, height, radius) {
        const r = Math.min(radius, width / 2, height / 2);
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + width, y, x + width, y + height, r);
        ctx.arcTo(x + width, y + height, x, y + height, r);
        ctx.arcTo(x, y + height, x, y, r);
        ctx.arcTo(x, y, x + width, y, r);
        ctx.closePath();
    }

    /* -------------------------------- layout -------------------------------- */

    /**
     * Distribuye los bloques verticalmente (flujo columna + gap, como el modal)
     * y devuelve el alto final: el lienzo crece con el contenido en vez de
     * comprimirlo.
     */
    function planBlocks(ctx, win) {
        const contentWidth = LAYOUT.width - LAYOUT.padX * 2;
        const blocks = [];
        let y = LAYOUT.padTop;

        const pushText = (spec, text, color) => {
            const m = measure(ctx, spec, text);
            blocks.push({ type: 'text', y, text, spec, color, baseline: m.baseline });
            y += m.height + LAYOUT.gap;
        };

        pushText(LAYOUT.overline, 'GIRO DIARIO', COLOR.brand);
        pushText(LAYOUT.title, '¡FELICIDADES!', COLOR.yellow);
        if (win.nick) {
            pushText(LAYOUT.nick, ellipsize(ctx, LAYOUT.nick, 'GANADO POR ' + win.nick.toUpperCase(), contentWidth), COLOR.muted);
        }

        const nameLines = wrapLines(ctx, LAYOUT.name, win.name.toUpperCase(), contentWidth, LAYOUT.name.maxLines);
        nameLines.forEach((line, index) => {
            const m = measure(ctx, LAYOUT.name, line);
            blocks.push({ type: 'text', y, text: line, spec: LAYOUT.name, color: COLOR.text, baseline: m.baseline });
            y += m.height + (index === nameLines.length - 1 ? LAYOUT.gap : 0);
        });

        // Tarjeta cuadrada (aspect-ratio 1/1) acotada por max-height, como en CSS.
        const cardHeight = Math.min(contentWidth, LAYOUT.card.maxHeight);
        blocks.push({ type: 'card', y, x: LAYOUT.padX, width: contentWidth, height: cardHeight });
        y += cardHeight + LAYOUT.gap;

        if (win.basePrice > 0) {
            const originalSpec = { size: LAYOUT.pricing.original, weight: LAYOUT.pricing.weight };
            const finalSpec = { size: LAYOUT.pricing.final, weight: LAYOUT.pricing.weight };
            const originalText = 'S/ ' + win.basePrice.toFixed(2);
            const finalText = 'S/ ' + win.finalPrice.toFixed(2);
            const original = measure(ctx, originalSpec, originalText);
            const final = measure(ctx, finalSpec, finalText);
            const total = original.width + LAYOUT.pricing.gap + final.width;
            blocks.push({
                type: 'pricing',
                y,
                x: LAYOUT.width / 2 - total / 2,
                baseline: final.baseline,
                original: { text: originalText, spec: originalSpec, width: original.width },
                final: { text: finalText, spec: finalSpec, width: final.width, offset: original.width + LAYOUT.pricing.gap },
            });
            y += final.height + LAYOUT.gap;
        }

        const brandSpec = { size: LAYOUT.brand.size, weight: LAYOUT.brand.weight };
        const brand = measure(ctx, brandSpec, BRAND_TEXT);
        const brandWidth = brand.width + LAYOUT.brand.gap + LAYOUT.brand.icon;
        blocks.push({
            type: 'brand',
            y,
            x: LAYOUT.width / 2 - brandWidth / 2,
            spec: brandSpec,
            text: BRAND_TEXT,
            textWidth: brand.width,
            baseline: brand.baseline,
            height: brand.height,
        });
        y += brand.height + LAYOUT.padBottom;

        return { width: LAYOUT.width, height: Math.ceil(y), blocks };
    }

    /* -------------------------------- pintura ------------------------------- */

    function paintText(ctx, block) {
        ctx.font = fontOf(block.spec);
        ctx.fillStyle = block.color;
        ctx.textAlign = 'center';
        ctx.fillText(block.text, LAYOUT.width / 2, block.y + block.baseline);
    }

    function paintCard(ctx, block, win, img) {
        const { x, y, width, height } = block;

        ctx.save();
        roundRectPath(ctx, x, y, width, height, LAYOUT.radius);
        ctx.clip();
        const gradient = ctx.createLinearGradient(0, y, 0, y + height);
        gradient.addColorStop(0, win.colorTop);
        gradient.addColorStop(1, win.colorBot);
        ctx.fillStyle = gradient;
        ctx.fillRect(x, y, width, height);
        if (img && img.naturalWidth > 0 && img.naturalHeight > 0) {
            // object-fit: contain
            const scale = Math.min(width / img.naturalWidth, height / img.naturalHeight);
            const drawWidth = img.naturalWidth * scale;
            const drawHeight = img.naturalHeight * scale;
            ctx.drawImage(img, x + (width - drawWidth) / 2, y + (height - drawHeight) / 2, drawWidth, drawHeight);
        }
        ctx.restore();

        const badge = LAYOUT.card.badge;
        const label = measure(ctx, badge, win.badgeText);
        const badgeWidth = label.width + badge.padX * 2;
        const badgeHeight = label.height + badge.padY * 2;
        const badgeX = x + badge.offset;
        const badgeY = y + badge.offset;
        ctx.fillStyle = win.isFlash ? COLOR.accent : COLOR.yellow;
        roundRectPath(ctx, badgeX, badgeY, badgeWidth, badgeHeight, badge.radius);
        ctx.fill();
        ctx.font = fontOf(badge);
        ctx.fillStyle = COLOR.dark;
        ctx.textAlign = 'left';
        ctx.fillText(win.badgeText, badgeX + badge.padX, badgeY + badge.padY + label.baseline);
    }

    function paintPricing(ctx, block) {
        const baselineY = block.y + block.baseline;
        ctx.textAlign = 'left';

        ctx.font = fontOf(block.original.spec);
        ctx.fillStyle = COLOR.muted;
        ctx.fillText(block.original.text, block.x, baselineY);
        ctx.strokeStyle = COLOR.muted;
        ctx.lineWidth = LAYOUT.pricing.strike;
        ctx.beginPath();
        const strikeY = baselineY - block.original.spec.size * 0.3;
        ctx.moveTo(block.x, strikeY);
        ctx.lineTo(block.x + block.original.width, strikeY);
        ctx.stroke();

        ctx.font = fontOf(block.final.spec);
        ctx.fillStyle = COLOR.success;
        ctx.fillText(block.final.text, block.x + block.final.offset, baselineY);
    }

    function paintBrand(ctx, block) {
        ctx.font = fontOf(block.spec);
        ctx.fillStyle = COLOR.brand;
        ctx.textAlign = 'left';
        ctx.fillText(block.text, block.x, block.y + block.baseline);

        const icon = LAYOUT.brand.icon;
        ctx.save();
        ctx.translate(block.x + block.textWidth + LAYOUT.brand.gap, block.y + (block.height - icon) / 2);
        ctx.scale(icon / 24, icon / 24);
        ctx.strokeStyle = COLOR.brand;
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.stroke(new Path2D(GLOBE_ICON_PATH));
        ctx.restore();
    }

    function paint(ctx, plan, win, img) {
        ctx.fillStyle = COLOR.surface;
        ctx.fillRect(0, 0, plan.width, plan.height);
        ctx.textBaseline = 'alphabetic';
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        for (const block of plan.blocks) {
            switch (block.type) {
                case 'text': paintText(ctx, block); break;
                case 'card': paintCard(ctx, block, win, img); break;
                case 'pricing': paintPricing(ctx, block); break;
                case 'brand': paintBrand(ctx, block); break;
            }
        }
    }

    function toPngBlob(canvas) {
        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                // Libera el backing store del canvas en cuanto el PNG existe.
                canvas.width = 0;
                canvas.height = 0;
                if (blob) resolve(blob);
                else reject(new Error('canvas_blob_failed'));
            }, 'image/png');
        });
    }

    /* ------------------------------- API pública ---------------------------- */

    /** @returns {Promise<Blob>} PNG del premio a OUTPUT_SCALE× del layout lógico. */
    function buildPrizeImage(rawWin) {
        const win = normalizeWin(rawWin);
        return Promise.all([loadImage(win.imgUrl), loadFonts()]).then(([img]) => {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            if (!ctx) throw new Error('canvas_2d_unavailable');

            const plan = planBlocks(ctx, win);
            canvas.width = plan.width * CONFIG.OUTPUT_SCALE;
            canvas.height = plan.height * CONFIG.OUTPUT_SCALE;
            ctx.scale(CONFIG.OUTPUT_SCALE, CONFIG.OUTPUT_SCALE);
            paint(ctx, plan, win, img);
            return toPngBlob(canvas);
        });
    }

    function downloadBlob(blob) {
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = CONFIG.FILE_NAME;
        anchor.rel = 'noopener';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    /**
     * Genera el PNG y lo comparte con Web Share si el navegador admite archivos;
     * si no, lo descarga. Rechaza con `AbortError` cuando el usuario cancela.
     */
    function share(rawWin) {
        const win = normalizeWin(rawWin);
        return buildPrizeImage(win).then((blob) => {
            const file = new File([blob], CONFIG.FILE_NAME, { type: 'image/png' });
            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                return navigator.share({
                    files: [file],
                    title: '¡Gané en el Giro Diario!',
                    text: `${win.name} con -${win.discount}% de descuento`,
                });
            }
            downloadBlob(blob);
        });
    }

    return Object.freeze({ share, buildPrizeImage, CONFIG });
})();

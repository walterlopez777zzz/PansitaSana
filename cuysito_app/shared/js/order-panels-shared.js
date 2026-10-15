// Módulo compartido (sin dependencias) para renderizar filas de productos de
// pedidos y calcular los degradados de rareza de Fortnite. Consumido por
// /cuenta (dashboard.js) y /wabo (gestionar_pedidos.php).

export function roundSoles(value) {
    const n = Number(value);
    return Number.isFinite(n) ? Math.round(n * 10) / 10 : 0;
}

export function normalizeHexDb(value) {
    const raw = String(value ?? '').trim();
    if (!raw) return null;
    const hex = raw.replace(/^#/, '').toLowerCase();
    if (/^[0-9a-f]{6}$/.test(hex)) return `#${hex}`;
    if (/^[0-9a-f]{8}$/.test(hex)) return `#${hex.slice(0, 6)}`;
    return null;
}

export function buildFortniteGradientVarsFromDb(color1, color2, color3) {
    const c1 = normalizeHexDb(color1);
    const c2 = normalizeHexDb(color2);
    const c3 = normalizeHexDb(color3);
    const vars = [];
    if (c1) vars.push(`--fn-color-1: ${c1};`);
    if (c2) vars.push(`--fn-color-2: ${c2};`);
    if (c3) vars.push(`--fn-color-3: ${c3};`);
    return vars.join(' ');
}

export function formatMoneySoles(value) {
    const n = Number(value);
    return Number.isFinite(n) ? roundSoles(n).toFixed(2) : '0.00';
}

export function formatNumber(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n.toLocaleString('es-ES') : '0';
}

export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

export function renderOrderProductRow(product, options = {}) {
    const p = product || {};
    const variant = options && options.variant === 'admin' ? 'admin' : 'user';
    const priceLayout = options && options.priceLayout
        ? String(options.priceLayout)
        : (variant === 'admin' ? 'stack' : 'horizontal');

    const image = p.imagen_producto_url || p.image || '';
    const name = p.nombre_producto || p.name || '';
    const type = p.tipo_producto || p.tipo || p.displayType || '';
    const quantity = Number(p.cantidad ?? p.quantity ?? 0) || 0;
    const finalPriceRaw = p.precio_final ?? p.price ?? null;
    const basePriceRaw = p.precio_base ?? p.base_price ?? null;
    const vbucksRaw = p.v_bucks ?? p.vbucks ?? null;

    const finalPrice = Number(finalPriceRaw);
    const basePrice = Number(basePriceRaw);
    const hasFinalPrice = finalPriceRaw != null && Number.isFinite(finalPrice);
    const hasBasePrice = basePriceRaw != null && Number.isFinite(basePrice);
    const hasDiscount = hasFinalPrice && hasBasePrice && basePrice > 0 && finalPrice < basePrice;
    const hasVbucks = vbucksRaw != null && String(vbucksRaw).trim() !== '';

    const gradientVars = buildFortniteGradientVarsFromDb(p.color_1, p.color_2, p.color_3);
    const sectionName = String(p.section_name ?? p.cacheData?.section_name ?? '').trim();
    const storeRankRaw = p.store_rank ?? p.cacheData?.store_rank ?? null;
    const storeRank = storeRankRaw != null && String(storeRankRaw).trim() !== '' ? String(storeRankRaw).trim() : '';

    let priceHtml = '';
    if (hasFinalPrice) {
        if (hasDiscount) {
            if (variant === 'admin' || priceLayout === 'stack') {
                priceHtml = `
                    <div class="flex flex-col items-end leading-tight">
                        <div class="text-sm font-semibold text-zinc-500 line-through">S/ ${escapeHtml(formatMoneySoles(basePrice))}</div>
                        <div class="text-xl font-extrabold text-emerald-400">S/ ${escapeHtml(formatMoneySoles(finalPrice))}</div>
                    </div>
                `;
            } else {
                priceHtml = `
                    <div class="flex items-baseline justify-end gap-2">
                        <span class="text-xs font-semibold text-zinc-500 line-through">S/ ${escapeHtml(formatMoneySoles(basePrice))}</span>
                        <span class="text-sm font-semibold text-emerald-400">S/ ${escapeHtml(formatMoneySoles(finalPrice))}</span>
                    </div>
                `;
            }
        } else if (variant === 'admin') {
            priceHtml = `<div class="text-xl font-extrabold text-zinc-100">S/ ${escapeHtml(formatMoneySoles(finalPrice))}</div>`;
        } else {
            priceHtml = `<div class="text-sm font-semibold text-zinc-100">S/ ${escapeHtml(formatMoneySoles(finalPrice))}</div>`;
        }
    }

    let vbucksHtml = '';
    if (hasVbucks) {
        vbucksHtml = variant === 'admin'
            ? `<div class="text-lg font-bold tracking-wider text-zinc-200">${escapeHtml(formatNumber(vbucksRaw))} PAVOS</div>`
            : `<div class="text-xs font-bold tracking-wider text-zinc-400">${escapeHtml(formatNumber(vbucksRaw))} PAVOS</div>`;
    }

    if (variant === 'admin') {
        return `
            <div class="order-product-row flex items-start gap-4 bg-zinc-950 border border-zinc-800 rounded-xl px-4 py-3">
                <div class="min-w-0 flex-1">
                    <div class="text-base font-bold text-zinc-100 truncate">${escapeHtml(name)}</div>
                    ${quantity ? `<div class="mt-0.5 text-sm text-zinc-400 truncate">x${escapeHtml(quantity)}</div>` : ''}
                    ${type ? `<div class="mt-1 text-xs font-bold tracking-wider text-zinc-500 truncate">${escapeHtml(type)}</div>` : ''}
                    <div class="mt-2 grid grid-cols-2 gap-3 md:hidden">
                        <div>
                            <div class="text-[10px] font-bold tracking-[0.22em] uppercase text-zinc-500">Sección</div>
                            <div class="mt-1 text-sm font-semibold text-zinc-200 truncate">${escapeHtml(sectionName || '—')}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-[10px] font-bold tracking-[0.22em] uppercase text-zinc-500">Rank #</div>
                            <div class="mt-1 text-sm font-semibold text-zinc-200">${escapeHtml(storeRank || '—')}</div>
                        </div>
                    </div>
                </div>
                <div class="hidden md:block w-56 shrink-0">
                    <div class="text-[10px] font-bold tracking-[0.22em] uppercase text-zinc-500">Sección</div>
                    <div class="mt-1 text-sm font-semibold text-zinc-200 truncate">${escapeHtml(sectionName || '—')}</div>
                </div>
                <div class="hidden md:block w-24 shrink-0 text-center">
                    <div class="text-[10px] font-bold tracking-[0.22em] uppercase text-zinc-500">Rank #</div>
                    <div class="mt-1 text-sm font-semibold text-zinc-200">${escapeHtml(storeRank || '—')}</div>
                </div>
                <div class="shrink-0 w-48 text-right">
                    ${priceHtml}
                    ${vbucksHtml}
                </div>
            </div>
        `;
    }

    return `
        <div class="order-product-row flex items-start justify-between gap-4 bg-zinc-950 border border-zinc-800 rounded-xl px-4 py-3">
            <div class="flex items-start gap-3 min-w-0">
                <div class="w-12 h-12 rounded-lg border border-zinc-800 shrink-0 overflow-hidden fortnite-gradient" style="${gradientVars}">
                    ${image ? `<img src="${escapeHtml(image)}" alt="" class="w-full h-full object-cover" />` : ''}
                </div>
                <div class="min-w-0">
                    <div class="text-sm font-semibold text-zinc-100 truncate">${escapeHtml(name)}</div>
                    <div class="mt-0.5 text-xs font-bold tracking-wider text-zinc-500 truncate">
                        ${type ? `${escapeHtml(type)}${quantity ? ` • x${escapeHtml(quantity)}` : ''}` : (quantity ? `x${escapeHtml(quantity)}` : '')}
                    </div>
                </div>
            </div>
            <div class="shrink-0 text-right">
                ${priceHtml}
                ${vbucksHtml}
            </div>
        </div>
    `;
}

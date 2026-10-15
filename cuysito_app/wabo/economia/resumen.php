<?php
// /wabo/economia/resumen.php — Circulación real, emisión por origen y distribución de saldos.
require_once __DIR__ . '/../core/admin_header.php';
$ecoCurrent = 'resumen';
$ecoSubtitle = 'Cuántos Coins existen, de dónde salen, en qué se gastan y cómo se reparten entre los usuarios.';
require_once __DIR__ . '/economia_ui.php';
?>

<div class="max-w-[1500px] mx-auto">

<!-- FILTRO DE PERIODO -->
<div class="card rounded-xl p-3 mb-4 eco-fade eco-d1 flex flex-wrap items-center gap-2">
    <span class="text-xs font-semibold uppercase tracking-wide mr-1" style="color: var(--text-secondary);">Periodo</span>
    <button class="eco-btn text-xs px-3 py-1.5 rounded-lg border" style="border-color: var(--border-color);" onclick="ecoSetPeriod(7)">7 días</button>
    <button class="eco-btn text-xs px-3 py-1.5 rounded-lg border" style="border-color: var(--border-color);" onclick="ecoSetPeriod(30)">30 días</button>
    <button class="eco-btn text-xs px-3 py-1.5 rounded-lg border" style="border-color: var(--border-color);" onclick="ecoSetPeriod(90)">90 días</button>
    <button class="eco-btn text-xs px-3 py-1.5 rounded-lg border" style="border-color: var(--border-color);" onclick="ecoSetMonth()">Este mes</button>
    <button class="eco-btn text-xs px-3 py-1.5 rounded-lg border" style="border-color: var(--border-color);" onclick="ecoSetPeriod(3650)">Histórico</button>
    <div class="flex items-center gap-2 ml-auto">
        <input type="date" id="ecoFrom" class="eco-input text-sm py-1.5 px-2 rounded-lg border bg-transparent" style="border-color: var(--border-color);">
        <span class="text-xs" style="color: var(--text-secondary);">→</span>
        <input type="date" id="ecoTo" class="eco-input text-sm py-1.5 px-2 rounded-lg border bg-transparent" style="border-color: var(--border-color);">
        <button onclick="ecoLoadSummary()" class="eco-btn text-sm px-4 py-1.5 rounded-lg font-semibold text-white" style="background: var(--accent);"><i class="fas fa-sync-alt mr-1"></i>Aplicar</button>
    </div>
</div>

<!-- KPIs -->
<div id="ecoKpis" class="grid grid-cols-2 md:grid-cols-4 2xl:grid-cols-8 gap-3 mb-4">
    <?php for ($i = 0; $i < 8; $i++) echo '<div class="eco-skel h-24"></div>'; ?>
</div>

<!-- INDICADOR DE UMBRAL -->
<div id="ecoFocus" class="card rounded-xl p-4 mb-5 eco-fade eco-d1 flex flex-col lg:flex-row lg:items-center gap-4" style="border-left: 4px solid var(--accent);">
    <div class="flex-1 min-w-0">
        <p class="text-[11px] font-semibold uppercase tracking-wide" style="color: var(--text-secondary);"><i class="fas fa-bullseye mr-1"></i>Indicador de umbral</p>
        <p class="text-2xl md:text-3xl font-extrabold mt-1 leading-tight"><span id="ecoFocusUsers">—</span> <span class="text-lg font-semibold" style="color: var(--text-secondary);">usuarios tienen más de</span> <span id="ecoFocusValue">10</span> <span class="text-lg font-semibold" style="color: var(--text-secondary);">Coins</span></p>
        <p id="ecoFocusSub" class="text-xs mt-1" style="color: var(--text-secondary);"></p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <span class="text-xs" style="color: var(--text-secondary);">Más de</span>
        <input type="number" id="ecoFocusInput" value="10" min="0" step="1" class="eco-input text-sm py-1.5 px-2 rounded-lg border bg-transparent w-24 font-bold" style="border-color: var(--border-color);" onchange="ecoLoadDistribution()">
        <span class="text-xs" style="color: var(--text-secondary);">Coins</span>
        <div class="flex gap-1 ml-1">
            <?php foreach ([5, 10, 20, 50, 100] as $q): ?><button class="eco-btn text-xs px-2.5 py-1 rounded-lg border" style="border-color: var(--border-color);" onclick="ecoSetFocus(<?php echo $q; ?>)"><?php echo $q; ?></button><?php endforeach; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-5">
    <!-- GRÁFICA DIARIA -->
    <div class="card rounded-xl p-4 xl:col-span-2 eco-fade eco-d1">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-sm uppercase tracking-wide" style="color: var(--text-secondary);"><i class="fas fa-chart-column mr-1"></i>Emisión vs consumo por día</h3>
            <div class="flex items-center gap-3 text-[11px]" style="color: var(--text-secondary);"><span><i class="fas fa-square eco-pos mr-1"></i>Emitido</span><span><i class="fas fa-square eco-neg mr-1"></i>Consumido</span></div>
        </div>
        <div id="ecoDaily" class="h-52 flex items-end gap-[3px] overflow-hidden"><div class="eco-skel w-full h-full"></div></div>
        <div id="ecoDailyHint" class="text-[11px] mt-2 text-center" style="color: var(--text-secondary);"></div>
    </div>

    <!-- ORIGEN -->
    <div class="card rounded-xl p-4 eco-fade eco-d2">
        <h3 class="font-bold text-sm uppercase tracking-wide mb-3" style="color: var(--text-secondary);"><i class="fas fa-sitemap mr-1"></i>Emisión por origen</h3>
        <div id="ecoByCategory" class="space-y-3"><div class="eco-skel h-6"></div><div class="eco-skel h-6"></div><div class="eco-skel h-6"></div></div>
        <div class="mt-4 pt-3 border-t text-[11px] leading-relaxed" style="border-color: var(--border-color); color: var(--text-secondary);">
            <b>Orgánico</b> = generado por el sistema (cashback, sorteos, giro, vídeos). <b>Manual</b> = añadido o retirado por un administrador. Las reglas de cada origen se editan en su propio módulo.
        </div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-5">
    <!-- DISTRIBUCIÓN / SIMULADOR DE CUOTA -->
    <div class="card rounded-xl overflow-hidden eco-fade eco-d2">
        <div class="p-4 border-b flex flex-wrap items-center justify-between gap-2" style="border-color: var(--border-color);">
            <div>
                <h3 class="font-bold text-sm uppercase tracking-wide" style="color: var(--text-secondary);"><i class="fas fa-layer-group mr-1"></i>Distribución de saldos</h3>
                <p class="text-[11px] mt-0.5" style="color: var(--text-secondary);">Cuántos usuarios podrían pagar una cuota de inscripción de X Coins.</p>
            </div>
            <div class="flex items-center gap-2">
                <input type="text" id="ecoThresholds" value="1,5,10,20,50,100,200,500" class="eco-input text-xs py-1.5 px-2 rounded-lg border bg-transparent w-56" style="border-color: var(--border-color);" placeholder="Umbrales, ej: 5,20,50">
                <button onclick="ecoLoadDistribution()" class="eco-btn text-xs px-3 py-1.5 rounded-lg border font-semibold" style="border-color: var(--border-color);">Recalcular</button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="eco-table w-full">
                <thead><tr><th>Saldo ≥</th><th class="text-right">Usuarios</th><th class="text-right">% holders</th><th class="text-right">% registrados</th><th class="text-right">Coins que concentran</th><th class="text-right">Bote si todos pagan</th></tr></thead>
                <tbody id="ecoDistBody"><tr><td colspan="6"><div class="eco-skel h-6 my-2"></div></td></tr></tbody>
            </table>
        </div>
        <div id="ecoBands" class="p-4 border-t" style="border-color: var(--border-color);"></div>
    </div>

    <!-- POR MOTIVO -->
    <div class="card rounded-xl overflow-hidden eco-fade eco-d3">
        <div class="p-4 border-b" style="border-color: var(--border-color);">
            <h3 class="font-bold text-sm uppercase tracking-wide" style="color: var(--text-secondary);"><i class="fas fa-list mr-1"></i>Detalle por motivo (periodo)</h3>
        </div>
        <div class="overflow-x-auto max-h-[460px] overflow-y-auto">
            <table class="eco-table w-full">
                <thead><tr><th>Motivo</th><th>Origen</th><th class="text-right">Emitido</th><th class="text-right">Consumido</th><th class="text-right">Mov.</th></tr></thead>
                <tbody id="ecoReasonBody"><tr><td colspan="5"><div class="eco-skel h-6 my-2"></div></td></tr></tbody>
            </table>
        </div>
    </div>
</div>

</div>

<script>
function ecoSetPeriod(days) { document.getElementById('ecoFrom').value = ecoDaysAgo(days - 1); document.getElementById('ecoTo').value = ecoToday(); ecoLoadSummary(); }
function ecoSetMonth() { document.getElementById('ecoFrom').value = ecoMonthStart(); document.getElementById('ecoTo').value = ecoToday(); ecoLoadSummary(); }

function ecoKpiCard(l, v, s, icon, cls) {
    return `<div class="eco-kpi card rounded-xl p-4 eco-fade"><div class="flex items-start justify-between gap-2"><div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">${l}</p><p class="text-xl font-bold mt-1 ${cls || ''} truncate">${v}</p>${s ? `<p class="text-[10px] mt-0.5" style="color: var(--text-secondary);">${s}</p>` : ''}</div><div class="p-2 rounded-lg" style="background: rgba(128,128,128,.1);"><i class="fas ${icon} ${cls || ''}"></i></div></div></div>`;
}

async function ecoLoadSummary() {
    const from = document.getElementById('ecoFrom').value, to = document.getElementById('ecoTo').value;
    try {
        const j = await ecoGet({ action: 'summary', from, to, thresholds: document.getElementById('ecoThresholds').value, focus: ecoFocusValue() });
        const d = j.data, c = d.circulation, t = d.totals;
        const manualPct = t.emitted > 0 ? Math.round(t.manual_in * 100 / t.emitted) : 0;
        document.getElementById('ecoKpis').innerHTML = [
            ecoKpiCard('Coins en circulación', ecoNum(c.total), `${ecoInt(c.holders)} holders de ${ecoInt(c.users_total)} usuarios`, 'fa-coins', ''),
            ecoKpiCard('Media por usuario', ecoNum(c.avg_user), `Todos los registrados · por holder: ${ecoNum(c.avg_holder)}`, 'fa-divide', ''),
            ecoKpiCard('Mediana por usuario', ecoNum(c.median_user), `La mitad tiene menos · por holder: ${ecoNum(c.median_holder)}`, 'fa-align-center', ''),
            ecoKpiCard('Saldo máximo', ecoNum(c.max_balance), 'El holder con más Coins', 'fa-crown', ''),
            ecoKpiCard('Emitido en el periodo', ecoNum(t.emitted), `${ecoInt(t.tx_count)} movimientos · ${ecoInt(t.active_users)} usuarios`, 'fa-arrow-trend-up', 'eco-pos'),
            ecoKpiCard('Consumido en el periodo', ecoNum(t.consumed), 'Compras, inscripciones, tiradas…', 'fa-arrow-trend-down', 'eco-neg'),
            ecoKpiCard('Balance neto', ecoSigned(t.net), t.net >= 0 ? 'La masa monetaria creció' : 'La masa monetaria se redujo', 'fa-scale-balanced', t.net >= 0 ? 'eco-pos' : 'eco-neg'),
            ecoKpiCard('Emisión manual (admin)', ecoNum(t.manual_in), `${manualPct}% de lo emitido · retirado: ${ecoNum(t.manual_out)}`, 'fa-user-shield', manualPct > 30 ? 'eco-neg' : ''),
        ].join('');

        // Gráfica diaria (barras CSS)
        const daily = d.daily || [];
        const max = Math.max(1, ...daily.map(r => Math.max(Number(r.emitted), Number(r.consumed))));
        const box = document.getElementById('ecoDaily');
        if (!daily.length) { box.innerHTML = '<div class="w-full text-center text-sm self-center" style="color: var(--text-secondary);">Sin movimientos en el periodo.</div>'; }
        else {
            box.innerHTML = daily.map(r => {
                const e = Math.round(Number(r.emitted) * 100 / max), s = Math.round(Number(r.consumed) * 100 / max);
                return `<div class="flex-1 min-w-[4px] h-full flex items-end gap-[1px]" title="${r.d}: +${ecoNum(r.emitted)} / -${ecoNum(r.consumed)}"><span class="flex-1 rounded-t" style="height:${Math.max(e, 1)}%; background:#4ade80;"></span><span class="flex-1 rounded-t" style="height:${Math.max(s, 1)}%; background:#f87171;"></span></div>`;
            }).join('');
        }
        document.getElementById('ecoDailyHint').textContent = daily.length ? `${daily[0].d} → ${daily[daily.length - 1].d} · ${daily.length} días con actividad` : '';

        // Por categoría
        const cats = d.by_category || [];
        const catMax = Math.max(1, ...cats.map(r => Math.max(Number(r.emitted), Number(r.consumed))));
        document.getElementById('ecoByCategory').innerHTML = cats.length ? cats.map(r => `
            <div>
                <div class="flex items-center justify-between text-xs mb-1"><span>${ecoChip(r.category, r.label)}</span><span style="color: var(--text-secondary);">${ecoInt(r.tx_count)} mov.</span></div>
                <div class="flex items-center gap-2 text-[11px]"><span class="w-16 text-right eco-pos">+${ecoNum(r.emitted, 0)}</span><div class="eco-bar flex-1"><span style="width:${Math.round(Number(r.emitted) * 100 / catMax)}%; background:#4ade80;"></span></div></div>
                <div class="flex items-center gap-2 text-[11px] mt-0.5"><span class="w-16 text-right eco-neg">-${ecoNum(r.consumed, 0)}</span><div class="eco-bar flex-1"><span style="width:${Math.round(Number(r.consumed) * 100 / catMax)}%; background:#f87171;"></span></div></div>
            </div>`).join('') : '<p class="text-sm" style="color: var(--text-secondary);">Sin datos en el periodo.</p>';

        // Por motivo
        document.getElementById('ecoReasonBody').innerHTML = (d.by_reason || []).map(r => `<tr class="eco-row"><td><div class="font-medium">${ecoEsc(r.label)}</div><div class="text-[10px] font-mono" style="color: var(--text-secondary);">${ecoEsc(r.reason || '')}</div></td><td>${ecoChip(r.category, r.category_label)}</td><td class="text-right eco-pos">${Number(r.emitted) ? '+' + ecoNum(r.emitted) : '—'}</td><td class="text-right eco-neg">${Number(r.consumed) ? '-' + ecoNum(r.consumed) : '—'}</td><td class="text-right">${ecoInt(r.tx_count)}</td></tr>`).join('') || '<tr><td colspan="5" class="text-center py-6" style="color: var(--text-secondary);">Sin movimientos en el periodo.</td></tr>';

        ecoRenderDistribution(d.distribution);
    } catch (e) { ecoToast(e.message, false); }
}

function ecoFocusValue() { const v = parseFloat(document.getElementById('ecoFocusInput').value); return isNaN(v) || v < 0 ? 10 : v; }
function ecoSetFocus(v) { document.getElementById('ecoFocusInput').value = v; ecoLoadDistribution(); }

async function ecoLoadDistribution() {
    try { const j = await ecoGet({ action: 'distribution', thresholds: document.getElementById('ecoThresholds').value, focus: ecoFocusValue() }); ecoRenderDistribution(j.data); }
    catch (e) { ecoToast(e.message, false); }
}

function ecoRenderDistribution(dist) {
    if (!dist) return;
    const f = dist.focus;
    if (f) {
        document.getElementById('ecoFocusUsers').textContent = ecoInt(f.users);
        document.getElementById('ecoFocusValue').textContent = ecoNum(f.threshold, 0);
        document.getElementById('ecoFocusSub').textContent = `${f.pct_users}% de los ${ecoInt(dist.users_total)} registrados · ${f.pct_holders}% de los holders · concentran ${ecoNum(f.coins_held, 0)} Coins (${f.pct_coins}% de la circulación)`;
    }
    document.getElementById('ecoDistBody').innerHTML = dist.thresholds.map(t => `<tr class="eco-row">
        <td class="font-bold">${ecoNum(t.threshold, 0)} <span class="text-[10px] font-normal" style="color: var(--text-secondary);">Coins</span></td>
        <td class="text-right font-semibold">${ecoInt(t.users)}</td>
        <td class="text-right"><div class="flex items-center justify-end gap-2"><div class="eco-bar w-16"><span style="width:${t.pct_holders}%; background: var(--accent);"></span></div><span class="w-12 text-right">${t.pct_holders}%</span></div></td>
        <td class="text-right" style="color: var(--text-secondary);">${t.pct_users}%</td>
        <td class="text-right">${ecoNum(t.coins_held, 0)} <span class="text-[10px]" style="color: var(--text-secondary);">(${t.pct_coins}%)</span></td>
        <td class="text-right eco-pos">${ecoNum(t.fee_pot, 0)}</td>
    </tr>`).join('');
    const bands = dist.bands || [];
    const bandMax = Math.max(1, ...bands.map(b => b.users), dist.zero_balance_users);
    const bandHtml = [{ label: '0 Coins', users: dist.zero_balance_users, zero: true }].concat(bands.map(b => ({ label: b.to === null ? `≥ ${ecoNum(b.from, 0)}` : (b.from === 0 ? `0 – ${ecoNum(b.to, 0)}` : `${ecoNum(b.from, 0)} – ${ecoNum(b.to, 0)}`), users: b.users })))
        .map(b => `<div class="flex items-center gap-2 text-xs"><span class="w-24 text-right font-mono" style="color: var(--text-secondary);">${b.label}</span><div class="eco-bar flex-1"><span style="width:${Math.round(b.users * 100 / bandMax)}%; background:${b.zero ? '#64748b' : 'var(--accent)'};"></span></div><span class="w-14 text-right font-semibold">${ecoInt(b.users)}</span></div>`).join('');
    document.getElementById('ecoBands').innerHTML = `<p class="text-[11px] font-semibold uppercase tracking-wide mb-2" style="color: var(--text-secondary);">Tramos exclusivos de saldo (usuarios)</p><div class="space-y-1.5">${bandHtml}</div>`;
}

document.getElementById('ecoFrom').value = ecoDaysAgo(29);
document.getElementById('ecoTo').value = ecoToday();
ecoLoadSummary();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

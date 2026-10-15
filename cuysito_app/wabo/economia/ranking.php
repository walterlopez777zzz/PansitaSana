<?php
// /wabo/economia/ranking.php — Holders con más saldo y ranking por actividad en un periodo (uso interno).
require_once __DIR__ . '/../core/admin_header.php';
$ecoCurrent = 'ranking';
$ecoSubtitle = 'Quién acumula más Coins y quién más gana o gasta en el periodo. Vista interna previa a un posible ranking público.';
require_once __DIR__ . '/economia_ui.php';
?>

<div class="max-w-[1300px] mx-auto">

<div class="card rounded-xl p-4 mb-4 eco-fade eco-d1">
    <div class="flex flex-col lg:flex-row lg:items-end gap-3">
        <div class="flex-grow">
            <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Ordenar por</label>
            <div id="rkSorts" class="flex flex-wrap gap-1.5">
                <button data-sort="balance" onclick="rkSort('balance')" class="eco-tab is-active"><i class="fas fa-piggy-bank"></i>Saldo actual (holders)</button>
                <button data-sort="earned" onclick="rkSort('earned')" class="eco-tab"><i class="fas fa-arrow-trend-up"></i>Más ganados en el periodo</button>
                <button data-sort="spent" onclick="rkSort('spent')" class="eco-tab"><i class="fas fa-arrow-trend-down"></i>Más gastados en el periodo</button>
                <button data-sort="net" onclick="rkSort('net')" class="eco-tab"><i class="fas fa-scale-balanced"></i>Mejor neto</button>
                <button data-sort="tx" onclick="rkSort('tx')" class="eco-tab"><i class="fas fa-bolt"></i>Más activos</button>
            </div>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <div>
                <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Mes</label>
                <input type="month" id="rkMonth" class="eco-input py-2 px-2 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);" onchange="rkUseMonth()">
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Desde</label>
                <input type="date" id="rkFrom" class="eco-input py-2 px-2 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);">
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Hasta</label>
                <input type="date" id="rkTo" class="eco-input py-2 px-2 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);">
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Saldo mín.</label>
                <input type="number" id="rkMin" min="0" step="1" value="0" class="eco-input py-2 px-2 rounded-lg border bg-transparent text-sm w-24" style="border-color: var(--border-color);">
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Top</label>
                <select id="rkLimit" class="eco-input py-2 px-2 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);"><option>25</option><option selected>50</option><option>100</option><option>200</option></select>
            </div>
            <button onclick="rkLoad()" class="eco-btn px-4 py-2 rounded-lg font-semibold text-sm text-white" style="background: var(--accent);"><i class="fas fa-sync-alt mr-1"></i>Actualizar</button>
        </div>
    </div>
</div>

<div id="rkPodium" class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4"></div>

<div class="card rounded-xl overflow-hidden eco-fade eco-d2">
    <div class="px-4 py-3 border-b flex flex-wrap items-center justify-between gap-2" style="border-color: var(--border-color);">
        <span id="rkTitle" class="font-bold text-sm uppercase tracking-wide" style="color: var(--text-secondary);">Ranking</span>
        <span id="rkPeriodLabel" class="text-[11px]" style="color: var(--text-secondary);"></span>
    </div>
    <div class="overflow-x-auto">
        <table class="eco-table w-full">
            <thead><tr><th class="text-center">#</th><th>Usuario</th><th class="text-right">Saldo actual</th><th class="text-right">Ganados</th><th class="text-right">Gastados</th><th class="text-right">Neto</th><th class="text-right">Manual</th><th class="text-right">Mov.</th><th>Último mov.</th><th class="text-center">Ver</th></tr></thead>
            <tbody id="rkBody"><tr><td colspan="10"><div class="eco-skel h-6 my-2"></div></td></tr></tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t text-[11px] leading-relaxed" style="border-color: var(--border-color); color: var(--text-secondary);">
        <b>Ganados / Gastados / Neto / Mov.</b> se calculan solo dentro del periodo elegido; <b>Saldo actual</b> es el saldo real de hoy. <b>Manual</b> = Coins recibidos por ajuste de administrador en el periodo (útil para separar mérito orgánico de regalos).
    </div>
</div>

</div>

<script>
let rkSortVal = 'balance';
const rkTitles = { balance: 'Top holders por saldo actual', earned: 'Más Coins ganados en el periodo', spent: 'Más Coins gastados en el periodo', net: 'Mejor balance neto del periodo', tx: 'Usuarios más activos (movimientos)' };
function rkSort(s) { rkSortVal = s; document.querySelectorAll('#rkSorts .eco-tab').forEach(b => b.classList.toggle('is-active', b.dataset.sort === s)); rkLoad(); }
function rkUseMonth() {
    const m = document.getElementById('rkMonth').value; if (!m) return;
    const [y, mo] = m.split('-').map(Number);
    const last = new Date(y, mo, 0).getDate();
    document.getElementById('rkFrom').value = `${m}-01`; document.getElementById('rkTo').value = `${m}-${String(last).padStart(2, '0')}`;
    rkLoad();
}
async function rkLoad() {
    const body = document.getElementById('rkBody');
    body.innerHTML = '<tr><td colspan="10"><div class="eco-skel h-6 my-2"></div></td></tr>';
    try {
        const j = await ecoGet({ action: 'ranking', sort: rkSortVal, from: document.getElementById('rkFrom').value, to: document.getElementById('rkTo').value, limit: document.getElementById('rkLimit').value, min_balance: document.getElementById('rkMin').value || 0 });
        document.getElementById('rkTitle').textContent = rkTitles[rkSortVal] || 'Ranking';
        document.getElementById('rkPeriodLabel').textContent = `Periodo: ${j.period.from.slice(0, 10)} → ${document.getElementById('rkTo').value}`;
        const rows = j.data;
        const medal = ['🥇', '🥈', '🥉'];
        const metric = (r) => ({ balance: r.balance, earned: r.earned, spent: r.spent, net: r.net, tx: r.tx_count })[rkSortVal];
        document.getElementById('rkPodium').innerHTML = rows.slice(0, 3).map((r, i) => `<div class="eco-kpi card rounded-xl p-4 eco-fade flex items-center gap-3"><div class="text-3xl">${medal[i]}</div><div class="min-w-0 flex-grow">${ecoUserCell(r)}</div><div class="text-right"><div class="text-[10px] uppercase" style="color: var(--text-secondary);">${rkSortVal === 'tx' ? 'Movimientos' : 'Coins'}</div><div class="font-mono font-bold text-lg">${rkSortVal === 'tx' ? ecoInt(metric(r)) : ecoNum(metric(r))}</div></div></div>`).join('');
        body.innerHTML = rows.length ? rows.map(r => `<tr class="eco-row">
            <td class="text-center font-bold" style="color: var(--text-secondary);">${r.position}</td>
            <td>${ecoUserCell(r)}${Number(r.is_verified) ? '<span class="eco-chip eco-chip-videos ml-1" title="Verificado"><i class="fas fa-check"></i></span>' : ''}${r.account_status && r.account_status !== 'active' ? `<span class="eco-chip eco-chip-manual ml-1">${ecoEsc(r.account_status)}</span>` : ''}</td>
            <td class="text-right font-mono font-bold">${ecoNum(r.balance)}</td>
            <td class="text-right font-mono eco-pos">${Number(r.earned) ? '+' + ecoNum(r.earned) : '—'}</td>
            <td class="text-right font-mono eco-neg">${Number(r.spent) ? '-' + ecoNum(r.spent) : '—'}</td>
            <td class="text-right font-mono font-semibold ${Number(r.net) >= 0 ? 'eco-pos' : 'eco-neg'}">${Number(r.net) ? ecoSigned(r.net) : '—'}</td>
            <td class="text-right font-mono ${Number(r.manual_in) ? 'eco-chip eco-chip-manual' : ''}" style="color: var(--text-secondary);">${Number(r.manual_in) ? '+' + ecoNum(r.manual_in) : '—'}</td>
            <td class="text-right">${ecoInt(r.tx_count)}</td>
            <td class="text-xs whitespace-nowrap" style="color: var(--text-secondary);">${ecoDate(r.last_tx)}</td>
            <td class="text-center"><a href="economia/movimientos.php?user_id=${Number(r.id)}" class="eco-btn text-xs px-2 py-1 rounded-md border inline-block" style="border-color: var(--border-color); color: var(--text-secondary);" title="Ver movimientos"><i class="fas fa-book"></i></a></td>
        </tr>`).join('') : '<tr><td colspan="10" class="text-center py-10" style="color: var(--text-secondary);">Nadie cumple el filtro.</td></tr>';
    } catch (e) { body.innerHTML = `<tr><td colspan="10" class="text-center py-6 text-red-400">${ecoEsc(e.message)}</td></tr>`; }
}
document.getElementById('rkFrom').value = ecoMonthStart();
document.getElementById('rkTo').value = ecoToday();
document.getElementById('rkMonth').value = ecoToday().slice(0, 7);
rkLoad();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

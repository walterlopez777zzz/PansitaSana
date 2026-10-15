<?php
// /wabo/economia/movimientos.php — Libro mayor de coin_transactions + ajuste manual con motivo obligatorio.
require_once __DIR__ . '/../core/admin_header.php';
$ecoCurrent = 'movimientos';
$ecoSubtitle = 'Libro mayor de Coins: cada entrada y salida, con su origen (orgánico vs. ajuste manual).';
require_once __DIR__ . '/economia_ui.php';
?>

<div class="max-w-[1500px] mx-auto">

<!-- TOTALES DEL FILTRO -->
<div id="ecoMovTotals" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <?php for ($i = 0; $i < 4; $i++) echo '<div class="eco-skel h-20"></div>'; ?>
</div>

<!-- FILTROS -->
<div class="card rounded-xl p-4 mb-4 eco-fade eco-d1">
    <div class="flex flex-col lg:flex-row gap-3">
        <div class="relative flex-grow">
            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
            <input type="text" id="mvQ" placeholder="Usuario, nickname, código, email o ID…" class="eco-input w-full pl-10 pr-4 py-2.5 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);">
        </div>
        <select id="mvOrigin" class="eco-input py-2.5 px-3 rounded-lg border bg-transparent text-sm min-w-[170px]" style="border-color: var(--border-color);">
            <option value="all">Orgánico + manual</option>
            <option value="organic">Solo orgánico (sistema)</option>
            <option value="manual">Solo ajustes manuales</option>
        </select>
        <select id="mvDirection" class="eco-input py-2.5 px-3 rounded-lg border bg-transparent text-sm min-w-[140px]" style="border-color: var(--border-color);">
            <option value="all">Entradas y salidas</option>
            <option value="in">Solo entradas (+)</option>
            <option value="out">Solo salidas (−)</option>
        </select>
        <button onclick="mvLoad(1)" class="eco-btn px-5 py-2.5 rounded-lg font-semibold text-sm text-white" style="background: var(--accent);"><i class="fas fa-filter mr-1.5"></i>Filtrar</button>
        <button onclick="mvOpenAdjust()" class="eco-btn px-4 py-2.5 rounded-lg font-semibold text-sm border" style="border-color: var(--accent); color: var(--accent);"><i class="fas fa-plus-minus mr-1.5"></i>Ajuste manual</button>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mt-3 pt-3 border-t" style="border-color: var(--border-color);">
        <div>
            <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Desde</label>
            <input type="date" id="mvFrom" class="eco-input w-full py-2 px-2 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);">
        </div>
        <div>
            <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Hasta</label>
            <input type="date" id="mvTo" class="eco-input w-full py-2 px-2 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);">
        </div>
        <div>
            <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Origen / módulo</label>
            <select id="mvCategory" class="eco-input w-full py-2 px-2 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);">
                <option value="">Todos</option>
                <option value="tienda">Tienda / Pedidos</option>
                <option value="sorteos">Sorteos Flash</option>
                <option value="giro">Giro Diario</option>
                <option value="videos">Vídeos de Comunidad</option>
                <option value="manual">Ajuste manual</option>
                <option value="otros">Otros</option>
            </select>
        </div>
        <div class="lg:col-span-2">
            <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Motivo concreto</label>
            <select id="mvReason" class="eco-input w-full py-2 px-2 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);"><option value="">Todos los motivos</option></select>
        </div>
        <div class="flex items-end gap-2">
            <select id="mvPerPage" class="eco-input w-full py-2 px-2 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);">
                <option value="50">50 / pág.</option><option value="100">100 / pág.</option><option value="200">200 / pág.</option>
            </select>
            <button onclick="mvReset()" class="eco-btn text-xs px-3 py-2 rounded-lg border whitespace-nowrap" style="border-color: var(--border-color); color: var(--text-secondary);"><i class="fas fa-eraser mr-1"></i>Limpiar</button>
        </div>
    </div>
</div>

<!-- TABLA -->
<div class="card rounded-xl overflow-hidden eco-fade eco-d2">
    <div class="overflow-x-auto">
        <table class="eco-table w-full">
            <thead><tr><th>Fecha</th><th>Usuario</th><th>Motivo</th><th>Origen</th><th class="text-right">Importe</th><th>Referencia</th><th class="text-center">Acción</th></tr></thead>
            <tbody id="mvBody"><tr><td colspan="7"><div class="eco-skel h-6 my-2"></div></td></tr></tbody>
        </table>
    </div>
    <div id="mvPagination" class="px-4 py-3 border-t flex flex-col sm:flex-row justify-between items-center gap-2 text-sm" style="border-color: var(--border-color);"></div>
</div>

</div>

<!-- MODAL AJUSTE MANUAL -->
<div id="mvAdjustModal" class="fixed inset-0 z-[90] hidden" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="mvCloseAdjust()"></div>
    <div class="relative flex items-start justify-center min-h-screen p-4">
        <div class="card rounded-2xl w-full max-w-lg mt-10 shadow-2xl eco-modal overflow-hidden">
            <div class="px-5 py-4 border-b flex items-center justify-between" style="border-color: var(--border-color);">
                <h3 class="font-bold text-lg"><i class="fas fa-plus-minus mr-2" style="color: var(--accent);"></i>Ajuste manual de Coins</h3>
                <button onclick="mvCloseAdjust()" class="eco-btn w-8 h-8 rounded-lg" style="color: var(--text-secondary);"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-5 space-y-4">
                <div>
                    <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Usuario</label>
                    <div id="mvAdjSelected" class="hidden items-center justify-between gap-2 p-2 rounded-lg border" style="border-color: var(--border-color);"></div>
                    <div id="mvAdjSearchWrap">
                        <input type="text" id="mvAdjSearch" placeholder="Buscar por nombre, código, email o ID…" autocomplete="off" class="eco-input w-full py-2.5 px-3 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);">
                        <div id="mvAdjResults" class="mt-1 rounded-lg border divide-y max-h-56 overflow-y-auto hidden" style="border-color: var(--border-color);"></div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Operación</label>
                        <div class="flex rounded-lg border overflow-hidden" style="border-color: var(--border-color);">
                            <button type="button" id="mvAdjPlus" onclick="mvAdjSign(1)" class="flex-1 py-2 text-sm font-bold eco-btn bg-emerald-600 text-white"><i class="fas fa-plus mr-1"></i>Añadir</button>
                            <button type="button" id="mvAdjMinus" onclick="mvAdjSign(-1)" class="flex-1 py-2 text-sm font-bold eco-btn" style="color: var(--text-secondary);"><i class="fas fa-minus mr-1"></i>Quitar</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Cantidad de Coins</label>
                        <input type="number" id="mvAdjAmount" min="0.01" step="0.01" placeholder="0.00" class="eco-input w-full py-2 px-3 rounded-lg border bg-transparent text-sm font-mono" style="border-color: var(--border-color);">
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase mb-1" style="color: var(--text-secondary);">Motivo <span class="text-red-400">*</span> <span class="font-normal normal-case">(obligatorio, queda en el historial y en la auditoría)</span></label>
                    <div class="flex flex-wrap gap-1.5 mb-2">
                        <?php foreach (['Compensación por incidencia', 'Corrección de error', 'Premio manual / evento', 'Bonificación de fidelidad', 'Sanción / retiro por abuso', 'Reversión de ajuste anterior'] as $chip): ?>
                        <button type="button" onclick="mvAdjChip(this)" class="eco-btn text-[11px] px-2.5 py-1 rounded-full border" style="border-color: var(--border-color); color: var(--text-secondary);"><?php echo $chip; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <textarea id="mvAdjNote" rows="2" maxlength="255" placeholder="Ej.: Compensación por pedido #1234 duplicado" class="eco-input w-full py-2 px-3 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);"></textarea>
                </div>
                <div id="mvAdjPreview" class="text-xs rounded-lg p-3" style="background: rgba(128,128,128,.08); color: var(--text-secondary);">Selecciona un usuario y una cantidad para ver el saldo resultante.</div>
            </div>
            <div class="px-5 py-4 border-t flex justify-end gap-2" style="border-color: var(--border-color);">
                <button onclick="mvCloseAdjust()" class="eco-btn px-4 py-2 rounded-lg border text-sm" style="border-color: var(--border-color);">Cancelar</button>
                <button id="mvAdjSubmit" onclick="mvSubmitAdjust()" class="eco-btn px-5 py-2 rounded-lg text-sm font-bold text-white" style="background: var(--accent);"><i class="fas fa-check mr-1"></i>Aplicar ajuste</button>
            </div>
        </div>
    </div>
</div>

<script>
let mvPage = 1, mvUserId = 0;

function mvReset() {
    ['mvQ', 'mvReason', 'mvCategory'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('mvOrigin').value = 'all'; document.getElementById('mvDirection').value = 'all';
    document.getElementById('mvFrom').value = ecoDaysAgo(29); document.getElementById('mvTo').value = ecoToday();
    mvUserId = 0; mvLoad(1);
}

async function mvLoadReasons() {
    try {
        const j = await ecoGet({ action: 'reasons' });
        const sel = document.getElementById('mvReason');
        j.data.forEach(r => { const o = document.createElement('option'); o.value = r.reason || ''; o.textContent = `${r.label} (${ecoInt(r.c)})`; sel.appendChild(o); });
    } catch (e) { /* el filtro de motivo es opcional */ }
}

async function mvLoad(page = 1) {
    mvPage = page;
    const params = {
        action: 'movements', page,
        per_page: document.getElementById('mvPerPage').value,
        from: document.getElementById('mvFrom').value, to: document.getElementById('mvTo').value,
        q: document.getElementById('mvQ').value.trim(),
        origin: document.getElementById('mvOrigin').value, direction: document.getElementById('mvDirection').value,
        category: document.getElementById('mvCategory').value, reason: document.getElementById('mvReason').value,
    };
    if (mvUserId > 0) params.user_id = mvUserId;
    const body = document.getElementById('mvBody');
    body.innerHTML = '<tr><td colspan="7"><div class="eco-skel h-6 my-2"></div></td></tr>';
    try {
        const j = await ecoGet(params);
        const t = j.totals;
        document.getElementById('ecoMovTotals').innerHTML = [
            ['Movimientos', ecoInt(t.count), 'fa-list', ''], ['Entradas (+)', ecoNum(t.in), 'fa-arrow-down', 'eco-pos'],
            ['Salidas (−)', ecoNum(t.out), 'fa-arrow-up', 'eco-neg'], ['Neto del filtro', ecoSigned(t.net), 'fa-scale-balanced', t.net >= 0 ? 'eco-pos' : 'eco-neg'],
        ].map(([l, v, i, c]) => `<div class="eco-kpi card rounded-xl p-4 eco-fade"><div class="flex items-start justify-between"><div><p class="text-[11px] font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">${l}</p><p class="text-xl font-bold mt-1 ${c}">${v}</p></div><div class="p-2 rounded-lg" style="background: rgba(128,128,128,.1);"><i class="fas ${i} ${c}"></i></div></div></div>`).join('');

        if (!j.data.length) { body.innerHTML = '<tr><td colspan="7" class="text-center py-10" style="color: var(--text-secondary);"><i class="fas fa-inbox text-2xl mb-2 block"></i>Sin movimientos con este filtro.</td></tr>'; }
        else body.innerHTML = j.data.map(r => {
            const amt = Number(r.amount);
            const motivo = r.is_manual ? (r.note || (r.reason && r.reason !== 'admin_adjust' ? r.reason : 'Ajuste manual')) : r.reason_label;
            const ref = r.reference_type === 'admin_id' ? `<span class="text-[11px]"><i class="fas fa-user-shield mr-1"></i>${ecoEsc(r.admin_name || ('admin #' + (r.reference_id || '?')))}</span>`
                : (r.reference_type ? `<span class="text-[11px] font-mono" style="color: var(--text-secondary);">${ecoEsc(r.reference_type)} ${ecoEsc(r.reference_id || '')}</span>` : '<span class="text-[11px]" style="color: var(--text-secondary);">—</span>');
            return `<tr class="eco-row">
                <td class="whitespace-nowrap text-xs" style="color: var(--text-secondary);">${ecoDate(r.created_at)}</td>
                <td>${ecoUserCell(r)}</td>
                <td><div class="font-medium">${ecoEsc(motivo)}</div>${(!r.is_manual && r.reason) ? `<div class="text-[10px] font-mono" style="color: var(--text-secondary);">${ecoEsc(r.reason)}</div>` : ''}</td>
                <td>${ecoChip(r.category, r.category_label)}</td>
                <td class="text-right font-mono font-bold ${amt >= 0 ? 'eco-pos' : 'eco-neg'}">${ecoSigned(amt)}</td>
                <td>${ref}</td>
                <td class="text-center"><button onclick="mvFilterUser(${Number(r.user_id)}, '${ecoEsc(r.game_username || '')}')" class="eco-btn text-xs px-2 py-1 rounded-md border" style="border-color: var(--border-color); color: var(--text-secondary);" title="Ver solo este usuario"><i class="fas fa-user-magnifying-glass"></i></button></td>
            </tr>`;
        }).join('');

        const p = j.pagination;
        document.getElementById('mvPagination').innerHTML = `<span style="color: var(--text-secondary);">Página ${p.page} de ${Math.max(1, p.pages)} · ${ecoInt(p.total)} movimientos${mvUserId ? ` · <b>filtrando usuario #${mvUserId}</b> <button onclick="mvUserId=0;mvLoad(1)" class="underline">quitar</button>` : ''}</span>
            <div class="flex gap-1">
                <button ${p.page <= 1 ? 'disabled' : ''} onclick="mvLoad(${p.page - 1})" class="eco-btn px-3 py-1 rounded-md border text-xs disabled:opacity-40" style="border-color: var(--border-color);"><i class="fas fa-chevron-left"></i></button>
                <button ${p.page >= p.pages ? 'disabled' : ''} onclick="mvLoad(${p.page + 1})" class="eco-btn px-3 py-1 rounded-md border text-xs disabled:opacity-40" style="border-color: var(--border-color);"><i class="fas fa-chevron-right"></i></button>
            </div>`;
    } catch (e) { body.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-red-400">${ecoEsc(e.message)}</td></tr>`; }
}

function mvFilterUser(id, name) { mvUserId = id; document.getElementById('mvQ').value = name || ''; mvLoad(1); }

// ---------- Ajuste manual ----------
let mvAdjUser = null, mvAdjSignVal = 1, mvAdjTimer = null;
function mvOpenAdjust(preUser) {
    mvAdjUser = null; mvAdjSignVal = 1;
    document.getElementById('mvAdjAmount').value = ''; document.getElementById('mvAdjNote').value = '';
    document.getElementById('mvAdjSearch').value = ''; document.getElementById('mvAdjResults').classList.add('hidden');
    mvAdjRenderSelected(); mvAdjSign(1); mvAdjPreview();
    document.getElementById('mvAdjustModal').classList.remove('hidden');
    if (preUser) mvAdjPick(preUser); else setTimeout(() => document.getElementById('mvAdjSearch').focus(), 50);
}
function mvCloseAdjust() { document.getElementById('mvAdjustModal').classList.add('hidden'); }
function mvAdjSign(s) {
    mvAdjSignVal = s;
    const plus = document.getElementById('mvAdjPlus'), minus = document.getElementById('mvAdjMinus');
    plus.className = 'flex-1 py-2 text-sm font-bold eco-btn ' + (s > 0 ? 'bg-emerald-600 text-white' : '');
    minus.className = 'flex-1 py-2 text-sm font-bold eco-btn ' + (s < 0 ? 'bg-red-600 text-white' : '');
    plus.style.color = s > 0 ? '' : 'var(--text-secondary)'; minus.style.color = s < 0 ? '' : 'var(--text-secondary)';
    mvAdjPreview();
}
function mvAdjChip(btn) { const ta = document.getElementById('mvAdjNote'); ta.value = btn.textContent.trim() + (ta.value && !ta.value.startsWith(btn.textContent.trim()) ? ' — ' + ta.value : ''); ta.focus(); }
function mvAdjRenderSelected() {
    const box = document.getElementById('mvAdjSelected'), wrap = document.getElementById('mvAdjSearchWrap');
    if (!mvAdjUser) { box.classList.add('hidden'); box.classList.remove('flex'); wrap.classList.remove('hidden'); return; }
    box.innerHTML = `${ecoUserCell(mvAdjUser)}<div class="text-right"><div class="text-[10px] uppercase" style="color: var(--text-secondary);">Saldo actual</div><div class="font-mono font-bold">${ecoNum(mvAdjUser.balance)}</div></div><button onclick="mvAdjUser=null;mvAdjRenderSelected();mvAdjPreview();" class="eco-btn w-8 h-8 rounded-lg" style="color: var(--text-secondary);" title="Cambiar usuario"><i class="fas fa-times"></i></button>`;
    box.classList.remove('hidden'); box.classList.add('flex'); wrap.classList.add('hidden');
}
function mvAdjPick(u) { mvAdjUser = u; mvAdjRenderSelected(); mvAdjPreview(); document.getElementById('mvAdjAmount').focus(); }
function mvAdjPreview() {
    const el = document.getElementById('mvAdjPreview');
    const amt = Math.abs(Number(document.getElementById('mvAdjAmount').value || 0)) * mvAdjSignVal;
    if (!mvAdjUser || !amt) { el.innerHTML = 'Selecciona un usuario y una cantidad para ver el saldo resultante.'; return; }
    const result = Number(mvAdjUser.balance) + amt;
    el.innerHTML = `<b>${ecoEsc(mvAdjUser.game_username)}</b>: ${ecoNum(mvAdjUser.balance)} <span class="${amt > 0 ? 'eco-pos' : 'eco-neg'} font-bold">${ecoSigned(amt)}</span> → <b class="${result < 0 ? 'eco-neg' : ''}">${ecoNum(result)} Coins</b>${result < 0 ? ' · <span class="eco-neg">no se permite saldo negativo</span>' : ''}`;
}
document.getElementById('mvAdjAmount').addEventListener('input', mvAdjPreview);
document.getElementById('mvAdjSearch').addEventListener('input', function () {
    clearTimeout(mvAdjTimer);
    const q = this.value.trim(), res = document.getElementById('mvAdjResults');
    if (q.length < 2) { res.classList.add('hidden'); return; }
    mvAdjTimer = setTimeout(async () => {
        try {
            const j = await ecoGet({ action: 'user_search', q });
            window._mvAdjFound = j.data;
            res.innerHTML = j.data.length ? j.data.map((u, i) => `<button type="button" onclick="mvAdjPick(window._mvAdjFound[${i}])" class="w-full flex items-center justify-between gap-2 p-2 text-left hover:bg-black/10">${ecoUserCell(u)}<span class="font-mono text-sm">${ecoNum(u.balance)}</span></button>`).join('')
                : '<div class="p-3 text-sm text-center" style="color: var(--text-secondary);">Sin resultados.</div>';
            res.classList.remove('hidden');
        } catch (e) { res.innerHTML = `<div class="p-3 text-sm text-red-400">${ecoEsc(e.message)}</div>`; res.classList.remove('hidden'); }
    }, 280);
});
async function mvSubmitAdjust() {
    const amt = Math.abs(Number(document.getElementById('mvAdjAmount').value || 0)) * mvAdjSignVal;
    const note = document.getElementById('mvAdjNote').value.trim();
    if (!mvAdjUser) return ecoToast('Selecciona un usuario.', false);
    if (!amt) return ecoToast('Indica una cantidad distinta de 0.', false);
    if (note.length < 4) return ecoToast('El motivo es obligatorio (mínimo 4 caracteres).', false);
    if (!confirm(`¿Aplicar ${ecoSigned(amt)} Coins a ${mvAdjUser.game_username}?\nMotivo: ${note}`)) return;
    const btn = document.getElementById('mvAdjSubmit'); btn.disabled = true;
    try {
        const j = await ecoPost({ action: 'adjust', user_id: mvAdjUser.id, amount: amt, note });
        ecoToast(j.message, true); mvCloseAdjust(); mvLoad(1);
    } catch (e) { ecoToast(e.message, false); }
    finally { btn.disabled = false; }
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') mvCloseAdjust(); });
document.getElementById('mvQ').addEventListener('keydown', e => { if (e.key === 'Enter') { mvUserId = 0; mvLoad(1); } });

document.getElementById('mvFrom').value = ecoDaysAgo(29);
document.getElementById('mvTo').value = ecoToday();
(() => { const uid = parseInt(new URLSearchParams(location.search).get('user_id') || '0', 10); if (uid > 0) { mvUserId = uid; document.getElementById('mvFrom').value = ecoDaysAgo(365); } })();
mvLoadReasons();
mvLoad(1);
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

<?php
// /wabo/sorteos_flash/baneos_expulsados.php
// Vista dedicada: ranking de expulsados (raffle_strikes), baneos activos e historial (raffle_bans).
// Reutiliza las acciones existentes de api_sorteos.php: cc_strikes_overview, cc_bans_list, cc_user_search, cc_ban, cc_unban.
require_once __DIR__ . '/../core/admin_header.php';
?>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    @keyframes bxFadeUp { from { opacity:0; transform:translateY(10px);} to { opacity:1; transform:translateY(0);} }
    @keyframes bxShimmer { 0% { background-position:-200% 0;} 100% { background-position:200% 0;} }
    .bx-fade { animation: bxFadeUp .35s ease-out both; } .bx-d1 { animation-delay:.05s } .bx-d2 { animation-delay:.1s } .bx-d3 { animation-delay:.15s }
    .bx-skel { background: linear-gradient(90deg, rgba(128,128,128,.1) 25%, rgba(128,128,128,.2) 50%, rgba(128,128,128,.1) 75%); background-size:200% 100%; animation: bxShimmer 1.4s infinite; border-radius:8px; }
    .bx-kpi { transition: transform .2s, box-shadow .2s; } .bx-kpi:hover { transform: translateY(-2px); box-shadow: 0 8px 25px -6px rgba(0,0,0,.2); }
    .bx-tab { display:inline-flex; align-items:center; gap:.45rem; padding:.5rem .9rem; border-radius:.6rem; font-size:.85rem; font-weight:600; color: var(--text-secondary); border:1px solid transparent; transition: all .2s; white-space:nowrap; cursor:pointer; background:transparent; }
    .bx-tab:hover { background: rgba(128,128,128,.08); color: var(--text-primary); }
    .bx-tab.is-active { background: linear-gradient(135deg,#ef4444,#f97316); color:#fff !important; box-shadow: 0 4px 14px -4px rgba(239,68,68,.5); }
    .bx-tab .bx-count { background: rgba(255,255,255,.18); padding:0 .4rem; border-radius:999px; font-size:.7rem; }
    .bx-tab:not(.is-active) .bx-count { background: rgba(128,128,128,.15); }
    .bx-input { transition: border-color .2s, box-shadow .2s; } .bx-input:focus { border-color:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.18); outline:none; }
    .bx-btn { transition: all .2s; cursor:pointer; } .bx-btn:active { transform: scale(.97); } .bx-btn:disabled { opacity:.5; cursor:not-allowed; }
    .bx-tag { display:inline-flex; align-items:center; gap:.3rem; padding:.15rem .55rem; border-radius:999px; font-size:.66rem; font-weight:800; letter-spacing:.03em; text-transform:uppercase; white-space:nowrap; }
    .bx-tag--perm { background: rgba(239,68,68,.16); color:#f87171; } .bx-tag--date { background: rgba(245,158,11,.16); color:#fbbf24; }
    .bx-tag--n { background: rgba(99,102,241,.18); color:#a5b4fc; } .bx-tag--lifted { background: rgba(34,197,94,.16); color:#4ade80; }
    .bx-tag--free { background: rgba(148,163,184,.16); color:#94a3b8; } .bx-tag--ver { background: rgba(16,185,129,.16); color:#34d399; }
    .bx-strikes { display:inline-flex; align-items:center; justify-content:center; min-width:2.4rem; height:2.4rem; border-radius:.7rem; font-weight:900; font-size:1.05rem; }
    .bx-s1 { background: rgba(148,163,184,.15); color:#cbd5e1; } .bx-s2 { background: rgba(245,158,11,.18); color:#fbbf24; } .bx-s3 { background: rgba(239,68,68,.2); color:#f87171; box-shadow: 0 0 0 2px rgba(239,68,68,.25); }
    .bx-table th { font-size:.68rem; letter-spacing:.06em; text-transform:uppercase; font-weight:700; color: var(--text-secondary); padding:.7rem .9rem; text-align:left; white-space:nowrap; }
    .bx-table td { padding:.6rem .9rem; font-size:.84rem; vertical-align:middle; }
    .bx-table tbody tr { border-top:1px solid var(--border-color); animation: bxFadeUp .25s ease-out both; } .bx-table tbody tr:hover { background: rgba(128,128,128,.05); }
    .bx-avatar { width:34px; height:34px; border-radius:50%; object-fit:cover; background:#222; flex-shrink:0; }
    .bx-ban { background:#ef4444; color:#fff; border:none; border-radius:.5rem; padding:.4rem .75rem; font-weight:800; font-size:.75rem; display:inline-flex; align-items:center; gap:.35rem; }
    .bx-unban { background:#22c55e; color:#06210f; border:none; border-radius:.5rem; padding:.4rem .75rem; font-weight:800; font-size:.75rem; display:inline-flex; align-items:center; gap:.35rem; }
    .bx-ghost { background:transparent; border:1px solid var(--border-color); color: var(--text-secondary); border-radius:.5rem; padding:.4rem .6rem; font-size:.75rem; }
    [data-theme="light"] .bx-tag--perm { color:#b91c1c; } [data-theme="light"] .bx-tag--date { color:#b45309; } [data-theme="light"] .bx-tag--n { color:#4338ca; } [data-theme="light"] .bx-tag--lifted { color:#15803d; }
    [data-theme="light"] .bx-s1 { color:#334155; } [data-theme="light"] .bx-s2 { color:#b45309; } [data-theme="light"] .bx-s3 { color:#b91c1c; }
</style>

<div class="max-w-[1400px] mx-auto">

<div class="flex flex-col lg:flex-row lg:items-end justify-between gap-3 mb-4 bx-fade">
    <div>
        <h2 class="text-2xl font-bold flex items-center gap-2"><i class="fas fa-gavel text-red-400"></i> Baneos y Expulsados</h2>
        <p class="text-sm mt-0.5" style="color: var(--text-secondary);">Quién ha sido expulsado de Sorteos Flash, cuántas veces, y baneo/desbaneo directo. Los baneos son exclusivos de sorteos (no tocan la cuenta).</p>
    </div>
    <div class="flex items-center gap-2">
        <button onclick="bxBanSearch()" class="bx-btn bx-ban py-2 px-4"><i class="fas fa-user-slash"></i> Banear a un usuario…</button>
        <button onclick="bxReload()" class="bx-btn bx-ghost py-2 px-3" title="Actualizar"><i class="fas fa-sync-alt"></i></button>
    </div>
</div>

<!-- KPIs -->
<div id="bxKpis" class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
    <?php for ($i = 0; $i < 5; $i++) echo '<div class="bx-skel h-20"></div>'; ?>
</div>

<!-- TABS -->
<div class="card rounded-xl overflow-hidden bx-fade bx-d1">
    <div class="px-4 pt-3 pb-3 border-b flex flex-col lg:flex-row lg:items-center justify-between gap-3" style="border-color: var(--border-color);">
        <div class="flex gap-1 overflow-x-auto">
            <button class="bx-tab is-active" data-tab="ranking" onclick="bxTab('ranking')"><i class="fas fa-ranking-star"></i>Ranking de expulsados <span class="bx-count" id="bxCountRanking">0</span></button>
            <button class="bx-tab" data-tab="active" onclick="bxTab('active')"><i class="fas fa-lock"></i>Baneos activos <span class="bx-count" id="bxCountActive">0</span></button>
            <button class="bx-tab" data-tab="strikes" onclick="bxTab('strikes')"><i class="fas fa-clock-rotate-left"></i>Historial de expulsiones <span class="bx-count" id="bxCountStrikes">0</span></button>
            <button class="bx-tab" data-tab="history" onclick="bxTab('history')"><i class="fas fa-box-archive"></i>Baneos pasados <span class="bx-count" id="bxCountHistory">0</span></button>
        </div>
        <div class="flex items-center gap-2">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-xs"></i>
                <input type="text" id="bxSearch" placeholder="Filtrar por nombre, código, teléfono…" class="bx-input pl-8 pr-3 py-2 rounded-lg border bg-transparent text-sm w-64" style="border-color: var(--border-color);">
            </div>
            <select id="bxMin" class="bx-input py-2 px-2 rounded-lg border bg-transparent text-sm" style="border-color: var(--border-color);" onchange="bxRender()" title="Mínimo de expulsiones (solo ranking)">
                <option value="1">≥ 1 expulsión</option><option value="2">≥ 2 (reincidentes)</option><option value="3">≥ 3</option><option value="5">≥ 5</option>
            </select>
        </div>
    </div>
    <div id="bxPanel" class="overflow-x-auto"><div class="p-4"><div class="bx-skel h-8 mb-2"></div><div class="bx-skel h-8 mb-2"></div><div class="bx-skel h-8"></div></div></div>
</div>

<p class="text-[11px] mt-3 leading-relaxed bx-fade bx-d2" style="color: var(--text-secondary);">
    <b>Expulsión</b> = el admin sacó al usuario de un sorteo concreto (queda un strike y se le devolvió la cuota). <b>Baneo</b> = bloqueo para TODOS los sorteos (por cuenta, IP y huella) hasta que se levante o expire. Expulsar sigue haciéndose desde la <a href="sorteos_flash/sala_mando.php" class="underline">Sala de Mando</a> de cada sorteo.
</p>

</div>

<script>
const BX_API = 'sorteos_flash/api_sorteos.php';
const BX_AVATAR = '/assets/avatars/';
const bxEsc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const bxJs = (s) => bxEsc(s).replace(/'/g, "\\'");
const bxDate = (s) => { if (!s) return '—'; const d = new Date(String(s).replace(' ', 'T') + (String(s).endsWith('Z') ? '' : 'Z')); return isNaN(d) ? s : d.toLocaleString('es-PE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }); };
const bxAvatar = (f) => `<img class="bx-avatar" src="${bxEsc(BX_AVATAR + (f || 'default.png'))}" alt="">`;
const bxUser = (u) => `<div class="flex items-center gap-2 min-w-0">${bxAvatar(u.avatar)}<div class="min-w-0"><div class="font-semibold truncate flex items-center gap-1.5">${bxEsc(u.name)}${Number(u.is_verified) ? '<span class="bx-tag bx-tag--ver" title="Verificado"><i class="fas fa-check"></i></span>' : ''}</div><div class="text-[11px] truncate" style="color: var(--text-secondary);">${u.game_username && u.game_username !== u.name ? bxEsc(u.game_username) + ' · ' : ''}${bxEsc(u.public_code || '')}${u.phone ? ' · ' + bxEsc(u.phone) : ''}</div></div></div>`;
const bxToast = (msg, ok = true) => Swal.fire({ toast: true, position: 'top-end', icon: ok ? 'success' : 'error', title: msg, showConfirmButton: false, timer: 3500 });

let bxData = { ranking: [], history: [], kpi: {} }, bxBans = { active: [], history: [] }, bxCurrent = 'ranking';

function bxBanTag(b) {
    if (!b) return '<span class="bx-tag bx-tag--free">Sin baneo</span>';
    if (b.ban_type === 'permanent') return '<span class="bx-tag bx-tag--perm"><i class="fas fa-infinity"></i> Permanente</span>';
    if (b.ban_type === 'until_date') return `<span class="bx-tag bx-tag--date"><i class="fas fa-calendar"></i> Hasta ${bxEsc(String(b.expires_at || '?').slice(0, 16))} UTC</span>`;
    return `<span class="bx-tag bx-tag--n"><i class="fas fa-ticket"></i> ${Number(b.raffles_remaining || 0)} sorteo(s)</span>`;
}

async function bxReload() {
    try {
        const [ov, bans] = await Promise.all([
            $.getJSON(BX_API, { action: 'cc_strikes_overview', limit: 500 }),
            $.getJSON(BX_API, { action: 'cc_bans_list' }),
        ]);
        if (ov.status !== 'success') throw new Error(ov.message || 'Error');
        if (bans.status !== 'success') throw new Error(bans.message || 'Error');
        bxData = ov; bxBans = bans;
        const k = ov.kpi;
        document.getElementById('bxKpis').innerHTML = [
            ['Usuarios expulsados', k.users_expelled, 'fa-user-xmark', ''], ['Expulsiones totales', k.strikes_total, 'fa-ban', ''],
            ['Últimos 30 días', k.strikes_30d, 'fa-calendar-day', ''], ['Reincidentes (≥2)', k.repeat_offenders, 'fa-triangle-exclamation', 'text-amber-400'],
            ['Baneos activos', k.bans_active, 'fa-lock', k.bans_active > 0 ? 'text-red-400' : ''],
        ].map(([l, v, i, c]) => `<div class="bx-kpi card rounded-xl p-4 bx-fade"><div class="flex items-start justify-between"><div><p class="text-[11px] font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">${l}</p><p class="text-2xl font-bold mt-1 ${c}">${Number(v || 0).toLocaleString('es-PE')}</p></div><div class="p-2 rounded-lg" style="background: rgba(128,128,128,.1);"><i class="fas ${i} ${c}"></i></div></div></div>`).join('');
        bxRender();
    } catch (e) { document.getElementById('bxPanel').innerHTML = `<div class="p-6 text-center text-red-400">${bxEsc(e.message || 'No se pudo cargar.')}</div>`; }
}

function bxTab(t) { bxCurrent = t; document.querySelectorAll('.bx-tab').forEach(b => b.classList.toggle('is-active', b.dataset.tab === t)); bxRender(); }

function bxMatch(u, q) { if (!q) return true; return [u.name, u.game_username, u.public_code, u.phone, u.reason, u.raffle_title].some(v => String(v || '').toLowerCase().includes(q)); }

function bxRender() {
    const q = document.getElementById('bxSearch').value.trim().toLowerCase();
    const min = Number(document.getElementById('bxMin').value || 1);
    const ranking = bxData.ranking.filter(u => u.strikes >= min && bxMatch(u, q));
    const strikes = bxData.history.filter(u => bxMatch(u, q));
    const active = bxBans.active.filter(u => bxMatch(u, q));
    const history = bxBans.history.filter(u => bxMatch(u, q));
    document.getElementById('bxCountRanking').textContent = ranking.length;
    document.getElementById('bxCountStrikes').textContent = strikes.length;
    document.getElementById('bxCountActive').textContent = active.length;
    document.getElementById('bxCountHistory').textContent = history.length;
    const panel = document.getElementById('bxPanel');
    const empty = (t) => `<div class="p-10 text-center" style="color: var(--text-secondary);"><i class="fas fa-inbox text-2xl mb-2 block opacity-50"></i>${t}</div>`;

    if (bxCurrent === 'ranking') {
        panel.innerHTML = ranking.length ? `<table class="bx-table w-full"><thead><tr><th class="text-center">Expulsiones</th><th>Usuario</th><th class="text-center">Sorteos afectados</th><th class="text-center">Participaciones</th><th>Última expulsión</th><th>Estado de baneo</th><th class="text-right">Acción</th></tr></thead><tbody>` +
            ranking.map(u => {
                const ban = u.active_ban_id ? { ban_type: u.active_ban_type, raffles_remaining: u.active_ban_remaining, expires_at: u.active_ban_expires } : null;
                const ratio = u.participations > 0 ? Math.round(u.strikes * 100 / u.participations) : 0;
                return `<tr>
                    <td class="text-center"><span class="bx-strikes ${u.strikes >= 3 ? 'bx-s3' : (u.strikes === 2 ? 'bx-s2' : 'bx-s1')}">${u.strikes}</span></td>
                    <td>${bxUser(u)}</td>
                    <td class="text-center">${u.raffles_hit}</td>
                    <td class="text-center">${u.participations} <span class="text-[10px]" style="color: var(--text-secondary);">(${ratio}% expulsado)</span></td>
                    <td class="text-xs whitespace-nowrap" style="color: var(--text-secondary);">${bxDate(u.last_strike)}</td>
                    <td>${bxBanTag(ban)}${u.bans_total > 0 ? `<div class="text-[10px] mt-0.5" style="color: var(--text-secondary);">${u.bans_total} baneo(s) en total</div>` : ''}</td>
                    <td class="text-right whitespace-nowrap">
                        <button class="bx-btn bx-ghost mr-1" onclick="bxUserStrikes(${u.user_id})" title="Ver sus expulsiones"><i class="fas fa-list"></i></button>
                        ${ban ? `<button class="bx-btn bx-unban" onclick="bxUnban(${u.active_ban_id}, '${bxJs(u.name)}')"><i class="fas fa-unlock"></i> Desbanear</button>`
                              : `<button class="bx-btn bx-ban" onclick="bxBan(${u.user_id}, '${bxJs(u.name)}')"><i class="fas fa-gavel"></i> Banear</button>`}
                    </td>
                </tr>`;
            }).join('') + '</tbody></table>' : empty('Nadie cumple el filtro. 🎉');
    }
    else if (bxCurrent === 'strikes') {
        panel.innerHTML = strikes.length ? `<table class="bx-table w-full"><thead><tr><th>Fecha</th><th>Usuario</th><th>Sorteo</th><th>Motivo</th><th>Por</th></tr></thead><tbody>` +
            strikes.map(s => `<tr><td class="text-xs whitespace-nowrap" style="color: var(--text-secondary);">${bxDate(s.created_at)}</td><td>${bxUser(s)}</td><td class="text-xs">${s.raffle_id ? `<a href="sorteos_flash/sala_mando.php?id=${s.raffle_id}" class="underline">#${s.raffle_id}</a> ${bxEsc(s.raffle_title || '')}` : '—'}</td><td>${bxEsc(s.reason || '—')}</td><td class="text-xs" style="color: var(--text-secondary);"><i class="fas fa-user-shield mr-1"></i>${bxEsc(s.created_by)}</td></tr>`).join('') + '</tbody></table>' : empty('Sin expulsiones registradas.');
    }
    else {
        const list = bxCurrent === 'active' ? active : history, isActive = bxCurrent === 'active';
        panel.innerHTML = list.length ? `<table class="bx-table w-full"><thead><tr><th>Usuario</th><th>Tipo</th><th>Motivo</th><th>IP / Huella</th><th>Creado</th>${isActive ? '<th class="text-right">Acción</th>' : '<th>Fin</th>'}</tr></thead><tbody>` +
            list.map(b => `<tr>
                <td>${bxUser(b)}</td>
                <td>${bxBanTag(b)}</td>
                <td class="text-sm">${bxEsc(b.reason || '—')}</td>
                <td class="text-[11px] font-mono" style="color: var(--text-secondary);">${bxEsc(b.ip || 's/IP')}<br>${b.fingerprint ? bxEsc(String(b.fingerprint).slice(0, 14)) + '…' : 's/huella'}</td>
                <td class="text-xs whitespace-nowrap" style="color: var(--text-secondary);">${bxDate(b.created_at)}<br><i class="fas fa-user-shield mr-1"></i>${bxEsc(b.created_by)}</td>
                ${isActive ? `<td class="text-right"><button class="bx-btn bx-unban" onclick="bxUnban(${b.id}, '${bxJs(b.name)}')"><i class="fas fa-unlock"></i> Desbanear</button></td>`
                           : `<td>${b.lifted_at ? '<span class="bx-tag bx-tag--lifted">Levantado</span><div class="text-[10px] mt-0.5" style="color: var(--text-secondary);">' + bxDate(b.lifted_at) + '</div>' : '<span class="bx-tag bx-tag--lifted">Expirado</span>'}</td>`}
            </tr>`).join('') + '</tbody></table>' : empty(isActive ? 'No hay baneos activos. 🎉' : 'Sin baneos pasados.');
    }
}

function bxUserStrikes(uid) {
    const list = bxData.history.filter(s => s.user_id === uid);
    const u = bxData.ranking.find(r => r.user_id === uid) || { name: '#' + uid };
    Swal.fire({
        title: `Expulsiones de ${bxEsc(u.name)}`,
        html: list.length ? `<div style="text-align:left;max-height:50vh;overflow:auto;font-size:.85rem;">${list.map(s => `<div style="padding:.5rem 0;border-bottom:1px solid rgba(128,128,128,.2);"><div style="font-weight:700;">${s.raffle_id ? '#' + s.raffle_id + ' ' : ''}${bxEsc(s.raffle_title || 'Sorteo')}</div><div style="opacity:.8;">${bxEsc(s.reason || 'Sin motivo')}</div><div style="font-size:.72rem;opacity:.6;">${bxDate(s.created_at)} · por ${bxEsc(s.created_by)}</div></div>`).join('')}</div>` : 'Solo aparecen las últimas 300 expulsiones globales.',
        showConfirmButton: false, showCloseButton: true, width: 560,
    });
}

function bxBan(uid, name) {
    Swal.fire({
        title: 'Banear de sorteos',
        html: `Se bloqueará a <strong>${bxEsc(name)}</strong> en TODOS los Sorteos Flash (también por IP y dispositivo para evitar multicuentas).
            <div style="text-align:left;margin-top:12px;">
                <label style="font-size:.8rem;font-weight:700;">Tipo de baneo</label>
                <select id="bxType" class="swal2-select" style="width:100%;margin:4px 0 10px;" onchange="document.getElementById('bxNWrap').style.display=this.value==='next_n_raffles'?'block':'none';document.getElementById('bxDWrap').style.display=this.value==='until_date'?'block':'none';">
                    <option value="next_n_raffles">Próximos N sorteos</option>
                    <option value="until_date">Por días</option>
                    <option value="permanent" selected>Permanente</option>
                </select>
                <div id="bxNWrap" style="display:none;"><label style="font-size:.8rem;font-weight:700;">Número de sorteos</label><input id="bxN" type="number" min="1" value="3" class="swal2-input" style="width:100%;margin:4px 0 10px;"></div>
                <div id="bxDWrap" style="display:none;"><label style="font-size:.8rem;font-weight:700;">Duración (días)</label><input id="bxDays" type="number" min="1" value="7" class="swal2-input" style="width:100%;margin:4px 0 10px;"></div>
                <label style="font-size:.8rem;font-weight:700;">Motivo</label>
                <input id="bxReason" type="text" class="swal2-input" placeholder="Motivo (opcional, visible en el historial)" style="width:100%;margin:4px 0 0;">
            </div>`,
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, banear', cancelButtonText: 'Cancelar', confirmButtonColor: '#ef4444',
        preConfirm: () => ({ ban_type: document.getElementById('bxType').value, raffles_n: document.getElementById('bxN').value, days: document.getElementById('bxDays').value, reason: document.getElementById('bxReason').value }),
    }).then(r => {
        if (!r.isConfirmed) return;
        const v = r.value || {};
        $.post(BX_API, { action: 'cc_ban', user_id: uid, ban_type: v.ban_type, raffles_n: v.raffles_n, days: v.days, reason: v.reason || '' }, res => {
            if (res && res.status === 'success') { bxToast(res.message || 'Baneado.'); bxReload(); }
            else Swal.fire('Error', (res && res.message) || 'No se pudo banear.', 'error');
        }, 'json').fail(() => Swal.fire('Error', 'No se pudo banear.', 'error'));
    });
}

function bxUnban(banId, name) {
    Swal.fire({ title: '¿Levantar el baneo?', text: `${name} podrá participar de inmediato (también se libera su IP/huella).`, icon: 'question', showCancelButton: true, confirmButtonText: 'Sí, desbanear', cancelButtonText: 'Cancelar', confirmButtonColor: '#22c55e' })
        .then(r => {
            if (!r.isConfirmed) return;
            $.post(BX_API, { action: 'cc_unban', ban_id: banId }, res => {
                if (res && res.status === 'success') { bxToast(res.message || 'Baneo levantado.'); bxReload(); }
                else Swal.fire('Error', (res && res.message) || 'No se pudo desbanear.', 'error');
            }, 'json').fail(() => Swal.fire('Error', 'No se pudo desbanear.', 'error'));
        });
}

// Banear a alguien que aún no aparece en el ranking (búsqueda global de usuarios).
function bxBanSearch() {
    Swal.fire({
        title: 'Buscar usuario para banear',
        html: `<input id="bxQ" class="swal2-input" placeholder="Nombre, código, teléfono o email…" style="width:100%;margin:0 0 8px;" autocomplete="off"><div id="bxQRes" style="text-align:left;max-height:45vh;overflow:auto;font-size:.85rem;"></div>`,
        showConfirmButton: false, showCloseButton: true, width: 560,
        didOpen: () => {
            const inp = document.getElementById('bxQ'), box = document.getElementById('bxQRes'); let t = null;
            inp.focus();
            inp.addEventListener('input', () => {
                clearTimeout(t); const q = inp.value.trim();
                if (q.length < 2) { box.innerHTML = ''; return; }
                t = setTimeout(() => $.getJSON(BX_API, { action: 'cc_user_search', q }, res => {
                    const users = (res && res.users) || [];
                    window._bxFound = users;
                    box.innerHTML = users.length ? users.map((u, i) => `<button type="button" onclick="Swal.close();bxBan(${u.user_id}, '${bxJs(u.name || u.game_username)}')" style="width:100%;display:flex;align-items:center;gap:.6rem;padding:.5rem;border:0;background:transparent;border-bottom:1px solid rgba(128,128,128,.2);cursor:pointer;text-align:left;color:inherit;">${bxAvatar(u.avatar)}<div style="min-width:0;flex:1;"><div style="font-weight:700;">${bxEsc(u.name || u.game_username)}</div><div style="font-size:.72rem;opacity:.7;">${bxEsc(u.game_username || '')} · ${bxEsc(u.public_code || '')}${u.phone ? ' · ' + bxEsc(u.phone) : ''}</div></div><span style="font-size:.72rem;opacity:.7;">${u.purchases_count || 0} compras</span></button>`).join('')
                        : '<div style="padding:1rem;text-align:center;opacity:.7;">Sin resultados.</div>';
                }), 280);
            });
        },
    });
}

document.getElementById('bxSearch').addEventListener('input', bxRender);
bxReload();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

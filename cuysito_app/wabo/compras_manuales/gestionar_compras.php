<?php
// wabo/compras_manuales/gestionar_compras.php
// Compras Manuales: Revisión Diferida de sorteos + registro de compras
// declaradas por chat (WhatsApp/Messenger) para CUALQUIER usuario.
require_once __DIR__ . '/../core/admin_header.php';

$page_title = "Compras Manuales";
?>

<style>
    :root {
        --cm-surface: var(--secondary);
        --cm-border: var(--border-color);
        --cm-text: var(--text-primary);
        --cm-muted: var(--text-secondary);
        --cm-chip: rgba(17, 24, 39, 0.05);
        --cm-accent: #22d3ee;
        --cm-green: #22c55e;
        --cm-amber: #f59e0b;
        --cm-red: #ef4444;
        --cm-overlay: rgba(0, 0, 0, 0.55);
    }
    html[data-theme="dark"] {
        --cm-surface: #0a0a0a;
        --cm-chip: rgba(255, 255, 255, 0.06);
        --cm-overlay: rgba(0, 0, 0, 0.72);
    }

    .cm-card { background: var(--cm-surface); border: 1px solid var(--cm-border); border-radius: 14px; }
    .cm-muted { color: var(--cm-muted); }
    .cm-input { background: var(--cm-chip); border: 1px solid var(--cm-border); color: var(--cm-text);
        border-radius: 10px; padding: .55rem .75rem; font-size: .875rem; }
    .cm-input:focus { outline: none; border-color: rgba(34, 211, 238, 0.6); }

    /* Tabs */
    .cm-tabs { display: flex; gap: .5rem; border-bottom: 1px solid var(--cm-border); margin-bottom: 1.25rem; }
    .cm-tab { padding: .6rem 1.1rem; font-weight: 700; font-size: .875rem; color: var(--cm-muted);
        border-bottom: 2px solid transparent; cursor: pointer; display: flex; align-items: center; gap: .45rem; }
    .cm-tab.is-active { color: var(--cm-accent); border-bottom-color: var(--cm-accent); }

    /* KPIs */
    .cm-kpis { display: flex; gap: .9rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .cm-kpi { background: var(--cm-surface); border: 1px solid var(--cm-border); border-radius: 12px;
        padding: .6rem 1rem; min-width: 130px; }
    .cm-kpi__num { font-size: 1.35rem; font-weight: 800; }
    .cm-kpi__label { font-size: .65rem; text-transform: uppercase; letter-spacing: .04em; color: var(--cm-muted); }

    /* Filas de participante / usuario */
    .cm-prow { display: flex; align-items: center; gap: .8rem; padding: .7rem .85rem; border-radius: 12px;
        border: 1px solid var(--cm-border); background: var(--cm-surface); margin-bottom: .55rem;
        cursor: pointer; transition: border-color .15s, transform .15s; }
    .cm-prow:hover { border-color: rgba(34, 211, 238, 0.55); transform: translateY(-1px); }
    .cm-prow img { height: 44px; width: 44px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--cm-chip); }
    .cm-prow__main { min-width: 0; flex: 1; }
    .cm-prow__name { font-weight: 700; display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
    .cm-prow__sub { font-size: .74rem; color: var(--cm-muted); margin-top: .18rem; }
    .cm-prow__cta { color: var(--cm-muted); font-size: .78rem; display: flex; align-items: center; gap: .35rem; white-space: nowrap; }
    .cm-tag { font-size: .6rem; font-weight: 800; text-transform: uppercase; padding: .12rem .45rem; border-radius: 999px; }
    .cm-tag--ok { background: rgba(34,197,94,.16); color: var(--cm-green); }
    .cm-tag--warn { background: rgba(245,158,11,.16); color: var(--cm-amber); }
    .cm-tag--bad { background: rgba(239,68,68,.16); color: var(--cm-red); }
    .cm-empty { text-align: center; color: var(--cm-muted); padding: 2.2rem 1rem; }

    /* ===== Modal de trabajo por usuario ===== */
    #cmModal { position: fixed; inset: 0; z-index: 70; display: none; align-items: center; justify-content: center;
        background: var(--cm-overlay); padding: 1rem; }
    #cmModal.is-open { display: flex; }
    .cmm-box { background: var(--cm-surface); border: 1px solid var(--cm-border); border-radius: 16px;
        width: 100%; max-width: 980px; max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; }
    .cmm-head { display: flex; align-items: center; gap: .9rem; padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--cm-border);
        background: linear-gradient(120deg, rgba(34,211,238,.12), rgba(124,58,237,.10)); }
    .cmm-head img { height: 52px; width: 52px; border-radius: 50%; object-fit: cover; background: var(--cm-chip); }
    .cmm-head__name { font-weight: 800; font-size: 1.05rem; display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; }
    .cmm-head__sub { font-size: .74rem; color: var(--cm-muted); margin-top: .12rem; }
    .cmm-totals { display: flex; gap: .9rem; margin-left: auto; flex-wrap: wrap; }
    .cmm-total { text-align: right; }
    .cmm-total__num { font-weight: 800; font-size: .95rem; }
    .cmm-total__label { font-size: .6rem; text-transform: uppercase; letter-spacing: .04em; color: var(--cm-muted); }
    .cmm-body { display: grid; grid-template-columns: 1fr 1fr; gap: 0; flex: 1; overflow: hidden; }
    @media (max-width: 860px) { .cmm-body { grid-template-columns: 1fr; overflow-y: auto; } }
    .cmm-col { padding: 1rem 1.25rem; overflow-y: auto; }
    .cmm-col + .cmm-col { border-left: 1px solid var(--cm-border); }
    .cmm-sec-title { font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
        color: var(--cm-muted); margin: .2rem 0 .55rem; display: flex; align-items: center; gap: .4rem; }
    .cmm-item { display: flex; align-items: center; justify-content: space-between; gap: .6rem;
        border: 1px solid var(--cm-border); background: var(--cm-chip); border-radius: 10px;
        padding: .45rem .65rem; margin-bottom: .4rem; font-size: .8rem; }
    .cmm-item__main { min-width: 0; }
    .cmm-item__amount { font-weight: 800; white-space: nowrap; }
    .cmm-item--dup { border-color: rgba(245,158,11,.6); }
    .cmm-delbtn { color: var(--cm-red); font-size: .8rem; padding: .15rem .35rem; border-radius: 6px; }
    .cmm-delbtn:hover { background: rgba(239,68,68,.12); }

    /* Formulario de alta */
    .cmm-form label { display: block; font-size: .72rem; font-weight: 700; color: var(--cm-muted); margin: .65rem 0 .25rem; }
    .cmm-viabtns { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
    .cmm-viabtn { border: 1px solid var(--cm-border); background: var(--cm-chip); border-radius: 12px;
        padding: .65rem .5rem; text-align: center; cursor: pointer; font-weight: 700; font-size: .84rem;
        display: flex; flex-direction: column; align-items: center; gap: .2rem; }
    .cmm-viabtn small { font-weight: 500; font-size: .64rem; color: var(--cm-muted); }
    .cmm-viabtn.is-active { border-color: var(--cm-accent); background: rgba(34,211,238,.10); color: var(--cm-accent); }
    .cmm-subcats { display: none; grid-template-columns: repeat(3, 1fr); gap: .45rem; margin-top: .5rem; }
    .cmm-subcats.is-visible { display: grid; }
    .cmm-subcat { border: 1px solid var(--cm-border); background: var(--cm-chip); border-radius: 10px;
        padding: .45rem .3rem; text-align: center; cursor: pointer; font-weight: 700; font-size: .78rem; }
    .cmm-subcat.is-active { border-color: #a78bfa; background: rgba(167,139,250,.12); color: #a78bfa; }
    .cmm-savebtn { width: 100%; margin-top: 1rem; background: var(--cm-green); color: #06210f;
        font-weight: 800; padding: .7rem; border-radius: 12px; font-size: .9rem; }
    .cmm-savebtn:hover { filter: brightness(1.06); }
    .cmm-savebtn:disabled { opacity: .5; cursor: not-allowed; }
    .cmm-dupwarn { display: none; margin-top: .6rem; border: 1px solid rgba(245,158,11,.55);
        background: rgba(245,158,11,.10); color: var(--cm-amber); border-radius: 10px;
        padding: .5rem .7rem; font-size: .74rem; }
    .cmm-dupwarn.is-visible { display: block; }
    .cmm-session { margin-top: .8rem; font-size: .74rem; color: var(--cm-muted); }
</style>

<div class="p-6 md:p-8" style="background: var(--primary); min-height: 100%;">
    <div class="max-w-6xl mx-auto">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-6">
            <div>
                <h1 class="text-3xl font-bold flex items-center gap-2" style="color: var(--cm-text);">
                    <i class="fas fa-receipt" style="color: var(--cm-accent);"></i> Compras Manuales
                </h1>
                <p class="cm-muted text-sm mt-1">Registro de compras por chat (regalo / cuenta): revisión por sorteo o alta directa a cualquier usuario</p>
            </div>
            <a href="sorteos_flash/gestionar_sorteos.php" class="cm-card py-2 px-4 text-sm font-bold flex items-center gap-2" style="color: var(--cm-text);">
                <i class="fas fa-bolt text-yellow-400"></i> Ir a Sorteos Flash
            </a>
        </div>

        <div class="cm-tabs">
            <div id="cmTabDeferred" class="cm-tab is-active" onclick="cmSwitchTab('deferred')">
                <i class="fas fa-bolt"></i> Por sorteo
            </div>
            <div id="cmTabSearch" class="cm-tab" onclick="cmSwitchTab('search')">
                <i class="fas fa-user-plus"></i> Cualquier usuario
            </div>
        </div>

        <!-- ===== TAB: Revisión Diferida ===== -->
        <div id="cmPaneDeferred">
            <div class="cm-card p-4 mb-4 flex items-center gap-3 flex-wrap">
                <label class="text-sm cm-muted font-bold">Sorteo:</label>
                <select id="cmRaffleSelect" class="cm-input" onchange="cmLoadDeferred(this.value)"></select>
                <span id="cmRuleInfo" class="text-xs cm-muted"></span>
                <button onclick="cmLoadDeferred(document.getElementById('cmRaffleSelect').value)" class="cm-input text-sm font-bold" style="cursor:pointer;">
                    <i class="fas fa-sync"></i> Refrescar
                </button>
            </div>
            <div class="cm-card p-4 mb-4">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs cm-muted"></i>
                    <input id="cmRaffleUserSearch" type="text" class="cm-input w-full pl-9" placeholder="Buscar usuario para registrar una compra (nombre, usuario, código, teléfono o email)…" autocomplete="off">
                </div>
                <p class="text-xs cm-muted mt-2">Primero aparecen los participantes de este sorteo; después, cualquier otro usuario registrado (aunque no haya entrado al sorteo).</p>
            </div>
            <div id="cmRaffleSearchResults" class="hidden"></div>
            <div id="cmDeferredWrap">
                <div class="cmm-sec-title"><i class="fas fa-clipboard-check" style="color: var(--cm-amber);"></i> Declararon compra por chat (“Bajo Revisión”)</div>
                <div id="cmSummary" class="cm-kpis"></div>
                <div id="cmDeferredList"><div class="cm-empty">Cargando…</div></div>
            </div>
        </div>

        <!-- ===== TAB: Buscar usuario ===== -->
        <div id="cmPaneSearch" class="hidden">
            <div class="cm-card p-4 mb-4">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs cm-muted"></i>
                    <input id="cmUserSearch" type="text" class="cm-input w-full pl-9" placeholder="Buscar por nombre, usuario, código, teléfono o email…">
                </div>
                <p class="text-xs cm-muted mt-2">Sirve para registrar compras a usuarios que aún no participan en ningún sorteo: cuando entren, su historial ya contará.</p>
            </div>
            <div id="cmSearchResults"><div class="cm-empty">Escribe para buscar un usuario.</div></div>
        </div>
    </div>
</div>

<!-- ===== MODAL: FICHA DE COMPRAS DEL USUARIO ===== -->
<div id="cmModal" onclick="if(event.target===this) cmCloseModal()">
    <div class="cmm-box">
        <div class="cmm-head">
            <img id="cmmAvatar" src="" alt="">
            <div>
                <div class="cmm-head__name"><span id="cmmName">—</span><span id="cmmTags"></span></div>
                <div class="cmm-head__sub" id="cmmSub">—</div>
            </div>
            <div class="cmm-totals">
                <div class="cmm-total">
                    <div class="cmm-total__num" id="cmmWebTotal" style="color: var(--cm-accent);">—</div>
                    <div class="cmm-total__label">Web (válidas)</div>
                </div>
                <div class="cmm-total">
                    <div class="cmm-total__num" id="cmmManualTotal" style="color: var(--cm-green);">—</div>
                    <div class="cmm-total__label">Manual (válidas)</div>
                </div>
            </div>
            <button onclick="cmCloseModal()" class="ml-2 cm-muted hover:opacity-70 text-lg px-2" title="Cerrar"><i class="fas fa-times"></i></button>
        </div>
        <div class="cmm-body">
            <!-- Columna izquierda: historial -->
            <div class="cmm-col">
                <div class="cmm-sec-title"><i class="fas fa-globe" style="color: var(--cm-accent);"></i> Pedidos web completados</div>
                <div id="cmmWebOrders"></div>
                <div class="cmm-sec-title mt-4"><i class="fas fa-hand-holding-dollar" style="color: var(--cm-green);"></i> Compras manuales registradas</div>
                <div id="cmmManualList"></div>
            </div>
            <!-- Columna derecha: agregar compra -->
            <div class="cmm-col">
                <div class="cmm-sec-title"><i class="fas fa-plus-circle" style="color: var(--cm-green);"></i> Registrar nueva compra</div>
                <div class="cmm-form">
                    <label>Modalidad</label>
                    <div class="cmm-viabtns">
                        <div id="cmmViaRegalo" class="cmm-viabtn is-active" onclick="cmSetVia('regalo')">
                            <span><i class="fas fa-gift"></i> Vía Regalo</span>
                            <small>Solo importa el monto gastado</small>
                        </div>
                        <div id="cmmViaCuenta" class="cmm-viabtn" onclick="cmSetVia('cuenta')">
                            <span><i class="fas fa-user-circle"></i> Vía Cuenta</span>
                            <small>Club, Pavos o Packs</small>
                        </div>
                    </div>
                    <div id="cmmSubcats" class="cmm-subcats">
                        <div class="cmm-subcat" data-sub="club" onclick="cmSetSubcat('club')"><i class="fas fa-crown"></i> Club</div>
                        <div class="cmm-subcat" data-sub="pavos" onclick="cmSetSubcat('pavos')"><i class="fas fa-coins"></i> Pavos</div>
                        <div class="cmm-subcat" data-sub="packs" onclick="cmSetSubcat('packs')"><i class="fas fa-box"></i> Packs</div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label>Fecha de compra</label>
                            <input id="cmmDate" type="date" class="cm-input w-full" oninput="cmCheckDup()">
                        </div>
                        <div>
                            <label>Monto (S/)</label>
                            <input id="cmmAmount" type="number" step="0.01" min="0.01" class="cm-input w-full" placeholder="0.00" oninput="cmCheckDup()">
                        </div>
                    </div>
                    <label>Nota (opcional)</label>
                    <input id="cmmNote" type="text" maxlength="255" class="cm-input w-full" placeholder="Ej. me mandó captura por WhatsApp">
                    <div id="cmmDupWarn" class="cmm-dupwarn"></div>
                    <button id="cmmSaveBtn" class="cmm-savebtn" onclick="cmSavePurchase()">
                        <i class="fas fa-check"></i> Guardar compra y seguir
                    </button>
                    <div id="cmmSession" class="cmm-session"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const CM_API = 'sorteos_flash/api_sorteos.php';
const CM_AVATAR_BASE = '../../assets/avatars/';

const cmState = {
    tab: 'deferred',
    raffleId: null,
    minDate: '',
    searchTimer: null,
    raffleSearchTimer: null,
    // Estado del modal de trabajo:
    userId: null,
    userName: '',
    via: 'regalo',
    subcat: null,
    webOrders: [],
    manualPurchases: [],
    sessionAdded: 0,
};

function cmEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function cmAvatar(f) { return CM_AVATAR_BASE + (f || 'default.png'); }
function cmMoney(n) { return 'S/' + Number(n || 0).toFixed(2); }
function cmToast(msg) {
    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: msg, showConfirmButton: false, timer: 2200, timerProgressBar: true });
}

// ============ TABS ============
function cmSwitchTab(tab) {
    cmState.tab = tab;
    document.getElementById('cmTabDeferred').classList.toggle('is-active', tab === 'deferred');
    document.getElementById('cmTabSearch').classList.toggle('is-active', tab === 'search');
    document.getElementById('cmPaneDeferred').classList.toggle('hidden', tab !== 'deferred');
    document.getElementById('cmPaneSearch').classList.toggle('hidden', tab !== 'search');
    if (tab === 'search') document.getElementById('cmUserSearch').focus();
}

// Fila de usuario reutilizable (resultados de búsqueda).
function cmUserRow(u, ctx) {
    const tags = [];
    if (ctx === 'raffle') {
        tags.push(u.is_participant
            ? '<span class="cm-tag cm-tag--ok"><i class="fas fa-bolt"></i> En el sorteo</span>'
            : '<span class="cm-tag" style="background: var(--cm-chip); color: var(--cm-muted);">No participa</span>');
    }
    if (u.is_verified) tags.push('<span class="cm-tag cm-tag--ok">VIP</span>');
    if (u.account_status !== 'active') tags.push(`<span class="cm-tag cm-tag--bad">${cmEsc(u.account_status)}</span>`);
    return `<div class="cm-prow" onclick="cmOpenUser(${u.user_id})">
        <img src="${cmEsc(cmAvatar(u.avatar))}">
        <div class="cm-prow__main">
            <div class="cm-prow__name"><span>${cmEsc(u.name)}</span> ${tags.join(' ')}</div>
            <div class="cm-prow__sub">
                ${cmEsc(u.public_code || '')} · ${cmEsc(u.game_username || '')}${u.phone ? ' · ' + cmEsc(u.phone) : ''} ·
                ${u.purchases_count} pedido(s) web / ${cmMoney(u.purchases_sum)}
            </div>
        </div>
        <div class="cm-prow__cta"><i class="fas fa-plus-circle" style="color: var(--cm-green);"></i> Registrar compra <i class="fas fa-chevron-right"></i></div>
    </div>`;
}

// ============ BÚSQUEDA DENTRO DEL SORTEO (participantes primero, luego cualquier usuario) ============
$(document).on('input', '#cmRaffleUserSearch', function () {
    clearTimeout(cmState.raffleSearchTimer);
    const q = this.value.trim();
    cmState.raffleSearchTimer = setTimeout(() => cmRaffleSearch(q), 300);
});

function cmRaffleSearch(q) {
    const box = document.getElementById('cmRaffleSearchResults');
    const wrap = document.getElementById('cmDeferredWrap');
    if (!q) { box.classList.add('hidden'); box.innerHTML = ''; wrap.classList.remove('hidden'); return; }
    wrap.classList.add('hidden');
    box.classList.remove('hidden');
    box.innerHTML = '<div class="cm-empty"><i class="fas fa-circle-notch fa-spin"></i> Buscando…</div>';
    $.getJSON(`${CM_API}?action=cc_user_search&q=${encodeURIComponent(q)}&raffle_id=${cmState.raffleId || 0}`).done(res => {
        const users = (res && res.users) || [];
        if (!users.length) { box.innerHTML = '<div class="cm-empty">Sin resultados.</div>'; return; }
        const inRaffle = users.filter(u => u.is_participant);
        const others = users.filter(u => !u.is_participant);
        let html = '';
        if (inRaffle.length) {
            html += `<div class="cmm-sec-title"><i class="fas fa-bolt" style="color: var(--cm-amber);"></i> Participantes del sorteo #${cmState.raffleId || ''} (${inRaffle.length})</div>`;
            html += inRaffle.map(u => cmUserRow(u, 'raffle')).join('');
        }
        if (others.length) {
            html += `<div class="cmm-sec-title" style="margin-top: ${inRaffle.length ? '1rem' : '.2rem'};"><i class="fas fa-users"></i> Otros usuarios (${others.length})</div>`;
            html += others.map(u => cmUserRow(u, 'raffle')).join('');
        }
        box.innerHTML = html;
    }).fail(() => { box.innerHTML = '<div class="cm-empty">Error en la búsqueda.</div>'; });
}

// ============ TAB 1: REVISIÓN DIFERIDA ============
function cmLoadRaffles() {
    $.getJSON(`${CM_API}?action=cc_recent_raffles`).done(res => {
        if (!res || res.status !== 'success') return;
        const sel = document.getElementById('cmRaffleSelect');
        sel.innerHTML = (res.raffles || []).map(r =>
            `<option value="${r.raffle_id}">#${r.raffle_id} · ${cmEsc(r.title || 'Sorteo')} (${cmEsc(r.status)})</option>`
        ).join('');
        if (res.raffles && res.raffles.length) cmLoadDeferred(res.raffles[0].raffle_id);
        else document.getElementById('cmDeferredList').innerHTML = '<div class="cm-empty">No hay sorteos.</div>';
    }).fail(() => {
        document.getElementById('cmDeferredList').innerHTML = '<div class="cm-empty">Error al cargar los sorteos.</div>';
    });
}

function cmLoadDeferred(rid) {
    rid = parseInt(rid, 10) || 0;
    const raffleChanged = cmState.raffleId !== rid;
    cmState.raffleId = rid;
    if (raffleChanged) {
        const rq = document.getElementById('cmRaffleUserSearch').value.trim();
        if (rq) cmRaffleSearch(rq);
    }
    $('#cmDeferredList').html('<div class="cm-empty"><i class="fas fa-circle-notch fa-spin"></i> Cargando…</div>');
    $('#cmSummary').empty();
    $.getJSON(`${CM_API}?action=cc_deferred_list&id=${rid}`).done(res => {
        if (!res || res.status !== 'success') return;
        const rf = res.raffle || {};
        cmState.minDate = rf.min_date || '';
        $('#cmRuleInfo').text(rf.min_date ? `Regla: compras desde ${rf.min_date}` : 'Sin regla de fecha (cuenta histórico)');
        cmRenderDeferred(res.participants || []);
    }).fail(() => $('#cmDeferredList').html('<div class="cm-empty">Error al cargar la lista.</div>'));
}

function cmRenderDeferred(list) {
    const total = list.length;
    const cubiertos = list.filter(p => (p.web_orders + p.manual_count) > 0 || p.is_verified).length;
    const pendientes = total - cubiertos;
    $('#cmSummary').html(`
        <div class="cm-kpi"><div class="cm-kpi__num">${total}</div><div class="cm-kpi__label">Declararon compra</div></div>
        <div class="cm-kpi"><div class="cm-kpi__num" style="color:var(--cm-green);">${cubiertos}</div><div class="cm-kpi__label">Con registros</div></div>
        <div class="cm-kpi"><div class="cm-kpi__num" style="color:var(--cm-amber);">${pendientes}</div><div class="cm-kpi__label">Sin registro aún</div></div>
    `);
    if (!total) { $('#cmDeferredList').html('<div class="cm-empty">Nadie entró “Bajo Revisión” en este sorteo.</div>'); return; }

    $('#cmDeferredList').html(list.map(p => {
        const tags = [];
        if (p.is_verified) tags.push('<span class="cm-tag cm-tag--ok">VIP</span>');
        if (p.review_status === 'expelled') tags.push('<span class="cm-tag cm-tag--bad">EXPULSADO</span>');
        if (p.strikes > 0) tags.push(`<span class="cm-tag cm-tag--warn">⚠ ${p.strikes} exp.</span>`);
        tags.push((p.web_orders + p.manual_count) > 0
            ? `<span class="cm-tag cm-tag--ok">${p.web_orders} web · ${p.manual_count} manual</span>`
            : '<span class="cm-tag cm-tag--warn">SIN REGISTRO</span>');
        return `<div class="cm-prow" onclick="cmOpenUser(${p.user_id})">
            <img src="${cmEsc(cmAvatar(p.avatar))}">
            <div class="cm-prow__main">
                <div class="cm-prow__name"><span>${cmEsc(p.name)}</span> ${tags.join(' ')}</div>
                <div class="cm-prow__sub">
                    ${cmEsc(p.public_code || '')} · Ticket #${p.ticket} ·
                    Web: ${cmMoney(p.web_spent)} · Manual: ${cmMoney(p.manual_sum)}
                </div>
            </div>
            <div class="cm-prow__cta">Gestionar compras <i class="fas fa-chevron-right"></i></div>
        </div>`;
    }).join(''));
}

// ============ TAB 2: BUSCAR USUARIO ============
$(document).on('input', '#cmUserSearch', function () {
    clearTimeout(cmState.searchTimer);
    const q = this.value.trim();
    cmState.searchTimer = setTimeout(() => cmDoSearch(q), 300);
});

function cmDoSearch(q) {
    if (!q) { $('#cmSearchResults').html('<div class="cm-empty">Escribe para buscar un usuario.</div>'); return; }
    $('#cmSearchResults').html('<div class="cm-empty"><i class="fas fa-circle-notch fa-spin"></i> Buscando…</div>');
    $.getJSON(`${CM_API}?action=cc_user_search&q=${encodeURIComponent(q)}&raffle_id=0`).done(res => {
        const users = (res && res.users) || [];
        if (!users.length) { $('#cmSearchResults').html('<div class="cm-empty">Sin resultados.</div>'); return; }
        $('#cmSearchResults').html(users.map(u => cmUserRow(u, 'global')).join(''));
    }).fail(() => $('#cmSearchResults').html('<div class="cm-empty">Error en la búsqueda.</div>'));
}

// ============ MODAL DE TRABAJO POR USUARIO ============
function cmOpenUser(uid) {
    cmState.userId = uid;
    cmState.sessionAdded = 0;
    cmSetVia('regalo');
    document.getElementById('cmmDate').value = cmState.minDate || new Date().toISOString().slice(0, 10);
    document.getElementById('cmmAmount').value = '';
    document.getElementById('cmmNote').value = '';
    document.getElementById('cmmSession').textContent = '';
    document.getElementById('cmModal').classList.add('is-open');
    cmRefreshUser();
}

function cmCloseModal() {
    document.getElementById('cmModal').classList.remove('is-open');
    // Refresca la lista de fondo para que los contadores queden al día.
    if (cmState.tab === 'deferred' && cmState.raffleId) {
        cmLoadDeferred(cmState.raffleId);
        const rq = document.getElementById('cmRaffleUserSearch').value.trim();
        if (rq) cmRaffleSearch(rq);
    }
    else if (cmState.tab === 'search') cmDoSearch(document.getElementById('cmUserSearch').value.trim());
}

function cmRefreshUser() {
    const uid = cmState.userId;
    const md = cmState.tab === 'deferred' ? (cmState.minDate || '') : '';
    $('#cmmWebOrders, #cmmManualList').html('<div class="cm-empty" style="padding:1rem;"><i class="fas fa-circle-notch fa-spin"></i></div>');
    $.getJSON(`${CM_API}?action=mp_user_overview&user_id=${uid}&min_date=${encodeURIComponent(md)}`).done(res => {
        if (!res || res.status !== 'success') { Swal.fire('Error', (res && res.message) || 'No se pudo cargar la ficha.', 'error'); return; }
        const u = res.user, t = res.totals || {};
        cmState.userName = u.name;
        cmState.webOrders = res.web_orders || [];
        cmState.manualPurchases = res.manual_purchases || [];

        document.getElementById('cmmAvatar').src = cmAvatar(u.avatar);
        document.getElementById('cmmName').textContent = u.name;
        document.getElementById('cmmTags').innerHTML = u.is_verified ? ' <span class="cm-tag cm-tag--ok">VIP</span>' : '';
        document.getElementById('cmmSub').textContent =
            `${u.public_code || ''} · ${u.game_username || ''}${u.phone ? ' · ' + u.phone : ''}`;
        document.getElementById('cmmWebTotal').textContent = `${t.web_orders || 0} · ${cmMoney(t.web_spent)}`;
        document.getElementById('cmmManualTotal').textContent = `${t.manual_count || 0} · ${cmMoney(t.manual_sum)}`;

        cmRenderWebOrders();
        cmRenderManual();
        cmCheckDup();
    }).fail(() => Swal.fire('Error', 'No se pudo cargar la ficha del usuario.', 'error'));
}

function cmRenderWebOrders() {
    const el = document.getElementById('cmmWebOrders');
    if (!cmState.webOrders.length) {
        el.innerHTML = '<div class="cm-empty" style="padding:.8rem;">Sin pedidos web completados.</div>';
        return;
    }
    el.innerHTML = cmState.webOrders.map(o => {
        const fecha = (o.fecha_creacion || '').slice(0, 10);
        return `<div class="cmm-item">
            <div class="cmm-item__main">
                <strong>${cmEsc(o.codigo_pedido || ('#' + o.id))}</strong>
                <span class="cm-muted"> · ${cmEsc(fecha)}</span>
            </div>
            <div class="cmm-item__amount" style="color: var(--cm-accent);">${cmMoney(o.total)}</div>
        </div>`;
    }).join('');
}

function cmViaLabel(m) {
    if (m.via === 'regalo') return '🎁 Regalo';
    if (m.via === 'cuenta') {
        const s = { club: 'Club', pavos: 'Pavos', packs: 'Packs' }[m.subcategory] || 'Cuenta';
        return `👤 Cuenta · ${s}`;
    }
    return cmEsc(m.channel || 'manual');
}

function cmRenderManual() {
    const el = document.getElementById('cmmManualList');
    if (!cmState.manualPurchases.length) {
        el.innerHTML = '<div class="cm-empty" style="padding:.8rem;">Aún no tiene compras manuales.</div>';
        return;
    }
    el.innerHTML = cmState.manualPurchases.map(m => `<div class="cmm-item">
        <div class="cmm-item__main">
            <strong>${cmEsc(m.purchase_date)}</strong>
            <span class="cm-muted"> · ${cmViaLabel(m)}</span>
            ${m.note ? `<span class="cm-muted"> · ${cmEsc(m.note)}</span>` : ''}
            ${m.admin_name ? `<span class="cm-muted"> · por ${cmEsc(m.admin_name)}</span>` : ''}
        </div>
        <div style="display:flex;align-items:center;gap:.4rem;">
            <div class="cmm-item__amount" style="color: var(--cm-green);">${cmMoney(m.amount)}</div>
            <button class="cmm-delbtn" title="Eliminar registro" onclick="cmDeletePurchase(${m.id})"><i class="fas fa-trash"></i></button>
        </div>
    </div>`).join('');
}

function cmSetVia(via) {
    cmState.via = via;
    if (via !== 'cuenta') cmState.subcat = null;
    document.getElementById('cmmViaRegalo').classList.toggle('is-active', via === 'regalo');
    document.getElementById('cmmViaCuenta').classList.toggle('is-active', via === 'cuenta');
    document.getElementById('cmmSubcats').classList.toggle('is-visible', via === 'cuenta');
    if (via !== 'cuenta') document.querySelectorAll('.cmm-subcat').forEach(b => b.classList.remove('is-active'));
}

function cmSetSubcat(sub) {
    cmState.subcat = sub;
    document.querySelectorAll('.cmm-subcat').forEach(b => b.classList.toggle('is-active', b.dataset.sub === sub));
}

// Aviso preventivo: compara la fecha/monto tecleados contra los pedidos web
// completados y las compras manuales ya registradas del usuario.
function cmCheckDup() {
    const warnEl = document.getElementById('cmmDupWarn');
    const date = document.getElementById('cmmDate').value;
    const amount = parseFloat(document.getElementById('cmmAmount').value || '0');
    const hits = [];
    if (date) {
        cmState.webOrders.forEach(o => {
            const f = (o.fecha_creacion || '').slice(0, 10);
            if (f === date) {
                const sameAmt = amount > 0 && Math.abs(o.total - amount) < 0.01;
                hits.push(`Pedido web ${o.codigo_pedido || ('#' + o.id)} del ${f} por ${cmMoney(o.total)}${sameAmt ? ' (¡mismo monto!)' : ''}`);
            }
        });
        cmState.manualPurchases.forEach(m => {
            if (m.purchase_date === date) {
                const sameAmt = amount > 0 && Math.abs(m.amount - amount) < 0.01;
                hits.push(`Compra manual del ${m.purchase_date} por ${cmMoney(m.amount)}${sameAmt ? ' (¡mismo monto!)' : ''}`);
            }
        });
    }
    if (hits.length) {
        warnEl.innerHTML = '<i class="fas fa-triangle-exclamation"></i> <strong>Posible duplicado:</strong> ya existe en esa fecha:<br>· ' + hits.map(cmEsc).join('<br>· ');
        warnEl.classList.add('is-visible');
    } else {
        warnEl.classList.remove('is-visible');
    }
}

function cmSavePurchase(force) {
    const date = document.getElementById('cmmDate').value;
    const amount = document.getElementById('cmmAmount').value;
    const note = document.getElementById('cmmNote').value.trim();
    if (!date) { Swal.fire('Falta la fecha', 'Indica la fecha real de la compra.', 'warning'); return; }
    if (!amount || Number(amount) <= 0) { Swal.fire('Monto inválido', 'El monto debe ser mayor a 0.', 'warning'); return; }
    if (cmState.via === 'cuenta' && !cmState.subcat) { Swal.fire('Falta la subcategoría', 'Selecciona Club, Pavos o Packs.', 'warning'); return; }

    const btn = document.getElementById('cmmSaveBtn');
    btn.disabled = true;
    $.post(CM_API, {
        action: 'mp_add',
        user_id: cmState.userId,
        purchase_date: date,
        amount: amount,
        via: cmState.via,
        subcategory: cmState.subcat || '',
        note: note,
        force: force ? 1 : 0
    }, null, 'json').done(res => {
        btn.disabled = false;
        if (res && res.status === 'duplicate_warning') {
            Swal.fire({
                title: 'Posible duplicado',
                html: `<div style="text-align:left;font-size:.85rem;white-space:pre-line;">${cmEsc(res.message)}</div><p style="margin-top:.6rem;font-size:.85rem;">¿Registrar de todas formas?</p>`,
                icon: 'warning', showCancelButton: true,
                confirmButtonText: 'Sí, registrar igual', cancelButtonText: 'Cancelar',
                confirmButtonColor: '#f59e0b'
            }).then(r => { if (r.isConfirmed) cmSavePurchase(true); });
            return;
        }
        if (res && res.status === 'success') {
            cmState.sessionAdded++;
            document.getElementById('cmmSession').innerHTML =
                `<i class="fas fa-circle-check" style="color:var(--cm-green);"></i> ${cmState.sessionAdded} compra(s) registrada(s) en esta sesión para <strong>${cmEsc(cmState.userName)}</strong>.`;
            document.getElementById('cmmAmount').value = '';
            document.getElementById('cmmNote').value = '';
            cmToast('Compra registrada para ' + cmState.userName);
            cmRefreshUser();
        } else {
            Swal.fire('Error', (res && res.message) || 'No se pudo registrar.', 'error');
        }
    }).fail(() => { btn.disabled = false; Swal.fire('Error', 'No se pudo registrar la compra.', 'error'); });
}

function cmDeletePurchase(id) {
    Swal.fire({ title: '¿Eliminar registro?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#ef4444' })
        .then(r => {
            if (!r.isConfirmed) return;
            $.post(CM_API, { action: 'mp_delete', id }, res => {
                if (res && res.status === 'success') { cmToast('Registro eliminado.'); cmRefreshUser(); }
                else { Swal.fire('Error', (res && res.message) || 'No se pudo eliminar.', 'error'); }
            }, 'json').fail(() => Swal.fire('Error', 'No se pudo eliminar.', 'error'));
        });
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('cmModal').classList.contains('is-open')) cmCloseModal();
});

$(document).ready(cmLoadRaffles);
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

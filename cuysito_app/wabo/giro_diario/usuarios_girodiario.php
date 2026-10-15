<?php
// === Giro Diario · Usuarios — Panel de Administración ===
// Participación del día: métricas, desglose por modo/filtro, quién giró y quién no,
// búsqueda de jugadores, detalle con historial, giros bonus y reactivación de cupones.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/admin_header.php';
$gdCurrent = 'usuarios';
require_once __DIR__ . '/girodiario_ui.php';
?>

<div class="space-y-6">

    <!-- ─── Métricas del día ─── -->
    <div class="gd-card" id="stats-card">
        <div class="gd-section-header" style="cursor:default;">
            <i class="fas fa-chart-line section-icon"></i>
            <h2>Métricas del día</h2>
            <span class="gd-live-dot" title="Actualizado"></span>
            <span class="text-xs font-semibold" style="margin-left:auto;color:var(--gd-text-secondary);">
                Día de negocio: <span id="stats-date">—</span>
                <button class="gd-btn gd-btn-ghost gd-btn-sm" onclick="refreshAll()" title="Actualizar" style="margin-left:0.5rem;padding:0.3rem 0.55rem;"><i class="fas fa-sync-alt"></i></button>
            </span>
        </div>

        <div class="flex flex-col lg:flex-row gap-4">
            <div class="flex items-center gap-4" style="min-width:260px;">
                <div class="gd-ring" id="ring-active"><span id="ring-active-value">—</span></div>
                <div>
                    <div class="gd-subtitle" style="margin-bottom:0.2rem;"><i class="fas fa-user-check"></i> Participación</div>
                    <div style="font-size:0.84rem;color:var(--gd-text-primary);font-weight:600;">
                        <span id="stat-active-spun">—</span> de <span id="stat-active">—</span> usuarios activos ya giraron
                    </div>
                    <div class="gd-hint">Activos = login en los últimos <span id="stat-active-days">30</span> días · <span id="stat-total-users">—</span> registrados en total</div>
                </div>
            </div>

            <div class="gd-kpi-grid" style="flex:1;--gd-kpi-cols:5;">
                <div class="gd-kpi gd-kpi-green">
                    <div class="kpi-label"><i class="fas fa-check-circle"></i> Ya giraron</div>
                    <div class="kpi-value" id="kpi-spun-pct">—</div>
                    <div class="kpi-sub"><span id="kpi-spun-users">—</span> usuarios activos</div>
                </div>
                <div class="gd-kpi gd-kpi-red">
                    <div class="kpi-label"><i class="fas fa-hourglass-half"></i> Pendientes</div>
                    <div class="kpi-value" id="kpi-pending-pct">—</div>
                    <div class="kpi-sub"><span id="kpi-pending-users">—</span> usuarios activos</div>
                </div>
                <div class="gd-kpi gd-kpi-amber">
                    <div class="kpi-label"><i class="fas fa-dice"></i> Giros hoy</div>
                    <div class="kpi-value" id="kpi-spins">—</div>
                    <div class="kpi-sub"><span id="kpi-spins-per-user">—</span> por jugador</div>
                </div>
                <div class="gd-kpi gd-kpi-blue">
                    <div class="kpi-label"><i class="fas fa-percent"></i> Descuento medio</div>
                    <div class="kpi-value" id="kpi-avg-percent">—</div>
                    <div class="kpi-sub">Máximo hoy: <span id="kpi-max-percent">—</span></div>
                </div>
                <div class="gd-kpi">
                    <div class="kpi-label"><i class="fas fa-bolt"></i> Premios Flash</div>
                    <div class="kpi-value" id="kpi-flash">—</div>
                    <div class="kpi-sub">Cupones flash entregados</div>
                </div>
            </div>
        </div>

        <div class="gd-divider"></div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div>
                <div class="gd-subtitle"><i class="fas fa-filter"></i> Desglose por modo / filtro</div>
                <div id="breakdown-pool"></div>
                <div class="gd-empty" id="breakdown-pool-empty" style="display:none;padding:1.25rem;">
                    <i class="fas fa-dice"></i>
                    <p>Sin giros todavía en esta ventana.</p>
                </div>
            </div>
            <div>
                <div class="gd-subtitle"><i class="fas fa-ticket-alt"></i> Desglose por origen del giro</div>
                <div id="breakdown-source"></div>
                <div class="gd-empty" id="breakdown-source-empty" style="display:none;padding:1.25rem;">
                    <i class="fas fa-dice"></i>
                    <p>Sin giros todavía en esta ventana.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Buscador global ─── -->
    <div class="gd-card">
        <div class="gd-section-header" style="cursor:default;">
            <i class="fas fa-user-secret section-icon"></i>
            <h2>Inspector de Usuarios</h2>
        </div>

        <div class="gd-search-wrapper">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="user-search-input" class="gd-input" placeholder="Buscar cualquier jugador por Nickname o WhatsApp..." autocomplete="off">
            <div class="search-spinner" id="search-spinner">
                <i class="fas fa-spinner fa-spin" style="color:var(--gd-accent);"></i>
            </div>
        </div>

        <div id="inspector-search" style="display:none;margin-top:1rem;">
            <div class="flex items-center justify-between mb-2">
                <span class="gd-badge gd-badge-used"><i class="fas fa-search"></i> Resultados</span>
                <div class="text-xs font-semibold" style="color:var(--gd-text-secondary);"><span id="count-search">0</span></div>
            </div>
            <div id="grid-search" class="gd-user-grid grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5"></div>
            <div class="gd-empty" id="empty-search" style="display:none;">
                <i class="fas fa-user-slash"></i>
                <p>No se encontraron usuarios.</p>
            </div>
        </div>
    </div>

    <!-- ─── Tabla 1: Ya giraron hoy ─── -->
    <div class="gd-card">
        <div class="gd-section-header" onclick="toggleSection('spun-body', this)">
            <i class="fas fa-check-circle section-icon" style="color:#22c55e;"></i>
            <h2>Usuarios que giraron hoy</h2>
            <span class="gd-badge gd-badge-active" style="margin-left:0.5rem;"><span id="count-spun-today">0</span></span>
            <i class="fas fa-chevron-down collapse-chevron"></i>
        </div>
        <div id="spun-body">
            <div id="grid-spun-today" class="gd-user-grid grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5"></div>
            <div class="gd-empty" id="empty-spun-today" style="display:none;">
                <i class="fas fa-dice"></i>
                <p>Nadie giró hoy todavía.</p>
            </div>
        </div>
    </div>

    <!-- ─── Tabla 2: Aún no giraron ─── -->
    <div class="gd-card">
        <div class="gd-section-header" onclick="toggleSection('pending-body', this)">
            <i class="fas fa-hourglass-half section-icon" style="color:#ef4444;"></i>
            <h2>Usuarios que aún NO han girado</h2>
            <span class="gd-badge gd-badge-expired" style="margin-left:0.5rem;"><span id="count-not-spun-today">0</span></span>
            <i class="fas fa-chevron-down collapse-chevron"></i>
        </div>
        <div id="pending-body">
            <div class="gd-search-wrapper mb-4">
                <i class="fas fa-filter search-icon"></i>
                <input type="text" id="pending-search-input" class="gd-input" placeholder="Filtrar pendientes por Nickname o WhatsApp..." autocomplete="off">
                <div class="search-spinner" id="pending-spinner">
                    <i class="fas fa-spinner fa-spin" style="color:var(--gd-accent);"></i>
                </div>
            </div>
            <div id="grid-not-spun-today" class="gd-user-grid grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5"></div>
            <div class="gd-empty" id="empty-not-spun-today" style="display:none;">
                <i class="fas fa-check"></i>
                <p id="empty-not-spun-text">Todos los usuarios ya giraron hoy.</p>
            </div>
            <div class="gd-card-actions" id="pending-more-wrap" style="display:none;justify-content:center;">
                <button class="gd-btn gd-btn-ghost" onclick="loadMorePending()" id="pending-more-btn">
                    <i class="fas fa-angle-down"></i> Cargar más (<span id="pending-remaining">0</span> restantes)
                </button>
            </div>
        </div>
    </div>

</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- ═══  USER DETAIL MODAL  ═════════════════════════════════════════ -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="gd-modal-overlay" id="user-modal">
    <div class="gd-modal-content">
        <div class="gd-modal-header" id="modal-header">
            <img src="" alt="Avatar" class="avatar-lg" id="modal-avatar">
            <div class="header-info">
                <div class="header-name" id="modal-username">—</div>
                <div class="header-meta">
                    <span><i class="fab fa-whatsapp" style="color:#25d366;"></i> <span id="modal-phone">—</span></span>
                    <span><i class="fas fa-coins" style="color:#fbbf24;"></i> <span id="modal-coins">0</span> monedas</span>
                    <span><i class="fas fa-id-badge" style="color:var(--gd-accent);"></i> ID: <span id="modal-uid">—</span></span>
                </div>
            </div>
            <button class="close-btn" onclick="closeUserModal()" title="Cerrar">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="gd-modal-body">
            <h3 style="font-size:0.85rem;font-weight:700;color:var(--gd-text-secondary);text-transform:uppercase;margin-bottom:0.5rem;">
                <i class="fas fa-sync-alt" style="color:var(--gd-accent);margin-right:0.3rem;"></i> Giros Bonus
            </h3>
            <div class="gd-spins-control">
                <button class="gd-spin-btn minus" onclick="changeSpins(-1)" title="Quitar 1 giro">
                    <i class="fas fa-minus"></i>
                </button>
                <div style="flex:1;text-align:center;">
                    <div class="spin-value" id="modal-spins">0</div>
                    <div class="spin-label">Giros disponibles</div>
                </div>
                <button class="gd-spin-btn plus" onclick="changeSpins(1)" title="Añadir 1 giro">
                    <i class="fas fa-plus"></i>
                </button>
                <div style="border-left:1px solid var(--gd-border);height:36px;margin:0 0.25rem;"></div>
                <button class="gd-spin-btn plus" onclick="changeSpins(5)" title="Añadir 5 giros" style="font-size:0.75rem;width:auto;padding:0 10px;">
                    +5
                </button>
                <button class="gd-spin-btn plus" onclick="changeSpins(10)" title="Añadir 10 giros" style="font-size:0.75rem;width:auto;padding:0 10px;">
                    +10
                </button>
            </div>

            <h3 style="font-size:0.85rem;font-weight:700;color:var(--gd-text-secondary);text-transform:uppercase;margin-bottom:0.5rem;">
                <i class="fas fa-history" style="color:var(--gd-accent);margin-right:0.3rem;"></i> Historial de Giros
            </h3>
            <div style="overflow-x:auto;">
                <table class="gd-history-table">
                    <thead>
                        <tr>
                            <th>Ítem</th>
                            <th>Descuento</th>
                            <th>Pool</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th>Expira</th>
                            <th style="text-align:right;">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="modal-history-body"></tbody>
                </table>
            </div>
            <div class="gd-empty" id="modal-history-empty" style="display:none;">
                <i class="fas fa-dice"></i>
                <p>Este usuario no tiene historial de giros aún.</p>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const { apiCall, toast, escapeHtml, avatarUrl, formatDate, formatInt, formatPct, themeVar } = window.GD;

    const PENDING_PAGE = 30;
    let currentUserId = null;
    let searchDebounce = null;
    let pendingDebounce = null;
    let pendingQuery = '';
    let pendingOffset = 0;
    let pendingTotal = 0;
    let pendingLoading = false;

    const $ = (id) => document.getElementById(id);

    // ══════════════════════════════════════════════════════════════════
    // STATS
    // ══════════════════════════════════════════════════════════════════
    async function loadStats() {
        try {
            const data = await apiCall('get_user_stats');
            if (!data.success) { toast(data.error || 'Error al cargar métricas', 'error'); return; }
            const u = data.users || {};
            const s = data.spins || {};

            $('stats-date').textContent = data.business_date || '—';
            $('stat-active-days').textContent = data.active_days ?? 30;
            $('stat-total-users').textContent = formatInt(u.total);
            $('stat-active').textContent = formatInt(u.active);
            $('stat-active-spun').textContent = formatInt(u.active_spun);

            const ring = $('ring-active');
            ring.style.setProperty('--p', Math.max(0, Math.min(100, Number(u.active_spun_pct || 0))));
            $('ring-active-value').textContent = formatPct(u.active_spun_pct, 0);

            $('kpi-spun-pct').textContent = formatPct(u.active_spun_pct);
            $('kpi-spun-users').textContent = formatInt(u.active_spun);
            $('kpi-pending-pct').textContent = formatPct(u.active_pending_pct);
            $('kpi-pending-users').textContent = formatInt(u.active_pending);
            $('kpi-spins').textContent = formatInt(s.total);
            $('kpi-spins-per-user').textContent = Number(s.per_user || 0).toLocaleString('es-PE', { maximumFractionDigits: 2 });
            $('kpi-avg-percent').textContent = formatPct(s.avg_percent);
            $('kpi-max-percent').textContent = (s.max_percent ?? 0) + '%';
            $('kpi-flash').textContent = formatInt(s.flash_prizes);

            renderBars('breakdown-pool', 'breakdown-pool-empty', s.by_pool || [], s.total || 0, (row) =>
                `${formatInt(row.count)} giros · ${formatPct(row.share)} · media ${formatPct(row.avg_percent)}`);
            renderBars('breakdown-source', 'breakdown-source-empty', s.by_source || [], s.total || 0, (row) =>
                `${formatInt(row.count)} giros · ${formatPct(s.total > 0 ? row.count * 100 / s.total : 0)}`);
        } catch (e) {
            console.error(e);
            toast('Error de red', 'error');
        }
    }

    function renderBars(containerId, emptyId, rows, total, metaFn) {
        const box = $(containerId);
        const empty = $(emptyId);
        box.innerHTML = '';
        if (!rows.length) { empty.style.display = ''; return; }
        empty.style.display = 'none';
        rows.forEach(row => {
            const pct = total > 0 ? (row.count * 100 / total) : 0;
            const el = document.createElement('div');
            el.className = 'gd-bar-row';
            el.innerHTML = `
                <span class="bar-label">${escapeHtml(row.label)}</span>
                <span class="bar-meta">${escapeHtml(metaFn(row))}</span>
                <div class="gd-bar"><span style="width:${Math.max(2, Math.min(100, pct))}%"></span></div>
            `;
            box.appendChild(el);
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // USER CARDS
    // ══════════════════════════════════════════════════════════════════
    function buildUserCard(u, statusType) {
        const card = document.createElement('div');
        card.className = 'gd-user-grid-card';
        card.onclick = () => openUserModal(u.id);

        const statusDotClass = statusType === 'spun' ? 'gd-dot-green' : 'gd-dot-gray';
        let statusText = 'Sin giro';
        if (statusType === 'spun') {
            statusText = 'Giró hoy' + (u.spins_today > 1 ? ' (' + u.spins_today + ')' : '');
        }
        const detail = statusType === 'spun' && u.last_label
            ? `<div class="gd-hint" style="margin-top:0.25rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtml(u.last_label)}">${escapeHtml(u.last_label)}${u.best_percent ? ' · ' + u.best_percent + '%' : ''}</div>`
            : '';

        const imgSrc = avatarUrl(u.avatar_filename, u.game_username);
        card.innerHTML = `
            <img src="${imgSrc}" alt="Avatar" class="avatar" onerror="this.src='${avatarUrl(null, (u.game_username||'U'))}'">
            <div class="name">${escapeHtml(u.game_username || '—')}</div>
            <div class="status"><span class="dot ${statusDotClass}"></span> ${escapeHtml(statusText)}</div>
            ${detail}
        `;
        return card;
    }

    function renderGrid(gridEl, emptyEl, users, statusType, append = false) {
        if (!append) gridEl.innerHTML = '';
        if ((!users || users.length === 0) && gridEl.children.length === 0) {
            emptyEl.style.display = '';
            return;
        }
        emptyEl.style.display = 'none';
        (users || []).forEach(u => gridEl.appendChild(buildUserCard(u, statusType)));
    }

    // ══════════════════════════════════════════════════════════════════
    // INSPECTOR (girados + pendientes con búsqueda/paginación)
    // ══════════════════════════════════════════════════════════════════
    const gridSpunToday = $('grid-spun-today');
    const gridNotSpunToday = $('grid-not-spun-today');
    const emptySpunToday = $('empty-spun-today');
    const emptyNotSpunToday = $('empty-not-spun-today');

    async function loadInspector({ append = false } = {}) {
        if (pendingLoading) return;
        pendingLoading = true;
        $('pending-spinner').classList.add('active');
        try {
            const data = await apiCall('get_user_inspector', {
                query: pendingQuery,
                limit: PENDING_PAGE,
                offset: append ? pendingOffset : 0,
            });
            if (!data.success) { toast(data.error || 'Error al cargar usuarios', 'error'); return; }

            if (!append) {
                const spun = data.spun_today || [];
                $('count-spun-today').textContent = spun.length;
                renderGrid(gridSpunToday, emptySpunToday, spun, 'spun');
            }

            const notSpun = data.not_spun_today || [];
            pendingTotal = Number(data.not_spun_total || 0);
            pendingOffset = Number(data.not_spun_offset || 0) + notSpun.length;
            $('count-not-spun-today').textContent = formatInt(pendingTotal);
            $('empty-not-spun-text').textContent = pendingQuery
                ? 'Ningún pendiente coincide con la búsqueda.'
                : 'Todos los usuarios ya giraron hoy.';
            renderGrid(gridNotSpunToday, emptyNotSpunToday, notSpun, 'not', append);

            const remaining = Math.max(0, pendingTotal - pendingOffset);
            $('pending-remaining').textContent = formatInt(remaining);
            $('pending-more-wrap').style.display = remaining > 0 ? '' : 'none';
        } catch (e) {
            console.error(e);
            toast('Error de red', 'error');
        } finally {
            pendingLoading = false;
            $('pending-spinner').classList.remove('active');
        }
    }

    window.loadMorePending = function() { loadInspector({ append: true }); };

    $('pending-search-input').addEventListener('input', function() {
        clearTimeout(pendingDebounce);
        const q = this.value.trim();
        pendingDebounce = setTimeout(() => {
            pendingQuery = q;
            pendingOffset = 0;
            loadInspector();
        }, 300);
    });

    // ══════════════════════════════════════════════════════════════════
    // BÚSQUEDA GLOBAL
    // ══════════════════════════════════════════════════════════════════
    const searchInput = $('user-search-input');
    const searchSpinner = $('search-spinner');
    const inspectorSearch = $('inspector-search');
    const gridSearch = $('grid-search');
    const emptySearch = $('empty-search');
    const countSearch = $('count-search');

    searchInput.addEventListener('input', function() {
        clearTimeout(searchDebounce);
        const q = this.value.trim();
        if (q.length < 2) {
            inspectorSearch.style.display = 'none';
            emptySearch.style.display = 'none';
            gridSearch.innerHTML = '';
            countSearch.textContent = '0';
            return;
        }
        searchDebounce = setTimeout(() => searchUsers(q), 350);
    });

    async function searchUsers(query) {
        searchSpinner.classList.add('active');
        try {
            const data = await apiCall('search_user', { query });
            if (!data.success) { toast(data.error || 'Error buscando', 'error'); return; }
            inspectorSearch.style.display = '';
            const users = data.users || [];
            countSearch.textContent = users.length;
            renderGrid(gridSearch, emptySearch, users, 'not');
        } catch (e) {
            toast('Error de red', 'error');
        } finally {
            searchSpinner.classList.remove('active');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // USER MODAL
    // ══════════════════════════════════════════════════════════════════
    const modal = $('user-modal');

    async function openUserModal(userId) {
        currentUserId = userId;
        modal.classList.add('open');
        $('modal-username').textContent = 'Cargando...';
        $('modal-phone').textContent = '...';
        $('modal-coins').textContent = '...';
        $('modal-uid').textContent = userId;
        $('modal-spins').textContent = '...';
        $('modal-history-body').innerHTML = '';
        $('modal-history-empty').style.display = 'none';

        try {
            const data = await apiCall('get_user_details', { user_id: userId });
            if (!data.success) {
                toast(data.error || 'Error cargando usuario', 'error');
                closeUserModal();
                return;
            }
            renderModal(data.user, data.history);
        } catch (e) {
            toast('Error de red', 'error');
            closeUserModal();
        }
    }

    function renderModal(user, history) {
        const avatar = $('modal-avatar');
        avatar.src = avatarUrl(user.avatar_filename, user.game_username);
        avatar.onerror = function() { this.src = avatarUrl(null, user.game_username); };
        $('modal-username').textContent = user.game_username || '—';
        $('modal-phone').textContent = user.whatsapp_phone_e164 || 'Sin WhatsApp';
        $('modal-coins').textContent = parseInt(user.coin_balance || 0).toLocaleString();
        $('modal-uid').textContent = user.id;
        $('modal-spins').textContent = parseInt(user.bonus_spins || 0);

        const tbody = $('modal-history-body');
        const emptyHist = $('modal-history-empty');
        tbody.innerHTML = '';

        if (!history || history.length === 0) {
            emptyHist.style.display = '';
            return;
        }
        emptyHist.style.display = 'none';

        history.forEach(h => {
            const statusClass = h.status === 'active' ? 'gd-badge-active' :
                                h.status === 'used' ? 'gd-badge-used' : 'gd-badge-expired';
            const statusIcon  = h.status === 'active' ? 'fa-check-circle' :
                                h.status === 'used' ? 'fa-shopping-bag' : 'fa-clock';
            const canReactivate = (h.status === 'used' || h.status === 'expired');

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td data-label="Ítem">
                    <div class="gd-item-cell">
                        ${h.item_image_url ? `<img src="${escapeHtml(h.item_image_url)}" alt="" onerror="this.style.display='none'">` : ''}
                        <span>${escapeHtml(h.item_name || '—')}</span>
                    </div>
                </td>
                <td data-label="Descuento" style="font-weight:700;color:#22c55e;">${h.discount_percent}%</td>
                <td data-label="Pool"><span class="gd-pill gd-pill-info">${escapeHtml(h.pool_label || 'Sin clasificar')}</span></td>
                <td data-label="Estado"><span class="gd-badge ${statusClass}"><i class="fas ${statusIcon}"></i> ${escapeHtml(h.status)}</span></td>
                <td data-label="Fecha">${formatDate(h.created_at)}</td>
                <td data-label="Expira">${formatDate(h.expires_at)}</td>
                <td data-label="Acción" style="text-align:right;">
                    ${canReactivate
                        ? `<button class="gd-btn gd-btn-success gd-btn-sm" onclick="reactivateDiscount(${Number(h.id)})"><i class="fas fa-redo-alt"></i> Reactivar</button>`
                        : `<span style="font-size:0.75rem;color:var(--gd-text-secondary);">—</span>`
                    }
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    window.closeUserModal = function() {
        modal.classList.remove('open');
        currentUserId = null;
    };

    modal.addEventListener('click', function(e) {
        if (e.target === this) closeUserModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeUserModal();
    });

    window.changeSpins = async function(delta) {
        if (!currentUserId) return;
        try {
            const data = await apiCall('update_spins', { user_id: currentUserId, delta });
            if (data.success) {
                $('modal-spins').textContent = data.new_bonus_spins;
                toast(delta > 0 ? `+${delta} giro(s) añadido(s)` : `${delta} giro(s) removido(s)`, 'success');
            } else {
                toast(data.error || 'Error', 'error');
            }
        } catch (e) {
            toast('Error de red', 'error');
        }
    };

    window.reactivateDiscount = async function(discountId) {
        const confirm = await Swal.fire({
            title: '¿Reactivar descuento?',
            text: 'El descuento se activará por 24 horas adicionales.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, reactivar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#22c55e',
            background: themeVar('--secondary', '#0a0a0a'),
            color: themeVar('--text-primary', '#f1f5f9'),
        });
        if (!confirm.isConfirmed) return;

        try {
            const data = await apiCall('reactivate_discount', { discount_id: discountId });
            if (data.success) {
                toast(data.message || 'Descuento reactivado');
                if (currentUserId) openUserModal(currentUserId);
            } else {
                toast(data.error || 'Error', 'error');
            }
        } catch (e) {
            toast('Error de red', 'error');
        }
    };

    // ── Init ─────────────────────────────────────────────────────────
    window.refreshAll = function() {
        pendingOffset = 0;
        loadStats();
        loadInspector();
    };
    refreshAll();
})();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

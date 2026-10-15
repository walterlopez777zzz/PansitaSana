<?php
// === Giro Diario · Configuración — Panel de Administración ===
// Reglas del juego: elegibilidad (quién puede girar), descuentos por modo (Todo / Sin Pistas),
// filtros por categoría, economía (paid spins) y evento flash. La participación vive en usuarios_girodiario.php.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/admin_header.php';
$gdCurrent = 'config';
require_once __DIR__ . '/girodiario_ui.php';
?>

<div class="space-y-6">

    <!-- ─── Resumen de la configuración vigente ─── -->
    <div class="gd-card" id="summary-card">
        <div class="gd-section-header" style="cursor:default;">
            <i class="fas fa-chart-pie section-icon"></i>
            <h2>Resumen del catálogo</h2>
            <span class="gd-live-dot" id="config-live-dot" title="Conectado"></span>
            <span id="config-mode-chip" class="gd-mode-chip gd-mode-normal" style="margin-left:0.5rem;">
                <i class="fas fa-circle" style="font-size:0.45rem;"></i> <span>NORMAL</span>
            </span>
            <span id="eligibility-chip" class="gd-pill gd-pill-muted" style="margin-left:0.5rem;" title="Quién puede girar">—</span>
            <span class="text-xs font-semibold" style="margin-left:auto;color:var(--gd-text-secondary);">
                Día de negocio: <span id="summary-date">—</span>
            </span>
        </div>
        <div class="gd-kpi-grid" style="--gd-kpi-cols:6;">
            <div class="gd-kpi">
                <div class="kpi-label"><i class="fas fa-store"></i> Ítems en tienda</div>
                <div class="kpi-value" id="kpi-total-items">—</div>
                <div class="kpi-sub">Pool completo (Todo)</div>
            </div>
            <div class="gd-kpi gd-kpi-amber">
                <div class="kpi-label"><i class="fas fa-music"></i> Sin Pistas</div>
                <div class="kpi-value" id="kpi-notrack-items">—</div>
                <div class="kpi-sub"><span id="kpi-track-items">—</span> pistas excluidas</div>
            </div>
            <div class="gd-kpi">
                <div class="kpi-label"><i class="fas fa-layer-group"></i> Categorías</div>
                <div class="kpi-value" id="kpi-categories">—</div>
                <div class="kpi-sub">Detectadas hoy en el catálogo</div>
            </div>
            <div class="gd-kpi gd-kpi-green">
                <div class="kpi-label"><i class="fas fa-filter"></i> Filtros visibles</div>
                <div class="kpi-value" id="kpi-enabled-filters">—</div>
                <div class="kpi-sub"><span id="kpi-enabled-items">—</span> ítems alcanzables</div>
            </div>
            <div class="gd-kpi gd-kpi-blue">
                <div class="kpi-label"><i class="fas fa-percent"></i> Media filtros</div>
                <div class="kpi-value" id="kpi-avg-filter">—</div>
                <div class="kpi-sub">Ponderada por nº de ítems</div>
            </div>
            <div class="gd-kpi">
                <div class="kpi-label"><i class="fas fa-trophy"></i> Mejor descuento</div>
                <div class="kpi-value" id="kpi-best">—</div>
                <div class="kpi-sub" id="kpi-best-sub">—</div>
            </div>
        </div>
    </div>

    <!-- ─── Elegibilidad (quién puede participar) ─── -->
    <section class="gd-card" id="eligibility-card" aria-labelledby="eligibility-title">
        <div class="gd-section-header" onclick="toggleSection('eligibility-body', this)">
            <i class="fas fa-user-shield section-icon"></i>
            <h2 id="eligibility-title">Elegibilidad</h2>
            <i class="fas fa-chevron-down collapse-chevron"></i>
        </div>
        <div id="eligibility-body">
            <p class="gd-hint" style="margin-bottom:0.9rem;">
                Ajuste <strong>global</strong> (no cambia con el día de negocio). El servidor lo aplica en cada solicitud de giro; el modal público sólo refleja el veredicto.
            </p>
            <form id="eligibility-form" novalidate>
                <fieldset class="gd-choice-group" id="eligibility-options" aria-describedby="eligibility-status">
                    <legend>Quién puede girar</legend>
                    <label class="gd-choice">
                        <input type="radio" name="eligibility" value="verified">
                        <span class="choice-body">
                            <span class="choice-title"><i class="fas fa-check-circle"></i> Solo cuentas verificadas</span>
                            <span class="choice-desc">Requiere <code>is_verified = 1</code>. Las cuentas sin verificar ven el aviso de verificación y el servidor rechaza el giro.</span>
                        </span>
                    </label>
                    <label class="gd-choice">
                        <input type="radio" name="eligibility" value="all">
                        <span class="choice-body">
                            <span class="choice-title"><i class="fas fa-users"></i> Cuentas en general</span>
                            <span class="choice-desc">Cualquier usuario con sesión iniciada puede girar. Se mantienen el resto de reglas (giro gratis, tiros extra, límites).</span>
                        </span>
                    </label>
                </fieldset>
                <div class="gd-card-actions">
                    <span class="gd-unsaved" id="eligibility-unsaved"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Cambios sin guardar</span>
                    <span id="eligibility-status" class="gd-hint" role="status" aria-live="polite"></span>
                    <button type="submit" class="gd-btn gd-btn-primary" id="eligibility-save">
                        <i class="fas fa-save"></i> Guardar elegibilidad
                    </button>
                </div>
            </form>
        </div>
    </section>

    <!-- ─── Descuentos por modo ─── -->
    <div class="gd-card" id="modes-card">
        <div class="gd-section-header" onclick="toggleSection('modes-body', this)">
            <i class="fas fa-percentage section-icon"></i>
            <h2>Descuentos por modo</h2>
            <i class="fas fa-chevron-down collapse-chevron"></i>
        </div>
        <div id="modes-body">
            <p class="gd-hint" style="margin-bottom:0.9rem;">
                Porcentaje que gana el jugador según el modo elegido en el modal público. Un filtro por categoría (abajo) tiene prioridad sobre ambos.
            </p>
            <div class="gd-discount-grid">
                <div class="gd-discount-tile gd-tile-all">
                    <div class="tile-head">
                        <span class="tile-title"><i class="fas fa-globe"></i> Modo Libre / Global · Todo</span>
                        <span class="tile-count"><span id="mode-all-count">—</span> ítems</span>
                    </div>
                    <div class="gd-percent-input">
                        <input type="number" id="cfg-normal-percent" class="gd-input" min="0" max="100" step="1" value="15" placeholder="15" inputmode="numeric">
                        <span class="unit">%</span>
                        <button type="button" class="gd-btn gd-btn-ghost gd-btn-sm" data-reset="cfg-normal-percent" data-default="15" title="Restablecer al valor por defecto">Default 15%</button>
                    </div>
                    <span class="gd-hint">Se aplica a todo el catálogo, pistas incluidas.</span>
                </div>
                <div class="gd-discount-tile gd-tile-notrk">
                    <div class="tile-head">
                        <span class="tile-title"><i class="fas fa-music"></i> Modo "Sin Pistas"</span>
                        <span class="tile-count"><span id="mode-notrack-count">—</span> ítems</span>
                    </div>
                    <div class="gd-percent-input">
                        <input type="number" id="cfg-notracks-percent" class="gd-input" min="0" max="100" step="1" value="10" placeholder="10" inputmode="numeric">
                        <span class="unit">%</span>
                        <button type="button" class="gd-btn gd-btn-ghost gd-btn-sm" data-reset="cfg-notracks-percent" data-default="10" title="Restablecer al valor por defecto">Default 10%</button>
                    </div>
                    <span class="gd-hint">Excluye las Pistas de Improvisación del pool.</span>
                </div>
            </div>
            <div class="gd-card-actions">
                <span class="gd-unsaved" id="modes-unsaved"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Cambios sin guardar</span>
                <button class="gd-btn gd-btn-primary" onclick="saveModes()">
                    <i class="fas fa-save"></i> Guardar descuentos
                </button>
            </div>
        </div>
    </div>

    <!-- ─── Filtros por categoría ─── -->
    <div class="gd-card" id="filters-card">
        <div class="gd-section-header" onclick="toggleSection('filters-body', this)">
            <i class="fas fa-filter section-icon"></i>
            <h2>Filtros por categoría</h2>
            <span class="gd-pill gd-pill-ok" style="margin-left:0.5rem;"><span id="filters-on-count">0</span> visibles</span>
            <i class="fas fa-chevron-down collapse-chevron"></i>
        </div>
        <div id="filters-body">
            <p class="gd-hint" style="margin-bottom:0.9rem;">
                Las categorías salen del catálogo activo (<em>displayType</em>). Solo las marcadas como <strong>visibles</strong> aparecen en el botón <strong>Filtros</strong> del modal público, con el texto exacto de la columna "Vista previa".
            </p>
            <div style="overflow-x:auto;">
                <table class="gd-filter-table">
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th style="text-align:center;">Ítems hoy</th>
                            <th style="text-align:center;">Visible</th>
                            <th style="text-align:center;">% Descuento</th>
                            <th>Vista previa</th>
                        </tr>
                    </thead>
                    <tbody id="filters-tbody">
                        <tr><td colspan="5" class="gd-empty"><i class="fas fa-spinner fa-spin"></i><p>Cargando catálogo…</p></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="gd-empty" id="filters-empty" style="display:none;">
                <i class="fas fa-box-open"></i>
                <p>No se detectaron categorías en el catálogo activo.</p>
            </div>
            <div class="gd-card-actions">
                <span class="gd-unsaved" id="filters-unsaved"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Cambios sin guardar</span>
                <button class="gd-btn gd-btn-ghost" onclick="bulkFilters(false)" title="Ocultar todas las categorías"><i class="fas fa-eye-slash"></i> Ocultar todo</button>
                <button class="gd-btn gd-btn-ghost" onclick="bulkFilters(true)" title="Mostrar todas las categorías con ítems"><i class="fas fa-eye"></i> Mostrar todo</button>
                <button class="gd-btn gd-btn-primary" onclick="saveFilters()">
                    <i class="fas fa-save"></i> Guardar filtros
                </button>
            </div>
        </div>
    </div>

    <!-- ─── Economía & Eventos (paid spins / flash offer) ─── -->
    <div class="gd-card" id="config-card">
        <div class="gd-section-header" onclick="toggleSection('config-body', this)">
            <i class="fas fa-coins section-icon"></i>
            <h2>Economía &amp; Eventos</h2>
            <i class="fas fa-chevron-down collapse-chevron" id="config-chevron"></i>
        </div>

        <div id="config-body">
            <div class="flex items-start gap-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-4" style="flex:1;">
                    <div class="gd-stat-item">
                        <div class="gd-stat-label">Paid Spins</div>
                        <div class="mt-2" id="mon-paid-status">—</div>
                    </div>
                    <div class="gd-stat-item">
                        <div class="gd-stat-value" id="mon-paid-price">—</div>
                        <div class="gd-stat-label">Precio</div>
                    </div>
                    <div class="gd-stat-item">
                        <div class="gd-stat-value" id="mon-paid-limit">—</div>
                        <div class="gd-stat-label">Límite Usuario</div>
                    </div>
                    <div class="gd-stat-item">
                        <div class="gd-stat-label">Flash Offer</div>
                        <div class="mt-2" id="mon-flash-status">—</div>
                    </div>
                    <div class="gd-stat-item">
                        <div class="gd-stat-value" id="mon-flash-claims">—</div>
                        <div class="gd-stat-label">Claims Usados</div>
                    </div>
                    <div class="gd-stat-item">
                        <div class="gd-stat-value" id="mon-flash-percent">—</div>
                        <div class="gd-stat-label">% Descuento Flash</div>
                    </div>
                </div>

                <button class="gd-btn gd-btn-ghost gd-btn-sm" onclick="openAdvancedConfig()" title="Configuración avanzada" aria-label="Configuración avanzada" style="justify-content:center;padding:0.45rem 0.6rem;">
                    <i class="fas fa-cog"></i>
                </button>
            </div>
        </div>
    </div>

</div>

<!-- ─── Modal: Configuración Avanzada (modo del día, paid spins, flash) ─── -->
<div class="gd-modal-overlay" id="config-modal">
    <div class="gd-modal-content">
        <div class="gd-modal-header">
            <div class="header-info">
                <div class="header-name">Configuración Avanzada</div>
                <div class="header-meta">
                    <span><i class="fas fa-coins" style="color:var(--gd-accent);"></i> Economy</span>
                    <span><i class="fas fa-bolt" style="color:var(--gd-accent);"></i> Event</span>
                </div>
            </div>
            <button class="close-btn" onclick="closeAdvancedConfig()" title="Cerrar">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="gd-modal-body">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="gd-label">Modo del Día</label>
                    <select id="cfg-mode" class="gd-select">
                        <option value="normal">🎯 Normal</option>
                        <option value="boosted">🔥 Boosted</option>
                        <option value="jackpot">💎 Jackpot</option>
                        <option value="disabled">🚫 Desactivado</option>
                    </select>
                </div>

                <div>
                    <label class="gd-label">Paid Spins</label>
                    <div class="flex items-center gap-3 mt-1">
                        <label class="gd-toggle">
                            <input type="checkbox" id="cfg-paid-enabled">
                            <span class="slider"></span>
                        </label>
                        <span id="cfg-paid-label" class="text-sm font-semibold" style="color:var(--gd-text-secondary);">Desactivado</span>
                    </div>
                </div>

                <div>
                    <label class="gd-label">Precio por Giro (Coins)</label>
                    <input type="number" id="cfg-paid-price" class="gd-input" min="0" max="1000000" value="50" placeholder="50">
                </div>

                <div>
                    <label class="gd-label">Límite Paid Spins (Usuario)</label>
                    <input type="number" id="cfg-paid-limit" class="gd-input" min="0" max="9999" value="3" placeholder="3">
                </div>

                <div>
                    <label class="gd-label">Flash Offer</label>
                    <div class="flex items-center gap-3 mt-1">
                        <label class="gd-toggle">
                            <input type="checkbox" id="cfg-flash-enabled">
                            <span class="slider"></span>
                        </label>
                        <span id="cfg-flash-label" class="text-sm font-semibold" style="color:var(--gd-text-secondary);">Desactivada</span>
                    </div>
                </div>

                <div>
                    <label class="gd-label">% Descuento Flash</label>
                    <input type="number" id="cfg-flash-percent" class="gd-input" min="0" max="100" value="15" placeholder="15">
                </div>

                <div>
                    <label class="gd-label">Límite de Claims Flash</label>
                    <input type="number" id="cfg-flash-limit" class="gd-input" min="0" max="9999" value="10" placeholder="10">
                </div>
            </div>
            <p class="gd-hint" style="margin-top:1rem;">
                Los descuentos <strong>Global</strong> y <strong>Sin Pistas</strong> se editan en la tarjeta "Descuentos por modo" de esta misma página.
            </p>
        </div>

        <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--gd-border); display:flex; justify-content:flex-end; gap:0.5rem;">
            <button class="gd-btn gd-btn-ghost" onclick="closeAdvancedConfig()">
                Cerrar
            </button>
            <button class="gd-btn gd-btn-primary" onclick="saveAdvancedConfig()">
                <i class="fas fa-save"></i> Guardar Cambios
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const { apiCall, toast, escapeHtml, formatInt, formatPct, filterOptionText } = window.GD;

    let currentConfig = null;
    let catalogStats = {};          // { total_items, no_tracks_items, track_items, categories, ... } del backend
    let filtersState = [];          // [{ key, label, source_type, count, is_bundle, enabled, percent, sort_order }]
    let modesDirty = false;
    let filtersDirty = false;
    let eligibilityDirty = false;
    // Política de elegibilidad confirmada por el servidor (fuente de verdad para el chip y el payload).
    let eligibilityPolicy = null;   // 'verified' | 'all' | null (aún no cargada)
    let eligibilitySaving = false;

    const $ = (id) => document.getElementById(id);
    const clampPct = (v, fallback) => {
        const n = parseInt(v, 10);
        if (Number.isNaN(n)) return fallback;
        return Math.max(0, Math.min(100, n));
    };

    // ══════════════════════════════════════════════════════════════════
    // LOAD
    // ══════════════════════════════════════════════════════════════════
    // keepFilters: conserva ediciones de la tabla de filtros aún sin guardar.
    async function loadConfig({ keepFilters = false } = {}) {
        try {
            const data = await apiCall('get_config');
            if (!data.success) { toast(data.error || 'Error al cargar configuración', 'error'); return; }
            const c = data.config || {};
            currentConfig = c;

            $('summary-date').textContent = data.business_date || '—';

            // Avanzado (modal)
            $('cfg-mode').value = c.mode || 'normal';
            $('cfg-flash-enabled').checked = c.flash_offer_enabled == 1;
            $('cfg-flash-limit').value = c.flash_offer_limit ?? 10;
            $('cfg-flash-percent').value = c.flash_offer_percent ?? 15;
            $('cfg-paid-enabled').checked = c.paid_spins_enabled == 1;
            $('cfg-paid-limit').value = c.paid_spins_limit ?? 0;
            $('cfg-paid-price').value = c.paid_spin_price ?? 0;
            updateFlashLabel();
            updatePaidLabel();
            updateMonitoring(c);
            updateModeChip(c.mode);
            applyEligibilityPayload(data.eligibility);

            // Modos
            const modes = data.modes || {};
            $('cfg-normal-percent').value = modes.all?.percent ?? c.normal_discount_percent ?? (data.defaults?.all ?? 15);
            $('cfg-notracks-percent').value = modes.no_tracks?.percent ?? c.no_tracks_discount_percent ?? (data.defaults?.no_tracks ?? 10);
            $('mode-all-count').textContent = formatInt(modes.all?.count ?? 0);
            $('mode-notrack-count').textContent = formatInt(modes.no_tracks?.count ?? 0);
            setModesDirty(false);

            if (keepFilters && filtersDirty) {
                catalogStats = data.catalog || {};
                recomputeLocalKpis();
            } else {
                applyFiltersPayload(data);
            }
        } catch (e) {
            console.error('Error cargando config:', e);
            toast('Error de red', 'error');
        }
    }

    function applyFiltersPayload(data) {
        filtersState = (data.filters || []).map(f => ({
            key: f.key,
            label: f.label,
            source_type: f.source_type || '',
            count: Number(f.count || 0),
            track_count: Number(f.track_count || 0),
            is_bundle: !!f.is_bundle,
            enabled: !!f.enabled,
            percent: clampPct(f.percent, 5),
            configured: !!f.configured,
            sort_order: Number(f.sort_order || 100),
        }));
        catalogStats = data.catalog || {};
        renderFilters();
        recomputeLocalKpis();
        setFiltersDirty(false);
    }

    // ══════════════════════════════════════════════════════════════════
    // KPIs
    // ══════════════════════════════════════════════════════════════════
    function renderCatalogKpis(cat, modes) {
        $('kpi-total-items').textContent = formatInt(cat.total_items ?? 0);
        $('kpi-notrack-items').textContent = formatInt(cat.no_tracks_items ?? 0);
        $('kpi-track-items').textContent = formatInt(cat.track_items ?? 0);
        $('kpi-categories').textContent = formatInt(cat.categories ?? 0);
        $('kpi-enabled-filters').textContent = formatInt(cat.enabled_filters ?? 0);
        $('kpi-enabled-items').textContent = formatInt(cat.enabled_items ?? 0);
        $('kpi-avg-filter').textContent = formatPct(cat.avg_filter_percent ?? 0);

        const candidates = [
            { label: 'Modo Global', percent: Number(modes.all?.percent ?? 0) },
            { label: 'Modo Sin Pistas', percent: Number(modes.no_tracks?.percent ?? 0) },
            ...filtersState.filter(f => f.enabled && f.count > 0).map(f => ({ label: 'Filtro ' + f.label, percent: f.percent })),
        ];
        const best = candidates.reduce((a, b) => (b.percent > a.percent ? b : a), candidates[0]);
        $('kpi-best').textContent = best ? best.percent + '%' : '—';
        $('kpi-best-sub').textContent = best ? best.label : '—';
    }

    // KPIs derivados del estado en pantalla (reflejan los cambios antes de guardar).
    function recomputeLocalKpis() {
        const live = filtersState.filter(f => f.enabled && f.count > 0);
        const items = live.reduce((s, f) => s + f.count, 0);
        const weighted = live.reduce((s, f) => s + f.count * f.percent, 0);
        $('filters-on-count').textContent = live.length;
        renderCatalogKpis({
            ...catalogStats,
            enabled_filters: live.length,
            enabled_items: items,
            avg_filter_percent: items > 0 ? Math.round((weighted / items) * 10) / 10 : 0,
        }, {
            all: { percent: clampPct($('cfg-normal-percent').value, 15) },
            no_tracks: { percent: clampPct($('cfg-notracks-percent').value, 10) },
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // MODES
    // ══════════════════════════════════════════════════════════════════
    function setModesDirty(v) {
        modesDirty = v;
        $('modes-unsaved').classList.toggle('is-visible', v);
    }
    ['cfg-normal-percent', 'cfg-notracks-percent'].forEach(id => {
        $(id).addEventListener('input', () => { setModesDirty(true); recomputeLocalKpis(); });
    });
    document.querySelectorAll('[data-reset]').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = $(btn.dataset.reset);
            input.value = btn.dataset.default;
            input.dispatchEvent(new Event('input'));
        });
    });

    window.saveModes = async function() {
        if (!currentConfig) return;
        const normal = clampPct($('cfg-normal-percent').value, null);
        const noTracks = clampPct($('cfg-notracks-percent').value, null);
        if (normal === null || noTracks === null) { toast('Introduce porcentajes entre 0 y 100', 'error'); return; }
        try {
            const data = await apiCall('save_config', buildConfigPayload({ normal, noTracks }));
            if (data.success) {
                toast('Descuentos guardados');
                setModesDirty(false);
                loadConfig({ keepFilters: true });
            } else {
                toast(data.error || 'Error al guardar', 'error');
            }
        } catch (e) {
            toast('Error de red', 'error');
        }
    };

    // Un único payload para save_config: siempre envía el estado completo de la fila del día.
    // La elegibilidad viaja con el valor CONFIRMADO por el servidor (no con la selección sin
    // guardar de la tarjeta): sólo `saveEligibility()` la cambia.
    function buildConfigPayload(override = {}) {
        return {
            ...(eligibilityPolicy ? { eligibility: override.eligibility ?? eligibilityPolicy } : {}),
            mode: $('cfg-mode').value,
            flash_offer_enabled: $('cfg-flash-enabled').checked ? 1 : 0,
            flash_offer_limit: $('cfg-flash-limit').value,
            flash_offer_percent: $('cfg-flash-percent').value,
            normal_discount_percent: override.normal ?? clampPct($('cfg-normal-percent').value, 15),
            no_tracks_discount_percent: override.noTracks ?? clampPct($('cfg-notracks-percent').value, 10),
            paid_spins_enabled: $('cfg-paid-enabled').checked ? 1 : 0,
            paid_spins_limit: $('cfg-paid-limit').value,
            paid_spin_price: $('cfg-paid-price').value,
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // ELIGIBILITY (quién puede girar — ajuste global)
    // ══════════════════════════════════════════════════════════════════
    const ELIGIBILITY_CHIP = {
        verified: { cls: 'gd-pill-warn', icon: 'fa-check-circle', text: 'Solo verificadas' },
        all:      { cls: 'gd-pill-ok',   icon: 'fa-users',        text: 'Cuentas en general' },
    };
    const eligibilityForm = $('eligibility-form');
    const eligibilityRadios = () => Array.from(eligibilityForm.elements.eligibility || []);
    const selectedEligibility = () => (eligibilityForm.elements.eligibility?.value) || null;

    function setEligibilityDirty(v) {
        eligibilityDirty = v;
        $('eligibility-unsaved').classList.toggle('is-visible', v);
    }

    function renderEligibilityChip(policy) {
        const chip = $('eligibility-chip');
        const meta = ELIGIBILITY_CHIP[policy];
        if (!meta) { chip.className = 'gd-pill gd-pill-muted'; chip.textContent = '—'; return; }
        chip.className = 'gd-pill ' + meta.cls;
        chip.innerHTML = '<i class="fas ' + meta.icon + '"></i> ' + escapeHtml(meta.text);
    }

    // Sincroniza la tarjeta con el veredicto del servidor. Si el admin tiene una selección
    // sin guardar, se respeta (una relectura de fondo no debe pisar su edición).
    function applyEligibilityPayload(payload) {
        const policy = payload && ELIGIBILITY_CHIP[payload.policy] ? payload.policy : null;
        eligibilityPolicy = policy;
        renderEligibilityChip(policy);
        if (eligibilityDirty) return;
        eligibilityRadios().forEach(r => { r.checked = r.value === policy; });
        $('eligibility-status').textContent = policy
            ? 'Vigente: ' + (payload.options?.find(o => o.value === policy)?.label || ELIGIBILITY_CHIP[policy].text)
            : '';
    }

    eligibilityForm.addEventListener('change', () => {
        setEligibilityDirty(selectedEligibility() !== eligibilityPolicy);
    });

    async function saveEligibility() {
        const policy = selectedEligibility();
        if (!policy || !ELIGIBILITY_CHIP[policy] || eligibilitySaving) return;
        eligibilitySaving = true;
        const btn = $('eligibility-save');
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        try {
            const data = await apiCall('save_config', buildConfigPayload({ eligibility: policy }));
            if (data.success) {
                setEligibilityDirty(false);
                applyEligibilityPayload(data.eligibility);
                toast('Elegibilidad guardada');
            } else {
                toast(data.error || 'Error al guardar la elegibilidad', 'error');
            }
        } catch (e) {
            toast('Error de red', 'error');
        } finally {
            eligibilitySaving = false;
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
        }
    }
    eligibilityForm.addEventListener('submit', (e) => { e.preventDefault(); saveEligibility(); });

    // ══════════════════════════════════════════════════════════════════
    // FILTERS
    // ══════════════════════════════════════════════════════════════════
    function setFiltersDirty(v) {
        filtersDirty = v;
        $('filters-unsaved').classList.toggle('is-visible', v);
    }

    function renderFilters() {
        const tbody = $('filters-tbody');
        const empty = $('filters-empty');
        tbody.innerHTML = '';
        if (filtersState.length === 0) {
            empty.style.display = '';
            $('filters-on-count').textContent = '0';
            return;
        }
        empty.style.display = 'none';

        filtersState.forEach((f, idx) => {
            const tr = document.createElement('tr');
            tr.dataset.key = f.key;
            const absent = f.count === 0;
            tr.className = (f.enabled ? '' : 'is-off') + (absent ? ' is-absent' : '');

            const pills = [];
            if (f.is_bundle) pills.push('<span class="gd-pill gd-pill-warn" title="El jugador verá el aviso de Lotes al seleccionarlo"><i class="fas fa-exclamation-triangle"></i> Aviso</span>');
            if (f.track_count > 0) pills.push('<span class="gd-pill gd-pill-info" title="Categoría de pistas: se excluye en modo Sin Pistas"><i class="fas fa-music"></i> Pistas</span>');
            if (absent) pills.push('<span class="gd-pill gd-pill-muted" title="Guardada antes, pero hoy no hay ítems en la tienda">Sin stock hoy</span>');

            tr.innerHTML = `
                <td data-label="Categoría">
                    <span class="gd-filter-name">${escapeHtml(f.label)} ${pills.join(' ')}</span>
                    <span class="gd-filter-src">${escapeHtml(f.source_type || f.key)}</span>
                </td>
                <td data-label="Ítems hoy" style="text-align:center;font-weight:700;">${formatInt(f.count)}</td>
                <td data-label="Visible" style="text-align:center;">
                    <label class="gd-toggle" title="${f.enabled ? 'Visible en la web' : 'Oculto en la web'}">
                        <input type="checkbox" data-role="enabled" ${f.enabled ? 'checked' : ''} ${absent ? 'disabled' : ''}>
                        <span class="slider"></span>
                    </label>
                </td>
                <td data-label="% Descuento" style="text-align:center;">
                    <input type="number" class="gd-input" data-role="percent" min="0" max="100" step="1" inputmode="numeric" value="${f.percent}">
                </td>
                <td data-label="Vista previa">
                    <span class="gd-filter-preview ${f.enabled && !absent ? 'is-on' : ''}" data-role="preview">${escapeHtml(filterOptionText(f.label, f.percent, f.count))}</span>
                </td>
            `;

            tr.querySelector('[data-role="enabled"]').addEventListener('change', (e) => {
                filtersState[idx].enabled = e.target.checked;
                tr.classList.toggle('is-off', !e.target.checked);
                e.target.closest('label').title = e.target.checked ? 'Visible en la web' : 'Oculto en la web';
                refreshPreview(tr, filtersState[idx]);
                setFiltersDirty(true);
                recomputeLocalKpis();
            });
            tr.querySelector('[data-role="percent"]').addEventListener('input', (e) => {
                filtersState[idx].percent = clampPct(e.target.value, filtersState[idx].percent);
                refreshPreview(tr, filtersState[idx]);
                setFiltersDirty(true);
                recomputeLocalKpis();
            });
            tr.querySelector('[data-role="percent"]').addEventListener('blur', (e) => {
                e.target.value = filtersState[idx].percent;
            });

            tbody.appendChild(tr);
        });

        $('filters-on-count').textContent = filtersState.filter(f => f.enabled && f.count > 0).length;
    }

    function refreshPreview(tr, f) {
        const el = tr.querySelector('[data-role="preview"]');
        el.textContent = filterOptionText(f.label, f.percent, f.count);
        el.classList.toggle('is-on', f.enabled && f.count > 0);
    }

    window.bulkFilters = function(enabled) {
        filtersState.forEach(f => { if (f.count > 0) f.enabled = enabled; });
        renderFilters();
        setFiltersDirty(true);
        recomputeLocalKpis();
    };

    window.saveFilters = async function() {
        const items = filtersState.map((f, i) => ({
            key: f.key,
            label: f.label,
            enabled: f.enabled ? 1 : 0,
            percent: f.percent,
            sort_order: f.configured ? f.sort_order : (i + 1) * 10,
        }));
        try {
            const data = await apiCall('save_filters', { filters: JSON.stringify(items) });
            if (data.success) {
                toast(`Filtros guardados (${data.written})`);
                applyFiltersPayload(data);
            } else {
                toast(data.error || 'Error al guardar filtros', 'error');
            }
        } catch (e) {
            toast('Error de red', 'error');
        }
    };

    // ══════════════════════════════════════════════════════════════════
    // ECONOMÍA & EVENTOS (modal avanzado)
    // ══════════════════════════════════════════════════════════════════
    function updateMonitoring(c) {
        const paidEnabled = c.paid_spins_enabled == 1;
        $('mon-paid-status').innerHTML = paidEnabled
            ? '<span class="gd-badge gd-badge-active"><i class="fas fa-check-circle"></i> Activo</span>'
            : '<span class="gd-badge gd-badge-expired"><i class="fas fa-times-circle"></i> Inactivo</span>';
        $('mon-paid-price').textContent = (c.paid_spin_price ?? 0) + ' Coins';
        $('mon-paid-limit').textContent = (c.paid_spins_limit ?? 0);

        const flashEnabled = c.flash_offer_enabled == 1;
        $('mon-flash-status').innerHTML = flashEnabled
            ? '<span class="gd-badge gd-badge-active"><i class="fas fa-bolt"></i> Activo</span>'
            : '<span class="gd-badge gd-badge-expired"><i class="fas fa-moon"></i> Inactivo</span>';
        $('mon-flash-claims').textContent = (c.flash_offer_claimed ?? 0) + ' / ' + (c.flash_offer_limit ?? 0);
        $('mon-flash-percent').textContent = (c.flash_offer_percent ?? 0) + '%';
    }

    function updateModeChip(mode) {
        const chip = $('config-mode-chip');
        chip.className = 'gd-mode-chip gd-mode-' + (mode || 'normal');
        chip.querySelector('span').textContent = (mode || 'normal').toUpperCase();
    }

    function updateFlashLabel() {
        $('cfg-flash-label').textContent = $('cfg-flash-enabled').checked ? 'Activada' : 'Desactivada';
    }
    $('cfg-flash-enabled').addEventListener('change', updateFlashLabel);

    function updatePaidLabel() {
        $('cfg-paid-label').textContent = $('cfg-paid-enabled').checked ? 'Activado' : 'Desactivado';
    }
    $('cfg-paid-enabled').addEventListener('change', updatePaidLabel);

    const configModal = $('config-modal');
    window.openAdvancedConfig = function() { configModal.classList.add('open'); };
    window.closeAdvancedConfig = function() { configModal.classList.remove('open'); };

    window.saveAdvancedConfig = async function() {
        try {
            const data = await apiCall('save_config', buildConfigPayload());
            if (data.success) {
                toast('Configuración guardada');
                closeAdvancedConfig();
                loadConfig({ keepFilters: true });
            } else {
                toast(data.error || 'Error al guardar', 'error');
            }
        } catch (e) {
            toast('Error de red', 'error');
        }
    };

    configModal.addEventListener('click', function(e) {
        if (e.target === this) closeAdvancedConfig();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && configModal.classList.contains('open')) closeAdvancedConfig();
    });

    window.addEventListener('beforeunload', function(e) {
        if (!modesDirty && !filtersDirty && !eligibilityDirty) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ── Init ─────────────────────────────────────────────────────────
    loadConfig();
})();
</script>

<?php require_once __DIR__ . '/../core/admin_footer.php'; ?>

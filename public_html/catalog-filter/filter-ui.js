/**
 * catalog-filter/filter-ui.js — Panel "Filtro de la tienda" (tipos + precio)
 * y sus controles de acceso (botones con badge y widgets "Borrar filtros").
 *
 * El panel edita un *borrador* local; el store sólo cambia al pulsar
 * "Ver resultados" o "Borrar". Los controles externos (badge, widgets)
 * reflejan siempre el estado confirmado. Nada de este módulo toca el catálogo.
 */

import { PRICE_RANGE, clampPriceRange, isPriceDefault } from './match.js';
import { hasFilters } from './state.js';

/** Orden de presentación de los tipos en el panel (los ausentes no se listan). */
export const TYPE_ORDER = Object.freeze([
  'Trajes',
  'Picos',
  'Accesorios mochileros',
  'Alas delta',
  'Envoltorios',
  'Gestos',
  'Compa\u00f1eros',
  'Instrumentos',
  'Pistas de improvisaci\u00f3n',
  'Coches',
  'Ruedas',
  'Calzado',
  'Estelas',
  'Rastros',
  'Potenciadores',
]);

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');
/** Debe coincidir con la transición de opacidad de #filter-overlay (style.css). */
const CLOSE_FALLBACK_MS = 300;
const BACKDROP_CLIENT = 'filterPanel';
const SVG_NS = 'http://www.w3.org/2000/svg';

/** CheckmarkIcon de la tienda oficial: anillo + cuadro amarillos, check oscuro. */
const CHECK_PATHS = Object.freeze([
  ['#f7ff19', 'M17.8,24H6.2C2.8,24,0,21.2,0,17.8V6.2C0,2.8,2.8,0,6.2,0h11.7C21.2,0,24,2.8,24,6.2v11.7 C24,21.2,21.2,24,17.8,24z M6.2,2C3.9,2,2,3.9,2,6.2v11.7C2,20.1,3.9,22,6.2,22h11.7c2.3,0,4.1-1.8,4.1-4.1V6.2 C22,3.9,20.1,2,17.8,2H6.2z'],
  ['#f7ff19', 'M4,6.9C4,5.3,5.3,4,6.9,4h10.2C18.7,4,20,5.3,20,6.9v10.2c0,1.6-1.3,2.9-2.9,2.9H6.9C5.3,20,4,18.7,4,17.1V6.9z'],
  ['#18181b', 'M17,9.6l-6.5,6.7l-3.6-3.6l1.5-1.5c0.3-0.3,0.7-0.3,1,0l1,1l3.9-4.1c0.3-0.3,0.7-0.3,1,0L17,9.6z'],
]);

/**
 * @param {string} type
 * @param {number} count
 * @param {boolean} checked
 */
function buildTypeOption(type, count, checked) {
  const li = document.createElement('li');
  const label = document.createElement('label');
  label.className = 'custom-checkbox flex items-center gap-3 text-white py-1';
  const input = document.createElement('input');
  input.type = 'checkbox';
  input.value = type;
  input.checked = checked;
  const visual = document.createElement('div');
  visual.className = 'checkbox-visual';
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  for (const [fill, d] of CHECK_PATHS) {
    const path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('fill', fill);
    path.setAttribute('d', d);
    svg.appendChild(path);
  }
  visual.appendChild(svg);
  const text = document.createElement('span');
  text.textContent = `${type} [${count}]`;
  label.append(input, visual, text);
  li.appendChild(label);
  return li;
}

/**
 * @param {number} min
 * @param {number} max
 */
function formatPriceLabel(min, max) {
  const minText = min.toLocaleString('es-ES');
  const maxText = max.toLocaleString('es-ES');
  return max >= PRICE_RANGE.MAX ? `${minText} – ${maxText} y más` : `${minText} – ${maxText} paVos`;
}

/**
 * @param {object} options
 * @param {import('./state.js').FilterStore} options.store
 * @param {ReturnType<import('./apply.js').createCatalogApplier>} options.applier  fuente de los conteos por tipo
 * @param {Document} [options.doc]
 */
export function mountFilterUi({ store, applier, doc = document }) {
  const overlay = doc.getElementById('filter-overlay');
  const panel = doc.getElementById('filter-panel');
  const typeList = doc.getElementById('filter-type-list');
  const minInput = doc.getElementById('price-filter-min');
  const maxInput = doc.getElementById('price-filter-max');
  const priceLabel = doc.getElementById('price-range-label');
  const applyBtn = doc.getElementById('apply-filters-btn');
  const clearBtn = doc.getElementById('clear-filters-btn');
  const closeBtn = doc.getElementById('close-filters-btn');
  if (!overlay || !panel || !typeList || !minInput || !maxInput || !priceLabel || !applyBtn || !clearBtn || !closeBtn) {
    return null;
  }

  const slider = minInput.closest('.price-range-slider');
  const openButtons = [
    { el: doc.getElementById('filter-btn-mobile'), desktop: false },
    { el: doc.getElementById('filter-btn-desktop'), desktop: true },
  ].filter((entry) => entry.el);
  const clearWidgets = [
    doc.getElementById('desktop-clear-filter-widget'),
    doc.getElementById('mobile-clear-filter-widget'),
  ].filter(Boolean);
  const widgetClearButtons = [doc.getElementById('desktop-clear-btn'), doc.getElementById('mobile-clear-btn')].filter(Boolean);
  const badgeButtons = Array.from(doc.querySelectorAll('.filter-button'));

  const controller = new AbortController();
  const { signal } = controller;
  const win = doc.defaultView;

  let isOpen = false;
  let closeTimer = 0;
  /** @type {HTMLElement | null} */
  let lastTrigger = null;
  let listSignature = '';

  /** Borrador editable del panel: se confirma con "Ver resultados". */
  const draft = { types: new Set(), priceMin: PRICE_RANGE.MIN, priceMax: PRICE_RANGE.MAX };

  const draftHasFilters = () => draft.types.size > 0 || !isPriceDefault(draft);

  // ── Sincronización de vistas ───────────────────────────────────────────

  const syncPanelButtons = () => {
    const active = draftHasFilters();
    applyBtn.textContent = active ? 'Ver Resultados' : 'Mostrar todos los artículos';
    clearBtn.disabled = !active;
  };

  const syncPriceControls = () => {
    minInput.value = String(draft.priceMin);
    maxInput.value = String(draft.priceMax);
    priceLabel.textContent = formatPriceLabel(draft.priceMin, draft.priceMax);
    if (slider) {
      const span = PRICE_RANGE.MAX - PRICE_RANGE.MIN;
      slider.style.setProperty('--price-range-min-percent', `${((draft.priceMin - PRICE_RANGE.MIN) / span) * 100}%`);
      slider.style.setProperty('--price-range-max-percent', `${((draft.priceMax - PRICE_RANGE.MIN) / span) * 100}%`);
    }
  };

  const syncTypeChecks = () => {
    for (const input of typeList.querySelectorAll('input[type="checkbox"]')) {
      input.checked = draft.types.has(input.value);
    }
  };

  const loadDraft = (state) => {
    draft.types = new Set(state.types);
    draft.priceMin = state.priceMin;
    draft.priceMax = state.priceMax;
    syncTypeChecks();
    syncPriceControls();
    syncPanelButtons();
  };

  const syncChrome = (state) => {
    const active = hasFilters(state);
    for (const btn of badgeButtons) btn.classList.toggle('has-active-filters', active);
    for (const widget of clearWidgets) widget.classList.toggle('hidden', !active);
  };

  const renderTypeList = ({ typeCounts }) => {
    const rows = TYPE_ORDER.filter((type) => typeCounts.get(type) > 0);
    const signature = rows.map((type) => `${type}:${typeCounts.get(type)}`).join('|');
    if (signature === listSignature) return;
    listSignature = signature;
    const fragment = doc.createDocumentFragment();
    for (const type of rows) fragment.appendChild(buildTypeOption(type, typeCounts.get(type), draft.types.has(type)));
    typeList.replaceChildren(fragment);
  };

  // ── Precio ─────────────────────────────────────────────────────────────

  const setDraftPrice = (min, max, anchor) => {
    const clamped = clampPriceRange(min, max, anchor);
    draft.priceMin = clamped.min;
    draft.priceMax = clamped.max;
    syncPriceControls();
    syncPanelButtons();
  };

  const onMinInput = () => setDraftPrice(minInput.value, draft.priceMax, 'min');
  const onMaxInput = () => setDraftPrice(draft.priceMin, maxInput.value, 'max');

  const valueFromPointer = (clientX) => {
    const rect = slider.getBoundingClientRect();
    if (rect.width <= 0) return PRICE_RANGE.MIN;
    const ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
    const raw = PRICE_RANGE.MIN + ratio * (PRICE_RANGE.MAX - PRICE_RANGE.MIN);
    return Math.round(raw / PRICE_RANGE.STEP) * PRICE_RANGE.STEP;
  };

  /**
   * Arrastre por puntero sobre el contenedor: en cada pointerdown se elige el
   * handle más cercano (en empate decide la dirección del gesto) y se mueve
   * hasta pointerup/pointercancel. Más fiable que los pseudo-elementos de los
   * thumbs cuando ambos se superponen.
   */
  const onSliderPointerDown = (event) => {
    if (!event.isPrimary || event.button !== 0) return;
    const value = valueFromPointer(event.clientX);
    const distMin = Math.abs(value - draft.priceMin);
    const distMax = Math.abs(value - draft.priceMax);
    const anchor = distMin < distMax || (distMin === distMax && value <= draft.priceMin) ? 'min' : 'max';
    const target = anchor === 'min' ? minInput : maxInput;

    event.preventDefault();
    slider.setPointerCapture(event.pointerId);
    target.focus({ preventScroll: true });

    const move = (x) => (anchor === 'min' ? setDraftPrice(x, draft.priceMax, 'min') : setDraftPrice(draft.priceMin, x, 'max'));
    move(value);

    const drag = new AbortController();
    const stop = () => drag.abort();
    slider.addEventListener('pointermove', (e) => move(valueFromPointer(e.clientX)), { signal: drag.signal });
    slider.addEventListener('pointerup', stop, { signal: drag.signal });
    slider.addEventListener('pointercancel', stop, { signal: drag.signal });
    signal.addEventListener('abort', stop, { once: true });
  };

  // ── Apertura / cierre ──────────────────────────────────────────────────

  const trapFocus = (event) => {
    if (!isOpen) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }
    if (event.key !== 'Tab') return;
    const focusables = Array.from(panel.querySelectorAll(FOCUSABLE));
    if (!focusables.length) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = doc.activeElement;
    if (event.shiftKey ? active === first || !panel.contains(active) : active === last || !panel.contains(active)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus({ preventScroll: true });
    }
  };

  function open(desktop) {
    if (isOpen || doc.body.dataset.view !== 'viaregalo') return;
    isOpen = true;
    if (closeTimer) clearTimeout(closeTimer);
    closeTimer = 0;
    lastTrigger = doc.activeElement instanceof HTMLElement ? doc.activeElement : null;

    loadDraft(store.get());

    overlay.hidden = false;
    win.Backdrop?.acquire(BACKDROP_CLIENT, overlay, close, { lockScroll: !desktop });
    win.requestAnimationFrame(() => {
      if (!isOpen) return;
      overlay.classList.add('is-visible');
      const initial = panel.querySelector(FOCUSABLE) || closeBtn;
      initial.focus({ preventScroll: true });
    });
  }

  function close() {
    if (!isOpen) return;
    isOpen = false;
    overlay.classList.remove('is-visible');
    win.Backdrop?.release(BACKDROP_CLIENT);
    closeTimer = setTimeout(() => {
      closeTimer = 0;
      overlay.hidden = true;
    }, CLOSE_FALLBACK_MS);
    if (lastTrigger && lastTrigger.isConnected) lastTrigger.focus({ preventScroll: true });
    lastTrigger = null;
  }

  const applyDraft = () => {
    store.set({ types: Array.from(draft.types), priceMin: draft.priceMin, priceMax: draft.priceMax });
    close();
  };

  const clearAll = () => {
    store.clearFilters();
    loadDraft(store.get());
  };

  // ── Wiring ─────────────────────────────────────────────────────────────

  for (const { el, desktop } of openButtons) el.addEventListener('click', () => open(desktop), { signal });
  for (const el of widgetClearButtons) el.addEventListener('click', clearAll, { signal });
  closeBtn.addEventListener('click', close, { signal });
  applyBtn.addEventListener('click', applyDraft, { signal });
  clearBtn.addEventListener('click', clearAll, { signal });
  typeList.addEventListener(
    'change',
    (event) => {
      const input = event.target;
      if (!(input instanceof HTMLInputElement) || input.type !== 'checkbox') return;
      if (input.checked) draft.types.add(input.value);
      else draft.types.delete(input.value);
      syncPanelButtons();
    },
    { signal },
  );
  minInput.addEventListener('input', onMinInput, { signal });
  minInput.addEventListener('change', onMinInput, { signal });
  maxInput.addEventListener('input', onMaxInput, { signal });
  maxInput.addEventListener('change', onMaxInput, { signal });
  if (slider) slider.addEventListener('pointerdown', onSliderPointerDown, { signal });
  doc.addEventListener('keydown', trapFocus, { signal });

  doc.addEventListener(
    'banano:view-change',
    () => {
      if (doc.body.dataset.view !== 'viaregalo') close();
    },
    { signal },
  );

  const unsubscribeStore = store.subscribe(syncChrome);
  const unsubscribeIndex = applier.onIndex(renderTypeList);

  loadDraft(store.get());
  syncChrome(store.get());

  return {
    open,
    close,
    destroy() {
      close();
      if (closeTimer) clearTimeout(closeTimer);
      unsubscribeStore();
      unsubscribeIndex();
      controller.abort();
    },
  };
}

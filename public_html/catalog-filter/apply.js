/**
 * catalog-filter/apply.js — Proyección del estado sobre un catálogo ya
 * renderizado, sin mutaciones destructivas.
 *
 * Invariantes que este módulo garantiza:
 *  - Nunca crea, mueve, sustituye ni elimina nodos de tarjeta o sección; sólo
 *    alterna el atributo `hidden` de la "tile" (celda de grid o tarjeta de
 *    carrusel) y de la sección.
 *  - Sólo escribe en el DOM cuando el valor cambia (cero escrituras idempotentes).
 *  - Todo el trabajo pendiente (reindexado + aplicación) se consolida en un
 *    único requestAnimationFrame por instancia; nunca hay dos frames
 *    intermedios con estados parciales.
 *  - El índice se lee del DOM una vez por cambio real del catálogo; los cambios
 *    de estado no vuelven a leer layout ni atributos.
 */

import { cardMatches, deriveCriteria, normalizeText } from './match.js';

/**
 * Contrato de facetas declaradas por cada tarjeta:
 *  - nombre: `data-filter-name` o, en su defecto, el texto del primer `h3`.
 *  - tipos:  `data-filter-types` separados por `|` (opcional).
 *  - precio: `data-filter-price` en paVos (opcional).
 * @param {HTMLElement} card
 * @returns {import('./match.js').Facets}
 */
export function readCardFacets(card) {
  const name = card.dataset.filterName ?? card.querySelector('h3')?.textContent ?? '';
  const rawTypes = card.dataset.filterTypes;
  const types = rawTypes ? rawTypes.split('|').filter(Boolean) : [];
  const price = Number.parseFloat(card.dataset.filterPrice ?? '');
  return { text: normalizeText(name), types, price };
}

/**
 * Escribe `hidden` sólo si cambia. Devuelve true si hubo escritura.
 * @param {HTMLElement} el
 * @param {boolean} hidden
 */
function setHidden(el, hidden) {
  if (el.hidden === hidden) return false;
  el.hidden = hidden;
  return true;
}

/**
 * @typedef {object} ApplierOptions
 * @property {HTMLElement} root  contenedor del catálogo (las secciones son descendientes directos)
 * @property {string} sectionSelector  selector relativo a `root`; admite `:scope`
 * @property {string} cardSelector
 * @property {string} [trackSelector]  contenedores donde la tile es la propia tarjeta
 * @property {(section: HTMLElement) => boolean} [isAuxSection]  secciones ocultas siempre que haya criterios activos
 * @property {(state: import('./state.js').FilterState) => object} [select]  subconjunto de estado que gobierna este catálogo
 * @property {string} [emptyMessage]
 * @property {string} [emptyClassName]
 * @property {() => boolean} [canRestoreScroll]  si se define, se memoriza scrollY al activar criterios y se restaura al desactivarlos
 * @property {(section: HTMLElement, hidden: boolean) => void} [onSectionHidden]  se invoca sólo cuando cambia la visibilidad de una sección
 * @property {typeof requestAnimationFrame} [raf]
 * @property {typeof cancelAnimationFrame} [caf]
 */

/**
 * @typedef {object} IndexSummary
 * @property {number} cards
 * @property {ReadonlyMap<string, number>} typeCounts
 */

/**
 * @param {ApplierOptions} options
 */
export function createCatalogApplier(options) {
  const {
    root,
    sectionSelector,
    cardSelector,
    trackSelector = null,
    isAuxSection = () => false,
    select = (state) => state,
    emptyMessage = 'No se encontraron resultados.',
    emptyClassName = 'text-center empty-state-text my-16',
    canRestoreScroll = null,
    onSectionHidden = null,
    raf = (cb) => window.requestAnimationFrame(cb),
    caf = (id) => window.cancelAnimationFrame(id),
  } = options;

  /** @type {WeakMap<HTMLElement, import('./match.js').Facets>} */
  const facetsCache = new WeakMap();
  /** @type {{ el: HTMLElement, aux: boolean, tiles: { card: HTMLElement, facets: import('./match.js').Facets }[] }[]} */
  let sections = [];
  /** @type {IndexSummary} */
  let summary = { cards: 0, typeCounts: new Map() };
  /** @type {Set<(summary: IndexSummary) => void>} */
  const indexListeners = new Set();

  /** @type {HTMLElement | null} */
  let emptyEl = null;
  let state = null;
  let indexDirty = true;
  let frame = 0;
  let destroyed = false;
  let wasActive = false;
  /** @type {number | null} scrollY memorizado al activar criterios en la vista actual */
  let savedScrollY = null;

  const tileOf = (card) => {
    const parent = card.parentElement;
    if (!parent || parent === root) return card;
    if (trackSelector && parent.matches(trackSelector)) return card;
    return parent;
  };

  const facetsOf = (card) => {
    let facets = facetsCache.get(card);
    if (!facets) {
      facets = readCardFacets(card);
      facetsCache.set(card, facets);
    }
    return facets;
  };

  function reindex() {
    const typeCounts = new Map();
    let cards = 0;
    sections = Array.from(root.querySelectorAll(sectionSelector), (el) => {
      const aux = isAuxSection(el);
      const tiles = Array.from(el.querySelectorAll(cardSelector), (card) => {
        const facets = facetsOf(card);
        if (!aux) {
          cards += 1;
          for (const type of facets.types) typeCounts.set(type, (typeCounts.get(type) || 0) + 1);
        }
        return { card, facets };
      });
      return { el, aux, tiles };
    });
    summary = { cards, typeCounts };
    indexDirty = false;
    for (const listener of Array.from(indexListeners)) listener(summary);
  }

  function ensureEmptyEl() {
    if (!emptyEl) {
      emptyEl = document.createElement('div');
      emptyEl.className = emptyClassName;
      emptyEl.setAttribute('role', 'status');
      emptyEl.textContent = emptyMessage;
      emptyEl.hidden = true;
    }
    if (emptyEl.parentNode !== root) root.appendChild(emptyEl);
    return emptyEl;
  }

  function apply() {
    const criteria = deriveCriteria(select(state));
    let anyVisible = false;

    for (const section of sections) {
      let hideSection = criteria.active;
      if (!section.aux) {
        let visible = 0;
        for (const tile of section.tiles) {
          const match = cardMatches(tile.facets, criteria);
          setHidden(tileOf(tile.card), !match);
          if (match) visible += 1;
        }
        if (visible) anyVisible = true;
        hideSection = criteria.active && visible === 0;
      }
      if (setHidden(section.el, hideSection) && onSectionHidden) onSectionHidden(section.el, hideSection);
    }

    const showEmpty = criteria.active && !anyVisible && summary.cards > 0;
    if (showEmpty) setHidden(ensureEmptyEl(), false);
    else if (emptyEl?.isConnected) setHidden(emptyEl, true);

    if (canRestoreScroll && criteria.active !== wasActive) {
      if (criteria.active) {
        savedScrollY = canRestoreScroll() ? window.scrollY : null;
      } else if (savedScrollY !== null && canRestoreScroll()) {
        window.scrollTo(0, savedScrollY);
      }
    }
    wasActive = criteria.active;
  }

  function commit() {
    frame = 0;
    if (destroyed || !state) return;
    if (indexDirty) reindex();
    apply();
  }

  function schedule() {
    if (frame || destroyed) return;
    frame = raf(commit);
  }

  return {
    /** El catálogo cambió realmente (render/refresh): releer índice y reaplicar. */
    invalidateIndex() {
      indexDirty = true;
      schedule();
    },
    /** @param {import('./state.js').FilterState} next */
    setState(next) {
      state = next;
      schedule();
    },
    /** Notifica el resumen del índice tras cada reindexado (y de inmediato si ya existe). */
    onIndex(listener) {
      indexListeners.add(listener);
      if (!indexDirty) listener(summary);
      return () => {
        indexListeners.delete(listener);
      };
    },
    getSummary: () => summary,
    /** Ejecuta el trabajo pendiente ahora (tests / integraciones sincrónicas). */
    flush() {
      if (frame) {
        caf(frame);
        frame = 0;
      }
      commit();
    },
    destroy() {
      destroyed = true;
      if (frame) caf(frame);
      frame = 0;
      indexListeners.clear();
      sections = [];
    },
  };
}

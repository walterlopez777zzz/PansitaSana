/**
 * catalog-filter/state.js — Única fuente de verdad del buscador y los filtros.
 *
 * El estado es un objeto inmutable; cada `set()` produce una instancia nueva
 * sólo si algo cambió y notifica a los suscriptores de forma sincrónica y
 * determinista (en orden de suscripción). Quién pinta y cuándo (rAF) es
 * responsabilidad de apply.js, no de aquí.
 */

import { PRICE_RANGE, clampPriceRange } from './match.js';

/**
 * @typedef {object} FilterState
 * @property {string} query           texto crudo recortado
 * @property {readonly string[]} types  tipos seleccionados, ordenados y únicos
 * @property {number} priceMin
 * @property {number} priceMax
 */

/** @type {FilterState} */
export const INITIAL_STATE = Object.freeze({
  query: '',
  types: Object.freeze([]),
  priceMin: PRICE_RANGE.MIN,
  priceMax: PRICE_RANGE.MAX,
});

/**
 * @param {unknown} types
 * @returns {readonly string[]}
 */
function normalizeTypes(types) {
  const unique = new Set();
  for (const type of types || []) {
    const name = String(type).trim();
    if (name) unique.add(name);
  }
  return Object.freeze(Array.from(unique).sort());
}

/**
 * @param {readonly string[]} a
 * @param {readonly string[]} b
 */
function sameTypes(a, b) {
  if (a.length !== b.length) return false;
  for (let i = 0; i < a.length; i += 1) if (a[i] !== b[i]) return false;
  return true;
}

/**
 * Aplica un parche parcial y devuelve el estado normalizado. Función pura.
 * @param {FilterState} state
 * @param {Partial<{ query: unknown, types: unknown, priceMin: unknown, priceMax: unknown }>} patch
 * @returns {FilterState}
 */
export function reduce(state, patch) {
  const query = patch.query === undefined ? state.query : String(patch.query ?? '').trim();
  const types = patch.types === undefined ? state.types : normalizeTypes(patch.types);
  const { min: priceMin, max: priceMax } = clampPriceRange(
    patch.priceMin === undefined ? state.priceMin : patch.priceMin,
    patch.priceMax === undefined ? state.priceMax : patch.priceMax,
    patch.priceMin !== undefined && patch.priceMax === undefined ? 'min' : 'max',
  );
  if (
    query === state.query &&
    priceMin === state.priceMin &&
    priceMax === state.priceMax &&
    sameTypes(types, state.types)
  ) {
    return state;
  }
  return Object.freeze({ query, types, priceMin, priceMax });
}

/** @param {FilterState} state */
export function hasSearch(state) {
  return state.query !== '';
}

/** @param {FilterState} state */
export function hasFilters(state) {
  return state.types.length > 0 || state.priceMin !== PRICE_RANGE.MIN || state.priceMax !== PRICE_RANGE.MAX;
}

/**
 * @typedef {object} FilterStore
 * @property {() => FilterState} get
 * @property {(patch: Partial<FilterState>) => boolean} set  true si cambió
 * @property {() => boolean} clearFilters  restablece tipos y precio
 * @property {() => boolean} clearSearch
 * @property {(listener: (state: FilterState, previous: FilterState) => void) => () => void} subscribe
 */

/**
 * @param {FilterState} [initial]
 * @returns {FilterStore}
 */
export function createFilterStore(initial = INITIAL_STATE) {
  let state = reduce(INITIAL_STATE, initial);
  /** @type {Set<(state: FilterState, previous: FilterState) => void>} */
  const listeners = new Set();

  const set = (patch) => {
    const next = reduce(state, patch);
    if (next === state) return false;
    const previous = state;
    state = next;
    for (const listener of Array.from(listeners)) listener(state, previous);
    return true;
  };

  return {
    get: () => state,
    set,
    clearFilters: () => set({ types: [], priceMin: PRICE_RANGE.MIN, priceMax: PRICE_RANGE.MAX }),
    clearSearch: () => set({ query: '' }),
    subscribe(listener) {
      listeners.add(listener);
      return () => {
        listeners.delete(listener);
      };
    },
  };
}

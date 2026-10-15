/**
 * catalog-filter/match.js — Reglas puras de coincidencia.
 *
 * Sin DOM, sin estado: cada función es determinista y se prueba de forma
 * aislada. El resto del sistema (apply.js) sólo decide *dónde* aplicar el
 * resultado; aquí se decide *qué* coincide.
 */

/** Rango del deslizador de precio (paVos). `MAX` significa "sin tope". */
export const PRICE_RANGE = Object.freeze({ MIN: 200, MAX: 3000, STEP: 50, MIN_GAP: 50 });

const DIACRITICS_RE = /[\u0300-\u036f]/g;
const NON_ALNUM_RE = /[^a-z0-9\s]/g;
const WHITESPACE_RE = /\s+/g;
const REPEATED_CHAR_RE = /(.)\1+/g;

/**
 * Sustituciones fonéticas del español aplicadas tras quitar tildes (orden
 * relevante). La ñ ya se ha reducido a n por la descomposición NFD, de modo
 * que "muneco" y "muñeco" convergen.
 */
const PHONETIC_RULES = Object.freeze([
  [/v/g, 'b'],
  [/\bh/g, ''],
  [/([aeiou])h/g, '$1'],
  [/ll/g, 'y'],
  [/z/g, 's'],
  [/ce/g, 'se'],
  [/ci/g, 'si'],
  [/ge/g, 'je'],
  [/gi/g, 'ji'],
  [/qu/g, 'k'],
]);

/**
 * Normaliza texto para comparación tolerante: minúsculas, sin tildes ni
 * signos, fonética española y letras repetidas colapsadas.
 * @param {unknown} text
 * @returns {string}
 */
export function normalizeText(text) {
  if (text == null) return '';
  let s = String(text)
    .toLowerCase()
    .normalize('NFD')
    .replace(DIACRITICS_RE, '')
    .replace(NON_ALNUM_RE, '')
    .replace(WHITESPACE_RE, ' ')
    .trim();
  for (const [pattern, replacement] of PHONETIC_RULES) s = s.replace(pattern, replacement);
  return s.replace(REPEATED_CHAR_RE, '$1');
}

/**
 * Convierte la consulta del usuario en tokens normalizados no vacíos.
 * @param {unknown} query
 * @returns {string[]}
 */
export function tokenize(query) {
  return normalizeText(query).split(' ').filter(Boolean);
}

/**
 * AND de subcadenas: todos los tokens deben aparecer en el texto normalizado.
 * Una lista vacía de tokens coincide siempre.
 * @param {string} normalizedText
 * @param {readonly string[]} tokens
 */
export function matchesTokens(normalizedText, tokens) {
  for (let i = 0; i < tokens.length; i += 1) {
    if (!normalizedText.includes(tokens[i])) return false;
  }
  return true;
}

/**
 * Ajusta un par (min, max) al rango permitido respetando el hueco mínimo.
 * `moved` indica qué extremo movió el usuario: ese es el que se detiene
 * contra el otro (los tiradores no se empujan entre sí).
 * @param {number} min
 * @param {number} max
 * @param {'min' | 'max'} [moved]
 * @returns {{ min: number, max: number }}
 */
export function clampPriceRange(min, max, moved = 'max') {
  const { MIN, MAX, MIN_GAP, STEP } = PRICE_RANGE;
  const snap = (value, fallback) => {
    const n = Number(value);
    if (!Number.isFinite(n)) return fallback;
    return Math.min(MAX, Math.max(MIN, Math.round(n / STEP) * STEP));
  };
  let lo = snap(min, MIN);
  let hi = snap(max, MAX);
  if (hi - lo < MIN_GAP) {
    if (moved === 'min') {
      lo = Math.max(MIN, Math.min(lo, hi - MIN_GAP));
      hi = Math.max(hi, lo + MIN_GAP);
    } else {
      hi = Math.min(MAX, Math.max(hi, lo + MIN_GAP));
      lo = Math.min(lo, hi - MIN_GAP);
    }
  }
  return { min: lo, max: hi };
}

/** @param {{ priceMin: number, priceMax: number }} range */
export function isPriceDefault(range) {
  return range.priceMin === PRICE_RANGE.MIN && range.priceMax === PRICE_RANGE.MAX;
}

/**
 * Comprueba si un precio en paVos entra en el rango. Con el tope en `MAX` el
 * rango es abierto por arriba. Precios no positivos nunca coinciden.
 * @param {number} price
 * @param {{ priceMin: number, priceMax: number }} range
 */
export function priceInRange(price, range) {
  if (!Number.isFinite(price) || price <= 0) return false;
  if (price < range.priceMin) return false;
  return range.priceMax >= PRICE_RANGE.MAX || price <= range.priceMax;
}

/**
 * Criterios ya derivados del estado, listos para evaluar muchas tarjetas.
 * @typedef {object} Criteria
 * @property {string[]} tokens
 * @property {ReadonlySet<string>} types
 * @property {{ priceMin: number, priceMax: number } | null} price  null = sin filtro de precio
 * @property {boolean} active  true si algún criterio restringe el catálogo
 */

/**
 * Deriva los criterios a partir del estado (o de un subconjunto de él).
 * @param {{ query?: string, types?: readonly string[], priceMin?: number, priceMax?: number }} state
 * @returns {Criteria}
 */
export function deriveCriteria(state) {
  const tokens = tokenize(state.query);
  const types = new Set(state.types || []);
  const priceMin = state.priceMin ?? PRICE_RANGE.MIN;
  const priceMax = state.priceMax ?? PRICE_RANGE.MAX;
  const price = isPriceDefault({ priceMin, priceMax }) ? null : { priceMin, priceMax };
  return { tokens, types, price, active: tokens.length > 0 || types.size > 0 || price !== null };
}

/**
 * Facetas precalculadas de una tarjeta.
 * @typedef {object} Facets
 * @property {string} text   nombre normalizado
 * @property {readonly string[]} types  tipos (plural) del artículo
 * @property {number} price  precio en paVos (NaN si no aplica)
 */

/**
 * Evalúa una tarjeta contra los criterios. Todos los criterios activos son
 * conjuntivos (AND); dentro de "tipos" basta con que uno coincida (OR).
 * @param {Facets} facets
 * @param {Criteria} criteria
 */
export function cardMatches(facets, criteria) {
  if (!criteria.active) return true;
  if (criteria.tokens.length && !matchesTokens(facets.text, criteria.tokens)) return false;
  if (criteria.types.size) {
    let hit = false;
    for (let i = 0; i < facets.types.length && !hit; i += 1) hit = criteria.types.has(facets.types[i]);
    if (!hit) return false;
  }
  if (criteria.price && !priceInRange(facets.price, criteria.price)) return false;
  return true;
}

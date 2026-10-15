/**
 * Normaliza la respuesta cruda de `api/via-cuenta.php` al contrato
 * `ViaCuentaProduct` que consume el editor.
 *
 * @typedef {Object} ViaCuentaProduct
 * @property {string} id
 * @property {string} name
 * @property {string} description
 * @property {string} category
 * @property {string} imageUrl
 * @property {boolean} active
 * @property {number} stock              -1 ilimitado · 0 agotado · >0 unidades
 * @property {number} basePriceSoles
 * @property {number|null} offerPriceSoles
 * @property {"precio_fijo"|"porcentaje"} offerKind
 * @property {string|null} expiresAt
 * @property {string|null} offerExpiresAt
 * @property {{ text: string, bg: string, fg: string }|null} label
 * @property {[number, number]} sortKey
 */

export const VIA_CUENTA_OFFER_PREFIX = "vc:";
export const VIA_CUENTA_FREE_PREFIX = "vc:free:";

const UNLIMITED_STOCK = -1;

function toTrimmedString(value) {
  return value == null ? "" : String(value).trim();
}

function toFiniteNumber(value, fallback) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : fallback;
}

function toMoney(value) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric) || numeric < 0) return null;
  return Math.round(numeric * 100) / 100;
}

function toIsoOrNull(value) {
  const text = toTrimmedString(value);
  if (!text) return null;
  const time = new Date(text).getTime();
  return Number.isFinite(time) ? new Date(time).toISOString() : null;
}

function normalizeLabel(raw) {
  if (!raw || typeof raw !== "object") return null;
  const text = toTrimmedString(raw.text);
  if (!text) return null;
  return Object.freeze({
    text,
    bg: toTrimmedString(raw.bg) || "#111111",
    fg: toTrimmedString(raw.fg) || "#FFFFFF",
  });
}

function normalizeSortKey(raw) {
  const pair = Array.isArray(raw) ? raw : [];
  return Object.freeze([
    toFiniteNumber(pair[0], Number.MAX_SAFE_INTEGER),
    toFiniteNumber(pair[1], Number.MAX_SAFE_INTEGER),
  ]);
}

/**
 * @returns {ViaCuentaProduct|null}
 */
export function normalizeViaCuentaProduct(raw) {
  if (!raw || typeof raw !== "object") return null;

  const id = toTrimmedString(raw.id);
  const name = toTrimmedString(raw.name);
  if (!id || !name) return null;

  const basePriceSoles = toMoney(raw.basePriceSoles);
  if (basePriceSoles == null) return null;

  const stock = Math.trunc(toFiniteNumber(raw.stock, UNLIMITED_STOCK));

  return Object.freeze({
    id,
    name,
    description: toTrimmedString(raw.description),
    category: toTrimmedString(raw.category),
    imageUrl: toTrimmedString(raw.imageUrl),
    active: raw.active === true || raw.active === 1 || raw.active === "1",
    stock: stock < UNLIMITED_STOCK ? UNLIMITED_STOCK : stock,
    basePriceSoles,
    offerPriceSoles: toMoney(raw.offerPriceSoles),
    offerKind: raw.offerKind === "porcentaje" ? "porcentaje" : "precio_fijo",
    expiresAt: toIsoOrNull(raw.expiresAt),
    offerExpiresAt: toIsoOrNull(raw.offerExpiresAt),
    label: normalizeLabel(raw.label),
    sortKey: normalizeSortKey(raw.sortKey),
  });
}

function compareSortKey(a, b) {
  return a.sortKey[0] - b.sortKey[0]
    || a.sortKey[1] - b.sortKey[1]
    || a.name.localeCompare(b.name, "es");
}

/**
 * Normaliza `payload.products`, descarta entradas inválidas y ordena por `sortKey`.
 * @returns {ViaCuentaProduct[]}
 */
export function normalizeViaCuentaProducts(payload) {
  const rawList = Array.isArray(payload?.products)
    ? payload.products
    : Array.isArray(payload) ? payload : [];

  const seen = new Set();
  const products = [];
  for (const raw of rawList) {
    const product = normalizeViaCuentaProduct(raw);
    if (!product || seen.has(product.id)) continue;
    seen.add(product.id);
    products.push(product);
  }
  products.sort(compareSortKey);
  return Object.freeze(products);
}

export function viaCuentaOfferId(productId) {
  return `${VIA_CUENTA_OFFER_PREFIX}${toTrimmedString(productId)}`;
}

export function isViaCuentaOfferId(offerId) {
  return String(offerId || "").startsWith(VIA_CUENTA_OFFER_PREFIX);
}

export function productIdFromOfferId(offerId) {
  const value = String(offerId || "");
  if (!value.startsWith(VIA_CUENTA_OFFER_PREFIX) || value.startsWith(VIA_CUENTA_FREE_PREFIX)) {
    return null;
  }
  const id = value.slice(VIA_CUENTA_OFFER_PREFIX.length).trim();
  return id || null;
}

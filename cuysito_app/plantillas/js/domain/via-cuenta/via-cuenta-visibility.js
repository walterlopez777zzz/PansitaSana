/**
 * Reglas de visibilidad y estado de productos Vía Cuenta (RN-3, RN-4).
 * Funciones puras: reciben el producto normalizado y, opcionalmente, un
 * instante `nowMs` para que el resultado sea determinista en tests y render.
 */

export const MANUAL_SEARCH_MIN_LENGTH = 2;

export function isSoldOut(product) {
  return Number(product?.stock) === 0;
}

export function isExpired(product, nowMs = Date.now()) {
  const iso = product?.expiresAt;
  if (!iso) return false;
  const time = new Date(iso).getTime();
  return Number.isFinite(time) && time <= nowMs;
}

export function isOfferActive(product, nowMs = Date.now()) {
  const offer = Number(product?.offerPriceSoles);
  const base = Number(product?.basePriceSoles);
  if (!Number.isFinite(offer) || !Number.isFinite(base) || offer >= base) return false;
  const until = product?.offerExpiresAt ? new Date(product.offerExpiresAt).getTime() : NaN;
  return !Number.isFinite(until) || until > nowMs;
}

/**
 * RN-3: solo productos activos y con stock aparecen en el listado sin query.
 */
export function isListedByDefault(product, nowMs = Date.now()) {
  return Boolean(product?.active) && !isSoldOut(product) && !isExpired(product, nowMs);
}

/**
 * Una query con al menos `MANUAL_SEARCH_MIN_LENGTH` caracteres habilita la
 * búsqueda manual sobre el catálogo completo (incluye inactivos y agotados).
 */
export function isManualSearchQuery(query) {
  return String(query || "").trim().length >= MANUAL_SEARCH_MIN_LENGTH;
}

export function filterListableProducts(products, query, nowMs = Date.now()) {
  const list = Array.isArray(products) ? products : [];
  if (isManualSearchQuery(query)) return list;
  return list.filter((product) => isListedByDefault(product, nowMs));
}

/**
 * Porcentaje de descuento entero (1..99) o `null` si no hay oferta activa.
 */
export function resolveDiscountPercent(product, nowMs = Date.now()) {
  if (!isOfferActive(product, nowMs)) return null;
  const base = Number(product.basePriceSoles);
  const offer = Number(product.offerPriceSoles);
  if (base <= 0) return null;
  const percent = Math.round((1 - offer / base) * 100);
  return percent >= 1 && percent <= 99 ? percent : null;
}

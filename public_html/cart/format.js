/**
 * Formateo y normalización de datos del carrito (puro, sin DOM).
 */

const IMAGE_CDN = 'https://wsrv.nl/?url=';
const SAFE_PROTOCOLS = new Set(['http:', 'https:']);
const HEX_COLOR = /^#[0-9a-f]{6}$/i;
/** Color crudo de la API (`rrggbb` o `rrggbbaa`, con o sin `#`); sólo se conserva el RGB. */
const API_COLOR = /^#?([0-9a-f]{6})(?:[0-9a-f]{2})?$/i;

/** Presentación neutra cuando la línea no trae metadatos de tarjeta. */
const NEUTRAL_TILE = Object.freeze({
  itemType: null,
  itemId: null,
  isCarOffer: false,
  isDynamicBundle: false,
  background: null,
});

/**
 * Importe en soles con dos decimales: "S/ 12.50".
 * @param {unknown} amount
 * @returns {string}
 */
export function formatMoney(amount) {
  const n = Number(amount);
  return `S/ ${Number.isFinite(n) ? n.toFixed(2) : '0.00'}`;
}

/**
 * Texto del contador: "1 artículo" / "3 artículos".
 * @param {number} count
 * @returns {string}
 */
export function formatCount(count) {
  const n = Math.max(0, Math.trunc(Number(count) || 0));
  return `${n} ${n === 1 ? 'artículo' : 'artículos'}`;
}

/**
 * Texto compacto para la insignia del disparador. Por encima de 99 se
 * muestra "99+" para no romper el círculo.
 * @param {number} count
 * @returns {string}
 */
export function formatBadge(count) {
  const n = Math.max(0, Math.trunc(Number(count) || 0));
  return n > 99 ? '99+' : String(n);
}

/**
 * Devuelve la URL sólo si es absoluta y http(s); cualquier otra cosa
 * (javascript:, data:, relativa, basura) se descarta.
 * @param {unknown} value
 * @returns {string|null}
 */
export function safeHttpUrl(value) {
  if (typeof value !== 'string' || value.length === 0) return null;
  try {
    const url = new URL(value);
    return SAFE_PROTOCOLS.has(url.protocol) ? url.href : null;
  } catch {
    return null;
  }
}

/**
 * Miniatura optimizada vía CDN (webp, ancho fijo). Las URLs ya servidas por
 * el CDN o inválidas se devuelven tal cual / null.
 * @param {unknown} value
 * @param {number} [width]
 * @returns {string|null}
 */
export function thumbnailUrl(value, width = 192) {
  const url = safeHttpUrl(value);
  if (url === null) return null;
  if (url.startsWith(IMAGE_CDN)) return url;
  return `${IMAGE_CDN}${encodeURIComponent(url)}&w=${width}&q=82&output=webp`;
}

/**
 * @param {unknown} value
 * @returns {string|null}
 */
export function safeHexColor(value) {
  return typeof value === 'string' && HEX_COLOR.test(value) ? value.toLowerCase() : null;
}

/**
 * `#rrggbb` a partir de un color crudo de la API; null si no es válido.
 * @param {unknown} value
 * @returns {string|null}
 */
function apiColorToHex(value) {
  const match = typeof value === 'string' ? API_COLOR.exec(value) : null;
  return match === null ? null : `#${match[1].toLowerCase()}`;
}

/**
 * Fondo de la caja de la tarjeta a partir de `entry.colors`, con la misma
 * receta que el storefront (worker `generateBackgroundStyle`): degradado
 * vertical color1 → color3 → color2 en 0 / 65 / 100 %, color plano si sólo
 * hay uno y null (fondo neutro de la hoja de estilos) si no hay ninguno.
 * @param {unknown} colors
 * @returns {string|null}  Valor CSS para `background`.
 */
export function tileBackground(colors) {
  if (!colors || typeof colors !== 'object') return null;
  const { color1, color2, color3 } = /** @type {Record<string, unknown>} */ (colors);
  const stops = [color1, color3, color2].map(apiColorToHex).filter((hex) => hex !== null);
  if (stops.length >= 2) {
    return `linear-gradient(180deg, ${stops[0]} 0%, ${stops[1]} 65%, ${stops[2] ?? stops[1]} 100%)`;
  }
  return stops[0] ?? null;
}

/**
 * Presentación 1×1 de la tarjeta original: encuadre por tipo/objeto y
 * fondo. Acepta el `card` publicado por cart_manager.php (snake_case) y
 * degrada a la presentación neutra si faltan datos.
 * @param {unknown} colors
 * @param {unknown} card
 * @returns {import('./store.js').CartTile}
 */
export function toCartTile(colors, card) {
  const meta = card && typeof card === 'object' ? /** @type {Record<string, unknown>} */ (card) : {};
  const tile = {
    itemType: toText(meta.item_type),
    itemId: toText(meta.item_id),
    isCarOffer: meta.is_car_offer === true,
    isDynamicBundle: meta.is_dynamic_bundle === true,
    background: tileBackground(colors),
  };
  const isNeutral = tile.itemType === null && tile.itemId === null && !tile.isCarOffer
    && !tile.isDynamicBundle && tile.background === null;
  return isNeutral ? NEUTRAL_TILE : Object.freeze(tile);
}

/**
 * @param {unknown} value
 * @returns {number}
 */
function toMoney(value) {
  const n = Number(value);
  return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) / 100 : 0;
}

/**
 * @param {unknown} value
 * @returns {string|null}
 */
function toText(value) {
  if (typeof value !== 'string') return null;
  const text = value.trim();
  return text.length > 0 ? text : null;
}

/**
 * Identificador: acepta cadenas no vacías y enteros (ids numéricos de
 * productos estáticos) y los normaliza a texto.
 * @param {unknown} value
 * @returns {string|null}
 */
function toId(value) {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return String(value);
  return toText(value);
}

/**
 * Normaliza una línea tal como la publica cart_manager.php a la forma
 * interna `CartLine`. Devuelve null si no hay offerId utilizable.
 *
 * @param {Record<string, unknown>|null|undefined} raw
 * @returns {import('./store.js').CartLine|null}
 */
export function toCartLine(raw) {
  if (!raw || typeof raw !== 'object') return null;
  const offerId = toId(raw.offerId);
  if (offerId === null) return null;

  const unitPrice = toMoney(raw.price);
  const unitPriceBase = Math.max(toMoney(raw.price_base), unitPrice);
  const rarityId = toText(raw.rarity);
  const expires = Number(raw.expires_at_timestamp);
  const card = raw.card && typeof raw.card === 'object' ? /** @type {Record<string, unknown>} */ (raw.card) : null;

  return Object.freeze({
    offerId,
    name: toText(raw.name) ?? 'Artículo',
    imageUrl: safeHttpUrl(card?.image) ?? safeHttpUrl(raw.image),
    unitPrice,
    unitPriceBase,
    hasOffer: raw.has_offer === true && unitPriceBase > unitPrice,
    quantity: Math.max(1, Math.trunc(Number(raw.quantity) || 1)),
    rarity: rarityId === null
      ? null
      : Object.freeze({
        id: rarityId,
        label: toText(raw.rarity_label) ?? rarityId,
        color: safeHexColor(raw.rarity_color),
      }),
    type: toText(raw.type),
    tile: toCartTile(raw.colors, card),
    isStatic: raw.is_static === true,
    expiresAt: Number.isFinite(expires) && expires > 0 ? expires * 1000 : null,
  });
}

/**
 * Normaliza el snapshot completo de una respuesta del servidor.
 * @param {Record<string, unknown>|null|undefined} payload
 * @returns {{lines: import('./store.js').CartLine[], maxQuantity: number|undefined}}
 */
export function toCartSnapshot(payload) {
  const items = payload && Array.isArray(payload.cart_items) ? payload.cart_items : [];
  const lines = [];
  for (const item of items) {
    const line = toCartLine(/** @type {Record<string, unknown>} */ (item));
    if (line !== null) lines.push(line);
  }
  const max = payload ? Number(payload.max_quantity_per_item) : NaN;
  return { lines, maxQuantity: Number.isInteger(max) && max > 0 ? max : undefined };
}

/**
 * Construye la línea optimista que se pinta mientras el servidor confirma
 * un alta desde la página de objeto.
 *
 * @param {import('./operations.js').CartProduct} product
 * @returns {import('./store.js').CartLine}
 */
export function productToLine(product) {
  const unitPrice = toMoney(product.price);
  const unitPriceBase = Math.max(toMoney(product.priceBase), unitPrice);
  return Object.freeze({
    offerId: String(product.offerId),
    name: toText(product.name) ?? 'Artículo',
    imageUrl: safeHttpUrl(product.image),
    unitPrice,
    unitPriceBase,
    hasOffer: unitPriceBase > unitPrice,
    quantity: 1,
    rarity: null,
    type: toText(product.type),
    tile: toCartTile(product.colors, product.card),
    isStatic: product.isStatic === true,
    expiresAt: null,
  });
}

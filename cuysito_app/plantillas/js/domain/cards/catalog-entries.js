import { CARD_SOURCES } from "../collage/collage-item.js";
import { buildSearchIndex, executeSearch, getNameHighlight } from "../item-picker/search-engine.js";
import { formatSolesExact } from "../pricing/money-format.js";
import {
  isExpired,
  isListedByDefault,
  isManualSearchQuery,
  isOfferActive,
  isSoldOut,
} from "../via-cuenta/via-cuenta-visibility.js";
import { VIA_CUENTA_TYPE_LABEL } from "./card-snapshot.js";
import { draftFromShopItem, draftFromViaCuenta } from "./card-drafts.js";

/**
 * Catálogo unificado para «Añadir tarjetas»: ítems de la tienda (Vía Regalo)
 * y productos Vía Cuenta bajo un mismo contrato consultable con el motor de
 * búsqueda del selector lateral. Funciones puras; la memoización vive en el
 * controlador.
 *
 * Las entradas exponen los campos que indexa `search-engine`
 * (`displayName`, `typeLabel`, `rarityLabel`, `layoutName`, `offerId`) para
 * poder pasar el mismo array a `buildSearchIndex` sin adaptadores.
 *
 * @typedef {Object} CatalogEntry
 * @property {string} key                          `vr:<offerId>` | `vc:<productId>`
 * @property {import("../collage/collage-item.js").CardSource} source
 * @property {string} displayName
 * @property {string} typeLabel
 * @property {string} rarityLabel
 * @property {string} layoutName
 * @property {string} offerId
 * @property {string} imageUrl
 * @property {string} priceLabel                   «1.200» (V-Bucks) · «S/ 25.50»
 * @property {boolean} vbucks                      `true` si el precio es en V-Bucks
 * @property {string} statusLabel                  «» · «Agotado» · «Vencido» · «Inactivo»
 * @property {boolean} available                   `false` si agotado/vencido/inactivo
 * @property {object} payload                      shopItem | ViaCuentaProduct
 */

const EMPTY = Object.freeze([]);
const KEY_PREFIX = Object.freeze({ [CARD_SOURCES.VIA_REGALO]: "vr:", [CARD_SOURCES.VIA_CUENTA]: "vc:" });

function text(value) {
  return value == null ? "" : String(value).trim();
}

function formatVbucks(value) {
  const numeric = Number(value);
  return Number.isFinite(numeric) && numeric > 0 ? numeric.toLocaleString("es-PE") : "";
}

function viaCuentaStatus(product, nowMs) {
  if (!product.active) return "Inactivo";
  if (isSoldOut(product)) return "Agotado";
  if (isExpired(product, nowMs)) return "Vencido";
  return "";
}

function viaCuentaPrice(product, offersMode, nowMs) {
  const useOffer = offersMode && isOfferActive(product, nowMs);
  return formatSolesExact(useOffer ? product.offerPriceSoles : product.basePriceSoles);
}

/** @returns {CatalogEntry|null} */
export function entryFromShopItem(shopItem) {
  const offerId = text(shopItem?.offerId);
  if (!offerId) return null;
  return Object.freeze({
    key: `${KEY_PREFIX.viaRegalo}${offerId}`,
    source: CARD_SOURCES.VIA_REGALO,
    displayName: text(shopItem.displayName) || offerId,
    typeLabel: text(shopItem.typeLabel),
    rarityLabel: text(shopItem.rarityLabel),
    layoutName: text(shopItem.layoutName),
    offerId,
    imageUrl: text(shopItem.imageUrl),
    priceLabel: formatVbucks(shopItem.vbucks),
    vbucks: true,
    statusLabel: "",
    available: true,
    payload: shopItem,
  });
}

/**
 * @param {import("../via-cuenta/via-cuenta-normalizer.js").ViaCuentaProduct} product
 * @returns {CatalogEntry|null}
 */
export function entryFromViaCuenta(product, { offersMode = false, nowMs = Date.now() } = {}) {
  const id = text(product?.id);
  if (!id) return null;
  return Object.freeze({
    key: `${KEY_PREFIX.viaCuenta}${id}`,
    source: CARD_SOURCES.VIA_CUENTA,
    displayName: text(product.name) || id,
    typeLabel: VIA_CUENTA_TYPE_LABEL,
    rarityLabel: text(product.category),
    layoutName: text(product.category),
    offerId: id,
    imageUrl: text(product.imageUrl),
    priceLabel: viaCuentaPrice(product, offersMode, nowMs),
    vbucks: false,
    statusLabel: viaCuentaStatus(product, nowMs),
    available: isListedByDefault(product, nowMs),
    payload: product,
  });
}

/**
 * @param {{ shopItems?: ReadonlyArray<object>, viaCuentaProducts?: ReadonlyArray<object>, offersMode?: boolean, nowMs?: number }} input
 * @returns {ReadonlyArray<CatalogEntry>}
 */
export function buildCatalogEntries({ shopItems, viaCuentaProducts, offersMode = false, nowMs = Date.now() } = {}) {
  const entries = [];
  const seen = new Set();
  const push = (entry) => {
    if (!entry || seen.has(entry.key)) return;
    seen.add(entry.key);
    entries.push(entry);
  };
  for (const item of Array.isArray(shopItems) ? shopItems : EMPTY) push(entryFromShopItem(item));
  for (const product of Array.isArray(viaCuentaProducts) ? viaCuentaProducts : EMPTY) {
    push(entryFromViaCuenta(product, { offersMode, nowMs }));
  }
  return Object.freeze(entries);
}

/**
 * Índice de búsqueda sobre las entradas (mismo motor que el selector lateral).
 */
export function buildCatalogIndex(entries) {
  return buildSearchIndex(Array.isArray(entries) ? entries : EMPTY);
}

/**
 * @typedef {Object} CatalogSearchResult
 * @property {ReadonlyArray<CatalogEntry>} entries
 * @property {number} total          coincidencias antes de aplicar `limit`
 * @property {(entry: CatalogEntry) => ({ start: number, end: number }|null)} highlightFor
 * @property {(entry: CatalogEntry) => ({ score: number, matchField: string, matchType: string }|null)} matchFor
 */

/**
 * RN-3/RN-4: sin query (o con una sola letra) los productos Vía Cuenta no
 * disponibles se ocultan; con ≥2 caracteres se busca en el catálogo completo.
 *
 * @param {{ entries: ReadonlyArray<CatalogEntry>, index: ReturnType<typeof buildCatalogIndex> }} catalog
 * @param {string} query
 * @param {{ limit?: number }} [options]
 * @returns {CatalogSearchResult}
 */
export function searchCatalog(catalog, query, { limit = 60 } = {}) {
  const normalizedQuery = text(query);
  const showUnavailable = isManualSearchQuery(normalizedQuery);
  const { items, resultMap } = normalizedQuery
    ? executeSearch(catalog.index, normalizedQuery)
    : { items: catalog.entries, resultMap: null };

  const matches = showUnavailable
    ? items
    : items.filter((entry) => entry.source !== CARD_SOURCES.VIA_CUENTA || entry.available);

  const cap = Math.max(1, Math.floor(Number(limit) || 60));
  return Object.freeze({
    entries: Object.freeze(matches.length > cap ? matches.slice(0, cap) : matches),
    total: matches.length,
    highlightFor: (entry) => (resultMap
      ? getNameHighlight(resultMap.get(entry), entry.displayName, normalizedQuery)
      : null),
    matchFor: (entry) => (resultMap ? resultMap.get(entry) || null : null),
  });
}

/**
 * RN-9 (pegado de pedidos): resuelve una línea a una única entrada solo si la
 * mejor coincidencia es inequívoca (exacta/prefijo/contenida, o difusa sobre el
 * nombre). Coincidencias difusas por otros campos se consideran ambiguas.
 * @returns {CatalogEntry|null}
 */
export function resolveOrderQuery(catalog, query) {
  const normalizedQuery = text(query);
  if (!normalizedQuery) return null;
  const result = searchCatalog(catalog, normalizedQuery, { limit: 1 });
  const best = result.entries[0];
  if (!best) return null;
  const match = result.matchFor(best);
  if (!match) return null;
  return match.matchType !== "fuzzy" || match.matchField === "name" ? best : null;
}

/**
 * Draft insertable a partir de una entrada (RN-5/RN-6 para Vía Cuenta).
 * @param {CatalogEntry} entry
 * @param {{ offersMode?: boolean, aspectRatio?: number|null, nowMs?: number }} [options]
 */
export function draftFromCatalogEntry(entry, options = {}) {
  if (!entry) return null;
  return entry.source === CARD_SOURCES.VIA_CUENTA
    ? draftFromViaCuenta(entry.payload, options)
    : draftFromShopItem(entry.payload);
}

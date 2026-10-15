import { createDefaultCollageItemStyle } from "./collage-item-style.js";
import { createDefaultCollageItemDiscount } from "./collage-discount.js";
import { normalizeDiscountConfig } from "../pricing/discounts.js";
import { parseTileSizeWidthUnits } from "../shop/shop-tile-size.js";
import { isCardId } from "../cards/card-id.js";
import { normalizeCardSnapshot, normalizeCardBadges } from "../cards/card-snapshot.js";
import { normalizeCardMedia, TILE_MEDIA, DEFAULT_COVER_MEDIA } from "../cards/card-media.js";
import { normalizeCardPricing, VBUCKS_PRICING } from "../cards/card-pricing.js";
import { isViaCuentaOfferId } from "../via-cuenta/via-cuenta-normalizer.js";

/**
 * Normalización pura y memoizada (por referencia) de una tarjeta de
 * Collage/Carrito. Nunca genera identificadores aleatorios: las tarjetas
 * legacy sin `cardId` conservan `cardId: null` hasta que `ensureCardIds`
 * las migra en la capa de carga.
 *
 * @typedef {"viaRegalo"|"viaCuenta"} CardSource
 *
 * @typedef {Object} CollageItem
 * @property {string|null} cardId
 * @property {CardSource} source
 * @property {string} offerId
 * @property {import("../cards/card-snapshot.js").CardSnapshot|null} snapshot
 * @property {import("../cards/card-media.js").CardMedia} media
 * @property {import("../cards/card-pricing.js").CardPricing} pricing
 * @property {import("../cards/card-snapshot.js").CardBadges|null} badges
 * @property {ReturnType<typeof createDefaultCollageItemStyle>} style
 * @property {{ useGlobalDiscount: boolean, config: ReturnType<typeof normalizeDiscountConfig> }} discount
 * @property {string} [layoutId]
 * @property {string} [layoutName]
 * @property {string} [tileSize]
 */

export const CARD_SOURCES = Object.freeze({ VIA_REGALO: "viaRegalo", VIA_CUENTA: "viaCuenta" });

const styleCache = new WeakMap();
const itemCache = new WeakMap();

function hasOwnKeys(value) {
  if (!value || typeof value !== "object") return false;
  for (const key in value) return Object.prototype.hasOwnProperty.call(value, key);
  return false;
}

function mergeSection(base, patch) {
  return hasOwnKeys(patch) ? { ...base, ...patch } : base;
}

export function normalizeCollageItemStyle(style) {
  if (style && typeof style === "object") {
    const cached = styleCache.get(style);
    if (cached) return cached;
  }

  const defaults = createDefaultCollageItemStyle();
  if (!style || typeof style !== "object") return defaults;

  const merged = {
    card: mergeSection(defaults.card, style.card),
    image: mergeSection(defaults.image, style.image),
    name: mergeSection(defaults.name, style.name),
    price: mergeSection(defaults.price, style.price),
    solesOffer: mergeSection(defaults.solesOffer, style.solesOffer),
    solesList: mergeSection(defaults.solesList, style.solesList),
    outDate: mergeSection(defaults.outDate, style.outDate),
    banner: mergeSection(defaults.banner, style.banner),
  };

  const untouched = Object.keys(merged).every((key) => merged[key] === defaults[key]);
  const normalized = untouched ? defaults : merged;
  styleCache.set(style, normalized);
  return normalized;
}

export function resolveCardSource(item) {
  if (item?.source === CARD_SOURCES.VIA_CUENTA) return CARD_SOURCES.VIA_CUENTA;
  if (item?.source === CARD_SOURCES.VIA_REGALO) return CARD_SOURCES.VIA_REGALO;
  return isViaCuentaOfferId(item?.offerId) ? CARD_SOURCES.VIA_CUENTA : CARD_SOURCES.VIA_REGALO;
}

function normalizeDiscount(rawDiscount, source) {
  const discount = rawDiscount && typeof rawDiscount === "object" ? rawDiscount : {};
  const defaultUseGlobal = source !== CARD_SOURCES.VIA_CUENTA;
  const useGlobalDiscount = typeof discount.useGlobalDiscount === "boolean"
    ? discount.useGlobalDiscount
    : defaultUseGlobal;
  return { useGlobalDiscount, config: normalizeDiscountConfig(discount.config) };
}

function withLegacyWidthUnits(style, tileSize) {
  const widthUnits = Number(style?.card?.widthUnits);
  if (Number.isFinite(widthUnits) && widthUnits > 0) return style;
  return { ...style, card: { ...style.card, widthUnits: parseTileSizeWidthUnits(tileSize) } };
}

function createLegacyStringItem(offerId) {
  return {
    cardId: null,
    source: CARD_SOURCES.VIA_REGALO,
    offerId,
    snapshot: null,
    media: TILE_MEDIA,
    pricing: VBUCKS_PRICING,
    badges: null,
    style: createDefaultCollageItemStyle(),
    discount: createDefaultCollageItemDiscount(),
  };
}

/**
 * @returns {CollageItem|null}
 */
export function normalizeCollageItem(item) {
  if (!item) return null;
  if (typeof item === "string") return createLegacyStringItem(item);
  if (typeof item !== "object") return null;

  const cached = itemCache.get(item);
  if (cached !== undefined) return cached;

  const offerId = String(item.offerId || "");
  if (!offerId) {
    itemCache.set(item, null);
    return null;
  }

  const source = resolveCardSource(item);
  const isViaCuenta = source === CARD_SOURCES.VIA_CUENTA;
  const style = withLegacyWidthUnits(normalizeCollageItemStyle(item.style), item.tileSize);

  const normalized = {
    cardId: isCardId(item.cardId) ? item.cardId : null,
    source,
    offerId,
    snapshot: normalizeCardSnapshot(item.snapshot),
    media: normalizeCardMedia(item.media, { fallback: isViaCuenta ? DEFAULT_COVER_MEDIA : TILE_MEDIA }),
    pricing: normalizeCardPricing(item.pricing, { fallback: VBUCKS_PRICING }),
    badges: normalizeCardBadges(item.badges),
    style,
    discount: normalizeDiscount(item.discount, source),
    layoutId: item.layoutId,
    layoutName: item.layoutName,
    tileSize: item.tileSize,
  };

  itemCache.set(item, normalized);
  return normalized;
}

export function isViaCuentaItem(item) {
  return resolveCardSource(item) === CARD_SOURCES.VIA_CUENTA;
}

/**
 * Identificador de una tarjeta cruda (string u objeto) sin normalizar todo.
 */
export function readCardId(rawItem) {
  return rawItem && typeof rawItem === "object" && isCardId(rawItem.cardId) ? rawItem.cardId : null;
}

export function readOfferId(rawItem) {
  if (typeof rawItem === "string") return rawItem;
  return String(rawItem?.offerId || "");
}

import { parseTileSizeWidthUnits } from "../shop/shop-tile-size.js";
import { CARD_SOURCES } from "../collage/collage-item.js";
import {
  snapshotFromShopItem,
  snapshotFromViaCuenta,
  snapshotFromFreeCard,
  badgesFromViaCuenta,
} from "./card-snapshot.js";
import { TILE_MEDIA, createCoverMedia, DEFAULT_COVER_MEDIA } from "./card-media.js";
import { VBUCKS_PRICING, createSolesPricing, parseManualPriceText } from "./card-pricing.js";
import { isOfferActive } from "../via-cuenta/via-cuenta-visibility.js";
import { viaCuentaOfferId, VIA_CUENTA_FREE_PREFIX } from "../via-cuenta/via-cuenta-normalizer.js";

/**
 * Un `CardDraft` es una tarjeta lista para insertarse salvo por `cardId`
 * (lo asigna el comando) y por la herencia de estilo de sección (la resuelve
 * `materializeDraft` con el estado del slice destino).
 *
 * @typedef {Object} CardDraft
 * @property {import("../collage/collage-item.js").CardSource} source
 * @property {string} offerId
 * @property {import("./card-snapshot.js").CardSnapshot|null} snapshot
 * @property {import("./card-media.js").CardMedia} media
 * @property {import("./card-pricing.js").CardPricing} pricing
 * @property {import("./card-snapshot.js").CardBadges|null} badges
 * @property {string} layoutId
 * @property {string} layoutName
 * @property {number} widthUnits
 * @property {{ framing?: string, overflowTop?: boolean }|null} imageDefaults
 * @property {{ widthUnits?: number, framing?: string, ignoreGaps?: boolean }|null} presets
 * @property {boolean} useGlobalDiscount
 */

const FREE_CARD_PLACEHOLDER_OFFER = `${VIA_CUENTA_FREE_PREFIX}pending`;
const MAX_IMAGE_URL_LENGTH = 2048;

function trimmed(value) {
  return value == null ? "" : String(value).trim();
}

function normalizePresets(presets) {
  if (!presets || typeof presets !== "object") return null;
  const out = {};
  const widthUnits = Number(presets.widthUnits);
  if (Number.isFinite(widthUnits) && widthUnits >= 1) out.widthUnits = Math.min(4, Math.round(widthUnits));
  if (typeof presets.framing === "string" && presets.framing.trim()) out.framing = presets.framing.trim();
  if (typeof presets.ignoreGaps === "boolean") out.ignoreGaps = presets.ignoreGaps;
  return Object.keys(out).length ? Object.freeze(out) : null;
}

/**
 * Tarjeta Vía Regalo a partir de un ítem de la tienda.
 */
export function draftFromShopItem(shopItem, options = {}) {
  if (!shopItem || typeof shopItem !== "object") return null;
  const offerId = trimmed(shopItem.offerId);
  if (!offerId) return null;
  return Object.freeze({
    source: CARD_SOURCES.VIA_REGALO,
    offerId,
    snapshot: snapshotFromShopItem(shopItem),
    media: TILE_MEDIA,
    pricing: VBUCKS_PRICING,
    badges: null,
    layoutId: trimmed(shopItem.layoutId),
    layoutName: trimmed(shopItem.layoutName),
    widthUnits: parseTileSizeWidthUnits(shopItem.tileSize),
    shopItem,
    presets: normalizePresets(options.presets),
    useGlobalDiscount: true,
  });
}

/**
 * Tarjeta Vía Cuenta a partir de un producto del catálogo (RN-5, RN-6).
 * @param {import("../via-cuenta/via-cuenta-normalizer.js").ViaCuentaProduct} product
 * @param {{ offersMode?: boolean, aspectRatio?: number|null, presets?: object, nowMs?: number }} [options]
 */
export function draftFromViaCuenta(product, options = {}) {
  if (!product || typeof product !== "object") return null;
  const offerId = viaCuentaOfferId(product.id);
  const nowMs = Number.isFinite(options.nowMs) ? options.nowMs : Date.now();
  const offerActive = isOfferActive(product, nowMs);
  const useOffer = options.offersMode === true && offerActive;
  return Object.freeze({
    source: CARD_SOURCES.VIA_CUENTA,
    offerId,
    snapshot: snapshotFromViaCuenta(product),
    media: options.aspectRatio ? createCoverMedia(options.aspectRatio) : DEFAULT_COVER_MEDIA,
    pricing: createSolesPricing({
      amount: product.basePriceSoles,
      offerAmount: offerActive ? product.offerPriceSoles : null,
      useOffer,
    }),
    badges: badgesFromViaCuenta(product, { offersMode: options.offersMode === true, nowMs }),
    layoutId: "",
    layoutName: trimmed(product.category),
    widthUnits: 1,
    shopItem: null,
    presets: normalizePresets(options.presets),
    useGlobalDiscount: false,
  });
}

export function isLikelyImageUrl(value) {
  const text = trimmed(value);
  if (!text || text.length > MAX_IMAGE_URL_LENGTH) return false;
  if (text.startsWith("data:image/")) return true;
  let url;
  try {
    url = new URL(text, globalThis.location?.href || "https://localhost/");
  } catch {
    return false;
  }
  if (url.protocol !== "https:" && url.protocol !== "http:") return false;
  return /\.(png|jpe?g|webp|gif|avif|svg)(\?.*)?$/i.test(url.pathname + url.search)
    || /[?&](format|fm|ext)=(png|jpe?g|webp|avif)/i.test(url.search)
    || /\/(image|img|media|uploads|cdn)\//i.test(url.pathname);
}

/**
 * RN-9: tarjeta libre. Devuelve `{ draft }` o `{ error }` con mensaje legible.
 * @param {{ imageUrl: string, title: string, priceText: string, aspectRatio?: number|null, presets?: object }} input
 * @returns {{ draft: CardDraft, error?: undefined } | { draft?: undefined, error: string }}
 */
export function createFreeCardDraft(input = {}) {
  const imageUrl = trimmed(input.imageUrl);
  const title = trimmed(input.title);
  const priceText = trimmed(input.priceText);

  if (!imageUrl) return { error: "Indica la URL de la imagen." };
  if (!isLikelyImageUrl(imageUrl)) return { error: "La URL no parece una imagen válida (http/https)." };
  if (!title) return { error: "Indica un título para la tarjeta." };
  const amount = parseManualPriceText(priceText);
  if (amount == null) return { error: "Indica un precio válido en soles (p. ej. 12.50)." };

  return {
    draft: Object.freeze({
      source: CARD_SOURCES.VIA_CUENTA,
      offerId: FREE_CARD_PLACEHOLDER_OFFER,
      snapshot: snapshotFromFreeCard({ imageUrl, title }),
      media: input.aspectRatio ? createCoverMedia(input.aspectRatio) : DEFAULT_COVER_MEDIA,
      pricing: createSolesPricing({ amount }),
      badges: null,
      layoutId: "",
      layoutName: "",
      widthUnits: 1,
      shopItem: null,
      presets: normalizePresets(input.presets),
      useGlobalDiscount: false,
      isFreeCard: true,
    }),
  };
}

export function isFreeCardDraft(draft) {
  return draft?.isFreeCard === true;
}

export function freeCardOfferId(cardId) {
  return `${VIA_CUENTA_FREE_PREFIX}${cardId}`;
}

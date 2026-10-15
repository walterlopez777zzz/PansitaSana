import { isSolesPricing } from "../../domain/cards/card-pricing.js";
import { CARD_SOURCES } from "../../domain/collage/collage-item.js";

/**
 * Resuelve el "sujeto" que pinta una tarjeta: qué imagen, nombre y precio
 * mostrar, combinando la tienda viva con el snapshot guardado (RN-14).
 *
 * - Vía Regalo: la tienda viva manda; si la oferta rotó, se usa el snapshot.
 * - Vía Cuenta / tarjeta libre: siempre snapshot + `pricing` (soles directos).
 *
 * El resultado tiene la misma forma que un item de tienda (`displayName`,
 * `imageUrl`, `vbucks`, `typeLabel`, …) más `pricing`, `media`, `badges` y
 * `source`, para que el painter de tarjetas no dependa del origen.
 *
 * @typedef {Object} CardSubject
 * @property {string} source
 * @property {string} offerId
 * @property {string} displayName
 * @property {string} imageUrl
 * @property {number} vbucks
 * @property {string} typeLabel
 * @property {string} typeCategory
 * @property {boolean} isWrap
 * @property {boolean} isCarOffer
 * @property {boolean} isDynamicBundle
 * @property {boolean} noPricing
 * @property {string} newBannerText
 * @property {string|null} bannerVariant
 * @property {string|null} leavingDateIso
 * @property {string} layoutId
 * @property {string} layoutName
 * @property {import("../../domain/cards/card-pricing.js").CardPricing} pricing
 * @property {object} media
 * @property {object|null} badges
 * @property {boolean} fromSnapshot  la tienda viva no tenía la oferta
 */

const FALLBACK_NAME = "Artículo";

const subjectCache = new WeakMap();

function textOf(value, fallback = "") {
  const text = String(value ?? "").trim();
  return text || fallback;
}

function fromLiveShopItem(item, shopItem) {
  return Object.freeze({
    source: CARD_SOURCES.VIA_REGALO,
    offerId: item.offerId,
    displayName: textOf(shopItem.displayName, FALLBACK_NAME),
    imageUrl: textOf(shopItem.imageUrl),
    vbucks: Math.max(0, Math.round(Number(shopItem.vbucks || 0))),
    typeLabel: textOf(shopItem.typeLabel),
    typeCategory: textOf(shopItem.typeCategory),
    isWrap: shopItem.isWrap === true,
    isCarOffer: shopItem.isCarOffer === true,
    isDynamicBundle: shopItem.isDynamicBundle === true,
    noPricing: shopItem.noPricing === true,
    newBannerText: textOf(shopItem.newBannerText),
    bannerVariant: shopItem.bannerVariant || null,
    leavingDateIso: shopItem.leavingDateIso || null,
    layoutId: textOf(shopItem.layoutId),
    layoutName: textOf(shopItem.layoutName),
    pricing: item.pricing,
    media: item.media,
    badges: null,
    fromSnapshot: false,
  });
}

function fromSnapshot(item) {
  const snapshot = item.snapshot || {};
  const soles = isSolesPricing(item.pricing);
  return Object.freeze({
    source: item.source,
    offerId: item.offerId,
    displayName: textOf(snapshot.displayName, FALLBACK_NAME),
    imageUrl: textOf(snapshot.imageUrl),
    vbucks: Math.max(0, Math.round(Number(snapshot.vbucks || 0))),
    typeLabel: textOf(snapshot.typeLabel),
    typeCategory: "",
    isWrap: false,
    isCarOffer: false,
    isDynamicBundle: false,
    noPricing: !soles && snapshot.vbucks == null,
    newBannerText: "",
    bannerVariant: null,
    leavingDateIso: null,
    layoutId: textOf(item.layoutId),
    layoutName: textOf(item.layoutName),
    pricing: item.pricing,
    media: item.media,
    badges: item.source === CARD_SOURCES.VIA_CUENTA ? item.badges || null : null,
    fromSnapshot: true,
  });
}

/**
 * @param {object} item  item normalizado (`normalizeCollageItem`)
 * @param {Map<string, object>} shopByOfferId
 * @returns {CardSubject}
 */
export function resolveCardSubject(item, shopByOfferId) {
  const live = item.source === CARD_SOURCES.VIA_REGALO ? shopByOfferId.get(String(item.offerId || "")) : null;

  let perShop = subjectCache.get(item);
  if (!perShop) {
    perShop = new Map();
    subjectCache.set(item, perShop);
  }
  const key = live || null;
  const cached = perShop.get(key);
  if (cached) return cached;

  const subject = live ? fromLiveShopItem(item, live) : fromSnapshot(item);
  perShop.clear();
  perShop.set(key, subject);
  return subject;
}

export function isSolesSubject(subject) {
  return isSolesPricing(subject?.pricing);
}

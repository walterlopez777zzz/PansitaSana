import { isOfferActive, isSoldOut, resolveDiscountPercent } from "../via-cuenta/via-cuenta-visibility.js";

/**
 * Snapshot estable de una tarjeta (RN-14): lo mínimo para renderizarla aunque
 * la tienda rote o el catálogo Vía Cuenta cambie.
 *
 * @typedef {Object} CardSnapshot
 * @property {string} displayName
 * @property {string} imageUrl
 * @property {string} [typeLabel]
 * @property {number} [vbucks]
 * @property {string} [rawImageUrl]
 * @property {{ text: string, bg: string, fg: string }|null} [label]
 *
 * @typedef {Object} CardBadges
 * @property {number|null} discountPercent
 * @property {boolean} soldOut
 * @property {string|null} expiresAt
 */

export const VIA_CUENTA_TYPE_LABEL = "Vía Cuenta";

function toTrimmedString(value) {
  return value == null ? "" : String(value).trim();
}

/** @returns {CardSnapshot|null} */
export function normalizeCardSnapshot(snapshot) {
  if (!snapshot || typeof snapshot !== "object") return null;
  const displayName = toTrimmedString(snapshot.displayName);
  const imageUrl = toTrimmedString(snapshot.imageUrl);
  if (!displayName && !imageUrl) return null;

  const normalized = { displayName, imageUrl };
  const typeLabel = toTrimmedString(snapshot.typeLabel);
  if (typeLabel) normalized.typeLabel = typeLabel;
  const vbucks = Number(snapshot.vbucks);
  if (Number.isFinite(vbucks) && vbucks >= 0) normalized.vbucks = vbucks;
  const rawImageUrl = toTrimmedString(snapshot.rawImageUrl);
  if (rawImageUrl && rawImageUrl !== imageUrl) normalized.rawImageUrl = rawImageUrl;
  if (snapshot.label && typeof snapshot.label === "object" && toTrimmedString(snapshot.label.text)) {
    normalized.label = Object.freeze({
      text: toTrimmedString(snapshot.label.text),
      bg: toTrimmedString(snapshot.label.bg) || "#111111",
      fg: toTrimmedString(snapshot.label.fg) || "#FFFFFF",
    });
  }
  return Object.freeze(normalized);
}

/** @returns {CardBadges|null} */
export function normalizeCardBadges(badges) {
  if (!badges || typeof badges !== "object") return null;
  const percent = Number(badges.discountPercent);
  const discountPercent = Number.isFinite(percent) && percent >= 1 && percent <= 99
    ? Math.round(percent)
    : null;
  const soldOut = badges.soldOut === true;
  const expiresAt = toTrimmedString(badges.expiresAt) || null;
  if (discountPercent == null && !soldOut && !expiresAt) return null;
  return Object.freeze({ discountPercent, soldOut, expiresAt });
}

/** @returns {CardSnapshot|null} */
export function snapshotFromShopItem(shopItem) {
  if (!shopItem || typeof shopItem !== "object") return null;
  return normalizeCardSnapshot({
    displayName: shopItem.displayName,
    imageUrl: shopItem.imageUrl,
    rawImageUrl: shopItem.rawImageUrl,
    typeLabel: shopItem.typeLabel,
    vbucks: shopItem.vbucks,
  });
}

/**
 * @param {import("../via-cuenta/via-cuenta-normalizer.js").ViaCuentaProduct} product
 */
export function snapshotFromViaCuenta(product) {
  if (!product || typeof product !== "object") return null;
  return normalizeCardSnapshot({
    displayName: product.name,
    imageUrl: product.imageUrl,
    typeLabel: VIA_CUENTA_TYPE_LABEL,
    label: product.label,
  });
}

/**
 * @param {import("../via-cuenta/via-cuenta-normalizer.js").ViaCuentaProduct} product
 * @param {{ offersMode?: boolean, nowMs?: number }} [options]
 * @returns {CardBadges|null}
 */
export function badgesFromViaCuenta(product, options = {}) {
  if (!product || typeof product !== "object") return null;
  const nowMs = Number.isFinite(options.nowMs) ? options.nowMs : Date.now();
  const showDiscount = options.offersMode === true && isOfferActive(product, nowMs);
  return normalizeCardBadges({
    discountPercent: showDiscount ? resolveDiscountPercent(product, nowMs) : null,
    soldOut: isSoldOut(product),
    expiresAt: product.expiresAt,
  });
}

/**
 * RN-9: tarjeta libre creada a mano por el operador.
 * @param {{ imageUrl: string, title: string }} input
 */
export function snapshotFromFreeCard(input) {
  return normalizeCardSnapshot({
    displayName: input?.title,
    imageUrl: input?.imageUrl,
    typeLabel: VIA_CUENTA_TYPE_LABEL,
  });
}

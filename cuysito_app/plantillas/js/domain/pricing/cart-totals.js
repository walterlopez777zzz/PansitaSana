import { normalizeCollageItem } from "../collage/collage-item.js";
import { effectiveCardId } from "../cards/card-index.js";
import { isSolesPricing, resolveSolesAmount, parseManualPriceText, toCents, fromCents } from "../cards/card-pricing.js";
import { computeDiscountedPrices, resolveDiscountConfig } from "./discounts.js";
import { resolveConversionRate } from "./conversion-rate.js";
import { roundSoles, formatSoles, formatSolesExact } from "./money-format.js";

/**
 * Total del Carrito (RN-7, RN-8, RN-10).
 *
 * - Vía Regalo: precio derivado de V-Bucks × conversión con descuentos.
 * - Vía Cuenta / soles directos: `pricing.manualText` manda; si no, importe
 *   base u oferta según `useOffer`.
 * - Un texto de precio sobrescrito en la tarjeta (`style.price.text` en soles
 *   directos, `style.solesOffer.text` en Vía Regalo) manda sobre el cálculo.
 * - Los importes se suman en céntimos enteros. Las tarjetas sin precio
 *   interpretable (p. ej. "gratis") se listan en `unpricedCardIds`.
 *
 * @typedef {Object} CartTotal
 * @property {number} totalSoles
 * @property {number} pricedCount
 * @property {string[]} unpricedCardIds
 * @property {boolean} hasDirectSoles  hay al menos una tarjeta con precio directo en soles
 */

function buildOfferIndex(shopItems, byOfferId) {
  if (byOfferId instanceof Map) return byOfferId;
  const list = Array.isArray(shopItems) ? shopItems : [];
  return new Map(list.map((item) => [String(item?.offerId || ""), item]));
}

function resolveVbucksSoles(item, shopItem, { conversionRate, globalDiscount }) {
  const baseOfferVbucks = Math.max(0, Math.round(Number(shopItem?.vbucks || 0)));
  const discount = resolveDiscountConfig({
    globalDiscount,
    useGlobalDiscount: item.discount?.useGlobalDiscount,
    localDiscount: item.discount?.config,
  });
  const prices = computeDiscountedPrices({ baseOfferVbucks, conversionRate, discount });
  return prices.offerSoles != null ? prices.offerSoles : roundSoles(baseOfferVbucks * conversionRate);
}

/**
 * Importe efectivo de una tarjeta o `null` si no tiene precio interpretable.
 */
export function resolveCardSoles(rawItem, context) {
  const item = normalizeCollageItem(rawItem);
  if (!item) return null;

  if (isSolesPricing(item.pricing)) {
    const manualText = String(item.style?.price?.text || "").trim();
    if (manualText) return parseManualPriceText(manualText);
    return resolveSolesAmount(item.pricing);
  }

  // Vía Regalo: el importe en soles visible es `style.solesOffer.text`.
  const manualSolesText = String(item.style?.solesOffer?.text || "").trim();
  if (manualSolesText) return parseManualPriceText(manualSolesText);

  const shopItem = context.byOfferId.get(String(item.offerId || ""));
  if (!shopItem && item.snapshot?.vbucks == null) return null;
  const source = shopItem || { vbucks: item.snapshot?.vbucks };
  return resolveVbucksSoles(item, source, context);
}

/**
 * @param {{ items: unknown[], shopItems?: unknown[], conversionRate?: unknown, globalDiscount?: unknown, byOfferId?: Map<string, unknown> }} params
 * @returns {CartTotal}
 */
export function computeCartTotal({ items, shopItems, conversionRate, globalDiscount, byOfferId } = {}) {
  const list = Array.isArray(items) ? items : [];
  const context = {
    byOfferId: buildOfferIndex(shopItems, byOfferId),
    conversionRate: resolveConversionRate(conversionRate),
    globalDiscount,
  };

  let totalCents = 0;
  let pricedCount = 0;
  let hasDirectSoles = false;
  const unpricedCardIds = [];

  for (let index = 0; index < list.length; index += 1) {
    const rawItem = list[index];
    const item = normalizeCollageItem(rawItem);
    if (!item) continue;
    if (isSolesPricing(item.pricing)) hasDirectSoles = true;
    const amount = resolveCardSoles(rawItem, context);
    const cents = toCents(amount);
    if (cents == null) {
      unpricedCardIds.push(effectiveCardId(rawItem, index));
      continue;
    }
    totalCents += cents;
    pricedCount += 1;
  }

  return Object.freeze({ totalSoles: fromCents(totalCents), pricedCount, unpricedCardIds, hasDirectSoles });
}

/**
 * Texto del total: céntimos exactos si hay precios directos; formato
 * histórico (1 decimal) para carritos solo Vía Regalo.
 */
export function formatCartTotal(total) {
  return total?.hasDirectSoles ? formatSolesExact(total.totalSoles) : formatSoles(total?.totalSoles ?? 0);
}

/** Compatibilidad: total numérico. */
export function computeCartTotalSoles(params) {
  return computeCartTotal(params).totalSoles;
}

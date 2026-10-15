/**
 * Pricing de tarjeta.
 *
 * - `vbucks` → precio derivado de la tienda (conversión + descuentos globales/locales).
 * - `soles`  → precio directo en soles (RN-10). `manualText` tiene prioridad
 *              sobre `amount`/`offerAmount` (RN-8); un texto no interpretable
 *              deja la tarjeta "sin precio" en lugar de sumar 0.
 *
 * @typedef {{ kind: "vbucks" }} VbucksPricing
 * @typedef {{
 *   kind: "soles",
 *   amount: number|null,
 *   offerAmount: number|null,
 *   useOffer: boolean,
 *   manualText: string|null
 * }} SolesPricing
 * @typedef {VbucksPricing|SolesPricing} CardPricing
 */

export const VBUCKS_PRICING = Object.freeze({ kind: "vbucks" });

const CENTS_PER_SOL = 100;
const MANUAL_PRICE_PATTERN = /-?\d{1,3}(?:[.,\s]\d{3})*(?:[.,]\d+)?|-?\d+(?:[.,]\d+)?/;

export function toCents(amount) {
  const numeric = Number(amount);
  return Number.isFinite(numeric) ? Math.round(numeric * CENTS_PER_SOL) : null;
}

export function fromCents(cents) {
  return Number(cents) / CENTS_PER_SOL;
}

/**
 * Normaliza un importe en soles. `null` cuando no es un número finito ≥ 0.
 */
export function normalizeSolesAmount(value) {
  if (typeof value === "string") return parseManualPriceText(value);
  const cents = toCents(value);
  return cents == null || cents < 0 ? null : fromCents(cents);
}

/**
 * Interpreta el texto que el operador escribe en la tarjeta.
 * Acepta `S/ 12,50`, `12.5`, `S/12`, `1.250,00`, `1,250.00`.
 * Devuelve `null` para texto sin importe (`gratis`, vacío, `S/`).
 */
export function parseManualPriceText(text) {
  const source = String(text ?? "").trim();
  if (!source) return null;

  const match = source.match(MANUAL_PRICE_PATTERN);
  if (!match) return null;

  let token = match[0].replace(/\s+/g, "");
  const negative = token.startsWith("-");
  if (negative) return null;

  const lastComma = token.lastIndexOf(",");
  const lastDot = token.lastIndexOf(".");
  const separatorIndex = Math.max(lastComma, lastDot);

  if (separatorIndex !== -1) {
    const decimalsLength = token.length - separatorIndex - 1;
    const isDecimalSeparator = decimalsLength > 0 && decimalsLength <= 2;
    if (isDecimalSeparator) {
      const integerPart = token.slice(0, separatorIndex).replace(/[.,]/g, "");
      const decimalPart = token.slice(separatorIndex + 1);
      token = `${integerPart}.${decimalPart}`;
    } else {
      token = token.replace(/[.,]/g, "");
    }
  }

  const numeric = Number(token);
  if (!Number.isFinite(numeric) || numeric < 0) return null;
  return fromCents(toCents(numeric));
}

/**
 * @param {unknown} pricing
 * @param {{ fallback?: CardPricing }} [options]
 * @returns {CardPricing}
 */
export function normalizeCardPricing(pricing, options = {}) {
  const fallback = options.fallback || VBUCKS_PRICING;
  if (!pricing || typeof pricing !== "object") return fallback;
  if (pricing.kind === "vbucks") return VBUCKS_PRICING;
  if (pricing.kind !== "soles") return fallback;

  const manualText = typeof pricing.manualText === "string" && pricing.manualText.trim()
    ? pricing.manualText
    : null;

  return Object.freeze({
    kind: "soles",
    amount: normalizeSolesAmount(pricing.amount),
    offerAmount: normalizeSolesAmount(pricing.offerAmount),
    useOffer: pricing.useOffer === true,
    manualText,
  });
}

export function createSolesPricing({ amount, offerAmount = null, useOffer = false, manualText = null }) {
  return normalizeCardPricing({ kind: "soles", amount, offerAmount, useOffer, manualText });
}

export function isSolesPricing(pricing) {
  return pricing?.kind === "soles";
}

/**
 * Importe efectivo de una tarjeta en soles (RN-6, RN-8).
 * `null` ⇒ tarjeta sin precio válido.
 */
export function resolveSolesAmount(pricing) {
  if (!isSolesPricing(pricing)) return null;
  if (pricing.manualText != null) return parseManualPriceText(pricing.manualText);
  if (pricing.useOffer && pricing.offerAmount != null) return pricing.offerAmount;
  return pricing.amount;
}

/**
 * Importe de lista (tachado) cuando hay oferta activa; `null` en otro caso.
 */
export function resolveSolesListAmount(pricing) {
  if (!isSolesPricing(pricing) || !pricing.useOffer) return null;
  if (pricing.offerAmount == null || pricing.amount == null) return null;
  return pricing.offerAmount < pricing.amount ? pricing.amount : null;
}

/**
 * Sincroniza `pricing.manualText` con el texto que el operador escribe en el
 * precio de la tarjeta. Texto vacío ⇒ vuelve al importe calculado.
 */
export function withManualPriceText(pricing, text) {
  if (!isSolesPricing(pricing)) return pricing;
  const trimmed = String(text ?? "").trim();
  const manualText = trimmed ? String(text) : null;
  if (pricing.manualText === manualText) return pricing;
  return Object.freeze({ ...pricing, manualText });
}

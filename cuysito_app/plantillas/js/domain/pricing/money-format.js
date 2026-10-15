import { toCents, fromCents } from "../cards/card-pricing.js";

/**
 * Formato de importes en soles.
 *
 * - `formatSoles`      → formato histórico (1 decimal, usado por Vía Regalo).
 * - `formatSolesExact` → céntimos exactos (RN-7/RN-10): `S/ 12`, `S/ 12.50`.
 */

export function roundSoles(value) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? Math.round(10 * numeric) / 10 : 0;
}

export function formatSoles(value) {
  const rounded = roundSoles(value);
  return `S/ ${Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1)}`;
}

export function formatSolesExact(value) {
  const cents = toCents(value);
  if (cents == null) return "";
  const amount = fromCents(cents);
  return `S/ ${cents % 100 === 0 ? String(amount) : amount.toFixed(2)}`;
}

/**
 * Suma exacta en céntimos enteros. Ignora valores no numéricos.
 * @param {Iterable<number|null|undefined>} values
 */
export function sumExact(values) {
  let totalCents = 0;
  for (const value of values) {
    const cents = toCents(value);
    if (cents != null) totalCents += cents;
  }
  return fromCents(totalCents);
}

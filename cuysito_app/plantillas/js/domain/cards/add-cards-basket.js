import { CARD_SOURCES } from "../collage/collage-item.js";
import { clampQuantity } from "./card-commands.js";
import { createFreeCardDraft } from "./card-drafts.js";
import { draftFromCatalogEntry } from "./catalog-entries.js";

/**
 * Cesta inmutable del diálogo «Añadir tarjetas». Reductores puros: cada
 * operación devuelve una cesta nueva (o la misma instancia si no hay cambio),
 * lo que permite renderizar por comparación de referencia.
 *
 * @typedef {Object} FreeCardInput
 * @property {string} imageUrl
 * @property {string} title
 * @property {string} priceText
 *
 * @typedef {Object} BasketLine
 * @property {string} key                  identidad estable de la línea
 * @property {"catalog"|"free"} kind
 * @property {import("./catalog-entries.js").CatalogEntry|null} entry
 * @property {FreeCardInput|null} free
 * @property {number} quantity
 *
 * @typedef {Object} Basket
 * @property {ReadonlyArray<BasketLine>} lines
 */

const EMPTY_BASKET = Object.freeze({ lines: Object.freeze([]) });
const MAX_LINES = 200;

export function createEmptyBasket() {
  return EMPTY_BASKET;
}

function withLines(lines) {
  return Object.freeze({ lines: Object.freeze(lines) });
}

function freeLineKey(input) {
  return `free:${input.imageUrl}\u0000${input.title}\u0000${input.priceText}`;
}

function normalizeFreeInput(input) {
  return Object.freeze({
    imageUrl: String(input?.imageUrl || "").trim(),
    title: String(input?.title || "").trim(),
    priceText: String(input?.priceText || "").trim(),
  });
}

function upsertLine(basket, line) {
  const lines = basket.lines;
  const index = lines.findIndex((existing) => existing.key === line.key);
  if (index === -1) {
    if (lines.length >= MAX_LINES) return basket;
    return withLines([...lines, line]);
  }
  const merged = clampQuantity(lines[index].quantity + line.quantity);
  if (merged === lines[index].quantity) return basket;
  const next = lines.slice();
  next[index] = Object.freeze({ ...lines[index], quantity: merged });
  return withLines(next);
}

/** @returns {Basket} */
export function addCatalogEntry(basket, entry, quantity = 1) {
  if (!entry?.key) return basket;
  return upsertLine(basket, Object.freeze({
    key: entry.key,
    kind: "catalog",
    entry,
    free: null,
    quantity: clampQuantity(quantity),
  }));
}

/** @returns {Basket} */
export function addFreeCard(basket, input, quantity = 1) {
  const free = normalizeFreeInput(input);
  return upsertLine(basket, Object.freeze({
    key: freeLineKey(free),
    kind: "free",
    entry: null,
    free,
    quantity: clampQuantity(quantity),
  }));
}

/** @returns {Basket} */
export function setLineQuantity(basket, key, quantity) {
  const index = basket.lines.findIndex((line) => line.key === key);
  if (index === -1) return basket;
  const next = clampQuantity(quantity);
  if (next === basket.lines[index].quantity) return basket;
  const lines = basket.lines.slice();
  lines[index] = Object.freeze({ ...lines[index], quantity: next });
  return withLines(lines);
}

/** @returns {Basket} */
export function removeLine(basket, key) {
  const lines = basket.lines.filter((line) => line.key !== key);
  return lines.length === basket.lines.length ? basket : withLines(lines);
}

export function countBasketCards(basket) {
  let total = 0;
  for (const line of basket.lines) total += line.quantity;
  return total;
}

/**
 * URL de imagen de las líneas con formato cover (Vía Cuenta y libres), cuya
 * proporción debe medirse antes de insertar.
 */
export function coverImageUrls(basket) {
  const urls = new Set();
  for (const line of basket.lines) {
    const url = line.kind === "free"
      ? line.free?.imageUrl
      : (line.entry?.source === CARD_SOURCES.VIA_CUENTA ? line.entry.imageUrl : "");
    if (url) urls.add(url);
  }
  return urls;
}

/**
 * Materializa la cesta en entradas para `addBatch`.
 *
 * @param {Basket} basket
 * @param {{ offersMode?: boolean, aspectRatioFor?: (imageUrl: string) => number|null, nowMs?: number }} [options]
 * @returns {{ entries: Array<{ draft: object, quantity: number }>, errors: string[] }}
 */
export function buildBatchEntries(basket, { offersMode = false, aspectRatioFor = () => null, nowMs = Date.now() } = {}) {
  const entries = [];
  const errors = [];
  for (const line of basket.lines) {
    if (line.kind === "free") {
      const result = createFreeCardDraft({ ...line.free, aspectRatio: aspectRatioFor(line.free.imageUrl) });
      if (result.error) errors.push(`${line.free.title || "Tarjeta libre"}: ${result.error}`);
      else entries.push({ draft: result.draft, quantity: line.quantity });
      continue;
    }
    const draft = draftFromCatalogEntry(line.entry, {
      offersMode,
      nowMs,
      aspectRatio: aspectRatioFor(line.entry.imageUrl),
    });
    if (draft) entries.push({ draft, quantity: line.quantity });
    else errors.push(`${line.entry?.displayName || line.key}: no se pudo preparar la tarjeta.`);
  }
  return { entries, errors };
}

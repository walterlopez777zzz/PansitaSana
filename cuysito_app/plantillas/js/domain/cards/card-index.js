import { readCardId, readOfferId } from "../collage/collage-item.js";
import { legacyCardId } from "./card-id.js";

/**
 * Índice `cardId → índice` memoizado por referencia del array de tarjetas.
 * Las tarjetas sin `cardId` real se indexan por su id legacy determinista
 * (`legacy:<offerId>:<index>`) para que la selección funcione antes de la
 * migración.
 */

const EMPTY_INDEX = new Map();
const indexCache = new WeakMap();

/**
 * Identificador efectivo de la tarjeta en la posición `index`.
 */
export function effectiveCardId(rawItem, index) {
  return readCardId(rawItem) || legacyCardId(readOfferId(rawItem), index);
}

/**
 * @param {ReadonlyArray<unknown>} items
 * @returns {Map<string, number>}
 */
export function indexByCardId(items) {
  if (!Array.isArray(items)) return EMPTY_INDEX;
  const cached = indexCache.get(items);
  if (cached) return cached;

  const map = new Map();
  for (let index = 0; index < items.length; index += 1) {
    const id = effectiveCardId(items[index], index);
    if (!map.has(id)) map.set(id, index);
  }
  indexCache.set(items, map);
  return map;
}

export function findCardIndex(items, cardId) {
  if (!cardId) return -1;
  const index = indexByCardId(items).get(String(cardId));
  return index === undefined ? -1 : index;
}

export function cardIdAt(items, index) {
  if (!Array.isArray(items)) return null;
  const safeIndex = Number(index);
  if (!Number.isInteger(safeIndex) || safeIndex < 0 || safeIndex >= items.length) return null;
  return effectiveCardId(items[safeIndex], safeIndex);
}

import { createCardId } from "./card-id.js";
import { readCardId } from "../collage/collage-item.js";

/**
 * Migración única de tarjetas legacy: asigna `cardId` real (UUID) a las que
 * no lo tengan. Es la única capa, junto con los comandos, autorizada a generar
 * identificadores aleatorios. Devuelve el mismo array si nada cambió.
 *
 * @template T
 * @param {ReadonlyArray<T>} items
 * @returns {ReadonlyArray<T>}
 */
export function ensureCardIds(items) {
  if (!Array.isArray(items) || items.length === 0) return items;

  let changed = false;
  const migrated = items.map((rawItem) => {
    if (readCardId(rawItem)) return rawItem;
    changed = true;
    if (typeof rawItem === "string") {
      return { cardId: createCardId(), offerId: rawItem };
    }
    if (rawItem && typeof rawItem === "object") {
      return { ...rawItem, cardId: createCardId() };
    }
    return rawItem;
  });

  return changed ? migrated : items;
}

const GRID_SLICES = Object.freeze(["collage", "cart"]);

/**
 * Aplica `ensureCardIds` a `collage.items` y `cart.items`. Devuelve el mismo
 * estado si no hubo cambios, de modo que puede usarse dentro de `setState`.
 */
export function ensureStateCardIds(state) {
  let next = state;
  for (const sliceKey of GRID_SLICES) {
    const slice = next?.[sliceKey];
    const items = slice?.items;
    if (!Array.isArray(items)) continue;
    const migrated = ensureCardIds(items);
    if (migrated !== items) {
      next = { ...next, [sliceKey]: { ...slice, items: migrated } };
    }
  }
  return next;
}

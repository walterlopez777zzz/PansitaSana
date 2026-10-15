import {
  cardSelectionId,
  parseCardSelectionId,
  normalizeCardPartKind,
  cardPartKindToken,
} from "../../domain/cards/card-selection.js";
import { cardIdAt, findCardIndex } from "../../domain/cards/card-index.js";

/**
 * Identificadores de selección del editor.
 *
 * Formatos soportados para tarjetas:
 *   - moderno: `card:<cardId>[:<kind>]`      (estable ante inserciones/reordenaciones)
 *   - legacy:  `collage:<index>[:<kind>]`    (se sigue aceptando en entrada)
 * Elementos globales: `collage:title`, `collage:branding`, `collage:cartTotal`,
 * `collage:recipient:<index>`.
 *
 * `parseCollageSelection` devuelve siempre `{ index, kind, cardId }` cuando se
 * le pasan los `items` del slice activo, de modo que los consumidores
 * existentes (basados en `index`) funcionan con ambos formatos.
 */

const LAYER_PREFIX = "layer:";
const COLLAGE_PREFIX = "collage:";
const RECIPIENT_PREFIX = "recipient:";

export { cardSelectionId, parseCardSelectionId };

export function layerKey(layerId) {
  return `${LAYER_PREFIX}${String(layerId || "")}`;
}

export function selectedLayerId(selection) {
  const value = String(selection || "");
  return value.startsWith(LAYER_PREFIX) ? value.slice(LAYER_PREFIX.length) || null : null;
}

function globalSelection(kind, index = null) {
  return Object.freeze({ index, kind, cardId: null });
}

function parseLegacyCollageSelection(rest) {
  if (rest === "title") return globalSelection("title");
  if (rest === "branding") return globalSelection("branding");
  if (rest === "cartTotal") return globalSelection("cartTotal");

  if (rest.startsWith(RECIPIENT_PREFIX)) {
    const index = Number(rest.slice(RECIPIENT_PREFIX.length));
    return Number.isFinite(index) && index >= 0 ? globalSelection("recipient", index) : null;
  }

  const [rawIndex, rawKind] = rest.split(":").map((part) => String(part || "").trim());
  const index = Number(rawIndex);
  if (!Number.isFinite(index) || index < 0) return null;
  return { index, kind: normalizeCardPartKind(rawKind) };
}

/**
 * @param {string} selection
 * @param {ReadonlyArray<unknown>} [items]  tarjetas del slice activo (para resolver `cardId ↔ index`)
 * @returns {{ index: number|null, kind: string, cardId: string|null }|null}
 */
export function parseCollageSelection(selection, items) {
  const value = String(selection || "").trim();

  const cardSelection = parseCardSelectionId(value);
  if (cardSelection) {
    const index = findCardIndex(items, cardSelection.cardId);
    return Object.freeze({
      index: index === -1 ? null : index,
      kind: cardSelection.kind,
      cardId: cardSelection.cardId,
    });
  }

  if (!value.startsWith(COLLAGE_PREFIX)) return null;
  const parsed = parseLegacyCollageSelection(value.slice(COLLAGE_PREFIX.length));
  if (!parsed) return null;
  if (parsed.cardId === null) return parsed;
  return Object.freeze({
    index: parsed.index,
    kind: parsed.kind,
    cardId: cardIdAt(items, parsed.index),
  });
}

export function isCardSelection(parsed) {
  return Boolean(parsed) && parsed.cardId !== null && parsed.index !== null;
}

/**
 * Construye el id de selección para la tarjeta en `index`. Con `items`
 * disponibles usa el formato estable `card:<cardId>`; en otro caso emite el
 * formato legacy por índice.
 */
export function collageSelectionIdFor(index, kind, items) {
  const normalizedKind = String(kind || "").trim();
  if (normalizedKind === "title") return "collage:title";
  if (normalizedKind === "branding") return "collage:branding";
  if (normalizedKind === "cartTotal") return "collage:cartTotal";
  if (normalizedKind === "recipient") {
    return `collage:recipient:${Math.max(0, Math.floor(Number(index) || 0))}`;
  }

  const safeIndex = Math.max(0, Math.floor(Number(index) || 0));
  const cardId = cardIdAt(items, safeIndex);
  if (cardId) return cardSelectionId(cardId, normalizedKind || "card");

  const token = cardPartKindToken(normalizedKind || "card");
  return token ? `collage:${safeIndex}:${token}` : `collage:${safeIndex}`;
}

/**
 * Enlaza los helpers a un origen de tarjetas (slice activo) para que los
 * consumidores no tengan que pasar `items` en cada llamada.
 * @param {() => ReadonlyArray<unknown>} getItems
 */
export function createSelectionResolver(getItems) {
  const items = () => {
    const list = getItems();
    return Array.isArray(list) ? list : [];
  };
  return Object.freeze({
    parseCollageSelection: (selection) => parseCollageSelection(selection, items()),
    collageSelectionIdFor: (index, kind) => collageSelectionIdFor(index, kind, items()),
    selectedCardId: (selection) => parseCollageSelection(selection, items())?.cardId ?? null,
  });
}

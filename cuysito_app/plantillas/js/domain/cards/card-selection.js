/**
 * Formato canónico de selección estable por tarjeta:
 *   `card:<cardId>`            → tarjeta completa
 *   `card:<cardId>:<kind>`     → sub-elemento (name, price, image, …)
 *
 * El formato legacy `collage:<index>[:kind]` lo resuelve
 * `ui/selection/selection-ids.js`; aquí solo vive la parte de dominio.
 */

export const CARD_SELECTION_PREFIX = "card:";

export const CARD_PART_KINDS = Object.freeze([
  "card",
  "image",
  "name",
  "price",
  "solesOffer",
  "solesList",
  "outDate",
  "banner",
]);

const KIND_TO_TOKEN = Object.freeze({
  card: "",
  image: "image",
  name: "name",
  price: "price",
  solesOffer: "solesoffer",
  solesList: "soleslist",
  outDate: "outdate",
  banner: "banner",
});

const TOKEN_TO_KIND = Object.freeze({
  image: "image",
  name: "name",
  price: "price",
  solesoffer: "solesOffer",
  soleslist: "solesList",
  outdate: "outDate",
  banner: "banner",
});

export function normalizeCardPartKind(rawKind) {
  const token = String(rawKind || "").trim().toLowerCase();
  return TOKEN_TO_KIND[token] || "card";
}

export function cardPartKindToken(kind) {
  const token = KIND_TO_TOKEN[String(kind || "").trim()];
  return token === undefined ? String(kind || "").trim().toLowerCase() : token;
}

export function cardSelectionId(cardId, kind = "card") {
  const id = String(cardId || "").trim();
  if (!id) return null;
  const token = cardPartKindToken(kind);
  return token ? `${CARD_SELECTION_PREFIX}${id}:${token}` : `${CARD_SELECTION_PREFIX}${id}`;
}

/**
 * @returns {{ cardId: string, kind: string }|null}
 */
export function parseCardSelectionId(selection) {
  const value = String(selection || "").trim();
  if (!value.startsWith(CARD_SELECTION_PREFIX)) return null;
  const rest = value.slice(CARD_SELECTION_PREFIX.length);
  const separator = rest.lastIndexOf(":");
  if (separator === -1) {
    return rest ? { cardId: rest, kind: "card" } : null;
  }
  const kindToken = rest.slice(separator + 1).toLowerCase();
  if (TOKEN_TO_KIND[kindToken]) {
    const cardId = rest.slice(0, separator);
    return cardId ? { cardId, kind: TOKEN_TO_KIND[kindToken] } : null;
  }
  return { cardId: rest, kind: "card" };
}

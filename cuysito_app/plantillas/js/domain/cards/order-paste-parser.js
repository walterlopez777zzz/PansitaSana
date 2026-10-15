import { clampQuantity, MAX_BATCH_QUANTITY } from "./card-commands.js";

/**
 * Pegado inteligente de pedidos: convierte texto libre (una línea por
 * producto) en pares `{ query, quantity }` listos para resolverse contra el
 * catálogo. Función pura y tolerante a formatos habituales de chat:
 *
 *   "2x Peely"        "Peely x2"        "Peely ×2"      "Peely (2)"
 *   "- Peely - 2"     "3 Peely"         "Peely 2 uds"   "• Peely"
 *
 * Líneas vacías, encabezados («Pedido:», «Total: S/ 40») y separadores se
 * ignoran. La cantidad se acota a [1, MAX_BATCH_QUANTITY].
 *
 * @typedef {Object} ParsedOrderLine
 * @property {string} raw
 * @property {string} query
 * @property {number} quantity
 */

const BULLET_RE = /^\s*(?:[-•*▪●◦>]+|\d{1,2}[.)])\s+/;
const LEADING_QTY_RE = /^\s*(\d{1,3})\s*(?:x|×|und?s?\.?|unidades?|pcs?)?\s+(?=\S)/i;
const TRAILING_QTY_RE = /\s*(?:[-–—:]\s*(\d{1,3})|\(\s*(\d{1,3})\s*\)|(?:x|×)\s*(\d{1,3})|(\d{1,3})\s*(?:x|×|und?s?\.?|unidades?|pcs?))\s*$/i;
const SKIP_LINE_RE = /^(?:pedido|orden|order|total|subtotal|cliente|nombre|nick|usuario|pago|yape|plin)\b/i;
const SEPARATOR_RE = /^[\s\-=_*~·•]+$/;
const MAX_LINES = 200;

function splitLines(text) {
  return String(text || "")
    .replace(/\r\n?/g, "\n")
    .split(/\n|;/)
    .map((line) => line.trim())
    .filter(Boolean)
    .slice(0, MAX_LINES);
}

function parseQuantity(...candidates) {
  for (const candidate of candidates) {
    if (candidate == null || candidate === "") continue;
    const numeric = Number(candidate);
    if (Number.isFinite(numeric) && numeric >= 1) return clampQuantity(Math.min(numeric, MAX_BATCH_QUANTITY));
  }
  return null;
}

/** @returns {ParsedOrderLine|null} */
export function parseOrderLine(rawLine) {
  const raw = String(rawLine || "").trim();
  if (!raw || SEPARATOR_RE.test(raw) || SKIP_LINE_RE.test(raw)) return null;

  let line = raw.replace(BULLET_RE, "");
  let quantity = null;

  const leading = LEADING_QTY_RE.exec(line);
  if (leading) {
    quantity = parseQuantity(leading[1]);
    line = line.slice(leading[0].length);
  }

  const trailing = TRAILING_QTY_RE.exec(line);
  if (trailing && line.slice(0, trailing.index).trim()) {
    quantity = quantity ?? parseQuantity(trailing[1], trailing[2], trailing[3], trailing[4]);
    line = line.slice(0, trailing.index);
  }

  const query = line.replace(/\s+/g, " ").trim();
  if (!query) return null;
  return Object.freeze({ raw, query, quantity: quantity ?? 1 });
}

/** @returns {ReadonlyArray<ParsedOrderLine>} */
export function parseOrderText(text) {
  const lines = [];
  for (const rawLine of splitLines(text)) {
    const parsed = parseOrderLine(rawLine);
    if (parsed) lines.push(parsed);
  }
  return Object.freeze(lines);
}

/**
 * Heurística para decidir si un texto pegado en el buscador es un pedido
 * (varias líneas) y no una consulta simple.
 */
export function looksLikeOrderText(text) {
  return splitLines(text).length >= 2;
}

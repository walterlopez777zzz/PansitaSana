import { clamp } from "../../utils/number-utils.js";
import { clampCoverRatio, isCoverMedia } from "../cards/card-media.js";

/**
 * Geometría de una tarjeta dentro de la rejilla.
 *
 * - `tile`  (Vía Regalo): alto fijo `cardW / TILE_ASPECT` con un "headroom"
 *   superior del 15 % que se descuenta del área visible.
 * - `cover` (Vía Cuenta, RN-13): mismo ancho de columna, alto dinámico
 *   `tileW / aspectRatio` con ratio acotado a `[0.5, 4]` (máx. 2 × ancho),
 *   sin headroom.
 */

export const TILE_HEADROOM = 0.15;
export const TILE_ASPECT = 0.627;
export const SUBSECTION_EXTRA_GAP_RATIO = 0.2267;
export const MAX_WIDTH_UNITS = 4;

/**
 * @typedef {Object} CardGeometry
 * @property {number} units
 * @property {number} tileW
 * @property {number} tileH      alto total que ocupa en la fila
 * @property {number} headroom   franja superior no visible
 * @property {number} visibleH   alto pintado (`tileH - headroom`)
 * @property {"tile"|"cover"} kind
 */

export function normalizeWidthUnits(widthUnits, maxUnits = MAX_WIDTH_UNITS) {
  return clamp(Math.round(Number(widthUnits) || 1), 1, maxUnits);
}

/**
 * @param {number} widthUnits
 * @param {{ cardW: number, gap: number, media?: import("../cards/card-media.js").CardMedia|null }} options
 * @returns {CardGeometry}
 */
export function resolveCardGeometry(widthUnits, { cardW, gap, media = null }) {
  const units = normalizeWidthUnits(widthUnits);
  const tileW = cardW * units + gap * (units - 1);

  if (isCoverMedia(media)) {
    const tileH = tileW / clampCoverRatio(media.aspectRatio);
    return Object.freeze({ units, tileW, tileH, headroom: 0, visibleH: tileH, kind: "cover" });
  }

  const tileH = cardW / TILE_ASPECT;
  const headroom = TILE_HEADROOM * tileH;
  return Object.freeze({ units, tileW, tileH, headroom, visibleH: tileH - headroom, kind: "tile" });
}

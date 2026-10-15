/**
 * Media de tarjeta: cómo se encaja la imagen dentro del tile.
 *
 * - `tile`  → geometría legacy de la tienda (headroom + overflow superior).
 * - `cover` → RN-13: mismo ancho de columna, alto = ancho / aspectRatio,
 *             ratio acotado a [0.5, 4] (0.5 ⇒ alto máximo = 2× ancho).
 *
 * @typedef {{ kind: "tile" }} TileMedia
 * @typedef {{ kind: "cover", aspectRatio: number }} CoverMedia
 * @typedef {TileMedia|CoverMedia} CardMedia
 */

export const MIN_COVER_RATIO = 0.5;
export const MAX_COVER_RATIO = 4;
export const DEFAULT_COVER_RATIO = 1;

export const TILE_MEDIA = Object.freeze({ kind: "tile" });
export const DEFAULT_COVER_MEDIA = Object.freeze({ kind: "cover", aspectRatio: DEFAULT_COVER_RATIO });

export function clampCoverRatio(ratio) {
  const numeric = Number(ratio);
  if (!Number.isFinite(numeric) || numeric <= 0) return DEFAULT_COVER_RATIO;
  return Math.min(MAX_COVER_RATIO, Math.max(MIN_COVER_RATIO, numeric));
}

/**
 * Relación ancho/alto de una imagen. Devuelve `null` si las medidas no sirven.
 */
export function resolveAspectRatio(width, height) {
  const w = Number(width);
  const h = Number(height);
  if (!Number.isFinite(w) || !Number.isFinite(h) || w <= 0 || h <= 0) return null;
  return w / h;
}

export function createCoverMedia(aspectRatio) {
  const ratio = clampCoverRatio(aspectRatio);
  return ratio === DEFAULT_COVER_RATIO ? DEFAULT_COVER_MEDIA : Object.freeze({ kind: "cover", aspectRatio: ratio });
}

/**
 * @param {unknown} media
 * @param {{ fallback?: CardMedia }} [options]
 * @returns {CardMedia}
 */
export function normalizeCardMedia(media, options = {}) {
  const fallback = options.fallback || TILE_MEDIA;
  if (!media || typeof media !== "object") return fallback;
  if (media.kind === "cover") return createCoverMedia(media.aspectRatio);
  if (media.kind === "tile") return TILE_MEDIA;
  return fallback;
}

export function isCoverMedia(media) {
  return media?.kind === "cover";
}

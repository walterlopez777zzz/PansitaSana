/**
 * @typedef {Object} FadeSpec
 * @property {boolean} enabled
 * @property {number} height
 * @property {number} density
 */

/**
 * @typedef {Object} LayerFilterSpec
 * @property {number} brightness
 * @property {number} contrast
 * @property {number} saturation
 * @property {number} hue
 * @property {number} sepia
 */

import { clamp } from '../../utils/number-utils.js';

/**
 * Compute the fade settings used for template-driven image fading.
 *
 * @param {any} template
 * @returns {FadeSpec}
 */
export function computeImageFadeSpec(template) {
  const enabled = template?.imageFadeEnabled !== false;
  const height = clamp(Number(template?.imageFadeHeight ?? 0.1), 0, 0.5);
  const density = clamp(Number(template?.imageFadeDensity ?? 0), 0, 1);
  return { enabled, height, density };
}

/**
 * Compute fade settings used for per-layer fading.
 *
 * @param {any} layer
 * @returns {FadeSpec}
 */
export function computeLayerFadeSpec(layer) {
  const enabled = layer?.fadeEnabled !== false;
  const height = clamp(Number(layer?.fadeHeight ?? 0.1), 0, 0.5);
  const density = clamp(Number(layer?.fadeDensity ?? 0), 0, 1);
  return { enabled, height, density };
}

/**
 * Normalize layer filter values into a bounded spec.
 *
 * @param {any} layer
 * @returns {LayerFilterSpec}
 */
export function computeLayerFilterSpec(layer) {
  const brightness = clamp(Number(layer?.brightness ?? 100), 0, 200);
  const contrast = clamp(Number(layer?.contrast ?? 100), 0, 200);
  const saturation = clamp(Number(layer?.saturation ?? 100), 0, 300);
  const hue = clamp(Number(layer?.hue ?? 0), 0, 360);
  const sepia = clamp(Number(layer?.sepia ?? 0), 0, 100);
  return { brightness, contrast, saturation, hue, sepia };
}

/* ═══════════════════════════════════════════════════════════════
 * Finding 2.7 + F12: Memoized CSS filter string.
 *
 * In a collage with 120 cards the same filter parameters appear on
 * virtually every card (most share defaults 100/100/100/0/0).
 * A last-value cache avoids rebuilding the template literal on
 * every frame — hitting >90 % of cases.  When the parameters
 * do change we try a small 8-entry LRU ring.
 *
 * F12 Enhancement: A WeakMap reference cache keyed on the layer
 * object itself.  With structural sharing (F1) unchanged layers
 * keep their identity — so this is an O(1) cache hit that skips
 * all Number() casts and comparisons entirely.
 * ═══════════════════════════════════════════════════════════════ */

/** F12: WeakMap reference cache — returns cached string when the
 *  same layer object reference is passed again (structural sharing). */
const _layerFilterRefCache = new WeakMap();

/** Last-value cache fields */
let _lastFilterB = -1, _lastFilterC = -1, _lastFilterS = -1;
let _lastFilterH = -1, _lastFilterSe = -1;
let _lastFilterStr = 'none';

/** Small LRU ring (8 entries) for the handful of unique filter combinations. */
const _filterCacheSize = 8;
const _filterCacheKeys = new Float64Array(_filterCacheSize * 5); // 5 params each
const _filterCacheVals = new Array(_filterCacheSize).fill('none');
let _filterCacheIdx = 0;

/**
 * Build a CSS canvas filter string for a given layer.
 * Memoized: returns cached string when filter parameters haven't changed.
 *
 * @param {any} layer
 * @returns {string}
 */
export function computeLayerFilterString(layer) {
  // F12: Reference cache — O(1) hit when the same layer object is reused
  if (layer && typeof layer === 'object') {
    const refCached = _layerFilterRefCache.get(layer);
    if (refCached !== undefined) return refCached;
  }

  const f = computeLayerFilterSpec(layer);

  // Fast path: all defaults → 'none'
  if (f.brightness === 100 && f.contrast === 100
    && f.saturation === 100 && f.hue === 0 && f.sepia === 0) {
    if (layer && typeof layer === 'object') _layerFilterRefCache.set(layer, 'none');
    return 'none';
  }

  // Last-value cache hit (most common in collage: all cards share one filter)
  if (f.brightness === _lastFilterB && f.contrast === _lastFilterC
    && f.saturation === _lastFilterS && f.hue === _lastFilterH
    && f.sepia === _lastFilterSe) {
    if (layer && typeof layer === 'object') _layerFilterRefCache.set(layer, _lastFilterStr);
    return _lastFilterStr;
  }

  // LRU ring lookup
  for (let i = 0; i < _filterCacheSize; i++) {
    const off = i * 5;
    if (_filterCacheKeys[off] === f.brightness
      && _filterCacheKeys[off + 1] === f.contrast
      && _filterCacheKeys[off + 2] === f.saturation
      && _filterCacheKeys[off + 3] === f.hue
      && _filterCacheKeys[off + 4] === f.sepia) {
      // Cache hit — promote to last-value
      const cached = _filterCacheVals[i];
      _lastFilterB = f.brightness; _lastFilterC = f.contrast;
      _lastFilterS = f.saturation; _lastFilterH = f.hue;
      _lastFilterSe = f.sepia; _lastFilterStr = cached;
      if (layer && typeof layer === 'object') _layerFilterRefCache.set(layer, cached);
      return cached;
    }
  }

  // Cache miss — build the string and store it
  const str = `brightness(${f.brightness / 100}) contrast(${f.contrast / 100}) saturate(${f.saturation / 100}) hue-rotate(${f.hue}deg) sepia(${f.sepia / 100})`;

  // Update last-value cache
  _lastFilterB = f.brightness; _lastFilterC = f.contrast;
  _lastFilterS = f.saturation; _lastFilterH = f.hue;
  _lastFilterSe = f.sepia; _lastFilterStr = str;

  // Insert into LRU ring
  const off = _filterCacheIdx * 5;
  _filterCacheKeys[off] = f.brightness;
  _filterCacheKeys[off + 1] = f.contrast;
  _filterCacheKeys[off + 2] = f.saturation;
  _filterCacheKeys[off + 3] = f.hue;
  _filterCacheKeys[off + 4] = f.sepia;
  _filterCacheVals[_filterCacheIdx] = str;
  _filterCacheIdx = (_filterCacheIdx + 1) % _filterCacheSize;

  // F12: Store in reference cache
  if (layer && typeof layer === 'object') _layerFilterRefCache.set(layer, str);

  return str;
}

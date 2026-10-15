/**
 * @typedef {Object} FadeSpec
 * @property {boolean} enabled
 * @property {number} height
 * @property {number} density
 */

/**
 * @typedef {Object} FadeResult
 * @property {CanvasImageSource} source
 * @property {'bitmap'|'canvas'} type
 */

import { clamp } from '../../utils/number-utils.js';

/**
 * @typedef {Object} FadeCache
 * @property {(url: string, img: {width:number, height:number}, fade: FadeSpec, contentBottomY?: number) => string} makeFadeCacheKey
 * @property {(params: {cacheKey: string, img: CanvasImageSource & {width:number, height:number}, fade: FadeSpec, token: number|null, contentBottomY?: number}) => Promise<FadeResult|null>} getFadedSource
 * @property {(params: {cacheKey: string, img: CanvasImageSource & {width:number, height:number}, fade: FadeSpec, contentBottomY?: number}) => (FadeResult|null)} requestFadedSource
 * @property {() => void} clear
 */

/**
 * @typedef {Object} CreateFadeCacheOptions
 * @property {number=} maxEntries
 * @property {number=} maxMaskSize
 * @property {(() => void)=} onAssetReady Called after an async fade result is computed.
 * @property {(token: number) => boolean=} isTokenValid Used to cancel stale async computations.
 */

/* ═══════════════════════════════════════════════════════════════
 * Finding 4.4: Shared scratch canvas.
 *
 * Instead of creating one <canvas> per unique fade key (N canvases
 * for N items → N GPU texture allocations), a single shared scratch
 * canvas is reused for every computation.  After drawing the faded
 * source, createImageBitmap() snapshots the result.  The scratch
 * canvas is then immediately available for the next item.
 *
 * GPU texture count: N → 1.
 * ═══════════════════════════════════════════════════════════════ */

/**
 * Create a cache for generating per-image fade masks (implemented as alpha gradients).
 *
 * @param {CreateFadeCacheOptions=} options
 * @returns {FadeCache}
 */
export function createAssetFadeCache(options = {}) {
  const maxEntries = Number.isFinite(Number(options.maxEntries))
    ? Math.max(1, Math.floor(Number(options.maxEntries)))
    : 32;
  const maxMaskSize = Number.isFinite(Number(options.maxMaskSize))
    ? Math.max(64, Math.floor(Number(options.maxMaskSize)))
    : 2048;

  const onAssetReady = typeof options.onAssetReady === 'function' ? options.onAssetReady : null;
  const isTokenValid = typeof options.isTokenValid === 'function' ? options.isTokenValid : null;

  /** Single shared scratch canvas — reused for every fade computation. */
  let _sharedScratchCanvas = null;
  let _sharedScratchCtx = null;

  const getScratch = () => {
    if (!_sharedScratchCanvas) {
      _sharedScratchCanvas = document.createElement('canvas');
      _sharedScratchCtx = _sharedScratchCanvas.getContext('2d', { alpha: true, desynchronized: true });
    }
    return { canvas: _sharedScratchCanvas, ctx: _sharedScratchCtx };
  };

  const fadeResultCache = new Map();

  /**
   * @param {FadeResult|Promise<FadeResult|null>|null} value
   * @returns {void}
   */
  const disposeFadeCacheValue = (value) => {
    if (!value) return;
    const resolved = (value && typeof value.then === 'function') ? null : value;
    const src = resolved?.source;
    if (src && typeof src.close === 'function') {
      try {
        src.close();
      } catch (_) {
      }
    }
  };

  const enforceLimit = () => {
    while (fadeResultCache.size > maxEntries) {
      const firstKey = fadeResultCache.keys().next().value;
      const firstVal = fadeResultCache.get(firstKey);
      fadeResultCache.delete(firstKey);
      disposeFadeCacheValue(firstVal);
    }
  };

  /**
   * @param {string} url
   * @param {{width:number, height:number}} img
   * @param {FadeSpec} fade
   * @returns {string}
   */
  const makeFadeCacheKey = (url, img, fade, contentBottomY) => {
    const u = String(url || '');
    const w = Number(img?.width || 0);
    const h = Number(img?.height || 0);
    const eh = Math.round(clamp(Number(fade?.height ?? 0), 0, 0.5) * 1000);
    const ed = Math.round(clamp(Number(fade?.density ?? 0), 0, 1) * 1000);
    const en = fade?.enabled ? 1 : 0;
    const cb = Math.round(clamp(Number(contentBottomY ?? 1), 0, 1) * 1000);
    return `${u}#${w}x${h}#${en}:${eh}:${ed}:${cb}`;
  };

  /**
   * @param {{cacheKey: string, img: CanvasImageSource & {width:number, height:number}, fade: FadeSpec, token: number|null}} params
   * @returns {Promise<FadeResult|null>}
   */
  const computeFadedSource = async ({ cacheKey, img, fade, token, contentBottomY }) => {
    const scratch = getScratch();
    const fadeScratchCanvas = scratch.canvas;
    const fadeScratchCtx = scratch.ctx;

    const down = Math.min(1, maxMaskSize / Math.max(img.width, img.height));
    const sw = Math.max(1, Math.round(img.width * down));
    const sh = Math.max(1, Math.round(img.height * down));

    if (fadeScratchCanvas.width !== sw) fadeScratchCanvas.width = sw;
    if (fadeScratchCanvas.height !== sh) fadeScratchCanvas.height = sh;

    fadeScratchCtx.setTransform(1, 0, 0, 1, 0, 0);
    fadeScratchCtx.globalAlpha = 1;
    fadeScratchCtx.globalCompositeOperation = 'source-over';
    fadeScratchCtx.filter = 'none';
    fadeScratchCtx.shadowColor = 'rgba(0,0,0,0)';
    fadeScratchCtx.shadowBlur = 0;
    fadeScratchCtx.shadowOffsetX = 0;
    fadeScratchCtx.shadowOffsetY = 0;
    fadeScratchCtx.clearRect(0, 0, sw, sh);
    fadeScratchCtx.imageSmoothingEnabled = true;
    fadeScratchCtx.imageSmoothingQuality = 'high';
    fadeScratchCtx.drawImage(img, 0, 0, sw, sh);

    fadeScratchCtx.globalCompositeOperation = 'destination-in';
    const anchorY = Math.round(sh * clamp(Number(contentBottomY ?? 1), 0, 1));
    const fadePx = Math.round(sh * fade.height);
    const yStart = anchorY - fadePx;

    const g = fadeScratchCtx.createLinearGradient(0, anchorY, 0, yStart);
    if (fade.density <= 0) {
      g.addColorStop(0, 'rgba(0,0,0,0)');
      g.addColorStop(1, 'rgba(0,0,0,1)');
    } else {
      const p = clamp(0.2 + (1 - fade.density) * 0.8, 0.2, 1);
      const mid = clamp(p * 0.55, 0, 1);
      g.addColorStop(0, 'rgba(0,0,0,0)');
      g.addColorStop(mid, 'rgba(0,0,0,0.55)');
      g.addColorStop(p, 'rgba(0,0,0,1)');
      g.addColorStop(1, 'rgba(0,0,0,1)');
    }

    fadeScratchCtx.fillStyle = g;
    fadeScratchCtx.fillRect(0, 0, sw, sh);
    fadeScratchCtx.globalCompositeOperation = 'source-over';

    // Always snapshot via createImageBitmap so the shared scratch
    // canvas is immediately available for the next fade computation.
    if (typeof createImageBitmap === 'function') {
      try {
        const bitmap = await createImageBitmap(fadeScratchCanvas);
        if (typeof token === 'number' && isTokenValid && !isTokenValid(token)) return null;
        return { source: bitmap, type: 'bitmap' };
      } catch (_) {
        // Fallback: clone the canvas content into a dedicated canvas
        return cloneScratchToCanvas(fadeScratchCanvas, sw, sh);
      }
    }

    // No createImageBitmap — clone the scratch content into a
    // dedicated canvas so the shared scratch can be reused.
    return cloneScratchToCanvas(fadeScratchCanvas, sw, sh);
  };

  /**
   * Clone the shared scratch canvas content into a new dedicated canvas.
   * Used as a fallback when createImageBitmap is not available.
   *
   * @param {HTMLCanvasElement} src
   * @param {number} w
   * @param {number} h
   * @returns {FadeResult}
   */
  const cloneScratchToCanvas = (src, w, h) => {
    const clone = document.createElement('canvas');
    clone.width = w;
    clone.height = h;
    const cloneCtx = clone.getContext('2d', { alpha: true });
    cloneCtx.drawImage(src, 0, 0);
    return { source: clone, type: 'canvas' };
  };

  /**
   * @param {{cacheKey: string, img: CanvasImageSource & {width:number, height:number}, fade: FadeSpec, token: number|null}} params
   * @returns {Promise<FadeResult|null>}
   */
  const getFadedSource = async ({ cacheKey, img, fade, token, contentBottomY }) => {
    const cached = fadeResultCache.get(cacheKey);
    if (cached) return await cached;

    const p = computeFadedSource({ cacheKey, img, fade, token, contentBottomY });
    fadeResultCache.set(cacheKey, p);

    try {
      const resolved = await p;
      if (!resolved) {
        fadeResultCache.delete(cacheKey);
        return null;
      }
      fadeResultCache.set(cacheKey, resolved);
      enforceLimit();
      return resolved;
    } catch (e) {
      fadeResultCache.delete(cacheKey);
      throw e;
    }
  };

  /**
   * @param {{cacheKey: string, img: CanvasImageSource & {width:number, height:number}, fade: FadeSpec}} params
   * @returns {FadeResult|null}
   */
  const requestFadedSource = ({ cacheKey, img, fade, contentBottomY }) => {
    const cached = fadeResultCache.get(cacheKey);
    if (cached) {
      if (cached && typeof cached.then === 'function') return null;
      return cached;
    }

    getFadedSource({ cacheKey, img, fade, token: null, contentBottomY })
      .then(() => { if (onAssetReady) onAssetReady(); })
      .catch(() => { });

    return null;
  };

  const clear = () => {
    for (const v of fadeResultCache.values()) disposeFadeCacheValue(v);
    fadeResultCache.clear();
    // Don't destroy the shared scratch — it's lightweight and reusable.
  };

  return Object.freeze({ makeFadeCacheKey, getFadedSource, requestFadedSource, clear });
}

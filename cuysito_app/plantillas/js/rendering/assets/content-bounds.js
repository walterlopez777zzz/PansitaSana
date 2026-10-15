/**
 * @typedef {Object} ContentBoundsAnalyzer
 * @property {(img: CanvasImageSource & {width:number, height:number, src?:string}) => number} getContentBottomY
 * @property {() => void} clear
 */

const SAMPLE_SIZE = 64;
const ALPHA_THRESHOLD = 10;

/**
 * Create a cached analyzer that finds the normalized Y coordinate (0–1) of
 * the lowest non-transparent pixel row in an image.
 *
 * Uses a tiny 64×64 sampling canvas for near-instant analysis.
 * Results are cached by image `src` so each URL is analyzed only once.
 *
 * @returns {ContentBoundsAnalyzer}
 */
export function createContentBoundsAnalyzer() {
  const cache = new Map();

  const sampleCanvas = document.createElement('canvas');
  sampleCanvas.width = SAMPLE_SIZE;
  sampleCanvas.height = SAMPLE_SIZE;
  const sampleCtx = sampleCanvas.getContext('2d', { alpha: true, willReadFrequently: true });

  /**
   * Return the normalized Y (0 = top, 1 = bottom) of the lowest row that
   * contains at least one pixel with alpha > ALPHA_THRESHOLD.
   *
   * Falls back to 1.0 (container bottom) when the image is fully transparent
   * or cannot be analyzed.
   *
   * @param {CanvasImageSource & {width:number, height:number, src?:string}} img
   * @returns {number}
   */
  const getContentBottomY = (img) => {
    if (!img) return 1;

    const src = img.src || '';
    if (src && cache.has(src)) return cache.get(src);

    const w = Number(img.width || 0);
    const h = Number(img.height || 0);
    if (w <= 0 || h <= 0) return 1;

    sampleCtx.clearRect(0, 0, SAMPLE_SIZE, SAMPLE_SIZE);
    try {
      sampleCtx.drawImage(img, 0, 0, SAMPLE_SIZE, SAMPLE_SIZE);
    } catch (_) {
      return 1;
    }

    let data;
    try {
      data = sampleCtx.getImageData(0, 0, SAMPLE_SIZE, SAMPLE_SIZE).data;
    } catch (_) {
      return 1;
    }

    for (let row = SAMPLE_SIZE - 1; row >= 0; row--) {
      const rowStart = row * SAMPLE_SIZE * 4;
      for (let col = 0; col < SAMPLE_SIZE; col++) {
        if (data[rowStart + col * 4 + 3] > ALPHA_THRESHOLD) {
          const result = row / SAMPLE_SIZE;
          if (src) cache.set(src, result);
          return result;
        }
      }
    }

    if (src) cache.set(src, 1);
    return 1;
  };

  /** M3: Check if result is already cached without triggering analysis. */
  const hasResult = (img) => {
    if (!img) return true;
    const src = img.src || '';
    return src ? cache.has(src) : false;
  };

  /** M3: Pre-compute content bounds for an image off the render path.
   *  Called when images finish loading so the result is warm in cache
   *  before the next render frame needs it. */
  const precompute = (img) => {
    if (!img) return;
    const src = img.src || '';
    if (src && cache.has(src)) return;
    getContentBottomY(img);
  };

  const clear = () => cache.clear();

  return Object.freeze({ getContentBottomY, hasResult, precompute, clear });
}

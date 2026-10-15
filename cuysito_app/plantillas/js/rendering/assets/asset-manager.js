/**
 * @typedef {Object} ImageCache
 * @property {(url: string) => Promise<ImageBitmap|HTMLImageElement|null>} load
 * @property {(url: string) => (ImageBitmap|HTMLImageElement|null)} request
 * @property {(url: string) => (ImageBitmap|HTMLImageElement|null|Promise<ImageBitmap|HTMLImageElement|null>|undefined)} peek
 * @property {(fn: (url: string) => void) => (() => void)} subscribe
 */

/**
 * If the app is using `wsrv.nl` image proxy URLs, unwrap it to the original URL.
 *
 * @param {string} inputUrl
 * @returns {string}
 */
export function unwrapWeservUrl(inputUrl) {
  const raw = String(inputUrl || '').trim();
  if (!raw) return '';
  try {
    const u = new URL(raw);
    const host = String(u.hostname || '').toLowerCase();
    if (host !== 'wsrv.nl' && !host.endsWith('.wsrv.nl')) return raw;
    let nested = u.searchParams.get('url') || '';
    if (!nested) return raw;
    for (let i = 0; i < 2; i += 1) {
      if (!/%[0-9a-f]{2}/i.test(nested)) break;
      try {
        nested = decodeURIComponent(nested);
      } catch {
        break;
      }
    }
    return String(nested || '').trim() || raw;
  } catch {
    return raw;
  }
}

/* ═══════════════════════════════════════════════════════════════
 * Finding 4.1 + 4.2: Off-main-thread decoding via createImageBitmap.
 *
 * When supported the cache stores ImageBitmap (pre-decoded, GPU-
 * resident) instead of HTMLImageElement.  Drawing an ImageBitmap
 * skips the browser's decode + GPU-upload steps — 10–30 % faster
 * drawImage() calls.
 *
 * Decoding uses createImageBitmap(blob) which runs on a background
 * thread, eliminating main-thread jank during batch loads.
 *
 * MAX_INFLIGHT is scaled with navigator.hardwareConcurrency so
 * mobile devices (2–4 cores) don't saturate the decoder.
 *
 * Falls back to HTMLImageElement + img.decode() when
 * createImageBitmap is unavailable.
 * ═══════════════════════════════════════════════════════════════ */

/** Feature-detect createImageBitmap support once. */
const _supportsImageBitmap = typeof createImageBitmap === 'function';

/**
 * Create a concurrency-limited image cache for canvas rendering.
 *
 * @param {{ maxInflight?: number }=} options
 * @returns {ImageCache}
 */
export function createImageCache(options = {}) {
  const cache = new Map();
  const listeners = new Set();

  // M2: LRU eviction — cap decoded images to prevent unbounded GPU memory growth
  const MAX_CACHE_ENTRIES = Number.isFinite(Number(options.maxCacheEntries))
    ? Math.max(16, Math.floor(Number(options.maxCacheEntries)))
    : 256;

  const _evictOldest = () => {
    while (cache.size > MAX_CACHE_ENTRIES) {
      const oldest = cache.keys().next().value;
      if (oldest === undefined) break;
      const val = cache.get(oldest);
      cache.delete(oldest);
      // Release GPU texture memory for ImageBitmap entries
      if (val && typeof val.close === 'function') {
        try { val.close(); } catch (_) { /* ignore */ }
      }
    }
  };

  /* Scale MAX_INFLIGHT with hardware concurrency.
   * Desktop (8+ cores) → 24 (capped).
   * Mobile  (2–4 cores) → 6–8 for minimal decoder contention. */
  const defaultMax = Math.min(24, Math.max(6,
    (typeof navigator !== 'undefined' && Number.isFinite(navigator.hardwareConcurrency))
      ? navigator.hardwareConcurrency * 3
      : 24
  ));
  const MAX_INFLIGHT = Number.isFinite(Number(options.maxInflight))
    ? Math.max(1, Math.floor(Number(options.maxInflight)))
    : defaultMax;
  let inflight = 0;
  const pendingStart = new Set();
  const startQueue = [];
  const startEntries = new Map();
  let pumpRaf = 0;

  /** @param {string} url */
  const notify = (url) => {
    for (const fn of listeners) {
      try {
        fn(url);
      } catch (_) {
      }
    }
  };

  /* ══════════════════════════════════════════════════════════════
   * Finding F7: Immediate pump on completion.
   *
   * pumpNow() starts queued loads synchronously — called from
   * every completion callback so the next download begins with
   * zero delay.  schedulePump() is still used for the initial
   * request() / load() call (rAF-gated to avoid blocking first
   * paint), but completions bypass the rAF bottleneck entirely.
   * ══════════════════════════════════════════════════════════════ */

  const pumpNow = () => {
    while (inflight < MAX_INFLIGHT && startQueue.length > 0) {
      const url = startQueue.shift();
      if (!pendingStart.has(url)) continue;
      pendingStart.delete(url);
      const entry = startEntries.get(url);
      if (!entry) continue;
      startEntries.delete(url);

      inflight += 1;
      startLoad(url, entry);
    }
  };

  const schedulePump = () => {
    if (pumpRaf) return;
    pumpRaf = requestAnimationFrame(() => {
      pumpRaf = 0;
      pumpNow();
    });
  };

  /**
   * Start loading a single image URL.
   * Strategy:
   *   1. If createImageBitmap is supported → fetch blob → createImageBitmap
   *      (fully off-main-thread decode).
   *   2. Fallback → HTMLImageElement + img.decode().
   *
   * @param {string} url
   * @param {{ resolve: Function }} entry
   */
  const startLoad = (url, entry) => {
    if (_supportsImageBitmap) {
      // ── Off-main-thread path: fetch → blob → createImageBitmap ──
      fetch(url, { mode: 'cors', credentials: 'omit' })
        .then((res) => {
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
          return res.blob();
        })
        .then((blob) => createImageBitmap(blob))
        .then((bitmap) => {
          cache.set(url, bitmap);
          entry.resolve(bitmap);
          notify(url);
          inflight -= 1;
          pumpNow();
        })
        .catch(() => {
          // Fallback to HTMLImageElement if fetch/bitmap fails (e.g. CORS)
          startLoadFallback(url, entry);
        });
    } else {
      startLoadFallback(url, entry);
    }
  };

  /**
   * Fallback loader using HTMLImageElement + img.decode().
   * Used when createImageBitmap is unavailable or when the
   * off-main-thread path fails (e.g. strict CORS on the resource).
   *
   * @param {string} url
   * @param {{ resolve: Function }} entry
   */
  const startLoadFallback = (url, entry) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.decoding = 'async';
    img.onload = () => {
      const finish = () => {
        // If createImageBitmap is available, convert the loaded HTMLImageElement
        // to ImageBitmap so downstream code gets the GPU-ready asset.
        if (_supportsImageBitmap) {
          createImageBitmap(img).then((bitmap) => {
            cache.set(url, bitmap);
            entry.resolve(bitmap);
            notify(url);
            inflight -= 1;
            pumpNow();
          }).catch(() => {
            // Ultra-fallback: store the HTMLImageElement as-is
            cache.set(url, img);
            entry.resolve(img);
            notify(url);
            inflight -= 1;
            pumpNow();
          });
          return;
        }
        cache.set(url, img);
        entry.resolve(img);
        notify(url);
        inflight -= 1;
        pumpNow();
      };

      if (typeof img.decode === 'function') {
        img.decode().then(finish).catch(finish);
        return;
      }
      finish();
    };
    img.onerror = () => {
      cache.set(url, null);
      entry.resolve(null);
      notify(url);
      inflight -= 1;
      pumpNow();
    };
    img.src = url;
  };

  /**
   * @param {string} url
   * @returns {Promise<ImageBitmap|HTMLImageElement|null>}
   */
  const load = (url) => {
    if (!url) return Promise.resolve(null);
    if (cache.has(url)) {
      const cached = cache.get(url);
      if (cached && typeof cached.then === 'function') {
        schedulePump();
        return cached;
      }
      return Promise.resolve(cached);
    }

    const p = new Promise((resolve) => {
      pendingStart.add(url);
      startQueue.push(url);
      startEntries.set(url, { resolve });
      schedulePump();
    });

    cache.set(url, p);
    _evictOldest();
    schedulePump();
    return p;
  };

  /**
   * @param {string} url
   * @returns {ImageBitmap|HTMLImageElement|null}
   */
  const request = (url) => {
    if (!url) return null;
    const cached = cache.get(url);
    if (cached === undefined) {
      load(url);
      return null;
    }
    if (cached && typeof cached.then === 'function') return null;
    // M2: LRU touch — move to end of Map insertion order
    cache.delete(url);
    cache.set(url, cached);
    return cached;
  };

  /**
   * @param {(url: string) => void} fn
   * @returns {() => void}
   */
  const subscribe = (fn) => {
    if (typeof fn !== 'function') return () => { };
    listeners.add(fn);
    return () => listeners.delete(fn);
  };

  /**
   * @param {string} url
   * @returns {ImageBitmap|HTMLImageElement|null|Promise<ImageBitmap|HTMLImageElement|null>|undefined}
   */
  const peek = (url) => cache.get(url);

  return Object.freeze({ load, request, peek, subscribe });
}

/**
 * Mide la proporción natural (ancho / alto) de imágenes remotas para el
 * formato «cover» de Vía Cuenta y tarjetas libres (RN-13).
 *
 * - Caché acotada (LRU simple) por URL; los fallos también se cachean para no
 *   reintentar en cada apertura del diálogo.
 * - Una sola carga en vuelo por URL; los solicitantes concurrentes comparten
 *   la promesa.
 * - Nunca rechaza: resuelve `null` si la imagen no carga o vence el tiempo.
 * - `dispose()` corta las cargas pendientes (resuelven `null`).
 */

const DEFAULT_TIMEOUT_MS = 6000;
const DEFAULT_MAX_ENTRIES = 256;

function readRatio(image) {
  const width = Number(image.naturalWidth);
  const height = Number(image.naturalHeight);
  return width > 0 && height > 0 ? width / height : null;
}

export function createImageAspectService({ timeoutMs = DEFAULT_TIMEOUT_MS, maxEntries = DEFAULT_MAX_ENTRIES } = {}) {
  /** @type {Map<string, number|null>} */
  const cache = new Map();
  /** @type {Map<string, { promise: Promise<number|null>, settle: (ratio: number|null) => void }>} */
  const inFlight = new Map();
  let disposed = false;

  const remember = (url, ratio) => {
    cache.delete(url);
    cache.set(url, ratio);
    while (cache.size > maxEntries) {
      const oldest = cache.keys().next().value;
      if (oldest === undefined) break;
      cache.delete(oldest);
    }
  };

  const load = (url) => {
    let settle = () => {};
    const promise = new Promise((resolve) => {
      const image = new Image();
      image.decoding = "async";
      let done = false;
      let timer = 0;

      settle = (ratio) => {
        if (done) return;
        done = true;
        clearTimeout(timer);
        image.onload = null;
        image.onerror = null;
        image.src = "";
        inFlight.delete(url);
        if (!disposed) remember(url, ratio);
        resolve(ratio);
      };

      image.onload = () => settle(readRatio(image));
      image.onerror = () => settle(null);
      timer = setTimeout(() => settle(null), timeoutMs);
      image.src = url;
    });
    return { promise, settle };
  };

  /** @returns {Promise<number|null>} */
  const measure = (url) => {
    const key = String(url || "").trim();
    if (!key || disposed) return Promise.resolve(null);
    if (cache.has(key)) return Promise.resolve(cache.get(key));
    const pending = inFlight.get(key);
    if (pending) return pending.promise;
    const entry = load(key);
    inFlight.set(key, entry);
    return entry.promise;
  };

  /**
   * Mide varias URL en paralelo y devuelve un lector sincrónico.
   * @param {Iterable<string>} urls
   * @returns {Promise<(url: string) => number|null>}
   */
  const measureAll = async (urls) => {
    const list = Array.from(new Set(Array.from(urls, (url) => String(url || "").trim()).filter(Boolean)));
    const ratios = await Promise.all(list.map(measure));
    const byUrl = new Map(list.map((url, index) => [url, ratios[index]]));
    return (url) => byUrl.get(String(url || "").trim()) ?? null;
  };

  return Object.freeze({
    /** @returns {number|null|undefined} `undefined` si aún no se midió. */
    peek(url) {
      return cache.get(String(url || "").trim());
    },
    measure,
    measureAll,
    dispose() {
      disposed = true;
      for (const entry of Array.from(inFlight.values())) entry.settle(null);
      inFlight.clear();
      cache.clear();
    },
  });
}

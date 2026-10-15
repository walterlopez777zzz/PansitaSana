/**
 * Presets de tarjetas libres (RN-9) persistidos en `localStorage`.
 *
 * Tolerante a almacenamiento no disponible (modo privado, cuota agotada,
 * JSON corrupto): en ese caso opera en memoria durante la sesión. Los datos
 * se validan al leer para que un valor externo malformado nunca rompa la UI.
 *
 * @typedef {Object} FreeCardPreset
 * @property {string} id
 * @property {string} imageUrl
 * @property {string} title
 * @property {string} priceText
 * @property {number} savedAt   epoch ms
 */

export const FREE_CARD_PRESETS_STORAGE_KEY = "plantillas:add-cards:free-presets:v1";
const MAX_PRESETS = 24;
const MAX_FIELD_LENGTH = 2048;

function field(value, max = MAX_FIELD_LENGTH) {
  return String(value ?? "").trim().slice(0, max);
}

function normalizePreset(raw) {
  if (!raw || typeof raw !== "object") return null;
  const imageUrl = field(raw.imageUrl);
  const title = field(raw.title, 200);
  const priceText = field(raw.priceText, 32);
  if (!imageUrl || !title) return null;
  const savedAt = Number(raw.savedAt);
  return Object.freeze({
    id: field(raw.id, 64) || `${imageUrl}\u0000${title}`,
    imageUrl,
    title,
    priceText,
    savedAt: Number.isFinite(savedAt) ? savedAt : 0,
  });
}

function sameIdentity(a, b) {
  return a.imageUrl === b.imageUrl && a.title === b.title;
}

function resolveStorage(storage) {
  if (storage) return storage;
  try {
    return globalThis.localStorage || null;
  } catch {
    return null;
  }
}

/**
 * @param {{ storage?: Storage|null, key?: string, now?: () => number }} [options]
 */
export function createFreeCardPresetsStore({ storage, key = FREE_CARD_PRESETS_STORAGE_KEY, now = Date.now } = {}) {
  const backend = resolveStorage(storage);
  /** @type {ReadonlyArray<FreeCardPreset>|null} */
  let memory = null;

  const read = () => {
    if (memory) return memory;
    let parsed = [];
    try {
      const raw = backend?.getItem(key);
      const value = raw ? JSON.parse(raw) : [];
      parsed = Array.isArray(value) ? value : [];
    } catch {
      parsed = [];
    }
    const presets = [];
    for (const item of parsed) {
      const preset = normalizePreset(item);
      if (preset && !presets.some((existing) => sameIdentity(existing, preset))) presets.push(preset);
    }
    memory = Object.freeze(presets.slice(0, MAX_PRESETS));
    return memory;
  };

  const write = (presets) => {
    memory = Object.freeze(presets);
    try {
      backend?.setItem(key, JSON.stringify(memory));
    } catch {
      // Sin persistencia (cuota/privado): se conserva en memoria.
    }
    return memory;
  };

  return Object.freeze({
    /** @returns {ReadonlyArray<FreeCardPreset>} más recientes primero */
    list() {
      return read();
    },

    /**
     * Guarda o reemplaza (misma imagen + título) y lo coloca al frente.
     * @returns {FreeCardPreset|null}
     */
    save(input) {
      const preset = normalizePreset({ ...input, id: `${now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`, savedAt: now() });
      if (!preset) return null;
      const rest = read().filter((existing) => !sameIdentity(existing, preset));
      write([preset, ...rest].slice(0, MAX_PRESETS));
      return preset;
    },

    remove(id) {
      const target = String(id || "");
      const next = read().filter((preset) => preset.id !== target);
      if (next.length !== read().length) write(next);
    },
  });
}

/**
 * Decodificación de las tramas de sincronización `{ _sd, _sn }` que emite el
 * backend del carrito (AES-256-CBC + base64). Las respuestas en claro (modo
 * depuración) se devuelven sin tocar.
 */

const FRAME_KEY = new Uint8Array([
  99, 52, 114, 116, 95, 106, 53, 48, 110, 95, 116, 48, 107, 51, 110, 95,
  57, 102, 56, 97, 49, 98, 50, 99, 51, 100, 52, 101, 53, 102, 54, 103,
]);

/** @type {Promise<CryptoKey>|null} */
let keyPromise = null;

function importKey() {
  if (keyPromise === null) {
    keyPromise = crypto.subtle.importKey('raw', FRAME_KEY, { name: 'AES-CBC' }, false, ['decrypt']);
  }
  return keyPromise;
}

/** @param {string} b64 */
function fromBase64(b64) {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i += 1) out[i] = bin.charCodeAt(i);
  return out;
}

/**
 * @param {unknown} value
 * @returns {value is {_sd: string, _sn: string}}
 */
export function isSyncFrame(value) {
  return Boolean(value)
    && typeof value === 'object'
    && typeof (/** @type {{_sd?: unknown}} */ (value))._sd === 'string'
    && typeof (/** @type {{_sn?: unknown}} */ (value))._sn === 'string';
}

/**
 * @param {unknown} payload  Cuerpo JSON ya parseado.
 * @returns {Promise<unknown>} Payload en claro.
 */
export async function decodeFrame(payload) {
  if (!isSyncFrame(payload)) return payload;
  const key = await importKey();
  const plain = await crypto.subtle.decrypt(
    { name: 'AES-CBC', iv: fromBase64(payload._sn) },
    key,
    fromBase64(payload._sd),
  );
  return JSON.parse(new TextDecoder().decode(plain));
}

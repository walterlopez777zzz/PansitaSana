/**
 * Transporte del carrito: una petición = una promesa, sin estado compartido.
 *
 *  - Ruta única `POST /web.php` con `action=c9d` y `sub` ofuscado.
 *  - Cada petición lleva su propio AbortController (tiempo máximo + señal
 *    externa encadenada).
 *  - Sólo `get` reintenta: las mutaciones podrían duplicar efectos.
 *  - Las respuestas (también las de error HTTP) viajan en trama `_sd/_sn`; se
 *    decodifican siempre para rescatar `message` y `code`.
 */

import { decodeFrame } from './frame.js';

const ENDPOINT = '/web.php';
const GATEWAY_ACTION = 'c9d';

export const Sub = Object.freeze({
  GET: 'g3',
  ADD: 'a2',
  BUY_NOW: 'b1',
  SET_QUANTITY: 'q5',
  REMOVE: 'r4',
});

export const ErrorCode = Object.freeze({
  ABORTED: 'ABORTED',
  TIMEOUT: 'TIMEOUT',
  NETWORK: 'NETWORK',
  SERVER: 'SERVER',
  INVALID: 'INVALID',
  MAX_QUANTITY: 'MAX_QUANTITY',
  NOT_FOUND: 'NOT_FOUND',
});

const DEFAULT_MESSAGES = Object.freeze({
  [ErrorCode.ABORTED]: 'Operación cancelada.',
  [ErrorCode.TIMEOUT]: 'El servidor tardó demasiado. Inténtalo de nuevo.',
  [ErrorCode.NETWORK]: 'Sin conexión. Revisa tu red e inténtalo otra vez.',
  [ErrorCode.SERVER]: 'No pudimos actualizar tu carrito. Inténtalo de nuevo.',
  [ErrorCode.INVALID]: 'Respuesta no válida del servidor.',
  [ErrorCode.MAX_QUANTITY]: 'Alcanzaste el máximo por artículo.',
  [ErrorCode.NOT_FOUND]: 'Este artículo ya no está disponible.',
});

export class CartApiError extends Error {
  /**
   * @param {string} code
   * @param {string} [message]
   * @param {{status?: number, cause?: unknown}} [options]
   */
  constructor(code, message, options = {}) {
    super(message || DEFAULT_MESSAGES[code] || DEFAULT_MESSAGES[ErrorCode.SERVER], { cause: options.cause });
    this.name = 'CartApiError';
    this.code = code;
    this.status = options.status ?? 0;
  }

  /** Forma serializable para el store. */
  toState() {
    return Object.freeze({ code: this.code, message: this.message });
  }
}

/** @param {unknown} error */
export function isAbortError(error) {
  return (error instanceof CartApiError && error.code === ErrorCode.ABORTED)
    || (error instanceof Error && error.name === 'AbortError');
}

/** @param {unknown} error @returns {CartApiError} */
export function toCartApiError(error) {
  if (error instanceof CartApiError) return error;
  if (error instanceof Error && error.name === 'AbortError') return new CartApiError(ErrorCode.ABORTED, undefined, { cause: error });
  if (error instanceof TypeError) return new CartApiError(ErrorCode.NETWORK, undefined, { cause: error });
  return new CartApiError(ErrorCode.SERVER, undefined, { cause: error });
}

/** @param {Record<string, unknown>} fields */
function encodeBody(fields) {
  const body = new URLSearchParams();
  body.set('action', GATEWAY_ACTION);
  for (const [key, value] of Object.entries(fields)) {
    if (value === undefined || value === null) continue;
    body.set(key, String(value));
  }
  return body;
}

/** @param {unknown} payload @param {number} status */
function toResponseError(payload, status) {
  const data = payload && typeof payload === 'object' ? /** @type {Record<string, unknown>} */ (payload) : {};
  const message = typeof data.message === 'string' ? data.message : (typeof data.error === 'string' ? data.error : undefined);
  const code = typeof data.code === 'string' && data.code in ErrorCode
    ? data.code
    : (status === 404 ? ErrorCode.NOT_FOUND : ErrorCode.SERVER);
  return new CartApiError(code, message, { status });
}

/**
 * @param {number} ms
 * @param {AbortSignal|undefined} signal
 */
function delay(ms, signal) {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) { reject(new CartApiError(ErrorCode.ABORTED)); return; }
    const id = setTimeout(() => { signal?.removeEventListener('abort', onAbort); resolve(undefined); }, ms);
    function onAbort() { clearTimeout(id); reject(new CartApiError(ErrorCode.ABORTED)); }
    signal?.addEventListener('abort', onAbort, { once: true });
  });
}

/**
 * @typedef {Object} CartApiOptions
 * @property {typeof fetch} [fetch]
 * @property {string} [endpoint]
 * @property {number} [timeoutMs]
 * @property {number} [getRetries]
 * @property {number} [retryBackoffMs]
 * @property {(payload: unknown) => Promise<unknown>} [decode]
 */

/**
 * @typedef {Object} CartApi
 * @property {(options?: {signal?: AbortSignal}) => Promise<Record<string, unknown>>} get
 * @property {(input: {offerId: string, quantity?: number, signal?: AbortSignal}) => Promise<Record<string, unknown>>} add
 * @property {(input: {offerId: string, quantity?: number, signal?: AbortSignal}) => Promise<Record<string, unknown>>} buyNow
 * @property {(input: {offerId: string, quantity: number, signal?: AbortSignal}) => Promise<Record<string, unknown>>} setQuantity
 * @property {(input: {offerId: string, signal?: AbortSignal}) => Promise<Record<string, unknown>>} remove
 */

/**
 * @param {CartApiOptions} [options]
 * @returns {CartApi}
 */
export function createCartApi(options = {}) {
  const fetchImpl = options.fetch ?? ((input, init) => globalThis.fetch(input, init));
  const endpoint = options.endpoint ?? ENDPOINT;
  const timeoutMs = options.timeoutMs ?? 7000;
  const getRetries = options.getRetries ?? 2;
  const retryBackoffMs = options.retryBackoffMs ?? 300;
  const decode = options.decode ?? decodeFrame;

  /**
   * @param {string} sub
   * @param {Record<string, unknown>} fields
   * @param {AbortSignal|undefined} signal
   * @returns {Promise<Record<string, unknown>>}
   */
  async function request(sub, fields, signal) {
    if (signal?.aborted) throw new CartApiError(ErrorCode.ABORTED);

    const controller = new AbortController();
    let timedOut = false;
    const onAbort = () => controller.abort();
    signal?.addEventListener('abort', onAbort, { once: true });
    const timer = setTimeout(() => { timedOut = true; controller.abort(); }, timeoutMs);

    try {
      let response;
      try {
        response = await fetchImpl(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: encodeBody({ sub, ...fields }),
          credentials: 'same-origin',
          signal: controller.signal,
        });
      } catch (error) {
        if (timedOut) throw new CartApiError(ErrorCode.TIMEOUT, undefined, { cause: error });
        throw toCartApiError(error);
      }

      let payload;
      try {
        payload = await decode(await response.json());
      } catch (error) {
        if (timedOut) throw new CartApiError(ErrorCode.TIMEOUT, undefined, { cause: error });
        if (controller.signal.aborted) throw new CartApiError(ErrorCode.ABORTED, undefined, { cause: error });
        if (!response.ok) throw new CartApiError(ErrorCode.SERVER, undefined, { status: response.status, cause: error });
        throw new CartApiError(ErrorCode.INVALID, undefined, { status: response.status, cause: error });
      }

      if (!payload || typeof payload !== 'object') {
        throw new CartApiError(ErrorCode.INVALID, undefined, { status: response.status });
      }
      const data = /** @type {Record<string, unknown>} */ (payload);
      if (!response.ok || data.success !== true) throw toResponseError(data, response.status);
      return data;
    } finally {
      clearTimeout(timer);
      signal?.removeEventListener('abort', onAbort);
    }
  }

  /** @param {CartApiError} error */
  const isRetryable = (error) => error.code === ErrorCode.NETWORK
    || error.code === ErrorCode.TIMEOUT
    || (error.code === ErrorCode.SERVER && (error.status === 0 || error.status >= 500));

  return Object.freeze({
    async get({ signal } = {}) {
      let attempt = 0;
      for (;;) {
        try {
          return await request(Sub.GET, {}, signal);
        } catch (error) {
          const apiError = toCartApiError(error);
          if (attempt >= getRetries || !isRetryable(apiError)) throw apiError;
          attempt += 1;
          await delay(retryBackoffMs * 2 ** (attempt - 1), signal);
        }
      }
    },

    add({ offerId, quantity = 1, signal }) {
      return request(Sub.ADD, { offerId, quantity }, signal);
    },

    buyNow({ offerId, quantity = 1, signal }) {
      return request(Sub.BUY_NOW, { offerId, quantity }, signal);
    },

    setQuantity({ offerId, quantity, signal }) {
      return request(Sub.SET_QUANTITY, { offerId, quantity }, signal);
    },

    remove({ offerId, signal }) {
      return request(Sub.REMOVE, { offerId }, signal);
    },
  });
}

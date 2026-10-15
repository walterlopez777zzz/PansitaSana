import { VIA_CUENTA_API_ENDPOINT } from "../../constants/endpoints.js";
import { normalizeViaCuentaProducts } from "./via-cuenta-normalizer.js";

/**
 * Cliente HTTP de `api/via-cuenta.php`.
 *
 * - Una sola petición en vuelo: `load()` aborta la anterior antes de iniciar.
 * - `If-None-Match` + `304` conservan los productos previos.
 * - Errores legibles en español; el JSON inválido (p. ej. `die()` de PHP) no
 *   propaga excepciones crudas.
 *
 * @typedef {Object} ViaCuentaLoadResult
 * @property {"ready"|"not-modified"} status
 * @property {ReadonlyArray<import("./via-cuenta-normalizer.js").ViaCuentaProduct>|null} products  null cuando `status === "not-modified"`
 * @property {string|null} etag
 * @property {string} loadedAt
 */

export const DEFAULT_VIA_CUENTA_TIMEOUT_MS = 12000;

export class ViaCuentaRequestError extends Error {
  /**
   * @param {string} message  Texto listo para mostrar al operador.
   * @param {{ cause?: unknown, aborted?: boolean }} [options]
   */
  constructor(message, options = {}) {
    super(message);
    this.name = "ViaCuentaRequestError";
    this.aborted = options.aborted === true;
    if (options.cause !== undefined) this.cause = options.cause;
  }
}

function describeHttpFailure(status) {
  if (status === 401 || status === 403) return "Sesión no autorizada para leer Vía Cuenta.";
  if (status === 404) return "El servicio de Vía Cuenta no está disponible.";
  if (status >= 500) return "El servidor de Vía Cuenta respondió con error.";
  return `No se pudo cargar Vía Cuenta (HTTP ${status}).`;
}

async function parseJsonSafely(response) {
  try {
    return await response.json();
  } catch (cause) {
    throw new ViaCuentaRequestError("La respuesta de Vía Cuenta no es válida.", { cause });
  }
}

/**
 * @param {{ endpoint?: string, timeoutMs?: number, fetchImpl?: typeof fetch }} [options]
 */
export function createViaCuentaClient(options = {}) {
  const endpoint = options.endpoint || VIA_CUENTA_API_ENDPOINT;
  const timeoutMs = Number.isFinite(options.timeoutMs) && options.timeoutMs > 0
    ? options.timeoutMs
    : DEFAULT_VIA_CUENTA_TIMEOUT_MS;
  const fetchImpl = options.fetchImpl || ((input, init) => fetch(input, init));

  /** @type {AbortController|null} */
  let activeController = null;

  function abort() {
    if (activeController) {
      activeController.abort();
      activeController = null;
    }
  }

  /**
   * @param {{ etag?: string|null, signal?: AbortSignal }} [params]
   * @returns {Promise<ViaCuentaLoadResult>}
   */
  async function load(params = {}) {
    abort();
    const controller = new AbortController();
    activeController = controller;

    const onExternalAbort = () => controller.abort();
    params.signal?.addEventListener("abort", onExternalAbort, { once: true });

    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, timeoutMs);

    const headers = { Accept: "application/json" };
    if (params.etag) headers["If-None-Match"] = params.etag;

    try {
      const response = await fetchImpl(endpoint, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers,
        signal: controller.signal,
      });

      const loadedAt = new Date().toISOString();

      if (response.status === 304) {
        return { status: "not-modified", products: null, etag: params.etag ?? null, loadedAt };
      }
      if (!response.ok) {
        throw new ViaCuentaRequestError(describeHttpFailure(response.status));
      }

      const payload = await parseJsonSafely(response);
      return {
        status: "ready",
        products: normalizeViaCuentaProducts(payload),
        etag: response.headers.get("ETag"),
        loadedAt,
      };
    } catch (cause) {
      if (cause instanceof ViaCuentaRequestError) throw cause;
      if (controller.signal.aborted) {
        throw new ViaCuentaRequestError(
          timedOut
            ? "Vía Cuenta tardó demasiado en responder."
            : "Carga de Vía Cuenta cancelada.",
          { cause, aborted: !timedOut },
        );
      }
      throw new ViaCuentaRequestError("No se pudo conectar con Vía Cuenta.", { cause });
    } finally {
      clearTimeout(timer);
      params.signal?.removeEventListener("abort", onExternalAbort);
      if (activeController === controller) activeController = null;
    }
  }

  return Object.freeze({
    load,
    abort,
    isLoading: () => activeController !== null,
  });
}

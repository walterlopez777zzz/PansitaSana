import { createViaCuentaClient, ViaCuentaRequestError } from "../domain/via-cuenta/via-cuenta-client.js";
import { selectViaCuentaCatalog } from "../domain/via-cuenta/catalog-state.js";

/**
 * Orquesta la carga del catálogo Vía Cuenta hacia `state.catalog.viaCuenta`.
 *
 * Garantías:
 * - Nunca hay dos cargas en paralelo; una nueva llamada reutiliza la promesa
 *   en vuelo (`ensureLoaded`) o la reemplaza (`reload`).
 * - Un `304` conserva `products` y solo actualiza `loadedAt`.
 * - Tras `dispose()` ninguna respuesta tardía toca el store.
 */
export function createViaCuentaCatalogService({ store, client = createViaCuentaClient() }) {
  /** @type {Promise<void>|null} */
  let inFlight = null;
  let disposed = false;

  function patchCatalog(patch) {
    if (disposed) return;
    store.setState((state) => {
      const current = selectViaCuentaCatalog(state);
      return {
        ...state,
        catalog: {
          ...(state?.catalog || {}),
          viaCuenta: { ...current, ...patch },
        },
      };
    });
  }

  async function runLoad() {
    const previous = selectViaCuentaCatalog(store.getState());
    patchCatalog({ status: "loading", error: null });
    try {
      const result = await client.load({ etag: previous.etag });
      if (disposed) return;
      if (result.status === "not-modified") {
        patchCatalog({ status: "ready", loadedAt: result.loadedAt, error: null });
        return;
      }
      patchCatalog({
        status: "ready",
        products: result.products,
        etag: result.etag,
        loadedAt: result.loadedAt,
        error: null,
      });
    } catch (error) {
      if (disposed) return;
      if (error instanceof ViaCuentaRequestError && error.aborted) {
        patchCatalog({ status: previous.products.length ? "ready" : "idle" });
        return;
      }
      const message = error instanceof ViaCuentaRequestError
        ? error.message
        : "No se pudo cargar Vía Cuenta.";
      patchCatalog({ status: "error", error: message });
    }
  }

  function reload() {
    if (disposed) return Promise.resolve();
    inFlight = runLoad().finally(() => {
      inFlight = null;
    });
    return inFlight;
  }

  function ensureLoaded() {
    if (disposed) return Promise.resolve();
    if (inFlight) return inFlight;
    if (selectViaCuentaCatalog(store.getState()).status === "ready") return Promise.resolve();
    return reload();
  }

  function setOffersMode(enabled) {
    if (disposed) return;
    store.setState((state) => {
      const catalog = state?.catalog || {};
      if (catalog.offersMode === Boolean(enabled)) return state;
      return { ...state, catalog: { ...catalog, offersMode: Boolean(enabled) } };
    });
  }

  function dispose() {
    disposed = true;
    client.abort();
    inFlight = null;
  }

  return Object.freeze({ ensureLoaded, reload, setOffersMode, dispose });
}

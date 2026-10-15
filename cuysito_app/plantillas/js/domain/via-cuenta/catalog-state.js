/**
 * Rama `state.catalog` del store. No forma parte de la plantilla persistida
 * ni del history (ver `SNAPSHOT_SLICE_KEYS` en `HistoryManager`).
 *
 * @typedef {"idle"|"loading"|"ready"|"error"} ViaCuentaCatalogStatus
 *
 * @typedef {Object} ViaCuentaCatalogState
 * @property {ViaCuentaCatalogStatus} status
 * @property {ReadonlyArray<import("./via-cuenta-normalizer.js").ViaCuentaProduct>} products
 * @property {string|null} etag
 * @property {string|null} error
 * @property {string|null} loadedAt
 *
 * @typedef {Object} CatalogState
 * @property {ViaCuentaCatalogState} viaCuenta
 * @property {boolean} offersMode  RN-6: usar `offerPriceSoles` solo si el operador lo activa.
 */

const EMPTY_PRODUCTS = Object.freeze([]);

/** @returns {CatalogState} */
export function createInitialCatalogState() {
  return {
    viaCuenta: {
      status: "idle",
      products: EMPTY_PRODUCTS,
      etag: null,
      error: null,
      loadedAt: null,
    },
    offersMode: false,
  };
}

/** @returns {CatalogState} */
export function selectCatalog(state) {
  const catalog = state?.catalog;
  return catalog && typeof catalog === "object" ? catalog : createInitialCatalogState();
}

/** @returns {ViaCuentaCatalogState} */
export function selectViaCuentaCatalog(state) {
  const viaCuenta = selectCatalog(state).viaCuenta;
  return viaCuenta && typeof viaCuenta === "object"
    ? viaCuenta
    : createInitialCatalogState().viaCuenta;
}

export function selectOffersMode(state) {
  return selectCatalog(state).offersMode === true;
}

export function selectViaCuentaProducts(state) {
  const products = selectViaCuentaCatalog(state).products;
  return Array.isArray(products) ? products : EMPTY_PRODUCTS;
}

export function findViaCuentaProduct(state, productId) {
  const id = String(productId || "").trim();
  if (!id) return null;
  return selectViaCuentaProducts(state).find((product) => product.id === id) || null;
}

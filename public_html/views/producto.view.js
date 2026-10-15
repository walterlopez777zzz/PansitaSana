/**
 * Static product page view module (/producto/{slug}--{id}).
 *
 * Same strategy as the item view: instant paint from staticProductsMap when
 * the product is already in memory (SPA navigation from a Vía Regalo card),
 * otherwise a single aggregated gateway request resolves the product row so
 * deep links and refreshes work standalone. Sold-out products render with
 * disabled purchase buttons ("Agotado"), exactly like the store cards.
 *
 * @type {import('./view').ViewModule}
 */
let currentId = null;
const productCache = new Map(); // id -> product row (page lifetime)

function renderLoading(outlet) {
  outlet.innerHTML =
    '<div class="item-page item-page--loading" aria-busy="true">' +
    '<div class="custom-spinner-container"><svg class="custom-spinner" viewBox="0 0 50 50">' +
    '<circle cx="25" cy="25" r="20" fill="none" stroke="currentColor" stroke-width="5"></circle>' +
    '</svg></div></div>';
}

function renderError(outlet) {
  outlet.innerHTML =
    '<div class="item-page"><a class="item-page__back" data-route data-back href="/via-regalo"><span>← </span><span>Tienda</span></a>' +
    '<p class="item-page__notfound">No se pudo cargar este producto. Inténtalo de nuevo.</p></div>';
}

function localProduct(app, id) {
  const found = app.itemPages.findItemDataById(id);
  return (found && found.isStatic) ? found.data : null;
}

export default {
  name: 'producto',

  async mount({ outlet, signal, params, api }) {
    if (!outlet) return;
    const app = window.BananoApp;
    const id = params?.id || '';
    currentId = id;
    if (!id) { renderError(outlet); return; }

    const cached = productCache.get(id);
    if (cached) {
      app.itemPages.renderProductPage(outlet, cached);
      return;
    }

    const local = localProduct(app, id);
    if (local) {
      app.itemPages.renderProductPage(outlet, local);
      return;
    }

    renderLoading(outlet);
    try {
      const payload = await api.itemPage.get({ id, kind: 'producto' }, signal);
      if (signal.aborted || currentId !== id) return;
      if (!payload || payload.ok === false || !payload.product) {
        renderError(outlet);
        return;
      }
      productCache.set(id, payload.product);
      app.itemPages.renderProductPage(outlet, payload.product);
    } catch (err) {
      if (signal.aborted) return;
      console.error('[producto] load failed', err);
      renderError(outlet);
    }
  },

  unmount() {
    currentId = null;
    try { window.BananoApp?.itemPages?.unmount(); } catch (_) { /* noop */ }
  },
};

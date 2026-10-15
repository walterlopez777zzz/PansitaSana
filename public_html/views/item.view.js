/**
 * Item page view module (/objeto/{tipo}/{slug}--{id}).
 *
 * Render strategy — "instant when possible, one request otherwise":
 *   1. If the shop data is already in memory (SPA navigation from a card),
 *      the page paints IMMEDIATELY from the local snapshot entry, including
 *      the manual video decision (the primordial snapshot carries
 *      `manualVideo`), so there is never an image→video flash.
 *   2. In parallel (or as the only source on deep links / out-of-shop items),
 *      ONE aggregated gateway request ('k3t' → get_item_page.php) brings the
 *      HQ cosmetic record, availability, manual video and shop history, and
 *      the page re-renders enriched.
 *
 * All rendering lives in app.js (BananoApp.itemPages.renderObjectPage) so the
 * page reuses the store's price/discount/media helpers. This module only
 * orchestrates data acquisition per URL id (remountable when the id changes)
 * and declares the page's section backdrop (window.SectionBackdrop) from the
 * same data: the entry's section layout locally, the server-resolved URL once
 * the aggregate arrives.
 *
 * @type {import('./view').ViewModule}
 */
let currentId = null;
const aggregateCache = new Map(); // id -> aggregated payload (page lifetime)

function sectionLayoutOf(data) {
  return data?.entry?.layout || data?.container?.layout || null;
}

function declareBackdrop(payload, local) {
  window.SectionBackdrop?.setPage({
    url: payload?.sectionBackground || null,
    layout: sectionLayoutOf(payload) || sectionLayoutOf(local),
  });
}

function renderLoading(outlet) {
  outlet.innerHTML =
    '<div class="item-page item-page--loading" aria-busy="true">' +
    '<div class="custom-spinner-container"><svg class="custom-spinner" viewBox="0 0 50 50">' +
    '<circle cx="25" cy="25" r="20" fill="none" stroke="currentColor" stroke-width="5"></circle>' +
    '</svg></div></div>';
}

function renderError(outlet) {
  declareBackdrop(null, null);
  outlet.innerHTML =
    '<div class="item-page"><a class="item-page__back" data-route data-back href="/via-regalo"><span>← </span><span>Tienda</span></a>' +
    '<p class="item-page__notfound">No se pudo cargar este objeto. Inténtalo de nuevo.</p></div>';
}

function localData(app, id) {
  const found = app.itemPages.findShopEntryForPageId(id);
  if (!found) return null;
  const c = found.container;
  return {
    id,
    entry: found.entry,
    container: c,
    containerBg: c ? { colors: c.colors, bgStyle: c.bgStyle } : null,
    cosmetic: null,
    availability: null,
    videoUrl: found.entry?.manualVideo?.url || null,
    videoStyles: found.entry?.manualVideo?.styles || null,
    itemVideos: found.entry?.itemVideos || null,
    itemRenderImages: null,
    shopHistory: found.entry?.shopHistory || null,
  };
}

function mergedData(id, payload, local) {
  return {
    id,
    entry: payload.entry || local?.entry || null,
    container: local?.container || null,
    containerBg: payload.containerBg || local?.containerBg || null,
    cosmetic: payload.cosmetic || null,
    availability: payload.availability || null,
    videoUrl: payload.video?.url || local?.videoUrl || null,
    videoStyles: payload.video?.styles || local?.videoStyles || null,
    itemVideos: payload.itemVideos || local?.itemVideos || null,
    itemRenderImages: payload.itemRenderImages || null,
    shopHistory: payload.shopHistory || local?.shopHistory || null,
  };
}

export default {
  name: 'item',

  async mount({ outlet, signal, params, api }) {
    if (!outlet) return;
    const app = window.BananoApp;
    const id = params?.id || '';
    currentId = id;
    if (!id) { renderError(outlet); return; }

    const local = localData(app, id);
    const cachedPayload = aggregateCache.get(id);

    if (cachedPayload) {
      declareBackdrop(cachedPayload, local);
      app.itemPages.renderObjectPage(outlet, mergedData(id, cachedPayload, local));
      return;
    }

    // Paint instantly from in-memory shop data while the aggregate loads.
    if (local && (local.entry || local.container)) {
      declareBackdrop(null, local);
      app.itemPages.renderObjectPage(outlet, local);
    } else {
      renderLoading(outlet);
    }

    try {
      const payload = await api.itemPage.get({ id, kind: 'objeto' }, signal);
      if (signal.aborted || currentId !== id) return;
      if (!payload || payload.ok === false) {
        if (!local || (!local.entry && !local.container)) renderError(outlet);
        return;
      }
      aggregateCache.set(id, payload);
      declareBackdrop(payload, local);
      app.itemPages.renderObjectPage(outlet, mergedData(id, payload, local));
    } catch (err) {
      if (signal.aborted) return;
      console.error('[item] aggregate load failed', err);
      if (!local || (!local.entry && !local.container)) renderError(outlet);
    }
  },

  unmount() {
    currentId = null;
    window.SectionBackdrop?.clearPage();
    try { window.BananoApp?.itemPages?.unmount(); } catch (_) { /* noop */ }
  },
};

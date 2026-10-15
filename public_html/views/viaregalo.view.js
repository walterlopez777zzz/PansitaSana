/**
 * Vía Regalo view module.
 *
 * Data is produced by the shop Web Worker (see app.js). We start it exactly
 * once; the rendered cards then live in the DOM for the lifetime of the page.
 * Returning to this view is a no-op — no re-fetch, no re-render (this is the
 * "se cargan todas las tarjetas nuevamente" bug, fixed by keep-alive).
 *
 * @type {import('./view').ViewModule}
 */
let started = false;

export default {
  name: 'viaregalo',

  async mount({ api }) {
    if (started) return; // keep-alive: cards already loaded
    started = true;
    try {
      await api.shop.start();
    } catch (err) {
      started = false; // allow a retry on next visit
      throw err;
    }
  },

  unmount() {
    // Intentionally empty: the shop worker keeps running and the rendered
    // cards stay in the DOM so revisiting is instant.
  },
};

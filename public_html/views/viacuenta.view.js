/**
 * Vía Cuenta view module.
 *
 * Lazily loads the account catalog (pavos / club / pases / packs) the first
 * time the view is visited — NOT eagerly at app startup as before. The fetched
 * data and the rendered DOM are cached for the page lifetime, so navigating
 * away and back does not re-fetch or re-render. If the load is aborted (user
 * navigates away mid-flight) or fails, the next visit retries.
 *
 * Fetch goes through the unified API client (`api.account.getCatalog`);
 * rendering + spinner reuse the existing helpers in app.js.
 *
 * @type {import('./view').ViewModule}
 */
let state = 'idle'; // 'idle' | 'loading' | 'ready' | 'error'
let cached = null;

export default {
  name: 'viacuenta',

  async mount({ signal, api }) {
    if (state === 'ready' || state === 'loading') return; // keep-alive / dedupe
    state = 'loading';
    const app = window.BananoApp;

    try {
      app.viaCuenta.spinner(true);
      const data = await api.account.getCatalog(signal);
      if (signal.aborted) { state = 'idle'; return; }
      cached = data;
      app.viaCuenta.render(data);
      state = 'ready';
    } catch (err) {
      if (signal.aborted) { state = 'idle'; return; }
      console.error('[viacuenta] catalog load failed', err);
      app.viaCuenta.renderError(err);
      state = 'error'; // retry on next visit
    } finally {
      app.viaCuenta.spinner(false);
    }
  },

  unmount() {
    // DOM is kept. If a load was in flight, the router has already aborted the
    // shared AbortSignal, which sends mount() down its `signal.aborted` path
    // and resets state to 'idle' so the next visit retries cleanly.
  },
};

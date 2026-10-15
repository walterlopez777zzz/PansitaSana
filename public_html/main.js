/**
 * main.js — Client navigation core (Option A architecture).
 *
 * Single source of truth for navigation between the storefront views
 * (Vía Regalo, Vía Cuenta, Juegos). Replaces the previous "casero" approach
 * (hash routing + manual show/hide + `document.body[data-view]` observed by
 * scattered MutationObservers + SEO via regex injection in router.php).
 *
 * Responsibilities, all centralized here:
 *   - Declarative route table (the only place a view is registered).
 *   - History API router (clean URLs, real back/forward, scroll restoration).
 *   - Observable store for navigation state.
 *   - Unified API client that maps domain calls to the obfuscated gateway codes.
 *   - View lifecycle: lazy load (code-split via dynamic import), mount once,
 *     keep-alive on revisit (NO re-fetch / NO re-render), abort in-flight work
 *     when navigating away.
 *
 * Heavy rendering (shop cards, cart, AES gateway transport) stays in
 * the legacy `app.js` and is reached through the explicit `window.BananoApp`
 * integration surface — this module owns *when* and *whether* to load, app.js
 * owns *how* to render.
 *
 * Build note: this file is referenced from index.php as a classic deferred
 * script and uses dynamic `import()` for views. The frontend build resolves
 * dynamic-import specifiers at runtime via `window.__FRONTEND_ASSETS__`, so view
 * files are hashed and code-split automatically. Static cross-file `import`s are
 * intentionally avoided because the build does not rewrite their specifiers;
 * the exceptions are `catalog-filter/` and `cart/`, ESM folders the build
 * bundles into single files before hashing (see ESM_BUNDLE_ENTRIES in
 * build-frontend.mjs).
 */
(function bootstrapNavigation() {
  'use strict';

  /* ───────────────────────────── store ───────────────────────────── */

  function createStore(initialState) {
    let state = { ...initialState };
    const listeners = new Set();

    return {
      getState: () => state,
      setState(patch) {
        const partial = typeof patch === 'function' ? patch(state) : patch;
        if (!partial) return;
        const prev = state;
        const next = { ...state, ...partial };
        let changed = false;
        for (const key of Object.keys(partial)) {
          if (prev[key] !== next[key]) { changed = true; break; }
        }
        if (!changed) return;
        state = next;
        listeners.forEach((fn) => {
          try { fn(state, prev); } catch (err) { console.error('[store] listener error', err); }
        });
      },
      subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    };
  }

  /* ──────────────────────────── api client ────────────────────────── */

  /**
   * Domain → obfuscated gateway action codes. This is the single place the
   * codes live on the client; views speak in domain terms (`api.account
   * .getCatalog()`), never in `h4v`/`g1c`/… The low-level transport + AES
   * decoding lives in app.js and is reached via `BananoApp.gatewayFetch`.
   */
  const GATEWAY_ACTIONS = Object.freeze({
    accountCatalog: 'h4v',
    dailyOffer: 'd6j',
    autoOffers: 'y9a',
    itemPage: 'k3t',
    spinInit: 's4w',
    spinGo: 'z2f',
    raffleHubStatus: 'u2h',
  });

  function createApiClient(app) {
    const gateway = (action, signal, params) => {
      if (!app || typeof app.gatewayFetch !== 'function') {
        return Promise.reject(new Error('BananoApp.gatewayFetch unavailable'));
      }
      return app.gatewayFetch(action, { signal, params });
    };

    return {
      shop: {
        /** Vía Regalo data is produced by the shop Web Worker (see app.js). */
        start: () => app.startShop(),
      },
      account: {
        getCatalog: (signal) => gateway(GATEWAY_ACTIONS.accountCatalog, signal),
      },
      itemPage: {
        /** Aggregated payload for /objeto and /producto pages (1 request). */
        get: ({ id, kind }, signal) => gateway(GATEWAY_ACTIONS.itemPage, signal, { id, kind }),
      },
      games: {
        /** Giro Diario: sesión, saldo, disponibilidad y preview de la ruleta. */
        spinInit: (signal) => gateway(GATEWAY_ACTIONS.spinInit, signal),
        /**
         * Ejecuta un giro. `expectFree` viaja como `expect_free=1` para que el
         * servidor rechace la petición si el giro gratis dejó de estar disponible
         * entre la última lectura y el clic. `mode` (all | no_tracks) y `filter`
         * (clave de categoría activa) acotan el pool de premios; el porcentaje
         * final lo decide el servidor según su configuración.
         */
        spinGo: ({ type, expectFree, mode, filter }, signal) =>
          gateway(GATEWAY_ACTIONS.spinGo, signal, {
            type,
            expect_free: expectFree ? '1' : undefined,
            mode: mode || undefined,
            filter: filter || undefined,
          }),
        /** Sorteo Flash: resumen de sólo lectura del sorteo vigente para el hub. */
        raffleHubStatus: (signal) => gateway(GATEWAY_ACTIONS.raffleHubStatus, signal),
      },
    };
  }

  /* ────────────────────────── route table ─────────────────────────── */

  const SITE_NAME = 'Banano Store';

  /**
   * The declarative route table — the ONLY place a storefront view is wired.
   * Adding a view = one entry here + one file in /views. No more touching
   * index.php nav, router.php, a VIEWS map and two observers.
   *
   * @type {Array<{
   *   path: string, aliases?: string[], name: string, dataView: string,
   *   outletId: string, navIds: string[],
   *   title: string, description: string,
   *   load: () => Promise<{ default: import('./views/view').ViewModule }>
   * }>}
   */
  const routes = [
    {
      path: '/via-regalo',
      aliases: ['/', '/viaregalo', '/tienda'],
      name: 'viaregalo',
      dataView: 'viaregalo',
      outletId: 'view-viaregalo',
      navIds: ['nav-viaregalo', 'mobile-nav-viaregalo'],
      title: `${SITE_NAME} — Vía Regalo`,
      description: 'Explora la tienda de Fortnite y envía cualquier objeto como regalo de forma segura con Banano Store.',
      load: () => import('./views/viaregalo.view.js'),
    },
    {
      path: '/via-cuenta',
      aliases: ['/viacuenta'],
      name: 'viacuenta',
      dataView: 'viacuenta',
      outletId: 'view-viacuenta',
      navIds: ['nav-viacuenta', 'mobile-nav-viacuenta'],
      title: `${SITE_NAME} — Vía Cuenta`,
      description: 'Compra pavos, el Club de Fortnite, pases y packs directamente en tu cuenta con Banano Store.',
      load: () => import('./views/viacuenta.view.js'),
    },
    {
      path: '/juegos',
      name: 'juegos',
      dataView: 'games',
      outletId: 'view-juegos',
      navIds: ['nav-juegos', 'mobile-nav-juegos'],
      title: `${SITE_NAME} — Juegos`,
      description: 'Gana recompensas jugando en el Rewards Hub de Banano Store.',
      load: () => import('./views/juegos.view.js'),
    },
    {
      // /via-regalo/{tipo}/{slug}--{id} — página individual de objeto de la tienda.
      path: '/via-regalo',
      pattern: /^\/via-regalo\/([a-z0-9\-]+)\/(?:.*--)?([^/]+)$/i,
      paramNames: ['tipo', 'id'],
      name: 'item',
      dataView: 'item',
      outletId: 'view-item',
      navIds: ['nav-viaregalo', 'mobile-nav-viaregalo'],
      title: `${SITE_NAME} — Objeto`,
      description: 'Detalles del objeto de Fortnite en Banano Store.',
      load: () => import('./views/item.view.js'),
    },
    {
      // /via-cuenta/{slug}--{id} — página individual de producto estático.
      path: '/via-cuenta',
      pattern: /^\/via-cuenta\/(?:.*--)?([^/]+)$/i,
      paramNames: ['id'],
      name: 'producto',
      dataView: 'producto',
      outletId: 'view-producto',
      navIds: ['nav-viacuenta', 'mobile-nav-viacuenta'],
      title: `${SITE_NAME} — Producto`,
      description: 'Detalles del producto en Banano Store.',
      load: () => import('./views/producto.view.js'),
    },
  ];

  function buildRouteIndex(table) {
    const byPath = new Map();
    for (const route of table) {
      if (route.pattern) continue; // pattern routes are matched dynamically
      byPath.set(normalizePath(route.path), route);
      (route.aliases || []).forEach((alias) => byPath.set(normalizePath(alias), route));
    }
    return byPath;
  }

  function normalizePath(pathname) {
    let p = String(pathname || '/').split('?')[0].split('#')[0];
    if (p.length > 1 && p.endsWith('/')) p = p.replace(/\/+$/, '');
    return p.toLowerCase() || '/';
  }

  /* ─────────────────────────────── router ─────────────────────────── */

  class Router {
    constructor({ table, store, api }) {
      this.table = table;
      this.byPath = buildRouteIndex(table);
      this.store = store;
      this.api = api;
      this.modules = new Map();        // route.name -> resolved view module
      this.currentRoute = null;
      this.currentPathname = null;     // canonical URL of the mounted view
      this.navToken = 0;               // guards against out-of-order navigations
      this.abortController = null;     // aborts the leaving view's in-flight work
      this.scrollPositions = new Map(); // pathname -> scrollY
      this._indicator = this._resolveIndicator();
    }

    match(pathname) {
      const raw = String(pathname || '/').split('?')[0].split('#')[0];
      const clean = raw.length > 1 && raw.endsWith('/') ? raw.replace(/\/+$/, '') : raw;
      for (const route of this.table) {
        if (!route.pattern) continue;
        const m = clean.match(route.pattern);
        if (m) {
          const params = {};
          (route.paramNames || []).forEach((key, i) => {
            params[key] = decodeURIComponent(m[i + 1] || '');
          });
          return { route, params, canonical: clean };
        }
      }
      const route = this.byPath.get(normalizePath(pathname)) || this.table[0];
      return { route, params: {}, canonical: route.path };
    }

    /** Programmatic SPA navigation (used by app.js: cards, cart, subitems). */
    navigate(pathname) {
      this._navigate(pathname);
    }

    start() {
      if ('scrollRestoration' in history) history.scrollRestoration = 'manual';

      // Intercept activations on declared route links (clean-URL anchors).
      document.addEventListener('click', (e) => this._onClick(e));
      window.addEventListener('popstate', () => {
        this._navigate(window.location.pathname, { replace: true, fromPop: true });
      });

      if (this._indicator?.track) {
        const ro = new ResizeObserver(() => this._updateIndicator());
        ro.observe(this._indicator.track);
      }

      // First paint: adopt whatever URL the server served (no reload).
      this._navigate(window.location.pathname, { replace: true, initial: true });
    }

    _onClick(e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const anchor = e.target.closest('a[data-route]');
      if (!anchor) return;
      if (anchor.target && anchor.target !== '_self') return;

      const href = anchor.getAttribute('href') || '';
      const url = new URL(href, window.location.origin);
      if (url.origin !== window.location.origin) return;

      e.preventDefault();

      // Links "volver" (data-back): si hay historial interno, retrocede de
      // verdad — popstate restaura la posición de scroll guardada. Con deep
      // link (sin historial propio) cae a la navegación normal del href.
      if (anchor.hasAttribute('data-back') && this._canGoBack) {
        history.back();
        return;
      }

      if (normalizePath(url.pathname) === normalizePath(window.location.pathname)) {
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }
      this._navigate(url.pathname);
    }

    async _navigate(pathname, { replace = false, initial = false, fromPop = false } = {}) {
      const { route, params, canonical } = this.match(pathname);
      const prev = this.currentRoute;
      const paramsKey = JSON.stringify(params);
      if (prev && prev.name === route.name && this.currentParamsKey === paramsKey && !initial) {
        // Same view re-selected — keep-alive, nothing to load or re-render.
        this._applyChrome(route, { fromPop, pathname: canonical });
        return;
      }

      const token = ++this.navToken;

      // Remember where we were so back/forward can restore scroll. Keyed by
      // the actual URL, not route.path: several routes share the same base
      // path (e.g. shop ↔ item page) and would overwrite each other's entry.
      if (prev && this.currentPathname) {
        this.scrollPositions.set(this.currentPathname, window.scrollY);
      }

      // Cancel any in-flight work owned by the view we are leaving.
      if (this.abortController) this.abortController.abort();
      this.abortController = new AbortController();
      const { signal } = this.abortController;

      const paramsChanged = prev && prev.name === route.name && this.currentParamsKey !== paramsKey;
      if (prev && (prev.name !== route.name || paramsChanged)) {
        const prevModule = this.modules.get(prev.name);
        try { prevModule?.unmount?.({ store: this.store, api: this.api }); }
        catch (err) { console.error(`[router] ${prev.name}.unmount failed`, err); }
      }

      // Update the URL + chrome immediately so navigation feels instant.
      if (!fromPop) {
        const method = replace ? 'replaceState' : 'pushState';
        history[method]({ view: route.name }, '', canonical);
        if (method === 'pushState') this._canGoBack = true;
      }
      this.currentRoute = route;
      this.currentPathname = canonical;
      this.currentParamsKey = paramsKey;
      this.store.setState({ view: route.name, dataView: route.dataView });
      this._applyChrome(route, { fromPop, initial, pathname: canonical });

      // Lazily load the view chunk, then mount it (idempotent / cached).
      let module = this.modules.get(route.name);
      try {
        if (!module) {
          const mod = await route.load();
          if (token !== this.navToken) return; // superseded mid-load
          module = mod.default || mod;
          this.modules.set(route.name, module);
        }
        const outlet = document.getElementById(route.outletId);
        await module.mount?.({
          outlet,
          signal,
          params,
          store: this.store,
          api: this.api,
        });
      } catch (err) {
        if (signal.aborted) return;
        console.error(`[router] failed to mount "${route.name}"`, err);
      }
    }

    /** Everything that must change on a route switch, in one place. */
    _applyChrome(route, { fromPop = false, initial = false, pathname = null } = {}) {
      // Document metadata (was previously injected by regex in router.php).
      document.title = route.title;
      this._setMeta('description', route.description);
      document.body.dataset.view = route.dataView;
      // Contrato con los consumidores de la vista activa (p. ej. SectionBackdrop).
      document.dispatchEvent(new CustomEvent('banano:view-change', {
        detail: { view: route.dataView, name: route.name, initial },
      }));

      // Show the active outlet, hide the rest.
      for (const r of this.table) {
        const wrapper = document.getElementById(r.outletId);
        if (!wrapper) continue;
        if (r.name === route.name) {
          wrapper.removeAttribute('hidden');
          wrapper.style.display = '';
        } else {
          wrapper.setAttribute('hidden', '');
        }
      }

      // Nav active state + ARIA across desktop and mobile tabs.
      const activeNavIds = new Set(route.navIds);
      for (const r of this.table) {
        for (const navId of r.navIds) {
          const el = document.getElementById(navId);
          if (!el) continue;
          const isActive = activeNavIds.has(navId);
          el.classList.toggle('active', isActive);
          el.setAttribute('aria-selected', String(isActive));
          if (isActive) el.setAttribute('aria-current', 'page');
          else el.removeAttribute('aria-current');
          if (el.getAttribute('role') === 'tab') el.setAttribute('tabindex', isActive ? '0' : '-1');
        }
      }

      this._updateIndicator();

      // Scroll: restore on back/forward, jump to top on a fresh navigation.
      const scrollKey = pathname || route.path;
      if (fromPop && this.scrollPositions.has(scrollKey)) {
        const y = this.scrollPositions.get(scrollKey) || 0;
        requestAnimationFrame(() => window.scrollTo({ top: y, behavior: 'auto' }));
      } else if (!initial) {
        window.scrollTo({ top: 0, behavior: 'auto' });
      }

      this._announce(route);
    }

    _resolveIndicator() {
      const track = document.querySelector('.site-nav__track');
      const indicator = track?.querySelector('.site-nav__indicator');
      if (indicator) indicator.setAttribute('data-no-transition', '');
      return { track, indicator, ready: false };
    }

    _updateIndicator() {
      const ind = this._indicator;
      if (!ind || !ind.track || !ind.indicator) return;
      const activeTab = ind.track.querySelector('.site-nav__tab.active');
      if (!activeTab) { ind.indicator.style.opacity = '0'; return; }
      const trackRect = ind.track.getBoundingClientRect();
      const tabRect = activeTab.getBoundingClientRect();
      ind.indicator.style.width = `${tabRect.width}px`;
      ind.indicator.style.transform = `translateX(${tabRect.left - trackRect.left}px)`;
      ind.indicator.style.opacity = '1';
      if (!ind.ready) {
        requestAnimationFrame(() => requestAnimationFrame(() => {
          ind.indicator.removeAttribute('data-no-transition');
          ind.ready = true;
        }));
      }
    }

    _setMeta(name, content) {
      let tag = document.head.querySelector(`meta[name="${name}"]`);
      if (!tag) {
        tag = document.createElement('meta');
        tag.setAttribute('name', name);
        document.head.appendChild(tag);
      }
      tag.setAttribute('content', content);
    }

    _announce(route) {
      const region = document.getElementById('view-outlet') || document.getElementById(route.outletId);
      if (!region) return;
      if (!region.hasAttribute('tabindex')) region.setAttribute('tabindex', '-1');
      // Defer focus so the active panel is visible before we move focus into it.
      requestAnimationFrame(() => { try { region.focus({ preventScroll: true }); } catch (_) { /* noop */ } });
    }
  }

  /* ──────────────────────────── bootstrap ─────────────────────────── */

  function whenAppReady() {
    if (window.BananoApp) return Promise.resolve(window.BananoApp);
    return new Promise((resolve) => {
      document.addEventListener('banano:app-ready', () => resolve(window.BananoApp), { once: true });
    });
  }

  function init() {
    // Fondos de sección: independiente de app.js (arranca aunque éste falle) y
    // antes del router, para que escuche el primer `banano:view-change`.
    window.SectionBackdrop?.boot();

    // Buscador y filtros: chrome global e independiente del router. Se
    // sincroniza con `banano:view-change` y con `banano:catalog-change`.
    import('./catalog-filter/index.js')
      .then(({ mountCatalogFilter }) => mountCatalogFilter())
      .catch((error) => console.error('[main] catalog-filter no disponible', error));

    // Carrito: cajón, contadores y CTAs de página. Independiente de app.js;
    // se comunica por eventos `banano:cart-*` en `document`.
    import('./cart/index.js')
      .then(({ mountCart }) => mountCart({ backdrop: window.Backdrop ?? null }))
      .catch((error) => console.error('[main] cart no disponible', error));

    whenAppReady().then((app) => {
      const store = createStore({ view: null, dataView: null });
      const api = createApiClient(app);
      const router = new Router({ table: routes, store, api });
      window.__BananoRouter = router; // debugging handle
      router.start();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();

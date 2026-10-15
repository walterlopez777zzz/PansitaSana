/**
 * View module contract (documentation / type hints only — no runtime code).
 *
 * Every storefront view exported as the default of a `*.view.js` file
 * implements this shape. The router loads the module lazily (code-split via
 * dynamic import), then drives its lifecycle:
 *
 *   - `mount(ctx)` is called when the view becomes active. It MUST be
 *     idempotent: on the first activation it loads + renders; on later
 *     activations (keep-alive) it should return immediately without
 *     re-fetching or re-rendering.
 *   - `unmount(ctx)` is called when navigating away. The DOM is kept; this is
 *     only for pausing/teardown of live work. In-flight async work started in
 *     `mount` is cancelled by the shared `ctx.signal` (aborted by the router).
 *
 * @typedef {Object} ViewContext
 * @property {HTMLElement|null} outlet   The view's container element.
 * @property {AbortSignal} signal        Aborts when navigating away.
 * @property {Object} params             Route params (reserved for future use).
 * @property {Object} store              The navigation store.
 * @property {Object} api                The unified API client.
 *
 * @typedef {Object} ViewModule
 * @property {string} name
 * @property {(ctx: ViewContext) => (void | Promise<void>)} [mount]
 * @property {(ctx: ViewContext) => void} [unmount]
 */

export {};

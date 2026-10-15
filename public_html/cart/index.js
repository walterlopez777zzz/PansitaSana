/**
 * Raíz de composición del carrito. Único módulo que conoce a todos los demás.
 *
 * Eventos públicos (en `document`):
 *  - `banano:cart-mount` / `banano:cart-unmount`  → entry.js (CTAs de página)
 *  - `banano:cart-added` ({offerId, host})           ← entry.js
 *  - `banano:cart-view` (cancelable, {offerId, isStatic}) ← drawer.js
 *  - `banano:cart-open` / `banano:cart-close`      ← drawer.js
 *  - `banano:cart-change` ({count, previous})        ← index.js
 */

import { createCartStore, selectTotals } from './store.js';
import { createCartApi } from './api.js';
import { createCartOperations } from './operations.js';
import { mountCartDrawer } from './drawer.js';
import { mountCartBadges } from './badge.js';
import { mountCartEntries } from './entry.js';

const OPEN_SELECTOR = '[data-cart-action="open"]';

/**
 * @typedef {Object} CartHandle
 * @property {import('./store.js').CartStore} store
 * @property {import('./operations.js').CartOperations} ops
 * @property {import('./drawer.js').CartDrawer|null} drawer
 * @property {() => void} dispose
 */

/**
 * @param {Object} [options]
 * @param {Document} [options.doc]
 * @param {{acquire: Function, release: Function}|null} [options.backdrop]
 * @returns {CartHandle}
 */
export function mountCart({ doc = document, backdrop = null } = {}) {
  const store = createCartStore();
  const api = createCartApi();
  const ops = createCartOperations({ store, api });
  const drawer = mountCartDrawer({ store, ops, doc, backdrop });
  const badges = mountCartBadges({ store, root: doc });
  const entries = mountCartEntries({ store, ops, doc });

  /**
   * Los disparadores conmutan el cajón: cada pulsación alterna de forma
   * determinista (abrir ↔ cerrar), también en ráfaga, sin perder ninguna.
   * @param {MouseEvent} event
   */
  function onTriggerClick(event) {
    const trigger = /** @type {HTMLElement|null} */ (/** @type {Element} */ (event.target).closest(OPEN_SELECTOR));
    if (!trigger || !drawer) return;
    event.preventDefault();
    drawer.toggle(trigger);
  }

  /**
   * En escritorio el cajón es un panel no modal, así que abrirlo al añadir no
   * interrumpe la navegación; en móvil la hoja modal sólo se abre a petición.
   * `open` es idempotente: si ya está abierto no hace nada y si se está
   * cerrando registra la reapertura.
   * @param {Event} event
   */
  function onAdded(event) {
    if (!drawer || !drawer.isPanel()) return;
    const { host } = /** @type {CustomEvent<{offerId: string, host: HTMLElement}>} */ (event).detail;
    drawer.open(host.querySelector('[data-cart-action="decrement"]') ?? host);
  }

  /** @param {PageTransitionEvent} event */
  function onPageShow(event) {
    if (event.persisted) void ops.hydrate();
  }

  let lastCount = selectTotals(store.getState()).count;
  const unsubscribe = store.subscribe((state) => {
    const { count } = selectTotals(state);
    if (count === lastCount) return;
    const previous = lastCount;
    lastCount = count;
    doc.dispatchEvent(new CustomEvent('banano:cart-change', { detail: { count, previous } }));
  });

  doc.addEventListener('click', onTriggerClick);
  doc.addEventListener('banano:cart-added', onAdded);
  window.addEventListener('pageshow', onPageShow);
  void ops.hydrate();

  return Object.freeze({
    store,
    ops,
    drawer,
    dispose() {
      doc.removeEventListener('click', onTriggerClick);
      doc.removeEventListener('banano:cart-added', onAdded);
      window.removeEventListener('pageshow', onPageShow);
      unsubscribe();
      entries.dispose();
      badges.dispose();
      drawer?.dispose();
      ops.dispose();
    },
  });
}

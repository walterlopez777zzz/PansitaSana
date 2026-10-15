/**
 * Puntos de entrada al carrito en las páginas de objeto/producto.
 *
 * Contrato (desacoplado de app.js):
 *  - El renderizador de la página crea un host `[data-cart-entry]` con el
 *    producto serializado en `data-cart-product` y emite en `document`
 *    `banano:cart-mount` con `{ host }`. Al desmontar emite
 *    `banano:cart-unmount` con `{ host }` (o simplemente retira el nodo).
 *  - Este módulo pinta dentro del host «Comprar ahora» + «Agregar al carrito»
 *    o, si la línea ya existe, el selector de cantidad; y los mantiene en
 *    sincronía con el store.
 *  - Tras añadir con éxito emite `banano:cart-added` con `{ offerId, host }`;
 *    qué hacer después (p. ej. abrir el cajón) lo decide la raíz (index.js).
 *
 * @typedef {import('./operations.js').CartProduct & {disabled?: boolean, disabledLabel?: string}} EntryProduct
 */

import { selectLine, selectIsPending } from './store.js';
import { AddOutcome } from './operations.js';
import { toCartApiError } from './api.js';

const HOST_SELECTOR = '[data-cart-entry]';
const FEEDBACK_MS = 1800;
const SVG_NS = 'http://www.w3.org/2000/svg';
const XLINK = 'http://www.w3.org/1999/xlink';
/** Glifo de basurero compartido con /plantillas (mismo trazo y geometría). */
const TRASH_GLYPH = Object.freeze({
  viewBox: '0 0 100 100',
  d: 'M35 12H65M12 32H88M25 32L34 90H66L75 32',
  strokeWidth: '10',
});

/** @param {string} className */
function svgElement(className) {
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('class', className);
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  return svg;
}

/**
 * @param {string} id
 * @param {string} className
 */
function icon(id, className) {
  const svg = svgElement(className);
  const use = document.createElementNS(SVG_NS, 'use');
  use.setAttribute('href', `#${id}`);
  use.setAttributeNS(XLINK, 'xlink:href', `#${id}`);
  svg.appendChild(use);
  return svg;
}

/** @param {string} className */
function trashIcon(className) {
  const svg = svgElement(className);
  svg.setAttribute('viewBox', TRASH_GLYPH.viewBox);
  const path = document.createElementNS(SVG_NS, 'path');
  path.setAttribute('fill', 'none');
  path.setAttribute('stroke', 'currentColor');
  path.setAttribute('stroke-width', TRASH_GLYPH.strokeWidth);
  path.setAttribute('stroke-linecap', 'round');
  path.setAttribute('stroke-linejoin', 'round');
  path.setAttribute('d', TRASH_GLYPH.d);
  svg.appendChild(path);
  return svg;
}

/**
 * @param {string} className
 * @param {string} action
 * @param {string|null} [label]  Sólo para controles cuyo contenido visible es un icono.
 */
function button(className, action, label = null) {
  const node = document.createElement('button');
  node.type = 'button';
  node.className = className;
  node.dataset.cartAction = action;
  if (label !== null) node.setAttribute('aria-label', label);
  return node;
}

/** @param {HTMLElement} host @returns {EntryProduct|null} */
function readProduct(host) {
  try {
    const raw = JSON.parse(host.dataset.cartProduct ?? 'null');
    if (!raw || typeof raw !== 'object' || !raw.offerId) return null;
    return /** @type {EntryProduct} */ ({ ...raw, offerId: String(raw.offerId) });
  } catch {
    return null;
  }
}

/**
 * @typedef {Object} EntryInstance
 * @property {HTMLElement} host
 * @property {EntryProduct} product
 * @property {HTMLButtonElement} buy
 * @property {HTMLButtonElement} add
 * @property {HTMLElement} stepper
 * @property {HTMLButtonElement} dec
 * @property {HTMLButtonElement} inc
 * @property {HTMLOutputElement} qty
 * @property {HTMLElement} status
 * @property {import('./store.js').CartLine|null} line
 * @property {boolean} busy
 * @property {ReturnType<typeof setTimeout>|0} feedbackTimer
 */

/**
 * @param {Object} deps
 * @param {import('./store.js').CartStore} deps.store
 * @param {import('./operations.js').CartOperations} deps.ops
 * @param {Document} [deps.doc]
 * @returns {{dispose: () => void}}
 */
export function mountCartEntries({ store, ops, doc = document }) {
  /** @type {Map<HTMLElement, EntryInstance>} */
  const instances = new Map();
  /** @type {import('./store.js').CartError|null} */
  let seenError = store.getState().lastError;

  /** @param {HTMLElement} host */
  function build(host) {
    if (instances.has(host)) return;
    const product = readProduct(host);
    if (!product) return;

    const disabled = product.disabled === true;

    const buy = button('cart-cta cart-cta--primary cart-entry__buy', 'buy-now');
    buy.textContent = disabled && product.disabledLabel ? product.disabledLabel : 'Comprar ahora';

    const add = button('cart-cta cart-cta--secondary cart-entry__add', 'add');
    add.append(icon('icon-cart-plus', 'cart-cta__icon'), doc.createTextNode('Agregar al carrito'));

    const stepper = doc.createElement('div');
    stepper.className = 'cart-stepper cart-stepper--entry';
    stepper.setAttribute('role', 'group');
    stepper.setAttribute('aria-label', 'Cantidad en el carrito');
    stepper.hidden = true;

    const dec = button('cart-stepper__btn', 'decrement', 'Reducir cantidad');
    dec.append(
      icon('icon-minus', 'cart-stepper__icon cart-stepper__icon--minus'),
      trashIcon('cart-stepper__icon cart-stepper__icon--trash'),
    );
    const qty = doc.createElement('output');
    qty.className = 'cart-stepper__value';
    qty.textContent = '1';
    const inc = button('cart-stepper__btn', 'increment', 'Aumentar cantidad');
    inc.append(icon('icon-plus', 'cart-stepper__icon'));
    stepper.append(dec, qty, inc);

    const status = doc.createElement('p');
    status.className = 'cart-entry__status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    if (disabled) {
      buy.disabled = true;
      add.disabled = true;
      host.dataset.state = 'disabled';
    }

    host.replaceChildren(buy, add, stepper, status);
    host.dataset.cartMounted = 'true';

    /** @type {EntryInstance} */
    const instance = { host, product, buy, add, stepper, dec, inc, qty, status, line: null, busy: false, feedbackTimer: 0 };
    instances.set(host, instance);
    sync(instance, store.getState(), true);
  }

  /** @param {HTMLElement} host */
  function teardown(host) {
    const instance = instances.get(host);
    if (!instance) return;
    clearTimeout(instance.feedbackTimer);
    instances.delete(host);
    delete host.dataset.cartMounted;
  }

  /**
   * @param {EntryInstance} instance
   * @param {string} message
   * @param {'added'|'max'|'error'|null} kind
   */
  function feedback(instance, message, kind) {
    clearTimeout(instance.feedbackTimer);
    instance.status.textContent = message;
    if (kind) instance.host.dataset.feedback = kind; else delete instance.host.dataset.feedback;
    instance.feedbackTimer = setTimeout(() => {
      delete instance.host.dataset.feedback;
      instance.status.textContent = '';
    }, FEEDBACK_MS);
  }

  /**
   * @param {EntryInstance} instance
   * @param {import('./store.js').CartState} state
   * @param {boolean} initial
   */
  function sync(instance, state, initial) {
    if (instance.host.dataset.state === 'disabled') return;
    const line = selectLine(state, instance.product.offerId);
    const pending = line !== null && selectIsPending(state, instance.product.offerId);
    const hadLine = instance.line !== null;
    const hasLine = line !== null;

    if (line !== instance.line || initial) {
      instance.line = line;
      instance.add.hidden = hasLine;
      instance.stepper.hidden = !hasLine;

      if (line) {
        instance.qty.textContent = String(line.quantity);
        const remove = line.quantity <= 1;
        instance.dec.dataset.intent = remove ? 'remove' : 'decrement';
        instance.dec.setAttribute('aria-label', remove ? 'Quitar del carrito' : 'Reducir cantidad');
        const atMax = line.quantity >= state.maxQuantity;
        if (atMax) instance.inc.setAttribute('aria-disabled', 'true'); else instance.inc.removeAttribute('aria-disabled');
      }

      // El control con foco puede desaparecer: se traslada al equivalente.
      if (!initial && hadLine !== hasLine) {
        const active = doc.activeElement;
        if (active && instance.host.contains(active)) {
          (hasLine ? instance.inc : instance.add).focus({ preventScroll: true });
        }
      }
    }

    const nextState = instance.busy ? 'busy' : hasLine ? (pending ? 'syncing' : 'in-cart') : 'idle';
    if (instance.host.dataset.state !== nextState) instance.host.dataset.state = nextState;
  }

  /** @param {import('./store.js').CartState} state */
  function onState(state) {
    for (const [host, instance] of instances) {
      if (!host.isConnected) { teardown(host); continue; }
      sync(instance, state, false);
    }
    if (state.lastError !== seenError) {
      seenError = state.lastError;
      if (seenError?.offerId) {
        for (const instance of instances.values()) {
          if (instance.product.offerId === seenError.offerId) feedback(instance, seenError.message, 'error');
        }
      }
    }
  }

  /**
   * Compra directa de vuelo único por instancia: el botón queda ocupado hasta
   * que la operación asienta (con éxito se navega; si la página vuelve desde
   * bfcache el estado ya está liberado).
   * @param {EntryInstance} instance
   */
  async function buyNow(instance) {
    if (instance.busy) return;
    instance.busy = true;
    instance.buy.setAttribute('aria-busy', 'true');
    instance.buy.setAttribute('aria-disabled', 'true');
    sync(instance, store.getState(), false);
    try {
      await ops.buyNow(instance.product);
    } catch (error) {
      if (instances.get(instance.host) === instance) feedback(instance, toCartApiError(error).message, 'error');
    } finally {
      instance.busy = false;
      instance.buy.removeAttribute('aria-busy');
      instance.buy.removeAttribute('aria-disabled');
      if (instances.get(instance.host) === instance) sync(instance, store.getState(), false);
    }
  }

  /** @param {MouseEvent} event */
  function onClick(event) {
    const control = /** @type {HTMLElement|null} */ (/** @type {Element} */ (event.target).closest('[data-cart-action]'));
    if (!control || control.getAttribute('aria-disabled') === 'true') return;
    const host = /** @type {HTMLElement|null} */ (control.closest(HOST_SELECTOR));
    const instance = host ? instances.get(host) : undefined;
    if (!instance || instance.busy) return;

    switch (control.dataset.cartAction) {
      case 'add': {
        const outcome = ops.add(instance.product);
        if (outcome === AddOutcome.MAX) {
          feedback(instance, `Máximo ${store.getState().maxQuantity} por artículo`, 'max');
          break;
        }
        feedback(instance, 'Añadido al carrito', 'added');
        doc.dispatchEvent(new CustomEvent('banano:cart-added', {
          detail: { offerId: instance.product.offerId, host: instance.host },
        }));
        break;
      }
      case 'increment': {
        const line = instance.line;
        if (line && line.quantity >= store.getState().maxQuantity) {
          feedback(instance, `Máximo ${store.getState().maxQuantity} por artículo`, 'max');
        } else {
          ops.increment(instance.product.offerId);
        }
        break;
      }
      case 'decrement':
        ops.decrement(instance.product.offerId);
        break;
      case 'buy-now':
        void buyNow(instance);
        break;
      default:
        break;
    }
  }

  /** @param {Event} event */
  function onMount(event) {
    const host = /** @type {CustomEvent<{host?: HTMLElement}>} */ (event).detail?.host;
    if (host instanceof HTMLElement) build(host);
  }

  /** @param {Event} event */
  function onUnmount(event) {
    const host = /** @type {CustomEvent<{host?: HTMLElement}>} */ (event).detail?.host;
    if (host instanceof HTMLElement) teardown(host);
  }

  doc.addEventListener('banano:cart-mount', onMount);
  doc.addEventListener('banano:cart-unmount', onUnmount);
  doc.addEventListener('click', onClick);
  const unsubscribe = store.subscribe(onState);

  for (const host of doc.querySelectorAll(HOST_SELECTOR)) build(/** @type {HTMLElement} */ (host));

  return Object.freeze({
    dispose() {
      unsubscribe();
      doc.removeEventListener('banano:cart-mount', onMount);
      doc.removeEventListener('banano:cart-unmount', onUnmount);
      doc.removeEventListener('click', onClick);
      for (const host of Array.from(instances.keys())) teardown(host);
    },
  });
}

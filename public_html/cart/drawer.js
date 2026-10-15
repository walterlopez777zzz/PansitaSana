/**
 * Cajón del carrito: proyecta el estado del store sobre el `<dialog>` estático
 * declarado en cuysito_app/cart/drawer.php.
 *
 *  - Render por reconciliación: cada línea se materializa una sola vez a partir
 *    de `<template id="cart-line-template">` y después sólo se parchean los
 *    nodos cuyo valor cambió. Nunca se usa innerHTML con datos del servidor.
 *  - Los renders se coalescen por frame (requestAnimationFrame).
 *  - Dos presentaciones sobre el mismo `<dialog>`, decididas al abrir según
 *    `PANEL_MEDIA` (espejo del breakpoint de cart.css):
 *      · móvil: hoja modal (`showModal`) — foco atrapado, Escape y `::backdrop`
 *        del navegador; velo visual y scroll-lock del Backdrop global.
 *      · escritorio: panel lateral NO modal (`show`) — sin velo ni scroll-lock,
 *        la página (y el header sticky) sigue viva; Escape y pointer fuera
 *        del panel lo cierran. Si el breakpoint cambia con el cajón abierto
 *        se cierra, porque un dialog no puede alternar modalidad en caliente.
 *  - Ciclo de vida gobernado por una máquina de estados explícita
 *    (`Phase`: closed → open ⇄ closing → closed). Toda transición pasa por
 *    `open`/`toggle`/`close`/`finishClose`, que son idempotentes: repetir un
 *    disparador nunca duplica `show()`, oyentes ni velos.
 *  - Movimiento del panel reversible en tiempo real: el desplazamiento es una
 *    transición CSS (`data-state`), así que abrir durante el cierre (o cerrar
 *    durante la apertura) sólo cambia el destino y el navegador invierte el
 *    recorrido desde el valor exacto en curso; la presentación (diálogo
 *    abierto, oyentes, velo) se conserva y no hay nada que esperar. El cierre
 *    real del diálogo espera a que asiente el movimiento del propio panel
 *    (Web Animations `finished`) o es inmediato si no lo hay
 *    (`prefers-reduced-motion`); la espera se cancela por AbortSignal al
 *    revertir, ante un cierre externo o en `dispose`, sin callbacks huérfanos.
 *  - El `close` nativo llega en una tarea posterior a `dialog.close()`; el
 *    desmontaje de la presentación se hace de forma síncrona en la propia
 *    transición y el evento sólo se atiende si el cierre fue externo (p. ej.
 *    el navegador ante Escape), nunca si el diálogo ya volvió a abrirse.
 */

import { actions, selectIsPending, selectTotals } from './store.js';
import { formatCount, formatMoney, thumbnailUrl } from './format.js';

const BACKDROP_ID = 'cart';
const NOTICE_MS = 4000;
const PANEL_MEDIA = '(min-width: 768px)';
/**
 * Pulsar fuera del panel lo cierra, salvo sobre un CTA de tarjeta (la línea
 * aparece dentro) o sobre un disparador del carrito (actúa como conmutador
 * en su propio `click`).
 */
const OUTSIDE_EXEMPT_SELECTOR = '[data-cart-entry], [data-cart-action="open"]';

/** Fases del ciclo de vida del cajón. */
const Phase = Object.freeze({
  CLOSED: 'closed',
  OPEN: 'open',
  CLOSING: 'closing',
});

/**
 * @typedef {Object} CartDrawer
 * @property {(trigger?: Element|null) => void} open    Idempotente; durante el cierre lo revierte al instante.
 * @property {(trigger?: Element|null) => void} toggle  Conmutador para disparadores de la página.
 * @property {() => void} close                         Idempotente.
 * @property {() => boolean} isOpen
 * @property {() => boolean} isPanel  true si la presentación actual es el panel de escritorio.
 * @property {() => void} dispose
 */

/**
 * @param {Element} scope
 * @returns {Record<string, HTMLElement>}
 */
function collectSlots(scope) {
  /** @type {Record<string, HTMLElement>} */
  const slots = {};
  for (const node of scope.querySelectorAll('[data-cart-slot]')) {
    slots[/** @type {HTMLElement} */ (node).dataset.cartSlot] = /** @type {HTMLElement} */ (node);
  }
  return slots;
}

/**
 * Asigna `textContent` sólo si cambió (evita invalidar layout sin motivo).
 * @param {HTMLElement} node @param {string} value
 */
function setText(node, value) {
  if (node.textContent !== value) node.textContent = value;
}

/** @param {HTMLElement} node @param {boolean} hidden */
function setHidden(node, hidden) {
  if (node.hidden !== hidden) node.hidden = hidden;
}

/** @param {Element} node @param {string} name @param {string|null} value */
function setAttr(node, name, value) {
  if (value === null) {
    if (node.hasAttribute(name)) node.removeAttribute(name);
  } else if (node.getAttribute(name) !== value) {
    node.setAttribute(name, value);
  }
}

/**
 * Publica en la miniatura los mismos `data-*` que las tarjetas de la tienda, de
 * modo que el encuadre por tipo/objeto de style.css (`.item-tile[data-…]`)
 * aplique sin reglas duplicadas; el degradado va en la caja inferior.
 * @param {HTMLElement} media
 * @param {HTMLElement} box
 * @param {import('./store.js').CartTile} tile
 */
function applyTile(media, box, tile) {
  setAttr(media, 'data-item-type', tile.itemType);
  setAttr(media, 'data-item-id', tile.itemId);
  setAttr(media, 'data-dynamic-bundle', tile.isDynamicBundle ? '1' : null);
  setAttr(media, 'data-car-offer', tile.isCarOffer ? '1' : null);
  const background = tile.background ?? '';
  if (box.style.background !== background) box.style.background = background;
}

/**
 * Fuerza el recálculo de estilos del nodo. Fija el "estilo previo" de una
 * transición CSS (para que el siguiente cambio de atributo la dispare) y hace
 * que animaciones/transiciones recién activadas figuren en `getAnimations()`.
 * Sólo estilo, no layout: `opacity` no depende de la geometría.
 * @param {HTMLElement} node
 */
function flushStyle(node) {
  void getComputedStyle(node).opacity;
}

/**
 * Ejecuta `done` cuando asientan (terminan o se cancelan) las animaciones y
 * transiciones propias del nodo, o de inmediato si no hay ninguna (p. ej.
 * `prefers-reduced-motion`). Sólo mira las del nodo, no las de sus
 * descendientes. Si `signal` se aborta antes, `done` no se ejecuta.
 *
 * @param {HTMLElement} node
 * @param {AbortSignal} signal
 * @param {() => void} done
 */
function afterAnimations(node, signal, done) {
  if (signal.aborted) return;
  flushStyle(node);
  const running = typeof node.getAnimations === 'function' ? node.getAnimations() : [];
  if (running.length === 0) { done(); return; }
  void Promise.allSettled(running.map((animation) => animation.finished)).then(() => {
    if (!signal.aborted) done();
  });
}

/**
 * @param {Object} deps
 * @param {import('./store.js').CartStore} deps.store
 * @param {import('./operations.js').CartOperations} deps.ops
 * @param {Document} [deps.doc]
 * @param {{acquire: Function, release: Function}|null} [deps.backdrop]
 * @returns {CartDrawer|null}  null si el marcado no está presente.
 */
export function mountCartDrawer({ store, ops, doc = document, backdrop = null }) {
  const dialog = /** @type {HTMLDialogElement|null} */ (doc.getElementById('cart-drawer'));
  const template = /** @type {HTMLTemplateElement|null} */ (doc.getElementById('cart-line-template'));
  if (!dialog || !template) return null;

  const panel = /** @type {HTMLElement} */ (dialog.querySelector('.cart-drawer__panel'));
  const title = /** @type {HTMLElement} */ (dialog.querySelector('#cart-drawer-title'));
  const slots = collectSlots(dialog);
  const list = /** @type {HTMLUListElement} */ (slots.lines);
  const lineTemplate = /** @type {HTMLLIElement} */ (template.content.firstElementChild);

  /** @type {Map<string, HTMLLIElement>} */
  const rows = new Map();
  /** @type {Map<string, HTMLLIElement>} */
  const leaving = new Map();
  /** @type {WeakMap<HTMLLIElement, import('./store.js').CartLine>} */
  const rendered = new WeakMap();
  /** @type {WeakMap<HTMLLIElement, Record<string, HTMLElement>>} */
  const rowSlots = new WeakMap();

  const panelMedia = window.matchMedia(PANEL_MEDIA);
  /** Vida del componente: cancela oyentes y esperas de animación al desmontar. */
  const lifetime = new AbortController();

  let frame = 0;
  let noticeTimer = 0;
  /** @type {import('./store.js').CartError|null} */
  let shownError = null;
  let lastState = store.getState();
  let lastMax = lastState.maxQuantity;

  /** @type {string} */
  let phase = Phase.CLOSED;
  /** Oyentes y velo de la presentación vigente (panel o modal). @type {AbortController|null} */
  let presentation = null;
  /** Espera del movimiento de salida en curso. @type {AbortController|null} */
  let exit = null;

  /* ───────────── filas ───────────── */

  /** @param {import('./store.js').CartLine} line */
  function createRow(line) {
    const row = /** @type {HTMLLIElement} */ (lineTemplate.cloneNode(true));
    row.dataset.offerId = line.offerId;
    rowSlots.set(row, collectSlots(row));
    return row;
  }

  /**
   * @param {HTMLLIElement} row
   * @param {import('./store.js').CartLine} line
   * @param {boolean} pending
   * @param {number} maxQuantity
   */
  function patchRow(row, line, pending, maxQuantity) {
    const s = /** @type {Record<string, HTMLElement>} */ (rowSlots.get(row));
    const prev = rendered.get(row);
    const maxChanged = lastMax !== maxQuantity;

    if (prev !== line) {
      setText(s.name, line.name);

      const image = /** @type {HTMLImageElement} */ (s.image);
      const src = line.imageUrl ? thumbnailUrl(line.imageUrl, 192) : '';
      if (image.getAttribute('src') !== src) {
        s.media.dataset.state = src ? 'loading' : 'fallback';
        if (src) image.setAttribute('src', src); else image.removeAttribute('src');
      }
      if (prev?.tile !== line.tile) applyTile(s.media, s.tile, line.tile);

      if (line.rarity) {
        setText(s.rarity, line.rarity.label);
        s.rarity.style.setProperty('--cart-pill-color', line.rarity.color ?? 'rgba(255,255,255,0.35)');
        setHidden(s.rarity, false);
      } else {
        setHidden(s.rarity, true);
      }
      if (line.type) { setText(s.type, line.type); setHidden(s.type, false); } else setHidden(s.type, true);

      setText(s.price, formatMoney(line.unitPrice * line.quantity));
      setAttr(s.price, 'data-discount', line.hasOffer ? 'true' : null);
      if (line.hasOffer) {
        setText(s['price-base'], formatMoney(line.unitPriceBase * line.quantity));
        setHidden(s['price-base'], false);
      } else {
        setHidden(s['price-base'], true);
      }

      setText(s.quantity, String(line.quantity));
      const dec = /** @type {HTMLButtonElement} */ (row.querySelector('[data-cart-action="decrement"]'));
      const remove = line.quantity <= 1;
      setAttr(dec, 'data-intent', remove ? 'remove' : 'decrement');
      setAttr(dec, 'aria-label', remove ? `Quitar ${line.name} del carrito` : `Reducir cantidad de ${line.name}`);
      const inc = /** @type {HTMLButtonElement} */ (row.querySelector('[data-cart-action="increment"]'));
      setAttr(inc, 'aria-label', `Aumentar cantidad de ${line.name}`);
      setAttr(s.media, 'aria-label', `Ver ${line.name}`);
      rendered.set(row, line);
    }

    if (prev !== line || maxChanged) {
      const inc = /** @type {HTMLButtonElement} */ (row.querySelector('[data-cart-action="increment"]'));
      setAttr(inc, 'aria-disabled', line.quantity >= maxQuantity ? 'true' : null);
    }

    setAttr(row, 'aria-busy', pending ? 'true' : null);
  }

  /** @param {HTMLLIElement} row */
  function moveFocusFrom(row) {
    if (!row.contains(doc.activeElement)) return;
    const sibling = /** @type {HTMLLIElement|null} */ (row.nextElementSibling) ?? /** @type {HTMLLIElement|null} */ (row.previousElementSibling);
    const target = sibling && !leaving.has(sibling.dataset.offerId ?? '')
      ? /** @type {HTMLElement|null} */ (sibling.querySelector('[data-cart-action="decrement"]'))
      : title;
    (target ?? title).focus({ preventScroll: true });
  }

  /** @param {string} offerId @param {HTMLLIElement} row */
  function leave(offerId, row) {
    rows.delete(offerId);
    leaving.set(offerId, row);
    moveFocusFrom(row);
    row.dataset.state = 'leaving';
    afterAnimations(row, lifetime.signal, () => {
      if (leaving.get(offerId) === row) {
        leaving.delete(offerId);
        row.remove();
      }
    });
  }

  /** @param {HTMLLIElement} row */
  function enter(row) {
    row.dataset.state = 'entering';
    afterAnimations(row, lifetime.signal, () => {
      if (row.dataset.state === 'entering') row.dataset.state = 'idle';
    });
  }

  /** @param {Element|null} node */
  function nextLive(node) {
    while (node && /** @type {HTMLElement} */ (node).dataset.state === 'leaving') node = node.nextElementSibling;
    return node;
  }

  /** @param {import('./store.js').CartState} state */
  function renderLines(state) {
    let cursor = nextLive(list.firstElementChild);
    const seen = new Set();

    for (const line of state.lines) {
      seen.add(line.offerId);
      let row = rows.get(line.offerId);
      let fresh = false;
      if (!row) {
        const recycled = leaving.get(line.offerId);
        if (recycled) {
          leaving.delete(line.offerId);
          row = recycled;
          row.dataset.state = 'idle';
        } else {
          row = createRow(line);
          fresh = true;
        }
        rows.set(line.offerId, row);
      }
      patchRow(row, line, selectIsPending(state, line.offerId), state.maxQuantity);

      if (row === cursor) {
        cursor = nextLive(cursor.nextElementSibling);
      } else {
        list.insertBefore(row, cursor);
      }
      if (fresh) enter(row);
    }

    for (const [offerId, row] of rows) {
      if (!seen.has(offerId)) leave(offerId, row);
    }
  }

  /* ───────────── aviso ───────────── */

  /** @param {string} message */
  function showNotice(message) {
    clearTimeout(noticeTimer);
    setText(slots.notice, message);
    slots.notice.dataset.visible = 'true';
    noticeTimer = setTimeout(() => {
      delete slots.notice.dataset.visible;
      setText(slots.notice, '');
    }, NOTICE_MS);
  }

  /* ───────────── render ───────────── */

  /** @param {import('./store.js').CartState} state */
  function render(state) {
    const totals = selectTotals(state);
    const isEmpty = state.lines.length === 0;
    const showLoading = state.status === 'loading' && isEmpty;
    const showError = state.status === 'error' && isEmpty;
    const showEmpty = isEmpty && !showLoading && !showError;

    setAttr(dialog, 'data-status', state.status);
    setAttr(dialog, 'data-empty', isEmpty ? 'true' : 'false');
    setHidden(slots.loading, !showLoading);
    setHidden(slots.error, !showError);
    setHidden(slots.empty, !showEmpty);
    setHidden(list, isEmpty);
    if (showError && state.lastError) setText(slots['error-text'], state.lastError.message);

    setText(slots.count, formatCount(totals.count));
    setText(slots.subtotal, formatMoney(totals.subtotal));
    setText(slots.savings, formatMoney(totals.savings));
    setHidden(slots['savings-row'], totals.savings <= 0);

    const checkout = /** @type {HTMLAnchorElement} */ (slots.checkout);
    const canCheckout = !isEmpty && state.status === 'ready';
    setAttr(checkout, 'aria-disabled', canCheckout ? null : 'true');
    setAttr(checkout, 'tabindex', canCheckout ? null : '-1');

    renderLines(state);
    lastMax = state.maxQuantity;

    if (state.lastError !== shownError) {
      shownError = state.lastError;
      if (shownError && !showError && phase === Phase.OPEN) showNotice(shownError.message);
    }
  }

  function scheduleRender() {
    if (frame) return;
    frame = requestAnimationFrame(() => {
      frame = 0;
      render(lastState);
    });
  }

  const unsubscribe = store.subscribe((state) => {
    lastState = state;
    scheduleRender();
  });

  /* ───────────── máquina de estados: apertura / cierre ───────────── */

  /**
   * CLOSED → OPEN. Presenta el diálogo según el breakpoint y registra los
   * oyentes de esa presentación bajo un AbortController propio. El panel se
   * muestra aún en reposo (fuera de pantalla), se fija ese estilo y sólo
   * entonces se marca `open`, para que la entrada sea una transición desde el
   * borde y no una aparición seca.
   * @param {Element|null} trigger
   */
  function enterOpen(trigger) {
    phase = Phase.OPEN;
    const returnFocusTo = trigger ?? (doc.activeElement instanceof HTMLElement ? doc.activeElement : null);
    store.dispatch(actions.drawerOpened(returnFocusTo));
    const { status } = store.getState();
    if (status === 'idle' || status === 'error') void ops.hydrate();

    presentation = new AbortController();
    const { signal } = presentation;
    if (panelMedia.matches) {
      dialog.show();
      doc.addEventListener('keydown', onPanelKeydown, { signal });
      doc.addEventListener('pointerdown', onPanelPointerDown, { capture: true, signal });
    } else {
      dialog.showModal();
      if (backdrop) {
        backdrop.acquire(BACKDROP_ID, dialog, close, { lockScroll: true, inertMain: false });
        signal.addEventListener('abort', () => backdrop.release(BACKDROP_ID), { once: true });
      }
    }
    flushStyle(panel);
    dialog.dataset.state = 'open';
    render(lastState);
    title.focus({ preventScroll: true });
    doc.dispatchEvent(new CustomEvent('banano:cart-open'));
  }

  /**
   * CLOSING → OPEN. Revierte el movimiento de salida desde su punto actual:
   * la presentación sigue montada, así que basta con cancelar la espera de
   * cierre y devolver el destino al estado abierto.
   */
  function revertClosing() {
    exit?.abort();
    exit = null;
    phase = Phase.OPEN;
    dialog.dataset.state = 'open';
  }

  /**
   * OPEN → CLOSING. Cambia el destino del panel (la transición arranca desde
   * donde esté, aunque la entrada no haya terminado) y espera a que asiente
   * para cerrar el diálogo.
   */
  function enterClosing() {
    phase = Phase.CLOSING;
    dialog.dataset.state = 'closing';
    const controller = new AbortController();
    exit = controller;
    afterAnimations(panel, controller.signal, () => {
      exit = null;
      finishClose();
    });
  }

  /** CLOSING|OPEN → CLOSED. Desmonta la presentación de forma síncrona y devuelve el foco. */
  function finishClose() {
    phase = Phase.CLOSED;
    dialog.dataset.state = 'idle';
    presentation?.abort();
    presentation = null;
    if (dialog.open) dialog.close();

    const { returnFocusTo } = store.getState().drawer;
    store.dispatch(actions.drawerClosed());
    if (returnFocusTo instanceof HTMLElement && returnFocusTo.isConnected) {
      returnFocusTo.focus({ preventScroll: true });
    }
    doc.dispatchEvent(new CustomEvent('banano:cart-close'));
  }

  /** @param {Element|null} trigger */
  function open(trigger = null) {
    switch (phase) {
      case Phase.CLOSED: enterOpen(trigger); break;
      case Phase.CLOSING: revertClosing(); break;
      default: break;
    }
  }

  /** @param {Element|null} trigger */
  function toggle(trigger = null) {
    switch (phase) {
      case Phase.CLOSED: enterOpen(trigger); break;
      case Phase.OPEN: enterClosing(); break;
      case Phase.CLOSING: revertClosing(); break;
      default: break;
    }
  }

  function close() {
    if (phase === Phase.OPEN) enterClosing();
  }

  /**
   * `close` nativo. Sólo actúa ante un cierre externo (el navegador cerró el
   * diálogo sin pasar por la máquina): si la fase ya es CLOSED o el diálogo
   * volvió a abrirse, el evento es el eco de un cierre ya conciliado.
   */
  function onClosed() {
    if (phase === Phase.CLOSED || dialog.open) return;
    exit?.abort();
    exit = null;
    finishClose();
  }

  /* ───────────── eventos ───────────── */

  /** Sustituto de `cancel` para el panel no modal. @param {KeyboardEvent} event */
  function onPanelKeydown(event) {
    if (event.key !== 'Escape' || event.defaultPrevented) return;
    event.preventDefault();
    close();
  }

  /** @param {PointerEvent} event */
  function onPanelPointerDown(event) {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (dialog.contains(target) || target.closest(OUTSIDE_EXEMPT_SELECTOR)) return;
    close();
  }

  function onPanelMediaChange() {
    if (phase === Phase.OPEN) enterClosing();
  }

  /** @param {Event} event */
  function onSubmit(event) {
    event.preventDefault();
    close();
  }

  /** @param {Event} event */
  function onCancel(event) {
    event.preventDefault();
    close();
  }

  /** @param {MouseEvent} event */
  function onClick(event) {
    if (event.target === dialog) { close(); return; }
    const control = /** @type {HTMLElement|null} */ (/** @type {Element} */ (event.target).closest('[data-cart-action]'));
    if (!control || control.getAttribute('aria-disabled') === 'true') return;
    const row = /** @type {HTMLElement|null} */ (control.closest('.cart-line'));
    const offerId = row?.dataset.offerId ?? null;

    switch (control.dataset.cartAction) {
      case 'increment':
        if (offerId) ops.increment(offerId);
        break;
      case 'decrement':
        if (offerId) ops.decrement(offerId);
        break;
      case 'retry':
        void ops.hydrate();
        break;
      case 'view': {
        if (!offerId) break;
        const line = rendered.get(/** @type {HTMLLIElement} */ (row));
        const view = new CustomEvent('banano:cart-view', {
          cancelable: true,
          detail: { offerId, isStatic: line?.isStatic === true },
        });
        // Quien resuelva la navegación cancela el evento; sólo entonces se cierra.
        if (!doc.dispatchEvent(view)) close();
        break;
      }
      default:
        break;
    }
  }

  /** @param {Event} event */
  function onMediaEvent(event) {
    const image = event.target;
    if (!(image instanceof HTMLImageElement)) return;
    const media = /** @type {HTMLElement|null} */ (image.closest('[data-cart-slot="media"]'));
    if (media) media.dataset.state = event.type === 'error' ? 'fallback' : 'ready';
  }

  const { signal } = lifetime;
  dialog.addEventListener('submit', onSubmit, { signal });
  dialog.addEventListener('cancel', onCancel, { signal });
  dialog.addEventListener('close', onClosed, { signal });
  dialog.addEventListener('click', onClick, { signal });
  list.addEventListener('error', onMediaEvent, { capture: true, signal });
  list.addEventListener('load', onMediaEvent, { capture: true, signal });
  panelMedia.addEventListener('change', onPanelMediaChange, { signal });

  render(lastState);

  return Object.freeze({
    open,
    toggle,
    close,
    isOpen: () => phase !== Phase.CLOSED,
    isPanel: () => panelMedia.matches,
    dispose() {
      unsubscribe();
      cancelAnimationFrame(frame);
      clearTimeout(noticeTimer);
      lifetime.abort();
      exit?.abort();
      exit = null;
      presentation?.abort();
      presentation = null;
      phase = Phase.CLOSED;
      dialog.dataset.state = 'idle';
      if (dialog.open) dialog.close();
    },
  });
}

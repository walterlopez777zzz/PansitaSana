/**
 * Orquestación store ⇄ api: aplica cada intención de forma optimista y la
 * reconcilia con el snapshot del servidor sin carreras ni respuestas cruzadas.
 *
 * Modelo de concurrencia
 *  - Un `opId` monótono por intención. El store sólo acepta la respuesta de la
 *    intención más reciente de cada línea; aquí además se descarta antes de
 *    llegar a él (una respuesta superada nunca pisa una intención posterior).
 *  - Un canal por línea con UNA petición en vuelo como máximo. Las intenciones
 *    que llegan mientras tanto se coalescen en la cantidad ABSOLUTA deseada; al
 *    volver la respuesta, el canal envía (si hace falta) una única petición con
 *    esa cantidad final. Sin temporizadores: la propia latencia de red es la
 *    ventana de coalescencia.
 *  - Las mutaciones nunca se abortan: cancelar un POST no deshace su efecto en
 *    el servidor, sólo pierde la información de si ocurrió. El canal decide en
 *    el momento del envío entre alta (`add`) y ajuste (`setQuantity`) según lo
 *    que sabe que existe en el servidor, así una ráfaga «añadir → +1» nunca
 *    ajusta una línea que el servidor aún no tiene.
 *  - Los snapshots se aplican en orden de emisión (secuenciador): una lectura
 *    o mutación rezagada no puede retroceder el estado ya confirmado por una
 *    respuesta posterior.
 *  - `buyNow` es de vuelo único, espera a que los canales asienten y congela
 *    nuevas mutaciones mientras dura: vacía el carrito en el servidor y una
 *    petición rezagada lo repoblaría.
 *  - `dispose` aborta todo lo que siga en vuelo mediante un único AbortSignal.
 */

import { actions, selectLine } from './store.js';
import { ErrorCode, isAbortError, toCartApiError } from './api.js';
import { productToLine, toCartSnapshot } from './format.js';

export const CHECKOUT_URL = '/checkout/';

/** Resultado sincrónico de `add` (la confirmación del servidor llega por el store). */
export const AddOutcome = Object.freeze({
  ADDED: 'added',
  INCREMENTED: 'incremented',
  MAX: 'max',
});

/**
 * @typedef {Object} CartProduct
 * @property {string} offerId
 * @property {boolean} [isStatic]
 * @property {string} [name]
 * @property {number} [price]
 * @property {number} [priceBase]
 * @property {string|null} [image]
 * @property {string|null} [type]
 * @property {Record<string, string>|null} [colors]  `entry.colors` crudos de la API (rrggbbaa).
 * @property {{item_type?: string|null, item_id?: string|null, is_car_offer?: boolean, is_dynamic_bundle?: boolean}|null} [card]
 *   Encuadre de la tarjeta original, con las mismas claves que publica cart_manager.php.
 */

/**
 * @typedef {Object} CartOperations
 * @property {() => Promise<void>} hydrate
 * @property {(product: CartProduct) => string} add  Devuelve un `AddOutcome`.
 * @property {(offerId: string, quantity: number) => void} setQuantity
 * @property {(offerId: string) => void} increment
 * @property {(offerId: string) => void} decrement
 * @property {(offerId: string) => void} remove
 * @property {(product: CartProduct) => Promise<void>} buyNow
 * @property {() => Promise<void>} settle  Se resuelve cuando no queda ninguna mutación en vuelo.
 * @property {() => void} dispose
 */

/**
 * Canal de una línea.
 * @typedef {Object} Channel
 * @property {number} opId          Intención más reciente (la única que el store aceptará).
 * @property {number} desired       Cantidad absoluta objetivo de esa intención (0 = eliminar).
 * @property {boolean} serverHas    Última certeza sobre si la línea existe en el servidor.
 * @property {Promise<void>} flight Bomba en curso; se resuelve al vaciarse el canal.
 */

/**
 * Orden de aplicación de snapshots: sólo se adopta uno emitido después del
 * último adoptado. La respuesta rezagada conserva su semántica (confirmar o
 * rechazar la operación) pero no aporta datos.
 */
function createSequencer() {
  let issued = 0;
  let applied = 0;
  return Object.freeze({
    issue: () => ++issued,
    /** @param {number} ticket */
    accept(ticket) {
      if (ticket <= applied) return false;
      applied = ticket;
      return true;
    },
    /** Marca todo lo emitido hasta ahora como superado (snapshot autoritativo). */
    supersede() { applied = issued; },
  });
}

/**
 * @param {ReturnType<typeof toCartSnapshot>} snapshot
 * @param {string} offerId
 */
function quantityIn(snapshot, offerId) {
  for (const line of snapshot.lines) if (line.offerId === offerId) return line.quantity;
  return 0;
}

/**
 * @param {Object} deps
 * @param {import('./store.js').CartStore} deps.store
 * @param {import('./api.js').CartApi} deps.api
 * @param {(url: string) => void} [deps.navigate]
 * @returns {CartOperations}
 */
export function createCartOperations({ store, api, navigate }) {
  let nextOpId = 0;
  const lifetime = new AbortController();
  const { signal } = lifetime;
  const sequence = createSequencer();
  /** @type {Map<string, Channel>} */
  const channels = new Map();
  /** @type {AbortController|null} */
  let hydration = null;
  /** @type {Promise<void>|null} */
  let checkout = null;

  const go = navigate ?? ((url) => { window.location.assign(url); });

  /* ───────────── canales ───────────── */

  /**
   * @param {string} offerId
   * @param {Channel} channel
   */
  function send(offerId, channel) {
    const quantity = channel.desired;
    if (quantity === 0) return api.remove({ offerId, signal });
    if (channel.serverHas) return api.setQuantity({ offerId, quantity, signal });
    return api.add({ offerId, quantity, signal });
  }

  /**
   * Bomba del canal: envía la intención vigente, reconcilia y repite mientras
   * haya una intención más nueva que el servidor aún no refleje. Termina
   * siempre en un número finito de vueltas (una por intención superada).
   *
   * @param {string} offerId
   * @param {Channel} channel
   */
  async function pump(offerId, channel) {
    for (;;) {
      const { opId } = channel;
      const ticket = sequence.issue();
      try {
        const snapshot = toCartSnapshot(await send(offerId, channel));
        if (signal.aborted) return;
        const remote = quantityIn(snapshot, offerId);
        channel.serverHas = remote > 0;
        if (channel.opId !== opId && remote !== channel.desired) continue;
        store.dispatch(actions.opConfirmed(channel.opId, sequence.accept(ticket) ? snapshot : null));
        return;
      } catch (error) {
        if (signal.aborted) return;
        const apiError = toCartApiError(error);
        if (apiError.code === ErrorCode.NOT_FOUND) channel.serverHas = false;
        if (channel.opId !== opId) continue;
        store.dispatch(actions.opRejected(opId, apiError.toState()));
        return;
      }
    }
  }

  /**
   * Registra una intención ya aplicada al store y garantiza que un canal la
   * lleve al servidor.
   * @param {string} offerId
   * @param {number} opId
   * @param {number} desired
   */
  function enqueue(offerId, opId, desired) {
    const existing = channels.get(offerId);
    if (existing) {
      existing.opId = opId;
      existing.desired = desired;
      return;
    }
    const pending = store.getState().pending[offerId];
    /** @type {Channel} */
    const channel = {
      opId,
      desired,
      serverHas: pending !== undefined && pending.base !== null,
      flight: Promise.resolve(),
    };
    channels.set(offerId, channel);
    channel.flight = pump(offerId, channel).finally(() => {
      if (channels.get(offerId) === channel) channels.delete(offerId);
    });
  }

  /**
   * Aplica una acción optimista; sólo si cambió el estado se encola su envío.
   * @param {string} offerId
   * @param {number} desired
   * @param {(opId: number) => {type: string}} action
   */
  function intend(offerId, desired, action) {
    if (signal.aborted || checkout !== null) return;
    const before = store.getState();
    const opId = ++nextOpId;
    store.dispatch(action(opId));
    if (store.getState() !== before) enqueue(offerId, opId, desired);
  }

  /**
   * @param {string} offerId
   * @param {number} quantity  Cantidad absoluta objetivo (0 = eliminar).
   */
  function applyQuantity(offerId, quantity) {
    const state = store.getState();
    const target = Math.max(0, Math.min(Math.trunc(quantity), state.maxQuantity));
    const line = selectLine(state, offerId);
    if (line === null || line.quantity === target) return;
    intend(offerId, target, target === 0
      ? (opId) => actions.lineRemoved(offerId, opId)
      : (opId) => actions.quantitySet(offerId, target, opId));
  }

  async function settle() {
    while (channels.size > 0) {
      await Promise.allSettled(Array.from(channels.values(), (channel) => channel.flight));
    }
  }

  /* ───────────── compra directa ───────────── */

  /** @param {CartProduct} product */
  async function runCheckout(product) {
    const offerId = String(product.offerId);
    await settle();
    if (signal.aborted) return;
    const snapshot = toCartSnapshot(await api.buyNow({ offerId, quantity: 1, signal }));
    if (signal.aborted) return;
    hydration?.abort();
    sequence.supersede();
    store.dispatch(actions.replaced(snapshot));
    go(CHECKOUT_URL);
  }

  return Object.freeze({
    async hydrate() {
      if (signal.aborted) return;
      hydration?.abort();
      const controller = new AbortController();
      hydration = controller;
      const ticket = sequence.issue();
      store.dispatch(actions.hydrateStarted());
      try {
        const snapshot = toCartSnapshot(await api.get({ signal: controller.signal }));
        if (controller.signal.aborted) return;
        if (sequence.accept(ticket)) store.dispatch(actions.hydrated(snapshot));
      } catch (error) {
        if (controller.signal.aborted || isAbortError(error)) return;
        store.dispatch(actions.hydrateFailed(toCartApiError(error).toState()));
      } finally {
        if (hydration === controller) hydration = null;
      }
    },

    add(product) {
      const offerId = String(product.offerId);
      const state = store.getState();
      const existing = selectLine(state, offerId);
      if (existing !== null) {
        if (existing.quantity >= state.maxQuantity) return AddOutcome.MAX;
        applyQuantity(offerId, existing.quantity + 1);
        return AddOutcome.INCREMENTED;
      }
      intend(offerId, 1, (opId) => actions.lineAdded(productToLine(product), opId));
      return AddOutcome.ADDED;
    },

    setQuantity: applyQuantity,

    increment(offerId) {
      const line = selectLine(store.getState(), offerId);
      if (line !== null) applyQuantity(offerId, line.quantity + 1);
    },

    decrement(offerId) {
      const line = selectLine(store.getState(), offerId);
      if (line !== null) applyQuantity(offerId, line.quantity - 1);
    },

    remove(offerId) {
      applyQuantity(offerId, 0);
    },

    buyNow(product) {
      checkout ??= runCheckout(product).finally(() => { checkout = null; });
      return checkout;
    },

    settle,

    dispose() {
      lifetime.abort();
      hydration?.abort();
      hydration = null;
      channels.clear();
    },
  });
}

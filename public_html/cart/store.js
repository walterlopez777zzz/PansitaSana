/**
 * Estado del carrito: inmutable, reducido por acciones puras y observable.
 *
 * Contrato:
 *  - Toda estructura publicada está congelada (Object.freeze) en profundidad.
 *  - El reductor devuelve la MISMA instancia cuando la acción no cambia nada,
 *    lo que permite a los suscriptores comparar por identidad.
 *  - `revision` sube de forma monótona cada vez que cambian las líneas.
 *  - Las mutaciones optimistas quedan registradas en `pending[offerId]` con el
 *    último `opId` de la línea y la copia confirmada (`base`) para revertir
 *    sólo esa línea si el servidor rechaza la operación.
 *
 * @typedef {Object} CartRarity
 * @property {string} id
 * @property {string} label
 * @property {string|null} color  Hex #rrggbb ya validado o null.
 *
 * @typedef {Object} CartTile  Presentación 1×1 de la tarjeta original (anatomía `.item-tile`).
 * @property {string|null} itemType     Tipo plural que selecciona el encuadre (`data-item-type`).
 * @property {string|null} itemId       Cosmético principal, excepciones de encuadre (`data-item-id`).
 * @property {boolean} isCarOffer       Oferta de vehículo (`data-car-offer`).
 * @property {boolean} isDynamicBundle  Multi-cosmético sin lote propio (`data-dynamic-bundle`).
 * @property {string|null} background   Valor CSS `background` de la caja o null (fondo neutro).
 *
 * @typedef {Object} CartLine
 * @property {string} offerId
 * @property {string} name
 * @property {string|null} imageUrl
 * @property {number} unitPrice
 * @property {number} unitPriceBase
 * @property {boolean} hasOffer
 * @property {number} quantity
 * @property {CartRarity|null} rarity
 * @property {string|null} type
 * @property {CartTile} tile
 * @property {boolean} isStatic
 * @property {number|null} expiresAt  Epoch ms o null.
 *
 * @typedef {'idle'|'loading'|'ready'|'error'} CartStatus
 *
 * @typedef {Object} CartError
 * @property {string} code
 * @property {string} message
 * @property {string} [offerId]  Línea afectada cuando el error nace de una operación.
 *
 * @typedef {Object} PendingOp
 * @property {number} opId
 * @property {CartLine|null} base   Línea confirmada antes de la primera mutación optimista.
 * @property {number} baseIndex     Posición original (para reinsertar al revertir).
 *
 * @typedef {Object} CartState
 * @property {CartStatus} status
 * @property {ReadonlyArray<CartLine>} lines
 * @property {number} revision
 * @property {Readonly<Record<string, PendingOp>>} pending
 * @property {Readonly<{open: boolean, returnFocusTo: Element|null}>} drawer
 * @property {CartError|null} lastError
 * @property {number} maxQuantity  Tope por línea informado por el servidor.
 */

export const DEFAULT_MAX_QUANTITY = 3;

/** @type {CartState} */
export const INITIAL_STATE = Object.freeze({
  status: 'idle',
  lines: Object.freeze([]),
  revision: 0,
  pending: Object.freeze({}),
  drawer: Object.freeze({ open: false, returnFocusTo: null }),
  lastError: null,
  maxQuantity: DEFAULT_MAX_QUANTITY,
});

export const ActionType = Object.freeze({
  HYDRATE_STARTED: 'hydrate/started',
  HYDRATED: 'hydrate/done',
  HYDRATE_FAILED: 'hydrate/failed',
  REPLACED: 'snapshot/replaced',
  LINE_ADDED: 'line/added',
  QUANTITY_SET: 'line/quantity',
  LINE_REMOVED: 'line/removed',
  OP_CONFIRMED: 'op/confirmed',
  OP_REJECTED: 'op/rejected',
  DRAWER_OPENED: 'drawer/opened',
  DRAWER_CLOSED: 'drawer/closed',
});

/* ─────────────────────────── acciones ─────────────────────────── */

export const actions = Object.freeze({
  hydrateStarted: () => ({ type: ActionType.HYDRATE_STARTED }),
  /** @param {{lines: CartLine[], maxQuantity?: number}} snapshot */
  hydrated: (snapshot) => ({ type: ActionType.HYDRATED, snapshot }),
  /** @param {CartError} error */
  hydrateFailed: (error) => ({ type: ActionType.HYDRATE_FAILED, error }),
  /** Sustitución autoritativa (descarta cualquier operación pendiente). @param {{lines: CartLine[], maxQuantity?: number}} snapshot */
  replaced: (snapshot) => ({ type: ActionType.REPLACED, snapshot }),
  /** @param {CartLine} line @param {number} opId */
  lineAdded: (line, opId) => ({ type: ActionType.LINE_ADDED, line, opId }),
  /** @param {string} offerId @param {number} quantity @param {number} opId */
  quantitySet: (offerId, quantity, opId) => ({ type: ActionType.QUANTITY_SET, offerId, quantity, opId }),
  /** @param {string} offerId @param {number} opId */
  lineRemoved: (offerId, opId) => ({ type: ActionType.LINE_REMOVED, offerId, opId }),
  /** @param {number} opId @param {{lines: CartLine[], maxQuantity?: number}|null} [snapshot] */
  opConfirmed: (opId, snapshot = null) => ({ type: ActionType.OP_CONFIRMED, opId, snapshot }),
  /** @param {number} opId @param {CartError} error */
  opRejected: (opId, error) => ({ type: ActionType.OP_REJECTED, opId, error }),
  /** @param {Element|null} returnFocusTo */
  drawerOpened: (returnFocusTo = null) => ({ type: ActionType.DRAWER_OPENED, returnFocusTo }),
  drawerClosed: () => ({ type: ActionType.DRAWER_CLOSED }),
});

/* ─────────────────────────── utilidades ───────────────────────── */

/**
 * @param {CartLine} line
 * @returns {CartLine}
 */
export function freezeLine(line) {
  if (line.rarity && !Object.isFrozen(line.rarity)) Object.freeze(line.rarity);
  if (line.tile && !Object.isFrozen(line.tile)) Object.freeze(line.tile);
  return Object.isFrozen(line) ? line : Object.freeze(line);
}

/** @param {CartLine[]} lines @returns {ReadonlyArray<CartLine>} */
function freezeLines(lines) {
  for (const line of lines) freezeLine(line);
  return Object.freeze(lines);
}

/**
 * @param {CartState} state
 * @param {Partial<CartState>} patch
 * @returns {CartState}
 */
function next(state, patch) {
  return Object.freeze({ ...state, ...patch });
}

/**
 * @param {CartState} state
 * @param {CartLine[]} lines
 * @param {Partial<CartState>} [patch]
 * @returns {CartState}
 */
function withLines(state, lines, patch = {}) {
  return next(state, { ...patch, lines: freezeLines(lines), revision: state.revision + 1 });
}

/**
 * @param {ReadonlyArray<CartLine>} lines
 * @param {string} offerId
 */
function indexOfLine(lines, offerId) {
  for (let i = 0; i < lines.length; i += 1) {
    if (lines[i].offerId === offerId) return i;
  }
  return -1;
}

/**
 * Registra (o renueva) la operación pendiente de una línea conservando la
 * copia confirmada de la primera mutación de la ráfaga.
 *
 * @param {CartState} state
 * @param {string} offerId
 * @param {number} opId
 * @param {CartLine|null} currentLine
 * @param {number} currentIndex
 */
function trackPending(state, offerId, opId, currentLine, currentIndex) {
  const previous = state.pending[offerId];
  const entry = previous
    ? Object.freeze({ ...previous, opId })
    : Object.freeze({ opId, base: currentLine, baseIndex: currentIndex });
  return Object.freeze({ ...state.pending, [offerId]: entry });
}

/** @param {Readonly<Record<string, PendingOp>>} pending @param {number} opId */
function findPendingByOp(pending, opId) {
  for (const offerId of Object.keys(pending)) {
    if (pending[offerId].opId === opId) return offerId;
  }
  return null;
}

/**
 * @param {Readonly<Record<string, PendingOp>>} pending
 * @param {string} offerId
 */
function withoutPending(pending, offerId) {
  if (!(offerId in pending)) return pending;
  const copy = { ...pending };
  delete copy[offerId];
  return Object.freeze(copy);
}

/**
 * Fusiona un snapshot del servidor con el estado local: el servidor es la
 * fuente de verdad salvo para líneas con una operación aún en vuelo, donde se
 * conserva el valor optimista local (o su ausencia).
 *
 * @param {CartState} state
 * @param {CartLine[]} remote  Copia mutable de las líneas remotas.
 * @param {Readonly<Record<string, PendingOp>>} pending  Operaciones que siguen en vuelo.
 * @returns {CartLine[]}
 */
function mergeSnapshot(state, remote, pending) {
  for (const offerId of Object.keys(pending)) {
    const localIndex = indexOfLine(state.lines, offerId);
    const remoteIndex = indexOfLine(remote, offerId);
    if (localIndex === -1) {
      if (remoteIndex !== -1) remote.splice(remoteIndex, 1);
    } else if (remoteIndex === -1) {
      remote.splice(Math.min(localIndex, remote.length), 0, state.lines[localIndex]);
    } else {
      remote[remoteIndex] = Object.freeze({ ...remote[remoteIndex], quantity: state.lines[localIndex].quantity });
    }
  }
  return remote;
}

/**
 * @param {number} value
 * @param {number} fallback
 */
function positiveInt(value, fallback) {
  const n = Number(value);
  return Number.isInteger(n) && n > 0 ? n : fallback;
}

/* ─────────────────────────── reductor ─────────────────────────── */

/**
 * @param {CartState} state
 * @param {{type: string, [key: string]: unknown}} action
 * @returns {CartState}
 */
export function reduce(state, action) {
  switch (action.type) {
    case ActionType.HYDRATE_STARTED: {
      if (state.status !== 'idle' && state.status !== 'error') return state;
      return next(state, { status: 'loading', lastError: null });
    }

    case ActionType.HYDRATED: {
      const { lines, maxQuantity } = /** @type {{lines: CartLine[], maxQuantity?: number}} */ (action.snapshot);
      return withLines(state, mergeSnapshot(state, Array.from(lines), state.pending), {
        status: 'ready',
        lastError: null,
        maxQuantity: positiveInt(maxQuantity, state.maxQuantity),
      });
    }

    case ActionType.HYDRATE_FAILED: {
      return next(state, { status: 'error', lastError: /** @type {CartError} */ (action.error) });
    }

    case ActionType.REPLACED: {
      const { lines, maxQuantity } = /** @type {{lines: CartLine[], maxQuantity?: number}} */ (action.snapshot);
      return withLines(state, Array.from(lines), {
        status: 'ready',
        lastError: null,
        pending: INITIAL_STATE.pending,
        maxQuantity: positiveInt(maxQuantity, state.maxQuantity),
      });
    }

    case ActionType.LINE_ADDED: {
      const line = freezeLine(/** @type {CartLine} */ (action.line));
      const opId = /** @type {number} */ (action.opId);
      const index = indexOfLine(state.lines, line.offerId);
      const lines = Array.from(state.lines);

      if (index === -1) {
        lines.push(line);
      } else {
        const current = lines[index];
        const quantity = Math.min(current.quantity + line.quantity, state.maxQuantity);
        if (quantity === current.quantity) return state;
        lines[index] = Object.freeze({ ...current, quantity });
      }

      return withLines(state, lines, {
        pending: trackPending(state, line.offerId, opId, index === -1 ? null : state.lines[index], index),
        lastError: null,
      });
    }

    case ActionType.QUANTITY_SET: {
      const offerId = /** @type {string} */ (action.offerId);
      const opId = /** @type {number} */ (action.opId);
      const quantity = Math.max(0, Math.min(Number(action.quantity) || 0, state.maxQuantity));
      const index = indexOfLine(state.lines, offerId);
      if (index === -1) return state;

      const current = state.lines[index];
      if (current.quantity === quantity) return state;

      const lines = Array.from(state.lines);
      if (quantity === 0) lines.splice(index, 1);
      else lines[index] = Object.freeze({ ...current, quantity });

      return withLines(state, lines, {
        pending: trackPending(state, offerId, opId, current, index),
        lastError: null,
      });
    }

    case ActionType.LINE_REMOVED: {
      const offerId = /** @type {string} */ (action.offerId);
      const opId = /** @type {number} */ (action.opId);
      const index = indexOfLine(state.lines, offerId);
      if (index === -1) return state;

      const lines = Array.from(state.lines);
      lines.splice(index, 1);

      return withLines(state, lines, {
        pending: trackPending(state, offerId, opId, state.lines[index], index),
        lastError: null,
      });
    }

    case ActionType.OP_CONFIRMED: {
      const opId = /** @type {number} */ (action.opId);
      const offerId = findPendingByOp(state.pending, opId);
      if (offerId === null) return state; // respuesta obsoleta o desconocida

      const pending = withoutPending(state.pending, offerId);
      const snapshot = /** @type {{lines: CartLine[], maxQuantity?: number}|null} */ (action.snapshot);
      if (!snapshot) return next(state, { pending, status: 'ready' });

      return withLines(state, mergeSnapshot(state, Array.from(snapshot.lines), pending), {
        pending,
        status: 'ready',
        maxQuantity: positiveInt(snapshot.maxQuantity, state.maxQuantity),
      });
    }

    case ActionType.OP_REJECTED: {
      const opId = /** @type {number} */ (action.opId);
      const error = /** @type {CartError} */ (action.error);
      const offerId = findPendingByOp(state.pending, opId);
      if (offerId === null) return state; // respuesta obsoleta o desconocida

      const { base, baseIndex } = state.pending[offerId];
      const lines = Array.from(state.lines);
      const index = indexOfLine(lines, offerId);

      if (base === null) {
        if (index !== -1) lines.splice(index, 1);
      } else if (index === -1) {
        lines.splice(Math.min(baseIndex, lines.length), 0, base);
      } else {
        lines[index] = base;
      }

      return withLines(state, lines, {
        pending: withoutPending(state.pending, offerId),
        lastError: Object.freeze({ ...error, offerId }),
      });
    }

    case ActionType.DRAWER_OPENED: {
      const returnFocusTo = /** @type {Element|null} */ (action.returnFocusTo);
      if (state.drawer.open && state.drawer.returnFocusTo === returnFocusTo) return state;
      return next(state, { drawer: Object.freeze({ open: true, returnFocusTo }) });
    }

    case ActionType.DRAWER_CLOSED: {
      if (!state.drawer.open) return state;
      return next(state, { drawer: INITIAL_STATE.drawer });
    }

    default:
      return state;
  }
}

/* ─────────────────────────── selectores ───────────────────────── */

/**
 * @typedef {Object} CartTotals
 * @property {number} count      Unidades totales.
 * @property {number} lineCount  Líneas distintas.
 * @property {number} subtotal
 * @property {number} savings    Diferencia acumulada base − final.
 */

/** @type {WeakMap<ReadonlyArray<CartLine>, CartTotals>} */
const totalsCache = new WeakMap();

/**
 * Totales memorizados por identidad del array de líneas.
 * @param {CartState} state
 * @returns {CartTotals}
 */
export function selectTotals(state) {
  const cached = totalsCache.get(state.lines);
  if (cached) return cached;

  let count = 0;
  let subtotal = 0;
  let savings = 0;
  for (const line of state.lines) {
    count += line.quantity;
    subtotal += line.unitPrice * line.quantity;
    if (line.hasOffer && line.unitPriceBase > line.unitPrice) {
      savings += (line.unitPriceBase - line.unitPrice) * line.quantity;
    }
  }

  const totals = Object.freeze({
    count,
    lineCount: state.lines.length,
    subtotal: Math.round(subtotal * 100) / 100,
    savings: Math.round(savings * 100) / 100,
  });
  totalsCache.set(state.lines, totals);
  return totals;
}

/**
 * @param {CartState} state
 * @param {string} offerId
 * @returns {CartLine|null}
 */
export function selectLine(state, offerId) {
  const index = indexOfLine(state.lines, offerId);
  return index === -1 ? null : state.lines[index];
}

/** @param {CartState} state @param {string} offerId */
export function selectIsPending(state, offerId) {
  return offerId in state.pending;
}

/** @param {CartState} state */
export function selectHasPending(state) {
  return Object.keys(state.pending).length > 0;
}

/* ─────────────────────────── store ────────────────────────────── */

/**
 * @typedef {Object} CartStore
 * @property {() => CartState} getState
 * @property {(action: {type: string}) => CartState} dispatch
 * @property {(listener: (state: CartState, previous: CartState) => void) => () => void} subscribe
 */

/**
 * @param {CartState} [initialState]
 * @returns {CartStore}
 */
export function createCartStore(initialState = INITIAL_STATE) {
  let state = initialState;
  /** @type {Set<(state: CartState, previous: CartState) => void>} */
  const listeners = new Set();

  return Object.freeze({
    getState: () => state,
    dispatch(action) {
      const previous = state;
      const updated = reduce(previous, action);
      if (updated === previous) return state;
      state = updated;
      for (const listener of Array.from(listeners)) listener(updated, previous);
      return state;
    },
    subscribe(listener) {
      listeners.add(listener);
      return () => { listeners.delete(listener); };
    },
  });
}

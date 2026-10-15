import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  INITIAL_STATE,
  actions,
  createCartStore,
  reduce,
  selectIsPending,
  selectLine,
  selectTotals,
} from './store.js';

const line = (offerId, quantity = 1, extra = {}) => Object.freeze({
  offerId,
  name: `Item ${offerId}`,
  imageUrl: null,
  unitPrice: 10,
  unitPriceBase: 10,
  hasOffer: false,
  quantity,
  rarity: null,
  type: null,
  isStatic: false,
  expiresAt: null,
  ...extra,
});

const ready = (...lines) => reduce(INITIAL_STATE, actions.hydrated({ lines, maxQuantity: 3 }));

test('hydrate: idle → loading → ready; congela estado y líneas', () => {
  const loading = reduce(INITIAL_STATE, actions.hydrateStarted());
  assert.equal(loading.status, 'loading');
  assert.equal(reduce(loading, actions.hydrateStarted()), loading, 'no-op mientras carga');

  const state = reduce(loading, actions.hydrated({ lines: [line('a', 2)], maxQuantity: 5 }));
  assert.equal(state.status, 'ready');
  assert.equal(state.maxQuantity, 5);
  assert.equal(state.revision, 1);
  assert.ok(Object.isFrozen(state));
  assert.ok(Object.isFrozen(state.lines));
  assert.ok(Object.isFrozen(state.lines[0]));
});

test('hydrateFailed: pasa a error y permite reintentar', () => {
  const failed = reduce(reduce(INITIAL_STATE, actions.hydrateStarted()), actions.hydrateFailed({ code: 'NETWORK', message: 'x' }));
  assert.equal(failed.status, 'error');
  assert.equal(failed.lastError.code, 'NETWORK');
  assert.equal(reduce(failed, actions.hydrateStarted()).status, 'loading');
});

test('acciones sin efecto conservan la identidad del estado', () => {
  const state = ready(line('a'));
  assert.equal(reduce(state, actions.quantitySet('zzz', 2, 1)), state);
  assert.equal(reduce(state, actions.quantitySet('a', 1, 1)), state);
  assert.equal(reduce(state, actions.lineRemoved('zzz', 1)), state);
  assert.equal(reduce(state, actions.opConfirmed(999)), state);
  assert.equal(reduce(state, actions.opRejected(999, { code: 'NETWORK', message: 'x' })), state, 'rechazo obsoleto');
  assert.equal(reduce(state, actions.drawerClosed()), state);
  assert.equal(reduce(state, { type: 'unknown' }), state);
});

test('lineAdded: crea la línea o incrementa sin superar maxQuantity', () => {
  const s1 = reduce(ready(), actions.lineAdded(line('a'), 1));
  assert.equal(s1.lines.length, 1);
  assert.ok(selectIsPending(s1, 'a'));

  const s2 = reduce(reduce(s1, actions.opConfirmed(1)), actions.lineAdded(line('a'), 2));
  assert.equal(selectLine(s2, 'a').quantity, 2);

  const full = ready(line('a', 3));
  assert.equal(reduce(full, actions.lineAdded(line('a'), 3)), full, 'en el tope no cambia nada');
});

test('quantitySet: acota a [0, max] y 0 elimina la línea', () => {
  const state = ready(line('a', 2));
  assert.equal(selectLine(reduce(state, actions.quantitySet('a', 99, 1)), 'a').quantity, 3);
  assert.equal(selectLine(reduce(state, actions.quantitySet('a', 0, 2)), 'a'), null);
  assert.equal(selectLine(reduce(state, actions.quantitySet('a', -4, 3)), 'a'), null);
});

test('opRejected: revierte a la línea base en su posición original', () => {
  const state = ready(line('a', 1), line('b', 1), line('c', 1));
  const error = { code: 'SERVER', message: 'boom' };

  const removed = reduce(state, actions.lineRemoved('b', 7));
  assert.deepEqual(removed.lines.map((l) => l.offerId), ['a', 'c']);
  const restored = reduce(removed, actions.opRejected(7, error));
  assert.deepEqual(restored.lines.map((l) => l.offerId), ['a', 'b', 'c']);
  assert.equal(restored.lastError.offerId, 'b');
  assert.ok(!selectIsPending(restored, 'b'));

  const changed = reduce(state, actions.quantitySet('c', 3, 8));
  assert.equal(selectLine(reduce(changed, actions.opRejected(8, error)), 'c').quantity, 1);

  const added = reduce(state, actions.lineAdded(line('d'), 9));
  assert.equal(selectLine(reduce(added, actions.opRejected(9, error)), 'd'), null, 'alta rechazada desaparece');
});

test('opConfirmed con snapshot: adopta el servidor pero respeta líneas aún pendientes', () => {
  const state = ready(line('a', 1), line('b', 1));
  const s1 = reduce(state, actions.quantitySet('a', 2, 1));
  const s2 = reduce(s1, actions.quantitySet('b', 3, 2));

  const server = [line('a', 2), line('b', 1)];
  const s3 = reduce(s2, actions.opConfirmed(1, { lines: server, maxQuantity: 3 }));
  assert.equal(selectLine(s3, 'a').quantity, 2);
  assert.equal(selectLine(s3, 'b').quantity, 3, 'b sigue pendiente: mantiene su valor optimista');
  assert.ok(!selectIsPending(s3, 'a'));
  assert.ok(selectIsPending(s3, 'b'));
});

test('replaced: sustituye todo y descarta pendientes', () => {
  const s1 = reduce(ready(line('a')), actions.quantitySet('a', 2, 1));
  const s2 = reduce(s1, actions.replaced({ lines: [line('z')], maxQuantity: 2 }));
  assert.deepEqual(s2.lines.map((l) => l.offerId), ['z']);
  assert.deepEqual(s2.pending, {});
  assert.equal(s2.maxQuantity, 2);
});

test('selectTotals: memoriza por identidad de líneas y calcula ahorro', () => {
  const state = ready(
    line('a', 2, { unitPrice: 8, unitPriceBase: 10, hasOffer: true }),
    line('b', 1, { unitPrice: 5.5, unitPriceBase: 5.5 }),
  );
  const totals = selectTotals(state);
  assert.deepEqual(totals, { count: 3, lineCount: 2, subtotal: 21.5, savings: 4 });
  assert.equal(selectTotals(state), totals);
  assert.equal(selectTotals(reduce(state, actions.drawerOpened())), totals, 'mismas líneas → mismo objeto');
});

test('drawer: abre con foco de retorno y cierra', () => {
  const trigger = {};
  const open = reduce(INITIAL_STATE, actions.drawerOpened(trigger));
  assert.equal(open.drawer.open, true);
  assert.equal(open.drawer.returnFocusTo, trigger);
  assert.equal(reduce(open, actions.drawerOpened(trigger)), open);
  assert.equal(reduce(open, actions.drawerClosed()).drawer, INITIAL_STATE.drawer);
});

test('createCartStore: notifica sólo en cambios reales y permite desuscribirse', () => {
  const store = createCartStore();
  const calls = [];
  const off = store.subscribe((state, previous) => calls.push([state.status, previous.status]));

  store.dispatch(actions.hydrateStarted());
  store.dispatch(actions.hydrateStarted());
  assert.deepEqual(calls, [['loading', 'idle']]);

  off();
  store.dispatch(actions.hydrated({ lines: [] }));
  assert.equal(calls.length, 1);
  assert.equal(store.getState().status, 'ready');
});

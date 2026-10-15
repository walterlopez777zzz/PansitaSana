import { test } from 'node:test';
import assert from 'node:assert/strict';

import { CartApiError, ErrorCode } from './api.js';
import { createCartOperations, AddOutcome, CHECKOUT_URL } from './operations.js';
import { createCartStore, selectLine } from './store.js';

/* ───────────── API falsa con resolución controlada ───────────── */

/**
 * Cada llamada queda registrada y en espera; el test decide cuándo y con qué
 * responde (`resolve`/`reject`) para reproducir cualquier orden de llegada.
 * El servidor simulado mantiene su propio carrito para poder responder con
 * snapshots realistas mediante `server.snapshot()`.
 */
function createFakeApi() {
  /** @type {Array<{method: string, args: Record<string, unknown>, resolve: (v: unknown) => void, reject: (e: unknown) => void, signal: AbortSignal|undefined}>} */
  const calls = [];
  /** @type {Map<string, number>} */
  const cart = new Map();

  const call = (method) => (args) => new Promise((resolve, reject) => {
    const entry = { method, args, resolve, reject, signal: args.signal };
    args.signal?.addEventListener('abort', () => reject(new CartApiError(ErrorCode.ABORTED, 'abort')), { once: true });
    calls.push(entry);
  });

  const item = (offerId, quantity) => ({ offerId, name: `Item ${offerId}`, price: 10, quantity });
  const snapshot = () => ({
    cart_items: Array.from(cart, ([offerId, quantity]) => item(offerId, quantity)),
    max_quantity_per_item: 3,
  });

  return {
    api: { get: call('get'), add: call('add'), setQuantity: call('setQuantity'), remove: call('remove'), buyNow: call('buyNow') },
    calls,
    cart,
    snapshot,
    /** Aplica la mutación de la llamada `i` al carrito simulado y responde con el snapshot resultante. */
    fulfil(i) {
      const { method, args, resolve } = calls[i];
      const offerId = /** @type {string} */ (args.offerId);
      if (method === 'remove') cart.delete(offerId);
      else if (method === 'add') cart.set(offerId, Math.min(3, (cart.get(offerId) ?? 0) + /** @type {number} */ (args.quantity)));
      else if (method === 'setQuantity') cart.set(offerId, /** @type {number} */ (args.quantity));
      else if (method === 'buyNow') { cart.clear(); cart.set(offerId, 1); }
      resolve(snapshot());
    },
    reject(i, code = ErrorCode.NETWORK) {
      calls[i].reject(new CartApiError(code, code));
    },
    methods: () => calls.map((c) => c.method),
  };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const product = (offerId) => ({ offerId, name: `Item ${offerId}`, price: 10 });

async function setup(initial = []) {
  const fake = createFakeApi();
  const store = createCartStore();
  const navigations = [];
  const ops = createCartOperations({ store, api: fake.api, navigate: (url) => navigations.push(url) });
  for (const [offerId, quantity] of initial) fake.cart.set(offerId, quantity);
  const hydrating = ops.hydrate();
  fake.calls[0].resolve(fake.snapshot());
  await hydrating;
  fake.calls.length = 0;
  return { fake, store, ops, navigations };
}

const quantity = (store, offerId) => selectLine(store.getState(), offerId)?.quantity ?? 0;

/* ───────────── tests ───────────── */

test('ráfaga de add: una sola petición en vuelo, la siguiente lleva el objetivo final', async () => {
  const { fake, store, ops } = await setup();

  assert.equal(ops.add(product('a')), AddOutcome.ADDED);
  assert.equal(ops.add(product('a')), AddOutcome.INCREMENTED);
  assert.equal(ops.add(product('a')), AddOutcome.INCREMENTED);
  assert.equal(ops.add(product('a')), AddOutcome.MAX);
  assert.equal(quantity(store, 'a'), 3, 'optimista: tope respetado');
  assert.deepEqual(fake.methods(), ['add'], 'sólo la primera intención viaja de inmediato');
  assert.equal(fake.calls[0].args.quantity, 1);

  fake.fulfil(0);
  await flush();
  assert.deepEqual(fake.methods(), ['add', 'setQuantity'], 'la línea ya existe: ajuste absoluto, no otro alta');
  assert.equal(fake.calls[1].args.quantity, 3, 'coalescido al último objetivo');
  assert.equal(quantity(store, 'a'), 3, 'el snapshot intermedio (1) no pisa la intención vigente');

  fake.fulfil(1);
  await ops.settle();
  assert.equal(quantity(store, 'a'), 3);
  assert.equal(Object.keys(store.getState().pending).length, 0);
});

test('add → remove antes de la respuesta: el remove viaja tras confirmarse el alta', async () => {
  const { fake, store, ops } = await setup();
  ops.add(product('a'));
  ops.remove('a');
  assert.equal(quantity(store, 'a'), 0);
  assert.deepEqual(fake.methods(), ['add']);

  fake.fulfil(0);
  await flush();
  assert.deepEqual(fake.methods(), ['add', 'remove']);
  assert.equal(quantity(store, 'a'), 0, 'el snapshot del alta no resucita la línea eliminada');

  fake.fulfil(1);
  await ops.settle();
  assert.equal(quantity(store, 'a'), 0);
  assert.equal(fake.cart.has('a'), false);
});

test('steppers en ráfaga sobre una línea existente: intención final única', async () => {
  const { fake, store, ops } = await setup([['a', 1]]);
  ops.increment('a');
  ops.increment('a');
  ops.decrement('a');
  ops.increment('a');
  assert.equal(quantity(store, 'a'), 3);
  assert.deepEqual(fake.methods(), ['setQuantity']);
  assert.equal(fake.calls[0].args.quantity, 2);

  fake.fulfil(0);
  await flush();
  assert.equal(fake.calls[1].args.quantity, 3);
  fake.fulfil(1);
  await ops.settle();
  assert.equal(quantity(store, 'a'), 3);
});

test('la petición en vuelo ya refleja la intención más nueva: no se reenvía', async () => {
  const { fake, store, ops } = await setup([['a', 1]]);
  ops.increment('a'); // → 2 en vuelo
  ops.increment('a'); // → 3
  ops.decrement('a'); // → 2 (coincide con lo que viaja)
  fake.fulfil(0);
  await ops.settle();
  assert.deepEqual(fake.methods(), ['setQuantity']);
  assert.equal(quantity(store, 'a'), 2);
  assert.equal(Object.keys(store.getState().pending).length, 0);
});

test('respuestas fuera de orden entre líneas: un snapshot rezagado no retrocede el estado', async () => {
  const { fake, store, ops } = await setup([['a', 1], ['b', 1]]);
  ops.increment('a'); // llamada 0
  ops.increment('b'); // llamada 1
  fake.fulfil(1); // b responde primero (snapshot: a=1 en servidor, b=2)
  await flush();
  assert.equal(quantity(store, 'a'), 2, 'a sigue pendiente y conserva su valor optimista');
  assert.equal(quantity(store, 'b'), 2);

  fake.fulfil(0); // a responde tarde (snapshot: a=2, b=2)
  await ops.settle();
  assert.equal(quantity(store, 'a'), 2);
  assert.equal(quantity(store, 'b'), 2);
});

test('snapshot rezagado (ticket antiguo) confirma la operación sin aportar datos', async () => {
  const { fake, store, ops } = await setup([['a', 1], ['b', 1]]);
  ops.increment('a'); // llamada 0 (ticket 1)
  ops.increment('b'); // llamada 1 (ticket 2)
  // El servidor procesó b antes que a: la respuesta de a (llega segunda pero
  // con ticket anterior) lleva un snapshot donde b aún vale 1.
  fake.cart.set('b', 2);
  fake.calls[1].resolve(fake.snapshot());
  await flush();
  fake.calls[0].resolve({ cart_items: [{ offerId: 'a', quantity: 2, price: 10 }, { offerId: 'b', quantity: 1, price: 10 }] });
  await ops.settle();
  assert.equal(quantity(store, 'a'), 2);
  assert.equal(quantity(store, 'b'), 2, 'el snapshot con ticket antiguo se descarta');
  assert.equal(Object.keys(store.getState().pending).length, 0);
});

test('rechazo de una intención ya superada: se envía la vigente en vez de revertir', async () => {
  const { fake, store, ops } = await setup([['a', 1]]);
  ops.increment('a'); // → 2
  ops.increment('a'); // → 3
  fake.reject(0);
  await flush();
  assert.equal(quantity(store, 'a'), 3, 'sin rollback: la intención vigente sigue viva');
  assert.deepEqual(fake.methods(), ['setQuantity', 'setQuantity']);
  fake.fulfil(1);
  await ops.settle();
  assert.equal(quantity(store, 'a'), 3);
  assert.equal(store.getState().lastError, null);
});

test('rechazo de la intención vigente: rollback a la base', async () => {
  const { fake, store, ops } = await setup([['a', 1]]);
  ops.increment('a');
  ops.increment('a');
  fake.fulfil(0);
  await flush();
  fake.reject(1, ErrorCode.MAX_QUANTITY);
  await ops.settle();
  assert.equal(quantity(store, 'a'), 1, 'vuelve a la base previa a la ráfaga');
  assert.equal(store.getState().lastError?.code, ErrorCode.MAX_QUANTITY);
});

test('NOT_FOUND en ajuste: la siguiente intención vuelve a dar de alta', async () => {
  const { fake, store, ops } = await setup([['a', 1]]);
  ops.increment('a'); // setQuantity 2
  ops.increment('a'); // → 3
  fake.cart.delete('a'); // otra pestaña la quitó
  fake.reject(0, ErrorCode.NOT_FOUND);
  await flush();
  assert.equal(fake.methods()[1], 'add');
  assert.equal(fake.calls[1].args.quantity, 3);
  fake.fulfil(1);
  await ops.settle();
  assert.equal(quantity(store, 'a'), 3);
});

test('hydrate durante una mutación: conserva el valor optimista de la línea pendiente', async () => {
  const { fake, store, ops } = await setup([['a', 1]]);
  ops.increment('a');
  const hydrating = ops.hydrate();
  fake.calls[1].resolve(fake.snapshot()); // el GET responde con a=1 (aún sin aplicar)
  await hydrating;
  assert.equal(quantity(store, 'a'), 2);
  fake.fulfil(0);
  await ops.settle();
  assert.equal(quantity(store, 'a'), 2);
});

test('hydrate repetido: sólo la última lectura se aplica', async () => {
  const { fake, store, ops } = await setup();
  const first = ops.hydrate();
  const second = ops.hydrate();
  assert.equal(fake.calls[0].signal?.aborted, true, 'la lectura anterior se cancela');
  fake.cart.set('z', 1);
  fake.calls[1].resolve(fake.snapshot());
  await Promise.all([first, second]);
  assert.equal(quantity(store, 'z'), 1);
  assert.equal(store.getState().status, 'ready');
});

test('buyNow: vuelo único, espera a que asienten las mutaciones y congela nuevas', async () => {
  const { fake, store, ops, navigations } = await setup([['a', 1]]);
  ops.increment('a');
  const p1 = ops.buyNow(product('b'));
  const p2 = ops.buyNow(product('b'));
  assert.equal(p1, p2, 'misma promesa para pulsaciones repetidas');
  await flush();
  assert.deepEqual(fake.methods(), ['setQuantity'], 'buyNow no viaja hasta que asiente el canal');

  ops.increment('a');
  assert.equal(quantity(store, 'a'), 2, 'mutaciones congeladas durante la compra directa');

  fake.fulfil(0);
  await flush();
  assert.deepEqual(fake.methods(), ['setQuantity', 'buyNow']);
  fake.fulfil(1);
  await p1;
  assert.deepEqual(navigations, [CHECKOUT_URL]);
  assert.equal(quantity(store, 'b'), 1);
  assert.equal(quantity(store, 'a'), 0, 'el snapshot de buyNow es autoritativo');
  assert.equal(Object.keys(store.getState().pending).length, 0);
});

test('buyNow fallido: libera el vuelo único y vuelve a permitir mutaciones', async () => {
  const { fake, store, ops, navigations } = await setup([['a', 1]]);
  const attempt = ops.buyNow(product('b'));
  await flush();
  fake.reject(0);
  await assert.rejects(attempt);
  assert.deepEqual(navigations, []);
  ops.increment('a');
  assert.equal(quantity(store, 'a'), 2);
  const retry = ops.buyNow(product('b'));
  assert.notEqual(retry, attempt);
  fake.fulfil(1);
  await flush();
  fake.fulfil(2);
  await retry;
  assert.deepEqual(navigations, [CHECKOUT_URL]);
});

test('dispose: aborta lo que está en vuelo y no vuelve a tocar el store', async () => {
  const { fake, store, ops } = await setup([['a', 1]]);
  ops.increment('a');
  const before = store.getState();
  ops.dispose();
  assert.equal(fake.calls[0].signal?.aborted, true);
  await flush();
  assert.equal(store.getState(), before, 'ni confirmación ni rollback tras desmontar');
  ops.increment('a');
  assert.equal(store.getState(), before, 'las intenciones posteriores se ignoran');
  await ops.settle();
});

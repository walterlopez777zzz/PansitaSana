import { test } from 'node:test';
import assert from 'node:assert/strict';

import { INITIAL_STATE, createFilterStore, hasFilters, hasSearch, reduce } from './state.js';

test('reduce: devuelve la misma instancia si nada cambia', () => {
  assert.equal(reduce(INITIAL_STATE, {}), INITIAL_STATE);
  assert.equal(reduce(INITIAL_STATE, { query: '   ' }), INITIAL_STATE);
  assert.equal(reduce(INITIAL_STATE, { types: [] }), INITIAL_STATE);
  assert.equal(reduce(INITIAL_STATE, { priceMin: 200, priceMax: 3000 }), INITIAL_STATE);
});

test('reduce: normaliza query, tipos y precio; el resultado es inmutable', () => {
  const next = reduce(INITIAL_STATE, {
    query: '  Traje ',
    types: ['Picos', ' Trajes', 'Picos', ''],
    priceMin: 226,
    priceMax: 99999,
  });
  assert.equal(next.query, 'Traje');
  assert.deepEqual(next.types, ['Picos', 'Trajes']);
  assert.equal(next.priceMin, 250);
  assert.equal(next.priceMax, 3000);
  assert.ok(Object.isFrozen(next));
  assert.ok(Object.isFrozen(next.types));
  assert.equal(INITIAL_STATE.query, '');
});

test('reduce: el extremo movido se detiene contra el otro sin arrastrarlo', () => {
  const base = reduce(INITIAL_STATE, { priceMin: 500, priceMax: 550 });
  assert.deepEqual([base.priceMin, base.priceMax], [500, 550]);

  assert.equal(reduce(base, { priceMin: 700 }), base, 'mover el mínimo más allá del máximo no cambia nada');
  assert.equal(reduce(base, { priceMax: 400 }), base, 'mover el máximo por debajo del mínimo no cambia nada');

  const wider = reduce(base, { priceMax: 1000 });
  assert.deepEqual([wider.priceMin, wider.priceMax], [500, 1000]);
  const minUp = reduce(wider, { priceMin: 900 });
  assert.deepEqual([minUp.priceMin, minUp.priceMax], [900, 1000]);
});

test('hasSearch / hasFilters', () => {
  assert.equal(hasSearch(INITIAL_STATE), false);
  assert.equal(hasFilters(INITIAL_STATE), false);
  assert.equal(hasSearch(reduce(INITIAL_STATE, { query: 'x' })), true);
  assert.equal(hasFilters(reduce(INITIAL_STATE, { query: 'x' })), false);
  assert.equal(hasFilters(reduce(INITIAL_STATE, { types: ['Picos'] })), true);
  assert.equal(hasFilters(reduce(INITIAL_STATE, { priceMax: 1000 })), true);
});

test('store: set notifica sólo ante cambios reales, en orden y con estado previo', () => {
  const store = createFilterStore();
  const log = [];
  const offA = store.subscribe((state, previous) => log.push(['a', state.query, previous.query]));
  store.subscribe((state) => log.push(['b', state.query]));

  assert.equal(store.set({ query: 'uno' }), true);
  assert.equal(store.set({ query: 'uno' }), false);
  assert.deepEqual(log, [['a', 'uno', ''], ['b', 'uno']]);

  offA();
  log.length = 0;
  assert.equal(store.set({ query: 'dos' }), true);
  assert.deepEqual(log, [['b', 'dos']]);
});

test('store: clearFilters conserva la búsqueda y clearSearch conserva los filtros', () => {
  const store = createFilterStore();
  store.set({ query: 'traje', types: ['Picos'], priceMin: 500 });

  assert.equal(store.clearFilters(), true);
  assert.deepEqual(store.get(), { query: 'traje', types: [], priceMin: 200, priceMax: 3000 });
  assert.equal(store.clearFilters(), false);

  store.set({ types: ['Picos'] });
  assert.equal(store.clearSearch(), true);
  assert.deepEqual(store.get(), { query: '', types: ['Picos'], priceMin: 200, priceMax: 3000 });
});

test('store: un listener que se desuscribe durante la notificación no rompe el resto', () => {
  const store = createFilterStore();
  const seen = [];
  const off = store.subscribe(() => {
    seen.push('first');
    off();
  });
  store.subscribe(() => seen.push('second'));
  store.set({ query: 'a' });
  store.set({ query: 'b' });
  assert.deepEqual(seen, ['first', 'second', 'second']);
});

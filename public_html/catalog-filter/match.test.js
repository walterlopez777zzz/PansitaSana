import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  PRICE_RANGE,
  cardMatches,
  clampPriceRange,
  deriveCriteria,
  isPriceDefault,
  matchesTokens,
  normalizeText,
  priceInRange,
  tokenize,
} from './match.js';

test('normalizeText: minúsculas, sin acentos ni signos, espacios colapsados', () => {
  assert.equal(normalizeText('  Pícaro   Élite!! '), 'picaro elite');
  assert.equal(normalizeText('Ñandú'), 'nandu');
  assert.equal(normalizeText(null), '');
  assert.equal(normalizeText(undefined), '');
  assert.equal(normalizeText(42), '42');
});

test('normalizeText: reglas fonéticas del español', () => {
  assert.equal(normalizeText('vaca'), normalizeText('baca'));
  assert.equal(normalizeText('hola'), normalizeText('ola'));
  assert.equal(normalizeText('llama'), normalizeText('yama'));
  assert.equal(normalizeText('zapato'), normalizeText('sapato'));
  assert.equal(normalizeText('cielo'), normalizeText('sielo'));
  assert.equal(normalizeText('gente'), normalizeText('jente'));
  assert.equal(normalizeText('queso'), normalizeText('keso'));
  assert.equal(normalizeText('carro'), normalizeText('caro'));
});

test('tokenize: separa por espacios y descarta vacíos', () => {
  assert.deepEqual(tokenize('  Traje  de   Élite '), ['traje', 'de', 'elite']);
  assert.deepEqual(tokenize(''), []);
  assert.deepEqual(tokenize('   '), []);
});

test('matchesTokens: todos los tokens deben aparecer (AND)', () => {
  const text = normalizeText('Caballero Oscuro Rojo');
  assert.equal(matchesTokens(text, tokenize('oscuro caballero')), true);
  assert.equal(matchesTokens(text, tokenize('cabayero')), true);
  assert.equal(matchesTokens(text, tokenize('oscuro azul')), false);
  assert.equal(matchesTokens(text, []), true);
});

test('clampPriceRange: ajusta al paso y a los límites', () => {
  assert.deepEqual(clampPriceRange(0, 99999), { min: 200, max: 3000 });
  assert.deepEqual(clampPriceRange('abc', undefined), { min: 200, max: 3000 });
  assert.deepEqual(clampPriceRange(226, 1010), { min: 250, max: 1000 });
  assert.equal(PRICE_RANGE.STEP, 50);
});

test('clampPriceRange: el extremo movido se detiene contra el otro', () => {
  assert.deepEqual(clampPriceRange(1000, 1000, 'min'), { min: 950, max: 1000 });
  assert.deepEqual(clampPriceRange(1000, 1000, 'max'), { min: 1000, max: 1050 });
  assert.deepEqual(clampPriceRange(3000, 3000, 'min'), { min: 2950, max: 3000 });
  assert.deepEqual(clampPriceRange(3000, 3000, 'max'), { min: 2950, max: 3000 });
  assert.deepEqual(clampPriceRange(200, 200, 'min'), { min: 200, max: 250 });
  assert.deepEqual(clampPriceRange(200, 200, 'max'), { min: 200, max: 250 });
  assert.deepEqual(clampPriceRange(1200, 800, 'min'), { min: 750, max: 800 });
  assert.deepEqual(clampPriceRange(1200, 800, 'max'), { min: 1200, max: 1250 });
});

test('isPriceDefault / priceInRange', () => {
  assert.equal(isPriceDefault({ priceMin: 200, priceMax: 3000 }), true);
  assert.equal(isPriceDefault({ priceMin: 250, priceMax: 3000 }), false);

  const closed = { priceMin: 500, priceMax: 1000 };
  assert.equal(priceInRange(500, closed), true);
  assert.equal(priceInRange(1000, closed), true);
  assert.equal(priceInRange(1001, closed), false);
  assert.equal(priceInRange(499, closed), false);

  const open = { priceMin: 500, priceMax: PRICE_RANGE.MAX };
  assert.equal(priceInRange(99999, open), true);

  assert.equal(priceInRange(0, { priceMin: 200, priceMax: 3000 }), false);
  assert.equal(priceInRange(NaN, { priceMin: 200, priceMax: 3000 }), false);
  assert.equal(priceInRange(-5, { priceMin: 200, priceMax: 3000 }), false);
});

test('deriveCriteria: inactivo con estado por defecto y con subconjuntos', () => {
  const idle = deriveCriteria({ query: '', types: [], priceMin: 200, priceMax: 3000 });
  assert.equal(idle.active, false);
  assert.equal(idle.price, null);

  const onlyQuery = deriveCriteria({ query: '  Traje ' });
  assert.equal(onlyQuery.active, true);
  assert.deepEqual(onlyQuery.tokens, ['traje']);
  assert.equal(onlyQuery.types.size, 0);
  assert.equal(onlyQuery.price, null);

  const priced = deriveCriteria({ query: '', types: [], priceMin: 500, priceMax: 3000 });
  assert.equal(priced.active, true);
  assert.deepEqual(priced.price, { priceMin: 500, priceMax: 3000 });
});

test('cardMatches: AND entre texto, tipo y precio; OR dentro de tipos', () => {
  const facets = { text: normalizeText('Caballero Oscuro'), types: ['Trajes', 'Lotes'], price: 1500 };

  assert.equal(cardMatches(facets, deriveCriteria({ query: '' })), true);
  assert.equal(cardMatches(facets, deriveCriteria({ query: 'oscuro' })), true);
  assert.equal(cardMatches(facets, deriveCriteria({ query: 'oscuro', types: ['Picos'] })), false);
  assert.equal(cardMatches(facets, deriveCriteria({ query: 'oscuro', types: ['Picos', 'Lotes'] })), true);
  assert.equal(cardMatches(facets, deriveCriteria({ types: ['Trajes'], priceMin: 1600, priceMax: 3000 })), false);
  assert.equal(cardMatches(facets, deriveCriteria({ types: ['Trajes'], priceMin: 1000, priceMax: 1500 })), true);

  const free = { text: 'gratis', types: ['Gestos'], price: 0 };
  assert.equal(cardMatches(free, deriveCriteria({ query: 'gratis' })), true);
  assert.equal(cardMatches(free, deriveCriteria({ priceMin: 200, priceMax: 2000 })), false);
});

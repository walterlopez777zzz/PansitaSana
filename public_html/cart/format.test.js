import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  formatBadge,
  formatCount,
  formatMoney,
  productToLine,
  safeHexColor,
  safeHttpUrl,
  thumbnailUrl,
  toCartLine,
  toCartSnapshot,
} from './format.js';

test('formatMoney: dos decimales y tolerancia a valores inválidos', () => {
  assert.equal(formatMoney(12.5), 'S/ 12.50');
  assert.equal(formatMoney('7'), 'S/ 7.00');
  assert.equal(formatMoney(NaN), 'S/ 0.00');
  assert.equal(formatMoney(undefined), 'S/ 0.00');
});

test('formatCount / formatBadge', () => {
  assert.equal(formatCount(1), '1 artículo');
  assert.equal(formatCount(2), '2 artículos');
  assert.equal(formatCount(0), '0 artículos');
  assert.equal(formatBadge(0), '0');
  assert.equal(formatBadge(-2), '0');
  assert.equal(formatBadge(7), '7');
  assert.equal(formatBadge(120), '99+');
});

test('safeHttpUrl: sólo http(s) absolutas', () => {
  assert.equal(safeHttpUrl('https://cdn.example.com/a.png'), 'https://cdn.example.com/a.png');
  assert.equal(safeHttpUrl('javascript:alert(1)'), null);
  assert.equal(safeHttpUrl('data:image/png;base64,AAAA'), null);
  assert.equal(safeHttpUrl('//cdn.example.com/a.png'), null);
  assert.equal(safeHttpUrl(''), null);
  assert.equal(safeHttpUrl(42), null);
});

test('thumbnailUrl: pasa por el CDN con ancho y no toca URLs inválidas', () => {
  const out = thumbnailUrl('https://cdn.example.com/a.png', 96);
  assert.ok(out.startsWith('https://wsrv.nl/?url='));
  assert.ok(out.includes(encodeURIComponent('https://cdn.example.com/a.png')));
  assert.ok(out.includes('w=96'));
  assert.equal(thumbnailUrl('javascript:x'), null);
});

test('safeHexColor: sólo #rrggbb', () => {
  assert.equal(safeHexColor('#A1B2C3'), '#a1b2c3');
  assert.equal(safeHexColor('#abc'), null);
  assert.equal(safeHexColor('red'), null);
  assert.equal(safeHexColor('#a1b2c3; background:url(x)'), null);
});

test('toCartLine: normaliza y sanea el item del backend', () => {
  const line = toCartLine({
    offerId: 123,
    name: '  Pico  ',
    price: '8.5',
    price_base: 10,
    has_offer: true,
    image: 'https://cdn.example.com/p.png',
    quantity: '2.9',
    rarity: 'epic',
    rarity_label: 'Épico',
    rarity_color: '#8000ff',
    type: 'Pico',
    is_static: false,
    expires_at_timestamp: 1_700_000_000,
  });
  assert.equal(line.offerId, '123');
  assert.equal(line.name, 'Pico');
  assert.equal(line.unitPrice, 8.5);
  assert.equal(line.unitPriceBase, 10);
  assert.equal(line.hasOffer, true);
  assert.equal(line.quantity, 2);
  assert.deepEqual(line.rarity, { id: 'epic', label: 'Épico', color: '#8000ff' });
  assert.equal(line.expiresAt, 1_700_000_000_000);
  assert.ok(Object.isFrozen(line));
});

test('toCartLine: defaults defensivos', () => {
  assert.equal(toCartLine(null), null);
  assert.equal(toCartLine({ name: 'sin id' }), null);
  const line = toCartLine({ offerId: 'x', price: 5, price_base: 3, has_offer: true, image: 'javascript:1', quantity: 0 });
  assert.equal(line.name, 'Artículo');
  assert.equal(line.imageUrl, null);
  assert.equal(line.unitPriceBase, 5, 'la base nunca queda por debajo del precio final');
  assert.equal(line.hasOffer, false, 'sin descuento real no hay oferta');
  assert.equal(line.quantity, 1);
  assert.equal(line.rarity, null);
  assert.equal(line.expiresAt, null);
});

test('toCartSnapshot: filtra items inválidos y lee el máximo por artículo', () => {
  const snap = toCartSnapshot({
    cart_items: [{ offerId: 'a', price: 1 }, null, { name: 'sin id' }, { offerId: 'b', price: 2 }],
    max_quantity_per_item: '3',
  });
  assert.deepEqual(snap.lines.map((l) => l.offerId), ['a', 'b']);
  assert.equal(snap.maxQuantity, 3);
  assert.deepEqual(toCartSnapshot(null), { lines: [], maxQuantity: undefined });
  assert.equal(toCartSnapshot({ cart_items: [], max_quantity_per_item: 0 }).maxQuantity, undefined);
});

test('productToLine: línea optimista con cantidad 1', () => {
  const line = productToLine({ offerId: 99, name: 'Traje', price: '20', priceBase: 25, image: 'https://x.y/z.png', isStatic: true, type: 'Traje' });
  assert.equal(line.offerId, '99');
  assert.equal(line.quantity, 1);
  assert.equal(line.hasOffer, true);
  assert.equal(line.isStatic, true);
  assert.equal(line.rarity, null);
  assert.equal(productToLine({ offerId: 'a', price: 5 }).hasOffer, false);
});

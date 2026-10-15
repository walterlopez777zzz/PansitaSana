import { test, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';

import { createCatalogApplier, readCardFacets } from './apply.js';
import { INITIAL_STATE, reduce } from './state.js';

/* ------------------------------------------------------------------ *
 * DOM mínimo: sólo lo que apply.js necesita. Instrumentado para contar
 * escrituras de `hidden` y detectar cualquier mutación estructural.
 * ------------------------------------------------------------------ */

class FakeElement {
  constructor(tag, { className = '', dataset = {}, text = '' } = {}) {
    this.tagName = tag.toUpperCase();
    this.className = className;
    this.dataset = { ...dataset };
    this.textContent = text;
    this.attributes = {};
    this.children = [];
    this.parentElement = null;
    this.isRoot = false;
    this._hidden = false;
    this.hiddenWrites = 0;
  }

  get hidden() {
    return this._hidden;
  }

  set hidden(value) {
    this._hidden = Boolean(value);
    this.hiddenWrites += 1;
  }

  get parentNode() {
    return this.parentElement;
  }

  get isConnected() {
    let node = this;
    while (node.parentElement) node = node.parentElement;
    return node.isRoot;
  }

  appendChild(child) {
    if (child.parentElement) child.parentElement.removeChild(child);
    child.parentElement = this;
    this.children.push(child);
    return child;
  }

  removeChild(child) {
    this.children = this.children.filter((c) => c !== child);
    child.parentElement = null;
    return child;
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  matches(selector) {
    return matchesSimple(this, selector);
  }

  querySelector(selector) {
    return this.querySelectorAll(selector)[0] ?? null;
  }

  querySelectorAll(selector) {
    const scoped = selector.match(/^:scope\s*>\s*(.+)$/);
    if (scoped) return this.children.filter((child) => matchesSimple(child, scoped[1].trim()));
    const out = [];
    const walk = (node) => {
      for (const child of node.children) {
        if (matchesSimple(child, selector)) out.push(child);
        walk(child);
      }
    };
    walk(this);
    return out;
  }
}

function matchesSimple(el, selector) {
  const parts = selector.match(/[.#[][^.#[\]]+\]?|^[a-z0-9]+/gi) || [];
  return parts.every((part) => {
    if (part.startsWith('.')) return el.className.split(/\s+/).includes(part.slice(1));
    if (part.startsWith('[')) {
      const attr = part.slice(1, -1);
      const dataKey = attr.replace(/^data-/, '').replace(/-([a-z])/g, (_, c) => c.toUpperCase());
      return attr.startsWith('data-') ? dataKey in el.dataset : attr in el.attributes;
    }
    return el.tagName === part.toUpperCase();
  });
}

function card({ name, types = [], price }) {
  const el = new FakeElement('div', {
    className: 'item-card-wrapper',
    dataset: { filterTypes: types.join('|'), ...(price !== undefined ? { filterPrice: String(price) } : {}) },
  });
  el.appendChild(new FakeElement('h3', { className: 'card-title', text: name }));
  return el;
}

function gridSection(id, cards, aux = false) {
  const section = new FakeElement('div', { dataset: { catalogSection: '', ...(aux ? { catalogAux: '' } : {}) } });
  section.id = id;
  section.appendChild(new FakeElement('h2', { text: id }));
  const container = new FakeElement('div', { className: 'section-items-container' });
  for (const c of cards) {
    const tile = new FakeElement('div');
    tile.appendChild(c);
    container.appendChild(tile);
  }
  section.appendChild(container);
  return section;
}

function carouselSection(id, cards) {
  const section = new FakeElement('div', { dataset: { catalogSection: '' } });
  section.id = id;
  const container = new FakeElement('div', { className: 'section-items-container' });
  const track = new FakeElement('div', { className: 'shop-carousel__track' });
  for (const c of cards) track.appendChild(c);
  container.appendChild(track);
  section.appendChild(container);
  return section;
}

/** Instantánea estructural (identidad de nodos por nivel) para probar "cero mutaciones". */
function snapshot(node) {
  return { node, children: node.children.map(snapshot) };
}

function assertSameStructure(before, node) {
  assert.equal(before.node, node);
  assert.deepEqual(before.children.map((c) => c.node), node.children);
  before.children.forEach((childSnap, i) => assertSameStructure(childSnap, node.children[i]));
}

function totalHiddenWrites(node) {
  return node.hiddenWrites + node.children.reduce((sum, child) => sum + totalHiddenWrites(child), 0);
}

function makeRaf() {
  const queue = new Map();
  let nextId = 1;
  return {
    raf: (cb) => {
      const id = nextId++;
      queue.set(id, cb);
      return id;
    },
    caf: (id) => queue.delete(id),
    pending: () => queue.size,
    tick() {
      const callbacks = Array.from(queue.values());
      queue.clear();
      for (const cb of callbacks) cb(0);
    },
  };
}

const fakeWindow = { scrollY: 0, scrollTo(_x, y) { this.scrollY = y; } };
let savedGlobals;

beforeEach(() => {
  savedGlobals = { document: globalThis.document, window: globalThis.window };
  globalThis.document = { createElement: (tag) => new FakeElement(tag) };
  fakeWindow.scrollY = 0;
  globalThis.window = fakeWindow;
});

afterEach(() => {
  globalThis.document = savedGlobals.document;
  globalThis.window = savedGlobals.window;
});

function buildCatalog() {
  const root = new FakeElement('div');
  root.isRoot = true;
  const traje = card({ name: 'Caballero Oscuro', types: ['Trajes'], price: 1500 });
  const pico = card({ name: 'Hacha Sombría', types: ['Picos'], price: 800 });
  const lote = card({ name: 'Lote Caballero', types: ['Lotes', 'Trajes', 'Picos'], price: 2500 });
  const gratis = card({ name: 'Gesto Gratis', types: ['Gestos'], price: 0 });
  const auxCard = card({ name: 'Caballero Oscuro', types: ['Trajes'], price: 1500 });
  const featured = gridSection('featured', [traje, pico]);
  const bundles = carouselSection('bundles', [lote, gratis]);
  const bestsellers = gridSection('bestsellers', [auxCard], true);
  root.appendChild(bestsellers);
  root.appendChild(featured);
  root.appendChild(bundles);
  return { root, traje, pico, lote, gratis, auxCard, featured, bundles, bestsellers };
}

function createApplier(root, frames, extra = {}) {
  return createCatalogApplier({
    root,
    sectionSelector: ':scope > [data-catalog-section]',
    cardSelector: '.item-card-wrapper',
    trackSelector: '.shop-carousel__track',
    isAuxSection: (section) => 'catalogAux' in section.dataset,
    raf: frames.raf,
    caf: frames.caf,
    ...extra,
  });
}

test('readCardFacets: lee nombre (h3), tipos y precio; precio ausente = NaN', () => {
  const facets = readCardFacets(card({ name: 'Pícaro Élite', types: ['Trajes', 'Lotes'], price: 1200 }));
  assert.equal(facets.text, 'picaro elite');
  assert.deepEqual(facets.types, ['Trajes', 'Lotes']);
  assert.equal(facets.price, 1200);
  assert.ok(Number.isNaN(readCardFacets(card({ name: 'X' })).price));
});

test('indexa el catálogo: cuenta tarjetas y tipos sólo de secciones no auxiliares', () => {
  const { root } = buildCatalog();
  const frames = makeRaf();
  const applier = createApplier(root, frames);
  const summaries = [];
  applier.onIndex((summary) => summaries.push(summary));

  applier.setState(INITIAL_STATE);
  assert.equal(frames.pending(), 1);
  frames.tick();

  assert.equal(summaries.length, 1);
  const { cards, typeCounts } = applier.getSummary();
  assert.equal(cards, 4);
  assert.deepEqual(Object.fromEntries(typeCounts), { Trajes: 2, Picos: 2, Lotes: 1, Gestos: 1 });
});

test('filtrar sólo alterna hidden en tiles/secciones; la estructura del DOM es idéntica', () => {
  const cat = buildCatalog();
  const frames = makeRaf();
  const applier = createApplier(cat.root, frames);
  applier.setState(INITIAL_STATE);
  frames.tick();
  const before = snapshot(cat.root);

  applier.setState(reduce(INITIAL_STATE, { query: 'caballero' }));
  frames.tick();

  assertSameStructure(before, cat.root);
  assert.equal(cat.traje.parentElement.hidden, false, 'tile de grid coincidente visible');
  assert.equal(cat.pico.parentElement.hidden, true, 'tile de grid no coincidente oculta');
  assert.equal(cat.lote.hidden, false, 'en carrusel la tile es la propia tarjeta');
  assert.equal(cat.gratis.hidden, true);
  assert.equal(cat.featured.hidden, false);
  assert.equal(cat.bundles.hidden, false);
  assert.equal(cat.bestsellers.hidden, true, 'sección auxiliar oculta con criterios activos');
  assert.equal(cat.auxCard.parentElement.hidden, false, 'las tarjetas auxiliares no se tocan');

  applier.setState(reduce(INITIAL_STATE, { types: ['Gestos'] }));
  frames.tick();
  assert.equal(cat.featured.hidden, true, 'sección sin coincidencias oculta');
  assert.equal(cat.bundles.hidden, false);
  assert.equal(cat.gratis.hidden, false);

  applier.setState(INITIAL_STATE);
  frames.tick();
  assertSameStructure(before, cat.root);
  for (const el of [cat.traje.parentElement, cat.pico.parentElement, cat.lote, cat.gratis, cat.featured, cat.bundles, cat.bestsellers]) {
    assert.equal(el.hidden, false);
  }
});

test('cero escrituras idempotentes y un único frame por ráfaga de cambios', () => {
  const cat = buildCatalog();
  const frames = makeRaf();
  const applier = createApplier(cat.root, frames);

  applier.setState(INITIAL_STATE);
  applier.invalidateIndex();
  applier.setState(reduce(INITIAL_STATE, { query: 'caballero' }));
  applier.setState(reduce(INITIAL_STATE, { query: 'hacha' }));
  assert.equal(frames.pending(), 1, 'todos los cambios se consolidan en un frame');
  frames.tick();

  const writesAfterFirstApply = totalHiddenWrites(cat.root);
  assert.ok(writesAfterFirstApply > 0);
  assert.equal(cat.pico.parentElement.hidden, false);
  assert.equal(cat.traje.parentElement.hidden, true);

  applier.setState(reduce(INITIAL_STATE, { query: 'hacha ' }));
  frames.tick();
  assert.equal(totalHiddenWrites(cat.root), writesAfterFirstApply, 'mismo resultado => cero escrituras');

  applier.invalidateIndex();
  frames.tick();
  assert.equal(totalHiddenWrites(cat.root), writesAfterFirstApply, 'reindexar sin cambios => cero escrituras');
});

test('onSectionHidden se invoca sólo cuando cambia la visibilidad de una sección', () => {
  const cat = buildCatalog();
  const frames = makeRaf();
  const calls = [];
  const applier = createApplier(cat.root, frames, {
    onSectionHidden: (section, hidden) => calls.push([section.id, hidden]),
  });

  applier.setState(INITIAL_STATE);
  frames.tick();
  assert.deepEqual(calls, [], 'sin criterios nada cambia');

  applier.setState(reduce(INITIAL_STATE, { types: ['Gestos'] }));
  frames.tick();
  assert.deepEqual(calls, [['bestsellers', true], ['featured', true]]);

  applier.setState(reduce(INITIAL_STATE, { types: ['Gestos'], query: 'gesto' }));
  frames.tick();
  assert.equal(calls.length, 2, 'mismo resultado => sin llamadas');

  applier.setState(INITIAL_STATE);
  frames.tick();
  assert.deepEqual(calls.slice(2), [['bestsellers', false], ['featured', false]]);
});

test('estado vacío: aparece sólo sin coincidencias y se oculta sin tocar el catálogo', () => {
  const cat = buildCatalog();
  const frames = makeRaf();
  const applier = createApplier(cat.root, frames, { emptyMessage: 'Nada' });
  applier.setState(INITIAL_STATE);
  frames.tick();
  const sectionsBefore = [...cat.root.children];

  applier.setState(reduce(INITIAL_STATE, { query: 'wxwx' }));
  frames.tick();
  const emptyEl = cat.root.children.find((child) => !('catalogSection' in child.dataset));
  assert.ok(emptyEl, 'se añade un nodo de estado vacío al final del root');
  assert.equal(emptyEl.hidden, false);
  assert.equal(emptyEl.textContent, 'Nada');
  assert.equal(emptyEl.attributes.role, 'status');
  assert.deepEqual(cat.root.children.slice(0, 3), sectionsBefore);
  for (const section of sectionsBefore) assert.equal(section.hidden, true);

  applier.setState(INITIAL_STATE);
  frames.tick();
  assert.equal(emptyEl.hidden, true);
  assert.equal(cat.root.children.length, 4, 'el nodo vacío se conserva oculto; no se recrea');
});

test('el estado vacío no se muestra cuando el catálogo aún no tiene tarjetas', () => {
  const root = new FakeElement('div');
  root.isRoot = true;
  root.appendChild(gridSection('skeleton', []));
  const frames = makeRaf();
  const applier = createApplier(root, frames);
  applier.setState(reduce(INITIAL_STATE, { query: 'x' }));
  frames.tick();
  assert.equal(root.children.length, 1);
});

test('scroll: se memoriza al activar criterios y se restaura exactamente al desactivarlos', () => {
  const cat = buildCatalog();
  const frames = makeRaf();
  let viewActive = true;
  const applier = createApplier(cat.root, frames, { canRestoreScroll: () => viewActive });
  applier.setState(INITIAL_STATE);
  frames.tick();

  fakeWindow.scrollY = 1234;
  applier.setState(reduce(INITIAL_STATE, { query: 'caballero' }));
  frames.tick();
  fakeWindow.scrollY = 40; // el navegador recorta el scroll al encoger el contenido

  applier.setState(reduce(INITIAL_STATE, { query: 'hacha' }));
  frames.tick();
  assert.equal(fakeWindow.scrollY, 40, 'cambiar criterios activos no toca el scroll');

  applier.setState(INITIAL_STATE);
  frames.tick();
  assert.equal(fakeWindow.scrollY, 1234);

  fakeWindow.scrollY = 500;
  viewActive = false;
  applier.setState(reduce(INITIAL_STATE, { query: 'x' }));
  frames.tick();
  applier.setState(INITIAL_STATE);
  frames.tick();
  assert.equal(fakeWindow.scrollY, 500, 'fuera de la vista activa no se memoriza ni restaura');
});

test('sin canRestoreScroll nunca se escribe el scroll', () => {
  const cat = buildCatalog();
  const frames = makeRaf();
  const applier = createApplier(cat.root, frames);
  applier.setState(INITIAL_STATE);
  frames.tick();
  fakeWindow.scrollY = 999;
  applier.setState(reduce(INITIAL_STATE, { query: 'caballero' }));
  frames.tick();
  fakeWindow.scrollY = 10;
  applier.setState(INITIAL_STATE);
  frames.tick();
  assert.equal(fakeWindow.scrollY, 10);
});

test('invalidateIndex tras un re-render real recoge las tarjetas nuevas y aplica el estado vigente', () => {
  const cat = buildCatalog();
  const frames = makeRaf();
  const applier = createApplier(cat.root, frames);
  applier.setState(reduce(INITIAL_STATE, { query: 'nuevo' }));
  frames.tick();
  assert.equal(cat.featured.hidden, true);

  const nuevo = card({ name: 'Objeto Nuevo', types: ['Trajes'], price: 300 });
  const tile = new FakeElement('div');
  tile.appendChild(nuevo);
  cat.featured.children[1].appendChild(tile);

  applier.invalidateIndex();
  frames.tick();
  assert.equal(applier.getSummary().cards, 5);
  assert.equal(cat.featured.hidden, false);
  assert.equal(tile.hidden, false);
  assert.equal(cat.traje.parentElement.hidden, true);
});

test('destroy cancela el frame pendiente y flush ejecuta el trabajo de inmediato', () => {
  const cat = buildCatalog();
  const frames = makeRaf();
  const applier = createApplier(cat.root, frames);

  applier.setState(reduce(INITIAL_STATE, { query: 'caballero' }));
  assert.equal(frames.pending(), 1);
  applier.flush();
  assert.equal(frames.pending(), 0);
  assert.equal(cat.pico.parentElement.hidden, true);

  applier.setState(INITIAL_STATE);
  assert.equal(frames.pending(), 1);
  applier.destroy();
  assert.equal(frames.pending(), 0);
  frames.tick();
  assert.equal(cat.pico.parentElement.hidden, true, 'nada se aplica tras destroy');
});

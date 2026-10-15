'use strict';

(function () {
  const isNode = typeof module !== 'undefined' && !!module.exports;

  let api;
  if (isNode) {
    api = require('./section-backdrop.js')._pure;
  } else {
    api = typeof SectionBackdrop !== 'undefined' ? SectionBackdrop._pure : null;
  }

  function fail(message) {
    throw new Error(message);
  }

  function assertEqual(label, actual, expected) {
    if (actual !== expected) {
      fail(label + ': expected ' + expected + ' but got ' + actual);
    }
  }

  function assertIntent(label, actual, expected) {
    assertEqual(label + ' (mode)', actual.mode, expected.mode);
    assertEqual(label + ' (url)', actual.url, expected.url);
    assertEqual(label + ' (hold)', actual.hold, expected.hold);
  }

  const DEFAULT = '/u/bg/default.webp';
  const SLEEK = '/u/bg/sleek.webp';
  const GAMING = '/u/bg/gaming.webp';
  const PAGE = '/u/bg/page.webp';

  const CONFIG = {
    default: DEFAULT,
    page: PAGE,
    sections: [
      { key: 'sleeksectiontoday', name: 'Sleek Section Today', image: SLEEK },
      { key: 'section-random-offer', name: 'Oferta Aleatoria', image: GAMING },
      { key: 'broken', name: 'Sin imagen', image: '' },
      { key: 'gaming-legends', name: 'Gaming Legends', image: GAMING },
      null,
      'garbage'
    ]
  };

  function runTests() {
    if (!api) {
      fail('SectionBackdrop pure API not found');
    }

    const { normalizeKey, createRegistry, resolveLayout, resolveSectionElement, resolveIntent, imageSource, parseCssTime } = api;

    // normalizeKey: espejo de SectionBackgroundsRepository::normalizeKey
    assertEqual('normalizeKey lowercases', normalizeKey('SleekSectionToday'), 'sleeksectiontoday');
    assertEqual('normalizeKey collapses symbols', normalizeKey('  Gaming__Legends! '), 'gaming-legends');
    assertEqual('normalizeKey tolerates null', normalizeKey(null), '');
    assertEqual('normalizeKey tolerates numbers', normalizeKey(42), '42');

    // createRegistry: tolerante a configuración ausente o basura
    const empty = createRegistry(null);
    assertEqual('empty registry default', empty.defaultUrl, null);
    assertEqual('empty registry page', empty.pageUrl, null);
    assertEqual('empty registry byKey size', empty.byKey.size, 0);
    assertEqual('garbage registry', createRegistry('x').defaultUrl, null);
    assertEqual('registry frozen', Object.isFrozen(createRegistry({})), true);

    const registry = createRegistry(CONFIG);
    assertEqual('registry default', registry.defaultUrl, DEFAULT);
    assertEqual('registry page', registry.pageUrl, PAGE);
    assertEqual('registry skips entries without image', registry.byKey.has('broken'), false);
    assertEqual('registry ignores non-object entries', registry.byKey.size, 3);
    assertEqual('registry name index normalized', registry.byName.get('oferta-aleatoria'), GAMING);
    assertEqual('registry blank default → null', createRegistry({ default: '   ' }).defaultUrl, null);

    // resolveLayout: por id, por nombre, sin coincidencia
    assertEqual('layout by id', resolveLayout(registry, { id: 'SleekSectionToday', name: 'x' }), SLEEK);
    assertEqual('layout by id (symbols)', resolveLayout(registry, { id: 'Gaming__Legends' }), GAMING);
    assertEqual('layout by name fallback', resolveLayout(registry, { id: 'other', name: 'Gaming Legends' }), GAMING);
    assertEqual('layout unknown → null', resolveLayout(registry, { id: 'nope', name: 'nope' }), null);
    assertEqual('layout null → null', resolveLayout(registry, null), null);
    assertEqual('layout never returns default', resolveLayout(registry, { id: 'section-default-background' }), null);

    // resolveSectionElement: data-layout-id > id (virtual) > id sin prefijo > nombre
    const el = (id, dataset) => ({ id, dataset: dataset || {} });
    assertEqual('section by data-layout-id', resolveSectionElement(registry, el('section-x', { layoutId: 'SleekSectionToday' })), SLEEK);
    assertEqual('virtual section by id', resolveSectionElement(registry, el('section-random-offer')), GAMING);
    assertEqual('section by id without prefix', resolveSectionElement(registry, el('section-gaming-legends')), GAMING);
    assertEqual('section by title', resolveSectionElement(registry, el('section-zzz', { titleDesktop: 'Sleek Section Today' })), SLEEK);
    assertEqual('section unknown → null', resolveSectionElement(registry, el('section-zzz', { titleDesktop: 'Nada' })), null);
    assertEqual('section null → null', resolveSectionElement(registry, null), null);
    assertEqual('section without dataset', resolveSectionElement(registry, { id: 'section-random-offer' }), GAMING);

    // resolveIntent: política por vista
    const STORE = 'store', PAGE_MODE = 'page', DEF = 'default', NONE = 'none';
    assertIntent('store without section → default',
      resolveIntent(registry, { view: 'viaregalo', section: null, page: null }),
      { mode: STORE, url: DEFAULT, hold: false });
    assertIntent('store with section → section bg',
      resolveIntent(registry, { view: 'viaregalo', section: el('section-random-offer'), page: null }),
      { mode: STORE, url: GAMING, hold: false });
    assertIntent('store with unknown section → default',
      resolveIntent(registry, { view: 'viaregalo', section: el('section-unknown'), page: null }),
      { mode: STORE, url: DEFAULT, hold: false });
    assertIntent('store without any config → none url',
      resolveIntent(empty, { view: 'viaregalo', section: el('section-random-offer'), page: null }),
      { mode: STORE, url: null, hold: false });

    assertIntent('item without page → hold',
      resolveIntent(registry, { view: 'item', section: null, page: null }),
      { mode: PAGE_MODE, url: null, hold: true });
    assertIntent('item with explicit url',
      resolveIntent(registry, { view: 'item', section: null, page: { url: '/u/x.webp', layout: null } }),
      { mode: PAGE_MODE, url: '/u/x.webp', hold: false });
    assertIntent('item with layout only',
      resolveIntent(registry, { view: 'item', section: null, page: { url: null, layout: { id: 'SleekSectionToday' } } }),
      { mode: PAGE_MODE, url: SLEEK, hold: false });
    assertIntent('item without match → default',
      resolveIntent(registry, { view: 'item', section: null, page: { url: null, layout: { id: 'nope' } } }),
      { mode: PAGE_MODE, url: DEFAULT, hold: false });
    assertIntent('item ignores store section',
      resolveIntent(registry, { view: 'item', section: el('section-random-offer'), page: { url: null, layout: null } }),
      { mode: PAGE_MODE, url: DEFAULT, hold: false });

    for (const view of ['viacuenta', 'games', 'producto']) {
      assertIntent(view + ' → default',
        resolveIntent(registry, { view, section: el('section-random-offer'), page: { url: PAGE, layout: null } }),
        { mode: DEF, url: DEFAULT, hold: false });
    }
    assertIntent('default view without default → none url',
      resolveIntent(empty, { view: 'games', section: null, page: null }),
      { mode: DEF, url: null, hold: false });

    assertIntent('unknown view → none',
      resolveIntent(registry, { view: 'admin', section: el('section-random-offer'), page: { url: PAGE, layout: null } }),
      { mode: NONE, url: null, hold: false });
    assertIntent('null view → none',
      resolveIntent(registry, { view: null, section: null, page: null }),
      { mode: NONE, url: null, hold: false });

    // parseCssTime: valores de --section-backdrop-fade
    assertEqual('parse ms', parseCssTime(' 150ms'), 150);
    assertEqual('parse s', parseCssTime('0.32s'), 320);
    assertEqual('parse zero', parseCssTime('0ms'), 0);
    assertEqual('parse empty → NaN', Number.isNaN(parseCssTime('')), true);
    assertEqual('parse undefined → NaN', Number.isNaN(parseCssTime(undefined)), true);
    assertEqual('parse garbage → NaN', Number.isNaN(parseCssTime('fast')), true);

    // imageSource: paridad con SectionBackdropResolver::imageSource (PHP)
    const cdn = 'https://cdn2.unrealengine.com/bg%20image.png';
    const source = imageSource(cdn);
    assertEqual('imageSource src (1920w webp)',
      source.src, 'https://wsrv.nl/?url=' + encodeURIComponent(cdn) + '&w=1920&q=90&output=webp');
    assertEqual('imageSource srcset widths',
      source.srcset.split(', ').map((c) => c.split(' ')[1]).join(','), '640w,1080w,1920w,2560w');
    assertEqual('imageSource sizes', source.sizes, '(orientation: portrait) 200vw, 100vw');
    assertEqual('imageSource passthrough wsrv',
      JSON.stringify(imageSource('https://wsrv.nl/?url=x')), JSON.stringify({ src: 'https://wsrv.nl/?url=x', srcset: '', sizes: '' }));
    assertEqual('imageSource passthrough relative',
      JSON.stringify(imageSource('/img/a.png')), JSON.stringify({ src: '/img/a.png', srcset: '', sizes: '' }));
  }

  try {
    runTests();
    if (typeof console !== 'undefined' && console.log) {
      console.log('[section-backdrop.test] All tests passed');
    }
  } catch (err) {
    if (typeof console !== 'undefined' && console.error) {
      console.error('[section-backdrop.test] FAILED:', err && err.message ? err.message : err);
    }
    if (isNode) {
      process.exitCode = 1;
    }
  }
})();

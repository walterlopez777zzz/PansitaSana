/**
 * catalog-filter/index.js — Composición y ciclo de vida del buscador y los
 * filtros de la tienda.
 *
 *   store (state.js)  ──►  appliers (apply.js)  ──►  atributos `hidden`
 *      ▲                        ▲
 *      │                        └── `banano:catalog-change` (renders reales)
 *      └── search-ui.js / filter-ui.js (única vía de entrada de usuario)
 *
 * Un applier por catálogo: Vía Regalo (búsqueda + tipos + precio) y Vía
 * Cuenta (sólo búsqueda). Ambos comparten el store y se actualizan en el
 * mismo frame. Ninguna ruta reconstruye tarjetas ni invoca al router.
 */

import { createFilterStore } from './state.js';
import { createCatalogApplier } from './apply.js';
import { mountSearchUi } from './search-ui.js';
import { mountFilterUi } from './filter-ui.js';

const CARD_SELECTOR = '.item-card-wrapper';
const SECTION_SELECTOR = ':scope > [data-catalog-section]';

/**
 * @param {Document} [doc]
 * @returns {{ destroy(): void } | null}
 */
export function mountCatalogFilter(doc = document) {
  const storeRoot = doc.getElementById('viaregalo-content');
  if (!storeRoot) return null;

  const store = createFilterStore();
  const controller = new AbortController();
  const { signal } = controller;
  const isViewActive = (view) => doc.body.dataset.view === view;

  /** Los enlaces de navegación (lateral y desplegable) siguen a su sección. */
  const syncSectionLinks = (section, hidden) => {
    if (!section.id) return;
    for (const link of doc.querySelectorAll(`[data-section="${CSS.escape(section.id)}"]`)) {
      (link.closest('li') ?? link).hidden = hidden;
    }
  };

  const appliers = new Map();
  appliers.set(
    'store',
    createCatalogApplier({
      root: storeRoot,
      sectionSelector: SECTION_SELECTOR,
      cardSelector: CARD_SELECTOR,
      trackSelector: '.shop-carousel__track',
      isAuxSection: (section) => 'catalogAux' in section.dataset,
      canRestoreScroll: () => isViewActive('viaregalo'),
      onSectionHidden: syncSectionLinks,
    }),
  );

  const viaCuentaRoot = doc.getElementById('view-viacuenta');
  if (viaCuentaRoot) {
    appliers.set(
      'viacuenta',
      createCatalogApplier({
        root: viaCuentaRoot,
        sectionSelector: SECTION_SELECTOR,
        cardSelector: CARD_SELECTOR,
        select: (state) => ({ query: state.query }),
        canRestoreScroll: () => isViewActive('viacuenta'),
      }),
    );
  }

  const unsubscribe = store.subscribe((state) => {
    for (const applier of appliers.values()) applier.setState(state);
  });

  doc.addEventListener(
    'banano:catalog-change',
    (event) => appliers.get(event.detail?.scope)?.invalidateIndex(),
    { signal },
  );

  const searchUi = mountSearchUi({ store, doc });
  const filterUi = mountFilterUi({ store, applier: appliers.get('store'), doc });

  for (const applier of appliers.values()) {
    applier.invalidateIndex();
    applier.setState(store.get());
  }

  return {
    destroy() {
      controller.abort();
      searchUi?.destroy();
      filterUi?.destroy();
      unsubscribe();
      for (const applier of appliers.values()) applier.destroy();
      appliers.clear();
    },
  };
}

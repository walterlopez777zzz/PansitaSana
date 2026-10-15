import { computeCartTotal } from "../../../domain/pricing/cart-totals.js";
import { normalizeCollageItem } from "../../../domain/collage/collage-item.js";
import { effectiveCardId } from "../../../domain/cards/card-index.js";
import { cardSelectionId } from "../../../domain/cards/card-selection.js";
import { normalizeMode } from "../../../domain/mode-utils.js";
import { resolveCardSubject } from "../../../rendering/painters/card-subject.js";

/**
 * RN-8: aviso discreto en el panel del Carrito con las tarjetas sin precio
 * válido. Deriva `unpricedCardIds` del mismo `computeCartTotal` que el canvas,
 * por lo que nunca puede discrepar del total pintado. Cada tarjeta se lista
 * como botón que la selecciona (`card:<cardId>`) para corregirla al momento.
 *
 * Re-render solo cuando cambia alguna de sus entradas (modo, ítems, tienda,
 * conversión o descuento); el DOM se reconstruye únicamente si cambia la
 * lista de ids sin precio.
 */

const EMPTY_ITEMS = Object.freeze([]);

function readInputs(state) {
  return {
    mode: normalizeMode(state?.ui?.mode),
    items: Array.isArray(state?.cart?.items) ? state.cart.items : EMPTY_ITEMS,
    shopItems: Array.isArray(state?.shop?.items) ? state.shop.items : EMPTY_ITEMS,
    conversionRate: state?.template?.conversionRate,
    globalDiscount: state?.template?.collageDiscount ?? state?.template?.discount,
  };
}

function sameInputs(a, b) {
  return Boolean(a) && Boolean(b)
    && a.mode === b.mode
    && a.items === b.items
    && a.shopItems === b.shopItems
    && a.conversionRate === b.conversionRate
    && a.globalDiscount === b.globalDiscount;
}

function sameIds(a, b) {
  if (a.length !== b.length) return false;
  for (let i = 0; i < a.length; i += 1) if (a[i] !== b[i]) return false;
  return true;
}

function titlesByCardId(items, shopItems) {
  const byOfferId = new Map(shopItems.map((item) => [String(item?.offerId || ""), item]));
  const titles = new Map();
  items.forEach((rawItem, index) => {
    const normalized = normalizeCollageItem(rawItem);
    if (!normalized) return;
    titles.set(effectiveCardId(rawItem, index), resolveCardSubject(normalized, byOfferId).displayName);
  });
  return titles;
}

/**
 * @param {{ store: object, hintEl: HTMLElement|null }} deps
 */
export function createCartUnpricedController({ store, hintEl }) {
  if (!store || !hintEl) return Object.freeze({ dispose: () => {} });

  const abort = new AbortController();
  let lastInputs = null;
  let lastIds = [];

  const renderList = (ids, titles) => {
    const count = ids.length;
    const intro = document.createElement("span");
    intro.className = "cart-unpriced__intro";
    intro.textContent = count === 1
      ? "1 tarjeta sin precio válido no suma al total:"
      : `${count} tarjetas sin precio válido no suman al total:`;

    const list = document.createElement("ul");
    list.className = "cart-unpriced__list";
    for (const cardId of ids) {
      const item = document.createElement("li");
      const button = document.createElement("button");
      button.type = "button";
      button.className = "cart-unpriced__card";
      button.dataset.cardId = cardId;
      const title = titles.get(cardId) || "Tarjeta";
      button.textContent = title;
      button.title = `Seleccionar «${title}»`;
      item.append(button);
      list.append(item);
    }
    hintEl.replaceChildren(intro, list);
  };

  const render = () => {
    const state = store.getState();
    const inputs = readInputs(state);
    if (sameInputs(inputs, lastInputs)) return;
    lastInputs = inputs;

    if (inputs.mode !== "cart" || inputs.items.length === 0) {
      if (!hintEl.hidden) {
        hintEl.hidden = true;
        hintEl.replaceChildren();
        lastIds = [];
      }
      return;
    }

    const { unpricedCardIds } = computeCartTotal(inputs);
    if (unpricedCardIds.length === 0) {
      if (!hintEl.hidden) {
        hintEl.hidden = true;
        hintEl.replaceChildren();
      }
      lastIds = [];
      return;
    }

    if (hintEl.hidden || !sameIds(unpricedCardIds, lastIds)) {
      renderList(unpricedCardIds, titlesByCardId(inputs.items, inputs.shopItems));
      lastIds = unpricedCardIds;
      hintEl.hidden = false;
    }
  };

  hintEl.addEventListener("click", (event) => {
    const button = event.target instanceof Element ? event.target.closest("button[data-card-id]") : null;
    const cardId = button?.dataset.cardId;
    if (!cardId) return;
    store.update("ui.selectedElement", cardSelectionId(cardId));
  }, { signal: abort.signal });

  const unsubscribe = store.subscribe(render);
  render();

  return Object.freeze({
    refresh: render,
    dispose() {
      unsubscribe();
      abort.abort();
      hintEl.hidden = true;
      hintEl.replaceChildren();
    },
  });
}

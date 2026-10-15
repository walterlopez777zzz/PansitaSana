import { isGridMode, normalizeMode } from "../../../domain/mode-utils.js";
import { addBatch } from "../../../domain/cards/card-commands.js";
import {
  buildCatalogEntries,
  buildCatalogIndex,
  resolveOrderQuery,
  searchCatalog,
} from "../../../domain/cards/catalog-entries.js";
import {
  addCatalogEntry,
  addFreeCard,
  countBasketCards,
  coverImageUrls,
  createEmptyBasket,
  buildBatchEntries,
  removeLine,
  setLineQuantity,
} from "../../../domain/cards/add-cards-basket.js";
import { looksLikeOrderText, parseOrderText } from "../../../domain/cards/order-paste-parser.js";
import { createFreeCardDraft, isLikelyImageUrl } from "../../../domain/cards/card-drafts.js";
import {
  selectOffersMode,
  selectViaCuentaCatalog,
  selectViaCuentaProducts,
} from "../../../domain/via-cuenta/catalog-state.js";
import { parseCollageSelection } from "../../selection/selection-ids.js";
import { attachDialogDismiss } from "../../utils/dialog-behavior.js";
import {
  renderBasket,
  renderCatalogStatus,
  renderPresets,
  renderResults,
  updateActiveOption,
} from "./add-cards-view.js";

/**
 * Controlador del diálogo «Añadir tarjetas» (RN-1…RN-9).
 *
 * Responsabilidades:
 * - ciclo de vida del `<dialog>` nativo (`showModal()` aporta focus trap y
 *   cierre con Escape; aquí solo se decide *cuándo* se puede cerrar);
 * - estado local del diálogo (query, resultado, opción activa, cesta) con
 *   flujos unívocos: cada acción muta el estado y re-renderiza la lista
 *   afectada, nada más;
 * - suscripción al store únicamente mientras el diálogo está abierto;
 * - inserción en lote por `addBatch` tras medir (con timeout) las proporciones
 *   de las imágenes cover.
 *
 * Determinismo asíncrono: `openToken` se incrementa en cada apertura/cierre y
 * todo trabajo diferido lo compara antes de tocar DOM o store. Mientras hay
 * una inserción en curso, el diálogo rechaza cerrarse para que la acción
 * termine exactamente una vez.
 */

const DIALOG_PART_IDS = Object.freeze({
  formEl: "add-cards-form",
  closeEl: "add-cards-close",
  searchEl: "add-cards-search",
  catalogStatusEl: "add-cards-catalog-status",
  offersModeEl: "add-cards-offers-mode",
  resultsEl: "add-cards-results",
  basketEl: "add-cards-basket",
  basketCountEl: "add-cards-basket-count",
  freeDetailsEl: "add-cards-free",
  freeImageEl: "add-cards-free-image",
  freeTitleEl: "add-cards-free-title",
  freePriceEl: "add-cards-free-price",
  freeErrorEl: "add-cards-free-error",
  freeAddEl: "add-cards-free-add",
  freeSaveEl: "add-cards-free-save",
  presetsEl: "add-cards-presets",
  feedbackEl: "add-cards-feedback",
  cancelEl: "add-cards-cancel",
  submitEl: "add-cards-submit",
});

const EMPTY_RESULT = Object.freeze({
  entries: Object.freeze([]),
  total: 0,
  highlightFor: () => null,
  matchFor: () => null,
});

const NOOP_CONTROLLER = Object.freeze({
  open: () => {},
  close: () => {},
  isOpen: () => false,
  dispose: () => {},
});

function queryDialogParts(dialogEl) {
  const parts = {};
  for (const [name, id] of Object.entries(DIALOG_PART_IDS)) {
    const node = dialogEl.querySelector(`#${id}`);
    if (!node) return null;
    parts[name] = node;
  }
  return Object.freeze(parts);
}

function isPrimaryModifier(event) {
  return event.ctrlKey || event.metaKey;
}

function moveIndex(current, delta, length) {
  if (length <= 0) return -1;
  if (current < 0) return delta > 0 ? 0 : length - 1;
  return (current + delta + length) % length;
}

/**
 * @param {{
 *   store: object,
 *   history?: object,
 *   dialogEl: HTMLDialogElement|null,
 *   triggerEl?: HTMLButtonElement|null,
 *   catalogService: ReturnType<import("../../../services/via-cuenta-catalog-service.js").createViaCuentaCatalogService>,
 *   imageAspectService: ReturnType<import("../../../services/image-aspect-service.js").createImageAspectService>,
 *   presetsStore: ReturnType<import("./free-card-presets-store.js").createFreeCardPresetsStore>,
 *   setStatus?: (message: string) => void,
 * }} deps
 */
export function createAddCardsController({
  store,
  history = null,
  dialogEl,
  triggerEl = null,
  catalogService,
  imageAspectService,
  presetsStore,
  setStatus = () => {},
}) {
  if (!store || !dialogEl || typeof dialogEl.showModal !== "function") return NOOP_CONTROLLER;
  const parts = queryDialogParts(dialogEl);
  if (!parts) return NOOP_CONTROLLER;

  const abort = new AbortController();
  const { signal } = abort;
  const on = (target, type, handler, options) =>
    target.addEventListener(type, handler, options ? { ...options, signal } : { signal });

  // ── Estado local ─────────────────────────────────────────────────────
  let openToken = 0;
  let submitting = false;
  let insertAtEnd = false;
  let query = "";
  let result = EMPTY_RESULT;
  let activeIndex = -1;
  let basket = createEmptyBasket();
  let unsubscribeStore = null;
  let searchFrame = 0;

  /** Catálogo memoizado por referencias de entrada. */
  const catalogMemo = { shopItems: null, products: null, offersMode: null, value: null };
  const catalogFor = (state) => {
    const shopItems = Array.isArray(state?.shop?.items) ? state.shop.items : null;
    const products = selectViaCuentaProducts(state);
    const offersMode = selectOffersMode(state);
    if (
      catalogMemo.value
      && catalogMemo.shopItems === shopItems
      && catalogMemo.products === products
      && catalogMemo.offersMode === offersMode
    ) return catalogMemo.value;
    const entries = buildCatalogEntries({ shopItems: shopItems || [], viaCuentaProducts: products, offersMode });
    catalogMemo.shopItems = shopItems;
    catalogMemo.products = products;
    catalogMemo.offersMode = offersMode;
    catalogMemo.value = Object.freeze({ entries, index: buildCatalogIndex(entries) });
    return catalogMemo.value;
  };

  const currentMode = () => normalizeMode(store.getState()?.ui?.mode);

  // ── Render ───────────────────────────────────────────────────────────
  const setFeedback = (message, tone = "info") => {
    parts.feedbackEl.textContent = message || "";
    parts.feedbackEl.dataset.tone = tone;
  };

  const setActiveIndex = (next) => {
    const bounded = next >= 0 && next < result.entries.length ? next : -1;
    const activeId = updateActiveOption(parts.resultsEl, activeIndex, bounded);
    activeIndex = bounded;
    if (activeId) parts.searchEl.setAttribute("aria-activedescendant", activeId);
    else parts.searchEl.removeAttribute("aria-activedescendant");
  };

  const renderStatus = (state) => {
    const viaCuenta = selectViaCuentaCatalog(state);
    renderCatalogStatus(parts.catalogStatusEl, {
      total: result.total,
      viaCuentaStatus: viaCuenta.status,
      viaCuentaError: viaCuenta.error,
    });
    parts.offersModeEl.checked = selectOffersMode(state);
  };

  const runSearch = () => {
    const state = store.getState();
    result = searchCatalog(catalogFor(state), query);
    const previousActive = activeIndex;
    activeIndex = -1;
    renderResults(parts.resultsEl, result, -1);
    setActiveIndex(result.entries.length ? Math.min(Math.max(previousActive, 0), result.entries.length - 1) : -1);
    renderStatus(state);
  };

  const scheduleSearch = () => {
    if (searchFrame) return;
    searchFrame = requestAnimationFrame(() => {
      searchFrame = 0;
      if (dialogEl.open) runSearch();
    });
  };

  const renderBasketState = () => {
    renderBasket(parts.basketEl, basket);
    const count = countBasketCards(basket);
    parts.basketCountEl.value = String(count);
    parts.basketCountEl.dataset.empty = count === 0 ? "true" : "false";
    parts.submitEl.disabled = submitting || count === 0;
    parts.submitEl.textContent = count > 0 ? `Añadir ${count} ${count === 1 ? "tarjeta" : "tarjetas"}` : "Añadir";
  };

  const renderPresetList = () => {
    renderPresets(parts.presetsEl, presetsStore.list());
  };

  const renderSubmitting = () => {
    parts.submitEl.classList.toggle("add-cards__submit-busy", submitting);
    parts.submitEl.setAttribute("aria-busy", submitting ? "true" : "false");
    parts.submitEl.disabled = submitting || countBasketCards(basket) === 0;
  };

  const renderTrigger = () => {
    if (triggerEl) triggerEl.hidden = !isGridMode(currentMode());
  };

  // ── Mutaciones de la cesta ───────────────────────────────────────────
  const updateBasket = (next) => {
    if (next === basket) return;
    basket = next;
    renderBasketState();
  };

  const addEntryByKey = (key, quantity = 1) => {
    const entry = result.entries.find((candidate) => candidate.key === key);
    if (!entry) return;
    updateBasket(addCatalogEntry(basket, entry, quantity));
    setFeedback(`${entry.displayName} añadida a la cesta.`);
  };

  const addActiveEntry = () => {
    const entry = result.entries[activeIndex] || result.entries[0];
    if (!entry) return;
    updateBasket(addCatalogEntry(basket, entry, 1));
    setFeedback(`${entry.displayName} añadida a la cesta.`);
    if (query) {
      query = "";
      parts.searchEl.value = "";
      activeIndex = -1;
      runSearch();
    }
  };

  const readFreeInput = () => ({
    imageUrl: parts.freeImageEl.value,
    title: parts.freeTitleEl.value,
    priceText: parts.freePriceEl.value,
  });

  const showFreeError = (message, focusEl = null) => {
    parts.freeErrorEl.textContent = message || "";
    parts.freeDetailsEl.open = true;
    focusEl?.focus();
  };

  const addFreeCardFromForm = () => {
    const input = readFreeInput();
    const { error } = createFreeCardDraft(input);
    if (error) {
      const focusTarget = !input.imageUrl.trim() || !isLikelyImageUrl(input.imageUrl.trim())
        ? parts.freeImageEl
        : (!input.title.trim() ? parts.freeTitleEl : parts.freePriceEl);
      showFreeError(error, focusTarget);
      return;
    }
    parts.freeErrorEl.textContent = "";
    updateBasket(addFreeCard(basket, input, 1));
    setFeedback(`${input.title.trim()} añadida a la cesta.`);
  };

  const savePresetFromForm = () => {
    const input = readFreeInput();
    const imageUrl = input.imageUrl.trim();
    if (!imageUrl || !isLikelyImageUrl(imageUrl)) {
      showFreeError("Indica una URL de imagen válida para guardar el preset.", parts.freeImageEl);
      return;
    }
    if (!input.title.trim()) {
      showFreeError("Indica un título para guardar el preset.", parts.freeTitleEl);
      return;
    }
    parts.freeErrorEl.textContent = "";
    const saved = presetsStore.save(input);
    renderPresetList();
    setFeedback(saved ? `Preset «${saved.title}» guardado.` : "No se pudo guardar el preset.", saved ? "info" : "error");
  };

  const usePreset = (presetId) => {
    const preset = presetsStore.list().find((candidate) => candidate.id === presetId);
    if (!preset) return;
    parts.freeImageEl.value = preset.imageUrl;
    parts.freeTitleEl.value = preset.title;
    parts.freePriceEl.value = preset.priceText;
    parts.freeErrorEl.textContent = "";
    if (createFreeCardDraft(preset).draft) {
      updateBasket(addFreeCard(basket, preset, 1));
      setFeedback(`${preset.title} añadida a la cesta.`);
    } else {
      parts.freeDetailsEl.open = true;
      parts.freePriceEl.focus();
    }
  };

  /** RN-9: pegado de pedidos → una línea por tarjeta con cantidad. */
  const importOrder = (text) => {
    const lines = parseOrderText(text);
    if (lines.length === 0) return false;
    const catalog = catalogFor(store.getState());
    const unresolved = [];
    let next = basket;
    for (const line of lines) {
      const entry = resolveOrderQuery(catalog, line.query);
      if (entry) next = addCatalogEntry(next, entry, line.quantity);
      else unresolved.push(line.raw);
    }
    updateBasket(next);
    if (unresolved.length === 0) {
      setFeedback(`${lines.length} ${lines.length === 1 ? "línea importada" : "líneas importadas"} del pedido.`);
      query = "";
      parts.searchEl.value = "";
    } else {
      setFeedback(
        `${lines.length - unresolved.length} de ${lines.length} líneas importadas. Sin coincidencia: ${unresolved.join(" · ")}`,
        "error",
      );
      query = unresolved[0];
      parts.searchEl.value = query;
    }
    activeIndex = -1;
    runSearch();
    return true;
  };

  // ── Inserción ────────────────────────────────────────────────────────
  const commit = async () => {
    if (submitting || countBasketCards(basket) === 0) return;
    const mode = currentMode();
    if (!isGridMode(mode)) {
      setFeedback("Cambia a Collage o Carrito para insertar tarjetas.", "error");
      return;
    }
    const token = openToken;
    submitting = true;
    renderSubmitting();
    setFeedback("Preparando tarjetas…");

    let aspectRatioFor = () => null;
    try {
      aspectRatioFor = await imageAspectService.measureAll(coverImageUrls(basket));
    } catch {
      // Sin medidas se usa la proporción por defecto de cada fuente.
    }
    if (token !== openToken) return;

    const state = store.getState();
    const { entries, errors } = buildBatchEntries(basket, {
      offersMode: selectOffersMode(state),
      aspectRatioFor,
    });
    if (entries.length === 0) {
      submitting = false;
      renderSubmitting();
      setFeedback(errors[0] || "No hay tarjetas válidas en la cesta.", "error");
      return;
    }

    const anchorCardId = insertAtEnd
      ? null
      : parseCollageSelection(state?.ui?.selectedElement, state?.[mode]?.items)?.cardId ?? null;
    const created = addBatch({ store, history }, mode, entries, { anchorCardId, selectFirst: true });
    submitting = false;
    const summary = `${created.length} ${created.length === 1 ? "tarjeta añadida" : "tarjetas añadidas"}`;
    setStatus(errors.length ? `${summary} · ${errors.length} con error.` : `${summary}.`);
    dialogEl.close();
  };

  // ── Ciclo de vida ────────────────────────────────────────────────────
  const resetState = () => {
    query = "";
    result = EMPTY_RESULT;
    activeIndex = -1;
    basket = createEmptyBasket();
    submitting = false;
    parts.searchEl.value = "";
    parts.searchEl.removeAttribute("aria-activedescendant");
    parts.freeErrorEl.textContent = "";
    setFeedback("");
  };

  const handleClosed = () => {
    openToken += 1;
    unsubscribeStore?.();
    unsubscribeStore = null;
    if (searchFrame) {
      cancelAnimationFrame(searchFrame);
      searchFrame = 0;
    }
    resetState();
  };

  const subscribeWhileOpen = () => {
    let lastCatalog = catalogFor(store.getState());
    let lastViaCuenta = selectViaCuentaCatalog(store.getState());
    unsubscribeStore = store.subscribe(() => {
      if (!dialogEl.open) return;
      const state = store.getState();
      const nextCatalog = catalogFor(state);
      const nextViaCuenta = selectViaCuentaCatalog(state);
      if (nextCatalog !== lastCatalog) {
        lastCatalog = nextCatalog;
        lastViaCuenta = nextViaCuenta;
        scheduleSearch();
      } else if (nextViaCuenta !== lastViaCuenta) {
        lastViaCuenta = nextViaCuenta;
        renderStatus(state);
      }
    });
  };

  /**
   * @param {{ anchor?: "selection"|"end" }} [options]
   *   `selection` inserta tras la tarjeta seleccionada (si la hay);
   *   `end` (tarjeta fantasma) siempre añade al final.
   */
  const open = ({ anchor = "selection" } = {}) => {
    if (!isGridMode(currentMode())) {
      setStatus("Cambia a Collage o Carrito para añadir tarjetas.");
      return;
    }
    insertAtEnd = anchor === "end";
    if (dialogEl.open) {
      parts.searchEl.focus();
      return;
    }
    openToken += 1;
    resetState();
    renderBasketState();
    renderPresetList();
    subscribeWhileOpen();
    catalogService.ensureLoaded().catch(() => {});
    runSearch();
    dialogEl.showModal();
    parts.searchEl.focus();
  };

  const requestClose = () => {
    if (submitting || !dialogEl.open) return;
    dialogEl.close();
  };

  // ── Listeners (todos con `signal`; el dismiss se destruye en dispose) ──
  const dismiss = attachDialogDismiss(dialogEl, requestClose);
  on(dialogEl, "close", handleClosed);
  if (triggerEl) on(triggerEl, "click", () => open());
  on(parts.closeEl, "click", requestClose);
  on(parts.cancelEl, "click", requestClose);

  on(parts.formEl, "submit", (event) => {
    event.preventDefault();
    commit();
  });

  on(parts.formEl, "keydown", (event) => {
    if (event.key !== "Enter" || event.isComposing) return;
    if (isPrimaryModifier(event)) {
      event.preventDefault();
      commit();
      return;
    }
    const target = event.target;
    if (target === parts.searchEl) {
      event.preventDefault();
      addActiveEntry();
    } else if (target === parts.freeImageEl || target === parts.freeTitleEl || target === parts.freePriceEl) {
      event.preventDefault();
      addFreeCardFromForm();
    } else if (target instanceof HTMLInputElement) {
      event.preventDefault();
    }
  });

  on(parts.searchEl, "input", () => {
    query = parts.searchEl.value;
    activeIndex = -1;
    scheduleSearch();
  });

  on(parts.searchEl, "keydown", (event) => {
    if (event.isComposing) return;
    const length = result.entries.length;
    switch (event.key) {
      case "ArrowDown":
        event.preventDefault();
        setActiveIndex(moveIndex(activeIndex, 1, length));
        break;
      case "ArrowUp":
        event.preventDefault();
        setActiveIndex(moveIndex(activeIndex, -1, length));
        break;
      case "Home":
        if (!query) {
          event.preventDefault();
          setActiveIndex(length ? 0 : -1);
        }
        break;
      case "End":
        if (!query) {
          event.preventDefault();
          setActiveIndex(length - 1);
        }
        break;
      default:
        break;
    }
  });

  on(parts.searchEl, "paste", (event) => {
    const text = event.clipboardData?.getData("text/plain") || "";
    if (!looksLikeOrderText(text)) return;
    event.preventDefault();
    importOrder(text);
  });

  on(parts.offersModeEl, "change", () => {
    catalogService.setOffersMode(parts.offersModeEl.checked);
  });

  on(parts.catalogStatusEl, "click", (event) => {
    if (event.target instanceof HTMLElement && event.target.dataset.action === "retry") {
      catalogService.reload().catch(() => {});
    }
  });

  // Mantiene el foco en el buscador al pulsar resultados con el ratón.
  on(parts.resultsEl, "pointerdown", (event) => {
    if (event.target instanceof Element && event.target.closest(".add-cards__option")) event.preventDefault();
  });

  on(parts.resultsEl, "click", (event) => {
    const option = event.target instanceof Element ? event.target.closest(".add-cards__option") : null;
    if (!option) return;
    addEntryByKey(option.dataset.key, 1);
  });

  on(parts.basketEl, "click", (event) => {
    const button = event.target instanceof Element ? event.target.closest("button[data-action]") : null;
    const line = button?.closest(".add-cards__line");
    if (!button || !line) return;
    const key = line.dataset.key;
    const current = basket.lines.find((candidate) => candidate.key === key);
    if (!current) return;
    switch (button.dataset.action) {
      case "inc":
        updateBasket(setLineQuantity(basket, key, current.quantity + 1));
        break;
      case "dec":
        updateBasket(setLineQuantity(basket, key, current.quantity - 1));
        break;
      case "remove":
        updateBasket(removeLine(basket, key));
        break;
      default:
        break;
    }
  });

  on(parts.basketEl, "change", (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.dataset.action !== "qty") return;
    const key = input.closest(".add-cards__line")?.dataset.key;
    if (!key) return;
    updateBasket(setLineQuantity(basket, key, Number.parseInt(input.value, 10)));
  });

  on(parts.freeAddEl, "click", addFreeCardFromForm);
  on(parts.freeSaveEl, "click", savePresetFromForm);

  on(parts.presetsEl, "click", (event) => {
    const button = event.target instanceof Element ? event.target.closest("button[data-action]") : null;
    const item = button?.closest(".add-cards__preset-item");
    if (!button || !item) return;
    if (button.dataset.action === "use-preset") usePreset(item.dataset.presetId);
    else if (button.dataset.action === "remove-preset") {
      presetsStore.remove(item.dataset.presetId);
      renderPresetList();
    }
  });

  // Visibilidad del disparador según el modo (suscripción permanente y barata).
  let lastMode = currentMode();
  renderTrigger();
  const unsubscribeMode = store.subscribe(() => {
    const mode = currentMode();
    if (mode === lastMode) return;
    lastMode = mode;
    renderTrigger();
  });

  return Object.freeze({
    open,
    close: requestClose,
    isOpen: () => dialogEl.open,
    dispose() {
      if (dialogEl.open) dialogEl.close();
      handleClosed();
      unsubscribeMode();
      dismiss.destroy();
      abort.abort();
    },
  });
}

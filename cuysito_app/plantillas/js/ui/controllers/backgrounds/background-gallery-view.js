/**
 * Background Gallery View
 *
 * Pure view for the background picker: builds and maintains one card per
 * background, keeps the "solid colour" card and the "create" button, and
 * applies the name filter.
 *
 * The view never touches the store. It receives data via `render()` and a
 * query via `setQuery()`, and reports user intent through callbacks.
 *
 * Performance notes:
 *   - Cards are cached by background key (`id`, else `url`) and reused across
 *     renders, so thumbnails are not re-downloaded and `<img>` nodes are not
 *     recreated when the list, selection or filter changes.
 *   - Filtering toggles `hidden` and reorders children only when the order
 *     actually changed; it is coalesced to one `requestAnimationFrame`.
 */

import {
  createBackgroundSearchIndex,
  searchBackgrounds,
} from "../../../domain/backgrounds/background-search.js";

const SOLID_CARD_KEY = "__solid__";
const SOLID_CARD_NAME = "Color Sólido";
const UNNAMED_LABEL = "(Sin nombre)";
const DEFAULT_SOLID_COLOR = "#333335";
const THUMBNAIL_OPTIONS = Object.freeze({ w: 320, q: 85, output: "webp" });

const LOAD_STATE = Object.freeze({ LOADING: "loading", LOADED: "loaded", ERROR: "error" });

function noop() {}

function createElement(tag, className, textContent) {
  const element = document.createElement(tag);
  if (className) element.className = className;
  if (textContent !== undefined) element.textContent = textContent;
  return element;
}

function markSelected(rootEl, thumbEl, selected) {
  if (selected) rootEl.dataset.selected = "true";
  else delete rootEl.dataset.selected;
  if (!thumbEl) return;
  if (selected) thumbEl.setAttribute("aria-current", "true");
  else thumbEl.removeAttribute("aria-current");
}

function setAriaLabel(element, label) {
  if (element instanceof Element && element.getAttribute("aria-label") !== label) {
    element.setAttribute("aria-label", label);
  }
}

function createActionButton({ ariaLabel, svg, danger = false, onClick }) {
  const button = createElement(
    "button",
    danger ? "collage-bg-action collage-bg-action--danger" : "collage-bg-action",
  );
  button.type = "button";
  button.setAttribute("aria-label", ariaLabel);
  button.innerHTML = svg || "";
  button.addEventListener("click", (event) => {
    event.preventDefault();
    onClick();
  });
  return button;
}

/**
 * Thumbnail with loading/error placeholders.
 *
 * Construction only builds inert nodes; nothing loads and `onStateChange`
 * is never invoked until `load(url)` is called. This lets the owner finish
 * assembling its own record before any (possibly synchronous, e.g. cached
 * image) state transition reaches it.
 */
function createThumbnail({ alt, brokenImageSvg, onStateChange = noop }) {
  const image = createElement("img", "collage-bg-card__img");
  image.crossOrigin = "anonymous";
  image.alt = alt;
  image.hidden = true;

  const placeholder = createElement("div", "collage-bg-card__placeholder");
  const loadingLabel = createElement(
    "div",
    "collage-bg-placeholder__label collage-bg-placeholder__label--loading",
    "Cargando…",
  );
  const errorBox = createElement("div", "collage-bg-placeholder__error");
  const errorIcon = createElement("div");
  errorIcon.innerHTML = brokenImageSvg || "";
  const errorLabel = createElement(
    "div",
    "collage-bg-placeholder__label collage-bg-placeholder__label--error",
    "No disponible",
  );
  errorBox.append(errorIcon, errorLabel);
  placeholder.append(loadingLabel, errorBox);

  let state = null;
  const transition = (next) => {
    if (next === state) return;
    state = next;
    const loaded = next === LOAD_STATE.LOADED;
    image.hidden = !loaded;
    placeholder.hidden = loaded;
    placeholder.dataset.state = next;
    loadingLabel.hidden = next !== LOAD_STATE.LOADING;
    errorBox.hidden = next !== LOAD_STATE.ERROR;
    onStateChange(next);
  };
  const settle = () => transition(image.naturalWidth > 0 ? LOAD_STATE.LOADED : LOAD_STATE.ERROR);

  image.addEventListener("load", settle);
  image.addEventListener("error", () => transition(LOAD_STATE.ERROR));

  return Object.freeze({
    image,
    placeholder,
    /** Starts loading `url`; an empty URL settles straight into the error state. */
    load(url) {
      if (!url) {
        transition(LOAD_STATE.ERROR);
        return;
      }
      transition(LOAD_STATE.LOADING);
      image.src = url;
      if (image.complete) settle();
    },
  });
}

/**
 * @param {object} options
 * @param {HTMLElement} options.galleryEl
 * @param {HTMLElement} [options.emptyStateEl]
 * @param {(url: string, opts: object) => string} [options.optimizeImageUrl]
 * @param {(hex: string) => string} [options.normalizeHex]
 * @param {{ plus?: string, pencil?: string, trash?: string, brokenImage?: string }} [options.icons]
 * @param {(url: string) => void} [options.onSelectBackground]
 * @param {() => void} [options.onSelectSolid]
 * @param {() => void} [options.onCreate]
 * @param {(background: object) => void} [options.onEdit]
 * @param {(background: object) => void} [options.onDelete]
 * @param {(summary: { total: number, visible: number, query: string }) => void} [options.onFilterChange]
 */
export function createBackgroundGalleryView({
  galleryEl,
  emptyStateEl = null,
  optimizeImageUrl,
  normalizeHex,
  icons = {},
  onSelectBackground = noop,
  onSelectSolid = noop,
  onCreate = noop,
  onEdit = noop,
  onDelete = noop,
  onFilterChange = noop,
} = {}) {
  if (!galleryEl) {
    return Object.freeze({ showLoading: noop, render: noop, setQuery: noop, destroy: noop });
  }

  const toHex = typeof normalizeHex === "function" ? normalizeHex : (value) => value;
  const toThumbnailUrl = (url) =>
    url.startsWith("data:") || url.startsWith("blob:") || typeof optimizeImageUrl !== "function"
      ? url
      : optimizeImageUrl(url, THUMBNAIL_OPTIONS) || url;

  /* ── Static nodes ───────────────────────────────────────────────── */

  const createButton = createElement("button", "collage-bg-create-btn");
  createButton.type = "button";
  createButton.setAttribute("aria-label", "Crear nuevo fondo");
  const createIcon = createElement("span", "collage-bg-create-btn__icon");
  createIcon.innerHTML = icons.plus || "";
  createButton.append(createIcon, createElement("span", "", "Crear Nuevo Fondo"));
  createButton.addEventListener("click", () => onCreate());

  const gridEl = createElement("div", "collage-bg-grid");
  gridEl.setAttribute("role", "list");
  gridEl.setAttribute("aria-label", "Fondos disponibles");

  const solidCardEl = createElement("div", "collage-bg-card collage-bg-card--solid");
  solidCardEl.setAttribute("role", "listitem");
  const solidThumbEl = createElement(
    "button",
    "collage-bg-card__thumb collage-bg-card__thumb--solid collage-bg-card__thumb--checker",
  );
  solidThumbEl.type = "button";
  solidThumbEl.addEventListener("click", () => onSelectSolid());
  const solidFooterEl = createElement("div", "collage-bg-card__footer");
  solidFooterEl.append(createElement("div", "collage-bg-card__name", SOLID_CARD_NAME));
  solidCardEl.append(solidThumbEl, solidFooterEl);

  const loadingEl = createElement("div", "collage-bg-loading", "Cargando fondos…");

  /* ── Cards ──────────────────────────────────────────────────────── */

  /** @type {Map<string, object>} key → card record */
  const cards = new Map();

  /** Refreshes the accessible names of a fully assembled card record. */
  const syncCardLabels = (card) => {
    if (!card?.root || !card.thumb) return;
    const name = card.name || card.url || "Fondo";
    const selected = card.root.dataset.selected === "true";
    const suffix = card.loadState === LOAD_STATE.ERROR ? " (imagen no disponible)" : "";
    setAriaLabel(card.thumb, `${selected ? "Fondo seleccionado" : "Seleccionar fondo"}: ${name}${suffix}`);
    card.root.title = `${name}${suffix}`;
    setAriaLabel(card.editButton, `Editar fondo: ${name}`);
    setAriaLabel(card.deleteButton, `Eliminar fondo: ${name}`);
  };

  /**
   * Builds a card record. Lifecycle is strictly ordered: every node and field
   * of the record exists before the thumbnail starts loading, so the
   * `onStateChange` callback always observes a complete record.
   */
  const createCard = (background, key) => {
    const url = String(background.url || "").trim();
    const name = String(background.name || "").trim();

    const root = createElement("div", "collage-bg-card");
    root.setAttribute("role", "listitem");
    root.dataset.key = key;

    const thumb = createElement("button", "collage-bg-card__thumb");
    thumb.type = "button";
    thumb.addEventListener("click", () => onSelectBackground(url));

    const editButton = createActionButton({
      ariaLabel: "Editar fondo",
      svg: icons.pencil,
      onClick: () => onEdit(card.background),
    });
    const deleteButton = createActionButton({
      ariaLabel: "Eliminar fondo",
      svg: icons.trash,
      danger: true,
      onClick: () => onDelete(card.background),
    });

    const card = {
      root,
      thumb,
      nameEl: createElement("div", "collage-bg-card__name", name || UNNAMED_LABEL),
      editButton,
      deleteButton,
      url,
      name,
      background,
      loadState: LOAD_STATE.LOADING,
    };

    const thumbnail = createThumbnail({
      alt: name || "Fondo",
      brokenImageSvg: icons.brokenImage,
      onStateChange(state) {
        card.loadState = state;
        syncCardLabels(card);
      },
    });
    thumb.append(thumbnail.image, thumbnail.placeholder);

    const actions = createElement("div", "collage-bg-card__actions");
    actions.append(editButton, deleteButton);
    const footer = createElement("div", "collage-bg-card__footer");
    footer.append(card.nameEl);
    root.append(thumb, actions, footer);

    syncCardLabels(card);
    thumbnail.load(url ? toThumbnailUrl(url) : "");
    return card;
  };

  const removeCard = (key) => {
    const card = cards.get(key);
    if (!card) return;
    card.root.remove();
    cards.delete(key);
  };

  /* ── Filtering ──────────────────────────────────────────────────── */

  let searchIndex = createBackgroundSearchIndex([]);
  let query = "";
  let filterFrame = 0;
  let isDestroyed = false;
  let isGalleryMounted = false;

  const solidPseudoBackground = Object.freeze({ id: SOLID_CARD_KEY, name: SOLID_CARD_NAME, url: "" });
  const keyOf = (background) =>
    String(background.id || "").trim() || String(background.url || "").trim();

  const applyFilter = () => {
    filterFrame = 0;
    if (isDestroyed) return;

    const results = searchBackgrounds(searchIndex, query);
    const visibleKeys = new Set(results.map(keyOf));
    const hasQuery = query.trim().length > 0;

    const orderedNodes = [];
    for (const background of results) {
      const key = keyOf(background);
      const node = key === SOLID_CARD_KEY ? solidCardEl : cards.get(key)?.root;
      if (node) orderedNodes.push(node);
    }

    solidCardEl.hidden = !visibleKeys.has(SOLID_CARD_KEY);
    if (solidCardEl.hidden) orderedNodes.push(solidCardEl);

    for (const entry of searchIndex.entries) {
      if (entry.key === SOLID_CARD_KEY || visibleKeys.has(entry.key)) continue;
      const card = cards.get(entry.key);
      if (card) {
        card.root.hidden = true;
        orderedNodes.push(card.root);
      }
    }
    for (const key of visibleKeys) {
      const card = cards.get(key);
      if (card) card.root.hidden = false;
    }

    const current = gridEl.children;
    let orderChanged = current.length !== orderedNodes.length;
    for (let i = 0; !orderChanged && i < orderedNodes.length; i += 1) {
      orderChanged = current[i] !== orderedNodes[i];
    }
    if (orderChanged) gridEl.replaceChildren(...orderedNodes);

    const visibleCount = visibleKeys.has(SOLID_CARD_KEY) ? visibleKeys.size - 1 : visibleKeys.size;
    const totalCount = searchIndex.size - 1;

    if (emptyStateEl) {
      const showEmpty = hasQuery && visibleKeys.size === 0;
      emptyStateEl.hidden = !showEmpty;
      emptyStateEl.textContent = showEmpty ? `Sin fondos que coincidan con “${query.trim()}”` : "";
    }

    onFilterChange({ total: totalCount, visible: visibleCount, query });
  };

  /* ── Public API ─────────────────────────────────────────────────── */

  return Object.freeze({
    showLoading() {
      if (isDestroyed) return;
      isGalleryMounted = false;
      galleryEl.setAttribute("aria-busy", "true");
      galleryEl.replaceChildren(loadingEl);
    },

    /**
     * @param {object} model
     * @param {object[]} [model.backgrounds]
     * @param {string} [model.selectedUrl] empty string selects the solid colour
     * @param {string} [model.solidColor]
     */
    render({ backgrounds = [], selectedUrl = "", solidColor = DEFAULT_SOLID_COLOR } = {}) {
      if (isDestroyed) return;

      const list = Array.isArray(backgrounds) ? backgrounds : [];
      const selected = String(selectedUrl || "").trim();
      const hex = toHex(solidColor || DEFAULT_SOLID_COLOR);

      solidThumbEl.style.setProperty("--solid-color", hex);
      solidThumbEl.setAttribute("aria-label", `Seleccionar color sólido (${hex})`);
      markSelected(solidCardEl, solidThumbEl, !selected);

      const seenKeys = new Set([SOLID_CARD_KEY]);
      for (const background of list) {
        if (!background || typeof background !== "object") continue;
        const key = keyOf(background);
        if (!key || seenKeys.has(key)) continue;
        seenKeys.add(key);

        const url = String(background.url || "").trim();
        const name = String(background.name || "").trim();

        let card = cards.get(key);
        if (card && card.url !== url) {
          removeCard(key);
          card = null;
        }
        if (card) card.background = background;
        else {
          card = createCard(background, key);
          cards.set(key, card);
        }

        let labelsDirty = false;
        if (card.name !== name) {
          card.name = name;
          card.nameEl.textContent = name || UNNAMED_LABEL;
          labelsDirty = true;
        }
        const isSelected = Boolean(selected && url && url === selected);
        if ((card.root.dataset.selected === "true") !== isSelected) {
          markSelected(card.root, card.thumb, isSelected);
          labelsDirty = true;
        }
        if (labelsDirty) syncCardLabels(card);
      }
      for (const key of [...cards.keys()]) if (!seenKeys.has(key)) removeCard(key);

      searchIndex = createBackgroundSearchIndex([solidPseudoBackground, ...list]);

      if (!isGalleryMounted) {
        isGalleryMounted = true;
        galleryEl.removeAttribute("aria-busy");
        galleryEl.replaceChildren(createButton, gridEl);
      }
      if (filterFrame) cancelAnimationFrame(filterFrame);
      applyFilter();
    },

    setQuery(value) {
      const next = String(value ?? "");
      if (next === query) return;
      query = next;
      if (isGalleryMounted && !filterFrame && !isDestroyed) {
        filterFrame = requestAnimationFrame(applyFilter);
      }
    },

    destroy() {
      isDestroyed = true;
      if (filterFrame) cancelAnimationFrame(filterFrame);
      filterFrame = 0;
      cards.clear();
      galleryEl.replaceChildren();
    },
  });
}

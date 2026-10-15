/**
 * Background Search Control
 *
 * Owns the search input, its clear button and the live result counter.
 * Emits the raw query on every change; filtering itself happens in the
 * gallery view. Escape clears the query (and stops propagating so parent
 * dialogs/panels do not also close).
 */

function noop() {}

function pluralize(count, singular, plural) {
  return `${count} ${count === 1 ? singular : plural}`;
}

/**
 * @param {object} options
 * @param {HTMLInputElement} [options.inputEl]
 * @param {HTMLButtonElement} [options.clearButtonEl]
 * @param {HTMLElement} [options.countEl] `aria-live` result summary
 * @param {(query: string) => void} [options.onQueryChange]
 */
export function createBackgroundSearchControl({
  inputEl,
  clearButtonEl,
  countEl,
  onQueryChange = noop,
} = {}) {
  if (!inputEl) {
    return Object.freeze({ getQuery: () => "", updateSummary: noop, destroy: noop });
  }

  const disposers = [];
  const listen = (element, type, handler) => {
    if (!element) return;
    element.addEventListener(type, handler);
    disposers.push(() => element.removeEventListener(type, handler));
  };

  let query = "";

  const syncClearButton = () => {
    if (clearButtonEl) clearButtonEl.hidden = query.length === 0;
  };

  const setQuery = (value) => {
    const next = String(value ?? "");
    if (next === query) return;
    query = next;
    syncClearButton();
    onQueryChange(query);
  };

  const clear = ({ focus = true } = {}) => {
    if (inputEl.value !== "") inputEl.value = "";
    setQuery("");
    if (focus) inputEl.focus();
  };

  listen(inputEl, "input", () => setQuery(inputEl.value));
  listen(inputEl, "keydown", (event) => {
    if (event.key !== "Escape" || inputEl.value === "") return;
    event.preventDefault();
    event.stopPropagation();
    clear();
  });
  listen(clearButtonEl, "click", () => clear());

  syncClearButton();

  return Object.freeze({
    getQuery: () => query,

    /** @param {{ total?: number, visible?: number, query?: string }} summary */
    updateSummary({ total = 0, visible = 0, query: activeQuery = "" } = {}) {
      if (!countEl) return;
      const text =
        String(activeQuery).trim().length > 0
          ? `${visible} de ${pluralize(total, "fondo", "fondos")}`
          : pluralize(total, "fondo", "fondos");
      if (countEl.textContent !== text) countEl.textContent = text;
    },

    destroy() {
      while (disposers.length) disposers.pop()();
    },
  });
}

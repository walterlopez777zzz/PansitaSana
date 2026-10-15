/**
 * Grid Global Controls Controller (UI → store)
 *
 * Binds the shared Collage/Cart header controls to the active grid slice:
 *   - "Nombre de Sección"  → `<slice>.sectionName`
 *   - "Total" (Cart only)  → `cart.totalOverride` (empty → `null`, i.e. computed)
 *   - "Separación (Gap)"   → `<slice>.gap`
 *   - background colour    → `<slice>.background`
 *
 * The opposite direction (store → inputs) lives in `mode-controller`
 * (`applyGridToInputs`), which skips the focused input, so typing is never
 * overwritten mid-keystroke. Handlers go through `onInput`, which ignores
 * events fired while state is being applied to the UI and flags interaction
 * for the render scheduler — that is what keeps the store ↔ UI loop closed
 * without feedback.
 */

const GAP_MIN = 0;
const GAP_MAX = 120;
const DEFAULT_GAP = 30;
const DEFAULT_BACKGROUND = "#333335";

function noop() {}

/**
 * @param {object} options
 * @param {object} options.store
 * @param {(handler: (event: Event) => void) => (event: Event) => void} options.onInput
 * @param {(mode: unknown) => string} options.normalizeMode
 * @param {(hex: string) => string} options.normalizeHex
 * @param {(hex: string) => string|null} options.normalizeOptionalHex
 * @param {(value: number, min: number, max: number) => number} options.clamp
 * @param {(rangeEl: HTMLInputElement) => void} [options.syncRangeProgress]
 * @param {(swatchEl: HTMLElement, hex: string) => void} [options.setSwatchColor]
 * @param {{ open: (anchor: HTMLElement, hex: string, onChange: (hex: string) => void) => void }} [options.colorPickerService]
 * @param {(inputEl: HTMLInputElement, value: string) => void} [options.setFieldValue]
 * @param {object} options.dom
 */
export function createGridGlobalControlsController({
  store,
  onInput,
  normalizeMode,
  normalizeHex,
  normalizeOptionalHex,
  clamp,
  syncRangeProgress = noop,
  setSwatchColor = noop,
  colorPickerService,
  setFieldValue,
  dom = {},
} = {}) {
  const { collageSectionNameEl, cartTotalEl, collageGapEl, collageBgHexEl, collageBgPickerEl } = dom;

  const disposers = [];
  const listen = (element, type, handler) => {
    if (!element) return;
    element.addEventListener(type, handler);
    disposers.push(() => element.removeEventListener(type, handler));
  };
  const guard = typeof onInput === "function" ? onInput : (handler) => handler;

  const resolveSliceKey = () => (normalizeMode(store.getState()?.ui?.mode) === "cart" ? "cart" : "collage");
  const updateSlice = (field, value) => store.update(`${resolveSliceKey()}.${field}`, value);

  /* ── Section name (Collage) ─────────────────────────────────────── */

  listen(
    collageSectionNameEl,
    "input",
    guard((event) => updateSlice("sectionName", String(event.target.value ?? ""))),
  );

  /* ── Total override (Cart) ──────────────────────────────────────── */

  listen(
    cartTotalEl,
    "input",
    guard((event) => {
      const raw = String(event.target.value ?? "").trim();
      store.update("cart.totalOverride", raw === "" ? null : raw);
    }),
  );

  /* ── Gap ────────────────────────────────────────────────────────── */

  listen(
    collageGapEl,
    "input",
    guard((event) => {
      const parsed = Number(event.target.value);
      const gap = clamp(Number.isFinite(parsed) ? parsed : DEFAULT_GAP, GAP_MIN, GAP_MAX);
      syncRangeProgress(event.target);
      updateSlice("gap", gap);
    }),
  );

  /* ── Background colour ──────────────────────────────────────────── */

  const applyBackground = (hex) => {
    updateSlice("background", hex);
    setSwatchColor(collageBgPickerEl, hex);
  };

  listen(
    collageBgHexEl,
    "input",
    guard((event) => {
      const hex = normalizeOptionalHex(event.target.value);
      if (hex) applyBackground(hex);
    }),
  );
  listen(
    collageBgHexEl,
    "blur",
    guard(() => {
      const current = normalizeHex(store.getState()?.[resolveSliceKey()]?.background ?? DEFAULT_BACKGROUND);
      if (typeof setFieldValue === "function") setFieldValue(collageBgHexEl, current, { force: true });
      else collageBgHexEl.value = current;
    }),
  );

  if (colorPickerService && collageBgPickerEl) {
    listen(collageBgPickerEl, "click", () => {
      const current = normalizeHex(store.getState()?.[resolveSliceKey()]?.background ?? DEFAULT_BACKGROUND);
      colorPickerService.open(collageBgPickerEl, current, (picked) => {
        const hex = normalizeHex(picked);
        if (collageBgHexEl && typeof setFieldValue === "function") {
          setFieldValue(collageBgHexEl, hex, { force: true });
        }
        applyBackground(hex);
      });
    });
  }

  return Object.freeze({
    destroy() {
      while (disposers.length) disposers.pop()();
    },
  });
}

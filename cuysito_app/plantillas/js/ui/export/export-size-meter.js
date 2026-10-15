/**
 * Export Size Meter
 *
 * Shows the exact byte size the current export will have when downloaded.
 * The number comes from the real export pipeline (`measureExport` must run
 * the same `exportCanvas` → `generateFormatBlob` path as the download button)
 * and is read from `blob.size` — never estimated.
 *
 * Scheduling:
 *   - Any store change that can alter the rendered scene marks the meter
 *     dirty; UI-only fields (active tab, search term, selection…) are ignored.
 *   - Measurements are debounced and only run while the "Imagen" panel is
 *     visible and the document is not hidden; a dirty meter measures as soon
 *     as it becomes visible again.
 *   - Only one export runs at a time. Results carry a token so a stale
 *     measurement can never overwrite a newer one; if the scene changed
 *     mid-flight, a new measurement starts when the current one settles.
 */

import {
  WHATSAPP_IMAGE_LIMIT_BYTES,
  SIZE_LEVEL,
  describeByteSize,
  evaluateAgainstLimit,
} from "../../domain/export/export-size-limit.js";

const DEFAULT_DEBOUNCE_MS = 350;

const METER_STATE = Object.freeze({
  IDLE: "idle",
  MEASURING: "measuring",
  READY: "ready",
  ERROR: "error",
});

/** `state.ui` keys that never affect the exported pixels. */
const IGNORED_UI_KEYS = new Set(["searchTerm", "activeTab", "inspectorImageTab", "selectedElement"]);

function noop() {}

/** Cheap structural check: top-level slices by reference, `ui` by relevant keys. */
function isSameScene(next, previous) {
  if (next === previous) return true;
  if (!next || !previous) return false;

  const keys = new Set([...Object.keys(next), ...Object.keys(previous)]);
  for (const key of keys) {
    if (key === "ui") continue;
    if (next[key] !== previous[key]) return false;
  }

  const nextUi = next.ui || {};
  const previousUi = previous.ui || {};
  const uiKeys = new Set([...Object.keys(nextUi), ...Object.keys(previousUi)]);
  for (const key of uiKeys) {
    if (IGNORED_UI_KEYS.has(key)) continue;
    if (nextUi[key] !== previousUi[key]) return false;
  }
  return true;
}

/**
 * @param {object} options
 * @param {{ getState: () => object, subscribe: (listener: Function) => () => void }} options.store
 * @param {() => Promise<{ blob: Blob, formatLabel?: string } | Blob>} options.measureExport
 * @param {() => boolean} [options.isPanelVisible]
 * @param {number} [options.limitBytes]
 * @param {number} [options.debounceMs]
 * @param {object} options.dom
 * @param {HTMLElement} [options.dom.rootEl] carries `data-state` / `data-level`
 * @param {HTMLElement} [options.dom.primaryEl] headline size (MB or KB)
 * @param {HTMLElement} [options.dom.bytesEl]
 * @param {HTMLElement} [options.dom.kbEl]
 * @param {HTMLElement} [options.dom.mbEl]
 * @param {HTMLMeterElement} [options.dom.meterEl]
 * @param {HTMLElement} [options.dom.badgeEl]
 * @param {HTMLElement} [options.dom.statusEl]
 */
export function createExportSizeMeter({
  store,
  measureExport,
  isPanelVisible = () => true,
  limitBytes = WHATSAPP_IMAGE_LIMIT_BYTES,
  debounceMs = DEFAULT_DEBOUNCE_MS,
  dom = {},
} = {}) {
  const { rootEl, primaryEl, bytesEl, kbEl, mbEl, meterEl, badgeEl, statusEl } = dom;
  if (!rootEl || typeof measureExport !== "function" || !store) {
    return Object.freeze({ requestMeasure: noop, destroy: noop });
  }

  const limitLabel = describeByteSize(limitBytes).primaryLabel;
  if (meterEl) {
    meterEl.max = limitBytes;
    meterEl.high = Math.round(limitBytes * 0.9);
    meterEl.optimum = 0;
  }

  /* ── Painting ───────────────────────────────────────────────────── */

  const setText = (element, text) => {
    if (element && element.textContent !== text) element.textContent = text;
  };

  const paintMeasuring = () => {
    rootEl.dataset.state = METER_STATE.MEASURING;
    rootEl.setAttribute("aria-busy", "true");
    setText(statusEl, "Calculando peso exacto…");
  };

  const paintError = (message) => {
    rootEl.dataset.state = METER_STATE.ERROR;
    rootEl.removeAttribute("aria-busy");
    delete rootEl.dataset.level;
    setText(primaryEl, "—");
    setText(bytesEl, "—");
    setText(kbEl, "—");
    setText(mbEl, "—");
    setText(badgeEl, "");
    setText(statusEl, message);
    if (meterEl) meterEl.value = 0;
  };

  const paintResult = (bytes, { formatLabel = "" } = {}) => {
    const size = describeByteSize(bytes);
    const verdict = evaluateAgainstLimit(size.bytes, limitBytes);

    rootEl.dataset.state = METER_STATE.READY;
    rootEl.dataset.level = verdict.level;
    rootEl.removeAttribute("aria-busy");

    setText(primaryEl, size.primaryLabel);
    setText(bytesEl, size.bytesLabel);
    setText(kbEl, size.kbLabel);
    setText(mbEl, size.mbLabel);
    if (meterEl) meterEl.value = Math.min(size.bytes, limitBytes);

    const format = formatLabel ? ` (${formatLabel})` : "";
    if (verdict.level === SIZE_LEVEL.OVER) {
      const excess = describeByteSize(-verdict.marginBytes).compactLabel;
      setText(badgeEl, "Supera 16 MB");
      setText(
        statusEl,
        `WhatsApp rechazará este archivo${format}: excede el límite de ${limitLabel} por ${excess}. Reduce la resolución o la calidad.`,
      );
    } else if (verdict.level === SIZE_LEVEL.WARNING) {
      const margin = describeByteSize(verdict.marginBytes).compactLabel;
      setText(badgeEl, "Cerca del límite");
      setText(statusEl, `Apto para WhatsApp${format}, pero solo quedan ${margin} de margen bajo ${limitLabel}.`);
    } else {
      const margin = describeByteSize(verdict.marginBytes).compactLabel;
      setText(badgeEl, "Apto para WhatsApp");
      setText(statusEl, `Dentro del límite de ${limitLabel}${format}. Margen disponible: ${margin}.`);
    }
  };

  /* ── Scheduling ─────────────────────────────────────────────────── */

  let isDestroyed = false;
  let isDirty = true;
  let isInFlight = false;
  let debounceTimer = 0;
  let measurementToken = 0;

  const canMeasure = () => !isDestroyed && isDirty && isPanelVisible() && document.visibilityState !== "hidden";

  const run = async () => {
    debounceTimer = 0;
    if (!canMeasure() || isInFlight) return;

    isInFlight = true;
    isDirty = false;
    measurementToken += 1;
    const token = measurementToken;
    paintMeasuring();

    try {
      const result = await measureExport();
      if (token !== measurementToken || isDestroyed) return;

      const blob = result?.blob ?? result;
      if (!blob || typeof blob.size !== "number") {
        throw new Error("El navegador no devolvió el archivo generado");
      }
      paintResult(blob.size, { formatLabel: result?.formatLabel || "" });
    } catch (error) {
      if (token !== measurementToken || isDestroyed) return;
      paintError(`No se pudo calcular el peso: ${String(error?.message || error)}`);
    } finally {
      isInFlight = false;
      if (isDirty) schedule();
    }
  };

  const schedule = () => {
    if (isDestroyed || !canMeasure()) return;
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(run, debounceMs);
  };

  const requestMeasure = () => {
    isDirty = true;
    measurementToken += 1; // invalidate any in-flight result
    schedule();
  };

  /* ── Subscriptions ──────────────────────────────────────────────── */

  let lastScene = store.getState();
  const unsubscribeStore = store.subscribe((nextState) => {
    const sceneChanged = !isSameScene(nextState, lastScene);
    lastScene = nextState;
    if (sceneChanged) requestMeasure();
    else if (isDirty) schedule(); // e.g. the "Imagen" tab just became visible
  });

  const onVisibilityChange = () => {
    if (document.visibilityState !== "hidden" && isDirty) schedule();
  };
  document.addEventListener("visibilitychange", onVisibilityChange);

  schedule();

  return Object.freeze({
    /** Forces a fresh measurement (e.g. after a format or quality change outside the store). */
    requestMeasure,

    destroy() {
      isDestroyed = true;
      measurementToken += 1;
      if (debounceTimer) clearTimeout(debounceTimer);
      debounceTimer = 0;
      unsubscribeStore();
      document.removeEventListener("visibilitychange", onVisibilityChange);
    },
  });
}

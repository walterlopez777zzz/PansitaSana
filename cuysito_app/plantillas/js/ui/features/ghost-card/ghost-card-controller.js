import { computeGhostSlot } from "../../../domain/collage/grid-layout.js";
import { normalizeMode, isGridMode } from "../../../domain/mode-utils.js";

/**
 * Ghost Card Controller (RN-12)
 *
 * Botón «+» accesible superpuesto a `#preview` en el siguiente hueco libre de
 * la rejilla (Collage/Carrito). Se posiciona con custom properties expresadas
 * como fracción del layout (0..1), de modo que el escalado CSS del preview lo
 * mantiene alineado sin medir el DOM (cero reflows forzados). Las escrituras
 * al DOM se agrupan en un `requestAnimationFrame` y sólo ocurren si la
 * colocación cambió.
 *
 * Colocaciones:
 * - `slot`  → el hueco cabe dentro del lienzo (fila a medias o rejilla vacía).
 * - `below` → el hueco inaugura una fila nueva fuera del lienzo: se muestra
 *             como barra a ancho de contenido justo debajo del preview.
 *
 * Oculto en modo individual/cupón, sin layout o durante arrastres sobre el
 * lienzo. La exportación PNG se genera desde el canvas, nunca desde el DOM,
 * así que el botón no aparece en la imagen por construcción.
 */

const PLACEMENT = Object.freeze({ SLOT: "slot", BELOW: "below" });

function toPercent(fraction) {
  return `${(Math.max(0, fraction) * 100).toFixed(4)}%`;
}

/**
 * @param {object} layout  layout de rejilla (`computeLayout` del modo activo)
 * @returns {{ placement: string, x: number, y: number, w: number, h: number }|null}
 *   coordenadas en fracción del layout; `null` si no hay hueco calculable.
 */
export function resolveGhostPlacement(layout) {
  const width = Number(layout?.width);
  const height = Number(layout?.height);
  if (!(width > 0) || !(height > 0)) return null;

  const slot = computeGhostSlot(layout);
  if (!slot) return null;

  const fitsInside = slot.y + slot.h <= height + 0.5;
  if (fitsInside) {
    return Object.freeze({
      placement: PLACEMENT.SLOT,
      x: slot.x / width,
      y: slot.y / height,
      w: slot.w / width,
      h: slot.h / height,
    });
  }

  const padding = Math.max(0, Number(layout.padding) || 0);
  return Object.freeze({
    placement: PLACEMENT.BELOW,
    x: padding / width,
    y: 1,
    w: Math.max(0, width - 2 * padding) / width,
    h: 0,
  });
}

function samePlacement(a, b) {
  if (a === b) return true;
  if (!a || !b) return false;
  return a.placement === b.placement && a.x === b.x && a.y === b.y && a.w === b.w && a.h === b.h;
}

function buildButton(plusSvg) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = "ghost-card";
  button.hidden = true;
  button.setAttribute("aria-label", "Añadir tarjetas en el siguiente hueco libre");
  button.title = "Añadir tarjetas (Insert o A)";

  const icon = document.createElement("span");
  icon.className = "ghost-card__icon";
  icon.setAttribute("aria-hidden", "true");
  if (plusSvg) icon.innerHTML = plusSvg;

  const label = document.createElement("span");
  label.className = "ghost-card__label";
  label.textContent = "Añadir";

  button.append(icon, label);
  return button;
}

/**
 * @param {object} options
 * @param {{ getState: () => object, subscribe: (listener: (state: object) => void) => () => void }} options.store
 * @param {HTMLElement} options.previewEl            contenedor `#preview`
 * @param {(state: object) => object|null} options.computeLayout  layout del modo de rejilla activo
 * @param {() => void} options.onActivate            acción del botón (abrir «Añadir tarjetas»)
 * @param {string} [options.plusSvg]
 */
export function createGhostCardController({ store, previewEl, computeLayout, onActivate, plusSvg = "" }) {
  if (!store || !previewEl || typeof computeLayout !== "function") {
    return Object.freeze({ refresh() {}, dispose() {} });
  }

  const button = buildButton(plusSvg);
  previewEl.prepend(button);

  const abort = new AbortController();
  const { signal } = abort;

  let frame = 0;
  let dragging = false;
  let applied = null;
  let appliedHidden = true;
  let lastMode = "";
  let lastSlice = null;
  let pending = null;
  let disposed = false;

  const apply = () => {
    frame = 0;
    if (disposed) return;

    const hidden = !pending || dragging;
    if (hidden !== appliedHidden) {
      appliedHidden = hidden;
      button.hidden = hidden;
    }
    if (!pending || samePlacement(pending, applied)) return;

    applied = pending;
    button.dataset.placement = pending.placement;
    button.style.setProperty("--ghost-x", toPercent(pending.x));
    button.style.setProperty("--ghost-y", toPercent(pending.y));
    button.style.setProperty("--ghost-w", toPercent(pending.w));
    button.style.setProperty("--ghost-h", toPercent(pending.h));
  };

  const scheduleApply = () => {
    if (frame || disposed) return;
    frame = requestAnimationFrame(apply);
  };

  const recompute = (state) => {
    const mode = normalizeMode(state?.ui?.mode);
    const slice = isGridMode(mode) ? state?.[mode === "cart" ? "cart" : "collage"] : null;
    if (mode === lastMode && slice === lastSlice) return;
    lastMode = mode;
    lastSlice = slice;

    pending = slice ? resolveGhostPlacement(computeLayout(state)) : null;
    scheduleApply();
  };

  const setDragging = (value) => {
    if (dragging === value) return;
    dragging = value;
    scheduleApply();
  };

  button.addEventListener("pointerdown", (event) => event.stopPropagation(), { signal });
  button.addEventListener("click", (event) => {
    event.preventDefault();
    onActivate?.();
  }, { signal });

  previewEl.addEventListener("pointerdown", (event) => {
    if (event.isPrimary && !button.contains(event.target)) setDragging(true);
  }, { signal });
  for (const type of ["pointerup", "pointercancel", "lostpointercapture"]) {
    previewEl.addEventListener(type, () => setDragging(false), { signal });
  }

  const unsubscribe = store.subscribe((state) => recompute(state));
  recompute(store.getState());

  return Object.freeze({
    /** Fuerza el recálculo (p. ej. tras cambios que no pasan por el store). */
    refresh() {
      lastSlice = null;
      recompute(store.getState());
    },
    dispose() {
      if (disposed) return;
      disposed = true;
      unsubscribe();
      abort.abort();
      if (frame) cancelAnimationFrame(frame);
      frame = 0;
      button.remove();
    },
  });
}

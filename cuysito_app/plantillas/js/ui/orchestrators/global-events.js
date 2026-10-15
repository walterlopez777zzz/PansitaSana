export function initGlobalUiEvents({
  getSelectedElement,
  clearSelection,
  syncRangeProgress,
  targetDocument = document,
} = {}) {
  const readSelectedElement = typeof getSelectedElement === "function"
    ? getSelectedElement
    : () => null;
  const clearSelectedElement = typeof clearSelection === "function"
    ? clearSelection
    : () => {};
  const syncRange = typeof syncRangeProgress === "function"
    ? syncRangeProgress
    : () => {};

  const ignoreSelector = [
    ".panel",
    "#preview-canvas",
    ".preview",
    "dialog",
    ".dialog",
    ".color-picker-popover",
    ".iro-color-picker",
    "[data-color-picker]",
    "#layers-float",
    ".command-bar",
    ".theme-toggle-fab",
  ].join(",");

  targetDocument.addEventListener("pointerdown", event => {
    if (event.button !== 0) {
      return;
    }

    const target = event.target;
    if (!target || typeof target.closest !== "function") {
      return;
    }

    if (target.closest(ignoreSelector)) {
      return;
    }

    if (readSelectedElement()) {
      clearSelectedElement();
    }
  });

  targetDocument.addEventListener("input", event => {
    if (event.target.classList.contains("range")) {
      syncRange(event.target);
    }
  });

  targetDocument.querySelectorAll(".range").forEach(syncRange);

  return Object.freeze({
    syncAllRanges() {
      targetDocument.querySelectorAll(".range").forEach(syncRange);
    },
  });
}

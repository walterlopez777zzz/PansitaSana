/**
 * Collage Backgrounds Controller (facade)
 *
 * Coordinates the background picker shared by Collage and Cart modes:
 *   - `background-gallery-view`  → cards, solid-colour card, filtering
 *   - `background-search-control` → search input + live counter
 *   - `background-dialog-controller` → create/edit form
 *
 * This module is the only piece that talks to the store and the backgrounds
 * service. Selection writes to `<mode>.globalDesign.backgroundImageUrl` of
 * the active slice, so a background chosen in Cart mode does not leak into
 * Collage mode.
 */

import { createBackgroundGalleryView } from "./backgrounds/background-gallery-view.js";
import { createBackgroundSearchControl } from "./backgrounds/background-search-control.js";
import { createBackgroundDialogController } from "./backgrounds/background-dialog-controller.js";

const DEFAULT_SOLID_COLOR = "#333335";
const TEMPORARY_ID_PREFIX = "tmp_";

function noop() {}

export function createCollageBackgroundsController({
  store,
  history,
  backgroundsService,
  optimizeImageUrl,
  normalizeHex,
  normalizeMode,
  setStatus = noop,
  collageBgGalleryEl,
  collageBgEmptyEl,
  collageBgSearchEl,
  collageBgSearchClearEl,
  collageBgSearchCountEl,
  collageBgDialogEl,
  collageBgDialogTitleEl,
  collageBgDialogNameEl,
  collageBgDialogFillNameEl,
  collageBgDialogUrlEl,
  collageBgDialogUrlLabelEl,
  collageBgDialogPreviewImgEl,
  collageBgDialogErrorEl,
  collageBgDialogCancelEl,
  collageBgDialogSaveEl,
  collageBgFileInputEl,
  collageBgUploadBtnEl,
  plusSvg,
  deleteSvg,
  pencilSvg,
  brokenImageSvg,
  uploadSvg,
} = {}) {
  const resolveSliceKey = () =>
    (typeof normalizeMode === "function" ? normalizeMode(store.getState()?.ui?.mode) : "collage") === "cart"
      ? "cart"
      : "collage";
  const backgroundUrlPath = () => `${resolveSliceKey()}.globalDesign.backgroundImageUrl`;
  const currentBackgroundUrl = (state) =>
    String(state?.[resolveSliceKey()]?.globalDesign?.backgroundImageUrl ?? "").trim();

  const selectBackground = (url) => {
    history?.beginTransaction?.();
    try {
      store.update(backgroundUrlPath(), String(url ?? ""));
    } finally {
      history?.endTransaction?.();
    }
  };

  let isDestroyed = false;

  /* ── Persistence ────────────────────────────────────────────────── */

  const saveBackground = async ({ mode, id, name, url }) => {
    const isPersistedEdit = mode === "edit" && id && !String(id).startsWith(TEMPORARY_ID_PREFIX);
    const saved = isPersistedEdit
      ? await backgroundsService.update(id, { name, url })
      : await backgroundsService.create({ name, url });
    if (!saved || isDestroyed) return;
    selectBackground(saved.url);
    render(store.getState());
  };

  const deleteBackground = async (background) => {
    if (!background?.id) return;
    if (!window.confirm("¿Eliminar este fondo de la galería?")) return;
    try {
      await backgroundsService.remove(background.id);
      if (isDestroyed) return;
      const url = String(background.url ?? "").trim();
      if (url && url === currentBackgroundUrl(store.getState())) selectBackground("");
      render(store.getState());
    } catch (error) {
      setStatus(`No se pudo eliminar el fondo: ${String(error?.message || error)}`);
    }
  };

  /* ── Sub-controllers ────────────────────────────────────────────── */

  const dialogController = createBackgroundDialogController({
    dialogEl: collageBgDialogEl,
    titleEl: collageBgDialogTitleEl,
    nameEl: collageBgDialogNameEl,
    fillNameEl: collageBgDialogFillNameEl,
    urlEl: collageBgDialogUrlEl,
    urlLabelEl: collageBgDialogUrlLabelEl,
    previewImgEl: collageBgDialogPreviewImgEl,
    errorEl: collageBgDialogErrorEl,
    cancelEl: collageBgDialogCancelEl,
    saveEl: collageBgDialogSaveEl,
    fileInputEl: collageBgFileInputEl,
    uploadButtonEl: collageBgUploadBtnEl,
    uploadSvg,
    getSuggestedName: () => String(store.getState()?.[resolveSliceKey()]?.sectionName ?? "").trim(),
    onSave: saveBackground,
    setStatus,
  });

  const searchControl = createBackgroundSearchControl({
    inputEl: collageBgSearchEl,
    clearButtonEl: collageBgSearchClearEl,
    countEl: collageBgSearchCountEl,
    onQueryChange: (query) => galleryView.setQuery(query),
  });

  const galleryView = createBackgroundGalleryView({
    galleryEl: collageBgGalleryEl,
    emptyStateEl: collageBgEmptyEl,
    optimizeImageUrl,
    normalizeHex,
    icons: { plus: plusSvg, pencil: pencilSvg, trash: deleteSvg, brokenImage: brokenImageSvg },
    onSelectBackground: selectBackground,
    onSelectSolid: () => selectBackground(""),
    onCreate: () => dialogController.open({ mode: "create" }),
    onEdit: (background) => dialogController.open({ mode: "edit", background }),
    onDelete: deleteBackground,
    onFilterChange: (summary) => searchControl.updateSummary(summary),
  });

  /* ── Render ─────────────────────────────────────────────────────── */

  let isFetchScheduled = false;

  function render(state) {
    if (isDestroyed || !collageBgGalleryEl) return;

    const slice = state?.[resolveSliceKey()] ?? {};
    const selectedUrl = String(slice.globalDesign?.backgroundImageUrl ?? "").trim();
    if (selectedUrl) backgroundsService.upsertFromUrl(selectedUrl);

    if (!backgroundsService.hasLoaded()) {
      galleryView.showLoading();
      if (!backgroundsService.isLoading() && !isFetchScheduled) {
        isFetchScheduled = true;
        backgroundsService
          .fetchAll()
          .catch((error) => setStatus(`Error cargando fondos: ${String(error?.message || error)}`))
          .finally(() => {
            isFetchScheduled = false;
            if (!isDestroyed) render(store.getState());
          });
      }
      return;
    }

    galleryView.render({
      backgrounds: backgroundsService.getAll(),
      selectedUrl,
      solidColor: slice.background ?? DEFAULT_SOLID_COLOR,
    });
  }

  return Object.freeze({
    render,
    openCollageBgDialog: dialogController.open,
    closeCollageBgDialog: dialogController.close,
    destroy() {
      isDestroyed = true;
      searchControl.destroy();
      dialogController.destroy();
      galleryView.destroy();
    },
  });
}

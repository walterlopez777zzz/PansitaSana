/**
 * Background Dialog Controller
 *
 * Create/edit form for a gallery background, backed by the native `<dialog>`
 * (modal focus containment, Escape and backdrop dismiss via
 * `attachDialogDismiss`). Supports remote URLs (validated by loading a
 * preview) and local files (data URLs, pre-validated by the file reader).
 *
 * Persistence is delegated to `onSave`; the controller only owns form state,
 * validation and preview lifecycle.
 */

import { attachDialogDismiss } from "../../utils/dialog-behavior.js";

const URL_LABEL_REMOTE = "URL de la Imagen";
const URL_LABEL_LOCAL = "Archivo Local";

function noop() {}

/** "my-cool_bg.png" → "my cool bg" */
export function displayNameFromFileName(fileName) {
  return String(fileName ?? "")
    .replace(/\.[^.]+$/, "")
    .replace(/[-_]+/g, " ")
    .trim();
}

/**
 * @param {object} options
 * @param {HTMLDialogElement} options.dialogEl
 * @param {HTMLElement} [options.titleEl]
 * @param {HTMLInputElement} [options.nameEl]
 * @param {HTMLButtonElement} [options.fillNameEl] copies the section name into the name field
 * @param {HTMLInputElement} [options.urlEl]
 * @param {HTMLElement} [options.urlLabelEl]
 * @param {HTMLImageElement} [options.previewImgEl]
 * @param {HTMLElement} [options.errorEl]
 * @param {HTMLButtonElement} [options.cancelEl]
 * @param {HTMLButtonElement} [options.saveEl]
 * @param {HTMLInputElement} [options.fileInputEl]
 * @param {HTMLButtonElement} [options.uploadButtonEl]
 * @param {string} [options.uploadSvg]
 * @param {() => string} [options.getSuggestedName] e.g. the current section name
 * @param {(payload: { mode: "create"|"edit", id: string|null, name: string, url: string }) => Promise<void>} options.onSave
 * @param {(message: string) => void} [options.setStatus]
 */
export function createBackgroundDialogController({
  dialogEl,
  titleEl,
  nameEl,
  fillNameEl,
  urlEl,
  urlLabelEl,
  previewImgEl,
  errorEl,
  cancelEl,
  saveEl,
  fileInputEl,
  uploadButtonEl,
  uploadSvg,
  getSuggestedName = () => "",
  onSave,
  setStatus = noop,
} = {}) {
  const canOpen = Boolean(dialogEl && typeof dialogEl.showModal === "function");

  const disposers = [];
  const listen = (element, type, handler) => {
    if (!element) return;
    element.addEventListener(type, handler);
    disposers.push(() => element.removeEventListener(type, handler));
  };

  if (uploadButtonEl && uploadSvg) {
    const iconEl = uploadButtonEl.querySelector(".collage-bg-upload-btn__icon");
    if (iconEl) iconEl.innerHTML = uploadSvg;
  }

  /* ── Form state ─────────────────────────────────────────────────── */

  const form = {
    mode: "create",
    id: null,
    originalName: "",
    originalUrl: "",
    isLocalFile: false,
    nameTouched: false,
    urlTouched: false,
    previewLoaded: false,
    previewSettled: true,
    isSaving: false,
  };
  let previewToken = 0;

  const nameValue = () => String(nameEl?.value ?? "").trim();
  const urlValue = () => String(urlEl?.value ?? "").trim();

  const setUrlLabel = () => {
    if (urlLabelEl) urlLabelEl.textContent = form.isLocalFile ? URL_LABEL_LOCAL : URL_LABEL_REMOTE;
  };

  const isUrlValid = () => (form.isLocalFile ? Boolean(urlValue()) : Boolean(urlValue()) && form.previewLoaded);

  const validate = () => {
    const name = nameValue();
    const url = urlValue();
    const nameOk = name.length > 0;
    const urlOk = isUrlValid();

    nameEl?.setAttribute("aria-invalid", form.nameTouched && !nameOk ? "true" : "false");
    urlEl?.setAttribute("aria-invalid", form.urlTouched && url && !urlOk ? "true" : "false");

    const changed = form.mode !== "edit" || name !== form.originalName || url !== form.originalUrl;
    if (saveEl) saveEl.disabled = form.isSaving || !(nameOk && urlOk && changed);
  };

  const showError = (visible) => {
    if (errorEl) errorEl.hidden = !visible;
  };

  /** Loads `url` into the preview; stale loads are ignored via `previewToken`. */
  const loadPreview = (url) => {
    if (!previewImgEl) return;
    previewToken += 1;
    const token = previewToken;

    form.previewLoaded = false;
    form.previewSettled = false;
    showError(false);

    if (!url) {
      form.previewSettled = true;
      previewImgEl.removeAttribute("src");
      previewImgEl.hidden = true;
      validate();
      return;
    }

    validate();
    previewImgEl.hidden = true;

    const settle = (loaded) => {
      if (token !== previewToken) return;
      form.previewSettled = true;
      form.previewLoaded = loaded;
      previewImgEl.hidden = !loaded;
      showError(!loaded && form.urlTouched);
      validate();
    };
    previewImgEl.onload = () => settle(true);
    previewImgEl.onerror = () => settle(false);
    previewImgEl.src = url;
    if (previewImgEl.complete) settle(previewImgEl.naturalWidth > 0);
  };

  /* ── Open / close ───────────────────────────────────────────────── */

  const open = ({ mode = "create", background = null, fromFile = false } = {}) => {
    if (!canOpen) return;

    form.mode = mode === "edit" ? "edit" : "create";
    form.id = background?.id ? String(background.id) : null;
    form.isLocalFile = Boolean(fromFile);
    form.nameTouched = false;
    form.urlTouched = false;
    form.previewLoaded = false;
    form.previewSettled = true;
    form.isSaving = false;
    form.originalName = String(background?.name ?? "").trim();
    form.originalUrl = String(background?.url ?? "").trim();

    if (titleEl) titleEl.textContent = form.mode === "edit" ? "Editar fondo" : "Agregar fondo";
    if (nameEl) nameEl.value = String(background?.name ?? "");
    if (urlEl) {
      urlEl.value = String(background?.url ?? "");
      urlEl.readOnly = form.isLocalFile; // protect the data URL from accidental edits
    }
    if (fillNameEl) fillNameEl.hidden = !getSuggestedName();
    setUrlLabel();
    showError(false);

    loadPreview(form.originalUrl);
    validate();
    dialogEl.showModal();
    nameEl?.focus();
  };

  const close = () => {
    if (!dialogEl) return;
    if (dialogEl.open) dialogEl.close();
    previewToken += 1;

    form.mode = "create";
    form.id = null;
    form.isLocalFile = false;
    form.nameTouched = false;
    form.urlTouched = false;
    form.previewLoaded = false;
    form.previewSettled = true;
    form.isSaving = false;
    form.originalName = "";
    form.originalUrl = "";

    if (urlEl) urlEl.readOnly = false;
    if (fileInputEl) fileInputEl.value = "";
    nameEl?.setAttribute("aria-invalid", "false");
    urlEl?.setAttribute("aria-invalid", "false");
    setUrlLabel();
    showError(false);
    validate();
  };

  /* ── Local file upload ──────────────────────────────────────────── */

  const openFromFile = (file) => {
    if (!file || !String(file.type).startsWith("image/")) return;
    const reader = new FileReader();
    reader.onload = () => {
      open({
        mode: "create",
        background: { name: displayNameFromFileName(file.name), url: String(reader.result) },
        fromFile: true,
      });
    };
    reader.onerror = () => setStatus("No se pudo leer el archivo seleccionado");
    reader.readAsDataURL(file);
  };

  /* ── Save ───────────────────────────────────────────────────────── */

  const save = async () => {
    const name = nameValue();
    const url = urlValue();
    if (!name || !url || !isUrlValid() || form.isSaving || typeof onSave !== "function") return;

    form.isSaving = true;
    validate();
    try {
      await onSave({ mode: form.mode, id: form.id, name, url });
      close();
    } catch (error) {
      setStatus(`No se pudo guardar el fondo: ${String(error?.message || error)}`);
    } finally {
      form.isSaving = false;
      validate();
    }
  };

  /* ── Wiring ─────────────────────────────────────────────────────── */

  const dismiss = attachDialogDismiss(dialogEl, close);
  disposers.push(() => dismiss.destroy());

  listen(cancelEl, "click", close);
  listen(saveEl, "click", save);
  listen(fillNameEl, "click", () => {
    const suggested = getSuggestedName();
    if (!suggested || !nameEl) return;
    nameEl.value = suggested;
    form.nameTouched = true;
    validate();
  });

  const touchName = () => {
    form.nameTouched = true;
    validate();
  };
  listen(nameEl, "input", touchName);
  listen(nameEl, "blur", touchName);
  listen(nameEl, "keydown", (event) => {
    if (event.key === "Enter" && !saveEl?.disabled) {
      event.preventDefault();
      save();
    }
  });

  listen(urlEl, "input", () => {
    form.urlTouched = true;
    if (form.isLocalFile && !urlEl.readOnly) {
      form.isLocalFile = false;
      setUrlLabel();
    }
    loadPreview(urlValue());
  });
  listen(urlEl, "blur", () => {
    form.urlTouched = true;
    showError(Boolean(urlValue()) && form.previewSettled && !form.previewLoaded);
    validate();
  });

  listen(uploadButtonEl, "click", () => {
    if (!fileInputEl) return;
    fileInputEl.value = "";
    fileInputEl.click();
  });
  listen(fileInputEl, "change", () => openFromFile(fileInputEl.files?.[0]));

  return Object.freeze({
    open,
    close,
    destroy() {
      close();
      while (disposers.length) disposers.pop()();
    },
  });
}

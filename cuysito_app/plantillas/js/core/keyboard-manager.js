/**
 * Atajos globales del editor.
 *
 * Reglas (RN-11):
 * - `Insert` o `A` (sin modificadores, fuera de campos editables) → abrir "Añadir tarjetas".
 * - `Ctrl/Cmd+D` → duplicar la selección (siempre con `preventDefault`, evita "Añadir marcador").
 * - Mientras exista un `<dialog open>` en el documento, los atajos globales
 *   (Escape, undo/redo, flechas, Delete, copiar/pegar…) se ceden al diálogo.
 *
 * Todos los callbacks son opcionales; el gestor sólo actúa cuando existen.
 */

const CAPTURE = Object.freeze({ capture: true });
const TEXT_INPUT_TYPES = new Set(["text", "search", "url", "email", "password", "tel", "number"]);
const BLUR_ON_CHANGE_INPUT_TYPES = new Set(["range", "color", "checkbox", "radio"]);
const KEY_REPEAT_MULTIPLIER = 10;

function normalizeKey(key) {
  return String(key || "").toLowerCase();
}

function asElement(target) {
  return target instanceof Element ? target : null;
}

export function isEditableTarget(target) {
  const el = asElement(target);
  if (!el) return false;
  if (el.isContentEditable || el.closest('[contenteditable="true"]')) return true;
  return Boolean(el.closest("input, textarea, select"));
}

export function isTextEditableTarget(target) {
  const el = asElement(target);
  if (!el) return false;
  if (el.isContentEditable || el.closest('[contenteditable="true"]')) return true;
  if (el.tagName === "TEXTAREA") return true;
  if (el.tagName === "INPUT") {
    return TEXT_INPUT_TYPES.has((el.getAttribute("type") || "text").toLowerCase());
  }
  return false;
}

export function hasOpenDialog(root = document) {
  return Boolean(root.querySelector("dialog[open]"));
}

export class KeyboardManager {
  constructor(options = {}) {
    this.getSelectedElement = options.getSelectedElement;
    this.nudgeSelected = options.nudgeSelected;
    this.hideSelected = options.hideSelected;
    this.copyStyle = options.copyStyle;
    this.pasteStyle = options.pasteStyle;
    this.copyCanvasImage = options.copyCanvasImage;
    this.exportPng = options.exportPng;
    this.deselect = options.deselect;
    this.toggleCommandBar = options.toggleCommandBar;
    this.isCommandBarOpen = options.isCommandBarOpen;
    this.openAddCards = options.openAddCards;
    this.duplicateSelected = options.duplicateSelected;
    this.history = options.history;

    this.isMac = /Mac|iPhone|iPad|iPod/i.test(navigator.platform);
    this.heldArrows = new Set();
    this.isMoveTransactionActive = false;
    this.styleClipboard = null;

    this.onKeyDown = this.onKeyDown.bind(this);
    this.onKeyUp = this.onKeyUp.bind(this);
    this.onBlur = this.onBlur.bind(this);
    this.onNonTextInputChange = this.onNonTextInputChange.bind(this);

    window.addEventListener("keydown", this.onKeyDown, CAPTURE);
    window.addEventListener("keyup", this.onKeyUp, CAPTURE);
    window.addEventListener("blur", this.onBlur);
    document.addEventListener("change", this.onNonTextInputChange, CAPTURE);
  }

  destroy() {
    window.removeEventListener("keydown", this.onKeyDown, CAPTURE);
    window.removeEventListener("keyup", this.onKeyUp, CAPTURE);
    window.removeEventListener("blur", this.onBlur);
    document.removeEventListener("change", this.onNonTextInputChange, CAPTURE);
    this.heldArrows.clear();
    this.isMoveTransactionActive = false;
  }

  /** Controles no textuales pierden el foco tras cambiar para que los atajos vuelvan al lienzo. */
  onNonTextInputChange(event) {
    const el = asElement(event.target);
    if (!el) return;
    if (el.tagName === "SELECT") {
      el.blur();
      return;
    }
    if (el.tagName === "INPUT" && BLUR_ON_CHANGE_INPUT_TYPES.has((el.getAttribute("type") || "").toLowerCase())) {
      el.blur();
    }
  }

  isEditableTarget(target) {
    return isEditableTarget(target);
  }

  isTextEditable(target) {
    return isTextEditableTarget(target);
  }

  hasPrimaryModifier(event) {
    return this.isMac ? event.metaKey : event.ctrlKey;
  }

  isUndoEvent(event) {
    return this.hasPrimaryModifier(event) && !event.shiftKey && normalizeKey(event.key) === "z";
  }

  isRedoEvent(event) {
    const key = normalizeKey(event.key);
    if (this.isMac) return event.metaKey && event.shiftKey && key === "z";
    return event.ctrlKey && (key === "y" || (event.shiftKey && key === "z"));
  }

  beginMoveTransaction() {
    if (this.isMoveTransactionActive) return;
    this.history?.beginTransaction?.();
    this.isMoveTransactionActive = true;
  }

  endMoveTransaction() {
    if (!this.isMoveTransactionActive) return;
    this.history?.endTransaction?.();
    this.isMoveTransactionActive = false;
  }

  onBlur() {
    this.heldArrows.clear();
    this.endMoveTransaction();
  }

  runInTransaction(work) {
    this.history?.beginTransaction?.();
    try {
      work();
    } finally {
      this.history?.endTransaction?.();
    }
  }

  onKeyDown(event) {
    if (!event) return;
    if (hasOpenDialog()) return;

    const key = normalizeKey(event.key);
    const primary = event.ctrlKey || event.metaKey;
    const plain = !primary && !event.altKey && !event.shiftKey;
    const textEditing = this.isTextEditable(event.target) || this.isTextEditable(document.activeElement);
    const editing = this.isEditableTarget(event.target) || this.isEditableTarget(document.activeElement);

    if (primary && !event.altKey && key === "s") {
      event.preventDefault();
      this.exportPng?.();
      return;
    }

    if (key === "f3" || (primary && !event.altKey && !event.shiftKey && key === "f")) {
      event.preventDefault();
      this.toggleCommandBar?.();
      return;
    }

    if (primary && !event.altKey && !event.shiftKey && key === "d") {
      event.preventDefault();
      if (!editing) this.duplicateSelected?.();
      return;
    }

    if (key === "escape") {
      event.preventDefault();
      if (this.isCommandBarOpen?.()) {
        this.toggleCommandBar?.();
        return;
      }
      const active = document.activeElement;
      if (active && typeof active.blur === "function") active.blur();
      this.deselect?.();
      return;
    }

    if (!editing && plain && (key === "insert" || key === "a")) {
      if (this.openAddCards) {
        event.preventDefault();
        this.openAddCards();
      }
      return;
    }

    if (!textEditing && this.isUndoEvent(event)) {
      event.preventDefault();
      this.history?.undo?.();
      return;
    }

    if (!textEditing && this.isRedoEvent(event)) {
      event.preventDefault();
      this.history?.redo?.();
      return;
    }

    if (editing) return;

    if (key === "backspace" || key === "delete") {
      if (!this.getSelectedElement?.()) return;
      event.preventDefault();
      this.runInTransaction(() => this.hideSelected?.());
      return;
    }

    if (primary && !event.altKey && !event.shiftKey && key === "c") {
      if (this.getSelectedElement?.()) {
        event.preventDefault();
        this.styleClipboard = this.copyStyle?.() ?? null;
      } else if (this.copyCanvasImage) {
        event.preventDefault();
        this.copyCanvasImage();
      }
      return;
    }

    if (primary && !event.altKey && !event.shiftKey && key === "v") {
      if (!this.getSelectedElement?.() || !this.styleClipboard) return;
      event.preventDefault();
      const clipboard = this.styleClipboard;
      this.runInTransaction(() => this.pasteStyle?.(clipboard));
      return;
    }

    if (!this.getSelectedElement?.()) return;

    const step = event.shiftKey ? KEY_REPEAT_MULTIPLIER : 1;
    let dx = 0;
    let dy = 0;
    if (key === "arrowleft") dx = -step;
    else if (key === "arrowright") dx = step;
    else if (key === "arrowup") dy = -step;
    else if (key === "arrowdown") dy = step;
    else return;

    event.preventDefault();
    this.heldArrows.add(key);
    this.beginMoveTransaction();
    this.nudgeSelected?.(dx, dy);
  }

  onKeyUp(event) {
    if (!event) return;
    const key = normalizeKey(event.key);
    if (!key.startsWith("arrow")) return;
    this.heldArrows.delete(key);
    if (this.heldArrows.size === 0) this.endMoveTransaction();
  }
}

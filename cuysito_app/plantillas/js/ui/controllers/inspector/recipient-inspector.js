/**
 * recipient-inspector.js
 *
 * Self-contained inspector panel for "PARA:" recipient headers in Cart Mode.
 *
 * Design principles:
 *   - Every event handler reads the current selection from the store at call-time.
 *     There are NO stale closures capturing a recipient index at setup time.
 *     This is why switching between multiple recipients always shows correct data.
 *
 *   - Smart sync-mode transitions (mirrors the branding bar):
 *       linked → independent  →  distributes masterColor/masterOpacity to both
 *                                labelColor/labelOpacity AND usernameColor/usernameOpacity
 *       independent → linked  →  promotes labelColor/labelOpacity back to masterColor/masterOpacity
 *
 *   - apply() receives the explicit recipientIndex and forceHydrate flag from the
 *     inspector controller — it never guesses the index from DOM state.
 *
 * Public API:  { apply(state, { recipientIndex, forceHydrate }), hide() }
 */

const DEFAULT_LABEL_COLOR    = "#FF5252";   // PARA: text
const DEFAULT_USERNAME_COLOR = "#CBD5E1";   // username text
const DEFAULT_OPACITY        = 0.78;
const SWATCH_LABEL_DEFAULT   = "#FF5252";
const SWATCH_USERNAME_DEFAULT = "#CBD5E1";

export function createRecipientInspector({
  store,
  normalizeMode,
  parseCollageSelection,
  setFieldValue: setFV,
  onInput,
  clamp,
  markInteracting,
  normalizeHex,
  isValidHex6,
  colorPickerService,
  setSwatchColor,
  // DOM elements ─────────────────────────────────────────────────────────
  recipientPanelEl,
  recipientSyncToggleEl,
  recipientMasterGroupEl,
  recipientMasterPickerEl,
  recipientMasterHexEl,
  recipientMasterOpacityEl,
  recipientIndependentGroupEl,
  recipientLabelPickerEl,
  recipientLabelHexEl,
  recipientLabelOpacityEl,
  recipientUsernamePickerEl,
  recipientUsernameHexEl,
  recipientUsernameOpacityEl,
  recipientOffsetXEl,
  recipientOffsetYEl,
} = {}) {

  if (!store) return Object.freeze({ apply() {}, hide() {} });

  // ── Live-reading state helpers ─────────────────────────────────────────
  // These always access the current store snapshot — never captured at setup time.

  const getMode = () => normalizeMode(store.getState()?.ui?.mode);

  const getSelectedIndex = () => {
    const parsed = parseCollageSelection(store.getState()?.ui?.selectedElement ?? "");
    return parsed?.kind === "recipient" ? parsed.index : -1;
  };

  const getConfig = (ri) => {
    const mode = getMode();
    const recs = store.getState()?.[mode]?.recipients;
    return (Array.isArray(recs) ? recs[ri] : null)?.config ?? {};
  };

  const setConfig = (ri, updater) => {
    const mode = getMode();
    store.setState(state => {
      const slice = state?.[mode] ?? {};
      const recs  = Array.isArray(slice.recipients) ? [...slice.recipients] : [];
      if (ri < 0 || ri >= recs.length) return state;
      const newCfg = updater(recs[ri]?.config ?? {});
      if (!newCfg) return state;
      recs[ri] = { ...recs[ri], config: newCfg };
      return { ...state, [mode]: { ...slice, recipients: recs } };
    });
  };

  // ── Sync-mode toggle ───────────────────────────────────────────────────

  recipientSyncToggleEl?.addEventListener("click", () => {
    markInteracting?.();
    const ri = getSelectedIndex();
    if (ri < 0) return;

    const cfg       = getConfig(ri);
    const wasLinked = cfg.syncMode !== "independent";
    const nowLinked = !wasLinked;

    setConfig(ri, c => {
      const next = { ...c, syncMode: nowLinked ? "linked" : "independent" };
      if (wasLinked && !nowLinked) {
        // linked → independent: fan-out master color and opacity to both parts
        const mc = c.masterColor   ?? DEFAULT_LABEL_COLOR;
        const mo = c.masterOpacity ?? DEFAULT_OPACITY;
        next.labelColor      = mc;
        next.labelOpacity    = mo;
        next.usernameColor   = mc;
        next.usernameOpacity = mo;
      } else if (!wasLinked && nowLinked) {
        // independent → linked: promote label color/opacity back to master
        next.masterColor   = c.labelColor   ?? DEFAULT_LABEL_COLOR;
        next.masterOpacity = c.labelOpacity ?? DEFAULT_OPACITY;
      }
      return next;
    });
  });

  // ── Master color (linked mode) ─────────────────────────────────────────

  if (recipientMasterPickerEl && colorPickerService) {
    recipientMasterPickerEl.addEventListener("click", () => {
      const ri  = getSelectedIndex();
      if (ri < 0) return;
      const cfg = getConfig(ri);
      colorPickerService.open(
        recipientMasterPickerEl,
        cfg.masterColor || DEFAULT_LABEL_COLOR,
        newColor => {
          markInteracting?.();
          setConfig(ri, c => ({
            ...c,
            masterColor:   newColor,
            labelColor:    newColor,
            usernameColor: newColor,
          }));
          setFV(recipientMasterHexEl, newColor, { force: true });
          setSwatchColor?.(recipientMasterPickerEl, newColor);
        }
      );
    });
  }

  recipientMasterHexEl?.addEventListener("input", onInput(e => {
    const val = String(e.target.value ?? "").trim();
    if (val.length < 4 || !isValidHex6(val)) return;
    const color = normalizeHex(val);
    const ri = getSelectedIndex();
    if (ri < 0) return;
    setConfig(ri, c => ({
      ...c,
      masterColor:   color,
      labelColor:    color,
      usernameColor: color,
    }));
    setSwatchColor?.(recipientMasterPickerEl, color);
  }));

  recipientMasterOpacityEl?.addEventListener("input", onInput(e => {
    markInteracting?.();
    const ri = getSelectedIndex();
    if (ri < 0) return;
    setConfig(ri, c => ({ ...c, masterOpacity: clamp(Number(e.target.value), 0, 1) }));
  }));

  // ── Label "PARA:" color (independent mode) ─────────────────────────────

  if (recipientLabelPickerEl && colorPickerService) {
    recipientLabelPickerEl.addEventListener("click", () => {
      const ri  = getSelectedIndex();
      if (ri < 0) return;
      const cfg = getConfig(ri);
      colorPickerService.open(
        recipientLabelPickerEl,
        cfg.labelColor || DEFAULT_LABEL_COLOR,
        newColor => {
          markInteracting?.();
          setConfig(ri, c => ({ ...c, labelColor: newColor }));
          setFV(recipientLabelHexEl, newColor, { force: true });
          setSwatchColor?.(recipientLabelPickerEl, newColor);
        }
      );
    });
  }

  recipientLabelHexEl?.addEventListener("input", onInput(e => {
    const val = String(e.target.value ?? "").trim();
    if (val.length < 4 || !isValidHex6(val)) return;
    const color = normalizeHex(val);
    const ri = getSelectedIndex();
    if (ri < 0) return;
    setConfig(ri, c => ({ ...c, labelColor: color }));
    setSwatchColor?.(recipientLabelPickerEl, color);
  }));

  recipientLabelOpacityEl?.addEventListener("input", onInput(e => {
    markInteracting?.();
    const ri = getSelectedIndex();
    if (ri < 0) return;
    setConfig(ri, c => ({ ...c, labelOpacity: clamp(Number(e.target.value), 0, 1) }));
  }));

  // ── Username color (independent mode) ──────────────────────────────────

  if (recipientUsernamePickerEl && colorPickerService) {
    recipientUsernamePickerEl.addEventListener("click", () => {
      const ri  = getSelectedIndex();
      if (ri < 0) return;
      const cfg = getConfig(ri);
      colorPickerService.open(
        recipientUsernamePickerEl,
        cfg.usernameColor || DEFAULT_USERNAME_COLOR,
        newColor => {
          markInteracting?.();
          setConfig(ri, c => ({ ...c, usernameColor: newColor }));
          setFV(recipientUsernameHexEl, newColor, { force: true });
          setSwatchColor?.(recipientUsernamePickerEl, newColor);
        }
      );
    });
  }

  recipientUsernameHexEl?.addEventListener("input", onInput(e => {
    const val = String(e.target.value ?? "").trim();
    if (val.length < 4 || !isValidHex6(val)) return;
    const color = normalizeHex(val);
    const ri = getSelectedIndex();
    if (ri < 0) return;
    setConfig(ri, c => ({ ...c, usernameColor: color }));
    setSwatchColor?.(recipientUsernamePickerEl, color);
  }));

  recipientUsernameOpacityEl?.addEventListener("input", onInput(e => {
    markInteracting?.();
    const ri = getSelectedIndex();
    if (ri < 0) return;
    setConfig(ri, c => ({ ...c, usernameOpacity: clamp(Number(e.target.value), 0, 1) }));
  }));

  // ── Offset X / Y (always visible) ──────────────────────────────────────

  recipientOffsetXEl?.addEventListener("input", onInput(e => {
    markInteracting?.();
    const ri = getSelectedIndex();
    if (ri < 0) return;
    setConfig(ri, c => ({ ...c, offsetX: Number(e.target.value) || 0 }));
  }));

  recipientOffsetYEl?.addEventListener("input", onInput(e => {
    markInteracting?.();
    const ri = getSelectedIndex();
    if (ri < 0) return;
    setConfig(ri, c => ({ ...c, offsetY: Number(e.target.value) || 0 }));
  }));

  // ── apply() ────────────────────────────────────────────────────────────
  /**
   * Hydrates the panel with data for the specified recipient.
   * Called by collage-inspector's apply() on every state/selection change.
   *
   * @param {object} state       - Current store state snapshot
   * @param {object} opts
   * @param {number} opts.recipientIndex   - Which recipient to display (0-based)
   * @param {boolean} [opts.forceHydrate]  - Force DOM update even if value looks equal
   */
  function apply(state, { recipientIndex: ri, forceHydrate: force = false } = {}) {
    if (!recipientPanelEl) return;

    if (typeof ri !== "number" || ri < 0) {
      recipientPanelEl.hidden = true;
      return;
    }

    const mode = normalizeMode(state?.ui?.mode);
    const recs = state?.[mode]?.recipients;
    const cfg  = (Array.isArray(recs) ? recs[ri] : null)?.config ?? {};
    const linked = cfg.syncMode !== "independent";

    recipientPanelEl.hidden = false;

    // Toggle button
    if (recipientSyncToggleEl) {
      recipientSyncToggleEl.setAttribute("aria-pressed", linked ? "true" : "false");
      recipientSyncToggleEl.textContent = linked ? "Activado" : "Desactivado";
    }

    // Show/hide the right color group
    if (recipientMasterGroupEl)      recipientMasterGroupEl.hidden      = !linked;
    if (recipientIndependentGroupEl) recipientIndependentGroupEl.hidden =  linked;

    if (linked) {
      const mc = cfg.masterColor   ?? DEFAULT_LABEL_COLOR;
      const mo = cfg.masterOpacity ?? DEFAULT_OPACITY;
      setFV(recipientMasterHexEl,     mc,              { force });
      setSwatchColor?.(recipientMasterPickerEl, cfg.masterColor ? mc : SWATCH_LABEL_DEFAULT);
      setFV(recipientMasterOpacityEl, clamp(mo, 0, 1), { force });
    } else {
      const lc = cfg.labelColor      ?? DEFAULT_LABEL_COLOR;
      const lo = cfg.labelOpacity    ?? DEFAULT_OPACITY;
      const uc = cfg.usernameColor   ?? DEFAULT_USERNAME_COLOR;
      const uo = cfg.usernameOpacity ?? DEFAULT_OPACITY;
      setFV(recipientLabelHexEl,        lc,               { force });
      setSwatchColor?.(recipientLabelPickerEl, cfg.labelColor ? lc : SWATCH_LABEL_DEFAULT);
      setFV(recipientLabelOpacityEl,    clamp(lo, 0, 1),  { force });
      setFV(recipientUsernameHexEl,     uc,               { force });
      setSwatchColor?.(recipientUsernamePickerEl, cfg.usernameColor ? uc : SWATCH_USERNAME_DEFAULT);
      setFV(recipientUsernameOpacityEl, clamp(uo, 0, 1),  { force });
    }

    // Offsets are always shown
    setFV(recipientOffsetXEl, cfg.offsetX ?? 0, { force });
    setFV(recipientOffsetYEl, cfg.offsetY ?? 0, { force });
  }

  function hide() {
    if (recipientPanelEl) recipientPanelEl.hidden = true;
  }

  return Object.freeze({ apply, hide });
}

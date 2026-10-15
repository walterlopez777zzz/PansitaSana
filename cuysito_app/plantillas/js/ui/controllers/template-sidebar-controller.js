function noop() {}

function identity(handler) {
  return handler;
}

export function createTemplateSidebarController(options = {}) {
  const {
    store,
    onInput,
    setFieldValue,
    setSwatchColor,
    normalizeHex,
    normalizeOptionalHex,
    parseOptionalInt,
    computeSoles,
    clamp,
    defaultAccentGradientHeight,
    defaultAccentGradientDensity,
    colorPickerService,
    setApplyingStateToUI,
    markInteracting,
    toggleHidden,
    renderPriceLock,
    renderGlobalDiscountControls,
    renderGradientList,
    syncGradientVisibilityButton,
    syncAccentVisibilityButton,
    dom = {},
  } = options;

  const {
    nameEl,
    typeEl,
    rarityEl,
    dateEl,
    vbucksEl,
    solesEl,
    conversionEl,
    giftLabelEl,
    gradientAddEl,
    accentVisibilityEl,
    accentPickerEl,
    accentHexEl,
    accentClearEl,
    accentHeightEl,
    accentDensityEl,
    accentAddEl,
    accentControlsEl,
    priceLockEl,
  } = dom;

  const wrapInput = typeof onInput === "function" ? onInput : identity;
  const updateField = typeof setFieldValue === "function" ? setFieldValue : noop;
  const updateSwatch = typeof setSwatchColor === "function" ? setSwatchColor : noop;
  const normalizeSolidHex = typeof normalizeHex === "function" ? normalizeHex : (value) => value;
  const normalizeOptional = typeof normalizeOptionalHex === "function"
    ? normalizeOptionalHex
    : (value) => value ?? null;
  const parseIntOrNull = typeof parseOptionalInt === "function"
    ? parseOptionalInt
    : (value) => value;
  const computeLockedSoles = typeof computeSoles === "function"
    ? computeSoles
    : () => 0;
  const clampNumber = typeof clamp === "function"
    ? clamp
    : (value) => value;
  const setApplyingState = typeof setApplyingStateToUI === "function"
    ? setApplyingStateToUI
    : noop;
  const setHiddenElement = typeof toggleHidden === "function" ? toggleHidden : noop;
  const setInteraction = typeof markInteracting === "function" ? markInteracting : noop;
  const renderLockState = typeof renderPriceLock === "function" ? renderPriceLock : noop;
  const renderDiscountState = typeof renderGlobalDiscountControls === "function"
    ? renderGlobalDiscountControls
    : noop;
  const renderTemplateGradientList = typeof renderGradientList === "function"
    ? renderGradientList
    : noop;
  const syncGradientVisibility = typeof syncGradientVisibilityButton === "function"
    ? syncGradientVisibilityButton
    : noop;
  const syncAccentVisibility = typeof syncAccentVisibilityButton === "function"
    ? syncAccentVisibilityButton
    : noop;

  function applyTemplateToInputs(template = {}) {
    setApplyingState(true);
    try {
      updateField(nameEl, template.itemName || "");
      updateField(typeEl, template.typeLabel || "");
      updateField(rarityEl, template.rarityLabel || "");
      updateField(dateEl, template.dateText || "");
      updateField(vbucksEl, template.vbucks ?? 0);
      updateField(conversionEl, template.conversionRate);
      updateField(giftLabelEl, template.giftLabel || "");
      updateField(solesEl, template.soles ?? null);
      renderDiscountState(template);

      const accentColor = normalizeOptional(template?.accentColor);
      const hasAccentColor = accentColor !== null;

      if (accentControlsEl) {
        accentControlsEl.hidden = !hasAccentColor;
      }

      if (accentAddEl) {
        accentAddEl.hidden = hasAccentColor;
      }

      if (hasAccentColor) {
        updateField(accentHexEl, accentColor);
        updateSwatch(accentPickerEl, normalizeSolidHex(accentColor));
      } else {
        updateField(accentHexEl, "");
        updateSwatch(accentPickerEl, "transparent");
      }

      updateField(
        accentHeightEl,
        clampNumber(Number(template?.accentGradientHeight ?? defaultAccentGradientHeight), 0, 0.5),
      );
      updateField(
        accentDensityEl,
        clampNumber(Number(template?.accentGradientDensity ?? defaultAccentGradientDensity), 0, 1),
      );
    } finally {
      setApplyingState(false);
    }
  }

  function syncState(template, state, { isInteracting = false } = {}) {
    applyTemplateToInputs(template);

    if (!isInteracting) {
      renderTemplateGradientList(template?.gradientColors);
    }

    syncAccentVisibility(state);
    syncGradientVisibility(state);
  }

  if (nameEl) {
    nameEl.addEventListener("input", wrapInput((event) => {
      store.update("template.itemName", event.target.value);
    }));
  }

  if (typeEl) {
    typeEl.addEventListener("input", wrapInput((event) => {
      store.update("template.typeLabel", event.target.value);
    }));
  }

  if (rarityEl) {
    rarityEl.addEventListener("input", wrapInput((event) => {
      store.update("template.rarityLabel", event.target.value);
    }));
  }

  if (dateEl) {
    dateEl.addEventListener("input", wrapInput((event) => {
      store.update("template.dateText", event.target.value);
    }));
  }

  if (vbucksEl) {
    vbucksEl.addEventListener("input", wrapInput((event) => {
      store.update("template.vbucks", Number(event.target.value) || 0);
    }));
  }

  if (solesEl) {
    solesEl.addEventListener("input", wrapInput((event) => {
      store.update("template.soles", parseIntOrNull(event.target.value));
    }));
  }

  if (conversionEl) {
    conversionEl.addEventListener("input", wrapInput((event) => {
      store.update("template.conversionRate", Number(event.target.value));
    }));
  }

  if (giftLabelEl) {
    giftLabelEl.addEventListener("input", wrapInput((event) => {
      store.update("template.giftLabel", event.target.value);
    }));
  }

  if (accentPickerEl && colorPickerService) {
    accentPickerEl.addEventListener("click", () => {
      colorPickerService.open(accentPickerEl, accentHexEl?.value || "#000000", (nextColor) => {
        const color = normalizeSolidHex(nextColor);
        if (accentHexEl) {
          accentHexEl.value = color;
        }
        store.update("template.accentColor", color);
      });
    });
  }

  if (accentVisibilityEl) {
    accentVisibilityEl.addEventListener("click", () => {
      setInteraction(180);
      setHiddenElement("accent");
    });
  }

  if (accentHexEl) {
    accentHexEl.addEventListener("input", wrapInput((event) => {
      const nextValue = String(event.target.value ?? "").trim();
      if (!nextValue) {
        store.update("template.accentColor", null);
        return;
      }
      if (nextValue.length < 4) {
        return;
      }
      const normalized = normalizeOptional(nextValue);
      if (normalized) {
        store.update("template.accentColor", normalized);
      }
    }));
  }

  if (accentClearEl) {
    accentClearEl.addEventListener("click", () => {
      store.update("template.accentColor", null);
    });
  }

  if (accentAddEl) {
    accentAddEl.addEventListener("click", () => {
      store.update("template.accentColor", "#000000");
    });
  }

  if (accentHeightEl) {
    accentHeightEl.addEventListener("input", wrapInput((event) => {
      const nextValue = Number(event.target.value);
      store.update(
        "template.accentGradientHeight",
        clampNumber(Number.isFinite(nextValue) ? nextValue : defaultAccentGradientHeight, 0, 0.5),
      );
    }));
  }

  if (accentDensityEl) {
    accentDensityEl.addEventListener("input", wrapInput((event) => {
      const nextValue = Number(event.target.value);
      store.update(
        "template.accentGradientDensity",
        clampNumber(Number.isFinite(nextValue) ? nextValue : defaultAccentGradientDensity, 0, 1),
      );
    }));
  }

  if (priceLockEl) {
    priceLockEl.addEventListener("click", () => {
      store.setState((state) => {
        const nextPricesLocked = !(state.ui?.pricesLocked !== false);
        const vbucks = Number(state.template?.vbucks || 0);
        const conversionRate = state.template?.conversionRate;
        const nextState = {
          ...state,
          ui: {
            ...(state.ui || {}),
            pricesLocked: nextPricesLocked,
          },
        };

        if (nextPricesLocked) {
          nextState.template = {
            ...state.template,
            soles: computeLockedSoles(vbucks, conversionRate),
          };
        }

        return nextState;
      });
    });
  }

  if (gradientAddEl) {
    gradientAddEl.addEventListener("click", () => {
      store.setState((state) => {
        const gradientColors = [
          ...(
            Array.isArray(state.template?.gradientColors)
              ? state.template.gradientColors
              : []
          ),
          "#000000",
        ];

        return {
          ...state,
          template: {
            ...state.template,
            gradientColors,
          },
        };
      });
    });
  }

  return Object.freeze({
    applyTemplateToInputs,
    syncState,
    renderPriceLock: renderLockState,
  });
}

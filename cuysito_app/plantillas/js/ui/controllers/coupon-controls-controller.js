function toArray(value) {
  return Array.isArray(value) ? value : [];
}

function swapItems(values, fromIndex, toIndex) {
  const nextValues = [...toArray(values)];
  [nextValues[fromIndex], nextValues[toIndex]] = [nextValues[toIndex], nextValues[fromIndex]];
  return nextValues;
}

function removeIndex(values, index) {
  return toArray(values).filter((_, currentIndex) => currentIndex !== index);
}

export function createCouponControlsController({
  store,
  buildGradientRow,
  markInteracting,
  defaultCouponPath,
  dom = {},
} = {}) {
  const {
    backgroundGradientListEl,
    backgroundGradientAddEl,
    bodyGradientListEl,
    bodyGradientAddEl,
    customPathEl,
    customPathErrorEl,
  } = dom;

  const notifyInteracting = typeof markInteracting === "function" ? markInteracting : () => {};
  const fallbackCouponPath = String(defaultCouponPath || "");

  const setPathValidity = isValid => {
    if (!customPathErrorEl) {
      return;
    }

    customPathErrorEl.textContent = isValid ? "" : "Trazado SVG inválido";
    customPathErrorEl.hidden = isValid;
  };

  const validateCustomPath = value => {
    let isValid = true;

    if (value && typeof Path2D === "function") {
      try {
        new Path2D(value);
      } catch {
        isValid = false;
      }
    }

    setPathValidity(isValid);
    return isValid;
  };

  const renderGradientList = (listEl, colorsKey, hiddenKey) => {
    if (!listEl || typeof buildGradientRow !== "function" || !store?.setState) {
      return;
    }

    listEl.textContent = "";

    const state = store.getState();
    const couponState = state?.coupon || {};
    const colors = toArray(couponState[colorsKey]);
    const hiddenStops = toArray(couponState[hiddenKey]);

    const handlers = {
      onChange(index, nextColor) {
        notifyInteracting(180);
        store.setState(currentState => {
          const sourceColors = toArray(currentState?.coupon?.[colorsKey]);
          return {
            ...currentState,
            coupon: {
              ...(currentState?.coupon || {}),
              [colorsKey]: sourceColors.map((color, currentIndex) => {
                return currentIndex === index ? nextColor : color;
              }),
            },
          };
        });
      },
      onMove(index, direction) {
        store.setState(currentState => {
          const sourceColors = toArray(currentState?.coupon?.[colorsKey]);
          const targetIndex = index + direction;
          if (targetIndex < 0 || targetIndex >= sourceColors.length) {
            return currentState;
          }

          return {
            ...currentState,
            coupon: {
              ...(currentState?.coupon || {}),
              [colorsKey]: swapItems(sourceColors, index, targetIndex),
              [hiddenKey]: swapItems(toArray(currentState?.coupon?.[hiddenKey]), index, targetIndex),
            },
          };
        });
      },
      onRemove(index) {
        store.setState(currentState => {
          return {
            ...currentState,
            coupon: {
              ...(currentState?.coupon || {}),
              [colorsKey]: removeIndex(currentState?.coupon?.[colorsKey], index),
              [hiddenKey]: removeIndex(currentState?.coupon?.[hiddenKey], index),
            },
          };
        });
      },
      onToggleHidden(index) {
        notifyInteracting(180);
        store.setState(currentState => {
          const nextHiddenStops = [...toArray(currentState?.coupon?.[hiddenKey])];
          nextHiddenStops[index] = !nextHiddenStops[index];
          return {
            ...currentState,
            coupon: {
              ...(currentState?.coupon || {}),
              [hiddenKey]: nextHiddenStops,
            },
          };
        });
      },
    };

    colors.forEach((color, index) => {
      const row = buildGradientRow(index, color, handlers, Boolean(hiddenStops[index]));
      listEl.appendChild(row);
    });
  };

  const renderLists = () => {
    renderGradientList(backgroundGradientListEl, "backgroundGradientColors", "backgroundStopsHidden");
    renderGradientList(bodyGradientListEl, "bodyGradientColors", "bodyStopsHidden");
  };

  const addGradientStop = colorsKey => {
    store?.setState?.(state => {
      const sourceColors = toArray(state?.coupon?.[colorsKey]);
      return {
        ...state,
        coupon: {
          ...(state?.coupon || {}),
          [colorsKey]: [...sourceColors, "#000000"],
        },
      };
    });
  };

  const syncCustomPath = customPath => {
    if (customPathEl && document.activeElement !== customPathEl) {
      customPathEl.value = customPath || fallbackCouponPath;
    }

    validateCustomPath(customPathEl?.value?.trim?.() ?? "");
  };

  const handleCustomPathInput = () => {
    const value = String(customPathEl?.value || "").trim();
    validateCustomPath(value);
    store?.setState?.(state => ({
      ...state,
      coupon: {
        ...(state?.coupon || {}),
        customPath: value,
      },
    }));
  };

  backgroundGradientAddEl?.addEventListener("click", () => {
    addGradientStop("backgroundGradientColors");
  });

  bodyGradientAddEl?.addEventListener("click", () => {
    addGradientStop("bodyGradientColors");
  });

  customPathEl?.addEventListener("input", handleCustomPathInput);

  return Object.freeze({
    renderLists,
    syncCustomPath,
    destroy() {
      customPathEl?.removeEventListener("input", handleCustomPathInput);
    },
  });
}

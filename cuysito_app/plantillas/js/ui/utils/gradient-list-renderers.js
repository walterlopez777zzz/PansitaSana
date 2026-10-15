import { createVisibilityToggle } from "../components/visibility-toggle.js";

function toArray(value) {
  return Array.isArray(value) ? value : [];
}

function swapItems(values, fromIndex, toIndex) {
  const nextValues = [...toArray(values)];
  const currentValue = nextValues[fromIndex];
  nextValues[fromIndex] = nextValues[toIndex];
  nextValues[toIndex] = currentValue;
  return nextValues;
}

function toggleIndex(values, index) {
  const nextValues = [...toArray(values)];
  nextValues[index] = !nextValues[index];
  return nextValues;
}

function removeIndex(values, index) {
  return toArray(values).filter((_, currentIndex) => currentIndex !== index);
}

function updateCardStyle(item, cardPatch) {
  return {
    ...item,
    style: {
      ...(item?.style || {}),
      card: {
        ...(item?.style?.card || {}),
        ...cardPatch,
      },
    },
  };
}

function prependBackgroundToggle(row, { hidden, onToggle } = {}) {
  const controls = row?.querySelector?.(".gradient-row__controls");
  if (!controls) {
    return null;
  }

  const visibilityToggle = createVisibilityToggle({
    hidden,
    labelVisible: "Ocultar fondo",
    labelHidden: "Mostrar fondo",
  });

  visibilityToggle.el.addEventListener("click", () => {
    onToggle?.();
  });

  controls.prepend(visibilityToggle.el);
  return visibilityToggle;
}

export function createTemplateGradientControls(options = {}) {
  const {
    store,
    gradientListEl,
    buildGradientRow,
    isHidden,
    toggleHidden,
    markInteracting,
  } = options;

  const notifyInteracting = typeof markInteracting === "function" ? markInteracting : () => {};
  const isElementHidden = typeof isHidden === "function" ? isHidden : () => false;
  const toggleElementHidden = typeof toggleHidden === "function" ? toggleHidden : () => {};

  let visibilityToggle = null;

  return {
    renderGradientList(gradientColors) {
      if (!gradientListEl || typeof buildGradientRow !== "function" || !store?.setState) {
        return;
      }

      gradientListEl.textContent = "";

      const colors = toArray(gradientColors);
      const hiddenStops = toArray(store.getState()?.template?.gradientStopsHidden);

      visibilityToggle = null;

      const handlers = {
        onChange(index, nextColor) {
          notifyInteracting(180);
          store.setState((state) => {
            const sourceColors = toArray(state?.template?.gradientColors);
            const nextColors = sourceColors.map((color, currentIndex) => {
              return currentIndex === index ? nextColor : color;
            });

            return {
              ...state,
              template: {
                ...(state?.template || {}),
                gradientColors: nextColors,
              },
            };
          });
        },
        onMove(index, direction) {
          store.setState((state) => {
            const sourceColors = toArray(state?.template?.gradientColors);
            const targetIndex = index + direction;
            if (targetIndex < 0 || targetIndex >= sourceColors.length) {
              return state;
            }

            const nextColors = swapItems(sourceColors, index, targetIndex);
            const nextHiddenStops = swapItems(
              toArray(state?.template?.gradientStopsHidden),
              index,
              targetIndex,
            );

            return {
              ...state,
              template: {
                ...(state?.template || {}),
                gradientColors: nextColors,
                gradientStopsHidden: nextHiddenStops,
              },
            };
          });
        },
        onRemove(index) {
          store.setState((state) => {
            return {
              ...state,
              template: {
                ...(state?.template || {}),
                gradientColors: removeIndex(state?.template?.gradientColors, index),
                gradientStopsHidden: removeIndex(state?.template?.gradientStopsHidden, index),
              },
            };
          });
        },
        onToggleHidden(index) {
          notifyInteracting(180);
          store.setState((state) => {
            return {
              ...state,
              template: {
                ...(state?.template || {}),
                gradientStopsHidden: toggleIndex(state?.template?.gradientStopsHidden, index),
              },
            };
          });
        },
      };

      colors.forEach((color, index) => {
        const row = buildGradientRow(index, color, handlers, Boolean(hiddenStops[index]));

        if (index === 0) {
          visibilityToggle = prependBackgroundToggle(row, {
            hidden: isElementHidden(store.getState(), "background"),
            onToggle() {
              notifyInteracting(180);
              toggleElementHidden("background");
            },
          });
        }

        gradientListEl.appendChild(row);
      });
    },
    syncGradientVisibilityButton(state) {
      if (!visibilityToggle) {
        return;
      }

      visibilityToggle.setHidden(isElementHidden(state, "background"));
    },
  };
}

export function createCollageCardGradientListRenderer(options = {}) {
  const { inspectorCollageCardGradientListEl, buildGradientRow, updateCollageItem } = options;

  let isApplyingChange = false;

  return {
    renderCollageCardGradientList(selectionId, gradientColors, gradientStopsHidden) {
      if (isApplyingChange) {
        return;
      }

      if (
        !inspectorCollageCardGradientListEl ||
        typeof buildGradientRow !== "function" ||
        typeof updateCollageItem !== "function"
      ) {
        return;
      }

      inspectorCollageCardGradientListEl.textContent = "";

      const colors = toArray(gradientColors);
      const hiddenStops = toArray(gradientStopsHidden);

      const handlers = {
        onChange(index, nextColor) {
          isApplyingChange = true;
          try {
            updateCollageItem(selectionId, (item) => {
              const sourceColors = Array.isArray(item?.style?.card?.gradientColors)
                ? item.style.card.gradientColors
                : colors;

              return updateCardStyle(item, {
                gradientColors: sourceColors.map((color, currentIndex) => {
                  return currentIndex === index ? nextColor : color;
                }),
              });
            });
          } finally {
            isApplyingChange = false;
          }
        },
        onMove(index, direction) {
          updateCollageItem(selectionId, (item) => {
            const sourceColors = Array.isArray(item?.style?.card?.gradientColors)
              ? item.style.card.gradientColors
              : colors;
            const targetIndex = index + direction;

            if (targetIndex < 0 || targetIndex >= sourceColors.length) {
              return item;
            }

            return updateCardStyle(item, {
              gradientColors: swapItems(sourceColors, index, targetIndex),
              gradientStopsHidden: swapItems(
                Array.isArray(item?.style?.card?.gradientStopsHidden)
                  ? item.style.card.gradientStopsHidden
                  : hiddenStops,
                index,
                targetIndex,
              ),
            });
          });
        },
        onRemove(index) {
          updateCollageItem(selectionId, (item) => {
            return updateCardStyle(item, {
              gradientColors: removeIndex(item?.style?.card?.gradientColors ?? colors, index),
              gradientStopsHidden: removeIndex(
                item?.style?.card?.gradientStopsHidden ?? hiddenStops,
                index,
              ),
            });
          });
        },
        onToggleHidden(index) {
          updateCollageItem(selectionId, (item) => {
            return updateCardStyle(item, {
              gradientStopsHidden: toggleIndex(
                item?.style?.card?.gradientStopsHidden ?? hiddenStops,
                index,
              ),
            });
          });
        },
      };

      colors.forEach((color, index) => {
        const row = buildGradientRow(index, color, handlers, Boolean(hiddenStops[index]));
        inspectorCollageCardGradientListEl.appendChild(row);
      });
    },
  };
}

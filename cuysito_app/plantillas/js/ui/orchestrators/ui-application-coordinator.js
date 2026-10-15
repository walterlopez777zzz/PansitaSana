function fallbackQueueMicrotask(callback) {
  Promise.resolve().then(callback);
}

export function createUiApplicationCoordinator({
  store,
  queueMicrotaskImpl,
} = {}) {
  const queueMicrotaskSafe = typeof queueMicrotaskImpl === "function"
    ? queueMicrotaskImpl
    : (typeof queueMicrotask === "function" ? queueMicrotask : fallbackQueueMicrotask);

  let isApplyingStateToUI = false;
  let inspectorController = null;
  let isInspectorRefreshQueued = false;
  let markInteracting = () => {};

  const refreshInspectorNow = () => {
    isInspectorRefreshQueued = false;

    if (!inspectorController || typeof inspectorController.apply !== "function") {
      return;
    }

    const previousApplyingState = isApplyingStateToUI;
    isApplyingStateToUI = true;
    try {
      inspectorController.apply(store?.getState?.());
    } finally {
      isApplyingStateToUI = previousApplyingState;
    }
  };

  const refreshInspector = () => {
    if (isInspectorRefreshQueued) {
      return;
    }

    isInspectorRefreshQueued = true;
    queueMicrotaskSafe(refreshInspectorNow);
  };

  const onInput = handler => event => {
    if (isApplyingStateToUI) {
      return;
    }

    markInteracting();
    handler(event);
  };

  return Object.freeze({
    bindInspector(nextInspectorController) {
      inspectorController = nextInspectorController || null;
    },
    setMarkInteracting(nextMarkInteracting) {
      markInteracting = typeof nextMarkInteracting === "function" ? nextMarkInteracting : () => {};
    },
    onInput,
    refreshInspector,
    refreshInspectorNow,
    setApplyingStateToUI(nextValue) {
      isApplyingStateToUI = nextValue === true;
    },
    getIsApplyingStateToUI() {
      return isApplyingStateToUI;
    },
  });
}

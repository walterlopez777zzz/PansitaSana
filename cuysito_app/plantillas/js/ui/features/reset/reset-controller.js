import { attachDialogDismiss } from "../../utils/dialog-behavior.js";

function performDefaultReset(store) {
  const state = store?.getState?.();
  const offerId = state?.selection?.offerId;
  if (!offerId) {
    return;
  }

  const defaults = state?.defaults?.templateByOfferId?.[offerId];
  if (!defaults) {
    return;
  }

  store?.setState?.((currentState) => {
    if (currentState?.selection?.offerId !== offerId) {
      return currentState;
    }

    return {
      ...currentState,
      template: {
        ...defaults,
      },
    };
  });
}

function buildResetMessage(mode) {
  if (mode === "collage") {
    return "¿Seguro que quieres restablecer todos los cambios del collage? Las cartas se conservarán.";
  }

  if (mode === "cart") {
    return "¿Seguro que quieres restablecer todos los cambios del carrito? Las cartas se conservarán.";
  }

  return "¿Seguro que quieres restablecer todos los cambios al estado por defecto del ítem?";
}

export function createResetController({
  store,
  performReset,
  resetAllEl,
  resetDialogEl,
  resetCancelEl,
  resetConfirmEl,
  heroEl,
  gridSectionEl,
  normalizeMode,
  attachDialogDismissFn = attachDialogDismiss,
  IntersectionObserverImpl,
  windowImpl,
} = {}) {
  const browserDocument = typeof document !== "undefined" ? document : null;
  const browserWindow = windowImpl || (typeof window !== "undefined" ? window : null);
  const ObserverCtor = IntersectionObserverImpl || (typeof IntersectionObserver !== "undefined" ? IntersectionObserver : null);
  const resolvedHeroEl = heroEl || browserDocument?.getElementById?.("hero");
  const resolvedGridSectionEl = gridSectionEl || browserDocument?.querySelector?.(".grid-section");
  const resolveMode = typeof normalizeMode === "function"
    ? normalizeMode
    : (value) => value || "individual";
  const runReset = typeof performReset === "function"
    ? performReset
    : () => performDefaultReset(store);
  const closeDialog = () => {
    if (typeof resetDialogEl?.close === "function") {
      resetDialogEl.close();
    }
  };
  const handleDialogDismiss = typeof attachDialogDismissFn === "function"
    ? attachDialogDismissFn(resetDialogEl, closeDialog)
    : { destroy() {} };

  const openResetDialog = () => {
    const state = store?.getState?.() || {};
    const mode = resolveMode(state?.ui?.mode);
    if (mode === "individual" && !state?.selection?.offerId) {
      return;
    }

    const message = buildResetMessage(mode);
    const dialogBodyEl = resetDialogEl?.querySelector?.(".dialog__body");
    if (dialogBodyEl) {
      dialogBodyEl.textContent = message;
    }

    if (typeof resetDialogEl?.showModal === "function") {
      resetDialogEl.showModal();
      return;
    }

    if (browserWindow?.confirm?.(message)) {
      runReset();
    }
  };

  const confirmReset = () => {
    closeDialog();
    runReset();
  };

  resetAllEl?.addEventListener("click", openResetDialog);
  resetCancelEl?.addEventListener("click", closeDialog);
  resetConfirmEl?.addEventListener("click", confirmReset);

  let observer = null;
  let handleScroll = null;

  if (resolvedHeroEl && resolvedGridSectionEl && resetAllEl) {
    const setHidden = hidden => {
      resetAllEl.classList.toggle("reset-fab--hidden", !!hidden);
    };

    let heroVisible = true;
    let gridVisible = false;
    const syncVisibility = () => {
      setHidden(!heroVisible || gridVisible);
    };

    if (ObserverCtor) {
      observer = new ObserverCtor(entries => {
        for (const entry of entries) {
          if (entry.target === resolvedHeroEl) {
            heroVisible = entry.isIntersecting;
          }
          if (entry.target === resolvedGridSectionEl) {
            gridVisible = entry.isIntersecting;
          }
        }

        syncVisibility();
      }, { threshold: 0.01 });

      observer.observe(resolvedHeroEl);
      observer.observe(resolvedGridSectionEl);
    } else if (browserWindow) {
      handleScroll = () => {
        const heroRect = resolvedHeroEl.getBoundingClientRect();
        heroVisible = heroRect.top < browserWindow.innerHeight && heroRect.bottom > 0;

        const gridRect = resolvedGridSectionEl.getBoundingClientRect();
        gridVisible = gridRect.top < browserWindow.innerHeight && gridRect.bottom > 0;

        syncVisibility();
      };

      browserWindow.addEventListener("scroll", handleScroll, { passive: true });
      handleScroll();
    }
  }

  return Object.freeze({
    performReset: runReset,
    performResetToDefaults: runReset,
    destroy() {
      resetAllEl?.removeEventListener("click", openResetDialog);
      resetCancelEl?.removeEventListener("click", closeDialog);
      resetConfirmEl?.removeEventListener("click", confirmReset);
      handleDialogDismiss.destroy();

      if (observer && typeof observer.disconnect === "function") {
        observer.disconnect();
      }
      observer = null;

      if (handleScroll && browserWindow) {
        browserWindow.removeEventListener("scroll", handleScroll);
      }
      handleScroll = null;
    },
  });
}
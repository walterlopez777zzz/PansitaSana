const READY_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>';
const INJECTION_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg>';

function hasProtectedUiState(store) {
  const state = store?.getState?.() || {};
  return Boolean(
    state?.selection?.offerId
    || state?.collage?.items?.length > 0
    || state?.cart?.items?.length > 0
  );
}

export function createShopSyncStatusController({
  store,
  shopSyncService,
  applyShopData,
  mergeShopItems,
  statusEl,
} = {}) {
  if (!shopSyncService || !statusEl) {
    return Object.freeze({
      destroy() {},
    });
  }

  const consumePendingData = action => {
    const pending = shopSyncService.consumePendingData();
    if (!pending) {
      return;
    }

    const { data, diffResult } = pending;
    if (action === "merge" && diffResult?.kind === "injection" && typeof mergeShopItems === "function") {
      mergeShopItems(data, diffResult);
      return;
    }

    if (typeof applyShopData === "function") {
      applyShopData(data);
    }
  };

  let lastStatus = "";
  let lastMessage = "";
  let lastAddedNames = null;
  let messageEl = null;

  const renderState = state => {
    const {
      status,
      message,
      addedNames,
    } = state || {};

    if (status === "idle" || !message) {
      if (lastStatus !== "idle") {
        statusEl.hidden = true;
        statusEl.textContent = "";
        lastStatus = "idle";
        lastMessage = "";
        lastAddedNames = null;
        messageEl = null;
      }
      return;
    }

    if (status === lastStatus && addedNames === lastAddedNames && message !== lastMessage) {
      lastMessage = message;
      if (messageEl) {
        messageEl.textContent = message;
      }
      return;
    }

    lastStatus = status;
    lastMessage = message;
    lastAddedNames = addedNames || null;
    statusEl.hidden = false;
    statusEl.textContent = "";

    const iconEl = document.createElement("div");
    iconEl.className = "shop-sync-status__icon";
    if (status === "watching") {
      const spinnerEl = document.createElement("div");
      spinnerEl.className = "shop-sync-status__spinner";
      iconEl.appendChild(spinnerEl);
    } else if (status === "injection") {
      iconEl.innerHTML = INJECTION_ICON;
    } else if (status === "ready") {
      iconEl.innerHTML = READY_ICON;
    }

    const bodyEl = document.createElement("div");
    bodyEl.className = "shop-sync-status__body";

    const textEl = document.createElement("span");
    textEl.className = "shop-sync-status__text";
    textEl.textContent = message;
    messageEl = textEl;
    bodyEl.appendChild(textEl);

    if (status === "injection" && addedNames && addedNames.length > 0) {
      const detailsEl = document.createElement("details");
      detailsEl.className = "shop-sync-status__details";

      const summaryEl = document.createElement("summary");
      summaryEl.className = "shop-sync-status__summary";
      summaryEl.textContent = "Ver artículos";
      detailsEl.appendChild(summaryEl);

      const listEl = document.createElement("ul");
      listEl.className = "shop-sync-status__list";
      for (const name of addedNames) {
        const itemEl = document.createElement("li");
        itemEl.className = "shop-sync-status__list-item";
        itemEl.textContent = name;
        listEl.appendChild(itemEl);
      }

      detailsEl.appendChild(listEl);
      bodyEl.appendChild(detailsEl);
    }

    statusEl.append(iconEl, bodyEl);

    if (status === "injection") {
      const actionEl = document.createElement("button");
      actionEl.type = "button";
      actionEl.className = "shop-sync-status__action";
      actionEl.textContent = "Incorporar";
      actionEl.addEventListener("click", () => {
        consumePendingData("merge");
      });
      statusEl.appendChild(actionEl);
      return;
    }

    if (status === "ready") {
      if (hasProtectedUiState(store)) {
        const actionEl = document.createElement("button");
        actionEl.type = "button";
        actionEl.className = "shop-sync-status__action";
        actionEl.textContent = "Actualizar";
        actionEl.addEventListener("click", () => {
          consumePendingData("replace");
        });
        statusEl.appendChild(actionEl);
      } else {
        consumePendingData("replace");
      }
    }
  };

  const unsubscribe = shopSyncService.subscribe(renderState);
  renderState(shopSyncService.getState());

  return Object.freeze({
    destroy() {
      if (typeof unsubscribe === "function") {
        unsubscribe();
      }
      statusEl.hidden = true;
      statusEl.textContent = "";
    },
  });
}

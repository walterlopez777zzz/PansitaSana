function noop() {}

function scheduleCanvas(renderScheduler) {
  if (!renderScheduler?.scheduleCanvasRender) {
    return;
  }

  renderScheduler.scheduleCanvasRender(renderScheduler.isInteracting ? "preview" : "final");
}

export function registerUiSubscribers({
  createDerivedSubscriber,
  store,
  setStatus,
  templateSidebar,
  renderScheduler,
  renderLayers,
  syncThumbnails,
  renderPriceLock,
  computeSoles,
  tabsController,
  refreshInspector,
  itemPicker,
  modeController,
  applyGridToInputs,
  renderCouponLists,
  syncCouponPathInput,
  applyBrandingToInputs,
  normalizeMode,
  isCouponMode,
  renderCartRecipients,
} = {}) {
  if (!store?.getState || typeof createDerivedSubscriber !== "function") {
    return [];
  }

  const updateStatus = typeof setStatus === "function" ? setStatus : noop;
  const syncTemplateSidebar = template => {
    templateSidebar?.syncState?.(template, store.getState(), {
      isInteracting: !!renderScheduler?.isInteracting,
    });
  };
  const renderLayerList = typeof renderLayers === "function" ? renderLayers : noop;
  const syncLayerThumbnails = typeof syncThumbnails === "function" ? syncThumbnails : noop;
  const renderLockState = typeof renderPriceLock === "function" ? renderPriceLock : noop;
  const computeLockedSoles = typeof computeSoles === "function" ? computeSoles : () => 0;
  const refresh = typeof refreshInspector === "function" ? refreshInspector : noop;
  const renderPicker = state => itemPicker?.render?.(state);
  const applyModeToUI = mode => modeController?.applyModeToUI?.(mode);
  const applyGrid = typeof applyGridToInputs === "function" ? applyGridToInputs : noop;
  const renderCoupons = typeof renderCouponLists === "function" ? renderCouponLists : noop;
  const syncCouponPath = typeof syncCouponPathInput === "function" ? syncCouponPathInput : noop;
  const applyBranding = typeof applyBrandingToInputs === "function" ? applyBrandingToInputs : noop;
  const normalizeUiMode = typeof normalizeMode === "function" ? normalizeMode : value => value;
  const isCouponUiMode = typeof isCouponMode === "function" ? isCouponMode : value => value === "coupon";
  const syncCartRecipients = typeof renderCartRecipients === "function" ? renderCartRecipients : noop;

  return Object.freeze([
    createDerivedSubscriber(
      store,
      state => state.status,
      status => {
        if (status?.loading) {
          updateStatus("Cargando tienda…");
          return;
        }

        if (status?.error) {
          updateStatus(status.error);
          return;
        }

        updateStatus("");
      },
      {
        equals: (next, previous) => next.loading === previous.loading && next.error === previous.error,
      },
    ),
    createDerivedSubscriber(store, state => state.template, template => {
      syncTemplateSidebar(template);
      refresh();
    }),
    createDerivedSubscriber(
      store,
      state => ({
        layers: Array.isArray(state?.template?.imageLayers) ? state.template.imageLayers : [],
        itemName: state?.template?.itemName || "",
        selected: state?.ui?.selectedElement || null,
      }),
      () => {
        renderLayerList(store.getState());
      },
      {
        equals: (next, previous) => {
          if (next.selected !== previous.selected) {
            return false;
          }

          if (String(next.itemName || "") !== String(previous.itemName || "")) {
            return false;
          }

          const nextLayers = Array.isArray(next.layers) ? next.layers : [];
          const previousLayers = Array.isArray(previous.layers) ? previous.layers : [];
          if (nextLayers.length !== previousLayers.length) {
            return false;
          }

          for (let index = 0; index < nextLayers.length; index += 1) {
            const nextLayer = nextLayers[index] || {};
            const previousLayer = previousLayers[index] || {};
            if (String(nextLayer.id || "") !== String(previousLayer.id || "")) {
              return false;
            }
            if (String(nextLayer.url || "") !== String(previousLayer.url || "")) {
              return false;
            }
            if (String(nextLayer.name || "") !== String(previousLayer.name || "")) {
              return false;
            }
            if ((nextLayer.visible !== false) !== (previousLayer.visible !== false)) {
              return false;
            }
          }

          return true;
        },
      },
    ),
    createDerivedSubscriber(
      store,
      state => ({
        offerId: state?.selection?.offerId || "",
        layers: Array.isArray(state?.template?.imageLayers) ? state.template.imageLayers : [],
      }),
      snapshot => {
        syncLayerThumbnails(snapshot);
      },
      {
        equals: (next, previous) => next.offerId === previous.offerId && next.layers === previous.layers,
      },
    ),
    createDerivedSubscriber(store, state => state.ui?.pricesLocked, pricesLocked => {
      const locked = pricesLocked !== false;
      renderLockState(locked);
      if (!locked) {
        return;
      }

      const state = store.getState();
      store.update("template.soles", computeLockedSoles(state.template?.vbucks, state.template?.conversionRate));
    }),
    createDerivedSubscriber(
      store,
      state => ({
        vbucks: state.template?.vbucks,
        conversionRate: state.template?.conversionRate,
        individualDiscount: state.template?.individualDiscount ?? state.template?.discount,
        pricesLocked: state.ui?.pricesLocked,
      }),
      priceState => {
        if (priceState?.pricesLocked === false) {
          return;
        }

        store.update("template.soles", computeLockedSoles(priceState?.vbucks, priceState?.conversionRate));
      },
      {
        equals: (next, previous) => (
          next.vbucks === previous.vbucks
          && next.conversionRate === previous.conversionRate
          && next.individualDiscount === previous.individualDiscount
          && next.pricesLocked === previous.pricesLocked
        ),
      },
    ),
    createDerivedSubscriber(store, state => state.ui?.activeTab, activeTab => {
      tabsController?.applyActiveTab?.(activeTab);
    }),
    createDerivedSubscriber(store, state => state.ui?.selectedElement, () => {
      refresh();
    }),
    createDerivedSubscriber(store, state => state.ui?.inspectorImageTab, () => {
      refresh();
    }),
    createDerivedSubscriber(store, state => state.selection?.offerId, () => {
      refresh();
    }),
    createDerivedSubscriber(
      store,
      state => ({
        items: state.shop.items,
        selectedOfferId: state.selection.offerId,
        searchTerm: state.ui.searchTerm,
        mode: state.ui?.mode,
      }),
      pickerState => {
        renderPicker(pickerState);
      },
      {
        equals: (next, previous) => (
          next.items === previous.items
          && next.selectedOfferId === previous.selectedOfferId
          && next.searchTerm === previous.searchTerm
          && next.mode === previous.mode
        ),
      },
    ),
    createDerivedSubscriber(store, state => state.ui?.mode, mode => {
      applyModeToUI(mode);
      refresh();
      if (isCouponUiMode(mode)) {
        renderCoupons();
      }
      scheduleCanvas(renderScheduler);
    }),
    createDerivedSubscriber(store, state => state.collage, collageState => {
      if (normalizeUiMode(store.getState()?.ui?.mode) !== "collage") {
        return;
      }

      if (renderScheduler?.isInteracting) {
        renderScheduler.pendingUiSync = true;
      } else {
        applyGrid(collageState, false);
        refresh();
      }

      scheduleCanvas(renderScheduler);
    }),
    createDerivedSubscriber(store, state => state.cart, cartState => {
      if (normalizeUiMode(store.getState()?.ui?.mode) !== "cart") {
        return;
      }

      syncCartRecipients(store.getState());

      if (renderScheduler?.isInteracting) {
        renderScheduler.pendingUiSync = true;
      } else {
        applyGrid(cartState, true);
        refresh();
      }

      scheduleCanvas(renderScheduler);
    }),
    createDerivedSubscriber(store, state => state.coupon, couponState => {
      const mode = normalizeUiMode(store.getState()?.ui?.mode);
      if (!isCouponUiMode(mode)) {
        return;
      }

      renderCoupons();
      syncCouponPath(couponState?.customPath);
      applyBranding(couponState?.branding);
      refresh();
      scheduleCanvas(renderScheduler);
    }),
    createDerivedSubscriber(store, state => Array.isArray(state.cart?.items) ? state.cart.items.length : 0, (nextCount, previousCount) => {
      if (nextCount === previousCount) {
        return;
      }

      const state = store.getState();
      if (state?.cart?.totalOverride !== null) {
        store.update("cart.totalOverride", null);
      }
    }),
    createDerivedSubscriber(
      store,
      state => ({
        template: state.template,
        selectedOfferId: state.selection.offerId,
        selectedElement: state.ui?.selectedElement,
      }),
      () => {
        scheduleCanvas(renderScheduler);
      },
      {
        equals: (next, previous) => (
          next.template === previous.template
          && next.selectedOfferId === previous.selectedOfferId
          && next.selectedElement === previous.selectedElement
        ),
      },
    ),
  ]);
}

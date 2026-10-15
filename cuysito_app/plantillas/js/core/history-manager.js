const DEFAULT_MAX_ENTRIES = 200;
const DEFAULT_DEBOUNCE_MS = 280;
const DEFAULT_MODE = "individual";
const DEFAULT_ACTIVE_TAB = "elementos";

const SNAPSHOT_SLICE_KEYS = Object.freeze(["template", "collage", "cart"]);
const TEXT_STYLE_FIELDS = Object.freeze(["color", "opacity", "maxWidth", "fontScale"]);
const OFFSET_SELECTION_FIELDS = Object.freeze([
  ["name", "nameOffsetX", "nameOffsetY"],
  ["vbucks", "vbucksOffsetX", "vbucksOffsetY"],
  ["soles", "solesOffsetX", "solesOffsetY"],
  ["solesOffer", "solesOfferOffsetX", "solesOfferOffsetY"],
  ["solesList", "solesListOffsetX", "solesListOffsetY"],
  ["type", "typeOffsetX", "typeOffsetY"],
  ["rarity", "rarityOffsetX", "rarityOffsetY"],
  ["date", "dateOffsetX", "dateOffsetY"],
  ["label", "labelOffsetX", "labelOffsetY"],
]);

function normalizeOfferId(value) {
  return String(value || "");
}

function normalizeMode(value) {
  return String(value || DEFAULT_MODE);
}

function normalizePricesLocked(ui) {
  return ui?.pricesLocked !== false;
}

function createUiSnapshot(ui = {}) {
  return {
    pricesLocked: normalizePricesLocked(ui),
    mode: normalizeMode(ui.mode),
  };
}

function createSnapshot(state = {}) {
  return {
    selectionOfferId: normalizeOfferId(state?.selection?.offerId),
    template: state?.template || {},
    collage: state?.collage || {},
    cart: state?.cart || {},
    ui: createUiSnapshot(state?.ui),
  };
}

function snapshotsEqual(left, right) {
  if (!left || !right) {
    return false;
  }

  return (
    left.selectionOfferId === right.selectionOfferId &&
    left.template === right.template &&
    left.collage === right.collage &&
    left.cart === right.cart &&
    left.ui.pricesLocked === right.ui.pricesLocked &&
    left.ui.mode === right.ui.mode
  );
}

function buildSnapshotDiff(previousSnapshot, nextSnapshot) {
  const diff = {};
  const base = {};

  for (const sliceKey of SNAPSHOT_SLICE_KEYS) {
    if (previousSnapshot[sliceKey] !== nextSnapshot[sliceKey]) {
      diff[sliceKey] = nextSnapshot[sliceKey];
      base[sliceKey] = previousSnapshot[sliceKey];
    }
  }

  if (previousSnapshot.selectionOfferId !== nextSnapshot.selectionOfferId) {
    diff.selectionOfferId = nextSnapshot.selectionOfferId;
    base.selectionOfferId = previousSnapshot.selectionOfferId;
  }

  if (
    previousSnapshot.ui.pricesLocked !== nextSnapshot.ui.pricesLocked ||
    previousSnapshot.ui.mode !== nextSnapshot.ui.mode
  ) {
    diff.ui = nextSnapshot.ui;
    base.ui = previousSnapshot.ui;
  }

  return { diff, base };
}

function applySnapshotDiff(baseSnapshot, diff) {
  return {
    selectionOfferId:
      diff.selectionOfferId !== undefined ? diff.selectionOfferId : baseSnapshot.selectionOfferId,
    template: diff.template !== undefined ? diff.template : baseSnapshot.template,
    collage: diff.collage !== undefined ? diff.collage : baseSnapshot.collage,
    cart: diff.cart !== undefined ? diff.cart : baseSnapshot.cart,
    ui: diff.ui !== undefined ? diff.ui : baseSnapshot.ui,
  };
}

function hasTrackedSnapshotChange(previousState, nextState) {
  return !(
    normalizeOfferId(previousState?.selection?.offerId) ===
      normalizeOfferId(nextState?.selection?.offerId) &&
    previousState?.template === nextState?.template &&
    previousState?.collage === nextState?.collage &&
    previousState?.cart === nextState?.cart &&
    normalizePricesLocked(previousState?.ui) === normalizePricesLocked(nextState?.ui) &&
    normalizeMode(previousState?.ui?.mode) === normalizeMode(nextState?.ui?.mode)
  );
}

function trimHistoryStack(stack, maxEntries) {
  if (stack.length > maxEntries) {
    stack.splice(0, stack.length - maxEntries);
  }
}

function findChangedTextStyleSelection(previousTemplate, nextTemplate) {
  const selectionIds = new Set([
    ...Object.keys(previousTemplate.textStyles || {}),
    ...Object.keys(nextTemplate.textStyles || {}),
  ]);

  for (const selectionId of selectionIds) {
    const previousStyle = previousTemplate.textStyles?.[selectionId] || {};
    const nextStyle = nextTemplate.textStyles?.[selectionId] || {};

    for (const field of TEXT_STYLE_FIELDS) {
      const fallbackValue = field === "color" ? "" : 1;
      const previousValue =
        field === "color"
          ? previousStyle[field] || ""
          : Number(previousStyle[field] ?? fallbackValue);
      const nextValue =
        field === "color"
          ? nextStyle[field] || ""
          : Number(nextStyle[field] ?? fallbackValue);

      if (previousValue !== nextValue) {
        return selectionId;
      }
    }
  }

  return null;
}

function inferContext(currentSnapshot, targetSnapshot) {
  if (currentSnapshot.selectionOfferId !== targetSnapshot.selectionOfferId) {
    return { activeTab: DEFAULT_ACTIVE_TAB, selectedElement: null };
  }

  if (currentSnapshot.ui.mode !== targetSnapshot.ui.mode) {
    return { activeTab: DEFAULT_ACTIVE_TAB, selectedElement: null };
  }

  const previousTemplate = currentSnapshot.template || {};
  const nextTemplate = targetSnapshot.template || {};
  const changedTextSelection = findChangedTextStyleSelection(previousTemplate, nextTemplate);

  if (changedTextSelection) {
    return { activeTab: "diseno", selectedElement: changedTextSelection };
  }

  if (
    (Array.isArray(previousTemplate.gradientColors) ? previousTemplate.gradientColors : []) !==
    (Array.isArray(nextTemplate.gradientColors) ? nextTemplate.gradientColors : [])
  ) {
    return { activeTab: "diseno", selectedElement: undefined };
  }

  if (String(previousTemplate.accentColor || "") !== String(nextTemplate.accentColor || "")) {
    return { activeTab: "diseno", selectedElement: undefined };
  }

  if (
    Number(previousTemplate.accentGradientHeight ?? 0.33) !==
    Number(nextTemplate.accentGradientHeight ?? 0.33)
  ) {
    return { activeTab: "diseno", selectedElement: undefined };
  }

  if (
    Number(previousTemplate.accentGradientDensity ?? 0) !==
    Number(nextTemplate.accentGradientDensity ?? 0)
  ) {
    return { activeTab: "diseno", selectedElement: undefined };
  }

  for (const [selectedElement, xKey, yKey] of OFFSET_SELECTION_FIELDS) {
    if (Number(previousTemplate[xKey] || 0) !== Number(nextTemplate[xKey] || 0)) {
      return { activeTab: undefined, selectedElement };
    }

    if (Number(previousTemplate[yKey] || 0) !== Number(nextTemplate[yKey] || 0)) {
      return { activeTab: undefined, selectedElement };
    }
  }

  if (
    (Array.isArray(previousTemplate.imageLayers) ? previousTemplate.imageLayers : []) !==
    (Array.isArray(nextTemplate.imageLayers) ? nextTemplate.imageLayers : [])
  ) {
    return { activeTab: undefined, selectedElement: undefined };
  }

  if (
    String(previousTemplate.itemName || "") !== String(nextTemplate.itemName || "") ||
    String(previousTemplate.typeLabel || "") !== String(nextTemplate.typeLabel || "") ||
    String(previousTemplate.rarityLabel || "") !== String(nextTemplate.rarityLabel || "") ||
    String(previousTemplate.dateText || "") !== String(nextTemplate.dateText || "") ||
    String(previousTemplate.giftLabel || "") !== String(nextTemplate.giftLabel || "") ||
    Number(previousTemplate.vbucks ?? 0) !== Number(nextTemplate.vbucks ?? 0) ||
    Number(previousTemplate.soles ?? 0) !== Number(nextTemplate.soles ?? 0) ||
    Number(previousTemplate.conversionRate ?? 0) !== Number(nextTemplate.conversionRate ?? 0)
  ) {
    return { activeTab: DEFAULT_ACTIVE_TAB, selectedElement: undefined };
  }

  return { activeTab: undefined, selectedElement: undefined };
}

function subscribeToStateChanges(store, signalStore, onStoreChange) {
  if (signalStore) {
    return signalStore.subscribe("*", (nextState, previousState) => {
      onStoreChange(nextState, previousState);
    });
  }

  return store.subscribe((nextState, previousState) => {
    onStoreChange(nextState, previousState);
  });
}

export class HistoryManager {
  constructor(store, options = {}) {
    this.store = store;
    this.past = [];
    this.future = [];
    this.maxEntries = Number.isFinite(Number(options.maxEntries))
      ? Number(options.maxEntries)
      : DEFAULT_MAX_ENTRIES;
    this.debounceMs = Number.isFinite(Number(options.debounceMs))
      ? Number(options.debounceMs)
      : DEFAULT_DEBOUNCE_MS;
    this.enabled = options.enabled !== false;
    this.isRestoring = false;
    this.pendingSnapshot = null;
    this.pendingTimer = null;
    this.transactionSnapshot = null;
    this.unsubscribe = subscribeToStateChanges(
      store,
      options.signalStore,
      (nextState, previousState) => {
        this.onStoreChange(nextState, previousState);
      },
    );
  }

  destroy() {
    if (this.unsubscribe) {
      this.unsubscribe();
    }

    this.unsubscribe = null;
    this.clearPending();
    this.transactionSnapshot = null;
  }

  setEnabled(enabled) {
    this.enabled = Boolean(enabled);

    if (!this.enabled) {
      this.clearPending();
      this.transactionSnapshot = null;
    }
  }

  clear() {
    this.past = [];
    this.future = [];
    this.clearPending();
    this.transactionSnapshot = null;
  }

  clearPending() {
    if (this.pendingTimer) {
      clearTimeout(this.pendingTimer);
    }

    this.pendingTimer = null;
    this.pendingSnapshot = null;
  }

  snapshotFromState(state) {
    return createSnapshot(state);
  }

  computeDiff(previousSnapshot, nextSnapshot) {
    return buildSnapshotDiff(previousSnapshot, nextSnapshot);
  }

  applyDiffSlices(baseSnapshot, diff) {
    return applySnapshotDiff(baseSnapshot, diff);
  }

  snapshotEquals(left, right) {
    return snapshotsEqual(left, right);
  }

  inferContext(currentSnapshot, targetSnapshot) {
    return inferContext(currentSnapshot, targetSnapshot);
  }

  scheduleCommit() {
    if (this.pendingTimer) {
      clearTimeout(this.pendingTimer);
    }

    this.pendingTimer = setTimeout(() => {
      this.commitPending();
    }, this.debounceMs);
  }

  onStoreChange(nextState, previousState) {
    if (!this.enabled || this.isRestoring || this.transactionSnapshot) {
      return;
    }

    if (!hasTrackedSnapshotChange(previousState, nextState)) {
      return;
    }

    if (!this.pendingSnapshot) {
      this.pendingSnapshot = this.snapshotFromState(previousState);
    }

    this.scheduleCommit();
  }

  commitPending() {
    if (!this.pendingSnapshot) {
      return;
    }

    const baseSnapshot = this.pendingSnapshot;
    this.clearPending();

    const currentSnapshot = this.snapshotFromState(this.store.getState());
    if (this.snapshotEquals(baseSnapshot, currentSnapshot)) {
      return;
    }

    this.past.push({ type: "diff", base: baseSnapshot, current: currentSnapshot });
    trimHistoryStack(this.past, this.maxEntries);
    this.future = [];
  }

  beginTransaction() {
    if (!this.enabled || this.isRestoring || this.transactionSnapshot) {
      return;
    }

    this.commitPending();
    this.transactionSnapshot = this.snapshotFromState(this.store.getState());
  }

  endTransaction() {
    if (!this.transactionSnapshot) {
      return;
    }

    const baseSnapshot = this.transactionSnapshot;
    this.transactionSnapshot = null;

    const currentSnapshot = this.snapshotFromState(this.store.getState());
    if (this.snapshotEquals(baseSnapshot, currentSnapshot)) {
      return;
    }

    this.past.push({ type: "diff", base: baseSnapshot, current: currentSnapshot });
    trimHistoryStack(this.past, this.maxEntries);
    this.future = [];
  }

  canUndo() {
    return this.past.length > 0;
  }

  canRedo() {
    return this.future.length > 0;
  }

  applySnapshot(snapshot, currentSnapshot) {
    const context = this.inferContext(currentSnapshot, snapshot);

    this.isRestoring = true;
    this.clearPending();
    this.transactionSnapshot = null;

    try {
      this.store.setState((state) => {
        const currentOfferId = normalizeOfferId(state?.selection?.offerId);
        const nextOfferId = normalizeOfferId(snapshot?.selectionOfferId);
        const selectionChanged = currentOfferId !== nextOfferId;
        const selectedElement = selectionChanged
          ? null
          : context.selectedElement === undefined
            ? state.ui?.selectedElement
            : context.selectedElement;

        const nextUi = {
          ...(state.ui || {}),
          pricesLocked: normalizePricesLocked(snapshot?.ui),
          mode: normalizeMode(snapshot?.ui?.mode),
          activeTab:
            context.activeTab === undefined
              ? state.ui?.activeTab
              : context.activeTab || DEFAULT_ACTIVE_TAB,
          selectedElement,
          skipNextSelectionTemplate: selectionChanged && Boolean(nextOfferId),
        };

        return {
          ...state,
          selection: {
            ...(state.selection || {}),
            offerId: nextOfferId,
          },
          template: snapshot?.template || {},
          collage: snapshot?.collage || {},
          cart: snapshot?.cart || state.cart || {},
          ui: nextUi,
        };
      });
    } finally {
      this.isRestoring = false;
    }
  }

  undo() {
    this.commitPending();
    this.endTransaction();

    if (!this.canUndo()) {
      return false;
    }

    const entry = this.past.pop();
    const currentSnapshot = this.snapshotFromState(this.store.getState());
    const targetSnapshot = entry?.type === "diff" ? entry.base : entry;

    this.future.push(
      entry?.type === "diff"
        ? entry
        : { type: "diff", base: targetSnapshot, current: currentSnapshot },
    );

    this.applySnapshot(targetSnapshot, currentSnapshot);
    return true;
  }

  redo() {
    this.commitPending();
    this.endTransaction();

    if (!this.canRedo()) {
      return false;
    }

    const entry = this.future.pop();
    const currentSnapshot = this.snapshotFromState(this.store.getState());
    const targetSnapshot = entry?.type === "diff" ? entry.current : entry;

    this.past.push(
      entry?.type === "diff"
        ? entry
        : { type: "diff", base: currentSnapshot, current: targetSnapshot },
    );
    trimHistoryStack(this.past, this.maxEntries);

    this.applySnapshot(targetSnapshot, currentSnapshot);
    return true;
  }
}
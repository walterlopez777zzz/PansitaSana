# Modularization Plan

> **Status:** Draft — awaiting review before execution.
> **Date:** 2026-02-17
> **Author:** Principal Software Architect (AI-assisted)

---

## 1. The Audit — Identifying the Monoliths

### File Size Census (Top 5)

| # | File | Lines | Bytes | Responsibility Count |
|---|------|------:|------:|:--------------------:|
| 1 | `js/ui/ui-root.js` | 2 278 | 79 915 | **7** |
| 2 | `js/ui/controllers/inspector/collage-inspector.js` | 1 641 | 72 243 | **5** |
| 3 | `js/rendering/modes/collage-mode.js` | 1 421 | 62 607 | **6** |
| 4 | `js/rendering/canvas-engine.js` | 1 044 | 44 427 | **5** |
| 5 | `js/rendering/modes/cart-mode.js` | 761 | 36 726 | **3** |

---

### 1.1  `js/ui/ui-root.js` — 2 278 lines

**Role today:** God-object orchestrator that boots the entire UI, wires every controller, binds remaining event listeners, manages render scheduling, and hosts several derived-state subscribers.

**Responsibility Mix:**

| # | Responsibility | Lines (approx.) | Description |
|---|----------------|:---------------:|-------------|
| R1 | **Controller Instantiation & DI Wiring** | ~350 | Creates every controller (inspector, export, mode, branding, discount, item-picker, layers, style-groups, backgrounds, command-bar, editor-interactions, tabs) and passes 50+ DOM refs + utility functions via DI bags. |
| R2 | **Grid Item Append/Reset Domain Logic** | ~350 | `appendCollageOfferIds`, `appendGridOfferIds`, `resetGridSliceToDefaults`, `performResetToDefaults` — heavy domain logic for creating new collage/cart items with style inheritance, section-style resolution, sibling gradient/accent derivation, and full reset-to-defaults flow. This is **business logic living in the UI layer**. |
| R3 | **Template ↔ Sidebar Input Sync** | ~120 | `applyTemplateToInputs` — reads template state and writes values to individual, collage, and accent sidebar fields. |
| R4 | **Render Scheduling & Interaction State** | ~80 | `scheduleCanvasRender`, `markInteracting`, `endInteractingNow`, `flushUiSync`, `pendingUiSync` — RAF-based render loop, interaction debounce, deferred UI sync. |
| R5 | **Derived-State Subscribers** | ~250 | 12+ `createDerivedSubscriber` calls wiring store slices to UI side-effects (template→inputs, layers→list, mode→layout, collage→render, cart→render, prices→soles, tab sync, etc.). |
| R6 | **Remaining Inline Event Listeners** | ~200 | Accent color/height/density, price-lock, gradient-add, collage background hex/picker, section-name, cart total override, gap, sidebar search, global deselection, range-progress. |
| R7 | **Layout Detection & Panel Height Tracking** | ~100 | ResizeObserver-based compact/tall hybrid layout system + panel `--_panel-h` CSS variable tracking + IntersectionObserver for reset FAB visibility. |

**Key Smells:**
- R2 is pure domain logic (~350 lines) that doesn't belong in a UI orchestrator.
- R5+R6 make the file the "subscriber dumping ground" — every new feature adds more here.
- The DI bags for `collageInspector` alone span ~110 lines of parameter passing.
- `appendCollageOfferIds` and `appendGridOfferIds` are near-identical (~130 lines each) — code duplication.

---

### 1.2  `js/ui/controllers/inspector/collage-inspector.js` — 1 641 lines

**Role today:** Handles ALL collage-card inspector sub-panels (card, image, text, name, price, solesList, solesOffer, outDate pill, banner badge, title, discount, gradient, accent) — event binding + state hydration.

**Responsibility Mix:**

| # | Responsibility | Lines (approx.) | Description |
|---|----------------|:---------------:|-------------|
| R1 | **Card Movement Logic** | ~110 | `computeGridRows`, `findVerticalTarget`, move-left/right/up/down — re-implements a grid layout algorithm to support directional card reordering. |
| R2 | **Card-Level Style Bindings** | ~200 | Scale, rotation, widthUnits, ignoreGaps, linkChildren — event handlers + state updates for card-level properties. |
| R3 | **Gradient & Accent Bindings** | ~220 | useGlobalGradient toggle, gradient-add, gradient-visibility, useGlobalAccent toggle, accent picker/hex/clear/add/visibility/height/density — each with group-sync logic. |
| R4 | **Text/Name/Price Sub-Element Bindings** | ~180 | Content, color, fontSize, opacity, rotation for name/price/solesOffer/solesList + name-specific maxWidth/lineHeight/typography. |
| R5 | **OutDate + Banner Badge Bindings** | ~170 | Two nearly identical blocks: content, bgColor, bgOpacity, textColor, textOpacity, rotation for outDate pill and ¡NUEVO! banner — clear duplication pattern. |
| R6 | **Image Sub-Element Bindings** | ~120 | url, zoom, opacity, rotation, fadeToggle, fadeHeight, fadeDensity + 5 image filters via `bindCollageImageFilter`. |
| R7 | **State Hydration (`apply`)** | ~280 | Reads state → writes to DOM inputs for whichever sub-panel is active. Mirrors the event handlers in reverse. |

**Key Smells:**
- R1 is domain logic (grid-row computation) duplicated from `collage-mode.js`.
- R3 has group-sync logic that repeats the same pattern for gradient and accent.
- R5 is blatant copy-paste between outDate and banner (identical structure, different field names).
- 116-line DI parameter list — a sign that the function is doing too much.
- `apply()` (R7) is a 280-line switch-case on `collageSel.kind` that could be dispatched to sub-inspectors.

---

### 1.3  `js/rendering/modes/collage-mode.js` — 1 421 lines

**Role today:** The collage grid renderer — layout computation, style resolution, card drawing, scene composition, hit-box recording, selection outline.

**Responsibility Mix:**

| # | Responsibility | Lines (approx.) | Description |
|---|----------------|:---------------:|-------------|
| R1 | **Layout Computation** | ~70 | `computeLayoutRaw`, `computeLayout` (memoized) — column/row algorithm, canvas dimensions. |
| R2 | **Style Resolution Pipeline** | ~160 | `resolveCollageCardGradientColors`, `resolveCollageCardGradientStopsHidden`, `resolveCollageCardAccentSpec`, `cachedResolveSectionStyle` — 4-tier cascade (Local → Section → API → Global). |
| R3 | **Card Renderer (`drawMiniCardImpl`)** | ~390 | Single-card rendering: gradient bitmap, image loading/fading/transform, accent overlay, price rendering, soles text, name wrapping, ¡NUEVO! badge. This is the core hot-path. |
| R4 | **Scene Compositor (`drawScene`)** | ~250 | Full-scene loop: background, background image, section-name title, per-item layout iteration, clip-region culling, progressive rendering with yield. |
| R5 | **Single-Item Renderer (`drawItemAtIndex`)** | ~70 | Overlay path for zero-paint selection — O(1) lookup via `ensureLayoutPositions` cache, draws one card. |
| R6 | **Geometry & Math Utilities** | ~80 | `rotatedAabb`, `cardTransformAabb`, `buildCardMatrix`, `resetCardCtx` — zero-allocation AABB, DOMMatrix composition. |
| R7 | **Memoization Infrastructure** | ~80 | `ensureMemoized` (byOfferId + sectionContext), `ensureLayoutPositions`, `ensureSelectionIds`, `_sectionCache*` — 4 independent caches. |
| R8 | **Miscellaneous** | ~50 | `drawImageCover`, `drawNewBadge`, `drawSelectionOutline`, `itemTypeImageDefaults`, `parseSelectionIndex`. |

**Key Smells:**
- R3 at 390 lines is a single function — the longest in the codebase.
- R2 (style resolution) is pure domain logic that both the renderer and the UI inspector need → currently duplicated in spirit.
- R6 (geometry) is general-purpose math that could be shared across modes.
- R7 (memoization) is interleaved with rendering code, making both harder to reason about.

---

### 1.4  `js/rendering/canvas-engine.js` — 1 044 lines

**Role today:** Orchestrator for the rendering pipeline — owns the canvas element, delegates to mode-specific renderers, manages chunk lifecycle, version-counter invalidation, overlay/ghost system, export path.

**Responsibility Mix:**

| # | Responsibility | Lines (approx.) | Description |
|---|----------------|:---------------:|-------------|
| R1 | **Subsystem Instantiation** | ~100 | Creates imageCache, fadeCache, contentBounds, collageBitmaps, all three mode renderers, overlayRenderer, chunkMgr, centering, workerSync. |
| R2 | **Version-Counter Invalidation Engine** | ~200 | 4 scoped counters (content/style/layout/asset), reference-equality tracking across collage/cart/shop/template, per-item overlay-aware comparison, per-layoutId targeted chunk invalidation. |
| R3 | **Overlay / Ghost System** | ~100 | Zero-paint selection logic: ghost tracking, exclude-index management, offset-change latching, viewport-scoped chunk guard. |
| R4 | **Grid Render Loop** | ~200 | Chunk iteration, dirty detection, `renderChunkContent`, `presentChunk`, merged hit-box accumulation, overlay card compositing, selection outline painting. |
| R5 | **Individual Mode Render** | ~50 | Canvas sizing, scale transform, workCanvas double-buffering, `individualMode.drawScene` delegation. |
| R6 | **Export Path** | ~60 | `exportCanvas`, `exportPngBlob` — temporary full-size canvas creation, mode-aware delegation. |
| R7 | **Chunk-LayoutId Mapping** | ~70 | `_buildChunkLayoutIds` — replays column/row layout to map items → chunks by Y position. |

**Key Smells:**
- R2+R3 are 300 lines of invalidation/overlay logic tightly coupled in the `render()` function's 500-line body.
- R7 duplicates the column/row layout algorithm from `collage-mode.js`.
- The `render()` function itself is ~510 lines — a single function controlling three distinct code paths (grid-overlay, grid-full, individual).
- Asset re-render queueing (R1) is interleaved with cache instantiation.

---

### 1.5  `js/rendering/modes/cart-mode.js` — 761 lines

**Role today:** Cart-mode renderer that wraps collage-mode and adds custom header/footer, out-date pill rendering, and state remapping.

**Responsibility Mix:**

| # | Responsibility | Lines (approx.) | Description |
|---|----------------|:---------------:|-------------|
| R1 | **State Remapping & Layout Override** | ~40 | `remapState`, `computeLayout` — adapts `state.cart` → `state.collage`. |
| R2 | **Header Rendering (Sneaker + Total)** | ~200 | `drawCartHeader` — sneaker icon Path2D, optical cap-height alignment, TOTAL label + value. |
| R3 | **Footer Rendering (Branding)** | ~200 | `drawCartFooter` — web icon Path2D, branding text, icon rendering (path or URL-based), custom icon loading. |
| R4 | **Out-Date Pill** | ~120 | `drawOutDatePill` — per-card leaving-date capsule with customizable colors/rotation. |

**Key Smells:**
- R2+R3 contain two 200-line drawing functions that are internally well-structured but embed large Path2D data constants (~50 lines each).
- The icon/branding rendering in R3 has its own image-loading branch that could be extracted.
- Otherwise this file is the **least problematic** of the five — already well-decomposed via delegation to collage-mode.

---

## 2. The Strategy — Proposed Splits

### 2.1  Splitting `ui-root.js` (2 278 → ~400 lines)

The goal is to reduce `ui-root.js` to a **thin bootstrap orchestrator** that:
1. Creates the DOM refs
2. Instantiates controllers
3. Wires derived subscribers
4. Returns the public API

All domain logic, event binding, and sidebar sync moves out.

| Extract | Target Module | Lines | What Moves |
|---------|--------------|:-----:|------------|
| **E1** Grid Item Factory | `js/domain/collage/grid-item-factory.js` | ~200 | `appendCollageOfferIds` + `appendGridOfferIds` unified into a single `appendGridItems(store, sliceKey, offerIds, opts)`. Sibling lookup, style inheritance, section-style resolution, image-defaults computation. Pure domain — zero DOM dependency. |
| **E2** Grid Reset Logic | `js/domain/collage/grid-reset.js` | ~180 | `resetGridSliceToDefaults`, `performResetToDefaults`, `createDefaultGridGlobalDesign`. Offer-ID variant matching, layout-based sibling lookups, per-item reset. Pure domain — zero DOM dependency. |
| **E3** Template Sidebar Sync | `js/ui/controllers/template-sidebar-controller.js` | ~100 | `applyTemplateToInputs` + the inline accent/price-lock/gradient-add event listeners + the template derived subscriber. Takes `{ store, dom, discountController, ... }`. |
| **E4** Render Scheduler | `js/ui/orchestrators/render-scheduler.js` | ~80 | `scheduleCanvasRender`, `markInteracting`, `endInteractingNow`, `flushUiSync`, `isInteracting`, `pendingUiSync`, `renderInFlight`, `rerenderAfterFlight`. Exports a `createRenderScheduler({ store, canvasEngine, buildRenderModel })`. |
| **E5** Derived Subscriber Registry | `js/ui/orchestrators/subscriber-registry.js` | ~250 | All 12+ `createDerivedSubscriber` calls moved to a single `registerSubscribers({ store, controllers, renderScheduler })` function. Each subscriber is a named entry for easy discovery. |
| **E6** Layout Detection | `js/ui/orchestrators/layout-detector.js` | ~100 | Panel height ResizeObserver, compact/tall hybrid detection, reset-FAB IntersectionObserver. Self-contained, zero store dependency. |
| **E7** Global Event Handlers | `js/ui/orchestrators/global-events.js` | ~60 | Global deselection `pointerdown`, reset dialog wiring, range-progress delegation. |

**Residual `ui-root.js`:** ~400 lines — imports, DOM destructuring, controller instantiation calls, one-line delegations, `return Object.freeze({ setStatus })`.

**Dependency graph (no cycles):**
```
ui-root.js
  ├─ grid-item-factory.js (domain, no UI imports)
  ├─ grid-reset.js (domain, no UI imports)
  ├─ render-scheduler.js (→ canvasEngine, store)
  ├─ template-sidebar-controller.js (→ store, dom)
  ├─ subscriber-registry.js (→ store, controllers, renderScheduler)
  ├─ layout-detector.js (→ DOM only)
  └─ global-events.js (→ store, DOM)
```

---

### 2.2  Splitting `collage-inspector.js` (1 641 → ~280 lines)

The inspector is a composite of 7+ sub-panels that follow an identical pattern: event binding + `apply()` hydration. Each should be its own module.

| Extract | Target Module | Lines | What Moves |
|---------|--------------|:-----:|------------|
| **E1** Card Movement | `js/ui/controllers/inspector/card-movement.js` | ~110 | `computeGridRows`, `findVerticalTarget`, move-left/right/up/down handlers. Could import a shared `computeGridRows` from domain layer (see 2.3 E1). |
| **E2** Card Style Bindings | `js/ui/controllers/inspector/card-style-inspector.js` | ~200 | Scale, rotation, widthUnits, ignoreGaps, linkChildren event handlers + `apply()` card panel hydration. |
| **E3** Gradient & Accent Bindings | `js/ui/controllers/inspector/card-gradient-accent-inspector.js` | ~220 | useGlobal toggles, gradient list/add/visibility, accent picker/hex/clear/add/visibility/height/density + group-sync logic. |
| **E4** Text Sub-Element | `js/ui/controllers/inspector/collage-text-inspector.js` | ~180 | Content, color, fontSize, opacity, rotation for name/price/solesOffer/solesList + name-specific controls + `apply()` text hydration. |
| **E5** Pill/Badge Inspector (Generic) | `js/ui/controllers/inspector/pill-badge-inspector.js` | ~100 | **Unified** handler for both outDate and banner — parameterized by `{ styleKey, defaults }`. Eliminates the copy-paste duplication. |
| **E6** Image Sub-Element | `js/ui/controllers/inspector/collage-image-inspector.js` | ~120 | URL, zoom, opacity, rotation, fade, 5 filters + `apply()` image hydration. |

**Residual `collage-inspector.js`:** ~280 lines — `apply()` dispatcher that delegates to the correct sub-inspector by `collageSel.kind`, shared helpers (`activeSliceKey`, `updateCollageItem`, `updateTitleStyle`), title panel (small enough to keep inline).

---

### 2.3  Splitting `collage-mode.js` (1 421 → ~500 lines)

The renderer should focus on **drawing**. Layout, style resolution, and geometry move out.

| Extract | Target Module | Lines | What Moves |
|---------|--------------|:-----:|------------|
| **E1** Grid Layout Engine | `js/domain/collage/grid-layout.js` | ~120 | `computeLayoutRaw`, `computeLayout` (memoized), `computeGridRows` (shared with inspector card-movement). Pure domain — no canvas dependency. `ensureLayoutPositions` moves here too. |
| **E2** Style Resolution Pipeline | `js/domain/collage/collage-style-resolver.js` | ~160 | `resolveCollageCardGradientColors`, `resolveCollageCardGradientStopsHidden`, `resolveCollageCardAccentSpec`, `resolveCollageGlobalDesign`, `cachedResolveSectionStyle`. Pure domain — consumed by both rendering and UI. |
| **E3** Card Geometry | `js/rendering/painters/card-geometry.js` | ~80 | `rotatedAabb`, `cardTransformAabb`, `buildCardMatrix`, `resetCardCtx`. Shared across collage-mode and canvas-engine. |
| **E4** Memoization Helpers | `js/rendering/infrastructure/render-memo.js` | ~80 | `ensureMemoized` (byOfferId + sectionContext), `ensureSelectionIds`, `ensureLayoutPositions`. Separate concerns from drawing. |

**Residual `collage-mode.js`:** ~500 lines — `drawMiniCardImpl`, `drawScene`, `drawItemAtIndex`, `drawSelectionOutline`, `drawNewBadge`, `drawImageCover` + the public API. Pure rendering, no layout or style-resolution logic.

---

### 2.4  Splitting `canvas-engine.js` (1 044 → ~350 lines)

The engine should be a thin orchestrator. Invalidation and overlay logic are separable subsystems.

| Extract | Target Module | Lines | What Moves |
|---------|--------------|:-----:|------------|
| **E1** Version Invalidation Engine | `js/rendering/infrastructure/invalidation-engine.js` | ~200 | All 4 version counters, reference-equality tracking, per-item overlay-aware comparison, per-layoutId targeted dirty-marking. Exports `createInvalidationEngine({ chunkMgr })`. |
| **E2** Overlay / Ghost Manager | `js/rendering/infrastructure/overlay-manager.js` | ~100 | Ghost tracking, exclude-index management, offset-change latching, overlay state machine. Exports `createOverlayManager()`. |
| **E3** Grid Render Coordinator | `js/rendering/infrastructure/grid-render-coordinator.js` | ~200 | Chunk dirty iteration, `renderChunkContent`, `presentChunk`, merged hit-box accumulation, overlay card compositing, selection outline painting, viewport-scoped chunk guard. |
| **E4** Asset Lifecycle | `js/rendering/infrastructure/asset-lifecycle.js` | ~40 | `imageCache` creation, `subscribe` → `pendingAssetInvalidation`, font preloading, `queueAssetRerender`, `applyAssetInvalidation`. |

**Residual `canvas-engine.js`:** ~350 lines — subsystem instantiation, `render()` as a thin dispatcher (grid → gridRenderCoordinator, individual → individualMode), export path, `hitTest`, public API.

---

### 2.5  `cart-mode.js` — Minimal Extraction (761 → ~550 lines)

This file is already well-structured. Only minor extractions are warranted.

| Extract | Target Module | Lines | What Moves |
|---------|--------------|:-----:|------------|
| **E1** Cart Icon Data | `js/rendering/painters/cart-icons.js` | ~100 | `HEADER_ICON_PATH`, `HEADER_ICON_VIEWBOX`, `WEB_SVG_PATH` Path2D constants. Shared data, no logic. |
| **E2** Cart Branding Renderer | `js/rendering/painters/branding-renderer.js` | ~150 | `drawCartFooter` — branding text + icon rendering. Could be reused if branding appears in other modes. |

**Residual `cart-mode.js`:** ~550 lines — `remapState`, `computeLayout`, `drawCartHeader`, `drawOutDatePill`, `drawScene` override.

---

## 3. Prioritization

### 🔴 High Priority (Critical for maintainability)

| ID | Extraction | Rationale |
|----|-----------|-----------|
| **2.1 E1** | `grid-item-factory.js` | **350 lines of domain logic in UI layer.** Duplicated between collage/cart. Blocks testability. |
| **2.1 E2** | `grid-reset.js` | Same issue — 180 lines of complex domain logic with zero DOM dependency, untestable in current location. |
| **2.1 E4** | `render-scheduler.js` | Interaction state machine is a cross-cutting concern referenced by 5+ controllers. Extracting it simplifies DI for all controllers. |
| **2.3 E1** | `grid-layout.js` | Layout algorithm duplicated across collage-mode, canvas-engine `_buildChunkLayoutIds`, and collage-inspector `computeGridRows`. **Single source of truth eliminates 3× duplication.** |
| **2.3 E2** | `collage-style-resolver.js` | Style resolution is the most complex domain logic in the renderer. Moving it to domain layer makes it unit-testable and shareable. |
| **2.4 E1** | `invalidation-engine.js` | 200 lines of version-counter logic tangled inside `render()`. Extracting it makes the render function readable and the invalidation strategy testable independently. |

### 🟡 Medium Priority (Significant improvement)

| ID | Extraction | Rationale |
|----|-----------|-----------|
| **2.1 E5** | `subscriber-registry.js` | 12+ subscribers scattered in ui-root. Centralizing them improves discoverability but isn't blocking. |
| **2.1 E3** | `template-sidebar-controller.js` | Clean extraction but lower risk — the code is relatively straightforward. |
| **2.2 E5** | `pill-badge-inspector.js` (unified) | Eliminates obvious copy-paste (outDate + banner), but only affects inspector, not hot path. |
| **2.2 E1** | `card-movement.js` | Isolates grid-row computation shared with domain. Medium impact. |
| **2.2 E3** | `card-gradient-accent-inspector.js` | Largest inspector sub-module (220 lines). Complex group-sync logic benefits from isolation. |
| **2.4 E2** | `overlay-manager.js` | Overlay state machine is self-contained. Extraction simplifies canvas-engine significantly. |
| **2.4 E3** | `grid-render-coordinator.js` | The main grid render loop. Extracts cleanly but has many dependencies on chunk/overlay state. |
| **2.3 E3** | `card-geometry.js` | Small but shared across files. Prevents future duplication. |

### 🟢 Low Priority (Nice to have)

| ID | Extraction | Rationale |
|----|-----------|-----------|
| **2.1 E6** | `layout-detector.js` | Self-contained DOM-only code. Low coupling, low churn. |
| **2.1 E7** | `global-events.js` | Small (~60 lines). Low ROI for the effort. |
| **2.2 E2** | `card-style-inspector.js` | Straightforward bindings. Benefits from extraction but not urgent. |
| **2.2 E4** | `collage-text-inspector.js` | Same pattern — clean but not urgent. |
| **2.2 E6** | `collage-image-inspector.js` | Already well-structured with `bindCollageImageFilter` helper. |
| **2.3 E4** | `render-memo.js` | Small utility. Minor improvement. |
| **2.4 E4** | `asset-lifecycle.js` | Small (~40 lines). Low standalone value. |
| **2.5 E1** | `cart-icons.js` | Data constants only. Trivial extraction. |
| **2.5 E2** | `branding-renderer.js` | Self-contained but only used in cart mode currently. |

---

## 4. Proposed File Tree (Post-Refactor)

```
js/
├── domain/
│   └── collage/
│       ├── grid-item-factory.js       ← NEW (from ui-root R2)
│       ├── grid-layout.js             ← NEW (from collage-mode R1 + engine R7 + inspector R1)
│       ├── grid-reset.js              ← NEW (from ui-root R2)
│       ├── collage-style-resolver.js  ← NEW (from collage-mode R2)
│       ├── collage-item.js            (existing)
│       ├── collage-item-style.js      (existing)
│       ├── collage-discount.js        (existing)
│       └── grid-card-image-defaults.js (existing)
│
├── rendering/
│   ├── infrastructure/
│   │   ├── invalidation-engine.js     ← NEW (from canvas-engine R2)
│   │   ├── overlay-manager.js         ← NEW (from canvas-engine R3)
│   │   ├── grid-render-coordinator.js ← NEW (from canvas-engine R4)
│   │   ├── asset-lifecycle.js         ← NEW (from canvas-engine R1)
│   │   ├── render-memo.js            ← NEW (from collage-mode R7)
│   │   ├── chunk-manager.js           (existing)
│   │   ├── layout-centering.js        (existing)
│   │   ├── worker-state-sync.js       (existing)
│   │   └── render-model.js            (existing)
│   ├── painters/
│   │   ├── card-geometry.js           ← NEW (from collage-mode R6)
│   │   ├── cart-icons.js              ← NEW (from cart-mode data)
│   │   ├── branding-renderer.js       ← NEW (from cart-mode R3)
│   │   ├── geometry.js                (existing)
│   │   ├── price-renderer.js          (existing)
│   │   └── ...                        (existing)
│   ├── modes/
│   │   ├── collage-mode.js            (slimmed ~500 lines)
│   │   └── cart-mode.js               (slimmed ~550 lines)
│   └── canvas-engine.js               (slimmed ~350 lines)
│
└── ui/
    ├── orchestrators/
    │   ├── render-scheduler.js        ← NEW (from ui-root R4)
    │   ├── subscriber-registry.js     ← NEW (from ui-root R5)
    │   ├── layout-detector.js         ← NEW (from ui-root R7)
    │   └── global-events.js           ← NEW (from ui-root R6)
    ├── controllers/
    │   ├── inspector/
    │   │   ├── collage-inspector.js           (slimmed ~280 lines, dispatcher only)
    │   │   ├── card-movement.js               ← NEW
    │   │   ├── card-style-inspector.js        ← NEW
    │   │   ├── card-gradient-accent-inspector.js ← NEW
    │   │   ├── collage-text-inspector.js      ← NEW
    │   │   ├── collage-image-inspector.js     ← NEW
    │   │   ├── pill-badge-inspector.js        ← NEW (unified outDate + banner)
    │   │   ├── inspector-main.js              (existing)
    │   │   ├── text-inspector.js              (existing)
    │   │   └── image-inspector.js             (existing)
    │   ├── template-sidebar-controller.js     ← NEW (from ui-root R3)
    │   └── ...                                (existing controllers)
    └── ui-root.js                             (slimmed ~400 lines, bootstrap only)
```

---

## 5. Execution Principles

1. **One extraction per PR** — each split is a self-contained, reviewable unit.
2. **Zero behavioral change** — every extraction must be a pure refactor with identical runtime behavior.
3. **Import-only coupling** — new modules communicate via imports and DI, never globals.
4. **No circular dependencies** — domain layer NEVER imports from ui/ or rendering/. Rendering NEVER imports from ui/. UI imports from both.
5. **Suggested execution order:** Follow the High → Medium → Low priority table. Within High priority, start with domain extractions (E1, E2 from 2.1 and E1, E2 from 2.3) before UI extractions, since domain modules have zero DOM dependencies and are simplest to verify.

---

## 6. Expected Outcome

| Metric | Before | After |
|--------|-------:|------:|
| `ui-root.js` | 2 278 lines | ~400 lines |
| `collage-inspector.js` | 1 641 lines | ~280 lines |
| `collage-mode.js` | 1 421 lines | ~500 lines |
| `canvas-engine.js` | 1 044 lines | ~350 lines |
| `cart-mode.js` | 761 lines | ~550 lines |
| **Largest file** | **2 278** | **~550** |
| New modules created | — | **~20** |
| Layout algorithm copies | 3 | **1** (shared `grid-layout.js`) |
| Domain logic in UI layer | ~530 lines | **0** |
| Testable domain modules | limited | **+4 pure modules** |

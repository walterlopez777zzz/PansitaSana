# Fortnite Shop Template Generator — File System Audit & Reorganization Plan

> **Author**: Principal Software Architect Audit  
> **Date**: 2026-02-17  
> **Scope**: Every file and folder in the project  
> **Status**: PROPOSAL ONLY — no moves executed

---

## 1. Current Structure Analysis

### 1.1 What's Working Well

The codebase has a solid foundational architecture:

- **`js/core/`** — Clean, minimal state primitives (`store.js`, `signal-store.js`, `derived-subscriber.js`). Excellent separation.
- **`js/domain/`** — Good domain-driven sub-directories (`collage/`, `pricing/`, `shop/`, `template/`, `item-picker/`). Pure business logic, no DOM.
- **`js/rendering/modes/`** — Mode renderers correctly grouped as a sub-directory of rendering.
- **`js/constants/`** — Clean, two focused files. No DOM.
- **CSS ITCSS architecture** (`settings/ → generic/ → elements/ → objects/ → components/ → utilities/`) — Professional, well-layered.
- **Naming**: Consistent kebab-case across all files. No camelCase file names detected.

### 1.2 Problems Identified

#### P1: Orphaned Root-Level JS Files (Architectural Layer Violations)

Five files sit directly in `js/` with no folder grouping:

| File | Actual Role | Problem |
|---|---|---|
| `main.js` | App bootstrap + initial state factory | Contains `buildDefaultTemplateForItem()` and `createInitialState()` — domain logic mixed with boot |
| `history.js` | Undo/Redo manager class | Infrastructure concern, not domain |
| `keyboard.js` | Global hotkey manager class | Input concern, not domain |
| `theme-loader.js` | FOUC-preventing IIFE (inline `<script>`) | Not a module — it's a pre-boot snippet |
| `theme.js` | Full theme manager (toggle, system detect, persist) | UI infrastructure concern |

#### P2: `js/utils/` Is a Dumping Ground

12 files with wildly different scopes:

| File | True Concern |
|---|---|
| `order-panels-shared.js` | **Completely foreign** — renders Tailwind HTML for an e-commerce order panel. Contains `renderOrderProductRow()` with admin/user variants, Tailwind classes. Not used by the template generator at all. |
| `mode-utils.js` | Domain logic (`normalizeMode`, `isGridMode`) — belongs near modes |
| `money-utils.js` | Domain pricing logic (`roundSoles`, `formatSoles`) — belongs in `domain/pricing/` |
| `hex-utils.js` | Single 3-line function wrapping `safeHex` — candidate for **deletion** (inline into color-utils) |
| `dom-utils.js` | Misleading name — contains `safeIdFromTitle()` only. Zero DOM access. Should be `string-utils.js` or merged |
| `download-utils.js`, `clipboard-utils.js` | Browser platform utilities (legitimate utils) |
| `idle-utils.js` | Single function, 8 lines — candidate for merge |
| `date-utils.js` | Fortnite shop-cycle-aware date logic — arguably domain |

#### P3: `js/ui/utils/` Is Also a Dumping Ground (11 files)

This directory mixes three distinct concerns:

| Concern | Files |
|---|---|
| **Export pipeline** | `magick-service.js`, `export-formats.js`, `image-encoders.js` (deprecated) |
| **Form/DOM micro-helpers** | `form-utils.js`, `scroll-utils.js`, `status-utils.js`, `dialog-behavior.js` |
| **Domain-coupled UI renderers** | `hidden-elements.js`, `template-gradient-controls.js`, `accent-visibility-controls.js`, `collage-card-gradient-list.js` |

The export pipeline (3 files) is significant enough to warrant its own directory. The gradient/accent/hidden-elements files are really **controllers** disguised as utils.

#### P4: `js/ui/dom/` Naming Is Misleading

- `dom-utils.js` is a 10-line `$()` helper (getElementById wrapper).
- `dom-ids.js` is a small ID constants object.
- `ui-dom.js` is a 279-line DOM registry factory.
- The folder name `dom/` is vague. It's really the **DOM bootstrap/registry**.

#### P5: `js/rendering/` Is Flat (22 files, no sub-grouping)

The rendering directory has grown to 22 files with distinct architectural layers:

| Layer | Files | Description |
|---|---|---|
| **Orchestrator** | `canvas-engine.js` | Main render loop coordinator |
| **Infrastructure** | `chunk-manager.js`, `layout-centering.js`, `worker-state-sync.js`, `render-worker-bridge.js`, `render.worker.js`, `render-model.js` | Stacked canvas, workers, centering |
| **Modes** | `modes/collage-mode.js`, `modes/cart-mode.js`, `modes/individual-mode.js` | Already grouped ✓ |
| **Painters** | `overlay-renderer.js`, `price-renderer.js`, `text-engine.js` | Text/price/overlay drawing |
| **Asset pipeline** | `asset-manager.js`, `asset-fade-cache.js`, `asset-layer-effects.js`, `content-bounds.js` | Image loading, fading, bounds analysis |
| **Shared math/visual** | `geometry.js`, `gradient-stops.js`, `collage-bitmaps.js`, `accent-overlay.js`, `hit-testing.js` | Geometry, gradients, hit-testing |

22 files in one flat folder is hard to scan. Sub-grouping the infrastructure and asset layers would dramatically improve discoverability.

#### P6: Dead / Deprecated Files

| File | Status |
|---|---|
| `js/ui/utils/image-encoders.js` | Explicitly deprecated, 6-line comment-only stub |
| `js/ui/components/placeholder.js` | 4-line no-op (`return null`) — dead code |
| `js/ui/features/reset/` | Empty directory |
| `js/utils/order-panels-shared.js` | Foreign code (Tailwind e-commerce HTML renderer) — does not belong in this project |

#### P7: Semantic Naming Issues

| Current Name | Problem | Suggested Name |
|---|---|---|
| `js/domain/item-mappings.js` | "mappings" is vague — it's the API→model normalizer | `shop-entry-normalizer.js` |
| `js/domain/item-type-schema.js` | Accurate but long, and "item" is generic | `cosmetic-type-schema.js` |
| `js/utils/dom-utils.js` | Contains zero DOM code (only `safeIdFromTitle`) | Merge into `string-utils.js` or `id-utils.js` |
| `js/ui/dom/dom-utils.js` | Just `$(id)` helper | `element-by-id.js` or merge into `ui-dom.js` |
| `js/domain/collage/collage-image-defaults.js` | Also used by cart mode | `grid-card-image-defaults.js` |
| `js/domain/collage/collage-item.js` | Also used by cart mode | `grid-card-item.js` or keep (collage is the base) |
| `js/rendering/collage-bitmaps.js` | Contains gradient/accent bitmap factories for grid cards | `grid-card-bitmaps.js` |
| `js/services/fortnite-shop-api.js` | Contains both `fetchShop` AND `optimizeImageUrl` | Split image proxy to `image-proxy.js` or rename to `shop-api-service.js` |
| `js/ui/ui-constants.js` | Only contains SVG icon strings | `svg-icons.js` |
| `js/history.js` | Root-level, should be infrastructure | Move to `core/` |
| `js/keyboard.js` | Root-level, should be input infrastructure | Move to `ui/` or `core/` |

#### P8: Dual `dom-utils.js` Confusion

Two files named `dom-utils.js` exist:
- `js/utils/dom-utils.js` — contains `safeIdFromTitle()` (no DOM!)
- `js/ui/dom/dom-utils.js` — contains `$(id)` (actual DOM)

This is a discoverability landmine.

#### P9: `theme.js` + `theme-loader.js` Split Is Unclear

- `theme-loader.js` is an IIFE meant to be inlined in `<head>` to prevent FOUC.
- `theme.js` is the full module with `initTheme()`, `toggleTheme()`, etc.

Both relate to theming but serve different runtime contexts. The naming doesn't convey this distinction.

---

## 2. Proposed Moves / Renames

### Legend
- **MOVE**: File relocated to a new path
- **RENAME**: File name changed in place
- **MERGE**: Content absorbed into another file
- **DELETE**: File removed entirely

### 2.1 Root-Level `js/` Cleanup

| # | Current Path | Proposed Path | Reason |
|---|---|---|---|
| 1 | `js/main.js` | `js/main.js` | **KEEP** — entry point stays at root. Extract `buildDefaultTemplateForItem` and `createInitialState` into `js/domain/template/initial-state.js` |
| 2 | `js/history.js` | `js/core/history-manager.js` | MOVE+RENAME — undo/redo is core infrastructure; add `-manager` suffix for class-based module clarity |
| 3 | `js/keyboard.js` | `js/core/keyboard-manager.js` | MOVE+RENAME — global hotkey handling is input infrastructure |
| 4 | `js/theme.js` | `js/core/theme-service.js` | MOVE+RENAME — theme persistence/toggle is app infrastructure; `-service` suffix matches the factory pattern |
| 5 | `js/theme-loader.js` | `js/core/theme-preload.js` | MOVE+RENAME — "preload" conveys its FOUC-prevention IIFE nature |

### 2.2 Domain Layer Improvements

| # | Current Path | Proposed Path | Reason |
|---|---|---|---|
| 6 | `js/domain/item-mappings.js` | `js/domain/shop/shop-entry-normalizer.js` | MOVE+RENAME — this normalizes raw API entries into `NormalizedShopItem`. It belongs in `shop/` and the name should describe the action |
| 7 | `js/domain/item-type-schema.js` | `js/domain/shop/cosmetic-type-schema.js` | MOVE+RENAME — "item" is generic; "cosmetic" is the Fortnite domain term. Group with other shop logic |
| 8 | `js/domain/collage/collage-image-defaults.js` | `js/domain/collage/grid-card-image-defaults.js` | RENAME — used by both collage and cart; "grid-card" is the shared concept |
| 9 | `js/domain/template/image-layers.js` | `js/domain/template/image-layers.js` | KEEP — name is accurate (5-line utility) |
| 10 | *(new file)* | `js/domain/template/initial-state.js` | CREATE — receives `buildDefaultTemplateForItem`, `createDefaultTextStyles`, `createInitialState` extracted from `main.js` |

### 2.3 Utils Layer Cleanup

| # | Current Path | Proposed Path | Reason |
|---|---|---|---|
| 11 | `js/utils/order-panels-shared.js` | **DELETE** | Foreign code (Tailwind e-commerce panel renderer). Not imported by any file in this project. Remove entirely. |
| 12 | `js/utils/hex-utils.js` | **MERGE** into `color-utils.js` | Single 3-line function (`isValidHex6`); trivial wrapper around `safeHex`. Eliminate the file. |
| 13 | `js/utils/dom-utils.js` | `js/utils/string-utils.js` | RENAME — file contains `safeIdFromTitle()`, zero DOM access. Name is misleading. |
| 14 | `js/utils/money-utils.js` | `js/domain/pricing/money-format.js` | MOVE+RENAME — `roundSoles`/`formatSoles` are domain pricing concerns, not generic utils. Many domain files already import from this. |
| 15 | `js/utils/mode-utils.js` | `js/domain/mode-utils.js` | MOVE — `normalizeMode`/`isGridMode` are domain concepts (mode = Individual/Collage/Cart). Move one level up into domain root. |
| 16 | `js/utils/date-utils.js` | `js/utils/date-utils.js` | KEEP — `parseDateDDMM` is generic enough; `isLeavingCurrentCycle` is shop-aware but the file is small. Acceptable. |
| 17 | `js/utils/idle-utils.js` | **MERGE** into `js/utils/scheduling-utils.js` | RENAME — 8-line single function. If more scheduling helpers appear, they go here. Alternatively, inline at call sites. |

### 2.4 Rendering Layer Sub-Grouping

| # | Current Path | Proposed Path | Reason |
|---|---|---|---|
| 18 | `js/rendering/chunk-manager.js` | `js/rendering/infrastructure/chunk-manager.js` | MOVE — stacked canvas lifecycle is rendering infra |
| 19 | `js/rendering/layout-centering.js` | `js/rendering/infrastructure/layout-centering.js` | MOVE — viewport centering is rendering infra |
| 20 | `js/rendering/worker-state-sync.js` | `js/rendering/infrastructure/worker-state-sync.js` | MOVE — worker bridge orchestration is rendering infra |
| 21 | `js/rendering/render-worker-bridge.js` | `js/rendering/infrastructure/render-worker-bridge.js` | MOVE — worker communication protocol is rendering infra |
| 22 | `js/rendering/render.worker.js` | `js/rendering/infrastructure/render.worker.js` | MOVE — the Web Worker script itself |
| 23 | `js/rendering/render-model.js` | `js/rendering/infrastructure/render-model.js` | MOVE — memoized render state boundary is rendering infra |
| 24 | `js/rendering/asset-manager.js` | `js/rendering/assets/asset-manager.js` | MOVE — image cache belongs in assets sub-group |
| 25 | `js/rendering/asset-fade-cache.js` | `js/rendering/assets/asset-fade-cache.js` | MOVE — fade bitmap cache |
| 26 | `js/rendering/asset-layer-effects.js` | `js/rendering/assets/asset-layer-effects.js` | MOVE — CSS filter computation for image layers |
| 27 | `js/rendering/content-bounds.js` | `js/rendering/assets/content-bounds.js` | MOVE — alpha-channel content analysis for images |
| 28 | `js/rendering/collage-bitmaps.js` | `js/rendering/painters/grid-card-bitmaps.js` | MOVE+RENAME — gradient/accent bitmap factories; "grid-card" is more accurate than "collage" (used by cart too) |
| 29 | `js/rendering/accent-overlay.js` | `js/rendering/painters/accent-overlay.js` | MOVE — accent gradient drawing |
| 30 | `js/rendering/gradient-stops.js` | `js/rendering/painters/gradient-stops.js` | MOVE — gradient stop computation |
| 31 | `js/rendering/overlay-renderer.js` | `js/rendering/painters/overlay-renderer.js` | MOVE — individual-mode overlay drawing |
| 32 | `js/rendering/price-renderer.js` | `js/rendering/painters/price-renderer.js` | MOVE — V-Bucks / Soles price drawing |
| 33 | `js/rendering/text-engine.js` | `js/rendering/painters/text-engine.js` | MOVE — text measurement, wrapping, drawing |
| 34 | `js/rendering/geometry.js` | `js/rendering/painters/geometry.js` | MOVE — AABB, rounded rect, rotation math |
| 35 | `js/rendering/hit-testing.js` | `js/rendering/painters/hit-testing.js` | MOVE — point-in-rect hit testing |

### 2.5 UI Layer Cleanup

| # | Current Path | Proposed Path | Reason |
|---|---|---|---|
| 36 | `js/ui/ui-constants.js` | `js/ui/components/svg-icons.js` | MOVE+RENAME — only contains SVG icon string constants. "ui-constants" is vague. Group with components. |
| 37 | `js/ui/dom/dom-utils.js` | **MERGE** into `js/ui/dom/ui-dom.js` | 10-line `$(id)` helper — inline into the DOM registry file that already imports it |
| 38 | `js/ui/utils/image-encoders.js` | **DELETE** | Deprecated 6-line stub. Remove entirely. |
| 39 | `js/ui/components/placeholder.js` | **DELETE** | Dead code — 4-line no-op `return null` |
| 40 | `js/ui/features/reset/` | **DELETE** (empty dir) | Empty directory with no files |
| 41 | `js/ui/features/` | **DELETE** (empty after above) | No remaining content |
| 42 | `js/ui/utils/magick-service.js` | `js/ui/export/magick-service.js` | MOVE — part of the export pipeline |
| 43 | `js/ui/utils/export-formats.js` | `js/ui/export/export-formats.js` | MOVE — part of the export pipeline |
| 44 | `js/ui/controllers/export-controller.js` | `js/ui/export/export-controller.js` | MOVE — co-locate export controller with its service layer |
| 45 | `js/ui/utils/hidden-elements.js` | `js/ui/controllers/hidden-elements-controller.js` | MOVE+RENAME — manages store state for hidden elements. It's a controller, not a util. |
| 46 | `js/ui/utils/template-gradient-controls.js` | `js/ui/controllers/template-gradient-controller.js` | MOVE+RENAME — builds & manages gradient list UI. Controller pattern. |
| 47 | `js/ui/utils/accent-visibility-controls.js` | `js/ui/controllers/accent-visibility-controller.js` | MOVE+RENAME — syncs accent visibility toggle with state. Controller pattern. |
| 48 | `js/ui/utils/collage-card-gradient-list.js` | `js/ui/controllers/collage-card-gradient-controller.js` | MOVE+RENAME — manages per-card gradient list rendering. Controller pattern. |
| 49 | `js/ui/dom/dom-ids.js` | `js/ui/dom/dom-ids.js` | KEEP — accurate name, correct location |

### 2.6 Services Layer

| # | Current Path | Proposed Path | Reason |
|---|---|---|---|
| 50 | `js/services/fortnite-shop-api.js` | `js/services/shop-api-service.js` | RENAME — shorter, consistent `-service` suffix. `optimizeImageUrl()` stays here as it's specific to the shop image proxy pipeline. |
| 51 | `js/services/backgrounds-service.js` | `js/services/backgrounds-service.js` | KEEP — name and location are correct |

---

## 3. Proposed Ideal Structure

```
plantillas/
├── index.html
├── api/
│   └── backgrounds.php
├── assets/
│   └── fonts/
│       ├── burbank-big-regular-black.otf
│       ├── burbank-small-black.otf
│       ├── burbank-small-bold.otf
│       └── burbank-small-medium.otf
├── css/
│   ├── style.css                          # Master import
│   └── src/
│       ├── settings/
│       │   ├── tokens.css
│       │   └── themes.css
│       ├── generic/
│       │   ├── reset.css
│       │   ├── fonts.css
│       │   └── interactions.css
│       ├── elements/
│       │   ├── base.css
│       │   └── forms.css
│       ├── objects/
│       │   └── layout.css
│       ├── components/
│       │   ├── collage.css
│       │   ├── color-picker.css
│       │   ├── command-bar.css
│       │   ├── corner-hud.css
│       │   ├── dialogs.css
│       │   ├── export-modal.css
│       │   ├── fabs.css
│       │   ├── inspector.css
│       │   ├── item-picker.css
│       │   ├── layers.css
│       │   ├── tabs.css
│       │   └── visibility-toggle.css
│       └── utilities/
│           └── utilities.css
│
└── js/
    ├── main.js                            # Boot only (no domain logic)
    │
    ├── constants/
    │   ├── defaults.js
    │   └── endpoints.js
    │
    ├── core/                              # Framework-level primitives
    │   ├── store.js
    │   ├── signal-store.js
    │   ├── derived-subscriber.js
    │   ├── history-manager.js             # ← from js/history.js
    │   ├── keyboard-manager.js            # ← from js/keyboard.js
    │   ├── theme-service.js               # ← from js/theme.js
    │   └── theme-preload.js               # ← from js/theme-loader.js
    │
    ├── domain/                            # Pure business logic (zero DOM)
    │   ├── mode-utils.js                  # ← from js/utils/mode-utils.js
    │   ├── collage/
    │   │   ├── collage-item.js
    │   │   ├── collage-item-style.js
    │   │   ├── collage-discount.js
    │   │   └── grid-card-image-defaults.js  # ← renamed from collage-image-defaults.js
    │   ├── item-picker/
    │   │   ├── item-sections-model.js
    │   │   └── search-engine.js
    │   ├── pricing/
    │   │   ├── discounts.js
    │   │   ├── soles.js
    │   │   ├── cart-totals.js
    │   │   └── money-format.js            # ← from js/utils/money-utils.js
    │   ├── shop/
    │   │   ├── shop-entry-normalizer.js   # ← from js/domain/item-mappings.js
    │   │   ├── cosmetic-type-schema.js    # ← from js/domain/item-type-schema.js
    │   │   ├── shop-colors.js
    │   │   └── shop-tile-size.js
    │   └── template/
    │       ├── image-layers.js
    │       └── initial-state.js           # NEW — extracted from main.js
    │
    ├── rendering/
    │   ├── canvas-engine.js               # Orchestrator (stays at root)
    │   ├── infrastructure/                # Canvas lifecycle + workers
    │   │   ├── chunk-manager.js
    │   │   ├── layout-centering.js
    │   │   ├── render-model.js
    │   │   ├── render-worker-bridge.js
    │   │   ├── render.worker.js
    │   │   └── worker-state-sync.js
    │   ├── assets/                        # Image loading + effects
    │   │   ├── asset-manager.js
    │   │   ├── asset-fade-cache.js
    │   │   ├── asset-layer-effects.js
    │   │   └── content-bounds.js
    │   ├── painters/                      # Drawing primitives
    │   │   ├── accent-overlay.js
    │   │   ├── geometry.js
    │   │   ├── gradient-stops.js
    │   │   ├── grid-card-bitmaps.js       # ← renamed from collage-bitmaps.js
    │   │   ├── hit-testing.js
    │   │   ├── overlay-renderer.js
    │   │   ├── price-renderer.js
    │   │   └── text-engine.js
    │   └── modes/                         # Already grouped ✓
    │       ├── collage-mode.js
    │       ├── cart-mode.js
    │       └── individual-mode.js
    │
    ├── services/                          # Network + external APIs
    │   ├── shop-api-service.js            # ← renamed from fortnite-shop-api.js
    │   └── backgrounds-service.js
    │
    ├── utils/                             # Truly generic utilities (zero domain knowledge)
    │   ├── array-utils.js
    │   ├── clipboard-utils.js
    │   ├── color-utils.js                 # + isValidHex6 merged from hex-utils.js
    │   ├── date-utils.js
    │   ├── download-utils.js
    │   ├── number-utils.js
    │   ├── scheduling-utils.js            # ← renamed from idle-utils.js
    │   └── string-utils.js               # ← renamed from dom-utils.js
    │   # DELETED: hex-utils.js (merged), order-panels-shared.js (foreign),
    │   #          mode-utils.js (moved to domain), money-utils.js (moved to domain/pricing)
    │
    └── ui/
        ├── ui-root.js                     # Main UI orchestrator
        ├── dom/
        │   ├── dom-ids.js
        │   └── ui-dom.js                  # + $(id) merged from dom-utils.js
        ├── components/
        │   ├── gradient-row.js
        │   ├── svg-icons.js              # ← renamed from ui-constants.js
        │   └── visibility-toggle.js
        │   # DELETED: placeholder.js (dead code)
        ├── controllers/
        │   ├── branding-controller.js
        │   ├── collage-backgrounds-controller.js
        │   ├── collage-style-groups-controller.js
        │   ├── command-bar-controller.js
        │   ├── discount-controller.js
        │   ├── editor-interactions-controller.js
        │   ├── item-picker-controller.js
        │   ├── mode-controller.js
        │   ├── tabs-controller.js
        │   ├── hidden-elements-controller.js    # ← from ui/utils/hidden-elements.js
        │   ├── template-gradient-controller.js  # ← from ui/utils/template-gradient-controls.js
        │   ├── accent-visibility-controller.js  # ← from ui/utils/accent-visibility-controls.js
        │   ├── collage-card-gradient-controller.js  # ← from ui/utils/collage-card-gradient-list.js
        │   ├── inspector/
        │   │   ├── inspector-main.js
        │   │   ├── text-inspector.js
        │   │   ├── image-inspector.js
        │   │   └── collage-inspector.js
        │   └── layers/
        │       └── layer-list-controller.js
        ├── export/                        # Export pipeline (co-located)
        │   ├── export-controller.js       # ← from controllers/
        │   ├── export-formats.js          # ← from utils/
        │   └── magick-service.js          # ← from utils/
        │   # DELETED: image-encoders.js (deprecated stub)
        ├── orchestrators/
        │   ├── canvas-render-scheduler.js
        │   └── interaction-manager.js
        ├── selection/
        │   ├── selection-ids.js
        │   ├── selection-meta.js
        │   └── selection-offsets.js
        ├── services/
        │   └── color-picker-service.js
        └── utils/                         # Remaining thin UI helpers
            ├── dialog-behavior.js
            ├── form-utils.js
            ├── scroll-utils.js
            └── status-utils.js
        # DELETED: features/reset/ (empty dir)
```

---

## 4. Impact Summary

| Metric | Before | After |
|---|---|---|
| Root-level `js/*.js` files | 5 | 1 (`main.js`) |
| `js/utils/` files | 12 | 7 (5 removed/moved) |
| `js/ui/utils/` files | 11 | 4 (7 removed/moved) |
| `js/rendering/` flat files | 19 | 1 (`canvas-engine.js` + 3 sub-dirs) |
| Dead/deprecated files | 4 | 0 |
| Foreign files | 1 (`order-panels-shared.js`) | 0 |
| Duplicate-named files (`dom-utils.js`) | 2 | 0 |
| New sub-directories | — | 4 (`rendering/{infrastructure,assets,painters}`, `ui/export/`) |
| New domain files | — | 1 (`domain/template/initial-state.js`) |

---

## 5. Migration Priority

### Phase 1 — Quick Wins (Low Risk, High Signal)
1. **Delete dead files**: `placeholder.js`, `image-encoders.js`, empty `features/reset/`, `order-panels-shared.js`
2. **Merge tiny files**: `hex-utils.js` → `color-utils.js`, `dom-utils.js` (ui/dom) → `ui-dom.js`
3. **Rename semantically wrong files**: `ui-constants.js` → `svg-icons.js`, `dom-utils.js` (utils) → `string-utils.js`

### Phase 2 — Structural Moves (Medium Risk)
4. **Move orphaned root files** into `core/`: `history.js`, `keyboard.js`, `theme.js`, `theme-loader.js`
5. **Move domain files** from `utils/`: `money-utils.js` → `domain/pricing/`, `mode-utils.js` → `domain/`
6. **Move domain files** within `domain/`: `item-mappings.js` → `shop/`, `item-type-schema.js` → `shop/`
7. **Move controller-disguised utils** from `ui/utils/` into `ui/controllers/`

### Phase 3 — Rendering Sub-Structure (Higher Risk, Highest Payoff)
8. **Create `rendering/infrastructure/`**, `rendering/assets/`, `rendering/painters/` and move files
9. **Create `ui/export/`** and co-locate export pipeline

### Phase 4 — Extraction (Requires Code Changes)
10. **Extract `initial-state.js`** from `main.js` — move `buildDefaultTemplateForItem`, `createDefaultTextStyles`, `createInitialState` to `domain/template/initial-state.js`

---

## 6. Rules for Future Files

1. **Zero root-level JS files** except `main.js`.
2. **`domain/`** = pure logic, zero DOM, zero canvas. If it touches `document` or `ctx`, it doesn't belong here.
3. **`rendering/`** = canvas-only. No DOM form manipulation, no `getElementById`.
4. **`ui/controllers/`** = any module that reads/writes store state AND manipulates DOM. Use `-controller.js` suffix.
5. **`ui/utils/`** = stateless, store-unaware DOM micro-helpers only.
6. **`utils/`** = truly generic (could exist in any project). No Fortnite-specific logic.
7. **All file names**: kebab-case. Class-based modules get `-manager` or `-service` suffix. Factory modules get `-controller` suffix.
8. **No more than ~10 files per directory** before creating a sub-directory.

"use strict";

/**
 * ============================================================
 * Card Manager — Groups Module
 * ============================================================
 * Self-contained module that manages the groups drawer, its
 * own reactive state, all API calls to api/groups.php, and
 * every DOM element inside the groups drawer.
 *
 * Dependencies (globals expected on window):
 *   CardState  — to read/remove cards when a group is deleted
 *   CardDOM    — for toast(), toastError(), confirm()
 *
 * HTML IDs this module owns:
 *   groups-toggle, groups-drawer, groups-drawer-close,
 *   groups-list, groups-add-btn, group-form-panel,
 *   gf-name, gf-type, gf-titular, gf-cvu,
 *   gf-cancel, gf-save,
 *   group-select, edit-group-select
 */

const CardGroups = (() => {

    // ── Constants ────────────────────────────────────────────

    const API_URL = "api/groups.php";

    /** Map card_type value → human-readable label for selects */
    const TYPE_LABELS = {
        dollarapp: "DollarApp",
        astropay:  "AstroPay",
    };

    // (TYPE_BADGE removed — badges are no longer shown)

    // ── Color Utilities ──────────────────────────────────────

    const _color = {
        /**
         * Convert #RRGGBB hex to {h:0-360, s:0-100, l:0-100}.
         */
        hexToHsl(hex) {
            const r = parseInt(hex.slice(1,3),16)/255;
            const g = parseInt(hex.slice(3,5),16)/255;
            const b = parseInt(hex.slice(5,7),16)/255;
            const max=Math.max(r,g,b), min=Math.min(r,g,b);
            let h=0, s=0;
            const l=(max+min)/2;
            if (max!==min) {
                const d=max-min;
                s=l>0.5?d/(2-max-min):d/(max+min);
                switch(max){
                    case r: h=((g-b)/d+(g<b?6:0))/6; break;
                    case g: h=((b-r)/d+2)/6; break;
                    case b: h=((r-g)/d+4)/6; break;
                }
            }
            return { h:Math.round(h*360), s:Math.round(s*100), l:Math.round(l*100) };
        },

        /**
         * Convert HSL (0-360, 0-100, 0-100) to #RRGGBB hex.
         */
        hslToHex(h,s,l) {
            h=((h%360)+360)%360; s/=100; l/=100;
            const a=s*Math.min(l,1-l);
            const f=n=>{
                const k=(n+h/30)%12;
                return Math.round(255*(l-a*Math.max(Math.min(k-3,9-k,1),-1)))
                    .toString(16).padStart(2,'0');
            };
            return `#${f(0)}${f(8)}${f(4)}`;
        },

        /** Generate a random vivid color (used for both bg and card). */
        randomBg() {
            const h=Math.floor(Math.random()*360);
            const s=Math.floor(Math.random()*25)+45; // 45-70
            const l=Math.floor(Math.random()*15)+42; // 42-57
            return this.hslToHex(h,s,l);
        },

        /**
         * Derive card color from bg — now returns the same color
         * since bg_color and card_color should match.
         */
        deriveCard(bgHex) {
            return bgHex;
        },

        /**
         * Derive a card color adjusted for dark mode.
         * Same hue, less saturated, a bit lighter than light-mode equivalent.
         */
        deriveCardDark(bgHex) {
            const {h,s,l}=this.hexToHsl(bgHex);
            return this.hslToHex(h, Math.min(s+18,55), Math.max(l-28,46));
        },

        /** Convert hex to rgba(r,g,b,a) string for inline styles. */
        toRgba(hex, alpha) {
            const r=parseInt(hex.slice(1,3),16);
            const g=parseInt(hex.slice(3,5),16);
            const b=parseInt(hex.slice(5,7),16);
            return `rgba(${r},${g},${b},${alpha})`;
        },
    };

    // ── Private State ────────────────────────────────────────

    /** @type {Array<{id:number, name:string, card_type:string, cardholder_name:string, cvu:string, sort_order:number}>} */
    let _groups = [];

    /** @type {Set<Function>} */
    const _subscribers = new Set();

    // ── Cached DOM Elements ──────────────────────────────────

    const _el = {};

    /** True while an async operation is in progress (prevents double-submit). */
    let _busy = false;

    /**
     * ID of the group currently being edited in the inline form.
     * null means we are in "create" mode.
     * @type {number|null}
     */
    let _editingGroupId = null;

    // ── Private Helpers: Reactivity ──────────────────────────

    /**
     * Replace the internal groups array and notify all subscribers.
     * @param {Array} groups
     */
    const _setGroups = (groups) => {
        _groups = Array.isArray(groups) ? groups.slice() : [];
        _subscribers.forEach((fn) => {
            try { fn(_groups.slice()); } catch (_) { /* subscriber errors are isolated */ }
        });
    };

    // ── Private Helpers: API ─────────────────────────────────

    /**
     * Low-level GET helper against api/groups.php.
     * @param {string} action
     * @returns {Promise<Object>}
     */
    const _get = async (action) => {
        const url = `${API_URL}?action=${encodeURIComponent(action)}`;
        const response = await fetch(url, {
            method:  "GET",
            headers: { Accept: "application/json" },
            cache:   "no-store",
        });
        const data = await response.json();
        if (!response.ok || data.status === "error") {
            throw new Error(data.message || `HTTP ${response.status}`);
        }
        return data;
    };

    /**
     * Low-level POST helper against api/groups.php.
     * @param {Object} payload
     * @returns {Promise<Object>}
     */
    const _post = async (payload) => {
        const response = await fetch(API_URL, {
            method:  "POST",
            headers: {
                "Content-Type": "application/json",
                Accept:         "application/json",
            },
            body:  JSON.stringify(payload),
            cache: "no-store",
        });
        const data = await response.json();
        if (!response.ok || data.status === "error") {
            throw new Error(data.message || `HTTP ${response.status}`);
        }
        return data;
    };

    // ── Private Helpers: Loading State ───────────────────────

    /**
     * Disable / re-enable every interactive element inside the drawer
     * while an async operation is running.
     * @param {boolean} isBusy
     */
    const _setDrawerBusy = (isBusy) => {
        _busy = isBusy;

        // Buttons in the form panel
        if (_el.gfSave)   _el.gfSave.disabled   = isBusy;
        if (_el.gfCancel) _el.gfCancel.disabled  = isBusy;

        // Groups add button
        if (_el.addBtn) _el.addBtn.disabled = isBusy;

        // All action buttons inside the list (reorder / edit / delete)
        if (_el.list) {
            _el.list.querySelectorAll("button").forEach((btn) => {
                btn.disabled = isBusy;
            });
        }
    };

    // ── Private Helpers: DOM builders ────────────────────────

    // (_buildTypeBadge removed — badges are no longer shown)

    /**
     * Build a mini card row for display inside a group item.
     * @param {Object} card
     * @returns {HTMLElement}
     */
    const _buildCardRow = (card) => {
        const row = document.createElement("div");
        row.className = "group-card-row";
        row.dataset.cardId = card.id;

        // Masked card number: show only last 4 digits
        const rawNumber = String(card.card_number || "").replace(/\D/g, "");
        const masked = rawNumber.length >= 4
            ? `•••• ${rawNumber.slice(-4)}`
            : rawNumber || "—";

        const numberSpan = document.createElement("span");
        numberSpan.className = "group-card-number";
        numberSpan.textContent = masked;

        const nameSpan = document.createElement("span");
        nameSpan.className = "group-card-holder";
        nameSpan.textContent = card.cardholder_name || "";

        // Reorder buttons for this card
        const upBtn = document.createElement("button");
        upBtn.type = "button";
        upBtn.className = "btn-icon-sm group-card-reorder-btn";
        upBtn.setAttribute("aria-label", "Subir tarjeta");
        upBtn.dataset.cardAction = "card-up";
        upBtn.dataset.cardId     = card.id;
        upBtn.textContent        = "↑";

        const downBtn = document.createElement("button");
        downBtn.type = "button";
        downBtn.className = "btn-icon-sm group-card-reorder-btn";
        downBtn.setAttribute("aria-label", "Bajar tarjeta");
        downBtn.dataset.cardAction = "card-down";
        downBtn.dataset.cardId     = card.id;
        downBtn.textContent        = "↓";

        const actions = document.createElement("div");
        actions.className = "group-card-actions";
        actions.append(upBtn, downBtn);

        row.append(numberSpan, nameSpan, actions);
        return row;
    };

    /**
     * Build a full group item element (group header + card list).
     * @param {Object}  group
     * @param {number}  index        Position in the array (0-based)
     * @param {number}  total        Total number of groups
     * @param {Array}   groupCards   Cards that belong to this group
     * @returns {HTMLElement}
     */
    const _buildGroupItem = (group, index, total, groupCards) => {
        const item = document.createElement("div");
        item.className = "group-item";
        item.dataset.groupId = group.id;

        // ── Group header ──────────────────────────────────────
        const header = document.createElement("div");
        header.className = "group-item__header";

        const info = document.createElement("div");
        info.className = "group-item__info";

        const nameSpan = document.createElement("span");
        nameSpan.className = "group-item__name";
        nameSpan.textContent = group.name;

        // Color dot (shown only if group has a bg_color)
        if (group.bg_color) {
            const dot = document.createElement("span");
            dot.className = "group-item__color-dot";
            dot.style.background = group.bg_color;
            dot.setAttribute("aria-hidden", "true");
            info.append(dot);
        }

        info.append(nameSpan);

        // If group has a cardholder name, show it as subtitle
        if (group.cardholder_name) {
            const sub = document.createElement("span");
            sub.className = "group-item__sub";
            sub.textContent = group.cardholder_name;
            info.appendChild(sub);
        }

        // ── Action buttons ────────────────────────────────────
        const actions = document.createElement("div");
        actions.className = "group-item__actions";

        const editBtn = document.createElement("button");
        editBtn.type = "button";
        editBtn.className = "btn btn-sm btn-ghost";
        editBtn.setAttribute("aria-label", `Editar grupo ${group.name}`);
        editBtn.dataset.groupAction = "edit";
        editBtn.dataset.groupId     = group.id;
        editBtn.textContent         = "✎ Editar";

        const upBtn = document.createElement("button");
        upBtn.type = "button";
        upBtn.className = "btn-icon-sm";
        upBtn.setAttribute("aria-label", "Mover grupo arriba");
        upBtn.dataset.groupAction = "up";
        upBtn.dataset.groupId     = group.id;
        upBtn.disabled            = (index === 0);
        upBtn.textContent         = "↑";

        const downBtn = document.createElement("button");
        downBtn.type = "button";
        downBtn.className = "btn-icon-sm";
        downBtn.setAttribute("aria-label", "Mover grupo abajo");
        downBtn.dataset.groupAction = "down";
        downBtn.dataset.groupId     = group.id;
        downBtn.disabled            = (index === total - 1);
        downBtn.textContent         = "↓";

        const deleteBtn = document.createElement("button");
        deleteBtn.type = "button";
        deleteBtn.className = "btn btn-sm btn-danger";
        deleteBtn.setAttribute("aria-label", `Eliminar grupo ${group.name}`);
        deleteBtn.dataset.groupAction = "delete";
        deleteBtn.dataset.groupId     = group.id;
        deleteBtn.textContent         = "✕ Eliminar";

        actions.append(editBtn, upBtn, downBtn, deleteBtn);
        header.append(info, actions);
        item.appendChild(header);

        // ── Nested card list ──────────────────────────────────
        if (groupCards.length > 0) {
            // Sort cards by card_sort_order ascending
            const sorted = groupCards.slice().sort((a, b) => {
                const ao = Number(a.card_sort_order ?? 0);
                const bo = Number(b.card_sort_order ?? 0);
                return ao - bo;
            });

            const cardList = document.createElement("div");
            cardList.className = "group-card-list";

            sorted.forEach((card) => {
                cardList.appendChild(_buildCardRow(card));
            });

            item.appendChild(cardList);
        }

        return item;
    };

    // ── Private: Render ──────────────────────────────────────

    /**
     * Full re-render of the groups list inside the drawer.
     * Reads current CardState cards to associate them with groups.
     */
    const _renderList = () => {
        if (!_el.list) return;

        _el.list.innerHTML = "";

        if (_groups.length === 0) {
            const empty = document.createElement("p");
            empty.className = "groups-empty";
            empty.textContent = "No hay grupos creados.";
            _el.list.appendChild(empty);
            return;
        }

        // Get all cards from CardState (if available)
        let allCards = [];
        try {
            allCards = (typeof CardState !== "undefined") ? CardState.get("cards") : [];
        } catch (_) { /* CardState not ready */ }

        const fragment = document.createDocumentFragment();
        const total    = _groups.length;

        _groups.forEach((group, index) => {
            const groupCards = allCards.filter(
                (c) => String(c.group_id) === String(group.id)
            );
            fragment.appendChild(_buildGroupItem(group, index, total, groupCards));
        });

        _el.list.appendChild(fragment);
    };

    // ── Private: Form Panel ──────────────────────────────────

    /**
     * Read the currently-selected card type from the main add-card
     * form radio group, so we can pre-fill the group form.
     * Falls back to 'dollarapp' if undetectable.
     * @returns {string}
     */
    const _detectMainFormType = () => {
        try {
            const checked = document.querySelector(".type-selector__radio:checked");
            if (checked && ["dollarapp", "astropay"].includes(checked.value)) {
                return checked.value;
            }
        } catch (_) { /* ignore */ }
        return "dollarapp";
    };

    /** Show the inline form panel in "create" mode. */
    const _openFormCreate = () => {
        _editingGroupId = null;

        if (_el.gfName)    _el.gfName.value    = "";
        if (_el.gfTitular) _el.gfTitular.value = "";
        if (_el.gfCvu)     _el.gfCvu.value     = "";

        // Pre-select card type based on main form
        if (_el.gfType) {
            const detected = _detectMainFormType();
            _el.gfType.value = detected;
        }

        // Generate random color for new groups (same for bg and card)
        const randColor = _color.randomBg();
        if (_el.gfBgColor) { _el.gfBgColor.value = randColor; _syncColorPreview(); }

        // Reset card_color toggle — default to same color
        if (_el.gfCardColorCheck) _el.gfCardColorCheck.checked = false;
        if (_el.gfCardColorWrap)  _el.gfCardColorWrap.classList.add("hidden");
        if (_el.gfCardColor)      _el.gfCardColor.value = randColor;

        // Resets panel: hidden on create (defaults handled server-side)
        _syncResetsPanel(false);

        if (_el.gfSave) _el.gfSave.textContent = "Guardar grupo";
        if (_el.formPanel) {
            _el.formPanel.classList.remove("hidden");
            _el.formPanel.setAttribute("aria-hidden", "false");
        }
        if (_el.gfName) _el.gfName.focus();
    };

    /**
     * Show the inline form panel in "edit" mode, pre-filled with group data.
     * @param {Object} group
     */
    const _openFormEdit = (group) => {
        _editingGroupId = group.id;

        if (_el.gfName)    _el.gfName.value    = group.name || "";
        if (_el.gfType)    _el.gfType.value    = group.card_type || "dollarapp";
        if (_el.gfTitular) _el.gfTitular.value = group.cardholder_name || "";
        if (_el.gfCvu)     _el.gfCvu.value     = group.cvu || "";

        // Colors
        const bgCol = group.bg_color || _color.randomBg();
        if (_el.gfBgColor) { _el.gfBgColor.value = bgCol; _syncColorPreview(); }

        const hasCardColor = !!group.card_color;
        if (_el.gfCardColorCheck) _el.gfCardColorCheck.checked = hasCardColor;
        if (_el.gfCardColorWrap)  _el.gfCardColorWrap.classList.toggle("hidden", !hasCardColor);
        if (_el.gfCardColor)      _el.gfCardColor.value = group.card_color || _color.deriveCard(bgCol);
        _syncCardColorPreview();

        // Reset window fields (DollarApp only)
        const isDollarApp = (group.card_type || "dollarapp") === "dollarapp";
        _syncResetsPanel(isDollarApp, group);

        if (_el.gfSave) _el.gfSave.textContent = "Actualizar";
        if (_el.formPanel) {
            _el.formPanel.classList.remove("hidden");
            _el.formPanel.setAttribute("aria-hidden", "false");
            _el.formPanel.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
        if (_el.gfName) _el.gfName.focus();
    };

    /** Hide and reset the inline form panel. */
    const _closeForm = () => {
        _editingGroupId = null;

        if (_el.gfName)    _el.gfName.value    = "";
        if (_el.gfTitular) _el.gfTitular.value = "";
        if (_el.gfCvu)     _el.gfCvu.value     = "";
        if (_el.gfType)    _el.gfType.value    = "dollarapp";
        if (_el.gfCardColorCheck) _el.gfCardColorCheck.checked = false;
        if (_el.gfCardColorWrap)  _el.gfCardColorWrap.classList.add("hidden");

        _syncResetsPanel(false);

        if (_el.formPanel) {
            _el.formPanel.classList.add("hidden");
            _el.formPanel.setAttribute("aria-hidden", "true");
        }
    };

    /** Sync the bg_color preview swatch. */
    const _syncColorPreview = () => {
        if (_el.gfBgPreview && _el.gfBgColor) {
            _el.gfBgPreview.style.background = _el.gfBgColor.value;
        }
    };

    /** Sync the card_color preview swatch. */
    const _syncCardColorPreview = () => {
        if (_el.gfCardPreview && _el.gfCardColor) {
            _el.gfCardPreview.style.background = _el.gfCardColor.value;
        }
    };    /**
     * Show or hide the reset-window editor panel and populate fields.
     * @param {boolean} show  Whether to show the panel
     * @param {Object}  [group]  Group data to populate fields (edit mode)
     */
    const _syncResetsPanel = (show, group = null) => {
        const panel = _el.gfResetsPanel;
        if (!panel) return;

        panel.style.display = show ? "" : "none";

        if (!show) return;

        // Populate fields
        if (_el.gfResetsTotal) {
            _el.gfResetsTotal.value = group?.resets_remaining ?? 30;
        }
        if (_el.gfResetsMonthly) {
            _el.gfResetsMonthly.value = group?.monthly_resets_used ?? 0;
        }
        if (_el.gfResetsWindow) {
            // Convert UTC datetime to local datetime-local input value
            const raw = group?.window_started_at;
            if (raw) {
                try {
                    const dt = new Date(raw.endsWith("Z") ? raw : raw + "Z");
                    // Format: YYYY-MM-DDTHH:MM
                    const pad = (n) => String(n).padStart(2, "0");
                    _el.gfResetsWindow.value =
                        `${dt.getFullYear()}-${pad(dt.getMonth()+1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
                } catch (_) {
                    _el.gfResetsWindow.value = "";
                }
            } else {
                _el.gfResetsWindow.value = "";
            }
        }
    };


    /**
     * Collect and validate form data, including colors.
     * CVU is now REQUIRED for groups.
     * @returns {Object|null}
     */
    const _collectFormData = () => {
        const name            = (_el.gfName    ? _el.gfName.value.trim()    : "");
        const card_type       = (_el.gfType    ? _el.gfType.value           : "dollarapp");
        const cardholder_name = (_el.gfTitular ? _el.gfTitular.value.trim() : "");
        const cvu             = (_el.gfCvu     ? _el.gfCvu.value.replace(/\D/g,"") : "");
        const bg_color        = (_el.gfBgColor ? _el.gfBgColor.value        : null);
        const useCardColor    = _el.gfCardColorCheck?.checked ?? false;
        const card_color      = (useCardColor && _el.gfCardColor)
            ? _el.gfCardColor.value
            : null;

        if (!name) {
            if (_el.gfName) _el.gfName.focus();
            CardDOM.toastError("El nombre del grupo es obligatorio.");
            return null;
        }

        if (!["dollarapp", "astropay"].includes(card_type)) {
            CardDOM.toastError("Tipo de tarjeta inválido.");
            return null;
        }

        if (!cvu || cvu.length < 6) {
            if (_el.gfCvu) _el.gfCvu.focus();
            CardDOM.toastError("El CVU del grupo es obligatorio (mínimo 6 dígitos).");
            return null;
        }

        const result = { name, card_type, cardholder_name, cvu, bg_color, card_color };

        // Include reset-window fields only when editing (not on create)
        if (_editingGroupId !== null && card_type === "dollarapp") {
            if (_el.gfResetsTotal) {
                result.resets_remaining = Math.max(0, Math.min(999, parseInt(_el.gfResetsTotal.value, 10) || 0));
            }
            if (_el.gfResetsMonthly) {
                result.monthly_resets_used = Math.max(0, Math.min(10, parseInt(_el.gfResetsMonthly.value, 10) || 0));
            }
            if (_el.gfResetsWindow) {
                const v = _el.gfResetsWindow.value;
                if (v) {
                    // Convert local datetime-local to ISO UTC string
                    result.window_started_at = new Date(v).toISOString();
                } else {
                    result.window_started_at = null; // cleared → server sets to NULL
                }
            }
        }

        return result;
    };

    // ── Private: Group Reorder Helpers ───────────────────────

    /**
     * Move a group at `fromIndex` to `toIndex` within _groups,
     * reassign sort_order 0…n, and batch-update the server.
     * @param {number} fromIndex
     * @param {number} toIndex
     */
    const _moveGroup = async (fromIndex, toIndex) => {
        if (fromIndex < 0 || toIndex < 0) return;
        if (fromIndex >= _groups.length || toIndex >= _groups.length) return;
        if (fromIndex === toIndex) return;

        if (_busy) return;

        // Mutate locally first for instant feedback
        const reordered = _groups.slice();
        const [moved]   = reordered.splice(fromIndex, 1);
        reordered.splice(toIndex, 0, moved);

        // Re-assign sort_order sequentially
        const items = reordered.map((g, i) => ({ id: g.id, sort_order: i }));
        // Update local state optimistically
        items.forEach(({ id, sort_order }) => {
            const g = reordered.find((x) => x.id === id);
            if (g) g.sort_order = sort_order;
        });

        _setGroups(reordered);
        _renderList();
        _populateSelects();

        _setDrawerBusy(true);
        try {
            await _post({ action: "reorder_groups", items });
        } catch (err) {
            CardDOM.toastError("Error al reordenar grupos: " + err.message);
            // Re-fetch to restore consistent server state
            try {
                const data = await _get("list");
                _setGroups(data.groups || []);
                _renderList();
                _populateSelects();
            } catch (_) { /* ignore secondary failure */ }
        } finally {
            _setDrawerBusy(false);
        }
    };

    // ── Private: Card Reorder Helpers ────────────────────────

    /**
     * Move a card up or down within its group's order.
     * Determines sibling cards in the same group from CardState,
     * reassigns card_sort_order, and calls the server.
     * @param {string|number} cardId
     * @param {'up'|'down'}   direction
     */
    const _moveCard = async (cardId, direction) => {
        if (_busy) return;

        let allCards = [];
        try {
            allCards = (typeof CardState !== "undefined") ? CardState.get("cards") : [];
        } catch (_) { return; }

        // Find target card
        const card = allCards.find((c) => String(c.id) === String(cardId));
        if (!card) return;

        const groupId = card.group_id;

        // Get siblings sorted by card_sort_order
        const siblings = allCards
            .filter((c) => String(c.group_id) === String(groupId))
            .slice()
            .sort((a, b) => Number(a.card_sort_order ?? 0) - Number(b.card_sort_order ?? 0));

        const idx = siblings.findIndex((c) => String(c.id) === String(cardId));
        if (idx === -1) return;

        const swapIdx = direction === "up" ? idx - 1 : idx + 1;
        if (swapIdx < 0 || swapIdx >= siblings.length) return;

        // Swap
        const reordered = siblings.slice();
        [reordered[idx], reordered[swapIdx]] = [reordered[swapIdx], reordered[idx]];

        // Build items payload
        const items = reordered.map((c, i) => ({ id: c.id, card_sort_order: i }));

        // Optimistic state update
        items.forEach(({ id, card_sort_order }) => {
            const c = allCards.find((x) => String(x.id) === String(id));
            if (c) c.card_sort_order = card_sort_order;
        });

        if (typeof CardState !== "undefined") {
            // CardState doesn't have a direct "setCards" that mutates in place,
            // but we can use updateCard for each modified card.
            items.forEach(({ id, card_sort_order }) => {
                const full = allCards.find((x) => String(x.id) === String(id));
                if (full) {
                    CardState.updateCard({ ...full, card_sort_order });
                }
            });
        }

        _renderList();

        _setDrawerBusy(true);
        try {
            await _post({ action: "reorder_cards", items });
        } catch (err) {
            CardDOM.toastError("Error al reordenar tarjetas: " + err.message);
        } finally {
            _setDrawerBusy(false);
        }
    };

    // ── Private: Event delegation for the drawer ─────────────

    /**
     * Handle all delegated click events within the groups list.
     * Uses data-group-action and data-card-action attributes.
     * @param {MouseEvent} e
     */
    const _onListClick = async (e) => {
        if (_busy) return;

        // ── Group-level actions ───────────────────────────────
        const groupBtn = e.target.closest("[data-group-action]");
        if (groupBtn) {
            const action  = groupBtn.dataset.groupAction;
            const groupId = Number(groupBtn.dataset.groupId);
            const group   = _groups.find((g) => g.id === groupId);

            switch (action) {
                case "edit":
                    if (group) _openFormEdit(group);
                    break;

                case "up": {
                    const idx = _groups.findIndex((g) => g.id === groupId);
                    await _moveGroup(idx, idx - 1);
                    break;
                }

                case "down": {
                    const idx = _groups.findIndex((g) => g.id === groupId);
                    await _moveGroup(idx, idx + 1);
                    break;
                }

                case "delete":
                    await _handleDeleteGroup(groupId, group ? group.name : String(groupId));
                    break;
            }
            return;
        }

        // ── Card-level reorder actions ────────────────────────
        const cardBtn = e.target.closest("[data-card-action]");
        if (cardBtn) {
            const action = cardBtn.dataset.cardAction;
            const cardId = cardBtn.dataset.cardId;

            if (action === "card-up")   await _moveCard(cardId, "up");
            if (action === "card-down") await _moveCard(cardId, "down");
        }
    };

    /**
     * Handle deletion of a group after user confirmation.
     * @param {number} groupId
     * @param {string} groupName
     */
    const _handleDeleteGroup = async (groupId, groupName) => {
        const confirmed = await CardDOM.confirm(
            "Eliminar grupo",
            `¿Eliminar el grupo "${groupName}"? Las tarjetas asociadas quedarán sin grupo.`
        );
        if (!confirmed) return;

        _setDrawerBusy(true);
        try {
            await _post({ action: "delete", id: groupId });

            // Remove from local state
            _setGroups(_groups.filter((g) => g.id !== groupId));

            // Clear group_id on any associated cards in CardState
            if (typeof CardState !== "undefined") {
                const cards = CardState.get("cards");
                cards.forEach((c) => {
                    if (String(c.group_id) === String(groupId)) {
                        CardState.updateCard({ ...c, group_id: null });
                    }
                });
            }

            _renderList();
            _populateSelects();
            CardDOM.toast(`Grupo "${groupName}" eliminado.`);
        } catch (err) {
            CardDOM.toastError("Error al eliminar el grupo: " + err.message);
        } finally {
            _setDrawerBusy(false);
        }
    };

    /**
     * Handle form submission (create or update a group).
     */
    const _handleFormSave = async () => {
        if (_busy) return;

        const payload = _collectFormData();
        if (!payload) return;

        _setDrawerBusy(true);

        try {
            if (_editingGroupId === null) {
                // ── CREATE ────────────────────────────────────
                const data = await _post({ action: "create", ...payload });
                const newGroup = data.group;

                _setGroups([..._groups, newGroup]);
                _renderList();
                _populateSelects();
                _closeForm();
                CardDOM.toast(`Grupo "${newGroup.name}" creado.`);
            } else {
                // ── UPDATE ────────────────────────────────────
                const data = await _post({ action: "update", id: _editingGroupId, ...payload });
                const updated = data.group;

                _setGroups(_groups.map((g) => (g.id === updated.id ? updated : g)));
                _renderList();
                _populateSelects();
                _closeForm();
                CardDOM.toast(`Grupo "${updated.name}" actualizado.`);
            }
        } catch (err) {
            CardDOM.toastError("Error al guardar el grupo: " + err.message);
        } finally {
            _setDrawerBusy(false);
        }
    };

    // ── Private: Custom Dropdown Builder ─────────────────────

    /**
     * Build or refresh a custom dropdown UI attached to a hidden <select>.
     * The dropdown shows a color dot + group name for each option.
     * @param {HTMLSelectElement} selectEl
     */
    const _buildCustomDropdown = (selectEl) => {
        if (!selectEl) return;

        const container = selectEl.closest(".group-select-wrapper") || selectEl.parentElement;
        let box = container.querySelector(".gsc-box");

        if (!box) {
            box = document.createElement("div");
            box.className = "gsc-box";
            container.insertBefore(box, selectEl);
        }

        const currentId  = selectEl.value;
        const current    = currentId ? _groups.find((g) => String(g.id) === currentId) : null;
        const dotStyle   = current?.bg_color
            ? `background:${current.bg_color}`
            : `background:transparent;border:2px dashed var(--border-primary)`;
        const labelText  = current ? current.name : "— Sin grupo —";

        box.innerHTML = `
            <div class="gsc-trigger" tabindex="0" role="combobox" aria-haspopup="listbox" aria-expanded="false">
                <span class="gsc-dot" style="${dotStyle}"></span>
                <span class="gsc-label">${_esc(labelText)}</span>
                <svg class="gsc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="gsc-dropdown hidden" role="listbox">
                <button class="gsc-option${!currentId ? " is-selected" : ""}" data-val="" role="option" type="button">
                    <span class="gsc-dot gsc-dot--empty"></span>
                    <span>— Sin grupo —</span>
                </button>
                ${_groups.map((g) => {
                    const dot = g.bg_color ? `background:${g.bg_color}` : `background:var(--border-primary)`;
                    const sel = String(g.id) === currentId ? " is-selected" : "";
                    return `<button class="gsc-option${sel}" data-val="${g.id}" role="option" type="button">
                        <span class="gsc-dot" style="${dot}"></span>
                        <span>${_esc(g.name)}</span>
                    </button>`;
                }).join("")}
            </div>`;

        const trigger  = box.querySelector(".gsc-trigger");
        const dropdown = box.querySelector(".gsc-dropdown");

        const _open = () => {
            // Close any other open dropdowns first
            document.querySelectorAll(".gsc-dropdown:not(.hidden)").forEach((d) => {
                d.classList.add("hidden");
                d.previousElementSibling?.setAttribute("aria-expanded", "false");
            });
            dropdown.classList.remove("hidden");
            trigger.setAttribute("aria-expanded", "true");
        };
        const _close = () => {
            dropdown.classList.add("hidden");
            trigger.setAttribute("aria-expanded", "false");
        };
        const _toggle = () => dropdown.classList.contains("hidden") ? _open() : _close();

        trigger.addEventListener("click", (e) => { e.stopPropagation(); _toggle(); });
        trigger.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === " ") { e.preventDefault(); _toggle(); }
            if (e.key === "Escape") _close();
        });

        box.querySelectorAll(".gsc-option").forEach((btn) => {
            btn.addEventListener("click", (e) => {
                e.stopPropagation();
                const val = btn.dataset.val;
                selectEl.value = val;
                selectEl.dispatchEvent(new Event("change", { bubbles: true }));
                _buildCustomDropdown(selectEl); // refresh UI
                _close();
            });
        });
    };

    /** HTML-escape helper to prevent XSS in template literals. */
    const _esc = (str) => String(str)
        .replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");

    // ── Private: Populate Selects ────────────────────────────

    /**
     * Populate all hidden group <select> elements and rebuild
     * their custom dropdown UIs.
     */
    const _populateSelects = () => {
        const targets = new Set();

        document.querySelectorAll(".group-select-el").forEach((el) => targets.add(el));
        const gs  = document.getElementById("group-select");
        const egs = document.getElementById("edit-group-select");
        if (gs)  targets.add(gs);
        if (egs) targets.add(egs);

        targets.forEach((select) => {
            const prev = select.value;

            select.innerHTML = "";
            const noGroup = document.createElement("option");
            noGroup.value = ""; noGroup.textContent = "— Sin grupo —";
            select.appendChild(noGroup);

            _groups.forEach((group) => {
                const opt = document.createElement("option");
                opt.value = group.id;
                opt.textContent = group.name;
                select.appendChild(opt);
            });

            if ([...select.options].some((o) => o.value === prev)) {
                select.value = prev;
            }

            // Rebuild custom dropdown UI
            _buildCustomDropdown(select);
        });
    };

    // ── Private: Drawer Open / Close ─────────────────────────

    const _openDrawer = () => {
        if (!_el.drawer) return;
        _renderList();
        _el.drawer.classList.remove("hidden", "is-leaving");
        _el.drawer.classList.add("is-open");
        _el.drawer.setAttribute("aria-hidden", "false");
        // Focus management: move focus to close button
        if (_el.closeBtn) setTimeout(() => _el.closeBtn.focus(), 50);
    };

    const _closeDrawer = () => {
        if (!_el.drawer) return;
        _closeForm();
        _el.drawer.classList.remove("is-open");
        _el.drawer.classList.add("is-leaving");
        _el.drawer.setAttribute("aria-hidden", "true");

        let fired = false;
        const onEnd = () => {
            if (fired) return;
            fired = true;
            _el.drawer.classList.add("hidden");
            _el.drawer.classList.remove("is-leaving");
        };

        setTimeout(onEnd, 380); // matches CSS duration-slow (350ms) + buffer
    };

    // ── Private: Init ────────────────────────────────────────

    /**
     * Cache all drawer DOM elements and wire events.
     * Safe to call multiple times (idempotent via guard flag).
     */
    let _drawerInitialized = false;

    const _initDrawer = () => {
        if (_drawerInitialized) return;
        _drawerInitialized = true;

        _el.toggleBtn  = document.getElementById("groups-toggle");
        _el.drawer     = document.getElementById("groups-drawer");
        _el.closeBtn   = document.getElementById("groups-drawer-close");
        _el.list       = document.getElementById("groups-list");
        _el.addBtn     = document.getElementById("groups-add-btn");
        _el.formPanel  = document.getElementById("group-form-panel");
        _el.gfName           = document.getElementById("gf-name");
        _el.gfType           = document.getElementById("gf-type");
        _el.gfTitular        = document.getElementById("gf-titular");
        _el.gfCvu            = document.getElementById("gf-cvu");
        _el.gfBgColor        = document.getElementById("gf-bg-color");
        _el.gfBgRandom       = document.getElementById("gf-bg-random");
        _el.gfBgPreview      = document.getElementById("gf-bg-preview");
        _el.gfCardColorCheck = document.getElementById("gf-card-color-check");
        _el.gfCardColorWrap  = document.getElementById("gf-card-color-wrap");
        _el.gfCardColor      = document.getElementById("gf-card-color");
        _el.gfCardColorDerive= document.getElementById("gf-card-color-derive");
        _el.gfCardPreview    = document.getElementById("gf-card-preview");
        _el.gfCancel         = document.getElementById("gf-cancel");
        _el.gfSave           = document.getElementById("gf-save");

        // Reset-window panel elements
        _el.gfResetsPanel    = document.getElementById("gf-resets-panel");
        _el.gfResetsTotal    = document.getElementById("gf-resets-total");
        _el.gfResetsMonthly  = document.getElementById("gf-resets-monthly");
        _el.gfResetsWindow   = document.getElementById("gf-resets-window");
        _el.gfResetsWindowNow   = document.getElementById("gf-resets-window-now");
        _el.gfResetsWindowClear = document.getElementById("gf-resets-window-clear");

        // ── Color form events ─────────────────────────────────

        // bg_color picker → live preview
        if (_el.gfBgColor) {
            _el.gfBgColor.addEventListener("input", _syncColorPreview);
        }

        // Random bg_color button
        if (_el.gfBgRandom) {
            _el.gfBgRandom.addEventListener("click", () => {
                const c = _color.randomBg();
                if (_el.gfBgColor) _el.gfBgColor.value = c;
                _syncColorPreview();
                // Auto-derive card color if toggle is on
                if (_el.gfCardColorCheck?.checked && _el.gfCardColor) {
                    _el.gfCardColor.value = _color.deriveCard(c);
                    _syncCardColorPreview();
                }
            });
        }

        // card_color checkbox toggle
        if (_el.gfCardColorCheck) {
            _el.gfCardColorCheck.addEventListener("change", () => {
                const on = _el.gfCardColorCheck.checked;
                if (_el.gfCardColorWrap) _el.gfCardColorWrap.classList.toggle("hidden", !on);
                if (on && _el.gfCardColor && _el.gfBgColor) {
                    // Auto-derive from current bg_color on first activation
                    _el.gfCardColor.value = _color.deriveCard(_el.gfBgColor.value);
                    _syncCardColorPreview();
                }
            });
        }

        // card_color picker → live preview
        if (_el.gfCardColor) {
            _el.gfCardColor.addEventListener("input", _syncCardColorPreview);
        }

        // Auto-derive card color from current bg_color
        if (_el.gfCardColorDerive) {
            _el.gfCardColorDerive.addEventListener("click", () => {
                if (_el.gfBgColor && _el.gfCardColor) {
                    _el.gfCardColor.value = _color.deriveCard(_el.gfBgColor.value);
                    _syncCardColorPreview();
                }
            });
        }

        // ── Reset-window shortcut buttons ─────────────────────

        if (_el.gfResetsWindowNow) {
            _el.gfResetsWindowNow.addEventListener("click", () => {
                if (_el.gfResetsWindow) {
                    const now = new Date();
                    const pad = (n) => String(n).padStart(2, "0");
                    _el.gfResetsWindow.value =
                        `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
                }
                if (_el.gfResetsMonthly) _el.gfResetsMonthly.value = 0;
            });
        }

        if (_el.gfResetsWindowClear) {
            _el.gfResetsWindowClear.addEventListener("click", () => {
                if (_el.gfResetsWindow)  _el.gfResetsWindow.value = "";
                if (_el.gfResetsMonthly) _el.gfResetsMonthly.value = 0;
            });
        }

        // Toggle resets panel when card type changes
        if (_el.gfType) {
            _el.gfType.addEventListener("change", () => {
                const isDollarApp = _el.gfType.value === "dollarapp";
                // Only show panel in edit mode
                if (_editingGroupId !== null) {
                    _syncResetsPanel(isDollarApp);
                }
            });
        }

        // ── Header toggle button ──────────────────────────────
        if (_el.toggleBtn) {
            _el.toggleBtn.addEventListener("click", () => {
                if (!_el.drawer) return;
                const isOpen = _el.drawer.classList.contains("is-open");
                if (isOpen) {
                    _el.toggleBtn.classList.remove("is-active");
                    _closeDrawer();
                } else {
                    _el.toggleBtn.classList.add("is-active");
                    _openDrawer();
                }
            });
        }

        // ── Drawer close (X button) ───────────────────────────
        if (_el.closeBtn) {
            _el.closeBtn.addEventListener("click", () => {
                if (_el.toggleBtn) _el.toggleBtn.classList.remove("is-active");
                _closeDrawer();
            });
        }

        // ── Backdrop click (click outside the panel) ──────────
        if (_el.drawer) {
            _el.drawer.addEventListener("click", (e) => {
                const backdrop = document.getElementById("groups-drawer-backdrop");
                if (e.target === backdrop) {
                    if (_el.toggleBtn) _el.toggleBtn.classList.remove("is-active");
                    _closeDrawer();
                }
            });
        }

        // ── Escape key ────────────────────────────────────────
        document.addEventListener("keydown", (e) => {
            if (e.key !== "Escape") return;
            if (!_el.drawer) return;
            const isOpen = _el.drawer.classList.contains("is-open");
            if (isOpen) {
                e.stopPropagation(); // prevent other Escape handlers
                if (_el.toggleBtn) _el.toggleBtn.classList.remove("is-active");
                _closeDrawer();
            }
        });

        // ── "+ Nuevo grupo" button ────────────────────────────
        if (_el.addBtn) {
            _el.addBtn.addEventListener("click", () => {
                if (_busy) return;
                const isVisible = _el.formPanel && !_el.formPanel.classList.contains("hidden");
                if (isVisible && _editingGroupId === null) {
                    // Toggle off if already in create mode
                    _closeForm();
                } else {
                    _openFormCreate();
                }
            });
        }

        // ── Form save ─────────────────────────────────────────
        if (_el.gfSave) {
            _el.gfSave.addEventListener("click", (e) => {
                e.preventDefault();
                _handleFormSave();
            });
        }

        // ── Form cancel ───────────────────────────────────────
        if (_el.gfCancel) {
            _el.gfCancel.addEventListener("click", (e) => {
                e.preventDefault();
                _closeForm();
            });
        }

        // ── Close dropdowns on outside click ──────────────────
        document.addEventListener("click", () => {
            document.querySelectorAll(".gsc-dropdown:not(.hidden)").forEach((d) => {
                d.classList.add("hidden");
                const trigger = d.previousElementSibling;
                if (trigger) trigger.setAttribute("aria-expanded", "false");
            });
        });

        // ── Allow Enter key in name field to submit ───────────
        if (_el.gfName) {
            _el.gfName.addEventListener("keydown", (e) => {
                if (e.key === "Enter") {
                    e.preventDefault();
                    _handleFormSave();
                }
            });
        }

        // ── List event delegation ─────────────────────────────
        if (_el.list) {
            _el.list.addEventListener("click", _onListClick);
        }

        // ── Subscribe to CardState changes to re-render list ──
        if (typeof CardState !== "undefined") {
            CardState.subscribe("cards", () => {
                // Re-render list if drawer is open (cards changed → card rows update)
                if (_el.drawer && !_el.drawer.classList.contains("hidden")) {
                    _renderList();
                }
            });
        }
    };

    // ── Public API ───────────────────────────────────────────

    return Object.freeze({

        /**
         * Load groups from the server and set internal state.
         * Call once on app boot (after CardDOM.init()).
         * @returns {Promise<void>}
         */
        async load() {
            try {
                const data = await _get("list");
                _setGroups(data.groups || []);
                _populateSelects();
            } catch (err) {
                // Non-fatal: app still works without groups
                if (typeof CardDOM !== "undefined") {
                    CardDOM.toastError("Error al cargar grupos: " + err.message);
                }
            }
        },

        /**
         * Get a shallow copy of all groups.
         * @returns {Array}
         */
        getAll() {
            return _groups.slice();
        },

        /**
         * Get a single group by ID.
         * @param {number|string} id
         * @returns {Object|null}
         */
        getById(id) {
            return _groups.find((g) => String(g.id) === String(id)) || null;
        },

        /**
         * Merge partial data into an existing group's internal state.
         * Used by app.js to reflect server-side changes (e.g., resets after card creation)
         * without doing a full reload.
         * @param {number|string} id
         * @param {Object} partial  e.g. { resets_remaining: 29, monthly_resets_used: 1, window_started_at: "..." }
         */
        updateGroupPartial(id, partial) {
            const idx = _groups.findIndex((g) => String(g.id) === String(id));
            if (idx === -1) return;
            _groups[idx] = { ..._groups[idx], ...partial };
        },

        /**
         * Try to find a group that matches the given attributes.
         * - cardholder_name comparison: case-insensitive trim
         * - cvu comparison:             exact string match
         * - card_type comparison:       exact string match
         * Any falsy argument is skipped in the comparison.
         *
         * @param {string} cardholderName
         * @param {string} cvu
         * @param {string} cardType
         * @returns {Object|null}
         */
        findMatch(cardholderName, cvu, cardType) {
            const normName = cardholderName ? cardholderName.trim().toLowerCase() : null;
            const normCvu  = cvu            ? cvu.replace(/\D/g, "")             : null;
            const normType = cardType       ? cardType.trim()                     : null;

            // Require at minimum a non-empty name AND a card type to attempt matching
            if (!normName || !normType) return null;

            return _groups.find((g) => {
                // Type must match exactly
                if (g.card_type !== normType) return false;

                // Name must match (case-insensitive, trimmed)
                if (g.cardholder_name.trim().toLowerCase() !== normName) return false;

                // CVU: only check if the group has a CVU
                if (g.cvu) {
                    // If group has CVU, user must provide a matching CVU
                    if (normCvu !== g.cvu.replace(/\D/g, "")) return false;
                }

                return true;
            }) || null;
        },

        /**
         * Subscribe to groups state changes.
         * The callback receives the current groups array on each change.
         * @param {Function} fn  - (groups: Array) => void
         * @returns {Function}   - Unsubscribe function
         */
        subscribe(fn) {
            _subscribers.add(fn);
            return () => _subscribers.delete(fn);
        },

        /**
         * Initialize the drawer DOM. Wire events.
         * Must be called once after DOMContentLoaded.
         * @returns {void}
         */
        initDrawer() {
            _initDrawer();
        },

        /**
         * Repopulate all group <select> elements.
         * Automatically called after any state change;
         * exposed for external callers (e.g., after form renders).
         * @returns {void}
         */
        populateSelects() {
            _populateSelects();
        },

        /**
         * Open the groups drawer.
         * @returns {void}
         */
        openDrawer() {
            _openDrawer();
        },

        /**
         * Close the groups drawer.
         * @returns {void}
         */
        closeDrawer() {
            _closeDrawer();
        },

        // ── CRUD ─────────────────────────────────────────────

        /**
         * Create a new group on the server and update state.
         * @param {{name:string, card_type:string, cardholder_name:string, cvu:string}} data
         * @returns {Promise<Object>} The created group object
         */
        async create(data) {
            const result = await _post({ action: "create", ...data });
            const group  = result.group;
            _setGroups([..._groups, group]);
            _populateSelects();
            return group;
        },

        /**
         * Update an existing group on the server and update state.
         * @param {number|string} id
         * @param {{name:string, card_type:string, cardholder_name:string, cvu:string}} data
         * @returns {Promise<Object>} The updated group object
         */
        async update(id, data) {
            const result  = await _post({ action: "update", id, ...data });
            const updated = result.group;
            _setGroups(_groups.map((g) => (String(g.id) === String(updated.id) ? updated : g)));
            _populateSelects();
            return updated;
        },

        /**
         * Delete a group on the server, update state, and clear any
         * associated card group_id references in CardState.
         * @param {number|string} id
         * @returns {Promise<void>}
         */
        async delete(id) {
            await _post({ action: "delete", id });
            _setGroups(_groups.filter((g) => String(g.id) !== String(id)));
            _populateSelects();

            // Clear group_id on associated cards in CardState
            if (typeof CardState !== "undefined") {
                const cards = CardState.get("cards");
                cards.forEach((c) => {
                    if (String(c.group_id) === String(id)) {
                        CardState.updateCard({ ...c, group_id: null });
                    }
                });
            }
        },

        /**
         * Batch reorder groups.
         * @param {Array<{id:number, sort_order:number}>} items
         * @returns {Promise<void>}
         */
        async reorderGroups(items) {
            const result = await _post({ action: "reorder_groups", items });
            if (result.groups) {
                _setGroups(result.groups);
                _populateSelects();
            }
        },

        /**
         * Batch reorder cards within a group.
         * @param {Array<{id:number, card_sort_order:number}>} items
         * @returns {Promise<void>}
         */
        async reorderCards(items) {
            await _post({ action: "reorder_cards", items });
        },

        /**
         * Expose color utilities to other modules (dom.js).
         */
        colorUtils: _color,

        /**
         * Rebuild custom dropdown for a given select element.
         * Called externally by app.js after it sets a value programmatically.
         * @param {HTMLSelectElement} selectEl
         */
        refreshDropdown(selectEl) {
            _buildCustomDropdown(selectEl);
        },
    });

})();

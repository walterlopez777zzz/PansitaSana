"use strict";

/**
 * ============================================================
 * Card Manager — Application Orchestrator
 * ============================================================
 * Wires together State, API, DOM, Timer, and History modules.
 * Handles event delegation, user actions, undo/redo, and lifecycle.
 */

const CardApp = (() => {
    // ── Theme Management ─────────────────────────────────────

    const THEME_KEY = "card-manager-theme";

    const _initTheme = () => {
        const stored = localStorage.getItem(THEME_KEY);
        const preferred = window.matchMedia("(prefers-color-scheme: dark)").matches
            ? "dark"
            : "light";
        const theme = stored || preferred;

        CardState.setTheme(theme);
        CardDOM.applyTheme(theme);
    };

    const _toggleTheme = () => {
        const current = CardState.getTheme();
        const next = current === "light" ? "dark" : "light";

        CardState.setTheme(next);
        CardDOM.applyTheme(next);
        localStorage.setItem(THEME_KEY, next);
    };

    // ── Groups ───────────────────────────────────────────────

    /**
     * Load groups and wire the groups-toggle button.
     * Also subscribes to group changes so dropdowns stay fresh.
     */
    const _initGroups = async () => {
        try {
            CardGroups.initDrawer();
            await CardGroups.load();

            // Re-render cards when groups change (badges update)
            CardGroups.subscribe(() => {
                CardDOM.renderCards(CardState.get("cards"));
            });
        } catch (err) {
            CardDOM.toastError("Error al cargar grupos: " + err.message);
        }
    };

    /**
     * Set up autodetect: when the user selects a group from the dropdown,
     * auto-fill the form fields. When the user manually edits Titular/CVU/Tipo,
     * check if the values match any group and auto-select it (or clear it).
     */
    const _setupGroupAutodetect = () => {
        const groupSelect  = document.getElementById("group-select");
        const titularInput = document.getElementById("titular-input");
        const cvuInput     = document.getElementById("cvu-input");

        if (!groupSelect) return;

        // When user picks a group → autofill Titular, CVU, Tipo
        groupSelect.addEventListener("change", () => {
            const selectedId = groupSelect.value;
            if (!selectedId) return; // "Sin grupo" — do nothing

            const group = CardGroups.getById(Number(selectedId));
            if (group) CardDOM.fillFormFromGroup(group);
        });

        // When user edits Titular or CVU manually → try to match a group
        const _tryAutodetect = () => {
            const name = titularInput ? titularInput.value.trim() : "";
            const cvu  = cvuInput     ? cvuInput.value.replace(/\D/g, "") : "";
            const type = CardDOM.getSelectedCardType();

            const match = CardGroups.findMatch(name, cvu, type);

            if (match) {
                // Only update the group select — don't overwrite the fields
                CardDOM.setGroupSelectValue(match.id);
            } else {
                CardDOM.clearGroupSelect();
            }
        };

        if (titularInput) titularInput.addEventListener("input", _tryAutodetect);
        if (cvuInput)     cvuInput.addEventListener("input",     _tryAutodetect);

        // Also listen for type changes (radio group)
        document.querySelectorAll(".type-selector__radio").forEach((radio) => {
            radio.addEventListener("change", _tryAutodetect);
        });
    };

    // ── Data Loading ─────────────────────────────────────────

    const _loadCards = async () => {
        CardState.setLoading(true);
        CardDOM.setLoading(true);

        try {
            const data = await CardAPI.fetchCards();

            CardState.setServerTimeDelta(data.server_time);
            CardState.setCards(data.cards || []);
            CardDOM.renderCards(CardState.get("cards"));
        } catch (err) {
            CardDOM.toastError("Error al cargar tarjetas: " + err.message);
        } finally {
            CardState.setLoading(false);
            CardDOM.setLoading(false);
        }
    };

    // ── Card Actions ─────────────────────────────────────────

    const _handleAddCard = async (e) => {
        e.preventDefault();

        const cardNumber      = CardDOM.getCardNumber();
        const cvu             = CardDOM.getCvu();
        const cvc             = CardDOM.getCvc();
        const expiration      = CardDOM.getExpiration();
        const cardType        = CardDOM.getSelectedCardType();
        const cardholderName  = CardDOM.getTitularValue();

        if (cardNumber.length < 13 || cardNumber.length > 16) return;
        if (cvc.length < 3 || cvc.length > 4) return;
        if (expiration.length !== 5) return;
        if (!["dollarapp", "astropay"].includes(cardType)) return;
        if (!cardholderName) return;

        CardDOM.setAddButtonLoading(true);

        try {
            const groupId = CardDOM.getSelectedGroupId();
            const data = await CardAPI.createCard(
                cardNumber, cvu, cvc, expiration, cardType, cardholderName, groupId
            );

            CardState.setServerTimeDelta(data.server_time);
            CardState.addCard(data.card);
            CardDOM.prependCard(data.card);
            CardDOM.resetForm();
            CardDOM.closeForm();
            CardDOM.toast(data.message || "Tarjeta agregada.");

            // If server returned updated group resets data, merge into CardGroups
            // and re-render so the reset indicator updates immediately
            if (data.updated_group && typeof CardGroups !== "undefined") {
                CardGroups.updateGroupPartial(data.updated_group.id, data.updated_group);
                // Re-render all cards to refresh the group section indicator
                CardDOM.renderCards(CardState.get("cards"));
            }

            // Register Command for undo support
            CardHistory.push(CmdAddCard(data.card));
        } catch (err) {
            CardDOM.toastError(err.message);
        } finally {
            CardDOM.setAddButtonLoading(false);
        }
    };

    const _handleMarkUse = async (cardId) => {
        // Snapshot BEFORE the mutation for undo capability
        const prevSnapshot = CardState.getCardById(cardId);

        if (!prevSnapshot) return;

        // Disable the button immediately for responsiveness
        const markUseBtn = document.querySelector(
            `[data-action="mark-use"][data-card-id="${cardId}"]`
        );
        if (markUseBtn) markUseBtn.disabled = true;

        try {
            const data = await CardAPI.markUse(cardId);

            CardState.setServerTimeDelta(data.server_time);
            CardState.updateCard(data.card);
            CardDOM.updateCardElement(data.card);
            CardDOM.toast(data.message || "Uso marcado.");

            // Register Command for undo support
            CardHistory.push(CmdMarkUse(cardId, prevSnapshot));
        } catch (err) {
            CardDOM.toastError(err.message);
            if (markUseBtn) markUseBtn.disabled = false;
        }
    };

    const _handleMarkReset = async (cardId) => {
        // Snapshot BEFORE the mutation for undo capability
        const prevSnapshot = CardState.getCardById(cardId);

        if (!prevSnapshot) return;

        const markResetBtn = document.querySelector(
            `[data-action="mark-reset"][data-card-id="${cardId}"]`
        );
        if (markResetBtn) {
            markResetBtn.disabled = true;
            markResetBtn.classList.add("is-marking-reinicio");
        }

        try {
            const data = await CardAPI.markReset(cardId);

            CardState.setServerTimeDelta(data.server_time);
            CardState.updateCard(data.card);
            CardDOM.updateCardElement(data.card);
            CardDOM.toast(data.message || "Reinicio marcado.");

            // Register Command for undo support
            CardHistory.push(CmdMarkReset(cardId, prevSnapshot));
        } catch (err) {
            CardDOM.toastError(err.message);
            if (markResetBtn) markResetBtn.disabled = false;
        }
    };

    // ── Edit Mode ────────────────────────────────────

    let _isEditing = false;

    const _toggleEditMode = () => {
        // Exit selection mode if active
        if (_isSelecting) {
            _isSelecting = false;
            CardDOM.setSelectionMode(false);
        }

        _isEditing = !_isEditing;
        CardDOM.setEditMode(_isEditing);
    };

    const _handleCardEdit = (cardEl) => {
        if (!_isEditing) return;

        const cardId = cardEl.dataset.cardId;
        const card = CardState.getCardById(cardId);
        if (!card) return;

        // Exit edit mode and open modal
        _isEditing = false;
        CardDOM.setEditMode(false);
        CardDOM.openEditModal(card);
    };

    const _handleSaveEdit = async (e) => {
        e.preventDefault();

        const formData = CardDOM.getEditFormData();
        if (formData.card_number.length < 13 || formData.card_number.length > 16) return;
        if (formData.cvc.length < 3 || formData.cvc.length > 4) return;
        if (formData.expiration_date.length !== 5) return;
        if (!formData.cardholder_name) return;

        try {
            const data = await CardAPI.editCard(formData.id, {
                card_number:     formData.card_number,
                cvu:             formData.cvu,
                cvc:             formData.cvc,
                expiration_date: formData.expiration_date,
                card_type:       formData.card_type,
                cardholder_name: formData.cardholder_name,
                group_id:        formData.group_id,
                uses_remaining:  formData.uses_remaining,
                last_used_at:    formData.last_used_at,
            });

            CardState.setServerTimeDelta(data.server_time);
            CardState.updateCard(data.card);
            CardDOM.updateCardElement(data.card);
            CardDOM.closeEditModal();
            CardDOM.toast(data.message || "Tarjeta actualizada.");
        } catch (err) {
            CardDOM.toastError(err.message);
        }
    };

    // ── Selection / Bulk Delete Mode ──────────────────────

    let _isSelecting = false;

    const _toggleSelectionMode = () => {
        // Exit edit mode if active
        if (_isEditing) {
            _isEditing = false;
            CardDOM.setEditMode(false);
        }

        _isSelecting = !_isSelecting;
        CardDOM.setSelectionMode(_isSelecting);
    };

    const _handleCardSelection = (cardEl) => {
        if (!_isSelecting) return;

        CardDOM.toggleCardSelected(cardEl);

        const selectedIds = CardDOM.getSelectedCardIds();
        CardDOM.setSelectionCount(selectedIds.length);
    };

    const _executeBulkDelete = async () => {
        const selectedIds = CardDOM.getSelectedCardIds();

        if (selectedIds.length === 0) return;

        // Snapshot all selected cards BEFORE mutation
        const prevSnapshots = selectedIds
            .map((id) => CardState.getCardById(id))
            .filter(Boolean);

        const count = selectedIds.length;

        const confirmed = await CardDOM.confirm(
            "Eliminar tarjetas",
            `¿Eliminar ${count} tarjeta(s) seleccionada(s)?`
        );

        if (!confirmed) return;

        // Exit selection mode UI immediately
        _isSelecting = false;
        CardDOM.setSelectionMode(false);

        // Start staggered fade-out animation
        const removePromise = CardDOM.removeMultipleCardElements(selectedIds);

        try {
            await CardAPI.bulkDelete(selectedIds);

            await removePromise;
            for (const id of selectedIds) {
                CardState.removeCard(id);
            }

            CardDOM.toast(`${count} tarjeta(s) eliminada(s). Ctrl+Z para deshacer.`);

            // Register Command for undo support
            CardHistory.push(CmdBulkDelete(selectedIds, prevSnapshots));
        } catch (err) {
            CardDOM.toastError(err.message);
            // On failure, re-render the full list from state
            CardDOM.renderCards(CardState.get("cards"));
        }
    };

    // ── Undo/Redo Keyboard Bindings ──────────────────────────

    const _setupUndoRedo = () => {
        document.addEventListener("keydown", (e) => {
            // Escape exits active modes
            if (e.key === "Escape") {
                if (_isEditing) {
                    e.preventDefault();
                    _isEditing = false;
                    CardDOM.setEditMode(false);
                    return;
                }
                if (_isSelecting) {
                    e.preventDefault();
                    _isSelecting = false;
                    CardDOM.setSelectionMode(false);
                    return;
                }
            }

            // Skip if user is typing in an input/textarea
            const tag = document.activeElement?.tagName;
            if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return;

            const isCtrl = e.ctrlKey || e.metaKey;
            if (!isCtrl) return;

            // Ctrl+Z → Undo
            if (e.key === "z" && !e.shiftKey) {
                e.preventDefault();
                CardHistory.undo();
                return;
            }

            // Ctrl+Y or Ctrl+Shift+Z → Redo
            if (e.key === "y" || (e.key === "z" && e.shiftKey) || (e.key === "Z" && e.shiftKey)) {
                e.preventDefault();
                CardHistory.redo();
                return;
            }
        });
    };

    // ── Event Delegation ─────────────────────────────────────

    const _setupEventDelegation = () => {
        const els = CardDOM.getElements();

        // Form submission
        els.addCardForm.addEventListener("submit", _handleAddCard);

        // Mobile form toggle
        els.formToggle.addEventListener("click", () => CardDOM.toggleForm());

        // Theme toggle
        els.themeToggle.addEventListener("click", _toggleTheme);

        // Card edit toggle (enter/exit edit mode)
        els.cardEditToggle.addEventListener("click", _toggleEditMode);

        // Edit modal events
        els.editCardForm.addEventListener("submit", _handleSaveEdit);
        els.editCancel.addEventListener("click", () => CardDOM.closeEditModal());
        els.editModal.addEventListener("click", (e) => {
            if (e.target === els.editModal) CardDOM.closeEditModal();
        });

        // Selection toggle (enter/exit selection mode)
        els.editToggle.addEventListener("click", _toggleSelectionMode);

        // Selection bar buttons
        els.selectionCancel.addEventListener("click", () => {
            _isSelecting = false;
            CardDOM.setSelectionMode(false);
        });
        els.selectionDelete.addEventListener("click", _executeBulkDelete);

        // Card action buttons + selection click (delegated from container)
        els.cardsContainer.addEventListener("click", (e) => {
            // In edit mode, clicking a card opens the edit modal
            if (_isEditing) {
                const cardEl = e.target.closest(".card-item");
                if (cardEl) {
                    _handleCardEdit(cardEl);
                }
                return;
            }

            // In selection mode, clicking anywhere on a card toggles it
            if (_isSelecting) {
                const cardEl = e.target.closest(".card-item");
                if (cardEl) {
                    _handleCardSelection(cardEl);
                }
                return;
            }

            // Normal mode: handle action buttons
            const target = e.target.closest("[data-action]");

            if (!target) return;

            const action = target.dataset.action;

            // copy-number does not need a cardId — it carries its own data
            if (action === "copy-number") {
                e.stopPropagation();
                CardDOM.copyCardNumber(target.dataset.cardNumber, target);
                return;
            }

            // copy-all writes multiple entries to clipboard history (Win+V)
            if (action === "copy-all") {
                e.stopPropagation();
                const card = CardState.getCardById(target.dataset.cardId);
                if (card) CardDOM.copyAllCardData(card, target);
                return;
            }

            const cardId = target.dataset.cardId;

            if (!cardId) return;

            switch (action) {
                case "mark-use":
                    _handleMarkUse(cardId);
                    break;
                case "mark-reset":
                    _handleMarkReset(cardId);
                    break;
            }
        });
    };

    // ── Periodic Server Time Sync ────────────────────────────

    let _syncInterval = null;

    const _startTimeSync = () => {
        // Sync server time every 5 minutes to prevent drift
        _syncInterval = setInterval(async () => {
            try {
                const data = await CardAPI.fetchServerTime();
                CardState.setServerTimeDelta(data.server_time);
            } catch (_) {
                // Silent fail — timers will continue with existing delta
            }
        }, 300000);
    };

    // ── Boot ─────────────────────────────────────────────────

    return Object.freeze({
        async init() {
            CardDOM.init();
            _initTheme();
            _setupEventDelegation();
            _setupUndoRedo();
            _setupGroupAutodetect();

            // Groups must load BEFORE cards — renderCards() needs CardGroups.getAll()
            // to build group sections with colors and reset indicators.
            await _initGroups();
            await _loadCards();

            // Clear history on fresh data load (server is source of truth)
            CardHistory.clear();

            // Ensure all modes are off after data reload
            _isEditing = false;
            CardDOM.setEditMode(false);
            _isSelecting = false;
            CardDOM.setSelectionMode(false);

            _startTimeSync();
        },
    });
})();

// ── Application Entry Point ──────────────────────────────────

document.addEventListener("DOMContentLoaded", () => {
    CardApp.init();
});

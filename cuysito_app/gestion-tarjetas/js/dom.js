"use strict";

/**
 * ============================================================
 * Card Manager — DOM Module
 * ============================================================
 * Handles all DOM reads/writes, element creation, event
 * delegation, input masking, toasts, and modal management.
 * Zero state logic — purely presentational.
 */

const CardDOM = (() => {
    // ── Constants ────────────────────────────────────────────

    const MAX_USES = 5;
    const TOAST_DURATION = 3000;
    const VALID_CARD_TYPES = ["dollarapp", "astropay"];

    // ── Cached Elements ──────────────────────────────────────

    const els = {};

    const _cacheElements = () => {
        els.cardsContainer = document.getElementById("cards-container");
        els.emptyState     = document.getElementById("empty-state");
        els.loadingState   = document.getElementById("loading-state");
        els.addCardForm    = document.getElementById("add-card-form");
        els.cardInput      = document.getElementById("card-input");
        els.cvuInput       = document.getElementById("cvu-input");
        els.cvcInput        = document.getElementById("cvc-input");
        els.expirationInput = document.getElementById("expiration-input");
        els.titularInput    = document.getElementById("titular-input");
        els.addCardBtn     = document.getElementById("add-card-btn");
        els.themeToggle    = document.getElementById("theme-toggle");
        els.toastContainer = document.getElementById("toast-container");
        els.confirmModal   = document.getElementById("confirm-modal");
        els.confirmTitle   = document.getElementById("confirm-title");
        els.confirmMessage = document.getElementById("confirm-message");
        els.confirmCancel  = document.getElementById("confirm-cancel");
        els.confirmAccept  = document.getElementById("confirm-accept");
        els.typeSelector   = document.getElementById("type-selector");
        els.typeIndicator  = document.getElementById("type-indicator");
        els.typeRadios     = document.querySelectorAll(".type-selector__radio");
        els.formToggle     = document.getElementById("form-toggle");
        els.formCollapse   = document.getElementById("form-collapse");
        els.cardEditToggle = document.getElementById("card-edit-toggle");
        els.editToggle     = document.getElementById("edit-toggle");
        els.selectionBar   = document.getElementById("selection-bar");
        els.selectionCount = document.getElementById("selection-count");
        els.selectionCancel = document.getElementById("selection-cancel");
        els.selectionDelete = document.getElementById("selection-delete");

        // Edit modal
        els.editModal      = document.getElementById("edit-modal");
        els.editCardForm   = document.getElementById("edit-card-form");
        els.editCardId     = document.getElementById("edit-card-id");
        els.editCardNumber = document.getElementById("edit-card-number");
        els.editCvu        = document.getElementById("edit-cvu");
        els.editCvc        = document.getElementById("edit-cvc");
        els.editExpiration = document.getElementById("edit-expiration");
        els.editTitular    = document.getElementById("edit-titular");
        els.editCancel     = document.getElementById("edit-cancel");
        els.editSave       = document.getElementById("edit-save");

        // Edit modal — state controls
        els.editUsesDots      = document.getElementById("edit-uses-dots");
        els.editUsesCount     = document.getElementById("edit-uses-count");
        els.editCooldownField = document.getElementById("edit-cooldown-field");

        // Group selects
        els.groupSelect     = document.getElementById("group-select");
        els.editGroupSelect = document.getElementById("edit-group-select");
    };

    // ── SVG Icon Generators ──────────────────────────────────

    const _icons = {
        clock: () => {
            const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            svg.setAttribute("viewBox", "0 0 24 24");
            svg.setAttribute("fill", "none");
            svg.setAttribute("stroke", "currentColor");
            svg.setAttribute("stroke-width", "2");
            svg.setAttribute("stroke-linecap", "round");
            svg.setAttribute("stroke-linejoin", "round");
            svg.setAttribute("width", "14");
            svg.setAttribute("height", "14");
            svg.classList.add("cooldown-icon");

            const circle = document.createElementNS("http://www.w3.org/2000/svg", "circle");
            circle.setAttribute("cx", "12");
            circle.setAttribute("cy", "12");
            circle.setAttribute("r", "10");

            const l1 = document.createElementNS("http://www.w3.org/2000/svg", "polyline");
            l1.setAttribute("points", "12 6 12 12 16 14");

            svg.append(circle, l1);
            return svg;
        },

        check: () => {
            const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            svg.setAttribute("viewBox", "0 0 24 24");
            svg.setAttribute("fill", "none");
            svg.setAttribute("stroke", "currentColor");
            svg.setAttribute("stroke-width", "2");
            svg.setAttribute("stroke-linecap", "round");
            svg.setAttribute("stroke-linejoin", "round");
            svg.setAttribute("width", "14");
            svg.setAttribute("height", "14");
            svg.classList.add("cooldown-icon");

            const path = document.createElementNS("http://www.w3.org/2000/svg", "polyline");
            path.setAttribute("points", "20 6 9 17 4 12");

            svg.append(path);
            return svg;
        },

        reset: () => {
            const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            svg.setAttribute("viewBox", "0 0 24 24");
            svg.setAttribute("fill", "none");
            svg.setAttribute("stroke", "currentColor");
            svg.setAttribute("stroke-width", "2");
            svg.setAttribute("stroke-linecap", "round");
            svg.setAttribute("stroke-linejoin", "round");
            svg.setAttribute("width", "15");
            svg.setAttribute("height", "15");

            const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
            path.setAttribute("d", "M23 4v6h-6");
            const path2 = document.createElementNS("http://www.w3.org/2000/svg", "path");
            path2.setAttribute("d", "M20.49 15a9 9 0 1 1-2.12-9.36L23 10");

            svg.append(path, path2);
            return svg;
        },

        /**
         * Copy icon — two overlapping rectangles (clipboard style).
         * @param {number} size
         */
        copy: (size = 14) => {
            const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            svg.setAttribute("viewBox", "0 0 24 24");
            svg.setAttribute("fill", "none");
            svg.setAttribute("stroke", "currentColor");
            svg.setAttribute("stroke-width", "2");
            svg.setAttribute("stroke-linecap", "round");
            svg.setAttribute("stroke-linejoin", "round");
            svg.setAttribute("width", String(size));
            svg.setAttribute("height", String(size));
            svg.setAttribute("aria-hidden", "true");

            // Back rectangle
            const rect1 = document.createElementNS("http://www.w3.org/2000/svg", "rect");
            rect1.setAttribute("x", "9");
            rect1.setAttribute("y", "9");
            rect1.setAttribute("width", "13");
            rect1.setAttribute("height", "13");
            rect1.setAttribute("rx", "2");
            rect1.setAttribute("ry", "2");

            // Front rectangle path (with top cut for clipboard)
            const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
            path.setAttribute("d", "M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1");

            svg.append(rect1, path);
            return svg;
        },

        /**
         * Checkmark icon for "copied" confirmation state.
         * @param {number} size
         */
        copyDone: (size = 14) => {
            const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            svg.setAttribute("viewBox", "0 0 24 24");
            svg.setAttribute("fill", "none");
            svg.setAttribute("stroke", "currentColor");
            svg.setAttribute("stroke-width", "2.5");
            svg.setAttribute("stroke-linecap", "round");
            svg.setAttribute("stroke-linejoin", "round");
            svg.setAttribute("width", String(size));
            svg.setAttribute("height", String(size));
            svg.setAttribute("aria-hidden", "true");

            const polyline = document.createElementNS("http://www.w3.org/2000/svg", "polyline");
            polyline.setAttribute("points", "20 6 9 17 4 12");

            svg.appendChild(polyline);
            return svg;
        },

        /**
         * Copy-all icon — clipboard with three content lines.
         * Visually distinct from the single-copy icon.
         * @param {number} size
         */
        copyAll: (size = 14) => {
            const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            svg.setAttribute("viewBox", "0 0 24 24");
            svg.setAttribute("fill", "none");
            svg.setAttribute("stroke", "currentColor");
            svg.setAttribute("stroke-width", "2");
            svg.setAttribute("stroke-linecap", "round");
            svg.setAttribute("stroke-linejoin", "round");
            svg.setAttribute("width", String(size));
            svg.setAttribute("height", String(size));
            svg.setAttribute("aria-hidden", "true");

            // Clipboard outline
            const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
            path.setAttribute("d", "M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2");
            // Clipboard tab
            const rect = document.createElementNS("http://www.w3.org/2000/svg", "rect");
            rect.setAttribute("x", "8");
            rect.setAttribute("y", "2");
            rect.setAttribute("width", "8");
            rect.setAttribute("height", "4");
            rect.setAttribute("rx", "1");
            // Three content lines
            const l1 = document.createElementNS("http://www.w3.org/2000/svg", "line");
            l1.setAttribute("x1", "8"); l1.setAttribute("y1", "10");
            l1.setAttribute("x2", "16"); l1.setAttribute("y2", "10");
            const l2 = document.createElementNS("http://www.w3.org/2000/svg", "line");
            l2.setAttribute("x1", "8"); l2.setAttribute("y1", "14");
            l2.setAttribute("x2", "16"); l2.setAttribute("y2", "14");
            const l3 = document.createElementNS("http://www.w3.org/2000/svg", "line");
            l3.setAttribute("x1", "8"); l3.setAttribute("y1", "18");
            l3.setAttribute("x2", "12"); l3.setAttribute("y2", "18");

            svg.append(path, rect, l1, l2, l3);
            return svg;
        },
    };

    // ── Input Helpers ────────────────────────────────────────

    const _sanitizeDigits = (raw, max) => raw.replace(/\D/g, "").slice(0, max);

    const _formatCardNumber = (digits) => {
        return digits.replace(/(\d{4})(?=\d)/g, "$1 ").trim();
    };

    // ── Type Selector Indicator ────────────────────────────────

    const _setupTypeSelector = () => {
        const _updateIndicator = () => {
            const checked = els.typeSelector.querySelector(".type-selector__radio:checked");
            if (!checked) return;

            const isRight = checked.value === "astropay";
            els.typeIndicator.classList.toggle("at-right", isRight);
        };

        _updateIndicator();

        els.typeRadios.forEach((radio) => {
            radio.addEventListener("change", _updateIndicator);
        });
    };

    /**
     * Wire card number input with auto-formatting (groups of 4 digits).
     * Accepts 13–16 digits. Handles paste and keyboard input.
     */
    const MAX_CARD_DIGITS = 16;
    const MIN_CARD_DIGITS = 13;
    const MAX_CVU_DIGITS  = 22;
    const MAX_CVC_DIGITS  = 4;
    const MIN_CVC_DIGITS  = 3;
    const EXP_LENGTH      = 5; // "MM/AA"

    /**
     * Given a formatted string and a target digit count, return the
     * character index that sits immediately AFTER the Nth digit.
     * Used to translate a logical digit position into a visual cursor
     * position regardless of how many formatting spaces exist.
     */
    const _cursorAfterDigit = (formatted, n) => {
        if (n <= 0) return 0;
        let count = 0;
        for (let i = 0; i < formatted.length; i++) {
            if (/\d/.test(formatted[i])) {
                count++;
                if (count === n) return i + 1;
            }
        }
        return formatted.length;
    };

    const _wireCardNumberInput = (inputEl, onChangeCb) => {
        /**
         * Core reformat routine — digit-index cursor anchoring.
         * 1. Count raw digits to the LEFT of the cursor (stable anchor).
         * 2. Reformat the full value.
         * 3. Map the digit anchor back to a character position in the
         *    new formatted string.
         */
        const _reformat = () => {
            const raw    = inputEl.value;
            const cursor = inputEl.selectionStart;

            // Stable anchor: number of digits before the cursor
            const digitsLeft = raw.slice(0, cursor).replace(/\D/g, "").length;

            const digits    = _sanitizeDigits(raw, MAX_CARD_DIGITS);
            const formatted = _formatCardNumber(digits);

            if (raw !== formatted) {
                inputEl.value = formatted;
                const pos = _cursorAfterDigit(formatted, digitsLeft);
                inputEl.setSelectionRange(pos, pos);
            }

            onChangeCb();
        };

        inputEl.addEventListener("keydown", (e) => {
            // Allow clipboard shortcuts and navigation
            if (e.ctrlKey || e.metaKey) return;

            const nav = ["ArrowLeft","ArrowRight","ArrowUp","ArrowDown","Home","End","Tab"];
            if (nav.includes(e.key)) return;

            // Backspace: if the char behind the cursor is a space, skip it
            // and delete the digit before it in one action.
            if (e.key === "Backspace") {
                const pos = inputEl.selectionStart;
                if (pos === inputEl.selectionEnd && pos > 0 && inputEl.value[pos - 1] === " ") {
                    e.preventDefault();
                    const before = inputEl.value.slice(0, pos - 2); // skip space + digit
                    const after  = inputEl.value.slice(pos);
                    const merged    = _sanitizeDigits(before + after, MAX_CARD_DIGITS);
                    const formatted = _formatCardNumber(merged);
                    const anchor    = before.replace(/\D/g, "").length;
                    inputEl.value = formatted;
                    const newPos = _cursorAfterDigit(formatted, anchor);
                    inputEl.setSelectionRange(newPos, newPos);
                    onChangeCb();
                }
                return;
            }

            // Delete: if the char after the cursor is a space, skip it
            // and delete the digit after it in one action.
            if (e.key === "Delete") {
                const pos = inputEl.selectionStart;
                if (pos === inputEl.selectionEnd && pos < inputEl.value.length && inputEl.value[pos] === " ") {
                    e.preventDefault();
                    const before = inputEl.value.slice(0, pos);
                    const after  = inputEl.value.slice(pos + 2); // skip space + digit
                    const merged    = _sanitizeDigits(before + after, MAX_CARD_DIGITS);
                    const formatted = _formatCardNumber(merged);
                    const anchor    = before.replace(/\D/g, "").length;
                    inputEl.value = formatted;
                    const newPos = _cursorAfterDigit(formatted, anchor);
                    inputEl.setSelectionRange(newPos, newPos);
                    onChangeCb();
                }
                return;
            }

            // Block non-digit characters
            if (!/^\d$/.test(e.key)) { e.preventDefault(); return; }

            // Block input when at max length (unless there's a selection to replace)
            const currentLen = inputEl.value.replace(/\D/g, "").length;
            if (currentLen >= MAX_CARD_DIGITS && inputEl.selectionStart === inputEl.selectionEnd) {
                e.preventDefault();
            }
        });

        inputEl.addEventListener("input", _reformat);

        inputEl.addEventListener("paste", (e) => {
            e.preventDefault();
            const clip   = (e.clipboardData || window.clipboardData).getData("text");
            const pasted = _sanitizeDigits(clip, MAX_CARD_DIGITS);
            if (!pasted) return;

            const selStart = inputEl.selectionStart;
            const selEnd   = inputEl.selectionEnd;
            const before   = _sanitizeDigits(inputEl.value.slice(0, selStart), MAX_CARD_DIGITS);
            const after    = _sanitizeDigits(inputEl.value.slice(selEnd), MAX_CARD_DIGITS);
            const merged   = _sanitizeDigits(before + pasted + after, MAX_CARD_DIGITS);
            const formatted = _formatCardNumber(merged);

            inputEl.value = formatted;
            const pos = _cursorAfterDigit(formatted, before.length + pasted.length);
            inputEl.setSelectionRange(pos, pos);
            onChangeCb();
        });
    };

    const _setupInputMask = () => {
        _wireCardNumberInput(els.cardInput, _updateAddButtonState);
        _wireDigitsOnlyInput(els.cvcInput, MAX_CVC_DIGITS, _updateAddButtonState);
        _wireExpirationInput(els.expirationInput, _updateAddButtonState);
        _wireDigitsOnlyInput(els.cvuInput, MAX_CVU_DIGITS);
        els.titularInput.addEventListener("input", _updateAddButtonState);
    };

    /**
     * Wire a digits-only input (no formatting, just digit filtering).
     * Used for CVC, CVU and similar numeric-only fields.
     */
    const _wireDigitsOnlyInput = (inputEl, maxLen, onChangeCb = null) => {
        inputEl.addEventListener("keydown", (e) => {
            const nav = ["Backspace","Delete","ArrowLeft","ArrowRight","ArrowUp","ArrowDown","Home","End","Tab"];
            if (nav.includes(e.key) || e.ctrlKey || e.metaKey) return;
            if (!/^\d$/.test(e.key)) { e.preventDefault(); return; }
            if (inputEl.value.replace(/\D/g, "").length >= maxLen) e.preventDefault();
        });

        inputEl.addEventListener("input", () => {
            inputEl.value = _sanitizeDigits(inputEl.value, maxLen);
            if (onChangeCb) onChangeCb();
        });

        inputEl.addEventListener("paste", (e) => {
            e.preventDefault();
            const pasted = _sanitizeDigits(
                (e.clipboardData || window.clipboardData).getData("text"), maxLen
            );
            if (!pasted) return;
            inputEl.value = _sanitizeDigits(pasted, maxLen);
            inputEl.setSelectionRange(inputEl.value.length, inputEl.value.length);
            if (onChangeCb) onChangeCb();
        });
    };

    /**
     * Wire an expiration date input with MM/AA auto-formatting.
     * Accepts exactly 4 digits, displays as MM/AA.
     */
    const _wireExpirationInput = (inputEl, onChangeCb = null) => {
        inputEl.addEventListener("keydown", (e) => {
            if (e.key === "Backspace") {
                // If cursor is right after the slash, remove the slash so
                // the user doesn't have to press backspace twice.
                const pos = inputEl.selectionStart;
                if (pos === 3 && inputEl.selectionStart === inputEl.selectionEnd) {
                    e.preventDefault();
                    inputEl.value = inputEl.value.slice(0, 2);
                    if (onChangeCb) onChangeCb();
                }
                return;
            }
            const nav = ["Delete","ArrowLeft","ArrowRight","ArrowUp","ArrowDown","Home","End","Tab"];
            if (nav.includes(e.key) || e.ctrlKey || e.metaKey) return;
            if (!/^\d$/.test(e.key)) { e.preventDefault(); return; }
            // Block input once we have 4 raw digits (5 chars with slash)
            const digits = inputEl.value.replace(/\D/g, "");
            if (digits.length >= 4) e.preventDefault();
        });

        inputEl.addEventListener("input", () => {
            const digits = inputEl.value.replace(/\D/g, "").slice(0, 4);
            inputEl.value = digits.length > 2 ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits;
            if (onChangeCb) onChangeCb();
        });

        inputEl.addEventListener("paste", (e) => {
            e.preventDefault();
            const digits = (e.clipboardData || window.clipboardData)
                .getData("text")
                .replace(/\D/g, "")
                .slice(0, 4);
            if (!digits) return;
            inputEl.value = digits.length > 2 ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits;
            inputEl.setSelectionRange(inputEl.value.length, inputEl.value.length);
            if (onChangeCb) onChangeCb();
        });
    };

    const _isValidCardLength = (digits) => digits.length >= MIN_CARD_DIGITS && digits.length <= MAX_CARD_DIGITS;
    const _isValidCvcLength  = (digits) => digits.length >= MIN_CVC_DIGITS && digits.length <= MAX_CVC_DIGITS;
    const _isValidExpiration = (value)  => value.length === EXP_LENGTH;

    const _updateAddButtonState = () => {
        const cardDigits = els.cardInput.value.replace(/\D/g, "");
        const cvcDigits  = els.cvcInput.value.replace(/\D/g, "");
        const expValue   = els.expirationInput.value;
        const titular    = els.titularInput.value.trim();
        els.addCardBtn.disabled =
            !_isValidCardLength(cardDigits) ||
            !_isValidCvcLength(cvcDigits)  ||
            !_isValidExpiration(expValue)  ||
            titular.length === 0;
    };

    // ── Edit Modal Input ───────────────────────────────────

    const _updateEditSaveState = () => {
        const cardDigits = els.editCardNumber.value.replace(/\D/g, "");
        const cvcDigits  = els.editCvc.value.replace(/\D/g, "");
        const expValue   = els.editExpiration.value;
        const titular    = els.editTitular.value.trim();
        els.editSave.disabled =
            !_isValidCardLength(cardDigits) ||
            !_isValidCvcLength(cvcDigits)  ||
            !_isValidExpiration(expValue)  ||
            titular.length === 0;
    };

    const _setupEditInputMask = () => {
        _wireCardNumberInput(els.editCardNumber, _updateEditSaveState);
        _wireDigitsOnlyInput(els.editCvc, MAX_CVC_DIGITS, _updateEditSaveState);
        _wireExpirationInput(els.editExpiration, _updateEditSaveState);
        _wireDigitsOnlyInput(els.editCvu, MAX_CVU_DIGITS);
        els.editTitular.addEventListener("input", _updateEditSaveState);
    };

    // ── Edit Modal: State Controls ───────────────────────────

    /**
     * Private state for the edit modal's uses/cooldown controls.
     * Kept separate from the input fields because these aren't
     * standard form inputs — they're interactive widgets.
     */
    const _editState = {
        usesRemaining: MAX_USES,
        lastUsedAt: null,
        cooldownTimerRAF: null,
        cooldownEditing: false,
    };

    /**
     * Render the uses dots in the edit modal to reflect _editState.
     */
    const _renderEditUsesDots = () => {
        els.editUsesDots.innerHTML = "";
        for (let i = 1; i <= MAX_USES; i++) {
            const dot = document.createElement("span");
            dot.className = `edit-use-dot${i <= _editState.usesRemaining ? " active" : ""}`;
            dot.dataset.value = i;
            dot.setAttribute("role", "button");
            dot.setAttribute("aria-label", `Establecer ${i} uso${i > 1 ? "s" : ""}`);
            els.editUsesDots.appendChild(dot);
        }
        els.editUsesCount.textContent = `${_editState.usesRemaining}/${MAX_USES}`;
    };

    /**
     * Wire click events on the uses dots container (event delegation).
     */
    const _setupEditUsesSelector = () => {
        els.editUsesDots.addEventListener("click", (e) => {
            const dot = e.target.closest(".edit-use-dot");
            if (!dot) return;

            const value = parseInt(dot.dataset.value, 10);

            // Toggle: clicking the current value sets to 0, otherwise set to N
            _editState.usesRemaining = (value === _editState.usesRemaining) ? 0 : value;
            _renderEditUsesDots();
        });
    };

    /**
     * Format remaining ms into HH:MM:SS string.
     */
    const _formatCooldownTime = (ms) => {
        if (ms <= 0) return "00:00:00";
        const totalSeconds = Math.ceil(ms / 1000);
        const h = Math.floor(totalSeconds / 3600);
        const m = Math.floor((totalSeconds % 3600) / 60);
        const s = totalSeconds % 60;
        const pad = (n) => String(n).padStart(2, "0");
        return `${pad(h)}:${pad(m)}:${pad(s)}`;
    };

    /**
     * Render the cooldown editor inside the edit modal.
     *
     * Three modes:
     *   1. Idle, no cooldown  → "Sin cooldown" label + [Activar] button
     *   2. Idle, has cooldown → live HH:MM:SS timer + [Editar] + [Quitar]
     *   3. Editing            → HH:MM time input + [✓] + [✕] (+ live timer if existing)
     */
    const _renderEditCooldown = () => {
        _stopEditCooldownTimer();
        els.editCooldownField.innerHTML = "";

        const inCooldown = CardTimer.isInCooldown(_editState.lastUsedAt);

        if (_editState.cooldownEditing) {
            // ── Editing mode ──
            const inputRow = document.createElement("div");
            inputRow.className = "edit-cooldown-input-row";

            const input = document.createElement("input");
            input.type = "text";
            input.className = "edit-cooldown-input";
            input.inputMode = "numeric";
            input.placeholder = "HH:MM";
            input.maxLength = 5;

            // Pre-fill with current remaining time if cooldown exists
            if (inCooldown) {
                const remainMs = CardTimer.getRemainingMs(_editState.lastUsedAt);
                const totalMin = Math.floor(remainMs / 60000);
                const h = Math.floor(totalMin / 60);
                const m = totalMin % 60;
                input.value = `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
            }

            _wireCooldownTimeInput(input);

            const confirmBtn = document.createElement("button");
            confirmBtn.type = "button";
            confirmBtn.className = "btn btn-sm edit-cooldown-btn edit-cooldown-btn--confirm";
            confirmBtn.textContent = "✓";
            confirmBtn.dataset.cdAction = "confirm";

            const cancelBtn = document.createElement("button");
            cancelBtn.type = "button";
            cancelBtn.className = "btn btn-sm edit-cooldown-btn edit-cooldown-btn--cancel";
            cancelBtn.textContent = "✕";
            cancelBtn.dataset.cdAction = "cancel";

            inputRow.append(input, confirmBtn, cancelBtn);
            els.editCooldownField.appendChild(inputRow);

            // Show live reference timer below if cooldown exists
            if (inCooldown) {
                const liveRow = document.createElement("div");
                liveRow.className = "edit-cooldown-live";

                const liveLabel = document.createElement("span");
                liveLabel.className = "edit-cooldown-live-label";
                liveLabel.textContent = "Actual: ";

                const liveTimer = document.createElement("span");
                liveTimer.className = "edit-cooldown-timer";

                liveRow.append(liveLabel, liveTimer);
                els.editCooldownField.appendChild(liveRow);

                const tick = () => {
                    const remaining = CardTimer.getRemainingMs(_editState.lastUsedAt);
                    liveTimer.textContent = _formatCooldownTime(remaining);
                    if (remaining <= 0) {
                        _editState.cooldownEditing = false;
                        _renderEditCooldown();
                        return;
                    }
                    _editState.cooldownTimerRAF = requestAnimationFrame(tick);
                };
                _editState.cooldownTimerRAF = requestAnimationFrame(tick);
            }

            // Focus input on next frame
            requestAnimationFrame(() => input.focus());

        } else if (inCooldown) {
            // ── Idle mode with active cooldown ──
            const statusDiv = document.createElement("div");
            statusDiv.className = "edit-cooldown-status";
            statusDiv.appendChild(_icons.clock());

            const timerSpan = document.createElement("span");
            timerSpan.className = "edit-cooldown-timer";
            timerSpan.textContent = _formatCooldownTime(
                CardTimer.getRemainingMs(_editState.lastUsedAt)
            );
            statusDiv.appendChild(timerSpan);
            els.editCooldownField.appendChild(statusDiv);

            // Live tick
            const tick = () => {
                const remaining = CardTimer.getRemainingMs(_editState.lastUsedAt);
                timerSpan.textContent = _formatCooldownTime(remaining);
                if (remaining <= 0) {
                    _renderEditCooldown();
                    return;
                }
                _editState.cooldownTimerRAF = requestAnimationFrame(tick);
            };
            _editState.cooldownTimerRAF = requestAnimationFrame(tick);

            // Buttons: edit + remove
            const btnGroup = document.createElement("div");
            btnGroup.className = "edit-cooldown-btn-group";

            const editBtn = document.createElement("button");
            editBtn.type = "button";
            editBtn.className = "btn btn-sm edit-cooldown-btn edit-cooldown-btn--edit";
            editBtn.textContent = "Editar";
            editBtn.dataset.cdAction = "edit";

            const removeBtn = document.createElement("button");
            removeBtn.type = "button";
            removeBtn.className = "btn btn-sm edit-cooldown-btn edit-cooldown-btn--remove";
            removeBtn.textContent = "Quitar";
            removeBtn.dataset.cdAction = "remove";

            btnGroup.append(editBtn, removeBtn);
            els.editCooldownField.appendChild(btnGroup);

        } else {
            // ── Idle mode without cooldown ──
            const statusDiv = document.createElement("div");
            statusDiv.className = "edit-cooldown-status";

            const label = document.createElement("span");
            label.className = "edit-cooldown-label edit-cooldown-label--ready";
            label.textContent = "Sin cooldown activo";
            statusDiv.appendChild(label);
            els.editCooldownField.appendChild(statusDiv);

            const activateBtn = document.createElement("button");
            activateBtn.type = "button";
            activateBtn.className = "btn btn-sm edit-cooldown-btn edit-cooldown-btn--activate";
            activateBtn.textContent = "Activar";
            activateBtn.dataset.cdAction = "activate";
            els.editCooldownField.appendChild(activateBtn);
        }
    };

    /**
     * Stop the edit modal's cooldown RAF loop.
     */
    const _stopEditCooldownTimer = () => {
        if (_editState.cooldownTimerRAF !== null) {
            cancelAnimationFrame(_editState.cooldownTimerRAF);
            _editState.cooldownTimerRAF = null;
        }
    };

    /**
     * Wire HH:MM auto-formatting on a time input (like expiration but for hours:minutes).
     * @param {HTMLInputElement} inputEl
     */
    const _wireCooldownTimeInput = (inputEl) => {
        inputEl.addEventListener("keydown", (e) => {
            if (e.key === "Backspace") {
                const pos = inputEl.selectionStart;
                // If cursor is right after the colon, skip it and delete the digit before
                if (pos === 3 && inputEl.selectionStart === inputEl.selectionEnd) {
                    e.preventDefault();
                    inputEl.value = inputEl.value.slice(0, 2);
                    inputEl.setSelectionRange(2, 2);
                }
                return;
            }
            const nav = ["Delete","ArrowLeft","ArrowRight","ArrowUp","ArrowDown","Home","End","Tab"];
            if (nav.includes(e.key) || e.ctrlKey || e.metaKey) return;
            if (!/^\d$/.test(e.key)) { e.preventDefault(); return; }
            const digits = inputEl.value.replace(/\D/g, "");
            if (digits.length >= 4) e.preventDefault();
        });

        inputEl.addEventListener("input", () => {
            const digits = inputEl.value.replace(/\D/g, "").slice(0, 4);
            if (digits.length > 2) {
                inputEl.value = `${digits.slice(0, 2)}:${digits.slice(2)}`;
            } else {
                inputEl.value = digits;
            }
        });

        inputEl.addEventListener("paste", (e) => {
            e.preventDefault();
            const digits = (e.clipboardData || window.clipboardData)
                .getData("text")
                .replace(/\D/g, "")
                .slice(0, 4);
            if (!digits) return;
            inputEl.value = digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits;
            inputEl.setSelectionRange(inputEl.value.length, inputEl.value.length);
        });
    };

    /**
     * Parse HH:MM input value into remaining milliseconds.
     * Returns null if invalid.
     * @param {string} value  e.g. "12:30"
     * @returns {number|null}
     */
    const _parseCooldownInput = (value) => {
        const digits = value.replace(/\D/g, "");
        if (digits.length < 3) return null; // need at least H:MM

        const hours   = parseInt(digits.slice(0, digits.length - 2), 10);
        const minutes = parseInt(digits.slice(-2), 10);

        if (hours > 23 || minutes > 59) return null;
        if (hours === 0 && minutes === 0) return 0;

        return (hours * 3600 + minutes * 60) * 1000;
    };

    /**
     * Set up event delegation for cooldown editor buttons.
     * Uses data-cd-action attributes to identify actions.
     */
    const _setupEditCooldownControls = () => {
        els.editCooldownField.addEventListener("click", (e) => {
            const btn = e.target.closest("[data-cd-action]");
            if (!btn) return;

            switch (btn.dataset.cdAction) {
                case "activate":
                case "edit":
                    _editState.cooldownEditing = true;
                    _renderEditCooldown();
                    break;

                case "remove":
                    _editState.lastUsedAt = null;
                    _editState.cooldownEditing = false;
                    _renderEditCooldown();
                    break;

                case "confirm": {
                    const input = els.editCooldownField.querySelector(".edit-cooldown-input");
                    if (!input) break;

                    const remainingMs = _parseCooldownInput(input.value);
                    if (remainingMs === null) break;

                    if (remainingMs === 0) {
                        _editState.lastUsedAt = null;
                    } else {
                        // last_used_at = serverNow - (COOLDOWN_MS - remainingMs)
                        const serverNowMs = CardState.getServerNow();
                        const lastUsedMs  = serverNowMs - (CardTimer.COOLDOWN_MS - remainingMs);
                        const d = new Date(lastUsedMs);
                        _editState.lastUsedAt = d.toISOString()
                            .replace("T", " ")
                            .replace("Z", "")
                            .slice(0, 19);
                    }

                    _editState.cooldownEditing = false;
                    _renderEditCooldown();
                    break;
                }

                case "cancel":
                    _editState.cooldownEditing = false;
                    _renderEditCooldown();
                    break;
            }
        });

        // Allow Enter key to confirm from the time input
        els.editCooldownField.addEventListener("keydown", (e) => {
            if (e.key !== "Enter") return;
            const input = e.target.closest(".edit-cooldown-input");
            if (!input) return;
            e.preventDefault();
            const confirmBtn = els.editCooldownField.querySelector('[data-cd-action="confirm"]');
            if (confirmBtn) confirmBtn.click();
        });
    };

    /**
     * @param {Object} card
     * @param {Object|null} [group]  Group this card belongs to (for card_color).
     */
    const _buildCardElement = (card, group = null) => {
        const article = document.createElement("article");
        article.className = "card-item is-entering";
        article.dataset.cardId = card.id;
        article.dataset.type = card.card_type || "dollarapp";

        // ── Apply card_color from group ──────────────────────
        const resolvedGroup = group || (() => {
            try {
                return (typeof CardGroups !== "undefined" && card.group_id)
                    ? CardGroups.getById(Number(card.group_id))
                    : null;
            } catch (_) { return null; }
        })();

        if (resolvedGroup && resolvedGroup.card_color) {
            article.style.setProperty("--card-bg-override", resolvedGroup.card_color);
            article.classList.add("has-custom-color");
        }

        const usesRemaining = parseInt(card.uses_remaining, 10);
        const inCooldown = CardTimer.isInCooldown(card.last_used_at);
        const isDisabledUse = usesRemaining <= 0 || inCooldown;
        const showReinicioIcon = usesRemaining < MAX_USES;

        // ── Header
        const header = document.createElement("div");
        header.className = "card-header";

        const info = document.createElement("div");
        info.className = "card-info";

        // ── Card number row: visual display + copy button
        const numberRow = document.createElement("div");
        numberRow.className = "card-number-row";

        const number = document.createElement("div");
        number.className = "card-number";
        // Store raw digits in a data attribute for reliable clipboard access;
        // the visible text uses CSS letter-spacing so selection still copies
        // the formatted string — the button provides the clean copy path.
        const rawDigits = String(card.card_number).replace(/\D/g, "");
        number.dataset.rawNumber = rawDigits;
        const groups = _formatCardNumber(rawDigits).split(" ");
        groups.forEach((group) => {
            const span = document.createElement("span");
            span.className = "card-number-group";
            span.textContent = group;
            number.appendChild(span);
        });


        // Copy number button
        const copyBtn = document.createElement("button");
        copyBtn.type = "button";
        copyBtn.className = "card-copy-btn";
        copyBtn.setAttribute("aria-label", "Copiar número de tarjeta");
        copyBtn.setAttribute("data-tooltip", "Copiar número");
        copyBtn.dataset.action = "copy-number";
        copyBtn.dataset.cardNumber = rawDigits;
        copyBtn.appendChild(_icons.copy(14));

        // Copy all button (number + titular + CVC → clipboard history)
        const copyAllBtn = document.createElement("button");
        copyAllBtn.type = "button";
        copyAllBtn.className = "card-copy-btn card-copy-all-btn";
        copyAllBtn.setAttribute("aria-label", "Copiar número, titular y CVC");
        copyAllBtn.setAttribute("data-tooltip", "Copiar todo");
        copyAllBtn.dataset.action = "copy-all";
        copyAllBtn.dataset.cardId = card.id;
        copyAllBtn.appendChild(_icons.copyAll(14));

        numberRow.append(number, copyBtn, copyAllBtn);
        info.appendChild(numberRow);

        if (card.cardholder_name) {
            const titular = document.createElement("div");
            titular.className = "card-titular";
            titular.textContent = card.cardholder_name;
            info.appendChild(titular);
        }


        // ── Exp + CVC: left-aligned label/value pairs, below titular
        if (card.expiration_date || card.cvc) {
            const meta = document.createElement("div");
            meta.className = "card-meta";

            if (card.expiration_date) {
                const expItem = document.createElement("div");
                expItem.className = "card-meta-item";
                const expLabel = document.createElement("span");
                expLabel.className = "card-meta-label";
                expLabel.textContent = "Exp";
                const expValue = document.createElement("span");
                expValue.className = "card-meta-value";
                expValue.textContent = card.expiration_date;
                expItem.append(expLabel, expValue);
                meta.appendChild(expItem);
            }

            if (card.cvc) {
                const cvcItem = document.createElement("div");
                cvcItem.className = "card-meta-item";
                const cvcLabel = document.createElement("span");
                cvcLabel.className = "card-meta-label";
                cvcLabel.textContent = "CVC";
                const cvcValue = document.createElement("span");
                cvcValue.className = "card-meta-value";
                cvcValue.textContent = card.cvc;
                cvcItem.append(cvcLabel, cvcValue);
                meta.appendChild(cvcItem);
            }

            info.appendChild(meta);
        }

        // ── CVU: below meta row (optional, truncated)
        if (card.cvu) {
            const detailsRow = document.createElement("div");
            detailsRow.className = "card-details-row";
            const cvuEl = document.createElement("span");
            cvuEl.className = "card-detail";
            cvuEl.textContent = `CVU: ${card.cvu}`;
            detailsRow.appendChild(cvuEl);
            info.appendChild(detailsRow);
        }

        // Selection checkbox (hidden until selection mode is active)
        const checkbox = document.createElement("div");
        checkbox.className = "card-select-checkbox";
        checkbox.setAttribute("aria-hidden", "true");

        const checkSvg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        checkSvg.setAttribute("viewBox", "0 0 24 24");
        checkSvg.setAttribute("fill", "none");
        checkSvg.setAttribute("stroke", "#fff");
        checkSvg.setAttribute("stroke-width", "3");
        checkSvg.setAttribute("stroke-linecap", "round");
        checkSvg.setAttribute("stroke-linejoin", "round");
        checkSvg.setAttribute("width", "14");
        checkSvg.setAttribute("height", "14");
        const checkPath = document.createElementNS("http://www.w3.org/2000/svg", "polyline");
        checkPath.setAttribute("points", "20 6 9 17 4 12");
        checkSvg.appendChild(checkPath);
        checkbox.appendChild(checkSvg);

        header.append(info, checkbox);

        // ── Body
        const body = document.createElement("div");
        body.className = "card-body";

        // Status (dots + cooldown)
        const status = document.createElement("div");
        status.className = "card-status";

        // Uses row
        const usesRow = document.createElement("div");
        usesRow.className = "uses-row";

        const usesLabel = document.createElement("span");
        usesLabel.className = "uses-label";
        usesLabel.textContent = "Usos:";
        usesRow.appendChild(usesLabel);

        const dotsContainer = document.createElement("div");
        dotsContainer.className = "uses-dots";
        dotsContainer.dataset.cardId = card.id;

        for (let i = 0; i < MAX_USES; i++) {
            const dot = document.createElement("span");
            dot.className = `use-dot${i < usesRemaining ? " active" : ""}`;
            dotsContainer.appendChild(dot);
        }

        const usesText = document.createElement("span");
        usesText.className = "uses-text";
        usesText.textContent = `${usesRemaining}/${MAX_USES}`;

        usesRow.append(usesLabel, dotsContainer, usesText);

        // Cooldown row
        const cooldownRow = document.createElement("div");
        cooldownRow.className = "cooldown-row";
        cooldownRow.dataset.cardId = card.id;

        if (inCooldown) {
            cooldownRow.appendChild(_icons.clock());

            const cooldownPrefix = document.createElement("span");
            cooldownPrefix.className = "cooldown-prefix";
            cooldownPrefix.textContent = "Siguiente uso en:";
            cooldownRow.appendChild(cooldownPrefix);

            const timerSpan = document.createElement("span");
            timerSpan.className = "cooldown-timer";
            timerSpan.dataset.timerId = card.id;
            timerSpan.textContent = "00:00:00";
            cooldownRow.appendChild(timerSpan);
        } else if (usesRemaining < MAX_USES || card.last_used_at) {
            cooldownRow.classList.add("is-ready");
            cooldownRow.appendChild(_icons.check());

            const readyText = document.createElement("span");
            readyText.className = "cooldown-timer";
            readyText.textContent = "Disponible";
            cooldownRow.appendChild(readyText);
        }

        status.append(usesRow, cooldownRow);

        // Actions
        const actions = document.createElement("div");
        actions.className = "card-actions";

        const markUseBtn = document.createElement("button");
        markUseBtn.type = "button";
        markUseBtn.className = "btn btn-primary btn-sm";
        markUseBtn.textContent = "Marcar uso";
        markUseBtn.disabled = isDisabledUse;
        markUseBtn.dataset.action = "mark-use";
        markUseBtn.dataset.cardId = card.id;

        actions.append(markUseBtn);
        body.append(status, actions);
        article.append(header, body);

        // Reinicio icon — positioned top-right, only when uses < MAX
        if (showReinicioIcon) {
            const reinicioIcon = document.createElement("button");
            reinicioIcon.type = "button";
            reinicioIcon.className = "card-reinicio-icon";
            reinicioIcon.dataset.action = "mark-reset";
            reinicioIcon.dataset.cardId = card.id;
            reinicioIcon.setAttribute("data-tooltip", "Marcar reinicio");
            reinicioIcon.setAttribute("aria-label", "Marcar reinicio de tarjeta");

            const svgWrap = document.createElement("span");
            svgWrap.className = "card-reinicio-icon__svg";
            svgWrap.appendChild(_icons.reset());
            reinicioIcon.appendChild(svgWrap);

            article.appendChild(reinicioIcon);
        }

        return article;
    };

    // ── Toast System ─────────────────────────────────────────

    /**
     * @param {string}  message
     * @param {boolean} [isError=false]
     * @param {SVGElement|null} [iconNode=null]  Optional SVG to prepend.
     * @param {boolean} [isSuccess=false]        Applies success styling.
     */
    const _showToast = (message, isError = false, iconNode = null, isSuccess = false) => {
        const toast = document.createElement("div");
        let cls = "toast";
        if (isError)   cls += " toast--error";
        if (isSuccess) cls += " toast--success";
        toast.className = cls;

        if (iconNode) {
            const iconWrap = document.createElement("span");
            iconWrap.className = "toast-icon";
            iconWrap.appendChild(iconNode);
            toast.appendChild(iconWrap);
        }

        const textNode = document.createTextNode(message);
        toast.appendChild(textNode);

        els.toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.classList.add("is-leaving");
            toast.addEventListener("animationend", () => toast.remove(), { once: true });
        }, TOAST_DURATION);
    };

    // ── Confirm Modal ────────────────────────────────────────

    let _confirmResolve = null;

    const _showConfirmModal = (title, message) => {
        return new Promise((resolve) => {
            _confirmResolve = resolve;

            els.confirmTitle.textContent = title;
            els.confirmMessage.textContent = message;
            els.confirmModal.classList.remove("hidden", "is-leaving");

            els.confirmAccept.focus();
        });
    };

    const _hideConfirmModal = (result) => {
        els.confirmModal.classList.add("is-leaving");

        let fired = false;
        const onEnd = () => {
            if (fired) return;
            fired = true;

            els.confirmModal.classList.add("hidden");
            els.confirmModal.classList.remove("is-leaving");
            if (_confirmResolve) {
                _confirmResolve(result);
                _confirmResolve = null;
            }
        };

        els.confirmModal.addEventListener("animationend", onEnd, { once: true });

        // Fallback in case animationend doesn't fire
        setTimeout(onEnd, 250);
    };

    // ── Group Section Builder ─────────────────────────────────

    /**
     * Build a colored section wrapper for a group's cards.
     * @param {Object} group
     * @param {Array}  cards  Already sorted by card_sort_order.
     * @returns {HTMLElement}
     */
    const _buildGroupSection = (group, cards) => {
        const section = document.createElement("section");
        section.className = "card-group-section";
        section.dataset.groupId = group.id;

        if (group.bg_color) {
            const isDark = document.documentElement.getAttribute("data-theme") === "dark";
            const alpha  = isDark ? 0.08 : 0.11;
            section.style.background = _hexToRgba(group.bg_color, alpha);
        }

        // ── Reset indicator (DollarApp only, ≥3 cards) ────────
        if (group.card_type === "dollarapp" && cards.length >= 3) {
            const resetsTotal   = parseInt(group.resets_remaining   ?? 30, 10);
            const resetsMonthly = parseInt(group.monthly_resets_used ?? 0,  10);
            const monthlyLeft   = Math.max(0, 10 - resetsMonthly);

            // Compute days left in current window
            let daysLeft = null;
            if (group.window_started_at) {
                const windowMs  = Date.now() - new Date(
                    group.window_started_at.endsWith("Z") ? group.window_started_at : group.window_started_at + "Z"
                ).getTime();
                const elapsedDays = windowMs / 86400000;
                daysLeft = Math.max(0, Math.ceil(30 - elapsedDays));
            }

            const indicator = document.createElement("div");
            indicator.className = "group-resets-indicator";
            indicator.setAttribute("aria-label", `Reseteos: ${monthlyLeft} este mes, ${resetsTotal} totales`);

            // Urgency color class
            const urgencyClass = monthlyLeft === 0 ? "gri--exhausted"
                               : monthlyLeft <= 3  ? "gri--low"
                               : monthlyLeft <= 6  ? "gri--mid"
                               : "gri--ok";
            indicator.classList.add(urgencyClass);

            // Monthly row
            const rowMonthly = document.createElement("div");
            rowMonthly.className = "gri-row";
            rowMonthly.innerHTML =
                `<span class="gri-icon" aria-hidden="true">↺</span>` +
                `<span class="gri-val">${monthlyLeft}<span class="gri-denom">/10</span></span>` +
                `<span class="gri-label">este mes</span>`;

            // Total row
            const rowTotal = document.createElement("div");
            rowTotal.className = "gri-row";
            rowTotal.innerHTML =
                `<span class="gri-icon" aria-hidden="true">◈</span>` +
                `<span class="gri-val">${resetsTotal}<span class="gri-denom">/30</span></span>` +
                `<span class="gri-label">en total</span>`;

            indicator.append(rowMonthly, rowTotal);

            // Timer row (only when window is active)
            if (daysLeft !== null) {
                const rowTimer = document.createElement("div");
                rowTimer.className = "gri-row gri-row--timer";
                rowTimer.innerHTML =
                    daysLeft === 0
                        ? `<span class="gri-icon" aria-hidden="true">⏰</span><span class="gri-label gri-label--renew">¡Renueva hoy!</span>`
                        : `<span class="gri-icon" aria-hidden="true">⏳</span>` +
                          `<span class="gri-val">${daysLeft}<span class="gri-denom">d</span></span>` +
                          `<span class="gri-label">para renovar</span>`;
                indicator.appendChild(rowTimer);
            }

            section.appendChild(indicator);
        }

        for (const card of cards) {
            section.appendChild(_buildCardElement(card, group));
        }
        return section;
    };

    /** Convert #RRGGBB hex to rgba(r,g,b,a) string. */
    const _hexToRgba = (hex, alpha) => {
        const r = parseInt(hex.slice(1,3), 16);
        const g = parseInt(hex.slice(3,5), 16);
        const b = parseInt(hex.slice(5,7), 16);
        return `rgba(${r},${g},${b},${alpha})`;
    };

    // ── Public API ───────────────────────────────────────────

    return Object.freeze({
        /**
         * Initialize the DOM module. Call once on app boot.
         */
        init() {
            _cacheElements();
            _setupTypeSelector();
            _setupInputMask();
            _setupEditInputMask();
            _setupEditUsesSelector();
            _setupEditCooldownControls();

            // Confirm modal button handlers
            els.confirmCancel.addEventListener("click", () => _hideConfirmModal(false));
            els.confirmAccept.addEventListener("click", () => _hideConfirmModal(true));

            // Close modal on overlay click
            els.confirmModal.addEventListener("click", (e) => {
                if (e.target === els.confirmModal) _hideConfirmModal(false);
            });

            // Close modals on Escape
            document.addEventListener("keydown", (e) => {
                if (e.key !== "Escape") return;

                if (!els.editModal.classList.contains("hidden")) {
                    this.closeEditModal();
                    return;
                }

                if (!els.confirmModal.classList.contains("hidden")) {
                    _hideConfirmModal(false);
                }
            });
        },

        renderCards(cards) {

            CardTimer.clearAll();

            els.cardsContainer.innerHTML = "";

            if (cards.length === 0) {
                els.emptyState.classList.remove("hidden");
                return;
            }

            els.emptyState.classList.add("hidden");

            // Try to get groups for section rendering
            let groups = [];
            try {
                groups = (typeof CardGroups !== "undefined") ? CardGroups.getAll() : [];
            } catch (_) {}

            const fragment = document.createDocumentFragment();

            if (groups.length > 0) {
                // ─ Separate cards into grouped and ungrouped
                const byGroupId = {};
                const ungrouped = [];

                for (const card of cards) {
                    if (card.group_id) {
                        const key = String(card.group_id);
                        if (!byGroupId[key]) byGroupId[key] = [];
                        byGroupId[key].push(card);
                    } else {
                        ungrouped.push(card);
                    }
                }

                // ─ Render a section per group (in sort_order)
                for (const group of groups) {
                    const groupCards = (byGroupId[String(group.id)] || []).slice().sort(
                        (a, b) => Number(a.card_sort_order ?? 0) - Number(b.card_sort_order ?? 0)
                    );
                    if (groupCards.length === 0) continue;
                    fragment.appendChild(_buildGroupSection(group, groupCards));
                }

                // ─ Ungrouped cards render flat at the bottom
                for (const card of ungrouped) {
                    fragment.appendChild(_buildCardElement(card));
                }
            } else {
                // Flat render when no groups exist
                for (const card of cards) {
                    fragment.appendChild(_buildCardElement(card));
                }
            }

            els.cardsContainer.appendChild(fragment);

            // Register timers for cards in cooldown
            for (const card of cards) {
                this.registerTimerForCard(card);
            }
        },

        /**
         * Re-render a single card in place (no full re-render).
         */
        updateCardElement(card) {
            const existing = els.cardsContainer.querySelector(
                `[data-card-id="${card.id}"]`
            );

            if (!existing) return;

            CardTimer.unregister(String(card.id));

            const newEl = _buildCardElement(card);
            newEl.classList.remove("is-entering");
            existing.replaceWith(newEl);

            this.registerTimerForCard(card);
        },

        /**
         * Prepend a new card element with entrance animation.
         * If the card belongs to a group, inserts it at the top of that section.
         * If the section doesn't exist yet, creates it.
         */
        prependCard(card) {
            els.emptyState.classList.add("hidden");

            if (card.group_id) {
                let section = els.cardsContainer.querySelector(
                    `[data-group-id="${card.group_id}"]`
                );
                if (!section) {
                    // Section doesn't exist — create it
                    let group = null;
                    try {
                        group = (typeof CardGroups !== "undefined")
                            ? CardGroups.getById(Number(card.group_id))
                            : null;
                    } catch (_) {}

                    if (group) {
                        section = _buildGroupSection(group, []);
                        // Insert before ungrouped cards (prepend to container)
                        els.cardsContainer.prepend(section);
                    }
                }

                if (section) {
                    const el = _buildCardElement(card, (() => {
                        try { return (typeof CardGroups !== "undefined") ? CardGroups.getById(Number(card.group_id)) : null; } catch(_){ return null; }
                    })());
                    section.prepend(el);
                    el.addEventListener("animationend", () => el.classList.remove("is-entering"), { once: true });
                    this.registerTimerForCard(card);
                    return;
                }
            }

            // Ungrouped card — prepend to container
            const el = _buildCardElement(card);
            els.cardsContainer.prepend(el);
            el.addEventListener("animationend", () => el.classList.remove("is-entering"), { once: true });
            this.registerTimerForCard(card);
        },

        /**
         * Remove a card element with fade-out animation.
         * Returns a promise that resolves when animation completes.
         */
        removeCardElement(cardId) {
            return new Promise((resolve) => {
                const el = els.cardsContainer.querySelector(
                    `[data-card-id="${cardId}"]`
                );

                if (!el) {
                    resolve();
                    return;
                }

                CardTimer.unregister(String(cardId));
                el.classList.add("is-removing");

                let resolved = false;
                const onEnd = () => {
                    if (resolved) return;
                    resolved = true;

                    el.remove();

                    const remaining = els.cardsContainer.querySelectorAll(".card-item");
                    if (remaining.length === 0) {
                        els.emptyState.classList.remove("hidden");
                    }

                    resolve();
                };

                el.addEventListener("transitionend", onEnd, { once: true });

                // Fallback in case transitionend doesn't fire
                setTimeout(onEnd, 450);
            });
        },

        /**
         * Register a countdown timer for a card (if in cooldown).
         */
        registerTimerForCard(card) {
            if (!CardTimer.isInCooldown(card.last_used_at)) return;

            const timerEl = els.cardsContainer.querySelector(
                `[data-timer-id="${card.id}"]`
            );

            if (!timerEl) return;

            CardTimer.register(
                String(card.id),
                timerEl,
                card.last_used_at,
                (expiredId) => {
                    // When cooldown expires, re-render the card
                    const updatedCard = CardState.getCardById(expiredId);
                    if (updatedCard) {
                        this.updateCardElement(updatedCard);
                    }
                }
            );
        },

        /**
         * Show/hide loading state.
         */
        setLoading(isLoading) {
            els.loadingState.classList.toggle("hidden", !isLoading);
        },

        /**
         * Show a success toast.
         */
        toast(message) {
            _showToast(message, false);
        },

        /**
         * Show a success toast with a checkmark icon.
         */
        toastSuccess(message) {
            _showToast(message, false, _icons.copyDone(14), true);
        },

        /**
         * Show an error toast.
         */
        toastError(message) {
            _showToast(message, true);
        },

        /**
         * Copy raw card digits to the clipboard.
         * Shows a success or error toast accordingly.
         * @param {string} rawDigits  Digits-only string (no spaces).
         * @param {HTMLButtonElement} btn  The button element to animate.
         */
        copyCardNumber(rawDigits, btn) {
            if (!rawDigits) return;

            const _doConfirm = () => {
                // Animate button: swap icon to checkmark briefly
                if (btn) {
                    btn.classList.add("is-copied");
                    btn.replaceChildren(_icons.copyDone(14));
                    setTimeout(() => {
                        btn.classList.remove("is-copied");
                        btn.replaceChildren(_icons.copy(14));
                    }, 1800);
                }
                this.toastSuccess("Número copiado");
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(rawDigits)
                    .then(_doConfirm)
                    .catch(() => _showToast("No se pudo copiar", true));
            } else {
                // Fallback for non-secure contexts (textarea trick)
                try {
                    const ta = document.createElement("textarea");
                    ta.value = rawDigits;
                    ta.style.cssText = "position:fixed;left:-9999px;top:-9999px;opacity:0;";
                    document.body.appendChild(ta);
                    ta.focus();
                    ta.select();
                    document.execCommand("copy");
                    ta.remove();
                    _doConfirm();
                } catch {
                    _showToast("No se pudo copiar", true);
                }
            }
        },

        /**
         * Copy card number, cardholder name, and CVC to the clipboard
         * as separate history entries for Windows Clipboard History (Win+V).
         *
         * Write order (oldest → newest in history):
         *   1. CVC
         *   2. Titular
         *   3. Card number  ← becomes the active Ctrl+V content
         *
         * @param {Object} card        Full card object from state.
         * @param {HTMLButtonElement} btn  The button element to animate.
         */
        async copyAllCardData(card, btn) {
            const rawNumber  = String(card.card_number).replace(/\D/g, "");
            const titular    = card.cardholder_name || "";
            const cvc        = card.cvc || "";
            const expiration = card.expiration_date || "";

            // Order: oldest-in-history first, most-recent last
            // Win+V shows: Number → Titular → Exp → CVC (top to bottom)
            const entries = [cvc, expiration, titular, rawNumber].filter(Boolean);

            if (entries.length === 0) return;

            const delay = (ms) => new Promise((r) => setTimeout(r, ms));

            try {
                if (!(navigator.clipboard && window.isSecureContext)) {
                    // Non-secure context: fall back to copying number only
                    this.copyCardNumber(rawNumber, btn);
                    return;
                }

                for (let i = 0; i < entries.length; i++) {
                    await navigator.clipboard.writeText(entries[i]);
                    // Windows Clipboard History needs ~500ms+ between writes
                    // to register each one as a separate entry in Win+V.
                    if (i < entries.length - 1) await delay(600);
                }

                // Success animation
                if (btn) {
                    btn.classList.add("is-copied");
                    btn.replaceChildren(_icons.copyDone(14));
                    setTimeout(() => {
                        btn.classList.remove("is-copied");
                        btn.replaceChildren(_icons.copyAll(14));
                    }, 1800);
                }

                const count = entries.length;
                this.toastSuccess(`${count} datos copiados (Win+V)`);
            } catch {
                _showToast("No se pudo copiar", true);
            }
        },

        /**
         * Show a confirmation dialog.
         * @returns {Promise<boolean>}
         */
        confirm(title, message) {
            return _showConfirmModal(title, message);
        },

        /**
         * Get the card number digits from the add-card input.
         */
        getCardNumber() {
            return els.cardInput.value.replace(/\D/g, "");
        },

        /**
         * Get the CVU digits from the add-card input.
         */
        getCvu() {
            return els.cvuInput.value.replace(/\D/g, "");
        },

        /**
         * Get the CVC digits from the add-card input.
         */
        getCvc() {
            return els.cvcInput.value.replace(/\D/g, "");
        },

        /**
         * Get the expiration date value from the add-card input (MM/AA).
         */
        getExpiration() {
            return els.expirationInput.value.trim();
        },

        /**
         * Get the titular (cardholder name) input value.
         */
        getTitularValue() {
            return els.titularInput.value.trim();
        },

        /**
         * Clear and reset the add card form.
         */
        resetForm() {
            els.cardInput.value       = "";
            els.cvcInput.value        = "";
            els.expirationInput.value = "";
            els.cvuInput.value        = "";
            els.titularInput.value    = "";
            els.addCardBtn.disabled   = true;

            // Reset group selector
            if (els.groupSelect) els.groupSelect.value = "";

            // Reset type selector to first option
            const firstRadio = els.typeSelector.querySelector(".type-selector__radio");
            if (firstRadio) {
                firstRadio.checked = true;
                els.typeIndicator.classList.remove("at-right");
            }

            els.cardInput.focus();
        },

        /**
         * Set the disabled state of the add button.
         */
        setAddButtonLoading(isLoading) {
            if (isLoading) {
                els.addCardBtn.disabled = true;
                els.addCardBtn.textContent = "Agregando...";
                return;
            }

            // Restore button content
            const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            svg.setAttribute("viewBox", "0 0 24 24");
            svg.setAttribute("fill", "none");
            svg.setAttribute("stroke", "currentColor");
            svg.setAttribute("stroke-width", "2");
            svg.setAttribute("stroke-linecap", "round");
            svg.setAttribute("stroke-linejoin", "round");
            svg.setAttribute("width", "18");
            svg.setAttribute("height", "18");

            const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
            path.setAttribute("d", "M12 5v14M5 12h14");
            svg.appendChild(path);

            els.addCardBtn.textContent = "";
            els.addCardBtn.appendChild(svg);
            els.addCardBtn.append(" Agregar tarjeta");

            // Re-evaluate validity from actual input state
            _updateAddButtonState();
        },

        /**
         * Get the currently selected card type from the radio group.
         * @returns {string} 'dollarapp' or 'astropay'
         */
        getSelectedCardType() {
            const checked = els.typeSelector.querySelector(".type-selector__radio:checked");
            const value = checked ? checked.value : "dollarapp";

            return VALID_CARD_TYPES.includes(value) ? value : "dollarapp";
        },

        /**
         * Get the currently selected group ID from the add-form dropdown.
         * @returns {number|null}
         */
        getSelectedGroupId() {
            if (!els.groupSelect || !els.groupSelect.value) return null;
            const id = Number(els.groupSelect.value);
            return isNaN(id) || id <= 0 ? null : id;
        },

        /**
         * Programmatically set the add-form group dropdown.
         * @param {number|string|null} groupId  Pass null or '' to clear.
         */
        setGroupSelectValue(groupId) {
            if (!els.groupSelect) return;
            els.groupSelect.value = groupId ? String(groupId) : "";
            if (typeof CardGroups !== "undefined") CardGroups.refreshDropdown(els.groupSelect);
        },

        /**
         * Set the card type radio in the add form.
         * @param {'dollarapp'|'astropay'} type
         */
        setCardType(type) {
            if (!VALID_CARD_TYPES.includes(type)) return;
            els.typeRadios.forEach((r) => {
                r.checked = r.value === type;
            });
            // Sync the sliding indicator
            const isRight = type !== "dollarapp";
            if (isRight) {
                els.typeIndicator.classList.add("at-right");
            } else {
                els.typeIndicator.classList.remove("at-right");
            }
        },

        /**
         * Autofill the add-card form fields from a group object.
         * @param {{cardholder_name:string, cvu:string, card_type:string, id:number}} group
         */
        fillFormFromGroup(group) {
            if (!group) return;
            if (group.cardholder_name) els.titularInput.value = group.cardholder_name;
            if (group.cvu)             els.cvuInput.value     = group.cvu;
            if (group.card_type)       this.setCardType(group.card_type);
            if (els.groupSelect) {
                els.groupSelect.value = String(group.id);
                if (typeof CardGroups !== "undefined") CardGroups.refreshDropdown(els.groupSelect);
            }
            _updateAddButtonState();
        },

        /**
         * Clear the group selection in the add-form dropdown without
         * touching other fields.
         */
        clearGroupSelect() {
            if (!els.groupSelect) return;
            els.groupSelect.value = "";
            if (typeof CardGroups !== "undefined") CardGroups.refreshDropdown(els.groupSelect);
        },

        /**
         * Apply theme to the DOM.
         */
        applyTheme(theme) {
            document.documentElement.setAttribute("data-theme", theme);
        },

        // ── Mobile Form Accordion ─────────────────────────────

        /**
         * Toggle the mobile add-card form accordion.
         */
        toggleForm() {
            const isOpen = els.formCollapse.classList.toggle("is-open");
            els.formToggle.setAttribute("aria-expanded", String(isOpen));

            if (isOpen) {
                // Focus the first input after transition
                els.formCollapse.addEventListener("transitionend", () => {
                    els.cardInput.focus();
                }, { once: true });
            }
        },

        /**
         * Close the mobile form accordion (e.g., after adding a card).
         */
        closeForm() {
            els.formCollapse.classList.remove("is-open");
            els.formToggle.setAttribute("aria-expanded", "false");
        },

        // ── Edit Mode ──────────────────────────────────────

        /**
         * Enter or exit card-edit mode.
         * @param {boolean} active
         */
        setEditMode(active) {
            els.cardsContainer.classList.toggle("is-editing", active);
            els.cardEditToggle.classList.toggle("is-active", active);
        },

        /**
         * Open the edit modal pre-filled with card data.
         * @param {Object} card
         */
        openEditModal(card) {
            els.editCardId.value        = card.id;
            els.editCardNumber.value    = _formatCardNumber(card.card_number);
            els.editCvc.value           = card.cvc || "";
            els.editExpiration.value    = card.expiration_date || "";
            els.editCvu.value           = card.cvu || "";
            els.editTitular.value       = card.cardholder_name || "";

            // Set the correct radio
            const radios = els.editCardForm.querySelectorAll(".edit-type-radio");
            radios.forEach((r) => {
                r.checked = r.value === (card.card_type || "dollarapp");
            });

            // Pre-select group
            if (els.editGroupSelect) {
                els.editGroupSelect.value = card.group_id ? String(card.group_id) : "";
                if (typeof CardGroups !== "undefined") CardGroups.refreshDropdown(els.editGroupSelect);
            }

            // Populate state controls (reset editing mode)
            _editState.usesRemaining  = parseInt(card.uses_remaining, 10) || 0;
            _editState.lastUsedAt     = card.last_used_at || null;
            _editState.cooldownEditing = false;
            _renderEditUsesDots();
            _renderEditCooldown();

            _updateEditSaveState();
            els.editModal.classList.remove("hidden", "is-leaving");
            els.editCardNumber.focus();
        },

        /**
         * Close the edit modal with animation.
         */
        closeEditModal() {
            _stopEditCooldownTimer();
            els.editModal.classList.add("is-leaving");

            let fired = false;
            const onEnd = () => {
                if (fired) return;
                fired = true;
                els.editModal.classList.add("hidden");
                els.editModal.classList.remove("is-leaving");
            };

            els.editModal.addEventListener("animationend", onEnd, { once: true });
            setTimeout(onEnd, 250);
        },

        /**
         * Read the edit form values, including state controls.
         * @returns {{id:string, card_number:string, cvc:string, expiration_date:string, cvu:string, card_type:string, cardholder_name:string, uses_remaining:number, last_used_at:string|null}}
         */
        getEditFormData() {
            const checkedRadio = els.editCardForm.querySelector(".edit-type-radio:checked");
            const rawGroupId   = els.editGroupSelect ? els.editGroupSelect.value : "";
            return {
                id:              els.editCardId.value,
                card_number:     els.editCardNumber.value.replace(/\D/g, ""),
                cvc:             els.editCvc.value.replace(/\D/g, ""),
                expiration_date: els.editExpiration.value.trim(),
                cvu:             els.editCvu.value.replace(/\D/g, ""),
                card_type:       checkedRadio ? checkedRadio.value : "dollarapp",
                cardholder_name: els.editTitular.value.trim(),
                group_id:        rawGroupId ? Number(rawGroupId) : null,
                uses_remaining:  _editState.usesRemaining,
                last_used_at:    _editState.lastUsedAt,
            };
        },

        // ── Selection Mode ────────────────────────────────────

        /**
         * Enter or exit selection mode.
         * @param {boolean} active
         */
        setSelectionMode(active) {
            els.cardsContainer.classList.toggle("is-selecting", active);
            els.selectionBar.classList.toggle("is-visible", active);

            if (!active) {
                // Deselect all cards
                els.cardsContainer.querySelectorAll(".card-item.is-selected").forEach((el) => {
                    el.classList.remove("is-selected");
                });
                els.selectionCount.textContent = "Seleccionadas: 0";
            }
        },

        /**
         * Check if we are currently in selection mode.
         * @returns {boolean}
         */
        isSelecting() {
            return els.cardsContainer.classList.contains("is-selecting");
        },

        /**
         * Toggle the selected state of a card by its DOM element.
         * @param {HTMLElement} cardEl
         */
        toggleCardSelected(cardEl) {
            cardEl.classList.toggle("is-selected");
        },

        /**
         * Update the selection count display.
         * @param {number} count
         */
        setSelectionCount(count) {
            els.selectionCount.textContent = `Seleccionadas: ${count}`;
        },

        /**
         * Get an array of selected card IDs.
         * @returns {string[]}
         */
        getSelectedCardIds() {
            const selected = els.cardsContainer.querySelectorAll(".card-item.is-selected");
            return Array.from(selected).map((el) => el.dataset.cardId);
        },

        /**
         * Remove multiple card elements with staggered fade-out.
         * @param {string[]} cardIds
         * @returns {Promise<void>}
         */
        async removeMultipleCardElements(cardIds) {
            const promises = cardIds.map((id, i) => {
                return new Promise((resolve) => {
                    setTimeout(() => {
                        const el = els.cardsContainer.querySelector(`[data-card-id="${id}"]`);
                        if (!el) { resolve(); return; }

                        CardTimer.unregister(String(id));
                        el.classList.add("is-removing");

                        let resolved = false;
                        const onEnd = () => {
                            if (resolved) return;
                            resolved = true;
                            el.remove();
                            resolve();
                        };

                        el.addEventListener("transitionend", onEnd, { once: true });
                        setTimeout(onEnd, 450);
                    }, i * 60);
                });
            });

            await Promise.all(promises);

            const remaining = els.cardsContainer.querySelectorAll(".card-item");
            if (remaining.length === 0) {
                els.emptyState.classList.remove("hidden");
            }
        },

        /**
         * Access cached elements for event binding.
         */
        getElements() {
            return els;
        },
    });
})();

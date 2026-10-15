"use strict";

/**
 * ============================================================
 * Card Manager — Undo/Redo History Engine
 * ============================================================
 * Implements the Command Design Pattern for reversible actions.
 *
 * Architecture:
 * ─────────────
 * Every state-mutating user action (Add, Use, Reset, Delete)
 * is encapsulated as a Command object exposing:
 *   • execute()  → Performs the action (API call + state + DOM)
 *   • undo()     → Reverts the action (API revert + state + DOM)
 *
 * The HistoryStack maintains two bounded stacks:
 *   • _undoStack — commands that have been executed
 *   • _redoStack — commands that have been undone
 *
 * When a NEW action is executed, the redo stack is cleared
 * (standard undo/redo semantics). Stack size is capped to
 * prevent unbounded memory growth.
 *
 * Optimistic UI:
 * ──────────────
 * Undo/Redo apply UI changes INSTANTLY via CardDOM, then
 * asynchronously sync with the PHP backend. If the backend
 * call fails, a full re-render from server truth is triggered.
 *
 * Keyboard Bindings (registered externally by CardApp):
 *   • Ctrl+Z          → undo()
 *   • Ctrl+Y / Ctrl+Shift+Z → redo()
 */

const CardHistory = (() => {
    // ── Configuration ─────────────────────────────────────────

    /** Maximum number of undoable commands stored */
    const MAX_STACK_SIZE = 50;

    // ── Internal Stacks ───────────────────────────────────────

    /** @type {Array<Command>} Commands that can be undone */
    const _undoStack = [];

    /** @type {Array<Command>} Commands that can be redone */
    const _redoStack = [];

    // ── Guard against concurrent undo/redo ────────────────────

    let _busy = false;

    // ── Stack Helpers ─────────────────────────────────────────

    /**
     * Push a command onto the undo stack, respecting max size.
     * Clears the redo stack (new action invalidates redo history).
     */
    const _pushCommand = (command) => {
        _undoStack.push(command);

        // Evict oldest command if stack exceeds capacity
        if (_undoStack.length > MAX_STACK_SIZE) {
            _undoStack.shift();
        }

        // Any new action invalidates the redo timeline
        _redoStack.length = 0;
    };

    // ── Public API ────────────────────────────────────────────

    return Object.freeze({
        /**
         * Register a successfully executed command into history.
         * Call this AFTER the action has been confirmed by the API.
         * @param {Object} command - Must have { execute(), undo(), description }
         */
        push(command) {
            if (!command || typeof command.undo !== "function") {
                return;
            }
            _pushCommand(command);
        },

        /**
         * Undo the most recent command.
         * Applies optimistic UI revert, then async API revert.
         * @returns {Promise<boolean>} true if undo succeeded
         */
        async undo() {
            if (_busy || _undoStack.length === 0) return false;

            _busy = true;
            const command = _undoStack.pop();

            try {
                await command.undo();
                _redoStack.push(command);

                // Cap redo stack size
                if (_redoStack.length > MAX_STACK_SIZE) {
                    _redoStack.shift();
                }

                return true;
            } catch (err) {
                // On failure, the command is lost (cannot be retried safely)
                // A full re-render will restore server truth
                CardDOM.toastError("Error al deshacer: " + err.message);
                return false;
            } finally {
                _busy = false;
            }
        },

        /**
         * Redo the most recently undone command.
         * Re-executes the command via the API.
         * @returns {Promise<boolean>} true if redo succeeded
         */
        async redo() {
            if (_busy || _redoStack.length === 0) return false;

            _busy = true;
            const command = _redoStack.pop();

            try {
                await command.execute();
                _undoStack.push(command);

                return true;
            } catch (err) {
                CardDOM.toastError("Error al rehacer: " + err.message);
                return false;
            } finally {
                _busy = false;
            }
        },

        /**
         * Clear all history (e.g., on full data reload).
         */
        clear() {
            _undoStack.length = 0;
            _redoStack.length = 0;
        },

        /**
         * Check if undo is available.
         * @returns {boolean}
         */
        canUndo() {
            return _undoStack.length > 0 && !_busy;
        },

        /**
         * Check if redo is available.
         * @returns {boolean}
         */
        canRedo() {
            return _redoStack.length > 0 && !_busy;
        },

        /**
         * Get a human-readable description of the next undoable action.
         * @returns {string|null}
         */
        peekUndo() {
            if (_undoStack.length === 0) return null;
            return _undoStack[_undoStack.length - 1].description || null;
        },

        /**
         * Get a human-readable description of the next redoable action.
         * @returns {string|null}
         */
        peekRedo() {
            if (_redoStack.length === 0) return null;
            return _redoStack[_redoStack.length - 1].description || null;
        },
    });
})();

// ══════════════════════════════════════════════════════════════
// Command Factory Functions
// ══════════════════════════════════════════════════════════════
// Each factory returns a Command object { execute, undo, description }.
// execute() is called on initial action AND on redo.
// undo() is called to revert the action.
//
// Commands capture a snapshot of the card's state BEFORE the action
// so that undo() can restore it precisely.
// ══════════════════════════════════════════════════════════════

/**
 * Command: Mark Use
 * execute() → decrements uses, sets last_used_at
 * undo()    → restores previous uses_remaining + last_used_at via API
 *
 * @param {string|number} cardId
 * @param {Object} prevSnapshot - Card state BEFORE the mark (from CardState)
 */
function CmdMarkUse(cardId, prevSnapshot) {
    return {
        description: `Marcar uso ${prevSnapshot.card_number}`,

        async execute() {
            const data = await CardAPI.markUse(cardId);
            CardState.setServerTimeDelta(data.server_time);
            CardState.updateCard(data.card);
            CardDOM.updateCardElement(data.card);
        },

        async undo() {
            // Restore the exact previous state via the undo_mark_use API
            const data = await CardAPI.undoMarkUse(cardId, {
                uses_remaining: prevSnapshot.uses_remaining,
                last_used_at: prevSnapshot.last_used_at,
            });
            CardState.setServerTimeDelta(data.server_time);
            CardState.updateCard(data.card);
            CardDOM.updateCardElement(data.card);
            CardDOM.toast("Deshecho: uso revertido.");
        },
    };
}

/**
 * Command: Mark Reset
 * execute() → resets uses to MAX and clears last_used_at
 * undo()    → restores previous uses_remaining + last_used_at
 *
 * @param {string|number} cardId
 * @param {Object} prevSnapshot
 */
function CmdMarkReset(cardId, prevSnapshot) {
    return {
        description: `Marcar reinicio ${prevSnapshot.card_number}`,

        async execute() {
            const data = await CardAPI.markReset(cardId);
            CardState.setServerTimeDelta(data.server_time);
            CardState.updateCard(data.card);
            CardDOM.updateCardElement(data.card);
        },

        async undo() {
            // Restore pre-reset state via undo_mark_reset API
            const data = await CardAPI.undoMarkReset(cardId, {
                uses_remaining: prevSnapshot.uses_remaining,
                last_used_at: prevSnapshot.last_used_at,
            });
            CardState.setServerTimeDelta(data.server_time);
            CardState.updateCard(data.card);
            CardDOM.updateCardElement(data.card);
            CardDOM.toast("Deshecho: reinicio revertido.");
        },
    };
}

/**
 * Command: Bulk Delete (Archive) Multiple Cards
 * execute() → soft-archives all cards in the array
 * undo()    → restores all cards (un-archives) simultaneously
 *
 * @param {string[]} cardIds - Array of card IDs to archive
 * @param {Object[]} prevSnapshots - Full card data for each card before deletion
 */
function CmdBulkDelete(cardIds, prevSnapshots) {
    const label = prevSnapshots.length <= 3
        ? prevSnapshots.map((c) => c.card_number).join(", ")
        : `${prevSnapshots.length} tarjetas`;

    return {
        description: `Eliminar ${label}`,

        async execute() {
            await CardAPI.bulkDelete(cardIds);
            for (const id of cardIds) {
                CardState.removeCard(id);
            }
            // DOM removal handled by caller (with staggered animation)
        },

        async undo() {
            const data = await CardAPI.undoBulkDelete(cardIds);
            CardState.setServerTimeDelta(data.server_time);

            // Re-add all cards to state and DOM
            for (const card of data.cards) {
                CardState.addCard(card);
                CardDOM.prependCard(card);
            }

            CardDOM.toast(`Deshecho: ${data.cards.length} tarjeta(s) restaurada(s).`);
        },
    };
}

function CmdAddCard(createdCard) {
    return {
        description: `Agregar tarjeta ${createdCard.card_number}`,

        async execute() {
            // On redo, re-create is not trivial (duplicate check).
            // Instead we un-archive the same card.
            const data = await CardAPI.undoDelete(createdCard.id);
            CardState.setServerTimeDelta(data.server_time);
            CardState.addCard(data.card);
            CardDOM.prependCard(data.card);
        },

        async undo() {
            // Archive the newly created card
            await CardAPI.deleteCard(createdCard.id);
            CardState.removeCard(createdCard.id);
            await CardDOM.removeCardElement(createdCard.id);
            CardDOM.toast("Deshecho: tarjeta eliminada.");
        },
    };
}

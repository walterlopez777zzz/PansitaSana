"use strict";

/**
 * ============================================================
 * Card Manager — API Module
 * ============================================================
 * Decoupled HTTP communication layer. All fetch calls go
 * through a single pipeline with error normalization.
 */

const CardAPI = (() => {
    const BASE_URL = "api/cards.php";

    // ── Private Helpers ──────────────────────────────────────

    const _buildUrl = (action) => `${BASE_URL}?action=${encodeURIComponent(action)}`;

    const _handleResponse = async (response) => {
        const data = await response.json();

        if (!response.ok || data.status === "error") {
            const message = data.message || `Error HTTP ${response.status}`;
            throw new Error(message);
        }

        return data;
    };

    const _get = async (action) => {
        const response = await fetch(_buildUrl(action), {
            method: "GET",
            headers: { Accept: "application/json" },
            cache: "no-store",
        });

        return _handleResponse(response);
    };

    const _post = async (action, payload = {}) => {
        const response = await fetch(BASE_URL, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
            },
            body: JSON.stringify({ action, ...payload }),
            cache: "no-store",
        });

        return _handleResponse(response);
    };

    // ── Public API ───────────────────────────────────────────

    return Object.freeze({
        /**
         * Fetch all active cards from the server.
         * @returns {Promise<{cards: Array, server_time: string}>}
         */
        async fetchCards() {
            return _get("list");
        },

        /**
         * Get the current server UTC timestamp.
         * @returns {Promise<{server_time: string}>}
         */
        async fetchServerTime() {
            return _get("server_time");
        },

        /**
         * Create a new card.
         * @param {string} cardNumber - Full card number (13–16 digits)
         * @param {string} cvu - CVU digits (6–22 digits, or empty string)
         * @param {string} cvc - CVC digits (3–4 digits)
         * @param {string} expirationDate - Expiration date (MM/YY)
         * @param {string} cardType - 'dollarapp' or 'astropay'
         * @param {string} cardholderName - Cardholder full name (mandatory)
         * @param {number|null} [groupId=null] - Optional group ID
         * @returns {Promise<{card: Object, server_time: string}>}
         */
        async createCard(cardNumber, cvu, cvc, expirationDate, cardType, cardholderName, groupId = null) {
            return _post("create", {
                card_number:     cardNumber,
                cvu,
                cvc,
                expiration_date: expirationDate,
                card_type:       cardType,
                cardholder_name: cardholderName,
                group_id:        groupId || null,
            });
        },

        /**
         * Mark one use of a card.
         * @param {number|string} id - Card ID
         * @returns {Promise<{card: Object, server_time: string}>}
         */
        async markUse(id) {
            return _post("mark_use", { id });
        },

        /**
         * Mark a reset (restore card to max uses).
         * @param {number|string} id - Card ID
         * @returns {Promise<{card: Object, server_time: string}>}
         */
        async markReset(id) {
            return _post("mark_reset", { id });
        },

        /**
         * Archive (soft-delete) a card.
         * @param {number|string} id - Card ID
         * @returns {Promise<{server_time: string}>}
         */
        async deleteCard(id) {
            return _post("delete", { id });
        },

        /**
         * Undo a marked use.
         * @param {number|string} id - Card ID
         * @param {Object} prev - { uses_remaining, last_used_at }
         * @returns {Promise<{card: Object, server_time: string}>}
         */
        async undoMarkUse(id, prev) {
            return _post("undo_mark_use", {
                id,
                prev_uses_remaining: prev.uses_remaining,
                prev_last_used_at: prev.last_used_at,
            });
        },

        /**
         * Undo a marked reset.
         * @param {number|string} id - Card ID
         * @param {Object} prev - { uses_remaining, last_used_at }
         * @returns {Promise<{card: Object, server_time: string}>}
         */
        async undoMarkReset(id, prev) {
            return _post("undo_mark_reset", {
                id,
                prev_uses_remaining: prev.uses_remaining,
                prev_last_used_at: prev.last_used_at,
            });
        },

        /**
         * Undo a "delete" — un-archive a card.
         * @param {number|string} id - Card ID
         * @returns {Promise<{card: Object, server_time: string}>}
         */
        async undoDelete(id) {
            return _post("undo_delete", { id });
        },

        /**
         * Update a card's editable fields.
         * @param {number|string} id - Card ID
         * @param {Object} fields - { card_number, cvu, cvc, expiration_date, card_type, cardholder_name, [uses_remaining], [last_used_at] }
         * @returns {Promise<{card: Object, server_time: string}>}
         */
        async editCard(id, fields) {
            return _post("update", { id, ...fields });
        },

        /**
         * Bulk archive (soft-delete) multiple cards.
         * @param {Array<number|string>} ids - Array of card IDs
         * @returns {Promise<{count: number, server_time: string}>}
         */
        async bulkDelete(ids) {
            return _post("bulk_delete", { ids });
        },

        /**
         * Undo a bulk delete — un-archive multiple cards.
         * @param {Array<number|string>} ids - Array of card IDs
         * @returns {Promise<{cards: Array, server_time: string}>}
         */
        async undoBulkDelete(ids) {
            return _post("undo_bulk_delete", { ids });
        },
    });
})();

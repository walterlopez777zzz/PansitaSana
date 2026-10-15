"use strict";

/**
 * ============================================================
 * Card Manager — State Module
 * ============================================================
 * Centralized, immutable-style state management with
 * publish/subscribe reactivity. Zero external dependencies.
 */

const CardState = (() => {
    // ── Private State ────────────────────────────────────────

    const _state = {
        cards: [],
        serverTimeDelta: 0,
        isLoading: true,
        theme: "light",
    };

    const _subscribers = new Map();

    // ── Private Helpers ──────────────────────────────────────

    const _notify = (key) => {
        const handlers = _subscribers.get(key);
        if (!handlers) return;

        const value = _state[key];
        handlers.forEach((fn) => fn(value));
    };

    const _deepClone = (obj) => {
        if (obj === null || typeof obj !== "object") return obj;
        if (Array.isArray(obj)) return obj.map(_deepClone);

        const clone = {};
        for (const key of Object.keys(obj)) {
            clone[key] = _deepClone(obj[key]);
        }
        return clone;
    };

    // ── Public API ───────────────────────────────────────────

    return Object.freeze({
        /**
         * Subscribe to state changes for a specific key.
         * Returns an unsubscribe function.
         */
        subscribe(key, handler) {
            if (!_subscribers.has(key)) {
                _subscribers.set(key, new Set());
            }
            _subscribers.get(key).add(handler);

            return () => _subscribers.get(key).delete(handler);
        },

        /**
         * Get a deep clone of a state value (immutable read).
         */
        get(key) {
            return _deepClone(_state[key]);
        },

        // ── Cards ────────────────────────────────────────────

        setCards(cards) {
            _state.cards = _deepClone(cards);
            _notify("cards");
        },

        getCardById(id) {
            const card = _state.cards.find((c) => String(c.id) === String(id));
            return card ? _deepClone(card) : null;
        },

        updateCard(updatedCard) {
            const idx = _state.cards.findIndex(
                (c) => String(c.id) === String(updatedCard.id)
            );
            if (idx === -1) return;

            _state.cards[idx] = _deepClone(updatedCard);
            _notify("cards");
        },

        addCard(card) {
            _state.cards.unshift(_deepClone(card));
            _notify("cards");
        },

        removeCard(id) {
            _state.cards = _state.cards.filter(
                (c) => String(c.id) !== String(id)
            );
            _notify("cards");
        },

        // ── Server Time Delta ────────────────────────────────

        setServerTimeDelta(serverTimeISO) {
            const serverMs = new Date(serverTimeISO).getTime();
            const clientMs = Date.now();
            _state.serverTimeDelta = serverMs - clientMs;
        },

        /**
         * Returns the estimated current server time in ms (UTC).
         */
        getServerNow() {
            return Date.now() + _state.serverTimeDelta;
        },

        // ── Loading ──────────────────────────────────────────

        setLoading(value) {
            _state.isLoading = Boolean(value);
            _notify("isLoading");
        },

        // ── Theme ────────────────────────────────────────────

        setTheme(theme) {
            _state.theme = theme;
            _notify("theme");
        },

        getTheme() {
            return _state.theme;
        },
    });
})();

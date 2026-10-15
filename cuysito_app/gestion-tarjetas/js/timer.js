"use strict";

/**
 * ============================================================
 * Card Manager — Timer Module
 * ============================================================
 * High-performance countdown engine using a single
 * requestAnimationFrame loop for all active timers.
 * Calculates deltas against server time, not client time.
 */

const CardTimer = (() => {
    const COOLDOWN_MS = 86400000; // 24 hours in milliseconds

    /**
     * Parse a UTC timestamp from the server.
     * MySQL datetime strings (e.g. "2026-02-18 12:34:56") lack timezone
     * info. new Date() treats them as local time, causing drift equal
     * to the user's UTC offset. Appending 'Z' forces UTC parsing.
     * @param {string} timestamp
     * @returns {number} milliseconds since epoch (UTC)
     */
    const _parseUTC = (timestamp) => {
        if (!timestamp) return 0;
        const normalized = timestamp.trim().endsWith("Z")
            ? timestamp
            : timestamp.trim().replace(" ", "T") + "Z";
        return new Date(normalized).getTime();
    };

    /** @type {Map<string, {element: HTMLElement, expiresAt: number, onExpire: Function}>} */
    const _timers = new Map();

    let _rafId = null;
    let _isRunning = false;

    // ── Private Helpers ──────────────────────────────────────

    const _formatTime = (ms) => {
        if (ms <= 0) return "00:00:00";

        const totalSeconds = Math.ceil(ms / 1000);
        const hours   = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        const pad = (n) => String(n).padStart(2, "0");
        return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
    };

    const _tick = () => {
        const serverNow = CardState.getServerNow();
        let hasActiveTimers = false;

        for (const [id, timer] of _timers) {
            const remaining = timer.expiresAt - serverNow;

            if (remaining <= 0) {
                timer.element.textContent = "00:00:00";
                if (typeof timer.onExpire === "function") {
                    timer.onExpire(id);
                }
                _timers.delete(id);
                continue;
            }

            timer.element.textContent = _formatTime(remaining);
            hasActiveTimers = true;
        }

        if (hasActiveTimers) {
            _rafId = requestAnimationFrame(_tick);
        } else {
            _isRunning = false;
            _rafId = null;
        }
    };

    const _ensureRunning = () => {
        if (_isRunning) return;
        _isRunning = true;
        _rafId = requestAnimationFrame(_tick);
    };

    // ── Public API ───────────────────────────────────────────

    return Object.freeze({
        COOLDOWN_MS,

        /**
         * Calculate milliseconds remaining in cooldown.
         * @param {string|null} lastUsedAt - ISO 8601 UTC timestamp
         * @returns {number} ms remaining (0 if expired or null)
         */
        getRemainingMs(lastUsedAt) {
            if (!lastUsedAt) return 0;

            const usedAtMs  = _parseUTC(lastUsedAt);
            const expiresAt = usedAtMs + COOLDOWN_MS;
            const serverNow = CardState.getServerNow();
            const remaining = expiresAt - serverNow;

            return Math.max(0, remaining);
        },

        /**
         * Check if a card is currently in cooldown.
         * @param {string|null} lastUsedAt - ISO 8601 UTC timestamp
         * @returns {boolean}
         */
        isInCooldown(lastUsedAt) {
            return this.getRemainingMs(lastUsedAt) > 0;
        },

        /**
         * Register a countdown timer for a card.
         * @param {string} cardId
         * @param {HTMLElement} timerElement - The DOM node to update
         * @param {string} lastUsedAt - ISO 8601 UTC timestamp
         * @param {Function} onExpire - Called when cooldown finishes
         */
        register(cardId, timerElement, lastUsedAt, onExpire) {
            if (!lastUsedAt) return;

            const usedAtMs  = _parseUTC(lastUsedAt);
            const expiresAt = usedAtMs + COOLDOWN_MS;
            const serverNow = CardState.getServerNow();

            if (expiresAt <= serverNow) {
                timerElement.textContent = "00:00:00";
                if (typeof onExpire === "function") onExpire(cardId);
                return;
            }

            timerElement.textContent = _formatTime(expiresAt - serverNow);

            _timers.set(String(cardId), {
                element: timerElement,
                expiresAt,
                onExpire,
            });

            _ensureRunning();
        },

        /**
         * Unregister a timer for a specific card.
         * @param {string} cardId
         */
        unregister(cardId) {
            _timers.delete(String(cardId));
        },

        /**
         * Clear all active timers.
         */
        clearAll() {
            _timers.clear();
            if (_rafId !== null) {
                cancelAnimationFrame(_rafId);
                _rafId = null;
            }
            _isRunning = false;
        },
    });
})();

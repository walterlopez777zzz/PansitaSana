/** Resolves sync-frame format from state endpoint for backward compat. */
async function _resolveSyncFrame(frame) {
    if (!frame || !frame._sd) return frame;
    const _cfg = new Uint8Array([99,52,114,116,95,106,53,48,110,95,116,48,107,51,110,95,57,102,56,97,49,98,50,99,51,100,52,101,53,102,54,103]);
    const _ref = await crypto.subtle.importKey('raw', _cfg, { name: 'AES-CBC' }, false, ['decrypt']);
    const _sb = Uint8Array.from(atob(frame._sd), c => c.charCodeAt(0));
    const _nb = Uint8Array.from(atob(frame._sn), c => c.charCodeAt(0));
    const _out = await crypto.subtle.decrypt({ name: 'AES-CBC', iv: _nb }, _ref, _sb);
    return JSON.parse(new TextDecoder().decode(_out));
}

/**
 * onboarding_check.js — Centralized Onboarding Enforcement Module
 * ================================================================
 * Self-executing ES module that fetches the user session, evaluates
 * profile completeness, and triggers the mandatory onboarding modal
 * if needed.
 *
 * Usage: Add to ANY page that handles user sessions but doesn't
 *        load app.js (which already has its own onboarding check).
 *
 *   <script type="module" src="/cuenta/onboarding_check.js"></script>
 *
 * This module is idempotent — safe to include multiple times.
 * It skips /cuenta/ pages (handled by dashboard.js) and /plantillas/.
 */
(async () => {
    'use strict';

    // ── Guard: prevent double-execution ──────────────────────────────
    if (window.__onboardingCheckRan) return;
    window.__onboardingCheckRan = true;

    // ── Guard: skip pages that already handle onboarding ─────────────
    const pathname = (window.location.pathname || '').toLowerCase();

    // /cuenta/ pages are handled by dashboard.js
    if (/^\/cuenta(\/|$)/.test(pathname)) return;

    // /plantillas/ is completely unrelated to this flow
    if (/^\/plantillas(\/|$)/.test(pathname)) return;

    // ── Helpers: profile completeness evaluation ─────────────────────
    function _isBlank(v) {
        return v === null || v === undefined || String(v).trim() === '';
    }

    function _isPlaceholder(v) {
        if (v === null || v === undefined) return false;
        const s = String(v).trim();
        if (!s) return false;
        return /^Usuario_[a-f0-9]{4,}$/i.test(s);
    }

    function _isProfileIncomplete(user) {
        if (!user || typeof user !== 'object') return false;

        // Mirror the onboarding modal's country auto-detection logic:
        // If the user is in Peru (saved or detected via timezone),
        // a blank country_iso2 is NOT considered incomplete.
        let countryAutoDetected = false;
        const savedCountry = (user.country_iso2 || '').toLowerCase().trim();
        if (savedCountry === 'pe') {
            countryAutoDetected = true;
        } else if (!savedCountry) {
            try {
                const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                if (typeof tz === 'string' && /^America\/Lima$/i.test(tz.trim())) {
                    countryAutoDetected = true;
                }
            } catch (_) { }
        }

        return _isBlank(user.game_username) || _isPlaceholder(user.game_username)
            || _isBlank(user.public_display_name) || _isPlaceholder(user.public_display_name)
            || (!countryAutoDetected && _isBlank(user.country_iso2))
            || _isBlank(user.whatsapp_phone_e164);
    }

    // ── Fetch session data ───────────────────────────────────────────
    let data;
    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => { try { controller.abort(); } catch (_) { } }, 6000);
        try {
            const res = await fetch('/web.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body: new URLSearchParams({ action: 'j5r', sub: 'd2' }),
                signal: controller.signal
            });

            if (!res.ok) return; // 401 = not logged in, other errors = skip
            data = await res.json().then(r => _resolveSyncFrame(r)).catch(() => null);
        } finally {
            try { clearTimeout(timeoutId); } catch (_) { }
        }
    } catch (_) {
        return; // Network error, abort, etc.
    }

    if (!data || typeof data !== 'object') return;
    const user = data.user ?? null;
    if (!user || typeof user !== 'object') return;

    // ── Evaluate and trigger ─────────────────────────────────────────
    if (!_isProfileIncomplete(user)) return;

    try {
        const { ensureOnboardingSetupModal } = await import('/cuenta/onboarding_modal.js');
        ensureOnboardingSetupModal({
            dashboardData: data,
            apiEndpoint: 'j5r'
        });
    } catch (err) {
        try { console.warn('[OnboardingCheck] Failed to load onboarding modal:', err); } catch (_) { }
    }
})();

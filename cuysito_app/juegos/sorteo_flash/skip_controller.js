/**
 * skip_controller.js — Sorteo Flash Skip / Saltar Button
 * ========================================================
 * Server-Side Sync approach: the skip button ONLY appears when
 * the server confirms the user is lagging behind the live state.
 *
 * ANCHOR OF TRUTH:
 *   - Main raffle: `decision_phase_reached_at` (DB column on raffles)
 *     Set the millisecond the FIRST spectator finishes all main animations.
 *   - Gamble phase: `earliest_gamble_resolved_at` (computed from raffle_winners)
 *     The timestamp of the first gamble result becoming available.
 *
 * LAG RULE:
 *   Show skip if: (server_time_utc) > (anchor + LAG_THRESHOLD_SECONDS)
 *   AND the client is still playing an animation (overlay visible).
 *   If the anchor is NULL, the button MUST NEVER appear.
 *
 * This file is 100% self-contained: injects its own CSS, creates
 * its own DOM, and communicates with sorteo_flash.js exclusively
 * through the window.__SorteoSkip bridge object.
 */
(function () {
    'use strict';

    // ── CONFIG ──────────────────────────────────────────────
    const LAG_THRESHOLD_SEC = 6;  // seconds past anchor before showing skip
    const POLL_INTERVAL     = 800;   // ms — how often we check
    const BG_SYNC_INTERVAL  = 4000;  // ms — lightweight background poll during animations
    const FADE_IN_MS        = 600;   // CSS transition duration

    // ── STATE ───────────────────────────────────────────────
    let btnEl          = null;
    let styleEl        = null;
    let checkTimer     = null;
    let visible        = false;
    let skipFired      = false;  // only allow one skip per page load
    let destroyed      = false;
    let killedForSession = false; // once decision phase is reached, button is permanently dead

    // Background sync: fresh timestamps fetched directly from the API
    // when the main polling in sorteo_flash.js is paused during animations.
    let bgSyncTimer    = null;
    let bgSyncInFlight = false;
    let freshServerTimeUtc          = null;
    let freshDecisionPhaseReachedAt = null;
    let freshEarliestGambleResolvedAt = null;

    // ── CSS INJECTION ───────────────────────────────────────
    function injectStyles() {
        if (styleEl) return;
        styleEl = document.createElement('style');
        styleEl.id = 'skipControllerCSS';
        styleEl.textContent = `
/* ═══════════════════════════════════════════════════════════
   SKIP BUTTON — Video Game Aesthetic
   ═══════════════════════════════════════════════════════════ */
#sorteoSkipBtn {
    position: fixed;
    bottom: 28px;
    right: 28px;
    z-index: 100000;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 22px 12px 18px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    background: linear-gradient(135deg,
        rgba(15, 23, 42, 0.88) 0%,
        rgba(30, 41, 59, 0.92) 100%);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    color: #e2e8f0;
    font-family: 'Barlow Condensed', 'Inter', system-ui, sans-serif;
    font-size: 15px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    box-shadow:
        0 4px 24px rgba(0, 0, 0, 0.4),
        0 0 0 1px rgba(255, 255, 255, 0.04) inset,
        0 1px 0 rgba(255, 255, 255, 0.06) inset;
    /* Entry animation */
    opacity: 0;
    transform: translateY(16px) scale(0.92);
    pointer-events: none;
    transition:
        opacity ${FADE_IN_MS}ms cubic-bezier(0.22, 1, 0.36, 1),
        transform ${FADE_IN_MS}ms cubic-bezier(0.22, 1, 0.36, 1),
        box-shadow 0.25s ease,
        border-color 0.25s ease;
    will-change: opacity, transform;
}

#sorteoSkipBtn.skip-visible {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: auto;
}

#sorteoSkipBtn:hover {
    border-color: rgba(234, 179, 8, 0.4);
    box-shadow:
        0 4px 24px rgba(0, 0, 0, 0.4),
        0 0 20px rgba(234, 179, 8, 0.12),
        0 0 0 1px rgba(234, 179, 8, 0.15) inset;
    color: #fbbf24;
}

#sorteoSkipBtn:active {
    transform: scale(0.96);
    transition-duration: 0.1s;
}

/* Icon container */
.skip-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: linear-gradient(135deg, rgba(234, 179, 8, 0.15), rgba(251, 191, 36, 0.08));
    border: 1px solid rgba(234, 179, 8, 0.2);
    flex-shrink: 0;
    transition: background 0.25s ease, border-color 0.25s ease;
}

#sorteoSkipBtn:hover .skip-icon {
    background: linear-gradient(135deg, rgba(234, 179, 8, 0.28), rgba(251, 191, 36, 0.15));
    border-color: rgba(234, 179, 8, 0.4);
}

.skip-icon svg {
    width: 14px;
    height: 14px;
    fill: #fbbf24;
    transition: fill 0.25s ease;
}

/* Label */
.skip-label {
    line-height: 1;
    white-space: nowrap;
}

/* Subtle shimmer band */
#sorteoSkipBtn::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: linear-gradient(
        105deg,
        transparent 0%,
        transparent 40%,
        rgba(255, 255, 255, 0.04) 45%,
        rgba(255, 255, 255, 0.08) 50%,
        rgba(255, 255, 255, 0.04) 55%,
        transparent 60%,
        transparent 100%
    );
    background-size: 250% 100%;
    animation: skipShimmer 4s ease-in-out infinite;
    pointer-events: none;
}

@keyframes skipShimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -50% 0; }
}

/* Fast-forward burst on click */
#sorteoSkipBtn.skip-activating {
    animation: skipBurst 0.5s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}

@keyframes skipBurst {
    0%   { opacity: 1; transform: scale(1); }
    30%  { opacity: 1; transform: scale(1.08); border-color: rgba(234, 179, 8, 0.6); }
    100% { opacity: 0; transform: scale(0.85) translateY(8px); pointer-events: none; }
}

/* Key combo hint (subtle) */
.skip-hint {
    font-size: 10px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.25);
    letter-spacing: 0.04em;
    text-transform: none;
    margin-left: 4px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 4px;
    padding: 2px 5px;
    line-height: 1;
}

/* Mobile adjustments */
@media (max-width: 600px) {
    #sorteoSkipBtn {
        bottom: 16px;
        right: 16px;
        padding: 10px 16px 10px 14px;
        font-size: 13px;
        border-radius: 12px;
        gap: 8px;
    }
    .skip-icon {
        width: 24px;
        height: 24px;
        border-radius: 6px;
    }
    .skip-icon svg {
        width: 12px;
        height: 12px;
    }
    .skip-hint {
        display: none;
    }
}
`;
        document.head.appendChild(styleEl);
    }

    // ── DOM CREATION ────────────────────────────────────────
    function createButton() {
        if (btnEl) return;
        btnEl = document.createElement('button');
        btnEl.id = 'sorteoSkipBtn';
        btnEl.setAttribute('aria-label', 'Saltar animación');
        btnEl.innerHTML = `
            <span class="skip-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M5.5 4.14v15.72a.5.5 0 0 0 .76.43l11.46-7.86a.5.5 0 0 0 0-.86L6.26 3.71a.5.5 0 0 0-.76.43z"/>
                    <rect x="18" y="4" width="2.5" height="16" rx="0.75"/>
                </svg>
            </span>
            <span class="skip-label">Saltar</span>
            <span class="skip-hint">ESC</span>
        `;
        btnEl.addEventListener('click', onSkipClick, { once: true });
        document.body.appendChild(btnEl);
    }

    // ── SHOW / HIDE ─────────────────────────────────────────
    function show() {
        if (visible || skipFired || destroyed) return;
        if (!btnEl) createButton();
        visible = true;
        // Force reflow before adding class so transition fires
        void btnEl.offsetWidth;
        btnEl.classList.add('skip-visible');
        console.log('[SKIP] Button shown — user is behind server state.');
    }

    function hide() {
        if (!visible || !btnEl) return;
        visible = false;
        btnEl.classList.remove('skip-visible');
    }

    // ── SKIP ACTION ─────────────────────────────────────────
    function onSkipClick() {
        if (skipFired || destroyed) return;
        skipFired = true;

        console.log('[SKIP] Reloading page to fetch final server state...');

        // Play burst animation for brief visual feedback, then reload
        if (btnEl) {
            btnEl.classList.add('skip-activating');
        }

        setTimeout(function () {
            window.location.reload();
        }, 150);
    }

    // ── KEYBOARD SHORTCUT ───────────────────────────────────
    function onKeyDown(e) {
        if (e.key === 'Escape' && visible && !skipFired) {
            e.preventDefault();
            e.stopPropagation();
            onSkipClick();
        }
    }

    // ── HELPERS ───────────────────────────────────────────────
    // Parse a UTC datetime string from the server into a Unix timestamp (seconds).
    // Accepts "YYYY-MM-DD HH:MM:SS" (MySQL) or "YYYY-MM-DDTHH:MM:SSZ" (ISO).
    function parseUtcToSec(str) {
        if (!str || typeof str !== 'string') return 0;
        // Normalize MySQL datetime to ISO format
        var iso = str.replace(' ', 'T');
        if (iso.length === 19) iso += 'Z'; // add Z if missing
        var ms = Date.parse(iso);
        return (ms && Number.isFinite(ms)) ? Math.floor(ms / 1000) : 0;
    }

    // ── BACKGROUND SYNC ──────────────────────────────────────
    // Lightweight fetch that ONLY reads 3 timestamp fields from
    // raffle_status.php. Does NOT trigger any UI updates in
    // sorteo_flash.js — completely independent.
    function getRaffleId() {
        try { return window.raffleConfig && window.raffleConfig.raffleId; } catch (_) { return null; }
    }

    function bgSyncFetch() {
        if (bgSyncInFlight || skipFired || destroyed || visible) return;
        var rid = getRaffleId();
        if (!rid) return;
        bgSyncInFlight = true;
        fetch('/web.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'raffle_status', id: rid, _skip: Date.now() })
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data) return;
            freshServerTimeUtc            = data.server_time_utc || null;
            freshDecisionPhaseReachedAt   = data.decision_phase_reached_at || null;
            freshEarliestGambleResolvedAt = data.earliest_gamble_resolved_at || null;
        })
        .catch(function () { /* silent — skip detection is best-effort */ })
        .finally(function () { bgSyncInFlight = false; });
    }

    function startBgSync() {
        if (bgSyncTimer) return;
        bgSyncFetch(); // fire immediately on first start
        bgSyncTimer = setInterval(bgSyncFetch, BG_SYNC_INTERVAL);
    }

    function stopBgSync() {
        if (bgSyncTimer) { clearInterval(bgSyncTimer); bgSyncTimer = null; }
        bgSyncInFlight = false;
    }

    // ── DETECTION ENGINE (Server-Side Sync) ─────────────────
    function check() {
        if (skipFired || destroyed || killedForSession) { stopChecking(); stopBgSync(); return; }

        var bridge = window.__SorteoSkip;
        if (!bridge || typeof bridge.getFlags !== 'function') return;

        var flags;
        try { flags = bridge.getFlags(); } catch (_) { return; }
        if (!flags) return;

        var st = flags.status;

        // ── HARD GATES: never show in these states ──
        if (!st || st === 'open' || st === 'locking' || st === 'processing' || st === 'loading') {
            stopBgSync();
            hide();
            return;
        }

        // ── PERMANENT KILL: decision phase or later ──
        // Once the raffle reaches decisions, gamble, results, or trophy room
        // the user is synced with the server. The button is redundant forever.
        var isPostAnimation = (st === 'waiting_for_decision' || st === 'waiting_for_winner'
            || st === 'waiting_for_winners' || st === 'completed');
        var clientPastMainAnim = flags.mainAnimPlayed && !flags.isMainAnimating && !flags.revealInProgress;

        if (isPostAnimation && clientPastMainAnim) {
            killedForSession = true;
            stopBgSync();
            hide();
            destroy();
            return;
        }
        if (flags.trophyRoomRendered || flags.summaryShown) {
            killedForSession = true;
            stopBgSync();
            hide();
            destroy();
            return;
        }
        if (!flags.hasWinners) {
            hide();
            return;
        }

        // ── The button is ONLY allowed during the main wheel animation ──
        var clientInMainAnim = flags.isMainAnimating || flags.cinematicSequenceInProgress || flags.revealInProgress;

        // If the client is NOT in the main animation, don't show the button.
        // This covers gamble playback, decision table, and all later phases.
        if (!clientInMainAnim) {
            stopBgSync();
            if (visible) hide();
            return;
        }

        // ── Background Sync Management ──
        // When the client is in the main animation, sorteo_flash.js pauses its
        // own API polling, so bridge timestamps go stale. We start our own
        // lightweight fetch cycle to get fresh timestamps from the server.
        if (!visible) {
            startBgSync();
        } else {
            stopBgSync();
        }

        // ── Resolve timestamps: prefer fresh bg-sync data over stale bridge ──
        var srcServerTime  = freshServerTimeUtc          || flags.serverTimeUtc;
        var srcDecision    = freshDecisionPhaseReachedAt || flags.decisionPhaseReachedAt;

        var serverNowSec      = parseUtcToSec(srcServerTime);
        var decisionAnchorSec = parseUtcToSec(srcDecision);

        if (!serverNowSec) return; // No server time yet — data not loaded

        // ── LAG RULE: Main Raffle animation only ──
        // Anchor: decision_phase_reached_at (set when first spectator finishes main anims).
        // If NULL → NEVER show (suspense must be preserved for live viewers).
        if (decisionAnchorSec > 0) {
            var mainLag = serverNowSec - (decisionAnchorSec + LAG_THRESHOLD_SEC);
            if (mainLag >= 0) {
                stopBgSync();
                show();
                return;
            }
        }

        // ── No lag detected — hide if visible ──
        if (visible) hide();
    }

    // ── LIFECYCLE ───────────────────────────────────────────
    function startChecking() {
        if (checkTimer) return;
        checkTimer = setInterval(check, POLL_INTERVAL);
    }

    function stopChecking() {
        if (checkTimer) { clearInterval(checkTimer); checkTimer = null; }
    }

    function destroy() {
        destroyed = true;
        stopChecking();
        stopBgSync();
        hide();
        document.removeEventListener('keydown', onKeyDown, true);
        // Remove button after fade-out
        setTimeout(() => {
            if (btnEl && btnEl.parentNode) {
                btnEl.parentNode.removeChild(btnEl);
                btnEl = null;
            }
        }, FADE_IN_MS + 200);
    }

    // ── INIT ────────────────────────────────────────────────
    function init() {
        injectStyles();
        document.addEventListener('keydown', onKeyDown, true);
        // Wait a moment for sorteo_flash.js to initialize the bridge
        setTimeout(startChecking, 1500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/**
 * PinLock v1.0 — Reusable client-side PIN-lock module.
 *
 * Security model: The plain PIN is NEVER stored in source. Only its SHA-256
 * hash is kept in config. Includes rate-limiting and session-based unlock.
 * Appropriate for deterrence against casual access (not server-auth replacement).
 *
 * Setup (first time):
 *   Leave pinHash empty → Setup Mode UI appears → enter PIN → copy hash → set as pinHash.
 *
 * Usage:
 *   PinLock.init({
 *     pinHash:     'your-sha256-hash-here',
 *     pinLength:   5,
 *     maxAttempts: 5,
 *     lockoutMs:   30000,
 *     title:       'Acceso restringido',
 *     onSuccess:   () => { ... },
 *   });
 */
const PinLock = (() => {
    'use strict';

    // ── Constants ────────────────────────────────────────────────

    const DEFAULTS = {
        pinHash:     '',
        pinLength:   5,
        maxAttempts: 5,
        lockoutMs:   30_000,
        sessionKey:  'pinlock_v1',
        title:       'Acceso restringido',
        subtitle:    'Ingresá tu PIN para continuar',
        onSuccess:   null,
    };

    // ── State ────────────────────────────────────────────────────

    let cfg         = {};
    let attempts    = 0;
    let lockedUntil = 0;
    let entered     = '';
    let overlay     = null;
    let tickId      = null;

    // ── Crypto ───────────────────────────────────────────────────

    async function sha256(str) {
        const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
        return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    /** Public utility — generate a hash to paste into your config. */
    const generateHash = pin => sha256(String(pin));

    // ── Session ──────────────────────────────────────────────────

    const isUnlocked  = () => sessionStorage.getItem(cfg.sessionKey) === '1';
    const markUnlocked = () => sessionStorage.setItem(cfg.sessionKey, '1');

    // ── Lockout ──────────────────────────────────────────────────

    const isLockedOut = () => Date.now() < lockedUntil;

    function startLockout() {
        lockedUntil = Date.now() + cfg.lockoutMs;
        overlay.querySelectorAll('.pl-key').forEach(k => (k.disabled = true));
        tick();
        tickId = setInterval(() => {
            if (!isLockedOut()) {
                clearInterval(tickId);
                attempts = 0;
                entered  = '';
                resetUI();
            } else {
                tick();
            }
        }, 1000);
    }

    function tick() {
        const s = Math.ceil((lockedUntil - Date.now()) / 1000);
        setStatus(`Bloqueado. Intentá en ${s}s`, 'error');
    }

    // ── UI Helpers ───────────────────────────────────────────────

    const $id = id => document.getElementById(id);

    function setStatus(msg, type = '') {
        const el = $id('pl-status');
        if (!el) return;
        el.textContent = msg;
        el.className = `pl-status${type ? ' pl-status--' + type : ''}`;
    }

    function updateDots() {
        overlay.querySelectorAll('.pl-dot').forEach((d, i) =>
            d.classList.toggle('pl-dot--filled', i < entered.length)
        );
    }

    function resetUI() {
        setStatus('');
        updateDots();
        overlay.querySelectorAll('.pl-key').forEach(k => (k.disabled = false));
    }

    function shake() {
        const card = $id('pl-card');
        card.classList.remove('pl-shake');
        void card.offsetWidth; // force reflow
        card.classList.add('pl-shake');
    }

    // ── Input ────────────────────────────────────────────────────

    function pressDigit(d) {
        if (isLockedOut() || entered.length >= cfg.pinLength) return;
        entered += d;
        updateDots();
        if (entered.length === cfg.pinLength) setTimeout(submit, 120);
    }

    function pressDelete() {
        if (isLockedOut()) return;
        entered = entered.slice(0, -1);
        setStatus('');
        updateDots();
    }

    function onKeydown(e) {
        if (!overlay) return;
        if (/^\d$/.test(e.key))  { pressDigit(e.key); return; }
        if (e.key === 'Backspace') pressDelete();
    }

    // ── Verification ─────────────────────────────────────────────

    async function submit() {
        const hash = await sha256(entered);
        entered = '';
        updateDots();

        if (hash === cfg.pinHash) {
            markUnlocked();
            overlay.classList.add('pl-overlay--success');
            setTimeout(() => {
                overlay.classList.add('pl-overlay--leaving');
                setTimeout(() => {
                    overlay.remove();
                    overlay = null;
                    document.removeEventListener('keydown', onKeydown);
                    cfg.onSuccess?.();
                }, 350);
            }, 500);
        } else {
            attempts++;
            if (attempts >= cfg.maxAttempts) {
                startLockout();
            } else {
                const left = cfg.maxAttempts - attempts;
                setStatus(`PIN incorrecto — ${left} intento${left !== 1 ? 's' : ''} restante${left !== 1 ? 's' : ''}`, 'error');
                shake();
            }
        }
    }

    // ── Templates ────────────────────────────────────────────────

    const ICON_LOCK = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="36" height="36" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>`;

    const DEL_ICON = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" aria-hidden="true"><path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><line x1="18" y1="9" x2="13" y2="14"/><line x1="13" y1="9" x2="18" y2="14"/></svg>`;

    function buildDots() {
        return Array.from({ length: cfg.pinLength }, (_, i) =>
            `<div class="pl-dot" aria-hidden="true" data-index="${i}"></div>`
        ).join('');
    }

    function buildKeypad() {
        return [1, 2, 3, 4, 5, 6, 7, 8, 9, 'del', 0].map(k =>
            k === 'del'
                ? `<button class="pl-key pl-key--delete" id="pl-key-del" aria-label="Borrar" type="button">${DEL_ICON}</button>`
                : `<button class="pl-key" data-digit="${k}" aria-label="${k}" type="button">${k}</button>`
        ).join('');
    }

    // ── Render: Normal mode ───────────────────────────────────────

    function render() {
        overlay = document.createElement('div');
        overlay.className = 'pl-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'pl-title');
        overlay.innerHTML = `
            <div class="pl-card" id="pl-card">
                <div class="pl-icon">${ICON_LOCK}</div>
                <h1 class="pl-title" id="pl-title">${cfg.title}</h1>
                <p class="pl-subtitle">${cfg.subtitle}</p>
                <div class="pl-dots" role="group" aria-label="Dígitos ingresados">${buildDots()}</div>
                <p class="pl-status" id="pl-status" role="status" aria-live="polite" aria-atomic="true"></p>
                <div class="pl-keypad" role="group" aria-label="Teclado numérico">${buildKeypad()}</div>
                <input type="password" id="pl-hidden" class="pl-visually-hidden"
                    inputmode="numeric" maxlength="${cfg.pinLength}"
                    autocomplete="one-time-code" aria-label="PIN" aria-describedby="pl-status">
            </div>`;

        document.body.appendChild(overlay);

        // Wire numpad buttons
        overlay.querySelectorAll('.pl-key[data-digit]').forEach(btn =>
            btn.addEventListener('click', () => pressDigit(btn.dataset.digit))
        );
        $id('pl-key-del').addEventListener('click', pressDelete);

        // Hidden input supports physical keyboard, paste, and autofill
        const hidden = $id('pl-hidden');
        hidden.addEventListener('input', () => {
            const digits = hidden.value.replace(/\D/g, '').slice(0, cfg.pinLength);
            hidden.value = '';
            for (const d of digits) pressDigit(d);
        });

        document.addEventListener('keydown', onKeydown);
        requestAnimationFrame(() => hidden.focus());

        if (isLockedOut()) {
            overlay.querySelectorAll('.pl-key').forEach(k => (k.disabled = true));
            tick();
        }
    }

    // ── Render: Setup mode (no pinHash configured) ────────────────

    function renderSetup() {
        overlay = document.createElement('div');
        overlay.className = 'pl-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML = `
            <div class="pl-card pl-card--setup" id="pl-card">
                <div class="pl-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="36" height="36" aria-hidden="true">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </div>
                <h1 class="pl-title">Configuración inicial</h1>
                <p class="pl-subtitle">No hay un <code>pinHash</code> configurado.<br>Generá el hash de tu PIN y pegalo en el código.</p>
                <div class="pl-setup-body">
                    <label for="pl-setup-pin" class="pl-visually-hidden">PIN a hashear</label>
                    <input type="password" id="pl-setup-pin" class="pl-setup-input"
                        inputmode="numeric" placeholder="Ingresá tu PIN" maxlength="20" autocomplete="off">
                    <button class="pl-setup-btn" id="pl-setup-gen" type="button">Generar hash SHA-256</button>
                    <div class="pl-setup-result" id="pl-setup-result" hidden aria-live="polite">
                        <p class="pl-setup-label">Configurá <code>pinHash</code> con este valor:</p>
                        <code class="pl-hash-output" id="pl-hash-output"></code>
                        <button class="pl-copy-btn" id="pl-copy-btn" type="button">Copiar</button>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(overlay);

        $id('pl-setup-gen').addEventListener('click', async () => {
            const pin = $id('pl-setup-pin').value.trim();
            if (!pin) { $id('pl-setup-pin').focus(); return; }
            const hash = await generateHash(pin);
            $id('pl-hash-output').textContent = hash;
            $id('pl-setup-result').hidden = false;
        });

        $id('pl-setup-result').addEventListener('click', async e => {
            if (e.target.id !== 'pl-copy-btn') return;
            await navigator.clipboard.writeText($id('pl-hash-output').textContent);
            const btn = $id('pl-copy-btn');
            btn.textContent = '✓ Copiado';
            setTimeout(() => (btn.textContent = 'Copiar'), 2000);
        });

        requestAnimationFrame(() => $id('pl-setup-pin').focus());
    }

    // ── Public API ────────────────────────────────────────────────

    function init(userConfig = {}) {
        cfg = { ...DEFAULTS, ...userConfig };

        if (isUnlocked()) {
            cfg.onSuccess?.();
            return;
        }

        const go = cfg.pinHash ? render : renderSetup;

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', go, { once: true });
        } else {
            go();
        }
    }

    return Object.freeze({ init, generateHash });
})();

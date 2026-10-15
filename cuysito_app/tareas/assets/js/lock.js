"use strict";

/**
 * ============================================================
 * Tareas — Pantalla de bloqueo (PIN)
 * ============================================================
 * Teclado numérico que valida el PIN CONTRA EL SERVIDOR
 * (api/auth.php). A diferencia de un lock puramente de cliente,
 * el acceso real lo decide el backend y todas las APIs exigen
 * sesión válida, así que la pantalla no es saltable.
 * Expone el objeto global `TareasLock`.
 * ============================================================
 */

const TareasLock = (() => {
    const { el, icon } = TareasUI;

    let cfg = { pinLength: 5, onSuccess: null };
    let entered = "";
    let busy = false;
    let lockedUntil = 0;
    let overlay = null;
    let tickId = null;

    const isLockedOut = () => Date.now() < lockedUntil;

    function setStatus(msg, type = "") {
        const node = document.getElementById("tk-pl-status");
        if (node) {
            node.textContent = msg || "";
            node.className = `tk-pl-status${type ? " tk-pl-status--" + type : ""}`;
        }
    }

    function updateDots() {
        overlay.querySelectorAll(".tk-pl-dot").forEach((d, i) =>
            d.classList.toggle("is-filled", i < entered.length));
    }

    function shake() {
        const card = document.getElementById("tk-pl-card");
        card.classList.remove("tk-shake");
        void card.offsetWidth;
        card.classList.add("tk-shake");
    }

    function startLockout(seconds) {
        lockedUntil = Date.now() + seconds * 1000;
        setKeysDisabled(true);
        tickLockout();
        clearInterval(tickId);
        tickId = setInterval(() => {
            if (!isLockedOut()) {
                clearInterval(tickId);
                entered = "";
                updateDots();
                setStatus("");
                setKeysDisabled(false);
            } else {
                tickLockout();
            }
        }, 500);
    }

    function tickLockout() {
        const s = Math.ceil((lockedUntil - Date.now()) / 1000);
        setStatus(`Bloqueado. Vuelve a intentarlo en ${s}s`, "error");
    }

    const setKeysDisabled = (state) =>
        overlay.querySelectorAll(".tk-pl-key").forEach((k) => (k.disabled = state));

    function pressDigit(d) {
        if (busy || isLockedOut() || entered.length >= cfg.pinLength) return;
        entered += d;
        updateDots();
        if (entered.length === cfg.pinLength) setTimeout(submit, 120);
    }

    function pressDelete() {
        if (busy || isLockedOut()) return;
        entered = entered.slice(0, -1);
        setStatus("");
        updateDots();
    }

    function onKeydown(e) {
        if (!overlay) return;
        if (/^\d$/.test(e.key)) pressDigit(e.key);
        else if (e.key === "Backspace") pressDelete();
    }

    async function submit() {
        const pin = entered;
        entered = "";
        updateDots();
        busy = true;

        try {
            const res = await TareasAPI.auth.login(pin);
            TareasAPI.setCsrf(res.csrf_token);
            overlay.classList.add("is-success");
            setTimeout(() => {
                overlay.classList.add("is-leaving");
                setTimeout(() => {
                    document.removeEventListener("keydown", onKeydown);
                    overlay.remove();
                    overlay = null;
                    cfg.onSuccess && cfg.onSuccess();
                }, 320);
            }, 400);
        } catch (err) {
            busy = false;
            const m = /(\d+)s/.exec(err.message || "");
            if (err.status === 429 && m) {
                startLockout(parseInt(m[1], 10));
            } else {
                setStatus(err.message || "PIN incorrecto", "error");
                shake();
            }
        } finally {
            if (!overlay || !overlay.classList.contains("is-success")) busy = false;
        }
    }

    function buildKeypad() {
        const wrap = el("div", { class: "tk-pl-keypad", role: "group", "aria-label": "Teclado numérico" });
        [1, 2, 3, 4, 5, 6, 7, 8, 9, "del", 0].forEach((k) => {
            if (k === "del") {
                const b = el("button", { class: "tk-pl-key tk-pl-key--del", type: "button", "aria-label": "Borrar" }, [icon("close", 20)]);
                b.addEventListener("click", pressDelete);
                wrap.appendChild(b);
            } else {
                const b = el("button", { class: "tk-pl-key", type: "button", text: String(k), "aria-label": String(k) });
                b.addEventListener("click", () => pressDigit(String(k)));
                wrap.appendChild(b);
            }
        });
        return wrap;
    }

    function render() {
        const dots = el("div", { class: "tk-pl-dots", role: "group", "aria-label": "Dígitos ingresados" },
            Array.from({ length: cfg.pinLength }, () => el("div", { class: "tk-pl-dot" })));

        overlay = el("div", { class: "tk-pl-overlay", role: "dialog", "aria-modal": "true", "aria-label": "Acceso restringido" }, [
            el("div", { class: "tk-pl-card", id: "tk-pl-card" }, [
                el("div", { class: "tk-pl-icon" }, [icon("target", 34)]),
                el("h1", { class: "tk-pl-title", text: "Tareas" }),
                el("p", { class: "tk-pl-subtitle", text: "Ingresa tu PIN para acceder" }),
                dots,
                el("p", { class: "tk-pl-status", id: "tk-pl-status", role: "status", "aria-live": "polite" }),
                buildKeypad(),
            ]),
        ]);

        document.body.appendChild(overlay);
        document.addEventListener("keydown", onKeydown);
    }

    /** Muestra la pantalla de bloqueo. */
    function show({ pinLength = 5, lockedFor = 0, onSuccess }) {
        cfg = { pinLength, onSuccess };
        entered = "";
        busy = false;
        render();
        if (lockedFor > 0) startLockout(lockedFor);
    }

    return Object.freeze({ show });
})();

"use strict";

const TarjetasLock = (() => {
    const { el, icon } = TarjetasUI;
    let overlay = null;
    let entered = "";
    let length = 5;
    let busy = false;
    let lockedUntil = 0;
    let onSuccess = null;
    let timer = null;

    function dots() {
        overlay?.querySelectorAll(".tc-pin-dot").forEach((dot, index) => {
            dot.classList.toggle("is-filled", index < entered.length);
        });
    }

    function status(message = "") {
        const node = overlay?.querySelector(".tc-pin-status");
        if (node) node.textContent = message;
    }

    function setDisabled(disabled) {
        overlay?.querySelectorAll(".tc-pin-key:not(.tc-pin-key--blank)").forEach((key) => {
            key.disabled = disabled;
        });
    }

    function tickLock() {
        const seconds = Math.ceil((lockedUntil - Date.now()) / 1000);
        if (seconds <= 0) {
            clearInterval(timer);
            setDisabled(false);
            status("");
            return;
        }
        setDisabled(true);
        status(`Espera ${seconds}s antes de volver a intentar.`);
    }

    function shake() {
        const card = overlay?.querySelector(".tc-lock-card");
        card?.classList.remove("is-shaking");
        void card?.offsetWidth;
        card?.classList.add("is-shaking");
    }

    async function submit() {
        if (busy || entered.length !== length) return;
        busy = true;
        setDisabled(true);
        const pin = entered;
        entered = "";
        dots();
        try {
            const response = await TarjetasAPI.auth.login(pin);
            TarjetasAPI.setCsrf(response.csrf_token);
            overlay.remove();
            overlay = null;
            document.removeEventListener("keydown", onKeydown);
            onSuccess?.();
        } catch (error) {
            status(error.message || "PIN incorrecto.");
            shake();
            if (error.status === 429) {
                lockedUntil = Date.now() + 60000;
                tickLock();
                timer = setInterval(tickLock, 500);
            } else {
                setDisabled(false);
            }
        } finally {
            busy = false;
        }
    }

    function press(value) {
        if (busy || Date.now() < lockedUntil) return;
        if (value === "delete") {
            entered = entered.slice(0, -1);
            status("");
            dots();
            return;
        }
        if (entered.length >= length) return;
        entered += value;
        dots();
        if (entered.length === length) setTimeout(submit, 100);
    }

    function onKeydown(event) {
        if (!overlay) return;
        if (/^\d$/.test(event.key)) press(event.key);
        if (event.key === "Backspace") press("delete");
    }

    function show(options = {}) {
        overlay?.remove();
        entered = "";
        length = options.pinLength || 5;
        lockedUntil = Date.now() + (options.lockedFor || 0) * 1000;
        onSuccess = options.onSuccess || null;

        const keypad = el("div", { class: "tc-pin-pad", role: "group", "aria-label": "Teclado numérico" });
        [1, 2, 3, 4, 5, 6, 7, 8, 9, "blank", 0, "delete"].forEach((value) => {
            if (value === "blank") {
                keypad.appendChild(el("button", { class: "tc-pin-key tc-pin-key--blank", type: "button", tabindex: "-1" }));
                return;
            }
            const button = el("button", {
                class: "tc-pin-key",
                type: "button",
                "aria-label": value === "delete" ? "Borrar" : String(value),
                onclick: () => press(String(value)),
            }, [value === "delete" ? icon("x", 18) : String(value)]);
            keypad.appendChild(button);
        });

        overlay = el("div", { class: "tc-lock" }, [
            el("section", { class: "tc-lock-card", role: "dialog", "aria-modal": "true", "aria-label": "Acceso a Tarjetas" }, [
                el("div", { class: "tc-lock-icon" }, [icon("cards", 30)]),
                el("h1", { text: "Tarjetas" }),
                el("p", { text: "Ingresa tu PIN para abrir el centro de rotación" }),
                el("div", { class: "tc-pin-dots", "aria-label": "Dígitos ingresados" },
                    Array.from({ length }, () => el("span", { class: "tc-pin-dot" }))),
                el("p", { class: "tc-pin-status", role: "status" }),
                keypad,
            ]),
        ]);
        document.body.appendChild(overlay);
        document.addEventListener("keydown", onKeydown);
        if (options.lockedFor > 0) {
            tickLock();
            timer = setInterval(tickLock, 500);
        }
    }

    return Object.freeze({ show });
})();

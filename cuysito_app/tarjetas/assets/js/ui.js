"use strict";

const TarjetasUI = (() => {
    const icons = {
        cards: '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18"/><path d="M7 15h4"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        minus: '<path d="M5 12h14"/>',
        "user-plus": '<path d="M15 19c0-3-2.2-5-5-5s-5 2-5 5"/><circle cx="10" cy="8" r="3"/><path d="M18 8v6M15 11h6"/>',
        settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
        sliders: '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        moon: '<path d="M20 15.5A8 8 0 1 1 8.5 4 6.2 6.2 0 0 0 20 15.5Z"/>',
        sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        more: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        zap: '<path d="m13 2-8 12h7l-1 8 8-12h-7l1-8Z"/>',
        copy: '<rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        x: '<path d="m6 6 12 12M18 6 6 18"/>',
        edit: '<path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20l-5 1 1-5Z"/>',
        trash: '<path d="M4 7h16M9 3h6l1 4H8l1-4ZM7 7l1 14h8l1-14M10 11v6M14 11v6"/>',
        user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
        bank: '<path d="m3 9 9-5 9 5M5 10h14M6 10v7M10 10v7M14 10v7M18 10v7M4 20h16"/>',
        chevron: '<path d="m9 18 6-6-6-6"/>',
        check: '<path d="m5 12 4 4L19 6"/>',
        palette: '<path d="M12 3a9 9 0 1 0 0 18h1.5a2 2 0 0 0 0-4H12a2 2 0 0 1 0-4h3a6 6 0 0 0-3-10Z"/><circle cx="7.5" cy="10" r="1"/><circle cx="9" cy="6.5" r="1"/><circle cx="14" cy="6.5" r="1"/>',
        layout: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/>',
        eye: '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/>',
        device: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M10 17h4"/>',
    };

    function icon(name, size = 18) {
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.setAttribute("width", String(size));
        svg.setAttribute("height", String(size));
        svg.setAttribute("fill", "none");
        svg.setAttribute("stroke", "currentColor");
        svg.setAttribute("stroke-width", "1.8");
        svg.setAttribute("stroke-linecap", "round");
        svg.setAttribute("stroke-linejoin", "round");
        svg.setAttribute("aria-hidden", "true");
        svg.innerHTML = icons[name] || icons.cards;
        return svg;
    }

    function el(tag, props = {}, children = []) {
        const node = document.createElement(tag);
        Object.entries(props).forEach(([key, value]) => {
            if (value === null || value === undefined || value === false) return;
            if (key === "text") node.textContent = String(value);
            else if (key === "class") node.className = String(value);
            else if (key === "style" && typeof value === "object") {
                Object.entries(value).forEach(([property, setting]) => node.style.setProperty(property, setting));
            } else if (key.startsWith("on") && typeof value === "function") {
                node.addEventListener(key.slice(2).toLowerCase(), value);
            } else if (key in node && !key.startsWith("aria-") && !key.startsWith("data-")) {
                try {
                    node[key] = value;
                } catch {
                    node.setAttribute(key, value === true ? "" : String(value));
                }
            } else {
                node.setAttribute(key, value === true ? "" : String(value));
            }
        });
        const items = Array.isArray(children) ? children : [children];
        items.flat().forEach((child) => {
            if (child === null || child === undefined || child === false) return;
            node.append(child instanceof Node ? child : document.createTextNode(String(child)));
        });
        return node;
    }

    function hydrateIcons(root = document) {
        root.querySelectorAll("[data-icon]").forEach((node) => {
            if (node.dataset.iconReady) return;
            node.prepend(icon(node.dataset.icon, Number(node.dataset.iconSize || 18)));
            node.dataset.iconReady = "true";
        });
    }

    function toast(message, type = "info") {
        const host = document.getElementById("tc-toasts");
        const item = el("div", { class: `tc-toast tc-toast--${type}`, role: "status" }, [
            icon(type === "success" ? "check" : type === "error" ? "x" : "cards", 17),
            el("span", { text: message }),
        ]);
        host.appendChild(item);
        setTimeout(() => item.remove(), 3500);
    }

    function announce(message) {
        const live = document.getElementById("tc-live");
        live.textContent = "";
        setTimeout(() => { live.textContent = message; }, 20);
    }

    async function copy(text, label = "Dato") {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(String(text));
            } else {
                const input = el("textarea", { value: String(text), "aria-hidden": "true" });
                input.style.cssText = "position:fixed;opacity:0;pointer-events:none";
                document.body.appendChild(input);
                input.select();
                if (!document.execCommand("copy")) throw new Error();
                input.remove();
            }
            toast(`${label} copiado`, "success");
            announce(`${label} copiado al portapapeles`);
        } catch {
            toast("No se pudo copiar el dato.", "error");
        }
    }

    let activeOverlay = null;
    let previousFocus = null;

    function closeOverlay() {
        if (!activeOverlay) return;
        activeOverlay.remove();
        activeOverlay = null;
        document.body.style.overflow = "";
        previousFocus?.focus?.();
    }

    function openOverlay(content, options = {}) {
        closeOverlay();
        previousFocus = document.activeElement;
        const backdrop = el("div", {
            class: `tc-backdrop${options.center ? " tc-backdrop--center" : ""}`,
            role: "presentation",
        }, [content]);
        backdrop.addEventListener("mousedown", (event) => {
            if (event.target === backdrop && options.dismissible !== false) closeOverlay();
        });
        document.getElementById("tc-overlay-root").appendChild(backdrop);
        activeOverlay = backdrop;
        document.body.style.overflow = "hidden";
        requestAnimationFrame(() => content.querySelector("input, select, button")?.focus());
        return backdrop;
    }

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && activeOverlay) closeOverlay();
    });

    function overlayHeader(title, subtitle = "") {
        return el("div", { class: "tc-overlay-head" }, [
            el("div", {}, [
                el("h2", { text: title }),
                subtitle ? el("p", { text: subtitle }) : null,
            ]),
            el("button", {
                class: "tc-icon-btn",
                type: "button",
                "aria-label": "Cerrar",
                title: "Cerrar",
                onclick: closeOverlay,
            }, [icon("x")]),
        ]);
    }

    function drawer(title, subtitle, content) {
        const panel = el("section", {
            class: "tc-drawer",
            role: "dialog",
            "aria-modal": "true",
            "aria-label": title,
        }, [overlayHeader(title, subtitle), content]);
        return openOverlay(panel);
    }

    function modal(title, subtitle, content, wide = false) {
        const panel = el("section", {
            class: `tc-modal${wide ? " tc-modal--wide" : ""}`,
            role: "dialog",
            "aria-modal": "true",
            "aria-label": title,
        }, [overlayHeader(title, subtitle), content]);
        return openOverlay(panel, { center: true });
    }

    function confirm(options) {
        return new Promise((resolve) => {
            const cancel = () => { closeOverlay(); resolve(false); };
            const accept = () => { closeOverlay(); resolve(true); };
            const content = el("div", {}, [
                el("p", { class: "tc-confirm-text", text: options.message }),
                el("div", { class: "tc-confirm-actions" }, [
                    el("button", { class: "tc-btn tc-btn--ghost", type: "button", text: "Cancelar", onclick: cancel }),
                    el("button", {
                        class: `tc-btn ${options.danger ? "tc-btn--danger" : "tc-btn--primary"}`,
                        type: "button",
                        text: options.acceptLabel || "Confirmar",
                        onclick: accept,
                    }),
                ]),
            ]);
            modal(options.title || "Confirmar", "", content);
        });
    }

    function field(label, input, options = {}) {
        return el("div", { class: `tc-field${options.full ? " tc-field--full" : ""}` }, [
            el("label", { for: input.id, text: label }),
            input,
            options.note ? el("small", { class: "tc-field-note", text: options.note }) : null,
        ]);
    }

    function normalize(value) {
        return String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    }

    function initials(name) {
        return String(name || "?").trim().split(/\s+/).slice(0, 2).map((part) => part[0] || "").join("").toUpperCase();
    }

    function formatCard(number) {
        return String(number || "").replace(/\D/g, "").replace(/(.{4})/g, "$1 ").trim();
    }

    function maskCard(number) {
        const clean = String(number || "").replace(/\D/g, "");
        const visible = clean.slice(-4);
        return `•••• •••• •••• ${visible}`;
    }

    function parseServerDate(value) {
        if (!value) return null;
        const base = String(value).includes("T") ? String(value) : String(value).replace(" ", "T");
        const iso = /(?:Z|[+-]\d\d:\d\d)$/.test(base) ? base : `${base}Z`;
        const date = new Date(iso);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function cooldown(value, now = Date.now()) {
        const date = parseServerDate(value);
        if (!date || date.getTime() <= now) return { ready: true, label: "Lista", milliseconds: 0 };
        const remaining = date.getTime() - now;
        const hours = Math.floor(remaining / 3600000);
        const minutes = Math.floor((remaining % 3600000) / 60000);
        const seconds = Math.floor((remaining % 60000) / 1000);
        return {
            ready: false,
            milliseconds: remaining,
            label: hours > 0 ? `${hours}h ${String(minutes).padStart(2, "0")}m` : `${minutes}m ${String(seconds).padStart(2, "0")}s`,
        };
    }

    return Object.freeze({
        icon, el, hydrateIcons, toast, announce, copy, closeOverlay, drawer, modal,
        confirm, field, normalize, initials, formatCard, maskCard, cooldown, parseServerDate,
    });
})();

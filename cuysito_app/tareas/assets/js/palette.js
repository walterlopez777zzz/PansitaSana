/**
 * ============================================================
 * Tareas — Paleta de comandos (Ctrl/⌘-K)
 * ============================================================
 * Componente accesible (patrón combobox + listbox con
 * `aria-activedescendant`) que unifica acciones rápidas y la
 * búsqueda global de ideas y misiones en un único campo.
 *
 * Es agnóstico del dominio: recibe un proveedor de ítems y se
 * encarga del filtrado, la navegación por teclado, el foco
 * atrapado y el render. La app (app.js) inyecta los comandos y
 * los resultados de búsqueda reales. Sólo puede haber una
 * instancia abierta a la vez.
 * ============================================================
 */
const TareasPalette = (() => {
    "use strict";

    const { el, icon, normalizeText, trapFocus } = TareasUI;

    let backdrop = null;       // overlay activo (o null si está cerrada)
    let releaseTrap = null;    // liberador del foco atrapado
    let returnFocus = null;    // elemento al que devolver el foco al cerrar

    const isOpen = () => backdrop !== null;

    /** Cierra la paleta (idempotente) y restaura el foco previo. */
    function close() {
        if (!backdrop) return;
        const node = backdrop;
        backdrop = null;
        node.classList.remove("is-open");
        node.classList.add("is-closing");
        document.removeEventListener("keydown", onEscapeCapture, true);
        if (releaseTrap) { releaseTrap(); releaseTrap = null; }
        setTimeout(() => node.remove(), 160);
        const target = returnFocus;
        returnFocus = null;
        if (target && typeof target.focus === "function") target.focus();
    }

    // Escape se captura en fase de captura para ganarle a otros handlers
    // (atajos globales, acordes) sin cerrar diálogos por debajo.
    function onEscapeCapture(e) {
        if (e.key === "Escape") { e.stopPropagation(); e.preventDefault(); close(); }
    }

    function footHint(keys, label) {
        return el("span", { class: "tk-cmd-foot-item" }, [
            el("kbd", { class: "tk-kbd", text: keys }),
            el("span", { text: label }),
        ]);
    }

    /**
     * Abre la paleta.
     * @param {Object}   cfg
     * @param {Function} cfg.getItems    Devuelve la lista de ítems:
     *   `{ group, label, hint, icon, keywords:[], run:Function }`.
     *   El orden recibido define el orden de aparición; `group` agrupa
     *   con encabezados (los ítems del mismo grupo deben venir contiguos).
     * @param {string}  [cfg.placeholder]
     */
    function open({ getItems, placeholder = "Escribe un comando o busca…" }) {
        if (isOpen()) return;
        returnFocus = document.activeElement;
        const allItems = getItems();
        let visible = [];
        let active = 0;

        const input = el("input", {
            class: "tk-cmd-input", type: "text", placeholder,
            role: "combobox", "aria-expanded": "true", "aria-controls": "tk-cmd-list",
            "aria-autocomplete": "list", "aria-activedescendant": "",
            "aria-label": placeholder, autocomplete: "off", spellcheck: "false",
        });
        const listbox = el("ul", { class: "tk-cmd-list", id: "tk-cmd-list", role: "listbox", "aria-label": "Resultados" });
        const dialog = el("div", {
            class: "tk-cmd", role: "dialog", "aria-modal": "true", "aria-label": "Paleta de comandos",
        }, [
            el("div", { class: "tk-cmd-head" }, [icon("search", 18), input]),
            listbox,
            el("div", { class: "tk-cmd-foot" }, [
                footHint("↑ ↓", "navegar"), footHint("↵", "ejecutar"), footHint("Esc", "cerrar"),
            ]),
        ]);
        backdrop = el("div", { class: "tk-cmd-backdrop tk-modal-backdrop" }, [dialog]);

        const options = () => [...listbox.querySelectorAll(".tk-cmd-opt")];

        function setActive(i) {
            const opts = options();
            if (!opts.length) { active = 0; input.setAttribute("aria-activedescendant", ""); return; }
            active = (i + opts.length) % opts.length;
            opts.forEach((o, idx) => o.setAttribute("aria-selected", String(idx === active)));
            const current = opts[active];
            input.setAttribute("aria-activedescendant", current.id);
            current.scrollIntoView({ block: "nearest" });
        }

        function execute(i) {
            if (typeof i === "number") active = i;
            const item = visible[active];
            if (!item) return;
            close();
            if (typeof item.run === "function") item.run();
        }

        function render() {
            const q = normalizeText(input.value.trim());
            visible = q
                ? allItems.filter((it) =>
                    normalizeText(`${it.label} ${it.hint || ""} ${(it.keywords || []).join(" ")}`).includes(q))
                : allItems;
            listbox.replaceChildren();
            if (!visible.length) {
                listbox.appendChild(el("li", { class: "tk-cmd-empty", role: "presentation", text: "Sin resultados" }));
                input.setAttribute("aria-activedescendant", "");
                return;
            }
            let lastGroup = null;
            visible.forEach((it, i) => {
                if (it.group && it.group !== lastGroup) {
                    lastGroup = it.group;
                    listbox.appendChild(el("li", { class: "tk-cmd-group", role: "presentation", text: it.group }));
                }
                const li = el("li", {
                    class: "tk-cmd-opt", id: `tk-cmd-opt-${i}`, role: "option", "aria-selected": "false",
                }, [
                    el("span", { class: "tk-cmd-opt-icon" }, [icon(it.icon || "arrow", 16)]),
                    el("span", { class: "tk-cmd-opt-label", text: it.label }),
                    it.hint ? el("span", { class: "tk-cmd-opt-hint", text: it.hint }) : null,
                ].filter(Boolean));
                li.addEventListener("mousemove", () => setActive(i));
                li.addEventListener("click", () => execute(i));
                listbox.appendChild(li);
            });
            setActive(0);
        }

        input.addEventListener("input", render);
        input.addEventListener("keydown", (e) => {
            if (e.key === "ArrowDown") { e.preventDefault(); setActive(active + 1); }
            else if (e.key === "ArrowUp") { e.preventDefault(); setActive(active - 1); }
            else if (e.key === "Home") { e.preventDefault(); setActive(0); }
            else if (e.key === "End") { e.preventDefault(); setActive(options().length - 1); }
            else if (e.key === "Enter") { e.preventDefault(); execute(); }
        });
        backdrop.addEventListener("mousedown", (e) => { if (e.target === backdrop) close(); });

        document.addEventListener("keydown", onEscapeCapture, true);
        document.body.appendChild(backdrop);
        releaseTrap = trapFocus(dialog);
        render();
        requestAnimationFrame(() => { backdrop.classList.add("is-open"); input.focus(); });
    }

    return Object.freeze({ open, close, isOpen });
})();

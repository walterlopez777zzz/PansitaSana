"use strict";

/**
 * ============================================================
 * Tareas — Utilidades de UI
 * ============================================================
 * Helpers de DOM (creación segura sin innerHTML para datos de
 * usuario → anti-XSS), iconos, toasts, modales y tema.
 * Expone el objeto global `TareasUI`.
 * ============================================================
 */

const TareasUI = (() => {

    // ── Iconos (SVG estáticos y de confianza) ────────────────
    const ICONS = {
        bolt:    '<path d="M13 2 3 14h8l-1 8 10-12h-8l1-8z"/>',
        target:  '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/>',
        // Diana del tab de Misiones: anillo + punto central. Su trazo se
        // engrosa por CSS (única excepción de grosor) para igualar el punto.
        diana:   '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>',
        pin:     '<path d="M9 4h6l-1 6 3 3v2H7v-2l3-3-1-6z"/><line x1="12" y1="15" x2="12" y2="21"/>',
        unpin:   '<path d="M9 4h6l-1 6 3 3v2H7v-2l3-3-1-6z"/><line x1="12" y1="15" x2="12" y2="21"/><line x1="3" y1="3" x2="21" y2="21"/>',
        // Caja del trazo centrada en (12,12) del viewBox: combinado con el
        // centrado de la insignia (.tk-check en el CSS) queda centrado exacto.
        check:   '<polyline points="5 12.5 10 17.5 19 6.5"/>',
        plus:    '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        trash:   '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
        edit:    '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        arrow:   '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        sun:     '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
        moon:    '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        users:   '<path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75M23 21h-4"/><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2z"/><circle cx="8.5" cy="7" r="4"/>',
        logout:  '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        inbox:   '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        close:   '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        chevron: '<polyline points="6 9 12 15 18 9"/>',
        search:  '<circle cx="11" cy="11" r="7"/><line x1="20.5" y1="20.5" x2="16.5" y2="16.5"/>',
        undo:    '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>',
        redo:    '<path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13"/>',
        clock:   '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/>',
        help:    '<circle cx="12" cy="12" r="10"/><path d="M9.1 9.2a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4"/><line x1="12" y1="17.5" x2="12" y2="17.5"/>',
        sort:    '<line x1="4" y1="7" x2="16" y2="7"/><line x1="4" y1="12" x2="13" y2="12"/><line x1="4" y1="17" x2="10" y2="17"/><polyline points="17.5 14 20 16.5 20 16.5"/><path d="M20 16.5V8"/><polyline points="22.5 11 20 8 17.5 11"/>',
        copy:    '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        clipboard: '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
        tag:     '<path d="M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.4" fill="currentColor" stroke="none"/>',
        calendar:'<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        bell:    '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9z"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        repeat:  '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        grip:    '<circle cx="9" cy="6" r="1.4" fill="currentColor" stroke="none"/><circle cx="15" cy="6" r="1.4" fill="currentColor" stroke="none"/><circle cx="9" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="9" cy="18" r="1.4" fill="currentColor" stroke="none"/><circle cx="15" cy="18" r="1.4" fill="currentColor" stroke="none"/>',
        board:   '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/>',
        chart:   '<line x1="4" y1="20" x2="4" y2="10"/><line x1="10" y1="20" x2="10" y2="4"/><line x1="16" y1="20" x2="16" y2="14"/><line x1="20" y1="20" x2="20" y2="20"/><line x1="2" y1="20" x2="22" y2="20"/>',
        download:'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        upload:  '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        comment: '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        paperclip:'<path d="M21.44 11.05 12.25 20.24a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
        layout:  '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/>',
        checkSquare: '<rect x="3" y="3" width="18" height="18" rx="2.5"/><polyline points="8 12 11 15 16 9"/>',
        command: '<rect x="3" y="4" width="18" height="16" rx="2"/><polyline points="7 9 10 12 7 15"/><line x1="12.5" y1="15" x2="16.5" y2="15"/>',
        more:    '<circle cx="5" cy="12" r="1.7" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.7" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.7" fill="currentColor" stroke="none"/>',
    };

    let uidSeq = 0;

    /**
     * Devuelve un <svg> de confianza para el icono pedido.
     * `opts.filled` lo renderiza como silueta sólida (fill) en vez de
     * contorno (stroke) — p. ej. el rayo "Ideas" del logo. En ambos modos
     * las uniones van redondeadas para un estilo uniforme entre iconos.
     */
    function icon(name, size = 18, opts = {}) {
        const ns = "http://www.w3.org/2000/svg";
        const filled = opts.filled === true;
        const svg = document.createElementNS(ns, "svg");
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.setAttribute("width", String(size));
        svg.setAttribute("height", String(size));
        svg.setAttribute("fill", filled ? "currentColor" : "none");
        svg.setAttribute("stroke", "currentColor");
        // En relleno, un trazo fino del mismo color redondea sutilmente las
        // esquinas para que combine con los iconos de contorno.
        svg.setAttribute("stroke-width", filled ? "1.5" : "2");
        svg.setAttribute("stroke-linecap", "round");
        svg.setAttribute("stroke-linejoin", "round");
        svg.setAttribute("aria-hidden", "true");
        // ICONS son constantes del propio código (no datos de usuario): seguro.
        svg.innerHTML = ICONS[name] || "";
        return svg;
    }

    /**
     * Hidrata los iconos declarativos del HTML estático (header, tabs, logo):
     * cada nodo con `data-icon` recibe su <svg> al frente, desde la misma
     * fuente (`ICONS`) que el resto de la UI. Atributos opcionales:
     * `data-icon-size` (px) y `data-icon-filled` ("true").
     */
    function hydrateIcons(root = document) {
        root.querySelectorAll("[data-icon]").forEach((node) => {
            const size = node.dataset.iconSize ? Number(node.dataset.iconSize) : 18;
            node.prepend(icon(node.dataset.icon, size, { filled: node.dataset.iconFilled === "true" }));
        });
    }

    /**
     * Aplica estilos inline desde un objeto.
     *
     * Distingue dos familias de propiedades:
     *   - Custom properties CSS (`--algo`): se setean con `setProperty`. Es
     *     obligatorio: el CSSOM no las expone como propiedades del objeto
     *     `style`, así que `Object.assign(node.style, { '--x': v })` las
     *     descarta en silencio (causa raíz del bug del acento siempre violeta).
     *   - Propiedades estándar (camelCase o kebab-case): se delegan a
     *     `setProperty`, que acepta ambas notaciones de forma robusta.
     *
     * Los valores `null`/`undefined` se ignoran; el resto se normaliza a
     * string para evitar sorpresas con números u otros tipos.
     */
    function applyStyles(node, styles) {
        for (const [prop, value] of Object.entries(styles)) {
            if (value === null || value === undefined) continue;
            node.style.setProperty(toCssProperty(prop), String(value));
        }
    }

    /** Convierte `marginTop` → `margin-top`; deja intactas las `--custom-props`. */
    function toCssProperty(prop) {
        if (prop.startsWith("--")) return prop;
        return prop.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`);
    }

    /**
     * Creador de elementos DOM. Las propiedades se setean de forma segura;
     * `text` usa textContent (nunca innerHTML) para neutralizar XSS.
     */
    function el(tag, props = {}, children = []) {
        const node = document.createElement(tag);
        for (const [key, value] of Object.entries(props)) {
            if (value === null || value === undefined || value === false) continue;
            if (key === "text") node.textContent = value;
            else if (key === "html") node.innerHTML = value; // sólo para markup de confianza
            else if (key === "class") node.className = value;
            else if (key === "dataset") Object.assign(node.dataset, value);
            else if (key.startsWith("on") && typeof value === "function") {
                node.addEventListener(key.slice(2).toLowerCase(), value);
            } else if (key === "style" && typeof value === "object") {
                applyStyles(node, value);
            } else {
                node.setAttribute(key, value);
            }
        }
        const list = Array.isArray(children) ? children : [children];
        for (const child of list) {
            if (child === null || child === undefined || child === false) continue;
            node.appendChild(typeof child === "string" ? document.createTextNode(child) : child);
        }
        return node;
    }

    // ── Dropdown personalizado (listbox accesible) ───────────
    /**
     * Reemplazo accesible del <select> nativo (patrón button + listbox).
     *
     * - ARIA: button[aria-haspopup=listbox][aria-expanded] + ul[role=listbox]
     *   con li[role=option][aria-selected] y aria-activedescendant.
     * - Teclado: ↑/↓, Home/End, Enter/Espacio, Esc, Tab y typeahead.
     * - El panel se monta en <body> (position:fixed) para no ser recortado
     *   por contenedores con overflow:hidden (tarjetas, modal).
     *
     * Devuelve el elemento raíz, que expone una propiedad `value`
     * (get/set) para integrarse con formularios como un <select>.
     *
     * @param {{ options: {value:string,label:string}[], value?:string,
     *           ariaLabel?:string, id?:string, className?:string,
     *           onChange?:(value:string)=>void }} cfg
     */
    function select(cfg = {}) {
        const options = Array.isArray(cfg.options) ? cfg.options : [];
        const uid = `tk-sel-${++uidSeq}`;
        const labelOf = (v) => {
            const o = options.find((x) => String(x.value) === String(v));
            return o ? o.label : "";
        };
        let current = cfg.value != null && labelOf(cfg.value) !== ""
            ? String(cfg.value)
            : (options[0] ? String(options[0].value) : "");
        let activeIndex = Math.max(0, options.findIndex((o) => String(o.value) === current));
        let open = false;

        const valueLabel = el("span", { class: "tk-select-value", text: labelOf(current) });
        const trigger = el("button", {
            type: "button",
            class: "tk-select-trigger",
            id: cfg.id || undefined,
            "aria-haspopup": "listbox",
            "aria-expanded": "false",
            "aria-controls": uid,
            "aria-label": cfg.ariaLabel || undefined,
        }, [valueLabel, icon("chevron", 16)]);

        const list = el("ul", {
            class: "tk-select-list",
            id: uid,
            role: "listbox",
            tabindex: "-1",
            "aria-label": cfg.ariaLabel || undefined,
        });
        const optionEls = options.map((o, i) => {
            const li = el("li", {
                class: "tk-select-option",
                id: `${uid}-opt-${i}`,
                role: "option",
                "aria-selected": String(String(o.value) === current),
                dataset: { value: String(o.value) },
            }, [el("span", { text: o.label })]);
            li.addEventListener("click", () => choose(String(o.value)));
            li.addEventListener("mousemove", () => setActive(i));
            return li;
        });
        optionEls.forEach((li) => list.appendChild(li));

        const root = el("div", { class: `tk-select ${cfg.className || ""}`.trim() }, [trigger]);

        function position() {
            const r = trigger.getBoundingClientRect();
            const gap = 6;
            list.style.minWidth = `${r.width}px`;
            list.style.left = `${r.left}px`;
            // medir alto para decidir si abre hacia arriba
            const h = list.offsetHeight;
            const below = window.innerHeight - r.bottom;
            if (below < h + gap && r.top > below) {
                list.style.top = `${Math.max(gap, r.top - h - gap)}px`;
            } else {
                list.style.top = `${r.bottom + gap}px`;
            }
        }

        function setActive(i) {
            if (!options.length) return;
            activeIndex = (i + options.length) % options.length;
            optionEls.forEach((li, idx) => li.classList.toggle("is-active", idx === activeIndex));
            const li = optionEls[activeIndex];
            if (li) {
                list.setAttribute("aria-activedescendant", li.id);
                li.scrollIntoView({ block: "nearest" });
            }
        }

        function openList() {
            if (open || !options.length) return;
            open = true;
            document.body.appendChild(list);
            root.classList.add("is-open");
            trigger.setAttribute("aria-expanded", "true");
            position();
            setActive(Math.max(0, options.findIndex((o) => String(o.value) === current)));
            list.focus();
            document.addEventListener("pointerdown", onDocPointer, true);
            window.addEventListener("resize", onReflow, true);
            window.addEventListener("scroll", onReflow, true);
        }

        function closeList(focusTrigger = true) {
            if (!open) return;
            open = false;
            root.classList.remove("is-open");
            trigger.setAttribute("aria-expanded", "false");
            list.removeAttribute("aria-activedescendant");
            document.removeEventListener("pointerdown", onDocPointer, true);
            window.removeEventListener("resize", onReflow, true);
            window.removeEventListener("scroll", onReflow, true);
            if (list.parentNode) list.parentNode.removeChild(list);
            if (focusTrigger) trigger.focus();
        }

        const onReflow = () => { if (open) position(); };
        const onDocPointer = (e) => {
            if (!root.contains(e.target) && !list.contains(e.target)) closeList(false);
        };

        function choose(v) {
            const changed = v !== current;
            current = v;
            valueLabel.textContent = labelOf(v);
            optionEls.forEach((li) => li.setAttribute("aria-selected", String(li.dataset.value === v)));
            closeList();
            if (changed && typeof cfg.onChange === "function") cfg.onChange(v);
        }

        // Typeahead: escribir para saltar a la opción que empieza con esas letras.
        let typeBuf = "", typeTimer = null;
        function typeahead(ch) {
            typeBuf += ch.toLowerCase();
            clearTimeout(typeTimer);
            typeTimer = setTimeout(() => { typeBuf = ""; }, 600);
            const idx = options.findIndex((o) => (o.label || "").toLowerCase().startsWith(typeBuf));
            if (idx < 0) return;
            if (open) setActive(idx); else choose(String(options[idx].value));
        }

        trigger.addEventListener("click", () => (open ? closeList() : openList()));
        trigger.addEventListener("keydown", (e) => {
            if (["ArrowDown", "ArrowUp", "Enter", " "].includes(e.key)) {
                e.preventDefault();
                openList();
            } else if (e.key.length === 1 && /\S/.test(e.key)) {
                typeahead(e.key);
            }
        });
        list.addEventListener("keydown", (e) => {
            switch (e.key) {
                case "ArrowDown": e.preventDefault(); setActive(activeIndex + 1); break;
                case "ArrowUp": e.preventDefault(); setActive(activeIndex - 1); break;
                case "Home": e.preventDefault(); setActive(0); break;
                case "End": e.preventDefault(); setActive(options.length - 1); break;
                case "Enter":
                case " ": e.preventDefault(); if (options[activeIndex]) choose(String(options[activeIndex].value)); break;
                case "Escape": e.preventDefault(); e.stopPropagation(); closeList(); break;
                case "Tab": closeList(false); break;
                default:
                    if (e.key.length === 1 && /\S/.test(e.key)) typeahead(e.key);
            }
        });

        Object.defineProperty(root, "value", {
            get: () => current,
            set: (v) => { current = String(v); valueLabel.textContent = labelOf(current); optionEls.forEach((li) => li.setAttribute("aria-selected", String(li.dataset.value === current))); },
            configurable: true,
        });
        root.focus = () => trigger.focus();
        return root;
    }

    // ── Menú de acciones (overflow accesible) ────────────────
    /**
     * Menú de acciones tipo "kebab" (patrón button + role="menu"). Acota el
     * ancho de un clúster de acciones en contenedores estrechos (p. ej. las
     * tarjetas de idea): las acciones secundarias viven aquí y agregar más no
     * ensancha la UI ni empuja otros elementos. Escalable y predecible.
     *
     * - ARIA: button[aria-haspopup="menu"][aria-expanded] + ul[role="menu"]
     *   con button[role="menuitem"]. El panel se monta en <body> (position:
     *   fixed) para no recortarse por contenedores con `overflow:hidden`.
     * - Teclado: ↓/↑ mueven el foco (cíclico), Home/End saltan a los
     *   extremos, Enter/Espacio activan (nativo del botón), Esc cierra y
     *   devuelve el foco al disparador, Tab cierra sin retener el foco.
     * - Puntero: clic fuera cierra; activar un ítem cierra y ejecuta.
     * - Se reposiciona ante scroll/resize y se autocierra si su disparador
     *   se desconecta del DOM (p. ej. la tarjeta se re-renderiza).
     *
     * @param {{ items: { label:string, icon?:string, onClick?:()=>void,
     *           danger?:boolean, disabled?:boolean }[],
     *           ariaLabel?:string, triggerClass?:string, iconName?:string,
     *           iconSize?:number, align?:"start"|"end" }} cfg
     * @returns {HTMLElement} raíz que contiene el disparador.
     */
    function menu(cfg = {}) {
        const items = (Array.isArray(cfg.items) ? cfg.items : []).filter(Boolean);
        const label = cfg.ariaLabel || "Más acciones";
        const align = cfg.align === "start" ? "start" : "end";
        const uid = `tk-menu-${++uidSeq}`;
        let open = false;

        const trigger = el("button", {
            type: "button",
            class: `tk-menu-trigger ${cfg.triggerClass || "tk-btn tk-btn--ghost tk-btn--sm"}`.trim(),
            "aria-haspopup": "menu",
            "aria-expanded": "false",
            "aria-controls": uid,
            "aria-label": label,
            title: label,
        }, [icon(cfg.iconName || "more", cfg.iconSize || 16)]);

        const list = el("ul", {
            class: "tk-menu-list",
            id: uid,
            role: "menu",
            tabindex: "-1",
            "aria-label": label,
        });

        const itemEls = items.map((it) => {
            const btn = el("button", {
                type: "button",
                role: "menuitem",
                tabindex: "-1",
                class: `tk-menu-item${it.danger ? " tk-menu-item--danger" : ""}`,
                disabled: it.disabled ? "" : null,
            }, [
                it.icon ? icon(it.icon, 15) : null,
                el("span", { class: "tk-menu-item-label", text: it.label }),
            ]);
            btn.addEventListener("click", () => activate(it));
            list.appendChild(el("li", { role: "none" }, [btn]));
            return btn;
        });

        const root = el("div", { class: "tk-menu" }, [trigger]);

        const enabled = () => itemEls.filter((b) => !b.disabled);

        function position() {
            const r = trigger.getBoundingClientRect();
            const gap = 6;
            list.style.minWidth = `${Math.max(Math.round(r.width), 168)}px`;
            const w = list.offsetWidth;
            let left = align === "end" ? r.right - w : r.left;
            left = Math.max(gap, Math.min(left, window.innerWidth - w - gap));
            list.style.left = `${Math.round(left)}px`;
            const h = list.offsetHeight;
            const below = window.innerHeight - r.bottom;
            // Abre hacia arriba sólo si abajo no entra y arriba hay más sitio.
            const up = below < h + gap && r.top > below;
            list.style.top = `${Math.round(up ? Math.max(gap, r.top - h - gap) : r.bottom + gap)}px`;
        }

        const onReflow = () => {
            if (!open) return;
            if (!trigger.isConnected) closeMenu(false);
            else position();
        };
        const onDocPointer = (e) => {
            if (!root.contains(e.target) && !list.contains(e.target)) closeMenu(false);
        };

        function openMenu() {
            if (open || !enabled().length) return;
            open = true;
            document.body.appendChild(list);
            root.classList.add("is-open");
            trigger.setAttribute("aria-expanded", "true");
            position();
            enabled()[0].focus();
            document.addEventListener("pointerdown", onDocPointer, true);
            window.addEventListener("resize", onReflow, true);
            window.addEventListener("scroll", onReflow, true);
        }

        function closeMenu(focusTrigger = true) {
            if (!open) return;
            open = false;
            root.classList.remove("is-open");
            trigger.setAttribute("aria-expanded", "false");
            document.removeEventListener("pointerdown", onDocPointer, true);
            window.removeEventListener("resize", onReflow, true);
            window.removeEventListener("scroll", onReflow, true);
            if (list.parentNode) list.parentNode.removeChild(list);
            if (focusTrigger && trigger.isConnected) trigger.focus();
        }

        function activate(it) {
            if (it.disabled) return;
            // Cierra antes de ejecutar: devuelve el foco al disparador, que pasa
            // a ser el `returnFocus` de cualquier modal que abra la acción.
            closeMenu(true);
            if (typeof it.onClick === "function") it.onClick();
        }

        function moveFocus(delta) {
            const f = enabled();
            if (!f.length) return;
            const i = f.indexOf(document.activeElement);
            f[(i < 0 ? 0 : (i + delta + f.length) % f.length)].focus();
        }

        trigger.addEventListener("click", (e) => {
            e.stopPropagation();
            open ? closeMenu() : openMenu();
        });
        trigger.addEventListener("keydown", (e) => {
            if (e.key === "ArrowDown" || e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                openMenu();
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                openMenu();
                const f = enabled();
                if (f.length) f[f.length - 1].focus();
            }
        });
        list.addEventListener("keydown", (e) => {
            switch (e.key) {
                case "ArrowDown": e.preventDefault(); moveFocus(1); break;
                case "ArrowUp":   e.preventDefault(); moveFocus(-1); break;
                case "Home":      e.preventDefault(); enabled()[0]?.focus(); break;
                case "End":       e.preventDefault(); { const f = enabled(); f[f.length - 1]?.focus(); } break;
                case "Escape":    e.preventDefault(); e.stopPropagation(); closeMenu(true); break;
                case "Tab":       closeMenu(false); break;
            }
        });

        return root;
    }

    // ── Selector de color (swatches accesible) ───────────────
    /**
     * Grupo de muestras de color accesible (patrón radiogroup). Devuelve la
     * raíz, que expone `value` (get/set) para integrarse como un campo de
     * formulario. Teclado: ←/→ y Home/End mueven la selección.
     *
     * @param {{ options: {value:string,color:string,label?:string}[],
     *           value?:string, ariaLabel?:string }} cfg
     */
    function swatchPicker(cfg = {}) {
        const options = Array.isArray(cfg.options) ? cfg.options : [];
        let current = cfg.value != null ? String(cfg.value) : (options[0] ? String(options[0].value) : "");

        const root = el("div", { class: "tk-swatches", role: "radiogroup", "aria-label": cfg.ariaLabel || undefined });
        const buttons = options.map((o) => {
            const selected = String(o.value) === current;
            const b = el("button", {
                class: `tk-swatch${selected ? " is-selected" : ""}`,
                type: "button",
                role: "radio",
                "aria-checked": String(selected),
                "aria-label": o.label || o.value,
                tabindex: selected ? "0" : "-1",
                style: { background: o.color },
                dataset: { value: String(o.value) },
            });
            b.addEventListener("click", () => set(String(o.value), true));
            return b;
        });
        buttons.forEach((b) => root.appendChild(b));

        function set(v, focus = false) {
            current = v;
            buttons.forEach((b) => {
                const on = b.dataset.value === v;
                b.classList.toggle("is-selected", on);
                b.setAttribute("aria-checked", String(on));
                b.tabIndex = on ? 0 : -1;
                if (on && focus) b.focus();
            });
        }

        root.addEventListener("keydown", (e) => {
            const idx = options.findIndex((o) => String(o.value) === current);
            if (idx < 0) return;
            let next = null;
            if (e.key === "ArrowRight" || e.key === "ArrowDown") next = (idx + 1) % options.length;
            else if (e.key === "ArrowLeft" || e.key === "ArrowUp") next = (idx - 1 + options.length) % options.length;
            else if (e.key === "Home") next = 0;
            else if (e.key === "End") next = options.length - 1;
            if (next === null) return;
            e.preventDefault();
            set(String(options[next].value), true);
        });

        Object.defineProperty(root, "value", { get: () => current, set: (v) => set(String(v)), configurable: true });
        return root;
    }

    // ── Toasts ───────────────────────────────────────────────
    function toastHost() {
        let host = document.getElementById("tk-toasts");
        if (!host) {
            host = el("div", { id: "tk-toasts", class: "tk-toasts", "aria-live": "polite" });
            document.body.appendChild(host);
        }
        return host;
    }

    function dismissToast(node) {
        if (!node.isConnected) return;
        node.classList.add("is-leaving");
        setTimeout(() => node.remove(), 220);
    }

    /**
     * Auto-descarte con "holds": el toast no se cierra mientras exista alguna
     * razón para retenerlo (cursor encima, foco de teclado y —en modo pausa—
     * pestaña en segundo plano). Soporta dos estrategias:
     *
     *  - `pauseOnHold:true`  → la cuenta se *congela* mientras está retenido y
     *    se reanuda con el tiempo restante exacto. `onHoldChange` permite
     *    congelar/reanudar una barra de progreso (toasts de acción).
     *  - `pauseOnHold:false` → la cuenta *sigue corriendo siempre*; sólo se
     *    posterga el descarte si al cumplirse el tiempo el toast aún está bajo
     *    el cursor/foco (toasts simples: no se cierran "debajo del cursor").
     *
     * Con `duration <= 0` el toast es persistente. `onElapsed` se invoca como
     * mucho una vez. Devuelve `{ cancel }` para descartes externos (acción o
     * cierre manual) sin disparar `onElapsed`.
     *
     * @param {HTMLElement} node
     * @param {number} duration  ms (<= 0 ⇒ persistente).
     * @param {{ onElapsed?: () => void,
     *           onHoldChange?: (held: boolean, remaining: number, duration: number) => void,
     *           pauseOnHold?: boolean, holdOnHidden?: boolean }} [opts]
     * @returns {{ cancel: () => void }}
     */
    function autoDismiss(node, duration, opts = {}) {
        const { onElapsed, onHoldChange, pauseOnHold = true, holdOnHidden = pauseOnHold } = opts;
        if (!(duration > 0)) return { cancel() {} };

        const holds = new Set();
        let done = false;
        let timer = 0;
        let startedAt = 0;
        let remaining = duration;
        let pendingElapse = false;

        function schedule(ms) {
            startedAt = performance.now();
            timer = setTimeout(onTimeUp, ms);
        }
        function onTimeUp() {
            timer = 0;
            if (!pauseOnHold && holds.size) { pendingElapse = true; return; }
            finish();
        }
        function finish() {
            if (done) return;
            done = true;
            teardown();
            if (onElapsed) onElapsed();
        }

        // Sólo modo pausa: congelar/reanudar la cuenta con el tiempo restante.
        function freeze() {
            if (!timer) return;
            clearTimeout(timer);
            timer = 0;
            remaining = Math.max(0, remaining - (performance.now() - startedAt));
        }
        function resume() {
            if (!done && !timer) schedule(remaining);
        }

        function hold(reason) {
            if (done || holds.has(reason)) return;
            holds.add(reason);
            if (holds.size > 1 || !pauseOnHold) return;
            freeze();
            if (onHoldChange) onHoldChange(true, remaining, duration);
        }
        function release(reason) {
            if (!holds.delete(reason) || holds.size) return;
            if (pauseOnHold) {
                if (onHoldChange) onHoldChange(false, remaining, duration);
                resume();
            } else if (pendingElapse) {
                finish();
            }
        }

        function onEnter(e) { if (e.pointerType !== "touch") hold("hover"); }
        function onLeave(e) { if (e.pointerType !== "touch") release("hover"); }
        function onFocusIn() { hold("focus"); }
        function onFocusOut(e) { if (!node.contains(e.relatedTarget)) release("focus"); }
        function onVisibility() { if (document.hidden) hold("hidden"); else release("hidden"); }

        function teardown() {
            clearTimeout(timer);
            node.removeEventListener("pointerenter", onEnter);
            node.removeEventListener("pointerleave", onLeave);
            node.removeEventListener("focusin", onFocusIn);
            node.removeEventListener("focusout", onFocusOut);
            if (holdOnHidden) document.removeEventListener("visibilitychange", onVisibility);
        }

        node.addEventListener("pointerenter", onEnter);
        node.addEventListener("pointerleave", onLeave);
        node.addEventListener("focusin", onFocusIn);
        node.addEventListener("focusout", onFocusOut);
        if (holdOnHidden) document.addEventListener("visibilitychange", onVisibility);

        schedule(duration);
        return { cancel() { if (!done) { done = true; teardown(); } } };
    }

    function toast(message, type = "info", ms = 3200) {
        const tIcon = type === "error" ? "close" : type === "success" ? "check" : "bolt";
        const node = el("div", { class: `tk-toast tk-toast--${type}` }, [
            icon(tIcon, 16, { filled: tIcon === "bolt" }),
            el("span", { text: message }),
        ]);
        toastHost().appendChild(node);
        // La cuenta sigue corriendo siempre; sólo se posterga el cierre si el
        // tiempo se cumple con el cursor/foco encima (no se va bajo el cursor).
        const ctrl = autoDismiss(node, ms, { pauseOnHold: false, onElapsed: () => dismissToast(node) });
        return { dismiss() { ctrl.cancel(); dismissToast(node); } };
    }

    /**
     * Toast con acción y cuenta regresiva visual. La barra indica cuánto
     * tiempo permanece disponible el botón; al expirar se ejecuta `onExpire`
     * y al pulsarlo `onAction` (excluyentes: lo que ocurra primero gana).
     * Al pasar el cursor o enfocarlo, la cuenta se pausa y la barra se congela.
     *
     * @param {string} message
     * @param {{ actionLabel?:string, duration?:number,
     *           onAction?:()=>void, onExpire?:()=>void }} cfg
     */
    function toastAction(message, { actionLabel = "Deshacer", duration = 5000, onAction, onExpire } = {}) {
        let settled = false;
        const bar = el("span", { class: "tk-toast-timer" });
        const btn = el("button", { class: "tk-toast-action", type: "button" }, [icon("undo", 15), el("span", { text: actionLabel })]);
        const node = el("div", { class: "tk-toast tk-toast--action", role: "status" }, [
            icon("clock", 16),
            el("span", { class: "tk-toast-msg", text: message }),
            btn,
            bar,
        ]);
        toastHost().appendChild(node);

        // Barra: arranca llena y se vacía linealmente en `duration` (CSS).
        requestAnimationFrame(() => { bar.style.transitionDuration = `${duration}ms`; bar.style.transform = "scaleX(0)"; });

        const ctrl = autoDismiss(node, duration, {
            onElapsed: () => settle(onExpire),
            // Congela la barra en la fracción restante y la reanuda al salir.
            onHoldChange(held, remaining) {
                node.classList.toggle("is-paused", held);
                if (held) {
                    bar.style.transitionDuration = "0ms";
                    bar.style.transform = `scaleX(${remaining / duration})`;
                } else {
                    bar.style.transitionDuration = `${remaining}ms`;
                    requestAnimationFrame(() => { bar.style.transform = "scaleX(0)"; });
                }
            },
        });

        function settle(cb) {
            if (settled) return;
            settled = true;
            ctrl.cancel();
            dismissToast(node);
            if (cb) cb();
        }
        btn.addEventListener("click", () => settle(onAction));
        return { dismiss: () => settle(onExpire) };
    }

    /** Ajusta la altura de un <textarea> a su contenido (auto-grow). */
    function autoGrow(textarea, maxPx = 320) {
        const resize = () => {
            textarea.style.height = "auto";
            textarea.style.height = Math.min(textarea.scrollHeight, maxPx) + "px";
        };
        textarea.addEventListener("input", resize);
        requestAnimationFrame(resize);
        return resize;
    }

    /**
     * Contador de caracteres discreto: sólo se revela al acercarse al límite
     * (>=75% de `max`) y vira a "alerta" cuando quedan pocos. Se actualiza en
     * cada cambio del control y refleja el conteo accesiblemente (aria-live).
     */
    function charCounter(control, max) {
        const node = el("span", { class: "tk-counter", "aria-live": "polite" });
        const update = () => {
            const used = control.value.length;
            const remaining = max - used;
            node.textContent = `${used}/${max}`;
            node.classList.toggle("is-near", used >= max * 0.75);
            node.classList.toggle("is-warn", remaining <= max * 0.1);
        };
        control.addEventListener("input", update);
        update();
        return node;
    }

    /** Normaliza texto para comparaciones (minúsculas, sin diacríticos). */
    function normalizeText(str) {
        return (str || "").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    }

    /**
     * Devuelve un DocumentFragment con `text`, resaltando las coincidencias de
     * `query` en <mark>. La comparación es insensible a mayúsculas y a tildes;
     * como la normalización NFD altera la longitud, se mantiene un mapa de
     * índice-normalizado → índice-original para recortar el texto real.
     * Construido con nodos de texto (nunca innerHTML con datos de usuario) →
     * seguro frente a XSS.
     */
    function highlight(text, query) {
        const frag = document.createDocumentFragment();
        const src = text || "";
        const q = (query || "").trim();
        const emit = () => { frag.appendChild(document.createTextNode(src)); return frag; };
        if (!q) return emit();

        // Normalizamos carácter por carácter, guardando el índice original de
        // cada carácter normalizado producido (los diacríticos sueltos → 0).
        let norm = "";
        const map = [];
        for (let i = 0; i < src.length; i++) {
            const n = normalizeText(src[i]);
            for (let k = 0; k < n.length; k++) { norm += n[k]; map.push(i); }
        }
        const nQ = normalizeText(q);
        if (nQ === "") return emit();

        let from = 0;
        let at = norm.indexOf(nQ, 0);
        if (at === -1) return emit();
        while (at !== -1) {
            const start = map[at];
            const end = at + nQ.length < map.length ? map[at + nQ.length] : src.length;
            if (start > from) frag.appendChild(document.createTextNode(src.slice(from, start)));
            frag.appendChild(el("mark", { class: "tk-mark", text: src.slice(start, end) }));
            from = end;
            at = norm.indexOf(nQ, at + nQ.length);
        }
        if (from < src.length) frag.appendChild(document.createTextNode(src.slice(from)));
        return frag;
    }

    /**
     * Construye una barra de búsqueda accesible (icono + input + limpiar).
     * `onInput(value)` se llama en cada cambio. Expone `.input` y `.setValue`.
     */
    function searchBar({ placeholder = "Buscar…", ariaLabel = "Buscar", onInput } = {}) {
        const input = el("input", { type: "search", class: "tk-search-input", placeholder, "aria-label": ariaLabel, autocomplete: "off", spellcheck: "false" });
        const clear = el("button", { class: "tk-search-clear", type: "button", "aria-label": "Limpiar búsqueda", title: "Limpiar" }, [icon("close", 14)]);
        const root = el("div", { class: "tk-search" }, [icon("search", 16), input, clear]);
        const sync = () => {
            root.classList.toggle("has-value", input.value !== "");
            if (onInput) onInput(input.value);
        };
        input.addEventListener("input", sync);
        input.addEventListener("keydown", (e) => { if (e.key === "Escape" && input.value) { e.stopPropagation(); input.value = ""; sync(); } });
        clear.addEventListener("click", () => { input.value = ""; sync(); input.focus(); });
        root.input = input;
        root.setValue = (v) => { input.value = v || ""; sync(); };
        return root;
    }

    // ── Accesibilidad ────────────────────────────────────────
    const FOCUSABLE_SELECTOR = [
        "a[href]", "button:not([disabled])", "textarea:not([disabled])",
        "input:not([disabled])", "select:not([disabled])", "[tabindex]:not([tabindex='-1'])",
    ].join(",");

    /**
     * Atrapa el foco dentro de `container` (Tab/Shift+Tab cicla sin salir).
     * No interfiere cuando el foco está fuera del contenedor (p. ej. mientras
     * un dropdown custom monta su listbox en <body>). Devuelve una función
     * para liberar el atrapado.
     */
    function trapFocus(container) {
        const onKey = (e) => {
            if (e.key !== "Tab") return;
            const active = document.activeElement;
            if (!container.contains(active)) return;
            const focusables = Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR))
                .filter((n) => n.tabIndex !== -1 && (n.offsetParent !== null || n === active));
            if (!focusables.length) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (e.shiftKey && active === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && active === last) { e.preventDefault(); first.focus(); }
        };
        container.addEventListener("keydown", onKey);
        return () => container.removeEventListener("keydown", onKey);
    }

    /**
     * Región viva (aria-live) compartida para anunciar acciones a lectores de
     * pantalla (deshacer/rehacer, etc.). Se reescribe en dos pasos para forzar
     * el reanuncio aunque el texto se repita.
     */
    function announce(message) {
        let node = document.getElementById("tk-live");
        if (!node) {
            node = el("div", { id: "tk-live", class: "tk-sr-only", role: "status", "aria-live": "polite", "aria-atomic": "true" });
            document.body.appendChild(node);
        }
        node.textContent = "";
        requestAnimationFrame(() => { node.textContent = message || ""; });
    }

    // ── Modal genérico ───────────────────────────────────────
    function openModal({ title, fields = [], submitLabel = "Guardar", onSubmit }) {
        return new Promise((resolve) => {
            const inputs = {};
            const fieldNodes = fields.map((f) => {
                let input;
                if (f.type === "textarea") {
                    input = el("textarea", { id: `f-${f.name}`, placeholder: f.placeholder || "", maxlength: f.maxlength || "" });
                    if (f.value) input.value = f.value;
                    autoGrow(input);
                } else if (f.type === "select") {
                    input = select({
                        id: `f-${f.name}`,
                        ariaLabel: f.label,
                        value: f.value,
                        options: f.options || [],
                        className: "tk-select--block",
                    });
                } else if (f.type === "swatch") {
                    input = swatchPicker({ value: f.value, options: f.options || [], ariaLabel: f.label });
                } else if (f.type === "custom") {
                    // Control externo ya construido (expone `value`); gestiona su
                    // propia accesibilidad interna. Se inserta tal cual.
                    input = f.control;
                } else {
                    input = el("input", { id: `f-${f.name}`, type: f.type || "text", placeholder: f.placeholder || "", maxlength: f.maxlength || "" });
                    if (f.value) input.value = f.value;
                }
                inputs[f.name] = input;
                // Asociamos la etiqueta por aria-labelledby (no por <label for>):
                // así el control conserva su nombre accesible para lectores de
                // pantalla, pero clickear la etiqueta NO enfoca ni abre el control
                // —sólo clickear el control directamente lo hace—.
                const labelId = `fl-${f.name}`;
                const labelNode = el("label", { id: labelId, text: f.label });
                const children = [labelNode, input];
                if (f.type === "select") {
                    // El disparador es el botón interno; le quitamos el aria-label
                    // duplicado y lo enlazamos a la etiqueta visible.
                    const trigger = input.querySelector(".tk-select-trigger");
                    if (trigger) {
                        trigger.removeAttribute("aria-label");
                        trigger.setAttribute("aria-labelledby", labelId);
                    }
                } else if (f.type === "swatch") {
                    input.removeAttribute("aria-label");
                    input.setAttribute("aria-labelledby", labelId);
                } else if (f.type === "custom") {
                    // El control custom expone su propio nombre accesible; sólo
                    // enlazamos el grupo a la etiqueta visible si declara un rol.
                    if (input.getAttribute("role")) input.setAttribute("aria-labelledby", labelId);
                } else {
                    input.setAttribute("aria-labelledby", labelId);
                    if (f.maxlength) children.push(charCounter(input, Number(f.maxlength)));
                }
                return el("div", { class: "tk-field" }, children);
            });

            const backdrop = el("div", { class: "tk-modal-backdrop" });
            const returnFocus = document.activeElement;
            let releaseTrap = null;
            const close = (result) => {
                backdrop.classList.remove("is-open");
                backdrop.classList.add("is-closing");
                setTimeout(() => backdrop.remove(), 200);
                document.removeEventListener("keydown", onKey);
                if (releaseTrap) releaseTrap();
                if (returnFocus && typeof returnFocus.focus === "function") returnFocus.focus();
                resolve(result);
            };
            const onKey = (e) => { if (e.key === "Escape") close(null); };

            const submitBtn = el("button", { class: "tk-btn tk-btn--primary", type: "button", text: submitLabel });
            submitBtn.addEventListener("click", async () => {
                const values = {};
                for (const [name, node] of Object.entries(inputs)) values[name] = node.value;
                submitBtn.disabled = true;
                try {
                    const ok = onSubmit ? await onSubmit(values) : true;
                    if (ok !== false) close(values);
                    else submitBtn.disabled = false;
                } catch (err) {
                    submitBtn.disabled = false;
                    toast(err.message || "Error al guardar.", "error");
                }
            });

            const titleId = "tk-modal-title";
            const modal = el("div", { class: "tk-modal", role: "dialog", "aria-modal": "true", "aria-labelledby": titleId }, [
                el("h2", { id: titleId, text: title }),
                ...fieldNodes,
                el("div", { class: "tk-modal-actions" }, [
                    el("button", { class: "tk-btn tk-btn--ghost", type: "button", text: "Cancelar", onClick: () => close(null) }),
                    submitBtn,
                ]),
            ]);

            backdrop.appendChild(modal);
            backdrop.addEventListener("click", (e) => { if (e.target === backdrop) close(null); });
            document.body.appendChild(backdrop);
            document.addEventListener("keydown", onKey);
            releaseTrap = trapFocus(modal);
            requestAnimationFrame(() => {
                backdrop.classList.add("is-open");
                const first = fieldNodes[0]?.querySelector("input, textarea, select, button");
                if (first) first.focus();
            });
        });
    }

    /** Confirmación simple (resuelve a boolean). */
    function confirmDialog(message, { danger = false, confirmLabel = "Confirmar" } = {}) {
        return new Promise((resolve) => {
            const backdrop = el("div", { class: "tk-modal-backdrop" });
            const returnFocus = document.activeElement;
            let releaseTrap = null;
            const close = (val) => {
                backdrop.classList.remove("is-open");
                backdrop.classList.add("is-closing");
                setTimeout(() => backdrop.remove(), 200);
                document.removeEventListener("keydown", onKey);
                if (releaseTrap) releaseTrap();
                if (returnFocus && typeof returnFocus.focus === "function") returnFocus.focus();
                resolve(val);
            };
            const onKey = (e) => { if (e.key === "Escape") close(false); };
            const confirmBtn = el("button", { class: `tk-btn ${danger ? "tk-btn--danger tk-btn--primary" : "tk-btn--primary"}`, type: "button", text: confirmLabel, onClick: () => close(true) });
            const titleId = "tk-confirm-title";
            const modal = el("div", { class: "tk-modal", role: "alertdialog", "aria-modal": "true", "aria-labelledby": titleId }, [
                el("h2", { id: titleId, text: "¿Confirmar?" }),
                el("p", { text: message, style: { color: "var(--tk-text-muted)", margin: "0 0 .5rem" } }),
                el("div", { class: "tk-modal-actions" }, [
                    el("button", { class: "tk-btn tk-btn--ghost", type: "button", text: "Cancelar", onClick: () => close(false) }),
                    confirmBtn,
                ]),
            ]);
            backdrop.appendChild(modal);
            backdrop.addEventListener("click", (e) => { if (e.target === backdrop) close(false); });
            document.body.appendChild(backdrop);
            document.addEventListener("keydown", onKey);
            releaseTrap = trapFocus(modal);
            requestAnimationFrame(() => { backdrop.classList.add("is-open"); confirmBtn.focus(); });
        });
    }

    // ── Formato ──────────────────────────────────────────────
    function relativeDate(utcString) {
        if (!utcString) return "";
        const then = new Date(utcString.replace(" ", "T") + (utcString.includes("Z") ? "" : "Z"));
        const diff = (Date.now() - then.getTime()) / 1000;
        if (isNaN(diff)) return "";
        if (diff < 60) return "recién";
        if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
        if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
        if (diff < 604800) return `hace ${Math.floor(diff / 86400)} d`;
        return then.toLocaleDateString("es", { day: "numeric", month: "short" });
    }

    /** Parsea una fecha UTC del backend ("YYYY-MM-DD HH:MM:SS") a Date. */
    function parseUtc(utcString) {
        if (!utcString) return null;
        const d = new Date(utcString.replace(" ", "T") + (utcString.includes("Z") ? "" : "Z"));
        return isNaN(d.getTime()) ? null : d;
    }

    // Ticker único y perezoso: refresca todas las fechas relativas vivas del
    // DOM cada minuto. Consultar el DOM en cada tic evita referencias colgadas
    // a nodos recreados por los renders.
    let relTickerOn = false;
    function ensureRelTicker() {
        if (relTickerOn) return;
        relTickerOn = true;
        setInterval(() => {
            document.querySelectorAll("time.tk-timeago[data-ts]").forEach((node) => {
                if (node.dataset.ts) node.textContent = relativeDate(node.dataset.ts);
            });
        }, 60000);
    }

    /**
     * Elemento <time> con fecha relativa que se autoactualiza (P5). Expone la
     * fecha absoluta como `datetime` y `title` para accesibilidad/hover.
     */
    function timeAgo(utcString, { className = "" } = {}) {
        const node = el("time", { class: `tk-timeago${className ? " " + className : ""}`, text: relativeDate(utcString) });
        const date = parseUtc(utcString);
        if (date) {
            node.dataset.ts = utcString;
            node.setAttribute("datetime", date.toISOString());
            node.setAttribute("title", date.toLocaleString("es"));
        }
        ensureRelTicker();
        return node;
    }

    const initials = (name) => (name || "?").trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

    // ── Tema ─────────────────────────────────────────────────
    function initTheme(toggleBtn) {
        const root = document.documentElement;
        const apply = (theme) => {
            root.setAttribute("data-theme", theme);
            if (toggleBtn) {
                toggleBtn.replaceChildren(icon(theme === "dark" ? "sun" : "moon", 18));
                toggleBtn.setAttribute("aria-label", theme === "dark" ? "Modo claro" : "Modo oscuro");
            }
        };
        const saved = localStorage.getItem("theme") ||
            (matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
        apply(saved);
        if (toggleBtn) {
            toggleBtn.addEventListener("click", () => {
                const next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
                localStorage.setItem("theme", next);
                apply(next);
            });
        }
    }

    return Object.freeze({
        icon, hydrateIcons, el, select, menu, swatchPicker, toast, toastAction, openModal, confirmDialog,
        relativeDate, timeAgo, initials, initTheme, autoGrow, charCounter, normalizeText, highlight, searchBar,
        trapFocus, announce,
    });
})();

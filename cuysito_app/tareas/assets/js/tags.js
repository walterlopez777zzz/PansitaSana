"use strict";

/**
 * ============================================================
 * Tareas — Etiquetas (chips, selector y gestor)
 * ============================================================
 * Funciones de presentación y controles accesibles para las
 * etiquetas (tags): el chip de sólo lectura que se ve en las
 * tarjetas, el combobox de selección múltiple para asignar
 * etiquetas a ideas/misiones, la barra de filtro y el gestor
 * CRUD. Expone el objeto global `TareasTags`.
 *
 * El CRUD vive en la API; este módulo no toca la base salvo a
 * través de `TareasAPI`. Los controles son agnósticos del estado
 * de la app: reciben los datos y emiten callbacks.
 * ============================================================
 */

const TareasTags = (() => {
    const { el, icon, toast, confirmDialog, openModal, normalizeText } = TareasUI;

    // Paleta de acentos compartida con ideas/misiones (tokens → variables CSS).
    const ACCENT_VARS = {
        violet: "var(--acc-violet)", blue: "var(--acc-blue)", green: "var(--acc-green)",
        amber: "var(--acc-amber)", rose: "var(--acc-rose)", cyan: "var(--acc-cyan)",
    };
    const ACCENT_OPTIONS = Object.keys(ACCENT_VARS).map((name) => ({ value: name, color: ACCENT_VARS[name], label: name }));

    /** Resuelve el color visible de una etiqueta: token de acento o hex. */
    const colorOf = (tag) => ACCENT_VARS[tag.color] || (/^#[0-9a-f]{6}$/i.test(tag.color || "") ? tag.color : ACCENT_VARS.violet);

    /**
     * Chip de etiqueta. Por defecto es decorativo (`<span>`); con `onClick`
     * pasa a ser un botón (filtrar por la etiqueta) y con `removable` agrega
     * un botón "quitar" (combobox de selección).
     */
    function chip(tag, { removable = false, onRemove = null, onClick = null } = {}) {
        const interactive = typeof onClick === "function";
        const node = el(interactive ? "button" : "span", {
            class: `tk-tag${interactive ? " tk-tag--btn" : ""}`,
            type: interactive ? "button" : undefined,
            style: { "--tag-color": colorOf(tag) },
            title: interactive ? `Filtrar por ${tag.name}` : tag.name,
            "aria-label": interactive ? `Filtrar por etiqueta ${tag.name}` : undefined,
            onClick: interactive ? (e) => { e.stopPropagation(); onClick(tag); } : undefined,
        }, [
            el("span", { class: "tk-tag-dot", "aria-hidden": "true" }),
            el("span", { class: "tk-tag-name", text: tag.name }),
        ]);
        if (removable) {
            node.appendChild(el("button", {
                class: "tk-tag-remove", type: "button",
                "aria-label": `Quitar etiqueta ${tag.name}`,
                onClick: (e) => { e.stopPropagation(); if (onRemove) onRemove(tag); },
            }, [icon("close", 12)]));
        }
        return node;
    }

    /** Lista de chips para una tarjeta (o null si no hay). `onClick` la hace filtrable. */
    function chipList(tags, { onClick = null } = {}) {
        if (!tags || !tags.length) return null;
        return el("div", { class: "tk-tags", role: "list", "aria-label": "Etiquetas" },
            tags.map((t) => { const c = chip(t, { onClick }); c.setAttribute("role", "listitem"); return c; }));
    }

    // ── Combobox de selección múltiple (token input accesible) ───
    /**
     * Selector de etiquetas con patrón combobox + listbox (WAI-ARIA). Las
     * etiquetas elegidas se muestran como chips removibles; el input filtra
     * las disponibles y, si se provee `onCreate`, permite crear una nueva al
     * vuelo. La raíz expone `value` (array de ids numéricos) y `focus()`.
     *
     * @param {{ tags?: {id:number,name:string,color:string}[],
     *           selected?: number[], ariaLabel?: string,
     *           onCreate?: (name:string) => Promise<{id:number,name:string,color:string}> }} cfg
     */
    function picker(cfg = {}) {
        const available = [...(cfg.tags || [])];
        const selectedIds = [...new Set((cfg.selected || []).map(Number))];
        const onCreate = typeof cfg.onCreate === "function" ? cfg.onCreate : null;
        const uid = `tk-tagbox-${Math.random().toString(36).slice(2, 8)}`;

        const tagById = (id) => available.find((t) => Number(t.id) === Number(id)) || null;

        const chipsWrap = el("div", { class: "tk-tagbox-chips" });
        const input = el("input", {
            type: "text", class: "tk-tagbox-input", role: "combobox",
            "aria-expanded": "false", "aria-controls": uid, "aria-autocomplete": "list",
            "aria-label": cfg.ariaLabel || "Etiquetas", autocomplete: "off", spellcheck: "false",
            placeholder: "Buscar o crear…",
        });
        const field = el("div", { class: "tk-tagbox-field" }, [chipsWrap, input]);
        const root = el("div", { class: "tk-tagbox" }, [field]);

        const list = el("ul", { class: "tk-tagbox-list", id: uid, role: "listbox", tabindex: "-1" });

        let open = false;
        let activeIndex = -1;
        let optionEls = []; // [{ node, kind:"tag"|"create", tag?, name? }]

        // ── Suscripción de cambios (para refrescar el `.value` externo) ──
        function chosenTags() { return selectedIds.map(tagById).filter(Boolean); }

        function renderChips() {
            chipsWrap.replaceChildren(...chosenTags().map((t) =>
                chip(t, { removable: true, onRemove: (tag) => removeTag(tag.id) })));
        }

        function removeTag(id) {
            const i = selectedIds.indexOf(Number(id));
            if (i >= 0) selectedIds.splice(i, 1);
            renderChips();
            input.focus();
            if (open) refreshOptions();
        }

        function addTag(tag) {
            if (!selectedIds.includes(Number(tag.id))) selectedIds.push(Number(tag.id));
            input.value = "";
            renderChips();
            refreshOptions();
            input.focus();
        }

        async function createAndAdd(name) {
            if (!onCreate) return;
            try {
                const tag = await onCreate(name);
                if (!tag) return;
                if (!tagById(tag.id)) available.push(tag);
                addTag(tag);
            } catch (err) {
                toast(err.message || "No se pudo crear la etiqueta.", "error");
            }
        }

        function matches() {
            const q = normalizeText(input.value.trim());
            return available
                .filter((t) => !selectedIds.includes(Number(t.id)))
                .filter((t) => q === "" || normalizeText(t.name).includes(q));
        }

        /** ¿El texto tipeado coincide exactamente (case/acentos) con una etiqueta? */
        function exactExists(name) {
            const n = normalizeText(name);
            return available.some((t) => normalizeText(t.name) === n);
        }

        function refreshOptions() {
            const q = input.value.trim();
            const found = matches();
            optionEls = [];
            list.replaceChildren();

            found.forEach((t, i) => {
                const li = el("li", { class: "tk-tagbox-option", id: `${uid}-opt-${i}`, role: "option", "aria-selected": "false" }, [
                    el("span", { class: "tk-tag-dot", "aria-hidden": "true", style: { "--tag-color": colorOf(t) } }),
                    el("span", { text: t.name }),
                ]);
                li.addEventListener("mousedown", (e) => { e.preventDefault(); addTag(t); });
                li.addEventListener("mousemove", () => setActive(optionEls.findIndex((o) => o.node === li)));
                list.appendChild(li);
                optionEls.push({ node: li, kind: "tag", tag: t });
            });

            if (onCreate && q !== "" && !exactExists(q)) {
                const li = el("li", { class: "tk-tagbox-option tk-tagbox-option--create", id: `${uid}-opt-create`, role: "option", "aria-selected": "false" }, [
                    icon("plus", 14), el("span", { text: `Crear «${q}»` }),
                ]);
                li.addEventListener("mousedown", (e) => { e.preventDefault(); createAndAdd(q); });
                li.addEventListener("mousemove", () => setActive(optionEls.findIndex((o) => o.node === li)));
                list.appendChild(li);
                optionEls.push({ node: li, kind: "create", name: q });
            }

            if (!optionEls.length) {
                list.appendChild(el("li", { class: "tk-tagbox-empty", role: "presentation", text: q ? "Sin coincidencias." : "No hay más etiquetas." }));
            }

            activeIndex = optionEls.length ? 0 : -1;
            paintActive();
            if (open) position();
        }

        function setActive(i) {
            if (i < 0 || i >= optionEls.length) return;
            activeIndex = i;
            paintActive();
            optionEls[activeIndex].node.scrollIntoView({ block: "nearest" });
        }

        function paintActive() {
            optionEls.forEach((o, idx) => o.node.classList.toggle("is-active", idx === activeIndex));
            const active = optionEls[activeIndex];
            if (active) input.setAttribute("aria-activedescendant", active.node.id);
            else input.removeAttribute("aria-activedescendant");
        }

        function position() {
            const r = field.getBoundingClientRect();
            list.style.minWidth = `${r.width}px`;
            list.style.left = `${r.left}px`;
            const h = list.offsetHeight;
            const below = window.innerHeight - r.bottom;
            const gap = 6;
            if (below < h + gap && r.top > below) list.style.top = `${Math.max(gap, r.top - h - gap)}px`;
            else list.style.top = `${r.bottom + gap}px`;
        }

        function openList() {
            if (open) return;
            open = true;
            document.body.appendChild(list);
            root.classList.add("is-open");
            input.setAttribute("aria-expanded", "true");
            refreshOptions();
            position();
            document.addEventListener("pointerdown", onDocPointer, true);
            window.addEventListener("resize", onReflow, true);
            window.addEventListener("scroll", onReflow, true);
        }

        function closeList() {
            if (!open) return;
            open = false;
            root.classList.remove("is-open");
            input.setAttribute("aria-expanded", "false");
            input.removeAttribute("aria-activedescendant");
            document.removeEventListener("pointerdown", onDocPointer, true);
            window.removeEventListener("resize", onReflow, true);
            window.removeEventListener("scroll", onReflow, true);
            if (list.parentNode) list.parentNode.removeChild(list);
        }

        const onReflow = () => { if (open) position(); };
        const onDocPointer = (e) => { if (!root.contains(e.target) && !list.contains(e.target)) closeList(); };

        function confirmActive() {
            const active = optionEls[activeIndex];
            if (!active) return;
            if (active.kind === "create") createAndAdd(active.name);
            else addTag(active.tag);
        }

        input.addEventListener("focus", openList);
        input.addEventListener("click", openList);
        input.addEventListener("input", () => { if (!open) openList(); else refreshOptions(); });
        input.addEventListener("keydown", (e) => {
            switch (e.key) {
                case "ArrowDown": e.preventDefault(); if (!open) openList(); else setActive((activeIndex + 1) % optionEls.length); break;
                case "ArrowUp": e.preventDefault(); if (open && optionEls.length) setActive((activeIndex - 1 + optionEls.length) % optionEls.length); break;
                case "Enter": if (open && activeIndex >= 0) { e.preventDefault(); confirmActive(); } break;
                case "Escape": if (open) { e.preventDefault(); e.stopPropagation(); closeList(); } break;
                case "Backspace": if (input.value === "" && selectedIds.length) { e.preventDefault(); removeTag(selectedIds[selectedIds.length - 1]); } break;
                case "Tab": closeList(); break;
            }
        });
        // Click en el marco (no en un chip/botón) enfoca el input.
        field.addEventListener("mousedown", (e) => { if (e.target === field || e.target === chipsWrap) { e.preventDefault(); input.focus(); } });

        renderChips();
        Object.defineProperty(root, "value", { get: () => [...selectedIds], configurable: true });
        root.focus = () => input.focus();
        return root;
    }

    // ── Barra de filtro por etiqueta ─────────────────────────
    /**
     * Fila de chips conmutables para filtrar por etiqueta. `selected` es el
     * conjunto activo de ids; `onToggle(id)` y `onClear()` notifican cambios.
     * Devuelve null si no hay etiquetas (nada que filtrar).
     */
    function filterBar({ tags = [], selected = [], onToggle, onClear } = {}) {
        if (!tags.length) return null;
        const sel = new Set(selected.map(Number));
        const row = el("div", { class: "tk-tag-filter", role: "group", "aria-label": "Filtrar por etiqueta" });
        row.appendChild(el("span", { class: "tk-tag-filter-icon", "aria-hidden": "true" }, [icon("tag", 14)]));
        tags.forEach((t) => {
            const on = sel.has(Number(t.id));
            const btn = el("button", {
                class: `tk-tag-chip${on ? " is-active" : ""}`, type: "button",
                style: { "--tag-color": colorOf(t) },
                "aria-pressed": String(on),
                onClick: () => { if (onToggle) onToggle(Number(t.id)); },
            }, [el("span", { class: "tk-tag-dot", "aria-hidden": "true" }), el("span", { text: t.name })]);
            row.appendChild(btn);
        });
        if (sel.size) {
            row.appendChild(el("button", {
                class: "tk-tag-filter-clear", type: "button", text: "Limpiar",
                onClick: () => { if (onClear) onClear(); },
            }));
        }
        return row;
    }

    // ── Gestor CRUD ──────────────────────────────────────────
    function panel(node) {
        const backdrop = el("div", { class: "tk-modal-backdrop" });
        const returnFocus = document.activeElement;
        let releaseTrap = null;
        const close = () => {
            backdrop.classList.remove("is-open");
            backdrop.classList.add("is-closing");
            document.removeEventListener("keydown", onKey, true);
            if (releaseTrap) releaseTrap();
            setTimeout(() => backdrop.remove(), 200);
            if (returnFocus && returnFocus.focus) returnFocus.focus();
        };
        const onKey = (e) => { if (e.key === "Escape") { e.stopPropagation(); close(); } };
        backdrop.appendChild(node);
        backdrop.addEventListener("mousedown", (e) => { if (e.target === backdrop) close(); });
        document.body.appendChild(backdrop);
        document.addEventListener("keydown", onKey, true);
        releaseTrap = TareasUI.trapFocus(node);
        requestAnimationFrame(() => backdrop.classList.add("is-open"));
        return { backdrop, close };
    }

    /**
     * Gestor de etiquetas: alta, edición (nombre + color) y borrado (con aviso
     * de uso). `getTags()` devuelve la lista vigente; `reload()` resincroniza
     * tras cada cambio para reflejar conteos y asociaciones.
     */
    async function openManager(getTags, reload) {
        const listWrap = el("div", { class: "tk-tag-manager-list" });

        const renderList = () => {
            const tags = getTags();
            listWrap.replaceChildren();
            if (!tags.length) {
                listWrap.appendChild(el("p", { class: "tk-muted-note", text: "Todavía no hay etiquetas. Crea la primera abajo." }));
                return;
            }
            tags.forEach((t) => {
                const usage = Number(t.usage || 0);
                listWrap.appendChild(el("div", { class: "tk-tag-manager-row" }, [
                    chip(t),
                    el("span", { class: "tk-tag-usage", text: usage ? `${usage} uso${usage === 1 ? "" : "s"}` : "sin uso" }),
                    el("button", {
                        class: "tk-btn tk-btn--ghost tk-btn--sm", title: "Editar etiqueta", "aria-label": `Editar etiqueta ${t.name}`,
                        onClick: () => editTag(t),
                    }, [icon("edit", 15)]),
                    el("button", {
                        class: "tk-btn tk-btn--ghost tk-btn--sm tk-btn--danger", title: "Eliminar etiqueta", "aria-label": `Eliminar etiqueta ${t.name}`,
                        onClick: () => deleteTag(t),
                    }, [icon("trash", 15)]),
                ]));
            });
        };

        const editTag = async (tag) => {
            await openModal({
                title: "Editar etiqueta",
                fields: [
                    { name: "name", label: "Nombre", value: tag.name, maxlength: 40 },
                    { name: "color", label: "Color", type: "swatch", value: tag.color, options: ACCENT_OPTIONS },
                ],
                submitLabel: "Guardar",
                onSubmit: async (v) => {
                    if (!v.name.trim()) { toast("El nombre es obligatorio.", "error"); return false; }
                    await TareasAPI.tags.update({ id: tag.id, name: v.name.trim(), color: v.color });
                    await reload();
                    renderList();
                    toast("Etiqueta actualizada.", "success");
                },
            });
        };

        const deleteTag = async (tag) => {
            const usage = Number(tag.usage || 0);
            const warn = usage
                ? `¿Eliminar la etiqueta "${tag.name}"? Se quitará de ${usage} elemento${usage === 1 ? "" : "s"}.`
                : `¿Eliminar la etiqueta "${tag.name}"?`;
            if (!(await confirmDialog(warn, { danger: true, confirmLabel: "Eliminar" }))) return;
            try {
                await TareasAPI.tags.delete(tag.id);
                await reload();
                renderList();
                toast("Etiqueta eliminada.", "success");
            } catch (err) { toast(err.message, "error"); }
        };

        const nameInput = el("input", { type: "text", maxlength: "40", placeholder: "Nombre de la etiqueta", "aria-labelledby": "tk-tag-new-label" });
        const colorPicker = TareasUI.swatchPicker({ value: "violet", options: ACCENT_OPTIONS, ariaLabel: "Color de la etiqueta" });
        const addBtn = el("button", { class: "tk-btn tk-btn--primary", type: "button", text: "Agregar" });

        const create = async () => {
            const name = nameInput.value.trim();
            if (!name) { nameInput.focus(); return; }
            addBtn.disabled = true;
            try {
                await TareasAPI.tags.create({ name, color: colorPicker.value });
                await reload();
                nameInput.value = "";
                renderList();
                nameInput.focus();
                toast("Etiqueta creada.", "success");
            } catch (err) { toast(err.message, "error"); }
            finally { addBtn.disabled = false; }
        };
        addBtn.addEventListener("click", create);
        nameInput.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); create(); } });

        const node = el("div", { class: "tk-modal", role: "dialog", "aria-modal": "true", "aria-labelledby": "tk-tag-manager-title" }, [
            el("h2", { id: "tk-tag-manager-title", text: "Etiquetas" }),
            listWrap,
            el("div", { class: "tk-field" }, [el("label", { id: "tk-tag-new-label", text: "Nueva etiqueta" }), nameInput]),
            el("div", { class: "tk-field" }, [el("label", { text: "Color" }), colorPicker]),
            el("div", { class: "tk-modal-actions" }, [
                el("button", { class: "tk-btn tk-btn--ghost", type: "button", text: "Cerrar", onClick: () => ctrl.close() }),
                addBtn,
            ]),
        ]);

        const ctrl = panel(node);
        renderList();
        requestAnimationFrame(() => nameInput.focus());
    }

    return Object.freeze({ chip, chipList, picker, filterBar, openManager, colorOf, ACCENT_OPTIONS });
})();

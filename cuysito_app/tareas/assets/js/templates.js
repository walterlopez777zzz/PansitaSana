"use strict";

/**
 * ============================================================
 * Tareas — Plantillas de misión (gestor + instanciado)
 * ============================================================
 * Expone en la UI la feature de plantillas del backend: crear,
 * editar y borrar plantillas (datos base + checklist), y crear
 * una misión a partir de una plantilla.
 *
 * Las plantillas no forman parte de la carga inicial de la app
 * (no se necesitan para Ideas/Misiones): el gestor administra su
 * propia lista de forma perezosa para no inflar `loadAll`. Toda
 * la base se toca sólo a través de `TareasAPI`.
 * Expone el objeto global `TareasTemplates`.
 * ============================================================
 */

const TareasTemplates = (() => {
    const { el, icon, toast, confirmDialog, openModal, swatchPicker } = TareasUI;

    const TPL_MAX_ITEMS = 100;

    const ACCENT_VARS = {
        violet: "var(--acc-violet)", blue: "var(--acc-blue)", green: "var(--acc-green)",
        amber: "var(--acc-amber)", rose: "var(--acc-rose)", cyan: "var(--acc-cyan)",
    };
    const ACCENT_OPTIONS = Object.keys(ACCENT_VARS).map((name) => ({ value: name, color: ACCENT_VARS[name], label: name }));
    const PRIORITY_OPTIONS = [
        { value: "alta", label: "Alta" }, { value: "media", label: "Media" }, { value: "baja", label: "Baja" },
    ];

    /**
     * Backdrop + panel accesible (focus-trap, Escape y restitución del foco),
     * mismo contrato que los demás gestores del módulo.
     */
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
     * Editor de ítems de checklist para el modal de plantilla. Es un control
     * "custom" que expone `value` (array de strings) para integrarse con
     * `openModal`. Cada fila se puede reordenar implícitamente por su orden de
     * aparición; Enter en una fila agrega la siguiente.
     */
    function itemsEditor(initialItems = []) {
        const rows = el("div", { class: "tk-tpl-items" });
        const root = el("div", { class: "tk-tpl-items-editor" }, [rows]);

        const addBtn = el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm", type: "button" },
            [icon("plus", 14), el("span", { text: "Agregar paso" })]);

        const makeRow = (content = "") => {
            const input = el("input", {
                type: "text", maxlength: "255", class: "tk-tpl-item-input",
                placeholder: "Paso de la checklist…", "aria-label": "Paso de la plantilla",
            });
            input.value = content;
            const del = el("button", {
                class: "tk-btn tk-btn--ghost tk-btn--sm tk-btn--danger", type: "button",
                title: "Quitar paso", "aria-label": "Quitar paso",
                onClick: () => { row.remove(); if (!rows.children.length) addRow(); },
            }, [icon("trash", 14)]);
            const row = el("div", { class: "tk-tpl-item-row" }, [input, del]);
            input.addEventListener("keydown", (e) => {
                if (e.key === "Enter") {
                    e.preventDefault();
                    if (rows.children.length < TPL_MAX_ITEMS) addRow().focus();
                }
            });
            row.focusInput = () => input.focus();
            return row;
        };

        function addRow(content = "") {
            const row = makeRow(content);
            rows.appendChild(row);
            return { focus: () => row.focusInput() };
        }

        (initialItems.length ? initialItems : [""]).forEach((c) =>
            addRow(typeof c === "string" ? c : (c.content || "")));

        addBtn.addEventListener("click", () => { if (rows.children.length < TPL_MAX_ITEMS) addRow().focus(); });
        root.appendChild(addBtn);

        Object.defineProperty(root, "value", {
            get: () => Array.from(rows.querySelectorAll(".tk-tpl-item-input"))
                .map((i) => i.value.trim()).filter(Boolean),
            configurable: true,
        });
        return root;
    }

    /** Modal de alta/edición de plantilla. Resuelve cuando se cierra. */
    async function openEditor(template, onSaved) {
        const isEdit = !!template;
        await openModal({
            title: isEdit ? "Editar plantilla" : "Nueva plantilla",
            submitLabel: isEdit ? "Guardar" : "Crear",
            fields: [
                { name: "name", label: "Nombre de la plantilla", maxlength: 80, value: template ? template.name : "", placeholder: "Ej.: Onboarding de cliente" },
                { name: "title", label: "Título de la misión (opcional)", maxlength: 160, value: template ? (template.title || "") : "" },
                { name: "description", label: "Descripción (opcional)", type: "textarea", maxlength: 4000, value: template ? (template.description || "") : "" },
                { name: "priority", label: "Prioridad", type: "select", value: template ? template.priority : "media", options: PRIORITY_OPTIONS },
                { name: "accent", label: "Color", type: "swatch", value: template ? (template.accent || "violet") : "violet", options: ACCENT_OPTIONS },
                { name: "items", label: "Checklist base", type: "custom", control: itemsEditor(template ? template.items : []) },
            ],
            onSubmit: async (v) => {
                if (!v.name.trim()) { toast("El nombre de la plantilla es obligatorio.", "error"); return false; }
                const payload = {
                    name: v.name.trim(), title: v.title, description: v.description,
                    priority: v.priority, accent: v.accent, items: v.items,
                };
                if (isEdit) await TareasAPI.templates.update({ id: template.id, ...payload });
                else await TareasAPI.templates.create(payload);
                toast(isEdit ? "Plantilla actualizada." : "Plantilla creada.", "success");
                if (onSaved) await onSaved();
            },
        });
    }

    /**
     * Modal para crear una misión desde una plantilla: permite ajustar el
     * título y un vencimiento opcional. `onCreated(misionId)` se invoca al
     * crear para que el orquestador refresque y muestre la nueva misión.
     */
    async function openInstantiate(template, onCreated) {
        await openModal({
            title: `Usar plantilla · ${template.name}`,
            submitLabel: "Crear misión",
            fields: [
                { name: "title", label: "Título de la misión", maxlength: 160, value: template.title || template.name },
                { name: "due_date", label: "Vencimiento (opcional)", type: "date", value: "" },
            ],
            onSubmit: async (v) => {
                if (!v.title.trim()) { toast("El título es obligatorio.", "error"); return false; }
                const res = await TareasAPI.templates.instantiate({ id: template.id, title: v.title.trim(), due_date: v.due_date });
                toast("Misión creada desde plantilla.", "success");
                if (onCreated) await onCreated(res.mision_id || null);
            },
        });
    }

    /**
     * Gestor de plantillas: lista con conteo de pasos y acciones (usar,
     * editar, eliminar) + alta. Administra su propia lista de forma perezosa.
     *
     * @param {{ onMisionCreated?: (misionId:number|null) => void }} [opts]
     */
    async function openManager({ onMisionCreated } = {}) {
        let templates = [];

        const listWrap = el("div", { class: "tk-tpl-list" });

        const reload = async () => {
            templates = (await TareasAPI.templates.list()).templates || [];
            renderList();
        };

        const deleteTemplate = async (tpl) => {
            if (!(await confirmDialog(`¿Eliminar la plantilla "${tpl.name}"?`, { danger: true, confirmLabel: "Eliminar" }))) return;
            try {
                await TareasAPI.templates.delete(tpl.id);
                await reload();
                toast("Plantilla eliminada.", "success");
            } catch (err) { toast(err.message || "No se pudo eliminar.", "error"); }
        };

        const templateRow = (tpl) => {
            const count = (tpl.items || []).length;
            const useBtn = el("button", {
                class: "tk-btn tk-btn--sm", type: "button", title: "Crear misión desde esta plantilla",
                "aria-label": `Usar plantilla ${tpl.name}`,
                onClick: () => openInstantiate(tpl, async (misionId) => {
                    ctrl.close();
                    if (onMisionCreated) onMisionCreated(misionId);
                }),
            }, [icon("plus", 14), el("span", { text: "Usar" })]);
            const editBtn = el("button", {
                class: "tk-btn tk-btn--ghost tk-btn--sm", title: "Editar plantilla",
                "aria-label": `Editar plantilla ${tpl.name}`,
                onClick: () => openEditor(tpl, reload),
            }, [icon("edit", 15)]);
            const delBtn = el("button", {
                class: "tk-btn tk-btn--ghost tk-btn--sm tk-btn--danger", title: "Eliminar plantilla",
                "aria-label": `Eliminar plantilla ${tpl.name}`,
                onClick: () => deleteTemplate(tpl),
            }, [icon("trash", 15)]);

            return el("div", { class: "tk-tpl-row" }, [
                el("div", { class: "tk-tpl-info" }, [
                    el("span", { class: "tk-tpl-name", text: tpl.name }),
                    el("span", { class: "tk-tpl-count", text: count ? `${count} paso${count === 1 ? "" : "s"}` : "sin checklist" }),
                ]),
                el("div", { class: "tk-tpl-actions" }, [useBtn, editBtn, delBtn]),
            ]);
        };

        const renderList = () => {
            listWrap.replaceChildren();
            if (!templates.length) {
                listWrap.appendChild(el("p", { class: "tk-muted-note", text: "Todavía no hay plantillas. Crea la primera abajo." }));
                return;
            }
            templates.forEach((tpl) => listWrap.appendChild(templateRow(tpl)));
        };

        const newBtn = el("button", { class: "tk-btn tk-btn--primary", type: "button" },
            [icon("plus", 16), el("span", { text: "Nueva plantilla" })]);
        newBtn.addEventListener("click", () => openEditor(null, reload));

        const node = el("div", { class: "tk-modal", role: "dialog", "aria-modal": "true", "aria-labelledby": "tk-tpl-title" }, [
            el("h2", { id: "tk-tpl-title", text: "Plantillas" }),
            el("p", { class: "tk-muted-note", text: "Reutiliza estructuras de misión recurrentes: define los datos base y una checklist, y crea misiones con un solo clic." }),
            listWrap,
            el("div", { class: "tk-modal-actions" }, [
                el("button", { class: "tk-btn tk-btn--ghost", type: "button", text: "Cerrar", onClick: () => ctrl.close() }),
                newBtn,
            ]),
        ]);

        const ctrl = panel(node);
        listWrap.replaceChildren(el("p", { class: "tk-muted-note", text: "Cargando plantillas…" }));
        try {
            await reload();
        } catch (err) {
            listWrap.replaceChildren(el("p", { class: "tk-detail-note is-error", role: "alert", text: err.message || "No se pudieron cargar las plantillas." }));
        }
    }

    return Object.freeze({ openManager, openInstantiate, openEditor });
})();

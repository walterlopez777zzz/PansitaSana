"use strict";

/**
 * ============================================================
 * Tareas — Colaboradores (gestor + selector de asignación)
 * ============================================================
 * Expone el objeto global `TareasColaboradores`.
 * ============================================================
 */

const TareasColaboradores = (() => {
    const { el, icon, initials, toast, confirmDialog } = TareasUI;

    const PALETTE = ["#6c63ff", "#4d9dff", "#3ecf8e", "#f5a524", "#ff6b9d", "#2dd4d4", "#ff5c5c", "#a855f7"];

    /** Construye un backdrop + panel y devuelve { backdrop, close }. */
    function panel(node) {
        const backdrop = el("div", { class: "tk-modal-backdrop" });
        const close = () => {
            backdrop.classList.remove("is-open");
            backdrop.classList.add("is-closing");
            setTimeout(() => backdrop.remove(), 200);
        };
        backdrop.appendChild(node);
        backdrop.addEventListener("click", (e) => { if (e.target === backdrop) close(); });
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add("is-open"));
        return { backdrop, close };
    }

    /** Gestor de colaboradores: alta, edición de color, archivado. */
    async function openManager(getColaboradores, reload) {
        const listWrap = el("div", { style: { display: "flex", flexDirection: "column", gap: ".4rem", marginBottom: "1rem" } });

        const renderList = () => {
            listWrap.replaceChildren();
            const cols = getColaboradores();
            if (!cols.length) {
                listWrap.appendChild(el("p", { text: "Todavía no hay colaboradores.", style: { color: "var(--tk-text-muted)", fontSize: ".88rem", margin: "0" } }));
                return;
            }
            cols.forEach((c) => {
                listWrap.appendChild(el("div", { style: { display: "flex", alignItems: "center", gap: ".6rem" } }, [
                    el("span", { class: "tk-avatar", style: { background: c.color }, text: initials(c.name) }),
                    el("span", { text: c.name, style: { flex: "1" } }),
                    el("button", {
                        class: "tk-btn tk-btn--ghost tk-btn--sm tk-btn--danger", title: "Archivar", "aria-label": "Archivar",
                        onClick: async () => {
                            if (!(await confirmDialog(`¿Archivar a "${c.name}"? Sus misiones quedarán sin asignar.`, { danger: true, confirmLabel: "Archivar" }))) return;
                            try { await TareasAPI.colaboradores.archive(c.id); await reload(); renderList(); toast("Colaborador archivado.", "success"); }
                            catch (err) { toast(err.message, "error"); }
                        },
                    }, [icon("trash", 15)]),
                ]));
            });
        };

        const nameInput = el("input", { type: "text", maxlength: "60", placeholder: "Nombre del colaborador", "aria-labelledby": "tk-colab-name-label" });
        let selectedColor = PALETTE[0];
        const swatches = el("div", { class: "tk-swatches", style: { margin: ".25rem 0" } },
            PALETTE.map((color) => {
                const s = el("button", { class: "tk-swatch", type: "button", style: { background: color }, "aria-label": color });
                if (color === selectedColor) s.classList.add("is-selected");
                s.addEventListener("click", () => {
                    selectedColor = color;
                    swatches.querySelectorAll(".tk-swatch").forEach((x) => x.classList.remove("is-selected"));
                    s.classList.add("is-selected");
                });
                return s;
            }));

        const addBtn = el("button", { class: "tk-btn tk-btn--primary", type: "button", text: "Agregar" });

        const node = el("div", { class: "tk-modal", role: "dialog", "aria-modal": "true" }, [
            el("h2", { text: "Colaboradores" }),
            listWrap,
            el("div", { class: "tk-field" }, [el("label", { id: "tk-colab-name-label", text: "Nuevo colaborador" }), nameInput]),
            swatches,
            el("div", { class: "tk-modal-actions" }, [
                el("button", { class: "tk-btn tk-btn--ghost", type: "button", text: "Cerrar", onClick: () => ctrl.close() }),
                addBtn,
            ]),
        ]);

        const ctrl = panel(node);

        addBtn.addEventListener("click", async () => {
            const name = nameInput.value.trim();
            if (!name) { nameInput.focus(); return; }
            addBtn.disabled = true;
            try {
                await TareasAPI.colaboradores.create({ name, color: selectedColor });
                await reload();
                nameInput.value = "";
                renderList();
                toast("Colaborador agregado.", "success");
            } catch (err) { toast(err.message, "error"); }
            finally { addBtn.disabled = false; }
        });

        renderList();
        requestAnimationFrame(() => nameInput.focus());
    }

    /** Selector de asignación. Resuelve con { id } (0 = desasignar) o null (cancelar). */
    function openPicker(colaboradores, currentId) {
        return new Promise((resolve) => {
            const options = el("div", { style: { display: "flex", flexDirection: "column", gap: ".35rem", marginBottom: "1rem" } });

            const pick = (id) => { ctrl.close(); resolve({ id }); };

            const optionRow = (label, id, color) => {
                const active = String(id) === String(currentId || 0);
                const row = el("button", {
                    class: `tk-btn${active ? " tk-btn--primary" : ""}`,
                    type: "button",
                    style: { justifyContent: "flex-start", width: "100%" },
                    onClick: () => pick(id),
                }, [
                    color ? el("span", { class: "tk-avatar", style: { background: color }, text: initials(label) }) : icon("users", 16),
                    el("span", { text: label }),
                ]);
                return row;
            };

            options.appendChild(optionRow("Sin asignar", 0, null));
            colaboradores.forEach((c) => options.appendChild(optionRow(c.name, c.id, c.color)));

            const node = el("div", { class: "tk-modal", role: "dialog", "aria-modal": "true" }, [
                el("h2", { text: "Asignar misión" }),
                options,
                el("div", { class: "tk-modal-actions" }, [
                    el("button", { class: "tk-btn tk-btn--ghost", type: "button", text: "Cancelar", onClick: () => { ctrl.close(); resolve(null); } }),
                ]),
            ]);

            const ctrl = panel(node);
        });
    }

    return Object.freeze({ openManager, openPicker, PALETTE });
})();

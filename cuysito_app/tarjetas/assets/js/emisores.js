"use strict";

/**
 * ============================================================
 * Tarjetas — Emisores (billeteras) y colores por defecto
 * ============================================================
 * Catálogo configurable de emisores (AstroPay, Belo, Lemon, ...)
 * con un color por defecto cada uno. Se administran desde un
 * panel propio y alimentan el selector del formulario de
 * perfiles, que hereda el color automáticamente.
 * Expone el objeto global `TarjetasEmisores`.
 * ============================================================
 */

const TarjetasEmisores = (() => {
    const { el, icon, toast } = TarjetasUI;

    let emisores = [];

    const list = () => emisores;
    const byId = (id) => emisores.find((e) => Number(e.id) === Number(id)) || null;

    async function load() {
        const res = await TarjetasAPI.emisores.list();
        emisores = res.emisores || [];
    }

    // ── Panel de configuración ───────────────────────────────
    function openPanel() {
        const overlay = el("div", { class: "tj-modal-overlay", role: "dialog", "aria-modal": "true", "aria-label": "Emisores" });
        const listMount = el("div", { class: "tj-emisores-list" });

        const nameInput  = el("input", { class: "tj-input", type: "text", maxlength: "80", placeholder: "Nombre (ej. AstroPay)", required: "" });
        const colorInput = el("input", { class: "tj-color", type: "color", value: "#6c63ff", title: "Color por defecto" });
        const addBtn     = el("button", { class: "tj-btn tj-btn--primary", type: "submit" }, [icon("plus", 14), "Agregar"]);

        const form = el("form", { class: "tj-emisores-form" }, [nameInput, colorInput, addBtn]);
        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            const nombre = nameInput.value.trim();
            if (!nombre) { nameInput.focus(); return; }
            try {
                await TarjetasAPI.emisores.create({ nombre, color: colorInput.value });
                await load();
                nameInput.value = "";
                renderList();
                toast("Emisor agregado", "success");
                document.dispatchEvent(new CustomEvent("tarjetas:emisores-changed"));
            } catch (err) {
                toast(err.message, "error");
            }
        });

        function renderList() {
            listMount.textContent = "";
            if (!emisores.length) {
                listMount.appendChild(el("p", { class: "tj-empty-hint", text: "Sin emisores todavía. Agrega el primero arriba." }));
                return;
            }
            emisores.forEach((em) => {
                const nombre = el("input", { class: "tj-input tj-input--inline", type: "text", value: em.nombre, maxlength: "80" });
                const color  = el("input", { class: "tj-color", type: "color", value: em.color });

                const save = el("button", { class: "tj-icon-btn tj-icon-btn--sm", type: "button", title: "Guardar cambios", "aria-label": `Guardar ${em.nombre}` }, [icon("check", 15)]);
                save.addEventListener("click", async () => {
                    try {
                        await TarjetasAPI.emisores.update({ id: em.id, nombre: nombre.value.trim(), color: color.value });
                        await load();
                        renderList();
                        toast("Emisor actualizado", "success");
                        document.dispatchEvent(new CustomEvent("tarjetas:emisores-changed"));
                    } catch (err) {
                        toast(err.message, "error");
                    }
                });

                const del = el("button", { class: "tj-icon-btn tj-icon-btn--sm tj-icon-btn--danger", type: "button", title: "Eliminar emisor", "aria-label": `Eliminar ${em.nombre}` }, [icon("trash", 15)]);
                del.addEventListener("click", async () => {
                    if (!confirm(`¿Eliminar el emisor "${em.nombre}"? Los perfiles conservarán su color.`)) return;
                    try {
                        await TarjetasAPI.emisores.delete(em.id);
                        await load();
                        renderList();
                        toast("Emisor eliminado", "success");
                        document.dispatchEvent(new CustomEvent("tarjetas:emisores-changed"));
                    } catch (err) {
                        toast(err.message, "error");
                    }
                });

                listMount.appendChild(el("div", { class: "tj-emisor-row" }, [
                    el("span", { class: "tj-emisor-dot", style: `background:${em.color}` }),
                    nombre, color, save, del,
                ]));
            });
        }

        const closeBtn = el("button", { class: "tj-icon-btn tj-icon-btn--sm", type: "button", "aria-label": "Cerrar" }, [icon("close", 16)]);
        closeBtn.addEventListener("click", () => overlay.remove());

        const modal = el("div", { class: "tj-modal tj-emisores-panel" }, [
            el("div", { class: "tj-modal-head" }, [
                el("h2", { text: "Emisores y colores" }),
                closeBtn,
            ]),
            el("p", { class: "tj-modal-sub", text: "Cada emisor define el color por defecto que heredan sus perfiles y tarjetas (siempre editable)." }),
            form,
            listMount,
        ]);

        overlay.addEventListener("click", (e) => { if (e.target === overlay) overlay.remove(); });
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        renderList();
        nameInput.focus();
    }

    function init() {
        const btn = document.getElementById("tj-emisores-btn");
        if (btn) btn.addEventListener("click", openPanel);
    }

    return Object.freeze({ init, load, list, byId, openPanel });
})();

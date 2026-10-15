"use strict";

/**
 * ============================================================
 * Tarjetas — Panel de Perfiles (cuentas fijas)
 * ============================================================
 * Sidebar de gestión: lista de perfiles con acceso rápido para
 * crear cuentas nuevas (formulario inline en dos bloques:
 * principal obligatorio + complementario opcional plegable),
 * editar, eliminar, filtrar tarjetas y añadir tarjetas al vuelo.
 * Expone el objeto global `TarjetasPerfiles`.
 * ============================================================
 */

const TarjetasPerfiles = (() => {
    const { el, icon, toast } = TarjetasUI;

    const DISPOSITIVOS = ["Celular principal", "Celular secundario", "Tablet", "PC / Notebook", "Emulador"];

    let perfiles = [];
    let selectedId = null;   // null = "todas las tarjetas"
    let formOpen = false;
    let editingId = null;

    const list = () => perfiles;
    const byId = (id) => perfiles.find((p) => Number(p.id) === Number(id)) || null;
    const getSelectedId = () => selectedId;

    async function load() {
        const res = await TarjetasAPI.perfiles.list();
        perfiles = res.perfiles || [];
    }

    function notifyFilter() {
        document.dispatchEvent(new CustomEvent("tarjetas:perfil-selected", { detail: { perfilId: selectedId } }));
    }

    // ── Formulario de perfil (crear / editar) ────────────────
    function buildForm(perfil = null) {
        const isEdit = perfil !== null;

        const emisorSelect = el("select", { class: "tj-input", required: "" }, [
            el("option", { value: "", text: "Emisor…", disabled: "", selected: "" }),
            ...TarjetasEmisores.list().map((em) =>
                el("option", { value: em.id, text: em.nombre, dataset: { color: em.color } })),
        ]);
        if (isEdit && perfil.emisor_id) emisorSelect.value = String(perfil.emisor_id);

        const colorInput = el("input", { class: "tj-color", type: "color", value: (isEdit ? perfil.color : "#6c63ff"), title: "Color del perfil" });

        // Al elegir emisor se hereda su color (editable después).
        emisorSelect.addEventListener("change", () => {
            const em = TarjetasEmisores.byId(emisorSelect.value);
            if (em) colorInput.value = em.color;
        });

        const bancoInput   = el("input", { class: "tj-input", type: "text", maxlength: "120", placeholder: "Banco / entidad", required: "", value: isEdit ? perfil.banco : "" });
        const titularInput = el("input", { class: "tj-input", type: "text", maxlength: "120", placeholder: "Nombre del titular", required: "", value: isEdit ? perfil.titular : "" });
        const cvuInput     = el("input", { class: "tj-input", type: "text", maxlength: "60", placeholder: "CVU / CBU", required: "", value: isEdit ? perfil.cvu : "" });

        // Dispositivo: opciones predefinidas + entrada manual (datalist).
        const dlId = `tj-dispositivos-${Date.now().toString(36)}`;
        const dispositivoInput = el("input", { class: "tj-input", type: "text", maxlength: "120", placeholder: "Dispositivo", required: "", list: dlId, value: isEdit ? perfil.dispositivo : "" });
        const datalist = el("datalist", { id: dlId }, DISPOSITIVOS.map((d) => el("option", { value: d })));

        // Bloque complementario (opcional, plegable).
        const emailInput    = el("input", { class: "tj-input", type: "email", maxlength: "190", placeholder: "Correo de la cuenta (opcional)", value: isEdit ? perfil.email : "" });
        const passInput     = el("input", { class: "tj-input", type: "text", maxlength: "190", placeholder: "Contraseña (opcional)", value: isEdit ? perfil.password : "" });
        const ubicacionInput = el("input", { class: "tj-input", type: "text", maxlength: "190", placeholder: "Ubicación / dirección (opcional)", value: isEdit ? perfil.ubicacion : "" });
        const cuentaInput   = el("input", { class: "tj-input", type: "text", maxlength: "120", placeholder: "N° de cuenta / ID interna (opcional)", value: isEdit ? perfil.cuenta_id : "" });
        const telInput      = el("input", { class: "tj-input", type: "text", maxlength: "60", placeholder: "Celular / teléfono (opcional)", value: isEdit ? perfil.telefono : "" });

        const extra = el("details", { class: "tj-form-extra" }, [
            el("summary", { text: "Datos complementarios (opcional)" }),
            emailInput, passInput, ubicacionInput, cuentaInput, telInput,
        ]);
        if (isEdit && (perfil.email || perfil.password || perfil.ubicacion || perfil.cuenta_id || perfil.telefono)) {
            extra.setAttribute("open", "");
        }

        const submitBtn = el("button", { class: "tj-btn tj-btn--primary tj-btn--block", type: "submit" },
            [icon("check", 15), isEdit ? "Guardar cambios" : "Crear perfil"]);
        const cancelBtn = el("button", { class: "tj-btn tj-btn--block", type: "button", text: "Cancelar" });
        cancelBtn.addEventListener("click", () => { formOpen = false; editingId = null; render(); });

        const form = el("form", { class: "tj-perfil-form" }, [
            el("div", { class: "tj-form-row" }, [emisorSelect, colorInput]),
            bancoInput,
            dispositivoInput, datalist,
            titularInput,
            cvuInput,
            extra,
            el("div", { class: "tj-form-actions" }, [cancelBtn, submitBtn]),
        ]);

        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            const payload = {
                emisor_id:   emisorSelect.value ? Number(emisorSelect.value) : 0,
                banco:       bancoInput.value.trim(),
                dispositivo: dispositivoInput.value.trim(),
                titular:     titularInput.value.trim(),
                cvu:         cvuInput.value.trim(),
                color:       colorInput.value,
                email:       emailInput.value.trim(),
                password:    passInput.value,
                ubicacion:   ubicacionInput.value.trim(),
                cuenta_id:   cuentaInput.value.trim(),
                telefono:    telInput.value.trim(),
            };
            try {
                if (isEdit) {
                    await TarjetasAPI.perfiles.update({ id: perfil.id, ...payload });
                    toast("Perfil actualizado", "success");
                } else {
                    const res = await TarjetasAPI.perfiles.create(payload);
                    selectedId = Number(res.perfil.id);
                    toast("Perfil creado", "success");
                }
                formOpen = false;
                editingId = null;
                await load();
                render();
                notifyFilter();
                TarjetasCards.refresh();
            } catch (err) {
                toast(err.message, "error");
            }
        });

        return form;
    }

    // ── Render del sidebar ───────────────────────────────────
    function render() {
        const mount = document.getElementById("tj-perfiles-mount");
        if (!mount) return;
        mount.textContent = "";

        const addBtn = el("button", { class: "tj-btn tj-btn--primary tj-btn--sm", type: "button", title: "Nuevo perfil" }, [icon("plus", 14), "Perfil"]);
        addBtn.addEventListener("click", () => { formOpen = true; editingId = null; render(); });

        mount.appendChild(el("div", { class: "tj-sidebar-head" }, [
            el("h2", {}, [icon("user", 16), " Perfiles"]),
            addBtn,
        ]));

        if (formOpen) {
            mount.appendChild(buildForm(editingId ? byId(editingId) : null));
        }

        const listWrap = el("div", { class: "tj-perfiles-list", role: "listbox", "aria-label": "Perfiles" });

        // Fila "Todas".
        const allRow = el("button", {
            class: `tj-perfil-row${selectedId === null ? " is-selected" : ""}`,
            type: "button", role: "option",
        }, [
            el("span", { class: "tj-perfil-dot tj-perfil-dot--all" }),
            el("span", { class: "tj-perfil-info" }, [
                el("span", { class: "tj-perfil-name", text: "Todas las tarjetas" }),
            ]),
        ]);
        allRow.addEventListener("click", () => { selectedId = null; render(); notifyFilter(); });
        listWrap.appendChild(allRow);

        perfiles.forEach((p) => {
            const row = el("button", {
                class: `tj-perfil-row${selectedId === Number(p.id) ? " is-selected" : ""}`,
                type: "button", role: "option",
            }, [
                el("span", { class: "tj-perfil-dot", style: `background:${p.color}` }),
                el("span", { class: "tj-perfil-info" }, [
                    el("span", { class: "tj-perfil-name", text: p.titular }),
                    el("span", { class: "tj-perfil-meta", text: `${p.emisor_nombre || p.banco} · ${p.cards_count} tarjeta${Number(p.cards_count) === 1 ? "" : "s"}` }),
                ]),
            ]);
            row.addEventListener("click", () => {
                selectedId = selectedId === Number(p.id) ? null : Number(p.id);
                render();
                notifyFilter();
            });

            const addCard = el("button", { class: "tj-icon-btn tj-icon-btn--sm", type: "button", title: "Añadir tarjeta a este perfil", "aria-label": `Añadir tarjeta a ${p.titular}` }, [icon("plus", 14)]);
            addCard.addEventListener("click", (e) => {
                e.stopPropagation();
                TarjetasCards.openQuickAdd(p);
            });

            const editBtn = el("button", { class: "tj-icon-btn tj-icon-btn--sm", type: "button", title: "Editar perfil", "aria-label": `Editar ${p.titular}` }, [icon("edit", 14)]);
            editBtn.addEventListener("click", (e) => {
                e.stopPropagation();
                formOpen = true;
                editingId = Number(p.id);
                render();
            });

            const delBtn = el("button", { class: "tj-icon-btn tj-icon-btn--sm tj-icon-btn--danger", type: "button", title: "Eliminar perfil", "aria-label": `Eliminar ${p.titular}` }, [icon("trash", 14)]);
            delBtn.addEventListener("click", async (e) => {
                e.stopPropagation();
                if (!confirm(`¿Eliminar el perfil "${p.titular}" y todas sus tarjetas?`)) return;
                try {
                    await TarjetasAPI.perfiles.delete(p.id);
                    if (selectedId === Number(p.id)) selectedId = null;
                    await load();
                    render();
                    notifyFilter();
                    TarjetasCards.refresh();
                    toast("Perfil eliminado", "success");
                } catch (err) {
                    toast(err.message, "error");
                }
            });

            listWrap.appendChild(el("div", { class: "tj-perfil-item" }, [row, el("div", { class: "tj-perfil-actions" }, [addCard, editBtn, delBtn])]));
        });

        if (!perfiles.length) {
            listWrap.appendChild(el("p", { class: "tj-empty-hint", text: "Sin perfiles todavía. Crea el primero con el botón + Perfil." }));
        }

        mount.appendChild(listWrap);
    }

    function init() {
        document.addEventListener("tarjetas:emisores-changed", async () => {
            await load();
            render();
        });
    }

    return Object.freeze({ init, load, render, list, byId, getSelectedId });
})();

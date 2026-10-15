"use strict";

const TarjetasPresets = (() => {
    const { el, icon, field, modal, closeOverlay, confirm, toast } = TarjetasUI;
    const defaults = Object.freeze([
        {
            id: "split",
            name: "Dividida · 3 columnas",
            columns: 3,
            ratio: "portrait",
            sidebar: "right",
            sort: "manual",
            density: "comfortable",
        },
        {
            id: "compact",
            name: "Compacta · alta densidad",
            columns: 4,
            ratio: "square",
            sidebar: "right",
            sort: "profile",
            density: "compact",
        },
        {
            id: "focus",
            name: "Enfoque · tarjetas clásicas",
            columns: 2,
            ratio: "classic",
            sidebar: "right",
            sort: "cooldown",
            density: "comfortable",
        },
        {
            id: "ledger",
            name: "Inventario · lectura rápida",
            columns: 1,
            ratio: "auto",
            sidebar: "bottom",
            sort: "uses",
            density: "compact",
        },
    ]);
    let context = null;

    function init(options) {
        context = options;
    }

    function all() {
        return [...defaults, ...(context.state.preferences.custom_presets || [])];
    }

    function current(id = context.state.preferences.selected_preset) {
        return all().find((preset) => preset.id === id) || defaults[0];
    }

    function apply(id, persist = false) {
        const preset = current(id);
        context.state.currentPreset = preset;
        context.state.preferences.selected_preset = preset.id;
        const grid = document.getElementById("tc-card-grid");
        const main = document.querySelector(".tc-main");
        grid.style.setProperty("--tc-columns", String(preset.columns));
        grid.dataset.ratio = preset.ratio;
        grid.dataset.density = preset.density;
        main.dataset.sidebar = preset.sidebar;
        const select = document.getElementById("tc-preset-select");
        if (select) select.value = preset.id;
        context.renderCards();
        if (persist) context.savePreferences();
    }

    function renderSelect() {
        const select = document.getElementById("tc-preset-select");
        select.replaceChildren();
        const defaultGroup = el("optgroup", { label: "Preajustes útiles" });
        defaults.forEach((preset) => defaultGroup.appendChild(el("option", {
            value: preset.id,
            text: preset.name,
        })));
        select.appendChild(defaultGroup);
        const custom = context.state.preferences.custom_presets || [];
        if (custom.length) {
            const customGroup = el("optgroup", { label: "Mis preajustes" });
            custom.forEach((preset) => customGroup.appendChild(el("option", {
                value: preset.id,
                text: preset.name,
            })));
            select.appendChild(customGroup);
        }
        select.value = current().id;
    }

    function previewNode(values) {
        const cards = el("div", {
            class: "tc-preset-cards",
            style: { "--preview-columns": String(values.columns) },
        });
        for (let index = 0; index < Math.min(12, values.columns * 2); index++) cards.appendChild(el("span"));
        return el("div", { class: `tc-preset-preview${values.sidebar === "bottom" ? " is-bottom" : ""}` }, [
            cards,
            el("div", { class: "tc-preset-side" }),
        ]);
    }

    function openBuilder(existing = null) {
        const name = el("input", {
            id: "tc-preset-name",
            type: "text",
            maxlength: "50",
            required: true,
            value: existing?.name || "",
            placeholder: "Ej. Operación nocturna",
        });
        const columns = el("input", {
            id: "tc-preset-columns",
            type: "range",
            min: "1",
            max: "6",
            value: existing?.columns || 3,
        });
        const columnLabel = el("span", { class: "tc-field-note" });
        const ratio = el("select", { id: "tc-preset-ratio" }, [
            el("option", { value: "portrait", text: "Vertical 3:4" }),
            el("option", { value: "classic", text: "Clásica horizontal" }),
            el("option", { value: "square", text: "Cuadrada" }),
            el("option", { value: "auto", text: "Altura automática" }),
        ]);
        ratio.value = existing?.ratio || "portrait";
        const sidebar = el("select", { id: "tc-preset-sidebar" }, [
            el("option", { value: "right", text: "Perfiles a la derecha" }),
            el("option", { value: "bottom", text: "Perfiles debajo" }),
        ]);
        sidebar.value = existing?.sidebar || "right";
        const sort = el("select", { id: "tc-preset-sort" }, [
            el("option", { value: "manual", text: "Orden manual" }),
            el("option", { value: "profile", text: "Por titular" }),
            el("option", { value: "uses", text: "Más usos primero" }),
            el("option", { value: "cooldown", text: "Más próximas a estar listas" }),
            el("option", { value: "recent", text: "Más recientes primero" }),
        ]);
        sort.value = existing?.sort || "manual";
        const density = el("select", { id: "tc-preset-density" }, [
            el("option", { value: "comfortable", text: "Cómoda" }),
            el("option", { value: "compact", text: "Compacta" }),
        ]);
        density.value = existing?.density || "comfortable";
        const previewHost = el("div");

        function values() {
            return {
                columns: Number(columns.value),
                ratio: ratio.value,
                sidebar: sidebar.value,
                sort: sort.value,
                density: density.value,
            };
        }

        function renderPreview() {
            columnLabel.textContent = `${columns.value} columna${columns.value === "1" ? "" : "s"}`;
            previewHost.replaceChildren(previewNode(values()));
        }
        [columns, ratio, sidebar, sort, density].forEach((control) => control.addEventListener("input", renderPreview));

        const form = el("form", {}, [
            el("div", { class: "tc-form-grid" }, [
                field("Nombre del preajuste", name, { full: true }),
                field("Cantidad de columnas", el("div", {}, [columns, columnLabel])),
                field("Proporción", ratio),
                field("Ubicación de perfiles", sidebar),
                field("Orden de tarjetas", sort),
                field("Densidad", density),
                field("Vista previa", previewHost, { full: true }),
            ]),
            el("div", { class: "tc-confirm-actions" }, [
                el("button", { class: "tc-btn tc-btn--ghost", type: "button", text: "Cancelar", onclick: closeOverlay }),
                el("button", { class: "tc-btn tc-btn--primary", type: "submit", text: "Guardar y aplicar" }),
            ]),
        ]);
        renderPreview();

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const baseId = name.value.trim().normalize("NFD").replace(/[\u0300-\u036f]/g, "")
                .toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "vista";
            const id = existing?.id || `${baseId}-${Date.now().toString(36)}`;
            const preset = { id, name: name.value.trim(), ...values() };
            const custom = [...(context.state.preferences.custom_presets || [])];
            const index = custom.findIndex((item) => item.id === id);
            if (index >= 0) custom[index] = preset;
            else custom.push(preset);
            context.state.preferences.custom_presets = custom;
            context.state.preferences.selected_preset = id;
            closeOverlay();
            renderSelect();
            apply(id);
            await context.savePreferences();
            toast("Preajuste guardado.", "success");
        });

        modal(existing ? "Editar preajuste" : "Nuevo preajuste", "Controla dimensiones, ubicación y orden", form, true);
    }

    function emitterForm(emitter = null) {
        const name = el("input", {
            id: "tc-emitter-name",
            type: "text",
            required: true,
            maxlength: "80",
            value: emitter?.name || "",
            placeholder: "Ej. Mercado Pago",
        });
        const color = el("input", {
            id: "tc-emitter-color",
            type: "color",
            value: emitter?.color || "#7468ff",
        });
        const form = el("form", { class: "tc-inline-form" }, [
            el("div", { class: "tc-form-grid" }, [
                field("Nombre del emisor", name),
                field("Color por defecto", color),
            ]),
            el("div", { class: "tc-detail-actions" }, [
                el("button", {
                    class: "tc-btn tc-btn--primary tc-btn--small",
                    type: "submit",
                    text: emitter ? "Guardar emisor" : "Añadir emisor",
                }),
                emitter ? el("button", {
                    class: "tc-btn tc-btn--ghost tc-btn--small",
                    type: "button",
                    text: "Cancelar",
                    onclick: () => openManager("emitters"),
                }) : null,
            ]),
        ]);
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            try {
                if (emitter) await TarjetasAPI.emitters.update({ id: emitter.id, name: name.value, color: color.value });
                else await TarjetasAPI.emitters.create({ name: name.value, color: color.value });
                await context.reload();
                openManager("emitters");
                toast(emitter ? "Emisor actualizado." : "Emisor añadido.", "success");
            } catch (error) {
                context.handleError(error);
            }
        });
        return form;
    }

    function emittersPanel(editingId = null) {
        const editing = context.state.emitters.find((item) => item.id === editingId);
        const list = el("div", { class: "tc-settings-list" });
        context.state.emitters.forEach((emitter) => {
            list.appendChild(el("div", { class: "tc-settings-row", style: { "--row-color": emitter.color } }, [
                el("span", { class: "tc-settings-dot" }),
                el("strong", { text: emitter.name }),
                el("div", { class: "tc-settings-actions" }, [
                    el("button", {
                        class: "tc-icon-btn tc-icon-btn--small",
                        type: "button",
                        title: "Editar emisor",
                        "aria-label": `Editar ${emitter.name}`,
                        onclick: () => openEmitterEditor(emitter),
                    }, [icon("edit", 15)]),
                    el("button", {
                        class: "tc-icon-btn tc-icon-btn--small",
                        type: "button",
                        title: "Eliminar emisor",
                        "aria-label": `Eliminar ${emitter.name}`,
                        onclick: () => deleteEmitter(emitter),
                    }, [icon("trash", 15)]),
                ]),
            ]));
        });
        return el("div", {}, [
            emitterForm(editing),
            list,
        ]);
    }

    function openEmitterEditor(emitter) {
        const content = emitterForm(emitter);
        modal("Editar emisor", "El color nuevo se aplicará a futuros perfiles", content);
    }

    async function deleteEmitter(emitter) {
        const accepted = await confirm({
            title: "Eliminar emisor",
            message: `Se eliminará ${emitter.name}. Solo es posible si no tiene perfiles asociados.`,
            acceptLabel: "Eliminar",
            danger: true,
        });
        if (!accepted) return;
        try {
            await TarjetasAPI.emitters.delete(emitter.id);
            await context.reload();
            openManager("emitters");
            toast("Emisor eliminado.", "success");
        } catch (error) {
            context.handleError(error);
        }
    }

    function presetsPanel() {
        const list = el("div", { class: "tc-settings-list" });
        (context.state.preferences.custom_presets || []).forEach((preset) => {
            list.appendChild(el("div", { class: "tc-settings-row" }, [
                el("span", { class: "tc-settings-dot", style: { "--row-color": "var(--tc-accent)" } }),
                el("strong", { text: preset.name }),
                el("div", { class: "tc-settings-actions" }, [
                    el("button", {
                        class: "tc-icon-btn tc-icon-btn--small",
                        type: "button",
                        title: "Editar",
                        onclick: () => openBuilder(preset),
                    }, [icon("edit", 15)]),
                    el("button", {
                        class: "tc-icon-btn tc-icon-btn--small",
                        type: "button",
                        title: "Eliminar",
                        onclick: async () => {
                            context.state.preferences.custom_presets = context.state.preferences.custom_presets
                                .filter((item) => item.id !== preset.id);
                            if (context.state.preferences.selected_preset === preset.id) {
                                context.state.preferences.selected_preset = "split";
                            }
                            await context.savePreferences();
                            renderSelect();
                            apply(context.state.preferences.selected_preset);
                            openManager("presets");
                        },
                    }, [icon("trash", 15)]),
                ]),
            ]));
        });
        return el("div", {}, [
            el("button", {
                class: "tc-btn tc-btn--primary tc-btn--block",
                type: "button",
                text: "Crear preajuste personalizado",
                onclick: () => openBuilder(),
            }, [icon("plus", 16)]),
            el("p", {
                class: "tc-field-note",
                text: "Los cuatro preajustes base permanecen disponibles y no se pueden borrar.",
                style: { display: "block", margin: "10px 0 14px" },
            }),
            list,
        ]);
    }

    function appearancePanel() {
        const theme = el("select", { id: "tc-theme-setting" }, [
            el("option", { value: "system", text: "Seguir al sistema" }),
            el("option", { value: "dark", text: "Oscuro" }),
            el("option", { value: "light", text: "Claro" }),
        ]);
        theme.value = context.state.preferences.theme || "system";
        theme.addEventListener("change", async () => {
            context.state.preferences.theme = theme.value;
            context.setTheme(theme.value);
            await context.savePreferences();
        });
        return el("div", { class: "tc-form-grid" }, [
            field("Tema de la interfaz", theme, {
                full: true,
                note: "La preferencia se guarda para tus próximas visitas.",
            }),
        ]);
    }

    function openManager(initialTab = "emitters") {
        const tabs = [
            { id: "emitters", label: "Emisores", render: emittersPanel },
            { id: "presets", label: "Preajustes", render: presetsPanel },
            { id: "appearance", label: "Apariencia", render: appearancePanel },
        ];
        const tabBar = el("div", { class: "tc-tabs", role: "tablist" });
        const panel = el("div", { class: "tc-tab-panel is-active" });
        const activate = (id) => {
            tabBar.querySelectorAll(".tc-tab").forEach((button) => button.classList.toggle("is-active", button.dataset.tab === id));
            const tab = tabs.find((item) => item.id === id) || tabs[0];
            panel.replaceChildren(tab.render());
        };
        tabs.forEach((tab) => tabBar.appendChild(el("button", {
            class: `tc-tab${tab.id === initialTab ? " is-active" : ""}`,
            type: "button",
            role: "tab",
            "data-tab": tab.id,
            text: tab.label,
            onclick: () => activate(tab.id),
        })));
        const content = el("div", {}, [tabBar, panel]);
        modal("Configuración", "Emisores, vistas y preferencias persistentes", content, true);
        activate(initialTab);
    }

    return Object.freeze({
        init, defaults, current, apply, renderSelect, openBuilder, openManager,
    });
})();

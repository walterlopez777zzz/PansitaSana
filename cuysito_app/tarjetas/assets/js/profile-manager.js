"use strict";

const TarjetasProfiles = (() => {
    const { el, icon, field, drawer, closeOverlay, confirm, copy, initials, normalize, toast } = TarjetasUI;
    let context = null;

    function init(options) {
        context = options;
    }

    function emitterOptions(selectedId) {
        return context.state.emitters.map((emitter) =>
            el("option", { value: emitter.id, text: emitter.name, selected: Number(selectedId) === Number(emitter.id) }));
    }

    function input(id, value = "", options = {}) {
        return el("input", {
            id,
            name: id,
            type: options.type || "text",
            value: value || "",
            required: options.required || false,
            placeholder: options.placeholder || "",
            autocomplete: options.autocomplete || "off",
            list: options.list || null,
        });
    }

    function openForm(profile = null) {
        if (context.state.emitters.length === 0) {
            toast("Primero crea un emisor desde Configuración.", "error");
            context.openSettings("emitters");
            return;
        }

        const emitter = context.state.emitters.find((item) => item.id === profile?.emitter_id) || context.state.emitters[0];
        const emitterSelect = el("select", { id: "tc-profile-emitter", required: true }, emitterOptions(emitter.id));
        const colorInput = input("tc-profile-color", profile?.color || emitter.color, { type: "color" });
        const devices = el("datalist", { id: "tc-device-options" }, [
            el("option", { value: "iPhone" }),
            el("option", { value: "Android" }),
            el("option", { value: "PC" }),
            el("option", { value: "Tablet" }),
        ]);
        emitterSelect.addEventListener("change", () => {
            const selected = context.state.emitters.find((item) => item.id === Number(emitterSelect.value));
            if (selected) colorInput.value = selected.color;
        });

        const form = el("form", {}, [
            el("fieldset", { class: "tc-form-section" }, [
                el("legend", { text: "Datos principales" }),
                el("div", { class: "tc-form-grid" }, [
                    field("Emisor", emitterSelect),
                    field("Color del perfil", colorInput),
                    field("Banco", input("tc-profile-bank", profile?.bank, {
                        required: true,
                        placeholder: "Ej. Santander",
                    })),
                    field("Dispositivo", input("tc-profile-device", profile?.device, {
                        required: true,
                        placeholder: "Selecciona o escribe",
                        list: "tc-device-options",
                    })),
                    devices,
                    field("Titular", input("tc-profile-holder", profile?.holder, {
                        required: true,
                        placeholder: "Nombre completo",
                        autocomplete: "name",
                    })),
                    field("CVU / CBU", input("tc-profile-cvu", profile?.cvu, {
                        required: true,
                        placeholder: "Número de cuenta",
                        autocomplete: "off",
                    })),
                ]),
            ]),
            el("fieldset", { class: "tc-form-section" }, [
                el("legend", { text: "Datos complementarios · opcionales" }),
                el("div", { class: "tc-form-grid" }, [
                    field("Correo de la cuenta", input("tc-profile-email", profile?.email, {
                        type: "email",
                        autocomplete: "username",
                    })),
                    field("Contraseña de la cuenta", input("tc-profile-password", profile?.account_password, {
                        type: "password",
                        autocomplete: "current-password",
                    })),
                    field("Ubicación o dirección", input("tc-profile-location", profile?.location)),
                    field("Número de cuenta interno", input("tc-profile-account", profile?.account_number)),
                    field("Celular / teléfono", input("tc-profile-phone", profile?.phone, {
                        type: "tel",
                        autocomplete: "tel",
                    })),
                ]),
            ]),
            el("div", { class: "tc-form-actions" }, [
                el("button", { class: "tc-btn tc-btn--ghost", type: "button", text: "Cancelar", onclick: closeOverlay }),
                el("button", {
                    class: "tc-btn tc-btn--primary",
                    type: "submit",
                    text: profile ? "Guardar cambios" : "Crear perfil",
                }),
            ]),
        ]);

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            const payload = {
                id: profile?.id,
                emitter_id: Number(emitterSelect.value),
                bank: form.querySelector("#tc-profile-bank").value,
                device: form.querySelector("#tc-profile-device").value,
                holder: form.querySelector("#tc-profile-holder").value,
                cvu: form.querySelector("#tc-profile-cvu").value,
                email: form.querySelector("#tc-profile-email").value,
                account_password: form.querySelector("#tc-profile-password").value,
                location: form.querySelector("#tc-profile-location").value,
                account_number: form.querySelector("#tc-profile-account").value,
                phone: form.querySelector("#tc-profile-phone").value,
                color: colorInput.value,
            };
            try {
                const response = profile
                    ? await TarjetasAPI.profiles.update(payload)
                    : await TarjetasAPI.profiles.create(payload);
                closeOverlay();
                await context.reload();
                context.selectProfile(profile?.id || response.id);
                toast(profile ? "Perfil actualizado." : "Perfil creado.", "success");
            } catch (error) {
                context.handleError(error);
                submit.disabled = false;
            }
        });

        drawer(profile ? "Editar perfil" : "Nuevo perfil", "La cuenta fija de la que nacen las tarjetas", form);
    }

    function detailItem(label, value, copyLabel = label) {
        const safeValue = value || "—";
        return el("div", { class: "tc-detail-item" }, [
            el("small", { text: label }),
            value
                ? el("button", { type: "button", text: safeValue, title: `Copiar ${label}`, onclick: () => copy(value, copyLabel) })
                : el("span", { text: safeValue }),
        ]);
    }

    function openDetail(profile) {
        context.selectProfile(profile.id);
        const content = el("div", {}, [
            el("div", { class: "tc-detail-hero", style: { "--detail-color": profile.color } }, [
                el("h3", { text: profile.holder }),
                el("p", { text: `${profile.emitter_name} · ${profile.bank} · ${profile.device}` }),
            ]),
            el("div", { class: "tc-detail-grid" }, [
                detailItem("CVU / CBU", profile.cvu),
                detailItem("Número interno", profile.account_number),
                detailItem("Correo", profile.email),
                detailItem("Contraseña", profile.account_password),
                detailItem("Teléfono", profile.phone),
                detailItem("Ubicación", profile.location),
            ]),
            el("div", { class: "tc-detail-actions" }, [
                el("button", {
                    class: "tc-btn tc-btn--primary",
                    type: "button",
                    text: "Añadir tarjeta",
                    onclick: () => { closeOverlay(); context.openCardForm(profile.id); },
                }, [icon("plus", 16)]),
                el("button", {
                    class: "tc-btn",
                    type: "button",
                    text: "Editar perfil",
                    onclick: () => { closeOverlay(); openForm(profile); },
                }, [icon("edit", 16)]),
                el("button", {
                    class: "tc-btn tc-btn--danger",
                    type: "button",
                    text: "Eliminar",
                    onclick: async () => {
                        const accepted = await confirm({
                            title: "Eliminar perfil",
                            message: `Se eliminará ${profile.holder} y sus ${profile.card_count} tarjeta(s). Esta acción no se puede deshacer.`,
                            acceptLabel: "Eliminar perfil",
                            danger: true,
                        });
                        if (!accepted) return;
                        try {
                            await TarjetasAPI.profiles.delete(profile.id);
                            closeOverlay();
                            context.selectProfile(null);
                            await context.reload();
                            toast("Perfil eliminado.", "success");
                        } catch (error) {
                            context.handleError(error);
                        }
                    },
                }, [icon("trash", 16)]),
            ]),
        ]);
        drawer("Detalle del perfil", "Datos sensibles protegidos y accesos rápidos", content);
    }

    function render() {
        const host = document.getElementById("tc-profile-list");
        const query = normalize(document.getElementById("tc-profile-search").value);
        const profiles = context.state.profiles.filter((profile) =>
            normalize(`${profile.holder} ${profile.bank} ${profile.emitter_name} ${profile.device}`).includes(query));
        host.replaceChildren();

        if (profiles.length === 0) {
            host.appendChild(el("div", { class: "tc-empty tc-empty--small" }, [
                el("div", { class: "tc-empty-inner" }, [
                    icon("user", 30),
                    el("h3", { text: query ? "Sin coincidencias" : "Aún no hay perfiles" }),
                    el("p", { text: query ? "Prueba con otro término." : "Crea la primera cuenta fija para empezar." }),
                    query ? null : el("button", {
                        class: "tc-btn tc-btn--primary tc-btn--small",
                        type: "button",
                        text: "Crear perfil",
                        onclick: () => openForm(),
                    }),
                ]),
            ]));
            return;
        }

        profiles.forEach((profile) => {
            const row = el("button", {
                class: `tc-profile${context.state.selectedProfileId === profile.id ? " is-selected" : ""}`,
                type: "button",
                style: { "--profile-color": profile.color },
                "aria-label": `Abrir perfil de ${profile.holder}`,
                onclick: () => openDetail(profile),
            }, [
                el("span", { class: "tc-profile-avatar", text: initials(profile.holder) }),
                el("span", { class: "tc-profile-copy" }, [
                    el("strong", { text: profile.holder }),
                    el("small", { text: `${profile.emitter_name} · ${profile.device}` }),
                ]),
                el("span", { class: "tc-profile-count", text: profile.card_count }),
            ]);
            host.appendChild(row);
        });
    }

    return Object.freeze({ init, render, openForm, openDetail });
})();

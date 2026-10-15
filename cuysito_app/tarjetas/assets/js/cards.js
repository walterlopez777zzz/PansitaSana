"use strict";

const TarjetasCards = (() => {
    const {
        el, icon, field, drawer, closeOverlay, confirm, copy, formatCard,
        cooldown, normalize, toast,
    } = TarjetasUI;
    let context = null;
    let timer = null;
    let draggingId = null;

    function init(options) {
        context = options;
        clearInterval(timer);
        timer = setInterval(updateCountdowns, 1000);
    }

    function getProfile(id) {
        return context.state.profiles.find((profile) => profile.id === Number(id));
    }

    function sortedCards(cards) {
        const sort = context.state.currentPreset?.sort || "manual";
        return [...cards].sort((a, b) => {
            if (sort === "profile") return a.holder.localeCompare(b.holder, "es");
            if (sort === "uses") return b.uses_remaining - a.uses_remaining;
            if (sort === "cooldown") {
                return (TarjetasUI.parseServerDate(a.cooldown_until)?.getTime() || 0)
                    - (TarjetasUI.parseServerDate(b.cooldown_until)?.getTime() || 0);
            }
            if (sort === "recent") return String(b.created_at).localeCompare(String(a.created_at));
            return a.sort_order - b.sort_order;
        });
    }

    function visibleCards() {
        const query = normalize(document.getElementById("tc-card-search").value);
        const profileId = Number(document.getElementById("tc-profile-filter").value || 0);
        const readyOnly = document.getElementById("tc-ready-only").checked;
        return sortedCards(context.state.cards.filter((card) => {
            const haystack = normalize(`${card.holder} ${card.bank} ${card.emitter_name} ${card.device} ${card.number.slice(-4)}`);
            if (query && !haystack.includes(query)) return false;
            if (profileId && card.profile_id !== profileId) return false;
            if (readyOnly && !cooldown(card.cooldown_until).ready) return false;
            return true;
        }));
    }

    function manualReorderAvailable() {
        return (context.state.currentPreset?.sort || "manual") === "manual"
            && document.getElementById("tc-card-search").value === ""
            && document.getElementById("tc-profile-filter").value === ""
            && !document.getElementById("tc-ready-only").checked;
    }

    async function reorderCard(targetId) {
        if (!draggingId || draggingId === targetId) return;
        const ordered = [...context.state.cards].sort((a, b) => a.sort_order - b.sort_order);
        const from = ordered.findIndex((card) => card.id === draggingId);
        const to = ordered.findIndex((card) => card.id === targetId);
        if (from < 0 || to < 0) return;
        const [moved] = ordered.splice(from, 1);
        ordered.splice(to, 0, moved);
        ordered.forEach((card, index) => { card.sort_order = index; });
        render();
        try {
            await TarjetasAPI.cards.reorder(ordered.map((card) => card.id));
            toast("Orden guardado.", "success");
        } catch (error) {
            context.handleError(error);
            await context.reload();
        }
    }

    async function quickUse(card, hours = 24) {
        try {
            await TarjetasAPI.cards.use(card.id, hours);
            await context.reload();
            toast(`Uso registrado · ${hours}h de cooldown`, "success");
        } catch (error) {
            context.handleError(error);
        }
    }

    function copyButton(className, text, value, label) {
        return el("button", {
            class: `tc-copy ${className}`,
            type: "button",
            title: `Copiar ${label}`,
            onclick: () => copy(value, label),
        }, [text]);
    }

    function createCard(card) {
        const remaining = card.uses_remaining;
        const useClass = remaining < 0 ? " is-negative" : remaining <= 1 ? " is-low" : "";
        const time = cooldown(card.cooldown_until);
        const article = el("article", {
            class: "tc-card",
            style: { "--card-color": card.color },
            "data-card-id": card.id,
            draggable: manualReorderAvailable(),
            title: manualReorderAvailable() ? "Arrastra para cambiar el orden" : "",
        }, [
            el("div", { class: "tc-card-head" }, [
                el("div", { class: "tc-card-issuer" }, [
                    el("strong", { text: card.emitter_name }),
                    el("small", { text: `${card.bank} · ${card.device}` }),
                ]),
                el("button", {
                    class: "tc-card-menu",
                    type: "button",
                    title: "Ver detalles",
                    "aria-label": `Ver detalles de la tarjeta de ${card.holder}`,
                    onclick: () => openDetail(card),
                }, [icon("more", 18)]),
            ]),
            copyButton("tc-card-number", formatCard(card.number), card.number, "Número"),
            el("div", { class: "tc-card-details" }, [
                el("div", { class: "tc-card-data" }, [
                    el("small", { text: "Vence" }),
                    copyButton("", card.expiry, card.expiry, "Vencimiento"),
                ]),
                el("div", { class: "tc-card-data" }, [
                    el("small", { text: "CVV" }),
                    copyButton("", card.cvv, card.cvv, "CVV"),
                ]),
            ]),
            el("div", { class: "tc-card-holder" }, [
                el("small", { text: "Titular" }),
                copyButton("", card.holder, card.holder, "Titular"),
            ]),
            el("div", { class: "tc-card-foot" }, [
                el("span", { class: `tc-use-badge${useClass}` }, [
                    icon("zap", 13),
                    `${remaining} uso${remaining === 1 ? "" : "s"}`,
                ]),
                el("span", {
                    class: `tc-cooldown${time.ready ? " is-ready" : ""}`,
                    "data-cooldown": card.cooldown_until || "",
                }, [
                    icon("clock", 13),
                    el("span", { text: time.label }),
                ]),
                el("button", {
                    class: "tc-card-quick-use",
                    type: "button",
                    title: "Usar ahora y activar 24h",
                    "aria-label": `Registrar un uso de la tarjeta de ${card.holder}`,
                    onclick: () => quickUse(card),
                }, [icon("zap", 15)]),
            ]),
        ]);
        article.addEventListener("dragstart", (event) => {
            draggingId = card.id;
            article.classList.add("is-dragging");
            event.dataTransfer.effectAllowed = "move";
            event.dataTransfer.setData("text/plain", String(card.id));
        });
        article.addEventListener("dragend", () => {
            draggingId = null;
            article.classList.remove("is-dragging");
        });
        article.addEventListener("dragover", (event) => {
            if (!manualReorderAvailable()) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = "move";
        });
        article.addEventListener("drop", (event) => {
            event.preventDefault();
            reorderCard(card.id);
        });
        return article;
    }

    function render() {
        const host = document.getElementById("tc-card-grid");
        const cards = visibleCards();
        host.replaceChildren();
        if (cards.length === 0) {
            const hasCards = context.state.cards.length > 0;
            host.appendChild(el("div", { class: "tc-empty", style: { "grid-column": "1 / -1" } }, [
                el("div", { class: "tc-empty-inner" }, [
                    icon(hasCards ? "search" : "cards", 38),
                    el("h3", { text: hasCards ? "No encontramos tarjetas" : "Tu rotación empieza aquí" }),
                    el("p", {
                        text: hasCards
                            ? "Ajusta la búsqueda o los filtros para volver a verlas."
                            : "Crea un perfil y registra su primera tarjeta temporal.",
                    }),
                    hasCards ? null : el("button", {
                        class: "tc-btn tc-btn--primary",
                        type: "button",
                        text: context.state.profiles.length ? "Nueva tarjeta" : "Crear primer perfil",
                        onclick: () => context.state.profiles.length ? openForm() : context.openProfileForm(),
                    }),
                ]),
            ]));
            return;
        }
        cards.forEach((card) => host.appendChild(createCard(card)));
    }

    function updateCountdowns() {
        document.querySelectorAll("[data-cooldown]").forEach((node) => {
            const current = cooldown(node.dataset.cooldown);
            node.classList.toggle("is-ready", current.ready);
            const label = node.querySelector("span");
            if (label) label.textContent = current.label;
        });
    }

    function usesControl(initialValue = 5) {
        const input = el("input", {
            id: "tc-card-uses",
            type: "number",
            min: "-999",
            max: "999",
            value: initialValue,
            "aria-label": "Usos restantes",
        });
        const preview = el("div", { class: "tc-use-preview", "aria-live": "polite" });

        function renderPreview() {
            const value = Math.max(-999, Math.min(999, Number(input.value || 0)));
            preview.replaceChildren();
            if (value < 0) {
                preview.appendChild(el("span", { class: "tc-use-negative", text: `${value} usos restantes` }));
                return;
            }
            const visible = Math.min(10, Math.max(5, value));
            for (let index = 0; index < visible; index++) {
                preview.appendChild(el("button", {
                    class: `tc-use-dot${index < value ? " is-active" : ""}`,
                    type: "button",
                    title: `Dejar ${index + 1} uso(s)`,
                    "aria-label": `Dejar ${index + 1} uso(s)`,
                    onclick: () => { input.value = index + 1; renderPreview(); },
                }));
            }
            if (value > 10) preview.appendChild(el("span", { class: "tc-use-overflow", text: `+${value - 10}` }));
        }

        input.addEventListener("input", renderPreview);
        const control = el("div", {}, [
            el("div", { class: "tc-stepper" }, [
                el("button", {
                    type: "button",
                    "aria-label": "Restar un uso",
                    onclick: () => { input.value = Number(input.value || 0) - 1; renderPreview(); },
                }, [icon("minus", 17)]),
                input,
                el("button", {
                    type: "button",
                    "aria-label": "Añadir un uso",
                    onclick: () => { input.value = Number(input.value || 0) + 1; renderPreview(); },
                }, [icon("plus", 17)]),
            ]),
            preview,
        ]);
        renderPreview();
        return { control, input };
    }

    function openForm(profileId = null, card = null) {
        if (context.state.profiles.length === 0) {
            toast("Crea un perfil antes de registrar una tarjeta.", "error");
            context.openProfileForm();
            return;
        }

        const selectedProfileId = card?.profile_id || profileId || context.state.selectedProfileId || context.state.profiles[0].id;
        const profileSelect = el("select", { id: "tc-card-profile", required: true },
            context.state.profiles.map((profile) => el("option", {
                value: profile.id,
                selected: profile.id === selectedProfileId,
                text: `${profile.holder} · ${profile.emitter_name}`,
            })));
        const numberInput = el("input", {
            id: "tc-card-number",
            type: "text",
            inputmode: "numeric",
            autocomplete: "cc-number",
            required: true,
            value: card ? formatCard(card.number) : "",
            placeholder: "0000 0000 0000 0000",
            maxlength: "23",
        });
        numberInput.addEventListener("input", () => {
            numberInput.value = formatCard(numberInput.value).slice(0, 23);
        });
        const expiryInput = el("input", {
            id: "tc-card-expiry",
            type: "text",
            inputmode: "numeric",
            autocomplete: "cc-exp",
            required: true,
            value: card?.expiry || "",
            placeholder: "MM/AA",
            maxlength: "7",
        });
        expiryInput.addEventListener("input", () => {
            const digits = expiryInput.value.replace(/\D/g, "").slice(0, 6);
            expiryInput.value = digits.length > 2 ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits;
        });
        const cvvInput = el("input", {
            id: "tc-card-cvv",
            type: "password",
            inputmode: "numeric",
            autocomplete: "cc-csc",
            required: true,
            value: card?.cvv || "",
            placeholder: "123",
            maxlength: "4",
        });
        const uses = usesControl(card?.uses_remaining ?? 5);
        const useNow = el("input", { id: "tc-use-now", type: "checkbox" });
        const cooldownHours = el("input", {
            id: "tc-cooldown-hours",
            type: "number",
            min: "0",
            max: "720",
            step: "0.5",
            placeholder: "Ej. 18",
        });
        if (card?.cooldown_until) {
            const remaining = cooldown(card.cooldown_until).milliseconds / 3600000;
            cooldownHours.value = remaining > 0 ? remaining.toFixed(1) : "";
        }

        const form = el("form", {}, [
            el("fieldset", { class: "tc-form-section" }, [
                el("legend", { text: "Tarjeta temporal" }),
                el("div", { class: "tc-form-grid" }, [
                    field("Perfil / titular", profileSelect, { full: true }),
                    field("Número de tarjeta", numberInput, { full: true }),
                    field("Vencimiento", expiryInput),
                    field("CVV", cvvInput),
                ]),
            ]),
            el("fieldset", { class: "tc-form-section" }, [
                el("legend", { text: "Usos y disponibilidad" }),
                el("div", { class: "tc-form-grid" }, [
                    field("Usos restantes", uses.control, {
                        full: true,
                        note: "Puedes sumar, desmarcar o continuar bajo cero.",
                    }),
                    card ? null : el("label", { class: "tc-switch-row tc-field--full" }, [
                        useNow,
                        el("span", { text: "Usar ahora al guardar · resta 1 uso y activa 24h" }),
                    ]),
                    field(card ? "Cooldown restante (horas)" : "Cooldown manual inicial (horas)", cooldownHours, {
                        full: true,
                        note: "Déjalo vacío si la tarjeta está lista.",
                    }),
                ]),
            ]),
            el("div", { class: "tc-form-actions" }, [
                el("button", { class: "tc-btn tc-btn--ghost", type: "button", text: "Cancelar", onclick: closeOverlay }),
                el("button", {
                    class: "tc-btn tc-btn--primary",
                    type: "submit",
                    text: card ? "Guardar cambios" : "Registrar tarjeta",
                }),
            ]),
        ]);

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            const hours = cooldownHours.value === "" ? null : Number(cooldownHours.value);
            const payload = {
                id: card?.id,
                profile_id: Number(profileSelect.value),
                number: numberInput.value,
                expiry: expiryInput.value,
                cvv: cvvInput.value,
                uses_remaining: Number(uses.input.value),
                cooldown_hours: hours,
                cooldown_until: hours && hours > 0
                    ? new Date(Date.now() + hours * 3600000).toISOString().slice(0, 19).replace("T", " ")
                    : null,
                use_now: !card && useNow.checked,
            };
            try {
                if (card) await TarjetasAPI.cards.update(payload);
                else await TarjetasAPI.cards.create(payload);
                closeOverlay();
                context.selectProfile(payload.profile_id);
                await context.reload();
                toast(card ? "Tarjeta actualizada." : "Tarjeta registrada.", "success");
            } catch (error) {
                context.handleError(error);
                submit.disabled = false;
            }
        });

        drawer(card ? "Editar tarjeta" : "Nueva tarjeta", "Solo tres datos obligatorios; el resto viene del perfil", form);
    }

    function detailItem(label, value, copyLabel = label) {
        return el("div", { class: "tc-detail-item" }, [
            el("small", { text: label }),
            value
                ? el("button", { type: "button", text: value, onclick: () => copy(value, copyLabel) })
                : el("span", { text: "—" }),
        ]);
    }

    function openDetail(card) {
        const profile = getProfile(card.profile_id);
        if (!profile) return;
        const currentCooldown = cooldown(card.cooldown_until);
        const content = el("div", {}, [
            el("div", { class: "tc-detail-hero", style: { "--detail-color": card.color } }, [
                el("h3", { text: card.holder }),
                el("p", { text: `${card.emitter_name} · ${card.bank} · ${card.device}` }),
            ]),
            el("div", { class: "tc-detail-grid" }, [
                detailItem("Número", formatCard(card.number), "Número"),
                detailItem("Vencimiento", card.expiry),
                detailItem("CVV", card.cvv),
                detailItem("CVU / CBU", card.cvu),
                detailItem("Correo", profile.email),
                detailItem("Contraseña", profile.account_password),
                detailItem("Teléfono", profile.phone),
                detailItem("Ubicación", profile.location),
            ]),
            el("fieldset", { class: "tc-form-section", style: { "margin-top": "18px" } }, [
                el("legend", { text: "Control rápido" }),
                el("div", { class: "tc-detail-grid" }, [
                    el("div", { class: "tc-detail-item" }, [
                        el("small", { text: "Usos restantes" }),
                        el("div", { class: "tc-stepper", style: { "margin-top": "6px" } }, [
                            el("button", {
                                type: "button",
                                "aria-label": "Restar un uso",
                                onclick: async () => {
                                    await updateUses(card, card.uses_remaining - 1);
                                },
                            }, [icon("minus", 16)]),
                            el("input", {
                                type: "number",
                                value: card.uses_remaining,
                                "aria-label": "Usos restantes",
                                onchange: async (event) => updateUses(card, Number(event.target.value)),
                            }),
                            el("button", {
                                type: "button",
                                "aria-label": "Añadir un uso",
                                onclick: async () => {
                                    await updateUses(card, card.uses_remaining + 1);
                                },
                            }, [icon("plus", 16)]),
                        ]),
                    ]),
                    el("div", { class: "tc-detail-item" }, [
                        el("small", { text: "Cooldown" }),
                        el("span", {
                            text: currentCooldown.label,
                            "data-cooldown": card.cooldown_until || "",
                            style: { "padding-top": "9px" },
                        }),
                    ]),
                ]),
            ]),
            el("div", { class: "tc-detail-actions" }, [
                el("button", {
                    class: "tc-btn tc-btn--primary",
                    type: "button",
                    text: "Usar ahora · 24h",
                    onclick: async () => { closeOverlay(); await quickUse(card); },
                }, [icon("zap", 16)]),
                el("button", {
                    class: "tc-btn",
                    type: "button",
                    text: "Cooldown manual",
                    onclick: () => openCooldown(card),
                }, [icon("clock", 16)]),
                el("button", {
                    class: "tc-btn",
                    type: "button",
                    text: "Editar",
                    onclick: () => { closeOverlay(); openForm(card.profile_id, card); },
                }, [icon("edit", 16)]),
                el("button", {
                    class: "tc-btn tc-btn--danger",
                    type: "button",
                    text: "Eliminar y reemplazar",
                    onclick: () => removeCard(card),
                }, [icon("trash", 16)]),
            ]),
        ]);
        drawer("Detalle de tarjeta", "Datos heredados del perfil y acciones avanzadas", content);
    }

    async function updateUses(card, value) {
        try {
            await TarjetasAPI.cards.setUses(card.id, value);
            closeOverlay();
            await context.reload();
            const fresh = context.state.cards.find((item) => item.id === card.id);
            if (fresh) openDetail(fresh);
        } catch (error) {
            context.handleError(error);
        }
    }

    function openCooldown(card) {
        const hours = el("input", {
            id: "tc-manual-hours",
            type: "number",
            min: "0",
            max: "720",
            step: "0.5",
            value: "24",
        });
        const content = el("form", {}, [
            field("Horas restantes", hours, {
                full: true,
                note: "Usa 0 para dejarla lista inmediatamente.",
            }),
            el("div", { class: "tc-confirm-actions" }, [
                el("button", { class: "tc-btn tc-btn--ghost", type: "button", text: "Cancelar", onclick: closeOverlay }),
                el("button", { class: "tc-btn tc-btn--primary", type: "submit", text: "Aplicar" }),
            ]),
        ]);
        content.addEventListener("submit", async (event) => {
            event.preventDefault();
            try {
                await TarjetasAPI.cards.setCooldown(card.id, Number(hours.value || 0));
                closeOverlay();
                await context.reload();
                toast("Cooldown actualizado.", "success");
            } catch (error) {
                context.handleError(error);
            }
        });
        TarjetasUI.modal("Cooldown manual", "Ajusta el tiempo real que todavía falta", content);
    }

    async function removeCard(card) {
        const accepted = await confirm({
            title: "Eliminar tarjeta agotada",
            message: `Se eliminará la tarjeta terminada en ${card.number.slice(-4)}. El perfil ${card.holder} se conservará para cargar el reemplazo.`,
            acceptLabel: "Eliminar tarjeta",
            danger: true,
        });
        if (!accepted) return;
        try {
            await TarjetasAPI.cards.delete(card.id);
            await context.reload();
            toast("Tarjeta eliminada. El perfil quedó listo para el reemplazo.", "success");
            openForm(card.profile_id);
        } catch (error) {
            context.handleError(error);
        }
    }

    return Object.freeze({ init, render, openForm, openDetail, updateCountdowns });
})();

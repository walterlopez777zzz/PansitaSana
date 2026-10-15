"use strict";

/**
 * ============================================================
 * Tarjetas — Panel lateral de detalles ampliados
 * ============================================================
 * Drawer accesible con toda la información de la tarjeta y su
 * cuenta (incluidos los datos sensibles ocultos en la vista
 * general), edición de datos, cooldown manual, ajuste de usos y
 * el flujo de descarte + reemplazo ágil.
 * Expone el objeto global `TarjetasDetail`.
 * ============================================================
 */

const TarjetasDetail = (() => {
    const { el, icon, toast, copyText, formatCardNumber, formatCountdown } = TarjetasUI;

    let drawer = null;

    function close() {
        if (drawer) {
            drawer.classList.remove("is-open");
            const node = drawer;
            setTimeout(() => node.remove(), 260);
            drawer = null;
            document.removeEventListener("keydown", onKeydown);
        }
    }

    function onKeydown(e) {
        if (e.key === "Escape") close();
    }

    /** Fila copiable de datos (con opción de valor enmascarado). */
    function row(label, value, { masked = false, mono = false } = {}) {
        if (!value) return null;

        const valueSpan = el("span", {
            class: `tj-detail-value${mono ? " tj-mono" : ""}`,
            text: masked ? "••••••••" : String(value),
        });

        const copyBtn = el("button", { class: "tj-icon-btn tj-icon-btn--sm", type: "button", title: `Copiar ${label.toLowerCase()}`, "aria-label": `Copiar ${label}` }, [icon("copy", 14)]);
        copyBtn.addEventListener("click", () => copyText(value, label));

        const actions = el("div", { class: "tj-detail-row-actions" }, [copyBtn]);

        if (masked) {
            let shown = false;
            const eyeBtn = el("button", { class: "tj-icon-btn tj-icon-btn--sm", type: "button", title: "Mostrar / ocultar", "aria-label": `Mostrar ${label}` }, [icon("eye", 14)]);
            eyeBtn.addEventListener("click", () => {
                shown = !shown;
                valueSpan.textContent = shown ? String(value) : "••••••••";
            });
            actions.prepend(eyeBtn);
        }

        return el("div", { class: "tj-detail-row" }, [
            el("span", { class: "tj-detail-label", text: label }),
            valueSpan,
            actions,
        ]);
    }

    function section(title, rows) {
        const items = rows.filter(Boolean);
        if (!items.length) return null;
        return el("section", { class: "tj-detail-section" }, [
            el("h3", { text: title }),
            ...items,
        ]);
    }

    function open(card) {
        close();

        const perfil = TarjetasPerfiles.byId(card.perfil_id) || {};
        const left = Number(card.cooldown_seconds || 0);

        const closeBtn = el("button", { class: "tj-icon-btn", type: "button", "aria-label": "Cerrar detalles" }, [icon("close", 18)]);
        closeBtn.addEventListener("click", close);

        // ── Acciones principales ─────────────────────────────
        const useBtn = el("button", { class: "tj-btn tj-btn--primary", type: "button" }, [icon("bolt", 15), "Usar ahora (24 h)"]);
        useBtn.addEventListener("click", async () => {
            try {
                const res = await TarjetasAPI.cards.useNow(card.id);
                TarjetasCards.patchCard(res.card);
                toast("Uso registrado · cooldown 24 h", "success");
                close();
            } catch (err) { toast(err.message, "error"); }
        });

        const cooldownBtn = el("button", { class: "tj-btn", type: "button" }, [icon("clock", 15), "Cooldown manual"]);
        cooldownBtn.addEventListener("click", async () => {
            const raw = prompt("Horas de cooldown restantes (0 para limpiar):");
            if (raw === null) return;
            const hours = parseFloat(String(raw).replace(",", "."));
            if (!Number.isFinite(hours) || hours < 0) { toast("Horas inválidas", "error"); return; }
            try {
                const res = await TarjetasAPI.cards.setCooldown(card.id, hours);
                TarjetasCards.patchCard(res.card);
                toast(hours > 0 ? `Cooldown de ${hours} h fijado` : "Cooldown limpiado", "success");
                close();
            } catch (err) { toast(err.message, "error"); }
        });

        const editBtn = el("button", { class: "tj-btn", type: "button" }, [icon("edit", 15), "Editar datos"]);
        editBtn.addEventListener("click", () => openEdit(card));

        // Descarte + reemplazo ágil: elimina y abre el alta rápida del mismo perfil.
        const replaceBtn = el("button", { class: "tj-btn tj-btn--danger", type: "button" }, [icon("trash", 15), "Descartar y reemplazar"]);
        replaceBtn.addEventListener("click", async () => {
            if (!confirm("¿Eliminar esta tarjeta agotada y registrar su reemplazo?")) return;
            try {
                await TarjetasAPI.cards.delete(card.id);
                await Promise.all([TarjetasCards.refresh(), TarjetasPerfiles.load()]);
                TarjetasPerfiles.render();
                close();
                const p = TarjetasPerfiles.byId(card.perfil_id);
                if (p) TarjetasCards.openQuickAdd(p);
            } catch (err) { toast(err.message, "error"); }
        });

        const deleteBtn = el("button", { class: "tj-btn tj-btn--danger tj-btn--ghost", type: "button" }, [icon("trash", 15), "Sólo eliminar"]);
        deleteBtn.addEventListener("click", async () => {
            if (!confirm("¿Eliminar esta tarjeta definitivamente?")) return;
            try {
                await TarjetasAPI.cards.delete(card.id);
                await Promise.all([TarjetasCards.refresh(), TarjetasPerfiles.load()]);
                TarjetasPerfiles.render();
                toast("Tarjeta eliminada", "success");
                close();
            } catch (err) { toast(err.message, "error"); }
        });

        drawer = el("div", { class: "tj-drawer-overlay" }, [
            el("aside", { class: "tj-drawer", role: "dialog", "aria-modal": "true", "aria-label": "Detalles de la tarjeta" }, [
                el("header", { class: "tj-drawer-head", style: `--card-color:${card.perfil_color || "#6c63ff"}` }, [
                    el("div", {}, [
                        el("h2", { text: card.titular }),
                        el("p", { text: `${card.emisor_nombre || card.banco || ""} · ${formatCardNumber(card.numero)}` }),
                    ]),
                    closeBtn,
                ]),

                el("div", { class: "tj-drawer-body" }, [
                    el("div", { class: "tj-detail-status" }, [
                        el("span", { class: "tj-detail-chip", text: `Usos restantes: ${card.usos_restantes}` }),
                        el("span", { class: `tj-detail-chip${left > 0 ? " is-warning" : " is-ok"}`, text: left > 0 ? `Cooldown: ${formatCountdown(left)}` : "Disponible ahora" }),
                    ]),

                    section("Tarjeta", [
                        row("Número", card.numero, { mono: true }),
                        row("Vencimiento", card.vencimiento, { mono: true }),
                        row("CVV", card.cvv, { mono: true }),
                    ]),

                    section("Cuenta / Perfil", [
                        row("Titular", card.titular),
                        row("CVU/CBU", card.cvu, { mono: true }),
                        row("Banco", perfil.banco),
                        row("Emisor", card.emisor_nombre),
                        row("Dispositivo", perfil.dispositivo),
                    ]),

                    section("Datos sensibles", [
                        row("Correo", perfil.email, { masked: true }),
                        row("Contraseña", perfil.password, { masked: true }),
                        row("Ubicación", perfil.ubicacion),
                        row("N° de cuenta / ID", perfil.cuenta_id, { mono: true }),
                        row("Teléfono", perfil.telefono, { masked: true }),
                    ]),

                    el("div", { class: "tj-drawer-actions" }, [useBtn, cooldownBtn, editBtn, replaceBtn, deleteBtn]),
                ]),
            ]),
        ]);

        drawer.addEventListener("click", (e) => { if (e.target === drawer) close(); });
        document.body.appendChild(drawer);
        document.addEventListener("keydown", onKeydown);
        requestAnimationFrame(() => drawer && drawer.classList.add("is-open"));
    }

    // ── Edición de los datos de la tarjeta ───────────────────
    function openEdit(card) {
        const overlay = el("div", { class: "tj-modal-overlay", role: "dialog", "aria-modal": "true", "aria-label": "Editar tarjeta" });

        const numeroInput = el("input", { class: "tj-input tj-input--mono", type: "text", inputmode: "numeric", required: "", maxlength: "23", value: formatCardNumber(card.numero) });
        numeroInput.addEventListener("input", () => {
            numeroInput.value = formatCardNumber(numeroInput.value.replace(/\D/g, "").slice(0, 19));
        });
        const vencInput = el("input", { class: "tj-input tj-input--mono", type: "text", inputmode: "numeric", required: "", maxlength: "5", value: card.vencimiento });
        vencInput.addEventListener("input", () => {
            let v = vencInput.value.replace(/\D/g, "").slice(0, 4);
            if (v.length >= 3) v = v.slice(0, 2) + "/" + v.slice(2);
            vencInput.value = v;
        });
        const cvvInput = el("input", { class: "tj-input tj-input--mono", type: "text", inputmode: "numeric", required: "", maxlength: "4", value: card.cvv });
        cvvInput.addEventListener("input", () => { cvvInput.value = cvvInput.value.replace(/\D/g, "").slice(0, 4); });

        const saveBtn = el("button", { class: "tj-btn tj-btn--primary", type: "submit" }, [icon("check", 15), "Guardar"]);
        const cancelBtn = el("button", { class: "tj-btn", type: "button", text: "Cancelar" });
        cancelBtn.addEventListener("click", () => overlay.remove());

        const closeBtn = el("button", { class: "tj-icon-btn tj-icon-btn--sm", type: "button", "aria-label": "Cerrar" }, [icon("close", 16)]);
        closeBtn.addEventListener("click", () => overlay.remove());

        const form = el("form", { class: "tj-modal" }, [
            el("div", { class: "tj-modal-head" }, [el("h2", { text: "Editar tarjeta" }), closeBtn]),
            numeroInput,
            el("div", { class: "tj-form-row" }, [vencInput, cvvInput]),
            el("div", { class: "tj-modal-actions" }, [cancelBtn, saveBtn]),
        ]);

        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            try {
                const res = await TarjetasAPI.cards.update({
                    id: card.id,
                    numero: numeroInput.value.replace(/\D/g, ""),
                    vencimiento: vencInput.value.trim(),
                    cvv: cvvInput.value.trim(),
                });
                TarjetasCards.patchCard(res.card);
                overlay.remove();
                close();
                toast("Tarjeta actualizada", "success");
            } catch (err) {
                toast(err.message, "error");
            }
        });

        overlay.addEventListener("click", (e) => { if (e.target === overlay) overlay.remove(); });
        overlay.appendChild(form);
        document.body.appendChild(overlay);
        numeroInput.focus();
    }

    return Object.freeze({ open, close });
})();

"use strict";

/**
 * ============================================================
 * Tareas — Vista de Misiones (tareas + checklists)
 * ============================================================
 * Funciones de render puras. Expone el objeto global
 * `TareasMisiones`.
 * ============================================================
 */

const TareasMisiones = (() => {
    const { el, icon, initials, select, highlight, timeAgo } = TareasUI;

    const PRIORITY_LABEL = { alta: "Alta", media: "Media", baja: "Baja" };
    const STATUS_LABEL = { pendiente: "Pendiente", en_progreso: "En progreso", completada: "Completada" };

    const ACCENT_VARS = {
        violet: "var(--acc-violet)", blue: "var(--acc-blue)", green: "var(--acc-green)",
        amber: "var(--acc-amber)", rose: "var(--acc-rose)", cyan: "var(--acc-cyan)",
    };

    /** Etiqueta corta para `data-label`/anuncios accesibles. */
    const shortLabel = (text) => {
        const t = (text || "").trim().replace(/\s+/g, " ");
        return t.length > 60 ? `${t.slice(0, 57)}…` : t;
    };

    /** Asa de arrastre accesible (un <button> enfocable). */
    const dragHandle = (kind, label) => el("button", {
        class: "tk-drag-handle",
        type: "button",
        title: "Reordenar",
        "aria-label": `Reordenar ${kind}: ${label}. Pulsa Espacio o Enter para tomarlo.`,
    }, [icon("grip", 16)]);

    function assigneeNode(m, handlers) {
        const btn = el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm", title: "Asignar colaborador", "aria-label": "Asignar colaborador", onClick: () => handlers.onAssign(m) });
        if (m.colaborador_id && m.colaborador_name) {
            btn.appendChild(el("span", { class: "tk-assignee" }, [
                el("span", { class: "tk-avatar", style: { background: m.colaborador_color || "var(--tk-accent)" }, text: initials(m.colaborador_name) }),
                el("span", { text: m.colaborador_name }),
            ]));
        } else {
            btn.appendChild(el("span", { class: "tk-assignee" }, [icon("users", 15), el("span", { class: "tk-unassigned", text: "Sin asignar" })]));
        }
        return btn;
    }

    /**
     * Fila de checklist con edición inline del texto (P1). El texto es un botón
     * que, al activarse, se reemplaza por un input; Enter/blur confirma y
     * Escape cancela. Si no hubo cambios no se llama al servidor.
     */
    function checklistItemNode(item, handlers) {
        const done = item.is_done == 1;
        const row = el("div", {
            class: `tk-checklist-item${done ? " is-done" : ""}`,
            "data-id": String(item.id),
            "data-label": shortLabel(item.content),
        });

        const handle = dragHandle("ítem", shortLabel(item.content));
        const check = el("button", { class: "tk-mini-check", "aria-label": done ? "Desmarcar ítem" : "Marcar ítem", onClick: () => handlers.onToggleItem(item) }, [icon("check", 13)]);
        const textBtn = el("button", { class: "tk-item-text tk-item-text--btn", title: "Editar ítem", "aria-label": `Editar ítem: ${item.content}`, text: item.content });
        const del = el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm tk-item-del", title: "Eliminar ítem", "aria-label": "Eliminar ítem", onClick: () => handlers.onDeleteItem(item) }, [icon("trash", 14)]);

        textBtn.addEventListener("click", () => {
            const input = el("input", { class: "tk-item-edit", type: "text", maxlength: "255", "aria-label": "Editar ítem" });
            input.value = item.content;
            row.replaceChild(input, textBtn);
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);

            let settled = false;
            const restore = () => { if (!settled) { settled = true; if (input.parentNode === row) row.replaceChild(textBtn, input); } };
            const commit = () => {
                if (settled) return;
                const value = input.value.trim();
                if (value && value !== item.content) { settled = true; handlers.onUpdateItem(item, value); }
                else restore();
            };
            input.addEventListener("keydown", (e) => {
                if (e.key === "Enter") { e.preventDefault(); commit(); }
                else if (e.key === "Escape") { e.preventDefault(); restore(); }
            });
            input.addEventListener("blur", commit);
        });

        row.append(handle, check, textBtn, del);
        return row;
    }

    function checklistNode(m, handlers) {
        const wrap = el("div", { class: "tk-checklist" });

        (m.checklist || []).forEach((item) => wrap.appendChild(checklistItemNode(item, handlers)));

        const input = el("input", { type: "text", maxlength: "255", placeholder: "Agregar paso a la checklist…", "aria-label": "Nuevo ítem" });
        const submit = () => {
            const v = input.value.trim();
            if (v) { handlers.onAddItem(m.id, v); input.value = ""; }
        };
        input.addEventListener("keydown", (e) => { if (e.key === "Enter") submit(); });
        wrap.appendChild(el("div", { class: "tk-add-item" }, [
            input,
            el("button", { class: "tk-btn tk-btn--sm", type: "button", "aria-label": "Agregar ítem", onClick: submit }, [icon("plus", 15)]),
        ]));

        return wrap;
    }

    /** Fecha relativa: "completada hace X" si está hecha, si no "creada hace X". */
    function dateNode(m) {
        if (m.status === "completada" && m.completed_at) {
            return el("span", { class: "tk-mission-date is-done" }, [
                icon("check", 12), timeAgo(m.completed_at),
            ]);
        }
        return el("span", { class: "tk-mission-date" }, [
            icon("clock", 12), timeAgo(m.created_at),
        ]);
    }

    function statusSelect(m, handlers) {
        return select({
            ariaLabel: "Estado",
            value: m.status,
            className: "tk-select--pill",
            options: Object.entries(STATUS_LABEL).map(([value, label]) => ({ value, label })),
            onChange: (status) => handlers.onSetStatus(m, status),
        });
    }

    /** Chip "desde idea" para misiones nacidas de una idea convertida. */
    function originChip() {
        return el("span", { class: "tk-pill tk-pill--origin", title: "Nació de una idea convertida" }, [
            icon("bolt", 12, { filled: true }), el("span", { text: "desde idea" }),
        ]);
    }

    /**
     * Insignia de vencimiento: muestra la fecha relativa ("Hoy", "Mañana",
     * "Hace 2 días"…) con color según su urgencia (vencida/hoy/pronto). Las
     * misiones completadas o archivadas nunca se marcan como vencidas.
     * Devuelve null si la misión no tiene fecha.
     */
    function dueNode(m) {
        if (!m.due_date) return null;
        const active = m.status !== "completada" && m.status !== "archivada";
        const bucket = TareasDates.classify(m.due_date, { active });
        const label = TareasDates.formatRelative(m.due_date);
        const absolute = TareasDates.formatAbsolute(m.due_date);
        const title = active && bucket === "overdue"
            ? `Venció el ${absolute}`
            : `Vence el ${absolute}`;
        return el("span", { class: `tk-pill tk-due tk-due--${bucket}`, title }, [
            icon("calendar", 12), el("span", { text: label }),
        ]);
    }

    /**
     * Chip de detalle: superficie los contadores de comentarios y adjuntos
     * (hasta ahora invisibles pese a venir en el listado) y, al activarse,
     * abre el panel de detalle de la misión. Es un <button> accesible con un
     * nombre que verbaliza ambos conteos para lectores de pantalla.
     */
    function detailChip(m, handlers) {
        const comments = m.comments_count || 0;
        const attachments = m.attachments_count || 0;
        const tone = comments + attachments > 0 ? " tk-pill--detail-active" : "";
        return el("button", {
            class: `tk-pill tk-pill--detail${tone}`, type: "button",
            title: "Comentarios y adjuntos",
            "aria-label": `Detalle: ${comments} comentario${comments === 1 ? "" : "s"} y ${attachments} adjunto${attachments === 1 ? "" : "s"}`,
            onClick: () => handlers.onOpenDetail(m),
        }, [
            icon("comment", 12), el("span", { text: String(comments) }),
            icon("paperclip", 12), el("span", { text: String(attachments) }),
        ]);
    }

    /** Chip de recurrencia ("Diaria"/"Semanal"/"Mensual") si aplica. */
    function recurrenceNode(m) {
        const short = m.recurrence && TareasDates.RECURRENCE_SHORT[m.recurrence];
        if (!short) return null;
        return el("span", {
            class: "tk-pill tk-pill--recur",
            title: `Se repite: ${TareasDates.RECURRENCE_LABEL[m.recurrence].toLowerCase()}`,
        }, [icon("repeat", 12), el("span", { text: short })]);
    }

    /**
     * Casilla de selección múltiple (patrón checkbox accesible). Sólo se
     * renderiza en modo selección; al activarse delega en el orquestador.
     */
    function selectBox(m, selection) {
        const checked = selection.has(m.id);
        return el("button", {
            class: `tk-select-box${checked ? " is-checked" : ""}`,
            type: "button", role: "checkbox", "aria-checked": String(checked),
            "aria-label": `Seleccionar misión: ${shortLabel(m.title)}`,
            onClick: (e) => selection.onToggle(m.id, e),
        }, [icon("check", 13)]);
    }

    function card(m, handlers, query, selection = null) {
        const selecting = !!(selection && selection.mode);
        const done = m.status === "completada";
        const items = m.checklist || [];
        const doneCount = items.filter((i) => i.is_done == 1).length;
        const pct = items.length ? Math.round((doneCount / items.length) * 100) : 0;
        const accent = ACCENT_VARS[m.accent] || ACCENT_VARS.violet;

        const title = el("h3", { class: "tk-mission-title" });
        title.appendChild(highlight(m.title, query));
        let desc = null;
        if (m.description) {
            desc = el("p", { class: "tk-mission-desc" });
            desc.appendChild(highlight(m.description, query));
        }

        const meta = el("div", { class: "tk-mission-meta" }, [
            el("span", { class: `tk-pill tk-pill--${m.priority}`, text: PRIORITY_LABEL[m.priority] || m.priority }),
            statusSelect(m, handlers),
            dueNode(m),
            recurrenceNode(m),
            assigneeNode(m, handlers),
            detailChip(m, handlers),
            m.origin_idea_id ? originChip() : null,
        ]);

        const tagList = TareasTags.chipList(m.tags, { onClick: handlers.onTagClick });

        // El asa se renderiza siempre; su visibilidad la controla la clase
        // `.is-sortable` del contenedor vía CSS. Mantenerla fuera de la firma del
        // nodo evita reconstruir tarjetas (y saltos visuales) al alternar el modo.
        const head = el("div", { class: "tk-mission-head" }, [
            selecting ? selectBox(m, selection) : null,
            dragHandle("misión", shortLabel(m.title)),
            el("button", { class: "tk-check", title: done ? "Reabrir" : "Completar", "aria-label": "Completar", onClick: () => handlers.onToggleDone(m) }, [icon("check", 13)]),
            el("div", { class: "tk-mission-main" }, [title, desc, meta, tagList]),
            el("div", { class: "tk-mission-actions" }, [
                dateNode(m),
                el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm", title: "Editar", "aria-label": "Editar", onClick: () => handlers.onEdit(m) }, [icon("edit", 15)]),
                el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm", title: "Duplicar", "aria-label": "Duplicar misión", onClick: () => handlers.onDuplicate(m) }, [icon("copy", 15)]),
                el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm tk-btn--danger", title: "Archivar", "aria-label": "Archivar", onClick: () => handlers.onArchive(m) }, [icon("trash", 15)]),
            ]),
        ]);

        const article = el("article", {
            class: `tk-mission${done ? " is-done" : ""}${selecting && selection.has(m.id) ? " is-selected" : ""}`,
            style: { "--mission-accent": accent },
            "data-id": String(m.id),
            "data-label": shortLabel(m.title),
        }, [head]);
        if (items.length) {
            article.appendChild(el("div", { class: "tk-progress-row" }, [
                el("div", { class: "tk-progress" }, [el("span", { style: { width: pct + "%" } })]),
                el("span", { class: "tk-progress-text", text: `${doneCount}/${items.length} · ${pct}%` }),
            ]));
        }
        article.appendChild(checklistNode(m, handlers));
        return article;
    }

    function emptyState() {
        return el("div", { class: "tk-empty" }, [
            icon("target", 40),
            el("h3", { text: "Sin misiones activas" }),
            el("p", { text: "Crea una misión o convierte una idea para empezar." }),
        ]);
    }

    function noResults() {
        return el("div", { class: "tk-empty tk-empty--filtered" }, [
            icon("search", 36),
            el("h3", { text: "Sin coincidencias" }),
            el("p", { text: "Ninguna misión coincide con el filtro o la búsqueda." }),
        ]);
    }

    /**
     * Firma de render de una misión: resume todo lo que afecta su DOM (datos
     * + opciones de presentación). Si no cambia entre renders, el
     * reconciliador reutiliza el nodo intacto (sin parpadeo ni pérdida de
     * foco/scroll).
     */
    function signature(m, { query, selecting, selected }) {
        return JSON.stringify({
            t: m.title, d: m.description || "", pr: m.priority, st: m.status,
            ac: m.accent || "", dd: m.due_date || "", rc: m.recurrence || "",
            ci: m.colaborador_id || 0, cn: m.colaborador_name || "", cc: m.colaborador_color || "",
            cm: m.comments_count || 0, at: m.attachments_count || 0, oi: m.origin_idea_id || 0,
            ca: m.completed_at || "", cr: m.created_at || "",
            cl: (m.checklist || []).map((i) => [i.id, i.content, i.is_done, i.sort_order]),
            tg: (m.tags || []).map((t) => [t.id, t.name, t.color]),
            q: query, sel: selecting, on: selected,
        });
    }

    /**
     * Plan de render incremental: lista de unidades { key, sig, create } que
     * consume `TareasReconcile`. Intercala las etiquetas de grupo (cuando hay
     * más de un grupo) como unidades propias, con clave por índice para
     * garantizar identidad estable aunque dos grupos compartan rótulo.
     */
    function plan(flat, handlers, { query = "", selection = null, groups = null } = {}) {
        const selecting = !!(selection && selection.mode);
        const units = [];
        const pushCard = (m) => units.push({
            key: `m:${m.id}`,
            sig: signature(m, { query, selecting, selected: selecting && selection.has(m.id) }),
            create: () => card(m, handlers, query, selection),
        });
        if (groups && groups.length) {
            const showLabels = groups.length > 1;
            groups.forEach((g, gi) => {
                if (showLabels) {
                    units.push({
                        key: `g:${gi}`, sig: `g:${g.label}`,
                        create: () => el("div", { class: "tk-group-label", text: g.label }),
                    });
                }
                g.items.forEach(pushCard);
            });
        } else {
            flat.forEach(pushCard);
        }
        return units;
    }

    /**
     * Render del listado de misiones (ya filtrado/ordenado por el llamador).
     * `groups`, si se provee, es una lista de { label, items } y se muestran
     * encabezados de sección (sólo cuando hay más de un grupo).
     */
    function render(misiones, handlers, { query = "", filtered = false, groups = null, sortable = false, selection = null } = {}) {
        if (!misiones.length) return filtered ? noResults() : emptyState();
        const list = el("div", { class: `tk-missions${sortable ? " is-sortable" : ""}` });
        if (groups && groups.length) {
            const showLabels = groups.length > 1;
            groups.forEach((g) => {
                if (showLabels) list.appendChild(el("div", { class: "tk-group-label", text: g.label }));
                g.items.forEach((m) => list.appendChild(card(m, handlers, query, selection)));
            });
        } else {
            misiones.forEach((m) => list.appendChild(card(m, handlers, query, selection)));
        }
        return list;
    }

    return Object.freeze({ render, plan, emptyState, noResults, STATUS_LABEL, PRIORITY_LABEL });
})();

"use strict";

/**
 * ============================================================
 * Tareas — Vista de Ideas (captura rápida)
 * ============================================================
 * Funciones de render puras: reciben datos + callbacks y
 * devuelven nodos DOM. Expone el objeto global `TareasIdeas`.
 * ============================================================
 */

const TareasIdeas = (() => {
    const { el, icon, timeAgo, highlight, menu } = TareasUI;

    const ACCENT_VARS = {
        violet: "var(--acc-violet)", blue: "var(--acc-blue)", green: "var(--acc-green)",
        amber: "var(--acc-amber)", rose: "var(--acc-rose)", cyan: "var(--acc-cyan)",
    };

    /** Etiqueta corta para `data-label`/anuncios accesibles. */
    const shortLabel = (text) => {
        const t = (text || "").trim().replace(/\s+/g, " ");
        return t.length > 60 ? `${t.slice(0, 57)}…` : t;
    };

    function card(idea, handlers, query) {
        const accent = ACCENT_VARS[idea.accent] || ACCENT_VARS.violet;

        // El asa se renderiza siempre; su visibilidad/funcionalidad la controla
        // la clase `.is-sortable` del grid vía CSS. Mantenerla fuera de la firma
        // del nodo evita reconstruir tarjetas (y con ello saltos visuales) cuando
        // el modo "ordenable" cambia al pasar de 2→1 ideas o al filtrar.
        const handle = el("button", {
            class: "tk-drag-handle",
            type: "button",
            title: "Reordenar",
            "aria-label": `Reordenar idea: ${shortLabel(idea.content)}. Pulsa Espacio o Enter para tomarla.`,
        }, [icon("grip", 16)]);

        const pinned = idea.is_pinned == 1;
        // Acciones primarias inline (ancho fijo y predecible) + secundarias en
        // un menú de desbordamiento, para que el clúster nunca crezca ni empuje
        // la fecha aunque se sumen acciones en el futuro.
        const actions = el("div", { class: "tk-idea-actions", role: "group", "aria-label": "Acciones de la idea" }, [
            el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm", type: "button", title: "Convertir en misión", "aria-label": "Convertir en misión", onClick: () => handlers.onConvert(idea) }, [icon("arrow", 15)]),
            el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm", type: "button", title: pinned ? "Desfijar" : "Fijar", "aria-label": pinned ? "Desfijar" : "Fijar", onClick: () => handlers.onPin(idea) }, [icon(pinned ? "unpin" : "pin", 15)]),
            menu({
                ariaLabel: "Más acciones",
                align: "end",
                triggerClass: "tk-btn tk-btn--ghost tk-btn--sm",
                items: [
                    { label: "Copiar al portapapeles", icon: "clipboard", onClick: () => handlers.onCopy(idea) },
                    { label: "Duplicar", icon: "copy", onClick: () => handlers.onDuplicate(idea) },
                    { label: "Editar", icon: "edit", onClick: () => handlers.onEdit(idea) },
                    { label: "Archivar", icon: "trash", danger: true, onClick: () => handlers.onArchive(idea) },
                ],
            }),
        ]);

        const foot = el("div", { class: "tk-idea-foot" }, [
            handle,
            el("span", { class: "tk-idea-date" }, [timeAgo(idea.created_at)]),
            actions,
        ]);

        const textNode = el("p", { class: "tk-idea-text" });
        textNode.appendChild(highlight(idea.content, query));

        const tagList = TareasTags.chipList(idea.tags, { onClick: handlers.onTagClick });

        return el("article", {
            class: `tk-idea${idea.is_pinned == 1 ? " is-pinned" : ""}`,
            style: { "--idea-accent": accent },
            "data-id": String(idea.id),
            "data-label": shortLabel(idea.content),
        }, [
            el("span", { class: "tk-idea-pin" }, [icon("pin", 14)]),
            textNode,
            tagList,
            foot,
        ]);
    }

    function emptyState() {
        return el("div", { class: "tk-empty" }, [
            icon("bolt", 40),
            el("h3", { text: "Sin ideas todavía" }),
            el("p", { text: "Escribe arriba y guarda tu primera idea en segundos." }),
        ]);
    }

    function noResults() {
        return el("div", { class: "tk-empty tk-empty--filtered" }, [
            icon("search", 36),
            el("h3", { text: "Sin coincidencias" }),
            el("p", { text: "Ninguna idea coincide con tu búsqueda." }),
        ]);
    }

    /**
     * Firma de render de una idea: resume todo lo que afecta su DOM. Si no
     * cambia entre renders, el reconciliador reutiliza el nodo intacto.
     */
    function signature(idea, query) {
        return JSON.stringify({
            c: idea.content, a: idea.accent, p: idea.is_pinned,
            t: (idea.tags || []).map((t) => [t.id, t.name, t.color]),
            q: query,
        });
    }

    /**
     * Plan de render incremental: lista de unidades { key, sig, create } que
     * consume `TareasReconcile`. La fábrica `create` es perezosa (sólo se
     * invoca en altas o cambios de contenido).
     */
    function plan(ideas, handlers, { query = "" } = {}) {
        return ideas.map((idea) => ({
            key: `i:${idea.id}`,
            sig: signature(idea, query),
            create: () => card(idea, handlers, query),
        }));
    }

    /** Render del listado de ideas (ya filtrado por el llamador). */
    function render(ideas, handlers, { query = "", filtered = false, sortable = false } = {}) {
        if (!ideas.length) return filtered ? noResults() : emptyState();
        const grid = el("div", { class: `tk-ideas-grid${sortable ? " is-sortable" : ""}` });
        ideas.forEach((idea) => grid.appendChild(card(idea, handlers, query)));
        return grid;
    }

    return Object.freeze({ render, plan, emptyState, noResults, ACCENT_VARS });
})();

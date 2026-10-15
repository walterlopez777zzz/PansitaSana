"use strict";

/**
 * ============================================================
 * Tareas — Detalle de misión (comentarios + adjuntos)
 * ============================================================
 * Panel lateral accesible que expone dos features del backend
 * que hasta ahora no tenían UI: los comentarios y los adjuntos
 * de una misión. Ambas listas se cargan de forma perezosa al
 * abrir el panel (no se traen con el listado de misiones, que
 * sólo recibe los contadores) para mantener la carga inicial
 * liviana y escalar con misiones muy comentadas/adjuntadas.
 *
 * El módulo es agnóstico del estado de la app: recibe la misión
 * y un callback `onChanged` que el orquestador usa para
 * resincronizar los contadores de la tarjeta tras cada cambio.
 * Toda la base se toca exclusivamente a través de `TareasAPI`.
 * Expone el objeto global `TareasMisionDetail`.
 * ============================================================
 */

const TareasMisionDetail = (() => {
    const { el, icon, toast, confirmDialog, timeAgo, autoGrow, charCounter, trapFocus, announce } = TareasUI;

    const COMMENT_MAXLEN = 4000;
    const ATTACH_MAX_BYTES = 10 * 1024 * 1024; // 10 MiB (espejo del backend)

    // Extensiones permitidas (espejo de TAREAS_ATTACH_TYPES en el backend):
    // validar en cliente evita una subida condenada al rechazo del servidor.
    const ALLOWED_EXTENSIONS = [
        "png", "jpg", "jpeg", "gif", "webp", "pdf", "txt", "md",
        "csv", "json", "doc", "docx", "xls", "xlsx", "zip",
    ];
    const IMAGE_EXTENSIONS = ["png", "jpg", "jpeg", "gif", "webp"];

    const extensionOf = (name) => {
        const dot = String(name || "").lastIndexOf(".");
        return dot >= 0 ? name.slice(dot + 1).toLowerCase() : "";
    };

    /** Tamaño legible (B, KB, MB) con un decimal cuando aporta. */
    function formatSize(bytes) {
        const n = Number(bytes) || 0;
        if (n < 1024) return `${n} B`;
        if (n < 1024 * 1024) return `${(n / 1024).toFixed(n < 10240 ? 1 : 0)} KB`;
        return `${(n / (1024 * 1024)).toFixed(1)} MB`;
    }

    /**
     * Backdrop + panel accesible (focus-trap, Escape y restitución del foco).
     * Mismo contrato que el gestor de etiquetas para mantener coherencia.
     */
    function panel(node) {
        const backdrop = el("div", { class: "tk-modal-backdrop tk-detail-backdrop" });
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
        releaseTrap = trapFocus(node);
        requestAnimationFrame(() => backdrop.classList.add("is-open"));
        return { backdrop, close };
    }

    /** Bloque de estado (cargando / vacío / error) reutilizable por sección. */
    function statusNote(text, { error = false } = {}) {
        return el("p", { class: `tk-detail-note${error ? " is-error" : ""}`, role: error ? "alert" : undefined, text });
    }

    // ── Sección de comentarios ───────────────────────────────
    /**
     * Controla la lista de comentarios y su composer. Devuelve un objeto con
     * el nodo raíz y `load()` para la carga perezosa inicial.
     */
    function commentsSection(misionId, notifyChange) {
        const list = el("div", { class: "tk-comments-list" });

        const textarea = el("textarea", {
            class: "tk-comment-input", rows: "2", maxlength: String(COMMENT_MAXLEN),
            placeholder: "Escribe un comentario…", "aria-label": "Nuevo comentario",
        });
        autoGrow(textarea);
        const counter = charCounter(textarea, COMMENT_MAXLEN);
        const sendBtn = el("button", { class: "tk-btn tk-btn--primary tk-btn--sm", type: "button", disabled: true }, [
            icon("comment", 15), el("span", { text: "Comentar" }),
        ]);

        const syncDisabled = () => { sendBtn.disabled = textarea.value.trim() === ""; };
        textarea.addEventListener("input", syncDisabled);
        textarea.addEventListener("keydown", (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === "Enter") { e.preventDefault(); submit(); }
        });

        const composer = el("div", { class: "tk-comment-composer" }, [
            textarea,
            el("div", { class: "tk-comment-composer-bar" }, [
                el("span", { class: "tk-hint", text: "Ctrl + Enter para enviar" }),
                counter,
                sendBtn,
            ]),
        ]);

        const commentNode = (c) => {
            const del = el("button", {
                class: "tk-btn tk-btn--ghost tk-btn--sm tk-btn--danger",
                title: "Eliminar comentario", "aria-label": "Eliminar comentario",
                onClick: async () => {
                    if (!(await confirmDialog("¿Eliminar este comentario?", { danger: true, confirmLabel: "Eliminar" }))) return;
                    try {
                        await TareasAPI.comments.delete(c.id);
                        notifyChange();
                        await load();
                        toast("Comentario eliminado.", "success");
                    } catch (err) { toast(err.message || "No se pudo eliminar.", "error"); }
                },
            }, [icon("trash", 14)]);

            return el("article", { class: "tk-comment" }, [
                el("div", { class: "tk-comment-head" }, [
                    timeAgo(c.created_at, { className: "tk-comment-time" }),
                    del,
                ]),
                el("p", { class: "tk-comment-body", text: c.body }),
            ]);
        };

        const renderList = (comments) => {
            list.replaceChildren();
            if (!comments.length) {
                list.appendChild(statusNote("Todavía no hay comentarios. Sé el primero en anotar algo."));
                return;
            }
            comments.forEach((c) => list.appendChild(commentNode(c)));
        };

        async function load() {
            list.replaceChildren(statusNote("Cargando comentarios…"));
            try {
                const comments = (await TareasAPI.comments.list(misionId)).comments || [];
                renderList(comments);
            } catch (err) {
                list.replaceChildren(statusNote(err.message || "No se pudieron cargar los comentarios.", { error: true }));
            }
        }

        async function submit() {
            const text = textarea.value.trim();
            if (!text) return;
            sendBtn.disabled = true;
            try {
                await TareasAPI.comments.create(misionId, text);
                textarea.value = "";
                textarea.dispatchEvent(new Event("input"));
                notifyChange();
                await load();
                announce("Comentario agregado.");
            } catch (err) {
                toast(err.message || "No se pudo comentar.", "error");
            } finally { syncDisabled(); }
        }
        sendBtn.addEventListener("click", submit);

        const root = el("section", { class: "tk-detail-section", "aria-label": "Comentarios" }, [
            el("h3", { class: "tk-detail-subtitle" }, [icon("comment", 16), el("span", { text: "Comentarios" })]),
            list,
            composer,
        ]);
        return { root, load };
    }

    // ── Sección de adjuntos ──────────────────────────────────
    /**
     * Controla la lista de adjuntos, la subida (clic o arrastrar-soltar) y el
     * borrado. Valida tipo y tamaño en cliente antes de enviar.
     */
    function attachmentsSection(misionId, notifyChange) {
        const list = el("div", { class: "tk-attach-list" });

        const fileInput = el("input", {
            type: "file", class: "tk-sr-only", multiple: true,
            accept: ALLOWED_EXTENSIONS.map((e) => "." + e).join(","),
            "aria-hidden": "true", tabindex: "-1",
        });

        const dropzone = el("button", {
            class: "tk-dropzone", type: "button",
            "aria-label": "Subir adjunto: elige un archivo o arrástralo aquí",
        }, [
            icon("upload", 22),
            el("span", { class: "tk-dropzone-title", text: "Sube un archivo" }),
            el("span", { class: "tk-dropzone-hint", text: "Haz clic para elegir, o arrastra y suelta · máx. 10 MB" }),
        ]);
        dropzone.addEventListener("click", () => fileInput.click());

        // Arrastrar y soltar con realimentación visual accesible.
        const setDragging = (on) => dropzone.classList.toggle("is-dragging", on);
        ["dragenter", "dragover"].forEach((ev) => dropzone.addEventListener(ev, (e) => { e.preventDefault(); setDragging(true); }));
        ["dragleave", "dragend"].forEach((ev) => dropzone.addEventListener(ev, () => setDragging(false)));
        dropzone.addEventListener("drop", (e) => {
            e.preventDefault();
            setDragging(false);
            if (e.dataTransfer && e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
        });
        fileInput.addEventListener("change", () => {
            if (fileInput.files.length) uploadFiles(fileInput.files);
            fileInput.value = ""; // permite resubir el mismo archivo
        });

        const attachmentNode = (a) => {
            const ext = extensionOf(a.original_name);
            const isImage = IMAGE_EXTENSIONS.includes(ext);
            const del = el("button", {
                class: "tk-btn tk-btn--ghost tk-btn--sm tk-btn--danger",
                title: "Eliminar adjunto", "aria-label": `Eliminar adjunto ${a.original_name}`,
                onClick: async () => {
                    if (!(await confirmDialog(`¿Eliminar "${a.original_name}"?`, { danger: true, confirmLabel: "Eliminar" }))) return;
                    try {
                        await TareasAPI.attachments.delete(a.id);
                        notifyChange();
                        await load();
                        toast("Adjunto eliminado.", "success");
                    } catch (err) { toast(err.message || "No se pudo eliminar.", "error"); }
                },
            }, [icon("trash", 14)]);

            // La descarga va por GET tras la sesión (cookie same-origin); el
            // backend la sirve como adjunto con nosniff.
            const link = el("a", {
                class: "tk-attach-name", href: TareasAPI.attachments.downloadUrl(a.id),
                target: "_blank", rel: "noopener", download: a.original_name,
                title: `Descargar ${a.original_name}`,
            }, [el("span", { class: "tk-attach-filename", text: a.original_name })]);

            return el("div", { class: "tk-attach" }, [
                el("span", { class: "tk-attach-icon", "aria-hidden": "true" }, [icon(isImage ? "layout" : "paperclip", 16)]),
                el("div", { class: "tk-attach-main" }, [
                    link,
                    el("span", { class: "tk-attach-meta" }, [
                        el("span", { text: formatSize(a.size) }),
                        el("span", { class: "tk-attach-dot", "aria-hidden": "true", text: "·" }),
                        timeAgo(a.created_at),
                    ]),
                ]),
                del,
            ]);
        };

        const renderList = (attachments) => {
            list.replaceChildren();
            if (!attachments.length) {
                list.appendChild(statusNote("Sin adjuntos por ahora."));
                return;
            }
            attachments.forEach((a) => list.appendChild(attachmentNode(a)));
        };

        async function load() {
            list.replaceChildren(statusNote("Cargando adjuntos…"));
            try {
                const attachments = (await TareasAPI.attachments.list(misionId)).attachments || [];
                renderList(attachments);
            } catch (err) {
                list.replaceChildren(statusNote(err.message || "No se pudieron cargar los adjuntos.", { error: true }));
            }
        }

        /** Valida y sube los archivos elegidos uno por uno, con feedback. */
        async function uploadFiles(fileList) {
            const files = Array.from(fileList);
            dropzone.disabled = true;
            try {
                for (const file of files) {
                    const ext = extensionOf(file.name);
                    if (!ALLOWED_EXTENSIONS.includes(ext)) {
                        toast(`"${file.name}": tipo de archivo no permitido.`, "error");
                        continue;
                    }
                    if (file.size <= 0 || file.size > ATTACH_MAX_BYTES) {
                        toast(`"${file.name}": supera el máximo de 10 MB o está vacío.`, "error");
                        continue;
                    }
                    try {
                        await TareasAPI.attachments.upload(misionId, file);
                        toast(`"${file.name}" subido.`, "success");
                    } catch (err) {
                        toast(err.message || `No se pudo subir "${file.name}".`, "error");
                    }
                }
                notifyChange();
                await load();
            } finally { dropzone.disabled = false; }
        }

        const root = el("section", { class: "tk-detail-section", "aria-label": "Adjuntos" }, [
            el("h3", { class: "tk-detail-subtitle" }, [icon("paperclip", 16), el("span", { text: "Adjuntos" })]),
            dropzone,
            fileInput,
            list,
        ]);
        return { root, load };
    }

    /**
     * Abre el panel de detalle de una misión. `mision` aporta id y título;
     * `onChanged` se invoca tras cada alta/baja para que el orquestador
     * resincronice los contadores de la tarjeta.
     *
     * @param {{id:number, title:string}} mision
     * @param {{ onChanged?: () => void }} [opts]
     */
    function open(mision, { onChanged } = {}) {
        const notifyChange = typeof onChanged === "function" ? onChanged : () => {};

        const comments = commentsSection(mision.id, notifyChange);
        const attachments = attachmentsSection(mision.id, notifyChange);

        const titleId = "tk-detail-title";
        const closeBtn = el("button", {
            class: "tk-icon-btn", type: "button", title: "Cerrar", "aria-label": "Cerrar detalle",
        }, [icon("close", 18)]);

        const node = el("div", {
            class: "tk-modal tk-detail", role: "dialog", "aria-modal": "true", "aria-labelledby": titleId,
        }, [
            el("div", { class: "tk-detail-header" }, [
                el("div", { class: "tk-detail-titlewrap" }, [
                    el("p", { class: "tk-detail-eyebrow", text: "Misión" }),
                    el("h2", { id: titleId, class: "tk-detail-title", text: mision.title || "Detalle" }),
                ]),
                closeBtn,
            ]),
            el("div", { class: "tk-detail-body" }, [comments.root, attachments.root]),
        ]);

        const ctrl = panel(node);
        closeBtn.addEventListener("click", ctrl.close);

        // Carga perezosa en paralelo de ambas listas.
        comments.load();
        attachments.load();
        requestAnimationFrame(() => closeBtn.focus());
    }

    return Object.freeze({ open });
})();

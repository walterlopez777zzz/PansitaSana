"use strict";

/**
 * ============================================================
 * Tareas — Exportar / Importar
 * ============================================================
 * Expone en la UI la feature de respaldo del backend:
 *   • Exportar un backup JSON completo o un CSV (misiones/ideas).
 *   • Importar un backup JSON (aditivo: nunca borra nada).
 *
 * Las descargas viajan por GET tras la sesión (cookie
 * same-origin); la importación lee el archivo en el cliente,
 * valida que sea JSON y delega en `TareasAPI`. Expone el objeto
 * global `TareasDataIO`.
 * ============================================================
 */

const TareasDataIO = (() => {
    const { el, icon, toast, confirmDialog, trapFocus } = TareasUI;

    const IMPORT_MAX_BYTES = 20 * 1024 * 1024; // backup de texto: límite defensivo

    /** Dispara la descarga de una URL del mismo origen sin abandonar la SPA. */
    function triggerDownload(url) {
        const a = el("a", { href: url, rel: "noopener" });
        a.style.display = "none";
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    /** Backdrop + panel accesible (focus-trap, Escape, restitución del foco). */
    function panel(node) {
        const backdrop = el("div", { class: "tk-modal-backdrop" });
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

    /** Fila de acción de exportación (título + descripción + botón). */
    function exportRow({ title, hint, label, iconName, onClick }) {
        const btn = el("button", { class: "tk-btn tk-btn--sm", type: "button", onClick }, [
            icon(iconName, 15), el("span", { text: label }),
        ]);
        return el("div", { class: "tk-io-row" }, [
            el("div", { class: "tk-io-info" }, [
                el("span", { class: "tk-io-name", text: title }),
                el("span", { class: "tk-io-hint", text: hint }),
            ]),
            btn,
        ]);
    }

    /** Resumen legible de lo importado a partir del objeto del backend. */
    function summaryPhrase(imported) {
        const map = [
            ["misiones", "misión", "misiones"],
            ["ideas", "idea", "ideas"],
            ["tags", "etiqueta", "etiquetas"],
            ["templates", "plantilla", "plantillas"],
        ];
        const parts = map
            .map(([key, sing, plur]) => [Number(imported[key] || 0), sing, plur])
            .filter(([n]) => n > 0)
            .map(([n, sing, plur]) => `${n} ${n === 1 ? sing : plur}`);
        return parts.length ? parts.join(", ") : "nada nuevo";
    }

    /**
     * Abre el menú de exportar/importar.
     * @param {{ onImported?: () => void }} [opts] callback para refrescar la app.
     */
    function open({ onImported } = {}) {
        const notifyImported = typeof onImported === "function" ? onImported : () => {};

        const fileInput = el("input", {
            type: "file", accept: "application/json,.json", class: "tk-sr-only",
            "aria-hidden": "true", tabindex: "-1",
        });

        const importStatus = el("p", { class: "tk-detail-note", "aria-live": "polite" });

        const importBtn = el("button", { class: "tk-btn tk-btn--sm", type: "button" }, [
            icon("upload", 15), el("span", { text: "Elegir archivo…" }),
        ]);
        importBtn.addEventListener("click", () => fileInput.click());

        async function handleImport(file) {
            if (file.size > IMPORT_MAX_BYTES) {
                importStatus.textContent = "El archivo supera el límite de 20 MB.";
                importStatus.classList.add("is-error");
                return;
            }
            importStatus.classList.remove("is-error");
            importStatus.textContent = `Leyendo "${file.name}"…`;

            let backup;
            try {
                backup = JSON.parse(await file.text());
            } catch {
                importStatus.textContent = "El archivo no es un JSON válido.";
                importStatus.classList.add("is-error");
                return;
            }
            if (!backup || typeof backup !== "object") {
                importStatus.textContent = "El backup no tiene el formato esperado.";
                importStatus.classList.add("is-error");
                return;
            }

            const ok = await confirmDialog(
                "La importación es aditiva: agrega el contenido del backup sin borrar lo existente. ¿Continuar?",
                { confirmLabel: "Importar" },
            );
            if (!ok) { importStatus.textContent = ""; return; }

            importBtn.disabled = true;
            importStatus.textContent = "Importando…";
            try {
                const res = await TareasAPI.data.import(backup);
                const phrase = summaryPhrase(res.imported || {});
                toast(`Importación completada: ${phrase}.`, "success");
                notifyImported();
                ctrl.close();
            } catch (err) {
                importStatus.textContent = err.message || "No se pudo importar el backup.";
                importStatus.classList.add("is-error");
            } finally { importBtn.disabled = false; }
        }

        fileInput.addEventListener("change", () => {
            if (fileInput.files.length) handleImport(fileInput.files[0]);
            fileInput.value = "";
        });

        const node = el("div", { class: "tk-modal", role: "dialog", "aria-modal": "true", "aria-labelledby": "tk-io-title" }, [
            el("h2", { id: "tk-io-title", text: "Exportar / Importar" }),

            el("section", { class: "tk-io-section", "aria-label": "Exportar" }, [
                el("h3", { class: "tk-detail-subtitle" }, [icon("download", 16), el("span", { text: "Exportar" })]),
                exportRow({
                    title: "Backup completo", hint: "JSON · ideas, misiones, etiquetas y plantillas", label: "JSON", iconName: "download",
                    onClick: () => triggerDownload(TareasAPI.data.exportUrl("json")),
                }),
                exportRow({
                    title: "Misiones", hint: "CSV · una fila por misión", label: "CSV", iconName: "download",
                    onClick: () => triggerDownload(TareasAPI.data.exportUrl("csv", "misiones")),
                }),
                exportRow({
                    title: "Ideas", hint: "CSV · una fila por idea", label: "CSV", iconName: "download",
                    onClick: () => triggerDownload(TareasAPI.data.exportUrl("csv", "ideas")),
                }),
            ]),

            el("section", { class: "tk-io-section", "aria-label": "Importar" }, [
                el("h3", { class: "tk-detail-subtitle" }, [icon("upload", 16), el("span", { text: "Importar" })]),
                el("div", { class: "tk-io-row" }, [
                    el("div", { class: "tk-io-info" }, [
                        el("span", { class: "tk-io-name", text: "Restaurar backup JSON" }),
                        el("span", { class: "tk-io-hint", text: "Aditivo: no borra lo existente" }),
                    ]),
                    importBtn,
                ]),
                fileInput,
                importStatus,
            ]),

            el("div", { class: "tk-modal-actions" }, [
                el("button", { class: "tk-btn tk-btn--ghost", type: "button", text: "Cerrar", onClick: () => ctrl.close() }),
            ]),
        ]);

        const ctrl = panel(node);
        requestAnimationFrame(() => node.querySelector("button, a").focus());
    }

    return Object.freeze({ open });
})();

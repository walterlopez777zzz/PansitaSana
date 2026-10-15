"use strict";

/**
 * ============================================================
 * Tareas — Capa de API
 * ============================================================
 * Comunicación HTTP centralizada. Inyecta el token CSRF en cada
 * mutación y normaliza los errores en un único pipeline.
 * Expone el objeto global `TareasAPI`.
 * ============================================================
 */

const TareasAPI = (() => {
    // Rutas relativas a /tareas/ (el módulo se sirve bajo esa ruta).
    const ENDPOINTS = {
        auth:          "api/auth.php",
        ideas:         "api/ideas.php",
        misiones:      "api/misiones.php",
        colaboradores: "api/colaboradores.php",
        tags:          "api/tags.php",
        templates:     "api/templates.php",
        comments:      "api/comments.php",
        attachments:   "api/attachments.php",
        data:          "api/data.php",
    };

    let csrfToken = null;

    const setCsrf = (token) => { csrfToken = token || null; };
    const getCsrf = () => csrfToken;

    /**
     * Identificador de cliente estable para esta pestaña. Viaja en cada
     * mutación (cabecera `X-Client-Id`) para que el backend lo registre en
     * la bitácora de cambios; el canal de tiempo real lo usa luego para
     * suprimir el "eco" (no repintamos lo que nosotros mismos provocamos,
     * que ya se aplicó de forma optimista). Se genera una vez por carga.
     */
    const clientId = (() => {
        try {
            if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
                return crypto.randomUUID();
            }
            if (typeof crypto !== "undefined" && typeof crypto.getRandomValues === "function") {
                const bytes = crypto.getRandomValues(new Uint8Array(16));
                return Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
            }
        } catch {
            // Entornos sin Web Crypto: caemos a un id no criptográfico.
        }
        return `c${Date.now().toString(16)}${Math.random().toString(16).slice(2, 10)}`;
    })();

    const getClientId = () => clientId;

    /** Construye la URL absoluta-relativa de un endpoint+acción (+query extra). */
    function urlFor(endpoint, action, params = {}) {
        const usp = new URLSearchParams({ action, ...params });
        return `${ENDPOINTS[endpoint]}?${usp.toString()}`;
    }

    async function request(endpoint, action, { method = "GET", body = null, params = {} } = {}) {
        const url = urlFor(endpoint, action, params);
        const headers = {};
        const options = { method, headers, credentials: "same-origin" };

        if (method !== "GET") {
            headers["Content-Type"] = "application/json";
            if (csrfToken) headers["X-CSRF-Token"] = csrfToken;
            headers["X-Client-Id"] = clientId;
            options.body = JSON.stringify(body || {});
        }

        let response;
        try {
            response = await fetch(url, options);
        } catch {
            throw new ApiError("Sin conexión con el servidor.", 0);
        }

        let data = {};
        try {
            data = await response.json();
        } catch {
            // respuesta no-JSON (ej. error 500 del servidor)
        }

        if (!response.ok || data.status === "error") {
            throw new ApiError(data.message || `Error HTTP ${response.status}`, response.status);
        }

        return data;
    }

    /**
     * Envío multipart (subida de archivos). No se fija Content-Type a mano:
     * el navegador agrega el boundary correcto del FormData. Igual inyecta el
     * token CSRF por cabecera y normaliza errores como `request`.
     */
    async function upload(endpoint, action, formData) {
        const headers = { "X-Client-Id": clientId };
        if (csrfToken) headers["X-CSRF-Token"] = csrfToken;

        let response;
        try {
            response = await fetch(urlFor(endpoint, action), {
                method: "POST", headers, body: formData, credentials: "same-origin",
            });
        } catch {
            throw new ApiError("Sin conexión con el servidor.", 0);
        }

        let data = {};
        try { data = await response.json(); } catch { /* no-JSON */ }

        if (!response.ok || data.status === "error") {
            throw new ApiError(data.message || `Error HTTP ${response.status}`, response.status);
        }
        return data;
    }

    class ApiError extends Error {
        constructor(message, status) {
            super(message);
            this.name = "ApiError";
            this.status = status;
        }
    }

    // ── Métodos por recurso ──────────────────────────────────
    const auth = {
        status: () => request("auth", "status"),
        login:  (pin) => request("auth", "login", { method: "POST", body: { pin } }),
        logout: () => request("auth", "logout", { method: "POST" }),
    };

    const ideas = {
        list:          () => request("ideas", "list"),
        create:        (body) => request("ideas", "create", { method: "POST", body }),
        update:        (body) => request("ideas", "update", { method: "POST", body }),
        togglePin:     (id) => request("ideas", "toggle_pin", { method: "POST", body: { id } }),
        archive:       (id) => request("ideas", "archive", { method: "POST", body: { id } }),
        restore:       (id) => request("ideas", "restore", { method: "POST", body: { id } }),
        convert:       (body) => request("ideas", "convert", { method: "POST", body }),
        revertConvert: (id) => request("ideas", "revert_convert", { method: "POST", body: { id } }),
        reorder:       (ids) => request("ideas", "reorder", { method: "POST", body: { ids } }),
        duplicate:     (id) => request("ideas", "duplicate", { method: "POST", body: { id } }),
        setTags:       (id, tagIds) => request("ideas", "set_tags", { method: "POST", body: { id, tag_ids: tagIds } }),
    };

    const misiones = {
        list:         () => request("misiones", "list"),
        create:       (body) => request("misiones", "create", { method: "POST", body }),
        update:       (body) => request("misiones", "update", { method: "POST", body }),
        setStatus:    (id, status) => request("misiones", "set_status", { method: "POST", body: { id, status } }),
        assign:       (id, colaboradorId) => request("misiones", "assign", { method: "POST", body: { id, colaborador_id: colaboradorId } }),
        archive:      (id) => request("misiones", "archive", { method: "POST", body: { id } }),
        restore:      (id, status) => request("misiones", "restore", { method: "POST", body: { id, status } }),
        addItem:      (misionId, content) => request("misiones", "add_item", { method: "POST", body: { mision_id: misionId, content } }),
        toggleItem:   (id) => request("misiones", "toggle_item", { method: "POST", body: { id } }),
        updateItem:   (id, content) => request("misiones", "update_item", { method: "POST", body: { id, content } }),
        deleteItem:   (id) => request("misiones", "delete_item", { method: "POST", body: { id } }),
        restoreItem:  (body) => request("misiones", "restore_item", { method: "POST", body }),
        reorder:      (ids) => request("misiones", "reorder", { method: "POST", body: { ids } }),
        reorderItems: (misionId, ids) => request("misiones", "reorder_items", { method: "POST", body: { mision_id: misionId, ids } }),
        setTags:      (id, tagIds) => request("misiones", "set_tags", { method: "POST", body: { id, tag_ids: tagIds } }),
        duplicate:    (id) => request("misiones", "duplicate", { method: "POST", body: { id } }),
        bulk:         (op, ids, value) => request("misiones", "bulk", { method: "POST", body: { op, ids, value } }),
    };

    const colaboradores = {
        list:    () => request("colaboradores", "list"),
        create:  (body) => request("colaboradores", "create", { method: "POST", body }),
        update:  (body) => request("colaboradores", "update", { method: "POST", body }),
        archive: (id) => request("colaboradores", "archive", { method: "POST", body: { id } }),
    };

    const tags = {
        list:   () => request("tags", "list"),
        create: (body) => request("tags", "create", { method: "POST", body }),
        update: (body) => request("tags", "update", { method: "POST", body }),
        delete: (id) => request("tags", "delete", { method: "POST", body: { id } }),
    };

    const templates = {
        list:        () => request("templates", "list"),
        create:      (body) => request("templates", "create", { method: "POST", body }),
        update:      (body) => request("templates", "update", { method: "POST", body }),
        delete:      (id) => request("templates", "delete", { method: "POST", body: { id } }),
        instantiate: (body) => request("templates", "instantiate", { method: "POST", body }),
    };

    const comments = {
        list:   (misionId) => request("comments", "list", { params: { mision_id: misionId } }),
        create: (misionId, text) => request("comments", "create", { method: "POST", body: { mision_id: misionId, body: text } }),
        delete: (id) => request("comments", "delete", { method: "POST", body: { id } }),
    };

    const attachments = {
        list:   (misionId) => request("attachments", "list", { params: { mision_id: misionId } }),
        upload: (misionId, file) => {
            const fd = new FormData();
            fd.append("mision_id", String(misionId));
            fd.append("file", file);
            return upload("attachments", "upload", fd);
        },
        delete:      (id) => request("attachments", "delete", { method: "POST", body: { id } }),
        downloadUrl: (id) => urlFor("attachments", "download", { id }),
    };

    const data = {
        import:    (backup) => request("data", "import", { method: "POST", body: backup }),
        exportUrl: (format = "json", scope = null) =>
            urlFor("data", "export", scope ? { format, scope } : { format }),
    };

    return Object.freeze({
        setCsrf, getCsrf, getClientId, ApiError, urlFor,
        auth, ideas, misiones, colaboradores,
        tags, templates, comments, attachments, data,
    });
})();

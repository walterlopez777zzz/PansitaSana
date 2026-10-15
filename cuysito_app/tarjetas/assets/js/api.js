"use strict";

const TarjetasAPI = (() => {
    let csrfToken = "";

    class ApiError extends Error {
        constructor(message, status) {
            super(message);
            this.name = "ApiError";
            this.status = status;
        }
    }

    function endpoint(resource, action = "") {
        const query = action ? `?action=${encodeURIComponent(action)}` : "";
        return `api/${resource}.php${query}`;
    }

    async function request(resource, action = "", options = {}) {
        const method = options.method || "GET";
        const headers = { Accept: "application/json" };
        const init = { method, headers, credentials: "same-origin" };

        if (method !== "GET") {
            headers["Content-Type"] = "application/json";
            if (csrfToken) headers["X-CSRF-Token"] = csrfToken;
            init.body = JSON.stringify(options.body || {});
        }

        let response;
        try {
            response = await fetch(endpoint(resource, action), init);
        } catch {
            throw new ApiError("No hay conexión con el servidor.", 0);
        }

        let payload = {};
        try {
            payload = await response.json();
        } catch {
            throw new ApiError("El servidor devolvió una respuesta inválida.", response.status);
        }

        if (!response.ok || payload.status === "error") {
            throw new ApiError(payload.message || `Error HTTP ${response.status}`, response.status);
        }
        return payload;
    }

    return Object.freeze({
        ApiError,
        setCsrf(token) { csrfToken = token || ""; },
        auth: {
            status: () => request("auth", "status"),
            login: (pin) => request("auth", "login", { method: "POST", body: { pin } }),
            logout: () => request("auth", "logout", { method: "POST" }),
        },
        data: {
            snapshot: () => request("data"),
        },
        profiles: {
            create: (body) => request("profiles", "create", { method: "POST", body }),
            update: (body) => request("profiles", "update", { method: "POST", body }),
            delete: (id) => request("profiles", "delete", { method: "POST", body: { id } }),
            reorder: (ids) => request("profiles", "reorder", { method: "POST", body: { ids } }),
        },
        cards: {
            create: (body) => request("cards", "create", { method: "POST", body }),
            update: (body) => request("cards", "update", { method: "POST", body }),
            use: (id, hours = 24) => request("cards", "use", { method: "POST", body: { id, hours } }),
            setUses: (id, usesRemaining) => request("cards", "set_uses", {
                method: "POST",
                body: { id, uses_remaining: usesRemaining },
            }),
            setCooldown: (id, hours) => request("cards", "set_cooldown", { method: "POST", body: { id, hours } }),
            delete: (id) => request("cards", "delete", { method: "POST", body: { id } }),
            reorder: (ids) => request("cards", "reorder", { method: "POST", body: { ids } }),
        },
        emitters: {
            create: (body) => request("emitters", "create", { method: "POST", body }),
            update: (body) => request("emitters", "update", { method: "POST", body }),
            delete: (id) => request("emitters", "delete", { method: "POST", body: { id } }),
        },
        preferences: {
            save: (body) => request("preferences", "", { method: "POST", body }),
        },
    });
})();

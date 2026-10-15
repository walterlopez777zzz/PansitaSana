"use strict";

/**
 * ============================================================
 * Tareas — Cliente de tiempo real (Server-Sent Events)
 * ============================================================
 * Mantiene una conexión `EventSource` con `api/events.php` y avisa a la
 * app cuándo otra pestaña/colaborador cambió una colección, para que ésta
 * resincronice SÓLO esa vista (a través del mismo camino incremental que
 * ya repinta únicamente las tarjetas afectadas, sin parpadeo).
 *
 * Decisiones de diseño (robustez de nivel enterprise):
 *   - Supresión de eco: cada cambio que YO origino llega también por el
 *     canal con mi `clientId` en `actor`; lo ignoro porque ya lo apliqué
 *     de forma optimista. Así nunca repinto mi propia acción.
 *   - Reconexión gestionada con backoff exponencial + jitter (tope
 *     `MAX_BACKOFF_MS`). El stream del servidor se cierra a propósito cada
 *     ~25 s (no deja workers tomados); ese cierre "esperado" se reconecta
 *     casi al instante, mientras que los fallos repetidos se espacian.
 *   - Cursor reanudable: recuerdo el último `id` recibido y lo reenvío al
 *     reconectar (`?last_id`), de modo que no pierdo ni duplico avisos
 *     dentro de la ventana de retención de la bitácora.
 *   - Reacción a la red/visibilidad: si el navegador vuelve a estar online
 *     o la pestaña se hace visible, intento reconectar de inmediato.
 *
 * Es un singleton (una sola conexión por pestaña), en línea con el resto
 * de módulos del módulo (`TareasAPI`, `TareasHistory`, …). Expone el objeto
 * global `TareasRealtime`.
 * ============================================================
 */

const TareasRealtime = (() => {
    const DEFAULT_URL     = "api/events.php";
    const BASE_BACKOFF_MS = 1000;   // primer reintento tras un fallo
    const MAX_BACKOFF_MS  = 30000;  // tope del backoff exponencial
    const QUICK_RECONNECT_MS = 400; // reconexión tras un cierre esperado

    let source       = null;   // EventSource activo (o null)
    let options      = null;   // { clientId, onChange }
    let running      = false;  // ¿el cliente quiere estar conectado?
    let lastId       = 0;      // último id recibido (cursor reanudable)
    let attempts     = 0;      // fallos consecutivos sin abrir
    let openedCycle  = false;  // ¿la conexión actual llegó a abrir?
    let reconnectTimer = null;

    /**
     * Inicia el canal. Idempotente: si ya está corriendo, no hace nada.
     * @param {{ clientId: string, onChange: (scope: string) => void, url?: string }} opts
     */
    function start(opts) {
        if (running) return;
        if (!opts || typeof opts.onChange !== "function") {
            throw new TypeError("TareasRealtime.start requiere { onChange }.");
        }
        if (typeof EventSource === "undefined") {
            // Navegador sin SSE: la app sigue funcionando, sólo sin tiempo real.
            return;
        }
        options = { url: DEFAULT_URL, clientId: "", ...opts };
        running = true;
        attempts = 0;
        wireGlobalListeners();
        connect();
    }

    /** Detiene el canal y libera la conexión (p. ej. al bloquear la sesión). */
    function stop() {
        running = false;
        clearTimer();
        closeSource();
        unwireGlobalListeners();
    }

    function connect() {
        if (!running) return;
        clearTimer();
        closeSource();

        openedCycle = false;

        let es;
        try {
            es = new EventSource(urlWithCursor(), { withCredentials: true });
        } catch {
            scheduleReconnect();
            return;
        }

        source = es;
        es.addEventListener("open", handleOpen);
        es.addEventListener("change", handleChange);
        es.addEventListener("error", handleError);
    }

    function handleOpen() {
        openedCycle = true;
        attempts = 0; // conexión sana: reinicia el backoff
    }

    function handleChange(event) {
        // El id del evento (Last-Event-ID) es nuestro cursor reanudable.
        const id = Number.parseInt(event.lastEventId || "", 10);
        if (Number.isFinite(id) && id > lastId) lastId = id;

        let payload;
        try {
            payload = JSON.parse(event.data);
        } catch {
            return; // evento malformado: lo ignoramos sin romper el canal
        }

        // Supresión de eco: no repintamos lo que esta misma pestaña provocó.
        if (payload.actor && payload.actor === options.clientId) return;

        const scope = typeof payload.scope === "string" ? payload.scope : "";
        if (scope) options.onChange(scope);
    }

    function handleError() {
        // `EventSource` ya cerró o reintentará; tomamos el control nosotros
        // para aplicar un backoff acotado y predecible.
        closeSource();
        if (!running) return;

        // Un cierre tras haber abierto es el reciclado esperado del stream
        // (el servidor corta cada ~25 s): reconecta casi al instante.
        // Un fallo sin llegar a abrir escala con backoff exponencial.
        const delay = openedCycle
            ? QUICK_RECONNECT_MS
            : backoffDelay(attempts++);

        scheduleReconnect(delay);
    }

    function scheduleReconnect(delay) {
        if (!running) return;
        clearTimer();
        const wait = typeof delay === "number" ? delay : backoffDelay(attempts++);
        reconnectTimer = window.setTimeout(connect, wait);
    }

    /** Backoff exponencial (2^n) con jitter, acotado a MAX_BACKOFF_MS. */
    function backoffDelay(n) {
        const exp = Math.min(MAX_BACKOFF_MS, BASE_BACKOFF_MS * 2 ** n);
        return Math.round(exp / 2 + Math.random() * (exp / 2));
    }

    function urlWithCursor() {
        const sep = options.url.includes("?") ? "&" : "?";
        // `last_id` sólo se envía cuando ya hay cursor (lastId > 0). En la
        // primera conexión lo omitimos para que el servidor arranque desde el
        // último id existente en vez de reproducir toda la bitácora retenida:
        // ese historial dispararía un aluvión de resincronías que compiten con
        // las ediciones del usuario y provocan parpadeos.
        const cursor = lastId > 0 ? `&last_id=${lastId}` : "";
        return `${options.url}${sep}action=stream${cursor}`;
    }

    function closeSource() {
        if (!source) return;
        source.removeEventListener("open", handleOpen);
        source.removeEventListener("change", handleChange);
        source.removeEventListener("error", handleError);
        try { source.close(); } catch { /* ya cerrado */ }
        source = null;
    }

    function clearTimer() {
        if (reconnectTimer !== null) {
            window.clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
    }

    // ── Reacción a red/visibilidad ───────────────────────────
    // Si volvemos a estar online o la pestaña se hace visible y no hay
    // conexión viva, reconectamos de inmediato (sin esperar al backoff).
    function onWake() {
        if (!running) return;
        if (source && source.readyState !== EventSource.CLOSED) return;
        attempts = 0;
        scheduleReconnect(0);
    }

    function onVisibility() {
        if (document.visibilityState === "visible") onWake();
    }

    let listenersWired = false;
    function wireGlobalListeners() {
        if (listenersWired) return;
        window.addEventListener("online", onWake);
        document.addEventListener("visibilitychange", onVisibility);
        listenersWired = true;
    }

    function unwireGlobalListeners() {
        if (!listenersWired) return;
        window.removeEventListener("online", onWake);
        document.removeEventListener("visibilitychange", onVisibility);
        listenersWired = false;
    }

    return Object.freeze({ start, stop });
})();

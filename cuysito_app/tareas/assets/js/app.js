"use strict";

/**
 * ============================================================
 * Tareas — Orquestador de la aplicación
 * ============================================================
 * Arranca el módulo: valida acceso (PIN server-side), carga los
 * datos, construye el composer/toolbar y cablea todos los
 * handlers de Ideas, Misiones y Colaboradores.
 * ============================================================
 */

(() => {
    const { el, icon, hydrateIcons, toast, toastAction, openModal, autoGrow, charCounter, searchBar, normalizeText, select, swatchPicker, announce } = TareasUI;

    const IDEA_MAXLEN = 1000;

    // Opciones de color para el selector de acento (idea), derivadas de la
    // paleta única definida en la vista de ideas.
    const ACCENT_OPTIONS = Object.keys(TareasIdeas.ACCENT_VARS).map((name) => ({
        value: name, color: TareasIdeas.ACCENT_VARS[name], label: name,
    }));

    const state = {
        ideas: [], misiones: [], colaboradores: [], tags: [],
        filter: "todas",
        sort: "prioridad",
        search: { ideas: "", misiones: "" },
        // Etiquetas activas para filtrar cada vista (ids; lógica OR).
        tagFilter: { ideas: [], misiones: [] },
        // Selección múltiple de misiones (acciones en lote).
        selectMode: false,
        selection: new Set(),
    };

    /** Lee/escribe en localStorage tolerando entornos sin almacenamiento. */
    const storage = {
        get(key) { try { return localStorage.getItem(key); } catch { return null; } },
        set(key, value) { try { localStorage.setItem(key, value); } catch { /* no disponible */ } },
    };

    const $ = (id) => document.getElementById(id);

    /** ¿`text` contiene `query`? (insensible a mayúsculas y tildes). */
    const matches = (text, query) => normalizeText(text).includes(normalizeText(query));

    /**
     * Copia texto al portapapeles con degradación elegante: usa la Clipboard
     * API moderna cuando está disponible (contexto seguro) y, si no, recurre
     * a un `<textarea>` temporal + `execCommand` para navegadores/origenes
     * sin acceso a `navigator.clipboard`.
     */
    async function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        const ta = el("textarea", { "aria-hidden": "true" });
        ta.value = text;
        ta.style.setProperty("position", "fixed");
        ta.style.setProperty("opacity", "0");
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            if (!document.execCommand("copy")) throw new Error("No se pudo copiar.");
        } finally {
            ta.remove();
        }
    }

    // ── Carga de datos ───────────────────────────────────────
    // Cada colección lleva un contador monótono de cargas. Una respuesta de
    // `list()` sólo se aplica si sigue siendo la más reciente solicitada: así
    // una respuesta que llega fuera de orden (red lenta, refrescos solapados,
    // resincronía en tiempo real que cruza con una edición) nunca pisa un
    // estado más nuevo y la vista no retrocede a un valor anterior (parpadeo).
    const loadSeq = { ideas: 0, misiones: 0, colaboradores: 0, tags: 0 };

    /**
     * Ejecuta una carga de colección de forma monótona. Devuelve `true` si la
     * respuesta se aplicó (seguía vigente) y `false` si quedó obsoleta por una
     * carga posterior y se descartó sin tocar el estado.
     */
    async function loadCollection(name, fetch, apply) {
        const seq = ++loadSeq[name];
        const data = await fetch();
        if (seq !== loadSeq[name]) return false; // una carga más nueva ya manda
        apply(data);
        return true;
    }

    async function loadIdeas() {
        const fresh = await loadCollection("ideas", () => TareasAPI.ideas.list(),
            (res) => { state.ideas = res.ideas || []; });
        if (fresh) renderIdeas();
    }
    async function loadColaboradores() {
        await loadCollection("colaboradores", () => TareasAPI.colaboradores.list(),
            (res) => { state.colaboradores = res.colaboradores || []; });
    }
    async function loadMisiones() {
        const fresh = await loadCollection("misiones", () => TareasAPI.misiones.list(),
            (res) => { state.misiones = res.misiones || []; });
        if (fresh) renderMisiones();
    }
    async function loadTags() {
        await loadCollection("tags", () => TareasAPI.tags.list(), (res) => {
            state.tags = res.tags || [];
            // Descarta del filtro las etiquetas que dejaron de existir.
            const ids = new Set(state.tags.map((t) => t.id));
            state.tagFilter.ideas = state.tagFilter.ideas.filter((id) => ids.has(id));
            state.tagFilter.misiones = state.tagFilter.misiones.filter((id) => ids.has(id));
        });
    }
    async function loadAll() {
        // Recarga completa: el historial deja de ser válido sobre datos frescos.
        TareasHistory.clear();
        await Promise.all([loadTags(), loadIdeas(), loadColaboradores().then(loadMisiones)]);
        updateCounts();
    }

    const guard = (fn) => async (...args) => {
        try { await fn(...args); }
        catch (err) {
            if (err instanceof TareasAPI.ApiError && err.status === 401) return relock();
            toast(err.message || "Ocurrió un error.", "error");
        }
    };

    function updateCounts() {
        $("tk-count-ideas").textContent = state.ideas.length;
        $("tk-count-misiones").textContent = state.misiones.filter((m) => m.status !== "completada").length;
    }

    const findMision = (id) => state.misiones.find((m) => m.id === id);

    // ── Patch de estado local + render incremental (#14) ─────
    // El backend devuelve la entidad afectada en casi todas las mutaciones,
    // así que actualizamos el estado en memoria con esa respuesta (upsert /
    // remove) en lugar de re-listar todo. La capa de vista repinta de forma
    // incremental (reconciliador con claves), reutilizando los nodos intactos
    // y preservando foco, scroll y estado de arrastre.

    /** Clon profundo barato (entidades JSON puras) para snapshots de rollback. */
    const clone = (entity) => JSON.parse(JSON.stringify(entity));

    function upsertMision(m) {
        if (!m) return;
        const i = state.misiones.findIndex((x) => x.id === m.id);
        if (i >= 0) state.misiones[i] = m; else state.misiones.push(m);
    }
    function removeMision(id) {
        const i = state.misiones.findIndex((x) => x.id === id);
        if (i >= 0) state.misiones.splice(i, 1);
    }
    /** Aplica la respuesta de una mutación de misión (entidad + posible spawn). */
    function syncMision(res) {
        if (res && res.mision) upsertMision(res.mision);
        if (res && res.spawned) upsertMision(res.spawned);
        return res;
    }

    // ── Serialización + versionado monótono de mutaciones por entidad ──
    // Marcar/desmarcar (u otras escrituras) muy rápido lanza muchas mutaciones
    // sobre la MISMA entidad. Dos carreras distintas pueden hacer reaparecer un
    // valor anterior —el parpadeo—:
    //
    //   A) Orden de LLEGADA: la respuesta de una mutación vieja llega después
    //      de pintar una más nueva y la pisa.
    //   B) Orden de PROCESO: el navegador abre hasta ~6 conexiones en paralelo
    //      y el servidor las atiende en cualquier orden. Cada respuesta refleja
    //      el estado que el servidor vio AL PROCESAR esa petición; la de la
    //      última mutación disparada puede haberse procesado ANTES que otras
    //      previas y traer, por tanto, una instantánea ya superada (sobre todo
    //      tras una ráfaga larga, con muchas escrituras en vuelo).
    //
    // Se cierran AMBAS con dos garantías por entidad:
    //
    //   1. Versionado monótono: sólo la mutación más reciente aplica su
    //      respuesta autoritativa; las superadas se descartan sin repintar
    //      (cierra A y evita reproducir estados intermedios).
    //   2. Serialización FIFO de las ESCRITURAS de red: las llamadas de una
    //      misma entidad se encadenan, de modo que el servidor las procesa en
    //      el orden en que el usuario las disparó y la respuesta de la última
    //      refleja el estado final canónico (cierra B).
    //
    // La PREDICCIÓN optimista se aplica de inmediato (feedback instantáneo);
    // sólo la llamada de red y su reconciliación viajan por la cola. Misiones e
    // ideas usan carriles separados porque sus ids son disjuntos.
    const entityMutations = (() => {
        const lanes = { mision: new Map(), idea: new Map() };

        const laneFor = (scope, id) => {
            const map = lanes[scope];
            let lane = map.get(id);
            if (!lane) { lane = { seq: 0, tail: Promise.resolve() }; map.set(id, lane); }
            return lane;
        };

        /**
         * Encola la escritura de red `call` para una entidad. Se ejecuta sólo
         * cuando las mutaciones previas de esa entidad terminaron (FIFO), así el
         * servidor las procesa en orden. Devuelve la promesa del resultado
         * (`run`) y un predicado `isCurrent()` —cierto mientras ninguna mutación
         * posterior la haya reemplazado—, para aplicar la respuesta de forma
         * monótona.
         * @param {"mision"|"idea"} scope
         * @param {number} id
         * @param {() => Promise<object>} call
         */
        const enqueue = (scope, id, call) => {
            const lane = laneFor(scope, id);
            const seq = ++lane.seq;
            const run = lane.tail.then(call);
            // La cadena sobrevive a un fallo puntual para no romper el orden.
            lane.tail = run.then(() => {}, () => {});
            return { run, isCurrent: () => lane.seq === seq };
        };

        /** Olvida todas las versiones/colas (al cambiar de sesión). */
        const clear = () => { lanes.mision.clear(); lanes.idea.clear(); };

        return { enqueue, clear };
    })();

    /**
     * Aplica la respuesta autoritativa de una mutación de misión de forma
     * monótona y serializada: la escritura se encola por entidad (el servidor
     * la procesa en orden, así la última respuesta es la final canónica) y sólo
     * se aplica si sigue siendo la más reciente; una respuesta superada se
     * descarta sin repintar. Devuelve la respuesta cruda para que el llamador
     * conserve datos auxiliares (ids generados, mensajes, etc.).
     */
    async function authoritativeMision(id, call) {
        const { run, isCurrent } = entityMutations.enqueue("mision", id, call);
        const res = await run;
        if (isCurrent()) { syncMision(res); syncMisionesView(); }
        return res;
    }
    /**
     * Inserta o actualiza una idea en el estado. Si la idea no existe y se
     * indica `index`, se reinserta en esa posición (preserva el orden al
     * deshacer un borrado); sin `index`, se añade al final.
     * @param {object} idea
     * @param {number} [index]  posición destino para reinserciones (deshacer)
     */
    function upsertIdea(idea, index) {
        if (!idea) return;
        const i = state.ideas.findIndex((x) => x.id === idea.id);
        if (i >= 0) { state.ideas[i] = idea; return; }
        if (typeof index === "number" && index >= 0) {
            state.ideas.splice(Math.min(index, state.ideas.length), 0, idea);
        } else {
            state.ideas.push(idea);
        }
    }
    function removeIdea(id) {
        const i = state.ideas.findIndex((x) => x.id === id);
        if (i >= 0) state.ideas.splice(i, 1);
    }
    /** Reordena `arr` in place según una secuencia de ids (string|number). */
    function reorderByIds(arr, orderedIds) {
        const pos = new Map(orderedIds.map((id, i) => [String(id), i]));
        const rank = (x) => (pos.has(String(x.id)) ? pos.get(String(x.id)) : Number.MAX_SAFE_INTEGER);
        arr.sort((a, b) => rank(a) - rank(b));
    }

    // Repintado incremental + sincronía de contadores tras un patch local.
    const syncIdeasView = () => { renderIdeas(); updateCounts(); };
    const syncMisionesView = () => { renderMisiones(); updateCounts(); };

    /**
     * Mutación optimista de una misión: aplica una predicción local al
     * instante (feedback inmediato), llama a la API y reconcilia con la
     * entidad autoritativa. Si la red falla, revierte al snapshot y propaga
     * el error (el llamador ya muestra el toast y/o resincroniza).
     * @param {number} id
     * @param {(draft:object)=>object} predict  recibe un clon mutable y devuelve el predicho
     * @param {()=>Promise<object>} call          llamada a la API (devuelve la respuesta)
     */
    async function optimisticMision(id, predict, call) {
        const i = state.misiones.findIndex((m) => m.id === id);
        const backup = i >= 0 ? state.misiones[i] : null;
        // La predicción se pinta YA (en orden de clic); sólo la escritura de red
        // se serializa por entidad para que el servidor la procese en ese orden.
        if (backup) { state.misiones[i] = predict(clone(backup)); syncMisionesView(); }
        const { run, isCurrent } = entityMutations.enqueue("mision", id, call);
        try {
            const res = await run;
            // Sólo la mutación vigente aplica su respuesta: una posterior manda.
            if (isCurrent()) { syncMision(res); syncMisionesView(); }
            return res;
        } catch (err) {
            // Sólo se revierte si esta mutación sigue siendo la vigente: si otra
            // más nueva ya tomó el control, su predicción es la verdad actual y
            // no debe pisarse con un snapshot anterior.
            if (backup && isCurrent()) { upsertMision(backup); syncMisionesView(); }
            throw err;
        }
    }

    // ── Historial Deshacer / Rehacer (comandos reversibles) ──
    /**
     * Recarga y re-renderiza las colecciones indicadas desde el servidor. Es
     * la fuente de verdad tras cada comando: mantiene el estado en memoria en
     * sincronía con la base sin acumular lógica de parcheo local.
     * @param {("ideas"|"misiones"|"colaboradores")[]} cols
     */
    async function refreshCollections(cols) {
        const jobs = [];
        if (cols.includes("tags")) jobs.push(loadTags());
        if (cols.includes("colaboradores")) jobs.push(loadColaboradores());
        if (cols.includes("ideas")) jobs.push(loadIdeas());
        if (cols.includes("misiones")) jobs.push(loadMisiones());
        await Promise.all(jobs);
        // Cambios que no recargan la lista pero alteran su render igual la repintan.
        if (cols.includes("colaboradores") && !cols.includes("misiones")) renderMisiones();
        if (cols.includes("tags")) { if (!cols.includes("ideas")) renderIdeas(); if (!cols.includes("misiones")) renderMisiones(); }
        updateCounts();
    }

    /** Ejecuta la acción de un comando y lo registra como reversible. */
    async function commitCommand(command) {
        await command.redo();
        TareasHistory.push(command);
    }

    /**
     * Variante "disparo y olvido" de `commitCommand`: centraliza el manejo de
     * errores (re-bloqueo en 401) y, opcionalmente, anuncia un toast de éxito
     * y/o uno con botón "Deshacer". Para acciones NO modales.
     */
    async function runCommand(command, { undoToast = null, success = null } = {}) {
        try {
            await commitCommand(command);
        } catch (err) {
            if (err instanceof TareasAPI.ApiError && err.status === 401) return relock();
            return toast(err.message || "Ocurrió un error.", "error");
        }
        if (success) toast(success, "success");
        if (undoToast) toastAction(undoToast, { onAction: safeUndo });
    }

    /** Deshace/rehace con resincronización segura si la inversa falla. */
    const transitionHistory = (kind, doneVerb) => async () => {
        try {
            const command = await TareasHistory[kind]();
            announce(command ? `${doneVerb}: ${command.label}.` : `Nada para ${kind === "undo" ? "deshacer" : "rehacer"}.`);
        } catch (err) {
            if (err instanceof TareasAPI.ApiError && err.status === 401) return relock();
            toast(err.message || "No se pudo completar la operación.", "error");
            try { await loadAll(); } catch { /* se resincroniza en la próxima carga */ }
        }
    };
    const safeUndo = transitionHistory("undo", "Deshecho");
    const safeRedo = transitionHistory("redo", "Rehecho");

    // ── Fábricas de comandos ─────────────────────────────────
    // Cada comando expone `redo` (la acción forward, también en la primera
    // ejecución) y `undo` (su inversa). Tras cada operación se resincroniza.

    // Fijar/desfijar es optimista: invierte el estado al instante y reubica la
    // idea (las fijadas van primero) para feedback inmediato; luego recarga el
    // listado para adoptar el orden canónico del servidor (repintado incremental).
    const togglePinCommand = (idea) => {
        const op = async () => {
            const i = state.ideas.findIndex((x) => x.id === idea.id);
            const backup = i >= 0 ? clone(state.ideas[i]) : null;
            const pinFirst = () => state.ideas.sort((a, b) => (b.is_pinned || 0) - (a.is_pinned || 0));
            if (i >= 0) {
                state.ideas[i].is_pinned = state.ideas[i].is_pinned == 1 ? 0 : 1;
                pinFirst(); // ordenación estable: preserva el orden intra-grupo
                syncIdeasView();
            }
            // La escritura se serializa por idea (el servidor la procesa en
            // orden); el recargado canónico sólo lo dispara la mutación vigente:
            // una posterior sobre la misma idea ya pintó la verdad y hará el suyo.
            const { run, isCurrent } = entityMutations.enqueue("idea", idea.id, () => TareasAPI.ideas.togglePin(idea.id));
            try { await run; if (isCurrent()) { await loadIdeas(); updateCounts(); } }
            catch (err) { if (backup && isCurrent()) { upsertIdea(backup); pinFirst(); syncIdeasView(); } throw err; }
        };
        return { label: idea.is_pinned == 1 ? "desfijar idea" : "fijar idea", redo: op, undo: op };
    };

    const archiveIdeaCommand = (idea) => {
        // Se recuerda la posición que ocupaba al archivar para devolverla allí
        // al deshacer, en vez de reaparecer como la más reciente.
        let index = -1;
        return {
            label: "archivar idea",
            redo: async () => {
                await TareasAPI.ideas.archive(idea.id);
                index = state.ideas.findIndex((x) => x.id === idea.id);
                removeIdea(idea.id);
                syncIdeasView();
            },
            undo: async () => {
                upsertIdea((await TareasAPI.ideas.restore(idea.id)).idea, index);
                syncIdeasView();
            },
        };
    };

    /** Ids de las etiquetas asociadas a una entidad (ideas/misiones). */
    const tagIdsOf = (entity) => (entity.tags || []).map((t) => t.id);

    /**
     * Crea una etiqueta al vuelo desde el combobox y la suma al estado en
     * memoria para que quede disponible de inmediato en pickers/filtros.
     * Propaga el error (el combobox ya lo notifica con un toast).
     */
    async function createTagInline(name) {
        const tag = (await TareasAPI.tags.create({ name, color: "violet" })).tag;
        if (tag && !state.tags.some((t) => t.id === tag.id)) state.tags.push(tag);
        return tag;
    }

    /** Campo de fecha de vencimiento (HTML5 date, opcional) para los modales. */
    const dueDateField = (value) => ({
        name: "due_date", label: "Vencimiento (opcional)", type: "date", value: value || "",
    });

    /** Campo de recurrencia (se aplica al completar una misión con vencimiento). */
    const recurrenceField = (value) => ({
        name: "recurrence", label: "Recurrencia", type: "select",
        value: value || "none", options: TareasDates.RECURRENCE_OPTIONS,
    });

    /** Campo "custom" reutilizable: combobox de etiquetas para los modales. */
    function tagPickerField(selectedTagIds) {
        return {
            name: "tagIds", label: "Etiquetas", type: "custom",
            control: TareasTags.picker({
                tags: state.tags, selected: selectedTagIds,
                ariaLabel: "Etiquetas", onCreate: createTagInline,
            }),
        };
    }

    const editIdeaCommand = (idea, next) => {
        const before = { content: idea.content, accent: idea.accent, tagIds: tagIdsOf(idea) };
        // `content`/`accent` van por `update`; las etiquetas por su endpoint
        // dedicado (la idea no las acepta en `update`).
        const apply = (data) => async () => {
            await TareasAPI.ideas.update({ id: idea.id, content: data.content, accent: data.accent });
            upsertIdea((await TareasAPI.ideas.setTags(idea.id, data.tagIds)).idea);
            syncIdeasView();
        };
        return { label: "editar idea", redo: apply(next), undo: apply(before) };
    };

    const duplicateIdeaCommand = (idea) => {
        let id = null;
        return {
            label: "duplicar idea",
            redo: async () => {
                const created = id === null
                    ? (await TareasAPI.ideas.duplicate(idea.id)).idea
                    : (await TareasAPI.ideas.restore(id)).idea;
                id = created.id; upsertIdea(created); syncIdeasView();
            },
            undo: async () => { await TareasAPI.ideas.archive(id); removeIdea(id); syncIdeasView(); },
        };
    };

    const convertIdeaCommand = (idea, payload) => ({
        label: "convertir idea",
        redo: async () => { await TareasAPI.ideas.convert({ id: idea.id, ...payload }); await refreshCollections(["ideas", "misiones"]); },
        undo: async () => { await TareasAPI.ideas.revertConvert(idea.id); await refreshCollections(["ideas", "misiones"]); },
    });

    const createIdeaCommand = (payload) => {
        let id = null;
        return {
            label: "crear idea",
            redo: async () => {
                const created = id === null
                    ? (await TareasAPI.ideas.create(payload)).idea
                    : (await TareasAPI.ideas.restore(id)).idea;
                id = created.id; upsertIdea(created); syncIdeasView();
            },
            undo: async () => { await TareasAPI.ideas.archive(id); removeIdea(id); syncIdeasView(); },
        };
    };

    const setStatusCommand = (m, status) => {
        const before = m.status;
        // Completar una misión recurrente engendra la próxima ocurrencia en el
        // servidor. Para que el deshacer sea total, archivamos esa ocurrencia
        // al revertir; el rehacer vuelve a generar una nueva (la anterior queda
        // archivada, nunca quedan dos activas).
        let spawnedId = null;
        const redo = () => optimisticMision(m.id,
            (d) => { d.status = status; return d; },
            async () => {
                const res = await TareasAPI.misiones.setStatus(m.id, status);
                if (res.spawned && res.spawned.id) {
                    spawnedId = res.spawned.id;
                    toast(res.message || "Próxima ocurrencia generada.", "success");
                }
                return res;
            });
        const undo = () => optimisticMision(m.id,
            (d) => { d.status = before; return d; },
            async () => {
                const res = await TareasAPI.misiones.setStatus(m.id, before);
                if (spawnedId != null) { await TareasAPI.misiones.archive(spawnedId); removeMision(spawnedId); spawnedId = null; }
                return res;
            });
        return { label: "cambiar estado", redo, undo };
    };

    const assignCommand = (m, colaboradorId) => {
        const before = m.colaborador_id || null;
        const apply = (c) => () => authoritativeMision(m.id, () => TareasAPI.misiones.assign(m.id, c));
        return { label: "asignar colaborador", redo: apply(colaboradorId), undo: apply(before) };
    };

    const editMisionCommand = (m, next) => {
        const before = {
            title: m.title, description: m.description || "", priority: m.priority,
            accent: m.accent || "violet", due_date: m.due_date || "",
            recurrence: m.recurrence || "none", tag_ids: tagIdsOf(m),
        };
        const apply = (data) => () => authoritativeMision(m.id, () => TareasAPI.misiones.update({ id: m.id, ...data }));
        return { label: "editar misión", redo: apply(next), undo: apply(before) };
    };

    const archiveMisionCommand = (m) => {
        const before = m.status; // estado al que se restaura (nunca 'archivada')
        return {
            label: "archivar misión",
            redo: async () => { await TareasAPI.misiones.archive(m.id); removeMision(m.id); syncMisionesView(); },
            undo: async () => { syncMision(await TareasAPI.misiones.restore(m.id, before)); syncMisionesView(); },
        };
    };

    const archiveCompletedCommand = (missions) => {
        const snap = missions.map((m) => ({ id: m.id, before: m.status }));
        return {
            label: snap.length === 1 ? "archivar misión" : "archivar completadas",
            redo: async () => { for (const s of snap) { await TareasAPI.misiones.archive(s.id); removeMision(s.id); } syncMisionesView(); },
            undo: async () => { for (const s of snap) syncMision(await TareasAPI.misiones.restore(s.id, s.before)); syncMisionesView(); },
        };
    };

    // Acción en lote: una sola operación reversible sobre N misiones. El
    // snapshot captura el valor previo de cada misión para que el deshacer las
    // restaure exactamente, agrupando por valor para minimizar las llamadas.
    const BULK_LABELS = {
        status: "cambiar estado", priority: "cambiar prioridad",
        assign: "asignar colaborador", archive: "archivar misiones",
    };
    const bulkCommand = (op, value, missions) => {
        const ids = missions.map((m) => m.id);
        const snapshot = missions.map((m) => ({
            id: m.id, status: m.status, priority: m.priority,
            colaborador_id: m.colaborador_id || null,
        }));
        // El backend devuelve la lista completa tras un bulk; adoptamos esa
        // lista autoritativa como nuevo estado (sin re-listar por separado).
        const applyList = (res) => { if (res && res.misiones) state.misiones = res.misiones; };
        const restoreBy = async (valueOf, bulkOp) => {
            const groups = new Map();
            snapshot.forEach((s) => {
                const v = valueOf(s);
                if (!groups.has(v)) groups.set(v, []);
                groups.get(v).push(s.id);
            });
            for (const [v, gids] of groups) applyList(await TareasAPI.misiones.bulk(bulkOp, gids, v));
        };
        const redo = async () => { applyList(await TareasAPI.misiones.bulk(op, ids, value)); syncMisionesView(); };
        const undoFns = {
            // Archivar es una transición de estado aparte: se revierte
            // restaurando cada misión a su estado previo (nunca 'archivada').
            archive:  async () => { for (const s of snapshot) syncMision(await TareasAPI.misiones.restore(s.id, s.status)); },
            status:   () => restoreBy((s) => s.status, "status"),
            priority: () => restoreBy((s) => s.priority, "priority"),
            assign:   () => restoreBy((s) => s.colaborador_id ?? 0, "assign"),
        };
        const undo = async () => { await undoFns[op](); syncMisionesView(); };
        return { label: BULK_LABELS[op] || "acción en lote", redo, undo };
    };

    const duplicateMisionCommand = (m) => {
        let id = null;
        return {
            label: "duplicar misión",
            redo: async () => {
                const created = id === null
                    ? (await TareasAPI.misiones.duplicate(m.id)).mision
                    : (await TareasAPI.misiones.restore(id, "pendiente")).mision;
                id = created.id; upsertMision(created); syncMisionesView();
            },
            undo: async () => { await TareasAPI.misiones.archive(id); removeMision(id); syncMisionesView(); },
        };
    };

    const createMisionCommand = (payload) => {
        let id = null;
        return {
            label: "crear misión",
            redo: async () => {
                const created = id === null
                    ? (await TareasAPI.misiones.create(payload)).mision
                    : (await TareasAPI.misiones.restore(id, "pendiente")).mision;
                id = created.id; upsertMision(created); syncMisionesView();
            },
            undo: async () => { await TareasAPI.misiones.archive(id); removeMision(id); syncMisionesView(); },
        };
    };

    const addItemCommand = (m, content) => {
        let itemId = null;
        let sortOrder = 0;
        return {
            label: "agregar ítem",
            redo: async () => {
                if (itemId === null) {
                    const before = new Set(((findMision(m.id) || {}).checklist || []).map((i) => i.id));
                    const res = syncMision(await TareasAPI.misiones.addItem(m.id, content));
                    const created = (res.mision.checklist || []).find((i) => !before.has(i.id));
                    if (created) { itemId = created.id; sortOrder = created.sort_order; }
                } else {
                    const res = await TareasAPI.misiones.restoreItem({ mision_id: m.id, content, is_done: 0, sort_order: sortOrder });
                    itemId = res.item_id; syncMision(res);
                }
                syncMisionesView();
            },
            undo: async () => { if (itemId != null) syncMision(await TareasAPI.misiones.deleteItem(itemId)); syncMisionesView(); },
        };
    };

    // Marcar/desmarcar es optimista: invierte el ítem en memoria al instante y
    // reconcilia con la misión que devuelve el servidor (o revierte si falla).
    const toggleItemCommand = (item) => {
        const op = () => optimisticMision(item.mision_id,
            (d) => {
                d.checklist = (d.checklist || []).map((i) => i.id === item.id ? { ...i, is_done: i.is_done == 1 ? 0 : 1 } : i);
                return d;
            },
            () => TareasAPI.misiones.toggleItem(item.id));
        return { label: item.is_done == 1 ? "desmarcar ítem" : "marcar ítem", redo: op, undo: op };
    };

    const updateItemCommand = (item, content) => {
        const before = item.content;
        const apply = (text) => () => authoritativeMision(item.mision_id, () => TareasAPI.misiones.updateItem(item.id, text));
        return { label: "editar ítem", redo: apply(content), undo: apply(before) };
    };

    const deleteItemCommand = (item) => {
        const snapshot = { mision_id: item.mision_id, content: item.content, is_done: item.is_done == 1 ? 1 : 0, sort_order: item.sort_order || 0 };
        let itemId = item.id;
        return {
            label: "eliminar ítem",
            redo: async () => { syncMision(await TareasAPI.misiones.deleteItem(itemId)); syncMisionesView(); },
            undo: async () => { const res = await TareasAPI.misiones.restoreItem(snapshot); itemId = res.item_id; syncMision(res); syncMisionesView(); },
        };
    };

    // Comandos de reordenamiento (drag & drop): el snapshot del orden previo
    // se toma del estado en memoria *antes* de persistir el nuevo, y la inversa
    // simplemente reaplica esa secuencia anterior.
    const reorderIdeasCommand = (orderedIds, prevIds) => ({
        label: "reordenar ideas",
        redo: async () => { await TareasAPI.ideas.reorder(orderedIds); reorderByIds(state.ideas, orderedIds); syncIdeasView(); },
        undo: async () => { await TareasAPI.ideas.reorder(prevIds); reorderByIds(state.ideas, prevIds); syncIdeasView(); },
    });

    const reorderMisionesCommand = (orderedIds, prevIds) => ({
        label: "reordenar misiones",
        redo: async () => { await TareasAPI.misiones.reorder(orderedIds); reorderByIds(state.misiones, orderedIds); syncMisionesView(); },
        undo: async () => { await TareasAPI.misiones.reorder(prevIds); reorderByIds(state.misiones, prevIds); syncMisionesView(); },
    });

    const reorderItemsCommand = (misionId, orderedIds, prevIds) => {
        const apply = (ids) => async () => { syncMision(await TareasAPI.misiones.reorderItems(misionId, ids)); syncMisionesView(); };
        return { label: "reordenar checklist", redo: apply(orderedIds), undo: apply(prevIds) };
    };

    // ── Ideas ────────────────────────────────────────────────
    const ideaHandlers = {
        onPin: (idea) => runCommand(togglePinCommand(idea)),
        onTagClick: (tag) => toggleIdeaTagFilter(tag.id),
        onArchive: (idea) => runCommand(archiveIdeaCommand(idea), { undoToast: "Idea archivada." }),
        onEdit: guard(async (idea) => {
            await openModal({
                title: "Editar idea",
                fields: [
                    { name: "content", label: "Idea", type: "textarea", value: idea.content, maxlength: 1000 },
                    { name: "accent", label: "Color", type: "swatch", value: idea.accent, options: ACCENT_OPTIONS },
                    tagPickerField(tagIdsOf(idea)),
                ],
                submitLabel: "Guardar",
                onSubmit: async (v) => {
                    if (!v.content.trim()) { toast("La idea no puede estar vacía.", "error"); return false; }
                    await commitCommand(editIdeaCommand(idea, { content: v.content, accent: v.accent, tagIds: v.tagIds }));
                    toast("Idea actualizada.", "success");
                },
            });
        }),
        onDuplicate: (idea) => runCommand(duplicateIdeaCommand(idea), { success: "Idea duplicada." }),
        onCopy: guard(async (idea) => {
            await copyToClipboard(idea.content);
            toast("Idea copiada al portapapeles.", "success");
        }),
        onConvert: guard(async (idea) => {
            await openModal({
                title: "Convertir en misión",
                fields: [
                    { name: "title", label: "Título de la misión", value: idea.content.slice(0, 160), maxlength: 160 },
                    { name: "description", label: "Descripción (opcional)", type: "textarea", maxlength: 4000 },
                    { name: "priority", label: "Prioridad", type: "select", value: "media", options: [
                        { value: "alta", label: "Alta" }, { value: "media", label: "Media" }, { value: "baja", label: "Baja" },
                    ] },
                ],
                submitLabel: "Crear misión",
                onSubmit: async (v) => {
                    if (!v.title.trim()) { toast("El título es obligatorio.", "error"); return false; }
                    await commitCommand(convertIdeaCommand(idea, { title: v.title, description: v.description, priority: v.priority }));
                    toast("Idea convertida en misión.", "success");
                },
            });
        }),
    };

    let ideasSearch = null;
    let misionesSearch = null;

    let ideasTagFilterMount = null;
    let misionesTagFilterMount = null;

    function buildIdeasTools() {
        ideasSearch = searchBar({
            placeholder: "Buscar ideas…",
            ariaLabel: "Buscar ideas",
            onInput: (v) => { state.search.ideas = v; renderIdeas(); },
        });
        ideasTagFilterMount = el("div", { class: "tk-tag-filter-mount" });
        $("tk-ideas-tools").replaceChildren(el("div", { class: "tk-ideas-tools" }, [ideasSearch, ideasTagFilterMount]));
    }

    function toggleIdeaTagFilter(id) {
        const f = state.tagFilter.ideas;
        const i = f.indexOf(Number(id));
        if (i >= 0) f.splice(i, 1); else f.push(Number(id));
        renderIdeas();
    }

    function renderIdeasTagFilter() {
        if (!ideasTagFilterMount) return;
        ideasTagFilterMount.replaceChildren(TareasTags.filterBar({
            tags: state.tags,
            selected: state.tagFilter.ideas,
            onToggle: toggleIdeaTagFilter,
            onClear: () => { state.tagFilter.ideas = []; renderIdeas(); },
        }) || "");
    }

    // El grid de ideas es persistente: el reordenamiento (drag & drop) se cablea
    // una sola vez sobre él y el render incremental sólo parchea su contenido,
    // de modo que los listeners de DnD nunca se duplican entre renders.
    let ideasGrid = null;
    function ensureIdeasGrid() {
        if (ideasGrid) return ideasGrid;
        ideasGrid = el("div", { class: "tk-ideas-grid" });
        TareasSortable.attach(ideasGrid, {
            itemSelector: ".tk-idea",
            handleSelector: ".tk-drag-handle",
            axis: "grid",
            describe: (el) => el.dataset.label || "idea",
            onCommit: (orderedIds) => {
                const prevIds = state.ideas.map((i) => String(i.id));
                runCommand(reorderIdeasCommand(orderedIds, prevIds));
            },
        });
        return ideasGrid;
    }

    function renderIdeas() {
        const query = state.search.ideas.trim();
        const tagFilter = state.tagFilter.ideas;
        let list = state.ideas;
        // Lógica OR: una idea pasa el filtro si lleva al menos una etiqueta activa.
        if (tagFilter.length) list = list.filter((i) => (i.tags || []).some((t) => tagFilter.includes(t.id)));
        if (query) list = list.filter((i) => matches(i.content, query));
        const filtered = query !== "" || tagFilter.length > 0;
        // El reordenamiento sólo es coherente sobre la lista completa (sin
        // filtro activo), donde el orden visible == orden real.
        const sortable = !filtered && state.ideas.length > 1;
        // Las herramientas sólo tienen sentido si hay ideas para filtrar.
        $("tk-ideas-tools").style.display = state.ideas.length ? "" : "none";
        renderIdeasTagFilter();

        const mount = $("tk-ideas-mount");
        if (!list.length) {
            // Estado vacío: descarta el grid persistente y su caché de claves.
            TareasReconcile.reset(ideasGrid);
            mount.replaceChildren(filtered ? TareasIdeas.noResults() : TareasIdeas.emptyState());
            return;
        }
        const grid = ensureIdeasGrid();
        grid.classList.toggle("is-sortable", sortable);
        if (grid.parentNode !== mount) mount.replaceChildren(grid);
        TareasReconcile.children(grid, TareasIdeas.plan(list, ideaHandlers, { query }), { enterClass: "is-entering", leaveClass: "is-leaving" });
    }

    // ── Misiones ─────────────────────────────────────────────
    const misionHandlers = {
        onToggleDone: (m) => runCommand(setStatusCommand(m, m.status === "completada" ? "pendiente" : "completada")),
        onSetStatus: (m, status) => runCommand(setStatusCommand(m, status)),
        onTagClick: (tag) => toggleMisionTagFilter(tag.id),
        onArchive: (m) => runCommand(archiveMisionCommand(m), { undoToast: "Misión archivada." }),
        onEdit: guard(async (m) => {
            await openModal({
                title: "Editar misión",
                fields: [
                    { name: "title", label: "Título", value: m.title, maxlength: 160 },
                    { name: "description", label: "Descripción", type: "textarea", value: m.description || "", maxlength: 4000 },
                    { name: "priority", label: "Prioridad", type: "select", value: m.priority, options: [
                        { value: "alta", label: "Alta" }, { value: "media", label: "Media" }, { value: "baja", label: "Baja" },
                    ] },
                    dueDateField(m.due_date),
                    recurrenceField(m.recurrence),
                    { name: "accent", label: "Color", type: "swatch", value: m.accent || "violet", options: ACCENT_OPTIONS },
                    tagPickerField(tagIdsOf(m)),
                ],
                submitLabel: "Guardar",
                onSubmit: async (v) => {
                    if (!v.title.trim()) { toast("El título es obligatorio.", "error"); return false; }
                    await commitCommand(editMisionCommand(m, { title: v.title, description: v.description, priority: v.priority, due_date: v.due_date, recurrence: v.recurrence, accent: v.accent, tag_ids: v.tagIds }));
                    toast("Misión actualizada.", "success");
                },
            });
        }),
        onAssign: guard(async (m) => {
            const res = await TareasColaboradores.openPicker(state.colaboradores, m.colaborador_id);
            if (!res) return;
            await runCommand(assignCommand(m, res.id));
        }),
        onDuplicate: (m) => runCommand(duplicateMisionCommand(m), { success: "Misión duplicada." }),
        // Comentarios y adjuntos viven en su propio panel y mutan fuera del
        // historial (el borrado de adjuntos es irreversible en disco): tras
        // cada cambio sólo resincronizamos los contadores de la tarjeta.
        onOpenDetail: (m) => TareasMisionDetail.open(m, {
            onChanged: () => refreshCollections(["misiones"]).catch((err) => {
                if (err instanceof TareasAPI.ApiError && err.status === 401) return relock();
                toast(err.message || "No se pudo actualizar.", "error");
            }),
        }),
        onAddItem: (misionId, content) => runCommand(addItemCommand(findMision(misionId) || { id: misionId }, content)),
        onToggleItem: (item) => runCommand(toggleItemCommand(item)),
        onUpdateItem: (item, content) => runCommand(updateItemCommand(item, content), { success: "Ítem actualizado." }),
        onDeleteItem: (item) => runCommand(deleteItemCommand(item), { undoToast: "Ítem eliminado." }),
    };

    // ── Orden / agrupado de misiones ─────────────────────────
    const PRIORITY_RANK = { alta: 0, media: 1, baja: 2 };
    const STATUS_ORDER = ["pendiente", "en_progreso", "completada"];

    // Desempate estable y legible: prioridad, luego más recientes primero.
    const byPriority = (a, b) =>
        (PRIORITY_RANK[a.priority] ?? 9) - (PRIORITY_RANK[b.priority] ?? 9) ||
        (b.created_at || "").localeCompare(a.created_at || "");
    const byRecent = (a, b) => (b.created_at || "").localeCompare(a.created_at || "");

    const groupBy = (list, keyFn, order) => {
        const buckets = new Map();
        list.forEach((m) => {
            const k = keyFn(m);
            if (!buckets.has(k)) buckets.set(k, []);
            buckets.get(k).push(m);
        });
        const keys = order ? order.filter((k) => buckets.has(k)) : [...buckets.keys()].sort((a, b) => a.localeCompare(b));
        return keys.map((k) => ({ key: k, items: buckets.get(k).sort(byPriority) }));
    };

    const SORTS = {
        manual:    { label: "Manual", build: (l) => ({ flat: l }) },
        prioridad: { label: "Prioridad", build: (l) => ({ flat: [...l].sort(byPriority) }) },
        recientes: { label: "Recientes", build: (l) => ({ flat: [...l].sort(byRecent) }) },
        vencimiento: { label: "Vencimiento", build: (l) => {
            const isActive = (m) => m.status !== "completada" && m.status !== "archivada";
            // "soon" (vence en ≤2 días) se fusiona con "upcoming" para no
            // fragmentar de más; el orden interno por fecha ya lo distingue.
            const bucketOf = (m) => {
                if (!m.due_date) return "none";
                const b = TareasDates.classify(m.due_date, { active: isActive(m) });
                return b === "soon" ? "upcoming" : b;
            };
            const order = ["overdue", "today", "upcoming", "none"];
            const LABEL = { overdue: "Vencidas", today: "Hoy", upcoming: "Próximas", none: "Sin fecha" };
            const byDue = (a, b) => (a.due_date || "").localeCompare(b.due_date || "") || byPriority(a, b);
            const groups = groupBy(l, bucketOf, order).map((g) => ({
                label: LABEL[g.key] || g.key,
                items: g.key === "none" ? g.items : [...g.items].sort(byDue),
            }));
            return { flat: groups.flatMap((g) => g.items), groups };
        } },
        estado:    { label: "Estado", build: (l) => {
            const groups = groupBy(l, (m) => m.status, STATUS_ORDER)
                .map((g) => ({ label: TareasMisiones.STATUS_LABEL[g.key] || g.key, items: g.items }));
            return { flat: groups.flatMap((g) => g.items), groups };
        } },
        colaborador: { label: "Colaborador", build: (l) => {
            const assigned = l.filter((m) => m.colaborador_name);
            const unassigned = l.filter((m) => !m.colaborador_name);
            const groups = groupBy(assigned, (m) => m.colaborador_name);
            if (unassigned.length) groups.push({ key: "", items: unassigned.sort(byPriority) });
            const labeled = groups.map((g) => ({ label: g.key || "Sin asignar", items: g.items }));
            return { flat: labeled.flatMap((g) => g.items), groups: labeled };
        } },
    };
    const SORT_STORAGE_KEY = "tareas:misiones-sort";
    const FILTER_STORAGE_KEY = "tareas:misiones-filter";
    const FILTER_VALUES = ["todas", "pendiente", "en_progreso", "completada"];

    function filteredMisiones() {
        const query = state.search.misiones.trim();
        const tagFilter = state.tagFilter.misiones;
        return state.misiones.filter((m) => {
            if (state.filter !== "todas" && m.status !== state.filter) return false;
            // Lógica OR: pasa si lleva al menos una de las etiquetas activas.
            if (tagFilter.length && !(m.tags || []).some((t) => tagFilter.includes(t.id))) return false;
            if (query && !(matches(m.title, query) || matches(m.description || "", query) || matches(m.colaborador_name || "", query))) return false;
            return true;
        });
    }

    function toggleMisionTagFilter(id) {
        const f = state.tagFilter.misiones;
        const i = f.indexOf(Number(id));
        if (i >= 0) f.splice(i, 1); else f.push(Number(id));
        renderMisiones();
    }

    function renderMisionesTagFilter() {
        if (!misionesTagFilterMount) return;
        misionesTagFilterMount.replaceChildren(TareasTags.filterBar({
            tags: state.tags,
            selected: state.tagFilter.misiones,
            onToggle: toggleMisionTagFilter,
            onClear: () => { state.tagFilter.misiones = []; renderMisiones(); },
        }) || "");
    }

    // Cambia el filtro de estado desde cualquier control (chips o tarjetas de
    // resumen) manteniendo todo sincronizado y persistido.
    function setMisionFilter(value) {
        if (!FILTER_VALUES.includes(value)) return;
        state.filter = value;
        storage.set(FILTER_STORAGE_KEY, value);
        syncFilterChips();
        renderMisiones();
    }

    function syncFilterChips() {
        document.querySelectorAll("#tk-mis-toolbar .tk-chip[data-filter]").forEach((c) => {
            c.classList.toggle("is-active", c.dataset.filter === state.filter);
        });
    }

    // ── Resumen / mini-dashboard de misiones ─────────────────
    // Conteos sobre las misiones activas (se excluyen las archivadas) y avance
    // global = % completadas. Cada tarjeta actúa de atajo al filtro de estado.
    const STAT_DEFS = [
        { key: "todas", label: "Total", filter: "todas" },
        { key: "pendiente", label: "Pendientes", filter: "pendiente" },
        { key: "en_progreso", label: "En progreso", filter: "en_progreso" },
        { key: "completada", label: "Completadas", filter: "completada" },
    ];

    function misionStats() {
        const counts = { todas: 0, pendiente: 0, en_progreso: 0, completada: 0 };
        state.misiones.forEach((m) => {
            if (m.status === "archivada") return;
            counts.todas++;
            if (counts[m.status] != null) counts[m.status]++;
        });
        const pct = counts.todas ? Math.round((counts.completada / counts.todas) * 100) : 0;
        return { counts, pct };
    }

    function renderMisionStats() {
        const mount = $("tk-mis-stats");
        if (!mount) return;
        const { counts, pct } = misionStats();
        const cards = STAT_DEFS.map((d) => {
            const active = state.filter === d.filter;
            const card = el("button", {
                class: `tk-stat tk-stat--${d.key}${active ? " is-active" : ""}`,
                type: "button", "aria-pressed": String(active),
                "aria-label": `${d.label}: ${counts[d.key]}. Filtrar.`,
            }, [
                el("span", { class: "tk-stat-num", text: String(counts[d.key]) }),
                el("span", { class: "tk-stat-label", text: d.label }),
            ]);
            card.addEventListener("click", () => setMisionFilter(d.filter));
            return card;
        });
        const progress = el("div", {
            class: "tk-stat tk-stat--progress", role: "group",
            "aria-label": `Avance global: ${pct}% completado`,
        }, [
            el("div", { class: "tk-stat-progresshead" }, [
                el("span", { class: "tk-stat-num", text: `${pct}%` }),
                el("span", { class: "tk-stat-label", text: "Avance" }),
            ]),
            el("div", { class: "tk-progress" }, [el("span", { style: { width: `${pct}%` } })]),
        ]);
        mount.replaceChildren(el("div", { class: "tk-stats", role: "group", "aria-label": "Resumen de misiones" }, [...cards, progress]));
    }

    // La lista de misiones es persistente: el DnD de tarjetas se cablea una vez
    // sobre ella y el render incremental sólo parchea su contenido (sin duplicar
    // listeners ni perder foco/scroll/estado de arrastre).
    let misionesList = null;
    function ensureMisionesList() {
        if (misionesList) return misionesList;
        misionesList = el("div", { class: "tk-missions" });
        TareasSortable.attach(misionesList, {
            itemSelector: ".tk-mission",
            handleSelector: ".tk-mission-head > .tk-drag-handle",
            axis: "list",
            describe: (el) => el.dataset.label || "misión",
            onCommit: (orderedIds) => {
                const prevIds = state.misiones.map((m) => String(m.id));
                runCommand(reorderMisionesCommand(orderedIds, prevIds));
            },
        });
        return misionesList;
    }

    function renderMisiones() {
        renderMisionStats();
        const query = state.search.misiones.trim();
        const isFiltered = query !== "" || state.filter !== "todas" || state.tagFilter.misiones.length > 0;
        const sort = SORTS[state.sort] || SORTS.prioridad;
        const { flat, groups = null } = sort.build(filteredMisiones());
        // Reordenar misiones requiere orden "Manual" y sin filtros/búsqueda
        // que oculten parte de la lista (orden visible == orden real).
        const sortable = state.sort === "manual" && !isFiltered && flat.length > 1;
        // El orden visible alimenta "seleccionar todo" y el rango Shift+clic;
        // se descartan de la selección las misiones que ya no están visibles.
        lastRenderedMisionIds = flat.map((m) => m.id);
        if (state.selection.size) {
            const visible = new Set(lastRenderedMisionIds);
            state.selection.forEach((id) => { if (!visible.has(id)) state.selection.delete(id); });
        }
        renderMisionesTagFilter();

        const mount = $("tk-misiones-mount");
        if (!flat.length) {
            TareasReconcile.reset(misionesList);
            mount.replaceChildren(isFiltered ? TareasMisiones.noResults() : TareasMisiones.emptyState());
        } else {
            const listEl = ensureMisionesList();
            listEl.classList.toggle("is-sortable", sortable);
            if (listEl.parentNode !== mount) mount.replaceChildren(listEl);
            TareasReconcile.children(listEl, TareasMisiones.plan(flat, misionHandlers, { query, selection: selectionView(), groups }), { enterClass: "is-entering", leaveClass: "is-leaving" });
            attachChecklistSortables();
        }
        syncBulkArchive();
        renderBulkBar();
        renderReminders();
    }

    // El DnD de cada checklist se cablea una sola vez por elemento. Las tarjetas
    // reutilizadas conservan su checklist (y su listener ya activo); las
    // recreadas traen un checklist nuevo, ausente del WeakSet, que se cablea
    // entonces. Así nunca se duplican listeners sobre el mismo nodo.
    const wiredChecklists = new WeakSet();
    function attachChecklistSortables() {
        if (!misionesList) return;
        misionesList.querySelectorAll(".tk-mission").forEach((cardEl) => {
            const checklist = cardEl.querySelector(".tk-checklist");
            if (!checklist || wiredChecklists.has(checklist)) return;
            if (checklist.querySelectorAll(".tk-checklist-item").length < 2) return;
            const misionId = Number(cardEl.dataset.id);
            wiredChecklists.add(checklist);
            TareasSortable.attach(checklist, {
                itemSelector: ".tk-checklist-item",
                handleSelector: ".tk-drag-handle",
                axis: "list",
                describe: (el) => el.dataset.label || "ítem",
                onCommit: (orderedIds) => {
                    const m = findMision(misionId);
                    const prevIds = (m && m.checklist || []).map((i) => String(i.id));
                    runCommand(reorderItemsCommand(misionId, orderedIds, prevIds));
                },
            });
        });
    }

    let bulkArchiveBtn = null;
    function syncBulkArchive() {
        if (!bulkArchiveBtn) return;
        const n = state.misiones.filter((m) => m.status === "completada").length;
        bulkArchiveBtn.style.display = n ? "" : "none";
        bulkArchiveBtn.querySelector(".tk-bulk-count").textContent = String(n);
    }

    // ── Selección múltiple / acciones en lote ────────────────
    // `selectModeBtn`/`bulkBarMount` se crean en la toolbar; `selectionAnchor`
    // ancla el rango para Shift+clic; `lastRenderedMisionIds` refleja el orden
    // visible actual (base de "seleccionar todo" y del rango).
    let selectModeBtn = null;
    let bulkBarMount = null;
    let selectionAnchor = null;
    let lastRenderedMisionIds = [];

    /** Vista de selección que recibe la capa de render (o null si está off). */
    const selectionView = () => state.selectMode ? {
        mode: true,
        has: (id) => state.selection.has(Number(id)),
        onToggle: toggleSelect,
    } : null;

    function setSelectMode(on) {
        state.selectMode = on;
        if (!on) { state.selection.clear(); selectionAnchor = null; }
        if (selectModeBtn) selectModeBtn.setAttribute("aria-pressed", String(on));
        renderMisiones();
    }
    const toggleSelectMode = () => setSelectMode(!state.selectMode);
    const exitSelectMode = () => { if (state.selectMode) setSelectMode(false); };

    /** Refresca clase/aria de una sola tarjeta sin repintar la lista entera. */
    function paintCardSelection(id) {
        const card = $("tk-misiones-mount").querySelector(`.tk-mission[data-id="${id}"]`);
        if (!card) return;
        const on = state.selection.has(id);
        card.classList.toggle("is-selected", on);
        const box = card.querySelector(".tk-select-box");
        if (box) { box.classList.toggle("is-checked", on); box.setAttribute("aria-checked", String(on)); }
    }

    function toggleSelect(id, event) {
        id = Number(id);
        // Shift+clic: selecciona el rango contiguo desde el ancla previa.
        if (event && event.shiftKey && selectionAnchor != null) {
            const a = lastRenderedMisionIds.indexOf(selectionAnchor);
            const b = lastRenderedMisionIds.indexOf(id);
            if (a >= 0 && b >= 0) {
                const [lo, hi] = a < b ? [a, b] : [b, a];
                for (let i = lo; i <= hi; i++) state.selection.add(lastRenderedMisionIds[i]);
                selectionAnchor = id;
                renderMisiones();
                return;
            }
        }
        if (state.selection.has(id)) state.selection.delete(id); else state.selection.add(id);
        selectionAnchor = id;
        paintCardSelection(id);
        renderBulkBar();
    }

    function selectAllVisible() {
        const ids = lastRenderedMisionIds;
        if (!ids.length) return;
        const allSelected = ids.every((id) => state.selection.has(id));
        if (allSelected) state.selection.clear();
        else ids.forEach((id) => state.selection.add(id));
        selectionAnchor = null;
        renderMisiones();
    }

    const BULK_DONE = {
        status: "Estado actualizado", priority: "Prioridad actualizada",
        assign: "Asignación actualizada", archive: "Misiones archivadas",
    };
    function applyBulk(op, value) {
        const missions = [...state.selection].map(findMision).filter(Boolean);
        if (!missions.length) return;
        const n = missions.length;
        runCommand(bulkCommand(op, value, missions), { undoToast: `${BULK_DONE[op]} (${n}).` });
        setSelectMode(false);
    }

    /**
     * Select de "acción de un disparo": la primera opción es un placeholder y,
     * tras elegir una acción, vuelve a él para poder repetir la misma acción.
     */
    function actionSelect(placeholder, ariaLabel, options, onPick) {
        const sel = select({
            ariaLabel, value: "", className: "tk-select--pill",
            options: [{ value: "", label: placeholder }, ...options],
            onChange: (v) => { if (v === "") return; sel.value = ""; onPick(v); },
        });
        return sel;
    }

    function renderBulkBar() {
        if (!bulkBarMount) return;
        if (!state.selectMode) { bulkBarMount.replaceChildren(); return; }

        const n = state.selection.size;
        const ids = lastRenderedMisionIds;
        const allSelected = ids.length > 0 && ids.every((id) => state.selection.has(id));

        const count = el("span", {
            class: "tk-bulkbar-count", "aria-live": "polite", role: "status",
            text: n ? `${n} seleccionada${n === 1 ? "" : "s"}` : "Ninguna seleccionada",
        });
        const selectAllBtn = el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm", type: "button", text: allSelected ? "Quitar todo" : "Seleccionar todo" });
        selectAllBtn.addEventListener("click", selectAllVisible);

        const statusSel = actionSelect("Estado…", "Cambiar estado de las seleccionadas", [
            { value: "pendiente", label: "Pendiente" },
            { value: "en_progreso", label: "En progreso" },
            { value: "completada", label: "Completada" },
        ], (v) => applyBulk("status", v));
        const prioritySel = actionSelect("Prioridad…", "Cambiar prioridad de las seleccionadas", [
            { value: "alta", label: "Alta" }, { value: "media", label: "Media" }, { value: "baja", label: "Baja" },
        ], (v) => applyBulk("priority", v));
        const assignSel = actionSelect("Asignar…", "Asignar las seleccionadas", [
            { value: "0", label: "Sin asignar" },
            ...state.colaboradores.map((c) => ({ value: String(c.id), label: c.name })),
        ], (v) => applyBulk("assign", v));
        const archiveBtn = el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm tk-btn--danger", type: "button" }, [icon("inbox", 15), el("span", { text: "Archivar" })]);
        archiveBtn.addEventListener("click", () => applyBulk("archive"));

        const actions = el("div", { class: `tk-bulkbar-actions${n ? "" : " is-disabled"}`, "aria-disabled": String(n === 0) },
            [statusSel, prioritySel, assignSel, archiveBtn]);

        const exitBtn = el("button", { class: "tk-icon-btn", type: "button", title: "Salir de selección", "aria-label": "Salir de selección" }, [icon("close", 16)]);
        exitBtn.addEventListener("click", exitSelectMode);

        bulkBarMount.replaceChildren(el("div", { class: "tk-bulkbar", role: "toolbar", "aria-label": "Acciones en lote" }, [
            count, selectAllBtn, actions, exitBtn,
        ]));
    }

    // ── Recordatorios in-app ─────────────────────────────────
    const REMINDER_DISMISS_KEY = "tareas:reminders-dismissed";
    const REMINDER_NOTIFIED_KEY = "tareas:reminders-notified";

    /** Misiones activas vencidas o que vencen hoy (base de los recordatorios). */
    function dueReminders() {
        const overdue = [], today = [];
        state.misiones.forEach((m) => {
            if (!m.due_date || m.status === "completada" || m.status === "archivada") return;
            const bucket = TareasDates.classify(m.due_date, { active: true });
            if (bucket === "overdue") overdue.push(m);
            else if (bucket === "today") today.push(m);
        });
        return { overdue, today };
    }

    /** Frase "N vencidas y M para hoy" reutilizada por banner y notificación. */
    function reminderPhrase(overdue, today) {
        const parts = [];
        if (overdue.length) parts.push(`${overdue.length} ${overdue.length > 1 ? "vencidas" : "vencida"}`);
        if (today.length) parts.push(`${today.length} para hoy`);
        return parts.join(" y ");
    }

    /** Notificación del navegador (opt-in), como mucho una vez por día. */
    function maybeNotify(overdue, today) {
        if (!("Notification" in window) || Notification.permission !== "granted") return;
        if (storage.get(REMINDER_NOTIFIED_KEY) === TareasDates.todayISO()) return;
        storage.set(REMINDER_NOTIFIED_KEY, TareasDates.todayISO());
        try {
            new Notification("Tareas · recordatorio", {
                body: `Tienes ${reminderPhrase(overdue, today)}.`,
                tag: "tareas-reminders",
            });
        } catch { /* algunos navegadores exigen Service Worker para notificar */ }
    }

    function renderReminders() {
        const mount = $("tk-reminders-mount");
        if (!mount) return;
        const { overdue, today } = dueReminders();
        if ((overdue.length + today.length) === 0 ||
            storage.get(REMINDER_DISMISS_KEY) === TareasDates.todayISO()) {
            mount.replaceChildren();
            return;
        }
        maybeNotify(overdue, today);

        const seeBtn = el("button", { class: "tk-btn tk-btn--sm tk-btn--ghost", type: "button", text: "Ver" });
        seeBtn.addEventListener("click", () => {
            state.filter = "todas"; storage.set(FILTER_STORAGE_KEY, "todas");
            state.tagFilter.misiones = [];
            state.sort = "vencimiento"; storage.set(SORT_STORAGE_KEY, "vencimiento");
            buildToolbar();
            renderMisiones();
        });

        const children = [
            icon("bell", 18),
            el("p", { class: "tk-reminder-text", text: `Tienes ${reminderPhrase(overdue, today)}.` }),
            seeBtn,
        ];

        if ("Notification" in window && Notification.permission === "default") {
            const notifyBtn = el("button", { class: "tk-btn tk-btn--sm tk-btn--ghost", type: "button" },
                [icon("bell", 14), el("span", { text: "Activar avisos" })]);
            notifyBtn.addEventListener("click", async () => {
                try { await Notification.requestPermission(); } catch { /* ignorado */ }
                renderReminders();
            });
            children.push(notifyBtn);
        }

        const dismiss = el("button", {
            class: "tk-icon-btn tk-reminder-close", type: "button",
            title: "Descartar por hoy", "aria-label": "Descartar recordatorios por hoy",
        }, [icon("close", 16)]);
        dismiss.addEventListener("click", () => {
            storage.set(REMINDER_DISMISS_KEY, TareasDates.todayISO());
            mount.replaceChildren();
        });
        children.push(dismiss);

        const tone = overdue.length ? "is-overdue" : "is-today";
        mount.replaceChildren(el("div", { class: `tk-reminder ${tone}`, role: "status" }, children));
    }

    function archiveCompleted() {
        const completed = state.misiones.filter((m) => m.status === "completada");
        if (!completed.length) return;
        const word = completed.length === 1 ? "misión archivada" : "misiones archivadas";
        runCommand(archiveCompletedCommand(completed), { undoToast: `${completed.length} ${word}.` });
    }

    // ── Composer de ideas ────────────────────────────────────
    function buildComposer() {
        const textarea = el("textarea", { placeholder: "¿Qué idea no quieres que se te escape? Escríbela y guárdala…", "aria-label": "Nueva idea", rows: "2", maxlength: String(IDEA_MAXLEN) });
        autoGrow(textarea);
        const counter = charCounter(textarea, IDEA_MAXLEN);
        const accentPicker = swatchPicker({ value: "violet", options: ACCENT_OPTIONS, ariaLabel: "Color de la idea" });

        const saveBtn = el("button", { class: "tk-btn tk-btn--primary", type: "button", disabled: true }, [icon("bolt", 16, { filled: true }), el("span", { text: "Guardar idea" })]);

        // El botón sólo se habilita con contenido: así clickearlo nunca roba
        // el foco al textarea cuando está vacío.
        const syncDisabled = () => { saveBtn.disabled = textarea.value.trim() === ""; };

        const save = async () => {
            const content = textarea.value.trim();
            if (!content) return;
            saveBtn.disabled = true;
            try {
                await commitCommand(createIdeaCommand({ content, accent: accentPicker.value }));
                textarea.value = "";
                // Reajusta alto, contador y estado del botón tras limpiar.
                textarea.dispatchEvent(new Event("input"));
            } catch (err) {
                if (err instanceof TareasAPI.ApiError && err.status === 401) return relock();
                toast(err.message || "No se pudo guardar la idea.", "error");
            } finally { syncDisabled(); }
        };

        saveBtn.addEventListener("click", save);
        textarea.addEventListener("input", syncDisabled);
        textarea.addEventListener("keydown", (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === "Enter") save();
        });

        // El resalte del composer se ata al foco real del textarea (no a
        // :focus-within), para que clickear swatches/botón no lo "encienda".
        const composer = el("div", { class: "tk-composer" }, [
            textarea,
            el("div", { class: "tk-composer-bar" }, [
                accentPicker,
                el("span", { class: "tk-hint", text: "Ctrl + Enter para guardar" }),
                counter,
                saveBtn,
            ]),
        ]);
        textarea.addEventListener("focus", () => composer.classList.add("is-focused"));
        textarea.addEventListener("blur", () => composer.classList.remove("is-focused"));
        $("tk-composer-mount").replaceChildren(composer);
    }

    // ── Toolbar de misiones ──────────────────────────────────
    function buildToolbar() {
        const FILTERS = [
            ["todas", "Todas"], ["pendiente", "Pendientes"],
            ["en_progreso", "En progreso"], ["completada", "Completadas"],
        ];
        const chips = el("div", { class: "tk-filter" },
            FILTERS.map(([value, label]) => {
                const chip = el("button", {
                    class: `tk-chip${value === state.filter ? " is-active" : ""}`,
                    type: "button", text: label, dataset: { filter: value },
                });
                chip.addEventListener("click", () => setMisionFilter(value));
                return chip;
            }));

        const newBtn = el("button", { class: "tk-btn tk-btn--primary", type: "button" }, [icon("plus", 16), el("span", { text: "Nueva misión" })]);
        newBtn.addEventListener("click", openNewMision);

        misionesSearch = searchBar({
            placeholder: "Buscar misiones…",
            ariaLabel: "Buscar misiones",
            onInput: (v) => { state.search.misiones = v; renderMisiones(); },
        });

        bulkArchiveBtn = el("button", { class: "tk-btn tk-btn--ghost tk-btn--sm", type: "button", title: "Archivar todas las misiones completadas" }, [
            icon("inbox", 15),
            el("span", { text: "Archivar completadas" }),
            el("span", { class: "tk-bulk-count tk-count", text: "0" }),
        ]);
        bulkArchiveBtn.addEventListener("click", archiveCompleted);

        selectModeBtn = el("button", {
            class: "tk-btn tk-btn--ghost tk-btn--sm", type: "button",
            "aria-pressed": String(state.selectMode), title: "Seleccionar varias misiones",
        }, [icon("checkSquare", 15), el("span", { text: "Seleccionar" })]);
        selectModeBtn.addEventListener("click", toggleSelectMode);

        const sortControl = el("div", { class: "tk-toolbar-sort" }, [
            icon("sort", 15),
            select({
                ariaLabel: "Ordenar misiones",
                value: state.sort,
                className: "tk-select--pill",
                options: Object.entries(SORTS).map(([value, s]) => ({ value, label: s.label })),
                onChange: (v) => {
                    state.sort = v;
                    storage.set(SORT_STORAGE_KEY, v);
                    renderMisiones();
                },
            }),
        ]);

        misionesTagFilterMount = el("div", { class: "tk-tag-filter-mount" });
        bulkBarMount = el("div", { class: "tk-bulkbar-mount" });
        $("tk-mis-toolbar").replaceChildren(el("div", { class: "tk-mis-toolbar-wrap" }, [
            el("div", { class: "tk-toolbar" }, [newBtn, bulkArchiveBtn, selectModeBtn, chips, misionesSearch, sortControl]),
            misionesTagFilterMount,
            bulkBarMount,
        ]));
        renderMisionesTagFilter();
        syncBulkArchive();
        renderBulkBar();
    }

    // ── Tabs ─────────────────────────────────────────────────
    const TAB_STORAGE_KEY = "tareas:tab";
    const TABS = ["ideas", "misiones"];

    function selectTab(target) {
        if (!TABS.includes(target)) return;
        document.querySelectorAll(".tk-tab").forEach((t) => t.classList.toggle("is-active", t.dataset.view === target));
        document.querySelectorAll(".tk-view").forEach((v) => v.classList.toggle("is-active", v.id === `view-${target}`));
        storage.set(TAB_STORAGE_KEY, target);
    }
    function activeView() {
        return $("view-misiones").classList.contains("is-active") ? "misiones" : "ideas";
    }
    function wireTabs() {
        document.querySelectorAll(".tk-tab").forEach((tab) => {
            tab.addEventListener("click", () => selectTab(tab.dataset.view));
        });
    }

    // ── Crear misión (botón + atajo) ─────────────────────────
    const openNewMision = guard(async () => {
        await openModal({
            title: "Nueva misión",
            fields: [
                { name: "title", label: "Título", maxlength: 160, placeholder: "¿Qué hay que hacer?" },
                { name: "description", label: "Descripción (opcional)", type: "textarea", maxlength: 4000 },
                { name: "priority", label: "Prioridad", type: "select", value: "media", options: [
                    { value: "alta", label: "Alta" }, { value: "media", label: "Media" }, { value: "baja", label: "Baja" },
                ] },
                dueDateField(""),
                recurrenceField("none"),
                { name: "accent", label: "Color", type: "swatch", value: "violet", options: ACCENT_OPTIONS },
                tagPickerField([]),
            ],
            submitLabel: "Crear",
            onSubmit: async (v) => {
                if (!v.title.trim()) { toast("El título es obligatorio.", "error"); return false; }
                await commitCommand(createMisionCommand({ title: v.title, description: v.description, priority: v.priority, due_date: v.due_date, recurrence: v.recurrence, accent: v.accent, tag_ids: v.tagIds }));
                toast("Misión creada.", "success");
            },
        });
    });

    // ── Plantillas y respaldo (export/import) ────────────────
    /** Abre el gestor de plantillas; instanciar una crea y revela la misión. */
    function openTemplatesManager() {
        TareasTemplates.openManager({
            onMisionCreated: (misionId) => {
                selectTab("misiones");
                refreshCollections(["misiones"])
                    .then(() => { if (misionId) revealEntity("misiones", misionId); })
                    .catch((err) => {
                        if (err instanceof TareasAPI.ApiError && err.status === 401) return relock();
                        toast(err.message || "No se pudo actualizar.", "error");
                    });
            },
        });
    }

    /** Abre el menú de exportar/importar; una importación recarga todo. */
    function openDataIO() {
        TareasDataIO.open({
            onImported: () => bootData().catch((err) => {
                if (err instanceof TareasAPI.ApiError && err.status === 401) return relock();
                toast(err.message || "No se pudo recargar.", "error");
            }),
        });
    }

    // ── Atajos de teclado ────────────────────────────────────
    const SHORTCUTS = [
        { keys: ["Ctrl", "K"], desc: "Abrir la paleta de comandos / búsqueda" },
        { keys: ["N"], desc: "Nueva misión" },
        { keys: ["G", "I"], desc: "Ir a Ideas" },
        { keys: ["G", "M"], desc: "Ir a Misiones" },
        { keys: ["C"], desc: "Componer una idea" },
        { keys: ["/"], desc: "Buscar en la vista actual" },
        { keys: ["Ctrl", "A"], desc: "Seleccionar todas (en modo selección)" },
        { keys: ["Ctrl", "Z"], desc: "Deshacer la última acción" },
        { keys: ["Ctrl", "Y"], desc: "Rehacer" },
        { keys: ["?"], desc: "Mostrar esta ayuda" },
        { keys: ["Esc"], desc: "Cerrar diálogos y menús" },
        { keys: ["Ctrl", "Enter"], desc: "Guardar idea (en el composer)" },
    ];

    const isTypingTarget = (t) =>
        t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.tagName === "SELECT" || t.isContentEditable);

    // ¿Hay un diálogo/menú modal abierto? (modal genérico, ayuda, o dropdown).
    const overlayOpen = () =>
        document.querySelector(".tk-modal-backdrop.is-open, #tk-help-overlay, .tk-select.is-open") !== null;

    function focusActiveSearch() {
        const bar = activeView() === "misiones" ? misionesSearch : ideasSearch;
        if (activeView() === "ideas" && !state.ideas.length) return; // barra oculta
        if (bar) bar.input.focus();
    }

    /** Enfoca el composer de ideas (cambia a la pestaña Ideas si hace falta). */
    function focusComposer() {
        selectTab("ideas");
        const ta = $("tk-composer-mount").querySelector("textarea");
        if (ta) ta.focus();
    }

    function openShortcutsHelp() {
        if ($("tk-help-overlay")) return;
        const returnFocus = document.activeElement;
        const list = el("div", { class: "tk-shortcuts" }, SHORTCUTS.map((s) =>
            el("div", { class: "tk-shortcut" }, [
                el("span", { text: s.desc }),
                el("span", { class: "tk-keys" }, s.keys.map((k) => el("kbd", { class: "tk-kbd", text: k }))),
            ])));
        const closeBtn = el("button", { class: "tk-btn tk-btn--primary", type: "button", text: "Entendido" });
        const dialog = el("div", { class: "tk-modal", role: "dialog", "aria-modal": "true", "aria-labelledby": "tk-help-title" }, [
            el("h2", { id: "tk-help-title", text: "Atajos de teclado" }),
            list,
            el("div", { class: "tk-modal-actions" }, [closeBtn]),
        ]);
        const backdrop = el("div", { id: "tk-help-overlay", class: "tk-modal-backdrop" }, [dialog]);
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
        backdrop.addEventListener("mousedown", (e) => { if (e.target === backdrop) close(); });
        closeBtn.addEventListener("click", close);
        document.addEventListener("keydown", onKey, true);
        document.body.appendChild(backdrop);
        releaseTrap = TareasUI.trapFocus(dialog);
        requestAnimationFrame(() => backdrop.classList.add("is-open"));
        closeBtn.focus();
    }

    // ── Paleta de comandos (Ctrl/⌘-K) + búsqueda global ─────
    // Refleja el último estado del historial para mostrar Deshacer/Rehacer
    // sólo cuando hay algo que (des)hacer (TareasHistory no expone getState).
    let historyState = { canUndo: false, canRedo: false, undoLabel: null, redoLabel: null };

    const truncate = (text, max = 70) => {
        const t = String(text).replace(/\s+/g, " ").trim();
        return t.length > max ? `${t.slice(0, max - 1)}…` : t;
    };

    /**
     * Lleva el foco visual a una idea/misión concreta: cambia de vista,
     * limpia filtros/búsqueda para garantizar su visibilidad y resalta la
     * tarjeta brevemente. Reusa los setters existentes (persistencia + sync).
     */
    function revealEntity(view, id) {
        selectTab(view);
        if (view === "misiones") {
            state.tagFilter.misiones = [];
            state.search.misiones = "";
            if (misionesSearch) misionesSearch.input.value = "";
            setMisionFilter("todas"); // persiste, sincroniza chips y re-renderiza
        } else {
            state.tagFilter.ideas = [];
            state.search.ideas = "";
            if (ideasSearch) ideasSearch.input.value = "";
            renderIdeas();
        }
        const mountId = view === "misiones" ? "tk-misiones-mount" : "tk-ideas-mount";
        requestAnimationFrame(() => {
            const node = $(mountId).querySelector(`article[data-id="${CSS.escape(String(id))}"]`);
            if (!node) return;
            node.scrollIntoView({ behavior: "smooth", block: "center" });
            node.classList.add("tk-flash");
            setTimeout(() => node.classList.remove("tk-flash"), 1500);
        });
    }

    /** Construye la lista de ítems (acciones + resultados) para la paleta. */
    function paletteItems() {
        const G_ACTION = "Acciones";
        const items = [
            { group: G_ACTION, label: "Nueva misión", hint: "N", icon: "plus", keywords: ["crear", "tarea", "add"], run: () => { selectTab("misiones"); openNewMision(); } },
            { group: G_ACTION, label: "Componer una idea", hint: "C", icon: "bolt", keywords: ["nueva", "nota", "capturar"], run: focusComposer },
            { group: G_ACTION, label: "Ir a Ideas", hint: "G I", icon: "bolt", keywords: ["ver", "navegar"], run: () => selectTab("ideas") },
            { group: G_ACTION, label: "Ir a Misiones", hint: "G M", icon: "diana", keywords: ["ver", "navegar", "tareas"], run: () => selectTab("misiones") },
            { group: G_ACTION, label: state.selectMode ? "Salir del modo selección" : "Seleccionar varias misiones", icon: "checkSquare", keywords: ["lote", "multiple", "bulk"], run: () => { selectTab("misiones"); toggleSelectMode(); } },
            { group: G_ACTION, label: "Archivar misiones completadas", icon: "inbox", keywords: ["limpiar", "terminadas"], run: archiveCompleted },
            { group: G_ACTION, label: "Gestionar etiquetas", icon: "tag", keywords: ["tags", "labels"], run: () => TareasTags.openManager(() => state.tags, async () => { await refreshCollections(["tags", "ideas", "misiones"]); }) },
            { group: G_ACTION, label: "Gestionar colaboradores", icon: "users", keywords: ["equipo", "personas"], run: () => TareasColaboradores.openManager(() => state.colaboradores, async () => { await loadColaboradores(); renderMisiones(); }) },
            { group: G_ACTION, label: "Gestionar plantillas", icon: "layout", keywords: ["templates", "plantilla", "reutilizar"], run: openTemplatesManager },
            { group: G_ACTION, label: "Exportar / Importar datos", icon: "download", keywords: ["backup", "respaldo", "csv", "json", "export", "import"], run: openDataIO },
            { group: G_ACTION, label: "Cambiar tema (claro / oscuro)", icon: "moon", keywords: ["dark", "light", "modo"], run: () => $("tk-theme-toggle").click() },
            { group: G_ACTION, label: "Atajos de teclado", hint: "?", icon: "help", keywords: ["ayuda", "teclas"], run: openShortcutsHelp },
        ];
        if (historyState.canUndo) {
            items.push({ group: G_ACTION, label: `Deshacer${historyState.undoLabel ? `: ${historyState.undoLabel}` : ""}`, hint: "Ctrl Z", icon: "undo", keywords: ["undo", "revertir"], run: safeUndo });
        }
        if (historyState.canRedo) {
            items.push({ group: G_ACTION, label: `Rehacer${historyState.redoLabel ? `: ${historyState.redoLabel}` : ""}`, hint: "Ctrl Y", icon: "redo", keywords: ["redo"], run: safeRedo });
        }
        items.push({ group: G_ACTION, label: "Bloquear sesión", icon: "logout", keywords: ["salir", "logout", "cerrar"], run: guard(async () => { await TareasAPI.auth.logout(); relock(); }) });

        const tagName = (id) => { const t = state.tags.find((x) => x.id === id); return t ? t.name : ""; };
        state.ideas.forEach((idea) => {
            items.push({
                group: "Ideas", label: truncate(idea.content), icon: "bolt",
                keywords: (idea.tag_ids || []).map(tagName),
                run: () => revealEntity("ideas", idea.id),
            });
        });
        state.misiones.forEach((m) => {
            if (m.status === "archivada") return;
            items.push({
                group: "Misiones", label: truncate(m.title), hint: TareasMisiones.STATUS_LABEL[m.status] || "",
                icon: "diana", keywords: [m.description || "", ...(m.tag_ids || []).map(tagName)],
                run: () => revealEntity("misiones", m.id),
            });
        });
        return items;
    }

    function openPalette() {
        if (TareasPalette.isOpen()) { TareasPalette.close(); return; }
        TareasPalette.open({ getItems: paletteItems });
    }

    function wirePalette() {
        const btn = $("tk-cmd-btn");
        if (btn) btn.addEventListener("click", openPalette);
        document.addEventListener("keydown", (e) => {
            if (!(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey) return;
            if (e.key.toLowerCase() !== "k") return;
            if (!$("tk-app").classList.contains("is-ready")) return;
            e.preventDefault();
            openPalette();
        });
    }

    // Estado del acorde "g" (g+i / g+m): se arma al pulsar `g` y expira sola.
    let pendingGoto = false;
    let pendingGotoTimer = 0;
    const armGoto = () => {
        pendingGoto = true;
        clearTimeout(pendingGotoTimer);
        pendingGotoTimer = setTimeout(() => { pendingGoto = false; }, 900);
    };
    const clearGoto = () => { pendingGoto = false; clearTimeout(pendingGotoTimer); };

    function wireShortcuts() {
        document.addEventListener("keydown", (e) => {
            if (e.ctrlKey || e.metaKey || e.altKey) return;       // no pisar atajos del navegador
            if (!$("tk-app").classList.contains("is-ready")) return; // bloqueado por PIN
            if (isTypingTarget(e.target) || overlayOpen()) return;

            const key = e.key.toLowerCase();

            // Acorde de navegación: g seguido de i / m.
            if (pendingGoto) {
                if (key === "i") { e.preventDefault(); clearGoto(); selectTab("ideas"); return; }
                if (key === "m") { e.preventDefault(); clearGoto(); selectTab("misiones"); return; }
                clearGoto();
            }

            if (key === "g") { e.preventDefault(); armGoto(); }
            else if (key === "c") { e.preventDefault(); focusComposer(); }
            else if (e.key === "/") { e.preventDefault(); focusActiveSearch(); }
            else if (e.key === "?") { e.preventDefault(); openShortcutsHelp(); }
            else if (key === "escape" && state.selectMode) { e.preventDefault(); exitSelectMode(); }
            else if (key === "n") { e.preventDefault(); selectTab("misiones"); openNewMision(); }
        });
    }

    // Ctrl/⌘-A selecciona todas las misiones visibles, pero sólo en modo
    // selección y en la vista de Misiones (fuera de ahí no pisa el navegador).
    function wireSelectAll() {
        document.addEventListener("keydown", (e) => {
            if (!(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey) return;
            if (e.key.toLowerCase() !== "a") return;
            if (!$("tk-app").classList.contains("is-ready")) return;
            if (!state.selectMode || activeView() !== "misiones") return;
            if (isTypingTarget(e.target) || overlayOpen()) return;
            e.preventDefault();
            lastRenderedMisionIds.forEach((id) => state.selection.add(id));
            selectionAnchor = null;
            renderMisiones();
        });
    }

    // Deshacer/Rehacer (Ctrl+Z / Ctrl+Y / Ctrl+Shift+Z). Se respeta el
    // deshacer NATIVO mientras se escribe en un campo y cuando hay un overlay.
    function wireHistoryShortcuts() {
        document.addEventListener("keydown", (e) => {
            if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
            if (!$("tk-app").classList.contains("is-ready")) return;
            if (isTypingTarget(e.target) || overlayOpen()) return;

            const key = e.key.toLowerCase();
            if (key === "z" && !e.shiftKey) { e.preventDefault(); safeUndo(); }
            else if (key === "y" || (key === "z" && e.shiftKey)) { e.preventDefault(); safeRedo(); }
        });
    }

    // ── Estados de carga y error (#11) ───────────────────────
    // Skeletons durante la carga inicial (evitan el salto desde una pantalla en
    // blanco) y una tarjeta de error con "Reintentar" ante fallos de red.
    function skeletonList(n, kind) {
        const cards = [];
        for (let i = 0; i < n; i++) {
            cards.push(el("div", { class: `tk-skel tk-skel--${kind}`, "aria-hidden": "true" }, [
                el("div", { class: "tk-skel-bar tk-skel-bar--title" }),
                el("div", { class: "tk-skel-bar" }),
                el("div", { class: "tk-skel-bar tk-skel-bar--short" }),
            ]));
        }
        return el("div", {
            class: kind === "mision" ? "tk-missions" : "tk-ideas-grid",
            role: "status", "aria-busy": "true", "aria-label": "Cargando…",
        }, cards);
    }

    function errorCard(onRetry) {
        const retry = el("button", { class: "tk-btn tk-btn--primary", type: "button" }, [
            icon("undo", 16), el("span", { text: "Reintentar" }),
        ]);
        retry.addEventListener("click", onRetry);
        return el("div", { class: "tk-loaderror", role: "alert" }, [
            icon("bell", 30),
            el("h3", { class: "tk-loaderror-title", text: "No se pudieron cargar los datos." }),
            el("p", { class: "tk-loaderror-hint", text: "Revisa tu conexión e inténtalo de nuevo." }),
            retry,
        ]);
    }

    function showLoading() {
        TareasReconcile.reset(ideasGrid);
        TareasReconcile.reset(misionesList);
        $("tk-ideas-mount").replaceChildren(skeletonList(3, "idea"));
        $("tk-misiones-mount").replaceChildren(skeletonList(4, "mision"));
    }

    function showLoadError() {
        TareasReconcile.reset(ideasGrid);
        TareasReconcile.reset(misionesList);
        $("tk-ideas-mount").replaceChildren(errorCard(bootData));
        $("tk-misiones-mount").replaceChildren(errorCard(bootData));
    }

    /** Carga inicial con feedback visible: skeleton → datos, o tarjeta de error. */
    async function bootData() {
        showLoading();
        try {
            await loadAll();
        } catch (err) {
            if (err instanceof TareasAPI.ApiError && err.status === 401) return relock();
            showLoadError();
            toast(err.message || "No se pudo cargar.", "error");
        }
    }

    // ── Tiempo real (SSE) ────────────────────────────────────
    // Cada aviso del canal indica qué colección cambió en otra pestaña/
    // colaborador; resincronizamos sólo esa vista por el mismo camino
    // incremental que ya repinta únicamente las tarjetas afectadas. El
    // mapa traduce el "scope" del backend a las colecciones a recargar
    // (p. ej. renombrar una etiqueta altera los chips de ideas y misiones).
    const REALTIME_SCOPES = {
        ideas:         ["ideas"],
        misiones:      ["misiones"],
        colaboradores: ["colaboradores"],
        tags:          ["tags", "ideas", "misiones"],
        all:           ["tags", "colaboradores", "ideas", "misiones"],
    };

    // Coalescencia de resincronías: una ráfaga de avisos (varios scopes
    // seguidos) se funde en un único refresco por frame con la UNIÓN de las
    // colecciones afectadas. Evita GETs solapados redundantes que podrían
    // llegar fuera de orden, y deja un solo repintado con el estado final.
    const pendingScopes = new Set();
    let realtimeFlush = 0;

    function flushRealtime() {
        realtimeFlush = 0;
        const cols = new Set();
        for (const scope of pendingScopes) {
            for (const col of REALTIME_SCOPES[scope]) cols.add(col);
        }
        pendingScopes.clear();
        if (!cols.size) return;
        refreshCollections([...cols]).catch((err) => {
            if (err instanceof TareasAPI.ApiError && err.status === 401) return relock();
            // Un fallo puntual de resincronía no debe molestar al usuario: la
            // próxima señal (o recarga) lo corrige. Lo dejamos en consola.
            console.warn("[tareas] resincronía en tiempo real fallida:", err);
        });
    }

    function onRealtimeChange(scope) {
        if (!REALTIME_SCOPES[scope]) return; // scope desconocido: lo ignoramos
        pendingScopes.add(scope);
        if (realtimeFlush) return; // ya hay un flush encolado para este frame
        realtimeFlush = typeof requestAnimationFrame === "function"
            ? requestAnimationFrame(flushRealtime)
            : setTimeout(flushRealtime, 16);
    }

    function startRealtime() {
        TareasRealtime.start({
            clientId: TareasAPI.getClientId(),
            onChange: onRealtimeChange,
        });
    }

    // ── Acceso / bloqueo ─────────────────────────────────────
    function reveal() { $("tk-app").classList.add("is-ready"); }

    /** Entra a la app tras autenticarse: revela la UI, carga datos y abre el canal. */
    function enterApp() {
        reveal();
        startRealtime();
        return bootData();
    }

    function relock() {
        $("tk-app").classList.remove("is-ready");
        TareasRealtime.stop(); // el canal no debe seguir vivo sin sesión
        if (realtimeFlush) { // descarta cualquier resincronía encolada
            (typeof cancelAnimationFrame === "function" ? cancelAnimationFrame : clearTimeout)(realtimeFlush);
            realtimeFlush = 0;
            pendingScopes.clear();
        }
        entityMutations.clear(); // versiones y colas atadas a la sesión
        TareasHistory.clear(); // el historial no sobrevive a un cambio de sesión
        TareasLock.show({ pinLength: 5, onSuccess: enterApp });
    }

    /** Conecta los botones de deshacer/rehacer del header con el historial. */
    function wireHistoryControls() {
        const undoBtn = $("tk-undo-btn");
        const redoBtn = $("tk-redo-btn");
        if (!undoBtn || !redoBtn) return;
        undoBtn.addEventListener("click", safeUndo);
        redoBtn.addEventListener("click", safeRedo);
        const sync = (btn, can, label, base) => {
            btn.disabled = !can;
            const text = can ? `${base}: ${label}` : base;
            btn.title = text;
            btn.setAttribute("aria-label", text);
        };
        TareasHistory.subscribe((s) => {
            historyState = s;
            sync(undoBtn, s.canUndo, s.undoLabel, "Deshacer");
            sync(redoBtn, s.canRedo, s.redoLabel, "Rehacer");
        });
    }

    async function init() {
        hydrateIcons();
        TareasUI.initTheme($("tk-theme-toggle"));
        const savedSort = storage.get(SORT_STORAGE_KEY);
        if (savedSort && savedSort in SORTS) state.sort = savedSort;
        const savedFilter = storage.get(FILTER_STORAGE_KEY);
        if (savedFilter && FILTER_VALUES.includes(savedFilter)) state.filter = savedFilter;
        const savedTab = storage.get(TAB_STORAGE_KEY);
        if (savedTab && TABS.includes(savedTab)) selectTab(savedTab);
        wireTabs();
        wireShortcuts();
        wirePalette();
        wireSelectAll();
        wireHistoryShortcuts();
        wireHistoryControls();
        buildComposer();
        buildIdeasTools();
        buildToolbar();

        $("tk-help-btn").addEventListener("click", openShortcutsHelp);
        $("tk-colaboradores-btn").addEventListener("click", () =>
            TareasColaboradores.openManager(() => state.colaboradores, async () => { await loadColaboradores(); renderMisiones(); }));
        $("tk-tags-btn").addEventListener("click", () =>
            TareasTags.openManager(() => state.tags, async () => { await refreshCollections(["tags", "ideas", "misiones"]); }));
        $("tk-templates-btn").addEventListener("click", openTemplatesManager);
        $("tk-data-btn").addEventListener("click", openDataIO);

        $("tk-logout-btn").addEventListener("click", guard(async () => {
            await TareasAPI.auth.logout();
            relock();
        }));

        try {
            const status = await TareasAPI.auth.status();
            if (status.authenticated) {
                TareasAPI.setCsrf(status.csrf_token);
                await enterApp();
            } else {
                TareasLock.show({
                    pinLength: status.pin_length || 5,
                    lockedFor: status.locked_for || 0,
                    onSuccess: enterApp,
                });
            }
        } catch (err) {
            toast(err.message || "No se pudo conectar.", "error");
        }
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
    else init();
})();

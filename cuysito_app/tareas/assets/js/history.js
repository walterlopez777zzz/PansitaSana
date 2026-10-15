"use strict";

/**
 * ============================================================
 * Tareas — Pila de Deshacer / Rehacer (patrón Command)
 * ============================================================
 * Implementa un historial genérico de comandos reversibles.
 * Cada comando es `{ label, redo(), undo() }` donde `redo` aplica
 * la acción (también en la PRIMERA ejecución) y `undo` su inversa.
 *
 * Garantías:
 *  - Serialización: nunca corren dos transiciones a la vez (`busy`),
 *    evitando estados intermedios inconsistentes si el usuario
 *    machaca Ctrl+Z/Ctrl+Y.
 *  - Seguridad ante fallos: si una inversa falla (p. ej. el dato fue
 *    tocado en otro dispositivo o la sesión expiró), el historial se
 *    purga para no aplicar inversas sobre datos desincronizados; el
 *    llamador resincroniza recargando desde el servidor.
 *  - Observable: `subscribe` notifica los cambios de estado para
 *    reflejar disponibilidad/etiquetas en la UI (botones, atajos).
 *
 * Expone el objeto global `TareasHistory`.
 * ============================================================
 */

const TareasHistory = (() => {
    // Tope de profundidad: acota memoria sin sacrificar una historia útil.
    const LIMIT = 100;

    /** @typedef {{ label: string, redo: () => Promise<void>|void, undo: () => Promise<void>|void }} Command */

    /** @type {Command[]} */ const undoStack = [];
    /** @type {Command[]} */ const redoStack = [];
    /** @type {Set<(state: HistoryState) => void>} */ const listeners = new Set();

    let busy = false;

    /** @typedef {{ canUndo: boolean, canRedo: boolean, undoLabel: string|null, redoLabel: string|null }} HistoryState */
    const snapshot = () => ({
        canUndo: undoStack.length > 0,
        canRedo: redoStack.length > 0,
        undoLabel: undoStack.length ? undoStack[undoStack.length - 1].label : null,
        redoLabel: redoStack.length ? redoStack[redoStack.length - 1].label : null,
    });

    const notify = () => {
        const state = snapshot();
        listeners.forEach((fn) => fn(state));
    };

    /**
     * Registra un comando YA EJECUTADO para volverlo reversible. Al apilar un
     * comando nuevo se invalida la rama de rehacer (semántica estándar de undo).
     * @param {Command} command
     */
    function push(command) {
        undoStack.push(command);
        if (undoStack.length > LIMIT) undoStack.shift();
        redoStack.length = 0;
        notify();
    }

    /**
     * Aplica una transición (undo o redo) de forma atómica. Ante un error deja
     * el historial vacío y lo propaga para que el llamador resincronice.
     * @param {Command[]} from  pila de origen
     * @param {Command[]} to    pila de destino
     * @param {"undo"|"redo"} method
     * @returns {Promise<Command|null>} el comando aplicado, o null si no había
     */
    async function transition(from, to, method) {
        if (busy || from.length === 0) return null;
        busy = true;
        const command = from.pop();
        try {
            await command[method]();
            to.push(command);
            notify();
            return command;
        } catch (err) {
            undoStack.length = 0;
            redoStack.length = 0;
            notify();
            throw err;
        } finally {
            busy = false;
        }
    }

    const undo = () => transition(undoStack, redoStack, "undo");
    const redo = () => transition(redoStack, undoStack, "redo");

    /** Vacía el historial (p. ej. al re-bloquear o recargar todo el estado). */
    function clear() {
        undoStack.length = 0;
        redoStack.length = 0;
        notify();
    }

    /**
     * Suscribe un observador del estado del historial. Lo invoca de inmediato
     * con el estado actual y devuelve una función para desuscribir.
     * @param {(state: HistoryState) => void} fn
     */
    function subscribe(fn) {
        listeners.add(fn);
        fn(snapshot());
        return () => listeners.delete(fn);
    }

    return Object.freeze({ push, undo, redo, clear, subscribe, isBusy: () => busy });
})();

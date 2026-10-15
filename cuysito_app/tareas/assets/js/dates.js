"use strict";

/**
 * ============================================================
 * Tareas — Utilidades de fechas de vencimiento y recurrencia
 * ============================================================
 * Helpers puros y deterministas para trabajar con fechas
 * 'YYYY-MM-DD' (columna DATE del backend) en hora LOCAL, sin
 * desfases de zona horaria. Reutilizado por la vista de
 * misiones, el orden por vencimiento y los recordatorios.
 * Expone el objeto global `TareasDates`.
 * ============================================================
 */

const TareasDates = (() => {

    /** Recurrencias soportadas (espejo del enum del backend). */
    const RECURRENCE_LABEL = Object.freeze({
        none: "No se repite",
        daily: "Cada día",
        weekly: "Cada semana",
        monthly: "Cada mes",
    });

    /** Opciones para el <select> de recurrencia en los modales. */
    const RECURRENCE_OPTIONS = Object.freeze(
        Object.entries(RECURRENCE_LABEL).map(([value, label]) => ({ value, label }))
    );

    /** Etiqueta corta de la cadencia (para el chip de la tarjeta). */
    const RECURRENCE_SHORT = Object.freeze({
        daily: "Diaria", weekly: "Semanal", monthly: "Mensual",
    });

    const MS_PER_DAY = 86_400_000;

    /** Medianoche local de hoy (base estable para comparar días). */
    function startOfToday() {
        const now = new Date();
        return new Date(now.getFullYear(), now.getMonth(), now.getDate());
    }

    /** Fecha local de hoy como 'YYYY-MM-DD' (para prerellenar inputs date). */
    function todayISO() {
        return toISO(startOfToday());
    }

    /** Serializa un Date a 'YYYY-MM-DD' en hora local (no UTC). */
    function toISO(date) {
        if (!(date instanceof Date) || isNaN(date.getTime())) return "";
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, "0");
        const d = String(date.getDate()).padStart(2, "0");
        return `${y}-${m}-${d}`;
    }

    /**
     * Parsea 'YYYY-MM-DD' a un Date a medianoche LOCAL. Evita el `new Date(str)`
     * nativo, que interpreta la cadena como UTC y corre el día según la zona.
     * Devuelve null si la cadena no es una fecha válida.
     */
    function parseISO(iso) {
        if (typeof iso !== "string") return null;
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso.trim());
        if (!m) return null;
        const [, y, mo, d] = m;
        const date = new Date(Number(y), Number(mo) - 1, Number(d));
        // Rechaza desbordes (ej. 2024-02-31 → marzo).
        return date.getMonth() === Number(mo) - 1 ? date : null;
    }

    /** Días enteros desde hoy hasta la fecha (negativo = pasado, 0 = hoy). */
    function daysUntil(iso) {
        const date = parseISO(iso);
        if (!date) return null;
        return Math.round((date.getTime() - startOfToday().getTime()) / MS_PER_DAY);
    }

    /**
     * Clasifica una fecha de vencimiento en una franja temporal. Las misiones
     * ya completadas/archivadas no se consideran "vencidas".
     * @returns {"overdue"|"today"|"soon"|"upcoming"|"none"}
     */
    function classify(iso, { active = true } = {}) {
        const days = daysUntil(iso);
        if (days === null) return "none";
        if (active && days < 0) return "overdue";
        if (days === 0) return "today";
        if (days <= 2) return "soon";
        return "upcoming";
    }

    /**
     * Etiqueta humana y breve de la fecha relativa a hoy: "Hoy", "Mañana",
     * "Ayer", "En N días", "Hace N días" para el corto plazo y la fecha
     * absoluta ("23 jun", "5 mar 2026") para el resto.
     */
    function formatRelative(iso) {
        const days = daysUntil(iso);
        if (days === null) return "";
        if (days === 0) return "Hoy";
        if (days === 1) return "Mañana";
        if (days === -1) return "Ayer";
        if (days > 1 && days <= 6) return `En ${days} días`;
        if (days < -1 && days >= -6) return `Hace ${-days} días`;
        return formatAbsolute(iso);
    }

    /** Fecha absoluta legible; incluye el año sólo si no es el actual. */
    function formatAbsolute(iso) {
        const date = parseISO(iso);
        if (!date) return "";
        const opts = { day: "numeric", month: "short" };
        if (date.getFullYear() !== new Date().getFullYear()) opts.year = "numeric";
        return date.toLocaleDateString("es", opts);
    }

    return Object.freeze({
        RECURRENCE_LABEL, RECURRENCE_OPTIONS, RECURRENCE_SHORT,
        startOfToday, todayISO, toISO, parseISO, daysUntil, classify,
        formatRelative, formatAbsolute,
    });
})();

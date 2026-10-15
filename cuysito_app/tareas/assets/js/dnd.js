"use strict";

/**
 * ============================================================
 * Tareas — Ordenamiento por arrastre (accesible)
 * ============================================================
 * Reordenamiento de listas mediante puntero (mouse/touch/pen) y
 * teclado, sin dependencias externas. Implementa el patrón WAI-ARIA
 * de "tomar y mover": el usuario de teclado toma un ítem con Espacio
 * o Enter, lo mueve con las flechas y lo suelta con Espacio/Enter, o
 * cancela con Escape. Cada cambio se anuncia por la región viva.
 *
 * Expone el objeto global `TareasSortable`.
 * ============================================================
 */

const TareasSortable = (() => {
    const { announce } = TareasUI;

    // Distancia (px) que el puntero debe recorrer antes de considerar
    // que es un arrastre y no un clic accidental.
    const DRAG_THRESHOLD = 5;

    /**
     * Vuelve ordenable un contenedor.
     *
     * @param {HTMLElement} container Contenedor de los ítems.
     * @param {object} opts
     * @param {string}  opts.itemSelector   Selector de cada ítem ordenable.
     * @param {string}  opts.handleSelector Selector del asa dentro del ítem.
     * @param {(ids:string[]) => void} opts.onCommit  Recibe el nuevo orden de ids.
     * @param {(el:HTMLElement)=>string} [opts.getId]    Extrae el id de un ítem.
     * @param {(el:HTMLElement)=>string} [opts.describe] Texto accesible del ítem.
     * @param {("list"|"grid")} [opts.axis="list"]      Geometría de la lista.
     * @returns {{ destroy: () => void }}
     */
    function attach(container, opts) {
        const {
            itemSelector,
            handleSelector,
            onCommit,
            getId = (el) => el.dataset.id,
            describe = (el) => el.dataset.label || "elemento",
            axis = "list",
        } = opts;

        const items = () => Array.from(container.querySelectorAll(itemSelector));
        const orderIds = () => items().map(getId);

        // ── Estado de arrastre por puntero ───────────────────
        let pointerId = null;
        let dragEl = null;
        let startX = 0, startY = 0;
        let dragging = false;
        let startOrder = null;

        // ── Estado de "tomado" por teclado ───────────────────
        let grabbedEl = null;
        let grabbedOrder = null;

        function commitIfChanged() {
            const next = orderIds();
            const prev = startOrder || grabbedOrder;
            if (prev && next.join("|") !== prev.join("|")) onCommit(next);
        }

        // ── Inserción basada en geometría (puntero) ──────────
        function elementAfterPoint(x, y) {
            const others = items().filter((el) => el !== dragEl);
            if (axis === "grid") {
                // Rejilla: el ítem cuyo centro esté más cerca decide la
                // posición; insertamos antes si el puntero quedó por
                // encima/izquierda de su centro, si no, después.
                let best = null, bestDist = Infinity, bestBefore = true;
                for (const el of others) {
                    const b = el.getBoundingClientRect();
                    const cx = b.left + b.width / 2, cy = b.top + b.height / 2;
                    const dist = (x - cx) ** 2 + (y - cy) ** 2;
                    if (dist < bestDist) {
                        bestDist = dist;
                        bestBefore = y < cy - 1 || (Math.abs(y - cy) <= b.height / 2 && x < cx);
                        best = el;
                    }
                }
                if (!best) return null;
                return bestBefore ? best : best.nextElementSibling;
            }
            // Lista vertical: primer ítem cuyo medio quede por debajo del puntero.
            for (const el of others) {
                const b = el.getBoundingClientRect();
                if (y < b.top + b.height / 2) return el;
            }
            return null;
        }

        function onPointerDown(e) {
            if (e.button !== undefined && e.button !== 0) return; // sólo principal
            const handle = e.target.closest(handleSelector);
            if (!handle || !container.contains(handle)) return;
            const item = handle.closest(itemSelector);
            if (!item) return;

            pointerId = e.pointerId;
            dragEl = item;
            startX = e.clientX;
            startY = e.clientY;
            dragging = false;
            startOrder = orderIds();

            // Los listeners viven en `window`, no en el asa: al reordenar el DOM
            // el asa se mueve y, con `setPointerCapture`, Chrome deja de
            // entregarle eventos. Escuchando en `window` el arrastre nunca se
            // "pierde" aunque el nodo cambie de posición.
            window.addEventListener("pointermove", onPointerMove);
            window.addEventListener("pointerup", onPointerUp);
            window.addEventListener("pointercancel", onPointerUp);
        }

        function beginDrag() {
            dragging = true;
            container.classList.add("is-sorting");
            dragEl.classList.add("is-dragging");
            dragEl.setAttribute("aria-grabbed", "true");
        }

        function onPointerMove(e) {
            if (e.pointerId !== pointerId || !dragEl) return;
            if (!dragging) {
                if (Math.hypot(e.clientX - startX, e.clientY - startY) < DRAG_THRESHOLD) return;
                beginDrag();
            }
            e.preventDefault();
            const ref = elementAfterPoint(e.clientX, e.clientY);
            if (ref === dragEl) return;
            if (ref == null) {
                // "Al final": después del último ítem, nunca después de nodos
                // ajenos que el contenedor pueda tener al final (p. ej. el
                // campo "agregar" de una checklist).
                const others = items().filter((el) => el !== dragEl);
                const last = others[others.length - 1];
                if (last && last.nextElementSibling !== dragEl) container.insertBefore(dragEl, last.nextElementSibling);
                else if (!last) container.appendChild(dragEl);
            } else if (ref !== dragEl.nextElementSibling) {
                container.insertBefore(dragEl, ref);
            }
        }

        function onPointerUp(e) {
            if (e.pointerId !== pointerId) return;
            window.removeEventListener("pointermove", onPointerMove);
            window.removeEventListener("pointerup", onPointerUp);
            window.removeEventListener("pointercancel", onPointerUp);

            if (dragging) {
                container.classList.remove("is-sorting");
                dragEl.classList.remove("is-dragging");
                dragEl.removeAttribute("aria-grabbed");
                commitIfChanged();
            }
            pointerId = null;
            dragEl = null;
            dragging = false;
            startOrder = null;
        }

        // ── Teclado (tomar / mover / soltar) ─────────────────
        function position(el) {
            const list = items();
            return { index: list.indexOf(el), total: list.length };
        }

        function grab(item) {
            grabbedEl = item;
            grabbedOrder = orderIds();
            item.classList.add("is-grabbed");
            item.setAttribute("aria-grabbed", "true");
            const { index, total } = position(item);
            announce(`Tomaste «${describe(item)}». Posición ${index + 1} de ${total}. Flechas para mover, Espacio para soltar, Escape para cancelar.`);
        }

        function release(commit) {
            if (!grabbedEl) return;
            const item = grabbedEl;
            item.classList.remove("is-grabbed");
            item.removeAttribute("aria-grabbed");
            if (commit) {
                const { index, total } = position(item);
                announce(`Soltaste «${describe(item)}» en la posición ${index + 1} de ${total}.`);
                commitIfChanged();
            }
            grabbedEl = null;
            grabbedOrder = null;
        }

        function cancel() {
            if (!grabbedEl) return;
            // Restaura el orden original re-anexando según el snapshot.
            const byId = new Map(items().map((el) => [getId(el), el]));
            grabbedOrder.forEach((id) => { const el = byId.get(id); if (el) container.appendChild(el); });
            grabbedEl.classList.remove("is-grabbed");
            grabbedEl.removeAttribute("aria-grabbed");
            grabbedEl = null;
            grabbedOrder = null;
            announce("Movimiento cancelado.");
        }

        function move(dir) {
            const list = items();
            const i = list.indexOf(grabbedEl);
            const j = i + dir;
            if (j < 0 || j >= list.length) return;
            if (dir < 0) container.insertBefore(grabbedEl, list[j]);
            else container.insertBefore(grabbedEl, list[j].nextElementSibling);
            // Mover el nodo en el DOM hace que el navegador pierda el foco del
            // asa contenida; lo restauramos para que sigan funcionando flechas,
            // Espacio y Escape, y para no disparar una suelta espuria.
            grabbedEl.querySelector(handleSelector)?.focus();
            const { index, total } = position(grabbedEl);
            announce(`Posición ${index + 1} de ${total}.`);
        }

        function onKeyDown(e) {
            const handle = e.target.closest(handleSelector);
            if (!handle || !container.contains(handle)) return;
            const item = handle.closest(itemSelector);
            if (!item) return;

            const back = e.key === "ArrowUp" || (axis === "grid" && e.key === "ArrowLeft");
            const fwd = e.key === "ArrowDown" || (axis === "grid" && e.key === "ArrowRight");

            // El foco vive en el asa (un <button>) y la acompaña al mover el
            // nodo en el DOM, así que no hace falta re-enfocar manualmente.
            if (e.key === " " || e.key === "Spacebar" || e.key === "Enter") {
                e.preventDefault();
                if (grabbedEl === item) release(true);
                else if (!grabbedEl) grab(item);
            } else if (e.key === "Escape" && grabbedEl === item) {
                e.preventDefault();
                cancel();
            } else if (grabbedEl === item && (back || fwd)) {
                e.preventDefault();
                move(back ? -1 : 1);
            }
        }

        // Soltar (confirmando) si el asa pierde el foco mientras se sostiene.
        // El chequeo se difiere un frame: al reordenar, el foco "parpadea"
        // (sale del asa y vuelve), y sólo queremos soltar si terminó realmente
        // fuera del contenedor.
        function onFocusOut() {
            if (!grabbedEl) return;
            requestAnimationFrame(() => {
                if (grabbedEl && !container.contains(document.activeElement)) release(true);
            });
        }

        container.addEventListener("pointerdown", onPointerDown);
        container.addEventListener("keydown", onKeyDown);
        container.addEventListener("focusout", onFocusOut);

        return {
            destroy() {
                container.removeEventListener("pointerdown", onPointerDown);
                container.removeEventListener("keydown", onKeyDown);
                container.removeEventListener("focusout", onFocusOut);
            },
        };
    }

    return Object.freeze({ attach });
})();

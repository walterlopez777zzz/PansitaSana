"use strict";

/**
 * ============================================================
 * Tareas — Reconciliador de listas con claves
 * ============================================================
 * Actualiza incrementalmente los hijos de un contenedor a partir
 * de una lista de "unidades" de render, en lugar de reconstruir
 * todo el DOM en cada cambio. Cada unidad declara:
 *
 *   - `key`    Identidad estable (p. ej. `m:42`). Decide qué nodo
 *              se reutiliza entre renders.
 *   - `sig`    Firma del contenido. Si no cambió respecto al render
 *              anterior, el nodo existente se reutiliza tal cual (no
 *              se reconstruye): se preservan foco, scroll, selección
 *              de texto y los listeners ya cableados.
 *   - `create` Fábrica perezosa del nodo. Sólo se invoca cuando hay
 *              que construir (alta o cambio de firma).
 *
 * El algoritmo es O(n): reutiliza por clave, reconstruye sólo lo que
 * cambió, reordena con el mínimo de `insertBefore` y elimina lo que
 * sobra. La caché se guarda en el propio contenedor, de modo que el
 * reconciliador es sin estado y reentrante.
 *
 * Garantía clave: los nodos cuya firma no cambió **no se tocan** (no
 * se mueven ni se re-insertan). Para lograrlo, los nodos reemplazados
 * o eliminados se quitan ANTES de reordenar; así un cambio en una
 * tarjeta no arrastra (vía `insertBefore`) a sus vecinas intactas —
 * lo que provocaría repintado y el reinicio de sus animaciones CSS.
 *
 * Animación de entrada: con la opción `enterClass`, el reconciliador
 * añade esa clase SÓLO a las altas reales (claves nuevas: carga
 * inicial o tarjeta recién creada), no a las actualizaciones in situ
 * de una tarjeta ya existente. La clase es transitoria: se retira al
 * terminar la animación, de modo que un futuro reordenamiento del
 * nodo no la vuelva a disparar.
 *
 * Animación de salida: con la opción `leaveClass`, una baja no se quita
 * del DOM al instante; se le añade esa clase para que reproduzca su
 * animación de salida (inversa de la entrada) y se elimina al concluir.
 * Mientras "se va", el nodo se marca como saliente y queda EXCLUIDO de
 * la contabilidad del reconciliador (orden y recorte operan sólo sobre
 * los nodos vivos), de modo que las pasadas siguientes no lo arrastren
 * ni lo borren de golpe. Si la clave reaparece (p. ej. al deshacer) se
 * cancela la salida pendiente. Funciona igual para bajas locales y para
 * las que llegan de otro colaborador por el canal de tiempo real.
 *
 * SÓLO se anima la salida de las bajas REALES (la clave deja de existir).
 * Actualizar una tarjeta ya visible NO es una baja: su nodo se reemplaza
 * en la MISMA posición y de forma INMEDIATA (en espejo con la entrada, que
 * tampoco se anima en una actualización in situ). Animar la salida del nodo
 * viejo lo dejaría solapado un instante con su reemplazo —el "fantasma"
 * duplicado—; por eso el reemplazo se hace sin animación de salida.
 *
 * Se respeta `prefers-reduced-motion`: con movimiento reducido la baja
 * es inmediata (sin animación de salida).
 *
 * Expone el objeto global `TareasReconcile`.
 * ============================================================
 */

const TareasReconcile = (() => {
    // Clave de la caché en el contenedor (Map<key, { sig, node }>).
    const CACHE = "__tkReconcile";
    // Mapa de nodos saliendo en el contenedor (Map<key, node>).
    const LEAVING = "__tkLeaving";
    // Red de seguridad si 'animationend' no llegara a dispararse (ms).
    const LEAVE_FALLBACK_MS = 600;
    // Deslizamiento (FLIP) al reacomodarse: duración, curva y umbral mínimo.
    const MOVE_MS = 220;
    const MOVE_EASING = "cubic-bezier(.22, .68, 0, 1.2)";
    const MOVE_EPSILON = 0.5; // px: por debajo se considera que el nodo no se movió

    function prefersReducedMotion() {
        return typeof window.matchMedia === "function" &&
            window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    }

    /** Mapa de salientes asociado al contenedor (perezoso). */
    function leavingMap(container) {
        if (!(container[LEAVING] instanceof Map)) container[LEAVING] = new Map();
        return container[LEAVING];
    }

    /** Marca un nodo como "recién entrado" con una clase transitoria que se
     *  retira al concluir su animación (ignorando las de sus descendientes). */
    function markEntering(node, enterClass) {
        if (!enterClass || typeof node.classList === "undefined") return;
        node.classList.add(enterClass);
        const onEnd = (e) => {
            if (e.target !== node) return; // sólo la animación propia, no las hijas
            node.classList.remove(enterClass);
            node.removeEventListener("animationend", onEnd);
        };
        node.addEventListener("animationend", onEnd);
    }

    /** Saca un nodo del flujo fijándolo (absolute) en su posición actual dentro
     *  del contenedor, para que, al desvanecerse, no siga ocupando un hueco del
     *  layout: así los vecinos se reacomodan de inmediato sin que el "fantasma"
     *  los empuje. El contenedor debe estar posicionado (position != static). */
    function pinOutOfFlow(node, rect, containerRect, container) {
        if (typeof node.style === "undefined") return;
        const s = node.style;
        s.boxSizing = "border-box";
        s.position = "absolute";
        s.margin = "0";
        s.top = (rect.top - containerRect.top - container.clientTop) + "px";
        s.left = (rect.left - containerRect.left - container.clientLeft) + "px";
        s.width = rect.width + "px";
        s.height = rect.height + "px";
        node.__tkPinned = true;
    }

    /** Revierte `pinOutOfFlow`: devuelve el nodo al flujo normal. */
    function unpin(node) {
        if (!node || !node.__tkPinned || typeof node.style === "undefined") return;
        const s = node.style;
        s.position = s.margin = s.top = s.left = s.width = s.height = s.boxSizing = "";
        node.__tkPinned = false;
    }

    /** Anima un nodo desde su geometría previa hasta la actual con la técnica
     *  FLIP: invierte el cambio (posición y/o altura) sin transición y, tras el
     *  reflujo, lo deja volver a su valor final animando.
     *
     *  - Posición: `translate(dx, dy)` → 0 (transformada, sin reflujo: barato).
     *  - Altura:   se fija la altura previa y se anima hasta la nueva; al
     *    concluir se limpia para devolver el nodo al dimensionado natural
     *    (p. ej. el "estirado" de la rejilla). Es lo que permite que las
     *    vecinas de una fila crezcan/encojan suavemente al igualar alturas.
     *
     *  Reemplaza cualquier animación en curso para no encadenar saltos y limpia
     *  los estilos en línea al terminar (con red de seguridad por si algún
     *  `transitionend` no llega). `dx`/`dy` en 0 omiten la transformada; `fromH`
     *  nulo omite la altura.
     */
    function playMove(node, dx, dy, fromH, toH) {
        if (typeof node.style === "undefined") return;
        const s = node.style;
        // Cancela cualquier limpieza/animación previa en vuelo sobre este nodo.
        if (node.__tkMoveEnd) {
            node.removeEventListener("transitionend", node.__tkMoveEnd);
            if (node.__tkMoveTimer) window.clearTimeout(node.__tkMoveTimer);
            node.__tkMoveEnd = node.__tkMoveTimer = null;
        }
        const movePos = !!(dx || dy);
        const moveH = fromH != null && toH != null && Math.abs(fromH - toH) >= MOVE_EPSILON;
        if (!movePos && !moveH) return;

        const props = [];
        if (movePos) props.push("transform");
        if (moveH) props.push("height");

        // INVERT: posiciona el nodo en su geometría previa, sin transición.
        s.transition = "none";
        if (movePos) s.transform = `translate(${dx}px, ${dy}px)`;
        if (moveH) s.height = fromH + "px";
        void node.offsetWidth; // fuerza reflow: fija el punto de partida invertido

        const cleanup = () => {
            node.removeEventListener("transitionend", onEnd);
            if (node.__tkMoveTimer) window.clearTimeout(node.__tkMoveTimer);
            node.__tkMoveEnd = node.__tkMoveTimer = null;
            s.transition = "";
            if (movePos) s.transform = "";
            if (moveH) s.height = ""; // vuelve al alto natural (estirado por la rejilla)
        };
        let pending = props.length;
        const onEnd = (e) => {
            if (e.target !== node || props.indexOf(e.propertyName) === -1) return;
            if (--pending <= 0) cleanup();
        };

        // PLAY: anima cada propiedad de vuelta a su valor final.
        s.transition = props.map((p) => `${p} ${MOVE_MS}ms ${MOVE_EASING}`).join(", ");
        if (movePos) s.transform = "";
        if (moveH) s.height = toH + "px";
        node.addEventListener("transitionend", onEnd);
        // Red de seguridad: si algún `transitionend` no llega, limpia igual.
        node.__tkMoveTimer = window.setTimeout(cleanup, MOVE_MS + 80);
        node.__tkMoveEnd = onEnd;
    }

    /**
     * Da de baja un nodo. Con `leaveClass` y movimiento permitido, reproduce
     * la animación de salida y lo elimina al terminar; en caso contrario lo
     * quita de inmediato. Expone `node.__tkLeave` con `finish` (consuma la baja)
     * y `cancel` (rescata el nodo intacto si la clave reaparece).
     */
    function leave(container, key, node, leaveClass) {
        if (!leaveClass || typeof node.classList === "undefined" || prefersReducedMotion()) {
            unpin(node);
            if (node.parentNode === container) container.removeChild(node);
            return;
        }

        const map = leavingMap(container);
        map.set(key, node);
        node.setAttribute("aria-hidden", "true"); // fuera del árbol accesible al irse
        node.classList.add(leaveClass);

        let timer;
        const stop = () => {
            node.removeEventListener("animationend", onEnd);
            window.clearTimeout(timer);
            node.__tkLeave = null;
            if (map.get(key) === node) map.delete(key);
        };
        // Fin natural: la baja se consuma (el nodo se quita del DOM).
        const finish = () => { stop(); if (node.parentNode === container) container.removeChild(node); };
        // Cancelación (la clave reaparece, p. ej. al deshacer): se rescata el nodo
        // intacto deshaciendo las marcas de salida para poder reutilizarlo.
        const cancel = () => { stop(); node.classList.remove(leaveClass); node.removeAttribute("aria-hidden"); unpin(node); };
        const onEnd = (e) => { if (e.target === node) finish(); };

        node.addEventListener("animationend", onEnd);
        timer = window.setTimeout(finish, LEAVE_FALLBACK_MS);
        node.__tkLeave = { cancel, finish };
    }

    /** Hijos "vivos" del contenedor (excluye los que están saliendo). */
    function liveChildren(container) {
        const leaving = container[LEAVING] instanceof Map ? container[LEAVING] : null;
        if (!leaving || leaving.size === 0) return Array.from(container.childNodes);
        const set = new Set(leaving.values());
        const live = [];
        for (const child of container.childNodes) {
            if (!set.has(child)) live.push(child);
        }
        return live;
    }

    /**
     * Reconcilia los hijos de `container` con `units` (en orden).
     * @param {HTMLElement} container
     * @param {{key:string, sig:string, create:() => Node}[]} units
     * @param {{ enterClass?: string, leaveClass?: string }} [options]
     *        Clases de animación de entrada y de salida.
     * @returns {HTMLElement} el mismo contenedor (encadenable).
     */
    function children(container, units, options) {
        const enterClass = options && options.enterClass;
        const leaveClass = options && options.leaveClass;
        const prev = container[CACHE] instanceof Map ? container[CACHE] : new Map();
        const leaving = leavingMap(container);
        const next = new Map();
        const ordered = [];
        const orderedKeys = [];

        for (const unit of units) {
            // Claves duplicadas romperían la identidad 1:1; la primera gana y
            // las repetidas se ignoran de forma determinista.
            if (next.has(unit.key)) continue;
            // Si la clave vuelve mientras su nodo anterior aún se va (p. ej.
            // deshacer un borrado), cancela esa salida y rescata el nodo intacto.
            const goingAway = leaving.get(unit.key);
            if (goingAway && goingAway.__tkLeave) goingAway.__tkLeave.cancel();
            const reused = prev.get(unit.key);
            let node;
            if (reused && reused.sig === unit.sig) {
                node = reused.node; // intacto: se reutiliza sin tocarlo
            } else {
                node = unit.create(); // alta o cambio de contenido
                // Anima sólo las altas reales (clave inexistente antes), nunca
                // las actualizaciones in situ de una tarjeta ya presente.
                if (!prev.has(unit.key)) markEntering(node, enterClass);
            }
            next.set(unit.key, { sig: unit.sig, node });
            ordered.push(node);
            orderedKeys.push(unit.key);
        }

        // ¿Pudo cambiar la disposición? (altas, bajas, reordenamiento o salidas
        // aún en curso). Si no, se evita medir el layout: un render que sólo
        // actualiza contenido in situ no provoca movimiento alguno y el trabajo
        // de FLIP sería puro coste sin efecto.
        const animate = !!leaveClass && !prefersReducedMotion();
        const prevKeys = [...prev.keys()];
        let mightMove = leaving.size > 0 || prevKeys.length !== orderedKeys.length;
        for (let k = 0; !mightMove && k < orderedKeys.length; k++) {
            if (orderedKeys[k] !== prevKeys[k]) mightMove = true;
        }

        // FIRST (FLIP): posiciones previas de los nodos presentes, ANTES de mutar.
        const firstRects = new Map();
        if (animate && mightMove) {
            const containerRect = container.getBoundingClientRect();
            for (const [key, entry] of prev) {
                if (entry.node.parentNode === container) firstRects.set(key, entry.node.getBoundingClientRect());
            }
            // Saca YA del flujo los nodos que se van (baja real), usando su
            // posición previa, para que su desvanecimiento no empuje a los vivos:
            // así una baja "se desvanece en su sitio" sin desplazar al vecino.
            for (const [key, entry] of prev) {
                if (entry.node.parentNode !== container || next.get(key)) continue;
                const r = firstRects.get(key);
                if (r) pinOutOfFlow(entry.node, r, containerRect, container);
            }
        }

        // Baja: retira los nodos del render anterior que ya no siguen vigentes.
        // Hacerlo ANTES de reordenar evita mover los nodos reutilizados intactos.
        //
        // Se distinguen dos casos para no provocar duplicados visuales:
        //   • Eliminación real (la clave desapareció): se reproduce la animación
        //     de salida; el nodo (ya fuera de flujo) se desvanece en su sitio.
        //   • Reemplazo in situ (misma clave, contenido nuevo): el nodo viejo se
        //     sustituye por el nuevo en la MISMA posición, así que animar su
        //     salida lo dejaría solapado con su reemplazo. Se retira de inmediato,
        //     en espejo con la entrada (que tampoco se anima al actualizar).
        for (const [key, entry] of prev) {
            if (entry.node.parentNode !== container) continue;
            const kept = next.get(key);
            if (!kept) {
                leave(container, key, entry.node, leaveClass); // eliminación real
            } else if (kept.node !== entry.node) {
                container.removeChild(entry.node); // reemplazo: swap inmediato
            }
        }

        // Orden: coloca cada nodo en su posición con el mínimo de movimientos,
        // ignorando los nodos salientes (que sólo ocupan su lugar mientras se
        // desvanecen). Los nodos vivos ya en su sitio no se mueven.
        let live = liveChildren(container);
        let i = 0;
        for (const node of ordered) {
            if (live[i] !== node) {
                container.insertBefore(node, live[i] || null);
                live = liveChildren(container); // refleja el movimiento recién hecho
            }
            i++;
        }

        // Recorte defensivo de cualquier nodo vivo sobrante al final.
        live = liveChildren(container);
        while (live.length > ordered.length) {
            container.removeChild(live[live.length - 1]);
            live.pop();
        }

        // LAST + INVERT + PLAY (FLIP): anima suavemente a su geometría final los
        // nodos que cambiaron al reacomodarse: de POSICIÓN (reordenar, fijar,
        // deshacer, o el hueco que deja una baja) y/o de ALTURA (las vecinas de
        // una fila que se estiran/encogen al igualar la altura de la rejilla
        // cuando entra/sale una tarjeta más alta). Los recién entrados ya animan
        // su alta aparte, así que se excluyen (no estaban en `firstRects`).
        if (firstRects.size) {
            for (const [key, entry] of next) {
                const first = firstRects.get(key);
                if (!first) continue;
                const last = entry.node.getBoundingClientRect();
                const dx = first.left - last.left;
                const dy = first.top - last.top;
                const moved = Math.abs(dx) >= MOVE_EPSILON || Math.abs(dy) >= MOVE_EPSILON;
                const resized = Math.abs(first.height - last.height) >= MOVE_EPSILON;
                if (moved || resized) {
                    playMove(entry.node, moved ? dx : 0, moved ? dy : 0,
                        resized ? first.height : null, resized ? last.height : null);
                }
            }
        }

        container[CACHE] = next;
        return container;
    }

    /** Olvida la caché de un contenedor (fuerza reconstrucción completa). */
    function reset(container) {
        if (container) delete container[CACHE];
    }

    return Object.freeze({ children, reset });
})();

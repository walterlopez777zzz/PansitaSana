/**
 * Contadores del carrito (cabecera y barra móvil).
 *
 * Cada disparador `[data-cart-action="open"]` contiene un `[data-cart-badge]`.
 * Sólo se escribe en el DOM cuando cambia el número de unidades; la animación
 * de rebote se activa vía `data-bump` y la limpia el propio `animationend`.
 * Los disparadores conmutan el cajón, así que también reflejan su estado en
 * `aria-expanded`.
 */

import { selectTotals } from './store.js';
import { formatBadge, formatCount } from './format.js';

const TRIGGER_SELECTOR = '[data-cart-action="open"]';
const BADGE_SELECTOR = '[data-cart-badge]';

/**
 * @param {Object} deps
 * @param {import('./store.js').CartStore} deps.store
 * @param {ParentNode} [deps.root]
 * @returns {{dispose: () => void}}
 */
export function mountCartBadges({ store, root = document }) {
  const triggers = Array.from(root.querySelectorAll(TRIGGER_SELECTOR));
  /** @type {Array<{trigger: HTMLElement, badge: HTMLElement|null, baseLabel: string}>} */
  const targets = triggers.map((trigger) => ({
    trigger: /** @type {HTMLElement} */ (trigger),
    badge: /** @type {HTMLElement|null} */ (trigger.querySelector(BADGE_SELECTOR)),
    baseLabel: trigger.getAttribute('aria-label') ?? 'Carrito de compras',
  }));

  let lastCount = -1;
  /** @type {boolean|null} */
  let lastOpen = null;
  let primed = false;

  /** @param {AnimationEvent} event */
  function onBumpEnd(event) {
    delete /** @type {HTMLElement} */ (event.currentTarget).dataset.bump;
  }

  /** @param {import('./store.js').CartState} state */
  function render(state) {
    const { open } = state.drawer;
    if (open !== lastOpen) {
      lastOpen = open;
      for (const { trigger } of targets) trigger.setAttribute('aria-expanded', String(open));
    }

    const { count } = selectTotals(state);
    if (count === lastCount) return;
    const direction = count > lastCount ? 'up' : 'down';
    const animate = primed;
    lastCount = count;
    primed = primed || state.status === 'ready';

    const text = formatBadge(count);

    for (const { trigger, badge, baseLabel } of targets) {
      trigger.setAttribute('aria-label', count > 0 ? `${baseLabel}, ${formatCount(count)}` : baseLabel);
      if (!badge) continue;
      badge.textContent = text;
      badge.hidden = count === 0;
      if (animate) badge.dataset.bump = direction;
    }
  }

  for (const { badge } of targets) badge?.addEventListener('animationend', onBumpEnd);
  const unsubscribe = store.subscribe(render);
  render(store.getState());

  return Object.freeze({
    dispose() {
      unsubscribe();
      for (const { badge } of targets) badge?.removeEventListener('animationend', onBumpEnd);
    },
  });
}

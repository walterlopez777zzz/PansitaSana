/**
 * catalog-filter/search-ui.js — Panel de búsqueda (máquina de estados
 * idle → opening → open → closing → idle).
 *
 * Este módulo sólo toca su propio panel y el store: nunca al catálogo. El
 * texto escrito llega al store con debounce; cerrar vacía la consulta de
 * inmediato (el catálogo se restaura en el mismo frame en que empieza el
 * desvanecimiento del panel). Todos los listeners y temporizadores cuelgan de
 * un único AbortController, así que `destroy()` los libera de golpe.
 */

const SEARCH_VIEWS = new Set(['viaregalo', 'viacuenta']);
const TRANSITION_FALLBACK_MS = 500;
const FOCUSABLE = 'input:not([disabled]), button:not([disabled])';

/**
 * Resuelve cuando termina la transición de `property` en `el` o tras el
 * tiempo de gracia. Se cancela con `signal`.
 * @param {HTMLElement} el
 * @param {string} property
 * @param {AbortSignal} signal
 * @returns {Promise<void>}
 */
function afterTransition(el, property, signal) {
  return new Promise((resolve) => {
    const done = () => {
      clearTimeout(timer);
      el.removeEventListener('transitionend', onEnd);
      signal.removeEventListener('abort', done);
      resolve();
    };
    const onEnd = (event) => {
      if (event.target === el && event.propertyName === property) done();
    };
    const timer = setTimeout(done, TRANSITION_FALLBACK_MS);
    el.addEventListener('transitionend', onEnd);
    signal.addEventListener('abort', done, { once: true });
  });
}

/**
 * @param {object} options
 * @param {import('./state.js').FilterStore} options.store
 * @param {number} [options.debounceMs]
 * @param {Document} [options.doc]
 */
export function mountSearchUi({ store, debounceMs = 250, doc = document }) {
  const system = doc.getElementById('search');
  const trigger = doc.getElementById('search-trigger');
  const panel = doc.getElementById('search-panel');
  const input = doc.getElementById('search-input');
  const closeBtn = doc.getElementById('search-close');
  if (!system || !trigger || !panel || !input || !closeBtn) return null;

  const controller = new AbortController();
  const { signal } = controller;
  const win = doc.defaultView;

  /** @type {'idle' | 'opening' | 'open' | 'closing'} */
  let phase = 'idle';
  /** @type {HTMLElement | null} */
  let previousFocus = null;
  let debounceTimer = 0;

  const setPhase = (next) => {
    phase = next;
    system.setAttribute('data-state', next);
    trigger.setAttribute('aria-expanded', String(next === 'opening' || next === 'open'));
    doc.body.classList.toggle('search-active', next === 'opening' || next === 'open');
  };

  const cancelDebounce = () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = 0;
  };

  const commitQuery = () => {
    debounceTimer = 0;
    store.set({ query: input.value });
  };

  async function open() {
    if (phase !== 'idle') return;
    previousFocus = doc.activeElement instanceof HTMLElement ? doc.activeElement : null;
    setPhase('opening');
    panel.hidden = false;
    await afterTransition(panel, 'opacity', signal);
    if (signal.aborted || phase !== 'opening') return;
    setPhase('open');
    // La vista pudo cambiar durante la animación de apertura.
    if (trigger.hidden) {
      close();
      return;
    }
    input.focus({ preventScroll: true });
  }

  async function close() {
    if (phase !== 'open') return;
    cancelDebounce();
    input.value = '';
    store.clearSearch();
    setPhase('closing');
    await afterTransition(panel, 'opacity', signal);
    if (signal.aborted || phase !== 'closing') return;
    panel.hidden = true;
    setPhase('idle');
    if (previousFocus && previousFocus.isConnected) previousFocus.focus({ preventScroll: true });
    previousFocus = null;
  }

  const trapTab = (event) => {
    if (event.key !== 'Tab' || phase !== 'open' || !panel.contains(doc.activeElement)) return;
    const focusables = Array.from(panel.querySelectorAll(FOCUSABLE));
    if (!focusables.length) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (event.shiftKey && doc.activeElement === first) {
      event.preventDefault();
      last.focus({ preventScroll: true });
    } else if (!event.shiftKey && doc.activeElement === last) {
      event.preventDefault();
      first.focus({ preventScroll: true });
    }
  };

  const onKeydown = (event) => {
    const isMac = /Mac|iPhone|iPad|iPod/.test(win.navigator.platform);
    const shortcut = event.key === 'F3' || (event.key === 'f' && (isMac ? event.metaKey : event.ctrlKey));
    if (shortcut) {
      event.preventDefault();
      event.stopPropagation();
      if (phase === 'idle' && !trigger.hidden) open();
      else if (phase === 'open') close();
      return;
    }
    if (event.key === 'Escape' && phase === 'open' && panel.contains(event.target)) {
      event.preventDefault();
      close();
      return;
    }
    if (event.key === 'Enter' && event.target === input && debounceTimer) {
      cancelDebounce();
      commitQuery();
      return;
    }
    trapTab(event);
  };

  const syncView = () => {
    const allowed = SEARCH_VIEWS.has(doc.body.dataset.view || '');
    trigger.hidden = !allowed;
    if (!allowed && phase === 'open') close();
  };

  const syncExpandDuration = () => {
    const vw = win.innerWidth;
    const seconds = Math.max(0.5, Math.min(2.2, 0.5 + ((vw - 768) * 1.7) / 632));
    doc.documentElement.style.setProperty('--_expand-duration', `${seconds.toFixed(2)}s`);
  };

  trigger.addEventListener('click', open, { signal });
  closeBtn.addEventListener('click', close, { signal });
  input.addEventListener(
    'input',
    () => {
      cancelDebounce();
      debounceTimer = setTimeout(commitQuery, debounceMs);
    },
    { signal },
  );
  doc.addEventListener('keydown', onKeydown, { signal });
  doc.addEventListener('banano:view-change', syncView, { signal });
  win.addEventListener('resize', syncExpandDuration, { passive: true, signal });

  setPhase('idle');
  syncView();
  syncExpandDuration();

  return {
    open,
    close,
    destroy() {
      cancelDebounce();
      controller.abort();
      doc.body.classList.remove('search-active');
    },
  };
}

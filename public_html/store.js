/**
 * Minimal observable store.
 *
 * Replaces the previous pattern of treating `document.body[data-view]` as the
 * source of truth and reacting to it through scattered `MutationObserver`s.
 * State lives here; the DOM is derived from it.
 *
 * NOTE: `main.js` inlines an identical `createStore` because the frontend build
 * does not rewrite static cross-file `import` specifiers (only dynamic
 * `import()` and `new Worker()` are resolved via `window.__FRONTEND_ASSETS__`).
 * This standalone module is kept as the canonical definition / for unit tests.
 *
 * @template T
 * @param {T} initialState
 * @returns {{
 *   getState: () => T,
 *   setState: (patch: Partial<T> | ((state: T) => Partial<T>)) => void,
 *   subscribe: (listener: (state: T, prev: T) => void) => () => void
 * }}
 */
export function createStore(initialState) {
  let state = { ...initialState };
  const listeners = new Set();

  function getState() {
    return state;
  }

  function setState(patch) {
    const partial = typeof patch === 'function' ? patch(state) : patch;
    if (!partial) return;

    const prev = state;
    const next = { ...state, ...partial };

    // Bail out if nothing actually changed (shallow compare).
    let changed = false;
    for (const key of Object.keys(partial)) {
      if (prev[key] !== next[key]) {
        changed = true;
        break;
      }
    }
    if (!changed) return;

    state = next;
    listeners.forEach((listener) => {
      try {
        listener(state, prev);
      } catch (err) {
        console.error('[store] listener error', err);
      }
    });
  }

  function subscribe(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
  }

  return { getState, setState, subscribe };
}

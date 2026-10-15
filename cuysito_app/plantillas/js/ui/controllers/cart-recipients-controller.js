/**
 * Cart Recipients Controller
 *
 * Manages "Para:" recipient groups in Cart Mode.
 * Each recipient represents a username that items are grouped under.
 * Recipients are stored as `cart.recipients[]` with { id, username, startIndex }.
 */

const DELETE_ICON = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="1em" height="1em">
  <path fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round"
    stroke-linejoin="round" d="M35 12H65M12 32H88M25 32L34 90H66L75 32" />
</svg>`;

const USER_ICON = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" fill="none">
  <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/>
  <path d="M4 20c0-3.3 3.6-6 8-6s8 2.7 8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
</svg>`;

function generateRecipientId() {
  return `r_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
}

/**
 * Normalizes the recipients array, ensuring it is always a valid array.
 * @param {unknown} recipients
 * @returns {Array<{id: string, username: string, startIndex: number}>}
 */
function normalizeRecipients(recipients) {
  if (!Array.isArray(recipients)) return [];
  return recipients;
}

/**
 * Computes how many items belong to each recipient.
 * @param {Array} recipients
 * @param {number} totalItems
 * @returns {Map<string, number>} recipientId -> item count
 */
function computeRecipientItemCounts(recipients, totalItems) {
  const counts = new Map();
  if (!recipients.length) return counts;

  for (let i = 0; i < recipients.length; i++) {
    const current = recipients[i];
    const nextStart = i + 1 < recipients.length
      ? recipients[i + 1].startIndex
      : totalItems;
    const count = Math.max(0, nextStart - current.startIndex);
    counts.set(current.id, count);
  }
  return counts;
}


export function createCartRecipientsController({
  store,
  onInput,
  addRecipientBtnEl,
  recipientsListEl,
  recipientsSectionEl,
} = {}) {
  if (!store || !addRecipientBtnEl || !recipientsListEl) {
    return Object.freeze({
      render() {},
      show() {},
      hide() {},
    });
  }

  /* ── Add recipient ─────────────────────────────────────── */

  addRecipientBtnEl.addEventListener("click", () => {
    store.setState((state) => {
      const cart = state?.cart || {};
      const items = Array.isArray(cart.items) ? cart.items : [];
      const recipients = normalizeRecipients(cart.recipients);
      const newId = generateRecipientId();

      // First recipient always starts at index 0 (covers existing items)
      // Subsequent recipients start at the end of the items array
      const startIndex = recipients.length === 0 ? 0 : items.length;

      const newRecipient = { id: newId, username: "", startIndex };

      return {
        ...state,
        cart: {
          ...cart,
          recipients: [...recipients, newRecipient],
        },
      };
    });
  });


  /* ── Remove recipient ──────────────────────────────────── */

  function removeRecipient(recipientId) {
    store.setState((state) => {
      const cart = state?.cart || {};
      const recipients = normalizeRecipients(cart.recipients);
      const idx = recipients.findIndex((r) => r.id === recipientId);
      if (idx < 0) return state;

      const next = recipients.filter((r) => r.id !== recipientId);

      // If removing the first recipient and there are more,
      // ensure the new first starts at 0
      if (next.length > 0 && next[0].startIndex > 0) {
        next[0] = { ...next[0], startIndex: 0 };
      }

      return {
        ...state,
        cart: {
          ...cart,
          recipients: next,
        },
      };
    });
  }


  /* ── Update username ───────────────────────────────────── */

  function updateRecipientUsername(recipientId, username) {
    store.setState((state) => {
      const cart = state?.cart || {};
      const recipients = normalizeRecipients(cart.recipients);
      const idx = recipients.findIndex((r) => r.id === recipientId);
      if (idx < 0) return state;

      const updated = [...recipients];
      updated[idx] = { ...updated[idx], username };

      return {
        ...state,
        cart: {
          ...cart,
          recipients: updated,
        },
      };
    });
  }


  /* ── Render the recipients list in the panel ───────────── */

  let _lastRenderedIdsJSON = "";

  function renderFull(recipients, counts) {
    recipientsListEl.textContent = "";
    if (recipients.length === 0) return;

    const fragment = document.createDocumentFragment();

    for (let i = 0; i < recipients.length; i++) {
      const recipient = recipients[i];
      const count = counts.get(recipient.id) || 0;

      const entry = document.createElement("div");
      entry.className = "cart-recipient-entry";
      entry.dataset.recipientId = recipient.id;

      // Header row: icon + "Para:" label + input + delete button
      const header = document.createElement("div");
      header.className = "cart-recipient-entry__header";

      const iconSpan = document.createElement("span");
      iconSpan.className = "cart-recipient-entry__icon";
      iconSpan.innerHTML = USER_ICON;

      const label = document.createElement("span");
      label.className = "cart-recipient-entry__label";
      label.textContent = "Para:";

      const input = document.createElement("input");
      input.className = "input cart-recipient-entry__input";
      input.type = "text";
      input.placeholder = "Nombre de usuario";
      input.autocomplete = "off";
      input.spellcheck = false;
      input.value = recipient.username;
      input.setAttribute("aria-label", `Nombre de usuario del destinatario ${i + 1}`);

      // Debounced input handler — updates state but does NOT trigger a full re-render
      let inputTimer = 0;
      input.addEventListener("input", (e) => {
        clearTimeout(inputTimer);
        const val = e.target.value;
        inputTimer = setTimeout(() => {
          updateRecipientUsername(recipient.id, val);
        }, 150);
      });

      const deleteBtn = document.createElement("button");
      deleteBtn.className = "icon-btn icon-btn--danger cart-recipient-entry__delete";
      deleteBtn.type = "button";
      deleteBtn.title = "Eliminar destinatario";
      deleteBtn.setAttribute("aria-label", "Eliminar destinatario");
      deleteBtn.innerHTML = DELETE_ICON;
      deleteBtn.addEventListener("click", () => removeRecipient(recipient.id));

      header.append(iconSpan, label, input, deleteBtn);

      // Item count
      const countEl = document.createElement("div");
      countEl.className = "cart-recipient-entry__count";
      countEl.textContent = count === 1 ? "1 artículo" : `${count} artículos`;

      entry.append(header, countEl);
      fragment.appendChild(entry);
    }

    recipientsListEl.appendChild(fragment);
  }

  function renderInPlace(recipients, counts) {
    for (const recipient of recipients) {
      const entry = recipientsListEl.querySelector(`[data-recipient-id="${recipient.id}"]`);
      if (!entry) continue;
      // Only update the input value if it is NOT the currently focused element
      // (otherwise we'd clobber what the user is typing)
      const input = entry.querySelector(".cart-recipient-entry__input");
      if (input && document.activeElement !== input) {
        input.value = recipient.username;
      }
      const countEl = entry.querySelector(".cart-recipient-entry__count");
      const count = counts.get(recipient.id) || 0;
      if (countEl) {
        const text = count === 1 ? "1 artículo" : `${count} artículos`;
        if (countEl.textContent !== text) countEl.textContent = text;
      }
    }
  }

  function render(cartState) {
    const cart = cartState || store.getState()?.cart || {};
    const recipients = normalizeRecipients(cart.recipients);
    const items = Array.isArray(cart.items) ? cart.items : [];
    const counts = computeRecipientItemCounts(recipients, items.length);

    // Structural check: only rebuild DOM when the set of recipient IDs changes
    const idsJSON = JSON.stringify(recipients.map((r) => r.id));
    if (idsJSON !== _lastRenderedIdsJSON) {
      _lastRenderedIdsJSON = idsJSON;
      renderFull(recipients, counts);
    } else {
      // Just patch values and counts without disturbing focus
      renderInPlace(recipients, counts);
    }
  }


  /* ── Show / Hide ───────────────────────────────────────── */

  function show() {
    if (recipientsSectionEl) recipientsSectionEl.hidden = false;
  }

  function hide() {
    if (recipientsSectionEl) recipientsSectionEl.hidden = true;
  }


  return Object.freeze({ render, show, hide });
}

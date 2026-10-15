import { CARD_SOURCES } from "../../../domain/collage/collage-item.js";
import { MAX_BATCH_QUANTITY, MIN_BATCH_QUANTITY } from "../../../domain/cards/card-commands.js";
import { DELETE_SVG, PLUS_SVG, VBUCKS_SVG } from "../../components/svg-icons.js";

/**
 * Vista del diálogo «Añadir tarjetas»: funciones puras que construyen DOM a
 * partir de datos ya resueltos. No leen el store ni conservan estado; el
 * controlador decide cuándo re-renderizar cada lista.
 *
 * Todo el texto proveniente de datos se inserta como nodos de texto (nunca
 * `innerHTML`); solo los iconos SVG constantes se inyectan como markup.
 */

const SOURCE_LABEL = Object.freeze({
  [CARD_SOURCES.VIA_REGALO]: "Vía Regalo",
  [CARD_SOURCES.VIA_CUENTA]: "Vía Cuenta",
});

export const OPTION_ID_PREFIX = "add-cards-opt-";

export function optionElementId(index) {
  return `${OPTION_ID_PREFIX}${index}`;
}

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text != null) node.textContent = text;
  return node;
}

function iconButton(className, svg, label, action) {
  const button = el("button", `icon-btn ${className}`);
  button.type = "button";
  button.dataset.action = action;
  button.setAttribute("aria-label", label);
  button.title = label;
  button.innerHTML = svg;
  return button;
}

function thumb(imageUrl, alt = "") {
  if (!imageUrl) {
    const empty = el("span", "add-cards__thumb add-cards__thumb--empty", "?");
    empty.setAttribute("aria-hidden", "true");
    return empty;
  }
  const img = el("img", "add-cards__thumb");
  img.src = imageUrl;
  img.alt = alt;
  img.loading = "lazy";
  img.decoding = "async";
  img.referrerPolicy = "no-referrer";
  return img;
}

/** Nombre con `<mark>` sobre el rango resaltado (si lo hay). */
function highlightedName(name, range) {
  const span = el("span", "add-cards__name");
  span.title = name;
  if (!range || range.end <= range.start) {
    span.textContent = name;
    return span;
  }
  span.append(
    document.createTextNode(name.slice(0, range.start)),
    el("mark", "", name.slice(range.start, range.end)),
    document.createTextNode(name.slice(range.end)),
  );
  return span;
}

function priceNode(priceLabel, vbucks) {
  const price = el("span", "add-cards__price");
  if (!priceLabel) {
    price.textContent = "—";
    return price;
  }
  if (vbucks) {
    const icon = el("span");
    icon.setAttribute("aria-hidden", "true");
    icon.innerHTML = VBUCKS_SVG;
    price.append(icon, document.createTextNode(priceLabel));
    price.setAttribute("aria-label", `${priceLabel} V-Bucks`);
  } else {
    price.textContent = priceLabel;
  }
  return price;
}

function metaNode(entry) {
  const meta = el("span", "add-cards__meta");
  const source = el("span", "add-cards__source", SOURCE_LABEL[entry.source] || entry.source);
  source.dataset.source = entry.source;
  meta.append(source);
  if (entry.statusLabel) meta.append(el("span", "add-cards__badge", entry.statusLabel));
  const detail = [entry.typeLabel, entry.rarityLabel].filter(Boolean).join(" · ");
  if (detail) meta.append(el("span", "", detail));
  return meta;
}

/**
 * @param {HTMLElement} listEl  `<ul role="listbox">`
 * @param {import("../../../domain/cards/catalog-entries.js").CatalogSearchResult} result
 * @param {number} activeIndex
 */
export function renderResults(listEl, result, activeIndex) {
  const fragment = document.createDocumentFragment();
  result.entries.forEach((entry, index) => {
    const option = el("li", "add-cards__option");
    option.id = optionElementId(index);
    option.setAttribute("role", "option");
    option.setAttribute("aria-selected", index === activeIndex ? "true" : "false");
    option.dataset.key = entry.key;
    option.dataset.available = entry.available ? "true" : "false";

    const body = el("span", "add-cards__option-body");
    body.append(highlightedName(entry.displayName, result.highlightFor(entry)), metaNode(entry));

    const trailing = el("span", "add-cards__option-trailing");
    trailing.append(priceNode(entry.priceLabel, entry.vbucks));
    trailing.append(iconButton("add-cards__option-add", PLUS_SVG, `Añadir ${entry.displayName} a la cesta`, "add"));

    option.append(thumb(entry.imageUrl), body, trailing);
    fragment.append(option);
  });
  listEl.replaceChildren(fragment);
}

/** Cambia solo el `aria-selected` de las dos opciones afectadas. */
export function updateActiveOption(listEl, previousIndex, nextIndex) {
  const previous = previousIndex >= 0 ? listEl.children[previousIndex] : null;
  const next = nextIndex >= 0 ? listEl.children[nextIndex] : null;
  if (previous && previous !== next) previous.setAttribute("aria-selected", "false");
  if (next) {
    next.setAttribute("aria-selected", "true");
    next.scrollIntoView({ block: "nearest" });
  }
  return next ? next.id : "";
}

function quantityControl(line) {
  const wrapper = el("span", "add-cards__qty");
  wrapper.setAttribute("role", "group");
  wrapper.setAttribute("aria-label", "Cantidad");

  const dec = el("button", "add-cards__qty-btn", "−");
  dec.type = "button";
  dec.dataset.action = "dec";
  dec.setAttribute("aria-label", "Una menos");
  dec.disabled = line.quantity <= MIN_BATCH_QUANTITY;

  const input = el("input", "add-cards__qty-input");
  input.type = "number";
  input.inputMode = "numeric";
  input.min = String(MIN_BATCH_QUANTITY);
  input.max = String(MAX_BATCH_QUANTITY);
  input.step = "1";
  input.value = String(line.quantity);
  input.dataset.action = "qty";
  input.setAttribute("aria-label", "Cantidad de tarjetas");

  const inc = el("button", "add-cards__qty-btn", "+");
  inc.type = "button";
  inc.dataset.action = "inc";
  inc.setAttribute("aria-label", "Una más");
  inc.disabled = line.quantity >= MAX_BATCH_QUANTITY;

  wrapper.append(dec, input, inc);
  return wrapper;
}

/**
 * @param {HTMLElement} listEl
 * @param {import("../../../domain/cards/add-cards-basket.js").Basket} basket
 */
export function renderBasket(listEl, basket) {
  const fragment = document.createDocumentFragment();
  for (const line of basket.lines) {
    const item = el("li", "add-cards__line");
    item.dataset.key = line.key;

    const isFree = line.kind === "free";
    const name = isFree ? line.free.title : line.entry.displayName;
    const imageUrl = isFree ? line.free.imageUrl : line.entry.imageUrl;

    const body = el("span", "add-cards__option-body");
    body.append(highlightedName(name, null));
    const meta = el("span", "add-cards__meta");
    if (isFree) {
      const source = el("span", "add-cards__source", "Libre");
      source.dataset.source = CARD_SOURCES.VIA_CUENTA;
      meta.append(source, el("span", "", `S/ ${line.free.priceText}`));
    } else {
      const source = el("span", "add-cards__source", SOURCE_LABEL[line.entry.source] || line.entry.source);
      source.dataset.source = line.entry.source;
      meta.append(source, priceNode(line.entry.priceLabel, line.entry.vbucks));
    }
    body.append(meta);

    item.append(
      thumb(imageUrl),
      body,
      quantityControl(line),
      iconButton("add-cards__line-remove", DELETE_SVG, `Quitar ${name} de la cesta`, "remove"),
    );
    fragment.append(item);
  }
  listEl.replaceChildren(fragment);
}

/**
 * @param {HTMLElement} listEl
 * @param {ReadonlyArray<import("./free-card-presets-store.js").FreeCardPreset>} presets
 */
export function renderPresets(listEl, presets) {
  const fragment = document.createDocumentFragment();
  for (const preset of presets) {
    const item = el("li", "add-cards__preset-item");
    item.dataset.presetId = preset.id;

    const use = el("button", "add-cards__preset");
    use.type = "button";
    use.dataset.action = "use-preset";
    use.title = preset.priceText ? `${preset.title} · S/ ${preset.priceText}` : preset.title;
    const body = el("span", "add-cards__option-body");
    body.append(highlightedName(preset.title, null));
    body.append(el("span", "add-cards__meta", preset.priceText ? `S/ ${preset.priceText}` : "Sin precio"));
    use.append(thumb(preset.imageUrl), body);

    item.append(use, iconButton("add-cards__preset-remove", DELETE_SVG, `Eliminar preset ${preset.title}`, "remove-preset"));
    fragment.append(item);
  }
  listEl.replaceChildren(fragment);
}

/**
 * Texto de estado del catálogo: recuento de resultados + estado Vía Cuenta.
 * Devuelve `retry: true` si procede ofrecer «Reintentar».
 */
export function renderCatalogStatus(statusEl, { total, viaCuentaStatus, viaCuentaError }) {
  const parts = [total === 1 ? "1 resultado" : `${total} resultados`];
  let retry = false;
  if (viaCuentaStatus === "loading") parts.push("cargando Vía Cuenta…");
  else if (viaCuentaStatus === "error") {
    parts.push(`Vía Cuenta no disponible${viaCuentaError ? ` (${viaCuentaError})` : ""}.`);
    retry = true;
  }
  statusEl.replaceChildren(document.createTextNode(parts.join(" · ")));
  if (retry) {
    const button = el("button", "add-cards__retry", "Reintentar");
    button.type = "button";
    button.dataset.action = "retry";
    statusEl.append(button);
  }
}

/**
 * Hit-testing sobre las cajas registradas por los renderers.
 *
 * Las tarjetas se prueban de la última a la primera (la que se pinta encima
 * gana) y, dentro de cada tarjeta, de la parte más pequeña/superior (fecha,
 * banner, precios, nombre, imagen) al contorno completo. Los ids que devuelve
 * son los mismos que registró el renderer (`card:<cardId>[:kind]` o legacy
 * `collage:<index>[:kind]`), por lo que este módulo no interpreta formatos.
 */

const SINGLE_MODE_PARTS = Object.freeze([
  "name",
  "date",
  "rarity",
  "type",
  "solesOffer",
  "solesList",
  "vbucks",
  "label",
]);

const COLLAGE_GLOBAL_PARTS = Object.freeze(["collage:title", "collage:cartTotal", "collage:branding"]);

const RECIPIENT_PREFIX = "collage:recipient:";

function containsPoint(box, x, y) {
  return Boolean(box) && x >= box.x && y >= box.y && x <= box.x + box.w && y <= box.y + box.h;
}

function cardPartOrder(group) {
  return [
    group.outDateId,
    group.bannerId,
    group.solesOfferId,
    group.solesListId,
    group.priceId,
    group.nameId,
    group.imageId,
    group.cardId,
  ];
}

function hitCollageGroups(hitBoxes, groups, x, y) {
  for (let i = groups.length - 1; i >= 0; i -= 1) {
    const group = groups[i];
    if (!group) continue;
    for (const id of cardPartOrder(group)) {
      if (id && containsPoint(hitBoxes[id], x, y)) return id;
    }
  }
  for (const key of Object.keys(hitBoxes)) {
    if (key.startsWith(RECIPIENT_PREFIX) && containsPoint(hitBoxes[key], x, y)) return key;
  }
  for (const key of COLLAGE_GLOBAL_PARTS) {
    if (containsPoint(hitBoxes[key], x, y)) return key;
  }
  return null;
}

/**
 * @param {Record<string, {x:number,y:number,w:number,h:number}>|null} hitBoxes
 * @param {number} x
 * @param {number} y
 * @param {{layerKey?: (id: string) => string}} [options]
 * @returns {string|null} id del elemento bajo el puntero
 */
export function hitTest(hitBoxes, x, y, options = {}) {
  if (!hitBoxes) return null;
  const layerKey = typeof options.layerKey === "function" ? options.layerKey : (id) => `layer:${String(id || "")}`;

  if (Array.isArray(hitBoxes.collageGroups)) {
    return hitCollageGroups(hitBoxes, hitBoxes.collageGroups, x, y);
  }

  for (const part of SINGLE_MODE_PARTS) {
    if (containsPoint(hitBoxes[part], x, y)) return part;
  }

  const layers = Array.isArray(hitBoxes.layers) ? hitBoxes.layers : [];
  for (let i = layers.length - 1; i >= 0; i -= 1) {
    const layer = layers[i];
    if (containsPoint(layer, x, y)) return layerKey(String(layer.id || ""));
  }

  return containsPoint(hitBoxes["collage:branding"], x, y) ? "collage:branding" : null;
}

/**
 * Card image framing for collage/cart cards, derived 1:1 from the main shop
 * CSS (public_html/style.css, `.item-image` rules).
 *
 * Mapping: a collage card is treated as the shop's visible card box (the
 * bottom `1 - --tile-headroom` part of a tile), so every percentage below is
 * relative to the card rect. Each spec mirrors one CSS variant:
 *   fit      -> object-fit (cover | contain)
 *   scale    -> --img-scale
 *   cx / cy  -> --img-left / --img-top (fractions of card width/height)
 *   elem     -> the layout box of the <img> element:
 *                 "rect"    width:100%; height: hFrac * card height
 *                 "squareH" width:auto; aspect-ratio:1; side = hFrac * card height
 *                 "squareW" width:100%; aspect-ratio:1; side = card width
 *   anchorTop-> object-position: top
 *
 * The shop clips the artwork with `clip-path: inset(-18% 0 0 0 round 7px)`,
 * i.e. sides/bottom stay clipped while the top may overflow up to 18% of the
 * card height. CARD_IMAGE_TOP_OVERFLOW_RATIO mirrors that value.
 */

export const CARD_IMAGE_TOP_OVERFLOW_RATIO = 0.18;

/** Framing mode stored in `style.image.framing` for shop-accurate items. */
export const CARD_IMAGE_FRAMING_STORE = "store";

const VEHICLE_CATEGORIES = Object.freeze([
    "vehicle_body",
    "vehicle_skin",
    "vehicle_wheel",
    "vehicle_booster",
    "vehicle_trail",
]);

const spec = (fit, scale, cx, cy, elem, hFrac, anchorTop = false, anchorRight = false) =>
    Object.freeze({ fit, scale, cx, cy, elem, hFrac, anchorTop, anchorRight });

// Default `.item-image` (outfits and any unmatched type).
const DEFAULT_SPEC = spec("cover", 1.6, 0.5, 0.765, "rect", 1.1765);
// Gestos / Envoltorios / Estelas / Música / Ruedas.
const STANDARD_CONTAIN_SPEC = spec("contain", 0.7, 0.528, 0.38, "squareH", 1, true);
// Picos / Instrumentos.
const PICKAXE_SPEC = spec("contain", 0.75, 0.498, 0.259, "squareH", 1.1765);
// Alas delta / Accesorios mochileros.
const GLIDER_BACKPACK_SPEC = spec("contain", 0.78, 0.528, 0.42, "squareH", 1);
// Calzado.
const SHOE_SPEC = spec("contain", 0.8, 0.528, 0.42, "squareH", 1);
// Compañeros.
const SIDEKICK_SPEC = spec("contain", 0.7, 0.528, 0.38, "squareH", 1);
// Pistas de improvisación (--img-top: calc(14.7% + 2.5rem) ≈ 26.5%).
const TRACK_SPEC = spec("cover", 0.875, 0.5, 0.265, "squareW", 1);
// Coches (all spans keep cover ×1).
const VEHICLE_BODY_SPEC = spec("cover", 1, 0.5, 0.412, "rect", 1.1765);
// Ofertas de coche (`[data-car-offer="1"] .item-image`): cover ×1 filling the
// card, `object-position: 100% 50%`, `clip-path: inset(0)` (no pop-out).
const CAR_OFFER_SPEC = spec("cover", 1, 0.5, 0.5, "rect", 1, false, true);
// Generic `.span-card-2/3/4 .item-image` override for wide cards. Per-type
// wide overrides below inherit this element box (full-width contain layer)
// and only change --img-scale / --img-top (tracks also --img-left).
const WIDE_GENERIC_SPEC = spec("contain", 1.6, 0.5, 0.747, "rect", 1.1765);

// Wide override that keeps the generic full-width contain layer and only
// swaps --img-scale / --img-top (and optionally --img-left).
const wideSpec = (scale, cy, cx = 0.5) => spec("contain", scale, cx, cy, "rect", 1.1765);

// A framing row: one spec per card width in grid units (1–4).
const row = (one, two, three, four = three) =>
    Object.freeze({ 1: one, 2: two, 3: three, 4: four });

// scaled-standard wide: grande 0.9 @ 38.2% · venti/trenta 0.95 @ 41.2%.
const STANDARD_ROW = row(
    STANDARD_CONTAIN_SPEC,
    wideSpec(0.9, 0.382),
    wideSpec(0.95, 0.412),
);
// scaled-half-step wide: grande 0.85 @ 30.6% · venti/trenta 0.95 @ 35.6%.
const PICKAXE_ROW = row(PICKAXE_SPEC, wideSpec(0.85, 0.306), wideSpec(0.95, 0.356));
const GLIDER_BACKPACK_ROW = row(
    GLIDER_BACKPACK_SPEC,
    wideSpec(0.85, 0.306),
    wideSpec(0.95, 0.356),
);
// kicks wide: grande 0.9 @ 23.5% · venti/trenta 0.95 @ 29.4%.
const SHOE_ROW = row(SHOE_SPEC, wideSpec(0.9, 0.235), wideSpec(0.95, 0.294));
// companion wide: grande 0.9 · venti 0.95 · trenta 1 — all @ 32.9%.
const SIDEKICK_ROW = row(
    SIDEKICK_SPEC,
    wideSpec(0.9, 0.329),
    wideSpec(0.95, 0.329),
    wideSpec(1, 0.329),
);
// full-square wide (jam tracks): the art keeps a square layer (w-auto,
// side = --img-h of the wide layer) offset right on venti/trenta.
const trackWideSpec = (scale, cy, cx) => spec("contain", scale, cx, cy, "squareH", 1.1765);
const TRACK_ROW = row(
    TRACK_SPEC,
    trackWideSpec(0.75, 0.306, 0.5),
    trackWideSpec(0.8, 0.353, 0.625),
    trackWideSpec(0.8, 0.353, 0.6),
);
// Música has NO per-type wide override in the shop — wide falls back to the
// generic wide layer (its square album art still fits it edge-to-edge).
const MUSIC_ROW = row(
    STANDARD_CONTAIN_SPEC,
    WIDE_GENERIC_SPEC,
    WIDE_GENERIC_SPEC,
);
// full (car bodies): plain cover ×1 at every span.
const VEHICLE_BODY_ROW = row(VEHICLE_BODY_SPEC, VEHICLE_BODY_SPEC, VEHICLE_BODY_SPEC);
// Lotes (and dynamic bundles) per span.
const BUNDLE_ROW = row(
    spec("cover", 1, 0.5, 0.471, "rect", 1.1765),
    spec("contain", 1.4, 0.5, 0.706, "rect", 1.1765),
    spec("contain", 1.75, 0.5, 0.741, "rect", 1.1765),
    spec("contain", 2.28, 0.5, 1.094, "rect", 1.1765),
);

/**
 * Category × width framing table, mirroring the shop CSS cascade:
 * default `.item-image` → per-type 1×1 override → generic wide override →
 * per-type wide override. Categories missing a row fall back to the wide
 * generic (width > 1) or the default cover spec (width 1), exactly like
 * un-matched `data-item-type` values do in the shop.
 */
const FRAMING_TABLE = Object.freeze({
    emote: STANDARD_ROW,
    wrap: STANDARD_ROW,
    contrail: STANDARD_ROW,
    vehicle_wheel: STANDARD_ROW,
    vehicle_trail: STANDARD_ROW,
    music: MUSIC_ROW,
    pickaxe: PICKAXE_ROW,
    sparks_guitar: PICKAXE_ROW,
    sparks_bass: PICKAXE_ROW,
    sparks_drum: PICKAXE_ROW,
    sparks_keyboard: PICKAXE_ROW,
    sparks_mic: PICKAXE_ROW,
    glider: GLIDER_BACKPACK_ROW,
    backpack: GLIDER_BACKPACK_ROW,
    shoe: SHOE_ROW,
    sidekick: SIDEKICK_ROW,
    track: TRACK_ROW,
    vehicle_body: VEHICLE_BODY_ROW,
    bundle: BUNDLE_ROW,
});

const LABEL_CATEGORY_RULES = Object.freeze([
    ["bundle", ["LOTE", "BUNDLE"]],
    ["emote", ["GESTO", "EMOTE"]],
    ["wrap", ["ENVOLTORIO", "WRAP", "PAPEL"]],
    ["contrail", ["ESTELA", "TRAIL", "CONTRAIL"]],
    ["music", ["MÚSICA", "MUSICA", "MUSIC"]],
    ["vehicle_wheel", ["RUEDA", "WHEEL"]],
    ["pickaxe", ["PICO", "PICKAXE"]],
    ["sparks_guitar", ["INSTRUMENTO", "GUITARRA", "BAJO", "BATERÍA", "TECLADO", "MICRÓFONO"]],
    ["glider", ["PLANEADOR", "ALA DELTA", "ALADELTA", "GLIDER"]],
    ["backpack", ["MOCHIL", "BACK BLING"]],
    ["shoe", ["CALZADO", "ZAPAT", "SHOE"]],
    ["sidekick", ["COMPAÑERO", "SIDEKICK", "PET"]],
    ["track", ["PISTA", "JAM TRACK", "TRACK"]],
    ["vehicle_body", ["CARROCERÍA", "CARROCERIA", "COCHE", "CAR BODY"]],
    ["vehicle_skin", ["PINTURA"]],
]);

const normalizeCategory = (typeCategory, typeLabel) => {
    const category = String(typeCategory || "").trim();
    if (category) return category;
    const label = String(typeLabel || "").trim().toUpperCase();
    if (!label) return "";
    for (const [resolved, keywords] of LABEL_CATEGORY_RULES) {
        if (keywords.some((keyword) => label.includes(keyword))) return resolved;
    }
    return "";
};

const normalizeWidthUnits = (widthUnits) => {
    const units = Math.round(Number(widthUnits) || 1);
    return Math.min(4, Math.max(1, units));
};

export const isVehicleTypeCategory = (typeCategory, typeLabel) =>
    VEHICLE_CATEGORIES.includes(normalizeCategory(typeCategory, typeLabel));

/**
 * Resolves the shop framing spec for an item type at a given card width.
 * @param {string} typeCategory Item schema category (e.g. "outfit").
 * @param {string} typeLabel Human label fallback (e.g. "Traje").
 * @param {number} widthUnits Card width in grid units (1–4).
 * @param {boolean} [isCarOffer] Shop `data-car-offer` flag: an entry selling
 *   only cars (no BR items/instruments/tracks), including car-only bundles.
 * @param {boolean} [isDynamicBundle] Shop `data-dynamic-bundle` flag: a
 *   multi-cosmetic offer without a bundle wrapper — the shop renders it with
 *   the bundle framing at every size, regardless of the lead item's type.
 */
export function resolveCardImageFraming(
    typeCategory,
    typeLabel,
    widthUnits,
    isCarOffer = false,
    isDynamicBundle = false,
) {
    if (isCarOffer === true) return CAR_OFFER_SPEC;
    const units = normalizeWidthUnits(widthUnits);
    if (isDynamicBundle === true) return BUNDLE_ROW[units];
    const category = normalizeCategory(typeCategory, typeLabel);
    const perType = FRAMING_TABLE[category];
    if (perType) return perType[units];
    return units > 1 ? WIDE_GENERIC_SPEC : DEFAULT_SPEC;
}

/**
 * Computes the destination rect of the artwork inside a card, replicating the
 * CSS layout (element box + object-fit + object-position + transform scale).
 * @returns {{w:number,h:number,cx:number,cy:number}} Draw size and center,
 *   relative to the card's top-left corner. `zoom` is the user multiplier
 *   applied on top of the variant scale (1 = shop-exact).
 */
export function computeFramedImageDraw(framing, { cardW, cardH, imgW, imgH, zoom = 1 }) {
    let elemW;
    let elemH;
    if (framing.elem === "squareH") {
        elemW = elemH = framing.hFrac * cardH;
    } else if (framing.elem === "squareW") {
        elemW = elemH = cardW;
    } else {
        elemW = cardW;
        elemH = framing.hFrac * cardH;
    }
    const fitScale =
        framing.fit === "contain"
            ? Math.min(elemW / imgW, elemH / imgH)
            : Math.max(elemW / imgW, elemH / imgH);
    const totalScale = framing.scale * zoom;
    const anchorShiftY = framing.anchorTop
        ? ((imgH * fitScale - elemH) / 2) * totalScale
        : 0;
    const anchorShiftX = framing.anchorRight
        ? -((imgW * fitScale - elemW) / 2) * totalScale
        : 0;
    return {
        w: imgW * fitScale * totalScale,
        h: imgH * fitScale * totalScale,
        cx: framing.cx * cardW + anchorShiftX,
        cy: framing.cy * cardH + anchorShiftY,
    };
}

/**
 * Image style defaults applied when adding an item to the grid: shop-accurate
 * framing for everyone, top overflow enabled except for vehicles and
 * car offers (the shop clips those fully: `clip-path: inset(0)`).
 * Signature kept from the legacy grid-card-image-defaults module.
 */
export function computeImageDefaults(typeLabel, widthUnits, typeCategory, isCarOffer = false) {
    return {
        framing: CARD_IMAGE_FRAMING_STORE,
        overflowTop:
            isCarOffer !== true &&
            !isVehicleTypeCategory(typeCategory, typeLabel),
    };
}

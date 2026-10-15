import { DEFAULT_COLLAGE_GLOBAL_GRADIENT_COLORS } from "../../constants/defaults.js";
import { normalizeCollageItem, CARD_SOURCES } from "../collage/collage-item.js";
import { createDefaultCollageItemStyle } from "../collage/collage-item-style.js";
import { createDefaultCollageItemDiscount } from "../collage/collage-discount.js";
import { computeImageDefaults } from "../collage/card-image-framing.js";
import {
  buildSectionBaseStyleMap,
  normalizeLayoutId,
  normalizeSectionGradientColors,
  resolveSectionStyle,
} from "../shop/shop-colors.js";
import { normalizeOptionalHex } from "../../utils/color-utils.js";
import { clamp } from "../../utils/number-utils.js";
import { createCardId } from "./card-id.js";
import { findCardIndex } from "./card-index.js";
import { ensureCardIds } from "./card-migration.js";
import { draftFromShopItem, isFreeCardDraft, freeCardOfferId } from "./card-drafts.js";
import { cardSelectionId } from "./card-selection.js";

/**
 * Comandos de dominio para tarjetas de Collage/Carrito.
 *
 * Cada comando:
 * - abre exactamente una transacción de history (cerrada en `finally`);
 * - asigna `cardId` propio a cada tarjeta creada;
 * - en Carrito reinicia `cart.totalOverride`;
 * - devuelve los `cardId` creados en orden de inserción.
 *
 * `ctx = { store, history }` · `mode = "collage" | "cart"`.
 */

export const MIN_BATCH_QUANTITY = 1;
export const MAX_BATCH_QUANTITY = 50;

export { createFreeCardDraft, draftFromShopItem, draftFromViaCuenta } from "./card-drafts.js";

function resolveSliceKey(mode) {
  return mode === "cart" ? "cart" : "collage";
}

function readSliceItems(state, sliceKey) {
  const items = state?.[sliceKey]?.items;
  return Array.isArray(items) ? items : [];
}

export function clampQuantity(quantity) {
  const numeric = Math.round(Number(quantity));
  return clamp(Number.isFinite(numeric) ? numeric : MIN_BATCH_QUANTITY, MIN_BATCH_QUANTITY, MAX_BATCH_QUANTITY);
}

function findSiblingByLayout(items, layoutId, layoutName) {
  const normalizedLayoutId = normalizeLayoutId(layoutId);
  const name = String(layoutName || "").trim();
  for (const rawItem of items) {
    const item = normalizeCollageItem(rawItem);
    if (!item) continue;
    if (normalizedLayoutId && normalizeLayoutId(item.layoutId) === normalizedLayoutId) return item;
    if (!normalizedLayoutId && name && String(item.layoutName || "").trim() === name) return item;
  }
  return null;
}

/**
 * Convierte un draft en una tarjeta completa heredando el estilo de la
 * sección (`sectionStyles`/`baseMap`) igual que hacía `grid-item-factory`.
 */
function materializeDraft(draft, cardId, { existingItems, sectionStyles, baseMap }) {
  const defaults = createDefaultCollageItemStyle();
  const layoutId = normalizeLayoutId(draft.layoutId);
  const layoutName = String(draft.layoutName || "").trim();
  const sibling = findSiblingByLayout(existingItems, layoutId, layoutName);
  const siblingCard = sibling?.style?.card || null;
  const sectionStyle = layoutId
    ? resolveSectionStyle({
      layoutId,
      sectionStyles,
      baseMap,
      fallbackColors: DEFAULT_COLLAGE_GLOBAL_GRADIENT_COLORS,
    })
    : null;
  const shopItem = draft.shopItem || null;

  const gradientColors = siblingCard && Array.isArray(siblingCard.gradientColors) && siblingCard.gradientColors.length > 0
    ? [...siblingCard.gradientColors]
    : sectionStyle?.gradientColors || normalizeSectionGradientColors(shopItem?.colors, DEFAULT_COLLAGE_GLOBAL_GRADIENT_COLORS);
  const accentColor = siblingCard
    ? siblingCard.accentColor ?? null
    : sectionStyle?.accentColor ?? normalizeOptionalHex(shopItem?.textBackgroundColor);
  const accentHidden = siblingCard ? siblingCard.accentHidden === true : sectionStyle?.accentHidden === true;
  const accentGradientHeight = siblingCard
    ? siblingCard.accentGradientHeight ?? defaults.card.accentGradientHeight
    : sectionStyle?.accentGradientHeight ?? defaults.card.accentGradientHeight;
  const accentGradientDensity = siblingCard
    ? siblingCard.accentGradientDensity ?? defaults.card.accentGradientDensity
    : sectionStyle?.accentGradientDensity ?? defaults.card.accentGradientDensity;

  const presets = draft.presets || null;
  const widthUnits = clamp(Math.round(Number(presets?.widthUnits ?? draft.widthUnits) || 1), 1, 4);
  const imageDefaults = draft.source === CARD_SOURCES.VIA_REGALO
    ? computeImageDefaults(shopItem?.typeLabel, widthUnits, shopItem?.typeCategory, shopItem?.isCarOffer === true)
    : { framing: "cover", overflowTop: false };
  if (presets?.framing) imageDefaults.framing = presets.framing;

  const offerId = isFreeCardDraft(draft) ? freeCardOfferId(cardId) : draft.offerId;

  return {
    cardId,
    source: draft.source,
    offerId,
    snapshot: draft.snapshot,
    media: draft.media,
    pricing: draft.pricing,
    badges: draft.badges,
    layoutId,
    layoutName,
    style: {
      ...defaults,
      card: {
        ...defaults.card,
        styleGroupKey: layoutId || layoutName || `offer:${offerId}`,
        widthUnits,
        ignoreGaps: presets?.ignoreGaps === true,
        gradientColors,
        useGlobalGradient: true,
        useGlobalAccent: true,
        accentColor,
        accentHidden,
        accentGradientHeight,
        accentGradientDensity,
      },
      image: { ...defaults.image, ...imageDefaults },
    },
    discount: {
      ...createDefaultCollageItemDiscount(),
      useGlobalDiscount: draft.useGlobalDiscount !== false,
    },
  };
}

function runTransaction(history, work) {
  history?.beginTransaction?.();
  try {
    return work();
  } finally {
    history?.endTransaction?.();
  }
}

/**
 * Inserta `drafts` en `insertAt` (o al final si es `null`) dentro del slice.
 * Con `selectFirst`, la selección pasa a la primera tarjeta creada.
 * @returns {string[]} cardIds creados
 */
function insertDrafts(ctx, mode, drafts, resolveInsertAt, { selectFirst = false } = {}) {
  const list = Array.isArray(drafts) ? drafts.filter(Boolean) : [];
  if (list.length === 0) return [];

  const sliceKey = resolveSliceKey(mode);
  const cardIds = list.map(() => createCardId());

  runTransaction(ctx?.history, () => {
    ctx.store.setState((state) => {
      const slice = state?.[sliceKey] || {};
      const rawItems = readSliceItems(state, sliceKey);
      const insertAt = resolveInsertAt(rawItems);
      const existingItems = ensureCardIds(rawItems);
      const shopItems = Array.isArray(state?.shop?.items) ? state.shop.items : [];
      const baseMap = buildSectionBaseStyleMap(shopItems, { fallbackColors: DEFAULT_COLLAGE_GLOBAL_GRADIENT_COLORS });
      const materializeContext = { existingItems, sectionStyles: slice.sectionStyles, baseMap };

      const created = list.map((draft, i) => materializeDraft(draft, cardIds[i], materializeContext));
      const items = insertAt == null || insertAt >= existingItems.length
        ? [...existingItems, ...created]
        : [...existingItems.slice(0, insertAt), ...created, ...existingItems.slice(insertAt)];

      const nextSlice = { ...slice, items };
      if (sliceKey === "cart") {
        nextSlice.totalOverride = null;
      } else if (list[0]?.source === CARD_SOURCES.VIA_REGALO) {
        const firstName = String(list[0]?.layoutName || "").trim();
        if (firstName) nextSlice.sectionName = firstName;
      }
      const next = { ...state, [sliceKey]: nextSlice };
      if (selectFirst) next.ui = { ...(state?.ui || {}), selectedElement: cardSelectionId(cardIds[0]) };
      return next;
    });
  });

  return cardIds;
}

export function appendCards(ctx, mode, drafts, options = {}) {
  return insertDrafts(ctx, mode, drafts, () => null, options);
}

/**
 * RN-1: si `anchorCardId` no existe, se comporta como `appendCards`.
 */
export function insertCardsAfter(ctx, mode, anchorCardId, drafts, options = {}) {
  return insertDrafts(ctx, mode, drafts, (items) => {
    const anchorIndex = findCardIndex(items, anchorCardId);
    return anchorIndex === -1 ? null : anchorIndex + 1;
  }, options);
}

/**
 * RN-2: cada `quantity` genera tarjetas independientes.
 * @param {Array<{ draft: object, quantity?: number }>} entries
 * @param {{ anchorCardId?: string|null, selectFirst?: boolean }} options
 */
export function addBatch(ctx, mode, entries, options = {}) {
  const drafts = [];
  for (const entry of Array.isArray(entries) ? entries : []) {
    if (!entry?.draft) continue;
    const quantity = clampQuantity(entry.quantity ?? 1);
    for (let i = 0; i < quantity; i += 1) drafts.push(entry.draft);
  }
  const insertOptions = { selectFirst: Boolean(options.selectFirst) };
  return options.anchorCardId
    ? insertCardsAfter(ctx, mode, options.anchorCardId, drafts, insertOptions)
    : appendCards(ctx, mode, drafts, insertOptions);
}

function cloneForDuplicate(rawItem, cardId) {
  const source = rawItem && typeof rawItem === "object" ? rawItem : { offerId: String(rawItem || "") };
  const copy = { ...source, cardId };
  if (typeof source.offerId === "string" && source.offerId.startsWith("vc:free:")) {
    copy.offerId = freeCardOfferId(cardId);
  }
  return copy;
}

/**
 * Inserta cada copia justo después de su original y selecciona la primera copia.
 * @returns {string[]} cardIds de las copias
 */
export function duplicateCards(ctx, mode, cardIds) {
  const requested = Array.isArray(cardIds) ? cardIds.map(String).filter(Boolean) : [];
  if (requested.length === 0) return [];

  const sliceKey = resolveSliceKey(mode);
  const copies = new Map(requested.map((id) => [id, createCardId()]));
  const created = [];

  runTransaction(ctx?.history, () => {
    ctx.store.setState((state) => {
      const slice = state?.[sliceKey] || {};
      const rawItems = readSliceItems(state, sliceKey);
      const copyIdByIndex = new Map();
      for (const [originalId, copyId] of copies) {
        const index = findCardIndex(rawItems, originalId);
        if (index !== -1) copyIdByIndex.set(index, copyId);
      }
      const existingItems = ensureCardIds(rawItems);
      const items = [];
      for (let index = 0; index < existingItems.length; index += 1) {
        const rawItem = existingItems[index];
        items.push(rawItem);
        const copyId = copyIdByIndex.get(index);
        if (copyId) {
          items.push(cloneForDuplicate(rawItem, copyId));
          created.push(copyId);
        }
      }
      if (created.length === 0) return state;

      const nextSlice = { ...slice, items };
      if (sliceKey === "cart") nextSlice.totalOverride = null;
      return {
        ...state,
        [sliceKey]: nextSlice,
        ui: { ...(state?.ui || {}), selectedElement: cardSelectionId(created[0]) },
      };
    });
  });

  return created;
}

/**
 * Recoloca la tarjeta `cardId` en `toIndex` (se acota al rango del slice) y
 * mantiene la selección sobre esa misma tarjeta conservando `kind`. Al
 * identificar la tarjeta por `cardId` la operación es idempotente frente a
 * ids legados derivados del índice: se asignan ids estables antes de mover.
 * @returns {boolean} `true` si el slice cambió
 */
export function moveCard(ctx, mode, cardId, toIndex, { kind = "card" } = {}) {
  const id = String(cardId || "").trim();
  if (!id || !Number.isFinite(Number(toIndex))) return false;

  const sliceKey = resolveSliceKey(mode);
  let moved = false;

  runTransaction(ctx?.history, () => {
    ctx.store.setState((state) => {
      const slice = state?.[sliceKey] || {};
      const rawItems = readSliceItems(state, sliceKey);
      const from = findCardIndex(rawItems, id);
      if (from === -1) return state;
      const existingItems = ensureCardIds(rawItems);
      const to = clamp(Math.floor(Number(toIndex)), 0, existingItems.length - 1);
      if (from === to) return state;

      const items = existingItems.slice();
      const [card] = items.splice(from, 1);
      items.splice(to, 0, card);
      moved = true;
      return {
        ...state,
        [sliceKey]: { ...slice, items },
        ui: { ...(state?.ui || {}), selectedElement: cardSelectionId(card.cardId, kind) },
      };
    });
  });

  return moved;
}

/**
 * Compatibilidad con el flujo legado por `offerId` (picker lateral).
 */
export function appendOfferIds(ctx, mode, offerIds, options = {}) {
  const ids = Array.isArray(offerIds) ? offerIds.map((id) => String(id || "")).filter(Boolean) : [];
  if (ids.length === 0) return [];
  const shopItems = Array.isArray(ctx?.store?.getState()?.shop?.items) ? ctx.store.getState().shop.items : [];
  const byOfferId = new Map(shopItems.map((item) => [String(item?.offerId || ""), item]));
  const drafts = ids.map((offerId) => draftFromShopItem(byOfferId.get(offerId) || { offerId }, options));
  return appendCards(ctx, mode, drafts);
}

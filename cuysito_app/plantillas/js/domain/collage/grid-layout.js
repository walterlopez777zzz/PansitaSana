import {
  COLLAGE_BASE_CARD_H as CARD_H,
  COLLAGE_BASE_CARD_W as CARD_W,
  COLLAGE_COLUMNS as COLS,
} from "../../constants/defaults.js";
import { clamp } from "../../utils/number-utils.js";
import { normalizeCollageItem, normalizeCollageItemStyle } from "./collage-item.js";
import {
  resolveCardGeometry,
  normalizeWidthUnits,
  SUBSECTION_EXTRA_GAP_RATIO,
} from "./grid-card-geometry.js";
import { isCoverMedia } from "../cards/card-media.js";

/**
 * Flujo de rejilla compartido por preview, export, hit-testing,
 * virtualización y la tarjeta fantasma.
 */

const RECIPIENT_FONT_RATIO = 0.78;
const RECIPIENT_PADDING_RATIO = 0.55;
const MIN_CARD_SCALE = 0.5;
const MAX_CARD_SCALE = 2;

export const RECIPIENT_DIVIDER_H = 22;

export function recipientHeaderHeight(titleFontSize, padding) {
  return Math.round(titleFontSize * RECIPIENT_FONT_RATIO) + Math.round(padding * RECIPIENT_PADDING_RATIO);
}

const layoutGroupKey = (normalized) => String(normalized?.layoutId || "").trim().toLowerCase()
  || String(normalized?.layoutName || "").trim().toLowerCase();

/**
 * @typedef {Object} GridSlot
 * @property {number} x
 * @property {number} y
 * @property {number} w
 * @property {number} h
 * @property {boolean} newRow  `true` si el hueco inaugura una fila nueva
 */

/**
 * @typedef {Object} GridFlowOptions
 * @property {number} padding
 * @property {number} gap
 * @property {number} columns
 * @property {number} cardW
 * @property {number} [cardH]
 * @property {"store"|"legacy"} [geometry]
 * @property {number} [headerHeight]
 * @property {Array<{ startIndex: number }>|null} [recipients]
 * @property {number} [recipientHeaderH]
 * @property {number} [dividerH]
 * @property {boolean} [subsectionBreaks]
 */

/**
 * @param {ReadonlyArray<unknown>} items
 * @param {GridFlowOptions} opts
 */
export const walkGridFlow = (items, opts) => {
  const {
    padding,
    gap,
    columns,
    cardW,
    cardH = 0,
    geometry = "legacy",
    headerHeight = 0,
    recipients = null,
    recipientHeaderH = 0,
    dividerH = 0,
    subsectionBreaks = false,
  } = opts;

  const recipientAtIndex = new Map();
  if (Array.isArray(recipients)) {
    for (let ri = 0; ri < recipients.length; ri += 1) {
      recipientAtIndex.set(recipients[ri].startIndex, ri);
    }
  }

  const storeGeometry = geometry === "store";
  const subsectionExtraGap = Math.round(SUBSECTION_EXTRA_GAP_RATIO * cardW);
  const positions = [];
  const bands = [];
  const recipientHeaders = [];

  let colUsed = 0;
  let rowTop = padding + headerHeight;
  let rowMaxTileH = 0;
  let rowIndices = [];
  let rowCount = 1;
  let bottomY = rowTop;
  let prevGroup = "";

  const flushRow = () => {
    if (rowIndices.length > 0) {
      bands.push({ topY: rowTop, bottomY: rowTop + rowMaxTileH, indices: rowIndices });
      bottomY = rowTop + rowMaxTileH;
      rowTop += rowMaxTileH + gap;
      rowCount += 1;
    }
    colUsed = 0;
    rowMaxTileH = 0;
    rowIndices = [];
  };

  const geometryFor = (widthUnits, media) => {
    if (storeGeometry || isCoverMedia(media)) {
      return resolveCardGeometry(widthUnits, { cardW, gap, media });
    }
    return { tileH: cardH, headroom: 0, visibleH: cardH };
  };

  for (let i = 0; i < items.length; i += 1) {
    const item = normalizeCollageItem(items[i]);
    if (!item) {
      positions.push(null);
      continue;
    }

    if (recipientAtIndex.has(i)) {
      const ri = recipientAtIndex.get(i);
      if (colUsed > 0) flushRow();
      if (ri > 0) rowTop += dividerH;
      recipientHeaders.push({ recipientIndex: ri, y: rowTop });
      rowTop += recipientHeaderH;
    }

    if (subsectionBreaks) {
      const group = layoutGroupKey(item);
      if (group) {
        if (prevGroup && group !== prevGroup) {
          if (colUsed > 0) flushRow();
          rowTop += subsectionExtraGap;
        }
        prevGroup = group;
      }
    }

    const style = normalizeCollageItemStyle(item.style);
    const widthUnits = normalizeWidthUnits(style?.card?.widthUnits ?? 1, columns);
    if (widthUnits > columns - colUsed) flushRow();

    const scale = clamp(Number(style?.card?.scale ?? 1), MIN_CARD_SCALE, MAX_CARD_SCALE);
    const ignoreGaps = style?.card?.ignoreGaps === true;
    const geo = geometryFor(widthUnits, item.media);
    const cardWidth = cardW * widthUnits + (ignoreGaps ? 0 : gap * (widthUnits - 1));
    const x = padding + colUsed * (cardW + gap) + Number(style?.card?.offsetX || 0);
    const y = rowTop + geo.headroom * scale + Number(style?.card?.offsetY || 0);

    rowMaxTileH = Math.max(rowMaxTileH, geo.tileH * scale);
    colUsed += widthUnits;
    positions.push({ x, y, w: cardWidth, h: geo.visibleH, st: style, normalized: item });
    rowIndices.push(i);
  }

  // Siguiente hueco libre (para la tarjeta fantasma), calculado antes de
  // cerrar la última fila para reutilizar el espacio restante si existe.
  const ghostGeo = geometryFor(1, null);
  const nextSlot = colUsed > 0 && colUsed < columns
    ? {
      x: padding + colUsed * (cardW + gap),
      y: rowTop + ghostGeo.headroom,
      w: cardW,
      h: ghostGeo.visibleH,
      newRow: false,
    }
    : {
      x: padding,
      y: (rowIndices.length > 0 ? rowTop + rowMaxTileH + gap : rowTop) + ghostGeo.headroom,
      w: cardW,
      h: ghostGeo.visibleH,
      newRow: true,
    };

  if (rowIndices.length > 0) {
    bands.push({ topY: rowTop, bottomY: rowTop + rowMaxTileH, indices: rowIndices });
    bottomY = rowTop + rowMaxTileH;
  }

  return { positions, bands, recipientHeaders, bottomY, rowCount, nextSlot };
};

const flowOptionsFromLayout = (layout) => ({
  padding: layout.padding,
  gap: layout.gap,
  columns: layout.columns,
  cardW: layout.cardW,
  cardH: layout.cardH,
  geometry: layout.geometry,
  headerHeight: layout.headerHeight || 0,
  recipients: layout.recipients,
  recipientHeaderH: layout.recipientHeaderH,
  dividerH: layout.dividerH,
  subsectionBreaks: layout.subsectionBreaks === true,
});

export const computeLayoutRaw = (state) => {
  const col = state?.collage || {};
  const items = Array.isArray(col.items) ? col.items : [];
  const count = items.length;
  const sectionName = String(col.sectionName || "").trim();
  const titleStyle = col.titleStyle || {};
  const titleFontSize = clamp(Math.round(Number(titleStyle.fontSize ?? 90)), 10, 300);
  const padding = clamp(Number(col.padding ?? 90), 0, 260);
  const gap = clamp(Number(col.gap ?? 30), 0, 240);
  const columns = COLS;
  const cardW = CARD_W;
  const cardH = CARD_H;
  const width = Math.round(2 * padding + columns * cardW + gap * (columns - 1));
  const titleHeight = sectionName ? titleFontSize + padding : 0;
  const geometry = col.geometry === "store" ? "store" : "legacy";
  const subsectionBreaks = geometry === "store" && !Array.isArray(col.recipients);
  const recipients = Array.isArray(col.recipients) ? col.recipients : [];
  const recHeaderH = recipientHeaderHeight(titleFontSize, padding);
  const dividerH = RECIPIENT_DIVIDER_H;

  const flow = walkGridFlow(items, {
    padding,
    gap,
    columns,
    cardW,
    cardH,
    geometry,
    headerHeight: titleHeight,
    recipients,
    recipientHeaderH: recHeaderH,
    dividerH,
    subsectionBreaks,
  });

  const rawTotalH = count > 0 ? flow.bottomY + padding : 2 * padding + titleHeight + cardH;
  const minH = Math.max(320, Math.round(2 * padding + cardH));
  const height = Math.max(minH, Math.round(rawTotalH));

  return Object.freeze({
    width,
    height,
    padding,
    gap,
    columns,
    cardW,
    cardH,
    rows: flow.rowCount,
    count,
    headerHeight: titleHeight,
    sectionName,
    titleFontSize,
    titleStyle,
    recipients,
    recipientHeaderH: recHeaderH,
    dividerH,
    subsectionBreaks,
    geometry,
    items,
    nextSlot: flow.nextSlot,
  });
};

/**
 * Hueco donde aparecería la siguiente tarjeta (RN-12). Devuelve `null` si el
 * layout no es de rejilla.
 * @returns {GridSlot|null}
 */
export function computeGhostSlot(layout) {
  if (!layout || typeof layout !== "object") return null;
  if (layout.nextSlot) return layout.nextSlot;
  if (!Array.isArray(layout.items)) return null;
  return walkGridFlow(layout.items, flowOptionsFromLayout(layout)).nextSlot;
}

export const createMemoizedComputeLayout = () => {
  let prevCollage = null;
  let cachedLayout = null;
  return (state) => {
    const col = state?.collage;
    if (col === prevCollage && cachedLayout) return cachedLayout;
    prevCollage = col;
    cachedLayout = computeLayoutRaw(state);
    return cachedLayout;
  };
};

export const computeGridRows = (items) => {
  const rows = [[]];
  let colUsed = 0;
  for (let i = 0; i < items.length; i += 1) {
    const item = normalizeCollageItem(items[i]);
    const units = normalizeWidthUnits(item?.style?.card?.widthUnits ?? 1, COLS);
    if (units > COLS - colUsed) {
      rows.push([]);
      colUsed = 0;
    }
    rows[rows.length - 1].push({ index: i, units });
    colUsed += units;
  }
  return rows;
};

export const createLayoutPositionCache = () => {
  let cachedItems = null;
  let cachedLayout = null;
  let positions = [];
  let rowBands = [];

  const compute = (items, layout) => {
    cachedItems = items;
    cachedLayout = layout;
    const flow = walkGridFlow(items, flowOptionsFromLayout(layout));
    positions = flow.positions;
    rowBands = flow.bands;
  };

  const cache = (items, layout) => {
    if (items !== cachedItems || layout !== cachedLayout) compute(items, layout);
    return positions;
  };

  cache.getVisibleIndices = (topY, bottomY) => {
    if (!rowBands.length) return null;
    let lo = 0;
    let hi = rowBands.length - 1;
    while (lo < hi) {
      const mid = (lo + hi) >>> 1;
      if (rowBands[mid].bottomY < topY) lo = mid + 1;
      else hi = mid;
    }
    const result = [];
    for (let i = lo; i < rowBands.length && rowBands[i].topY <= bottomY; i += 1) {
      for (const idx of rowBands[i].indices) result.push(idx);
    }
    return result;
  };

  return cache;
};

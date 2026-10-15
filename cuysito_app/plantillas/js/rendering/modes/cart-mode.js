/**
 * cart-mode.js
 *
 * Renders Cart Mode by delegating card drawing to collage-mode,
 * then overlaying:
 *   - "Para: USERNAME" recipient group headers (with the Para: label bolded
 *     and the username in a thinner weight, same font family)
 *   - Subtle horizontal divider lines between groups
 *   - "TOTAL:" header at the top
 *   - Branding footer at the bottom
 *
 * Selection IDs:
 *   - "collage:cartTotal"           → the TOTAL: header
 *   - "collage:branding"            → the branding bar
 *   - "collage:recipient:N"         → the N-th "Para:" header (0-indexed)
 */

import { createCollageModeRenderer } from "./collage-mode.js";
import { computeCartTotal, formatCartTotal } from "../../domain/pricing/cart-totals.js";
import { internBurbankBig } from "../painters/font-cache.js";
import { createBrandingFooterPainter } from "../painters/branding-renderer.js";
import { RECIPIENT_DIVIDER_H, walkGridFlow } from "../../domain/collage/grid-layout.js";

// ─── BANANO logo path (same as before) ───────────────────────────────────────

const LOGO_PATH = new Path2D(
  "M26.9 14.1C29.1 14 30.7 13.4 31.7 15.6C33.5 19.3 35.1 23.1 36.7 26.9C38.3 26.9 42.2 26.3 43.9 26L57.3 24.2L103.8 17.7C109.8 16.9 118.1 15.4 124.1 14.9C123.7 25.4 122.3 37.6 121.6 48.3C121.2 54.3 120.7 64.1 119.9 69.8C117.6 70.2 113.3 70.4 110.9 70.5L96.1 71.4L71.7 73C67 73.3 61.7 73.7 57 73.8C57.1 78 57.2 84.6 56.9 88.7C60.3 88.1 67 87.5 70.7 87.1L89.5 84.9L106.8 82.9C108.5 82.7 112.4 82.2 113.9 82.2C114.8 83.5 115 89 115.4 91.1C111.3 91.2 110.2 91.7 105.5 91.3C106.3 91.7 107.4 92.2 108 92.7C122.3 103 106.3 120.9 94.4 110.1C91.1 107.1 90.3 101.5 92.3 97.5C93.7 94.7 94.9 94.3 96.9 92.4C96 92 71.8 93.6 68.2 93.6C68.8 94.6 71.9 95.7 73.3 98.5C74.4 101 75 103 74.1 105.8C73.3 108.4 71.6 110.5 69.2 111.8C61.3 115.9 52.7 108.5 55.4 100.1C56.4 96.8 58.4 95.4 61.3 94C57.5 94.6 53.2 94.5 49.2 95C49.5 89 50.9 79.5 51.7 73.3C50 69.4 48.3 66.2 46.5 62.4C42.2 53.6 37.9 44.8 33.4 36.1C31.4 32.1 28 25.7 26.5 21.8C24.8 22.4 21.2 23.2 19.2 23.7C14.5 24.8 9.9 26 5.2 27.2C4.8 25.3 3.8 17.7 4.3 16C5 15.3 23.8 14.4 26.9 14.1ZM68.8 29.7C69.5 33 71.7 43.4 71.7 46.4C73.9 45.8 87 44.4 89.8 44.1C89.1 40.3 88.7 31.9 88.4 27.6C87.8 27.4 88 27.4 87.3 27.6C81.6 28.5 74.8 28.8 68.8 29.7ZM42.3 32.6C43.7 35.5 47.8 45.6 48.5 48.7C49.5 49.4 59.9 47.8 61.8 47.5C63.1 47.3 64.4 47.1 65.8 46.8C64.7 44.6 61.3 30.7 60.7 30.3C55.1 31.6 48 31.4 42.3 32.6ZM95.7 26.6C96.2 30.5 95.8 38.7 95.7 43C95.8 43.1 95.9 43.3 96 43.3L96.2 43.3C99.7 42.6 110 41.4 113.7 41.1C113.3 38.4 113.1 27.7 113.4 24.9L112.8 24.9C107.8 25.4 100.5 26.5 95.7 26.6ZM66.4 51.3C64.1 51.8 52.8 52.9 50.1 53.1C51.1 55 55.5 65.9 55.9 68C56.4 68.1 69.7 66.9 71.3 66.8C70.6 64.7 67.2 51.8 66.6 51.4L66.4 51.3ZM75.5 66.2C76.4 66.3 77.5 66 78.5 66C82.8 65.7 87 65 91.3 64.9C90.4 60.3 90.7 53.6 90 49.5L89.6 49.4C84 50.1 78.4 50.4 72.8 51.1C73.3 53.2 75.6 64.3 75.5 66.2ZM96.3 64.2C101.6 63.4 108.3 63.3 113.6 62.6C113.6 58.3 113.4 54.1 113.3 49.8C113.3 49.2 113.5 48 113.4 47.5L113 47.4C107.3 47.8 101.4 48.6 95.6 48.9C95.8 51.1 95.3 63.8 95.7 64.4C95.8 64.4 96.2 64.3 96.3 64.2Z"
);

// ─── Default style for recipient headers ─────────────────────────────────────

const DEFAULT_RECIPIENT_OPACITY = 0.78;
const DEFAULT_PARA_COLOR = "#FF5252";           // PARA: label — same red as TOTAL: value
const DEFAULT_USERNAME_COLOR = "#CBD5E1";

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Adapts state so that `state.collage` maps to cart data (including recipients).
 * The collage renderer reads `state.collage.*`, so we remap here.
 */
function adaptStateForCollage(state) {
  if (!state) return state;
  return {
    ...state,
    collage: {
      ...(state.cart || state.collage || {}),
      // Carry recipients through so grid-layout can account for header height
      recipients: Array.isArray(state.cart?.recipients) ? state.cart.recipients : [],
    },
  };
}

/**
 * Computes Y positions for each recipient header in the canvas by running
 * the shared grid flow walker (single source of truth in grid-layout.js).
 *
 * @param {object[]} recipients
 * @param {object[]} items
 * @param {object} layout
 * @returns {Array<{ recipientIndex: number, y: number }>}
 */
function computeRecipientHeaderPositions(recipients, items, layout) {
  if (!recipients.length) return [];

  const flow = walkGridFlow(items, {
    padding: layout.padding,
    gap: layout.gap,
    columns: layout.columns,
    cardW: layout.cardW,
    cardH: layout.cardH,
    geometry: layout.geometry,
    headerHeight: layout.headerHeight || 0,
    recipients,
    recipientHeaderH:
      typeof layout.recipientHeaderH === "number" ? layout.recipientHeaderH : 0,
    dividerH:
      typeof layout.dividerH === "number" ? layout.dividerH : RECIPIENT_DIVIDER_H,
  });

  return flow.recipientHeaders;
}

// ─── Recipient header renderer ────────────────────────────────────────────────

/**
 * Draws a single "Para: USERNAME" header on the canvas.
 *
 * @param {CanvasRenderingContext2D} ctx
 * @param {object} layout
 * @param {object} recipient   - { id, username, config? }
 * @param {number} baseY       - Top of the header slot on canvas
 * @returns {{ x: number, y: number, w: number, h: number }} hitbox
 */
function drawRecipientHeader(ctx, layout, recipient, baseY) {
  const { padding, recipientHeaderH, titleFontSize } = layout;
  const recH = typeof recipientHeaderH === "number" ? recipientHeaderH : 60;
  const titleFs = titleFontSize || 90;

  // Font sizes: 78% of TOTAL: title
  const labelFs = Math.round(titleFs * 0.78);
  // Username is same font family but visibly thinner — use weight 400 vs 900
  const usernameFs = labelFs;

  const config = recipient.config || {};
  const linked = config.syncMode !== "independent";
  const masterOpacity = config.masterOpacity ?? DEFAULT_RECIPIENT_OPACITY;
  const labelOpacity = linked ? masterOpacity : (config.labelOpacity ?? DEFAULT_RECIPIENT_OPACITY);
  const usernameOpacity = linked ? masterOpacity : (config.usernameOpacity ?? DEFAULT_RECIPIENT_OPACITY);
  const labelColor = config.labelColor || DEFAULT_PARA_COLOR;
  const usernameColor = config.usernameColor || DEFAULT_USERNAME_COLOR;

  const offsetX = Number(config.offsetX || 0);
  const offsetY = Number(config.offsetY || 0);

  // Center of the header slot
  const slotCenterY = baseY + recH / 2;
  const textBaselineY = slotCenterY + Math.round(labelFs * 0.36); // approximate alphabetic baseline

  const LABEL_TEXT = "PARA:";
  const username = String(recipient.username || "").trim() || "—";
  const GAP = Math.round(labelFs * 0.22); // gap between "PARA:" and username

  // Measure widths (for hitbox calculation)
  ctx.save();
  ctx.font = internBurbankBig(labelFs, 900);
  const labelMetrics = ctx.measureText(LABEL_TEXT);
  const labelW = labelMetrics.width;
  ctx.font = `400 ${usernameFs}px "Burbank Big", system-ui, sans-serif`;
  const usernameMetrics = ctx.measureText(username);
  const usernameW = usernameMetrics.width;
  ctx.restore();

  const totalW = labelW + GAP + usernameW;
  // Left-aligned: start at canvas padding (same x as leftmost card edge)
  const textStartX = Math.round(padding) + offsetX;
  const drawY = Math.round(textBaselineY) + offsetY;

  ctx.save();
  try {
    ctx.textBaseline = "alphabetic";

    // Draw "Para:" (bold)
    ctx.save();
    try {
      ctx.globalAlpha = ctx.globalAlpha * labelOpacity;
      ctx.font = internBurbankBig(labelFs, 900);
      ctx.fillStyle = labelColor;
      ctx.textAlign = "left";
      ctx.fillText(LABEL_TEXT, textStartX, drawY);
    } finally {
      ctx.restore();
    }

    // Draw username (thin)
    ctx.save();
    try {
      ctx.globalAlpha = ctx.globalAlpha * usernameOpacity;
      ctx.font = `400 ${usernameFs}px "Burbank Big", system-ui, sans-serif`;
      ctx.fillStyle = usernameColor;
      ctx.textAlign = "left";
      ctx.fillText(username, textStartX + labelW + GAP, drawY);
    } finally {
      ctx.restore();
    }
  } finally {
    ctx.restore();
  }

  // Return hitbox for inspector selection (matches left-aligned text position)
  return {
    x: Math.round(padding) + offsetX,
    y: Math.round(baseY) + offsetY,
    w: Math.round(totalW + 40),
    h: Math.round(recH),
  };
}

/**
 * Draws a subtle divider line between two recipient groups.
 *
 * @param {CanvasRenderingContext2D} ctx
 * @param {object} layout
 * @param {number} y - Y position of the divider center line
 */
function drawRecipientDivider(ctx, layout, y) {
  const { padding, width } = layout;
  const lineY = Math.round(y + RECIPIENT_DIVIDER_H / 2);
  const lineH = 5; // px, visible line thickness (not the slot height)

  ctx.save();
  try {
    ctx.globalAlpha = ctx.globalAlpha * 0.28; // slightly more visible
    ctx.fillStyle = "#CBD5E1"; // light grayish
    ctx.fillRect(padding, lineY - Math.floor(lineH / 2), width - padding * 2, lineH);
  } finally {
    ctx.restore();
  }
}

// ─── TOTAL: header renderer ───────────────────────────────────────────────────

/**
 * Draws the "TOTAL: S/ X.XX" header.
 * Returns the hitbox or null.
 */
function drawCartTotalHeader(ctx, layout, totalText, cartHeaderConfig) {
  const { padding, titleFontSize, width } = layout;
  const cfg = cartHeaderConfig || {};
  const syncMode = cfg.syncMode || "partial";
  const DEFAULT_OPACITY = 0.78;

  let iconOpacity, labelOpacity, valueOpacity;
  if (syncMode === "linked") {
    const op = cfg.masterOpacity ?? DEFAULT_OPACITY;
    iconOpacity = op;
    labelOpacity = op;
    valueOpacity = op;
  } else if (syncMode === "independent") {
    iconOpacity = cfg.iconOpacity ?? DEFAULT_OPACITY;
    labelOpacity = cfg.labelOpacity ?? DEFAULT_OPACITY;
    valueOpacity = cfg.indepValueOpacity ?? 1;
  } else {
    // partial
    const op = cfg.iconLabelOpacity ?? DEFAULT_OPACITY;
    iconOpacity = op;
    labelOpacity = op;
    valueOpacity = cfg.valueOpacity ?? 1;
  }

  const iconColor = cfg.iconColor || "#CBD5E1";
  const labelColor = cfg.labelColor || "#CBD5E1";
  const valueColor = cfg.valueColor || "#FF5252";

  const fontSize = titleFontSize || 90;
  const font = internBurbankBig(fontSize);
  const centerX = width / 2;
  const centerY = padding + Math.round(fontSize / 2);

  const LABEL = "TOTAL:";
  const VALUE = String(totalText || "S/ 0");

  ctx.save();
  let hitbox = null;
  try {
    ctx.textBaseline = "alphabetic";
    ctx.font = font;

    const lm = ctx.measureText(LABEL);
    const labelW = lm.width;
    const labelAscent = lm.actualBoundingBoxAscent || fontSize * 0.72;
    const labelDescent = lm.actualBoundingBoxDescent || 0;

    const vm = ctx.measureText(VALUE);
    const valueW = vm.width;
    const valueAscent = vm.actualBoundingBoxAscent || fontSize * 0.72;
    const valueDescent = vm.actualBoundingBoxDescent || 0;

    const iconSize = Math.round(fontSize);
    const iconGap = Math.round(fontSize * 0.3);
    const labelValueGap = Math.round(fontSize * 0.2);
    const totalRowW = iconSize + iconGap + labelW + labelValueGap + valueW;
    const rowStartX = Math.round(centerX - totalRowW / 2);

    const labelBaselineY = centerY + Math.round((labelAscent - labelDescent) / 2);
    const valueBaselineY = centerY + Math.round((valueAscent - valueDescent) / 2);
    const rowH = Math.max(iconSize, labelAscent + labelDescent, valueAscent + valueDescent);
    hitbox = {
      x: rowStartX,
      y: centerY - Math.round(rowH / 2),
      w: Math.round(totalRowW),
      h: Math.round(rowH),
    };

    // Icon
    const iconOffsetY = Math.round(fontSize * 0.022);
    ctx.save();
    try {
      ctx.globalAlpha = ctx.globalAlpha * iconOpacity;
      ctx.translate(rowStartX, centerY - Math.round(iconSize / 2) + iconOffsetY);
      const iconScale = iconSize / 128;
      ctx.scale(iconScale, iconScale);
      ctx.fillStyle = iconColor;
      ctx.fill(LOGO_PATH);
    } finally {
      ctx.restore();
    }

    // "TOTAL:" label
    ctx.save();
    try {
      ctx.globalAlpha = ctx.globalAlpha * labelOpacity;
      ctx.font = font;
      ctx.fillStyle = labelColor;
      ctx.textAlign = "left";
      ctx.fillText(LABEL, rowStartX + iconSize + iconGap, labelBaselineY);
    } finally {
      ctx.restore();
    }

    // Value
    ctx.save();
    try {
      ctx.globalAlpha = ctx.globalAlpha * valueOpacity;
      ctx.font = font;
      ctx.fillStyle = valueColor;
      ctx.textAlign = "left";
      ctx.fillText(VALUE, rowStartX + iconSize + iconGap + labelW + labelValueGap, valueBaselineY);
    } finally {
      ctx.restore();
    }
  } finally {
    ctx.restore();
  }

  return hitbox;
}

// ─── Factory ─────────────────────────────────────────────────────────────────

export function createCartModeRenderer(options) {
  const collageRenderer = createCollageModeRenderer(options);
  const { drawBrandingFooter } = createBrandingFooterPainter();

  return Object.freeze({
    // ── computeLayout ──────────────────────────────────────────────────────
    computeLayout: (state) => {
      const adapted = adaptStateForCollage(state);
      const layout = collageRenderer.computeLayout(adapted);

      // Reserve extra space at top for TOTAL: header (same as original)
      const totalHeaderH = (layout.titleFontSize || 90) + layout.padding;
      if (layout.headerHeight >= totalHeaderH) return layout;
      const diff = totalHeaderH - layout.headerHeight;
      // El flujo de tarjetas arranca en `padding + headerHeight`, así que el
      // hueco fantasma también baja `diff`.
      const nextSlot = layout.nextSlot
        ? Object.freeze({ ...layout.nextSlot, y: layout.nextSlot.y + diff })
        : layout.nextSlot;
      return Object.freeze({ ...layout, headerHeight: totalHeaderH, height: layout.height + diff, nextSlot });
    },

    parseSelectionIndex: collageRenderer.parseSelectionIndex,

    // ── drawScene ──────────────────────────────────────────────────────────
    drawScene: async (ctx, state, opts = {}) => {
      const adapted = adaptStateForCollage(state);
      const layout = opts.layout;

      // Delegate card drawing to collage renderer (suppress title and branding)
      const strippedState = {
        ...adapted,
        collage: { ...adapted.collage, sectionName: "", branding: undefined },
      };
      const result = await collageRenderer.drawScene(ctx, strippedState, opts);

      const isClipping =
        typeof opts.clipTopY === "number" && typeof opts.clipBottomY === "number";

      const cart = state?.cart || {};
      const recipients = Array.isArray(cart.recipients) ? cart.recipients : [];
      const items = Array.isArray(cart.items) ? cart.items : [];

      // ── Recipient headers & dividers ──────────────────────────────────
      if (recipients.length > 0 && layout) {
        const headerPositions = computeRecipientHeaderPositions(recipients, items, layout);

        for (const { recipientIndex: ri, y } of headerPositions) {
          const recipient = recipients[ri];

          // Skip if outside clip region
          if (isClipping) {
            const slotBottom = y + (layout.recipientHeaderH || 60);
            if (slotBottom < opts.clipTopY || y > opts.clipBottomY) continue;
          }

          // Divider before this group (except first)
          if (ri > 0) {
            drawRecipientDivider(ctx, layout, y - (layout.dividerH || RECIPIENT_DIVIDER_H));
          }

          // Header text
          const hitbox = drawRecipientHeader(ctx, layout, recipient, y);

          // Register hitbox for inspector selection
          if (result?.hitBoxes) {
            result.hitBoxes[`collage:recipient:${ri}`] = hitbox;
          }
        }
      }

      // ── TOTAL: header ─────────────────────────────────────────────────
      const totalY = layout ? layout.padding + (layout.headerHeight || 0) : 0;
      const isTopVisible = !isClipping || (0 < opts.clipBottomY && totalY > opts.clipTopY);
      if (isTopVisible && layout) {
        const totalText = (() => {
          const override = cart.totalOverride;
          if (override != null && String(override).trim() !== "") return String(override).trim();
          return formatCartTotal(
            computeCartTotal({
              items,
              shopItems: Array.isArray(state?.shop?.items) ? state.shop.items : [],
              conversionRate: state?.template?.conversionRate,
              globalDiscount: state?.template?.collageDiscount ?? state?.template?.discount,
            })
          );
        })();

        const totalHitbox = drawCartTotalHeader(ctx, layout, totalText, cart.cartHeaderConfig);
        if (result?.hitBoxes && totalHitbox) {
          result.hitBoxes["collage:cartTotal"] = totalHitbox;
        }
      }

      // ── Branding footer ────────────────────────────────────────────────
      if (layout) {
        const footerY = layout.height - layout.padding;
        const isFooterVisible =
          !isClipping || (footerY < opts.clipBottomY && layout.height > opts.clipTopY);
        if (isFooterVisible) {
          const brandingHitbox = drawBrandingFooter(ctx, layout, cart.branding);
          if (result?.hitBoxes && brandingHitbox) {
            result.hitBoxes["collage:branding"] = brandingHitbox;
          }
        }
      }

      return result;
    },

    // ── Pass-through from collage renderer ─────────────────────────────
    drawItemAtIndex: async (ctx, state, opts = {}) => {
      return collageRenderer.drawItemAtIndex(ctx, adaptStateForCollage(state), opts);
    },
    drawSelectionOutline: collageRenderer.drawSelectionOutline,
    normalizeCollageItemStyle: collageRenderer.normalizeCollageItemStyle,
    resolveCollageGlobalDesign: collageRenderer.resolveCollageGlobalDesign,
    resolveCollageCardGradientColors: collageRenderer.resolveCollageCardGradientColors,
    resolveCollageCardAccentSpec: collageRenderer.resolveCollageCardAccentSpec,
    computeGradientStops: collageRenderer.computeGradientStops,
    drawImageCover: collageRenderer.drawImageCover,
    formatSoles: collageRenderer.formatSoles,
    tokenOk: collageRenderer.tokenOk,
  });
}
/**
 * Card text/pricing layout for collage/cart cards, derived 1:1 from the main
 * shop overlay CSS (public_html/style.css, `.card-info-overlay` rules) and
 * the card template markup (index.php, `#item-card-template`).
 *
 * Reference tile: MOBILE 2-column grid at a 390px viewport — the layout the
 * shop is normally seen at, and where its fixed-px fonts are largest relative
 * to the card. Card box width = (390 × (1 − 2 × 2.5% padding) − 8px gap) / 2
 * ≈ 181.25px. Every ratio below is `shopPx / 181.25`, applied to the collage
 * base card width (COLLAGE_BASE_CARD_W = 500) to get the default values in
 * card units, which are then multiplied by the per-card layout scale at draw
 * time. (The shop's fonts are fixed px across breakpoints, so text-to-card
 * ratio shrinks as cards grow; anchoring to the desktop 327px card made
 * collage text look far smaller than the shop as users actually see it.)
 *
 * Shop anatomy being mirrored (values measured on the live shop):
 *   - font: HeadingNowVariable at "wdth" 668 / "wght" 500, no text-transform
 *     (served in /plantillas as the static instance "Heading Now Card")
 *   - overlay padding 0.5rem (8px) on sides/bottom
 *   - `.card-title`: 16px / 24px line-height, #FFFFFF, 2-line clamp
 *   - footer row `justify-between`, margin-top 0 (mobile):
 *       vbucks block LEFT  → 16px text, 24px icon (fixed px on every
 *       breakpoint → icon:text ratio 24/19 at the desktop reference),
 *       4px icon gap, #FFFFFF
 *       soles block  RIGHT → 16px spans (`.price-container > span` override)
 *   - discounted soles: `.price-container` is a ROW (`gap: 8px`, baseline
 *     aligned): struck original price on the LEFT of the final price, both
 *     at the same font size; final price `text-green-500` (#22C55E);
 *     original price white at 0.93 opacity, struck by a white 3px bar at
 *     0.8 opacity rotated -7°, spanning the text plus 0.25rem (4px)
 *     padding-inline (`.base-price::before`).
 */

import { COLLAGE_BASE_CARD_W } from "../../constants/defaults.js";

/** Shop card box width (px) at the mobile reference layout (390px viewport). */
const SHOP_CARD_W = 181.25;

/** Converts a shop px value to collage base-card units, rounded to 0.1. */
const toCardUnits = (shopPx) =>
    Math.round((shopPx / SHOP_CARD_W) * COLLAGE_BASE_CARD_W * 10) / 10;

/* ── Overlay box ── */
export const CARD_TEXT_PADDING = toCardUnits(8); // 22.1
/**
 * Shop footer `margin-top` is 0 on mobile (4px only ≥640px); the visual
 * breathing room comes from the title's 24px line box, which the canvas
 * renderer already accounts for via the title's em box.
 */
export const CARD_TITLE_PRICING_GAP = 0;

/* ── Item name (`.card-title`) ── */
export const CARD_TITLE_FONT_SIZE = toCardUnits(16); // 44.1
/**
 * Official wide-tile title scale: on fortnite.com, multi-column tiles render
 * the item name one text step above the 1×1 tiles (19px → 24px at lg,
 * 16px → 19px at md), while the vBucks price keeps the 1×1 scale on every
 * span. Applied to the default title size when `card.widthUnits > 1`.
 */
export const CARD_TITLE_WIDE_SCALE = 24 / 19;
export const CARD_TITLE_LINE_HEIGHT = 1.2;
export const CARD_TITLE_COLOR = "#FFFFFF";
export const CARD_TITLE_MAX_LINES = 2;

/* ── V-Bucks price (`.vbucks-block`) ── */
export const CARD_VBUCKS_FONT_SIZE = toCardUnits(16); // 44.1
/** Fixed 24px shop icon next to 19px desktop text → ratio 24/19 to the 16px anchor. */
export const CARD_VBUCKS_ICON_SIZE = toCardUnits(16 * (24 / 19)); // 55.7
export const CARD_VBUCKS_ICON_GAP = toCardUnits(4); // 11
export const CARD_VBUCKS_COLOR = "#FFFFFF";

/* ── Soles prices (`.price-block`) ── */
export const CARD_SOLES_FONT_SIZE = toCardUnits(16); // 44.1
export const CARD_SOLES_COLOR = "#FFFFFF";
/** Tailwind `text-green-500`, used by the discounted final price. */
export const CARD_SOLES_DISCOUNT_COLOR = "#22C55E";
/** `.price-container` row gap between the struck original and final price. */
export const CARD_PRICE_ROW_GAP = toCardUnits(8); // 22.1

/* ── Struck original price (`.base-price`) ── */
export const CARD_STRIKE_TEXT_COLOR = "#FFFFFF";
export const CARD_STRIKE_TEXT_OPACITY = 0.93;
/** `.base-price::before`: white 3px bar at 0.8 opacity rotated -7°. */
export const CARD_STRIKE_BAR_THICKNESS = toCardUnits(3); // 8.3
export const CARD_STRIKE_BAR_OPACITY = 0.8;
export const CARD_STRIKE_BAR_ANGLE_DEG = -7;
/** `padding-inline: 0.25rem` — the bar spans the text plus this per side. */
export const CARD_STRIKE_BAR_PADDING_X = toCardUnits(4); // 11

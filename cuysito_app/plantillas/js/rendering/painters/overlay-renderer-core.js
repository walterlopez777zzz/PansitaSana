import { PriceRenderer } from "./price-renderer.js";
import { rotatedAabbRect, roundedRect } from "./geometry.js";
import {
  drawSingleLineText,
  drawStrikeThrough,
  drawWrappedTextBlock,
  normalizeAlign,
  normalizeVAnchor,
  wrapText,
} from "./text-engine.js";
import { internBurbankSmall, internBurbankBig } from "./font-cache.js";
import { cachedMeasureWidth } from "./text-measure-cache.js";
import { SECONDARY_LABEL_COLOR } from "../../constants/defaults.js";
import { resolveConversionRate } from "../../domain/pricing/conversion-rate.js";
import {
  computeDiscountedPrices,
  normalizeDiscountConfig,
} from "../../domain/pricing/discounts.js";
import { formatSoles } from "../../domain/pricing/money-format.js";
import { fillAccentOverlay } from "./accent-overlay.js";
import { clamp } from "../../utils/number-utils.js";
import { safeHex, hexToRgba } from "../../utils/color-utils.js";

const DEFAULT_GIFT_LABEL = "VÍA REGALO";
const DEFAULT_TEXT_COLOR = "#FFFFFF";
const DEFAULT_DISCOUNT_COLOR = "#55FF55";
const BADGE_BACKGROUND = "rgba(0,0,0,0.40)";
const CLOCK_STROKE_WIDTH = 12;
const EDGE_PADDING = 130;
const TOP_PADDING = 90;
const BOTTOM_PADDING = 90;
const LABEL_FONT_SIZE = 110;
const VBUCKS_FONT_SIZE = 150;
const VBUCKS_ICON_SIZE = 110;
const VBUCKS_GAP = 22;
const SOLES_GAP_Y = 12;
const TYPE_FONT_SIZE = 62;
const NAME_FONT_SIZE = 170;
const BADGE_RADIUS = 16;
const BOX_HORIZONTAL_PADDING = 16;
const BOX_VERTICAL_PADDING = 12;
const DATE_ICON_GAP = 12;
const PRICE_KEYS = new Set(["soles", "solesOffer", "solesList"]);
const BOTTOM_ANCHOR_KEYS = new Set(["name", "type", "rarity"]);
const MAX_WIDTH_DEFAULT = 1;
const LINE_HEIGHT_DEFAULT = 0.92;
const FONT_SCALE_MIN = 0.6;
const FONT_SCALE_MAX = 1.4;
const MAX_WIDTH_MIN = 0.35;
const MAX_WIDTH_MAX = 1;
const LINE_HEIGHT_MIN = 0.6;
const LINE_HEIGHT_MAX = 1.8;
const ROTATION_MIN = -180;
const ROTATION_MAX = 180;

function ensureArray(value) {
  return Array.isArray(value) ? value : [];
}

function isTokenStillValid(token, isTokenValid) {
  return (
    typeof token !== "number" ||
    typeof isTokenValid !== "function" ||
    isTokenValid(token)
  );
}

function sanitizeCanvasSize(size) {
  return Math.max(1, Math.round(Number(size) || 0));
}

function snapshotEquals(left, right) {
  return Boolean(left) && Boolean(right) && (
    left.size === right.size &&
    left.template === right.template &&
    left.ui === right.ui &&
    left.selectionOfferId === right.selectionOfferId
  );
}

function createSnapshot(state, size) {
  return {
    size,
    template: state?.template || {},
    ui: state?.ui || {},
    selectionOfferId: String(state?.selection?.offerId || ""),
  };
}

function getOffsetValue(primaryValue, fallbackValue) {
  return primaryValue ?? fallbackValue;
}

function createOffsets(template) {
  return {
    name: {
      x: Number(template?.nameOffsetX || 0),
      y: Number(template?.nameOffsetY || 0),
    },
    vbucks: {
      x: Number(template?.vbucksOffsetX || 0),
      y: Number(template?.vbucksOffsetY || 0),
    },
    soles: {
      x: Number(template?.solesOffsetX || 0),
      y: Number(template?.solesOffsetY || 0),
    },
    solesOffer: {
      x: Number(getOffsetValue(template?.solesOfferOffsetX, template?.solesOffsetX) || 0),
      y: Number(getOffsetValue(template?.solesOfferOffsetY, template?.solesOffsetY) || 0),
    },
    solesList: {
      x: Number(getOffsetValue(template?.solesListOffsetX, template?.solesOffsetX) || 0),
      y: Number(getOffsetValue(template?.solesListOffsetY, template?.solesOffsetY) || 0),
    },
    type: {
      x: Number(template?.typeOffsetX || 0),
      y: Number(template?.typeOffsetY || 0),
    },
    rarity: {
      x: Number(template?.rarityOffsetX || 0),
      y: Number(template?.rarityOffsetY || 0),
    },
    date: {
      x: Number(template?.dateOffsetX || 0),
      y: Number(template?.dateOffsetY || 0),
    },
    label: {
      x: Number(template?.labelOffsetX || 0),
      y: Number(template?.labelOffsetY || 0),
    },
  };
}

function createTextStyleResolver(template) {
  return (key) => {
    const textStyle = template?.textStyles?.[key] || {};
    const cssColor = textStyle.color || template?.textColor || DEFAULT_TEXT_COLOR;
    const rgbaColor = hexToRgba(cssColor);
    const opacity = clamp(Number(textStyle.opacity ?? template?.textOpacity ?? 1), 0, 1);
    const fontScale = clamp(Number(textStyle.fontScale ?? 1), FONT_SCALE_MIN, FONT_SCALE_MAX);
    const maxWidth = clamp(Number(textStyle.maxWidth ?? MAX_WIDTH_DEFAULT), MAX_WIDTH_MIN, MAX_WIDTH_MAX);
    const lineHeight = clamp(Number(textStyle.lineHeight ?? LINE_HEIGHT_DEFAULT), LINE_HEIGHT_MIN, LINE_HEIGHT_MAX);
    const rotation = clamp(Number(textStyle.rotation ?? 0), ROTATION_MIN, ROTATION_MAX);
    const defaultAlign = PRICE_KEYS.has(key) ? "right" : key === "name" ? "center" : "left";
    const defaultVAnchor = BOTTOM_ANCHOR_KEYS.has(key)
      ? "bottom"
      : key === "date"
        ? "middle"
        : "top";

    return Object.freeze({
      opacity,
      fontScale,
      maxWidth,
      lineHeight,
      rotation,
      align: normalizeAlign(textStyle.align, defaultAlign),
      vAnchor: normalizeVAnchor(textStyle.vAnchor, defaultVAnchor),
      rgba(alpha = 1) {
        const nextAlpha = clamp(Number(alpha || 1) * opacity * rgbaColor.a, 0, 1);
        return `rgba(${rgbaColor.r},${rgbaColor.g},${rgbaColor.b},${nextAlpha})`;
      },
    });
  };
}

function measureLines(ctx, lines) {
  const widths = new Array(lines.length);
  let maxWidth = 0;

  for (let index = 0; index < lines.length; index += 1) {
    const width = cachedMeasureWidth(ctx, lines[index]);
    widths[index] = width;
    if (width > maxWidth) {
      maxWidth = width;
    }
  }

  return { widths, maxWidth };
}

function drawClockIcon(ctx, x, y, size, color) {
  ctx.save();
  try {
    ctx.translate(x, y);
    const scale = size / 100;
    ctx.scale(scale, scale);
    ctx.strokeStyle = color;
    ctx.lineWidth = CLOCK_STROKE_WIDTH;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    ctx.beginPath();
    ctx.arc(50, 50, 40, 0, Math.PI * 2);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(50, 30);
    ctx.lineTo(50, 50);
    ctx.lineTo(64, 64);
    ctx.stroke();
  } finally {
    ctx.restore();
  }
}

function withRotation(ctx, rotationRadians, originX, originY, draw) {
  ctx.save();
  try {
    if (rotationRadians) {
      ctx.translate(originX, originY);
      ctx.rotate(rotationRadians);
      ctx.translate(-originX, -originY);
    }

    draw();
  } finally {
    ctx.restore();
  }
}

function getRotatedBox(box, rotationRadians) {
  if (!rotationRadians) {
    return box;
  }

  return rotatedAabbRect(
    box,
    rotationRadians,
    box.x + box.w / 2,
    box.y + box.h / 2,
  );
}

function drawWrappedBadge(ctx, {
  text,
  x,
  y,
  maxWidth,
  fontSize,
  color,
  align,
  lineHeight,
  rotation,
}) {
  ctx.font = internBurbankSmall(fontSize);

  const availableWidth = Math.max(1, maxWidth - (BOX_HORIZONTAL_PADDING * 2));
  const lines = wrapText(ctx, text, availableWidth);
  if (lines.length === 0) {
    return null;
  }

  const { widths, maxWidth: textWidth } = measureLines(ctx, lines);
  const lineStep = fontSize * lineHeight;
  const textHeight = fontSize + ((lines.length - 1) * lineStep);
  const boxHeight = BOX_VERTICAL_PADDING + textHeight + BOX_VERTICAL_PADDING;
  const box = {
    x,
    y: y - boxHeight,
    w: BOX_HORIZONTAL_PADDING + textWidth + BOX_HORIZONTAL_PADDING,
    h: boxHeight,
  };
  const rotationRadians = rotation ? (rotation * Math.PI) / 180 : 0;

  withRotation(
    ctx,
    rotationRadians,
    box.x + box.w / 2,
    box.y + box.h / 2,
    () => {
      ctx.fillStyle = BADGE_BACKGROUND;
      roundedRect(ctx, box.x, box.y, box.w, box.h, BADGE_RADIUS);
      ctx.fill();
      ctx.textAlign = "left";
      ctx.textBaseline = "top";
      ctx.fillStyle = color;

      const textX = box.x + BOX_HORIZONTAL_PADDING;
      const textY = box.y + BOX_VERTICAL_PADDING;

      for (let index = 0; index < lines.length; index += 1) {
        const line = lines[index];
        const width = widths[index];
        const offsetX = align === "right"
          ? textWidth - width
          : align === "center"
            ? (textWidth - width) / 2
            : 0;

        ctx.fillText(line, textX + offsetX, textY + (index * lineStep));
      }
    },
  );

  return getRotatedBox(box, rotationRadians);
}

function drawDateBadge(ctx, {
  text,
  x,
  y,
  maxWidth,
  style,
}) {
  const fontSize = TYPE_FONT_SIZE * style.fontScale;
  const iconSize = fontSize + 2;
  const font = internBurbankSmall(fontSize);

  ctx.font = font;

  const availableWidth = Math.max(
    1,
    maxWidth - ((BOX_HORIZONTAL_PADDING * 2) + iconSize + DATE_ICON_GAP),
  );
  const lines = wrapText(ctx, text, availableWidth);
  if (lines.length === 0) {
    return null;
  }

  const { widths, maxWidth: textWidth } = measureLines(ctx, lines);
  const lineStep = fontSize * style.lineHeight;
  const textHeight = fontSize + ((lines.length - 1) * lineStep);
  const box = {
    x,
    y,
    w: (BOX_HORIZONTAL_PADDING * 2) + iconSize + DATE_ICON_GAP + textWidth,
    h: (BOX_VERTICAL_PADDING * 2) + Math.max(iconSize, textHeight),
  };
  const rotationRadians = style.rotation ? (style.rotation * Math.PI) / 180 : 0;

  withRotation(
    ctx,
    rotationRadians,
    box.x + box.w / 2,
    box.y + box.h / 2,
    () => {
      ctx.fillStyle = BADGE_BACKGROUND;
      roundedRect(ctx, box.x, box.y, box.w, box.h, BADGE_RADIUS);
      ctx.fill();

      const iconX = box.x + BOX_HORIZONTAL_PADDING;
      const iconY = box.y + ((box.h - iconSize) / 2) + 1;
      drawClockIcon(ctx, iconX, iconY, iconSize, style.rgba(1));

      ctx.textAlign = "left";
      ctx.textBaseline = "top";
      ctx.fillStyle = style.rgba(1);

      const textX = box.x + BOX_HORIZONTAL_PADDING + iconSize + DATE_ICON_GAP;
      const textY = box.y + ((box.h - textHeight) / 2);

      for (let index = 0; index < lines.length; index += 1) {
        const line = lines[index];
        const width = widths[index];
        const offsetX = style.align === "right"
          ? textWidth - width
          : style.align === "center"
            ? (textWidth - width) / 2
            : 0;

        ctx.fillText(line, textX + offsetX, textY + (index * lineStep));
      }
    },
  );

  return getRotatedBox(box, rotationRadians);
}

function resolveDiscountPrices(template, ui) {
  return computeDiscountedPrices({
    baseOfferVbucks: Number(template?.vbucks || 0),
    conversionRate: resolveConversionRate(template?.conversionRate),
    baseListSoles: ui?.pricesLocked !== false ? null : template?.soles,
    discount: normalizeDiscountConfig(template?.individualDiscount ?? template?.discount),
  });
}

export function createOverlayRenderer({ isTokenValid } = {}) {
  let canvas = null;
  let context = null;
  let canvasSize = 0;
  let lastSnapshot = null;
  let lastHitBoxes = null;
  let lastHiddenElements = null;
  let hiddenElementSet = new Set();

  function ensureCanvas(size) {
    if (!canvas || !context) {
      canvas = document.createElement("canvas");
      context = canvas.getContext("2d", { alpha: true, desynchronized: true });
    }

    if (canvasSize !== size || canvas.width !== size || canvas.height !== size) {
      canvasSize = size;
      canvas.width = size;
      canvas.height = size;
      lastSnapshot = null;
      lastHitBoxes = null;
    }
  }

  function getHiddenSet(hiddenElements) {
    if (hiddenElements !== lastHiddenElements) {
      lastHiddenElements = hiddenElements;
      hiddenElementSet = new Set(ensureArray(hiddenElements).map((entry) => String(entry)));
    }

    return hiddenElementSet;
  }

  return Object.freeze({
    render(state, { size, token } = {}) {
      const nextSize = sanitizeCanvasSize(size);
      ensureCanvas(nextSize);

      const snapshot = createSnapshot(state, nextSize);
      if (snapshotEquals(snapshot, lastSnapshot) && lastHitBoxes) {
        return lastHitBoxes;
      }

      lastSnapshot = snapshot;

      const template = snapshot.template;
      const ui = snapshot.ui;
      const offsets = createOffsets(template);
      const resolveTextStyle = createTextStyleResolver(template);
      const hiddenSet = getHiddenSet(template?.hiddenElements);
      const imageLayers = ensureArray(template?.imageLayers);
      const hitBoxes = {};

      const labelStyle = resolveTextStyle("label");
      const vbucksStyle = resolveTextStyle("vbucks");
      const solesOfferStyle = resolveTextStyle("solesOffer");
      const solesListStyle = resolveTextStyle("solesList");
      const typeStyle = resolveTextStyle("type");
      const rarityStyle = resolveTextStyle("rarity");
      const dateStyle = resolveTextStyle("date");
      const nameStyle = resolveTextStyle("name");

      const itemName = String(template?.itemName || "");
      const typeLabel = String(template?.typeLabel || "");
      const rarityLabel = String(template?.rarityLabel || "");
      const giftLabel = String(template?.giftLabel || DEFAULT_GIFT_LABEL);
      const dateText = String(template?.dateText || "").trim();
      const vbucks = Number(template?.vbucks || 0);
      const selectionOfferId = snapshot.selectionOfferId;
      const hasContent = Boolean(selectionOfferId || itemName || imageLayers.length > 0);
      const prices = resolveDiscountPrices(template, ui);
      const resolvedOfferSoles = prices?.offerSoles;
      const resolvedListSoles = prices?.active ? prices?.listSoles : null;
      const offerSolesText = resolvedOfferSoles != null && String(resolvedOfferSoles).trim() !== ""
        ? formatSoles(resolvedOfferSoles)
        : "";
      const listSolesText = (
        resolvedListSoles != null &&
        String(resolvedListSoles).trim() !== "" &&
        resolvedListSoles !== resolvedOfferSoles
      )
        ? formatSoles(resolvedListSoles)
        : "";

      context.save();
      try {
        context.setTransform(1, 0, 0, 1, 0, 0);
        context.globalAlpha = 1;
        context.globalCompositeOperation = "source-over";
        context.textAlign = "left";
        context.textBaseline = "alphabetic";
        context.clearRect(0, 0, nextSize, nextSize);

        if (!hiddenSet.has("accent")) {
          fillAccentOverlay(context, nextSize, nextSize, template);
        }

        if (!isTokenStillValid(token, isTokenValid)) {
          return null;
        }

        if (!hasContent) {
          lastHitBoxes = hitBoxes;
          return hitBoxes;
        }

        context.textAlign = "left";
        context.textBaseline = "top";

        if (!hiddenSet.has("label")) {
          const labelBox = drawWrappedTextBlock(context, {
            text: giftLabel,
            x: TOP_PADDING + offsets.label.x,
            y: TOP_PADDING + offsets.label.y,
            maxWidth: (nextSize - (EDGE_PADDING * 2)) * labelStyle.maxWidth,
            fontSize: LABEL_FONT_SIZE * labelStyle.fontScale,
            font: internBurbankSmall(LABEL_FONT_SIZE * labelStyle.fontScale),
            fillStyle: labelStyle.rgba(1),
            align: labelStyle.align,
            vAnchor: labelStyle.vAnchor,
            lineHeight: labelStyle.lineHeight,
            rotation: labelStyle.rotation,
          });

          if (labelBox) {
            hitBoxes.label = labelBox;
          }
        }

        if (!hiddenSet.has("vbucks")) {
          const formattedVbucks = PriceRenderer.formatThousands(vbucks);
          const offerFontSize = VBUCKS_FONT_SIZE * vbucksStyle.fontScale;
          const listFontSize = Math.max(1, Math.round(offerFontSize * 0.66));
          const vbucksX = TOP_PADDING + offsets.vbucks.x;
          const vbucksY = TOP_PADDING + 120 + offsets.vbucks.y;

          context.save();
          try {
            context.font = internBurbankSmall(Math.max(1, Number(offerFontSize) || 1));
            const textWidth = cachedMeasureWidth(context, String(formattedVbucks || ""));
            const centerX = vbucksX + ((VBUCKS_ICON_SIZE + VBUCKS_GAP + textWidth) / 2);
            const priceBox = PriceRenderer.renderIndividualVbucksPrice(context, {
              centerX,
              topY: vbucksY,
              offerText: formattedVbucks,
              listText: "",
              offerFontSize,
              listFontSize,
              iconSize: VBUCKS_ICON_SIZE,
              gapX: VBUCKS_GAP,
              offerColor: vbucksStyle.rgba(1),
              listColor: SECONDARY_LABEL_COLOR,
              rotation: vbucksStyle.rotation ? (vbucksStyle.rotation * Math.PI) / 180 : 0,
              draw: true,
            });

            if (priceBox) {
              hitBoxes.vbucks = priceBox;
            }
          } finally {
            context.restore();
          }
        }

        if (!hiddenSet.has("soles") && (offerSolesText || listSolesText)) {
          const useSharedOffsets = !(
            template?.solesOfferOffsetX != null ||
            template?.solesOfferOffsetY != null ||
            template?.solesListOffsetX != null ||
            template?.solesListOffsetY != null
          );
          const offerFontSize = VBUCKS_FONT_SIZE * solesOfferStyle.fontScale;
          const listFontSize = Math.max(
            1,
            Math.round(offerFontSize * 0.66 * solesListStyle.fontScale),
          );
          const baseX = nextSize - BOTTOM_PADDING + offsets.soles.x;
          const baseY = TOP_PADDING + offsets.soles.y;
          const offerX = useSharedOffsets ? baseX : nextSize - BOTTOM_PADDING + offsets.solesOffer.x;
          const listX = useSharedOffsets ? baseX : nextSize - BOTTOM_PADDING + offsets.solesList.x;
          const offerY = useSharedOffsets
            ? (listSolesText ? baseY + listFontSize + SOLES_GAP_Y : baseY)
            : TOP_PADDING + offsets.solesOffer.y;
          const listY = useSharedOffsets ? baseY : TOP_PADDING + offsets.solesList.y;

          if (!hiddenSet.has("solesOffer") && offerSolesText) {
            const discountHex = safeHex(template?.textStyles?.solesOffer?.discountColor) || DEFAULT_DISCOUNT_COLOR;
            const discountColor = hexToRgba(discountHex);
            const offerColor = listSolesText
              ? `rgba(${discountColor.r},${discountColor.g},${discountColor.b},${clamp(Number(solesOfferStyle.opacity ?? 1), 0, 1)})`
              : solesOfferStyle.rgba(1);
            const offerBox = drawSingleLineText(context, {
              text: offerSolesText,
              x: offerX,
              y: offerY,
              fontSize: offerFontSize,
              font: internBurbankSmall(offerFontSize),
              fillStyle: offerColor,
              align: solesOfferStyle.align,
              vAnchor: solesOfferStyle.vAnchor,
              rotation: solesOfferStyle.rotation,
              draw: true,
            });

            if (offerBox) {
              hitBoxes.solesOffer = offerBox;
            }
          }

          if (!hiddenSet.has("solesList") && listSolesText) {
            const listBox = drawSingleLineText(context, {
              text: listSolesText,
              x: listX,
              y: listY,
              fontSize: listFontSize,
              font: internBurbankSmall(listFontSize),
              fillStyle: solesListStyle.rgba(1),
              align: solesListStyle.align,
              vAnchor: solesListStyle.vAnchor,
              rotation: solesListStyle.rotation,
              draw: true,
            });

            if (listBox) {
              hitBoxes.solesList = listBox;
              drawStrikeThrough(context, {
                x: listBox.x,
                y: listBox.y,
                w: listBox.w,
                fontSize: listFontSize,
                color: solesListStyle.rgba(1),
                rotation: solesListStyle.rotation,
                originX: listX,
                originY: listY,
              });
            }
          }
        }

        context.textAlign = "left";
        context.textBaseline = "alphabetic";

        const bottomAnchorY = nextSize - EDGE_PADDING;

        if (!hiddenSet.has("type") && typeLabel) {
          const typeBox = drawWrappedBadge(context, {
            text: typeLabel,
            x: 8 + offsets.type.x,
            y: nextSize - 8 + offsets.type.y,
            maxWidth: (nextSize - (EDGE_PADDING * 2)) * typeStyle.maxWidth,
            fontSize: TYPE_FONT_SIZE * typeStyle.fontScale,
            color: typeStyle.rgba(1),
            align: typeStyle.align,
            lineHeight: typeStyle.lineHeight,
            rotation: typeStyle.rotation,
          });

          if (typeBox) {
            hitBoxes.type = typeBox;
          }
        }

        if (!hiddenSet.has("rarity") && rarityLabel) {
          const rarityBox = drawWrappedTextBlock(context, {
            text: rarityLabel,
            x: EDGE_PADDING - 40 + offsets.rarity.x,
            y: bottomAnchorY - 28 + offsets.rarity.y,
            maxWidth: (nextSize - (EDGE_PADDING * 2)) * rarityStyle.maxWidth,
            fontSize: TYPE_FONT_SIZE * rarityStyle.fontScale,
            font: internBurbankSmall(TYPE_FONT_SIZE * rarityStyle.fontScale),
            fillStyle: rarityStyle.rgba(1),
            align: rarityStyle.align,
            vAnchor: rarityStyle.vAnchor,
            lineHeight: rarityStyle.lineHeight,
            rotation: rarityStyle.rotation,
          });

          if (rarityBox) {
            hitBoxes.rarity = rarityBox;
          }
        }

        if (!hiddenSet.has("date") && dateText) {
          const fontSize = TYPE_FONT_SIZE * dateStyle.fontScale;
          const iconSize = fontSize + 2;
          const font = internBurbankSmall(fontSize);

          context.font = font;
          const availableWidth = Math.max(
            1,
            ((nextSize - (EDGE_PADDING * 2)) * dateStyle.maxWidth) -
              ((BOX_HORIZONTAL_PADDING * 2) + iconSize + DATE_ICON_GAP),
          );
          const lines = wrapText(context, dateText, availableWidth);
          const { maxWidth: textWidth } = measureLines(context, lines);
          const lineStep = fontSize * dateStyle.lineHeight;
          const textHeight = fontSize + ((Math.max(1, lines.length) - 1) * lineStep);
          const boxWidth = (BOX_HORIZONTAL_PADDING * 2) + iconSize + DATE_ICON_GAP + textWidth;
          const boxHeight = (BOX_VERTICAL_PADDING * 2) + Math.max(iconSize, textHeight);
          const dateBox = drawDateBadge(context, {
            text: dateText,
            x: nextSize - 8 - boxWidth + offsets.date.x,
            y: nextSize - 8 - boxHeight + offsets.date.y,
            maxWidth: (nextSize - (EDGE_PADDING * 2)) * dateStyle.maxWidth,
            style: dateStyle,
          });

          if (dateBox) {
            hitBoxes.date = dateBox;
          }
        }

        if (!hiddenSet.has("name")) {
          const nameBox = drawWrappedTextBlock(context, {
            text: itemName.toUpperCase(),
            x: (nextSize / 2) + offsets.name.x,
            y: bottomAnchorY + offsets.name.y,
            maxWidth: (nextSize - (EDGE_PADDING * 2)) * nameStyle.maxWidth,
            fontSize: NAME_FONT_SIZE * nameStyle.fontScale,
            font: internBurbankBig(NAME_FONT_SIZE * nameStyle.fontScale),
            fillStyle: nameStyle.rgba(1),
            align: nameStyle.align,
            vAnchor: nameStyle.vAnchor,
            lineHeight: nameStyle.lineHeight,
            rotation: nameStyle.rotation,
          });

          if (nameBox) {
            hitBoxes.name = nameBox;
          }
        }
      } finally {
        context.restore();
      }

      lastHitBoxes = hitBoxes;
      return hitBoxes;
    },
    getCanvas() {
      return canvas;
    },
  });
}

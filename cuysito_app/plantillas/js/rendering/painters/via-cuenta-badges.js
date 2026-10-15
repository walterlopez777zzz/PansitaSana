import { roundedRect } from "./geometry.js";
import { internBurbankSmall } from "./font-cache.js";

/**
 * Pinta las insignias de una tarjeta Vía Cuenta (RN-6) apiladas desde la
 * esquina superior derecha: `-NN%`, `AGOTADO` y `Vence dd/mm`.
 *
 * Mismo painter para preview y export; no registra hitboxes porque las
 * insignias no son editables por el operador.
 */

const BADGE_COLORS = Object.freeze({
  discount: { bg: "#FFD400", fg: "#111111" },
  soldOut: { bg: "#B3261E", fg: "#FFFFFF" },
  expires: { bg: "#000000", fg: "#FFFFFF" },
});

function pad2(value) {
  return String(value).padStart(2, "0");
}

export function formatExpiryLabel(expiresAt) {
  if (!expiresAt) return "";
  const date = new Date(expiresAt);
  if (Number.isNaN(date.getTime())) return "";
  return `Vence ${pad2(date.getDate())}/${pad2(date.getMonth() + 1)}`;
}

/**
 * Convierte `badges` del modelo en etiquetas listas para pintar, en orden.
 * @param {{discountPercent: number|null, soldOut: boolean, expiresAt: string|null}|null} badges
 */
export function resolveViaCuentaBadgeLabels(badges) {
  if (!badges) return [];
  const labels = [];
  const percent = Number(badges.discountPercent);
  if (Number.isFinite(percent) && percent > 0) {
    labels.push({ text: `-${Math.round(percent)}%`, ...BADGE_COLORS.discount });
  }
  if (badges.soldOut === true) {
    labels.push({ text: "AGOTADO", ...BADGE_COLORS.soldOut });
  }
  const expiry = formatExpiryLabel(badges.expiresAt);
  if (expiry) labels.push({ text: expiry, ...BADGE_COLORS.expires });
  return labels;
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {{cardX:number, cardY:number, cardW:number, layoutScale:number, badges:object|null}} params
 * @returns {{x:number,y:number,w:number,h:number}|null} caja envolvente de las insignias pintadas
 */
export function drawViaCuentaBadges(ctx, { cardX, cardY, cardW, layoutScale, badges }) {
  const labels = resolveViaCuentaBadgeLabels(badges);
  if (!labels.length) return null;

  const scale = Number(layoutScale) || 1;
  const margin = Math.round(26 * scale);
  const fontSize = Math.round(30 * scale);
  const padX = Math.round(14 * scale);
  const padY = Math.round(8 * scale);
  const gap = Math.round(8 * scale);
  const radius = Math.round(10 * scale);
  const pillH = fontSize + 2 * padY;
  const rightX = cardX + cardW - margin;

  let y = cardY + margin;
  let minX = rightX;

  ctx.save();
  try {
    ctx.font = internBurbankSmall(fontSize, 700);
    ctx.textAlign = "left";
    ctx.textBaseline = "middle";
    for (const label of labels) {
      const pillW = Math.ceil(ctx.measureText(label.text).width) + 2 * padX;
      const x = rightX - pillW;
      ctx.fillStyle = label.bg;
      roundedRect(ctx, x, y, pillW, pillH, radius);
      ctx.fill();
      ctx.fillStyle = label.fg;
      ctx.fillText(label.text, x + padX, y + pillH / 2);
      minX = Math.min(minX, x);
      y += pillH + gap;
    }
  } finally {
    ctx.restore();
  }

  return { x: minX, y: cardY + margin, w: rightX - minX, h: y - gap - (cardY + margin) };
}

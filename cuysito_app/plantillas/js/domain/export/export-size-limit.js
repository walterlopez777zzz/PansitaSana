/**
 * Export Size Limit (pure domain)
 *
 * Byte-size formatting and evaluation against the WhatsApp image limit.
 * Units are decimal (1 KB = 1000 bytes, 1 MB = 1 000 000 bytes), matching how
 * WhatsApp states its "16 MB" limit.
 */

export const WHATSAPP_IMAGE_LIMIT_BYTES = 16_000_000;

/** Fraction of the limit from which the size is flagged as "near the limit". */
export const WARNING_RATIO = 0.9;

export const SIZE_LEVEL = Object.freeze({
  OK: "ok",
  WARNING: "warning",
  OVER: "over",
});

const BYTES_PER_KB = 1000;
const BYTES_PER_MB = 1_000_000;

const integerFormatter = new Intl.NumberFormat("es-PE", { maximumFractionDigits: 0 });
const decimalFormatter = new Intl.NumberFormat("es-PE", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

function toSafeBytes(value) {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? Math.round(number) : 0;
}

/**
 * @param {number} bytes
 * @returns {{ bytes: number, kb: number, mb: number, bytesLabel: string, kbLabel: string,
 *   mbLabel: string, primaryLabel: string, compactLabel: string }}
 */
export function describeByteSize(bytes) {
  const safe = toSafeBytes(bytes);
  const kb = safe / BYTES_PER_KB;
  const mb = safe / BYTES_PER_MB;
  const bytesLabel = `${integerFormatter.format(safe)} bytes`;
  const kbLabel = `${decimalFormatter.format(kb)} KB`;
  const mbLabel = `${decimalFormatter.format(mb)} MB`;
  const primaryLabel = safe >= BYTES_PER_MB ? mbLabel : kbLabel;

  return Object.freeze({
    bytes: safe,
    kb,
    mb,
    bytesLabel,
    kbLabel,
    mbLabel,
    primaryLabel,
    compactLabel: safe < BYTES_PER_KB ? bytesLabel : primaryLabel,
  });
}

/**
 * @param {number} bytes
 * @param {number} [limitBytes]
 * @returns {{ level: "ok"|"warning"|"over", ratio: number, marginBytes: number, isWithinLimit: boolean }}
 */
export function evaluateAgainstLimit(bytes, limitBytes = WHATSAPP_IMAGE_LIMIT_BYTES) {
  const safe = toSafeBytes(bytes);
  const limit = toSafeBytes(limitBytes) || WHATSAPP_IMAGE_LIMIT_BYTES;
  const ratio = safe / limit;

  let level = SIZE_LEVEL.OK;
  if (safe > limit) level = SIZE_LEVEL.OVER;
  else if (ratio >= WARNING_RATIO) level = SIZE_LEVEL.WARNING;

  return Object.freeze({
    level,
    ratio,
    marginBytes: limit - safe,
    isWithinLimit: safe <= limit,
  });
}

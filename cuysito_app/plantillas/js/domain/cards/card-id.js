/**
 * Identificadores estables de tarjeta.
 *
 * - `createCardId()` genera un UUID v4 (o un id pseudoaleatorio equivalente
 *   cuando `crypto.randomUUID` no existe). Solo debe invocarse desde comandos
 *   o migraciones, nunca desde normalizadores puros.
 * - `legacyCardId(offerId, index)` deriva un id determinista para tarjetas
 *   antiguas mientras no han sido migradas.
 */

const LEGACY_PREFIX = "legacy:";
const MAX_CARD_ID_LENGTH = 200;
const CARD_ID_PATTERN = /^[A-Za-z0-9:_\-.]+$/;

function randomHex(bytes) {
  const buffer = new Uint8Array(bytes);
  if (globalThis.crypto?.getRandomValues) {
    globalThis.crypto.getRandomValues(buffer);
  } else {
    for (let i = 0; i < buffer.length; i += 1) buffer[i] = Math.floor(Math.random() * 256);
  }
  let out = "";
  for (const byte of buffer) out += byte.toString(16).padStart(2, "0");
  return out;
}

export function createCardId() {
  if (typeof globalThis.crypto?.randomUUID === "function") {
    return globalThis.crypto.randomUUID();
  }
  const hex = randomHex(16);
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-4${hex.slice(13, 16)}-a${hex.slice(17, 20)}-${hex.slice(20, 32)}`;
}

export function isCardId(value) {
  if (typeof value !== "string") return false;
  const trimmed = value.trim();
  return trimmed.length > 0
    && trimmed.length <= MAX_CARD_ID_LENGTH
    && trimmed === value
    && CARD_ID_PATTERN.test(trimmed);
}

export function legacyCardId(offerId, index) {
  const safeOffer = String(offerId || "").replace(/[^A-Za-z0-9_\-.]/g, "_");
  const safeIndex = Math.max(0, Math.floor(Number(index) || 0));
  return `${LEGACY_PREFIX}${safeOffer}:${safeIndex}`;
}

export function isLegacyCardId(value) {
  return typeof value === "string" && value.startsWith(LEGACY_PREFIX);
}

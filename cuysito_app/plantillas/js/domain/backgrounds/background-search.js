/**
 * Background Search (pure domain)
 *
 * Name search over the background gallery. Text is normalised once per
 * background when the index is built, so typing only pays for the cheap
 * per-token comparisons.
 *
 * Matching rules (per query token, all tokens must match):
 *   exact name > name prefix > word prefix > substring > one-edit fuzzy
 *
 * Fuzzy matching is limited to tokens of 5+ characters and a single edit
 * (insertion, deletion or substitution) against a whole word, which catches
 * typical typos without flooding the results.
 */

const DIACRITICS_PATTERN = /[\u0300-\u036f]/g;
const NON_ALPHANUMERIC_PATTERN = /[^\p{L}\p{N}]+/gu;
const IMAGE_EXTENSION_PATTERN = /\.(?:png|jpe?g|webp|gif|avif|svg|bmp)$/i;
const MIN_FUZZY_TOKEN_LENGTH = 5;

const MATCH_SCORE = Object.freeze({
  EXACT: 4,
  PREFIX: 3,
  WORD_START: 2,
  SUBSTRING: 1,
  FUZZY: 0,
});
const NO_MATCH = -1;

/** Lower-cases, strips diacritics and collapses separators to single spaces. */
export function normalizeSearchText(value) {
  return String(value ?? "")
    .normalize("NFD")
    .replace(DIACRITICS_PATTERN, "")
    .toLowerCase()
    .replace(NON_ALPHANUMERIC_PATTERN, " ")
    .trim();
}

/** @param {string} normalizedText output of `normalizeSearchText` */
export function tokenizeSearchText(normalizedText) {
  return normalizedText ? normalizedText.split(" ").filter(Boolean) : [];
}

/** Fallback display name for backgrounds without one: the URL's file stem. */
export function deriveNameFromUrl(url) {
  const value = String(url ?? "").trim();
  if (!value || value.startsWith("data:") || value.startsWith("blob:")) return "";

  const path = value.split(/[?#]/, 1)[0];
  const lastSegment = path.slice(path.lastIndexOf("/") + 1);
  let decoded = lastSegment;
  try {
    decoded = decodeURIComponent(lastSegment);
  } catch {
    /* keep the raw segment when the URL is malformed */
  }
  return decoded.replace(IMAGE_EXTENSION_PATTERN, "").trim();
}

/** True when `a` and `b` differ by at most one insertion, deletion or substitution. */
function isWithinOneEdit(a, b) {
  const lengthDelta = a.length - b.length;
  if (lengthDelta > 1 || lengthDelta < -1) return false;

  const [shorter, longer] = a.length > b.length ? [b, a] : [a, b];
  let i = 0;
  let j = 0;
  let edits = 0;

  while (i < shorter.length && j < longer.length) {
    if (shorter[i] === longer[j]) {
      i += 1;
      j += 1;
      continue;
    }
    if (edits === 1) return false;
    edits = 1;
    if (shorter.length === longer.length) i += 1; // substitution
    j += 1; // insertion / deletion
  }
  return true;
}

/**
 * @typedef {object} BackgroundSearchEntry
 * @property {object} background original record
 * @property {string} key stable identity (`id`, else `url`)
 * @property {string} text normalised searchable name
 * @property {string[]} words tokens of `text`
 */

/**
 * @param {Array<{ id?: string, name?: string, url?: string }>} backgrounds
 * @returns {{ entries: ReadonlyArray<BackgroundSearchEntry>, size: number }}
 */
export function createBackgroundSearchIndex(backgrounds) {
  const list = Array.isArray(backgrounds) ? backgrounds : [];
  const entries = [];

  for (const background of list) {
    if (!background || typeof background !== "object") continue;

    const url = String(background.url ?? "").trim();
    const id = String(background.id ?? "").trim();
    const explicitName = String(background.name ?? "").replace(IMAGE_EXTENSION_PATTERN, "").trim();
    const text = normalizeSearchText(explicitName || deriveNameFromUrl(url));

    entries.push(
      Object.freeze({
        background,
        key: id || url,
        text,
        words: tokenizeSearchText(text),
      }),
    );
  }

  return Object.freeze({ entries: Object.freeze(entries), size: entries.length });
}

function scoreToken(entry, token) {
  const { text, words } = entry;
  if (text === token) return MATCH_SCORE.EXACT;
  if (text.startsWith(token)) return MATCH_SCORE.PREFIX;
  for (const word of words) if (word.startsWith(token)) return MATCH_SCORE.WORD_START;
  if (text.includes(token)) return MATCH_SCORE.SUBSTRING;
  if (token.length >= MIN_FUZZY_TOKEN_LENGTH) {
    for (const word of words) if (isWithinOneEdit(token, word)) return MATCH_SCORE.FUZZY;
  }
  return NO_MATCH;
}

/**
 * Filters and ranks the indexed backgrounds. An empty query returns every
 * background in index order. Ties keep index order, so results are stable.
 *
 * @param {ReturnType<typeof createBackgroundSearchIndex>} index
 * @param {string} query raw user input
 * @returns {object[]} matching background records, best first
 */
export function searchBackgrounds(index, query) {
  const entries = index?.entries ?? [];
  const tokens = tokenizeSearchText(normalizeSearchText(query));
  if (tokens.length === 0) return entries.map((entry) => entry.background);

  const matches = [];
  for (let position = 0; position < entries.length; position += 1) {
    const entry = entries[position];
    if (!entry.text) continue;

    let score = 0;
    let matchesAllTokens = true;
    for (const token of tokens) {
      const tokenScore = scoreToken(entry, token);
      if (tokenScore === NO_MATCH) {
        matchesAllTokens = false;
        break;
      }
      score += tokenScore;
    }
    if (matchesAllTokens) matches.push({ background: entry.background, score, position });
  }

  matches.sort((a, b) => b.score - a.score || a.position - b.position);
  return matches.map((match) => match.background);
}

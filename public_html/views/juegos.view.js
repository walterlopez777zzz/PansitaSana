/**
 * Juegos view module — hub de Giro Diario y Sorteo Flash.
 *
 * El esqueleto estable de las tarjetas y del modal de Sorteo Flash llega
 * renderizado desde el servidor (cuysito_app/juegos/index.php); el modal de
 * Giro Diario vive en cuysito_app/juegos/daily_spin/widget.php. Este módulo es
 * el único punto de montaje del comportamiento de la vista.
 *
 * Arquitectura (flujo unidireccional, sin estado duplicado):
 *
 *   gateway ─▶ normalize ─▶ store (hechos del servidor + hechos locales)
 *                              │
 *                              ▼  derive(state, now)  (función pura y total)
 *                        ViewModel único
 *                        ├─▶ renderCard   (tarjeta exterior)
 *                        └─▶ renderModal  (modal / dialog)
 *
 * Tarjeta y modal se pintan desde el MISMO ViewModel en la misma pasada, por lo
 * que no pueden divergir. Los derivadores son totales: cualquier estado de la
 * store (incluido el inicial, sin datos) produce un ViewModel válido; la
 * ausencia de datos se presenta como `loading` (o `error` si la única lectura
 * falló), nunca como excepción. Los renderers escriben sólo textContent /
 * atributos en slots `[data-gh-slot]` ya existentes (sin innerHTML) y
 * únicamente cuando el valor cambia: un tick del reloj cuesta una comparación
 * de cadenas por slot y cero layout shifts. La única lista dinámica (opciones
 * del selector de filtros) se reconcilia por clave dentro de su slot.
 *
 * Hechos locales del Giro Diario (viven en la store junto a los del servidor):
 * `picks` (modo Todo / Sin Pistas + filtro de categoría elegidos), `menuOpen`
 * (desplegable de filtros), `pendingFilter` (filtro de Lotes a la espera de
 * confirmar el aviso) y `confirmSpin` (giro pagado a la espera de confirmar el
 * gasto de bananos). El servidor sigue decidiendo el % final del premio.
 *
 * Ciclo de vida: `mount` abre una *sesión* (un AbortController) de la que
 * cuelgan el transporte, el listener de visibilidad y las relecturas; `unmount`
 * la aborta y todo lo asociado muere con ella. El módulo es keep-alive: el DOM
 * y las suscripciones a la store se crean una sola vez.
 *
 * Concurrencia: cada recurso lleva un número de secuencia; una respuesta
 * antigua nunca pisa a una más nueva. El reloj compartido se detiene al ocultar
 * la pestaña y al desmontar; al volver, refresca si los datos están viejos.
 *
 * @type {import('./view').ViewModule}
 */

/* ─────────────────────────────── constantes ────────────────────────── */

const LOGIN_HREF = '/login?redirect_to=/juegos';
const ACCOUNT_HREF = '/cuenta/';
/**
 * Sala del Sorteo Flash. URL limpia a propósito: la sala resuelve el sorteo
 * vigente en el servidor con la misma regla que `raffle_hub_status.php`, así que
 * exponer `?id=` no aporta nada y filtra un identificador interno.
 */
const ROOM_HREF = '/juegos/sorteo_flash/';
/** Id de cliente en window.Backdrop (velo compartido) para el dialog de Sorteo Flash. */
const FLASH_BACKDROP_CLIENT = 'flashDialog';

/** Frescura máxima de una lectura antes de refrescar al volver a la pestaña. */
const STALE_MS = 15_000;

/** Cadencia de refresco en segundo plano según el estado del recurso. */
const REFRESH_MS = Object.freeze({
  dailyIdle: 5 * 60_000,
  flashOpen: 20_000,
  flashResolving: 8_000,
  flashScheduled: 60_000,
  flashIdle: 120_000,
  afterError: 60_000,
});

/** Backoff cuando la cuenta atrás llega a 0 y el servidor aún no cambió de estado. */
const DEADLINE_BACKOFF_MS = Object.freeze([2_000, 4_000, 8_000, 15_000, 30_000]);

const TONE = Object.freeze({
  none: 'none',
  neutral: 'neutral',
  info: 'info',
  success: 'success',
  warning: 'warning',
  danger: 'danger',
});

/** Modos del pool de premios (contrato con spin_init/spin_go). */
const SPIN_MODE = Object.freeze({ all: 'all', noTracks: 'no_tracks' });
/** Porcentajes de respaldo si el servidor no envía `spin_config`. */
const DEFAULT_MODE_PERCENT = Object.freeze({ all: 15, no_tracks: 10 });
/** Selección local por defecto: todo el catálogo, sin filtro de categoría. */
const DEFAULT_PICKS = Object.freeze({ mode: SPIN_MODE.all, filter: '' });
const BUNDLE_WARNING = 'Algunos atuendos o vehículos están catalogados como Lote';

/* ─────────────────────────────── utilidades ────────────────────────── */

const intFormatter = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 0 });

function toInt(value, fallback = 0) {
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : fallback;
}

function toNumber(value, fallback = 0) {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}

function toText(value, fallback = '') {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : fallback;
}

function toPercent(value, fallback) {
  return Math.min(100, Math.max(0, toInt(value, fallback)));
}

/** ISO-8601 UTC ("…Z") → epoch ms, o null si no es una fecha válida. */
function parseUtc(value) {
  if (typeof value !== 'string' || value === '') return null;
  const ms = Date.parse(value);
  return Number.isFinite(ms) ? ms : null;
}

function formatInt(n) {
  return intFormatter.format(n);
}

function formatCoins(n) {
  return `${formatInt(n)} ${n === 1 ? 'banano' : 'bananos'}`;
}

/** Duración restante → "HH:MM:SS" (o "Nd HH:MM:SS" a partir de un día). */
function formatCountdown(ms) {
  const total = Math.max(0, Math.floor(ms / 1000));
  const days = Math.floor(total / 86_400);
  const h = Math.floor((total % 86_400) / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const hms = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  return days > 0 ? `${days}d ${hms}` : hms;
}

/** Duración para lectores de pantalla ("2 horas 5 minutos"). */
function describeCountdown(ms) {
  const total = Math.max(0, Math.floor(ms / 1000));
  const days = Math.floor(total / 86_400);
  const h = Math.floor((total % 86_400) / 3600);
  const m = Math.floor((total % 3600) / 60);
  const parts = [];
  if (days) parts.push(`${days} ${days === 1 ? 'día' : 'días'}`);
  if (h) parts.push(`${h} ${h === 1 ? 'hora' : 'horas'}`);
  if (m || parts.length === 0) parts.push(`${m} ${m === 1 ? 'minuto' : 'minutos'}`);
  return parts.join(' ');
}

class InvalidResponse extends Error {
  constructor(message) {
    super(message);
    this.name = 'InvalidResponse';
  }
}

/** Clasifica un fallo de transporte: 'aborted' | 'invalid' | 'network' | 'http'. */
function classifyError(err) {
  if (err && err.name === 'AbortError') return 'aborted';
  if (err instanceof InvalidResponse) return 'invalid';
  if (err instanceof SyntaxError) return 'invalid';
  if (err instanceof TypeError) return 'network';
  return 'http';
}

/* ─────────────────────────────── store ─────────────────────────────── */

/**
 * @typedef {Object} ResourceState
 * @property {'idle'|'loading'|'ready'|'error'} phase  Ciclo de la última lectura.
 * @property {Object|null} data       Últimos hechos válidos del servidor (sobreviven a un error).
 * @property {'network'|'http'|'invalid'|null} error
 * @property {string|null} notice     Mensaje transitorio (p. ej. rechazo del giro).
 * @property {'spinning'|null} action Hecho local que bloquea el CTA.
 */

/** @returns {ResourceState} */
const initialResourceState = () => ({
  phase: 'idle',
  data: null,
  error: null,
  notice: null,
  action: null,
});

/**
 * Store observable mínima. `set` fusiona el parche y notifica de forma
 * síncrona sólo si algún valor cambió: los suscriptores (render) son baratos y
 * así el DOM refleja el cambio en la misma tarea que lo produjo (sincronía
 * atómica tarjeta + modal) sin pasadas redundantes.
 */
function createStore(initial) {
  let state = initial;
  const listeners = new Set();
  return {
    get: () => state,
    set(patch) {
      let changed = false;
      for (const key of Object.keys(patch)) {
        if (!Object.is(state[key], patch[key])) { changed = true; break; }
      }
      if (!changed) return;
      state = { ...state, ...patch };
      for (const fn of listeners) fn(state);
    },
    subscribe(fn) {
      listeners.add(fn);
      return () => listeners.delete(fn);
    },
  };
}

/* ─────────────────────────────── reloj ─────────────────────────────── */

/**
 * Reloj compartido: un único temporizador alineado al segundo para todas las
 * cuentas atrás. Se detiene al ocultar la pestaña y al desmontar. El desfase
 * con el servidor se calibra con `server_time_utc` de cada respuesta para que
 * las cuentas atrás no dependan de la hora local del dispositivo.
 */
function createClock(onTick) {
  let timer = 0;
  let offsetMs = 0;
  let running = false;

  const now = () => Date.now() + offsetMs;
  const schedule = () => {
    timer = window.setTimeout(tick, 1000 - (Date.now() % 1000));
  };
  const tick = () => {
    timer = 0;
    if (!running) return;
    onTick(now());
    if (running) schedule();
  };

  return {
    now,
    calibrate(serverUtcIso) {
      const serverMs = parseUtc(serverUtcIso);
      if (serverMs !== null) offsetMs = serverMs - Date.now();
    },
    start() {
      if (running) return;
      running = true;
      schedule();
    },
    stop() {
      running = false;
      if (timer) window.clearTimeout(timer);
      timer = 0;
    },
  };
}

/* ─────────────────────── normalización (contratos) ─────────────────── */

/**
 * spin_init.spin_config → porcentajes por modo y filtros de categoría activos
 * (sólo los que el admin encendió y tienen objetos hoy), ordenados de mayor a
 * menor % OFF (empates conservan el orden del backend). Total: sin payload
 * devuelve los porcentajes por defecto y ninguna categoría.
 */
function normalizeSpinConfig(raw) {
  const cfg = raw && typeof raw === 'object' ? raw : {};
  const modesRaw = cfg.modes && typeof cfg.modes === 'object' ? cfg.modes : {};
  const mode = (key) => {
    const m = modesRaw[key] && typeof modesRaw[key] === 'object' ? modesRaw[key] : {};
    return { percent: toPercent(m.percent, DEFAULT_MODE_PERCENT[key]), count: Math.max(0, toInt(m.count)) };
  };
  const filters = [];
  const seen = new Set();
  for (const f of Array.isArray(cfg.filters) ? cfg.filters : []) {
    if (!f || typeof f !== 'object') continue;
    const key = toText(f.key, '');
    const count = Math.max(0, toInt(f.count));
    if (key === '' || seen.has(key) || count === 0) continue;
    seen.add(key);
    filters.push({
      key,
      label: toText(f.label, key),
      percent: toPercent(f.percent, 0),
      count,
      isBundle: f.is_bundle === true,
    });
  }
  filters.sort((a, b) => b.percent - a.percent);
  return { modes: { all: mode(SPIN_MODE.all), no_tracks: mode(SPIN_MODE.noTracks) }, filters };
}

/**
 * spin_init → hechos del Giro Diario. Sesión no válida es un estado
 * (`session: 'guest'`), no un error. Lanza InvalidResponse si el contrato no
 * se cumple.
 */
function normalizeSpin(raw) {
  if (!raw || typeof raw !== 'object') throw new InvalidResponse('spin_init: payload vacío');
  const serverTimeUtc = toText(raw.server_time_utc, '') || null;

  if (raw.is_logged === false) {
    return { serverTimeUtc, session: 'guest' };
  }
  if (raw.success !== true || !raw.user_data || !raw.spin_status) {
    throw new InvalidResponse(toText(raw.message, 'spin_init: respuesta incompleta'));
  }

  const user = raw.user_data;
  const status = raw.spin_status;
  const flash = (raw.event_config && raw.event_config.flash_offer) || null;
  const verified = user.verified === true || user.verified === 1 || user.verified === '1';

  return {
    serverTimeUtc,
    session: 'user',
    nick: toText(user.nick, ''),
    verified,
    // Veredicto de elegibilidad del servidor (política global); sin él, verificada ⇔ elegible.
    eligible: user.eligibility && typeof user.eligibility.eligible === 'boolean'
      ? user.eligibility.eligible
      : verified,
    coins: toInt(user.coins),
    bonusSpins: toInt(user.bonus_spins),
    freeAvailable: status.free_spin_available === true,
    nextResetMs: parseUtc(status.next_reset_utc),
    paidEnabled: toInt(status.paid_spins_enabled, 1) !== 0,
    paidLimit: toInt(status.paid_spins_limit, 999),
    paidPrice: toInt(status.paid_spin_price, toInt(status.cost_extra, 50)),
    paidUsed: toInt(status.user_paid_spins_count),
    flashOffer: flash && flash.active === true
      ? { claimed: toInt(flash.claimed), limit: Math.max(1, toInt(flash.limit, 1)), percent: toInt(flash.percent) }
      : null,
    previewItems: Array.isArray(raw.preview_items) ? raw.preview_items : [],
    spinConfig: normalizeSpinConfig(raw.spin_config),
  };
}

const FLASH_STATUSES = new Set([
  'scheduled', 'open', 'locking', 'processing', 'waiting_for_winner', 'completed', 'cancelled',
]);

/** raffle_hub_status → hechos del Sorteo Flash. */
function normalizeFlash(raw) {
  if (!raw || typeof raw !== 'object') throw new InvalidResponse('raffle_hub_status: payload vacío');
  if (raw.status === 'error') throw new InvalidResponse(toText(raw.message, 'raffle_hub_status: error'));
  if (typeof raw.found !== 'boolean') throw new InvalidResponse('raffle_hub_status: contrato inesperado');

  const serverTimeUtc = toText(raw.server_time_utc, '') || null;
  const next = raw.next_scheduled && typeof raw.next_scheduled === 'object'
    ? { id: toInt(raw.next_scheduled.id), startMs: parseUtc(raw.next_scheduled.start_time_utc) }
    : null;

  const base = {
    serverTimeUtc,
    loggedIn: raw.is_logged_in === true,
    banned: raw.my_banned === true,
    next: next && next.id > 0 ? next : null,
    raffle: null,
  };
  if (!raw.found || !raw.raffle || typeof raw.raffle !== 'object') return base;

  const r = raw.raffle;
  const status = toText(r.status, '');
  if (!FLASH_STATUSES.has(status)) throw new InvalidResponse(`raffle_hub_status: estado "${status}"`);
  const slotsTotal = Math.max(0, toInt(r.slots_total));
  const review = toText(r.my_review_status, '') || null;

  return {
    ...base,
    raffle: {
      id: toInt(r.id),
      title: toText(r.title, ''),
      status,
      startMs: parseUtc(r.start_time_utc),
      endMs: parseUtc(r.end_time_utc),
      participants: Math.max(0, toInt(r.participants)),
      slotsTotal,
      slotsLeft: slotsTotal > 0 ? Math.max(0, toInt(r.slots_left)) : -1,
      entryCost: Math.max(0, toNumber(r.entry_cost)),
      myReview: review === 'active' || review === 'under_review' || review === 'expelled' ? review : null,
      myWon: r.my_won === true,
    },
  };
}

/* ─────────────────────────── ViewModel: común ──────────────────────── */

const ERROR_COPY = Object.freeze({
  network: { badge: 'Sin conexión', desc: 'No pudimos conectar. Revisa tu conexión e inténtalo de nuevo.' },
  http: { badge: 'Servicio no disponible', desc: 'El servicio no respondió. Inténtalo de nuevo en unos segundos.' },
  invalid: { badge: 'No disponible', desc: 'Recibimos una respuesta inesperada. Inténtalo de nuevo.' },
});

function cta([text, tone, action]) {
  return { text, tone, action, enabled: action !== null };
}

/**
 * Estado base cuando la store aún no tiene hechos del servidor: la primera
 * lectura está en curso o pendiente (`loading`, indistinguible del esqueleto
 * servido en HTML) o la única lectura falló (`error`). Con datos devuelve null
 * y decide la máquina de cada juego.
 */
function resolveEmptyState(s) {
  if (s.data) return null;
  return s.phase === 'error' ? 'error' : 'loading';
}

/** Sobrescribe badge/desc/note con el detalle del último fallo de transporte. */
function applyErrorCopy(vm, s) {
  const e = ERROR_COPY[s.error] || ERROR_COPY.http;
  vm.badge = { text: e.badge, tone: TONE.danger };
  vm.desc = e.desc;
  vm.note = e.desc;
}

/**
 * Meta de la tarjeta/modal: siempre presente (label + value) para que ambas
 * tarjetas conserven la misma silueta en cualquier estado. `deadline` sólo en
 * cuentas atrás; `spoken` es la lectura accesible del valor.
 */
function staticMeta(label, value) {
  return { label, value, spoken: null, deadline: null };
}

/** Cuenta atrás: si `deadline` es null (dato ausente) degrada al meta estático dado. */
function countdownMeta(label, deadline, now, expiredText, fallback) {
  if (deadline === null) return fallback;
  const remaining = deadline - now;
  return {
    label,
    value: remaining <= 0 ? expiredText : formatCountdown(remaining),
    spoken: describeCountdown(remaining),
    deadline,
  };
}

/** Placeholder con la geometría exacta de una cuenta atrás (sin datos aún). */
const PENDING_TIME = '--:--:--';

/* ─────────────────────────── ViewModel: Giro Diario ────────────────── */

/**
 * Copys por estado. Un único lugar, versionado, sin condicionales dispersos.
 * `cardCta`/`modalCta`: `[text, tone, action]`; `action: null` ⇒ disabled.
 */
const DAILY_COPY = Object.freeze({
  loading: {
    badge: ['', TONE.none],
    desc: 'Consultando tu giro de hoy…',
    cardCta: ['Cargando…', 'primary', null],
    modalCta: ['Cargando…', 'primary', null],
    note: '',
  },
  guest: {
    badge: ['Recompensa diaria', TONE.neutral],
    desc: 'Gira gratis cada día y gana descuentos en la tienda.',
    cardCta: ['Inicia sesión para girar', 'secondary', 'login'],
    modalCta: ['Iniciar sesión', 'secondary', 'login'],
    note: 'Inicia sesión para reclamar tu giro gratis de hoy.',
  },
  unverified: {
    badge: ['Verificación requerida', TONE.warning],
    desc: 'Verifica tu cuenta para desbloquear el giro diario.',
    cardCta: ['Ver mi giro', 'secondary', 'open-daily'],
    modalCta: ['Verificar mi cuenta', 'secondary', 'account'],
    note: 'Necesitas verificar tu cuenta para poder girar.',
  },
  free: {
    badge: ['¡Giro gratis disponible!', TONE.success],
    desc: 'Tu giro de hoy está listo. ¡Prueba tu suerte!',
    cardCta: ['Girar gratis', 'primary', 'open-daily'],
    modalCta: ['Girar gratis', 'primary', 'spin'],
    note: 'Este giro no consume bananos.',
  },
  bonus: {
    badge: ['Tiro extra disponible', TONE.info],
    desc: 'Tienes tiros extra acumulados. Úsalos sin esperar al reset.',
    cardCta: ['Usar tiro extra', 'primary', 'open-daily'],
    modalCta: ['Usar tiro extra', 'primary', 'spin'],
    note: 'Se consumirá 1 tiro extra.',
  },
  paid: {
    badge: ['Tiro extra', TONE.info],
    desc: 'Ya usaste tu giro gratis. Puedes comprar otro tiro con bananos.',
    cardCta: ['Girar por bananos', 'secondary', 'open-daily'],
    modalCta: ['Girar por bananos', 'primary', 'spin'],
    note: '',
  },
  'paid-insufficient': {
    badge: ['Saldo insuficiente', TONE.warning],
    desc: 'Te faltan bananos para un tiro extra. Tu giro gratis vuelve pronto.',
    cardCta: ['Ver mi giro', 'secondary', 'open-daily'],
    modalCta: ['Bananos insuficientes', 'secondary', null],
    note: '',
  },
  'paid-limit': {
    badge: ['Límite diario alcanzado', TONE.neutral],
    desc: 'Usaste todos tus tiros extra de hoy. Vuelve tras el reset.',
    cardCta: ['Ver mi giro', 'secondary', 'open-daily'],
    modalCta: ['Sin tiros por hoy', 'secondary', null],
    note: '',
  },
  cooldown: {
    badge: ['Giro usado', TONE.neutral],
    desc: 'Ya giraste hoy. Tu siguiente giro gratis se activa en el reset.',
    cardCta: ['Ver mi giro', 'secondary', 'open-daily'],
    modalCta: ['Vuelve en el reset', 'secondary', null],
    note: '',
  },
  spinning: {
    badge: ['Girando…', TONE.info],
    desc: 'La ruleta está en marcha. ¡Suerte!',
    cardCta: ['Girando…', 'primary', null],
    modalCta: ['Girando…', 'primary', null],
    note: '',
  },
  error: {
    badge: ['Sin conexión', TONE.danger],
    desc: 'No pudimos consultar tu giro. Revisa tu conexión e inténtalo de nuevo.',
    cardCta: ['Reintentar', 'secondary', 'retry'],
    modalCta: ['Reintentar', 'secondary', 'retry'],
    note: 'No pudimos consultar tu giro.',
  },
});

const DAILY_RESET_STATES = new Set(['paid', 'paid-insufficient', 'paid-limit', 'cooldown']);
const DAILY_OFFER_STATES = new Set(['free', 'bonus', 'paid']);

/**
 * Selección efectiva de pool a partir de la preferencia local y la
 * configuración vigente: un filtro guardado que el admin apagó (o que ya no
 * tiene objetos) cae a "Todos". El filtro manda sobre el modo, igual que en
 * el servidor. `percent` es null sin datos (invitado / cargando).
 */
function resolveDailyPicks(d, picks) {
  const p = picks || DEFAULT_PICKS;
  const cfg = d ? d.spinConfig : null;
  const mode = p.mode === SPIN_MODE.noTracks ? SPIN_MODE.noTracks : SPIN_MODE.all;
  const filter = cfg && p.filter ? cfg.filters.find((f) => f.key === p.filter) || null : null;
  let percent = null;
  if (filter) percent = filter.percent;
  else if (cfg) percent = cfg.modes[mode].percent;
  return { mode, filter, percent };
}

/** Items del preview elegibles bajo la selección (con fallback al total si queda vacío). */
function filterPreviewItems(items, picks) {
  if (!Array.isArray(items) || items.length === 0) return [];
  let out = items;
  if (picks.filter) out = items.filter((it) => it && it.typeKey === picks.filter.key);
  else if (picks.mode === SPIN_MODE.noTracks) out = items.filter((it) => it && it.isTrack !== true);
  return out.length > 0 ? out : items;
}

function formatFilterOption(label, percent, count) {
  return `${label} (${percent}% OFF) - ${formatInt(count)} ${count === 1 ? 'ítem' : 'ítems'}`;
}

/**
 * ViewModel del selector de pool (barra de filtros + toggle Todo / Sin Pistas).
 * Las opciones se generan desde `spinConfig.filters` (sólo activas en admin);
 * la primera es siempre "Todos" con el porcentaje/conteo del modo vigente.
 */
function derivePicker(d, picks, s) {
  const cfg = d ? d.spinConfig : null;
  const enabled = !!cfg && !s.action;
  const options = [];
  if (cfg) {
    const modeCfg = cfg.modes[picks.mode];
    options.push({
      key: '',
      label: 'Todos',
      meta: `${modeCfg.percent}% OFF · ${formatInt(modeCfg.count)} ítems`,
      text: formatFilterOption('Todos', modeCfg.percent, modeCfg.count),
      selected: picks.filter === null,
      isBundle: false,
    });
    for (const f of cfg.filters) {
      options.push({
        key: f.key,
        label: f.label,
        meta: `${f.percent}% OFF · ${formatInt(f.count)} ítems`,
        text: formatFilterOption(f.label, f.percent, f.count),
        selected: picks.filter !== null && picks.filter.key === f.key,
        isBundle: f.isBundle,
      });
    }
  }
  return {
    enabled,
    hasFilters: !!cfg && cfg.filters.length > 0,
    open: enabled && s.menuOpen === true,
    filterKey: picks.filter ? picks.filter.key : '',
    filterLabel: picks.filter ? picks.filter.label : 'Filtros',
    options,
    mode: picks.mode,
    modeMuted: picks.filter !== null,
    discount: picks.percent === null ? '—' : `${picks.percent}%`,
    discountLabel: picks.filter ? picks.filter.label : (picks.mode === SPIN_MODE.noTracks ? 'Sin Pistas' : 'Descuento'),
    warning: cfg && s.pendingFilter ? { key: s.pendingFilter, text: BUNDLE_WARNING } : null,
  };
}

/**
 * Confirmación previa a un giro que consume bananos. Sólo existe en estado
 * `paid`: los giros gratis y los tiros bonus no la necesitan.
 */
function derivePaidConfirm(d) {
  const price = formatCoins(d.paidPrice);
  return {
    text: `¿Deseas gastar ${price} para realizar un giro extra?`,
    hint: `Saldo actual: ${formatCoins(d.coins)}. Te quedarán ${formatCoins(Math.max(0, d.coins - d.paidPrice))}.`,
    cta: `Sí, gastar ${price}`,
  };
}

/** Meta del Giro Diario según el estado derivado de los datos (nunca null). */
function dailyMeta(state, d, now) {
  switch (state) {
    case 'guest':
      return staticMeta('Giro gratis', 'Cada día');
    case 'unverified':
      return staticMeta('Requisito', 'Cuenta verificada');
    case 'free':
      return countdownMeta('Tu giro vence en', d.nextResetMs, now, 'Actualizando…', staticMeta('Giro gratis', 'Disponible ahora'));
    case 'bonus':
      return staticMeta('Tiros extra', `${formatInt(d.bonusSpins)} ${d.bonusSpins === 1 ? 'disponible' : 'disponibles'}`);
    default:
      if (DAILY_RESET_STATES.has(state)) {
        return countdownMeta('Giro gratis en', d.nextResetMs, now, 'Actualizando…', staticMeta('Giro gratis', 'Tras el reset'));
      }
      return staticMeta('Giro gratis en', PENDING_TIME);
  }
}

/** Resuelve el estado finito del Giro Diario a partir de los hechos. Función total. */
function resolveDailyState(s) {
  const empty = resolveEmptyState(s);
  if (empty) return empty;
  if (s.action === 'spinning') return 'spinning';
  const d = s.data;
  if (d.session !== 'user') return 'guest';
  if (!d.eligible) return 'unverified';
  if (d.freeAvailable) return 'free';
  if (d.bonusSpins > 0) return 'bonus';
  if (!d.paidEnabled) return 'cooldown';
  if (d.paidUsed >= d.paidLimit) return 'paid-limit';
  if (d.coins < d.paidPrice) return 'paid-insufficient';
  return 'paid';
}

/**
 * (estado de la store, ahora) → ViewModel del Giro Diario.
 * Función pura y total: mismo input, mismo output; nunca lanza. Alimenta
 * tarjeta y modal.
 */
function deriveDaily(s, now) {
  const state = resolveDailyState(s);
  const copy = DAILY_COPY[state];
  const d = s.data && s.data.session === 'user' ? s.data : null;
  // Mientras gira, el meta sigue reflejando el estado de los datos (sin saltos).
  const dataState = state === 'spinning' ? resolveDailyState({ ...s, action: null }) : state;
  const picks = resolveDailyPicks(d, s.picks);
  const vm = {
    state,
    busy: state === 'loading' || state === 'spinning',
    badge: { text: copy.badge[0], tone: copy.badge[1] },
    desc: copy.desc,
    meta: dailyMeta(dataState, d, now),
    cardCta: cta(copy.cardCta),
    modalCta: cta(copy.modalCta),
    note: s.notice || copy.note,
    live: '',
    coins: d ? formatInt(d.coins) : '—',
    bonus: d ? formatInt(d.bonusSpins) : '—',
    flashOffer: null,
    expectFree: state === 'free',
    nick: d ? d.nick : '',
    previewItems: d ? filterPreviewItems(d.previewItems, picks) : [],
    picks,
    picker: derivePicker(d, picks, s),
    confirmSpin: null,
  };

  if (state === 'error') applyErrorCopy(vm, s);

  if (d) {
    if (state === 'bonus') {
      vm.badge = { text: `${formatInt(d.bonusSpins)} ${d.bonusSpins === 1 ? 'tiro extra' : 'tiros extra'}`, tone: TONE.info };
    }
    if (state === 'paid') {
      vm.badge = { text: `Tiro extra: ${formatCoins(d.paidPrice)}`, tone: TONE.info };
      vm.modalCta = { ...vm.modalCta, text: `Girar por ${formatCoins(d.paidPrice)}` };
      vm.note = s.notice || `Tiros extra hoy: ${formatInt(d.paidUsed)} de ${formatInt(d.paidLimit)}.`;
      if (s.confirmSpin === true) vm.confirmSpin = derivePaidConfirm(d);
    }
    if (state === 'paid-insufficient') {
      vm.note = s.notice || `Un tiro extra cuesta ${formatCoins(d.paidPrice)}.`;
    }
    if (state === 'paid-limit') {
      vm.note = s.notice || `Límite de ${formatInt(d.paidLimit)} tiros extra por día.`;
    }
    if (d.flashOffer && DAILY_OFFER_STATES.has(state)) {
      const f = d.flashOffer;
      vm.flashOffer = {
        text: `Oferta flash: −${f.percent}% en ${formatInt(f.limit - f.claimed)} de ${formatInt(f.limit)} premios`,
        percent: Math.min(100, Math.round((f.claimed / f.limit) * 100)),
      };
      if (state === 'free') vm.badge = { text: `Oferta flash −${f.percent}%`, tone: TONE.success };
    }
  }

  vm.live = s.notice || (state === 'loading' ? '' : `${vm.badge.text || 'Giro Diario'}. ${vm.desc}`);
  return vm;
}

/* ─────────────────────────── ViewModel: Sorteo Flash ───────────────── */

const FLASH_COPY = Object.freeze({
  loading: {
    badge: ['', TONE.none],
    desc: 'Consultando el sorteo en curso…',
    cardCta: ['Cargando…', 'primary', null],
    modalCta: ['Cargando…', 'primary', null],
    note: '',
  },
  none: {
    badge: ['Sin sorteos por ahora', TONE.neutral],
    desc: 'Anunciamos los próximos sorteos en la comunidad. ¡Atento!',
    cardCta: ['Próximamente', 'secondary', null],
    modalCta: ['Próximamente', 'secondary', null],
    note: 'Aún no hay un sorteo programado.',
  },
  next: {
    badge: ['Próximo sorteo', TONE.info],
    desc: 'El siguiente sorteo está programado. Prepárate para entrar.',
    cardCta: ['Ver detalles', 'secondary', 'open-flash'],
    modalCta: ['Ir a la sala', 'secondary', 'room'],
    note: 'Las inscripciones abrirán cuando termine la cuenta atrás.',
  },
  scheduled: {
    badge: ['Programado', TONE.info],
    desc: 'Las inscripciones abren en breve. Revisa los detalles.',
    cardCta: ['Ver detalles', 'secondary', 'open-flash'],
    modalCta: ['Ir a la sala', 'secondary', 'room'],
    note: 'Todavía no puedes unirte; espera a que abran las inscripciones.',
  },
  guest: {
    badge: ['¡Sorteo abierto!', TONE.success],
    desc: 'Hay un sorteo en curso. Inicia sesión para participar.',
    cardCta: ['Inicia sesión para entrar', 'secondary', 'login'],
    modalCta: ['Iniciar sesión', 'secondary', 'login'],
    note: 'Necesitas una cuenta para participar.',
  },
  open: {
    badge: ['¡Sorteo abierto!', TONE.success],
    desc: 'Inscripciones abiertas. Entra antes de que se cierre.',
    cardCta: ['Entrar al sorteo', 'primary', 'open-flash'],
    modalCta: ['Entrar al sorteo', 'primary', 'room'],
    note: 'La inscripción se confirma en la sala del sorteo.',
  },
  joined: {
    badge: ['Ya participas', TONE.success],
    desc: 'Estás dentro. Sigue el sorteo en vivo desde la sala.',
    cardCta: ['Ver sala', 'secondary', 'open-flash'],
    modalCta: ['Ver sala en vivo', 'primary', 'room'],
    note: 'Tu participación está confirmada. ¡Suerte!',
  },
  full: {
    badge: ['Cupos agotados', TONE.neutral],
    desc: 'Se llenaron todos los cupos de este sorteo.',
    cardCta: ['Ver sala', 'secondary', 'open-flash'],
    modalCta: ['Ver sala', 'secondary', 'room'],
    note: 'Ya no quedan cupos disponibles.',
  },
  review: {
    badge: ['En revisión', TONE.warning],
    desc: 'Tu participación está siendo revisada por el equipo.',
    cardCta: ['Ver sala', 'secondary', 'open-flash'],
    modalCta: ['Ver sala', 'secondary', 'room'],
    note: 'Participas bajo revisión; el equipo confirmará tu entrada.',
  },
  expelled: {
    badge: ['Retirado del sorteo', TONE.danger],
    desc: 'No puedes participar en este sorteo.',
    cardCta: ['Ver detalles', 'secondary', 'open-flash'],
    modalCta: ['Participación no disponible', 'secondary', null],
    note: 'Tu participación fue retirada de este sorteo.',
  },
  banned: {
    badge: ['Cuenta suspendida', TONE.danger],
    desc: 'Tu cuenta no puede participar en sorteos.',
    cardCta: ['Ver detalles', 'secondary', 'open-flash'],
    modalCta: ['Participación no disponible', 'secondary', null],
    note: 'Tu cuenta está suspendida para sorteos.',
  },
  resolving: {
    badge: ['Sorteando…', TONE.info],
    desc: 'Inscripciones cerradas. Se está eligiendo al ganador.',
    cardCta: ['Ver en vivo', 'secondary', 'open-flash'],
    modalCta: ['Ver en vivo', 'primary', 'room'],
    note: 'El resultado se muestra en la sala.',
  },
  completed: {
    badge: ['Finalizado', TONE.neutral],
    desc: 'El sorteo terminó. Consulta los resultados.',
    cardCta: ['Ver resultados', 'secondary', 'open-flash'],
    modalCta: ['Ver resultados', 'secondary', 'room'],
    note: '',
  },
  cancelled: {
    badge: ['Cancelado', TONE.neutral],
    desc: 'Este sorteo fue cancelado.',
    cardCta: ['Ver detalles', 'secondary', 'open-flash'],
    modalCta: ['Ir a la sala', 'secondary', 'room'],
    note: 'El sorteo fue cancelado por la organización.',
  },
  error: {
    badge: ['Sin conexión', TONE.danger],
    desc: 'No pudimos consultar el sorteo. Inténtalo de nuevo.',
    cardCta: ['Reintentar', 'secondary', 'retry'],
    modalCta: ['Reintentar', 'secondary', 'retry'],
    note: '',
  },
});

/** Estados que muestran la cuenta atrás hasta el cierre del sorteo vigente. */
const FLASH_CLOSING_STATES = new Set(['open', 'guest', 'joined', 'full', 'review', 'expelled', 'banned']);
/** Estados que muestran la cuenta atrás hasta la apertura del próximo sorteo. */
const FLASH_UPCOMING_STATES = new Set(['next', 'completed', 'cancelled']);
const FLASH_CLOSED_STATUSES = new Set(['locking', 'processing', 'waiting_for_winner']);

const FLASH_NEXT_PENDING = staticMeta('Próximo sorteo', 'Por anunciar');

/** Meta del Sorteo Flash según estado (nunca null). */
function flashMeta(state, d, r, now) {
  const nextStart = d && d.next ? d.next.startMs : null;
  if (state === 'loading' || state === 'error') return staticMeta('Tiempo', PENDING_TIME);
  if (state === 'none' || state === 'next') {
    return countdownMeta('Próximo sorteo en', nextStart, now, 'Abriendo…', FLASH_NEXT_PENDING);
  }
  if (state === 'scheduled') {
    return countdownMeta('Abre en', r.startMs, now, 'Abriendo…', staticMeta('Apertura', 'Por anunciar'));
  }
  if (FLASH_CLOSING_STATES.has(state)) {
    return countdownMeta('Cierra en', r.endMs, now, 'Cerrando…', staticMeta('Cierre', 'Por anunciar'));
  }
  if (state === 'resolving') return staticMeta('Sorteo', 'Eligiendo ganador…');
  // completed | cancelled
  const afterwards = state === 'completed'
    ? staticMeta('Participaron', formatInt(r.participants))
    : FLASH_NEXT_PENDING;
  return countdownMeta('Próximo sorteo en', nextStart, now, 'Abriendo…', afterwards);
}

/**
 * Resuelve el estado finito del Sorteo Flash. Función total. La hora del
 * cliente (calibrada) decide `open` → `resolving` cuando el cierre ya venció y
 * el servidor aún no lo materializó (el cierre lo dispara la sala/cron, no
 * este endpoint).
 */
function resolveFlashState(s, now) {
  const empty = resolveEmptyState(s);
  if (empty) return empty;
  const d = s.data;
  const r = d.raffle;
  if (!r) return d.next ? 'next' : 'none';

  if (r.status === 'completed') return 'completed';
  if (r.status === 'cancelled') return 'cancelled';
  if (FLASH_CLOSED_STATUSES.has(r.status)) return 'resolving';
  if (r.status === 'scheduled') return 'scheduled';

  // r.status === 'open'
  if (r.endMs !== null && now >= r.endMs) return 'resolving';
  if (!d.loggedIn) return 'guest';
  if (d.banned) return 'banned';
  if (r.myReview === 'expelled') return 'expelled';
  if (r.myReview === 'under_review') return 'review';
  if (r.myReview === 'active') return 'joined';
  if (r.slotsLeft === 0) return 'full';
  return 'open';
}

/** (estado de la store, ahora) → ViewModel del Sorteo Flash. Pura y total. */
function deriveFlash(s, now) {
  const state = resolveFlashState(s, now);
  const copy = FLASH_COPY[state];
  const d = s.data;
  const r = d ? d.raffle : null;

  const vm = {
    state,
    busy: state === 'loading',
    badge: { text: copy.badge[0], tone: copy.badge[1] },
    title: r && r.title ? r.title : 'Sorteo Flash',
    desc: copy.desc,
    meta: flashMeta(state, d, r, now),
    cardCta: cta(copy.cardCta),
    modalCta: cta(copy.modalCta),
    note: s.notice || copy.note,
    live: '',
    stats: { timeLabel: 'Tiempo', time: '—', slots: '—', cost: '—' },
    roomHref: ROOM_HREF,
  };

  if (state === 'error') applyErrorCopy(vm, s);

  if (r) {
    vm.stats.cost = r.entryCost > 0 ? formatCoins(r.entryCost) : 'Gratis';
    vm.stats.slots = r.slotsTotal > 0
      ? `${formatInt(r.participants)} / ${formatInt(r.slotsTotal)}`
      : `${formatInt(r.participants)} (sin límite)`;

    if (state === 'open') {
      vm.desc = r.slotsTotal > 0
        ? `Quedan ${formatInt(r.slotsLeft)} ${r.slotsLeft === 1 ? 'cupo' : 'cupos'}. Entrada: ${vm.stats.cost.toLowerCase()}.`
        : `Cupos ilimitados. Entrada: ${vm.stats.cost.toLowerCase()}.`;
      vm.modalCta = {
        ...vm.modalCta,
        text: r.entryCost > 0 ? `Entrar (${formatCoins(r.entryCost)})` : 'Entrar gratis',
      };
    }
    if (state === 'full') {
      vm.desc = `Se llenaron los ${formatInt(r.slotsTotal)} cupos de este sorteo.`;
    }
    if (state === 'resolving' && r.myReview === 'active') {
      vm.desc = 'Participas en este sorteo. Sigue el resultado en vivo.';
    }
    if (state === 'completed' && r.myWon) {
      vm.badge = { text: '¡Ganaste!', tone: TONE.success };
      vm.desc = 'Resultaste ganador en este sorteo. Revisa tu premio en la sala.';
      vm.modalCta = { ...vm.modalCta, text: 'Ver mi premio', tone: 'primary' };
    }
  }

  if (FLASH_UPCOMING_STATES.has(state) && state !== 'next' && vm.meta.deadline !== null) {
    vm.desc = `${vm.desc} El próximo sorteo ya está programado.`;
  }
  if (vm.meta.deadline !== null) {
    vm.stats.timeLabel = vm.meta.label;
    vm.stats.time = vm.meta.value;
  }

  vm.live = s.notice || (state === 'loading' ? '' : `${vm.badge.text || 'Sorteo Flash'}. ${vm.desc}`);
  return vm;
}

/* ─────────────────────────────── render ────────────────────────────── */

/** Escrituras idempotentes: sólo tocan el DOM si el valor cambia. */
function setText(el, text) {
  if (el && el.textContent !== text) el.textContent = text;
}
function setAttr(el, name, value) {
  if (!el) return;
  if (value === null || value === undefined || value === false) {
    if (el.hasAttribute(name)) el.removeAttribute(name);
  } else if (el.getAttribute(name) !== String(value)) {
    el.setAttribute(name, String(value));
  }
}
function setHidden(el, hidden) {
  if (el && el.hidden !== hidden) el.hidden = hidden;
}

/** Mapa nombre → nodo de los slots de una raíz (resuelto una sola vez). */
function collectSlots(root) {
  const slots = {};
  if (!root) return slots;
  for (const el of root.querySelectorAll('[data-gh-slot]')) {
    slots[el.dataset.ghSlot] = el;
  }
  return slots;
}

function renderCta(el, model) {
  if (!el) return;
  setText(el, model.text);
  setAttr(el, 'data-tone', model.tone);
  setAttr(el, 'data-action', model.enabled ? model.action : null);
  if (el.disabled !== !model.enabled) el.disabled = !model.enabled;
}

function renderBadge(el, badge) {
  if (!el) return;
  setText(el, badge.text);
  setAttr(el, 'data-tone', badge.text ? badge.tone : TONE.none);
}

function renderMeta(slots, meta) {
  const value = slots['meta-value'];
  setText(slots['meta-label'], meta.label);
  setText(value, meta.value);
  // Cuenta atrás: role="timer" y lectura por minutos (no por segundo).
  setAttr(value, 'role', meta.deadline !== null ? 'timer' : null);
  setAttr(value, 'aria-label', meta.spoken ? `${meta.label} ${meta.spoken}` : null);
}

/** Tarjeta exterior (misma forma para ambos juegos). */
function renderCard(card, slots, vm) {
  setAttr(card, 'data-state', vm.state);
  setAttr(card, 'aria-busy', vm.busy ? 'true' : null);
  renderBadge(slots.badge, vm.badge);
  setText(slots.desc, vm.desc);
  renderMeta(slots, vm.meta);
  renderCta(slots.cta, vm.cardCta);
  setText(slots.live, vm.live);
}

function setDisabled(el, disabled) {
  if (el && el.disabled !== disabled) el.disabled = disabled;
}

/** Crea una opción del desplegable de filtros (nombre + "% OFF · N ítems"). */
function createFilterOption(option) {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'ds-filter__opt';
  btn.setAttribute('role', 'option');
  btn.dataset.action = 'pick-filter';
  btn.dataset.filterKey = option.key;
  const name = document.createElement('span');
  name.className = 'ds-filter__opt-name';
  const meta = document.createElement('span');
  meta.className = 'ds-filter__opt-meta';
  btn.append(name, meta);
  return btn;
}

/**
 * Reconcilia las opciones del listbox por clave: reutiliza nodos existentes,
 * crea los que falten y elimina los sobrantes. El texto se escribe en dos
 * spans (nombre / meta) y `aria-label` lleva el formato completo
 * "Nombre (X% OFF) - N ítems".
 */
function renderFilterOptions(menu, options) {
  if (!menu) return;
  const existing = new Map();
  for (const el of menu.children) existing.set(el.dataset.filterKey ?? '', el);
  options.forEach((option, index) => {
    let el = existing.get(option.key);
    if (el) existing.delete(option.key);
    else el = createFilterOption(option);
    if (menu.children[index] !== el) menu.insertBefore(el, menu.children[index] || null);
    setText(el.firstElementChild, option.label);
    setText(el.lastElementChild, option.meta);
    setAttr(el, 'aria-label', option.text);
    setAttr(el, 'aria-selected', option.selected ? 'true' : 'false');
    setAttr(el, 'data-bundle', option.isBundle ? 'true' : null);
  });
  for (const el of existing.values()) el.remove();
}

/** Barra de filtros + toggle Todo / Sin Pistas + aviso de Lotes. */
function renderPicker(slots, warn, picker) {
  const btn = slots['filter-btn'];
  setDisabled(btn, !picker.enabled || !picker.hasFilters);
  setAttr(btn, 'aria-expanded', picker.open ? 'true' : 'false');
  if (btn) btn.classList.toggle('is-active', picker.filterKey !== '');
  setText(slots['filter-label'], picker.filterLabel);
  setHidden(slots['filter-menu'], !picker.open);
  renderFilterOptions(slots['filter-menu'], picker.options);

  const value = slots['discount-value'];
  if (value && value.textContent !== picker.discount) {
    value.textContent = picker.discount;
    value.classList.remove('is-bump');
    void value.offsetWidth;
    value.classList.add('is-bump');
  }
  setText(slots['discount-label'], picker.discountLabel);
  setAttr(slots.discount, 'aria-label', picker.discount === '—' ? 'Descuento no disponible' : `Descuento activo ${picker.discount}`);

  setAttr(slots.mode, 'aria-disabled', picker.modeMuted ? 'true' : null);
  setDisabled(slots['mode-all'], !picker.enabled);
  setDisabled(slots['mode-no-tracks'], !picker.enabled);
  setAttr(slots['mode-all'], 'aria-pressed', picker.mode === SPIN_MODE.all ? 'true' : 'false');
  setAttr(slots['mode-no-tracks'], 'aria-pressed', picker.mode === SPIN_MODE.noTracks ? 'true' : 'false');

  setHidden(warn, !picker.warning);
  if (picker.warning) setText(slots['warn-text'], picker.warning.text);
}

/** Modal de Giro Diario (#ds-screen-surface). */
function renderDailyModal(surface, slots, vm) {
  setAttr(surface, 'data-state', vm.state);
  setAttr(surface, 'aria-busy', vm.busy ? 'true' : null);
  setText(slots.desc, vm.desc);
  setText(slots.note, vm.note);
  renderCta(slots.cta, vm.modalCta);
  renderMeta(slots, vm.meta);
  setText(slots.coins, vm.coins);
  setText(slots.bonus, vm.bonus);
  setHidden(slots.flash, !vm.flashOffer);
  if (vm.flashOffer) {
    setText(slots['flash-text'], vm.flashOffer.text);
    const bar = slots['flash-progress'];
    if (bar && bar.value !== vm.flashOffer.percent) bar.value = vm.flashOffer.percent;
  }
  renderPicker(slots, dom ? dom.dailyWarn : null, vm.picker);
  renderPaidConfirm(slots, dom ? dom.dailyConfirm : null, vm.confirmSpin);
}

/** Modal de confirmación del giro pagado (#ds-confirm-modal). */
function renderPaidConfirm(slots, modal, confirm) {
  setHidden(modal, !confirm);
  if (!confirm) return;
  setText(slots['confirm-text'], confirm.text);
  setText(slots['confirm-hint'], confirm.hint);
  setText(slots['confirm-spin'], confirm.cta);
}

/** Dialog de Sorteo Flash (#gh-flash-dialog). */
function renderFlashDialog(dialog, slots, vm) {
  setAttr(dialog, 'data-state', vm.state);
  setAttr(dialog, 'aria-busy', vm.busy ? 'true' : null);
  renderBadge(slots.badge, vm.badge);
  setText(slots.title, vm.title);
  setText(slots.desc, vm.desc);
  setText(slots['stat-time-label'], vm.stats.timeLabel);
  setText(slots['stat-time'], vm.stats.time);
  setText(slots['stat-slots'], vm.stats.slots);
  setText(slots['stat-cost'], vm.stats.cost);
  setText(slots.note, vm.note);
  renderCta(slots.cta, vm.modalCta);
}

/* ─────────────────────────────── recurso ───────────────────────────── */

/**
 * Recurso remoto con número de secuencia (descarta respuestas fuera de orden)
 * y refresco programado. `load()` nunca lanza: los fallos se vuelcan en la
 * store como `phase: 'error'` conservando los últimos datos válidos. El
 * transporte cuelga de la señal de la sesión activa (`getSignal`); sin sesión
 * no hay lectura.
 */
function createResource({ name, store, clock, fetcher, normalize, nextRefreshMs, getSignal, onData }) {
  let seq = 0;
  let refreshTimer = 0;
  let deadlineRetries = 0;
  let lastLoadedAt = 0;

  const clearRefresh = () => {
    if (refreshTimer) window.clearTimeout(refreshTimer);
    refreshTimer = 0;
  };

  const scheduleRefresh = (ms) => {
    clearRefresh();
    if (!Number.isFinite(ms) || ms <= 0 || document.visibilityState === 'hidden') return;
    refreshTimer = window.setTimeout(() => {
      refreshTimer = 0;
      load();
    }, ms);
  };

  async function load() {
    const signal = getSignal();
    if (!signal || signal.aborted) return;
    const mySeq = ++seq;
    clearRefresh();
    store.set({ phase: 'loading' });

    let data;
    try {
      const raw = await fetcher(signal);
      if (mySeq !== seq || signal.aborted) return;
      data = normalize(raw);
    } catch (err) {
      if (mySeq !== seq || signal.aborted) return;
      const kind = classifyError(err);
      // Un aborto que no viene de la sesión es el timeout interno del gateway.
      const error = kind === 'aborted' ? 'network' : kind;
      if (error !== 'network') console.error(`[juegos] ${name}`, err);
      store.set({ phase: 'error', error });
      scheduleRefresh(REFRESH_MS.afterError);
      return;
    }

    lastLoadedAt = Date.now();
    if (data.serverTimeUtc) clock.calibrate(data.serverTimeUtc);
    // `notice` (mensaje de un rechazo del servidor) sobrevive a la relectura
    // que lo sigue; lo limpia la siguiente acción del usuario.
    store.set({ phase: 'ready', error: null, data });
    if (onData) onData(data);

    // `null` ⇒ la cuenta atrás ya venció y el servidor aún no cambió de
    // estado: el reloj llamará a `onDeadline`, que reintenta con backoff.
    scheduleRefresh(nextRefreshMs(data, clock.now()));
  }

  const isStale = () => Date.now() - lastLoadedAt > STALE_MS;

  /** Relee al volver (pestaña/vista) si los datos envejecieron o no hay refresco programado. */
  const resume = () => {
    if (store.get().phase !== 'loading' && (isStale() || !refreshTimer)) load();
  };

  return {
    load,
    resume,
    pause: clearRefresh,
    /** Primera lectura o reanudación, según haya datos previos. */
    start() {
      if (store.get().data) resume(); else load();
    },
    /** Llamado por el reloj en cada tick posterior al vencimiento de una cuenta atrás. */
    onDeadline() {
      if (refreshTimer || store.get().phase === 'loading') return;
      const wait = DEADLINE_BACKOFF_MS[Math.min(deadlineRetries, DEADLINE_BACKOFF_MS.length - 1)];
      deadlineRetries += 1;
      scheduleRefresh(wait);
    },
    resetDeadlineBackoff() {
      deadlineRetries = 0;
    },
    /**
     * Cierre de sesión: invalida respuestas en vuelo (la señal ya está
     * abortada) y devuelve la store a un estado en reposo coherente con sus
     * datos, para que el siguiente `start` decida entre releer o reanudar.
     */
    dispose() {
      seq += 1;
      clearRefresh();
      const s = store.get();
      if (s.phase === 'loading') store.set({ phase: s.data ? 'ready' : 'idle' });
    },
  };
}

/* ─────────────────────────────── panel ─────────────────────────────── */

/**
 * Un panel = tarjeta exterior + modal de un juego, pintados desde el mismo
 * ViewModel en la misma pasada. Recuerda el vencimiento de su cuenta atrás
 * para que el reloj sepa si debe seguir latiendo y cuándo reintentar.
 */
function createPanel({ store, resource, derive, card, modal, renderModal, getNow }) {
  const cardSlots = collectSlots(card);
  const modalSlots = collectSlots(modal);
  let deadline = null;

  const paint = (now = getNow()) => {
    const vm = derive(store.get(), now);
    if (card) renderCard(card, cardSlots, vm);
    if (modal) renderModal(modal, modalSlots, vm);
    const next = vm.meta.deadline;
    if (next !== deadline) resource.resetDeadlineBackoff();
    deadline = next;
    return vm;
  };

  /** Tick del reloj: repinta la cuenta atrás y avisa al recurso si venció. */
  const tick = (now) => {
    if (deadline === null) return;
    paint(now);
    if (deadline !== null && now >= deadline) resource.onDeadline();
  };

  return {
    store,
    resource,
    modalSlots,
    paint,
    tick,
    get deadline() {
      return deadline;
    },
  };
}

/* ─────────────────────────────── módulo ────────────────────────────── */

const dailyStore = createStore({
  ...initialResourceState(),
  picks: DEFAULT_PICKS,
  menuOpen: false,
  pendingFilter: null,
  confirmSpin: false,
});
const flashStore = createStore(initialResourceState());

/** Sesión de montaje activa (AbortController) o null si la vista no está montada. */
let session = null;
let api = null;
let dom = null;
let clock = null;
let dailyPanel = null;
let flashPanel = null;
let panels = [];
/** Elemento que abrió el dialog de Sorteo Flash; recupera el foco al cerrarlo. */
let flashOpener = null;

const activeSignal = () => (session ? session.signal : null);

function resolveDom(outlet) {
  const section = outlet ? outlet.querySelector('.gh') : null;
  return {
    section,
    dailyCard: section ? section.querySelector('[data-gh-game="daily"]') : null,
    flashCard: section ? section.querySelector('[data-gh-game="flash"]') : null,
    flashDialog: document.getElementById('gh-flash-dialog'),
    dailySurface: document.getElementById('ds-screen-surface'),
    dailyOverlay: document.getElementById('ds-modal-overlay'),
    dailyWarn: document.getElementById('ds-warn-modal'),
    dailyConfirm: document.getElementById('ds-confirm-modal'),
  };
}

/** Motor de la ruleta (global heredado), sólo si expone el contrato esperado. */
function dailySpin() {
  const ds = window.DailySpin;
  return ds && typeof ds.runAnimation === 'function' ? ds : null;
}

function paintAll(now = clock.now()) {
  for (const panel of panels) panel.paint(now);
}

/* ── reloj ── */

function syncClock() {
  const needed = panels.some((panel) => panel.deadline !== null);
  if (session && needed && document.visibilityState === 'visible') clock.start();
  else clock.stop();
}

function onTick(now) {
  for (const panel of panels) panel.tick(now);
  syncClock();
}

/* ── acciones ── */

/** Firma del pool visible en la cinta idle; cambia sólo si cambia la selección. */
let idlePoolKey = '';

const picksKey = (vm) => `${vm.picks.mode}|${vm.picks.filter ? vm.picks.filter.key : ''}`;

/** Regenera la cinta idle cuando el usuario cambia de modo/filtro con el modal abierto. */
function syncIdlePool(vm) {
  const ds = dailySpin();
  if (!ds || typeof ds.setIdlePool !== 'function' || !ds.isOpen()) return;
  const key = picksKey(vm);
  if (key === idlePoolKey) return;
  idlePoolKey = key;
  ds.setIdlePool(vm.previewItems);
}

function openDailyModal() {
  const ds = dailySpin();
  if (!ds || !session) return;
  dailyStore.set({ notice: null, menuOpen: false, pendingFilter: null, confirmSpin: false });
  const vm = dailyPanel.paint();
  idlePoolKey = picksKey(vm);
  ds.hydrate({ previewItems: vm.previewItems, nick: vm.nick });
  ds.openModal();
  const ctaEl = dailyPanel.modalSlots.cta;
  const focusTarget = ctaEl && !ctaEl.disabled ? ctaEl : document.getElementById('ds-close-btn');
  if (focusTarget) focusTarget.focus({ preventScroll: true });
}

/**
 * El <dialog> aporta su propia modalidad (top layer, Esc, inert del resto del
 * documento); el velo y el scroll-lock los comparte con el resto de modales vía
 * window.Backdrop. `inertMain: false` porque el dialog vive dentro de
 * #main-content y un inert ahí lo dejaría inoperativo.
 */
function openFlashDialog(opener) {
  const dialog = dom.flashDialog;
  if (!dialog || dialog.open) return;
  flashOpener = opener instanceof HTMLElement ? opener : null;
  if (typeof dialog.showModal === 'function') dialog.showModal();
  else dialog.setAttribute('open', '');
  window.Backdrop?.acquire(FLASH_BACKDROP_CLIENT, dialog, closeFlashDialog, { lockScroll: true, inertMain: false });
  const ctaEl = flashPanel.modalSlots.cta;
  const focusTarget = ctaEl && !ctaEl.disabled ? ctaEl : dialog.querySelector('[data-gh-action="close-flash"]');
  if (focusTarget) focusTarget.focus({ preventScroll: true });
}

function closeFlashDialog() {
  const dialog = dom.flashDialog;
  if (!dialog || !dialog.open) return;
  if (typeof dialog.close === 'function') dialog.close();
  else {
    dialog.removeAttribute('open');
    onFlashDialogClosed();
  }
}

/** `close` nativo del dialog (botón, Esc o backdrop): libera el velo y devuelve el foco al disparador. */
function onFlashDialogClosed() {
  window.Backdrop?.release(FLASH_BACKDROP_CLIENT);
  const opener = flashOpener;
  flashOpener = null;
  if (opener && opener.isConnected && session) opener.focus({ preventScroll: true });
}

/**
 * Giro: bloquea tarjeta y modal (`action: 'spinning'`) desde el clic hasta que
 * DailySpin notifica el premio (`onSpinSettled`), pausa las relecturas para
 * que ninguna hidratación toque la cinta mientras gira y vuelca cualquier
 * rechazo del servidor como `notice`. Un giro que consume bananos pasa antes
 * por el modal de confirmación (`confirmSpin`); sólo `confirmed` llama al
 * endpoint.
 */
async function spin(confirmed = false) {
  const ds = dailySpin();
  const signal = activeSignal();
  if (!ds || !signal || ds.isSpinning) return;
  const vm = deriveDaily(dailyStore.get(), clock.now());
  if (!vm.modalCta.enabled || vm.modalCta.action !== 'spin') return;
  if (vm.state === 'paid' && !confirmed) {
    dailyStore.set({ confirmSpin: true, menuOpen: false, pendingFilter: null });
    const btn = dailyPanel.modalSlots['confirm-spin'];
    if (btn) btn.focus({ preventScroll: true });
    return;
  }
  const { resource } = dailyPanel;

  ds.prepareSpin();
  resource.pause();
  dailyStore.set({ action: 'spinning', notice: null, menuOpen: false, pendingFilter: null, confirmSpin: false });

  const fail = (notice) => {
    dailyStore.set({ action: null, notice });
    resource.load();
  };

  let raw;
  try {
    raw = await api.games.spinGo({
      type: 'normal',
      expectFree: vm.expectFree,
      mode: vm.picks.mode,
      filter: vm.picks.filter ? vm.picks.filter.key : '',
    }, signal);
  } catch (err) {
    if (signal.aborted) return;
    fail((ERROR_COPY[classifyError(err)] || ERROR_COPY.http).desc);
    return;
  }
  if (signal.aborted) return;

  if (!raw || typeof raw !== 'object') return fail(ERROR_COPY.invalid.desc);
  if (raw.is_logged === false) return fail('Tu sesión expiró. Inicia sesión para girar.');
  if (raw.success !== true || !raw.data || !Array.isArray(raw.data.tape)) {
    return fail(toText(raw.message, 'No se pudo realizar el giro.'));
  }
  ds.runAnimation(raw.data);
}

/** DailySpin ya mostró el premio: liberar el CTA y releer el estado real. */
function onSpinSettled() {
  if (!session) return;
  dailyStore.set({ action: null, notice: null });
  dailyPanel.resource.resetDeadlineBackoff();
  dailyPanel.resource.load();
}

/* ── selector de pool (modo + filtro) ── */

function setMode(mode) {
  const s = dailyStore.get();
  if (s.action) return;
  const next = mode === SPIN_MODE.noTracks ? SPIN_MODE.noTracks : SPIN_MODE.all;
  // Elegir un modo con un filtro activo vuelve a "Todos" bajo ese modo.
  if (s.picks.mode === next && s.picks.filter === '') return;
  dailyStore.set({ picks: { mode: next, filter: '' }, notice: null });
}

function toggleFilterMenu() {
  const s = dailyStore.get();
  if (s.action) return;
  dailyStore.set({ menuOpen: !s.menuOpen });
}

function closeFilterMenu() {
  if (dailyStore.get().menuOpen) dailyStore.set({ menuOpen: false });
}

/** Aplica el filtro; "Lotes" pasa antes por el aviso (`pendingFilter`). */
function pickFilter(key) {
  const s = dailyStore.get();
  if (s.action) return;
  const vm = deriveDaily(s, clock.now());
  const option = vm.picker.options.find((o) => o.key === key);
  if (!option) return;
  if (option.isBundle && key !== s.picks.filter) {
    dailyStore.set({ menuOpen: false, pendingFilter: key });
    const confirm = dailyPanel.modalSlots['warn-confirm'];
    if (confirm) confirm.focus({ preventScroll: true });
    return;
  }
  applyFilter(key);
}

function applyFilter(key) {
  const s = dailyStore.get();
  dailyStore.set({
    picks: s.picks.filter === key ? s.picks : { mode: s.picks.mode, filter: key },
    menuOpen: false,
    pendingFilter: null,
    notice: null,
  });
  const btn = dailyPanel.modalSlots['filter-btn'];
  if (btn) btn.focus({ preventScroll: true });
}

function confirmPendingFilter() {
  const key = dailyStore.get().pendingFilter;
  if (key) applyFilter(key);
}

function cancelPendingFilter() {
  if (dailyStore.get().pendingFilter === null) return;
  dailyStore.set({ pendingFilter: null });
  const btn = dailyPanel.modalSlots['filter-btn'];
  if (btn) btn.focus({ preventScroll: true });
}

/* ── confirmación del giro pagado ── */

function confirmPaidSpin() {
  if (dailyStore.get().confirmSpin !== true) return;
  spin(true);
}

function cancelPaidSpin() {
  if (dailyStore.get().confirmSpin !== true) return;
  dailyStore.set({ confirmSpin: false });
  const cta = dailyPanel.modalSlots.cta;
  if (cta) cta.focus({ preventScroll: true });
}

function performAction(action, panel, trigger) {
  switch (action) {
    case 'open-daily': openDailyModal(); break;
    case 'open-flash': openFlashDialog(trigger); break;
    case 'close-flash': closeFlashDialog(); break;
    case 'spin': spin(); break;
    case 'set-mode': setMode(trigger.dataset.mode); break;
    case 'toggle-filters': toggleFilterMenu(); break;
    case 'pick-filter': pickFilter(trigger.dataset.filterKey || ''); break;
    case 'confirm-filter': confirmPendingFilter(); break;
    case 'cancel-filter': cancelPendingFilter(); break;
    case 'confirm-spin': confirmPaidSpin(); break;
    case 'cancel-spin': cancelPaidSpin(); break;
    case 'login': window.location.assign(LOGIN_HREF); break;
    case 'account': window.location.assign(ACCOUNT_HREF); break;
    case 'room': window.location.assign(ROOM_HREF); break;
    case 'retry': panel.resource.load(); break;
    default: break;
  }
}

/** Delegación única: cualquier `[data-action]` / `[data-gh-action]` dentro de las raíces. */
function onDelegatedClick(event) {
  if (!session) return;
  const target = event.target instanceof Element ? event.target.closest('[data-action], [data-gh-action]') : null;
  if (!target || target.disabled) return;
  const action = target.dataset.action || target.dataset.ghAction;
  if (!action) return;
  const isFlash = target.closest('[data-gh-game="flash"], #gh-flash-dialog') !== null;
  event.preventDefault();
  performAction(action, isFlash ? flashPanel : dailyPanel, target);
}

/** Clic fuera del selector de filtros (dentro del modal) cierra el desplegable. */
function onDailyOverlayClick(event) {
  if (!session || !dailyStore.get().menuOpen) return;
  const inside = event.target instanceof Element && event.target.closest('[data-gh-slot="filter"]');
  if (!inside) closeFilterMenu();
}

/** Esc cierra primero el desplegable / aviso; si no hay nada abierto, sigue al Backdrop. */
function onDailyKeydown(event) {
  if (!session || event.key !== 'Escape') return;
  const s = dailyStore.get();
  if (s.confirmSpin === true) {
    event.stopPropagation();
    cancelPaidSpin();
  } else if (s.pendingFilter !== null) {
    event.stopPropagation();
    cancelPendingFilter();
  } else if (s.menuOpen) {
    event.stopPropagation();
    closeFilterMenu();
  }
}

/** Clic en el velo del aviso de Lotes (fuera del contenedor) lo cancela. */
function onWarnBackdropClick(event) {
  if (event.target === dom.dailyWarn) cancelPendingFilter();
}

/** Clic en el velo de la confirmación de giro pagado (fuera del contenedor) la cancela. */
function onConfirmBackdropClick(event) {
  if (event.target === dom.dailyConfirm) cancelPaidSpin();
}

function onVisibilityChange() {
  if (!session) return;
  if (document.visibilityState === 'visible') {
    paintAll();
    for (const panel of panels) {
      if (panel.store.get().action !== 'spinning') panel.resource.resume();
    }
  } else {
    for (const panel of panels) panel.resource.pause();
  }
  syncClock();
}

/* ── cadencias de refresco ── */

/**
 * Espera hasta `deadline` (con margen) acotada por `cap`; `null` si el
 * vencimiento ya pasó (el reintento pasa a manos del backoff del reloj).
 */
function untilDeadline(deadline, now, cap) {
  if (deadline === null) return cap;
  const remaining = deadline - now;
  if (remaining <= 0) return null;
  return Math.min(cap, remaining + 500);
}

function dailyNextRefresh(data, now) {
  if (data.session === 'user' && !data.freeAvailable) {
    return untilDeadline(data.nextResetMs, now, REFRESH_MS.dailyIdle);
  }
  return REFRESH_MS.dailyIdle;
}

function flashNextRefresh(data, now) {
  const r = data.raffle;
  if (!r) return untilDeadline(data.next ? data.next.startMs : null, now, REFRESH_MS.flashIdle);
  if (r.status === 'open') return untilDeadline(r.endMs, now, REFRESH_MS.flashOpen);
  if (r.status === 'scheduled') return untilDeadline(r.startMs, now, REFRESH_MS.flashScheduled);
  if (FLASH_CLOSED_STATUSES.has(r.status)) return REFRESH_MS.flashResolving;
  return REFRESH_MS.flashIdle;
}

/* ── ciclo de vida ── */

/** Una sola vez por página: DOM, reloj, recursos, paneles y delegación de eventos. */
function setup(ctx) {
  api = ctx.api;
  dom = resolveDom(ctx.outlet);
  clock = createClock(onTick);
  const getNow = () => clock.now();

  dailyPanel = createPanel({
    store: dailyStore,
    derive: deriveDaily,
    card: dom.dailyCard,
    modal: dom.dailySurface,
    renderModal: renderDailyModal,
    getNow,
    resource: createResource({
      name: 'spin_init',
      store: dailyStore,
      clock,
      fetcher: (signal) => api.games.spinInit(signal),
      normalize: normalizeSpin,
      nextRefreshMs: dailyNextRefresh,
      getSignal: activeSignal,
      onData(data) {
        const ds = dailySpin();
        if (!ds || data.session !== 'user') return;
        const vm = deriveDaily(dailyStore.get(), clock.now());
        ds.hydrate({ previewItems: vm.previewItems, nick: vm.nick });
      },
    }),
  });
  // El aviso de Lotes y la confirmación de giro pagado viven fuera de
  // #ds-screen-surface: sus slots se pintan con el mismo VM.
  Object.assign(dailyPanel.modalSlots, collectSlots(dom.dailyWarn), collectSlots(dom.dailyConfirm));
  flashPanel = createPanel({
    store: flashStore,
    derive: deriveFlash,
    card: dom.flashCard,
    modal: dom.flashDialog,
    renderModal: renderFlashDialog,
    getNow,
    resource: createResource({
      name: 'raffle_hub_status',
      store: flashStore,
      clock,
      fetcher: (signal) => api.games.raffleHubStatus(signal),
      normalize: normalizeFlash,
      nextRefreshMs: flashNextRefresh,
      getSignal: activeSignal,
    }),
  });
  panels = [dailyPanel, flashPanel];

  for (const panel of panels) {
    panel.store.subscribe(() => {
      const vm = panel.paint();
      if (panel === dailyPanel) syncIdlePool(vm);
      syncClock();
    });
  }

  // El DOM es persistente (keep-alive): los listeners viven con él.
  if (dom.section) dom.section.addEventListener('click', onDelegatedClick);
  if (dom.dailyOverlay) {
    dom.dailyOverlay.addEventListener('click', onDelegatedClick);
    dom.dailyOverlay.addEventListener('click', onDailyOverlayClick);
    dom.dailyOverlay.addEventListener('keydown', onDailyKeydown);
  }
  if (dom.dailyWarn) {
    dom.dailyWarn.addEventListener('click', onDelegatedClick);
    dom.dailyWarn.addEventListener('click', onWarnBackdropClick);
    dom.dailyWarn.addEventListener('keydown', onDailyKeydown);
  }
  if (dom.dailyConfirm) {
    dom.dailyConfirm.addEventListener('click', onDelegatedClick);
    dom.dailyConfirm.addEventListener('click', onConfirmBackdropClick);
    dom.dailyConfirm.addEventListener('keydown', onDailyKeydown);
  }
  if (dom.flashDialog) {
    dom.flashDialog.addEventListener('click', (event) => {
      // Clic en el backdrop (fuera del panel) cierra el dialog.
      if (event.target === dom.flashDialog) closeFlashDialog();
    });
    dom.flashDialog.addEventListener('close', onFlashDialogClosed);
  }

  const ds = dailySpin();
  if (ds) ds.onSpinSettled(onSpinSettled);
}

/** Núcleo puro (sin DOM) expuesto para pruebas unitarias: juegos.view.test.js. */
export const _pure = Object.freeze({
  initialResourceState,
  createStore,
  normalizeSpin,
  normalizeSpinConfig,
  normalizeFlash,
  resolveDailyState,
  resolveDailyPicks,
  filterPreviewItems,
  deriveDaily,
  resolveFlashState,
  deriveFlash,
  dailyNextRefresh,
  flashNextRefresh,
  formatCountdown,
  describeCountdown,
});

export default {
  name: 'juegos',

  async mount(ctx) {
    if (!dom) setup(ctx);
    if (session) return; // keep-alive: ya montada
    session = new AbortController();
    document.addEventListener('visibilitychange', onVisibilityChange, { signal: session.signal });

    paintAll();
    for (const panel of panels) panel.resource.start();
    syncClock();
  },

  unmount() {
    if (!session) return;
    const closing = session;
    session = null;
    closing.abort();
    clock.stop();
    closeFlashDialog();
    for (const panel of panels) {
      panel.resource.pause();
      panel.resource.dispose();
    }
    if (dailyStore.get().action === 'spinning') dailyStore.set({ action: null });
    dailyStore.set({ menuOpen: false, pendingFilter: null, confirmSpin: false });
  },
};

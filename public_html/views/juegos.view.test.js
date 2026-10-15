/**
 * Pruebas del núcleo puro de la vista /juegos (sin DOM).
 * Ejecutar: node --test public_html/views/juegos.view.test.js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { _pure } from './juegos.view.js';

const {
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
} = _pure;

const NOW = Date.parse('2026-09-06T12:00:00Z');
const iso = (offsetMs) => new Date(NOW + offsetMs).toISOString().replace(/\.\d{3}Z$/, 'Z');

const state = (patch) => ({ ...initialResourceState(), ...patch });

const userSpin = (overrides = {}) => normalizeSpin({
  success: true,
  is_logged: true,
  server_time_utc: iso(0),
  user_data: { nick: 'Cuy', verified: 1, coins: 120, bonus_spins: 0, ...overrides.user },
  spin_status: {
    free_spin_available: false,
    next_reset_utc: iso(3_600_000),
    paid_spins_enabled: 1,
    paid_spins_limit: 3,
    paid_spin_price: 50,
    user_paid_spins_count: 0,
    ...overrides.status,
  },
  event_config: { flash_offer: overrides.flash || null },
  preview_items: overrides.preview || [{ displayName: 'Skin' }],
  spin_config: overrides.config,
});

const SPIN_CONFIG = {
  modes: { all: { percent: 15, count: 300 }, no_tracks: { percent: 10, count: 175 } },
  filters: [
    { key: 'atuendo', label: 'Atuendos', percent: 5, count: 48, is_bundle: false },
    { key: 'gesto', label: 'Gestos', percent: 5, count: 24, is_bundle: false },
    { key: 'lote', label: 'Lotes', percent: 2, count: 20, is_bundle: true },
  ],
};

const PREVIEW = [
  { displayName: 'Skin', typeKey: 'atuendo', isTrack: false },
  { displayName: 'Track', typeKey: 'pista_de_improvisacion', isTrack: true },
  { displayName: 'Bundle', typeKey: 'lote', isTrack: false },
];

const hub = (raffle, extra = {}) => normalizeFlash({
  found: raffle !== null,
  server_time_utc: iso(0),
  is_logged_in: true,
  my_banned: false,
  raffle,
  next_scheduled: null,
  ...extra,
});

const openRaffle = (overrides = {}) => ({
  id: 7,
  title: 'Sorteo de prueba',
  status: 'open',
  start_time_utc: iso(-60_000),
  end_time_utc: iso(600_000),
  participants: 4,
  slots_total: 10,
  slots_left: 6,
  entry_cost: 0,
  my_review_status: null,
  my_won: false,
  ...overrides,
});

/* ── estados sin datos: nunca lanzan, siempre un ViewModel válido ── */

test('estado inicial (idle, sin datos) deriva a loading en ambos juegos', () => {
  const s = initialResourceState();
  assert.equal(resolveDailyState(s), 'loading');
  assert.equal(resolveFlashState(s, NOW), 'loading');
  const daily = deriveDaily(s, NOW);
  const flash = deriveFlash(s, NOW);
  assert.equal(daily.busy, true);
  assert.equal(daily.cardCta.enabled, false);
  assert.equal(daily.live, '');
  assert.deepEqual(daily.meta, { label: 'Giro gratis en', value: '--:--:--', spoken: null, deadline: null });
  assert.equal(flash.busy, true);
  assert.equal(flash.modalCta.enabled, false);
  assert.deepEqual(flash.meta, { label: 'Tiempo', value: '--:--:--', spoken: null, deadline: null });
});

test('loading sin datos y error sin datos', () => {
  assert.equal(resolveDailyState(state({ phase: 'loading' })), 'loading');
  const err = state({ phase: 'error', error: 'network' });
  assert.equal(resolveDailyState(err), 'error');
  assert.equal(resolveFlashState(err, NOW), 'error');
  const vm = deriveDaily(err, NOW);
  assert.equal(vm.badge.text, 'Sin conexión');
  assert.equal(vm.cardCta.action, 'retry');
  assert.equal(deriveFlash(state({ phase: 'error', error: 'http' }), NOW).badge.text, 'Servicio no disponible');
});

test('un error de refresco conserva los últimos datos válidos', () => {
  const s = state({ phase: 'error', error: 'network', data: userSpin() });
  assert.equal(resolveDailyState(s), 'paid');
});

/* ── Giro Diario ── */

test('spin_init guest → guest; usuario no verificado → unverified', () => {
  const guest = normalizeSpin({ is_logged: false, server_time_utc: iso(0) });
  assert.deepEqual(guest, { serverTimeUtc: iso(0), session: 'guest' });
  const vm = deriveDaily(state({ phase: 'ready', data: guest }), NOW);
  assert.equal(vm.state, 'guest');
  assert.equal(vm.cardCta.action, 'login');
  assert.equal(vm.coins, '—');

  const unverified = state({ phase: 'ready', data: userSpin({ user: { verified: 0 } }) });
  assert.equal(resolveDailyState(unverified), 'unverified');
  assert.equal(deriveDaily(unverified, NOW).modalCta.action, 'account');
});

test('elegibilidad: manda el veredicto del servidor; sin él, verificada ⇔ elegible', () => {
  const open = userSpin({ user: { verified: 0, eligibility: { policy: 'all', eligible: true, reason: null } } });
  assert.equal(open.eligible, true);
  assert.equal(resolveDailyState(state({ phase: 'ready', data: open })), 'paid');

  const closed = userSpin({ user: { verified: 0, eligibility: { policy: 'verified', eligible: false, reason: 'account_not_verified' } } });
  assert.equal(resolveDailyState(state({ phase: 'ready', data: closed })), 'unverified');

  // Contrato defensivo: un `eligible` no booleano se ignora y decide `verified`.
  const malformed = userSpin({ user: { verified: 1, eligibility: { eligible: 'yes' } } });
  assert.equal(malformed.eligible, true);
});

test('spin_init incompleto lanza InvalidResponse', () => {
  assert.throws(() => normalizeSpin({ success: true, is_logged: true }), { name: 'InvalidResponse' });
  assert.throws(() => normalizeSpin(null), { name: 'InvalidResponse' });
});

test('máquina de estados del Giro Diario', () => {
  const ready = (o) => state({ phase: 'ready', data: userSpin(o) });
  assert.equal(resolveDailyState(ready({ status: { free_spin_available: true } })), 'free');
  assert.equal(resolveDailyState(ready({ user: { bonus_spins: 2 } })), 'bonus');
  assert.equal(resolveDailyState(ready({ status: { paid_spins_enabled: 0 } })), 'cooldown');
  assert.equal(resolveDailyState(ready({ status: { user_paid_spins_count: 3 } })), 'paid-limit');
  assert.equal(resolveDailyState(ready({ user: { coins: 10 } })), 'paid-insufficient');
  assert.equal(resolveDailyState(ready()), 'paid');
  assert.equal(resolveDailyState(state({ phase: 'ready', data: userSpin(), action: 'spinning' })), 'spinning');
});

test('ViewModel paid: precio en CTA, contador al reset y expectFree sólo en free', () => {
  const vm = deriveDaily(state({ phase: 'ready', data: userSpin() }), NOW);
  assert.equal(vm.modalCta.text, 'Girar por 50 bananos');
  assert.equal(vm.meta.label, 'Giro gratis en');
  assert.equal(vm.meta.value, '01:00:00');
  assert.equal(vm.meta.deadline, NOW + 3_600_000);
  assert.equal(vm.expectFree, false);
  assert.equal(vm.coins, '120');

  const free = deriveDaily(state({ phase: 'ready', data: userSpin({ status: { free_spin_available: true } }) }), NOW);
  assert.equal(free.expectFree, true);
  assert.equal(free.meta.label, 'Tu giro vence en');
  assert.equal(free.meta.deadline, NOW + 3_600_000);
});

test('meta del Giro Diario nunca es null y refleja el estado', () => {
  const ready = (o, extra) => deriveDaily(state({ phase: 'ready', data: userSpin(o), ...extra }), NOW).meta;
  const guest = deriveDaily(state({ phase: 'ready', data: normalizeSpin({ is_logged: false }) }), NOW).meta;
  assert.deepEqual([guest.label, guest.value], ['Giro gratis', 'Cada día']);
  const unverified = ready({ user: { verified: 0 } });
  assert.deepEqual([unverified.label, unverified.value], ['Requisito', 'Cuenta verificada']);
  const bonus = ready({ user: { bonus_spins: 2 } });
  assert.deepEqual([bonus.label, bonus.value], ['Tiros extra', '2 disponibles']);
  assert.equal(ready({ user: { bonus_spins: 1 } }).value, '1 disponible');
  // Sin next_reset_utc: degrada a texto estático, sigue sin ser null.
  const noReset = ready({ status: { next_reset_utc: null } });
  assert.deepEqual([noReset.label, noReset.value, noReset.deadline], ['Giro gratis', 'Tras el reset', null]);
  // Girando: conserva el meta del estado de los datos (cuenta atrás al reset).
  const spinning = ready({}, { action: 'spinning' });
  assert.deepEqual([spinning.label, spinning.value], ['Giro gratis en', '01:00:00']);
});

test('oferta flash visible en free/bonus/paid y notice sobrescribe la nota', () => {
  const flash = { active: true, claimed: 2, limit: 8, percent: 40 };
  const vm = deriveDaily(state({ phase: 'ready', data: userSpin({ flash, status: { free_spin_available: true } }) }), NOW);
  assert.equal(vm.flashOffer.percent, 25);
  assert.equal(vm.badge.text, 'Oferta flash −40%');
  const noticed = deriveDaily(state({ phase: 'ready', data: userSpin(), notice: 'Rechazado' }), NOW);
  assert.equal(noticed.note, 'Rechazado');
  assert.equal(noticed.live, 'Rechazado');
});

/* ── Giro Diario: modos y filtros de categoría ── */

test('spin_config ausente → porcentajes por defecto y sin filtros', () => {
  const cfg = normalizeSpinConfig(undefined);
  assert.deepEqual(cfg.modes, { all: { percent: 15, count: 0 }, no_tracks: { percent: 10, count: 0 } });
  assert.deepEqual(cfg.filters, []);
  const vm = deriveDaily(state({ phase: 'ready', data: userSpin() }), NOW);
  assert.equal(vm.picker.discount, '15%');
  assert.equal(vm.picker.hasFilters, false);
  assert.equal(vm.picker.options.length, 1);
});

test('spin_config: filtros activos con formato "Nombre (X% OFF) - N ítems"; descarta vacíos y duplicados', () => {
  const cfg = normalizeSpinConfig({
    ...SPIN_CONFIG,
    filters: [...SPIN_CONFIG.filters, { key: 'pico', label: 'Picos', percent: 5, count: 0 }, { key: 'gesto', percent: 9, count: 1 }],
  });
  assert.deepEqual(cfg.filters.map((f) => f.key), ['atuendo', 'gesto', 'lote']);
  assert.equal(cfg.filters[2].isBundle, true);
  const vm = deriveDaily(state({ phase: 'ready', data: userSpin({ config: SPIN_CONFIG }) }), NOW);
  assert.deepEqual(vm.picker.options.map((o) => o.text), [
    'Todos (15% OFF) - 300 ítems',
    'Atuendos (5% OFF) - 48 ítems',
    'Gestos (5% OFF) - 24 ítems',
    'Lotes (2% OFF) - 20 ítems',
  ]);
  assert.equal(vm.picker.options[0].selected, true);
  assert.equal(vm.picker.filterLabel, 'Filtros');
});

test('spin_config: filtros ordenados por % OFF descendente (empates en orden del backend), tras "Todos"', () => {
  const cfg = normalizeSpinConfig({
    ...SPIN_CONFIG,
    filters: [
      { key: 'lote', label: 'Lotes', percent: 2, count: 20, is_bundle: true },
      { key: 'atuendo', label: 'Atuendos', percent: 5, count: 48 },
      { key: 'estela', label: 'Estelas', percent: 12, count: 1 },
      { key: 'gesto', label: 'Gestos', percent: 5, count: 24 },
      { key: 'pico', label: 'Picos', percent: 8, count: 19 },
    ],
  });
  assert.deepEqual(cfg.filters.map((f) => f.key), ['estela', 'pico', 'atuendo', 'gesto', 'lote']);
  const vm = deriveDaily(state({ phase: 'ready', data: userSpin({ config: { ...SPIN_CONFIG, filters: cfg.filters.map((f) => ({ ...f, is_bundle: f.isBundle })) } }) }), NOW);
  assert.equal(vm.picker.options[0].key, '');
  assert.deepEqual(vm.picker.options.slice(1).map((o) => o.text), [
    'Estelas (12% OFF) - 1 ítem',
    'Picos (8% OFF) - 19 ítems',
    'Atuendos (5% OFF) - 48 ítems',
    'Gestos (5% OFF) - 24 ítems',
    'Lotes (2% OFF) - 20 ítems',
  ]);
});

test('giro pagado: confirmSpin expone el importe en bananos; free/bonus nunca confirman', () => {
  const paid = deriveDaily(state({ phase: 'ready', data: userSpin(), confirmSpin: true }), NOW);
  assert.equal(paid.state, 'paid');
  assert.equal(paid.confirmSpin.text, '¿Deseas gastar 50 bananos para realizar un giro extra?');
  assert.equal(paid.confirmSpin.hint, 'Saldo actual: 120 bananos. Te quedarán 70 bananos.');
  assert.equal(paid.confirmSpin.cta, 'Sí, gastar 50 bananos');

  const idle = deriveDaily(state({ phase: 'ready', data: userSpin() }), NOW);
  assert.equal(idle.confirmSpin, null);

  const free = deriveDaily(state({ phase: 'ready', data: userSpin({ status: { free_spin_available: true } }), confirmSpin: true }), NOW);
  assert.equal(free.confirmSpin, null);
  const bonus = deriveDaily(state({ phase: 'ready', data: userSpin({ user: { bonus_spins: 2 } }), confirmSpin: true }), NOW);
  assert.equal(bonus.state, 'bonus');
  assert.equal(bonus.confirmSpin, null);
  const broke = deriveDaily(state({ phase: 'ready', data: userSpin({ user: { coins: 10 } }), confirmSpin: true }), NOW);
  assert.equal(broke.state, 'paid-insufficient');
  assert.equal(broke.confirmSpin, null);
});

test('picks: el modo cambia el % al instante y el filtro manda sobre el modo', () => {
  const data = userSpin({ config: SPIN_CONFIG, preview: PREVIEW });
  const ready = (picks) => deriveDaily(state({ phase: 'ready', data, picks }), NOW);

  const all = ready({ mode: 'all', filter: '' });
  assert.equal(all.picker.discount, '15%');
  assert.equal(all.picker.mode, 'all');
  assert.equal(all.previewItems.length, 3);

  const noTracks = ready({ mode: 'no_tracks', filter: '' });
  assert.equal(noTracks.picker.discount, '10%');
  assert.equal(noTracks.picker.discountLabel, 'Sin Pistas');
  assert.equal(noTracks.picker.options[0].text, 'Todos (10% OFF) - 175 ítems');
  assert.deepEqual(noTracks.previewItems.map((i) => i.displayName), ['Skin', 'Bundle']);

  const filtered = ready({ mode: 'no_tracks', filter: 'lote' });
  assert.equal(filtered.picker.discount, '2%');
  assert.equal(filtered.picker.filterLabel, 'Lotes');
  assert.equal(filtered.picker.modeMuted, true);
  assert.equal(filtered.picks.filter.key, 'lote');
  assert.deepEqual(filtered.previewItems.map((i) => i.displayName), ['Bundle']);
  assert.equal(filtered.picker.options.find((o) => o.key === 'lote').selected, true);
});

test('picks: un filtro que el admin apagó cae a "Todos"; sin datos el % es —', () => {
  const data = userSpin({ config: SPIN_CONFIG });
  const picks = resolveDailyPicks(data, { mode: 'all', filter: 'pico' });
  assert.equal(picks.filter, null);
  assert.equal(picks.percent, 15);
  const vm = deriveDaily(state({ phase: 'ready', data, picks: { mode: 'all', filter: 'pico' } }), NOW);
  assert.equal(vm.picker.filterKey, '');

  assert.deepEqual(resolveDailyPicks(null, undefined), { mode: 'all', filter: null, percent: null });
  const loading = deriveDaily(initialResourceState(), NOW);
  assert.equal(loading.picker.discount, '—');
  assert.equal(loading.picker.enabled, false);
});

test('preview filtrado cae al total si la selección no deja objetos', () => {
  const onlyTracks = [{ displayName: 'Track', typeKey: 'pista', isTrack: true }];
  assert.deepEqual(filterPreviewItems(onlyTracks, { mode: 'no_tracks', filter: null }), onlyTracks);
  assert.deepEqual(filterPreviewItems([], { mode: 'all', filter: null }), []);
});

test('aviso de Lotes: pendingFilter expone el texto; el selector se bloquea al girar', () => {
  const data = userSpin({ config: SPIN_CONFIG });
  const vm = deriveDaily(state({ phase: 'ready', data, pendingFilter: 'lote', menuOpen: true }), NOW);
  assert.equal(vm.picker.warning.text, 'Algunos atuendos o vehículos están catalogados como Lote');
  assert.equal(vm.picker.open, true);
  const spinning = deriveDaily(state({ phase: 'ready', data, action: 'spinning', menuOpen: true }), NOW);
  assert.equal(spinning.picker.enabled, false);
  assert.equal(spinning.picker.open, false);
});

test('cadencia daily: hasta el reset con tope, null si ya venció', () => {
  const d = userSpin();
  assert.equal(dailyNextRefresh(d, NOW), 5 * 60_000);
  assert.equal(dailyNextRefresh(d, NOW + 3_599_000), 1_500);
  assert.equal(dailyNextRefresh(d, NOW + 3_600_000), null);
});

/* ── Sorteo Flash ── */

test('normalizeFlash: sin sorteo, con próximo, y contrato inválido', () => {
  assert.equal(resolveFlashState(state({ phase: 'ready', data: hub(null) }), NOW), 'none');
  const withNext = hub(null, { next_scheduled: { id: 9, start_time_utc: iso(120_000) } });
  assert.equal(resolveFlashState(state({ phase: 'ready', data: withNext }), NOW), 'next');
  assert.throws(() => normalizeFlash({ status: 'error', message: 'x' }), { name: 'InvalidResponse' });
  assert.throws(() => normalizeFlash({ found: true, raffle: openRaffle({ status: 'weird' }) }), { name: 'InvalidResponse' });
});

test('máquina de estados del Sorteo Flash', () => {
  const ready = (r, extra) => state({ phase: 'ready', data: hub(r, extra) });
  assert.equal(resolveFlashState(ready(openRaffle()), NOW), 'open');
  assert.equal(resolveFlashState(ready(openRaffle(), { is_logged_in: false }), NOW), 'guest');
  assert.equal(resolveFlashState(ready(openRaffle(), { my_banned: true }), NOW), 'banned');
  assert.equal(resolveFlashState(ready(openRaffle({ my_review_status: 'active' })), NOW), 'joined');
  assert.equal(resolveFlashState(ready(openRaffle({ my_review_status: 'under_review' })), NOW), 'review');
  assert.equal(resolveFlashState(ready(openRaffle({ my_review_status: 'expelled' })), NOW), 'expelled');
  assert.equal(resolveFlashState(ready(openRaffle({ slots_left: 0 })), NOW), 'full');
  assert.equal(resolveFlashState(ready(openRaffle({ status: 'scheduled' })), NOW), 'scheduled');
  assert.equal(resolveFlashState(ready(openRaffle({ status: 'locking' })), NOW), 'resolving');
  assert.equal(resolveFlashState(ready(openRaffle({ status: 'completed' })), NOW), 'completed');
  assert.equal(resolveFlashState(ready(openRaffle({ status: 'cancelled' })), NOW), 'cancelled');
  // Cierre vencido según el reloj calibrado, aunque el servidor siga en `open`.
  assert.equal(resolveFlashState(ready(openRaffle()), NOW + 600_000), 'resolving');
});

test('ViewModel open: cupos, entrada, cuenta atrás y enlace a la sala', () => {
  const vm = deriveFlash(state({ phase: 'ready', data: hub(openRaffle()) }), NOW);
  assert.equal(vm.title, 'Sorteo de prueba');
  assert.equal(vm.desc, 'Quedan 6 cupos. Entrada: gratis.');
  assert.equal(vm.stats.slots, '4 / 10');
  assert.equal(vm.stats.cost, 'Gratis');
  assert.equal(vm.meta.label, 'Cierra en');
  assert.equal(vm.meta.value, '00:10:00');
  assert.equal(vm.modalCta.text, 'Entrar gratis');
  assert.equal(vm.roomHref, '/juegos/sorteo_flash/');
});

test('la sala del Sorteo Flash se abre con URL limpia en todos los estados', () => {
  const cases = [
    state({ phase: 'ready', data: hub(openRaffle()) }),
    state({ phase: 'ready', data: hub(openRaffle({ status: 'completed' })) }),
    state({ phase: 'ready', data: hub(null) }),
    state({ phase: 'error', error: 'network' }),
  ];
  for (const s of cases) {
    const { roomHref } = deriveFlash(s, NOW);
    assert.equal(roomHref, '/juegos/sorteo_flash/');
    assert.doesNotMatch(roomHref, /[?#]/, 'la URL no debe exponer parámetros');
  }
});

test('completado con próximo programado: cuenta atrás al siguiente y ¡Ganaste!', () => {
  const data = hub(openRaffle({ status: 'completed', my_won: true }), {
    next_scheduled: { id: 8, start_time_utc: iso(90_000) },
  });
  const vm = deriveFlash(state({ phase: 'ready', data }), NOW);
  assert.equal(vm.badge.text, '¡Ganaste!');
  assert.equal(vm.meta.label, 'Próximo sorteo en');
  assert.equal(vm.meta.value, '00:01:30');
  assert.match(vm.desc, /El próximo sorteo ya está programado\.$/);
});

test('meta del Sorteo Flash nunca es null y refleja el estado', () => {
  const meta = (r, extra) => deriveFlash(state({ phase: 'ready', data: hub(r, extra) }), NOW).meta;
  assert.deepEqual([meta(null).label, meta(null).value], ['Próximo sorteo', 'Por anunciar']);
  const resolving = meta(openRaffle({ status: 'processing' }));
  assert.deepEqual([resolving.label, resolving.value, resolving.deadline], ['Sorteo', 'Eligiendo ganador…', null]);
  const completed = meta(openRaffle({ status: 'completed', participants: 12 }));
  assert.deepEqual([completed.label, completed.value], ['Participaron', '12']);
  const cancelled = meta(openRaffle({ status: 'cancelled' }));
  assert.deepEqual([cancelled.label, cancelled.value], ['Próximo sorteo', 'Por anunciar']);
  const scheduledNoStart = meta(openRaffle({ status: 'scheduled', start_time_utc: null }));
  assert.deepEqual([scheduledNoStart.label, scheduledNoStart.value], ['Apertura', 'Por anunciar']);
  // El bloque de estadísticas del dialog sólo toma el meta cuando es una cuenta atrás.
  const vm = deriveFlash(state({ phase: 'ready', data: hub(openRaffle({ status: 'processing' })) }), NOW);
  assert.deepEqual([vm.stats.timeLabel, vm.stats.time], ['Tiempo', '—']);
});

test('cadencia flash según estado', () => {
  assert.equal(flashNextRefresh(hub(openRaffle()), NOW), 20_000);
  assert.equal(flashNextRefresh(hub(openRaffle({ end_time_utc: iso(5_000) })), NOW), 5_500);
  assert.equal(flashNextRefresh(hub(openRaffle({ status: 'processing' })), NOW), 8_000);
  assert.equal(flashNextRefresh(hub(null), NOW), 120_000);
});

/* ── store y formato ── */

test('store: notifica sólo cuando algo cambia', () => {
  const store = createStore(initialResourceState());
  let calls = 0;
  store.subscribe(() => { calls += 1; });
  store.set({ phase: 'idle' });
  assert.equal(calls, 0);
  store.set({ phase: 'loading' });
  assert.equal(calls, 1);
  store.set({ phase: 'loading', notice: null });
  assert.equal(calls, 1);
});

test('formatCountdown', () => {
  assert.equal(formatCountdown(-5_000), '00:00:00');
  assert.equal(formatCountdown(61_000), '00:01:01');
  assert.equal(formatCountdown(90_000_000), '1d 01:00:00');
});

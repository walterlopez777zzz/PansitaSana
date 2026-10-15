import { test } from 'node:test';
import assert from 'node:assert/strict';

import { CartApiError, ErrorCode, Sub, createCartApi, isAbortError, toCartApiError } from './api.js';

const jsonResponse = (body, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  json: async () => body,
});

/** fetch falso que registra llamadas y responde según una cola. */
function fakeFetch(queue) {
  const calls = [];
  const fetch = async (url, init) => {
    calls.push({ url, init, body: Object.fromEntries(new URLSearchParams(init.body)) });
    const next = queue.shift();
    if (typeof next === 'function') return next(init);
    return next;
  };
  return { fetch, calls };
}

const identityDecode = async (payload) => payload;

test('request: POST url-encoded al gateway con el sub correcto y cookies', async () => {
  const { fetch, calls } = fakeFetch([jsonResponse({ success: true, cart_items: [] })]);
  const api = createCartApi({ fetch, decode: identityDecode });

  const data = await api.add({ offerId: 'abc', quantity: 2 });
  assert.equal(data.success, true);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, '/web.php');
  assert.equal(calls[0].init.method, 'POST');
  assert.equal(calls[0].init.credentials, 'same-origin');
  assert.deepEqual(calls[0].body, { action: 'c9d', sub: Sub.ADD, offerId: 'abc', quantity: '2' });
});

test('errores del servidor: usa `code` cuando existe, 404 → NOT_FOUND, resto → SERVER', async () => {
  const { fetch } = fakeFetch([
    jsonResponse({ success: false, code: 'MAX_QUANTITY', message: 'Tope' }, 409),
    jsonResponse({ success: false, message: 'nope' }, 404),
    jsonResponse({ success: false, message: 'fail' }, 200),
  ]);
  const api = createCartApi({ fetch, decode: identityDecode });

  await assert.rejects(api.setQuantity({ offerId: 'a', quantity: 9 }), (e) => {
    assert.ok(e instanceof CartApiError);
    assert.equal(e.code, ErrorCode.MAX_QUANTITY);
    assert.equal(e.message, 'Tope');
    assert.equal(e.status, 409);
    return true;
  });
  await assert.rejects(api.remove({ offerId: 'a' }), { code: ErrorCode.NOT_FOUND });
  await assert.rejects(api.buyNow({ offerId: 'a' }), { code: ErrorCode.SERVER });
});

test('payload no JSON o no-objeto → INVALID; JSON roto con status 5xx → SERVER', async () => {
  const { fetch } = fakeFetch([
    jsonResponse('texto', 200),
    { ok: false, status: 502, json: async () => { throw new SyntaxError('bad json'); } },
  ]);
  const api = createCartApi({ fetch, decode: identityDecode, getRetries: 0 });
  await assert.rejects(api.add({ offerId: 'a' }), { code: ErrorCode.INVALID });
  await assert.rejects(api.add({ offerId: 'a' }), { code: ErrorCode.SERVER });
});

test('get: reintenta fallos de red/5xx con backoff; las mutaciones no reintentan', async () => {
  const network = () => { throw new TypeError('Failed to fetch'); };
  const okBody = { success: true, cart_items: [] };

  const getCase = fakeFetch([network, jsonResponse({}, 500), jsonResponse(okBody)]);
  const api = createCartApi({ fetch: getCase.fetch, decode: identityDecode, getRetries: 2, retryBackoffMs: 1 });
  assert.deepEqual(await api.get(), okBody);
  assert.equal(getCase.calls.length, 3);

  const addCase = fakeFetch([network, jsonResponse(okBody)]);
  const api2 = createCartApi({ fetch: addCase.fetch, decode: identityDecode, retryBackoffMs: 1 });
  await assert.rejects(api2.add({ offerId: 'a' }), { code: ErrorCode.NETWORK });
  assert.equal(addCase.calls.length, 1);
});

test('get: no reintenta errores de negocio (4xx)', async () => {
  const { fetch, calls } = fakeFetch([jsonResponse({ success: false }, 400)]);
  const api = createCartApi({ fetch, decode: identityDecode, getRetries: 3, retryBackoffMs: 1 });
  await assert.rejects(api.get(), { code: ErrorCode.SERVER });
  assert.equal(calls.length, 1);
});

test('timeout: aborta la petición y responde TIMEOUT', async () => {
  const fetch = (_url, init) => new Promise((_resolve, reject) => {
    init.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
  });
  const api = createCartApi({ fetch, decode: identityDecode, timeoutMs: 5, getRetries: 0 });
  await assert.rejects(api.get(), { code: ErrorCode.TIMEOUT });
});

test('señal externa: ya abortada → ABORTED inmediato; abortada en vuelo → ABORTED', async () => {
  const pre = new AbortController();
  pre.abort();
  const { fetch, calls } = fakeFetch([]);
  const api = createCartApi({ fetch, decode: identityDecode });
  await assert.rejects(api.get({ signal: pre.signal }), { code: ErrorCode.ABORTED });
  assert.equal(calls.length, 0);

  const live = new AbortController();
  const hanging = (_url, init) => new Promise((_resolve, reject) => {
    init.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
  });
  const api2 = createCartApi({ fetch: hanging, decode: identityDecode, getRetries: 0 });
  const pending = api2.remove({ offerId: 'a', signal: live.signal });
  live.abort();
  await assert.rejects(pending, (e) => isAbortError(e));
});

test('decode: el frame cifrado se decodifica antes de validar', async () => {
  const { fetch } = fakeFetch([jsonResponse({ _sd: 'x', _sn: 'y' })]);
  const decode = async (payload) => (payload._sd ? { success: true, cart_items: [{ offerId: 'z' }] } : payload);
  const api = createCartApi({ fetch, decode });
  const data = await api.get();
  assert.equal(data.cart_items[0].offerId, 'z');
});

test('toCartApiError / CartApiError.toState', () => {
  const err = toCartApiError(new DOMException('x', 'AbortError'));
  assert.equal(err.code, ErrorCode.ABORTED);
  assert.equal(toCartApiError(new TypeError('Failed to fetch')).code, ErrorCode.NETWORK);
  const same = new CartApiError(ErrorCode.SERVER);
  assert.equal(toCartApiError(same), same);
  assert.deepEqual(same.toState(), { code: 'SERVER', message: 'No pudimos actualizar tu carrito. Inténtalo de nuevo.' });
  assert.ok(Object.isFrozen(same.toState()));
});

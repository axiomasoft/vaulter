import { test } from 'node:test'
import assert from 'node:assert/strict'
import crypto from 'node:crypto'
import { authenticate, BackendRejection, loadDocument, storeDocument } from '../src/webhook.js'

// Unit coverage for the backend client the hooks call. fetch is mocked — no network.

const cfg = {
  webhookBase: 'http://laravel/api/vaulter',
  webhookSecret: 'shared-secret',
  webhookTimeoutMs: 0,
}

function mockFetch(handler) {
  const calls = []
  const fetchImpl = async (url, init) => {
    calls.push({ url, init })
    return handler(url, init)
  }
  return { fetchImpl, calls }
}

function jsonResponse(body, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
    text: async () => JSON.stringify(body),
  }
}

function expectedSignature(timestamp, rawBody, secret) {
  return crypto.createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex')
}

// ── authentication of every call ─────────────────────────────────────────────

test('every call carries the room JWT and a signature of the exact bytes it sends, never the secret itself', async () => {
  const { fetchImpl, calls } = mockFetch(() => jsonResponse({ allowed: true, mode: 'rw', userId: '7', epoch: 0 }))

  const before = Math.floor(Date.now() / 1000)
  await authenticate({ token: 'jwt-abc', room: 'vaulter.documents.1.42.0' }, { config: cfg, fetchImpl })
  const after = Math.floor(Date.now() / 1000)

  const { headers, body: rawBody } = calls[0].init
  const timestamp = Number(headers['X-Vaulter-Collab-Timestamp'])

  assert.equal(calls[0].url, 'http://laravel/api/vaulter/collab/authenticate')
  assert.equal(headers.Authorization, 'Bearer jwt-abc')
  assert.equal(headers['X-Vaulter-Collab-Secret'], undefined)
  assert.ok(Number.isInteger(timestamp) && timestamp >= before && timestamp <= after)
  assert.equal(headers['X-Vaulter-Collab-Signature'], expectedSignature(timestamp, rawBody, cfg.webhookSecret))
  assert.equal(JSON.parse(rawBody).room, 'vaulter.documents.1.42.0')
})

test('two calls with the same payload never share a signature (fresh nonce per body)', async () => {
  const { fetchImpl, calls } = mockFetch(() => jsonResponse({ state: null }))

  await loadDocument({ token: 't', room: 'r' }, { config: cfg, fetchImpl })
  await loadDocument({ token: 't', room: 'r' }, { config: cfg, fetchImpl })

  assert.notEqual(JSON.parse(calls[0].init.body).nonce, JSON.parse(calls[1].init.body).nonce)
  assert.notEqual(calls[0].init.headers['X-Vaulter-Collab-Signature'], calls[1].init.headers['X-Vaulter-Collab-Signature'])
})

test('no signature is sent when no webhook secret is configured (the backend then refuses)', async () => {
  const { fetchImpl, calls } = mockFetch(() => jsonResponse({ allowed: true, mode: 'rw' }))

  await authenticate({ token: 'jwt', room: 'r' }, { config: { ...cfg, webhookSecret: undefined }, fetchImpl })

  assert.equal(calls[0].init.headers['X-Vaulter-Collab-Timestamp'], undefined)
  assert.equal(calls[0].init.headers['X-Vaulter-Collab-Signature'], undefined)
})

// ── authenticate ────────────────────────────────────────────────────────────

test('authenticate maps the backend answer and defaults to read-only', async () => {
  const cases = [
    [{ allowed: true, mode: 'rw', userId: '7', epoch: 3 }, { allowed: true, mode: 'rw', userId: '7', epoch: 3, conflict: null }],
    [{ allowed: true, mode: 'ro', userId: '7', epoch: 3 }, { allowed: true, mode: 'ro', userId: '7', epoch: 3, conflict: null }],
    [{ allowed: true }, { allowed: true, mode: 'ro', userId: null, epoch: null, conflict: null }],
    [{ allowed: false }, { allowed: false, mode: 'ro', userId: null, epoch: null, conflict: null }],
  ]

  for (const [answer, expected] of cases) {
    const { fetchImpl } = mockFetch(() => jsonResponse(answer))
    assert.deepEqual(await authenticate({ token: 't', room: 'r' }, { config: cfg, fetchImpl }), expected)
  }
})

test('authenticate refuses on any non-2xx and names an epoch conflict', async () => {
  const unauthenticated = mockFetch(() => jsonResponse({ message: 'Unauthenticated collaboration call.' }, 401))
  const conflict = mockFetch(() => jsonResponse({ code: 'collaboration_epoch_mismatch' }, 409))

  assert.deepEqual(
    await authenticate({ token: 't', room: 'r' }, { config: cfg, fetchImpl: unauthenticated.fetchImpl }),
    { allowed: false, mode: 'ro', userId: null, epoch: null, conflict: null },
  )
  assert.equal(
    (await authenticate({ token: 't', room: 'r' }, { config: cfg, fetchImpl: conflict.fetchImpl })).conflict,
    'collaboration_epoch_mismatch',
  )
})

// ── load ────────────────────────────────────────────────────────────────────

test('loadDocument returns the backend payload and rejects 4xx without retry semantics', async () => {
  const payload = { state: null, content: { type: 'doc', content: [] }, epoch: 0, schemaVersion: 1, stateVersion: 1, type: 'vaulter/rich-text' }
  const ok = mockFetch(() => jsonResponse(payload))
  const gone = mockFetch(() => jsonResponse({ code: 'node_not_found' }, 404))
  const down = mockFetch(() => jsonResponse({}, 503))

  assert.deepEqual(await loadDocument({ token: 't', room: 'r' }, { config: cfg, fetchImpl: ok.fetchImpl }), payload)
  await assert.rejects(
    loadDocument({ token: 't', room: 'r' }, { config: cfg, fetchImpl: gone.fetchImpl }),
    (error) => error instanceof BackendRejection && error.status === 404 && error.code === 'node_not_found',
  )
  await assert.rejects(loadDocument({ token: 't', room: 'r' }, { config: cfg, fetchImpl: down.fetchImpl }), /collab\/load failed \(503\)/)
})

// ── store ───────────────────────────────────────────────────────────────────

test('storeDocument posts the base64 state, the projection and the seed flag', async () => {
  const { fetchImpl, calls } = mockFetch(() => jsonResponse({ data: { changed: true, epoch: 0 } }))
  const state = Buffer.from(new Uint8Array([0, 1, 2, 255])).toString('base64')
  const content = { type: 'doc', content: [] }

  const result = await storeDocument({ room: 'r', state, content, seed: true, token: 'jwt' }, { config: cfg, fetchImpl })
  const sent = JSON.parse(calls[0].init.body)

  assert.equal(calls[0].url, 'http://laravel/api/vaulter/collab/store')
  assert.deepEqual({ room: sent.room, state: sent.state, content: sent.content, seed: sent.seed }, { room: 'r', state, content, seed: true })
  assert.equal('user_id' in sent, false)
  assert.deepEqual(result, { ok: true, data: { changed: true, epoch: 0 } })
})

test('storeDocument sends the state version a seed was built from, and nothing else adds it', async () => {
  const { fetchImpl, calls } = mockFetch(() => jsonResponse({ data: {} }))

  await storeDocument({ room: 'r', state: 'AAA=', seed: true, stateVersion: 7, token: 't' }, { config: cfg, fetchImpl })
  await storeDocument({ room: 'r', state: 'AAA=', token: 't' }, { config: cfg, fetchImpl })

  assert.equal(JSON.parse(calls[0].init.body).state_version, 7)
  assert.equal('state_version' in JSON.parse(calls[1].init.body), false)
})

test('storeDocument reports a 4xx as a terminal answer instead of throwing (no retry loop)', async () => {
  for (const [status, code] of [[409, 'collaboration_epoch_mismatch'], [409, 'collaboration_seed_conflict'], [403, 'permission_denied'], [404, 'node_not_found']]) {
    const { fetchImpl } = mockFetch(() => jsonResponse({ code }, status))
    assert.deepEqual(
      await storeDocument({ room: 'r', state: 'AAA=', token: 't' }, { config: cfg, fetchImpl }),
      { ok: false, status, code },
    )
  }
})

test('storeDocument throws on 408/425/429 (try later) so the hub retries instead of reporting a refusal', async () => {
  for (const status of [408, 425, 429]) {
    const { fetchImpl } = mockFetch(() => jsonResponse({ code: 'throttled' }, status))

    await assert.rejects(
      storeDocument({ room: 'r', state: 'AAA=', token: 't' }, { config: cfg, fetchImpl }),
      new RegExp(`collab/store failed \\(${status}\\)`),
    )
  }
})

test('storeDocument throws on 5xx so Hocuspocus retries, without echoing the token', async () => {
  const { fetchImpl } = mockFetch(() => jsonResponse({}, 502))

  await assert.rejects(
    storeDocument({ room: 'r', state: 'AAA=', token: 'secret-token' }, { config: cfg, fetchImpl }),
    (error) => /collab\/store failed \(502\)/.test(error.message) && !error.message.includes('secret-token'),
  )
})

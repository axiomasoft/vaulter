import { test } from 'node:test'
import assert from 'node:assert/strict'
import { authenticate, storeDocument } from '../src/webhook.js'

// Unit coverage for the server-to-server webhook seam (onAuthenticate /
// onStoreDocument call into these). fetch is mocked — no network, no running stack.

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

function jsonResponse(body, ok = true, status = 200) {
  return {
    ok,
    status,
    json: async () => body,
    text: async () => JSON.stringify(body),
  }
}

// ── authenticate ────────────────────────────────────────────────────────────

test('authenticate forwards the JWT as a bearer token and the room in the body', async () => {
  const { fetchImpl, calls } = mockFetch(() =>
    jsonResponse({ allowed: true, readOnly: false, user: { id: '7' } }),
  )

  const result = await authenticate(
    { token: 'jwt-abc', room: 'vaulter.documents.42' },
    { config: cfg, fetchImpl },
  )

  assert.equal(calls.length, 1)
  assert.equal(calls[0].url, 'http://laravel/api/vaulter/collab/authenticate')
  assert.equal(calls[0].init.method, 'POST')
  assert.equal(calls[0].init.headers.Authorization, 'Bearer jwt-abc')
  assert.equal(calls[0].init.headers['X-Vaulter-Collab-Secret'], 'shared-secret')

  const sent = JSON.parse(calls[0].init.body)
  assert.equal(sent.room, 'vaulter.documents.42')
  assert.equal(sent.node_id, 'vaulter.documents.42')

  assert.deepEqual(result, { allowed: true, readOnly: false, userId: '7' })
})

test('authenticate honours readOnly:true from the backend', async () => {
  const { fetchImpl } = mockFetch(() => jsonResponse({ allowed: true, readOnly: true, user: { id: 1 } }))

  const result = await authenticate({ token: 't', room: 'r' }, { config: cfg, fetchImpl })

  assert.equal(result.allowed, true)
  assert.equal(result.readOnly, true)
})

test('authenticate maps allowed:false to a refused connection', async () => {
  const { fetchImpl } = mockFetch(() => jsonResponse({ allowed: false }))

  const result = await authenticate({ token: 't', room: 'r' }, { config: cfg, fetchImpl })

  assert.equal(result.allowed, false)
  // Never grant write access on a refusal.
  assert.equal(result.readOnly, true)
})

test('authenticate treats a non-2xx as not-allowed + read-only (fail closed)', async () => {
  const { fetchImpl } = mockFetch(() => jsonResponse({ message: 'unauthenticated' }, false, 401))

  const result = await authenticate({ token: 't', room: 'r' }, { config: cfg, fetchImpl })

  assert.deepEqual(result, { allowed: false, readOnly: true, userId: null })
})

test('authenticate defaults to read-only when the backend omits readOnly', async () => {
  const { fetchImpl } = mockFetch(() => jsonResponse({ allowed: true }))

  const result = await authenticate({ token: 't', room: 'r' }, { config: cfg, fetchImpl })

  assert.equal(result.allowed, true)
  assert.equal(result.readOnly, true)
})

test('authenticate omits the secret header when none is configured (JWT-only)', async () => {
  const { fetchImpl, calls } = mockFetch(() => jsonResponse({ allowed: true, readOnly: false }))

  await authenticate(
    { token: 'jwt-only', room: 'r' },
    { config: { ...cfg, webhookSecret: undefined }, fetchImpl },
  )

  assert.equal(calls[0].init.headers.Authorization, 'Bearer jwt-only')
  assert.equal(calls[0].init.headers['X-Vaulter-Collab-Secret'], undefined)
})

// ── storeDocument ───────────────────────────────────────────────────────────

test('storeDocument posts the base64 yjs_state + room and authenticates with the JWT', async () => {
  const { fetchImpl, calls } = mockFetch(() =>
    jsonResponse({ data: { node_id: '42', version_no: 2, yjs_state_size: 10 } }),
  )

  const base64 = Buffer.from(new Uint8Array([0, 1, 2, 255])).toString('base64')

  const result = await storeDocument(
    { room: 'vaulter.documents.42', yjsState: base64, token: 'jwt-abc' },
    { config: cfg, fetchImpl },
  )

  assert.equal(calls[0].url, 'http://laravel/api/vaulter/collab/store')
  assert.equal(calls[0].init.headers.Authorization, 'Bearer jwt-abc')

  const sent = JSON.parse(calls[0].init.body)
  assert.equal(sent.room, 'vaulter.documents.42')
  assert.equal(sent.node_id, 'vaulter.documents.42')
  assert.equal(sent.yjs_state, base64)
  // No user_id when none is supplied (the JWT carries `sub`).
  assert.equal('user_id' in sent, false)

  assert.deepEqual(result, { data: { node_id: '42', version_no: 2, yjs_state_size: 10 } })
})

test('storeDocument carries user_id out of band for a shared-secret call', async () => {
  const { fetchImpl, calls } = mockFetch(() => jsonResponse({ data: {} }))

  await storeDocument(
    { room: 'r', yjsState: 'AAA=', userId: 5 },
    { config: cfg, fetchImpl },
  )

  const sent = JSON.parse(calls[0].init.body)
  assert.equal(sent.user_id, 5)
  // The shared secret authenticates the call (no JWT supplied).
  assert.equal(calls[0].init.headers['X-Vaulter-Collab-Secret'], 'shared-secret')
  assert.equal(calls[0].init.headers.Authorization, undefined)
})

test('storeDocument throws on a non-2xx so the server can retry', async () => {
  const { fetchImpl } = mockFetch(() => jsonResponse({ message: 'forbidden' }, false, 403))

  await assert.rejects(
    storeDocument({ room: 'r', yjsState: 'AAA=', token: 't' }, { config: cfg, fetchImpl }),
    /collab\/store failed \(403\)/,
  )
})

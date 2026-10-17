import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as Y from 'yjs'
import { buildConfig } from '../src/config.js'
import { authenticate, loadDocument, storeDocument } from '../src/webhook.js'
import { createHooks, EPOCH_CHANGED } from '../src/index.js'

// The hub only talks to the versioned collaboration/hub API of Laravel.

const BASE = 'http://app/api/vaulter/v1'
const ROOM = 'vaulter.documents.1.42.0'
const cfg = { webhookBase: BASE, webhookSecret: 's', webhookTimeoutMs: 0 }

function problem(status, body) {
  return { ok: false, status, headers: { get: () => 'application/problem+json' }, json: async () => body }
}

function recorder(answer) {
  const calls = []
  return {
    calls,
    fetchImpl: async (url, init) => {
      calls.push({ url, init })
      return answer
    },
  }
}

const ok = (body) => ({ ok: true, status: 200, json: async () => body })

test('authenticate, load and store hit {base}/collaboration/hub/*', async () => {
  const auth = recorder(ok({ allowed: true, mode: 'rw', userId: '1', epoch: 0 }))
  const load = recorder(ok({ state: null }))
  const store = recorder(ok({ data: {} }))

  await authenticate({ token: 't', room: ROOM }, { config: cfg, fetchImpl: auth.fetchImpl })
  await loadDocument({ token: 't', room: ROOM }, { config: cfg, fetchImpl: load.fetchImpl })
  await storeDocument({ token: 't', room: ROOM, state: 'AA==' }, { config: cfg, fetchImpl: store.fetchImpl })

  assert.equal(auth.calls[0].url, `${BASE}/collaboration/hub/authenticate`)
  assert.equal(load.calls[0].url, `${BASE}/collaboration/hub/load`)
  assert.equal(store.calls[0].url, `${BASE}/collaboration/hub/store`)
})

test('a base without /v1 fails the configuration with a clear message', () => {
  assert.throws(() => buildConfig({ VAULTER_API_URL: 'http://app/api/vaulter' }), /VAULTER_API_URL must end with "\/v1"/)
  assert.throws(() => buildConfig({ VAULTER_LARAVEL_WEBHOOK_URL: 'http://app/api/vaulter' }), /VAULTER_LARAVEL_WEBHOOK_URL must end with "\/v1"/)
})

test('a problem+json 409 epoch mismatch closes the room with 4409 and the store is not repeated', async () => {
  const sent = recorder(problem(409, { code: 'collaboration_epoch_mismatch' }))
  const closed = []
  const instance = { documents: new Map([[ROOM, { getConnections: () => [{ close: (e) => closed.push(e) }] }]]) }
  const hooks = createHooks({
    webhook: { storeDocument: (args) => storeDocument(args, { config: cfg, fetchImpl: sent.fetchImpl }) },
    retryDelayMs: 0,
    sleep: async () => {},
  })
  const document = new Y.Doc()
  const args = { documentName: ROOM, document, lastContext: { token: 't', mode: 'rw', readOnly: false }, instance }

  await hooks.onStoreDocument(args).catch(() => {})
  await hooks.onStoreDocument(args).catch(() => {})

  assert.equal(sent.calls.length, 1)
  assert.deepEqual(closed, [EPOCH_CHANGED])
  assert.equal(hooks.isFenced(ROOM), true)
})

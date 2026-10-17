import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as Y from 'yjs'
import { createHooks } from '../src/index.js'
import { NACK } from '../src/persistence.js'
import { clientUpdate, envelope, fakeConnection, quietLog, ROOM, testConf } from './support/rooms.js'

// Read-only is enforced by the hub itself (connection readOnly: Hocuspocus drops the connection's
// updates; its envelopes are refused) and an expired token stops every further message — the
// backend refuses both again on its side. A client that does not announce protocol 2 is read-only:
// it cannot send envelopes, so nothing it types could ever be saved.

function token(claims) {
  const part = (value) => Buffer.from(JSON.stringify(value)).toString('base64url')

  return `${part({ alg: 'HS256', typ: 'JWT' })}.${part(claims)}.signature`
}

const v2 = new URLSearchParams('protocol=2')

function hooksAnswering(answer, extra = {}) {
  return createHooks({ backend: { authenticate: async () => answer }, conf: testConf(), log: quietLog, ...extra })
}

test('a read-only answer marks the connection read-only and records mode, protocol and expiry in the context', async () => {
  const hooks = hooksAnswering({ allowed: true, mode: 'ro', userId: '2' })
  const connectionConfig = { readOnly: false }
  const exp = Math.floor(Date.now() / 1000) + 600

  const context = await hooks.onAuthenticate({ token: token({ exp }), documentName: ROOM, connectionConfig, requestParameters: v2 })

  assert.equal(connectionConfig.readOnly, true)
  assert.deepEqual(
    { mode: context.mode, readOnly: context.readOnly, userId: context.userId, exp: context.exp, protocol: context.protocol },
    { mode: 'ro', readOnly: true, userId: '2', exp, protocol: 2 },
  )
})

test('a writer that speaks protocol 2 stays writable', async () => {
  const hooks = hooksAnswering({ allowed: true, mode: 'rw', userId: '1' })
  const connectionConfig = { readOnly: true }

  await hooks.onAuthenticate({ token: token({ exp: Math.floor(Date.now() / 1000) + 600 }), documentName: ROOM, connectionConfig, requestParameters: v2 })

  assert.equal(connectionConfig.readOnly, false)
})

test('a writer that does not announce protocol 2 is read-only', async () => {
  for (const requestParameters of [undefined, new URLSearchParams(''), new URLSearchParams('protocol=1')]) {
    const hooks = hooksAnswering({ allowed: true, mode: 'rw', userId: '1' })
    const connectionConfig = { readOnly: false }

    const context = await hooks.onAuthenticate({ token: token({ exp: Math.floor(Date.now() / 1000) + 600 }), documentName: ROOM, connectionConfig, requestParameters })

    assert.equal(connectionConfig.readOnly, true)
    assert.deepEqual([context.readOnly, context.protocol], [true, 1])
  }
})

test('a refused or past-epoch connection never opens; a protocol mismatch is reported', async () => {
  await assert.rejects(hooksAnswering({ allowed: false, mode: 'ro', conflict: null }).onAuthenticate({ token: 't', documentName: ROOM }), /rejected/)
  await assert.rejects(hooksAnswering({ allowed: false, mode: 'ro', conflict: 'collaboration_epoch_mismatch' }).onAuthenticate({ token: 't', documentName: ROOM }), /epoch_changed/)
  await assert.rejects(hooksAnswering({ allowed: true, mode: 'rw' }).onAuthenticate({ token: '', documentName: ROOM }), /Missing collaboration token/)

  const mismatched = hooksAnswering({ allowed: false, mode: 'ro', conflict: 'collaboration_protocol_mismatch' })
  await assert.rejects(mismatched.onAuthenticate({ token: 't', documentName: ROOM }), /rejected/)
  assert.equal(mismatched.persistence.state().protocolMismatch, true)
})

test('a read-only connection\'s envelope is refused and nothing is appended', async () => {
  const appended = []
  const base = new Y.Doc()
  base.getText('t').insert(0, 'checkpoint')
  const hooks = createHooks({
    backend: {
      authenticate: async () => ({ allowed: true, mode: 'ro', userId: '2' }),
      load: async () => ({ generation: 0, schema_version: 1, type: 'vaulter/rich-text', state_version: 1, checkpoint: Buffer.from(Y.encodeStateAsUpdate(base)).toString('base64'), checkpoint_seq: 1, head_seq: 1, content: null, tail: [] }),
      append: async (args) => { appended.push(args); return { ok: true, status: 200, ack: { seq: 2, update_id: args.updateId, generation: 0, persisted: true } } },
    },
    conf: testConf(),
    log: quietLog,
  })
  const context = await hooks.onAuthenticate({ token: token({ exp: Math.floor(Date.now() / 1000) + 600 }), documentName: ROOM, connectionConfig: {}, requestParameters: v2 })
  const document = new Y.Doc()
  const viewer = fakeConnection('viewer')
  await hooks.onLoadDocument({ documentName: ROOM, document, context })
  await hooks.connected({ connection: viewer, context, documentName: ROOM, socketId: 'viewer' })
  const message = envelope(clientUpdate(document, 'viewer typing'))

  await hooks.onStateless({ connection: viewer, documentName: ROOM, document, payload: JSON.stringify(message) })
  await hooks.persistence.ledger.flush()

  assert.deepEqual(viewer.of('nack'), [{ t: 'nack', id: message.id, code: NACK.FORBIDDEN, retry: false }])
  assert.equal(appended.length, 0)
  assert.doesNotMatch(document.getText('t').toString(), /viewer typing/)
})

test('an expired token stops every further message', async () => {
  const hooks = hooksAnswering({ allowed: true, mode: 'rw' })

  await assert.doesNotReject(hooks.beforeHandleMessage({ context: { exp: Math.floor(Date.now() / 1000) + 60 } }))
  await assert.rejects(hooks.beforeHandleMessage({ context: { exp: Math.floor(Date.now() / 1000) - 1 } }), /expired/)
})

test('a reconnect with an expired token is refused with the reason the client renews its session on', async () => {
  const calls = []
  const hooks = createHooks({ backend: { authenticate: async (args) => { calls.push(args); return { allowed: true, mode: 'rw', userId: '1' } } }, conf: testConf(), log: quietLog })
  const expired = token({ exp: Math.floor(Date.now() / 1000) - 5 })

  await assert.rejects(
    hooks.onAuthenticate({ token: expired, documentName: ROOM }),
    (error) => error.reason === 'Collaboration token expired' && error.message === 'Collaboration token expired',
  )
  // Refused before the backend is asked: it would answer an opaque 401.
  assert.equal(calls.length, 0)
})

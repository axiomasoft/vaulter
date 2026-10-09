import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as Y from 'yjs'
import { createHooks } from '../src/index.js'

// Read-only is enforced by the hub itself (connection readOnly: Hocuspocus drops the
// connection's updates; no store under a read-only context) and an expired token stops
// every further message — the backend refuses both again on its side.

const ROOM = 'vaulter.documents.1.42.0'

function token(claims) {
  const part = (value) => Buffer.from(JSON.stringify(value)).toString('base64url')

  return `${part({ alg: 'HS256', typ: 'JWT' })}.${part(claims)}.signature`
}

function hooksAnswering(answer) {
  const stores = []

  return {
    stores,
    hooks: createHooks({
      webhook: {
        authenticate: async () => answer,
        storeDocument: async (args) => {
          stores.push(args)
          return { ok: true, data: {} }
        },
      },
    }),
  }
}

test('a read-only answer marks the connection read-only and records mode and expiry in the context', async () => {
  const { hooks } = hooksAnswering({ allowed: true, mode: 'ro', userId: '2' })
  const connectionConfig = { readOnly: false }
  const exp = Math.floor(Date.now() / 1000) + 600

  const context = await hooks.onAuthenticate({ token: token({ exp }), documentName: ROOM, connectionConfig })

  assert.equal(connectionConfig.readOnly, true)
  assert.deepEqual({ mode: context.mode, readOnly: context.readOnly, userId: context.userId, exp: context.exp }, { mode: 'ro', readOnly: true, userId: '2', exp })
})

test('a writer stays writable', async () => {
  const { hooks } = hooksAnswering({ allowed: true, mode: 'rw', userId: '1' })
  const connectionConfig = { readOnly: true }

  await hooks.onAuthenticate({ token: token({ exp: Math.floor(Date.now() / 1000) + 600 }), documentName: ROOM, connectionConfig })

  assert.equal(connectionConfig.readOnly, false)
})

test('a refused or past-epoch connection never opens', async () => {
  await assert.rejects(hooksAnswering({ allowed: false, mode: 'ro', conflict: null }).hooks.onAuthenticate({ token: 't', documentName: ROOM }), /rejected/)
  await assert.rejects(hooksAnswering({ allowed: false, mode: 'ro', conflict: 'collaboration_epoch_mismatch' }).hooks.onAuthenticate({ token: 't', documentName: ROOM }), /epoch_changed/)
  await assert.rejects(hooksAnswering({ allowed: true, mode: 'rw' }).hooks.onAuthenticate({ token: '', documentName: ROOM }), /Missing collaboration token/)
})

test('a read-only context never stores, whatever the document holds', async () => {
  const { hooks, stores } = hooksAnswering({ allowed: true, mode: 'ro' })
  const document = new Y.Doc()
  document.getXmlFragment('default').insert(0, [new Y.XmlText('viewer typing')])

  await hooks.onStoreDocument({ documentName: ROOM, document, lastContext: { token: 'jwt-ro', mode: 'ro', readOnly: true } })
  await hooks.onStoreDocument({ documentName: ROOM, document, lastContext: undefined, context: undefined })

  assert.equal(stores.length, 0)
})

test('an expired token stops every further message', async () => {
  const { hooks } = hooksAnswering({ allowed: true, mode: 'rw' })

  await assert.doesNotReject(hooks.beforeHandleMessage({ context: { exp: Math.floor(Date.now() / 1000) + 60 } }))
  await assert.rejects(hooks.beforeHandleMessage({ context: { exp: Math.floor(Date.now() / 1000) - 1 } }), /expired/)
})

test('a reconnect with an expired token is refused with the reason the client renews its session on', async () => {
  const calls = []
  const hooks = createHooks({ webhook: { authenticate: async (args) => { calls.push(args); return { allowed: true, mode: 'rw', userId: '1' } } } })
  const expired = token({ exp: Math.floor(Date.now() / 1000) - 5 })

  await assert.rejects(
    hooks.onAuthenticate({ token: expired, documentName: ROOM }),
    (error) => error.reason === 'Collaboration token expired' && error.message === 'Collaboration token expired',
  )
  // Refused before the backend is asked: it would answer an opaque 401.
  assert.equal(calls.length, 0)
})

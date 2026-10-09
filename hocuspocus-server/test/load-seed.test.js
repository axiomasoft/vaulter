import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as Y from 'yjs'
import { TiptapTransformer } from '@hocuspocus/transformer'
import { createHooks } from '../src/index.js'
import { FIELD, extensionsFor } from '../src/schema.js'

// onLoadDocument against a stub backend: the hub loads the stored Yjs state, or seeds
// the room from JSON exactly once — never twice, never on a client.

const ROOM = 'vaulter.documents.1.42.0'

function doc(text) {
  return { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text }] }] }
}

function stateOf(json) {
  return Buffer.from(Y.encodeStateAsUpdate(TiptapTransformer.toYdoc(json, FIELD, extensionsFor('vaulter/rich-text')))).toString('base64')
}

function textOf(document) {
  return JSON.stringify(TiptapTransformer.fromYdoc(document, FIELD))
}

function stubBackend({ loads, store = async () => ({ ok: true, data: {} }) }) {
  const calls = { load: [], store: [] }
  const queue = [...loads]

  return {
    calls,
    webhook: {
      authenticate: async () => ({ allowed: true, mode: 'rw', userId: '1' }),
      loadDocument: async (args) => {
        calls.load.push(args)
        return queue.shift()
      },
      storeDocument: async (args) => {
        calls.store.push(args)
        return store(args)
      },
    },
  }
}

const rw = { token: 'jwt-rw', mode: 'rw', readOnly: false }

test('applies the stored Yjs state and stores nothing', async () => {
  const backend = stubBackend({ loads: [{ state: stateOf(doc('Stored')), content: null, type: 'vaulter/rich-text' }] })
  const document = new Y.Doc()

  await createHooks({ webhook: backend.webhook }).onLoadDocument({ documentName: ROOM, document, context: rw })

  assert.match(textOf(document), /Stored/)
  assert.equal(backend.calls.store.length, 0)
  assert.deepEqual(backend.calls.load[0], { token: 'jwt-rw', room: ROOM })
})

test('seeds a document without Yjs state once, with the seed flag and its projection', async () => {
  const backend = stubBackend({ loads: [{ state: null, content: doc('From JSON'), type: 'vaulter/rich-text' }] })
  const document = new Y.Doc()

  await createHooks({ webhook: backend.webhook }).onLoadDocument({ documentName: ROOM, document, context: rw })

  assert.equal(backend.calls.store.length, 1)
  const [seed] = backend.calls.store
  assert.equal(seed.seed, true)
  assert.equal(seed.room, ROOM)
  assert.equal(seed.token, 'jwt-rw')
  assert.deepEqual(seed.content, doc('From JSON'))
  // The room holds exactly the stored seed.
  const stored = new Y.Doc()
  Y.applyUpdate(stored, Buffer.from(seed.state, 'base64'))
  assert.equal(textOf(document), textOf(stored))
  assert.equal(textOf(document).match(/From JSON/g)?.length, 1)
})

test('takes the other instance\'s seed after a seed conflict instead of merging both', async () => {
  const winner = stateOf(doc('Seeded elsewhere'))
  const backend = stubBackend({
    loads: [
      { state: null, content: doc('Seeded elsewhere'), type: 'vaulter/rich-text' },
      { state: winner, content: null, type: 'vaulter/rich-text' },
    ],
    store: async () => ({ ok: false, status: 409, code: 'collaboration_seed_conflict' }),
  })
  const document = new Y.Doc()

  await createHooks({ webhook: backend.webhook }).onLoadDocument({ documentName: ROOM, document, context: rw })

  assert.equal(backend.calls.load.length, 2)
  // Our own seed was never applied: the text appears once, from the winner's state.
  assert.equal(textOf(document).match(/Seeded elsewhere/g)?.length, 1)
  const expected = new Y.Doc()
  Y.applyUpdate(expected, Buffer.from(winner, 'base64'))
  assert.deepEqual(Y.encodeStateVector(document), Y.encodeStateVector(expected))
})

test('a read-only connection shows the seed but never stores it (single instance)', async () => {
  const backend = stubBackend({ loads: [{ state: null, content: doc('Viewer'), type: 'vaulter/rich-text' }] })
  const document = new Y.Doc()

  await createHooks({ webhook: backend.webhook, shared: false }).onLoadDocument({
    documentName: ROOM, document, context: { token: 'jwt-ro', mode: 'ro', readOnly: true },
  })

  assert.match(textOf(document), /Viewer/)
  assert.equal(backend.calls.store.length, 0)
})

test('with a shared Y.Doc (Redis) a read-only connection applies no seed of its own', async () => {
  const backend = stubBackend({ loads: [{ state: null, content: doc('Viewer'), type: 'vaulter/rich-text' }] })
  const document = new Y.Doc()

  await createHooks({ webhook: backend.webhook, shared: true, log: { warn: () => {} } }).onLoadDocument({
    documentName: ROOM, document, context: { token: 'jwt-ro', mode: 'ro', readOnly: true },
  })

  assert.equal(Y.encodeStateAsUpdate(document).length, Y.encodeStateAsUpdate(new Y.Doc()).length)
  assert.equal(backend.calls.store.length, 0)
})

test('a seed the backend refused never enters the room', async () => {
  const backend = stubBackend({
    loads: [{ state: null, content: doc('Refused'), type: 'vaulter/rich-text' }],
    store: async () => ({ ok: false, status: 422, code: 'invalid_document_content' }),
  })
  const document = new Y.Doc()

  await assert.rejects(
    createHooks({ webhook: backend.webhook, log: { warn: () => {} } }).onLoadDocument({ documentName: ROOM, document, context: rw }),
    /seed refused/,
  )
  assert.equal(textOf(document).includes('Refused'), false)
})

test('refuses to load a room whose generation ended while seeding', async () => {
  const backend = stubBackend({
    loads: [{ state: null, content: doc('Old'), type: 'vaulter/rich-text' }],
    store: async () => ({ ok: false, status: 409, code: 'collaboration_epoch_mismatch' }),
  })
  const hooks = createHooks({ webhook: backend.webhook })

  await assert.rejects(hooks.onLoadDocument({ documentName: ROOM, document: new Y.Doc(), context: rw }), /epoch_changed/)
  assert.equal(hooks.isFenced(ROOM), true)
})

import { test } from 'node:test'
import { readFileSync } from 'node:fs'
import assert from 'node:assert/strict'
import * as Y from 'yjs'
import { TiptapTransformer } from '@hocuspocus/transformer'
import { Hocuspocus } from '@hocuspocus/server'
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

test('a single instance never caches a viewer seed that overwrites a later REST update', async () => {
  const backend = stubBackend({ loads: [
    { state: null, content: doc('Draft'), type: 'vaulter/rich-text', stateVersion: 1 },
    { state: null, content: doc('Newer REST content'), type: 'vaulter/rich-text', stateVersion: 2 },
  ] })
  const hooks = createHooks({ webhook: backend.webhook, log: { warn: () => {} } })
  const loadedDocuments = []
  const server = new Hocuspocus({ onLoadDocument: async (payload) => {
    loadedDocuments.push(payload.document)
    await hooks.onLoadDocument(payload)
  } })
  const request = new Request('http://localhost')
  const connection = { readOnly: true, isAuthenticated: true }

  try {
    await assert.rejects(server.createDocument(ROOM, request, 'viewer', connection, {
      token: 'jwt-ro', mode: 'ro', readOnly: true,
    }), /not initialised/)
    assert.equal(server.documents.has(ROOM), false)
    assert.equal(backend.calls.store.length, 0)

    // Hocuspocus must load again after REST changed the unseeded document.
    const writer = await server.createDocument(ROOM, request, 'writer', { ...connection, readOnly: false }, rw)
    assert.equal(backend.calls.load.length, 2)
    assert.equal(backend.calls.store[0].seed, true)
    assert.equal(backend.calls.store[0].stateVersion, 2)
    assert.deepEqual(backend.calls.store[0].content, doc('Newer REST content'))
    await hooks.onStoreDocument({ documentName: ROOM, document: writer, lastContext: rw })
    assert.deepEqual(backend.calls.store[1].content, doc('Newer REST content'))

    // A viewer may reuse a room only after the backend accepted its seed.
    const viewer = await server.createDocument(ROOM, request, 'viewer', connection, { token: 'jwt-ro', mode: 'ro' })
    assert.equal(viewer, writer)
    assert.match(textOf(viewer), /Newer REST content/)
    assert.equal(textOf(viewer).includes('Draft'), false)
  } finally {
    for (const document of loadedDocuments) document.destroy()
    server.documents.clear()
  }
})

test('with a shared Y.Doc (Redis) a read-only connection to an unseeded document is refused, not cached empty', async () => {
  const backend = stubBackend({
    loads: [
      { state: null, content: doc('Viewer'), type: 'vaulter/rich-text' },
      { state: null, content: doc('Viewer'), type: 'vaulter/rich-text' },
    ],
  })
  const hooks = createHooks({ webhook: backend.webhook, shared: true, log: { warn: () => {} } })
  const document = new Y.Doc()

  await assert.rejects(
    hooks.onLoadDocument({ documentName: ROOM, document, context: { token: 'jwt-ro', mode: 'ro', readOnly: true } }),
    /not initialised/,
  )
  assert.equal(Y.encodeStateAsUpdate(document).length, Y.encodeStateAsUpdate(new Y.Doc()).length)
  assert.equal(backend.calls.store.length, 0)

  // The writer that comes next seeds the document with its real text, not an empty one.
  const writerDoc = new Y.Doc()
  await hooks.onLoadDocument({ documentName: ROOM, document: writerDoc, context: rw })

  assert.equal(backend.calls.store.length, 1)
  assert.equal(backend.calls.store[0].seed, true)
  assert.deepEqual(backend.calls.store[0].content, doc('Viewer'))
  assert.match(textOf(writerDoc), /Viewer/)
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

test('the seed of a document with a link carries only attributes the backend allows', async () => {
  const withLink = {
    type: 'doc',
    content: [{ type: 'paragraph', content: [{ type: 'text', text: 'see', marks: [{ type: 'link', attrs: { href: 'https://example.com' } }] }] }],
  }
  const backend = stubBackend({ loads: [{ state: null, content: withLink, type: 'vaulter/rich-text' }] })

  await createHooks({ webhook: backend.webhook }).onLoadDocument({ documentName: ROOM, document: new Y.Doc(), context: rw })

  const [seed] = backend.calls.store
  const attrs = seed.content.content[0].content[0].marks[0].attrs
  const fixture = JSON.parse(readFileSync(new URL('../../packages/collaboration/tests/Fixtures/tiptap-schema.json', import.meta.url), 'utf8'))
  const allowed = fixture['vaulter/rich-text'].attributes.link

  assert.equal(attrs.href, 'https://example.com')
  assert.deepEqual(Object.keys(attrs).filter((key) => !allowed.includes(key)), [])
})

test('the seed carries the state version collab/load returned', async () => {
  const backend = stubBackend({ loads: [{ state: null, content: doc('From JSON'), type: 'vaulter/rich-text', stateVersion: 5 }] })

  await createHooks({ webhook: backend.webhook }).onLoadDocument({ documentName: ROOM, document: new Y.Doc(), context: rw })

  assert.equal(backend.calls.store[0].stateVersion, 5)
})

test('a seed refused because the JSON changed since the load is rebuilt from the current JSON', async () => {
  const backend = stubBackend({
    loads: [
      { state: null, content: doc('Old'), type: 'vaulter/rich-text', stateVersion: 1 },
      { state: null, content: doc('Written over REST'), type: 'vaulter/rich-text', stateVersion: 2 },
    ],
    store: async (args) => (args.stateVersion === 1
      ? { ok: false, status: 409, code: 'collaboration_seed_conflict' }
      : { ok: true, data: {} }),
  })
  const document = new Y.Doc()

  await createHooks({ webhook: backend.webhook }).onLoadDocument({ documentName: ROOM, document, context: rw })

  assert.deepEqual(backend.calls.store.map((call) => call.stateVersion), [1, 2])
  assert.deepEqual(backend.calls.store[1].content, doc('Written over REST'))
  // The room holds the REST text once; the stale 'Old' text never entered it.
  assert.match(textOf(document), /Written over REST/)
  assert.equal(textOf(document).includes('Old'), false)
})

test('gives up when the document keeps changing under the seed', async () => {
  const loads = Array.from({ length: 4 }, (_, i) => ({ state: null, content: doc(`v${i}`), type: 'vaulter/rich-text', stateVersion: i }))
  const backend = stubBackend({ loads, store: async () => ({ ok: false, status: 409, code: 'collaboration_seed_conflict' }) })

  await assert.rejects(
    createHooks({ webhook: backend.webhook, log: { warn: () => {} } }).onLoadDocument({ documentName: ROOM, document: new Y.Doc(), context: rw }),
    /conflicted repeatedly/,
  )
  assert.equal(backend.calls.store.length, 3)
})

// A host that allows an extra attribute in the backend (tiptap.extra_attributes) but did not
// add its extension to the hub: the hub's schema would cut it from the room and save the cut
// document back.
test('refuses a seed whose JSON the hub schema cannot carry whole', async () => {
  const aligned = { type: 'doc', content: [{ type: 'paragraph', attrs: { textAlign: 'center' }, content: [{ type: 'text', text: 'Centered' }] }] }
  const backend = stubBackend({ loads: [{ state: null, content: aligned, type: 'vaulter/rich-text' }] })
  const warnings = []
  const document = new Y.Doc()

  await assert.rejects(
    createHooks({ webhook: backend.webhook, log: { warn: (message) => warnings.push(message) } })
      .onLoadDocument({ documentName: ROOM, document, context: rw }),
    (error) => /seed refused/.test(error.message) && error.message.includes('paragraph.textAlign') && !error.message.includes('center'),
  )
  assert.equal(backend.calls.store.length, 0)
  assert.equal(textOf(document).includes('Centered'), false)
})

test('a document whose attributes the schema keeps (defaults added, marks reordered) is not refused', async () => {
  const rich = {
    type: 'doc',
    content: [{ type: 'paragraph', content: [
      { type: 'text', text: 'a', marks: [{ type: 'italic' }, { type: 'bold' }, { type: 'link', attrs: { href: 'https://example.com', target: '_blank' } }] },
      { type: 'text', text: 'b', marks: [{ type: 'italic' }, { type: 'bold' }, { type: 'link', attrs: { href: 'https://example.com', target: '_blank' } }] },
    ] }],
  }
  const backend = stubBackend({ loads: [{ state: null, content: rich, type: 'vaulter/rich-text' }] })

  await createHooks({ webhook: backend.webhook }).onLoadDocument({ documentName: ROOM, document: new Y.Doc(), context: rw })

  assert.equal(backend.calls.store.length, 1)
})

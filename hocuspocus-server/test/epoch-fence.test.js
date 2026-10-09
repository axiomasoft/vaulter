import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as Y from 'yjs'
import { TiptapTransformer } from '@hocuspocus/transformer'
import { createHooks, DOCUMENT_GONE, EPOCH_CHANGED } from '../src/index.js'
import { FIELD, extensionsFor } from '../src/schema.js'

// onStoreDocument behind the backend's epoch fence: a 409 is terminal — the hub never
// throws (Hocuspocus would retry the store forever), closes the room with 4409
// epoch_changed and drops every later store of that room until it is unloaded.

const ROOM = 'vaulter.documents.1.42.0'
const writer = { token: 'jwt-rw', mode: 'rw', readOnly: false }

function editedDocument() {
  return TiptapTransformer.toYdoc(
    { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Edit' }] }] },
    FIELD,
    extensionsFor('vaulter/rich-text'),
  )
}

function fakeInstance(count = 2) {
  const closed = []
  const connections = Array.from({ length: count }, () => ({ close: (event) => closed.push(event) }))

  return {
    closed,
    instance: { documents: new Map([[ROOM, { getConnections: () => connections }]]) },
  }
}

function backendAnswering(answer) {
  const stores = []

  return {
    stores,
    webhook: {
      storeDocument: async (args) => {
        stores.push(args)
        return typeof answer === 'function' ? answer(args) : answer
      },
    },
  }
}

test('sends the binary state and its JSON projection with the writer\'s token', async () => {
  const backend = backendAnswering({ ok: true, data: {} })
  const document = editedDocument()

  await createHooks({ webhook: backend.webhook }).onStoreDocument({ documentName: ROOM, document, lastContext: writer })

  const [store] = backend.stores
  assert.equal(store.room, ROOM)
  assert.equal(store.token, 'jwt-rw')
  assert.equal(store.seed, undefined)
  assert.deepEqual(Buffer.from(store.state, 'base64'), Buffer.from(Y.encodeStateAsUpdate(document)))
  assert.deepEqual(store.content, TiptapTransformer.fromYdoc(document, FIELD))
})

for (const code of ['collaboration_epoch_mismatch', 'collaboration_seed_conflict']) {
  test(`a 409 ${code} closes the room with 4409 epoch_changed and never throws`, async () => {
    const backend = backendAnswering({ ok: false, status: 409, code })
    const { instance, closed } = fakeInstance()
    const hooks = createHooks({ webhook: backend.webhook })

    await hooks.onStoreDocument({ documentName: ROOM, document: editedDocument(), lastContext: writer, instance })

    assert.deepEqual(closed, [EPOCH_CHANGED, EPOCH_CHANGED])
    assert.equal(hooks.isFenced(ROOM), true)

    // The room's next debounced store (e.g. on unload) is dropped, not sent.
    await hooks.onStoreDocument({ documentName: ROOM, document: editedDocument(), lastContext: writer, instance })
    assert.equal(backend.stores.length, 1)
  })
}

test('the fence ends when Hocuspocus unloads the room', async () => {
  const backend = backendAnswering({ ok: false, status: 409, code: 'collaboration_epoch_mismatch' })
  const { instance } = fakeInstance()
  const hooks = createHooks({ webhook: backend.webhook })

  await hooks.onStoreDocument({ documentName: ROOM, document: editedDocument(), lastContext: writer, instance })
  await hooks.afterUnloadDocument({ documentName: ROOM })

  assert.equal(hooks.isFenced(ROOM), false)
})

test('a removed document closes the room with 4404 and is not retried', async () => {
  const backend = backendAnswering({ ok: false, status: 404, code: 'node_not_found' })
  const { instance, closed } = fakeInstance(1)

  await createHooks({ webhook: backend.webhook }).onStoreDocument({ documentName: ROOM, document: editedDocument(), lastContext: writer, instance })

  assert.deepEqual(closed, [DOCUMENT_GONE])
})

test('a backend outage throws so Hocuspocus retries the store', async () => {
  const backend = backendAnswering(() => {
    throw new Error('collab/store failed (503)')
  })
  const hooks = createHooks({ webhook: backend.webhook })

  await assert.rejects(hooks.onStoreDocument({ documentName: ROOM, document: editedDocument(), lastContext: writer }), /503/)
  assert.equal(hooks.isFenced(ROOM), false)
})

test('another refusal is logged without the token and not retried', async () => {
  const backend = backendAnswering({ ok: false, status: 422, code: 'invalid_document_content' })
  const logged = []

  await createHooks({ webhook: backend.webhook, log: { error: (message) => logged.push(message) } })
    .onStoreDocument({ documentName: ROOM, document: editedDocument(), lastContext: writer })

  assert.equal(logged.length, 1)
  assert.match(logged[0], /422 invalid_document_content/)
  assert.equal(logged[0].includes('jwt-rw'), false)
})

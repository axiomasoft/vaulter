import { test } from 'node:test'
import assert from 'node:assert/strict'
import * as Y from 'yjs'
import { HocuspocusProvider } from '@hocuspocus/provider'
import { TiptapTransformer } from '@hocuspocus/transformer'

// End to end against a real hub and a real Laravel host (scripts/collab-e2e.sh starts
// both). Proves what no stub can: the hub seeds once on the server, a restore during a
// live session fences the old room (4409 epoch_changed), the reopened room shows the
// restored document and the old edit never comes back; read-only stays read-only.

const BACKEND = process.env.VAULTER_E2E_BACKEND
const HUB = process.env.VAULTER_E2E_HUB

if (!BACKEND || !HUB) {
  throw new Error('Run through scripts/collab-e2e.sh (VAULTER_E2E_BACKEND and VAULTER_E2E_HUB are required).')
}

const API = `${BACKEND}/api/vaulter`
const clients = []

async function json(url, { method = 'GET', user, body } = {}) {
  const response = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(user === undefined ? {} : { 'X-E2E-User': String(user) }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const payload = await response.json().catch(() => null)

  if (!response.ok) {
    throw new Error(`${method} ${url} → ${response.status} ${JSON.stringify(payload)}`)
  }

  return payload
}

async function eventually(probe, what, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs
  let last

  while (Date.now() < deadline) {
    last = await probe()
    if (last) {
      return last
    }
    await new Promise((resolve) => setTimeout(resolve, 100))
  }

  throw new Error(`timed out waiting for ${what}`)
}

function session(nodeId, user, schemaVersion) {
  return json(`${API}/nodes/${nodeId}/collab/token`, {
    method: 'POST',
    user,
    body: schemaVersion === undefined ? {} : { schema_version: schemaVersion },
  }).then((payload) => payload.data)
}

function text(doc) {
  return JSON.stringify(TiptapTransformer.fromYdoc(doc, 'default'))
}

function type(doc, words) {
  const paragraph = new Y.XmlElement('paragraph')
  paragraph.insert(0, [new Y.XmlText(words)])
  doc.getXmlFragment('default').push([paragraph])
}

async function connect(descriptor) {
  const doc = new Y.Doc()
  const closes = []
  const refusals = []
  const provider = new HocuspocusProvider({ url: HUB, name: descriptor.room, token: descriptor.token, document: doc })
  provider.on('close', ({ event }) => closes.push({ code: event?.code, reason: event?.reason }))
  provider.on('authenticationFailed', ({ reason }) => refusals.push(reason))
  clients.push(provider)

  await eventually(() => provider.isSynced, `sync of ${descriptor.room}`)

  return { doc, provider, closes, refusals }
}

function stored(nodeId) {
  return json(`${BACKEND}/e2e/document/${nodeId}`)
}

test.after(() => {
  for (const provider of clients) {
    provider.destroy()
  }
})

test('V12: a restore during a live session fences the old room; the reopened room shows the restored document', async () => {
  const scene = await json(`${BACKEND}/e2e/setup`)
  const writerSession = await session(scene.node_id, scene.writer_user)
  assert.equal(writerSession.mode, 'rw')
  assert.equal(writerSession.epoch, 0)

  const writer = await connect(writerSession)
  // The hub seeded the room from the JSON once, on the server.
  await eventually(() => text(writer.doc).includes('Hello from setup'), 'the seed')
  assert.equal(text(writer.doc).match(/Hello from setup/g).length, 1)

  type(writer.doc, 'Edit before restore')
  await eventually(async () => JSON.stringify((await stored(scene.node_id)).content).includes('Edit before restore'), 'the first store')

  await json(`${API}/nodes/${scene.node_id}/document-versions/${scene.first_revision_id}/restore`, { method: 'POST', user: scene.writer_user, body: {} })
  assert.equal((await stored(scene.node_id)).epoch, 1)

  // The old room keeps editing: its next store is refused and the hub closes the room.
  type(writer.doc, 'Late edit after restore')
  await eventually(() => writer.closes.some((close) => close.code === 4409 && close.reason === 'epoch_changed'), 'the 4409 close')
  writer.provider.destroy()

  const after = await stored(scene.node_id)
  assert.equal(after.epoch, 1)
  assert.equal(JSON.stringify(after.content).includes('Late edit after restore'), false)
  assert.equal(JSON.stringify(after.content).includes('Edit before restore'), false)

  // A new session opens the next generation on a NEW Y.Doc.
  const reopenedSession = await session(scene.node_id, scene.writer_user)
  assert.equal(reopenedSession.epoch, 1)
  assert.notEqual(reopenedSession.room, writerSession.room)
  const reopened = await connect(reopenedSession)
  await eventually(() => text(reopened.doc).includes('Hello from setup'), 'the restored content')
  assert.equal(text(reopened.doc).includes('Edit before restore'), false)
  assert.equal(text(reopened.doc).includes('Late edit after restore'), false)

  // The new generation writes again.
  type(reopened.doc, 'Edit after reopening')
  await eventually(async () => JSON.stringify((await stored(scene.node_id)).content).includes('Edit after reopening'), 'the new room store')
})

test('a read-only client changes nothing', async () => {
  const scene = await json(`${BACKEND}/e2e/setup`)
  const writer = await connect(await session(scene.node_id, scene.writer_user))
  await eventually(() => text(writer.doc).includes('Hello from setup'), 'the seed')

  const viewerSession = await session(scene.node_id, scene.viewer_user)
  assert.equal(viewerSession.mode, 'ro')
  const viewer = await connect(viewerSession)

  type(viewer.doc, 'Viewer typing')
  type(writer.doc, 'Writer typing')
  await eventually(async () => JSON.stringify((await stored(scene.node_id)).content).includes('Writer typing'), 'the writer store')
  await new Promise((resolve) => setTimeout(resolve, 1500))

  assert.equal(JSON.stringify((await stored(scene.node_id)).content).includes('Viewer typing'), false)
  assert.equal(text(writer.doc).includes('Viewer typing'), false)
})

test('V11: a client with an older schema version is read-only', async () => {
  const scene = await json(`${BACKEND}/e2e/setup`)
  const outdated = await session(scene.node_id, scene.writer_user, 0)
  assert.equal(outdated.mode, 'ro')
  assert.equal(outdated.schema_version, 1)

  const client = await connect(outdated)
  await eventually(() => text(client.doc).includes('Hello from setup'), 'the seed shown read-only')
  type(client.doc, 'Outdated editor typing')
  await new Promise((resolve) => setTimeout(resolve, 1500))

  const document = await stored(scene.node_id)
  assert.equal(JSON.stringify(document.content).includes('Outdated editor typing'), false)
  // A read-only room shows the seed but never stores it.
  assert.equal(document.yjs_state_size, null)
})

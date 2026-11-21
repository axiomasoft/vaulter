// @ts-check
// Which write paths of Hocuspocus 4.3.0 reach a room's Y.Doc, and which of them pass beforeHandleMessage.
// Runs the real @hocuspocus/server 4.3.0 on a loopback port with Node's WebSocket client and hand-encoded
// y-protocols frames (the provider is not needed to produce exactly these frames).
//
// Run: node plan/examples/reference/live-write/hocuspocus-paths.mjs   (after npm ci in this directory)
//
// Paths (installed sources, node_modules/@hocuspocus/...):
//   sync step 2 / update  — server/src/Connection.ts processMessages() → beforeHandleMessage → MessageReceiver.apply
//                           → readSyncMessage(): skipped when connection.readOnly (MessageReceiver.ts:214, :256)
//   awareness / stateless — same queue; awareness changes no document content
//   DirectConnection      — server/src/DirectConnection.ts transact(): document.transact(fn, {source:'local'}), no hook
//   Redis                 — extension-redis/src/Redis.ts handleIncomingMessage(): new MessageReceiver(message,
//                           this.redisTransactionOrigin).apply(document, undefined, reply): no hook, no connection,
//                           so the readOnly check cannot apply
//   load                  — server/src/Hocuspocus.ts createDocument(): applies what onLoadDocument returns (hook-owned)

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { IncomingMessage, MessageReceiver, Server } from '@hocuspocus/server'
import * as decoding from 'lib0/decoding'
import * as encoding from 'lib0/encoding'
import * as awarenessProtocol from 'y-protocols/awareness'
import * as syncProtocol from 'y-protocols/sync'
import * as Y from 'yjs'

const here = path.dirname(fileURLToPath(import.meta.url))
const NAME = 'strict-room-probe'
const MESSAGE = { Sync: 0, Awareness: 1, Auth: 2, Stateless: 5, SyncStatus: 8 }

/** Kinds of a raw frame as beforeHandleMessage sees it. */
function frameKind(update) {
  const decoder = decoding.createDecoder(update)
  decoding.readVarString(decoder)
  const type = decoding.readVarUint(decoder)
  if (type === MESSAGE.Sync) {
    return ['sync_step_1', 'sync_step_2', 'update'][decoding.readVarUint(decoder)] ?? 'sync_unknown'
  }

  return { 1: 'awareness', 3: 'query_awareness', 5: 'stateless' }[type] ?? `type_${type}`
}

// Observations; every wait below is on one of these changing (no timers).
const before = []
const after = []
const updates = []
const stateless = []
let inHandler = 0
let wake = []
const poke = () => { const woken = wake; wake = []; woken.forEach((resolve) => resolve()) }
async function until(predicate, label) {
  const guard = AbortSignal.timeout(5000)
  while (!predicate()) {
    if (guard.aborted) throw new Error(`timed out waiting for ${label}`)
    await new Promise((resolve) => { wake.push(resolve); guard.addEventListener('abort', () => resolve(undefined), { once: true }) })
  }
}

/** Per-connection hold used to show that the hook does not serialize the room. */
let holdWriter = null

const server = new Server({
  port: 0,
  address: '127.0.0.1',
  quiet: true,
  stopOnSignals: false,
  async onAuthenticate({ token, connectionConfig }) {
    // 'strict-*' models the 3.5 profile: every Hocuspocus connection is read-only; writes come as envelopes.
    connectionConfig.readOnly = token.startsWith('strict')

    return { token }
  },
  async afterLoadDocument({ document }) {
    document.on('update', (_update, origin) => {
      updates.push({ source: origin?.source ?? String(origin), token: origin?.connection?.context?.token ?? null, inHandler: inHandler > 0 })
      poke()
    })
  },
  async beforeHandleMessage({ update, context }) {
    inHandler++
    before.push({ token: context.token, kind: frameKind(update) })
    poke()
    if (holdWriter && context.token === 'writer' && frameKind(update) === 'update') {
      await holdWriter.promise
    }
  },
  async afterHandleMessage({ update, context }) {
    inHandler--
    after.push({ token: context.token, kind: frameKind(update) })
    poke()
  },
  async onStateless({ payload, connection }) {
    stateless.push({ token: connection.context.token, payload })
    poke()
  },
})
await server.listen()
const url = `ws://127.0.0.1:${server.address.port}`

/** A WebSocket client speaking the Hocuspocus frames by hand. */
async function connect(token) {
  const socket = new WebSocket(url)
  socket.binaryType = 'arraybuffer'
  const received = []
  socket.addEventListener('message', (event) => {
    const decoder = decoding.createDecoder(new Uint8Array(/** @type {ArrayBuffer} */ (event.data)))
    decoding.readVarString(decoder)
    const type = decoding.readVarUint(decoder)
    received.push({ type, value: type === MESSAGE.Auth || type === MESSAGE.SyncStatus ? decoding.readVarUint(decoder) : null })
    poke()
  })
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }) })
  const frame = (write) => {
    const encoder = encoding.createEncoder()
    encoding.writeVarString(encoder, NAME)
    write(encoder)
    socket.send(encoding.toUint8Array(encoder))
  }
  frame((encoder) => { encoding.writeVarUint(encoder, MESSAGE.Auth); encoding.writeVarUint(encoder, 0); encoding.writeVarString(encoder, token) })
  await until(() => received.some((message) => message.type === MESSAGE.Auth && message.value === 2), `${token} authenticated`)

  return { socket, received, frame, token }
}

function paragraphUpdate(text) {
  const doc = new Y.Doc()
  const paragraph = new Y.XmlElement('paragraph')
  doc.getXmlFragment('default').insert(0, [paragraph])
  const node = new Y.XmlText()
  paragraph.insert(0, [node])
  node.insert(0, text)

  return { doc, update: Y.encodeStateAsUpdate(doc) }
}

const afterCount = (token, kind) => after.filter((entry) => entry.token === token && entry.kind === kind).length
const rows = []
function record(pathName, symbol, hook, applied, note) {
  rows.push({ path: pathName, symbol, beforeHandleMessage: hook, appliedToDocument: applied, note })
}

const writer = await connect('writer')
const strict = await connect('strict')
const writer2 = await connect('writer2')

// 1. Writer sync step 2 and update: through the hook, applied inside the hook window.
const step2 = paragraphUpdate('sync step 2')
writer.frame((encoder) => { encoding.writeVarUint(encoder, MESSAGE.Sync); syncProtocol.writeSyncStep2(encoder, step2.doc) })
await until(() => afterCount('writer', 'sync_step_2') === 1, 'writer sync step 2')
writer.frame((encoder) => { encoding.writeVarUint(encoder, MESSAGE.Sync); syncProtocol.writeUpdate(encoder, paragraphUpdate('update').update) })
await until(() => afterCount('writer', 'update') === 1, 'writer update')
const writerUpdates = updates.filter((entry) => entry.token === 'writer')
assert.equal(writerUpdates.length, 2)
assert.ok(writerUpdates.every((entry) => entry.source === 'connection' && entry.inHandler))
record('sync step 2 (writable connection)', 'server/src/Connection.ts processMessages → MessageReceiver.readSyncMessage', true, true, 'origin {source:"connection"}; applied before any commit')
record('update (writable connection)', 'server/src/Connection.ts processMessages → MessageReceiver.readSyncMessage', true, true, 'origin {source:"connection"}; applied before any commit')

// 2. Read-only (strict) connection: Hocuspocus itself refuses to apply and answers SyncStatus 0.
const updatesBeforeStrict = updates.length
strict.frame((encoder) => { encoding.writeVarUint(encoder, MESSAGE.Sync); syncProtocol.writeUpdate(encoder, paragraphUpdate('strict update').update) })
strict.frame((encoder) => { encoding.writeVarUint(encoder, MESSAGE.Sync); syncProtocol.writeSyncStep2(encoder, paragraphUpdate('strict step 2').doc) })
await until(() => afterCount('strict', 'update') === 1 && afterCount('strict', 'sync_step_2') === 1, 'strict frames handled')
assert.equal(updates.length, updatesBeforeStrict)
await until(() => strict.received.filter((message) => message.type === MESSAGE.SyncStatus).length === 2, 'strict SyncStatus replies')
assert.deepEqual(strict.received.filter((message) => message.type === MESSAGE.SyncStatus).map((message) => message.value), [0, 0])
record('sync step 2 / update (readOnly connection)', 'MessageReceiver.ts readSyncMessage: connection?.readOnly', true, false, 'SyncStatus 0 to the client; the strict-room barrier relies on this')

// 3. Awareness and stateless pass the hook; awareness changes no document; stateless reaches onStateless.
const awarenessDoc = new Y.Doc()
const awareness = new awarenessProtocol.Awareness(awarenessDoc)
awareness.setLocalState({ user: 'writer' })
writer.frame((encoder) => { encoding.writeVarUint(encoder, MESSAGE.Awareness); encoding.writeVarUint8Array(encoder, awarenessProtocol.encodeAwarenessUpdate(awareness, [awarenessDoc.clientID])) })
writer.frame((encoder) => { encoding.writeVarUint(encoder, MESSAGE.Stateless); encoding.writeVarString(encoder, '{"t":"u"}') })
await until(() => afterCount('writer', 'awareness') === 1 && afterCount('writer', 'stateless') === 1 && stateless.length === 1, 'awareness and stateless')
assert.equal(updates.length, updatesBeforeStrict)
record('awareness', 'MessageReceiver.ts case Awareness → beforeHandleAwareness', true, false, 'presence only, no Y.Doc content')
record('stateless envelope', 'MessageReceiver.ts case Stateless → onStateless', true, false, 'the Hub envelope path; the strict room sends it to the sequencer')

// 4. The hook is per connection: a held writer does not stop another connection.
let release = () => {}
holdWriter = { promise: new Promise((resolve) => { release = resolve }) }
writer.frame((encoder) => { encoding.writeVarUint(encoder, MESSAGE.Sync); syncProtocol.writeUpdate(encoder, paragraphUpdate('held').update) })
await until(() => before.filter((entry) => entry.token === 'writer' && entry.kind === 'update').length === 2, 'writer held in its hook')
writer2.frame((encoder) => { encoding.writeVarUint(encoder, MESSAGE.Sync); syncProtocol.writeUpdate(encoder, paragraphUpdate('overtakes').update) })
await until(() => updates.some((entry) => entry.token === 'writer2'), 'writer2 applied while writer is held')
assert.equal(updates.filter((entry) => entry.token === 'writer').length, 2, 'held writer update not applied yet')
release()
holdWriter = null
await until(() => updates.filter((entry) => entry.token === 'writer').length === 3, 'held writer applied after release')
record('two connections', 'Connection.ts messageQueue is per connection', true, true, 'writer2 applied while writer waited in beforeHandleMessage: the hook alone is not a room barrier')

// 5. DirectConnection.transact (what Hub serverWrite uses): no hook at all.
const beforeCount = before.length
const direct = await server.hocuspocus.openDirectConnection(NAME, { token: 'server' })
await direct.transact((document) => { document.getXmlFragment('default').insert(0, [new Y.XmlElement('paragraph')]) })
const directEntry = updates.at(-1)
assert.equal(directEntry.source, 'local')
assert.equal(directEntry.inHandler, false)
assert.equal(before.length, beforeCount)
await direct.disconnect({ unloadImmediately: false })
record('DirectConnection.transact / Hub serverWrite', 'server/src/DirectConnection.ts transact', false, true, 'origin {source:"local"}; bypass')

// 6. Redis: exactly the two statements of Redis.handleIncomingMessage (pinned below in the installed dist).
const redisDist = readFileSync(path.join(here, 'node_modules/@hocuspocus/extension-redis/dist/hocuspocus-redis.esm.js'), 'utf8')
assert.ok(redisDist.includes('await new MessageReceiver(message, this.redisTransactionOrigin).apply(document, void 0, (reply) => {'), 'Redis 4.3.0 applies without a connection')
const redisSource = readFileSync(path.join(here, 'node_modules/@hocuspocus/extension-redis/src/Redis.ts'), 'utf8')
assert.ok(redisSource.includes('source: "redis"'))
const document = server.hocuspocus.documents.get(NAME)
const encoder = encoding.createEncoder()
encoding.writeVarString(encoder, NAME)
encoding.writeVarUint(encoder, MESSAGE.Sync)
syncProtocol.writeUpdate(encoder, paragraphUpdate('from another instance').update)
const message = new IncomingMessage(encoding.toUint8Array(encoder))
const documentName = message.readVarString()
message.writeVarString(documentName)
await new MessageReceiver(message, { source: 'redis' }).apply(document, undefined, () => {})
const redisEntry = updates.at(-1)
assert.equal(redisEntry.source, 'redis')
assert.equal(redisEntry.inHandler, false)
assert.equal(before.length, beforeCount)
record('Redis pub/sub sync / update', 'extension-redis/src/Redis.ts handleIncomingMessage', false, true, 'connection undefined: readOnly cannot apply; bypass')

for (const client of [writer, strict, writer2]) client.socket.close()
await server.destroy()

process.stdout.write(`Hocuspocus ${JSON.parse(readFileSync(path.join(here, 'node_modules/@hocuspocus/server/package.json'), 'utf8')).version}, yjs ${JSON.parse(readFileSync(path.join(here, 'node_modules/yjs/package.json'), 'utf8')).version}, node ${process.version}\n`)
console.table(rows)
const bypasses = rows.filter((row) => row.appliedToDocument && !row.beforeHandleMessage).map((row) => row.path)
assert.deepEqual(bypasses, ['DirectConnection.transact / Hub serverWrite', 'Redis pub/sub sync / update'])
process.stdout.write(`OK hocuspocus-paths: bypasses of beforeHandleMessage = ${JSON.stringify(bypasses)}\n`)
process.exit(0)

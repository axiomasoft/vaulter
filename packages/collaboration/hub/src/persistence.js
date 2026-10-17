import crypto from 'node:crypto'
import * as Y from 'yjs'
import { TiptapTransformer } from '@hocuspocus/transformer'
import * as backendModule from './backend.js'
import { config as defaultConfig } from './config.js'
import { createLedger } from './ledger.js'
import { retryPolicy } from './retry.js'
import { closeConnection, closeRoom, DOCUMENT_GONE, EPOCH_CHANGED, FORBIDDEN, TOKEN_EXPIRED } from './rooms.js'
import { extensionsFor, FIELD, lostAttributes } from './schema.js'

// The hub's persistence, protocol 2 (D26). The room is never stored as a whole: every Yjs update
// that must reach the backend is appended once, as one log record, by the pending ledger
// (src/ledger.js), and only the committed receipt is acknowledged.
//
// Where updates come from, and who appends them:
//   (a) a client's envelope — the stateless message {"t":"u","v":2,"g":G,"id":uuid,"h":sha256,
//       "b":base64} sent with each local Yjs update: the hub that holds the connection checks it,
//       applies it to the room (a no-op when the sync message already did) and appends it under
//       the client's update_id; the client gets {"t":"ack","id","seq","g"} after the commit, or
//       {"t":"nack","id","code","retry"};
//   (b) a server/direct change (openDirectConnection, serverWrite(), any transaction that is not a
//       client's, Redis' or the hub's own): the hub gives it an update_id and appends it once;
//   (c) a change that arrived through the Redis extension: never appended here — the hub that
//       took it from its client (or made it) appends it;
//   (d) sync step 1/2 and y-protocols updates: never appended and never acknowledged — a client
//       repeats an unacknowledged update as an envelope with the same update_id.
//
// A room is loaded from its log: the checkpoint and the committed tail (C, H] replayed in seq
// order. A generation without checkpoint and without log is seeded once from the document's JSON:
// the seed is the first record of the log (appended with seed_state_version, which the backend
// refuses with 409 collaboration_seed_conflict when the log is no longer empty or the JSON moved
// on), so every hub — and the compaction worker — rebuilds the same Y.Doc from the log alone.
//
// Modes (VAULTER_COLLAB_MODE):
//   buffered (default) — sync reaches the other clients before the append; the ack still waits
//     for the commit. Pending bytes are bounded per room and in total: past either budget, writes
//     stop in every room (status writes:stopped; writers' updates are dropped, envelopes nacked
//     retry:true) until the ledger drains below. A killed hub loses what was pending (no RPO).
//   durable — the update enters the room but the ack waits for the commit, and while the backend
//     does not answer (an exhausted retry series) writes stop in every room and new envelopes are
//     nacked retry:true: the client keeps them queued. serverWrite() returns after the append.
//
// The hub tells each connection {"t":"status","mode","writes":"open|stopped"} when it connects and
// whenever the writes switch.

/** The client ↔ hub protocol this hub speaks; a client without it is read-only. */
export const CLIENT_PROTOCOL = 2

/** Codes of a nack the hub decides itself (the backend's problem codes are passed through). */
export const NACK = Object.freeze({
  INVALID_ENVELOPE: 'invalid_envelope',
  INVALID_UPDATE: 'invalid_update',
  FORBIDDEN: 'forbidden',
  WRITES_STOPPED: 'writes_stopped',
  UPDATE_MISMATCH: 'collaboration_update_mismatch',
})

/** How many times a seed is rebuilt after a seed conflict before the load gives up. */
const SEED_ATTEMPTS = 3

const OWN = Symbol('vaulter.collab.own-origin')

/** The origin the hub replays a loaded log with: never appended again. */
export const LOAD_ORIGIN = Object.freeze({ [OWN]: true, source: 'vaulter-load' })

/** The Redis extension of Hocuspocus v2 marked its transactions with this string. */
const LEGACY_REDIS_ORIGIN = '__hocuspocus__redis__origin__'

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const HEX64 = /^[0-9a-f]{64}$/i
const BASE64 = /^[A-Za-z0-9+/]+={0,2}$/

export function sha256Hex(bytes) {
  return crypto.createHash('sha256').update(bytes).digest('hex')
}

function base64(bytes) {
  return Buffer.from(bytes).toString('base64')
}

/** The origin a client's envelope is applied with: the client's own change, never appended twice. */
function envelopeOrigin(connection) {
  return { [OWN]: true, source: 'vaulter-envelope', connection }
}

function isRedisOrigin(origin, redisOrigins) {
  if (origin === null || origin === undefined) {
    return false
  }
  if (origin === LEGACY_REDIS_ORIGIN || redisOrigins.includes(origin)) {
    return true
  }
  if (typeof origin !== 'object') {
    return false
  }

  return origin.source === 'redis'
    || redisOrigins.some((extension) => extension?.redisTransactionOrigin !== undefined && extension.redisTransactionOrigin === origin)
    || (origin.constructor?.name === 'Redis' && typeof origin.onConfigure === 'function')
}

/** A Hocuspocus connection is the origin of a client's sync step 2 / update. */
function isConnectionOrigin(origin) {
  if (!origin || typeof origin !== 'object') {
    return false
  }

  return origin.source === 'connection'
    || (typeof origin.sendStateless === 'function' && ('webSocket' in origin || 'socketId' in origin))
}

/**
 * How an update reached a room, from its transaction origin: 'own' (the hub's replay or a client's
 * envelope), 'redis', 'client' (a connection's sync message) or 'server' — everything else, the
 * changes the hub must append itself (direct connections, server transformations).
 */
export function originKind(origin, { redisOrigins = [] } = {}) {
  if (origin && typeof origin === 'object' && origin[OWN] === true) {
    return 'own'
  }
  if (isRedisOrigin(origin, redisOrigins)) {
    return 'redis'
  }
  if (isConnectionOrigin(origin)) {
    return 'client'
  }

  return 'server'
}

/**
 * Replay a loaded log onto a Y.Doc: the checkpoint, then the tail in seq order. True when the log
 * held anything (a generation that needs no seed). The compaction worker rebuilds a document the
 * same way.
 */
export function replayLog(document, { checkpoint = null, tail = [] } = {}, origin = LOAD_ORIGIN) {
  let applied = false

  if (typeof checkpoint === 'string' && checkpoint !== '') {
    Y.applyUpdate(document, Buffer.from(checkpoint, 'base64'), origin)
    applied = true
  }

  for (const record of [...(tail ?? [])].sort((left, right) => left.seq - right.seq)) {
    Y.applyUpdate(document, Buffer.from(record.payload, 'base64'), origin)
    applied = true
  }

  return applied
}

/**
 * The seed update of a document's JSON. Fail-closed: the hub's TipTap schema only knows the
 * extensions in src/schema.js, and ProseMirror silently drops what its schema does not declare — a
 * host's extra attribute (vaulter-documents.tiptap.extra_attributes) would vanish from the room
 * and from every checkpoint. So a document whose JSON the schema cannot carry whole is refused with
 * the names of what would be lost, never seeded in a cut form.
 */
export function buildSeed(loaded) {
  const seed = TiptapTransformer.toYdoc(loaded.content, FIELD, extensionsFor(loaded.type))
  const projection = TiptapTransformer.fromYdoc(seed, FIELD)
  const lost = lostAttributes(loaded.content, projection)

  if (lost.length > 0) {
    seed.destroy()
    throw new Error(
      `Collaboration seed refused: the hub's TipTap schema would drop ${lost.join(', ')} from the document. `
      + 'Add the extension that declares them to packages/collaboration/hub/src/schema.js (see the README).',
    )
  }

  const update = Y.encodeStateAsUpdate(seed)
  seed.destroy()

  return update
}

function readVarUint(bytes, cursor) {
  let value = 0
  let multiplier = 1

  for (;;) {
    if (cursor.position >= bytes.length || multiplier > 2 ** 49) {
      return null
    }

    const byte = bytes[cursor.position++]
    value += (byte & 0x7f) * multiplier

    if (byte < 0x80) {
      return value
    }

    multiplier *= 0x80
  }
}

/** Hocuspocus message types that carry a Yjs sync message (Sync, SyncReply). */
const SYNC_MESSAGES = Object.freeze([0, 4])

/**
 * Whether a raw Hocuspocus message writes to the document: a sync step 2 or a y-protocols update
 * (`varString documentName · varUint type · varUint syncType …`). Step 1 and everything else read.
 */
export function isWriteMessage(bytes) {
  if (!(bytes instanceof Uint8Array)) {
    return false
  }

  const cursor = { position: 0 }
  const nameLength = readVarUint(bytes, cursor)

  if (nameLength === null) {
    return false
  }

  cursor.position += nameLength
  const type = readVarUint(bytes, cursor)

  if (!SYNC_MESSAGES.includes(type)) {
    return false
  }

  const step = readVarUint(bytes, cursor)

  return step === 1 || step === 2
}

/** The refusal of a room whose generation is over; the client reopens the document. */
export function epochChanged() {
  return Object.assign(new Error(EPOCH_CHANGED.reason), { reason: EPOCH_CHANGED.reason })
}

/** A connection that may append: a writer token, not read-only, speaking the client protocol 2. */
export function isWriter(context) {
  return context?.mode === 'rw' && context.readOnly !== true && context.protocol === CLIENT_PROTOCOL
}

/**
 * The persistence of one hub process. `backend` is the protocol 2 client (src/backend.js) and is
 * injectable with `backendOptions` ({ config, fetchImpl }) so tests run it against a mock backend;
 * time (clock, sleep, random) is injectable for the ledger. `redisOrigins` are the Redis extension
 * instances of the server (their transactions are never appended here). `serverToken(documentName)`
 * may name the token server changes are appended with; by default a connected writer's current
 * token is used.
 */
export function createPersistence({
  backend = backendModule,
  backendOptions = {},
  conf = defaultConfig,
  log = console,
  clock = Date.now,
  sleep,
  random = Math.random,
  redisOrigins = [],
  serverToken = null,
  uuid = () => crypto.randomUUID(),
} = {}) {
  const mode = conf.mode === 'durable' ? 'durable' : 'buffered'
  const policy = retryPolicy(conf)
  /** documentName → { generation, document } of a loaded room. */
  const rooms = new Map()
  /** Rooms whose generation ended or whose document is gone: nothing of theirs is appended any more. */
  const fenced = new Set()
  /**
   * documentName → Map<socketId (or the connection), { connection, context }> of the writers
   * connected to this hub; onDisconnect names the socket, not always the connection.
   */
  const writers = new Map()
  const contexts = new WeakMap()
  /** Writers set read-only while writes are stopped; given back their writes when they reopen. */
  const muted = new Set()
  let instance = null
  let writesStopped = false
  let protocolMismatch = false
  /** The records captured while serverWrite() runs its change. */
  let capturing = null

  const ledger = createLedger({
    append: (entry, { token, timeoutMs }) => backend.append({
      token,
      room: entry.documentName,
      generation: entry.generation,
      updateId: entry.updateId,
      sha256: entry.sha256,
      payload: entry.payload,
      origin: entry.origin,
      seedStateVersion: entry.seedStateVersion,
    }, { ...backendOptions, timeoutMs }),
    credentials: (entry) => entry.token ?? writerToken(entry.documentName),
    policy,
    clock,
    sleep,
    random,
    flushIntervalMs: conf.flushIntervalMs ?? 1000,
    log,
    events: {
      acked: (entry, ack) => {
        for (const connection of entry.connections) {
          send(connection, { t: 'ack', id: entry.clientId, seq: ack.seq, g: ack.generation ?? entry.generation })
        }
      },
      failed: (entry, failure) => nack(entry, failure.code, true),
      dropped: (entry, failure) => nack(entry, failure.code, failure.retry === true),
      epochMismatch: (documentName) => fence(documentName, EPOCH_CHANGED),
      documentGone: (documentName) => fence(documentName, DOCUMENT_GONE),
      forbidden: (entry) => entry.connections.forEach((connection) => closeConnection(connection, FORBIDDEN)),
      unauthorized: (entry) => entry.connections.forEach((connection) => closeConnection(connection, TOKEN_EXPIRED)),
      protocolMismatch: () => reportProtocolMismatch(),
      changed: () => evaluateWrites(),
    },
  })

  function bind(hocuspocus) {
    if (hocuspocus && instance === null) {
      instance = hocuspocus
    }
  }

  function send(connection, message) {
    try {
      connection?.sendStateless?.(JSON.stringify(message))
    } catch {
      // The connection is closing; an unacknowledged update is repeated by the client.
    }
  }

  function nack(entry, code, retry) {
    for (const connection of entry.connections) {
      send(connection, { t: 'nack', id: entry.clientId, code, retry })
    }
  }

  function contextOf(connection) {
    return contexts.get(connection) ?? connection?.context ?? null
  }

  /** A connected writer's token for a server change; re-read on every cycle, so it is a current one. */
  function writerToken(documentName) {
    if (typeof serverToken === 'function') {
      const token = serverToken(documentName)

      if (token) {
        return token
      }
    }

    const now = clock() / 1000

    for (const { context } of writers.get(documentName)?.values() ?? []) {
      if (context?.token && (typeof context.exp !== 'number' || context.exp > now)) {
        return context.token
      }
    }

    return null
  }

  function fence(documentName, close) {
    fenced.add(documentName)
    closeRoom(instance, documentName, close)
  }

  function reportProtocolMismatch() {
    if (!protocolMismatch) {
      log.error?.('Vaulter collab hub: the backend does not speak collaboration protocol 2 (409 collaboration_protocol_mismatch); nothing can be appended')
    }

    protocolMismatch = true
  }

  function allConnections() {
    const found = new Set()

    for (const document of instance?.documents?.values?.() ?? []) {
      for (const connection of typeof document?.getConnections === 'function' ? document.getConnections() : []) {
        found.add(connection)
      }
    }

    for (const room of writers.values()) {
      for (const { connection } of room.values()) {
        found.add(connection)
      }
    }

    return [...found]
  }

  function statusMessage() {
    return { t: 'status', mode, writes: writesStopped ? 'stopped' : 'open' }
  }

  /** While writes are stopped a writer is read-only: Hocuspocus drops its sync writes. */
  function gate(connection) {
    if (!connection || !isWriter(contextOf(connection))) {
      return
    }

    if (writesStopped && connection.readOnly !== true) {
      connection.readOnly = true
      muted.add(connection)
    } else if (!writesStopped && muted.has(connection)) {
      connection.readOnly = false
      muted.delete(connection)
    }
  }

  function overBudget() {
    const total = conf.pendingBudgetBytes
    const perRoom = conf.pendingRoomBudgetBytes

    if (Number.isFinite(total) && ledger.pendingBytes() > total) {
      return true
    }

    return Number.isFinite(perRoom) && ledger.documents().some((documentName) => ledger.pendingBytes(documentName) > perRoom)
  }

  function evaluateWrites() {
    const stop = mode === 'durable' ? (!ledger.available() || protocolMismatch) : overBudget()

    if (stop === writesStopped) {
      return
    }

    writesStopped = stop

    if (stop) {
      log.warn?.(`Vaulter collab hub: writes stopped in every room (${mode === 'durable' ? 'the backend does not answer' : 'the pending budget is used up'})`)
    } else {
      log.info?.('Vaulter collab hub: writes open again')
    }

    for (const connection of allConnections()) {
      gate(connection)
      send(connection, statusMessage())
    }
  }

  function capture(documentName, update, origin) {
    if (originKind(origin, { redisOrigins }) !== 'server') {
      return
    }

    const room = rooms.get(documentName)

    if (!room) {
      return
    }

    if (fenced.has(documentName)) {
      log.warn?.(`Vaulter collab hub: a server change of ${documentName} came after its generation ended; it is not appended`)

      return
    }

    const bytes = update instanceof Uint8Array ? update : new Uint8Array(update)
    const entry = ledger.enqueue({
      documentName,
      generation: room.generation,
      updateId: uuid(),
      sha256: sha256Hex(bytes),
      payload: base64(bytes),
      bytes: bytes.length,
      origin: 'server',
      token: (origin && typeof origin === 'object' ? (origin.token ?? origin.context?.token) : null) ?? null,
    })

    capturing?.push(entry)
  }

  async function loadLog(context, documentName) {
    try {
      return await backend.load({ token: context?.token, room: documentName }, backendOptions)
    } catch (error) {
      if (error?.status === 409 && error.code === backendModule.EPOCH_MISMATCH) {
        fence(documentName, EPOCH_CHANGED)
        throw epochChanged()
      }
      if (error?.status === 409 && error.code === backendModule.PROTOCOL_MISMATCH) {
        reportProtocolMismatch()
      }

      throw error
    }
  }

  /**
   * Seed an empty generation from the JSON load returned: the seed is appended as the log's first
   * record before it enters the room. A read-only connection cannot, and is refused until a writer
   * opened the document — caching an unpersisted seed would let it diverge from the log.
   */
  async function seed(documentName, document, context, loaded) {
    let current = loaded

    for (let attempt = 1; ; attempt++) {
      if (replayLog(document, current)) {
        return current
      }

      if (!current.content) {
        return current
      }

      const update = buildSeed(current)

      if (!isWriter(context)) {
        log.warn?.(`Vaulter collab hub: ${documentName} has no collaboration log yet; a read-only connection waits for a writer's seed`)

        throw new Error('Collaboration document is not initialised yet; retry once a writer has opened it')
      }

      const result = await ledger.send({
        documentName,
        generation: current.generation,
        updateId: uuid(),
        sha256: sha256Hex(update),
        payload: base64(update),
        bytes: update.length,
        origin: 'server',
        token: context.token,
        seedStateVersion: current.state_version,
      })
      // The seed's series told whether the backend answers (durable writes follow it).
      evaluateWrites()

      if (result.type === 'acked') {
        Y.applyUpdate(document, update, LOAD_ORIGIN)

        return current
      }

      if (result.code === backendModule.SEED_CONFLICT) {
        // Another hub seeded first (its seed is loaded instead — never both, which would merge into
        // duplicated content) or the JSON changed under us (seeded again from the current JSON).
        current = await loadLog(context, documentName)

        if (attempt >= SEED_ATTEMPTS && current.checkpoint === null && (current.tail ?? []).length === 0) {
          log.warn?.(`Vaulter collab hub: the seed of ${documentName} kept conflicting (${attempt} attempts)`)

          throw new Error('Collaboration seed conflicted repeatedly')
        }

        continue
      }

      if (result.type === 'epoch') {
        fence(documentName, EPOCH_CHANGED)

        throw epochChanged()
      }

      if (result.protocolMismatch) {
        reportProtocolMismatch()
      }

      // A seed the backend did not commit is not content of the room: loading it would let the
      // clients edit text that was never stored (and, with Redis, replicate it).
      log.warn?.(`Vaulter collab hub: the seed of ${documentName} was not appended (${result.status ?? 'no answer'} ${result.code ?? ''})`)

      throw new Error(`Collaboration seed refused (${result.status ?? result.code})`)
    }
  }

  /** onLoadDocument: replay the room's log (or seed it), then follow its server changes. */
  async function onLoadDocument({ documentName, document, context, instance: hocuspocus }) {
    bind(hocuspocus)
    const loaded = await seed(documentName, document, context, await loadLog(context, documentName))
    const generation = loaded.generation

    // Server changes of this room the backend has not committed yet (the room was unloaded while
    // they were pending): the room holds them again.
    for (const entry of ledger.entries(documentName)) {
      if (entry.generation === generation) {
        Y.applyUpdate(document, Buffer.from(entry.payload, 'base64'), LOAD_ORIGIN)
      }
    }

    rooms.set(documentName, { generation, document })
    document.on('update', (update, origin) => capture(documentName, update, origin))
  }

  /** A client's envelope: check it, apply it, append it; the ack follows the commit. */
  function handleEnvelope(connection, documentName, document, message) {
    const id = typeof message.id === 'string' && UUID.test(message.id) ? message.id.toLowerCase() : null
    const reply = (code, retry) => send(connection, { t: 'nack', id: typeof message.id === 'string' ? message.id : null, code, retry })

    if (id === null || !Number.isInteger(message.g) || typeof message.h !== 'string' || !HEX64.test(message.h)
      || typeof message.b !== 'string' || !BASE64.test(message.b)) {
      return reply(NACK.INVALID_ENVELOPE, false)
    }
    if (message.v !== CLIENT_PROTOCOL) {
      return reply(backendModule.PROTOCOL_MISMATCH, false)
    }

    const context = contextOf(connection)

    if (!isWriter(context)) {
      return reply(NACK.FORBIDDEN, false)
    }

    const room = rooms.get(documentName)

    if (!room || fenced.has(documentName) || message.g !== room.generation) {
      return reply(backendModule.EPOCH_MISMATCH, false)
    }

    const sha256 = message.h.toLowerCase()
    const pending = ledger.find(documentName, id)

    // Repeated while still pending (a reconnect): it waits once, and both connections get the ack.
    if (pending) {
      if (pending.sha256 !== sha256) {
        return reply(NACK.UPDATE_MISMATCH, false)
      }

      ledger.enqueue({ documentName, updateId: id, connection })

      return undefined
    }

    if (writesStopped) {
      return reply(NACK.WRITES_STOPPED, true)
    }

    const bytes = Buffer.from(message.b, 'base64')

    if (bytes.length === 0 || sha256Hex(bytes) !== sha256) {
      return reply(NACK.UPDATE_MISMATCH, false)
    }

    try {
      Y.applyUpdate(document ?? room.document, bytes, envelopeOrigin(connection))
    } catch {
      return reply(NACK.INVALID_UPDATE, false)
    }

    ledger.enqueue({
      documentName,
      generation: room.generation,
      updateId: id,
      sha256,
      payload: message.b,
      bytes: bytes.length,
      origin: 'client',
      token: context.token,
      clientId: message.id,
      connection,
    })

    return undefined
  }

  /** onStateless: envelopes are JSON objects with t:"u"; any other stateless message is not ours. */
  async function onStateless({ connection, documentName, document, payload, instance: hocuspocus }) {
    bind(hocuspocus)
    let message

    try {
      message = typeof payload === 'string' ? JSON.parse(payload) : null
    } catch {
      return
    }

    if (message?.t === 'u') {
      handleEnvelope(connection, documentName, document, message)
    }
  }

  /** connected: register a writer, apply the write gate and tell the client the mode and the writes. */
  async function connected({ connection, context, documentName, socketId, instance: hocuspocus }) {
    bind(hocuspocus)

    if (!connection) {
      return
    }

    contexts.set(connection, context)
    const room = documentName ?? connection.document?.name

    if (isWriter(context)) {
      if (!writers.has(room)) {
        writers.set(room, new Map())
      }

      writers.get(room).set(socketId ?? connection, { connection, context })
    }

    gate(connection)
    send(connection, statusMessage())
  }

  /** While writes are stopped, a writer's sync step 2 / update is not applied (it is dropped as read-only). */
  async function beforeHandleMessage({ connection, update }) {
    if (writesStopped && connection && isWriteMessage(update)) {
      gate(connection)
    }
  }

  async function onDisconnect({ connection, documentName, socketId }) {
    const room = writers.get(documentName)
    const known = room?.get(socketId ?? connection)?.connection ?? connection

    room?.delete(socketId ?? connection)

    if (known) {
      muted.delete(known)
      ledger.detach(known)
    }
  }

  async function afterUnloadDocument({ documentName }) {
    rooms.delete(documentName)
    fenced.delete(documentName)
    writers.delete(documentName)
  }

  /**
   * Apply a server change to a room and append it: `change(document)` runs in one transaction of a
   * direct connection. In durable mode this resolves only after the backend committed every update
   * of the change (and rejects when it refused one, or after `timeoutMs`); in buffered mode right
   * after the update was put into the ledger. Resolves the update_ids the change was appended with.
   */
  async function serverWrite(documentName, change, { context = {}, token = null, timeoutMs = policy.deadlineMs } = {}) {
    if (!instance) {
      throw new Error('The collaboration persistence is not attached to a Hocuspocus instance')
    }
    if (writesStopped) {
      throw Object.assign(new Error('Collaboration writes are stopped; the server change was not applied'), { code: NACK.WRITES_STOPPED })
    }

    const connection = await instance.openDirectConnection(documentName, context)
    const captured = []
    const origin = { source: 'vaulter-server', token: token ?? context?.token ?? null }

    try {
      capturing = captured
      // The loaded room itself, so the transaction carries this origin (and its token).
      const document = connection.document ?? instance.documents?.get?.(documentName) ?? null

      if (document) {
        document.transact(() => change(document), origin)
      } else {
        await connection.transact(change)
      }
    } finally {
      capturing = null
      await connection.disconnect()
    }

    if (mode === 'durable' && captured.length > 0) {
      let timer = null
      const timeout = new Promise((resolve) => {
        timer = setTimeout(() => resolve({ status: 'timeout', code: 'append_timeout' }), timeoutMs)
        timer.unref?.()
      })
      const results = await Promise.all(captured.map((entry) => Promise.race([entry.settled, timeout])))
      clearTimeout(timer)
      const failure = results.find((result) => result.status !== 'acked')

      if (failure) {
        throw Object.assign(new Error(`The server change was not appended (${failure.code})`), { code: failure.code })
      }
    }

    return captured.map((entry) => entry.updateId)
  }

  return {
    onLoadDocument,
    onStateless,
    connected,
    beforeHandleMessage,
    onDisconnect,
    afterUnloadDocument,
    serverWrite,
    reportProtocolMismatch,
    attach: bind,
    start: () => ledger.start(),
    stop: () => ledger.stop(),
    ledger,
    mode,
    isFenced: (documentName) => fenced.has(documentName),
    writesStopped: () => writesStopped,
    /** What the management listener reports (P5.9): mode, writes, protocol and the ledger. */
    state: () => ({
      mode,
      writes: writesStopped ? 'stopped' : 'open',
      protocolMismatch,
      rooms: rooms.size,
      ledger: ledger.stats(),
      oldestPendingMs: ledger.oldestAgeMs(),
    }),
  }
}

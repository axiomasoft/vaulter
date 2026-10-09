import 'dotenv/config'
import { Server } from '@hocuspocus/server'
import { Throttle } from '@hocuspocus/extension-throttle'
import { TiptapTransformer } from '@hocuspocus/transformer'
import { createServer } from 'node:http'
import * as Y from 'yjs'
import { config, isOriginAllowed, validateConfig } from './config.js'
import { extensionsFor, FIELD } from './schema.js'
import * as backend from './webhook.js'

// Vaulter collaboration hub — the external, env-configured Hocuspocus process.
//
// Flow:
//   1. The client asks Laravel for a session (POST …/nodes/{node}/collab/token) and gets a
//      room JWT and the room name "<prefix>.<driveId>.<nodeId>.<epoch>". The epoch is the
//      document's generation: a restore moves it on, so a room never outlives one.
//   2. onConnect enforces the Origin allowlist (CSWSH); onAuthenticate asks
//      collab/authenticate whether the connection is allowed and in which mode — a
//      read-only connection's updates are dropped by Hocuspocus itself.
//   3. onLoadDocument loads the room from collab/load: the binary Yjs working state, or,
//      when the document has none yet, its JSON — the hub builds the Y.Doc from it ONCE
//      and stores it as the seed (collab/store seed=true). Clients never seed: two
//      clients converting the same JSON would merge into duplicated content.
//   4. onStoreDocument (debounced) sends the binary state and its JSON projection to
//      collab/store. The backend fences stores by epoch: a 409 means the room's
//      generation is over (a restore happened). The hub does NOT throw then — retrying
//      cannot change the answer — but closes the room's connections with 4409
//      `epoch_changed` and drops any later store of that room; clients reopen the
//      document with a new Y.Doc.
//
// Hardening: Origin allowlist, Throttle (per-IP), maxPayload, perMessageDeflate off,
// token expiry enforced before every message, optional Redis (multi-instance) + Logger,
// fail-fast config validation, graceful shutdown (stopOnSignals=true).

/** Close code and reason a client sees when its room's generation is over. */
export const EPOCH_CHANGED = Object.freeze({ code: 4409, reason: 'epoch_changed' })

/** Close code for a document that was removed while its room was open. */
export const DOCUMENT_GONE = Object.freeze({ code: 4404, reason: 'document_gone' })

/**
 * Build the extension chain. Throttle is registered FIRST (the docs require it before
 * any other extension) so handshake-flood protection runs ahead of everything. Redis and
 * Logger are opt-in and lazily imported so the default (single-instance) path stays light.
 */
async function buildExtensions() {
  const extensions = [
    new Throttle({
      throttle: config.throttle,
      consideredSeconds: config.throttleSeconds,
      banTime: config.banTime,
    }),
  ]

  if (config.redisUrl) {
    // Multi-instance document + awareness sync. ioredis parses the full URL (auth, db,
    // rediss:// TLS); pub and sub need SEPARATE connections, so createClient mints one each.
    const { default: IORedis } = await import('ioredis')
    const { Redis } = await import('@hocuspocus/extension-redis')
    extensions.push(new Redis({
      identifier: config.instanceName,
      createClient: () => new IORedis(config.redisUrl),
    }))
  }

  if (config.logEnabled) {
    const { Logger } = await import('@hocuspocus/extension-logger')
    // onChange is high-cardinality and expensive at scale — keep it off.
    extensions.push(new Logger({ onChange: false }))
  }

  return extensions
}

/**
 * onConnect — the Origin gate (CSWSH defence), runs BEFORE onAuthenticate. A browser
 * supplies an Origin; it must be on the allowlist. A non-browser client (no Origin) is
 * allowed here and still gated by the auth webhook. Throwing refuses the socket.
 */
async function onConnect({ requestHeaders }) {
  const origin = readOrigin(requestHeaders)

  if (!isOriginAllowed(origin)) {
    throw new Error(`Collaboration connection refused: origin not allowed (${origin ?? 'none'})`)
  }
}

/**
 * Read the Origin header version-agnostically: Hocuspocus v2 hands a plain
 * IncomingHttpHeaders object (`headers.origin`), while v4 hands a web `Headers`
 * (`headers.get('origin')`). Reading the wrong shape would yield undefined and silently
 * disable the CSWSH allowlist, so support both.
 */
function readOrigin(requestHeaders) {
  if (!requestHeaders) {
    return undefined
  }
  if (typeof requestHeaders.get === 'function') {
    return requestHeaders.get('origin') ?? undefined
  }
  return requestHeaders.origin
}

/**
 * The `exp` (unix seconds) claim of a JWT, read WITHOUT verifying its signature — the
 * hub never holds the signing secret, and the backend verifies the token on every call.
 * This is local bookkeeping so the hub knows when to stop accepting messages; it grants
 * nothing on its own. Malformed/missing → null.
 */
function decodeTokenExpiry(token) {
  if (typeof token !== 'string') {
    return null
  }

  const segments = token.split('.')

  if (segments.length < 2) {
    return null
  }

  try {
    const payload = JSON.parse(Buffer.from(segments[1], 'base64url').toString('utf8'))

    return typeof payload.exp === 'number' ? payload.exp : null
  } catch {
    return null
  }
}

/** Close code and reason of a connection whose token has expired. */
export const TOKEN_EXPIRED = Object.freeze({ code: 4001, reason: 'Collaboration token expired' })

/**
 * Close one connection so the client sees BOTH the reason and the numeric code.
 * Hocuspocus' Connection.close() only writes the reason into the protocol (the provider
 * then reports code 1000), so the transport is closed with the code as well.
 */
function closeConnection(connection, { code, reason }) {
  connection.close({ code, reason })

  try {
    connection.webSocket?.close?.(code, reason)
  } catch {
    // The socket is already closing; the protocol close message above was the best effort.
  }
}

/**
 * Close the connection once its token expires (already expired → now). A read-only
 * viewer never sends a store, so without this its socket would outlive the token.
 * unref() so a pending timer never keeps the process alive on shutdown.
 */
function armExpiryTimer(connection, exp) {
  if (exp === null || typeof connection?.close !== 'function') {
    return
  }

  const delayMs = exp * 1000 - Date.now()

  if (delayMs <= 0) {
    closeConnection(connection, TOKEN_EXPIRED)

    return
  }

  const timer = setTimeout(() => {
    closeConnection(connection, TOKEN_EXPIRED)
  }, delayMs)

  if (typeof timer.unref === 'function') {
    timer.unref()
  }
}

/**
 * Close every local connection of a room with the given code. Hocuspocus' own
 * closeConnections() takes no code, and the client needs one to tell "reopen with a new
 * Y.Doc" from an ordinary disconnect.
 */
function closeRoom(instance, documentName, { code, reason }) {
  const document = instance?.documents?.get?.(documentName)
  const connections = typeof document?.getConnections === 'function' ? document.getConnections() : []

  for (const connection of connections) {
    closeConnection(connection, { code, reason })
  }

  if (connections.length === 0 && typeof instance?.closeConnections === 'function') {
    instance.closeConnections(documentName)
  }
}

/**
 * The refusal of a room whose generation is over. Hocuspocus sends an error's `reason`
 * to the client as the authentication failure reason, so the client can tell it from a
 * plain refusal and reopen the document with a new session.
 */
function epochChanged() {
  return Object.assign(new Error(EPOCH_CHANGED.reason), { reason: EPOCH_CHANGED.reason })
}

function base64(update) {
  return Buffer.from(update).toString('base64')
}

/**
 * The hub's Hocuspocus hooks. `webhook` is the backend client (src/webhook.js) and is
 * injectable so the hooks run in tests against a stub backend; `log` receives
 * token-free messages only.
 */
export function createHooks({
  webhook = backend,
  log = console,
  // Hosts of one room's Y.Doc beyond this process (Redis): a seed no backend has accepted
  // must not enter such a doc, or two hubs' independent seeds merge into duplicated text.
  shared = Boolean(config.redisUrl),
  // Hocuspocus 4.7 does not reschedule a store whose hook threw, so a backend outage is
  // retried here: this many extra attempts, waiting retryDelayMs × attempt between them.
  storeRetries = 3,
  retryDelayMs = 1000,
  sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
} = {}) {
  // Rooms whose generation ended (409) or whose document is gone: their later stores
  // are dropped until Hocuspocus unloads them. Keyed by room name, which includes the
  // epoch, so a new generation is never affected.
  const fenced = new Set()

  function fence(instance, documentName, close) {
    fenced.add(documentName)
    closeRoom(instance, documentName, close)
  }

  /**
   * The connect gate. allowed:false (or a 409 for a past epoch) refuses the socket; a
   * read-only mode makes Hocuspocus drop the connection's updates (server-side), and
   * the backend refuses a store under a read-only token anyway.
   */
  async function onAuthenticate({ token, documentName, connection, connectionConfig }) {
    if (!token) {
      throw new Error('Missing collaboration token')
    }

    const result = await webhook.authenticate({ token, room: documentName })

    if (!result.allowed) {
      if (result.conflict === 'collaboration_epoch_mismatch') {
        throw epochChanged()
      }
      throw new Error('Collaboration connection rejected by the Vaulter backend')
    }

    const readOnly = result.mode !== 'rw'
    const exp = decodeTokenExpiry(token)

    // v4 hands the per-connection settings as connectionConfig; older payloads as
    // connection. Set whichever is there.
    for (const target of [connectionConfig, connection]) {
      if (target && typeof target === 'object') {
        target.readOnly = readOnly
      }
    }
    armExpiryTimer(connection, exp)

    return { token, room: documentName, mode: result.mode, readOnly, userId: result.userId, exp }
  }

  /** The live connection is known here: close it when its token expires. */
  async function connected({ connection, context }) {
    armExpiryTimer(connection, context?.exp ?? null)
  }

  /**
   * Refuse every message once the connection's token has expired: rights are only
   * re-checked on the backend calls, so an expired token must not keep editing.
   */
  async function beforeHandleMessage({ context, connection }) {
    if (typeof context?.exp === 'number' && context.exp * 1000 <= Date.now()) {
      // A bare throw would reach the client as "Reset Connection"; close with 4001 first.
      if (typeof connection?.close === 'function') {
        closeConnection(connection, TOKEN_EXPIRED)
      }

      throw new Error(TOKEN_EXPIRED.reason)
    }
  }

  /**
   * Load a room from the backend: apply the stored Yjs state, or build the Y.Doc from
   * the JSON once and store it as the seed. The seed is built in a separate Y.Doc and
   * only applied once the backend accepted it; if another hub instance seeded first
   * (409 seed conflict), its state is loaded instead — never both, which would merge
   * into duplicated content.
   */
  async function onLoadDocument({ documentName, document, context, instance }) {
    const loaded = await webhook.loadDocument({ token: context?.token, room: documentName })

    if (loaded.state) {
      Y.applyUpdate(document, Buffer.from(loaded.state, 'base64'))

      return
    }

    if (!loaded.content) {
      return
    }

    const seed = TiptapTransformer.toYdoc(loaded.content, FIELD, extensionsFor(loaded.type))
    const update = Y.encodeStateAsUpdate(seed)

    // A read-only connection cannot store the seed; the first writer's room stores it
    // through the ordinary path. It may show its own copy only when nothing else holds
    // this room's Y.Doc — with Redis the copy would merge into the writer's seed.
    if (context?.mode !== 'rw') {
      if (shared) {
        log.warn?.(`Vaulter collab hub: ${documentName} has no stored state yet; a read-only viewer waits for a writer's seed`)
      } else {
        Y.applyUpdate(document, update)
      }

      return
    }

    const stored = await webhook.storeDocument({
      room: documentName,
      state: base64(update),
      content: TiptapTransformer.fromYdoc(seed, FIELD),
      seed: true,
      token: context.token,
    })

    if (stored.ok) {
      Y.applyUpdate(document, update)

      return
    }

    if (stored.code === 'collaboration_seed_conflict') {
      const reloaded = await webhook.loadDocument({ token: context.token, room: documentName })

      if (reloaded.state) {
        Y.applyUpdate(document, Buffer.from(reloaded.state, 'base64'))
      }

      return
    }

    if (stored.code === 'collaboration_epoch_mismatch') {
      fence(instance, documentName, EPOCH_CHANGED)
      throw epochChanged()
    }

    // A seed the backend refused is not content of the room: loading it would let the
    // clients edit text that was never stored (and, with Redis, replicate it).
    log.warn?.(`Vaulter collab hub: the seed of ${documentName} was refused (${stored.status} ${stored.code ?? ''})`)
    throw new Error(`Collaboration seed refused (${stored.status})`)
  }

  /**
   * The debounced persistence. Sends the binary state and its JSON projection with the
   * last writer's token. Answers retrying cannot change are terminal: a 409 closes the
   * room with 4409 epoch_changed, a 404 (document removed) with 4404; neither throws,
   * because the answer would not change. Network errors and 5xx are retried a few times
   * here (Hocuspocus 4.7 does not reschedule a failed store), then thrown.
   */
  async function onStoreDocument({ documentName, document, context, lastContext, instance }) {
    const ctx = lastContext ?? context ?? {}

    if (fenced.has(documentName) || ctx.mode !== 'rw' || ctx.readOnly) {
      return
    }

    const payload = {
      room: documentName,
      state: base64(Y.encodeStateAsUpdate(document)),
      content: TiptapTransformer.fromYdoc(document, FIELD),
      token: ctx.token,
    }
    let stored

    for (let attempt = 0; ; attempt++) {
      try {
        stored = await webhook.storeDocument(payload)
        break
      } catch (error) {
        // Out of attempts: rethrow, Hocuspocus keeps the document in memory and stores it
        // again on the next change.
        if (attempt >= storeRetries) {
          throw error
        }

        await sleep(retryDelayMs * (attempt + 1))
      }
    }

    if (stored.ok) {
      return
    }

    if (backend.TERMINAL_CONFLICTS.includes(stored.code)) {
      fence(instance, documentName, EPOCH_CHANGED)

      return
    }

    if (stored.status === 404) {
      fence(instance, documentName, DOCUMENT_GONE)

      return
    }

    log.error?.(`Vaulter collab hub: the store of ${documentName} was refused (${stored.status} ${stored.code ?? ''})`)
  }

  async function afterUnloadDocument({ documentName }) {
    fenced.delete(documentName)
  }

  return {
    onAuthenticate,
    connected,
    beforeHandleMessage,
    onLoadDocument,
    onStoreDocument,
    afterUnloadDocument,
    isFenced: (documentName) => fenced.has(documentName),
  }
}

/**
 * The hardened WebSocket transport options. maxPayload caps the frame size (the underlying
 * `ws` default is ~100 MiB, far too high for incremental Yjs updates); perMessageDeflate off
 * trims attack surface and CPU. In @hocuspocus/server v4 the transport moved to `crossws`,
 * and these options are wired through the `websocketOptions` config KEY on the `Server`
 * constructor — the Server forwards them verbatim to `crossws({ serverOptions })`, i.e. to
 * the real ws.WebSocketServer. Keep this as the single source so buildServer() and the
 * hardening test exercise the exact same options.
 */
export function websocketOptions() {
  return {
    maxPayload: config.maxPayloadBytes,
    perMessageDeflate: false,
  }
}

/**
 * Build the Hocuspocus `Server` with the hardened configuration. name/timeout/debounce/
 * maxDebounce/quiet are production-tuned via env. In v4 the WebSocket frame limits are wired
 * via the `websocketOptions` config key (forwarded to the crossws transport). `Server` owns
 * the HTTP server + upgrade handling.
 */
async function buildServer({ hooks = createHooks() } = {}) {
  return new Server({
    name: config.instanceName,
    port: config.port,
    timeout: config.timeout,
    debounce: config.debounce,
    maxDebounce: config.maxDebounce,
    quiet: config.quiet,
    websocketOptions: websocketOptions(),
    extensions: await buildExtensions(),
    onConnect,
    onAuthenticate: hooks.onAuthenticate,
    connected: hooks.connected,
    beforeHandleMessage: hooks.beforeHandleMessage,
    onLoadDocument: hooks.onLoadDocument,
    onStoreDocument: hooks.onStoreDocument,
    afterUnloadDocument: hooks.afterUnloadDocument,
  })
}

/**
 * A tiny HTTP health endpoint for the compose/orchestrator healthcheck, on a SEPARATE
 * port so it never interferes with the WebSocket upgrade handling on the main port.
 */
function activeRoomStats(server) {
  const documents = server?.hocuspocus?.documents

  if (!documents || typeof documents.size !== 'number') {
    return { rooms: 0, connections: 0 }
  }

  let connections = 0

  for (const document of documents.values()) {
    const count = document?.connections?.size
    connections += typeof count === 'number' ? count : 0
  }

  return { rooms: documents.size, connections }
}

function startHealthEndpoint(server) {
  const health = createServer((req, res) => {
    if (req.url === '/stats') {
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify(activeRoomStats(server)))
      return
    }

    if (req.url === '/health' || req.url === '/healthz' || req.url === '/') {
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify({ status: 'ok', service: 'vaulter-hocuspocus', name: config.instanceName }))
      return
    }
    res.writeHead(404, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ status: 'not_found' }))
  })

  health.listen(config.healthPort, () => {
    // eslint-disable-next-line no-console
    console.log(`Vaulter collab health endpoint on http://0.0.0.0:${config.healthPort}/health`)
  })

  // Hocuspocus handles its own graceful shutdown on SIGINT/SIGTERM (stopOnSignals=true);
  // close the companion health server alongside so the process can exit cleanly.
  for (const signal of ['SIGINT', 'SIGTERM']) {
    process.once(signal, () => health.close())
  }

  return health
}

async function boot() {
  const problems = validateConfig()
  if (problems.length > 0) {
    // eslint-disable-next-line no-console
    console.error('Vaulter collab hub: invalid configuration:\n - ' + problems.join('\n - '))
    process.exit(1)
  }

  if (config.allowAllOrigins || (!config.isProduction && config.allowedOrigins.length === 0)) {
    // eslint-disable-next-line no-console
    console.warn('Vaulter collab hub: Origin allowlist is OPEN (dev / "*") — set VAULTER_COLLAB_ALLOWED_ORIGINS in production.')
  }

  const server = await buildServer()
  startHealthEndpoint(server)
  await server.listen()
  // eslint-disable-next-line no-console
  console.log(`Vaulter Hocuspocus hub "${config.instanceName}" listening on ws://0.0.0.0:${config.port}`)

  return server
}

// Only boot when run as the entrypoint — importing this module (e.g. in tests) must not
// open sockets.
if (process.argv[1] && import.meta.url === `file://${process.argv[1]}`) {
  boot().catch((error) => {
    // eslint-disable-next-line no-console
    console.error('Vaulter collab hub failed to start:', error?.message ?? error)
    process.exit(1)
  })
}

export { buildServer, buildExtensions, onConnect, decodeTokenExpiry, armExpiryTimer, closeRoom, activeRoomStats }

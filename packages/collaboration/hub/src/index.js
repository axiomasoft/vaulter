import 'dotenv/config'
import { Server } from '@hocuspocus/server'
import { Throttle } from '@hocuspocus/extension-throttle'
import { createServer } from 'node:http'
import * as backendModule from './backend.js'
import { config, isOriginAllowed, validateConfig } from './config.js'
import { CLIENT_PROTOCOL, createPersistence, epochChanged } from './persistence.js'
import { closeConnection, closeRoom, DOCUMENT_GONE, EPOCH_CHANGED, FORBIDDEN, TOKEN_EXPIRED } from './rooms.js'
import { createWorker } from './worker.js'

// Vaulter collaboration hub — the external, env-configured Hocuspocus process.
//
// Flow (collaboration protocol 2, D26):
//   1. The client asks Laravel for a session (POST …/documents/{node}/collaboration/session) and
//      gets a room JWT and the room name "<prefix>.<driveId>.<nodeId>.<epoch>". The epoch is the
//      document's generation: a restore moves it on, so a room never outlives one.
//   2. onConnect enforces the Origin allowlist (CSWSH); onAuthenticate asks collab/authenticate
//      whether the connection is allowed and in which mode. A client that does not speak protocol 2
//      (connection parameter protocol=2) is read-only: it cannot send envelopes, and Hocuspocus
//      drops a read-only connection's updates itself.
//   3. onLoadDocument replays the room's log from collab/load (checkpoint + committed tail), or
//      seeds an empty generation once from the document's JSON — the seed is appended as the log's
//      first record before it enters the room (src/persistence.js).
//   4. Every client update comes with an envelope (a stateless message); the hub appends it through
//      its pending ledger (src/ledger.js) and acknowledges it only after the backend commit. Server
//      and direct changes are appended once under an update_id of the hub; changes that arrived
//      through Redis are appended by the hub that took them in. There is no onStoreDocument: the
//      room is never stored as a whole.
//   5. A 409 collaboration_epoch_mismatch means the room's generation is over: the hub closes the
//      room with 4409 `epoch_changed` and drops its pending records; clients reopen the document
//      with a new Y.Doc.
//   6. The compaction worker (src/worker.js) leases the generations due for a checkpoint — open
//      room or not, drain requests first — merges each log and publishes the checkpoint.
//
// Started by `vaulter-collaboration-hub serve` (src/cli.js) or `node src/index.js`.
//
// Hardening: Origin allowlist, Throttle (per-IP), maxPayload, perMessageDeflate off, token expiry
// enforced before every message, optional Redis (multi-instance) + Logger, fail-fast config
// validation, graceful shutdown (stopOnSignals=true).

export { CLIENT_PROTOCOL, DOCUMENT_GONE, EPOCH_CHANGED, FORBIDDEN, TOKEN_EXPIRED }

/**
 * Build the extension chain. Throttle is registered FIRST (the docs require it before any other
 * extension) so handshake-flood protection runs ahead of everything. Redis and Logger are opt-in
 * and lazily imported so the default (single-instance) path stays light.
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
    // Multi-instance document + awareness sync. ioredis parses the full URL (auth, db, rediss://
    // TLS); pub and sub need SEPARATE connections, so createClient mints one each.
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

/** The Redis extensions of a chain: their transactions are appended by the hub that made them. */
function redisExtensionsOf(extensions) {
  return extensions.filter((extension) => extension?.constructor?.name === 'Redis' && typeof extension.onConfigure === 'function')
}

/**
 * onConnect — the Origin gate (CSWSH defence), runs BEFORE onAuthenticate. A browser supplies an
 * Origin; it must be on the allowlist. A non-browser client (no Origin) is allowed here and still
 * gated by the auth webhook. Throwing refuses the socket.
 */
async function onConnect({ requestHeaders }) {
  const origin = readOrigin(requestHeaders)

  if (!isOriginAllowed(origin)) {
    throw new Error(`Collaboration connection refused: origin not allowed (${origin ?? 'none'})`)
  }
}

/**
 * Read the Origin header version-agnostically: Hocuspocus v2 hands a plain IncomingHttpHeaders
 * object (`headers.origin`), while v4 hands a web `Headers` (`headers.get('origin')`). Reading the
 * wrong shape would yield undefined and silently disable the CSWSH allowlist, so support both.
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
 * The client protocol a connection announced (the provider's `parameters: { protocol: 2 }`, i.e.
 * the `protocol` query parameter); 1 when it announced none.
 */
function readClientProtocol(requestParameters) {
  const value = typeof requestParameters?.get === 'function'
    ? requestParameters.get('protocol')
    : requestParameters?.protocol

  return String(value ?? '').trim() === String(CLIENT_PROTOCOL) ? CLIENT_PROTOCOL : 1
}

/**
 * The `exp` (unix seconds) claim of a JWT, read WITHOUT verifying its signature — the hub never
 * holds the signing secret, and the backend verifies the token on every call. This is local
 * bookkeeping so the hub knows when to stop accepting messages; it grants nothing on its own.
 * Malformed/missing → null.
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

/**
 * Close the connection once its token expires (already expired → now). A read-only viewer never
 * appends, so without this its socket would outlive the token. unref() so a pending timer never
 * keeps the process alive on shutdown.
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

/** The refusal of an expired token; the reason is what lets the client renew its session. */
function tokenExpired() {
  return Object.assign(new Error(TOKEN_EXPIRED.reason), { reason: TOKEN_EXPIRED.reason })
}

/**
 * The hub's Hocuspocus hooks. `backend` is the protocol 2 client (src/backend.js) and is
 * injectable, like the rest of the persistence options (backendOptions, conf, clock, sleep, random,
 * redisOrigins, serverToken), so the hooks run in tests against a mock backend; `log` receives
 * token-free messages only.
 */
export function createHooks({ backend = backendModule, log = console, persistence = null, ...options } = {}) {
  const store = persistence ?? createPersistence({ backend, log, ...options })

  /**
   * The connect gate. allowed:false (or a 409 for a past epoch) refuses the socket; a read-only
   * mode — or a client without protocol 2 — makes Hocuspocus drop the connection's updates
   * (server-side), and the hub refuses its envelopes.
   */
  async function onAuthenticate({ token, documentName, connection, connectionConfig, requestParameters }) {
    if (!token) {
      throw new Error('Missing collaboration token')
    }

    // An expired token is refused HERE, with the reason the client recognises: a backend 401 is
    // indistinguishable from any other refusal, and a reconnecting provider (laptop asleep past
    // token_ttl) re-presents the old token. The signature is not checked — the backend does that;
    // this only picks the reason, it grants nothing.
    const exp = decodeTokenExpiry(token)

    if (exp !== null && exp * 1000 <= Date.now()) {
      throw tokenExpired()
    }

    const result = await backend.authenticate({ token, room: documentName }, options.backendOptions ?? {})

    if (!result.allowed) {
      if (result.conflict === backendModule.EPOCH_MISMATCH) {
        throw epochChanged()
      }
      if (result.conflict === backendModule.PROTOCOL_MISMATCH) {
        store.reportProtocolMismatch()
      }
      throw new Error('Collaboration connection rejected by the Vaulter backend')
    }

    const protocol = readClientProtocol(requestParameters)
    const readOnly = result.mode !== 'rw' || protocol !== CLIENT_PROTOCOL

    // v4 hands the per-connection settings as connectionConfig; older payloads as connection. Set
    // whichever is there.
    for (const target of [connectionConfig, connection]) {
      if (target && typeof target === 'object') {
        target.readOnly = readOnly
      }
    }
    armExpiryTimer(connection, exp)

    return { token, room: documentName, mode: result.mode, readOnly, protocol, userId: result.userId, exp }
  }

  /** The live connection is known here: close it when its token expires, and register it. */
  async function connected(payload) {
    armExpiryTimer(payload?.connection, payload?.context?.exp ?? null)
    await store.connected(payload)
  }

  /**
   * Refuse every message once the connection's token has expired: rights are only re-checked on
   * the backend calls, so an expired token must not keep editing. While writes are stopped, a
   * writer's sync writes are dropped (src/persistence.js).
   */
  async function beforeHandleMessage(payload) {
    const { context, connection } = payload ?? {}

    if (typeof context?.exp === 'number' && context.exp * 1000 <= Date.now()) {
      // A bare throw would reach the client as "Reset Connection"; close with 4001 first.
      if (typeof connection?.close === 'function') {
        closeConnection(connection, TOKEN_EXPIRED)
      }

      throw new Error(TOKEN_EXPIRED.reason)
    }

    await store.beforeHandleMessage(payload)
  }

  return {
    onAuthenticate,
    connected,
    beforeHandleMessage,
    onLoadDocument: store.onLoadDocument,
    onStateless: store.onStateless,
    onDisconnect: store.onDisconnect,
    afterUnloadDocument: store.afterUnloadDocument,
    persistence: store,
    isFenced: store.isFenced,
  }
}

/**
 * The hardened WebSocket transport options. maxPayload caps the frame size (the underlying `ws`
 * default is ~100 MiB, far too high for incremental Yjs updates); perMessageDeflate off trims
 * attack surface and CPU. In @hocuspocus/server v4 the transport moved to `crossws`, and these
 * options are wired through the `websocketOptions` config KEY on the `Server` constructor — the
 * Server forwards them verbatim to `crossws({ serverOptions })`, i.e. to the real
 * ws.WebSocketServer. Keep this as the single source so buildServer() and the hardening test
 * exercise the exact same options.
 */
export function websocketOptions() {
  return {
    maxPayload: config.maxPayloadBytes,
    perMessageDeflate: false,
  }
}

/**
 * Build the Hocuspocus `Server` with the hardened configuration. name/timeout/debounce/maxDebounce/
 * quiet are production-tuned via env. In v4 the WebSocket frame limits are wired via the
 * `websocketOptions` config key (forwarded to the crossws transport). `Server` owns the HTTP server
 * + upgrade handling. The persistence is attached to the server's Hocuspocus instance; its ledger
 * cycle starts with boot().
 */
async function buildServer({ hooks = null } = {}) {
  const extensions = await buildExtensions()
  const serverHooks = hooks ?? createHooks({ redisOrigins: redisExtensionsOf(extensions) })
  const server = new Server({
    name: config.instanceName,
    port: config.port,
    timeout: config.timeout,
    debounce: config.debounce,
    maxDebounce: config.maxDebounce,
    quiet: config.quiet,
    websocketOptions: websocketOptions(),
    extensions,
    onConnect,
    onAuthenticate: serverHooks.onAuthenticate,
    connected: serverHooks.connected,
    beforeHandleMessage: serverHooks.beforeHandleMessage,
    onLoadDocument: serverHooks.onLoadDocument,
    onStateless: serverHooks.onStateless,
    onDisconnect: serverHooks.onDisconnect,
    afterUnloadDocument: serverHooks.afterUnloadDocument,
  })

  serverHooks.persistence.attach(server.hocuspocus)
  server.vaulterPersistence = serverHooks.persistence

  return server
}

/**
 * A tiny HTTP health endpoint for the compose/orchestrator healthcheck, on a SEPARATE port so it
 * never interferes with the WebSocket upgrade handling on the main port.
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
  server.vaulterPersistence.start()
  startHealthEndpoint(server)
  await server.listen()
  // eslint-disable-next-line no-console
  console.log(`Vaulter Hocuspocus hub "${config.instanceName}" listening on ws://0.0.0.0:${config.port} (${config.mode})`)

  const worker = createWorker({ events: { protocolMismatch: () => server.vaulterPersistence.reportProtocolMismatch() } })
  worker.start()
  server.vaulterWorker = worker

  for (const signal of ['SIGINT', 'SIGTERM']) {
    process.once(signal, () => {
      worker.stop()
    })
  }

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

export { boot, buildServer, buildExtensions, onConnect, decodeTokenExpiry, armExpiryTimer, closeRoom, activeRoomStats, readClientProtocol }

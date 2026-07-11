import 'dotenv/config'
import { Hocuspocus } from '@hocuspocus/server'
import { Throttle } from '@hocuspocus/extension-throttle'
import { createServer } from 'node:http'
import { config, isOriginAllowed, validateConfig } from './config.js'
import { authenticate, storeDocument } from './webhook.js'

// Vaulter collaboration hub (M4b-edge) — the external, env-configured Hocuspocus image.
//
// Flow (vaulter-spec.md §10 / master plan §7 + Addendum A / ADR-0001):
//   1. The Vue client requests a room JWT from Laravel
//      (POST /api/vaulter/nodes/{node}/collab/token).
//   2. VaulterEditor opens a WebSocket here with that JWT as the token and the
//      ROOM NAME (vaulter.documents.<nodeId>) as the document name.
//   3. onConnect enforces the Origin allowlist (CSWSH); onAuthenticate forwards the JWT +
//      room to the Laravel collab/authenticate webhook; allowed:false rejects the socket,
//      readOnly gates writes.
//   4. Yjs syncs edits; onStoreDocument (debounced by the server) ships the binary Yjs
//      snapshot back to collab/store, which persists it into
//      v_node_document_versions.yjs_state — the binary is the source of truth.
//
// Hardening (Addendum A.3): Origin allowlist, Throttle (per-IP), maxPayload,
// perMessageDeflate off, prod liveness/debounce config, optional Redis (multi-instance)
// + Logger, fail-fast config validation, non-root image, graceful shutdown (Hocuspocus
// stopOnSignals=true).

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
 * onAuthenticate — the connect gate. The client presents its room JWT as `token` and the
 * room name as `documentName`. We delegate the decision to Laravel's collab/authenticate
 * webhook (JWT forwarded as Authorization: Bearer, room in the body). A rejected call
 * (allowed:false / non-2xx) throws → refused connection. readOnly downgrades the
 * connection so the server drops a viewer's updates; the token is stashed on the context
 * so onStoreDocument authenticates the snapshot write with the SAME room-bound JWT.
 *
 * F-P1.6-03 (periodic exp re-check): onStoreDocument only re-validates the token on a
 * WRITE — a long-lived read-only viewer session would otherwise never be disconnected
 * once its token expires (the connection just keeps syncing over the open socket). Arm
 * a timer here that force-closes the socket once the token's `exp` passes.
 */
async function onAuthenticate({ token, documentName, connection }) {
  if (!token) {
    throw new Error('Missing collaboration token')
  }

  const result = await authenticate({ token, room: documentName })

  if (!result.allowed) {
    throw new Error('Collaboration connection rejected by the Vaulter backend')
  }

  if (connection) {
    if (result.readOnly) {
      connection.readOnly = true
    }

    armExpiryTimer(connection, decodeTokenExpiry(token))
  }

  return {
    token,
    room: documentName,
    readOnly: result.readOnly,
    userId: result.userId,
  }
}

/**
 * The `exp` (unix seconds) claim of a JWT, read WITHOUT verifying its signature — the
 * hub never holds the signing secret (C9), and the token was ALREADY verified
 * server-side by the collab/authenticate webhook call above. This is purely local
 * bookkeeping so the hub knows when to proactively close a socket; it grants nothing on
 * its own. Malformed/missing/unparsable token or `exp` claim → null (no timer armed,
 * i.e. the pre-P3.2 behaviour: only exp'd JWTs on a future store call get caught).
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
 * Arm (or immediately act on) the connection's expiry timer for the given `exp` (unix
 * seconds). Already-expired → close now. Otherwise a single setTimeout fires the close
 * at the exact expiry instant; unref() so a pending timer never keeps the Node process
 * alive on shutdown. No-op when `exp` is null (unparsable token) — same as before this
 * fix, degrading to store-time-only re-validation.
 */
function armExpiryTimer(connection, exp) {
  if (exp === null) {
    return
  }

  const delayMs = exp * 1000 - Date.now()

  if (delayMs <= 0) {
    connection.close({ code: 4001, reason: 'Collaboration token expired' })

    return
  }

  const timer = setTimeout(() => {
    connection.close({ code: 4001, reason: 'Collaboration token expired' })
  }, delayMs)

  if (typeof timer.unref === 'function') {
    timer.unref()
  }
}

/**
 * onStoreDocument — the debounced persistence hook. Ships the BINARY Yjs state (base64)
 * to Laravel's collab/store webhook so it lands in v_node_document_versions.yjs_state
 * verbatim. Authenticated with the connection's room-bound JWT; a read-only connection
 * never reaches store.
 */
async function onStoreDocument({ documentName, state, document, context, lastContext }) {
  // v4 renames `context` → `lastContext`; support both.
  const ctx = context ?? lastContext ?? {}

  if (ctx.readOnly) {
    return
  }

  const binary = await encodeBinaryState({ state, document })

  if (binary.length === 0) {
    return
  }

  await storeDocument({
    room: documentName,
    yjsState: Buffer.from(binary).toString('base64'),
    token: ctx.token,
    userId: ctx.userId,
  })
}

/**
 * The hardened `ws` server options. maxPayload caps the frame size (the `ws` default is
 * ~100 MiB, far too high for incremental Yjs updates); perMessageDeflate off trims attack
 * surface and CPU. In @hocuspocus/server v2.x these are honoured ONLY as the 3rd argument
 * of `server.listen()` — a `websocketOptions` config KEY on the Hocuspocus constructor is
 * silently ignored (it only became a config key in v4), so passing it there is a no-op that
 * leaves the limits at the library defaults. Keep this as the single source so boot() and
 * the test exercise the exact same options.
 */
export function websocketOptions() {
  return {
    maxPayload: config.maxPayloadBytes,
    perMessageDeflate: false,
  }
}

/**
 * Build the Hocuspocus server with the hardened configuration. name/timeout/debounce/
 * maxDebounce/quiet are production-tuned via env. The WebSocket frame limits are applied at
 * listen() time (see websocketOptions + boot), not here — v2.x ignores them on the config.
 */
async function buildServer() {
  return new Hocuspocus({
    name: config.instanceName,
    port: config.port,
    timeout: config.timeout,
    debounce: config.debounce,
    maxDebounce: config.maxDebounce,
    quiet: config.quiet,
    extensions: await buildExtensions(),
    onConnect,
    onAuthenticate,
    onStoreDocument,
  })
}

/**
 * The binary Yjs update for the document. Hocuspocus' Database extension hands a ready
 * `state: Uint8Array`; the core onStoreDocument hands the `document` (Y.Doc), from which
 * we encode the full update. Either way we ship raw bytes — the Yjs binary is truth.
 */
async function encodeBinaryState({ state, document }) {
  if (state instanceof Uint8Array) {
    return state
  }

  if (document && typeof document.transact === 'function') {
    const { encodeStateAsUpdate } = await import('yjs')
    return encodeStateAsUpdate(document)
  }

  return new Uint8Array()
}

/**
 * A tiny HTTP health endpoint for the compose/orchestrator healthcheck, on a SEPARATE
 * port so it never interferes with the WebSocket upgrade handling on the main port.
 */
function startHealthEndpoint() {
  const health = createServer((req, res) => {
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

  startHealthEndpoint()

  const server = await buildServer()
  // ws options (maxPayload / perMessageDeflate) MUST be passed here — v2.x only reads them
  // from listen()'s 3rd arg, not the Hocuspocus config (see websocketOptions).
  await server.listen(config.port, undefined, websocketOptions())
  // eslint-disable-next-line no-console
  console.log(`Vaulter Hocuspocus hub "${config.instanceName}" listening on ws://0.0.0.0:${config.port}`)

  return server
}

// Only boot when run as the entrypoint — importing this module (e.g. in tests) must not
// open sockets.
if (process.argv[1] && import.meta.url === `file://${process.argv[1]}`) {
  boot().catch((error) => {
    // eslint-disable-next-line no-console
    console.error('Vaulter collab hub failed to start:', error)
    process.exit(1)
  })
}

export { buildServer, buildExtensions, onConnect, onAuthenticate, decodeTokenExpiry, armExpiryTimer }
// websocketOptions is exported above (function declaration) for boot() + the hardening test.

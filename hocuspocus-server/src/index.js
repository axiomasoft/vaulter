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
  const origin = requestHeaders?.origin

  if (!isOriginAllowed(origin)) {
    throw new Error(`Collaboration connection refused: origin not allowed (${origin ?? 'none'})`)
  }
}

/**
 * onAuthenticate — the connect gate. The client presents its room JWT as `token` and the
 * room name as `documentName`. We delegate the decision to Laravel's collab/authenticate
 * webhook (JWT forwarded as Authorization: Bearer, room in the body). A rejected call
 * (allowed:false / non-2xx) throws → refused connection. readOnly downgrades the
 * connection so the server drops a viewer's updates; the token is stashed on the context
 * so onStoreDocument authenticates the snapshot write with the SAME room-bound JWT.
 */
async function onAuthenticate({ token, documentName, connection }) {
  if (!token) {
    throw new Error('Missing collaboration token')
  }

  const result = await authenticate({ token, room: documentName })

  if (!result.allowed) {
    throw new Error('Collaboration connection rejected by the Vaulter backend')
  }

  if (connection && result.readOnly) {
    connection.readOnly = true
  }

  return {
    token,
    room: documentName,
    readOnly: result.readOnly,
    userId: result.userId,
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
 * Build the Hocuspocus server with the hardened configuration. WebSocket limits go through
 * websocketOptions (maxPayload caps frame size; perMessageDeflate off reduces attack
 * surface). name/timeout/debounce/maxDebounce/quiet are production-tuned via env.
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
    websocketOptions: {
      maxPayload: config.maxPayloadBytes,
      perMessageDeflate: false,
    },
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
    console.error('Vaulter collab hub failed to start:', error)
    process.exit(1)
  })
}

export { buildServer, buildExtensions, onConnect }

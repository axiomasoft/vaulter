import 'dotenv/config'
import { Server } from '@hocuspocus/server'
import { createServer } from 'node:http'
import { config } from './config.js'
import { authenticate, storeDocument } from './webhook.js'

// Vaulter collaboration hub (M4b-edge).
//
// Flow (vaulter-spec.md §10 / master plan §7 / ADR-0001):
//   1. The Vue client requests a room JWT from Laravel
//      (POST /api/vaulter/nodes/{node}/collab/token).
//   2. VaulterEditor opens a WebSocket here with that JWT as the token and the
//      ROOM NAME (vaulter.documents.<nodeId>) as the document name.
//   3. onAuthenticate forwards the JWT + room to the Laravel collab/authenticate
//      webhook; allowed:false rejects the socket, readOnly gates writes.
//   4. Yjs syncs edits; onStoreDocument (debounced by the server) ships the binary
//      Yjs snapshot back to collab/store, which persists it into
//      v_node_document_versions.yjs_state — the binary is the source of truth.

const server = new Server({
  port: config.port,

  /**
   * onAuthenticate — the connect gate. The client presents its room JWT as `token`
   * and the room name as `documentName`. We delegate the decision to Laravel's
   * collab/authenticate webhook (the JWT is forwarded as Authorization: Bearer, the
   * room in the body). A rejected call (allowed:false / non-2xx) throws, which
   * Hocuspocus turns into a refused connection. The resolved readOnly flag is pushed
   * onto the connection so the server refuses writes from a read-only viewer, and the
   * token is stashed on the returned context so onStoreDocument can authenticate the
   * snapshot write with the SAME room-bound JWT.
   */
  async onAuthenticate({ token, documentName, connection }) {
    if (!token) {
      throw new Error('Missing collaboration token')
    }

    const result = await authenticate({ token, room: documentName })

    if (!result.allowed) {
      throw new Error('Collaboration connection rejected by the Vaulter backend')
    }

    // The connection object lets us downgrade a viewer/commenter to read-only; the
    // server then drops their document updates (see Hocuspocus connection.readOnly).
    if (connection && result.readOnly) {
      connection.readOnly = true
    }

    // Returned as the connection `context` — onStoreDocument reads it back to reuse
    // the room-bound JWT (and to skip storing from a read-only connection).
    return {
      token,
      room: documentName,
      readOnly: result.readOnly,
      userId: result.userId,
    }
  },

  /**
   * onStoreDocument — the debounced persistence hook (the @hocuspocus Database/
   * onStoreDocument seam; the server debounces it). We ship the BINARY Yjs state
   * (base64) to Laravel's collab/store webhook so it lands in
   * v_node_document_versions.yjs_state verbatim — we never reconstruct Yjs from JSON.
   * The call is authenticated with the connection's room-bound JWT (forwarded as
   * Authorization: Bearer) when available, else the shared webhook secret. A read-only
   * connection never reaches store (the backend would 403 it anyway).
   */
  async onStoreDocument({ documentName, state, document, context, lastContext }) {
    // The v4 payload renames `context` → `lastContext`; support both so the hook is
    // version-tolerant.
    const ctx = context ?? lastContext ?? {}

    if (ctx.readOnly) {
      // A read-only connection must not persist; the backend forbids it, so skip the
      // round-trip entirely.
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
  },
})

/**
 * The binary Yjs update for the document. Hocuspocus' Database extension hands a
 * ready `state: Uint8Array`; the core onStoreDocument hands the `document` (Y.Doc),
 * from which we encode the full update. Either way we ship raw bytes — the Yjs binary
 * is the source of truth.
 */
async function encodeBinaryState({ state, document }) {
  if (state instanceof Uint8Array) {
    return state
  }

  if (document && typeof document.transact === 'function') {
    // yjs is a dependency of @hocuspocus/server; import its encoder lazily so the
    // hot path stays dependency-light and the module imports cleanly without a Y.Doc.
    const { encodeStateAsUpdate } = await import('yjs')
    return encodeStateAsUpdate(document)
  }

  return new Uint8Array()
}

/**
 * A tiny HTTP health endpoint for the compose/orchestrator healthcheck. It is served
 * on a SEPARATE port (config.healthPort) so it never interferes with the Hocuspocus
 * WebSocket upgrade handling on the main port.
 */
function startHealthEndpoint() {
  const health = createServer((req, res) => {
    if (req.url === '/health' || req.url === '/healthz' || req.url === '/') {
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify({ status: 'ok', service: 'vaulter-hocuspocus' }))
      return
    }
    res.writeHead(404, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ status: 'not_found' }))
  })

  health.listen(config.healthPort, () => {
    // eslint-disable-next-line no-console
    console.log(`Vaulter collab health endpoint on http://0.0.0.0:${config.healthPort}/health`)
  })

  return health
}

async function boot() {
  startHealthEndpoint()

  await server.listen()
  // eslint-disable-next-line no-console
  console.log(`Vaulter Hocuspocus hub listening on ws://0.0.0.0:${config.port}`)
}

// Only boot when run as the entrypoint — importing this module (e.g. in tests)
// must not open sockets.
if (process.argv[1] && import.meta.url === `file://${process.argv[1]}`) {
  boot().catch((error) => {
    // eslint-disable-next-line no-console
    console.error('Vaulter collab hub failed to start:', error)
    process.exit(1)
  })
}

export { server }

import crypto from 'node:crypto'
import { config as defaultConfig } from './config.js'

// The server-to-server calls the hub makes BACK into Laravel (M4b webhooks):
//   - authenticate → POST {base}/collab/authenticate  (onAuthenticate)
//   - storeDocument → POST {base}/collab/store          (onStoreDocument)
//
// Each call authenticates EITHER with the connection's room-bound JWT (forwarded as
// Authorization: Bearer — the same token the client presented) OR the shared webhook
// secret (X-Vaulter-Collab-Secret). The Laravel endpoints are never open: a call with
// neither is 401'd there.
//
// HARDENING (VLT-REMED P3.2 / F-P1.6-02, N2): on top of identity, every call carries an
// HMAC-SHA256 signature of "{timestamp}.{rawBody}" keyed by webhookSecret
// (X-Vaulter-Collab-Timestamp + X-Vaulter-Collab-Signature) — the SAME canonical string
// Vaulter\Webhooks\WebhookSigner already uses for OUTBOUND deliveries, reused here for
// the INBOUND direction. This closes the gap where a captured secret/JWT let an
// attacker replay or tamper with the payload indefinitely: Laravel's
// CollaborationCallResolver re-derives the signature over the exact bytes it received
// and rejects a stale timestamp or a replayed signature even when identity checks out.
// Without webhookSecret configured no signature can be produced — the call is sent
// unsigned and Laravel refuses it (fail-closed: running a hub without webhookSecret
// configured is no longer a supported deployment, see UPGRADE.md).
//
// fetch + config are injectable so the handlers are unit-testable without a network.

/**
 * The HMAC-SHA256 signature of "{timestamp}.{rawBody}" keyed by the shared webhook
 * secret, hex-encoded — matching PHP's `hash_hmac('sha256', …)` default output
 * byte-for-byte. Returns null when no secret is configured (nothing to sign with).
 */
function signRawBody(rawBody, secret) {
  if (typeof secret !== 'string' || secret === '') {
    return null
  }

  const timestamp = Math.floor(Date.now() / 1000)
  const signature = crypto.createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex')

  return { timestamp, signature }
}

/**
 * Build the auth + signature headers for a webhook call: the room JWT as a bearer
 * token when we hold one (it is bound to the room and carries the user + readOnly),
 * the shared secret header when configured (a belt-and-braces fallback the backend
 * also accepts), and — when a signature was computed — the timestamp + signature
 * headers the backend's HMAC check requires.
 */
function authHeaders({ token, secret, signed }) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' }

  if (typeof token === 'string' && token !== '') {
    headers.Authorization = `Bearer ${token}`
  }

  if (typeof secret === 'string' && secret !== '') {
    headers['X-Vaulter-Collab-Secret'] = secret
  }

  if (signed) {
    headers['X-Vaulter-Collab-Timestamp'] = String(signed.timestamp)
    headers['X-Vaulter-Collab-Signature'] = signed.signature
  }

  return headers
}

async function postJson({ url, headers, rawBody, fetchImpl, timeoutMs }) {
  const controller = new AbortController()
  const timer = timeoutMs ? setTimeout(() => controller.abort(), timeoutMs) : null

  try {
    return await fetchImpl(url, {
      method: 'POST',
      headers,
      body: rawBody,
      signal: controller.signal,
    })
  } finally {
    if (timer) {
      clearTimeout(timer)
    }
  }
}

/**
 * The onAuthenticate webhook. POSTs the room (and the bearer JWT) to
 * collab/authenticate. Returns a normalised { allowed, readOnly, userId }. A non-2xx
 * (or { allowed:false }) means the connection must be refused; readOnly defaults to
 * true so a malformed/ambiguous answer NEVER grants write access.
 */
export async function authenticate(
  { token, room },
  { config = defaultConfig, fetchImpl = globalThis.fetch } = {},
) {
  const rawBody = JSON.stringify({ room, node_id: room })
  const signed = signRawBody(rawBody, config.webhookSecret)

  const response = await postJson({
    url: `${config.webhookBase}/collab/authenticate`,
    headers: authHeaders({ token, secret: config.webhookSecret, signed }),
    rawBody,
    fetchImpl,
    timeoutMs: config.webhookTimeoutMs,
  })

  if (!response.ok) {
    return { allowed: false, readOnly: true, userId: null }
  }

  const payload = await response.json().catch(() => ({}))
  const allowed = payload?.allowed === true
  // Default to read-only unless the backend explicitly says otherwise.
  const readOnly = payload?.readOnly !== false
  const userId = payload?.user?.id ?? null

  return { allowed, readOnly, userId }
}

/**
 * The onStoreDocument webhook. POSTs the base64 binary Yjs state to collab/store so it
 * persists into v_node_document_versions.yjs_state. Throws on a non-2xx so the server's
 * store-retry can kick in (data loss is worse than a retry). The Yjs binary is the
 * source of truth — no JSON projection is reconstructed here.
 */
export async function storeDocument(
  { room, yjsState, token, userId },
  { config = defaultConfig, fetchImpl = globalThis.fetch } = {},
) {
  const body = { room, node_id: room, yjs_state: yjsState }

  // A shared-secret call carries the user out of band; a JWT call already has `sub`.
  if (userId !== undefined && userId !== null) {
    body.user_id = userId
  }

  const rawBody = JSON.stringify(body)
  const signed = signRawBody(rawBody, config.webhookSecret)

  const response = await postJson({
    url: `${config.webhookBase}/collab/store`,
    headers: authHeaders({ token, secret: config.webhookSecret, signed }),
    rawBody,
    fetchImpl,
    timeoutMs: config.webhookTimeoutMs,
  })

  if (!response.ok) {
    const detail = await response.text().catch(() => '')
    throw new Error(`collab/store failed (${response.status})${detail ? `: ${detail}` : ''}`)
  }

  return response.json().catch(() => ({}))
}

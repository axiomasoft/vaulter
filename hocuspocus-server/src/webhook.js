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
// fetch + config are injectable so the handlers are unit-testable without a network.

/**
 * Build the auth headers for a webhook call: the room JWT as a bearer token when we
 * hold one (it is bound to the room and carries the user + readOnly), plus the shared
 * secret header when configured (a belt-and-braces fallback the backend also accepts).
 */
function authHeaders({ token, secret }) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' }

  if (typeof token === 'string' && token !== '') {
    headers.Authorization = `Bearer ${token}`
  }

  if (typeof secret === 'string' && secret !== '') {
    headers['X-Vaulter-Collab-Secret'] = secret
  }

  return headers
}

async function postJson({ url, headers, body, fetchImpl, timeoutMs }) {
  const controller = new AbortController()
  const timer = timeoutMs ? setTimeout(() => controller.abort(), timeoutMs) : null

  try {
    return await fetchImpl(url, {
      method: 'POST',
      headers,
      body: JSON.stringify(body),
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
  const response = await postJson({
    url: `${config.webhookBase}/collab/authenticate`,
    headers: authHeaders({ token, secret: config.webhookSecret }),
    body: { room, node_id: room },
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

  const response = await postJson({
    url: `${config.webhookBase}/collab/store`,
    headers: authHeaders({ token, secret: config.webhookSecret }),
    body,
    fetchImpl,
    timeoutMs: config.webhookTimeoutMs,
  })

  if (!response.ok) {
    const detail = await response.text().catch(() => '')
    throw new Error(`collab/store failed (${response.status})${detail ? `: ${detail}` : ''}`)
  }

  return response.json().catch(() => ({}))
}

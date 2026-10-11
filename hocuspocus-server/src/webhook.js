import crypto from 'node:crypto'
import { config as defaultConfig } from './config.js'

// The server-to-server calls the hub makes BACK into Laravel:
//   - authenticate  → POST {base}/collaboration/hub/authenticate  (onAuthenticate)
//   - loadDocument  → POST {base}/collaboration/hub/load          (onLoadDocument)
//   - storeDocument → POST {base}/collaboration/hub/store         (onStoreDocument and the seed)
//
// Every call carries two independent proofs, both required by the backend:
//   - the room JWT the client presented (Authorization: Bearer) — it names the user, the
//     room (drive, node, epoch) and the mode; the hub never holds its signing secret;
//   - an HMAC-SHA256 signature of "{timestamp}.{rawBody}" keyed by the webhook secret
//     (X-Vaulter-Collab-Timestamp + X-Vaulter-Collab-Signature), the same canonical
//     string as Vaulter\Webhooks\WebhookSigner. The backend refuses a stale timestamp
//     and a replayed signature, so every body carries a random nonce: two genuine calls
//     with the same payload in the same second must not share a signature.
//
// Tokens never appear in errors or logs. fetch + config are injectable for tests.

/** The backend answered 409 for a room that must not write again (no retry). */
export const TERMINAL_CONFLICTS = Object.freeze(['collaboration_epoch_mismatch', 'collaboration_seed_conflict'])

/** 4xx answers of collab/store that mean "try again later". */
const RETRYABLE_CLIENT_ERRORS = Object.freeze([408, 425, 429])

/**
 * A non-2xx answer the hub does not retry. `status` and the problem `code` only — the
 * request (and its token) is never attached.
 */
export class BackendRejection extends Error {
  constructor(endpoint, status, code) {
    super(`collab/${endpoint} rejected (${status}${code ? ` ${code}` : ''})`)
    this.name = 'BackendRejection'
    this.status = status
    this.code = code
  }
}

function signRawBody(rawBody, secret) {
  if (typeof secret !== 'string' || secret === '') {
    return null
  }

  const timestamp = Math.floor(Date.now() / 1000)
  const signature = crypto.createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex')

  return { timestamp, signature }
}

function headersFor({ token, signed }) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' }

  if (typeof token === 'string' && token !== '') {
    headers.Authorization = `Bearer ${token}`
  }

  if (signed) {
    headers['X-Vaulter-Collab-Timestamp'] = String(signed.timestamp)
    headers['X-Vaulter-Collab-Signature'] = signed.signature
  }

  return headers
}

async function post(endpoint, payload, token, { config = defaultConfig, fetchImpl = globalThis.fetch } = {}) {
  const rawBody = JSON.stringify({ ...payload, nonce: crypto.randomUUID() })
  const controller = new AbortController()
  const timer = config.webhookTimeoutMs ? setTimeout(() => controller.abort(), config.webhookTimeoutMs) : null

  try {
    return await fetchImpl(`${config.webhookBase}/collaboration/hub/${endpoint}`, {
      method: 'POST',
      headers: headersFor({ token, signed: signRawBody(rawBody, config.webhookSecret) }),
      body: rawBody,
      signal: controller.signal,
    })
  } finally {
    if (timer) {
      clearTimeout(timer)
    }
  }
}

async function problemCode(response) {
  const payload = await response.json().catch(() => null)

  return typeof payload?.code === 'string' ? payload.code : null
}

/**
 * onAuthenticate: { allowed, mode, userId, epoch, conflict }. Anything but a clear
 * allowed answer refuses the connection, and anything but a clear 'rw' is read-only.
 * `conflict` names a 409 (the room's epoch is over: the client must ask for a new
 * session).
 */
export async function authenticate({ token, room }, options = {}) {
  const response = await post('authenticate', { room }, token, options)

  if (!response.ok) {
    const code = response.status === 409 ? await problemCode(response) : null

    return { allowed: false, mode: 'ro', userId: null, epoch: null, conflict: code }
  }

  const payload = await response.json().catch(() => ({}))

  return {
    allowed: payload?.allowed === true,
    mode: payload?.mode === 'rw' ? 'rw' : 'ro',
    userId: payload?.userId ?? null,
    epoch: Number.isInteger(payload?.epoch) ? payload.epoch : null,
    conflict: null,
  }
}

/**
 * onLoadDocument: { state (base64|null), content (JSON|null), epoch, schemaVersion,
 * stateVersion, type }. Throws BackendRejection on a 4xx (the connection is refused)
 * and a plain Error otherwise.
 */
export async function loadDocument({ token, room }, options = {}) {
  const response = await post('load', { room }, token, options)

  if (response.ok) {
    return response.json()
  }

  if (response.status >= 400 && response.status < 500) {
    throw new BackendRejection('load', response.status, await problemCode(response))
  }

  throw new Error(`collab/load failed (${response.status})`)
}

/**
 * onStoreDocument and the seed: POST {room, state, content, seed, state_version}. Returns
 * { ok: true, data } or, for an answer that retrying cannot change, { ok: false,
 * status, code } — a 409 epoch/seed conflict, a 4xx refusal. Throws on 5xx and
 * network errors; the hub retries those a few times (losing data is worse).
 */
export async function storeDocument({ room, state, content, seed = false, stateVersion, token }, options = {}) {
  const payload = { room, state }

  if (content !== undefined && content !== null) {
    payload.content = content
  }
  if (seed) {
    payload.seed = true
  }
  // A seed's precondition: the state version collab/load returned. The backend refuses the
  // seed (409 collaboration_seed_conflict) when the document moved on since.
  if (Number.isInteger(stateVersion)) {
    payload.state_version = stateVersion
  }

  const response = await post('store', payload, token, options)

  if (response.ok) {
    return { ok: true, data: (await response.json().catch(() => ({})))?.data ?? null }
  }

  // 408/425/429 say "later", not "no": retried like a 5xx instead of reported as a refusal.
  if (response.status >= 400 && response.status < 500 && !RETRYABLE_CLIENT_ERRORS.includes(response.status)) {
    return { ok: false, status: response.status, code: await problemCode(response) }
  }

  throw new Error(`collab/store failed (${response.status})`)
}

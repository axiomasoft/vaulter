import crypto from 'node:crypto'
import { config as defaultConfig } from './config.js'
import { parseRetryAfter } from './retry.js'

// The hub's client of the Laravel backend, protocol 2 (D26):
//   - authenticate → POST {base}/collaboration/hub/authenticate  (onAuthenticate)
//   - load         → POST {base}/collaboration/hub/load          (checkpoint + committed tail)
//   - append       → POST {base}/collaboration/hub/append        (one update; the ledger repeats it)
//   - leaseCompaction   → POST {base}/collaboration/hub/compaction/lease (the compaction worker)
//   - publishCheckpoint → POST {base}/collaboration/hub/checkpoint       (a merged checkpoint, CAS)
//
// Every call names the protocol (X-Vaulter-Collab-Protocol: 2; the backend answers anything else
// with 409 collaboration_protocol_mismatch). The calls made for a room connection carry two
// independent proofs, both required by the backend; the compaction calls are the hub's own and
// carry the signature alone:
//   - the room JWT of the connection the call is made for (Authorization: Bearer) — it names the
//     user, the room (drive, node, epoch) and the mode; the hub never holds its signing secret;
//   - an HMAC-SHA256 signature of "{timestamp}.{rawBody}" keyed by the webhook secret
//     (X-Vaulter-Collab-Timestamp + X-Vaulter-Collab-Signature), the same canonical string as
//     Vaulter\Webhooks\WebhookSigner. The backend refuses a stale timestamp and a replayed
//     signature, so every body carries a random nonce: two genuine calls with the same payload in
//     the same second must not share a signature.
//
// append makes ONE attempt and reports what happened; repeating it is the job of src/retry.js,
// driven by the pending ledger. Tokens never appear in errors or logs. fetch and config are
// injectable for tests.

export const PROTOCOL_HEADER = 'X-Vaulter-Collab-Protocol'
export const PROTOCOL_VERSION = '2'

/** Problem codes the hub acts on. */
export const EPOCH_MISMATCH = 'collaboration_epoch_mismatch'
export const PROTOCOL_MISMATCH = 'collaboration_protocol_mismatch'
export const SEED_CONFLICT = 'collaboration_seed_conflict'
export const CHECKPOINT_CONFLICT = 'collaboration_checkpoint_conflict'

/**
 * A non-2xx answer the hub does not retry. `status` and the problem `code` only — the request
 * (and its token) is never attached.
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
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    [PROTOCOL_HEADER]: PROTOCOL_VERSION,
  }

  if (typeof token === 'string' && token !== '') {
    headers.Authorization = `Bearer ${token}`
  }

  if (signed) {
    headers['X-Vaulter-Collab-Timestamp'] = String(signed.timestamp)
    headers['X-Vaulter-Collab-Signature'] = signed.signature
  }

  return headers
}

async function post(endpoint, payload, token, { config = defaultConfig, fetchImpl = globalThis.fetch, timeoutMs } = {}) {
  const rawBody = JSON.stringify({ ...payload, nonce: crypto.randomUUID() })
  const controller = new AbortController()
  const limit = timeoutMs ?? config.webhookTimeoutMs
  const timer = limit ? setTimeout(() => controller.abort(), limit) : null

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

function header(response, name) {
  const headers = response?.headers

  if (!headers) {
    return null
  }

  return typeof headers.get === 'function' ? headers.get(name) : (headers[name] ?? headers[name.toLowerCase()] ?? null)
}

/**
 * onAuthenticate: { allowed, mode, userId, epoch, conflict }. Anything but a clear allowed answer
 * refuses the connection, and anything but a clear 'rw' is read-only. `conflict` names the problem
 * of a 409: the room's epoch is over (the client asks for a new session) or the backend does not
 * speak protocol 2.
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
 * The room's log: { generation, schema_version, type, state_version, checkpoint (base64|null),
 * checkpoint_seq, head_seq, content (JSON|null), tail: [{seq, update_id, payload}] }. Throws
 * BackendRejection on a 4xx (the connection is refused) and a plain Error otherwise.
 */
export async function load({ token, room }, options = {}) {
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
 * ONE append attempt of an update: POST {room, generation, update_id, sha256, payload, origin}
 * (+ seed_state_version for the seed of an empty log). Resolves the answer:
 *   - `{ ok: true, status, ack: {generation, update_id, seq, sha256, persisted} }` after the commit;
 *   - `{ ok: false, status, code, retryAfterMs }` for any other status.
 * Throws when there is no answer (network error, `timeoutMs` elapsed) — src/retry.js reads that
 * as retryable.
 */
export async function append({ token, room, generation, updateId, sha256, payload, origin = 'client', seedStateVersion }, options = {}) {
  const body = { room, generation, update_id: updateId, sha256, payload, origin }

  if (Number.isInteger(seedStateVersion)) {
    body.seed_state_version = seedStateVersion
  }

  const response = await post('append', body, token, options)

  if (response.ok) {
    const ack = await response.json().catch(() => null)

    // A 2xx without a committed receipt is not an ack; the request is repeated as for a 5xx.
    if (ack?.persisted !== true || !Number.isInteger(ack?.seq) || String(ack?.update_id).toLowerCase() !== String(updateId).toLowerCase()) {
      return { ok: false, status: 502, code: 'collaboration_invalid_receipt', retryAfterMs: null }
    }

    return { ok: true, status: response.status, ack }
  }

  return refusal(response)
}

/** A non-2xx answer: its status, problem code and, for a 429, the Retry-After wait. */
async function refusal(response) {
  return {
    ok: false,
    status: response.status,
    code: await problemCode(response),
    retryAfterMs: response.status === 429 ? parseRetryAfter(header(response, 'Retry-After')) : null,
  }
}

/**
 * ONE compaction lease call of the hub's worker: POST {hub_id, max}, signed, without a room
 * token. Resolves:
 *   - `{ ok: true, status, entries, serverTime }` — the generations leased to this hub, drain
 *     requests first, each `{document, generation, lease_token, lease_expires_at, checkpoint_seq,
 *     head_seq, checkpoint_version, type, schema_version, checkpoint, content, tail}`; `serverTime`
 *     is the answer's Date (ms, null without one), which the worker measures the leases against;
 *   - `{ ok: false, status, code, retryAfterMs }` for any other answer.
 * Throws when there is no answer (network error, timeout).
 */
export async function leaseCompaction({ hubId, max }, options = {}) {
  const response = await post('compaction/lease', { hub_id: hubId, max }, null, options)

  if (!response.ok) {
    return refusal(response)
  }

  const entries = await response.json().catch(() => null)

  if (!Array.isArray(entries)) {
    return { ok: false, status: 502, code: 'collaboration_invalid_lease', retryAfterMs: null }
  }

  const date = Date.parse(header(response, 'Date') ?? '')

  return { ok: true, status: response.status, entries, serverTime: Number.isNaN(date) ? null : date }
}

/**
 * ONE checkpoint publication under a compaction lease: POST {document, generation, lease_token,
 * covered_from, covered_through, expected_checkpoint_version, state, content}, signed, without a
 * room token. Resolves `{ ok: true, status, checkpointVersion, markerCleared }` after the commit,
 * `{ ok: false, status, code, retryAfterMs }` otherwise (409 collaboration_checkpoint_conflict: the
 * lease or the log moved on; the backend changed and deleted nothing). Throws when there is no
 * answer — src/retry.js reads that as retryable.
 */
export async function publishCheckpoint({ document, generation, leaseToken, coveredFrom, coveredThrough, expectedCheckpointVersion, state, content }, options = {}) {
  const response = await post('checkpoint', {
    document,
    generation,
    lease_token: leaseToken,
    covered_from: coveredFrom,
    covered_through: coveredThrough,
    expected_checkpoint_version: expectedCheckpointVersion,
    state,
    content,
  }, null, options)

  if (!response.ok) {
    return refusal(response)
  }

  const result = await response.json().catch(() => null)

  // A 2xx without the published version is not a publication; repeated as for a 5xx.
  if (!Number.isInteger(result?.checkpoint_version) || typeof result?.marker_cleared !== 'boolean') {
    return { ok: false, status: 502, code: 'collaboration_invalid_checkpoint', retryAfterMs: null }
  }

  return { ok: true, status: response.status, checkpointVersion: result.checkpoint_version, markerCleared: result.marker_cleared }
}

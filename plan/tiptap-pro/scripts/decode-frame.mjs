import { createHash } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { pathToFileURL } from 'node:url'
import * as decoding from '../lab/node_modules/lib0/decoding.js'

const REDACTED = '[REDACTED]'
const SECRET_KEY = /token|key|auth|secret|cookie|password|credential|signature|session|bearer/i
const MAX_FRAME_BYTES = 16 * 1024 * 1024
const TYPES = new Map([[0, 'Sync'], [1, 'Awareness'], [2, 'Auth'], [3, 'QueryAwareness'], [5, 'Stateless'], [7, 'CLOSE'], [8, 'SyncStatus']])

function digest(bytes) {
  return createHash('sha256').update(bytes).digest('hex')
}

function sanitizeString(value) {
  return value
    .replace(/-----BEGIN [A-Z0-9 ]*(?:PRIVATE KEY|CERTIFICATE)-----[\s\S]*?-----END [A-Z0-9 ]*(?:PRIVATE KEY|CERTIFICATE)-----/g, REDACTED)
    .replace(/\b[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/g, REDACTED)
    .replace(/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+/=-]+/gi, REDACTED)
    .replace(/(?:https?|wss?):\/\/[^\s<>"']+/gi, match => {
      try {
        const url = new URL(match)
        if (url.username || url.password) {
          url.username = REDACTED
          url.password = REDACTED
        }
        // Query/fragment values are unnecessary to identify the endpoint.
        for (const name of [...url.searchParams.keys()]) url.searchParams.set(name, REDACTED)
        if (url.hash) url.hash = REDACTED
        return url.toString()
      } catch {
        return '[REDACTED URL]'
      }
    })
    .replace(/\b(?:token|api[_-]?key|authorization|secret|password)\s*[:=]\s*(?:"[^"]*"|'[^']*'|[^\s&,;]+)/gi, REDACTED)
}

/** Sanitize observations before any logging or artifact serialization. */
export function sanitize(value) {
  const visited = new WeakSet()
  function visit(item, depth = 0) {
    if (depth > 32) return '[DEPTH LIMIT]'
    if (typeof item === 'string') return sanitizeString(item)
    if (item === null || typeof item === 'boolean' || typeof item === 'number') return item
    if (typeof item === 'bigint') return String(item)
    if (typeof item !== 'object') return null
    if (ArrayBuffer.isView(item) || item instanceof ArrayBuffer) {
      const bytes = item instanceof ArrayBuffer
        ? Buffer.from(item)
        : Buffer.from(item.buffer, item.byteOffset, item.byteLength)
      return { bytes: bytes.length, sha256: digest(bytes), binary: 'omitted' }
    }
    if (visited.has(item)) return '[CIRCULAR]'
    visited.add(item)
    if (Array.isArray(item)) {
      const result = item.map(entry => visit(entry, depth + 1))
      visited.delete(item)
      return result
    }
    const result = Object.create(null)
    const descriptors = Object.getOwnPropertyDescriptors(item)
    const headerName = descriptors.name?.value ?? descriptors.key?.value
    const sensitivePair = typeof headerName === 'string' && SECRET_KEY.test(headerName)
    // Do not invoke user-defined getters while handling captured objects.
    for (const [name, descriptor] of Object.entries(descriptors)) {
      if (!descriptor.enumerable) continue
      const safeName = sanitizeString(name)
      result[safeName] = SECRET_KEY.test(name) || (sensitivePair && name === 'value')
        ? REDACTED
        : 'value' in descriptor ? visit(descriptor.value, depth + 1) : '[ACCESSOR OMITTED]'
    }
    visited.delete(item)
    return result
  }
  return visit(value)
}

/** Decode the OSS Hocuspocus address envelope. Never return raw frame bytes. */
export function decodeFrame(input) {
  const bytes = input instanceof ArrayBuffer ? Buffer.from(input) : Buffer.from(input)
  const summary = { bytes: bytes.length, sha256: digest(bytes) }
  if (bytes.length > MAX_FRAME_BYTES) return { ...summary, status: 'oversized' }
  try {
    const decoder = decoding.createDecoder(bytes)
    const documentName = decoding.readVarString(decoder)
    const type = decoding.readVarUint(decoder)
    if (!TYPES.has(type)) return { ...summary, status: 'unknown-type' }
    const frame = { ...summary, status: 'decoded', documentName: sanitizeString(documentName), type, typeName: TYPES.get(type) }
    if (type === 2) {
      // Suppress the entire auth payload regardless of subtype/version/parser success.
      frame.authSubtype = decoding.readVarUint(decoder)
      frame.authPayload = REDACTED
      frame.payloadBytes = bytes.length - decoder.pos
      return frame
    }
    if (type === 0) {
      const subtype = decoding.readVarUint(decoder)
      if (subtype > 2) return { ...summary, status: 'unknown-sync-subtype' }
      const payload = decoding.readVarUint8Array(decoder)
      frame.syncSubtype = subtype
      frame.syncName = ['SyncStep1', 'SyncStep2', 'Update'][subtype]
      frame.payloadBytes = payload.length
      frame.payloadSha256 = digest(payload)
    } else if (type === 1) {
      const payload = decoding.readVarUint8Array(decoder)
      frame.payloadBytes = payload.length
      frame.payloadSha256 = digest(payload)
    } else if (type === 5) {
      const payload = decoding.readVarString(decoder)
      frame.payloadBytes = Buffer.byteLength(payload)
      try {
        frame.payload = sanitize(JSON.parse(payload))
      } catch {
        frame.payload = { status: 'opaque', sha256: digest(Buffer.from(payload)) }
      }
    } else if (type === 7) {
      // Close reasons are arbitrary server strings and may echo credentials.
      const reason = decoding.readVarString(decoder)
      frame.reasonBytes = Buffer.byteLength(reason)
      frame.reason = '[OMITTED]'
    } else if (type === 8) {
      frame.syncStatus = decoding.readVarInt(decoder)
      frame.applied = frame.syncStatus === 1 ? true : frame.syncStatus === 0 ? false : null
    }
    if (decoder.pos > bytes.length) return { ...summary, status: 'malformed' }
    frame.trailingBytes = bytes.length - decoder.pos
    if (frame.trailingBytes) frame.status = 'partially-decoded'
    return frame
  } catch {
    // Error messages and partially parsed strings can contain secrets.
    return { ...summary, status: 'malformed' }
  }
}

async function selfCheck() {
  const encoding = await import('../lab/node_modules/lib0/encoding.js')
  const token = 'synthetic-private-token-do-not-log'
  const encoder = encoding.createEncoder()
  encoding.writeVarString(encoder, 'tiptap-pro-lab-self-check')
  encoding.writeVarUint(encoder, 2)
  encoding.writeVarUint(encoder, 0)
  encoding.writeVarString(encoder, token)
  const auth = decodeFrame(encoding.toUint8Array(encoder))
  const malformed = decodeFrame(Buffer.from([255]))
  if (JSON.stringify({ auth, malformed }).includes(token) || malformed.status !== 'malformed') {
    throw new Error('Decoder self-check failed')
  }
  return { ok: true, checks: ['auth-payload-redacted', 'malformed-frame-safe'], auth, malformed }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const file = process.argv[2]
  if (file === '--self-check') {
    console.log(JSON.stringify(await selfCheck(), null, 2))
  } else if (file) {
    console.log(JSON.stringify(decodeFrame(await readFile(file === '-' ? '/dev/stdin' : file)), null, 2))
  } else {
    console.error('Usage: node decode-frame.mjs <binary-frame-file|-> | --self-check')
    process.exitCode = 1
  }
}

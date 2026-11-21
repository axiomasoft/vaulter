import test from 'node:test'
import assert from 'node:assert/strict'
import * as encoding from '../lab/node_modules/lib0/encoding.js'
import { decodeFrame, sanitize } from './decode-frame.mjs'

function frame(type, write, name = 'tiptap-pro-lab-test') {
  const encoder = encoding.createEncoder()
  encoding.writeVarString(encoder, name)
  encoding.writeVarUint(encoder, type)
  write?.(encoder)
  return encoding.toUint8Array(encoder)
}

test('decodes address prefix and sync subtype without exposing update content', () => {
  const result = decodeFrame(frame(0, e => {
    encoding.writeVarUint(e, 2)
    encoding.writeVarUint8Array(e, Buffer.from('private document content'))
  }))
  assert.equal(result.documentName, 'tiptap-pro-lab-test')
  assert.equal(result.type, 0)
  assert.equal(result.syncSubtype, 2)
  assert.equal(result.syncName, 'Update')
  assert.equal(result.payloadBytes, 24)
  assert.equal(result.trailingBytes, 0)
  assert.match(result.payloadSha256, /^[a-f0-9]{64}$/)
  assert.ok(!JSON.stringify(result).includes('private document content'))
})

test('all auth subtypes suppress the complete payload, even unrecognizable tokens', () => {
  for (const subtype of [0, 1, 2, 99]) {
    const secret = 'not-a-JWT-and-not-recognizable'
    const result = decodeFrame(frame(2, e => {
      encoding.writeVarUint(e, subtype)
      encoding.writeVarString(e, secret)
    }))
    assert.equal(result.authSubtype, subtype)
    assert.equal(result.authPayload, '[REDACTED]')
    assert.ok(!JSON.stringify(result).includes(secret))
  }
})

test('stateless JSON preserves event but recursively removes credentials', () => {
  const secret = 'synthetic-npm-token'
  const result = decodeFrame(frame(5, e => encoding.writeVarString(e, JSON.stringify({
    event: 'version.created', version: 2,
    meta: { secretValue: secret, nested: [{ authorization: secret }, { note: 'Bearer abcdef-secret' }] },
  }))))
  assert.equal(result.payload.event, 'version.created')
  assert.equal(result.payload.version, 2)
  assert.ok(!JSON.stringify(result).includes(secret))
  assert.ok(!JSON.stringify(result).includes('abcdef-secret'))
})

test('unknown and malformed frames emit only safe fingerprint metadata', () => {
  const unknown = decodeFrame(frame(999, e => encoding.writeVarString(e, 'secret-on-wire')))
  const malformed = decodeFrame(Buffer.from([255]))
  for (const result of [unknown, malformed]) {
    assert.deepEqual(Object.keys(result).sort(), ['bytes', 'sha256', 'status'])
    assert.match(result.sha256, /^[a-f0-9]{64}$/)
    assert.ok(!JSON.stringify(result).includes('secret-on-wire'))
  }
})

test('truncated length-delimited payload is rejected rather than partly trusted', () => {
  const result = decodeFrame(frame(0, e => {
    encoding.writeVarUint(e, 2)
    encoding.writeVarUint(e, 10000)
    encoding.writeUint8(e, 0)
  }))
  assert.equal(result.status, 'malformed')
  assert.deepEqual(Object.keys(result).sort(), ['bytes', 'sha256', 'status'])
})

test('opaque stateless payload and close reason cannot echo arbitrary tokens', () => {
  for (const type of [5, 7]) {
    const result = decodeFrame(frame(type, e => encoding.writeVarString(e, 'unstructured-secret-token')))
    assert.ok(!JSON.stringify(result).includes('unstructured-secret-token'))
  }
})

test('sanitizer scrubs URL query, JWT, PEM, binary data, cycles and sensitive keys', () => {
  const jwt = 'eyJhbGciOiJFUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.c2lnbmF0dXJl'
  const pem = '-----BEGIN PRIVATE KEY-----\nVERY_PRIVATE_BYTES\n-----END PRIVATE KEY-----'
  const input = {
    request: 'https://user:password@example.test/path?custom=custom-secret#fragment-secret',
    nested: [{ message: `Received ${jwt}` }, { message: pem }],
    privateKey: 'arbitrary-private-key', binary: Buffer.from('hidden-bytes'),
    headers: [{ name: 'Authorization', value: 'opaque-auth-value' }, { name: 'X-Api-Key', value: 'opaque-api-value' }],
  }
  input.self = input
  Object.defineProperty(input, 'getter', { enumerable: true, get() { throw new Error('must not run') } })
  const result = sanitize(input)
  const json = JSON.stringify(result)
  for (const secret of [jwt, 'VERY_PRIVATE_BYTES', 'password', 'custom-secret', 'fragment-secret', 'arbitrary-private-key', 'hidden-bytes', 'opaque-auth-value', 'opaque-api-value']) {
    assert.ok(!json.includes(secret), `leaked sentinel ${secret}`)
  }
  assert.match(result.request, /example\.test\/path/)
  assert.equal(result.self, '[CIRCULAR]')
  assert.equal(result.getter, '[ACCESSOR OMITTED]')
})

test('shared non-cyclic metadata stays readable at each occurrence', () => {
  const shared = { name: 'Synthetic User', privateKey: 'opaque-key-material' }
  const result = sanitize({ a: shared, b: shared })
  assert.equal(result.a.name, 'Synthetic User')
  assert.equal(result.b.name, 'Synthetic User')
  assert.equal(result.b.privateKey, '[REDACTED]')
})

test('SyncStatus distinguishes server rejection and application; trailing bytes are visible', () => {
  assert.equal(decodeFrame(frame(8, e => encoding.writeVarUint(e, 0))).applied, false)
  assert.equal(decodeFrame(frame(8, e => encoding.writeVarUint(e, 1))).applied, true)
  const extra = decodeFrame(frame(8, e => {
    encoding.writeVarUint(e, 1)
    encoding.writeVarUint(e, 123)
  }))
  assert.equal(extra.status, 'partially-decoded')
  assert.equal(extra.trailingBytes, 1)
})

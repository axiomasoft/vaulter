import { test } from 'node:test'
import assert from 'node:assert/strict'
import { buildConfig, isOriginAllowed, validateConfig } from '../src/config.js'

// CSWSH Origin allowlist + fail-fast config validation (master plan Addendum A.3).

const prod = (extra = {}) => buildConfig({ NODE_ENV: 'production', VAULTER_BACKEND_URL: 'http://app', ...extra })
const dev = (extra = {}) => buildConfig({ NODE_ENV: 'development', ...extra })

test('a browser Origin on the allowlist is allowed (exact match)', () => {
  const conf = prod({ VAULTER_COLLAB_ALLOWED_ORIGINS: 'https://app.example.com,https://admin.example.com' })
  assert.equal(isOriginAllowed('https://app.example.com', conf), true)
})

test('a browser Origin NOT on the allowlist is rejected', () => {
  const conf = prod({ VAULTER_COLLAB_ALLOWED_ORIGINS: 'https://app.example.com' })
  assert.equal(isOriginAllowed('https://evil.example.com', conf), false)
})

test('no substring/prefix bypass of the allowlist', () => {
  const conf = prod({ VAULTER_COLLAB_ALLOWED_ORIGINS: 'https://app.example.com' })
  assert.equal(isOriginAllowed('https://app.example.com.evil.com', conf), false)
  assert.equal(isOriginAllowed('https://app.example.com:1', conf), false)
})

test('a non-browser client (no Origin) is allowed — not a CSWSH vector, still auth-gated', () => {
  const conf = prod({ VAULTER_COLLAB_ALLOWED_ORIGINS: 'https://app.example.com' })
  assert.equal(isOriginAllowed(undefined, conf), true)
  assert.equal(isOriginAllowed('', conf), true)
})

test('"*" in the allowlist allows any Origin (explicit opt-in)', () => {
  const conf = prod({ VAULTER_COLLAB_ALLOWED_ORIGINS: '*' })
  assert.equal(isOriginAllowed('https://anything.example', conf), true)
})

test('dev with an empty allowlist allows any Origin (convenience)', () => {
  assert.equal(isOriginAllowed('http://localhost:5273', dev()), true)
})

test('production with an empty allowlist rejects a browser Origin (fail-closed)', () => {
  // (validateConfig also makes this combination fatal at boot — see below.)
  const conf = buildConfig({ NODE_ENV: 'production', VAULTER_BACKEND_URL: 'http://app' })
  assert.equal(isOriginAllowed('https://app.example.com', conf), false)
})

test('validateConfig: production requires a backend URL and an origin allowlist', () => {
  const problems = validateConfig(buildConfig({ NODE_ENV: 'production' }))
  assert.equal(problems.length, 2)
  assert.ok(problems.some((p) => p.includes('VAULTER_BACKEND_URL')))
  assert.ok(problems.some((p) => p.includes('VAULTER_COLLAB_ALLOWED_ORIGINS')))
})

test('validateConfig: a fully configured production hub is valid', () => {
  const problems = validateConfig(prod({ VAULTER_COLLAB_ALLOWED_ORIGINS: 'https://app.example.com' }))
  assert.deepEqual(problems, [])
})

test('validateConfig: dev needs no backend/origin config', () => {
  assert.deepEqual(validateConfig(dev()), [])
})

test('validateConfig: a non-positive maxPayload is rejected', () => {
  const problems = validateConfig(dev({ VAULTER_COLLAB_MAX_PAYLOAD_BYTES: '0' }))
  assert.ok(problems.some((p) => p.includes('VAULTER_COLLAB_MAX_PAYLOAD_BYTES')))
})

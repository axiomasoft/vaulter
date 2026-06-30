import { test } from 'node:test'
import assert from 'node:assert/strict'

// Proves the WIRED onConnect Origin gate (not just the policy fn) refuses a forged
// browser Origin under a production config. Env is set BEFORE importing index.js so the
// module-level config is built restrictively (node --test runs each file in its own
// process, so this env does not leak into the other suites).
process.env.NODE_ENV = 'production'
process.env.VAULTER_BACKEND_URL = 'http://app'
process.env.VAULTER_COLLAB_ALLOWED_ORIGINS = 'https://app.example.com'

const { onConnect } = await import('../src/index.js')

test('onConnect refuses a forged browser Origin (CSWSH)', async () => {
  await assert.rejects(
    () => onConnect({ requestHeaders: { origin: 'https://evil.example.com' } }),
    /origin not allowed/i,
  )
})

test('onConnect admits an allowlisted browser Origin', async () => {
  await assert.doesNotReject(
    () => onConnect({ requestHeaders: { origin: 'https://app.example.com' } }),
  )
})

test('onConnect admits a non-browser client with no Origin (still auth-gated downstream)', async () => {
  await assert.doesNotReject(() => onConnect({ requestHeaders: {} }))
})

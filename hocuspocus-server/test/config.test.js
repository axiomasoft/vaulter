import { test } from 'node:test'
import assert from 'node:assert/strict'
import { resolveWebhookBase } from '../src/config.js'

// The webhook base URL the collab/authenticate + collab/store calls live under.

test('derives the webhook base from backend URL + the default api prefix', () => {
  assert.equal(
    resolveWebhookBase({ VAULTER_BACKEND_URL: 'http://app' }),
    'http://app/api/vaulter',
  )
})

test('respects a custom api prefix and strips stray slashes', () => {
  assert.equal(
    resolveWebhookBase({ VAULTER_BACKEND_URL: 'http://app/', VAULTER_API_PREFIX: '/v1/vaulter/' }),
    'http://app/v1/vaulter',
  )
})

test('an explicit VAULTER_LARAVEL_WEBHOOK_URL wins over the derived base', () => {
  assert.equal(
    resolveWebhookBase({
      VAULTER_BACKEND_URL: 'http://ignored',
      VAULTER_LARAVEL_WEBHOOK_URL: 'https://collab.example.com/api/vaulter/',
    }),
    'https://collab.example.com/api/vaulter',
  )
})

test('falls back to localhost when nothing is configured', () => {
  assert.equal(resolveWebhookBase({}), 'http://localhost/api/vaulter')
})

// Runtime configuration for the Vaulter collaboration hub, read from the environment
// (see .env.example). The names align with the Laravel package config
// (config/vaulter.php → vaulter.collaboration):
//
//   VAULTER_COLLAB_PORT          — the WebSocket port the hub listens on (default 1234).
//   VAULTER_COLLAB_HEALTH_PORT   — the HTTP health endpoint port (default 1235).
//   VAULTER_BACKEND_URL          — the Laravel app base URL (e.g. http://app).
//   VAULTER_API_PREFIX           — the API route prefix (default api/vaulter).
//   VAULTER_LARAVEL_WEBHOOK_URL  — explicit override of the full webhook base
//                                  (…/api/vaulter); otherwise derived from the two above.
//   VAULTER_COLLAB_WEBHOOK_SECRET— the shared secret presented on the webhook calls
//                                  (X-Vaulter-Collab-Secret). Mirrors the Laravel
//                                  config `vaulter.collaboration.webhook_secret`.
//
// The room JWT itself is minted by Laravel and forwarded by the client; the hub does
// NOT need the JWT signing secret — it only relays the token to the webhook.

function str(name, fallback = undefined) {
  const value = process.env[name]
  return typeof value === 'string' && value !== '' ? value : fallback
}

function int(name, fallback) {
  const raw = process.env[name]
  const parsed = raw === undefined ? NaN : Number.parseInt(raw, 10)
  return Number.isFinite(parsed) ? parsed : fallback
}

function trimSlashes(value) {
  return value.replace(/\/+$/, '')
}

/**
 * The base URL the collab/authenticate + collab/store webhooks live under
 * (…/api/vaulter). An explicit VAULTER_LARAVEL_WEBHOOK_URL wins; otherwise it is
 * derived from the backend URL + the API prefix.
 */
export function resolveWebhookBase(env = process.env) {
  const explicit = env.VAULTER_LARAVEL_WEBHOOK_URL
  if (typeof explicit === 'string' && explicit !== '') {
    return trimSlashes(explicit)
  }

  const backend = trimSlashes(env.VAULTER_BACKEND_URL ?? 'http://localhost')
  const prefix = (env.VAULTER_API_PREFIX ?? 'api/vaulter').replace(/^\/+|\/+$/g, '')

  return prefix === '' ? backend : `${backend}/${prefix}`
}

export const config = {
  port: int('VAULTER_COLLAB_PORT', 1234),
  healthPort: int('VAULTER_COLLAB_HEALTH_PORT', 1235),
  webhookBase: resolveWebhookBase(),
  webhookSecret: str('VAULTER_COLLAB_WEBHOOK_SECRET'),
  // The request timeout (ms) for a webhook round-trip.
  webhookTimeoutMs: int('VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS', 10000),
}

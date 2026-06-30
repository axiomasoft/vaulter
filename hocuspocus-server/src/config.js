// Runtime configuration for the Vaulter collaboration hub, read from the environment
// (12-factor: env-only, no secrets baked into the image). Names align with the Laravel
// package config (config/vaulter.php → vaulter.collaboration). Full contract + security
// notes live in docs/guide/hocuspocus-server.md.
//
// Connectivity:
//   VAULTER_COLLAB_PORT          — WebSocket port the hub listens on (default 1234).
//   VAULTER_COLLAB_HEALTH_PORT   — HTTP health endpoint port (default 1235).
//   VAULTER_BACKEND_URL          — Laravel app base URL (e.g. http://app). REQUIRED in prod.
//   VAULTER_API_PREFIX           — API route prefix (default api/vaulter).
//   VAULTER_LARAVEL_WEBHOOK_URL  — explicit override of the full webhook base (…/api/vaulter).
//   VAULTER_COLLAB_WEBHOOK_SECRET— shared secret presented on the webhook calls
//                                  (X-Vaulter-Collab-Secret). Mirrors the Laravel config.
//   VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS — webhook round-trip timeout (default 10000).
//
// Security (see docs):
//   VAULTER_COLLAB_ALLOWED_ORIGINS — csv of EXACT browser origins allowed to connect
//                                    (CSWSH defence). REQUIRED in prod. "*" = allow any
//                                    (dev only; logs a warning). Empty in prod ⇒ reject all.
//   VAULTER_COLLAB_MAX_PAYLOAD_BYTES — max WS frame size (default 4 MiB; NOT the ws 100 MiB).
//   VAULTER_COLLAB_THROTTLE         — per-IP handshake attempts before ban (default 15).
//   VAULTER_COLLAB_THROTTLE_SECONDS — window the attempts are counted over (default 60).
//   VAULTER_COLLAB_BAN_TIME         — ban duration in minutes (default 5).
//
// Liveness / persistence pressure:
//   VAULTER_COLLAB_TIMEOUT_MS     — dead-peer heartbeat interval (default 30000).
//   VAULTER_COLLAB_DEBOUNCE_MS    — onStoreDocument debounce (default 5000).
//   VAULTER_COLLAB_MAX_DEBOUNCE_MS— hard cap before a forced store (default 30000).
//
// Scaling / ops:
//   VAULTER_COLLAB_REDIS_URL      — when set, enables the Redis extension (multi-instance sync).
//   VAULTER_COLLAB_INSTANCE_NAME  — unique name per instance for log correlation.
//   VAULTER_COLLAB_QUIET          — suppress the startup banner (default true).
//   VAULTER_COLLAB_LOG            — enable the Logger extension (default false; noisy at scale).
//   NODE_ENV                      — 'production' tightens required-config validation.
//
// The room JWT is minted by Laravel and forwarded by the client; the hub does NOT need
// the JWT signing secret — it only relays the token to the webhook (which verifies it).

function str(name, fallback = undefined, env = process.env) {
  const value = env[name]
  return typeof value === 'string' && value !== '' ? value : fallback
}

function int(name, fallback, env = process.env) {
  const raw = env[name]
  const parsed = raw === undefined ? NaN : Number.parseInt(raw, 10)
  return Number.isFinite(parsed) ? parsed : fallback
}

function bool(name, fallback, env = process.env) {
  const raw = env[name]
  if (raw === undefined || raw === '') {
    return fallback
  }
  return ['1', 'true', 'yes', 'on'].includes(raw.toLowerCase())
}

/** A comma-separated env value → array of non-empty trimmed entries. */
function csv(name, env = process.env) {
  return (env[name] ?? '')
    .split(',')
    .map((entry) => entry.trim())
    .filter((entry) => entry !== '')
}

function trimSlashes(value) {
  return value.replace(/\/+$/, '')
}

/**
 * Whether we are in production. FAIL-CLOSED: the value is trimmed + lowercased and ONLY
 * an explicit non-production value relaxes the hub — anything else (a typo, 'prod',
 * 'production ' with a stray space, 'staging', unset) is treated as production so the
 * CSWSH allowlist + boot validation stay enforced. (A naive `=== 'production'` would
 * silently flip a mistyped NODE_ENV into the permissive branch.)
 */
function isProductionEnv(env = process.env) {
  const nodeEnv = str('NODE_ENV', 'production', env).trim().toLowerCase()
  return !['development', 'dev', 'test', 'local'].includes(nodeEnv)
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

export function buildConfig(env = process.env) {
  const allowedOrigins = csv('VAULTER_COLLAB_ALLOWED_ORIGINS', env)

  return {
    port: int('VAULTER_COLLAB_PORT', 1234, env),
    healthPort: int('VAULTER_COLLAB_HEALTH_PORT', 1235, env),
    webhookBase: resolveWebhookBase(env),
    backendUrlSet: typeof str('VAULTER_BACKEND_URL', undefined, env) === 'string'
      || typeof str('VAULTER_LARAVEL_WEBHOOK_URL', undefined, env) === 'string',
    webhookSecret: str('VAULTER_COLLAB_WEBHOOK_SECRET', undefined, env),
    webhookTimeoutMs: int('VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS', 10000, env),

    // Security
    allowedOrigins,
    allowAllOrigins: allowedOrigins.includes('*'),
    maxPayloadBytes: int('VAULTER_COLLAB_MAX_PAYLOAD_BYTES', 4 * 1024 * 1024, env),
    throttle: int('VAULTER_COLLAB_THROTTLE', 15, env),
    throttleSeconds: int('VAULTER_COLLAB_THROTTLE_SECONDS', 60, env),
    banTime: int('VAULTER_COLLAB_BAN_TIME', 5, env),

    // Liveness / persistence pressure
    timeout: int('VAULTER_COLLAB_TIMEOUT_MS', 30000, env),
    debounce: int('VAULTER_COLLAB_DEBOUNCE_MS', 5000, env),
    maxDebounce: int('VAULTER_COLLAB_MAX_DEBOUNCE_MS', 30000, env),

    // Scaling / ops
    redisUrl: str('VAULTER_COLLAB_REDIS_URL', undefined, env),
    instanceName: str('VAULTER_COLLAB_INSTANCE_NAME', 'vaulter-hocuspocus', env),
    quiet: bool('VAULTER_COLLAB_QUIET', true, env),
    logEnabled: bool('VAULTER_COLLAB_LOG', false, env),
    isProduction: isProductionEnv(env),
  }
}

/**
 * Whether a connection's Origin is allowed (CSWSH defence). The policy:
 *   - "*" in the allowlist ⇒ allow any (explicit dev/opt-in).
 *   - dev (NODE_ENV != production) with an EMPTY allowlist ⇒ allow any (convenience; prod
 *     refuses to boot with an empty allowlist — see validateConfig).
 *   - NO Origin header ⇒ allow: a non-browser client (node provider, server-to-server,
 *     native app) is not a CSWSH vector — browsers ALWAYS send Origin — and access is
 *     still gated by the auth webhook (JWT).
 *   - a browser-supplied Origin ⇒ must be on the allowlist by EXACT match (never
 *     wildcard/substring).
 */
export function isOriginAllowed(origin, conf = config) {
  if (conf.allowAllOrigins) {
    return true
  }
  if (!conf.isProduction && conf.allowedOrigins.length === 0) {
    return true
  }
  if (typeof origin !== 'string' || origin === '') {
    return true
  }
  return conf.allowedOrigins.includes(origin)
}

/**
 * Validate the config at startup and return a list of fatal problems (empty = ok). In
 * production we REQUIRE an explicit backend URL and a non-empty origin allowlist, so the
 * hub never boots into an open/misrouted state.
 */
export function validateConfig(conf = config) {
  const problems = []

  if (conf.isProduction && !conf.backendUrlSet) {
    problems.push('VAULTER_BACKEND_URL (or VAULTER_LARAVEL_WEBHOOK_URL) is required in production.')
  }
  if (conf.isProduction && conf.allowedOrigins.length === 0) {
    problems.push('VAULTER_COLLAB_ALLOWED_ORIGINS is required in production (CSWSH defence); set explicit origins or "*" for dev only.')
  }
  if (!Number.isFinite(conf.maxPayloadBytes) || conf.maxPayloadBytes <= 0) {
    problems.push('VAULTER_COLLAB_MAX_PAYLOAD_BYTES must be a positive integer.')
  }

  return problems
}

export const config = buildConfig()

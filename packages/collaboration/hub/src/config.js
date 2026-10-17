// Runtime configuration for the Vaulter collaboration hub, read from the environment
// (12-factor: env-only, no secrets baked into the image). Names align with the Laravel
// package config (config/vaulter.php → vaulter.collaboration). Full contract + security
// notes live in docs/guides/hub.md.
//
// Connectivity:
//   VAULTER_COLLAB_PORT          — WebSocket port the hub listens on (default 1234).
//   VAULTER_COLLAB_HEALTH_PORT   — HTTP health endpoint port (default 1235).
//   VAULTER_BACKEND_URL          — Laravel app base URL (e.g. http://app). REQUIRED in prod.
//   VAULTER_API_PREFIX           — API route prefix (default api/vaulter).
//   VAULTER_API_URL              — versioned API base the hub calls (…/api/vaulter/v1); must end with /v1.
//   VAULTER_LARAVEL_WEBHOOK_URL  — explicit override of the full base (…/api/vaulter/v1); wins over VAULTER_API_URL.
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
// Liveness:
//   VAULTER_COLLAB_TIMEOUT_MS     — dead-peer heartbeat interval (default 30000).
//   VAULTER_COLLAB_DEBOUNCE_MS    — Hocuspocus' debounce before it unloads an idle room (default 5000).
//   VAULTER_COLLAB_MAX_DEBOUNCE_MS— hard cap of that debounce (default 30000).
//
// Persistence (protocol 2; every update is appended through the pending ledger, src/ledger.js):
//   VAULTER_COLLAB_MODE                     — buffered (default) | durable (see src/persistence.js).
//   VAULTER_COLLAB_PENDING_BUDGET_BYTES     — pending bytes of all rooms before writes stop (default 64 MiB).
//   VAULTER_COLLAB_PENDING_ROOM_BUDGET_BYTES— pending bytes of one room before writes stop (default 8 MiB).
//   VAULTER_COLLAB_FLUSH_INTERVAL_MS        — the ledger's flush cycle, which repeats failed appends (default 1000).
//   VAULTER_COLLAB_RETRY_BASE_MS            — full jitter base (default 100).
//   VAULTER_COLLAB_RETRY_CAP_MS             — full jitter cap (default 2000).
//   VAULTER_COLLAB_RETRY_MAX_ATTEMPTS       — attempts of one retry series (default 5).
//   VAULTER_COLLAB_RETRY_DEADLINE_MS        — deadline of one series, network time included (default 10000).
//   VAULTER_COLLAB_RETRY_ATTEMPT_TIMEOUT_MS — timeout of one attempt (default 2000).
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
  if (raw === undefined || raw.trim() === '') {
    return fallback
  }
  // STRICT: only a clean integer is accepted. parseInt('30abc')===30 and parseInt('-5')===-5
  // would silently boot a misconfigured hub — instead return NaN so validateConfig() reports
  // the offending variable and the process fails fast at boot rather than running wrong.
  const trimmed = raw.trim()
  return /^-?\d+$/.test(trimmed) ? Number.parseInt(trimmed, 10) : NaN
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

/** buffered (default): sync before the append, bounded pending memory; durable: writes stop while appends fail. */
export const PERSISTENCE_MODES = Object.freeze(['buffered', 'durable'])

/** The Laravel API version every hub call is addressed to. */
const API_VERSION_SUFFIX = '/v1'

function requireVersionedBase(base, envName) {
  if (!base.endsWith(API_VERSION_SUFFIX)) {
    throw new Error(
      `${envName} must end with "${API_VERSION_SUFFIX}" (e.g. http://app/api/vaulter/v1); got "${base}". `
      + 'The hub only talks to the versioned collaboration/hub API.',
    )
  }

  return base
}

/**
 * The versioned base URL the collaboration/hub calls live under (…/api/vaulter/v1).
 * An explicit VAULTER_LARAVEL_WEBHOOK_URL wins, then VAULTER_API_URL; otherwise it is
 * derived from the backend URL + the API prefix. An explicit base without the /v1
 * suffix is a deploy mistake and fails the start instead of hitting dead routes.
 */
export function resolveWebhookBase(env = process.env) {
  for (const name of ['VAULTER_LARAVEL_WEBHOOK_URL', 'VAULTER_API_URL']) {
    const explicit = env[name]
    if (typeof explicit === 'string' && explicit !== '') {
      return requireVersionedBase(trimSlashes(explicit), name)
    }
  }

  const backend = trimSlashes(env.VAULTER_BACKEND_URL ?? 'http://localhost')
  const prefix = (env.VAULTER_API_PREFIX ?? 'api/vaulter').replace(/^\/+|\/+$/g, '')

  return `${prefix === '' ? backend : `${backend}/${prefix}`}${API_VERSION_SUFFIX}`
}

export function buildConfig(env = process.env) {
  const allowedOrigins = csv('VAULTER_COLLAB_ALLOWED_ORIGINS', env)

  return {
    port: int('VAULTER_COLLAB_PORT', 1234, env),
    healthPort: int('VAULTER_COLLAB_HEALTH_PORT', 1235, env),
    webhookBase: resolveWebhookBase(env),
    backendUrlSet: ['VAULTER_BACKEND_URL', 'VAULTER_API_URL', 'VAULTER_LARAVEL_WEBHOOK_URL']
      .some((name) => typeof str(name, undefined, env) === 'string'),
    webhookSecret: str('VAULTER_COLLAB_WEBHOOK_SECRET', undefined, env),
    webhookTimeoutMs: int('VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS', 10000, env),

    // Security
    allowedOrigins,
    allowAllOrigins: allowedOrigins.includes('*'),
    maxPayloadBytes: int('VAULTER_COLLAB_MAX_PAYLOAD_BYTES', 4 * 1024 * 1024, env),
    throttle: int('VAULTER_COLLAB_THROTTLE', 15, env),
    throttleSeconds: int('VAULTER_COLLAB_THROTTLE_SECONDS', 60, env),
    banTime: int('VAULTER_COLLAB_BAN_TIME', 5, env),

    // Liveness
    timeout: int('VAULTER_COLLAB_TIMEOUT_MS', 30000, env),
    debounce: int('VAULTER_COLLAB_DEBOUNCE_MS', 5000, env),
    maxDebounce: int('VAULTER_COLLAB_MAX_DEBOUNCE_MS', 30000, env),

    // Persistence (protocol 2)
    mode: str('VAULTER_COLLAB_MODE', 'buffered', env).trim().toLowerCase(),
    pendingBudgetBytes: int('VAULTER_COLLAB_PENDING_BUDGET_BYTES', 64 * 1024 * 1024, env),
    pendingRoomBudgetBytes: int('VAULTER_COLLAB_PENDING_ROOM_BUDGET_BYTES', 8 * 1024 * 1024, env),
    flushIntervalMs: int('VAULTER_COLLAB_FLUSH_INTERVAL_MS', 1000, env),
    retryBaseMs: int('VAULTER_COLLAB_RETRY_BASE_MS', 100, env),
    retryCapMs: int('VAULTER_COLLAB_RETRY_CAP_MS', 2000, env),
    retryMaxAttempts: int('VAULTER_COLLAB_RETRY_MAX_ATTEMPTS', 5, env),
    retryDeadlineMs: int('VAULTER_COLLAB_RETRY_DEADLINE_MS', 10000, env),
    retryAttemptTimeoutMs: int('VAULTER_COLLAB_RETRY_ATTEMPT_TIMEOUT_MS', 2000, env),

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
    problems.push('VAULTER_API_URL (or VAULTER_BACKEND_URL, VAULTER_LARAVEL_WEBHOOK_URL) is required in production.')
  }
  if (conf.isProduction && conf.allowedOrigins.length === 0) {
    problems.push('VAULTER_COLLAB_ALLOWED_ORIGINS is required in production (CSWSH defence); set explicit origins or "*" for dev only.')
  }

  // Numeric guards: int() yields NaN for a malformed value (e.g. '12a34', '4.5'), so a typo in
  // any of these fails fast at boot with the offending variable named, rather than silently
  // booting a misconfigured hub. Ports are bound to the valid TCP range and must differ.
  const numeric = [
    { key: 'port', env: 'VAULTER_COLLAB_PORT', min: 1, max: 65535 },
    { key: 'healthPort', env: 'VAULTER_COLLAB_HEALTH_PORT', min: 1, max: 65535 },
    { key: 'maxPayloadBytes', env: 'VAULTER_COLLAB_MAX_PAYLOAD_BYTES', min: 1 },
    { key: 'throttle', env: 'VAULTER_COLLAB_THROTTLE', min: 1 },
    { key: 'throttleSeconds', env: 'VAULTER_COLLAB_THROTTLE_SECONDS', min: 1 },
    { key: 'banTime', env: 'VAULTER_COLLAB_BAN_TIME', min: 0 },
    { key: 'webhookTimeoutMs', env: 'VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS', min: 1 },
    { key: 'timeout', env: 'VAULTER_COLLAB_TIMEOUT_MS', min: 0 },
    { key: 'debounce', env: 'VAULTER_COLLAB_DEBOUNCE_MS', min: 0 },
    { key: 'maxDebounce', env: 'VAULTER_COLLAB_MAX_DEBOUNCE_MS', min: 0 },
    { key: 'pendingBudgetBytes', env: 'VAULTER_COLLAB_PENDING_BUDGET_BYTES', min: 1 },
    { key: 'pendingRoomBudgetBytes', env: 'VAULTER_COLLAB_PENDING_ROOM_BUDGET_BYTES', min: 1 },
    { key: 'flushIntervalMs', env: 'VAULTER_COLLAB_FLUSH_INTERVAL_MS', min: 10 },
    { key: 'retryBaseMs', env: 'VAULTER_COLLAB_RETRY_BASE_MS', min: 1 },
    { key: 'retryCapMs', env: 'VAULTER_COLLAB_RETRY_CAP_MS', min: 1 },
    { key: 'retryMaxAttempts', env: 'VAULTER_COLLAB_RETRY_MAX_ATTEMPTS', min: 1 },
    { key: 'retryDeadlineMs', env: 'VAULTER_COLLAB_RETRY_DEADLINE_MS', min: 1 },
    { key: 'retryAttemptTimeoutMs', env: 'VAULTER_COLLAB_RETRY_ATTEMPT_TIMEOUT_MS', min: 1 },
  ]
  for (const { key, env, min, max } of numeric) {
    const value = conf[key]
    if (!Number.isInteger(value)) {
      problems.push(`${env} must be a valid integer.`)
      continue
    }
    if (value < min) {
      problems.push(`${env} must be >= ${min}.`)
    }
    if (max !== undefined && value > max) {
      problems.push(`${env} must be <= ${max}.`)
    }
  }
  if (Number.isInteger(conf.port) && conf.port === conf.healthPort) {
    problems.push('VAULTER_COLLAB_PORT and VAULTER_COLLAB_HEALTH_PORT must differ.')
  }
  if (!PERSISTENCE_MODES.includes(conf.mode)) {
    problems.push(`VAULTER_COLLAB_MODE must be one of ${PERSISTENCE_MODES.join(', ')}.`)
  }
  if (Number.isInteger(conf.retryBaseMs) && Number.isInteger(conf.retryCapMs) && conf.retryCapMs < conf.retryBaseMs) {
    problems.push('VAULTER_COLLAB_RETRY_CAP_MS must be >= VAULTER_COLLAB_RETRY_BASE_MS.')
  }
  if (Number.isInteger(conf.retryAttemptTimeoutMs) && Number.isInteger(conf.retryDeadlineMs) && conf.retryAttemptTimeoutMs > conf.retryDeadlineMs) {
    problems.push('VAULTER_COLLAB_RETRY_ATTEMPT_TIMEOUT_MS must be <= VAULTER_COLLAB_RETRY_DEADLINE_MS.')
  }

  return problems
}

export const config = buildConfig()

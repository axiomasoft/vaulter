import os from 'node:os'
import * as backendModule from './backend.js'
import { CompactionError, compactLease, REFUSAL } from './compaction.js'
import { config as defaultConfig } from './config.js'
import { retryPolicy, withRetry } from './retry.js'

// The hub's compaction worker (D26, revision 3 p.1): PHP does not merge Yjs, so without it a
// document with an uncheckpointed tail and no open room would keep its pending marker, and REST
// would answer 409 document_state_pending forever. One pass:
//   1. asks the backend for compaction leases (POST compaction/lease {hub_id, max}): the generations
//      with a drain request first, then those whose log reached a trigger — each with the
//      checkpoint C and the tail (C, H] read under the lease's locks;
//   2. merges each entry, in the order the backend gave, into a checkpoint (src/compaction.js);
//   3. publishes it (POST checkpoint) with the lease token, covered_from C, covered_through H and
//      the checkpoint version it started from — the backend's compare-and-set.
// A 409 (checkpoint conflict: the lease or the log moved on; epoch mismatch: the generation was
// closed) is dropped without a retry: the backend changed and deleted nothing, and a later lease
// starts from what is current. Network errors, 5xx and 429 are repeated under src/retry.js, but
// never past the lease: a checkpoint is published only while this hub's lease still holds (with a
// margin; the lease is measured against the answer's Date, so a skewed clock does not stretch it).
// A lease that cannot become a faithful checkpoint (src/compaction.js: an incomplete tail, an
// attribute the TipTap schema would drop …) is not published; the error is logged and counted, and
// the lease runs out.
//
// The worker runs inside `serve` every VAULTER_COLLAB_COMPACTION_INTERVAL_MS (at once again when a
// lease call came back full, so a backlog does not hold drain requests back), and alone in the
// one-shot `compact --pending` (src/cli.js): drain() repeats passes until a lease call hands out
// nothing — after every lease of its own that did not clear a marker has run out, so an empty
// answer cannot hide one — or the deadline passes.
//
// Time (clock, sleep, random), the backend client and the compaction are injectable for tests.

/** Leases are published no later than this before they end (and counted as over this long after). */
export const LEASE_MARGIN_MS = 2000

/** What became of one leased generation. */
export const OUTCOME = Object.freeze({
  PUBLISHED: 'published',
  CONFLICT: 'conflict',
  EPOCH: 'epoch_mismatch',
  EXPIRED: 'lease_expired',
  FAILED: 'compaction_failed',
  REJECTED: 'rejected',
  UNAVAILABLE: 'backend_unavailable',
})

/** The lease owner this process names (the backend keeps it for diagnostics; the token is what counts). */
export function defaultHubId(conf = defaultConfig) {
  return `${conf.instanceName ?? 'vaulter-hocuspocus'}@${os.hostname()}#${process.pid}`.slice(0, 191)
}

function describe(failure) {
  if (!failure) {
    return 'no answer'
  }

  return failure.kind === 'network' ? `no answer (${failure.error?.message ?? 'network error'})` : `${failure.status}${failure.code ? ` ${failure.code}` : ''}`
}

/**
 * The compaction worker of one hub process. `events` may take `outcome(result)` (every leased
 * generation), `compacted({document, generation, seconds, bytes})` and `protocolMismatch()`.
 */
export function createWorker({
  backend = backendModule,
  backendOptions = {},
  conf = defaultConfig,
  log = console,
  clock = Date.now,
  sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
  random = Math.random,
  hubId = defaultHubId(conf),
  compact = compactLease,
  events = {},
} = {}) {
  const policy = retryPolicy(conf)
  const batch = conf.compactionBatch ?? 10
  const intervalMs = conf.compactionIntervalMs ?? 2000
  const counters = {
    passes: 0,
    leaseFailures: 0,
    leased: 0,
    published: 0,
    markersCleared: 0,
    conflicts: 0,
    epochMismatches: 0,
    expired: 0,
    failures: 0,
    projectionLosses: 0,
    rejected: 0,
    unavailable: 0,
  }
  const compaction = { count: 0, seconds: 0 }
  let leaseFailing = false
  let running = false
  let timer = null
  let current = null

  function emit(name, payload) {
    try {
      events[name]?.(payload)
    } catch {
      // An observer never stops the worker.
    }
  }

  /**
   * When the lease of an entry must be published by, and when the backend surely counts it as
   * over: its remaining time on the backend's clock (lease_expires_at − the answer's Date), from
   * the local moment the answer arrived.
   */
  function leaseWindow(entry, receivedAt, serverTime) {
    const expiresAt = Date.parse(entry?.lease_expires_at ?? '')

    if (Number.isNaN(expiresAt)) {
      return { publishBy: receivedAt, lapsesAt: receivedAt + LEASE_MARGIN_MS }
    }

    const remaining = expiresAt - (serverTime ?? receivedAt)

    return { publishBy: receivedAt + remaining - LEASE_MARGIN_MS, lapsesAt: receivedAt + remaining + LEASE_MARGIN_MS }
  }

  function result(entry, outcome, extra = {}) {
    return { document: String(entry?.document ?? ''), generation: entry?.generation ?? null, outcome, ...extra }
  }

  async function publish(compacted, publishBy) {
    const remaining = publishBy - clock()

    if (remaining <= 0) {
      return { expired: true }
    }

    // A checkpoint may carry max_state_bytes: an attempt gets the webhook timeout, not the append's.
    const attemptTimeoutMs = Math.min(Math.max(policy.attemptTimeoutMs, conf.webhookTimeoutMs ?? 0), remaining)

    return withRetry(({ timeoutMs }) => backend.publishCheckpoint(compacted, { ...backendOptions, timeoutMs }), {
      policy: { ...policy, attemptTimeoutMs, deadlineMs: Math.min(Math.max(policy.deadlineMs, attemptTimeoutMs), remaining) },
      clock,
      sleep,
      random,
    })
  }

  /** Merge and publish one leased generation; resolves its result, never throws. */
  async function compactEntry(entry, receivedAt, serverTime) {
    const { publishBy, lapsesAt } = leaseWindow(entry, receivedAt, serverTime)
    const label = `document ${String(entry?.document ?? '?')} generation ${entry?.generation ?? '?'}`

    if (clock() >= publishBy) {
      counters.expired++
      log.warn?.(`Vaulter collab compaction: the lease of ${label} ran out before its merge; nothing is published`)

      return result(entry, OUTCOME.EXPIRED, { retryAt: lapsesAt })
    }

    const startedAt = clock()
    let compacted

    try {
      compacted = compact(entry)
    } catch (error) {
      const reason = error instanceof CompactionError ? error.reason : 'error'

      counters.failures++
      if (reason === REFUSAL.PROJECTION_LOSS) {
        counters.projectionLosses++
      }
      log.error?.(`Vaulter collab compaction: ${label} is not checkpointed (${reason}): ${error?.message ?? error}`)

      return result(entry, OUTCOME.FAILED, { reason, retryAt: lapsesAt })
    }

    const seconds = Math.max(0, clock() - startedAt) / 1000
    compaction.count++
    compaction.seconds += seconds
    emit('compacted', { document: String(entry.document), generation: entry.generation, seconds, bytes: compacted.bytes })

    const series = await publish(compacted, publishBy)

    if (series.expired) {
      counters.expired++
      log.warn?.(`Vaulter collab compaction: the lease of ${label} ran out during its merge; nothing is published`)

      return result(entry, OUTCOME.EXPIRED, { retryAt: lapsesAt })
    }

    const answer = series.outcome ?? {}

    if (answer.ok === true) {
      counters.published++
      if (answer.markerCleared) {
        counters.markersCleared++
      }

      // The publication spent the lease: the generation can be leased again at once.
      return result(entry, OUTCOME.PUBLISHED, { markerCleared: answer.markerCleared, checkpointVersion: answer.checkpointVersion, coveredThrough: compacted.coveredThrough, retryAt: clock() })
    }

    if (series.exhausted) {
      counters.unavailable++
      log.error?.(`Vaulter collab compaction: the checkpoint of ${label} was not published, the backend did not answer (${describe(answer)})`)

      return result(entry, OUTCOME.UNAVAILABLE, { status: answer.status ?? null, code: answer.code ?? null, retryAt: lapsesAt })
    }

    if (answer.status === 409 && answer.code === backendModule.EPOCH_MISMATCH) {
      counters.epochMismatches++
      log.warn?.(`Vaulter collab compaction: ${label} was closed (restore) before its checkpoint; dropped`)

      return result(entry, OUTCOME.EPOCH, { status: 409, code: answer.code, retryAt: clock() })
    }

    if (answer.status === 409 && answer.code === backendModule.PROTOCOL_MISMATCH) {
      emit('protocolMismatch')
    }

    if (answer.status === 409) {
      counters.conflicts++
      log.warn?.(`Vaulter collab compaction: the checkpoint of ${label} lost its compare-and-set (${describe(answer)}); dropped, not repeated`)

      return result(entry, OUTCOME.CONFLICT, { status: 409, code: answer.code ?? null, retryAt: lapsesAt })
    }

    counters.rejected++
    log.error?.(`Vaulter collab compaction: the backend refused the checkpoint of ${label} (${describe(answer)})`)

    return result(entry, OUTCOME.REJECTED, { status: answer.status ?? null, code: answer.code ?? null, retryAt: lapsesAt })
  }

  /**
   * One pass: one lease call, then every leased generation in the backend's order. Resolves
   * `{ ok: true, entries, results }` or, when the lease call failed, `{ ok: false, status, code,
   * error }`. Never throws.
   */
  async function runOnce() {
    counters.passes++
    let answer

    try {
      answer = await backend.leaseCompaction({ hubId, max: batch }, backendOptions)
    } catch (error) {
      answer = { ok: false, status: null, code: null, error }
    }

    if (!answer.ok) {
      counters.leaseFailures++
      if (answer.status === 409 && answer.code === backendModule.PROTOCOL_MISMATCH) {
        emit('protocolMismatch')
      }
      if (!leaseFailing) {
        log.error?.(`Vaulter collab compaction: the lease call failed (${answer.error ? `no answer: ${answer.error.message ?? answer.error}` : describe(answer)}); the worker keeps asking`)
      }
      leaseFailing = true

      return { ok: false, status: answer.status, code: answer.code, error: answer.error ?? null }
    }

    if (leaseFailing) {
      log.info?.('Vaulter collab compaction: the lease call answers again')
    }
    leaseFailing = false

    const receivedAt = clock()
    const results = []
    counters.leased += answer.entries.length

    for (const entry of answer.entries) {
      const outcome = await compactEntry(entry, receivedAt, answer.serverTime ?? null)
      results.push(outcome)
      emit('outcome', outcome)
    }

    return { ok: true, entries: answer.entries.length, results }
  }

  function schedule(delayMs) {
    timer = setTimeout(tick, delayMs)
    timer.unref?.()
  }

  async function tick() {
    timer = null
    current = runOnce().catch((error) => {
      log.error?.(`Vaulter collab compaction: the pass failed: ${error?.message ?? error}`)

      return null
    })
    const pass = await current
    current = null

    if (running) {
      schedule(pass?.ok && pass.entries >= batch ? 0 : intervalMs)
    }
  }

  /** Run passes every interval (in `serve`); the timer never keeps the process alive. */
  function start() {
    if (running) {
      return
    }

    running = true
    schedule(0)
  }

  /** Stop the cycle; resolves once the pass in flight (if any) has ended. */
  async function stop() {
    running = false

    if (timer) {
      clearTimeout(timer)
      timer = null
    }

    await current
  }

  /**
   * Run passes until a lease call hands out nothing (`drained: true`) or `deadlineMs` passed
   * (`drained: false`). An empty answer settles the run only once every generation this run
   * leased without clearing its marker has had its lease run out before that call — until then the
   * backend would not hand it out again, however pending it is. `remaining` lists the generations
   * not shown to be clear: those whose last outcome left the marker, and those of the last lease
   * answer when it was not empty (the backend still had them pending); `failure` is the last failed
   * lease call.
   */
  async function drain({ deadlineMs }) {
    const deadlineAt = clock() + deadlineMs
    /** document → the last result of a generation whose marker this run did not see cleared. */
    const unresolved = new Map()
    let lastAnswer = []
    let failure = null
    let published = 0

    for (;;) {
      const askedAt = clock()

      if (askedAt >= deadlineAt) {
        break
      }

      const pass = await runOnce()

      if (pass.ok) {
        failure = null

        for (const outcome of pass.results) {
          if (outcome.outcome === OUTCOME.PUBLISHED) {
            published++
          }
          if (outcome.outcome === OUTCOME.PUBLISHED && outcome.markerCleared) {
            unresolved.delete(outcome.document)
          } else {
            unresolved.set(outcome.document, outcome)
          }
        }

        if (pass.entries === 0) {
          const waiting = [...unresolved.values()].filter((outcome) => outcome.retryAt > askedAt)

          if (waiting.length === 0) {
            return { drained: true, published, remaining: [], failure: null }
          }

          // Nothing else was leasable: only the generations still under a lease of this run count.
          lastAnswer = []
        } else {
          lastAnswer = pass.results

          if (pass.entries >= batch) {
            continue
          }
        }
      } else {
        failure = pass
      }

      const wait = Math.min(intervalMs, deadlineAt - clock())

      if (wait > 0) {
        await sleep(wait)
      }
    }

    const remaining = new Map(lastAnswer.map((outcome) => [outcome.document, outcome]))

    for (const [document, outcome] of unresolved) {
      remaining.set(document, outcome)
    }

    return { drained: false, published, remaining: [...remaining.values()], failure }
  }

  return {
    runOnce,
    start,
    stop,
    drain,
    hubId,
    stats: () => ({ ...counters, compactionCount: compaction.count, compactionSeconds: compaction.seconds, running }),
  }
}

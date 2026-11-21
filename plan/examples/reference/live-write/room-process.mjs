// @ts-check
// One Hub-like room owner process for plan/checks/collaboration/live-write.test.mjs. It owns one strict
// room (a real Y.Doc) and its sequencer and talks to the parent over the Node IPC channel. The parent
// kills it with SIGKILL at the point the brief names: after the owner confirmed the commit, before the
// room applied the bytes. The kill barrier is an explicit IPC message, not a timer.
//
// Requests: { id, op, ...args } → replies { id, ok: true, result } | { id, ok: false, error }.
// Events (no id): { event: 'committed', status, appliedSeq, tree } while a command is held after commit.

import crypto from 'node:crypto'
import { setTimeout as delay } from 'node:timers/promises'
import { createPsqlOwner } from './owner-psql.mjs'
import { createRoomSequencer, Y } from './room-sequencer.mjs'
import { readTree } from './schema.mjs'

/** @type {ReturnType<typeof createPsqlOwner> | null} */
let owner = null
/** @type {ReturnType<typeof createRoomSequencer> | null} */
let sequencer = null
const room = new Y.Doc()
const ownerId = crypto.randomUUID()
let fence = 0
/** @type {object[]} */
const traceLog = []

function send(message) {
  process.send?.(message)
}

function snapshot() {
  return { appliedSeq: sequencer?.appliedSeq ?? null, violations: sequencer?.violations ?? 0, tree: readTree(room).paragraphs }
}

/** @param {any} request */
async function handle(request) {
  switch (request.op) {
    case 'open': {
      owner = createPsqlOwner(request.documentId)
      // A lease of a killed process is not released: the new writer waits for its deadline (DB clock).
      for (;;) {
        const lease = owner.acquire(ownerId, request.ttlMs)
        if ('fence' in lease) {
          fence = lease.fence
          sequencer = createRoomSequencer({ room, owner, ownerId, fence, generation: lease.generation, trace: (event) => traceLog.push(event) })
          break
        }
        if (lease.error !== 'held') {
          throw new Error(`acquire refused: ${lease.error}`)
        }
        traceLog.push({ type: 'lease_held', retryAfterMs: lease.retry_after_ms })
        await delay((lease.retry_after_ms ?? 100) + 20)
      }
      await sequencer.open({ throughSeq: request.throughSeq ?? null })

      return { ownerId, fence, ...snapshot(), mayEvaluate: sequencer.mayEvaluate() }
    }
    case 'update': {
      const result = await requireSequencer().submitUpdate(new Uint8Array(Buffer.from(request.payload, 'base64')))

      return { result, ...snapshot() }
    }
    case 'command': {
      const hooks = {}
      if (request.failBeforeEffect !== undefined) {
        hooks.beforeEffect = (index) => {
          if (index === request.failBeforeEffect) {
            throw new Error(`injected failure before effect ${index}`)
          }
        }
      }
      if (request.holdAfterCommit) {
        hooks.afterCommit = async (status) => {
          send({ event: 'committed', status, ...snapshot() })
          // Never resolves: the parent kills this process before the room applies the bytes.
          await new Promise(() => {})
        }
      }
      const result = await requireSequencer().runCommand(request.intent, hooks)

      return { result, ...snapshot() }
    }
    case 'deliver': {
      const result = await requireSequencer().deliver(request.event)

      return { result, ...snapshot() }
    }
    case 'state':
      return { ...snapshot(), mayEvaluate: requireSequencer().mayEvaluate(), trace: traceLog, update: Buffer.from(Y.encodeStateAsUpdate(room)).toString('base64') }
    case 'exit': {
      const released = owner?.release(ownerId, fence) ?? false
      setImmediate(() => process.exit(0))

      return { released }
    }
    default:
      throw new Error(`unknown op ${request.op}`)
  }
}

function requireSequencer() {
  if (!sequencer) {
    throw new Error('room is not open')
  }

  return sequencer
}

process.on('message', (request) => {
  handle(request).then(
    (result) => send({ id: /** @type {any} */ (request).id, ok: true, result }),
    (error) => send({ id: /** @type {any} */ (request).id, ok: false, error: String(error?.stack ?? error) }),
  )
})
// The parent is the test: when it goes away the room goes with it (its lease simply expires).
process.on('disconnect', () => process.exit(1))
send({ event: 'ready', pid: process.pid })

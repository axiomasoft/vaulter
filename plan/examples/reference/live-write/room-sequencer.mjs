// @ts-check
// Plan 3.5 reference: a strict live room whose authoritative state is the owner's journal.
//
// What this module shows on a real Y.Doc (yjs 13.6.31, the version of the Hub lockfile):
//   1. the confirmed base of a command is rebuilt from the owner's binary checkpoint + contiguous tail —
//      never from the live room and never from JSON, so Yjs item IDs are the journal's;
//   2. a compound command is applied to a throw-away COPY in one transaction; any failure drops the copy;
//   3. its update is Y.encodeStateAsUpdate(copy, baseStateVector) — the exact bytes sent to the owner;
//   4. only after the owner's commit the room applies THE STORED BYTES (read back from the log by seq)
//      under an origin the Hub never appends again (see originKind / COMMITTED_ORIGIN);
//   5. a room that lags behind head_seq replays the missing prefix before it evaluates the next command.
//
// Mapping to the product (designed, plan/spec/implementation/vaulter.md 3.5): this file is the reference
// for `hub/src/room-sequencer.js`; OwnerPort is the private `collaboration/hub/commands/settle` +
// `load` + `append` protocol of PHP `CommandJournal`/`RoomOwnership`/`UpdateLog`.

import crypto from 'node:crypto'
import * as Y from 'yjs'

export { Y }

/** The Y.XmlFragment the Tiptap Collaboration extension binds to (packages/collaboration/hub/src/schema.js FIELD). */
export const FIELD = 'default'

/**
 * Marker of the origins the hub applies itself. In the Hub this is the module-private `OWN` symbol of
 * packages/collaboration/hub/src/persistence.js; originKind() there returns 'own' for it and capture()
 * then never appends the transaction. The sequencer must reuse exactly that marker.
 */
export const OWN = Symbol('vaulter.collab.own-origin')

/** Origin of the checkpoint + tail replay when a room loads (persistence.js LOAD_ORIGIN). */
export const LOAD_ORIGIN = Object.freeze({ [OWN]: true, source: 'vaulter-load' })

/** Origin of committed bytes the sequencer applies after the owner's commit (designed for 3.5). */
export const COMMITTED_ORIGIN = Object.freeze({ [OWN]: true, source: 'vaulter-committed' })

/**
 * Mirror of `originKind()` in packages/collaboration/hub/src/persistence.js (b230f6e27, lines 117-160):
 * 'own' → never appended; 'redis' → appended by the hub that took it; 'client' → a connection's sync
 * message; everything else is 'server' and capture() appends it under a NEW update_id.
 * @param {unknown} origin
 * @returns {'own' | 'redis' | 'client' | 'server'}
 */
export function originKind(origin) {
  if (origin && typeof origin === 'object' && /** @type {any} */ (origin)[OWN] === true) {
    return 'own'
  }
  if (origin === '__hocuspocus__redis__origin__' || (origin && typeof origin === 'object' && /** @type {any} */ (origin).source === 'redis')) {
    return 'redis'
  }
  if (origin && typeof origin === 'object' && /** @type {any} */ (origin).source === 'connection') {
    return 'client'
  }

  return 'server'
}

/**
 * @typedef {{ seq: number, update_id: string, sha256: string, payload: string }} LogRecord
 * @typedef {{ generation: number, head_seq: number, checkpoint_seq: number, checkpoint: string | null, tail: LogRecord[] }} LoadedLog
 * @typedef {{ effect: 'replace_exact', block_id: string, expected: string, replacement: string }} ReplaceExact
 * @typedef {{ effect: 'insert_paragraph_after', block_id: string, text: string }} InsertParagraphAfter
 * @typedef {ReplaceExact | InsertParagraphAfter} Effect
 * @typedef {{ block_id: string, expected_text: string, expected_marks: string[] }} Precondition
 * @typedef {{ id: string, command_id: string, generation: number, preconditions: Precondition[], atomic_effects: Effect[] }} StoredIntent
 *   `id` is the owner's row id of the registered intent (v_collaboration_commands.id).
 * @typedef {{ id: string, command_id: string, state: 'running' | 'committed' | 'rejected', receipt_id: string | null,
 *   rejection_code: string | null, base_seq: number | null, from_seq: number | null, to_seq: number | null,
 *   owner_fence: number | null, marker: { generation: number, position: number } | null, duplicate?: boolean }} CommandStatus
 * @typedef {{ error: string, head_seq?: number }} OwnerRefusal
 * @typedef {{ update_id: string, sha256: string, payload: string }} OutgoingUpdate
 * @typedef {{ document_id: string, generation: number, from_seq: number | null, to_seq: number, command_id: string, receipt_id: string }} CommittedEvent
 *
 * BOUNDARY: the owner of the journal. In the product it is PHP (CommandJournal / RoomOwnership / UpdateLog)
 * behind signed Hub routes; the reference check implements it with SQL functions on PostgreSQL 18
 * (plan/checks/collaboration/live-write-owner.sql, adapter owner-psql.mjs).
 * @typedef {{
 *   head(): { generation: number, head_seq: number, owner_fence: number },
 *   load(afterSeq?: number | null, throughSeq?: number | null): LoadedLog,
 *   appendUpdate(input: { ownerId: string, fence: number, expectedHead: number, update: OutgoingUpdate }): { seq: number, duplicate: boolean } | OwnerRefusal,
 *   commandById(id: string): CommandStatus,
 *   settleCommit(input: { id: string, ownerId: string, fence: number, baseSeq: number, updates: OutgoingUpdate[], evidence: object }): CommandStatus | OwnerRefusal,
 *   settleReject(input: { id: string, ownerId: string, fence: number, code: string }): CommandStatus | OwnerRefusal,
 * }} OwnerPort
 */

/** @param {Uint8Array} bytes */
export function sha256Hex(bytes) {
  return crypto.createHash('sha256').update(bytes).digest('hex')
}

/** @param {Uint8Array} bytes */
export function toBase64(bytes) {
  return Buffer.from(bytes).toString('base64')
}

/**
 * Bytes of a log record after checking them against the hash the owner stored.
 * @param {LogRecord} record
 */
export function recordBytes(record) {
  const bytes = new Uint8Array(Buffer.from(record.payload, 'base64'))

  if (sha256Hex(bytes) !== record.sha256) {
    throw Object.assign(new Error(`log record ${record.seq} does not match its sha256`), { code: 'hash_mismatch' })
  }

  return bytes
}

/**
 * A fresh Y.Doc holding exactly the confirmed base: checkpoint, then the tail in seq order (contiguous).
 * Same algorithm as persistence.js replayLog(), plus the contiguity and hash checks.
 * @param {LoadedLog} log
 * @returns {{ doc: Y.Doc, position: number }}
 */
export function docFromLog(log) {
  const doc = new Y.Doc()
  let position = log.checkpoint_seq

  if (typeof log.checkpoint === 'string' && log.checkpoint !== '') {
    Y.applyUpdate(doc, new Uint8Array(Buffer.from(log.checkpoint, 'base64')), LOAD_ORIGIN)
  }
  for (const record of log.tail) {
    if (record.seq !== position + 1) {
      throw Object.assign(new Error(`log gap: expected ${position + 1}, got ${record.seq}`), { code: 'log_gap' })
    }
    Y.applyUpdate(doc, recordBytes(record), LOAD_ORIGIN)
    position = record.seq
  }

  return { doc, position }
}

/**
 * The paragraph/heading elements of the document with their block IDs (attrs.blockId of plan 3.1).
 * @param {Y.Doc} doc
 */
function topLevel(doc) {
  return doc.getXmlFragment(FIELD).toArray()
}

/**
 * @param {Y.Doc} doc
 * @param {string} blockId
 * @returns {{ index: number, element: Y.XmlElement }}
 */
function findBlock(doc, blockId) {
  const matches = []

  topLevel(doc).forEach((node, index) => {
    if (node instanceof Y.XmlElement && node.getAttribute('blockId') === blockId) {
      matches.push({ index, element: node })
    }
  })
  if (matches.length !== 1) {
    throw Object.assign(new Error(`block ${blockId}: ${matches.length} matches`), { code: 'target_not_found' })
  }

  return matches[0]
}

/**
 * The single text run of a block. Fail-closed: inline nodes (hard break, image) are not supported by this
 * internal adapter, so a block with anything but one Y.XmlText is refused instead of being guessed at.
 * @param {Y.XmlElement} element
 */
function textOf(element) {
  const children = element.toArray()

  if (children.length !== 1 || !(children[0] instanceof Y.XmlText)) {
    throw Object.assign(new Error('block is not a single text run'), { code: 'unsupported_block' })
  }
  const text = /** @type {Y.XmlText} */ (children[0])
  /** @type {Array<{ insert: string, attributes?: Record<string, unknown> }>} */
  const delta = text.toDelta()

  return { text, delta, plain: delta.map((op) => op.insert).join('') }
}

/**
 * Mark names over [from, to) of a delta (y-prosemirror stores marks as text attributes).
 * @param {Array<{ insert: string, attributes?: Record<string, unknown> }>} delta
 */
function marksBetween(delta, from, to) {
  const marks = new Set()
  let offset = 0

  for (const op of delta) {
    const end = offset + op.insert.length
    if (end > from && offset < to) {
      Object.keys(op.attributes ?? {}).forEach((name) => marks.add(name))
    }
    offset = end
  }

  return [...marks].sort()
}

/**
 * @param {Y.Doc} doc
 * @param {Precondition[]} preconditions
 * @returns {string[]} the failed block IDs
 */
export function failedPreconditions(doc, preconditions) {
  return preconditions.filter((condition) => {
    try {
      const { delta, plain } = textOf(findBlock(doc, condition.block_id).element)

      return plain !== condition.expected_text
        || JSON.stringify(marksBetween(delta, 0, plain.length)) !== JSON.stringify([...condition.expected_marks].sort())
    } catch {
      return true
    }
  }).map((condition) => condition.block_id)
}

/** Deterministic block ID of a paragraph a command inserts (stable across a re-preparation). */
export function insertedBlockId(commandId, effectIndex) {
  return `${commandId}-p${effectIndex}`
}

/**
 * Apply one effect inside the caller's transaction.
 * @param {Y.Doc} doc
 * @param {Effect} effect
 * @param {string} commandId
 * @param {number} index
 */
function applyEffect(doc, effect, commandId, index) {
  const { index: at, element } = findBlock(doc, effect.block_id)

  if (effect.effect === 'replace_exact') {
    const { text, delta, plain } = textOf(element)
    const from = plain.indexOf(effect.expected)

    if (from < 0 || plain.indexOf(effect.expected, from + 1) >= 0) {
      throw Object.assign(new Error(`"${effect.expected}" is not exactly once in ${effect.block_id}`), { code: 'precondition_failed' })
    }
    const attributes = Object.fromEntries(marksBetween(delta, from, from + effect.expected.length).map((name) => [name, {}]))
    text.delete(from, effect.expected.length)
    text.insert(from, effect.replacement, attributes)

    return
  }
  if (effect.effect === 'insert_paragraph_after') {
    const paragraph = new Y.XmlElement('paragraph')
    doc.getXmlFragment(FIELD).insert(at + 1, [paragraph])
    paragraph.setAttribute('blockId', insertedBlockId(commandId, index))
    const text = new Y.XmlText()
    paragraph.insert(0, [text])
    text.insert(0, effect.text)

    return
  }

  throw Object.assign(new Error(`unknown effect ${/** @type {any} */ (effect).effect}`), { code: 'invalid_intent' })
}

/**
 * Plain projection used for postconditions and evidence: [{ blockId, text }] in document order.
 * @param {Y.Doc} doc
 */
export function blockTexts(doc) {
  return topLevel(doc).map((node) => {
    const element = /** @type {Y.XmlElement} */ (node)
    let text = ''
    try {
      text = textOf(element).plain
    } catch {
      text = element.toString()
    }

    return { blockId: element.getAttribute('blockId') ?? null, type: element.nodeName, text }
  })
}

/**
 * The expected projection after all effects, computed from the base projection without Yjs: the
 * postcondition the copy must reach.
 * @param {ReturnType<typeof blockTexts>} base
 * @param {StoredIntent} intent
 */
function expectedAfter(base, intent) {
  const blocks = base.map((block) => ({ ...block }))

  intent.atomic_effects.forEach((effect, index) => {
    const at = blocks.findIndex((block) => block.blockId === effect.block_id)
    if (effect.effect === 'replace_exact') {
      blocks[at].text = blocks[at].text.replace(effect.expected, effect.replacement)
    } else {
      blocks.splice(at + 1, 0, { blockId: insertedBlockId(intent.command_id, index), type: 'paragraph', text: effect.text })
    }
  })

  return blocks
}

/**
 * Prepare a command on a copy of the confirmed base.
 * @param {{ log: LoadedLog, intent: StoredIntent, beforeEffect?: (index: number) => void }} input
 * @returns {{ ok: true, baseSeq: number, update: Uint8Array, sha256: string, evidence: object }
 *   | { ok: false, baseSeq: number, reason: 'precondition_failed' | 'effect_failed' | 'postcondition_failed', detail: string }}
 */
export function prepareOnCopy({ log, intent, beforeEffect }) {
  const { doc: copy, position: baseSeq } = docFromLog(log)

  try {
    const failed = failedPreconditions(copy, intent.preconditions)
    if (failed.length > 0) {
      return { ok: false, baseSeq, reason: 'precondition_failed', detail: failed.join(',') }
    }

    const base = blockTexts(copy)
    const baseVector = Y.encodeStateVector(copy)

    try {
      // One transaction groups the effects into one update, but it does NOT roll back on a throw: the
      // copy is what makes the command atomic (see the partial-failure variant of the check).
      copy.transact(() => {
        intent.atomic_effects.forEach((effect, index) => {
          beforeEffect?.(index)
          applyEffect(copy, effect, intent.command_id, index)
        })
      }, LOAD_ORIGIN)
    } catch (error) {
      const code = /** @type {any} */ (error)?.code

      return { ok: false, baseSeq, reason: code === 'precondition_failed' || code === 'target_not_found' ? 'precondition_failed' : 'effect_failed', detail: String(/** @type {any} */ (error)?.message ?? error) }
    }

    const after = blockTexts(copy)
    if (JSON.stringify(after) !== JSON.stringify(expectedAfter(base, intent))) {
      return { ok: false, baseSeq, reason: 'postcondition_failed', detail: JSON.stringify(after) }
    }

    const update = Y.encodeStateAsUpdate(copy, baseVector)

    // The bytes alone must reproduce the copy on the confirmed base: this is what every replay does.
    const { doc: check } = docFromLog(log)
    Y.applyUpdate(check, update, LOAD_ORIGIN)
    const reproduced = JSON.stringify(blockTexts(check))
    check.destroy()
    if (reproduced !== JSON.stringify(after)) {
      return { ok: false, baseSeq, reason: 'postcondition_failed', detail: 'update does not reproduce the copy' }
    }

    const touched = new Set(intent.atomic_effects.flatMap((effect, index) => [effect.block_id, insertedBlockId(intent.command_id, index)]))

    return { ok: true, baseSeq, update, sha256: sha256Hex(update), evidence: { base_seq: baseSeq, blocks: after.filter((block) => touched.has(block.blockId)) } }
  } finally {
    copy.destroy()
  }
}

/**
 * The single writer of one room/generation/owner_fence. Every change of the room — a user's update, an
 * agent command, a delivered outbox event, a catch-up — runs through `enqueue`, one at a time, and the
 * room only ever receives committed bytes.
 * @param {{ room: Y.Doc, owner: OwnerPort, ownerId: string, fence: number, generation: number,
 *   trace?: (event: object) => void, uuid?: () => string }} options
 */
export function createRoomSequencer({ room, owner, ownerId, fence, generation, trace = () => {}, uuid = () => crypto.randomUUID() }) {
  let appliedSeq = -1
  /** @type {Map<number, string>} */
  const appliedHashes = new Map()
  let chain = Promise.resolve()
  let violations = 0

  // Runtime guard: in a strict room nothing but the sequencer may change the document. Any other origin
  // (a DirectConnection transaction, a Redis-delivered sync message, a client sync update) is a bypass:
  // the hub fences the room and the live capability stays off.
  room.on('update', (_update, origin) => {
    if (origin !== COMMITTED_ORIGIN && origin !== LOAD_ORIGIN) {
      violations++
      trace({ type: 'bypass', origin: originKind(origin) })
    }
  })

  /**
   * @template T
   * @param {() => Promise<T> | T} task
   * @returns {Promise<T>}
   */
  function enqueue(task) {
    const run = chain.then(task)
    chain = run.then(() => undefined, () => undefined)

    return run
  }

  /** @param {LogRecord} record */
  function applyCommitted(record) {
    if (record.seq !== appliedSeq + 1) {
      throw Object.assign(new Error(`applied ${appliedSeq}, record ${record.seq}`), { code: 'log_gap' })
    }
    Y.applyUpdate(room, recordBytes(record), COMMITTED_ORIGIN)
    appliedSeq = record.seq
    appliedHashes.set(record.seq, record.sha256)
    trace({ type: 'applied', seq: record.seq })
  }

  function catchUp() {
    const head = owner.head()

    if (head.generation !== generation) {
      throw Object.assign(new Error('generation changed'), { code: 'generation_changed' })
    }
    if (head.head_seq > appliedSeq) {
      trace({ type: 'catch_up', from: appliedSeq, to: head.head_seq })
      owner.load(appliedSeq, head.head_seq).tail.forEach(applyCommitted)
    }

    return head.head_seq
  }

  /** @param {{ throughSeq?: number | null }} [options] */
  function open({ throughSeq = null } = {}) {
    return enqueue(() => {
      const log = owner.load(null, throughSeq)
      if (log.generation !== generation) {
        throw Object.assign(new Error('generation changed'), { code: 'generation_changed' })
      }
      if (typeof log.checkpoint === 'string' && log.checkpoint !== '') {
        Y.applyUpdate(room, new Uint8Array(Buffer.from(log.checkpoint, 'base64')), LOAD_ORIGIN)
      }
      appliedSeq = log.checkpoint_seq
      for (const record of log.tail) {
        if (record.seq !== appliedSeq + 1) {
          throw Object.assign(new Error('log gap on load'), { code: 'log_gap' })
        }
        Y.applyUpdate(room, recordBytes(record), LOAD_ORIGIN)
        appliedSeq = record.seq
        appliedHashes.set(record.seq, record.sha256)
      }
      trace({ type: 'loaded', appliedSeq, headSeq: log.head_seq })

      return appliedSeq
    })
  }

  /**
   * A user's update in a strict room (an envelope): commit first, then apply the stored bytes.
   * @param {Uint8Array} update
   */
  function submitUpdate(update) {
    return enqueue(() => {
      catchUp()
      const outgoing = { update_id: uuid(), sha256: sha256Hex(update), payload: toBase64(update) }
      const result = owner.appendUpdate({ ownerId, fence, expectedHead: appliedSeq, update: outgoing })
      if ('error' in result) {
        return result
      }
      owner.load(appliedSeq, result.seq).tail.forEach(applyCommitted)

      return result
    })
  }

  /**
   * An outbox event of a committed command delivered to this room (at least once).
   * @param {CommittedEvent & { sha256: string[], payload: string[] }} event
   *   sha256/payload: the bytes the deliverer carries for from_seq..to_seq; the room applies the LOG's bytes
   *   and only after both agree.
   */
  function deliver(event) {
    return enqueue(() => {
      if (event.generation !== generation) {
        return { outcome: 'foreign_generation' }
      }
      if (event.from_seq === null) {
        return { outcome: 'no_bytes', appliedSeq }
      }
      const outcomes = []
      for (let seq = event.from_seq; seq <= event.to_seq; seq++) {
        const index = seq - event.from_seq
        const carried = new Uint8Array(Buffer.from(event.payload[index] ?? '', 'base64'))
        if (sha256Hex(carried) !== event.sha256[index]) {
          outcomes.push('hash_mismatch')
          continue
        }
        if (seq <= appliedSeq) {
          outcomes.push(appliedHashes.get(seq) === event.sha256[index] ? 'duplicate' : 'hash_mismatch')
          continue
        }
        if (seq > appliedSeq + 1) {
          catchUp()
        }
        if (seq <= appliedSeq) {
          outcomes.push(appliedHashes.get(seq) === event.sha256[index] ? 'applied_by_catch_up' : 'hash_mismatch')
          continue
        }
        const [record] = owner.load(appliedSeq, seq).tail
        if (!record || record.seq !== seq || record.sha256 !== event.sha256[index]) {
          outcomes.push('hash_mismatch')
          continue
        }
        applyCommitted(record)
        outcomes.push('applied')
      }

      return { outcome: outcomes.join(','), appliedSeq }
    })
  }

  /**
   * Evaluate and settle a registered command.
   * @param {StoredIntent} intent
   * @param {{ beforeEffect?: (index: number) => void, afterCommit?: (status: CommandStatus) => Promise<void> | void }} [hooks]
   * @returns {Promise<CommandStatus | OwnerRefusal | { outcome: 'failed_before_commit', reason: string, detail: string }>}
   */
  function runCommand(intent, { beforeEffect, afterCommit } = {}) {
    return enqueue(async () => {
      for (let attempt = 1; attempt <= 3; attempt++) {
        const known = owner.commandById(intent.id)
        if (known.state !== 'running') {
          // A repeated command or outbox retry never runs the transformation again.
          trace({ type: 'already_settled', state: known.state })

          return known
        }
        const head = catchUp()
        const log = owner.load(null, head)
        trace({ type: 'evaluate', command: intent.command_id, baseSeq: head, appliedSeq })

        const prepared = prepareOnCopy({ log, intent, beforeEffect })
        if (!prepared.ok) {
          if (prepared.reason === 'precondition_failed') {
            return owner.settleReject({ id: intent.id, ownerId, fence, code: 'precondition_failed' })
          }

          // A crash-like failure: the intent stays running and may be prepared again from the journal.
          return { outcome: 'failed_before_commit', reason: prepared.reason, detail: prepared.detail }
        }

        const status = owner.settleCommit({
          id: intent.id,
          ownerId,
          fence,
          baseSeq: prepared.baseSeq,
          updates: [{ update_id: uuid(), sha256: prepared.sha256, payload: toBase64(prepared.update) }],
          evidence: prepared.evidence,
        })
        if ('error' in status) {
          if (status.error === 'base_moved') {
            continue
          }

          return status
        }
        trace({ type: 'committed', command: intent.command_id, from: status.from_seq, to: status.to_seq })

        await afterCommit?.(status)

        if (status.to_seq !== null && status.to_seq > appliedSeq) {
          owner.load(appliedSeq, status.to_seq).tail.forEach(applyCommitted)
        }

        return status
      }

      return { error: 'base_kept_moving' }
    })
  }

  return {
    open,
    submitUpdate,
    deliver,
    runCommand,
    catchUp: () => enqueue(catchUp),
    /** True only when the room holds the whole confirmed prefix: the next command may be evaluated. */
    mayEvaluate: () => owner.head().head_seq === appliedSeq,
    get appliedSeq() { return appliedSeq },
    get violations() { return violations },
  }
}

// Plan 3.5 check: a compound agent command A42 survives a crash between the owner's SQL commit and the
// room's apply. Real PostgreSQL 18 (temporary cluster of live-write.sh), real separate Node processes,
// real Y.Doc (yjs 13.6.31) and the Hub's Tiptap schema through y-prosemirror.
//
// Run: bash plan/checks/collaboration/live-write.sh   (sets PSQL/PGHOST and installs the pinned sample)
//
// Every count of journal records and receipts comes from SQL (live_write_owner.counts), independently of
// any Y.Doc; every tree comes from readTree() (y-prosemirror JSON validated by the ProseMirror schema).

import assert from 'node:assert/strict'
import { fork, spawn } from 'node:child_process'
import crypto from 'node:crypto'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const sample = path.join(root, 'plan/examples/reference/live-write')
// Yjs is imported only through the sample, so one copy of the library is loaded.
const { COMMITTED_ORIGIN, LOAD_ORIGIN, Y, docFromLog, insertedBlockId, originKind, prepareOnCopy, sha256Hex, toBase64 } = await import(path.join(sample, 'room-sequencer.mjs'))
const { createPsqlOwner, psqlJson, setupDocument } = await import(path.join(sample, 'owner-psql.mjs'))
const { readTree, seedUpdate } = await import(path.join(sample, 'schema.mjs'))
const fixture = JSON.parse(readFileSync(path.join(root, 'plan/examples/live-write.json'), 'utf8'))

const step = (name, detail = '') => process.stdout.write(`OK ${name}${detail ? ` — ${detail}` : ''}\n`)
const timeline = Object.fromEntries(fixture.timeline.map((entry) => [entry.step, entry]))
const variant = (id) => fixture.independent_variants.find((entry) => entry.id === id)
const PROJECT = fixture.scope.project_id
const SUBJECT = `user/${fixture.agent_intent.effective_actor}`
const GENERATION = fixture.initial.generation

/** A room owner in its own Node process. */
function startRoom() {
  const child = fork(path.join(sample, 'room-process.mjs'), [], { stdio: ['ignore', 'inherit', 'inherit', 'ipc'] })
  const pending = new Map()
  const events = []
  let wake = []
  let next = 0
  const exited = new Promise((resolve) => child.on('exit', (code, signal) => {
    for (const { reject } of pending.values()) reject(new Error(`room process exited (${code ?? signal})`))
    pending.clear()
    resolve({ code, signal })
  }))
  child.on('message', (message) => {
    if (message.id !== undefined) {
      const waiter = pending.get(message.id)
      pending.delete(message.id)
      if (message.ok) waiter.resolve(message.result)
      else waiter.reject(new Error(message.error))

      return
    }
    events.push(message)
    const woken = wake
    wake = []
    woken.forEach((resolve) => resolve())
  })

  return {
    child,
    exited,
    call(op, args = {}) {
      const id = ++next

      return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject })
        child.send({ id, op, ...args })
      })
    },
    async event(name) {
      for (;;) {
        const found = events.find((event) => event.event === name)
        if (found) return found
        await new Promise((resolve) => wake.push(resolve))
      }
    },
  }
}

function fingerprint(body) {
  return sha256Hex(Buffer.from(JSON.stringify(body)))
}

function intentBody(intent) {
  return { generation: intent.generation, read_marker: intent.read_marker, preconditions: intent.preconditions, atomic_effects: intent.atomic_effects }
}

/** A browser's edit of one block made on its own Y.Doc synced to the confirmed state (or to an older prefix: offline). */
function browserEdit(owner, blockId, newText, throughSeq = null) {
  const { doc } = docFromLog(owner.load(null, throughSeq))
  const element = doc.getXmlFragment('default').toArray().find((node) => node.getAttribute?.('blockId') === blockId)
  const text = element.toArray()[0]
  const old = text.toString()
  let prefix = 0
  while (prefix < old.length && old[prefix] === newText[prefix]) prefix++
  let suffix = 0
  while (suffix < old.length - prefix && old[old.length - 1 - suffix] === newText[newText.length - 1 - suffix]) suffix++
  const vector = Y.encodeStateVector(doc)
  doc.transact(() => {
    text.delete(prefix, old.length - prefix - suffix)
    text.insert(prefix, newText.slice(prefix, newText.length - suffix))
  })
  const update = Y.encodeStateAsUpdate(doc, vector)
  doc.destroy()

  return update
}

function newDocument() {
  const ids = { documentId: crypto.randomUUID(), nodeId: crypto.randomUUID(), driveId: crypto.randomUUID() }
  setupDocument({ ...ids, generation: GENERATION, headSeq: fixture.initial.head_seq, fence: fixture.initial.owner_fence - 1, checkpoint: seedUpdate(fixture.initial.blocks) })

  return { ...ids, owner: createPsqlOwner(ids.documentId) }
}

function registerA42(owner, overrides = {}) {
  const intent = { ...fixture.agent_intent, ...overrides }
  const status = owner.registerIntent({ project: PROJECT, subject: SUBJECT, commandId: intent.command_id, fingerprint: fingerprint(intentBody(intent)), intent: intentBody(intent), readPosition: intent.read_marker.position })

  return { status, stored: { id: status.id, command_id: intent.command_id, generation: intent.generation, preconditions: intent.preconditions, atomic_effects: intent.atomic_effects } }
}

const texts = (tree) => tree.map((block) => block.text)
const count = (tree, blockId) => tree.filter((block) => block.blockId === blockId).length
const A42_BLOCK = insertedBlockId('A42', 1)
const [initialPayment, initialBank] = fixture.initial.blocks.map((block) => block.text)

// ── Main timeline ────────────────────────────────────────────────────────────────────────────────────
const main = newDocument()
const { owner } = main

const registered = registerA42(owner)
assert.equal(registered.status.state, 'running')
assert.equal(owner.counts().commands_running, timeline.register_intent.assert.stored_intents)
assert.equal(owner.counts().head_seq, timeline.register_intent.assert.head_seq)
step('register_intent', `command row ${registered.status.id}, head ${owner.counts().head_seq}`)

const room1 = startRoom()
await room1.event('ready')
const opened1 = await room1.call('open', { documentId: main.documentId, ttlMs: 1500 })
assert.equal(opened1.fence, fixture.initial.owner_fence)
assert.equal(opened1.appliedSeq, fixture.initial.applied_seq)
step('room process 1 opened', `pid ${room1.child.pid}, owner_fence ${opened1.fence}, applied ${opened1.appliedSeq}`)

const humanUpdate = browserEdit(owner, 'bank', timeline.accept_human_edit.new_text)
const human = await room1.call('update', { payload: toBase64(humanUpdate) })
assert.equal(human.result.seq, timeline.accept_human_edit.assert.head_seq)
assert.equal(human.appliedSeq, timeline.accept_human_edit.assert.applied_seq)
assert.equal(owner.counts().head_seq, 41)
step('accept_human_edit', `seq ${human.result.seq}, room text ${JSON.stringify(texts(human.tree))}`)

// BOUNDARY: the compaction lease PHP grants on collaboration/hub/compaction/lease (2.x columns of
// v_collaboration_heads). It is a right to read the prefix ≤ 41, not a right to write the room.
const compactor = crypto.randomUUID()
const granted = psqlJson(`WITH granted AS (UPDATE v_collaboration_heads SET lease_token = repeat('c', 64), lease_owner = :'p1',
    lease_expires_at = (clock_timestamp() AT TIME ZONE 'UTC') + interval '5 minutes', lease_through_seq = head_seq
    WHERE document_id = :'p0'::uuid RETURNING 1) SELECT to_jsonb(count(*)) FROM granted`, [main.documentId, compactor])
assert.equal(granted, 1)

// Prepare on a copy, commit, then die before the room applies the stored bytes.
const held = room1.call('command', { intent: registered.stored, holdAfterCommit: true }).catch((error) => error)
const committed = await room1.event('committed')
assert.equal(committed.status.state, 'committed')
assert.equal(committed.status.base_seq, timeline.prepare_on_copy.assert.expected_head_seq)
assert.equal(committed.status.to_seq, timeline.commit_update_receipt_and_outbox.assert.head_seq)
assert.equal(committed.appliedSeq, timeline.commit_update_receipt_and_outbox.assert.applied_seq)
assert.deepEqual(texts(committed.tree), [initialPayment, timeline.accept_human_edit.new_text], 'live room unchanged between commit and apply')
const atCommitCounts = owner.counts()
assert.equal(atCommitCounts.head_seq, 42)
assert.equal(atCommitCounts.command_receipts, timeline.commit_update_receipt_and_outbox.assert.stored_receipts)
room1.child.kill('SIGKILL')
const death = await room1.exited
assert.equal(death.signal, 'SIGKILL')
assert.match(String(await held), /room process exited/)
assert.equal(owner.counts().head_seq, timeline.crash_room_before_apply_and_drop_response.assert.durable_position)
step('commit then SIGKILL before apply', `owner head ${atCommitCounts.head_seq}, room applied ${committed.appliedSeq}, room text ${JSON.stringify(texts(committed.tree))}`)

const status = owner.status({ project: PROJECT, subject: SUBJECT, commandId: 'A42' })
assert.equal(status.state, timeline.query_status_as_authorized_actor.assert.state)
assert.equal(status.receipt_id, committed.status.receipt_id)
assert.deepEqual(status.marker, timeline.query_status_as_authorized_actor.assert.marker)
step('query_status_as_authorized_actor', `receipt ${status.receipt_id}, marker ${JSON.stringify(status.marker)}`)

// A new process; its first load is deliberately one record short (a paged load / a commit racing the load).
const room2 = startRoom()
await room2.event('ready')
const opened2 = await room2.call('open', { documentId: main.documentId, ttlMs: 60000, throughSeq: 41 })
assert.equal(opened2.fence, timeline.open_next_room_owner.owner_fence)
assert.equal(opened2.appliedSeq, 41)
assert.equal(opened2.mayEvaluate, timeline.open_next_room_owner.assert.a43_may_evaluate_against_position_41)
assert.ok((await room2.call('state')).trace.some((event) => event.type === 'lease_held'), 'the killed owner lease was waited out')
step('open_next_room_owner', `pid ${room2.child.pid}, owner_fence ${opened2.fence}, applied ${opened2.appliedSeq}, mayEvaluate ${opened2.mayEvaluate}`)

const leases = psqlJson(`SELECT jsonb_build_object('lease_owner', lease_owner, 'lease_through_seq', lease_through_seq, 'owner_fence', owner_fence)
    FROM v_collaboration_heads WHERE document_id = :'p0'::uuid`, [main.documentId])
assert.deepEqual(leases, { lease_owner: compactor, lease_through_seq: 41, owner_fence: 8 })
const compactorWrite = owner.appendUpdate({ ownerId: compactor, fence: 8, expectedHead: 42, update: { update_id: crypto.randomUUID(), sha256: sha256Hex(humanUpdate), payload: toBase64(humanUpdate) } })
assert.equal(compactorWrite.error, 'stale_owner')
step('compaction lease ≠ room writer', `writer fence 7→8 kept the compactor's prefix ≤ ${leases.lease_through_seq}; the compactor cannot append (${compactorWrite.error}); publication bounds: lease-schema.sql`)

const [outboxEvent, ...extraEvents] = owner.outbox()
assert.equal(extraEvents.length, 0)
const [record42] = owner.load(41, 42).tail
const delivery = { ...outboxEvent.payload, sha256: [record42.sha256], payload: [record42.payload] }
const firstDelivery = await room2.call('deliver', { event: delivery })
const secondDelivery = await room2.call('deliver', { event: delivery })
assert.equal(firstDelivery.result.outcome, 'applied')
assert.equal(secondDelivery.result.outcome, 'duplicate')
assert.equal(secondDelivery.appliedSeq, timeline.replay_committed_prefix.assert.applied_seq)
const afterReplay = await room2.call('state')
assert.equal(afterReplay.mayEvaluate, timeline.replay_committed_prefix.assert.a43_may_evaluate_against_position_42)
assert.equal(afterReplay.violations, 0)
step('replay_committed_prefix + outbox twice', `${firstDelivery.result.outcome} then ${secondDelivery.result.outcome}, applied ${secondDelivery.appliedSeq}`)

const again = registerA42(owner)
assert.equal(again.status.state, 'committed')
assert.equal(again.status.receipt_id, committed.status.receipt_id)
const repeated = await room2.call('command', { intent: registered.stored })
assert.equal(repeated.result.receipt_id, committed.status.receipt_id)
assert.ok((await room2.call('state')).trace.some((event) => event.type === 'already_settled'), 'repeat did not run the transformation')
const counts = owner.counts()
assert.equal(counts.head_seq, timeline.repeat_a42_and_deliver_outbox_twice.assert.head_seq)
assert.equal(counts.command_receipts, timeline.repeat_a42_and_deliver_outbox_twice.assert.stored_receipts)
assert.equal(counts.commands_committed, 1)
assert.equal(counts.outbox_events, 1)
assert.equal(counts.updates, 2)
assert.equal(counts.update_receipts, 2)
assert.equal(count(repeated.tree, A42_BLOCK), timeline.repeat_a42_and_deliver_outbox_twice.assert.inserted_paragraph_count)
step('repeat_a42_and_deliver_outbox_twice', `SQL ${JSON.stringify(counts)}`)

const atCommit = docFromLog(owner.load(null, status.marker.position))
const atCommitTree = readTree(atCommit.doc).paragraphs
const currentTree = repeated.tree
for (const tree of [atCommitTree, currentTree]) {
  assert.deepEqual(texts(tree), timeline.read_at_commit_and_current.assert.ordered_text)
  assert.equal(count(tree, A42_BLOCK), 1)
  assert.ok(tree.every((block) => block.marks.length === 0))
}
assert.equal(atCommit.position, timeline.read_at_commit_and_current.assert.marker.position)
step('read_at_commit_and_current', JSON.stringify(texts(currentTree)))

// ── Sensitivity of the main timeline ─────────────────────────────────────────────────────────────────
const tampered = Buffer.from(record42.payload, 'base64')
tampered[tampered.length - 2] ^= 0xff
const wrongBytes = await room2.call('deliver', { event: { ...delivery, payload: [tampered.toString('base64')] } })
const otherBytes = await room2.call('deliver', { event: { ...delivery, sha256: [sha256Hex(humanUpdate)], payload: [toBase64(humanUpdate)] } })
const foreign = await room2.call('deliver', { event: { ...delivery, generation: GENERATION + 1 } })
assert.equal(wrongBytes.result.outcome, 'hash_mismatch')
assert.equal(otherBytes.result.outcome, 'hash_mismatch')
assert.equal(foreign.result.outcome, 'foreign_generation')
assert.deepEqual(texts(foreign.tree), timeline.read_at_commit_and_current.assert.ordered_text)
step('sensitivity: tampered bytes / other bytes for seq 42 / old-generation event refused', `${wrongBytes.result.outcome}, ${otherBytes.result.outcome}, ${foreign.result.outcome}`)

const staleCommit = owner.settleCommit({ id: registered.stored.id, ownerId: opened1.ownerId, fence: opened1.fence, baseSeq: 42, updates: [{ update_id: crypto.randomUUID(), sha256: sha256Hex(humanUpdate), payload: toBase64(humanUpdate) }], evidence: {} })
assert.equal(staleCommit.duplicate, true, 'a settled command keeps its outcome even for a stale caller')
const probe = registerA42(owner, { command_id: 'A42-stale-probe' })
const staleNew = owner.settleCommit({ id: probe.stored.id, ownerId: opened1.ownerId, fence: opened1.fence, baseSeq: 42, updates: [{ update_id: crypto.randomUUID(), sha256: sha256Hex(humanUpdate), payload: toBase64(humanUpdate) }], evidence: {} })
const staleAppend = owner.appendUpdate({ ownerId: opened1.ownerId, fence: opened1.fence, expectedHead: 42, update: { update_id: crypto.randomUUID(), sha256: sha256Hex(humanUpdate), payload: toBase64(humanUpdate) } })
assert.equal(staleNew.error, 'stale_owner')
assert.equal(staleAppend.error, 'stale_owner')
assert.equal(owner.counts().head_seq, 42)
assert.equal(variant('stale_owner').expect.updates_accepted_from_old_fence, owner.counts().updates - 2)
step('sensitivity: owner_fence 7 after fence 8', `settle ${staleNew.error}, append ${staleAppend.error}`)

const conflict = registerA42(owner, { atomic_effects: [{ ...fixture.agent_intent.atomic_effects[0], replacement: '45' }, fixture.agent_intent.atomic_effects[1]] })
assert.equal(conflict.status.error, 'key_conflict')
assert.equal(variant('different_intent_same_key').expect.key_conflict, true)
assert.equal(owner.counts().command_receipts, variant('different_intent_same_key').expect.receipt_count)
step('sensitivity: same key, replacement 45', conflict.status.error)

// A naive implementation re-runs the transformation (like a repeated serverWrite) on its stale base 41.
const naive = prepareOnCopy({ log: owner.load(null, 41), intent: registered.stored })
assert.ok(naive.ok)
assert.notEqual(naive.sha256, record42.sha256, 'Yjs generated new bytes for the same intent')
const naiveRoom = new Y.Doc()
Y.applyUpdate(naiveRoom, Buffer.from(afterReplay.update, 'base64'))
Y.applyUpdate(naiveRoom, naive.update)
const naiveTree = readTree(naiveRoom).paragraphs
assert.equal(count(naiveTree, A42_BLOCK), 2, 'the detector sees the duplicated paragraph')
step('sensitivity: re-executed A42 duplicates the paragraph', JSON.stringify(texts(naiveTree)))

// Replay origin: the Hub appends every update whose origin it classifies as 'server'.
const kinds = []
const record42Bytes = Buffer.from(record42.payload, 'base64')
for (const origin of [COMMITTED_ORIGIN, { source: 'vaulter-server', token: null }]) {
  const replica = docFromLog(owner.load(null, 41)).doc
  replica.on('update', (_bytes, seen) => kinds.push(originKind(seen)))
  Y.applyUpdate(replica, record42Bytes, origin)
  replica.destroy()
}
const holder = new Y.Doc()
Y.applyUpdate(holder, Buffer.from(afterReplay.update, 'base64'))
holder.on('update', (_bytes, seen) => kinds.push(`held:${originKind(seen)}`))
Y.applyUpdate(holder, record42Bytes, LOAD_ORIGIN)
assert.deepEqual(kinds, ['own', 'server'], 'committed replay is own; the serverWrite origin would be appended again; a held update emits nothing')
const persistence = readFileSync(path.join(root, 'packages/collaboration/hub/src/persistence.js'), 'utf8')
for (const pinned of ['export const LOAD_ORIGIN = Object.freeze({ [OWN]: true', "if (origin && typeof origin === 'object' && origin[OWN] === true) {", "if (originKind(origin, { redisOrigins }) !== 'server') {"]) {
  assert.ok(persistence.includes(pinned), `persistence.js still contains: ${pinned}`)
}
step('replay origin', `COMMITTED_ORIGIN → ${kinds[0]} (not appended), serverWrite origin → ${kinds[1]} (Hub capture() would append a second record); re-applying held bytes emits no update`)

const released2 = await room2.call('exit')
assert.equal(released2.released, true)
await room2.exited

// ── A43 is evaluated only after the room replayed position 42 ─────────────────────────────────────────
const a43Intent = {
  command_id: 'A43', generation: GENERATION, read_marker: { generation: GENERATION, position: 42 },
  preconditions: [{ block_id: A42_BLOCK, expected_text: fixture.agent_intent.atomic_effects[1].text, expected_marks: [] }],
  atomic_effects: [{ effect: 'replace_exact', block_id: A42_BLOCK, expected: 'финансового', replacement: 'генерального' }],
}
const a43 = owner.registerIntent({ project: PROJECT, subject: SUBJECT, commandId: 'A43', fingerprint: fingerprint(intentBody(a43Intent)), intent: intentBody(a43Intent), readPosition: 42 })
const a43Stored = { id: a43.id, command_id: 'A43', generation: GENERATION, preconditions: a43Intent.preconditions, atomic_effects: a43Intent.atomic_effects }
const room3 = startRoom()
await room3.event('ready')
const opened3 = await room3.call('open', { documentId: main.documentId, ttlMs: 60000, throughSeq: 41 })
assert.equal(opened3.appliedSeq, 41)
assert.equal(opened3.fence, 9, 'a released lease is taken again with the next fence')
const atPrefix41 = prepareOnCopy({ log: owner.load(null, 41), intent: a43Stored })
assert.equal(atPrefix41.ok, false)
assert.equal(atPrefix41.reason, 'precondition_failed')
const base41 = owner.settleCommit({ id: a43.id, ownerId: opened3.ownerId, fence: opened3.fence, baseSeq: 41, updates: [{ update_id: crypto.randomUUID(), sha256: sha256Hex(humanUpdate), payload: toBase64(humanUpdate) }], evidence: {} })
assert.equal(base41.error, 'base_moved')
const a43Run = await room3.call('command', { intent: a43Stored })
assert.equal(a43Run.result.state, 'committed')
assert.equal(a43Run.result.base_seq, 42)
const trace3 = (await room3.call('state')).trace
const catchUpAt = trace3.findIndex((event) => event.type === 'catch_up' && event.from === 41 && event.to === 42)
const evaluateAt = trace3.findIndex((event) => event.type === 'evaluate' && event.command === 'A43')
assert.ok(catchUpAt >= 0 && evaluateAt > catchUpAt, 'catch-up to 42 precedes A43 evaluation')
assert.equal(trace3[evaluateAt].baseSeq, 42)
assert.deepEqual(texts(a43Run.tree), [timeline.read_at_commit_and_current.assert.ordered_text[0], 'Основание: решение генерального директора', timeline.accept_human_edit.new_text])
assert.equal(owner.counts().head_seq, 43)
step('A43 waits for position 42', `room loaded 41; trace ${JSON.stringify(trace3.filter((event) => ['catch_up', 'evaluate', 'committed'].includes(event.type)))}; at prefix 41 → ${atPrefix41.reason}, owner base 41 → ${base41.error}`)
await room3.call('exit')
await room3.exited

// ── Variant partial_patch_failure: the second effect throws on the copy ──────────────────────────────
const partial = newDocument()
const partialIntent = registerA42(partial.owner)
const room4 = startRoom()
await room4.event('ready')
await room4.call('open', { documentId: partial.documentId, ttlMs: 60000 })
const failed = await room4.call('command', { intent: partialIntent.stored, failBeforeEffect: 1 })
const expectPartial = variant('partial_patch_failure').expect
assert.equal(failed.result.outcome, 'failed_before_commit')
assert.equal(failed.tree[0].text, expectPartial.live_payment_text)
assert.equal(count(failed.tree, A42_BLOCK), expectPartial.inserted_paragraph_count)
const partialCounts = partial.owner.counts()
assert.equal(partialCounts.updates, expectPartial.accepted_agent_updates)
assert.equal(partialCounts.head_seq, 40)
assert.equal(partialCounts.commands_running, 1)
assert.equal(partialCounts.command_receipts, 0)
assert.equal(partialCounts.outbox_events, 0)
step('partial_patch_failure', `room ${JSON.stringify(texts(failed.tree))}, SQL ${JSON.stringify(partialCounts)}`)

// Without the copy the same failure leaves the first effect in the document: Y.Doc.transact is not atomic.
const direct = docFromLog(partial.owner.load()).doc
const payment = direct.getXmlFragment('default').toArray()[0].toArray()[0]
assert.throws(() => direct.transact(() => {
  payment.delete(16, 2)
  payment.insert(16, '30')
  throw new Error('second effect failed')
}))
assert.equal(readTree(direct).paragraphs[0].text, 'Аванс в течение 30 дней')
step('sensitivity: same failure applied to a live Y.Doc keeps the first effect', readTree(direct).paragraphs[0].text)

const resumed = await room4.call('command', { intent: partialIntent.stored })
assert.equal(resumed.result.state, 'committed')
assert.equal(count(resumed.tree, A42_BLOCK), 1)
step('partial_patch_failure resumed from the stored intent', `receipt ${resumed.result.receipt_id}, head ${partial.owner.counts().head_seq}`)
await room4.call('exit')
await room4.exited

// ── Variant concurrent_duplicate: two workers settle the same intent at the same moment ──────────────
// Barrier: a third connection holds the v_documents row; both workers are released only after
// pg_blocking_pids() shows both of them waiting. No timers decide the interleaving.
// The mutant reads the command state before taking the locks (the state check is not under the lock).
psqlJson(`CREATE SCHEMA IF NOT EXISTS live_write_mutant;
CREATE OR REPLACE FUNCTION live_write_mutant.settle_commit(cmd uuid, owner uuid, fence bigint, base bigint, updates jsonb, proof jsonb) RETURNS jsonb
LANGUAGE plpgsql AS $$
DECLARE c v_collaboration_commands%ROWTYPE; doc uuid; h v_collaboration_heads; item jsonb; last_seq bigint; receipt uuid := uuidv7();
BEGIN
    SELECT * INTO STRICT c FROM v_collaboration_commands WHERE id = cmd;            -- unlocked read
    IF c.state <> 'running' THEN RETURN live_write_owner.command_json(c) || jsonb_build_object('duplicate', true); END IF;
    doc := c.document_id;
    h := live_write_owner.writer_head(doc, owner, fence);
    IF h.document_id IS NULL THEN RETURN jsonb_build_object('error', 'stale_owner'); END IF;
    IF h.head_seq <> base THEN RETURN jsonb_build_object('error', 'base_moved', 'head_seq', h.head_seq); END IF;
    last_seq := base;
    FOR item IN SELECT value FROM jsonb_array_elements(updates) LOOP
        last_seq := live_write_owner.put_update(h, item->>'update_id', decode(item->>'payload', 'base64'), item->>'sha256');
        h.head_seq := last_seq;
    END LOOP;
    UPDATE v_collaboration_commands SET state = 'committed', owner_fence = fence, base_seq = base, from_seq = base + 1, to_seq = last_seq,
        receipt_id = receipt, evidence = proof, finalized_at = CURRENT_TIMESTAMP, evidence_expires_at = CURRENT_TIMESTAMP + interval '7 days'
        WHERE id = cmd RETURNING * INTO c;
    RETURN live_write_owner.command_json(c) || jsonb_build_object('duplicate', false);
END $$;
SELECT 'null'::jsonb`)

/** psql in its own process with an open stdin: a connection whose transaction the test controls. */
function holdingSession() {
  const child = spawn(process.env.PSQL ?? 'psql', ['-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1'], { stdio: ['pipe', 'pipe', 'inherit'] })
  let buffer = ''
  let waiter = null
  child.stdout.on('data', (chunk) => {
    buffer += chunk
    if (waiter && buffer.includes('\n')) {
      const line = buffer.slice(0, buffer.indexOf('\n'))
      buffer = buffer.slice(buffer.indexOf('\n') + 1)
      const resolve = waiter
      waiter = null
      resolve(line)
    }
  })
  return {
    query(sql) {
      return new Promise((resolve) => {
        waiter = resolve
        child.stdin.write(`${sql}\n`)
      })
    },
    end() {
      child.stdin.end('COMMIT;\n')
      return new Promise((resolve) => child.on('exit', resolve))
    },
  }
}

/** One worker = one psql process = one connection, started without waiting for it. */
function asyncSettle(fn, input) {
  const args = ['-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1']
  const params = [input.id, input.ownerId, input.fence, input.baseSeq, JSON.stringify(input.updates), JSON.stringify({})]
  params.forEach((value, index) => args.push('-v', `p${index}=${value}`))
  const child = spawn(process.env.PSQL ?? 'psql', args, { stdio: ['pipe', 'pipe', 'inherit'] })
  let out = ''
  child.stdout.on('data', (chunk) => { out += chunk })
  child.stdin.end(`SELECT ${fn}(:'p0'::uuid, :'p1'::uuid, :'p2'::bigint, :'p3'::bigint, :'p4'::jsonb, :'p5'::jsonb) /* concurrent-settle */;\n`)
  return new Promise((resolve, reject) => child.on('exit', (code) => (code === 0 ? resolve(JSON.parse(out.trim())) : reject(new Error(`psql exited ${code}`)))))
}

async function concurrentDuplicate(fn) {
  const doc = newDocument()
  const writer = crypto.randomUUID()
  const lease = doc.owner.acquire(writer, 60000)
  const human = browserEdit(doc.owner, 'bank', timeline.accept_human_edit.new_text)
  assert.equal(doc.owner.appendUpdate({ ownerId: writer, fence: lease.fence, expectedHead: lease.head_seq, update: { update_id: crypto.randomUUID(), sha256: sha256Hex(human), payload: toBase64(human) } }).seq, 41)
  const intent = registerA42(doc.owner)
  // Each worker prepared its own copy: different Yjs bytes for the same intent.
  const prepared = [1, 2].map(() => prepareOnCopy({ log: doc.owner.load(null, 41), intent: intent.stored }))
  assert.ok(prepared.every((p) => p.ok) && prepared[0].sha256 !== prepared[1].sha256)
  const holder = holdingSession()
  await holder.query(`BEGIN; SELECT pg_backend_pid() FROM v_documents WHERE id = '${doc.documentId}' FOR UPDATE;`)
  const workers = prepared.map((p) => asyncSettle(fn, { id: intent.stored.id, ownerId: writer, fence: lease.fence, baseSeq: 41, updates: [{ update_id: crypto.randomUUID(), sha256: p.sha256, payload: toBase64(p.update) }] }))
  let polls = 0
  for (;;) {
    const blocked = psqlJson(`SELECT to_jsonb(count(*)) FROM pg_stat_activity WHERE query LIKE '%concurrent-settle%' AND cardinality(pg_blocking_pids(pid)) > 0`)
    if (blocked === 2) break
    assert.ok(++polls < 2000, 'workers never reached the lock barrier')
    await new Promise((resolve) => setImmediate(resolve))
  }
  await holder.end()
  const results = await Promise.all(workers)
  const tree = readTree(docFromLog(doc.owner.load()).doc).paragraphs
  return { results, counts: doc.owner.counts(), inserted: count(tree, A42_BLOCK) }
}

const expectDuplicate = variant('concurrent_duplicate').expect
const good = await concurrentDuplicate('live_write_owner.settle_commit')
const [first, second] = [...good.results].sort((a, b) => Number(a.duplicate) - Number(b.duplicate))
assert.equal(first.duplicate, false)
assert.equal(second.duplicate, true, 'the late worker gets the stored outcome')
assert.equal(second.receipt_id, first.receipt_id)
assert.equal(good.counts.head_seq, expectDuplicate.head_seq)
assert.equal(good.counts.command_receipts, expectDuplicate.receipt_count)
assert.equal(good.counts.outbox_events, 1)
assert.equal(good.inserted, expectDuplicate.inserted_paragraph_count)
step('concurrent_duplicate', `two workers blocked on the document row, then: duplicate=${first.duplicate}/${second.duplicate}, one receipt ${first.receipt_id}, SQL ${JSON.stringify(good.counts)}`)

const bad = await concurrentDuplicate('live_write_mutant.settle_commit')
const badErrors = bad.results.map((r) => r.error ?? (r.duplicate ? 'duplicate' : 'committed')).sort()
assert.deepEqual(badErrors, ['base_moved', 'committed'], 'the mutant loses the stored outcome for the late worker')
assert.equal(bad.counts.command_receipts, 1)
step('sensitivity: state read before the lock', `late worker gets ${badErrors[0]} instead of the original receipt (an agent would see a conflict for a committed command)`)

// ── Variants generation_restored and late_delivery_after_restore ──────────────────────────────────────
// BOUNDARY: restore of Documents (2.x) is modelled by one transaction under the same lock order:
// v_documents row → retire the head of generation 3 → open generation 4 from the restored checkpoint.
function restoreToNextGeneration(doc) {
  return psqlJson(`BEGIN;
UPDATE v_documents SET collab_epoch = collab_epoch + 1, state_version = state_version + 1 WHERE id = :'p0'::uuid;
UPDATE v_collaboration_heads SET retired_at = CURRENT_TIMESTAMP, owner_id = NULL, owner_expires_at = NULL
    WHERE document_id = :'p0'::uuid AND retired_at IS NULL;
INSERT INTO v_collaboration_heads(document_id, generation, head_seq, checkpoint_seq, owner_fence)
    SELECT d.id, d.collab_epoch, :'p2'::bigint, :'p2'::bigint, (SELECT max(owner_fence) FROM v_collaboration_heads WHERE document_id = d.id)
    FROM v_documents d WHERE d.id = :'p0'::uuid;
UPDATE v_document_sync_states SET collab_state = decode(:'p1', 'base64'), collab_state_size = octet_length(decode(:'p1', 'base64'))
    WHERE document_id = :'p0'::uuid;
COMMIT;
SELECT live_write_owner.head(:'p0'::uuid)`, [doc.documentId, Buffer.from(seedUpdate(fixture.initial.blocks)).toString('base64'), fixture.initial.head_seq])
}
const writesIn = (doc, generation) => psqlJson(`SELECT to_jsonb(count(*)) FROM v_collaboration_updates WHERE document_id = :'p0'::uuid AND generation = :'p1'::bigint`, [doc.documentId, generation])
// The mutant is the owner's own settle_commit without the generation check, derived from its definition.
psqlJson(`DO $do$ BEGIN EXECUTE replace(replace(pg_get_functiondef('live_write_owner.settle_commit'::regproc),
    'FUNCTION live_write_owner.settle_commit', 'FUNCTION live_write_mutant.settle_commit_nogen'),
    'IF c.generation <> h.generation THEN RETURN jsonb_build_object(''error'', ''generation_changed''); END IF;', ''); END $do$;
SELECT to_jsonb(pg_get_functiondef('live_write_mutant.settle_commit_nogen'::regproc) NOT LIKE '%generation_changed%')`)

function generationRestored(settleFn) {
  const doc = newDocument()
  const oldWriter = crypto.randomUUID()
  const lease = doc.owner.acquire(oldWriter, 60000)
  const human = browserEdit(doc.owner, 'bank', timeline.accept_human_edit.new_text)
  doc.owner.appendUpdate({ ownerId: oldWriter, fence: lease.fence, expectedHead: lease.head_seq, update: { update_id: crypto.randomUUID(), sha256: sha256Hex(human), payload: toBase64(human) } })
  const intent = registerA42(doc.owner)
  const prepared = prepareOnCopy({ log: doc.owner.load(null, 41), intent: intent.stored })
  assert.ok(prepared.ok)
  const restored = restoreToNextGeneration(doc)
  assert.equal(restored.generation, GENERATION + 1)
  // The writer of generation 3 finishes its prepared copy after the restore.
  const late = doc.owner.settleCommit({ id: intent.stored.id, ownerId: oldWriter, fence: lease.fence, baseSeq: 41, updates: [{ update_id: crypto.randomUUID(), sha256: prepared.sha256, payload: toBase64(prepared.update) }], evidence: {} })
  // The room owner of generation 4 picks the same stored intent up and prepares it on the restored text.
  const newWriter = crypto.randomUUID()
  const newLease = doc.owner.acquire(newWriter, 60000)
  const again = prepareOnCopy({ log: doc.owner.load(), intent: intent.stored })
  const settled = again.ok
    ? psqlJson(`SELECT ${settleFn}(:'p0'::uuid, :'p1'::uuid, :'p2'::bigint, :'p3'::bigint, :'p4'::jsonb, '{}'::jsonb)`, [intent.stored.id, newWriter, newLease.fence, again.baseSeq,
        JSON.stringify([{ update_id: crypto.randomUUID(), sha256: again.sha256, payload: toBase64(again.update) }])])
    : { error: again.reason }
  return { late, settled, newFence: newLease.fence, writes4: writesIn(doc, GENERATION + 1), counts: doc.owner.counts() }
}

const expectRestored = variant('generation_restored').expect
const restoredGood = generationRestored('live_write_owner.settle_commit')
assert.equal(restoredGood.late.error, 'stale_owner', 'the generation-3 writer lost its head with the restore')
assert.equal(restoredGood.settled.error, 'generation_changed')
assert.equal(restoredGood.writes4, expectRestored.old_generation_writes_to_new_room)
assert.equal(restoredGood.counts.updates - 1, expectRestored.accepted_agent_updates, 'only the human update of generation 3 exists')
assert.equal(restoredGood.counts.commands_committed, 0)
step('generation_restored', `old writer → ${restoredGood.late.error}; generation-4 owner (fence ${restoredGood.newFence}) with the generation-3 intent → ${restoredGood.settled.error}; writes in generation 4: ${restoredGood.writes4}`)
const restoredBad = generationRestored('live_write_mutant.settle_commit_nogen')
assert.equal(restoredBad.settled.state, 'committed')
assert.equal(restoredBad.writes4, 1)
step('sensitivity: settle without the generation check', `the generation-3 intent is committed into generation 4 (${restoredBad.writes4} write)`)

{
  const doc = newDocument()
  const writer = crypto.randomUUID()
  const lease = doc.owner.acquire(writer, 60000)
  const human = browserEdit(doc.owner, 'bank', timeline.accept_human_edit.new_text)
  doc.owner.appendUpdate({ ownerId: writer, fence: lease.fence, expectedHead: lease.head_seq, update: { update_id: crypto.randomUUID(), sha256: sha256Hex(human), payload: toBase64(human) } })
  const intent = registerA42(doc.owner)
  const prepared = prepareOnCopy({ log: doc.owner.load(null, 41), intent: intent.stored })
  const committedA42 = doc.owner.settleCommit({ id: intent.stored.id, ownerId: writer, fence: lease.fence, baseSeq: 41, updates: [{ update_id: crypto.randomUUID(), sha256: prepared.sha256, payload: toBase64(prepared.update) }], evidence: {} })
  assert.equal(committedA42.state, 'committed')
  const [event] = doc.owner.outbox()
  const [record] = doc.owner.load(41, 42).tail
  doc.owner.release(writer, lease.fence)
  restoreToNextGeneration(doc)
  const room5 = startRoom()
  await room5.event('ready')
  const opened5 = await room5.call('open', { documentId: doc.documentId, ttlMs: 60000 })
  const late = await room5.call('deliver', { event: { ...event.payload, sha256: [record.sha256], payload: [record.payload] } })
  const expectLate = variant('late_delivery_after_restore').expect
  assert.equal(late.result.outcome, 'foreign_generation')
  assert.equal(count(late.tree, A42_BLOCK), 0)
  assert.equal(writesIn(doc, GENERATION + 1), expectLate.old_generation_writes_to_new_room)
  const historical = doc.owner.status({ project: PROJECT, subject: SUBJECT, commandId: 'A42' })
  assert.deepEqual(historical.marker, expectLate.authorized_historical_receipt_marker)
  assert.equal(historical.receipt_id, committedA42.receipt_id)
  // Sensitivity: a position-only rule ("apply when to_seq > applied") would accept this event, because
  // generation 4 restarted at 40 and position 42 lies ahead of it; only the generation tells them apart.
  assert.ok(event.payload.to_seq > opened5.appliedSeq && event.payload.generation !== GENERATION + 1)
  step('late_delivery_after_restore', `room of generation ${GENERATION + 1} (fence ${opened5.fence}, applied ${opened5.appliedSeq}) → ${late.result.outcome}, 0 writes; status marker ${JSON.stringify(historical.marker)}; a position-only rule would take to_seq ${event.payload.to_seq} > ${opened5.appliedSeq}`)
  await room5.call('exit')
  await room5.exited
}

// ── Variant offline_target_edit_arrives_later: CRDT merges, meaning does not ──────────────────────────
// The human typed "20" over "15" offline at position 40; A42 ("15" → "30") commits at 41; the offline
// update arrives at 42. Yjs keeps both insertions. The owner stored anchors around the agent's span at
// commit (effect-anchors.mjs); verify(current) resolves them in the merged state.
const { anchorEffects, checkEffects, wholeBlockEqual } = await import(path.join(sample, 'effect-anchors.mjs'))
function offlineAfterCommit(offlineText) {
  const doc = newDocument()
  const writer = crypto.randomUUID()
  const lease = doc.owner.acquire(writer, 60000)
  const offline = browserEdit(doc.owner, 'payment-terms', offlineText, 40)
  const intent = registerA42(doc.owner)
  const prepared = prepareOnCopy({ log: doc.owner.load(null, 40), intent: intent.stored })
  const settled = doc.owner.settleCommit({ id: intent.stored.id, ownerId: writer, fence: lease.fence, baseSeq: 40, updates: [{ update_id: crypto.randomUUID(), sha256: prepared.sha256, payload: toBase64(prepared.update) }], evidence: {} })
  assert.equal(settled.state, 'committed')
  const committedDoc = docFromLog(doc.owner.load(null, settled.marker.position)).doc
  const evidence = anchorEffects({ baseDoc: docFromLog(doc.owner.load(null, 40)).doc, committedDoc, commandId: 'A42', effects: intent.stored.atomic_effects, insertedBlockId })
  assert.equal(doc.owner.appendUpdate({ ownerId: writer, fence: lease.fence, expectedHead: settled.marker.position, update: { update_id: crypto.randomUUID(), sha256: sha256Hex(offline), payload: toBase64(offline) } }).seq, settled.marker.position + 1)
  const current = docFromLog(doc.owner.load()).doc
  const spanOnly = anchorEffects({ baseDoc: docFromLog(doc.owner.load(null, 40)).doc, committedDoc, commandId: 'A42', effects: intent.stored.atomic_effects, insertedBlockId, widen: 0 })
  return {
    atCommit: checkEffects(committedDoc, evidence), current: checkEffects(current, evidence), spanOnly: checkEffects(current, spanOnly)[0],
    wholeBlock: wholeBlockEqual(committedDoc, current, 'payment-terms'), text: readTree(current).paragraphs[0].text, window: evidence[0].window,
  }
}
const expectOffline = variant('offline_target_edit_arrives_later').expect
const overlap = offlineAfterCommit('Аванс в течение 20 дней')
assert.ok(overlap.atCommit.every((check) => check.passed), 'verify(at_commit) passes: the receipt itself is right')
assert.equal(expectOffline.at_commit_payment_text, 'Аванс в течение 30 дней')
assert.ok(overlap.text.includes('30') && overlap.text.includes('20'), `both insertions survive the merge: ${overlap.text}`)
const anchorOverlap = overlap.current.find((check) => check.name === 'effect_anchor')
assert.equal(anchorOverlap.passed, false)
assert.equal(overlap.wholeBlock, false)
assert.equal(overlap.current.find((check) => check.name === 'inserted_block').passed, true)
step('offline_target_edit_arrives_later', `merged text ${JSON.stringify(overlap.text)}; at_commit passed; current effect_anchor ${JSON.stringify(anchorOverlap.expected)} → ${JSON.stringify(anchorOverlap.actual)} failed`)
assert.equal(overlap.spanOnly.passed, true, 'anchors on the span alone still read "30" next to the inserted "20"')
step('sensitivity: anchors without neighbour characters', `span-only window ${JSON.stringify(overlap.spanOnly.actual)} passes although the text reads ${JSON.stringify(overlap.text)}`)
const elsewhere = offlineAfterCommit('Предоплата в течение 15 дней')
const anchorElsewhere = elsewhere.current.find((check) => check.name === 'effect_anchor')
assert.equal(elsewhere.text, 'Предоплата в течение 30 дней')
assert.equal(anchorElsewhere.passed, true, 'an edit elsewhere in the block is not an overlap')
assert.equal(elsewhere.wholeBlock, false, 'the whole-block rule raises a false alarm here')
step('sensitivity: offline edit elsewhere in the block', `${JSON.stringify(elsewhere.text)}: effect_anchor passed (window ${JSON.stringify(anchorElsewhere.actual)}), whole-block rule failed — false alarm the anchors remove`)

process.stdout.write('OK live-write: crash between commit and apply, duplicate delivery, A43 ordering, partial failure, concurrent duplicate, restore before commit, late delivery after restore and offline overlap\n')

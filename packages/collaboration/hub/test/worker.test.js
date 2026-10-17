import { test } from 'node:test'
import assert from 'node:assert/strict'
import crypto from 'node:crypto'
import * as Y from 'yjs'
import { TiptapTransformer } from '@hocuspocus/transformer'
import { CHECKPOINT_CONFLICT, EPOCH_MISMATCH, PROTOCOL_MISMATCH } from '../src/backend.js'
import { buildConfig, validateCompactConfig, validateConfig } from '../src/config.js'
import { createWorker, OUTCOME } from '../src/worker.js'
import { extensionsFor, FIELD } from '../src/schema.js'
import { problem, startMockBackend, virtualTime } from './support/mock-backend.js'
import { eventually, testConf } from './support/rooms.js'

// The compaction worker against a mock backend on a loopback port (I17 #19): a lease of a document
// no room has open is merged and published once, covering exactly (C, H]; a 409 is dropped, never
// repeated; a lease is never published after it ran out; a projection that loses an attribute is
// not published; drain requests (the backend's first entries) go first; drain() settles only on
// an empty lease answer that its own unspent leases cannot hide.

function b64(bytes) {
  return Buffer.from(bytes).toString('base64')
}

function paragraphs(...texts) {
  return { type: 'doc', content: texts.map((text) => ({ type: 'paragraph', content: [{ type: 'text', text }] })) }
}

/** A lease entry of document `document`: a checkpoint ("Stored") and one client update ("Edited"), C = 4, H = 5. */
function leaseEntry(document = '42', { attributes = {}, expiresInMs = 60_000, ...overrides } = {}) {
  const base = TiptapTransformer.toYdoc(paragraphs('Stored'), FIELD, extensionsFor('vaulter/rich-text'))
  const checkpoint = Y.encodeStateAsUpdate(base)
  base.destroy()
  const client = new Y.Doc()
  Y.applyUpdate(client, checkpoint)
  const before = Y.encodeStateVector(client)
  const paragraph = new Y.XmlElement('paragraph')

  for (const [name, value] of Object.entries(attributes)) {
    paragraph.setAttribute(name, value)
  }

  paragraph.insert(0, [new Y.XmlText('Edited')])
  client.getXmlFragment(FIELD).push([paragraph])
  const update = Y.encodeStateAsUpdate(client, before)
  client.destroy()

  return {
    document,
    generation: 3,
    lease_token: `lease-${document}`,
    lease_expires_at: new Date(Date.now() + expiresInMs).toISOString(),
    checkpoint_seq: 4,
    head_seq: 5,
    checkpoint_version: 9,
    type: 'vaulter/rich-text',
    schema_version: 1,
    checkpoint: b64(checkpoint),
    content: null,
    tail: [{ seq: 5, update_id: crypto.randomUUID(), payload: b64(update) }],
    ...overrides,
  }
}

const published = (markerCleared = true) => () => ({ status: 200, body: { checkpoint_version: 10, marker_cleared: markerCleared } })

/** The lease handler answering `answers[n - 1]` on the n-th call and `[]` after them. */
function leases(...answers) {
  return (call, n) => ({ status: 200, body: answers[n - 1] ?? [] })
}

function recordingLog() {
  const logged = { error: [], warn: [], info: [] }

  return {
    logged,
    log: {
      error: (message) => logged.error.push(message),
      warn: (message) => logged.warn.push(message),
      info: (message) => logged.info.push(message),
    },
  }
}

async function workerFor(t, handlers, { conf = {}, time = virtualTime(), events = {} } = {}) {
  const mock = await startMockBackend(handlers)
  t.after(() => mock.close())
  const { log, logged } = recordingLog()
  const worker = createWorker({
    backendOptions: mock.backendOptions,
    conf: testConf({ compactionBatch: 10, compactionIntervalMs: 1000, webhookTimeoutMs: 2000, ...conf }),
    log,
    clock: time.clock,
    sleep: time.sleep,
    random: () => 0.5,
    hubId: 'hub-test',
    events,
  })
  t.after(() => worker.stop())

  return { mock, worker, logged, time }
}

const checkpoints = (mock) => mock.calls.filter((call) => call.endpoint === 'checkpoint')
const leaseCalls = (mock) => mock.calls.filter((call) => call.endpoint === 'compaction/lease')

test('a lease of a document without an open room is merged and published once, covering (C, H]', async (t) => {
  const entry = leaseEntry('42')
  const { mock, worker } = await workerFor(t, { 'compaction/lease': leases([entry]), checkpoint: published(true) })

  const pass = await worker.runOnce()

  assert.equal(pass.ok, true)
  assert.deepEqual(pass.results.map(({ document, outcome, markerCleared }) => ({ document, outcome, markerCleared })), [{ document: '42', outcome: OUTCOME.PUBLISHED, markerCleared: true }])
  assert.equal(leaseCalls(mock).length, 1)
  assert.deepEqual({ hub_id: leaseCalls(mock)[0].body.hub_id, max: leaseCalls(mock)[0].body.max }, { hub_id: 'hub-test', max: 10 })

  const [call] = checkpoints(mock)
  assert.equal(checkpoints(mock).length, 1)
  assert.deepEqual(
    { document: call.body.document, generation: call.body.generation, lease_token: call.body.lease_token, covered_from: call.body.covered_from, covered_through: call.body.covered_through, expected_checkpoint_version: call.body.expected_checkpoint_version },
    { document: '42', generation: 3, lease_token: 'lease-42', covered_from: 4, covered_through: 5, expected_checkpoint_version: 9 },
  )

  // The state is the checkpoint with the tail applied; the content its projection.
  const merged = new Y.Doc()
  Y.applyUpdate(merged, Buffer.from(call.body.state, 'base64'))
  assert.deepEqual(TiptapTransformer.fromYdoc(merged, FIELD), call.body.content)
  assert.deepEqual(call.body.content.content.map((block) => block.content.map((node) => node.text).join('')), ['Stored', 'Edited'])
  merged.destroy()

  // The hub's own call: protocol 2, signed with the webhook secret, no room token.
  assert.equal(call.headers['x-vaulter-collab-protocol'], '2')
  assert.equal(call.headers.authorization, undefined)
  const expected = crypto.createHmac('sha256', 'test-secret').update(`${call.headers['x-vaulter-collab-timestamp']}.${call.raw}`).digest('hex')
  assert.equal(call.headers['x-vaulter-collab-signature'], expected)

  assert.equal(worker.stats().published, 1)
  assert.equal(worker.stats().markersCleared, 1)
})

test('a 409 checkpoint conflict is dropped and never repeated', async (t) => {
  const { mock, worker, logged } = await workerFor(t, { 'compaction/lease': leases([leaseEntry('42')]), checkpoint: () => problem(409, CHECKPOINT_CONFLICT) })

  const pass = await worker.runOnce()

  assert.equal(pass.results[0].outcome, OUTCOME.CONFLICT)
  assert.equal(pass.results[0].code, CHECKPOINT_CONFLICT)
  assert.equal(checkpoints(mock).length, 1)
  assert.equal(worker.stats().conflicts, 1)
  assert.equal(logged.warn.length, 1)
  assert.doesNotMatch(logged.warn[0], /lease-42/)
})

test('a 409 epoch mismatch (the generation was closed) is dropped and never repeated', async (t) => {
  const { mock, worker } = await workerFor(t, { 'compaction/lease': leases([leaseEntry('42')]), checkpoint: () => problem(409, EPOCH_MISMATCH) })

  const pass = await worker.runOnce()

  assert.equal(pass.results[0].outcome, OUTCOME.EPOCH)
  assert.equal(checkpoints(mock).length, 1)
  assert.equal(worker.stats().epochMismatches, 1)
})

test('a 5xx is repeated with the same publication while the lease holds', async (t) => {
  const { mock, worker } = await workerFor(t, {
    'compaction/lease': leases([leaseEntry('42')]),
    checkpoint: (call, n) => (n === 1 ? problem(503, 'service_unavailable') : published(true)()),
  })

  const pass = await worker.runOnce()

  assert.equal(pass.results[0].outcome, OUTCOME.PUBLISHED)
  const [first, second] = checkpoints(mock)
  assert.equal(checkpoints(mock).length, 2)
  for (const key of ['document', 'generation', 'lease_token', 'covered_from', 'covered_through', 'expected_checkpoint_version', 'state']) {
    assert.deepEqual(second.body[key], first.body[key], key)
  }
})

test('a backend that keeps failing leaves the checkpoint unpublished and reports it', async (t) => {
  const { mock, worker, logged } = await workerFor(t, { 'compaction/lease': leases([leaseEntry('42')]), checkpoint: () => problem(503, 'service_unavailable') })

  const pass = await worker.runOnce()

  assert.equal(pass.results[0].outcome, OUTCOME.UNAVAILABLE)
  assert.equal(checkpoints(mock).length, 3)
  assert.equal(worker.stats().unavailable, 1)
  assert.equal(logged.error.length, 1)
})

test('a lease that runs out before its publication is not published', async (t) => {
  // One second left on the backend's clock: less than the safety margin.
  const { mock, worker } = await workerFor(t, { 'compaction/lease': leases([leaseEntry('42', { expiresInMs: 1000 })]), checkpoint: published(true) })

  const pass = await worker.runOnce()

  assert.equal(pass.results[0].outcome, OUTCOME.EXPIRED)
  assert.equal(checkpoints(mock).length, 0)
  assert.equal(worker.stats().expired, 1)
})

test('a projection that would lose an attribute is not published; the error is logged and counted', async (t) => {
  const { mock, worker, logged } = await workerFor(t, {
    'compaction/lease': leases([leaseEntry('42', { attributes: { 'data-secret': 'keep-me' } })]),
    checkpoint: published(true),
  })

  const pass = await worker.runOnce()

  assert.equal(pass.results[0].outcome, OUTCOME.FAILED)
  assert.equal(pass.results[0].reason, 'projection_loss')
  assert.equal(checkpoints(mock).length, 0)
  assert.equal(worker.stats().projectionLosses, 1)
  assert.equal(worker.stats().failures, 1)
  assert.match(logged.error[0], /document 42 generation 3/)
  assert.match(logged.error[0], /paragraph\.data-secret/)
})

test('entries are compacted in the backend\'s order (drain requests first), and a full batch is leased again at once', async (t) => {
  const { mock, worker } = await workerFor(t, {
    'compaction/lease': leases([leaseEntry('drained'), leaseEntry('due')], [leaseEntry('next')]),
    checkpoint: published(true),
  }, { conf: { compactionBatch: 2, compactionIntervalMs: 60_000 } })

  worker.start()
  await eventually(() => checkpoints(mock).length === 3, 'three publications')

  assert.deepEqual(checkpoints(mock).map((call) => call.body.document), ['drained', 'due', 'next'])
  // The second call followed the full batch at once, not after the 60 s interval.
  assert.equal(leaseCalls(mock).length, 2)
  await worker.stop()
  assert.equal(worker.stats().running, false)
})

test('a failed lease call is logged once and asked again on the next pass', async (t) => {
  const { worker, logged } = await workerFor(t, { 'compaction/lease': (call, n) => (n <= 2 ? problem(503, 'service_unavailable') : { status: 200, body: [] }) })

  assert.equal((await worker.runOnce()).ok, false)
  assert.equal((await worker.runOnce()).ok, false)
  const third = await worker.runOnce()

  assert.equal(third.ok, true)
  assert.equal(third.entries, 0)
  assert.equal(logged.error.length, 1)
  assert.equal(logged.info.length, 1)
  assert.equal(worker.stats().leaseFailures, 2)
})

test('a lease answer of protocol mismatch is reported', async (t) => {
  let reported = 0
  const { worker } = await workerFor(t, { 'compaction/lease': () => problem(409, PROTOCOL_MISMATCH) }, { events: { protocolMismatch: () => { reported++ } } })

  const pass = await worker.runOnce()

  assert.equal(pass.ok, false)
  assert.equal(reported, 1)
})

test('drain() settles on an empty lease answer only once its own unspent lease ran out', async (t) => {
  const time = virtualTime()
  // The conflict leaves the lease held for its 10 s: the backend answers [] meanwhile.
  const { mock, worker } = await workerFor(t, {
    'compaction/lease': leases([leaseEntry('42', { expiresInMs: 10_000 })]),
    checkpoint: () => problem(409, CHECKPOINT_CONFLICT),
  }, { time })
  const startedAt = time.clock()

  const run = await worker.drain({ deadlineMs: 60_000 })

  assert.equal(run.drained, true)
  assert.deepEqual(run.remaining, [])
  // Not at the first empty answer: only after the lease (10 s) and its margin had passed.
  assert.ok(leaseCalls(mock).length > 2)
  assert.ok(time.clock() - startedAt >= 10_000, `settled after ${time.clock() - startedAt} ms`)
})

test('drain() settles at once on an empty answer after every marker was cleared', async (t) => {
  const { mock, worker } = await workerFor(t, { 'compaction/lease': leases([leaseEntry('42')]), checkpoint: published(true) })

  const run = await worker.drain({ deadlineMs: 60_000 })

  assert.equal(run.drained, true)
  assert.equal(run.published, 1)
  assert.equal(leaseCalls(mock).length, 2)
})

test('drain() reports the documents still pending when the deadline passes', async (t) => {
  const { worker } = await workerFor(t, {
    'compaction/lease': () => ({ status: 200, body: [leaseEntry('77')] }),
    // Edits past H keep the marker: the backend hands the document out again.
    checkpoint: published(false),
  })

  const run = await worker.drain({ deadlineMs: 5000 })

  assert.equal(run.drained, false)
  assert.deepEqual(run.remaining.map(({ document }) => document), ['77'])
  assert.equal(run.remaining[0].markerCleared, false)
})

test('the compaction interval and batch come from the hub config, and the one-shot needs the webhook secret', () => {
  const defaults = buildConfig({ NODE_ENV: 'test' })

  assert.deepEqual([defaults.compactionIntervalMs, defaults.compactionBatch], [2000, 10])
  assert.deepEqual(validateConfig(defaults), [])
  for (const value of ['0', '101', '5x']) {
    const conf = buildConfig({ NODE_ENV: 'test', VAULTER_COLLAB_COMPACTION_BATCH: value })
    assert.ok(validateConfig(conf).some((problem) => problem.includes('VAULTER_COLLAB_COMPACTION_BATCH')), value)
    assert.ok(validateCompactConfig(conf).some((problem) => problem.includes('VAULTER_COLLAB_COMPACTION_BATCH')), value)
  }
  assert.ok(validateConfig(buildConfig({ NODE_ENV: 'test', VAULTER_COLLAB_COMPACTION_INTERVAL_MS: '5' })).some((problem) => problem.includes('VAULTER_COLLAB_COMPACTION_INTERVAL_MS')))

  // The one-shot opens no WebSocket: no Origin allowlist, but a signed call needs the secret.
  const production = { NODE_ENV: 'production', VAULTER_API_URL: 'http://app/api/vaulter/v1' }
  assert.ok(validateCompactConfig(buildConfig(production)).some((problem) => problem.includes('VAULTER_COLLAB_WEBHOOK_SECRET')))
  assert.deepEqual(validateCompactConfig(buildConfig({ ...production, VAULTER_COLLAB_WEBHOOK_SECRET: 'secret' })), [])
  assert.ok(validateCompactConfig(buildConfig({ NODE_ENV: 'production', VAULTER_COLLAB_WEBHOOK_SECRET: 'secret' })).some((problem) => problem.includes('VAULTER_API_URL')))
})

test('drain() names the failed lease call when the backend never answered', async (t) => {
  const { worker } = await workerFor(t, { 'compaction/lease': () => problem(401, 'unauthenticated') })

  const run = await worker.drain({ deadlineMs: 3000 })

  assert.equal(run.drained, false)
  assert.deepEqual(run.remaining, [])
  assert.equal(run.failure.status, 401)
  assert.equal(run.failure.code, 'unauthenticated')
})

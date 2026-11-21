#!/usr/bin/env node
import { chromium } from '../lab/node_modules/playwright-core/index.mjs'
import { randomUUID } from 'node:crypto'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { decodeFrame, sanitize } from './decode-frame.mjs'

const base = new URL(process.env.TIPTAP_LAB_URL || 'http://127.0.0.1:5193')
if (!['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname)) throw new Error('Lab must use loopback')
const room = `tiptap-pro-lab-comments-versions-${randomUUID()}`
const startedAt = new Date().toISOString()
const evidence = {
  schema: 'tiptap-pro-cloud-comments-versions/v1', startedAt, room,
  target: 'Configured user-owned Cloud test environment, using newly allocated synthetic document only',
  features: ['comments', 'snapshot'], observations: [], checks: [], snapshots: [],
  limitations: ['No server process failure is injected; server retrieval is not proof of crash durability.', 'Cloud restore behavior does not replace the Vaulter generation fence contract.'],
}
let stage = 'launch'
let browser
let page
let socketSequence = 0

function endpoint(value) {
  try { const url = new URL(value); return sanitize(`${url.protocol}//${url.host}${url.pathname}`) } catch { return '[invalid endpoint]' }
}

function observe(item) {
  if (evidence.observations.length < 1200) evidence.observations.push(sanitize({ at: new Date().toISOString(), ...item }))
}

function check(name, detail) { evidence.checks.push({ name, passed: true, detail }) }

async function instrumentation(target, client) {
  target.on('response', response => observe({ kind: 'http', client, method: response.request().method(), endpoint: endpoint(response.url()), status: response.status(), resourceType: response.request().resourceType() }))
  target.on('requestfailed', request => observe({ kind: 'http-failure', client, method: request.method(), endpoint: endpoint(request.url()), failure: 'network-request-failed' }))
  target.on('pageerror', error => observe({ kind: 'page-error', client, errorClass: error.name }))
  target.on('websocket', socket => {
    const socketId = `${client}-${++socketSequence}`
    observe({ kind: 'ws-open', client, socketId, endpoint: endpoint(socket.url()) })
    for (const [event, direction] of [['framesent', 'sent'], ['framereceived', 'received']]) {
      socket.on(event, ({ payload }) => observe({ kind: 'ws-frame', client, socketId, direction, frame: decodeFrame(payload) }))
    }
    socket.on('close', () => observe({ kind: 'ws-close', client, socketId }))
    socket.on('socketerror', () => observe({ kind: 'ws-error', client, socketId, failure: 'websocket-error' }))
  })
  const cdp = await target.context().newCDPSession(target)
  await cdp.send('Network.enable')
  cdp.on('Network.webSocketHandshakeResponseReceived', event => observe({ kind: 'ws-upgrade', client, status: event.response.status }))
}

async function connect(target) {
  await target.goto(base.href, { waitUntil: 'domcontentloaded', timeout: 20_000 })
  await target.waitForFunction(() => Boolean(window.lab?.createThread && window.lab?.saveVersion), undefined, { timeout: 10_000 })
  await target.evaluate(({ room, features }) => window.lab.connect({ mode: 'cloud', room, features }), { room, features: evidence.features })
  await target.waitForFunction(() => window.lab.states.length === 2 && window.lab.states.every(state => state.authenticated && state.synced && state.status === 'connected'), undefined, { timeout: 30_000 })
}

async function snapshot(label, target = page) {
  const data = await target.evaluate(() => ({
    editor: window.lab.snapshot(),
    threads: [0, 1].map(index => window.lab.threads(index, { types: ['archived', 'unarchived'] })),
    versions: [0, 1].map(index => window.lab.versions(index)),
    sharedData: window.lab.docs.map(doc => [...doc.share].map(([name, type]) => ({ name, type: type.constructor.name, value: type.toJSON() }))),
  }))
  evidence.snapshots.push(sanitize({ label, ...data }))
  return data
}

async function waitIdle(target = page) {
  await target.waitForFunction(() => window.lab.states.every(state => state.unsynced === 0), undefined, { timeout: 15_000 })
}

async function saveVersion(name) {
  await page.evaluate(name => window.lab.saveVersion(0, name, { force: true, meta: { source: 'vaulter-cloud-comments-versions-lab' } }), name)
  await page.waitForFunction(name => window.lab.versions(0).some(version => version.name === name), name, { timeout: 15_000 })
  return page.evaluate(name => window.lab.versions(0).find(version => version.name === name), name)
}

try {
  browser = await chromium.launch({ channel: 'chrome', headless: true })
  let context = await browser.newContext()
  page = await context.newPage()
  page.setDefaultTimeout(12_000)
  await instrumentation(page, 'primary')
  stage = 'connect-two-cloud-clients'
  await connect(page)
  check('two-clients-authenticated-and-synced', 'Alice and Bob have independent providers and Y.Doc instances.')

  stage = 'seed-p42-with-bold-text'
  await page.evaluate(() => window.lab.editors[0].commands.setContent({ type: 'doc', content: [
    { type: 'paragraph', attrs: { blockId: 'p42' }, content: [{ type: 'text', text: 'Payment within ' }, { type: 'text', text: '15', marks: [{ type: 'bold' }] }, { type: 'text', text: ' working days.' }] },
    { type: 'paragraph', attrs: { blockId: 'p71' }, content: [{ type: 'text', text: 'Independent clause stays unchanged.' }] },
  ] }))
  await page.waitForFunction(() => window.lab.editors.every(editor => editor.getText().includes('15 working days.')), undefined, { timeout: 15_000 })
  await waitIdle()
  check('seed-converged', 'p42 and p71 exist on both clients.')

  stage = 'create-thread-at-payment-number'
  const created = await page.evaluate(() => {
    let range
    window.lab.editors[0].state.doc.descendants((node, position) => { if (node.isText && node.text === '15') range = { from: position, to: position + 2 } })
    if (!range) return { ok: false, reason: 'seed_range_not_found' }
    return window.lab.createThread(0, { ...range, content: 'Synthetic review: confirm the payment term.', data: { source: 'vaulter-lab' } })
  })
  if (!created.ok || !created.threadId) throw new Error('thread_creation_failed')
  const threadId = created.threadId
  evidence.threadId = threadId
  await page.waitForFunction(id => window.lab.threads(1).some(thread => thread.id === id), threadId, { timeout: 15_000 })
  check('thread-replicated', 'Bob reads the thread and its current text ranges.')
  await snapshot('thread-created')

  stage = 'bob-reply'
  await page.evaluate(id => window.lab.reply(1, id, 'Synthetic Bob reply: term reviewed.'), threadId)
  await page.waitForFunction(id => window.lab.threads(0).some(thread => thread.id === id && thread.comments.length === 2), threadId, { timeout: 15_000 })
  await waitIdle()
  check('reply-replicated', 'Both comment IDs are visible to Alice.')

  stage = 'save-baseline-version'
  const baseline = await saveVersion('Vaulter lab baseline 15 days')
  evidence.baselineVersion = baseline
  check('baseline-version-created', `Version ${baseline.version} has the expected name.`)

  stage = 'resolve-and-edit-live-document'
  await page.evaluate(id => window.lab.resolveThread(1, id), threadId)
  await page.waitForFunction(id => Boolean(window.lab.threads(0).find(thread => thread.id === id)?.resolvedAt), threadId, { timeout: 15_000 })
  await page.evaluate(() => {
    const editor = window.lab.editors[0]
    let target
    editor.state.doc.descendants((node, position) => { if (node.isText && node.text === '15') target = { from: position, to: position + 2, marks: node.marks.map(mark => mark.toJSON()) } })
    if (!target) throw new Error('range_missing')
    editor.commands.insertContentAt({ from: target.from, to: target.to }, { type: 'text', text: '30', marks: target.marks })
  })
  await page.waitForFunction(() => window.lab.editors.every(editor => editor.getText().includes('30 working days.')), undefined, { timeout: 15_000 })
  await waitIdle()
  check('resolve-and-text-change-replicated', 'Discussion is resolved; p42 says30; p71 remains unchanged.')
  const changed = await saveVersion('Vaulter lab changed 30 days')
  evidence.changedVersion = changed
  await snapshot('after-30-and-resolve')

  stage = 'preview-baseline-without-changing-live-document'
  await page.evaluate(version => window.lab.previewVersion(0, version), baseline.version)
  await page.waitForFunction(() => window.lab.previewContent(0) !== null, undefined, { timeout: 15_000 })
  const preview = await page.evaluate(() => ({ content: window.lab.previewContent(0), current: window.lab.editors.map(editor => editor.getText()) }))
  evidence.preview = sanitize(preview)
  if (!JSON.stringify(preview.content).includes('15') || !preview.current.every(text => text.includes('30 working days.'))) throw new Error('preview_semantics_mismatch')
  check('preview-isolated', 'Baseline preview contains15; both live editors still contain30.')

  stage = 'restore-baseline'
  await page.evaluate(version => window.lab.revertVersion(0, version, { currentVersionName: 'Vaulter lab before restore', newVersionName: 'Vaulter lab restored baseline' }), baseline.version)
  await page.waitForFunction(() => window.lab.editors.every(editor => editor.getText().includes('15 working days.')), undefined, { timeout: 15_000 })
  await waitIdle()
  const restored = await snapshot('after-restore')
  check('restore-text-converged', 'Both clients show15 working days again.')
  evidence.discussionStateAfterRestore = restored.threads.map(threads => {
    const thread = threads.find(thread => thread.id === threadId)
    return { present: Boolean(thread), resolved: Boolean(thread?.resolvedAt), commentCount: thread?.comments.length, ranges: thread?.ranges }
  })
  check('discussion-state-observed-after-restore', 'The evidence records discussion state separately from the restored text.')

  stage = 'fresh-context-server-retrieval'
  await context.close()
  context = await browser.newContext()
  page = await context.newPage()
  await instrumentation(page, 'fresh')
  await connect(page)
  await page.waitForFunction(id => window.lab.editors.every(editor => editor.getText().includes('15 working days.')) && window.lab.threads(0).some(thread => thread.id === id && thread.comments.length === 2), threadId, { timeout: 15_000 })
  await snapshot('fresh-context')
  check('fresh-context-content-and-discussion', 'New browser context reads restored content and the existing reply from Cloud.')
  evidence.status = 'passed'
} catch (error) {
  evidence.status = evidence.checks.length ? 'partial' : 'blocked'
  evidence.failure = { stage, errorClass: error.name, rawMessage: 'omitted' }
  if (page && !page.isClosed()) {
    try { observe({ kind: 'failure-state', state: await page.evaluate(() => ({ snapshot: window.lab?.snapshot(), events: window.lab?.events, error: document.querySelector('#error')?.textContent })) }) } catch {}
  }
  process.exitCode = 1
} finally {
  await browser?.close()
  evidence.finishedAt = new Date().toISOString()
  evidence.frameSummary = evidence.observations.filter(item => item.kind === 'ws-frame').reduce((summary, item) => {
    const name = `${item.direction}:${item.frame.typeName || item.frame.status}${item.frame.syncName ? `:${item.frame.syncName}` : ''}`
    summary[name] = (summary[name] || 0) + 1
    return summary
  }, {})
  const directory = fileURLToPath(new URL('../results/', import.meta.url))
  await mkdir(directory, { recursive: true })
  const output = `${directory}/cloud-comments-versions-${startedAt.replace(/[:.]/g, '-')}.json`
  await writeFile(output, `${JSON.stringify(sanitize(evidence), null, 2)}\n`)
  console.log(JSON.stringify({ status: evidence.status, stage, checks: evidence.checks.map(item => item.name), frameSummary: evidence.frameSummary, evidence: output }, null, 2))
}

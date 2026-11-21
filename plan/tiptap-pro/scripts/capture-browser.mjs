import { chromium } from '../lab/node_modules/@playwright/test/index.mjs'
import { createHash, randomUUID } from 'node:crypto'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { decodeFrame, sanitize } from './decode-frame.mjs'

const args = Object.fromEntries(process.argv.slice(2).map(value => value.replace(/^--/, '').split('=')))
const mode = args.mode ?? 'local'
if (!['local', 'cloud'].includes(mode)) throw new Error('Use --mode=local or --mode=cloud')
const base = new URL(args.url ?? 'http://127.0.0.1:5193')
if (!['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname)) throw new Error('Lab URL must use loopback')
const room = `tiptap-pro-lab-capture-${randomUUID()}`
const evidence = {
  startedAt: new Date().toISOString(), mode, room,
  observations: [], checks: [], limitations: [
    'WebSocket acknowledgements do not establish durable backend storage.',
    'A fresh client proves server retrieval while the test server process remains alive.',
    'Only synthetic lab documents and text are used. No raw auth frames or HTTP bodies are recorded.',
  ],
}
const monotonicStart = performance.now()
let socketSequence = 0
const observe = value => evidence.observations.push(sanitize({ at: new Date().toISOString(), elapsedMs: performance.now() - monotonicStart, ...value }))
const check = (name, detail) => evidence.checks.push({ name, passed: true, detail })
const digest = value => createHash('sha256').update(value).digest('hex')

async function capture(page, client) {
  const cdp = await page.context().newCDPSession(page)
  await cdp.send('Network.enable')
  cdp.on('Network.webSocketHandshakeResponseReceived', event => observe({
    client, kind: 'ws-upgrade', requestId: event.requestId, status: event.response.status,
  }))
  page.on('request', req => observe({ client, kind: 'http-request', method: req.method(), url: req.url() }))
  page.on('response', res => observe({ client, kind: 'http-response', status: res.status(), url: res.url() }))
  page.on('requestfailed', req => observe({ client, kind: 'http-error', url: req.url(), error: req.failure()?.errorText }))
  page.on('pageerror', error => observe({ client, kind: 'page-error', message: error.message }))
  page.on('console', message => { if (['error', 'warning'].includes(message.type())) observe({ client, kind: 'console', level: message.type(), message: message.text() }) })
  page.on('websocket', socket => {
    const socketId = `${client}-${++socketSequence}`
    observe({ client, socketId, kind: 'ws-open', url: socket.url() })
    for (const [event, direction] of [['framesent', 'sent'], ['framereceived', 'received']]) {
      socket.on(event, ({ payload }) => {
        const bytes = Buffer.isBuffer(payload) ? payload : Buffer.from(payload)
        observe({ client, socketId, kind: 'ws-frame', direction, frame: decodeFrame(bytes) })
      })
    }
    socket.on('close', () => observe({ client, socketId, kind: 'ws-close', url: socket.url() }))
    socket.on('socketerror', error => observe({ client, socketId, kind: 'ws-error', message: String(error) }))
  })
}

async function waitReady(page) {
  await page.waitForFunction(() => window.lab?.states.length === 2 && window.lab.states.every(
    state => state.authenticated && state.synced && state.status === 'connected',
  ), undefined, { timeout: 30_000 })
}

async function waitText(page, expected) {
  await page.waitForFunction(text => window.lab.editors.every(editor => editor.getText() === text), expected, { timeout: 15_000 })
}

async function snapshot(page, label) {
  const state = await page.evaluate(() => ({
    states: window.lab.states,
    documents: window.lab.editors.map(editor => editor.getJSON()),
    sharedTypes: window.lab.docs.map(doc => [...doc.share].map(([name, type]) => ({ name, type: type.constructor.name }))),
  }))
  evidence.checks.push({ name: label, observation: sanitize(state), sha256: digest(JSON.stringify(state.documents)) })
}

let browser
const diagnosticPages = []
try {
  browser = await chromium.launch({ headless: true, channel: args.channel ?? process.env.PLAYWRIGHT_CHANNEL ?? 'chrome' })
  const context = await browser.newContext()
  const page = await context.newPage()
  diagnosticPages.push({ page, name: 'primary' })
  await capture(page, 'primary')
  await page.goto(base.href)
  await page.waitForFunction(() => Boolean(window.lab))
  await page.evaluate(({ mode, room }) => window.lab.connect({ mode, room }), { mode, room })
  await waitReady(page)
  check('two-clients-connected', 'Two independent Y.Doc and provider instances are authenticated and synced.')

  const initial = 'Synthetic contract: payment in 15 days.'
  await page.evaluate(text => window.lab.setText(0, text), initial)
  await waitText(page, initial)
  check('initial-convergence', initial)
  await snapshot(page, 'after-initial-edit')

  await page.evaluate(() => window.lab.disconnect(1))
  await page.waitForFunction(() => window.lab.states[1].status !== 'connected')
  await page.evaluate(() => {
    window.lab.appendText(0, ' Online change.')
    window.lab.appendText(1, ' Offline change.')
  })
  await page.evaluate(() => window.lab.reconnect(1))
  await waitReady(page)
  await page.waitForFunction(() => {
    const [a, b] = window.lab.editors.map(editor => editor.getText())
    return a === b && a.includes('Online change.') && a.includes('Offline change.')
  }, undefined, { timeout: 15_000 })
  check('offline-reconnect-convergence', 'Both independent edits survive reconnection.')
  await snapshot(page, 'after-reconnect')

  const text = await page.evaluate(() => window.lab.editors[0].getText())
  const freshContext = await browser.newContext()
  const fresh = await freshContext.newPage()
  diagnosticPages.push({ page: fresh, name: 'fresh-context' })
  await capture(fresh, 'fresh-context')
  await fresh.goto(base.href)
  await fresh.waitForFunction(() => Boolean(window.lab))
  await fresh.evaluate(({ mode, room }) => window.lab.connect({ mode, room }), { mode, room })
  await waitReady(fresh)
  await waitText(fresh, text)
  check('fresh-context-server-read', 'An independent browser context receives the converged document.')
  await snapshot(fresh, 'fresh-client')
  await freshContext.close()
  await context.close()
  evidence.outcome = 'passed'
} catch (error) {
  evidence.outcome = 'failed'
  evidence.error = sanitize({ name: error.name, message: error.message })
  for (const { page, name } of diagnosticPages) {
    if (page.isClosed()) continue
    try { evidence.observations.push(sanitize({ kind: 'failure-state', client: name, state: await page.evaluate(() => ({ snapshot: window.lab?.snapshot(), events: window.lab?.events, visibleError: document.querySelector('#error')?.textContent })) })) } catch {}
  }
  process.exitCode = 1
} finally {
  await browser?.close()
  evidence.finishedAt = new Date().toISOString()
  const directory = new URL('../results/', import.meta.url)
  await mkdir(directory, { recursive: true })
  const path = new URL(`browser-${mode}-${evidence.startedAt.replace(/[:.]/g, '-')}.json`, directory)
  await writeFile(path, JSON.stringify(sanitize(evidence), null, 2) + '\n')
  console.log(JSON.stringify({ outcome: evidence.outcome, mode, checks: evidence.checks.length, evidence: fileURLToPath(path) }))
}

import { chromium } from '../lab/node_modules/playwright/index.mjs'
import { randomUUID } from 'node:crypto'
import { writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { decodeFrame, sanitize } from './decode-frame.mjs'

const room = `tiptap-pro-lab-review-${randomUUID()}`
const evidence = { room, startedAt: new Date().toISOString(), checks: [], frames: [] }
const pages = []
let browser
let stage = 'connect'

const poll = { timeout: 15_000 }
const check = name => evidence.checks.push(name)

async function open() {
  const page = await browser.newPage()
  const client = pages.push(page) - 1
  const cdp = await page.context().newCDPSession(page)
  await cdp.send('Network.enable')
  cdp.on('Network.webSocketHandshakeResponseReceived', data => {
    evidence.frames.push({ client, event: 'upgrade', status: data.response.status })
  })
  page.on('websocket', socket => {
    if (!new URL(socket.url()).hostname.endsWith('.collab.tiptap.cloud')) return
    for (const [event, direction] of [['framesent', 'sent'], ['framereceived', 'received']]) {
      socket.on(event, ({ payload }) => {
        const frame = decodeFrame(Buffer.isBuffer(payload) ? payload : Buffer.from(payload))
        // Record transport metadata without persisting the underlying packets.
        const { status, typeName, syncSubtype, applied, bytes } = frame
        evidence.frames.push({ client, direction, status, typeName, syncSubtype, applied, bytes })
      })
    }
  })
  await page.goto('http://127.0.0.1:5193/', { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => Boolean(window.lab?.trackedReplace), undefined, poll)
  await page.evaluate(({ room, client }) => window.lab.connect({ mode: 'cloud', room, clientCount: 1, clientOffset: client, features: ['trackedChanges'] }), { room, client })
  await page.waitForFunction(() => window.lab.states[0]?.authenticated && window.lab.states[0]?.synced, undefined, poll)
  return page
}

async function waitText(page, expected) {
  await page.waitForFunction(text => window.lab.editors[0].getText() === text, expected, poll)
}

async function waitProposal(page) {
  const proposal = await page.waitForFunction(() => window.lab.suggestions(0)[0], undefined, poll)
  return proposal.jsonValue()
}

async function decide(page, action, id) {
  if (!await page.evaluate(({ action, id }) => window.lab[action](0, id), { action, id })) {
    throw new Error(`Review command returned false: ${action}`)
  }
}

try {
  browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' })
  const a = await open()
  const b = await open()
  check('two-independent-contexts-authenticated-and-synced')

  stage = 'seed'
  await a.evaluate(() => window.lab.setText(0, 'Payment in 15 days.'))
  await waitText(b, 'Payment in 15 days.')

  stage = 'reject-insertion'
  await a.evaluate(() => window.lab.trackedInsert(0, window.lab.editors[0].state.doc.content.size - 1, ' Rejected.'))
  const draft = await waitProposal(b)
  if (draft.type !== 'add' || draft.userId !== 'Alice') throw new Error('Expected insertion proposal by Alice')
  await decide(b, 'reject', draft.id)
  await waitText(a, 'Payment in 15 days.')
  await b.waitForFunction(() => !window.lab.suggestions(0).length, undefined, poll)
  check('remote-rejection-removed-inserted-text')

  stage = 'accept-replacement'
  await a.evaluate(() => {
    const start = 1 + window.lab.editors[0].getText().indexOf('15')
    window.lab.trackedReplace(0, start, start + 2, '30')
  })
  await b.waitForFunction(previous => window.lab.suggestions(0).some(item => item.id !== previous), draft.id, poll)
  const replacement = await waitProposal(b)
  if (replacement.type !== 'replace') throw new Error('Expected replacement proposal')
  await decide(b, 'accept', replacement.id)
  await Promise.all([waitText(a, 'Payment in 30 days.'), waitText(b, 'Payment in 30 days.')])
  for (const page of [a, b]) {
    await page.waitForFunction(() => !window.lab.suggestions(0).length && window.lab.states[0].unsynced === 0, undefined, poll)
  }
  evidence.finalDocument = await a.evaluate(() => window.lab.editors[0].getJSON())
  check('remote-acceptance-converged-and-cleared-proposal')
  evidence.status = 'passed'
} catch (error) {
  evidence.status = evidence.checks.length ? 'partial' : 'blocked'
  evidence.failure = { stage, name: error.name, message: error.message }
  evidence.clients = await Promise.all(pages.map(page => page.evaluate(() => window.lab?.states).catch(() => null)))
  process.exitCode = 1
} finally {
  await browser?.close()
  evidence.finishedAt = new Date().toISOString()
  const result = new URL(`../results/cloud-tracked-${evidence.startedAt.replace(/[:.]/g, '-')}.json`, import.meta.url)
  await writeFile(result, JSON.stringify(sanitize(evidence), null, 2) + '\n')
  console.log(JSON.stringify({ status: evidence.status, room, checks: evidence.checks, failure: evidence.failure, evidence: fileURLToPath(result) }))
}

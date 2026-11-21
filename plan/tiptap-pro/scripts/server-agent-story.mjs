import { createRequire } from 'node:module'
import { randomUUID, createHash } from 'node:crypto'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { chromium } from '../lab/node_modules/@playwright/test/index.mjs'
import { sanitize } from './decode-frame.mjs'
import { paragraph, textAt, plain, replaceExact } from './text-profile.mjs'

const require = createRequire(new URL('../lab/package.json', import.meta.url))
const { config } = require('dotenv')
const { SignJWT, importPKCS8 } = await import(require.resolve('jose'))
const Y = require('yjs')
const { yDocToProsemirrorJSON } = require('@tiptap/y-tiptap')
config({ path: fileURLToPath(new URL('../../../.env', import.meta.url)), quiet: true })
const runId = randomUUID()
const evidence = {
  schema: 'tiptap-pro-headless-agent/v1', runId, startedAt: new Date().toISOString(),
  requests: [], checks: {},
  limitations: [
    'This tests Cloud REST and Yjs feasibility, not Vaulter office.prepare/commit/status/verify.',
    'No durable business intent, command receipt, reviewer ACL, transaction fencing or logical command deduplication is provided here.',
    'Replaying identical Yjs bytes is transport idempotency, not proof that recomputing an intent is idempotent.',
    'Neighbour edits are simulated by a second independent server-side Y.Doc, not a live human browser.',
  ],
}
let browser
let token
let origin
const assert = (name, condition) => { evidence.checks[name] = Boolean(condition); if (!condition) throw new Error(`Postcondition failed: ${name}`) }
const sha256 = bytes => createHash('sha256').update(bytes).digest('hex')

async function request(document, method, body, format = 'yjs') {
  if (!/^tiptap-pro-lab-agent-[A-Za-z0-9-]+$/.test(document)) throw new Error('Research prefix required')
  const url = `${origin}/api/documents/${encodeURIComponent(document)}?format=${format}`
  const response = await fetch(url, {
    method, headers: { Authorization: `Bearer ${token}`, 'content-type': 'application/octet-stream' },
    body, redirect: 'error', signal: AbortSignal.timeout(15_000),
  })
  const bytes = Buffer.from(await response.arrayBuffer())
  evidence.requests.push({ document, method, format, status: response.status, responseBytes: bytes.length, sha256: sha256(bytes) })
  if (!response.ok) throw new Error(`Cloud returned HTTP ${response.status}`)
  return bytes
}

async function seed(name) {
  const doc = new Y.Doc()
  doc.getXmlFragment('default').insert(0, [
    paragraph('p42', [{ text: 'Срок оплаты: ' }, { text: '15', attributes: { bold: {} } }, { text: ' рабочих дней.' }]),
    paragraph('p71', [{ text: 'Реквизиты: Банк А.' }]),
  ])
  await request(name, 'POST', Y.encodeStateAsUpdate(doc))
  doc.destroy()
}

async function load(name) {
  const doc = new Y.Doc()
  Y.applyUpdate(doc, await request(name, 'GET'))
  return doc
}

try {
  for (const name of ['TIPTAP_APP_ID', 'TIPTAP_ENVIRONMENT_ID', 'TIPTAP_PRIVATE_KEY']) if (!process.env[name]) throw new Error(`Missing ${name}`)
  origin = process.env.TIPTAP_API_URL || `https://${process.env.TIPTAP_APP_ID}.collab.tiptap.cloud`
  const url = new URL(origin)
  if (url.protocol !== 'https:' || !url.hostname.endsWith('.collab.tiptap.cloud') || url.username || url.password || url.search || url.hash) throw new Error('Expected Cloud HTTPS origin')
  origin = url.origin
  token = await new SignJWT({ permissions: [{ action: 'Documents:Api:All', resource: '*' }] })
    .setProtectedHeader({ alg: 'ES256' }).setIssuer(process.env.TIPTAP_ENVIRONMENT_ID)
    .setAudience(['Documents']).setIssuedAt().setExpirationTime('5m')
    .sign(await importPKCS8(process.env.TIPTAP_PRIVATE_KEY.replace(/\\n/g, '\n'), 'ES256'))

  const success = `tiptap-pro-lab-agent-success-${runId}`
  const conflict = `tiptap-pro-lab-agent-conflict-${runId}`
  evidence.documents = { success, conflict }
  await seed(success)
  const prepared = await load(success)
  const preparedText = plain(textAt(prepared, 'p42'))
  prepared.destroy()

  const neighbour = await load(success)
  await request(success, 'PATCH', replaceExact(neighbour, 'p71', 'Банк А', 'Банк Б'))
  neighbour.destroy()

  const current = await load(success)
  assert('target-unchanged-after-neighbour-edit', plain(textAt(current, 'p42')) === preparedText)
  const agentUpdate = replaceExact(current, 'p42', '15', '30')
  evidence.agentUpdate = { bytes: agentUpdate.length, sha256: sha256(agentUpdate) }
  current.destroy()
  await request(success, 'PATCH', agentUpdate)
  await request(success, 'PATCH', agentUpdate)
  const verified = await load(success)
  const json = yDocToProsemirrorJSON(verified, 'default')
  const p42 = json.content.find(node => node.attrs?.blockId === 'p42')
  const p71 = json.content.find(node => node.attrs?.blockId === 'p71')
  assert('payment-changed-once', plain(textAt(verified, 'p42')) === 'Срок оплаты: 30 рабочих дней.')
  assert('bold-preserved', p42.content.some(node => node.text === '30' && node.marks?.some(mark => mark.type === 'bold')))
  assert('neighbour-preserved', plain(textAt(verified, 'p71')) === 'Реквизиты: Банк Б.')
  assert('stable-block-identities', p42.attrs.blockId === 'p42' && p71.attrs.blockId === 'p71')
  evidence.successDocument = json
  verified.destroy()

  await seed(conflict)
  const competing = await load(conflict)
  await request(conflict, 'PATCH', replaceExact(competing, 'p42', '15', '20'))
  competing.destroy()
  const stale = await load(conflict)
  const stateBefore = Y.encodeStateVector(stale)
  let rejected = false
  try { replaceExact(stale, 'p42', '15', '30') } catch (error) { if (error.message === 'Exact target conflict') rejected = true; else throw error }
  assert('changed-target-rejected-without-write', rejected && Buffer.from(stateBefore).equals(Buffer.from(Y.encodeStateVector(stale))))
  assert('competing-value-preserved', plain(textAt(stale, 'p42')) === 'Срок оплаты: 20 рабочих дней.')
  evidence.conflictDocument = yDocToProsemirrorJSON(stale, 'default')
  stale.destroy()
  evidence.mutationsFinishedAt = new Date().toISOString()

  if (!process.argv.includes('--server-only')) {
    browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome', headless: true })
    evidence.browserStartedAfterMutations = true
    const page = await browser.newPage()
    await page.goto('http://127.0.0.1:5193')
    await page.waitForFunction(() => Boolean(window.lab))
    await page.evaluate(room => window.lab.connect({ mode: 'cloud', room, features: [], clientCount: 1, readOnly: true }), success)
    await page.waitForFunction(() => window.lab.states[0]?.authenticated && window.lab.states[0]?.synced, undefined, { timeout: 30_000 })
    await page.waitForFunction(() => window.lab.editors.every(editor => editor.getText().includes('30') && editor.getText().includes('Банк Б')), undefined, { timeout: 15_000 })
    const ui = await page.evaluate(() => window.lab.editors.map(editor => editor.getJSON()))
    assert('fresh-browser-reads-server-agent-result', ui.every(doc => doc.content.some(node => node.attrs?.blockId === 'p42' && node.content.some(text => text.text === '30' && text.marks?.some(mark => mark.type === 'bold')))))
    evidence.freshBrowserDocuments = ui
  } else {
    evidence.browserSkipped = true
  }
  evidence.status = 'passed'
} catch (error) {
  evidence.status = 'failed'
  evidence.error = sanitize({ name: error.name, message: error.message, causeCode: error.cause?.code })
  process.exitCode = 1
} finally {
  await browser?.close()
  evidence.finishedAt = new Date().toISOString()
  const directory = new URL('../results/', import.meta.url)
  await mkdir(directory, { recursive: true })
  const result = new URL(`server-agent-${evidence.startedAt.replace(/[:.]/g, '-')}.json`, directory)
  await writeFile(result, JSON.stringify(sanitize(evidence), null, 2) + '\n')
  console.log(JSON.stringify({ status: evidence.status, checks: evidence.checks, evidence: fileURLToPath(result) }))
}

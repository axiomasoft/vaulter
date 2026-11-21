import { chromium, expect } from '../lab/node_modules/@playwright/test/index.mjs'
import { writeFile, mkdir } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { createHash } from 'node:crypto'
import { decodeFrame, sanitize } from './decode-frame.mjs'

const PREVIEW = 'https://embed-pro.tiptap.dev/preview/Experiments/TrackedChanges'
const output = fileURLToPath(new URL('../results/public-tracked-changes.json', import.meta.url))
const result = {
  runAt: new Date().toISOString(), source: PREVIEW, scope: 'official-public-demo-only',
  status: 'running', browser: null, navigation: [], network: [], websockets: [],
  console: [], steps: [], assertions: [], limitations: [
    'No user Cloud credentials or demo tokens are extracted or reused.',
    'No source or bundle content is copied. Only rendered editor state is inspected.',
    'Network deltas cover each observed action interval, not all possible delayed future traffic.',
    'Public demo local behavior does not establish hosted collaboration or durable persistence.',
  ],
}
let browser
let page
let editorFrame
let editor

function fingerprint(value) {
  return createHash('sha256').update(String(value)).digest('hex')
}

async function state() {
  return sanitize(await editor.evaluate(element => {
    const instance = element.editor
    const json = typeof instance?.getJSON === 'function' ? instance.getJSON() : null
    const nodeTypes = {}
    const syntheticLeaves = []
    const syntheticBlocks = []
    const plainText = node => node.text ?? (node.content ?? []).map(plainText).join('')
    const walk = node => {
      if (node.type === 'paragraph' && plainText(node).startsWith('ZZLAB_BASE')) syntheticBlocks.push(node)
      nodeTypes[node.type] = (nodeTypes[node.type] ?? 0) + 1
      if (typeof node.text === 'string' && node.text.includes('ZZLAB_')) syntheticLeaves.push({
        type: node.type, sentinels: node.text.match(/ZZLAB_[A-Z_]+/g) ?? [], marks: node.marks ?? [],
      })
      for (const child of node.content ?? []) walk(child)
    }
    if (json) walk(json)
    const suggestions = [...element.querySelectorAll('[data-suggestion-type]')].map(node => ({
      tag: node.tagName,
      attrs: Object.fromEntries([...node.attributes].filter(a => a.name.startsWith('data-suggestion')).map(a => [a.name, a.value])),
      text: node.closest('p,h1,h2,h3,h4,h5,h6,li')?.textContent.includes('ZZLAB_')
        ? node.textContent.slice(0, 120) : '[BASE DEMO TEXT OMITTED]',
      textLength: node.textContent.length,
    }))
    return {
      editorExposed: !!instance, jsonAvailable: !!json,
      extensions: instance?.extensionManager?.extensions?.map(extension => extension.name) ?? [],
      documentNodeTypes: nodeTypes, syntheticLeaves, syntheticBlocks,
      syntheticText: (element.textContent.match(/ZZLAB_[A-Z_]+/g) ?? []).join(' '),
      suggestionElements: suggestions, suggestionElementCount: suggestions.length,
      uniqueSuggestionCount: new Set(suggestions.map(s => s.attrs['data-suggestion-id'])).size,
      trackingButton: [...document.querySelectorAll('button')].map(b => b.textContent?.trim()).find(t => /^(Enable|Disable) Track Changes$/.test(t)),
    }
  }))
}

async function step(name, operation) {
  const networkStart = result.network.length
  const socketStart = result.websockets.reduce((n, socket) => n + socket.frames.length, 0)
  const start = Date.now()
  await operation()
  await editorFrame.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))))
  await page.waitForLoadState('networkidle', { timeout: 10000 })
  const snapshot = await state()
  result.steps.push({
    name, durationMs: Date.now() - start, snapshot,
    networkIndices: [networkStart, result.network.length],
    networkRequestsDuringAction: result.network.slice(networkStart),
    websocketFramesDuringAction: result.websockets.reduce((n, socket) => n + socket.frames.length, 0) - socketStart,
  })
  return snapshot
}

function check(name, condition) {
  result.assertions.push({ name, passed: !!condition })
  if (!condition) throw new Error(`Assertion failed: ${name}`)
}

async function tracking(enabled) {
  const action = editorFrame.getByRole('button', { name: enabled ? 'Enable Track Changes' : 'Disable Track Changes', exact: true })
  if (await action.count()) await action.click()
  await expect(editorFrame.getByRole('button', { name: enabled ? 'Disable Track Changes' : 'Enable Track Changes', exact: true })).toBeVisible()
}

async function end() {
  // Use the public Editor API exposed by the demo, avoiding DOM/PM selection drift.
  await editor.evaluate(element => new Promise(resolve => {
    element.editor.commands.focus('end')
    requestAnimationFrame(() => requestAnimationFrame(resolve))
  }))
}

async function selectEnd(length) {
  await end()
  await editor.evaluate((element, length) => {
    const end = element.editor.state.doc.content.size - 1
    element.editor.commands.setTextSelection({ from: end - length, to: end })
    element.editor.view.focus()
  }, length)
}

async function reviewAll(action) {
  await editorFrame.getByRole('button', { name: `${action} All`, exact: true }).click()
  await expect(editor.locator('[data-suggestion-type]')).toHaveCount(0)
}

try {
  browser = await chromium.launch({ channel: 'chrome', headless: true })
  result.browser = browser.version()
  const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } })
  page = await context.newPage()
  page.on('request', request => result.network.push(sanitize({
    at: new Date().toISOString(), method: request.method(), resourceType: request.resourceType(), url: request.url(),
  })))
  page.on('response', response => {
    const request = response.request()
    const record = [...result.network].reverse().find(item => item.method === request.method() && item.url === sanitize(request.url()) && item.status === undefined)
    if (record) record.status = response.status()
  })
  page.on('console', message => {
    if (message.type() === 'error') result.console.push(sanitize({ type: 'error', location: message.location().url, messageSha256: fingerprint(message.text()) }))
  })
  page.on('pageerror', error => result.console.push({ type: 'pageerror', errorName: error.name, messageSha256: fingerprint(error.message) }))
  page.on('websocket', socket => {
    const record = { url: sanitize(socket.url()), frames: [] }
    result.websockets.push(record)
    for (const [event, direction] of [['framesent', 'sent'], ['framereceived', 'received']]) {
      socket.on(event, ({ payload }) => record.frames.push({
        direction, at: new Date().toISOString(),
        summary: typeof payload === 'string'
          ? { kind: 'text', bytes: Buffer.byteLength(payload), sha256: fingerprint(payload) }
          : decodeFrame(payload),
      }))
    }
  })
  const response = await page.goto(PREVIEW, { waitUntil: 'networkidle', timeout: 60000 })
  result.navigation.push({ url: sanitize(page.url()), status: response?.status() })
  const iframe = page.locator('iframe').first()
  await expect(iframe).toBeAttached({ timeout: 20000 })
  editorFrame = await (await iframe.elementHandle()).contentFrame()
  editor = editorFrame.locator('.tiptap.ProseMirror[contenteditable="true"]')
  await expect(editor).toBeVisible({ timeout: 30000 })
  result.editorURL = sanitize(editorFrame.url())
  result.initial = await state()
  await step('clear-initial-demo-suggestions', () => reviewAll('Reject'))
  const baseline = await step('create-untracked-synthetic-baseline', async () => {
    await tracking(false)
    await editor.evaluate(element => element.editor.commands.insertContentAt(element.editor.state.doc.content.size, {
      type: 'paragraph', content: [{ type: 'text', text: 'ZZLAB_BASE alpha beta omega' }],
    }))
    await end()
    await tracking(true)
  })
  check('isolated-synthetic-paragraph-created', baseline.syntheticBlocks.length === 1 && baseline.suggestionElementCount === 0)
  let snapshot = await step('insert-suggestion', async () => {
    await end()
    await page.keyboard.insertText(' ZZLAB_INSERT_REJECT')
  })
  check('insertion-has-add-marker', snapshot.suggestionElements.some(s => s.attrs['data-suggestion-type'] === 'add' && s.text.includes('ZZLAB_INSERT_REJECT')))
  snapshot = await step('reject-insertion', () => reviewAll('Reject'))
  check('rejected-insertion-removed', !snapshot.syntheticText.includes('ZZLAB_INSERT_REJECT'))
  await step('insert-for-accept', async () => { await end(); await page.keyboard.insertText(' ZZLAB_ACCEPTED') })
  snapshot = await step('accept-insertion', () => reviewAll('Accept'))
  check('accepted-insertion-kept-without-marker', snapshot.syntheticText.includes('ZZLAB_ACCEPTED') && snapshot.suggestionElementCount === 0)
  snapshot = await step('delete-suggestion', async () => {
    await selectEnd(' ZZLAB_ACCEPTED'.length)
    await page.keyboard.press('Backspace')
  })
  check('deletion-retains-text-under-delete-marker', snapshot.suggestionElements.some(s => s.attrs['data-suggestion-type'] === 'delete' && s.text.includes('ZZLAB_ACCEPTED')))
  snapshot = await step('reject-deletion', () => reviewAll('Reject'))
  check('rejected-deletion-restores-text', snapshot.syntheticText.includes('ZZLAB_ACCEPTED') && snapshot.suggestionElementCount === 0)
  await step('delete-for-accept', async () => { await selectEnd(' ZZLAB_ACCEPTED'.length); await page.keyboard.press('Backspace') })
  snapshot = await step('accept-deletion', () => reviewAll('Accept'))
  check('accepted-deletion-removes-text', !snapshot.syntheticText.includes('ZZLAB_ACCEPTED'))
  await step('seed-replacement-baseline', async () => {
    await tracking(false); await end(); await page.keyboard.insertText(' ZZLAB_REPLACE_OLD'); await tracking(true)
  })
  snapshot = await step('replace-suggestion', async () => {
    await selectEnd('ZZLAB_REPLACE_OLD'.length)
    await page.keyboard.insertText('ZZLAB_REPLACE_NEW')
  })
  result.replacementMarkerTypes = [...new Set(snapshot.suggestionElements.map(s => s.attrs['data-suggestion-type']))]
  check('replacement-has-linked-insertion-and-deletion',
    snapshot.suggestionElements.some(s => s.attrs['data-suggestion-type'] === 'replaceInsertion' && s.text === 'NEW') &&
    snapshot.suggestionElements.some(s => s.attrs['data-suggestion-type'] === 'replaceDeletion' && s.text === 'OLD'))
  snapshot = await step('reject-replacement', () => reviewAll('Reject'))
  check('rejected-replacement-restores-old', snapshot.syntheticText.includes('ZZLAB_REPLACE_OLD') && !snapshot.syntheticText.includes('ZZLAB_REPLACE_NEW'))
  await step('replace-for-accept', async () => {
    await selectEnd('ZZLAB_REPLACE_OLD'.length); await page.keyboard.insertText('ZZLAB_REPLACE_NEW')
  })
  snapshot = await step('accept-replacement', () => reviewAll('Accept'))
  check('accepted-replacement-keeps-new', snapshot.syntheticText.includes('ZZLAB_REPLACE_NEW') && !snapshot.syntheticText.includes('ZZLAB_REPLACE_OLD'))
  snapshot = await step('disabled-tracking-direct-edit', async () => {
    await tracking(false); await end(); await page.keyboard.insertText(' ZZLAB_UNTRACKED')
  })
  check('disabled-tracking-adds-no-marker', snapshot.syntheticText.includes('ZZLAB_UNTRACKED') && snapshot.suggestionElementCount === 0)
  result.status = 'passed'
} catch (error) {
  result.status = result.steps.length ? 'failed' : 'blocked'
  result.error = sanitize({ name: error.name, message: error.message })
  if (page) result.currentURL = sanitize(page.url())
} finally {
  await browser?.close()
  result.finishedAt = new Date().toISOString()
  await mkdir(fileURLToPath(new URL('../results/', import.meta.url)), { recursive: true })
  await writeFile(output, JSON.stringify(sanitize(result), null, 2) + '\n')
  console.log(JSON.stringify({ status: result.status, assertions: result.assertions, steps: result.steps.length, output }, null, 2))
  if (result.status !== 'passed') process.exitCode = 1
}

#!/usr/bin/env node
// Local execution only: this probe never reads .env and never calls an LLM.
import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { chromium } from '../lab/node_modules/playwright-core/index.mjs'
import { createServer } from '../lab/node_modules/vite/dist/node/index.js'
import * as generation from '../lab/node_modules/@tiptap-pro/extension-ai/dist/index.js'
import { sanitize } from './decode-frame.mjs'

const labDirectory = fileURLToPath(new URL('../lab/', import.meta.url))
const resultPath = fileURLToPath(new URL('../results/ai-toolkit-local.json', import.meta.url))
const evidence = { schema: 'tiptap-ai-local-probe/v1', startedAt: new Date().toISOString(), mode: 'local-mocked-resolvers', environmentFileRead: false, realLlmCalls: 0, checks: [], transports: [] }
let server
let browser

const fixture = String.raw`
import { Editor, Node } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { ServerAiToolkit, getEditorContext } from '@tiptap/ai-toolkit'
import Ai from '@tiptap-pro/extension-ai'
import { z } from 'zod'

const seed = { type: 'doc', content: [
  { type: 'paragraph', content: [{type:'text',text:'Payment in '},{type:'text',text:'15',marks:[{type:'bold'}]},{type:'text',text:' days.'}] },
  { type: 'paragraph', content: [{type:'text',text:'Independent clause.'}] },
] }
const ProbeBlock = Node.create({
 name:'probeBlock', group:'block', content:'inline*',
 addAttributes(){ return {label:{default:'base'},layout:{default:{width:2}}} },
 parseHTML(){return [{tag:'probe-block'}]},renderHTML({HTMLAttributes}){return ['probe-block',HTMLAttributes,0]},
 addJsonSchemaAwareness(){return {name:'Own probe block',description:'Synthetic schema-awareness fixture.',attributes:{label:z.string().optional()}}},
})
const sdkEditor = new Editor({element:document.querySelector('#sdk'),extensions:[StarterKit,ServerAiToolkit,ProbeBlock],content:seed})
const context = getEditorContext(sdkEditor,{customNodes:[{extensionName:'paragraph',name:'Own paragraph override',description:'Own fixture metadata.'}]})
const events=[]
let behavior={mode:'completion',response:'30'}
let pendingRelease
let pendingStream
const basic = new Editor({
 element:document.querySelector('#basic'),extensions:[StarterKit,Ai.configure({
  appId:'synthetic-app',token:'synthetic-noncredential',baseUrl:'https://synthetic.invalid/ai',autocompletion:false,
  aiCompletionResolver:async()=>{
   if(behavior.mode==='error')throw new Error('synthetic-resolver-error')
   if(behavior.mode==='pending')return new Promise(resolve=>{pendingRelease=resolve})
   return behavior.response
  },
  aiStreamResolver:async()=>new ReadableStream({start(controller){
    if(behavior.mode==='held-stream'){pendingStream=controller;controller.enqueue(new TextEncoder().encode('3'));return}
    const bytes=new TextEncoder().encode(behavior.response)
    for(let i=0;i<bytes.length;i+=2)controller.enqueue(bytes.slice(i,i+2))
    controller.close()
  }}),
  onLoading:({isStreaming,action})=>events.push({type:'loading',isStreaming,action}),
  onChunk:({chunk,response})=>events.push({type:'chunk',chunk,response}),
  onSuccess:({response,isStreaming})=>events.push({type:'success',response,isStreaming}),
  onError:()=>events.push({type:'error'}),
 })],content:seed,
})
function reset(mode,response='30'){
 basic.commands.setContent(seed)
 basic.commands.setTextSelection({from:12,to:14})
 events.length=0;behavior={mode,response}
}
function state(){
 const s=basic.storage.ai
 return {text:basic.getText(),json:basic.getJSON(),state:s.state,response:s.response,pastResponseCount:s.pastResponses.length,generatedRange:s.generatedWith?.range,events:[...events]}
}
window.aiProbe={sdkEditor,basic,context,reset,state,
 command(options={}){return basic.commands.aiTextPrompt({text:'Synthetic local instruction',format:'plain-text',append:false,...options})},
 accept(){return basic.commands.aiAccept()},reject(){return basic.commands.aiReject()},
 releasePending(){pendingRelease('30')},
 finishStream(){pendingStream.enqueue(new TextEncoder().encode('0'));pendingStream.close()},
 appendIndependent(){basic.commands.insertContentAt(basic.state.doc.content.size-1,' HUMAN')},
}
window.aiProbeReady=true
`

function checked(name, details) { evidence.checks.push({ name, passed: true, ...details }) }

async function transportProbes() {
  const originalFetch = globalThis.fetch
  const token = 'synthetic-local-token-not-a-real-credential'
  let responseMode = 'completion'
  globalThis.fetch = async (input, options = {}) => {
    const url = new URL(String(input))
    assert.equal(url.hostname, 'synthetic.invalid')
    const headers = new Headers(options.headers)
    const body = JSON.parse(options.body)
    evidence.transports.push({ path: url.pathname + url.search, method: options.method, verifiedAccessHeader: headers.get('Authorization') === `Bearer ${token}`, applicationIdHeader: headers.get('X-App-Id'), body: sanitize(body), signalProvided: options.signal instanceof AbortSignal })
    if (responseMode === 'error') return Response.json({ error: { status: 403, message: 'Synthetic denied' } }, { status: 403 })
    if (responseMode === 'stream') return new Response('Synthetic stream', { headers: { 'content-type': 'text/plain' } })
    return Response.json({ response: responseMode === 'image' ? 'https://synthetic.invalid/image.png' : 'Synthetic completion' })
  }
  try {
    const extensionOptions = { appId: 'synthetic-app', token, baseUrl: 'https://synthetic.invalid/v1/ai' }
    assert.equal(await generation.resolveAiCompletion({ action: 'summarize', text: 'Own synthetic text.', textOptions: { format: 'rich-text', modelName: 'synthetic-model', language: 'en' }, extensionOptions }), 'Synthetic completion')
    assert.equal(evidence.transports.at(-1).body.html, true)
    assert.equal(evidence.transports.at(-1).verifiedAccessHeader, true)
    checked('basic-completion-request', { endpoint: '/v1/ai/text/summarize', htmlFlag: true })
    responseMode = 'stream'
    const stream = await generation.resolveAiStream({ action: 'prompt', text: 'Own synthetic prompt.', textOptions: { format: 'plain-text' }, extensionOptions, aborter: new AbortController() })
    assert.equal(await new Response(stream).text(), 'Synthetic stream')
    assert.equal(evidence.transports.at(-1).body.stream, true)
    assert.equal(evidence.transports.at(-1).signalProvided, true)
    checked('basic-stream-request', { endpoint: '/v1/ai/text/prompt?stream=1', bodyStreamFlag: true })
    responseMode = 'image'
    assert.equal(await generation.resolveAiImage({ text: 'Own synthetic image prompt.', imageOptions: { size: '256x256' }, extensionOptions }), 'https://synthetic.invalid/image.png')
    checked('legacy-image-request-mocked', { endpoint: '/v1/ai/image/prompt', realImageGeneration: false })
    responseMode = 'error'
    await assert.rejects(() => generation.resolveAiCompletion({ action: 'prompt', text: 'Own input.', textOptions: {}, extensionOptions }), /403 Synthetic denied/)
    checked('basic-error-response-conversion', { syntheticStatus: 403 })
  } finally { globalThis.fetch = originalFetch }
}

try {
  for (const name of ['@tiptap/ai-toolkit', '@tiptap-pro/extension-ai']) {
    const manifest = JSON.parse(await readFile(`${labDirectory}/node_modules/${name}/package.json`, 'utf8'))
    evidence.packages ||= []
    evidence.packages.push({ name, version: manifest.version, license: manifest.license })
  }
  await transportProbes()
  const fixtureId = '\0vaulter-ai-local-probe'
  server = await createServer({
    configFile: false, root: labDirectory, appType: 'custom', logLevel: 'silent',
    cacheDir: `${labDirectory}/artifacts/ai-probe-vite`,
    optimizeDeps: { include: ['@tiptap/core', '@tiptap/starter-kit', '@tiptap/ai-toolkit', '@tiptap-pro/extension-ai', 'zod'] },
    server: { host: '127.0.0.1', port: 0, hmr: false, strictPort: false },
    plugins: [{
      name: 'vaulter-ai-local-probe',
      resolveId(id) { if (id === '/__ai_probe__.mjs') return fixtureId },
      load(id) { if (id === fixtureId) return fixture },
      configureServer(vite) {
        vite.middlewares.use((req, res, next) => {
          if (req.url !== '/') return next()
          res.setHeader('Content-Type', 'text/html')
          res.end('<!doctype html><div id="sdk"></div><div id="basic"></div><script type="module" src="/__ai_probe__.mjs"></script>')
        })
      },
    }],
  })
  await server.listen()
  browser = await chromium.launch({ channel: 'chrome', headless: true })
  const page = await browser.newPage()
  evidence.blockedExternalRequests = []
  evidence.browserErrors = []
  page.on('pageerror', error => evidence.browserErrors.push(sanitize({ name: error.name, message: error.message })))
  await page.route('**/*', route => {
    const url = new URL(route.request().url())
    if (['127.0.0.1', 'localhost'].includes(url.hostname)) return route.continue()
    evidence.blockedExternalRequests.push({ origin: url.origin, path: url.pathname })
    return route.abort()
  })
  const port = server.httpServer.address().port
  await page.goto(`http://127.0.0.1:${port}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.aiProbeReady, undefined, { timeout: 20_000 })
  const contextSummary = await page.evaluate(() => {
    const { context, sdkEditor } = window.aiProbe
    const snapshot = sdkEditor.getJSON()
    const summary = {
      propertyNames: Object.keys(context), nodes: Object.keys(context.serializedSchema.nodes), marks: Object.keys(context.serializedSchema.marks),
      itemNames: context.items.map(item => ({ extensionName: item.extensionName, name: item.name })),
      paragraphAttributes: context.serializedSchema.nodes.paragraph.attrs,
      ownNode: context.items.find(item => item.extensionName === 'probeBlock'),
      schemaContainsDomHooks: JSON.stringify(context.serializedSchema).includes('toDOM'),
      containsOwnDocumentText: JSON.stringify(context).includes('Independent clause.'),
      ownParagraphMetadata: context.items.find(item => item.extensionName === 'paragraph'),
      initialHashes: snapshot.content.map(node => node.attrs?._hash),
    }
    sdkEditor.commands.insertContentAt(2,'X')
    summary.hashesAfterEdit = sdkEditor.getJSON().content.map(node => node.attrs?._hash)
    return summary
  })
  evidence.editorContext = contextSummary
  assert.deepEqual(contextSummary.propertyNames.sort(), ['items', 'serializedSchema'])
  assert.equal(contextSummary.containsOwnDocumentText, false)
  assert.equal(contextSummary.schemaContainsDomHooks, false)
  assert.equal(contextSummary.ownParagraphMetadata.name, 'Own paragraph override')
  assert(contextSummary.initialHashes.every(hash => hash === null))
  assert(contextSummary.hashesAfterEdit.every(hash => hash === null))
  checked('server-toolkit-context-and-hash-registration', { contextExcludesDocument: true, localHashAssignment: false, customMetadataOverride: true })

  async function waitResult() { await page.waitForFunction(() => window.aiProbe.state().events.some(event => event.type === 'success' || event.type === 'error'), undefined, { timeout: 10_000 }) }
  async function capture(name) { const state = await page.evaluate(() => window.aiProbe.state()); evidence.editorRuns ||= []; evidence.editorRuns.push({ name, ...state }); return state }

  await page.evaluate(() => { window.aiProbe.reset('completion'); window.aiProbe.command() })
  await waitResult()
  const completion = await capture('completion')
  assert(completion.text.includes('Payment in 30 days.'))
  checked('completion-editor-replacement', { pastResponseCount: completion.pastResponseCount })
  await page.evaluate(() => window.aiProbe.reject())
  const rejected = await capture('reject')
  assert(rejected.text.includes('Payment in 15 days.'))
  assert.equal(rejected.pastResponseCount, 0)
  checked('basic-reject-restores-local-selection-slice', { responseHistoryCleared: true })

  await page.evaluate(() => { window.aiProbe.reset('completion', '30'); window.aiProbe.command({ insert: false }) })
  await waitResult()
  const preview = await capture('insert-false')
  assert(preview.text.includes('Payment in 15 days.'))
  assert.equal(preview.response, '30')
  await page.evaluate(() => window.aiProbe.accept())
  const accepted = await capture('accept-preview')
  assert(accepted.text.includes('Payment in 30 days.'))
  checked('basic-preview-then-accept', { previewDoesNotWrite: true })
  function hasBoldNumber(node) {
    return Boolean(node.type === 'text' && node.text?.includes('30') && node.marks?.some(mark => mark.type === 'bold')) || (node.content || []).some(hasBoldNumber)
  }
  assert.equal(hasBoldNumber(completion.json), false)
  assert.equal(hasBoldNumber(accepted.json), true)
  checked('plain-text-completion-and-preview-accept-formatting-differ', { directReplacementKeepsBold: false, previewAcceptKeepsBold: true })

  await page.evaluate(() => { window.aiProbe.reset('stream', '30 ✅'); window.aiProbe.command({ stream: true }) })
  await waitResult()
  const streamed = await capture('stream-multibyte')
  assert.equal(streamed.response, '30 ✅')
  assert(streamed.text.includes('30 ✅'))
  checked('stream-utf8-boundary-decoding', { byteChunkSize: 2, response: streamed.response })

  await page.evaluate(() => { window.aiProbe.reset('pending'); window.aiProbe.command() })
  const secondWhileLoading = await page.evaluate(() => window.aiProbe.command())
  assert.equal(secondWhileLoading, false)
  await page.evaluate(() => window.aiProbe.releasePending())
  await waitResult()
  checked('loading-guards-overlapping-commands', { secondCommandAccepted: false })

  await page.evaluate(() => { window.aiProbe.reset('held-stream'); window.aiProbe.command({ stream: true }) })
  await page.waitForFunction(() => window.aiProbe.state().text.includes('Payment in 3 days.'), undefined, { timeout: 10_000 })
  await page.evaluate(() => { window.aiProbe.appendIndependent(); window.aiProbe.finishStream() })
  await waitResult()
  const concurrent = await capture('stream-with-independent-edit')
  assert(concurrent.text.includes('Payment in 30 days.'))
  assert(concurrent.text.includes('Independent clause. HUMAN'))
  checked('stream-range-maps-independent-edit', { independentEditPreserved: true })

  await page.evaluate(() => { window.aiProbe.reset('error'); window.aiProbe.command() })
  await waitResult()
  const failed = await capture('resolver-error')
  assert.equal(failed.state, 'error')
  assert(failed.text.includes('Payment in 15 days.'))
  checked('resolver-error-is-visible-without-editor-write', {})
  assert.equal(evidence.blockedExternalRequests.length, 0)
  evidence.status = 'passed'
} catch (error) {
  evidence.status = 'failed'
  evidence.failure = sanitize({ name: error.name, message: error.message })
  process.exitCode = 1
} finally {
  await browser?.close()
  await server?.close()
  evidence.finishedAt = new Date().toISOString()
  for (const relative of [
    'node_modules/@tiptap/ai-toolkit/src/schema-awareness/get-editor-context.ts',
    'node_modules/@tiptap/ai-toolkit/src/hash-extension/server-ai-toolkit-hash-extension.ts',
    'node_modules/@tiptap-pro/extension-ai/dist/index.js',
  ]) {
    const bytes = await readFile(`${labDirectory}/${relative}`)
    evidence.sourceProvenance ||= []
    evidence.sourceProvenance.push({ path: relative, sha256: createHash('sha256').update(bytes).digest('hex'), bytes: bytes.length })
  }
  await mkdir(fileURLToPath(new URL('../results/', import.meta.url)), { recursive: true })
  await writeFile(resultPath, `${JSON.stringify(sanitize(evidence), null, 2)}\n`)
  console.log(JSON.stringify({ status: evidence.status, checks: evidence.checks.map(check => check.name), failure: evidence.failure, realLlmCalls: evidence.realLlmCalls, evidence: resultPath }, null, 2))
}

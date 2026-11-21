import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { mkdir, writeFile } from 'node:fs/promises'
import { randomUUID } from 'node:crypto'
import { sanitize } from './decode-frame.mjs'

const require = createRequire(new URL('../lab/package.json', import.meta.url))
const { TiptapCollabProvider, TiptapCollabProviderWebsocket } = require('@tiptap-pro/provider')
const Y = require('yjs')
const WS = require('ws')
const base = 'http://127.0.0.1:5193'
const output = fileURLToPath(new URL('../results/node-provider-probe.json', import.meta.url))
const result = {
  runAt: new Date().toISOString(), scope: 'Node-ws-transport; no-DOM; no-env-file-read',
  runtime: process.version, room: `tiptap-pro-lab-node-provider-${randomUUID()}`,
  status: 'running', clients: [], checks: [], issuanceHTTP: [],
}
const resources = []

function bounded(promise, milliseconds, label) {
  let timer
  const deadline = new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(`${label} deadline ${milliseconds}ms`)), milliseconds) })
  return Promise.race([promise, deadline]).finally(() => clearTimeout(timer))
}

function check(name, passed) {
  result.checks.push({ name, passed: !!passed })
  if (!passed) throw new Error(`Assertion failed: ${name}`)
}

function waitForValue(document, field, value) {
  const map = document.getMap('nodeLifecycleProbe')
  if (map.get(field) === value) return Promise.resolve()
  let onUpdate
  const changed = new Promise(resolve => {
    onUpdate = () => { if (map.get(field) === value) resolve() }
    document.on('update', onUpdate)
  })
  return bounded(changed, 5000, 'document-update').finally(() => document.off('update', onUpdate))
}

async function makeClient(appId, label) {
  const response = await fetch(`${base}/api/token`, {
    method: 'POST', headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ mode: 'cloud', room: result.room, user: label, readOnly: false }),
  })
  result.issuanceHTTP.push({ client: label, status: response.status })
  if (!response.ok) throw new Error(`Token endpoint HTTP ${response.status}`)
  const credential = (await response.json()).token
  if (typeof credential !== 'string') throw new Error('Token endpoint returned no token')
  const observation = { label, events: [] }
  result.clients.push(observation)
  const event = (name, details = {}) => observation.events.push({ name, at: Date.now(), ...details })
  let resolveReady
  let rejectReady
  const ready = new Promise((resolve, reject) => { resolveReady = resolve; rejectReady = reject })
  // Attach a rejection observer immediately while the second credential is issued.
  ready.catch(() => {})
  const websocket = new TiptapCollabProviderWebsocket({ appId, WebSocketPolyfill: WS, timeout: 5000, maxAttempts: 2 })
  const document = new Y.Doc()
  const provider = new TiptapCollabProvider({
    websocketProvider: websocket, document, name: result.room, user: label,
    token: credential,
    onSynced({ state }) { event('synced', { state }); if (state) resolveReady() },
    onAuthenticated({ scope }) { event('permission-accepted', { scope }) },
    onAuthenticationFailed() { event('permission-rejected'); rejectReady(new Error('Cloud permission rejected')) },
  })
  resources.push({ websocket, provider, document, observation })
  websocket.on('status', ({ status }) => event('status', { status }))
  websocket.on('connect', () => event('connect'))
  websocket.on('open', () => event('native-open'))
  websocket.on('disconnect', ({ event: close }) => event('disconnect', { code: close?.code }))
  websocket.on('maxAttemptsFailed', ({ error }) => {
    event('attempts-failed', { errorName: error?.name, errorCode: error?.code })
    rejectReady(new Error('Node WebSocket attempt failed'))
  })
  const native = websocket.webSocket
  if (native) {
    native.on('upgrade', response => event('upgrade', { status: response.statusCode }))
    native.on('unexpected-response', (_request, response) => event('unexpected-response', { status: response.statusCode }))
    native.on('error', error => event('native-error', { errorName: error.name, errorCode: error.code }))
  }
  provider.attach()
  return { provider, document, websocket, ready }
}

try {
  const configResponse = await fetch(`${base}/api/config`)
  if (!configResponse.ok) throw new Error(`Config endpoint HTTP ${configResponse.status}`)
  const config = await configResponse.json()
  if (!config.cloudReady || !config.appId) throw new Error('Local lab Cloud configuration is incomplete')
  const a = await makeClient(config.appId, 'node-lifecycle-a')
  const b = await makeClient(config.appId, 'node-lifecycle-b')
  await bounded(Promise.all([a.ready, b.ready]), 10000, 'two-client-sync')
  check('both-node-clients-synchronized', a.provider.isSynced && b.provider.isSynced)
  check('both-permissions-accepted', a.provider.isAuthenticated && b.provider.isAuthenticated)
  const markerA = `synthetic-node-a-${randomUUID()}`
  const deliveredToB = waitForValue(b.document, 'fromA', markerA)
  a.document.getMap('nodeLifecycleProbe').set('fromA', markerA)
  await deliveredToB
  check('a-update-reaches-b', b.document.getMap('nodeLifecycleProbe').get('fromA') === markerA)
  const markerB = `synthetic-node-b-${randomUUID()}`
  const deliveredToA = waitForValue(a.document, 'fromB', markerB)
  b.document.getMap('nodeLifecycleProbe').set('fromB', markerB)
  await deliveredToA
  check('b-update-reaches-a', a.document.getMap('nodeLifecycleProbe').get('fromB') === markerB)
  check('synthetic-maps-converged', JSON.stringify(a.document.getMap('nodeLifecycleProbe').toJSON()) === JSON.stringify(b.document.getMap('nodeLifecycleProbe').toJSON()))
  result.syntheticMaps = [a.document, b.document].map(document => document.getMap('nodeLifecycleProbe').toJSON())
  result.status = 'passed'
} catch (error) {
  result.status = 'failed-or-blocked'
  result.error = sanitize({ name: error.name, message: error.message })
} finally {
  for (const { websocket, provider, document, observation } of resources) {
    observation.final = {
      isSynced: provider.isSynced, permissionAccepted: provider.isAuthenticated, isAttached: provider.isAttached,
      status: websocket.status, nativeReadyState: websocket.webSocket?.readyState ?? null,
      shouldConnect: websocket.shouldConnect, retryTimeout: websocket.configuration.timeout,
      maxAttempts: websocket.configuration.maxAttempts, mapSize: websocket.configuration.providerMap.size,
    }
    provider.destroy()
    websocket.destroy()
    document.destroy()
  }
  result.finishedAt = new Date().toISOString()
  await mkdir(fileURLToPath(new URL('../results/', import.meta.url)), { recursive: true })
  await writeFile(output, JSON.stringify(sanitize(result), null, 2) + '\n')
  console.log(JSON.stringify({ status: result.status, checks: result.checks, clients: result.clients.map(c => ({ label: c.label, final: c.final })), error: result.error, output }, null, 2))
  if (result.status !== 'passed') process.exitCode = 1
}

import { chromium } from '../lab/node_modules/playwright/index.mjs'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { randomUUID } from 'node:crypto'
import { sanitize } from './decode-frame.mjs'

const BASE = 'http://127.0.0.1:5193'
const output = fileURLToPath(new URL('../results/provider-lifecycle.json', import.meta.url))
const result = {
  runAt: new Date().toISOString(), room: `tiptap-pro-lab-lifecycle-${randomUUID()}`,
  status: 'running', scope: 'three-provider-constructions-maximum; no-content-mutations',
  cdp: [], playwrightSockets: [], attempts: [], pageEvents: [],
}
let browser
let page

async function snapshot() {
  return page.evaluate(() => {
    const lifecycle = window.__providerLifecycle
    const id = value => {
      if (!value || typeof value !== 'object') return null
      if (!lifecycle.ids.has(value)) lifecycle.ids.set(value, ++lifecycle.nextId)
      return lifecycle.ids.get(value)
    }
    const inspect = provider => {
      const websocket = provider.configuration.websocketProvider
      return {
        providerId: id(provider), websocketId: id(websocket), nativeId: id(websocket.webSocket),
        isAttached: provider.isAttached, isSynced: provider.isSynced, permissionAccepted: provider.isAuthenticated,
        manageSocket: provider.manageSocket, status: websocket.status,
        shouldConnect: websocket.shouldConnect, nativeReadyState: websocket.webSocket?.readyState ?? null,
        autoConnect: websocket.configuration.autoConnect, retryTimeout: websocket.configuration.timeout,
        initialDelay: websocket.configuration.initialDelay, delay: websocket.configuration.delay,
        messageReconnectTimeout: websocket.configuration.messageReconnectTimeout,
        mapSize: websocket.configuration.providerMap.size,
        routedToSelf: websocket.configuration.providerMap.get(provider.effectiveName) === provider,
        hasConnectionAttempt: !!websocket.connectionAttempt,
        lastMessageReceivedNonzero: !!websocket.lastMessageReceived,
      }
    }
    return { providers: window.lab.providers.map(inspect), replacement: lifecycle.replacement ? inspect(lifecycle.replacement) : null }
  })
}

async function waitForSynced(kind) {
  const started = Date.now()
  let outcome = 'synced'
  try {
    await page.waitForFunction(kind => {
      const providers = kind === 'initial' ? window.lab.providers : [window.__providerLifecycle.replacement]
      return providers.every(p => p?.isSynced && p.isAuthenticated && p.configuration.websocketProvider.status === 'connected')
    }, kind, { timeout: 10000 })
  } catch { outcome = 'timeout-10s' }
  result.attempts.push({ kind, outcome, durationMs: Date.now() - started, snapshot: await snapshot() })
}

try {
  browser = await chromium.launch({ channel: 'chrome', headless: true })
  page = await browser.newPage()
  const cdp = await page.context().newCDPSession(page)
  await cdp.send('Network.enable')
  for (const event of ['webSocketCreated', 'webSocketWillSendHandshakeRequest', 'webSocketHandshakeResponseReceived', 'webSocketClosed', 'webSocketFrameError']) {
    cdp.on(`Network.${event}`, data => {
      // Never store CDP request headers, response headers or frame payloads.
      result.cdp.push(sanitize({
        event, at: Date.now(), requestId: data.requestId,
        ...(data.url ? { url: data.url } : {}),
        ...(data.response ? { status: data.response.status } : {}),
        ...(data.errorMessage ? { error: data.errorMessage } : {}),
      }))
    })
  }
  page.on('websocket', socket => result.playwrightSockets.push({ at: Date.now(), url: sanitize(socket.url()) }))
  page.on('framenavigated', frame => { if (frame === page.mainFrame()) result.pageEvents.push({ event: 'main-frame-navigation', at: Date.now(), url: sanitize(frame.url()) }) })
  page.on('console', message => {
    if (/^\[vite\]/.test(message.text())) result.pageEvents.push({ event: 'vite-console', at: Date.now(), text: sanitize(message.text()) })
  })
  await page.goto(BASE, { waitUntil: 'networkidle', timeout: 30000 })
  await page.waitForFunction(() => Boolean(window.lab), null, { timeout: 10000 })
  await page.evaluate(async room => {
    window.__providerLifecycle = { ids: new WeakMap(), nextId: 0, replacement: null }
    await window.lab.connect({ mode: 'cloud', room, features: [] })
  }, result.room)
  result.initialImmediate = await snapshot()
  await waitForSynced('initial')
  result.destroyAndReplace = await page.evaluate(async room => {
    const previous = window.lab.providers[1]
    const previousSocket = previous.configuration.websocketProvider
    const previousNative = previousSocket.webSocket
    const Provider = previous.constructor
    const Doc = previous.document.constructor
    const issueToken = previous.configuration.token
    const config = await fetch('/api/config').then(response => response.json())
    previous.destroy()
    const afterDestroy = {
      attached: previous.isAttached, shouldConnect: previousSocket.shouldConnect,
      nativeCleared: previousSocket.webSocket === null, mapSize: previousSocket.configuration.providerMap.size,
    }
    const document = new Doc()
    const replacement = new Provider({ appId: config.appId, name: room, document, token: issueToken })
    window.__providerLifecycle.replacement = replacement
    return {
      afterDestroy,
      differentSocketObject: replacement.configuration.websocketProvider !== previousSocket,
      differentNativeObject: replacement.configuration.websocketProvider.webSocket !== previousNative,
      explicitOldSocketPassed: false,
    }
  }, result.room)
  result.replacementImmediate = await snapshot()
  await waitForSynced('replacement')
  result.status = result.attempts.every(attempt => attempt.outcome === 'synced') ? 'passed-on-this-run' : 'pending-handshake-or-sync-observed'
} catch (error) {
  result.status = 'diagnostic-error'
  result.error = sanitize({ name: error.name, message: error.message })
} finally {
  if (page) {
    await page.evaluate(() => window.__providerLifecycle?.replacement?.destroy()).catch(() => {})
  }
  await browser?.close()
  result.finishedAt = new Date().toISOString()
  await mkdir(fileURLToPath(new URL('../results/', import.meta.url)), { recursive: true })
  await writeFile(output, JSON.stringify(sanitize(result), null, 2) + '\n')
  console.log(JSON.stringify({ status: result.status, attempts: result.attempts.map(({ kind, outcome, durationMs }) => ({ kind, outcome, durationMs })), destroyAndReplace: result.destroyAndReplace, output }, null, 2))
  if (result.status === 'diagnostic-error') process.exitCode = 1
}

import http from 'node:http'
import { fileURLToPath } from 'node:url'
import process from 'node:process'
import { config as loadEnv } from 'dotenv'
import { generateKeyPair, importPKCS8, jwtVerify, SignJWT } from 'jose'
import { Server } from '@hocuspocus/server'
import * as Y from 'yjs'

loadEnv({ path: fileURLToPath(new URL('../../../.env', import.meta.url)), quiet: true })
const apiPort = Number(process.env.TIPTAP_LAB_API_PORT || 5194)
const localPort = Number(process.env.TIPTAP_LAB_LOCAL_PORT || 5195)
const environmentId = process.env.TIPTAP_ENVIRONMENT_ID || ''
const appId = process.env.TIPTAP_APP_ID || ''
const cloudUrl = process.env.TIPTAP_WS_URL || (appId ? `wss://${appId}.collab.tiptap.cloud` : '')
const cloudReady = Boolean(environmentId && cloudUrl && process.env.TIPTAP_PRIVATE_KEY)
const ephemeral = await generateKeyPair('ES256')
const localDocuments = new Map()
let cloudSigningKey

function validRoom(room) {
  return typeof room === 'string' && /^tiptap-pro-lab-[A-Za-z0-9_.-]{1,110}$/.test(room)
}

async function issueToken({ room, user, mode, action = 'Documents:Write' }) {
  if (!validRoom(room)) throw new Error('Only tiptap-pro-lab-* rooms are allowed')
  const local = mode === 'local'
  if (!local && !cloudReady) throw new Error('Cloud credentials are incomplete')
  if (!local && !cloudSigningKey) {
    cloudSigningKey = await importPKCS8(process.env.TIPTAP_PRIVATE_KEY.replace(/\\n/g, '\n'), 'ES256')
  }
  const token = await new SignJWT({ permissions: [{ action, resource: room }] })
    .setProtectedHeader({ alg: 'ES256' })
    .setIssuer(local ? 'tiptap-pro-lab-local' : environmentId)
    .setAudience(['Documents'])
    .setSubject(String(user || 'researcher').slice(0, 80))
    .setIssuedAt()
    .setExpirationTime('10m')
    .sign(local ? ephemeral.privateKey : cloudSigningKey)
  return token
}

const hocuspocus = new Server({
  name: 'tiptap-pro-lab-local',
  address: '127.0.0.1',
  port: localPort,
  quiet: true,
  debounce: 50,
  maxDebounce: 200,
  async onAuthenticate({ token, documentName, connectionConfig }) {
    if (!validRoom(documentName)) throw new Error('Research room prefix required')
    const { payload } = await jwtVerify(token, ephemeral.publicKey, { issuer: 'tiptap-pro-lab-local', audience: 'Documents', algorithms: ['ES256'] })
    const permission = payload.permissions?.find(item => item.resource === documentName)
    if (!permission || !['Documents:Read', 'Documents:Write'].includes(permission.action)) throw new Error('Permission denied')
    connectionConfig.readOnly = permission.action !== 'Documents:Write'
    return { user: payload.sub }
  },
  async onLoadDocument({ documentName, document }) {
    const state = localDocuments.get(documentName)
    if (state) Y.applyUpdate(document, state)
    return document
  },
  async onStoreDocument({ documentName, document }) {
    localDocuments.set(documentName, Y.encodeStateAsUpdate(document))
  },
})
await hocuspocus.listen()

function sendJson(res, status, data) {
  res.writeHead(status, { 'content-type': 'application/json', 'cache-control': 'no-store', 'x-content-type-options': 'nosniff' })
  res.end(JSON.stringify(data))
}

const api = http.createServer(async (req, res) => {
  // Vite proxies same-origin /api calls; do not expose this token mint to arbitrary sites.
  const origin = req.headers.origin
  if (origin && !/^http:\/\/(127\.0\.0\.1|localhost):5193$/.test(origin)) return sendJson(res, 403, { error: 'Local lab origin required' })
  const host = req.headers.host || ''
  if (![ `127.0.0.1:${apiPort}`, `localhost:${apiPort}` ].includes(host)) return sendJson(res, 403, { error: 'Local lab host required' })
  const url = new URL(req.url, `http://127.0.0.1:${apiPort}`)
  if (req.method === 'GET' && url.pathname === '/api/config') {
    return sendJson(res, 200, { environmentId, appId, cloudUrl, cloudReady, localUrl: `ws://127.0.0.1:${localPort}`, provider: 'oss-hocuspocus', roomPrefix: 'tiptap-pro-lab-' })
  }
  if (req.method === 'GET' && url.pathname === '/api/health') return sendJson(res, 200, { ok: true })
  if (req.method === 'POST' && url.pathname === '/api/token') {
    try {
      let body = ''
      for await (const chunk of req) {
        body += chunk
        if (body.length > 4096) return sendJson(res, 413, { error: 'Request too large' })
      }
      const input = JSON.parse(body)
      if (!['local', 'cloud'].includes(input.mode)) return sendJson(res, 400, { error: 'Unknown mode' })
      if (!validRoom(input.room)) return sendJson(res, 400, { error: 'Research room prefix required' })
      const action = input.readOnly ? 'Documents:Read' : 'Documents:Write'
      const token = await issueToken({ ...input, action })
      return sendJson(res, 200, { token, expiresIn: 600, permission: action })
    } catch {
      // Do not echo input, PEM errors, or JWTs to the browser or logs.
      return sendJson(res, 400, { error: cloudReady ? 'Unable to issue token: check server configuration' : 'Cloud credentials incomplete; local mode remains available' })
    }
  }
  sendJson(res, 404, { error: 'Not found' })
})
api.listen(apiPort, '127.0.0.1', () => console.log(`Lab auth API: http://127.0.0.1:${apiPort}; local Hocuspocus: ws://127.0.0.1:${localPort}; Cloud configured: ${cloudReady}`))
async function stop() {
  api.close()
  await hocuspocus.destroy()
}
process.once('SIGTERM', stop)
process.once('SIGINT', stop)

#!/usr/bin/env node
// Operates only on the new UUID document allocated by this invocation.
import { createHash, createPublicKey, randomUUID } from 'node:crypto'
import { mkdir, writeFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { fileURLToPath, pathToFileURL } from 'node:url'

const requireLab = createRequire(new URL('../lab/package.json', import.meta.url))
const argumentsSet = new Set(process.argv.slice(2))
const allowedArguments = new Set(['--check-config', '--cleanup', '--help'])
const requiredVariables = ['TIPTAP_ENVIRONMENT_ID', 'TIPTAP_APP_ID', 'TIPTAP_PRIVATE_KEY']
const resultsDirectory = fileURLToPath(new URL('../results/', import.meta.url))
const knownSecrets = new Set()
const timeoutMs = 15_000

class LabFailure extends Error {
  constructor(code, details = {}) {
    super(code)
    this.code = code
    this.details = details
  }
}

async function importLabDependency(name) {
  try {
    return await import(pathToFileURL(requireLab.resolve(name)).href)
  } catch {
    throw new LabFailure('dependency_missing', { package: name, installDirectory: 'plan/tiptap-pro/lab' })
  }
}

function sanitize(value, depth = 0) {
  if (depth > 16) return '[depth limit]'
  if (typeof value === 'string') {
    if (knownSecrets.has(value) || /-----BEGIN .*PRIVATE KEY-----|\bBearer\s|\beyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/.test(value)) {
      return '[redacted]'
    }
    return value.slice(0, 4000)
  }
  if (Array.isArray(value)) return value.slice(0, 200).map(item => sanitize(item, depth + 1))
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).slice(0, 200).map(([key, item]) => [
      key,
      /token|secret|password|authorization|private.?key|cookie/i.test(key) ? '[redacted]' : sanitize(item, depth + 1),
    ]))
  }
  return value
}

function parseJson(buffer) {
  try {
    return JSON.parse(buffer.toString('utf8'))
  } catch {
    return undefined
  }
}

function safeServiceCode(json) {
  const allowed = new Set([
    'permission_denied', 'feature_not_available', 'rate_limited', 'service_unavailable',
    'token_expired', 'signature_invalid', 'unauthorized', 'not_found', 'document_not_found',
  ])
  return allowed.has(json?.code) ? json.code : undefined
}

async function configuration(jose, dotenv) {
  dotenv.config({ path: fileURLToPath(new URL('../../../.env', import.meta.url)), quiet: true })
  const missing = requiredVariables.filter(name => !process.env[name]?.trim())
  if (missing.length) throw new LabFailure('config_missing', { missing })
  const environmentId = process.env.TIPTAP_ENVIRONMENT_ID.trim()
  const appId = process.env.TIPTAP_APP_ID.trim()
  if (!/^[A-Za-z0-9_-]+$/.test(appId)) throw new LabFailure('invalid_app_id')
  const pem = process.env.TIPTAP_PRIVATE_KEY.replace(/\\n/g, '\n').trim()
  knownSecrets.add(pem)
  knownSecrets.add(process.env.TIPTAP_PRIVATE_KEY)
  let privateKey
  let publicKey
  let publicKeyFingerprint
  try {
    privateKey = await jose.importPKCS8(pem, 'ES256')
    const publicObject = createPublicKey(pem)
    const publicDer = publicObject.export({ type: 'spki', format: 'der' })
    publicKeyFingerprint = createHash('sha256').update(publicDer).digest('hex')
    publicKey = await jose.importSPKI(publicObject.export({ type: 'spki', format: 'pem' }).toString(), 'ES256')
  } catch {
    throw new LabFailure('private_key_invalid', { expected: 'ES256 P-256 PKCS8 PEM' })
  }
  let baseUrl
  try {
    baseUrl = new URL(process.env.TIPTAP_API_URL?.trim() || `https://${appId}.collab.tiptap.cloud`)
    if (!['https:', 'http:'].includes(baseUrl.protocol) || baseUrl.username || baseUrl.password || baseUrl.search || baseUrl.hash) {
      throw new Error('invalid URL')
    }
    if (baseUrl.pathname !== '/') throw new Error('base URL must not have a path')
  } catch {
    throw new LabFailure('invalid_api_url', { expected: 'HTTP(S) origin without path, query, fragment or credentials' })
  }
  const token = await new jose.SignJWT({ permissions: [{ action: 'Documents:Api:All', resource: '*' }] })
    .setProtectedHeader({ alg: 'ES256' })
    .setIssuer(environmentId)
    .setAudience(['Documents'])
    .setSubject('tiptap-pro-rest-lab')
    .setIssuedAt()
    .setExpirationTime('5m')
    .sign(privateKey)
  knownSecrets.add(token)
  try {
    await jose.jwtVerify(token, publicKey, { issuer: environmentId, audience: 'Documents', algorithms: ['ES256'] })
  } catch {
    throw new LabFailure('local_signature_verification_failed')
  }
  return { baseUrl, token, publicKeyFingerprint }
}

async function run() {
  for (const argument of argumentsSet) {
    if (!allowedArguments.has(argument)) throw new LabFailure('unknown_argument', { allowed: [...allowedArguments] })
  }
  if (argumentsSet.has('--help')) {
    console.log('node plan/tiptap-pro/scripts/cloud-rest.mjs [--check-config] [--cleanup]')
    console.log('Default: create a fresh lab document and leave it. --cleanup deletes only that new document.')
    return
  }
  const [dotenv, jose, Y] = await Promise.all(['dotenv', 'jose', 'yjs'].map(importLabDependency))
  const config = await configuration(jose, dotenv)
  if (argumentsSet.has('--check-config')) {
    console.log(JSON.stringify({ status: 'config_valid', localSignatureVerified: true, algorithm: 'ES256', publicKeySha256: config.publicKeyFingerprint, networkRequests: 0 }, null, 2))
    return
  }

  const runId = randomUUID()
  const documentName = `tiptap-pro-lab-rest-${runId}`
  const documentPath = `/api/documents/${encodeURIComponent(documentName)}`
  const startedAt = new Date().toISOString()
  const evidence = {
    schema: 'tiptap-pro-cloud-rest/v1', runId, documentName, startedAt,
    serverOrigin: config.baseUrl.origin, timeoutMs, publicKeySha256: config.publicKeyFingerprint,
    cleanupRequested: argumentsSet.has('--cleanup'), requests: [], checks: {},
    documentation: 'https://tiptap.dev/docs/collaboration/documents/rest-api',
  }
  const failures = []
  let created = false

  async function request(operation, method, suffix = '', body, contentType) {
    const url = new URL(`${documentPath}${suffix}`, config.baseUrl)
    // The caller can access this run's document and its child routes only.
    if (url.pathname !== documentPath && !url.pathname.startsWith(`${documentPath}/`)) {
      throw new LabFailure('document_scope_violation')
    }
    const record = { operation, method, path: `${url.pathname}${url.search}` }
    evidence.requests.push(record)
    const start = performance.now()
    try {
      const response = await fetch(url, {
        method, redirect: 'error', signal: AbortSignal.timeout(timeoutMs),
        headers: { Authorization: `Bearer ${config.token}`, ...(contentType ? { 'Content-Type': contentType } : {}) },
        body,
      })
      const buffer = Buffer.from(await response.arrayBuffer())
      const json = parseJson(buffer)
      Object.assign(record, {
        status: response.status, ok: response.ok, durationMs: Math.round(performance.now() - start),
        contentType: response.headers.get('content-type'), bytes: buffer.length,
      })
      if (response.ok && json !== undefined) record.json = sanitize(json)
      if (!response.ok) record.serviceCode = safeServiceCode(json)
      return { ok: response.ok, status: response.status, buffer, json }
    } catch (error) {
      record.durationMs = Math.round(performance.now() - start)
      record.error = ['AbortError', 'TimeoutError'].includes(error?.name) ? 'timeout' : 'network_or_redirect_error'
      return { ok: false, status: null }
    }
  }

  function requireSuccess(result, operation) {
    if (!result.ok) {
      failures.push(operation)
      throw new LabFailure('required_request_failed', { operation, status: result.status })
    }
  }

  try {
    const seed = { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: `Vaulter isolated REST experiment ${runId}` }] }] }
    const create = await request('create-document', 'POST', '?format=json', JSON.stringify(seed), 'application/json')
    requireSuccess(create, 'create-document')
    created = true

    const initialJson = await request('get-json', 'GET', '?format=json')
    requireSuccess(initialJson, 'get-json')
    const initialYjs = await request('get-yjs', 'GET', '?format=yjs')
    requireSuccess(initialYjs, 'get-yjs')
    const workingDocument = new Y.Doc()
    try {
      Y.applyUpdate(workingDocument, initialYjs.buffer)
    } catch {
      throw new LabFailure('initial_yjs_decode_failed')
    }
    // Decode the known rich-text field explicitly; other server-defined fields remain opaque.
    const baselineContent = workingDocument.getXmlFragment('default').toString()
    evidence.initialTopLevelTypes = [...workingDocument.share].map(([name, type]) => ({ name, constructor: type.constructor.name }))
    const vector = Y.encodeStateVector(workingDocument)
    const mapName = '__vaulter_tiptap_pro_rest_lab'
    const metadata = { runId, scope: 'metadata-only', createdAt: startedAt }
    workingDocument.transact(() => {
      for (const [key, value] of Object.entries(metadata)) workingDocument.getMap(mapName).set(key, value)
    }, 'vaulter-rest-experiment')
    const differentialUpdate = Y.encodeStateAsUpdate(workingDocument, vector)
    evidence.patch = { kind: 'differential-yjs-update', changedTopLevelType: mapName, bytes: differentialUpdate.length, metadata }
    const patch = await request('patch-yjs-metadata', 'PATCH', '', differentialUpdate, 'application/octet-stream')
    requireSuccess(patch, 'patch-yjs-metadata')

    const verifyYjs = await request('verify-yjs', 'GET', '?format=yjs')
    requireSuccess(verifyYjs, 'verify-yjs')
    const verifiedDocument = new Y.Doc()
    try {
      Y.applyUpdate(verifiedDocument, verifyYjs.buffer)
    } catch {
      throw new LabFailure('verified_yjs_decode_failed')
    }
    evidence.observedMetadata = sanitize(verifiedDocument.getMap(mapName).toJSON())
    evidence.checks.metadataPersisted = Object.entries(metadata).every(([key, value]) => verifiedDocument.getMap(mapName).get(key) === value)
    evidence.checks.defaultFragmentUnchanged = verifiedDocument.getXmlFragment('default').toString() === baselineContent
    evidence.finalTopLevelTypes = [...verifiedDocument.share].map(([name, type]) => ({ name, constructor: type.constructor.name }))
    const finalJson = await request('verify-json', 'GET', '?format=json')
    requireSuccess(finalJson, 'verify-json')
    evidence.checks.jsonUnchanged = JSON.stringify(initialJson.json) === JSON.stringify(finalJson.json)
    for (const [name, passed] of Object.entries(evidence.checks)) if (!passed) failures.push(name)

    const createVersion = await request('create-version-optional', 'POST', '/versions', JSON.stringify({ name: `REST lab ${runId}`, meta: { runId, source: 'vaulter-black-box-lab' } }), 'application/json')
    const listVersions = await request('list-own-versions-optional', 'GET', '/versions')
    evidence.optionalVersions = { createStatus: createVersion.status, listStatus: listVersions.status, available: createVersion.ok && listVersions.ok }
    const head = await request('head-own-document', 'HEAD')
    requireSuccess(head, 'head-own-document')
    workingDocument.destroy()
    verifiedDocument.destroy()
  } catch (error) {
    evidence.failure = error instanceof LabFailure ? { code: error.code, ...error.details } : { code: 'experiment_failed' }
    if (!failures.length) failures.push(evidence.failure.code)
  } finally {
    if (created && argumentsSet.has('--cleanup')) {
      const cleanup = await request('delete-own-generated-document', 'DELETE')
      evidence.cleanedUp = cleanup.ok
      if (!cleanup.ok) failures.push('cleanup_failed')
    } else {
      evidence.cleanedUp = false
    }
    evidence.status = failures.length ? 'failed' : 'passed'
    evidence.failures = failures
    evidence.finishedAt = new Date().toISOString()
    await mkdir(resultsDirectory, { recursive: true })
    const outputPath = `${resultsDirectory}/rest-${startedAt.replace(/[:.]/g, '-')}-${runId}.json`
    await writeFile(outputPath, `${JSON.stringify(sanitize(evidence), null, 2)}\n`, { mode: 0o600 })
    console.log(JSON.stringify({ status: evidence.status, documentName, cleanedUp: evidence.cleanedUp, failures, evidence: outputPath }, null, 2))
    if (failures.length) process.exitCode = 1
  }
}

run().catch(error => {
  const output = error instanceof LabFailure ? { status: error.code, ...error.details } : { status: 'configuration_or_startup_failed' }
  console.error(JSON.stringify(output, null, 2))
  process.exitCode = 1
})

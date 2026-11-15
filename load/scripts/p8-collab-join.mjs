#!/usr/bin/env node
/**
 * Hold seeded Yjs rooms open while the matrix scrapes Hocuspocus /stats.
 *
 * Manual mode:
 *   node load/scripts/p8-collab-join.mjs --token <jwt> --room <room> --duration 60s
 *
 * Dataset mode:
 *   node load/scripts/p8-collab-join.mjs --backend http://localhost:8080 \
 *     --dataset load/k6/dataset.json --limit 20 --duration 60s
 */
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import process from 'node:process'
import { pathToFileURL } from 'node:url'

const args = parseArgs(process.argv.slice(2))
const backend = (args.backend ?? process.env.VAULTER_BACKEND_URL ?? 'http://localhost:8080').replace(/\\$/, '')
const apiPrefix = (args['api-prefix'] ?? process.env.VAULTER_API_PREFIX ?? 'api/vaulter').replace(/^\\$|\\$/g, '')
const actor = String(args.actor ?? process.env.VAULTER_LOAD_ACTOR ?? '1')
const durationMs = parseDuration(args.duration ?? process.env.DURATION ?? '60s')
const limit = Number(args.limit ?? '20')
const sessions = args.token && args.room
  ? [{ token: args.token, room: args.room, url: args.ws ?? process.env.VAULTER_COLLAB_WS_URL }]
  : await discoverSessions()

if (sessions.length === 0) {
  throw new Error('p8-collab-join: no seeded document collaboration sessions discovered')
}

const requireFromHub = createRequire(new URL('../../packages/collaboration/hub/package.json', import.meta.url))
const providerModule = await import(pathToFileURL(requireFromHub.resolve('@hocuspocus/provider')).href)
const yjsModule = await import(pathToFileURL(requireFromHub.resolve('yjs')).href)
const providers = sessions.map((session) => {
  const document = new yjsModule.Doc()
  const provider = new providerModule.HocuspocusProvider({
    url: args.ws ?? session.url ?? process.env.VAULTER_COLLAB_WS_URL ?? 'ws://localhost:1234',
    name: session.room,
    token: session.token,
    document,
  })
  provider.on('status', ({ status }) => {
    if (status === 'connected') {
      console.log(`p8-collab-join: connected to ${session.room}`)
    }
  })
  return { provider, document }
})

let stop
const stopped = new Promise((resolve) => {
  stop = resolve
})
process.once('SIGINT', stop)
process.once('SIGTERM', stop)

await Promise.race([
  new Promise((resolve) => setTimeout(resolve, durationMs)),
  stopped,
])

for (const { provider, document } of providers) {
  provider.destroy()
  document.destroy()
}

async function discoverSessions () {
  const datasetPath = args.dataset ?? new URL('../k6/dataset.json', import.meta.url)
  const dataset = JSON.parse(await readFile(datasetPath, 'utf8'))
  const root = await listChildren(dataset.storage_id, dataset.root_node_id)
  const folder = root.find((node) => node.kind === 'folder' && node.name === 'documents')
  if (!folder) {
    return []
  }
  const documents = (await listChildren(dataset.storage_id, folder.id))
    .filter((node) => node.kind === 'document')
    .slice(0, limit)
  return await Promise.all(documents.map(issueSession))
}

async function listChildren (storageId, parentId) {
  const url = new URL(`${backend}/${apiPrefix}/storages/${storageId}/nodes`)
  url.searchParams.set('parent_id', String(parentId))
  url.searchParams.set('per_page', '200')
  const response = await fetch(url, { headers: { 'X-Load-Actor': actor } })
  if (!response.ok) {
    throw new Error(`p8-collab-join: node discovery failed with HTTP ${response.status}`)
  }
  const payload = await response.json()
  return Array.isArray(payload.data) ? payload.data : []
}

async function issueSession (node) {
  const response = await fetch(`${backend}/${apiPrefix}/nodes/${node.id}/collab/token`, {
    method: 'POST',
    headers: { 'X-Load-Actor': actor },
  })
  if (!response.ok) {
    throw new Error(`p8-collab-join: token request for node ${node.id} failed with HTTP ${response.status}`)
  }
  const payload = await response.json()
  const session = payload.data
  if (!session?.token || !session?.room) {
    throw new Error(`p8-collab-join: token response for node ${node.id} is incomplete`)
  }
  return session
}

function parseArgs (argv) {
  const result = {}
  for (let index = 0; index < argv.length; index += 1) {
    const key = argv[index]
    if (!key.startsWith('--')) continue
    const value = argv[index + 1]
    if (value && !value.startsWith('--')) {
      result[key.slice(2)] = value
      index += 1
    }
  }
  return result
}

function parseDuration (value) {
  const match = /^(\\d+)(ms|s|m)$/.exec(String(value))
  if (!match) throw new Error(`p8-collab-join: invalid duration ${value}`)
  const multipliers = { ms: 1, s: 1000, m: 60000 }
  return Number(match[1]) * multipliers[match[2]]
}

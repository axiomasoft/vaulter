import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'
import * as Y from '../lab/node_modules/yjs/dist/yjs.mjs'
import { TiptapCollabProvider, TiptapCollabProviderWebsocket } from '../lab/node_modules/@tiptap-pro/provider/dist/index.js'
import { getSchema } from '../lab/node_modules/@tiptap/core/dist/index.js'
import StarterKit from '../lab/node_modules/@tiptap/starter-kit/dist/index.js'
import { prosemirrorJSONToYDoc } from '../lab/node_modules/@tiptap/y-tiptap/dist/index.js'
import { getChangesetTransform } from '../lab/node_modules/@tiptap-pro/extension-snapshot-compare/dist/index.js'
import { getPreviewContentFromVersionPayload } from '../lab/node_modules/@tiptap-pro/extension-snapshot/dist/index.js'

// Public classes, synthetic local state, socket auto-connect disabled. No Cloud request.
const socket = new TiptapCollabProviderWebsocket({ baseUrl: 'ws://127.0.0.1:9', autoConnect: false })
const document = new Y.Doc()
const provider = new TiptapCollabProvider({ name: 'tiptap-pro-lab-source-state-only', document, websocketProvider: socket, user: 'source-probe', token: 'local-unused' })
const checks = []
const actions = []
const updates = []
provider.sendStateless = payload => actions.push(JSON.parse(payload))
document.on('update', update => updates.push(update.length))
function check(name, condition) { assert.ok(condition, name); checks.push({ name, passed: true }) }
let report
try {
  const thread = provider.createThread({ field: 'default', data: { ownSynthetic: true } })
  const first = provider.addComment(thread.id, { content: 'Synthetic first comment', data: { userId: 'source-probe' } })
  const reply = provider.addComment(thread.id, { content: 'Synthetic reply', data: { userId: 'source-probe-2' } })
  const beforeResolve = document.getArray('__tiptapcollab__threads').toJSON()
  provider.updateThread(thread.id, { resolvedAt: '2026-11-21T00:00:00.000Z' })
  check('thread resolve is shared resolvedAt mutation', Boolean(provider.getThread(thread.id).resolvedAt))
  provider.updateThread(thread.id, { resolvedAt: null })
  check('thread reopen sets resolvedAt null', provider.getThread(thread.id).resolvedAt === null)
  const replyId = reply.comments.at(-1).id
  provider.deleteComment(thread.id, replyId, { deleteContent: false })
  const deletedReply = provider.getThread(thread.id)
  check('delete reply moves record to deletedComments', deletedReply.comments.length === 1 && deletedReply.deletedComments.length === 1)
  check('deleteContent false retains synthetic comment content', deletedReply.deletedComments[0].content === 'Synthetic reply')
  provider.deleteThread(thread.id)
  check('archive hides thread in default getThreads', provider.getThreads().length === 0 && provider.getThreads({ types: ['archived'] }).length === 1)
  provider.restoreThread(thread.id)
  check('restore clears deletedAt without recreating ID', provider.getThreads()[0].id === thread.id && provider.getThreads()[0].deletedAt === null)
  const record = document.getArray('__tiptapcollab__threads').get(0)
  check('threads array contains Y.Map', record instanceof Y.Map)
  check('comments and tombstones are nested Y.Array', record.get('comments') instanceof Y.Array && record.get('deletedComments') instanceof Y.Array)
  check('comments array contains Y.Map', record.get('comments').get(0) instanceof Y.Map)
  check('comment mutations did not call stateless transport', actions.length === 0)

  document.getArray('__tiptapcollab__versions').push([{ version: 0, name: 'local legacy', date: 0 }])
  check('v1 listVersions reads shared array', (await provider.listVersions())[0].name === 'local legacy')
  document.getMap('__tiptapcollab__config').set('versionsV1Migrated', 1)
  provider.emit('synced', { state: true })
  check('migrated config switches versions protocol to2', provider.getVersionsVersion() === 2)
  provider.createVersion('synthetic version', true, { ownSynthetic: true })
  provider.previewVersion(2)
  provider.revertToVersion(1, { fields: ['default'], currentVersionName: false, newVersionName: 'synthetic restore' })
  check('history operations are stateless actions', ['versions.list', 'version.create', 'version.preview', 'document.revert'].every(action => actions.some(item => item.action === action)))
  check('constructor socket stayed disconnected', socket.status === 'disconnected' && !socket.shouldConnect)

  const schema = getSchema([StarterKit])
  const original = { type: 'doc', content: [
    { type: 'paragraph', content: [{ type: 'text', text: 'Pay 15 days.' }] },
    { type: 'paragraph', content: [{ type: 'text', text: 'Unchanged neighbour.' }] },
  ] }
  const ydoc = prosemirrorJSONToYDoc(schema, original, 'default')
  ydoc.gc = false
  const users = new Y.PermanentUserData(ydoc, ydoc.getMap('__tiptapcollab__users'))
  users.setUserMapping(ydoc, ydoc.clientID, 'source-probe')
  const previous = Y.encodeStateAsUpdateV2(ydoc)
  const text = ydoc.getXmlFragment('default').get(0).get(0)
  ydoc.transact(() => { text.delete(4, 2); text.insert(4, '30'); text.format(4, 2, { bold: {} }) }, 'source-probe')
  await new Promise(resolve => setTimeout(resolve, 5))
  const latest = Y.encodeStateAsUpdateV2(ydoc)
  const changes = await getChangesetTransform({ prevSnapshot: previous, snapshot: latest, schema })
  const diffs = changes.toDiff()
  const diffSummary = diffs.map(diff => ({ type: diff.type, from: diff.from, to: diff.to, text: diff.content.textBetween(0, diff.content.size), contentType: diff.diffContentType, userId: diff.attribution.userId || null }))
  const result = changes.doc.toJSON()
  check('changeset transform replaces15 with30', result.content[0].content.map(node => node.text || '').join('') === 'Pay 30 days.')
  check('changeset transform retains bold mark', result.content[0].content.some(node => node.text === '30' && node.marks?.some(mark => mark.type === 'bold')))
  check('changeset transform preserves neighbour', JSON.stringify(result.content[1]) === JSON.stringify(original.content[1]))
  check('changeset classifies added and deleted content', diffSummary.some(diff => diff.type.endsWith('insert') && diff.text.includes('30')) && diffSummary.some(diff => diff.type.endsWith('delete') && diff.text.includes('15')))
  const preview = getPreviewContentFromVersionPayload(JSON.stringify({ event: 'version.preview', version: 2, ydoc: Buffer.from(Y.encodeStateAsUpdate(ydoc)).toString('base64') }))
  check('preview decodes full Yjs V1 state into document JSON', preview.content[0].content.map(node => node.text || '').join('') === 'Pay 30 days.')

  const sourceFiles = []
  for (const name of ['provider', 'extension-comments', 'extension-snapshot', 'extension-snapshot-compare']) {
    const base = new URL(`../lab/node_modules/@tiptap-pro/${name}/`, import.meta.url)
    const pkg = JSON.parse(await readFile(new URL('package.json', base), 'utf8'))
    const bytes = await readFile(new URL('dist/index.js', base))
    sourceFiles.push({ package: pkg.name, version: pkg.version, path: `lab/node_modules/@tiptap-pro/${name}/dist/index.js`, sha256: createHash('sha256').update(bytes).digest('hex') })
  }
  report = {
    at: new Date().toISOString(), scope: 'local installed-source/public-API probe; zero Cloud requests; synthetic state only',
    sourceFiles, checks, sharedTypes: [...document.share].map(([name, value]) => ({ name, type: value.constructor.name })),
    threadShape: { keys: [...record.keys()], commentKeys: [...record.get('comments').get(0).keys()], deletedCommentKeys: [...record.get('deletedComments').get(0).keys()], firstCommentCount: first.comments.length, beforeResolveCount: beforeResolve.length },
    updateCount: updates.length, updateSizes: updates, statelessActions: actions, diffSummary, finalJson: result,
  }
  ydoc.destroy()
} finally {
  provider.destroy()
  socket.destroy()
  document.destroy()
}
const output = new URL('../results/source-state-probe.json', import.meta.url)
await mkdir(fileURLToPath(new URL('../results/', import.meta.url)), { recursive: true })
await writeFile(output, JSON.stringify(report, null, 2) + '\n')
console.log(JSON.stringify({ checks: checks.length, passed: checks.every(item => item.passed), result: fileURLToPath(output) }))

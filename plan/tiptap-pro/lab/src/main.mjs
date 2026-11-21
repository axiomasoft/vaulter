import { Editor, Extension } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import Collaboration from '@tiptap/extension-collaboration'
import CollaborationCaret from '@tiptap/extension-collaboration-caret'
import { HocuspocusProvider } from '@hocuspocus/provider'
import * as Y from 'yjs'
import { TiptapCollabProvider, proAvailable } from 'virtual:cloud-provider'
import { CommentsKit, getThreadsWithPositions, Snapshot, watchPreviewContent, SnapshotCompare, TrackedChanges, findSuggestions, proFeaturesAvailable } from 'virtual:pro-extensions'
import './style.css'

const LabBlockId = Extension.create({
  name: 'labBlockId',
  addGlobalAttributes() {
    return [{
      types: ['paragraph', 'heading'],
      attributes: {
        blockId: {
          default: null,
          parseHTML: element => element.getAttribute('data-block-id'),
          renderHTML: attributes => attributes.blockId ? { 'data-block-id': attributes.blockId } : {},
        },
      },
    }]
  },
})

const users = [{ name: 'Alice', color: '#2563eb' }, { name: 'Bob', color: '#b43370' }]
let activeUsers = users
const editors = []
const providers = []
const docs = []
const events = []
const states = []
let config
let activeRoom
let activeMode
let activeFeatures = []
const previewContent = [null, null]
const cleanup = []

function renderProPanels() {
  const target = document.querySelector('#pro-state')
  if (!target) return
  target.textContent = JSON.stringify({
    installed: proFeaturesAvailable,
    enabled: activeFeatures,
    clients: editors.map((editor, index) => ({
      user: activeUsers[index].name,
      threads: activeFeatures.includes('comments') ? providers[index]?.getThreads?.() || [] : [],
      versions: activeFeatures.includes('snapshot') ? providers[index]?.getVersions?.() || [] : [],
      tracking: editor.storage.trackedChanges?.enabled || false,
      suggestions: activeFeatures.includes('trackedChanges') ? findSuggestions(editor) : [],
    })),
  }, null, 2)
}

function emit(client, type, data = {}) {
  events.push({ at: new Date().toISOString(), client, type, ...data })
  if (events.length > 400) events.shift()
  document.querySelector('#events').textContent = events.map(item => JSON.stringify(item)).join('\n')
  queueMicrotask(renderProPanels)
}

function state(index, update) {
  states[index] = { ...states[index], ...update }
  document.querySelector(`#state-${index}`).textContent = JSON.stringify(states[index])
}

async function tokenFor(room, user, mode, readOnly = false) {
  const response = await fetch('/api/token', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ room, user, mode, readOnly }) })
  const data = await response.json()
  if (!response.ok) throw new Error(data.error)
  return data.token
}

function destroy() {
  cleanup.splice(0).forEach(stop => stop())
  providers.splice(0).forEach(provider => provider.destroy())
  editors.splice(0).forEach(editor => editor.destroy())
  docs.splice(0).forEach(doc => doc.destroy())
  states.splice(0)
}

async function connect(options = {}) {
  config ||= await fetch('/api/config').then(response => response.json())
  const room = options.room || document.querySelector('#room').value
  const mode = options.mode || document.querySelector('#mode').value
  const clientCount = options.clientCount ?? 2
  const clientOffset = options.clientOffset ?? 0
  if (![1, 2].includes(clientCount) || ![0, 1].includes(clientOffset) || clientOffset + clientCount > users.length) {
    throw new Error('Choose Alice, Bob, or both lab clients')
  }
  const features = options.features || (mode === 'cloud' ? [...document.querySelectorAll('[data-feature]:checked')].map(input => input.dataset.feature) : [])
  for (const feature of features) {
    if (!proFeaturesAvailable[feature]) throw new Error(`Pro feature ${feature} не установлена`)
    if (mode !== 'cloud' && feature !== 'trackedChanges') throw new Error(`${feature} требует Pro Cloud provider в этом стенде`)
  }
  if (mode === 'cloud' && !proAvailable) throw new Error('Cloud заблокирован: установите @tiptap-pro/provider через npm-токен и перезапустите стенд.')
  if (mode === 'cloud' && !config.cloudReady) throw new Error('Cloud ещё не настроен: нужны Environment ID, Document server ID и приватный ключ в корневом .env.')
  if (!/^tiptap-pro-lab-[A-Za-z0-9_.-]{1,110}$/.test(room)) throw new Error('Разрешены только комнаты tiptap-pro-lab-*')
  destroy()
  activeUsers = users.slice(clientOffset, clientOffset + clientCount)
  activeRoom = room
  activeMode = mode
  activeFeatures = [...features]
  previewContent.fill(null)
  document.querySelector('#mode').value = mode
  document.querySelector('#room').value = room
  for (let index = 0; index < clientCount; index++) {
    const user = activeUsers[index]
    const doc = new Y.Doc()
    docs.push(doc)
    state(index, { status: 'connecting', synced: false, authenticated: false, unsynced: 0 })
    const Provider = mode === 'cloud' ? TiptapCollabProvider : HocuspocusProvider
    const provider = new Provider({
      ...(mode === 'cloud' ? { appId: config.appId } : { url: config.localUrl }),
      name: room,
      user: user.name,
      document: doc,
      token: () => tokenFor(room, user.name, mode, Boolean(options.readOnly)),
      onStatus({ status }) { state(index, { status }); emit(index, 'status', { status }) },
      onSynced({ state: synced }) { state(index, { synced }); emit(index, 'synced', { synced }) },
      onAuthenticated() { state(index, { authenticated: true }); emit(index, 'authenticated') },
      onAuthenticationFailed() { state(index, { authenticated: false }); emit(index, 'authenticationFailed') },
      onUnsyncedChanges({ number }) { state(index, { unsynced: number }); emit(index, 'unsyncedChanges', { number }) },
      onClose({ event }) { emit(index, 'close', { code: event?.code }) },
    })
    providers.push(provider)
    const editor = new Editor({
      element: document.querySelector(`#editor-${index}`),
      editable: !options.readOnly,
      enableContentCheck: true,
      extensions: [
        StarterKit.configure({ undoRedo: false }),
        LabBlockId,
        Collaboration.configure({ document: doc }),
        CollaborationCaret.configure({ provider, user }),
        ...(features.includes('comments') ? [CommentsKit.configure({ provider, useLegacyWrapping: false })] : []),
        ...(features.includes('snapshot') ? [Snapshot.configure({ provider, onUpdate(payload) { emit(index, 'versionsUpdated', { count: payload.versions.length, currentVersion: payload.currentVersion, version: payload.version }) } })] : []),
        ...(features.includes('snapshotCompare') ? [SnapshotCompare.configure({ provider })] : []),
        ...(features.includes('trackedChanges') ? [TrackedChanges.configure({ enabled: false, userId: user.name, userMetadata: { name: user.name }, onSuggestionCreate(suggestion) { emit(index, 'suggestionCreated', { id: suggestion.id, suggestionType: suggestion.type }) }, onSuggestionAccept(id) { emit(index, 'suggestionAccepted', { id }) }, onSuggestionReject(id) { emit(index, 'suggestionRejected', { id }) } })] : []),
      ],
      onContentError({ disableCollaboration }) { disableCollaboration(); emit(index, 'contentError') },
      onUpdate() { emit(index, 'editorUpdate') },
    })
    editors.push(editor)
    if (mode === 'cloud') {
      const onSaved = date => emit(index, 'saved', { date: date?.toISOString?.() || null })
      const onStateless = ({ payload }) => {
        try {
          const data = JSON.parse(payload)
          emit(index, 'stateless', { event: typeof data.event === 'string' ? data.event : 'unknown', version: Number.isSafeInteger(data.version) ? data.version : undefined })
        } catch { emit(index, 'stateless', { event: 'non-json', bytes: String(payload).length }) }
      }
      provider.on('saved', onSaved)
      provider.on('stateless', onStateless)
      cleanup.push(() => { provider.off('saved', onSaved); provider.off('stateless', onStateless) })
      if (features.includes('comments')) {
        const onThreads = () => emit(index, 'threadsUpdated', { count: provider.getThreads().length })
        provider.watchThreads(onThreads)
        cleanup.push(() => provider.unwatchThreads(onThreads))
      }
      if (features.includes('snapshot')) cleanup.push(watchPreviewContent(provider, content => { previewContent[index] = content; emit(index, 'versionPreview') }))
    }
    doc.on('update', update => emit(index, 'yUpdate', { bytes: update.byteLength }))
  }
  emit('lab', 'connect', { room, mode, provider: activeMode === 'cloud' ? 'tiptap-pro' : 'oss-hocuspocus' })
  return { room, mode }
}

window.lab = {
  editors, providers, docs, events, states, connect,
  available: proFeaturesAvailable,
  get features() { return [...activeFeatures] },
  threads(index, getThreadsOptions) {
    if (!activeFeatures.includes('comments')) throw new Error('Connect with comments enabled')
    return getThreadsWithPositions({ editor: editors[index], provider: providers[index], getThreadsOptions })
  },
  createThread(index, { content, from, to, data = {}, commentData = {} }) {
    if (!activeFeatures.includes('comments')) throw new Error('Connect with comments enabled')
    const before = new Set(providers[index].getThreads().map(thread => thread.id))
    const editor = editors[index]
    if (from !== undefined) editor.commands.setTextSelection({ from, to: to ?? from })
    const ok = editor.commands.setThread({ content, data, commentData: { userId: activeUsers[index].name, ...commentData } })
    const thread = providers[index].getThreads().find(item => !before.has(item.id)) || null
    return { ok, threadId: thread?.id || null, thread }
  },
  reply(index, threadId, content) { return editors[index].commands.createComment({ threadId, content, data: { userId: activeUsers[index].name } }) },
  resolveThread(index, id) { return editors[index].commands.resolveThread({ id }) },
  unresolveThread(index, id) { return editors[index].commands.unresolveThread({ id }) },
  removeThread(index, id, deleteThread = false) { return editors[index].commands.removeThread({ id, deleteThread }) },
  saveVersion(index, name, options = {}) { return editors[index].commands.saveVersion(name, options.force ?? true, options.meta) },
  fetchVersions(index) { return Promise.race([providers[index].listVersions(), new Promise((_, reject) => { const timer = setTimeout(() => reject(new Error('Version list response timeout')), 12000); timer.unref?.() })]) },
  versions(index) { return providers[index].getVersions() },
  versioning(index, enabled) { return enabled ? editors[index].commands.enableVersioning() : editors[index].commands.disableVersioning() },
  previewVersion(index, version) { previewContent[index] = null; providers[index].previewVersion(version); return true },
  previewContent(index) { return previewContent[index] },
  revertVersion(index, version, options = {}) { providers[index].revertToVersion(version, options); return true },
  compareVersions(index, fromVersion, toVersion) { return editors[index].commands.compareVersions({ fromVersion, toVersion }) },
  track(index, enabled) { return enabled ? editors[index].commands.enableTrackedChanges() : editors[index].commands.disableTrackedChanges() },
  suggestions(index) { if (!activeFeatures.includes('trackedChanges')) return []; return findSuggestions(editors[index]) },
  accept(index, id) { return editors[index].commands.acceptSuggestion({ id }) },
  reject(index, id) { return editors[index].commands.rejectSuggestion({ id }) },
  acceptAll(index) { return editors[index].commands.acceptAllSuggestions() },
  rejectAll(index) { return editors[index].commands.rejectAllSuggestions() },
  trackedInsert(index, from, content) { return editors[index].commands.addTrackedInsertion({ from, content }) },
  trackedDelete(index, from, to) { return editors[index].commands.addTrackedDeletion({ from, to }) },
  trackedReplace(index, from, to, content) { return editors[index].commands.addTrackedReplacement({ from, to, content }) },
  snapshot() { return { room: activeRoom, mode: activeMode, provider: activeMode === 'cloud' ? 'tiptap-pro' : 'oss-hocuspocus', features: [...activeFeatures], states: states.map(value => ({ ...value })), json: editors.map(editor => editor.getJSON()), text: editors.map(editor => editor.getText()), yStateBytes: docs.map(doc => Y.encodeStateAsUpdate(doc).byteLength) } },
  setText(index, text) { editors[index].commands.setContent({ type: 'doc', content: [{ type: 'paragraph', content: text ? [{ type: 'text', text }] : [] }] }) },
  appendText(index, text) { const editor = editors[index]; editor.commands.insertContentAt(editor.state.doc.content.size - 1, text) },
  disconnect(index) { providers[index].disconnect() },
  reconnect(index) { providers[index].connect() },
  destroy,
}

const query = new URLSearchParams(window.location.search)
if (['cloud', 'local'].includes(query.get('mode'))) document.querySelector('#mode').value = query.get('mode')
if (query.get('room')) document.querySelector('#room').value = query.get('room')

document.querySelector('#connect-form').addEventListener('submit', event => {
  event.preventDefault()
  document.querySelector('#error').textContent = ''
  connect().catch(error => { document.querySelector('#error').textContent = error.message })
})
document.querySelectorAll('[data-disconnect]').forEach(button => button.addEventListener('click', () => window.lab.disconnect(Number(button.dataset.disconnect))))
document.querySelectorAll('[data-reconnect]').forEach(button => button.addEventListener('click', () => window.lab.reconnect(Number(button.dataset.reconnect))))
document.querySelectorAll('[data-pro-action]').forEach(button => button.addEventListener('click', async () => {
  const value = id => document.querySelector(`#${id}`).value
  try {
    let result
    const threadId = value('thread-id')
    switch (button.dataset.proAction) {
      case 'create-thread': result = window.lab.createThread(0, { content: value('comment-content') }); if (result.threadId) document.querySelector('#thread-id').value = result.threadId; break
      case 'reply': result = window.lab.reply(0, threadId, value('comment-content')); break
      case 'resolve': result = window.lab.resolveThread(0, threadId); break
      case 'unresolve': result = window.lab.unresolveThread(0, threadId); break
      case 'save-version': result = window.lab.saveVersion(0, value('version-name')); break
      case 'fetch-versions': result = await window.lab.fetchVersions(0); break
      case 'preview-version': result = window.lab.previewVersion(0, Number(value('version-id'))); break
      case 'revert-version': result = window.lab.revertVersion(0, Number(value('version-id'))); break
      case 'track-on': result = window.lab.track(0, true); break
      case 'track-off': result = window.lab.track(0, false); break
      case 'accept': result = window.lab.accept(0, value('suggestion-id')); break
      case 'reject': result = window.lab.reject(0, value('suggestion-id')); break
    }
    document.querySelector('#pro-result').textContent = JSON.stringify(result)
    renderProPanels()
  } catch (error) { document.querySelector('#error').textContent = error.message }
}))
renderProPanels()

try {
  config = await fetch('/api/config').then(response => response.json())
  document.querySelector('#config-status').textContent = `Cloud: ${config.cloudReady ? 'настроен, подключение ещё не проверено' : 'параметры отсутствуют'}; Pro provider: ${proAvailable ? 'установлен' : 'не установлен'}; локальный сервер: ${config.localUrl}`
  if (query.get('auto') === '1') await connect()
} catch {
  document.querySelector('#config-status').textContent = 'Auth API недоступен. Запустите npm run dev из папки lab.'
}

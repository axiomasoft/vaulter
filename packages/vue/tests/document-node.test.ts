import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import VaulterDocumentNode from '../src/components/VaulterDocumentNode.vue'
import { VaulterApiError } from '../src/api/errors'
import type { CollaborationSession, Node } from '../src/api/types'
import { makeDocument, makePresentation } from './support/nodes'
import { withClient } from './support/mount'

async function flushAll(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await Promise.resolve()
    await nextTick()
  }
}

const content = (body: object, etag: string | null = '"20.0.1"') =>
  ({ type: 'richtext', schema_version: 1, state_version: 1, collab_epoch: 0, revision_id: null, content: body, etag })

const hocuspocusSession = (readOnly: boolean, schemaVersion = 1): CollaborationSession => ({
  driver: 'hocuspocus', room: 'vaulter.documents.3.20.0', node_id: '20', drive_id: '3',
  epoch: 0, schema_version: schemaVersion, mode: readOnly ? 'ro' : 'rw', token: 'jwt', url: 'ws://hub:1234',
})

// A stub Hocuspocus provider — no real socket. Lets the session build in a test.
const stubProviders = () => ({
  createHocuspocusProvider: vi.fn().mockReturnValue({
    awareness: null,
    isConnected: () => true,
    onConnectionChange: () => () => {},
    destroy: vi.fn(),
  }),
})

function collaborativeNode(overrides: Parameters<typeof makeDocument>[0] = {}): Node {
  const presentation = makePresentation({ key: 'vaulter/tiptap', documentType: 'richtext', handler: null })
  presentation.capabilities.collaborative = true

  return makeDocument({ id: '20', presentation, ...overrides })
}

/**
 * A fake client. `session` (when set) makes the collaboration endpoint resolve a Hocuspocus
 * session; `sessionError` makes it fail.
 */
function docClient(options: { session?: CollaborationSession, sessionError?: unknown } = {}) {
  const update = vi.fn().mockResolvedValue(content({ type: 'doc', saved: true }, '"20.0.2"'))
  const collaborationSession = options.sessionError !== undefined
    ? vi.fn().mockRejectedValue(options.sessionError)
    : vi.fn().mockResolvedValue(options.session)
  const contentFn = vi.fn().mockResolvedValue(content({ type: 'doc', content: [] }))

  return { client: { documents: { content: contentFn, update, collaborationSession } }, update, collaborationSession, contentFn }
}

/** An editor in the default slot: renders the mode flags so a test can assert them. */
const editorSlot = `
  <template #default="{ collaborative, readonly, ydoc, content }">
    <div
      class="stub-editor"
      :data-collaborative="collaborative ? 'true' : 'false'"
      :data-readonly="readonly ? 'true' : 'false'"
      :data-has-ydoc="ydoc ? 'true' : 'false'"
      :data-has-content="content ? 'true' : 'false'"
    >editor-mounted</div>
  </template>`

describe('VaulterDocumentNode', () => {
  it('mounts the slot editor with the loaded content for a non-collaborative document', async () => {
    const { client, collaborationSession } = docClient()
    const presentation = makePresentation({ key: 'vaulter/tiptap', documentType: 'richtext', handler: null })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: makeDocument({ id: '20', presentation }) },
      slots: { default: editorSlot },
      ...withClient(client),
    })

    await flushAll()

    const editor = wrapper.get('.stub-editor')
    expect(editor.attributes('data-collaborative')).toBe('false')
    expect(editor.attributes('data-has-content')).toBe('true')
    expect(wrapper.emitted('ready')?.[0]?.[0]).toMatchObject({ id: '20' })
    // Never opens a session for a document that is not collaborative.
    expect(collaborationSession).not.toHaveBeenCalled()
    expect(wrapper.attributes('data-document-type')).toBe('richtext')
  })

  it('mounts the editor in COLLABORATIVE mode for a collaborative document', async () => {
    const { client, update } = docClient({ session: hocuspocusSession(false) })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), collaborationProviders: stubProviders() },
      slots: { default: editorSlot },
      ...withClient(client),
    })

    await flushAll()

    const editor = wrapper.get('.stub-editor')
    expect(editor.attributes('data-collaborative')).toBe('true')
    // The shared Y.Doc is handed to the editor and writes go through Yjs (no autosave).
    expect(editor.attributes('data-has-ydoc')).toBe('true')
    // The hub seeds the room; the editor gets no JSON it could seed it with again.
    expect(editor.attributes('data-has-content')).toBe('false')
    expect(wrapper.emitted('collaborating')?.[0]?.[0]).toBe(false)
    expect(update).not.toHaveBeenCalled()
  })

  it('opens a document in a newer schema read-only and asks to update the application', async () => {
    const { client } = docClient({ session: hocuspocusSession(false, 2) })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), collaborationProviders: stubProviders(), supportedSchemaVersion: 1 },
      slots: { default: editorSlot },
      ...withClient(client),
    })

    await flushAll()

    expect(wrapper.get('.stub-editor').attributes('data-readonly')).toBe('true')
    expect(wrapper.emitted('schema-outdated')?.[0]).toEqual([2])
  })

  it('respects a read-only collaboration session', async () => {
    const { client } = docClient({ session: hocuspocusSession(true) })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), collaborationProviders: stubProviders() },
      slots: { default: editorSlot },
      ...withClient(client),
    })

    await flushAll()

    const editor = wrapper.get('.stub-editor')
    expect(editor.attributes('data-collaborative')).toBe('true')
    expect(editor.attributes('data-readonly')).toBe('true')
    expect(wrapper.emitted('collaborating')?.[0]?.[0]).toBe(true)
  })

  it('is read-only for a caller without the edit ability, and ignores edits', async () => {
    const { client, update } = docClient()
    const presentation = makePresentation({ key: 'vaulter/tiptap', documentType: 'richtext', handler: null })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: makeDocument({ id: '20', presentation, abilities: { edit: false } }), debounceMs: 0 },
      slots: { default: `<template #default="{ readonly, setContent }"><button class="type" :data-readonly="readonly" @click="setContent({ type: 'doc', typed: true })">x</button></template>` },
      ...withClient(client),
    })
    await flushAll()

    expect(wrapper.get('.type').attributes('data-readonly')).toBe('true')
    await wrapper.get('.type').trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 5))

    expect(update).not.toHaveBeenCalled()
  })

  it('falls back to the autosave path ONLY when the session endpoint says collaboration_unavailable', async () => {
    const unavailable = new VaulterApiError({ status: 409, code: 'collaboration_unavailable' })
    const { client } = docClient({ sessionError: unavailable })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client),
    })

    await flushAll()

    const editor = wrapper.get('.stub-editor')
    expect(editor.attributes('data-collaborative')).toBe('false')
    expect(editor.attributes('data-readonly')).toBe('false')
    expect(wrapper.emitted('collaborating')).toBeUndefined()
    expect(wrapper.emitted('collaboration-failed')).toBeUndefined()
  })

  it('stays read-only (never autosaves over a Yjs state) when the session fails for another reason', async () => {
    const failure = new VaulterApiError({ status: 503, code: 'http_503' })
    const { client, update } = docClient({ sessionError: failure })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client),
    })

    await flushAll()

    expect(wrapper.get('.stub-editor').attributes('data-readonly')).toBe('true')
    expect(wrapper.emitted('collaboration-failed')?.[0]?.[0]).toBe(failure)
    expect(update).not.toHaveBeenCalled()
  })

  it('autosaves with the loaded ETag and reports a lost race (412) through the conflict slot and event', async () => {
    const { client, update, contentFn } = docClient()
    const stale = new VaulterApiError({ status: 412, code: 'document_state_mismatch', title: 'Document changed' })
    update.mockRejectedValueOnce(stale)
    contentFn
      .mockResolvedValueOnce(content({ type: 'doc', v: 1 }, '"20.0.1"'))
      .mockResolvedValueOnce(content({ type: 'doc', v: 2 }, '"20.0.2"'))
    const presentation = makePresentation({ key: 'vaulter/tiptap', documentType: 'richtext', handler: null })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: makeDocument({ id: '20', presentation }), debounceMs: 0 },
      slots: { default: `<template #default="{ setContent }"><button class="type" @click="setContent({ type: 'doc', mine: true })">x</button></template>` },
      ...withClient(client),
    })
    await flushAll()

    await wrapper.get('.type').trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 5))
    await flushAll()

    expect(update).toHaveBeenCalledWith('20', { type: 'doc', mine: true }, { etag: '"20.0.1"' })
    expect(wrapper.get('.vaulter-document-node__conflict').text()).toContain('Someone saved this document first')
    expect(wrapper.find('.vaulter-document-node__error').exists()).toBe(false)
  })

  it('renders the fallback note when no editor is mounted in the slot', async () => {
    const { client } = docClient()
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: makeDocument({ id: '20' }) },
      ...withClient(client),
    })

    await flushAll()

    expect(wrapper.find('.vaulter-document-node__fallback').exists()).toBe(true)
    expect(wrapper.text()).toContain('vaulter/tiptap')
  })

  it('shows a load failure in the error region', async () => {
    const { client, contentFn } = docClient()
    contentFn.mockRejectedValue(new Error('boom'))
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: makeDocument({ id: '20' }) },
      ...withClient(client),
    })

    await flushAll()

    expect(wrapper.get('.vaulter-document-node__error').text()).toBe('Could not load this document.')
  })

  it('accepts a host-provided editor component through the slot using h()', async () => {
    const { client } = docClient()
    const Probe = defineComponent({ props: { content: { type: Object, default: null } }, render() { return h('i', { class: 'probe' }, String(Boolean(this.content))) } })
    const presentation = makePresentation({ key: 'vaulter/tiptap', documentType: 'richtext', handler: null })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: makeDocument({ id: '20', presentation }) },
      slots: { default: ({ content: loaded }: { content: object | null }) => h(Probe, { content: loaded ?? undefined }) },
      ...withClient(client),
    })

    await flushAll()

    expect(wrapper.get('.probe').text()).toBe('true')
  })

  it('two overlapping load() calls: the superseded one is not a failed session, and the editor stays editable', async () => {
    let releaseFirst!: (session: CollaborationSession) => void
    let releaseSecond!: (session: CollaborationSession) => void
    const { client, collaborationSession } = docClient({ session: hocuspocusSession(false) })
    collaborationSession
      .mockReset()
      .mockReturnValueOnce(new Promise<CollaborationSession>((resolve) => { releaseFirst = resolve }))
      .mockReturnValueOnce(new Promise<CollaborationSession>((resolve) => { releaseSecond = resolve }))
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), collaborationProviders: stubProviders(), autoLoad: false },
      slots: { default: editorSlot },
      ...withClient(client),
    })
    const load = (wrapper.vm as unknown as { load: () => Promise<void> }).load

    const older = load()
    await flushAll()
    const newer = load()
    await flushAll()
    // The superseded session answers first, while the winner is still waiting for its own.
    releaseFirst(hocuspocusSession(false))
    await flushAll()
    releaseSecond(hocuspocusSession(false))
    await Promise.allSettled([older, newer])
    await flushAll()

    expect(wrapper.emitted('collaboration-failed')).toBeUndefined()
    const editor = wrapper.get('.stub-editor')
    expect(editor.attributes('data-collaborative')).toBe('true')
    expect(editor.attributes('data-readonly')).toBe('false')
  })

  it('a successful session clears an earlier failure, so the editor is no longer read-only', async () => {
    const { client, collaborationSession } = docClient({ session: hocuspocusSession(false) })
    collaborationSession
      .mockReset()
      .mockRejectedValueOnce(new VaulterApiError({ status: 503, code: 'http_503' }))
      .mockResolvedValueOnce(hocuspocusSession(false))
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), collaborationProviders: stubProviders() },
      slots: { default: editorSlot },
      ...withClient(client),
    })
    await flushAll()
    expect(wrapper.get('.stub-editor').attributes('data-readonly')).toBe('true')

    await (wrapper.vm as unknown as { load: () => Promise<void> }).load()
    await flushAll()

    expect(wrapper.get('.stub-editor').attributes('data-readonly')).toBe('false')
  })
})

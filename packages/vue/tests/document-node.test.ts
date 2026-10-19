import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref, shallowRef } from 'vue'
import { mount } from '@vue/test-utils'
import VaulterDocumentNode from '../src/components/VaulterDocumentNode.vue'
import { VaulterApiError } from '../src/api/errors'
import type { CollaborationAdapter, CollaborationHandle, CollaborationStatus } from '../src/collaboration-adapter'
import type { Node } from '../src/api/types'
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

/** A fake session: an opaque document, refs the test can flip, and an `end` spy. */
function fakeHandle(overrides: {
  readOnly?: boolean
  schemaOutdated?: boolean
  schemaVersion?: number | null
  status?: CollaborationStatus
} = {}) {
  const doc = shallowRef<{ id: number }>({ id: 1 })
  const status = ref<CollaborationStatus>(overrides.status ?? 'synchronized')
  const readOnly = ref(overrides.readOnly ?? false)
  const end = vi.fn()
  const handle: CollaborationHandle = {
    get doc() { return doc.value },
    status,
    readOnly,
    connected: ref(true),
    collaborators: ref([]),
    schemaOutdated: ref(overrides.schemaOutdated ?? false),
    schemaVersion: ref(overrides.schemaVersion ?? 1),
    end,
  }

  return { handle, doc, status, readOnly, end }
}

/** An adapter that opens the given handle (or runs the given `open`). */
function fakeAdapter(open: CollaborationAdapter['open']) {
  const spy = vi.fn(open)
  const adapter: CollaborationAdapter = { open: spy }

  return { adapter, open: spy }
}

function collaborativeNode(overrides: Parameters<typeof makeDocument>[0] = {}): Node {
  const presentation = makePresentation({ key: 'vaulter/tiptap', documentType: 'richtext', handler: null })
  presentation.capabilities.collaborative = true

  return makeDocument({ id: '20', presentation, ...overrides })
}

/** A fake client: the document content and the REST autosave. */
function docClient() {
  const update = vi.fn().mockResolvedValue(content({ type: 'doc', saved: true }, '"20.0.2"'))
  const contentFn = vi.fn().mockResolvedValue(content({ type: 'doc', content: [] }))

  return { client: { documents: { content: contentFn, update } }, update, contentFn }
}

/** An editor in the default slot: renders the mode flags so a test can assert them. */
const editorSlot = `
  <template #default="{ collaborative, readonly, ydoc, content, status }">
    <div
      class="stub-editor"
      :data-collaborative="collaborative ? 'true' : 'false'"
      :data-readonly="readonly ? 'true' : 'false'"
      :data-has-ydoc="ydoc ? 'true' : 'false'"
      :data-ydoc-id="ydoc ? ydoc.id : ''"
      :data-has-content="content ? 'true' : 'false'"
      :data-status="status ?? ''"
    >editor-mounted</div>
  </template>`

describe('VaulterDocumentNode', () => {
  it('mounts the slot editor with the loaded content for a non-collaborative document', async () => {
    const { client } = docClient()
    const { adapter, open } = fakeAdapter(async () => fakeHandle().handle)
    const presentation = makePresentation({ key: 'vaulter/tiptap', documentType: 'richtext', handler: null })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: makeDocument({ id: '20', presentation }) },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
    })

    await flushAll()

    const editor = wrapper.get('.stub-editor')
    expect(editor.attributes('data-collaborative')).toBe('false')
    expect(editor.attributes('data-has-content')).toBe('true')
    expect(wrapper.emitted('ready')?.[0]?.[0]).toMatchObject({ id: '20' })
    // Never opens a session for a document that is not collaborative.
    expect(open).not.toHaveBeenCalled()
    expect(wrapper.attributes('data-document-type')).toBe('richtext')
  })

  it('mounts the editor in COLLABORATIVE mode for a collaborative document', async () => {
    const { client, update } = docClient()
    const { adapter, open } = fakeAdapter(async () => fakeHandle().handle)
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
    })

    await flushAll()

    expect(open).toHaveBeenCalledOnce()
    expect(open.mock.calls[0][0]).toBe('20')
    expect(open.mock.calls[0][1].client).toBe(client)
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
    const { client } = docClient()
    const { adapter, open } = fakeAdapter(async () => fakeHandle({ readOnly: true, schemaOutdated: true, schemaVersion: 2 }).handle)
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), supportedSchemaVersion: 1 },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
    })

    await flushAll()

    expect(open.mock.calls[0][1]).toMatchObject({ supportedSchemaVersion: 1 })
    expect(wrapper.get('.stub-editor').attributes('data-readonly')).toBe('true')
    expect(wrapper.emitted('schema-outdated')?.[0]).toEqual([2])
  })

  it('respects a read-only collaboration session', async () => {
    const { client } = docClient()
    const { adapter } = fakeAdapter(async () => fakeHandle({ readOnly: true }).handle)
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
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
    const { client } = docClient()
    const { adapter } = fakeAdapter(async () => { throw unavailable })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
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
    const { client, update } = docClient()
    const { adapter } = fakeAdapter(async () => { throw failure })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
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
    const first = fakeHandle()
    const second = fakeHandle()
    let releaseFirst!: (handle: CollaborationHandle) => void
    let releaseSecond!: (handle: CollaborationHandle) => void
    const { client } = docClient()
    const { adapter } = fakeAdapter(vi.fn()
      .mockReturnValueOnce(new Promise<CollaborationHandle>((resolve) => { releaseFirst = resolve }))
      .mockReturnValueOnce(new Promise<CollaborationHandle>((resolve) => { releaseSecond = resolve })))
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), autoLoad: false },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
    })
    const load = (wrapper.vm as unknown as { load: () => Promise<void> }).load

    const older = load()
    await flushAll()
    const newer = load()
    await flushAll()
    // The superseded session answers first, while the winner is still waiting for its own.
    releaseFirst(first.handle)
    await flushAll()
    releaseSecond(second.handle)
    await Promise.allSettled([older, newer])
    await flushAll()

    expect(wrapper.emitted('collaboration-failed')).toBeUndefined()
    expect(first.end).toHaveBeenCalledOnce()
    expect(second.end).not.toHaveBeenCalled()
    const editor = wrapper.get('.stub-editor')
    expect(editor.attributes('data-collaborative')).toBe('true')
    expect(editor.attributes('data-readonly')).toBe('false')
  })

  it('a successful session clears an earlier failure, so the editor is no longer read-only', async () => {
    const { client } = docClient()
    const { adapter } = fakeAdapter(vi.fn()
      .mockRejectedValueOnce(new VaulterApiError({ status: 503, code: 'http_503' }))
      .mockResolvedValueOnce(fakeHandle().handle))
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
    })
    await flushAll()
    expect(wrapper.get('.stub-editor').attributes('data-readonly')).toBe('true')

    await (wrapper.vm as unknown as { load: () => Promise<void> }).load()
    await flushAll()

    expect(wrapper.get('.stub-editor').attributes('data-readonly')).toBe('false')
  })

  it('falls back to the autosave path when the module route answers 404', async () => {
    const { client, update } = docClient()
    const { adapter } = fakeAdapter(async () => { throw new VaulterApiError({ status: 404, code: 'http_404' }) })
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), debounceMs: 0 },
      slots: { default: `<template #default="{ readonly, collaborative, setContent }"><button class="type" :data-readonly="readonly" :data-collaborative="collaborative" @click="setContent({ type: 'doc', typed: true })">x</button></template>` },
      ...withClient(client, { collaboration: adapter }),
    })
    await flushAll()

    const button = wrapper.get('.type')
    expect(button.attributes('data-collaborative')).toBe('false')
    expect(button.attributes('data-readonly')).toBe('false')
    expect(wrapper.emitted('collaboration-failed')).toBeUndefined()

    await button.trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 5))

    expect(update).toHaveBeenCalledWith('20', { type: 'doc', typed: true }, { etag: '"20.0.1"' })
  })

  it('a collaborative document without an installed adapter is edited through the REST autosave', async () => {
    const { client, update } = docClient()
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), debounceMs: 0 },
      slots: { default: `<template #default="{ readonly, collaborative, ydoc, content, setContent }"><button class="type" :data-readonly="readonly" :data-collaborative="collaborative" :data-has-ydoc="ydoc ? 'true' : 'false'" :data-has-content="content ? 'true' : 'false'" @click="setContent({ type: 'doc', typed: true })">x</button></template>` },
      ...withClient(client),
    })
    await flushAll()

    const button = wrapper.get('.type')
    expect(button.attributes('data-collaborative')).toBe('false')
    expect(button.attributes('data-readonly')).toBe('false')
    expect(button.attributes('data-has-ydoc')).toBe('false')
    expect(button.attributes('data-has-content')).toBe('true')
    expect(wrapper.emitted('collaboration-failed')).toBeUndefined()

    await button.trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 5))

    expect(update).toHaveBeenCalledWith('20', { type: 'doc', typed: true }, { etag: '"20.0.1"' })
  })

  it('does not open a session when collaboration is switched off by the prop', async () => {
    const { client } = docClient()
    const { adapter, open } = fakeAdapter(async () => fakeHandle().handle)
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), collaboration: false },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
    })

    await flushAll()

    expect(open).not.toHaveBeenCalled()
    expect(wrapper.get('.stub-editor').attributes('data-collaborative')).toBe('false')
  })

  it('does not autosave in collaborative mode: the session persists the edits', async () => {
    const { client, update } = docClient()
    const { adapter } = fakeAdapter(async () => fakeHandle().handle)
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode(), debounceMs: 0 },
      slots: { default: `<template #default="{ setContent }"><button class="type" @click="setContent({ type: 'doc', typed: true })">x</button></template>` },
      ...withClient(client, { collaboration: adapter }),
    })
    await flushAll()

    await wrapper.get('.type').trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 5))

    expect(update).not.toHaveBeenCalled()
  })

  it('passes the status of the session to the slot and rebinds a replaced document', async () => {
    const { client } = docClient()
    const session = fakeHandle({ status: 'pending' })
    const { adapter } = fakeAdapter(async () => session.handle)
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
    })
    await flushAll()
    const editor = () => wrapper.get('.stub-editor')

    expect(editor().attributes('data-status')).toBe('pending')
    expect(editor().attributes('data-ydoc-id')).toBe('1')

    session.status.value = 'saved'
    session.doc.value = { id: 2 }
    await flushAll()

    expect(editor().attributes('data-status')).toBe('saved')
    expect(editor().attributes('data-ydoc-id')).toBe('2')
  })

  it('goes read-only when the session turns read-only (a status error stops local edits)', async () => {
    const { client } = docClient()
    const session = fakeHandle()
    const { adapter } = fakeAdapter(async () => session.handle)
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
    })
    await flushAll()
    expect(wrapper.get('.stub-editor').attributes('data-readonly')).toBe('false')

    session.status.value = 'error'
    session.readOnly.value = true
    await flushAll()

    expect(wrapper.get('.stub-editor').attributes('data-readonly')).toBe('true')
  })

  it('re-reads the content when the session reports a new generation (onEpochChanged)', async () => {
    const { client, contentFn } = docClient()
    const { adapter, open } = fakeAdapter(async () => fakeHandle().handle)
    mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
    })
    await flushAll()
    const before = contentFn.mock.calls.length

    await open.mock.calls[0][1].onEpochChanged?.()

    expect(contentFn.mock.calls.length).toBe(before + 1)
  })

  it('ends the session when the component unmounts, and a session that answers afterwards', async () => {
    const live = fakeHandle()
    const late = fakeHandle()
    let releaseLate!: (handle: CollaborationHandle) => void
    const { client } = docClient()
    const { adapter } = fakeAdapter(vi.fn()
      .mockResolvedValueOnce(live.handle)
      .mockReturnValueOnce(new Promise<CollaborationHandle>((resolve) => { releaseLate = resolve })))
    const wrapper = mount(VaulterDocumentNode, {
      props: { node: collaborativeNode() },
      slots: { default: editorSlot },
      ...withClient(client, { collaboration: adapter }),
    })
    await flushAll()
    const pending = (wrapper.vm as unknown as { load: () => Promise<void> }).load()
    await flushAll()
    expect(live.end).toHaveBeenCalledOnce()

    wrapper.unmount()
    releaseLate(late.handle)
    await pending
    await flushAll()

    expect(late.end).toHaveBeenCalledOnce()
  })
})

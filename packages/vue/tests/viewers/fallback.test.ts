import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import VaulterViewer from '../../src/viewers/VaulterViewer.vue'
import VaulterUnsupportedNode from '../../src/components/VaulterUnsupportedNode.vue'
import { builtinViewers, VIEWER_KEYS } from '../../src/viewers/builtin'
import { createViewerRegistry, viewerRegistryKey, type ViewerEntry } from '../../src/viewers/registry'
import { vaulterClientKey } from '../../src/composables/useVaulterClient'
import { createVaulterI18n, vaulterI18nKey, type VaulterI18nOptions } from '../../src/i18n'
import { makeDocument, makeFile, makePresentation, settle, stubClient } from '../support/nodes'
import type { Node } from '../../src/api/types'

function mountViewer(node: Node, options: { viewers?: Record<string, ViewerEntry>, i18n?: VaulterI18nOptions, respond?: Parameters<typeof stubClient>[0] } = {}) {
  const { client, calls } = stubClient(options.respond ?? (() => undefined))
  const wrapper = mount(VaulterViewer, {
    props: { node },
    global: {
      provide: {
        [vaulterClientKey as symbol]: client,
        [viewerRegistryKey as symbol]: createViewerRegistry({ ...builtinViewers(), ...options.viewers }),
        [vaulterI18nKey as symbol]: createVaulterI18n(options.i18n),
      },
    },
  })

  return { wrapper, calls }
}

describe('VaulterViewer — registered viewers', () => {
  it('mounts the viewer registered under presentation.viewer.key with the node', async () => {
    const seen = vi.fn()
    const Diagram = defineComponent({
      props: { node: { type: Object, required: true }, readonly: Boolean, theme: { type: String, default: '' } },
      setup(props) {
        seen(props)
        return () => h('div', { class: 'diagram' }, `diagram:${props.theme}`)
      },
    })
    const node = makeFile({ name: 'flow.diagram', presentation: makePresentation({ key: 'acme/diagram', props: { theme: 'dark' } }) })

    const { wrapper } = mountViewer(node, { viewers: { 'acme/diagram': Diagram } })
    await settle()

    expect(wrapper.find('.diagram').text()).toBe('diagram:dark')
    expect(seen.mock.calls[0][0].node).toStrictEqual(node)
    expect(seen.mock.calls[0][0].readonly).toBe(false)
  })

  it('never lets server viewer props override the node', async () => {
    const seen = vi.fn()
    const Probe = defineComponent({
      props: { node: { type: Object, required: true } },
      setup(props) {
        seen(props.node)
        return () => h('i')
      },
    })
    const node = makeFile({ presentation: makePresentation({ key: 'acme/probe', props: { node: 'evil' } }) })

    mountViewer(node, { viewers: { 'acme/probe': Probe } })
    await settle()

    expect(seen.mock.calls[0][0]).toStrictEqual(node)
  })

  it('mounts a lazily loaded viewer', async () => {
    const Lazy = defineComponent({ render: () => h('p', { class: 'lazy' }, 'ready') })
    const node = makeFile({ presentation: makePresentation({ key: 'acme/lazy' }) })

    const { wrapper } = mountViewer(node, { viewers: { 'acme/lazy': () => Promise.resolve({ default: Lazy }) } })
    await settle()

    expect(wrapper.find('.lazy').exists()).toBe(true)
  })
})

describe('VaulterViewer — fallback (V37)', () => {
  it('an unknown viewer key mounts VaulterUnsupportedNode with the name and a download button', async () => {
    const node = makeFile({ name: 'model.acme', presentation: makePresentation({ key: 'acme/unknown' }), abilities: { download: true } })

    const { wrapper } = mountViewer(node)
    await settle()

    expect(wrapper.findComponent(VaulterUnsupportedNode).exists()).toBe(true)
    expect(wrapper.text()).toContain('model.acme')
    const button = wrapper.find('button.vaulter-unsupported-node__download')
    expect(button.exists()).toBe(true)
    expect(button.text()).toBe('Download')
  })

  it('the download button says «Скачать» in the ru locale', async () => {
    const node = makeFile({ presentation: makePresentation({ key: 'acme/unknown' }) })

    const { wrapper } = mountViewer(node, { i18n: { locale: 'ru' } })
    await settle()

    expect(wrapper.find('button').text()).toBe('Скачать')
  })

  it('has no download button when abilities.download is false (previewer)', async () => {
    const node = makeFile({ presentation: makePresentation({ key: 'acme/unknown' }), abilities: { download: false } })

    const { wrapper } = mountViewer(node)
    await settle()

    expect(wrapper.findComponent(VaulterUnsupportedNode).exists()).toBe(true)
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('the download button opens the file download URL of the client', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    const node = makeFile({ id: '55', presentation: makePresentation({ key: 'acme/unknown' }) })

    const { wrapper } = mountViewer(node)
    await settle()
    await wrapper.find('button').trigger('click')

    expect(open).toHaveBeenCalledWith('/api/vaulter/v1/files/55/download', '_blank', 'noopener')
    expect(wrapper.findComponent(VaulterUnsupportedNode).emitted('download')).toHaveLength(1)
    open.mockRestore()
  })

  it('a document whose type is not available shows the fallback with its document_type', async () => {
    const node = makeDocument({
      name: 'Budget',
      presentation: makePresentation({ key: 'vaulter/tiptap', documentType: 'acme.budget', typeAvailable: false, handler: null }),
    })

    const { wrapper } = mountViewer(node)
    await settle()

    const fallback = wrapper.findComponent(VaulterUnsupportedNode)
    expect(fallback.exists()).toBe(true)
    expect(fallback.text()).toContain('Budget')
    expect(fallback.text()).toContain('acme.budget')
    expect(fallback.text()).toContain('"acme.budget" is not available')
    // Documents have no byte stream to download.
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('a node without a presentation (a folder) falls back too', async () => {
    const { wrapper } = mountViewer(makeFile({ presentation: null, name: 'Folder-ish' }))
    await settle()

    expect(wrapper.findComponent(VaulterUnsupportedNode).exists()).toBe(true)
  })

  it('a null viewer key falls back', async () => {
    const { wrapper } = mountViewer(makeFile({ presentation: makePresentation({ key: null }) }))
    await settle()

    expect(wrapper.findComponent(VaulterUnsupportedNode).exists()).toBe(true)
  })

  it('the host can replace the fallback viewer itself', async () => {
    const Mine = defineComponent({ render: () => h('div', { class: 'mine' }, 'custom fallback') })
    const node = makeFile({ presentation: makePresentation({ key: 'acme/unknown' }) })

    const { wrapper } = mountViewer(node, { viewers: { [VIEWER_KEYS.unsupported]: Mine } })
    await settle()

    expect(wrapper.find('.mine').exists()).toBe(true)
  })
})

describe('built-in preview viewers', () => {
  it('download viewer links the file only when abilities.download', async () => {
    const allowed = mountViewer(makeFile({ id: '7', presentation: makePresentation({ key: VIEWER_KEYS.download }) }))
    await settle()
    const denied = mountViewer(makeFile({ presentation: makePresentation({ key: VIEWER_KEYS.download }), abilities: { download: false } }))
    await settle()

    expect(allowed.wrapper.find('a').attributes('href')).toBe('/api/vaulter/v1/files/7/download')
    expect(denied.wrapper.find('a').exists()).toBe(false)
  })

  it('image preview shows the authorised bytes through an object URL, and nothing for a previewer', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:image-1')
    URL.revokeObjectURL = vi.fn()
    const respond = () => new Response('png', { status: 200, headers: { 'X-Vaulter-Api-Version': '1' } })
    const node = makeFile({ id: '8', name: 'cat.png', presentation: makePresentation({ key: VIEWER_KEYS.imagePreview }) })

    const allowed = mountViewer(node, { respond })
    await settle()
    const denied = mountViewer(makeFile({ presentation: makePresentation({ key: VIEWER_KEYS.imagePreview }), abilities: { download: false } }), { respond })
    await settle()

    expect(allowed.wrapper.find('img').attributes('src')).toBe('blob:image-1')
    expect(allowed.wrapper.find('img').attributes('alt')).toBe('cat.png')
    expect(allowed.calls[0].url).toBe('/files/8/download')
    expect(denied.wrapper.find('img').exists()).toBe(false)
    expect(denied.calls).toHaveLength(0)

    allowed.wrapper.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:image-1')
  })

  it('pdf preview reports a failed download', async () => {
    const respond = () => new Response('{}', { status: 500, headers: { 'Content-Type': 'application/json' } })
    const node = makeFile({ presentation: makePresentation({ key: VIEWER_KEYS.pdfPreview }) })

    const { wrapper } = mountViewer(node, { respond })
    await settle()

    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').text()).toBe('The preview could not be loaded.')
  })

  it('image preview shows a failure instead of a broken image when the bytes are unavailable', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:unavailable')
    const respond = () => new Response(JSON.stringify({
      code: 'content_unavailable',
      status: 423,
      title: 'Content unavailable',
      request_id: 'req-1',
    }), {
      status: 423,
      headers: { 'Content-Type': 'application/problem+json', 'X-Vaulter-Api-Version': '1' },
    })
    const node = makeFile({ id: '8', presentation: makePresentation({ key: VIEWER_KEYS.imagePreview }) })

    const { wrapper } = mountViewer(node, { respond })
    await settle()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').text()).toBe('The preview could not be loaded.')
    expect(URL.createObjectURL).not.toHaveBeenCalled()
  })

  it('pdf preview shows a failure instead of a frame when the bytes are unavailable', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:unavailable-pdf')
    const respond = () => new Response(JSON.stringify({
      code: 'content_unavailable',
      status: 423,
      title: 'Content unavailable',
      request_id: 'req-1',
    }), {
      status: 423,
      headers: { 'Content-Type': 'application/problem+json', 'X-Vaulter-Api-Version': '1' },
    })
    const node = makeFile({ presentation: makePresentation({ key: VIEWER_KEYS.pdfPreview }) })

    const { wrapper } = mountViewer(node, { respond })
    await settle()

    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').text()).toBe('The preview could not be loaded.')
    expect(URL.createObjectURL).not.toHaveBeenCalled()
  })

  it('image preview links the download URL when direct_signed redirects', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:redirected')
    const respond = () => new Response(null, {
      status: 302,
      headers: { Location: 'https://signed.example/cat.png', 'X-Vaulter-Api-Version': '1' },
    })
    const node = makeFile({ id: '8', presentation: makePresentation({ key: VIEWER_KEYS.imagePreview }) })

    const { wrapper, calls } = mountViewer(node, { respond })
    await settle()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('a').attributes('href')).toBe('/api/vaulter/v1/files/8/download')
    expect(wrapper.find('a').text()).toBe('Download')
    expect(calls).toHaveLength(1)
    expect(calls[0].url).toBe('/files/8/download')
    expect(URL.createObjectURL).not.toHaveBeenCalled()
  })

  it('pdf preview links the download URL when direct_signed redirects', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:redirected-pdf')
    const respond = () => new Response(null, {
      status: 302,
      headers: { Location: 'https://signed.example/doc.pdf', 'X-Vaulter-Api-Version': '1' },
    })
    const node = makeFile({ id: '11', presentation: makePresentation({ key: VIEWER_KEYS.pdfPreview }) })

    const { wrapper, calls } = mountViewer(node, { respond })
    await settle()

    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.find('a').attributes('href')).toBe('/api/vaulter/v1/files/11/download')
    expect(calls).toHaveLength(1)
    expect(URL.createObjectURL).not.toHaveBeenCalled()
  })
})

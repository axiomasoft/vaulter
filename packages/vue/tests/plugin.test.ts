import { describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, h, inject } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { VaulterPlugin, provideVaulterClient, provideViewerRegistry } from '../src/plugin'
import { useVaulterClient } from '../src/composables/useVaulterClient'
import { useViewerRegistry, createViewerRegistry } from '../src/viewers/registry'
import { useVaulterI18n } from '../src/i18n'
import { createVaulterClient } from '../src/api/client'
import { VaulterCollaborationKey, type CollaborationAdapter } from '../src/collaboration-adapter'
import { VIEWER_KEYS } from '../src/viewers/builtin'
import VaulterViewer from '../src/viewers/VaulterViewer.vue'
import { makeFile, makePresentation } from './support/nodes'

const Probe = defineComponent({
  setup(_props, { expose }) {
    expose({ client: useVaulterClient(), registry: useViewerRegistry(), i18n: useVaulterI18n() })
    return () => h('span')
  },
})

describe('VaulterPlugin', () => {
  it('provides the client, a per-app viewer registry with the five built-in keys, and i18n', () => {
    const wrapper = mount(Probe, {
      global: { plugins: [[VaulterPlugin, { baseUrl: '/api/vaulter/v1' }]] },
    })
    const probe = wrapper.vm as unknown as ReturnType<typeof useViewerRegistry> extends never ? never : {
      client: ReturnType<typeof useVaulterClient>
      registry: ReturnType<typeof useViewerRegistry>
      i18n: ReturnType<typeof useVaulterI18n>
    }

    expect(typeof probe.client.nodes.get).toBe('function')
    expect(probe.registry.keys().sort()).toEqual(Object.values(VIEWER_KEYS).sort())
    expect(probe.i18n.locale).toBe('en')
  })

  it('merges host viewers and replaces a built-in key', () => {
    const Mine = defineComponent({ render: () => h('b', 'mine') })
    const Diagram = defineComponent({ render: () => h('b', 'diagram') })
    const wrapper = mount(Probe, {
      global: { plugins: [[VaulterPlugin, { viewers: { [VIEWER_KEYS.download]: Mine, 'acme/diagram': Diagram } }]] },
    })
    const registry = (wrapper.vm as unknown as { registry: ReturnType<typeof useViewerRegistry> }).registry

    expect(registry.resolve(VIEWER_KEYS.download)).toBe(Mine)
    expect(registry.resolve('acme/diagram')).toBe(Diagram)
    expect(registry.has(VIEWER_KEYS.pdfPreview)).toBe(true)
  })

  it('uses the client given in the options instead of building one', () => {
    const client = createVaulterClient({ fetch: vi.fn() })
    const wrapper = mount(Probe, { global: { plugins: [[VaulterPlugin, { client }]] } })

    expect((wrapper.vm as unknown as { client: unknown }).client).toBe(client)
  })

  it('passes i18n options through: the ru locale and message overrides', () => {
    const wrapper = mount(Probe, {
      global: {
        plugins: [[VaulterPlugin, { i18n: { locale: 'ru', messages: { ru: { 'viewer.download': 'Забрать' } } } }]],
      },
    })
    const { t } = (wrapper.vm as unknown as { i18n: ReturnType<typeof useVaulterI18n> }).i18n

    expect(t('viewer.download')).toBe('Забрать')
    expect(t('viewer.loading')).toBe('Загрузка…')
  })

  it('falls back to English per key for an unknown locale and fills placeholders', () => {
    const wrapper = mount(Probe, {
      global: { plugins: [[VaulterPlugin, { i18n: { locale: 'de' } }]] },
    })
    const { t } = (wrapper.vm as unknown as { i18n: ReturnType<typeof useVaulterI18n> }).i18n

    expect(t('viewer.download')).toBe('Download')
    expect(t('viewer.unsupported.typeUnavailable', { type: 'acme.sheet' })).toBe(
      'The document type "acme.sheet" is not available in this application.',
    )
  })

  it('registers every component globally', () => {
    const app = createApp({ render: () => h('div') })
    app.use(VaulterPlugin, {})

    for (const name of [
      'VaulterBreadcrumb', 'VaulterCommentsPanel', 'VaulterDocumentNode', 'VaulterDrive', 'VaulterFolderPicker',
      'VaulterGrantsPanel', 'VaulterNameDialog', 'VaulterNodeList', 'VaulterSharedWithMe', 'VaulterSharePanel',
      'VaulterUnsupportedNode', 'VaulterUploader', 'VaulterViewer',
    ]) {
      expect(app.component(name), name).toBeTruthy()
    }
  })

  it('end to end: an installed app mounts the built-in download viewer for a node that names it', async () => {
    const node = makeFile({ id: '9', name: 'plain.bin', presentation: makePresentation({ key: VIEWER_KEYS.download }) })
    const wrapper = mount(VaulterViewer, { props: { node }, global: { plugins: [[VaulterPlugin, {}]] } })
    await vi.dynamicImportSettled()
    await flushPromises()

    expect(wrapper.find('.vaulter-download-viewer').exists()).toBe(true)
    expect(wrapper.get('a').attributes('href')).toBe('/api/vaulter/v1/files/9/download')
  })

  it('provides the collaboration adapter given in the options, and none otherwise', () => {
    const adapter: CollaborationAdapter = { open: vi.fn() }
    const AdapterProbe = defineComponent({
      setup(_props, { expose }) {
        expose({ adapter: inject(VaulterCollaborationKey, null) })
        return () => h('span')
      },
    })
    const withAdapter = mount(AdapterProbe, { global: { plugins: [[VaulterPlugin, { collaboration: adapter }]] } })
    const without = mount(AdapterProbe, { global: { plugins: [[VaulterPlugin, {}]] } })

    expect((withAdapter.vm as unknown as { adapter: unknown }).adapter).toBe(adapter)
    expect((without.vm as unknown as { adapter: unknown }).adapter).toBeNull()
  })

  it('provide helpers wire a client and a registry into an app by hand', () => {
    const client = createVaulterClient({ fetch: vi.fn() })
    const registry = createViewerRegistry()
    const app = createApp(Probe)
    provideVaulterClient(app, client)
    provideViewerRegistry(app, registry)
    const instance = app.mount(document.createElement('div')) as unknown as { client: unknown, registry: unknown }

    expect(instance.client).toBe(client)
    expect(instance.registry).toBe(registry)
  })
})

import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import VaulterDocumentNode from '../src/components/VaulterDocumentNode.vue'
import {
  clearEngineComponents,
  registerEngineComponent,
  registeredEngineComponentIds,
  resolveEngineComponent,
  unregisterEngineComponent,
} from '../src/engineRegistry'
import { docVaultClientKey } from '../src/composables/useVaulterClient'
import { VaulterApiError, type VaulterClient } from '../src/client'
import type { HandlerDescriptor, TipTapDocument } from '../src/types'

function handler(overrides: Partial<HandlerDescriptor> = {}): HandlerDescriptor {
  return {
    id: 'custom-engine',
    capabilities: {
      editable: true,
      previewable: true,
      downloadable: true,
      thumbnailable: false,
      collaborative: false,
    },
    ui: { component: 'AppCustomEngine', route: null, props: {} },
    ...overrides,
  }
}

function mockClient(descriptor: HandlerDescriptor, content: TipTapDocument | null = null): VaulterClient {
  return {
    documentNodes: {
      content: vi.fn().mockResolvedValue({ id: 1, content, format: null } as never),
      handler: vi.fn().mockResolvedValue(descriptor),
    },
  } as unknown as VaulterClient
}

function withClient(client: VaulterClient) {
  return { global: { provide: { [docVaultClientKey as symbol]: client } } }
}

describe('engineRegistry', () => {
  afterEach(() => {
    clearEngineComponents()
  })

  it('registers, resolves and unregisters a component by id', () => {
    const Comp = defineComponent({ render: () => h('div') })
    expect(resolveEngineComponent('my-engine')).toBeUndefined()

    registerEngineComponent('my-engine', Comp)
    expect(resolveEngineComponent('my-engine')).toBe(Comp)
    expect(registeredEngineComponentIds()).toContain('my-engine')

    unregisterEngineComponent('my-engine')
    expect(resolveEngineComponent('my-engine')).toBeUndefined()
  })

  it('overwrites a previously registered component under the same id', () => {
    const First = defineComponent({ render: () => h('div') })
    const Second = defineComponent({ render: () => h('span') })

    registerEngineComponent('my-engine', First)
    registerEngineComponent('my-engine', Second)

    expect(resolveEngineComponent('my-engine')).toBe(Second)
  })

  it('clearEngineComponents empties the registry', () => {
    registerEngineComponent('a', defineComponent({ render: () => h('div') }))
    registerEngineComponent('b', defineComponent({ render: () => h('div') }))

    clearEngineComponents()

    expect(registeredEngineComponentIds()).toEqual([])
  })
})

describe('VaulterDocumentNode — engine resolution', () => {
  afterEach(() => {
    clearEngineComponents()
  })

  it('mounts a component registered via the global registry when componentMap does not cover it', async () => {
    const AppCustomEngine = defineComponent({
      name: 'AppCustomEngine',
      render: () => h('div', { class: 'app-custom-engine' }),
    })
    registerEngineComponent('AppCustomEngine', AppCustomEngine)

    const descriptor = handler()
    const wrapper = mount(VaulterDocumentNode, {
      props: { nodeId: 1 },
      ...withClient(mockClient(descriptor)),
    })
    await flushPromises()

    expect(wrapper.find('.app-custom-engine').exists()).toBe(true)
  })

  it('prefers the componentMap prop over a same-id global registration', async () => {
    const FromRegistry = defineComponent({ render: () => h('div', { class: 'from-registry' }) })
    const FromProp = defineComponent({ render: () => h('div', { class: 'from-prop' }) })
    registerEngineComponent('AppCustomEngine', FromRegistry)

    const descriptor = handler()
    const wrapper = mount(VaulterDocumentNode, {
      props: { nodeId: 1, componentMap: { AppCustomEngine: FromProp } },
      ...withClient(mockClient(descriptor)),
    })
    await flushPromises()

    expect(wrapper.find('.from-prop').exists()).toBe(true)
    expect(wrapper.find('.from-registry').exists()).toBe(false)
  })

  it('falls back to the fallback slot when the handler is unmapped in both sources', async () => {
    const descriptor = handler({ id: 'unmapped-engine', ui: { component: 'AppUnmapped', route: null, props: {} } })
    const wrapper = mount(VaulterDocumentNode, {
      props: { nodeId: 1 },
      ...withClient(mockClient(descriptor)),
    })
    await flushPromises()

    expect(wrapper.find('.vaulter-document-node__fallback').exists()).toBe(true)
  })

  it('warns once (not repeated) in dev mode for an unmapped handler', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const descriptor = handler({ id: 'unmapped-engine', ui: { component: 'AppUnmapped', route: null, props: {} } })
    const wrapper = mount(VaulterDocumentNode, {
      props: { nodeId: 1 },
      ...withClient(mockClient(descriptor)),
    })
    await flushPromises()
    // Trigger a second reactive pass over the same resolved (unmapped) handler —
    // the warning must not repeat within this component instance.
    await wrapper.setProps({ nodeId: 1 })
    await flushPromises()

    const ownWarnings = warn.mock.calls.filter(([msg]) => String(msg).includes('unmapped-engine'))
    expect(ownWarnings).toHaveLength(1)
    expect(String(ownWarnings[0][0])).toContain('AppUnmapped')
    warn.mockRestore()
  })

  it('does not warn when a handler resolves via the global registry', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    registerEngineComponent('AppCustomEngine', defineComponent({ render: () => h('div') }))
    const descriptor = handler()
    mount(VaulterDocumentNode, {
      props: { nodeId: 1 },
      ...withClient(mockClient(descriptor)),
    })
    await flushPromises()

    expect(warn.mock.calls.filter(([msg]) => String(msg).includes('custom-engine'))).toHaveLength(0)
    warn.mockRestore()
  })
})

describe('VaulterDocumentNode — collaboration session failures', () => {
  afterEach(() => {
    clearEngineComponents()
  })

  const doc: TipTapDocument = { type: 'doc', content: [] } as never

  function collaborativeClient(token: () => Promise<unknown>): VaulterClient {
    return {
      documentNodes: {
        content: vi.fn().mockResolvedValue({ id: 1, content: doc, format: null } as never),
        handler: vi.fn().mockResolvedValue(handler({
          capabilities: { editable: true, previewable: true, downloadable: true, thumbnailable: false, collaborative: true },
        })),
        // The autosave path; it must not be reached when collaboration merely failed.
        update: vi.fn().mockResolvedValue({}),
      },
      nodeCollaboration: { token },
    } as unknown as VaulterClient
  }

  function mountNode(client: VaulterClient) {
    const seen: Array<Record<string, unknown>> = []
    registerEngineComponent('AppCustomEngine', defineComponent({
      props: { readonly: Boolean, collaborative: Boolean, onChange: { type: Function, default: null } },
      setup(props) {
        seen.push(props as never)
        return () => h('div', { class: 'engine' })
      },
    }))

    return { seen, wrapper: mount(VaulterDocumentNode, { props: { nodeId: 1, debounceMs: 0 }, ...withClient(client) }) }
  }

  it('falls back to autosave only for 409 collaboration_unavailable', async () => {
    const unavailable = new VaulterApiError(409, 'off', {}, undefined, 'collaboration_unavailable')
    const { seen, wrapper } = mountNode(collaborativeClient(() => Promise.reject(unavailable)))
    await flushPromises()

    expect(wrapper.emitted('collaboration-failed')).toBeUndefined()
    expect(seen.at(-1)).toMatchObject({ readonly: false, collaborative: false })
  })

  for (const status of [0, 429, 500, 503]) {
    it(`stays read-only and does not autosave when the session request fails with ${status || 'a network error'}`, async () => {
      const failure = status === 0 ? new TypeError('Failed to fetch') : new VaulterApiError(status, 'later', {}, undefined, null)
      const client = collaborativeClient(() => Promise.reject(failure))
      const { seen, wrapper } = mountNode(client)
      await flushPromises()

      expect(seen.at(-1)).toMatchObject({ readonly: true, collaborative: false })
      expect(wrapper.emitted('collaboration-failed')?.[0]).toEqual([failure])
      expect((client.documentNodes as unknown as { update: ReturnType<typeof vi.fn> }).update).not.toHaveBeenCalled()
    })
  }

  it('a 409 with another code is not "collaboration unavailable"', async () => {
    const conflict = new VaulterApiError(409, 'epoch', {}, undefined, 'collaboration_epoch_mismatch')
    const { seen } = mountNode(collaborativeClient(() => Promise.reject(conflict)))
    await flushPromises()

    expect(seen.at(-1)).toMatchObject({ readonly: true })
  })
})

import { describe, expect, it } from 'vitest'
import { createApp, defineComponent, h, type Component } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import { createViewerRegistry, useViewerRegistry, viewerRegistryKey } from '../../src/viewers/registry'
import { builtinViewers, VIEWER_KEYS } from '../../src/viewers/builtin'

const Viewer = (label: string): Component => defineComponent({ render: () => h('div', label) })

describe('createViewerRegistry', () => {
  it('resolves a registered component, and null for an unknown key', () => {
    const registry = createViewerRegistry({ 'acme/diagram': Viewer('diagram') })

    expect(registry.resolve('acme/diagram')).not.toBeNull()
    expect(registry.resolve('acme/other')).toBeNull()
    expect(registry.has('acme/diagram')).toBe(true)
    expect(registry.keys()).toEqual(['acme/diagram'])
  })

  it('replaces and unregisters a key', () => {
    const registry = createViewerRegistry({ 'a/b': Viewer('one') })
    const first = registry.resolve('a/b')

    registry.register('a/b', Viewer('two'))
    expect(registry.resolve('a/b')).not.toBe(first)

    registry.unregister('a/b')
    expect(registry.resolve('a/b')).toBeNull()
  })

  it('turns a loader function into one stable async component', async () => {
    const registry = createViewerRegistry({ 'acme/lazy': () => Promise.resolve({ default: Viewer('lazy') }) })

    const resolved = registry.resolve('acme/lazy')!
    expect(registry.resolve('acme/lazy')).toBe(resolved)

    const wrapper = mount(defineComponent({ render: () => h(resolved) }))
    await flushPromises()
    expect(wrapper.text()).toBe('lazy')
  })

  it('lists the six built-in keys the server may name', () => {
    expect(Object.keys(builtinViewers()).sort()).toEqual([
      'vaulter/download',
      'vaulter/image-preview',
      'vaulter/pdf-preview',
      'vaulter/tiptap',
      'vaulter/univer',
      'vaulter/unsupported',
    ])
    expect(Object.values(VIEWER_KEYS).sort()).toEqual(Object.keys(builtinViewers()).sort())
  })
})

describe('the registry belongs to one application', () => {
  function probe(): { component: Component, seen: () => string[] } {
    let keys: string[] = []
    const component = defineComponent({
      setup() {
        keys = useViewerRegistry().keys()
        return () => h('span')
      },
    })

    return { component, seen: () => keys }
  }

  function appWith(root: Component, viewers: Record<string, Component>) {
    const app = createApp(root)
    app.provide(viewerRegistryKey, createViewerRegistry({ ...builtinViewers(), ...viewers }))

    return app
  }

  it('two apps with different viewers never see each other\'s registrations', () => {
    const first = probe()
    const second = probe()

    const appA = appWith(first.component, { 'acme/only-a': Viewer('a') })
    appA.mount(document.createElement('div'))
    const appB = appWith(second.component, { 'acme/only-b': Viewer('b') })
    appB.mount(document.createElement('div'))

    expect(first.seen()).toContain('acme/only-a')
    expect(first.seen()).not.toContain('acme/only-b')
    expect(second.seen()).toContain('acme/only-b')
    expect(second.seen()).not.toContain('acme/only-a')
    // The built-in keys are in both.
    expect(first.seen()).toContain('vaulter/download')
    expect(second.seen()).toContain('vaulter/download')

    appA.unmount()
    appB.unmount()
  })

  it('lets the host replace a built-in key', () => {
    const replacement = Viewer('mine')
    const app = appWith(probe().component, { [VIEWER_KEYS.download]: replacement })

    const registry = app.runWithContext(() => useViewerRegistry())

    expect(registry.resolve(VIEWER_KEYS.download)).toBe(replacement)
  })

  it('throws a clear error when no registry was provided', () => {
    expect(() => mount(probe().component)).toThrow(/No viewer registry/)
  })
})

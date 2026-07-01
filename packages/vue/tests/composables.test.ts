import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import type { VaulterClient } from '../src/client'
import { provideVaulterClient } from '../src/plugin'
import { useComments } from '../src/composables/useComments'

/**
 * Mount a component that runs `setup()` against a fake client and exposes whatever
 * it returns so the test can drive the composable.
 */
function mountWith<R>(client: Partial<VaulterClient>, setup: () => R): { vm: R } {
  let captured: R
  const Comp = defineComponent({
    setup() {
      captured = setup()
      return () => h('div')
    },
  })
  mount(Comp, {
    global: {
      plugins: [{ install(app) { provideVaulterClient(app, client as VaulterClient) } }],
    },
  })
  return { vm: captured! }
}

describe('useComments (node — ADR-0014)', () => {
  it('lists a node\'s comments into reactive state', async () => {
    const list = vi.fn().mockResolvedValue([{ id: 1, body: 'A' }])
    const { vm } = mountWith(
      { comments: { list, create: vi.fn(), resolve: vi.fn(), remove: vi.fn() } as never },
      () => useComments(42),
    )

    expect(await vm.list()).toHaveLength(1)
    expect(list).toHaveBeenCalledWith(42)
    expect(vm.comments.value).toHaveLength(1)
  })

  it('appends a created comment and drops a removed one', async () => {
    const { vm } = mountWith(
      {
        comments: {
          list: vi.fn().mockResolvedValue([{ id: 1, body: 'A' }]),
          create: vi.fn().mockResolvedValue({ id: 2, body: 'B' }),
          resolve: vi.fn(),
          remove: vi.fn().mockResolvedValue(undefined),
        } as never,
      },
      () => useComments(42),
    )

    await vm.list()
    await vm.add({ body: 'B' })
    expect(vm.comments.value.map((c) => c.id)).toEqual([1, 2])

    await vm.remove(1)
    expect(vm.comments.value.map((c) => c.id)).toEqual([2])
  })
})

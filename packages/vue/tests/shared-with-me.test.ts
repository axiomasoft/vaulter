import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import VaulterSharedWithMe from '../src/components/VaulterSharedWithMe.vue'
import { useSharedNodes } from '../src/composables/useSharedNodes'
import { provideVaulterClient } from '../src/plugin'
import { docVaultClientKey } from '../src/composables/useVaulterClient'
import type { VaulterClient } from '../src/client'
import type { Node, NodeAbilities, Page } from '../src/types'

// ─── Fakes ──────────────────────────────────────────────────────────────────

/** A length-aware (`paginate()`) page envelope, like the real `GET /shared`. */
function sharedPage<T>(data: T[], overrides: Partial<Page<T>['meta']> = {}): Page<T> {
  const perPage = overrides.per_page ?? 50
  return {
    data,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: perPage,
      total: data.length,
      from: data.length === 0 ? null : 1,
      to: data.length === 0 ? null : data.length,
      path: '/api/vaulter/shared',
      ...overrides,
    },
  }
}

function node(partial: Partial<Node> & Pick<Node, 'id'>): Node {
  return {
    kind: 'folder', name: '', mime: null, extension: null, size_bytes: null,
    storage_id: 7, parent_id: null, file_id: null, current_version_id: null,
    is_pinned: false, position: null, trashed_at: null, created_at: null,
    updated_at: null, children: null, breadcrumb: null,
    abilities: {} as NodeAbilities, handler: null,
    ...partial,
  }
}

/** Provide a (partial) fake client to a mounted component. */
function withClient(client: Partial<VaulterClient>) {
  return { global: { provide: { [docVaultClientKey as symbol]: client as VaulterClient } } }
}

/** Mount a bare component running `setup()` against a fake client. */
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

/** Drain microtasks + render ticks so async (onMounted) handlers settle. */
async function flushAll(): Promise<void> {
  for (let i = 0; i < 4; i += 1) {
    await Promise.resolve()
    await nextTick()
  }
}

// ─── useSharedNodes ───────────────────────────────────────────────────────────

describe('useSharedNodes', () => {
  it('loads the shared nodes and exposes them on refresh', async () => {
    const shared = vi.fn().mockResolvedValue(sharedPage([
      node({ id: 1, kind: 'file', name: 'a.txt', storage_id: 7 }),
      node({ id: 2, kind: 'document', name: 'notes', storage_id: 9 }),
    ]))
    const { vm } = mountWith({ nodes: { shared } as never }, () => useSharedNodes())

    expect(vm.nodes.value).toHaveLength(0)
    await vm.refresh()

    // Cross-storage: ids from two different storages land in one flat list.
    expect(shared).toHaveBeenCalledWith({ page: 1, perPage: undefined })
    expect(vm.nodes.value.map((n) => n.id)).toEqual([1, 2])
    expect(vm.nodes.value.map((n) => n.storage_id)).toEqual([7, 9])
    expect(vm.error.value).toBeNull()
    expect(vm.loading.value).toBe(false)
  })

  it('captures a load failure into error and clears loading', async () => {
    const boom = new Error('nope')
    const shared = vi.fn().mockRejectedValue(boom)
    const { vm } = mountWith({ nodes: { shared } as never }, () => useSharedNodes())

    await expect(vm.refresh()).rejects.toBe(boom)
    expect(vm.error.value).toBe(boom)
    expect(vm.loading.value).toBe(false)
    expect(vm.nodes.value).toHaveLength(0)
  })

  it('pages with loadMore when more pages exist, then stops', async () => {
    const shared = vi.fn()
      .mockResolvedValueOnce(sharedPage([node({ id: 1, kind: 'file' })], { current_page: 1, last_page: 2, total: 2, per_page: 1 }))
      .mockResolvedValueOnce(sharedPage([node({ id: 2, kind: 'file' })], { current_page: 2, last_page: 2, total: 2, per_page: 1 }))
    const { vm } = mountWith({ nodes: { shared } as never }, () => useSharedNodes({ perPage: 1 }))

    await vm.refresh()
    expect(vm.hasMore.value).toBe(true)

    await vm.loadMore()
    expect(shared).toHaveBeenLastCalledWith({ page: 2, perPage: 1 })
    // Appended, not replaced.
    expect(vm.nodes.value.map((n) => n.id)).toEqual([1, 2])
    expect(vm.hasMore.value).toBe(false)

    // No further page → loadMore is a no-op (the client is not hit again).
    await vm.loadMore()
    expect(shared).toHaveBeenCalledTimes(2)
  })

  it('a stale earlier refresh never overwrites a later one', async () => {
    let resolveFirst: ((page: Page<Node>) => void) | undefined
    const shared = vi.fn()
      .mockImplementationOnce(() => new Promise<Page<Node>>((resolve) => { resolveFirst = resolve }))
      .mockResolvedValueOnce(sharedPage([node({ id: 2, kind: 'file', name: 'winner' })]))
    const { vm } = mountWith({ nodes: { shared } as never }, () => useSharedNodes())

    const first = vm.refresh()
    const second = vm.refresh()
    await second
    // The later call wins.
    expect(vm.nodes.value.map((n) => n.id)).toEqual([2])

    // Now the slow first call resolves late — it must NOT clobber the list.
    resolveFirst?.(sharedPage([node({ id: 1, kind: 'file', name: 'stale' })]))
    await first
    expect(vm.nodes.value.map((n) => n.id)).toEqual([2])
  })

  it('a stale in-flight loadMore never clobbers a list a later refresh replaced', async () => {
    let resolveMore: ((page: Page<Node>) => void) | undefined
    const shared = vi.fn()
      .mockResolvedValueOnce(sharedPage([node({ id: 1, kind: 'file', name: 'p1' })], { current_page: 1, last_page: 2, total: 2, per_page: 1 }))
      .mockImplementationOnce(() => new Promise<Page<Node>>((resolve) => { resolveMore = resolve })) // loadMore page 2 (slow)
      .mockResolvedValueOnce(sharedPage([node({ id: 9, kind: 'file', name: 'fresh' })], { current_page: 1, last_page: 1, total: 1, per_page: 1 })) // refresh wins
    const { vm } = mountWith({ nodes: { shared } as never }, () => useSharedNodes({ perPage: 1 }))

    await vm.refresh()
    const more = vm.loadMore() // page 2 in flight
    await vm.refresh() // a later refresh replaces the list with a single fresh page
    expect(vm.nodes.value.map((n) => n.id)).toEqual([9])

    // The stale page-2 resolves late — it must NOT graft onto / rewind the fresh list.
    resolveMore?.(sharedPage([node({ id: 2, kind: 'file', name: 'stale' })], { current_page: 2, last_page: 2, total: 2, per_page: 1 }))
    await more
    expect(vm.nodes.value.map((n) => n.id)).toEqual([9])
    expect(vm.hasMore.value).toBe(false)
  })

  it('a double-click on loadMore appends the next page only once', async () => {
    let resolveSecond: ((page: Page<Node>) => void) | undefined
    const shared = vi.fn()
      .mockResolvedValueOnce(sharedPage([node({ id: 1, kind: 'file' })], { current_page: 1, last_page: 2, total: 2, per_page: 1 }))
      .mockImplementationOnce(() => new Promise<Page<Node>>((resolve) => { resolveSecond = resolve }))
    const { vm } = mountWith({ nodes: { shared } as never }, () => useSharedNodes({ perPage: 1 }))
    await vm.refresh()

    const a = vm.loadMore() // page 2 in flight
    const b = vm.loadMore() // re-entry while loading → no-op (no second request)
    resolveSecond?.(sharedPage([node({ id: 2, kind: 'file' })], { current_page: 2, last_page: 2, total: 2, per_page: 1 }))
    await Promise.all([a, b])

    // Exactly two client calls (refresh + ONE loadMore); page 2 appended once, no dupes.
    expect(shared).toHaveBeenCalledTimes(2)
    expect(vm.nodes.value.map((n) => n.id)).toEqual([1, 2])
  })
})

// ─── VaulterSharedWithMe ───────────────────────────────────────────────────────

describe('VaulterSharedWithMe', () => {
  it('renders the shared nodes from the client and emits open-node on open', async () => {
    const shared = vi.fn().mockResolvedValue(sharedPage([
      node({ id: 1, kind: 'file', name: 'shared.txt', storage_id: 7, abilities: {} }),
      node({ id: 2, kind: 'document', name: 'plan', storage_id: 9, abilities: {} }),
    ]))
    const wrapper = mount(VaulterSharedWithMe, { ...withClient({ nodes: { shared } as never }) })

    await flushAll()
    expect(shared).toHaveBeenCalledWith({ page: 1, perPage: undefined })

    const rows = wrapper.findAll('.vaulter-node-list__item')
    expect(rows).toHaveLength(2)

    // Open the second (cross-storage) node → open-node carries the node as-is.
    await rows[1].trigger('dblclick')
    expect(wrapper.emitted('open-node')?.[0]?.[0]).toMatchObject({ id: 2, kind: 'document', storage_id: 9 })
  })

  it('shows the empty state when nothing is shared', async () => {
    const shared = vi.fn().mockResolvedValue(sharedPage<Node>([]))
    const wrapper = mount(VaulterSharedWithMe, { ...withClient({ nodes: { shared } as never }) })

    await flushAll()

    expect(wrapper.findAll('.vaulter-node-list__item')).toHaveLength(0)
    expect(wrapper.find('.vaulter-shared__empty').exists()).toBe(true)
  })

  it('refreshes on the toolbar affordance', async () => {
    const shared = vi.fn().mockResolvedValue(sharedPage([node({ id: 1, kind: 'file', name: 'a', abilities: {} })]))
    const wrapper = mount(VaulterSharedWithMe, { ...withClient({ nodes: { shared } as never }) })

    await flushAll()
    expect(shared).toHaveBeenCalledTimes(1)

    await wrapper.get('.vaulter-shared__refresh').trigger('click')
    await flushAll()
    expect(shared).toHaveBeenCalledTimes(2)
  })

  it('surfaces a load error and keeps the mount alive', async () => {
    const shared = vi.fn().mockRejectedValue(new Error('boom'))
    const wrapper = mount(VaulterSharedWithMe, { ...withClient({ nodes: { shared } as never }) })

    await flushAll()

    expect(wrapper.find('.vaulter-shared__error').exists()).toBe(true)
  })
})

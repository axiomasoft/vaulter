import { describe, expect, it, vi } from 'vitest'
import { useComments } from '../src/composables/useComments'
import { pageOf } from './support/nodes'
import { mountWith } from './support/mount'

describe('useComments', () => {
  it('lists a node\'s comments into reactive state and keeps the cursor', async () => {
    const list = vi.fn().mockResolvedValue(pageOf([{ id: '1', body: 'A' }], 'c2'))
    const { vm } = mountWith(
      { comments: { list, add: vi.fn(), resolve: vi.fn(), remove: vi.fn() } },
      () => useComments('42'),
    )

    expect(await vm.list()).toHaveLength(1)
    expect(list).toHaveBeenCalledWith('42')
    expect(vm.comments.value).toHaveLength(1)
    expect(vm.cursor.value?.next_cursor).toBe('c2')
  })

  it('appends the next page', async () => {
    const list = vi.fn()
      .mockResolvedValueOnce(pageOf([{ id: '1' }], 'c2'))
      .mockResolvedValueOnce(pageOf([{ id: '2' }]))
    const { vm } = mountWith({ comments: { list } }, () => useComments('42'))

    await vm.list()
    await vm.loadMore()

    expect(list).toHaveBeenLastCalledWith('42', { cursor: 'c2' })
    expect(vm.comments.value.map((c) => c.id)).toEqual(['1', '2'])
    // No more pages: another call does not fetch.
    await vm.loadMore()
    expect(list).toHaveBeenCalledTimes(2)
  })

  it('appends a created comment and drops a removed one', async () => {
    const { vm } = mountWith(
      {
        comments: {
          list: vi.fn().mockResolvedValue(pageOf([{ id: '1', body: 'A' }])),
          add: vi.fn().mockResolvedValue({ id: '2', body: 'B' }),
          resolve: vi.fn(),
          remove: vi.fn().mockResolvedValue(undefined),
        },
      },
      () => useComments('42'),
    )

    await vm.list()
    await vm.add({ body: 'B' })
    expect(vm.comments.value.map((c) => c.id)).toEqual(['1', '2'])

    await vm.remove('1')
    expect(vm.comments.value.map((c) => c.id)).toEqual(['2'])
  })
})

import { describe, expect, it, vi } from 'vitest'
import { VaulterApiError } from '../src/api/errors'
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
    const remove = vi.fn().mockResolvedValue(undefined)
    const { vm } = mountWith(
      {
        comments: {
          list: vi.fn().mockResolvedValue(pageOf([{ id: '1', body: 'A', etag: '"comment.1.0"' }])),
          add: vi.fn().mockResolvedValue({ id: '2', body: 'B', etag: '"comment.2.0"' }),
          resolve: vi.fn(),
          remove,
        },
      },
      () => useComments('42'),
    )

    await vm.list()
    await vm.add({ body: 'B' })
    expect(vm.comments.value.map((c) => c.id)).toEqual(['1', '2'])

    await vm.remove('1')
    expect(remove).toHaveBeenCalledWith('42', '1', { etag: '"comment.1.0"' })
    expect(vm.comments.value.map((c) => c.id)).toEqual(['2'])
  })

  it('resolves with the held comment etag and swaps in the raised version', async () => {
    const resolved = { id: '1', body: 'A', resolved_at: '2026-01-01T00:00:00Z', version: 1, etag: '"comment.1.1"' }
    const resolve = vi.fn().mockResolvedValue(resolved)
    const { vm } = mountWith(
      { comments: { list: vi.fn().mockResolvedValue(pageOf([{ id: '1', body: 'A', version: 0, etag: '"comment.1.0"' }])), resolve } },
      () => useComments('42'),
    )
    await vm.list()

    await vm.resolve('1')

    expect(resolve).toHaveBeenCalledWith('42', '1', { etag: '"comment.1.0"' })
    expect(vm.comments.value[0]).toEqual(resolved)
  })

  it('passes a 412 stale_write through as a VaulterApiError and keeps the thread', async () => {
    const stale = new VaulterApiError({ status: 412, code: 'stale_write', retryable: 'after_refetch' })
    const { vm } = mountWith(
      { comments: { list: vi.fn().mockResolvedValue(pageOf([{ id: '1', body: 'A', etag: '"comment.1.0"' }])), resolve: vi.fn().mockRejectedValue(stale) } },
      () => useComments('42'),
    )
    await vm.list()

    await expect(vm.resolve('1')).rejects.toBe(stale)
    expect(vm.comments.value.map((c) => c.etag)).toEqual(['"comment.1.0"'])
  })

  it('refuses to write a comment it holds no etag for instead of sending it blind', async () => {
    const remove = vi.fn()
    const { vm } = mountWith({ comments: { remove } }, () => useComments('42'))

    await expect(vm.remove('9')).rejects.toThrow('No ETag for comment 9')
    expect(remove).not.toHaveBeenCalled()
    await vm.remove('9', { etag: '"comment.9.4"' })
    expect(remove).toHaveBeenCalledWith('42', '9', { etag: '"comment.9.4"' })
  })
})

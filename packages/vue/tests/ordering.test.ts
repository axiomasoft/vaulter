import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { VaulterApiError } from '../src/api/errors'
import { useActivity } from '../src/composables/useActivity'
import { useComments } from '../src/composables/useComments'
import { useDocument } from '../src/composables/useDocument'
import { useGrants } from '../src/composables/useGrants'
import { useNodes } from '../src/composables/useNodes'
import { useRevisions } from '../src/composables/useRevisions'
import { useShares } from '../src/composables/useShares'
import { makeFile, pageOf } from './support/nodes'
import { deferred } from './support/deferred'
import { mountWith } from './support/mount'

// One invariant, tested wherever an async result can arrive after a newer local action:
// a response that is older than a later local action never overwrites it.

const content = (body: object, etag = '"20.1.1"') =>
  ({ type: 'richtext', schema_version: 1, state_version: 1, collab_epoch: 0, revision_id: null, content: body, etag })

describe('useNodes — loadMore', () => {
  it('does not append a locally touched row a second time when it is renamed during the page', async () => {
    const one = makeFile({ id: '1', name: 'old', etag: '"E1"' })
    const two = makeFile({ id: '2', name: 'page2' })
    const more = deferred<ReturnType<typeof pageOf>>()
    const children = vi.fn()
      .mockResolvedValueOnce(pageOf([one], 'next'))
      .mockReturnValueOnce(more.promise)
    const update = vi.fn().mockResolvedValue({ ...one, name: 'new', etag: '"E2"' })
    const { vm } = mountWith({ nodes: { children, update } }, () => useNodes('7'))
    await vm.refresh()

    const loading = vm.loadMore()
    await vm.rename(one, 'new')
    more.resolve(pageOf([two]))
    await loading

    expect(vm.nodes.value.map((n) => `${n.id}:${n.name}`)).toEqual(['1:new', '2:page2'])
  })

  it('a loadMore started during a refresh neither cancels the refresh nor leaves loading stuck', async () => {
    const fresh = makeFile({ id: '9', name: 'fresh' })
    const refreshing = deferred<ReturnType<typeof pageOf>>()
    const children = vi.fn()
      .mockResolvedValueOnce(pageOf([makeFile({ id: '1' })], 'next'))
      .mockReturnValueOnce(refreshing.promise)
      .mockResolvedValueOnce(pageOf([makeFile({ id: '2' })]))
    const { vm } = mountWith({ nodes: { children } }, () => useNodes('7'))
    await vm.refresh()

    const pending = vm.refresh()
    await vm.loadMore()
    refreshing.resolve(pageOf([fresh]))
    await pending

    expect(vm.loading.value).toBe(false)
    expect(vm.nodes.value.map((n) => n.id)).toContain('9')
  })

  it('two loadMore calls in a row fetch the page once', async () => {
    const more = deferred<ReturnType<typeof pageOf>>()
    const children = vi.fn()
      .mockResolvedValueOnce(pageOf([makeFile({ id: '1' })], 'next'))
      .mockReturnValueOnce(more.promise)
    const { vm } = mountWith({ nodes: { children } }, () => useNodes('7'))
    await vm.refresh()

    const a = vm.loadMore()
    const b = vm.loadMore()
    more.resolve(pageOf([makeFile({ id: '2' })]))
    await Promise.all([a, b])

    expect(children).toHaveBeenCalledTimes(2)
    expect(vm.nodes.value.map((n) => n.id)).toEqual(['1', '2'])
  })

  it('a refresh that starts while loadMore is in flight discards the old page', async () => {
    const more = deferred<ReturnType<typeof pageOf>>()
    const children = vi.fn()
      .mockResolvedValueOnce(pageOf([makeFile({ id: '1' })], 'next'))
      .mockReturnValueOnce(more.promise)
      .mockResolvedValueOnce(pageOf([makeFile({ id: '7' })]))
    const { vm } = mountWith({ nodes: { children } }, () => useNodes('7'))
    await vm.refresh()

    const loading = vm.loadMore()
    await vm.refresh()
    more.resolve(pageOf([makeFile({ id: '2' })]))
    await loading

    expect(vm.nodes.value.map((n) => n.id)).toEqual(['7'])
  })
})

describe('useDocument — conflict and load ordering', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('an autosave armed before a 412 never overwrites the other author: it waits for the user', async () => {
    const first = deferred<ReturnType<typeof content>>()
    const update = vi.fn().mockReturnValueOnce(first.promise).mockResolvedValue(content({ v: 'C3' }, '"E3"'))
    const loaded = vi.fn()
      .mockResolvedValueOnce(content({ v: 'C1' }, '"E1"'))
      .mockResolvedValue(content({ v: 'theirs' }, '"E2"'))
    const { vm } = mountWith({ documents: { content: loaded, update } }, () => useDocument('20', { debounceMs: 30 }))
    await vm.load()

    vm.setContent({ v: 'C2' })
    const saving = vm.save().catch(() => undefined)
    await vi.advanceTimersByTimeAsync(0)
    vm.setContent({ v: 'C3' })                       // arms the autosave
    first.reject(new VaulterApiError({ status: 412, code: 'document_state_mismatch' }))
    await saving
    await vi.advanceTimersByTimeAsync(100)

    expect(update).toHaveBeenCalledTimes(1)
    expect(vm.conflict.value).not.toBeNull()
    expect(vm.content.value).toEqual({ v: 'C3' })

    // The user decides: an explicit save overwrites with the re-read tag.
    await vm.save()
    expect(update).toHaveBeenLastCalledWith('20', { v: 'C3' }, { etag: '"E2"' })
    expect(vm.conflict.value).toBeNull()
  })

  it('does not autosave while a conflict is open, even for further edits', async () => {
    const update = vi.fn().mockRejectedValueOnce(new VaulterApiError({ status: 412, code: 'document_state_mismatch' }))
    const loaded = vi.fn()
      .mockResolvedValueOnce(content({ v: 'C1' }, '"E1"'))
      .mockResolvedValue(content({ v: 'theirs' }, '"E2"'))
    const { vm } = mountWith({ documents: { content: loaded, update } }, () => useDocument('20', { debounceMs: 30 }))
    await vm.load()
    vm.setContent({ v: 'C2' })
    await vm.save().catch(() => undefined)

    vm.setContent({ v: 'C3' })
    await vi.advanceTimersByTimeAsync(200)

    expect(update).toHaveBeenCalledTimes(1)
  })

  it('a save in flight when load() is called finishes first; load() then has the last word', async () => {
    const held = deferred<ReturnType<typeof content>>()
    const update = vi.fn().mockReturnValue(held.promise)
    const loaded = vi.fn()
      .mockResolvedValueOnce(content({ v: 'C1' }, '"E1"'))
      .mockResolvedValueOnce(content({ v: 'server-now' }, '"E9"'))
    const { vm } = mountWith({ documents: { content: loaded, update } }, () => useDocument('20'))
    await vm.load()

    vm.setContent({ v: 'local' })
    const saving = vm.save()
    await vi.advanceTimersByTimeAsync(0)
    const reloading = vm.load()
    held.resolve(content({ v: 'local' }, '"E2"'))
    await saving
    await reloading

    expect(vm.content.value).toEqual({ v: 'server-now' })
    expect(vm.head.value?.content).toEqual({ v: 'server-now' })
    expect(vm.head.value?.etag).toBe('"E9"')
  })
})

describe('late list responses never replace a newer local state', () => {
  it('useComments: a refresh from before an add does not drop the new comment, and loadMore is single-flight', async () => {
    const late = deferred<ReturnType<typeof pageOf>>()
    const more = deferred<ReturnType<typeof pageOf>>()
    const list = vi.fn()
      .mockResolvedValueOnce(pageOf([{ id: 'c1' }], 'next'))
      .mockReturnValueOnce(late.promise)
      .mockReturnValueOnce(more.promise)
    const { vm } = mountWith(
      { comments: { list, add: vi.fn().mockResolvedValue({ id: 'c-new' }) } },
      () => useComments('9'),
    )
    await vm.list()

    const refreshing = vm.list()
    await vm.add({ body: 'x' })
    late.resolve(pageOf([{ id: 'c1' }]))
    await refreshing
    expect(vm.comments.value.map((c) => c.id)).toContain('c-new')

    // loadMore (cursor is still 'next' because the stale refresh was dropped)
    const a = vm.loadMore()
    const b = vm.loadMore()
    more.resolve(pageOf([{ id: 'c2' }]))
    await Promise.all([a, b])
    expect(vm.comments.value.map((c) => c.id).filter((id) => id === 'c2')).toHaveLength(1)
  })

  it('useShares: a list from before a create does not hide the new link that lastCreated shows', async () => {
    const late = deferred<unknown[]>()
    const list = vi.fn().mockResolvedValueOnce([{ id: 's-old' }]).mockReturnValueOnce(late.promise)
    const create = vi.fn().mockResolvedValue({ id: 's-new', token: 't', url: 'u' })
    const { vm } = mountWith({ shares: { list, create } }, () => useShares('9'))
    await vm.list()

    const refreshing = vm.list()
    await vm.create()
    late.resolve([{ id: 's-old' }])
    await refreshing

    expect(vm.shares.value.map((s) => s.id)).toEqual(['s-new', 's-old'])
    expect(vm.loading.value).toBe(false)
  })

  it('useGrants: a list from before a grant keeps the new grant', async () => {
    const late = deferred<unknown>()
    const listTagged = vi.fn()
      .mockResolvedValueOnce({ etag: '"node-access.9.0"', data: [{ id: 'g-old' }] })
      .mockReturnValueOnce(late.promise)
    const { vm } = mountWith({ grants: { listTagged, create: vi.fn().mockResolvedValue({ id: 'g-new' }) } }, () => useGrants('9'))
    await vm.list()

    const refreshing = vm.list()
    await vm.grant({ subject: { type: 'user', id: '1' }, role: 'reader' })
    late.resolve({ etag: '"node-access.9.0"', data: [{ id: 'g-old' }] })
    await refreshing

    expect(vm.grants.value.map((g) => g.id)).toEqual(['g-new', 'g-old'])
    // The late list does not bring back the tag the grant made stale.
    expect(vm.accessTag.value).toBeNull()
  })

  it('useRevisions: a list from before a snapshot keeps the snapshot', async () => {
    const late = deferred<ReturnType<typeof pageOf>>()
    const revisions = vi.fn().mockResolvedValueOnce(pageOf([{ id: 'r-old' }])).mockReturnValueOnce(late.promise)
    const snapshot = vi.fn().mockResolvedValue({ id: 'r-new' })
    const { vm } = mountWith({ documents: { revisions, snapshot } }, () => useRevisions('20'))
    await vm.list()

    const refreshing = vm.list()
    await vm.snapshot()
    late.resolve(pageOf([{ id: 'r-old' }]))
    await refreshing

    expect(vm.revisions.value.map((r) => r.id)).toEqual(['r-new', 'r-old'])
  })

  it('useActivity: an older refresh answering last does not replace the newer one; loadMore is single-flight', async () => {
    const stale = deferred<ReturnType<typeof pageOf>>()
    const more = deferred<ReturnType<typeof pageOf>>()
    const forDrive = vi.fn()
      .mockReturnValueOnce(stale.promise)
      .mockResolvedValueOnce(pageOf([{ id: 'fresh' }], 'c2'))
      .mockReturnValueOnce(more.promise)
    const { vm } = mountWith({ activity: { forDrive } }, () => useActivity({ driveId: '7' }))

    const first = vm.refresh()
    await vm.refresh()
    stale.resolve(pageOf([{ id: 'stale' }]))
    await first
    expect(vm.activity.value.map((a) => a.id)).toEqual(['fresh'])

    const a = vm.loadMore()
    const b = vm.loadMore()
    more.resolve(pageOf([{ id: 'older' }]))
    await Promise.all([a, b])
    expect(vm.activity.value.map((x) => x.id)).toEqual(['fresh', 'older'])
    expect(forDrive).toHaveBeenCalledTimes(3)
    await nextTick()
  })
})

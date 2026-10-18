import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { VaulterApiError } from '../src/api/errors'
import { useActivity } from '../src/composables/useActivity'
import { useDocument } from '../src/composables/useDocument'
import { useDrive } from '../src/composables/useDrive'
import { useGrants } from '../src/composables/useGrants'
import { useNodes } from '../src/composables/useNodes'
import { useRevisions } from '../src/composables/useRevisions'
import { useShares } from '../src/composables/useShares'
import { useUpload } from '../src/composables/useUpload'
import { makeFile, makeNode, pageOf } from './support/nodes'
import { mountWith } from './support/mount'

function problem(status: number, code: string): VaulterApiError {
  return new VaulterApiError({ status, code, title: code })
}

function content(body: object, etag: string | null = '"20.1.1"') {
  return { type: 'richtext', schema_version: 1, state_version: 1, collab_epoch: 0, revision_id: null, content: body, etag }
}

describe('useDrive', () => {
  it('loads the drive', async () => {
    const get = vi.fn().mockResolvedValue({ id: '7', name: 'Main' })
    const { vm } = mountWith({ drives: { get } }, () => useDrive('7'))

    await vm.load()

    expect(get).toHaveBeenCalledWith('7')
    expect(vm.drive.value?.name).toBe('Main')
  })
})

describe('useNodes', () => {
  it('loads a folder and updates the reactive list after createFolder', async () => {
    const a = makeFile({ id: '1', name: 'a', parent_id: null })
    const created = makeNode({ id: '2', name: 'New', parent_id: null })
    const children = vi.fn().mockResolvedValue(pageOf([a]))
    const create = vi.fn().mockResolvedValue(created)
    const { vm } = mountWith({ nodes: { children }, folders: { create } }, () => useNodes('7'))

    await vm.refresh()
    expect(vm.nodes.value).toHaveLength(1)

    await vm.createFolder({ name: 'New' })
    expect(children).toHaveBeenCalledWith('7', { parent: null })
    expect(create).toHaveBeenCalledWith('7', { name: 'New', parentId: null })
    // The new folder (same parent = root) is appended optimistically.
    expect(vm.nodes.value.map((n) => n.id)).toEqual(['1', '2'])
  })

  it('drops a node from the list after trash, sending its etag', async () => {
    const one = makeFile({ id: '1', etag: '"1.4.aaaa"' })
    const two = makeFile({ id: '2' })
    const children = vi.fn().mockResolvedValue(pageOf([one, two]))
    const trash = vi.fn().mockResolvedValue({ ...one, trashed_at: 'now' })
    const { vm } = mountWith({ nodes: { children, trash } }, () => useNodes('7'))

    await vm.refresh()
    await vm.trash(one)

    expect(trash).toHaveBeenCalledWith('1', { etag: '"1.4.aaaa"' })
    expect(vm.nodes.value.map((n) => n.id)).toEqual(['2'])
  })

  it('sends the node etag verbatim for rename, move, restore and purge', async () => {
    const node = makeFile({ id: '1', etag: '"1.9.beef"' })
    const update = vi.fn().mockResolvedValue({ ...node, name: 'b' })
    const move = vi.fn().mockResolvedValue({ ...node, parent_id: '40' })
    const restore = vi.fn().mockResolvedValue(node)
    const purge = vi.fn().mockResolvedValue(undefined)
    const children = vi.fn().mockResolvedValue(pageOf([node]))
    const { vm } = mountWith({ nodes: { children, update, move, restore, purge } }, () => useNodes('7'))
    await vm.refresh()

    await vm.rename(node, 'b')
    expect(update).toHaveBeenCalledWith('1', { name: 'b' }, { etag: '"1.9.beef"' })
    expect(vm.nodes.value[0].name).toBe('b')

    await vm.move(node, '40')
    expect(move).toHaveBeenCalledWith('1', '40', { etag: '"1.9.beef"' })
    // It left the listed folder.
    expect(vm.nodes.value).toEqual([])

    await vm.restore(node)
    expect(restore).toHaveBeenCalledWith('1', { etag: '"1.9.beef"' })

    await vm.purge(node)
    expect(purge).toHaveBeenCalledWith('1', { etag: '"1.9.beef"' })
  })

  it('navigates into a folder and re-lists with the new parent', async () => {
    const children = vi.fn()
      .mockResolvedValueOnce(pageOf([makeNode({ id: '1' })]))
      .mockResolvedValueOnce(pageOf([makeNode({ id: '5' }), makeNode({ id: '6' })]))
    const { vm } = mountWith({ nodes: { children } }, () => useNodes('7'))

    await vm.refresh()
    await vm.navigate('99')

    expect(vm.parentId.value).toBe('99')
    expect(children).toHaveBeenLastCalledWith('7', { parent: '99' })
    expect(vm.nodes.value.map((n) => n.id)).toEqual(['5', '6'])
  })

  it('pages with the cursor and stops at the last page', async () => {
    const children = vi.fn()
      .mockResolvedValueOnce(pageOf([makeNode({ id: '1' })], 'next'))
      .mockResolvedValueOnce(pageOf([makeNode({ id: '2' })]))
    const { vm } = mountWith({ nodes: { children } }, () => useNodes('7'))

    await vm.refresh()
    await vm.loadMore()
    await vm.loadMore()

    expect(children).toHaveBeenCalledTimes(2)
    expect(children).toHaveBeenLastCalledWith('7', { parent: null, cursor: 'next' })
    expect(vm.nodes.value.map((n) => n.id)).toEqual(['1', '2'])
  })

  describe('a stale etag (412)', () => {
    it('re-reads the node, sets conflict and still rejects', async () => {
      const stale = makeFile({ id: '1', name: 'old', etag: '"1.1.old"' })
      const fresh = { ...stale, name: 'renamed by someone', etag: '"1.2.new"' }
      const error = problem(412, 'node_sequence_mismatch')
      const children = vi.fn().mockResolvedValue(pageOf([stale]))
      const update = vi.fn().mockRejectedValue(error)
      const get = vi.fn().mockResolvedValue(fresh)
      const { vm } = mountWith({ nodes: { children, update, get } }, () => useNodes('7'))
      await vm.refresh()

      await expect(vm.rename(stale, 'mine')).rejects.toBe(error)

      expect(get).toHaveBeenCalledWith('1')
      expect(vm.nodes.value[0]).toEqual(fresh)
      expect(vm.conflict.value).toEqual({ nodeId: '1', error })
    })

    it('drops the row when the re-read finds the node gone', async () => {
      const stale = makeFile({ id: '1' })
      const children = vi.fn().mockResolvedValue(pageOf([stale]))
      const trash = vi.fn().mockRejectedValue(problem(412, 'node_sequence_mismatch'))
      const get = vi.fn().mockRejectedValue(problem(404, 'node_not_found'))
      const { vm } = mountWith({ nodes: { children, trash, get } }, () => useNodes('7'))
      await vm.refresh()

      await expect(vm.trash(stale)).rejects.toBeInstanceOf(VaulterApiError)

      expect(vm.nodes.value).toEqual([])
      expect(vm.conflict.value?.nodeId).toBe('1')
    })

    it('clears the conflict on the next mutation', async () => {
      const node = makeFile({ id: '1' })
      const children = vi.fn().mockResolvedValue(pageOf([node]))
      const update = vi.fn()
        .mockRejectedValueOnce(problem(412, 'node_sequence_mismatch'))
        .mockResolvedValueOnce({ ...node, name: 'ok' })
      const { vm } = mountWith({ nodes: { children, update, get: vi.fn().mockResolvedValue(node) } }, () => useNodes('7'))
      await vm.refresh()

      await vm.rename(node, 'x').catch(() => undefined)
      expect(vm.conflict.value).not.toBeNull()

      await vm.rename(node, 'ok')
      expect(vm.conflict.value).toBeNull()
    })
  })

  it('a missing precondition (428) is an integration error: rethrown, no re-read, no conflict', async () => {
    const node = makeFile({ id: '1' })
    const error = problem(428, 'precondition_required')
    const get = vi.fn()
    const { vm } = mountWith(
      { nodes: { children: vi.fn().mockResolvedValue(pageOf([node])), update: vi.fn().mockRejectedValue(error), get } },
      () => useNodes('7'),
    )
    await vm.refresh()

    await expect(vm.rename(node, 'x')).rejects.toBe(error)

    expect(get).not.toHaveBeenCalled()
    expect(vm.conflict.value).toBeNull()
  })

  it('stars and unstars a node in place', async () => {
    const node = makeFile({ id: '1' })
    const starred = { ...node, viewer: { starred: true } }
    const { vm } = mountWith({
      nodes: {
        children: vi.fn().mockResolvedValue(pageOf([node])),
        star: vi.fn().mockResolvedValue(starred),
        unstar: vi.fn().mockResolvedValue(node),
      },
    }, () => useNodes('7'))
    await vm.refresh()

    await vm.star(node)
    expect(vm.nodes.value[0].viewer.starred).toBe(true)

    await vm.unstar(starred)
    expect(vm.nodes.value[0].viewer.starred).toBe(false)
  })
})

describe('useUpload', () => {
  it('reports progress 0..1 during a resumable upload', async () => {
    // A fake resumable that emits progress then resolves with a node.
    const start = vi.fn().mockImplementation((_drive, file: Blob, options) => ({
      start: async () => {
        options.onProgress(0, file.size)
        options.onProgress(file.size, file.size)
        return makeFile({ id: '50' })
      },
      abort: vi.fn(),
    }))
    const { vm } = mountWith({ uploads: { start } }, () => useUpload('7'))

    const created = await vm.uploadResumable(new Blob(['0123456789']))

    expect(created.id).toBe('50')
    expect(vm.progress.value).toBe(1)
    expect(vm.sent.value).toBe(10)
    expect(vm.total.value).toBe(10)
  })

  it('captures the error and resets the uploading flag on failure', async () => {
    const upload = vi.fn().mockRejectedValue(new Error('boom'))
    const { vm } = mountWith({ files: { upload } }, () => useUpload('7'))

    await expect(vm.upload(new Blob(['x']))).rejects.toThrow('boom')
    expect(vm.uploading.value).toBe(false)
    expect(vm.error.value).toBeInstanceOf(Error)
  })
})

describe('useGrants', () => {
  it('lists, prepends a new grant and revokes by id', async () => {
    const existing = { id: 'g1', node_id: '9', subject: { type: 'user', id: '1' }, role: 'reader' }
    const created = { id: 'g2', node_id: '9', subject: { type: 'user', id: '2' }, role: 'writer' }
    const revoke = vi.fn().mockResolvedValue(undefined)
    const create = vi.fn().mockResolvedValue(created)
    const listTagged = vi.fn()
      .mockResolvedValueOnce({ etag: '"node-access.9.0"', data: [existing] })
      .mockResolvedValueOnce({ etag: '"node-access.9.1"', data: [created, existing] })
    const { vm } = mountWith({ grants: { listTagged, create, revoke } }, () => useGrants('9'))

    await vm.list()
    expect(vm.accessTag.value).toBe('"node-access.9.0"')
    await vm.grant({ subject: { type: 'user', id: '2' }, role: 'writer' })
    expect(vm.grants.value.map((g) => g.id)).toEqual(['g2', 'g1'])
    // The grant raised the access version on the server: the held tag is gone.
    expect(vm.accessTag.value).toBeNull()

    // So the revoke reloads first and sends the fresh access tag.
    await vm.revoke('g1')
    expect(listTagged).toHaveBeenCalledTimes(2)
    expect(revoke).toHaveBeenCalledWith('9', 'g1', { etag: '"node-access.9.1"' })
    expect(vm.grants.value.map((g) => g.id)).toEqual(['g2'])
    expect(vm.accessTag.value).toBeNull()
  })

  it('revokes with the held access tag without reloading, and passes a 412 through', async () => {
    const stale = new VaulterApiError({ status: 412, code: 'stale_write' })
    const revoke = vi.fn().mockRejectedValue(stale)
    const listTagged = vi.fn().mockResolvedValue({ etag: '"node-access.9.4"', data: [{ id: 'g1' }] })
    const { vm } = mountWith({ grants: { listTagged, revoke } }, () => useGrants('9'))
    await vm.list()

    await expect(vm.revoke('g1')).rejects.toBe(stale)
    expect(listTagged).toHaveBeenCalledTimes(1)
    expect(revoke).toHaveBeenCalledWith('9', 'g1', { etag: '"node-access.9.4"' })
    expect(vm.grants.value.map((g) => g.id)).toEqual(['g1'])
  })
})

describe('useShares', () => {
  it('surfaces the one-time token via lastCreated and prepends the share', async () => {
    const created = { id: 's1', node_id: '9', token: 'abc.SECRET', url: 'https://x/s/abc.SECRET', role: 'reader' }
    const create = vi.fn().mockResolvedValue(created)
    const { vm } = mountWith({ shares: { create } }, () => useShares('9'))

    const result = await vm.create({ role: 'reader' })

    expect(result.token).toBe('abc.SECRET')
    expect(vm.lastCreated.value?.token).toBe('abc.SECRET')
    expect(vm.shares.value).toHaveLength(1)

    vm.clearLastCreated()
    expect(vm.lastCreated.value).toBeNull()
  })

  it('rotates a link (new token once) and forgets the token of a revoked link', async () => {
    const rotated = { id: 's1', token: 'new.SECRET', url: 'u', etag: '"share.s1.1"' }
    const revoke = vi.fn().mockResolvedValue(undefined)
    const rotate = vi.fn().mockResolvedValue(rotated)
    const { vm } = mountWith(
      { shares: { list: vi.fn().mockResolvedValue([{ id: 's1', etag: '"share.s1.0"' }]), rotate, revoke } },
      () => useShares('9'),
    )
    await vm.list()

    await vm.rotate('s1')
    expect(rotate).toHaveBeenCalledWith('9', 's1', { etag: '"share.s1.0"' })
    expect(vm.lastCreated.value?.token).toBe('new.SECRET')

    // The rotation raised the version: the revoke sends the rotated link's tag.
    await vm.revoke('s1')
    expect(revoke).toHaveBeenCalledWith('9', 's1', { etag: '"share.s1.1"' })
    expect(vm.lastCreated.value).toBeNull()
    expect(vm.shares.value).toEqual([])
  })
})

describe('useActivity', () => {
  it('reads the drive feed or the node feed with the cursor', async () => {
    const forDrive = vi.fn().mockResolvedValue(pageOf([{ id: 'a1' }], 'c2'))
    const forNode = vi.fn().mockResolvedValue(pageOf([{ id: 'n1' }]))
    const drive = mountWith({ activity: { forDrive, forNode } }, () => useActivity({ driveId: '7' })).vm
    const node = mountWith({ activity: { forDrive, forNode } }, () => useActivity({ nodeId: '9' })).vm

    await drive.refresh()
    await drive.loadMore()
    await node.refresh()

    expect(forDrive).toHaveBeenNthCalledWith(1, '7', { cursor: undefined })
    expect(forDrive).toHaveBeenNthCalledWith(2, '7', { cursor: 'c2' })
    expect(forNode).toHaveBeenCalledWith('9', { cursor: undefined })
  })
})

describe('useDocument', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('loads the content and keeps its ETag for the next save', async () => {
    const loaded = vi.fn().mockResolvedValue(content({ type: 'doc' }, '"20.1.5"'))
    const { vm } = mountWith({ documents: { content: loaded } }, () => useDocument('20'))

    await vm.load()

    expect(vm.content.value).toEqual({ type: 'doc' })
    expect(vm.head.value?.etag).toBe('"20.1.5"')
  })

  it('debounces autosave: a single save with the loaded etag fires after the debounce window', async () => {
    const update = vi.fn().mockResolvedValue(content({ type: 'doc', content: [1, 2, 3] }, '"20.1.6"'))
    const loaded = vi.fn().mockResolvedValue(content({ type: 'doc' }, '"20.1.5"'))
    const { vm } = mountWith({ documents: { content: loaded, update } }, () => useDocument('20', { debounceMs: 500 }))
    await vm.load()

    vm.setContent({ type: 'doc', content: [1] })
    vm.setContent({ type: 'doc', content: [1, 2] })
    vm.setContent({ type: 'doc', content: [1, 2, 3] })
    expect(update).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(500)
    await nextTick()

    expect(update).toHaveBeenCalledTimes(1)
    expect(update).toHaveBeenCalledWith('20', { type: 'doc', content: [1, 2, 3] }, { etag: '"20.1.5"' })
    // The next save is based on the ETag the server just answered.
    expect(vm.head.value?.etag).toBe('"20.1.6"')
  })

  it('flush() saves the pending content immediately', async () => {
    const update = vi.fn().mockResolvedValue(content({ type: 'doc' }, '"20.1.6"'))
    const { vm } = mountWith(
      { documents: { content: vi.fn().mockResolvedValue(content({ type: 'doc' })), update } },
      () => useDocument('20'),
    )
    await vm.load()

    vm.setContent({ type: 'doc', content: [] })
    expect(update).not.toHaveBeenCalled()

    await vm.flush()
    expect(update).toHaveBeenCalledTimes(1)
    expect(await vm.flush()).toBeNull()
  })

  it('refuses to save without an ETag (the header was not exposed): an integration error', async () => {
    const update = vi.fn()
    const { vm } = mountWith(
      { documents: { content: vi.fn().mockResolvedValue(content({ type: 'doc' }, null)), update } },
      () => useDocument('20'),
    )
    await vm.load()

    await expect(vm.save()).rejects.toThrow(/ETag is unknown/)
    expect(update).not.toHaveBeenCalled()
  })

  it('refuses to save before anything is loaded or set', async () => {
    const { vm } = mountWith({ documents: {} }, () => useDocument('20'))

    await expect(vm.save()).rejects.toThrow('Nothing to save')
  })

  it('a stale save (412) keeps the local edits, re-reads the head and sets conflict', async () => {
    const error = problem(412, 'document_state_mismatch')
    const loaded = vi.fn()
      .mockResolvedValueOnce(content({ type: 'doc', v: 'mine-base' }, '"20.1.1"'))
      .mockResolvedValueOnce(content({ type: 'doc', v: 'theirs' }, '"20.1.2"'))
    const update = vi.fn().mockRejectedValue(error)
    const { vm } = mountWith({ documents: { content: loaded, update } }, () => useDocument('20'))
    await vm.load()
    vm.setContent({ type: 'doc', v: 'mine' })

    await expect(vm.save()).rejects.toBe(error)

    expect(vm.conflict.value).toBe(error)
    // The local edit survives; the head now carries the server's version and tag.
    expect(vm.content.value).toEqual({ type: 'doc', v: 'mine' })
    expect(vm.head.value?.etag).toBe('"20.1.2"')

    // Saving again overwrites with the fresh tag; taking the server's version is load().
    update.mockResolvedValueOnce(content({ type: 'doc', v: 'mine' }, '"20.1.3"'))
    await vm.save()
    expect(update).toHaveBeenLastCalledWith('20', { type: 'doc', v: 'mine' }, { etag: '"20.1.2"' })
    expect(vm.conflict.value).toBeNull()
  })

  it('a missing precondition (428) is not a conflict', async () => {
    const error = problem(428, 'precondition_required')
    const { vm } = mountWith(
      { documents: { content: vi.fn().mockResolvedValue(content({ type: 'doc' })), update: vi.fn().mockRejectedValue(error) } },
      () => useDocument('20'),
    )
    await vm.load()

    await expect(vm.save()).rejects.toBe(error)
    expect(vm.conflict.value).toBeNull()
  })
})

describe('useDocument — overlapping saves', () => {
  /** A promise settled by the test, to hold a request in flight. */
  function deferred<T>() {
    let resolve!: (value: T) => void
    let reject!: (reason: unknown) => void
    const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej })

    return { promise, resolve, reject }
  }

  it('queues a second save behind the first, so it carries the tag the first answered', async () => {
    const first = deferred<ReturnType<typeof content>>()
    const update = vi.fn()
      .mockReturnValueOnce(first.promise)
      .mockResolvedValueOnce(content({ v: 'C3' }, '"20.1.3"'))
    const { vm } = mountWith(
      { documents: { content: vi.fn().mockResolvedValue(content({ v: 'C1' }, '"20.1.1"')), update } },
      () => useDocument('20'),
    )
    await vm.load()

    vm.setContent({ v: 'C2' })
    const a = vm.save()
    await nextTick()
    vm.setContent({ v: 'C3' })
    const b = vm.save()
    await nextTick()

    // The second save has not gone out with the old tag.
    expect(update).toHaveBeenCalledTimes(1)

    first.resolve(content({ v: 'C2' }, '"20.1.2"'))
    await Promise.all([a, b])

    expect(update).toHaveBeenNthCalledWith(1, '20', { v: 'C2' }, { etag: '"20.1.1"' })
    expect(update).toHaveBeenNthCalledWith(2, '20', { v: 'C3' }, { etag: '"20.1.2"' })
    expect(vm.conflict.value).toBeNull()
    expect(vm.head.value?.etag).toBe('"20.1.3"')
  })

  it('an explicit save supersedes the pending autosave instead of leaving its timer armed', async () => {
    vi.useFakeTimers()
    const update = vi.fn().mockResolvedValue(content({ v: 'C2' }, '"20.1.2"'))
    const { vm } = mountWith(
      { documents: { content: vi.fn().mockResolvedValue(content({ v: 'C1' }, '"20.1.1"')), update } },
      () => useDocument('20', { debounceMs: 500 }),
    )
    await vm.load()

    vm.setContent({ v: 'C2' })
    await vm.save()
    await vi.advanceTimersByTimeAsync(1000)

    expect(update).toHaveBeenCalledTimes(1)
    vi.useRealTimers()
  })

  it('a failed save does not block the ones queued after it', async () => {
    const update = vi.fn()
      .mockRejectedValueOnce(problem(412, 'document_state_mismatch'))
      .mockResolvedValueOnce(content({ v: 'C3' }, '"20.1.3"'))
    const { vm } = mountWith(
      {
        documents: {
          content: vi.fn()
            .mockResolvedValueOnce(content({ v: 'C1' }, '"20.1.1"'))
            .mockResolvedValueOnce(content({ v: 'theirs' }, '"20.1.2"')),
          update,
        },
      },
      () => useDocument('20'),
    )
    await vm.load()

    vm.setContent({ v: 'C3' })
    const a = vm.save().catch((error: unknown) => error)
    const b = vm.save()
    await a
    await b

    expect(update).toHaveBeenNthCalledWith(2, '20', { v: 'C3' }, { etag: '"20.1.2"' })
    expect(vm.conflict.value).toBeNull()
  })
})

describe('useNodes — a late response never rolls a row back', () => {
  function deferred<T>() {
    let resolve!: (value: T) => void
    const promise = new Promise<T>((res) => { resolve = res })

    return { promise, resolve }
  }

  it('keeps the renamed row (and its etag) when a listing built before the rename arrives after it', async () => {
    const before = makeFile({ id: '1', name: 'old', etag: '"E1"' })
    const renamed = { ...before, name: 'new', etag: '"E2"' }
    const late = deferred<ReturnType<typeof pageOf>>()
    const children = vi.fn()
      .mockResolvedValueOnce(pageOf([before]))
      .mockReturnValueOnce(late.promise)
    const update = vi.fn().mockResolvedValue(renamed)
    const { vm } = mountWith({ nodes: { children, update } }, () => useNodes('7'))
    await vm.refresh()

    const refreshing = vm.refresh()
    await vm.rename(before, 'new')
    late.resolve(pageOf([before]))
    await refreshing

    expect(vm.nodes.value[0].name).toBe('new')
    expect(vm.nodes.value[0].etag).toBe('"E2"')
  })

  it('does not bring back a node that was trashed while the listing was in flight', async () => {
    const one = makeFile({ id: '1' })
    const two = makeFile({ id: '2' })
    const late = deferred<ReturnType<typeof pageOf>>()
    const children = vi.fn()
      .mockResolvedValueOnce(pageOf([one, two]))
      .mockReturnValueOnce(late.promise)
    const { vm } = mountWith(
      { nodes: { children, trash: vi.fn().mockResolvedValue({ ...one, trashed_at: 'now' }) } },
      () => useNodes('7'),
    )
    await vm.refresh()

    const refreshing = vm.refresh()
    await vm.trash(one)
    late.resolve(pageOf([one, two]))
    await refreshing

    expect(vm.nodes.value.map((n) => n.id)).toEqual(['2'])
  })

  it('takes the page of the newest listing when an older one answers last', async () => {
    const stale = deferred<ReturnType<typeof pageOf>>()
    const children = vi.fn()
      .mockReturnValueOnce(stale.promise)
      .mockResolvedValueOnce(pageOf([makeFile({ id: '9', name: 'fresh' })]))
    const { vm } = mountWith({ nodes: { children } }, () => useNodes('7'))

    const first = vm.refresh()
    await vm.refresh()
    stale.resolve(pageOf([makeFile({ id: '1', name: 'stale' })]))
    await first

    expect(vm.nodes.value.map((n) => n.id)).toEqual(['9'])
  })

  it('keeps a starred row when a snapshot from before the star arrives late', async () => {
    const before = makeFile({ id: '1' })
    const starred = { ...before, viewer: { starred: true }, etag: '"E2"' }
    const late = deferred<ReturnType<typeof pageOf>>()
    const children = vi.fn()
      .mockResolvedValueOnce(pageOf([before]))
      .mockReturnValueOnce(late.promise)
    const { vm } = mountWith({ nodes: { children, star: vi.fn().mockResolvedValue(starred) } }, () => useNodes('7'))
    await vm.refresh()

    const refreshing = vm.refresh()
    await vm.star(before)
    late.resolve(pageOf([before]))
    await refreshing

    expect(vm.nodes.value[0].viewer.starred).toBe(true)
  })
})

describe('useRevisions', () => {
  it('lists, snapshots, sets retention and restores with the document ETag', async () => {
    const first = { id: 'r1', number: 1, label: null, keep_forever: false }
    const snap = { id: 'r2', number: 2, label: 'v2', keep_forever: true }
    const restored = content({ type: 'doc' }, '"20.2.1"')
    const client = {
      documents: {
        revisions: vi.fn().mockResolvedValue(pageOf([first])),
        snapshot: vi.fn().mockResolvedValue(snap),
        setRevisionRetention: vi.fn().mockResolvedValue({ ...first, keep_forever: true }),
        restoreRevision: vi.fn().mockResolvedValue(restored),
      },
    }
    const { vm } = mountWith(client, () => useRevisions('20'))

    await vm.list()
    await vm.snapshot({ label: 'v2', keepForever: true })
    expect(vm.revisions.value.map((r) => r.id)).toEqual(['r2', 'r1'])

    await vm.setRetention('r1', true)
    expect(vm.revisions.value.find((r) => r.id === 'r1')?.keep_forever).toBe(true)

    expect(await vm.restore('r1', { etag: '"20.1.9"' })).toBe(restored)
    expect(client.documents.restoreRevision).toHaveBeenCalledWith('20', 'r1', { etag: '"20.1.9"' })
  })
})

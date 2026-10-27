import { describe, expect, it } from 'vitest'
import {
  applyChangePage,
  bootstrapSyncCache,
  emptySyncCache,
  isValidSyncCache,
  nextSyncStep,
  pinNode,
  resolveRevalidation,
  resolveSubtreeReconciliation,
  setTrashView,
  shouldResetSync,
  unpinNode,
} from '../../src/api/sync'
import type { SyncCache } from '../../src/api/sync'
import type { ChangePage, Node } from '../../src/api/types'

const node = (id: string, parent: string | null, key: string | null): Node => ({ id, parent_id: parent, sync_key: key }) as unknown as Node
const upsert = (n: Node, subtree = false) => ({ operation: 'upsert', sync_key: n.sync_key, node_id: n.id, subtree, node_sequence: 1, node: n })
const remove = (key: string) => ({ operation: 'remove', sync_key: key })
const seeded = (nodes: Node[], cursor = 'c1'): SyncCache => ({ ...emptySyncCache(), cursor, nodes: Object.fromEntries(nodes.map(n => [n.id, n])) })
const page = (data: unknown[], next = 'c2'): ChangePage => ({ data, meta: { next_cursor: next, has_more: false } }) as unknown as ChangePage

describe('keyed change feed cache', () => {
  it('removes a node and its descendants by sync key and moves the cursor with the nodes', () => {
    const folder = node('1', null, 'k-1')
    const child = node('2', '1', 'k-2')
    const start = applyChangePage(emptySyncCache(), page([upsert(folder), upsert(child)], 'c1'))

    const next = applyChangePage(start, page([remove('k-1')], 'c2'))

    expect(Object.keys(start.nodes)).toEqual(['1', '2'])
    expect(next).toEqual({ ...emptySyncCache(), cursor: 'c2', scanned: 3 })
  })

  it('treats a remove of an unknown key as harmless', () => {
    const start = seeded([node('1', null, 'k-1')])

    const next = applyChangePage(start, page([remove('unknown')], 'c2'))

    expect(Object.keys(next.nodes)).toEqual(['1'])
    expect(next.cursor).toBe('c2')
  })

  it('leaves the cache untouched when a page cannot be applied (atomic checkpoint)', () => {
    const start = seeded([node('1', null, 'k-1')])

    expect(() => applyChangePage(start, page([remove('k-1'), upsert(node('3', null, null))], 'c2'))).toThrow('sync_key')
    expect(start).toEqual(seeded([node('1', null, 'k-1')]))
    expect(start.scanned).toBe(0)
  })

  it('invalidates a legacy cache whose nodes have no key', () => {
    expect(isValidSyncCache(seeded([node('1', null, null)]))).toBe(false)
    expect(isValidSyncCache(seeded([node('1', null, 'k-1')]))).toBe(true)
  })

  it('resets on a 400 or 410 from the feed only', () => {
    expect([400, 410, 404, 500].map(s => shouldResetSync(s))).toEqual([true, true, false, false])
  })

  it('does not reset again while the cache is still waiting for its bootstrap (no reset storm)', () => {
    expect(shouldResetSync(410, seeded([node('1', null, 'k-1')]))).toBe(true)
    expect(shouldResetSync(410, emptySyncCache())).toBe(false)
    expect(shouldResetSync(400, emptySyncCache())).toBe(false)
  })

  it('revalidates a pinned descendant on its own when its ancestor is removed, and never by raw id', () => {
    const folder = node('1', null, 'k-1')
    const pinned = node('2', '1', 'k-2')
    const below = node('3', '2', 'k-3')
    const plain = node('4', '1', 'k-4')
    const start = pinNode(seeded([folder, pinned, below, plain]), '2')

    const next = applyChangePage(start, page([remove('k-1')], 'c2'))

    expect(Object.keys(next.nodes)).toEqual([])
    expect(next.revalidate).toEqual(['2'])
    expect(next.pinned).toEqual(['2'])
    // A remove carries a key only: the raw node id of a pinned node removes nothing.
    expect(Object.keys(applyChangePage(start, page([remove('2')])).nodes).sort()).toEqual(['1', '2', '3', '4'])

    const kept = resolveRevalidation(next, '2', node('2', null, 'k-2b'))
    expect(kept.nodes['2']?.sync_key).toBe('k-2b')
    expect(kept.revalidate).toEqual([])
    const gone = resolveRevalidation(next, '2', null)
    expect(gone.pinned).toEqual([])
    expect(gone.revalidate).toEqual([])
  })

  it('removing a pinned node by its own key drops it without queueing a revalidation', () => {
    const start = pinNode(seeded([node('1', null, 'k-1'), node('2', '1', 'k-2')]), '2')

    const next = applyChangePage(start, page([remove('k-2')]))

    expect(Object.keys(next.nodes)).toEqual(['1'])
    expect(next.revalidate).toEqual([])
    expect(unpinNode(next, '2').pinned).toEqual([])
  })

  it('keeps the trash view independent of feed removes and of the live view', () => {
    const live = node('1', null, 'k-1')
    const trashed = node('9', null, 'k-9')
    const start = setTrashView(seeded([live]), [trashed])

    const next = applyChangePage(start, page([remove('k-9'), remove('k-1')]))

    expect(next.nodes).toEqual({})
    expect(Object.keys(next.trash)).toEqual(['9'])
    expect(() => setTrashView(start, [node('8', null, null)])).toThrow('sync_key')
    expect(isValidSyncCache({ ...start, trash: { 8: node('8', null, null) } })).toBe(false)
  })

  it('bootstraps from the start cursor and a keyed listing, keeping pins and the new keys after an owner bump', () => {
    const old = pinNode(seeded([node('1', null, 'k-old'), node('2', null, 'k-2')], 'c-old'), '2')

    const fresh = bootstrapSyncCache(old, 'c-start', [node('1', null, 'k-new')])

    expect(fresh.cursor).toBe('c-start')
    expect(fresh.nodes['1']?.sync_key).toBe('k-new')
    expect(fresh.pinned).toEqual(['2'])
    expect(fresh.revalidate).toEqual(['2'])
    // A remove under the old key (before the bump) finds nothing.
    expect(Object.keys(applyChangePage(fresh, page([remove('k-old')])).nodes)).toEqual(['1'])
    expect(() => bootstrapSyncCache(old, 'c', [node('1', null, null)])).toThrow('sync_key')
  })

  it('wakes up into a bootstrap for a keyless or cursorless cache, a revalidation for queued pins, else the feed', () => {
    expect(nextSyncStep(emptySyncCache())).toBe('bootstrap')
    expect(nextSyncStep(seeded([node('1', null, null)]))).toBe('bootstrap')
    expect(nextSyncStep({ ...seeded([node('1', null, 'k-1')]), revalidate: ['1'] })).toBe('revalidate')
    expect(nextSyncStep(seeded([node('1', null, 'k-1')]))).toBe('feed')
  })

  it('keeps the scanned progress across pages and through a refused page', () => {
    const first = applyChangePage(emptySyncCache(), page([upsert(node('1', null, 'k-1')), upsert(node('2', null, 'k-2'))], 'c1'))

    expect(first.scanned).toBe(2)
    expect(() => applyChangePage(first, page([upsert(node('3', null, null))], 'c2'))).toThrow('sync_key')
    expect(first.scanned).toBe(2)
    expect(applyChangePage(first, page([remove('nope')], 'c2')).scanned).toBe(3)
  })
})

describe('subtree upsert starts root reconciliation', () => {
  const folder = node('1', null, 'k-1')
  const stale = node('2', '1', 'k-2')
  const deeper = node('3', '2', 'k-3')

  it('keeps folder F directly visible but stops showing its revoked inherited descendants, with the cursor still at c0', () => {
    const start = seeded([folder, stale, deeper], 'c0')

    const next = applyChangePage(start, page([upsert(folder, true)], 'c1'))

    expect(Object.keys(next.nodes)).toEqual(['1'])
    expect(next.cursor).toBe('c0')
    expect(next.pendingCursor).toBe('c1')
    expect(next.reconcile).toEqual(['1'])
    expect(nextSyncStep(next)).toBe('reconcile')
    // A plain upsert of the same folder touches one entry and moves the cursor at once.
    const plain = applyChangePage(start, page([upsert(folder)], 'c1'))
    expect(Object.keys(plain.nodes)).toEqual(['1', '2', '3'])
    expect(plain).toMatchObject({ cursor: 'c1', pendingCursor: null, reconcile: [] })
  })

  it('advances the checkpoint only after the fresh listing: stale gone, newly visible shown', () => {
    const start = seeded([folder, stale, deeper], 'c0')
    const pending = applyChangePage(start, page([upsert(folder, true)], 'c1'))
    const fresh = node('5', '1', 'k-5')

    const done = resolveSubtreeReconciliation(pending, '1', [fresh])

    expect(Object.keys(done.nodes).sort()).toEqual(['1', '5'])
    expect(done).toMatchObject({ cursor: 'c1', pendingCursor: null, reconcile: [] })
    expect(nextSyncStep(done)).toBe('feed')
  })

  it('shows the descendants of a copied or restored folder that the cache never held', () => {
    const copy = node('7', null, 'k-7')
    const pending = applyChangePage(seeded([], 'c0'), page([upsert(copy, true)], 'c1'))
    expect(pending).toMatchObject({ cursor: 'c0', pendingCursor: 'c1', reconcile: ['7'] })

    const done = resolveSubtreeReconciliation(pending, '7', [node('8', '7', 'k-8'), node('9', '8', 'k-9')])

    expect(Object.keys(done.nodes).sort()).toEqual(['7', '8', '9'])
    expect(done.cursor).toBe('c1')
  })

  it('does not advance the checkpoint or show stale nodes when the reconciliation fails or is unfinished', () => {
    const pending = applyChangePage(seeded([folder, stale], 'c0'), page([upsert(folder, true)], 'c1'))

    expect(() => resolveSubtreeReconciliation(pending, '1', [node('5', '1', null)])).toThrow('sync_key')
    expect(pending).toMatchObject({ cursor: 'c0', pendingCursor: 'c1', reconcile: ['1'] })
    expect(Object.keys(pending.nodes)).toEqual(['1'])
    // The next page is not applied over an unfinished reconciliation, so no later cursor can skip it.
    expect(() => applyChangePage(pending, page([], 'c2'))).toThrow('reconciliation')
  })

  it('moves the cursor only after all roots of the page are reconciled', () => {
    const other = node('10', null, 'k-10')
    const pending = applyChangePage(seeded([folder, stale, other], 'c0'), page([upsert(folder, true), upsert(other, true)], 'c1'))
    expect(pending.reconcile).toEqual(['1', '10'])

    const half = resolveSubtreeReconciliation(pending, '1', [])
    expect(half).toMatchObject({ cursor: 'c0', pendingCursor: 'c1', reconcile: ['10'] })

    const all = resolveSubtreeReconciliation(half, '10', [node('11', '10', 'k-11')])
    expect(all).toMatchObject({ cursor: 'c1', pendingCursor: null, reconcile: [] })
    expect(Object.keys(all.nodes).sort()).toEqual(['1', '10', '11'])
    // Resolving a root that is not pending is harmless.
    expect(resolveSubtreeReconciliation(all, '1', [])).toBe(all)
  })

  it('drops a reconciled folder the reader no longer sees and a folder removed in the same page', () => {
    const pending = applyChangePage(seeded([folder, stale], 'c0'), page([upsert(folder, true)], 'c1'))

    const gone = resolveSubtreeReconciliation(pending, '1', null)
    expect(gone.nodes).toEqual({})
    expect(gone).toMatchObject({ cursor: 'c1', reconcile: [] })

    const removed = applyChangePage(seeded([folder, stale], 'c0'), page([upsert(folder, true), remove('k-1')], 'c1'))
    expect(removed).toMatchObject({ cursor: 'c1', pendingCursor: null, reconcile: [], nodes: {} })
  })

  it('keeps a pinned independent descendant for its own revalidation, and the trash view apart', () => {
    const trashed = node('9', null, 'k-9')
    const start = setTrashView(pinNode(seeded([folder, stale, deeper], 'c0'), '3'), [trashed])

    const pending = applyChangePage(start, page([upsert(folder, true)], 'c1'))

    expect(Object.keys(pending.nodes)).toEqual(['1'])
    expect(pending.revalidate).toEqual(['3'])
    expect(pending.pinned).toEqual(['3'])
    expect(Object.keys(pending.trash)).toEqual(['9'])

    const listed = resolveSubtreeReconciliation(pending, '1', [node('5', '1', 'k-5')])
    expect(listed.revalidate).toEqual(['3'])
    expect(Object.keys(listed.trash)).toEqual(['9'])
    const proven = resolveRevalidation(listed, '3', node('3', null, 'k-3b'))
    expect(proven.nodes['3']?.sync_key).toBe('k-3b')
    // A reconciliation finishing after the pin was proven on its own keeps it.
    const early = resolveRevalidation(pending, '3', node('3', null, 'k-3b'))
    expect(resolveSubtreeReconciliation(early, '1', [])).toMatchObject({ cursor: 'c1', revalidate: [] })
    expect(Object.keys(resolveSubtreeReconciliation(early, '1', []).nodes).sort()).toEqual(['1', '3'])
  })

  it('does not queue a pinned descendant again that was proven on its own while still listed under the folder', () => {
    const pinnedBelow = node('3', '2', 'k-3')
    const pending = applyChangePage(pinNode(seeded([folder, stale, pinnedBelow], 'c0'), '3'), page([upsert(folder, true)], 'c1'))
    expect(pending.revalidate).toEqual(['3'])
    // Read on its own, the pinned node keeps a parent that sits under the reconciled folder.
    const early = resolveRevalidation(pending, '3', node('3', '2', 'k-3b'))

    const done = resolveSubtreeReconciliation(early, '1', [])

    expect(done.nodes['3']?.sync_key).toBe('k-3b')
    expect(done.revalidate).toEqual([])
    expect(done.cursor).toBe('c1')
    expect(nextSyncStep(done)).toBe('feed')
  })
})

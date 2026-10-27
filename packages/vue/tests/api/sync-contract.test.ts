import { describe, expect, it } from 'vitest'
import {
  applyChangePage,
  bootstrapSyncCache,
  emptySyncCache,
  isValidSyncCache,
  nextSyncStep,
  pinNode,
  resolveRevalidation,
  setTrashView,
  shouldResetSync,
  unpinNode,
} from '../../src/api/sync'
import type { SyncCache } from '../../src/api/sync'
import type { ChangePage, Node } from '../../src/api/types'

const node = (id: string, parent: string | null, key: string | null): Node => ({ id, parent_id: parent, sync_key: key }) as unknown as Node
const upsert = (n: Node) => ({ operation: 'upsert', sync_key: n.sync_key, node_id: n.id, subtree: false, node_sequence: 1, node: n })
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

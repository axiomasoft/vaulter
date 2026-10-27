import type { Change, ChangeRemove, ChangeUpsert, ChangePage, Node } from './types'

/**
 * A client copy of what the change feed shows one reader (PLAN4.D20). The cursor and the nodes
 * move together: a page is applied as a whole and the next cursor is stored with its result.
 */
export interface SyncCache {
  /** The cursor to continue from; null before the first page. */
  cursor: string | null
  /** Nodes by id, each with the reader-scoped `sync_key` a remove names it by. */
  nodes: Record<string, Node>
  /** The trash view: listed on its own and never touched by a feed remove, which speaks of the live view. */
  trash: Record<string, Node>
  /** Ids the user pinned explicitly (kept for offline use); a pin is not a cache entry. */
  pinned: string[]
  /** Pinned ids that lost their ancestor in a remove: each is fetched on its own before it is trusted or dropped. */
  revalidate: string[]
  /** Changes applied since the last bootstrap; a page that cannot be applied does not move it. */
  scanned: number
}

export const emptySyncCache = (): SyncCache => ({ cursor: null, nodes: {}, trash: {}, pinned: [], revalidate: [], scanned: 0 })

const isRemove = (change: Change): change is ChangeRemove => change.operation === 'remove'

const hasKey = (node: Node): boolean => typeof node.sync_key === 'string' && node.sync_key !== ''

/**
 * A cache holding a node without a string `sync_key` (live or in the trash view) was built before keyed
 * removes: it cannot be reconciled by key and must be discarded and listed again.
 */
export const isValidSyncCache = (cache: SyncCache): boolean =>
  [...Object.values(cache.nodes), ...Object.values(cache.trash ?? {})].every(hasKey)

/**
 * Drop `id` and every node below it. A pinned node below it is not trusted any more, but it is not
 * proven gone either — the reader may see it through a grant of its own — so it is queued for revalidation.
 */
function forget(nodes: Record<string, Node>, id: string, pinned: ReadonlySet<string>, revalidate: Set<string>, top = true): void {
  delete nodes[id]
  if (!top && pinned.has(id)) revalidate.add(id)
  for (const [childId, child] of Object.entries(nodes)) {
    if (child.parent_id === id) forget(nodes, childId, pinned, revalidate, false)
  }
}

/**
 * Apply one page atomically: the result holds every change and the page's next cursor, or the
 * call throws and the given cache is left as it was. A remove matches by `sync_key`; a key the
 * cache does not know is harmless. An upsert without a key (a legacy server) is refused.
 */
export function applyChangePage(cache: SyncCache, page: ChangePage): SyncCache {
  const nodes: Record<string, Node> = { ...cache.nodes }
  const pinned = new Set(cache.pinned)
  const revalidate = new Set(cache.revalidate)
  for (const change of page.data) {
    if (isRemove(change)) {
      const known = Object.entries(nodes).find(([, node]) => node.sync_key === change.sync_key)
      if (known !== undefined) {
        forget(nodes, known[0], pinned, revalidate)
        // The node itself left this reader's view: its pin stays but there is nothing to revalidate.
        revalidate.delete(known[0])
      }
      continue
    }
    const upsert: ChangeUpsert = change
    if (!hasKey(upsert.node)) {
      throw new Error('[Vaulter] A change without a sync_key cannot be applied; list the drive again.')
    }
    nodes[upsert.node.id] = upsert.node
    revalidate.delete(upsert.node.id)
  }
  return { ...cache, cursor: page.meta.next_cursor, nodes, revalidate: [...revalidate], scanned: cache.scanned + page.data.length }
}

/**
 * The start cursor taken before a full listing plus that listing: the committed bootstrap. Everything
 * after the cursor is replayed by the feed, so a change made while the listing ran is not lost. Pins survive;
 * a listing node without a key is refused whole.
 */
export function bootstrapSyncCache(previous: SyncCache, startCursor: string, listing: Node[]): SyncCache {
  if (!listing.every(hasKey)) throw new Error('[Vaulter] A listing without sync_key cannot seed the cache; update the server.')
  return {
    ...emptySyncCache(),
    cursor: startCursor,
    nodes: Object.fromEntries(listing.map(node => [node.id, node])),
    pinned: previous.pinned,
    // A pin outside the listing has to prove itself on its own.
    revalidate: previous.pinned.filter(id => !listing.some(node => node.id === id)),
  }
}

/** Replace the trash view with a trash listing; the live view and the cursor are not involved. */
export function setTrashView(cache: SyncCache, listing: Node[]): SyncCache {
  if (!listing.every(hasKey)) throw new Error('[Vaulter] A trash listing without sync_key cannot be cached.')
  return { ...cache, trash: Object.fromEntries(listing.map(node => [node.id, node])) }
}

export const pinNode = (cache: SyncCache, id: string): SyncCache =>
  cache.pinned.includes(id) ? cache : { ...cache, pinned: [...cache.pinned, id] }

export const unpinNode = (cache: SyncCache, id: string): SyncCache => ({
  ...cache,
  pinned: cache.pinned.filter(p => p !== id),
  revalidate: cache.revalidate.filter(p => p !== id),
})

/**
 * The answer of reading a queued pinned node on its own: the node (keyed) is trusted again, a null
 * (404) means the reader no longer sees it and the pin is released.
 */
export function resolveRevalidation(cache: SyncCache, id: string, current: Node | null): SyncCache {
  if (current === null) return unpinNode({ ...cache, nodes: Object.fromEntries(Object.entries(cache.nodes).filter(([key]) => key !== id)) }, id)
  if (!hasKey(current)) throw new Error('[Vaulter] A node without a sync_key cannot be trusted; update the server.')
  return { ...cache, nodes: { ...cache.nodes, [current.id]: current }, revalidate: cache.revalidate.filter(p => p !== id) }
}

/** The feed answered 400 `invalid_cursor` or 410 `cursor_expired`: the cache is rebuilt from a listing. */
export const shouldResetSync = (status: number, cache: SyncCache | null = null): boolean =>
  (status === 400 || status === 410) && (cache === null || cache.cursor !== null)

/**
 * What a wake-up of the feed (the tab came back, the network returned) has to do first: a cache that is
 * keyless or has no cursor is rebuilt, one with queued pins revalidates them, otherwise the feed continues.
 */
export const nextSyncStep = (cache: SyncCache): 'bootstrap' | 'revalidate' | 'feed' =>
  cache.cursor === null || !isValidSyncCache(cache) ? 'bootstrap' : cache.revalidate.length > 0 ? 'revalidate' : 'feed'

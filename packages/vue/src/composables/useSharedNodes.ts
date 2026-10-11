import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type { CursorQuery, Node, PageMeta } from '../api/types'

export interface UseSharedNodesReturn {
  /** The nodes shared with the actor (flat, cross-drive). */
  nodes: ShallowRef<Node[]>
  /** The cursor meta of the last load, or null before first load. */
  meta: ShallowRef<PageMeta | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  /** Whether another scan page exists, including after an empty visible page. */
  hasMore: Ref<boolean>
  /** (Re)load the first page, replacing the list. */
  refresh: () => Promise<Node[]>
  /** Append the next page (if any) to the list. */
  loadMore: () => Promise<Node[]>
}

/**
 * Reactive "shared with me" listing — the nodes the actor holds a live grant on, served by
 * the cross-drive `GET /shared` endpoint. A FLAT list: the set spans drives, so unlike
 * {@link useNodes} there is no folder navigation. Paging follows the opaque cursor,
 * including empty pages when the server reaches its scan budget.
 *
 * It does NOT auto-load — the component calls `refresh()` on mount, matching the Drive idiom.
 *
 * Both `refresh()` and `loadMore()` are guarded against stale overlapping loads (a shared
 * `loadSeq` token): a slower earlier call can never overwrite the list a later call already
 * replaced, and `loadMore` is re-entrant-safe (it no-ops while a load is in flight) so a
 * double-click can't append the same page twice.
 */
export function useSharedNodes(query?: CursorQuery): UseSharedNodesReturn {
  const client = useVaulterClient()
  const nodes = shallowRef<Node[]>([])
  const meta = shallowRef<PageMeta | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)
  const hasMore = ref(false)

  const perPage = query?.perPage
  let loadSeq = 0

  function syncHasMore(): void {
    hasMore.value = meta.value?.next_cursor != null
  }

  async function refresh(): Promise<Node[]> {
    const seq = ++loadSeq
    loading.value = true
    error.value = null
    try {
      const page = await client.nodes.shared({ cursor: query?.cursor ?? null, perPage })
      // A later refresh already won — drop this stale page entirely.
      if (seq !== loadSeq) {
        return nodes.value
      }
      nodes.value = page.data
      meta.value = page.meta
      syncHasMore()
      return nodes.value
    } catch (caught) {
      if (seq === loadSeq) {
        error.value = caught
      }
      throw caught
    } finally {
      if (seq === loadSeq) {
        loading.value = false
      }
    }
  }

  async function loadMore(): Promise<Node[]> {
    // A second click (or a click during a refresh) is a no-op while a load is in flight —
    // otherwise two clicks both pass the hasMore check on the same meta and append the SAME
    // page twice (duplicate node ids → VaulterNodeList key clash).
    if (loading.value || !hasMore.value || meta.value == null) {
      return nodes.value
    }
    const seq = ++loadSeq
    const next = meta.value.next_cursor
    loading.value = true
    error.value = null
    try {
      const page = await client.nodes.shared({ cursor: next, perPage })
      // A later refresh/loadMore already won — never graft this stale page onto the list
      // (or rewind meta) it already replaced.
      if (seq !== loadSeq) {
        return nodes.value
      }
      nodes.value = [...nodes.value, ...page.data]
      meta.value = page.meta
      syncHasMore()
      return nodes.value
    } catch (caught) {
      if (seq === loadSeq) {
        error.value = caught
      }
      throw caught
    } finally {
      if (seq === loadSeq) {
        loading.value = false
      }
    }
  }

  return { nodes, meta, loading, error, hasMore, refresh, loadMore }
}

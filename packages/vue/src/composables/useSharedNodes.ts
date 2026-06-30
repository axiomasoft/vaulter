import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type { Node, PageMeta, SharedNodesQuery } from '../types'

export interface UseSharedNodesReturn {
  /** The nodes shared with the actor (flat, cross-storage). */
  nodes: ShallowRef<Node[]>
  /** The page meta of the last load (page counters + total), or null before first load. */
  meta: ShallowRef<PageMeta | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  /** Whether another page exists (the listing is length-aware, not infinite). */
  hasMore: Ref<boolean>
  /** (Re)load the first page, replacing the list. */
  refresh: () => Promise<Node[]>
  /** Append the next page (if any) to the list. */
  loadMore: () => Promise<Node[]>
}

/**
 * Reactive "shared with me" listing — the nodes the actor holds a live allow-grant
 * on, served by the cross-storage `GET /shared` endpoint. A FLAT list: there is no
 * folder navigation here (the set spans storages), so unlike {@link useNodes} it
 * has no `parentId`/`navigate`. The endpoint is length-aware (page/total), so paging
 * is `page`-based: `loadMore` advances `current_page` until `last_page`.
 *
 * Injection + error handling mirror {@link useNodes}: the client is resolved via
 * `useVaulterClient`, every load flips `loading` and captures into `error`. It does
 * NOT auto-load — the component calls `refresh()` on mount, matching the Drive idiom.
 *
 * Both `refresh()` and `loadMore()` are guarded against stale overlapping loads (a shared
 * `loadSeq` token): a slower earlier call can never overwrite the list a later call already
 * replaced, and `loadMore` is additionally re-entrant-safe (it no-ops while a load is in
 * flight) so a double-click can't append the same page twice.
 */
export function useSharedNodes(query?: SharedNodesQuery): UseSharedNodesReturn {
  const client = useVaulterClient()
  const nodes = shallowRef<Node[]>([])
  const meta = shallowRef<PageMeta | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)
  const hasMore = ref(false)

  const perPage = query?.perPage
  let loadSeq = 0

  function syncHasMore(): void {
    hasMore.value = meta.value != null && meta.value.current_page < meta.value.last_page
  }

  async function refresh(): Promise<Node[]> {
    const seq = ++loadSeq
    loading.value = true
    error.value = null
    try {
      const page = await client.nodes.shared({ page: query?.page ?? 1, perPage })
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
    // Re-entry guard: a second click (or a click during a refresh) is a no-op while a
    // load is in flight — otherwise two clicks both pass the hasMore check on the same
    // meta and append the SAME page twice (duplicate node ids → VaulterNodeList key clash).
    if (loading.value || !hasMore.value || meta.value == null) {
      return nodes.value
    }
    const seq = ++loadSeq
    const next = meta.value.current_page + 1
    loading.value = true
    error.value = null
    try {
      const page = await client.nodes.shared({ page: next, perPage })
      // A later refresh/loadMore already won — never graft this stale page onto the
      // list (or rewind meta) it already replaced.
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

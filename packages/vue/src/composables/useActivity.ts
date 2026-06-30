import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type { Activity, CursorMeta, CursorPage, NodeId, StorageId } from '../types'

export interface UseActivityReturn {
  activity: ShallowRef<Activity[]>
  cursor: ShallowRef<CursorMeta | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  /** (Re)load the first page of the feed. */
  refresh: () => Promise<Activity[]>
  /** Append the next cursor page (if any). */
  loadMore: () => Promise<Activity[]>
}

/**
 * Reactive read of the append-only activity feed for a storage tree OR a single
 * node. Pass `{ storageId }` for the storage feed, `{ nodeId }` for the node feed.
 * Cursor-paginated newest-first.
 */
export function useActivity(scope: { storageId: StorageId } | { nodeId: NodeId }): UseActivityReturn {
  const client = useVaulterClient()
  const activity = shallowRef<Activity[]>([])
  const cursor = shallowRef<CursorMeta | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)

  function fetchPage(cursorValue?: string | null): Promise<CursorPage<Activity>> {
    return 'storageId' in scope
      ? client.activity.forStorage(scope.storageId, { cursor: cursorValue })
      : client.activity.forNode(scope.nodeId, { cursor: cursorValue })
  }

  async function refresh(): Promise<Activity[]> {
    loading.value = true
    error.value = null
    try {
      const page = await fetchPage()
      activity.value = page.data
      cursor.value = page.meta
      return activity.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function loadMore(): Promise<Activity[]> {
    const next = cursor.value?.next_cursor
    if (next == null) {
      return activity.value
    }
    const page = await fetchPage(next)
    activity.value = [...activity.value, ...page.data]
    cursor.value = page.meta
    return activity.value
  }

  return { activity, cursor, loading, error, refresh, loadMore }
}

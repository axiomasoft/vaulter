import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import { createListGuard } from './listGuard'
import type { Activity, CursorPage, DriveId, NodeId, PageMeta } from '../api/types'

export interface UseActivityReturn {
  activity: ShallowRef<Activity[]>
  cursor: ShallowRef<PageMeta | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  /** (Re)load the first page of the feed. */
  refresh: () => Promise<Activity[]>
  /** Append the next cursor page (if any). */
  loadMore: () => Promise<Activity[]>
}

/**
 * Reactive read of the append-only activity feed for a drive OR a single node. Pass
 * `{ driveId }` for the drive feed, `{ nodeId }` for the node feed. Cursor-paginated newest-first.
 */
export function useActivity(scope: { driveId: DriveId } | { nodeId: NodeId }): UseActivityReturn {
  const client = useVaulterClient()
  const activity = shallowRef<Activity[]>([])
  const cursor = shallowRef<PageMeta | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)
  const guard = createListGuard()

  function fetchPage(cursorValue?: string | null): Promise<CursorPage<Activity>> {
    return 'driveId' in scope
      ? client.activity.forDrive(scope.driveId, { cursor: cursorValue })
      : client.activity.forNode(scope.nodeId, { cursor: cursorValue })
  }

  async function refresh(): Promise<Activity[]> {
    const token = guard.begin()
    loading.value = true
    error.value = null
    try {
      const page = await fetchPage()
      // A newer refresh already answered: this page is history.
      if (guard.isCurrent(token)) {
        activity.value = page.data
        cursor.value = page.meta
      }
      return activity.value
    } catch (caught) {
      if (guard.isCurrent(token)) {
        error.value = caught
      }
      throw caught
    } finally {
      loading.value = !guard.end()
    }
  }

  async function loadMore(): Promise<Activity[]> {
    const next = cursor.value?.next_cursor
    const token = next == null ? null : guard.beginMore()
    if (next == null || token === null) {
      return activity.value
    }
    try {
      const page = await fetchPage(next)
      if (guard.isCurrent(token)) {
        activity.value = [...activity.value, ...page.data]
        cursor.value = page.meta
      }
      return activity.value
    } finally {
      guard.endMore()
    }
  }

  return { activity, cursor, loading, error, refresh, loadMore }
}

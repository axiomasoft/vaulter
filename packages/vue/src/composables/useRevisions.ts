import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import { createListGuard } from './listGuard'
import type { DocumentContent, DocumentRevision, IfMatch, NodeId, PageMeta } from '../api/types'

export interface UseRevisionsReturn {
  revisions: ShallowRef<DocumentRevision[]>
  cursor: ShallowRef<PageMeta | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  list: () => Promise<DocumentRevision[]>
  loadMore: () => Promise<DocumentRevision[]>
  /** Snapshot the current content as a (named) revision. */
  snapshot: (options?: { label?: string | null, keepForever?: boolean }) => Promise<DocumentRevision>
  setRetention: (revisionId: string, keepForever: boolean) => Promise<DocumentRevision>
  /**
   * Make a revision the current content. Needs the ETag of the content being replaced
   * (`useDocument().head.etag`): restoring over somebody else's newer save answers 412.
   */
  restore: (revisionId: string, options: IfMatch) => Promise<DocumentContent>
}

/** Reactive revision history of a document node. */
export function useRevisions(nodeId: NodeId): UseRevisionsReturn {
  const client = useVaulterClient()
  const revisions = shallowRef<DocumentRevision[]>([])
  const cursor = shallowRef<PageMeta | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)
  const guard = createListGuard()

  async function list(): Promise<DocumentRevision[]> {
    const token = guard.begin()
    loading.value = true
    error.value = null
    try {
      const page = await client.documents.revisions(nodeId)
      // A newer load or a local change happened meanwhile: this page is history.
      if (guard.isCurrent(token)) {
        revisions.value = page.data
        cursor.value = page.meta
      }
      return revisions.value
    } catch (caught) {
      if (guard.isCurrent(token)) {
        error.value = caught
      }
      throw caught
    } finally {
      loading.value = !guard.end()
    }
  }

  async function loadMore(): Promise<DocumentRevision[]> {
    const next = cursor.value?.next_cursor
    const token = next == null ? null : guard.beginMore()
    if (next == null || token === null) {
      return revisions.value
    }
    try {
      const page = await client.documents.revisions(nodeId, { cursor: next })
      if (guard.isCurrent(token)) {
        revisions.value = [...revisions.value, ...page.data]
        cursor.value = page.meta
      }
      return revisions.value
    } finally {
      guard.endMore()
    }
  }

  async function snapshot(options?: { label?: string | null, keepForever?: boolean }): Promise<DocumentRevision> {
    const created = await client.documents.snapshot(nodeId, options)
    guard.invalidate()
    revisions.value = [created, ...revisions.value]
    return created
  }

  async function setRetention(revisionId: string, keepForever: boolean): Promise<DocumentRevision> {
    const updated = await client.documents.setRevisionRetention(nodeId, revisionId, keepForever)
    guard.invalidate()
    revisions.value = revisions.value.map((revision) => (revision.id === revisionId ? updated : revision))
    return updated
  }

  function restore(revisionId: string, options: IfMatch): Promise<DocumentContent> {
    return client.documents.restoreRevision(nodeId, revisionId, options)
  }

  return { revisions, cursor, loading, error, list, loadMore, snapshot, setRetention, restore }
}

import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import { createListGuard } from './listGuard'
import { requireTag } from './conflict'
import type { Comment, CreateCommentInput, IfMatch, NodeId, PageMeta } from '../api/types'

export interface UseCommentsReturn {
  comments: ShallowRef<Comment[]>
  cursor: ShallowRef<PageMeta | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  /** (Re)load the first page of the thread. */
  list: () => Promise<Comment[]>
  /** Append the next cursor page (if any). */
  loadMore: () => Promise<Comment[]>
  add: (input: CreateCommentInput) => Promise<Comment>
  /**
   * Resolve a thread. Sends the comment's `etag` as If-Match (the held comment's, unless one is passed);
   * a comment resolved or deleted since it was read rejects with a 412 `stale_write` VaulterApiError.
   */
  resolve: (commentId: string, options?: Partial<IfMatch>) => Promise<Comment>
  /** Delete a comment, conditional on its `etag` like `resolve`. */
  remove: (commentId: string, options?: Partial<IfMatch>) => Promise<void>
}

/** Reactive comment thread for a node (`/nodes/{node}/comments`), backed by the injected client. */
export function useComments(nodeId: NodeId): UseCommentsReturn {
  const client = useVaulterClient()
  const comments = shallowRef<Comment[]>([])
  const cursor = shallowRef<PageMeta | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)
  const guard = createListGuard()

  async function list(): Promise<Comment[]> {
    const token = guard.begin()
    loading.value = true
    error.value = null
    try {
      const page = await client.comments.list(nodeId)
      // A newer load or a local change happened meanwhile: this page is history.
      if (guard.isCurrent(token)) {
        comments.value = page.data
        cursor.value = page.meta
      }
      return comments.value
    } catch (caught) {
      if (guard.isCurrent(token)) {
        error.value = caught
      }
      throw caught
    } finally {
      loading.value = !guard.end()
    }
  }

  async function loadMore(): Promise<Comment[]> {
    const next = cursor.value?.next_cursor
    const token = next == null ? null : guard.beginMore()
    if (next == null || token === null) {
      return comments.value
    }
    try {
      const page = await client.comments.list(nodeId, { cursor: next })
      if (guard.isCurrent(token)) {
        comments.value = [...comments.value, ...page.data]
        cursor.value = page.meta
      }
      return comments.value
    } finally {
      guard.endMore()
    }
  }

  async function add(input: CreateCommentInput): Promise<Comment> {
    const created = await client.comments.add(nodeId, input)
    guard.invalidate()
    comments.value = [...comments.value, created]
    return created
  }

  function tagOf(commentId: string, options?: Partial<IfMatch>): IfMatch {
    const held = comments.value.find((comment) => comment.id === commentId)
    return { etag: requireTag(options?.etag, held?.etag, `comment ${commentId}`) }
  }

  async function resolve(commentId: string, options?: Partial<IfMatch>): Promise<Comment> {
    const updated = await client.comments.resolve(nodeId, commentId, tagOf(commentId, options))
    guard.invalidate()
    comments.value = comments.value.map((comment) => (comment.id === commentId ? updated : comment))
    return updated
  }

  async function remove(commentId: string, options?: Partial<IfMatch>): Promise<void> {
    await client.comments.remove(nodeId, commentId, tagOf(commentId, options))
    guard.invalidate()
    comments.value = comments.value.filter((comment) => comment.id !== commentId)
  }

  return { comments, cursor, loading, error, list, loadMore, add, resolve, remove }
}

import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type { CreateCommentPayload, VaulterComment, NodeId } from '../types'

export interface UseCommentsReturn {
  comments: ShallowRef<VaulterComment[]>
  loading: Ref<boolean>
  error: Ref<unknown>
  list: () => Promise<VaulterComment[]>
  add: (payload: CreateCommentPayload) => Promise<VaulterComment>
  resolve: (commentId: NodeId) => Promise<VaulterComment>
  remove: (commentId: NodeId) => Promise<void>
}

/**
 * Reactive comment thread for a document NODE (`/nodes/{node}/comments`), backed by
 * the injected client — the canonical comment surface after ADR-0014.
 */
export function useComments(nodeId: NodeId): UseCommentsReturn {
  const client = useVaulterClient()
  const comments = shallowRef<VaulterComment[]>([])
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function list(): Promise<VaulterComment[]> {
    loading.value = true
    error.value = null
    try {
      comments.value = await client.comments.list(nodeId)
      return comments.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function add(payload: CreateCommentPayload): Promise<VaulterComment> {
    const created = await client.comments.create(nodeId, payload)
    comments.value = [...comments.value, created]
    return created
  }

  async function resolve(commentId: NodeId): Promise<VaulterComment> {
    const updated = await client.comments.resolve(nodeId, commentId)
    comments.value = comments.value.map((comment) => (comment.id === commentId ? updated : comment))
    return updated
  }

  async function remove(commentId: NodeId): Promise<void> {
    await client.comments.remove(nodeId, commentId)
    comments.value = comments.value.filter((comment) => comment.id !== commentId)
  }

  return { comments, loading, error, list, add, resolve, remove }
}

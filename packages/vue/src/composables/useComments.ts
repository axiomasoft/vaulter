import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useDocVaultClient } from './useDocVaultClient'
import type { CreateCommentPayload, DocVaultComment, DocumentId } from '../types'

export interface UseCommentsReturn {
  comments: ShallowRef<DocVaultComment[]>
  loading: Ref<boolean>
  error: Ref<unknown>
  list: () => Promise<DocVaultComment[]>
  add: (payload: CreateCommentPayload) => Promise<DocVaultComment>
  resolve: (commentId: DocumentId) => Promise<DocVaultComment>
  remove: (commentId: DocumentId) => Promise<void>
}

/**
 * Reactive comment thread for a document, backed by the injected client.
 */
export function useComments(documentId: DocumentId): UseCommentsReturn {
  const client = useDocVaultClient()
  const comments = shallowRef<DocVaultComment[]>([])
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function list(): Promise<DocVaultComment[]> {
    loading.value = true
    error.value = null
    try {
      comments.value = await client.comments.list(documentId)
      return comments.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function add(payload: CreateCommentPayload): Promise<DocVaultComment> {
    const created = await client.comments.create(documentId, payload)
    comments.value = [...comments.value, created]
    return created
  }

  async function resolve(commentId: DocumentId): Promise<DocVaultComment> {
    const updated = await client.comments.resolve(documentId, commentId)
    comments.value = comments.value.map((comment) => (comment.id === commentId ? updated : comment))
    return updated
  }

  async function remove(commentId: DocumentId): Promise<void> {
    await client.comments.remove(documentId, commentId)
    comments.value = comments.value.filter((comment) => comment.id !== commentId)
  }

  return { comments, loading, error, list, add, resolve, remove }
}

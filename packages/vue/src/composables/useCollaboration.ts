import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useDocVaultClient } from './useDocVaultClient'
import type { CollaborationConnection, DocumentId } from '../types'

export interface UseCollaborationReturn {
  connection: ShallowRef<CollaborationConnection | null>
  connecting: Ref<boolean>
  error: Ref<unknown>
  fetchConnection: () => Promise<CollaborationConnection>
}

/**
 * Fetches the realtime connection descriptor for a document (channel/room +
 * token) from the backend. Establishing the Yjs provider (Reverb `y-websocket`
 * or Hocuspocus) on top of this descriptor lands in M3.
 */
export function useCollaboration(documentId: DocumentId): UseCollaborationReturn {
  const client = useDocVaultClient()
  const connection = shallowRef<CollaborationConnection | null>(null)
  const connecting = ref(false)
  const error = ref<unknown>(null)

  async function fetchConnection(): Promise<CollaborationConnection> {
    connecting.value = true
    error.value = null
    try {
      const resolved = await client.collaboration.token(documentId)
      connection.value = resolved
      return resolved
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      connecting.value = false
    }
  }

  return { connection, connecting, error, fetchConnection }
}

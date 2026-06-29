import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { Doc as YDoc } from 'yjs'
import { useVaulterClient } from './useVaulterClient'
import { createCollaborationSession, type CollaborationSession, type CreateCollaborationOptions } from '../collaboration'
import type { CollaborationConnection, DocumentId } from '../types'

export interface UseCollaborationReturn {
  connection: ShallowRef<CollaborationConnection | null>
  connecting: Ref<boolean>
  error: Ref<unknown>
  doc: YDoc
  session: ShallowRef<CollaborationSession | null>
  fetchConnection: () => Promise<CollaborationConnection>
  connect: (options?: Partial<Omit<CreateCollaborationOptions, 'doc'>>) => Promise<CollaborationSession>
  disconnect: () => void
}

/**
 * Fetches the realtime connection descriptor and (optionally) opens a Yjs session
 * over it. Bind the returned `doc` to your TipTap editor via the `Collaboration`
 * extension; call `disconnect()` on unmount.
 */
export function useCollaboration(documentId: DocumentId): UseCollaborationReturn {
  const client = useVaulterClient()
  const connection = shallowRef<CollaborationConnection | null>(null)
  const connecting = ref(false)
  const error = ref<unknown>(null)
  const doc = new YDoc()
  const session = shallowRef<CollaborationSession | null>(null)

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

  async function connect(
    options: Partial<Omit<CreateCollaborationOptions, 'doc'>> = {},
  ): Promise<CollaborationSession> {
    const resolved = await fetchConnection()
    const next = createCollaborationSession(resolved, { doc, ...options })
    session.value = next
    return next
  }

  function disconnect(): void {
    session.value?.destroy()
    session.value = null
  }

  return { connection, connecting, error, doc, session, fetchConnection, connect, disconnect }
}

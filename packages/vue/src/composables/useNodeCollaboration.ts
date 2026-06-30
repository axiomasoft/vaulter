import { ref, shallowRef, watch, type Ref, type ShallowRef } from 'vue'
import { Doc as YDoc } from 'yjs'
import { useVaulterClient } from './useVaulterClient'
import {
  createNodeCollaborationSession,
  type Collaborator,
  type CreateNodeCollaborationOptions,
  type NodeCollaborationSessionHandle,
} from '../nodeCollaboration'
import type { NodeCollaborationSession, NodeId } from '../types'

export interface UseNodeCollaborationOptions {
  /** A pre-existing Yjs doc to bind (defaults to a fresh `Y.Doc`). */
  doc?: YDoc
  /** Provider factories passed through to the session (Hocuspocus override / Reverb bridge). */
  providers?: Partial<Omit<CreateNodeCollaborationOptions, 'doc'>>
}

export interface UseNodeCollaborationReturn {
  /** The shared Yjs document to bind to TipTap via the Collaboration extension. */
  doc: YDoc
  /** The resolved backend session descriptor (null until connected). */
  session: ShallowRef<NodeCollaborationSession | null>
  /** The live transport handle (null until connected). */
  handle: ShallowRef<NodeCollaborationSessionHandle | null>
  /** True while the token handshake is in flight. */
  connecting: Ref<boolean>
  /** A live connection flag (first-handshake/disconnect from the provider). */
  connected: Ref<boolean>
  /** Whether writes are disallowed — taken from the backend ability. */
  readOnly: Ref<boolean>
  /** The remote participants (awareness); empty when the transport has no presence. */
  collaborators: Ref<Collaborator[]>
  /** Any error from the token fetch / session build. */
  error: Ref<unknown>
  /** Fetch the token only (no session) — for callers that build their own provider. */
  fetchSession: () => Promise<NodeCollaborationSession>
  /** Fetch the token AND open the Yjs session. Resolves to the live handle. */
  connect: () => Promise<NodeCollaborationSessionHandle>
  /** Tear down the live session (idempotent). */
  disconnect: () => void
}

/**
 * Open a realtime collaboration session for a document NODE (M4b). It fetches the
 * room token from the node collab endpoint, then builds a Yjs session over it — the
 * Hocuspocus transport via `@hocuspocus/provider`, the Reverb transport via the
 * host-supplied `createReverbProvider` bridge. Bind the returned `doc` to TipTap via
 * the `Collaboration` extension and mount a read-only editor when `readOnly` is true;
 * call `disconnect()` on unmount.
 */
export function useNodeCollaboration(
  nodeId: NodeId,
  options: UseNodeCollaborationOptions = {},
): UseNodeCollaborationReturn {
  const client = useVaulterClient()
  const doc = options.doc ?? new YDoc()

  const session = shallowRef<NodeCollaborationSession | null>(null)
  const handle = shallowRef<NodeCollaborationSessionHandle | null>(null)
  const connecting = ref(false)
  const connected = ref(false)
  const readOnly = ref(false)
  const collaborators = ref<Collaborator[]>([])
  const error = ref<unknown>(null)

  // Mirror the live handle's reactive state up through this composable's refs so a
  // caller binds a single, stable surface regardless of when `connect()` runs.
  let stopWatchers: Array<() => void> = []

  async function fetchSession(): Promise<NodeCollaborationSession> {
    connecting.value = true
    error.value = null
    try {
      const resolved = await client.nodeCollaboration.token(nodeId)
      session.value = resolved
      readOnly.value = resolved.read_only
      return resolved
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      connecting.value = false
    }
  }

  async function connect(): Promise<NodeCollaborationSessionHandle> {
    const resolved = await fetchSession()
    const live = createNodeCollaborationSession(resolved, { doc, ...options.providers })

    handle.value = live
    readOnly.value = live.readOnly

    // Forward the handle's reactive connected/collaborators into our own refs so a
    // caller binds a single, stable surface regardless of when `connect()` runs.
    connected.value = live.connected.value
    collaborators.value = live.collaborators.value
    stopWatchers.push(
      watch(live.connected, (value) => { connected.value = value }),
      watch(live.collaborators, (value) => { collaborators.value = value }),
    )

    return live
  }

  function disconnect(): void {
    for (const stop of stopWatchers) {
      stop()
    }
    stopWatchers = []
    handle.value?.destroy()
    handle.value = null
    connected.value = false
    collaborators.value = []
  }

  return {
    doc,
    session,
    handle,
    connecting,
    connected,
    readOnly,
    collaborators,
    error,
    fetchSession,
    connect,
    disconnect,
  }
}

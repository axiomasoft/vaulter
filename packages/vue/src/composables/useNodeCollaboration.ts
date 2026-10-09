import { ref, shallowRef, watch, type Ref, type ShallowRef } from 'vue'
import { Doc as YDoc } from 'yjs'
import { useVaulterClient } from './useVaulterClient'
import {
  createNodeCollaborationSession,
  type Collaborator,
  type CreateNodeCollaborationOptions,
  type NodeCollaborationEnd,
  type NodeCollaborationSessionHandle,
} from '../nodeCollaboration'
import type { NodeCollaborationSession, NodeId } from '../types'

export interface UseNodeCollaborationOptions {
  /** Provider factory override (passed through to the session). */
  providers?: Partial<Omit<CreateNodeCollaborationOptions, 'doc'>>
  /**
   * The document schema version the editor supports. Sent with the session request,
   * and a session in a newer schema is opened read-only with `schemaOutdated` set.
   */
  supportedSchemaVersion?: number
}

export interface UseNodeCollaborationReturn {
  /**
   * The `Y.Doc` to bind to TipTap via the Collaboration extension. It is REPLACED when
   * the hub ends the room's generation (a restore): rebind the editor to the new value
   * and never copy the old document's content over.
   */
  doc: ShallowRef<YDoc>
  /** The resolved backend session descriptor (null until connected). */
  session: ShallowRef<NodeCollaborationSession | null>
  /** The live transport handle (null until connected). */
  handle: ShallowRef<NodeCollaborationSessionHandle | null>
  /** True while the session handshake is in flight. */
  connecting: Ref<boolean>
  /** A live connection flag (first-handshake/disconnect from the provider). */
  connected: Ref<boolean>
  /** Whether writes are disallowed: a read-only session or an outdated editor schema. */
  readOnly: Ref<boolean>
  /** True when the session's schema is newer than `supportedSchemaVersion`. */
  schemaOutdated: Ref<boolean>
  /** The remote participants (awareness); empty when the transport has no presence. */
  collaborators: Ref<Collaborator[]>
  /** Any error from the session fetch / build. */
  error: Ref<unknown>
  /** Fetch the session only (no provider) — for callers that build their own. */
  fetchSession: () => Promise<NodeCollaborationSession>
  /** Fetch the session AND open the Yjs provider. Resolves to the live handle. */
  connect: () => Promise<NodeCollaborationSessionHandle>
  /** Tear down the live session (idempotent). */
  disconnect: () => void
}

/**
 * Open a realtime collaboration session for a document node: fetch the session, open
 * the Hocuspocus provider over a fresh `Y.Doc`, and keep it open across the hub's
 * terminal closes:
 *
 *   - 4409 `epoch_changed` (the document was restored): the old provider and `Y.Doc` are
 *     destroyed, a new session is fetched and a NEW `Y.Doc` replaces `doc` — the old
 *     state is never merged into the restored document;
 *   - 4001 (token expired): a new session is fetched; the `Y.Doc` is kept while the
 *     room is the same generation, replaced otherwise.
 *
 * The content always comes from the hub; do not seed the editor in collaborative mode.
 * Mount a read-only editor while `readOnly` is true; call `disconnect()` on unmount.
 */
export function useNodeCollaboration(
  nodeId: NodeId,
  options: UseNodeCollaborationOptions = {},
): UseNodeCollaborationReturn {
  const client = useVaulterClient()

  const doc = shallowRef(new YDoc())
  const session = shallowRef<NodeCollaborationSession | null>(null)
  const handle = shallowRef<NodeCollaborationSessionHandle | null>(null)
  const connecting = ref(false)
  const connected = ref(false)
  const readOnly = ref(false)
  const schemaOutdated = ref(false)
  const collaborators = ref<Collaborator[]>([])
  const error = ref<unknown>(null)

  let stopWatchers: Array<() => void> = []

  async function fetchSession(): Promise<NodeCollaborationSession> {
    connecting.value = true
    error.value = null
    try {
      const resolved = await client.nodeCollaboration.token(
        nodeId,
        options.supportedSchemaVersion === undefined ? undefined : { schemaVersion: options.supportedSchemaVersion },
      )
      session.value = resolved
      schemaOutdated.value = options.supportedSchemaVersion !== undefined
        && resolved.schema_version > options.supportedSchemaVersion
      readOnly.value = resolved.mode !== 'rw' || schemaOutdated.value
      return resolved
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      connecting.value = false
    }
  }

  function teardown(): void {
    for (const stop of stopWatchers) {
      stop()
    }
    stopWatchers = []
    handle.value?.destroy()
    handle.value = null
    connected.value = false
    collaborators.value = []
  }

  function open(resolved: NodeCollaborationSession): NodeCollaborationSessionHandle {
    const live = createNodeCollaborationSession(resolved, { doc: doc.value, ...options.providers })

    handle.value = live
    connected.value = live.connected.value
    collaborators.value = live.collaborators.value
    stopWatchers.push(
      watch(live.connected, (value) => { connected.value = value }),
      watch(live.collaborators, (value) => { collaborators.value = value }),
      live.onEnd((end) => { void reopen(end) }),
    )

    return live
  }

  async function reopen(end: NodeCollaborationEnd): Promise<void> {
    const previousRoom = session.value?.room
    teardown()

    try {
      const resolved = await fetchSession()
      if (end === 'epoch_changed' || resolved.room !== previousRoom) {
        doc.value.destroy()
        doc.value = new YDoc()
      }
      open(resolved)
    } catch {
      // error is already set; the editor stays disconnected until the host reconnects.
    }
  }

  async function connect(): Promise<NodeCollaborationSessionHandle> {
    teardown()
    return open(await fetchSession())
  }

  function disconnect(): void {
    teardown()
  }

  return {
    doc,
    session,
    handle,
    connecting,
    connected,
    readOnly,
    schemaOutdated,
    collaborators,
    error,
    fetchSession,
    connect,
    disconnect,
  }
}

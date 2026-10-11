import { ref, shallowRef, watch, type Ref, type ShallowRef } from 'vue'
import { Doc as YDoc } from 'yjs'
import { useVaulterClient } from './useVaulterClient'
import { fetchCollaborationSession, type CollaborationOptions } from '../collaboration/session'
import {
  openCollaborationTransport,
  type Collaborator,
  type CollaborationEnd,
  type CollaborationTransportHandle,
} from '../collaboration/transport'
import type { CollaborationSession, NodeId } from '../api/types'

/** A connect() was overtaken by a newer connect()/reopen/disconnect(): not a failure of the session. */
export class CollaborationCancelledError extends Error {
  constructor() {
    super('[Vaulter] The collaboration connection was cancelled.')
    this.name = 'CollaborationCancelledError'
  }
}

export interface UseCollaborationOptions extends CollaborationOptions {
  /**
   * Called after the hub ended the room's generation (`epoch_changed`, a restore) and a new
   * `Y.Doc` replaced the old one. The document's ETag carries the epoch, so the host re-reads
   * the content here (`useDocument().load()`) before the next save.
   */
  onEpochChanged?: () => void | Promise<void>
}

export interface UseCollaborationReturn {
  /**
   * The `Y.Doc` to bind to TipTap via the Collaboration extension. It is REPLACED when the
   * hub ends the room's generation (a restore): rebind the editor to the new value and
   * never copy the old document's content over.
   */
  doc: ShallowRef<YDoc>
  /** The resolved backend session descriptor (null until connected). */
  session: ShallowRef<CollaborationSession | null>
  /** The live transport handle (null until connected). */
  handle: ShallowRef<CollaborationTransportHandle | null>
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
  /** Fetch the session AND open the Yjs provider. Resolves to the live handle. */
  connect: () => Promise<CollaborationTransportHandle>
  /** Tear down the live session (idempotent). */
  disconnect: () => void
}

/**
 * Open a realtime collaboration session for a document node: fetch the session, open the
 * Hocuspocus provider over a fresh `Y.Doc`, and keep it open across the hub's terminal closes:
 *
 *   - 4409 `epoch_changed` (the document was restored): the old provider and `Y.Doc` are
 *     destroyed, a new session is fetched and a NEW `Y.Doc` replaces `doc` — the old state is
 *     never merged into the restored document; `onEpochChanged` then lets the host re-read
 *     the content (and its ETag);
 *   - 4001 (token expired) and 4403 (the writer lost the right to write): a new session is
 *     fetched (read-only after 4403); the `Y.Doc` is kept while the room is the same generation;
 *   - 4422 `store_refused` (the backend refused the state itself): NOT reopened — the same
 *     state would be refused again; `error` is set and the editor stays disconnected.
 *
 * The content always comes from the hub; do not seed the editor in collaborative mode. Mount a
 * read-only editor while `readOnly` is true; call `disconnect()` on unmount.
 */
export function useCollaboration(nodeId: NodeId, options: UseCollaborationOptions = {}): UseCollaborationReturn {
  const client = useVaulterClient()

  const doc = shallowRef(new YDoc())
  const session = shallowRef<CollaborationSession | null>(null)
  const handle = shallowRef<CollaborationTransportHandle | null>(null)
  const connecting = ref(false)
  const connected = ref(false)
  const readOnly = ref(false)
  const schemaOutdated = ref(false)
  const collaborators = ref<Collaborator[]>([])
  const error = ref<unknown>(null)

  let stopWatchers: Array<() => void> = []
  // Every connect(), reopen and disconnect() starts a new generation. A session answer that
  // belongs to an older one is dropped, or it would open a second provider next to the live one.
  let generation = 0
  // The hub ended the room's generation (a restore): whoever opens the next session — the reopen
  // or a connect() that overtook it — must do it on a new Y.Doc, and the host re-reads the content.
  let freshDocPending = false

  /** The session for generation `mine`, or null when a newer connect()/reopen/disconnect() took over. */
  async function resolveSession(mine: number): Promise<CollaborationSession | null> {
    if (mine === generation) {
      connecting.value = true
      error.value = null
    }
    try {
      const resolved = await fetchCollaborationSession(client, nodeId, options)
      if (mine !== generation) {
        return null
      }
      session.value = resolved.session
      schemaOutdated.value = resolved.schemaOutdated
      readOnly.value = resolved.readOnly
      return resolved.session
    } catch (caught) {
      if (mine === generation) {
        error.value = caught
      }
      throw caught
    } finally {
      if (mine === generation) {
        connecting.value = false
      }
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

  function resetDoc(): void {
    doc.value.destroy()
    doc.value = new YDoc()
  }

  function open(resolved: CollaborationSession): CollaborationTransportHandle {
    const live = openCollaborationTransport(resolved, { doc: doc.value, ...options.providers })

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

  async function reopen(end: CollaborationEnd): Promise<void> {
    const previousRoom = session.value?.room
    const mine = ++generation
    teardown()
    if (end === 'epoch_changed') {
      freshDocPending = true
    }

    if (end === 'store_refused') {
      error.value = new Error('The collaboration hub could not save this document (the backend refused its state).')
      return
    }

    try {
      const resolved = await resolveSession(mine)
      if (resolved === null) {
        return
      }
      const epochReset = freshDocPending
      freshDocPending = false
      if (epochReset || resolved.room !== previousRoom) {
        resetDoc()
      }
      open(resolved)
      if (epochReset) {
        await options.onEpochChanged?.()
      }
    } catch {
      // error is already set; the editor stays disconnected until the host reconnects.
    }
  }

  async function connect(): Promise<CollaborationTransportHandle> {
    const mine = ++generation
    teardown()
    const resolved = await resolveSession(mine)
    if (resolved === null) {
      // Superseded while waiting: whoever took over owns the connection now.
      throw new CollaborationCancelledError()
    }

    const epochReset = freshDocPending
    freshDocPending = false
    if (epochReset) {
      resetDoc()
    }
    const live = open(resolved)
    if (epochReset) {
      await options.onEpochChanged?.()
    }

    return live
  }

  function disconnect(): void {
    generation += 1
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
    connect,
    disconnect,
  }
}

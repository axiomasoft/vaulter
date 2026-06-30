import { ref, type Ref } from 'vue'
import { HocuspocusProvider } from '@hocuspocus/provider'
import type { Doc as YDoc } from 'yjs'
import type { NodeCollaborationSession } from './types'

/**
 * The M4b node-collaboration session factory (the Drive surface). It turns a backend
 * {@link NodeCollaborationSession} descriptor into a live Yjs session bound to a shared
 * `Y.Doc`, transport-agnostic:
 *
 *   - `hocuspocus` — opens a `@hocuspocus/provider` against the hub url + room + JWT.
 *   - `reverb`     — bridges Laravel Echo/Reverb to Yjs via a host-supplied
 *                    `createReverbProvider` seam (Reverb is never hard-required).
 *
 * It exposes the reactive `connected` + `collaborators` (awareness) state and a
 * `destroy()` for teardown. `readOnly` is taken straight from the backend — a viewer/
 * commenter gets a read-only session.
 */

/** One remote participant lifted from the awareness states (best-effort shape). */
export interface Collaborator {
  clientId: number
  user?: { id?: string | number, name?: string, color?: string } & Record<string, unknown>
  [field: string]: unknown
}

/** A minimal Yjs awareness surface (the slice of `@hocuspocus/provider`.awareness we read). */
export interface AwarenessLike {
  getStates: () => Map<number, Record<string, unknown>>
  on: (event: 'change' | 'update', handler: () => void) => void
  off: (event: 'change' | 'update', handler: () => void) => void
}

/** The transport handle a provider factory returns. */
export interface NodeCollaborationProviderHandle {
  /** The awareness instance, when the transport exposes presence. */
  awareness?: AwarenessLike | null
  /** Subscribe to connection-status changes; call the returned disposer to unsubscribe. */
  onConnectionChange?: (handler: (connected: boolean) => void) => () => void
  /** Whether the transport reports itself connected right now (best-effort). */
  isConnected?: () => boolean
  destroy: () => void
}

export interface CreateNodeCollaborationOptions {
  /** The shared Yjs document the editor binds to. */
  doc: YDoc
  /** Override the Hocuspocus provider (defaults to `@hocuspocus/provider`). */
  createHocuspocusProvider?: (args: {
    url: string
    name: string
    token: string
    document: YDoc
  }) => NodeCollaborationProviderHandle
  /** Bridge Laravel Echo/Reverb to Yjs — required for the `reverb` driver. */
  createReverbProvider?: (args: {
    channel: string
    connection: string | null
    document: YDoc
  }) => NodeCollaborationProviderHandle
}

export interface NodeCollaborationSessionHandle {
  /** The transport (mirrors the session driver). */
  transport: 'reverb' | 'hocuspocus'
  /** Whether writes are disallowed (from the backend ability). */
  readOnly: boolean
  /** Reactive: a live first-handshake/connection flag. */
  connected: Ref<boolean>
  /** Reactive: the remote participants lifted from awareness (empty when unsupported). */
  collaborators: Ref<Collaborator[]>
  /** The raw provider handle (for advanced host wiring). */
  provider: NodeCollaborationProviderHandle
  /** Tear down the provider + listeners. */
  destroy: () => void
}

function defaultHocuspocusProvider(args: {
  url: string
  name: string
  token: string
  document: YDoc
}): NodeCollaborationProviderHandle {
  const provider = new HocuspocusProvider({
    url: args.url,
    name: args.name,
    token: args.token,
    document: args.document,
  })

  return {
    awareness: provider.awareness as unknown as AwarenessLike | null,
    isConnected: () => provider.isSynced,
    onConnectionChange: (handler) => {
      const onSynced = (): void => handler(true)
      const onDisconnect = (): void => handler(false)
      provider.on('synced', onSynced)
      provider.on('disconnect', onDisconnect)
      return () => {
        provider.off('synced', onSynced)
        provider.off('disconnect', onDisconnect)
      }
    },
    destroy: () => provider.destroy(),
  }
}

function collaboratorsFrom(awareness: AwarenessLike | null | undefined): Collaborator[] {
  if (!awareness) {
    return []
  }

  const list: Collaborator[] = []
  for (const [clientId, state] of awareness.getStates()) {
    list.push({ clientId, ...(state as Record<string, unknown>) })
  }
  return list
}

/**
 * Build a live node-collaboration session from a backend descriptor + a shared doc.
 * The Hocuspocus transport works out of the box; the Reverb transport needs a
 * host-supplied `createReverbProvider` bridge (it is never hard-required).
 */
export function createNodeCollaborationSession(
  session: NodeCollaborationSession,
  options: CreateNodeCollaborationOptions,
): NodeCollaborationSessionHandle {
  const handle = buildProvider(session, options)

  const connected = ref(handle.isConnected?.() ?? false)
  const collaborators = ref<Collaborator[]>(collaboratorsFrom(handle.awareness))

  const disposeConnection = handle.onConnectionChange?.((value) => {
    connected.value = value
  })

  const awareness = handle.awareness ?? null
  const onAwareness = (): void => {
    collaborators.value = collaboratorsFrom(awareness)
  }
  awareness?.on('change', onAwareness)

  function destroy(): void {
    disposeConnection?.()
    awareness?.off('change', onAwareness)
    handle.destroy()
  }

  return {
    transport: session.driver,
    readOnly: session.read_only,
    connected,
    collaborators,
    provider: handle,
    destroy,
  }
}

function buildProvider(
  session: NodeCollaborationSession,
  options: CreateNodeCollaborationOptions,
): NodeCollaborationProviderHandle {
  if (session.driver === 'hocuspocus') {
    const factory = options.createHocuspocusProvider ?? defaultHocuspocusProvider
    return factory({
      url: session.url ?? '',
      name: session.room,
      token: session.token,
      document: options.doc,
    })
  }

  if (!options.createReverbProvider) {
    throw new Error(
      '[Vaulter] The reverb collaboration driver requires a `createReverbProvider` to bridge '
      + 'Laravel Echo/Reverb to Yjs. See the collaboration guide.',
    )
  }

  return options.createReverbProvider({
    channel: session.token,
    connection: session.connection,
    document: options.doc,
  })
}

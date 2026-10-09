import { ref, type Ref } from 'vue'
import { HocuspocusProvider } from '@hocuspocus/provider'
import type { Doc as YDoc } from 'yjs'
import type { NodeCollaborationSession } from './types'

/**
 * Turns a backend {@link NodeCollaborationSession} into a live Hocuspocus session bound
 * to a `Y.Doc`. It exposes the reactive `connected` + `collaborators` (awareness) state,
 * reports when the hub ends the room's generation, and a `destroy()` for teardown.
 *
 * The room's content always comes from the hub: never seed the `Y.Doc` (or call the
 * editor's `setContent`) in collaborative mode — the hub seeds a document once on the
 * server, and a client-side seed merges into duplicated content.
 */

/** Close code the hub sends when the room's generation is over (a restore happened). */
export const EPOCH_CHANGED_CODE = 4409

/** Close reason that accompanies {@link EPOCH_CHANGED_CODE}. */
export const EPOCH_CHANGED_REASON = 'epoch_changed'

/** Close code the hub sends when the connection's token expired. */
export const TOKEN_EXPIRED_CODE = 4001

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

/** Why the hub ended a session: its generation is over, or its token expired. */
export type NodeCollaborationEnd = 'epoch_changed' | 'token_expired'

/** The transport handle a provider factory returns. */
export interface NodeCollaborationProviderHandle {
  /** The awareness instance, when the transport exposes presence. */
  awareness?: AwarenessLike | null
  /** Subscribe to connection-status changes; call the returned disposer to unsubscribe. */
  onConnectionChange?: (handler: (connected: boolean) => void) => () => void
  /** Subscribe to the hub ending the session; call the returned disposer to unsubscribe. */
  onEnd?: (handler: (end: NodeCollaborationEnd) => void) => () => void
  /** Whether the transport reports itself connected right now (best-effort). */
  isConnected?: () => boolean
  destroy: () => void
}

export interface CreateNodeCollaborationOptions {
  /** The Yjs document the editor binds to. Use a fresh one for every session. */
  doc: YDoc
  /** Override the Hocuspocus provider (defaults to `@hocuspocus/provider`). */
  createHocuspocusProvider?: (args: {
    url: string
    name: string
    token: string
    document: YDoc
  }) => NodeCollaborationProviderHandle
}

export interface NodeCollaborationSessionHandle {
  /** The session this handle was built from. */
  session: NodeCollaborationSession
  /** Whether writes are disallowed (`mode: 'ro'`). */
  readOnly: boolean
  /** Reactive: a live first-handshake/connection flag. */
  connected: Ref<boolean>
  /** Reactive: the remote participants lifted from awareness (empty when unsupported). */
  collaborators: Ref<Collaborator[]>
  /** The raw provider handle (for advanced host wiring). */
  provider: NodeCollaborationProviderHandle
  /** Call `handler` when the hub ends this session; returns a disposer. */
  onEnd: (handler: (end: NodeCollaborationEnd) => void) => () => void
  /** Tear down the provider + listeners. */
  destroy: () => void
}

/** How a close or an authentication refusal from the hub ends the session, if it does. */
export function sessionEnd(code: number | undefined, reason: string | undefined): NodeCollaborationEnd | null {
  if (code === EPOCH_CHANGED_CODE || reason === EPOCH_CHANGED_REASON) {
    return 'epoch_changed'
  }

  return code === TOKEN_EXPIRED_CODE ? 'token_expired' : null
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
    onEnd: (handler) => {
      const onClose = (payload: { event?: { code?: number, reason?: string } }): void => {
        const end = sessionEnd(payload?.event?.code, payload?.event?.reason)
        if (end) {
          handler(end)
        }
      }
      const onAuthenticationFailed = (payload: { reason?: string }): void => {
        const end = sessionEnd(undefined, payload?.reason)
        if (end) {
          handler(end)
        }
      }
      provider.on('close', onClose)
      provider.on('authenticationFailed', onAuthenticationFailed)
      return () => {
        provider.off('close', onClose)
        provider.off('authenticationFailed', onAuthenticationFailed)
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

/** Build a live collaboration session from a backend descriptor and a fresh `Y.Doc`. */
export function createNodeCollaborationSession(
  session: NodeCollaborationSession,
  options: CreateNodeCollaborationOptions,
): NodeCollaborationSessionHandle {
  const factory = options.createHocuspocusProvider ?? defaultHocuspocusProvider
  const handle = factory({
    url: session.url ?? '',
    name: session.room,
    token: session.token,
    document: options.doc,
  })

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

  const endDisposers: Array<() => void> = []

  function onEnd(handler: (end: NodeCollaborationEnd) => void): () => void {
    const dispose = handle.onEnd?.(handler) ?? (() => {})
    endDisposers.push(dispose)
    return dispose
  }

  function destroy(): void {
    disposeConnection?.()
    for (const dispose of endDisposers.splice(0)) {
      dispose()
    }
    awareness?.off('change', onAwareness)
    handle.destroy()
  }

  return {
    session,
    readOnly: session.mode !== 'rw',
    connected,
    collaborators,
    provider: handle,
    onEnd,
    destroy,
  }
}

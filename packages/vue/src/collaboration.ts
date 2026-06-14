import { HocuspocusProvider } from '@hocuspocus/provider'
import type { Doc as YDoc } from 'yjs'
import type { CollaborationConnection } from './types'

export interface CollaborationProviderHandle {
  destroy: () => void
}

export interface CollaborationSession {
  transport: 'reverb' | 'hocuspocus'
  destroy: () => void
}

export interface CreateCollaborationOptions {
  /** The shared Yjs document the editor binds to. */
  doc: YDoc
  /** Override the Hocuspocus provider (defaults to `@hocuspocus/provider`). */
  createHocuspocusProvider?: (args: {
    url: string
    name: string
    token: string
    document: YDoc
  }) => CollaborationProviderHandle
  /** Bridge Laravel Echo/Reverb to Yjs — required for the `reverb` driver. */
  createReverbProvider?: (args: {
    channel: string
    token: string
    document: YDoc
  }) => CollaborationProviderHandle
}

function defaultHocuspocusProvider(args: {
  url: string
  name: string
  token: string
  document: YDoc
}): CollaborationProviderHandle {
  const provider = new HocuspocusProvider({
    url: args.url,
    name: args.name,
    token: args.token,
    document: args.document,
  })

  return { destroy: () => provider.destroy() }
}

/**
 * Build a realtime session from a backend connection descriptor. The Hocuspocus
 * transport works out of the box; the Reverb transport is app-specific (wire your
 * Echo/Yjs bridge via `createReverbProvider`).
 */
export function createCollaborationSession(
  connection: CollaborationConnection,
  options: CreateCollaborationOptions,
): CollaborationSession {
  if (connection.driver === 'hocuspocus') {
    const factory = options.createHocuspocusProvider ?? defaultHocuspocusProvider
    const handle = factory({
      url: connection.url ?? '',
      name: connection.room,
      token: connection.token,
      document: options.doc,
    })

    return { transport: 'hocuspocus', destroy: () => handle.destroy() }
  }

  if (!options.createReverbProvider) {
    throw new Error(
      '[DocVault] The reverb collaboration driver requires a `createReverbProvider` to bridge '
      + 'Laravel Echo/Reverb to Yjs. See the collaboration guide.',
    )
  }

  const handle = options.createReverbProvider({
    channel: connection.channel,
    token: connection.token,
    document: options.doc,
  })

  return { transport: 'reverb', destroy: () => handle.destroy() }
}

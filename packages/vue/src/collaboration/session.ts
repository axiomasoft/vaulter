import { Doc as YDoc } from 'yjs'
import type { VaulterClient } from '../api/client'
import type { CollaborationSession, NodeId } from '../api/types'
import {
  openCollaborationTransport,
  type CollaborationTransportHandle,
  type CollaborationTransportOptions,
} from './transport'

export interface CollaborationOptions {
  /**
   * The document schema version the editor supports. It is sent with the session request;
   * a session in a NEWER schema opens read-only with `schemaOutdated` set.
   */
  supportedSchemaVersion?: number
  /** Provider factory override (passed through to the transport). */
  providers?: Partial<Omit<CollaborationTransportOptions, 'doc'>>
}

/** What the backend granted, judged against what this editor can handle. */
export interface ResolvedCollaborationSession {
  session: CollaborationSession
  /** True when the session's schema is newer than `supportedSchemaVersion`. */
  schemaOutdated: boolean
  /** Writes are disallowed: a read-only session (`mode: 'ro'`) or an outdated editor schema. */
  readOnly: boolean
}

export interface CollaborationSessionHandle extends CollaborationTransportHandle, ResolvedCollaborationSession {
  /** The Yjs document the editor binds to (bind it with the Collaboration extension). */
  doc: YDoc
}

/** Ask the backend for a session (`POST documents/{node}/collaboration/session`) and judge its mode and schema. */
export async function fetchCollaborationSession(
  client: VaulterClient,
  nodeId: NodeId,
  options: Pick<CollaborationOptions, 'supportedSchemaVersion'> = {},
): Promise<ResolvedCollaborationSession> {
  const session = await client.documents.collaborationSession(
    nodeId,
    options.supportedSchemaVersion === undefined ? undefined : { schemaVersion: options.supportedSchemaVersion },
  )
  const schemaOutdated = options.supportedSchemaVersion !== undefined
    && session.schema_version > options.supportedSchemaVersion

  return { session, schemaOutdated, readOnly: session.mode !== 'rw' || schemaOutdated }
}

/**
 * Fetch a session and open the realtime transport over a fresh `Y.Doc` (or the one given).
 * The room's content always comes from the hub: never seed the doc or the editor in
 * collaborative mode — a client-side seed merges into duplicated content.
 */
export async function createCollaborationSession(
  client: VaulterClient,
  nodeId: NodeId,
  options: CollaborationOptions & { doc?: YDoc } = {},
): Promise<CollaborationSessionHandle> {
  const resolved = await fetchCollaborationSession(client, nodeId, options)
  const doc = options.doc ?? new YDoc()
  const transport = openCollaborationTransport(resolved.session, { doc, ...options.providers })

  return { ...transport, ...resolved, readOnly: resolved.readOnly, doc }
}

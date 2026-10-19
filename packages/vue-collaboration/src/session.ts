import type { CollaborationSession, NodeId, VaulterClient } from '@vaulter/vue'
import type { CollaborationTransportOptions } from './transport'

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

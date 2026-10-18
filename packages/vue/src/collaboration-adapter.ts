import type { InjectionKey, Ref } from 'vue'
import type { VaulterClient } from './api/client'
import type { NodeId } from './api/types'

// The seam between the document editor and a realtime stack. Types and one injection key only:
// `@vaulter/vue` never imports `yjs` or `@hocuspocus/*`. The implementation is
// `createCollaborationAdapter()` of `@vaulter/vue-collaboration`; a host without it edits
// documents through the REST autosave.

/**
 * What the user can rely on for the edits of this session:
 *
 *   - `synchronized` — connected and in step with the hub, nothing of ours is in flight;
 *   - `pending`      — local edits (or the first sync) are not yet confirmed;
 *   - `saved`        — the backend confirmed (acknowledged) every local edit; only an ack gives it;
 *   - `error`        — an edit cannot be saved (refused, or the queue overflowed); the editor is read-only.
 */
export type CollaborationStatus = 'synchronized' | 'pending' | 'saved' | 'error'

type ReadonlyRef<T> = Readonly<Ref<T>>

/** One remote participant (awareness), best-effort shape. */
export interface Collaborator {
  clientId: number
  user?: { id?: string | number, name?: string, color?: string } & Record<string, unknown>
  [field: string]: unknown
}

export interface CollaborationOpenOptions {
  /** The API client of the host (the adapter asks it for the session). */
  client: VaulterClient
  /**
   * The document schema version the editor supports. A session in a NEWER schema opens
   * read-only with `schemaOutdated` set.
   */
  supportedSchemaVersion?: number
  /**
   * Called after the hub ended the room's generation (a restore) and `doc` was replaced by a new
   * document. The document's ETag carries the epoch, so the host re-reads the content here.
   */
  onEpochChanged?: () => void | Promise<void>
}

/** A live collaboration session of one document node. */
export interface CollaborationHandle {
  /**
   * The shared document the editor binds to (a `Y.Doc` for the Yjs adapter). It is REPLACED when
   * the hub ends the room's generation: read it where it is rendered (it may be a getter over a
   * ref) and rebind the editor to the new value; never copy the old document over.
   */
  readonly doc: unknown
  readonly status: ReadonlyRef<CollaborationStatus>
  /** Writes are disallowed: a read-only session, an outdated editor schema, or a status `error`. */
  readonly readOnly: ReadonlyRef<boolean>
  /** A live connection flag. */
  readonly connected?: ReadonlyRef<boolean>
  /** The remote participants; empty when there is no presence. */
  readonly collaborators?: ReadonlyRef<readonly Collaborator[]>
  /** True when the session's schema is newer than `supportedSchemaVersion`. */
  readonly schemaOutdated?: ReadonlyRef<boolean>
  /** The schema version of the session (null until known). */
  readonly schemaVersion?: ReadonlyRef<number | null>
  /** Any error of the session or of saving. */
  readonly error?: ReadonlyRef<unknown>
  /** Tear the session down (idempotent). */
  end(): void
}

export interface CollaborationAdapter {
  /**
   * Open a session for a document node. A rejection with a `VaulterApiError` of 404 (the route of
   * an inactive module) or 409 `collaboration_unavailable` means "not offered here": the editor
   * falls back to the REST autosave. Any other rejection leaves the editor read-only.
   */
  open(node: NodeId, options: CollaborationOpenOptions): Promise<CollaborationHandle>
}

/** The adapter the host installed (`VaulterPlugin` option `collaboration`); absent = REST autosave. */
export const VaulterCollaborationKey: InjectionKey<CollaborationAdapter> = Symbol('vaulter.collaboration')

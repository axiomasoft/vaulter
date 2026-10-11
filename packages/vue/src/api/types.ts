/**
 * Domain aliases over the generated `schema.d.ts`. Nothing here is hand-written
 * structure: a resource type is always `components['schemas'][…]`, so the client can never
 * drift from `openapi/vaulter-v1.yaml`. Ids are always strings.
 */
import type { components } from './schema'

/**
 * openapi-typescript renders an object schema without `properties` or `additionalProperties`
 * (a node's `properties`, a viewer's `props`, a document body, `meta`) as
 * `Record<string, never>`: nothing could be read from it. Those objects are open by
 * contract, so they are widened to `Record<string, unknown>`; every other key is untouched.
 */
type Open<T> =
  T extends readonly (infer U)[] ? Open<U>[]
    : T extends object
      ? string extends keyof T
        ? [T[keyof T]] extends [never] ? Record<string, unknown> : { [K in keyof T]: Open<T[K]> }
        : { [K in keyof T]: Open<T[K]> }
      : T

type Schemas = { [K in keyof components['schemas']]: Open<components['schemas'][K]> }

export type Node = Schemas['Node']
export type NodeKind = Schemas['NodeKind']
export type NodeAbilities = Schemas['Abilities']
export type NodePresentation = Schemas['NodePresentation']
export type NodeFile = Schemas['NodeFile']
export type NodeDocument = Schemas['NodeDocument']
export type NodeWithIncludes = Schemas['NodeWithIncludes']
export type ScanStatus = Schemas['ScanStatus']
export type PublicNode = Schemas['PublicNode']
export type Drive = Schemas['Drive']
export type FileRevision = Schemas['FileRevision']
export type DocumentRevision = Schemas['DocumentRevision']
export type DocumentRevisionWithContent = Schemas['DocumentRevisionWithContent']
export type DocumentBody = Schemas['DocumentBody']
export type Grant = Schemas['Grant']
export type Share = Schemas['Share']
export type MintedShare = Schemas['MintedShare']
export type Activity = Schemas['Activity']
export type Change = Schemas['Change']
export type Comment = Schemas['Comment']
export type UploadSession = Schemas['UploadSession']
export type UploadPart = Schemas['UploadPart']
export type CollaborationSession = Schemas['CollaborationSession']
export type AbilitiesExplained = Schemas['AbilitiesExplained']
export type Problem = Schemas['Problem']
export type PageMeta = Schemas['PageMeta']

export type NodeId = string
export type DriveId = string

/** A cursor page: `data` plus the opaque continuation cursor (never a page number or a total). */
export interface CursorPage<T> {
  data: T[]
  meta: PageMeta
}

/** The change feed page: its cursor is always present, `has_more` tells whether to continue. */
export type ChangePage = Schemas['ChangePage']

/** Cursor query shared by every list endpoint. */
export interface CursorQuery {
  cursor?: string | null
  perPage?: number
}

/** A document's content with the ETag the server sent in the response header. */
export type DocumentContent = Schemas['DocumentContent'] & {
  /** Opaque validator for `If-Match`; null when the response did not expose the header. */
  etag: string | null
}

export type ShareRole = 'previewer' | 'reader' | 'commenter' | 'writer'
export type ShareAudience = Schemas['Share']['audience']
export type GrantScope = Schemas['Grant']['scope']
export type UploadProtocol = 'tus' | 's3'

/**
 * The opaque validator a mutation sends in `If-Match`. It comes from the resource
 * (`node.etag`) and is passed back untouched — never parsed, never built by the client.
 */
export interface IfMatch {
  etag: string
}

export interface ListNodesQuery extends CursorQuery {
  /** The folder to list; null or omitted lists the drive root. */
  parent?: NodeId | null
}

export interface CreateGrantInput {
  subject: { type: string, id: string | number }
  role: string
  scope?: GrantScope
  expiresAt?: string
}

export interface CreateShareInput {
  role?: ShareRole
  audience?: ShareAudience
  scope?: GrantScope
  password?: string
  expiresAt?: string
  maxUses?: number
}

export interface UpdateShareInput {
  audience?: ShareAudience
  password?: string | null
  expiresAt?: string
  maxUses?: number | null
}

export interface CreateCommentInput {
  body: string
  parentId?: NodeId | null
  anchor?: Record<string, unknown> | null
}

export interface CreateDocumentInput {
  name: string
  /** The document type key; the server picks its default when omitted. */
  type?: string
  parentId?: NodeId | null
  content?: DocumentBody
}

/** The file an export yields: the exporter's bytes plus the name and type the server sent. */
export interface DocumentExport {
  blob: Blob
  filename: string
  mimeType: string
}

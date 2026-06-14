/**
 * Public types for the DocVault API surface. These mirror the JSON resources
 * returned by `axioma-studio/docvault-core`.
 */

export type DocumentId = number | string
export type WorkspaceId = number | string

export interface WorkspaceRef {
  type: string
  id: WorkspaceId
}

/** Built-in document types; host apps may register additional string types. */
export type DocumentTypeName = 'richtext' | 'table' | 'markdown' | 'template' | (string & {})

export type PermissionLevelName = 'view' | 'comment' | 'edit' | 'manage'

export interface DocumentAbilities {
  view: boolean
  comment: boolean
  edit: boolean
  manage: boolean
  delete: boolean
}

/** A TipTap JSON document. */
export interface TipTapDocument {
  type: 'doc'
  content?: unknown[]
  [key: string]: unknown
}

export interface DocVaultDocument {
  id: DocumentId
  workspace: WorkspaceRef
  folder_id: DocumentId | null
  type: DocumentTypeName
  title: string
  content: TipTapDocument | null
  meta: Record<string, unknown> | null
  created_at: string | null
  updated_at: string | null
  abilities: DocumentAbilities
}

export interface DocVaultVersion {
  id: DocumentId
  document_id: DocumentId
  snapshot_label: string | null
  created_by: DocumentId | null
  created_at: string | null
}

export interface DocVaultFolder {
  id: DocumentId
  workspace: WorkspaceRef
  parent_id: DocumentId | null
  name: string
  order: number
}

export interface DocVaultPermission {
  id: DocumentId
  document_id: DocumentId
  subject: {
    type: string
    id: WorkspaceId
  }
  permission: PermissionLevelName
  granted_by: DocumentId | null
}

export interface DocVaultComment {
  id: DocumentId
  document_id: DocumentId
  parent_id: DocumentId | null
  author: {
    type: string
    id: WorkspaceId
  }
  body: string
  anchor: Record<string, unknown> | null
  resolved_at: string | null
  created_at: string | null
}

export interface ReverbCollaborationConnection {
  driver: 'reverb'
  channel: string
  document_id: DocumentId
  token: string
}

export interface HocuspocusCollaborationConnection {
  driver: 'hocuspocus'
  url: string | null
  room: string
  token: string
}

export type CollaborationConnection =
  | ReverbCollaborationConnection
  | HocuspocusCollaborationConnection

// ─── Request payloads ────────────────────────────────────────────────────────

export interface ListDocumentsQuery {
  workspace_type: string
  workspace_id: WorkspaceId
  folder_id?: DocumentId | null
  type?: DocumentTypeName
}

export interface CreateDocumentPayload {
  workspace_type: string
  workspace_id: WorkspaceId
  type: DocumentTypeName
  title: string
  content: TipTapDocument
  folder_id?: DocumentId | null
  meta?: Record<string, unknown> | null
}

export interface UpdateDocumentPayload {
  title?: string
  content?: TipTapDocument
  meta?: Record<string, unknown> | null
}

export interface CreateVersionPayload {
  snapshot_label?: string | null
}

export interface ListFoldersQuery {
  workspace_type: string
  workspace_id: WorkspaceId
}

export interface CreateFolderPayload {
  workspace_type: string
  workspace_id: WorkspaceId
  name: string
  parent_id?: DocumentId | null
  order?: number
}

export interface UpdateFolderPayload {
  name?: string
  parent_id?: DocumentId | null
}

export interface GrantPermissionPayload {
  subject_type: string
  subject_id: WorkspaceId
  permission: PermissionLevelName
}

export interface CreateCommentPayload {
  body: string
  parent_id?: DocumentId | null
  anchor?: Record<string, unknown> | null
}

export interface DocVaultClientOptions {
  /** Base URL of the DocVault API (default: `/api/docvault`). */
  baseUrl?: string
  /** Extra request headers, or a getter (e.g. for a rotating bearer token). */
  headers?: Record<string, string> | (() => Record<string, string>)
  /** CSRF token sent as `X-CSRF-TOKEN`. */
  csrfToken?: string
  /** Custom fetch implementation (defaults to the global `fetch`). */
  fetch?: typeof fetch
}

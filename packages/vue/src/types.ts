/**
 * Public types for the Vaulter API surface. These mirror the JSON resources
 * returned by `axioma-studio/vaulter-core`.
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

export interface VaulterDocument {
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

export interface VaulterVersion {
  id: DocumentId
  document_id: DocumentId
  snapshot_label: string | null
  created_by: DocumentId | null
  created_at: string | null
}

export interface VaulterFolder {
  id: DocumentId
  workspace: WorkspaceRef
  parent_id: DocumentId | null
  name: string
  order: number
}

export interface VaulterPermission {
  id: DocumentId
  document_id: DocumentId
  subject: {
    type: string
    id: WorkspaceId
  }
  permission: PermissionLevelName
  granted_by: DocumentId | null
}

export type BulkAction = 'delete' | 'restore' | 'move' | 'force-delete'

export interface BulkDocumentsPayload {
  action: BulkAction
  ids: DocumentId[]
  folder_id?: DocumentId | null
}

export interface BulkResult {
  action: BulkAction
  processed: DocumentId[]
  skipped: DocumentId[]
}

export type ExportFormat = 'html' | 'markdown'

export interface DocumentExport {
  format: ExportFormat
  content: string
  filename: string
}

export interface VaulterComment {
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

export interface VaulterShare {
  id: DocumentId
  document_id: DocumentId
  token: string
  permission: PermissionLevelName
  expires_at: string | null
  created_at: string | null
}

/** The public, token-resolved view of a shared document. */
export interface SharedDocument {
  id: DocumentId
  type: DocumentTypeName
  title: string
  content: TipTapDocument | null
  abilities: DocumentAbilities
  share: {
    permission: PermissionLevelName
    expires_at: string | null
  }
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

/** The collaboration transport a node session runs over (M4b). */
export type CollaborationDriver = 'reverb' | 'hocuspocus'

/**
 * The realtime-collaboration session descriptor the M4b node endpoint returns
 * (`POST nodes/{node}/collab/token` → `data`). It mirrors the backend
 * `CollaborationSession::toArray()` and is the ONE shape the Vue Yjs binding consumes
 * regardless of transport:
 *
 *   - driver     — the chosen transport (`reverb` | `hocuspocus`).
 *   - room       — the room/channel name, always `channel_prefix.nodeId`.
 *   - node_id    — the document node the room maps to.
 *   - read_only  — encoded from the actor's ability (view/comment → true, edit → false);
 *                  the client mounts a read-only editor when true.
 *   - token      — the room access handle: a signed JWT for Hocuspocus, the private
 *                  channel name for Reverb.
 *   - url        — the websocket URL for the Hocuspocus hub; null for Reverb.
 *   - connection — the Laravel broadcast connection name for Reverb; null for Hocuspocus.
 */
export interface NodeCollaborationSession {
  driver: CollaborationDriver
  room: string
  node_id: NodeId
  read_only: boolean
  token: string
  url: string | null
  connection: string | null
}

// ─── Request payloads ────────────────────────────────────────────────────────

export interface ListDocumentsQuery {
  workspace_type: string
  workspace_id: WorkspaceId
  folder_id?: DocumentId | null
  type?: DocumentTypeName
  /** Full-text term (matched against title and content). */
  q?: string
}

export interface WorkspaceQuery {
  workspace_type: string
  workspace_id: WorkspaceId
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

export interface CreateSharePayload {
  permission: Extract<PermissionLevelName, 'view' | 'comment' | 'edit'>
  expires_at?: string | null
}

export interface VaulterClientOptions {
  /** Base URL of the Vaulter API (default: `/api/vaulter`). */
  baseUrl?: string
  /** Extra request headers, or a getter (e.g. for a rotating bearer token). */
  headers?: Record<string, string> | (() => Record<string, string>)
  /** CSRF token sent as `X-CSRF-TOKEN`. */
  csrfToken?: string
  /** Custom fetch implementation (defaults to the global `fetch`). */
  fetch?: typeof fetch
}

// ─── Drive (M1–M4) ───────────────────────────────────────────────────────────
//
// The unified `v_nodes` file/folder/document tree the Drive surface exposes. These
// DTOs mirror `Vaulter\Http\Resources\Drive\*`, `…\Sharing\*`, `…\Activity\*` and
// `…\Uploads\*`. They are additive — the legacy document-centric types above stay.

export type NodeId = number | string
export type StorageId = number | string
export type FileId = number | string

/** The three node kinds the Drive tree renders. */
export type NodeKind = 'folder' | 'file' | 'document' | 'shortcut' | (string & {})

/**
 * The ability map the AccessResolver fills for the request's actor on a node
 * (`NodeResource.abilities`). Host apps may advertise additional string abilities,
 * so reads are tolerant of unknown keys.
 */
export interface NodeAbilities {
  view?: boolean
  download?: boolean
  edit?: boolean
  create?: boolean
  move?: boolean
  copy?: boolean
  rename?: boolean
  trash?: boolean
  restore?: boolean
  delete?: boolean
  share?: boolean
  [ability: string]: boolean | undefined
}

/** What a document handler declares it can do (`DocumentCapabilities`). */
export interface DocumentCapabilities {
  editable: boolean
  previewable: boolean
  downloadable: boolean
  thumbnailable: boolean
  collaborative: boolean
}

/** The front-end mount descriptor a handler returns (`HandlerUi`). */
export interface HandlerUi {
  component: string
  route: string | null
  props: Record<string, unknown>
}

/**
 * The resolved-handler descriptor embedded in a leaf node payload
 * (`HandlerDescriptor`): which editor/preview/download surface the client mounts.
 */
export interface HandlerDescriptor {
  id: string
  capabilities: DocumentCapabilities
  ui: HandlerUi
}

/** A storage container (`StorageResource`). */
export interface Storage {
  id: StorageId
  panel: string | null
  owner: {
    type: string | null
    id: WorkspaceId | null
  }
  name: string
  slug: string | null
  root_node_id: NodeId | null
  disk: string | null
  storage_type: string | null
  used_bytes: number
  quota_bytes: number | null
  status: string
  created_at: string | null
  updated_at: string | null
  abilities: {
    view: boolean
    create_folder: boolean
    upload: boolean
    manage: boolean
  }
}

/** A node in the Drive tree (`NodeResource`). */
export interface Node {
  id: NodeId
  kind: NodeKind
  name: string
  mime: string | null
  extension: string | null
  size_bytes: number | null
  storage_id: StorageId
  parent_id: NodeId | null
  file_id: FileId | null
  current_version_id: NodeId | null
  is_pinned: boolean
  position: number | null
  trashed_at: string | null
  created_at: string | null
  updated_at: string | null
  /** Direct children, present only when `show` lists a folder. */
  children: Node[] | null
  /** Root → this node, present only on `show`. */
  breadcrumb: Node[] | null
  abilities: NodeAbilities
  /** The handler descriptor for a leaf (file/document); null for a folder. */
  handler: HandlerDescriptor | null
}

/** An immutable file version (`FileVersionResource`). */
export interface FileVersion {
  id: NodeId
  file_id: FileId
  version_no: number
  content_sha256: string | null
  size_bytes: number | null
  mime_type: string | null
  original_name: string | null
  status: string
  created_by: WorkspaceId | null
  created_at: string | null
  committed_at: string | null
}

export type GrantLevelName = 'reader' | 'commenter' | 'editor' | 'manager' | (string & {})
export type GrantEffectName = 'allow' | 'deny' | (string & {})
export type GrantScopeName = 'item' | 'subtree' | (string & {})

/** A per-node ACL grant (`NodeGrantResource`). */
export interface NodeGrant {
  id: NodeId
  node_id: NodeId
  subject_type: string
  subject_id: WorkspaceId
  level: GrantLevelName
  permissions: number
  effect: GrantEffectName
  scope: GrantScopeName
  expires_at: string | null
  created_at: string | null
}

/**
 * A bearer share-link (`NodeShareResource`). It NEVER carries the raw token on a
 * list/show — only the non-secret `token_prefix`. The `token` + `url` fields are
 * present EXACTLY ONCE, in the create response ({@see NodeShareCreated}).
 */
export interface NodeShare {
  id: NodeId
  node_id: NodeId
  token_prefix: string
  permissions: number
  level: GrantLevelName
  scope: GrantScopeName
  has_password: boolean
  require_login: boolean
  allow_discovery: boolean
  expires_at: string | null
  max_uses: number | null
  use_count: number
  revoked_at: string | null
  created_at: string | null
}

/** The create-share response: the share plus the raw token, returned ONCE. */
export interface NodeShareCreated extends NodeShare {
  token: string
  url: string
}

/** The public, token-resolved view of a shared node (`SharedNodeResource`). */
export interface SharedNode {
  id: NodeId
  kind: NodeKind
  name: string
  mime: string | null
  extension: string | null
  size_bytes: number | null
  parent_id: NodeId | null
  created_at: string | null
  updated_at: string | null
  children: SharedNode[] | null
}

/** An append-only activity row (`ActivityResource`). */
export interface Activity {
  id: NodeId
  verb: string
  storage_id: StorageId
  actor_type: string | null
  actor_id: WorkspaceId | null
  target_type: string | null
  target_id: NodeId | null
  context: Record<string, unknown> | null
  ip: string | null
  user_agent: string | null
  request_id: string | null
  created_at: string | null
}

export type DocumentFormatName = 'richtext' | 'table' | 'markdown' | (string & {})

/** A node-attached document version (`NodeDocumentVersionResource`). */
export interface DocumentVersion {
  id: NodeId
  node_id: NodeId
  version_no: number
  format: DocumentFormatName
  snapshot_label: string | null
  is_autosave: boolean
  keep_forever: boolean
  status: string
  content_hash: string | null
  yjs_state_size: number | null
  created_by: WorkspaceId | null
  created_at: string | null
  /** The full TipTap doc — present only on the single-version `content` fetch. */
  content: TipTapDocument | null
}

/**
 * An opaque document-content snapshot for a NON-rich-text handler (e.g. a Univer
 * spreadsheet workbook). The core stores and returns it verbatim; @vaulter/vue never
 * interprets its shape — the concrete editor (mounted in the VaulterSpreadsheet slot)
 * owns it.
 */
export type SpreadsheetWorkbook = Record<string, unknown>

/** A document version whose `content` is an opaque workbook rather than a TipTap doc. */
export interface SpreadsheetVersion extends Omit<DocumentVersion, 'content'> {
  content: SpreadsheetWorkbook | null
}

/** A resumable-upload session (`UploadSessionResource`). */
export type UploadProtocol = 'tus' | 's3' | (string & {})

export interface UploadSession {
  upload_id: string
  protocol: UploadProtocol
  status: string
  /** The authoritative server-side resume offset (tus Upload-Offset). */
  received_bytes: number
  total_size_bytes: number | null
  chunk_size: number | null
  mime_type: string | null
  node_id: NodeId | null
  target_parent_id: NodeId | null
  target_name: string | null
  assembled_blob_id: NodeId | null
  expires_at: string | null
}

/**
 * The Laravel cursor-pagination envelope `cursorPaginate()` returns: `data` plus a
 * `meta` block carrying the opaque next/prev cursors the client pages with.
 */
export interface CursorMeta {
  path?: string
  per_page?: number
  next_cursor: string | null
  prev_cursor: string | null
  [key: string]: unknown
}

export interface CursorPage<T> {
  data: T[]
  meta: CursorMeta
  links?: Record<string, unknown>
}

/**
 * The `meta` block of a Laravel length-aware (`paginate()` / `LengthAwarePaginator`)
 * page: 1-based page counters and a row total, unlike the opaque cursors of
 * {@link CursorMeta}. Used by the "shared with me" endpoint, which wraps an exact
 * cross-storage re-resolution in a bounded page it cannot cursor-paginate cleanly.
 */
export interface PageMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
  path?: string
  [key: string]: unknown
}

/** A Laravel length-aware page envelope: `data` plus a page/total `meta` block. */
export interface Page<T> {
  data: T[]
  meta: PageMeta
  links?: Record<string, unknown>
}

// ─── Drive request payloads ──────────────────────────────────────────────────

/** Query for the cross-storage "shared with me" listing (length-aware paging). */
export interface SharedNodesQuery {
  page?: number
  perPage?: number
}

export interface ListNodesQuery {
  parentId?: NodeId | null
  cursor?: string | null
  perPage?: number
}

export interface CursorQuery {
  cursor?: string | null
  perPage?: number
}

export interface CreateDriveFolderPayload {
  name: string
  parentId?: NodeId | null
}

export interface DirectUploadOptions {
  parentId?: NodeId | null
  /** Commit a NEW VERSION onto this existing file node instead of a new file. */
  nodeId?: NodeId | null
  /** Stored-name override. */
  name?: string
}

export interface CreateUploadSessionPayload {
  protocol?: UploadProtocol
  parentId?: NodeId | null
  /** New-version target (an existing file node). */
  nodeId?: NodeId | null
  name?: string
  totalSizeBytes?: number
  expectedSha256?: string
  mimeType?: string
  metadata?: Record<string, string>
}

export interface GrantNodeAccessPayload {
  subjectType: string
  subjectId: WorkspaceId
  level: GrantLevelName
  effect?: GrantEffectName
  scope?: GrantScopeName
  permissions?: number
  expiresAt?: string | null
}

export interface RevokeNodeAccessPayload {
  subjectType: string
  subjectId: WorkspaceId
}

export interface CreateNodeSharePayload {
  /** The explicit CRUDS bitmask (0..31). */
  permissions?: number
  /** Or a level whose bitmask is conferred when `permissions` is omitted. */
  level?: GrantLevelName
  scope?: GrantScopeName
  password?: string | null
  requireLogin?: boolean
  allowDiscovery?: boolean
  expiresAt?: string | null
  maxUses?: number | null
}

export interface CreateDocumentNodePayload {
  name: string
  content?: TipTapDocument
  parentId?: NodeId | null
  format?: DocumentFormatName
}

export interface UpdateDocumentContentOptions {
  /** The optional base64-encoded Yjs CRDT update (the realtime source of truth). */
  yjsState?: string
}

/** The export response a document node yields (`DocumentExport` — adds `mime_type`). */
export interface NodeDocumentExport {
  format: string
  content: string
  mime_type: string
  filename: string
}

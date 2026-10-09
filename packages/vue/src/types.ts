/**
 * Public types for the Vaulter API surface. These mirror the JSON resources
 * returned by `axioma-studio/vaulter-core`.
 */

export type WorkspaceId = number | string

/** A TipTap JSON document. */
export interface TipTapDocument {
  type: 'doc'
  content?: unknown[]
  [key: string]: unknown
}

/**
 * A threaded, anchorable comment on a document NODE (`/nodes/{node}/comments`) — the
 * canonical comment surface after ADR-0014.
 */
export interface VaulterComment {
  id: NodeId
  node_id: NodeId
  parent_id: NodeId | null
  author: {
    type: string
    id: WorkspaceId
  }
  body: string
  anchor: Record<string, unknown> | null
  resolved_at: string | null
  created_at: string | null
}

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

export interface CreateCommentPayload {
  body: string
  parent_id?: NodeId | null
  anchor?: Record<string, unknown> | null
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
// `…\Uploads\*` — the single canonical document path after ADR-0014.

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

/**
 * What a file handler declares it can do (`HandlerCapabilities`). This is an
 * ADDITIVE, open axis set (VLT-CANON P0.5): the server may ship a new boolean flag
 * (e.g. `rich_text`) at any time, and existing client code that destructures a
 * known subset keeps working unchanged — the index signature is the contract that
 * an unrecognised key is ignored, never an error.
 */
export interface HandlerCapabilities {
  editable: boolean
  previewable: boolean
  downloadable: boolean
  thumbnailable: boolean
  collaborative: boolean
  [capability: string]: boolean | undefined
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
  capabilities: HandlerCapabilities
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

/** A role key of the 1.0 access model (a role is a closed set of abilities, versioned). */
export type GrantRoleName = 'previewer' | 'reader' | 'commenter' | 'writer' | 'organizer' | 'owner' | (string & {})
export type GrantLevelName = GrantRoleName

/** The roles a share link can carry (organizer and owner never; writer needs a signed-in audience). */
export type ShareRoleName = 'previewer' | 'reader' | 'commenter' | 'writer'

/** Who may open a share link: the token is always required. */
export type ShareAudienceName = 'anyone' | 'authenticated' | 'tenant'
export type GrantScopeName = 'item' | 'subtree' | (string & {})

/** A per-node ACL grant (`NodeGrantResource`). */
export interface NodeGrant {
  id: NodeId
  node_id: NodeId
  subject_type: string
  subject_id: WorkspaceId
  role: GrantRoleName
  role_version: number
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
  role: ShareRoleName
  role_version: number
  audience: ShareAudienceName
  /** Raised each time the token is rotated. */
  token_generation: number
  scope: GrantScopeName
  has_password: boolean
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

/** A node-attached document version (`DocumentRevisionResource`). */
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
 * {@link CursorMeta}. Retained for consumers of length-aware page envelopes.
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

/** Query for the cross-storage "shared with me" cursor listing. */
export interface SharedNodesQuery extends CursorQuery {}

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
  role: GrantRoleName
  scope?: GrantScopeName
  expiresAt?: string | null
}

export interface RevokeNodeAccessPayload {
  subjectType: string
  subjectId: WorkspaceId
}

export interface CreateNodeSharePayload {
  /** The role the link grants; reader when omitted. */
  role?: ShareRoleName
  /** Who may open it; anyone when omitted. */
  audience?: ShareAudienceName
  scope?: GrantScopeName
  password?: string | null
  expiresAt?: string | null
  maxUses?: number | null
}

/** Changes to a live link. A field left out stays; `null` removes the password or the use budget. The token never changes. */
export interface UpdateNodeSharePayload {
  audience?: ShareAudienceName
  password?: string | null
  expiresAt?: string
  maxUses?: number | null
}

export interface CreateDocumentNodePayload {
  name: string
  content?: TipTapDocument
  parentId?: NodeId | null
  format?: DocumentFormatName
}

/**
 * Create a NON-rich-text (spreadsheet) document node. `mime` selects the format the
 * backend resolves a handler for (defaults to the xlsx sheet mime → the Univer
 * handler); `workbook` is the opaque initial snapshot the core stores verbatim. Needs
 * the `spreadsheets` feature enabled server-side, else the create is rejected.
 */
export interface CreateWorkbookNodePayload {
  name: string
  workbook?: SpreadsheetWorkbook
  parentId?: NodeId | null
  mime?: string
}

export interface UpdateDocumentContentOptions {
  /** The optional base64-encoded Yjs CRDT update (the realtime source of truth). */
  yjsState?: string
}

/** The file an export yields: the exporter's bytes plus the name and type the server sent. */
export interface NodeDocumentExport {
  blob: Blob
  filename: string
  mimeType: string
}

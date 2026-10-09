import type {
  Activity,
  CreateCommentPayload,
  CreateDocumentNodePayload,
  CreateWorkbookNodePayload,
  CreateDriveFolderPayload,
  CreateNodeSharePayload,
  UpdateNodeSharePayload,
  CreateUploadSessionPayload,
  CursorPage,
  CursorQuery,
  DirectUploadOptions,
  DocumentVersion,
  GrantNodeAccessPayload,
  HandlerDescriptor,
  ListNodesQuery,
  Node,
  NodeCollaborationSession,
  NodeDocumentExport,
  NodeGrant,
  NodeShare,
  NodeShareCreated,
  RevokeNodeAccessPayload,
  SharedNode,
  SharedNodesQuery,
  Storage,
  UpdateDocumentContentOptions,
  UploadSession,
  VaulterClientOptions,
  VaulterComment,
  NodeId,
  SpreadsheetVersion,
  SpreadsheetWorkbook,
  StorageId,
  TipTapDocument,
} from './types'

/** The default mime a spreadsheet (workbook) node is created with — routes to the
 *  server-side Univer handler when the `spreadsheets` feature is enabled. */
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

/** Error thrown for non-2xx Vaulter API responses. */
export class VaulterApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors: Record<string, string[]> = {},
    public readonly response?: Response,
  ) {
    super(message)
    this.name = 'VaulterApiError'
  }

  static async fromResponse(response: Response): Promise<VaulterApiError> {
    let message = `Vaulter API error (${response.status})`
    let errors: Record<string, string[]> = {}

    try {
      const body = (await response.json()) as { message?: string, errors?: Record<string, string[]> }
      if (typeof body.message === 'string') {
        message = body.message
      }
      if (body.errors) {
        errors = body.errors
      }
    } catch {
      // Non-JSON error body — keep the default message.
    }

    return new VaulterApiError(response.status, message, errors, response)
  }
}

interface RequestInitLike {
  body?: unknown
  query?: object
  /** Extra per-request headers (merged over the client defaults). */
  headers?: Record<string, string>
}

/** A low-level request that hands back the raw `Response` (header/stream reads). */
interface RawRequestInit {
  query?: object
  headers?: Record<string, string>
  /** A raw body sent as-is (no JSON serialisation, no Content-Type default). */
  body?: BodyInit | null
}

function toQueryString(query?: object): string {
  if (!query) {
    return ''
  }

  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null) {
      params.append(key, String(value))
    }
  }

  const serialized = params.toString()

  return serialized ? `?${serialized}` : ''
}

interface Envelope<T> {
  data: T
}

export interface VaulterClient {
  /**
   * Threaded comments on a document NODE (`/nodes/{node}/comments`) — the canonical
   * comment surface after the legacy document_id-bound path was excised (ADR-0014).
   */
  comments: {
    list(nodeId: NodeId): Promise<VaulterComment[]>
    create(nodeId: NodeId, payload: CreateCommentPayload): Promise<VaulterComment>
    resolve(nodeId: NodeId, commentId: NodeId): Promise<VaulterComment>
    remove(nodeId: NodeId, commentId: NodeId): Promise<void>
  }

  /**
   * The M4b node-collaboration handshake: mint a realtime session for a document
   * NODE (the Drive surface). Returns the transport-agnostic
   * {@link NodeCollaborationSession} the Yjs binding consumes.
   */
  nodeCollaboration: {
    token(nodeId: NodeId): Promise<NodeCollaborationSession>
  }

  // ─── Drive (M1–M4) ───────────────────────────────────────────────────────

  storages: {
    /** A single storage container with its root node + usage accounting. */
    get(storageId: StorageId): Promise<Storage>
  }

  nodes: {
    /** List the direct children of a folder (or the storage root). Cursor-paged. */
    list(storageId: StorageId, query?: ListNodesQuery): Promise<CursorPage<Node>>
    /** A single node plus its breadcrumb (a folder also carries its children). */
    show(nodeId: NodeId): Promise<Node>
    createFolder(storageId: StorageId, payload: CreateDriveFolderPayload): Promise<Node>
    rename(nodeId: NodeId, name: string): Promise<Node>
    move(nodeId: NodeId, intoId: NodeId | null): Promise<Node>
    copy(nodeId: NodeId, intoId: NodeId | null): Promise<Node>
    trash(nodeId: NodeId): Promise<Node>
    restore(nodeId: NodeId): Promise<Node>
    forceDelete(nodeId: NodeId): Promise<void>
    search(storageId: StorageId, term: string, query?: CursorQuery): Promise<CursorPage<Node>>
    trashed(storageId: StorageId, query?: CursorQuery): Promise<CursorPage<Node>>
    /**
     * The cross-storage "shared with me" listing (`GET /shared`): the nodes the
     * actor holds a live allow-grant on, re-resolved EXACTLY. Top-level (the subject
     * index is not storage-bound), with an opaque continuation cursor and no total;
     * an unauthenticated request sees an empty page.
     */
    shared(query?: SharedNodesQuery): Promise<CursorPage<Node>>
    /** The (relative) URL the browser hits to stream the node's current head. */
    downloadUrl(nodeId: NodeId): string
    /** Fetch the node's current-head bytes as a Blob. */
    download(nodeId: NodeId): Promise<Blob>
    versionDownloadUrl(nodeId: NodeId, versionId: NodeId): string
  }

  uploads: {
    /** A simple direct (single-request) upload. */
    upload(storageId: StorageId, file: Blob, options?: DirectUploadOptions): Promise<Node>
    /** Open a resumable session (tus by default; pass `protocol: 's3'` for S3). */
    createSession(storageId: StorageId, payload?: CreateUploadSessionPayload): Promise<UploadSession>
    /** Poll a session's status. */
    status(uploadId: string): Promise<UploadSession>
    /** The tus HEAD: read the authoritative server-side Upload-Offset. */
    head(uploadId: string): Promise<number>
    /** The tus PATCH: append one chunk at `offset`. Returns the new offset, or the
     *  created node once all bytes are in. */
    patch(uploadId: string, chunk: BodyInit, offset: number): Promise<TusPatchResult>
    /** The tus DELETE: abort the session. */
    abort(uploadId: string): Promise<void>
    /** S3-multipart: record one numbered part. */
    putPart(uploadId: string, partNo: number, byteOffset: number, chunk: BodyInit): Promise<S3PartResult>
    /** S3-multipart: complete → assemble → commit. Returns the created node. */
    complete(uploadId: string): Promise<Node>
    /** A hand-rolled resumable uploader that drives a session chunk by chunk. */
    resumable(storageId: StorageId, file: Blob, options?: ResumableUploadOptions): ResumableUpload
  }

  grants: {
    list(nodeId: NodeId, query?: CursorQuery): Promise<CursorPage<NodeGrant>>
    grant(nodeId: NodeId, payload: GrantNodeAccessPayload): Promise<NodeGrant>
    /** Revoke a subject's grant on the node. */
    revoke(nodeId: NodeId, payload: RevokeNodeAccessPayload): Promise<void>
  }

  nodeShares: {
    list(nodeId: NodeId, query?: CursorQuery): Promise<CursorPage<NodeShare>>
    /** Mint a share-link — the response carries the raw token EXACTLY ONCE. */
    create(nodeId: NodeId, payload?: CreateNodeSharePayload): Promise<NodeShareCreated>
    /** Change expiry, budget, password or audience; the token stays. */
    update(nodeId: NodeId, shareId: NodeId, payload: UpdateNodeSharePayload): Promise<NodeShare>
    /** Replace the token — the old one stops working at once; the response carries the new raw token ONCE. */
    rotate(nodeId: NodeId, shareId: NodeId): Promise<NodeShareCreated>
    revoke(nodeId: NodeId, shareId: NodeId): Promise<void>
    /** Resolve a public token-shared node (the separate sharing channel). */
    publicResolve(token: string): Promise<SharedNode>
    /** List the in-scope children of a subtree-shared folder. */
    publicNodes(token: string): Promise<SharedNode[]>
    /** The (relative) URL the browser hits to download a shared file. */
    publicDownloadUrl(token: string): string
  }

  activity: {
    forStorage(storageId: StorageId, query?: CursorQuery): Promise<CursorPage<Activity>>
    forNode(nodeId: NodeId, query?: CursorQuery): Promise<CursorPage<Activity>>
  }

  documentNodes: {
    /** Create a kind=document node under a folder (or the storage root). */
    createNode(storageId: StorageId, payload: CreateDocumentNodePayload): Promise<Node>
    /** Create a NON-rich-text (spreadsheet) document node — the same create route
     *  typed for an opaque workbook, defaulting to the xlsx mime so the backend
     *  resolves the Univer handler. Requires the `spreadsheets` feature server-side. */
    createWorkbookNode(storageId: StorageId, payload: CreateWorkbookNodePayload): Promise<Node>
    /** The document node's current-head content (a single version with content). */
    content(nodeId: NodeId): Promise<DocumentVersion>
    /** Autosave the content; pass `{ autosave }` only for symmetry — the route is
     *  the same PATCH either way. */
    updateContent(
      nodeId: NodeId,
      content: TipTapDocument,
      options?: UpdateDocumentContentOptions & { autosave?: boolean },
    ): Promise<DocumentVersion>
    versions(nodeId: NodeId, query?: CursorQuery): Promise<CursorPage<DocumentVersion>>
    createNamedVersion(nodeId: NodeId, label: string, keepForever?: boolean): Promise<DocumentVersion>
    restoreVersion(nodeId: NodeId, versionId: NodeId): Promise<DocumentVersion>
    export(nodeId: NodeId, format?: string): Promise<NodeDocumentExport>
    handler(nodeId: NodeId): Promise<HandlerDescriptor>
    /** The head content of a NON-rich-text node, typed as an opaque workbook snapshot
     *  (same route as `content`, different content shape). */
    workbook(nodeId: NodeId): Promise<SpreadsheetVersion>
    /** Autosave a non-rich-text node's opaque snapshot (same PATCH route as
     *  `updateContent`; the backend stores it verbatim for a richText=false handler). */
    updateWorkbook(
      nodeId: NodeId,
      workbook: SpreadsheetWorkbook,
      options?: UpdateDocumentContentOptions & { autosave?: boolean },
    ): Promise<SpreadsheetVersion>
  }
}

/** The result of a tus PATCH: either the advanced offset, or the created node. */
export type TusPatchResult =
  | { complete: false, offset: number }
  | { complete: true, offset: number, node: Node }

export interface S3PartResult {
  part_no: number
  size: number
  etag: string
}

export interface ResumableUploadOptions extends CreateUploadSessionPayload {
  /** Chunk size in bytes for the tus PATCH loop (default 5 MiB). */
  chunkSize?: number
  /** Progress callback: (bytesSent, totalBytes). */
  onProgress?: (sent: number, total: number) => void
}

/** A driveable resumable upload (hand-rolled, no external tus dependency). */
export interface ResumableUpload {
  /** Run the upload to completion, resuming from the server offset. Resolves to the
   *  created node. */
  start(): Promise<Node>
  /** Request abort — the in-flight loop stops and the session is DELETE-aborted. */
  abort(): Promise<void>
}

/**
 * The file name of a `Content-Disposition` header: the RFC 6266 `filename*` when present,
 * else the plain `filename`. Null when the header names none.
 */
export function filenameFromDisposition(header: string | null): string | null {
  if (!header) return null
  const extended = /filename\*\s*=\s*[^']*'[^']*'([^;]+)/i.exec(header)
  if (extended) {
    try {
      return decodeURIComponent(extended[1].trim())
    } catch {
      // fall through to the plain filename
    }
  }
  const plain = /filename\s*=\s*(?:"((?:[^"\\]|\\.)*)"|([^;]+))/i.exec(header)
  return plain ? (plain[1] ?? plain[2]).trim().replace(/\\(.)/g, '$1') : null
}

export function createVaulterClient(options: VaulterClientOptions = {}): VaulterClient {
  const baseUrl = (options.baseUrl ?? '/api/vaulter').replace(/\/+$/, '')
  const fetchImpl = options.fetch ?? globalThis.fetch

  function resolveHeaders(): Record<string, string> {
    const provided = typeof options.headers === 'function' ? options.headers() : options.headers ?? {}
    const headers: Record<string, string> = { ...provided }

    if (options.csrfToken) {
      headers['X-CSRF-TOKEN'] = options.csrfToken
    }

    return headers
  }

  async function request<T>(method: string, path: string, init: RequestInitLike = {}): Promise<T> {
    const hasBody = init.body !== undefined
    const response = await fetchImpl(`${baseUrl}${path}${toQueryString(init.query)}`, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(hasBody ? { 'Content-Type': 'application/json' } : {}),
        ...resolveHeaders(),
        ...init.headers,
      },
      body: hasBody ? JSON.stringify(init.body) : undefined,
    })

    if (!response.ok) {
      throw await VaulterApiError.fromResponse(response)
    }

    if (response.status === 204) {
      return undefined as T
    }

    return (await response.json()) as T
  }

  /**
   * A low-level request that returns the raw `Response` (for the tus header reads
   * and the binary download streams). The raw body is sent as-is — no JSON
   * serialisation, no Content-Type default. The CSRF header and client defaults
   * still apply; `VaulterApiError` is still thrown on a non-2xx.
   */
  async function rawRequest(method: string, path: string, init: RawRequestInit = {}): Promise<Response> {
    const response = await fetchImpl(`${baseUrl}${path}${toQueryString(init.query)}`, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...resolveHeaders(),
        ...init.headers,
      },
      body: init.body ?? undefined,
    })

    if (!response.ok) {
      throw await VaulterApiError.fromResponse(response)
    }

    return response
  }

  async function unwrap<T>(promise: Promise<Envelope<T>>): Promise<T> {
    return (await promise).data
  }

  /** The absolute (or base-prefixed) URL for a path — for `<a href>`/`<img src>`. */
  function urlFor(path: string): string {
    return `${baseUrl}${path}`
  }

  /** A cursor-pagination query: `?cursor=…&per_page=…`. */
  function cursorQuery(query?: CursorQuery): Record<string, string | number> {
    const params: Record<string, string | number> = {}
    if (query?.cursor != null) {
      params.cursor = query.cursor
    }
    if (query?.perPage != null) {
      params.per_page = query.perPage
    }
    return params
  }

  const client: VaulterClient = {
    comments: {
      list: (nodeId) => unwrap(request<Envelope<VaulterComment[]>>('GET', `/nodes/${nodeId}/comments`)),
      create: (nodeId, payload) =>
        unwrap(request<Envelope<VaulterComment>>('POST', `/nodes/${nodeId}/comments`, { body: payload })),
      resolve: (nodeId, commentId) =>
        unwrap(request<Envelope<VaulterComment>>('POST', `/nodes/${nodeId}/comments/${commentId}/resolve`)),
      remove: (nodeId, commentId) => request<void>('DELETE', `/nodes/${nodeId}/comments/${commentId}`),
    },
    nodeCollaboration: {
      token: (nodeId) =>
        unwrap(request<Envelope<NodeCollaborationSession>>('POST', `/nodes/${nodeId}/collab/token`, { body: {} })),
    },

    // ─── Drive (M1–M4) ─────────────────────────────────────────────────────

    storages: {
      get: (storageId) => unwrap(request<Envelope<Storage>>('GET', `/storages/${storageId}`)),
    },

    nodes: {
      list: (storageId, query) =>
        request<CursorPage<Node>>('GET', `/storages/${storageId}/nodes`, {
          query: {
            ...(query?.parentId != null ? { parent_id: query.parentId } : {}),
            ...cursorQuery(query),
          },
        }),
      show: (nodeId) => unwrap(request<Envelope<Node>>('GET', `/nodes/${nodeId}`)),
      createFolder: (storageId, payload) =>
        unwrap(request<Envelope<Node>>('POST', `/storages/${storageId}/folders`, {
          body: { name: payload.name, parent_id: payload.parentId ?? null },
        })),
      rename: (nodeId, name) =>
        unwrap(request<Envelope<Node>>('PATCH', `/nodes/${nodeId}`, { body: { name } })),
      move: (nodeId, intoId) =>
        unwrap(request<Envelope<Node>>('POST', `/nodes/${nodeId}/move`, { body: { parent_id: intoId } })),
      copy: (nodeId, intoId) =>
        unwrap(request<Envelope<Node>>('POST', `/nodes/${nodeId}/copy`, { body: { parent_id: intoId } })),
      trash: (nodeId) => unwrap(request<Envelope<Node>>('DELETE', `/nodes/${nodeId}`)),
      restore: (nodeId) => unwrap(request<Envelope<Node>>('POST', `/nodes/${nodeId}/restore`)),
      forceDelete: (nodeId) => request<void>('DELETE', `/nodes/${nodeId}/force`),
      search: (storageId, term, query) =>
        request<CursorPage<Node>>('GET', `/storages/${storageId}/search`, {
          query: { q: term, ...cursorQuery(query) },
        }),
      trashed: (storageId, query) =>
        request<CursorPage<Node>>('GET', `/storages/${storageId}/trash`, { query: cursorQuery(query) }),
      shared: (query) =>
        request<CursorPage<Node>>('GET', '/shared', { query: cursorQuery(query) }),
      downloadUrl: (nodeId) => urlFor(`/nodes/${nodeId}/download`),
      download: async (nodeId) => (await rawRequest('GET', `/nodes/${nodeId}/download`)).blob(),
      versionDownloadUrl: (nodeId, versionId) => urlFor(`/nodes/${nodeId}/versions/${versionId}/download`),
    },

    uploads: {
      upload: (storageId, file, options) => {
        const form = new FormData()
        form.append('file', file)
        if (options?.parentId != null) {
          form.append('parent_id', String(options.parentId))
        }
        if (options?.nodeId != null) {
          form.append('node_id', String(options.nodeId))
        }
        if (options?.name != null) {
          form.append('name', options.name)
        }
        // FormData drives its own multipart Content-Type/boundary — never set it.
        return rawRequest('POST', `/storages/${storageId}/uploads`, { body: form })
          .then((response) => response.json())
          .then((body: Envelope<Node>) => body.data)
      },
      createSession: (storageId, payload) =>
        unwrap(request<Envelope<UploadSession>>('POST', `/storages/${storageId}/resumable-uploads`, {
          body: sessionBody(payload),
        })),
      status: (uploadId) =>
        unwrap(request<Envelope<UploadSession>>('GET', `/resumable-uploads/${uploadId}`)),
      head: async (uploadId) => {
        const response = await rawRequest('HEAD', `/resumable-uploads/${uploadId}`, {
          headers: { 'Tus-Resumable': '1.0.0' },
        })
        return Number(response.headers.get('Upload-Offset') ?? '0')
      },
      patch: async (uploadId, chunk, offset) => {
        const response = await rawRequest('PATCH', `/resumable-uploads/${uploadId}`, {
          headers: {
            'Tus-Resumable': '1.0.0',
            'Content-Type': 'application/offset+octet-stream',
            'Upload-Offset': String(offset),
          },
          body: chunk,
        })
        const newOffset = Number(response.headers.get('Upload-Offset') ?? String(offset))
        // 204 = more chunks expected; 201 = all bytes in, the node is created.
        if (response.status === 201) {
          const body = (await response.json()) as Envelope<Node>
          return { complete: true, offset: newOffset, node: body.data }
        }
        return { complete: false, offset: newOffset }
      },
      abort: async (uploadId) => {
        await rawRequest('DELETE', `/resumable-uploads/${uploadId}`, {
          headers: { 'Tus-Resumable': '1.0.0' },
        })
      },
      putPart: (uploadId, partNo, byteOffset, chunk) =>
        rawRequest('POST', `/resumable-uploads/${uploadId}/parts`, {
          query: { part_no: partNo, byte_offset: byteOffset },
          body: chunk,
        }).then((response) => response.json() as Promise<S3PartResult>),
      complete: (uploadId) =>
        unwrap(request<Envelope<Node>>('POST', `/resumable-uploads/${uploadId}/complete`, { body: {} })),
      resumable: (storageId, file, options) => createResumableUpload(driveUploads(), storageId, file, options),
    },

    grants: {
      list: (nodeId, query) =>
        request<CursorPage<NodeGrant>>('GET', `/nodes/${nodeId}/grants`, { query: cursorQuery(query) }),
      grant: (nodeId, payload) =>
        unwrap(request<Envelope<NodeGrant>>('POST', `/nodes/${nodeId}/grants`, {
          body: {
            subject_type: payload.subjectType,
            subject_id: payload.subjectId,
            role: payload.role,
            scope: payload.scope,
            expires_at: payload.expiresAt,
          },
        })),
      revoke: (nodeId, payload) =>
        request<void>('DELETE', `/nodes/${nodeId}/grants`, {
          body: { subject_type: payload.subjectType, subject_id: payload.subjectId },
        }),
    },

    nodeShares: {
      list: (nodeId, query) =>
        request<CursorPage<NodeShare>>('GET', `/nodes/${nodeId}/shares`, { query: cursorQuery(query) }),
      create: (nodeId, payload) =>
        unwrap(request<Envelope<NodeShareCreated>>('POST', `/nodes/${nodeId}/shares`, {
          body: {
            role: payload?.role,
            audience: payload?.audience,
            scope: payload?.scope,
            password: payload?.password,
            expires_at: payload?.expiresAt,
            max_uses: payload?.maxUses,
          },
        })),
      update: (nodeId, shareId, payload) =>
        unwrap(request<Envelope<NodeShare>>('PATCH', `/nodes/${nodeId}/shares/${shareId}`, {
          // `undefined` keys are dropped by JSON, so only the fields given reach the server.
          body: {
            audience: payload.audience,
            password: payload.password,
            expires_at: payload.expiresAt,
            max_uses: payload.maxUses,
          },
        })),
      rotate: (nodeId, shareId) =>
        unwrap(request<Envelope<NodeShareCreated>>('POST', `/nodes/${nodeId}/shares/${shareId}/rotate`)),
      revoke: (nodeId, shareId) => request<void>('DELETE', `/nodes/${nodeId}/shares/${shareId}`),
      publicResolve: (token) => unwrap(request<Envelope<SharedNode>>('GET', `/share/${token}`)),
      publicNodes: (token) => unwrap(request<Envelope<SharedNode[]>>('GET', `/share/${token}/nodes`)),
      publicDownloadUrl: (token) => urlFor(`/share/${token}/download`),
    },

    activity: {
      forStorage: (storageId, query) =>
        request<CursorPage<Activity>>('GET', `/storages/${storageId}/activity`, { query: cursorQuery(query) }),
      forNode: (nodeId, query) =>
        request<CursorPage<Activity>>('GET', `/nodes/${nodeId}/activity`, { query: cursorQuery(query) }),
    },

    documentNodes: {
      createNode: (storageId, payload) =>
        unwrap(request<Envelope<Node>>('POST', `/storages/${storageId}/documents`, {
          body: {
            name: payload.name,
            parent_id: payload.parentId ?? null,
            format: payload.format,
            content: payload.content,
          },
        })),
      createWorkbookNode: (storageId, payload) =>
        unwrap(request<Envelope<Node>>('POST', `/storages/${storageId}/documents`, {
          body: {
            name: payload.name,
            parent_id: payload.parentId ?? null,
            mime: payload.mime ?? XLSX_MIME,
            content: payload.workbook,
          },
        })),
      content: (nodeId) =>
        unwrap(request<Envelope<DocumentVersion>>('GET', `/nodes/${nodeId}/content`)),
      updateContent: (nodeId, content, options) =>
        unwrap(request<Envelope<DocumentVersion>>('PATCH', `/nodes/${nodeId}/content`, {
          body: { content, yjs_state: options?.yjsState },
        })),
      versions: (nodeId, query) =>
        request<CursorPage<DocumentVersion>>('GET', `/nodes/${nodeId}/document-versions`, {
          query: cursorQuery(query),
        }),
      createNamedVersion: (nodeId, label, keepForever) =>
        unwrap(request<Envelope<DocumentVersion>>('POST', `/nodes/${nodeId}/document-versions`, {
          body: { label, keep_forever: keepForever },
        })),
      restoreVersion: (nodeId, versionId) =>
        unwrap(request<Envelope<DocumentVersion>>('POST', `/nodes/${nodeId}/document-versions/${versionId}/restore`)),
      export: (nodeId, format) =>
        rawRequest('GET', `/nodes/${nodeId}/export`, {
          query: format ? { format } : undefined,
        }).then(async (response) => ({
          blob: await response.blob(),
          filename: filenameFromDisposition(response.headers.get('Content-Disposition')) ?? `export-${nodeId}`,
          mimeType: response.headers.get('Content-Type') ?? 'application/octet-stream',
        })),
      handler: (nodeId) =>
        unwrap(request<Envelope<HandlerDescriptor>>('GET', `/nodes/${nodeId}/handler`)),
      workbook: (nodeId) =>
        unwrap(request<Envelope<SpreadsheetVersion>>('GET', `/nodes/${nodeId}/content`)),
      updateWorkbook: (nodeId, workbook, options) =>
        unwrap(request<Envelope<SpreadsheetVersion>>('PATCH', `/nodes/${nodeId}/content`, {
          body: { content: workbook, yjs_state: options?.yjsState },
        })),
    },
  }

  /**
   * Serialise the camelCase session payload to the snake_case the create endpoint
   * validates (omitting undefined keys keeps the request lean).
   */
  function sessionBody(payload?: CreateUploadSessionPayload): Record<string, unknown> {
    return {
      protocol: payload?.protocol,
      parent_id: payload?.parentId,
      node_id: payload?.nodeId,
      name: payload?.name,
      total_size_bytes: payload?.totalSizeBytes,
      expected_sha256: payload?.expectedSha256,
      mime_type: payload?.mimeType,
      metadata: payload?.metadata,
    }
  }

  // The uploads namespace references itself from `resumable()`; capture it lazily so
  // the resumable helper drives the same session/patch methods the client exposes.
  function driveUploads(): VaulterClient['uploads'] {
    return client.uploads
  }

  return client
}

/**
 * A hand-rolled resumable uploader (no external tus dependency): open a session,
 * then loop reading the server's authoritative Upload-Offset and PATCHing the next
 * slice until the server returns the created node. Resumable by construction — it
 * always resumes from the SERVER offset, never its own guess (H.3).
 */
function createResumableUpload(
  uploads: VaulterClient['uploads'],
  storageId: StorageId,
  file: Blob,
  options?: ResumableUploadOptions,
): ResumableUpload {
  const chunkSize = options?.chunkSize ?? 5 * 1024 * 1024
  const total = file.size
  let uploadId: string | null = null
  let aborted = false

  async function start(): Promise<Node> {
    const session = await uploads.createSession(storageId, {
      protocol: 'tus',
      totalSizeBytes: total,
      ...options,
    })
    uploadId = session.upload_id

    // Resume from whatever the server already holds.
    let offset = await uploads.head(uploadId)
    options?.onProgress?.(offset, total)

    while (offset < total) {
      if (aborted) {
        throw new Error('[Vaulter] Resumable upload aborted.')
      }

      const slice = file.slice(offset, Math.min(offset + chunkSize, total))
      const result = await uploads.patch(uploadId, slice, offset)
      offset = result.offset
      options?.onProgress?.(Math.min(offset, total), total)

      if (result.complete) {
        return result.node
      }
    }

    // A zero-byte (or boundary-aligned) upload finishes without the 201 in-loop —
    // the server completes on the final chunk; if not, the status carries the node.
    throw new Error('[Vaulter] Resumable upload ended without a created node.')
  }

  async function abort(): Promise<void> {
    aborted = true
    if (uploadId !== null) {
      await uploads.abort(uploadId)
    }
  }

  return { start, abort }
}

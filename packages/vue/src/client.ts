import type {
  BulkDocumentsPayload,
  BulkResult,
  CollaborationConnection,
  CreateCommentPayload,
  CreateDocumentPayload,
  CreateFolderPayload,
  CreateSharePayload,
  CreateVersionPayload,
  VaulterClientOptions,
  VaulterComment,
  VaulterDocument,
  DocumentExport,
  ExportFormat,
  VaulterFolder,
  VaulterPermission,
  VaulterShare,
  VaulterVersion,
  DocumentId,
  GrantPermissionPayload,
  ListDocumentsQuery,
  ListFoldersQuery,
  SharedDocument,
  UpdateDocumentPayload,
  UpdateFolderPayload,
  WorkspaceQuery,
} from './types'

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
  documents: {
    list(query: ListDocumentsQuery): Promise<VaulterDocument[]>
    trashed(query: WorkspaceQuery): Promise<VaulterDocument[]>
    get(id: DocumentId): Promise<VaulterDocument>
    create(payload: CreateDocumentPayload): Promise<VaulterDocument>
    update(id: DocumentId, payload: UpdateDocumentPayload): Promise<VaulterDocument>
    remove(id: DocumentId): Promise<void>
    forceDelete(id: DocumentId): Promise<void>
    restore(id: DocumentId): Promise<VaulterDocument>
    move(id: DocumentId, folderId: DocumentId | null): Promise<VaulterDocument>
    duplicate(id: DocumentId): Promise<VaulterDocument>
    bulk(payload: BulkDocumentsPayload): Promise<BulkResult>
    export(id: DocumentId, format?: ExportFormat): Promise<DocumentExport>
  }
  versions: {
    list(documentId: DocumentId): Promise<VaulterVersion[]>
    create(documentId: DocumentId, payload?: CreateVersionPayload): Promise<VaulterVersion>
    restore(documentId: DocumentId, versionId: DocumentId): Promise<VaulterDocument>
  }
  folders: {
    list(query: ListFoldersQuery): Promise<VaulterFolder[]>
    create(payload: CreateFolderPayload): Promise<VaulterFolder>
    update(id: DocumentId, payload: UpdateFolderPayload): Promise<VaulterFolder>
    remove(id: DocumentId): Promise<void>
  }
  permissions: {
    list(documentId: DocumentId): Promise<VaulterPermission[]>
    grant(documentId: DocumentId, payload: GrantPermissionPayload): Promise<VaulterPermission>
    revoke(documentId: DocumentId, permissionId: DocumentId): Promise<void>
  }
  comments: {
    list(documentId: DocumentId): Promise<VaulterComment[]>
    create(documentId: DocumentId, payload: CreateCommentPayload): Promise<VaulterComment>
    resolve(documentId: DocumentId, commentId: DocumentId): Promise<VaulterComment>
    remove(documentId: DocumentId, commentId: DocumentId): Promise<void>
  }
  shares: {
    list(documentId: DocumentId): Promise<VaulterShare[]>
    create(documentId: DocumentId, payload: CreateSharePayload): Promise<VaulterShare>
    revoke(documentId: DocumentId, shareId: DocumentId): Promise<void>
    resolve(token: string): Promise<SharedDocument>
  }
  collaboration: {
    token(documentId: DocumentId): Promise<CollaborationConnection>
  }
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

  async function unwrap<T>(promise: Promise<Envelope<T>>): Promise<T> {
    return (await promise).data
  }

  return {
    documents: {
      list: (query) => unwrap(request<Envelope<VaulterDocument[]>>('GET', '/documents', { query })),
      trashed: (query) => unwrap(request<Envelope<VaulterDocument[]>>('GET', '/documents/trash', { query })),
      get: (id) => unwrap(request<Envelope<VaulterDocument>>('GET', `/documents/${id}`)),
      create: (payload) => unwrap(request<Envelope<VaulterDocument>>('POST', '/documents', { body: payload })),
      update: (id, payload) => unwrap(request<Envelope<VaulterDocument>>('PATCH', `/documents/${id}`, { body: payload })),
      remove: (id) => request<void>('DELETE', `/documents/${id}`),
      forceDelete: (id) => request<void>('DELETE', `/documents/${id}/force`),
      restore: (id) => unwrap(request<Envelope<VaulterDocument>>('POST', `/documents/${id}/restore`)),
      move: (id, folderId) =>
        unwrap(request<Envelope<VaulterDocument>>('PATCH', `/documents/${id}/move`, { body: { folder_id: folderId } })),
      duplicate: (id) => unwrap(request<Envelope<VaulterDocument>>('POST', `/documents/${id}/duplicate`)),
      bulk: (payload) => unwrap(request<Envelope<BulkResult>>('POST', '/documents/bulk', { body: payload })),
      export: (id, format) =>
        unwrap(request<Envelope<DocumentExport>>('GET', `/documents/${id}/export`, { query: format ? { format } : undefined })),
    },
    versions: {
      list: (documentId) => unwrap(request<Envelope<VaulterVersion[]>>('GET', `/documents/${documentId}/versions`)),
      create: (documentId, payload) =>
        unwrap(request<Envelope<VaulterVersion>>('POST', `/documents/${documentId}/versions`, { body: payload ?? {} })),
      restore: (documentId, versionId) =>
        unwrap(request<Envelope<VaulterDocument>>('POST', `/documents/${documentId}/versions/${versionId}/restore`)),
    },
    folders: {
      list: (query) => unwrap(request<Envelope<VaulterFolder[]>>('GET', '/folders', { query })),
      create: (payload) => unwrap(request<Envelope<VaulterFolder>>('POST', '/folders', { body: payload })),
      update: (id, payload) => unwrap(request<Envelope<VaulterFolder>>('PATCH', `/folders/${id}`, { body: payload })),
      remove: (id) => request<void>('DELETE', `/folders/${id}`),
    },
    permissions: {
      list: (documentId) => unwrap(request<Envelope<VaulterPermission[]>>('GET', `/documents/${documentId}/permissions`)),
      grant: (documentId, payload) =>
        unwrap(request<Envelope<VaulterPermission>>('POST', `/documents/${documentId}/permissions`, { body: payload })),
      revoke: (documentId, permissionId) =>
        request<void>('DELETE', `/documents/${documentId}/permissions/${permissionId}`),
    },
    comments: {
      list: (documentId) => unwrap(request<Envelope<VaulterComment[]>>('GET', `/documents/${documentId}/comments`)),
      create: (documentId, payload) =>
        unwrap(request<Envelope<VaulterComment>>('POST', `/documents/${documentId}/comments`, { body: payload })),
      resolve: (documentId, commentId) =>
        unwrap(request<Envelope<VaulterComment>>('POST', `/documents/${documentId}/comments/${commentId}/resolve`)),
      remove: (documentId, commentId) => request<void>('DELETE', `/documents/${documentId}/comments/${commentId}`),
    },
    shares: {
      list: (documentId) => unwrap(request<Envelope<VaulterShare[]>>('GET', `/documents/${documentId}/shares`)),
      create: (documentId, payload) =>
        unwrap(request<Envelope<VaulterShare>>('POST', `/documents/${documentId}/shares`, { body: payload })),
      revoke: (documentId, shareId) => request<void>('DELETE', `/documents/${documentId}/shares/${shareId}`),
      resolve: (token) => unwrap(request<Envelope<SharedDocument>>('GET', `/shares/${token}`)),
    },
    collaboration: {
      token: (documentId) => request<CollaborationConnection>('POST', `/documents/${documentId}/collab/token`, { body: {} }),
    },
  }
}

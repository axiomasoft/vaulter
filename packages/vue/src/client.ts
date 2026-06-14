import type {
  BulkDocumentsPayload,
  BulkResult,
  CollaborationConnection,
  CreateCommentPayload,
  CreateDocumentPayload,
  CreateFolderPayload,
  CreateSharePayload,
  CreateVersionPayload,
  DocVaultClientOptions,
  DocVaultComment,
  DocVaultDocument,
  DocumentExport,
  ExportFormat,
  DocVaultFolder,
  DocVaultPermission,
  DocVaultShare,
  DocVaultVersion,
  DocumentId,
  GrantPermissionPayload,
  InstantiateTemplatePayload,
  ListDocumentsQuery,
  ListFoldersQuery,
  SharedDocument,
  UpdateDocumentPayload,
  UpdateFolderPayload,
  WorkspaceQuery,
} from './types'

/** Error thrown for non-2xx DocVault API responses. */
export class DocVaultApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors: Record<string, string[]> = {},
    public readonly response?: Response,
  ) {
    super(message)
    this.name = 'DocVaultApiError'
  }

  static async fromResponse(response: Response): Promise<DocVaultApiError> {
    let message = `DocVault API error (${response.status})`
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

    return new DocVaultApiError(response.status, message, errors, response)
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

export interface DocVaultClient {
  documents: {
    list(query: ListDocumentsQuery): Promise<DocVaultDocument[]>
    trashed(query: WorkspaceQuery): Promise<DocVaultDocument[]>
    get(id: DocumentId): Promise<DocVaultDocument>
    create(payload: CreateDocumentPayload): Promise<DocVaultDocument>
    update(id: DocumentId, payload: UpdateDocumentPayload): Promise<DocVaultDocument>
    remove(id: DocumentId): Promise<void>
    forceDelete(id: DocumentId): Promise<void>
    restore(id: DocumentId): Promise<DocVaultDocument>
    move(id: DocumentId, folderId: DocumentId | null): Promise<DocVaultDocument>
    duplicate(id: DocumentId): Promise<DocVaultDocument>
    instantiate(id: DocumentId, payload?: InstantiateTemplatePayload): Promise<DocVaultDocument>
    bulk(payload: BulkDocumentsPayload): Promise<BulkResult>
    export(id: DocumentId, format?: ExportFormat): Promise<DocumentExport>
  }
  versions: {
    list(documentId: DocumentId): Promise<DocVaultVersion[]>
    create(documentId: DocumentId, payload?: CreateVersionPayload): Promise<DocVaultVersion>
    restore(documentId: DocumentId, versionId: DocumentId): Promise<DocVaultDocument>
  }
  folders: {
    list(query: ListFoldersQuery): Promise<DocVaultFolder[]>
    create(payload: CreateFolderPayload): Promise<DocVaultFolder>
    update(id: DocumentId, payload: UpdateFolderPayload): Promise<DocVaultFolder>
    remove(id: DocumentId): Promise<void>
  }
  permissions: {
    list(documentId: DocumentId): Promise<DocVaultPermission[]>
    grant(documentId: DocumentId, payload: GrantPermissionPayload): Promise<DocVaultPermission>
    revoke(documentId: DocumentId, permissionId: DocumentId): Promise<void>
  }
  comments: {
    list(documentId: DocumentId): Promise<DocVaultComment[]>
    create(documentId: DocumentId, payload: CreateCommentPayload): Promise<DocVaultComment>
    resolve(documentId: DocumentId, commentId: DocumentId): Promise<DocVaultComment>
    remove(documentId: DocumentId, commentId: DocumentId): Promise<void>
  }
  shares: {
    list(documentId: DocumentId): Promise<DocVaultShare[]>
    create(documentId: DocumentId, payload: CreateSharePayload): Promise<DocVaultShare>
    revoke(documentId: DocumentId, shareId: DocumentId): Promise<void>
    resolve(token: string): Promise<SharedDocument>
  }
  collaboration: {
    token(documentId: DocumentId): Promise<CollaborationConnection>
  }
}

export function createDocVaultClient(options: DocVaultClientOptions = {}): DocVaultClient {
  const baseUrl = (options.baseUrl ?? '/api/docvault').replace(/\/+$/, '')
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
      throw await DocVaultApiError.fromResponse(response)
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
      list: (query) => unwrap(request<Envelope<DocVaultDocument[]>>('GET', '/documents', { query })),
      trashed: (query) => unwrap(request<Envelope<DocVaultDocument[]>>('GET', '/documents/trash', { query })),
      get: (id) => unwrap(request<Envelope<DocVaultDocument>>('GET', `/documents/${id}`)),
      create: (payload) => unwrap(request<Envelope<DocVaultDocument>>('POST', '/documents', { body: payload })),
      update: (id, payload) => unwrap(request<Envelope<DocVaultDocument>>('PATCH', `/documents/${id}`, { body: payload })),
      remove: (id) => request<void>('DELETE', `/documents/${id}`),
      forceDelete: (id) => request<void>('DELETE', `/documents/${id}/force`),
      restore: (id) => unwrap(request<Envelope<DocVaultDocument>>('POST', `/documents/${id}/restore`)),
      move: (id, folderId) =>
        unwrap(request<Envelope<DocVaultDocument>>('PATCH', `/documents/${id}/move`, { body: { folder_id: folderId } })),
      duplicate: (id) => unwrap(request<Envelope<DocVaultDocument>>('POST', `/documents/${id}/duplicate`)),
      instantiate: (id, payload) =>
        unwrap(request<Envelope<DocVaultDocument>>('POST', `/documents/${id}/instantiate`, { body: payload ?? {} })),
      bulk: (payload) => unwrap(request<Envelope<BulkResult>>('POST', '/documents/bulk', { body: payload })),
      export: (id, format) =>
        unwrap(request<Envelope<DocumentExport>>('GET', `/documents/${id}/export`, { query: format ? { format } : undefined })),
    },
    versions: {
      list: (documentId) => unwrap(request<Envelope<DocVaultVersion[]>>('GET', `/documents/${documentId}/versions`)),
      create: (documentId, payload) =>
        unwrap(request<Envelope<DocVaultVersion>>('POST', `/documents/${documentId}/versions`, { body: payload ?? {} })),
      restore: (documentId, versionId) =>
        unwrap(request<Envelope<DocVaultDocument>>('POST', `/documents/${documentId}/versions/${versionId}/restore`)),
    },
    folders: {
      list: (query) => unwrap(request<Envelope<DocVaultFolder[]>>('GET', '/folders', { query })),
      create: (payload) => unwrap(request<Envelope<DocVaultFolder>>('POST', '/folders', { body: payload })),
      update: (id, payload) => unwrap(request<Envelope<DocVaultFolder>>('PATCH', `/folders/${id}`, { body: payload })),
      remove: (id) => request<void>('DELETE', `/folders/${id}`),
    },
    permissions: {
      list: (documentId) => unwrap(request<Envelope<DocVaultPermission[]>>('GET', `/documents/${documentId}/permissions`)),
      grant: (documentId, payload) =>
        unwrap(request<Envelope<DocVaultPermission>>('POST', `/documents/${documentId}/permissions`, { body: payload })),
      revoke: (documentId, permissionId) =>
        request<void>('DELETE', `/documents/${documentId}/permissions/${permissionId}`),
    },
    comments: {
      list: (documentId) => unwrap(request<Envelope<DocVaultComment[]>>('GET', `/documents/${documentId}/comments`)),
      create: (documentId, payload) =>
        unwrap(request<Envelope<DocVaultComment>>('POST', `/documents/${documentId}/comments`, { body: payload })),
      resolve: (documentId, commentId) =>
        unwrap(request<Envelope<DocVaultComment>>('POST', `/documents/${documentId}/comments/${commentId}/resolve`)),
      remove: (documentId, commentId) => request<void>('DELETE', `/documents/${documentId}/comments/${commentId}`),
    },
    shares: {
      list: (documentId) => unwrap(request<Envelope<DocVaultShare[]>>('GET', `/documents/${documentId}/shares`)),
      create: (documentId, payload) =>
        unwrap(request<Envelope<DocVaultShare>>('POST', `/documents/${documentId}/shares`, { body: payload })),
      revoke: (documentId, shareId) => request<void>('DELETE', `/documents/${documentId}/shares/${shareId}`),
      resolve: (token) => unwrap(request<Envelope<SharedDocument>>('GET', `/shares/${token}`)),
    },
    collaboration: {
      token: (documentId) => request<CollaborationConnection>('POST', `/documents/${documentId}/collab/token`, { body: {} }),
    },
  }
}

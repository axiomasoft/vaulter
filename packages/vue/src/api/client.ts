import { createTransport, type Transport, type VaulterClientOptions } from './transport'
import {
  abortedError,
  DEFAULT_CHUNK_SIZE,
  type ResumableUpload,
  type ResumableUploadOptions,
} from './uploads/shared'
import { abortTus, runTus } from './uploads/tus'
import { runS3 } from './uploads/s3'
import type {
  AbilitiesExplained,
  Activity,
  ChangePage,
  CollaborationSession,
  Comment,
  CreateCommentInput,
  CreateDocumentInput,
  CreateGrantInput,
  CreateShareInput,
  CursorPage,
  CursorQuery,
  DocumentBody,
  DocumentContent,
  DocumentExport,
  DocumentRevision,
  DocumentRevisionWithContent,
  Drive,
  DriveId,
  FileRevision,
  Grant,
  IfMatch,
  ListNodesQuery,
  MintedShare,
  Node,
  NodeId,
  NodeWithIncludes,
  PublicNode,
  Share,
  UpdateShareInput,
  UploadProtocol,
  UploadSession,
} from './types'

export type { VaulterClientOptions } from './transport'

interface Envelope<T> {
  data: T
}

export interface StartUploadOptions extends ResumableUploadOptions {
  protocol?: UploadProtocol
  parentId?: NodeId | null
  name?: string
  mimeType?: string
  expectedSha256?: string
  /** Replace the content of this existing file node (needs `etag`, the node's current ETag). */
  replace?: { nodeId: NodeId } & IfMatch
}

export interface NodeMoveOptions extends IfMatch {
  onConflict?: 'fail' | 'rename'
}

export interface NodeRestoreOptions extends IfMatch {
  destinationId?: NodeId | null
  onConflict?: 'fail' | 'rename'
}

export interface ShareAccess {
  /** The session token a previous share response returned (`X-Share-Session`). */
  session?: string
  password?: string
}

/**
 * The Vaulter REST API v1 by resource. Methods that change a node or a document take the
 * resource's opaque `etag` as a REQUIRED `{ etag }` option and send it as `If-Match`; a stale
 * tag answers `node_sequence_mismatch`/`document_state_mismatch` (412), a missing one
 * `precondition_required` (428).
 */
export interface VaulterClient {
  drives: {
    get(driveId: DriveId): Promise<Drive>
    archive(driveId: DriveId): Promise<Drive>
    unarchive(driveId: DriveId): Promise<Drive>
    /** Start deleting a drive (it becomes `deleting`). */
    delete(driveId: DriveId): Promise<Drive>
  }

  nodes: {
    /** The direct children of a folder (or the drive root). Cursor-paged. */
    children(driveId: DriveId, query?: ListNodesQuery): Promise<CursorPage<Node>>
    search(driveId: DriveId, q: string, query?: CursorQuery): Promise<CursorPage<Node>>
    starred(driveId: DriveId, query?: CursorQuery): Promise<CursorPage<Node>>
    /** The cross-drive "shared with me" listing. */
    shared(query?: CursorQuery): Promise<CursorPage<Node>>
    get(nodeId: NodeId): Promise<Node>
    /** A node with `include=breadcrumb` (and optionally its first children page). */
    getWithIncludes(nodeId: NodeId, include: Array<'breadcrumb' | 'children'>): Promise<NodeWithIncludes>
    update(nodeId: NodeId, patch: { name: string }, options: IfMatch): Promise<Node>
    setProperties(
      nodeId: NodeId,
      patch: { namespace: string, set?: Record<string, unknown>, unset?: string[] },
      options: IfMatch,
    ): Promise<Node>
    move(nodeId: NodeId, destinationId: NodeId | null, options: NodeMoveOptions): Promise<Node>
    copy(nodeId: NodeId, destinationId?: NodeId | null, options?: { name?: string }): Promise<Node>
    trash(nodeId: NodeId, options: IfMatch): Promise<Node>
    restore(nodeId: NodeId, options: NodeRestoreOptions): Promise<Node>
    /** Purge a trashed node for good. */
    purge(nodeId: NodeId, options: IfMatch): Promise<void>
    star(nodeId: NodeId): Promise<Node>
    unstar(nodeId: NodeId): Promise<Node>
    abilities(nodeId: NodeId, options?: { explain?: string }): Promise<AbilitiesExplained>
  }

  trash: {
    list(driveId: DriveId, query?: CursorQuery): Promise<CursorPage<Node>>
    /** Purge the whole trash; resolves to the number of purged nodes. */
    empty(driveId: DriveId): Promise<number>
  }

  folders: {
    create(driveId: DriveId, input: { name: string, parentId?: NodeId | null }): Promise<Node>
  }

  files: {
    /** A simple one-request upload (multipart). */
    upload(driveId: DriveId, file: Blob, options?: { parentId?: NodeId | null, name?: string }): Promise<Node>
    /** The base-prefixed URL the browser hits to stream the current head. */
    downloadUrl(nodeId: NodeId): string
    download(nodeId: NodeId): Promise<Blob>
    revisions(nodeId: NodeId, query?: CursorQuery): Promise<CursorPage<FileRevision>>
    addRevision(nodeId: NodeId, file: Blob, options: IfMatch): Promise<FileRevision>
    revisionDownloadUrl(nodeId: NodeId, revisionId: string): string
    downloadRevision(nodeId: NodeId, revisionId: string): Promise<Blob>
    restoreRevision(nodeId: NodeId, revisionId: string, options: IfMatch): Promise<FileRevision>
    setRevisionRetention(nodeId: NodeId, revisionId: string, keepForever: boolean): Promise<FileRevision>
    deleteRevision(nodeId: NodeId, revisionId: string): Promise<void>
  }

  uploads: {
    /** Open a resumable session and return the runner (`start()` / `abort()`). */
    start(driveId: DriveId, file: Blob, options?: StartUploadOptions): ResumableUpload
    /** Poll a session (its `received_bytes` is the authoritative resume offset). */
    show(uploadId: string): Promise<UploadSession>
  }

  documents: {
    create(driveId: DriveId, input: CreateDocumentInput): Promise<Node>
    content(nodeId: NodeId): Promise<DocumentContent>
    update(nodeId: NodeId, content: DocumentBody, options: IfMatch): Promise<DocumentContent>
    export(nodeId: NodeId, format: string, options?: { revision?: string }): Promise<DocumentExport>
    revisions(nodeId: NodeId, query?: CursorQuery): Promise<CursorPage<DocumentRevision>>
    revision(nodeId: NodeId, revisionId: string): Promise<DocumentRevisionWithContent>
    snapshot(nodeId: NodeId, options?: { label?: string | null, keepForever?: boolean }): Promise<DocumentRevision>
    setRevisionRetention(nodeId: NodeId, revisionId: string, keepForever: boolean): Promise<DocumentRevision>
    restoreRevision(nodeId: NodeId, revisionId: string, options: IfMatch): Promise<DocumentContent>
    /** Mint a realtime session; the backend answers a different `schemaVersion` with a read-only one. */
    collaborationSession(nodeId: NodeId, options?: { schemaVersion?: number }): Promise<CollaborationSession>
  }

  grants: {
    list(nodeId: NodeId): Promise<Grant[]>
    create(nodeId: NodeId, input: CreateGrantInput): Promise<Grant>
    revoke(nodeId: NodeId, grantId: string): Promise<void>
  }

  access: {
    restrictInheritance(nodeId: NodeId, options?: { mode?: 'empty' | 'copy_current' }): Promise<Node>
    restoreInheritance(nodeId: NodeId): Promise<Node>
  }

  shares: {
    list(nodeId: NodeId): Promise<Share[]>
    /** Mint a share link — the response carries the raw token EXACTLY ONCE. */
    create(nodeId: NodeId, input?: CreateShareInput): Promise<MintedShare>
    update(nodeId: NodeId, shareId: string, input: UpdateShareInput): Promise<Share>
    /** Replace the token: the old one stops working at once; the new raw token comes ONCE. */
    rotate(nodeId: NodeId, shareId: string): Promise<MintedShare>
    revoke(nodeId: NodeId, shareId: string): Promise<void>
  }

  publicShares: {
    get(token: string, access?: ShareAccess): Promise<PublicNode>
    nodes(token: string, query?: CursorQuery, access?: ShareAccess): Promise<CursorPage<PublicNode>>
    downloadUrl(token: string, nodeId?: NodeId): string
  }

  comments: {
    list(nodeId: NodeId, query?: CursorQuery): Promise<CursorPage<Comment>>
    add(nodeId: NodeId, input: CreateCommentInput): Promise<Comment>
    resolve(nodeId: NodeId, commentId: string): Promise<Comment>
    remove(nodeId: NodeId, commentId: string): Promise<void>
  }

  activity: {
    forDrive(driveId: DriveId, query?: CursorQuery): Promise<CursorPage<Activity>>
    forNode(nodeId: NodeId, query?: CursorQuery): Promise<CursorPage<Activity>>
  }

  changes: {
    /** The cursor to start following the drive from "now". */
    start(driveId: DriveId): Promise<string>
    list(driveId: DriveId, query: { cursor: string, limit?: number }): Promise<ChangePage>
  }
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

function shareHeaders(access?: ShareAccess): Record<string, string> {
  return {
    ...(access?.session ? { 'X-Share-Session': access.session } : {}),
    ...(access?.password ? { 'X-Share-Password': access.password } : {}),
  }
}

export function createVaulterClient(options: VaulterClientOptions = {}): VaulterClient {
  const transport = createTransport(options)

  const data = async <T>(method: string, path: string, init?: Parameters<Transport['json']>[2]): Promise<T> =>
    (await transport.json<Envelope<T>>(method, path, init)).data
  const page = <T>(path: string, query?: object): Promise<CursorPage<T>> =>
    transport.json<CursorPage<T>>('GET', path, { query })
  const blob = async (path: string): Promise<Blob> => (await transport.raw('GET', path, { accept: '*/*' })).blob()
  const send = async (method: string, path: string, init?: Parameters<Transport['json']>[2]): Promise<void> => {
    await transport.json<void>(method, path, init)
  }

  /** The content endpoints carry the validator in the ETag header, not in the body. */
  async function documentContent(method: string, path: string, init?: Parameters<Transport['json']>[2]): Promise<DocumentContent> {
    const response = await transport.raw(method, path, init)
    const body = (await response.json()) as Envelope<Omit<DocumentContent, 'etag'>>

    return { ...body.data, etag: response.headers.get('ETag') }
  }

  function startUpload(driveId: DriveId, file: Blob, opts: StartUploadOptions = {}): ResumableUpload {
    const protocol = opts.protocol ?? 'tus'
    let uploadId: string | null = null
    let aborted = false

    async function start(): Promise<Node> {
      // The server completes a multipart session only from bytes it assembled, so an empty file
      // cannot go through it; fail before creating a session nobody can finish.
      if (protocol === 's3' && file.size === 0) {
        throw new Error('[Vaulter] An empty file cannot be uploaded through S3 multipart; use files.upload() or tus.')
      }

      const session = (await transport.json<Envelope<UploadSession>>('POST', `/drives/${driveId}/uploads`, {
        json: {
          protocol: protocol === 's3' ? 's3-multipart' : 'tus',
          parent_id: opts.parentId,
          node_id: opts.replace?.nodeId,
          name: opts.name,
          total_size_bytes: file.size,
          expected_sha256: opts.expectedSha256,
          mime_type: opts.mimeType,
        },
        etag: opts.replace?.etag,
      })).data
      uploadId = session.upload_id

      // abort() ran while the session was being created: it had nothing to delete yet.
      if (aborted) {
        await abortUpload()
        throw abortedError()
      }

      const run = {
        total: file.size,
        chunkSize: opts.chunkSize ?? (session.chunk_size > 0 ? session.chunk_size : DEFAULT_CHUNK_SIZE),
        onProgress: opts.onProgress,
        isAborted: () => aborted,
      }

      return protocol === 's3'
        ? runS3(transport, uploadId, file, run)
        : runTus(transport, uploadId, file, run)
    }

    // Only tus has a server-side abort; an s3-multipart session simply expires.
    async function abortUpload(): Promise<void> {
      if (uploadId !== null && protocol === 'tus') {
        await abortTus(transport, uploadId)
      }
    }

    async function abort(): Promise<void> {
      aborted = true
      await abortUpload()
    }

    return { start, abort }
  }

  return {
    drives: {
      get: (driveId) => data('GET', `/drives/${driveId}`),
      archive: (driveId) => data('POST', `/drives/${driveId}/archive`),
      unarchive: (driveId) => data('POST', `/drives/${driveId}/unarchive`),
      delete: (driveId) => data('DELETE', `/drives/${driveId}`),
    },

    nodes: {
      children: (driveId, query) =>
        page(`/drives/${driveId}/nodes`, {
          ...(query?.parent != null ? { parent: query.parent } : {}),
          ...cursorQuery(query),
        }),
      search: (driveId, q, query) => page(`/drives/${driveId}/search`, { q, ...cursorQuery(query) }),
      starred: (driveId, query) => page(`/drives/${driveId}/starred`, cursorQuery(query)),
      shared: (query) => page('/shared', cursorQuery(query)),
      get: (nodeId) => data('GET', `/nodes/${nodeId}`),
      getWithIncludes: (nodeId, include) =>
        data('GET', `/nodes/${nodeId}`, { query: { include: include.join(',') } }),
      update: (nodeId, patch, { etag }) => data('PATCH', `/nodes/${nodeId}`, { json: patch, etag }),
      setProperties: (nodeId, patch, { etag }) =>
        data('PATCH', `/nodes/${nodeId}/properties`, { json: patch, etag }),
      move: (nodeId, destinationId, { etag, onConflict }) =>
        data('POST', `/nodes/${nodeId}/move`, {
          json: { destination_id: destinationId, on_conflict: onConflict },
          etag,
        }),
      copy: (nodeId, destinationId, copyOptions) =>
        data('POST', `/nodes/${nodeId}/copy`, {
          json: { destination_id: destinationId ?? null, name: copyOptions?.name },
        }),
      trash: (nodeId, { etag }) => data('POST', `/nodes/${nodeId}/trash`, { etag }),
      restore: (nodeId, { etag, destinationId, onConflict }) =>
        data('POST', `/nodes/${nodeId}/restore`, {
          json: { destination_id: destinationId, on_conflict: onConflict },
          etag,
        }),
      purge: (nodeId, { etag }) => send('DELETE', `/nodes/${nodeId}`, { etag }),
      star: (nodeId) => data('PUT', `/nodes/${nodeId}/star`),
      unstar: (nodeId) => data('DELETE', `/nodes/${nodeId}/star`),
      abilities: (nodeId, abilityOptions) =>
        data('GET', `/nodes/${nodeId}/abilities`, { query: { explain: abilityOptions?.explain } }),
    },

    trash: {
      list: (driveId, query) => page(`/drives/${driveId}/trash`, cursorQuery(query)),
      empty: async (driveId) =>
        (await data<{ purged_count: number }>('DELETE', `/drives/${driveId}/trash`)).purged_count,
    },

    folders: {
      create: (driveId, input) =>
        data('POST', `/drives/${driveId}/folders`, {
          json: { name: input.name, parent_id: input.parentId ?? null },
        }),
    },

    files: {
      upload: (driveId, file, uploadOptions) => {
        const form = new FormData()
        form.append('file', file, uploadOptions?.name)
        if (uploadOptions?.parentId != null) {
          form.append('parent_id', uploadOptions.parentId)
        }
        if (uploadOptions?.name != null) {
          form.append('name', uploadOptions.name)
        }
        // FormData drives its own multipart Content-Type/boundary — never set it.
        return data('POST', `/drives/${driveId}/files`, { body: form })
      },
      downloadUrl: (nodeId) => transport.url(`/files/${nodeId}/download`),
      download: (nodeId) => blob(`/files/${nodeId}/download`),
      revisions: (nodeId, query) => page(`/files/${nodeId}/revisions`, cursorQuery(query)),
      addRevision: (nodeId, file, { etag }) => {
        const form = new FormData()
        form.append('file', file)
        return data('POST', `/files/${nodeId}/revisions`, { body: form, etag })
      },
      revisionDownloadUrl: (nodeId, revisionId) =>
        transport.url(`/files/${nodeId}/revisions/${revisionId}/download`),
      downloadRevision: (nodeId, revisionId) => blob(`/files/${nodeId}/revisions/${revisionId}/download`),
      restoreRevision: (nodeId, revisionId, { etag }) =>
        data('POST', `/files/${nodeId}/revisions/${revisionId}/restore`, { etag }),
      setRevisionRetention: (nodeId, revisionId, keepForever) =>
        data('PATCH', `/files/${nodeId}/revisions/${revisionId}`, { json: { keep_forever: keepForever } }),
      deleteRevision: (nodeId, revisionId) => send('DELETE', `/files/${nodeId}/revisions/${revisionId}`),
    },

    uploads: {
      start: startUpload,
      show: (uploadId) => data('GET', `/uploads/${uploadId}`),
    },

    documents: {
      create: (driveId, input) =>
        data('POST', `/drives/${driveId}/documents`, {
          json: {
            name: input.name,
            parent_id: input.parentId ?? null,
            type: input.type,
            content: input.content,
          },
        }),
      content: (nodeId) => documentContent('GET', `/documents/${nodeId}/content`),
      update: (nodeId, content, { etag }) =>
        documentContent('PUT', `/documents/${nodeId}/content`, { json: { content }, etag }),
      export: async (nodeId, format, exportOptions) => {
        const response = await transport.raw('GET', `/documents/${nodeId}/export/${format}`, {
          query: { revision: exportOptions?.revision },
          accept: '*/*',
        })

        return {
          blob: await response.blob(),
          filename: filenameFromDisposition(response.headers.get('Content-Disposition')) ?? `export-${nodeId}`,
          mimeType: response.headers.get('Content-Type') ?? 'application/octet-stream',
        }
      },
      revisions: (nodeId, query) => page(`/documents/${nodeId}/revisions`, cursorQuery(query)),
      revision: (nodeId, revisionId) => data('GET', `/documents/${nodeId}/revisions/${revisionId}`),
      snapshot: (nodeId, snapshotOptions) =>
        data('POST', `/documents/${nodeId}/revisions`, {
          json: { label: snapshotOptions?.label, keep_forever: snapshotOptions?.keepForever },
        }),
      setRevisionRetention: (nodeId, revisionId, keepForever) =>
        data('PATCH', `/documents/${nodeId}/revisions/${revisionId}`, { json: { keep_forever: keepForever } }),
      restoreRevision: (nodeId, revisionId, { etag }) =>
        documentContent('POST', `/documents/${nodeId}/revisions/${revisionId}/restore`, { etag }),
      collaborationSession: (nodeId, sessionOptions) =>
        data('POST', `/documents/${nodeId}/collaboration/session`, {
          json: sessionOptions?.schemaVersion === undefined ? {} : { schema_version: sessionOptions.schemaVersion },
        }),
    },

    grants: {
      list: (nodeId) => data('GET', `/nodes/${nodeId}/grants`),
      create: (nodeId, input) =>
        data('POST', `/nodes/${nodeId}/grants`, {
          json: {
            subject: input.subject,
            role: input.role,
            scope: input.scope,
            expires_at: input.expiresAt,
          },
        }),
      revoke: (nodeId, grantId) => send('DELETE', `/nodes/${nodeId}/grants/${grantId}`),
    },

    access: {
      restrictInheritance: (nodeId, restrictOptions) =>
        data('POST', `/nodes/${nodeId}/inheritance/restrict`, { json: { mode: restrictOptions?.mode } }),
      restoreInheritance: (nodeId) => data('POST', `/nodes/${nodeId}/inheritance/restore`),
    },

    shares: {
      list: (nodeId) => data('GET', `/nodes/${nodeId}/shares`),
      create: (nodeId, input) =>
        data('POST', `/nodes/${nodeId}/shares`, {
          json: {
            role: input?.role,
            audience: input?.audience,
            scope: input?.scope,
            password: input?.password,
            expires_at: input?.expiresAt,
            max_uses: input?.maxUses,
          },
        }),
      // `undefined` keys are dropped by JSON, so only the fields given reach the server.
      update: (nodeId, shareId, input) =>
        data('PATCH', `/nodes/${nodeId}/shares/${shareId}`, {
          json: {
            audience: input.audience,
            password: input.password,
            expires_at: input.expiresAt,
            max_uses: input.maxUses,
          },
        }),
      rotate: (nodeId, shareId) => data('POST', `/nodes/${nodeId}/shares/${shareId}/rotate`),
      revoke: (nodeId, shareId) => send('DELETE', `/nodes/${nodeId}/shares/${shareId}`),
    },

    publicShares: {
      get: (token, access) => data('GET', `/shares/${token}`, { headers: shareHeaders(access) }),
      nodes: (token, query, access) =>
        transport.json<CursorPage<PublicNode>>('GET', `/shares/${token}/nodes`, {
          query: cursorQuery(query),
          headers: shareHeaders(access),
        }),
      downloadUrl: (token, nodeId) =>
        transport.url(`/shares/${token}/download${nodeId !== undefined ? `?node=${encodeURIComponent(nodeId)}` : ''}`),
    },

    comments: {
      list: (nodeId, query) => page(`/nodes/${nodeId}/comments`, cursorQuery(query)),
      add: (nodeId, input) =>
        data('POST', `/nodes/${nodeId}/comments`, {
          json: { body: input.body, parent_id: input.parentId, anchor: input.anchor },
        }),
      resolve: (nodeId, commentId) => data('POST', `/nodes/${nodeId}/comments/${commentId}/resolve`),
      remove: (nodeId, commentId) => send('DELETE', `/nodes/${nodeId}/comments/${commentId}`),
    },

    activity: {
      forDrive: (driveId, query) => page(`/drives/${driveId}/activity`, cursorQuery(query)),
      forNode: (nodeId, query) => page(`/nodes/${nodeId}/activity`, cursorQuery(query)),
    },

    changes: {
      start: async (driveId) => (await data<{ cursor: string }>('GET', `/drives/${driveId}/changes/start`)).cursor,
      list: (driveId, query) =>
        transport.json<ChangePage>('GET', `/drives/${driveId}/changes`, {
          query: { cursor: query.cursor, limit: query.limit },
        }),
    },
  }
}

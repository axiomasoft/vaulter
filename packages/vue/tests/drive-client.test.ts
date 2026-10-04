import { describe, expect, it, vi } from 'vitest'
import { createVaulterClient, VaulterApiError } from '../src/client'
import type { TipTapDocument } from '../src/types'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

/** A 204 with tus headers (no body) — the not-yet-complete PATCH / HEAD reply. */
function tusResponse(headers: Record<string, string>, status = 204): Response {
  return new Response(null, { status, headers })
}

describe('createVaulterClient — Drive (M1–M4)', () => {
  describe('storages + nodes', () => {
    it('gets a storage and unwraps the data envelope', async () => {
      const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: { id: 7, name: 'Drive' } }))
      const client = createVaulterClient({ baseUrl: '/api/vaulter', fetch: fetchMock })

      const storage = await client.storages.get(7)

      expect(storage.id).toBe(7)
      const [url, init] = fetchMock.mock.calls[0]
      expect(url).toBe('/api/vaulter/storages/7')
      expect(init.method).toBe('GET')
    })

    it('lists nodes with parent + cursor as query params (no unwrap — full page)', async () => {
      const fetchMock = vi.fn().mockResolvedValue(
        jsonResponse({ data: [{ id: 1 }, { id: 2 }], meta: { next_cursor: 'c2', prev_cursor: null } }),
      )
      const client = createVaulterClient({ fetch: fetchMock })

      const page = await client.nodes.list(7, { parentId: 5, cursor: 'c1' })

      expect(page.data).toHaveLength(2)
      expect(page.meta.next_cursor).toBe('c2')
      const [url] = fetchMock.mock.calls[0]
      expect(url).toContain('/storages/7/nodes?')
      expect(url).toContain('parent_id=5')
      expect(url).toContain('cursor=c1')
    })

    it('creates a folder with the snake_case parent_id body', async () => {
      const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: { id: 9, kind: 'folder' } }, 201))
      const client = createVaulterClient({ fetch: fetchMock, csrfToken: 'tok' })

      const node = await client.nodes.createFolder(7, { name: 'Docs', parentId: 5 })

      expect(node.id).toBe(9)
      const [url, init] = fetchMock.mock.calls[0]
      expect(url).toBe('/api/vaulter/storages/7/folders')
      expect(init.method).toBe('POST')
      expect(init.headers['X-CSRF-TOKEN']).toBe('tok')
      const body = JSON.parse(init.body)
      expect(body).toEqual({ name: 'Docs', parent_id: 5 })
    })

    it('renames via PATCH /nodes/{id}', async () => {
      const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: { id: 9, name: 'Renamed' } }))
      const client = createVaulterClient({ fetch: fetchMock })

      await client.nodes.rename(9, 'Renamed')

      const [url, init] = fetchMock.mock.calls[0]
      expect(url).toBe('/api/vaulter/nodes/9')
      expect(init.method).toBe('PATCH')
      expect(JSON.parse(init.body)).toEqual({ name: 'Renamed' })
    })

    it('moves and copies with the parent_id body', async () => {
      const fetchMock = vi.fn()
        .mockResolvedValueOnce(jsonResponse({ data: { id: 9, parent_id: 3 } }))
        .mockResolvedValueOnce(jsonResponse({ data: { id: 10, parent_id: 3 } }, 201))
      const client = createVaulterClient({ fetch: fetchMock })

      await client.nodes.move(9, 3)
      await client.nodes.copy(9, 3)

      expect(fetchMock.mock.calls[0][0]).toBe('/api/vaulter/nodes/9/move')
      expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ parent_id: 3 })
      expect(fetchMock.mock.calls[1][0]).toBe('/api/vaulter/nodes/9/copy')
    })

    it('trashes (DELETE), restores (POST) and force-deletes (DELETE /force)', async () => {
      const fetchMock = vi.fn()
        .mockResolvedValueOnce(jsonResponse({ data: { id: 9, trashed_at: 'now' } }))
        .mockResolvedValueOnce(jsonResponse({ data: { id: 9, trashed_at: null } }))
        .mockResolvedValueOnce(new Response(null, { status: 204 }))
      const client = createVaulterClient({ fetch: fetchMock })

      await client.nodes.trash(9)
      await client.nodes.restore(9)
      await expect(client.nodes.forceDelete(9)).resolves.toBeUndefined()

      expect(fetchMock.mock.calls[0]).toMatchObject(['/api/vaulter/nodes/9', { method: 'DELETE' }])
      expect(fetchMock.mock.calls[1][0]).toBe('/api/vaulter/nodes/9/restore')
      expect(fetchMock.mock.calls[2]).toMatchObject(['/api/vaulter/nodes/9/force', { method: 'DELETE' }])
    })

    it('searches via ?q= and lists the trash', async () => {
      const fetchMock = vi.fn()
        .mockResolvedValueOnce(jsonResponse({ data: [{ id: 1 }], meta: { next_cursor: null, prev_cursor: null } }))
        .mockResolvedValueOnce(jsonResponse({ data: [{ id: 2 }], meta: { next_cursor: null, prev_cursor: null } }))
      const client = createVaulterClient({ fetch: fetchMock })

      await client.nodes.search(7, 'budget')
      await client.nodes.trashed(7)

      expect(fetchMock.mock.calls[0][0]).toContain('/storages/7/search?')
      expect(fetchMock.mock.calls[0][0]).toContain('q=budget')
      expect(fetchMock.mock.calls[1][0]).toContain('/storages/7/trash')
    })

    it('lists "shared with me" at the top-level /shared with query-bound cursor params', async () => {
      const fetchMock = vi.fn().mockResolvedValue(
        jsonResponse({
          data: [{ id: 1, storage_id: 7 }, { id: 2, storage_id: 9 }],
          meta: { next_cursor: 'next-shared', prev_cursor: null, per_page: 2, scanned: 4 },
        }),
      )
      const client = createVaulterClient({ fetch: fetchMock })

      const page = await client.nodes.shared({ cursor: 'after-denied', perPage: 2 })

      // The cursor page envelope is the result.
      expect(page.data.map((n) => n.id)).toEqual([1, 2])
      expect(page.meta.next_cursor).toBe('next-shared')
      expect(page.meta).not.toHaveProperty('total')
      const [url, init] = fetchMock.mock.calls[0]
      // Top-level, NOT storage-scoped (the subject index is cross-storage).
      expect(url).toContain('/api/vaulter/shared?')
      expect(url).not.toContain('/storages/')
      expect(url).toContain('cursor=after-denied')
      expect(new URL(url, 'https://test.invalid').searchParams.has('page')).toBe(false)
      expect(url).toContain('per_page=2')
      expect(init.method).toBe('GET')
    })

    it('lists "shared with me" with no query (defaults) and tolerates an empty page', async () => {
      const fetchMock = vi.fn().mockResolvedValue(
        jsonResponse({
          data: [],
          meta: { next_cursor: null, prev_cursor: null, per_page: 50, scanned: 0 },
        }),
      )
      const client = createVaulterClient({ fetch: fetchMock })

      const page = await client.nodes.shared()

      expect(page.data).toEqual([])
      expect(page.meta.next_cursor).toBeNull()
      // No cursor/per_page params when none were given.
      expect(fetchMock.mock.calls[0][0]).toBe('/api/vaulter/shared')
    })

    it('builds relative download URLs and fetches bytes as a Blob', async () => {
      const fetchMock = vi.fn().mockResolvedValue(
        new Response('hello', { status: 200, headers: { 'Content-Type': 'application/octet-stream' } }),
      )
      const client = createVaulterClient({ fetch: fetchMock })

      expect(client.nodes.downloadUrl(9)).toBe('/api/vaulter/nodes/9/download')
      expect(client.nodes.versionDownloadUrl(9, 4)).toBe('/api/vaulter/nodes/9/versions/4/download')

      const blob = await client.nodes.download(9)
      expect(await blob.text()).toBe('hello')
    })

    it('throws VaulterApiError on a non-2xx Drive response', async () => {
      // A fresh Response per call — a Response body can only be read once.
      const fetchMock = vi.fn().mockImplementation(() =>
        Promise.resolve(jsonResponse({ message: 'Forbidden', errors: { node: ['denied'] } }, 403)),
      )
      const client = createVaulterClient({ fetch: fetchMock })

      await expect(client.nodes.show(9)).rejects.toBeInstanceOf(VaulterApiError)
      await expect(client.nodes.show(9)).rejects.toMatchObject({ status: 403, message: 'Forbidden' })
    })
  })

  describe('uploads', () => {
    it('direct upload posts multipart FormData (no JSON Content-Type)', async () => {
      const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: { id: 11, kind: 'file' } }, 201))
      const client = createVaulterClient({ fetch: fetchMock })

      const file = new File(['data'], 'a.txt', { type: 'text/plain' })
      const node = await client.uploads.upload(7, file, { parentId: 5, name: 'a.txt' })

      expect(node.id).toBe(11)
      const [url, init] = fetchMock.mock.calls[0]
      expect(url).toBe('/api/vaulter/storages/7/uploads')
      expect(init.method).toBe('POST')
      expect(init.body).toBeInstanceOf(FormData)
      // The transport must NOT force a JSON Content-Type on a FormData body.
      expect(init.headers['Content-Type']).toBeUndefined()
      const form = init.body as FormData
      expect(form.get('parent_id')).toBe('5')
      expect(form.get('name')).toBe('a.txt')
    })

    it('creates a resumable session with the snake_case body', async () => {
      const fetchMock = vi.fn().mockResolvedValue(
        jsonResponse({ data: { upload_id: 'u1', protocol: 'tus', status: 'pending', received_bytes: 0 } }, 201),
      )
      const client = createVaulterClient({ fetch: fetchMock })

      const session = await client.uploads.createSession(7, { totalSizeBytes: 1024, mimeType: 'text/plain' })

      expect(session.upload_id).toBe('u1')
      const [url, init] = fetchMock.mock.calls[0]
      expect(url).toBe('/api/vaulter/storages/7/resumable-uploads')
      const body = JSON.parse(init.body)
      expect(body.total_size_bytes).toBe(1024)
      expect(body.mime_type).toBe('text/plain')
    })

    it('reads the authoritative Upload-Offset from the tus HEAD', async () => {
      const fetchMock = vi.fn().mockResolvedValue(
        tusResponse({ 'Tus-Resumable': '1.0.0', 'Upload-Offset': '512', 'Upload-Length': '1024' }),
      )
      const client = createVaulterClient({ fetch: fetchMock })

      const offset = await client.uploads.head('u1')

      expect(offset).toBe(512)
      const [url, init] = fetchMock.mock.calls[0]
      expect(url).toBe('/api/vaulter/resumable-uploads/u1')
      expect(init.method).toBe('HEAD')
      expect(init.headers['Tus-Resumable']).toBe('1.0.0')
    })

    it('PATCHes a chunk with the Upload-Offset header and reports the new offset', async () => {
      const fetchMock = vi.fn().mockResolvedValue(
        tusResponse({ 'Tus-Resumable': '1.0.0', 'Upload-Offset': '512' }),
      )
      const client = createVaulterClient({ fetch: fetchMock })

      const result = await client.uploads.patch('u1', new Blob(['xxxxx']), 0)

      expect(result).toEqual({ complete: false, offset: 512 })
      const [url, init] = fetchMock.mock.calls[0]
      expect(url).toBe('/api/vaulter/resumable-uploads/u1')
      expect(init.method).toBe('PATCH')
      expect(init.headers['Upload-Offset']).toBe('0')
      expect(init.headers['Content-Type']).toBe('application/offset+octet-stream')
    })

    it('returns the created node when the final tus PATCH completes (201)', async () => {
      const fetchMock = vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ data: { id: 12, kind: 'file' } }), {
          status: 201,
          headers: { 'Content-Type': 'application/json', 'Upload-Offset': '1024' },
        }),
      )
      const client = createVaulterClient({ fetch: fetchMock })

      const result = await client.uploads.patch('u1', new Blob(['xxxxx']), 512)

      expect(result).toEqual({ complete: true, offset: 1024, node: { id: 12, kind: 'file' } })
    })

    it('drives a resumable upload to completion, resuming from the server offset', async () => {
      // head → 0; PATCH #1 → offset 5 (incomplete); PATCH #2 → 201 with node.
      const fetchMock = vi.fn()
        .mockResolvedValueOnce(jsonResponse({ data: { upload_id: 'u9', protocol: 'tus', status: 'pending', received_bytes: 0 } }, 201))
        .mockResolvedValueOnce(tusResponse({ 'Upload-Offset': '0' })) // HEAD
        .mockResolvedValueOnce(tusResponse({ 'Upload-Offset': '5' })) // PATCH #1
        .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: 99, kind: 'file' } }), {
          status: 201,
          headers: { 'Content-Type': 'application/json', 'Upload-Offset': '10' },
        }))
      const client = createVaulterClient({ fetch: fetchMock })

      const progress: Array<[number, number]> = []
      const file = new Blob(['0123456789']) // 10 bytes
      const upload = client.uploads.resumable(7, file, {
        chunkSize: 5,
        onProgress: (sent, total) => progress.push([sent, total]),
      })
      const node = await upload.start()

      expect(node.id).toBe(99)
      // create, HEAD, PATCH, PATCH
      expect(fetchMock.mock.calls[0][0]).toContain('/storages/7/resumable-uploads')
      expect(fetchMock.mock.calls[1][1].method).toBe('HEAD')
      expect(fetchMock.mock.calls[2][1].method).toBe('PATCH')
      expect(fetchMock.mock.calls[3][1].method).toBe('PATCH')
      // progress ends at 10/10.
      expect(progress.at(-1)).toEqual([10, 10])
    })

    it('records an S3 part and completes the multipart upload', async () => {
      const fetchMock = vi.fn()
        .mockResolvedValueOnce(jsonResponse({ part_no: 1, size: 5, etag: 'e1' }, 201))
        .mockResolvedValueOnce(jsonResponse({ data: { id: 13, kind: 'file' } }, 201))
      const client = createVaulterClient({ fetch: fetchMock })

      const part = await client.uploads.putPart('u1', 1, 0, new Blob(['xxxxx']))
      expect(part).toEqual({ part_no: 1, size: 5, etag: 'e1' })
      expect(fetchMock.mock.calls[0][0]).toContain('/resumable-uploads/u1/parts?')
      expect(fetchMock.mock.calls[0][0]).toContain('part_no=1')

      const node = await client.uploads.complete('u1')
      expect(node.id).toBe(13)
      expect(fetchMock.mock.calls[1][0]).toBe('/api/vaulter/resumable-uploads/u1/complete')
    })
  })

  describe('grants', () => {
    it('lists, grants and revokes node grants', async () => {
      const fetchMock = vi.fn()
        .mockResolvedValueOnce(jsonResponse({ data: [{ id: 1 }], meta: { next_cursor: null, prev_cursor: null } }))
        .mockResolvedValueOnce(jsonResponse({ data: { id: 2, level: 'editor' } }, 201))
        .mockResolvedValueOnce(new Response(null, { status: 204 }))
      const client = createVaulterClient({ fetch: fetchMock })

      await client.grants.list(9)
      await client.grants.grant(9, { subjectType: 'user', subjectId: 3, level: 'editor', scope: 'subtree' })
      await client.grants.revoke(9, { subjectType: 'user', subjectId: 3 })

      expect(fetchMock.mock.calls[0][0]).toContain('/nodes/9/grants')
      const grantBody = JSON.parse(fetchMock.mock.calls[1][1].body)
      expect(grantBody).toMatchObject({ subject_type: 'user', subject_id: 3, level: 'editor', scope: 'subtree' })
      // Revoke addresses the subject in the body of a DELETE.
      expect(fetchMock.mock.calls[2][1].method).toBe('DELETE')
      expect(JSON.parse(fetchMock.mock.calls[2][1].body)).toEqual({ subject_type: 'user', subject_id: 3 })
    })
  })

  describe('node shares', () => {
    it('mints a share returning the raw token ONCE, then lists without it', async () => {
      const fetchMock = vi.fn()
        .mockResolvedValueOnce(jsonResponse({
          data: { id: 1, node_id: 9, token_prefix: 'abc', token: 'abc.SECRET', url: 'https://x/s/abc.SECRET', level: 'reader' },
        }, 201))
        .mockResolvedValueOnce(jsonResponse({
          data: [{ id: 1, node_id: 9, token_prefix: 'abc', level: 'reader' }],
          meta: { next_cursor: null, prev_cursor: null },
        }))
      const client = createVaulterClient({ fetch: fetchMock })

      const created = await client.nodeShares.create(9, { level: 'reader', expiresAt: null })
      expect(created.token).toBe('abc.SECRET')
      expect(created.url).toContain('abc.SECRET')

      const page = await client.nodeShares.list(9)
      // The list shape never carries a token field.
      expect(page.data[0]).not.toHaveProperty('token')
    })

    it('revokes a share and resolves a public token', async () => {
      const fetchMock = vi.fn()
        .mockResolvedValueOnce(new Response(null, { status: 204 }))
        .mockResolvedValueOnce(jsonResponse({ data: { id: 5, kind: 'file', name: 'shared.pdf' } }))
      const client = createVaulterClient({ fetch: fetchMock })

      await client.nodeShares.revoke(9, 1)
      expect(fetchMock.mock.calls[0]).toMatchObject(['/api/vaulter/nodes/9/shares/1', { method: 'DELETE' }])

      const shared = await client.nodeShares.publicResolve('abc.SECRET')
      expect(shared.name).toBe('shared.pdf')
      expect(fetchMock.mock.calls[1][0]).toBe('/api/vaulter/share/abc.SECRET')
      expect(client.nodeShares.publicDownloadUrl('abc.SECRET')).toBe('/api/vaulter/share/abc.SECRET/download')
    })
  })

  describe('activity', () => {
    it('reads the storage and node feeds with a cursor', async () => {
      const fetchMock = vi.fn().mockImplementation(() =>
        Promise.resolve(jsonResponse({ data: [{ id: 1, verb: 'node.created' }], meta: { next_cursor: 'c2', prev_cursor: null } })),
      )
      const client = createVaulterClient({ fetch: fetchMock })

      await client.activity.forStorage(7, { cursor: 'c1' })
      await client.activity.forNode(9)

      expect(fetchMock.mock.calls[0][0]).toContain('/storages/7/activity?')
      expect(fetchMock.mock.calls[0][0]).toContain('cursor=c1')
      expect(fetchMock.mock.calls[1][0]).toContain('/nodes/9/activity')
    })
  })

  describe('document nodes', () => {
    it('creates a document node, reads + autosaves content, exports and resolves the handler', async () => {
      const doc: TipTapDocument = { type: 'doc', content: [] }
      const fetchMock = vi.fn()
        .mockResolvedValueOnce(jsonResponse({ data: { id: 20, kind: 'document' } }, 201))
        .mockResolvedValueOnce(jsonResponse({ data: { id: 30, node_id: 20, content: doc, version_no: 1 } }))
        .mockResolvedValueOnce(jsonResponse({ data: { id: 31, node_id: 20, is_autosave: true, version_no: 2 } }))
        .mockResolvedValueOnce(jsonResponse({ data: { format: 'html', content: '<p></p>', mime_type: 'text/html', filename: 'd.html' } }))
        .mockResolvedValueOnce(jsonResponse({ data: { id: 'tiptap', capabilities: { editable: true }, ui: { component: 'VaulterEditor' } } }))
      const client = createVaulterClient({ fetch: fetchMock })

      const node = await client.documentNodes.createNode(7, { name: 'Spec', content: doc, parentId: 5 })
      expect(node.id).toBe(20)
      expect(fetchMock.mock.calls[0][0]).toBe('/api/vaulter/storages/7/documents')
      expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({ name: 'Spec', parent_id: 5 })

      const content = await client.documentNodes.content(20)
      expect(content.content).toEqual(doc)
      expect(fetchMock.mock.calls[1][0]).toBe('/api/vaulter/nodes/20/content')

      const saved = await client.documentNodes.updateContent(20, doc, { autosave: true })
      expect(saved.is_autosave).toBe(true)
      expect(fetchMock.mock.calls[2][1].method).toBe('PATCH')
      expect(JSON.parse(fetchMock.mock.calls[2][1].body)).toMatchObject({ content: doc })

      const exported = await client.documentNodes.export(20, 'html')
      expect(exported.mime_type).toBe('text/html')
      expect(fetchMock.mock.calls[3][0]).toContain('/nodes/20/export?format=html')

      const handler = await client.documentNodes.handler(20)
      expect(handler.id).toBe('tiptap')
      expect(fetchMock.mock.calls[4][0]).toBe('/api/vaulter/nodes/20/handler')
    })

    it('creates a spreadsheet node handler-aware — defaults to the xlsx mime + opaque workbook', async () => {
      const workbook = { id: 'wb1', sheets: {} }
      const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: { id: 21, kind: 'document' } }, 201))
      const client = createVaulterClient({ fetch: fetchMock })

      const node = await client.documentNodes.createWorkbookNode(7, { name: 'Budget.xlsx', workbook, parentId: 5 })

      expect(node.id).toBe(21)
      expect(fetchMock.mock.calls[0][0]).toBe('/api/vaulter/storages/7/documents')
      expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
        name: 'Budget.xlsx',
        parent_id: 5,
        mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        content: workbook,
      })
    })

    it('snapshots a named version and restores a prior version', async () => {
      const fetchMock = vi.fn()
        .mockResolvedValueOnce(jsonResponse({ data: { id: 40, snapshot_label: 'v1', keep_forever: true } }, 201))
        .mockResolvedValueOnce(jsonResponse({ data: { id: 41, version_no: 3 } }, 201))
      const client = createVaulterClient({ fetch: fetchMock })

      await client.documentNodes.createNamedVersion(20, 'v1', true)
      expect(fetchMock.mock.calls[0][0]).toBe('/api/vaulter/nodes/20/document-versions')
      expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ label: 'v1', keep_forever: true })

      await client.documentNodes.restoreVersion(20, 40)
      expect(fetchMock.mock.calls[1][0]).toBe('/api/vaulter/nodes/20/document-versions/40/restore')
    })
  })
})

import { describe, expect, it } from 'vitest'
import { createVaulterClient } from '../../src/api/client'
import { VaulterApiError } from '../../src/api/errors'
import { call, emptyResponse, fetchSequence, jsonResponse, problemResponse } from '../support/http'

const page = (data: unknown[] = []) => ({ data, meta: { next_cursor: null, per_page: 50 } })

describe('createVaulterClient — requests', () => {
  it('lists children with the parent and cursor as query and keeps the page meta', async () => {
    const fetchMock = fetchSequence(() => jsonResponse(page([{ id: '9' }])))
    const client = createVaulterClient({ fetch: fetchMock })

    const result = await client.nodes.children('7', { parent: '20', cursor: 'abc', perPage: 25 })

    expect(result.data).toEqual([{ id: '9' }])
    expect(result.meta.next_cursor).toBeNull()
    const sent = call(fetchMock)
    expect(sent.url).toBe('/api/vaulter/v1/drives/7/nodes?parent=20&cursor=abc&per_page=25')
    expect(sent.method).toBe('GET')
  })

  it('returns no page/total fields: lists are cursor-only', async () => {
    const client = createVaulterClient({ fetch: fetchSequence(() => jsonResponse(page())) })

    const result = await client.nodes.children('7')

    expect(Object.keys(result).sort()).toEqual(['data', 'meta'])
    expect(Object.keys(result.meta).sort()).toEqual(['next_cursor', 'per_page'])
  })

  it('honours baseUrl, extra headers and the CSRF token', async () => {
    const fetchMock = fetchSequence(() => jsonResponse({ data: { id: '7' } }))
    const client = createVaulterClient({
      baseUrl: 'https://host.test/api/vaulter/v1/',
      headers: () => ({ Authorization: 'Bearer t' }),
      csrfToken: 'csrf',
      fetch: fetchMock,
    })

    await client.drives.get('7')

    const sent = call(fetchMock)
    expect(sent.url).toBe('https://host.test/api/vaulter/v1/drives/7')
    expect(sent.headers.Authorization).toBe('Bearer t')
    expect(sent.headers['X-CSRF-TOKEN']).toBe('csrf')
    expect(sent.headers.Accept).toBe('application/json')
  })

  it('creates a folder with a snake_case body', async () => {
    const fetchMock = fetchSequence(() => jsonResponse({ data: { id: '9' } }, 201))
    const client = createVaulterClient({ fetch: fetchMock })

    await client.folders.create('7', { name: 'Docs', parentId: '20' })

    const sent = call(fetchMock)
    expect(sent.url).toBe('/api/vaulter/v1/drives/7/folders')
    expect(JSON.parse(sent.body as string)).toEqual({ name: 'Docs', parent_id: '20' })
    expect(sent.headers['Content-Type']).toBe('application/json')
  })

  it('resolves a 204 to undefined', async () => {
    const client = createVaulterClient({ fetch: fetchSequence(() => emptyResponse()) })

    await expect(client.comments.remove('1', '7', { etag: '"comment.7.0"' })).resolves.toBeUndefined()
  })

  it('maps trash.empty to the purged count', async () => {
    const client = createVaulterClient({
      fetch: fetchSequence(() => jsonResponse({ data: { purged_count: 3 } })),
    })

    await expect(client.trash.empty('7')).resolves.toBe(3)
  })

  it('reads the change cursor and pages the feed', async () => {
    const fetchMock = fetchSequence(
      () => jsonResponse({ data: { cursor: 'c0' } }),
      () => jsonResponse({ data: [], meta: { next_cursor: 'c1', has_more: false } }),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    expect(await client.changes.start('7')).toBe('c0')
    const feed = await client.changes.list('7', { cursor: 'c0', limit: 100 })

    expect(feed.meta).toEqual({ next_cursor: 'c1', has_more: false })
    expect(call(fetchMock, 1).url).toBe('/api/vaulter/v1/drives/7/changes?cursor=c0&limit=100')
  })

  it('sends share access headers for a public share', async () => {
    const fetchMock = fetchSequence(() => jsonResponse({ data: { id: '1' } }))
    const client = createVaulterClient({ fetch: fetchMock })

    await client.publicShares.get('tok', { session: 's1', password: 'pw' })

    const sent = call(fetchMock)
    expect(sent.url).toBe('/api/vaulter/v1/shares/tok')
    expect(sent.headers['X-Share-Session']).toBe('s1')
    expect(sent.headers['X-Share-Password']).toBe('pw')
  })

  it('creates a grant with the subject object and a share with snake_case fields', async () => {
    const fetchMock = fetchSequence(
      () => jsonResponse({ data: { id: 'g1' } }, 201),
      () => jsonResponse({ data: { id: 's1', token: 'raw', url: 'u' } }, 201),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    await client.grants.create('5', { subject: { type: 'user', id: 42 }, role: 'writer', expiresAt: '2027-01-01T00:00:00Z' })
    await client.shares.create('5', { role: 'reader', maxUses: 3 })

    expect(JSON.parse(call(fetchMock, 0).body as string)).toEqual({
      subject: { type: 'user', id: 42 },
      role: 'writer',
      expires_at: '2027-01-01T00:00:00Z',
    })
    expect(JSON.parse(call(fetchMock, 1).body as string)).toEqual({ role: 'reader', max_uses: 3 })
  })

  it('builds download URLs without fetching', () => {
    const fetchMock = fetchSequence()
    const client = createVaulterClient({ fetch: fetchMock })

    expect(client.files.downloadUrl('5')).toBe('/api/vaulter/v1/files/5/download')
    expect(client.files.revisionDownloadUrl('5', 'r2')).toBe('/api/vaulter/v1/files/5/revisions/r2/download')
    expect(client.publicShares.downloadUrl('tok', '9')).toBe('/api/vaulter/v1/shares/tok/download?node=9')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('exports a document with the filename the server sent', async () => {
    const fetchMock = fetchSequence(
      () => new Response('bytes', {
        headers: {
          'Content-Type': 'application/pdf',
          'Content-Disposition': 'attachment; filename="fallback.pdf"; filename*=UTF-8\'\'%D0%BE%D1%82%D1%87%D1%91%D1%82.pdf',
          'X-Vaulter-Api-Version': '1',
        },
      }),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    const exported = await client.documents.export('5', 'pdf', { revision: 'r1' })

    expect(exported.filename).toBe('отчёт.pdf')
    expect(exported.mimeType).toBe('application/pdf')
    expect(await exported.blob.text()).toBe('bytes')
    expect(call(fetchMock).url).toBe('/api/vaulter/v1/documents/5/export/pdf?revision=r1')
  })
})

describe('createVaulterClient — preconditions (If-Match)', () => {
  const node = { data: { id: '5', etag: '"5.2.aaaa"' } }

  it('sends the opaque etag verbatim in If-Match for every node mutation', async () => {
    const fetchMock = fetchSequence(
      () => jsonResponse(node),
      () => jsonResponse(node),
      () => jsonResponse(node),
      () => jsonResponse(node),
      () => jsonResponse(node),
      () => emptyResponse(),
    )
    const client = createVaulterClient({ fetch: fetchMock })
    const etag = '"5.1.W/odd"'

    await client.nodes.update('5', { name: 'New' }, { etag })
    await client.nodes.move('5', '20', { etag, onConflict: 'rename' })
    await client.nodes.trash('5', { etag })
    await client.nodes.restore('5', { etag, destinationId: null })
    await client.nodes.setProperties('5', { namespace: 'acme', set: { a: 1 } }, { etag })
    await client.nodes.purge('5', { etag })

    const sent = [0, 1, 2, 3, 4, 5].map((i) => call(fetchMock, i))
    for (const request of sent) {
      expect(request.headers['If-Match']).toBe(etag)
    }
    expect(sent.map((r) => `${r.method} ${r.url}`)).toEqual([
      'PATCH /api/vaulter/v1/nodes/5',
      'POST /api/vaulter/v1/nodes/5/move',
      'POST /api/vaulter/v1/nodes/5/trash',
      'POST /api/vaulter/v1/nodes/5/restore',
      'PATCH /api/vaulter/v1/nodes/5/properties',
      'DELETE /api/vaulter/v1/nodes/5',
    ])
    expect(JSON.parse(sent[1].body as string)).toEqual({ destination_id: '20', on_conflict: 'rename' })
  })

  it('does not send If-Match on reads and on non-validated mutations', async () => {
    const fetchMock = fetchSequence(() => jsonResponse(node), () => jsonResponse(node, 201))
    const client = createVaulterClient({ fetch: fetchMock })

    await client.nodes.get('5')
    await client.nodes.copy('5', '20', { name: 'Copy' })

    expect(call(fetchMock, 0).headers['If-Match']).toBeUndefined()
    expect(call(fetchMock, 1).headers['If-Match']).toBeUndefined()
  })

  it('takes the document validator from the ETag response header', async () => {
    const fetchMock = fetchSequence(
      () => jsonResponse({ data: { content: { type: 'doc' } } }, 200, { ETag: '"5.1.4"' }),
      () => jsonResponse({ data: { content: { type: 'doc' } } }, 200, { ETag: '"5.1.5"' }),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    const loaded = await client.documents.content('5')
    const saved = await client.documents.update('5', { type: 'doc' }, { etag: loaded.etag! })

    expect(loaded.etag).toBe('"5.1.4"')
    expect(saved.etag).toBe('"5.1.5"')
    const put = call(fetchMock, 1)
    expect(put.method).toBe('PUT')
    expect(put.headers['If-Match']).toBe('"5.1.4"')
    expect(JSON.parse(put.body as string)).toEqual({ content: { type: 'doc' } })
  })

  it('reports a missing ETag header as null instead of inventing one', async () => {
    const client = createVaulterClient({
      fetch: fetchSequence(() => jsonResponse({ data: { content: { type: 'doc' } } })),
    })

    expect((await client.documents.content('5')).etag).toBeNull()
  })

  it('attaches the etag to file revision uploads as multipart', async () => {
    const fetchMock = fetchSequence(() => jsonResponse({ data: { id: 'r2' } }, 201))
    const client = createVaulterClient({ fetch: fetchMock })

    await client.files.addRevision('5', new Blob(['x']), { etag: '"5.1.a"' })

    const sent = call(fetchMock)
    expect(sent.headers['If-Match']).toBe('"5.1.a"')
    expect(sent.body).toBeInstanceOf(FormData)
    expect(sent.headers['Content-Type']).toBeUndefined()
  })
})

describe('createVaulterClient — preconditions on drives, grants, inheritance, shares and comments', () => {
  const node = { data: { id: '5', etag: '"5.2.aaaa"' } }

  it('sends the resource tag verbatim in If-Match for every versioned write', async () => {
    const fetchMock = fetchSequence(
      () => jsonResponse({ data: { id: '7', etag: '"drive.7.1"' } }),
      () => jsonResponse({ data: { id: '7', etag: '"drive.7.2"' } }),
      () => jsonResponse({ data: { id: '7', etag: '"drive.7.3"' } }),
      () => emptyResponse(),
      () => jsonResponse(node),
      () => jsonResponse(node),
      () => jsonResponse({ data: { id: 's1', etag: '"share.s1.1"' } }),
      () => jsonResponse({ data: { id: 's1', token: 'raw', url: 'u', etag: '"share.s1.2"' } }),
      () => emptyResponse(),
      () => jsonResponse({ data: { id: 'c1', etag: '"comment.c1.1"' } }),
      () => emptyResponse(),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    await client.drives.archive('7', { etag: '"drive.7.0"' })
    await client.drives.unarchive('7', { etag: '"drive.7.1"' })
    await client.drives.delete('7', { etag: '"drive.7.2"' })
    await client.grants.revoke('5', 'g1', { etag: '"node-access.5.3"' })
    await client.access.restrictInheritance('5', { etag: '"node-access.5.4"', mode: 'copy_current' })
    await client.access.restoreInheritance('5', { etag: '"node-access.5.5"' })
    await client.shares.update('5', 's1', { maxUses: 2 }, { etag: '"share.s1.0"' })
    await client.shares.rotate('5', 's1', { etag: '"share.s1.1"' })
    await client.shares.revoke('5', 's1', { etag: '"share.s1.2"' })
    await client.comments.resolve('5', 'c1', { etag: '"comment.c1.0"' })
    await client.comments.remove('5', 'c1', { etag: '"comment.c1.1"' })

    const sent = Array.from({ length: 11 }, (_, i) => call(fetchMock, i))
    expect(sent.map((r) => `${r.method} ${r.url} ${r.headers['If-Match']}`)).toEqual([
      'POST /api/vaulter/v1/drives/7/archive "drive.7.0"',
      'POST /api/vaulter/v1/drives/7/unarchive "drive.7.1"',
      'DELETE /api/vaulter/v1/drives/7 "drive.7.2"',
      'DELETE /api/vaulter/v1/nodes/5/grants/g1 "node-access.5.3"',
      'POST /api/vaulter/v1/nodes/5/inheritance/restrict "node-access.5.4"',
      'POST /api/vaulter/v1/nodes/5/inheritance/restore "node-access.5.5"',
      'PATCH /api/vaulter/v1/nodes/5/shares/s1 "share.s1.0"',
      'POST /api/vaulter/v1/nodes/5/shares/s1/rotate "share.s1.1"',
      'DELETE /api/vaulter/v1/nodes/5/shares/s1 "share.s1.2"',
      'POST /api/vaulter/v1/nodes/5/comments/c1/resolve "comment.c1.0"',
      'DELETE /api/vaulter/v1/nodes/5/comments/c1 "comment.c1.1"',
    ])
    expect(JSON.parse(sent[4].body as string)).toEqual({ mode: 'copy_current' })
    expect(JSON.parse(sent[6].body as string)).toEqual({ max_uses: 2 })
  })

  it('lists the grants with the access tag of the node', async () => {
    const fetchMock = fetchSequence(() => jsonResponse({ etag: '"node-access.5.3"', data: [{ id: 'g1' }] }))
    const client = createVaulterClient({ fetch: fetchMock })

    expect(await client.grants.listTagged('5')).toEqual({ etag: '"node-access.5.3"', data: [{ id: 'g1' }] })
    expect(call(fetchMock).headers['If-Match']).toBeUndefined()
  })

  it('rejects a stale tag with a 412 stale_write VaulterApiError', async () => {
    const client = createVaulterClient({
      fetch: fetchSequence(() => problemResponse({ code: 'stale_write', status: 412, title: 'Stale write', retryable: 'after_refetch' }, 412)),
    })

    const error = await client.shares.revoke('5', 's1', { etag: '"share.s1.0"' }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(VaulterApiError)
    expect(error).toMatchObject({ status: 412, code: 'stale_write', isConflict: true, retryable: 'after_refetch' })
  })
})

describe('createVaulterClient — API version guard', () => {
  it('rejects a server speaking another major version, and every later call, without fetching again', async () => {
    const fetchMock = fetchSequence(() => jsonResponse({ data: {} }, 200, { 'X-Vaulter-Api-Version': '2' }))
    const client = createVaulterClient({ fetch: fetchMock })

    const first = await client.drives.get('7').catch((error: unknown) => error)
    const second = await client.nodes.get('5').catch((error: unknown) => error)

    expect(first).toBeInstanceOf(VaulterApiError)
    expect((first as VaulterApiError).code).toBe('api_version_mismatch')
    expect((second as VaulterApiError).code).toBe('api_version_mismatch')
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('accepts the supported major once and stops checking', async () => {
    const fetchMock = fetchSequence(
      () => jsonResponse({ data: {} }),
      () => jsonResponse({ data: {} }, 200, { 'X-Vaulter-Api-Version': '2' }),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    await client.drives.get('7')
    await expect(client.drives.get('7')).resolves.toEqual({})
  })

  it('does not decide on a response that names no version', async () => {
    const fetchMock = fetchSequence(
      () => new Response('{"data":{}}', { headers: { 'Content-Type': 'application/json' } }),
      () => jsonResponse({ data: {} }, 200, { 'X-Vaulter-Api-Version': '2' }),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    await client.drives.get('7')
    await expect(client.drives.get('7')).rejects.toMatchObject({ code: 'api_version_mismatch' })
  })
})


it('preserves a protocol-neutral session and sends the supported schema without importing an adapter', async () => {
  const session = {
    protocol: 'acme-ot', version: 'acme-ot/1', endpoint: null, token: 'opaque', expires_at: 1300,
    mode: 'read', node_id: '42', generation: 7, schema_version: 1, options: { channel: 'board' },
  }
  const fetchMock = fetchSequence(() => jsonResponse({ data: session }, 201))
  const client = createVaulterClient({ fetch: fetchMock })
  expect(await client.documents.collaborationSession('42', { schemaVersion: 0 })).toEqual(session)
  expect(call(fetchMock).url).toBe('/api/vaulter/v1/documents/42/collaboration/session')
  expect(JSON.parse(call(fetchMock).body as string)).toEqual({ schema_version: 0 })
})

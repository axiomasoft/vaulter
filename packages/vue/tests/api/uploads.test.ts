import { describe, expect, it, vi } from 'vitest'
import { createVaulterClient } from '../../src/api/client'
import { API_VERSION, call, emptyResponse, fetchSequence, jsonResponse } from '../support/http'

const session = (overrides: Record<string, unknown> = {}) => ({
  data: {
    upload_id: 'u1',
    drive_id: '7',
    protocol: 'tus',
    status: 'initiated',
    received_bytes: 0,
    total_size_bytes: 10,
    chunk_size: 4,
    mime_type: null,
    node_id: null,
    target_parent_id: null,
    target_name: null,
    expires_at: '2027-01-01T00:00:00Z',
    ...overrides,
  },
})

const createdNode = { data: { id: '99', name: 'big.bin' } }

describe('resumable uploads — tus', () => {
  it('creates the session, resumes from the server offset and PATCHes until the node is created', async () => {
    const fetchMock = fetchSequence(
      () => jsonResponse(session(), 201),
      () => emptyResponse(200, { 'Upload-Offset': '4' }),
      () => emptyResponse(204, { 'Upload-Offset': '8' }),
      () => jsonResponse(createdNode, 201, { 'Upload-Offset': '10' }),
    )
    const client = createVaulterClient({ fetch: fetchMock })
    const progress = vi.fn()

    const node = await client.uploads
      .start('7', new Blob(['0123456789']), { parentId: '20', name: 'big.bin', onProgress: progress })
      .start()

    expect(node.id).toBe('99')
    const create = call(fetchMock, 0)
    expect(create.url).toBe('/api/vaulter/v1/drives/7/uploads')
    expect(JSON.parse(create.body as string)).toMatchObject({
      protocol: 'tus', parent_id: '20', name: 'big.bin', total_size_bytes: 10,
    })

    expect(call(fetchMock, 1).method).toBe('HEAD')
    expect(call(fetchMock, 1).url).toBe('/api/vaulter/v1/uploads/u1')
    const first = call(fetchMock, 2)
    expect(first.method).toBe('PATCH')
    expect(first.headers['Upload-Offset']).toBe('4')
    expect(first.headers['Tus-Resumable']).toBe('1.0.0')
    expect(first.headers['Content-Type']).toBe('application/offset+octet-stream')
    expect(call(fetchMock, 3).headers['Upload-Offset']).toBe('8')
    expect(progress).toHaveBeenLastCalledWith(10, 10)
  })

  it('resumes a 409 from the Upload-Offset header instead of failing the upload', async () => {
    const fetchMock = fetchSequence(
      () => jsonResponse(session(), 201),
      () => emptyResponse(200, { 'Upload-Offset': '0' }),
      () => new Response(JSON.stringify({ code: 'upload_offset_mismatch', title: 'Conflict' }), {
        status: 409,
        headers: { 'Content-Type': 'application/problem+json', ...API_VERSION, 'Upload-Offset': '4' },
      }),
      () => emptyResponse(204, { 'Upload-Offset': '8' }),
      () => jsonResponse(createdNode, 201, { 'Upload-Offset': '10' }),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    const node = await client.uploads.start('7', new Blob(['0123456789'])).start()

    expect(node.id).toBe('99')
    expect(call(fetchMock, 3).headers['Upload-Offset']).toBe('4')
  })

  it('sends If-Match when replacing the content of an existing file', async () => {
    const fetchMock = fetchSequence(
      () => jsonResponse(session({ total_size_bytes: 0, node_id: '5' }), 201),
      () => emptyResponse(200, { 'Upload-Offset': '0' }),
      () => jsonResponse(createdNode, 201, { 'Upload-Offset': '0' }),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    await client.uploads.start('7', new Blob([]), { replace: { nodeId: '5', etag: '"5.3.ab"' } }).start()

    const create = call(fetchMock, 0)
    expect(create.headers['If-Match']).toBe('"5.3.ab"')
    expect(JSON.parse(create.body as string).node_id).toBe('5')
  })

  it('aborts the session on the server and stops the loop', async () => {
    let release!: () => void
    const gate = new Promise<void>((resolve) => { release = resolve })
    const fetchMock = vi.fn<typeof fetch>()
      .mockImplementationOnce(() => Promise.resolve(jsonResponse(session(), 201)))
      .mockImplementationOnce(() => Promise.resolve(emptyResponse(200, { 'Upload-Offset': '0' })))
      .mockImplementationOnce(async () => {
        await gate
        return emptyResponse(204, { 'Upload-Offset': '4' })
      })
      .mockImplementationOnce(() => Promise.resolve(emptyResponse(204)))
    const client = createVaulterClient({ fetch: fetchMock })
    const upload = client.uploads.start('7', new Blob(['0123456789']))

    const running = upload.start().catch((error: unknown) => error)
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3))
    const aborting = upload.abort()
    release()
    await aborting

    expect((await running as Error).message).toMatch(/aborted/)
    const abort = call(fetchMock, 3)
    expect(abort.method).toBe('DELETE')
    expect(abort.url).toBe('/api/vaulter/v1/uploads/u1')
  })
})

describe('resumable uploads — s3-multipart', () => {
  it('uploads numbered parts and completes the session', async () => {
    const fetchMock = fetchSequence(
      () => jsonResponse(session({ protocol: 's3-multipart' }), 201),
      () => jsonResponse({ part_no: 1, size: 4, etag: 'e1' }, 201),
      () => jsonResponse({ part_no: 2, size: 4, etag: 'e2' }, 201),
      () => jsonResponse({ part_no: 3, size: 2, etag: 'e3' }, 201),
      () => jsonResponse(createdNode, 201),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    const node = await client.uploads.start('7', new Blob(['0123456789']), { protocol: 's3' }).start()

    expect(node.id).toBe('99')
    expect(JSON.parse(call(fetchMock, 0).body as string).protocol).toBe('s3-multipart')
    expect([1, 2, 3].map((i) => call(fetchMock, i).url)).toEqual([
      '/api/vaulter/v1/uploads/u1/parts?part_no=1',
      '/api/vaulter/v1/uploads/u1/parts?part_no=2',
      '/api/vaulter/v1/uploads/u1/parts?part_no=3',
    ])
    expect(call(fetchMock, 1).headers['Content-Type']).toBe('application/octet-stream')
    expect(call(fetchMock, 4).url).toBe('/api/vaulter/v1/uploads/u1/complete')
  })
})

describe('resumable uploads — abort during the create request', () => {
  it('deletes the tus session that was created after abort() was called, and never uploads', async () => {
    let release!: () => void
    const gate = new Promise<void>((resolve) => { release = resolve })
    const fetchMock = vi.fn<typeof fetch>()
      .mockImplementationOnce(async () => {
        await gate
        return jsonResponse(session(), 201)
      })
      .mockImplementationOnce(() => Promise.resolve(emptyResponse(204)))
    const client = createVaulterClient({ fetch: fetchMock })
    const upload = client.uploads.start('7', new Blob(['0123456789']))

    const running = upload.start().catch((error: unknown) => error)
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    await upload.abort()
    release()

    expect((await running as Error).message).toMatch(/aborted/)
    expect(fetchMock).toHaveBeenCalledTimes(2)
    const abort = call(fetchMock, 1)
    expect(abort.method).toBe('DELETE')
    expect(abort.url).toBe('/api/vaulter/v1/uploads/u1')
  })

  it('refuses an empty file over S3 multipart before creating a session the server cannot complete', async () => {
    const fetchMock = fetchSequence()
    const client = createVaulterClient({ fetch: fetchMock })

    await expect(client.uploads.start('7', new Blob([]), { protocol: 's3' }).start()).rejects.toThrow(/empty file/)
    expect(fetchMock).not.toHaveBeenCalled()
  })
})

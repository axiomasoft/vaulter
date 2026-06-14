import { describe, expect, it, vi } from 'vitest'
import { createDocVaultClient, DocVaultApiError } from '../src/client'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('createDocVaultClient', () => {
  it('lists documents and unwraps the data envelope', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: [{ id: 1, title: 'A' }] }))
    const client = createDocVaultClient({ baseUrl: '/api/docvault', fetch: fetchMock })

    const documents = await client.documents.list({ workspace_type: 'App\\Project', workspace_id: 1 })

    expect(documents).toHaveLength(1)
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toContain('/api/docvault/documents?')
    expect(url).toContain('workspace_id=1')
    expect(init.method).toBe('GET')
  })

  it('sends a JSON body and CSRF header on create', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: { id: 2, title: 'X' } }, 201))
    const client = createDocVaultClient({ fetch: fetchMock, csrfToken: 'tok' })

    await client.documents.create({
      workspace_type: 'App\\Project',
      workspace_id: 1,
      type: 'richtext',
      title: 'X',
      content: { type: 'doc', content: [] },
    })

    const [, init] = fetchMock.mock.calls[0]
    expect(init.method).toBe('POST')
    expect(init.headers['X-CSRF-TOKEN']).toBe('tok')
    expect(JSON.parse(init.body).title).toBe('X')
  })

  it('resolves to undefined on a 204 response', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }))
    const client = createDocVaultClient({ fetch: fetchMock })

    await expect(client.documents.remove(5)).resolves.toBeUndefined()
  })

  it('throws DocVaultApiError with validation details on failure', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ message: 'Not allowed', errors: { title: ['required'] } }, 422),
    )
    const client = createDocVaultClient({ fetch: fetchMock })

    await expect(client.documents.get(1)).rejects.toMatchObject({
      status: 422,
      message: 'Not allowed',
      errors: { title: ['required'] },
    })
    await expect(client.documents.get(1)).rejects.toBeInstanceOf(DocVaultApiError)
  })

  it('creates and resolves comments', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ data: { id: 7, body: 'Hi' } }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: { id: 7, resolved_at: '2026-06-14T00:00:00Z' } }))
    const client = createDocVaultClient({ fetch: fetchMock })

    const created = await client.comments.create(1, { body: 'Hi' })
    expect(created.id).toBe(7)

    const resolved = await client.comments.resolve(1, 7)
    expect(resolved.resolved_at).not.toBeNull()

    expect(fetchMock.mock.calls[1][0]).toContain('/documents/1/comments/7/resolve')
  })

  it('creates a share and resolves a shared document by token', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ data: { id: 1, token: 'abc', permission: 'view' } }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: { id: 5, title: 'Shared', abilities: { view: true } } }))
    const client = createDocVaultClient({ fetch: fetchMock })

    const share = await client.shares.create(5, { permission: 'view' })
    expect(share.token).toBe('abc')

    const shared = await client.shares.resolve('abc')
    expect(shared.title).toBe('Shared')
    expect(fetchMock.mock.calls[1][0]).toContain('/shares/abc')
  })

  it('searches via the q query and lists the trash', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ data: [{ id: 1 }] }))
      .mockResolvedValueOnce(jsonResponse({ data: [{ id: 2 }] }))
    const client = createDocVaultClient({ fetch: fetchMock })

    await client.documents.list({ workspace_type: 'w', workspace_id: 1, q: 'roadmap' })
    expect(fetchMock.mock.calls[0][0]).toContain('q=roadmap')

    const trashed = await client.documents.trashed({ workspace_type: 'w', workspace_id: 1 })
    expect(trashed).toHaveLength(1)
    expect(fetchMock.mock.calls[1][0]).toContain('/documents/trash?')
  })

  it('force-deletes a document', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }))
    const client = createDocVaultClient({ fetch: fetchMock })

    await expect(client.documents.forceDelete(7)).resolves.toBeUndefined()
    expect(fetchMock.mock.calls[0][0]).toContain('/documents/7/force')
  })

  it('moves and duplicates a document', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ data: { id: 1, folder_id: 9 } }))
      .mockResolvedValueOnce(jsonResponse({ data: { id: 2, title: 'Copy of X' } }, 201))
    const client = createDocVaultClient({ fetch: fetchMock })

    const moved = await client.documents.move(1, 9)
    expect(moved.folder_id).toBe(9)
    const [, moveInit] = fetchMock.mock.calls[0]
    expect(moveInit.method).toBe('PATCH')
    expect(JSON.parse(moveInit.body).folder_id).toBe(9)

    const copy = await client.documents.duplicate(1)
    expect(copy.title).toBe('Copy of X')
    expect(fetchMock.mock.calls[1][0]).toContain('/documents/1/duplicate')
  })

  it('performs a bulk action', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ data: { action: 'delete', processed: [1, 2], skipped: [] } }),
    )
    const client = createDocVaultClient({ fetch: fetchMock })

    const result = await client.documents.bulk({ action: 'delete', ids: [1, 2] })

    expect(result.processed).toHaveLength(2)
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toContain('/documents/bulk')
    expect(JSON.parse(init.body).action).toBe('delete')
  })

  it('exports a document, passing the format query', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ data: { format: 'markdown', content: '# Hi', filename: 'hi.md' } }),
    )
    const client = createDocVaultClient({ fetch: fetchMock })

    const result = await client.documents.export(3, 'markdown')

    expect(result.content).toBe('# Hi')
    expect(fetchMock.mock.calls[0][0]).toContain('/documents/3/export?format=markdown')
  })

  it('requests a collaboration token without a data envelope', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ driver: 'reverb', channel: 'docvault.documents.1', document_id: 1, token: '' }),
    )
    const client = createDocVaultClient({ fetch: fetchMock })

    const connection = await client.collaboration.token(1)

    expect(connection.driver).toBe('reverb')
  })
})

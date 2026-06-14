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

  it('requests a collaboration token without a data envelope', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ driver: 'reverb', channel: 'docvault.documents.1', document_id: 1, token: '' }),
    )
    const client = createDocVaultClient({ fetch: fetchMock })

    const connection = await client.collaboration.token(1)

    expect(connection.driver).toBe('reverb')
  })
})

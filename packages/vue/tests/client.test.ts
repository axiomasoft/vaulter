import { describe, expect, it, vi } from 'vitest'
import { createVaulterClient, VaulterApiError } from '../src/client'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('createVaulterClient — node comments (ADR-0014)', () => {
  it('lists a node\'s comments and unwraps the data envelope', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: [{ id: 7, body: 'Hi' }] }))
    const client = createVaulterClient({ baseUrl: '/api/vaulter', fetch: fetchMock })

    const comments = await client.comments.list(1)

    expect(comments).toHaveLength(1)
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/vaulter/nodes/1/comments')
    expect(init.method).toBe('GET')
  })

  it('creates and resolves a node comment, sending CSRF + JSON body', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ data: { id: 7, body: 'Hi' } }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: { id: 7, resolved_at: '2026-06-14T00:00:00Z' } }))
    const client = createVaulterClient({ fetch: fetchMock, csrfToken: 'tok' })

    const created = await client.comments.create(1, { body: 'Hi' })
    expect(created.id).toBe(7)
    const [createUrl, createInit] = fetchMock.mock.calls[0]
    expect(createUrl).toBe('/api/vaulter/nodes/1/comments')
    expect(createInit.method).toBe('POST')
    expect(createInit.headers['X-CSRF-TOKEN']).toBe('tok')
    expect(JSON.parse(createInit.body).body).toBe('Hi')

    const resolved = await client.comments.resolve(1, 7)
    expect(resolved.resolved_at).not.toBeNull()
    expect(fetchMock.mock.calls[1][0]).toBe('/api/vaulter/nodes/1/comments/7/resolve')
  })

  it('removes a node comment and resolves to undefined on a 204', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }))
    const client = createVaulterClient({ fetch: fetchMock })

    await expect(client.comments.remove(1, 7)).resolves.toBeUndefined()
    expect(fetchMock.mock.calls[0][0]).toBe('/api/vaulter/nodes/1/comments/7')
  })

  it('throws VaulterApiError with validation details on failure', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ message: 'Not allowed', errors: { body: ['required'] } }, 422),
    )
    const client = createVaulterClient({ fetch: fetchMock })

    await expect(client.comments.create(1, { body: '' })).rejects.toMatchObject({
      status: 422,
      message: 'Not allowed',
      errors: { body: ['required'] },
    })
    await expect(client.comments.create(1, { body: '' })).rejects.toBeInstanceOf(VaulterApiError)
  })
})

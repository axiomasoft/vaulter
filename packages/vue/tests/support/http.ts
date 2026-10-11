import { vi, type Mock } from 'vitest'

export const API_VERSION = { 'X-Vaulter-Api-Version': '1' }

export function jsonResponse(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...API_VERSION, ...headers },
  })
}

export function problemResponse(body: Record<string, unknown>, status: number): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/problem+json', ...API_VERSION, 'X-Request-Id': 'req-1' },
  })
}

export function emptyResponse(status = 204, headers: Record<string, string> = {}): Response {
  return new Response(null, { status, headers: { ...API_VERSION, ...headers } })
}

/** A fetch mock answering with the given factories in order (a fresh Response each call). */
export function fetchSequence(...answers: Array<() => Response>): Mock<typeof fetch> {
  const mock = vi.fn<typeof fetch>()
  for (const answer of answers) {
    mock.mockImplementationOnce(() => Promise.resolve(answer()))
  }
  return mock
}

export interface RecordedCall {
  url: string
  method: string
  headers: Record<string, string>
  body: unknown
}

export function call(mock: Mock<typeof fetch>, index = 0): RecordedCall {
  const [url, init] = mock.mock.calls[index] as [string, RequestInit]
  return {
    url,
    method: init.method ?? 'GET',
    headers: (init.headers ?? {}) as Record<string, string>,
    body: init.body,
  }
}

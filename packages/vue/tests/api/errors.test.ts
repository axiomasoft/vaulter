import { describe, expect, it } from 'vitest'
import { createVaulterClient } from '../../src/api/client'
import { VaulterApiError, parseRetryAfter } from '../../src/api/errors'
import { fetchSequence, problemResponse } from '../support/http'

describe('VaulterApiError.fromResponse', () => {
  it('parses a problem+json body into code, detail, requestId, meta and errors', async () => {
    const response = problemResponse({
      type: 'https://example.test/errors#validation_failed',
      title: 'Validation failed',
      status: 422,
      code: 'validation_failed',
      detail: 'The name field is required.',
      request_id: 'req-9',
      errors: { name: ['required'] },
      meta: { field: 'name' },
    }, 422)

    const error = await VaulterApiError.fromResponse(response)

    expect(error).toBeInstanceOf(Error)
    expect(error).toMatchObject({
      name: 'VaulterApiError',
      status: 422,
      code: 'validation_failed',
      title: 'Validation failed',
      detail: 'The name field is required.',
      requestId: 'req-9',
      meta: { field: 'name' },
      errors: { name: ['required'] },
    })
    expect(error.message).toBe('The name field is required.')
  })

  it('maps an HTML proxy error to http_<status> without throwing a parse error', async () => {
    const response = new Response('<html>Bad gateway</html>', {
      status: 502,
      headers: { 'Content-Type': 'text/html', 'X-Request-Id': 'edge-1' },
    })

    const error = await VaulterApiError.fromResponse(response)

    expect(error.code).toBe('http_502')
    expect(error.status).toBe(502)
    expect(error.requestId).toBe('edge-1')
    expect(error.meta).toEqual({})
    expect(error.errors).toBeNull()
  })

  it('maps a JSON content type with an unparsable or foreign body to http_<status>', async () => {
    const broken = new Response('{oops', { status: 500, headers: { 'Content-Type': 'application/json' } })
    const foreign = new Response('{"message":"legacy"}', { status: 500, headers: { 'Content-Type': 'application/json' } })

    expect((await VaulterApiError.fromResponse(broken)).code).toBe('http_500')
    expect((await VaulterApiError.fromResponse(foreign)).code).toBe('http_500')
  })

  it('maps an empty body (504) to http_504', async () => {
    const error = await VaulterApiError.fromResponse(new Response(null, { status: 504 }))

    expect(error.code).toBe('http_504')
    expect(error.requestId).toBeNull()
  })

  it('flags a 412 as a conflict', async () => {
    const error = await VaulterApiError.fromResponse(problemResponse({
      type: 't', title: 'Node changed', status: 412, code: 'node_sequence_mismatch', request_id: 'r',
    }, 412))

    expect(error.isConflict).toBe(true)
  })
})

describe('Retry-After', () => {
  const problem = (status: number, code: string, headers: Record<string, string> = {}) => new Response(
    JSON.stringify({ type: 't', title: 'x', status, code, request_id: 'r', retryable: 'with_backoff' }),
    { status, headers: { 'Content-Type': 'application/problem+json', ...headers } },
  )

  it('decodes delta-seconds into retryAfterSeconds', async () => {
    const error = await VaulterApiError.fromResponse(problem(409, 'operation_in_progress', { 'Retry-After': '7' }))

    expect(error.retryAfterSeconds).toBe(7)
    expect(error.retryable).toBe('with_backoff')
  })

  it('is null without the header', async () => {
    const error = await VaulterApiError.fromResponse(problem(412, 'stale_write'))

    expect(error.retryAfterSeconds).toBeNull()
    expect(error.code).toBe('stale_write')
  })

  it('reads the header of a non-problem response too', async () => {
    const error = await VaulterApiError.fromResponse(new Response('<html>busy</html>', {
      status: 503,
      headers: { 'Content-Type': 'text/html', 'Retry-After': '30' },
    }))

    expect(error.code).toBe('http_503')
    expect(error.retryAfterSeconds).toBe(30)
  })

  it('turns an HTTP-date into the seconds left, never negative, and ignores garbage', () => {
    const now = Date.parse('2026-10-18T12:00:00Z')

    expect(parseRetryAfter('Sun, 18 Oct 2026 12:00:45 GMT', now)).toBe(45)
    expect(parseRetryAfter('Sun, 18 Oct 2026 11:59:00 GMT', now)).toBe(0)
    expect(parseRetryAfter('0', now)).toBe(0)
    expect(parseRetryAfter('soon', now)).toBeNull()
    expect(parseRetryAfter('', now)).toBeNull()
    expect(parseRetryAfter(null, now)).toBeNull()
  })
})

describe('client error surface', () => {
  it('throws VaulterApiError with the problem code from a failed call', async () => {
    const client = createVaulterClient({
      fetch: fetchSequence(() => problemResponse({
        type: 't', title: 'Not found', status: 404, code: 'node_not_found', request_id: 'r1',
      }, 404)),
    })

    await expect(client.nodes.get('404')).rejects.toMatchObject({ status: 404, code: 'node_not_found', requestId: 'r1' })
  })
})

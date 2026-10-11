import { describe, expect, it } from 'vitest'
import { createVaulterClient } from '../../src/api/client'
import { VaulterApiError } from '../../src/api/errors'
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

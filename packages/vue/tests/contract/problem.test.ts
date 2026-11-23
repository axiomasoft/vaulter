import { describe, expect, it } from 'vitest'
import { createVaulterClient } from '../../src/api/client'
import { VaulterApiError } from '../../src/api/errors'
import type { IfMatch, Problem } from '../../src/api/types'
import type { Wide } from '../support/wide'
import { fetchSequence, problemResponse } from '../support/http'
import problem412 from '../../../../openapi/examples/problem-412.json'
import problem428 from '../../../../openapi/examples/problem-428.json'

const problem412Body: Wide<Problem> = problem412.body
const problem428Body: Wide<Problem> = problem428.body

describe('problem+json examples', () => {
  it('412 becomes node_sequence_mismatch with the request id and the node in meta', async () => {
    const client = createVaulterClient({
      fetch: fetchSequence(() => problemResponse(problem412Body, 412)),
    })

    const error = await client.nodes.update('26', { name: 'x' }, { etag: '"26.0.old"' })
      .catch((caught: unknown) => caught) as VaulterApiError

    expect(error).toBeInstanceOf(VaulterApiError)
    expect(error.code).toBe('node_sequence_mismatch')
    expect(error.requestId).toBe(problem412.body.request_id)
    expect(error.detail).toBe(problem412.body.detail)
    expect(error.meta).toEqual({ node_id: '26' })
    expect(error.isConflict).toBe(true)
  })

  it('428 becomes precondition_required naming the missing header', async () => {
    const client = createVaulterClient({
      fetch: fetchSequence(() => problemResponse(problem428Body, 428)),
    })

    const error = await client.nodes.trash('26', {} as IfMatch).catch((caught: unknown) => caught) as VaulterApiError

    expect(error).toBeInstanceOf(VaulterApiError)
    expect(error.code).toBe('precondition_required')
    expect(error.status).toBe(428)
    expect(error.meta).toEqual({ header: 'If-Match', reason: 'missing' })
    expect(error.isConflict).toBe(false)
  })

  it('422 keeps the field errors map', async () => {
    const client = createVaulterClient({
      fetch: fetchSequence(() => problemResponse({
        type: 't',
        title: 'Validation failed',
        status: 422,
        code: 'validation_failed',
        detail: null,
        request_id: 'r-422',
        errors: { name: ['The name field is required.'] },
        meta: {},
      }, 422)),
    })

    const error = await client.folders.create('7', { name: '' }).catch((caught: unknown) => caught) as VaulterApiError

    expect(error.code).toBe('validation_failed')
    expect(error.errors).toEqual({ name: ['The name field is required.'] })
    expect(error.detail).toBeNull()
  })
})

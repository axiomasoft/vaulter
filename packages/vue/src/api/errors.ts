import type { Problem } from './types'

/**
 * Codes the client itself acts on. The server's `code` is an open string (new codes
 * are additive in v1), so the union keeps `string` open for everything else.
 */
export type VaulterErrorCode =
  | 'api_version_mismatch'
  | 'node_sequence_mismatch'
  | 'document_state_mismatch'
  | 'precondition_required'
  | 'validation_failed'
  | 'invalid_node_name'
  | 'permission_denied'
  | 'node_not_found'
  | 'idempotency_key_reused'
  | 'operation_in_progress'
  | (string & {})

/**
 * Whether the server says the request may be repeated (the `retryable` member of the problem):
 * never as is, after refetching the resource, or later with backoff (honouring `Retry-After`).
 * A body without the member decodes as `never`. The client does not retry on its own.
 */
export type VaulterRetryable = 'never' | 'after_refetch' | 'with_backoff'

export interface VaulterApiErrorInit {
  status: number
  code: VaulterErrorCode
  title?: string
  detail?: string | null
  retryable?: VaulterRetryable
  requestId?: string | null
  meta?: Record<string, unknown>
  errors?: Record<string, string[]> | null
  response?: Response
}

/** Error thrown for every non-2xx Vaulter API response (`application/problem+json`, RFC 9457). */
export class VaulterApiError extends Error {
  readonly status: number
  readonly code: VaulterErrorCode
  readonly title: string
  readonly detail: string | null
  readonly retryable: VaulterRetryable
  readonly requestId: string | null
  readonly meta: Record<string, unknown>
  readonly errors: Record<string, string[]> | null
  readonly response?: Response

  constructor(init: VaulterApiErrorInit) {
    const title = init.title ?? `Vaulter API error (${init.status})`
    super(init.detail ?? title)
    this.name = 'VaulterApiError'
    this.status = init.status
    this.code = init.code
    this.title = title
    this.detail = init.detail ?? null
    this.retryable = init.retryable ?? 'never'
    this.requestId = init.requestId ?? null
    this.meta = init.meta ?? {}
    this.errors = init.errors ?? null
    this.response = init.response
  }

  /** The `If-Match` precondition failed: somebody changed the resource since it was read. */
  get isConflict(): boolean {
    return this.status === 412
  }

  /**
   * Parse a failed response. A body that is not a problem document (an HTML 502 from a
   * proxy, an empty 504) never throws a parse error: it becomes `http_<status>`.
   */
  static async fromResponse(response: Response): Promise<VaulterApiError> {
    const fallback = {
      status: response.status,
      code: `http_${response.status}`,
      requestId: response.headers.get('X-Request-Id'),
      response,
    }

    const contentType = response.headers.get('Content-Type') ?? ''
    if (!/json/i.test(contentType)) {
      return new VaulterApiError(fallback)
    }

    let body: Partial<Problem> | null = null
    try {
      body = (await response.json()) as Partial<Problem> | null
    } catch {
      return new VaulterApiError(fallback)
    }

    if (body === null || typeof body !== 'object' || typeof body.code !== 'string') {
      return new VaulterApiError(fallback)
    }

    return new VaulterApiError({
      status: typeof body.status === 'number' ? body.status : response.status,
      code: body.code,
      title: typeof body.title === 'string' ? body.title : undefined,
      detail: typeof body.detail === 'string' ? body.detail : null,
      retryable: isRetryable(body.retryable) ? body.retryable : 'never',
      requestId: typeof body.request_id === 'string' ? body.request_id : fallback.requestId,
      meta: isRecord(body.meta) ? body.meta : {},
      errors: isRecord(body.errors) ? (body.errors as Record<string, string[]>) : null,
      response,
    })
  }

  /** The client refused a server speaking another major API version. */
  static apiVersionMismatch(received: string, response?: Response): VaulterApiError {
    return new VaulterApiError({
      status: response?.status ?? 0,
      code: 'api_version_mismatch',
      title: 'Unsupported API version',
      detail: `This client speaks Vaulter REST API v1, the server answered X-Vaulter-Api-Version: ${received}.`,
      requestId: response?.headers.get('X-Request-Id') ?? null,
      meta: { received },
      response,
    })
  }
}

function isRetryable(value: unknown): value is VaulterRetryable {
  return value === 'never' || value === 'after_refetch' || value === 'with_backoff'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

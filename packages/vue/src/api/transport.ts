import { VaulterApiError } from './errors'

export interface VaulterClientOptions {
  /** Base URL of the Vaulter REST API, version segment included (default: `/api/vaulter/v1`). */
  baseUrl?: string
  /** Extra request headers, or a getter (e.g. for a rotating bearer token). */
  headers?: Record<string, string> | (() => Record<string, string>)
  /** CSRF token sent as `X-CSRF-TOKEN`. */
  csrfToken?: string
  /** Custom fetch implementation (defaults to the global `fetch`). */
  fetch?: typeof fetch
}

export interface RequestInitLike {
  /** A JSON body, serialised by the transport. */
  json?: unknown
  /** A raw body sent as-is (FormData, a Blob chunk): no JSON, no Content-Type default. */
  body?: BodyInit | null
  query?: object
  headers?: Record<string, string>
  /** The opaque ETag of the representation being changed; sent as `If-Match`. */
  etag?: string
  accept?: string
}

/** The one place that talks to `fetch`: headers, version guard, problem+json parsing. */
export interface Transport {
  /** A request that returns the raw `Response` (2xx only). */
  raw(method: string, path: string, init?: RequestInitLike): Promise<Response>
  /** A JSON request; `undefined` for an empty (204) body. */
  json<T>(method: string, path: string, init?: RequestInitLike): Promise<T>
  /** The base-prefixed URL of a path — for `<a href>` / `<img src>`. */
  url(path: string): string
}

const SUPPORTED_MAJOR = 1

export function toQueryString(query?: object): string {
  if (!query) {
    return ''
  }

  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null) {
      params.append(key, String(value))
    }
  }

  const serialized = params.toString()

  return serialized ? `?${serialized}` : ''
}

export function createTransport(options: VaulterClientOptions = {}): Transport {
  const baseUrl = (options.baseUrl ?? '/api/vaulter/v1').replace(/\/+$/, '')
  const fetchImpl = options.fetch ?? globalThis.fetch.bind(globalThis)

  // The first response that names an API version decides, for this and every later call.
  let versionChecked = false
  let versionMismatch: string | null = null

  function defaultHeaders(): Record<string, string> {
    const provided = typeof options.headers === 'function' ? options.headers() : options.headers ?? {}
    const headers: Record<string, string> = { ...provided }

    if (options.csrfToken) {
      headers['X-CSRF-TOKEN'] = options.csrfToken
    }

    return headers
  }

  function checkVersion(response: Response): void {
    if (versionChecked) {
      return
    }

    const header = response.headers.get('X-Vaulter-Api-Version')
    if (header === null) {
      // A proxy error page or a non-Vaulter reply says nothing about the API version.
      return
    }

    versionChecked = true
    if (Number.parseInt(header, 10) !== SUPPORTED_MAJOR) {
      versionMismatch = header
      throw VaulterApiError.apiVersionMismatch(header, response)
    }
  }

  async function raw(method: string, path: string, init: RequestInitLike = {}): Promise<Response> {
    if (versionMismatch !== null) {
      throw VaulterApiError.apiVersionMismatch(versionMismatch)
    }

    const hasJson = init.json !== undefined
    const response = await fetchImpl(`${baseUrl}${path}${toQueryString(init.query)}`, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: init.accept ?? 'application/json',
        ...(hasJson ? { 'Content-Type': 'application/json' } : {}),
        ...(init.etag !== undefined ? { 'If-Match': init.etag } : {}),
        ...defaultHeaders(),
        ...init.headers,
      },
      body: hasJson ? JSON.stringify(init.json) : init.body ?? undefined,
    })

    checkVersion(response)

    if (!response.ok) {
      throw await VaulterApiError.fromResponse(response)
    }

    return response
  }

  async function json<T>(method: string, path: string, init: RequestInitLike = {}): Promise<T> {
    const response = await raw(method, path, init)

    if (response.status === 204) {
      return undefined as T
    }

    return (await response.json()) as T
  }

  return { raw, json, url: (path) => `${baseUrl}${path}` }
}

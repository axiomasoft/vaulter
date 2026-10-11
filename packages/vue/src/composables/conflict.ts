import { VaulterApiError } from '../api/errors'

/** A 412: the resource changed since it was read, so the `If-Match` tag no longer matches. */
export function isStaleError(error: unknown): error is VaulterApiError {
  return error instanceof VaulterApiError && error.status === 412
}

/**
 * A 428 means the call went out without its `If-Match` tag: a bug in the integration, not
 * a condition to recover from, so it is never swallowed or retried.
 */
export function isMissingPreconditionError(error: unknown): error is VaulterApiError {
  return error instanceof VaulterApiError && error.status === 428
}

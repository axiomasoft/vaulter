import { VaulterApiError } from '../api/errors'

/** Quarantine or another clean-scan failure: the bytes must not be shown as a preview. */
export function isContentUnavailable(error: unknown): boolean {
  return error instanceof VaulterApiError && (error.status === 423 || error.code === 'content_unavailable')
}

/** `files.download` stopped on a direct_signed redirect. Open `downloadUrl`; do not follow it. */
export function isSignedDownloadRedirect(error: unknown): boolean {
  return error instanceof VaulterApiError && error.status >= 300 && error.status < 400
}

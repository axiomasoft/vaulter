import type { Node } from '../types'

export const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024

export interface ResumableUploadOptions {
  /** Chunk size in bytes; defaults to the size the server advertises for the session. */
  chunkSize?: number
  /** Progress callback: (bytesSent, totalBytes). */
  onProgress?: (sent: number, total: number) => void
}

/** What a protocol runner needs from a running upload. */
export interface UploadRun {
  total: number
  chunkSize: number
  onProgress?: (sent: number, total: number) => void
  /** True once `abort()` was requested; checked between chunks. */
  isAborted: () => boolean
}

/** A driveable resumable upload (hand-rolled, no external tus dependency). */
export interface ResumableUpload {
  /** Run the upload to completion, resuming from the server offset. Resolves to the created node. */
  start(): Promise<Node>
  /** Request abort: the in-flight loop stops and the session is aborted on the server. */
  abort(): Promise<void>
}

export function abortedError(): Error {
  return new Error('[Vaulter] Resumable upload aborted.')
}

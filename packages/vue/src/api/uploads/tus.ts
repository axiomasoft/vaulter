import { VaulterApiError } from '../errors'
import type { Transport } from '../transport'
import type { Node } from '../types'
import { abortedError, type UploadRun } from './shared'

const TUS_VERSION = '1.0.0'

/** The authoritative server-side offset: HEAD reports min(on-disk, received_bytes). */
export async function tusOffset(transport: Transport, uploadId: string): Promise<number> {
  const response = await transport.raw('HEAD', `/uploads/${uploadId}`, {
    headers: { 'Tus-Resumable': TUS_VERSION, Accept: '*/*' },
  })
  const header = response.headers.get('Upload-Offset')
  const offset = header === null ? Number.NaN : Number(header)
  if (!Number.isFinite(offset)) {
    throw new Error('[Vaulter] Resumable upload did not report an offset.')
  }

  return offset
}

/**
 * The tus PATCH loop: always resumes from the SERVER offset, never its own guess, and
 * finishes on the 201 that carries the created node. A zero-byte file still sends one
 * empty PATCH, because the server commits on the final chunk.
 */
export async function runTus(transport: Transport, uploadId: string, file: Blob, run: UploadRun): Promise<Node> {
  let offset = await tusOffset(transport, uploadId)
  run.onProgress?.(offset, run.total)

  do {
    if (run.isAborted()) {
      throw abortedError()
    }

    const slice = file.slice(offset, Math.min(offset + run.chunkSize, run.total))
    let response: Response
    try {
      response = await transport.raw('PATCH', `/uploads/${uploadId}`, {
        headers: {
          'Tus-Resumable': TUS_VERSION,
          'Content-Type': 'application/offset+octet-stream',
          'Upload-Offset': String(offset),
        },
        body: slice,
      })
    } catch (error) {
      const header = error instanceof VaulterApiError && error.status === 409
        ? error.response?.headers.get('Upload-Offset')
        : null
      const resumed = header != null && /^\d+$/.test(header) ? Number(header) : Number.NaN
      if (!Number.isSafeInteger(resumed) || resumed === offset) {
        throw error
      }
      offset = resumed
      run.onProgress?.(Math.min(offset, run.total), run.total)
      continue
    }

    offset = Number(response.headers.get('Upload-Offset') ?? offset + slice.size)
    run.onProgress?.(Math.min(offset, run.total), run.total)

    // 204 = more chunks expected; 201 = all bytes are in and the node exists.
    if (response.status === 201) {
      return ((await response.json()) as { data: Node }).data
    }
  } while (offset < run.total)

  throw new Error('[Vaulter] Resumable upload ended without a created node.')
}

export async function abortTus(transport: Transport, uploadId: string): Promise<void> {
  await transport.raw('DELETE', `/uploads/${uploadId}`, { headers: { 'Tus-Resumable': TUS_VERSION } })
}

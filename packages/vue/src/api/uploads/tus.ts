import type { Transport } from '../transport'
import type { Node, UploadSession } from '../types'
import { abortedError, type UploadRun } from './shared'

const TUS_VERSION = '1.0.0'

/** The authoritative server-side offset to resume from. */
export async function tusOffset(transport: Transport, uploadId: string): Promise<number> {
  const { data } = await transport.json<{ data: UploadSession }>('GET', `/uploads/${uploadId}`)

  return data.received_bytes
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
    const response = await transport.raw('PATCH', `/uploads/${uploadId}`, {
      headers: {
        'Tus-Resumable': TUS_VERSION,
        'Content-Type': 'application/offset+octet-stream',
        'Upload-Offset': String(offset),
      },
      body: slice,
    })

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

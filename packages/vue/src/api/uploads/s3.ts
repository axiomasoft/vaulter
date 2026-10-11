import type { Transport } from '../transport'
import type { Node, UploadPart } from '../types'
import { abortedError, type UploadRun } from './shared'

/**
 * S3-multipart: every numbered part goes through the API (the server owns the credentials),
 * then `complete` assembles them into the node.
 */
export async function runS3(transport: Transport, uploadId: string, file: Blob, run: UploadRun): Promise<Node> {
  let sent = 0
  let partNo = 1
  run.onProgress?.(0, run.total)

  while (sent < run.total) {
    if (run.isAborted()) {
      throw abortedError()
    }

    const chunk = file.slice(sent, Math.min(sent + run.chunkSize, run.total))
    await transport.json<UploadPart>('POST', `/uploads/${uploadId}/parts`, {
      query: { part_no: partNo },
      headers: { 'Content-Type': 'application/octet-stream' },
      body: chunk,
    })

    sent += chunk.size
    partNo += 1
    run.onProgress?.(sent, run.total)
  }

  const { data } = await transport.json<{ data: Node }>('POST', `/uploads/${uploadId}/complete`)

  return data
}

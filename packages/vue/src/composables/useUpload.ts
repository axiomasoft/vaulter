import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type { StartUploadOptions, VaulterClient } from '../api/client'
import type { DriveId, Node, NodeId } from '../api/types'

export interface UseUploadReturn {
  uploading: Ref<boolean>
  error: Ref<unknown>
  /** Bytes sent so far in the active (resumable) upload. */
  sent: Ref<number>
  /** Total bytes of the active upload. */
  total: Ref<number>
  /** Progress 0..1 of the active resumable upload. */
  progress: ComputedRef<number>
  /** A simple one-request upload. */
  upload: (file: Blob, options?: { parentId?: NodeId | null, name?: string }) => Promise<Node>
  /** A resumable upload (tus by default, `protocol: 's3'` for multipart), reporting progress. */
  uploadResumable: (file: Blob, options?: StartUploadOptions) => Promise<Node>
  /** Abort the active resumable upload. */
  abort: () => Promise<void>
}

/**
 * Upload into a drive with progress, exposing both the simple path and the resumable one.
 * Progress reflects the active resumable upload.
 *
 * `client` is normally injected; pass a pre-resolved client when spinning up a per-file
 * uploader OUTSIDE the `setup()` injection context (e.g. one instance per dropped file),
 * where `inject()` is no longer available.
 */
export function useUpload(driveId: DriveId, client: VaulterClient = useVaulterClient()): UseUploadReturn {
  const uploading = ref(false)
  const error = ref<unknown>(null)
  const sent = ref(0)
  const total = ref(0)

  let active: { abort: () => Promise<void> } | null = null

  const progress = computed(() => (total.value > 0 ? sent.value / total.value : 0))

  async function upload(file: Blob, options?: { parentId?: NodeId | null, name?: string }): Promise<Node> {
    uploading.value = true
    error.value = null
    sent.value = 0
    total.value = file.size
    try {
      const node = await client.files.upload(driveId, file, options)
      sent.value = total.value
      return node
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      uploading.value = false
    }
  }

  async function uploadResumable(file: Blob, options?: StartUploadOptions): Promise<Node> {
    uploading.value = true
    error.value = null
    sent.value = 0
    total.value = file.size

    const resumable = client.uploads.start(driveId, file, {
      ...options,
      onProgress: (bytesSent, bytesTotal) => {
        sent.value = bytesSent
        total.value = bytesTotal
        options?.onProgress?.(bytesSent, bytesTotal)
      },
    })
    active = resumable

    try {
      return await resumable.start()
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      uploading.value = false
      active = null
    }
  }

  async function abort(): Promise<void> {
    await active?.abort()
  }

  return { uploading, error, sent, total, progress, upload, uploadResumable, abort }
}

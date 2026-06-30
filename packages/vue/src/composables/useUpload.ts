import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type { ResumableUploadOptions, VaulterClient } from '../client'
import type { DirectUploadOptions, Node, StorageId } from '../types'

export interface UseUploadReturn {
  uploading: Ref<boolean>
  error: Ref<unknown>
  /** Bytes sent so far in the active (resumable) upload. */
  sent: Ref<number>
  /** Total bytes of the active upload. */
  total: Ref<number>
  /** Progress 0..1 of the active resumable upload. */
  progress: ComputedRef<number>
  /** A simple direct (single-request) upload. */
  upload: (file: Blob, options?: DirectUploadOptions) => Promise<Node>
  /** A resumable upload driving the tus session, reporting progress. */
  uploadResumable: (file: Blob, options?: ResumableUploadOptions) => Promise<Node>
  /** Abort the active resumable upload. */
  abort: () => Promise<void>
}

/**
 * Upload into a storage with progress, exposing both the simple direct path and the
 * resumable (tus) path. Progress reflects the active resumable upload.
 *
 * `client` is normally injected; pass a pre-resolved client when spinning up a
 * per-file uploader OUTSIDE the `setup()` injection context (e.g. one instance per
 * dropped file), where `inject()` is no longer available.
 */
export function useUpload(storageId: StorageId, client: VaulterClient = useVaulterClient()): UseUploadReturn {
  const uploading = ref(false)
  const error = ref<unknown>(null)
  const sent = ref(0)
  const total = ref(0)

  let active: { abort: () => Promise<void> } | null = null

  const progress = computed(() => (total.value > 0 ? sent.value / total.value : 0))

  async function upload(file: Blob, options?: DirectUploadOptions): Promise<Node> {
    uploading.value = true
    error.value = null
    sent.value = 0
    total.value = file.size
    try {
      const node = await client.uploads.upload(storageId, file, options)
      sent.value = total.value
      return node
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      uploading.value = false
    }
  }

  async function uploadResumable(file: Blob, options?: ResumableUploadOptions): Promise<Node> {
    uploading.value = true
    error.value = null
    sent.value = 0
    total.value = file.size

    const upload = client.uploads.resumable(storageId, file, {
      ...options,
      onProgress: (bytesSent, bytesTotal) => {
        sent.value = bytesSent
        total.value = bytesTotal
        options?.onProgress?.(bytesSent, bytesTotal)
      },
    })
    active = upload

    try {
      return await upload.start()
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

import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type { Drive, DriveId } from '../api/types'

export interface UseDriveReturn {
  drive: ShallowRef<Drive | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  load: () => Promise<Drive>
}

/** Reactive load of a single drive (its root node and usage accounting). */
export function useDrive(driveId: DriveId): UseDriveReturn {
  const client = useVaulterClient()
  const drive = shallowRef<Drive | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function load(): Promise<Drive> {
    loading.value = true
    error.value = null
    try {
      drive.value = await client.drives.get(driveId)
      return drive.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  return { drive, loading, error, load }
}

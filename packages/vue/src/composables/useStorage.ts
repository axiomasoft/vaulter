import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type { Storage, StorageId } from '../types'

export interface UseStorageReturn {
  storage: ShallowRef<Storage | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  load: () => Promise<Storage>
}

/**
 * Reactive load of a single storage container (its root node + usage accounting),
 * backed by the injected client.
 */
export function useStorage(storageId: StorageId): UseStorageReturn {
  const client = useVaulterClient()
  const storage = shallowRef<Storage | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function load(): Promise<Storage> {
    loading.value = true
    error.value = null
    try {
      storage.value = await client.storages.get(storageId)
      return storage.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  return { storage, loading, error, load }
}

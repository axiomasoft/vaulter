import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useDocVaultClient } from './useDocVaultClient'
import type { CreateSharePayload, DocVaultShare, DocumentId } from '../types'

export interface UseSharesReturn {
  shares: ShallowRef<DocVaultShare[]>
  loading: Ref<boolean>
  error: Ref<unknown>
  list: () => Promise<DocVaultShare[]>
  create: (payload: CreateSharePayload) => Promise<DocVaultShare>
  revoke: (shareId: DocumentId) => Promise<void>
}

/**
 * Reactive management of a document's public share links.
 */
export function useShares(documentId: DocumentId): UseSharesReturn {
  const client = useDocVaultClient()
  const shares = shallowRef<DocVaultShare[]>([])
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function list(): Promise<DocVaultShare[]> {
    loading.value = true
    error.value = null
    try {
      shares.value = await client.shares.list(documentId)
      return shares.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function create(payload: CreateSharePayload): Promise<DocVaultShare> {
    const created = await client.shares.create(documentId, payload)
    shares.value = [created, ...shares.value]
    return created
  }

  async function revoke(shareId: DocumentId): Promise<void> {
    await client.shares.revoke(documentId, shareId)
    shares.value = shares.value.filter((share) => share.id !== shareId)
  }

  return { shares, loading, error, list, create, revoke }
}

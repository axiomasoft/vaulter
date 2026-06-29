import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type { CreateSharePayload, VaulterShare, DocumentId } from '../types'

export interface UseSharesReturn {
  shares: ShallowRef<VaulterShare[]>
  loading: Ref<boolean>
  error: Ref<unknown>
  list: () => Promise<VaulterShare[]>
  create: (payload: CreateSharePayload) => Promise<VaulterShare>
  revoke: (shareId: DocumentId) => Promise<void>
}

/**
 * Reactive management of a document's public share links.
 */
export function useShares(documentId: DocumentId): UseSharesReturn {
  const client = useVaulterClient()
  const shares = shallowRef<VaulterShare[]>([])
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function list(): Promise<VaulterShare[]> {
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

  async function create(payload: CreateSharePayload): Promise<VaulterShare> {
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

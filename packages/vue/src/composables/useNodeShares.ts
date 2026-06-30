import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type {
  CreateNodeSharePayload,
  NodeId,
  NodeShare,
  NodeShareCreated,
} from '../types'

export interface UseNodeSharesReturn {
  shares: ShallowRef<NodeShare[]>
  loading: Ref<boolean>
  error: Ref<unknown>
  /** The last-minted raw token + URL — held ONCE, until the next create/clear. */
  lastCreated: ShallowRef<NodeShareCreated | null>
  list: () => Promise<NodeShare[]>
  create: (payload?: CreateNodeSharePayload) => Promise<NodeShareCreated>
  revoke: (shareId: NodeId) => Promise<void>
  /** Forget the last-minted token (so a stale secret never lingers in memory). */
  clearLastCreated: () => void
}

/**
 * Reactive management of a node's bearer share-links. `create` surfaces the raw
 * token EXACTLY ONCE via `lastCreated`; the listed shares never carry it. The
 * created share (sans secret fields are fine to keep) is prepended to the list.
 */
export function useNodeShares(nodeId: NodeId): UseNodeSharesReturn {
  const client = useVaulterClient()
  const shares = shallowRef<NodeShare[]>([])
  const lastCreated = shallowRef<NodeShareCreated | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function list(): Promise<NodeShare[]> {
    loading.value = true
    error.value = null
    try {
      shares.value = (await client.nodeShares.list(nodeId)).data
      return shares.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function create(payload?: CreateNodeSharePayload): Promise<NodeShareCreated> {
    const created = await client.nodeShares.create(nodeId, payload)
    lastCreated.value = created
    shares.value = [created, ...shares.value]
    return created
  }

  async function revoke(shareId: NodeId): Promise<void> {
    await client.nodeShares.revoke(nodeId, shareId)
    shares.value = shares.value.filter((share) => share.id !== shareId)
    if (lastCreated.value?.id === shareId) {
      lastCreated.value = null
    }
  }

  function clearLastCreated(): void {
    lastCreated.value = null
  }

  return { shares, lastCreated, loading, error, list, create, revoke, clearLastCreated }
}

import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import { createListGuard } from './listGuard'
import type { CreateShareInput, MintedShare, NodeId, Share, UpdateShareInput } from '../api/types'

export interface UseSharesReturn {
  shares: ShallowRef<Share[]>
  loading: Ref<boolean>
  error: Ref<unknown>
  /** The last-minted raw token + URL — held ONCE, until the next create/clear. */
  lastCreated: ShallowRef<MintedShare | null>
  list: () => Promise<Share[]>
  create: (input?: CreateShareInput) => Promise<MintedShare>
  /** Change expiry, budget, password or audience of a link; its token stays. */
  update: (shareId: string, input: UpdateShareInput) => Promise<Share>
  /** Replace a link's token; the new raw token is held once in `lastCreated`. */
  rotate: (shareId: string) => Promise<MintedShare>
  revoke: (shareId: string) => Promise<void>
  /** Forget the last-minted token (so a stale secret never lingers in memory). */
  clearLastCreated: () => void
}

/**
 * Reactive management of a node's bearer share links. `create` surfaces the raw token
 * EXACTLY ONCE via `lastCreated`; the listed shares never carry it.
 */
export function useShares(nodeId: NodeId): UseSharesReturn {
  const client = useVaulterClient()
  const shares = shallowRef<Share[]>([])
  const lastCreated = shallowRef<MintedShare | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)
  const guard = createListGuard()

  async function list(): Promise<Share[]> {
    const token = guard.begin()
    loading.value = true
    error.value = null
    try {
      const loaded = await client.shares.list(nodeId)
      // A newer load or a local change happened meanwhile: this snapshot is history.
      if (guard.isCurrent(token)) {
        shares.value = loaded
      }
      return shares.value
    } catch (caught) {
      if (guard.isCurrent(token)) {
        error.value = caught
      }
      throw caught
    } finally {
      loading.value = !guard.end()
    }
  }

  async function create(input?: CreateShareInput): Promise<MintedShare> {
    const created = await client.shares.create(nodeId, input)
    guard.invalidate()
    lastCreated.value = created
    shares.value = [created, ...shares.value]
    return created
  }

  async function update(shareId: string, input: UpdateShareInput): Promise<Share> {
    const updated = await client.shares.update(nodeId, shareId, input)
    guard.invalidate()
    shares.value = shares.value.map((share) => (share.id === shareId ? updated : share))
    return updated
  }

  async function rotate(shareId: string): Promise<MintedShare> {
    const rotated = await client.shares.rotate(nodeId, shareId)
    guard.invalidate()
    lastCreated.value = rotated
    shares.value = shares.value.map((share) => (share.id === shareId ? rotated : share))
    return rotated
  }

  async function revoke(shareId: string): Promise<void> {
    await client.shares.revoke(nodeId, shareId)
    guard.invalidate()
    shares.value = shares.value.filter((share) => share.id !== shareId)
    if (lastCreated.value?.id === shareId) {
      lastCreated.value = null
    }
  }

  function clearLastCreated(): void {
    lastCreated.value = null
  }

  return { shares, lastCreated, loading, error, list, create, update, rotate, revoke, clearLastCreated }
}

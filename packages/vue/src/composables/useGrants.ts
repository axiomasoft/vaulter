import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import { createListGuard } from './listGuard'
import type { CreateGrantInput, Grant, NodeId } from '../api/types'

export interface UseGrantsReturn {
  grants: ShallowRef<Grant[]>
  loading: Ref<boolean>
  error: Ref<unknown>
  list: () => Promise<Grant[]>
  grant: (input: CreateGrantInput) => Promise<Grant>
  /** Revoke one grant by its id. */
  revoke: (grantId: string) => Promise<void>
}

/** Reactive management of a node's per-node grants, backed by the injected client. */
export function useGrants(nodeId: NodeId): UseGrantsReturn {
  const client = useVaulterClient()
  const grants = shallowRef<Grant[]>([])
  const loading = ref(false)
  const error = ref<unknown>(null)
  const guard = createListGuard()

  async function list(): Promise<Grant[]> {
    const token = guard.begin()
    loading.value = true
    error.value = null
    try {
      const loaded = await client.grants.list(nodeId)
      // A newer load or a local change happened meanwhile: this snapshot is history.
      if (guard.isCurrent(token)) {
        grants.value = loaded
      }
      return grants.value
    } catch (caught) {
      if (guard.isCurrent(token)) {
        error.value = caught
      }
      throw caught
    } finally {
      loading.value = !guard.end()
    }
  }

  async function grant(input: CreateGrantInput): Promise<Grant> {
    const created = await client.grants.create(nodeId, input)
    guard.invalidate()
    grants.value = [created, ...grants.value]
    return created
  }

  async function revoke(grantId: string): Promise<void> {
    await client.grants.revoke(nodeId, grantId)
    guard.invalidate()
    grants.value = grants.value.filter((existing) => existing.id !== grantId)
  }

  return { grants, loading, error, list, grant, revoke }
}

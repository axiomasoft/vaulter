import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import { createListGuard } from './listGuard'
import { requireTag } from './conflict'
import type { CreateGrantInput, Grant, IfMatch, NodeId } from '../api/types'

export interface UseGrantsReturn {
  grants: ShallowRef<Grant[]>
  /**
   * The node's access tag from the last `list()`, the If-Match of a revoke. Every grant and revoke
   * raises it on the server, so a local change clears it and the next `revoke` reloads first.
   */
  accessTag: Ref<string | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  list: () => Promise<Grant[]>
  grant: (input: CreateGrantInput) => Promise<Grant>
  /**
   * Revoke one grant by its id, conditional on the access tag (the held one, unless one is passed); a
   * grant set changed since it was read rejects with a 412 `stale_write` VaulterApiError.
   */
  revoke: (grantId: string, options?: Partial<IfMatch>) => Promise<void>
}

/** Reactive management of a node's per-node grants, backed by the injected client. */
export function useGrants(nodeId: NodeId): UseGrantsReturn {
  const client = useVaulterClient()
  const grants = shallowRef<Grant[]>([])
  const accessTag = ref<string | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)
  const guard = createListGuard()

  async function list(): Promise<Grant[]> {
    const token = guard.begin()
    loading.value = true
    error.value = null
    try {
      const loaded = await client.grants.listTagged(nodeId)
      // A newer load or a local change happened meanwhile: this snapshot is history.
      if (guard.isCurrent(token)) {
        grants.value = loaded.data
        accessTag.value = loaded.etag
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
    // The grant raised the node's access version: the held tag is stale now.
    accessTag.value = null
    grants.value = [created, ...grants.value]
    return created
  }

  async function revoke(grantId: string, options?: Partial<IfMatch>): Promise<void> {
    if (options?.etag === undefined && accessTag.value === null) {
      await list()
    }
    await client.grants.revoke(nodeId, grantId, { etag: requireTag(options?.etag, accessTag.value, `the grants of node ${nodeId}`) })
    guard.invalidate()
    accessTag.value = null
    grants.value = grants.value.filter((existing) => existing.id !== grantId)
  }

  return { grants, accessTag, loading, error, list, grant, revoke }
}

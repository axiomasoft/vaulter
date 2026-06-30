import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type {
  GrantNodeAccessPayload,
  NodeGrant,
  NodeId,
  RevokeNodeAccessPayload,
} from '../types'

export interface UseNodeGrantsReturn {
  grants: ShallowRef<NodeGrant[]>
  loading: Ref<boolean>
  error: Ref<unknown>
  list: () => Promise<NodeGrant[]>
  grant: (payload: GrantNodeAccessPayload) => Promise<NodeGrant>
  revoke: (payload: RevokeNodeAccessPayload) => Promise<void>
}

/**
 * Reactive management of a node's per-node ACL grants, backed by the injected
 * client. A grant appends to the list; a revoke drops every grant for the subject.
 */
export function useNodeGrants(nodeId: NodeId): UseNodeGrantsReturn {
  const client = useVaulterClient()
  const grants = shallowRef<NodeGrant[]>([])
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function list(): Promise<NodeGrant[]> {
    loading.value = true
    error.value = null
    try {
      grants.value = (await client.grants.list(nodeId)).data
      return grants.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function grant(payload: GrantNodeAccessPayload): Promise<NodeGrant> {
    const created = await client.grants.grant(nodeId, payload)
    grants.value = [created, ...grants.value]
    return created
  }

  async function revoke(payload: RevokeNodeAccessPayload): Promise<void> {
    await client.grants.revoke(nodeId, payload)
    grants.value = grants.value.filter(
      (g) =>
        ! (g.subject_type === payload.subjectType
          && String(g.subject_id) === String(payload.subjectId)),
    )
  }

  return { grants, loading, error, list, grant, revoke }
}

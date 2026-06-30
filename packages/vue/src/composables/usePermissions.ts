import { computed, toValue, type ComputedRef, type MaybeRefOrGetter } from 'vue'
import type { DocumentAbilities } from '../types'

export type AbilityName = keyof DocumentAbilities

export interface UsePermissionsReturn {
  abilities: ComputedRef<DocumentAbilities | null>
  canView: ComputedRef<boolean>
  canComment: ComputedRef<boolean>
  canEdit: ComputedRef<boolean>
  canManage: ComputedRef<boolean>
  canDelete: ComputedRef<boolean>
  can: (ability: AbilityName) => boolean
}

/**
 * Reactive helpers over a document's computed abilities (as shipped in
 * `VaulterDocument.abilities`). Missing abilities resolve to `false`.
 */
export function usePermissions(
  source: MaybeRefOrGetter<DocumentAbilities | null | undefined>,
): UsePermissionsReturn {
  const abilities = computed<DocumentAbilities | null>(() => toValue(source) ?? null)

  const flag = (ability: AbilityName): ComputedRef<boolean> =>
    computed(() => abilities.value?.[ability] ?? false)

  return {
    abilities,
    canView: flag('view'),
    canComment: flag('comment'),
    canEdit: flag('edit'),
    canManage: flag('manage'),
    canDelete: flag('delete'),
    can: (ability) => abilities.value?.[ability] ?? false,
  }
}

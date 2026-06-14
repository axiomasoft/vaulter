import { describe, expect, it } from 'vitest'
import { ref } from 'vue'
import { usePermissions } from '../src/composables/usePermissions'

describe('usePermissions', () => {
  it('exposes reactive ability flags', () => {
    const abilities = ref({ view: true, comment: false, edit: true, manage: false, delete: false })
    const { canView, canEdit, canManage, can } = usePermissions(abilities)

    expect(canView.value).toBe(true)
    expect(canEdit.value).toBe(true)
    expect(canManage.value).toBe(false)
    expect(can('delete')).toBe(false)
  })

  it('reacts to ability changes', () => {
    const abilities = ref({ view: false, comment: false, edit: false, manage: false, delete: false })
    const { canView } = usePermissions(abilities)

    expect(canView.value).toBe(false)
    abilities.value = { ...abilities.value, view: true }
    expect(canView.value).toBe(true)
  })

  it('defaults missing abilities to false', () => {
    const { canView, canEdit } = usePermissions(null)

    expect(canView.value).toBe(false)
    expect(canEdit.value).toBe(false)
  })
})

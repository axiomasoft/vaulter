<script setup lang="ts">
import { onMounted, ref, type Ref } from 'vue'
import { useVaulterClient } from '../composables/useVaulterClient'
import type { VaulterPermission, DocumentId, GrantPermissionPayload } from '../types'

const props = withDefaults(
  defineProps<{ documentId: DocumentId, autoLoad?: boolean }>(),
  { autoLoad: true },
)

const emit = defineEmits<{
  granted: [permission: VaulterPermission]
  revoked: [permissionId: DocumentId]
}>()

const client = useVaulterClient()
const permissions: Ref<VaulterPermission[]> = ref([])
const loading = ref(false)

async function refresh(): Promise<void> {
  loading.value = true
  try {
    permissions.value = await client.permissions.list(props.documentId)
  } finally {
    loading.value = false
  }
}

async function grant(payload: GrantPermissionPayload): Promise<void> {
  const permission = await client.permissions.grant(props.documentId, payload)
  emit('granted', permission)
  await refresh()
}

async function revoke(permission: VaulterPermission): Promise<void> {
  await client.permissions.revoke(props.documentId, permission.id)
  emit('revoked', permission.id)
  await refresh()
}

onMounted(() => {
  if (props.autoLoad) {
    void refresh()
  }
})

defineExpose({ refresh, grant, revoke, permissions, loading })
</script>

<template>
  <div class="vaulter-permissions-panel">
    <slot v-if="loading" name="loading">Loading…</slot>
    <ul v-else class="vaulter-permissions-panel__list">
      <li v-for="permission in permissions" :key="permission.id" class="vaulter-permissions-panel__item">
        <slot name="item" :permission="permission" :revoke="() => revoke(permission)">
          {{ permission.subject.type }}#{{ permission.subject.id }} — {{ permission.permission }}
        </slot>
      </li>
    </ul>
  </div>
</template>

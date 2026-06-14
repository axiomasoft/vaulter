<script setup lang="ts">
import { onMounted, ref, type Ref } from 'vue'
import { useDocVaultClient } from '../composables/useDocVaultClient'
import type { DocVaultPermission, DocumentId, GrantPermissionPayload } from '../types'

const props = withDefaults(
  defineProps<{ documentId: DocumentId, autoLoad?: boolean }>(),
  { autoLoad: true },
)

const emit = defineEmits<{
  granted: [permission: DocVaultPermission]
  revoked: [permissionId: DocumentId]
}>()

const client = useDocVaultClient()
const permissions: Ref<DocVaultPermission[]> = ref([])
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

async function revoke(permission: DocVaultPermission): Promise<void> {
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
  <div class="docvault-permissions-panel">
    <slot v-if="loading" name="loading">Loading…</slot>
    <ul v-else class="docvault-permissions-panel__list">
      <li v-for="permission in permissions" :key="permission.id" class="docvault-permissions-panel__item">
        <slot name="item" :permission="permission" :revoke="() => revoke(permission)">
          {{ permission.subject.type }}#{{ permission.subject.id }} — {{ permission.permission }}
        </slot>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref, type Ref } from 'vue'
import { useDocVaultClient } from '../composables/useDocVaultClient'
import type { DocVaultVersion, DocumentId } from '../types'

const props = withDefaults(
  defineProps<{ documentId: DocumentId, autoLoad?: boolean }>(),
  { autoLoad: true },
)

const emit = defineEmits<{
  restore: [version: DocVaultVersion]
  preview: [version: DocVaultVersion]
}>()

const client = useDocVaultClient()
const versions: Ref<DocVaultVersion[]> = ref([])
const loading = ref(false)

async function refresh(): Promise<void> {
  loading.value = true
  try {
    versions.value = await client.versions.list(props.documentId)
  } finally {
    loading.value = false
  }
}

async function restore(version: DocVaultVersion): Promise<void> {
  await client.versions.restore(props.documentId, version.id)
  emit('restore', version)
  await refresh()
}

onMounted(() => {
  if (props.autoLoad) {
    void refresh()
  }
})

defineExpose({ refresh, versions, loading })
</script>

<template>
  <div class="docvault-version-history">
    <slot v-if="loading" name="loading">Loading…</slot>
    <ul v-else class="docvault-version-history__list">
      <li v-for="version in versions" :key="version.id" class="docvault-version-history__item">
        <slot
          name="item"
          :version="version"
          :restore="() => restore(version)"
          :preview="() => emit('preview', version)"
        >
          {{ version.snapshot_label ?? version.created_at }}
        </slot>
      </li>
    </ul>
  </div>
</template>

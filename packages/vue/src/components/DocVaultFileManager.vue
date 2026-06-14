<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useDocuments } from '../composables/useDocuments'
import type { DocVaultDocument, DocumentId, DocumentTypeName } from '../types'

// File manager — the main entry point (Google Drive style). Loads a workspace's
// documents through the injected client; rendering is slot-driven so host apps
// control the look.
const props = withDefaults(
  defineProps<{
    workspaceType: string
    workspaceId: DocumentId
    viewMode?: 'grid' | 'list'
    allowedTypes?: DocumentTypeName[]
    autoLoad?: boolean
  }>(),
  { viewMode: 'list', allowedTypes: () => [], autoLoad: true },
)

const emit = defineEmits<{
  'open-document': [document: DocVaultDocument]
  'create-document': [type: DocumentTypeName, folderId: DocumentId | null]
  'folder-changed': [folderId: DocumentId | null]
}>()

const { documents, loading, error, list } = useDocuments()

async function refresh(): Promise<void> {
  await list({ workspace_type: props.workspaceType, workspace_id: props.workspaceId })
}

onMounted(() => {
  if (props.autoLoad) {
    void refresh()
  }
})

watch(
  () => [props.workspaceType, props.workspaceId],
  () => {
    if (props.autoLoad) {
      void refresh()
    }
  },
)

defineExpose({ refresh, documents, loading, error })
</script>

<template>
  <div class="docvault-file-manager" :data-view="viewMode">
    <slot v-if="loading" name="loading">Loading…</slot>
    <slot v-else-if="error" name="error" :error="error">Failed to load documents.</slot>
    <ul v-else class="docvault-file-manager__list">
      <li
        v-for="doc in documents"
        :key="doc.id"
        class="docvault-file-manager__item"
        @click="emit('open-document', doc)"
      >
        <slot name="item" :document="doc">{{ doc.title }}</slot>
      </li>
    </ul>
  </div>
</template>

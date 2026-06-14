<script setup lang="ts">
import { onMounted } from 'vue'
import { useDocVaultEditor } from '../composables/useDocVaultEditor'
import { usePermissions } from '../composables/usePermissions'
import type { DocVaultDocument, DocumentId, TipTapDocument } from '../types'

// Editor shell: loads the document, tracks unsaved changes and persists content
// (with optional autosave). The TipTap surface + collaborator presence land in M3;
// feed editor output to `set-content` (exposed) and read `content` from the slot.
const props = withDefaults(
  defineProps<{
    documentId: DocumentId
    collaborative?: boolean
    readonly?: boolean
    autosave?: boolean
    autoLoad?: boolean
  }>(),
  { collaborative: true, readonly: false, autosave: true, autoLoad: true },
)

const emit = defineEmits<{
  save: [document: DocVaultDocument]
  'content-changed': [content: TipTapDocument]
}>()

const { document, content, dirty, saving, loading, load, setContent, save, snapshot } = useDocVaultEditor(
  props.documentId,
  { autosave: props.autosave },
)

const { canEdit } = usePermissions(() => document.value?.abilities ?? null)

onMounted(() => {
  if (props.autoLoad) {
    void load()
  }
})

function update(next: TipTapDocument): void {
  if (props.readonly) {
    return
  }
  setContent(next)
  emit('content-changed', next)
}

async function persist(): Promise<void> {
  const saved = await save()
  if (saved) {
    emit('save', saved)
  }
}

defineExpose({ load, save: persist, setContent: update, snapshot, content, dirty, saving, canEdit })
</script>

<template>
  <div class="docvault-editor" :data-readonly="readonly">
    <slot v-if="loading" name="loading">Loading…</slot>
    <slot
      v-else
      :document="document"
      :content="content"
      :dirty="dirty"
      :saving="saving"
      :can-edit="canEdit"
      :set-content="update"
      :save="persist"
    >
      <div class="docvault-editor__placeholder">{{ document?.title ?? 'DocVault editor' }}</div>
    </slot>
  </div>
</template>

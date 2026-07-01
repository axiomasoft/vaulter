<script setup lang="ts">
import { onMounted } from 'vue'
import { useSpreadsheet } from '../composables/useSpreadsheet'
import type { NodeId, SpreadsheetVersion, SpreadsheetWorkbook } from '../types'

// Headless shell for a NON-rich-text (spreadsheet) document node — the sibling of
// VaulterEditor for TipTap. It owns loading the opaque workbook snapshot, tracking
// unsaved edits and persisting them (with debounced autosave); the actual spreadsheet
// surface (Univer, etc.) is mounted by the CONSUMER in the default slot, seeded from
// `workbook` and fed back through `set-workbook`. No spreadsheet engine is a dependency
// of @vaulter/vue — the shell is engine-agnostic.
const props = withDefaults(
  defineProps<{
    nodeId: NodeId
    readonly?: boolean
    autoLoad?: boolean
    /** Autosave debounce window in ms. */
    debounceMs?: number
  }>(),
  { readonly: false, autoLoad: true, debounceMs: 800 },
)

const emit = defineEmits<{
  save: [version: SpreadsheetVersion]
  'workbook-changed': [workbook: SpreadsheetWorkbook]
}>()

const { workbook, handler, loading, saving, error, load, setWorkbook, save, loadHandler } =
  useSpreadsheet(props.nodeId, { debounceMs: props.debounceMs })

onMounted(() => {
  if (props.autoLoad) {
    // A load failure is captured in `error` (shown via the error slot); swallow the
    // rejection here so it is not an unhandled promise rejection.
    void load().then(loadHandler).catch(() => {})
  }
})

function update(next: SpreadsheetWorkbook): void {
  if (props.readonly) {
    return
  }
  setWorkbook(next)
  emit('workbook-changed', next)
}

async function persist(): Promise<void> {
  const saved = await save()
  emit('save', saved)
}

defineExpose({ load, save: persist, setWorkbook: update, workbook, saving })
</script>

<template>
  <div class="vaulter-spreadsheet" :data-readonly="readonly">
    <slot v-if="loading" name="loading">Loading…</slot>
    <p v-else-if="error" class="vaulter-spreadsheet__error" role="alert">
      <slot name="error" :error="error">Could not load this spreadsheet.</slot>
    </p>
    <slot
      v-else
      :workbook="workbook"
      :handler="handler"
      :saving="saving"
      :readonly="readonly"
      :set-workbook="update"
      :save="persist"
    >
      <div class="vaulter-spreadsheet__placeholder">
        Mount your spreadsheet editor here (seed it from <code>workbook</code>, feed edits to
        <code>set-workbook</code>).
      </div>
    </slot>
  </div>
</template>

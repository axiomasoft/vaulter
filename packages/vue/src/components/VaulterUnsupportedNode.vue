<script setup lang="ts">
import { computed } from 'vue'
import { useVaulterClient } from '../composables/useVaulterClient'
import { useVaulterI18n } from '../i18n'
import type { Node } from '../api/types'

// The fallback viewer: names the item, says why it cannot be opened and offers the bytes
// when the caller may download them. Whether the caller may is the server's answer
// (`abilities.download`), never decided here: a previewer sees the item but not its contents.
const props = defineProps<{ node: Node }>()

const emit = defineEmits<{
  /** The download button was used; the browser is sent to the file's download URL. */
  download: [node: Node]
}>()

const client = useVaulterClient()
const { t } = useVaulterI18n()

const documentType = computed(() => props.node.presentation?.document_type ?? null)
const typeUnavailable = computed(() => props.node.presentation?.type_available === false)

// Only a stored file has a byte stream; a document's content is not downloadable as-is.
const canDownload = computed(() => props.node.abilities.download && props.node.kind === 'file')

function download(): void {
  emit('download', props.node)
  if (typeof window !== 'undefined') {
    window.open(client.files.downloadUrl(props.node.id), '_blank', 'noopener')
  }
}
</script>

<template>
  <div class="vaulter-unsupported-node" :data-kind="node.kind" role="group" :aria-label="node.name">
    <p class="vaulter-unsupported-node__name">{{ node.name }}</p>
    <p v-if="documentType !== null" class="vaulter-unsupported-node__type">{{ documentType }}</p>
    <p v-else-if="node.mime !== null" class="vaulter-unsupported-node__type">{{ node.mime }}</p>
    <p class="vaulter-unsupported-node__reason">
      {{
        typeUnavailable && documentType !== null
          ? t('viewer.unsupported.typeUnavailable', { type: documentType })
          : t('viewer.unsupported.noViewer')
      }}
    </p>
    <button v-if="canDownload" type="button" class="vaulter-unsupported-node__download" @click="download">
      {{ t('viewer.download') }}
    </button>
  </div>
</template>

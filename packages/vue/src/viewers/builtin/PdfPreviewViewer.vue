<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useVaulterClient } from '../../composables/useVaulterClient'
import { useVaulterI18n } from '../../i18n'
import type { Node } from '../../api/types'

// `vaulter/pdf-preview`: like the image preview, the PDF is fetched through the authorised
// download endpoint and shown from an object URL (browsers do not render a PDF inside a
// `sandbox`ed frame, so the frame only drops the referrer).
const props = defineProps<{ node: Node }>()

const client = useVaulterClient()
const { t } = useVaulterI18n()

const src = ref<string | null>(null)
const failed = ref(false)

onMounted(async () => {
  if (!props.node.abilities.download) {
    return
  }
  try {
    const blob = await client.files.download(props.node.id)
    src.value = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }))
  } catch {
    failed.value = true
  }
})

onBeforeUnmount(() => {
  if (src.value !== null) {
    URL.revokeObjectURL(src.value)
  }
})
</script>

<template>
  <div class="vaulter-pdf-preview">
    <iframe v-if="src" :src="src" :title="props.node.name" referrerpolicy="no-referrer" />
    <p v-else-if="!props.node.abilities.download" role="status">{{ t('viewer.downloadNotAllowed') }}</p>
    <p v-else-if="failed" role="alert">{{ t('viewer.previewFailed') }}</p>
    <p v-else role="status">{{ t('viewer.loading') }}</p>
  </div>
</template>

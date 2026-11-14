<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useVaulterClient } from '../../composables/useVaulterClient'
import { useVaulterI18n } from '../../i18n'
import type { Node } from '../../api/types'
import { isContentUnavailable, isSignedDownloadRedirect } from '../previewError'

// `vaulter/pdf-preview`: like the image preview, the PDF is fetched through the authorised
// download endpoint and shown from an object URL (browsers do not render a PDF inside a
// `sandbox`ed frame, so the frame only drops the referrer).
// 423 `content_unavailable` is a failed preview, never an object URL of the error body.
// A direct_signed 302 is not fetched further: the download URL is an anchor (fetch would hit CORS).
const props = defineProps<{ node: Node }>()

const client = useVaulterClient()
const { t } = useVaulterI18n()

const src = ref<string | null>(null)
const failed = ref(false)
const useAnchor = ref(false)

onMounted(async () => {
  if (!props.node.abilities.download) {
    return
  }
  try {
    const blob = await client.files.download(props.node.id)
    src.value = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }))
  } catch (error) {
    if (isContentUnavailable(error)) {
      failed.value = true
      return
    }
    if (isSignedDownloadRedirect(error)) {
      useAnchor.value = true
      return
    }
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
    <a
      v-else-if="useAnchor"
      class="vaulter-download-viewer__link"
      :href="client.files.downloadUrl(props.node.id)"
      rel="noopener"
      download
    >{{ t('viewer.download') }}</a>
    <p v-else-if="failed" role="alert">{{ t('viewer.previewFailed') }}</p>
    <p v-else role="status">{{ t('viewer.loading') }}</p>
  </div>
</template>

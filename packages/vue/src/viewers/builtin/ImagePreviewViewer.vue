<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useVaulterClient } from '../../composables/useVaulterClient'
import { useVaulterI18n } from '../../i18n'
import type { Node } from '../../api/types'
import { isContentUnavailable, isSignedDownloadRedirect } from '../previewError'

// `vaulter/image-preview`: the bytes come through the authorised download endpoint into an
// object URL (the response is an attachment, so it cannot be an `<img src>` directly), which
// is why a caller without `download` gets no preview (a previewer sees metadata only).
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
    src.value = URL.createObjectURL(await client.files.download(props.node.id))
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
  <figure class="vaulter-image-preview">
    <img v-if="src" :src="src" :alt="props.node.name">
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
  </figure>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useVaulterClient } from '../../composables/useVaulterClient'
import { useVaulterI18n } from '../../i18n'
import type { Node } from '../../api/types'

// `vaulter/image-preview`: the bytes come through the authorised download endpoint into an
// object URL (the response is an attachment, so it cannot be an `<img src>` directly), which
// is why a caller without `download` gets no preview (a previewer sees metadata only).
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
    src.value = URL.createObjectURL(await client.files.download(props.node.id))
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
  <figure class="vaulter-image-preview">
    <img v-if="src" :src="src" :alt="props.node.name">
    <p v-else-if="!props.node.abilities.download" role="status">{{ t('viewer.downloadNotAllowed') }}</p>
    <p v-else-if="failed" role="alert">{{ t('viewer.previewFailed') }}</p>
    <p v-else role="status">{{ t('viewer.loading') }}</p>
  </figure>
</template>

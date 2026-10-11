<script setup lang="ts">
import { useVaulterClient } from '../../composables/useVaulterClient'
import { useVaulterI18n } from '../../i18n'
import type { Node } from '../../api/types'

// `vaulter/download`: the item has no preview, only its bytes.
const props = defineProps<{ node: Node }>()

const client = useVaulterClient()
const { t } = useVaulterI18n()
</script>

<template>
  <div class="vaulter-download-viewer">
    <p class="vaulter-download-viewer__name">{{ props.node.name }}</p>
    <a
      v-if="props.node.abilities.download"
      class="vaulter-download-viewer__link"
      :href="client.files.downloadUrl(props.node.id)"
      rel="noopener"
      download
    >{{ t('viewer.download') }}</a>
    <p v-else class="vaulter-download-viewer__denied">{{ t('viewer.downloadNotAllowed') }}</p>
  </div>
</template>

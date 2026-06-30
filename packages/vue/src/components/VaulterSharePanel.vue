<script setup lang="ts">
import { onMounted } from 'vue'
import { useShares } from '../composables/useShares'
import type { CreateSharePayload, VaulterShare, DocumentId } from '../types'

const props = withDefaults(
  defineProps<{ documentId: DocumentId, autoLoad?: boolean }>(),
  { autoLoad: true },
)

const emit = defineEmits<{
  created: [share: VaulterShare]
  revoked: [shareId: DocumentId]
}>()

const { shares, loading, list, create, revoke } = useShares(props.documentId)

async function add(payload: CreateSharePayload): Promise<void> {
  const share = await create(payload)
  emit('created', share)
}

async function remove(share: VaulterShare): Promise<void> {
  await revoke(share.id)
  emit('revoked', share.id)
}

onMounted(() => {
  if (props.autoLoad) {
    void list()
  }
})

defineExpose({ list, create: add, revoke: remove, shares, loading })
</script>

<template>
  <div class="vaulter-share-panel">
    <slot v-if="loading" name="loading">Loading…</slot>
    <ul v-else class="vaulter-share-panel__list">
      <li v-for="share in shares" :key="share.id" class="vaulter-share-panel__item">
        <slot name="item" :share="share" :revoke="() => remove(share)">
          {{ share.permission }} · {{ share.token }}
        </slot>
      </li>
    </ul>
  </div>
</template>

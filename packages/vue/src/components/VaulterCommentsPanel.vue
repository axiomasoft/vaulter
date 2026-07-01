<script setup lang="ts">
import { onMounted } from 'vue'
import { useComments } from '../composables/useComments'
import type { VaulterComment, NodeId } from '../types'

const props = withDefaults(
  defineProps<{ nodeId: NodeId, autoLoad?: boolean }>(),
  { autoLoad: true },
)

const emit = defineEmits<{
  added: [comment: VaulterComment]
  resolved: [comment: VaulterComment]
}>()

const { comments, loading, list, add, resolve } = useComments(props.nodeId)

async function submit(body: string): Promise<void> {
  const comment = await add({ body })
  emit('added', comment)
}

async function markResolved(comment: VaulterComment): Promise<void> {
  const updated = await resolve(comment.id)
  emit('resolved', updated)
}

onMounted(() => {
  if (props.autoLoad) {
    void list()
  }
})

defineExpose({ list, add: submit, resolve: markResolved, comments, loading })
</script>

<template>
  <div class="vaulter-comments-panel">
    <slot v-if="loading" name="loading">Loading…</slot>
    <ul v-else class="vaulter-comments-panel__list">
      <li
        v-for="comment in comments"
        :key="comment.id"
        class="vaulter-comments-panel__item"
        :data-resolved="comment.resolved_at !== null"
      >
        <slot name="item" :comment="comment" :resolve="() => markResolved(comment)">
          {{ comment.author.type }}#{{ comment.author.id }}: {{ comment.body }}
        </slot>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useComments } from '../composables/useComments'
import type { Comment, NodeId } from '../api/types'

const props = withDefaults(
  defineProps<{ nodeId: NodeId, autoLoad?: boolean }>(),
  { autoLoad: true },
)

const emit = defineEmits<{
  added: [comment: Comment]
  resolved: [comment: Comment]
}>()

const { comments, loading, error, cursor, list, loadMore, add, resolve } = useComments(props.nodeId)

async function submit(body: string): Promise<void> {
  const comment = await add({ body })
  emit('added', comment)
}

async function markResolved(comment: Comment): Promise<void> {
  const updated = await resolve(comment.id)
  emit('resolved', updated)
}

onMounted(() => {
  if (props.autoLoad) {
    // A failure is captured in `error` and shown through the error slot.
    void list().catch(() => undefined)
  }
})

defineExpose({ list, loadMore, add: submit, resolve: markResolved, comments, loading, error })
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
    <p v-if="error" class="vaulter-comments-panel__error" role="alert">
      <slot name="error" :error="error">Could not load comments.</slot>
    </p>
    <button
      v-if="cursor?.next_cursor"
      type="button"
      class="vaulter-comments-panel__more"
      @click="loadMore"
    >
      <slot name="load-more">Load more</slot>
    </button>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useSharedNodes } from '../composables/useSharedNodes'
import VaulterNodeList, { type NodeAction } from './VaulterNodeList.vue'
import type { Node, SharedNodesQuery } from '../types'

// The cross-storage "shared with me" view: a FLAT listing of the nodes shared with
// the actor (the access-aware `GET /shared` endpoint). Unlike VaulterDrive there is
// no folder navigation — every open emits `open-node` for the host to route (the
// nodes span storages, so the host decides where each one opens). Reuses
// VaulterNodeList for the rendering (icon/name/size + per-node-ability actions) and
// owns only the load/refresh/empty/error chrome. Headless: minimal scoped styles,
// class hooks + slots throughout.
const props = withDefaults(
  defineProps<{
    viewMode?: 'list' | 'grid'
    /** Row actions to offer (still filtered per node abilities by the list). */
    actions?: NodeAction[]
    /** Initial page / page size for the length-aware listing. */
    query?: SharedNodesQuery
  }>(),
  {
    viewMode: 'list',
    actions: () => ['download', 'share'],
    query: undefined,
  },
)

const emit = defineEmits<{
  /** A shared node was opened — the host opens/edits/previews it (cross-storage). */
  'open-node': [node: Node]
  /** A shared node was selected (single-click). */
  'node-selected': [node: Node]
  /** A row action was invoked on a shared node. */
  'action': [action: NodeAction, node: Node]
}>()

const { nodes, loading, error, hasMore, refresh, loadMore } = useSharedNodes(props.query)

function onOpen(node: Node): void {
  emit('open-node', node)
}

function onSelect(node: Node): void {
  emit('node-selected', node)
}

function onAction(action: NodeAction, node: Node): void {
  emit('action', action, node)
}

onMounted(() => {
  // A first-load failure surfaces through `error` — don't reject the mount.
  void refresh().catch(() => undefined)
})

defineExpose({ refresh, loadMore })
</script>

<template>
  <div class="vaulter-shared" aria-label="Shared with me">
    <div class="vaulter-shared__toolbar">
      <slot name="toolbar" :refresh="refresh" :loading="loading">
        <h2 class="vaulter-shared__title">
          <slot name="title">Shared with me</slot>
        </h2>
        <button
          type="button"
          class="vaulter-shared__refresh"
          :disabled="loading"
          aria-label="Refresh shared items"
          @click="refresh"
        >
          <slot name="refresh-label">Refresh</slot>
        </button>
      </slot>
    </div>

    <p v-if="error" class="vaulter-shared__error" role="alert">
      <slot name="error" :error="error" :refresh="refresh">Could not load shared items.</slot>
    </p>

    <VaulterNodeList
      class="vaulter-shared__list"
      :nodes="nodes"
      :view-mode="viewMode"
      :actions="actions"
      :loading="loading"
      @select="onSelect"
      @open="onOpen"
      @action="onAction"
    >
      <template #empty>
        <slot name="empty">
          <p class="vaulter-shared__empty">Nothing has been shared with you yet.</p>
        </slot>
      </template>
      <!-- Surface the storage the node lives in (the only cross-storage context the
           /shared payload carries — breadcrumb is attached only on `show`). Hosts can
           override the `name` slot to resolve a storage label / richer breadcrumb. -->
      <template #name="{ node }">
        <slot name="name" :node="node">
          <span class="vaulter-shared__name">{{ node.name }}</span>
          <span class="vaulter-shared__context" :data-storage="node.storage_id">
            <slot name="context" :node="node">{{ node.kind }}</slot>
          </span>
        </slot>
      </template>
    </VaulterNodeList>

    <div v-if="hasMore" class="vaulter-shared__more">
      <slot name="load-more" :load-more="loadMore" :loading="loading">
        <button
          type="button"
          class="vaulter-shared__load-more"
          :disabled="loading"
          @click="loadMore"
        >
          Load more
        </button>
      </slot>
    </div>
  </div>
</template>

<style scoped>
.vaulter-shared__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.vaulter-shared__context {
  margin-inline-start: 0.5rem;
  opacity: 0.6;
  font-size: 0.85em;
}
</style>

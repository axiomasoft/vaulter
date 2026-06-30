<script setup lang="ts">
import type { Node, NodeId } from '../types'

// Drive breadcrumb: the root → current-node ancestry as clickable crumbs. Fed the
// node's `breadcrumb` (root-first, the node itself last). Navigation is emit-only —
// the host (VaulterDrive) decides what `navigate(null=root)` does. Headless: a
// `crumb` slot per ancestor lets hosts restyle.
withDefaults(
  defineProps<{
    /** Root → current ancestry (a node's `breadcrumb`). Empty = at the root. */
    path?: Node[]
    /** The label of the root crumb. */
    rootLabel?: string
  }>(),
  { path: () => [], rootLabel: 'Drive' },
)

const emit = defineEmits<{
  /** Navigate to an ancestor (or `null` for the storage root). */
  navigate: [nodeId: NodeId | null]
}>()
</script>

<template>
  <nav class="vaulter-drive-breadcrumb" aria-label="Breadcrumb">
    <button
      type="button"
      class="vaulter-drive-breadcrumb__root vaulter-drive-breadcrumb__crumb"
      @click="emit('navigate', null)"
    >
      <slot name="root">{{ rootLabel }}</slot>
    </button>
    <template v-for="(node, index) in path" :key="node.id">
      <span class="vaulter-drive-breadcrumb__sep" aria-hidden="true">/</span>
      <button
        type="button"
        class="vaulter-drive-breadcrumb__crumb"
        :aria-current="index === path.length - 1 ? 'page' : undefined"
        @click="emit('navigate', node.id)"
      >
        <slot name="crumb" :node="node" :is-current="index === path.length - 1">{{ node.name }}</slot>
      </button>
    </template>
  </nav>
</template>

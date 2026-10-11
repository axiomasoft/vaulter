<script setup lang="ts">
import type { NodeId } from '../api/types'

// Drive breadcrumb: the root → current-node ancestry as clickable crumbs. Fed the node's
// `breadcrumb` (`include=breadcrumb`: only the ancestors the caller may see, root-first).
// Navigation is emit-only — the host (VaulterDrive) decides what `navigate(null=root)` does.
// Headless: a `crumb` slot per ancestor lets hosts restyle.
withDefaults(
  defineProps<{
    /** Root → current ancestry. Empty = at the root. */
    path?: Array<{ id: NodeId, name: string }>
    /** The label of the root crumb. */
    rootLabel?: string
  }>(),
  { path: () => [], rootLabel: 'Drive' },
)

const emit = defineEmits<{
  /** Navigate to an ancestor (or `null` for the drive root). */
  navigate: [nodeId: NodeId | null]
}>()
</script>

<template>
  <nav class="vaulter-breadcrumb" aria-label="Breadcrumb">
    <button
      type="button"
      class="vaulter-breadcrumb__root vaulter-breadcrumb__crumb"
      @click="emit('navigate', null)"
    >
      <slot name="root">{{ rootLabel }}</slot>
    </button>
    <template v-for="(crumb, index) in path" :key="crumb.id">
      <span class="vaulter-breadcrumb__sep" aria-hidden="true">/</span>
      <button
        type="button"
        class="vaulter-breadcrumb__crumb"
        :aria-current="index === path.length - 1 ? 'page' : undefined"
        @click="emit('navigate', crumb.id)"
      >
        <slot name="crumb" :node="crumb" :is-current="index === path.length - 1">{{ crumb.name }}</slot>
      </button>
    </template>
  </nav>
</template>

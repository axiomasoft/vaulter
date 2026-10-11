<script setup lang="ts">
import { computed } from 'vue'
import VaulterUnsupportedNode from '../components/VaulterUnsupportedNode.vue'
import type { Node } from '../api/types'
import { VIEWER_KEYS } from './builtin'
import { useViewerRegistry } from './registry'

// Mounts the viewer the server chose for a node (`presentation.viewer.key`). A node without
// a key, with a key this application never registered, or of a document type this
// application cannot handle (`type_available = false`) falls back to the unsupported
// viewer — the item is still shown, named, and downloadable when the caller may.
const props = withDefaults(
  defineProps<{
    node: Node
    readonly?: boolean
  }>(),
  { readonly: false },
)

const registry = useViewerRegistry()

const viewer = computed(() => {
  const presentation = props.node.presentation
  const key = presentation?.viewer.key ?? null

  if (presentation !== null && presentation.type_available && key !== null) {
    const registered = registry.resolve(key)
    if (registered !== null) {
      return registered
    }
  }

  // A host may replace the fallback itself, like any other key.
  return registry.resolve(VIEWER_KEYS.unsupported) ?? VaulterUnsupportedNode
})

// The server's viewer props go first so they can never override the node or the mode.
const viewerProps = computed(() => ({
  ...(props.node.presentation?.viewer.props ?? {}),
  node: props.node,
  readonly: props.readonly,
}))
</script>

<template>
  <component :is="viewer" v-bind="viewerProps" class="vaulter-viewer" />
</template>

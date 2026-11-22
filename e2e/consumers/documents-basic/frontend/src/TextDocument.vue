<script setup lang="ts">
import { VaulterDocumentNode, type Node } from '@vaulter/vue'
import TipTapEditor from './TipTapEditor.vue'

defineProps<{
  node: Node
  readonly?: boolean
  preset: string
}>()
</script>

<template>
  <section class="text-document" :data-preset="preset">
    <VaulterDocumentNode :node="node" :readonly="readonly" :debounce-ms="30000" v-slot="slotProps">
      <TipTapEditor
        v-if="slotProps.content"
        :content="slotProps.content"
        :readonly="slotProps.readonly"
        :preset="preset"
        :set-content="slotProps.setContent"
        :flush="slotProps.flush"
        :conflict="slotProps.conflict"
      />
    </VaulterDocumentNode>
  </section>
</template>

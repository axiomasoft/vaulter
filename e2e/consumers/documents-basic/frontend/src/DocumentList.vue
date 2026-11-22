<script setup lang="ts">
import { ref } from 'vue'
import { VaulterDrive, VaulterViewer, type Node } from '@vaulter/vue'

defineProps<{
  driveId: string
}>()

const opened = ref<Node | null>(null)

function onOpen(node: Node): void {
  opened.value = node
}

function backToList(): void {
  opened.value = null
}
</script>

<template>
  <div v-if="opened" class="document-open" :data-open-node="opened.id">
    <button type="button" @click="backToList">Back to list</button>
    <VaulterViewer :node="opened" />
  </div>
  <VaulterDrive v-else :drive-id="driveId" @open-node="onOpen" />
</template>

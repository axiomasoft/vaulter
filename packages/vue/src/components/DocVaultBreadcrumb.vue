<script setup>
// Folder navigation breadcrumbs.
// Skeleton: props/events per docvault-spec.md §8.5.
defineProps({
  path: { type: Array, default: () => [] }, // array of folders
  current: { type: Object, default: null }, // current document/folder
})

defineEmits(['navigate'])
</script>

<template>
  <nav class="docvault-breadcrumb">
    <!-- TODO (M2): render path segments with @navigate handlers -->
    <slot>DocVault breadcrumb (skeleton)</slot>
  </nav>
</template>

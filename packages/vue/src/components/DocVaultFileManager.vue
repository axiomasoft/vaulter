<script setup>
// File manager — main entry point for users (Google Drive style).
// Skeleton: props/events declared per docvault-spec.md §8.1; behaviour added later.
defineProps({
  workspaceType: { type: String, required: true },
  workspaceId: { type: Number, required: true },
  baseUrl: { type: String, default: '/api/docvault' },
  viewMode: { type: String, default: 'list' }, // 'grid' | 'list'
  allowedTypes: { type: Array, default: () => [] },
})

defineEmits(['open-document', 'create-document', 'folder-changed'])
</script>

<template>
  <div class="docvault-file-manager">
    <!-- TODO (M2): folder sidebar, document list/grid, search, drag & drop -->
    <slot>DocVault file manager (skeleton)</slot>
  </div>
</template>

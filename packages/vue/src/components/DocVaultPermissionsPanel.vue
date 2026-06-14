<script setup>
// Document access management panel.
// Skeleton: props per docvault-spec.md §8.4.
defineProps({
  documentId: { type: Number, required: true },
  baseUrl: { type: String, default: '/api/docvault' },
  resolveSubject: { type: Function, default: null }, // callback to search users/roles
})

defineEmits(['granted', 'revoked'])
</script>

<template>
  <div class="docvault-permissions-panel">
    <!-- TODO (M4): subject table, level select (view/comment/edit/manage), autocomplete -->
    <slot>DocVault permissions panel (skeleton)</slot>
  </div>
</template>

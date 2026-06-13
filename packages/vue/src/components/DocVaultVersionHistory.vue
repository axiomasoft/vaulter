<script setup>
// Side panel listing a document's version history.
// Skeleton: props/events per docvault-spec.md §8.3.
defineProps({
  documentId: { type: Number, required: true },
  baseUrl: { type: String, default: '/api/docvault' },
})

defineEmits(['restore', 'preview'])
</script>

<template>
  <div class="docvault-version-history">
    <!-- TODO (M2): version list (date, author, label), diff preview, restore -->
    <slot>DocVault version history (skeleton)</slot>
  </div>
</template>

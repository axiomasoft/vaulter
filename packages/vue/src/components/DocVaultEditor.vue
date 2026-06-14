<script setup>
// TipTap wrapper with optional Yjs collaborative editing.
// Skeleton: props/events per docvault-spec.md §8.2; editor wiring added later.
defineProps({
  documentId: { type: Number, required: true },
  collaborative: { type: Boolean, default: true },
  readonly: { type: Boolean, default: false },
  toolbar: { type: Array, default: () => [] },
  baseUrl: { type: String, default: '/api/docvault' },
})

defineEmits(['save', 'collaborators-changed', 'version-created'])
</script>

<template>
  <div class="docvault-editor">
    <!-- TODO (M2/M3): TipTap toolbar, collaborator avatars, autosave, version snapshots -->
    <slot>DocVault editor (skeleton)</slot>
  </div>
</template>

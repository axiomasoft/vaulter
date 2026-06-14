<script setup lang="ts">
import type { DocVaultFolder } from '../types'

withDefaults(
  defineProps<{ path?: DocVaultFolder[], current?: DocVaultFolder | null }>(),
  { path: () => [], current: null },
)

const emit = defineEmits<{
  navigate: [folder: DocVaultFolder | null]
}>()
</script>

<template>
  <nav class="docvault-breadcrumb">
    <button type="button" class="docvault-breadcrumb__root" @click="emit('navigate', null)">
      <slot name="root">Root</slot>
    </button>
    <span v-for="folder in path" :key="folder.id" class="docvault-breadcrumb__crumb">
      <span class="docvault-breadcrumb__sep">/</span>
      <button type="button" @click="emit('navigate', folder)">{{ folder.name }}</button>
    </span>
  </nav>
</template>

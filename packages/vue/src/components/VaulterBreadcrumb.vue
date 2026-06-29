<script setup lang="ts">
import type { VaulterFolder } from '../types'

withDefaults(
  defineProps<{ path?: VaulterFolder[], current?: VaulterFolder | null }>(),
  { path: () => [], current: null },
)

const emit = defineEmits<{
  navigate: [folder: VaulterFolder | null]
}>()
</script>

<template>
  <nav class="vaulter-breadcrumb">
    <button type="button" class="vaulter-breadcrumb__root" @click="emit('navigate', null)">
      <slot name="root">Root</slot>
    </button>
    <span v-for="folder in path" :key="folder.id" class="vaulter-breadcrumb__crumb">
      <span class="vaulter-breadcrumb__sep">/</span>
      <button type="button" @click="emit('navigate', folder)">{{ folder.name }}</button>
    </span>
  </nav>
</template>

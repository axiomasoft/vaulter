<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Editor, type JSONContent } from '@tiptap/core'
import { extensionsFor } from './extensions'

const props = defineProps<{
  content: JSONContent
  readonly: boolean
  preset: string
  setContent: (content: JSONContent) => void
  flush: () => Promise<unknown>
  conflict: unknown
}>()

const element = ref<HTMLElement>()
const ready = ref(false)
const saving = ref(false)
let editor: Editor | null = null
let hydrating = true
let echoQueued = false

interface DocumentsBasicHook {
  getJSON: () => JSONContent | null
  preset: string
}

declare global {
  interface Window {
    __documentsBasic?: DocumentsBasicHook
  }
}

onMounted(() => {
  hydrating = true
  editor = new Editor({
    element: element.value,
    content: props.content,
    editable: !props.readonly,
    extensions: extensionsFor(props.preset),
    onUpdate: () => {
      if (hydrating || props.readonly || echoQueued) {
        return
      }
      echoQueued = true
      queueMicrotask(() => {
        echoQueued = false
        if (hydrating || props.readonly || editor === null) {
          return
        }
        props.setContent(editor.getJSON())
      })
    },
  })
  queueMicrotask(() => {
    hydrating = false
    ready.value = true
    window.__documentsBasic = {
      getJSON: () => editor?.getJSON() ?? null,
      preset: props.preset,
    }
  })
})

onBeforeUnmount(() => {
  editor?.destroy()
  editor = null
  if (window.__documentsBasic?.preset === props.preset) {
    delete window.__documentsBasic
  }
})

async function save(): Promise<void> {
  if (editor === null || props.readonly) {
    return
  }
  props.setContent(editor.getJSON())
  saving.value = true
  try {
    await props.flush()
  } catch {
    // VaulterDocumentNode shows the conflict or the load error.
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="tiptap-editor" :data-editor-ready="ready ? 'true' : 'false'" :data-preset="preset" :data-conflict="conflict ? 'true' : 'false'">
    <div ref="element"></div>
    <button class="text-document__save" type="button" :disabled="readonly || saving" @click="save">Save</button>
  </div>
</template>

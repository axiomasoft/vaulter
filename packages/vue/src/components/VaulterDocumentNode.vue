<script setup lang="ts">
import { computed, markRaw, onMounted, type Component } from 'vue'
import { useDocumentNode } from '../composables/useDocumentNode'
import type { HandlerDescriptor, NodeId, TipTapDocument } from '../types'

// The bridge from a document node's HANDLER REGISTRY descriptor to a concrete
// editor/preview UI. It loads the handler (`useDocumentNode.loadHandler`) and the
// head content, then mounts the component the handler's `ui.component` maps to.
// The mapping is a prop (`componentMap`) so hosts register their own editors per
// handler/ui-component id; the built-in TipTap-editor keys resolve to a host-
// supplied editor (typically the slice-1 VaulterEditor, passed in). Anything
// unmapped falls back to a preview/download surface.

/**
 * A mountable editor for a document node. It receives the loaded `content`, the
 * `handler` descriptor and an `onChange` it calls with new content (the parent
 * schedules the debounced autosave). Hosts may also bind via the default slot.
 */
export interface DocumentNodeEditorProps {
  nodeId: NodeId
  content: TipTapDocument | null
  handler: HandlerDescriptor
  readonly: boolean
  onChange: (content: TipTapDocument) => void
}

const props = withDefaults(
  defineProps<{
    nodeId: NodeId
    /**
     * Maps a handler's `ui.component` string (or the handler `id`) to the editor
     * component to mount. The default set of TipTap-editor identifiers
     * ({@link TIPTAP_COMPONENTS}) is recognised; hosts extend/override here.
     */
    componentMap?: Record<string, Component>
    readonly?: boolean
    autoLoad?: boolean
    debounceMs?: number
  }>(),
  {
    componentMap: () => ({}),
    readonly: false,
    autoLoad: true,
    debounceMs: 800,
  },
)

const emit = defineEmits<{
  /** The handler resolved — useful for hosts to react to capabilities. */
  ready: [handler: HandlerDescriptor]
  /** A new content version was persisted (autosave or flush). */
  saved: [content: TipTapDocument]
}>()

/** The handler `ui.component` identifiers that mean "the TipTap rich-text editor". */
const TIPTAP_COMPONENTS = ['VaulterEditor', 'tiptap', 'richtext', 'rich-text']

const {
  content, handler, loading, saving, error,
  load, setContent, flush, loadHandler,
} = useDocumentNode(props.nodeId, { debounceMs: props.debounceMs })

/**
 * Resolve which component to mount: look up the handler's `ui.component` (then its
 * `id`) in the host `componentMap`. If neither maps but the ui.component is a known
 * TipTap key, the host MUST have provided a TipTap editor under one of those keys —
 * otherwise we fall back to the preview/download slot.
 */
const resolved = computed<{ component: Component | null, isTipTap: boolean }>(() => {
  const h = handler.value
  if (!h) {
    return { component: null, isTipTap: false }
  }
  const uiKey = h.ui.component
  const mapped = props.componentMap[uiKey] ?? props.componentMap[h.id] ?? null
  const isTipTap = TIPTAP_COMPONENTS.includes(uiKey)
  // `markRaw` keeps the component out of the reactivity system (it may arrive via
  // a reactive prop) — avoids the "reactive Component" perf warning.
  return { component: mapped ? markRaw(mapped) : null, isTipTap }
})

const editorProps = computed<DocumentNodeEditorProps | null>(() => {
  if (!handler.value) {
    return null
  }
  return {
    nodeId: props.nodeId,
    content: content.value,
    handler: handler.value,
    readonly: props.readonly,
    onChange,
  }
})

function onChange(next: TipTapDocument): void {
  if (props.readonly) {
    return
  }
  setContent(next)
}

async function persist(): Promise<void> {
  const version = await flush()
  if (version && content.value) {
    emit('saved', content.value)
  }
}

async function loadAll(): Promise<void> {
  const descriptor = await loadHandler()
  await load()
  emit('ready', descriptor)
}

onMounted(() => {
  if (props.autoLoad) {
    void loadAll()
  }
})

defineExpose({ load: loadAll, flush: persist, content, handler, saving, error, loading })
</script>

<template>
  <div class="vaulter-document-node" :data-handler="handler?.id">
    <slot v-if="loading" name="loading">Loading…</slot>

    <template v-else-if="handler && editorProps">
      <!-- A mapped editor (e.g. the TipTap VaulterEditor the host registered). -->
      <component
        :is="resolved.component"
        v-if="resolved.component"
        v-bind="editorProps"
      />

      <!-- The default slot lets a host mount its editor inline instead of via the
           map, still receiving the loaded content + change handler. -->
      <slot
        v-else
        :handler="handler"
        :content="content"
        :readonly="readonly"
        :set-content="onChange"
        :flush="persist"
      >
        <!-- Fallback: a preview/download surface for unmapped handlers. -->
        <div class="vaulter-document-node__fallback" :data-capabilities="handler.id">
          <slot name="fallback" :handler="handler" :content="content">
            <p class="vaulter-document-node__fallback-note">
              No editor is registered for this document type
              (<code>{{ handler.ui.component }}</code>).
            </p>
          </slot>
        </div>
      </slot>
    </template>

    <p v-if="error" class="vaulter-document-node__error" role="alert">
      <slot name="error" :error="error">Could not load this document.</slot>
    </p>
  </div>
</template>

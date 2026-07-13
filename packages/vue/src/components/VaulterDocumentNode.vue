<script setup lang="ts">
import { computed, markRaw, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue'
import type { Doc as YDoc } from 'yjs'
import { useDocumentNode } from '../composables/useDocumentNode'
import { useNodeCollaboration } from '../composables/useNodeCollaboration'
import { resolveEngineComponent } from '../engineRegistry'
import type { Collaborator, CreateNodeCollaborationOptions } from '../nodeCollaboration'
import type { HandlerDescriptor, NodeId, TipTapDocument } from '../types'

// The bridge from a document node's HANDLER REGISTRY descriptor to a concrete
// editor/preview UI. It loads the handler (`useDocumentNode.loadHandler`) and the
// head content, then mounts the component the handler's `ui.component` maps to.
// Two mapping sources exist: the `componentMap` prop (checked first, for a scoped
// mapping local to one mount point) and the global `registerEngineComponent` registry
// (`../engineRegistry`, checked as a fallback — a host calls it once at startup for
// engines shared across every mount point). The built-in TipTap-editor keys resolve
// to a host-supplied editor (typically the slice-1 VaulterEditor) registered through
// either source. Anything unmapped falls back to a preview/download surface, and in
// dev mode logs a console warning naming the unmapped `ui.component`/`id`.
//
// M4b: when the handler is COLLABORATIVE and collaboration is enabled, the editor is
// mounted in collaborative mode — a Yjs session (useNodeCollaboration) is opened and
// its shared `Y.Doc` is handed to the editor (bind it to TipTap via the Collaboration
// extension); the debounced autosave path is bypassed (the Yjs binary is persisted
// server-side via the hub). Otherwise the M4a non-collab autosave path is used. It is
// a clean toggle: the host still supplies the actual editor via the componentMap.

/**
 * A mountable editor for a document node. It receives the loaded `content`, the
 * `handler` descriptor and an `onChange` it calls with new content (the parent
 * schedules the debounced autosave). In COLLABORATIVE mode it additionally receives
 * `collaborative: true` plus the shared `ydoc` + presence/connection state; bind the
 * doc to TipTap via the Collaboration extension and treat `onChange` as a no-op (Yjs
 * drives persistence). Hosts may also bind via the default slot.
 */
export interface DocumentNodeEditorProps {
  nodeId: NodeId
  content: TipTapDocument | null
  handler: HandlerDescriptor
  readonly: boolean
  onChange: (content: TipTapDocument) => void
  /** True when the editor should run in collaborative (Yjs) mode. */
  collaborative?: boolean
  /** The shared Yjs document to bind to TipTap (collaborative mode only). */
  ydoc?: YDoc | null
  /** A live connection flag for the collaborative session. */
  connected?: boolean
  /** The remote participants (awareness) for the collaborative session. */
  collaborators?: Collaborator[]
}

const props = withDefaults(
  defineProps<{
    nodeId: NodeId
    /**
     * Maps a handler's `ui.component` string (or the handler `id`) to the editor
     * component to mount. Checked BEFORE the global `registerEngineComponent`
     * registry (`../engineRegistry`) — a local override always wins. The default
     * set of TipTap-editor identifiers ({@link TIPTAP_COMPONENTS}) is recognised;
     * hosts extend/override here.
     */
    componentMap?: Record<string, Component>
    readonly?: boolean
    autoLoad?: boolean
    debounceMs?: number
    /**
     * Enable realtime collaboration when the handler declares it
     * (`capabilities.collaborative`). Default true — set false to force the autosave
     * path even for a collaborative handler.
     */
    collaboration?: boolean
    /**
     * Provider factories for the collaboration session (Hocuspocus override / the
     * Reverb Echo↔Yjs bridge). Forwarded to `useNodeCollaboration`.
     */
    collaborationProviders?: Partial<Omit<CreateNodeCollaborationOptions, 'doc'>>
  }>(),
  {
    componentMap: () => ({}),
    readonly: false,
    autoLoad: true,
    debounceMs: 800,
    collaboration: true,
    collaborationProviders: () => ({}),
  },
)

const emit = defineEmits<{
  /** The handler resolved — useful for hosts to react to capabilities. */
  ready: [handler: HandlerDescriptor]
  /** A new content version was persisted (autosave or flush). */
  saved: [content: TipTapDocument]
  /** A collaborative session was opened (collaborative mode only). */
  collaborating: [readonly: boolean]
}>()

/** The handler `ui.component` identifiers that mean "the TipTap rich-text editor". */
const TIPTAP_COMPONENTS = ['VaulterEditor', 'tiptap', 'richtext', 'rich-text']

const {
  content, handler, loading, saving, error,
  load, setContent, flush, loadHandler,
} = useDocumentNode(props.nodeId, { debounceMs: props.debounceMs })

// The collaboration session is created lazily — only when the resolved handler is
// collaborative AND collaboration is enabled. Until then everything behaves as M4a.
const collab = useNodeCollaboration(props.nodeId, { providers: props.collaborationProviders })
const collaborative = ref(false)

/**
 * Resolve which component to mount: look up the handler's `ui.component` (then its
 * `id`) first in the host `componentMap` prop, then in the global
 * `registerEngineComponent` registry. If neither maps but the ui.component is a
 * known TipTap key, the host MUST have provided a TipTap editor under one of those
 * keys — otherwise we fall back to the preview/download slot (the `watch` below
 * additionally warns once per unmapped handler in dev mode).
 */
const warnedUnmapped = new Set<string>()

/**
 * True outside a production build. Avoids depending on Vite-specific
 * `import.meta.env` typings (this package ships as a plain library, not a Vite
 * app) — `process.env.NODE_ENV` is the bundler-agnostic convention every major
 * build tool (Vite, webpack, Rollup) replaces at build time.
 */
function isDevMode(): boolean {
  return typeof process === 'undefined' || process.env?.NODE_ENV !== 'production'
}

const resolved = computed<{ component: Component | null, isTipTap: boolean }>(() => {
  const h = handler.value
  if (!h) {
    return { component: null, isTipTap: false }
  }
  const uiKey = h.ui.component
  const mapped = props.componentMap[uiKey]
    ?? props.componentMap[h.id]
    ?? resolveEngineComponent(uiKey)
    ?? resolveEngineComponent(h.id)
    ?? null
  const isTipTap = TIPTAP_COMPONENTS.includes(uiKey)
  // `markRaw` keeps the component out of the reactivity system (it may arrive via
  // a reactive prop) — avoids the "reactive Component" perf warning.
  return { component: mapped ? markRaw(mapped) : null, isTipTap }
})

// The dev-mode warning is a side effect, so it runs in a `watch` rather than inside
// the `resolved` computed above — a computed getter must stay pure (it may be
// re-evaluated speculatively by devtools/Suspense without that meaning "the UI
// actually rendered this state").
watch([handler, resolved], ([h, r]) => {
  if (!h || r.component || !isDevMode() || warnedUnmapped.has(h.ui.component)) {
    return
  }
  warnedUnmapped.add(h.ui.component)
  console.warn(
    `[VaulterDocumentNode] No editor registered for handler "${h.id}" `
    + `(ui.component: "${h.ui.component}"). Register one via the "componentMap" prop or `
    + `"registerEngineComponent('${h.ui.component}', YourComponent)" from "@vaulter/vue".`,
  )
})

/** Read-only is forced by the prop OR by a read-only collaboration session. */
const isReadonly = computed<boolean>(() => props.readonly || (collaborative.value && collab.readOnly.value))

const editorProps = computed<DocumentNodeEditorProps | null>(() => {
  if (!handler.value) {
    return null
  }
  const base: DocumentNodeEditorProps = {
    nodeId: props.nodeId,
    content: content.value,
    handler: handler.value,
    readonly: isReadonly.value,
    onChange,
  }
  if (collaborative.value) {
    // Collaborative mode: hand the shared doc + presence; Yjs drives persistence so
    // onChange is inert (kept for prop-shape stability).
    base.collaborative = true
    base.ydoc = collab.doc
    base.connected = collab.connected.value
    base.collaborators = collab.collaborators.value
  }
  return base
})

function onChange(next: TipTapDocument): void {
  // In collaborative mode the Yjs session persists changes (no client autosave); in
  // read-only mode there is nothing to persist.
  if (isReadonly.value || collaborative.value) {
    return
  }
  setContent(next)
}

async function persist(): Promise<void> {
  if (collaborative.value) {
    return
  }
  const version = await flush()
  if (version && content.value) {
    emit('saved', content.value)
  }
}

/** True when the resolved handler is collaborative and collaboration is enabled. */
function handlerIsCollaborative(descriptor: HandlerDescriptor): boolean {
  return props.collaboration && descriptor.capabilities.collaborative === true
}

async function loadAll(): Promise<void> {
  const descriptor = await loadHandler()

  // Decide the path BEFORE loading content: a collaborative handler opens a Yjs
  // session and skips the content fetch's role as the editing source (the doc is the
  // source). We still load the head content so a fallback/preview has something.
  if (handlerIsCollaborative(descriptor)) {
    try {
      await collab.connect()
      collaborative.value = true
      emit('collaborating', collab.readOnly.value)
    } catch {
      // The token handshake failed (collab unavailable / not configured) — fall back
      // to the M4a autosave path rather than blocking the editor.
      collaborative.value = false
    }
  }

  await load()
  emit('ready', descriptor)
}

onMounted(() => {
  if (props.autoLoad) {
    void loadAll()
  }
})

onBeforeUnmount(() => {
  collab.disconnect()
})

defineExpose({
  load: loadAll,
  flush: persist,
  content,
  handler,
  saving,
  error,
  loading,
  collaborative,
  connected: collab.connected,
  collaborators: collab.collaborators,
})
</script>

<template>
  <div
    class="vaulter-document-node"
    :data-handler="handler?.id"
    :data-collaborative="collaborative ? 'true' : 'false'"
  >
    <slot v-if="loading" name="loading">Loading…</slot>

    <template v-else-if="handler && editorProps">
      <!-- A mapped editor (e.g. the TipTap VaulterEditor the host registered). -->
      <component
        :is="resolved.component"
        v-if="resolved.component"
        v-bind="editorProps"
      />

      <!-- The default slot lets a host mount its editor inline instead of via the
           map, still receiving the loaded content + change handler (+ collab state). -->
      <slot
        v-else
        :handler="handler"
        :content="content"
        :readonly="isReadonly"
        :set-content="onChange"
        :flush="persist"
        :collaborative="collaborative"
        :ydoc="collaborative ? collab.doc : null"
        :connected="collab.connected.value"
        :collaborators="collab.collaborators.value"
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

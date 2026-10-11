<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { VaulterApiError } from '../api/errors'
import type { DocumentBody, Node } from '../api/types'
import { CollaborationCancelledError, useCollaboration } from '../composables/useCollaboration'
import { useDocument } from '../composables/useDocument'
import type { CollaborationOptions } from '../collaboration/session'

// The headless editing shell of a document node (the built-in `vaulter/tiptap` viewer): it
// loads the head content with its ETag, runs the debounced autosave, and — when the node's
// presentation says it is collaborative and collaboration is enabled — opens a Yjs session.
// The EDITOR is the host's: fill the default slot (or register your own viewer under the
// key), bind `ydoc` to TipTap via the Collaboration extension in collaborative mode, and
// call `set-content` otherwise.
//
// In collaborative mode the debounced autosave is bypassed (the hub persists the Yjs state)
// and the slot gets NO `content`: the hub seeds the room once on the server, and an editor
// that also set the JSON would duplicate it. When the hub ends the room's generation (a
// restore), `ydoc` is replaced by a new document — rebind to it — and the content (and its
// ETag) is re-read. Without collaboration the autosave path is used, but ONLY when the
// session endpoint answers 409 collaboration_unavailable (the contract for "not offered
// here"). Any other session failure (network, 429, 5xx) leaves the editor read-only and emits
// `collaboration-failed`: autosaving into a document that has a Yjs state would reset it and
// throw the other participants out.
const props = withDefaults(
  defineProps<{
    node: Node
    readonly?: boolean
    autoLoad?: boolean
    debounceMs?: number
    /**
     * Enable realtime collaboration when the node's presentation declares it
     * (`capabilities.collaborative`). Default true — set false to force the autosave path.
     */
    collaboration?: boolean
    /** Provider factory override for the collaboration session. */
    collaborationProviders?: CollaborationOptions['providers']
    /**
     * The document schema version the host's editor supports. A collaboration session in a
     * newer schema opens read-only and emits `schema-outdated`.
     */
    supportedSchemaVersion?: number
  }>(),
  {
    readonly: false,
    autoLoad: true,
    debounceMs: 800,
    collaboration: true,
    collaborationProviders: () => ({}),
    supportedSchemaVersion: undefined,
  },
)

const emit = defineEmits<{
  /** The head content was loaded. */
  ready: [node: Node]
  /** A new content version was persisted (autosave or flush). */
  saved: [content: DocumentBody]
  /** A collaborative session was opened (collaborative mode only). */
  collaborating: [readonly: boolean]
  /** The document is in a newer schema than the editor supports: update the application. */
  'schema-outdated': [schemaVersion: number]
  /** The collaboration session failed for a transient/unknown reason; the editor is read-only (call `load()` to retry). */
  'collaboration-failed': [error: unknown]
  /** A save lost the race: the server's version was re-read, the local edits are kept. */
  conflict: [error: VaulterApiError]
}>()

const { content, loading, saving, error, conflict, load: loadContent, setContent, flush } = useDocument(
  props.node.id,
  { debounceMs: props.debounceMs },
)

// The collaboration session is created lazily — only when the node is collaborative AND
// collaboration is enabled.
const collab = useCollaboration(props.node.id, {
  providers: props.collaborationProviders,
  supportedSchemaVersion: props.supportedSchemaVersion,
  onEpochChanged: async () => {
    await loadContent()
  },
})
const collaborative = ref(false)

/** The session could not be opened for a reason that does not make autosave safe. */
const sessionFailed = ref(false)

/** The documented answer for "realtime editing is not offered here": 409 collaboration_unavailable. */
function collaborationUnavailable(caught: unknown): boolean {
  return caught instanceof VaulterApiError && caught.status === 409 && caught.code === 'collaboration_unavailable'
}

/** Read-only is forced by the prop, the caller's abilities, a failed session, or a read-only session. */
const isReadonly = computed<boolean>(() =>
  props.readonly
  || !props.node.abilities.edit
  || sessionFailed.value
  || (collaborative.value && collab.readOnly.value))

function onChange(next: DocumentBody): void {
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
  const saved = await flush()
  if (saved) {
    emit('saved', saved.content)
  }
}

async function loadAll(): Promise<void> {
  sessionFailed.value = false

  if (props.collaboration && props.node.presentation?.capabilities.collaborative === true) {
    try {
      await collab.connect()
      sessionFailed.value = false
      collaborative.value = true
      emit('collaborating', collab.readOnly.value)
      if (collab.schemaOutdated.value && collab.session.value) {
        emit('schema-outdated', collab.session.value.schema_version)
      }
    } catch (caught) {
      // A newer load() took the connection over: it decides, this one just stops.
      if (caught instanceof CollaborationCancelledError) {
        return
      }
      collaborative.value = false

      if (!collaborationUnavailable(caught)) {
        // Anything but "not offered here" (network, 429, 5xx, 403) says nothing about the
        // document. A document that HAS a Yjs state must not be autosaved over from here: the
        // update moves its epoch, drops the state and throws every other participant out.
        // Stay read-only on the loaded content; a later load() retries the session.
        sessionFailed.value = true
        emit('collaboration-failed', caught)
      }
    }
  }

  await loadContent()
  emit('ready', props.node)
}

onMounted(() => {
  if (props.autoLoad) {
    // A load failure is captured in `error` (shown via the error slot).
    void loadAll().catch(() => undefined)
  }
})

onBeforeUnmount(() => {
  collab.disconnect()
})

defineExpose({
  load: loadAll,
  flush: persist,
  content,
  saving,
  error,
  loading,
  conflict,
  collaborative,
  sessionFailed,
  connected: collab.connected,
  collaborators: collab.collaborators,
})
</script>

<template>
  <div
    class="vaulter-document-node"
    :data-document-type="node.presentation?.document_type ?? undefined"
    :data-collaborative="collaborative ? 'true' : 'false'"
  >
    <slot v-if="loading" name="loading">Loading…</slot>

    <slot
      v-else
      :node="node"
      :content="collaborative ? null : content"
      :readonly="isReadonly"
      :set-content="onChange"
      :flush="persist"
      :collaborative="collaborative"
      :ydoc="collaborative ? collab.doc.value : null"
      :connected="collab.connected.value"
      :collaborators="collab.collaborators.value"
      :conflict="conflict"
    >
      <!-- Fallback: no editor was mounted in the slot. -->
      <div class="vaulter-document-node__fallback">
        <slot name="fallback" :node="node" :content="content">
          <p class="vaulter-document-node__fallback-note">
            No editor is mounted for this document. Register a viewer for
            <code>{{ node.presentation?.viewer.key ?? 'vaulter/tiptap' }}</code> or fill this component's slot.
          </p>
        </slot>
      </div>
    </slot>

    <p v-if="conflict" class="vaulter-document-node__conflict" role="alert">
      <slot name="conflict" :error="conflict">
        Someone saved this document first. Your edits are kept — save again to overwrite, or reload.
      </slot>
    </p>

    <p v-if="error && !conflict" class="vaulter-document-node__error" role="alert">
      <slot name="error" :error="error">Could not load this document.</slot>
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch, type Ref } from 'vue'
import { useVaulterClient } from '../composables/useVaulterClient'
import VaulterBreadcrumb from './VaulterBreadcrumb.vue'
import type { DriveId, Node, NodeId } from '../api/types'

// A modal/overlay destination picker for MOVE: it walks the drive's FOLDER tree
// (folders only) with the SAME client the rest of the Drive uses — a breadcrumb,
// the current folder's child folders, and a "Move here" action at the current
// location. Root (a null parent) is a selectable destination.
//
// Illegal destinations are never offered: the node being MOVED itself is hidden
// from the listing (so you can never descend INTO it → its whole subtree is
// excluded by construction), and "Move here" is disabled when the current
// location is the node's CURRENT parent (a no-op move) or the node itself.
//
// Headless by default to match VaulterNodeList/VaulterBreadcrumb: minimal
// scoped styles, class hooks + slots throughout; navigation is emit-driven.
const props = withDefaults(
  defineProps<{
    driveId: DriveId
    /** The node being moved — its self/subtree and current parent are excluded. */
    node: Node
    /** Whether the picker is shown. */
    open?: boolean
    /** The label of the root crumb / root destination. */
    rootLabel?: string
  }>(),
  {
    open: false,
    rootLabel: 'Drive',
  },
)

const emit = defineEmits<{
  /** A destination was chosen — `null` is the drive root. */
  'picked': [targetId: NodeId | null]
  /** The picker was dismissed (Esc / overlay / cancel) — no move happens. */
  'close': []
}>()

const client = useVaulterClient()

/** Where we are browsing (null = the drive root). */
const currentParentId = ref<NodeId | null>(null)
/** The child FOLDERS at the current location (already filtered). */
const folders = ref<Node[]>([])
/** Root → current location, for the breadcrumb (empty = at root). */
const breadcrumb: Ref<Array<{ id: NodeId, name: string }>> = ref([])
const loading = ref(false)
const error = ref<unknown>(null)

/** Ids are always strings; a missing parent (the drive root) is `null`. */
function sameId(a: NodeId | null, b: NodeId | null): boolean {
  return (a ?? null) === (b ?? null)
}

/** The node's current parent (null = it currently lives at the drive root). */
const currentParentOfNode = computed<NodeId | null>(() => props.node.parent_id ?? null)

/**
 * Whether the CURRENT location is a legal destination for the move:
 *  - not the node's current parent (that would be a no-op the backend rejects),
 *  - not the node itself (you can't move a folder into itself).
 * The node's subtree is excluded structurally — it's hidden from the listing, so
 * the user can never navigate INTO it to reach this state.
 */
const canMoveHere = computed<boolean>(() => {
  if (sameId(currentParentId.value, currentParentOfNode.value)) {
    return false
  }
  if (sameId(currentParentId.value, props.node.id)) {
    return false
  }
  return true
})

/** Keep only child folders, and never the node being moved (excludes its subtree). */
function keepFolders(nodes: Node[]): Node[] {
  return nodes.filter((n) => n.kind === 'folder' && !sameId(n.id, props.node.id))
}

// Monotonic load token: rapid navigation (click A then B) issues concurrent load()s,
// and an earlier response must NEVER overwrite a newer location's contents — otherwise
// the listing shows one folder while "Move here" targets another (a silent wrong move).
// Same guard the Drive's search uses (VaulterDrive.runSearch / searchSeq).
let loadSeq = 0

/** (Re)load the folders at the current location and refresh the breadcrumb. */
async function load(): Promise<void> {
  const seq = ++loadSeq
  loading.value = true
  error.value = null
  try {
    const collected: Node[] = []
    let cursor: string | null | undefined
    do {
      const page = await client.nodes.children(props.driveId, {
        parent: currentParentId.value,
        ...(cursor ? { cursor } : {}),
      })
      if (seq !== loadSeq) {
        return
      }
      collected.push(...page.data)
      cursor = page.meta.next_cursor
    } while (cursor)
    folders.value = keepFolders(collected)
    await refreshBreadcrumb(seq)
  } catch (caught) {
    if (seq !== loadSeq) {
      return
    }
    error.value = caught
    folders.value = []
  } finally {
    if (seq === loadSeq) {
      loading.value = false
    }
  }
}

/** Root → current location (empty at root), mirroring VaulterDrive's breadcrumb. */
async function refreshBreadcrumb(seq: number): Promise<void> {
  if (currentParentId.value == null) {
    breadcrumb.value = []
    return
  }
  try {
    const node = await client.nodes.getWithIncludes(currentParentId.value, ['breadcrumb'])
    if (seq !== loadSeq) {
      return
    }
    breadcrumb.value = node.breadcrumb ?? []
  } catch {
    // A breadcrumb fetch failure must not blank the listing (and a stale one is ignored).
    if (seq === loadSeq) {
      breadcrumb.value = []
    }
  }
}

/** Navigate into a folder (or `null` for the drive root) and list it. */
async function goTo(into: NodeId | null): Promise<void> {
  currentParentId.value = into
  await load()
}

/** Confirm the current location as the move target. */
function moveHere(): void {
  if (!canMoveHere.value) {
    return
  }
  emit('picked', currentParentId.value)
}

function close(): void {
  emit('close')
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
  }
}

// Open → start at the root and load; re-open always resets the browse position.
watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      currentParentId.value = null
      void load()
      void nextTick(() => root.value?.focus())
    }
  },
  { immediate: true },
)

/** The overlay root — focused on open so Esc works without a click first. */
const root = ref<HTMLElement | null>(null)

defineExpose({ goTo, moveHere, currentParentId, folders, breadcrumb, canMoveHere, loading })
</script>

<template>
  <div
    v-if="open"
    ref="root"
    class="vaulter-folder-picker"
    role="dialog"
    aria-modal="true"
    aria-label="Choose a destination folder"
    tabindex="-1"
    @keydown="onKeydown"
  >
    <div class="vaulter-folder-picker__backdrop" @click="close" />

    <div class="vaulter-folder-picker__panel" role="document">
      <header class="vaulter-folder-picker__header">
        <slot name="header" :node="node">
          <span class="vaulter-folder-picker__title">Move “{{ node.name }}” to…</span>
        </slot>
        <button
          type="button"
          class="vaulter-folder-picker__close"
          aria-label="Close"
          @click="close"
        >
          ×
        </button>
      </header>

      <VaulterBreadcrumb
        class="vaulter-folder-picker__breadcrumb"
        :path="breadcrumb"
        :root-label="rootLabel"
        @navigate="goTo"
      />

      <p v-if="error" class="vaulter-folder-picker__error" role="alert">
        <slot name="error" :error="error">Could not load folders.</slot>
      </p>

      <slot v-if="loading" name="loading">
        <p class="vaulter-folder-picker__loading">Loading…</p>
      </slot>

      <ul v-else-if="!error" class="vaulter-folder-picker__list" role="listbox" aria-label="Folders">
        <li
          v-for="folder in folders"
          :key="folder.id"
          class="vaulter-folder-picker__item"
          role="option"
          :aria-selected="false"
          tabindex="0"
          @click="goTo(folder.id)"
          @keydown.enter.prevent="goTo(folder.id)"
        >
          <slot name="folder" :folder="folder">
            <span class="vaulter-folder-picker__folder-icon" aria-hidden="true">📁</span>
            <span class="vaulter-folder-picker__folder-name">{{ folder.name }}</span>
          </slot>
        </li>
        <li v-if="folders.length === 0" class="vaulter-folder-picker__empty">
          <slot name="empty">No subfolders here.</slot>
        </li>
      </ul>

      <footer class="vaulter-folder-picker__footer">
        <slot
          name="footer"
          :move-here="moveHere"
          :can-move-here="canMoveHere"
          :current-parent-id="currentParentId"
          :cancel="close"
        >
          <button
            type="button"
            class="vaulter-folder-picker__move-here"
            :disabled="!canMoveHere"
            @click="moveHere"
          >
            Move here
          </button>
          <button type="button" class="vaulter-folder-picker__cancel" @click="close">
            Cancel
          </button>
        </slot>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.vaulter-folder-picker {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.vaulter-folder-picker__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
}
.vaulter-folder-picker__panel {
  position: relative;
  max-width: 32rem;
  width: 100%;
  max-height: 80vh;
  overflow: auto;
  background: #fff;
  border-radius: 0.5rem;
  padding: 1rem;
}
.vaulter-folder-picker__move-here[disabled] {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>

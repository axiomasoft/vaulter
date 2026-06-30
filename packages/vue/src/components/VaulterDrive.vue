<script setup lang="ts">
import { computed, onMounted, ref, watch, type Ref } from 'vue'
import { useNodes } from '../composables/useNodes'
import { useVaulterClient } from '../composables/useVaulterClient'
import VaulterDriveBreadcrumb from './VaulterDriveBreadcrumb.vue'
import VaulterFolderPicker from './VaulterFolderPicker.vue'
import VaulterNameDialog from './VaulterNameDialog.vue'
import VaulterNodeList, { type NodeAction } from './VaulterNodeList.vue'
import VaulterUploader from './VaulterUploader.vue'
import type { Node, NodeId, StorageId } from '../types'

// The headline Drive file-manager: a breadcrumb, a toolbar (new folder, upload,
// view toggle, search) and the node listing. Folder opens navigate internally;
// file/document opens emit `open-node` for the host to handle. Row actions wire to
// the `useNodes` composable mutations, with a confirm on the destructive ones.
const props = withDefaults(
  defineProps<{
    storageId: StorageId
    /** The folder to open initially (null/undefined = the storage root). */
    initialParentId?: NodeId | null
    viewMode?: 'list' | 'grid'
    /** Row actions to offer (still filtered per node abilities by the list). */
    actions?: NodeAction[]
    /** A confirm gate for the destructive actions (default: window.confirm). */
    confirm?: (message: string) => boolean | Promise<boolean>
  }>(),
  {
    initialParentId: null,
    viewMode: 'list',
    actions: () => ['rename', 'move', 'trash', 'download', 'share'],
    confirm: undefined,
  },
)

const emit = defineEmits<{
  /** A non-folder node was opened — the host opens/edits/previews it. */
  'open-node': [node: Node]
  /** A node was selected (single-click). */
  'node-selected': [node: Node]
  /** The listed folder changed (navigation). */
  'navigate': [parentId: NodeId | null]
}>()

const client = useVaulterClient()

const {
  nodes, parentId, loading, error,
  refresh, navigate, createFolder,
  rename, move, trash, restore, forceDelete,
} = useNodes(props.storageId, () => props.initialParentId ?? null)

const view = ref<'list' | 'grid'>(props.viewMode)
const selectedId = ref<NodeId | null>(null)
const searchTerm = ref('')
/** The active breadcrumb ancestry for the current folder (root → here). */
const breadcrumb: Ref<Node[]> = ref([])
/** When a search is active we list search hits instead of the folder children. */
const searchResults = ref<Node[] | null>(null)
/** The MOVE target picker: which node is being moved, and whether it's shown. */
const moveTarget = ref<Node | null>(null)
const pickerOpen = ref(false)

/**
 * The NAME-input dialog state, shared by new-folder and rename (replaces the old
 * browser name prompts). One instance serves both via the `mode`: 'new' creates a
 * folder from the entered name; 'rename' renames `node` (skipping an unchanged
 * name). `null` = closed. The `open` flag drives the dialog's open→seed/focus.
 */
type NameDialogState =
  | { mode: 'new', title: string, initialValue: string }
  | { mode: 'rename', node: Node, title: string, initialValue: string }
const nameDialog = ref<NameDialogState | null>(null)
const nameDialogOpen = ref(false)

const displayNodes = computed<Node[]>(() => searchResults.value ?? nodes.value)

function defaultConfirm(message: string): boolean {
  return typeof window !== 'undefined' ? window.confirm(message) : true
}

async function confirmAction(message: string): Promise<boolean> {
  return props.confirm ? props.confirm(message) : defaultConfirm(message)
}

/** Refresh the breadcrumb for the current folder (root → here), if not at root. */
async function refreshBreadcrumb(): Promise<void> {
  if (parentId.value == null) {
    breadcrumb.value = []
    return
  }
  try {
    const node = await client.nodes.show(parentId.value)
    breadcrumb.value = node.breadcrumb ?? []
  } catch {
    // A breadcrumb fetch failure must not blank the listing.
    breadcrumb.value = []
  }
}

async function goTo(into: NodeId | null): Promise<void> {
  searchResults.value = null
  searchTerm.value = ''
  selectedId.value = null
  await navigate(into)
  await refreshBreadcrumb()
  emit('navigate', into)
}

function onSelect(node: Node): void {
  selectedId.value = node.id
  emit('node-selected', node)
}

function onOpen(node: Node): void {
  if (node.kind === 'folder') {
    void goTo(node.id)
    return
  }
  emit('open-node', node)
}

function onNewFolder(): void {
  // Open the name dialog in "new folder" mode; createFolder runs on `submit`.
  nameDialog.value = { mode: 'new', title: 'New folder', initialValue: '' }
  nameDialogOpen.value = true
}

async function onAction(action: NodeAction, node: Node): Promise<void> {
  switch (action) {
    case 'rename': {
      // Open the name dialog prefilled with the current name; rename runs on `submit`.
      nameDialog.value = { mode: 'rename', node, title: 'Rename', initialValue: node.name }
      nameDialogOpen.value = true
      break
    }
    case 'move': {
      // Open the folder-target picker for this node; the move runs on `picked`.
      moveTarget.value = node
      pickerOpen.value = true
      break
    }
    case 'trash': {
      if (await confirmAction(`Move "${node.name}" to trash?`)) {
        await trash(node.id)
        if (String(selectedId.value) === String(node.id)) {
          selectedId.value = null
        }
      }
      break
    }
    case 'restore': {
      await restore(node.id)
      break
    }
    case 'forceDelete': {
      if (await confirmAction(`Permanently delete "${node.name}"? This cannot be undone.`)) {
        await forceDelete(node.id)
      }
      break
    }
    case 'download': {
      if (typeof window !== 'undefined') {
        window.open(client.nodes.downloadUrl(node.id), '_blank')
      }
      break
    }
    case 'share': {
      // Sharing UI lives in VaulterNodeSharePanel — surface the intent to the host.
      emit('node-selected', node)
      break
    }
  }
}

/** The picker chose a destination (null = root) — run the move, then close. */
async function onMovePicked(targetId: NodeId | null): Promise<void> {
  const node = moveTarget.value
  pickerOpen.value = false
  moveTarget.value = null
  if (node) {
    await move(node.id, targetId)
  }
}

/** The picker was dismissed — just close, no move. */
function onMovePickerClose(): void {
  pickerOpen.value = false
  moveTarget.value = null
}

/**
 * The name dialog confirmed a (trimmed, non-empty) name — run the right mutation
 * for the active mode, then close. Rename skips an unchanged name (the backend
 * would reject the no-op); new-folder always creates. Mirrors onMovePicked.
 */
async function onNameSubmit(name: string): Promise<void> {
  const state = nameDialog.value
  nameDialogOpen.value = false
  nameDialog.value = null
  if (!state) {
    return
  }
  if (state.mode === 'new') {
    await createFolder({ name })
  } else if (name !== state.node.name) {
    await rename(state.node.id, name)
  }
}

/** The name dialog was dismissed — just close, no mutation. */
function onNameDialogClose(): void {
  nameDialogOpen.value = false
  nameDialog.value = null
}

let searchSeq = 0
async function runSearch(): Promise<void> {
  const term = searchTerm.value.trim()
  if (term === '') {
    searchResults.value = null
    return
  }
  const seq = ++searchSeq
  const page = await client.nodes.search(props.storageId, term)
  // Ignore a stale response if a newer search has started.
  if (seq === searchSeq) {
    searchResults.value = page.data
  }
}

function onUploaded(): void {
  // A direct/resumable upload landed — re-list so the new node appears.
  if (searchResults.value === null) {
    void refresh()
  }
}

watch(() => props.viewMode, (next) => { view.value = next })

onMounted(() => {
  void refresh().then(refreshBreadcrumb)
})

defineExpose({ refresh, goTo, nodes, displayNodes, selectedId, view })
</script>

<template>
  <div class="vaulter-drive" :data-view="view">
    <div class="vaulter-drive__toolbar">
      <slot
        name="toolbar"
        :new-folder="onNewFolder"
        :view="view"
        :set-view="(v: 'list' | 'grid') => (view = v)"
        :search-term="searchTerm"
      >
        <button type="button" class="vaulter-drive__new-folder" @click="onNewFolder">New folder</button>

        <VaulterUploader
          class="vaulter-drive__uploader"
          :storage-id="storageId"
          :parent-id="parentId"
          @uploaded="onUploaded"
        >
          <template #dropzone="{ openPicker }">
            <span class="vaulter-drive__upload-label" @click.stop="openPicker">Upload</span>
          </template>
        </VaulterUploader>

        <div class="vaulter-drive__view-toggle" role="group" aria-label="View mode">
          <button
            type="button"
            class="vaulter-drive__view-list"
            :aria-pressed="view === 'list'"
            @click="view = 'list'"
          >
            List
          </button>
          <button
            type="button"
            class="vaulter-drive__view-grid"
            :aria-pressed="view === 'grid'"
            @click="view = 'grid'"
          >
            Grid
          </button>
        </div>

        <input
          v-model="searchTerm"
          class="vaulter-drive__search"
          type="search"
          placeholder="Search…"
          aria-label="Search files"
          @keydown.enter.prevent="runSearch"
        >
      </slot>
    </div>

    <VaulterDriveBreadcrumb
      class="vaulter-drive__breadcrumb"
      :path="breadcrumb"
      @navigate="goTo"
    />

    <p v-if="error" class="vaulter-drive__error" role="alert">
      <slot name="error" :error="error">Could not load this folder.</slot>
    </p>

    <VaulterNodeList
      class="vaulter-drive__list"
      :nodes="displayNodes"
      :view-mode="view"
      :selected-id="selectedId"
      :actions="actions"
      :loading="loading"
      @select="onSelect"
      @open="onOpen"
      @action="onAction"
    >
      <template #empty>
        <slot name="empty">
          <p class="vaulter-drive__empty">{{ searchResults ? 'No matches.' : 'This folder is empty.' }}</p>
        </slot>
      </template>
    </VaulterNodeList>

    <VaulterFolderPicker
      v-if="moveTarget"
      class="vaulter-drive__folder-picker"
      :storage-id="storageId"
      :node="moveTarget"
      :open="pickerOpen"
      @picked="onMovePicked"
      @close="onMovePickerClose"
    />

    <VaulterNameDialog
      v-if="nameDialog"
      class="vaulter-drive__name-dialog"
      :open="nameDialogOpen"
      :title="nameDialog.title"
      :initial-value="nameDialog.initialValue"
      :confirm-label="nameDialog.mode === 'rename' ? 'Rename' : 'Create'"
      placeholder="Name"
      @submit="onNameSubmit"
      @close="onNameDialogClose"
    />
  </div>
</template>

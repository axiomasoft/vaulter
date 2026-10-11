<script setup lang="ts">
import { computed, onMounted, ref, watch, type Ref } from 'vue'
import { useNodes } from '../composables/useNodes'
import { useVaulterClient } from '../composables/useVaulterClient'
import VaulterBreadcrumb from './VaulterBreadcrumb.vue'
import VaulterFolderPicker from './VaulterFolderPicker.vue'
import VaulterNameDialog from './VaulterNameDialog.vue'
import VaulterNodeList, { type NodeAction } from './VaulterNodeList.vue'
import VaulterUploader from './VaulterUploader.vue'
import type { DriveId, Node, NodeId } from '../api/types'

// The headline Drive file-manager: a breadcrumb, a toolbar (new folder, upload,
// view toggle, search) and the node listing. Folder opens navigate internally;
// file/document opens emit `open-node` for the host to handle. Row actions wire to
// the `useNodes` composable mutations, with a confirm on the destructive ones.
const props = withDefaults(
  defineProps<{
    driveId: DriveId
    /** The folder to open initially (null/undefined = the drive root). */
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
  nodes, parentId, loading, error, conflict,
  refresh: refreshNodes, navigate, createFolder,
  rename, move, trash, restore, purge,
} = useNodes(props.driveId, () => props.initialParentId ?? null)

const view = ref<'list' | 'grid'>(props.viewMode)
const selectedId = ref<NodeId | null>(null)
const searchTerm = ref('')
/** The visible ancestry of the current folder (root → here). */
const breadcrumb: Ref<Array<{ id: NodeId, name: string }>> = ref([])
const canCreate = ref(false)
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

/**
 * Run a node mutation, surfacing any failure into the shared `error` ref instead of
 * leaving an unhandled promise rejection. The dialogs/pickers dismiss BEFORE the
 * mutation is awaited, so a server reject (422 validation, 403 authorization, a name
 * conflict) would otherwise vanish with no feedback — the user would see neither the
 * change nor an error. `error` is cleared on the next `refresh`.
 */
async function runMutation(action: () => Promise<unknown>): Promise<void> {
  try {
    await action()
  } catch (caught) {
    error.value = caught
  }
}

/** A human-readable line for the current error — the API/Error message when present,
 *  else a neutral fallback (the error covers BOTH a failed listing and a failed mutation). */
const errorMessage = computed<string>(() => {
  if (conflict.value !== null) {
    return 'This item was changed by someone else. It has been reloaded — review it and try again.'
  }
  const caught = error.value
  if (caught instanceof Error && caught.message !== '') {
    return caught.message
  }
  return 'Something went wrong.'
})

/** Refresh the breadcrumb for the current folder (root → here), if not at root. */
/** Bumped by every search, clear and navigation: only the newest search may show its hits. */
let searchSeq = 0
let breadcrumbSeq = 0
async function refreshBreadcrumb(): Promise<void> {
  const seq = ++breadcrumbSeq
  canCreate.value = false
  try {
    if (parentId.value == null) {
      breadcrumb.value = []
      const drive = await client.drives.get(props.driveId)
      const root = await client.nodes.get(drive.root_node_id)
      if (seq === breadcrumbSeq) {
        canCreate.value = root.abilities.create
      }
      return
    }
    const node = await client.nodes.getWithIncludes(parentId.value, ['breadcrumb'])
    // The user opened another folder meanwhile: this trail belongs to the one they left.
    if (seq === breadcrumbSeq) {
      breadcrumb.value = node.breadcrumb ?? []
      canCreate.value = node.abilities.create
    }
  } catch {
    // A breadcrumb fetch failure must not blank the listing.
    if (seq === breadcrumbSeq) {
      breadcrumb.value = []
      canCreate.value = false
    }
  }
}

async function refresh(): Promise<void> {
  await refreshNodes()
  await refreshBreadcrumb()
}

async function goTo(into: NodeId | null): Promise<void> {
  breadcrumbSeq += 1
  canCreate.value = false
  // Opening a folder ends any search: its answer must not come back and replace the listing.
  searchSeq += 1
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
  if (!canCreate.value) return
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
        await runMutation(async () => {
          await trash(node)
          if (String(selectedId.value) === String(node.id)) {
            selectedId.value = null
          }
        })
      }
      break
    }
    case 'restore': {
      await runMutation(() => restore(node))
      break
    }
    case 'purge': {
      if (await confirmAction(`Permanently delete "${node.name}"? This cannot be undone.`)) {
        await runMutation(() => purge(node))
      }
      break
    }
    case 'download': {
      if (typeof window !== 'undefined') {
        window.open(client.files.downloadUrl(node.id), '_blank')
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
    await runMutation(() => move(node, targetId))
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
    await runMutation(() => createFolder({ name }))
  } else if (name !== state.node.name) {
    await runMutation(() => rename(state.node, name))
  }
}

/** The name dialog was dismissed — just close, no mutation. */
function onNameDialogClose(): void {
  nameDialogOpen.value = false
  nameDialog.value = null
}

async function runSearch(): Promise<void> {
  const term = searchTerm.value.trim()
  // An empty query also supersedes a search that is still in flight.
  const seq = ++searchSeq
  if (term === '') {
    searchResults.value = null
    return
  }
  const page = await client.nodes.search(props.driveId, term)
  // Ignore a stale response if a newer search has started.
  if (seq === searchSeq) {
    searchResults.value = page.data
  }
}

function onUploaded(): void {
  // A direct/resumable upload landed — re-list so the new node appears.
  if (searchResults.value === null) {
    void refresh().catch(() => undefined)
  }
}

watch(() => props.viewMode, (next) => { view.value = next })

onMounted(() => {
  void refresh().catch(() => undefined)
})

defineExpose({ refresh, goTo, nodes, displayNodes, selectedId, view })
</script>

<template>
  <div class="vaulter-drive" :data-view="view">
    <div class="vaulter-drive__toolbar">
      <slot
        name="toolbar"
        :new-folder="onNewFolder"
        :can-create="canCreate"
        :view="view"
        :set-view="(v: 'list' | 'grid') => (view = v)"
        :search-term="searchTerm"
      >
        <button v-if="canCreate" type="button" class="vaulter-drive__new-folder" @click="onNewFolder">New folder</button>

        <VaulterUploader
          v-if="canCreate"
          class="vaulter-drive__uploader"
          :drive-id="driveId"
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

    <VaulterBreadcrumb
      class="vaulter-drive__breadcrumb"
      :path="breadcrumb"
      @navigate="goTo"
    />

    <p v-if="error" class="vaulter-drive__error" role="alert">
      <slot name="error" :error="error" :message="errorMessage">{{ errorMessage }}</slot>
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
      :drive-id="driveId"
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

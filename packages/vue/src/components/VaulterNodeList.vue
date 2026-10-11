<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { humanByteSize, nodeIconKind, type NodeIconKind } from './drive/nodeDisplay'
import type { Node, NodeId } from '../api/types'

// The Drive node listing — a list or grid of nodes with a kind/mime icon, name, size and
// modified date, built as a WAI-ARIA listbox. Selection + double-click/Enter → open.
// Keyboard: Up/Down/Home/End move focus (one tab stop, roving tabindex), Enter opens,
// Space selects, F2 asks to rename, Delete asks to trash. After a node leaves the list
// (trash, move, purge) focus lands on its neighbour instead of falling back to the page.
// Per-row actions are gated on `node.abilities` (an action the actor can't do is never
// offered, by button or by key) and surfaced both as default buttons and via the `actions`
// slot. Headless by default: minimal scoped styles, class hooks + slots throughout.

/** The destructive/standard row actions the list can offer. */
export type NodeAction = 'rename' | 'move' | 'copy' | 'trash' | 'restore' | 'purge' | 'download' | 'share'

/** Maps a {@link NodeAction} to the ability that must be true to offer it. */
const ACTION_ABILITY: Record<NodeAction, keyof Node['abilities']> = {
  rename: 'edit',
  move: 'move',
  copy: 'copy',
  trash: 'trash',
  restore: 'restore',
  purge: 'delete',
  download: 'download',
  share: 'share',
}

const props = withDefaults(
  defineProps<{
    nodes: Node[]
    viewMode?: 'list' | 'grid'
    /** The currently-selected node id (single-select). */
    selectedId?: NodeId | null
    /** Which row actions to offer (still filtered by each node's abilities). */
    actions?: NodeAction[]
    loading?: boolean
    /** The accessible name of the listbox. */
    label?: string
  }>(),
  {
    viewMode: 'list',
    selectedId: null,
    actions: () => ['rename', 'move', 'trash', 'download', 'share'],
    loading: false,
    label: 'Files',
  },
)

const emit = defineEmits<{
  'select': [node: Node]
  /** A double-click (or Enter) — the host opens the node (navigate/edit/preview). */
  'open': [node: Node]
  /** A row action was invoked — the host/parent runs the matching composable call. */
  'action': [action: NodeAction, node: Node]
}>()

const isEmpty = computed(() => !props.loading && props.nodes.length === 0)

const list = ref<HTMLElement | null>(null)
/** The row that owns the single tab stop and the keyboard focus. */
const focusedId = ref<NodeId | null>(null)
let previousIds: NodeId[] = props.nodes.map((node) => node.id)

function iconKind(node: Node): NodeIconKind {
  return nodeIconKind(node)
}

function isSelected(node: Node): boolean {
  return props.selectedId != null && props.selectedId === node.id
}

/** The actions offered for a node: the requested set ∩ the node's abilities. */
function actionsFor(node: Node): NodeAction[] {
  return props.actions.filter((action) => node.abilities[ACTION_ABILITY[action]])
}

/** Exactly one row is a tab stop: the focused one, else the selected one, else the first. */
function tabIndexFor(node: Node, index: number): 0 | -1 {
  const stop = props.nodes.some((candidate) => candidate.id === focusedId.value)
    ? focusedId.value
    : props.nodes.some((candidate) => candidate.id === props.selectedId) ? props.selectedId : props.nodes[0]?.id

  return (stop === node.id || (stop === undefined && index === 0)) ? 0 : -1
}

function itemElement(nodeId: NodeId): HTMLElement | null {
  const rows = list.value?.querySelectorAll<HTMLElement>('[data-node-id]') ?? []

  return Array.from(rows).find((row) => row.dataset.nodeId === nodeId) ?? null
}

/** Move keyboard focus to a row. */
function focusNode(nodeId: NodeId): void {
  focusedId.value = nodeId
  void nextTick(() => itemElement(nodeId)?.focus())
}

function onSelect(node: Node): void {
  focusedId.value = node.id
  emit('select', node)
}

function onOpen(node: Node): void {
  emit('open', node)
}

function onAction(action: NodeAction, node: Node): void {
  emit('action', action, node)
}

function onKeydown(event: KeyboardEvent, node: Node, index: number): void {
  // Keys pressed on a button inside the row belong to that button.
  if (event.target !== event.currentTarget) {
    return
  }

  const last = props.nodes.length - 1
  const move = (to: number): void => {
    event.preventDefault()
    const target = props.nodes[Math.max(0, Math.min(last, to))]
    if (target) {
      focusNode(target.id)
    }
  }

  switch (event.key) {
    case 'ArrowDown': return move(index + 1)
    case 'ArrowUp': return move(index - 1)
    case 'Home': return move(0)
    case 'End': return move(last)
    case 'Enter':
      event.preventDefault()
      return onOpen(node)
    case ' ':
      event.preventDefault()
      return onSelect(node)
    case 'F2':
      if (actionsFor(node).includes('rename')) {
        event.preventDefault()
        onAction('rename', node)
      }
      return
    case 'Delete':
      if (actionsFor(node).includes('trash')) {
        event.preventDefault()
        onAction('trash', node)
      }
  }
}

// When the focused row disappears (trashed, moved, purged) hand the focus to its neighbour:
// the row that took its place, or the new last row when it was the last one.
watch(
  () => props.nodes,
  (next) => {
    const ids = next.map((node) => node.id)
    const gone = focusedId.value !== null && !ids.includes(focusedId.value)

    if (gone) {
      // The watcher runs before the DOM patch, so the removed row still holds the focus here.
      const hadFocus = list.value?.contains(document.activeElement) ?? false
      const neighbour = ids[Math.min(previousIds.indexOf(focusedId.value!), ids.length - 1)] ?? null
      focusedId.value = neighbour
      if (hadFocus && neighbour !== null) {
        void nextTick(() => itemElement(neighbour)?.focus())
      }
    }

    previousIds = ids
  },
)

defineExpose({ focusNode })
</script>

<template>
  <div class="vaulter-node-list" :data-view="viewMode">
    <slot v-if="loading" name="loading">
      <p class="vaulter-node-list__loading" role="status">Loading…</p>
    </slot>
    <slot v-else-if="isEmpty" name="empty">
      <p class="vaulter-node-list__empty">This folder is empty.</p>
    </slot>
    <ul
      v-else
      ref="list"
      class="vaulter-node-list__items"
      role="listbox"
      :aria-label="label"
    >
      <li
        v-for="(node, index) in nodes"
        :key="node.id"
        class="vaulter-node-list__item"
        :class="{ 'is-selected': isSelected(node) }"
        :data-node-id="node.id"
        :data-kind="node.kind"
        :data-starred="node.viewer.starred ? 'true' : undefined"
        :data-document-type="node.presentation?.document_type ?? undefined"
        role="option"
        :aria-selected="isSelected(node)"
        :tabindex="tabIndexFor(node, index)"
        @click="onSelect(node)"
        @dblclick="onOpen(node)"
        @focus="focusedId = node.id"
        @keydown="onKeydown($event, node, index)"
      >
        <span class="vaulter-node-list__icon" :class="`vaulter-node-icon--${iconKind(node)}`" aria-hidden="true">
          <slot name="icon" :node="node" :kind="iconKind(node)">{{ iconKind(node) }}</slot>
        </span>
        <span class="vaulter-node-list__name">
          <slot name="name" :node="node">{{ node.name }}</slot>
        </span>
        <span class="vaulter-node-list__size">{{ node.kind === 'folder' ? '' : humanByteSize(node.size_bytes) }}</span>
        <span class="vaulter-node-list__modified">{{ node.content_modified_at ?? node.updated_at }}</span>
        <span class="vaulter-node-list__actions">
          <slot name="actions" :node="node" :actions="actionsFor(node)" :run="(action: NodeAction) => onAction(action, node)">
            <button
              v-for="action in actionsFor(node)"
              :key="action"
              type="button"
              class="vaulter-node-list__action"
              :class="`vaulter-node-list__action--${action}`"
              :aria-label="`${action} ${node.name}`"
              @click.stop="onAction(action, node)"
            >
              {{ action }}
            </button>
          </slot>
        </span>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.vaulter-node-list[data-view='grid'] .vaulter-node-list__items {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(8rem, 1fr));
  gap: 0.5rem;
}
.vaulter-node-list__items {
  list-style: none;
  margin: 0;
  padding: 0;
}
.vaulter-node-list__item.is-selected {
  outline: 2px solid currentColor;
}
</style>

<script setup lang="ts">
import { computed } from 'vue'
import { humanByteSize, nodeIconKind, type NodeIconKind } from './drive/nodeDisplay'
import type { Node, NodeId } from '../types'

// The Drive node listing — a list or grid of nodes with a kind/mime icon, name,
// size and modified date. Selection + double-click → open. Per-row actions are
// gated on `node.abilities` (an action the actor can't do is never offered) and
// surfaced both as default buttons and via the `actions` slot for full restyling.
// Headless by default: minimal scoped styles, class hooks + slots throughout.

/** The destructive/standard row actions the list can offer. */
export type NodeAction = 'rename' | 'move' | 'copy' | 'trash' | 'restore' | 'forceDelete' | 'download' | 'share'

/** Maps a {@link NodeAction} to the ability that must be true to offer it. */
const ACTION_ABILITY: Record<NodeAction, string> = {
  rename: 'rename',
  move: 'move',
  copy: 'copy',
  trash: 'trash',
  restore: 'restore',
  forceDelete: 'delete',
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
  }>(),
  {
    viewMode: 'list',
    selectedId: null,
    actions: () => ['rename', 'move', 'trash', 'download', 'share'],
    loading: false,
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

function iconKind(node: Node): NodeIconKind {
  return nodeIconKind(node)
}

function isSelected(node: Node): boolean {
  return props.selectedId != null && String(props.selectedId) === String(node.id)
}

/** The actions offered for a node: the requested set ∩ the node's abilities. */
function actionsFor(node: Node): NodeAction[] {
  return props.actions.filter((action) => node.abilities[ACTION_ABILITY[action]] === true)
}

function onSelect(node: Node): void {
  emit('select', node)
}

function onOpen(node: Node): void {
  emit('open', node)
}

function onAction(action: NodeAction, node: Node): void {
  emit('action', action, node)
}
</script>

<template>
  <div class="vaulter-node-list" :data-view="viewMode" role="listbox" aria-label="Files">
    <slot v-if="loading" name="loading">
      <p class="vaulter-node-list__loading">Loading…</p>
    </slot>
    <slot v-else-if="isEmpty" name="empty">
      <p class="vaulter-node-list__empty">This folder is empty.</p>
    </slot>
    <ul v-else class="vaulter-node-list__items">
      <li
        v-for="node in nodes"
        :key="node.id"
        class="vaulter-node-list__item"
        :class="{ 'is-selected': isSelected(node) }"
        :data-kind="node.kind"
        role="option"
        :aria-selected="isSelected(node)"
        tabindex="0"
        @click="onSelect(node)"
        @dblclick="onOpen(node)"
        @keydown.enter.prevent="onOpen(node)"
      >
        <span class="vaulter-node-list__icon" :class="`vaulter-node-icon--${iconKind(node)}`" aria-hidden="true">
          <slot name="icon" :node="node" :kind="iconKind(node)">{{ iconKind(node) }}</slot>
        </span>
        <span class="vaulter-node-list__name">
          <slot name="name" :node="node">{{ node.name }}</slot>
        </span>
        <span class="vaulter-node-list__size">{{ humanByteSize(node.size_bytes) }}</span>
        <span class="vaulter-node-list__modified">{{ node.updated_at ?? '' }}</span>
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
.vaulter-node-list__item.is-selected {
  outline: 2px solid currentColor;
}
</style>

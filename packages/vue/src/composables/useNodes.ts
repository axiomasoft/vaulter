import {
  ref,
  shallowRef,
  toValue,
  watch,
  type MaybeRefOrGetter,
  type Ref,
  type ShallowRef,
} from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type {
  CreateDriveFolderPayload,
  CursorMeta,
  Node,
  NodeId,
  StorageId,
} from '../types'

export interface UseNodesReturn {
  nodes: ShallowRef<Node[]>
  /** The id of the folder currently listed (null = the storage root). */
  parentId: Ref<NodeId | null>
  cursor: Ref<CursorMeta | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  /** (Re)load the current folder's first page. */
  refresh: () => Promise<Node[]>
  /** Append the next cursor page (if any) to the list. */
  loadMore: () => Promise<Node[]>
  /** Navigate into a folder (null = the storage root) and load it. */
  navigate: (parentId: NodeId | null) => Promise<Node[]>
  createFolder: (payload: CreateDriveFolderPayload) => Promise<Node>
  rename: (nodeId: NodeId, name: string) => Promise<Node>
  move: (nodeId: NodeId, intoId: NodeId | null) => Promise<Node>
  copy: (nodeId: NodeId, intoId: NodeId | null) => Promise<Node>
  trash: (nodeId: NodeId) => Promise<Node>
  restore: (nodeId: NodeId) => Promise<Node>
  forceDelete: (nodeId: NodeId) => Promise<void>
}

/**
 * Reactive listing of a folder's children with navigation and the node actions
 * wired to the client. The `parentRef` drives which folder is shown — when it
 * changes the list reloads. Mutations refresh optimistically: a rename/move keeps
 * the row in place, a trash/force-delete drops it, a createFolder appends it.
 */
export function useNodes(
  storageId: StorageId,
  parentRef?: MaybeRefOrGetter<NodeId | null>,
): UseNodesReturn {
  const client = useVaulterClient()
  const nodes = shallowRef<Node[]>([])
  const parentId = ref<NodeId | null>(toValue(parentRef) ?? null)
  const cursor = shallowRef<CursorMeta | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function refresh(): Promise<Node[]> {
    loading.value = true
    error.value = null
    try {
      const page = await client.nodes.list(storageId, { parentId: parentId.value })
      nodes.value = page.data
      cursor.value = page.meta
      return nodes.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function loadMore(): Promise<Node[]> {
    const next = cursor.value?.next_cursor
    if (next == null) {
      return nodes.value
    }
    const page = await client.nodes.list(storageId, { parentId: parentId.value, cursor: next })
    nodes.value = [...nodes.value, ...page.data]
    cursor.value = page.meta
    return nodes.value
  }

  async function navigate(into: NodeId | null): Promise<Node[]> {
    parentId.value = into
    return refresh()
  }

  async function createFolder(payload: CreateDriveFolderPayload): Promise<Node> {
    const created = await client.nodes.createFolder(storageId, {
      ...payload,
      parentId: payload.parentId ?? parentId.value,
    })
    // Only reflect it in the current view when it lands in the listed folder.
    if (sameParent(created.parent_id, parentId.value)) {
      nodes.value = [...nodes.value, created]
    }
    return created
  }

  async function rename(nodeId: NodeId, name: string): Promise<Node> {
    const renamed = await client.nodes.rename(nodeId, name)
    nodes.value = nodes.value.map((node) => (node.id === nodeId ? renamed : node))
    return renamed
  }

  async function move(nodeId: NodeId, intoId: NodeId | null): Promise<Node> {
    const moved = await client.nodes.move(nodeId, intoId)
    // It left this folder unless it was moved back into it.
    nodes.value = sameParent(moved.parent_id, parentId.value)
      ? nodes.value.map((node) => (node.id === nodeId ? moved : node))
      : nodes.value.filter((node) => node.id !== nodeId)
    return moved
  }

  async function copy(nodeId: NodeId, intoId: NodeId | null): Promise<Node> {
    const copied = await client.nodes.copy(nodeId, intoId)
    if (sameParent(copied.parent_id, parentId.value)) {
      nodes.value = [...nodes.value, copied]
    }
    return copied
  }

  async function trash(nodeId: NodeId): Promise<Node> {
    const trashed = await client.nodes.trash(nodeId)
    nodes.value = nodes.value.filter((node) => node.id !== nodeId)
    return trashed
  }

  async function restore(nodeId: NodeId): Promise<Node> {
    const restored = await client.nodes.restore(nodeId)
    if (sameParent(restored.parent_id, parentId.value)) {
      nodes.value = [...nodes.value, restored]
    }
    return restored
  }

  async function forceDelete(nodeId: NodeId): Promise<void> {
    await client.nodes.forceDelete(nodeId)
    nodes.value = nodes.value.filter((node) => node.id !== nodeId)
  }

  // Reload when the external parent ref changes.
  if (parentRef !== undefined) {
    watch(
      () => toValue(parentRef) ?? null,
      (next) => {
        if (next !== parentId.value) {
          void navigate(next)
        }
      },
    )
  }

  return {
    nodes, parentId, cursor, loading, error,
    refresh, loadMore, navigate,
    createFolder, rename, move, copy, trash, restore, forceDelete,
  }
}

/** Compare two parent ids tolerant of the number/string id duality. */
function sameParent(a: NodeId | null, b: NodeId | null): boolean {
  if (a == null || b == null) {
    return a == null && b == null
  }
  return String(a) === String(b)
}

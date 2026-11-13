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
import { VaulterApiError } from '../api/errors'
import { isStaleError } from './conflict'
import type { DriveId, Node, NodeId, PageMeta } from '../api/types'

/** A mutation lost the race for a node: it was re-read and now shows its current state. */
export interface NodeConflict {
  nodeId: NodeId
  error: VaulterApiError
}

export interface UseNodesReturn {
  nodes: ShallowRef<Node[]>
  /** The id of the folder currently listed (null = the drive root). */
  parentId: Ref<NodeId | null>
  cursor: Ref<PageMeta | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  /**
   * Set when a mutation answered 412: the node was re-read (the list already shows its
   * current state) and the user has to look again before retrying. Cleared by the next mutation.
   */
  conflict: ShallowRef<NodeConflict | null>
  /** (Re)load the current folder's first page. */
  refresh: () => Promise<Node[]>
  /** Append the next cursor page (if any) to the list. */
  loadMore: () => Promise<Node[]>
  /** Navigate into a folder (null = the drive root) and load it. */
  navigate: (parentId: NodeId | null) => Promise<Node[]>
  createFolder: (input: { name: string, parentId?: NodeId | null }) => Promise<Node>
  rename: (node: Node, name: string) => Promise<Node>
  move: (node: Node, destinationId: NodeId | null) => Promise<Node>
  copy: (node: Node, destinationId: NodeId | null) => Promise<Node>
  trash: (node: Node) => Promise<Node>
  restore: (node: Node) => Promise<Node>
  purge: (node: Node) => Promise<void>
  star: (node: Node) => Promise<Node>
  unstar: (node: Node) => Promise<Node>
}

/**
 * Reactive listing of a folder's children with navigation and the node actions wired to
 * the client. Every mutation takes the NODE (not its id): the opaque `etag` it carries is
 * what the server compares in `If-Match`. A 412 re-reads the node and sets `conflict`
 * before rethrowing; a 428 is rethrown untouched, because a missing tag is an integration
 * bug. A rename/move keeps the row in place, a trash/purge drops it, a createFolder appends it.
 */
export function useNodes(
  driveId: DriveId,
  parentRef?: MaybeRefOrGetter<NodeId | null>,
): UseNodesReturn {
  const client = useVaulterClient()
  const nodes = shallowRef<Node[]>([])
  const parentId = ref<NodeId | null>(toValue(parentRef) ?? null)
  const cursor = shallowRef<PageMeta | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)
  const conflict = shallowRef<NodeConflict | null>(null)

  // A listing is a snapshot taken when the request was answered by the server; a mutation that
  // finished in between is newer than it. Every local change is stamped with a clock tick, and a
  // page only wins for the rows nobody touched after its request started (otherwise a late page
  // would bring back the old name — and the old ETag the next If-Match is taken from).
  let clock = 0
  let listSeq = 0
  let inflight = 0
  let moreInFlight = false
  const touched = new Map<NodeId, number>()

  /** The rows of a page, with the locally newer ones kept (a node trashed or moved away stays gone). */
  function reconcile(rows: Node[], startedAt: number, keepAdded = true): Node[] {
    const local = new Map(nodes.value.map((node) => [node.id, node]))
    const listed = new Set(rows.map((row) => row.id))

    const merged = rows.flatMap((row) => {
      const at = touched.get(row.id)
      if (at === undefined || at <= startedAt) {
        return [row]
      }

      const current = local.get(row.id)

      return current === undefined ? [] : [current]
    })

    // A node added here after the request started (a new folder, a copy) is not in the page yet.
    const added = keepAdded
      ? nodes.value.filter((node) => !listed.has(node.id) && (touched.get(node.id) ?? 0) > startedAt)
      : []

    return [...merged, ...added]
  }

  async function refresh(): Promise<Node[]> {
    const seq = ++listSeq
    const startedAt = clock
    inflight += 1
    loading.value = true
    error.value = null
    try {
      const page = await client.nodes.children(driveId, { parent: parentId.value })
      // A newer listing already won: this one is history.
      if (seq !== listSeq) {
        return nodes.value
      }
      nodes.value = reconcile(page.data, startedAt)
      cursor.value = page.meta
      return nodes.value
    } catch (caught) {
      if (seq === listSeq) {
        error.value = caught
      }
      throw caught
    } finally {
      inflight -= 1
      loading.value = inflight > 0
    }
  }

  async function loadMore(): Promise<Node[]> {
    const next = cursor.value?.next_cursor
    if (next == null || moreInFlight) {
      return nodes.value
    }
    // A reload that starts meanwhile bumps `listSeq`, which makes this page history; a reload
    // already in flight is not cancelled by it.
    const seq = listSeq
    const startedAt = clock
    moreInFlight = true
    try {
      const page = await client.nodes.children(driveId, { parent: parentId.value, cursor: next })
      if (seq !== listSeq) {
        return nodes.value
      }
      // Rows already shown keep their (possibly newer) local copy and are not appended twice.
      const shown = new Set(nodes.value.map((node) => node.id))
      nodes.value = [...nodes.value, ...reconcile(page.data, startedAt, false).filter((row) => !shown.has(row.id))]
      cursor.value = page.meta
      return nodes.value
    } catch (caught) {
      if (seq === listSeq) {
        error.value = caught
      }
      throw caught
    } finally {
      moreInFlight = false
    }
  }

  async function navigate(into: NodeId | null): Promise<Node[]> {
    parentId.value = into
    return refresh()
  }

  function replace(updated: Node): void {
    touched.set(updated.id, ++clock)
    nodes.value = nodes.value.map((node) => (node.id === updated.id ? updated : node))
  }

  function append(node: Node): void {
    touched.set(node.id, ++clock)
    nodes.value = [...nodes.value, node]
  }

  function drop(nodeId: NodeId): void {
    touched.set(nodeId, ++clock)
    nodes.value = nodes.value.filter((node) => node.id !== nodeId)
  }

  /** Run a mutation; on a stale tag re-read the node so the list shows what won. */
  async function mutate<T>(node: Node, run: () => Promise<T>): Promise<T> {
    conflict.value = null
    try {
      return await run()
    } catch (caught) {
      if (isStaleError(caught)) {
        try {
          replace(await client.nodes.get(node.id))
        } catch (readError) {
          // Only a missing or forbidden node leaves the list. A 503 or a network
          // error on the re-read must not pretend the file was deleted.
          if (readError instanceof VaulterApiError && (readError.status === 404 || readError.status === 403)) {
            drop(node.id)
          } else {
            error.value = readError
          }
        }
        conflict.value = { nodeId: node.id, error: caught }
      }
      throw caught
    }
  }

  async function createFolder(input: { name: string, parentId?: NodeId | null }): Promise<Node> {
    const created = await client.folders.create(driveId, {
      name: input.name,
      parentId: input.parentId ?? parentId.value,
    })
    // Only reflect it in the current view when it lands in the listed folder.
    if (created.parent_id === parentId.value) {
      append(created)
    }
    return created
  }

  async function rename(node: Node, name: string): Promise<Node> {
    const renamed = await mutate(node, () => client.nodes.update(node.id, { name }, { etag: node.etag }))
    replace(renamed)
    return renamed
  }

  async function move(node: Node, destinationId: NodeId | null): Promise<Node> {
    const moved = await mutate(node, () => client.nodes.move(node.id, destinationId, { etag: node.etag }))
    // It left this folder unless it was moved back into it.
    if (moved.parent_id === parentId.value) {
      replace(moved)
    } else {
      drop(node.id)
    }
    return moved
  }

  async function copy(node: Node, destinationId: NodeId | null): Promise<Node> {
    const copied = await client.nodes.copy(node.id, destinationId)
    if (copied.parent_id === parentId.value) {
      append(copied)
    }
    return copied
  }

  async function trash(node: Node): Promise<Node> {
    const trashed = await mutate(node, () => client.nodes.trash(node.id, { etag: node.etag }))
    drop(node.id)
    return trashed
  }

  async function restore(node: Node): Promise<Node> {
    const restored = await mutate(node, () => client.nodes.restore(node.id, { etag: node.etag }))
    if (restored.parent_id === parentId.value) {
      append(restored)
    }
    return restored
  }

  async function purge(node: Node): Promise<void> {
    await mutate(node, () => client.nodes.purge(node.id, { etag: node.etag }))
    drop(node.id)
  }

  async function star(node: Node): Promise<Node> {
    const starred = await client.nodes.star(node.id)
    replace(starred)
    return starred
  }

  async function unstar(node: Node): Promise<Node> {
    const unstarred = await client.nodes.unstar(node.id)
    replace(unstarred)
    return unstarred
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
    nodes, parentId, cursor, loading, error, conflict,
    refresh, loadMore, navigate,
    createFolder, rename, move, copy, trash, restore, purge, star, unstar,
  }
}

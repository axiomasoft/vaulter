import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import { isStaleError } from './conflict'
import type { VaulterApiError } from '../api/errors'
import type { DocumentBody, DocumentContent, DocumentExport, NodeId } from '../api/types'

export interface UseDocumentOptions {
  /** Autosave debounce window in ms (default 800). */
  debounceMs?: number
}

export interface UseDocumentReturn {
  /** The working content (what the editor shows); starts as the loaded head. */
  content: ShallowRef<DocumentBody | null>
  /** The last content the server confirmed, with the ETag the next save sends in `If-Match`. */
  head: ShallowRef<DocumentContent | null>
  loading: Ref<boolean>
  saving: Ref<boolean>
  error: Ref<unknown>
  /**
   * Set when a save answered 412: somebody saved first. `head` was re-read (it holds the
   * server's current content and tag) while `content` still holds the local edits — call
   * `save()` to overwrite with them, or `load()` to take the server's version.
   */
  conflict: ShallowRef<VaulterApiError | null>
  /** Load the current-head content and capture its ETag. */
  load: () => Promise<DocumentContent>
  /** Set the working content and schedule a debounced autosave. */
  setContent: (content: DocumentBody) => void
  /** Force-flush a pending autosave immediately (null when none is pending). */
  flush: () => Promise<DocumentContent | null>
  /** Save the current content now (no debounce). */
  save: () => Promise<DocumentContent>
  exportAs: (format: string, options?: { revision?: string }) => Promise<DocumentExport>
}

/**
 * Reactive editing of a document node: load its head content, edit it with a debounced
 * autosave, export it. Saves send the ETag of the content they were based on; a stale tag
 * (412) re-reads the head and sets `conflict` instead of silently overwriting. A missing tag
 * in both the response body and the exposed headers is an integration error and throws.
 */
export function useDocument(nodeId: NodeId, options: UseDocumentOptions = {}): UseDocumentReturn {
  const client = useVaulterClient()
  const debounceMs = options.debounceMs ?? 800

  const content = shallowRef<DocumentBody | null>(null)
  const head = shallowRef<DocumentContent | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<unknown>(null)
  const conflict = shallowRef<VaulterApiError | null>(null)

  let timer: ReturnType<typeof setTimeout> | null = null
  // Loads and saves run one at a time. A second save started while the first is in flight must
  // carry the tag the first answered, or it repeats the old one, loses the race with itself (412)
  // and can have its conflict wiped by the older success arriving late; a load must not be
  // overtaken by a save response that was already on its way.
  let queue: Promise<unknown> = Promise.resolve()

  function enqueue<T>(task: () => Promise<T>): Promise<T> {
    const run = queue.then(task, task)
    queue = run.catch(() => undefined)

    return run
  }

  function load(): Promise<DocumentContent> {
    return enqueue(fetchHead)
  }

  async function fetchHead(): Promise<DocumentContent> {
    loading.value = true
    error.value = null
    try {
      const loaded = await client.documents.content(nodeId)
      head.value = loaded
      content.value = loaded.content
      conflict.value = null
      return loaded
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  function setContent(next: DocumentBody): void {
    content.value = next
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
    // While a conflict is open the user decides (save to overwrite, load to take theirs):
    // an autosave must never make that decision for them.
    if (conflict.value !== null) {
      return
    }
    timer = setTimeout(() => {
      timer = null
      // A failed autosave is surfaced through `error`/`conflict`; do not leave a rejection unhandled.
      void enqueue(() => persist(true)).catch(() => undefined)
    }, debounceMs)
  }

  async function flush(): Promise<DocumentContent | null> {
    if (timer === null) {
      return null
    }
    clearTimeout(timer)
    timer = null
    return save()
  }

  function save(): Promise<DocumentContent> {
    // An explicit save carries the latest content, so a pending autosave has nothing left to add.
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }

    return enqueue(() => persist(false))
  }

  async function persist(auto: boolean): Promise<DocumentContent> {
    // An autosave that was already queued when a conflict opened has nothing to say any more.
    if (auto && conflict.value !== null && head.value !== null) {
      return head.value
    }

    if (content.value === null) {
      throw new Error('[Vaulter] Nothing to save — content has not been loaded or set.')
    }

    const etag = head.value?.etag ?? null
    if (etag === null) {
      throw new Error(
        '[Vaulter] The document ETag is unknown: the server response did not expose it. '
        + 'Expose X-Vaulter-ETag and ETag through CORS, then load the document again.',
      )
    }

    saving.value = true
    error.value = null
    try {
      const saved = await client.documents.update(nodeId, content.value, { etag })
      head.value = saved
      conflict.value = null
      return saved
    } catch (caught) {
      if (isStaleError(caught)) {
        // Edits typed while the save was in flight armed an autosave: it would overwrite the
        // other author with the tag we are about to re-read.
        if (timer !== null) {
          clearTimeout(timer)
          timer = null
        }
        try {
          head.value = await client.documents.content(nodeId)
        } catch {
          // The re-read failed too; the conflict is still reported below.
        }
        conflict.value = caught
      }
      error.value = caught
      throw caught
    } finally {
      saving.value = false
    }
  }

  function exportAs(format: string, exportOptions?: { revision?: string }): Promise<DocumentExport> {
    return client.documents.export(nodeId, format, exportOptions)
  }

  return { content, head, loading, saving, error, conflict, load, setContent, flush, save, exportAs }
}

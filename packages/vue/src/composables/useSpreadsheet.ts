import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type {
  HandlerDescriptor,
  NodeId,
  SpreadsheetVersion,
  SpreadsheetWorkbook,
  UpdateDocumentContentOptions,
} from '../types'

export interface UseSpreadsheetOptions {
  /** Autosave debounce window in ms (default 800). */
  debounceMs?: number
}

export interface UseSpreadsheetReturn {
  /** The current working workbook snapshot (opaque — the editor owns its shape). */
  workbook: ShallowRef<SpreadsheetWorkbook | null>
  head: ShallowRef<SpreadsheetVersion | null>
  handler: ShallowRef<HandlerDescriptor | null>
  loading: Ref<boolean>
  saving: Ref<boolean>
  error: Ref<unknown>
  /** Load the current-head workbook (and capture the head version). */
  load: () => Promise<SpreadsheetVersion>
  /** Set the working workbook and schedule a debounced autosave. */
  setWorkbook: (workbook: SpreadsheetWorkbook, options?: UpdateDocumentContentOptions) => void
  /** Force-flush a pending autosave immediately (returns null if none pending). */
  flush: () => Promise<SpreadsheetVersion | null>
  /** Save the current workbook now (no debounce). */
  save: (options?: UpdateDocumentContentOptions) => Promise<SpreadsheetVersion>
  loadHandler: () => Promise<HandlerDescriptor>
}

/**
 * Reactive editing of a NON-rich-text (spreadsheet) document node: load its opaque
 * workbook snapshot, edit it with a debounced autosave, resolve its handler. The
 * concrete editor (Univer, etc.) is mounted by the consumer — this composable only
 * owns the load/save plumbing, exactly as {@link useDocumentNode} does for TipTap.
 * Backed by the injected client (the `documentNodes.workbook`/`updateWorkbook` seam).
 */
export function useSpreadsheet(
  nodeId: NodeId,
  options: UseSpreadsheetOptions = {},
): UseSpreadsheetReturn {
  const client = useVaulterClient()
  const debounceMs = options.debounceMs ?? 800

  const workbook = shallowRef<SpreadsheetWorkbook | null>(null)
  const head = shallowRef<SpreadsheetVersion | null>(null)
  const handler = shallowRef<HandlerDescriptor | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<unknown>(null)

  let timer: ReturnType<typeof setTimeout> | null = null
  let pendingOptions: UpdateDocumentContentOptions | undefined

  async function load(): Promise<SpreadsheetVersion> {
    loading.value = true
    error.value = null
    try {
      const version = await client.documentNodes.workbook(nodeId)
      head.value = version
      workbook.value = version.content
      return version
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  function setWorkbook(next: SpreadsheetWorkbook, opts?: UpdateDocumentContentOptions): void {
    workbook.value = next
    pendingOptions = opts
    if (timer !== null) {
      clearTimeout(timer)
    }
    timer = setTimeout(() => {
      timer = null
      void save(pendingOptions)
    }, debounceMs)
  }

  async function flush(): Promise<SpreadsheetVersion | null> {
    if (timer === null) {
      return null
    }
    clearTimeout(timer)
    timer = null
    return save(pendingOptions)
  }

  async function save(opts?: UpdateDocumentContentOptions): Promise<SpreadsheetVersion> {
    if (workbook.value === null) {
      throw new Error('[Vaulter] Nothing to save — the workbook has not been loaded or set.')
    }
    saving.value = true
    error.value = null
    try {
      const version = await client.documentNodes.updateWorkbook(nodeId, workbook.value, opts)
      head.value = version
      return version
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      saving.value = false
    }
  }

  async function loadHandler(): Promise<HandlerDescriptor> {
    handler.value = await client.documentNodes.handler(nodeId)
    return handler.value
  }

  return {
    workbook, head, handler, loading, saving, error,
    load, setWorkbook, flush, save, loadHandler,
  }
}

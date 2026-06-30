import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type {
  DocumentVersion,
  HandlerDescriptor,
  NodeDocumentExport,
  NodeId,
  TipTapDocument,
  UpdateDocumentContentOptions,
} from '../types'

export interface UseDocumentNodeOptions {
  /** Autosave debounce window in ms (default 800). */
  debounceMs?: number
}

export interface UseDocumentNodeReturn {
  content: ShallowRef<TipTapDocument | null>
  head: ShallowRef<DocumentVersion | null>
  versions: ShallowRef<DocumentVersion[]>
  handler: ShallowRef<HandlerDescriptor | null>
  loading: Ref<boolean>
  saving: Ref<boolean>
  error: Ref<unknown>
  /** Load the current-head content (and capture the head version). */
  load: () => Promise<DocumentVersion>
  /** Set the working content and schedule a debounced autosave. */
  setContent: (content: TipTapDocument, options?: UpdateDocumentContentOptions) => void
  /** Force-flush a pending autosave immediately. */
  flush: () => Promise<DocumentVersion | null>
  /** Save the current content now (no debounce). */
  save: (options?: UpdateDocumentContentOptions) => Promise<DocumentVersion>
  loadVersions: () => Promise<DocumentVersion[]>
  createNamedVersion: (label: string, keepForever?: boolean) => Promise<DocumentVersion>
  restoreVersion: (versionId: NodeId) => Promise<DocumentVersion>
  loadHandler: () => Promise<HandlerDescriptor>
  exportAs: (format?: string) => Promise<NodeDocumentExport>
}

/**
 * Reactive editing of a document node: load its head content, edit it with a
 * debounced autosave, snapshot/restore named versions, resolve its handler and
 * export it. Backed by the injected client.
 */
export function useDocumentNode(
  nodeId: NodeId,
  options: UseDocumentNodeOptions = {},
): UseDocumentNodeReturn {
  const client = useVaulterClient()
  const debounceMs = options.debounceMs ?? 800

  const content = shallowRef<TipTapDocument | null>(null)
  const head = shallowRef<DocumentVersion | null>(null)
  const versions = shallowRef<DocumentVersion[]>([])
  const handler = shallowRef<HandlerDescriptor | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<unknown>(null)

  let timer: ReturnType<typeof setTimeout> | null = null
  let pendingOptions: UpdateDocumentContentOptions | undefined

  async function load(): Promise<DocumentVersion> {
    loading.value = true
    error.value = null
    try {
      const version = await client.documentNodes.content(nodeId)
      head.value = version
      content.value = version.content
      return version
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  function setContent(next: TipTapDocument, opts?: UpdateDocumentContentOptions): void {
    content.value = next
    pendingOptions = opts
    if (timer !== null) {
      clearTimeout(timer)
    }
    timer = setTimeout(() => {
      timer = null
      void save(pendingOptions)
    }, debounceMs)
  }

  async function flush(): Promise<DocumentVersion | null> {
    if (timer === null) {
      return null
    }
    clearTimeout(timer)
    timer = null
    return save(pendingOptions)
  }

  async function save(opts?: UpdateDocumentContentOptions): Promise<DocumentVersion> {
    if (content.value === null) {
      throw new Error('[Vaulter] Nothing to save — content has not been loaded or set.')
    }
    saving.value = true
    error.value = null
    try {
      const version = await client.documentNodes.updateContent(nodeId, content.value, opts)
      head.value = version
      return version
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      saving.value = false
    }
  }

  async function loadVersions(): Promise<DocumentVersion[]> {
    versions.value = (await client.documentNodes.versions(nodeId)).data
    return versions.value
  }

  async function createNamedVersion(label: string, keepForever?: boolean): Promise<DocumentVersion> {
    const version = await client.documentNodes.createNamedVersion(nodeId, label, keepForever)
    versions.value = [version, ...versions.value]
    return version
  }

  async function restoreVersion(versionId: NodeId): Promise<DocumentVersion> {
    const restored = await client.documentNodes.restoreVersion(nodeId, versionId)
    head.value = restored
    if (restored.content !== null) {
      content.value = restored.content
    }
    return restored
  }

  async function loadHandler(): Promise<HandlerDescriptor> {
    handler.value = await client.documentNodes.handler(nodeId)
    return handler.value
  }

  function exportAs(format?: string): Promise<NodeDocumentExport> {
    return client.documentNodes.export(nodeId, format)
  }

  return {
    content, head, versions, handler, loading, saving, error,
    load, setContent, flush, save,
    loadVersions, createNamedVersion, restoreVersion, loadHandler, exportAs,
  }
}

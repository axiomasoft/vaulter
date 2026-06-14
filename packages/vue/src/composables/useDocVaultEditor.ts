import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useDocVaultClient } from './useDocVaultClient'
import type { DocVaultDocument, DocumentId, TipTapDocument } from '../types'

export interface UseDocVaultEditorOptions {
  /** Save automatically after `setContent` (default: false). */
  autosave?: boolean
  /** Debounce window for autosave, in ms (default: 2000). */
  autosaveDelay?: number
}

export interface UseDocVaultEditorReturn {
  document: ShallowRef<DocVaultDocument | null>
  content: ShallowRef<TipTapDocument | null>
  loading: Ref<boolean>
  saving: Ref<boolean>
  dirty: Ref<boolean>
  load: () => Promise<DocVaultDocument>
  setContent: (content: TipTapDocument) => void
  save: () => Promise<DocVaultDocument | null>
  snapshot: (label?: string) => Promise<void>
}

/**
 * Loads a document, tracks unsaved changes and persists content (optionally with
 * debounced autosave). The actual TipTap/Yjs binding is wired in M3 — feed editor
 * output to `setContent` and read `content` to seed the editor.
 */
export function useDocVaultEditor(
  documentId: DocumentId,
  options: UseDocVaultEditorOptions = {},
): UseDocVaultEditorReturn {
  const client = useDocVaultClient()
  const document = shallowRef<DocVaultDocument | null>(null)
  const content = shallowRef<TipTapDocument | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const dirty = ref(false)

  let timer: ReturnType<typeof setTimeout> | null = null

  async function load(): Promise<DocVaultDocument> {
    loading.value = true
    try {
      const loaded = await client.documents.get(documentId)
      document.value = loaded
      content.value = loaded.content
      dirty.value = false
      return loaded
    } finally {
      loading.value = false
    }
  }

  async function save(): Promise<DocVaultDocument | null> {
    if (content.value === null) {
      return null
    }

    saving.value = true
    try {
      const updated = await client.documents.update(documentId, { content: content.value })
      document.value = updated
      dirty.value = false
      return updated
    } finally {
      saving.value = false
    }
  }

  function setContent(next: TipTapDocument): void {
    content.value = next
    dirty.value = true

    if (options.autosave) {
      if (timer !== null) {
        clearTimeout(timer)
      }
      timer = setTimeout(() => {
        void save()
      }, options.autosaveDelay ?? 2000)
    }
  }

  async function snapshot(label?: string): Promise<void> {
    await client.versions.create(documentId, { snapshot_label: label ?? null })
  }

  return { document, content, loading, saving, dirty, load, setContent, save, snapshot }
}

import { ref, type Ref } from 'vue'
import { useDocVaultClient } from './useDocVaultClient'
import type { DocumentExport, DocumentId, ExportFormat } from '../types'

export interface UseDocumentExportReturn {
  exporting: Ref<boolean>
  error: Ref<unknown>
  fetch: (format?: ExportFormat) => Promise<DocumentExport>
  download: (format?: ExportFormat) => Promise<DocumentExport>
}

/**
 * Exports a document to HTML or Markdown. `download` triggers a browser download
 * of the rendered content (no-op outside a DOM environment).
 */
export function useDocumentExport(documentId: DocumentId): UseDocumentExportReturn {
  const client = useDocVaultClient()
  const exporting = ref(false)
  const error = ref<unknown>(null)

  async function fetch(format?: ExportFormat): Promise<DocumentExport> {
    exporting.value = true
    error.value = null
    try {
      return await client.documents.export(documentId, format)
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      exporting.value = false
    }
  }

  async function download(format?: ExportFormat): Promise<DocumentExport> {
    const result = await fetch(format)

    if (typeof document !== 'undefined' && typeof URL?.createObjectURL === 'function') {
      const blob = new Blob([result.content], { type: result.format === 'markdown' ? 'text/markdown' : 'text/html' })
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = result.filename
      anchor.click()
      URL.revokeObjectURL(url)
    }

    return result
  }

  return { exporting, error, fetch, download }
}

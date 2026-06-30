import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useVaulterClient } from './useVaulterClient'
import type {
  BulkDocumentsPayload,
  BulkResult,
  CreateDocumentPayload,
  VaulterDocument,
  DocumentId,
  ListDocumentsQuery,
  UpdateDocumentPayload,
  WorkspaceQuery,
} from '../types'

export interface UseDocumentsReturn {
  documents: ShallowRef<VaulterDocument[]>
  trashed: ShallowRef<VaulterDocument[]>
  loading: Ref<boolean>
  error: Ref<unknown>
  list: (query: ListDocumentsQuery) => Promise<VaulterDocument[]>
  listTrashed: (query: WorkspaceQuery) => Promise<VaulterDocument[]>
  create: (payload: CreateDocumentPayload) => Promise<VaulterDocument>
  update: (id: DocumentId, payload: UpdateDocumentPayload) => Promise<VaulterDocument>
  move: (id: DocumentId, folderId: DocumentId | null) => Promise<VaulterDocument>
  duplicate: (id: DocumentId) => Promise<VaulterDocument>
  remove: (id: DocumentId) => Promise<void>
  forceDelete: (id: DocumentId) => Promise<void>
  bulk: (payload: BulkDocumentsPayload) => Promise<BulkResult>
}

/**
 * Reactive CRUD over a workspace's documents, backed by the injected client.
 */
export function useDocuments(): UseDocumentsReturn {
  const client = useVaulterClient()
  const documents = shallowRef<VaulterDocument[]>([])
  const trashed = shallowRef<VaulterDocument[]>([])
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function list(query: ListDocumentsQuery): Promise<VaulterDocument[]> {
    loading.value = true
    error.value = null
    try {
      documents.value = await client.documents.list(query)
      return documents.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function listTrashed(query: WorkspaceQuery): Promise<VaulterDocument[]> {
    trashed.value = await client.documents.trashed(query)
    return trashed.value
  }

  async function create(payload: CreateDocumentPayload): Promise<VaulterDocument> {
    const created = await client.documents.create(payload)
    documents.value = [...documents.value, created]
    return created
  }

  async function update(id: DocumentId, payload: UpdateDocumentPayload): Promise<VaulterDocument> {
    const updated = await client.documents.update(id, payload)
    documents.value = documents.value.map((doc) => (doc.id === id ? updated : doc))
    return updated
  }

  async function move(id: DocumentId, folderId: DocumentId | null): Promise<VaulterDocument> {
    const moved = await client.documents.move(id, folderId)
    documents.value = documents.value.map((doc) => (doc.id === id ? moved : doc))
    return moved
  }

  async function duplicate(id: DocumentId): Promise<VaulterDocument> {
    const copy = await client.documents.duplicate(id)
    documents.value = [...documents.value, copy]
    return copy
  }

  async function remove(id: DocumentId): Promise<void> {
    await client.documents.remove(id)
    documents.value = documents.value.filter((doc) => doc.id !== id)
  }

  async function forceDelete(id: DocumentId): Promise<void> {
    await client.documents.forceDelete(id)
    documents.value = documents.value.filter((doc) => doc.id !== id)
    trashed.value = trashed.value.filter((doc) => doc.id !== id)
  }

  async function bulk(payload: BulkDocumentsPayload): Promise<BulkResult> {
    const result = await client.documents.bulk(payload)
    const processed = new Set(result.processed.map(String))
    if (payload.action === 'delete' || payload.action === 'force-delete') {
      documents.value = documents.value.filter((doc) => ! processed.has(String(doc.id)))
    }
    return result
  }

  return {
    documents, trashed, loading, error,
    list, listTrashed, create, update, move, duplicate, remove, forceDelete, bulk,
  }
}

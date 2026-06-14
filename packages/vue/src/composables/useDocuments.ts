import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useDocVaultClient } from './useDocVaultClient'
import type {
  BulkDocumentsPayload,
  BulkResult,
  CreateDocumentPayload,
  DocVaultDocument,
  DocumentId,
  InstantiateTemplatePayload,
  ListDocumentsQuery,
  UpdateDocumentPayload,
  WorkspaceQuery,
} from '../types'

export interface UseDocumentsReturn {
  documents: ShallowRef<DocVaultDocument[]>
  trashed: ShallowRef<DocVaultDocument[]>
  loading: Ref<boolean>
  error: Ref<unknown>
  list: (query: ListDocumentsQuery) => Promise<DocVaultDocument[]>
  listTrashed: (query: WorkspaceQuery) => Promise<DocVaultDocument[]>
  create: (payload: CreateDocumentPayload) => Promise<DocVaultDocument>
  update: (id: DocumentId, payload: UpdateDocumentPayload) => Promise<DocVaultDocument>
  move: (id: DocumentId, folderId: DocumentId | null) => Promise<DocVaultDocument>
  duplicate: (id: DocumentId) => Promise<DocVaultDocument>
  instantiate: (id: DocumentId, payload?: InstantiateTemplatePayload) => Promise<DocVaultDocument>
  remove: (id: DocumentId) => Promise<void>
  forceDelete: (id: DocumentId) => Promise<void>
  bulk: (payload: BulkDocumentsPayload) => Promise<BulkResult>
}

/**
 * Reactive CRUD over a workspace's documents, backed by the injected client.
 */
export function useDocuments(): UseDocumentsReturn {
  const client = useDocVaultClient()
  const documents = shallowRef<DocVaultDocument[]>([])
  const trashed = shallowRef<DocVaultDocument[]>([])
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function list(query: ListDocumentsQuery): Promise<DocVaultDocument[]> {
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

  async function listTrashed(query: WorkspaceQuery): Promise<DocVaultDocument[]> {
    trashed.value = await client.documents.trashed(query)
    return trashed.value
  }

  async function create(payload: CreateDocumentPayload): Promise<DocVaultDocument> {
    const created = await client.documents.create(payload)
    documents.value = [...documents.value, created]
    return created
  }

  async function update(id: DocumentId, payload: UpdateDocumentPayload): Promise<DocVaultDocument> {
    const updated = await client.documents.update(id, payload)
    documents.value = documents.value.map((doc) => (doc.id === id ? updated : doc))
    return updated
  }

  async function move(id: DocumentId, folderId: DocumentId | null): Promise<DocVaultDocument> {
    const moved = await client.documents.move(id, folderId)
    documents.value = documents.value.map((doc) => (doc.id === id ? moved : doc))
    return moved
  }

  async function duplicate(id: DocumentId): Promise<DocVaultDocument> {
    const copy = await client.documents.duplicate(id)
    documents.value = [...documents.value, copy]
    return copy
  }

  async function instantiate(id: DocumentId, payload?: InstantiateTemplatePayload): Promise<DocVaultDocument> {
    const created = await client.documents.instantiate(id, payload)
    documents.value = [...documents.value, created]
    return created
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
    list, listTrashed, create, update, move, duplicate, instantiate, remove, forceDelete, bulk,
  }
}

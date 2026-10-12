import { describe, expect, it, vi } from 'vitest'
import { useDocument, useRevisions, useVaulterClient } from '../../src'
import { mountWith } from '../support/mount'
import { pageOf } from '../support/nodes'

// The regions between the recipe markers are copied into the guides byte for byte; the guides'
// own `import` lines are the imports of this file. Each region runs inside a component's
// setup(), where the injected client is a fake.
const nodeId = '77'
const driveId = '42'
const head = { type: 'vaulter/rich-text', content: { type: 'doc', content: [] }, etag: '"77.0.3"' }

async function inSetup(client: unknown, recipe: () => Promise<void>): Promise<void> {
  let done: Promise<void> = Promise.resolve()
  mountWith(client, () => {
    done = recipe()
    return {}
  })
  await done
}

describe('documents guide', () => {
  it('creates a document through the client', async () => {
    const create = vi.fn().mockResolvedValue({ id: '77', kind: 'document' })

    await inSetup({ documents: { create } }, async () => {
      // [recipe:vue-documents-create]
      const client = useVaulterClient()

      const node = await client.documents.create(driveId, {
        name: 'Design notes',
        parentId: null,             // null = drive root
        type: 'vaulter/rich-text',
        content: { type: 'doc', content: [] },
      })
      // [/recipe]
      expect(node.id).toBe('77')
    })

    expect(create).toHaveBeenCalledWith('42', expect.objectContaining({ name: 'Design notes', type: 'vaulter/rich-text' }))
  })

  it('loads the head content', async () => {
    const content = vi.fn().mockResolvedValue(head)

    await inSetup({ documents: { content } }, async () => {
      // [recipe:vue-documents-read]
      const { content, head, load, loading } = useDocument(nodeId)

      await load()
      // content.value → TipTapDocument | null
      // head.value    → the head DocumentContent (its `etag` goes into the next save)
      // [/recipe]
      expect(content.value).toEqual(head.value?.content)
      expect(loading.value).toBe(false)
    })
  })

  it('autosaves, flushes and saves with the loaded ETag', async () => {
    const nextTipTapDoc = { type: 'doc', content: [{ type: 'paragraph' }] }
    const update = vi.fn().mockResolvedValue({ ...head, content: nextTipTapDoc, etag: '"77.0.4"' })

    await inSetup({ documents: { content: vi.fn().mockResolvedValue(head), update } }, async () => {
      // [recipe:vue-documents-autosave]
      const { load, setContent, flush, save } = useDocument(nodeId, {
        debounceMs: 800,            // the CLIENT-side debounce before a save is sent
      })

      // Load first: a save carries the ETag of the content it is based on.
      await load()

      // As the editor emits changes, schedule a debounced save:
      setContent(nextTipTapDoc)

      // Force-flush a pending save (e.g. on blur / before navigation):
      await flush()

      // Save immediately, no debounce:
      await save()
      // [/recipe]
    })

    expect(update).toHaveBeenCalledTimes(2)
    expect(update).toHaveBeenNthCalledWith(1, '77', nextTipTapDoc, { etag: '"77.0.3"' })
  })

  it('takes a named snapshot', async () => {
    const snapshot = vi.fn().mockResolvedValue({ id: '12', label: 'Before client review', keep_forever: true })

    await inSetup({ documents: { snapshot } }, async () => {
      // [recipe:vue-documents-snapshot]
      const { snapshot } = useRevisions(nodeId)

      // keepForever = true pins it out of the prune window
      const version = await snapshot({ label: 'Before client review', keepForever: true })
      // [/recipe]
      expect(version.label).toBe('Before client review')
    })

    expect(snapshot).toHaveBeenCalledWith('77', { label: 'Before client review', keepForever: true })
  })

  it('lists the revisions', async () => {
    const revisions = vi.fn().mockResolvedValue(pageOf([{ id: '12' }, { id: '11' }]))

    await inSetup({ documents: { revisions } }, async () => {
      // [recipe:vue-documents-revisions]
      const { revisions, list } = useRevisions(nodeId)

      await list()
      // revisions.value → DocumentRevision[] (newest first; `content` omitted)
      // [/recipe]
      expect(revisions.value.map((revision) => revision.id)).toEqual(['12', '11'])
    })
  })

  it('restores a revision over the current head', async () => {
    const versionId = '12'
    const restoreRevision = vi.fn().mockResolvedValue({ ...head, etag: '"77.1.4"' })

    await inSetup({ documents: { content: vi.fn().mockResolvedValue(head), restoreRevision } }, async () => {
      // [recipe:vue-documents-restore]
      const { head, load } = useDocument(nodeId)
      const { restore } = useRevisions(nodeId)

      await load()
      const restored = await restore(versionId, { etag: head.value!.etag! })
      // the new head is a copy of versionId, labelled "Restored from #N"
      // [/recipe]
      expect(restored.etag).toBe('"77.1.4"')
    })
  })
})

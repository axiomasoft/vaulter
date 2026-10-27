import { describe, expect, it } from 'vitest'
import { createVaulterClient } from '../../src/api/client'
import type { Drive, DocumentContent, FileRevision, Grant, Node, Share } from '../../src/api/types'
import type { components } from '../../src/api/schema'
import type { Wide } from '../support/wide'
import { fetchSequence, jsonResponse } from '../support/http'
import nodeExample from '../../../../openapi/examples/node.json'
import driveExample from '../../../../openapi/examples/drive.json'
import documentContentExample from '../../../../openapi/examples/document-content.json'
import grantExample from '../../../../openapi/examples/grant.json'
import shareExample from '../../../../openapi/examples/share.json'
import fileRevisionsExample from '../../../../openapi/examples/file-revisions.json'
import changePageExample from '../../../../openapi/examples/change-page.json'

// Assigning an example to `Wide<T>` makes vue-tsc fail when the example and the generated
// type disagree on keys, nullability or primitive types; the tests below pin the enums.
const node: Wide<Node> = nodeExample.body.data
const drive: Wide<Drive> = driveExample.body.data
const documentContent: Wide<Omit<DocumentContent, 'etag'>> = documentContentExample.body.data
const grant: Wide<Grant> = grantExample.body.data
const share: Wide<Share> = shareExample.body.data
const revisions: Array<Wide<FileRevision>> = fileRevisionsExample.body.data
const changePage: Wide<components['schemas']['ChangePage']> = changePageExample.body

describe('openapi examples are assignable to the generated client types', () => {
  it('Node: enum values and the shape of a presentation-less node', () => {
    expect(['folder', 'file', 'document']).toContain(node.kind)
    expect(['not_required', 'pending', 'clean', 'infected', 'failed']).toContain(node.scan_status)
    expect(Object.keys(node.abilities).sort()).toEqual([
      'comment', 'copy', 'create', 'delete', 'download', 'edit', 'manage', 'move', 'restore', 'share', 'trash', 'view',
    ])
    expect(node.viewer).toHaveProperty('starred')
    expect(node).not.toHaveProperty('is_pinned')
    expect(node).not.toHaveProperty('rich_text')
    expect(node).not.toHaveProperty('handler')
    expect(node).not.toHaveProperty('storage_id')
    expect(typeof node.etag).toBe('string')
  })

  it('Drive, Grant, Share, FileRevision and the change feed keep their enums', () => {
    expect(['active', 'archived', 'deleting']).toContain(drive.status)
    expect(['item', 'subtree']).toContain(grant.scope)
    expect(['anyone', 'authenticated', 'tenant']).toContain(share.audience)
    expect(grant.subject).toEqual(expect.objectContaining({ type: expect.any(String), id: expect.any(String) }))
    expect(revisions.length).toBeGreaterThan(0)
    expect(['upsert', 'remove']).toContain(changePage.data[0].operation)
    expect(documentContent.collab_epoch).toEqual(expect.any(Number))
  })
})

describe('the client returns what the server sent', () => {
  it('nodes.get yields an object with exactly the keys of the example', async () => {
    const client = createVaulterClient({
      fetch: fetchSequence(() => jsonResponse(nodeExample.body, nodeExample.status)),
    })

    const result = await client.nodes.get(nodeExample.body.data.id)

    expect(Object.keys(result).sort()).toEqual(Object.keys(nodeExample.body.data).sort())
    expect(result.etag).toBe(nodeExample.body.data.etag)
  })

  it('documents.content keeps the body keys and adds only the etag', async () => {
    const client = createVaulterClient({
      fetch: fetchSequence(() => jsonResponse(documentContentExample.body, 200, { ETag: '"1.1.1"' })),
    })

    const result = await client.documents.content('1')

    expect(Object.keys(result).sort()).toEqual([...Object.keys(documentContentExample.body.data), 'etag'].sort())
  })

  it('drives.get and shares/grants lists unwrap the data envelope', async () => {
    const client = createVaulterClient({
      fetch: fetchSequence(
        () => jsonResponse(driveExample.body),
        () => jsonResponse({ etag: '"t"', data: [grantExample.body.data], meta: { next_cursor: null, per_page: 50 } }),
        () => jsonResponse({ data: [shareExample.body.data], meta: { next_cursor: null, per_page: 50 } }),
      ),
    })

    expect(await client.drives.get('7')).toEqual(driveExample.body.data)
    expect(await client.grants.list('5')).toEqual([grantExample.body.data])
    expect(await client.shares.list('5')).toEqual([shareExample.body.data])
  })
})

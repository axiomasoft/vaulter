import { describe, expect, it, vi } from 'vitest'
import { useNodes, useVaulterClient } from '../../src'
import { mountWith } from '../support/mount'
import { makeNode, pageOf } from '../support/nodes'

// The regions between the recipe markers are copied into the guides byte for byte; the guides'
// own `import` lines are the imports of this file. Each region runs inside a component's
// setup(), where the injected client is a fake.
const driveId = '42'
const folderId = '9'

async function inSetup(client: unknown, recipe: () => Promise<void>): Promise<void> {
  let done: Promise<void> = Promise.resolve()
  mountWith(client, () => {
    done = recipe()
    return {}
  })
  await done
}

describe('trash guide', () => {
  it('lists the trash, trashes, restores and purges through the client', async () => {
    const node = makeNode({ etag: '"n.1"' })
    const trashedNode = makeNode({ etag: '"n.2"' })
    const client = {
      trash: { list: vi.fn().mockResolvedValue(pageOf([trashedNode])) },
      nodes: {
        trash: vi.fn().mockResolvedValue(trashedNode),
        restore: vi.fn().mockResolvedValue(node),
        purge: vi.fn().mockResolvedValue(undefined),
      },
    }

    await inSetup(client, async () => {
      // [recipe:vue-trash-client]
      const client = useVaulterClient()

      const trashed = await client.trash.list(driveId)
      await client.nodes.trash(node.id, { etag: node.etag })                // reversible soft-delete
      await client.nodes.restore(node.id, { etag: trashedNode.etag })       // bring it back
      await client.nodes.purge(node.id, { etag: trashedNode.etag })         // permanent
      // [/recipe]
      expect(trashed.data).toHaveLength(1)
    })

    expect(client.nodes.trash).toHaveBeenCalledWith(node.id, { etag: '"n.1"' })
    expect(client.nodes.restore).toHaveBeenCalledWith(node.id, { etag: '"n.2"' })
    expect(client.nodes.purge).toHaveBeenCalledWith(node.id, { etag: '"n.2"' })
  })

  it('moves and copies through the client', async () => {
    const node = makeNode({ etag: '"n.1"' })
    const client = {
      nodes: {
        move: vi.fn().mockResolvedValue(node),
        copy: vi.fn().mockResolvedValue(node),
      },
    }

    await inSetup(client, async () => {
      // [recipe:vue-trash-move-copy]
      const client = useVaulterClient()

      await client.nodes.move(node.id, folderId, { etag: node.etag })   // or move(node.id, null, …) to un-file
      const copy = await client.nodes.copy(node.id, folderId)
      // [/recipe]
      expect(copy.id).toBe(node.id)
    })

    expect(client.nodes.move).toHaveBeenCalledWith(node.id, folderId, { etag: '"n.1"' })
    expect(client.nodes.copy).toHaveBeenCalledWith(node.id, folderId)
  })

  it('restores and purges a trashed node with useNodes', async () => {
    const trashedNode = makeNode({ etag: '"n.2"' })
    const client = {
      nodes: {
        children: vi.fn().mockResolvedValue(pageOf([])),
        restore: vi.fn().mockResolvedValue(trashedNode),
        purge: vi.fn().mockResolvedValue(undefined),
      },
    }

    await inSetup(client, async () => {
      // [recipe:vue-trash-use-nodes]
      const { restore, purge } = useNodes(driveId)

      await restore(trashedNode)  // bring a node back
      await purge(trashedNode)    // remove it for good
      // [/recipe]
    })

    expect(client.nodes.restore).toHaveBeenCalledWith(trashedNode.id, { etag: '"n.2"' })
    expect(client.nodes.purge).toHaveBeenCalledWith(trashedNode.id, { etag: '"n.2"' })
  })
})

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import type { VaulterClient } from '../src/client'
import { provideVaulterClient } from '../src/plugin'
import { useNodes } from '../src/composables/useNodes'
import { useUpload } from '../src/composables/useUpload'
import { useNodeShares } from '../src/composables/useNodeShares'
import { useDocumentNode } from '../src/composables/useDocumentNode'
import type { CursorPage, Node, NodeShareCreated, TipTapDocument } from '../src/types'

function page<T>(data: T[]): CursorPage<T> {
  return { data, meta: { next_cursor: null, prev_cursor: null } }
}

function node(partial: Partial<Node> & Pick<Node, 'id'>): Node {
  return {
    kind: 'folder', name: '', mime: null, extension: null, size_bytes: null,
    storage_id: 7, parent_id: null, file_id: null, current_version_id: null,
    is_pinned: false, position: null, trashed_at: null, created_at: null,
    updated_at: null, children: null, breadcrumb: null, abilities: {}, handler: null,
    ...partial,
  }
}

/**
 * Mount a component that runs `setup()` against a fake client and exposes whatever
 * it returns so the test can drive the composable.
 */
function mountWith<R>(client: Partial<VaulterClient>, setup: () => R): { vm: R } {
  let captured: R
  const Comp = defineComponent({
    setup() {
      captured = setup()
      return () => h('div')
    },
  })
  mount(Comp, {
    global: {
      plugins: [
        {
          install(app) {
            provideVaulterClient(app, client as VaulterClient)
          },
        },
      ],
    },
  })
  // captured is assigned synchronously during setup.
  return { vm: captured! }
}

describe('useNodes', () => {
  it('loads a folder and updates the reactive list after createFolder', async () => {
    const list = vi.fn().mockResolvedValue(page([node({ id: 1, kind: 'file', name: 'a' })]))
    const createFolder = vi.fn().mockResolvedValue(node({ id: 2, kind: 'folder', name: 'New', parent_id: null }))
    const { vm } = mountWith({ nodes: { list, createFolder } as never }, () => useNodes(7))

    await vm.refresh()
    expect(vm.nodes.value).toHaveLength(1)

    await vm.createFolder({ name: 'New' })
    expect(list).toHaveBeenCalledWith(7, { parentId: null })
    // The new folder (same parent = root) is appended optimistically.
    expect(vm.nodes.value.map((n) => n.id)).toEqual([1, 2])
  })

  it('drops a node from the list after trash', async () => {
    const list = vi.fn().mockResolvedValue(page([node({ id: 1, kind: 'file' }), node({ id: 2, kind: 'file' })]))
    const trash = vi.fn().mockResolvedValue(node({ id: 1, kind: 'file', trashed_at: 'now' }))
    const { vm } = mountWith({ nodes: { list, trash } as never }, () => useNodes(7))

    await vm.refresh()
    await vm.trash(1)

    expect(trash).toHaveBeenCalledWith(1)
    expect(vm.nodes.value.map((n) => n.id)).toEqual([2])
  })

  it('navigates into a folder and re-lists with the new parent', async () => {
    const list = vi.fn()
      .mockResolvedValueOnce(page([node({ id: 1 })]))
      .mockResolvedValueOnce(page([node({ id: 5 }), node({ id: 6 })]))
    const { vm } = mountWith({ nodes: { list } as never }, () => useNodes(7))

    await vm.refresh()
    await vm.navigate(99)

    expect(vm.parentId.value).toBe(99)
    expect(list).toHaveBeenLastCalledWith(7, { parentId: 99 })
    expect(vm.nodes.value.map((n) => n.id)).toEqual([5, 6])
  })
})

describe('useUpload', () => {
  it('reports progress 0..1 during a resumable upload', async () => {
    // A fake resumable that emits progress then resolves with a node.
    const resumable = vi.fn().mockImplementation((_storage, file: Blob, options) => ({
      start: async () => {
        options.onProgress(0, file.size)
        options.onProgress(file.size, file.size)
        return node({ id: 50, kind: 'file' })
      },
      abort: vi.fn(),
    }))
    const { vm } = mountWith({ uploads: { resumable } as never }, () => useUpload(7))

    const file = new Blob(['0123456789'])
    const created = await vm.uploadResumable(file)

    expect(created.id).toBe(50)
    expect(vm.progress.value).toBe(1)
    expect(vm.sent.value).toBe(10)
    expect(vm.total.value).toBe(10)
  })

  it('captures the error and resets the uploading flag on failure', async () => {
    const upload = vi.fn().mockRejectedValue(new Error('boom'))
    const { vm } = mountWith({ uploads: { upload } as never }, () => useUpload(7))

    await expect(vm.upload(new Blob(['x']))).rejects.toThrow('boom')
    expect(vm.uploading.value).toBe(false)
    expect(vm.error.value).toBeInstanceOf(Error)
  })
})

describe('useNodeShares', () => {
  it('surfaces the one-time token via lastCreated and prepends the share', async () => {
    const created: NodeShareCreated = {
      id: 1, node_id: 9, token_prefix: 'abc', token: 'abc.SECRET', url: 'https://x/s/abc.SECRET',
      permissions: 1, level: 'reader', scope: 'item', has_password: false, require_login: false,
      allow_discovery: false, expires_at: null, max_uses: null, use_count: 0, revoked_at: null, created_at: null,
    }
    const create = vi.fn().mockResolvedValue(created)
    const { vm } = mountWith({ nodeShares: { create } as never }, () => useNodeShares(9))

    const result = await vm.create({ level: 'reader' })

    expect(result.token).toBe('abc.SECRET')
    expect(vm.lastCreated.value?.token).toBe('abc.SECRET')
    expect(vm.shares.value).toHaveLength(1)

    vm.clearLastCreated()
    expect(vm.lastCreated.value).toBeNull()
  })
})

describe('useDocumentNode', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('debounces autosave: a single save fires after the debounce window', async () => {
    const doc: TipTapDocument = { type: 'doc', content: [] }
    const updateContent = vi.fn().mockResolvedValue({ id: 2, node_id: 20, is_autosave: true, version_no: 2, content: null } as never)
    const { vm } = mountWith({ documentNodes: { updateContent } as never }, () => useDocumentNode(20, { debounceMs: 500 }))

    // Three rapid edits within the window collapse to one save.
    vm.setContent({ ...doc, content: [{ type: 'paragraph' }] })
    vm.setContent({ ...doc, content: [{ type: 'paragraph' }, {}] })
    vm.setContent({ ...doc, content: [{ type: 'paragraph' }, {}, {}] })

    expect(updateContent).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(500)
    await nextTick()

    expect(updateContent).toHaveBeenCalledTimes(1)
    // It saves the LAST content set.
    expect(updateContent.mock.calls[0][1].content).toHaveLength(3)
  })

  it('flush() saves the pending content immediately', async () => {
    const doc: TipTapDocument = { type: 'doc', content: [{ type: 'paragraph' }] }
    const updateContent = vi.fn().mockResolvedValue({ id: 3, node_id: 20, version_no: 3, content: null } as never)
    const { vm } = mountWith({ documentNodes: { updateContent } as never }, () => useDocumentNode(20))

    vm.setContent(doc)
    expect(updateContent).not.toHaveBeenCalled()

    await vm.flush()
    expect(updateContent).toHaveBeenCalledTimes(1)
  })
})

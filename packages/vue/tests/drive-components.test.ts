import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, markRaw, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import VaulterDrive from '../src/components/VaulterDrive.vue'
import VaulterDriveBreadcrumb from '../src/components/VaulterDriveBreadcrumb.vue'
import VaulterNodeList from '../src/components/VaulterNodeList.vue'
import VaulterUploader from '../src/components/VaulterUploader.vue'
import VaulterNodeGrantsPanel from '../src/components/VaulterNodeGrantsPanel.vue'
import VaulterNodeSharePanel from '../src/components/VaulterNodeSharePanel.vue'
import VaulterDocumentNode from '../src/components/VaulterDocumentNode.vue'
import { humanByteSize, nodeIconKind } from '../src/components/drive/nodeDisplay'
import { docVaultClientKey } from '../src/composables/useVaulterClient'
import type { VaulterClient } from '../src/client'
import type {
  CursorPage,
  HandlerDescriptor,
  Node,
  NodeAbilities,
  NodeGrant,
  NodeShare,
  NodeShareCreated,
} from '../src/types'

// ─── Fakes ──────────────────────────────────────────────────────────────────

function page<T>(data: T[]): CursorPage<T> {
  return { data, meta: { next_cursor: null, prev_cursor: null } }
}

function node(partial: Partial<Node> & Pick<Node, 'id'>): Node {
  return {
    kind: 'folder', name: '', mime: null, extension: null, size_bytes: null,
    storage_id: 7, parent_id: null, file_id: null, current_version_id: null,
    is_pinned: false, position: null, trashed_at: null, created_at: null,
    updated_at: null, children: null, breadcrumb: null,
    abilities: {} as NodeAbilities, handler: null,
    ...partial,
  }
}

/** Provide a (partial) fake client to a mounted component. */
function withClient(client: Partial<VaulterClient>) {
  return { global: { provide: { [docVaultClientKey as symbol]: client as VaulterClient } } }
}

/** Drain the microtask queue + a few render ticks so async handlers settle. */
async function flushAll(): Promise<void> {
  for (let i = 0; i < 4; i += 1) {
    await Promise.resolve()
    await nextTick()
  }
}

// ─── Display helpers ──────────────────────────────────────────────────────────

describe('nodeDisplay helpers', () => {
  it('buckets nodes by kind / mime / extension', () => {
    expect(nodeIconKind(node({ id: 1, kind: 'folder' }))).toBe('folder')
    expect(nodeIconKind(node({ id: 2, kind: 'document' }))).toBe('document')
    expect(nodeIconKind(node({ id: 3, kind: 'file', mime: 'image/png' }))).toBe('image')
    expect(nodeIconKind(node({ id: 4, kind: 'file', mime: 'application/pdf' }))).toBe('pdf')
    expect(nodeIconKind(node({ id: 5, kind: 'file', mime: 'application/zip' }))).toBe('archive')
    expect(nodeIconKind(node({ id: 6, kind: 'file', mime: null, extension: 'mp4' }))).toBe('video')
    expect(nodeIconKind(node({ id: 7, kind: 'file', mime: 'text/plain' }))).toBe('file')
  })

  it('formats byte sizes humanely (and blanks an unsized node)', () => {
    expect(humanByteSize(null)).toBe('')
    expect(humanByteSize(0)).toBe('0 B')
    expect(humanByteSize(512)).toBe('512 B')
    expect(humanByteSize(1024)).toBe('1.0 KB')
    expect(humanByteSize(1.5 * 1024 * 1024)).toBe('1.5 MB')
  })
})

// ─── VaulterDriveBreadcrumb ───────────────────────────────────────────────────

describe('VaulterDriveBreadcrumb', () => {
  it('renders crumbs and emits navigate(null) for the root and the node id for a crumb', async () => {
    const path = [node({ id: 10, kind: 'folder', name: 'Reports' })]
    const wrapper = mount(VaulterDriveBreadcrumb, { props: { path } })

    expect(wrapper.classes()).toContain('vaulter-drive-breadcrumb')

    await wrapper.get('.vaulter-drive-breadcrumb__root').trigger('click')
    expect(wrapper.emitted('navigate')?.[0]).toEqual([null])

    const crumbs = wrapper.findAll('.vaulter-drive-breadcrumb__crumb')
    // root + one ancestor
    await crumbs[crumbs.length - 1].trigger('click')
    expect(wrapper.emitted('navigate')?.[1]).toEqual([10])
  })
})

// ─── VaulterNodeList ──────────────────────────────────────────────────────────

describe('VaulterNodeList', () => {
  it('hides actions the actor cannot do (per node abilities)', () => {
    const nodes = [
      node({ id: 1, kind: 'file', name: 'a', abilities: { rename: true, trash: false, download: true } }),
    ]
    const wrapper = mount(VaulterNodeList, {
      props: { nodes, actions: ['rename', 'trash', 'download'] },
    })

    const labels = wrapper.findAll('.vaulter-node-list__action').map((b) => b.text())
    expect(labels).toContain('rename')
    expect(labels).toContain('download')
    // trash ability is false → never offered.
    expect(labels).not.toContain('trash')
  })

  it('emits open on dblclick and select on click', async () => {
    const nodes = [node({ id: 9, kind: 'file', name: 'doc', abilities: {} })]
    const wrapper = mount(VaulterNodeList, { props: { nodes } })

    const row = wrapper.get('.vaulter-node-list__item')
    await row.trigger('click')
    expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({ id: 9 })

    await row.trigger('dblclick')
    expect(wrapper.emitted('open')?.[0]?.[0]).toMatchObject({ id: 9 })
  })

  it('emits action with the node when a row action is clicked', async () => {
    const nodes = [node({ id: 4, kind: 'file', name: 'x', abilities: { rename: true } })]
    const wrapper = mount(VaulterNodeList, { props: { nodes, actions: ['rename'] } })

    await wrapper.get('.vaulter-node-list__action--rename').trigger('click')
    expect(wrapper.emitted('action')?.[0]).toEqual(['rename', expect.objectContaining({ id: 4 })])
  })

  it('renders the empty state when there are no nodes', () => {
    const wrapper = mount(VaulterNodeList, { props: { nodes: [] } })
    expect(wrapper.find('.vaulter-node-list__empty').exists()).toBe(true)
  })
})

// ─── VaulterDrive ─────────────────────────────────────────────────────────────

describe('VaulterDrive', () => {
  function driveClient(initial: Node[], childByParent: Record<string, Node[]> = {}) {
    const list = vi.fn().mockImplementation((_storageId, query?: { parentId?: number | null }) => {
      const parent = query?.parentId ?? null
      if (parent === null) {
        return Promise.resolve(page(initial))
      }
      return Promise.resolve(page(childByParent[String(parent)] ?? []))
    })
    const show = vi.fn().mockResolvedValue(node({ id: 1, kind: 'folder', name: 'Folder', breadcrumb: [] }))
    return {
      list,
      show,
      client: { nodes: { list, show } } as unknown as Partial<VaulterClient>,
    }
  }

  it('renders a node list from the client and emits open-node for a file', async () => {
    const nodes = [
      node({ id: 1, kind: 'folder', name: 'Folder', abilities: {} }),
      node({ id: 2, kind: 'file', name: 'file.txt', abilities: {} }),
    ]
    const { client, list } = driveClient(nodes)
    const wrapper = mount(VaulterDrive, { props: { storageId: 7 }, ...withClient(client) })

    expect(wrapper.classes()).toContain('vaulter-drive')
    await nextTick()
    await nextTick()
    expect(list).toHaveBeenCalledWith(7, { parentId: null })

    const rows = wrapper.findAll('.vaulter-node-list__item')
    expect(rows).toHaveLength(2)

    // Double-click the FILE row → open-node emitted (folder would navigate instead).
    await rows[1].trigger('dblclick')
    expect(wrapper.emitted('open-node')?.[0]?.[0]).toMatchObject({ id: 2, kind: 'file' })
  })

  it('navigates into a folder on open (folder is handled internally)', async () => {
    const root = [node({ id: 1, kind: 'folder', name: 'Folder', abilities: {} })]
    const children = { '1': [node({ id: 5, kind: 'file', name: 'inner.txt', abilities: {} })] }
    const { client, list } = driveClient(root, children)
    const wrapper = mount(VaulterDrive, { props: { storageId: 7 }, ...withClient(client) })

    await nextTick()
    await nextTick()

    await wrapper.findAll('.vaulter-node-list__item')[0].trigger('dblclick')
    await nextTick()
    await nextTick()

    // It re-listed with the folder as parent, and did NOT emit open-node.
    expect(list).toHaveBeenLastCalledWith(7, { parentId: 1 })
    expect(wrapper.emitted('open-node')).toBeUndefined()
    expect(wrapper.emitted('navigate')?.[0]).toEqual([1])
  })
})

// ─── VaulterUploader ──────────────────────────────────────────────────────────

describe('VaulterUploader', () => {
  it('uploads a (small) picked file, shows progress and emits uploaded', async () => {
    const created = node({ id: 99, kind: 'file', name: 'pic.txt' })
    const upload = vi.fn().mockResolvedValue(created)
    const wrapper = mount(VaulterUploader, {
      props: { storageId: 7 },
      ...withClient({ uploads: { upload } as never }),
    })

    // Small file → direct path.
    const file = new File(['hello'], 'pic.txt', { type: 'text/plain' })
    const input = wrapper.get('.vaulter-uploader__input').element as HTMLInputElement
    Object.defineProperty(input, 'files', { value: [file], configurable: true })
    await wrapper.get('.vaulter-uploader__input').trigger('change')
    await flushAll()

    expect(upload).toHaveBeenCalledTimes(1)
    expect(upload).toHaveBeenCalledWith(7, file, { parentId: null })
    expect(wrapper.emitted('uploaded')?.[0]?.[0]).toMatchObject({ id: 99 })

    // The finished item reports full progress and a 'done' status.
    const items = (wrapper.vm as unknown as { items: Array<{ status: string, sent: number, size: number }> }).items
    expect(items[0].status).toBe('done')
    expect(items[0].sent).toBe(items[0].size)

    await nextTick()
    expect(wrapper.get('.vaulter-uploader__item').attributes('data-status')).toBe('done')
  })

  it('uses the resumable path for large files and tracks byte progress', async () => {
    const created = node({ id: 100, kind: 'file', name: 'big.bin' })
    const resumable = vi.fn().mockImplementation((_storage, file: Blob, options) => ({
      start: async () => {
        options.onProgress(file.size / 2, file.size)
        options.onProgress(file.size, file.size)
        return created
      },
      abort: vi.fn(),
    }))
    const wrapper = mount(VaulterUploader, {
      props: { storageId: 7, resumableThreshold: 4 },
      ...withClient({ uploads: { resumable } as never }),
    })

    const big = new File([new Uint8Array(10)], 'big.bin')
    const input = wrapper.get('.vaulter-uploader__input').element as HTMLInputElement
    Object.defineProperty(input, 'files', { value: [big], configurable: true })
    await wrapper.get('.vaulter-uploader__input').trigger('change')
    await flushAll()

    expect(resumable).toHaveBeenCalledTimes(1)
    expect(wrapper.emitted('uploaded')?.[0]?.[0]).toMatchObject({ id: 100 })
  })
})

// ─── VaulterNodeGrantsPanel ───────────────────────────────────────────────────

describe('VaulterNodeGrantsPanel', () => {
  function grant(partial: Partial<NodeGrant> & Pick<NodeGrant, 'id'>): NodeGrant {
    return {
      node_id: 9, subject_type: 'App\\User', subject_id: 1, level: 'reader', permissions: 1,
      effect: 'allow', scope: 'item', expires_at: null, created_at: null, ...partial,
    }
  }

  it('lists grants, adds one via the composable and revokes one', async () => {
    const created = grant({ id: 2, subject_type: 'App\\User', subject_id: 42, level: 'editor' })
    const listFn = vi.fn().mockResolvedValue(page([grant({ id: 1 })]))
    const grantFn = vi.fn().mockResolvedValue(created)
    const revokeFn = vi.fn().mockResolvedValue(undefined)
    const wrapper = mount(VaulterNodeGrantsPanel, {
      props: { nodeId: 9 },
      ...withClient({ grants: { list: listFn, grant: grantFn, revoke: revokeFn } as never }),
    })

    await nextTick()
    await Promise.resolve()
    await nextTick()
    expect(listFn).toHaveBeenCalled()
    expect(wrapper.findAll('.vaulter-node-grants-panel__item')).toHaveLength(1)

    // Fill the fallback subject inputs and submit.
    await wrapper.get('.vaulter-node-grants-panel__subject-type').setValue('App\\User')
    await wrapper.get('.vaulter-node-grants-panel__subject-id').setValue('42')
    await wrapper.get('.vaulter-node-grants-panel__level-select').setValue('editor')
    await wrapper.get('.vaulter-node-grants-panel__add').trigger('submit')
    await nextTick()
    await Promise.resolve()
    await nextTick()

    expect(grantFn).toHaveBeenCalledWith(9, expect.objectContaining({
      subjectType: 'App\\User', subjectId: '42', level: 'editor',
    }))
    expect(wrapper.emitted('granted')?.[0]?.[0]).toMatchObject({ id: 2 })

    // Two rows now; revoke the first.
    await wrapper.findAll('.vaulter-node-grants-panel__revoke')[0].trigger('click')
    await nextTick()
    await Promise.resolve()
    expect(revokeFn).toHaveBeenCalled()
    expect(wrapper.emitted('revoked')).toBeTruthy()
  })
})

// ─── VaulterNodeSharePanel ────────────────────────────────────────────────────

describe('VaulterNodeSharePanel', () => {
  function share(partial: Partial<NodeShare> & Pick<NodeShare, 'id'>): NodeShare {
    return {
      node_id: 9, token_prefix: 'pfx', permissions: 1, level: 'reader', scope: 'item',
      has_password: false, require_login: false, allow_discovery: false, expires_at: null,
      max_uses: null, use_count: 0, revoked_at: null, created_at: null, ...partial,
    }
  }

  it('shows the one-time token on create and never renders a stored raw token in the list', async () => {
    const created: NodeShareCreated = {
      ...share({ id: 5, token_prefix: 'abc' }),
      token: 'abc.SUPERSECRET',
      url: 'https://x/share/abc.SUPERSECRET/download',
    }
    const listFn = vi.fn().mockResolvedValue(page([share({ id: 1, token_prefix: 'old' })]))
    const createFn = vi.fn().mockResolvedValue(created)
    const publicDownloadUrl = vi.fn().mockReturnValue('https://x/share/x/download')
    const wrapper = mount(VaulterNodeSharePanel, {
      props: { nodeId: 9 },
      ...withClient({
        nodeShares: { list: listFn, create: createFn, publicDownloadUrl } as never,
      }),
    })

    await nextTick()
    await Promise.resolve()
    await nextTick()

    // The pre-existing share row must NOT carry a raw secret — only the prefix.
    const listHtml = wrapper.get('.vaulter-node-share-panel__list').html()
    expect(listHtml).toContain('old')
    expect(listHtml).not.toContain('SUPERSECRET')

    // Create → the one-time token surfaces in the created banner.
    await wrapper.get('.vaulter-node-share-panel__create').trigger('submit')
    await nextTick()
    await Promise.resolve()
    await nextTick()

    expect(createFn).toHaveBeenCalled()
    const banner = wrapper.get('.vaulter-node-share-panel__created')
    const tokenField = banner.get('.vaulter-node-share-panel__token').element as HTMLInputElement
    expect(tokenField.value).toBe('https://x/share/abc.SUPERSECRET/download')
    expect(wrapper.emitted('created')?.[0]?.[0]).toMatchObject({ token: 'abc.SUPERSECRET' })

    // The list row for the new share still shows only its prefix, never the token.
    const newListHtml = wrapper.get('.vaulter-node-share-panel__list').html()
    const prefixOccurrences = (newListHtml.match(/abc/g) ?? []).length
    expect(prefixOccurrences).toBeGreaterThan(0)
    expect(newListHtml).not.toContain('SUPERSECRET')
  })
})

// ─── VaulterDocumentNode ──────────────────────────────────────────────────────

describe('VaulterDocumentNode', () => {
  const tiptapHandler: HandlerDescriptor = {
    id: 'richtext',
    capabilities: { editable: true, previewable: true, downloadable: true, thumbnailable: false, collaborative: true },
    ui: { component: 'VaulterEditor', route: null, props: {} },
  }
  const fileHandler: HandlerDescriptor = {
    id: 'binary',
    capabilities: { editable: false, previewable: false, downloadable: true, thumbnailable: false, collaborative: false },
    ui: { component: 'BinaryDownload', route: null, props: {} },
  }

  function docClient(handler: HandlerDescriptor) {
    return {
      documentNodes: {
        handler: vi.fn().mockResolvedValue(handler),
        content: vi.fn().mockResolvedValue({
          id: 1, node_id: 20, version_no: 1, format: 'richtext', snapshot_label: null,
          is_autosave: false, keep_forever: false, status: 'committed', content_hash: null,
          yjs_state_size: null, created_by: null, created_at: null,
          content: { type: 'doc', content: [] },
        }),
        updateContent: vi.fn(),
      },
    } as unknown as Partial<VaulterClient>
  }

  // markRaw mirrors how real hosts pass imported SFCs (non-reactive) into the map.
  const StubEditor = markRaw(defineComponent({
    name: 'StubEditor',
    props: { nodeId: { type: [Number, String], required: true }, handler: { type: Object, required: true } },
    setup: () => () => h('div', { class: 'stub-editor' }, 'editor-mounted'),
  }))

  it('mounts the mapped editor for a TipTap document handler', async () => {
    const wrapper = mount(VaulterDocumentNode, {
      props: { nodeId: 20, componentMap: { VaulterEditor: StubEditor } },
      ...withClient(docClient(tiptapHandler)),
    })

    await nextTick()
    await Promise.resolve()
    await nextTick()
    await Promise.resolve()
    await nextTick()

    expect(wrapper.find('.stub-editor').exists()).toBe(true)
    expect(wrapper.emitted('ready')?.[0]?.[0]).toMatchObject({ id: 'richtext' })
  })

  it('renders the preview/download fallback for an unmapped handler', async () => {
    const wrapper = mount(VaulterDocumentNode, {
      props: { nodeId: 20 },
      ...withClient(docClient(fileHandler)),
    })

    await nextTick()
    await Promise.resolve()
    await nextTick()
    await Promise.resolve()
    await nextTick()

    expect(wrapper.find('.stub-editor').exists()).toBe(false)
    expect(wrapper.find('.vaulter-document-node__fallback').exists()).toBe(true)
    expect(wrapper.text()).toContain('BinaryDownload')
  })
})

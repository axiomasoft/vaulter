import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, markRaw, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import VaulterDrive from '../src/components/VaulterDrive.vue'
import VaulterDriveBreadcrumb from '../src/components/VaulterDriveBreadcrumb.vue'
import VaulterFolderPicker from '../src/components/VaulterFolderPicker.vue'
import VaulterNameDialog from '../src/components/VaulterNameDialog.vue'
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
  NodeCollaborationSession,
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
    const move = vi.fn().mockImplementation((nodeId, intoId) =>
      Promise.resolve(node({ id: nodeId, kind: 'file', name: 'moved', parent_id: intoId })))
    const createFolder = vi.fn().mockImplementation((_storageId, payload: { name: string, parentId?: number | null }) =>
      Promise.resolve(node({ id: 500, kind: 'folder', name: payload.name, parent_id: payload.parentId ?? null })))
    const rename = vi.fn().mockImplementation((nodeId, name: string) =>
      Promise.resolve(node({ id: nodeId, kind: 'file', name })))
    return {
      list,
      show,
      move,
      createFolder,
      rename,
      client: { nodes: { list, show, move, createFolder, rename } } as unknown as Partial<VaulterClient>,
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

  it('opens the folder picker for the move action and moves to the picked target (no window.prompt)', async () => {
    // A file that currently lives under folder 99 → the storage root is a valid target.
    const movable = node({ id: 2, kind: 'file', name: 'doc.txt', parent_id: 99, abilities: { move: true } })
    const { client, move } = driveClient([movable])
    // Guard: the old placeholder must never be reached.
    const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue(null)
    const wrapper = mount(VaulterDrive, { props: { storageId: 7 }, ...withClient(client) })

    await flushAll()

    // No picker until the move action fires.
    expect(wrapper.find('.vaulter-folder-picker').exists()).toBe(false)

    await wrapper.get('.vaulter-node-list__action--move').trigger('click')
    await flushAll()

    // The picker is now open for this node; window.prompt was NOT used.
    expect(wrapper.find('.vaulter-folder-picker').exists()).toBe(true)
    expect(promptSpy).not.toHaveBeenCalled()

    // "Move here" at the root (node lives under 99, so root is legal) → move(2, null).
    await wrapper.get('.vaulter-folder-picker__move-here').trigger('click')
    await flushAll()

    expect(move).toHaveBeenCalledWith(2, null)
    // The picker closes after a successful pick.
    expect(wrapper.find('.vaulter-folder-picker').exists()).toBe(false)

    promptSpy.mockRestore()
  })

  it('opens the name dialog for "new folder" and creates the folder with the typed name (no window.prompt)', async () => {
    const { client, createFolder } = driveClient([])
    // Guard: the old browser prompt must never be reached.
    const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue(null)
    const wrapper = mount(VaulterDrive, { props: { storageId: 7 }, ...withClient(client) })

    await flushAll()

    // No dialog until "New folder" is clicked.
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(false)

    await wrapper.get('.vaulter-drive__new-folder').trigger('click')
    await flushAll()

    // The dialog opened (empty, titled) and window.prompt was NOT used.
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(true)
    expect(promptSpy).not.toHaveBeenCalled()
    const field = wrapper.get('.vaulter-name-dialog__input').element as HTMLInputElement
    expect(field.value).toBe('')

    // Type a name and confirm → createFolder({ name }) (parentId resolved to root null).
    await wrapper.get('.vaulter-name-dialog__input').setValue('Invoices')
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await flushAll()

    expect(createFolder).toHaveBeenCalledWith(7, { name: 'Invoices', parentId: null })
    // The dialog closes after a successful submit.
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(false)

    promptSpy.mockRestore()
  })

  it('surfaces a failed mutation in the error region instead of swallowing it (dialog dismissed before the await)', async () => {
    const { client, createFolder } = driveClient([])
    // The backend rejects the create (e.g. a 422/409) AFTER the dialog has been dismissed.
    createFolder.mockRejectedValueOnce(new Error('A folder named "Invoices" already exists.'))
    const wrapper = mount(VaulterDrive, { props: { storageId: 7 }, ...withClient(client) })
    await flushAll()

    await wrapper.get('.vaulter-drive__new-folder').trigger('click')
    await flushAll()
    await wrapper.get('.vaulter-name-dialog__input').setValue('Invoices')
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await flushAll()

    // The mutation was attempted, the dialog closed — and the rejection is now visible,
    // not lost as an unhandled promise rejection.
    expect(createFolder).toHaveBeenCalled()
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(false)
    const error = wrapper.get('.vaulter-drive__error')
    expect(error.text()).toContain('already exists')
  })

  it('opens the name dialog prefilled for "rename" and renames with the new name (no window.prompt)', async () => {
    const target = node({ id: 3, kind: 'file', name: 'old.txt', abilities: { rename: true } })
    const { client, rename } = driveClient([target])
    const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue(null)
    const wrapper = mount(VaulterDrive, { props: { storageId: 7 }, ...withClient(client) })

    await flushAll()

    await wrapper.get('.vaulter-node-list__action--rename').trigger('click')
    await flushAll()

    // The dialog opened PREFILLED with the current name; window.prompt was NOT used.
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(true)
    expect(promptSpy).not.toHaveBeenCalled()
    const field = wrapper.get('.vaulter-name-dialog__input').element as HTMLInputElement
    expect(field.value).toBe('old.txt')

    // Overtype with a new name and confirm → rename(id, newName).
    await wrapper.get('.vaulter-name-dialog__input').setValue('new.txt')
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await flushAll()

    expect(rename).toHaveBeenCalledWith(3, 'new.txt')
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(false)

    promptSpy.mockRestore()
  })

  it('does NOT rename when the name is unchanged (skips the no-op the backend rejects)', async () => {
    const target = node({ id: 3, kind: 'file', name: 'same.txt', abilities: { rename: true } })
    const { client, rename } = driveClient([target])
    const wrapper = mount(VaulterDrive, { props: { storageId: 7 }, ...withClient(client) })

    await flushAll()

    await wrapper.get('.vaulter-node-list__action--rename').trigger('click')
    await flushAll()

    // Confirm without changing the prefilled name → no rename call, dialog still closes.
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await flushAll()

    expect(rename).not.toHaveBeenCalled()
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(false)
  })
})

// ─── VaulterNameDialog ────────────────────────────────────────────────────────

describe('VaulterNameDialog', () => {
  it('does not render when closed and seeds the field from initialValue on open', async () => {
    const closed = mount(VaulterNameDialog, { props: { open: false, initialValue: 'seed' } })
    expect(closed.find('.vaulter-name-dialog').exists()).toBe(false)

    const open = mount(VaulterNameDialog, { props: { open: true, initialValue: 'seed', title: 'Rename' } })
    await nextTick()
    const field = open.get('.vaulter-name-dialog__input').element as HTMLInputElement
    expect(field.value).toBe('seed')
    // The title surfaces in the header and as the dialog's aria-label.
    expect(open.get('.vaulter-name-dialog__title').text()).toBe('Rename')
    expect(open.get('.vaulter-name-dialog').attributes('aria-label')).toBe('Rename')
  })

  it('re-seeds from initialValue each time it re-opens (no stale value leaks through)', async () => {
    const wrapper = mount(VaulterNameDialog, { props: { open: true, initialValue: 'first' } })
    await nextTick()
    // User edits, then the dialog closes without submitting.
    await wrapper.get('.vaulter-name-dialog__input').setValue('edited')
    await wrapper.setProps({ open: false })
    await nextTick()
    // Re-open with a fresh seed → the edited value is gone.
    await wrapper.setProps({ open: true, initialValue: 'second' })
    await nextTick()
    const field = wrapper.get('.vaulter-name-dialog__input').element as HTMLInputElement
    expect(field.value).toBe('second')
  })

  it('disables confirm while the trimmed value is empty and enables it once non-blank', async () => {
    const wrapper = mount(VaulterNameDialog, { props: { open: true, initialValue: '' } })
    await nextTick()
    const submit = () => wrapper.get('.vaulter-name-dialog__submit').element as HTMLButtonElement

    // Empty → disabled.
    expect(submit().disabled).toBe(true)
    // Whitespace-only still counts as empty (trimmed) → disabled.
    await wrapper.get('.vaulter-name-dialog__input').setValue('   ')
    expect(submit().disabled).toBe(true)
    // Real text → enabled.
    await wrapper.get('.vaulter-name-dialog__input').setValue('hello')
    expect(submit().disabled).toBe(false)
  })

  it('emits submit with the TRIMMED value on confirm, and never submits a blank', async () => {
    const wrapper = mount(VaulterNameDialog, { props: { open: true, initialValue: '' } })
    await nextTick()

    // A blank confirm emits nothing.
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    expect(wrapper.emitted('submit')).toBeUndefined()

    // Padded text → submit carries it trimmed.
    await wrapper.get('.vaulter-name-dialog__input').setValue('  Reports  ')
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    expect(wrapper.emitted('submit')?.[0]).toEqual(['Reports'])
  })

  it('blocks a name with a path separator and a too-long name (mirrors DriveNameRules), showing an inline error', async () => {
    const wrapper = mount(VaulterNameDialog, { props: { open: true, initialValue: '' } })
    await nextTick()
    const submit = () => wrapper.get('.vaulter-name-dialog__submit').element as HTMLButtonElement
    const error = () => wrapper.find('.vaulter-name-dialog__error')

    // A forward slash makes it a path, not a segment → disabled + error + no submit.
    await wrapper.get('.vaulter-name-dialog__input').setValue('a/b')
    expect(submit().disabled).toBe(true)
    expect(error().exists()).toBe(true)
    expect(error().text()).toContain('path separators')
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await wrapper.get('.vaulter-name-dialog__input').trigger('keydown.enter')
    expect(wrapper.emitted('submit')).toBeUndefined()

    // A backslash is rejected too.
    await wrapper.get('.vaulter-name-dialog__input').setValue('a\\b')
    expect(submit().disabled).toBe(true)

    // Over 255 chars → disabled with the length message.
    await wrapper.get('.vaulter-name-dialog__input').setValue('x'.repeat(256))
    expect(submit().disabled).toBe(true)
    expect(error().text()).toContain('255')

    // Back to a legal segment → enabled, error gone, submit fires.
    await wrapper.get('.vaulter-name-dialog__input').setValue('Reports')
    expect(submit().disabled).toBe(false)
    expect(wrapper.find('.vaulter-name-dialog__error').exists()).toBe(false)
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    expect(wrapper.emitted('submit')?.[0]).toEqual(['Reports'])
  })

  it('submits (trimmed) on Enter in the field', async () => {
    const wrapper = mount(VaulterNameDialog, { props: { open: true, initialValue: '' } })
    await nextTick()

    await wrapper.get('.vaulter-name-dialog__input').setValue('  Typed  ')
    await wrapper.get('.vaulter-name-dialog__input').trigger('keydown.enter')
    expect(wrapper.emitted('submit')?.[0]).toEqual(['Typed'])
  })

  it('emits close on Cancel, on backdrop click and on Esc (no submit)', async () => {
    const onCancel = mount(VaulterNameDialog, { props: { open: true, initialValue: 'x' } })
    await nextTick()
    await onCancel.get('.vaulter-name-dialog__cancel').trigger('click')
    expect(onCancel.emitted('close')).toBeTruthy()
    expect(onCancel.emitted('submit')).toBeUndefined()

    const onBackdrop = mount(VaulterNameDialog, { props: { open: true, initialValue: 'x' } })
    await nextTick()
    await onBackdrop.get('.vaulter-name-dialog__backdrop').trigger('click')
    expect(onBackdrop.emitted('close')).toBeTruthy()

    const onEsc = mount(VaulterNameDialog, { props: { open: true, initialValue: 'x' } })
    await nextTick()
    await onEsc.get('.vaulter-name-dialog').trigger('keydown', { key: 'Escape' })
    expect(onEsc.emitted('close')).toBeTruthy()
    expect(onEsc.emitted('submit')).toBeUndefined()
  })
})

// ─── VaulterFolderPicker ──────────────────────────────────────────────────────

describe('VaulterFolderPicker', () => {
  /** A client whose `nodes.list` returns folders/files keyed by parent, plus show. */
  function pickerClient(childByParent: Record<string, Node[]>) {
    const list = vi.fn().mockImplementation((_storageId, query?: { parentId?: number | string | null }) => {
      const parent = query?.parentId ?? null
      return Promise.resolve(page(childByParent[parent === null ? 'root' : String(parent)] ?? []))
    })
    const show = vi.fn().mockImplementation((nodeId) =>
      Promise.resolve(node({ id: nodeId, kind: 'folder', name: `Folder ${nodeId}`, breadcrumb: [
        node({ id: nodeId, kind: 'folder', name: `Folder ${nodeId}` }),
      ] })))
    return { list, show, client: { nodes: { list, show } } as unknown as Partial<VaulterClient> }
  }

  it('lists only folders at the current location (files are filtered out)', async () => {
    const moved = node({ id: 1, kind: 'file', name: 'doc.txt', parent_id: 50 })
    const { client } = pickerClient({
      root: [
        node({ id: 10, kind: 'folder', name: 'Reports' }),
        node({ id: 11, kind: 'file', name: 'note.txt' }),
        node({ id: 12, kind: 'folder', name: 'Archive' }),
      ],
    })
    const wrapper = mount(VaulterFolderPicker, {
      props: { storageId: 7, node: moved, open: true },
      ...withClient(client),
    })

    await flushAll()

    const names = wrapper.findAll('.vaulter-folder-picker__folder-name').map((n) => n.text())
    expect(names).toEqual(['Reports', 'Archive'])
    // The file is not offered as a destination.
    expect(names).not.toContain('note.txt')
  })

  it('excludes the node being moved from the destination list (its subtree is unreachable)', async () => {
    // Moving folder 10 itself: it must not appear as a navigable destination.
    const moved = node({ id: 10, kind: 'folder', name: 'Reports', parent_id: null })
    const { client } = pickerClient({
      root: [
        node({ id: 10, kind: 'folder', name: 'Reports' }),
        node({ id: 12, kind: 'folder', name: 'Archive' }),
      ],
    })
    const wrapper = mount(VaulterFolderPicker, {
      props: { storageId: 7, node: moved, open: true },
      ...withClient(client),
    })

    await flushAll()

    const names = wrapper.findAll('.vaulter-folder-picker__folder-name').map((n) => n.text())
    expect(names).toEqual(['Archive'])
    // The moved folder is hidden → the user can never descend into its own subtree.
    expect(names).not.toContain('Reports')
  })

  it('keeps the moved folder hidden even when it has descendants (subtree is structurally unreachable)', async () => {
    // Folder 10 (moved) sits at root AND has a child folder 20. Because 10 is hidden
    // from the listing, the user can never click into it → 20 is unreachable, so an
    // illegal move into the node's own subtree can never be offered.
    const moved = node({ id: 10, kind: 'folder', name: 'Reports', parent_id: null })
    const { client } = pickerClient({
      root: [
        node({ id: 10, kind: 'folder', name: 'Reports' }),
        node({ id: 12, kind: 'folder', name: 'Archive' }),
      ],
      '10': [node({ id: 20, kind: 'folder', name: 'Reports 2026' })],
    })
    const wrapper = mount(VaulterFolderPicker, {
      props: { storageId: 7, node: moved, open: true },
      ...withClient(client),
    })

    await flushAll()

    const names = wrapper.findAll('.vaulter-folder-picker__folder-name').map((n) => n.text())
    // Only Archive is navigable: the moved folder (Reports) is gone, so its child
    // (Reports 2026), reachable only THROUGH it, can never surface as a destination.
    expect(names).toEqual(['Archive'])
    expect(names).not.toContain('Reports')
    expect(names).not.toContain('Reports 2026')
  })

  it('ignores a stale folder listing when navigation has moved on (no wrong-destination move)', async () => {
    // Rapid navigation A→B: B is the current location. If A's (slower) response wins the
    // listing, "Move here" would target B while showing A — a silent wrong move. The
    // loadSeq guard must discard the stale A response.
    const moved = node({ id: 1, kind: 'file', name: 'doc.txt', parent_id: 50 })
    const deferred: Record<string, (nodes: Node[]) => void> = {}
    const list = vi.fn().mockImplementation((_storageId, query?: { parentId?: number | string | null }) => {
      const parent = query?.parentId ?? null
      if (parent === null) {
        return Promise.resolve(page([
          node({ id: 10, kind: 'folder', name: 'A' }),
          node({ id: 20, kind: 'folder', name: 'B' }),
        ]))
      }
      return new Promise((resolve) => {
        deferred[String(parent)] = (nodes) => resolve(page(nodes))
      })
    })
    const show = vi.fn().mockResolvedValue(node({ id: 1, kind: 'folder', name: 'x', breadcrumb: [] }))
    const client = { nodes: { list, show } } as unknown as Partial<VaulterClient>
    const wrapper = mount(VaulterFolderPicker, {
      props: { storageId: 7, node: moved, open: true },
      ...withClient(client),
    })
    await flushAll()

    // Navigate into A then B (both loads pending on the deferreds).
    void (wrapper.vm as unknown as { goTo: (id: number | string | null) => Promise<void> }).goTo(10)
    void (wrapper.vm as unknown as { goTo: (id: number | string | null) => Promise<void> }).goTo(20)

    // Resolve the CURRENT location (B) first, then the STALE one (A): A must not win.
    deferred['20']([node({ id: 201, kind: 'folder', name: 'in-B' })])
    deferred['10']([node({ id: 101, kind: 'folder', name: 'in-A' })])
    await flushAll()

    const names = wrapper.findAll('.vaulter-folder-picker__folder-name').map((n) => n.text())
    expect(names).toEqual(['in-B'])
    expect(names).not.toContain('in-A')
  })

  it('disables "Move here" at the node\'s CURRENT parent (a no-op move)', async () => {
    // The node already lives at the root → "Move here" at the root is a no-op.
    const moved = node({ id: 1, kind: 'file', name: 'doc.txt', parent_id: null })
    const { client } = pickerClient({ root: [node({ id: 12, kind: 'folder', name: 'Archive' })] })
    const wrapper = mount(VaulterFolderPicker, {
      props: { storageId: 7, node: moved, open: true },
      ...withClient(client),
    })

    await flushAll()

    const moveHere = wrapper.get('.vaulter-folder-picker__move-here').element as HTMLButtonElement
    expect(moveHere.disabled).toBe(true)
  })

  it('emits picked(folderId) after navigating into a folder, and picked(null) for the root', async () => {
    // Node lives under 99 → both the root and folder 12 are valid destinations.
    const moved = node({ id: 1, kind: 'file', name: 'doc.txt', parent_id: 99 })
    const { client } = pickerClient({
      root: [node({ id: 12, kind: 'folder', name: 'Archive' })],
      '12': [],
    })
    const wrapper = mount(VaulterFolderPicker, {
      props: { storageId: 7, node: moved, open: true },
      ...withClient(client),
    })

    await flushAll()

    // Root is a legal target here → "Move here" emits picked(null).
    await wrapper.get('.vaulter-folder-picker__move-here').trigger('click')
    expect(wrapper.emitted('picked')?.[0]).toEqual([null])

    // Navigate INTO folder 12, then "Move here" → picked(12).
    await wrapper.get('.vaulter-folder-picker__item').trigger('click')
    await flushAll()
    await wrapper.get('.vaulter-folder-picker__move-here').trigger('click')
    expect(wrapper.emitted('picked')?.[1]).toEqual([12])
  })

  it('emits close on the Cancel button and does not render when closed', async () => {
    const moved = node({ id: 1, kind: 'file', name: 'doc.txt', parent_id: 99 })
    const { client } = pickerClient({ root: [] })

    const closed = mount(VaulterFolderPicker, {
      props: { storageId: 7, node: moved, open: false },
      ...withClient(client),
    })
    expect(closed.find('.vaulter-folder-picker').exists()).toBe(false)

    const open = mount(VaulterFolderPicker, {
      props: { storageId: 7, node: moved, open: true },
      ...withClient(client),
    })
    await flushAll()
    await open.get('.vaulter-folder-picker__cancel').trigger('click')
    expect(open.emitted('close')).toBeTruthy()
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

  /**
   * A fake client. `collabSession` (when set) makes the node collab/token endpoint
   * resolve a Hocuspocus session; absent → the token fetch rejects and the component
   * falls back to the M4a autosave path.
   */
  function docClient(handler: HandlerDescriptor, collabSession?: NodeCollaborationSession) {
    const updateContent = vi.fn().mockResolvedValue({ id: 2, node_id: 20, version_no: 2 })
    return {
      client: {
        documentNodes: {
          handler: vi.fn().mockResolvedValue(handler),
          content: vi.fn().mockResolvedValue({
            id: 1, node_id: 20, version_no: 1, format: 'richtext', snapshot_label: null,
            is_autosave: false, keep_forever: false, status: 'committed', content_hash: null,
            yjs_state_size: null, created_by: null, created_at: null,
            content: { type: 'doc', content: [] },
          }),
          updateContent,
        },
        nodeCollaboration: {
          token: collabSession
            ? vi.fn().mockResolvedValue(collabSession)
            : vi.fn().mockRejectedValue(new Error('collab unavailable')),
        },
      } as unknown as Partial<VaulterClient>,
      updateContent,
    }
  }

  const hocuspocusSession = (readOnly: boolean): NodeCollaborationSession => ({
    driver: 'hocuspocus', room: 'vaulter.documents.20', node_id: 20,
    read_only: readOnly, token: 'jwt', url: 'ws://hub:1234', connection: null,
  })

  // A stub Hocuspocus provider — no real socket. Lets the session build in a test.
  function stubProviders() {
    return {
      createHocuspocusProvider: vi.fn().mockReturnValue({
        awareness: null,
        isConnected: () => true,
        onConnectionChange: () => () => {},
        destroy: vi.fn(),
      }),
    }
  }

  // markRaw mirrors how real hosts pass imported SFCs (non-reactive) into the map.
  // It renders its collaborative + readonly props so the test can assert the mode.
  const StubEditor = markRaw(defineComponent({
    name: 'StubEditor',
    props: {
      nodeId: { type: [Number, String], required: true },
      handler: { type: Object, required: true },
      readonly: { type: Boolean, default: false },
      collaborative: { type: Boolean, default: false },
      ydoc: { type: Object, default: null },
    },
    setup: (props) => () => h('div', {
      class: 'stub-editor',
      'data-collaborative': props.collaborative ? 'true' : 'false',
      'data-readonly': props.readonly ? 'true' : 'false',
      'data-has-ydoc': props.ydoc ? 'true' : 'false',
    }, 'editor-mounted'),
  }))

  it('mounts the mapped editor for a TipTap document handler', async () => {
    const { client } = docClient(tiptapHandler, hocuspocusSession(false))
    const wrapper = mount(VaulterDocumentNode, {
      props: {
        nodeId: 20,
        componentMap: { VaulterEditor: StubEditor },
        collaborationProviders: stubProviders(),
      },
      ...withClient(client),
    })

    await flushAll()

    expect(wrapper.find('.stub-editor').exists()).toBe(true)
    expect(wrapper.emitted('ready')?.[0]?.[0]).toMatchObject({ id: 'richtext' })
  })

  it('mounts the editor in COLLABORATIVE mode for a collaborative handler', async () => {
    const { client, updateContent } = docClient(tiptapHandler, hocuspocusSession(false))
    const wrapper = mount(VaulterDocumentNode, {
      props: {
        nodeId: 20,
        componentMap: { VaulterEditor: StubEditor },
        collaborationProviders: stubProviders(),
      },
      ...withClient(client),
    })

    await flushAll()

    const editor = wrapper.find('.stub-editor')
    expect(editor.attributes('data-collaborative')).toBe('true')
    // The shared Y.Doc is handed to the editor and writes go through Yjs (no autosave).
    expect(editor.attributes('data-has-ydoc')).toBe('true')
    expect(wrapper.emitted('collaborating')?.[0]?.[0]).toBe(false)
    expect(updateContent).not.toHaveBeenCalled()
  })

  it('respects a read-only collaboration session', async () => {
    const { client } = docClient(tiptapHandler, hocuspocusSession(true))
    const wrapper = mount(VaulterDocumentNode, {
      props: {
        nodeId: 20,
        componentMap: { VaulterEditor: StubEditor },
        collaborationProviders: stubProviders(),
      },
      ...withClient(client),
    })

    await flushAll()

    const editor = wrapper.find('.stub-editor')
    expect(editor.attributes('data-collaborative')).toBe('true')
    expect(editor.attributes('data-readonly')).toBe('true')
    expect(wrapper.emitted('collaborating')?.[0]?.[0]).toBe(true)
  })

  it('falls back to the autosave (non-collaborative) path when collab is unavailable', async () => {
    // No collab session → the token fetch rejects → autosave path.
    const { client } = docClient(tiptapHandler)
    const wrapper = mount(VaulterDocumentNode, {
      props: { nodeId: 20, componentMap: { VaulterEditor: StubEditor } },
      ...withClient(client),
    })

    await flushAll()

    const editor = wrapper.find('.stub-editor')
    expect(editor.exists()).toBe(true)
    expect(editor.attributes('data-collaborative')).toBe('false')
    expect(wrapper.emitted('collaborating')).toBeUndefined()
  })

  it('uses the autosave path for a NON-collaborative handler (never opens a session)', async () => {
    const { client } = docClient({
      ...tiptapHandler,
      capabilities: { ...tiptapHandler.capabilities, collaborative: false },
    }, hocuspocusSession(false))

    const wrapper = mount(VaulterDocumentNode, {
      props: { nodeId: 20, componentMap: { VaulterEditor: StubEditor } },
      ...withClient(client),
    })

    await flushAll()

    const editor = wrapper.find('.stub-editor')
    expect(editor.attributes('data-collaborative')).toBe('false')
    // token() is never called for a non-collaborative handler.
    expect((client.nodeCollaboration!.token as ReturnType<typeof vi.fn>)).not.toHaveBeenCalled()
  })

  it('renders the preview/download fallback for an unmapped handler', async () => {
    const { client } = docClient(fileHandler)
    const wrapper = mount(VaulterDocumentNode, {
      props: { nodeId: 20 },
      ...withClient(client),
    })

    await flushAll()

    expect(wrapper.find('.stub-editor').exists()).toBe(false)
    expect(wrapper.find('.vaulter-document-node__fallback').exists()).toBe(true)
    expect(wrapper.text()).toContain('BinaryDownload')
  })
})

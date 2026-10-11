import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import VaulterBreadcrumb from '../src/components/VaulterBreadcrumb.vue'
import VaulterDrive from '../src/components/VaulterDrive.vue'
import VaulterFolderPicker from '../src/components/VaulterFolderPicker.vue'
import VaulterNameDialog from '../src/components/VaulterNameDialog.vue'
import VaulterNodeList from '../src/components/VaulterNodeList.vue'
import VaulterUploader from '../src/components/VaulterUploader.vue'
import { humanByteSize, nodeIconKind } from '../src/components/drive/nodeDisplay'
import { VaulterApiError } from '../src/api/errors'
import type { Node } from '../src/api/types'
import { makeFile, makeNode, makePresentation, pageOf } from './support/nodes'
import { withClient } from './support/mount'

// ─── Fakes ──────────────────────────────────────────────────────────────────

const page = pageOf

/** A folder/file/document node (every ability on) with the given fields. */
function node(partial: Partial<Omit<Node, 'abilities'>> & { abilities?: Partial<Node['abilities']> } & Pick<Node, 'id'>): Node {
  return makeNode(partial)
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
    expect(nodeIconKind(node({ id: '1', kind: 'folder' }))).toBe('folder')
    expect(nodeIconKind(node({ id: '2', kind: 'document' }))).toBe('document')
    expect(nodeIconKind(node({ id: '3', kind: 'file', mime: 'image/png' }))).toBe('image')
    expect(nodeIconKind(node({ id: '4', kind: 'file', mime: 'application/pdf' }))).toBe('pdf')
    expect(nodeIconKind(node({ id: '5', kind: 'file', mime: 'application/zip' }))).toBe('archive')
    expect(nodeIconKind(node({ id: '6', kind: 'file', mime: null, extension: 'mp4' }))).toBe('video')
    expect(nodeIconKind(node({ id: '7', kind: 'file', mime: 'text/plain' }))).toBe('file')
  })

  it('formats byte sizes humanely (and blanks an unsized node)', () => {
    expect(humanByteSize(null)).toBe('')
    expect(humanByteSize(0)).toBe('0 B')
    expect(humanByteSize(512)).toBe('512 B')
    expect(humanByteSize(1024)).toBe('1.0 KB')
    expect(humanByteSize(1.5 * 1024 * 1024)).toBe('1.5 MB')
  })
})

// ─── VaulterBreadcrumb ────────────────────────────────────────────────────────

describe('VaulterBreadcrumb', () => {
  it('renders crumbs and emits navigate(null) for the root and the node id for a crumb', async () => {
    const wrapper = mount(VaulterBreadcrumb, { props: { path: [{ id: '10', name: 'Reports' }] } })

    expect(wrapper.classes()).toContain('vaulter-breadcrumb')

    await wrapper.get('.vaulter-breadcrumb__root').trigger('click')
    expect(wrapper.emitted('navigate')?.[0]).toEqual([null])

    const crumbs = wrapper.findAll('.vaulter-breadcrumb__crumb')
    // root + one ancestor
    await crumbs[crumbs.length - 1].trigger('click')
    expect(wrapper.emitted('navigate')?.[1]).toEqual(['10'])
    expect(crumbs[crumbs.length - 1].attributes('aria-current')).toBe('page')
  })
})

// ─── VaulterNodeList ──────────────────────────────────────────────────────────

describe('VaulterNodeList', () => {
  it('hides actions the actor cannot do (per node abilities)', () => {
    const nodes = [node({ id: '1', kind: 'file', name: 'a', abilities: { edit: true, trash: false, download: true } })]
    const wrapper = mount(VaulterNodeList, { props: { nodes, actions: ['rename', 'trash', 'download'] } })

    const labels = wrapper.findAll('.vaulter-node-list__action').map((b) => b.text())
    // `rename` is gated by the `edit` ability — the 12 abilities have no separate rename.
    expect(labels).toContain('rename')
    expect(labels).toContain('download')
    // trash ability is false → never offered.
    expect(labels).not.toContain('trash')
  })

  it('gates purge on the delete ability', () => {
    const wrapper = mount(VaulterNodeList, {
      props: {
        nodes: [node({ id: '1', kind: 'file', abilities: { delete: false } }), node({ id: '2', kind: 'file', abilities: { delete: true } })],
        actions: ['purge'],
      },
    })

    const rows = wrapper.findAll('.vaulter-node-list__item')
    expect(rows[0].find('.vaulter-node-list__action--purge').exists()).toBe(false)
    expect(rows[1].find('.vaulter-node-list__action--purge').exists()).toBe(true)
  })

  it('emits open on dblclick and select on click', async () => {
    const wrapper = mount(VaulterNodeList, { props: { nodes: [node({ id: '9', kind: 'file', name: 'doc' })] } })

    const row = wrapper.get('.vaulter-node-list__item')
    await row.trigger('click')
    expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({ id: '9' })

    await row.trigger('dblclick')
    expect(wrapper.emitted('open')?.[0]?.[0]).toMatchObject({ id: '9' })
  })

  it('emits action with the node when a row action is clicked', async () => {
    const wrapper = mount(VaulterNodeList, {
      props: { nodes: [node({ id: '4', kind: 'file', name: 'x' })], actions: ['rename'] },
    })

    await wrapper.get('.vaulter-node-list__action--rename').trigger('click')
    expect(wrapper.emitted('action')?.[0]).toEqual(['rename', expect.objectContaining({ id: '4' })])
  })

  it('renders the empty state when there are no nodes', () => {
    const wrapper = mount(VaulterNodeList, { props: { nodes: [] } })
    expect(wrapper.find('.vaulter-node-list__empty').exists()).toBe(true)
  })

  it('marks starred nodes and shows the content modification time, not the metadata one', () => {
    const wrapper = mount(VaulterNodeList, {
      props: {
        nodes: [node({
          id: '1', kind: 'file', viewer: { starred: true },
          content_modified_at: '2026-02-01T00:00:00Z', updated_at: '2026-03-01T00:00:00Z',
        })],
      },
    })

    const row = wrapper.get('.vaulter-node-list__item')
    expect(row.attributes('data-starred')).toBe('true')
    expect(row.get('.vaulter-node-list__modified').text()).toBe('2026-02-01T00:00:00Z')
  })

  it('falls back to updated_at for a node whose content was never modified', () => {
    const wrapper = mount(VaulterNodeList, {
      props: { nodes: [node({ id: '1', kind: 'folder', content_modified_at: null, updated_at: '2026-03-01T00:00:00Z' })] },
    })

    expect(wrapper.get('.vaulter-node-list__modified').text()).toBe('2026-03-01T00:00:00Z')
  })

  it('exposes the document type of a document row', () => {
    const wrapper = mount(VaulterNodeList, {
      props: {
        nodes: [node({
          id: '1', kind: 'document',
          presentation: makePresentation({ key: 'vaulter/tiptap', documentType: 'richtext', handler: null }),
        })],
      },
    })

    expect(wrapper.get('.vaulter-node-list__item').attributes('data-document-type')).toBe('richtext')
  })
})

// ─── VaulterDrive ─────────────────────────────────────────────────────────────

describe('VaulterDrive', () => {
  function driveClient(initial: Node[], childByParent: Record<string, Node[]> = {}) {
    const children = vi.fn().mockImplementation((_driveId, query?: { parent?: string | null }) => {
      const parent = query?.parent ?? null
      return Promise.resolve(page(parent === null ? initial : childByParent[parent] ?? []))
    })
    const getWithIncludes = vi.fn().mockResolvedValue({ ...node({ id: '1', kind: 'folder', name: 'Folder' }), breadcrumb: [{ id: '1', name: 'Folder' }] })
    const move = vi.fn().mockImplementation((nodeId, destinationId) =>
      Promise.resolve(node({ id: nodeId, kind: 'file', name: 'moved', parent_id: destinationId })))
    const create = vi.fn().mockImplementation((_driveId, input: { name: string, parentId?: string | null }) =>
      Promise.resolve(node({ id: '500', kind: 'folder', name: input.name, parent_id: input.parentId ?? null })))
    const update = vi.fn().mockImplementation((nodeId, patch: { name: string }) =>
      Promise.resolve(node({ id: nodeId, kind: 'file', name: patch.name })))
    const trash = vi.fn().mockImplementation((nodeId) => Promise.resolve(node({ id: nodeId, kind: 'file', trashed_at: 'now' })))
    return {
      children, getWithIncludes, move, create, update, trash,
      client: {
        drives: { get: vi.fn().mockResolvedValue({ root_node_id: 'root' }) },
        nodes: { children, get: vi.fn().mockResolvedValue(node({ id: 'root', kind: 'folder' })), getWithIncludes, move, update, trash },
        folders: { create }, files: { downloadUrl: (id: string) => `/dl/${id}` },
      },
    }
  }

  it('renders a node list from the client and emits open-node for a file', async () => {
    const nodes = [
      node({ id: '1', kind: 'folder', name: 'Folder' }),
      node({ id: '2', kind: 'file', name: 'file.txt' }),
    ]
    const { client, children } = driveClient(nodes)
    const wrapper = mount(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })

    expect(wrapper.classes()).toContain('vaulter-drive')
    await nextTick()
    await nextTick()
    expect(children).toHaveBeenCalledWith('7', { parent: null })

    const rows = wrapper.findAll('.vaulter-node-list__item')
    expect(rows).toHaveLength(2)

    // Double-click the FILE row → open-node emitted (folder would navigate instead).
    await rows[1].trigger('dblclick')
    expect(wrapper.emitted('open-node')?.[0]?.[0]).toMatchObject({ id: '2', kind: 'file' })
  })

  it('navigates into a folder on open (folder is handled internally) and loads its breadcrumb', async () => {
    const root = [node({ id: '1', kind: 'folder', name: 'Folder' })]
    const inner = { '1': [node({ id: '5', kind: 'file', name: 'inner.txt' })] }
    const { client, children, getWithIncludes } = driveClient(root, inner)
    const wrapper = mount(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })

    await nextTick()
    await nextTick()

    await wrapper.findAll('.vaulter-node-list__item')[0].trigger('dblclick')
    await flushAll()

    // It re-listed with the folder as parent, and did NOT emit open-node.
    expect(children).toHaveBeenLastCalledWith('7', { parent: '1' })
    expect(getWithIncludes).toHaveBeenCalledWith('1', ['breadcrumb'])
    expect(wrapper.emitted('open-node')).toBeUndefined()
    expect(wrapper.emitted('navigate')?.[0]).toEqual(['1'])
    expect(wrapper.get('.vaulter-breadcrumb').text()).toContain('Folder')
  })

  it('opens the folder picker for the move action and moves with the node etag (no window.prompt)', async () => {
    // A file that currently lives under folder 99 → the drive root is a valid target.
    const movable = node({ id: '2', kind: 'file', name: 'doc.txt', parent_id: '99', etag: '"2.3.abcd"' })
    const { client, move } = driveClient([movable])
    // happy-dom (>=16) no longer implements window.prompt at all, so vi.spyOn has nothing to wrap unless a stub is defined first.
    window.prompt = window.prompt ?? ((): string | null => null)
    const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue(null)
    const wrapper = mount(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })

    await flushAll()

    // No picker until the move action fires.
    expect(wrapper.find('.vaulter-folder-picker').exists()).toBe(false)

    await wrapper.get('.vaulter-node-list__action--move').trigger('click')
    await flushAll()

    // The picker is now open for this node; window.prompt was NOT used.
    expect(wrapper.find('.vaulter-folder-picker').exists()).toBe(true)
    expect(promptSpy).not.toHaveBeenCalled()

    // "Move here" at the root (node lives under 99, so root is legal) → move('2', null, { etag }).
    await wrapper.get('.vaulter-folder-picker__move-here').trigger('click')
    await flushAll()

    expect(move).toHaveBeenCalledWith('2', null, { etag: '"2.3.abcd"' })
    // The picker closes after a successful pick.
    expect(wrapper.find('.vaulter-folder-picker').exists()).toBe(false)

    promptSpy.mockRestore()
  })

  it('opens the name dialog for "new folder" and creates the folder with the typed name', async () => {
    const { client, create } = driveClient([])
    const wrapper = mount(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })

    await flushAll()

    // No dialog until "New folder" is clicked.
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(false)

    await wrapper.get('.vaulter-drive__new-folder').trigger('click')
    await flushAll()

    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(true)
    const field = wrapper.get('.vaulter-name-dialog__input').element as HTMLInputElement
    expect(field.value).toBe('')

    // Type a name and confirm → folders.create(driveId, { name, parentId: null }).
    await wrapper.get('.vaulter-name-dialog__input').setValue('Invoices')
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await flushAll()

    expect(create).toHaveBeenCalledWith('7', { name: 'Invoices', parentId: null })
    // The dialog closes after a successful submit.
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(false)
  })

  it('surfaces a failed mutation in the error region instead of swallowing it (dialog dismissed before the await)', async () => {
    const { client, create } = driveClient([])
    // The backend rejects the create (e.g. a 422/409) AFTER the dialog has been dismissed.
    create.mockRejectedValueOnce(new Error('A folder named "Invoices" already exists.'))
    const wrapper = mount(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })
    await flushAll()

    await wrapper.get('.vaulter-drive__new-folder').trigger('click')
    await flushAll()
    await wrapper.get('.vaulter-name-dialog__input').setValue('Invoices')
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await flushAll()

    expect(create).toHaveBeenCalled()
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(false)
    expect(wrapper.get('.vaulter-drive__error').text()).toContain('already exists')
  })

  it('opens the name dialog prefilled for "rename" and renames with the new name and the node etag', async () => {
    const target = node({ id: '3', kind: 'file', name: 'old.txt', etag: '"3.1.beef"' })
    const { client, update } = driveClient([target])
    const wrapper = mount(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })

    await flushAll()

    await wrapper.get('.vaulter-node-list__action--rename').trigger('click')
    await flushAll()

    // The dialog opened PREFILLED with the current name.
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(true)
    const field = wrapper.get('.vaulter-name-dialog__input').element as HTMLInputElement
    expect(field.value).toBe('old.txt')

    // Overtype with a new name and confirm → update(id, { name }, { etag }).
    await wrapper.get('.vaulter-name-dialog__input').setValue('new.txt')
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await flushAll()

    expect(update).toHaveBeenCalledWith('3', { name: 'new.txt' }, { etag: '"3.1.beef"' })
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(false)
  })

  it('does NOT rename when the name is unchanged (skips the no-op the backend rejects)', async () => {
    const target = node({ id: '3', kind: 'file', name: 'same.txt' })
    const { client, update } = driveClient([target])
    const wrapper = mount(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })

    await flushAll()

    await wrapper.get('.vaulter-node-list__action--rename').trigger('click')
    await flushAll()

    // Confirm without changing the prefilled name → no rename call, dialog still closes.
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await flushAll()

    expect(update).not.toHaveBeenCalled()
    expect(wrapper.find('.vaulter-name-dialog').exists()).toBe(false)
  })

  it('trashes after the confirm gate, with the node etag', async () => {
    const target = node({ id: '3', kind: 'file', name: 'x.txt', etag: '"3.2.cafe"' })
    const { client, trash } = driveClient([target])
    const confirm = vi.fn().mockResolvedValue(true)
    const wrapper = mount(VaulterDrive, { props: { driveId: '7', confirm }, ...withClient(client) })
    await flushAll()

    await wrapper.get('.vaulter-node-list__action--trash').trigger('click')
    await flushAll()

    expect(confirm).toHaveBeenCalledWith('Move "x.txt" to trash?')
    expect(trash).toHaveBeenCalledWith('3', { etag: '"3.2.cafe"' })
    expect(wrapper.findAll('.vaulter-node-list__item')).toHaveLength(0)
  })

  it('tells the user a stale etag (412) reloaded the item, and shows its current state', async () => {
    const stale = node({ id: '3', kind: 'file', name: 'old.txt', etag: '"3.1.old"' })
    const fresh = { ...stale, name: 'renamed-elsewhere.txt', etag: '"3.2.new"' }
    const { client, update } = driveClient([stale])
    update.mockRejectedValueOnce(new VaulterApiError({ status: 412, code: 'node_sequence_mismatch' }))
    ;(client.nodes as Record<string, unknown>).get = vi.fn().mockResolvedValue(fresh)
    const wrapper = mount(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })
    await flushAll()

    await wrapper.get('.vaulter-node-list__action--rename').trigger('click')
    await flushAll()
    await wrapper.get('.vaulter-name-dialog__input').setValue('mine.txt')
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await flushAll()

    expect(wrapper.get('.vaulter-drive__error').text()).toContain('changed by someone else')
    expect(wrapper.get('.vaulter-node-list__name').text()).toBe('renamed-elsewhere.txt')
  })

  it('offers download through the files route', async () => {
    const target = node({ id: '3', kind: 'file', name: 'x.txt' })
    const { client } = driveClient([target])
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    const wrapper = mount(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })
    await flushAll()

    await wrapper.get('.vaulter-node-list__action--download').trigger('click')

    expect(open).toHaveBeenCalledWith('/dl/3', '_blank')
    open.mockRestore()
  })
})

// ─── VaulterNameDialog ────────────────────────────────────────────────────────

describe('VaulterNameDialog', () => {
  const mountDialog = (props: Record<string, unknown>) => mount(VaulterNameDialog, { props, ...withClient({}) })

  it('does not render when closed and seeds the field from initialValue on open', async () => {
    const closed = mountDialog({ open: false, initialValue: 'seed' })
    expect(closed.find('.vaulter-name-dialog').exists()).toBe(false)

    const open = mountDialog({ open: true, initialValue: 'seed', title: 'Rename' })
    await nextTick()
    const field = open.get('.vaulter-name-dialog__input').element as HTMLInputElement
    expect(field.value).toBe('seed')
    // The title surfaces in the header and as the dialog's aria-label.
    expect(open.get('.vaulter-name-dialog__title').text()).toBe('Rename')
    expect(open.get('.vaulter-name-dialog').attributes('aria-label')).toBe('Rename')
  })

  it('re-seeds from initialValue each time it re-opens (no stale value leaks through)', async () => {
    const wrapper = mountDialog({ open: true, initialValue: 'first' })
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
    const wrapper = mountDialog({ open: true, initialValue: '' })
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
    const wrapper = mountDialog({ open: true, initialValue: '' })
    await nextTick()

    // A blank confirm emits nothing.
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    expect(wrapper.emitted('submit')).toBeUndefined()

    // Padded text → submit carries it trimmed.
    await wrapper.get('.vaulter-name-dialog__input').setValue('  Reports  ')
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    expect(wrapper.emitted('submit')?.[0]).toEqual(['Reports'])
  })

  it.each([
    ['a/b', 'path separators'],
    ['a\\b', 'path separators'],
    ['..', '"." or ".."'],
    ['tab\there', 'control characters'],
    ['what?', 'must not contain <'],
    ['say "hi"', 'must not contain <'],
    ['trailing.', 'end with a dot'],
    ['CON', 'reserved by operating systems'],
    ['nul.txt', 'reserved by operating systems'],
    ['desktop.ini', 'reserved by operating systems'],
    ['x'.repeat(256), '255'],
  ])('blocks %j with an inline reason (portable-v1)', async (name, message) => {
    const wrapper = mountDialog({ open: true, initialValue: '' })
    await nextTick()

    await wrapper.get('.vaulter-name-dialog__input').setValue(name)

    expect((wrapper.get('.vaulter-name-dialog__submit').element as HTMLButtonElement).disabled).toBe(true)
    expect(wrapper.get('.vaulter-name-dialog__error').text()).toContain(message)
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    await wrapper.get('.vaulter-name-dialog__input').trigger('keydown.enter')
    expect(wrapper.emitted('submit')).toBeUndefined()
  })

  it('accepts a legal name after an illegal one and submits it', async () => {
    const wrapper = mountDialog({ open: true, initialValue: '' })
    await nextTick()

    await wrapper.get('.vaulter-name-dialog__input').setValue('a/b')
    expect(wrapper.find('.vaulter-name-dialog__error').exists()).toBe(true)

    await wrapper.get('.vaulter-name-dialog__input').setValue('Reports')
    expect((wrapper.get('.vaulter-name-dialog__submit').element as HTMLButtonElement).disabled).toBe(false)
    expect(wrapper.find('.vaulter-name-dialog__error').exists()).toBe(false)
    await wrapper.get('.vaulter-name-dialog__submit').trigger('click')
    expect(wrapper.emitted('submit')?.[0]).toEqual(['Reports'])
  })

  it('shows the server reason of a refused name (a drive policy stricter than portable-v1)', async () => {
    const serverError = new VaulterApiError({
      status: 422, code: 'invalid_node_name', title: 'Invalid node name', meta: { reason: 'reserved_name' },
    })
    const wrapper = mountDialog({ open: true, initialValue: 'CVS', serverError })
    await nextTick()

    expect(wrapper.get('.vaulter-name-dialog__error').text()).toContain('reserved by operating systems')
    expect(wrapper.get('.vaulter-name-dialog__input').attributes('aria-invalid')).toBe('true')
  })

  it('falls back to the problem detail for an unknown server reason, and ignores other errors', async () => {
    const unknownReason = new VaulterApiError({
      status: 422, code: 'invalid_node_name', title: 'Invalid node name', detail: 'Nope.', meta: { reason: 'from_the_future' },
    })
    const withDetail = mountDialog({ open: true, initialValue: 'ok', serverError: unknownReason })
    await nextTick()
    expect(withDetail.get('.vaulter-name-dialog__error').text()).toBe('Nope.')

    const other = mountDialog({
      open: true, initialValue: 'ok',
      serverError: new VaulterApiError({ status: 500, code: 'internal_error' }),
    })
    await nextTick()
    expect(other.find('.vaulter-name-dialog__error').exists()).toBe(false)
  })

  it('submits (trimmed) on Enter in the field', async () => {
    const wrapper = mountDialog({ open: true, initialValue: '' })
    await nextTick()

    await wrapper.get('.vaulter-name-dialog__input').setValue('  Typed  ')
    await wrapper.get('.vaulter-name-dialog__input').trigger('keydown.enter')
    expect(wrapper.emitted('submit')?.[0]).toEqual(['Typed'])
  })

  it('emits close on Cancel, on backdrop click and on Esc (no submit)', async () => {
    const onCancel = mountDialog({ open: true, initialValue: 'x' })
    await nextTick()
    await onCancel.get('.vaulter-name-dialog__cancel').trigger('click')
    expect(onCancel.emitted('close')).toBeTruthy()
    expect(onCancel.emitted('submit')).toBeUndefined()

    const onBackdrop = mountDialog({ open: true, initialValue: 'x' })
    await nextTick()
    await onBackdrop.get('.vaulter-name-dialog__backdrop').trigger('click')
    expect(onBackdrop.emitted('close')).toBeTruthy()

    const onEsc = mountDialog({ open: true, initialValue: 'x' })
    await nextTick()
    await onEsc.get('.vaulter-name-dialog').trigger('keydown', { key: 'Escape' })
    expect(onEsc.emitted('close')).toBeTruthy()
    expect(onEsc.emitted('submit')).toBeUndefined()
  })
})

// ─── VaulterFolderPicker ──────────────────────────────────────────────────────

describe('VaulterFolderPicker', () => {
  /** A client whose `nodes.children` returns folders/files keyed by parent, plus the breadcrumb read. */
  function pickerClient(childByParent: Record<string, Node[]>) {
    const children = vi.fn().mockImplementation((_driveId, query?: { parent?: string | null }) => {
      const parent = query?.parent ?? null
      return Promise.resolve(page(childByParent[parent === null ? 'root' : parent] ?? []))
    })
    const getWithIncludes = vi.fn().mockImplementation((nodeId) =>
      Promise.resolve({ ...node({ id: nodeId, kind: 'folder', name: `Folder ${nodeId}` }), breadcrumb: [{ id: nodeId, name: `Folder ${nodeId}` }] }))
    return { children, getWithIncludes, client: { nodes: { children, getWithIncludes } } }
  }

  it('lists only folders at the current location (files are filtered out)', async () => {
    const moved = node({ id: '1', kind: 'file', name: 'doc.txt', parent_id: '50' })
    const { client } = pickerClient({
      root: [
        node({ id: '10', kind: 'folder', name: 'Reports' }),
        node({ id: '11', kind: 'file', name: 'note.txt' }),
        node({ id: '12', kind: 'folder', name: 'Archive' }),
      ],
    })
    const wrapper = mount(VaulterFolderPicker, { props: { driveId: '7', node: moved, open: true }, ...withClient(client) })

    await flushAll()

    const names = wrapper.findAll('.vaulter-folder-picker__folder-name').map((n) => n.text())
    expect(names).toEqual(['Reports', 'Archive'])
    // The file is not offered as a destination.
    expect(names).not.toContain('note.txt')
  })

  it('excludes the node being moved from the destination list (its subtree is unreachable)', async () => {
    const moved = node({ id: '10', kind: 'folder', name: 'Reports', parent_id: null })
    const { client } = pickerClient({
      root: [
        node({ id: '10', kind: 'folder', name: 'Reports' }),
        node({ id: '12', kind: 'folder', name: 'Archive' }),
      ],
    })
    const wrapper = mount(VaulterFolderPicker, { props: { driveId: '7', node: moved, open: true }, ...withClient(client) })

    await flushAll()

    const names = wrapper.findAll('.vaulter-folder-picker__folder-name').map((n) => n.text())
    expect(names).toEqual(['Archive'])
    // The moved folder is hidden → the user can never descend into its own subtree.
    expect(names).not.toContain('Reports')
  })

  it('keeps the moved folder hidden even when it has descendants (subtree is structurally unreachable)', async () => {
    const moved = node({ id: '10', kind: 'folder', name: 'Reports', parent_id: null })
    const { client } = pickerClient({
      root: [
        node({ id: '10', kind: 'folder', name: 'Reports' }),
        node({ id: '12', kind: 'folder', name: 'Archive' }),
      ],
      '10': [node({ id: '20', kind: 'folder', name: 'Reports 2026' })],
    })
    const wrapper = mount(VaulterFolderPicker, { props: { driveId: '7', node: moved, open: true }, ...withClient(client) })

    await flushAll()

    const names = wrapper.findAll('.vaulter-folder-picker__folder-name').map((n) => n.text())
    expect(names).toEqual(['Archive'])
    expect(names).not.toContain('Reports 2026')
  })

  it('ignores a stale folder listing when navigation has moved on (no wrong-destination move)', async () => {
    // Rapid navigation A→B: B is the current location. If A's (slower) response wins the
    // listing, "Move here" would target B while showing A — a silent wrong move.
    const moved = node({ id: '1', kind: 'file', name: 'doc.txt', parent_id: '50' })
    const deferred: Record<string, (nodes: Node[]) => void> = {}
    const children = vi.fn().mockImplementation((_driveId, query?: { parent?: string | null }) => {
      const parent = query?.parent ?? null
      if (parent === null) {
        return Promise.resolve(page([
          node({ id: '10', kind: 'folder', name: 'A' }),
          node({ id: '20', kind: 'folder', name: 'B' }),
        ]))
      }
      return new Promise((resolve) => {
        deferred[parent] = (nodes) => resolve(page(nodes))
      })
    })
    const getWithIncludes = vi.fn().mockResolvedValue({ ...node({ id: '1', kind: 'folder', name: 'x' }), breadcrumb: [] })
    const wrapper = mount(VaulterFolderPicker, {
      props: { driveId: '7', node: moved, open: true },
      ...withClient({ nodes: { children, getWithIncludes } }),
    })
    await flushAll()

    const goTo = (wrapper.vm as unknown as { goTo: (id: string | null) => Promise<void> }).goTo
    void goTo('10')
    void goTo('20')

    // Resolve the CURRENT location (B) first, then the STALE one (A): A must not win.
    deferred['20']([node({ id: '201', kind: 'folder', name: 'in-B' })])
    deferred['10']([node({ id: '101', kind: 'folder', name: 'in-A' })])
    await flushAll()

    const names = wrapper.findAll('.vaulter-folder-picker__folder-name').map((n) => n.text())
    expect(names).toEqual(['in-B'])
  })

  it('disables "Move here" at the node\'s CURRENT parent (a no-op move)', async () => {
    const moved = node({ id: '1', kind: 'file', name: 'doc.txt', parent_id: null })
    const { client } = pickerClient({ root: [node({ id: '12', kind: 'folder', name: 'Archive' })] })
    const wrapper = mount(VaulterFolderPicker, { props: { driveId: '7', node: moved, open: true }, ...withClient(client) })

    await flushAll()

    expect((wrapper.get('.vaulter-folder-picker__move-here').element as HTMLButtonElement).disabled).toBe(true)
  })

  it('emits picked(folderId) after navigating into a folder, and picked(null) for the root', async () => {
    const moved = node({ id: '1', kind: 'file', name: 'doc.txt', parent_id: '99' })
    const { client } = pickerClient({
      root: [node({ id: '12', kind: 'folder', name: 'Archive' })],
      '12': [],
    })
    const wrapper = mount(VaulterFolderPicker, { props: { driveId: '7', node: moved, open: true }, ...withClient(client) })

    await flushAll()

    await wrapper.get('.vaulter-folder-picker__move-here').trigger('click')
    expect(wrapper.emitted('picked')?.[0]).toEqual([null])

    await wrapper.get('.vaulter-folder-picker__item').trigger('click')
    await flushAll()
    await wrapper.get('.vaulter-folder-picker__move-here').trigger('click')
    expect(wrapper.emitted('picked')?.[1]).toEqual(['12'])
  })

  it('emits close on the Cancel button and does not render when closed', async () => {
    const moved = node({ id: '1', kind: 'file', name: 'doc.txt', parent_id: '99' })
    const { client } = pickerClient({ root: [] })

    const closed = mount(VaulterFolderPicker, { props: { driveId: '7', node: moved, open: false }, ...withClient(client) })
    expect(closed.find('.vaulter-folder-picker').exists()).toBe(false)

    const open = mount(VaulterFolderPicker, { props: { driveId: '7', node: moved, open: true }, ...withClient(client) })
    await flushAll()
    await open.get('.vaulter-folder-picker__cancel').trigger('click')
    expect(open.emitted('close')).toBeTruthy()
  })
})

// ─── VaulterUploader ──────────────────────────────────────────────────────────

describe('VaulterUploader', () => {
  function pick(wrapper: ReturnType<typeof mount>, file: File) {
    const input = wrapper.get('.vaulter-uploader__input').element as HTMLInputElement
    Object.defineProperty(input, 'files', { value: [file], configurable: true })
    return wrapper.get('.vaulter-uploader__input').trigger('change')
  }

  it('uploads a (small) picked file, shows progress and emits uploaded', async () => {
    const created = makeFile({ id: '99', name: 'pic.txt' })
    const upload = vi.fn().mockResolvedValue(created)
    const wrapper = mount(VaulterUploader, { props: { driveId: '7' }, ...withClient({ files: { upload } }) })

    const file = new File(['hello'], 'pic.txt', { type: 'text/plain' })
    await pick(wrapper, file)
    await flushAll()

    expect(upload).toHaveBeenCalledTimes(1)
    expect(upload).toHaveBeenCalledWith('7', file, { parentId: null })
    expect(wrapper.emitted('uploaded')?.[0]?.[0]).toMatchObject({ id: '99' })

    const items = (wrapper.vm as unknown as { items: Array<{ status: string, sent: number, size: number }> }).items
    expect(items[0].status).toBe('done')
    expect(items[0].sent).toBe(items[0].size)

    await nextTick()
    expect(wrapper.get('.vaulter-uploader__item').attributes('data-status')).toBe('done')
  })

  it('uses the resumable path for large files and tracks byte progress', async () => {
    const created = makeFile({ id: '100', name: 'big.bin' })
    const start = vi.fn().mockImplementation((_drive, file: Blob, options) => ({
      start: async () => {
        options.onProgress(file.size / 2, file.size)
        options.onProgress(file.size, file.size)
        return created
      },
      abort: vi.fn(),
    }))
    const wrapper = mount(VaulterUploader, {
      props: { driveId: '7', resumableThreshold: 4 },
      ...withClient({ uploads: { start } }),
    })

    await pick(wrapper, new File([new Uint8Array(10)], 'big.bin'))
    await flushAll()

    expect(start).toHaveBeenCalledTimes(1)
    expect(start.mock.calls[0][0]).toBe('7')
    expect(wrapper.emitted('uploaded')?.[0]?.[0]).toMatchObject({ id: '100' })
  })

  it('announces a failed upload in an assertive live region', async () => {
    const upload = vi.fn().mockRejectedValue(new VaulterApiError({
      status: 413, code: 'file_size_limit_exceeded', title: 'Too large', detail: 'The file is too large.',
    }))
    const wrapper = mount(VaulterUploader, { props: { driveId: '7' }, ...withClient({ files: { upload } }) })
    const live = wrapper.get('.vaulter-uploader__announce')

    // The region exists before anything fails, so assistive tech is already watching it.
    expect(live.attributes('aria-live')).toBe('assertive')
    expect(live.attributes('role')).toBe('alert')
    expect(live.text()).toBe('')

    await pick(wrapper, new File(['x'], 'huge.bin'))
    await flushAll()

    expect(live.text()).toBe('Upload of huge.bin failed: The file is too large.')
    expect(wrapper.emitted('error')).toHaveLength(1)

    // A later success clears the stale announcement.
    upload.mockResolvedValueOnce(makeFile({ id: '1' }))
    await pick(wrapper, new File(['y'], 'ok.bin'))
    await flushAll()
    expect(live.text()).toBe('')
  })
})

// ─── VaulterDrive — answers that arrive after the user moved on ───────────────

describe('VaulterDrive — stale answers', () => {
  function held() {
    const children = vi.fn().mockResolvedValue(page([node({ id: 'root-1', kind: 'file', name: 'root' })]))
    return { children }
  }

  it('an in-flight search does not write its hits back after the query was cleared', async () => {
    const { children } = held()
    let release!: (value: ReturnType<typeof page>) => void
    const search = vi.fn().mockReturnValue(new Promise((resolve) => { release = resolve }))
    const wrapper = mount(VaulterDrive, {
      props: { driveId: '7' },
      ...withClient({ nodes: { children, search, getWithIncludes: vi.fn() }, folders: { create: vi.fn() }, files: {} }),
    })
    await flushAll()

    await wrapper.get('.vaulter-drive__search').setValue('report')
    await wrapper.get('.vaulter-drive__search').trigger('keydown.enter')
    await wrapper.get('.vaulter-drive__search').setValue('')
    await wrapper.get('.vaulter-drive__search').trigger('keydown.enter')
    release(page([node({ id: 'hit', kind: 'file', name: 'hit' })]))
    await flushAll()

    expect(wrapper.findAll('.vaulter-node-list__name').map((n) => n.text())).toEqual(['root'])
  })

  it('an in-flight search does not write its hits back after navigating into a folder', async () => {
    const folder = node({ id: 'f1', kind: 'folder', name: 'Docs' })
    const children = vi.fn()
      .mockResolvedValueOnce(page([folder]))
      .mockResolvedValue(page([node({ id: 'inner', kind: 'file', name: 'inner' })]))
    let release!: (value: ReturnType<typeof page>) => void
    const search = vi.fn().mockReturnValue(new Promise((resolve) => { release = resolve }))
    const getWithIncludes = vi.fn().mockResolvedValue({ ...folder, breadcrumb: [{ id: 'f1', name: 'Docs' }] })
    const wrapper = mount(VaulterDrive, {
      props: { driveId: '7' },
      ...withClient({ nodes: { children, search, getWithIncludes }, folders: { create: vi.fn() }, files: {} }),
    })
    await flushAll()

    await wrapper.get('.vaulter-drive__search').setValue('x')
    await wrapper.get('.vaulter-drive__search').trigger('keydown.enter')
    await wrapper.findAll('.vaulter-node-list__item')[0].trigger('dblclick')
    await flushAll()
    release(page([node({ id: 'hit', kind: 'file', name: 'hit' })]))
    await flushAll()

    expect(wrapper.findAll('.vaulter-node-list__name').map((n) => n.text())).toEqual(['inner'])
  })

  it('a slow breadcrumb of folder A never overwrites the trail of folder B', async () => {
    const a = node({ id: 'A', kind: 'folder', name: 'Folder A' })
    const b = node({ id: 'B', kind: 'folder', name: 'Folder B' })
    const children = vi.fn().mockResolvedValue(page([a, b]))
    const crumbs: Record<string, (value: unknown) => void> = {}
    const getWithIncludes = vi.fn().mockImplementation((id: string) =>
      new Promise((resolve) => { crumbs[id] = resolve }))
    const wrapper = mount(VaulterDrive, {
      props: { driveId: '7' },
      ...withClient({ nodes: { children, getWithIncludes, search: vi.fn() }, folders: { create: vi.fn() }, files: {} }),
    })
    await flushAll()

    const goTo = (wrapper.vm as unknown as { goTo: (id: string | null) => Promise<void> }).goTo
    void goTo('A')
    await flushAll()
    void goTo('B')
    await flushAll()
    crumbs.B({ ...b, breadcrumb: [{ id: 'B', name: 'Folder B' }] })
    await flushAll()
    crumbs.A({ ...a, breadcrumb: [{ id: 'A', name: 'Folder A' }] })
    await flushAll()

    expect(wrapper.get('.vaulter-breadcrumb').text()).toContain('Folder B')
    expect(wrapper.get('.vaulter-breadcrumb').text()).not.toContain('Folder A')
  })
})

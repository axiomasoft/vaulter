import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue'
import VaulterDrive from '../../src/components/VaulterDrive.vue'
import VaulterUploader from '../../src/components/VaulterUploader.vue'
import { VaulterApiError } from '../../src/api/errors'
import type { Node } from '../../src/api/types'
import { makeFile, pageOf } from '../support/nodes'
import { withClient } from '../support/mount'

// Testing Library only auto-cleans when the test globals are enabled; this package keeps them off.
afterEach(cleanup)

const rows = () => screen.getAllByRole('option')

/** A drive whose listing, rename and trash are fakes that remember what was asked. */
function fakeDrive(initial: Node[]) {
  let current = initial
  const children = vi.fn().mockImplementation(() => Promise.resolve(pageOf(current)))
  const update = vi.fn().mockImplementation((id: string, patch: { name: string }) =>
    Promise.resolve({ ...current.find((node) => node.id === id)!, name: patch.name }))
  const trash = vi.fn().mockImplementation((id: string) => {
    current = current.filter((node) => node.id !== id)
    return Promise.resolve({ ...initial.find((node) => node.id === id)!, trashed_at: 'now' })
  })

  return {
    children, update, trash,
    client: { nodes: { children, update, trash, getWithIncludes: vi.fn() }, folders: { create: vi.fn() }, files: { downloadUrl: vi.fn() } },
  }
}

describe('VaulterDrive — keyboard scenarios', () => {
  it('Delete trashes the focused node with its etag, then focus lands on the neighbour', async () => {
    const files = [
      makeFile({ id: '1', name: 'a.txt', etag: '"1.1.aa"' }),
      makeFile({ id: '2', name: 'b.txt', etag: '"2.1.bb"' }),
      makeFile({ id: '3', name: 'c.txt', etag: '"3.1.cc"' }),
    ]
    const { client, trash } = fakeDrive(files)
    render(VaulterDrive, { props: { driveId: '7', confirm: () => true }, ...withClient(client) })
    await waitFor(() => expect(rows()).toHaveLength(3))

    rows()[1].focus()
    await fireEvent.keyDown(rows()[1], { key: 'Delete' })

    await waitFor(() => expect(rows()).toHaveLength(2))
    expect(trash).toHaveBeenCalledWith('2', { etag: '"2.1.bb"' })
    await nextTick()
    expect(document.activeElement).toBe(rows()[1])
    expect(document.activeElement?.textContent).toContain('c.txt')
  })

  it('F2 opens the name dialog prefilled; submitting renames with the etag', async () => {
    const files = [makeFile({ id: '1', name: 'old.txt', etag: '"1.4.dd"' })]
    const { client, update } = fakeDrive(files)
    render(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })
    await waitFor(() => expect(rows()).toHaveLength(1))

    rows()[0].focus()
    await fireEvent.keyDown(rows()[0], { key: 'F2' })

    const field = await screen.findByLabelText('Name') as HTMLInputElement
    expect(field.value).toBe('old.txt')

    await fireEvent.update(field, 'new.txt')
    await fireEvent.keyDown(field, { key: 'Enter' })

    await waitFor(() => expect(update).toHaveBeenCalledWith('1', { name: 'new.txt' }, { etag: '"1.4.dd"' }))
  })

  it('Enter on a folder navigates into it', async () => {
    const folder = makeFile({ id: '1', name: 'Docs', kind: 'folder', presentation: null })
    const { client, children } = fakeDrive([folder])
    ;(client.nodes.getWithIncludes as ReturnType<typeof vi.fn>).mockResolvedValue({ ...folder, breadcrumb: [{ id: '1', name: 'Docs' }] })
    render(VaulterDrive, { props: { driveId: '7' }, ...withClient(client) })
    await waitFor(() => expect(rows()).toHaveLength(1))

    rows()[0].focus()
    await fireEvent.keyDown(rows()[0], { key: 'Enter' })

    await waitFor(() => expect(children).toHaveBeenLastCalledWith('7', { parent: '1' }))
  })

  it('shows a mutation failure in an alert region', async () => {
    const files = [makeFile({ id: '1', name: 'a.txt' })]
    const { client, trash } = fakeDrive(files)
    trash.mockRejectedValueOnce(new VaulterApiError({ status: 403, code: 'permission_denied', detail: 'You may not trash this.' }))
    render(VaulterDrive, { props: { driveId: '7', confirm: () => true }, ...withClient(client) })
    await waitFor(() => expect(rows()).toHaveLength(1))

    rows()[0].focus()
    await fireEvent.keyDown(rows()[0], { key: 'Delete' })

    // The uploader keeps its own (empty) live region in the toolbar, so find the alert by its text.
    const alert = await screen.findByText('You may not trash this.')
    expect(alert.getAttribute('role')).toBe('alert')
  })
})

describe('VaulterUploader — failures are announced', () => {
  it('exposes an assertive live region that receives the failure text', async () => {
    const upload = vi.fn().mockRejectedValue(new VaulterApiError({
      status: 413, code: 'file_size_limit_exceeded', title: 'Too large', detail: 'The file is too large.',
    }))
    const { container } = render(VaulterUploader, { props: { driveId: '7' }, ...withClient({ files: { upload } }) })
    const input = container.querySelector('input[type="file"]') as HTMLInputElement
    const file = new File(['x'], 'huge.bin')

    await fireEvent.update(input, '')
    Object.defineProperty(input, 'files', { value: [file], configurable: true })
    await fireEvent.change(input)

    const announcement = await screen.findByText('Upload of huge.bin failed: The file is too large.')
    expect(announcement.getAttribute('aria-live')).toBe('assertive')
    expect(announcement.getAttribute('role')).toBe('alert')
  })
})

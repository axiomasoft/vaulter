import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DocVaultBreadcrumb from '../src/components/DocVaultBreadcrumb.vue'
import DocVaultEditor from '../src/components/DocVaultEditor.vue'
import DocVaultFileManager from '../src/components/DocVaultFileManager.vue'
import DocVaultPermissionsPanel from '../src/components/DocVaultPermissionsPanel.vue'
import DocVaultVersionHistory from '../src/components/DocVaultVersionHistory.vue'
import { docVaultClientKey } from '../src/composables/useDocVaultClient'
import type { DocVaultClient } from '../src/client'

function mockClient(): DocVaultClient {
  return {
    documents: {
      list: vi.fn().mockResolvedValue([]),
      get: vi.fn(),
      create: vi.fn(),
      update: vi.fn(),
      remove: vi.fn(),
      restore: vi.fn(),
    },
    versions: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), restore: vi.fn() },
    folders: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), update: vi.fn(), remove: vi.fn() },
    permissions: { list: vi.fn().mockResolvedValue([]), grant: vi.fn(), revoke: vi.fn() },
    collaboration: { token: vi.fn() },
  } as unknown as DocVaultClient
}

function withClient(client: DocVaultClient = mockClient()) {
  return { global: { provide: { [docVaultClientKey as symbol]: client } } }
}

describe('@docvault/vue components', () => {
  it('mounts the file manager and loads workspace documents', async () => {
    const client = mockClient()
    const wrapper = mount(DocVaultFileManager, {
      props: { workspaceType: 'App\\Project', workspaceId: 1 },
      ...withClient(client),
    })

    expect(wrapper.classes()).toContain('docvault-file-manager')
    await Promise.resolve()
    expect(client.documents.list).toHaveBeenCalledWith({ workspace_type: 'App\\Project', workspace_id: 1 })
  })

  it('mounts the editor', () => {
    const wrapper = mount(DocVaultEditor, { props: { documentId: 1, autoLoad: false }, ...withClient() })
    expect(wrapper.classes()).toContain('docvault-editor')
  })

  it('mounts version history and the permissions panel', () => {
    expect(mount(DocVaultVersionHistory, { props: { documentId: 1, autoLoad: false }, ...withClient() }).exists()).toBe(true)
    expect(mount(DocVaultPermissionsPanel, { props: { documentId: 1, autoLoad: false }, ...withClient() }).exists()).toBe(true)
  })

  it('renders the breadcrumb without a client and emits navigate', async () => {
    const wrapper = mount(DocVaultBreadcrumb, { props: { path: [] } })
    expect(wrapper.classes()).toContain('docvault-breadcrumb')

    await wrapper.get('.docvault-breadcrumb__root').trigger('click')
    expect(wrapper.emitted('navigate')?.[0]).toEqual([null])
  })
})

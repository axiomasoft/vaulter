import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import VaulterBreadcrumb from '../src/components/VaulterBreadcrumb.vue'
import VaulterCommentsPanel from '../src/components/VaulterCommentsPanel.vue'
import VaulterEditor from '../src/components/VaulterEditor.vue'
import VaulterFileManager from '../src/components/VaulterFileManager.vue'
import VaulterPermissionsPanel from '../src/components/VaulterPermissionsPanel.vue'
import VaulterSharePanel from '../src/components/VaulterSharePanel.vue'
import VaulterVersionHistory from '../src/components/VaulterVersionHistory.vue'
import { docVaultClientKey } from '../src/composables/useVaulterClient'
import type { VaulterClient } from '../src/client'

function mockClient(): VaulterClient {
  return {
    documents: {
      list: vi.fn().mockResolvedValue([]),
      trashed: vi.fn().mockResolvedValue([]),
      get: vi.fn(),
      create: vi.fn(),
      update: vi.fn(),
      remove: vi.fn(),
      forceDelete: vi.fn(),
      restore: vi.fn(),
      move: vi.fn(),
      duplicate: vi.fn(),
      bulk: vi.fn(),
      export: vi.fn(),
    },
    versions: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), restore: vi.fn() },
    folders: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), update: vi.fn(), remove: vi.fn() },
    permissions: { list: vi.fn().mockResolvedValue([]), grant: vi.fn(), revoke: vi.fn() },
    comments: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), resolve: vi.fn(), remove: vi.fn() },
    shares: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), revoke: vi.fn(), resolve: vi.fn() },
    collaboration: { token: vi.fn() },
  } as unknown as VaulterClient
}

function withClient(client: VaulterClient = mockClient()) {
  return { global: { provide: { [docVaultClientKey as symbol]: client } } }
}

describe('@vaulter/vue components', () => {
  it('mounts the file manager and loads workspace documents', async () => {
    const client = mockClient()
    const wrapper = mount(VaulterFileManager, {
      props: { workspaceType: 'App\\Project', workspaceId: 1 },
      ...withClient(client),
    })

    expect(wrapper.classes()).toContain('vaulter-file-manager')
    await Promise.resolve()
    expect(client.documents.list).toHaveBeenCalledWith({ workspace_type: 'App\\Project', workspace_id: 1 })
  })

  it('mounts the editor', () => {
    const wrapper = mount(VaulterEditor, { props: { documentId: 1, autoLoad: false }, ...withClient() })
    expect(wrapper.classes()).toContain('vaulter-editor')
  })

  it('mounts version history, permissions and comments panels', () => {
    expect(mount(VaulterVersionHistory, { props: { documentId: 1, autoLoad: false }, ...withClient() }).exists()).toBe(true)
    expect(mount(VaulterPermissionsPanel, { props: { documentId: 1, autoLoad: false }, ...withClient() }).exists()).toBe(true)

    const comments = mount(VaulterCommentsPanel, { props: { documentId: 1, autoLoad: false }, ...withClient() })
    expect(comments.classes()).toContain('vaulter-comments-panel')

    const shares = mount(VaulterSharePanel, { props: { documentId: 1, autoLoad: false }, ...withClient() })
    expect(shares.classes()).toContain('vaulter-share-panel')
  })

  it('renders the breadcrumb without a client and emits navigate', async () => {
    const wrapper = mount(VaulterBreadcrumb, { props: { path: [] } })
    expect(wrapper.classes()).toContain('vaulter-breadcrumb')

    await wrapper.get('.vaulter-breadcrumb__root').trigger('click')
    expect(wrapper.emitted('navigate')?.[0]).toEqual([null])
  })
})

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import {
  DocVaultFileManager,
  DocVaultEditor,
  DocVaultVersionHistory,
  DocVaultPermissionsPanel,
  DocVaultBreadcrumb,
  DocVaultPlugin,
} from '../src/index.js'

describe('@docvault/vue exports', () => {
  it('exports the plugin with an install function', () => {
    expect(typeof DocVaultPlugin.install).toBe('function')
  })

  it('mounts DocVaultFileManager', () => {
    const wrapper = mount(DocVaultFileManager, {
      props: { workspaceType: 'App\\Models\\Project', workspaceId: 1 },
    })
    expect(wrapper.classes()).toContain('docvault-file-manager')
  })

  it('mounts DocVaultEditor', () => {
    const wrapper = mount(DocVaultEditor, { props: { documentId: 1 } })
    expect(wrapper.classes()).toContain('docvault-editor')
  })

  it('mounts the remaining components', () => {
    expect(mount(DocVaultVersionHistory, { props: { documentId: 1 } }).exists()).toBe(true)
    expect(mount(DocVaultPermissionsPanel, { props: { documentId: 1 } }).exists()).toBe(true)
    expect(mount(DocVaultBreadcrumb).exists()).toBe(true)
  })
})

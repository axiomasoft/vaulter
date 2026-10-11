import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import VaulterDrive from '../src/components/VaulterDrive.vue'
import VaulterNameDialog from '../src/components/VaulterNameDialog.vue'
import { makeNode, pageOf } from './support/nodes'
import { withClient } from './support/mount'

describe('VaulterDrive creation abilities', () => {
  it.each([
    [null, false], [null, true], ['folder', false], ['folder', true],
  ] as const)('uses the current folder ability (%s, create=%s)', async (parentId, allow) => {
    const folder = makeNode({ id: parentId ?? 'root', kind: 'folder', abilities: { create: allow } })
    const create = vi.fn().mockResolvedValue(makeNode({ id: 'created', kind: 'folder' }))
    const client = {
      drives: { get: vi.fn().mockResolvedValue({ root_node_id: 'root' }) },
      nodes: {
        children: vi.fn().mockResolvedValue(pageOf([makeNode({ id: 'child', abilities: { create: true } })])),
        get: vi.fn().mockResolvedValue(folder),
        getWithIncludes: vi.fn().mockResolvedValue({ ...folder, breadcrumb: [] }),
      },
      folders: { create },
      files: { upload: vi.fn() },
    }
    const wrapper = mount(VaulterDrive, { props: { driveId: 'drive', initialParentId: parentId }, ...withClient(client) })
    await flushPromises()

    expect(wrapper.find('.vaulter-drive__new-folder').exists()).toBe(allow)
    expect(wrapper.find('.vaulter-drive__uploader').exists()).toBe(allow)
    if (allow) {
      await wrapper.get('.vaulter-drive__new-folder').trigger('click')
      wrapper.getComponent(VaulterNameDialog).vm.$emit('submit', 'created')
      await flushPromises()
      expect(create).toHaveBeenCalledWith('drive', { name: 'created', parentId })
    } else {
      expect(create).not.toHaveBeenCalled()
      expect(client.files.upload).not.toHaveBeenCalled()
    }
    wrapper.unmount()
  })
})

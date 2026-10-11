import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import VaulterSpreadsheet from '../src/components/VaulterSpreadsheet.vue'
import { makeDocument } from './support/nodes'
import { withClient } from './support/mount'

function content(body: Record<string, unknown>, etag = '"20.0.1"') {
  return { type: 'univer', schema_version: 1, state_version: 1, collab_epoch: 0, revision_id: null, content: body, etag }
}

const sheet = () => makeDocument({ id: '20', name: 'Budget' })

describe('VaulterSpreadsheet', () => {
  it('loads on mount and exposes the workbook + a save through the default slot', async () => {
    const loaded = vi.fn().mockResolvedValue(content({ id: 'wb1', name: 'Budget' }))
    const update = vi.fn().mockResolvedValue(content({ id: 'wb1', name: 'Budget' }, '"20.0.2"'))

    const wrapper = mount(VaulterSpreadsheet, {
      props: { node: sheet() },
      slots: {
        default: `<template #default="{ workbook }"><pre class="wb">{{ JSON.stringify(workbook) }}</pre></template>`,
      },
      ...withClient({ documents: { content: loaded, update } }),
    })

    await flushPromises()

    expect(loaded).toHaveBeenCalledWith('20')
    expect(wrapper.get('.wb').text()).toContain('Budget')
    expect(wrapper.classes()).toContain('vaulter-spreadsheet')
  })

  it('persists with the ETag of the loaded snapshot', async () => {
    const loaded = vi.fn().mockResolvedValue(content({ id: 'wb1' }, '"20.0.7"'))
    const update = vi.fn().mockResolvedValue(content({ id: 'wb1', rev: 2 }, '"20.0.8"'))
    const wrapper = mount(VaulterSpreadsheet, {
      props: { node: sheet() },
      ...withClient({ documents: { content: loaded, update } }),
    })
    await flushPromises()

    ;(wrapper.vm as unknown as { setWorkbook: (w: object) => void }).setWorkbook({ id: 'wb1', rev: 2 })
    await (wrapper.vm as unknown as { save: () => Promise<void> }).save()

    expect(update).toHaveBeenCalledWith('20', { id: 'wb1', rev: 2 }, { etag: '"20.0.7"' })
    expect(wrapper.emitted('save')).toHaveLength(1)
  })

  it('ignores edits when the caller cannot edit the node', async () => {
    const loaded = vi.fn().mockResolvedValue(content({ id: 'wb1' }))
    const wrapper = mount(VaulterSpreadsheet, {
      props: { node: makeDocument({ id: '20', abilities: { edit: false } }) },
      ...withClient({ documents: { content: loaded, update: vi.fn() } }),
    })
    await flushPromises()

    ;(wrapper.vm as unknown as { setWorkbook: (w: object) => void }).setWorkbook({ id: 'wb1', rev: 9 })

    expect(wrapper.emitted('workbook-changed')).toBeUndefined()
    expect(wrapper.attributes('data-readonly')).toBe('true')
  })

  it('surfaces a load failure in the error slot', async () => {
    const wrapper = mount(VaulterSpreadsheet, {
      props: { node: sheet() },
      ...withClient({ documents: { content: vi.fn().mockRejectedValue(new Error('boom')), update: vi.fn() } }),
    })

    await flushPromises()

    expect(wrapper.find('.vaulter-spreadsheet__error').exists()).toBe(true)
  })
})
